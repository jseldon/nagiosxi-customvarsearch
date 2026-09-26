"""
Integration tests against a live Nagios XI with the component installed.

Expects the demo data from seed-demo-hosts.sh and tests/integration/seed-test-hosts.sh:
a non-admin "viewer" who is a contact for web-prod-01 only, and a "classicuser" whose
theme the classic-theme test switches to Modern (xi5).

Usage:
    XI_URL=http://localhost:8080 XI_ADMIN_PASS=... XI_VIEWER_PASS=... XI_CLASSIC_PASS=... \
        python3 tests/integration/test_live_xi.py
Env:
    CHROME           optional Chromium executable (defaults to Playwright's bundled browser)
    XI_CLIENTS       number of simultaneous sessions in the concurrency test (default 10)
"""
import concurrent.futures
import json
import os
import statistics
import time
import unittest
from urllib.parse import quote

from playwright.sync_api import sync_playwright

XI = os.environ.get("XI_URL", "http://localhost:8080").rstrip("/") + "/nagiosxi/"
ADMIN = (os.environ.get("XI_ADMIN_USER", "nagiosadmin"), os.environ["XI_ADMIN_PASS"])
VIEWER = (os.environ.get("XI_VIEWER_USER", "viewer"), os.environ["XI_VIEWER_PASS"])
CLASSIC = (os.environ.get("XI_CLASSIC_USER", "classicuser"), os.environ.get("XI_CLASSIC_PASS"))
CHROME = os.environ.get("CHROME")
CLIENTS = int(os.environ.get("XI_CLIENTS", "10"))
PAGE = XI + "includes/components/customvarsearch/index.php"
TAB = "&cvstab=1"


def launch(pw):
    return pw.chromium.launch(executable_path=CHROME) if CHROME else pw.chromium.launch()


def login(page, user, password):
    page.goto(XI + "login.php")
    page.fill("#usernameBox", user)
    page.fill("#passwordBox", password)
    page.click("#loginButton")
    page.wait_for_load_state("networkidle")
    if "showlicense" in page.url:
        page.check("#agree_license")
        page.click("#submitButton")
        page.wait_for_load_state("networkidle")
    assert "login.php" not in page.url, f"login failed for {user}"


class Client:
    """One logged-in XI browser session (its own cookies)."""

    def __init__(self, browser, creds):
        self.ctx = browser.new_context(viewport={"width": 1400, "height": 800})
        self.page = self.ctx.new_page()
        if creds:
            login(self.page, *creds)

    def get(self, query):
        return self.ctx.request.get(PAGE + "?" + query, max_redirects=0)

    def search(self, **params):
        params.setdefault("format", "json")
        q = "&".join(f"{k}={quote(str(v), safe='')}" for k, v in params.items())
        r = self.get(q)
        assert r.status == 200, (r.status, q)
        return r.json()

    def suggest(self, term):
        return self.search(format="suggest", term=term)

    def close(self):
        self.ctx.close()


class LiveXITests(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.pw = sync_playwright().start()
        cls.browser = launch(cls.pw)
        cls.admin = Client(cls.browser, ADMIN)
        cls.viewer = Client(cls.browser, VIEWER)
        cls.anon = Client(cls.browser, None)

    @classmethod
    def tearDownClass(cls):
        for c in (cls.admin, cls.viewer, cls.anon):
            c.close()
        cls.browser.close()
        cls.pw.stop()

    def hosts(self, res):
        return sorted({r["host_name"] for r in res["results"]})

    # ------------------------------------------------------------------ access control
    def test_anonymous_requests_are_refused(self):
        # XI answers unauthenticated requests with 401 (or a redirect to login.php)
        for q in ("q=dell&format=json", "format=suggest&term=dell", "q=dell&format=csv", "q=dell"):
            r = self.anon.get(q)
            self.assertIn(r.status, (301, 302, 401), q)
            if r.status != 401:
                self.assertIn("login.php", r.headers.get("location", ""), q)
            self.assertNotIn("db-prod-01", r.text(), q)
            self.assertNotIn("Dell", r.text(), q)

    def test_viewer_only_sees_authorized_hosts(self):
        self.assertEqual(self.hosts(self.admin.search(q="example.com")),
                         ["app-stage-01", "db-prod-01", "fw-edge-01", "sec-test-01", "web-prod-01"])
        self.assertEqual(self.hosts(self.viewer.search(q="example.com")), ["web-prod-01"])
        self.assertEqual([i for i in self.viewer.suggest("R750")], [], "viewer must not see db-prod-01 via suggest")

    # ------------------------------------------------------------------ search behaviour
    def test_match_modes(self):
        self.assertEqual(self.hosts(self.admin.search(q="dell")), ["app-stage-01", "db-prod-01"])
        self.assertEqual(self.hosts(self.admin.search(q="^SN-R[0-9]+", field="value", match="regex")), ["app-stage-01", "db-prod-01"])
        self.assertEqual(self.hosts(self.admin.search(q="production", match="exact", host="prod")), ["db-prod-01", "web-prod-01"])
        res = self.admin.search(q="contract", field="name")
        self.assertEqual([(r["host_name"], r["varname"]) for r in res["results"]], [("fw-edge-01", "SUPPORT_CONTRACT")])

    def test_template_inherited_variables_are_found(self):
        self.assertEqual(self.hosts(self.admin.search(q="TEMPLATE-DC-99")), ["tmpl-child-01"])

    def test_non_ascii_case_folding(self):
        self.assertEqual(self.hosts(self.admin.search(q="münchen")), ["sec-test-01"])

    def test_like_wildcards_are_literal(self):
        self.assertEqual(self.admin.search(q="%")["count"], 0)
        self.assertEqual(self.admin.search(q="_", field="value")["count"], 0)

    def test_sql_injection_attempts_are_inert(self):
        for q in ("' OR '1'='1", "\\' OR 1=1 -- ", "x' UNION SELECT 1,2,3,4,5,6,7,8 -- ", '" OR ""="'):
            for match in ("contains", "exact", "regex"):
                res = self.admin.search(q=q, match=match)
                self.assertEqual(res["count"], 0, (q, match))

    def test_bad_input_gives_errors_not_crashes(self):
        self.assertEqual(self.admin.search(q="[unclosed", match="regex")["error"], "Invalid regular expression.")
        self.assertIn("limited to", self.admin.search(q="a" * 256)["error"])
        for q in ("q[]=x&format=json", "field[]=x&q=dell&format=json", "format[]=json&q=dell", "format=suggest&term[]=x"):
            self.assertEqual(self.admin.get(q).status, 200, q)

    # ------------------------------------------------------------------ secrets
    def test_sensitive_values_are_masked_everywhere(self):
        secrets = ("s3cr3t-community", "hunter2-pw", "tok-abc123")
        res = self.admin.search(q="", host="sec-test-01")
        masked = {r["varname"]: r["varvalue"] for r in res["results"] if r["masked"]}
        self.assertEqual(masked, {"SNMP_COMMUNITY": "********", "DB_PASSWORD": "********", "API_TOKEN": "********"})
        csv = self.admin.get("q=&host=sec-test-01&format=csv").text()
        html = self.admin.get("q=&host=sec-test-01").text()
        for s in secrets:
            self.assertNotIn(s, json.dumps(res))
            self.assertNotIn(s, csv)
            self.assertNotIn(s, html)

    def test_sensitive_values_cannot_be_searched(self):
        for q, match in (("s3cr3t", "contains"), ("hunter2-pw", "contains"), ("^s3", "regex"), ("tok-abc123", "exact")):
            self.assertEqual(self.admin.search(q=q, match=match, field="value")["count"], 0, q)
            self.assertEqual(self.admin.suggest(q), [], q)
        # Searching by the variable *name* still finds it, masked
        res = self.admin.search(q="community", field="name")
        self.assertEqual([r["varvalue"] for r in res["results"]], ["********"])

    # ------------------------------------------------------------------ output encoding
    def test_html_output_escapes_custom_variable_values(self):
        # xss-test-01 comes from a CCM import file; the XI API strips tags on input
        # (XI strips tags from request input, so the payload can't be the search term itself)
        for q in ("q=onerror", "q=onerror&match=regex", "q=HTMLTEST&field=name"):
            html = self.admin.get(q).text()
            self.assertIn("xss-test-01", html, q)
            self.assertNotIn("<img src=x", html, q)
            self.assertIn("&lt;img src=x", html, q)

    def test_reflected_search_terms_are_escaped(self):
        html = self.admin.get("q=" + quote('"><script>alert(1)</script>') + "&host=" + quote('"><b>')).text()
        self.assertNotIn("<script>alert(1)", html)
        self.assertNotIn('"><b>', html)

    def test_csv_export_neutralises_formulas(self):
        csv = self.admin.get("q=HYPERLINK&format=csv").text()
        self.assertIn("'=HYPERLINK", csv)
        self.assertNotIn(",=HYPERLINK", csv)

    def test_json_responses_are_nosniff(self):
        for q in ("q=dell&format=json", "format=suggest&term=dell"):
            r = self.admin.get(q)
            self.assertIn("application/json", r.headers["content-type"])
            self.assertEqual(r.headers.get("x-content-type-options"), "nosniff")

    # ------------------------------------------------------------------ global search integration
    def test_suggest_links_to_custom_variables_tab(self):
        items = self.admin.suggest("R750")
        self.assertEqual(items[0]["url"], "/nagiosxi/includes/components/xicore/status.php?show=hostdetail&host=db-prod-01" + TAB)
        self.assertEqual(items[-1]["url"], "/nagiosxi/includes/components/customvarsearch/index.php?q=R750")

    def test_hook_script_is_served_on_xi_pages(self):
        html = self.admin.ctx.request.get(XI + "includes/components/xicore/status.php?show=hosts").text()
        self.assertIn('customvarsearch/search-hook.js?v=', html)
        self.assertEqual(self.admin.ctx.request.get(XI + "includes/components/customvarsearch/search-hook.js").status, 200)

    def test_neptune_ctrl_k_click_opens_custom_variables_tab(self):
        page = self.admin.page
        page.goto(XI + "index.php?xiwindow=" + quote("/nagiosxi/includes/components/xicore/status.php?show=hosts", safe=""))
        page.wait_for_load_state("networkidle")
        page.wait_for_function("() => window.__cvsSearchHook === true", timeout=15000)
        tour = page.locator("text=End Tour")
        if tour.count():
            tour.first.click()
        page.click("#header-search-box")
        page.fill("#header-search-box", "R750")
        item = page.locator("[cmdk-group]:has-text('Custom Variables') [cmdk-item]").first
        item.wait_for(timeout=10000)
        item.click()
        # Neptune navigates via ?xiwindow=, which recreates the content iframe
        page.wait_for_function("""() => { const f = document.querySelector('iframe[name=maincontentframe]');
            try { return f && f.contentWindow.location.href.includes('show=hostdetail'); } catch (e) { return false; } }""",
            timeout=15000)
        frame = page.frame(name="maincontentframe")
        frame.wait_for_selector("#tab-custom-customvariabletab", state="visible", timeout=15000)
        self.assertTrue(frame.url.endswith("host=db-prod-01" + TAB), frame.url)
        self.assertIn("SN-R750-2210", frame.inner_text("#tab-custom-customvariabletab"))
        self.assertFalse(frame.is_visible("#tab-overview"), "overview tab should not be the active tab")
        page.screenshot(path=os.path.join(os.path.dirname(__file__), "last-ctrl-k-click.png"))

    def test_viewer_ctrl_k_shows_only_authorized_hosts(self):
        page = self.viewer.page
        page.goto(XI + "index.php?xiwindow=" + quote("/nagiosxi/includes/components/xicore/status.php?show=hosts", safe=""))
        page.wait_for_load_state("networkidle")
        page.wait_for_function("() => window.__cvsSearchHook === true", timeout=15000)
        page.click("#header-search-box")
        page.fill("#header-search-box", "example.com")
        page.locator("[cmdk-group]:has-text('Custom Variables')").wait_for(timeout=10000)
        labels = page.locator("[cmdk-group]:has-text('Custom Variables') [cmdk-item]").all_inner_texts()
        self.assertTrue(labels and all("web-prod-01" in l or "See all" in l for l in labels), labels)
        page.keyboard.press("Escape")

    @unittest.skipUnless(CLASSIC[1], "set XI_CLASSIC_PASS to run the classic theme test")
    def test_classic_theme_navbar_search(self):
        c = Client(self.browser, CLASSIC)
        try:
            self.assertEqual(c.ctx.request.post(XI + "api/v2/account/theme", form={"theme": "xi5"}).status, 200)
            page = c.page
            page.goto(XI + "index.php")
            page.wait_for_load_state("networkidle")
            # Classic themes run the page-head hook on the top page: active straight away
            self.assertTrue(page.evaluate("window.__cvsSearchHook === true"))
            page.click("#open-search")
            page.type("#navbarSearchBox", "R750", delay=50)
            header = page.locator("ul.ui-autocomplete li.dropdown-header", has_text="Custom Variables")
            header.wait_for(timeout=10000)
            item = page.locator("ul.ui-autocomplete li.dropdown-header:has-text('Custom Variables') ~ li").first
            self.assertIn("db-prod-01", item.inner_text())
            self.assertIn("SN-R750-2210", item.inner_text())
            item.click()
            page.wait_for_function("""() => { try { return document.getElementById('maincontentframe')
                .contentWindow.location.href.includes('cvstab=1'); } catch (e) { return false; } }""", timeout=15000)
            frame = page.frame(name="maincontentframe")
            frame.wait_for_selector("#tab-custom-customvariabletab", state="visible", timeout=15000)
            page.screenshot(path=os.path.join(os.path.dirname(__file__), "last-classic-click.png"))
        finally:
            c.close()

    # ------------------------------------------------------------------ concurrency
    def test_concurrent_clients(self):
        """Several independent sessions typing into the search box at once."""
        terms = ["dell", "van-dc1", "R750", "fortinet", "prod", "example.com", "SN-", "rack"]
        expected = {t: json.dumps(self.admin.suggest(t), sort_keys=True) for t in terms}

        def run_client(n):
            # Each thread needs its own Playwright instance (sync API isn't thread-safe)
            with sync_playwright() as pw:
                browser = launch(pw)
                c = Client(browser, ADMIN)
                timings, wrong = [], []
                for i in range(3 * len(terms)):
                    t = terms[(n + i) % len(terms)]
                    start = time.perf_counter()
                    got = json.dumps(c.suggest(t), sort_keys=True)
                    timings.append(time.perf_counter() - start)
                    if got != expected[t]:
                        wrong.append(t)
                c.close()
                browser.close()
                return timings, wrong

        with concurrent.futures.ThreadPoolExecutor(max_workers=CLIENTS) as ex:
            results = list(ex.map(run_client, range(CLIENTS)))
        timings = [t for r in results for t in r[0]]
        wrong = [w for r in results for w in r[1]]
        print(f"\n    {CLIENTS} clients x {len(timings) // CLIENTS} suggest calls: "
              f"median {statistics.median(timings) * 1000:.0f} ms, max {max(timings) * 1000:.0f} ms", end=" ")
        self.assertEqual(wrong, [], "every client must get the same results as a lone client")


if __name__ == "__main__":
    unittest.main(verbosity=2)
