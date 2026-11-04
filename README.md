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

After upgrading WHMCS to 8.13.7 and putting the admin behind Tailscale Serve, the core license and add-on module license checks stopped validating. It was fixed the same afternoon (roughly 1–2 PM MST), without rolling back. What happened and what we did:

**How it works.** The core license stores a signed *local key* in the `License` row of `tblconfiguration`. Many commercial add-on modules store a `licensekey` plus a cached `localkey`/`licensestatus` in `tbladdonmodules`. After a **reissue**, the next remote check records the hostname, IP, and install directory it sees and binds the license to them.

**What went wrong.** After the reissue, the first checks didn't come from the public site. Tailscale Serve was proxying to `127.0.0.1`, so WHMCS saw a loopback address and the tailnet hostname. A command-line license check had run as well, with no web hostname at all. The licenses bound to the wrong context, and the admin was locked out.

**What fixed it (all of it standard licensing):**
1. **Back up first.** Dump the core `License` row, the full `tbladdonmodules` table, and the admin user row you'll sign in with. Also save checksums of each add-on setting. Diff them afterwards so you can show that only the cached local keys changed.
2. **Make the server present one consistent identity.** Point Tailscale Serve at the server's **public IP** rather than loopback (`tailscale serve --bg --https=8443 https+insecure://<public-ip>:443`). Set `UseCanonicalName On` in the SSL vhost. Use `mod_remoteip` so that `X-Forwarded-For` is trusted only from the server itself, which keeps the real client IP for logs and login bans. Allow the tailnet `Host` header only when the request arrives from loopback or the server's own IP, never from the internet.
3. **Reissue the core license** in the WHMCS client area (*Services → license → Reissue*).
4. **Do the first load from the real public hostname** (`https://example.com/`) before anything else touches WHMCS. Don't load it first from the `ts.net` name, `localhost`, the command line, or cron.
5. **Add-on modules.** Open each module's settings and re-save the licence key from the vendor account. Let the module fetch a fresh local key on a normal web load. If one still refuses, ask the vendor's support to reissue it for the correct domain.
6. **Verify.** Core license status shows *Active*, each add-on reports a valid license, and the before/after setting diff shows only the `localkey`/token rows changed. Then sign in to `/admin` over Tailscale as usual.

**Rules we adopted:**
- Plan license reissues *before* changing hostnames, proxies, or Serve targets.
- Never run command-line license checks right after a reissue.
- Never do the first load from a tailnet or internal hostname.
- **Don't roll back the upgrade** over a license problem, even after downtime. Fix forward with a reissue and vendor support. Rolling back doesn't undo the binding.
- Only run official, licensed builds, and keep license keys in a password manager. Diff every upgrade against the vendor's clean release.

## 10. Checklist after any change

- [ ] `https://example.com/admin/` → 404 from outside
- [ ] `https://whmcs.example.ts.net:8443/admin/` → login page
- [ ] Public `includes/api.php` → 404; allowlisted API call → success
- [ ] `/vendor`, `/whmcsupdates` → 404
- [ ] Checkout + gateway callback test passes
- [ ] New SSH session over tailnet works; public 22 closed
- [ ] License status valid (core + add-ons)
- [ ] Error logs clean

## License

Text: CC BY 4.0. Example configs: MIT.
