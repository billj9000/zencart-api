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

require('includes/application_top.php');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

define('FB_API_VERSION', 2);
define('FB_DEFAULT_TAX_CLASS_ID', 1);

// ─── Response helpers ────────────────────────────────────────────────────────

function api_ok($data = null): void
{
    $body = ['version' => FB_API_VERSION, 'ok' => true];
    if ($data !== null) {
        $body['data'] = $data;
    }
    echo json_encode($body);
    exit;
}

function api_error(string $message, int $http_status = 400): void
{
    http_response_code($http_status);
    echo json_encode(['version' => FB_API_VERSION, 'ok' => false, 'error' => $message]);
    exit;
}

// ─── Authentication ───────────────────────────────────────────────────────────

function check_access($db): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
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
function fb_update_order_history($db, int $order_id, string $comment, int $status, int $notify): int
{
    if (function_exists('zen_update_orders_history')) {
        return (int) zen_update_orders_history($order_id, $comment, 'FreeBooks', $status, $notify, false);
    }

    // Fallback: manual insert + order status update
    $db->Execute(
        "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY
        . " (orders_id, orders_status_id, date_added, customer_notified, comments, updated_by)"
        . " VALUES ("
            . $order_id . ", "
            . $status . ", NOW(), "
            . $notify . ", '"
            . zen_db_input($comment) . "', 'FreeBooks')"
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
    if (!defined('TABLE_SQUARE_PAYMENTS')) {
        define('TABLE_SQUARE_PAYMENTS', DB_PREFIX . 'square_payments');
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
    while (!$result->EOF) {
        $order_id = (int)$result->fields['orders_id'];

        // Status name
        $status_row = $db->Execute(
            "SELECT orders_status_id, orders_status_name FROM " . TABLE_ORDERS_STATUS
            . " WHERE language_id = 1 AND orders_status_id = " . (int)$result->fields['orders_status']
            . " LIMIT 1"
        );
        $order_status    = $status_row->EOF ? 'unknown' : $status_row->fields['orders_status_name'];
        $order_status_id = $status_row->EOF ? null : (int)$status_row->fields['orders_status_id'];

        // First non-empty comment from history
        $first_comment = '';
        if ($order_status_id !== null) {
            $hist = $db->Execute(
                "SELECT comments FROM " . TABLE_ORDERS_STATUS_HISTORY
                . " WHERE orders_id = " . $order_id
                . " AND comments IS NOT NULL AND comments != ''"
                . " ORDER BY date_added ASC LIMIT 1"
            );
            $first_comment = $hist->EOF ? '' : $hist->fields['comments'];
        }

        // Shipping total
        $ship_row = $db->Execute(
            "SELECT value FROM " . TABLE_ORDERS_TOTAL
            . " WHERE orders_id = " . $order_id . " AND class = 'ot_shipping' LIMIT 1"
        );

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
            'shippingAmount' => $ship_row->EOF ? 0.0 : (float)$ship_row->fields['value'],
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
                'state'      => $result->fields['billing_state'],
                'country'    => $result->fields['billing_country'],
            ],
            'shippingAddress' => [
                'name'       => $result->fields['delivery_name'],
                'company'    => $result->fields['delivery_company'],
                'addr1'      => $result->fields['delivery_street_address'],
                'addr2'      => $result->fields['delivery_suburb'],
                'city'       => $result->fields['delivery_city'],
                'postalCode' => $result->fields['delivery_postcode'],
                'state'      => $result->fields['delivery_state'],
                'country'    => $result->fields['delivery_country'],
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

            $items[] = [
                'productID' => (int)$prod_result->fields['products_id'],
                'sku'       => $prod_result->fields['products_model'],
                'name'      => $prod_result->fields['products_name'] . $attr_str,
                'image'     => HTTP_SERVER . DIR_WS_CATALOG . DIR_WS_IMAGES . $prod_result->fields['products_image'],
                'weight'    => (float)$prod_result->fields['products_weight'],
                'unitPrice' => round((float)$prod_result->fields['final_price'], 2),
                'quantity'  => (int)$prod_result->fields['products_quantity'],
                'tax'       => $tax,
                'options'   => $options,
            ];
            $prod_result->MoveNext();
        }

        // Order totals — coupon, shipping, Taxamo as extra line items
        $totals_result = $db->Execute(
            "SELECT class, title, value FROM " . TABLE_ORDERS_TOTAL . " WHERE orders_id = " . $order_id
        );
        $ot_extra = [];
        $dest_country = $order['shippingAddress']['country'];
        while (!$totals_result->EOF) {
            $ot_vat = ($dest_country === 'United Kingdom') ? $vat_rate : 0.0;
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
                    $ot_extra['shipping'] = [
                        'sku'       => 'Shipping',
                        'name'      => $title,
                        'quantity'  => 1,
                        'unitPrice' => $raw / (1 + $ot_vat / 100),
                        'tax'       => $ot_vat,
                    ];
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
            }
            $totals_result->MoveNext();
        }

        foreach (['coupon', 'shipping', 'taxamo_assure'] as $key) {
            if (isset($ot_extra[$key])) {
                $items[] = $ot_extra[$key];
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
        $orders[]       = $order;
        $result->MoveNext();
    }

    api_ok(['orders' => $orders]);
}

// ─── POST updateOrderComments ─────────────────────────────────────────────────

function update_order_comments($db, array $data): void
{
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

    $status = isset($data['status']) ? (int)$data['status'] : 4;
    $result = fb_update_order_history($db, $order_id, $comment, $status, 1);
    if ($result > 0) {
        api_ok(['message' => 'Order ' . $order_id . ' updated']);
    } else {
        api_error('Failed to update order history', 500);
    }
}

// ─── POST updateProducts ──────────────────────────────────────────────────────

function update_products($db, array $data): void
{
    $products    = $data['products'] ?? [];
    $added       = [];
    $updated     = [];
    $not_updated = [];

    foreach ($products as $product) {
        $sku = trim((string)($product['products_model'] ?? ''));
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

        // Manufacturer
        $mfr_name = (string)($product['manufacturer'] ?? '');
        $mfr_row  = $db->Execute(
            "SELECT manufacturers_id FROM " . TABLE_MANUFACTURERS
            . " WHERE manufacturers_name = '" . zen_db_input($mfr_name) . "' LIMIT 1"
        );
        if ($mfr_row->EOF) {
            api_error('Bad manufacturer: ' . $mfr_name . ' (' . $sku . ')');
        }
        $mfr_id = (int)$mfr_row->fields['manufacturers_id'];

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

        // Image — sanitise directory to prevent path traversal
        $img_dir  = '';
        $img_file = (string)($product['productImageFileName'] ?? '');
        $img_path = $img_file;
        if (!empty($product['productImageDirectory']) && is_string($product['productImageDirectory'])) {
            $img_dir  = preg_replace('/[\/\\\\].*/', '', $product['productImageDirectory']);
            $img_path = $img_dir . '/' . $img_file;
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
        $tax_class_id = !empty($product['productTaxable']) ? FB_DEFAULT_TAX_CLASS_ID : 0;
        $prod_row = [
            'phreebooks_sku'       => $sku,
            'products_model'       => $sku,
            'products_type'        => $type_id,
            'manufacturers_id'     => $mfr_id,
            'master_categories_id' => $cat_id,
        ];
        if ($img_file !== '')
            $prod_row['products_image']          = $img_path;
        if (isset($product['quantity']))
            $prod_row['products_quantity']        = max(0.0, (float)$product['quantity']);
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
        if (isset($product['productZencartOemPartnumbers']) && is_string($product['productZencartOemPartnumbers']))
            $prod_row['additional_skus']          = $product['productZencartOemPartnumbers'];
        if (isset($product['productZencartCondition']))
            $prod_row['products_condition']       = $product['productZencartCondition'];
        if (isset($product['productZencartDimUnits']))
            $prod_row['products_dim_type']        = $product['productZencartDimUnits'];
        if (isset($product['productZencartLength']))
            $prod_row['products_length']          = (float)$product['productZencartLength'];
        if (isset($product['productZencartWidth']))
            $prod_row['products_width']           = (float)$product['productZencartWidth'];
        if (isset($product['productZencartHeight']))
            $prod_row['products_height']          = (float)$product['productZencartHeight'];
        if (isset($product['productZencartReadyToShip']))
            $prod_row['products_ready_to_ship']   = $product['productZencartReadyToShip'];
        if (isset($product['productHsCode']))
            $prod_row['hs_code']                  = $product['productHsCode'];
        if (isset($product['productCountryOrigin']))
            $prod_row['country_of_origin']        = $product['productCountryOrigin'];
        if ($tax_class_id)
            $prod_row['products_tax_class_id']    = $tax_class_id;

        if (!empty($product['priceDiscountType'])) {
            $prod_row['products_discount_type']      = (int)$product['priceDiscountType'];
            $prod_row['products_quantity_order_min'] = $product['price_levels'][1]['qty'] ?? 1;
            $prod_row['products_price']              = (float)($product['price_levels'][1]['amount'] ?? 0);
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
        if ($existing->EOF) {
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
    }

    api_ok(['added' => $added, 'updated' => $updated, 'notUpdated' => $not_updated]);
}

// ─── POST updateProductsQty ───────────────────────────────────────────────────

function update_products_qty($db, array $data): void
{
    $products    = $data['products'] ?? [];
    $updated     = [];
    $not_updated = [];

    foreach ($products as $product) {
        $sku = trim((string)($product['products_model'] ?? ''));
        if ($sku === '') {
            $not_updated[] = '(empty SKU)';
            continue;
        }

        $row = [];
        if (isset($product['quantity'])) {
            $row['products_quantity'] = max(0.0, (float)$product['quantity']);
        }
        if (isset($product['price'])) {
            $row['products_price'] = (float)$product['price'];
        }
        if (empty($row)) {
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
        zen_db_perform(TABLE_PRODUCTS, $row, 'update', "products_id = " . (int)$existing->fields['products_id']);
        $updated[] = $sku;
    }

    api_ok(['updated' => $updated, 'notUpdated' => $not_updated]);
}

// ─── Router ───────────────────────────────────────────────────────────────────

check_access($db);

$task = (string)($_REQUEST['task'] ?? '');

if ($task === 'version') {
    api_ok(['apiVersion' => FB_API_VERSION, 'file' => 'freebooks-zencart.php']);
} elseif ($task === 'orders') {
    $last_order = isset($_REQUEST['last_order']) ? (int)$_REQUEST['last_order'] : -1;
    get_orders($db, $last_order);
} elseif (in_array($task, ['updateOrderComments', 'updateProducts', 'updateProductsQty'], true)) {
    $json = (string)file_get_contents('php://input');
    $data = json_decode($json, true);
    if (!is_array($data)) {
        api_error('Request body must be valid JSON');
    }
    if ($task === 'updateOrderComments') {
        update_order_comments($db, $data);
    } elseif ($task === 'updateProducts') {
        update_products($db, $data);
    } else {
        update_products_qty($db, $data);
    }
} else {
    api_error('Unknown task: ' . $task, 404);
}

require(DIR_WS_INCLUDES . 'application_bottom.php');
