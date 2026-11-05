# Protecting a WHMCS Admin Area with Tailscale

A practical, upgrade-safe pattern for taking the WHMCS admin area, API, and SSH off the public internet using Tailscale. It is written from a real production setup; all names, paths, and addresses here are examples.

| Placeholder | Meaning |
|---|---|
| `example.com` | Your public WHMCS hostname |
| `whmcs.example.ts.net` | The server's Tailscale (MagicDNS) name |
| `/sysadmin` | Your (renamed) WHMCS admin directory |
| `192.0.2.10` | This server's public IP |
| `203.0.113.10` | An allowlisted static/home IP (documentation range) |
| `100.64.0.0/10` | Tailscale CGNAT range |
| `<ZONE_ID>` | Cloudflare zone ID |
| `<ORIGIN_IP>` | Origin public IP. Other sections write this as `192.0.2.10` |
| `<HOME_IP>` | Allowlisted static/home IP. Other sections write this as `203.0.113.10` |
| `$CF_API_TOKEN` | Cloudflare API token (secrets manager; never git) |

> Assumes Apache 2.4 + PHP-FPM on Ubuntu. Nginx equivalents are noted where they differ.

---

## Table of Contents

- [1. Goals](#1-goals)
- [2. Tailscale Serve in front of the admin](#2-tailscale-serve-in-front-of-the-admin)
- [3. Lock the admin directory to tailnet-only (upgrade-safe)](#3-lock-the-admin-directory-to-tailnet-only-upgrade-safe)
- [4. Close the public API, keep it for tailnet + allowlist](#4-close-the-public-api-keep-it-for-tailnet--allowlist)
- [5. Lock /vendor and /whmcsupdates](#5-lock-vendor-and-whmcsupdates)
- [6. SSH over Tailscale only](#6-ssh-over-tailscale-only)
- [7. An exit node so automation comes from an allowlisted IP](#7-an-exit-node-so-automation-comes-from-an-allowlisted-ip)
- [8. Pitfalls we hit](#8-pitfalls-we-hit)
- [9. Licensing after an upgrade + Tailscale lock (lessons from 2026-11-03)](#9-licensing-after-an-upgrade--tailscale-lock-lessons-from-2026-11-03)
- [10. Gotcha: moving the admin hostname breaks existing passkeys (WebAuthn)](#10-gotcha-moving-the-admin-hostname-breaks-existing-passkeys-webauthn)
- [11. Gotcha: the WHMCS license goes Invalid when admin is reached via the Tailscale hostname](#11-gotcha-the-whmcs-license-goes-invalid-when-admin-is-reached-via-the-tailscale-hostname)
  - [11.1 Permanent fix (deployed): force the real Host with a local nginx](#111-permanent-fix-deployed-force-the-real-host-with-a-local-nginx)
  - [11.2 How to verify](#112-how-to-verify)
- [12. Checklist after any change](#12-checklist-after-any-change)
- [13. Planned](#13-planned)
- [14. Cloudflare edge hardening](#14-cloudflare-edge-hardening)
- [Credits](#credits)
- [License](#license)

## 1. Goals

- Admin area reachable **only** over the tailnet.
- `includes/api.php` closed to the public, still usable from the tailnet and from allowlisted IPs.
- `/vendor` and `/whmcsupdates` closed to the public (tailnet clients in `100.64.0.0/10` bypass).
- SSH only over Tailscale; public port 22 closed.
- Every change survives WHMCS upgrades and can be re-applied in one step.
- Licensing keeps working (see section 9 — read it **before** you change hostnames).
- Public HTTP(S) enters through Cloudflare. Origin port 443 accepts Cloudflare's ranges only, and WHMCS still sees the visitor IP (section 14).

## 2. Tailscale Serve in front of the admin

Join the server to your tailnet. The current chain (section 11.1) is `tailscale serve` (HTTPS on `:8443`, tailnet-only) → nginx on `127.0.0.1:8088` → Apache `https://127.0.0.1:443`. nginx forces `Host: example.com` and forwards the real client IP, so license checks always see the licensed domain.

```bash
sudo tailscale up --ssh=false
sudo tailscale serve --bg --https=8443 http://127.0.0.1:8088
tailscale serve status
```

Admins use `https://whmcs.example.ts.net:8443/sysadmin/`. A valid cert is issued automatically for the `ts.net` name. Do **not** use `tailscale funnel` — that would make it public again.

The nginx server block is [`examples/nginx-tailnet-hostfix.conf`](examples/nginx-tailnet-hostfix.conf), installed as `/etc/nginx/conf.d/nginx-tailnet-hostfix.conf`. Section 11.1 has the deploy steps and the rollback to the earlier `https+insecure://192.0.2.10:443` target.

## 3. Lock the admin directory to tailnet-only (upgrade-safe)

Don't edit files that WHMCS ships (they are overwritten on upgrade). Put the rule in the Apache vhost/conf, which upgrades never touch. See [`examples/apache-whmcs-lockdown.conf`](examples/apache-whmcs-lockdown.conf).

Key points:
- Keep the rules in a file `Include`d from the :443 vhost. nginx forces `Host: example.com` (section 11.1), so the `/sysadmin` lock matches the tailnet range `100.64.0.0/10`.
- Allow that tailnet range and your allowlisted IP. Answer everyone else with a real HTTP 404 (via `mod_rewrite`, not `Require`, which would give 403) so the path isn't advertised.
- `RemoteIPInternalProxy` must list this server's IP **and** `127.0.0.1`. The nginx hop is loopback; omitting `127.0.0.1` makes every tailnet client appear as `127.0.0.1`, and a login ban on that address locks everyone out (section 8).
- On a Cloudflare-proxied origin, `RemoteIPHeader` is `CF-Connecting-IP` and `RemoteIPTrustedProxy` is Cloudflare's published ranges (section 14). Switch that header together with the nginx `CF-Connecting-IP` line, or tailnet clients show up as the origin IP and this lock misses.
- The same tailnet bypass (`RewriteCond expr "! -R '100.64.0.0/10'"`) sits in front of the `/includes/api.php` rule and the `/vendor|whmcsupdates` rule.
- Keep both confs plus [`examples/reapply-lockdown.sh`](examples/reapply-lockdown.sh) next to your upgrade notes.

**Re-apply after any upgrade or control-panel rebuild:**
1. `sudo ./reapply-lockdown.sh` (idempotent: installs the Apache and nginx confs, runs `apachectl configtest` and `nginx -t`, reloads, and checks that `tailscale serve` targets `http://127.0.0.1:8088`)
2. Verify from **outside** the tailnet: `curl -s -o /dev/null -w '%{http_code}\n' https://example.com/sysadmin/` → `404`
3. Verify from the tailnet: `https://whmcs.example.ts.net:8443/sysadmin/` → login page
4. Confirm `https://example.com/status` still returns `200` (intentionally public)

## 4. Close the public API, keep it for tailnet + allowlist

Same conf file: a `RewriteCond expr "! -R '100.64.0.0/10'"` lets tailnet clients through, then `includes/api.php` returns 404 to everyone except loopback, this server, and your allowlisted IP(s).

Then in WHMCS → *System Settings → General → Security → API IP Access Restriction*, list the same allowlisted IP(s). Two layers: the web server blocks strangers before PHP runs; WHMCS still checks credentials and IP.

Verify:
```bash
# public, not allowlisted -> 404
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/includes/api.php
# from an allowlisted IP -> JSON result=success
curl -s https://example.com/includes/api.php \
  -d action=GetHealthStatus -d identifier="$ID" -d secret="$SECRET" -d responsetype=json
```
Never put the identifier/secret on the command line in shared logs; read them from a secrets manager.

> Exception to plan for: payment gateway callbacks (e.g. `modules/gateways/callback/*`) must stay public. Only lock `api.php`. Those callback paths, and `includes/api.php`, also need a Cloudflare skip so rate limiting, managed WAF, and Super Bot Fight Mode do not challenge the server-to-server call (section 14).

## 5. Lock `/vendor` and `/whmcsupdates`

Neither needs to be reachable from the public internet. The example conf returns 404 for both, after a `100.64.0.0/10` bypass so a tailnet admin can still open them. Leave public-facing pages alone: `/status` (intentionally public; it should keep returning 200), the cart, the client area, and gateway callbacks. Re-test checkout after the change.

## 6. SSH over Tailscale only

1. Confirm you can SSH via the tailnet name first: `ssh admin@whmcs.example.ts.net`.
2. Bind `sshd` to the Tailscale address, or keep it listening and firewall port 22 to `tailscale0` only:
   ```bash
   sudo ufw allow in on tailscale0 to any port 22 proto tcp
   sudo ufw delete allow 22/tcp
   ```
3. Also close 22 in any provider/cloud firewall.

**Verify-then-rollback habit:** before any change that could lock you out, schedule an automatic rollback, then cancel it only after verifying a *new* session works:
```bash
echo 'ufw allow 22/tcp' | sudo at now + 10 minutes   # safety net
# ...apply change, open a NEW ssh session over the tailnet, confirm...
sudo atrm <job-id>                                     # cancel rollback
```
Keep your existing session open the whole time. Have the provider's web console as last resort.

## 7. An exit node so automation comes from an allowlisted IP

Automation hosts (bots, dashboards, CI) usually sit on cloud IPs you don't want to allowlist. Route them through a tailnet **exit node** at a location with a stable IP (home/office router or small server), and allowlist that single IP.

```bash
# on the exit node
sudo tailscale up --advertise-exit-node
# on the automation host (approve the exit node in the admin console first)
sudo tailscale up --exit-node=<exit-node-name> --exit-node-allow-lan-access
curl -s https://ifconfig.me    # should print the allowlisted IP
```
Gotchas:
- If the exit node stops advertising, traffic **silently** falls back to the host's own egress and WHMCS answers `Invalid IP`. Check egress IP before API calls and keep a second exit node with the same public IP as backup.
- Userspace Tailscale (containers) exposes a SOCKS5/HTTP proxy instead; point the client at it explicitly.

## 8. Pitfalls we hit

- **Self-inflicted login ban on 127.0.0.1.** If Tailscale Serve proxies from loopback without real-IP handling, every admin looks like `127.0.0.1` to WHMCS. A few failed logins (or a script with a blank API secret) bans `127.0.0.1` and locks out *everyone*. Fix: remove the entry under *Utilities → Logs → Banned IPs* (or the `tblbannedips` table) and make sure WHMCS sees the real client IP — configure trusted proxies (`$trusted_proxies` / X-Forwarded-For handling) and Apache `mod_remoteip`, with `127.0.0.1` listed on `RemoteIPInternalProxy` (section 3).
- **Empty API secrets ban your allowlisted IP.** Wrap API calls in a helper that refuses to send if identifier/secret is empty.
- **Upgrades don't remove vhost rules, but panel rebuilds can.** Always run the re-apply script and the outside/inside checks after maintenance.
- **Gateways and webhooks.** Some payment processors refuse to call private/CGNAT addresses; keep their callbacks on public HTTPS. Exempt those paths (and the API) from Cloudflare rate limiting, managed WAF, and Super Bot Fight Mode (section 14).
- **`CF-Connecting-IP` without the hostfix header.** Switching `RemoteIPHeader` to `CF-Connecting-IP` without `proxy_set_header CF-Connecting-IP $http_x_forwarded_for;` makes tailnet clients appear as the origin IP. The `100.64.0.0/10` bypass misses, and the admin/API lock 404s them. Fix is in section 14.
- **Licensing** — see next section. This was our biggest outage.

## 9. Licensing after an upgrade + Tailscale lock (lessons from 2026-11-03)

After the command-line upgrade to WHMCS 8.13.7, with the admin already behind Tailscale Serve, the core license and add-on license checks failed. The timeline below comes from the server's sudo log, web access log, WHMCS activity log and backup timestamps. All times are MST.

**How it works.** The core license keeps a signed *local key* in the `License` row of `tblconfiguration`. Many commercial add-ons keep a `licensekey` plus a cached `localkey`/`licensestatus` in `tbladdonmodules`. A remote check records the hostname, IP and directory it sees, and the license is bound to them.

**What actually happened**
| Time | Event |
|---|---|
| 10:37 | Upgrade run from the command line (`install/bin/installer.php -u`). |
| 10:38–10:42 | **Command-line core license checks**: a small diagnostic script forced the official `validate()` remote check several times, outside any web request. |
| 10:39 | First web load of the admin after the upgrade came through Serve from **loopback** and showed `licenseerror.php`. |
| 10:57 | Admin login worked again over the tailnet. |
| 12:37–12:40 | Every add-on's settings page was rendered from the command line to read its license status. |
| 12:54–13:01 | Licence keys for four add-ons from one vendor were re-saved **from the command line** (and repeated once), after a table backup. |
| 13:08–13:10 | Add-on settings opened and saved through the real admin web page (*System Settings → Addon Modules*), with setting checksums taken before and after. |
| 13:20–13:26 | An admin access rule was changed and the admin was signed in to from the allowlisted IP: **second `licenseerror.php`**. |
| 13:57–14:00 | Core `License` row and `tbladdonmodules` backed up. Checksums show one add-on had pulled a fresh `localkey`. |
| 14:03 | Serve repointed from loopback to the server's public IP, with `UseCanonicalName On` and `mod_remoteip` trusting only the server itself. |
| 14:07 | Core local key refreshed by a normal web load. No further license errors that day. |

**Takeaways**
- Both license errors followed checks made outside a normal public-hostname web request: command-line `validate()` calls and command-line add-on saves, plus loads through Serve from loopback. Treat command-line license checks as harmful right after an upgrade or reissue.
- What ended the errors was giving WHMCS one consistent identity: Serve proxied to the **public IP**, a canonical hostname, and real client IPs only from the server itself. After that, a normal web load refreshed the core key.
- Re-save add-on license keys **in the admin web page**, not from the command line. Check settings checksums before and after, so you can show that only the cached `localkey`/token rows changed.

**Recommended order next time**
1. Back up the `License` row, `tbladdonmodules` and the admin row you'll use, and take settings checksums.
2. Set up the Serve target, `UseCanonicalName` and `mod_remoteip` **before** the upgrade or reissue.
3. If the license needs it, reissue in the vendor client area. Then do the first load from the real public hostname (`https://example.com/`), with no command-line checks and no tailnet-first load.
4. Re-save each add-on key in the admin web page. Ask the vendor's support for anything that still fails.
5. Confirm the core status is *Active*, each add-on shows a valid license, and the error log is clean.

**Rules:** don't roll back over a license problem, even after downtime; fix forward. Run only official, licensed builds, keep keys in a password manager, and diff every upgrade against the vendor's clean release.

## 10. Gotcha: moving the admin hostname breaks existing passkeys (WebAuthn)

WebAuthn passkeys are bound to the origin hostname: the relying-party ID is the hostname in the browser's address bar. A credential registered at one hostname will not work at another.

So if admins registered passkeys while the admin area was served at `example.com` and you then move it behind `whmcs.example.ts.net`, those admin passkeys stop working. The browser reports that no credential was found (or a `NotAllowedError`), even though the account and the key are fine.

- **Only the surface whose hostname changed is affected.** Client-area passkeys keep working as long as the client area stays on `example.com`.
- **Fix:** each affected user signs in at the **new** hostname with password + their other 2FA (e.g. TOTP), deletes the old passkey and registers a new one. No server-side setting rebinds an existing passkey to a new hostname.
- **Plan for it:** before moving an admin or client surface to a new hostname, list who has passkeys and warn them they'll need to re-enroll. Keep a non-passkey login path (password + TOTP) available so nobody is locked out during the switch.

## 11. Gotcha: the WHMCS license goes Invalid when admin is reached via the Tailscale hostname

**Symptom.** After locking admin behind `tailscale serve` at `whmcs.example.ts.net`, admin intermittently redirects every page to `licenseerror.php?status=Invalid`, and each request hangs ~15–20 s (a live, failing call to the WHMCS license server). The public site on `example.com` keeps serving normally; it's admin-only.

**Root cause.** On a remote recheck, WHMCS reports the HTTP `Host` of the incoming request to its license server. The license is bound to the real domain (`example.com`) + server IP (`192.0.2.10`). `tailscale serve` passes the tailnet hostname through as `Host`, so when admin is loaded via `whmcs.example.ts.net` (or the raw IP, or `localhost`) the recheck reports a hostname that isn't in the license's allowed domains. The license server returns Invalid, WHMCS caches it and locks the admin area. Local cached checks keep passing for hours, so it looks fine until the next periodic or forced remote recheck fires from the wrong hostname.

**Immediate fix (no rollback, no nulled files).**

1. In the WHMCS.com client area, open the license and click **Reissue License** (sidebar, "Moving or Reinstalling?"). This resets the bound install location; it's applied on next access.
2. Immediately force a clean validation that reports the **correct** domain, before anything touches the Tailscale hostname. On the server:
   ```bash
   for i in 1 2 3; do
     curl -sk -o /dev/null -w '%{http_code} %{time_total}\n' \
       -H "Host: example.com" https://127.0.0.1/clientarea.php
   done
   ```
   Do **not** first-load via `whmcs.example.ts.net`, the raw IP, `localhost` as itself, or a CLI license check: those report the wrong hostname and re-break it.
3. Verify: `clientarea.php` returns `200` (not a `302` to `licenseerror.php`) and responds in ~2 s, not ~19 s.

**Why it recurs / durable fix.** Every admin load via the Tailscale hostname risks re-poisoning the license on the next remote recheck. The durable fix is to make the proxy chain present the real `Host` to WHMCS. See 11.1 (deployed) and 11.2 (verification). Fallback: ask WHMCS licensing support to add the tailnet hostname as an allowed domain. Until a fix is in place, if admin shows a license error, re-run the reissue + clean-`Host` load above.

**Diagnosis tips.**

- Apache access log: find the first `licenseerror.php` redirect and which client IP / hostname triggered it.
- `tblconfiguration` rows `License` and `LicenseRemoteChecks`: remote checks ~15 s apart match the page hang.
- Confirm the key in `/var/www/example.com/public_html/configuration.php` is the official `WHMCS-xxxx` key (not nulled), and that the client-area License page shows the correct bound domains/IP.

### 11.1 Permanent fix (deployed): force the real `Host` with a local nginx

Deployed and verified in production on 2026-11-03. Note: `UseCanonicalName On` + `ServerName example.com` on the vhost does **not** fix this. It was already in place and the license still went Invalid, which shows WHMCS keys off the request's `Host` header, not `SERVER_NAME`.

**Chain:** `tailscale serve` (https `:8443`, tailnet-only) → nginx on `127.0.0.1:8088` → Apache `https://127.0.0.1:443`. nginx forces `Host: example.com` and passes the real tailnet client IP through, so WHMCS only ever sees the licensed domain. Every piece lives outside the WHMCS files, so it survives upgrades.

1. Install nginx and drop in the server block (also remove `/etc/nginx/sites-enabled/default`):

```bash
sudo apt install nginx-light
sudo rm /etc/nginx/sites-enabled/default
```

`/etc/nginx/conf.d/nginx-tailnet-hostfix.conf` (repo copy: [`examples/nginx-tailnet-hostfix.conf`](examples/nginx-tailnet-hostfix.conf)):

```nginx
# tailscale serve (:8443 tailnet) -> nginx 127.0.0.1:8088 -> Apache https 127.0.0.1:443 with Host example.com
server {
    listen 127.0.0.1:8088;
    server_name _;
    client_max_body_size 64m;
    proxy_read_timeout 300s;
    location / {
        proxy_pass https://127.0.0.1:443;
        proxy_ssl_server_name on;
        proxy_ssl_name example.com;
        proxy_ssl_verify off;
        proxy_set_header Host example.com;
        proxy_set_header X-Forwarded-Host example.com;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Real-IP $http_x_forwarded_for;
        proxy_set_header CF-Connecting-IP $http_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_redirect https://example.com/ /;
        proxy_redirect http://example.com/ /;
    }
}
```

```bash
sudo nginx -t && sudo systemctl enable --now nginx
```

2. Apache vhost (back up first: `cp example.com-le-ssl.conf example.com-le-ssl.conf.bak-<date>`).

Trust the nginx loopback so `mod_remoteip` still sets `REMOTE_ADDR` to the real `100.x` tailnet client. The section 3 `/sysadmin` rule (`-R '100.64.0.0/10'`) then keeps working unchanged. When section 14 switches `RemoteIPHeader` to `CF-Connecting-IP`, the `CF-Connecting-IP` line in the nginx block is what keeps that `100.x` address; without it the client shows up as the origin IP:

```apache
RemoteIPInternalProxy 192.0.2.10 127.0.0.1
```

The `/vendor`, `/whmcsupdates` (section 5) and `/includes/api.php` (section 4) locks were keyed on `Host == whmcs.example.ts.net`, which Apache now sees as `example.com`. Add a tailnet-source bypass **before** the Host condition in each block:

```apache
RewriteCond expr "! -R '100.64.0.0/10'"
RewriteCond %{HTTP_HOST} !^whmcs\.example\.ts\.net(:443|:8443)?$ [NC]
# ...existing conditions / RewriteRule for that block unchanged
```

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
```

3. Repoint serve at nginx (save the old config first: `tailscale serve status --json > serve-before.json`):

```bash
sudo tailscale serve --bg --https=8443 http://127.0.0.1:8088
```

**Revert (under a minute):**

```bash
sudo tailscale serve --bg --https=8443 https+insecure://192.0.2.10:443
sudo cp -a /etc/apache2/sites-available/example.com-le-ssl.conf.bak-<date> /etc/apache2/sites-available/example.com-le-ssl.conf
sudo apache2ctl configtest && sudo systemctl reload apache2
sudo rm /etc/nginx/conf.d/nginx-tailnet-hostfix.conf && sudo systemctl disable --now nginx
```

### 11.2 How to verify

1. **Smoke test on the nginx loopback** with a simulated tailnet client:
   ```bash
   curl -s -o /dev/null -w '%{http_code} %{redirect_url} %{time_total}\n' \
     -H "X-Forwarded-For: 100.100.100.100" http://127.0.0.1:8088/clientarea.php
   ```
   Expect `302` → `/login` in ~0.6 s, **not** `licenseerror.php`. `/sysadmin/` with that header gives `302` → `login.php`. Without the header it gives `404`, because the tailnet lock still holds.
2. **Real tailnet load:** open `https://whmcs.example.ts.net:8443/sysadmin/` from a tailnet device. The Apache access log should show the device's real `100.x` IP, and the request should arrive as `Host: example.com`, never the `.ts.net` name.
3. **Public still blocked:** from off-tailnet, `https://example.com/sysadmin/`, `/includes/api.php`, `/vendor/` and `/whmcsupdates/` should return 404/403. A CDN edge 403 also counts.
4. **License valid (on-server):**
   ```bash
   curl -sk -o /dev/null -w '%{http_code} %{time_total}\n' \
     -H "Host: example.com" https://127.0.0.1/clientarea.php
   ```
   Valid: `302`/`200` in ~2 s or less. Invalid: ~19 s, then a redirect to `licenseerror.php`.
5. **The real proof is surviving a remote license recheck.** WHMCS rechecks against its license server periodically, so watch the Apache access log for any `licenseerror` over the next 24–48 h. A scheduled `grep -c licenseerror` every few hours works. To prove it right away, load admin **via the tailnet hostname** and use **Help → License Information → Force Remote Check**, then confirm the status stays **Active**.
6. **`/status` stays public.** `curl -s -o /dev/null -w '%{http_code}\n' https://example.com/status` returns `200`. No lock rule covers it.

## 12. Checklist after any change

- [ ] `nginx -t` passes
- [ ] `tailscale serve status` shows the `:8443` handler proxying to `http://127.0.0.1:8088`
- [ ] `https://example.com/sysadmin/` → 404 from outside
- [ ] `https://whmcs.example.ts.net:8443/sysadmin/` → login page
- [ ] Public `includes/api.php` → 404; allowlisted API call → success
- [ ] `/vendor`, `/whmcsupdates` → 404 from outside the tailnet
- [ ] `/status` → 200 (intentionally public)
- [ ] Checkout + gateway callback test passes
- [ ] Origin 443 accepts Cloudflare ranges only; port 80 still open for HTTP-01 (section 14)
- [ ] After the `CF-Connecting-IP` switch, a tailnet admin still logs as `100.x`, not `<ORIGIN_IP>`
- [ ] Gateway callback and API paths are not challenged at the Cloudflare edge (section 14)
- [ ] New SSH session over tailnet works; public 22 closed
- [ ] License status valid (core + add-ons)
- [ ] Error logs clean

## 13. Planned

Passkey (WebAuthn) as a second factor, and a reCAPTCHA v3 upgrade of the login form, are still to come. Registering passkeys depends on the admin hostname staying put: section 10 explains why a hostname move invalidates existing WebAuthn credentials, so enroll them only after the tailnet hostname is settled and keep password plus TOTP available.

## 14. Cloudflare edge hardening

Public `example.com` is proxied at Cloudflare. Admin stays on the tailnet (section 11.1) and does not go through that proxy. Placeholders match the table at the top: `<ORIGIN_IP>` is `192.0.2.10`, `<HOME_IP>` is `203.0.113.10`, the zone is `<ZONE_ID>`, the token is `$CF_API_TOKEN`. Snippets: [`examples/cloudflare-edge-hardening.md`](examples/cloudflare-edge-hardening.md).

### 14.1 DNS and TLS

- A/AAAA for `example.com` are proxied (orange cloud). `whmcs.example.ts.net` is MagicDNS, not a Cloudflare record.
- SSL/TLS mode **Full (strict)**. The origin certificate must be valid for `example.com` (the existing Let's Encrypt cert).
- Authenticated Origin Pulls is recommended once Full (strict) already works: [Authenticated Origin Pulls](https://developers.cloudflare.com/ssl/origin-configuration/authenticated-origin-pull/).
- `<ZONE_ID>` and `$CF_API_TOKEN` stay out of git.

### 14.2 Origin firewall: 443 from Cloudflare only

Port 443 on the origin accepts Cloudflare's published ranges and nothing else, so a direct connection to `<ORIGIN_IP>` never reaches Apache. Port 80 stays open for ACME HTTP-01 (Let's Encrypt).

- https://www.cloudflare.com/ips-v4
- https://www.cloudflare.com/ips-v6

Re-check both URLs on a schedule. The CIDRs live in two places and move together: this firewall, and Apache `RemoteIPTrustedProxy` (14.3). A firewall list that gained a range Apache does not trust yet still connects, and WHMCS logs the edge IP. `ufw` needs `IPV6=yes`, or the v6 half is a no-op.

`<HOME_IP>` is not a 443 hole. Admins use the tailnet. That address is the section 4 allowlist, applied to the client IP `mod_remoteip` restored.

```bash
# Drop the generic 443 allow, then permit each published CIDR.
# The delete loop clears a previous run so rules do not stack.
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
sudo ufw allow 80/tcp comment 'acme-http-01'
```

Close 443 to the world on any provider firewall too (same habit as port 22 in section 6). Leave 80 open there.

Verify from a host that is not Cloudflare: `https://<ORIGIN_IP>/` times out or is refused. `https://example.com/status` still returns `200`.

### 14.3 Real client IP (`mod_remoteip`)

WHMCS logs, fraud rules, and Banned IPs key off `REMOTE_ADDR`. `CF-Connecting-IP` is the visitor address Cloudflare sends to the origin. Without it, every public client is an edge address: a ban lands on that edge, and the next visitor through the same address looks banned.

```apache
RemoteIPHeader CF-Connecting-IP
# One directive per CIDR from the two URLs in 14.2. Same fetch as ufw.
RemoteIPTrustedProxy <CF_CIDR>
RemoteIPInternalProxy <ORIGIN_IP> 127.0.0.1
```

`RemoteIPTrustedProxy` is the Cloudflare ranges only. `RemoteIPInternalProxy` is the local chain: `127.0.0.1` (nginx) and `<ORIGIN_IP>`. Omitting `127.0.0.1` is the section 8 lockout.

Replace the `RemoteIPHeader X-Forwarded-For` lines in [`examples/apache-whmcs-lockdown.conf`](examples/apache-whmcs-lockdown.conf) with this block in the same change as 14.4. The example file generates one `RemoteIPTrustedProxy` per current CIDR. Do not commit that generated list.

### 14.4 Tailnet hostfix must pass `CF-Connecting-IP`

`tailscale serve` puts the tailnet client in `X-Forwarded-For` (`100.64.0.0/10`). After 14.3, Apache reads `CF-Connecting-IP` and stops reading `X-Forwarded-For`. The hostfix proxy copies the client address across, which is what keeps the section 3/4/5 bypass (`RewriteCond expr "! -R '100.64.0.0/10'"`) matching.

[`examples/nginx-tailnet-hostfix.conf`](examples/nginx-tailnet-hostfix.conf) (also inlined in 11.1):

```nginx
proxy_set_header CF-Connecting-IP $http_x_forwarded_for;
```

**Known pitfall.** Switching `RemoteIPHeader` to `CF-Connecting-IP` without that `proxy_set_header` makes tailnet clients appear as `<ORIGIN_IP>`. The admin and API locks then treat them as public and return 404. Add the header in the same change. Section 11.2 is the check: the Apache log shows the device's `100.x` address, not `<ORIGIN_IP>`.

### 14.5 Skip edge challenges on callbacks and the API

Gateway callbacks (`/modules/gateways/callback/<gateway>.php`) and `includes/api.php` are server-to-server. A challenge, a managed-rule block, or a rate limit drops the call; there is no browser to solve it. One custom rule on zone `<ZONE_ID>`, action **Skip**.

```
(starts_with(http.request.uri.path, "/modules/gateways/callback/")) or (http.request.uri.path eq "/includes/api.php")
```

Leave `/sysadmin` off that expression.

Phases:

| Phase | What it skips |
|---|---|
| `http_ratelimit` | Rate limiting rules |
| `http_request_firewall_managed` | Managed WAF |
| `http_request_sbfm` | Super Bot Fight Mode |

Products (previous-version equivalents of rate limiting and managed WAF): `rateLimit`, `waf`.

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

Dashboard: **Security → Security rules → Custom rules**, action **Skip**, and select those phases and products. An API create uses `Authorization: Bearer $CF_API_TOKEN` against zone `<ZONE_ID>` (full `curl` in the example file).

The skip is not an origin allow. Section 4 still returns 404 for public `includes/api.php`. Callbacks stay public on purpose. Bot Fight Mode (Free plan) sits outside these phases and cannot be skipped; use Super Bot Fight Mode when the exemption has to work.

## Credits

The lockdown work and this guide were written by **Server Admin**, fuzzy's automation bot.

## License

Text: CC BY 4.0. Example configs: MIT.
