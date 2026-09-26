# Nagios XI – Custom Variable Search

A Nagios XI component that lets you find hosts by their **custom variables**
(`_SERIAL`, `_OWNER`, `_LOCATION`, `_VENDOR`, …) — something XI's built-in search can't do.

![VAN-DC1 search](demo/13-location-van-dc1.png)

## Features

- **Home → Details → Custom Variable Search** menu entry (Neptune and classic themes)
- Search the variable **name**, **value**, or **both**
- Match modes: **contains**, **exact**, **regular expression** (all case-insensitive)
- Optional **host name filter**
- Matches highlighted; each host links to its XI host detail page, with live UP/DOWN status
- **CSV** and **JSON** export of any search (`?format=csv` / `?format=json`)
- **Respects XI permissions** – non-admin users only see hosts they are authorized for
- Uses live runtime values (NDO `customvariablestatus`), including values inherited from templates
- Invalid regexes and LIKE wildcards (`%`, `_`) are handled safely

## Install

1. Download `customvarsearch.zip`
2. Nagios XI → **Admin → Manage Components → Upload a Component** → select the zip → Upload

Or copy the `customvarsearch/` directory to
`/usr/local/nagiosxi/html/includes/components/` (owned by `nagios:nagios`).

Tested on **Nagios XI 2026R2** on **Ubuntu 24.04** (PHP 8.3, MariaDB).

## JSON endpoint

Uses the logged-in XI session:

```
/nagiosxi/includes/components/customvarsearch/index.php?q=^SN-R&field=value&match=regex&format=json
```

Parameters: `q` (search text), `field` = `both|name|value`, `match` = `contains|exact|regex`,
`host` (host name substring), `format` = `html|json|csv`.

## Demo lab (Docker, Ubuntu 24.04)

`docker/Dockerfile` is an Ubuntu 24.04 image with systemd as PID 1, which the official XI
installer needs.

```bash
docker build -t nagiosxi-base:ubuntu24.04 docker/
docker run -d --name nagiosxi --hostname nagiosxi --privileged --cgroupns=host \
  -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
  -p 8080:80 -p 8443:443 nagiosxi-base:ubuntu24.04
docker exec nagiosxi bash -c 'cd /tmp && wget -q https://assets.nagios.com/downloads/nagiosxi/xi-latest.tar.gz \
  && tar xzf xi-latest.tar.gz && cd nagiosxi && ./fullinstall -n'
```

Then browse to `http://<host>:8080/nagiosxi/` and complete the 6-step setup wizard (requires
registering a free Home Lab or trial license), upload the component, and seed demo hosts:

```bash
XI_URL=http://localhost:8080 XI_KEY=<your api key> ./seed-demo-hosts.sh
```

`demo/demo.py` is the headless Playwright script that produced the screenshots and
`demo/customvarsearch-demo.webm`.

## Demo results

| Search | Result |
|---|---|
| `dell` (contains, name or value) | app-stage-01, db-prod-01 (`_VENDOR`) |
| `^SN-R[0-9]+` (regex, value only) | app-stage-01, db-prod-01 (`_SERIAL`) |
| `VAN-DC1` | db-prod-01, fw-edge-01 (DOWN), web-prod-01 (`_LOCATION`) |
| `contract` (variable name only) | fw-edge-01 `_SUPPORT_CONTRACT` |
| `production` (exact) + host filter `prod` | db-prod-01, web-prod-01 |
| `[unclosed` (regex) | "Invalid regular expression." |
| `example.com` as **nagiosadmin** | all 4 hosts |
| `example.com` as limited user **viewer** | only web-prod-01 (the one host they're a contact for) |
