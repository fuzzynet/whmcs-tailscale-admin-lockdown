#!/usr/bin/env bash
# Idempotent re-apply of the WHMCS lockdown after upgrades/rebuilds.
set -euo pipefail
SRC="$(dirname "$0")/apache-whmcs-lockdown.conf"
DST=/etc/apache2/whmcs-lockdown.conf
VHOST=/etc/apache2/sites-available/example.com-le-ssl.conf
install -m 0644 "$SRC" "$DST"
a2enmod -q rewrite remoteip
grep -q "Include $DST" "$VHOST" || { echo "Add 'Include $DST' inside the :443 VirtualHost in $VHOST"; exit 1; }
apachectl configtest
systemctl reload apache2
for p in admin/ includes/api.php vendor/ whmcsupdates/; do
  printf '%-20s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "https://example.com/$p")"
done
echo "Expect 404 for all of the above when run from a non-allowlisted host."
