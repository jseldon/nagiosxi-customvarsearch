<?php
/**
 * Custom Variable Search - search page and data endpoints.
 *
 * URL: /nagiosxi/includes/components/customvarsearch/index.php
 * Requires a logged-in XI session (unauthenticated requests are refused by XI).
 *
 * Request parameters (GET or POST):
 *   q       Text to search for; a regular expression when match=regex
 *   field   Where to look: both (default) | name | value
 *   match   How to match: contains (default) | exact | regex
 *   host    Optional host-name substring filter
 *   format  html (default) | json | csv | suggest
 *   term    (format=suggest only) text typed into XI's global search box
 *
 * Formats:
 *   html     The search form and a results table (matches highlighted, host links)
 *   json     {"query": {...}, "count": n, "error": string|null, "results": [...]}
 *            with the result fields documented at customvarsearch_search()
 *   csv      Download: host_name, alias, address, state, variable, value
 *   suggest  JSON list for search-hook.js, see customvarsearch_suggest_items()
 *
 * Sensitive values are masked in every format (see CUSTOMVARSEARCH_SENSITIVE_NAMES).
 *
 * @package   customvarsearch
 * @copyright 2026 James Seldon
 * @license   MIT (see LICENSE)
 */

require_once(dirname(__FILE__) . '/../../common.inc.php');
require_once(dirname(__FILE__) . '/customvarsearch.inc.php');

// Standard XI page bootstrap: session, request vars, prerequisites, login check
pre_init();
init_session();
grab_request_vars();
check_prereqs();
check_authentication(false);


$format = customvarsearch_request_str("format", "html");

// Global search box integration (called by search-hook.js) - same shape as XI's suggest.php
if ($format == "suggest") {
    header("Content-Type: application/json");
    header("X-Content-Type-Options: nosniff");
    echo json_encode(customvarsearch_suggest_items(customvarsearch_request_str("term")), CUSTOMVARSEARCH_JSON_FLAGS);
    exit();
}

$term = customvarsearch_request_str("q");
$field = customvarsearch_request_str("field", "both");
$match = customvarsearch_request_str("match", "contains");
$hostfilter = customvarsearch_request_str("host");
$searched = ($term !== '' || $hostfilter !== '');

$search = $searched ? customvarsearch_search($term, $field, $match, $hostfilter) : array('results' => array(), 'error' => null);

switch ($format) {
    case 'json':
        header("Content-Type: application/json");
        header("X-Content-Type-Options: nosniff");
        echo json_encode(array(
            'query'   => array('q' => $term, 'field' => $field, 'match' => $match, 'host' => $hostfilter),
            'count'   => count($search['results']),
            'error'   => $search['error'],
            'results' => $search['results'],
        ), JSON_PRETTY_PRINT | CUSTOMVARSEARCH_JSON_FLAGS);
        exit();

    case 'csv':
        header("Content-Type: text/csv; charset=UTF-8");
        header("X-Content-Type-Options: nosniff");
        header("Content-Disposition: attachment; filename=\"customvar-search.csv\"");
        $fh = fopen("php://output", "w");
        fputcsv($fh, array('host_name', 'alias', 'address', 'state', 'variable', 'value'));
        foreach ($search['results'] as $r) {
            fputcsv($fh, array_map('customvarsearch_csv_cell', array($r['host_name'], $r['alias'], $r['address'], $r['state'], $r['varname'], $r['varvalue'])));
        }
        fclose($fh);
        exit();
}

customvarsearch_show_page($term, $field, $match, $hostfilter, $searched, $search);


/**
 * Render the search page: the form, then either an error, or the result count with
 * CSV/JSON export links and the results table.
 *
 * All user- and database-supplied text is escaped with encode_form_val(), or
 * customvarsearch_highlight() for highlighted cells. Host names link to the host's
 * detail page; variable names link to its Custom Variables tab.
 *
 * @param string $term       Search text as entered
 * @param string $field      'both', 'name' or 'value' (form state)
 * @param string $match      'contains', 'exact' or 'regex' (form state)
 * @param string $hostfilter Host name filter as entered
 * @param bool   $searched   Whether a search was submitted (vs. the empty form)
 * @param array  $search     Return value of customvarsearch_search()
 * @return void              Outputs a full XI page
 */
function customvarsearch_show_page($term, $field, $match, $hostfilter, $searched, $search)
{
    $state_class = array('UP' => 'cvs-up', 'DOWN' => 'cvs-down', 'UNREACHABLE' => 'cvs-unreach', 'PENDING' => 'cvs-pending', 'UNKNOWN' => 'cvs-pending');
    $qs = http_build_query(array('q' => $term, 'field' => $field, 'match' => $match, 'host' => $hostfilter));

    do_page_start(array("page_title" => _("Custom Variable Search")), true);
?>
    <style>
        .cvs-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 10px 0 16px; }
        .cvs-form label { display: block; font-size: 11px; font-weight: bold; margin-bottom: 2px; }
        .cvs-form .cvs-q { width: 320px; }
        .cvs-table mark { background: #ffe066; padding: 0 1px; border-radius: 2px; }
        .cvs-table td.cvs-var { font-family: monospace; white-space: nowrap; }
        .cvs-state { display: inline-block; min-width: 90px; text-align: center; padding: 1px 6px; border-radius: 3px; color: #fff; font-size: 11px; font-weight: bold; }
        .cvs-up { background: #4caf50; } .cvs-down { background: #e53935; }
        .cvs-unreach { background: #fb8c00; } .cvs-pending { background: #9e9e9e; }
        .cvs-meta { margin: 6px 0; }
    </style>

    <h1><?php echo _("Custom Variable Search"); ?></h1>
    <p><?php echo _("Find hosts by their custom variables (e.g. <code>_SERIAL</code>, <code>_OWNER</code>, <code>_LOCATION</code>). Searches are case-insensitive."); ?></p>

    <form method="get" class="cvs-form">
        <div>
            <label for="cvs-q"><?php echo _("Search for"); ?></label>
            <input type="text" id="cvs-q" name="q" class="form-control cvs-q" value="<?php echo encode_form_val($term); ?>" placeholder="<?php echo _("e.g. rack-a3, dell, ^SN-"); ?>" autofocus>
        </div>
        <div>
            <label for="cvs-field"><?php echo _("Look in"); ?></label>
            <select id="cvs-field" name="field" class="form-control">
                <option value="both" <?php echo is_selected($field, "both"); ?>><?php echo _("Name or value"); ?></option>
                <option value="value" <?php echo is_selected($field, "value"); ?>><?php echo _("Value only"); ?></option>
                <option value="name" <?php echo is_selected($field, "name"); ?>><?php echo _("Variable name only"); ?></option>
            </select>
        </div>
        <div>
            <label for="cvs-match"><?php echo _("Match"); ?></label>
            <select id="cvs-match" name="match" class="form-control">
                <option value="contains" <?php echo is_selected($match, "contains"); ?>><?php echo _("Contains"); ?></option>
                <option value="exact" <?php echo is_selected($match, "exact"); ?>><?php echo _("Exact"); ?></option>
                <option value="regex" <?php echo is_selected($match, "regex"); ?>><?php echo _("Regular expression"); ?></option>
            </select>
        </div>
        <div>
            <label for="cvs-host"><?php echo _("Host name filter"); ?></label>
            <input type="text" id="cvs-host" name="host" class="form-control" value="<?php echo encode_form_val($hostfilter); ?>" placeholder="<?php echo _("optional"); ?>">
        </div>
        <div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> <?php echo _("Search"); ?></button>
        </div>
    </form>

<?php
    if ($search['error']) {
        echo '<div class="alert alert-danger">' . encode_form_val($search['error']) . '</div>';
    } else if ($searched) {
        $results = $search['results'];
        $hosts = count(array_unique(array_column($results, 'host_name')));
?>
        <div class="cvs-meta">
            <?php echo sprintf(_("%d matching variable(s) on %d host(s)."), count($results), $hosts); ?>
            <?php if (count($results)) { ?>
                &nbsp; <a href="?<?php echo encode_form_val($qs); ?>&amp;format=csv"><i class="fa fa-file-text-o"></i> CSV</a>
                &nbsp; <a href="?<?php echo encode_form_val($qs); ?>&amp;format=json" target="_blank"><i class="fa fa-code"></i> JSON</a>
            <?php } ?>
        </div>
<?php
        if (count($results)) {
?>
        <table class="table table-condensed table-striped table-bordered table-auto-width cvs-table">
            <thead>
                <tr>
                    <th><?php echo _("Host"); ?></th>
                    <th><?php echo _("Address"); ?></th>
                    <th><?php echo _("Status"); ?></th>
                    <th><?php echo _("Variable"); ?></th>
                    <th><?php echo _("Value"); ?></th>
                </tr>
            </thead>
            <tbody>
<?php
            $hl_name = ($field != 'value');
            $hl_value = ($field != 'name');
            foreach ($results as $r) {
                $url = get_base_url() . "includes/components/xicore/status.php?show=hostdetail&host=" . urlencode($r['host_name']);
                $varurl = customvarsearch_host_url($r['host_name']);
                echo '<tr>';
                echo '<td><a href="' . encode_form_val($url) . '">' . encode_form_val($r['host_name']) . '</a>';
                if ($r['alias'] != '' && $r['alias'] != $r['host_name']) {
                    echo '<br><small class="text-muted">' . encode_form_val($r['alias']) . '</small>';
                }
                echo '</td>';
                echo '<td>' . encode_form_val($r['address']) . '</td>';
                echo '<td><span class="cvs-state ' . $state_class[$r['state']] . '">' . encode_form_val($r['state']) . '</span></td>';
                echo '<td class="cvs-var"><a href="' . encode_form_val($varurl) . '" title="' . encode_form_val(_("Show this host's custom variables")) . '">_' . ($hl_name ? customvarsearch_highlight($r['varname'], $term, $match) : encode_form_val($r['varname'])) . '</a></td>';
                echo '<td>' . (($hl_value && !$r['masked']) ? customvarsearch_highlight($r['varvalue'], $term, $match) : encode_form_val($r['varvalue'])) . '</td>';
                echo '</tr>';
            }
?>
            </tbody>
        </table>
<?php
        }
    }

    do_page_end(true);
}
