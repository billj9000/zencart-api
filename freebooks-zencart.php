<?php
/**
 * FreeBooks ↔ ZenCart API
 *
 * Version 2.0.0
 * Compatible with ZenCart 1.5.8 – 2.x, PHP 7.4 – 8.3
 *
 * Copyright (c) 2022-2026 SAABits Ltd
 * Released under the GNU General Public License v2
 *
 * INSTALLATION
 * ------------
 * 1. Copy this file to your ZenCart web root (same level as index.php).
 * 2. Generate a strong random key:
 *      php -r "echo bin2hex(random_bytes(32));"
 * 3. Insert the key into ZenCart's configuration table:
 *      INSERT INTO configuration
 *        (configuration_title, configuration_key, configuration_value,
 *         configuration_description, configuration_group_id, sort_order, date_added)
 *      VALUES
 *        ('FreeBooks API Key', 'FREEBOOKS_API_KEY', '<your-key>',
 *         'Secret key for FreeBooks ERP integration', 1, 100, NOW());
 * 4. Callers must send:  Authorization: Bearer <your-key>
 *
 * ENDPOINTS (all via ?task=<name>)
 * ---------------------------------
 *  GET  ?task=version
 *  GET  ?task=orders[&last_order=<id>]
 *  POST ?task=updateOrderComments   body: {"orderId":N,"comment":"...","status":N}
 *  POST ?task=updateProducts        body: {"products":[...]}
 *  POST ?task=updateProductsQty     body: {"products":[{"products_model":"...","quantity":N,"price":N},...]}
 */

// ─── Bootstrap ────────────────────────────────────────────────────────────────
//
// Both the bootstrap and the router at the foot of this file are skipped when
// ZC_API_LIB_ONLY is defined before including it. That lets the helpers below
// be loaded on their own -- by the test suite in tests/, or by another script
// that wants zc_country_code() and friends without serving a request.
//
// A constant rather than an is-this-file-the-entry-point check, because the
// latter has to compare realpath() against SCRIPT_FILENAME or argv[0] and gets
// it wrong under CLI servers, symlinked docroots and PHP-FPM. This file moves
// money; predictable beats clever. Nothing outside PHP can define a constant,
// so a request cannot switch the router off.

if (!defined('ZC_API_LIB_ONLY')) {
    require('includes/application_top.php');

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
}

define('ZC_API_VERSION', 4);
// Tax class for taxable products, by TITLE rather than id -- see
// zc_tax_class_id(). Left empty by default, which means "use whichever class
// the store has"; set it only where a store has several and the ERP does not
// say which. Guarded, so it can be pre-defined by an auto_prepend file.
if (!defined('ZC_DEFAULT_TAX_CLASS_TITLE')) {
    define('ZC_DEFAULT_TAX_CLASS_TITLE', '');
}

// ─── Response helpers ────────────────────────────────────────────────────────

function api_ok($data = null): void
{
    $body = ['version' => ZC_API_VERSION, 'ok' => true];
    if ($data !== null) {
        $body['data'] = $data;
    }
    echo json_encode($body);
    exit;
}

function api_error(string $message, int $http_status = 400): void
{
    http_response_code($http_status);
    echo json_encode(['version' => ZC_API_VERSION, 'ok' => false, 'error' => $message]);
    exit;
}

// ─── Bulk-request memory management ──────────────────────────────────────────
//
// Zen Cart memoizes every SELECT of a request in QueryCache, keyed by the SQL
// string, and never evicts. That is right for a page render, which issues a
// bounded set of distinct queries and reuses them. It is wrong for these
// endpoints, which loop over a payload and issue at least one *distinct* SELECT
// per item: the key is different every time, so nothing is ever reused and the
// cache grows in proportion to the size of the request.
//
// Measured on this store, updateProductsQty retained 32 KB per product -- two
// buffered mysqli_result sets at 16 KB each -- growing dead linearly. A 128 MB
// memory_limit was therefore exhausted at ~3,700 products, and the hourly
// full-catalogue sync died every time with
//
//   PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
//   (tried to allocate 16384 bytes) in .../db/mysql/query_factory.php on line 448
//
// (16384 being exactly one more result buffer.) Flushing on a fixed interval
// makes the footprint flat rather than linear. It costs no extra queries,
// because each item is visited once and so no cache entry is ever read back --
// the cache was pure overhead on this path. Zen Cart core does the same thing
// for the same reason in modules/pages/sitemapxml/sitemapxml_products.php.
//
// Flushing is safe mid-iteration over an outer result set: queryFactoryResult
// keeps its own reference to the mysqli_result in ->resource, so dropping the
// cache's reference does not free a result that is still being walked.
//
// Sized to bound the cache at a few MB while keeping the call rare enough to be
// free; the exact value is not sensitive.
if (!defined('ZC_QUERY_CACHE_FLUSH_EVERY')) {
    define('ZC_QUERY_CACHE_FLUSH_EVERY', 250);
}

/**
 * Read and decode the JSON request body, then release the raw string.
 *
 * Returns the decoded array, or null if the body was absent or did not decode
 * to an array; callers decide whether that is fatal.
 */
function zc_read_json_body(): ?array
{
    $json = (string)file_get_contents('php://input');

    // A POST larger than post_max_size is discarded by PHP *before* this script
    // runs: the input stream reads back empty while Content-Length still reports
    // the real size. Left undetected that surfaces as a generic "not valid JSON",
    // which sends the caller hunting for a syntax error that isn't there. Name
    // the actual limit instead.
    $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($json === '' && $declared > 0) {
        api_error(sprintf(
            'Request body of %d bytes was discarded by PHP before it reached this script: '
            . 'it exceeds post_max_size (%s). Send the payload in smaller batches.',
            $declared,
            ini_get('post_max_size')
        ), 413);
    }

    $data = json_decode($json, true);

    // The raw body is the same order of size as the decoded payload -- megabytes
    // on a full-catalogue sync -- and is dead weight from here on. json_decode's
    // error state is global, so callers can still read json_last_error_msg().
    unset($json);

    return is_array($data) ? $data : null;
}

/**
 * Flush Zen Cart's SELECT memoization cache every ZC_QUERY_CACHE_FLUSH_EVERY
 * items. Call once per outer-loop iteration, passing a 1-based counter.
 *
 * Call it at the TOP of the loop body: these loops `continue` on several paths,
 * and a call at the bottom would be skipped exactly on the cheap iterations
 * that a large payload has most of.
 */
function zc_periodic_flush(int $iteration): void
{
    if ($iteration % ZC_QUERY_CACHE_FLUSH_EVERY !== 0) {
        return;
    }
    global $queryCache;
    // Probed rather than assumed -- this file supports ZC 1.5.8 through 2.x and
    // the global is not guaranteed to be present. Same probe core uses.
    if (isset($queryCache) && is_object($queryCache) && method_exists($queryCache, 'reset')) {
        $queryCache->reset('ALL');
    }
}

// ─── Customisation hooks ───────────────────────────────────────────────────────
//
// A store-specific quirk (e.g. a SKU prefix, a stock buffer on one product)
// belongs in a per-store hook file, not in this shared file. Drop PHP files into
// ZC_HOOK_DIR; each registers callbacks via zc_add_filter()/zc_add_action(), and
// this file calls zc_filter()/zc_do_action() at documented points (see README.md
// for the full list) — several hooks can share one point, and a broken hook
// can't take down the whole response.
//
// ZC_HOOK_DIR may be predefined (e.g. from includes/extra_configures/) to move
// the directory; otherwise it defaults below.
if (!defined('ZC_HOOK_DIR')) {
    define('ZC_HOOK_DIR', DIR_FS_CATALOG . 'includes/erp_hooks/');
}
define('ZC_HOOKS', true); // guard constant hook files check for, in case a server has no .htaccess support

$GLOBALS['zc_hooks'] = ['filters' => [], 'actions' => []];

function zc_add_filter(string $name, callable $fn, int $priority = 10): void
{
    $GLOBALS['zc_hooks']['filters'][$name][$priority][] = $fn;
}

function zc_add_action(string $name, callable $fn, int $priority = 10): void
{
    $GLOBALS['zc_hooks']['actions'][$name][$priority][] = $fn;
}

function zc_has_hook(string $name): bool
{
    return !empty($GLOBALS['zc_hooks']['filters'][$name]) || !empty($GLOBALS['zc_hooks']['actions'][$name]);
}

// Shared runner for both zc_filter() and zc_do_action(). $is_filter controls
// whether a callback's return value is kept and chained into the next callback.
function _zc_run(string $name, $value, array $ctx, bool $is_filter)
{
    $bucket = $is_filter ? 'filters' : 'actions';
    $queue  = $GLOBALS['zc_hooks'][$bucket][$name] ?? [];
    if (empty($queue)) {
        return $value;
    }
    ksort($queue); // lower priority number runs first

    foreach ($queue as $callbacks) {
        foreach ($callbacks as $fn) {
            try {
                $result = $fn($value, $ctx);
            } catch (\Throwable $e) {
                api_error('Hook "' . $name . '" failed: ' . $e->getMessage(), 500);
            }
            if ($is_filter) {
                if ($result === null) {
                    // Forgetting `return` in a filter is the commonest hook bug —
                    // keep the previous value rather than silently blanking it.
                    error_log('freebooks-zencart.php: filter "' . $name . '" returned null; ignoring and keeping previous value');
                } else {
                    $value = $result;
                }
            }
        }
    }
    return $value;
}

function zc_filter(string $name, $value, array $ctx = [])
{
    return _zc_run($name, $value, $ctx, true);
}

function zc_do_action(string $name, array $ctx = []): void
{
    _zc_run($name, null, $ctx, false);
}

function zc_load_hooks(): void
{
    global $db;
    if (!is_dir(ZC_HOOK_DIR)) {
        return;
    }
    $files = glob(rtrim(ZC_HOOK_DIR, '/') . '/*.php');
    if ($files === false) {
        return;
    }
    sort($files); // filename order, e.g. "10-sku-prefix.php" before "20-stock-buffer.php"
    foreach ($files as $file) {
        include_once $file;
    }
}

// ─── Authentication ───────────────────────────────────────────────────────────

function check_access($db): void
{
    // Apache may strip Authorization before it reaches $_SERVER; check fallbacks.
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '')
        ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        api_error('Missing or malformed Authorization header', 401);
    }
    $supplied_key = $m[1];

    $row = $db->Execute(
        "SELECT configuration_value FROM " . TABLE_CONFIGURATION
        . " WHERE configuration_key = 'FREEBOOKS_API_KEY' LIMIT 1"
    );
    if ($row->EOF || empty($row->fields['configuration_value'])) {
        api_error('API not configured — add FREEBOOKS_API_KEY to the configuration table', 503);
    }

    if (!hash_equals($row->fields['configuration_value'], $supplied_key)) {
        api_error('Unauthorized', 401);
    }
}

// ─── zen_update_orders_history compatibility shim ────────────────────────────

// ZenCart 1.5.x signature: zen_update_orders_history($oID, $message, $updated_by, $status, $notify, $notify_comments)
// ZenCart 2.x retains the same function name; $updated_by moved to a later parameter in some builds.
// We prefer the native function; if absent (stripped installs) we insert directly.
function zc_update_order_history($db, int $order_id, string $comment, int $status, int $notify, string $updated_by = 'FreeBooks'): int
{
    if (function_exists('zen_update_orders_history')) {
        return (int) zen_update_orders_history($order_id, $comment, $updated_by, $status, $notify, true);
    }

    // Fallback: manual insert + order status update
    $db->Execute(
        "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY
        . " (orders_id, orders_status_id, date_added, customer_notified, comments, updated_by)"
        . " VALUES ("
            . $order_id . ", "
            . $status . ", NOW(), "
            . $notify . ", '"
            . zen_db_input($comment) . "', '"
            . zen_db_input($updated_by) . "')"
    );
    $db->Execute(
        "UPDATE " . TABLE_ORDERS
        . " SET orders_status = " . $status . ", last_modified = NOW()"
        . " WHERE orders_id = " . $order_id
    );
    return 1;
}

// ─── Payment reference lookup ─────────────────────────────────────────────────

// Replaces sniffer->field_exists() with a direct SHOW COLUMNS query that
// works identically across ZenCart versions.
function get_payment_ref($db, int $order_id, string $module): string
{
    // These tables outlive their payment modules. Historical orders still
    // reference Square and Braintree transactions long after the modules are
    // uninstalled, so define the table names here rather than relying on the
    // modules' own datafiles being present.
    if (!defined('TABLE_SQUARE_PAYMENTS')) {
        define('TABLE_SQUARE_PAYMENTS', DB_PREFIX . 'square_payments');
    }
    if (!defined('TABLE_BRAINTREE')) {
        define('TABLE_BRAINTREE', DB_PREFIX . 'braintree');
    }

    switch ($module) {
        case 'paypal':
        case 'paypalwpp':
            $r = $db->Execute(
                "SELECT txn_id FROM " . TABLE_PAYPAL
                . " WHERE order_id = " . $order_id . " LIMIT 1"
            );
            return $r->EOF ? '' : (string)($r->fields['txn_id'] ?? '');

        case 'square':
        case 'square_webPay':
            // Prefer receipt_id if the column exists in this Square module version
            $col = $db->Execute(
                "SHOW COLUMNS FROM " . TABLE_SQUARE_PAYMENTS . " LIKE 'receipt_id'"
            );
            if (!$col->EOF) {
                $r = $db->Execute(
                    "SELECT receipt_id FROM " . TABLE_SQUARE_PAYMENTS
                    . " WHERE order_id = " . $order_id . " LIMIT 1"
                );
                if (!$r->EOF && !empty($r->fields['receipt_id'])) {
                    return (string)$r->fields['receipt_id'];
                }
            }
            $r = $db->Execute(
                "SELECT sq_order FROM " . TABLE_SQUARE_PAYMENTS
                . " WHERE order_id = " . $order_id . " LIMIT 1"
            );
            return $r->EOF ? '' : (string)($r->fields['sq_order'] ?? '');

        case 'braintree_api':
            $r = $db->Execute(
                "SELECT txn_id FROM " . TABLE_BRAINTREE
                . " WHERE order_id = " . $order_id . " LIMIT 1"
            );
            return $r->EOF ? '' : (string)($r->fields['txn_id'] ?? '');
    }

    return '';
}

// ─── Country code lookup ─────────────────────────────────────────────────────

/**
 * ISO 3166-1 alpha-2 code for a country name stored on an order.
 *
 * The orders table has never carried a delivery_country_code or
 * billing_country_code column in any Zen Cart release. It stores
 * delivery_country and billing_country as plain names -- varchar(64), captured
 * at the time of the order -- and there is no country id to go with them.
 *
 * This export read those two non-existent columns directly, so every order
 * FreeBooks has ever received carried a null countryCode, and each one logged
 * a PHP warning on the way out. Resolving from the stored name is the only
 * route available after the fact.
 *
 * Results are memoized for the request. An export of several thousand orders
 * otherwise repeats the same handful of lookups thousands of times, and this
 * runs inside the loop that already had to be taught not to exhaust memory.
 */
/**
 * The store's default language id.
 *
 * Everything here that reads a name out of a *_description or *_options table
 * has to pick a language, and there is no request context to take one from --
 * these endpoints are called by an ERP, not by a browser with a session.
 *
 * This used to be the literal 1. That is right on a stock Zen Cart, where the
 * English row is languages_id 1, and wrong the moment it is not. The failure
 * is not a missing name, it is a DUPLICATE: $resolve_option() looks for an
 * existing option at language 1, finds nothing because no such row exists,
 * and creates a new one. Next sync it looks again, finds nothing again, and
 * creates another. An option per sync, growing without bound, on a table the
 * storefront reads to build every product page.
 *
 * DEFAULT_LANGUAGE holds a code ('en'), not an id, so it needs resolving. If
 * that lookup fails too the fallback is the lowest installed language rather
 * than 1 -- a store must have at least one language row, so the lowest one
 * always exists, where id 1 might not.
 */
function zc_default_language_id($db): int
{
    static $id = null;

    if ($id !== null) {
        return $id;
    }

    $code = defined('DEFAULT_LANGUAGE') ? trim((string)DEFAULT_LANGUAGE) : '';

    if ($code !== '') {
        $row = $db->Execute(
            "SELECT languages_id FROM " . TABLE_LANGUAGES
            . " WHERE code = '" . zen_db_input($code) . "' LIMIT 1"
        );
        if (!$row->EOF) {
            return $id = (int)$row->fields['languages_id'];
        }
    }

    $row = $db->Execute(
        "SELECT languages_id FROM " . TABLE_LANGUAGES . " ORDER BY languages_id LIMIT 1"
    );

    return $id = $row->EOF ? 1 : (int)$row->fields['languages_id'];
}


/**
 * A tax class id from its title, or 0 if there is no such class.
 */
function zc_tax_class_by_title($db, string $title): int
{
    static $cache = [];

    $title = trim($title);

    if ($title === '') {
        return 0;
    }

    if (isset($cache[$title])) {
        return $cache[$title];
    }

    $row = $db->Execute(
        "SELECT tax_class_id FROM " . TABLE_TAX_CLASS
        . " WHERE tax_class_title = '" . zen_db_input($title) . "' LIMIT 1"
    );

    return $cache[$title] = $row->EOF ? 0 : (int)$row->fields['tax_class_id'];
}


/**
 * Which tax class a product should carry. 0 means "not taxed".
 *
 * This used to be the constant 1, on the reasoning that a stock Zen Cart puts
 * "Taxable Goods" at tax_class_id 1. Two things wrong with that. The id is an
 * auto_increment with no guarantee attached, and the TITLE is not fixed either
 * -- this very store calls its only class "VAT", not "Taxable Goods". So
 * neither the id nor a hard-coded title can be assumed, and the resolution has
 * to be a ladder:
 *
 *   1. the class the payload names, if it names one
 *   2. ZC_DEFAULT_TAX_CLASS_TITLE, if the install sets one
 *   3. the store's lowest tax_class_id -- correct wherever there is only one,
 *      which is the overwhelmingly common case
 *
 * A title the payload asks for and the store does not have is an error rather
 * than a fallback: the ERP asked for something specific, and quietly taxing at
 * some other rate is the kind of wrong that shows up in a VAT return months
 * later. Same reasoning for a taxable product on a store with no tax classes
 * at all.
 *
 * Overridable per product through the product.tax_class_id filter.
 */
function zc_tax_class_id($db, array $product, string $sku): int
{
    if (empty($product['productTaxable'])) {
        return (int)zc_filter('product.tax_class_id', 0, ['sku' => $sku, 'product' => $product]);
    }

    $asked = trim((string)($product['taxClass'] ?? ''));

    if ($asked !== '') {
        $id = zc_tax_class_by_title($db, $asked);
        if ($id === 0) {
            api_error('Unrecognised tax class "' . $asked . '" on ' . $sku);
        }
        return (int)zc_filter('product.tax_class_id', $id, ['sku' => $sku, 'product' => $product]);
    }

    if (ZC_DEFAULT_TAX_CLASS_TITLE !== '') {
        $id = zc_tax_class_by_title($db, ZC_DEFAULT_TAX_CLASS_TITLE);
        if ($id !== 0) {
            return (int)zc_filter('product.tax_class_id', $id, ['sku' => $sku, 'product' => $product]);
        }
    }

    $row = $db->Execute(
        "SELECT tax_class_id FROM " . TABLE_TAX_CLASS . " ORDER BY tax_class_id LIMIT 1"
    );

    if ($row->EOF) {
        api_error('Product ' . $sku . ' is taxable but this store has no tax classes');
    }

    return (int)zc_filter(
        'product.tax_class_id',
        (int)$row->fields['tax_class_id'],
        ['sku' => $sku, 'product' => $product]
    );
}


function zc_country_code($db, $country_name): string
{
    static $cache = [];

    $name = trim((string)$country_name);
    if ($name === '') {
        return '';
    }
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $r = $db->Execute(
        "SELECT countries_iso_code_2 FROM " . TABLE_COUNTRIES
        . " WHERE countries_name = '" . zen_db_input($name) . "' LIMIT 1"
    );
    $code = $r->EOF ? '' : (string)($r->fields['countries_iso_code_2'] ?? '');

    if ($code === '') {
        // Country names change, and an order taken before a rename holds a
        // name the countries table no longer contains, so the lookup above
        // finds nothing. Two things feed this list: names Zen Cart itself has
        // replaced in its shipped data over the years, and names a store has
        // replaced by bringing its countries table into line with ISO 3166-1.
        // Either way these are Zen Cart's former names rather than one store's
        // data entry, which is why they belong here rather than in a store's
        // hook file.
        //
        // Add to this list rather than editing historical orders: the name on
        // an order is a record of what the customer was shown at the time.
        //
        // Ordering matters and is deliberate. The countries table is consulted
        // FIRST, above, so a name that is still current always wins and an
        // entry here only takes effect once that name has genuinely stopped
        // being current. That is what makes it safe to list names an install
        // may not have renamed -- or may never rename.
        static $renamed = [
            // Replaced in Zen Cart's own shipped data
            'Slovakia (Slovak Republic)'                 => 'SK',
            'Turkey'                                     => 'TR',
            // Countries that were renamed
            'Cape Verde'                                 => 'CV',
            'Czech Republic'                             => 'CZ',
            'Swaziland'                                  => 'SZ',
            'Macedonia, The Former Yugoslav Republic of' => 'MK',
            // Corrected spelling, capitalisation and abbreviation
            'Bosnia and Herzegowina'                     => 'BA',
            'Guinea-bissau'                              => 'GW',
            'Heard and Mc Donald Islands'                => 'HM',
            'St. Pierre and Miquelon'                    => 'PM',
            'St. Helena'                                 => 'SH',
            'Svalbard and Jan Mayen Islands'             => 'SJ',
            'Virgin Islands (British)'                   => 'VG',
            'Virgin Islands (U.S.)'                      => 'VI',
            'Wallis and Futuna Islands'                  => 'WF',
            // Superseded by ISO's formal long form
            'Bolivia'                                    => 'BO',
            'Iran (Islamic Republic of)'                 => 'IR',
            'Moldova'                                    => 'MD',
            'Taiwan'                                     => 'TW',
            'Vatican City State (Holy See)'              => 'VA',
            'Venezuela'                                  => 'VE',
        ];
        $code = $renamed[$name] ?? '';
    }

    $cache[$name] = $code;

    return $code;
}

/**
 * Read a country code off an order row.
 *
 * Prefers a real column where the install has one -- some address mods add
 * delivery_country_code / billing_country_code -- and falls back to resolving
 * the stored country name. The column check is one SHOW COLUMNS per request,
 * not per order.
 *
 * @param string $prefix 'delivery' or 'billing'
 */
function zc_order_country_code($db, array $fields, string $prefix): string
{
    static $has_column = [];

    if (!isset($has_column[$prefix])) {
        $col = $db->Execute(
            "SHOW COLUMNS FROM " . TABLE_ORDERS . " LIKE '" . zen_db_input($prefix) . "_country_code'"
        );
        $has_column[$prefix] = !$col->EOF;
    }

    if ($has_column[$prefix] && !empty($fields[$prefix . '_country_code'])) {
        return (string)$fields[$prefix . '_country_code'];
    }

    return zc_country_code($db, $fields[$prefix . '_country'] ?? '');
}

// ─── GET orders ──────────────────────────────────────────────────────────────

function get_orders($db, int $last_order): void
{
    if ($last_order < 0) {
        api_ok(['orders' => []]);
    }

    $result = $db->Execute(
        "SELECT * FROM " . TABLE_ORDERS . " WHERE orders_id > " . $last_order
    );

    $orders = [];
    $n = 0;
    while (!$result->EOF) {
        zc_periodic_flush(++$n);
        $order_id = (int)$result->fields['orders_id'];

        // Status name
        $status_row = $db->Execute(
            "SELECT orders_status_id, orders_status_name FROM " . TABLE_ORDERS_STATUS
            . " WHERE language_id = " . zc_default_language_id($db)
            . "   AND orders_status_id = " . (int)$result->fields['orders_status']
            . " LIMIT 1"
        );
        $order_status    = $status_row->EOF ? 'unknown' : $status_row->fields['orders_status_name'];
        $order_status_id = $status_row->EOF ? null : (int)$status_row->fields['orders_status_id'];

        // The first orders_status_history entry (lowest ID) is always the checkout comment,
        // inserted by order->create() before any payment module's after_process() runs.
        // We read it unconditionally; empty means no comment was left.
        $hist = $db->Execute(
            "SELECT comments FROM " . TABLE_ORDERS_STATUS_HISTORY
            . " WHERE orders_id = " . $order_id
            . " ORDER BY orders_status_history_id ASC LIMIT 1"
        );
        $first_comment = $hist->EOF ? '' : (string)($hist->fields['comments'] ?? '');

        $order = [
            'orderId'        => $order_id,
            'orderDate'      => $result->fields['date_purchased'],
            'status'         => $order_status,
            'lastModified'   => $result->fields['last_modified'],
            'paymentMethod'  => $result->fields['payment_method'],
            'paymentModule'  => $result->fields['payment_module_code'],
            'paymentRef'     => get_payment_ref($db, $order_id, $result->fields['payment_module_code']),
            'shippingMethod' => $result->fields['shipping_method'],
            'shippingModule' => $result->fields['shipping_module_code'],
            'currency'       => $result->fields['currency'],
            'conversionRate' => (float)$result->fields['currency_value'],
            'totalAmount'    => (float)$result->fields['order_total'],
            'taxAmount'      => (float)$result->fields['order_tax'],
            'shippingAmount' => 0.0,
            'shippingTax'    => 0.0,
            'comments'       => $first_comment,
            'customerId'     => (int)$result->fields['customers_id'],
            'email'          => $result->fields['customers_email_address'],
            'phone'          => $result->fields['customers_telephone'],
            'customerAddress' => [
                'name'       => $result->fields['customers_name'],
                'company'    => $result->fields['customers_company'],
                'addr1'      => $result->fields['customers_street_address'],
                'addr2'      => $result->fields['customers_suburb'],
                'city'       => $result->fields['customers_city'],
                'postalCode' => $result->fields['customers_postcode'],
                'state'      => $result->fields['customers_state'],
                'country'    => $result->fields['customers_country'],
            ],
            'billingAddress' => [
                'name'       => $result->fields['billing_name'],
                'company'    => $result->fields['billing_company'],
                'addr1'      => $result->fields['billing_street_address'],
                'addr2'      => $result->fields['billing_suburb'],
                'city'       => $result->fields['billing_city'],
                'postalCode' => $result->fields['billing_postcode'],
                'state'       => $result->fields['billing_state'],
                'country'     => $result->fields['billing_country'],
                'countryCode' => zc_order_country_code($db, $result->fields, 'billing'),
            ],
            'shippingAddress' => [
                'name'        => $result->fields['delivery_name'],
                'company'     => $result->fields['delivery_company'],
                'addr1'       => $result->fields['delivery_street_address'],
                'addr2'       => $result->fields['delivery_suburb'],
                'city'        => $result->fields['delivery_city'],
                'postalCode'  => $result->fields['delivery_postcode'],
                'state'       => $result->fields['delivery_state'],
                'country'     => $result->fields['delivery_country'],
                'countryCode' => zc_order_country_code($db, $result->fields, 'delivery'),
            ],
        ];

        // Order products (joined with products table to avoid a second query per item)
        $prod_result = $db->Execute(
            "SELECT op.*, p.products_image, p.products_weight"
            . " FROM " . TABLE_ORDERS_PRODUCTS . " op"
            . " LEFT JOIN " . TABLE_PRODUCTS . " p ON p.products_id = op.products_id"
            . " WHERE op.orders_id = " . $order_id
        );

        $items    = [];
        $vat_rate = 0.0;
        while (!$prod_result->EOF) {
            $op_id = (int)$prod_result->fields['orders_products_id'];
            $tax   = (float)$prod_result->fields['products_tax'];
            if ($tax > $vat_rate) {
                $vat_rate = $tax;
            }

            // Options / attributes
            $opts_result = $db->Execute(
                "SELECT products_options, products_options_values, options_values_price, price_prefix"
                . " FROM " . TABLE_ORDERS_PRODUCTS_ATTRIBUTES
                . " WHERE orders_id = " . $order_id . " AND orders_products_id = " . $op_id
            );
            $options  = [];
            $attr_str = '';
            while (!$opts_result->EOF) {
                $price  = (float)$opts_result->fields['options_values_price'];
                $prefix = $opts_result->fields['price_prefix'];
                $options[] = [
                    'name'   => $opts_result->fields['products_options'],
                    'value'  => $opts_result->fields['products_options_values'],
                    'price'  => $price,
                    'prefix' => $prefix,
                ];
                $attr_str .= ' [' . $opts_result->fields['products_options']
                           . ': ' . zen_output_string_protected($opts_result->fields['products_options_values']);
                if ($price != 0) {
                    $attr_str .= ' (' . $prefix . zen_round($price, 2) . ')';
                }
                $attr_str .= ']';
                $opts_result->MoveNext();
            }

            $item = [
                'productID' => (int)$prod_result->fields['products_id'],
                'sku'       => zc_filter('sku.export', $prod_result->fields['products_model'], ['order_id' => $order_id]),
                'name'      => $prod_result->fields['products_name'] . $attr_str,
                'image'     => HTTP_SERVER . DIR_WS_CATALOG . DIR_WS_IMAGES . $prod_result->fields['products_image'],
                'weight'    => (float)$prod_result->fields['products_weight'],
                'unitPrice' => round((float)$prod_result->fields['final_price'], 2),
                'quantity'  => (int)$prod_result->fields['products_quantity'],
                'tax'       => $tax,
                'options'   => $options,
            ];
            $items[] = zc_filter('order.item', $item, ['order_id' => $order_id, 'fields' => $prod_result->fields]);
            $prod_result->MoveNext();
        }

        // Order totals — coupon, shipping, and other fee modules as extra line items
        $totals_result = $db->Execute(
            "SELECT class, title, value FROM " . TABLE_ORDERS_TOTAL . " WHERE orders_id = " . $order_id
        );
        $ot_extra = [];
        $dest_country = $order['shippingAddress']['country'];
        while (!$totals_result->EOF) {
            $ot_vat = $vat_rate;
            $raw    = (float)$totals_result->fields['value'];
            $title  = strip_tags($totals_result->fields['title']);
            switch ($totals_result->fields['class']) {
                case 'ot_coupon':
                    $ot_extra['coupon'] = [
                        'sku'       => 'Discount',
                        'name'      => $title,
                        'quantity'  => 1,
                        'unitPrice' => -($raw / (1 + $ot_vat / 100)),
                        'tax'       => $ot_vat,
                    ];
                    break;
                case 'ot_shipping':
                    $order['shippingAmount'] = $raw / (1 + $ot_vat / 100);
                    $order['shippingTax']    = $ot_vat;
                    break;
                case 'ot_taxamo_assure':
                    if ($dest_country !== 'United Kingdom' && $vat_rate != 0) {
                        $ot_extra['taxamo_assure'] = [
                            'sku'       => 'VAT',
                            'name'      => $title,
                            'quantity'  => 1,
                            'unitPrice' => $raw,
                            'tax'       => 0.0,
                        ];
                    }
                    break;
                case 'ot_dhl_landedcost':
                    if ($raw > 0.0) {
                        $ot_extra['dhl_landedcost'] = [
                            'sku'       => 'DHL_LANDEDCOST',
                            'name'      => $title,
                            'quantity'  => 1,
                            'unitPrice' => $raw,
                            'tax'       => 0.0,
                        ];
                    }
                    break;
                case 'ot_easproject_ioss':
                    if ($raw > 0.0) {
                        $ot_extra['easproject_ioss'] = [
                            'sku'       => 'IOSS_VAT',
                            'name'      => $title,
                            'quantity'  => 1,
                            'unitPrice' => $raw,
                            'tax'       => 0.0,
                        ];
                    }
                    break;
                default:
                    // Seam for store-specific order-total line items (e.g. a
                    // custom fee/discount module) without editing this file.
                    // Return an item array shaped like the ones above to have
                    // it appended; anything else (including the default null)
                    // is ignored.
                    $extra = zc_filter('order.total_line', null, [
                        'class'    => $totals_result->fields['class'],
                        'title'    => $title,
                        'value'    => $raw,
                        'vat_rate' => $ot_vat,
                        'order'    => $order,
                    ]);
                    if (is_array($extra)) {
                        $ot_extra['hook_' . count($ot_extra)] = $extra;
                    }
                    break;
            }
            $totals_result->MoveNext();
        }

        foreach (['coupon', 'taxamo_assure', 'dhl_landedcost', 'easproject_ioss'] as $key) {
            if (isset($ot_extra[$key])) {
                $items[] = $ot_extra[$key];
            }
        }
        foreach ($ot_extra as $key => $extra_item) {
            if (strpos($key, 'hook_') === 0) {
                $items[] = $extra_item;
            }
        }

        if (isset($ot_extra['taxamo_assure']) && class_exists('taxamo_assure')) {
            $taxamo = new taxamo_assure();
            $tx = $taxamo->GetOrderTransaction($order_id);
            if ($tx !== null) {
                $order['taxamoAssure'] = $tx;
            }
        }

        $order['items'] = $items;
        $orders[]       = zc_filter('order', $order, ['row' => $result->fields]);
        $result->MoveNext();
    }

    api_ok(zc_filter('orders.response', ['orders' => $orders]));
}

// ─── POST updateOrderComments ─────────────────────────────────────────────────

function update_order_comments($db, array $data): void
{
    $data = zc_filter('order_comment.incoming', $data);

    $order_id = isset($data['orderId']) ? (int)$data['orderId'] : 0;
    $comment  = isset($data['comment']) ? trim((string)$data['comment']) : '';
    if (!$order_id || $comment === '') {
        api_error('orderId and comment are required');
    }

    $exists = $db->Execute(
        "SELECT orders_id FROM " . TABLE_ORDERS . " WHERE orders_id = " . $order_id . " LIMIT 1"
    );
    if ($exists->EOF) {
        api_error('Order ' . $order_id . ' not found', 404);
    }

    $status     = isset($data['status']) ? (int)$data['status'] : 4;
    $updated_by = isset($data['updatedBy']) ? trim((string)$data['updatedBy']) : 'FreeBooks';
    if ($updated_by === '') $updated_by = 'FreeBooks';
    $result = zc_update_order_history($db, $order_id, $comment, $status, 1, $updated_by);
    if ($result > 0) {
        api_ok(['message' => 'Order ' . $order_id . ' updated']);
    } else {
        api_error('Failed to update order history', 500);
    }
}

// ─── POST updateProducts ──────────────────────────────────────────────────────

function update_products($db, array $data): void
{
    if (!defined('TABLE_PRODUCTS_DISCOUNT_QUANTITY')) {
        define('TABLE_PRODUCTS_DISCOUNT_QUANTITY', DB_PREFIX . 'products_discount_quantity');
    }
    $tbl_check = $db->Execute("SHOW TABLES LIKE '" . DB_PREFIX . "products_discount_quantity'");
    $has_qty_discount_table = !$tbl_check->EOF;

    $col = static function (string $col) use ($db): bool {
        return !$db->Execute("SHOW COLUMNS FROM " . TABLE_PRODUCTS . " LIKE '" . $col . "'")->EOF;
    };
    $has_additional_skus        = $col('additional_skus');
    $has_products_condition     = $col('products_condition');
    $has_products_dim_type      = $col('products_dim_type');
    $has_ships_in_own_box       = $col('product_ships_in_own_box');
    // Two names for one field. Zen Cart 2.0.0 adopted Numinix's New Product
    // Fields column into core and renamed it:
    //
    //   mysql_upgrade_zencart_200.sql:161
    //   ALTER TABLE products CHANGE products_ready_to_ship
    //                               product_ships_in_own_box TINYINT DEFAULT NULL;
    //
    // Neither name is in 1.5.8a core -- the old one exists only where NPF was
    // installed. So the pair below is a version fallback, not redundancy: 2.x
    // core name first, 1.5.8a + NPF name second. On a 2.x store the second
    // branch is unreachable, which is exactly why it is easy to mistake for
    // dead code and "simplify" away. Do not.
    $has_ready_to_ship          = $col('products_ready_to_ship');
    $has_hs_code                = $col('hs_code');
    $has_country_of_origin      = $col('country_of_origin');
    $has_zc_length              = $col('products_length');
    $has_zc_width               = $col('products_width');
    $has_zc_height              = $col('products_height');
    $has_custom_length          = $col('product_length');
    $has_custom_width           = $col('product_width');
    $has_custom_height          = $col('product_height');

    $products    = $data['products'] ?? [];
    $added       = [];
    $updated     = [];
    $not_updated = [];

    $n = 0;
    foreach ($products as $product) {
        zc_periodic_flush(++$n);
        $product   = zc_filter('product.incoming', $product);
        $source_sku = trim((string)($product['products_model'] ?? ''));
        $sku        = zc_filter('sku.import', $source_sku);
        if ($sku === '') {
            $not_updated[] = '(empty SKU)';
            continue;
        }

        // Language
        $lang_code = strtolower(substr((string)($product['languageCode'] ?? 'en'), 0, 2));
        $lang_row  = $db->Execute(
            "SELECT languages_id FROM " . TABLE_LANGUAGES
            . " WHERE code = '" . zen_db_input($lang_code) . "' LIMIT 1"
        );
        if ($lang_row->EOF) {
            api_error('Unrecognised language: ' . $lang_code);
        }
        $lang_id = (int)$lang_row->fields['languages_id'];

        // Product type
        $type_name = (string)($product['productType'] ?? '');
        $type_row  = $db->Execute(
            "SELECT type_id FROM " . TABLE_PRODUCT_TYPES
            . " WHERE type_name = '" . zen_db_input($type_name) . "' LIMIT 1"
        );
        if ($type_row->EOF) {
            api_error('Bad product type: ' . $type_name);
        }
        $type_id = (int)$type_row->fields['type_id'];

        // Manufacturer — optional; 0 means none in ZenCart
        $mfr_name = (string)($product['manufacturer'] ?? '');
        $mfr_id   = 0;
        if ($mfr_name !== '') {
            $mfr_row = $db->Execute(
                "SELECT manufacturers_id FROM " . TABLE_MANUFACTURERS
                . " WHERE manufacturers_name = '" . zen_db_input($mfr_name) . "' LIMIT 1"
            );
            if ($mfr_row->EOF) {
                api_error('Bad manufacturer: ' . $mfr_name . ' (' . $sku . ')');
            }
            $mfr_id = (int)$mfr_row->fields['manufacturers_id'];
        }

        // Category — must be a leaf (no sub-categories)
        $cat_name = (string)($product['productCategory'] ?? '');
        $cat_row  = $db->Execute(
            "SELECT categories_id FROM " . TABLE_CATEGORIES_DESCRIPTION
            . " WHERE categories_name = '" . zen_db_input($cat_name) . "' AND language_id = " . $lang_id
            . " LIMIT 1"
        );
        if ($cat_row->EOF) {
            api_error('Category not found: ' . $cat_name . ' (' . $sku . ')');
        }
        $cat_id  = (int)$cat_row->fields['categories_id'];
        $sub_row = $db->Execute(
            "SELECT categories_id FROM " . TABLE_CATEGORIES . " WHERE parent_id = " . $cat_id . " LIMIT 1"
        );
        if (!$sub_row->EOF) {
            api_error('Category is not a leaf node: ' . $cat_name . ' (' . $sku . ')');
        }

        // Image — sanitise directory and filename to prevent path traversal.
        // Both feed a path under images/, so a traversal sequence ("../") or a
        // non-image extension (".php") here would be an arbitrary-write / RCE
        // vector. basename() strips any directory component, and the extension
        // allowlist blocks executable/SVG payloads. SVG is excluded on purpose:
        // it can carry inline script and would run in the store's origin.
        $img_dir  = '';
        $img_file = basename((string)($product['productImageFileName'] ?? ''));
        if ($img_file !== '' && !preg_match('/^[^\/\\\\]+\.(jpe?g|png|gif|webp|bmp|tiff?)$/i', $img_file)) {
            api_error('Illegal image filename: ' . $img_file);
        }
        $img_path = $img_file;
        if (!empty($product['productImageDirectory']) && is_string($product['productImageDirectory'])) {
            $img_dir = basename(preg_replace('/[\/\\\\].*/', '', $product['productImageDirectory']));
            if ($img_dir === '.' || $img_dir === '..') $img_dir = '';
            if ($img_dir !== '') $img_path = $img_dir . '/' . $img_file;
        }
        if (!empty($product['productImageData'])) {
            $contents = base64_decode(str_replace(['-', '_'], ['+', '/'], $product['productImageData']));
            $dest_dir = DIR_FS_CATALOG . '/images/' . ($img_dir !== '' ? $img_dir . '/' : '');
            if ($img_dir !== '' && !is_dir($dest_dir)) {
                mkdir($dest_dir, 0755, true);
            }
            if (file_put_contents($dest_dir . $img_file, $contents) === false) {
                api_error('Failed to write image: ' . $img_path);
            }
        }

        // ERPNext HTML editor emits <ol> instead of <ul> — normalise
        if (isset($product['productZencartDescription'])) {
            $product['productZencartDescription'] = str_ireplace(
                ['<ol>', '</ol>'], ['<ul>', '</ul>'],
                $product['productZencartDescription']
            );
        }

        // Build products table row
        $prod_row = [
            'products_model'       => $sku,
            'products_type'        => $type_id,
            'manufacturers_id'     => $mfr_id,
            'master_categories_id' => $cat_id,
        ];
        if ($img_file !== '')
            $prod_row['products_image']          = $img_path;
        if (isset($product['quantity']))
            $prod_row['products_quantity']        = zc_filter(
                'quantity.import',
                max(0.0, (float)$product['quantity']),
                ['sku' => $sku, 'source_sku' => $source_sku, 'product' => $product, 'task' => 'updateProducts']
            );
        if (isset($product['product_virtual']))
            $prod_row['products_virtual']         = (int)$product['product_virtual'];
        if (isset($product['dateUpdated']))
            $prod_row['products_last_modified']   = $product['dateUpdated'];
        if (isset($product['dateAvailable']))
            $prod_row['products_date_available']  = $product['dateAvailable'];
        if (isset($product['productWeight']))
            $prod_row['products_weight']          = (float)$product['productWeight'];
        if (isset($product['productStatus']))
            $prod_row['products_status']          = (int)$product['productStatus'];
        if (isset($product['productHidePrice']))
            $prod_row['product_is_call']          = (int)$product['productHidePrice'];
        if (isset($product['productFreeShipping']))
            $prod_row['product_is_always_free_shipping'] = (int)$product['productFreeShipping'];
        if (isset($product['productZencartSortOrder']))
            $prod_row['products_sort_order']      = (int)$product['productZencartSortOrder'];
        elseif (isset($product['productSortOrder']))
            $prod_row['products_sort_order']      = (int)$product['productSortOrder'];
        // Dimensions: prefer standard ZenCart columns, fall back to custom columns
        if (isset($product['productZencartLength'])) {
            if ($has_zc_length)
                $prod_row['products_length'] = (float)$product['productZencartLength'];
            elseif ($has_custom_length)
                $prod_row['product_length']  = (float)$product['productZencartLength'];
        }
        if (isset($product['productZencartWidth'])) {
            if ($has_zc_width)
                $prod_row['products_width']  = (float)$product['productZencartWidth'];
            elseif ($has_custom_width)
                $prod_row['product_width']   = (float)$product['productZencartWidth'];
        }
        if (isset($product['productZencartHeight'])) {
            if ($has_zc_height)
                $prod_row['products_height'] = (float)$product['productZencartHeight'];
            elseif ($has_custom_height)
                $prod_row['product_height']  = (float)$product['productZencartHeight'];
        }
        // productZencartDimUnits (dimension unit type) — only if column exists
        if (isset($product['productZencartDimUnits']) && $has_products_dim_type)
            $prod_row['products_dim_type']        = $product['productZencartDimUnits'];
        // Ready-to-ship: ZenCart 2.x renamed the column
        if (isset($product['productZencartReadyToShip'])) {
            if ($has_ships_in_own_box)
                $prod_row['product_ships_in_own_box'] = (int)$product['productZencartReadyToShip'];
            elseif ($has_ready_to_ship)
                $prod_row['products_ready_to_ship']   = (int)$product['productZencartReadyToShip'];
        }
        if (isset($product['productZencartOemPartnumbers']) && is_string($product['productZencartOemPartnumbers']) && $has_additional_skus)
            $prod_row['additional_skus']          = $product['productZencartOemPartnumbers'];
        if (isset($product['productZencartCondition']) && $has_products_condition)
            $prod_row['products_condition']       = $product['productZencartCondition'];
        if (isset($product['productHsCode']) && $has_hs_code)
            $prod_row['hs_code']                  = $product['productHsCode'];
        if (isset($product['productCountryOrigin']) && $has_country_of_origin)
            $prod_row['country_of_origin']        = $product['productCountryOrigin'];
        // array_key_exists, not isset() or a truthiness test on the resolved id.
        //
        // The old line was `if ($tax_class_id)`, which skipped the assignment
        // whenever the class came out as 0. On an UPDATE that leaves the
        // existing value untouched, so marking a taxable product as no longer
        // taxable did nothing at all -- silently, and permanently.
        //
        // But it cannot simply always be written either. A payload that omits
        // productTaxable must leave the column alone: this store has 18,120 of
        // 18,122 products in one tax class, and writing 0 for an absent field
        // would untax the entire catalogue in a single sync. So the field being
        // PRESENT is what authorises the write, exactly as for every other
        // optional column in this array.
        if (array_key_exists('productTaxable', $product)) {
            $tax_class_id = zc_tax_class_id($db, $product, $sku);
            $prod_row['products_tax_class_id'] = $tax_class_id;

            // A sync that untaxes something should be findable afterwards
            // without diffing the catalogue.
            if ($tax_class_id === 0) {
                error_log('freebooks-zencart.php: clearing tax class on ' . $sku . ' (productTaxable is false)');
            }
        }

        if (!empty($product['priceDiscountType'])) {
            $prod_row['products_discount_type']      = (int)$product['priceDiscountType'];
            $prod_row['products_quantity_order_min'] = (int)($product['price_levels'][0]['qty'] ?? 1);
            $prod_row['products_price']              = (float)($product['price_levels'][0]['amount'] ?? 0);
        } else {
            $prod_row['products_discount_type'] = 0;
            if (isset($product['retailPrice'])) {
                $prod_row['products_price'] = (float)$product['retailPrice'];
            }
        }

        // Build products_description row
        // productDescription (plain text) → name; productZencartDescription (HTML) → body
        $desc_row = ['language_id' => $lang_id];
        if (isset($product['productName']))
            $desc_row['products_name'] = substr($product['productName'], 0, 64);
        if (isset($product['productDescription']))
            $desc_row['products_name'] = substr($product['productDescription'], 0, 64);
        if (isset($product['productZencartDescription']))
            $desc_row['products_description'] = $product['productZencartDescription'];
        if (isset($product['productURL']))
            $desc_row['products_url'] = str_replace('http://', '', $product['productURL']);

        // Insert or update
        $existing = $db->Execute(
            "SELECT products_id FROM " . TABLE_PRODUCTS
            . " WHERE products_model = '" . zen_db_input($sku) . "' LIMIT 1"
        );
        $is_new = $existing->EOF;
        $row_ctx = [
            'product'     => $product,
            'sku'         => $sku,
            'lang_id'     => $lang_id,
            'is_new'      => $is_new,
            'products_id' => $is_new ? null : (int)$existing->fields['products_id'],
        ];
        $prod_row = zc_filter('product.row', $prod_row, $row_ctx);
        $desc_row = zc_filter('product.desc_row', $desc_row, $row_ctx);

        if ($is_new) {
            $prod_row['products_date_added'] = date('Y-m-d H:i:s');
            zen_db_perform(TABLE_PRODUCTS, $prod_row);
            $prod_id = (int)zen_db_insert_id();
            $db->Execute(
                "INSERT INTO " . TABLE_PRODUCTS_TO_CATEGORIES
                . " SET categories_id = " . $cat_id . ", products_id = " . $prod_id
            );
            $desc_row['products_id'] = $prod_id;
            zen_db_perform(TABLE_PRODUCTS_DESCRIPTION, $desc_row);
            $added[] = $sku;
        } else {
            $prod_id = (int)$existing->fields['products_id'];
            zen_db_perform(TABLE_PRODUCTS, $prod_row, 'update', "products_id = " . $prod_id);
            // Category not updated on existing products: a product can belong to multiple
            // categories in ZenCart, and overwriting the link would lose those extra mappings.
            zen_db_perform(TABLE_PRODUCTS_DESCRIPTION, $desc_row, 'update',
                "products_id = " . $prod_id . " AND language_id = " . $lang_id);
            $updated[] = $sku;
        }

        zc_do_action('product.saved', [
            'products_id' => $prod_id,
            'sku'         => $sku,
            'product'     => $product,
            'is_new'      => $is_new,
            'lang_id'     => $lang_id,
        ]);

        // Write quantity-tier discount rows. Always wipe and rewrite so stale tiers don't accumulate.
        if ($has_qty_discount_table) {
            $db->Execute("DELETE FROM " . TABLE_PRODUCTS_DISCOUNT_QUANTITY . " WHERE products_id = " . $prod_id);
            if (!empty($product['price_levels']) && !empty($product['priceDiscountType'])) {
                foreach ($product['price_levels'] as $tier) {
                    $db->Execute(
                        "INSERT INTO " . TABLE_PRODUCTS_DISCOUNT_QUANTITY
                        . " SET products_id = " . $prod_id
                        . ", discount_qty = " . (int)($tier['qty'] ?? 0)
                        . ", discount_price = " . (float)($tier['amount'] ?? 0)
                    );
                }
            }
        }

        // ── Attribute upsert (Master Stock options/variants) ───────────────────
        // Runs only when FreeBooks sends an 'attributes' block. Writes native ZenCart
        // products_options / products_options_values / products_attributes tables
        // (vanilla ZenCart 1.5.8+). A dormant POSM branch is gated behind a
        // SHOW TABLES check — it activates automatically when the plugin is installed
        // with no change required on the FreeBooks side.
        if (!empty($product['attributes'])) {
            $attr_options  = $product['attributes']['options']  ?? [];
            $attr_variants = $product['attributes']['variants'] ?? [];

            // Capability detection (done once per request, cached in static)
            static $posm_available = null;
            if ($posm_available === null) {
                $posm_check   = $db->Execute(
                    "SHOW TABLES LIKE '" . DB_PREFIX . "products_options_stock'"
                );
                $posm_available = !$posm_check->EOF;
                if ($posm_available) {
                    define('TABLE_PRODUCTS_OPTIONS_STOCK',
                        DB_PREFIX . 'products_options_stock');
                    define('TABLE_PRODUCTS_OPTIONS_STOCK_ATTRIBUTES',
                        DB_PREFIX . 'products_options_stock_attributes');
                }
            }

            // Language for READING option names back. The INSERTs below already
            // write a row per installed language, which is correct; it is the
            // lookup that has to agree with them, or an existing option is
            // never found and a duplicate is created on every sync.
            $default_lang = zc_default_language_id($db);

            // Resolve or create a products_options row by name.
            // Returns the options_id.
            $resolve_option = static function (string $name) use ($db, $default_lang): int {
                $row = $db->Execute(
                    "SELECT products_options_id FROM " . TABLE_PRODUCTS_OPTIONS
                    . " WHERE products_options_name = '" . zen_db_input($name) . "'"
                    . "   AND language_id = " . $default_lang . " LIMIT 1"
                );
                if (!$row->EOF) return (int)$row->fields['products_options_id'];
                // removeFromQueryCache=true: this exact query text repeats across
                // options created within the same request, and ZenCart's queryFactory
                // memoizes Execute() results by raw SQL — without this, the 2nd+ new
                // option in one updateProducts call reads back the 1st call's stale
                // MAX() and collides onto the same id (INSERT IGNORE then silently
                // no-ops), losing the option entirely. Confirmed live.
                $max = $db->Execute("SELECT COALESCE(MAX(products_options_id),0) AS m FROM " . TABLE_PRODUCTS_OPTIONS, null, false, 0, true);
                $new_id = (int)$max->fields['m'] + 1;
                $langs  = $db->Execute("SELECT languages_id FROM " . TABLE_LANGUAGES);
                while (!$langs->EOF) {
                    $db->Execute(
                        "INSERT IGNORE INTO " . TABLE_PRODUCTS_OPTIONS
                        . " (products_options_id, language_id, products_options_name, products_options_type)"
                        . " VALUES (" . $new_id . ", " . (int)$langs->fields['languages_id']
                        . ", '" . zen_db_input($name) . "', 0)"
                    );
                    $langs->MoveNext();
                }
                return $new_id;
            };

            // Resolve or create a products_options_values row by name.
            // Ensures the join table row for (options_id, values_id) exists.
            // Returns the options_values_id.
            $resolve_option_value = static function (string $name, int $options_id) use ($db, $default_lang): int {
                // Find existing value by name, then verify it's linked to this option
                $row = $db->Execute(
                    "SELECT v.products_options_values_id"
                    . " FROM " . TABLE_PRODUCTS_OPTIONS_VALUES . " v"
                    . " INNER JOIN " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS . " j"
                    . "   ON j.products_options_values_id = v.products_options_values_id"
                    . "   AND j.products_options_id = " . $options_id
                    . " WHERE v.products_options_values_name = '" . zen_db_input($name) . "'"
                    . "   AND v.language_id = " . $default_lang . " LIMIT 1"
                );
                if (!$row->EOF) return (int)$row->fields['products_options_values_id'];

                // Create new value. removeFromQueryCache=true — see the matching
                // comment in $resolve_option above; same collision, same fix.
                $max    = $db->Execute("SELECT COALESCE(MAX(products_options_values_id),0) AS m FROM " . TABLE_PRODUCTS_OPTIONS_VALUES, null, false, 0, true);
                $new_id = (int)$max->fields['m'] + 1;
                $langs  = $db->Execute("SELECT languages_id FROM " . TABLE_LANGUAGES);
                while (!$langs->EOF) {
                    $db->Execute(
                        "INSERT IGNORE INTO " . TABLE_PRODUCTS_OPTIONS_VALUES
                        . " (products_options_values_id, language_id, products_options_values_name)"
                        . " VALUES (" . $new_id . ", " . (int)$langs->fields['languages_id']
                        . ", '" . zen_db_input($name) . "')"
                    );
                    $langs->MoveNext();
                }
                // Link value to option
                $db->Execute(
                    "INSERT IGNORE INTO " . TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS
                    . " (products_options_id, products_options_values_id)"
                    . " VALUES (" . $options_id . ", " . $new_id . ")"
                );
                return $new_id;
            };

            // Pass 1: resolve all option IDs and, per option, the deduplicated value IDs.
            // We do this before touching products_attributes so we can delete-then-reinsert
            // cleanly — avoiding the duplicate/wrong-option accumulation that upsert-then-prune
            // suffers when the store already has rows under different option IDs.
            $managed_options = []; // [ [options_id, sort_order, [[values_id, ...], ...]] ]
            $opt_sort = 0;
            foreach ($attr_options as $opt) {
                $opt_name = trim((string)($opt['name'] ?? ''));
                if ($opt_name === '') { $opt_sort++; continue; }
                $options_id = $resolve_option($opt_name);

                $seen_val_ids = [];
                $value_ids    = [];
                foreach ($opt['values'] ?? [] as $val) {
                    $val_name = trim((string)($val['name'] ?? ''));
                    if ($val_name === '') continue;
                    $values_id = $resolve_option_value($val_name, $options_id);
                    if (!isset($seen_val_ids[$values_id])) {
                        $value_ids[]              = $values_id;
                        $seen_val_ids[$values_id] = true;
                    }
                }

                if (!empty($value_ids)) {
                    $managed_options[] = [
                        'options_id' => $options_id,
                        'sort_order' => (int)($opt['sortOrder'] ?? $opt_sort),
                        'value_ids'  => $value_ids,
                    ];
                }
                $opt_sort++;
            }

            // Pass 2: delete all existing products_attributes rows for our managed options,
            // then reinsert from scratch. This is idempotent and prevents stale rows from
            // accumulating across uploads.
            foreach ($managed_options as $mo) {
                $db->Execute(
                    "DELETE FROM " . TABLE_PRODUCTS_ATTRIBUTES
                    . " WHERE products_id = " . $prod_id
                    . "   AND options_id = "  . $mo['options_id']
                );
                foreach ($mo['value_ids'] as $values_id) {
                    $db->Execute(
                        "INSERT INTO " . TABLE_PRODUCTS_ATTRIBUTES
                        . " (products_id, options_id, options_values_id,"
                        . "  options_values_price, price_prefix, products_options_sort_order)"
                        . " VALUES (" . $prod_id . ", " . $mo['options_id'] . ", " . $values_id
                        . ", 0.0000, '', " . $mo['sort_order'] . ")"
                    );
                }
            }

            // ── POSM per-variant stock (dormant on vanilla ZenCart) ────────────
            // Activated automatically when the products_options_stock table exists.
            // pos_hash reproduces generate_pos_option_hash(): md5(products_id . sorted(options_id . values_id))
            if ($posm_available && !empty($attr_variants)) {
                // Build a map: variant sku => pos_id (for update vs insert)
                $existing_pos = [];
                $pos_rows = $db->Execute(
                    "SELECT pos_id, pos_model FROM " . TABLE_PRODUCTS_OPTIONS_STOCK
                    . " WHERE products_id = " . $prod_id
                );
                while (!$pos_rows->EOF) {
                    $existing_pos[(string)$pos_rows->fields['pos_model']] = (int)$pos_rows->fields['pos_id'];
                    $pos_rows->MoveNext();
                }

                $seen_pos_ids = [];
                foreach ($attr_variants as $variant) {
                    $v_source_sku = trim((string)($variant['sku'] ?? ''));
                    $v_sku = zc_filter('sku.import', $v_source_sku);
                    $v_qty = zc_filter(
                        'quantity.import',
                        (float)($variant['quantity'] ?? 0),
                        ['sku' => $v_sku, 'source_sku' => $v_source_sku, 'product' => $variant, 'task' => 'updateProducts']
                    );
                    if ($v_sku === '') continue;

                    // Resolve each option name → (options_id, values_id) for this variant
                    $combo = []; // [options_id => values_id]
                    foreach ($variant['options'] ?? [] as $vo) {
                        $vo_name = trim((string)($vo['name']  ?? ''));
                        $vo_val  = trim((string)($vo['value'] ?? ''));
                        if ($vo_name === '' || $vo_val === '') continue;
                        $oid = $resolve_option($vo_name);
                        $vid = $resolve_option_value($vo_val, $oid);
                        $combo[$oid] = $vid;
                    }
                    if (empty($combo)) continue;

                    // Compute pos_hash
                    ksort($combo);
                    $hash_in = (string)(int)$prod_id;
                    foreach ($combo as $oid => $vid) {
                        $hash_in .= (string)$oid . (string)$vid;
                    }
                    $pos_hash = md5($hash_in);

                    if (isset($existing_pos[$v_sku])) {
                        $pos_id = $existing_pos[$v_sku];
                        $db->Execute(
                            "UPDATE " . TABLE_PRODUCTS_OPTIONS_STOCK
                            . " SET products_quantity = " . $v_qty
                            . ", pos_hash = '" . $pos_hash . "'"
                            . ", last_modified = NOW()"
                            . " WHERE pos_id = " . $pos_id
                        );
                    } else {
                        $db->Execute(
                            "INSERT INTO " . TABLE_PRODUCTS_OPTIONS_STOCK
                            . " (products_id, pos_name_id, products_quantity, pos_hash, pos_model, pos_date, last_modified)"
                            . " VALUES (" . $prod_id . ", 1, " . $v_qty
                            . ", '" . $pos_hash . "', '" . zen_db_input($v_sku) . "'"
                            . ", NOW(), NOW())"
                        );
                        $pos_id = (int)zen_db_insert_id();
                        // Link attributes
                        foreach ($combo as $oid => $vid) {
                            $db->Execute(
                                "INSERT IGNORE INTO " . TABLE_PRODUCTS_OPTIONS_STOCK_ATTRIBUTES
                                . " (pos_id, products_id, options_id, options_values_id)"
                                . " VALUES (" . $pos_id . ", " . $prod_id . ", " . $oid . ", " . $vid . ")"
                            );
                        }
                    }
                    $seen_pos_ids[$pos_id] = true;
                }

                // Remove POSM rows for variants no longer sent
                foreach ($existing_pos as $e_sku => $e_pos_id) {
                    if (!isset($seen_pos_ids[$e_pos_id])) {
                        $db->Execute(
                            "DELETE FROM " . TABLE_PRODUCTS_OPTIONS_STOCK_ATTRIBUTES
                            . " WHERE pos_id = " . $e_pos_id
                        );
                        $db->Execute(
                            "DELETE FROM " . TABLE_PRODUCTS_OPTIONS_STOCK
                            . " WHERE pos_id = " . $e_pos_id
                        );
                    }
                }
            }
        }
    }

    api_ok(zc_filter('products.response', ['added' => $added, 'updated' => $updated, 'notUpdated' => $not_updated]));
}

// ─── POST updateProductsQty ───────────────────────────────────────────────────

function update_products_qty($db, array $data): void
{
    $products    = $data['products'] ?? [];
    $updated     = [];
    $not_updated = [];

    $n = 0;
    foreach ($products as $product) {
        zc_periodic_flush(++$n);
        $product    = zc_filter('product_qty.incoming', $product);
        $source_sku = trim((string)($product['products_model'] ?? ''));
        $sku        = zc_filter('sku.import', $source_sku);
        if ($sku === '') {
            $not_updated[] = '(empty SKU)';
            continue;
        }

        $row = [];
        if (isset($product['quantity'])) {
            $row['products_quantity'] = zc_filter(
                'quantity.import',
                max(0.0, (float)$product['quantity']),
                ['sku' => $sku, 'source_sku' => $source_sku, 'product' => $product, 'task' => 'updateProductsQty']
            );
        }
        if (isset($product['price'])) {
            $row['products_price'] = (float)$product['price'];
        }
        if (empty($row) && !zc_has_hook('product_qty.row')) {
            // Nothing to write and no hook registered to possibly add
            // something — skip the lookup below entirely, as before hooks
            // existed.
            $not_updated[] = $sku;
            continue;
        }

        $existing = $db->Execute(
            "SELECT products_id FROM " . TABLE_PRODUCTS
            . " WHERE products_model = '" . zen_db_input($sku) . "' LIMIT 1"
        );
        if ($existing->EOF) {
            $not_updated[] = $sku;
            continue;
        }
        $products_id = (int)$existing->fields['products_id'];

        $row = zc_filter('product_qty.row', $row, [
            'sku'         => $sku,
            'source_sku'  => $source_sku,
            'products_id' => $products_id,
            'product'     => $product,
        ]);
        if (empty($row)) {
            $not_updated[] = $sku;
            continue;
        }

        zen_db_perform(TABLE_PRODUCTS, $row, 'update', "products_id = " . $products_id);
        $updated[] = $sku;
    }

    api_ok(zc_filter('products_qty.response', ['updated' => $updated, 'notUpdated' => $not_updated]));
}

// ─── Router ───────────────────────────────────────────────────────────────────

if (defined('ZC_API_LIB_ONLY')) {
    return;
}


check_access($db);
zc_load_hooks();

$task = (string)($_REQUEST['task'] ?? '');

zc_do_action('boot', ['task' => $task]);

if ($task === 'version') {
    api_ok([
        'apiVersion' => ZC_API_VERSION,
        'file'       => 'freebooks-zencart.php',
        'hookDir'    => ZC_HOOK_DIR,
        'hooks'      => array_values(array_unique(array_merge(
            array_keys($GLOBALS['zc_hooks']['filters']),
            array_keys($GLOBALS['zc_hooks']['actions'])
        ))),
    ]);
} elseif ($task === 'orders') {
    $last_order = isset($_REQUEST['last_order']) ? (int)$_REQUEST['last_order'] : -1;
    get_orders($db, $last_order);
} elseif (in_array($task, ['updateOrderComments', 'updateProducts', 'updateProductsQty'], true)) {
    $data = zc_read_json_body();
    if ($data === null) {
        api_error('Request body must be valid JSON: ' . json_last_error_msg());
    }
    if ($task === 'updateOrderComments') {
        update_order_comments($db, $data);
    } elseif ($task === 'updateProducts') {
        update_products($db, $data);
    } else {
        update_products_qty($db, $data);
    }
} elseif ($task !== '' && zc_has_hook('task.' . $task)) {
    // Lets a store add a wholly custom endpoint (registered in a hook file)
    // without touching this shared file. The hook is responsible for calling
    // api_ok()/api_error() itself, same as any built-in task handler.
    zc_do_action('task.' . $task, [
        'task'    => $task,
        'request' => $_REQUEST,
        'body'    => zc_read_json_body(),
    ]);
    api_error('Custom task "' . $task . '" did not send a response', 500);
} else {
    api_error('Unknown task: ' . $task, 404);
}

require(DIR_WS_INCLUDES . 'application_bottom.php');
