#!/bin/bash
#
# Extra fixtures for tests/integration/test_live_xi.py (run after ../../seed-demo-hosts.sh).
#
#   sec-test-01    secrets, a spreadsheet formula and non-ASCII text (its _HTMLTEST is
#                  stripped to empty by the XI API - see xss-test-01)
#   tmpl-child-01  gets _DATACENTER only by inheriting it from a host template
#   xss-test-01    an HTML/script payload in a custom variable (via CCM import, because
#                  the XI API strips tags from input but hand-written configs don't)
#   viewer         non-admin user who is a contact for web-prod-01 only
#   classicuser    user for the classic (Modern) theme test; the test sets the theme
#
# Usage: XI_URL=http://localhost:8080 XI_KEY=<admin api key> VIEWER_PASS=<password> \
#            CLASSIC_PASS=<password> CONTAINER=nagiosxi ./seed-test-hosts.sh
# CONTAINER is the Docker container running XI; the host template and xss-test-01 are
# dropped into XI's CCM import directory there (the v1 API has no host template endpoint).
#
set -euo pipefail
: "${XI_URL:?set XI_URL}" "${XI_KEY:?set XI_KEY}" "${VIEWER_PASS:?set VIEWER_PASS}" "${CLASSIC_PASS:?set CLASSIC_PASS}"
CONTAINER=${CONTAINER:-nagiosxi}
API="$XI_URL/nagiosxi/api/v1"
HOST_DEFAULTS=(-d "use=xiwizard_generic_host" -d "max_check_attempts=3" -d "check_period=24x7"
               -d "notification_period=24x7" -d "contacts=nagiosadmin" -d "check_command=check-host-alive" -d "force=1")

echo -n "sec-test-01: "
curl -s -XPOST "$API/config/host?apikey=$XI_KEY" -d host_name=sec-test-01 -d address=127.0.0.1 \
  -d alias="Security test host" "${HOST_DEFAULTS[@]}" \
  --data-urlencode "_SNMP_COMMUNITY=s3cr3t-community" \
  --data-urlencode "_DB_PASSWORD=hunter2-pw" \
  --data-urlencode "_API_TOKEN=tok-abc123" \
  --data-urlencode '_NOTE==HYPERLINK("http://evil.example","click")' \
  --data-urlencode '_HTMLTEST=<img src=x onerror=alert(1)>' \
  --data-urlencode "_CITY=MÜNCHEN" \
  --data-urlencode "_OWNER=sec-team@example.com"; echo

echo "host template cvs-dc-template + xss-test-01 (CCM import)"
docker exec -i "$CONTAINER" bash -c 'cat > /usr/local/nagios/etc/import/cvs-test-fixtures.cfg && chown nagios:nagios /usr/local/nagios/etc/import/cvs-test-fixtures.cfg' <<'EOF'
define host {
    name            cvs-dc-template
    use             xiwizard_generic_host
    _DATACENTER     TEMPLATE-DC-99
    register        0
}

define host {
    host_name       xss-test-01
    alias           XSS test host
    address         127.0.0.1
    use             xiwizard_generic_host
    max_check_attempts 3
    check_period    24x7
    notification_period 24x7
    contacts        nagiosadmin
    check_command   check-host-alive
    _HTMLTEST       <img src=x onerror=alert(1)>"'&
}
EOF
curl -s -XPOST "$API/system/applyconfig?apikey=$XI_KEY"; echo
sleep 30  # let the import + apply finish before referencing the template

echo -n "tmpl-child-01: "
curl -s -XPOST "$API/config/host?apikey=$XI_KEY" -d host_name=tmpl-child-01 -d address=127.0.0.1 \
  "${HOST_DEFAULTS[@]/xiwizard_generic_host/cvs-dc-template}"; echo

echo -n "viewer user: "
curl -s -XPOST "$API/system/user?apikey=$XI_KEY" -d username=viewer --data-urlencode "password=$VIEWER_PASS" \
  -d name="Web Team Viewer" -d email=viewer@localhost -d auth_level=user -d force_pw_change=0 \
  -d email_info=0 -d monitoring_contact=1 -d can_see_all_hs=0; echo
echo -n "classicuser: "
curl -s -XPOST "$API/system/user?apikey=$XI_KEY" -d username=classicuser --data-urlencode "password=$CLASSIC_PASS" \
  -d name="Classic Theme Tester" -d email=classic@localhost -d auth_level=user -d force_pw_change=0 \
  -d email_info=0 -d monitoring_contact=0 -d can_see_all_hs=1; echo
echo -n "web-prod-01 contacts: "
curl -s -XPUT "$API/config/host/web-prod-01?apikey=$XI_KEY&contacts=nagiosadmin,viewer"; echo

echo -n "applyconfig: "
curl -s -XPOST "$API/system/applyconfig?apikey=$XI_KEY"; echo
