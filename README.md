<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="img/social.svg">
  <img src="img/social-dark.svg" alt="Aloha Social" width="72">
</picture>

# Aloha Social

**A federated social app for Nextcloud.** Post and read across ActivityPub services,
and optionally host native AT Protocol accounts, from the same Nextcloud instance.

</div>

![Aloha Social timeline](img/readme/home.png)

Aloha Social gives Nextcloud users a Fediverse identity and connects it to other
ActivityPub servers. Administrators can also enable Bluesky/AT Protocol support.
These are separate protocols with separate identities and services; see
[Integrations](docs/Integrations.md) for what works and where the limits are.

## What it supports

| Area | Supported features | Important limits |
|---|---|---|
| ActivityPub | Profiles, follows, posts, replies, edits, deletes, likes, boosts, polls, warnings, media and federated timelines | Remote delivery and imports depend on the other server, network access and scheduled Nextcloud jobs. |
| Mastodon clients | A broad Mastodon-compatible API, OAuth, app passwords, media uploads, notifications and timelines | This is not a full Mastodon replacement. Root `/api` and `/oauth` proxy rules are needed for many clients; some optional API features are not implemented. |
| Bluesky / AT Protocol | Optional hosted identities and PDS/XRPC, posts, profiles, follows, likes, custom feeds, lists, starter packs, chat proxying and synchronization | Requires administrator setup and reachable PLC, relay, AppView and (for chat) chat services. A reachable endpoint does not guarantee indexing or remote availability. |
| Pixelfed | Federated photo posts and media, profile collections and supported story flows | Pixelfed-specific app/API behavior is partial; it is not a complete Pixelfed server replacement. |
| PeerTube | Read and publish federated video posts, channels, playback and video metadata | PeerTube's complete creator, moderation and administration APIs are outside scope. Video processing depends on server configuration. |
| Events | ActivityPub event cards and one-click import to Nextcloud Calendar where available | Calendar import copies an event; it is not an RSVP or attendance action. |
| Other Fediverse services | Compatible ActivityPub profiles, posts and interactions; recognized Articles, Pages, audio, video, polls and events | Rendering and actions depend on the remote server's object types and extensions. Lemmy/PieFed community moderation and voting are not full-featured here. |

The web app also includes home, local and federated timelines, Photos and Videos
feeds, Shorts, search, lists, notifications, moderation controls and an optional
For You feed. See the [user guide](docs/User-Guide.md) for the web experience.

## Integrations and documentation

| Document | Use it for |
|---|---|
| [Integration support matrix](docs/Integrations.md) | Supported networks, data flows, dependencies and known limits |
| [User guide](docs/User-Guide.md) | Posting, reading, accounts, settings and everyday use |
| [Administration](docs/Admin.md) | Setup checks, federation, Bluesky, media, moderation and configuration |
| [API reference](docs/API.md) | Mastodon-compatible, local and Bluesky/XRPC endpoints |
| [ActivityPub compatibility](docs/Federation-Compatibility.md) | Remote profile import behavior and known federation boundaries |
| [Mastodon compatibility](docs/Mastodon-Compatibility.md) | Client API coverage and intentional differences |
| [AT Protocol compatibility](docs/Atproto-Compatibility.md) | PDS/XRPC behavior, identity and protocol limits |
| [Events and Calendar](docs/Events.md) | Event cards, ICS import and supported behavior |
| [For You](docs/For-You.md) | Recommendation design, privacy and controls |
| [Architecture](docs/Architecture.md) | Services, storage, queues and protocol flows |
| [Performance](docs/Performance.md) | Current CI query measurements and benchmark limits |
| [Technical debt](docs/Technical-Debt.md) | Current maintenance inventory and priorities |
| [OCC commands](docs/OCC-Commands.md) | Command-line administration and maintenance |
| [Security policy](SECURITY.md) | Reporting a security issue |

## Install

Aloha Social supports Nextcloud **34–36** and PHP **8.3–8.5**. Install it from
the Nextcloud App Store, or place the app in the Nextcloud `apps/` directory and
enable it with `occ app:enable social`. Then run the setup check:

```sh
occ social:check:install
```

For federation, configure a stable public URL, HTTPS, `.well-known` redirects,
outbound HTTPS access and system cron. For Mastodon clients, configure the
[shipped root API/OAuth web-server rules](contrib/webserver/). Bluesky hosting
is optional and has additional requirements. Follow [Admin.md](docs/Admin.md) before exposing a
new instance publicly.

## Develop

```sh
composer install
npm ci
npm run build
```

Enable the app in a development Nextcloud instance. If that instance also
runs apps such as Mail, keep Social's development Composer autoloader out of
the live app runtime: Psalm's development dependencies include Amp 3, which
can collide with the Amp version used by Mail and make all of Nextcloud return
HTTP 500. After installing development dependencies, rebuild Social's runtime
autoloader with:

```sh
composer dump-autoload --no-dev --no-scripts
```

That leaves the installed packages in place but excludes development packages
from Nextcloud's autoload. Run PHP tests in a separate development checkout, or
restore the development autoloader there with `composer dump-autoload --dev`;
do not leave that autoloader active in the shared Nextcloud instance. See
[Admin.md](docs/Admin.md#empty-application-after-installing-development-dependencies)
for recovery steps. Frontend watch mode is `npm run watch`. Useful checks are:

```sh
composer run test:unit
npm test
npm run lint
npm run stylelint
npm run typecheck
```

`make check-pr` optionally runs the local PHP/JavaScript checks and rebuilds
the shipped bundles, then checks that the generated `js/` files are committed.
Database integration and real-peer interoperability remain separate CI checks.

Integration and browser tests require a running Nextcloud instance; see
[`tests/Integration/README.md`](tests/Integration/README.md) and
[`tests/e2e/`](tests/e2e/). To build a release archive, run `make appstore`.

## License

Aloha Social is licensed under [AGPL-3.0-or-later](LICENSES/AGPL-3.0-or-later.txt).
Third-party assets and dependencies keep their own licenses; see their
`*.LICENSE.txt` files and [`REUSE.toml`](REUSE.toml).
