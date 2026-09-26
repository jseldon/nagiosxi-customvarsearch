# Upload customvarsearch.zip through Admin > Manage Components, as an admin would
from xi import *
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    page = b.new_page()
    login(page, "nagiosadmin", pw("xi-admin-pw"))
    print(install_component(page, os.path.join(HERE, "..", "customvarsearch.zip")))
    b.close()
