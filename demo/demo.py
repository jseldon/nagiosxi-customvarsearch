# End-to-end demo of the Custom Variable Search component: drives the real XI UI,
# saves screenshots + a video to this directory.
import os
from playwright.sync_api import sync_playwright

SP = os.environ["SCRATCH"]
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell")
XI = "http://localhost:8080/nagiosxi/"
PAGE = XI + "includes/components/customvarsearch/index.php"
HERE = os.path.dirname(os.path.abspath(__file__))


def login(page, user, pw):
    page.goto(XI + "login.php")
    page.fill("#usernameBox", user)
    page.fill("#passwordBox", pw)
    page.click("#loginButton")
    page.wait_for_load_state("networkidle")
    if "showlicense" in page.url:  # first login for a new user
        page.check("#agree_license")
        page.click("#submitButton")
        page.wait_for_load_state("networkidle")


def search(frame, q, field="both", match="contains", host=""):
    frame.fill("#cvs-q", q)
    frame.select_option("#cvs-field", field)
    frame.select_option("#cvs-match", match)
    frame.fill("#cvs-host", host)
    frame.click("button[type=submit]")
    frame.wait_for_load_state("networkidle")
    frame.wait_for_timeout(1200)
    meta = frame.locator(".cvs-meta, .alert").first.inner_text().strip()
    rows = frame.locator(".cvs-table tbody tr").all_inner_texts()
    print(f"  q={q!r} field={field} match={match} host={host!r} -> {meta}")
    for r in rows:
        print("     ", " | ".join(r.split("\t")))


with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)

    # ---- nagiosadmin ----
    ctx = b.new_context(viewport={"width": 1400, "height": 800},
                        record_video_dir=HERE, record_video_size={"width": 1400, "height": 800})
    page = ctx.new_page()
    login(page, "nagiosadmin", open(f"{SP}/xi-admin-pw").read().strip())

    # Menu entry: Home > Details > Custom Variable Search (opens in the XI main frame)
    page.goto(XI + "index.php")
    page.wait_for_load_state("networkidle")
    tour = page.locator("text=End Tour")
    if tour.count():
        tour.first.click()
    page.click("#menu-home-section-details")
    page.wait_for_timeout(600)
    link = page.locator("a:has-text('Custom Variable Search')").first
    link.scroll_into_view_if_needed()
    link.click()
    page.wait_for_timeout(2000)
    frame = page.frame(name="maincontentframe") or page.main_frame
    print("admin: menu link loaded", frame.url)
    page.screenshot(path=f"{HERE}/10-menu-entry.png")

    print("admin searches:")
    search(frame, "dell")
    page.screenshot(path=f"{HERE}/11-contains-dell.png")
    search(frame, "^SN-R[0-9]+", field="value", match="regex")
    page.screenshot(path=f"{HERE}/12-regex-serial.png")
    search(frame, "VAN-DC1")
    page.screenshot(path=f"{HERE}/13-location-van-dc1.png")
    search(frame, "contract", field="name")
    page.screenshot(path=f"{HERE}/14-name-contract.png")
    search(frame, "production", match="exact", host="prod")
    page.screenshot(path=f"{HERE}/15-exact-with-hostfilter.png")
    search(frame, "[unclosed", match="regex")
    page.screenshot(path=f"{HERE}/16-bad-regex.png")
    search(frame, "example.com")
    page.screenshot(path=f"{HERE}/17-admin-all-owners.png")
    ctx.close()

    # ---- limited user: only a contact on web-prod-01 ----
    ctx = b.new_context(viewport={"width": 1400, "height": 800})
    page = ctx.new_page()
    login(page, "viewer", open(f"{SP}/xi-viewer-pw").read().strip())
    page.goto(PAGE)
    print("viewer searches:")
    search(page.main_frame, "example.com")
    page.screenshot(path=f"{HERE}/18-viewer-permissions.png")
    ctx.close()
    b.close()
