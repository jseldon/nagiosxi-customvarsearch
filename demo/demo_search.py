# Demo: custom variable matches in XI's global "Search Nagios XI" box (Neptune)
from xi import *
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    page = b.new_page(viewport={"width": 1400, "height": 800})
    login(page, "nagiosadmin", pw("xi-admin-pw"))
    page.goto(XI + "index.php"); page.wait_for_load_state("networkidle")
    print("hook on neptune home right after login:", page.evaluate("!!window.__cvsSearchHook"))
    # Open any classic XI page (Host Status) - its page head loads the hook into the shell
    page.goto(XI + "index.php?xiwindow=" + "/nagiosxi/includes/components/xicore/status.php%3Fshow%3Dhosts")
    page.wait_for_load_state("networkidle"); page.wait_for_timeout(1500)
    tour = page.locator("text=End Tour")
    if tour.count(): tour.first.click()
    print("hook after opening Host Status:", page.evaluate("!!window.__cvsSearchHook"))

    for i, term in enumerate(["R750", "van-dc1", "fortinet"]):
        page.click("#header-search-box")
        page.fill("#header-search-box", term)
        page.wait_for_timeout(2000)
        groups = page.evaluate("""() => Array.from(document.querySelectorAll('[cmdk-group]')).map(g =>
            (g.querySelector('[cmdk-group-heading]')||{}).innerText + ': ' +
            Array.from(g.querySelectorAll('[cmdk-item]')).map(i => i.innerText.replace(/\\s+/g,' ').trim()).join(' | '))""")
        print(f"\nsearch {term!r}:"); [print("   ", g) for g in groups]
        page.screenshot(path=f"{HERE}/2{i}-globalsearch-{term}.png")
        if i == 0:
            # Click the first custom variable result -> host detail page
            page.click("[cmdk-group]:has-text('Custom Variables') [cmdk-item] >> nth=0")
            page.wait_for_timeout(2500)
            f = page.frame(name="maincontentframe")
            print("  clicked result ->", f.url if f else page.url)
            page.screenshot(path=f"{HERE}/23-globalsearch-click-hostdetail.png")
        page.keyboard.press("Escape")
    b.close()
