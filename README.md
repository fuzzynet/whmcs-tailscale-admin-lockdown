# Protecting a WHMCS Admin Area with Tailscale

A practical, upgrade-safe pattern for taking the WHMCS admin area, API, and SSH off the public internet using Tailscale. It is written from a real production setup; all names, paths, and addresses here are examples.

| Placeholder | Meaning |
|---|---|
| `example.com` | Your public WHMCS hostname |
| `whmcs.example.ts.net` | The server's Tailscale (MagicDNS) name |
| `/admin` | Your (renamed) WHMCS admin directory |
| `192.0.2.10` | This server's public IP |
| `203.0.113.10` | An allowlisted static/home IP (documentation range) |
| `100.64.0.0/10` | Tailscale CGNAT range |

> Assumes Apache 2.4 + PHP-FPM on Ubuntu. Nginx equivalents are noted where they differ.

---

## 1. Goals

- Admin area reachable **only** over the tailnet.
- `includes/api.php` closed to the public, still usable from the tailnet and from allowlisted IPs.
- `/vendor` and `/whmcsupdates` not web-reachable.
- SSH only over Tailscale; public port 22 closed.
- Every change survives WHMCS upgrades and can be re-applied in one step.
- Licensing keeps working (see section 9 — read it **before** you change hostnames).

## 2. Tailscale Serve in front of the admin

Join the server to your tailnet, then let Tailscale terminate HTTPS on its MagicDNS name and proxy to the local web server:

```bash
sudo tailscale up --ssh=false
sudo tailscale serve --bg --https=8443 https+insecure://192.0.2.10:443   # server's public IP, see section 9
tailscale serve status
```

Admins now use `https://whmcs.example.ts.net:8443/admin/`. A valid cert is issued automatically for the `ts.net` name. Do **not** use `tailscale funnel` — that would make it public again.

Proxying to the public IP (not loopback) gives WHMCS one consistent server identity. The web-server rules trust the tailnet Host header only when it comes from the server itself.

## 3. Lock the admin directory to tailnet-only (upgrade-safe)

Don't edit files that WHMCS ships (they are overwritten on upgrade). Put the rule in the Apache vhost/conf, which upgrades never touch. See [`examples/apache-whmcs-lockdown.conf`](examples/apache-whmcs-lockdown.conf).

Key points:
- Keep the rules in a file `Include`d from the :443 vhost; the tailnet Host is trusted only when it arrives from the server itself (Serve).
- Allow the tailnet range `100.64.0.0/10`, your allowlisted IP, and Serve traffic from the server itself; answer everyone else with a real HTTP 404 (via `mod_rewrite`, not `Require`, which would give 403) so the path isn't advertised.
- Keep a copy of the conf plus a `reapply.sh` (see [`examples/reapply-lockdown.sh`](examples/reapply-lockdown.sh)) next to your upgrade notes.

**Re-apply after any upgrade or control-panel rebuild:**
1. `sudo apachectl configtest`
2. `sudo ./reapply-lockdown.sh` (idempotent: copies the conf, enables it, reloads)
3. Verify from **outside** the tailnet: `curl -s -o /dev/null -w '%{http_code}\n' https://example.com/admin/` → `404`
4. Verify from the tailnet: `https://whmcs.example.ts.net:8443/admin/` → login page

## 4. Close the public API, keep it for tailnet + allowlist

Same conf file: `includes/api.php` gets a rewrite rule (404) allowing loopback, the tailnet range, and your allowlisted IP(s).

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

> Exception to plan for: payment gateway callbacks (e.g. `modules/gateways/callback/*`) must stay public. Only lock `api.php`.

## 5. Lock `/vendor` and `/whmcsupdates`

Neither needs to be web-reachable. Deny them entirely (see the example conf). Leave public-facing pages (`/status` style pages, cart, client area, gateway callbacks) alone. Re-test checkout after the change.

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

- **Self-inflicted login ban on 127.0.0.1.** If Tailscale Serve proxies from loopback without real-IP handling, every admin looks like `127.0.0.1` to WHMCS. A few failed logins (or a script with a blank API secret) bans `127.0.0.1` and locks out *everyone*. Fix: remove the entry under *Utilities → Logs → Banned IPs* (or the `tblbannedips` table) and make sure WHMCS sees the real client IP — configure trusted proxies (`$trusted_proxies` / X-Forwarded-For handling) and Apache `mod_remoteip`.
- **Empty API secrets ban your allowlisted IP.** Wrap API calls in a helper that refuses to send if identifier/secret is empty.
- **Upgrades don't remove vhost rules, but panel rebuilds can.** Always run the re-apply script and the outside/inside checks after maintenance.
- **Gateways and webhooks.** Some payment processors refuse to call private/CGNAT addresses; keep their callbacks on public HTTPS.
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

**Why it recurs / durable fix.** Every admin load via the Tailscale hostname risks re-poisoning the license on the next remote recheck. The durable fix is to make the proxy chain present the real `Host` to WHMCS, e.g. a tiny local nginx between `tailscale serve` and Apache:

```nginx
location / {
    proxy_pass https://127.0.0.1:443;
    proxy_set_header Host example.com;
    proxy_ssl_server_name on;
    proxy_ssl_name example.com;
}
```

…and make sure the tailnet-only admin restriction (section 3) still allows that local proxy's source address. Alternative: ask WHMCS licensing support to add the tailnet hostname as an allowed domain on the license. Until then, if admin shows a license error, re-run the reissue + clean-`Host` load above.

**Diagnosis tips.**

- Apache access log: find the first `licenseerror.php` redirect and which client IP / hostname triggered it.
- `tblconfiguration` rows `License` and `LicenseRemoteChecks`: remote checks ~15 s apart match the page hang.
- Confirm the key in `/var/www/example.com/public_html/configuration.php` is the official `WHMCS-xxxx` key (not nulled), and that the client-area License page shows the correct bound domains/IP.

## 12. Checklist after any change

- [ ] `https://example.com/admin/` → 404 from outside
- [ ] `https://whmcs.example.ts.net:8443/admin/` → login page
- [ ] Public `includes/api.php` → 404; allowlisted API call → success
- [ ] `/vendor`, `/whmcsupdates` → 404
- [ ] Checkout + gateway callback test passes
- [ ] New SSH session over tailnet works; public 22 closed
- [ ] License status valid (core + add-ons)
- [ ] Error logs clean

## Credits

The lockdown work and this guide were written by **Server Admin**, fuzzy's automation bot.

## License

Text: CC BY 4.0. Example configs: MIT.
