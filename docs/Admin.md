# Administering Aloha Social

What an administrator has to set up, what they can change, and what tells them
something is wrong. For the routes behind the settings page see
[API.md](API.md), for the commands [OCC-Commands.md](OCC-Commands.md), and for
how the pieces fit together [Architecture.md](Architecture.md).

---

## Before it federates

Aloha Social is a fediverse server that happens to live inside Nextcloud, so the
things it needs are the things any fediverse server needs: a stable public
address, a `.well-known` answer, and a cron that runs.

**A stable address.** Aloha Social copies `overwrite.cli.url` into its own
`cloud_url` the first time somebody opens the app, derives `social_url` (that
address followed by `/apps/social/`) from it, and builds every account id,
post id and WebFinger answer from those copies. It never reads the system value
again. Set `overwrite.cli.url` — and get it right — before anyone opens Aloha Social;
changing the server's address afterwards is the single most expensive mistake
available here, because the old address is inside every id already federated.
See [*the address Aloha Social is set up for*](#the-address-aloha-social-is-set-up-for).

**`.well-known` redirects.** Another server looking for `@alice@example.com`
asks `https://example.com/.well-known/webfinger`. Nextcloud serves that from
`/index.php/.well-known/webfinger`, and the redirect from the domain root is
something the web server has to do; Nextcloud's
[documented redirects](https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL)
are exactly the ones needed. Without them nobody outside can find an account
here, however well everything inside works.

**Pretty URLs are not required**, but an instance reachable only at
`https://example.com/index.php/apps/social/...` federates under ids with
`index.php` in them, which some peers handle poorly. If `.htaccess`-based URL
rewriting is available, turn it on before the first account is created rather
than after.

**Behind a reverse proxy**, set `trusted_proxies` and `overwritehost`,
`overwriteprotocol` and `overwrite.cli.url` as the Nextcloud documentation
describes. Aloha Social signs its outbound requests over the `Host` and `Date`
headers and verifies the signatures on what arrives, so a proxy that rewrites
the host without Nextcloud knowing produces signature failures on both sides
that look like nothing else.

**Cron.** Background jobs must be set to *Cron* (system cron), not *AJAX* and
not *Webcron*. Everything this instance sends leaves through `Cron\Queue`,
which runs every 12 minutes; with AJAX jobs, delivery happens only when
somebody happens to load a page, and on a quiet instance that can be hours.
See [*the delivery job has not run*](#the-delivery-job-has-not-run).

**Outbound HTTP.** The server must be able to reach the rest of the fediverse
over HTTPS. Requests to local and private addresses are refused unless
Nextcloud's `allow_local_remote_servers` is on, which is a development setting
and not one to carry into production.

---

## The setup checks

Aloha Social registers its own checks in **Administration → Overview**, beside
Nextcloud's own. They are the things that break federation, or stop clients
connecting, without anything else saying so, and each links back to this page.

`occ social:check:install` runs the same classes (`lib/SetupChecks/`),
prints each with its severity and exits `1` if any of them reports an error, so
a deployment script can run it. `--offline` leaves out the WebFinger probe, the
only one that goes out on the network.

**Aloha Social: upload size.** Whether PHP will accept the uploads this app promises
to. PHP's `upload_max_filesize` and `post_max_size` are enforced before a byte
reaches this app's code, so `/api/v1/instance` and `/api/v2/instance` advertise
`image_size_limit` and `video_size_limit` as the smaller of the app's own
(`max_size`, `max_video_size`) and PHP's. When PHP's is the smaller, every
client is held to it: an upload over it is refused with a 422 naming the limit
(`the file is larger than this server accepts (2 MB)`), and a warning in the
Nextcloud log names the setting it went over. To let the app's own ceiling
apply, raise both PHP values; see
[Raising the upload limit](#raising-the-upload-limit).

**Aloha Social: video conversion.** Whether the videos posted here will play
anywhere else. A warning when **ffmpeg is missing**: iPhone `.mov` videos and
HEVC videos are then stored and sent as they were uploaded, and will not play
on other servers — Pixelfed refuses anything that is not an MP4 — or in Chrome
and Firefox. Information, not a warning, when ffmpeg is there and
`video_transcode` has been switched off, because that is a decision. See
[Videos do not play elsewhere](#videos-do-not-play-elsewhere).

**Aloha Social: reachable by other servers.** Whether the strict peers will talk to
this instance at all. Federation does not fail all at once: a plain-HTTP
instance, or one on a private address, federates happily with a permissive
server and is refused at the first gate by a strict one. The strictest in
common use is **Pixelfed**, which is also the network a photo server most wants
to reach — it refuses any peer that is not HTTPS on a publicly resolvable name,
before it checks a signature, from its inbox, its delivery and its actor fetch,
with nothing to configure. This is expected on a development or intranet
instance and is a warning rather than an error.

**Aloha Social: client API.** Whether a Mastodon app can reach this instance at all.
See below — this is the one check that is about apps rather than about
federation, and it is a warning rather than an error, because everything else
works without it.

### Mastodon apps cannot connect

A Mastodon app is given a domain and builds `https://<domain>/api/v1/...` from
it. Not one of them accepts a path, and Aloha Social's routes live under
`/index.php/apps/social`, so out of the box adding this instance to an app
fails at its first request with nothing in the log to show for it. The web
interface and federation with other servers are unaffected.

An administrator opening Aloha Social sees this at the top of the app, with the
Apache rules to paste, until the web server answers; it is the same check as
in **Administration → Overview**, and it goes quiet within five minutes of the
rules being in place.

Nextcloud only lets a short list of apps claim URLs at the root of the domain,
and Aloha Social is not on it, so this has to be done in the web server. Ready-made
rules are in [`contrib/webserver/`](../contrib/webserver): include
`apache-social-root.conf` from the `<VirtualHost>` that serves Nextcloud, or
`nginx-social-root.conf` from its `server` block, and reload. For Apache that
is:

```apache
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"

RewriteEngine On
RewriteRule ^/?api/(.*)$   http://127.0.0.1/index.php/apps/social/api/$1   [P,QSA,L]
RewriteRule ^/?oauth/(.*)$ http://127.0.0.1/index.php/apps/social/oauth/$1 [P,QSA,L]
RewriteRule ^/?\.well-known/host-meta$ http://127.0.0.1/index.php/.well-known/host-meta [P,QSA,L]
RewriteRule ^/?\.well-known/oauth-authorization-server$ http://127.0.0.1/index.php/apps/social/.well-known/oauth-authorization-server [P,QSA,L]
RewriteRule ^/?\.well-known/oauth-protected-resource$ http://127.0.0.1/index.php/apps/social/.well-known/oauth-protected-resource [P,QSA,L]
```

`mod_proxy`, `mod_proxy_http`, `mod_rewrite` and `mod_headers` have to be enabled. This cannot
go in an `.htaccess` file, because `ProxyPreserveHost` is not allowed there and
without it the app is handed `Host: 127.0.0.1` and refuses the request as an
untrusted domain.

**Check what `127.0.0.1:80` actually serves.** These rules proxy to plain HTTP
on the loopback address, which only works if something there serves *this*
Nextcloud. A server whose virtual hosts are all on `:443` — what a
certbot-managed config looks like once the HTTP ones have been commented out —
has no `:80` virtual host for the domain at all, so the request falls through
to the main server and its `DocumentRoot`, and every proxied request answers
404 from there.

That 404 is indistinguishable from having no rules at all, which is the trap:
the rules are correct, they fire, and the warning stays up. The way to tell them
apart is that an Apache error page names the port it came from —

```
/api/v1/instance  →  "Server at example.com Port 80"    the rules fired, the target is wrong
/login            →  "Server at example.com Port 443"   served by the HTTPS vhost itself
```

— and `apachectl -S` will show no `*:80` section. The app says which of the two
it is as well: the warning carries what the probe got back.

The fix is to give the proxy something to land on. A loopback-only listener
keeps plain HTTP off the public interface:

```apache
Listen 127.0.0.1:8081

<VirtualHost 127.0.0.1:8081>
    ServerName example.com
    DocumentRoot /path/to/nextcloud
    <Directory /path/to/nextcloud>
        Options FollowSymLinks
        AllowOverride All
        Require ip 127.0.0.1
    </Directory>
</VirtualHost>
```

then point the three rules at `http://127.0.0.1:8081` instead. `AllowOverride
All` is not optional here: Nextcloud's own `.htaccess` is what hands the
`Authorization` header to PHP, so without it the client API answers *the
access_token was revoked* for every signed-in request — see below.

They map three things onto the app:

| Path | Why |
| --- | --- |
| `/api/…` | every client call, from `/api/v1/instance` onwards |
| `/oauth/…` | registering the app, the consent screen, the token |
| `/.well-known/host-meta` | some clients ask for it before anything else |
| `/.well-known/oauth-authorization-server` | RFC 8414 discovery; a Mastodon 4.3 client asks for it before it registers anything, and reads a 404 as "not a server I can sign in to" |
| `/.well-known/oauth-protected-resource` | Bluesky apps signing in with Bluesky sign-in (OAuth) ask it which authorization server the accounts here use; with Bluesky on, `/.well-known/oauth-authorization-server` then describes both kinds of sign-in |

**Use an internal rewrite, not a redirect.** Nextcloud routes on the address
the request arrived at, so a plain internal rewrite to `/index.php/apps/social`
reaches PHP but not the route, and answers 404. Sending a redirect instead does
reach the route, but many HTTP clients drop the `Authorization` header when
they follow one, so every signed-in request then answers 401. The Apache rules
therefore proxy internally (`mod_proxy`, `mod_proxy_http` and `ProxyPreserveHost
On`), which keeps both the address and the header.

Apache also has to hand the `Authorization` header to PHP. Nextcloud's own
`.htaccess` does that, but only where `AllowOverride` lets it be read; with
`AllowOverride None` the rule never runs and every request from a signed-in app
answers `the access_token was revoked`. The shipped rules set it themselves for
the same reason.

**The discovery document describes the addresses it was reached at.** RFC 8414
requires the `issuer` in `/.well-known/oauth-authorization-server` to be the URL
the document was fetched from, minus the well-known suffix. A client that asks
the domain root and is told the issuer is `https://cloud.example/index.php/apps/social/`
has been handed a mismatch and rejects it — before it opens a browser, so
nothing here sees it fail. So while the rewrite is working, the document
advertises the root addresses the client used; where it is not, it advertises
the app's own, which is the only reachable answer. Which of the two it is comes
from the check below, read from its cache — an internal proxy hands PHP the app
path either way, so the request itself cannot say.

**`X-Forwarded-Proto` is not optional.** `mod_proxy` sends `X-Forwarded-For` and
`X-Forwarded-Host` by itself and stops there, and the rules above proxy to plain
HTTP — so without that header Nextcloud believes every proxied request arrived
over `http` and builds every absolute URL it answers with accordingly. A client
is then handed `http://` addresses for avatars, posts and the instance
thumbnail, on a site that is `https`. An **iOS client refuses them outright**
(App Transport Security), and its sign-in stops dead after registering the app,
with nothing in any log to show for it. The nginx rules set the equivalent
(`proxy_set_header X-Forwarded-Proto $scheme`) and always did.

The symptom is visible without a client: `/api/v2/instance` fetched through the
root answers a `thumbnail.url` beginning `http://`, where the same document
fetched at `/index.php/apps/social/api/v2/instance` begins `https://`.

**Add `127.0.0.1` to `trusted_proxies`.** Client requests reach PHP from the
proxy afterwards, so without it every app in the world shares one address for
rate limiting, brute-force protection and the log — one client tripping a limit
locks out all of them. The real address arrives in `X-Forwarded-For`, which
Apache's `mod_proxy` sends by itself and the nginx rules set explicitly.

The check probes `/api/v1/instance` at the address Aloha Social is configured for,
then at the host the request came in on, then at the server's base URL, and
reads the answer rather than only its status, so a login page or a catch-all
`index` at the root does not pass for a client API. A success is remembered for
an hour and a failure for five minutes.

### WebFinger does not answer

Nothing answers `/.well-known/webfinger` for an account of this instance, so no
other server can find anybody here — searches for a local account fail
everywhere else, and follows from outside never arrive.

Two quite different causes, and the check cannot tell them apart from outside:

1. The redirects are missing. Add the
   [documented ones](https://docs.nextcloud.com/server/latest/go.php?to=admin-setup-well-known-URL)
   and reload the web server.
2. Aloha Social is set up for a different address than the one the request arrives
   on, so it answers for a host nobody asks about. The next check is about
   that.

The probe is tried at the address Aloha Social is configured for, then at the host
the request came in on, then at the server's base URL, and a success is
remembered for an hour. If the instance uses a certificate the server itself
does not trust — a private CA during setup — set `social.checkssl` to `false`
in `config.php` to stop the probe verifying it. That switch is for the probe
only, not for federation.

### The address Aloha Social is set up for

Aloha Social builds every id from its stored `cloud_url` and the server now reports
something else. Accounts here cannot be found under the address the server
advertises, and every new post carries an id that resolves nowhere.

To see the two values:

```bash
occ config:app:get social cloud_url
occ config:system:get overwrite.cli.url
```

The same check compares the scheme, host and port of `social_url` — the base
the ids are actually minted from — with `cloud_url`. They can only disagree on
an instance set up by an earlier version, which took `social_url` from the
first request that opened the app: an internal hostname, or `http` behind a
proxy that does not pass the scheme on. If nothing has federated yet,
`occ config:app:delete social social_url` and it is derived from `cloud_url`
the next time the app is opened; otherwise `occ social:reset --uri=<address>`
moves both.

Aloha Social reports the mismatch and will not correct it, because the stored address
is inside every id already written. Either point `overwrite.cli.url` back at
the address Aloha Social knows, or accept the rename and run `occ social:reset
--uri=<new address>`, **which deletes everything Aloha Social holds** — every post,
follow and cached account, local and remote alike.

### The delivery job has not run

`Cron\Queue` is what sends. It is meant to run every 12 minutes; the check
warns once an hour has passed without it, and errors if the job is not
registered at all (disabling and re-enabling the app registers it again).

Nothing posted here leaves the server while it is not running, and nobody is
told: the author sees their post in their own timeline and nowhere else. Check
that background jobs are set to Cron, that the system cron is actually running
`cron.php`, and that no other job is monopolising the runs.

### Deliveries are stuck

Two things in the outbound queue do not move on their own: a delivery the drain
has given up on after every retry (16 attempts over a widening delay, the last
gap fourteen hours), and one it should have come back for a day ago and has
not. The first usually means an instance that is gone or refusing us; the
second means the drain is not draining.

```bash
occ social:queue:status                  # the same summary the settings page shows
occ social:queue:retry --min-tries 16    # give the abandoned ones the full run again
occ social:queue:retry --flush --min-tries 16   # or drop them, for a peer that is gone
```

The **Deliveries** section of the Aloha Social settings names the instances
the failures are stacked against, with the highest attempt count so far and
when each was last tried.

### No memory cache

Without `memcache.local` or `memcache.distributed` in `config.php`, every cache
Aloha Social asks Nextcloud for is one that forgets each write. Nextcloud's own check
already says a memcache would be faster; this one is there because, for this
app, some of what is lost is protection:

- **Kept in the database instead** (`social_durable_cache`, expired rows
  deleted by `Cron\Cache`): the inbox throttle (`inbox_throttle`), the
  records of Linked Data signatures and of signed requests already accepted,
  which are what refuse a replayed activity, the `Idempotency-Key` record that
  keeps a client's retried post from being published twice, and the
  statistics page's fifteen-minute copy. All of it works; each costs a few
  queries.
- **Always in the database**, memcache or not: the per-server delivery
  breaker (`social_host_breaker`), which every process and the cron share.
- **Worked out again on every request**: the network figures, peer trends
  and follow suggestions.

APCu (`memcache.local = \OC\Memcache\APCu`) is enough for all of it on a
single web server; Redis is what several need.

### Videos do not play elsewhere

An iPhone records `.mov`; Android phones, and iPhones exporting through some
apps, write **HEVC** inside an `.mp4`. Pixelfed's default `media_types` accepts
`video/mp4` and nothing else and drops the rest the moment it arrives, and
Chrome and Firefox do not play HEVC. Aloha Social converts such videos to H.264 in an
MP4 (`video_transcode`, on by default) — **but only where ffmpeg is installed**.
Install it from the distribution's packages (`apt install ffmpeg`,
`dnf install ffmpeg`, …); ffprobe comes with it, and is what tells an H.264
MP4, which is never touched, from an HEVC one. Nextcloud looks for both on the
`PATH` of the PHP process and in the directories `binary_search_paths` in
`config.php` names, only where PHP's `exec` is not disabled, and remembers the
answer for five minutes.

Nothing needs restarting. Videos uploaded before ffmpeg was there are picked up
by the background sweep, one every quarter of an hour, or all at once with
`occ social:media:transcode`. Posts that already went out with the old file are
not sent again.

### Raising the upload limit

A client is told, and held to, the smaller of Aloha Social's own ceilings and
PHP's. PHP's is the smaller of `upload_max_filesize` and `post_max_size`; its
own default is 2 MB and 8 MB. A refused upload leaves a warning in the
Nextcloud log saying which of the two it went over (`file upload refused:
larger than this server accepts, over upload_max_filesize`, or `… the request
body is over post_max_size`).

Raise both, with `post_max_size` a little above `upload_max_filesize` so the
rest of the form fits beside the file, wherever this PHP reads its settings:
the `php.ini` of the PHP-FPM pool or Apache module, a `.user.ini` in
Nextcloud's directory (PHP-FPM, picked up within five minutes), or
`php_value` lines in Nextcloud's `.htaccess` (Apache with `mod_php`). For
videos up to 2 GB:

```ini
upload_max_filesize = 2G
post_max_size = 2050M
```

Restart PHP-FPM or Apache after editing `php.ini`. A reverse proxy or web
server in front has a body limit of its own (`client_max_body_size` in nginx,
`LimitRequestBody` in Apache) that refuses a larger request before PHP sees
it. A refusal there never reaches Nextcloud's log.

---

## Bluesky

Every account on this instance can be a Bluesky account as well: this Nextcloud
is its personal data server (PDS), `alice.<host>` its handle, and a Bluesky
relay reads what it publishes. Nothing is asked of the person; the
administrator switches it on once, on the administration page, after the
requirements below are met. The design and what each phase delivers is in
[docs/Atproto-Compatibility.md](Atproto-Compatibility.md). Once it is on,
`contrib/atproto-live-probe.sh <host> <handle>` checks from outside that the
network can reach everything it needs, and
[docs/Atproto-Live-Test.md](Atproto-Live-Test.md) goes through the rest by hand.

**Requirements**, each a check on the Bluesky card and in the Overview's setup
checks, and the card refuses to switch Bluesky on while one fails:

- **https** on the instance host. A PDS is reached by relays and apps on the
  public network, and a plain-http one is not a PDS to them; the one exception is
  a development server nobody else talks to.
- **A wildcard host for the handles.** Every account's handle is
  `alice.<host>` and is resolved by `GET https://alice.<host>/.well-known/atproto-did`,
  so a DNS record `*.<host>` must point at this server and the certificate must
  cover it (a Let's Encrypt wildcard needs the DNS-01 challenge). The
  web-server rules for that host are in `contrib/webserver` (the handles block
  at the end of each file).
- **`*.<host>` in `trusted_domains`.** Nextcloud answers a request for a host it
  does not trust with an error page, before the app sees it:

  ```
  php occ config:system:set trusted_domains 3 --value='*.social.example.com'
  ```
- **The root rules.** The DID document names the bare host as the PDS, so
  `/xrpc/` has to be served at the root exactly as `/api/` is for Mastodon apps,
  along with `/.well-known/did.json`. The rules are in the same files:

  ```
  RewriteRule ^/?xrpc/com\.atproto\.sync\.subscribeRepos$ ws://127.0.0.1:8787/xrpc/com.atproto.sync.subscribeRepos [P,QSA,L]
  RewriteRule ^/?xrpc/(.*)$ http://127.0.0.1/index.php/apps/social/xrpc/$1 [P,QSA,L]
  RewriteRule ^/?\.well-known/did\.json$ http://127.0.0.1/index.php/apps/social/.well-known/did.json [P,QSA,L]
  ```

  Apache needs `mod_proxy_wstunnel` for the first of them; nginx the
  `Upgrade`/`Connection` headers the shipped block sets.
- **The firehose daemon.** `occ social:atproto:serve` is a long-running process
  that serves the event stream relays subscribe to. Under systemd:

  ```
  [Unit]
  Description=Aloha Social Bluesky firehose
  After=network.target mariadb.service

  [Service]
  User=www-data
  WorkingDirectory=/var/www/nextcloud
  ExecStart=/usr/bin/php occ social:atproto:serve --bind 127.0.0.1:8787
  Restart=always
  RestartSec=5

  [Install]
  WantedBy=multi-user.target
  ```

  In a container, the same command under the container's supervisor. The daemon
  reports to the card every fifteen seconds; a report older than a minute shows
  as "not running".
- **Cron.** The maintenance job (every five minutes) brings Bluesky up to
  date with the last day's public posts — a post the listener missed is
  published, an edit within the grace period is replaced, a deleted post's
  record is removed — resends directory operations, prunes the firehose
  past its window, drops retired keys, and removes Bluesky posts read here
  that their authors deleted there. The ordinary cron requirement
  covers it.

**Switching on.** The Bluesky card checks the requirements and flips the switch.
Then `php occ social:atproto:identities` gives every existing account its
identity (new accounts, and any account the command missed, get one on their
first public post), and `php occ social:atproto:crawl` tells the relays to
subscribe. From then on every public post of every account goes to Bluesky as
it is made, edits within five minutes replace the Bluesky copy and later ones
leave it standing with its link to the current post here, and a deleted post is
deleted there. Direct, followers-only and unlisted posts never leave. The other
way, people here follow Bluesky accounts by their bare handle
(`alice.bsky.social`) and read them in their home feed; the sync job reads the
followed authors' feeds from Bluesky's public AppView, and asks the AppView,
as each local account, what Bluesky did to it — follows, likes, reposts,
replies, mentions — which arrive in Activities like anything else. Each
person can pause their own Bluesky presence in Settings → Apps and account → Bluesky.

**What the card shows.** The handle host, the PDS endpoint and the instance's
`did:web`; the relays (`https://bsky.network` by default; "Tell the relays now"
sends the crawl request again); the PLC directory and the AppView the instance
talks to (the defaults are Bluesky's; the interop job points them at its own —
`atproto_appview` for public reads, `atproto_appview_auth` and
`atproto_appview_did` for the reads made as a user, which the public AppView
refuses); how many identities and repositories there are, how many events the
replay window holds and the head sequence number; the daemon's last report;
the age of the instance rotation key; and the reading side — how many Bluesky
authors are followed, how many accounts' notifications are asked for, and how
far behind the slowest of each is. `atproto_sync_ceiling` (default 200) caps
the AppView requests one sync pass makes.

**Blocking on Bluesky.** The card's *Blocked on Bluesky* list (or `occ
social:atproto:block`) blocks a Bluesky account by its DID, or a whole PDS
host and the hosts under it. A blocked account is not resolved, read or heard
from, and one that people here follow is removed with its posts — the
Bluesky counterpart of a domain block. Reports people file about Bluesky
accounts with *forward* ticked go to Bluesky's moderation service
(`atproto_moderation_did`, Bluesky's own by default), sent by this server, not
in the reporter's name.

**Video.** A video posted here goes to Bluesky as a Bluesky video: it is sent
to Bluesky's video service (`atproto_video_service`, `https://video.bsky.app`
by default, and `atproto_video_service_did`) in the poster's name, and the
post follows once the service has made it, usually within a few minutes.
A video Bluesky does not take — over 100 MB or three minutes, or past the
account's daily limit — goes out as a link to the post here. Set
`atproto_video_service` to an empty value to publish every video as a link.

**Keys and backups.** Each account's signing key and the instance's rotation and
service keys are stored sealed with the instance secret, like actor keys. They
are the identities: a DID whose keys are lost can no longer be updated, and the
person's recovery phrase (Settings → Apps and account → Bluesky) is their own way back. Back
up the database *and* `config.php`'s `secret` together. After a restore from an
older backup the firehose sequence goes backwards; run `php occ
social:atproto:crawl` and the relays re-read every repository.
`php occ social:atproto:rotate-key` replaces the instance rotation key; the old
one stays valid for the directory's 72-hour window.

**Switching off** keeps every identity and repository and stops the daemon's
work, the maintenance job and the publisher; the handles stop resolving until
it is switched on again.

## The administration page

**Administration → Aloha Social.** The sections are in six groups, by what an
administrator came to do, and one group is shown at a time:

| Group | Sections |
| --- | --- |
| **Overview** | Needs attention, Activity here |
| **Moderation** | Reports, Posts waiting for review, Accounts, Refused pictures, Server rules |
| **Sign-ups** | Who may sign up, Waiting and invited, External accounts (only when the server sends them, see [External users](#external-users)) |
| **Explore and features** | About this server, What may trend, Custom emoji, Announcements, Features, For you |
| **Federation** | Allowed and blocked servers, Block lists, Relays, Deliveries |
| **Server** | Server settings, Retention, Storage, Background jobs |

On a wide screen the groups are a list beside the page, each with a line saying
what is in it, and the sections of the open group are listed under it. On a
narrow one they are a row of tabs above it. **Find a setting** searches every
group at once, by a section's name, its description or a word it is known by
("cron", "spam", "blocklist").

The page opens on **Overview**, and **Needs attention** at the top of it lists
what is waiting, most urgent first, each line a link to the section that deals
with it: background jobs that are late or not registered, deliveries close to
being given up on, open reports, posts waiting for review, sign-ups waiting for
approval, and deliveries that failed and will be tried again. The groups with
something waiting carry the number beside their name; a retried delivery is
listed but not counted, because it needs nobody yet.

Each section has an id, and the address names the one on screen:
`/settings/admin/social#reports` opens Moderation at Reports. The report
notification and the Reports dashboard widget link to `#reports`, the
Federation health widget to `#federation`.


- **Reports** — what people here and peers elsewhere have complained about.
  The open ones are the table; the resolved ones are folded away below them and
  read a page at a time when the fold is opened. Fifty to a page, server-side,
  with a *Show more* under each.
- **Activity here** — posts written on this server in the last day and the last
  week, and how many accounts wrote them. Local posts only: a count that
  included what arrived would be a number about other servers and about this
  one's retention setting.
- **Posts waiting for review** — the review queue: the first post of a
  new account, and posts that tripped one of the spam rules. Each row carries
  the text, because a held post is in no timeline and there is nowhere else to
  go and read it. *Publish* sends it out; *Refuse* deletes it and tells its
  author. Three switches at the top turn first-post review, the spam rules and
  holding every post with a video on it on and off.
- **Features** — what this instance offers the people using it. Three switches,
  **all on by default**: *24-hour shorts* (shorts that only followers see
  and that are gone after a day), and the *Photos* and *Videos*
  timelines. Turning one off takes it out of the sidebar and stops it being
  offered; nothing already posted is touched, so a video posted while Videos
  was off is still a video and appears again the moment it is turned back on.
  The fourth setting is the Nextcloud groups that become Aloha Social lists, and it is
  **empty by default**: everybody in a chosen group gets a list for it holding
  the members who have a Aloha Social account, nobody is followed by it and nothing
  federates, but a group list does tell everybody in the group who else is in
  it -- which is why no group becomes one until an administrator chooses.
  Deselecting a group takes its lists away on the next cron reconcile.
  Administrators only, not delegates.
- **Accounts** — every account this instance knows, whether or not anybody has
  complained. Search by username, by handle or by instance, filter by origin
  and by what stands against them, and act on any of them.
- **Refused pictures** — files this instance will not store, named by their
  sha256. The one thing the account-level tools do not do is stop a *file*
  coming back; a refused one is turned away wherever it arrives, an upload here
  or an attachment fetched from another server. The list shows the newest 100;
  when there are more it says how many, and **Show more** adds the next 100
  below. **Allow again** works on any row, on whichever page.
- **About this server** — a few named subjects, each a handful of
  hashtags, shown at the top of Explore above the trending lists. Trending on
  a small server is four hashtags and a wedding; this is the part of that page
  chosen rather than counted, and it is what makes Explore look like somewhere
  to start. Nothing is named by default and the section of the page is absent
  until something is.
- **What may trend** — Explore shows whatever is being talked about, counted
  and nothing else, so the first ugly hashtag to catch on does so on
  everybody's Explore page and the only remedy used to be waiting. Keeping one
  out hides it from every trending list here and from the discover grid; the
  counters keep counting, so letting it back in restores the number it would
  have had. Hashtags can be kept out from the live trending list beside them;
  a link or a post is kept out by pasting its address. What is kept out is one
  list whatever kind it is, because the question is "what am I keeping out of
  Explore".
- **Custom emoji** — the pictures people here can write into a post as
  `:shortcode:`. They travel with the post, so somebody on another server sees
  them too. The upload goes through the same code `occ social:emoji` uses, so
  what is refused here is exactly what the command refuses.
- **Server rules** — one per line. Every client shows them to
  somebody deciding whether to join, and `/api/v1/instance/rules` serves them.
  Both this and the emoji were `occ`-only until now, which is why most
  instances had neither.
- **Retention** — how long remote statuses nobody here cares about are kept.
- **Storage** — what is on disk, split into what was posted here (somebody's
  own work, not going anywhere) and what is cached from other servers (what
  Retention removes). Added up by the background job once a day, because
  counting it is one file lookup per stored file; the page says when it was
  measured. `occ social:media:usage` measures it on demand.

  Not a card, but the thing to know beside this one: a **rejected remote
  attachment** — after changing this instance's media limits or fixing a
  temporary origin problem, retry just that file with
  `occ social:media:retry <remote_url>`. Use the exact `remote_url` reported
  by the attachment; Aloha Social clears the stored refusal for that one uncached
  row and tries it immediately. The normal media checks still apply, so a
  retry can be refused again. A picture that simply took too long to arrive
  while its post was being received is not refused: the background job fetches
  it again, and it and the retry command allow a download two minutes.
- **Deliveries** — what the outbound queue is doing, including how long
  the longest-failing delivery has been failing: the counts say how much and
  where, and that says whether it started an hour ago or a week ago, which is
  the difference between a peer rebooting and a delivery that is never going to
  happen.
- **Background jobs** — when each of this app's cron jobs last ran, and whether
  that is later than it should be. Almost everything the app does away from a
  page happens on a schedule: posts go out, 24-hour shorts expire, media is swept,
  videos are transcoded, the storage figures are taken. When cron stops, the
  symptom is a post that never arrives and a disk that never shrinks, and
  nothing in the app said so. A job is called late after **three** of its own
  intervals, because Nextcloud's cron is itself periodic and a five-minute job
  on a fifteen-minute timer is always two intervals behind with nothing wrong
  with it. A job missing from the list altogether is what an upgrade whose
  migrations have not run looks like; the page says so rather than showing a
  zero. Read from Nextcloud's own job list, so it cannot drift from what
  actually happened.
- **Allowed and blocked servers** — the block list or the allow list, the same
  one `occ social:fediverse` manages.
- **Block lists** — import a list of servers to block, with a preview of what it
  would do, or follow one somebody else publishes; see
  [*following a published list*](#commands-by-task) under Moderation below.
- **Announcements** — a notice every account here is shown once.
- **Server settings** — the instance-wide settings below, which had no interface at all
  before and could only be set with `occ config:app:set`.
- **Relays** — the relays this server subscribes to. A new server sees only
  what the people on it follow, so its federated timeline is empty on the first
  day and thin for months, and nobody out there has heard of it either. A relay
  breaks that circle: it rebroadcasts the public posts of every server
  subscribed to it, and sends this server's public posts on to all of them.
  Paste the relay's address — relays publish it on their own front page, and it
  usually ends in `/actor` — and the row will say **Waiting for an answer**
  until the relay replies, which for some relays means when a human has looked
  at the request. **Public posts and nothing else** are shared: a
  followers-only post has an audience that was chosen, and a relay is the
  opposite of a chosen audience. A relayed post is fetched from the server that
  wrote it rather than believed from the relay, so it arrives as the post it is
  and not as "relay.example boosted this".

Each section is one card with its name and a line saying what it is for, and
the three things that cannot be taken back — suspending an
account, taking a post down, removing an announcement — ask in a dialog that
says what they will cost before they do it.

The page can be **delegated**: hand the Aloha Social section to a group under
*Administration privileges* and that group can moderate without administering
the server. Server settings and Relays are the exception — neither is rendered for a
delegate and their endpoints refuse them, because what they hold is a decision
about the server rather than about a report: a relay changes what every
federated timeline here holds and where every public post written here is
sent. A delegate cannot act on a Nextcloud administrator's account, and
nobody — administrator or delegate — can silence, suspend, lift or purge their
own.

---

## External users

People without an account on this server can register one themselves, when
an administrator switches it on. They log in through the normal login page
and reach Aloha Social and nothing else. It is off by default, and the three cards
are in the **Sign-ups** group of the administration page, for
administrators proper only: who may have an account here is not a
moderator's decision.

**Registration.** The switch *Let people register*, the maximum number of
external accounts (registration closes when that many exist, with a message
saying so), the megabytes of media each may upload, and the minimum age a
person confirms. *Who gets an account* is anybody who registers, anybody an
administrator approves, or only the holders of an invitation link; an
invitation admits its holder in every mode, without approval. With *Confirm
the email address* on, which is the default, the account is made when the
link in the confirmation email is followed, so the server needs working
mail. The form also asks to accept the server rules (the **Server rules** section) and
links the privacy policy and legal notice set in Administration → Theming.
The additional registration and privacy information box is plain text shown
on the introduction page and beside the form. It starts with a translated
template and can be adapted for this instance. *Require registrants to accept
this notice* adds a required checkbox. When enabled, Aloha Social records the text
version, the time and the accepted text with the account. The record remains
for the lifetime of that account and is removed when the account is deleted.
Pending records transfer the acknowledgement to the account when it is made;
rejected records are removed, and unconfirmed registrations expire after 24
hours. These
settings provide an audit trail, not legal advice or a guarantee that a notice
is sufficient for a particular jurisdiction. The operator remains responsible
for local policies and any consent required.
*Usernames nobody may register* adds to a built-in list (`admin`, `root`,
`support`, `social`, `postmaster` and similar). Email domains on the access
block list are refused here as they are for every account.

*Require two-factor authentication* is Nextcloud's own mandatory two-factor
setting for the `social-external` group, the same one as in Administration →
Security; changing it asks for your password. With it on, external users set
up a second factor when they first log in.

Where Aloha Social is enabled for some groups only, external users are let in by
adding `social-external` to them. Switching registration on does that, and
the card warns and offers a button when the restriction leaves them out.

**Waiting and invited.** The registrations waiting for approval, with
*Approve*, which makes the account and emails the person, and *Reject*, which
deletes the registration and emails them, with a reason if you give one.
Below it, the invitation links: a note to remember who each is for, how many
registrations it admits (0 for any number) and how many days it works (0 for
ever). *Let everybody here send invitation links* also gives every user an
*Invite people* section in their Aloha Social settings, single-use links that work
for a week, at most ten open at a time.

**External accounts.** Every external account with its email and verification
status, enabled or disabled status, registration source, when it registered
and last logged in, media usage, and the version and time of any accepted
registration notice. Search matches the username and display name. Sort by username or registration
date, newest or oldest. Filters cover account status, registration source and
date, login activity, minimum media usage and notice acceptance. The list is paged and scans at most a
bounded number of candidates per request.
*Disable* and
*Enable* are Nextcloud's own. *Promote* makes one an ordinary account of this
server with the same username, password, two-factor setup and Aloha Social
account; from then on the groups and apps you give them apply, and there is
no way back. *Delete* removes the account and its Aloha Social account, the way
deleting any Nextcloud user does. Both ask for your password. External users
also appear on the Users page, with *Aloha Social* as their backend and in the
`social-external` group.

**Guests app.** Aloha Social's self-registered accounts use Aloha Social's own `Social`
user backend and the `social-external` group. They are distinct from accounts
created by the [Guests app](https://github.com/nextcloud/guests), which uses
the `Guests` backend and `guest_app` group. Aloha Social's account restrictions and
collaborator filtering target only its own backend, so they do not classify a
Guests-app account as a Aloha Social external account. The two apps can be installed
together, but the Guests app controls which Nextcloud apps its accounts may
open: add `social` to its app whitelist if those accounts should use Aloha Social.
This setting does not grant a Guests account the Aloha Social-only restrictions;
Guests accounts remain governed by the Guests app and Nextcloud group policy.

What an external user reaches: Aloha Social, logging in and out, their personal
settings for their details, language, security, appearance and
notifications, and their notifications. Everything else is refused before it
runs, including every app installed later, and WebDAV, CalDAV and CardDAV are
refused altogether, so Nextcloud's desktop and mobile clients and Talk do not
work for them; Mastodon apps do. Nobody outside Aloha Social finds them in the
share dialog, the contacts menu, the user status list or the system address
book. See [Architecture.md](Architecture.md#external-users) for how.

`occ social:reset` refuses to run while external accounts exist: their logins
are rows of this app.

## Moderating

Reports arrive from two directions: a local user reporting somebody through a
client, and a peer instance sending `POST /api/v1/reports` about one of its
own users' complaints. Both land in the same table, and the administrators (and
the delegated group) are notified.

There are five things a moderator can do to an account, in order of weight:

| Action | What it costs | Reversible |
|--------|---------------|------------|
| **Warn** | Nothing. The account is told there is a problem and everything else stays as it is. | n/a |
| **Mark everything sensitive** | Every post the account makes from now on is marked sensitive, whatever it said. It stays in the timelines and its followers still see it — behind a click. | yes, completely |
| **Silence** | The account leaves the public and global timelines. Whoever deliberately follows it still sees it. | yes, completely |
| **Take down** | One post is deleted. A local post is deleted everywhere it reached; a remote one only here. | no |
| **Suspend** | Everything the account posted here is deleted, its cached actor is dropped, its follows in both directions go, and everything it sends afterwards is refused. A **local** account's suspension is federated as a `Delete`. | the refusal stops; nothing deleted comes back |

*Resolve* marks the complaint handled and changes nothing about the account, so
a report can be closed with any of the four applied, or with none.

**What is recorded.** `social_moderation` holds what stands *now* — one row an
account, replaced by the next decision and gone when it is lifted.
`social_strikes` is the history: a warning, a silence, a suspension, a takedown
and a lift each write a row naming the moderator, and nothing removes one
except the account itself going. The strike count in the account browser counts
what stands against the account, so a lift is in the history and not in the
count.

**The audit log.** Suspending, silencing, lifting, taking a post down and
blocking or unblocking an instance each emit
`OCP\Log\Audit\CriticalActionPerformedEvent`, so with core's `admin_audit` app
enabled they land in the audit log beside "user X was added to group Y", naming
the moderator who acted. Without that app nothing listens and nothing is
written.

**Before anybody sees it.** Two rules hold a post back instead of publishing
it. The spam rules are on by default; first-post review is off until an
administrator turns it on:

- **first-post review** (`review_first_post`) holds the first post of an
  account that has published nothing here yet. Accounts here are Nextcloud
  users, so a spammer has to be given an account by this server before they can
  post at all — and on an instance that hands accounts to strangers, the
  cheapest thing that can be done about it is that the first thing they write
  is seen by a person. **Off by default**: on the ordinary instance, whose
  accounts are colleagues an administrator created, it held every new person's
  first post and made their first five minutes look broken — the composer
  closed, the post did not appear, and it existed only in a panel nobody had
  opened yet. Turn it on where registration is open.
  **An administrator's own posts are never held**: somebody who can empty the
  queue is not somebody to put in it, and the first post on a brand-new
  instance is the administrator's — held, it made a fresh install look broken,
  because the post did not appear and the only place it existed was a panel
  they had not opened yet.
- **autospam** (`autospam`) holds a post that trips one of a very short list of
  rules: more than five links in a short post, or more than five mentions from
  an account that nobody follows and that follows nobody. There is no wordlist
  and no score — "seven links" is something a moderator can agree or disagree
  with in a second, and "0.82" is not.

A **direct message is never held**, whatever it says: holding one would put
private correspondence in front of a moderator who was not written to, for a
machine's reason. Nor is a post by an account a decision already stands
against — that account is being dealt with by the decision.

Nothing is published, and nothing is deleted, without somebody pressing
something. A held post is stored as the *request* the client sent, not as a
post: it is in no timeline, no profile, no outbox and no search, not even its
author's, and their own copy of it is in **Settings → Your posts → Waiting for review**,
where they can take it back. Approving replays the request down the path an
immediate post takes and dates it now. Refusing deletes it, tells the author,
and records a takedown strike. One account may have twenty waiting; past that
its posts are refused outright, because nobody is going to read the fortieth.

**Instances, not accounts.** *Allowed and blocked servers* is the instance-wide list.
In block-list mode (the default, `access_type=all_but`) everything on it is
refused, including every subdomain, and blocking a domain queues a purge of
everything it ever sent. In allow-list mode (`none_but`) the list is the only
instances this server will talk to at all — and it is matched exactly, so a
subdomain of a listed domain is a different instance and is not covered.

The one thing an entry does cover is **what an allowed instance holds
elsewhere**. A post's pictures are fetched from whatever host the post says
holds them, and that is routinely not the host the post came from: a post on
`mastodon.xyz` carries its media on `6-28.mastodon.xyz`, and object storage or
a CDN is common besides. Those hosts are judged as the instance that named
them, because nobody can list the buckets another server keeps its pictures
in. Judging them on their own refused every picture from every allowed
instance, so allow-list mode federated text and nothing else. An instance that is a nuisance
rather than a menace can be *silenced* instead with `occ social:fediverse
silence`: its accounts leave the public timelines and stay readable for the
people who follow them.

---

### From a Pixelfed admin app

The administration screens of Pixelfed's official app call Pixelfed's own
`/api/admin/*` routes, and this instance answers them — behind the same gate
as everything above, and through the same service, so a takedown from the app
is the same takedown as one from this page. Users, open reports and the
instances this server federates with are all there; Pixelfed's `unlisted` on
an instance is the silence tier and its `banned` the deny list. Its **autospam**
screen draws this instance's own review queue, with *not spam* publishing a held
post and *delete* refusing it. What this instance does not have — per-account
"unlisted" or "content warning" flags, switches that flip from the app — is
answered as absent or refused with a reason, never as a 200 that changed
nothing. The
routes and their answers are in [API.md](API.md).

## Configuration

Everything below is app configuration under `social`, read and written with:

```bash
occ config:app:get social <key>
occ config:app:set social <key> --value=<value>
```

Nothing here is reachable through core's app-config API: the settings page
writes through its own validated endpoints, and a delegate can write only what
the moderation routes accept.

### Set by the app, not by you

| Key | Default | Meaning |
|-----|---------|---------|
| `cloud_url` | *(empty)* | The base address every id is built from, copied from `overwrite.cli.url` the first time the app is opened. Changing it by hand does not rewrite the ids already issued. |
| `social_url` | *(empty)* | The app's own base URL (`…/apps/social/`), which every id is minted from and which profile links, WebFinger and NodeInfo use. Derived from `cloud_url` — never from the request — the first time the app is opened with `cloud_url` set, and again by `occ social:reset`. |
| `social_address` | *(empty)* | The hostname accounts are federated under, when it is not the host of `cloud_url`. Only set this if the fediverse address genuinely differs from the Nextcloud host, and only before the first account exists. |
| `service` | `1` | Unused; a leftover of the original installer. |
| `installed_version` | | Written by the upgrade machinery. |
| `media_usage` | *(written by the job)* | The last measurement of what is on disk, as JSON with the moment it was taken. Bookkeeping, not a setting: the walk is a `stat` per stored file and belongs in the cron, so the administration page reads this rather than counting on page load. |
| `directory_known` | *(written by the job)* | What `Cron\Cache` last found out about the servers the Discover page may ask: the `fediverse.info` server list and each federated peer's NodeInfo software, as JSON with when each was read. Bookkeeping, not a setting: the page reads this rather than asking those servers while somebody waits. |
| `polls_swept` | `0` | How far the closed-poll sweep has got, as a timestamp. |
| `stream_author_hosts_filled` | `0` | Set to `1` by `Cron\StreamAuthorHosts` once every post carries the host of its author in `social_stream.author_host`. Until then the domain-block and silenced-instance filters also match a post by its actor id, which is slower. Bookkeeping, not a setting. |
| `story_secret` | *(generated)* | The secret a 24-hour short's fetch capability (the bearcap Pixelfed fetches it with) is derived from, made the first time one is published. Changing it invalidates every outstanding capability at once, which is the only revocation it needs: a 24-hour short lives a day. Never set this by hand. |

### Server settings

Instance-wide settings. Most of them are on the **Server** section of the
settings page, which validates the ranges given here; every one can be set with
`occ`.

| Key | Default | Meaning |
|-----|---------|---------|
| `contact_email` | *(empty)* | Who to write to about this instance. Mastodon's `instance.email`: every client reads it on its first request and shows it on the server's about page. Empty until somebody fills it in, which until now most instances never did, because nothing said it existed. |
| `contact_account` | *(empty)* | The local Aloha Social account responsible for this instance. Choose a local account by username under Server settings; remote accounts and team accounts without a Nextcloud user are refused. Aloha Social stores the owning Nextcloud user id and resolves the current account when it builds `/api/v1/instance` and `/api/v2/instance`, so profile changes are reflected without rewriting the setting. Clearing the field omits the contact account. |
| `extended_description` | *(empty)* | The long form of what this instance is, for `/api/v1/instance/extended_description`. Up to 10000 characters. |
| `max_size` | `10` | The largest picture or file an upload may be, in MB. 1–10240. |
| `max_video_size` | `2048` | The largest video, in MB. 1–102400. A peer will refuse a great deal less than the ceiling. |
| `image_max_edge` | `0` | The longest edge a **stored** picture may have, in pixels. `0` stores every upload exactly as it arrived — the default, and the only setting that loses nothing: this app strips metadata losslessly and re-encodes only a picture it has to rotate. Set it (480–16384) on an instance where storage costs money or whose people post from a 48-megapixel phone. A picture already inside the ceiling is not re-encoded, because shrinking nothing and losing a generation anyway is the worst of both. |
| `image_quality` | `85` | What a re-encoded picture is stored at, 40–100. Only consulted when `image_max_edge` is set. |
| `video_transcode` | `1` | Whether videos uploaded here are re-encoded to H.264 in an MP4 by a background job; a video cached from another server is never touched. **On by default, and it does nothing where ffmpeg is not installed** — the *Aloha Social: video conversion* setup check says which it is. What it converts: `.mov`, WebM, Matroska and the other formats in `VideoTranscodeService::CONVERTIBLE`, and an MP4 whose video ffprobe does not report as H.264 (HEVC, which phones write and only Safari plays). An H.264 MP4 is never re-encoded; without ffprobe an MP4 is left as it is. Why: **Pixelfed's default `media_types` accepts `video/mp4` and nothing else**, so every `video/quicktime` posted from here, which is every video straight off an iPhone, is dropped by its inbox without a word to anybody, and Safari will not play WebM. **The converted file replaces the original, which is deleted** — re-encoding is lossy, so switch it off where the uploaded files must be kept exactly as they are. Never runs during an upload: converting a video is minutes rather than the seconds a poster frame takes, so the upload finishes as it always did. A **new post** carrying such a video is queued but **held back** while the video it carries is converted by a job of its own at the next cron run, and goes out naming the MP4 — for at most ten minutes (`VideoDeliveryHold::HOLD_SECONDS`), after which it is delivered as it is. Posts without such a video, and every post on a server without ffmpeg or with this off, are not held at all. Older videos are converted by a sweep, one every quarter of an hour, or `occ social:media:transcode` to work through a backlog now. An instance that never saved this key has it on after upgrading. |
| `video_max_height` | `1080` | The tallest a converted video is written, 240–2160. Only smaller, never larger: a 480p video is left at 480p. Only consulted when `video_transcode` is on. |
| `video_ladder` | `0` | Whether each stored MP4 is **also** written at a ladder of smaller sizes, as HLS, so a player can pick the one that fits the connection. A different question from `video_transcode`, which is about a video being playable at all elsewhere; this is about it being watchable on a phone on a train. Off by default, because it is several ffmpeg encodes per video on this server. Each rung is one file — `-hls_flags single_file` writes the rendition as a fragmented MP4 and the playlist addresses its segments as byte ranges — so a forty-minute video is three files rather than a thousand, which is also the shape PeerTube publishes. The original is kept and is what a player without HLS falls back to. Needs ffmpeg **and** ffprobe. One video every half-hour, or `occ social:media:ladder` to work through a backlog now. Built from `video/mp4` only: a `.mov` goes through the transcoder first. |
| `video_ladder_heights` | `360,720,1080` | Which heights, comma-separated, 144–2160. Heights at or above a video's own are skipped rather than upscaled, and the video's own height is always a rung, so the best rung is never worse than the file beside it. A list with nothing usable in it is refused by the admin card rather than silently replaced with the default. Only consulted when `video_ladder` is on. |
| `search_window_days` | `365` | How far back a content search scans while it cannot use its word index: before `Cron\SearchIndex` has indexed the posts stored before the upgrade (until `search_index_ready` is `1`), and for a query that is only the beginning of a word. Such a search is a range rather than one indexed word, and unbounded it reads every post the instance has ever stored; a year covers what anybody is looking for. `0` searches everything, which an instance small enough can afford to say. A search for whole words reads the index and is not bounded by this. |
| `local_actor_cursor` | `''` | Bookkeeping, not a setting: where the cron's local-account refresh walk got to. It used to read every local account into memory on every pass; it pages now, and this is what makes the next pass carry on rather than start again. |
| `profile_link_cursor` | `''` | Bookkeeping, not a setting: where the cron's walk over the local accounts whose profile fields carry a link got to, so the next pass checks the next ones. |
| `video_quota` | `0` | How many megabytes of video **one account** may keep here; `0` is no quota, which is what every instance has in effect today. A different question from `max_video_size`, which is a ceiling on one file: that is about a single request, this about a year of them. Off by default because an instance that has been running without a quota and acquires one on upgrade would start refusing uploads from exactly the accounts that use it most. Checked once, where an upload is written, against the size recorded on each stored file — so a video uploaded before this app recorded sizes counts as nothing until the daily usage job has been past it, which fills the column in as it walks. The **ladders this server builds do not count against it**: they are made because an administrator asked for them, are several times the size of the upload, and would turn a quota somebody was told about into one several times smaller. They are counted in what an administrator is shown, because they are real disk. Who is holding what is under **Administration → Aloha Social → Storage**. |
| `nsfw_policy` | `default` | What happens to media somebody marked sensitive, for readers who have not chosen for themselves. PeerTube's three NSFW policies under Mastodon's names for the same three states: `show_all` (PeerTube's *display*), `default` (its *blur* — covered, the blurhash showing, one press away, and what this app has always done) and `hide_all` (its *hide* — not drawn, and no button to draw it). Anybody can override it for themselves under **Settings** in the app, and "follow the instance" stays a state of its own, so changing this moves everybody who is following it and nobody who has chosen. A **content warning is a different thing** and always covers its post, whatever this says. |
| `review_videos` | `0` | Whether every post with a video on it waits for a moderator. The third rule of the review queue, beside a new account's first post and the spam rules, and the one an instance that hosts video wants: a video is minutes of somebody's attention and a great deal of somebody else's disk. **Unlike the other two it is not about the account** — a trusted account with a thousand posts behind it is held by it too, every time, because what it is about is the video. A moderator's own video is not held, and neither is a direct message. Under **Administration → Aloha Social → Posts waiting to be looked at**. |
| `inbox_throttle` | `300` | Incoming inbox requests allowed per origin host per minute. `0` accepts everything, which is what an instance behind its own rate limiter wants. |
| `rate_limit_user` | `900` | How many client API requests one **signed-in account** may make per window. The routes that fan out — search, the directories, the follow graph — carry limits of their own and Nextcloud enforces those; this is the budget for everything else, which was some three hundred routes with no limit at all. Generous on purpose: it exists to stop a scraper reading the whole instance at machine speed, not to pace an app. One budget for the whole API, not one per route. `0` switches it off, which is what an instance behind its own limiter wants. Federation, the internal queue and the routes that serve bytes (attachments, avatars, emoji, GIFs) are never counted — one public page is forty requests for pictures, and deliveries arrive in bursts from a handful of addresses. |
| `rate_limit_anon` | `300` | The same budget for a caller with **no account**, counted per address. |
| `rate_limit_window` | `300` | How long that window is, in seconds. |
| `token_max_days` | `365` | How many days an OAuth token works from the moment it was granted, **however much it is used**. A token idle for a year stops working anyway; this is about one in use, which used to live for ever — every request kept it alive, so a token copied off a phone lived as long as whoever held the copy kept using it. Past it the app is signed out and the person signs in again; there are no refresh tokens. Each person sees when under **Settings → Authorized apps**. `0` lets a token in use live for ever. |
| `follow_limit` | `100` | How many follows **one account** may send in an hour. A compromised account, or one running a script, can fan out follows to thousands of servers from this instance's address in a few minutes — every one a signed request this instance is answerable for. An hour rather than a day because what this catches is a burst, and high enough that importing a follow list from another server still goes through. Counted from the rows, so it holds on an instance with no memcache. `0` is no limit. |
| `domain_media_quota` | `0` | How many megabytes of media **one other server** may keep here. Every picture on a post somebody here follows is fetched and cached, and nothing bounded that by where it came from: one server posting large images at a high rate fills the disk of every instance that follows anybody on it. The server is the host of the file's own address (its `url`), which for a Mastodon instance is often a separate media host. Counted from the figure the daily storage walk takes, plus successfully cached bytes from that host since — rejected or unreadable downloads do not spend quota. The figure is up to a day coarse and errs towards refusing early. Off by default, because an instance that has been federating for a year and acquires a quota on upgrade would start refusing the pictures of the servers it talks to most. Who is holding what is under **Administration → Aloha Social → Storage**. |
| `secure_mode` | `0` | Refuse ActivityPub fetches that are not signed. Mastodon's secure mode. Turning it on makes this instance invisible to every peer that does not sign what it asks for, and to every anonymous reader; it is a decision about who to federate with, not a hardening step to apply by default. |
| `publish_blocks` | `0` | Publish the deny list on `/api/v1/instance/domain_blocks`, the way Mastodon does, so somebody choosing a server can see who it will not talk to. Whether *this* server wants that read by anybody is a disclosure decision. |
| `allow_self_signed` | `0` | Accept peers whose certificates do not check out. **Development only**: on a server anybody else uses, this hands every federated request to whoever can answer for the address. |

### Moderation and federation

| Key | Default | Meaning |
|-----|---------|---------|
| `access_type` | `all_but` | `all_but` — federate with everyone except the listed instances (block list). `none_but` — federate only with the listed ones (allow list). |
| `access_list` | `[]` | The instances on that list, as a JSON array of hostnames. An entry covers every subdomain of itself. Managed from the settings page and by `occ social:fediverse`. |
| `silenced_list` | `[]` | Instances whose accounts are kept out of the public and global timelines but stay readable for whoever follows them. The middle tier a block does not have. |
| `retention_days` | `0` | Remote statuses older than this that no local user interacted with, follows the author of, or replied below are deleted, with their cached attachments. `0` disables it. Local content is never touched. |
| `notification_retention_days` | `90` | In-app notification rows older than this are deleted by the same prune, whether or not `retention_days` is set. `0` keeps them for good. |
| `notification_policy_defaults` | *(calm)* | What the notification policy of a **new** local account starts as, as JSON with the five keys of Mastodon's policy, each `accept` or `filter`. Unset, it is the calm policy: people the account does not follow, accounts younger than 30 days, private mentions it did not ask for and accounts this server limited are **held for review** (no bell, push or mail; they wait in the account's requests), everyone else gets through. Read once, when the account is created — the first-run screen, `occ social:account:create`, an external user's sign-up and every other path go through the same `AccountService::createActor()` — so changing it **never touches an existing account**; accounts from before this setting existed keep receiving everything until their owner chooses. Written through `GET`/`POST /apps/social/admin/notification-policy` (administrators only, not delegates; any value but `accept` or `filter` is refused with a 422, because an administrator decides what waits for review, never what is thrown away), whose current value the settings page receives as `notificationPolicyDefaults`. |
| `federate_blocks` | `1` | Whether a user's own blocks are federated to the blocked account's instance. `0` keeps them local. |
| `publish_video_objects` | `0` | Whether a post that is a video is federated as an ActivityPub `Video` (PeerTube's shape) rather than a `Note` with an attachment. Off by default: Pixelfed's inbox handles only `Note`s and silently drops a `Video`, so with this on no video posted here reaches a Pixelfed follower. Mastodon draws both shapes; PeerTube draws only the `Video`. Turn it on for an instance whose audience is on PeerTube. |
| `network_stats` | `1` | Whether the statistics page may ask [FediDB](https://fedidb.org) how big the fediverse is — servers, accounts, accounts that posted in the last month — to show beside the number of servers this one federates with. One request every six hours for the whole instance, carrying no account, no query and nothing about this instance; a survey that does not answer is left alone for half an hour. Set it to `0` and the section is absent rather than zeroed: an instance that makes no outbound request to draw a page is a legitimate thing to want. The same value governs the **growth** section below it, which asks [Fediverse Observer](https://fediverse.observer) for the last two years month by month — FediDB publishes a snapshot and no history, so the shape over time comes from a second survey, once a day. The two do not agree about the totals, because they crawl different servers and count dormant accounts differently, so the page keeps them apart and names each: a page that averaged them would produce a number neither survey would stand behind. |
| `rules` | *(empty)* | The instance rules shown by `/api/v1/instance/rules`, one per line. |
| `review_first_post` | `0` | Hold the first post of an account that has published nothing here yet, for a moderator to see before it goes out. Off by default; turn it on where accounts are handed to strangers. An administrator's own posts are never held — a moderator waiting on themselves is a circle, and on a new instance the first post is theirs. |
| `review_posts` | `1` | How many posts an account must have had published before its posts stop being held. `1` is first-post review as it has always meant. An account graduates by having that many posts approved — a person having looked at it that many times, which is the only measure of trust here that is not a guess. Capped at 20. |
| `autospam` | `1` | Hold a post that trips one of the spam rules — more than five links in a short post, or more than five mentions from an account nobody follows and that follows nobody. Nothing is ever refused by the rules, only shown to a person. |
| `ai_tags` | *(empty)* | Hashtags that label a post as **made with AI**, comma-separated, added to the built-in list (`#ai`, `#aiart`, `#aigenerated`, `#aigeneratedart`, `#aiimage`, `#aiphoto`, `#aivideo`, `#genai`, `#generativeai`, `#madewithai`, `#midjourney`, `#stablediffusion`, `#dalle`, `#dalle3`, `#sora`, `#fluxai`, `#comfyui`). Compared without the `#` and without regard to case; a built-in tag cannot be removed. A reader who turns on **Hide posts made with AI** under Settings → Blocking is shown no post carrying one of these tags, and no post whose picture states machine-generation provenance in its metadata (IPTC `DigitalSourceType` of `trainedAlgorithmicMedia` or `compositeWithTrainedAlgorithmicMedia`, in XMP or inside a C2PA manifest — read when the picture is stored, before the metadata is stripped). **What this can and cannot do:** it recognises *labels* — a hashtag the author or their tool added, a provenance mark a generator wrote into the file — and nothing else. A generated post that carries neither is not recognised; a real photograph tagged `#ai` by its author is. There is no classifier here and there should not be one. |

### External users

Set from the External users cards; these are the keys they write.

| Key | Default | Meaning |
|-----|---------|---------|
| `external_enabled` | `0` | Whether people without an account may register one for Aloha Social. |
| `external_max` | `100` | How many external accounts may exist. |
| `external_media_quota` | `1024` | Megabytes of media each external user may upload. `0` is no quota. |
| `external_signup_mode` | `approval` | `open`, `approval` or `invite`. |
| `external_verify_email` | `1` | Whether the email address is confirmed before the account is made. |
| `external_min_age` | `16` | The age a person confirms at registration. `0` asks nothing. |
| `external_reserved` | `[]` | Usernames nobody may register, as a JSON array, on top of the built-in ones. |
| `external_user_invites` | `0` | Whether every user may send invitation links. |

### System configuration

Two `config.php` values, neither documented anywhere else:

| Key | Default | Meaning |
|-----|---------|---------|
| `social.checkssl` | `true` | Whether the WebFinger setup check verifies the certificate it is offered. Set it to `false` on an instance using a certificate the server itself does not trust. It affects the probe only — federation still verifies. |
| `social.tests` | *(unset)* | Enables `GET /apps/social/test/{account}/`, a WebFinger self-test. Leave it unset outside development. |

And one of Nextcloud's own that matters here: `allow_local_remote_servers`,
which must be on for an instance to federate with anything on a private
address. That is a development arrangement.

---

## Commands by task

The full reference is [OCC-Commands.md](OCC-Commands.md); these are the ones an
administrator reaches for.

**Is it working?**

```bash
occ social:check:install            # the setup checks it can run from the console, plus repairs
occ social:check:install --offline  # the same without the network probe
occ social:queue:status             # what the outbound queue is doing
occ social:details <id>             # who can see one post and where it lands
```

Aloha Social also repairs the recipient and hashtag side indexes automatically in
bounded five-minute cron passes. Each pass visits at most 500 streams, stores
its last fully indexed NID, and resumes from there; a failed row is retried on
the next passes, and after five failures it is logged as skipped and the walk
moves on. Once the walk reaches the newest post the repair is complete
(app config `index_done` = `1`) and the job does nothing more; deleting that
key runs it again from the stored cursor. Keep Nextcloud background jobs
working as `Cron\Index` relies on them. The manual `occ social:check:install --index --force` remains a full
rebuild for administrators; it clears and repopulates both indexes and should
not be scheduled as a cron command.

**External users**

```bash
occ social:external list                                        # who, and how many of how many
OC_PASS=... occ social:external add <handle> --email EMAIL --password-from-env
occ social:external promote <handle>                            # make one an ordinary account
```

**Delivery is behind**

```bash
occ social:queue:process            # drain it now instead of waiting for cron
occ social:queue:retry              # give failing deliveries the full run of retries again
```

**Moderation**

```bash
occ social:fediverse list                  # the access list; bare, it prints the mode
occ social:fediverse add <instance>        # block it (or allow it, in allow-list mode)
occ social:fediverse remove <instance>
occ social:fediverse import <csv_file>     # add reviewed CSV domains to a block list
occ social:fediverse import <csv_file> --dry-run   # print what it would add, change nothing
occ social:fediverse silence <instance>    # out of the public timelines, still followable
```

`import` reads the first CSV column, accepts a `#domain`/`domain` header or a
plain one-domain-per-line file, validates the complete file before changing
anything, and adds the domains to the existing `all_but` block list. It refuses
allow-list mode, does not fetch or enable a list on its own, and applies the
normal audit and queued domain-purge behavior to every new entry.

The same import is on the admin page under **Block lists**, with a file picker
and a preview: it reads the file, says how many servers it would block and how
many it would silence, and changes nothing until the button under that number
is pressed. The page and the command run the same code, so a list imported one
way cannot turn out to have been read differently the other.

**Following a published list.** The same section offers two sources, both off
until an administrator turns one on:

| Source | What it is |
|--------|------------|
| `mastodon.social` | that server's own moderated-servers list, through Mastodon's `/api/v1/instance/domain_blocks` |
| `thebad.space` | a shared list rather than one server's, as a CSV export by how many of its participating servers agree. The default is `/80`: what eighty per cent of them block |

A Mastodon server answers `/api/v1/instance/domain_blocks` only where its
administrators turned publishing on, and most have not — which is why one such
server is offered rather than a row of them. Point either source at another
server's endpoint to follow that one instead; a server that does not publish
answers "that server did not hand over a list" rather than failing.

A followed source is re-read once a day by `OCA\Social\Cron\BlocklistSync`.
**Check now** reads one immediately; for a source that is off it reports what
following it *would* do, which is how to read a list before adopting it.

Turning a source off stops it being re-read. It does not undo what it already
applied: a block purges what this instance held of that server, and that does
not come back.

**Severity is honoured.** A row marked `suspend` blocks the server; a row
marked `silence` silences it — out of the public, global and hashtag timelines,
still followable — and a row marked `noop` does nothing. Reading the column and
blocking everything would delete what this instance holds of servers the source
merely limits: of mastodon.social's 276 entries, 30 are silences, and of The
Bad Space's 200 at the 80% threshold, 2 are. A list with no severity column is
a list of blocks, which is what a bare one-domain-per-line file has always
meant here.

Every entry added queues a domain purge, which deletes what this instance holds
of that server, so the import asks before it writes — `--no-interaction` skips
the question, and `--dry-run` prints the domains and changes nothing. A row
naming a single label (`com`, `localhost`) is refused, because an entry covers
every subdomain of itself and one such row would block a whole top-level
domain; so is a row naming this instance. That is not a public-suffix check:
`co.uk` still passes, which is what `--dry-run` is for. Files over 8 MB are
refused unread. An
administrator can download and review a [The Bad Space CSV export](https://tweaking.thebad.space/exports)
before importing it; the export source and moderation policy remain the
administrator's choice.

**Housekeeping**

```bash
occ social:stream:prune             # apply retention, purge finished queue rows and old notifications
occ social:cache:refresh            # re-fetch cached remote accounts
occ social:domain:purge <instance>  # remove what a now-blocked instance sent
```

**Starting over**

```bash
occ social:reset                    # empties every Social table; asks twice
occ social:reset --uninstall        # and drops the tables, jobs and app config
```

---

## Running a large instance

Everything below is optional and nothing on a small instance needs any of it.
These are the three things that stop working first as an instance grows, and
what to do about each.

**Install `notify_push`.** Without it every open tab asks the server for the
home timeline and the unread count **every thirty seconds**; with it the server
tells the client instead and the poll drops to once every five minutes — a
tenfold cut in the request volume, for one app install. The polls also answer
`304` now when nothing has changed, so even without it most of them cost an
index probe and an empty response rather than a rendered page.

**Run delivery workers.** `Cron\Queue` delivers twenty servers at a time and
takes batch after batch for its 240 seconds: with peers that answer within a
second that is up to 6,000 deliveries a run, some 30,000 an hour, and when every
batch runs into a dead peer's 30-second timeout still about 160 a run. A peer
that fails is left alone — from a minute, doubling to an hour — and that is
kept in the database (`social_host_breaker`), so it holds without a memcache and
across runs: a dead server costs one timeout per wait, not one per row. An
instance whose accounts are followed across tens of thousands of servers still
outgrows that with a popular post, and `occ social:worker` is the same delivery
in a loop that does not stop; **several may run at once**, see
[OCC-Commands.md](OCC-Commands.md) for a systemd unit. The worker still delivers
one row at a time, so it is more processes, not wider ones, that add capacity.

**Watch the cron actually finish.** The steps are budgeted at 300 seconds a pass
and resume where they stopped, so a pass that runs out of time is normal. A pass
that *never* reaches the later steps is not: `social:check` and the Nextcloud log
are where that shows.

**Cached remote accounts** are refreshed by `Cron\Cache` for up to 90 seconds
a pass, and their details (counts, pinned posts, verified links) for up to 90
more, batch after batch of fifty, the longest-waiting first. A refresh is one
fetch, so at a third of a second to a second each that is 90 to 300 accounts a
pass, 11,000 to 36,000 a day — enough for the ten-day refresh cycle to hold
over roughly 110,000 to 360,000 cached accounts, where one batch of fifty a
pass held to about 60,000. A details refresh is three or more fetches, so it
covers fewer, 30 to 90 a pass. Past those sizes the cycle stretches and the
stalest still go first; a key that rotated in between is fetched again the
first time a signature fails to verify.

**Plan the upgrade window on a large instance.** Three upgrade steps rewrite
`social_stream` row by row, and they run inside `occ upgrade`, while the server
is in maintenance mode — there is no way to defer them:

- `Version1000Date20260917000001` copies each post's sort key onto every one of
  its recipient rows in `social_stream_dest`;
- `Version1000Date20260917000003` reads every post's attachments to record what
  kind of media it carries;
- `Version1000Date20260917000005` reads every post's content to record whether
  it is a news item.

Each walks the table 5,000 posts to a statement, after a `COUNT(*)` over it, and
prints its progress every 50,000. The time is proportional to the number of
posts — for the first step to the number of recipient rows, which is several per
post — and to how fast the database rewrites rows; none of it has been measured
on a large instance, so time it on a copy of the database first. As a rough
rule of thumb rather than a measurement, expect minutes per million posts for
each of the three, and so hours of maintenance mode for ten million. The media
and news steps only touch rows that still carry no value, so an upgrade
interrupted during them picks up where it stopped; the first starts again from
the beginning.

Two settings exist for size and are listed above: `search_window_days` bounds
what a content search scans when it cannot use its word index, and `retention_days` bounds what cached remote
media costs. Both trade completeness for a bounded cost, and the default of each
is the one a medium instance wants.

`occ social:benchmark` seeds a realistic amount of content and times the
timeline queries against it, which is the only honest way to find out what any
of this costs on the hardware in front of you.

## What to watch

- **Administration → Overview.** The setup checks above are there precisely so
  that an administrator who never opens Aloha Social still hears about it.
- **The delivery queue.** A rising count of failing deliveries against one host
  is that instance's problem; a rising count against all of them is this one's.
- **`social.log` / the Nextcloud log.** Signature verification failures on
  inbound requests, and `could not federate` warnings on outbound ones.
- **Disk.** Cached remote attachments are the largest thing this app stores.
  `retention_days` is what bounds them; on an instance that federates widely,
  leaving it at `0` means keeping every picture anybody here ever scrolled
  past.
- **The audit log**, if `admin_audit` is enabled: every moderation decision,
  with the moderator who took it.
