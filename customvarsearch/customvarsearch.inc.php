<?php
/**
 * Custom Variable Search - Nagios XI component
 *
 * Finds hosts by the names and values of their custom variables (_SERIAL, _OWNER,
 * _LOCATION, ...), which XI's built-in search does not cover. Results are available from:
 *
 *   - a search page (Home > Details > Custom Variable Search), with CSV/JSON export
 *   - XI's global search box (Neptune "Search Nagios XI"/Ctrl+K and the classic navbar
 *     search), as a "Custom Variables" result group - see search-hook.js
 *
 * XI loads this file for every request (component registration, callbacks), and
 * index.php includes it for the search logic. It contains no page output of its own.
 *
 * Data source: the NDO database (nagios_customvariables / nagios_customvariablestatus),
 * i.e. the variables Nagios Core is actually running with, including those inherited
 * from host templates.
 *
 * Security model:
 *   - Every entry point requires an authenticated XI session (index.php).
 *   - Results are filtered with XI's own object permissions (is_authorized_for_object_id),
 *     so users only see hosts they could already see elsewhere in XI.
 *   - Variables whose names look like secrets (CUSTOMVARSEARCH_SENSITIVE_NAMES) are
 *     masked in every output and excluded from value matching.
 *   - All SQL input goes through escape_sql_param(); all HTML output through
 *     encode_form_val(). No core XI files are modified.
 *
 * @package   customvarsearch
 * @version   1.2.0
 * @author    James Seldon
 * @copyright 2026 James Seldon
 * @license   MIT (see LICENSE)
 * @requires  Nagios XI with NDO (tested on XI 2026R2, PHP 8.3, MariaDB)
 */

include_once(dirname(__FILE__) . '/../componenthelper.inc.php');

/** Longest search term / host filter accepted, in bytes (bounds regex cost on the database). */
define("CUSTOMVARSEARCH_MAX_TERM_LENGTH", 255);

/**
 * Variable names that are treated as secrets, as a case-insensitive regex alternation
 * matched anywhere in the name (without the leading underscore). Values of matching
 * variables are replaced by CUSTOMVARSEARCH_MASK in all output and are never matched by
 * value searches - otherwise a secret could be recovered one regex at a time.
 * The name itself stays searchable. Edit this list to suit your site's naming.
 */
define("CUSTOMVARSEARCH_SENSITIVE_NAMES", "PASS|PWD|SECRET|TOKEN|APIKEY|API_KEY|COMMUNITY|PRIVATE|CREDENTIAL");

/** Replacement shown for sensitive values. */
define("CUSTOMVARSEARCH_MASK", "********");

/** json_encode() flags: invalid UTF-8 in a custom variable must not make encoding fail. */
define("CUSTOMVARSEARCH_JSON_FLAGS", JSON_INVALID_UTF8_SUBSTITUTE);

/**
 * Panel id of the "Custom Variables" tab on XI's host detail page (added by XI's bundled
 * Custom Variable Tab component). Links from this component add CUSTOMVARSEARCH_TAB_PARAM=1
 * to the host detail URL and search-hook.js opens the tab; a #hash cannot be used because
 * Neptune drops it when it loads the link into its content frame.
 */
define("CUSTOMVARSEARCH_HOST_TAB", "tab-custom-customvariabletab");

/** Query parameter that asks search-hook.js to open CUSTOMVARSEARCH_HOST_TAB. */
define("CUSTOMVARSEARCH_TAB_PARAM", "cvstab");

/** Maximum number of host results offered in the global search box. */
define("CUSTOMVARSEARCH_SUGGEST_MAX", 10);

/** Shortest term the global search box will search for. */
define("CUSTOMVARSEARCH_SUGGEST_MIN_LENGTH", 2);

$customvarsearch_component_name = "customvarsearch";
customvarsearch_component_init();


// =====================================================================================
// Component registration and XI callbacks
// =====================================================================================

/**
 * Register the component with XI and hook the menu and page-head callbacks.
 *
 * Called once per request when XI loads its components.
 *
 * @return void
 */
function customvarsearch_component_init()
{
    global $customvarsearch_component_name;

    $args = array(
        COMPONENT_NAME        => $customvarsearch_component_name,
        COMPONENT_TYPE        => COMPONENT_TYPE_USER,
        COMPONENT_AUTHOR      => "James Seldon",
        COMPONENT_COPYRIGHT   => "Copyright (c) 2026 James Seldon",
        COMPONENT_LICENSE     => "MIT",
        COMPONENT_DESCRIPTION => _("Search hosts by the names and values of their custom variables, from a dedicated page or XI's global search box."),
        COMPONENT_TITLE       => _("Custom Variable Search"),
        COMPONENT_VERSION     => "1.2.0",
        COMPONENT_DATE        => "09/26/2026",
    );

    register_component($customvarsearch_component_name, $args);
    register_callback(CALLBACK_MENUS_INITIALIZED, 'customvarsearch_component_addmenu');
    register_callback(CALLBACK_PAGE_HEAD, 'customvarsearch_component_head');
}


/**
 * CALLBACK_PAGE_HEAD handler: include search-hook.js in the <head> of XI pages.
 *
 * The script hooks XI's global search box (Neptune Ctrl+K and the classic navbar) and
 * opens the Custom Variables tab on host detail pages linked from this component.
 * The Neptune shell itself doesn't run this callback, so the script is also loaded by
 * the child pages in XI's content frame and re-injects itself into the top window,
 * where the search box lives. Its settings are passed as data-* attributes.
 *
 * @param string $cbtype Callback type (CALLBACK_PAGE_HEAD)
 * @param array  $args   Callback arguments; 'child' is true for content-frame pages
 * @return void          Echoes a <script> tag
 */
function customvarsearch_component_head($cbtype, $args)
{
    global $customvarsearch_component_name;

    $base = get_component_url_base($customvarsearch_component_name, false);
    echo '<script type="text/javascript" src="' . encode_form_val($base . '/search-hook.js?v=1.2.0') . '"'
        . ' data-cvs-endpoint="' . encode_form_val($base . '/index.php') . '"'
        . ' data-cvs-tab="' . encode_form_val(CUSTOMVARSEARCH_HOST_TAB) . '"'
        . ' data-cvs-tab-param="' . encode_form_val(CUSTOMVARSEARCH_TAB_PARAM) . '"></script>' . "\n";
}


/**
 * CALLBACK_MENUS_INITIALIZED handler: add "Custom Variable Search" to the Home menu,
 * directly after "Host Status" in the Details section.
 *
 * "icon" is a Font Awesome class for the classic themes; "neptune-icon" must be one of
 * the icon names the Neptune UI bundles (it has no magnifying glass, so "Target").
 *
 * @param mixed $arg Unused callback argument
 * @return void
 */
function customvarsearch_component_addmenu($arg = null)
{
    global $customvarsearch_component_name;

    $mi = find_menu_item(MENU_HOME, "menu-home-hoststatus", "id");
    $order = ($mi == null) ? 100 : grab_array_var($mi, "order", 100) + 0.5;

    add_menu_item(MENU_HOME, array(
        "type"  => "link",
        "title" => _("Custom Variable Search"),
        "id"    => "menu-home-customvarsearch",
        "order" => $order,
        "opts"  => array(
            "href" => get_component_url_base($customvarsearch_component_name) . "/index.php",
            "icon" => "fa-search",
            "neptune-icon" => "Target",
        ),
    ));
}


// =====================================================================================
// Search
// =====================================================================================

/**
 * Search active hosts' custom variables in the NDO database.
 *
 * Matching is case-insensitive in all modes. Values of sensitive variables (see
 * CUSTOMVARSEARCH_SENSITIVE_NAMES) never match a value search and are masked in the
 * results. Only hosts the current session user is authorized to see are returned.
 * Invalid input (unknown $field/$match) falls back to the defaults; over-long input and
 * invalid regular expressions are rejected before any query runs.
 *
 * @param string $term       Text to search for; a regular expression when $match is
 *                           'regex'. Empty matches every variable (useful with $hostfilter).
 * @param string $field      Where to look: 'name', 'value' or 'both'
 * @param string $match      How to match: 'contains', 'exact' or 'regex'
 * @param string $hostfilter Optional case-insensitive substring the host name must contain
 * @return array {
 *     @type array       $results One entry per matching variable, ordered by host then
 *                                variable name: host_name, alias, address, varname
 *                                (without the leading underscore), varvalue, masked (bool),
 *                                state ('UP'|'DOWN'|'UNREACHABLE'|'PENDING'|'UNKNOWN')
 *     @type string|null $error   User-facing error message, or null on success
 * }
 */
function customvarsearch_search($term, $field = 'both', $match = 'contains', $hostfilter = '')
{
    global $db_tables;
    $out = array('results' => array(), 'error' => null);

    if (!in_array($field, array('value', 'name', 'both'), true)) {
        $field = 'both';
    }
    if (!in_array($match, array('contains', 'exact', 'regex'), true)) {
        $match = 'contains';
    }

    if (strlen($term) > CUSTOMVARSEARCH_MAX_TERM_LENGTH || strlen($hostfilter) > CUSTOMVARSEARCH_MAX_TERM_LENGTH) {
        $out['error'] = sprintf(_("Search text is limited to %d characters."), CUSTOMVARSEARCH_MAX_TERM_LENGTH);
        return $out;
    }

    // Reject bad patterns before they reach the database
    if ($match == 'regex' && customvarsearch_pcre($term) === null) {
        $out['error'] = _("Invalid regular expression.");
        return $out;
    }

    // The runtime value (customvariablestatus) wins over the config value when present
    $value_col = "COALESCE(cvs.varvalue, cv.varvalue)";
    $name_col = "cv.varname";
    $not_sensitive = "UPPER(cv.varname) NOT REGEXP '" . CUSTOMVARSEARCH_SENSITIVE_NAMES . "'";

    $where = array();
    if ($term !== '') {
        $conds = array();
        if ($field == 'name' || $field == 'both') {
            $conds[] = customvarsearch_condition($name_col, $term, $match);
        }
        if ($field == 'value' || $field == 'both') {
            $conds[] = "(" . customvarsearch_condition($value_col, $term, $match) . " AND " . $not_sensitive . ")";
        }
        $where[] = "(" . implode(" OR ", $conds) . ")";
    }
    if ($hostfilter !== '') {
        $where[] = customvarsearch_condition("o.name1", $hostfilter, 'contains');
    }

    $sql = "SELECT DISTINCT o.object_id, o.name1 AS host_name, h.alias, h.address,
                   cv.varname, " . $value_col . " AS varvalue,
                   hs.current_state, hs.has_been_checked
            FROM " . $db_tables[DB_NDOUTILS]['customvariables'] . " cv
            JOIN " . $db_tables[DB_NDOUTILS]['objects'] . " o
                 ON o.object_id = cv.object_id AND o.objecttype_id = " . OBJECTTYPE_HOST . " AND o.is_active = 1
            LEFT JOIN " . $db_tables[DB_NDOUTILS]['customvariablestatus'] . " cvs
                 ON cvs.object_id = cv.object_id AND cvs.varname = cv.varname
            LEFT JOIN " . $db_tables[DB_NDOUTILS]['hosts'] . " h
                 ON h.host_object_id = o.object_id
            LEFT JOIN " . $db_tables[DB_NDOUTILS]['hoststatus'] . " hs
                 ON hs.host_object_id = o.object_id"
        . (count($where) ? " WHERE " . implode(" AND ", $where) : "")
        . " ORDER BY o.name1, cv.varname";

    // PHP 8 mysqli throws on SQL errors rather than returning false
    try {
        $rs = exec_sql_query(DB_NDOUTILS, $sql, false);
    } catch (Exception $e) {
        $rs = false;
    }
    if (!$rs) {
        $out['error'] = ($match == 'regex') ? _("Search failed - the database could not run this regular expression.") : _("Search failed - database error.");
        return $out;
    }

    $userid = $_SESSION["user_id"];
    $authorized = array();
    while (!$rs->EOF) {
        $row = $rs->fields;
        // Respect XI object permissions for non-admin users (checked once per host)
        $oid = $row['object_id'];
        if (!isset($authorized[$oid])) {
            $authorized[$oid] = is_authorized_for_object_id($userid, $oid);
        }
        if ($authorized[$oid]) {
            $sensitive = customvarsearch_is_sensitive($row['varname']);
            $out['results'][] = array(
                'host_name' => $row['host_name'],
                'alias'     => $row['alias'],
                'address'   => $row['address'],
                'varname'   => $row['varname'],
                'varvalue'  => $sensitive ? CUSTOMVARSEARCH_MASK : $row['varvalue'],
                'masked'    => $sensitive,
                'state'     => customvarsearch_state_text($row['current_state'], $row['has_been_checked']),
            );
        }
        $rs->MoveNext();
    }

    return $out;
}


/**
 * Build the SQL condition that matches a column against a search term.
 *
 * Both sides go through LOWER() so case-folding is done by the database, which handles
 * non-ASCII text (PHP's strtolower() only folds ASCII). 'contains' escapes the LIKE
 * wildcards % and _ so they match literally. 'regex' relies on the NDO tables'
 * case-insensitive (_ci) collation, XI's default.
 *
 * @param string $col   SQL column expression (trusted - never user input)
 * @param string $term  User-supplied search text; escaped here
 * @param string $match 'contains', 'exact' or 'regex'
 * @return string       SQL condition
 */
function customvarsearch_condition($col, $term, $match)
{
    switch ($match) {
        case 'exact':
            return "LOWER(" . $col . ") = LOWER('" . escape_sql_param($term, DB_NDOUTILS) . "')";
        case 'regex':
            return $col . " REGEXP '" . escape_sql_param($term, DB_NDOUTILS) . "'";
        default:
            return "LOWER(" . $col . ") LIKE LOWER('%" . escape_sql_param(customvarsearch_escape_like($term), DB_NDOUTILS) . "%')";
    }
}


/**
 * Escape the LIKE wildcards (% and _) and the escape character itself, so the text
 * matches literally. The result must still go through escape_sql_param().
 *
 * @param string $s Raw text
 * @return string   Text safe to embed in a LIKE pattern
 */
function customvarsearch_escape_like($s)
{
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
}


/**
 * Turn a user-supplied regular expression into a case-insensitive PCRE pattern.
 *
 * Used to validate regex searches before they reach the database, and for highlighting.
 * \x01 is the delimiter, so users never need to escape '/' and can't end the pattern
 * early to inject modifiers; terms containing \x01 are rejected.
 *
 * Note: the database's regex dialect (MariaDB PCRE / MySQL ICU) can differ slightly from
 * PHP's; a pattern PHP accepts but the database rejects produces a search error.
 *
 * @param string $term User-supplied regular expression, without delimiters
 * @return string|null Complete PCRE pattern, or null if the expression is invalid
 */
function customvarsearch_pcre($term)
{
    if (strpos($term, "\x01") !== false) {
        return null;
    }
    $pattern = "\x01" . $term . "\x01i";
    return (@preg_match($pattern, '') === false) ? null : $pattern;
}


/**
 * Is this custom variable's value a secret that must not be shown or searched?
 *
 * @param string $varname Variable name, with or without the leading underscore
 * @return bool           True if the name matches CUSTOMVARSEARCH_SENSITIVE_NAMES
 */
function customvarsearch_is_sensitive($varname)
{
    return preg_match('/' . CUSTOMVARSEARCH_SENSITIVE_NAMES . '/i', $varname) === 1;
}


// =====================================================================================
// Output helpers (used by index.php)
// =====================================================================================

/**
 * URL of a host's detail page that opens on its Custom Variables tab.
 *
 * @param string $host_name Host name (URL-encoded here)
 * @param bool   $full      true for an absolute URL, false for a path starting /nagiosxi/
 * @return string           URL (not HTML-escaped)
 */
function customvarsearch_host_url($host_name, $full = true)
{
    return get_base_url($full) . "includes/components/xicore/status.php?show=hostdetail&host=" . urlencode($host_name)
        . "&" . CUSTOMVARSEARCH_TAB_PARAM . "=1";
}


/**
 * Read a request variable as a trimmed string.
 *
 * PHP turns ?q[]=x into an array; anything that isn't a string is treated as missing,
 * so crafted requests can't reach string functions with arrays.
 *
 * @param string $name    Request variable name
 * @param string $default Value when missing or not a string
 * @return string
 */
function customvarsearch_request_str($name, $default = "")
{
    $v = grab_request_var($name, $default);
    return is_string($v) ? trim($v) : $default;
}


/**
 * Results for XI's global search box, in the same shape as core suggest.php results.
 *
 * Returns up to CUSTOMVARSEARCH_SUGGEST_MAX matching variables (contains-match on name or
 * value) plus a final "See all N matches" link to the search page. Each item links to
 * the host's Custom Variables tab. Terms shorter than CUSTOMVARSEARCH_SUGGEST_MIN_LENGTH
 * return nothing (the search box queries on every keystroke).
 *
 * @param string $term Text typed into the search box
 * @return array List of items with keys:
 *               url      - path loaded into XI's content frame
 *               value    - text Neptune's command palette filters on (must contain the term)
 *               label    - text shown to the user (rendered as text, not HTML, by XI)
 *               category - result group heading ("Custom Variables")
 */
function customvarsearch_suggest_items($term)
{
    $items = array();

    if (strlen($term) < CUSTOMVARSEARCH_SUGGEST_MIN_LENGTH) {
        return $items;
    }

    $search = customvarsearch_search($term);
    foreach (array_slice($search['results'], 0, CUSTOMVARSEARCH_SUGGEST_MAX) as $r) {
        $items[] = array(
            'url'      => customvarsearch_host_url($r['host_name'], false),
            'value'    => $r['host_name'] . " _" . $r['varname'] . " " . $r['varvalue'],
            'label'    => $r['host_name'] . " \xE2\x80\x94 _" . $r['varname'] . " = " . $r['varvalue'],
            'category' => _("Custom Variables"),
        );
    }
    if (count($search['results']) > 0) {
        $items[] = array(
            'url'      => get_component_url_base("customvarsearch", false) . "/index.php?q=" . urlencode($term),
            'value'    => $term . " custom variable search",
            'label'    => sprintf(_("See all %d custom variable matches for \"%s\""), count($search['results']), $term),
            'category' => _("Custom Variables"),
        );
    }

    return $items;
}


/**
 * Make a value safe for a CSV cell opened in a spreadsheet (CSV/formula injection).
 *
 * Values starting with =, +, -, @, tab or carriage return are prefixed with a single
 * quote so Excel/LibreOffice show them as text instead of evaluating them.
 *
 * @param mixed $v Cell value
 * @return string
 */
function customvarsearch_csv_cell($v)
{
    $v = (string) $v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}


/**
 * HTML-escape a value and wrap the parts that matched the search in <mark>.
 *
 * Every piece of text is escaped with encode_form_val(); only the <mark> tags are
 * literal HTML. Falls back to the plain escaped value when nothing matches, the regex
 * is invalid, or PCRE fails (e.g. backtrack limit).
 *
 * @param string $text  Raw value from the database
 * @param string $term  Search text
 * @param string $match 'contains', 'exact' or 'regex'
 * @return string       Safe HTML
 */
function customvarsearch_highlight($text, $term, $match)
{
    $safe = encode_form_val($text);
    if ($term === '') {
        return $safe;
    }
    if ($match == 'regex') {
        $pattern = customvarsearch_pcre($term);
        if ($pattern === null) {
            return $safe;
        }
        // Walk the match offsets over the raw text, escaping every piece
        if (!preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return $safe;
        }
        $html = '';
        $pos = 0;
        foreach ($m[0] as $hit) {
            list($str, $off) = $hit;
            if ($str === '') {
                continue;
            }
            $html .= encode_form_val(substr($text, $pos, $off - $pos)) . '<mark>' . encode_form_val($str) . '</mark>';
            $pos = $off + strlen($str);
        }
        return $html . encode_form_val(substr($text, $pos));
    }
    $pos = stripos($text, $term);
    if ($pos === false || ($match == 'exact' && strcasecmp($text, $term) != 0)) {
        return $safe;
    }
    return encode_form_val(substr($text, 0, $pos))
        . '<mark>' . encode_form_val(substr($text, $pos, strlen($term))) . '</mark>'
        . encode_form_val(substr($text, $pos + strlen($term)));
}


/**
 * Translate an NDO host state into a label.
 *
 * @param int|string|null $state   nagios_hoststatus.current_state (0 up, 1 down, 2 unreachable)
 * @param int|string|null $checked nagios_hoststatus.has_been_checked
 * @return string 'UP', 'DOWN', 'UNREACHABLE', 'PENDING' (never checked) or 'UNKNOWN'
 */
function customvarsearch_state_text($state, $checked)
{
    if ($state === null) {
        return 'UNKNOWN';
    }
    if (!$checked) {
        return 'PENDING';
    }
    switch (intval($state)) {
        case 0: return 'UP';
        case 1: return 'DOWN';
        case 2: return 'UNREACHABLE';
    }
    return 'UNKNOWN';
}
