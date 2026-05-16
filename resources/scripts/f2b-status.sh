#!/usr/bin/env bash
# fail2ban status summary — list every jail and the per-jail
# Currently/Total banned counts. Read-only; useful when triaging
# "why can't I (or user X) reach the site" tickets.
#
# Server-side script, lives alongside `unbanme.sh`. Committed here
# for history and review; the live copy is on the server.
#
# Usage: sudo ./f2b-status.sh

jails=$(sudo fail2ban-client status | grep "Jail list" | sed 's/.*Jail list:\s*//' | tr ', ' '\n' | grep -v '^$')

for jail in $jails; do
    echo "=== $jail ==="
    sudo fail2ban-client status "$jail" | grep -E "Currently banned|Total banned"
done
