# AT Protocol (Bluesky) Setup Guide for Aloha Social

## Overview

This guide explains how to enable and configure AT Protocol (Bluesky) support in Aloha Social. When enabled, **every Social account automatically gets a Bluesky identity** (`did:plc`) with handle `@username.your-instance.com`. Public posts are automatically syndicated to Bluesky, and users can follow, like, repost, and reply to Bluesky accounts from within Aloha Social.

---

## Requirements

### Server Requirements
- **PHP 8.3+** with extensions: `gmp`, `sodium`
- **HTTPS** on the instance (required by AT Protocol)
- **Wildcard DNS record** `*.your-instance.com` pointing to your server
- **Wildcard SSL certificate** for `*.your-instance.com` (Let's Encrypt DNS-01 challenge)
- **WebSocket support** in web server (for firehose)

### Composer Dependencies (auto-installed)
```bash
composer require spomky-labs/cbor-php paragonie/ecc ratchet/rfc6455 react/socket lcobucci/jwt
```

---

## Web Server Configuration

### Apache
Include `contrib/webserver/apache-social-root.conf` in your HTTPS VirtualHost:

```apache
# AT Protocol (Bluesky) endpoints
RewriteRule ^/?xrpc/(.*)$  http://127.0.0.1/index.php/apps/social/xrpc/$1  [P,QSA,L]

# Wildcard host well-known for AT Protocol handle resolution
RewriteCond %{HTTP_HOST} ^([^.]+)\.(.+)$
RewriteRule ^/?\.well-known/atproto-did$ http://127.0.0.1/index.php/apps/social/.well-known/atproto-did [P,QSA,L]

# AT Protocol service DID
RewriteRule ^/?\.well-known/did\.json$ http://127.0.0.1/index.php/apps/social/.well-known/did.json [P,QSA,L]

# WebSocket proxy for AT Protocol firehose
<IfModule mod_proxy_wstunnel.c>
    ProxyPass /xrpc/com.atproto.sync.subscribeRepos ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos
    ProxyPassReverse /xrpc/com.atproto.sync.subscribeRepos ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos
</IfModule>
```

### Nginx
Include `contrib/webserver/nginx-social-root.conf` in your server block:

```nginx
# AT Protocol (Bluesky) endpoints
location ^~ /xrpc/ {
    rewrite ^/xrpc/(.*)$ /index.php/apps/social/xrpc/$1 break;
    proxy_pass http://127.0.0.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# Wildcard host well-known for AT Protocol handle resolution
location ~ ^/([^/]+)\.example\.com/\.well-known/atproto-did$ {
    rewrite ^ /index.php/apps/social/.well-known/atproto-did break;
    proxy_pass http://127.0.0.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# AT Protocol service DID
location = /.well-known/did.json {
    rewrite ^ /index.php/apps/social/.well-known/did.json break;
    proxy_pass http://127.0.0.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# WebSocket proxy for AT Protocol firehose
location /xrpc/com.atproto.sync.subscribeRepos {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400;
}
```

**Important**: Replace `example.com` with your actual domain in the Nginx config.

---

## Firehose Daemon (Required)

The firehose daemon **must run continuously** as a systemd service. It serves the WebSocket endpoint that Bluesky relays connect to.

### systemd Service File

Create `/etc/systemd/system/nextcloud-social-atproto-serve.service`:

```ini
[Unit]
Description=Aloha Social AT Protocol Firehose
After=network.target postgresql.service redis.service
Requires=postgresql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nextcloud
ExecStart=/usr/bin/php occ social:atproto:serve --host=127.0.0.1 --port=8080
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=nextcloud-social-atproto

# Resource limits
LimitNOFILE=65536
LimitNPROC=4096

[Install]
WantedBy=multi-user.target
```

### Enable and Start

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nextcloud-social-atproto-serve
sudo systemctl status nextcloud-social-atproto-serve
```

### Verify It's Running

```bash
# Check logs
sudo journalctl -u nextcloud-social-atproto-serve -f

# Test WebSocket connection
wscat -c ws://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos
# Should receive an #info frame
```

---

## Enable AT Protocol in Aloha Social

### 1. Run Migrations

```bash
cd /var/www/nextcloud
sudo -u www-data php occ maintenance:mode --on
sudo -u www-data php occ migration:execute social Version000040
sudo -u www-data php occ migration:execute social Version000041
sudo -u www-data php occ maintenance:mode --off
```

### 2. Enable via Admin Settings

Go to **Administration → Social → AT Protocol (Bluesky)** and configure:

| Setting | Description | Example |
|---------|-------------|---------|
| **Enable AT Protocol** | Master switch | ✓ Enabled |
| **Relays** | Relay URLs (one per line) | `https://bsky.network` |
| **Jetstream** | Optional real-time endpoint | `wss://jetstream1.us-east.bsky.network/subscribe` |
| **Sync Ceiling** | Max AppView requests per batch | `200` |
| **PLC Directory** | PLC directory URL | `https://plc.directory` |
| **AppView** | AppView for reads | `https://public.api.bsky.app` |

Click **Save**.

### 3. Create Identities for Existing Users

```bash
sudo -u www-data php occ social:atproto:identities
```

This creates Bluesky identities (`did:plc` + handle) for all existing local accounts.

### 4. Request Initial Relay Crawl

```bash
sudo -u www-data php occ social:atproto:crawl
```

---

## Optional: Jetstream Listener (Real-time)

For instant Bluesky updates on large instances, enable the Jetstream listener:

### systemd Service

Create `/etc/systemd/system/nextcloud-social-atproto-listen.service`:

```ini
[Unit]
Description=Aloha Social AT Protocol Jetstream Listener
After=network.target postgresql.service redis.service
Requires=postgresql.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nextcloud
ExecStart=/usr/bin/php occ social:atproto:listen
Restart=always
RestartSec=30
StandardOutput=journal
StandardError=journal
SyslogIdentifier=nextcloud-social-atproto-listen

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now nextcloud-social-atproto-listen
```

---

## Cron Jobs

The following cron jobs are automatically registered:

| Job | Frequency | Purpose |
|-----|-----------|---------|
| `AtprotoSync` | Every 2 min | Polls AppView for new posts from followed Bluesky accounts |
| `AtprotoNotifications` | Every 2 min | Fetches Bluesky notifications (likes, reposts, follows, replies) |

Verify they're running:

```bash
sudo -u www-data php occ social:worker
```

---

## Verification Checklist

After setup, verify everything works:

### 1. Identity Resolution
```bash
# Your own handle
sudo -u www-data php occ social:atproto:resolve alice.your-instance.com

# External handle
sudo -u www-data php occ social:atproto:resolve alice.bsky.social
```

### 2. Repository Access
```bash
# Get your repo head
sudo -u www-data php occ social:atproto:repo alice.your-instance.com

# Verify MST
sudo -u www-data php occ social:atproto:repo alice.your-instance.com --verify
```

### 3. Firehose Connectivity
```bash
# Test from external (relay perspective)
wscat -c wss://your-instance.com/xrpc/com.atproto.sync.subscribeRepos
```

### 4. Admin Status Page
Go to **Administration → Social → AT Protocol (Bluesky) → Status** and verify:
- ✓ Identities count > 0
- ✓ Firehose: Running
- ✓ Relay lag: < 1 minute
- ✓ No setup check warnings

### 5. User-Facing Test
1. Log in as a user
2. Go to **Settings → Your account** → verify Bluesky identity shown
3. Make a **public post** → check it appears on Bluesky at `https://bsky.app/profile/username.your-instance.com`
4. Search for `alice.bsky.social` → follow → verify follow appears in Activities

---

## Troubleshooting

### Firehose Not Connecting
```bash
# Check daemon status
systemctl status nextcloud-social-atproto-serve

# Check port binding
ss -tlnp | grep 8080

# Check WebSocket proxy
curl -v -H "Upgrade: websocket" -H "Connection: Upgrade" http://127.0.0.1:8080/xrpc/com.atproto.sync.subscribeRepos
```

### Relay Not Crawling
- Verify wildcard DNS: `dig +short _atproto.alice.your-instance.com TXT`
- Verify wildcard cert: `curl -I https://alice.your-instance.com/.well-known/atproto-did`
- Check admin status for "Relay lag"
- Manually trigger: `occ social:atproto:crawl`

### Posts Not Appearing on Bluesky
- Verify post is **public** (not unlisted/followers-only/direct)
- Check `occ social:worker` is processing queue
- Check firehose logs for commit frames
- Verify relay has crawled your repo

### Identities Not Created
```bash
# Check if atproto_enabled is true
sudo -u www-data php occ config:app:get social atproto_enabled

# Force create for specific user
sudo -u www-data php occ social:atproto:identities --user=123

# Check logs
grep atproto /var/www/nextcloud/data/nextcloud.log
```

---

## Architecture Summary

```
┌─────────────────────────────────────────────────────────────┐
│                     Aloha Social (Nextcloud)                │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │   Fediverse  │  │   AT Proto   │  │     Web UI          │
│  │  (ActivityPub)│  │  (Bluesky)   │  │  (Bluesky badges,   │
│  │              │  │              │  │   profiles, search) │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬──────────┘  │
│         │                 │                      │             │
│         ▼                 ▼                      ▼             │
│  ┌─────────────────────────────────────────────────────────┐  │
│  │              Shared Services & Database                 │  │
│  │  - PostService (auto-publishes to both)                │  │
│  │  - FollowService (creates atproto follows)             │  │
│  │  - LikeService / BoostService (creates atproto likes)  │  │
│  │  - Cron: AtprotoSync / AtprotoNotifications            │  │
│  └─────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
         │                              │
         ▼                              ▼
┌─────────────────────┐      ┌─────────────────────┐
│   ActivityPub       │      │   AT Protocol       │
│   Relays/Servers    │      │   Relay (bsky.net)  │
│                     │      │   AppView (api.bsky)│
└─────────────────────┘      └─────────────────────┘
```

---

## Commands Reference

```bash
# Firehose
occ social:atproto:serve [--host=127.0.0.1] [--port=8080] [--once] [--max-seconds=3600]

# Sync & Notifications
occ social:atproto:sync [--batch=50] [--dry-run]
occ social:atproto:notifications [--batch=50] [--dry-run]

# Identities
occ social:atproto:identities [--user=123] [--force] [--dry-run]

# PLC Operations
occ social:atproto:plc log|view|repair [did]

# Resolution
occ social:atproto:resolve <handle|did>

# Repository
occ social:atproto:repo <user> [--verify]

# Blocklist
occ social:atproto:block host|did <value> [--reason="..."]
occ social:atproto:block --unblock host|did <value>

# Relay
occ social:atproto:crawl [did]

# Keys
occ social:atproto:rotate-key [--dry-run] [--batch=50]

# Jetstream
occ social:atproto:listen [--once] [--max-seconds=3600]
```

---

## Security Notes

- **Keys**: Signing/rotation keys are sealed with Nextcloud's instance secret (same as app passwords)
- **Rate Limits**: 3000 points/hour/DID for writes, 300/5min per IP on `createSession`
- **Blocks/Mutes**: Never published to Bluesky (local only)
- **Direct Messages**: Out of scope (Bluesky Chat is separate)
- **App Passwords**: Used for Bluesky app login (Nextcloud app passwords)

---

## Upgrading

```bash
# 1. Backup database
# 2. Update code
# 3. Run migrations
occ migration:execute social Version000040
occ migration:execute social Version000041
# 4. Restart daemons
systemctl restart nextcloud-social-atproto-serve
systemctl restart nextcloud-social-atproto-listen
# 5. Verify
occ social:atproto:repo <user> --verify
```

---

## Support

- **Documentation**: `docs/Atproto-Compatibility.md`
- **Issues**: GitHub Issues
- **Logs**: `nextcloud-social-atproto-serve` and `nextcloud-social-atproto-listen` systemd journals