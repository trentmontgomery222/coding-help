#!/usr/bin/env python3
"""Drive the WPCode Values control panel from a script.

The panel lives at <site>/?acpsupdater=<key>. It has no styling, no
JavaScript and no nonces, so nothing here has to parse HTML: every
response starts with a RESULT: line, and every write is a plain form
POST with the password in it.

    ./acps-panel.py https://example.com KEY status
    ./acps-panel.py https://example.com KEY update --password PW
    ./acps-panel.py https://example.com KEY reinstall --password PW
    ./acps-panel.py https://example.com KEY set update_enabled=1 \
        panel_links="Staging | https://staging.example.com/" --password PW

The password can also come from the ACPS_PANEL_PASSWORD environment
variable, which keeps it out of the shell history.

Only the standard library is used, so this runs anywhere Python does.
"""

import argparse
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

ACTIONS = (
    "update",
    "reinstall",
    "flush",
    "rescan",
    "reset_sitewide",
    "resume",
    "clear_log",
)

TIMEOUT = 120


def panel_url(site, key, raw=True):
    """The panel's address. `raw` asks for text/plain with no forms."""
    query = {"acpsupdater": key}
    if raw:
        query["view"] = "raw"
    return site.rstrip("/") + "/?" + urllib.parse.urlencode(query)


def request(url, fields=None):
    """GET, or POST when there are fields. Returns (status, body, verdict).

    The verdict comes from the X-WPCodeBBV-Result header, which every
    response carries whatever its format. The RESULT: first line is only
    there in the raw view, so it is the fallback, not the source.
    """
    data = urllib.parse.urlencode(fields, doseq=True).encode() if fields else None
    req = urllib.request.Request(url, data=data, headers={"User-Agent": "acps-panel/1"})
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            body = resp.read().decode("utf-8", "replace")
            return resp.status, body, verdict_of(resp.headers, body)
    except urllib.error.HTTPError as err:
        # 404 (not on the IP list, or the wrong key) and 429 (rate
        # limited) are answers, not failures to connect.
        body = err.read().decode("utf-8", "replace")
        return err.code, body, verdict_of(err.headers, body)
    except urllib.error.URLError as err:
        return 0, "could not reach the site: %s" % err.reason, ""


def verdict_of(headers, body):
    """OK / FAIL / READ / RATE_LIMITED / ERROR, or '' if absent."""
    from_header = (headers.get("X-WPCodeBBV-Result") or "").strip()
    if from_header:
        return from_header
    for line in body.splitlines():
        if line.startswith("RESULT:"):
            return line.split(":", 1)[1].strip()
    return ""


def parse_report(body):
    """The raw report as {section: {key: value}}."""
    out, section = {}, ""
    for line in body.splitlines():
        line = line.rstrip()
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1]
            out[section] = {}
        elif ":" in line and section:
            key, value = line.split(":", 1)
            out[section][key.strip()] = value.strip()
    return out


def need_password(args):
    password = args.password or os.environ.get("ACPS_PANEL_PASSWORD", "")
    if not password:
        sys.exit("A password is needed for this. Pass --password or set ACPS_PANEL_PASSWORD.")
    return password


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("site", help="https://example.com")
    parser.add_argument("key", help="the value of panel_key in the plugin's settings")
    parser.add_argument("command", help="status, get KEY, set KEY=VALUE…, or one of: " + ", ".join(ACTIONS))
    parser.add_argument("rest", nargs="*", help="KEY=VALUE pairs for set, or a key name for get")
    parser.add_argument("--password", default="", help="the panel password set in wp-admin")
    args = parser.parse_args()

    url = panel_url(args.site, args.key)

    if args.command == "status":
        status, body, verdict = request(url)
        print(body, end="")
        return 0 if verdict in ("READ", "OK") else 1

    if args.command == "get":
        if not args.rest:
            sys.exit("get needs a setting name.")
        status, body, _ = request(url)
        settings = parse_report(body).get("SETTINGS", {})
        missing = False
        for name in args.rest:
            if name in settings:
                print("%s=%s" % (name, settings[name]))
            else:
                print("%s: not a setting" % name, file=sys.stderr)
                missing = True
        return 1 if missing else 0

    if args.command == "set":
        if not args.rest:
            sys.exit("set needs at least one KEY=VALUE.")
        fields = {
            "wpcodebbv_action": "save",
            "wpcodebbv_password": need_password(args),
        }
        for pair in args.rest:
            if "=" not in pair:
                sys.exit("%r is not KEY=VALUE." % pair)
            name, value = pair.split("=", 1)
            fields["wpcodebbv_settings[%s]" % name] = value
        status, body, verdict = request(url, fields)
        print(body, end="")
        return 0 if verdict == "OK" else 1

    if args.command in ACTIONS:
        fields = {
            "wpcodebbv_action": args.command,
            "wpcodebbv_password": need_password(args),
        }
        status, body, verdict = request(url, fields)
        print(body, end="")
        # `update` hands over to the updater, which also prints its own
        # SUCCESS / FAILED line; either one saying no is a no.
        if "FAILED" in body:
            return 1
        return 0 if verdict in ("OK", "") else 1

    sys.exit("Unknown command %r. Try: status, get, set, %s" % (args.command, ", ".join(ACTIONS)))


if __name__ == "__main__":
    sys.exit(main())
