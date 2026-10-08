# ATProto Deployment Runbook

End-to-end example of exposing a Nextcloud instance (with the Social app's
native ATProto support) to the public Bluesky / AT Protocol network: handle
resolution, root routing, firehose proxying, publishing and relay backfill.

This is a generic walkthrough. Replace every placeholder (`example.org`,
`203.0.113.10`, `admin@example.org`, `alice`) with your own values.

---

## 1. Why posts only show up after `publish` + `crawl`

- `occ social:atproto:publish` writes the post as an AT Protocol record into the
  local repository and logs the commit into the firehose database. Nothing is
  visible remotely until this has run.
- Relays connect with `com.atproto.sync.subscribeRepos` using a cursor. Without a
  stored cursor they only see what lands at the head of the stream from now on;
  past commits are not replayed.
- `occ social:atproto:crawl did:...` sends `com.atproto.sync.requestCrawl` to the
  relay (e.g. `bsky.network`). The relay then backfills the repository over HTTPS
  (`com.atproto.sync.getRepo`), and the profile data in the AppView builds up.

Order therefore: create identity → create post → `publish` → `crawl`. Only then
is the account resolvable by handle and DID.

---

## 2. Manual changes

### 2.1 DNS (Cloudflare or any other provider)

Zone `example.org`. Keep the public PDS host a direct A/CNAME record; a DNS-only
entry (grey cloud) avoids extra edge-certificate requirements.

| Name | Type | Target | Proxy |
|---|---|---|---|
| `*.social.example.org` | CNAME/ A | `203.0.113.10` (or `mail.example.org`) | **off** (DNS-only preferred) |

`social.example.org` itself stays DNS-only and points at the public IP.

The wildcard makes every future handle (`<user>.social.example.org`) resolve
without extra DNS records.

### 2.2 TLS (Let's Encrypt)

The handle host is issued via HTTP-01. The wildcard record resolves to the
server, so the ACME challenge is served by the existing Nextcloud vhost:

```sh
sudo certbot certonly --webroot -w /var/www/nextcloud \
  -d alice.social.example.org \
  --key-type ecdsa --agree-tos --email admin@example.org
```

Certbot schedules automatic renewal. Run again with more `-d` flags to extend
the certificate for additional handle hosts.

Alternative: a wildcard `*.social.example.org` via DNS-01 (requires
`python3-certbot-dns-cloudflare` and a scoped Cloudflare API token with
Zone:DNS:Edit — note that a *global* API key is not a scoped token).

### 2.3 Nextcloud configuration

Add every handle host to `trusted_domains` (exact strings; wildcards are not
supported):

```sh
sudo -u www-data php occ config:system:set trusted_domains 6 --value="alice.social.example.org"
```

Enable the feature (if not already on):

```sh
sudo -u www-data php occ config:app:get social atproto_enabled   # should be 1
sudo -u www-data php occ config:app:set social atproto_enabled --value=1
```

`trusted_proxies` must include `127.0.0.1` so Nextcloud trusts the
`X-Forwarded-Proto: https` header that Apache (see 2.4) sets for proxied
root-routing requests.

### 2.4 Apache

Requirements: `mod_proxy`, `mod_proxy_http`, `mod_proxy_wstunnel`, `mod_rewrite`,
`mod_headers`.

Upstream templates: see `docs/Atproto-Setup.md` and
`contrib/webserver/apache-social-root.conf` in this repository.

**a) Port-80 vhost (Nextcloud main host up to 443).** Add the wildcard
`ServerAlias` for handle hosts and make sure the HTTP→HTTPS redirect (1) skips
requests that already arrived via HTTPS (the local root-routing proxy sets
`X-Forwarded-Proto: https`) and (2) skips ACME paths:

```apache
ServerName social.example.org
ServerAlias social.example.org
ServerAlias *.social.example.org
...
RewriteEngine on
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteCond %{SERVER_NAME} =social.example.org
RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]

# Handle hosts: HTTPS redirect, except ACME and local proxy
RewriteCond %{HTTP:X-Forwarded-Proto} !https
RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
RewriteCond %{SERVER_NAME} \.social\.example\.org$
RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
```

**b) HTTPS vhost of the main host.** Add the root-routing rules. The
`subscribeRepos` rule **must** be placed before the generic `/xrpc/(.*)` rule:
in the URL-translate phase mod_rewrite wins over a `ProxyPass` declared further
down, so before this rule the WebSocket handshake would be proxied into
Nextcloud and answer 404:

```apache
# Firehose: the ws:// target makes mod_proxy_wstunnel forward the Upgrade
# header and answer 101 Switching Protocols.
RewriteRule ^/?xrpc/com\.atproto\.sync\.subscribeRepos$ ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos [P,L]
RewriteRule ^/?xrpc/(.*)$ http://127.0.0.1/index.php/apps/social/xrpc/$1 [P,QSA,L]
RewriteRule ^/?\.well-known/atproto-did$ http://127.0.0.1/index.php/apps/social/.well-known/atproto-did [P,QSA,L]
RewriteRule ^/?\.well-known/did\.json$ http://127.0.0.1/index.php/apps/social/.well-known/did.json [P,QSA,L]

ProxyPass "/xrpc/com.atproto.sync.subscribeRepos" "ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos"
ProxyPassReverse "/xrpc/com.atproto.sync.subscribeRepos" "ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos"
```

The main vhost should already carry `ProxyPreserveHost On` and
`RequestHeader setifempty X-Forwarded-Proto https`. The proxy loop runs back to
the public port-80 vhost; its rewrite redirect is suppressed through that
`X-Forwarded-Proto` check.

Optionally, instead of proxying back to the public port-80 vhost, follow the
upstream recommendation of a dedicated loopback-only listener (for example
`Listen 127.0.0.1:8081` + a vhost bound to `127.0.0.1:8081` serving the
Nextcloud document root) and point the `[P]` targets at it.

**c) HTTPS vhost for the handle host.** A dedicated vhost per handle (or a
shared one) with `ServerName alice.social.example.org`, the same root-routing
rules, `ProxyPreserveHost On`, `RequestHeader setifempty X-Forwarded-Proto https`,
`Include /etc/letsencrypt/options-ssl-apache.conf` and the handle certificate.
A dedicated vhost lets SNI hand out the correct certificate:

```sh
sudo a2ensite atproto-handle   # example: this vhost file
```

**d) Firehose daemon.** The app's native WebSocket stream must run on the
loopback address the rules proxy to:

```sh
sudo -u www-data php occ social:atproto:serve --host=127.0.0.1 --port=8080
```

Example systemd unit (from `docs/Atproto-Setup.md`):

```ini
[Unit]
Description=Nextcloud social native ATProto firehose
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/nextcloud
ExecStart=/usr/bin/php occ social:atproto:serve --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

After config changes: `sudo apache2ctl configtest && sudo systemctl reload apache2`.

---

## 3. Commands, in order

Run all `php occ` commands as the web-server user (otherwise Nextcloud refuses
to write/read `config/`) from the Nextcloud root:

```sh
# 1) Enable ATProto            (the app-level config key)
php occ config:app:set social atproto_enabled --value=1

# 2) Trust the handle host      (exact entry per handle)
php occ config:system:set trusted_domains 6 --value="alice.social.example.org"

# 3) Create identities for existing accounts
php occ social:atproto:identities --user=alice
#    -> handle alice.social.example.org, DID did:plc:…, state=active
#    (the PLC operation is submitted to plc.directory)

# 4) Inspect / resolve
php occ social:atproto:repo alice
php occ social:atproto:resolve alice.social.example.org

# 5) Sign + commit pending publications
php occ social:atproto:publish           # "pending outbox items: 0"
php occ social:atproto:repo alice        # Record Count > 0
php occ social:atproto:repo alice --verify  # MST/commit signature verified

# 6) Trigger relay backfill
php occ social:atproto:crawl did:plc:…   # "bsky.network: Accepted"
```

Public verification (must be reachable from the internet):

```sh
curl https://social.example.org/xrpc/com.atproto.server.describeServer
curl https://alice.social.example.org/.well-known/atproto-did
curl 'https://alice.social.example.org/xrpc/com.atproto.sync.getRepo?did=did:plc:…'
curl -i -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  https://social.example.org/xrpc/com.atproto.sync.subscribeRepos   # 101 Switching Protocols
curl 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=alice.social.example.org'
curl 'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor=did:plc:…'
```

New posts (UI or CLI), then republish + re-crawl:

```sh
php occ social:note:create alice "Text of the post"
php occ social:atproto:publish
php occ social:atproto:crawl did:plc:…
```

---

## 4. Caveats

- Only **public** posts enter the native repository; private, followers-only and
  unlisted posts stay out (app behavior).
- The firehose daemon needs to survive reboots (systemd unit above); without it,
  no live stream is served for new posts.
- Each new handle needs a `trusted_domains` entry and certificate coverage (add
  `-d` names to the existing certbot line); the wildcard DNS record already
  covers DNS.
- The DID is bound to the handle's domain in the PLC document — do not change
  the domain afterwards (see the app documentation).
- Firehose cursor retention is 72 hours; relays outside that window must be
  served again with `social:atproto:crawl`.