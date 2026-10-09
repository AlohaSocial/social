<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Bluesky and AT Protocol compatibility

**Status: phases 1, 2 and 3 (§18) and phase 4 — custom handles (4a), moving away (4b), moving here (4c), Bridgy twins with any migration tool (4d) and a moved account's posts in its timeline (4e) — are implemented, as are custom feeds and lists (§9.6).**
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
| D15 | **Direct messages** are Bluesky's chat service's: a Bluesky app signed in here reaches them through this PDS (`chat.bsky.*` proxied to the configured chat service), with a privileged app password or an OAuth app given `transition:chat.bsky`, as on Bluesky. They are not bridged into this app's own direct messages. | 10-06, revised 10-09 |
| D16 | **Mutes are never published, and blocks only as the person chooses** (revised 10-09: "Publish my blocks of Bluesky accounts", off by default, since a Bluesky block is public); in scope: honour Bluesky's moderation labels, let users subscribe to labelers, file reports with Bluesky's moderation service, instance-level blocks of PDS hosts and DIDs. | 09-25, extended 10-06 |
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

Nothing, unless they look: **Settings → Apps and account → Bluesky** shows the Bluesky
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
| `app.bsky.actor.profile` (rkey `self`) | account created, profile edited, a picture or a pin changed | `displayName`, `description` (plain text, bio), `pronouns` (the profile row named for them, at most 20 graphemes) and `website` (the row named for one, else the first row that is an address), `avatar` and `banner` blobs (each at most 1,000,000 bytes, JPEG or PNG, re-encoded otherwise), `pinnedPost` (the newest pin that is on Bluesky), `createdAt`; written again when the pinned post's record is replaced by an edit or deleted |
| `app.bsky.feed.post` | public post created (D8) | §8 |
| `app.bsky.feed.like` | a local actor likes a post that **exists on Bluesky** (a Bluesky post, or a local post that was published, §8.6) | `subject` {uri, cid} |
| `app.bsky.feed.repost` | a local actor boosts such a post | `subject` {uri, cid} |
| `app.bsky.graph.follow` | a local actor follows a Bluesky account (§9.2) | `subject` DID |
| `app.bsky.feed.threadgate`, `app.bsky.feed.postgate` | a post written here whose author narrowed who may reply or quote (§8), written with the post under its key and rewritten when the author changes it; or a Bluesky app signed in here writes one for one of the account's own posts (§6), kept under that post's key | `post`, `allow` (threadgate) or `embeddingRules` (postgate), `createdAt`; as the app wrote it |
| `app.bsky.graph.list`, `listitem`, `starterpack`, `app.bsky.feed.generator` | a Bluesky app signed in here writes one (§9.6) | as the app wrote it |
| `app.bsky.graph.block` | a local actor blocks a Bluesky account, **only when the person publishes their blocks** (D16, `Publisher\BlueskyBlocks`) | `subject` DID |
| `app.bsky.graph.listblock` | **never** (D16) | — |
| `chat.*` | never | — |

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
post; a video is one mp4 ≤ 100,000,000 bytes and three minutes, made into
a stream by Bluesky's video service (D11, built in 3d).

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
did:web:api.bsky.app>`): everything under `app.bsky.*`; `chat.bsky.*`
goes to Bluesky's chat service (`did:web:api.bsky.chat`) the same way
(D15). The proxy is `AppViewClient`, the same
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
- **Who may reply** (`app.bsky.feed.threadgate` on the thread's root): a
  thread its author closed to everybody is marked when it is read, so the
  reply is not offered here (`interaction_policy.reply` false); a narrower
  gate — the accounts the root mentions, its author's followers, the
  accounts the author follows, a list's members — is checked when somebody
  replies (`Reader\Threadgates`, through the AppView), and a reply it does
  not let through is refused with the reason: every AppView would hide it.
  This app's own posts carry the author's choice the same way: a post
  whose author lets only their followers, the accounts they follow, the
  accounts it mentions, or nobody reply is published with a threadgate
  under its key (`followerRule`, `followingRule`, `mentionRule`, or no
  rule at all), which every AppView holds Bluesky replies to; a change of
  mind later rewrites or removes the gate (`Publisher::updateGates()`).
  The rule is held here as well (`ReplyRuleService`): a reply from here
  it does not let through is refused with the reason, and one from
  another server or from Bluesky is not kept. The author always may.
- **Who may quote** (`app.bsky.feed.postgate` beside the quoted post): a
  quote of a post whose author turned quoting off (`disableRule`) is
  refused with the reason (`Reader\Postgates`), since every AppView would
  show it detached. The gate is read from the author's own PDS, under the
  post's key, because the AppView tells only a signed-in viewer; a post
  read as such a viewer and marked `embeddingDisabled` is not offered for
  quoting (`interaction_policy`). The author quoting their own post is not
  asked about.
- **Language**: `langs: [<post language>]` when known.
- **Link card**: when the post has a `StreamCard` and no pictures,
  `app.bsky.embed.external` with title, description and the preview image
  as a blob (fetched through the cache, resized to ≤ 1,000,000 bytes, the
  lexicon's limit for a card's thumbnail).

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
name, `description` → summary as plain text, `pronouns` and `website`
→ profile rows named Pronouns and Website, which is where the rest of the
network keeps them, avatar and banner as cached documents,
`followersCount`/`followsCount`/`postsCount` into the counts,
the profile's `pinnedPost` a pin as a Fediverse account's pins are, its
`verification` — verified, by whom, a trusted verifier itself — onto the
Account entity's `bluesky` block, where the app draws Bluesky's check),
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
blob's CID and alt text, a quote as `quoted_id`, an embedded custom feed,
list or starter pack as a link to its bsky.app page with a card made from
what the AppView says of it — its name, description, creator and picture,
no page read (`PostMapper::cardOf()`) — a reply as `in_reply_to`,
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

### 9.6 Feeds and lists

Bluesky's custom feeds and lists are read here as timelines
(`Reader\BlueskyFeeds`, `AtprotoFeedsController`):

- **Which ones a person keeps is their Bluesky preference**
  (`app.bsky.actor.defs#savedFeedsPrefV2`, the one `getPreferences` keeps,
  §6), so a feed saved in a Bluesky app signed in here shows in Social and
  the other way round. Settings → Reading → Bluesky feeds adds one by its
  `at://` URI or bsky.app address (`/profile/<handle or DID>/feed/<key>`,
  `/lists/<key>`), from Bluesky's suggestions (`getSuggestedFeeds`), and
  removes one; the sidebar lists them under Explore, sharing the room of
  the person's lists.
- **A feed is read from the AppView as the person** (`getFeed`,
  `getListFeed`, with a service-auth token for their DID), so a feed that
  ranks for its reader ranks for them. The posts are stored as any Bluesky
  post is and answered in the feed's order, without a hidden one (a label,
  a block, a muted account). The AppView's cursor is opaque: it is kept a
  hour for the last post of each page, so the next page is asked with the
  id of the post the client last saw.
- **Routes**: `GET`/`POST`/`DELETE /api/v1/social/bluesky/feeds`,
  `GET /api/v1/social/bluesky/feeds/suggested`, and
  `GET /api/v1/timelines/bluesky?feed=<at:// URI>` (API.md). In the web
  client the address is `/timeline/bluesky/<DID>/<feed|list>/<key>`: the
  URI's own slashes stay out of the path, as web servers refuse an encoded
  one.
- **Lists made in a Bluesky app** are written to the account's repository
  (§6), so they reach the AppView, and read here as their feed.
- **Discover** shows what is trending on Bluesky beside what is trending
  here — a topic that is a custom feed opens as one, any other as a
  search — and the accounts Bluesky suggests to the person, read as them
  and followed by their handle like anybody else there
  (`Reader\BlueskyDiscovery`). Trends are the same for everybody and kept
  ten minutes; an AppView without them answers nothing rather than an
  error.
- **Starter packs** open here at the path bsky.app gives them,
  `/starter-pack/<handle or DID>/<key>` — from a starter-pack card in a post
  (§9, `PostMapper::cardOf()`) or an address typed into the search
  (`Reader\StarterPacks`). The page lists the members (up to 150, read as
  the person, so whom they follow already is marked) and the feeds; it
  follows everybody not followed yet or one at a time, 25 to a request, as
  any Bluesky account is followed, and keeps the feeds when asked, as the
  Bluesky app does.

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
| `subscribed-post` | a post of an account whose bell the person rang in a Bluesky app: the post stored, and the bell's own notification (`status`), under the id a bell rung here gives it, so one rung both ways tells once. Ringing the bell here on a Bluesky account rings it on Bluesky too (`app.bsky.notification.putActivitySubscription`, `Reader\ActivitySubscriptions`), so a Bluesky app shows it |
| `like-via-repost`, `repost-via-repost` | `bluesky:repost_liked`, `bluesky:repost_reposted`: somebody liked or reposted the person's repost, about the post that was reposted |
| `verified`, `unverified` | `bluesky:verified`, `bluesky:unverified`: a trusted verifier verified the account on Bluesky, or no longer does |
| `starterpack-joined` | `bluesky:starterpack_joined`: somebody joined Bluesky with the person's starter pack |

The `bluesky:` kinds are this app's own, as Pixelfed's `story:` ones are:
the web app words them; a Mastodon client leaves out a kind it does not
know, as Mastodon's API asks; they raise no Nextcloud notification.

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
- **Settings → Apps and account → Bluesky**: the Bluesky handle/DID block and the
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
stored in `social_actor_relation`, applied on read. A mute is never
published; a block is written as `app.bsky.graph.block` **only when the
person chose to publish their blocks** (Settings → Bluesky, off by
default, because a Bluesky block is public), and only a published one
keeps the blocked account from replying to, quoting or mentioning them on
Bluesky (D16). Turning it on publishes the blocks of Bluesky accounts they
hold; turning it off withdraws every published one; an unblock withdraws
its record. A blocked Bluesky account's interactions are dropped on
arrival either way. A Bluesky user's public block *of* a
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
for the proxy), `atproto_video_service` and `atproto_video_service_did`
(default `https://video.bsky.app`, `did:web:video.bsky.app`; an empty
address publishes a video post as a link). Secrets (keys) in their
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
| `social_atproto_identity` | one per local actor: `actor_id(_prim)`, `did`, `handle`, `custom_handle` with `custom_handle_checked`/`_failures` (§4.2, phase 4), `signing_key` (sealed), `signing_public`, `recovery_public`, `state` (`active`/`deactivated`/`moved_away`/`tombstoned`), `moved_from_pds`, timestamps. Unique on actor and on DID |
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
says so when it detects http. That run is
[Atproto-Live-Test.md](Atproto-Live-Test.md): `contrib/atproto-live-probe.sh`
checks the host from outside (PDS, service identity, handle, DID document,
firehose, relay, AppView, OAuth metadata), then a checklist goes through
every feature with the Bluesky app on the other side.

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
- **The recovery key is issued on request**, from Settings → Apps and account → Bluesky,
  not at identity creation: an identity made in a background job has nobody
  there to show the phrase to. The phrase is twelve BIP-39 words (128 bits);
  the key is derived from them.
- **Pictures** are the stored original when it fits Bluesky's 2,000,000
  bytes and is a type Bluesky shows, re-encoded as JPEG otherwise and stored
  as a document of their own. The profile's avatar is the picture the
  person chose for their Nextcloud account — none for a generated one, or
  where they keep their avatar from other servers — read from Nextcloud and
  stored as a document of its own. A picture whose bytes are a blob already
  is that blob, so a copy is stored once. A post with a link and no pictures carries
  its link card (§8.3) — title and description, and the page's
  picture: fetched once as a cached remote document, through the guards
  any remote file passes, and re-encoded when it is over a card's
  1,000,000 bytes (`Publisher\CardThumbnail`). A picture that cannot be had
  leaves the card without one.
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

### Phase 2 as built

Everything in the phase 2 row, in `lib/Atproto/Reader/` (`BlueskyActorService`,
`ActorMapper`, `PostMapper`, `FacetRenderer`, `PostStore`, `FeedPoller`,
`NotificationPoller`, `BlueskyGraphService`, `BlueskySearch`,
`LocalRecordResolver`), `lib/Atproto/AppView/` (`AppViewClient`,
`ServiceAuth`), `lib/Atproto/Publisher/InteractionPublisher`, and
`Cron\AtprotoSync`, with these departures from the sections above:

- **Ids are https URLs on bsky.app, not `at://` URIs.** Every id this app
  stores must be an https URL: `id_prim` is empty for anything else, the
  import validation drops it, and the origin checks compare hosts. A
  Bluesky account is `https://bsky.app/profile/<did>`, a post
  `https://bsky.app/profile/<did>/post/<rkey>` — by DID, so a handle change
  moves nothing — and the `at://` URI, the CID and the AppView's counts ride
  in `details.atproto`. The page link (`details.page_url`) is by handle.
- **The cursor is the newest index time seen**, not the AppView's paging
  cursor: `getAuthorFeed` pages backwards, so each read takes the newest
  page and stops at the first item at or before the last read. Reposts are
  ordered by the repost's own time, as the feed is.
- **Replies to a local post carry a mention of its author.** Bluesky names
  nobody in a reply; the mention tag is what makes the notification here,
  as it does for a Mastodon reply.
- **A reply's parent is resolved only through the record table**: a Bluesky
  reply to `at://<local did>/…` lands under the local post; a reply to a
  Bluesky post that is not here is stored with its bsky.app parent id and
  the ordinary unknown-parent fetch is not run for it (one hop through
  `getPostThread` is phase 3).
- **Deletes from Bluesky are not yet noticed** (§9.5): a post gone from the
  author feed stays until phase 3 adds the daily `getPosts` check or the
  Jetstream listener. Nor is the optional Jetstream listener (§9.4) built.
- **No quote notification**: Social has none (the spec was wrong there); a
  quote of a local post is stored as the post it is in and shows in the
  feed of anybody who follows the quoting account.
- **Search** (§9.1) asks the typeahead only for text that is the start of a
  handle — a dot in it, no `@` — so a plain username never leaves the
  instance; what it finds is not stored until somebody follows or
  mentions it.
- **The admin's federation block list applies to a handle's domain** (the
  part after the first dot) in the directory; the per-DID/PDS-host block
  list of §12.4 is phase 3, as are labelers (§12.2) and reporting (§12.3).
  Of §12.1, posts and accounts labelled `!hide`/`!takedown` are not stored
  or read, the adult labels make the pictures sensitive with a warning
  naming the label, and other service labels become a warning; labelers'
  own definitions are not read yet.
- **Per-user opt-out** (§19.1, taken as recommended): Settings → Your
  account has a switch; off, the account is announced inactive on the
  firehose and by `getRepoStatus`, nothing more is published for it and its
  notifications are not read; the DID and repository stay.
- Likes and reposts are written by the queued publish job, like posts, so
  the request that made them never waits for a repository commit; a like
  of a Fediverse-only post writes nothing.
- The authenticated AppView (`atproto_appview_auth`, default
  `https://api.bsky.app`) and its DID (`atproto_appview_did`, default
  `did:web:api.bsky.app`) are configuration of their own beside the public
  one, because the public AppView refuses authenticated requests; the
  interop job points both at the dev AppView.

### Phase 3 as it lands

Phase 3 is five concerns, so it lands as five pull requests in this order:
**3a** interaction (threads, quotes, link cards, deletes both ways, quote
rules), **3b** moderation (§12.2–12.4), **3c** Bluesky apps logging in with
app passwords (§6.3), **3d** video (D11), **3e** OAuth for Bluesky apps
(§6.3).

**3a as built** — `Publisher\PostRefs` (the strong reference of a local
post that was published or of a Bluesky post read here, and a reply's
thread root), `RecordMapper` (reply, quote and card embeds, the postgate),
`InteractionPublisher::removeAllOf()`, `Reader\DeletionSweep`, and
`PostStore::storeByUri()`/`deleteGone()`:

- **Replies** to a Bluesky post read here are replies in its thread there:
  the parent is the post's stored URI and CID; the root is what was stored
  with the parent when it was read (`details.atproto.reply_root`, new), or
  what the AppView says of it, or the parent itself. A Bluesky reply whose
  parent is not here fetches the parent one hop up (`getPosts`), never
  further.
- **Quotes** of a post that is on Bluesky are `app.bsky.embed.record`
  (`recordWithMedia` with pictures); of anything else, the link as before.
  A quote of a Bluesky post stands at once: Bluesky asks nobody's
  permission, so no FEP-044f `QuoteRequest` waits for an answer, and it
  reads as accepted, as does a Bluesky post quoting anything.
- **Detached quotes**: the author of a post here detaches a Bluesky
  quote of it from the quote list as they would a Fediverse one; the
  quoting post's URI goes into the post's postgate
  (`detachedEmbeddingUris`, the newest 50, `Stream::addDetachedQuote()`),
  so every AppView shows the quote detached, and it reads as revoked here.
  A Bluesky quote whose quoted author detached it arrives as revoked
  (`viewDetached`).
- **Link cards**: the post's link preview as `app.bsky.embed.external`
  when it has a title and the post has no pictures and quotes nothing —
  **without a thumbnail**: the preview's picture is a remote URL, and a
  blob here is a stored document; storing card pictures idempotently
  across reconcile passes is left for later. A card that appears after the
  post was published is picked up by the reconcile pass within the edit
  grace period, as an edit would be.
- **Quote rules**: a post whose quote policy is followers-only or nobody
  gets an `app.bsky.feed.postgate` under its rkey with `disableRule`.
  Bluesky cannot say "followers only", so the stricter rule is published
  rather than an open one. A later change of the quote policy rewrites
  the postgate, or removes it.
- **Reply rules**: a post whose author narrowed who may reply gets an
  `app.bsky.feed.threadgate` under its rkey: followers →
  `followerRule`, the accounts followed → `followingRule`, the accounts
  mentioned → `mentionRule`, nobody → an empty `allow`. Everybody writes
  no gate. A change later is one commit that updates, makes or deletes it.
- **Deletes**: a local post's delete removes its gates with it and the
  like and repost records local accounts made of it (§8.5); a post deleted
  on Bluesky is noticed by the maintenance job, which asks the AppView
  about a page of 25 stored Bluesky posts of the last week per run and
  deletes what it no longer has (§9.5), the local likes' records too. An
  AppView that does not answer proves nothing and nothing is deleted.
- References are validated before they are written: a CID that is not a
  CID makes the post "not on Bluesky" (linked), never a refused commit.

**3b as built** — `Moderation\Blocklist` (the checks) and
`Moderation\BlocklistManager` (blocking, unblocking, purging),
`Moderation\BlueskyReporter`, `Moderation\LabelerService`, tables
`social_atproto_blocklist` and `social_atproto_labeler`:

- **Instance blocks** (§12.4) by DID or PDS host — a host blocks the hosts
  under it too. Checked wherever Bluesky comes in: resolution, the feed
  read (a blocked author's watch is dropped unread), notifications, stored
  posts, search. Blocking purges the blocked accounts that are followed
  here with the primitive a domain block uses (`ModerationService::purgeActor()`);
  a blocked host purges the followed accounts it hosts and refuses the rest
  as they come. `occ social:atproto:block`, and the admin card.
- **Reporting** (§12.3) is the `forward` of a Mastodon report: for a
  Bluesky account, `ReportForwardService` hands it to `BlueskyReporter`,
  which sends `com.atproto.moderation.createReport` to Bluesky's moderation
  service (`atproto_moderation_did`), the first reported post that is on
  Bluesky as the subject, else the account. **Made by this server, not the
  reporter**: the token is signed by the instance's `did:web` with its
  service key, which its DID document publishes — the same reason the
  Fediverse forward is signed as the instance. The report row records that
  it was forwarded; the Bluesky report id is logged, not stored.
- **Labelers** (§12.2): Bluesky's moderation service always applies, at read
  time, as §12.1 says; others are subscribed per person (by handle or DID,
  checked to be a labeler), each label value set to ignore, warn or hide,
  the labeler's own default until changed. Because one read of an author's
  feed serves everybody here who follows them, the read asks for every
  labeler anybody subscribes to (`atproto-accept-labelers`, at most 20),
  each post keeps its labels with their labeler (`label_sources`), and the
  choices apply when a person reads, in `FilterService`: a warn is a filter
  result naming the label, a hide leaves the post out. The status entity
  carries `bluesky: {uri, url, labels}` for this. Labeler definitions are
  read from the AppView once a day.
- Not done: reading a Bluesky user's public block of a local account
  (`viewer.blockedBy` is per viewer, and the reads here are anonymous).

**3c as built** — `Atproto\Client\` (`AppPasswordService`, `SessionService`,
`AppViewProxy`, `Preferences`, `WriteService`, `ClientXrpc`), tables
`social_atproto_app_password` and `social_atproto_session`:

- **App passwords are this app's, not Nextcloud's.** §6.3 said a Nextcloud
  app password; that would hand a Bluesky app the whole of Nextcloud —
  files, WebDAV — and can only be checked through the server's private API.
  Settings → Apps and account → Bluesky makes Bluesky-style app passwords instead (four
  groups of four, shown once, stored as a password hash, at most 25),
  good for this app's Bluesky surface only. Revoking one ends every session
  it opened. A wrong password counts against the caller's address in
  Nextcloud's brute-force protection, and the answer for an unknown account
  and a wrong password is the same.
- **Sessions** are JWTs this server signs with its service key: an access
  token of two hours naming its session, a refresh token of ninety days
  whose id is the session (`createSession`, `refreshSession`,
  `getSession`, `deleteSession`). A suspended account's apps are turned
  away; an account that moved away cannot sign in.
- **The AppView proxy** (`app.bsky.*`) goes to the configured AppView only —
  `atproto-proxy` cannot point the account's signature elsewhere — with a
  token the account's own key signs for the one method; the answer passes
  through with its status. `chat.bsky.*` goes the same way to the chat
  service the administrator configured (`atproto_chat`, by default
  `https://api.bsky.chat`; empty turns direct messages off, 501), for a
  session that may reach it: an app password made **privileged** (Settings →
  Bluesky, "Allow direct messages", off by default, as Bluesky's), or an
  OAuth app given `transition:chat.bsky` (D15).
  `app.bsky.actor.getPreferences`/`putPreferences` are kept here, per person,
  within the `app.bsky` namespace and 256 KB.
- **Writes are Social actions** (§16.5): `createRecord` of a post is a
  Social post (public; reply, quote, pictures, language; a link the app
  shortened is its whole address again), a like a like, a repost a boost,
  a follow a follow — the record the publisher writes for it is the answer,
  so the app sees what Social published, which may differ (a long post cut
  with a link). `putRecord` of the profile sets the display name and bio.
  `deleteRecord` undoes the action. `applyWrites` does the same one by one,
  not in one commit. Lists and their members, starter packs, feed
  generators, thread gates and post gates have no Social counterpart and
  are kept as the app wrote them, validated against their lexicon; a gate
  only for one of the account's own posts, under that post's key. A block
  is a block here, published, when the person publishes their blocks, and
  refused while they do not; list blocks are refused (D16). `uploadBlob` stores a picture as any upload is, named by its CID,
  for the post that uses it. A report an app files is a report here, passed
  on in this server's name (3b).
- The app's profile editor sets the avatar and the banner too: a picture
  the app uploaded becomes the account's — the avatar is the Nextcloud
  account's own picture, refused where its backend owns it — and one left
  out is taken away. An app sends the whole profile every time, so a
  picture it did not change is left alone. The pronouns and the website
  it sets are the account's profile rows named for them, rewritten, added
  while there is room among the four, or taken away
  (`Person::withProfileRows()`); unchanged, they are left alone.
  `getServiceAuth` came with 3d.
- A mention of a Bluesky account in a post published from here — the
  composer's `@alice.bsky.social` — is now a mention facet with the DID,
  where it was a link.

**3d as built** — `Publisher\VideoBlobService`, `Publisher\VideoUploadService`,
`Client\ServiceAuthGrant`, `Cron\AtprotoVideo`, table `social_atproto_video`:

- **A video posted here becomes a Bluesky video.** Bluesky's apps play the
  stream its video service makes, not a blob, so the publisher does what the
  Bluesky app does: it asks the service's daily limit, sends the video in
  the account's name (`app.bsky.video.uploadVideo`) with a token the account
  signs for `com.atproto.repo.uploadBlob` here, and the service stores the
  result in the account's repository with that token. The post waits in
  `social_atproto_video` meanwhile; `Cron\AtprotoVideo` sends a few videos a
  minute and asks after the jobs, and publishes each post once its job has
  ended, naming the stored blob as `app.bsky.embed.video` with the alt text
  and size of the video posted here. Beside a quote it is the media of
  `recordWithMedia`; pictures beside a video are left to the link.
- **When it stays a link.** A video Bluesky does not take (not MP4, WebM,
  QuickTime or MPEG; over 100,000,000 bytes; over three minutes), an
  account over its daily limit, a refusal, three failed tries, a job not
  done in thirty minutes, or a job whose result was not stored here: the
  post goes out as before, its text and a link to the post here, and the
  reason is logged. `atproto_video_service` empty turns the service off.
- **Apps upload video as the Bluesky app does.** `getServiceAuth` hands an
  app a token signed with the account's key, for two kinds of use only:
  Bluesky's video service (`getUploadLimits`, `uploadVideo`,
  `getJobStatus`) and this server's `uploadBlob`, for at most thirty
  minutes. Any other service or method is refused: it would be the
  account's signature somewhere this server knows nothing of. `uploadBlob`
  accepts such a token, checked against the account's key, as well as a
  session; the body is written to disk as it arrives, never held in
  memory, and a video may weigh up to 100,000,000 bytes. A post with an
  `app.bsky.embed.video` is a Social video post. A video blob is stored as
  it came, hashed as it is read, and `getBlob` streams it.
- **A Bluesky video plays here.** A post whose embed has a playlist is read
  as a federated `Video`: the HLS playlist is a streamed document, never
  copied, played through this server's playlist proxy (hls.js, as for
  PeerTube); the still is mirrored as its poster. A video the service has
  not finished is a link to the post until it is read again.
- **Fixed on the way:** a streamed HLS video was handed to the player as
  the byte route, so its segments 404'd; it now gets the playlist route,
  and stored links of older posts are rebuilt the same way when read.
- **`getSession` reports the Nextcloud account's e-mail address**, and as
  confirmed when it is set: the Bluesky app offers video only to an account
  with a confirmed address, and the account is one an administrator or the
  person manages.
- **Departure:** a token handed to the video service is not single-use;
  it is scoped to `uploadBlob` here and expires within thirty minutes, as
  Bluesky's own PDS does it.

**3e as built** — `Atproto\OAuth\` (`AuthorizationServer`, `ClientMetadataService`,
`ClientAuthenticator`, `DpopVerifier`, `DpopNonce`, `Jwk`, `Jose`),
`AtprotoOAuthController`, tables `social_atproto_oauth_request`,
`social_atproto_oauth_session`, `social_atproto_oauth_replay`:

- **Bluesky sign-in is AT Protocol's OAuth profile**: pushed authorization
  requests only, PKCE S256, DPoP with this server's nonces on every token and
  every PDS request, client IDs that are the address of the app's metadata
  (and the profile's `http://localhost` development exception), public and
  confidential (`private_key_jwt`) clients, the `atproto` scope with
  `transition:generic`, `transition:email` and `transition:chat.bsky` (the
  direct messages, D15).
- **One issuer with the Mastodon OAuth server.** AT Protocol requires the
  issuer to be the bare origin, and an origin has one
  `/.well-known/oauth-authorization-server`. With Bluesky on (and the root
  rules in place) that document is both servers': Mastodon's fields with AT
  Protocol's added, the issuer without a trailing slash, as RFC 8414 has it.
  `/oauth/authorize`, `/oauth/token` and `/oauth/revoke` are shared and tell
  the two apart by what only AT Protocol sends — a pushed `request_uri`, a
  `DPoP` proof, a URL as `client_id`; `/oauth/par` and
  `/.well-known/oauth-protected-resource` are AT Protocol's alone. The
  tokens, sessions and tables stay apart: a Mastodon token is not good on
  `/xrpc/`, an OAuth token for Bluesky not on `/api/`.
- **The consent page is the Mastodon apps' one**, told it is a Bluesky app:
  it names the app by the host of its client ID and shows the whole
  address, because an app's own name and logo are what it says of itself
  and are not shown. A `login_hint` naming another account than the one
  signed in is refused.
- **Tokens**: access tokens of fifteen minutes, bound to the DPoP key and
  checked against their session on every request, so signing an app out
  stops it at once; refresh tokens used once and rotated, and a replaced
  one presented again ends the session; a code exchanged twice ends the
  session the first exchange started. A public app's session ends after two
  weeks; a confidential one's refresh tokens last 180 days and must be
  presented with the key that started it, still published.
- **Settings → Apps and account → Bluesky** lists the apps signed in this way,
  with their client ID, what they may do and when they were last used, and
  signs one out. An OAuth app without `transition:generic` may only ask who
  it is (`getSession`); `getSession` shows the e-mail address only with
  `transition:email`.
- **Granular permissions** (atproto.com/specs/permission) are granted and
  held to: `repo:` records of a collection and an action, `rpc:` methods at
  a service (the AppView's `#bsky_appview` unless `atproto-proxy` names
  another), `blob:` uploads of a type, `account:email`, `identity:`, and
  `include:` permission sets. A set is resolved as Lexicon resolution
  says — the `_lexicon` TXT record of its authority, then the
  `com.atproto.lexicon.schema` record of that DID — kept a day at most, and
  only its `repo` and `rpc` permissions within its own namespace are taken;
  an `rpc` permission that inherits takes the `aud` the `include` named. A
  scope written wrong, or a set that cannot be resolved, is refused at the
  pushed request (`invalid_scope`). Each call is checked: a write per
  collection and action (every write of an `applyWrites` on its own), an
  AppView call per method and service, a service-auth token per method and
  audience, an upload per type; `getSession` is always answered and shows
  the e-mail address with `account:email` or `transition:email`. The
  transitional scopes stay as they were.
- **The consent page says what each permission means**: a sentence per
  scope, a permission set by its own title and detail (in the person's
  language where the set has it) with what it holds listed beneath, and a
  mark on what lets the app act rather than read.
- **Trusted apps**: the administrator may list client IDs (Bluesky admin
  section); for those, the consent page shows the app's own `client_name`
  and `logo_uri` (https only) and says the administrator vouches for it.
  Any other app is shown by its address only, because an app can call
  itself anything.

### Phase 4 as it lands

Five parts, five pull requests: **4a** custom handles (§4.2), **4b** moving
away (§13.2), **4c** moving a Bluesky account here (§13.1), **4d** Bridgy
Fed twins (§13.3), **4e** the moved posts in the timeline (§13.1, step 6).

**4a as built** — `Identity\HandleVerifier`, `Identity\CustomHandleService`,
`Identity\DnsLookup`, columns `custom_handle`, `custom_handle_checked`,
`custom_handle_failures` on `social_atproto_identity`:

- **Settings → Apps and account → Bluesky** takes a domain, shows the DNS TXT
  record (`_atproto.<domain>` = `did=<DID>`) and the file
  (`https://<domain>/.well-known/atproto-did` = the DID) that make it the
  account's, and checks them: either is enough, as for the AppView. Setting
  it asks for the Nextcloud password.
- **Then** the DID document names the domain (a PLC update with the same
  keys and endpoint) and the firehose sends `#identity`, so relays and
  AppViews resolve the handle again. The assigned `alice.<host>` keeps
  resolving here — `resolveHandle`, the well-known, sign-in by handle — as
  an alias the document does not list; going back to it is one button.
- **Refused**: a name that is not a resolvable domain, anything under this
  server's handle host (those are the ones it gives out), another
  account's handle here.
- **Checked daily** by the maintenance job, twenty at a time: a domain that
  failed two checks in a row is shown as broken in the settings, as
  Bluesky shows the handle as invalid. Nothing is changed for the person:
  the record may only be gone for a while.

**4b as built** — `Move\MoveAwayService`, `Move\PdsClient`,
`IdentityService::handOver()`/`markMovedAway()`, `Cron\AtprotoMove`, table
`social_atproto_move`:

- **Driven from here, not from the other side.** §13.2 had this server
  serve `requestPlcOperationSignature` and `signPlcOperation` to a
  migration tool. A tool would need a session with account-management
  rights, which neither app passwords nor `transition:generic` give, and
  this server holds both the signing key and a rotation key of the DID.
  So Settings → Migration → *Move your Bluesky account away* does what the
  tool would: the person names the other PDS, a handle there, an e-mail
  address and a password (and an invite code if it wants one), confirms
  with their Nextcloud password, and this server makes the account there
  with a token the account signs (`createAccount` with the DID). What the
  other server refuses is said at once.
- **Then a background job**: `importRepo` with this repository's CAR, every
  blob `listMissingBlobs` names, the preferences, then
  `getRecommendedDidCredentials` there and a PLC operation signed with this
  server's rotation key that hands the DID over — the person's recovery key
  first among the rotation keys, so the DID stays theirs; this server's key
  is no longer listed — then `activateAccount` there. Here the identity is
  `moved_away`, announced inactive on the firehose, and nothing more is
  published; no new identity is made for the account.
- **A step that fails stops the move**, and *Try again* starts it from that
  step. The DID moves only once the repository and the blobs are there, and
  a hand-over the directory refused is not left for the PLC repair pass:
  the move is finished by the person, never behind their back. The session
  on the other PDS is kept sealed while the move runs and dropped after.
- **The Fediverse account is untouched.** Posts written while the move
  runs, after the repository was copied, stay here only.

**4c as built** — `Move\MoveInService`, `Move\RepoArchive`,
`Protocol\MstReader`, `RepositoryService::import()`,
`IdentityService::adopt()`, `FollowService::adoptBlueskyFollow()`,
`DocumentService::storeAsIs()`:

- **Driven from here, as moving away is.** Settings → Migration → *Bring
  your Bluesky account here*: the person names their Bluesky account and
  types its **password** — the account's own, not an app password, because
  a PDS signs a change of the DID only for a full session (§13.1 said app
  password, which cannot work). It is used to sign in and not kept; the
  session is, sealed, while the move runs. A sign-in the old PDS wants
  confirmed by an e-mailed code takes that code as well.
- **The repository** is fetched with `getRepo`, checked — every block
  against its CID, the commit against the signing key the DID document
  names — and written here **byte for byte**, every record of every
  application, under one commit signed with a key made for the account
  here. Records keep their CIDs, so every reference to them stays good.
  **Blobs** are fetched with `listBlobs`/`getBlob` and stored as they came,
  each checked against its CID; nothing is re-encoded. **Preferences** come
  along. **Follows** become follows here, each tied to the record it came
  with, so nothing is written twice.
- **Then the old PDS e-mails a code** (`requestPlcOperationSignature`); the
  move waits. With the code it signs the operation that names this PDS,
  this server's rotation key and the account's handle here — checked
  before it is taken — the directory takes it, and the account **takes the
  DID**: the account's identity row keeps its handle (and custom handle)
  and gets the moved DID and its new key, and the DID this server had made
  for the account is **retired**, tombstoned with its repository. The page
  says so before the move starts. Last, `deactivateAccount` on the old PDS.
- **Then the posts** become posts in the timeline here (4e). A wrong code
  stops the move, and *Try again* asks the old PDS for a new one.

**4d as built** — `Move\InboundMoveService`, `Move\BridgyTwin`,
`ServiceAuth::verify()`, `IdentityService::receive()`/`submit()`; moves of
direction `inbound` in `social_atproto_move`:

- **This server as the new PDS of the protocol's own migration.** §13.3
  assumed the 4c flow with Bridgy as the old PDS; but a bridged account has
  no password — Bridgy holds its keys — and Bridgy moves an account out
  itself, with the DM command `migrate-to <pds> <email> <handle> <password>
  [invite]`, doing what a migration tool does against the new PDS. So 4d
  serves that side, and every standard tool (`goat account migrate`, say)
  can move an account here the same way.
- **Invited first.** Settings → Migration → *Bring your bridged Bluesky
  account here* finds the twin (the handle Bridgy gives the Fediverse
  address, resolving to a DID whose PDS is `atproto.brid.gy`); a person can
  also name any other account. Inviting, after the Nextcloud password, makes
  a one-time code, kept only as a hash, good for a day. A twin's move is
  then asked for by a direct message from the account to Bridgy's bot with
  the command; for anything else the page shows the server, the handle,
  the e-mail address and the code, once.
- **`createAccount`** takes only an invited DID, with the code (as password
  or invite code) and a service-auth token the DID signs for this server's
  `did:web`, checked against the key the directory names; the handle asked
  for is ignored — the account goes by its handle here. What it gets is a
  session that can only move the account: `importRepo` (checked as in 4c,
  replacing what came before, up to 64 MB), `listMissingBlobs`, `uploadBlob`
  (kept byte for byte, also after activation, as Bridgy sends blobs last),
  `getRecommendedDidCredentials` (this server's rotation key, the handle
  here, a key made for the account here, this PDS), `submitPlcOperation`
  for a tool that leaves the directory to the new PDS, `checkAccountStatus`,
  preferences, `refreshSession`. Seven days, then it ends.
- **`activateAccount`** checks the directory names this PDS, the
  recommended key and this server's rotation key; then the account takes
  the DID as in 4c (`receive()`, the auto-made DID retired) and a job turns
  its follows into follows here. Bridgy stops bridging the account to
  Bluesky itself.
- **A twin under a domain of the person's own** (Bridgy's `username`
  command) is found by the DNS record Bridgy keeps for the handle it gave
  first; failing that, and only when the person asks, Bluesky's search is
  given their Fediverse address, and a result is taken only when its DID
  document names their Fediverse actor — Bridgy lists it there, and a
  change of handle keeps it.

**4e as built** — `Move\PostHistory`, `PostImportService::importParsed()`,
`ImportedPostsRequest::isImported()`; a last step `posts` of both moves here:

- **The moved posts become the account's posts here**, through the import
  path every archive import takes, so nothing is federated: dated when they
  were written, the whole link where the app shortened it, the hashtags the
  facets name, the language, a warning where the post carried a label for
  one, a reply under its parent when that is one of the account's own
  posts too, the pictures or the video from the blobs held here.
- **Each is tied to the record it came from**, so a like, a repost or a reply
  on Bluesky reaches it here, and nothing is published again: the publisher
  leaves an imported post alone — neither rewrites its record when it is
  younger than the edit grace, nor publishes an archive import dated in the
  last day as new, which it did before.
- **The account has moved by then**: a post that cannot be imported is
  logged, and the move is done regardless. Running it again picks up only
  what is left.
- **Replies, likes and reposts.** A reply to somebody else's post hangs
  off that post here, and the account's latest 200 likes and 200 reposts
  become likes and boosts here, dated when they were made and sent
  nowhere (`LikeService`/`BoostService::recordWithoutSending()`), each tied
  to its record, so an unlike or an unboost here takes it back on Bluesky.
  A post that is not here is fetched from the AppView, for at most 300 in
  one move; past that a reply stands alone, and older likes and reposts
  stay on Bluesky only.

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
  one video per post, mp4 ≤ 100 MB and three minutes, through the video
  service; 25 videos or 10 GB a day per account (checked 2026-10-08). The
  flow: `getServiceAuth` (aud the PDS's `did:web`, lxm
  `com.atproto.repo.uploadBlob`, up to 30 minutes) →
  `POST https://video.bsky.app/xrpc/app.bsky.video.uploadVideo?did=&name=`
  with that token → `getJobStatus?jobId=` (no token) until
  `JOB_STATE_COMPLETED` with the blob, which the service stored on the PDS;
  `getUploadLimits` takes a token for `did:web:video.bsky.app`. A video
  view's playlist is `https://video.bsky.app/watch/<did>/<cid>/playlist.m3u8`,
  its segments relative to it.
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
