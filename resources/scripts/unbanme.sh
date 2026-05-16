#!/usr/bin/env bash
# fail2ban self-service unban — walk every active jail and `unbanip`
# the caller's address. Useful when you've locked yourself out via
# repeated failed logins / form submits and need to recover the SSH
# session you're already on, or to unban a known address by hand.
#
# Server-side script, committed here for history
# and review; the live copy is on the server.
#
# Usage: sudo SSH_IP="$SSH_CONNECTION" ./unbanme.sh
# Or:    sudo ./unbanme.sh <ip-address>

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
        echo "Run as root: sudo SSH_IP=\"\$SSH_CONNECTION\" $0"
        exit 1
fi

MY_IP="${1:-$(echo "$SSH_IP" | awk '{print $1}')}"

if [[ -z "$MY_IP" ]]; then
        echo "Usage: sudo SSH_IP=\"\$SSH_CONNECTION\" $0"
        echo "   or: sudo $0 <ip-address>"
        exit 1
fi

echo "Your IP: $MY_IP"

JAILS=$(fail2ban-client status | grep 'Jail list' | sed 's/.*://;s/,/ /g')
UNBANNED=0

for JAIL in $JAILS; do
        JAIL=$(echo "$JAIL" | xargs)
        if fail2ban-client status "$JAIL" 2>/dev/null | grep -q "$MY_IP"; then
                echo "Unbanning $MY_IP from jail: $JAIL"
                fail2ban-client set "$JAIL" unbanip "$MY_IP"
                UNBANNED=$((UNBANNED + 1))
        fi
done

if [[ $UNBANNED -eq 0 ]]; then
        echo "Your IP is not banned in any jail."
else
        echo "Unbanned from $UNBANNED jail(s)."
fi
