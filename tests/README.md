# Tests

Three suites, from fastest to most complete:

| Suite | Needs | What it covers |
|---|---|---|
| `php/run_tests.php` | PHP 8 CLI | Search logic against a fake database: SQL building and escaping, permission filtering, secret masking, input limits, regex validation, highlighting/HTML escaping, CSV formula neutralising, suggest results, UTF-8 handling (30 tests) |
| `js/test_search_hook.py` | Python 3, Playwright, Chromium | `search-hook.js` in a real browser against a mocked server: result merging, event handling, jQuery compatibility, failure fallbacks, what is *not* intercepted, abort, XHR reuse, frame→top injection, opening the Custom Variables tab (19 tests) |
| `integration/test_live_xi.py` | A running Nagios XI with the component and test data | End-to-end through XI: access control, admin vs limited user, all match modes, template inheritance, non-ASCII, SQL injection, XSS, secrets, CSV, headers, Neptune Ctrl+K and classic navbar clicks, 10 concurrent sessions (20 tests) |

## Running

```bash
pip install playwright && playwright install chromium   # once, for the JS and live suites

# PHP unit tests (any PHP 8 CLI, e.g. the XI server's)
php tests/php/run_tests.php

# JavaScript unit tests
python3 tests/js/test_search_hook.py

# Live XI integration tests
XI_URL=http://localhost:8080 XI_ADMIN_PASS=... XI_VIEWER_PASS=... XI_CLASSIC_PASS=... \
    python3 tests/integration/test_live_xi.py
```

Optional environment variables: `CHROME` (Chromium executable to use instead of
Playwright's), `HOOK_JS` (JS suite: test another copy of `search-hook.js`),
`XI_CLIENTS` (live suite: concurrent sessions, default 10), `XI_ADMIN_USER`,
`XI_VIEWER_USER`, `XI_CLASSIC_USER`.

## Test data for the live suite

1. Install the component and run `../seed-demo-hosts.sh` (the demo hosts).
2. Run `integration/seed-test-hosts.sh`. It adds `sec-test-01` (secrets, a spreadsheet
   formula, non-ASCII text), `tmpl-child-01` (a variable inherited from a host template),
   `xss-test-01` (an HTML payload), and the users `viewer` (contact for `web-prod-01`
   only) and `classicuser`.

The template and `xss-test-01` are written to XI's CCM import directory inside the XI
container, because the XI API has no host-template endpoint and strips HTML from input.

**License note:** demo + test data is 9 hosts. XI's free *Home Lab* license allows 7; use
a trial license for the full live suite, or remove `Google` and `localhost` first.
`test_template_inherited_variables_are_found` and
`test_html_output_escapes_custom_variable_values` need `tmpl-child-01` and `xss-test-01`.
