<?php
/**
 * Tests for freebooks-zencart.php.
 *
 *   php tests/run.php
 *
 * No database, no web server, no Zen Cart installation: tests/fakes.php
 * supplies the handful of constants and functions the file expects, plus a
 * FakeDb that answers canned rows and records every statement.
 *
 * The file is included with ZC_API_LIB_ONLY defined, which skips its bootstrap
 * and its router, so only the function definitions load.
 *
 * What this covers is the part where a wrong answer is expensive and silent:
 * how a country name becomes an ISO code, which language rows get read and
 * written, how a tax class is chosen, and the hook machinery that a store
 * relies on to add its own behaviour without editing this file. It does not
 * cover the HTTP surface or the SQL itself -- those need a real install.
 */

declare(strict_types=1);

require __DIR__ . '/fakes.php';

$db = new FakeDb();
$GLOBALS['db'] = $db;

require dirname(__DIR__) . '/freebooks-zencart.php';


// ===========================================================================
T::group('the file loads as a library');

T::ok('helpers are defined', function_exists('zc_country_code'));
// The router's first act is check_access($db), which queries. No statement at
// all on include is therefore proof it did not run -- a better check than
// headers_sent(), which is false under CLI regardless.
T::is('the router did not run: no statement issued', $db->queries, []);
T::ok('and no task was resolved', !isset($GLOBALS['task']));


// ===========================================================================
T::group('zc_country_code -- names to ISO alpha-2');

$db->reset();
$db->when('/countries_name = \'France\'/', [['countries_iso_code_2' => 'FR']]);

T::is('a name in the table resolves', zc_country_code($db, 'France'), 'FR');
T::is('and is cached, not re-queried', count($db->matching('/FROM countries/')), 1);

$db->reset();
T::is('the cache survives a reset of the db log', zc_country_code($db, 'France'), 'FR');
T::is('with no further query', $db->queries, []);

T::is('an empty name is not looked up', zc_country_code($db, ''), '');
T::is('nor is whitespace', zc_country_code($db, "  \t "), '');
T::is('still no query issued', $db->queries, []);

// The alias table is the whole reason historical orders keep resolving after a
// country is renamed in the countries table. An order placed in 2019 stores
// the name as it was then, and that name is no longer in the table.
$db->reset();
foreach ([
    'Czech Republic'                             => 'CZ',
    'Turkey'                                     => 'TR',
    'Cape Verde'                                 => 'CV',
    'Swaziland'                                  => 'SZ',
    'Macedonia, The Former Yugoslav Republic of' => 'MK',
    'Slovakia (Slovak Republic)'                 => 'SK',
    'Bosnia and Herzegowina'                     => 'BA',
    'Guinea-bissau'                              => 'GW',
    'Virgin Islands (British)'                   => 'VG',
    'Vatican City State (Holy See)'              => 'VA',
] as $old_name => $iso) {
    T::is("alias: $old_name", zc_country_code($db, $old_name), $iso);
}

T::is('an unknown name yields no code rather than a guess', zc_country_code($db, 'Atlantis'), '');


// ===========================================================================
T::group('zc_order_country_code -- column if present, name if not');

$db->reset();
// No *_country_code column on the orders table, which is every stock Zen Cart.
$db->when('/SHOW COLUMNS/', []);
$db->when('/countries_name = \'Spain\'/', [['countries_iso_code_2' => 'ES']]);

T::is(
    'falls back to resolving the stored country name',
    zc_order_country_code($db, ['delivery_country' => 'Spain'], 'delivery'),
    'ES'
);
T::ok('and it did ask whether the column exists', $db->matching('/SHOW COLUMNS/') !== []);

$db->reset();
T::is('the column check is cached per prefix', zc_order_country_code($db, ['delivery_country' => 'Spain'], 'delivery'), 'ES');
T::is('so SHOW COLUMNS is not repeated', $db->matching('/SHOW COLUMNS/'), []);

$db2 = new FakeDb();
$db2->when('/SHOW COLUMNS/', [['Field' => 'billing_country_code']]);
T::is(
    'a store that HAS the column gets the column',
    zc_order_country_code($db2, ['billing_country' => 'France', 'billing_country_code' => 'FR'], 'billing'),
    'FR'
);
T::is('and the countries table is not consulted', $db2->matching('/FROM countries/'), []);

$db3 = new FakeDb();
$db3->when('/SHOW COLUMNS/', [['Field' => 'billing_country_code']]);
$db3->when('/countries_name = \'Italy\'/', [['countries_iso_code_2' => 'IT']]);
T::is(
    'an empty column value still falls back to the name',
    zc_order_country_code($db3, ['billing_country' => 'Italy', 'billing_country_code' => ''], 'billing'),
    'IT'
);

$db4 = new FakeDb();
$db4->when('/SHOW COLUMNS/', []);
T::is('a missing country field is not fatal', zc_order_country_code($db4, [], 'delivery'), '');


// ===========================================================================
T::group('zc_default_language_id -- which language names are read in');

// DEFAULT_LANGUAGE holds a code, not an id, so it has to be resolved.
$dbl = new FakeDb();
$dbl->when('/code = \'en\'/', [['languages_id' => '3']]);
define('DEFAULT_LANGUAGE', 'en');

T::is('resolves the configured code to its id', zc_default_language_id($dbl), 3);
T::is('and is cached', count($dbl->matching('/FROM languages/')), 1);

// The static cache means the fallbacks need their own process to be observed.
$boot = 'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
      . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';';

// A store whose configured code is not in the languages table at all.
[$out] = run_in_child(
    $boot
    . 'define("DEFAULT_LANGUAGE", "xx");'
    . '$d = new FakeDb();'
    . '$d->when("/ORDER BY languages_id/", [["languages_id" => "7"]]);'
    . 'echo zc_default_language_id($d);'
);
T::is('an unrecognised code falls back to the lowest installed language', $out, '7');

// And the case that motivated all this: no language row with id 1.
[$out] = run_in_child(
    $boot
    . '$d = new FakeDb();'
    . '$d->when("/ORDER BY languages_id/", [["languages_id" => "4"]]);'
    . 'echo zc_default_language_id($d);'
);
T::is('DEFAULT_LANGUAGE undefined also falls back, and never to a bare 1', $out, '4');

// Only if there is no language at all does it give up and guess 1 -- which
// cannot happen on a working store, but must not be a fatal if it does.
[$out] = run_in_child($boot . '$d = new FakeDb(); echo zc_default_language_id($d);');
T::is('an empty languages table does not fatal', $out, '1');

// The point of the whole exercise: the option lookup must read in the same
// language the inserts write, or it never finds what it just created.
[$out] = run_in_child(
    $boot
    . 'define("DEFAULT_LANGUAGE", "en");'
    . '$d = new FakeDb();'
    . '$d->when("/code = .en./", [["languages_id" => "5"]]);'
    . 'echo zc_default_language_id($d);'
);
T::is('a non-1 default is used verbatim', $out, '5');

// The option resolvers are closures inside update_products(), so they cannot
// be called directly without a large fixture. What can be checked cheaply is
// that the literal never comes back: a source-level assertion, the same kind
// that is worth having for any constant whose reappearance is silent.
$src = (string)file_get_contents(dirname(__DIR__) . '/freebooks-zencart.php');

T::is(
    'no hard-coded language_id = 1 anywhere in the file',
    preg_match('/language_id\s*=\s*1\b/', $src),
    0
);
T::is(
    'both option lookups read at the resolved default',
    preg_match_all('/language_id = " \. \$default_lang/', $src),
    2
);
T::ok(
    'and the order-status lookup uses the helper',
    strpos($src, 'language_id = " . zc_default_language_id($db)') !== false
);
// The inserts were already right -- one row per installed language. Pin that,
// because "fixing" them to a single language would look like a simplification.
T::is(
    'option inserts still loop over every installed language',
    preg_match_all('/SELECT languages_id FROM " \. TABLE_LANGUAGES\);/', $src),
    2
);


// ===========================================================================
T::group('zc_tax_class_id -- the ladder, not a constant');

$boot2 = 'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
       . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';';

// One class, titled whatever the store felt like -- 'VAT' here, which is what
// production actually calls it, not Zen Cart's stock 'Taxable Goods'.
$one_class = '$d = new FakeDb();'
           . '$d->when("/ORDER BY tax_class_id/", [["tax_class_id" => "1"]]);'
           . '$d->when("/tax_class_title = .VAT./", [["tax_class_id" => "1"]]);';

[$out] = run_in_child($boot2 . $one_class
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "SKU1");');
T::is('a taxable product gets the store\'s only class', $out, '1');

[$out] = run_in_child($boot2 . $one_class
    . 'echo zc_tax_class_id($d, ["productTaxable" => 0], "SKU1");');
T::is('a non-taxable product gets 0', $out, '0');

[$out] = run_in_child($boot2 . $one_class
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1, "taxClass" => "VAT"], "SKU1");');
T::is('a payload naming a class it has gets that class', $out, '1');

// The ladder must not silently substitute. Asking for a class the store does
// not have is an ERP data error, and taxing at some other rate instead is the
// kind of wrong that only surfaces in a VAT return.
[$out] = run_in_child($boot2 . $one_class
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1, "taxClass" => "Reduced"], "SKU9");');
$body = json_decode(trim($out), true);
T::is('an unknown named class is an error', $body['ok'] ?? null, false);
T::ok('naming the class and the SKU', strpos($body['error'] ?? '', 'Reduced') !== false
                                   && strpos($body['error'] ?? '', 'SKU9') !== false);

// A store with no tax classes cannot have a taxable product.
[$out] = run_in_child($boot2
    . '$d = new FakeDb();'
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "SKU2");');
$body = json_decode(trim($out), true);
T::is('taxable with no classes at all is an error, not a 0', $body['ok'] ?? null, false);
T::ok('and says so plainly', strpos($body['error'] ?? '', 'no tax classes') !== false);

// A store with several classes picks the lowest id, unless told otherwise.
$many = '$d = new FakeDb();'
      . '$d->when("/tax_class_title = .Reduced./", [["tax_class_id" => "7"]]);'
      . '$d->when("/ORDER BY tax_class_id/", [["tax_class_id" => "2"]]);';

[$out] = run_in_child($boot2 . $many
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "SKU3");');
T::is('several classes and no preference: lowest id', $out, '2');

[$out] = run_in_child($boot2 . $many
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1, "taxClass" => "Reduced"], "SKU3");');
T::is('and the payload can still pick another', $out, '7');

// ZC_DEFAULT_TAX_CLASS_TITLE sits between the two.
[$out] = run_in_child(
    'define("ZC_DEFAULT_TAX_CLASS_TITLE", "Reduced");' . $boot2 . $many
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "SKU4");');
T::is('a configured default title wins over the lowest id', $out, '7');

// ...but a configured title the store does not have must not be fatal: it is
// install configuration, not payload data, and the store still has a class.
[$out] = run_in_child(
    'define("ZC_DEFAULT_TAX_CLASS_TITLE", "Nonexistent");' . $boot2 . $many
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "SKU5");');
T::is('a configured title that does not resolve falls through', $out, '2');

// The filter is the per-product override.
[$out] = run_in_child($boot2 . $one_class
    . 'zc_add_filter("product.tax_class_id", static fn($id, $ctx) => $ctx["sku"] === "ZERO" ? 0 : $id);'
    . 'echo zc_tax_class_id($d, ["productTaxable" => 1], "ZERO");');
T::is('a filter can override the resolved class', $out, '0');

// The old constant must not come back.
$src = (string)file_get_contents(dirname(__DIR__) . '/freebooks-zencart.php');
T::notOk('ZC_DEFAULT_TAX_CLASS_ID is gone', strpos($src, 'ZC_DEFAULT_TAX_CLASS_ID'));
T::ok(
    'and the column is written on presence of the field, not truthiness of the id',
    strpos($src, "array_key_exists('productTaxable', \$product)") !== false
);


// ===========================================================================
T::group('hooks -- the seam stores are told to use');

T::notOk('an unregistered hook does not report itself', zc_has_hook('nothing.here'));
T::is('and filtering through it returns the value untouched', zc_filter('nothing.here', 'x'), 'x');

zc_add_filter('order.item', static fn($item, $ctx) => array_merge((array)$item, ['seen' => true]));
T::ok('a registered filter is reported', zc_has_hook('order.item'));
T::is(
    'and it can change the value',
    zc_filter('order.item', ['sku' => 'A'], []),
    ['sku' => 'A', 'seen' => true]
);

// Priority ordering matters: a store may register a coarse filter and then a
// finer one that depends on running after it.
zc_add_filter('chain', static fn($v) => $v . 'b', 20);
zc_add_filter('chain', static fn($v) => $v . 'a', 10);
zc_add_filter('chain', static fn($v) => $v . 'c', 30);
T::is('filters run in priority order, not registration order', zc_filter('chain', ''), 'abc');

// Actions and filters share one runner, so both callbacks are ($value, $ctx)
// -- an action just gets null as the value. Worth pinning: writing an action
// as fn($ctx) silently receives null and looks like the context is missing.
$fired = [];
zc_add_action('boot', static function ($value, $ctx) use (&$fired) {
    $fired[] = [$value, $ctx['task'] ?? null];
});
zc_do_action('boot', ['task' => 'orders']);
T::is('actions are called as ($value, $ctx), with a null value', $fired, [[null, 'orders']]);

// A filter that forgets to return is the commonest hook mistake, and blanking
// the value would corrupt what reaches the ERP.
zc_add_filter('forgetful', static function ($v) { /* no return */ });
T::is('a filter returning null keeps the previous value', zc_filter('forgetful', 'kept'), 'kept');

// ...and it says so, because a filter that silently drops its value is the
// hardest hook bug to find.
$logfile = tempnam(sys_get_temp_dir(), 'zclog');
[$out] = run_in_child(
    'ini_set("error_log", ' . var_export($logfile, true) . ');'
    . 'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';'
    . 'zc_add_filter("f", static function ($v) {});'
    . 'zc_filter("f", "kept");'
    . 'echo trim(file_get_contents(' . var_export($logfile, true) . '));'
);
T::ok('discarding a real value is logged', strpos($out, 'returned null') !== false);

// But a chain that starts from null uses null to mean "not mine". Several
// hooks each claim one order-total class, so every hook that declines returns
// null -- warning on those would log once per hook, per order-total row, per
// order fetched.
$logfile2 = tempnam(sys_get_temp_dir(), 'zclog');
[$out] = run_in_child(
    'ini_set("error_log", ' . var_export($logfile2, true) . ');'
    . 'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';'
    . 'zc_add_filter("order.total_line", static fn($i, $c) => $c["class"] === "mine" ? ["sku" => "X"] : $i);'
    . 'zc_add_filter("order.total_line", static fn($i, $c) => $i);'
    . '$r = zc_filter("order.total_line", null, ["class" => "not-mine"]);'
    . 'echo var_export($r, true) . "|" . trim(@file_get_contents(' . var_export($logfile2, true) . '));'
);
T::is('declining a null-started chain returns null and logs nothing', $out, 'NULL|');

[$out] = run_in_child(
    'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
    . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';'
    . 'zc_add_filter("order.total_line", static fn($i, $c) => $c["class"] === "mine" ? ["sku" => "X"] : $i);'
    . 'zc_add_filter("order.total_line", static fn($i, $c) => $i);'
    . 'echo json_encode(zc_filter("order.total_line", null, ["class" => "mine"]));'
);
T::is('and a later declining hook does not undo an earlier claim', $out, '{"sku":"X"}');

// A throwing hook aborts the request with a 500 rather than carrying on.
// That is the right call for an integration that moves money -- half-built
// order data reaching the ERP is worse than an error the caller can retry --
// but it does mean one bad hook file takes the endpoint down, so it is pinned
// here deliberately rather than left to be discovered.
[$out, $code] = run_in_child(
    'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
    . '$db = new FakeDb(); $GLOBALS["db"] = $db;'
    . 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';'
    . 'zc_add_filter("explodes", static function ($v) { throw new RuntimeException("bad hook"); });'
    . 'zc_filter("explodes", "original");'
    . 'echo "REACHED-THE-END";'
);
// Decode rather than string-match: the message is inside JSON, so its quotes
// are escaped and a literal needle silently never matches.
$body = json_decode(trim($out), true);

T::ok('the child returned valid JSON', is_array($body));
T::is('reported as a failure', $body['ok'] ?? null, false);
T::is(
    'naming the hook and the error',
    $body['error'] ?? '',
    'Hook "explodes" failed: bad hook'
);
T::notOk('and the request stopped rather than continuing', strpos($out, 'REACHED-THE-END'));


// ===========================================================================
T::group('bundle pieces -- absent, empty and populated are three different things');

$src = (string)file_get_contents(dirname(__DIR__) . '/freebooks-zencart.php');

T::ok(
    'presence of the field authorises the write, not its truthiness',
    strpos($src, "array_key_exists('bundleComponents', \$product)") !== false
);
T::notOk(
    'so an empty list is never mistaken for the field being absent',
    preg_match('/!empty\(\$product\[.bundleComponents.\]\)/', $src)
);
T::ok(
    'and the list is filterable before it is written',
    strpos($src, "zc_filter('product.bundle_pieces'") !== false
);

// update_products() ends in api_ok(), which exits, so it cannot be called in
// process. products.response is the last filter to run before that exit, which
// makes it the seam a test can read the finished SQL from.
//
// The product is made to already exist: zen_db_insert_id() is not faked, so the
// insert path is not observable here, and the update path is the one a sync
// actually takes for an established catalogue anyway.
$bundle_sql = static function ($bundle, bool $table_exists): array {
    $product = [
        'products_model'  => 'SVK101',
        'productType'     => 'Product - General',
        'productCategory' => 'All',
    ];
    if ($bundle !== 'ABSENT') {
        $product['bundleComponents'] = $bundle;
    }

    $canned = [
        '/FROM languages WHERE code/'        => [['languages_id' => 1]],
        '/FROM product_types/'               => [['type_id' => 1]],
        '/FROM categories_description/'      => [['categories_id' => 5]],
        '/SELECT products_id FROM products/' => [['products_id' => 42]],
    ];
    // Absent from $canned means SHOW TABLES answers empty, which is how a store
    // without the optional table looks.
    if ($table_exists) {
        $canned['/SHOW TABLES LIKE .*products_bundle_components/'] = [['t' => 'y']];
    }

    $php = 'require ' . var_export(__DIR__ . '/fakes.php', true) . ';'
        . '$db = new FakeDb(); $GLOBALS["db"] = $db;';
    foreach ($canned as $pattern => $rows) {
        $php .= '$db->when(' . var_export($pattern, true) . ', ' . var_export($rows, true) . ');';
    }
    $php .= 'require ' . var_export(dirname(__DIR__) . '/freebooks-zencart.php', true) . ';'
        . 'zc_add_filter("products.response", static function ($v) use ($db) {'
        . ' echo "SQL:" . json_encode($db->matching('
        . var_export('/^(DELETE|INSERT).*products_bundle_components/', true)
        . ')) . "\n"; return $v; });'
        . 'update_products($db, ' . var_export(['products' => [$product]], true) . ');';

    [$out] = run_in_child($php);

    foreach (explode("\n", $out) as $line) {
        if (strpos($line, 'SQL:') === 0) {
            return json_decode(substr($line, 4), true) ?? [];
        }
    }
    return ['no marker in child output: ' . $out];
};

$two_pieces = [
    ['sku' => 'SB000254', 'qty' => 1, 'length' => 18, 'width' => 18, 'height' => 14.5, 'weight' => 0.42],
    ['sku' => 'BP6ES',    'qty' => 4, 'length' => 0,  'width' => 0,  'height' => 0,    'weight' => 0.05],
];

T::is('an absent field touches nothing', $bundle_sql('ABSENT', true), []);

$cleared = $bundle_sql([], true);
T::is('an empty list issues exactly one statement', count($cleared), 1);
T::is(
    'and it is the DELETE, so the bundle falls back to its own dimensions',
    $cleared[0] ?? '',
    'DELETE FROM products_bundle_components WHERE products_id = 42'
);

$written = $bundle_sql($two_pieces, true);
T::is('a populated list replaces wholesale: one DELETE, one INSERT per piece', count($written), 3);
T::ok('pieces are numbered in the order sent', strpos($written[2] ?? '', ", 1, 'BP6ES'") !== false);
T::ok('a fractional dimension survives intact', strpos($written[1] ?? '', '14.5') !== false);
T::ok('and a zero dimension is stored as sent, not invented', strpos($written[2] ?? '', ", 0, 0, 0, 0.05)") !== false);

// The whole point of the SHOW TABLES probe: a store that never created the
// table must be unaffected by an ERP that sends the field regardless.
T::is('without the table, a full list still writes nothing', $bundle_sql($two_pieces, false), []);


exit(T::summary());
