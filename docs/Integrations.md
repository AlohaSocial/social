<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integrations and compatibility

This page is the short, user-facing map of Aloha Social's integrations. It
describes implemented paths and their practical boundaries; it is not a claim
that every remote server or client has been tested. Network availability,
remote policy and server configuration still affect live behavior.

## At a glance

| Integration | Status | What Aloha Social can do | Key boundary |
|---|---|---|---|
| ActivityPub / Fediverse | Core | Host local identities; discover accounts; follow; publish, receive and read posts; reply, edit, delete, like and boost; process media, polls, warnings and supported object types | Federation is asynchronous. Delivery, profile completeness and media retrieval depend on remote servers and background jobs. |
| Mastodon clients and API | Broad compatibility | OAuth and app-password sign-in, accounts, timelines, posts, media, notifications, search and common interactions | Not complete API parity. Many clients need the web-server rules that expose `/api` and `/oauth` at the domain root. Streaming and optional endpoints have separate limits. |
| Bluesky / AT Protocol | Optional, native hosting | Host an AT Protocol identity and repository; support sessions/OAuth and XRPC; publish and read supported records; synchronize with relays and proxy AppView/Chat methods | AT Protocol is independent of ActivityPub. PLC, relay, AppView and Chat availability, setup and indexing are separate dependencies. Not every Lexicon or account-management method is supported. |
| Remote Bluesky accounts | Supported read/interactions | Resolve profiles and author feeds, use saved feeds/lists and starter packs, and exchange supported interactions and chat through configured services | AppView and Chat proxying is not the same as operating those Bluesky services. Their responses and moderation decisions remain external. |
| Pixelfed | Partial interoperability | Exchange ActivityPub photo posts and supported profile/media/story flows | Pixelfed's app-specific APIs and complete server behavior are not implemented as a full Pixelfed replacement. |
| PeerTube | Federated video support | Read remote videos and channels, play remote media through the supported proxy path, and publish supported local ActivityPub video objects | PeerTube's complete Studio, upload, account and moderation APIs are outside scope. Transcoding and HLS depend on optional server tools/settings. |
| Events (including Gancio/Mobilizon) | Event display and calendar import | Read supported ActivityPub event metadata, show event cards and import an `.ics` copy to Nextcloud Calendar when that integration is available | Importing is a calendar copy, not an RSVP. Federated attendance and event editing are not claimed unless a specific action is shown by the API. |
| Lemmy, PieFed and other communities | Partial ActivityPub interoperability | Display compatible actors and post types, including supported Page/community content | Community creation, roles, moderation, voting rules and full client workflows are not a Lemmy/PieFed replacement. |
| Nextcloud Calendar | Optional local integration | Add supported event posts to a writable calendar; an ICS download can be used where direct import is unavailable | Requires the Calendar app and a writable calendar. It does not send an event response to the origin server. |

## Protocol boundaries

### ActivityPub

The Fediverse identity, inbox/outbox, delivery queue and remote actor model are
ActivityPub. Remote profile reads can queue a bounded outbox import; cached
posts are served while that work proceeds. Anonymous profile reads use cached
data and do not initiate arbitrary remote fetches. The importer handles the
object and activity types listed in
[Federation-Compatibility.md](Federation-Compatibility.md), subject to the
remote server's outbox format and availability.

Set up HTTPS, a stable public URL, `.well-known` redirects, outbound HTTPS and
system cron. See [Admin.md](Admin.md#before-it-federates) and the
[remote profile notes](Federation-Compatibility.md).

### Mastodon-compatible clients

Aloha Social exposes a wide Mastodon-compatible client API, but compatibility
is not identical to running Mastodon. Check
[Mastodon-Compatibility.md](Mastodon-Compatibility.md) for endpoint families,
intentional differences and unsupported areas. Root `/api` and `/oauth`
rewrites are a web-server concern; see
[Admin.md](Admin.md#mastodon-apps-cannot-connect).

### Bluesky and AT Protocol

An administrator can enable native AT Protocol hosting. Each local identity
has its own DID and repository alongside its ActivityPub identity. A post may
be published through both protocols, but identities, authentication, records,
moderation and synchronization remain protocol-specific. Users can disable
their Bluesky presence without disabling ActivityPub.

The local XRPC surface handles supported PDS operations; `app.bsky.*` and
`chat.bsky.*` calls that Social does not host are proxied to configured remote
services. A successful proxy response does not prove that a post is indexed in
every AppView or visible in every Bluesky client. See
[ATProto-Compatibility.md](Atproto-Compatibility.md),
[Admin.md — Bluesky](Admin.md#bluesky), and the
[live-host verification guide](Atproto-Live-Test.md).

### Nextcloud services

- **Calendar:** import a supported event into a local writable calendar. This
  creates a calendar copy; it does not announce attendance to Gancio, Mobilizon
  or another event service. See [Events.md](Events.md).
- **Background jobs:** delivery, remote profile imports, feed synchronization,
  scheduled posts, retention and media processing rely on Nextcloud cron.
- **Files:** supported replies can be exposed as comments through the existing
  Nextcloud Files integration. This does not change who can read the social
  post itself.

## What “supported” means

The table distinguishes four things that are easy to confuse:

1. **Protocol support** means Aloha Social implements a local ActivityPub or
   AT Protocol path.
2. **Client compatibility** means a third-party app can use an API surface;
   it does not imply every client feature works.
3. **Remote-service proxying** means a request is forwarded to that service;
   it does not make the service part of this installation.
4. **Live interoperability** requires a successful exchange with a real remote
   server. Unit tests and HTTP 200 responses alone do not establish that.

Use the API reference for exact routes and parameters. For implementation
details, consult [Architecture.md](Architecture.md); for operator setup, use
[Admin.md](Admin.md). If a reported failure may be environmental, record the
request URL, status and response body, then compare that evidence with the
relevant integration's diagnostics before concluding that a code path is
missing.
