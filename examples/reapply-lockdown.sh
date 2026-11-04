#!/usr/bin/env bash
# Idempotent re-apply of the WHMCS lockdown after upgrades or panel rebuilds.
# Installs the Apache lock rules and the nginx Host-rewrite proxy, checks
# that tailscale serve targets the nginx loopback, and confirms /status
# stays public. Run as root from this directory, or via an absolute path.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"

APACHE_SRC="$ROOT/apache-whmcs-lockdown.conf"
APACHE_DST=/etc/apache2/whmcs-lockdown.conf
VHOST=/etc/apache2/sites-available/example.com-le-ssl.conf

install -m 0644 "$APACHE_SRC" "$APACHE_DST"
a2enmod -q rewrite remoteip
grep -q "Include $APACHE_DST" "$VHOST" || { echo "Add 'Include $APACHE_DST' inside the :443 VirtualHost in $VHOST"; exit 1; }
apachectl configtest
systemctl reload apache2

NGINX_SRC="$ROOT/nginx-tailnet-hostfix.conf"
NGINX_DST=/etc/nginx/conf.d/nginx-tailnet-hostfix.conf
install -m 0644 "$NGINX_SRC" "$NGINX_DST"
rm -f /etc/nginx/sites-enabled/default
nginx -t
if systemctl is-active --quiet nginx; then
  systemctl reload nginx
else
  systemctl enable --now nginx
fi

if ! tailscale serve status | grep -F 'http://127.0.0.1:8088' >/dev/null; then
  echo "tailscale serve is not pointed at http://127.0.0.1:8088" >&2
  tailscale serve status >&2 || true
  echo "Fix: sudo tailscale serve --bg --https=8443 http://127.0.0.1:8088" >&2
  exit 1
fi

for p in sysadmin/ includes/api.php vendor/ whmcsupdates/; do
  printf '%-22s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "https://example.com/$p")"
done
status_code="$(curl -s -o /dev/null -w '%{http_code}' 'https://example.com/status' || true)"
printf '%-22s %s\n' "status" "$status_code"
echo "Expect 404 for sysadmin/, includes/api.php, vendor/, and whmcsupdates/ when run from a non-allowlisted host."
echo "/status is intentionally public and must return 200."
if [ "$status_code" != "200" ]; then
  echo "/status returned ${status_code}, expected 200" >&2
  exit 1
fi
