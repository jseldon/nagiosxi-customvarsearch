<?php
//
// Custom Variable Search Component
// Search host custom variables (e.g. _SERIAL, _OWNER, _RACK) by name and/or value.
//

include_once(dirname(__FILE__) . '/../componenthelper.inc.php');

$customvarsearch_component_name = "customvarsearch";
customvarsearch_component_init();


function customvarsearch_component_init()
{
    global $customvarsearch_component_name;

    $args = array(
        COMPONENT_NAME        => $customvarsearch_component_name,
        COMPONENT_AUTHOR      => "jseldon",
        COMPONENT_DESCRIPTION => _("Search hosts by the names and values of their custom variables."),
        COMPONENT_TITLE       => _("Custom Variable Search"),
        COMPONENT_VERSION     => "1.0.0",
        COMPONENT_DATE        => "09/26/2026",
    );

    register_component($customvarsearch_component_name, $args);
    register_callback(CALLBACK_MENUS_INITIALIZED, 'customvarsearch_component_addmenu');
}


function customvarsearch_component_addmenu($arg = null)
{
    global $customvarsearch_component_name;

    // Place directly after "Host Status" in the Home > Details section
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


/**
 * Search host custom variables in the NDO database.
 *
 * @param string $term       Text (or regex) to search for
 * @param string $field      'value', 'name' or 'both'
 * @param string $match      'contains', 'exact' or 'regex'
 * @param string $hostfilter Optional substring to restrict host names
 * @return array             ['results' => [...], 'error' => string|null]
 */
function customvarsearch_search($term, $field = 'both', $match = 'contains', $hostfilter = '')
{
    global $db_tables;
    $out = array('results' => array(), 'error' => null);

    if (!in_array($field, array('value', 'name', 'both'))) {
        $field = 'both';
    }
    if (!in_array($match, array('contains', 'exact', 'regex'))) {
        $match = 'contains';
    }

    // Runtime value (customvariablestatus) wins over the config value when present
    $value_col = "COALESCE(cvs.varvalue, cv.varvalue)";
    $name_col = "cv.varname";

    $where = array();
    if ($term !== '') {
        $conds = array();
        $cols = array();
        if ($field == 'name' || $field == 'both') {
            $cols[] = $name_col;
        }
        if ($field == 'value' || $field == 'both') {
            $cols[] = $value_col;
        }
        foreach ($cols as $col) {
            $conds[] = customvarsearch_condition($col, $term, $match);
        }
        $where[] = "(" . implode(" OR ", $conds) . ")";
    }
    if ($hostfilter !== '') {
        $where[] = "LOWER(o.name1) LIKE '%" . escape_sql_param(customvarsearch_escape_like(strtolower($hostfilter)), DB_NDOUTILS) . "%'";
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

    // Reject bad patterns before they reach the database
    if ($match == 'regex' && @preg_match('/' . str_replace('/', '\/', $term) . '/', '') === false) {
        $out['error'] = _("Invalid regular expression.");
        return $out;
    }

    // PHP 8 mysqli throws on SQL errors rather than returning false
    try {
        $rs = exec_sql_query(DB_NDOUTILS, $sql, false);
    } catch (Exception $e) {
        $rs = false;
    }
    if (!$rs) {
        $out['error'] = ($match == 'regex') ? _("Search failed - check that your regular expression is valid.") : _("Search failed - database error.");
        return $out;
    }

    $userid = $_SESSION["user_id"];
    $authorized = array();
    while (!$rs->EOF) {
        $row = $rs->fields;
        // Respect XI object permissions for non-admin users (cached per host)
        $oid = $row['object_id'];
        if (!isset($authorized[$oid])) {
            $authorized[$oid] = is_authorized_for_object_id($userid, $oid);
        }
        if ($authorized[$oid]) {
            $out['results'][] = array(
                'host_name' => $row['host_name'],
                'alias'     => $row['alias'],
                'address'   => $row['address'],
                'varname'   => $row['varname'],
                'varvalue'  => $row['varvalue'],
                'state'     => customvarsearch_state_text($row['current_state'], $row['has_been_checked']),
            );
        }
        $rs->MoveNext();
    }

    return $out;
}


function customvarsearch_condition($col, $term, $match)
{
    switch ($match) {
        case 'exact':
            return "LOWER(" . $col . ") = '" . escape_sql_param(strtolower($term), DB_NDOUTILS) . "'";
        case 'regex':
            // MariaDB/MySQL REGEXP is case-insensitive with the default collation
            return $col . " REGEXP '" . escape_sql_param($term, DB_NDOUTILS) . "'";
        default:
            return "LOWER(" . $col . ") LIKE '%" . escape_sql_param(customvarsearch_escape_like(strtolower($term)), DB_NDOUTILS) . "%'";
    }
}


function customvarsearch_escape_like($s)
{
    return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $s);
}


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
