# Native ATProto PDS in Nextcloud Social

Status: draft, 2026-10-07. The PDS runs inside Social: Nextcloud owns identities,
sealed signing keys, SQL repositories, image blobs and publishing. Posts use the
existing Social composer, timeline, profiles and interaction controls. Users do
not need a separate PDS or an existing Bluesky account.

The publishing path has passed a real isolated Nextcloud → official PLC directory
→ official Indigo relay test, including HTTPS, binary WebSocket events and signed
repository verification with the official JavaScript SDK. This does not establish
public Bluesky AppView indexing or production deployment on your domain.

## Requirements

- Supported Nextcloud with PHP 8.3+, GMP, Sodium, Intl and GD.
- Dependencies from the committed Composer lockfile; install using `composer install`.
- Public HTTPS for the Social instance, e.g. `social.example.org`.
- Wildcard DNS and TLS for account handles, e.g. `alice.social.example.org`.
  Route their `/.well-known/atproto-did` requests to this same Nextcloud app,
  preserving the requested host. Include these hosts in Nextcloud's trusted domains.
- Nextcloud system cron. Publication is queued and retried by the registered
  `AtprotoOutboundWorker` job (60-second eligibility; actual delay depends on cron).
- The app's firehose command running continuously behind a WebSocket proxy.

Choose and verify the public domain **before** issuing identities: it becomes part
of their PLC document. Changing the instance address is not an account migration.
Back up the database, app data and Nextcloud instance secret together; the secret
is required to decrypt the stored keys.

## Install and enable

Install the updated app and run the normal Nextcloud upgrade process (`occ upgrade`).
The app migrations create the `social_atpds_*` native tables, preserving older
`social_atproto_*` external-account connector data; do not manually execute nonexistent
`Version000040` or `Version000041` migrations from earlier design notes.

Configure Social's cloud/social address through its existing administration setup.
Enable the native feature from the app's administrator API or with:

```sh
php occ config:app:set social atproto_enabled --value=1
# Optional dedicated PDS origin; defaults to the HTTPS origin of Social:
php occ config:app:set social atproto_pds_url --value=https://pds.example.org
```

Run commands as the operating-system user that owns Nextcloud. On installations
using `www-data`, administrators usually use `sudo -u www-data php occ ...`.

The signed-in user's Social settings contain the native identity section. An
identity can also be created on the first public native publication. PLC failures
leave a persisted pending identity and queued post for retry; they do not report
successful registration. To provision a specific existing Social user:

```sh
php occ social:atproto:identities --user=alice
php occ social:atproto:repo alice --verify
```

Save the recovery phrase securely when retrieving it from settings: it is shown
once. It is 24 BIP39 words, representing a 256-bit recovery key.

## Root routing and firehose

ATProto clients require root `/xrpc/...` and `/.well-known/...` paths. App routes
under `/index.php/apps/social/...` alone are insufficient. Adapt the templates
in [contrib/webserver](../contrib/webserver/) to your actual backend address.
Use a dedicated loopback Nextcloud HTTP listener (for example port 8081), rather
than proxying recursively to the public virtual host. Preserve Host, query strings
and forwarding headers; configure Nextcloud's trusted proxies.

- Proxy `/xrpc/com.atproto.sync.subscribeRepos` to the firehose on port 8080.
- Proxy other `/xrpc/*` to `/index.php/apps/social/xrpc/*` on the Nextcloud backend.
- Proxy `/.well-known/atproto-did` and `/.well-known/did.json` to their app routes.
- Do not put login redirects in front of these public relay endpoints.

```sh
php occ social:atproto:serve --host=127.0.0.1 --port=8080
```

Use your existing supervisor or a systemd service, for example:

```ini
[Unit]
Description=Nextcloud Social native ATProto firehose
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/html/nextcloud
ExecStart=/usr/bin/php occ social:atproto:serve --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

This command serves the app's database-backed WebSocket stream. It does not run
an external Node PDS. Cursor retention is 72 hours; consumers outside that window
must fetch current repositories again. Large commits use signed `#sync` events.

## Publish and verify

In the ordinary composer, select **Fediverse**, **ATProto**, or **both**. Native
publication is available for public posts. Private, followers-only and unlisted
posts stay out of the native repository. Transport selection survives draft and
scheduled-post storage. Text, replies, quotes and up to four images with alt text
are mapped to native records; unsupported media/polls link to the Social post.

```sh
curl https://social.example.org/xrpc/com.atproto.server.describeServer
curl https://alice.social.example.org/.well-known/atproto-did
curl 'https://social.example.org/xrpc/com.atproto.sync.getRepoStatus?did=YOUR_DID'
php occ social:atproto:publish
php occ social:atproto:repo alice --verify
php occ social:atproto:crawl
```

Verify from outside the server that DNS/TLS and root routing work, and that a
relay connects to `wss://social.example.org/xrpc/com.atproto.sync.subscribeRepos`.
Then verify the post through the public AppView or Bluesky. Repository acceptance
by a relay and indexing by the public AppView are distinct checks.

## Local testing and missing OCC commands

Run commands from the Nextcloud server directory, not `apps/social`. The HTTP
PDS endpoints run through Nextcloud's existing PHP web server. The `serve` command
starts only the persistent WebSocket firehose; `publish` executes a bounded pass
of the durable publication queue. Neither starts a separate external PDS.

```sh
cd /var/www/html/nextcloud
php occ list social:atproto
php occ social:atproto:serve --host=127.0.0.1 --port=18089 --max-seconds=10
php occ social:atproto:publish
```

If OCC says there are no commands in `social:atproto`, the installed app does not
contain this draft's code, the app is disabled, or its dependencies are missing.
Check `occ app:list`, the installed app's `appinfo/info.xml` command registration,
install the draft code and Composer dependencies, then run `occ upgrade`.
Building an isolated source checkout alone does not update the installed app.

For a private local test, use a dedicated test HTTPS origin such as `pds.test`,
a locally trusted certificate, root XRPC/well-known routing and a **test** PLC
directory configured using `atproto_plc_directory`. Resolve handle subdomains to
the test proxy too. A LAN IP alone is not a usable ATProto handle domain. Do not
register local-only `pds.test` identities in the public PLC directory. Posts and
identities in this private realm are test data; public Bluesky indexing requires
a reachable public origin and registration in the public directory.

See the [interoperability test instructions](../tests/Interop/atproto/README.md)
for the reference SDK verification and the isolated PLC/relay acceptance checks.

## Scope and remaining work

The current priority is publishing from Nextcloud's own Social account and PDS.
External Bluesky login, OAuth and authenticated third-party PDS client writes are
not implemented as a complete client interface. Account migration, video hosting,
full moderation service integration and instance signing-key migration remain
outside the validated publishing path. No app-password login, relay-lag metric or
automatic DNS/TLS configuration is provided.

Shared inbound search/feed polling and authenticated notification import use the
Bluesky AppView. Their public-network acceptance still needs a publicly registered
native identity. Jetstream is optional; polling does not require its daemon.
See [ATProto Compatibility](Atproto-Compatibility.md), [API](API.md), and the
[reference interoperability check](../tests/Interop/atproto/README.md).
