"""
Unit tests for customvarsearch/search-hook.js, run in headless Chromium.

No Nagios XI is needed: every request to http://cvs.test is answered by Playwright route
handlers, so each test controls exactly what core suggest.php and the component endpoint
return. The page uses real XMLHttpRequest and jQuery 3.6.0 (XI's copy, used by the classic
navbar autocomplete).

Usage:  python3 tests/js/test_search_hook.py
Env:    CHROME=/path/to/chromium   (optional; defaults to Playwright's bundled browser)
        HOOK_JS=/path/to/search-hook.js  (optional; test another copy of the hook)
"""
import json
import os
import time
import unittest
from urllib.parse import urlparse, parse_qs

from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
HOOK_JS = open(os.environ.get("HOOK_JS") or os.path.join(HERE, "..", "..", "customvarsearch", "search-hook.js")).read()
JQUERY = open(os.path.join(HERE, "vendor", "jquery-3.6.0.min.js")).read()

ORIGIN = "http://cvs.test"
ENDPOINT = "/nagiosxi/includes/components/customvarsearch/index.php"
HOOK_TAG = (f'<script src="/nagiosxi/includes/components/customvarsearch/search-hook.js" data-cvs-endpoint="{ENDPOINT}"'
            ' data-cvs-tab="tab-custom-customvariabletab" data-cvs-tab-param="cvstab"></script>')

# Stand-in for XI's host detail page: tabs whose links record clicks
HOST_DETAIL = f"""<html><head>{HOOK_TAG}</head><body><div id="tabs"><ul>
  <li><a href="#tab-overview">Overview</a></li>
  <li><a href="#tab-custom-customvariabletab">Custom Variables</a></li></ul></div>
<script>window.clicked = [];
  document.querySelectorAll('#tabs a').forEach(a => a.addEventListener('click', e => {{
      e.preventDefault(); window.clicked.push(a.getAttribute('href')); }}));</script></body></html>"""

CORE_ITEMS = [{"url": "/x/h", "value": "db-prod-01", "label": "db-prod-01", "category": "Host"}]
CVS_ITEMS = [{"url": "/x/cv", "value": "db-prod-01 _SERIAL SN-1", "label": "db-prod-01 - _SERIAL = SN-1", "category": "Custom Variables"}]


class HookTests(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.pw = sync_playwright().start()
        chrome = os.environ.get("CHROME")
        cls.browser = cls.pw.chromium.launch(executable_path=chrome) if chrome else cls.pw.chromium.launch()

    @classmethod
    def tearDownClass(cls):
        cls.browser.close()
        cls.pw.stop()

    def setUp(self):
        # Per-test mock behaviour, and a log of every request that reached the "server"
        self.core = {"status": 200, "body": json.dumps(CORE_ITEMS), "delay": 0}
        self.cvs = {"status": 200, "body": json.dumps(CVS_ITEMS), "delay": 0}
        self.requests = []
        self.ctx = self.browser.new_context()
        self.ctx.route(f"{ORIGIN}/**", self._serve)
        self.ctx.route("http://other.test/**", self._serve_other)
        self.page = self.ctx.new_page()

    def tearDown(self):
        self.ctx.close()

    # ------------------------------------------------------------------ mock server
    def _serve(self, route):
        url = urlparse(route.request.url)
        self.requests.append((route.request.method, url.path, parse_qs(url.query)))
        if url.path == "/top.html":
            return route.fulfill(content_type="text/html", body=f"<html><head>{HOOK_TAG}</head><body></body></html>")
        if url.path == "/top-with-frame.html":
            return route.fulfill(content_type="text/html", body='<html><head></head><body><iframe id="f" src="/child.html"></iframe></body></html>')
        if url.path == "/child.html":
            return route.fulfill(content_type="text/html", body=f"<html><head>{HOOK_TAG}</head><body>child</body></html>")
        if url.path == "/status.php":
            return route.fulfill(content_type="text/html", body=HOST_DETAIL)
        if url.path == "/no-endpoint.html":
            tag = '<script src="/nagiosxi/includes/components/customvarsearch/search-hook.js"></script>'
            return route.fulfill(content_type="text/html", body=f"<html><head>{tag}</head></html>")
        if url.path.endswith("/search-hook.js"):
            return route.fulfill(content_type="application/javascript", body=HOOK_JS)
        if url.path == "/jquery.js":
            return route.fulfill(content_type="application/javascript", body=JQUERY)
        if url.path == "/nagiosxi/suggest.php":
            return self._reply(route, self.core)
        if url.path == ENDPOINT:
            return self._reply(route, self.cvs)
        return route.fulfill(status=200, content_type="text/plain", body="other")

    def _serve_other(self, route):
        self.requests.append((route.request.method, "other.test" + urlparse(route.request.url).path, {}))
        route.fulfill(status=200, content_type="application/json", headers={"Access-Control-Allow-Origin": "*"}, body="[]")

    @staticmethod
    def _reply(route, spec):
        if spec["delay"]:
            time.sleep(spec["delay"])
        ctype = "application/json" if spec["body"].startswith(("[", "{")) else "text/html"
        route.fulfill(status=spec["status"], content_type=ctype, body=spec["body"])

    # ------------------------------------------------------------------ helpers
    def open_top(self, path="/top.html"):
        self.page.goto(ORIGIN + path)
        self.page.wait_for_load_state("load")

    def hits(self, path):
        return [r for r in self.requests if r[1] == path]

    def xhr(self, url, extra_js=""):
        """Run an XHR in the page and resolve with what its handlers observed."""
        return self.page.evaluate("""([url, extra]) => new Promise(resolve => {
            const x = new XMLHttpRequest();
            const seen = {onreadystatechange4: 0, onload: 0, onloadend: 0, listenerLoad: 0};
            x.onreadystatechange = () => { if (x.readyState === 4) seen.onreadystatechange4++; };
            x.onload = () => seen.onload++;
            x.addEventListener('load', () => seen.listenerLoad++);
            x.onloadend = () => { seen.onloadend++; setTimeout(() => resolve({seen, status: x.status, text: x.responseText,
                ctype: x.getResponseHeader('Content-Type')}), 50); };
            x.open('GET', url);
            eval(extra);
            x.send();
        })""", [url, extra_js])

    # ------------------------------------------------------------------ tests
    def test_merges_core_and_component_results(self):
        self.open_top()
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=SN-1")
        self.assertEqual(r["status"], 200)
        self.assertEqual(json.loads(r["text"]), CORE_ITEMS + CVS_ITEMS)
        self.assertIn("application/json", r["ctype"])
        (_, _, q), = self.hits(ENDPOINT)
        self.assertEqual(q, {"format": ["suggest"], "term": ["SN-1"]})

    def test_each_handler_fires_exactly_once(self):
        # Regression: handlers used to run twice (called directly and via dispatchEvent)
        self.open_top()
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(r["seen"], {"onreadystatechange4": 1, "onload": 1, "onloadend": 1, "listenerLoad": 1})

    def test_response_type_json(self):
        self.open_top()
        n = self.page.evaluate("""() => new Promise(res => {
            const x = new XMLHttpRequest(); x.responseType = 'json';
            x.onloadend = () => res(x.response.length);
            x.open('GET', '/nagiosxi/suggest.php?type=multi&term=ab'); x.send(); })""")
        self.assertEqual(n, 2)

    def test_jquery_ajax_like_classic_navbar(self):
        self.open_top()
        self.page.add_script_tag(url="/jquery.js")
        items = self.page.evaluate("""() => new Promise((res, rej) =>
            $.ajax({url: '/nagiosxi/suggest.php', data: {type: 'multi', term: 'ab'}, dataType: 'json'})
             .done(res).fail((x, s, e) => rej(s + ' ' + e)))""")
        self.assertEqual(items, CORE_ITEMS + CVS_ITEMS)

    def test_component_error_returns_core_results_only(self):
        self.cvs.update(status=500, body="boom")
        self.open_top()
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(json.loads(r["text"]), CORE_ITEMS)

    def test_component_html_response_returns_core_results_only(self):
        # e.g. component uninstalled or a login page served instead of JSON
        self.cvs.update(body="<html>login</html>")
        self.open_top()
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(json.loads(r["text"]), CORE_ITEMS)

    def test_core_failure_falls_back_to_the_real_request(self):
        self.core.update(status=500, body="server error")
        self.open_top()
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(r["status"], 500, "caller should see the genuine core response")
        self.assertEqual(r["text"], "server error")
        self.assertEqual(r["seen"]["onload"], 1)

    def test_other_requests_are_untouched(self):
        self.open_top()
        for url in ("/nagiosxi/suggest.php?type=host&term=ab", "/nagiosxi/other.php?type=multi&term=ab"):
            self.xhr(url)
        self.page.evaluate("fetch('/nagiosxi/suggest.php?type=multi&term=ab')")
        self.page.wait_for_timeout(200)
        self.assertEqual(self.hits(ENDPOINT), [], "fetch() and non-multi requests must not be hooked")

    def test_post_is_untouched(self):
        self.open_top()
        self.page.evaluate("""() => new Promise(res => { const x = new XMLHttpRequest();
            x.onloadend = res; x.open('POST', '/nagiosxi/suggest.php?type=multi&term=ab'); x.send('a=1'); })""")
        self.assertEqual(self.hits(ENDPOINT), [])

    def test_synchronous_request_is_untouched(self):
        self.open_top()
        text = self.page.evaluate("""() => { const x = new XMLHttpRequest();
            x.open('GET', '/nagiosxi/suggest.php?type=multi&term=ab', false); x.send(); return x.responseText; }""")
        self.assertEqual(json.loads(text), CORE_ITEMS)
        self.assertEqual(self.hits(ENDPOINT), [])

    def test_cross_origin_suggest_is_untouched(self):
        self.open_top()
        self.xhr("http://other.test/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(self.hits(ENDPOINT), [])
        self.assertEqual(len(self.hits("other.test/nagiosxi/suggest.php")), 1)

    def test_abort_suppresses_completion(self):
        self.cvs["delay"] = 0.5
        self.open_top()
        fired = self.page.evaluate("""() => new Promise(res => {
            const x = new XMLHttpRequest(); let loads = 0;
            x.onload = () => loads++;
            x.open('GET', '/nagiosxi/suggest.php?type=multi&term=ab'); x.send(); x.abort();
            setTimeout(() => res(loads), 1200); })""")
        self.assertEqual(fired, 0)

    def test_term_is_url_encoded(self):
        self.open_top()
        self.xhr("/nagiosxi/suggest.php?type=multi&term=" + "a%26b%23c%20M%C3%BCnchen")
        (_, _, q), = self.hits(ENDPOINT)
        self.assertEqual(q["term"], ["a&b#c München"])

    def test_child_frame_installs_hook_in_top_window_once(self):
        self.open_top("/top-with-frame.html")
        self.page.wait_for_function("() => window.__cvsSearchHook === true")
        # Reload the child: must not inject a second copy into the top window
        self.page.evaluate("document.getElementById('f').contentWindow.location.reload()")
        self.page.wait_for_timeout(300)
        self.assertEqual(self.page.evaluate("document.querySelectorAll('#cvs-search-hook').length"), 1)
        # The child document is gone after navigation; the hook lives in the top realm and still works
        self.page.evaluate("document.getElementById('f').src = 'about:blank'")
        self.page.wait_for_timeout(200)
        r = self.xhr("/nagiosxi/suggest.php?type=multi&term=ab")
        self.assertEqual(json.loads(r["text"]), CORE_ITEMS + CVS_ITEMS)
        self.assertEqual(len(self.hits(ENDPOINT)), 1, "exactly one component call per suggest request")

    def test_cvstab_param_opens_custom_variables_tab(self):
        self.page.goto(ORIGIN + "/status.php?show=hostdetail&host=db-prod-01&cvstab=1")
        self.page.wait_for_load_state("load")
        self.assertEqual(self.page.evaluate("window.clicked"), ["#tab-custom-customvariabletab"])

    def test_no_cvstab_param_leaves_tabs_alone(self):
        for q in ("", "&cvstab=0", "&cvstab=1x"):
            self.page.goto(ORIGIN + "/status.php?show=hostdetail&host=db-prod-01" + q)
            self.page.wait_for_load_state("load")
            self.assertEqual(self.page.evaluate("window.clicked"), [], q)

    def test_reused_xhr_gets_the_real_response_next_time(self):
        # A completed suggest request leaves overridden properties on the instance;
        # reusing the same object for another request must see the genuine response
        self.open_top()
        r = self.page.evaluate("""() => new Promise(res => {
            const x = new XMLHttpRequest(); const seen = [];
            x.onloadend = () => {
                seen.push([x.status, x.responseText.slice(0, 5)]);
                if (seen.length === 1) { x.open('GET', '/nagiosxi/other.php'); x.send(); } else { res(seen); }
            };
            x.open('GET', '/nagiosxi/suggest.php?type=multi&term=ab'); x.send(); })""")
        self.assertEqual(r[0], [200, '[{"ur'])
        self.assertEqual(r[1], [200, 'other'])

    def test_top_window_copy_carries_all_settings(self):
        self.open_top("/top-with-frame.html")
        self.page.wait_for_function("() => window.__cvsSearchHook === true")
        attrs = self.page.evaluate("""() => { const s = document.getElementById('cvs-search-hook');
            return [s.dataset.cvsEndpoint, s.dataset.cvsTab, s.dataset.cvsTabParam]; }""")
        self.assertEqual(attrs, [ENDPOINT, "tab-custom-customvariabletab", "cvstab"])

    def test_script_without_endpoint_does_nothing(self):
        self.open_top("/no-endpoint.html")
        self.assertFalse(self.page.evaluate("!!window.__cvsSearchHook"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
