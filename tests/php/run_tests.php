<?php
/**
 * Unit tests for customvarsearch.inc.php (no Nagios XI required).
 *
 * Usage: php tests/php/run_tests.php
 *
 * The component includes '../componenthelper.inc.php', so the runner copies it into a
 * temporary components/ tree whose componenthelper.inc.php loads the XI stubs.
 * Exit status is non-zero if any test fails.
 */

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . "/cvs-unit-" . getmypid();
@mkdir("$tmp/components/customvarsearch", 0777, true);
copy("$root/customvarsearch/customvarsearch.inc.php", "$tmp/components/customvarsearch/customvarsearch.inc.php");
file_put_contents("$tmp/components/componenthelper.inc.php", "<?php require_once " . var_export(__DIR__ . "/xi_stubs.php", true) . ";");
register_shutdown_function(function () use ($tmp) {
    @unlink("$tmp/components/customvarsearch/customvarsearch.inc.php");
    @unlink("$tmp/components/componenthelper.inc.php");
    @rmdir("$tmp/components/customvarsearch");
    @rmdir("$tmp/components");
    @rmdir($tmp);
});

require "$tmp/components/customvarsearch/customvarsearch.inc.php";

// ---------------------------------------------------------------- tiny test harness
$tests = array();
$failures = 0;
function test($name, $fn) { $GLOBALS['tests'][$name] = $fn; }
function eq($expected, $actual, $msg = '')
{
    if ($expected !== $actual) {
        throw new Exception(($msg ? "$msg: " : '') . "expected " . var_export($expected, true) . ", got " . var_export($actual, true));
    }
}
function ok($cond, $msg) { if (!$cond) { throw new Exception($msg); } }
function contains($needle, $haystack, $msg = '') { ok(strpos($haystack, $needle) !== false, ($msg ? "$msg: " : '') . "'$needle' not found in: $haystack"); }
function not_contains($needle, $haystack, $msg = '') { ok(strpos($haystack, $needle) === false, ($msg ? "$msg: " : '') . "'$needle' unexpectedly found in: $haystack"); }
function last_sql() { $s = $GLOBALS['cvs_test']['sql']; return end($s); }


// ---------------------------------------------------------------- registration
test('registers component, menu and page-head callbacks', function () {
    $reg = $GLOBALS['cvs_test']['registered'];
    eq('component', $reg[0][0]);
    eq('customvarsearch', $reg[0][1]);
    eq(COMPONENT_TYPE_USER, $reg[0][2][COMPONENT_TYPE]);
    eq('James Seldon', $reg[0][2][COMPONENT_AUTHOR]);
    eq('MIT', $reg[0][2][COMPONENT_LICENSE]);
    eq(90000, $reg[0][2][COMPONENT_REQUIRES_VERSION], 'requires 2026R2');
    ok(in_array(array('callback', CALLBACK_MENUS_INITIALIZED, 'customvarsearch_component_addmenu'), $reg, true), 'menu callback');
    ok(in_array(array('callback', CALLBACK_PAGE_HEAD, 'customvarsearch_component_head'), $reg, true), 'page head callback');
});

test('on XI older than 2026R2 the component registers but adds no hooks', function () {
    $GLOBALS['cvs_test']['registered'] = array();
    $GLOBALS['cvs_test']['xi_release'] = 80000;
    customvarsearch_component_init();
    $GLOBALS['cvs_test']['xi_release'] = 90000;
    eq(array('component'), array_column($GLOBALS['cvs_test']['registered'], 0));
});

test('menu item goes after Host Status and links to the search page', function () {
    $GLOBALS['cvs_test']['menu'] = array();
    customvarsearch_component_addmenu();
    $item = $GLOBALS['cvs_test']['menu'][0];
    eq(10.5, $item['order']);
    eq('http://xi.example/nagiosxi/includes/components/customvarsearch/index.php', $item['opts']['href']);
    eq('Target', $item['opts']['neptune-icon']);
});

test('page head emits the hook script with an escaped endpoint attribute', function () {
    ob_start();
    customvarsearch_component_head(CALLBACK_PAGE_HEAD, array('child' => true));
    $html = ob_get_clean();
    contains('src="/nagiosxi/includes/components/customvarsearch/search-hook.js?v=', $html);
    contains('data-cvs-endpoint="/nagiosxi/includes/components/customvarsearch/index.php"', $html);
    contains('data-cvs-tab="tab-custom-customvariabletab"', $html);
    contains('data-cvs-tab-param="cvstab"', $html);
});

// ---------------------------------------------------------------- small helpers
test('escape_like escapes LIKE wildcards and backslash', function () {
    eq('100\\%', customvarsearch_escape_like('100%'));
    eq('a\\_b', customvarsearch_escape_like('a_b'));
    eq('c:\\\\temp', customvarsearch_escape_like('c:\\temp'));
});

test('pcre accepts valid regexes, including ones with slashes', function () {
    ok(customvarsearch_pcre('^SN-R[0-9]+') !== null, 'simple');
    ok(customvarsearch_pcre('a\\/b') !== null, 'escaped slash');
    ok(customvarsearch_pcre('a/b') !== null, 'bare slash');
    eq(1, preg_match(customvarsearch_pcre('dell'), 'DELL'), 'case-insensitive');
});

test('pcre rejects invalid regexes and the delimiter character', function () {
    eq(null, customvarsearch_pcre('[unclosed'));
    eq(null, customvarsearch_pcre('(a'));
    eq(null, customvarsearch_pcre("a\x01i"));
});

test('sensitive variable names are detected case-insensitively', function () {
    foreach (array('PASSWORD', 'SNMP_COMMUNITY', 'db_pass', 'API_KEY', 'APIKEY', 'SECRET', 'AUTH_TOKEN', 'PRIVATE_KEY', 'CREDENTIALS', 'PWD') as $n) {
        ok(customvarsearch_is_sensitive($n), "$n should be sensitive");
    }
    foreach (array('SERIAL', 'OWNER', 'LOCATION', 'VENDOR', 'ENVIRONMENT') as $n) {
        ok(!customvarsearch_is_sensitive($n), "$n should not be sensitive");
    }
});

test('host url asks for the Custom Variables tab and encodes the host name', function () {
    eq('/nagiosxi/includes/components/xicore/status.php?show=hostdetail&host=web+01%26x%3D1&cvstab=1',
       customvarsearch_host_url('web 01&x=1', false));
    contains('http://xi.example/', customvarsearch_host_url('h'));
});

test('request_str trims strings and rejects arrays', function () {
    cvs_reset();
    $_REQUEST = array('q' => '  dell ', 'arr' => array('x'));
    eq('dell', customvarsearch_request_str('q'));
    eq('', customvarsearch_request_str('arr'));
    eq('both', customvarsearch_request_str('missing', 'both'));
});

test('csv_cell neutralises formula prefixes only', function () {
    eq("'=HYPERLINK(\"x\")", customvarsearch_csv_cell('=HYPERLINK("x")'));
    eq("'+1", customvarsearch_csv_cell('+1'));
    eq("'-1", customvarsearch_csv_cell('-1'));
    eq("'@SUM(A1)", customvarsearch_csv_cell('@SUM(A1)'));
    eq("'\tx", customvarsearch_csv_cell("\tx"));
    eq('SN-7731', customvarsearch_csv_cell('SN-7731'));
    eq('', customvarsearch_csv_cell(''));
    eq('3', customvarsearch_csv_cell(3));
});

test('state_text maps NDO host states', function () {
    eq('UP', customvarsearch_state_text(0, 1));
    eq('DOWN', customvarsearch_state_text('1', 1));
    eq('UNREACHABLE', customvarsearch_state_text(2, 1));
    eq('PENDING', customvarsearch_state_text(0, 0));
    eq('UNKNOWN', customvarsearch_state_text(null, null));
    eq('UNKNOWN', customvarsearch_state_text(9, 1));
});

// ---------------------------------------------------------------- highlighting (XSS-sensitive)
test('highlight contains-match wraps the match and escapes everything', function () {
    eq('VAN-<mark>DC1</mark>-A3', customvarsearch_highlight('VAN-DC1-A3', 'dc1', 'contains'));
    eq('&lt;b&gt;<mark>x</mark>&lt;/b&gt;', customvarsearch_highlight('<b>x</b>', 'x', 'contains'));
    eq('&lt;script&gt;', customvarsearch_highlight('<script>', 'zzz', 'contains'), 'no match still escaped');
});

test('highlight never lets the search term inject markup', function () {
    eq('a<mark>&lt;img&gt;</mark>b', customvarsearch_highlight('a<img>b', '<img>', 'contains'));
    eq('&quot;x&quot;', customvarsearch_highlight('"x"', '', 'contains'));
});

test('highlight exact only marks a whole-value match', function () {
    eq('<mark>Dell</mark>', customvarsearch_highlight('Dell', 'dell', 'exact'));
    eq('Dell EMC', customvarsearch_highlight('Dell EMC', 'dell', 'exact'));
});

test('highlight regex marks every match and keeps all text', function () {
    eq('<mark>SN-R750</mark>-2210', customvarsearch_highlight('SN-R750-2210', '^SN-R[0-9]+', 'regex'));
    eq('<mark>a</mark>-<mark>a</mark>', customvarsearch_highlight('a-a', 'a', 'regex'));
    // Patterns that can match empty strings used to drop text
    eq('a<mark>b</mark>c', customvarsearch_highlight('abc', 'b*', 'regex'));
    eq('&lt;<mark>x</mark>&gt;', customvarsearch_highlight('<x>', 'x', 'regex'));
    eq('abc', customvarsearch_highlight('abc', '[bad', 'regex'), 'invalid regex falls back to plain text');
});

// ---------------------------------------------------------------- search (fake database)
test('search builds a case-insensitive LIKE and returns mapped rows', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['rows'] = array(cvs_row(5, 'db-prod-01', 'VENDOR', 'Dell'));
    $r = customvarsearch_search('Dell');
    eq(null, $r['error']);
    eq(1, count($r['results']));
    eq(array('host_name' => 'db-prod-01', 'alias' => 'db-prod-01 alias', 'address' => '10.0.0.5', 'varname' => 'VENDOR',
             'varvalue' => 'Dell', 'masked' => false, 'state' => 'UP'), $r['results'][0]);
    contains("LOWER(cv.varname) LIKE LOWER('%Dell%')", last_sql());
    contains("LOWER(COALESCE(cvs.varvalue, cv.varvalue)) LIKE LOWER('%Dell%')", last_sql());
    contains("o.objecttype_id = 1 AND o.is_active = 1", last_sql());
});

test('search escapes quotes and backslashes (SQL injection)', function () {
    cvs_reset();
    customvarsearch_search("x' OR '1'='1");
    contains("LIKE LOWER('%x\\' OR \\'1\\'=\\'1%')", last_sql());
    customvarsearch_search("a\\' OR 1=1 -- ", 'value', 'exact');
    contains("= LOWER('a\\\\\\' OR 1=1 -- ')", last_sql());
    customvarsearch_search("x' UNION SELECT 1 -- ", 'name', 'regex');
    contains("REGEXP 'x\\' UNION SELECT 1 -- '", last_sql());
});

test('field=name only searches names, field=value only values', function () {
    cvs_reset();
    customvarsearch_search('dell', 'name');
    contains('LOWER(cv.varname)', last_sql());
    not_contains('LOWER(COALESCE', last_sql());
    customvarsearch_search('dell', 'value');
    not_contains('LOWER(cv.varname)', last_sql());
    contains('LOWER(COALESCE', last_sql());
});

test('unknown field/match values fall back to defaults', function () {
    cvs_reset();
    customvarsearch_search('dell', 'bogus', 'bogus');
    contains('LOWER(cv.varname) LIKE', last_sql());
    contains('LOWER(COALESCE(cvs.varvalue, cv.varvalue)) LIKE', last_sql());
});

test('value matching excludes sensitive variables; name matching does not', function () {
    cvs_reset();
    customvarsearch_search('s3cret', 'both');
    contains("AND UPPER(cv.varname) NOT REGEXP '" . CUSTOMVARSEARCH_SENSITIVE_NAMES . "'", last_sql());
    customvarsearch_search('pass', 'name');
    not_contains('NOT REGEXP', last_sql());
});

test('sensitive values are masked in results', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['rows'] = array(cvs_row(5, 'fw', 'SNMP_COMMUNITY', 'private-community'), cvs_row(5, 'fw', 'OWNER', 'netops'));
    $r = customvarsearch_search('fw', 'both');
    eq(CUSTOMVARSEARCH_MASK, $r['results'][0]['varvalue']);
    eq(true, $r['results'][0]['masked']);
    eq('netops', $r['results'][1]['varvalue']);
});

test('results are filtered by object permissions, checked once per host', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['authorized'] = array(5);
    $GLOBALS['cvs_test']['rows'] = array(cvs_row(5, 'allowed', 'A', '1'), cvs_row(5, 'allowed', 'B', '2'), cvs_row(6, 'denied', 'A', '1'));
    $r = customvarsearch_search('1');
    eq(array('allowed', 'allowed'), array_column($r['results'], 'host_name'));
});

test('host filter is a case-insensitive, escaped LIKE on the host name', function () {
    cvs_reset();
    customvarsearch_search('', 'both', 'contains', 'prod_%');
    contains("LOWER(o.name1) LIKE LOWER('%prod\\\\_\\\\%%')", last_sql());
    not_contains('cv.varname) LIKE', last_sql(), 'empty term adds no variable condition');
});

test('invalid regex is rejected before touching the database', function () {
    cvs_reset();
    $r = customvarsearch_search('[unclosed', 'both', 'regex');
    eq('Invalid regular expression.', $r['error']);
    eq(0, count($GLOBALS['cvs_test']['sql']));
});

test('over-long terms and host filters are rejected before touching the database', function () {
    cvs_reset();
    $r = customvarsearch_search(str_repeat('a', CUSTOMVARSEARCH_MAX_TERM_LENGTH + 1));
    ok($r['error'] !== null, 'term');
    $r = customvarsearch_search('a', 'both', 'contains', str_repeat('h', CUSTOMVARSEARCH_MAX_TERM_LENGTH + 1));
    ok($r['error'] !== null, 'host filter');
    eq(0, count($GLOBALS['cvs_test']['sql']));
    $r = customvarsearch_search(str_repeat('a', CUSTOMVARSEARCH_MAX_TERM_LENGTH));
    eq(null, $r['error'], 'exactly at the limit is fine');
});

test('database exceptions become a friendly error', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['db_error'] = true;
    $r = customvarsearch_search('(?<', 'both', 'contains');
    eq('Search failed - database error.', $r['error']);
    eq(array(), $r['results']);
});

// ---------------------------------------------------------------- global search suggestions
test('suggest needs at least 2 characters', function () {
    cvs_reset();
    eq(array(), customvarsearch_suggest_items('d'));
    eq(0, count($GLOBALS['cvs_test']['sql']));
});

test('suggest returns up to 10 host items plus a See-all link', function () {
    cvs_reset();
    for ($i = 1; $i <= 12; $i++) {
        $GLOBALS['cvs_test']['rows'][] = cvs_row($i, "h$i", 'LOCATION', "VAN-DC1-$i");
    }
    $items = customvarsearch_suggest_items('van');
    eq(11, count($items));
    eq('/nagiosxi/includes/components/xicore/status.php?show=hostdetail&host=h1&cvstab=1', $items[0]['url']);
    eq('h1 _LOCATION VAN-DC1-1', $items[0]['value']);
    eq("h1 \xE2\x80\x94 _LOCATION = VAN-DC1-1", $items[0]['label']);
    eq('Custom Variables', $items[0]['category']);
    eq('/nagiosxi/includes/components/customvarsearch/index.php?q=van', $items[10]['url']);
    contains('See all 12', $items[10]['label']);
});

test('suggest never exposes sensitive values', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['rows'] = array(cvs_row(1, 'fw', 'SNMP_COMMUNITY', 'private-community'));
    $items = customvarsearch_suggest_items('community');
    not_contains('private-community', json_encode($items));
    contains(CUSTOMVARSEARCH_MASK, $items[0]['label']);
});

test('suggest JSON survives invalid UTF-8 in a value', function () {
    cvs_reset();
    $GLOBALS['cvs_test']['rows'] = array(cvs_row(1, 'h', 'NOTE', "caf\xE9"));
    $json = json_encode(customvarsearch_suggest_items('caf'), CUSTOMVARSEARCH_JSON_FLAGS);
    ok($json !== false, 'json_encode returned false');
    contains("caf\u{FFFD}", json_decode($json, true)[0]['label']);
});


// ---------------------------------------------------------------- run
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL  $name\n        " . $e->getMessage() . "\n";
    }
}
printf("\n%d tests, %d passed, %d failed\n", count($tests), count($tests) - $failures, $failures);
exit($failures ? 1 : 0);
