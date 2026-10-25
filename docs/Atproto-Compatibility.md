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

**Verified against:** the original specification baseline, Aloha Social master `2eb7b99a9` (0.26.121),
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
mutes are never published, blocks only when the person chooses to; direct
messages are Bluesky's chat, and a conversation with somebody on Bluesky
is a conversation in this app's own messages like any other (§10.1). An
existing Bluesky user can later move their account here, keeping their DID
and their followers.

## 2. Decisions

Taken by the product owner; the date is the interview. **Do not re-ask.**

| # | Decision | Date |
|---|---|---|
| D1 | **Native identity**: Aloha Social is the PDS. No Bluesky account is needed or used. (The linked-account approach of PR #2482 is a separate track.) | 09-25, confirmed 10-06 |
| D2 | **Both directions**: Social → Bluesky and Bluesky → Social. | 09-25 |
| D3 | **Every account, automatically**: each Social account is reachable from Bluesky; the administrator can disable Bluesky instance-wide. No per-user opt-in; a person may switch their own presence off (D23). | 10-06 (supersedes 09-25's opt-in) |
| D4 | Handles are **`alice.<instance host>`** via wildcard DNS and a wildcard certificate; bring-your-own-domain handles are a later phase. | 09-25, fixed 10-06 |
| D5 | **`did:plc` only**, registered at `plc.directory`. | 09-25 |
| D6 | The firehose is served by a daemon, **`occ social:atproto:serve`**, run under systemd. | 09-25 |
| D7 | `/xrpc/` reaches the app through the existing root web-server rules (`contrib/webserver/*-social-root.conf`), like `/api/` and `/oauth/`. | 09-25 |
| D8 | **Every public post goes to Bluesky automatically.** Unlisted, followers-only and direct posts never do. No per-post switch. | 10-06 |
| D9 | A post that does not fit Bluesky is **published as a thread** of linked replies, preserving the full text; the original post remains the root. Up to **ten pictures** (the first four use `app.bsky.embed.images`, five to ten use `app.bsky.embed.gallery`); a poll becomes **the question plus a link**; a content warning becomes **a self-label plus a text prefix**. | 09-25, revised 10-23; galleries 10-21 |
| D10 | **Edits**: update the existing Bluesky record at the same URI, regardless of post age, preserving likes and replies. | 10-23 |
| D11 | **Pictures and video** are published as native Bluesky embeds. Video is processed by Bluesky's video service; arbitrary file blobs are not a supported `app.bsky.feed.post` embed. | 10-06; video and galleries implemented 10-21 |
| D12 | **Inbound** reads by **polling** the public AppView per followed author, with a cap and backoff; notifications stay on a one-minute cadence so an empty inbox cannot delay the next interaction for hours. **Jetstream** is an optional daemon an administrator may run for instant feed delivery on a large instance. | 09-25 polling, 10-06 the optional daemon; 10-21 notification cadence |
| D13 | Bluesky posts appear **in the same timelines**, their authors addressed as bare `@alice.bsky.social` with a **badge**. | 09-25, confirmed 10-06 |
| D14 | **All Bluesky interactions** (like, repost, follow, reply, mention, quote) show in Activities like their ActivityPub counterparts, under the same **notification policy** and requests inbox. | 10-06 |
| D15 | **Direct messages** are Bluesky's chat service's: a Bluesky app signed in here reaches them through this PDS (`chat.bsky.*` proxied to the configured chat service), with a privileged app password or an OAuth app given `transition:chat.bsky`, as on Bluesky. Revised again 10-09: they are also this app's own direct messages (§10.1): a message from Bluesky arrives in the person's messages, and a direct message written here to somebody on Bluesky is sent there as a Bluesky direct message; what the person writes in a Bluesky app shows here too, read state goes both ways, a message request is what a direct message from a stranger is here (held by the notification policy), and who may send them direct messages is one setting for both networks (`chat.bsky.actor.declaration`). | 10-06, revised 10-09 twice |
| D16 | **Mutes are never published, and blocks only as the person chooses** (revised 10-09: "Publish my blocks of Bluesky accounts", off by default, since a Bluesky block is public; revised again 10-09: people subscribe to Bluesky's moderation lists, §12.5, and a block list is published as a `listblock` record under the same choice); in scope: honour Bluesky's moderation labels, let users subscribe to labelers, file reports with Bluesky's moderation service, instance-level blocks of PDS hosts and DIDs. | 09-25, extended 10-06 |
| D17 | The client API is a PDS: Bluesky apps may log in here, with `app.bsky.*` proxied to the AppView; no AppView of our own. | 09-25 |
| D18 | Bridgy Fed twins of local accounts are folded into the native identity (§13.3). | 09-25 |
| D19 | **Moving an existing Bluesky account here** is its own phase. | 10-06 |
| D20 | **Phase 1 makes a Social account visible on Bluesky** (identity, repository, firehose); reading Bluesky from here is phase 2. Four phases, one PR each. | 09-25, order fixed 10-06 |
| D21 | CI and devel use a self-hosted PLC, `@atproto/dev-env` and an `indigo` relay; the real network is exercised by hand only. | 09-25 |
| D22 | **A person's lists are one set with their Bluesky lists** (§9.7). A list here is private, as a Mastodon list is, and is never published silently: the person makes it public, and a public list is a Bluesky curate list with its members, kept in step both ways; a curate list made in a Bluesky app signed in here, or brought by a move here, is a public list here. A list a reply rule names is published while the rule needs it, public or not (§8.3). | 10-09 |
| D23 | **A person may switch their own presence on Bluesky off** (was open question 1): "Be on Bluesky" in their settings, on by default. Off is Bluesky's own deactivation — the profile and posts are no longer shown there, nothing new is published, Bluesky apps are signed out, Bluesky direct messages and notifications are no longer read — while the DID, the handle and the repository stay theirs. Reading Bluesky accounts they follow goes on; their likes, replies and messages to Bluesky then stay here. On again, the repository is served as it was; **what was written while off is never published afterwards**. §4.6. | 10-09 |
| D24 | **The instance as a verifier**: moderators verify accounts here (a company verifying its own people), local and remote alike; the check is shown on the account everywhere, as one "verified" concept with Bluesky's trusted verifications, naming who verified. With Bluesky on, a verification of an account with a DID is published as an `app.bsky.graph.verification` record from a local **verifying account** the administrator chooses; Bluesky apps show it only once Bluesky trusts that account as a verifier. A Fediverse account without a DID is verified here only (§12.6). | 10-09 |

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
It is **pending** until the PLC directory has taken its genesis operation —
usually at once; while the directory cannot be reached, until the
maintenance job gets it through — and nothing is published, announced or
served for it before: the account is not on Bluesky yet, and the settings
page says so. The identity is three things stored in
`social_atproto_identity` (§15):

- the **DID** (`did:plc:…`), computed by the PLC rules from the genesis
  operation (the DID *is* a hash of the first operation);
- the **signing key**, secp256k1 (`k256`), private half sealed with the
  instance secret the way app passwords are (`PrivateKeyCipher`), public
  half as `did:key:z…` (multibase, multicodec `0xe7`);
- two **rotation keys**: the instance's rotation key (one per instance,
  rotated by the administrator, §16.3) first, and a per-account recovery
  key whose private half is shown to the person once (§4.4) and otherwise
  not stored. Order is priority: the instance key wins a dispute, which
  means the instance can nullify, within the PLC's 72-hour window, an
  operation the person signed with their recovery key — a move away
  included — and the person cannot nullify the instance's. That is
  deliberate for a hosted instance (the administrator's way back from a
  compromised phrase) and the opposite of Bluesky's own PDS, which lists
  the person's key first; the settings page says so beside the phrase.
  That is the order of an identity made here. A DID that moved here keeps
  the person's own keys first and this server's last (§13.1), and a DID
  the person changed with their own key keeps the order they gave it: no
  later operation of this server reorders it (§4.5).

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
same DID forever, as an alias the DID document does not list. The domain is
checked again every day; one that fails seven checks in a row — a week — is
dropped, and the account has its `alice.<host>` handle again, in the DID
document too. A handle reaches an account here by no other way unchecked:
one the PLC directory names for a DID (after an operation signed elsewhere,
or a move here) becomes its custom handle only when the domain names the
DID.

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
one. The recovery key is made on request (*Create recovery phrase*, after
password confirmation) and shown **once**, as a twelve-word phrase to
write down; making a new one replaces it on the DID (§4.5) rather than
revealing the old one. The same dialog shows the key the words stand for
in the forms other atproto tools take — the private key as multibase
(what `goat key generate` prints and `goat plc sign --plc-signing-key`
reads) and as hex (what the reference PDS and `@atproto/crypto` import),
and the public `did:key` the directory lists — with the advice to keep
the words, which are all of it. The settings page says that the
instance's key ranks above it (§4.1). The switch **Be on Bluesky** is
there too (§4.6).

**The derivation**, for anyone who reimplements it (`Mnemonic`;
`resources/atproto/recovery-key.py` does it with Python 3 and nothing
else):

1. The phrase is lower-cased and split on white space; it is exactly
   twelve words of BIP-39's English list (`resources/atproto/bip39-english.txt`,
   the standard 2048 words). No Unicode normalisation is needed: the list
   is ASCII.
2. Each word is its index in the list as eleven bits, most significant
   first; the 132 bits are the 128 bits of entropy (16 bytes, big-endian)
   and a 4-bit checksum, which must equal the first four bits of
   SHA-256 of the entropy — BIP-39's own encoding.
3. The private key is **SHA-256 of the 26 ASCII bytes
   `social.atproto.recovery.v1` followed by the 16 entropy bytes**, taken
   as a big-endian secp256k1 (`k256`) secret. It is *not* BIP-39's seed:
   no PBKDF2, no passphrase, no BIP-32 path, so a wallet that imports the
   words derives a different key.
4. Written out: hex is the 32 bytes in lower-case hex; multibase is `z`
   followed by base58btc (Bitcoin alphabet) of `0x81 0x26` (the multicodec
   varint of `secp256k1-priv`, 0x1301) and the 32 bytes; the `did:key` is
   `did:key:z` followed by base58btc of `0xe7 0x01` and the 33-byte
   compressed public point.

Example: `abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about`
(entropy sixteen zero bytes) is the hex key
`950f8acc1852f1a2562b2851457a04b1ba22541dd0f3b95ab9302d2894f29397`,
multibase `z3vLe2mABz9GSzoARHpVNW8P1rnySJ8rmfg3AKSpyVfHE526`, public
`did:key:zQ3shX448VC3xo912wY7zzdUFdMqGifmFTv9GomCodivqkgkt`
(`MnemonicTest` pins all three, and that the script agrees).

**What the phrase can do.** Its key is a rotation key of the DID, so it
signs PLC operations the directory accepts without this server. The
**recovery tool** (below) does that in the person's browser; goat does
the same from the command line (`goat plc update`, `goat plc sign
--plc-signing-key <multibase>`, `goat plc submit`, then
`goat account service-auth-offline` and `goat account create
--existing-did`). Both paths are in Moving.md's "If this server goes away"
and in the archive's README. Neither has been run end to end against the
live directory from this app's tests. While this server exists its key
ranks first, so for 72 hours it can nullify an operation signed with the
phrase (§4.1); the "Who controls" panel (§4.5b) shows that window.

**The recovery tool** is one HTML page with its script inline
(`RecoveryToolPage`, the self-contained webpack entry `src/recovery.js`,
which keeps `@noble/secp256k1` and `@noble/hashes` out of every other
bundle). It is served publicly at `GET /apps/social/bluesky-recovery`
(the inline script allowed by its CSP hash, `connect-src https:` for the
directory and whichever server the person names), downloaded from
`/bluesky-recovery/download` ("Download the recovery tool" in Settings),
and put into the Bluesky archive as `recovery/bluesky-recovery-tool.html`.
The page carries its strings, in the language it was downloaded in, and
the word list, so a saved copy runs with this server gone. In order, each
network request only on a button press:

1. the key from the words (`src/recovery/plc.js`, the derivation above)
   or from hex or multibase;
2. the DID's latest operation from `<directory>/<did>/log/last`, or
   pasted; the key must be one of its rotation keys (`key-not-listed`
   otherwise);
3. for a server that cannot sign any more, an operation that gives the
   DID a fresh signing key made in the browser and puts the person's key
   first, everything else as it was, sent to the directory;
4. the new server's DID from `describeServer`, and `createAccount` with
   the existing DID and a service-auth token (ES256K, `lxm`
   `com.atproto.server.createAccount`) signed with that signing key;
5. `importRepo` of `repo.car` and `uploadBlob` of each blob file;
6. `getRecommendedDidCredentials` with the new account's token (or
   pasted), the hand-over built exactly as `PlcOperation::fromCredentials()`
   builds it — the person's key first, then the new server's, at most five
   — signed and sent to the directory, or copied;
7. `activateAccount`.

Operations are DAG-CBOR as `DagCbor` writes it (lengths minimal, map keys
by length then bytes), CIDs CIDv1 dag-cbor SHA-256 base32, signatures
ECDSA over SHA-256, low-S, raw r‖s, base64url — RFC 6979 deterministic in
the tool, random-k in OpenSSL, both valid. The cross-language fixtures
(`tests/Atproto/Identity/fixtures/recovery-tool.json`, written by
`RecoveryToolFixturesTest` and `tests/js/recovery/plc.test.js` with
`SOCIAL_UPDATE_FIXTURES=1`) pin the same keys from the same words, the
same operation bytes and CIDs, PHP's signature accepted by the tool, and
the tool's operations and service token accepted by PHP.

### 4.5 PLC operations this app sends

- **create**: on first need (§4.1), signed with the instance rotation key.
  The identity is pending until the directory confirms it (§4.1); the
  confirmation, wherever it happens, makes it active and sends `#identity`.
- **update handle**: when the username changes (it does not, in Social, but
  the custom-handle phase needs it) and when the instance host changes.
- **rotate keys**: when the administrator rotates the instance rotation key
  (§16.3) or the person makes or replaces their recovery key — each only
  the key concerned, see below.
- **update PDS endpoint**: on a host change, and when an account moves
  away (§13.2).
- **tombstone**: when a Social account is deleted. `AccountService::deleteActor()`
  gains this step; the DID is dead afterwards, as the person asked.

**Rotation keys of a later operation.** Every operation this server signs
after the genesis — a handle or host change, a key rotation, a recovery
phrase — carries the rotation keys of the operation it follows (with
nothing pending, the directory's confirmed head), in their order
(`RotationKeys::carry()`). It changes only two of them: this server's own
key, where a rotation or a retired key puts the key in use in its place,
and the recovery key, which a new phrase puts in the old one's place, or
where there was none, beside this server's key — below it where this
server's key is first, above it where a key of the person already ranks
above this server. Every other key, the person's included, stays where it
stood. A DID that lists five keys already takes no recovery key (the
answer says so) rather than dropping one. An operation that waited while
the directory moved on — the person changed the DID with their own key
meanwhile — is carried onto the new head with only its own change
(`RotationKeys::rebase()`), so their change stays. A hand-over to another
PDS (§13.2) is the exception: it names the keys the new server recommends,
the person's recovery key first.

Every operation goes through one `PlcClient` with retries and is recorded
in `social_atproto_plc_log` before it is sent (so a crash between the two
leaves a row to reconcile from), and `occ social:atproto:plc` lists the log
and the directory's view side by side.

The log is a queue per DID, and `prev` is the directory's, not the log's:
an operation goes out in its turn, built on the operation the directory
confirmed last. The client retries a network failure, a 5xx and a 429;
any other 4xx is the directory's verdict, and the operation is **dead** —
kept in the log with the directory's reason, never sent again, and not
the `prev` of anything. The next operation is signed again on the
confirmed head (with whichever rotation key of this instance that head
still names, the retired ones included) and tried on its own. An
operation the directory could not be asked about waits, with everything
behind it, for the maintenance job (`IdentityService::repair()`, every
five minutes), which first compares the log with the directory's audit
log (`/log/audit`): an operation the directory holds is confirmed, one it
nullified is dead, one it holds that the log does not — the person's own,
signed with their recovery key elsewhere — is taken into the log as
confirmed, and the identity row takes the handle the directory names and
drops a recovery key it no longer lists. A signing key or instance key
the directory no longer names is warned of, with the DID; this server
cannot put that right by itself. A hand-over to another PDS (§13.2)
refuses to start while an earlier operation for the DID is pending.

### 4.5b Who controls the DID

The Bluesky section of Settings shows **Who controls your Bluesky
identifier** (`GET /api/v1/social/bluesky/control`, `DidControlService`):
the rotation keys of the DID's latest operation, highest first, each named
by what it is to the person — this server (`InstanceKeyService`'s keys,
read by their public halves without opening any; a retired one is "an
earlier key"), their recovery phrase, or a key this server does not know
(shown as its `did:key`). Whose key signed the latest operation is found
by checking its signature against the keys of the operation before it;
while that operation is younger than 72 hours and a key ranks above the
signer, the panel says until when, and by which keys, it can still be
undone (the PLC nullification rule). The directory's audit log is the
source, kept a minute in the distributed cache under the operation this
server confirmed last, so an operation this server sends is seen at once.

### 4.5a The account as files

**Download your Bluesky account** (Settings, the Bluesky section;
`GET /api/v1/social/bluesky/archive`, `BlueskyArchiveService`) and the
account export of the Migration page (`SocialMigrator`, under
`social/bluesky/`) carry the same files: `repo.car` (the repository as
`com.atproto.sync.getRepo` streams it), `blobs/<cid>` for every blob that
still reads, `plc-log.json` (the directory's `/log/audit`, or where it
cannot be asked the operations this server's log has as confirmed, in
the same shape), `did.json` (the directory's document, else the one this
app derives from its log), `recovery/recovery-key.py` with the word list
(§4.4), `recovery/bluesky-recovery-tool.html` (the recovery tool, §4.4,
where the JavaScript is built), and `README.txt`, which says what each
file is and the steps of Moving.md's "If this server goes away". Every file goes to the
archive as a stream, so none is ever whole in memory. The download is a
zip written as it is sent (`ZipStreamWriter`: stored, CRC and sizes in a
data descriptor after each file, ZIP64 offsets and end records past
4 GB): its first bytes go out at once, the CAR goes in as the repository
is read, and nothing of it is kept on disk. The Migration page's archive
is a `ZipArchive` file, the CAR going in through a temporary stream that
spills to disk. Nothing is read back from these files
on import: a Bluesky account comes here by a move (§13), not from a file.

### 4.6 Switching off (D23)

**Settings → Apps and account → Bluesky** has the switch **Be on Bluesky**,
on by default, with a plain explanation beside it. Switching it off asks
first, saying that Bluesky apps are signed out and that what is written
while off never reaches Bluesky; switching on does not. It is the one setting
that names Bluesky, since it is about presence there and nothing else. The
route is `POST /api/v1/social/bluesky/state` (`active`), the work
`PresenceService` (`lib/Atproto/Identity/PresenceService.php`).

**Off** is Bluesky's own deactivation (`com.atproto.server.deactivateAccount`
on a Bluesky PDS):

- the identity's state becomes `deactivated` (`IdentityService::deactivate()`)
  and the firehose says `#account` with `active: false, status: deactivated`;
  the relay and the AppView then stop showing the profile and the posts
  (the AppView answers `AccountDeactivated`). `getRepoStatus` and
  `listRepos` say `deactivated`; `getRepo`, `getRecord`, `getBlob` and the
  other reads of the repository answer `RepoDeactivated`, and neither the
  handle's well-known file nor `resolveHandle` resolves the handle;
- nothing is written to the repository: no post (`Publisher::publishPost()`),
  no edit or gate, no like, repost, follow, block or list record
  (`writeRecord()`), no profile. A **delete** still goes, so a post taken
  back while off does not come back with the account;
- every Bluesky app is signed out — the app-password sessions and the OAuth
  sessions end — and none signs in again: `createSession`,
  `refreshSession` and an access token answer `AccountDeactivated`, the
  OAuth endpoints `access_denied` or `invalid_token`. The app passwords
  stay, for when the account is on again;
- the notification and chat pollers drop the account's cursors, so
  Bluesky notifications and direct messages are no longer read, and a
  direct message written here to somebody on Bluesky is not sent there;
- nothing is asked of the AppView in the person's name (`activeForActor()`):
  following and reading Bluesky accounts goes on with anonymous reads —
  feeds, threads, search, suggestions — so the person keeps the Bluesky
  accounts they follow. A like, reply or bookmark of a Bluesky post stays
  here, and a mute or bell is not told to the AppView;
- `IdentityService::forActor()` returns the row as it is, whatever its
  state: neither first need nor `occ social:atproto:identities` makes a new
  identity or switches it back on. An account that moved away or was
  deleted cannot be switched either way.

**On again** (`IdentityService::activate()`): the state is `active`, and the
firehose says `#account` with `active: true`, `#identity` (the AppView
resolves the handle again) and `#sync` with the head commit, so a relay that
dropped the commits made while the account was off — the deletes — takes the
repository as it is now. The profile is written as it is now. Nothing is
published retroactively: like Bluesky's own reactivation, the repository is
served as it was left. The time of switching on is kept (user value
`atproto_on_since`), and a post published before it is never published by
the reconcile pass that otherwise picks up a missed post of the last day.
The UI says so: posts written while off stay off Bluesky.

## 5. The repository

### 5.1 Records this app writes

Collection → written when:

| Collection | Written | Content |
|---|---|---|
| `app.bsky.actor.profile` (rkey `self`) | account created, profile edited, a picture or a pin changed | `displayName` (the name the account federates, as far as its Nextcloud privacy setting allows; its username without one), `description` (plain text, bio), `pronouns` (the profile row named for them, at most 20 graphemes) and `website` (the row named for one, else the first row that is an address), `avatar` and `banner` blobs (each at most 1,000,000 bytes, JPEG or PNG, re-encoded otherwise), `pinnedPost` (the newest pin that is on Bluesky), `createdAt`; rewritten when the pinned post's CID changes on edit or the post is deleted |
| `app.bsky.feed.post` | public post created (D8) | §8 |
| `app.bsky.feed.like` | a local actor likes a post that **exists on Bluesky** (a Bluesky post, or a local post that was published, §8.6) | `subject` {uri, cid} |
| `app.bsky.feed.repost` | a local actor boosts such a post | `subject` {uri, cid} |
| `app.bsky.graph.follow` | a local actor follows a Bluesky account (§9.2) | `subject` DID |
| `app.bsky.feed.threadgate`, `app.bsky.feed.postgate` | a post written here whose author narrowed who may reply or quote (§8), written with the post under its key and rewritten when the author changes it; or a Bluesky app signed in here writes one for one of the account's own posts (§6), kept under that post's key | `post`, `allow` (threadgate) or `embeddingRules` (postgate), `createdAt`; as the app wrote it |
| `app.bsky.graph.list` (curate list), `listitem` | a local actor makes one of their lists public, or a reply rule names it (§8.3, §9.7, `Publisher\BlueskyLists`); renamed with it, withdrawn when it is made private and no threadgate names it, or deleted | `purpose` curatelist, `name` (the title, at most 64 characters), `createdAt`; an item's `subject` DID and `list` URI |
| `app.bsky.graph.list`, `listitem`, `starterpack`, `app.bsky.feed.generator` | a Bluesky app signed in here writes one (§9.6); a curate list and its items are then the person's list here too (§9.7) | as the app wrote it |
| `app.bsky.graph.block` | a local actor blocks a Bluesky account, **only when the person publishes their blocks** (D16, `Publisher\BlueskyBlocks`) | `subject` DID |
| `app.bsky.graph.listblock` | a local actor subscribes to a block list **and** publishes their blocks (D16, §12.5, `Reader\BlueskyModerationLists`) | `subject` list `at://` URI |
| `chat.bsky.actor.declaration` (rkey `self`) | who may send the person direct messages changed here or in a Bluesky app signed in here, or the account's first read of its direct messages finds none while the setting here is `none` (§10.1, `Chat\ChatDeclaration`) | `allowIncoming`: `all`, `following` or `none` |
| `app.bsky.graph.verification` | a moderator verifies an account that has a DID, in the repository of the instance's verifying account (D24, §12.6, `Publisher\BlueskyVerifications`); written again when the account's handle or display name changes, withdrawn when the verification is taken back | `subject` DID, `handle`, `displayName` (as they are when written), `createdAt` |
| any other `chat.*` | never | — |

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
blocks, not rows. A commit **edits the stored MST in place**
(`MstEditor`): it reads the nodes on the path from the root to each key it
writes, rewrites them, writes the new nodes to the block table and deletes
the ones they replace; every other node is kept by its CID and never read,
so a commit costs the depth of the tree, not the size of the repository.
The edits are the reference implementation's (split around a new key,
merge around a removed one, trim an emptied root), and the tree they leave
is, node for node, the one `Mst::build()` makes from the record table —
pinned by random sequences of commits in the tests. The whole tree is
still built from the records when a repository is imported (§13), when
`occ social:atproto:repo --verify` checks it, and by the next commit after
a stored node is found missing or malformed, which stores that tree in
place of what was there. The MST rules (fanout 4, key depth from the
leading zero bits of the SHA-256 of the key, two hash bits per level,
32-byte CID v1 `dag-cbor` SHA-256) are pinned by the interop test vectors
(§17.1). `tests/Integration/Atproto/CommitBenchmarkTest` (run with
`SOCIAL_BENCHMARK=1`) prints what one commit costs on twenty thousand
records next to what a rebuild of the tree costs.

### 5.3 Commits

`version: 3`, `did`, `data` (root MST CID), `rev` (a TID, strictly
increasing per repository), `prev: null`, `sig` (64-byte low-S secp256k1
over the DAG-CBOR of the unsigned commit). One commit per write, written in
the same database transaction as the record and the blocks, and one
firehose event per commit (§7). A write that fails half-way leaves the
previous commit as the repository's head: the `social_atproto_repo` row
(§15) holds the head CID and `rev`, and it is updated last. The
transaction begins by reading that row with a row lock (`SELECT … FOR
UPDATE`; SQLite, which has one writer at a time anyway, without the
clause), so two commits of one repository that start together run one
after the other and the second builds on the first's head rather than
forking the tree. A record that stands for one object here — a post, a
like, a repost, a follow — is looked for again after that read: when a
second worker publishing the same post finds the first one's record
there, its commit writes nothing, so a post never goes out twice. On
SQLite, without the lock, the database's single writer refuses the second
commit's first write instead, and that commit is rolled back.

### 5.4 Blobs

A picture or video that a record references is a **blob**: uploaded (§8.4)
or imported (§13), it is the app's own stored file (`social_cache_doc`,
through `DocumentService`) with a `social_atproto_blob` row giving its CID
(SHA-256 of the bytes, `raw` multicodec), MIME type and size. The MIME
type is the one the bytes are, never the one an upload declared, and
anything but a picture, a video or a sound is kept as
`application/octet-stream`. `getBlob` streams it with
`Content-Security-Policy: default-src 'none'; sandbox` and
`X-Content-Type-Options: nosniff`, and anything but a picture, a video or
a sound as `application/octet-stream` with `Content-Disposition:
attachment`; `listBlobs` lists them per repository; a blob no record refers
to is deleted by the ordinary document retention. Limits are Bluesky's:
pictures ≤ 2,000,000 bytes (re-encoded to fit, §8.4), at most ten per
post (four in `app.bsky.embed.images`, five to ten in
`app.bsky.embed.gallery`); a video is one mp4 ≤ 100,000,000 bytes and
three minutes, made into a stream by Bluesky's video service (D11, built
in 3d). AT Protocol blobs can hold other bytes, but the Bluesky post
Lexicon does not define arbitrary file attachments.

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

`com.atproto.sync.getRepo` (the CAR written out as the repository is
read, a page of rows at a time, so a repository larger than a request's
memory is served), `getLatestCommit`, `getRecord`, `getBlocks` (blocks by
CID — tree nodes, commits, records — as a CAR with no roots; one CID the
repository does not have refuses the request with `BlockNotFound`),
`getBlob`, `listBlobs`, `listRepos`, `getRepoStatus`, `subscribeRepos`
(§7);
`com.atproto.identity.resolveHandle`, `updateHandle` (custom handles, later);
`requestPlcOperationSignature` and `signPlcOperation` to a session allowed
`identity:*`, for a tool moving the account away (§13.2);
`getRecommendedDidCredentials`, `submitPlcOperation`, `checkAccountStatus`
and `activateAccount` to an account moving here (§13);
`com.atproto.server.describeServer`, `createSession`, `refreshSession`,
`deleteSession`, `getSession`, `getServiceAuth`, `createAppPassword`,
`listAppPasswords`, `revokeAppPassword`, `createAccount`
(migration in only, §13; open registration is **never** offered — a
Bluesky account here is a Nextcloud account); `deactivateAccount` is not
served (switching off is in Settings, §4.6); `com.atproto.repo.createRecord`, `putRecord`, `deleteRecord`,
`getRecord`, `listRecords`, `describeRepo`, `uploadBlob`, `applyWrites`,
`importRepo`, `listMissingBlobs`; `com.atproto.moderation.createReport`
(proxied, §12.3); `com.atproto.label.queryLabels` (answers the self-labels
of local records only); `_health`.

`describeServer` advertises the instance's actual handle suffix (for example,
`.social.example.com`) and the maximum upload limit enforced by the XRPC
endpoint, including its 100,000,000-byte video limit. It
does not publish a privacy-policy link unless the instance has a real policy
URL; the Social app landing page is not a privacy policy.

**Proxied to the AppView** with a service-auth token for the calling user
(`Authorization: Bearer <JWT signed by the user's signing key, aud
did:web:api.bsky.app#bsky_appview>`): everything under `app.bsky.*`;
`chat.bsky.*` goes to Bluesky's chat service
(`did:web:api.bsky.chat#bsky_chat`) the same way (D15). If an AppView
rejects the service-fragment audience with `401 BadJwtAudience`, both the
local reader and PDS proxy retry once with the bare service DID, for deployed
AppViews that still validate only the legacy audience. Other refusals pass
through unchanged.

**Not served**: `com.atproto.admin.*`, `com.atproto.temp.*`,
`tools.ozone.*`, `com.atproto.server.createInviteCode*`, `requestAccountDelete`
(an account is deleted in Nextcloud), `com.atproto.server.requestPasswordReset`
(Nextcloud's password is Nextcloud's).

### 6.3 Authentication for Bluesky apps (D17)

Bluesky apps can sign in with this PDS in either of two ways:

- **The official Bluesky app signs in with an app password.** The person
  enters this PDS as the hosting provider, then signs in with their handle
  and an app password made in Settings → Apps and account → Bluesky. The
  current official app calls `PasswordSession.login` against the selected
  service URL ([login implementation](https://github.com/bluesky-social/social-app/blob/main/src/state/session/session-core.ts)).
  This PDS accepts only its own Social app passwords at
  `com.atproto.server.createSession`; the Nextcloud account password is
  never accepted or sent to the Bluesky app. Access JWTs last two hours and
  refresh JWTs 90 days, signed with the instance service key and carried in
  `Authorization: Bearer`. Revoking an app password ends its sessions.
  The PDS also serves `createAppPassword`, `listAppPasswords` and
  `revokeAppPassword` for authenticated sessions. A newly created password
  is returned once; listing returns only its name, creation time and optional
  privileged flag, never its secret or internal database id. Creating a
  password with chat access requires a session that already has
  `transition:chat.bsky`.
  The login identifier may be a handle, DID, or Nextcloud email address. If
  an email address belongs to multiple local accounts, each account's own
  Bluesky identity and app-password hashes are checked, so an earlier
  account without a Bluesky identity cannot block the valid match.
- **OAuth is available to clients that implement AT Protocol OAuth.** They
  resolve this PDS's discovery metadata and use PAR, DPoP and PKCE S256.
  The person signs in to Nextcloud here, reviews the requested access and
  approves it. The client receives a DPoP-bound OAuth session and never
  receives the Nextcloud password. These sessions appear in Settings → Apps
  and account → Bluesky and can be revoked there. Settings also shows the
  exact PDS address and handle needed by the official app.

OAuth is AT Protocol's profile, not a reuse of the Mastodon client API's
OAuth tokens. The two token worlds and their sessions stay apart. The full
OAuth flow is exercised with the official `@atproto/oauth-client-node`
library against the development network, including account resolution,
PAR, server-side approval, token exchange, PDS requests, refresh and sign-out.
When `getSession` returns an email under `transition:email`,
`emailConfirmed` reflects Nextcloud's verified status for the primary email
property. Merely having an email address configured on the Nextcloud account
does not mark it as confirmed; without that verification, clients receive
`emailConfirmed: false`.

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
`#identity` (an operation the directory confirmed: a new DID, a handle
change), `#account` (deactivated/deleted), `#sync`
(the head commit, when an account is switched back on, §4.6) and `#info`
frames, each with a `seq` from one monotonic counter per instance. A client
that connects with `?cursor=N` is replayed from `N+1` out of
`social_atproto_event` (§15), which keeps 72 hours of frames (the relay's
replay window; *verify*), then followed live.

The daemon reads new events by polling the event table (every 250 ms when
idle, immediately after a write it was told about through a Redis/APCu
notice where available) — the web request writes the row, the daemon
serves it; no shared memory, no second code path. `--once` drains and exits
for tests; `--max-seconds` as `social:worker` has.

A frame's `seq` is the row's auto-increment id, taken when the row is
inserted inside the commit's transaction and visible when that transaction
commits, so a lower number can appear after a higher one is already
there (two writes of different repositories overlapping). The daemon
never serves past a number that is not there yet: it holds the later
frames back and waits up to two seconds (`FirehoseDaemon::GAP_GRACE`,
as the reference PDS sequencer does) for the missing one, then serves
them in order. A number that is still missing after that is a write that
rolled back; the daemon logs it at info level and goes on, and a frame
with that number that turns up later is served by replay only. systemd unit and the
Docker variant are documented in Admin.md; the setup check "firehose
reachable" connects to the public URL and reads an `#info` frame.

### 7.2 Telling the relay

`com.atproto.sync.requestCrawl` is sent to the relay (`bsky.network` by
default, configurable, several allowed) by the five-minute maintenance job
after Bluesky is enabled and after the daemon has been unreachable for more
than the replay window. Failed initial requests retry after 15 minutes; outage
recovery requests are spaced six hours apart. When a relay accepts a crawl but
the relay or public AppView still cannot find an active repository after an
hour, maintenance retries hourly while the indexing gap remains. The relay
then subscribes,
backfills with `getRepo` per DID, and stays connected. The admin page shows the
relay's last connection and the lag between the head `seq` and what was served.

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

What a missed event or a failed write left behind is caught by the reconcile
pass (`Publisher::reconcile()`, every five minutes from
`Cron\AtprotoMaintenance`): it checks the last day's public posts for a
missing record, and walks every locally linked Bluesky post record to repair
edits and remove records whose Social post is gone, including old posts. Each
run looks at the newest 100 and the next 100 below its saved position
(`walkPages()`, in `social_durable_cache`); it cycles through the full record
inventory over time while checking recent public posts each pass. Imported
posts and records a moving repository brought (no local object) are left as
they were. An edit that makes a post private, unlisted, archived or otherwise
ineligible removes its public Bluesky record and its gates; making it public
again publishes a new record.

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

The limit is **300 graphemes** and 3,000 bytes. Longer text is split at word
boundaries into linked `app.bsky.feed.post` records. The first record stays at
the original URI and remains the thread root; each continuation replies to the
previous part and points to that root. Facets are rebased to each part, and a
link crossing a boundary remains linked to its full URI. Edits update these
records in place, keep their URIs where parts remain, and delete obsolete
continuations. The counter in the composer is unchanged (Social's own limit),
but the composer explains when a long public post will become a Bluesky thread.
Threads exceeding the repository's 200-record commit limit are written in
ordered bounded commits; a retry or reconciliation completes any partial
publication, and deletion removes continuation records before the root.
Only a public post is published (`Publisher::goesToBluesky()`),
so where the server has Bluesky on and the writing account has an active
identity, the composer's audience menu says "Not on Bluesky" under Unlisted,
Followers and Direct message (`VisibilitiesInfos.js` marks which audiences
go there).

### 8.3 Warnings, polls, quotes, replies

- **Content warning** (`spoiler_text`): self-label `!warn` plus the warning
  as the first line, `Content warning: <warning>` then a blank line, counted
  against the 300. A Social post marked sensitive also gets `graphic-media` on its
  pictures as a self-label when it carries pictures. Self-labels received
  from a Bluesky post are retained when the post is mirrored back to Bluesky;
  a `putRecord` replaces that set, and editing the warning from a Fediverse
  client adds or removes `!warn` without discarding the other self-labels.
- **Poll**: the question and options as text (`Poll: A / B / C`), then
  the link; results are not synchronised.
- The words these add ("Content warning", "Poll", "+N more pictures" in
  §8.4) are in the post's language, from the app's translations, else in the
  author's Nextcloud language (`RecordMapper::l10nOf()`).
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
- **A Bluesky-only parent on ActivityPub** is exported as a standalone post
  with a link to the Bluesky parent. Its ATProto record still carries the
  native `reply` reference, but ActivityPub peers are not given an
  `inReplyTo` they cannot resolve, nor a delivery to the synthetic Bluesky
  actor. This also applies when the local post is edited or a held delivery
  is rebuilt (`ActivityService`).
- **Who may reply** (`app.bsky.feed.threadgate` on the thread's root): a
  thread its author closed to everybody is marked when it is read, so the
  reply is not offered here (`interaction_policy.reply` false); a narrower
  gate — the accounts the root mentions, its author's followers, the
  accounts the author follows, a list's members — is checked when somebody
  replies (`Reader\Threadgates`, through the AppView), and a reply it does
  not let through is refused with the reason: every AppView would hide it.
  This app's own posts carry the author's choice the same way: a post
  whose author lets only their followers, the accounts they follow, the
  accounts it mentions, the people on one of their lists — any of these
  together — or nobody reply is published with a threadgate under its key
  (a `followerRule`, `followingRule`, `mentionRule` or `listRule` each, or
  no rule at all; a list is published as a Bluesky curate list with its
  members that have a Bluesky identity, `BlueskyLists`, and stays published
  while a threadgate names it, even when the list is private, §9.7), which every AppView holds Bluesky replies to; a change of
  mind later rewrites or removes the gate (`Publisher::updateGates()`).
  The replies the author hid are in the same threadgate (`hiddenReplies`),
  and a Bluesky author's hidden replies are hidden here (see **Hidden
  replies** in Architecture.md).
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

### 8.4 Pictures and galleries

Up to ten `media_attachments` of image type, in order; more are dropped
and the text gains a line "+N more pictures" before the link. Each is
re-encoded to fit **2,000,000 bytes** — JPEG at descending quality, then
downscaled — through `gumlet/php-image-resize` which the app already
depends on, and uploaded as a blob (§5.4). One to four pictures use
`app.bsky.embed.images`; five to ten use `app.bsky.embed.gallery`. Both
preserve each picture's `alt` (the Social description) and aspect ratio;
when dimensions are unavailable, gallery items use a square ratio. A post
with pictures and a link card keeps the pictures.

### 8.5 Edits and deletes

**Delete**: `deleteRecord` of the post, and of the likes and reposts local
actors made of it, in the same job that sends the ActivityPub `Delete`.
Every removal is tried; when one fails for a reason that may pass, the job
is tried again later, as in §8.6, and removes what is still there.

**Edit** (D10): the record is updated under its existing rkey, so its AT
URI remains stable and likes, reposts and replies continue to refer to it.
The update is queued for delivery for posts of any age; the reconciliation
pass also walks old records and repairs missed updates. If an edit makes the
post private, unlisted, archived or otherwise ineligible, its public Bluesky
record and gates are removed instead.

### 8.6 Likes, boosts, follows

A local like of a post that exists on Bluesky writes `app.bsky.feed.like`
(unlike deletes it); a boost, `app.bsky.feed.repost`; a follow of a Bluesky
account, `app.bsky.graph.follow` (unfollow deletes it). "Exists on
Bluesky" means the post is either a Bluesky post (its stream row carries an
`at://` id, §9.3) or a local post with a `social_atproto_record` row. A like
of an ActivityPub-only post writes nothing. These go through the same
queued publisher and are reflected in the viewer flags the status entity
already carries (`favourited`, `reblogged`) — one action, two networks.
A write that fails for a reason that may pass — the service down, a lock
that timed out — is tried again later, eight times over about 80 minutes;
one Bluesky refuses as such is not. A like or boost undone before it was
written writes nothing.

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

`Cron\AtprotoSync` (a `TimedJob`, every minute) takes the watches due
(`next_sync <= now`), at most `SYNC_BATCH` per pass, at most the
instance-wide `atproto_sync_ceiling` (default 200 requests per pass; the
admin page shows how far behind the slowest watch is) and within the pass's
wall-clock budget (`AtprotoSync::MAX_DURATION`, 45 seconds for its three
steps — notifications, feeds, direct messages — each checking the clock
between accounts, so a slow AppView ends the pass and what is still due is
read on the next one), and asks the public
AppView `app.bsky.feed.getAuthorFeed` for each (`filter=posts_with_replies`,
`limit=50`, the stored cursor): new posts are stored, the cursor advanced,
`next_sync` set by backoff — one interval after a page with posts, doubling
up to six hours after empty pages, reset by the next post. Notifications are
polled first every minute and do not back off after empty pages: they are the
only inbound signal for likes, reposts and replies to local posts. The
effective delay still depends on the Nextcloud background-job runner cadence
and Bluesky AppView indexing. Reposts in the
feed become boosts (`Announce`) by the Bluesky author; replies are stored
with their `in_reply_to` and the parent fetched through `getPostThread`
when it is not here (bounded, one hop per pass). Only the public AppView is
read, unauthenticated, which is why nothing a Bluesky user kept to
themselves can arrive.

`FeedPoller` and `NotificationPoller` keep an unfinished walk in the existing
cursor column (`Reader\PollCursor`): `since`, the last committed timestamp;
`newest`, the newest timestamp of the walk; and `page`, the next opaque AppView
cursor. Each pass reads one page and resumes the rest on the next pass. Only
reaching `since` or the end commits `newest`; a failed or malformed response
does not move the checkpoint. `indexedAt` values are not event identifiers:
posts or notifications tied at the committed timestamp are read, and only a
strictly older timestamp ends the walk. The opaque cursor carries ties across
pages; imports are idempotent when the boundary is seen again. Empty pages
with a continuation are followed,
and pending walks do not back off as empty feeds. A first read remains a
snapshot of the newest page. Pinned posts are handled separately by
`BlueskyPins`, so they neither stop the chronological walk nor advance it.

A Bluesky post is a stream row of type `Note` with id `at://…/app.bsky.feed.post/…`
(`id_prim` as for any id), `url` the `https://bsky.app/profile/<handle>/post/<rkey>`
link, `attributed_to` the cached actor, `content` rebuilt from text and
facets (links and mentions as anchors, hashtags as hashtag links; a
mention's `Mention` tag is named by the `@handle` the text shows, the DID
only in its target, and a reply to a local post names its author by their
handle), pictures
as `social_cache_doc` rows pointing at the AppView's CDN URL with the
blob's CID and alt text. Both the legacy four-image embed and
`app.bsky.embed.gallery#view` are mapped; gallery items use their full image
URL, fall back to the AppView thumbnail when it is missing, and flow into
the existing paged gallery UI. Video embeds use the video playlist; when an
AppView view is missing but the original record has a validated video blob,
the public Bluesky video service playlist and thumbnail are derived from the
author DID and blob CID. Blob references in third-party embed lexicons are
also mapped when they carry a valid CID and media type: supported image blobs
use the Bluesky CDN, while other public attachments use the author's validated
HTTPS PDS `com.atproto.sync.getBlob` endpoint and this instance's streamed-file
route. Streamed images use that same-origin route as their preview as well, so
formats the Bluesky image CDN does not transform still render in the timeline.
Their declared filename is kept where available, otherwise a useful type-based
name is shown; the file card identifies its type from that name or the
validated MIME type even though the streamed URL has no filename suffix.
Logged-in-only posts never expose these public file routes. Unknown MIME types,
malformed CIDs and unsafe PDS endpoints are omitted.
External link embeds retain their URL, title, description and thumbnail as a link
preview. A quote becomes `quoted_id`; an embedded custom feed, list or
starter pack becomes a link to its bsky.app page with a card made from what
the AppView says of it — its name, description, creator and picture, no page
read (`PostMapper::cardOf()`); the card's provider line and the
link that stands in for a video with no usable playlist or blob says what the
thing is ("Feed by @…", "List", "Starter pack", "Video"), not which network
it is on — a reply as `in_reply_to`,
labels as `summary` (content warning) or `sensitive` per §12.1, `published`
from `indexedAt`, `local` false, and `details.atproto` with the CID and
the like/repost/reply counts the AppView reported. It goes through
`ImportService::parseIncomingRequest()` as a synthetic `Create`, so every
side effect of an arriving post — recipients, `social_stream_dest`,
notifications, filters, interests — happens the way it happens for
ActivityPub; nothing downstream knows a Bluesky post from any other.

When an AppView omits a usable embed view, standard post-record embeds remain
visible: image blobs resolve from their validated CID, image MIME type and
author DID to the Bluesky image CDN. Standard image-record blobs whose format
the CDN does not transform are recovered from the author's validated HTTPS
PDS and streamed through this instance. AVIF remains an inline preview;
formats not reliably rendered by browsers, such as HEIC/HEIF, are presented as
named downloads rather than broken image previews. A later AppView response can
fill media that was missing when the post was first indexed. External embeds retain their link
and blob thumbnail; quote-with-media records retain both the quote and its
images.
A raw video record remains a link to its post until the AppView supplies the
playable video playlist. Blobs in unknown/custom embed records are mapped as
files only when their CID, MIME type and public PDS endpoint validate; they
are streamed on demand rather than downloaded during feed ingestion. Unknown
MIME types and malformed CIDs are omitted rather than rendered as broken or
misleading media; arbitrary blobs are not assumed to be images.

A post is addressed to the public with the author's followers collection in
`cc`, which is what the home timeline joins on. An author who asked apps not
to show them to logged-out people — the `!no-unauthenticated` self-label on
the profile, or on one post — has that post, and any boost of it, addressed
to the followers collection alone: followers-only here, so the visibility
layer every read goes through keeps it out of anonymous pages, the public
timelines, search and the hashtag views, and what this server federates on
is not addressed to the public. Followers-only rather than unlisted because
unlisted is still served to anybody with the link, which is what the label
asks apps not to do (`PostMapper::isLoggedInOnly()`).

### 9.4 Jetstream (optional)

`occ social:atproto:listen` (`Reader\Jetstream\JetstreamListener`): one
WebSocket to the configured Jetstream's legacy `/subscribe` endpoint (the
one Jetstream v2 keeps wire-frozen for v1 consumers), asking for
`app.bsky.feed.post`, `app.bsky.feed.repost`, `app.bsky.actor.profile` and
`app.bsky.graph.block` with `requireHello=true`, so nothing arrives until an `options_update`
names the watched DIDs — at most 10,000, Jetstream's limit; an empty list
would be the whole network, so with no watches there is no connection. The
list is read again every minute and re-sent when it changed. The cursor
(`time_us`) is kept every five seconds or every 200 events, whichever comes
first, and on a lost connection and on stopping, and a reconnect starts five
seconds before it, which covers what was not kept yet; a lost connection
is retried with backoff up to a minute. The client (`JetstreamClient`) is this app's own,
on PHP streams: no compression is asked for, so every message is JSON.

What an event does (`JetstreamEvents`): a post or a repost created by a
watched account reads that account's feed the way the poller does
(`FeedPoller::pollWatch()`), two seconds later — when the AppView has
indexed it — and again after five and twenty seconds while the post, or
the boost the repost is, is still not here; then the watch is made due for
the poller's next pass (`AtprotoWatchRequest::wake()`). A deleted post is
deleted here at once (`PostStore::delete()`); a changed profile, or an
`#identity` event, reads the account again and keeps the new handle. A
block of a local account is recorded (§12.4). An `account` event — which
Jetstream sends for a wanted DID whatever the collections asked for — is
acted on at once (`AccountStatus`, §9.5): `active: false` with
`deactivated` or `suspended` hides the account, `takendown` or `deleted`
purges it, `active: true` restores it.
Interactions *on* local accounts by anybody else (likes, replies, follows)
are not in a DID-filtered stream; they keep arriving through the
notification poller (§10). The poller keeps running beside the listener,
so the daemon being down costs latency and nothing else. The admin page
shows whether the listener is connected, for how many accounts, and when
it last heard something, from the report the listener writes every fifteen
seconds. The cursor and the report are kept in the durable cache's table
(`JetstreamState`, `social_durable_cache`, a year's TTL renewed by every
write), not in app config, whose every write invalidates the config cache of
each request on the host; the `atproto_jetstream_cursor` and
`atproto_jetstream_status` app-config keys of older versions are moved there
on their first read and deleted.

### 9.4a Whole threads

Opening a post that is on Bluesky reads its thread from the AppView
(`BlueskyThreadSource`, `getPostThread`, ten reply levels and 20 parents up)
inline within one shared three-second budget across the sequential AppView
requests, so fallback reads cannot add another full timeout to the page load.
Thread roots and depth-continuation responses are accepted only when their
post URI matches the requested AT-URI; unavailable-post results must identify
that same URI as well.
Replies appear without waiting for cron. The reader compares every post's
visible direct replies with its `replyCount`, including replies nested below
the opened post. Where a node is incomplete, it tries the first-party
AppView's unspecced V2 thread view anchored at that post, then the specced
cursor-paged search fallback; remaining branches stay pending for background
retry. If the stable read reaches the endpoint's ten-level limit with a deeper
branch remaining, it also queues a background fill to continue that branch, up
to twenty bounded reads and the shared 150-new-reply budget. Context responses
keep their filling signal while that queued read is pending, including when
the inline thread is served from its short freshness cache, so the client
continues polling until the new replies are returned. A timeout or unavailable
deep branch leaves already stored replies intact. This also reads replies of
accounts nobody here follows and works for posts written here and published to
Bluesky. See **Whole conversations** in Architecture.md.

### 9.4b Who liked, reposted and quoted

The likes, boosts and quotes lists of a post that is on Bluesky — read from
there, or written here and published — have Bluesky's in them too
(`BlueskyInteractionSource`: `getLikes`, `getRepostedBy`, `getQuotes`), read
in the background beside the fediverse's collections. A visible read also
refreshes changed avatars on already cached Bluesky reactors, preserving their
full cached profile. The source checks each response's required `uri` against
the requested post before accepting actors, so a misrouted response cannot
replace the cached reactions. See **Who liked, boosted or quoted** in
Architecture.md.

### 9.4c Hashtags and searches

A hashtag's timeline and a search of posts here have Bluesky's posts too
(`BlueskyPostSource`, `searchPosts`), read in the background beside the
fediverse servers' hashtag timelines. See **Hashtags and searches beyond
this server** in Architecture.md.

A hashtag somebody here follows is read the same way every quarter of an
hour (`FollowedTagsFill`): `searchPosts` with `q=#tag`, `tag=[tag]`,
`sort=latest`, asked of the public AppView, at most 20 posts a tag, and only
the posts whose `sortAt` — the earlier of the record's `createdAt` and the
AppView's `indexedAt` — is after the last read and within two days. Those
are stored like any Bluesky post, so the home timeline of everybody
following the tag has them through the same join that puts a Fediverse
post there. A record's `tags` (the hashtags beside the text) are hashtags
here as the ones in its facets are: `searchPosts` finds a post by either.
Nothing is read while Bluesky is off for the instance.

### 9.4d Who follows an account, and whom it follows

The followers and following lists of an account on Bluesky have the ones
the AppView lists in them too (`BlueskyFollowListSource`:
`app.bsky.graph.getFollowers`, `getFollows`, up to three pages and 100
accounts), after the follows this server knows, read in the background
beside the fediverse's `followers`/`following` collections. Each account is
cached as it is found; one of this server's own accounts is named as
itself. Bluesky has no way to hide the lists, so nothing is hidden. See
**Who follows an account, and whom it follows** in Architecture.md.

### 9.4e Counts

A post read from Bluesky is initially stored with the `likeCount`,
`repostCount`, `replyCount` and `quoteCount` the AppView had at that moment.
When a thread is opened, its fresh `getPostThread` views also refresh those
counters on posts already stored from a feed or profile, without importing a
duplicate post. This makes the replies' counts current along with the thread
contents. Elsewhere, when somebody here sees a post (its page, a conversation
or a timeline page) and its counts are older than a quarter of an hour (a post
less than a day old) or six hours (older), it is queued for a background
`getPosts`, 25 posts a call (`BlueskyCountSource`); the cron's pass over posts
nobody looked at asks the same way every two hours. The answer replaces what
the post's own network said (`remote_*`) and the quote count in the post's
`atproto` block, and `quoteCount` is the origin's half of the post's quote
counter, which the status entity carries as `quotes_count`; a post the AppView
no longer has is deleted as §9.5 deletes it. Nothing is notified. See
**Counts where a post lives** in Architecture.md.

A local post that went to Bluesky is counted here, from the likes, boosts,
replies and quotes this server holds; the ones on Bluesky arrive as the
author's notifications (§10), and one the poll misses would never be
counted. So its record is asked about the same way, on the same schedule,
and the cron's pass takes the posts of the last seven days
(`BlueskyPublishedCountSource`). The AppView's totals already take in what
arrived from Bluesky and the likes, boosts, replies and quotes local accounts
wrote there as records too, so only what it counts beyond those is stored as
the post's `remote_*` half, and everything held here is added to it as for
any post: a post whose notifications all arrived shows the same numbers as
before, and one the poll missed a like of shows it. An author whose presence
on Bluesky is switched off (D23) is not asked about. See **A local post's
counts on Bluesky** in Architecture.md.

### 9.5 Deletes and edits from Bluesky

A `delete` op from Jetstream, or a post gone from the author feed on the
next poll (the AppView answers 404 for `getPosts` of it — checked for the
last page's ids once a day, bounded), becomes a `Delete` through the same
import path, as a remote `Delete` would. Bluesky has no edits.

An account Bluesky stops serving (`Reader\AccountStatus`): Jetstream's
`account` event says so at once (§9.4), and the feed poller concludes the
same when the AppView refuses `getAuthorFeed` with `AccountDeactivated`,
`AccountTakedown`, `RepoNotFound`, `RepoDeactivated`, `RepoSuspended` or
`RepoTakendown` three reads in a row (`FeedPoller::STATUS_AFTER`, with the
ordinary failure backoff between them — a single refusal is not acted on).
A *deactivated* or *suspended* account comes back, so its mirror is hidden
and kept: the cached actor is marked (`details.atproto.status`, and
`limited`, so no interaction of it is stored and account search does not
offer it) and its posts and boosts lose their `social_stream_dest` rows,
which takes them out of every timeline and viewer-limited read while the
rows themselves stay. The watch keeps being tried with backoff, and when
the feed answers again — or Jetstream says `active: true` — the mark is
lifted, the recipient rows are written back and the feed is read on the
next pass. A *deleted* or *taken-down* account is gone: the actor, its
posts, boosts, follows and everything else it left go through the cascade a
remote `Delete` of an actor goes through (`PersonInterface::delete()`), and
the watch is removed. Older posts are covered as well as recent ones; the
deletion sweep above only ever re-asked about the last week's.

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
  id of the post the client last saw. `DurableCache` keeps it in the
  database when the instance has no memcache; it is private to the viewer
  and feed and expires after that hour. A remote page with no visible posts
  but a continuation cursor is not the end of the feed: the reader follows
  such pages only within the three-second interactive AppView budget, with a
  ten-page hard cap. If time runs out, it checkpoints the opaque cursor and
  marks the response for timeline refill, so the client resumes without
  keeping the original request open. The XRPC specification describes a missing cursor
  as the end of pagination and flags the empty-page-with-cursor case as an
  area needing clarification; Social follows a returned cursor with a bound
  and retry checkpoint.
- **Routes**: `GET`/`POST`/`DELETE /api/v1/social/bluesky/feeds`,
  `GET /api/v1/social/bluesky/feeds/suggested`, and
  `GET /api/v1/timelines/bluesky?feed=<at:// URI>` (API.md). In the web
  client the address is `/timeline/bluesky/<DID>/<feed|list>/<key>`: the
  URI's own slashes stay out of the path, as web servers refuse an encoded
  one.
- **Lists made in a Bluesky app** are written to the account's repository
  (§6), so they reach the AppView, and read here as their feed; a curate
  list is the person's list here as well (§9.7).
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

### 9.7 A person's lists, one set (D22)

A person's lists here and their Bluesky lists are one set
(`Publisher\BlueskyLists` outward, `Reader\BlueskyListImport` inward). A
list here is a Mastodon list, private to its owner; a Bluesky list
(`app.bsky.graph.list`, purpose `curatelist`, members
`app.bsky.graph.listitem`) is a public record. So a list carries a
visibility (`social_list.visibility`, `private` by default, `public`;
`public` in the List entity, API.md):

- **Made public**, a list is written to the owner's repository as a curate
  list with an item for each member that has a Bluesky identity (a
  Bluesky account, or a local account's DID); a member on the Fediverse
  alone has none and is not listed there. Renamed here, the record is
  renamed, the rest of it (a description an app gave it) kept; a member
  added or taken out here is listed or withdrawn; deleted here, the list
  and its items go — an item an app wrote for an account that could not
  be found among them, matched by the list it names. A public list not on
  Bluesky yet (made public while Bluesky was off on this server, or whose
  first write failed) is published by the maintenance pass
  (`BlueskyLists::publishMissing()`, 200 lists a pass, going on where the
  last one stopped).
- **Made private again**, the records are withdrawn — unless one of the
  owner's threadgates names the list (§8.3): a reply rule that lets the
  members of a list reply needs the list on Bluesky, so it stays published
  while a gate names it. The list's settings say so.
- **A curate list a Bluesky app signed in here writes** (§6,
  `WriteService`) becomes a public list here, named as on Bluesky, and its
  items its members: a local DID as the local account, any other as the
  Bluesky account, read into the cache (an account the AppView does not
  know, or one blocked here, is left out). The record is tied to the list
  by its local id (`list:<id>`, an item `list:<id>#<md5 of the member>`),
  so the list is one from then on: a rename in the app renames it here,
  an item deleted takes the member out, the list deleted deletes it here.
  A list's visibility is not changed by the app; a moderation list is not
  a list here (§12.5).
- **No round trip**: what this server writes for a list goes into the
  repository directly and is never read back as an app's write, and a
  record that already stands for a list is not imported again.
- **An account that moved here** (§13.1) brings its curate lists: the last
  step of the move makes each a public list here with its members, the
  same way, and a second run makes none twice.

On Bluesky a list may hold anybody; a list here is a view of whom its
owner follows, so a member they do not follow shows nothing in the list's
timeline here until they do.

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

A notification the poll misses is lost as a notification, but not as a
number: the counts of the post it was about are read from the AppView too
(§9.4e).

The `bluesky:` kinds are this app's own, as Pixelfed's `story:` ones are:
the web app words them; a Mastodon client leaves out a kind it does not
know, as Mastodon's API asks; they raise no Nextcloud notification.

The **notification policy** (`NotificationPolicyService`) sees a Bluesky
sender like any other: `for_not_following` holds a like from a Bluesky
account the person does not follow, `for_new_accounts` reads the DID
document's creation time (the first PLC operation's `createdAt`) as the
account age, and the requests inbox shows the sender with the badge.

The **statistics page** counts all of this as it counts the fediverse's:
a Bluesky like, repost or reply is engagement like any other, and a
Bluesky follower is a follower (`Atproto\Reader\BlueskyStatistics`, a
`Service\Statistics\StatisticsSource`). Two things differ and are taken
from Bluesky's side. The server a Bluesky account is on is its PDS, as its
DID document names it; Bluesky's own `*.bsky.network` hosts are shown as one
`bsky.social`, and so is an account whose DID document was never read. A
post that also went out on Bluesky is counted from its author's repository
(a `app.bsky.feed.post` record carrying the post's id), because it is never
delivered to an instance and has no instance path. `starterpack-joined`
notifications are counted as the followers a starter pack brought.

**Suggested starter packs.** Once a day, with the fediverse's collections,
`Atproto\Reader\SuggestedStarterPacks` asks the AppView, signed out, for
`app.bsky.unspecced.getSuggestedStarterPacks` and each pack's list
(`app.bsky.graph.getList`, the first 50 members), and keeps them in
`social_starter_pack` with `origin` `bluesky`. They are listed on the
Discover page and served at `/api/v1/starter_packs` like any other starter
pack; the members are cached from the list's profile views, an account this
instance blocks or that Bluesky's moderation limits is left out, and a pack
Bluesky no longer suggests goes at the next read. A pack whose list cannot be
read that day keeps what was read last time.

### 10.1 Direct messages

A conversation with somebody on Bluesky is a conversation in **Messages**
like any other; nothing in it says which network it runs on.

- **Arriving.** `Chat\ChatPoller`, a step of `Cron\AtprotoSync`, reads
  each local account's chat log (`chat.bsky.convo.getLog`, as the account,
  with a service-auth token for the chat service, `AppViewClient::chatAs()`)
  from where it was read up to (`social_atproto_chat_cursor`). An account's
  first read takes the unread messages (up to 20) of its 20 newest
  conversations (`listConvos`, `getMessages`). Each message somebody else
  sent becomes a direct `Note` from its sender to the account, naming it
  (`Chat\ChatStore`, id `https://bsky.app/profile/<sender>/convo/<convo>/<message>`),
  through the same import path as a post, so it notifies, pushes and counts
  as unread like a direct message from the Fediverse, and the notification
  policy holds it the same way. Each message answers the one before it in
  the Bluesky conversation, received or sent from here, which is how this
  app's conversations (threads of direct messages) keep it as one. A
  message its sender deletes is deleted here. A shared post arrives as its
  link. An account is read every two minutes while it has messages, less
  often while it has none but at least every half hour, and on the next
  run once its owner opens Messages or sends one.
- **Leaving.** A direct message written here (`PostPublishedEvent`, queued
  as `AtprotoPublish` with action `message`) goes to the people it is
  addressed to who are on Bluesky as one Bluesky message
  (`Chat\ChatSender`): their conversation is found or started
  (`getConvoForMembers`), and the text — without the mentions that address
  it, at most 1,000 graphemes, its links and hashtags as facets — is sent
  (`sendMessage`). Recipients on the Fediverse get it over ActivityPub as
  before. Pictures are not sent: a Bluesky direct message carries none.
  `sendMessage` takes no idempotency key and a failed job is tried again,
  so the first attempt is recorded before the message goes
  (`ChatStore::attempting()`), and a retry of a message not recorded as
  sent first looks among the conversation's 100 newest messages
  (`getMessages`) for the account's own of the same text since that
  attempt; one found is recorded as sent instead of sending it again. Only
  a conversation with more than 100 messages since the attempt could hide
  it.
- **Written in a Bluesky app.** A message the account itself writes in a
  Bluesky app (signed in here or anywhere) is in the log too, and is
  stored as the account's own direct message to the conversation's other
  members (`getConvo`, asked once a run), answering the one before it.
  It is saved as it is (`StreamRequest::save()`, with its recipient rows),
  never through `PostService`: no `PostPublishedEvent`, so neither
  `ChatSender` nor ActivityPub delivers it again; the copy is not `local`.
  A message sent from here is remembered by the id `sendMessage` answered
  with (`ChatStore::sent()`, `DurableCache::setShared()`, 90 days), so its
  copy in the log is not stored a second time. A message the account
  deletes in a Bluesky app is deleted here when it was written there; one
  sent from here stays, as it may have gone to the Fediverse too.
- **Read state, both ways.** A conversation marked read here
  (`ConversationService::markRead()`) is marked read on Bluesky
  (`chat.bsky.convo.updateRead`) for each Bluesky conversation its first
  and newest messages are in — the conversation is in the id of a
  message from Bluesky, and remembered for one sent from here
  (`ChatStore::convoOf()`); reading every conversation here
  (`markAllRead()`) is `updateAllRead`. Both are queued (`AtprotoPublish`
  `chat`, `Chat\ChatState`), never in the request. A conversation read on
  Bluesky (`chat.bsky.convo.defs#logReadConvo`, or the older
  `#logReadMessage`) is marked read here up to the message it names
  (`ConversationService::markReadUpTo()`), never back.
- **Requests.** Bluesky files a conversation somebody the person does not
  follow started as a request (`status: request`). Here it is what a
  direct message from a stranger is everywhere: it shows in Messages, and
  the notification policy's `for_private_mentions` holds its notification
  among the requests (§10) while the person takes direct messages only from
  the people they follow (or from nobody) — then Bluesky lets no stranger
  start one, and only a request from before the change still brings
  messages. While the person takes them from everybody, a stranger's
  message notifies here like any other, as they chose, and Bluesky keeps
  the conversation among its requests until it is answered or accepted.
  Answering it from here accepts it on Bluesky
  (`acceptConvo` before `sendMessage`, which does not accept by itself);
  accepting the sender from the requests accepts it too, and dismissing
  the sender there, or putting the conversation away here
  (`DELETE /api/v1/conversations/{id}`), leaves it on Bluesky
  (`leaveConvo`) while it is still a request — never an accepted one. A
  conversation named by its sender is found with `getConvoAvailability`,
  which never starts one.
- **Who may send direct messages.** One setting, everybody / people you
  follow / nobody (`NotificationPolicyService::directMessagesFrom()`,
  `GET`/`PATCH /api/v1/social/direct_messages`). Here it is the policy's
  `for_private_mentions` seen from the other side: `all` accepts direct
  messages from strangers, `following` holds them, `none` holds every
  direct message whoever sent it (a sender accepted from the requests
  still reaches the person) — held, not refused, as the policy holds
  everything. On Bluesky it is the `chat.bsky.actor.declaration` record
  (`allowIncoming`), written by `Publisher::writeSelfRecord()` whenever
  the answer changes, from the settings page or from the policy; there the
  chat service refuses what the record does not allow. Unasked, on the
  account's first read of its messages, it is written only to narrow
  Bluesky's default (`none`): a wider setting is not published until the
  person sets it, so nobody is opened to strangers on Bluesky without
  choosing it. A declaration a
  Bluesky app writes through this server (`putRecord`, `createRecord`)
  becomes the setting here. The default is this app's: an account created
  here starts calm (§10), which is `following` — Bluesky's own default —
  and an older account that never chose a policy takes direct messages
  from everybody here, while Bluesky keeps its own default until the
  person sets the answer.

Everything stops when the chat service setting is empty (§14.2), but for
the declaration, which a change of the setting still writes: a Bluesky app
reaching the chat service another way goes by it.

## 11. What the person sees

- **Profiles**: a Bluesky account's profile is the ordinary profile page at
  `/@alice.bsky.social` (the handle has no second `@`, which is how the
  router tells it apart), the follow button, the counts, the posts from
  the author feed (fetched live as the reader's linked Bluesky identity when
  available, and publicly when no usable identity is linked, with one post of overlap beyond the API page
  and AppView cursors expanded once while local `max_id` pages through cached
  posts, when the account is not watched), and a **Bluesky badge** next to the name — the
  butterfly glyph with `title="On Bluesky"`; beside it, the one verified
  check (`VerifiedBadge`) for Bluesky's trusted verifications and this
  instance's own (§12.6). The badge appears on every card the author
  appears in: post header, notification, follower list, search result. The
  profile status API reads `getAuthorFeed` into the shared stream
  before its regular account query; opaque cursors are stored against local
  status ids, and each AppView page is read once while its cached posts are
  traversed, preserving the profile's existing `max_id` pagination. A bounded
  refill resumes from its saved cursor after an invalid or failed AppView page,
  so hidden records do not have to be traversed again. An AppView outage does
  not prevent already stored posts from being read. The
  same endpoint serves the Posts, Photos and Videos profile tabs, so imported
  records keep the existing media filtering, cards and post actions. Pinned
  posts continue through the profile's pin endpoint.
- **Addressing**: `@alice.bsky.social` in a post mentions the Bluesky
  account; the composer's mention picker completes Bluesky handles from the
  AppView typeahead when the typed text has a dot and no second `@`. Visible
  typeahead and post-search requests share the three-second AppView timeout;
  background reads keep the longer timeout.
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
  recovery phrase (§4.4). **Settings → Blocking**: labelers (§12.2) and
  shared lists (§12.5).
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
| `!no-unauthenticated` (self, on the profile or a post) | the post and any boost of it are followers-only here: not served to anonymous readers, not in the public timelines or search, not federated to the public (§9.3) |
| `!hide` (service) | the post is not stored; the account's posts are not fetched while the label stands |
| `spam`, `impersonation`, `scam`, `misleading`, other service labels | a content warning naming the label; the account's profile shows it |
| account-level `!hide` / takedown | the cached actor is marked limited: no posts fetched, interactions not announced, not offered by account search |
| account deactivated, suspended, taken down or deleted on Bluesky | hidden and kept, or purged, as §9.5 says |

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
published, but it is kept in step with the AppView, where a Bluesky mute
lives, privately (`Publisher\BlueskyMutes`): a mute or an unmute of a
Bluesky account made here is told to it (`app.bsky.graph.muteActor` /
`unmuteActor`, as the person), and one a Bluesky app signed in here
makes goes to the AppView and, once it took it, is made here too — so
the app and Social hide the same accounts. A mute that runs out here is
not taken back there. A block is written as `app.bsky.graph.block` **only when the
person chose to publish their blocks** (Settings → Bluesky, off by
default, because a Bluesky block is public), and only a published one
keeps the blocked account from replying to, quoting or mentioning them on
Bluesky (D16). Turning it on publishes the blocks of Bluesky accounts they
hold; turning it off withdraws every published one; an unblock withdraws
its record. A blocked Bluesky account's interactions are dropped on
arrival either way.

Thread notification mutes (`app.bsky.graph.muteThread` and
`unmuteThread`) are private AppView preferences. A signed-in Bluesky app's
accepted change is mirrored to `social_convo_state` after resolving its AT
URI through the local repository mapping. A post written here and mirrored
to Bluesky therefore resolves to its Social post id. A mute or unmute made in
Social uses the thread root's strong reference from `PostRefs`, so a local
thread root published to Bluesky is addressed by its repository AT URI.
This keeps both clients from disagreeing about locally stored replies. A
root that is not stored here cannot be mirrored locally; Bluesky still owns
the preference and its behavior in Bluesky clients.

A Bluesky user's block *of* a local account (`app.bsky.graph.block` in
their repository, subject the local DID) is never delivered here, and the
AppView says it only to the blocked account, as `viewer.blockedBy`. It is
learnt as that account and recorded as the same `blocked_by` relation an
incoming Fediverse `Block` is (`Service\BlockedBy\BlockedByService`, the
Bluesky side `Reader\BlueskyBlockedBy`), so everything that reads that
relation — the timelines, notifications, suggestions, the relationship's
`blocked_by` — applies unchanged; a block found gone takes it away. It is
learnt:

- **before an interaction reaches the account**: a reply, a quote or a
  follow asks the AppView as the local account
  (`app.bsky.actor.getProfiles`, 25 at a time) in the request, a like, a
  repost or a new post's mentions in the queued job that writes them
  (`Cron\AtprotoPublish`). The answer is believed for five minutes
  (`DurableCache::setShared`); when the AppView cannot be asked, what is
  recorded stands;
- **when a profile is looked at**: the relationship asked for one account
  asks too;
- **from reads already made as the person** — custom feeds and lists,
  post search, suggested accounts, starter packs — from every account view
  in the answer that carries a `viewer`, writing only what changed;
- **from Jetstream** (§9.4), when the listener runs: a block by a watched
  account whose subject is a local DID is recorded at once; a deleted block
  names nobody, so the local accounts that blocker is recorded as blocking
  are asked about again after two, five and twenty seconds, until the
  AppView no longer says so. Blocks by accounts nobody here follows are not
  in that stream; they are learnt the other ways.

A reply to a post whose author, or whose thread's first author (every
AppView keeps such a reply out), has blocked the local account is refused
with "This account has blocked you", before the thread gate and the
author's reply rule (`PostService`); so is a quote of one of their posts,
and a new follow of them (`FollowService`) — wherever the author is, since
a Fediverse block is the same relation. A like or a repost is still made,
and the follows already there stay, as Bluesky keeps them: the AppView
hides them, and the blocker's posts leave the local account's timelines.

The administrator's federation blocklist gains Bluesky: a blocked **PDS
host** or **DID** (`social_atproto_blocklist`, managed on the admin page and
by `occ social:atproto:block`) is refused on resolution, its posts not
fetched, its interactions dropped. The whole network is switched off by
the instance switch (§14.2), which keeps the repositories but stops the
daemon, the crons and the publisher.

### 12.5 Shared moderation lists

A Bluesky moderation list (`app.bsky.graph.list` with purpose
`modlist`, or any list) is somebody's list of accounts to mute or block.
**Settings → Blocking → Shared lists** subscribes to one by its bsky.app
link or `at://` URI, choosing *muted* or *blocked*
(`Service\ModerationList\ModerationListService`, the list read through an
`AccountListSource`, today `Reader\BlueskyModerationLists`). Nothing in
it is Bluesky-only from the reader's side: each account on the list,
read from the AppView (`app.bsky.graph.getList`, paged, at most 1,000) and
cached as any Bluesky account is, gets an ordinary mute or block in
`social_actor_relation`, applied on read like one the person made. What
Bluesky is told follows D16:

- a mute list is muted at the AppView (`app.bsky.graph.muteActorList` /
  `unmuteActorList`, as the person), privately, so a Bluesky app signed in
  here hides the same accounts;
- a block list is published as an `app.bsky.graph.listblock` record only
  when the person publishes their blocks (§12.4), and withdrawn when they
  unsubscribe.

The list is read again every six hours (`Cron\ModerationLists`): an
account added to it is muted or blocked, one taken off it no longer. The
subscription remembers which mutes or blocks it made (user value
`moderation_lists`), and only those are taken back when it ends or an
account leaves the list: an account the person had muted or blocked
already stays so. A list that cannot be read at the moment leaves its
accounts as they were. A list block does not sever follows the way a
person's own block does (§12.4): on Bluesky a list block hides both ways
without touching the follow records, and here the block relation does
the same on read. At most 20 subscriptions per person.

### 12.6 Verification by this instance (D24)

An instance can vouch for accounts: a company running the server verifies
its own people, a community the accounts it knows. **Administration →
Moderation → Verified accounts** lists them and verifies one by its handle;
a moderator (an administrator or a delegate of the Social settings) also
verifies or takes back from the account's profile menu
(`VerificationController`, `VerificationService`). Any account can be
verified — local, Fediverse, Bluesky. `social_verification` keeps one row
per account: who verified it, when, and the DID, handle and display name
its published record names.

The check is shown wherever the account appears: the account entity
carries `verification` (`{by, issuer, created_at}`, null when the instance
did not verify it), read once per request from the table rather than
stored on the cached actor, which is rewritten whole on every refresh.
The web app draws one check (`VerifiedBadge`) for both this and Bluesky's
own verification (`bluesky.verified`, §9.1), its label naming who:
"Verified by Example Inc", "… and by Bluesky", "Verified by 2 trusted
verifiers". `by` is the verifying account's name, or the instance's
(Theming) when none is chosen.

**Publishing.** The administrator chooses a local **verifying account**
(`verification_account`, admin only; it is given a Bluesky identity when it
has none, and its profile is published so the network knows its name).
With Bluesky on, a verification of an account that has a DID — a local
account's own identity, or a Bluesky account's — is an
`app.bsky.graph.verification` record in that account's repository, local
id `verification:<subject DID>`. The AppView judges a record invalid once
the subject's handle or display name differs from what it names, and keeps
one verification per issuer and subject, so a change means withdrawing the
record and writing a new one, in that order:

- a local account: when its profile is published (`Publisher::publishProfile()`,
  after a name change, and after a new custom handle, which queues it);
- a Bluesky account: when its cached profile is stored with another handle
  or display name (`BlueskyActorService::store()`).

The record's display name for a local account is the one its profile
record carries (`RecordMapper::displayNameOf()`); Bluesky treats a
verification of an account without a display name as invalid. Another
verifying account moves every record to its repository, off the request
(`AtprotoPublish` action `verifications`); none withdraws them all. A
Fediverse account without a DID is verified here only, its row says so
(`did` empty), and the administration page shows it as "Shown here only".
An account deleted here takes its verification with it, the record
included (`ActorCascadeService`); a suspended one keeps it, as a
suspension can be lifted.

**What Bluesky shows.** Bluesky apps show a verification only from a
*trusted verifier* — an account Bluesky chose (`trustedVerifierStatus`),
which this instance cannot make itself; until Bluesky trusts the verifying
account the records exist and show nowhere but here. The interop job makes
the dev AppView trust the verifying account (`DevNetwork::trustVerifier()`)
and reads the verification back from `getProfile`
(`AtprotoVerificationTest`).

## 13. Moving a Bluesky account here

Its own phase (D19). The AT Protocol account-migration flow, driven from
the Migration page as a third card, **Bring your Bluesky account here**,
beside the Fediverse move-in.

### 13.1 The flow

There are two ways in, and in both this server is the *new* PDS of the
protocol's account migration (classes `Move\MoveInService`,
`Move\InboundMoveService`, job `Cron\AtprotoMove`, table
`social_atproto_move`).

**Driven from here** (Migration → *Bring your Bluesky account
here*):

1. The person types their Bluesky handle (or DID) and the account's own
   **password** — not an app password: a PDS signs a change of the DID only
   for a full session — and confirms with their Nextcloud password. The
   old PDS is read from the DID's state in the PLC directory, and this
   server signs in there (`createSession`, with the e-mailed sign-in code
   when the old PDS asks for one). The password is not kept; the session
   is, sealed, while the move runs. A PDS limits password sign-ins per
   address, and all of this server's people share its address: so a
   person may start five moves an hour, and the whole instance sends one
   PDS host at most 20 sign-ins in ten minutes (`rate-limited` past that).
   Inviting a DID (below) is held to five an hour as well.
2. A background job copies the **repository** (`com.atproto.sync.getRepo`,
   checked against the DID's signing key and written here byte for byte
   under a commit signed with a key made for the account here), the
   **blobs** the copied records name (`getBlob`, each checked against its
   CID; a blob no record names is not asked for) and the **preferences**.
   The repository and the blobs arrive in temporary files rather than in
   memory,
   held to the caps the server takes from a tool too: 64 MB for the
   repository (`repo-too-large` past that), and the largest upload for a
   blob (a larger one is not copied, and counts as missing below). A move
   downloads at most 10 GB of blobs (`progress.blob_bytes`, whether they
   were kept or not), and at most 50,000 blobs in one run; past either,
   the rest counts as missing below. A tool that drives a move here (below)
   is held to the same 10 GB for what it uploads.
3. Before that, the blobs the copied records name are compared with the
   blobs stored here (and the counts logged). When any is missing, the
   move stops in state `needs_decision` with `progress.missing` (`count`
   and the first 50 `cids`) and code `blobs-missing`, and the first 20
   records that name a missing one in `progress.missing_records`
   (`MovedBlobs::named()`: a post's date, the start of its text and its
   address; the profile by its collection): the person has them copied
   again or goes on without them (`POST …/move-in/continue`), the latter
   after a confirmation that says how many are lost and lists those
   posts, so the DID never switches with pictures silently lost. Then the old PDS
   **e-mails a code** (`requestPlcOperationSignature`); the move waits for
   the person to enter it. The page says where the code went
   when the old PDS's session names the address (`progress.code_sent_to`),
   offers *Send a new code* (asks again, three times an hour) and *Call off
   the move*. Until the PLC operation may have been sent, a move can be
   called off: it ends (`state` `cancelled`), its session on the old PDS is
   ended and dropped, and the copied repository and blobs are deleted;
   a background job that is still copying stops and drops what it copied.
4. First, what was posted on the old PDS since the copy comes along: when
   its `com.atproto.sync.getLatestCommit` names a newer revision than the
   one copied, the repository is copied again, with the blobs new to it
   (and the blob check of step 3 once more). With the code the old PDS
   **signs the operation** (`signPlcOperation`)
   that names this PDS, the key made here, the handle below and the
   rotation keys below; this server checks it is what was asked and
   sends it to the directory. The account **takes the DID**, and the DID
   this server had made for the account is retired (tombstoned with its
   repository).
5. `deactivateAccount` on the old PDS; then the **follows** become follows
   here, the posts posts in the timeline, the likes and reposts actions,
   the curate lists lists here (§9.7) — through the import path, nothing
   re-published.

**A move whose job stopped.** Core drops a queued job before it runs it,
so a job killed mid-step (out of memory, a restart) leaves its move
`running` with nothing to carry it on. The Bluesky maintenance job
(`Move\StaleMoves`) picks up a running move — here, away or driven by a
tool — that has not been written to for an hour and has no job waiting:
the first time it queues the move again, from its step; the next time it
fails it with `move-interrupted`, so "Try again" and "Call it off" work
again (a move driven by a tool waits for the person's decision on the
blobs again, or is done once the account is active here; a move here
stopped at the follows or the posts, which come once the account is active
here and are best effort, is done too). A running move writes as it goes —
each blob, each page of follows and of posts — so a long one is not taken
for a stopped one, and the job holds the move's lock while it runs: a move
whose lock is held is left alone, and a second job queued for it finds the
lock held and leaves the move to the first. A move here whose job stopped after the directory
took the operation finds the DID pointing here with the key made for it,
and the account takes the DID without asking the old server to sign
again; a move away that stopped while the DID was handed over goes on
from the activation once the directory names the other server, and can
no longer be called off.

**Driven by a tool** (Migration → *Bring your bridged Bluesky
account here*): the person invites the DID and gets a one-time code; Bridgy
Fed, or a migration tool such as `goat account migrate`, then runs steps
1–12 of the protocol's migration against this server (`createAccount`
with the code, `importRepo`, `uploadBlob`, `getRecommendedDidCredentials`,
`submitPlcOperation`, `activateAccount`). A `submitPlcOperation` while
blobs the records name are missing is refused (`BlobsMissing`) and the
move waits for the person's decision as above; asked to copy them again,
this server fetches them from the old PDS itself (`getBlob` is public).
Once activated, the follows and the posts come over as above.

**Posts written here.** The identity this server made for the account is
retired by the move, and with its repository the posts written here
disappear from Bluesky. `GET …/bluesky/move` answers how many there are
now (`posts_on_bluesky`), for the confirmation; the move keeps the count
and the time they were written in (`progress.republish`). Once the move is
done, `POST …/move-in/republish` publishes them again, into the moved
repository, through the Publisher a page at a time in the background —
the account's local public posts of that time, not the ones brought over
from Bluesky or elsewhere (`progress.republish.state` `queued`, then
`done`, with how many were `published`).

**Handle.** A handle on a domain of the person's own (`alice.example.org`)
stays theirs: the operation names it, it becomes the account's custom
handle here (§4.2, checked again every day as any custom handle is), and
the move's progress says so (`handle_kept`). A handle is the person's own
when it is not under a domain the old server gives handles under — its
`describeServer`'s `availableUserDomains`, its own host, `bsky.social` or
Bridgy Fed's `brid.gy` — nor under this server's host, nor another
account's here — and only when the domain names the DID, as any custom
handle must (§4.2: the TXT record or the well-known file); the directory
says only what the DID's owner wrote there. Any other handle becomes the
account's handle here, `alice.<host>`. The decision is made before the code is asked for (driven
from here) or when the tool asks for the recommended credentials.

**Rotation keys.** A move here keeps the DID the person's: the operation
lists their own keys first and this server's last, so a key of theirs
ranks above this server's and can undo, for 72 hours, an operation this
server signed. In order:

1. the person's **recovery key** here, when they made one (the identity
   keeps it as its recovery key afterwards);
2. every rotation key the DID **lists now** that is **not the old server's
   own** — driven from here, the old PDS's own keys are the ones its
   `getRecommendedDidCredentials` names; when it does not answer, no key
   is dropped. Driven by a tool, the old server cannot be asked (only the
   tool has a session there): a DID Bridgy Fed holds lists only Bridgy's
   keys, and those are dropped; for any other old server every listed key
   is kept, the old server's included;
3. this server's rotation key.

The directory allows five rotation keys; past that, the lowest-ranked of
the person's are left out, never this server's. Operations this server
signs later for the DID (a handle change, a key rotation, §4.5) list this
server's key and the recovery key only.

### 13.2 Moving away

The mirror image, so a person is not locked in. On the Migration page,
**Move your Bluesky account away** drives the move from here, the way
`goat account migrate` does it from outside (`MoveAwayService`):

1. In the request: `describeServer` on the other PDS (its DID, the handle
   domains it gives out, whether it needs an invite code), a service-auth
   token the account signs for that PDS and `createAccount`, then
   `createAccount` there with the existing DID — the account exists there
   *deactivated*. What the other PDS refuses is answered at once, with a
   code (§13.5). The form can ask `GET …/bluesky/move/target?pds=` first
   for the handle domains the server gives out and whether it needs an
   invite code; a handle under none of them is refused before anything
   is made (`handle-not-allowed`).
2. In the background (`AtprotoMove` job, `oc_social_atproto_move`): the
   repository as a CAR into `importRepo` (sent from a temporary stream
   that spills to disk, never as one string), the blobs that
   `listMissingBlobs` names into `uploadBlob` (each once; the list ends at
   a cursor it named before, at a page that names no new blob, and after
   1000 pages), the preferences into `putPreferences`. While the move runs, or waits for the person, posts
   written here wait instead of going to the account's repository (the
   Publisher skips them with a log line). Right before the DID moves they
   are published, oldest first, and when the repository's head moved on
   since it was exported — those posts, or a like or a follow made
   meanwhile — it is exported into `importRepo` again and the blobs
   copied again. A move called off, or stopped before the DID moved, no
   longer holds posts back: what waited is published then, or by the
   reconcile pass. Then `checkAccountStatus` there: the repository at
   this server's head commit with as many records, and `importedBlobs`
   equal to `expectedBlobs`. Anything missing stops the move *before* the
   DID moves, in `needs_decision` with `blobs-missing` and
   `progress.missing` (or `repo-incomplete`), and the records the other
   PDS's first page of `listMissingBlobs` names, read here, in
   `progress.missing_records` as for a move here;
   `POST …/bluesky/move/continue` copies again, or with `without_missing`
   moves the DID without what is missing, which the page confirms first,
   with the count and those posts.
3. The DID: `getRecommendedDidCredentials` there, then this server signs
   and sends the PLC operation itself (`IdentityService::handOver()`), with
   the person's recovery key first among the rotation keys and the other
   PDS's after it; this server keeps no rotation key of the DID. When the
   step fails — the directory's answer did not come, say — the directory
   is asked where the DID points: once it names the endpoint the other
   PDS recommended, it took the operation, which is taken into the PLC
   log from its audit log, and the move goes on with the activation. The
   operation is never sent again behind the person's back.
4. `activateAccount` there; here the identity becomes `moved_away`, the
   firehose says the account is deactivated, and nothing more is published
   for it.

**Who controls the account afterwards.** `GET /api/v1/social/bluesky/move`
says whether the person holds a recovery key (`has_recovery_key`), the
rotation keys the handover names by role (`["recovery", "new_server"]`,
never the keys themselves) and, once the move is done, the other server's
host (`new_host`). Without a recovery key only the other server controls
the account afterwards, so a start without one has to say
`without_recovery: true`; else it is refused with `recovery-key-missing`.

**Where the account stands.** Each step records the stage in
`progress.stage`: `account_created` (made on the other server,
deactivated), `repo_copied` (the repository is there, the DID has not
moved), `identity_switched` (the DID points there, the account is not
active there yet), `activated`, `done`. "Try again" goes on from the step
that stopped. Before the DID moved, `DELETE …/bluesky/move` (after the
Nextcloud password, as for a move here) calls the move off: the run stops before its next step, and the account made on the other
server is left there, deactivated, because deleting it takes a code that
server e-mails (`requestAccountDelete`, then `deleteAccount` with the
account's password and that code) — the answer says so with
`target-account-left` and the host. Once the DID moved the move can only be
finished (`move-past-switch`). A move that failed handing the DID over is
asked of the directory before it is called off or started again: once the
DID points at the other server, the move goes on from the activation and
is not called off; while the directory cannot be asked, neither is done
(`plc-unreachable`). A move called off while a step is under way stays
called off, also when that step then fails; one the job takes on to the
handover while it is being called off is not called off
(`move-switching`): the call-off and the job's writes are each written
only while the move is still as the other last saw it.

**Moving away with another tool.** A migration tool driven from outside
(the reference flow, as `goat account migrate` runs it) gets what it needs
from this server to a session allowed to change the identity — an OAuth
grant with `identity:*`, as the reference PDS asks; an app password never
is, nor `transition:generic`, and `createSession` here takes app passwords
only, so a tool that signs in with a password cannot do this:

- `getServiceAuth` with `lxm` `com.atproto.server.createAccount` and the new
  PDS's DID as `aud` (`ServiceAuthGrant`), for `createAccount` there; the
  repository and the blobs are public (`getRepo`, `listBlobs`, `getBlob`);
- `requestPlcOperationSignature` (`PlcSignatureService`): a code e-mailed by
  Nextcloud's mail to the address of the Nextcloud account, kept hashed,
  good once and for fifteen minutes (the reference PDS's lifetime), three
  an hour;
- `signPlcOperation` with that code: the operation the tool proposes (each
  field the current one where it names none), checked against the
  vendored lexicon, signed with this server's rotation key that the DID's
  latest operation names and answered, not sent — the tool has the new PDS
  submit it. The person's recovery key keeps the first place among the
  rotation keys, as in `handOver()`. Ten an hour; refused while an earlier
  operation for the DID is not confirmed.

`deactivateAccount` is not served: after such a move, switch Bluesky off
in Settings (§4.6). The page's own move above needs none of this.

**The other half of the same person.** The start can ask
(`announce`) that once the move is done the account here says where the
Bluesky account went: one public post — "My account alice.example.com now
lives at alice.newpds.example: https://bsky.app/profile/alice.newpds.example"
— or a profile field "Also at" with that address (`MoveAnnouncement`).
Both go through the ordinary post and profile paths, so they are the
person's to edit or delete; the text is a short English template, as the
switch wizard's announcement is. A full profile (four fields) is left as
it is (`profile-fields-full`); the move is done either way.

**When the Fediverse account moves out instead** (`MigrationService::move()`
— the Migration page's button, `occ social:account:move`, and the move a
new Aloha Social server finishes with `move/authorized`), a moved account
posts nothing more here, so its Bluesky identity would be a silent account
on Bluesky for good. The move takes `bluesky` (`BlueskyAfterMoveOut`):
`deactivate`, the default, announces the account inactive on the firehose
and marks the identity deactivated (`IdentityService::deactivate()`, as
switching off in Settings does — it can be switched on again); `keep`
leaves it; `move` is taken only while or after a Bluesky move away runs,
else the Fediverse move is refused before anything is sent
(`bluesky-move-first`).

The Fediverse account is untouched; after the move the local actor has no
Bluesky identity until §4.1 creates a fresh one — which it does not do
automatically for a moved-away account (flag on the identity row), so the
person is not silently given a second Bluesky account.

### 13.3 Bridgy Fed twins (D18)

An account that was bridged to Bluesky by Bridgy Fed has a Bluesky
identity `alice.social.example.com.ap.brid.gy` with its own `did:plc` and
followers. Folding it in is the move-in of §13.1 with Bridgy as the old
PDS — Bridgy Fed supports account migration out — so the followers come
along; the page offers it when the handle resolves to a `*.ap.brid.gy`
DID, and tells the person to turn the bridge off afterwards.

### 13.4 Following the same people, without moving

Between nothing and a whole move: `POST /api/v1/social/bluesky/follow-from`
with a Bluesky handle or DID follows from the person's account here everyone
that account follows (`FollowFrom`). Follow records are public, so no
password is asked: the DID is resolved (the AppView's `resolveHandle`), its
PDS read from the PLC directory and asked through `PdsClient` with its
refusal of local addresses, and its `app.bsky.graph.follow` records are read
with `com.atproto.repo.listRecords`, at most 5000. The PDS is the person's
to name, so the reading also ends at a cursor it named before, at a page
that adds no account, and after 100 pages. Each account followed
there is resolved (`BlueskyActorService`) and followed from here through
`FollowService::followActor()` — one already followed is skipped — in the
background as an import of kind `bluesky_follows`, its progress on the
imports list like the CSV imports. The Bluesky account stays where it is,
and its followers do not come along: only a move (§13.1) brings them.

### 13.5 Move error codes

Every error answer of the move routes under `/api/v1/social/bluesky/*`,
and of the account's own Bluesky routes there — identity, recovery
phrase, custom handle (§4.2), app passwords (§6.3), `control` (§4.5b),
`archive` (§4.5a) — and of the recovery tool
(§4.4), carries a stable code beside the English sentence,
`{"error": "…", "code": "<slug>"}`, and a move that stopped keeps the same
code in its progress (`progress.code`) next to `error`. The Migration page
explains the code in the person's language, with what they can do next
(`src/utils/serverError.js`), and a code it does not know with a general
sentence; the English sentence is for logs and older clients, and the page
keeps it behind *Details* for whoever helps the person.

| Code | When | What the person can do |
|---|---|---|
| `bluesky-off` | Bluesky is not enabled on this server | Nothing here; ask the administrator |
| `no-identity` | The account has no Bluesky identity here (also `control` and `archive`, for one that moved away or was deleted) | Nothing to move or download |
| `identity-inactive` | The Bluesky account is switched off or already moved | Switch it on first |
| `move-in-progress` | Another move of the account is under way | Wait for it, or call it off |
| `pds-address-invalid` | The server typed is not an https address | Check the address |
| `pds-unreachable` | The other server did not answer, or its address is one this server does not send requests to (not told apart, and the network error is only logged, so the answer does not say which hosts resolve to local addresses) | Check the address, or try again later |
| `pds-unknown` | The other server does not say which DID it has (`describeServer`) | It is not a Bluesky server; check the address |
| `pds-refused` | The other server answered a step with an error | Try again; the details say which step |
| `pds-session-expired` | The session on the other server ended and could not be renewed | Try again |
| `handle-not-allowed` | The handle does not end in one the other server gives out | Pick a handle under one of its domains |
| `handle-taken` | The other server has that handle already | Pick another handle |
| `invite-code-required` | The other server needs an invite code | Ask that server for one |
| `invite-code-invalid` | The other server refused the invite code | Check the code |
| `password-not-accepted` | The other server refused the password | Pick a longer one |
| `rate-limited` | The other server asks to wait, or this server sent it too many sign-ins of late (§13.1) | Try again later |
| `account-not-created` | The other server did not make the account, for another reason | The details say why |
| `keys-sealed` | A key of this server cannot be opened (the instance secret changed): 503 on any route that needs one; an XRPC call answers 503 `KeysSealed` (§16.3) | Ask the administrator to run `occ social:atproto:keys:reseal` |
| `directory-unreachable` | `control`: the PLC directory did not answer (503) | Try again later |
| `tool-missing` | The recovery tool's script is not built on this server (503) | Ask the administrator; a release has it |
| `plc-unreachable` | The PLC directory did not answer | Try again later |
| `plc-refused` | The PLC directory refused the operation | The details say why |
| `recovery-key-missing` | No recovery key, and the start did not say `without_recovery` | Make a recovery phrase first, or confirm that only the new server will control the account |
| `no-move-to-call-off` | "Call it off" with no move under way | Nothing to do |
| `move-switching` | "Call it off" while the DID is being pointed at the other server | Wait a moment; the move then goes on or stops |
| `move-past-switch` | "Call it off" after the DID points at the other server, or, moving here, once the PLC operation may have been sent | Use "Try again" to finish the move |
| `target-account-left` | Not an error: a move was called off, and the account made on the other server is still there, deactivated | Delete it at that server (the answer names its host) |
| `blobs-missing` | Before the DID moved, pictures or videos did not arrive — on the other server, or here when moving here (`progress.missing`); the move waits in `needs_decision` | Copy again, or switch without them |
| `repo-incomplete` | Before the DID moved, the repository on the other server is not the one here; the move waits in `needs_decision` | Copy again |
| `no-decision-pending` | "Continue" with no move waiting for a decision | Nothing to do |
| `announce-invalid` | `announce` is not `post`, `profile` or `none` | Pick one of them |
| `profile-fields-full` | After the move, the profile had no free field for the new address (`progress.announce_code`) | Add the address to the profile by hand |
| `announce-failed` | After the move, the post or the profile field could not be written (`progress.announce_code`) | Post the new address by hand |
| `bluesky-move-first` | A Fediverse move-out asked `bluesky: move` with no Bluesky move away done or under way | Move the Bluesky account first, or choose `deactivate` or `keep` |
| `bluesky-choice-invalid` | A Fediverse move-out's `bluesky` is not `move`, `deactivate` or `keep` | Pick one of them |
| `handle-invalid` | What was typed is not a handle or a DID | Check the handle |
| `handle-not-found` | No Bluesky account goes by the handle | Check the handle |
| `did-not-supported` | The account's DID is not a `did:plc` | Nothing to do from here |
| `did-not-found` | The PLC directory does not know the DID, or knows no server for it; for `control`, a DID the directory does not know yet | Check the account |
| `import-in-progress` | The same people are being followed already | Wait for it on the imports list |
| `no-failed-move` | "Try again" with no stopped move | Nothing to do |
| `move-refused` | A move here could not be started again | The details say why |
| `account-here-already` | The Bluesky account lives on this server already | Nothing to move |
| `pds-not-named` | The DID names no server | Nothing can be moved from it |
| `pds-blocked` | The old server is on this instance's block list | Ask the administrator |
| `wrong-password` | The old server did not take the password | Type the account's own password, not an app password |
| `auth-factor-required` | The old server e-mailed a sign-in code | Enter that code and start again |
| `sign-in-code-invalid` / `sign-in-code-expired` | The sign-in code was wrong or old | Start again for a new one |
| `sign-in-refused` | The old server refused the sign-in for another reason | The sentence names it |
| `no-move-waiting` | A code was sent, but no move waits for one | Reload the page |
| `code-invalid` / `code-expired` | The e-mailed code was wrong or old | *Try again* asks for a new one |
| `repo-unavailable` | The old server did not hand out the repository | Try again later |
| `repo-too-large` | The repository is larger than the 64 MB this server takes | Ask the administrator |
| `blob-too-large` | A blob is larger than the largest upload this server takes; it is not copied and counts among the missing (`blobs-missing`) | Go on without it |
| `repo-invalid` | The repository is not the account's, or not signed with its key | Ask the old server's operator |
| `operation-mismatch` | The old server signed another operation than was asked | Try again; report it if it repeats |
| `nothing-to-republish` | Publishing again with no finished move that took posts off Bluesky | — |
| `republish-queued` | Publishing again a second time | Wait; it runs in the background |
| `move-replaced` | An invitation was replaced by a newer one | — |
| `invite-expired` / `move-expired` | An invitation, or a move driven by a tool, ran out of time | Invite again |
| `bridgy-unreachable` | Bridgy Fed could not be sent the request | Try again later |
| `handle-domain-invalid` | A custom handle that is not a domain name a handle can be | Check the domain |
| `handle-reserved` | A custom handle under this server's handle host | Use a domain of your own |
| `handle-in-use` | A custom handle another account here has | Use another domain |
| `handle-not-verified` | Neither the `_atproto` TXT record nor the `.well-known` file of the domain names the DID yet | Add the record, wait for it to spread, and try again |
| `app-password-name-invalid` | An app password's name is empty or longer than 64 characters | Give it a name |
| `app-password-name-taken` | The account has an app password of that name | Pick another name |
| `app-password-limit` | The account has 25 app passwords | Revoke one first |
| `move-interrupted` | The move's background job stopped twice without finishing (an hour without progress each time) | *Try again*, or call it off while the DID has not moved |
| `move-failed` | A step stopped for a reason without its own code | The details say why |
| `server-error` | Something unexpected on this server; the sentence is a fixed one, the details are in the log | Try again; the log has the details |

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
ceiling and the current lag, counts (identities, and of those the ones
their owners switched off, §4.6; records, blobs, watches, events in the
window), the PDS/DID blocklist (§12.4), and the state of
each daemon (last frame served, last event seen, pid, uptime). The same
numbers on the Statistics page's agency view, labelled.

Every 15 minutes, a background health check refreshes externally observable
checks and caches them for the admin page: the PLC directory must resolve an
active local DID to this PDS, each configured relay must report the sampled
active DID repository, and the configured public AppView must resolve an
active profile and the newest locally stored post. Relay crawling and AppView
indexing are asynchronous, so a missing profile or post is shown as a warning
with diagnostic guidance; it does not block enabling the PDS. With no active
identity or no local post, the corresponding check says it could not verify
that capability instead of reporting success. These checks establish
reachability and visibility for the sampled identity and post, not universal
indexing by every Bluesky relay or AppView.

When a relay reports `HostNotFound`, the admin check distinguishes a
non-public PDS address (private/reserved IP or local/reserved hostname) from a
public host the relay has not crawled yet. The former explains that public DNS,
HTTPS and an externally reachable web server are required before a crawl can
work; it does not recommend retrying `requestCrawl` against an unreachable
LAN address.

The admin health check sends an RFC 6455 upgrade request to the public
`subscribeRepos` route. It validates the `101` response, `Upgrade`, `Connection`
and `Sec-WebSocket-Accept` headers, using the same public-address guard and DNS
pinning as other outbound requests. An HTTP `426` is an error that blocks
enabling the PDS: it proves the route answers, but not that the reverse proxy
reached the daemon. Configure the proxy to pass the WebSocket upgrade through.

### 14.3 Commands

`social:atproto:serve` (§7.1), `social:atproto:listen` (§9.4),
`social:atproto:identities` (create missing identities; `--user`; an
identity switched off is listed with its state and left off; `--list`),
`social:atproto:plc` (log and directory view, `--repair`),
`social:atproto:resolve <handle|did>`, `social:atproto:repo <user>`
(head, record counts, `--verify` recomputes the MST and compares),
`social:atproto:block <host|did>` / `--unblock`, `social:atproto:crawl`
(`requestCrawl` now), `social:atproto:rotate-key`,
`social:atproto:keys:reseal` (§16.3). Each documented in OCC-Commands.md
with the usual shape.

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
| `social_verification` | the accounts this instance verified (§12.6): `actor_id(_prim)`, `did` ('' for none), `handle` and `display_name` as the published record names them, `verified_by` (the moderator), `creation`. Unique on the actor |

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
`aud`, signed per request; one that arrives here (`createAccount` of a
move, the video service's `uploadBlob`) is taken once — its `jti` is
recorded for the token's lifetime, a token without one or dated more than
a minute ahead is refused. The AppView proxy forwards only `app.bsky.*`,
strips `Authorization` from the client and adds its own, passes
`atproto-accept-labelers` and `atproto-proxy` through, and limits the body.
A proxied call is counted against the user's rate limit.

### 16.3 Rotation

The instance rotation key is rotated by the administrator (`occ
social:atproto:rotate-key`, admin page): a PLC operation per active or
pending identity (queued, paced, resent by the maintenance job), the old
key kept for the 72-hour window and then deleted.

A change of Nextcloud's `secret` is not a key rotation: no sealed key
opens any more, so nothing can be signed, and the keys are not replaced
— they are the DIDs. The status checks (`AtprotoStatusService`, the
Bluesky card, the setup check) count the keys that do not open, and
`occ social:atproto:keys:reseal` (`KeySealService`) opens each with the
previous secret, read from `OC_OLD_SECRET`, and stores it sealed with the
current one; the instance keys, retired ones included, and the signing
keys of every identity that still signs. A key neither secret opens is
reported and left as it is: without the previous secret, the only way
back is the person's recovery phrase (§4.4): its key signs a PLC
operation that gives the DID a signing key of the person's own and points
it elsewhere — with the recovery tool (§4.4) or goat, as Moving.md's "If
this server goes away" lists.

Until then a person's request that needs such a key — their identity,
the recovery phrase, a move, a Bluesky app's call — is not answered with
a raw error: the account routes answer 503
`{"error": "This server's keys need to be re-sealed by an administrator (occ social:atproto:keys:reseal)", "code": "keys-sealed"}`,
an XRPC call 503 with the error `KeysSealed` and the same message, and a
move that stops on it keeps that message as its error
(`AtprotoKeysSealedException`).

### 16.4 Input

Records from Bluesky are untrusted input. What the AppView, a relay or
Jetstream sends is read field by field — each value taken only when it is
the type expected, ids and DIDs checked by their syntax (`Syntax`), an
identity event's handle not taken at all (the one kept is the handle the
AppView verified) — and not validated against the lexicons: a newer
lexicon than the one vendored here must not make a valid post unreadable.
The lexicon validator checks the records this server writes and the ones
a Bluesky app writes through it (`RepositoryService::write()`). Facets are
clamped to the text's byte length, every URL
goes through the same guarded fetch as ActivityPub documents (no local
addresses, the federation blocklist, size ceilings), blobs are fetched
only from the AppView CDN or the author's PDS endpoint and only as the MIME
types the record declares, and text is escaped on render as every remote
post is. The firehose daemon accepts connections but reads nothing from
them beyond the `cursor` query; a slow consumer is dropped after a bounded
buffer, as the reference relay does to a slow PDS, and a connection past
the ceiling (256 in all, 8 from one address) is answered 503 and closed.

### 16.5 What is published

Only what D8, D16, D22 and D24 allow: public posts, their pictures,
profile fields that are already public on the Fediverse, follows of
Bluesky accounts, likes and reposts of Bluesky-visible posts, the lists a
person made public or a reply rule names (§9.7), who may send the person
direct messages (§10.1), and the verifications this instance issues
(§12.6). Nothing else is ever written to a repository, and `RecordMapper`
is the one place that could — but for those lists, that setting and those
verifications, which `BlueskyLists`, `ChatDeclaration` and
`BlueskyVerifications` write — so one test class (§17) is the whole of
that guarantee.

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
| **2 — Reading Bluesky** | §9 resolution, following, the poller, the storer, deletes; §10 notifications poll and the mapping; §8.6 likes/reposts/follows; §11 badges, profiles, addressing, mention picker; §12.1 labels honoured; §9.4 optional Jetstream | alice reads a followed account in her feed and an unfollowed author's profile across AppView cursor pages, likes a Bluesky post, and sees its like and reply in Activities |
| **3 — Complete interaction and apps** | §8.3 replies and quotes to Bluesky posts both ways, link cards, §12.2 labelers, §12.3 reporting, §12.4 instance blocks, §6.3 app passwords and OAuth for Bluesky clients, threadgates, video (D11) through the video service | the official Bluesky app logs in to Aloha Social as its PDS with a Social app password; an OAuth-capable client uses the consent flow; a thread spans both networks |
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
  told on the firehose); while it stands the repository reads answer
  `RepoTakendown` and `getRepoStatus` and `listRepos` say `takendown`.
  Deletion tombstones the DID; the reads of its repository answer
  `RepoNotFound` and `getRepoStatus` says `deleted`.

### Phase 2 as built

This section records phase 2 at the time it landed. Its references to work
deferred to phase 3 are historical, not a list of current missing features:
deletion checks, Jetstream, thread resolution and moderation are described
in their current sections above and in phase 3 below.

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
- **Deletes from Bluesky** (§9.5) are noticed in two ways: the maintenance
  job checks a page of 25 stored Bluesky posts from the last seven days
  against the AppView each run, and the optional Jetstream daemon deletes a
  watched post as soon as it receives its delete event. The periodic sweep
  remains useful when Jetstream is not configured or misses an event.
- **No quote notification**: Social has none (the spec was wrong there); a
  quote of a local post is stored as the post it is in and shows in the
  feed of anybody who follows the quoting account.
- **Search** (§9.1) asks the typeahead only for text that is the start of a
  handle — a dot in it, no `@` — so a plain username never leaves the
  instance. Each account it finds is the cached one, or the account as
  the AppView described it: the typeahead stores nothing, since it is
  asked once per keystroke, and its answer to a query is reused for a
  minute. A Mastodon client's search with `resolve` stores the accounts
  it is answered with, at most eight, because an account without a
  stored row has no id a client can open or follow it by; without
  `resolve` the typeahead is not asked. The composer's mention picker and
  the directory offer unstored accounts; one is stored when it is
  mentioned, opened or followed.
- **The admin's federation block list applies to a handle's domain** (the
  part after the first dot) in the directory; the per-DID/PDS-host block
  list of §12.4 is phase 3, as are labelers (§12.2) and reporting (§12.3).
  Of §12.1, posts and accounts labelled `!hide`/`!takedown` are not stored
  or read, the adult labels make the pictures sensitive with a warning
  naming the label, and other service labels become a warning; labelers'
  own definitions are not read yet.
- **Per-user opt-out** (then open question 1, since decided as D23 and
  completed 10-09, §4.6): Settings → Your account has a switch; off, the
  account is announced inactive on the firehose and by `getRepoStatus`,
  nothing more is published for it and its notifications are not read; the
  DID and repository stay.
- Likes and reposts are written by the queued publish job, like posts, so
  the request that made them never waits for a repository commit; a like
  of a Fediverse-only post writes nothing.
- The authenticated AppView (`atproto_appview_auth`, default
  `https://api.bsky.app`) and its DID (`atproto_appview_did`, default
  `did:web:api.bsky.app`) are configuration of their own beside the public
  one, because the public AppView refuses authenticated requests. Service
  authentication addresses the AppView as
  `did:web:api.bsky.app#bsky_appview` (and chat as
  `did:web:api.bsky.chat#bsky_chat`); the interop job points both at the dev
  AppView.

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
  (`viewDetached`). A quote written here of a Bluesky post, which its
  author detached, is noticed by the maintenance job: Bluesky says it
  only in the author's postgate, so the local posts quoting a Bluesky post
  in the last month are checked ten per run (`DetachedQuoteSweep`,
  `Postgates::detached()`), and a detached one reads as revoked here — as
  a quote taken back on the fediverse with a `Reject` does.
- **Link cards**: the post's link preview as `app.bsky.embed.external`
  when it has a title and the post has no pictures and quotes nothing —
  **without a thumbnail**: the preview's picture is a remote URL, and a
  blob here is a stored document; storing card pictures idempotently
  across reconcile passes is left for later. A card that appears after the
  post was published is picked up by the reconcile pass as an edit would be.
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

**3c as built** — `Atproto\Client\` (`AppPasswordService`, `SessionService`,
`AppViewProxy`, `Preferences`, `WriteService`, `ClientXrpc`), tables
`social_atproto_app_password` and `social_atproto_session`:

- **App passwords are this app's, not Nextcloud's.** §6.3 said a Nextcloud
  app password; that would hand a Bluesky app the whole of Nextcloud —
  files, WebDAV — and can only be checked through the server's private API.
  Settings → Apps and account → Bluesky makes Bluesky-style app passwords instead (four
  groups of four, shown once, stored as a password hash, at most 25),
  good for this app's Bluesky surface only. Revoking one ends every session
  it opened. The same records are managed through the PDS XRPC methods
  `createAppPassword`, `listAppPasswords` and `revokeAppPassword`; passwords
  are scoped to the authenticated Nextcloud account. A wrong password counts against the caller's address in
  Nextcloud's brute-force protection, and the answer for an unknown account
  and a wrong password is the same.
- **Sessions** are JWTs this server signs with its service key: an access
  token of two hours naming its session, a refresh token of ninety days
  whose id is the session (`createSession`, `refreshSession`,
  `getSession`, `deleteSession`). Refresh tokens are consumed with one
  atomic delete, so concurrent refreshes or a replay can issue only one
  replacement session. Every access token is also checked against the app
  password that created it, so revoking that password invalidates tokens
  even when revocation races a refresh. A suspended account's apps are
  turned away; an account that moved away cannot sign in.
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
  within the `app.bsky` namespace and 256 KB. **Bookmarks** are the
  person's bookmarks here (`Publisher\BlueskyBookmarks`): the app's
  `createBookmark`/`deleteBookmark` go to the AppView, which keeps the
  bookmark button's state, and once it took one it is set or taken away
  here; `getBookmarks` is answered from the bookmarks here that are on
  Bluesky, each as the AppView shows the post, so older bookmarks are in
  it; and a bookmark set here of a post on Bluesky is told to the AppView.
  Post views are fetched in groups of at most 25, as `getPosts` requires,
  including a bookmark page of 50 or 100. The bookmark order and cursor
  stay local; the cursor is the oldest decimal NID kept without converting
  it to a native PHP integer. If one group fails, the others still load.
  **Notification settings** (`app.bsky.notification.getPreferences`,
  `putPreferencesV2`) share the one setting both have
  (`Client\NotificationSettings`): whether anybody or only the people
  followed may notify — the policy here for people not followed (`accept`
  is "all", `filter` or `drop` is "follows") and `include` on each kind of
  Bluesky notification that has one. The app reads it from the policy;
  what the app saves goes to the AppView, which sends the app's push
  notifications by it, and becomes the policy when it changed; a policy
  changed here is told to the AppView. Bluesky's per-kind `list` and
  `push` switches have nothing here to be and stay the AppView's.
  The preferences are kept — except the **muted words**
  (`mutedWordsPref`), which are the person's filters (`Client\MutedWords`):
  the app reads every keyword of the filters that apply, a hashtag-only
  word as `#tag`; a word it takes away removes its keyword, one it changes
  rewrites it, and one it adds goes into a filter of its own, "Muted
  words from Bluesky", which hides what it matches everywhere (one per
  expiry, when the word has one).
  `putPreferences` replaces the preference set: if `mutedWordsPref` is absent,
  the app's mirrored muted words are cleared from the local filter projection.
  Before replacing anything, the app validates muted-word items and their
  targets, actor scope, ids and expiry types; malformed values receive
  `InvalidRequest` without changing the saved preferences or local filters.
- **Writes are Social actions** (§16.5): `createRecord` of a post is a
  Social post (public; reply, quote, pictures, language; a link the app
  shortened is its whole address again), a like a like, a repost a boost,
  a follow a follow — the record the publisher writes for it is the answer,
  so the app sees what Social published, which may differ (a long post cut
  with a link). `putRecord` of the profile sets the display name and bio; of an existing
  post it replaces the text, language, media and self-labels under the same
  record key, so its AT URI stays stable. Supported quoted-post and non-post
  record embeds can be replaced or removed; changing a reply target or an
  embed Social cannot represent without loss is refused.
  `putRecord` of `chat.bsky.actor.declaration` (as `createRecord` of it) sets who may
  send direct messages here, which writes the record (§10.1).
  `deleteRecord` undoes the action. `applyWrites` keeps a batch of
  repository-native records (lists and their members, starter packs, feed
  generators, thread gates and post gates) in one repository commit, and
  rejects an invalid batch before writing any of it. For these batches,
  `swapCommit` is compared with the locked repository head inside the write
  transaction; a stale CID returns `InvalidSwap` without changing the repo.
  Batches that mix Social actions and repository-native records run inside one
  database transaction and produce one repository commit. ActivityPub
  deliveries start only after the transaction commits; a failed action rolls
  back the database work and discards deferred delivery starts. `swapCommit`
  is checked against the locked head for mixed batches too. Avatar and media
  files use Nextcloud storage and are outside the database rollback. Those
  repository-native record
  types have no Social counterpart and are kept as the app wrote them,
  validated against their lexicon; a gate only for one of the account's own
  posts, under that post's key. A block is a block here, published, when the
  person publishes their blocks, and refused while they do not; a list block
  an app writes is refused (D16): lists are subscribed to from Settings →
  Blocking (§12.5). `uploadBlob` stores a picture as any upload is, named by
  its CID,
  for the post that uses it. A report an app files is a report here, passed
  on in this server's name (3b).
- The app's profile editor sets the avatar and the banner too: a picture
  the app uploaded becomes the account's — the avatar is the Nextcloud
  account's own picture, refused where its backend owns it — and one left
  out is taken away. An app sends the whole profile every time, so a
  picture it did not change is left alone. The pronouns and the website
  it sets are the account's profile rows named for them, rewritten, added
  while there is room among the four, or taken away
  (`Person::withProfileRows()`); unchanged, they are left alone. Pronouns
  are clipped to the profile lexicon's 20 graphemes and 200 bytes before
  those shared rows change, so both protocol profiles keep the same value.
  A non-empty website must be a valid HTTP or HTTPS URL; an invalid value is
  refused before any profile field or picture changes.
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

- **OAuth-capable Bluesky clients can sign in through AT Protocol's OAuth profile**: pushed authorization
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
  signed in is refused. A browser without a Nextcloud session is sent to
  Nextcloud login and returned to the same authorization URL, including the
  pushed request, before consent is displayed.
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
  another), `blob:` uploads of a type (matched against what the bytes
  are, not the `Content-Type` the app sent), `account:email`, `identity:`,
  and `include:` permission sets. A set is resolved as Lexicon resolution
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
  keys and endpoint) and, once the directory took it, the firehose sends
  `#identity`, so relays and AppViews resolve the handle again. The assigned `alice.<host>` keeps
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
  So Migration → *Move your Bluesky account away* does what the
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
  published; no new identity is made for the account. The repository reads
  here answer `RepoDeactivated`, and `getRepoStatus` and `listRepos` say
  `deactivated`, as a Bluesky PDS does for an account that moved off it.
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

- **Driven from here, as moving away is.** Migration → *Bring
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
  along.
- **Then the old PDS e-mails a code** (`requestPlcOperationSignature`); the
  move waits. With the code it signs the operation that names this PDS,
  the rotation keys of §13.1 — the person's own first, this server's last —
  and the account's handle here — checked
  before it is taken — the directory takes it, and the account **takes the
  DID**: the account's identity row keeps its handle (and custom handle)
  and gets the moved DID and its new key, and the DID this server had made
  for the account is **retired**, tombstoned with its repository. The page
  says so before the move starts. Then `deactivateAccount` on the old PDS.
- **Then the follows** become follows here, each tied to the record it came
  with, so nothing is written twice — only once the DID is the account's,
  as in a move driven by a tool, so a move that never got that far leaves
  no follows behind. **Then the posts** become posts in the timeline here (4e). A wrong code
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
- **Invited first.** Migration → *Bring your bridged Bluesky
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
  leaves an imported post alone and does not publish an archive import dated
  in the last day as new, which it did before.
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

1. **Who pays for pictures.** A re-encoded picture is a second stored file
   per picture per post. Count it against the user's quota (it is their
   post) or the app's? Recommended: the user's, like the original.
2. **Relay choice.** `bsky.network` only, or also announce to independent
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
- Commit `version: 3`, `prev` always present and written null (a CID link
  is allowed and read), 64-byte low-S secp256k1 signature;
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
- Relays limit hosts, not only accounts (checked 2026-10-09 against
  `bsky.network`): `com.atproto.sync.getHostStatus?hostname=` answers
  `status` (`active`, `idle`, `offline`, `throttled`, `banned`) and
  `accountCount`, or `HostNotFound` for a host it never crawled. A new host
  is limited in accounts and events and throttled past that; the limit is
  raised by the relay's operator. The admin card's `relay_host` check and
  the live probe read it. The size of the default limit: *verify*.
- Push to a Bluesky app signed in here (`app.bsky.notification.registerPush`)
  and age assurance (`app.bsky.ageassurance.*`) are AppView calls passed on
  by the proxy (§16.2), untested against the real AppView: *verify* with
  §3.9 of docs/Atproto-Live-Test.md.
- Packagist: `spomky-labs/cbor-php` 3.4.2, `paragonie/ecc` 2.6.0 (gmp),
  `ratchet/rfc6455` 0.4.1, `react/socket` 1.17.0 — usable on PHP 8.3.
  `aazsamir/libphpsky` and `karanshukla/php-atproto-identity` need PHP ≥ 8.4
  (floor is 8.3); `socialweb/atproto` is a 2023 alpha — not used. Verify
  each against Packagist before adding to `composer.json`.
- Interop vectors: github.com/bluesky-social/atproto-interop-tests.
