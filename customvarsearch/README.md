# Custom Variable Search for Nagios XI

Find hosts by their **custom variables** – `_SERIAL`, `_OWNER`, `_LOCATION`, `_VENDOR`,
`_RACK`, or anything else you track – from a dedicated search page or straight from XI's
global search box. XI's built-in search only covers host and service names.

**Version:** 1.2.0 · **Tested on:** Nagios XI 2026R2 (Ubuntu 24.04, PHP 8.3, MariaDB),
Neptune and classic themes

## Features

- **Global search box integration.** Type in *Search Nagios XI* (Ctrl+K in Neptune) or the
  classic navbar search and matching custom variables appear in a **Custom Variables**
  group. Selecting one opens that host's **Custom Variables** tab; the last entry opens
  the full search page.
- **Search page** at *Home → Details → Custom Variable Search*:
  - search the variable **name**, **value**, or **both**
  - **contains**, **exact** or **regular expression** matching, all case-insensitive
  - optional **host name filter**
  - matches highlighted; live UP/DOWN status; links to each host and its variables tab
  - **CSV** and **JSON** export of any search
- **Respects XI permissions:** users only ever see hosts they can already see in XI.
- **Protects secrets:** values of variables whose names look like credentials are masked
  and can't be searched (see [Security](#security)).
- Uses the values Nagios Core is actually running with, including variables inherited
  from host templates.
- No core XI files are modified; uninstalling removes it completely.

## Installation

1. *Admin → Manage Components → Upload a Component*
2. Choose `customvarsearch.zip` and click **Upload**

Or copy the `customvarsearch/` directory to
`/usr/local/nagiosxi/html/includes/components/` (owned by `nagios:nagios`).

No configuration is needed. The *Custom Variables* host tab it links to is provided by
XI's bundled **Custom Variable Tab** component, which is enabled by default; if it is
disabled, links open the host's Overview tab instead.

## Usage

**Global search box:** type at least two characters. Up to 10 matching variables are
shown, followed by *See all N custom variable matches*.

**Neptune note:** the Neptune shell doesn't run XI's page-head hook, so the search box
integration switches on the first time a classic XI page (Host Status, Service Status,
CCM, reports, this component's page, …) loads in your session. Classic themes have it on
every page.

**Search page examples**

| To find | Search for | Look in | Match |
|---|---|---|---|
| Every host in rack A3 | `rack-a3` | Name or value | Contains |
| Serial numbers starting `SN-R` | `^SN-R[0-9]+` | Value only | Regular expression |
| Hosts with any support contract variable | `contract` | Variable name only | Contains |
| Production hosts named `*prod*` | `production` + host filter `prod` | Name or value | Exact |

### JSON / CSV endpoint

Uses the logged-in XI session (e.g. from a browser or with the session cookie):

```
/nagiosxi/includes/components/customvarsearch/index.php?q=^SN-R&field=value&match=regex&format=json
```

| Parameter | Values |
|---|---|
| `q` | search text (a regex when `match=regex`) |
| `field` | `both` (default), `name`, `value` |
| `match` | `contains` (default), `exact`, `regex` |
| `host` | host name substring filter (optional) |
| `format` | `html` (default), `json`, `csv` |

JSON response:

```json
{
  "query": {"q": "^SN-R", "field": "value", "match": "regex", "host": ""},
  "count": 1,
  "error": null,
  "results": [
    {"host_name": "db-prod-01", "alias": "Primary MariaDB", "address": "10.0.0.5",
     "varname": "SERIAL", "varvalue": "SN-R750-2210", "masked": false, "state": "UP"}
  ]
}
```

`varname` is shown without its leading underscore, as Nagios stores it. `state` is
`UP`, `DOWN`, `UNREACHABLE`, `PENDING` or `UNKNOWN`.

## Security

- **Authentication:** every page and endpoint requires a logged-in XI session.
- **Authorization:** results are filtered with XI's own object permissions, so a user
  only sees hosts they are a contact for (or all hosts, if their account allows it).
- **Secrets:** a variable whose name contains `PASS`, `PWD`, `SECRET`, `TOKEN`, `APIKEY`,
  `API_KEY`, `COMMUNITY`, `PRIVATE` or `CREDENTIAL` (case-insensitive) is shown as
  `********` in every output – page, JSON, CSV and search box – and its value is never
  matched by a value search, so it can't be discovered by guessing. The variable name
  remains searchable. Adjust `CUSTOMVARSEARCH_SENSITIVE_NAMES` in
  `customvarsearch.inc.php` to match your naming.
  *Note:* XI's own Custom Variables host tab still shows these values to anyone who can
  see the host; this component doesn't change that.
- **Injection:** all SQL input is escaped with XI's `escape_sql_param()` (LIKE wildcards
  are matched literally); all HTML output is escaped with `encode_form_val()`; CSV cells
  that would start a spreadsheet formula are prefixed with `'`.
- **Limits:** search text is capped at 255 characters; invalid regular expressions are
  rejected before reaching the database.
- **Browser hook:** `search-hook.js` only intercepts same-origin, asynchronous GET
  requests to `suggest.php?type=multi`, and falls back to XI's untouched behaviour if
  anything fails.

## How it works

| File | Purpose |
|---|---|
| `customvarsearch.inc.php` | Component registration, menu entry, page-head hook, and the search/output logic |
| `index.php` | The search page and the `json` / `csv` / `suggest` endpoints |
| `search-hook.js` | Adds results to XI's global search box; opens the Custom Variables tab |

Searches read the NDO tables `nagios_customvariables` and `nagios_customvariablestatus`
(the runtime value wins when a variable has been changed with an external command),
joined to active host objects and their current status. XI has no extension point for
its search box, so `search-hook.js` wraps `XMLHttpRequest` and merges this component's
results into XI's `suggest.php` responses.

## Uninstall

*Admin → Manage Components* → delete **Custom Variable Search**.

## Changelog

See [CHANGES.txt](CHANGES.txt).
