# Shared helpers for the demo scripts
import os
from playwright.sync_api import sync_playwright
SP = os.environ["SCRATCH"]
CHROME = os.path.expanduser("~/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell")
XI = "http://localhost:8080/nagiosxi/"
HERE = os.path.dirname(os.path.abspath(__file__))

def pw(name):
    return open(f"{SP}/{name}").read().strip()

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

def install_component(page, zip_path):
    page.goto(XI + "admin/components.php")
    page.set_input_files("input[name=uploadedfile]", zip_path)
    page.click("form[enctype] button[type=submit]")
    page.wait_for_load_state("networkidle")
    return page.locator(".message, .alert, #message").first.inner_text().strip()
