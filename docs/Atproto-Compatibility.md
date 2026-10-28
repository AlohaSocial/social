<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Bluesky and AT Protocol compatibility

**Reviewed against:** Aloha Social **0.26.280** on **2026-10-27**.

**Current scope:** native AT Protocol hosting is an optional integration in
Aloha Social. It runs alongside ActivityPub; it does not convert a Fediverse
account into a Bluesky identity or use an existing Bluesky account as the local
PDS identity. Service availability and AppView indexing remain external.

For shared remote ActivityPub profile behavior, see
[Federation-Compatibility.md](Federation-Compatibility.md). For operator setup,
see [Admin.md — Bluesky](Admin.md#bluesky); for endpoint details, see
[API.md — Bluesky/AT Protocol](API.md). The real-network
checklist is [Atproto-Live-Test.md](Atproto-Live-Test.md).

## Supported capabilities

| Area | Implemented behavior | Boundary |
|---|---|---|
| Identity | Local accounts can have a `did:plc`; assigned and verified custom handles are resolved; profiles and ordered profile links are served | PLC directory and DNS/HTTPS handle proofs must be reachable and valid. ActivityPub identity remains separate. |
| Repository | Signed local repositories, commits, records and blobs; repository export/import and account moves | Social records are projected into Aloha's social model. Other valid collections are retained as opaque records; without a vendored Lexicon their schema cannot be checked locally. |
| Posts | Public posts, replies, embeds, images/galleries, video, self-labels, edits, deletes and supported reply/quote rules | The social model and publication pipeline define supported post records. Long text or unsupported embeds may be mapped or refused; inspect the specific write path. |
| Other records | Supported profile links, lists and list items, starter packs, feed records, thread gates and post gates can be retained or processed | Keeping a record does not mean Aloha provides a native editor or operates the referenced feed generator. |
| Client authentication | PDS sessions, app passwords and OAuth are available for supported clients and permissions | OAuth scopes and permission sets are checked. General Nextcloud account password/email administration is not delegated to XRPC. |
| XRPC | PDS methods are handled locally; supported `app.bsky.*` and `chat.bsky.*` methods not hosted locally are proxied to configured services | Proxying depends on AppView/Chat service authentication and availability. A successful response does not prove feed indexing. |
| Reading | Profiles, author feeds, threads, custom feeds, lists, starter packs, search/discovery and interactions are available through supported local and proxied paths | AppView results can lag behind the PDS or omit content according to its policies. |
| Synchronization | Relay firehose, background polling and optional Jetstream listening can ingest remote activity | Cron, relay configuration and optional daemons must be running. A stopped or delayed source can leave the local view stale. |
| Chat and notifications | Supported Bluesky chat methods are proxied; inbound messages, messages sent in Bluesky clients, unread backfill, read state, and emoji reactions in both directions are synchronized where a message has a known local-to-remote mapping. Reactions use the existing local message reaction summaries; a reaction cannot be displayed if its message is not stored locally. The first backfill paginates until it has found the conversation's reported number of unread incoming messages, retains own messages in that range, and counts deleted-message placeholders without importing them as content. If history ends before the boundary is found, no initial cursor is committed and the backfill is retried. Incremental `getLog` is revision-based and has no page-size or page-cursor parameter; Aloha processes the returned batch before committing the last processed revision, and refuses incomplete or unknown event records. | The Chat lexicon exposes `unreadCount` but no per-conversation read cursor in `convoView`, so the boundary is derived from sender identities and the count. `getLog` is not a multi-page endpoint; pagination applies to `listConvos` and `getMessages`. A structurally valid but incomplete `getLog` batch cannot be detected if the service does not report an error. The Chat service is separate from the PDS and requires its own service-auth configuration. Group membership and invitation management have no complete native UI. Verify live behavior with the checklist. |
| Moderation | Supported labels, blocks/mutes, reports and moderation-list behavior are integrated with local controls | Labeler and AppView decisions remain external; not every moderation method has a local UI. |
| Account migration | Supported flows can bring an account and repository here or move one away, including supported Bridgy Fed twins | Migration is a multi-step protocol operation. Follow the UI/commands and keep backups; it is not a generic identity takeover. |

## Blob lifecycle boundary

Blob CIDs identify the bytes served by `getBlob`, and duplicate registration is
scoped to the account DID. Concurrent duplicate registration preserves the
first document mapping and does not count its repository bytes again.

`uploadBlob` stores accepted files byte for byte, including image metadata,
without resizing or transcoding. The returned CID and size describe the original
upload. Type, size, quota and blocked-media checks still use the shared storage
policy; this does not accept every possible file format. Normal Social uploads
continue to remove image metadata and prepare media for publishing. ATProto
clients must remove unwanted metadata before uploading.

The general blob lifecycle is still incomplete. Registered blobs currently have
no separate temporary/unreferenced state:
`getBlob` and `listBlobs` read their registrations directly. Reference-aware
publication, temporary-upload expiry and removal after the last record
reference are remaining work against the [ATProto blob specification](https://atproto.com/specs/blob).
Generic record storage does not by itself establish full blob-lifecycle parity.

## How posting works

Public local posts can be published to the ActivityPub network and the native
AT Protocol repository as separate writes. Each side has its own identity,
records, delivery, deletion and indexing behavior. ActivityPub visibility
choices that are not public are not published as public Bluesky posts.

Bluesky clients can write through `com.atproto.repo.createRecord`,
`putRecord`, `deleteRecord` and `applyWrites`. Social records are projected
into Aloha's model where a corresponding action exists. Other collections are
kept as protocol-native records and are not converted to ActivityPub posts or
other Social objects. A vendored Lexicon receives full schema validation;
an unknown Lexicon is bounded by structural, DAG-CBOR and record-size checks
but cannot be validated against its application schema. Explicit `validate: true`
refuses unknown schemas, `false` skips schema checks, and omission validates
known schemas. Single and batch responses distinguish `valid` from `unknown`.
Social adapters still enforce their action, ownership and representation
constraints regardless of schema validation. The explicit list
block collection remains unsupported.
`com.atproto.repo.getRecord` honors an optional `cid`: a matching current
version is returned, while a different version answers `RecordNotFound` rather
than silently returning the latest bytes. Older record revisions are not
retained as a historical read API. An omitted CID reads the latest version.
Conditional writes are checked against the repository under its write lock.
Post updates preserve the record URI where supported. External link embeds,
record embeds, combined media and supported profile-link records are mapped
explicitly. The collection mapping is defined in
`lib/Atproto/Client/WriteService.php` and
`lib/Atproto/Publisher/RecordMapper.php`.

The AT Protocol does not make an uploaded post instantly visible in every
Bluesky surface. The sequence is: PDS commit, relay crawl, AppView indexing,
then client refresh. A relay's acceptance or a local XRPC success only proves
the earlier step. Use the live checklist to distinguish those states.

## Deliberate limits

- Opaque record storage does not make Aloha a native client for every ATProto
  application: unknown Lexicons have no app-specific validation or UI here.
- Native Bluesky draft synchronization, Live Now profile controls,
  full starter-pack editing, and a hosted custom-feed generator are not
  represented as complete features here. Opaque repository storage allows
  other apps to persist records, but does not provide their native UI or
  app-specific validation when their Lexicons are not vendored.
- Bluesky account activation/deactivation, deletion, email and password
  management are not a replacement for Nextcloud account administration.
- A Bluesky app can use supported APIs while a local web feature is absent; API
  proxy availability and a first-party user interface are different capabilities.
- A public profile or post can be fetched locally while it is still absent from
  an AppView or search index. This is an indexing/availability distinction, not
  proof that an XRPC route is missing.
- Bluesky chat reaction synchronization does not imply that every reaction
  feature available in the native chat client has a local visual equivalent;
  verify the deployed client and Chat service combination separately.

## Operations and verification

The administrator must configure the PDS host and public endpoints, PLC access,
relay/firehose service and the AppView/Chat destinations. The root `/xrpc/`
route must reach Social through the web-server configuration shipped in
`contrib/webserver/`. Configure system cron for background work; optionally run
`occ social:atproto:serve` for the firehose and `occ social:atproto:listen` for
Jetstream. The exact setup checks and service requirements are in
[Admin.md](Admin.md#bluesky) and [OCC-Commands.md](OCC-Commands.md).

The codebase includes unit, integration and protocol interoperability tests.
These do not establish live public relay/AppView indexing. For a production-like
check, use [Atproto-Live-Test.md](Atproto-Live-Test.md) with test accounts and
record which service acknowledged each step.

## Account recovery phrase

The account settings can create a twelve-word recovery phrase after password
confirmation. It is shown once; creating a replacement rotates the recovery
key, so store the new phrase safely. This key can sign supported PLC rotation
operations, but the instance key still ranks above it while the account remains
hosted here. Keep the phrase private and offline.

The words encode 128 bits of entropy using the standard English BIP-39 word
list and checksum. The private key is the big-endian secp256k1 scalar derived
as `SHA-256("social.atproto.recovery.v1" || entropy)`. This is **not** the
standard BIP-39 PBKDF2 wallet derivation. The offline helper is
[`resources/atproto/recovery-key.py`](../resources/atproto/recovery-key.py),
with the word list at
[`resources/atproto/bip39-english.txt`](../resources/atproto/bip39-english.txt).

Reference vector:

- Phrase: `abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about`
- Secret key hex: `950f8acc1852f1a2562b2851457a04b1ba22541dd0f3b95ab9302d2894f29397`
- Private-key multibase: `z3vLe2mABz9GSzoARHpVNW8P1rnySJ8rmfg3AKSpyVfHE526`
- Public `did:key`: `did:key:zQ3shX448VC3xo912wY7zzdUFdMqGifmFTv9GomCodivqkgkt`

The code and offline helper are checked against this vector in
`tests/Atproto/Identity/MnemonicTest.php`.

## Runtime configuration keys

These persisted keys identify the primary AT Protocol controls and the
Jetstream listener state. Configure service endpoints and enablement through
the Bluesky administration card where available; cursor and status keys are
runtime bookkeeping and should not be edited manually.

| Key | Purpose |
|---|---|
| `atproto_enabled` | Enables or disables native AT Protocol hosting. |
| `atproto_relays` | Configured relay endpoints used for repository synchronization. |
| `atproto_plc_directory` | PLC directory endpoint used for DID operations. |
| `atproto_chat` | Configured Bluesky Chat service endpoint. |
| `atproto_jetstream` | Optional Jetstream source for remote repository events. |
| `atproto_jetstream_cursor` | Last consumed Jetstream cursor; internal listener state. |
| `atproto_jetstream_status` | Listener health/status state; internal bookkeeping. |

Other `atproto_*` values may hold credentials, service DIDs, crawl cursors or
backfill progress. These are operational state, not general user preferences.
See [Admin.md](Admin.md#bluesky) for setup and secret-handling guidance.

## Protocol references

- [AT Protocol documentation](https://atproto.com/guides/overview)
- [AT Protocol specifications](https://atproto.com/specs/atp)
- [Bluesky lexicons](https://github.com/bluesky-social/atproto/tree/main/lexicons)
- [ActivityPub compatibility](Federation-Compatibility.md)
