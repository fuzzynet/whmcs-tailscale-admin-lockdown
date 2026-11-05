# Cloudflare edge hardening for a WHMCS origin

Placeholders only. `<ORIGIN_IP>` is this server's public IP (`192.0.2.10` in the rest of the guide). `<HOME_IP>` is the allowlisted home/office IP (`203.0.113.10`). `<ZONE_ID>` is the Cloudflare zone id. `$CF_API_TOKEN` comes from a secrets manager. Do not commit a real zone id, token, or address.

Public `example.com` is proxied (orange cloud). The tailnet admin path does not go through Cloudflare. See README section 14.

## DNS and TLS

- A/AAAA for `example.com`: proxied (orange cloud).
- `whmcs.example.ts.net` is Tailscale MagicDNS, not a Cloudflare record.
- SSL/TLS encryption mode: **Full (strict)**. Origin certificate must be valid for `example.com`.
- Authenticated Origin Pulls: recommended after Full (strict) works.
  https://developers.cloudflare.com/ssl/origin-configuration/authenticated-origin-pull/

## Origin firewall (port 443)

Allow 443 only from Cloudflare's published ranges. Leave port 80 open for ACME HTTP-01.

- https://www.cloudflare.com/ips-v4
- https://www.cloudflare.com/ips-v6

Re-check both URLs on a schedule. The same CIDRs go in two places: the firewall rules below, and Apache `RemoteIPTrustedProxy` in the next section. `ufw` must have `IPV6=yes` or the v6 list does nothing.

`<HOME_IP>` is not a 443 exception. Admins use the tailnet. That address is the section 4 allowlist, matched after `mod_remoteip` restores the client IP.

```bash
# Generic 443 allow comes off. Each published CIDR gets a tagged allow.
# Re-runs delete the previous cloudflare-443 rules first so they do not stack.
sudo ufw delete allow 443/tcp || true
while sudo ufw status numbered | grep -q 'cloudflare-443'; do
  n="$(sudo ufw status numbered | grep 'cloudflare-443' | tail -1 | sed -nE 's/^\[[[:space:]]*([0-9]+)\].*/\1/p')"
  if [ -z "$n" ]; then
    echo "could not parse a cloudflare-443 rule number" >&2
    break
  fi
  sudo ufw --force delete "$n"
done
while read -r cidr; do
  [ -n "$cidr" ] || continue
  sudo ufw allow from "$cidr" to any port 443 proto tcp comment 'cloudflare-443'
done < <(curl -fsS https://www.cloudflare.com/ips-v4; echo; curl -fsS https://www.cloudflare.com/ips-v6)

# HTTP-01 (Let's Encrypt) hits port 80. Leave it open.
sudo ufw allow 80/tcp comment 'acme-http-01'
```

Close 443 to the world on any provider firewall as well. Leave 80 open there too.

Check from a host that is not Cloudflare: `https://<ORIGIN_IP>/` times out or is refused. `https://example.com/status` still returns `200`.

## Apache `mod_remoteip`

WHMCS logs, fraud rules, and bans read `REMOTE_ADDR`. `CF-Connecting-IP` is the visitor address. Trust it only from the Cloudflare ranges, or a client can spoof it.

```apache
RemoteIPHeader CF-Connecting-IP
# One RemoteIPTrustedProxy per CIDR from ips-v4 and ips-v6. Same fetch as ufw.
RemoteIPTrustedProxy <CF_CIDR>
RemoteIPInternalProxy <ORIGIN_IP> 127.0.0.1
```

`RemoteIPInternalProxy` is the local reverse-proxy chain only: loopback (nginx on `127.0.0.1:8088`) and `<ORIGIN_IP>`. Dropping `127.0.0.1` makes every tailnet client look like `127.0.0.1` (README section 8).

Generator (server-local; do not commit the output). Replace the `RemoteIPHeader X-Forwarded-For` lines in `apache-whmcs-lockdown.conf` with an include of this file, in the same change as the nginx header below.

```bash
# ORIGIN_IP is <ORIGIN_IP> (192.0.2.10 in the rest of this guide).
: "${ORIGIN_IP:?set ORIGIN_IP}"
tmp="$(mktemp)"
{
  echo "# Generated $(date -u +%Y-%m-%dT%H:%M:%SZ). Refresh with the ufw cloudflare-443 rules."
  echo "RemoteIPHeader CF-Connecting-IP"
  curl -fsS https://www.cloudflare.com/ips-v4 | sed '/^$/d;/^#/d;s/^/RemoteIPTrustedProxy /'
  curl -fsS https://www.cloudflare.com/ips-v6 | sed '/^$/d;/^#/d;s/^/RemoteIPTrustedProxy /'
  echo "RemoteIPInternalProxy ${ORIGIN_IP} 127.0.0.1"
} > "$tmp"
sudo install -m 0644 "$tmp" /etc/apache2/conf-available/remoteip-cloudflare.conf
rm -f "$tmp"
# Inside the :443 VirtualHost:
#   Include /etc/apache2/conf-available/remoteip-cloudflare.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
```

## nginx hostfix: keep the tailnet client IP

`examples/nginx-tailnet-hostfix.conf` fronts `tailscale serve`. Add:

```nginx
proxy_set_header CF-Connecting-IP $http_x_forwarded_for;
```

`tailscale serve` sends the client in `X-Forwarded-For` (`100.64.0.0/10`). Once `RemoteIPHeader` is `CF-Connecting-IP`, Apache stops reading `X-Forwarded-For`. This line copies the tailnet client into the header Apache now trusts, so the `RewriteCond expr "! -R '100.64.0.0/10'"` bypass keeps matching.

**Known pitfall.** Switching `RemoteIPHeader` to `CF-Connecting-IP` without this `proxy_set_header` makes tailnet clients appear as `<ORIGIN_IP>`. `/sysadmin` and `includes/api.php` then 404 for admins on the tailnet. Ship the header and the header switch together. The Apache access log on a tailnet load must show the device's `100.x` address.

## WAF skip: gateway callbacks and the API

Server-to-server calls have no browser to solve a challenge. Exempt gateway callbacks and the WHMCS API from rate limiting, managed WAF, and Super Bot Fight Mode.

Custom rule on zone `<ZONE_ID>`, action **Skip**.

Expression (covers `/modules/gateways/callback/<gateway>.php` and the API). Leave `/sysadmin` off it:

```
(starts_with(http.request.uri.path, "/modules/gateways/callback/")) or (http.request.uri.path eq "/includes/api.php")
```

Phases to skip:

| Phase | Product |
|---|---|
| `http_ratelimit` | Rate limiting rules |
| `http_request_firewall_managed` | Managed WAF |
| `http_request_sbfm` | Super Bot Fight Mode |

Products to skip (previous-version equivalents of rate limiting and managed WAF): `rateLimit`, `waf`.

```json
{
  "description": "WHMCS gateway callbacks and API: skip rate limit, managed WAF, Super Bot Fight Mode",
  "expression": "(starts_with(http.request.uri.path, \"/modules/gateways/callback/\")) or (http.request.uri.path eq \"/includes/api.php\")",
  "action": "skip",
  "action_parameters": {
    "phases": [
      "http_ratelimit",
      "http_request_firewall_managed",
      "http_request_sbfm"
    ],
    "products": ["rateLimit", "waf"]
  }
}
```

```bash
# $CF_API_TOKEN from a secrets manager. <ZONE_ID> and <RULESET_ID> are placeholders.
# <RULESET_ID> is the zone entry point ruleset for http_request_firewall_custom.
curl "https://api.cloudflare.com/client/v4/zones/<ZONE_ID>/rulesets/<RULESET_ID>/rules" \
  --request POST \
  --header "Authorization: Bearer $CF_API_TOKEN" \
  --header "Content-Type: application/json" \
  --data @- <<'JSON'
{
  "description": "WHMCS gateway callbacks and API: skip rate limit, managed WAF, Super Bot Fight Mode",
  "expression": "(starts_with(http.request.uri.path, \"/modules/gateways/callback/\")) or (http.request.uri.path eq \"/includes/api.php\")",
  "action": "skip",
  "action_parameters": {
    "phases": [
      "http_ratelimit",
      "http_request_firewall_managed",
      "http_request_sbfm"
    ],
    "products": ["rateLimit", "waf"]
  }
}
JSON
```

Dashboard: **Security → Security rules → Create rule → Custom rules**, action **Skip**, and select those phases and products.

The skip does not open the origin. Public `includes/api.php` still returns 404 (README section 4). Callbacks stay public on purpose. Bot Fight Mode (Free plan) is outside these phases and cannot be skipped; use Super Bot Fight Mode when this exemption has to work.
