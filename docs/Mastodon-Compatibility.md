<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Mastodon client and federation compatibility

Aloha Social implements a broad portion of the Mastodon client API and speaks
ActivityPub to Mastodon and other Fediverse servers. It is **not a drop-in
Mastodon server**. This page summarizes the practical compatibility boundary;
the route-by-route reference is [API.md](API.md).

## Mastodon clients

The client API supports common account and profile operations, timelines,
publishing, media uploads, replies, polls, likes, boosts, notifications, lists,
search, moderation and OAuth. An optional streaming daemon serves supported
WebSocket and SSE streams. Consult the API tables for exact methods, scopes,
request shapes and any endpoint that is partial or unavailable.

Most Mastodon clients assume the API is at the domain root:

- `/api/...` must reach Social's client API;
- `/oauth/...` must reach Social's OAuth endpoints;
- `/xrpc/...` must reach the Bluesky service when that integration is enabled.

The app is mounted inside Nextcloud, so a web-server rule is required for those
root paths. The Apache/nginx examples and setup check are in
[`contrib/webserver/`](../contrib/webserver/) and
[Admin.md — Mastodon apps cannot connect](Admin.md#mastodon-apps-cannot-connect).
Browser-based clients also depend on the documented CORS behavior.

### Streaming

Streaming is optional and separate from ordinary REST requests. The
administrator must run `occ social:mastodon:stream` as a managed service and
proxy the streaming route as a WebSocket where applicable. Supported WebSocket
and SSE streams, health checks and authorization are documented in [API.md —
Streaming](API.md). If the daemon or proxy is unavailable, clients
should use REST polling; a successful REST request does not prove that the
stream is connected.

Mastodon Web Push subscription endpoints are part of the API, but delivery also
depends on client subscription, VAPID configuration, outbound connectivity and
the push provider. Nextcloud's own notification channel is a separate feature.

### Intentional differences

- Registration and core account credentials belong to Nextcloud. Social does
  not replace Mastodon's full sign-up, email or password-management system.
- Mastodon-specific endpoints may be absent or return a documented partial
  result. `API.md` is the source of truth for the current route surface; a
  route being registered does not guarantee identical Mastodon semantics.
- Some Aloha features have no Mastodon equivalent, and some Mastodon client
  features have no Aloha web interface.
- Public client API compatibility does not imply that Aloha can take over an
  existing Mastodon server's actor IDs, keys, host domain or followers in place.

## ActivityPub federation

Mastodon peers communicate with Aloha Social through signed ActivityPub
activities and WebFinger actor discovery. Supported posts, visibility,
interactions, media, polls, edits and deletions are handled by the shared
ActivityPub implementation, not by a Mastodon-only transport. See
[Federation-Compatibility.md](Federation-Compatibility.md) for outbox imports,
object types and remaining remote-profile boundaries.

Federation is asynchronous. A post accepted locally may still be waiting in
Nextcloud's delivery queue, refused by the remote server, or not displayed by a
particular Mastodon view. Check the delivery queue and the remote server's
response before describing a delivery as accepted. See
[Admin.md — delivery troubleshooting](Admin.md#deliveries-are-stuck).

## Moving accounts

The Fediverse account-move protocol is supported where the source and target
servers support ActivityPub `Move` and `alsoKnownAs`. A move redirects the
follow relationship; it does not transfer the old server's database, keys,
post identifiers or full archive. Aloha Social's export/import tools can bring
supported account data and media into a local account, but imported posts use
local identifiers. See [Moving.md](Moving.md).

Replacing an existing Mastodon instance while keeping its domain, actor URLs,
keys and post IDs is a different migration problem and is not supported by a
normal account move. Aloha Social's ActivityPub identities are minted under
its own URL space. Do not change the configured public URL after identities
have been federated; see [Admin.md](Admin.md#the-address-aloha-social-is-set-up-for).

## Pixelfed and other servers

Pixelfed, PeerTube, GoToSocial, Akkoma and other implementations share
ActivityPub but have different client APIs and extensions. Aloha Social
supports interoperability for common posts and interactions; it does not
implement each product's entire server API. For concrete behavior, see the
[integration matrix](Integrations.md) and
[remote profile compatibility](Federation-Compatibility.md).

## Verification

Unit and integration tests validate local behavior. The optional
[`tests/Interop/`](../tests/Interop/) suite exercises real Mastodon and
PeerTube instances when configured; a skipped test is not evidence of a live
exchange. For production issues, record the remote host, activity or request,
delivery state, response status and relevant time before deciding whether the
problem is local or remote.
