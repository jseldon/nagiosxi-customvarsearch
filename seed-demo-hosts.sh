#!/bin/bash
# Create demo hosts with custom variables via the Nagios XI REST API, then apply config.
# Usage: XI_URL=http://localhost:8080 XI_KEY=<api key> ./seed-demo-hosts.sh
set -euo pipefail
: "${XI_URL:?set XI_URL}" "${XI_KEY:?set XI_KEY}"
API="$XI_URL/nagiosxi/api/v1"

add_host() {  # name address alias then _VAR=value pairs
  local name=$1 addr=$2 alias=$3; shift 3
  local args=(-d "host_name=$name" -d "address=$addr" -d "alias=$alias"
              -d "use=xiwizard_generic_host" -d "max_check_attempts=3"
              -d "check_period=24x7" -d "notification_period=24x7"
              -d "contacts=nagiosadmin" -d "check_command=check-host-alive" -d "force=1")
  for kv in "$@"; do args+=(--data-urlencode "$kv"); done
  echo -n "$name: "; curl -s -XPOST "$API/config/host?apikey=$XI_KEY" "${args[@]}"; echo
}

add_host web-prod-01  127.0.0.1  "Production web server" \
  _SERIAL=SN-DL380-7731 _OWNER=web-team@example.com _LOCATION=VAN-DC1-RACK-A3 _VENDOR=HPE _ENVIRONMENT=production
add_host db-prod-01   127.0.0.1  "Primary MariaDB" \
  _SERIAL=SN-R750-2210 _OWNER=dba-team@example.com _LOCATION=VAN-DC1-RACK-B7 _VENDOR=Dell _ENVIRONMENT=production _BACKUP_POLICY=nightly-30d
add_host app-stage-01 127.0.0.1  "Staging app server" \
  _SERIAL=SN-R650-9001 _OWNER=app-team@example.com _LOCATION=TOR-DC2-RACK-C1 _VENDOR=Dell _ENVIRONMENT=staging
add_host fw-edge-01   192.0.2.1  "Edge firewall (unreachable on purpose)" \
  _SERIAL=FGT60F-3344 _OWNER=netops@example.com _LOCATION=VAN-DC1-RACK-A1 _VENDOR=Fortinet _ENVIRONMENT=production _SUPPORT_CONTRACT=FTNT-2027-0042

echo -n "applyconfig: "; curl -s -XPOST "$API/system/applyconfig?apikey=$XI_KEY"; echo
