<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Bluesky and AT Protocol compatibility

**Status: phase 1 (§18) is implemented; phases 2–4 are specification.**
This document is the contract for a multi-PR project: the decisions were
taken by the product owner in two interviews (2026-09-25 and 2026-10-06)
and are not to be re-derived; the technical facts were checked against the
AT Protocol specifications and the Bluesky reference implementation on the
dates given in §20, and the ones marked *verify* have to be checked again
before the code that depends on them is written. What phase 1 built, and
where it departs from the letter of this document, is in §18.

**Verified against:** Aloha Social master `2eb7b99a9` (0.26.121),
AT Protocol specifications as of 2026-10-06, Bluesky PDS reference
implementation (`@atproto/pds`) 0.4.x, PLC directory API as served by
`plc.directory`.

**Relation to PR #2482 ("AT-Proto/Bluesky integration").** That pull
request is a different product: a Social user *links an existing Bluesky
account* with an app password, and Social mirrors into it and acts from it.
The owner decided on 2026-10-06 to keep the two tracks separate. This
specification describes **native** identity — Aloha Social *is* the user's
Bluesky host — and shares nothing with the linked-account track beyond the
protocol library pieces (§4, §5) that both would use. Where the two meet in
the user interface (a profile that is both), §11 says which one wins.

## Contents

1. [The answer in one paragraph](#1-the-answer-in-one-paragraph)
2. [Decisions](#2-decisions)
3. [The protocol, as far as this app needs it](#3-the-protocol-as-far-as-this-app-needs-it)
4. [Identity](#4-identity)
5. [The repository](#5-the-repository)
6. [The XRPC surface](#6-the-xrpc-surface)
7. [The firehose](#7-the-firehose)
8. [Outbound: what a post becomes on Bluesky](#8-outbound-what-a-post-becomes-on-bluesky)
9. [Inbound: reading Bluesky](#9-inbound-reading-bluesky)
10. [Being followed, liked and answered from Bluesky](#10-being-followed-liked-and-answered-from-bluesky)
11. [What the person sees](#11-what-the-person-sees)
12. [Moderation](#12-moderation)
13. [Moving a Bluesky account here](#13-moving-a-bluesky-account-here)
14. [Operations](#14-operations)
15. [Data model](#15-data-model)
16. [Security](#16-security)
17. [Testing](#17-testing)
18. [Phases](#18-phases)
19. [Open questions](#19-open-questions)
20. [Facts, and when they were checked](#20-facts-and-when-they-were-checked)

## 1. The answer in one paragraph

Every Aloha Social account becomes a Bluesky account as well, hosted by this
Nextcloud: a `did:plc` identity whose handle is `alice.<instance host>`, a
signed repository of records that a Bluesky relay crawls, and a firehose the
relay subscribes to. A Bluesky user finds `alice.social.example.com` in the
Bluesky app, sees the profile and the public posts, follows, likes, replies
and quotes — and Alice sees all of that here, in Activities and in her
threads, under the same notification policy as everything else. Alice
follows Bluesky accounts from here and reads them in her home feed, badged
but otherwise ordinary posts. Nothing is required of the person: no Bluesky
account, no app password, no switch — the administrator turns Bluesky on for
the instance once, with a wildcard DNS record and certificate for the handle
host. Direct, followers-only and unlisted posts never leave ActivityPub;
blocks and mutes are never published; direct messages stay out of scope. An
existing Bluesky user can later move their account here, keeping their DID
and their followers.

## 2. Decisions

Taken by the product owner; the date is the interview. **Do not re-ask.**

| # | Decision | Date |
|---|---|---|
| D1 | **Native identity**: Aloha Social is the PDS. No Bluesky account is needed or used. (The linked-account approach of PR #2482 is a separate track.) | 09-25, confirmed 10-06 |
| D2 | **Both directions**: Social → Bluesky and Bluesky → Social. | 09-25 |
| D3 | **Every account, automatically**: each Social account is reachable from Bluesky; the administrator can disable Bluesky instance-wide. No per-user opt-in. | 10-06 (supersedes 09-25's opt-in) |
| D4 | Handles are **`alice.<instance host>`** via wildcard DNS and a wildcard certificate; bring-your-own-domain handles are a later phase. | 09-25, fixed 10-06 |
| D5 | **`did:plc` only**, registered at `plc.directory`. | 09-25 |
| D6 | The firehose is served by a daemon, **`occ social:atproto:serve`**, run under systemd. | 09-25 |
| D7 | `/xrpc/` reaches the app through the existing root web-server rules (`contrib/webserver/*-social-root.conf`), like `/api/` and `/oauth/`. | 09-25 |
| D8 | **Every public post goes to Bluesky automatically.** Unlisted, followers-only and direct posts never do. No per-post switch. | 10-06 |
| D9 | A post that does not fit Bluesky is **truncated with a link** to the full post; **first four pictures**; a poll becomes **the question plus a link**; a content warning becomes a **self-label plus a text prefix**. No threads. | 09-25, fixed 10-06 |
| D10 | **Edits**: within a grace period of five minutes the Bluesky record is deleted and recreated; after that the Bluesky copy keeps its text and its likes, and its link leads to the current version here. | 10-06 |
| D11 | **Pictures** go with the first posting phase; **video** through Bluesky's video service is a later phase, and until then a video post links here. | 10-06 |
| D12 | **Inbound** reads by **polling** the public AppView per followed author, with a cap and backoff; **Jetstream** is an optional daemon an administrator may run for instant delivery on a large instance. | 09-25 polling, 10-06 the optional daemon |
| D13 | Bluesky posts appear **in the same timelines**, their authors addressed as bare `@alice.bsky.social` with a **badge**. | 09-25, confirmed 10-06 |
| D14 | **All Bluesky interactions** (like, repost, follow, reply, mention, quote) show in Activities like their ActivityPub counterparts, under the same **notification policy** and requests inbox. | 10-06 |
| D15 | **Direct messages** are out of scope (Bluesky chat is a separate service). | 10-06 |
| D16 | **Blocks and mutes are never published**; in scope: honour Bluesky's moderation labels, let users subscribe to labelers, file reports with Bluesky's moderation service, instance-level blocks of PDS hosts and DIDs. | 09-25, extended 10-06 |
| D17 | The client API is a PDS: Bluesky apps may log in here, with `app.bsky.*` proxied to the AppView; no AppView of our own. | 09-25 |
| D18 | Bridgy Fed twins of local accounts are folded into the native identity (§13.3). | 09-25 |
| D19 | **Moving an existing Bluesky account here** is its own phase. | 10-06 |
| D20 | **Phase 1 makes a Social account visible on Bluesky** (identity, repository, firehose); reading Bluesky from here is phase 2. Four phases, one PR each. | 09-25, order fixed 10-06 |
| D21 | CI and devel use a self-hosted PLC, `@atproto/dev-env` and an `indigo` relay; the real network is exercised by hand only. | 09-25 |

## 3. The protocol, as far as this app needs it

Enough of AT Protocol to read the rest; the specifications are at
atproto.com and are the authority.

- **DID**: the permanent identifier of an account, `did:plc:…` (24
  base32 characters after the prefix). Resolves, through the PLC directory,
  to a *DID document* naming the account's **handle**, its **signing key**,
  its **rotation keys** and its **PDS** service endpoint.
- **Handle**: a domain name that *points at* a DID: either a DNS TXT record
  `_atproto.<handle>` holding `did=did:plc:…`, or
  `https://<handle>/.well-known/atproto-did` answering the DID as text.
  Handles are display and lookup; every reference between records is by
  DID.
- **PDS** (personal data server): the host that stores an account's
  **repository** and accepts its writes. This app is one.
- **Repository**: a Merkle search tree (MST) of **records**, keyed by
  `<collection>/<rkey>`, with a signed **commit** at the root. Every change
  is a new commit; the relay verifies the signature against the DID
  document's signing key.
- **Record**: a DAG-CBOR object of a **lexicon** type (`$type`), e.g.
  `app.bsky.feed.post`, `app.bsky.feed.like`, `app.bsky.graph.follow`,
  `app.bsky.actor.profile`. Addressed by an **AT URI**
  `at://<did>/<collection>/<rkey>` and, for the exact version, by its **CID**.
  The `rkey` of most records is a **TID** (timestamp id, 13 characters of
  base32-sortable).
- **Blob**: a binary (picture, video) referenced from a record by CID,
  stored by the PDS, fetched through `com.atproto.sync.getBlob`.
- **Relay** (Bluesky's `bsky.network`): subscribes to every PDS's
  **firehose** (`com.atproto.sync.subscribeRepos`, a WebSocket of commits),
  verifies and republishes them.
- **AppView** (`api.bsky.app`, public read-only mirror `public.api.bsky.app`):
  indexes the relay's stream into the views an app asks for — profiles,
  author feeds, threads, followers, notifications, search. A PDS proxies
  `app.bsky.*` client calls to it with a **service auth** token signed by the
  user's signing key.
- **Jetstream**: Bluesky's JSON firehose (`jetstream*.bsky.network`), filterable
  by collection and by author DID (up to 10,000 DIDs per connection), with a
  cursor in microseconds for catching up after a disconnect. *verify limits*
- **Labels**: moderation metadata (`com.atproto.label.defs#label`) emitted by
  a **labeler** service; Bluesky's own moderation service is one, and a user
  may subscribe to others. A post may also **self-label** (`!warn`,
  `porn`, `nudity`, `sexual`, `graphic-media`).
- **PLC directory** (`plc.directory`): the registry of `did:plc` documents,
  updated by **operations** signed by a rotation key. The last 72 hours of
  operations can be undone by a higher-priority rotation key.

## 4. Identity

### 4.1 One DID per account

When Bluesky is enabled for the instance, every local Social actor gets an
AT Protocol identity, created lazily on first need (the first public post,
the first follow of a Bluesky account, the first resolution of its handle)
and eagerly for every existing account by `occ social:atproto:identities`.
The identity is three things stored in `social_atproto_identity` (§15):

- the **DID** (`did:plc:…`), computed by the PLC rules from the genesis
  operation (the DID *is* a hash of the first operation);
- the **signing key**, secp256k1 (`k256`), private half sealed with the
  instance secret the way app passwords are (`PrivateKeyCipher`), public
  half as `did:key:z…` (multibase, multicodec `0xe7`);
- two **rotation keys**: the instance's rotation key (one per instance,
  rotated by the administrator, §16.3) first, and a per-account recovery
  key whose private half is shown to the person once (§4.4) and otherwise
  not stored. Order is priority: the instance key wins a dispute.

The DID document the PLC publishes names the handle (§4.2), the signing
key under `#atproto`, and the PDS service `#atproto_pds` with endpoint
`https://<instance host>` — the Nextcloud host itself, where `/xrpc/` is
served (§6.1).

### 4.2 Handles

`alice.<instance host>`, where the instance host is the hostname of
`social_url` (what `ConfigService::getSocialAddress()` answers) and `alice`
is the actor's `preferredUsername`. A username that is not a valid DNS
label (Social allows characters a hostname does not: dots, underscores,
uppercase) is mapped deterministically — lowercase, `_` and `.` to `-`,
collapse runs, trim — and the mapping is **stored**, not recomputed, so a
later change of the rule cannot rename anybody. Collisions after mapping
are resolved by a numeric suffix, stored too.

Resolution is served both ways: `https://alice.<host>/.well-known/atproto-did`
(the wildcard certificate and a web-server rule that routes every
`*.<host>/.well-known/atproto-did` to the app — a `contrib/webserver` rule
beside the root rules) **and** the DNS TXT record `_atproto.alice.<host>`
where the administrator runs a DNS server that this app can write to; the
HTTPS way is the required one, the TXT way is an optimisation the setup
check reports as "available" or not. The app answers the well-known from
the stored mapping and nothing else: no lookup by handle pattern, so a
handle that was never issued is a 404.

A later phase lets a person set a **custom handle** on a domain they own
(they place the TXT record or the well-known file; the app verifies and
updates the DID document). The `alice.<host>` handle keeps resolving to the
same DID forever, as an alias the DID document does not list.

### 4.3 Service identity

The instance itself has a `did:web:<instance host>` document at
`https://<host>/.well-known/did.json` naming the PDS service and its own
signing key, which is what service-auth tokens for the relay and the
`requestCrawl` call are signed with. One key pair per instance, sealed like
the account keys.

### 4.4 What the person sees of their identity

Nothing, unless they look: **Settings → Your account** shows the Bluesky
handle and DID under the Fediverse handle, with a *Copy* button and a
sentence that says the Bluesky account exists because this server offers
one. The recovery key is shown there **once**, on first view, as a
twelve-word phrase to write down (BIP-39 encoding of the 32-byte private
key), with a "Show again" that requires password confirmation and
regenerates (and re-registers, §4.5) rather than reveals.

### 4.5 PLC operations this app sends

- **create**: on first need (§4.1), signed with the instance rotation key.
- **update handle**: when the username changes (it does not, in Social, but
  the custom-handle phase needs it) and when the instance host changes.
- **rotate keys**: when the administrator rotates the instance rotation key
  (§16.3) or the person regenerates their recovery key.
- **update PDS endpoint**: on a host change, and when an account moves
  away (§13.4).
- **tombstone**: when a Social account is deleted. `AccountService::deleteActor()`
  gains this step; the DID is dead afterwards, as the person asked.

Every operation goes through one `PlcClient` with retries and is recorded
in `social_atproto_plc_log` before it is sent (so a crash between the two
leaves a row to reconcile from), and `occ social:atproto:plc` lists the log
and the directory's view side by side.

## 5. The repository

### 5.1 Records this app writes

Collection → written when:

| Collection | Written | Content |
|---|---|---|
| `app.bsky.actor.profile` (rkey `self`) | account created or profile edited | `displayName`, `description` (plain text, bio), `avatar` and `banner` blobs, `createdAt` |
| `app.bsky.feed.post` | public post created (D8) | §8 |
| `app.bsky.feed.like` | a local actor likes a post that **exists on Bluesky** (a Bluesky post, or a local post that was published, §8.6) | `subject` {uri, cid} |
| `app.bsky.feed.repost` | a local actor boosts such a post | `subject` {uri, cid} |
| `app.bsky.graph.follow` | a local actor follows a Bluesky account (§9.2) | `subject` DID |
| `app.bsky.feed.threadgate` | *optional, later*: a post's reply policy maps to one | who may reply |
| `app.bsky.graph.block` | **never** (D16) | — |
| `app.bsky.graph.list*`, `app.bsky.feed.generator`, `chat.*` | never | — |

Records are built by `RecordMapper` from the Social model (§8) and
validated against the lexicon shapes this app ships as JSON (the lexicon
files are vendored under `lib/Atproto/lexicons/`, read at runtime by a
small validator; no code generation).

### 5.2 Storage

Two tables (§15): `social_atproto_record` — one row per live record with
its collection, rkey, CID, DAG-CBOR bytes, and the Social id it came from
(`local_id`, so a post maps to its record in O(1) in both directions) — and
`social_atproto_block` — the content-addressed blocks of the MST and the
commits, keyed by CID, because the sync endpoints and the firehose serve
blocks, not rows. The MST is **recomputed in memory from the record table**
when a commit is made, then its new or changed nodes are written to the
block table and the unreferenced ones of the previous commit deleted — the
simplest correct implementation, and a repository of ten thousand records
rebuilds in well under a second in PHP; if a measurement says otherwise the
`MST` class gets an incremental path, behind the same interface. The MST
rules (fanout 4, key depth from the leading zero bits of the SHA-256 of the
key, two hash bits per level, 32-byte CID v1 `dag-cbor` SHA-256) are pinned
by the interop test vectors (§17.1).

### 5.3 Commits

`version: 3`, `did`, `data` (root MST CID), `rev` (a TID, strictly
increasing per repository), `prev: null`, `sig` (64-byte low-S secp256k1
over the DAG-CBOR of the unsigned commit). One commit per write, written in
the same database transaction as the record and the blocks, and one
firehose event per commit (§7). A write that fails half-way leaves the
previous commit as the repository's head: the `social_atproto_repo` row
(§15) holds the head CID and `rev`, and it is updated last.

### 5.4 Blobs

A picture or video that a record references is a **blob**: uploaded (§8.4)
or imported (§13), it is the app's own stored file (`social_cache_doc`,
through `DocumentService`) with a `social_atproto_blob` row giving its CID
(SHA-256 of the bytes, `raw` multicodec), MIME type and size. `getBlob`
streams it; `listBlobs` lists them per repository; a blob no record refers
to is deleted by the ordinary document retention. Limits are Bluesky's:
pictures ≤ 2,000,000 bytes (re-encoded to fit, §8.4), at most four per
post; video ≤ 300 MB mp4 and through the video service, a later phase (D11).

## 6. The XRPC surface

### 6.1 Where it is served

`https://<instance host>/xrpc/<nsid>` — the DID document's PDS endpoint is
the bare host, so the `/xrpc/` path has to be at the root. The existing
root rules in `contrib/webserver/apache-social-root.conf` and
`nginx-social-root.conf` gain one block each, exactly as `/api/` and
`/oauth/` have, rewriting `/xrpc/(.*)` to `/index.php/apps/social/xrpc/$1`,
plus `/.well-known/did.json` (§4.3) and the wildcard-host
`/.well-known/atproto-did` (§4.2). The setup check that pins the rule files
(see `DocumentationTest`) is extended; the admin page's "rules to paste"
warning shows the new blocks. **Without the root rules, the instance is not
a PDS**, and the admin page says so in the same words it uses for Mastodon
apps today.

### 6.2 Endpoints

**Served (this app implements them)** — the sync and identity surface a
relay and other PDSes need, and the account surface a Bluesky app logging
in here needs:

`com.atproto.sync.getRepo`, `getLatestCommit`, `getRecord`, `getBlob`,
`listBlobs`, `listRepos`, `getRepoStatus`, `subscribeRepos` (§7);
`com.atproto.identity.resolveHandle`, `updateHandle` (custom handles, later),
`getRecommendedDidCredentials`, `signPlcOperation`, `submitPlcOperation`,
`requestPlcOperationSignature` (§13); `com.atproto.server.describeServer`,
`createSession`, `refreshSession`, `deleteSession`, `getSession`,
`getServiceAuth`, `checkAccountStatus`, `activateAccount`,
`deactivateAccount`, `createAccount` (migration in only, §13; open
registration is **never** offered — a Bluesky account here is a Nextcloud
account); `com.atproto.repo.createRecord`, `putRecord`, `deleteRecord`,
`getRecord`, `listRecords`, `describeRepo`, `uploadBlob`, `applyWrites`,
`importRepo`, `listMissingBlobs`; `com.atproto.moderation.createReport`
(proxied, §12.3); `com.atproto.label.queryLabels` (answers the self-labels
of local records only); `_health`.

**Proxied to the AppView** with a service-auth token for the calling user
(`Authorization: Bearer <JWT signed by the user's signing key, aud
did:web:api.bsky.app>`): everything under `app.bsky.*`, and
`chat.bsky.*` is refused (D15). The proxy is `AppViewClient`, the same
guarded HTTP client as every outbound request (`CurlService`), with the
response passed through unchanged.

**Not served**: `com.atproto.admin.*`, `com.atproto.temp.*`,
`tools.ozone.*`, `com.atproto.server.createInviteCode*`, `requestAccountDelete`
(an account is deleted in Nextcloud), `com.atproto.server.requestPasswordReset`
(Nextcloud's password is Nextcloud's).

### 6.3 Authentication for Bluesky apps (D17)

Phase 1 serves `createSession` with **app passwords**: a Nextcloud app
password (Settings → Security) is what a Bluesky app is given as the
password, checked through `IProvider`/`IUserManager::checkPassword`, which
is how Nextcloud's own clients log in. The session is a pair of JWTs
(access 2 h, refresh 90 d) signed with the instance service key, carried in
`Authorization: Bearer`, with `did` and `handle` in the response as Bluesky
apps expect. **OAuth for Bluesky apps** (PAR, DPoP, PKCE S256, client
metadata URL — what the official app uses since 2025) is phase 3; until
then the official app's "custom PDS" sign-in works with the app password,
which is what every self-hosted PDS offered before OAuth. The existing
Mastodon OAuth server (`OAuthController`) is not reused for this: its
tokens are Social's client API's, and the two token worlds stay apart.

Rate limits: Bluesky's PDS limits are the model — 3,000 points per hour per
DID for writes (create 3, update 2, delete 1), 300/5 min per IP on
`createSession`; implemented with `AnonRateLimit`/`UserRateLimit` and a
`RateLimit-*` header answer the way `@atproto/pds` does. *verify current
numbers*

## 7. The firehose

### 7.1 `occ social:atproto:serve`

One long-running process (D6), a WebSocket server (`ratchet/rfc6455` +
`react/socket`, both on Packagist and PHP ≥ 8.1) bound to a local port that
the web server proxies at `/xrpc/com.atproto.sync.subscribeRepos`
(upgrade requests only; the `contrib/webserver` rules carry the `proxy_pass`
/ `ProxyPass` for that one path). It serves the **sequenced event stream**:
every commit (§5.3) is a `#commit` frame — `seq`, `did`, `rev`, `commit`
CID, `ops` (`create`/`update`/`delete` with path and CID), `blocks` (the CAR
of the commit, the changed MST nodes and the records), `time` — plus
`#identity` (handle change), `#account` (deactivated/deleted) and `#info`
frames, each with a `seq` from one monotonic counter per instance. A client
that connects with `?cursor=N` is replayed from `N+1` out of
`social_atproto_event` (§15), which keeps 72 hours of frames (the relay's
replay window; *verify*), then followed live.

The daemon reads new events by polling the event table (every 250 ms when
idle, immediately after a write it was told about through a Redis/APCu
notice where available) — the web request writes the row, the daemon
serves it; no shared memory, no second code path. `--once` drains and exits
for tests; `--max-seconds` as `social:worker` has. systemd unit and the
Docker variant are documented in Admin.md; the setup check "firehose
reachable" connects to the public URL and reads an `#info` frame.

### 7.2 Telling the relay

`com.atproto.sync.requestCrawl` is sent to the relay (`bsky.network` by
default, configurable, several allowed) when Bluesky is enabled and after
the daemon has been unreachable for more than the replay window. The relay
then subscribes, backfills with `getRepo` per DID, and stays connected. The
admin page shows the relay's last connection and the lag between the head
`seq` and what was served.

### 7.3 Backfill and consistency

A record the relay missed (daemon down longer than the window) is
recovered by the relay's own `getRepo` on reconnect — the sync endpoints are
the source of truth, the firehose a notification. The daemon never has to
be correct about history; the repository is.

## 8. Outbound: what a post becomes on Bluesky

Decided shape (D8, D9, D10, D11); the mapping lives in `RecordMapper` and
is pinned by tests against the lexicon validator.

### 8.1 Which posts

A local **public** post, at creation, by the same listener that queues
ActivityPub delivery (`PostService::createPost()` → a `PublishToAtproto`
queued job, so Bluesky never delays the Fediverse). Not: unlisted,
followers-only, direct, a post held for review, a post from a moved account
(refused by `ModerationService::assertNotMoved()` before anything), an
archived post. A team account (§Architecture, teams) publishes like a
person.

### 8.2 Text

Social posts are HTML; Bluesky posts are plain text with **facets**. The
HTML is converted with the existing `plainText` helper rules — paragraphs
to blank lines, `<br>` to newlines, links kept as their href when the text
is the href and as `text (href)` otherwise, mentions and hashtags as their
text — then facets are computed on the UTF-8 **byte** offsets of the result:
`#link` for every URL, `#tag` for every `#hashtag`, `#mention` for a mention
of a Bluesky account (resolved to its DID) or of a local account (its own
DID). A mention of an ActivityPub account that has no Bluesky identity
becomes a `#link` facet to its profile URL.

The limit is **300 graphemes** and 3,000 bytes. A post over it is cut at
the last word boundary before 280 graphemes, `…` appended, and the link to
the post here (`$post->getUrl()`) added on its own line with a `#link`
facet — always, so the truncated post is never the whole of what somebody
sees. The counter in the composer is unchanged (Social's own limit), but a
hint under it says "Bluesky shows the first 280 characters and a link"
when crossed.

### 8.3 Warnings, polls, quotes, replies

- **Content warning** (`spoiler_text`): self-label `!warn` plus the warning
  as the first line, `CW: <warning>` then a blank line, counted against the
  300. A Social post marked sensitive also gets `graphic-media` on its
  pictures as a self-label when it carries pictures. *verify label set*
- **Poll**: the question and options as text (`Poll: … — A / B / C`), then
  the link; results are not synchronised.
- **Quote** of a Bluesky post, or of a local post that was published:
  `embed` `app.bsky.embed.record` with the quoted {uri, cid}; of anything
  else: the quoted post's URL as a `#link` and an `app.bsky.embed.external`
  card built from Social's own link preview (`StreamCard`).
- **Reply** to a Bluesky post, or to a local post that was published:
  `reply: {root: {uri,cid}, parent: {uri,cid}}` with the root found by
  walking `in_reply_to` through `social_atproto_record` and the Bluesky
  thread (the AppView's `getPostThread` when the parent is remote). A reply
  to a post that is not on Bluesky is published as a top-level post with
  the parent's URL as a `#link` — the thread cannot be joined, and saying
  nothing would hide the post.
- **Language**: `langs: [<post language>]` when known.
- **Link card**: when the post has a `StreamCard` and no pictures,
  `app.bsky.embed.external` with title, description and the preview image
  as a blob (fetched through the cache, resized to ≤ 2 MB).

### 8.4 Pictures

Up to four `media_attachments` of image type, in order; more are dropped
and the text gains a line "+N more pictures" before the link. Each is
re-encoded to fit **2,000,000 bytes** — JPEG at descending quality, then
downscaled — through `gumlet/php-image-resize` which the app already
depends on, uploaded as a blob (§5.4), and placed in
`app.bsky.embed.images` with its `alt` (the Social description) and
`aspectRatio`. A post with pictures and a link card keeps the pictures.

### 8.5 Edits and deletes

**Delete**: `deleteRecord` of the post, and of the likes and reposts local
actors made of it, in the same job that sends the ActivityPub `Delete`.

**Edit** (D10): if the post is less than five minutes old (`created_at`),
the record is deleted and a new one created with the new content — a new
rkey, so the AT URI changes; replies that already referenced the old URI
on Bluesky dangle, which is why the grace period is short. Later than five
minutes the Bluesky record is left as it is; the link in it keeps pointing
at the post here, which shows the current text. The post menu's *Delivery
status* dialog says which of the two happened.

### 8.6 Likes, boosts, follows

A local like of a post that exists on Bluesky writes `app.bsky.feed.like`
(unlike deletes it); a boost, `app.bsky.feed.repost`; a follow of a Bluesky
account, `app.bsky.graph.follow` (unfollow deletes it). "Exists on
Bluesky" means the post is either a Bluesky post (its stream row carries an
`at://` id, §9.3) or a local post with a `social_atproto_record` row. A like
of an ActivityPub-only post writes nothing. These go through the same
queued publisher and are reflected in the viewer flags the status entity
already carries (`favourited`, `reblogged`) — one action, two networks.

## 9. Inbound: reading Bluesky

### 9.1 Resolving a Bluesky account

A handle typed anywhere a handle is accepted — search, the follow box, the
alias field — that is not an ActivityPub account is tried as a Bluesky
handle: `com.atproto.identity.resolveHandle` (through the public AppView,
then DNS/HTTPS directly as a fallback), the DID document from
`plc.directory` (or `did:web`), then `app.bsky.actor.getProfile`. The
result is a **cached actor** (`social_cache_actor`) with id `at://<did>`,
account `alice.bsky.social` (the handle, no `@…@` form), type `Person`,
`host` the handle's host, the profile fields mapped (`displayName` →
name, `description` → summary as plain text, avatar and banner as cached
documents, `followersCount`/`followsCount`/`postsCount` into the counts),
and a `details.atproto` block with the DID, the PDS endpoint and the
labels. The cache refresh cron (`manageCacheRemoteActors`) refreshes it
through `getProfile` the way it refreshes an ActivityPub actor through its
actor document, same bookkeeping columns. `CacheActorService::getFromAccount()`
is the one entry point and learns the `at://` scheme; everything that
already displays a cached actor displays this one (D13).

The **search** and **directory** code paths (`FediverseDirectoryService`)
gain a `bluesky` source: `app.bsky.actor.searchActorsTypeahead` on the
public AppView, unauthenticated, marked in the results like the other
sources.

### 9.2 Following

A follow of a Bluesky account writes `app.bsky.graph.follow` in the local
actor's repository (§8.6) and a `social_follows` row as for any follow —
accepted immediately, since Bluesky has no follow requests — and a
**watch** row (`social_atproto_watch`: DID, cursor, timing, failures). The
follower count on the Bluesky side is the AppView's business and appears
there within minutes of the relay indexing the commit.

### 9.3 Polling (D12)

`Cron\AtprotoSync` (a `TimedJob`, every two minutes) takes the watches due
(`next_sync <= now`), at most `SYNC_BATCH` per pass and at most the
instance-wide `atproto_sync_ceiling` (default 200 requests per pass; the
admin page shows how far behind the slowest watch is), and asks the public
AppView `app.bsky.feed.getAuthorFeed` for each (`filter=posts_with_replies`,
`limit=50`, the stored cursor): new posts are stored, the cursor advanced,
`next_sync` set by backoff — one interval after a page with posts, doubling
up to six hours after empty pages, reset by the next post. Reposts in the
feed become boosts (`Announce`) by the Bluesky author; replies are stored
with their `in_reply_to` and the parent fetched through `getPostThread`
when it is not here (bounded, one hop per pass). Only the public AppView is
read, unauthenticated, which is why nothing a Bluesky user kept to
themselves can arrive.

A Bluesky post is a stream row of type `Note` with id `at://…/app.bsky.feed.post/…`
(`id_prim` as for any id), `url` the `https://bsky.app/profile/<handle>/post/<rkey>`
link, `attributed_to` the cached actor, `content` rebuilt from text and
facets (links and mentions as anchors, hashtags as hashtag links), pictures
as `social_cache_doc` rows pointing at the AppView's CDN URL with the
blob's CID and alt text, a quote as `quoted_id`, a reply as `in_reply_to`,
labels as `summary` (content warning) or `sensitive` per §12.1, `published`
from `indexedAt`, `local` false, and `details.atproto` with the CID and
the like/repost/reply counts the AppView reported. It goes through
`ImportService::parseIncomingRequest()` as a synthetic `Create`, so every
side effect of an arriving post — recipients, `social_stream_dest`,
notifications, filters, interests — happens the way it happens for
ActivityPub; nothing downstream knows a Bluesky post from any other.

### 9.4 Jetstream (optional)

`occ social:atproto:listen`: one WebSocket to a Jetstream instance,
`wantedCollections=app.bsky.feed.post,app.bsky.feed.repost,app.bsky.feed.like,app.bsky.graph.follow`,
`wantedDids` the watched DIDs **and the local DIDs** (§10), re-sent as a
`#options_update` when a watch is added, cursor persisted every second.
Each event is handed to the same storer the poller uses; the poller keeps
running, backing off watches the listener keeps current, so the daemon
being down costs latency and nothing else. Over 10,000 DIDs the listener
opens a second connection. *verify the per-connection limit*

### 9.5 Deletes and edits from Bluesky

A `delete` op from Jetstream, or a post gone from the author feed on the
next poll (the AppView answers 404 for `getPosts` of it — checked for the
last page's ids once a day, bounded), becomes a `Delete` through the same
import path, as a remote `Delete` would. Bluesky has no edits.

## 10. Being followed, liked and answered from Bluesky

As a PDS this app is **not told** when somebody on Bluesky follows, likes,
reposts or replies to a local account: those are records in *their*
repositories, indexed by the AppView. Two sources, both used:

- **Jetstream** (when the listener runs, §9.4) with the local DIDs in
  `wantedDids` sees follows, likes and reposts *by* those DIDs — which is
  the local side — not *of* them. It does see a reply or quote *by a
  watched author*. So Jetstream alone is not enough.
- **The AppView's notifications**: `app.bsky.notification.listNotifications`,
  called *as the local user* with a service-auth token (§6.2), lists
  follows, likes, reposts, replies, mentions and quotes of that user's
  records, with a cursor. `Cron\AtprotoNotifications` polls it for every
  local account that has a Bluesky identity, with the same backoff as the
  watches (an account nobody on Bluesky interacts with is asked twice a
  day), and the ceiling shared with §9.3.

Each notification becomes the corresponding Social event by the import
path, so **D14** holds without a second notification system:

| Bluesky | Here |
|---|---|
| `follow` | a `Follow` from the cached actor, accepted (Bluesky follows need no acceptance); the follower appears in the followers list and count; **their posts are not fetched** by this alone — only if a local actor follows them back |
| `like` | a `Like` → favourite notification, count on the post |
| `repost` | an `Announce` → boost notification, count |
| `reply` | the reply post is fetched (`getPostThread`), stored with `in_reply_to`, a mention/reply notification follows |
| `mention` | the post is fetched and stored; a mention notification |
| `quote` | the quoting post is fetched; a quote notification (Social has quote notifications already) |
| `starterpack-joined`, `verified`, `unverified` | ignored |

The **notification policy** (`NotificationPolicyService`) sees a Bluesky
sender like any other: `for_not_following` holds a like from a Bluesky
account the person does not follow, `for_new_accounts` reads the DID
document's creation time (the first PLC operation's `createdAt`) as the
account age, and the requests inbox shows the sender with the badge.

## 11. What the person sees

- **Profiles**: a Bluesky account's profile is the ordinary profile page at
  `/@alice.bsky.social` (the handle has no second `@`, which is how the
  router tells it apart), the follow button, the counts, the posts from
  the author feed (fetched live and paged by the AppView cursor when the
  account is not watched), and a **Bluesky badge** next to the name — the
  butterfly glyph with `title="On Bluesky"`, the same place the verified
  tick sits. The badge appears on every card the author appears in: post
  header, notification, follower list, search result.
- **Addressing**: `@alice.bsky.social` in a post mentions the Bluesky
  account; the composer's mention picker completes Bluesky handles from the
  AppView typeahead when the typed text has a dot and no second `@`.
- **The person's own profile** shows both handles under the name, each
  with *Copy*; the Bluesky one links to `https://bsky.app/profile/<handle>`.
  No switch between "Fediverse" and "Bluesky" views: one account, one
  profile, one feed (D1, D13). PR #2482's network switch belongs to its
  own product and is not part of this one.
- **Composer**: the counter hint of §8.2; nothing else. There is no target
  picker (D8).
- **Post**: a Bluesky post's permalink menu entry *Open on Bluesky*; the
  like/boost/reply/quote buttons do what they do, writing the records of
  §8.6. Reply to a Bluesky post from here → published as a reply (§8.3).
- **Activities**: Bluesky events with the badge on the actor; the filter
  switcher gains no entry (D14 chose "mixed", not "separate").
- **Settings → Your account**: the Bluesky handle/DID block and the
  recovery phrase (§4.4). **Settings → Blocking**: labelers (§12.2).
- **Admin settings**: the Bluesky section (§14.2).

## 12. Moderation

### 12.1 Labels on what arrives (D16)

Bluesky's moderation service (`did:plc:ar7c4by46qjdydhdevvrndac`) labels
posts and accounts; the AppView attaches the labels to what it answers.
Mapping, applied when a Bluesky post is stored (§9.3) and when a cached
actor is refreshed:

| Label | Here |
|---|---|
| `porn`, `sexual`, `nudity`, `graphic-media` | `sensitive: true` on the post's pictures, and a content warning naming the label when the post had none |
| `!warn` (self or service) | content warning (the post's own text prefix is kept) |
| `!hide` (service) | the post is not stored; the account's posts are not fetched while the label stands |
| `spam`, `impersonation`, `scam`, `misleading`, other service labels | a content warning naming the label; the account's profile shows it |
| account-level `!hide` / takedown | the cached actor is marked limited: no posts fetched, interactions not announced |

A labeler's label value definitions (`app.bsky.labeler.service` record,
`policies.labelValueDefinitions`) are read once per labeler and cached, so
a custom label is shown with the labeler's own name and severity.

### 12.2 Subscribing to labelers

**Settings → Blocking → Labelers**: the list of labelers the person
subscribes to (Bluesky's moderation service is always there and cannot be
removed, as on Bluesky), add by handle or DID, with each label value's
setting *ignore / warn / hide* as the labeler defines its defaults. Stored
per user (`social_atproto_labeler`), applied in `FilterService` as a filter
source — which is where hiding already happens for words and for posts
made with AI — and sent along as `atproto-accept-labelers` on the AppView
calls made for that user, so the AppView's own answers (threads, search)
come labelled the way the person asked.

### 12.3 Reporting

*Report* on a Bluesky post or account files the local report as today and,
when the person ticks "also report to Bluesky's moderation service" (on by
default for Bluesky content), a `com.atproto.moderation.createReport`
proxied to the labeler (`atproto-proxy: <labeler did>#atproto_labeler`)
with the reason mapped (`spam` → `com.atproto.moderation.defs#reasonSpam`,
etc.) and the report's reference. The report row records the Bluesky
report id.

### 12.4 Blocks, mutes, instance blocks

Local blocks and mutes of Bluesky accounts work as they do for anybody —
stored in `social_actor_relation`, applied on read — and are **never
written as `app.bsky.graph.block`** (D16); a blocked Bluesky account's
interactions are dropped on arrival. A Bluesky user's public block *of* a
local account is read from their repository when it is encountered (the
AppView says `viewer.blockedBy`) and honoured: no replies, no quotes of
their posts are published by the blocked local account.

The administrator's federation blocklist gains Bluesky: a blocked **PDS
host** or **DID** (`social_atproto_blocklist`, managed on the admin page and
by `occ social:atproto:block`) is refused on resolution, its posts not
fetched, its interactions dropped. The whole network is switched off by
the instance switch (§14.2), which keeps the repositories but stops the
daemon, the crons and the publisher.

## 13. Moving a Bluesky account here

Its own phase (D19). The AT Protocol account-migration flow, driven from
the Migration page as a third card, **Bring your Bluesky account here**,
beside the Fediverse move-in.

### 13.1 The flow

1. The person types their Bluesky handle and app password (never stored
   beyond the run; Bluesky OAuth later). The old PDS is resolved from the
   DID document.
2. **Create the account here with the existing DID**:
   `com.atproto.server.getServiceAuth` on the old PDS (aud this instance's
   service DID, lxm `com.atproto.server.createAccount`), then
   `createAccount` here with that token and the DID — the account exists
   here *deactivated*. The DID is bound to the Nextcloud user; the local
   actor's own `did:plc` is **not** created, the moved DID is its identity.
3. **Repository**: `com.atproto.sync.getRepo` from the old PDS, imported
   with `importRepo` into this one; `listMissingBlobs` here, each fetched
   with `getBlob` from the old PDS and `uploadBlob` here; preferences via
   `app.bsky.actor.getPreferences`/`putPreferences`. Runs as a queued import
   (`ImportQueueService`, kind `bluesky_move`), progress on the page.
4. **Identity**: `getRecommendedDidCredentials` here (our signing key, our
   rotation key, the new handle `alice.<host>`, our PDS endpoint);
   `requestPlcOperationSignature` on the old PDS (Bluesky e-mails the
   person a code), the person enters the code, `signPlcOperation` on the
   old PDS, `submitPlcOperation` here.
5. **Activate**: `activateAccount` here, `deactivateAccount` on the old
   PDS. The followers follow the DID and need do nothing; the handle
   changes to `alice.<host>` unless the person owns a domain and sets a
   custom handle (later phase).
6. The imported posts become local stream rows (§9.3's mapping, attributed
   to the local actor), the follows become watches and `social_follows`
   rows, the likes and reposts become actions — through the import path,
   nothing re-published.

### 13.2 Moving away

The mirror image, so a person is not locked in: `Settings → Migration →
Move your Bluesky account away` performs steps 2–5 from the other side
(this instance is the *old* PDS: it serves `getServiceAuth`,
`requestPlcOperationSignature` by Nextcloud e-mail, `signPlcOperation`,
`deactivateAccount`). The Fediverse account is untouched; after the move
the local actor has no Bluesky identity until §4.1 creates a fresh one —
which it does not do automatically for a moved-away account (flag on the
identity row), so the person is not silently given a second Bluesky
account.

### 13.3 Bridgy Fed twins (D18)

An account that was bridged to Bluesky by Bridgy Fed has a Bluesky
identity `alice.social.example.com.ap.brid.gy` with its own `did:plc` and
followers. Folding it in is the move-in of §13.1 with Bridgy as the old
PDS — Bridgy Fed supports account migration out — so the followers come
along; the page offers it when the handle resolves to a `*.ap.brid.gy`
DID, and tells the person to turn the bridge off afterwards.

## 14. Operations

### 14.1 Requirements

HTTPS on the instance host; a **wildcard DNS record** `*.<host>` to the
same server and a **wildcard certificate** (Let's Encrypt DNS-01), for the
handle hosts; the **root web-server rules** (§6.1) including the WebSocket
proxy for the firehose; the **daemon** `occ social:atproto:serve` under
systemd (or the container's supervisor); `cron` background jobs (already
required); PHP `gmp` (for `paragonie/ecc`) and `sodium`. Each is a **setup
check** on the admin page and in `occ social:check:install`, with the fix
spelled out, and Bluesky cannot be switched on while one fails.

### 14.2 Admin page

A **Bluesky** section: the instance switch (D3), the handle host (read
from `social_url`, with the wildcard checks beside it), the relay(s), the
Jetstream endpoint and whether the listener is running, the service DID,
the instance rotation key's age and a *Rotate* button (§16.3), the sync
ceiling and the current lag, counts (identities, records, blobs, watches,
events in the window), the PDS/DID blocklist (§12.4), and the state of
each daemon (last frame served, last event seen, pid, uptime). The same
numbers on the Statistics page's agency view, labelled.

### 14.3 Commands

`social:atproto:serve` (§7.1), `social:atproto:listen` (§9.4),
`social:atproto:identities` (create missing identities; `--user`),
`social:atproto:plc` (log and directory view, `--repair`),
`social:atproto:resolve <handle|did>`, `social:atproto:repo <user>`
(head, record counts, `--verify` recomputes the MST and compares),
`social:atproto:block <host|did>` / `--unblock`, `social:atproto:crawl`
(`requestCrawl` now), `social:atproto:rotate-key`. Each documented in
OCC-Commands.md with the usual shape.

### 14.4 Configuration

App values: `atproto_enabled`, `atproto_relays` (JSON list),
`atproto_jetstream`, `atproto_sync_ceiling`, `atproto_plc_directory`
(default `https://plc.directory`, the dev-env's in CI), `atproto_appview`
(default `https://public.api.bsky.app` for reads, `https://api.bsky.app`
for the proxy), `atproto_video_service` (later). Secrets (keys) in their
tables, sealed; never in app values.

### 14.5 Backups

The instance rotation key and the account signing keys are **the
identities**: losing them loses the ability to update the DIDs (the
recovery phrase, §4.4, is the person's way back; the PLC's 72-hour window
is the administrator's). Admin.md gets a section: back up the database
*and* the instance secret, and what to do after a restore from an older
backup (the firehose `seq` goes backwards → `requestCrawl` and the relay
re-syncs from `getRepo`).

## 15. Data model

New tables, each in a **new migration step** (never the squash), with the
`CoreRequestBuilder::$tables` entry, the Architecture.md schema row and the
regenerated schema check that `SchemaConventionsTest` expects:

| Table | Row |
|---|---|
| `social_atproto_identity` | one per local actor: `actor_id(_prim)`, `did`, `handle`, `signing_key` (sealed), `signing_public`, `recovery_public`, `state` (`active`/`deactivated`/`moved_away`/`tombstoned`), `moved_from_pds`, timestamps. Unique on actor and on DID |
| `social_atproto_instance_key` | the instance's rotation and service keys, sealed, with `kind` and `created` (rotation keeps the previous one for the PLC window) |
| `social_atproto_repo` | one per DID: head `commit_cid`, `rev`, `record_count`, `blob_bytes`, `updated` |
| `social_atproto_record` | `did`, `collection`, `rkey`, `cid`, `bytes` (DAG-CBOR), `local_id(_prim)` (the Social object), `created`. Unique on (did, collection, rkey); index on `local_id_prim` |
| `social_atproto_block` | `cid`, `did`, `bytes`, `kind` (`mst`/`commit`/`record`); unique on (did, cid) |
| `social_atproto_blob` | `did`, `cid`, `document_id` (the `social_cache_doc` row), `mime`, `size`; unique on (did, cid) |
| `social_atproto_event` | the firehose frames: `seq` (primary), `did`, `kind`, `bytes` (the frame), `time`; pruned past the window |
| `social_atproto_plc_log` | `did`, `cid` (operation), `operation` (JSON), `sent`, `confirmed` |
| `social_atproto_watch` | followed Bluesky authors: `did`, `handle`, `cursor`, `last_sync`, `next_sync`, `failures`, `last_error`; one per DID, however many local followers |
| `social_atproto_notify_cursor` | per local DID: the AppView notifications cursor, `last_sync`, `next_sync`, `failures` |
| `social_atproto_labeler` | per user: labeler DID, the per-label settings (JSON), `added` |
| `social_atproto_blocklist` | admin blocks: `kind` (`host`/`did`), `value`, `reason`, `created` |
| `social_atproto_session` | app-password sessions for Bluesky apps (§6.3): `did`, `jti`, refresh token hash, `expires`, `created`; OAuth later adds its own |

Existing tables gain nothing except: `social_stream.id` may be an `at://`
URI (no schema change; `id_prim` handles it), `social_cache_actor.id`
likewise, and `details` JSON on both carries `atproto` blocks.

## 16. Security

### 16.1 Keys

Signing and rotation private keys are sealed with the instance secret
through `PrivateKeyCipher`, the same primitive as app passwords; decrypted
for one signature and discarded; never logged, never exported (the account
export carries the DID and the handle, not the keys — a moved repository
is re-signed by the new PDS's key, which is how the protocol works).
`paragonie/ecc` (secp256k1, deterministic RFC 6979 signatures, low-S
normalisation) with `gmp`; interop vectors pin the signature format.

### 16.2 Service auth and the proxy

Service-auth JWTs are short-lived (60 s), bound to `lxm` (the method) and
`aud`, signed per request; the AppView proxy forwards only `app.bsky.*`,
strips `Authorization` from the client and adds its own, passes
`atproto-accept-labelers` and `atproto-proxy` through, and limits the body.
A proxied call is counted against the user's rate limit.

### 16.3 Rotation

The instance rotation key is rotated by the administrator (`occ
social:atproto:rotate-key`, admin page): a PLC operation per identity
(queued, rate-limited by the PLC's limits — *verify*), the old key kept
for the 72-hour window and then deleted. An account's signing key is
rotated when its sealing secret changes (Nextcloud's `secret` rotation) —
the setup check notices the seal no longer opens and the repair re-signs
the repository with a fresh key and a PLC update.

### 16.4 Input

Records from Bluesky are untrusted input: the lexicon validator rejects
what does not fit, facets are clamped to the text's byte length, every URL
goes through the same guarded fetch as ActivityPub documents (no local
addresses, the federation blocklist, size ceilings), blobs are fetched
only from the AppView CDN or the author's PDS endpoint and only as the MIME
types the record declares, and text is escaped on render as every remote
post is. The firehose daemon accepts connections but reads nothing from
them beyond the `cursor` query; a slow consumer is dropped after a bounded
buffer, as the reference relay does to a slow PDS.

### 16.5 What is published

Only what D8 and D16 allow: public posts, their pictures, profile fields
that are already public on the Fediverse, follows of Bluesky accounts,
likes and reposts of Bluesky-visible posts. Nothing else is ever written
to a repository, and `RecordMapper` is the one place that could, so one
test class (§17) is the whole of that guarantee.

## 17. Testing

### 17.1 Unit

- Protocol primitives against **bluesky-social/atproto-interop-tests**:
  DAG-CBOR encoding, CID computation, TIDs, MST construction and diff
  (the published trees and their expected CIDs), commit signing (low-S),
  handle and DID syntax, AT URI parsing, lexicon validation of the records
  this app writes. Vendored as fixtures.
- `RecordMapper` both ways: every rule of §8 and §9.3, including the
  truncation at 280 graphemes with combining characters and emoji, facet
  byte offsets on multi-byte text, the four-picture cap, the CW prefix.
- Services with mocked HTTP: identity creation and PLC operations, the
  poller's backoff and ceiling, the notifications mapping of §10, the
  label mapping of §12.1, the migration steps of §13 in order.

### 17.2 Integration and interop (D21)

A workflow like `interop.yml`: the official `@atproto/dev-env` TestNetwork
(PLC, PDS, AppView, bsync, Ozone) plus an `indigo` relay (the dev-env
starts none), this app behind the Caddy of the interop job with the root
rules and the firehose proxy, the daemon started. Tests: a Bluesky user on
the dev PDS follows `alice.nextcloud.test`, sees her post in the AppView's
author feed and timeline, likes it and replies — asserted here through
Activities; alice follows the dev user and his post appears in her home
feed; a picture round-trips; a label from the dev Ozone hides a post; an
account migrates from the dev PDS to here and its follower still follows.
The real network (`plc.directory`, `bsky.network`) is touched by hand on a
public https host only — devel is http and cannot be a PDS; the admin page
says so when it detects http.

## 18. Phases

One PR each, each green on its own, each shrinking this document into
Architecture/API/OCC-Commands/Admin/User-Guide as it lands
([docs stay current](Architecture.md#keeping-this-document-in-sync)).

| Phase | Delivers | Demonstrable as |
|---|---|---|
| **1 — Visible on Bluesky** (D20) | §4 identity and PLC, §5 repository with profile and post records (text, links, pictures, CW, polls-as-text, quotes-as-link), §6.1–6.2 sync + identity endpoints and root rules, §7 firehose daemon and `requestCrawl`, §8.1–8.5 publisher (edits/deletes), §14 requirements, admin section, setup checks, `occ` basics, §15 tables 1–8, §16, §17.1 + the interop job's first tests | A Bluesky user searches `alice.<host>`, sees profile and posts, follows |
| **2 — Reading Bluesky** | §9 resolution, following, the poller, the storer, deletes; §10 notifications poll and the mapping; §8.6 likes/reposts/follows; §11 badges, profiles, addressing, mention picker; §12.1 labels honoured; §9.4 optional Jetstream | alice follows `bob.bsky.social`, reads him in her feed, likes his post, sees his like and reply in Activities |
| **3 — Complete interaction and apps** | §8.3 replies and quotes to Bluesky posts both ways, link cards, §12.2 labelers, §12.3 reporting, §12.4 instance blocks, §6.3 app passwords then OAuth for Bluesky apps, threadgates, video (D11) through the video service | the official Bluesky app logs in to Aloha Social as its PDS; a thread spans both networks |
| **4 — Moving** (D19) | §13 move in, move away, Bridgy twins; §4.2 custom handles | a Bluesky account moves here with its followers; a person sets `alice.example.org` as their handle |

Each phase has its version bump, its tests per commit, its docs in the
same change, and its interop job extended before the PR is opened.

### Phase 1 as built

Everything in the phase 1 row, in `lib/Atproto/` (`Protocol`, `Crypto`,
`Lexicon`, `Identity`, `Repository`, `Firehose`, `Publisher`, `Xrpc`,
`Service`), with these departures from the letter of the sections above,
each for a reason:

- **No `paragonie/ecc`, no gmp.** Signing and verification use OpenSSL's
  secp256k1 and P-256 (`Crypto\PrivateKey`, `Crypto\PublicKey`), which every
  Nextcloud host has; the little arithmetic OpenSSL does not expose
  (low-S, point decompression) is a hundred lines of pure PHP
  (`Crypto\BigNum`). A gmp requirement would have kept Bluesky off hosts
  that have never needed it.
- **No `ratchet`/`react`.** The firehose daemon is plain PHP sockets
  (`Firehose\WebSocketServer`): what a firehose needs of RFC 6455 is a page,
  and the library would have brought a PSR-7 implementation into the app's
  bundle beside the server's own.
- **The recovery key is issued on request**, from Settings → Your account,
  not at identity creation: an identity made in a background job has nobody
  there to show the phrase to. The phrase is twelve BIP-39 words (128 bits);
  the key is derived from them.
- **Pictures** are the stored original when it fits Bluesky's 2,000,000
  bytes and is a type Bluesky shows, re-encoded as JPEG otherwise and stored
  as a document of their own. Link cards (§8.3) are not yet built: a post
  with a link and no pictures carries the link as a facet only.
- **Replies** to a post that is on Bluesky — a local post that was published
  — are replies there (§8.3); to anything else they are a post with the
  parent linked. Quotes are a link. Both as the section says; the AppView
  thread walk for remote parents is phase 2.
- **Edits, deletes, profiles** as §8.5 and §5.1; a profile change is
  republished when the profile is saved (`AccountService::changingProfile`)
  and when the identity is first viewed.
- **Handles** are checked for syntax by `Protocol\Syntax::isHandle()` and,
  before anything is resolved, for a top-level domain that can exist by
  `isResolvableHandle()` — `.test` kept, for the interop job.
- **The interop job** (`interop-atproto.yml`) uses `@atproto/dev-env` with a
  second AppView subscription to this app's firehose rather than routing the
  dev PDS through the relay: the stock relay will not crawl a loopback or
  private address, so the relay in the job crawls this app alone (on a
  routed address the runner answers on its loopback) and the test treats it
  as evidence when it is there.
- **Suspension** leaves the identity alone (it can be lifted, and nothing is
  told); deletion tombstones the DID.

## 19. Open questions

For the owner, not blocking phase 1:

1. **Per-user opt-out.** D3 says every account, automatically. Should a
   person be able to switch *their* Bluesky identity off (deactivate the
   repository; the DID stays theirs) — a Nextcloud user who wants no
   presence on Bluesky at all? Recommended: yes, in phase 2, as
   `deactivateAccount` on their own identity with a plain explanation.
2. **Bluesky-only replies.** A Bluesky user replies to alice's post; alice
   answers from here; her answer goes to Bluesky (§8.3) — and to the
   Fediverse, where the parent does not exist. Publish it as an ordinary
   public post there (the parent linked), or keep a reply-to-Bluesky off
   the Fediverse? Recommended: publish, parent linked — one post, two
   addresses, as D8 says.
3. **Who pays for pictures.** A re-encoded picture is a second stored file
   per picture per post. Count it against the user's quota (it is their
   post) or the app's? Recommended: the user's, like the original.
4. **Relay choice.** `bsky.network` only, or also announce to independent
   relays by default? Recommended: `bsky.network` default, list editable.

## 20. Facts, and when they were checked

Checked 2026-09-25 unless marked; **verify** means re-check before the code
that depends on it is written, because the number or the API has moved
before.

- Post: 300 graphemes / 3,000 bytes; 4 images ≤ 2,000,000 bytes each;
  video mp4 ≤ 300 MB via the video service (*verify* current video limits
  and the `app.bsky.video.uploadVideo` / `getJobStatus` flow and its
  service-auth `aud did:web:video.bsky.app`).
- Commit `version: 3`, `prev: null`, 64-byte low-S secp256k1 signature;
  MST fanout 4, 2 bits per level; CID v1 dag-cbor sha-256.
- PLC: `POST /:did` operations, `GET /:did` document, `/:did/log/audit`,
  72-hour recovery window; `did:plc` is 24 base32 chars of the genesis
  operation's hash.
- Handle resolution: DNS TXT `_atproto.<handle>` or
  `https://<handle>/.well-known/atproto-did`.
- Account migration endpoints (checked 2026-10-06 against atproto.com):
  `com.atproto.server.describeServer`, `getServiceAuth`, `createAccount`,
  `checkAccountStatus`, `activateAccount`, `deactivateAccount`;
  `com.atproto.repo.importRepo`, `listMissingBlobs`; `com.atproto.sync.getRepo`,
  `listBlobs`; `com.atproto.identity.getRecommendedDidCredentials`,
  `requestPlcOperationSignature`, `signPlcOperation`, `submitPlcOperation`.
- Bluesky OAuth for apps needs PAR, DPoP, PKCE S256 and a client-metadata
  URL (phase 3).
- `@atproto/dev-env` TestNetwork starts PLC, bsync, AppView, PDS and Ozone
  and **no relay** — `indigo` `cmd/relay` for CI.
- Jetstream: `wantedCollections`, `wantedDids` (*verify* the 10,000 limit
  and the `#options_update` frame), `cursor` in microseconds, public
  instances `jetstream1/2.us-east.bsky.network`, `jetstream1/2.us-west…`.
- Bluesky moderation service DID `did:plc:ar7c4by46qjdydhdevvrndac`;
  reports via `com.atproto.moderation.createReport` with
  `atproto-proxy: <did>#atproto_labeler`. *verify* label value names.
- PDS rate limits (3,000 points/hour/DID for writes; per-IP on
  `createSession`): *verify*.
- Packagist: `spomky-labs/cbor-php` 3.4.2, `paragonie/ecc` 2.6.0 (gmp),
  `ratchet/rfc6455` 0.4.1, `react/socket` 1.17.0 — usable on PHP 8.3.
  `aazsamir/libphpsky` and `karanshukla/php-atproto-identity` need PHP ≥ 8.4
  (floor is 8.3); `socialweb/atproto` is a 2023 alpha — not used. Verify
  each against Packagist before adding to `composer.json`.
- Interop vectors: github.com/bluesky-social/atproto-interop-tests.
