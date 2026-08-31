<?php
/**
 * Minimal stand-ins for the Zen Cart runtime, so the API's helpers can be
 * exercised without a database, a web server or a Zen Cart installation.
 *
 * The point is not to reimplement Zen Cart. It is to make the decisions in
 * freebooks-zencart.php observable: which SQL it builds, what it does with the
 * rows it gets back, and what it does when it gets none. Anything the fakes
 * cannot honestly represent is left out rather than approximated, so a passing
 * test never means more than it should.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Zen Cart constants and functions the API file expects to already exist
// ---------------------------------------------------------------------------

define('ZC_API_LIB_ONLY', true);          // skip the bootstrap and the router
define('DIR_FS_CATALOG', __DIR__ . '/');
define('DIR_WS_INCLUDES', 'includes/');
define('DB_PREFIX', '');

foreach ([
    'TABLE_ORDERS'                    => 'orders',
    'TABLE_ORDERS_TOTAL'              => 'orders_total',
    'TABLE_ORDERS_STATUS'             => 'orders_status',
    'TABLE_ORDERS_STATUS_HISTORY'     => 'orders_status_history',
    'TABLE_ORDERS_PRODUCTS'           => 'orders_products',
    'TABLE_ORDERS_PRODUCTS_ATTRIBUTES'=> 'orders_products_attributes',
    'TABLE_COUNTRIES'                 => 'countries',
    'TABLE_LANGUAGES'                 => 'languages',
    'TABLE_PRODUCTS'                  => 'products',
    'TABLE_PRODUCTS_DESCRIPTION'      => 'products_description',
    'TABLE_PRODUCTS_OPTIONS'          => 'products_options',
    'TABLE_PRODUCTS_OPTIONS_VALUES'   => 'products_options_values',
    'TABLE_PRODUCTS_OPTIONS_VALUES_TO_PRODUCTS_OPTIONS' => 'products_options_values_to_products_options',
    'TABLE_PRODUCTS_ATTRIBUTES'       => 'products_attributes',
    'TABLE_PRODUCT_TYPES'             => 'product_types',
    'TABLE_CATEGORIES'                => 'categories',
    'TABLE_CATEGORIES_DESCRIPTION'    => 'categories_description',
    'TABLE_PRODUCTS_TO_CATEGORIES'    => 'products_to_categories',
    'TABLE_TAX_CLASS'                 => 'tax_class',
    'TABLE_TAX_RATES'                 => 'tax_rates',
    'TABLE_ADMIN'                     => 'admin',
    'TABLE_CONFIGURATION'             => 'configuration',
    'TABLE_PAYPAL'                    => 'paypal',
    'TABLE_SQUARE_PAYMENTS'           => 'square_payments',
    'TABLE_BRAINTREE'                 => 'braintree',
] as $const => $table) {
    if (!defined($const)) {
        define($const, DB_PREFIX . $table);
    }
}

if (!function_exists('zen_db_input')) {
    function zen_db_input($string) { return addslashes((string)$string); }
}
if (!function_exists('zen_not_null')) {
    function zen_not_null($value) { return $value !== null && $value !== '' && $value !== []; }
}
if (!function_exists('zen_db_perform')) {
    function zen_db_perform($table, $data, $action = 'insert', $parameters = '') {
        $GLOBALS['db']->perform($table, $data, $action, $parameters);
    }
}
if (!function_exists('zen_get_prid')) {
    function zen_get_prid($id) { return (int)$id; }
}

// ---------------------------------------------------------------------------
// A result set that behaves like Zen Cart's, including the parts that bite
// ---------------------------------------------------------------------------

/**
 * Zen Cart result objects expose ->fields for the current row, ->EOF, and
 * ->MoveNext(). Zen Cart 2.x also makes them iterable, and this file uses both
 * styles, so the fake supports both.
 *
 * ->fields on an exhausted set is an empty array rather than null, which is
 * what the real one does and is why `$r->fields['x'] ?? ''` is the safe idiom
 * against it.
 */
class FakeResult implements Iterator
{
    public $fields = [];
    public $EOF = true;

    private array $rows;
    private int $i = 0;

    public function __construct(array $rows = [])
    {
        $this->rows = array_values($rows);
        $this->rewind();
    }

    public function MoveNext(): void
    {
        $this->i++;
        $this->sync();
    }

    public function RecordCount(): int
    {
        return count($this->rows);
    }

    private function sync(): void
    {
        $this->EOF = !isset($this->rows[$this->i]);
        $this->fields = $this->EOF ? [] : $this->rows[$this->i];
    }

    // Iterator, so `foreach ($result as $row)` works as it does in ZC 2.x.
    #[\ReturnTypeWillChange] public function current() { return $this->rows[$this->i]; }
    #[\ReturnTypeWillChange] public function key() { return $this->i; }
    #[\ReturnTypeWillChange] public function next(): void { $this->MoveNext(); }
    #[\ReturnTypeWillChange] public function rewind(): void { $this->i = 0; $this->sync(); }
    #[\ReturnTypeWillChange] public function valid(): bool { return !$this->EOF; }
}

/**
 * A database that answers from a list of (pattern, rows) pairs and records
 * every statement it was given.
 *
 * Patterns are matched in the order registered, so a specific one registered
 * first wins over a general one. An unmatched SELECT returns an empty set --
 * deliberately, because "the row is not there" is the case most of these
 * helpers get wrong, and it should be the default a test falls into rather
 * than something it has to opt in to.
 */
class FakeDb
{
    public array $queries = [];
    public array $performed = [];
    public int $affected = 1;

    private array $canned = [];

    public function when(string $pattern, array $rows): self
    {
        $this->canned[] = [$pattern, $rows];
        return $this;
    }

    public function Execute($sql, $limit = null, $cache = false, $cacheTime = 0, $removeFromCache = false)
    {
        $this->queries[] = (string)$sql;

        foreach ($this->canned as [$pattern, $rows]) {
            if (preg_match($pattern, (string)$sql)) {
                return new FakeResult($rows);
            }
        }

        return new FakeResult([]);
    }

    public function perform($table, $data, $action = 'insert', $parameters = '')
    {
        $this->performed[] = compact('table', 'data', 'action', 'parameters');
        return new FakeResult([]);
    }

    public function affectedRows()
    {
        return $this->affected;
    }

    /** Every statement issued, joined -- handy for "was this ever asked?". */
    public function log(): string
    {
        return implode("\n", $this->queries);
    }

    /** Statements matching a pattern. */
    public function matching(string $pattern): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn($q) => (bool)preg_match($pattern, $q)
        ));
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->performed = [];
    }
}

// ---------------------------------------------------------------------------
// Running a fragment in a child process
// ---------------------------------------------------------------------------

/**
 * Run PHP in a separate process and return [stdout, exit code].
 *
 * Needed because api_ok() and api_error() both end in exit(), which is correct
 * for a JSON endpoint and impossible to observe in-process. Anything that ends
 * the request has to be tested from outside it.
 *
 * The fragment is written to a file rather than passed to php -r, because -r
 * mangles multi-line source on some hosts' CLI wrappers.
 */
function run_in_child(string $php): array
{
    $file = tempnam(sys_get_temp_dir(), 'zcapi') . '.php';
    file_put_contents($file, "<?php\n" . $php);

    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    @unlink($file);

    return [implode("\n", $out), $code];
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

final class T
{
    public static int $run = 0;
    public static int $failed = 0;
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n-- $name --\n";
    }

    public static function is(string $what, $got, $want): void
    {
        self::$run++;

        if ($got === $want) {
            echo "ok    $what\n";
            return;
        }

        self::$failed++;
        echo "FAIL  $what\n";
        echo '        got:  ' . self::show($got) . "\n";
        echo '        want: ' . self::show($want) . "\n";
    }

    public static function ok(string $what, $got): void
    {
        self::is($what, (bool)$got, true);
    }

    public static function notOk(string $what, $got): void
    {
        self::is($what, (bool)$got, false);
    }

    private static function show($v): string
    {
        return is_string($v) ? "'" . $v . "'" : json_encode($v);
    }

    public static function summary(): int
    {
        echo "\n";
        echo self::$failed === 0
            ? 'PASS  ' . self::$run . " checks\n"
            : 'FAIL  ' . self::$failed . ' of ' . self::$run . " checks\n";

        return self::$failed === 0 ? 0 : 1;
    }
}
