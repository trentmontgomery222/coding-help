#!/usr/bin/env python3
"""
Drive the ACPS Sitemap control panel from a script — no browser needed.

The control panel (served at  https://SITE/?acpsupdater=<key> ) is deliberately
plain HTML with predictable field names and no JavaScript, and it accepts the
password on every POST (so no cookie/CSRF dance is required). That makes it
trivial to automate.

Usage:
    export ACPS_URL="https://your-site.org/"
    export ACPS_KEY="your-access-key"          # the ?acpsupdater= value
    export ACPS_PW="your-control-panel-password"

    python3 acps-remote.py status               # print diagnostics
    python3 acps-remote.py check                # check for updates
    python3 acps-remote.py update               # install the latest version
    python3 acps-remote.py reinstall            # re-download + overwrite (repair)
    python3 acps-remote.py resume               # clear safe mode
    python3 acps-remote.py set enable_xml=1 max_per_sitemap=2000

Only the standard library is used.
"""

import os
import sys
import urllib.parse
import urllib.request

URL = os.environ.get("ACPS_URL", "").rstrip("/") + "/"
KEY = os.environ.get("ACPS_KEY", "")
PW = os.environ.get("ACPS_PW", "")
# The URL parameter name, in case it was renamed in settings to avoid a clash
# with another plugin. Defaults to "acpsupdater".
PARAM = os.environ.get("ACPS_PARAM", "acpsupdater")

ACTIONS = {
    "status": "",              # just load the dashboard
    "check": "check_update",
    "update": "force_update",
    "reinstall": "reinstall",
    "resume": "resume",
    "create-page": "create_page",
    "clear-issues": "clear_issues",
    "set": "save_settings",
}


def post(fields):
    """POST fields to the panel URL and return the response body as text."""
    endpoint = URL + "?" + urllib.parse.urlencode({PARAM: KEY})
    data = urllib.parse.urlencode(fields).encode("utf-8")
    req = urllib.request.Request(endpoint, data=data, method="POST")
    req.add_header("User-Agent", "acps-remote/1.0")
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def main():
    if not URL.strip("/") or not KEY or not PW:
        sys.exit("Set ACPS_URL, ACPS_KEY and ACPS_PW environment variables first.")

    if len(sys.argv) < 2 or sys.argv[1] not in ACTIONS:
        sys.exit("Command must be one of: " + ", ".join(ACTIONS))

    command = sys.argv[1]
    fields = {"acps_password": PW}
    action = ACTIONS[command]
    if action:
        fields["acps_action"] = action

    # `set key=value ...` edits settings (subject to the once-a-day limit).
    if command == "set":
        for pair in sys.argv[2:]:
            if "=" in pair:
                k, v = pair.split("=", 1)
                fields["s[" + k + "]"] = v

    status, body = post(fields)
    print("HTTP", status)
    print(body)


if __name__ == "__main__":
    main()
