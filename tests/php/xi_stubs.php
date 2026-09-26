<?php
/**
 * Minimal stand-ins for the Nagios XI API used by the component, so its library can be
 * unit tested without an XI install. Behaviour mirrors XI 2026R2 where it matters
 * (encode_form_val, escape_sql_param).
 *
 * Tests control the fake database through $GLOBALS['cvs_test'].
 */

define("COMPONENT_NAME", "name");
define("COMPONENT_TYPE", "type");
define("COMPONENT_TYPE_USER", "user");
define("COMPONENT_TITLE", "title");
define("COMPONENT_VERSION", "version");
define("COMPONENT_AUTHOR", "author");
define("COMPONENT_DESCRIPTION", "description");
define("COMPONENT_DATE", "date");
define("COMPONENT_COPYRIGHT", "copyright");
define("COMPONENT_LICENSE", "license");
define("CALLBACK_MENUS_INITIALIZED", "menus_initialized");
define("CALLBACK_PAGE_HEAD", "page_head");
define("MENU_HOME", "home");
define("DB_NDOUTILS", "ndoutils");
define("OBJECTTYPE_HOST", 1);

$GLOBALS['db_tables'] = array(DB_NDOUTILS => array(
    'customvariables'      => 'nagios_customvariables',
    'customvariablestatus' => 'nagios_customvariablestatus',
    'objects'              => 'nagios_objects',
    'hosts'                => 'nagios_hosts',
    'hoststatus'           => 'nagios_hoststatus',
));

$GLOBALS['cvs_test'] = array(
    'registered' => array(),   // register_component / register_callback calls
    'menu'       => array(),   // add_menu_item calls
    'sql'        => array(),   // every query passed to exec_sql_query
    'rows'       => array(),   // rows the fake database returns
    'db_error'   => false,     // true: exec_sql_query throws like PHP 8 mysqli
    'authorized' => null,      // null = all objects allowed, else list of object_ids
);

// gettext provides _() when installed (as on XI); untranslated it returns the input
if (!function_exists("_")) { function _($s) { return $s; } }
function register_component($name, $args) { $GLOBALS['cvs_test']['registered'][] = array('component', $name, $args); }
function register_callback($cb, $fn) { $GLOBALS['cvs_test']['registered'][] = array('callback', $cb, $fn); }
function find_menu_item($menu, $id, $key) { return array('order' => 10); }
function add_menu_item($menu, $item) { $GLOBALS['cvs_test']['menu'][] = $item; }
function grab_array_var($a, $k, $d = null) { return isset($a[$k]) ? $a[$k] : $d; }
function grab_request_var($k, $d = "") { return isset($_REQUEST[$k]) ? $_REQUEST[$k] : $d; }
function get_base_url($full = true) { return $full ? "http://xi.example/nagiosxi/" : "/nagiosxi/"; }
function get_component_url_base($name = "", $full = true) { return get_base_url($full) . "includes/components/" . $name; }

// Same as XI's utils.inc.php
function encode_form_val($rawval) { return htmlentities($rawval, ENT_COMPAT, 'UTF-8'); }

// Same escaping as mysqli_real_escape_string
function escape_sql_param($in, $dbh, $quote = false)
{
    return strtr($in, array("\\" => "\\\\", "'" => "\\'", '"' => '\\"', "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"));
}

function is_authorized_for_object_id($userid, $oid)
{
    $allowed = $GLOBALS['cvs_test']['authorized'];
    return $allowed === null || in_array((int) $oid, $allowed, true);
}

/** Fake ADOdb recordset over an array of rows */
class CvsFakeRecordset
{
    public $EOF;
    public $fields;
    private $rows;
    private $i = 0;

    public function __construct($rows)
    {
        $this->rows = array_values($rows);
        $this->load();
    }

    public function MoveNext()
    {
        $this->i++;
        $this->load();
    }

    private function load()
    {
        $this->EOF = $this->i >= count($this->rows);
        $this->fields = $this->EOF ? false : $this->rows[$this->i];
    }
}

function exec_sql_query($dbh, $sql, $handle_error = true)
{
    $GLOBALS['cvs_test']['sql'][] = $sql;
    if ($GLOBALS['cvs_test']['db_error']) {
        throw new Exception("simulated mysqli_sql_exception");
    }
    return new CvsFakeRecordset($GLOBALS['cvs_test']['rows']);
}

/** Build a result row the way the component's SELECT returns it */
function cvs_row($oid, $host, $varname, $varvalue, $state = 0, $checked = 1)
{
    return array('object_id' => $oid, 'host_name' => $host, 'alias' => "$host alias", 'address' => '10.0.0.' . $oid,
                 'varname' => $varname, 'varvalue' => $varvalue, 'current_state' => $state, 'has_been_checked' => $checked);
}

function cvs_reset()
{
    $GLOBALS['cvs_test']['sql'] = array();
    $GLOBALS['cvs_test']['rows'] = array();
    $GLOBALS['cvs_test']['db_error'] = false;
    $GLOBALS['cvs_test']['authorized'] = null;
    $_REQUEST = array();
    $_SESSION = array('user_id' => 7);
}
