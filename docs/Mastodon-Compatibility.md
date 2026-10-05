# Mastodon compatibility

How close this app is to Mastodon, in the three senses that phrase can have:
whether Mastodon's clients work against it, whether other fediverse servers can
tell the difference, and whether an existing Mastodon instance could move onto
it. Written for whoever has to decide what to build next.

**Verified against:** app version 0.19.95, `master`, 2026-09-15 — every status
re-checked against the code, and the client-facing claims re-checked against a
running instance rather than against the unit tests — and 0.26.60, 2026-09-25,
against the code only, for the domain-root sections (§1, §2, §3.1, §9 item 1
and *Where to start*), the post import (§5.3, item 28) and the list of what
Mastodon has no equivalent for; the rest has not been re-checked since.

**What changed in this revision**, for a reader who knew the document before:
a walk of Mastodon's documented client routes found 23 genuinely unserved (the
raw diff said 45; 22 of those were false positives — `statuses/{nid}/{act}`,
`timelines/{timeline}` and the `accounts/{id}` route in `appinfo/routes.php`
are catch-alls a path-by-path comparison cannot see). Of the 23, **everything
that is not Web Push, streaming or a sign-up this app does not own is now
served**: translation, grouped notifications, the notification policy and its
requests inbox, per-status filters, `profile/avatar|header`,
`suggestions/{id}`, `instance/privacy_policy|terms_of_service|translation_languages`
and `oembed`. `translate` no longer returns the post unchanged, and
`showing_reblogs` is no longer hardcoded. The web client gained the three
things a Mastodon user meets on day one and could not do here: filters,
delete-and-redraft, and the per-account bell and hide-boosts switches.

This document supersedes the parity reviews written against 0.11.51 and 0.11.63,
and the "what can be improved" assessment written against 0.11.49: every item in
those was checked, and what survives is here, in
[Performance.md](Performance.md) or in [Technical-Debt.md](Technical-Debt.md).
The rest is fixed.

Like those two, nothing in `tests/DocumentationTest.php` checks the *claims*
here, so they go stale silently. Re-check rather than trust after the next wave
of work.

---

## 1. The answer in one paragraph

Aloha Social 0.19.95 is a capable, standards-correct ActivityPub server with a broad
and largely genuine Mastodon client API. A walk of Mastodon's 145 documented
client routes against the route table found 23 genuinely unserved — the raw
path diff said 45, and 22 of those were catch-alls a textual comparison cannot
see. Of the 23, **three** are left: `push/subscription` and `streaming`, the
two known weeks-long items, both announced as absent so a client stops asking
rather than hanging, and `emails/confirmations`, which belongs to a sign-up
this app does not own. Everything else exists, and §3.3 says which of them is a
stub.

It is **not** a drop-in replacement for Mastodon, and two things stood between
it and that goal. One was small and mechanical: the API is not served at the
domain root by the app itself, and the web-server rules that put it there now
ship with the app (§3.1). (The other of that pair — an OAuth app row holding
exactly one token — is fixed: authorizations are their own table, so two people
can use the same client.) The second is architectural: **an actor's identity is
recomputed from configuration on every read rather than stored**, and every URI
the app mints lives under `/apps/social/`. That single decision is what makes
taking over an existing Mastodon domain impossible rather than merely
unimplemented.

The good news is that nothing here is impossible in principle. The storage layer
would accept Mastodon-shaped ids and imported keys today; there is simply no code
path that writes them.

---

## 2. What "drop-in" has to mean

The phrase hides three different tests, with three different difficulties. A
report that does not separate them will either sound alarming or sound smug.

| Test | Question | Verdict |
|---|---|---|
| **The client test** | Do existing Mastodon apps work against it, unmodified? | **Only once the shipped web-server rules are installed** (§3.1), and then without push or streaming (§3.3); without the rules, no |
| **The peer test** | Would other fediverse servers notice the difference? | **Almost no** — federation quality is genuinely good, with one live defect (§4) |
| **The takeover test** | Can an existing Mastodon instance move onto it, same domain, same users, without the network noticing? | **No** — and this is weeks to months of work |

Most of the value is in the first test. Most of the difficulty is in the third.

---

## 3. The client test

### 3.1 The API at the domain root

Every route is registered under the app prefix (a `#[FrontpageRoute]` on the
controller method), served at
`https://host/index.php/apps/social/api/v1/...`. The only things the app
registers at the server root are the WebFinger, NodeInfo and host-meta well-known
handlers (`AppInfo\Application`).

Ivory, Tusky, Mona, Elk, Ice Cubes and Phanpy all build request URLs as
`https://<domain>/api/v1/...` from the domain the user types. The Mastodon client
protocol has **no mechanism for a non-root API base**, so out of the box none of
them can reach any endpoint.

The way in is a web-server change, not an app setting: the app ships the rules
that map `/api` and `/oauth` at the domain root onto itself, for Apache and
nginx, in `contrib/webserver/`, with the explanation in
[Admin.md](Admin.md#mastodon-apps-cannot-connect). The `ClientApiAtRoot` setup
check (and `ProxyForwardsTheScheme` beside it) says in Administration →
Overview whether they are in place, and an administrator opening the app sees
the same warning with the rules to paste until they are. Where they are not,
nothing else in this section is reachable by a stock client.

### 3.2 Fixed — one access token per registered app

`social_client` used to hold a single `token`, `auth_user_id`, `auth_account`
and `auth_scopes` per row, and the whole OAuth flow keyed on `client_id`.
`authClient()` blanked the token on every authorization — with a comment
explaining that leaving it would let the previous user's token act as the new
one — so a second authorization against the same `client_id` silently revoked
the first. Elk and Phanpy register one app per instance and serve several
users from it, so user B signing in logged user A out, and a single user adding
the same account twice did the same thing.

Mastodon's model is one application, many tokens, and that is now the model
here: `social_client_auth` holds one authorization per (app, account) — the
code, the token, the scopes granted and the account they were granted to. The
app registration stays where it was, and every read joins it, so the rest of
the app still sees one `SocialClient` carrying both halves.

Three things went with it. Revoking a token took the app row's only token, so
revoking on one device signed out everybody who had authorized that client; it
now takes one authorization. The expiry sweep deleted the whole `social_client`
row, so an idle token took the app's registration with it and the client had to
register again; it now deletes authorizations. And a code is spent in the same
statement that writes the token, so two requests arriving together cannot both
exchange it.

### 3.3 The endpoint surface is now broad and mostly real

I opened the controller method behind each route rather than trusting the route
table. **One true stub remains in the entire Mastodon surface:**

| Endpoint | State | Evidence |
|---|---|---|
| `/api/saved_searches/list.json` | initialises the viewer, returns `[]` | `ApiController::savedSearches()` |

It is also not Mastodon's — it is a Twitter route an early client brought with
it, which is why §9 files it as a decision rather than a task.

Everything else that exists as a route does real work, including subsystems the
2026-09-11 review listed as absent: lists, v2 filters (applied server-side),
conversations, markers, edit history, trends for tags and statuses and links,
suggestions, the directory, featured tags, endorsements, per-user domain blocks,
announcements with an admin UI, account notes, scheduled statuses with a cron,
and a real admin API. Since that was written, so do conversation mute, the
three standalone `instance` sub-routes, `/api/v1/timelines/link` and
`/api/v1/statuses/{id}/card`, and the four notification types §6 used to list.

### 3.4 Genuinely missing endpoints

Three, and only three:

- **Web Push (`/api/v1/push/*`)** — absent. `lib/Service/PushService.php` is
  unrelated; it pokes the `notify_push` app so the *web* client refreshes.
  Third-party mobile apps get no push from this server.
- **Streaming** — absent, and deliberately so. `InstanceService` returns an empty
  `urls` object so clients fall back to polling immediately rather than after a
  timeout.
- **`/api/v1/emails/confirmations`** — part of a sign-up this app does not own;
  see §9 item 16. `/api/v1/admin/canonical_email_blocks` is absent for the same
  reason, though it belongs to the admin API rather than to this count.

What used to be on this list and is not any more: `POST /statuses/{id}/translate`
(a real translation through Nextcloud's own provider, answering a Translation
entity, with `instance/translation_languages` beside it and a 503 where the
server has no provider); the Mastodon 4.3 notification routes
(`/api/v2/notifications` and its four, `/api/v1/notifications/policy`, and
`/api/v1/notifications/requests` with its accept and dismiss, single and bulk);
the status half of v2 filters (`/api/v2/filters/{id}/statuses` and
`/api/v2/filters/statuses/{id}`); `DELETE /api/v1/profile/avatar` and `/header`;
`DELETE /api/v1/suggestions/{id}`; `/api/v1/instance/privacy_policy` and
`/terms_of_service`, from Nextcloud's own Theming settings; and `/api/oembed`,
answered as `type: link` because there is no embed page here and framing the
whole app would publish something nobody meant to.

Everything else that was on this list is answered now. Conversation mute is
handled (`ActionService::muteConversation()`); `/api/v1/timelines/link`,
`/api/v1/statuses/{id}/card` and the three standalone `instance` sub-routes are
registered; `/api/v1/preferences`, `familiar_followers`, `instance/peers`,
`instance/activity` and the v1 filter routes arrived with #2134;
`/api/v1/custom_emojis` answers with the instance's own emoji rather than `[]`;
and `/api/v1/accounts/search`, `favourited_by` and `reblogged_by` came with
#2126 — the first is what a composer calls to complete a `@handle` and no
client substitutes `/api/v2/search` for it, and the other two make a tap on a
favourite or boost count something other than a dead end. Both reaction lists
resolve the status through the visibility filter first: who liked a post is as
private as the post.

### 3.5 The version string

`Instance::COMPAT_VERSION = '4.3.0'`. It said `3.5.0` until #2126 and `4.2.0` until
the 4.3 surface was whole, which is what it announces now — with `api_versions`
(`{"mastodon": 3}`) beside it, which is what a 4.3 client reads *instead of*
parsing a string that, on a fork, says nothing about which Mastodon API is
implemented. This was
right when it was written and had stopped being: clients gate features on this
string, so they were hiding edit and history, calling the v1 filter routes that
404 instead of v2, and never asking for `/api/v2/instance` or
`/notifications/unread_count` — all of which are implemented.

The 4.x features still missing are announced rather than left to fail. `urls`
is an empty object, which is how a client learns there is no streaming
endpoint, and `vapid_key` is an empty string, so a client decides against
offering Web Push before it asks for it — which is what it does against any
server with no VAPID key. `configuration.translation.enabled` is no longer
always `false`: it is `true` where this Nextcloud has a translation provider,
and the languages that provider offers are at
`/api/v1/instance/translation_languages`.

### 3.6 Entity shapes

Good overall. Two of the four issues this section used to list are fixed in
#2126:

- **`source` was emitted on every Account**, including other people's and to
  anonymous callers, leaking `source.follow_requests_count` — how many people
  are waiting on an account's approval. It is now built by the two credentials
  routes, which are the two that know they are answering the account itself, and
  the model no longer has it to leak.
- **`poll` was absent rather than `null`** on non-poll statuses, against the
  app's own rule that a client should never have to test for a missing key.

`POST /api/v1/apps` was the third: it omitted `redirect_uri`, which Mastodon's
Application entity always carries. It now answers with the first registered URI
and an empty `vapid_key`, because there is no Web Push here to have a key for.

Two remain:

- An Account can still carry `"avatar": ""` — verified against a running
  instance, where one account out of a search page came back with an empty
  string — because `Person::exportAsLocal()` falls through to `getAvatar()`
  when the actor has no cached icon, and that is the stored value, which may be
  empty. Mastodon declares the field a URL, and a client that decodes it as one
  fails on the whole account.
One deliberate extension to note: a `Status` carries `delivery: "held"` on
the author's own copy while its deliveries wait for a video to be converted
(§ *Held for a video* in Architecture.md), and the key is absent otherwise. A
Mastodon client ignores an unknown key; the app's own web client draws the
"still converting" hint from it rather than guessing from the attachment type.

Another: every `Status` and every `MediaAttachment` carries `ai_generated`
(bool, always present) — whether the post *says* it was made with AI, by one
of a set of hashtags (`#aigenerated`, `#midjourney`, … plus the administrator's
`ai_tags`) or by a picture whose metadata stated IPTC `DigitalSourceType`
provenance when it was stored. A label the post carries, never a judgement
about its content. The reader's switch for it is `GET`/`PATCH
/api/v1/social/ai_content` under the filter scopes, echoed by
`/api/v1/preferences` as `reading:hide:ai`; turned on, such posts are dropped
server-side in `FilterService::apply()` — every timeline, thread, profile and
notification page, lists included — so a Mastodon client that knows nothing
of the switch still shows what the reader chose. One that reads the key can
draw a quiet mark for readers who left the switch off.

A second extension lives beside the notifications: `GET`/`PATCH
/api/v1/social/notification_delivery`, and the `notifications:delivery` key
`/api/v1/preferences` echoes. Mastodon has nothing to compare it with — its
notifications *are* the list, and a client's push is the client's business —
whereas here the list has a second half, the Nextcloud bell, and this is the
setting for when that half rings: at once, or as a digest at times of the
reader's choosing, with direct messages and mentions from followed accounts
passing through and optional quiet hours. A Mastodon client never sees the
difference: `/api/v1/notifications` and the markers read the stored rows, and
the setting only decides when the bell is told about them.

`showing_reblogs` is no longer among them either: `POST /accounts/{id}/follow`
takes `reblogs` as well as `notify`, a "no" is stored as a row in
`social_actor_relation`, and the home and list timelines drop that account's
boosts as a predicate of the query rather than by filtering a page after it was
read.

### 3.7 Profile editing

Fixed in #2126. `update_credentials` used to accept `header` and
`source[privacy]` and ignore `avatar`, `display_name` and `bot` while returning
200 — and a client's profile editor sends all of them in one PATCH, so somebody
changing their name, picture and bio together got a success and only the bio.

All three are written now. The name and the picture belong to the Nextcloud
account rather than to the actor, so they are written there and the actor cache
is refreshed; `bot` needed a column of its own and sets the actor's *type* with
it, because an account marked automated that went on publishing `Person` would
tell a client and a peer different things. A backend that owns the name or the
picture (LDAP, SAML, anything provisioned elsewhere) makes the request a **422**
rather than a silent success — which is what this section asked for, applied to
the fields that cannot be honoured rather than to the whole request.

Fixed in #2437: clients send this route as a multipart **PATCH** whenever a
picture is in it, and PHP parses a multipart body by itself for a POST only.
The body reached the controller unread, so no client could set an avatar or a
header, and a text-only multipart save changed nothing, all under a 200. The
body is now read with `request_parse_body()` on PHP 8.4 and later and by the
app's own parser on PHP 8.3. A body that cannot be read, and a picture that was
sent and did not arrive, are a **422** with nothing written.

Fixed in #2439: the alt text and focal point of a published post's media are
edited the way Mastodon edits them, with `media_attributes` on
`PUT /api/v1/statuses/:id`. Nothing read that field, and `PUT /api/v1/media/:id`
changed only the upload while the post kept its own copy, so a description
could only be fixed by deleting the post. That route now refuses an upload a
post carries, as Mastodon's does.

---

## 4. The peer test

This is where the app is strongest, and it deserves saying plainly. A remote
server talking to a Aloha Social instance on its own domain would find very little to
complain about.

**Working and correct:** signed delivery and verification including RFC 9421, a
dedicated instance actor with its own key pair for signed GETs
(`lib/Service/InstanceActorService.php`), paged outbox, followers, following and
featured collections, a real `replies` collection, Mastodon-shaped HTML content
whose `u-url mention` and `hashtag` anchors agree with the `tag` array
(`lib/Service/LinkifyService.php`), `contentMap` and language, correct visibility
addressing that fails closed on unknown, `updated` on edits, inbox forwarding per
ActivityPub 7.1.2 with the LD signature preserved (and, receiving one without an LD
signature, the object fetched from its origin rather than refused), per-inbox delivery
deduplication, and a retry window of 16 attempts on Sidekiq's `tries⁴+15`
backoff — about 49 hours, deliberately matching Mastodon.

Two findings from the 0.11.63 review are fixed: outbound content is no longer
escaped plain text, and delivery no longer sends one copy per mentioned user on
the same host. Three more have gone since: `Add` and `Remove` federate a pin,
`Move` is built by `occ social:account:move` with an `alsoKnownAs`
back-reference check, and the WebFinger profile-page link points at a Aloha Social
profile rather than at `/index.php/u/alice`.

**What a peer would still notice — one thing, and it is live today:**

**Attachments are served in Mastodon's client shape rather than as ActivityPub
documents.** Fetch any status of this instance with
`Accept: application/activity+json` and `attachment` comes back as

```json
[{"id": "707", "type": "video", "url": "…/media/02f8b930….mp4",
  "preview_url": "…", "remote_url": null, "meta": {…}, "description": "…"}]
```

where the wire calls for `{"type": "Document", "mediaType": "video/mp4",
"url": …, "name": "…"}`. There is no `mediaType` at all, `type` is the client
entity's half-word, and `description` is not the property a peer reads alt text
from.

The pieces are all present and correctly written — `MediaAttachment::asDocument()`
emits exactly the right object, and `StatusApiController::statusNew()` sets
`ACore::FORMAT_ACTIVITYPUB` on the attachments of a post as it is created, so
the original `Create` that goes out over the wire is right. What is wrong is
everything *after* that: `StreamRequest::save()` deliberately stores
`$item->asLocal()`, hydration leaves the rebuilt objects in the local format,
and `Stream::jsonSerialize()` then hands those objects straight to the
serialiser under `attachment`. So a single status, the outbox, `featured`,
`replies`, an `Update` after an edit, and any re-fetch by a peer resolving a
boost all carry the client shape.

This is the same defect the Pixelfed compatibility review found in September and
was believed fixed. It was not caught because the test that pins it —
`WireCompatibilityTest::testWhatGoesBackToPixelfedStatesItsMediaType()` — calls
`asDocument()` directly rather than serialising a stored status the way the
controller does, so it passes against a wire format nothing produces. It is §9
item 39, it is hours of work, and it is the highest-value item on that list
after the tier-1 blocker.

Authorized fetch inbound is **no longer** among them: a signed GET is verified
and resolved to the account behind it (`AuthorizedFetchService`), so a
followers-only object is served to a remote reader who follows it, and secure
mode — refusing an unsigned ActivityPub GET outright — is available behind the
`secure_mode` app value. Custom `Emoji` tags are emitted and reactions to an
announcement are stored and served; emoji reactions to a *status* are a
Misskey and Pleroma extension that Mastodon itself does not handle, and are
not implemented here either.

---

## 5. The takeover test

This is the part that makes "drop-in replacement" a much larger question than
feature parity, and it is where the honest answer is no.

### 5.1 The URLs do not match, and cannot be made to

Every id is built from `ConfigService::getSocialUrl()`, which is the app's route
root.

| Object | Aloha Social | Mastodon |
|---|---|---|
| actor | `https://host/apps/social/@alice` | `https://host/users/alice` |
| status | `https://host/apps/social/@alice/17578…` | `https://host/users/alice/statuses/<id>` |
| inbox | `https://host/apps/social/@alice/inbox` | `https://host/users/alice/inbox` |
| shared inbox | `https://host/apps/social/inbox` | `https://host/inbox` |
| instance actor | `https://host/apps/social/actor` | `https://host/actor` |

After a domain swap, every remote server still holds the old Mastodon URIs as
primary keys. Actors 404, so peers mark the accounts gone and the follower
relationships die on their side. Status URIs 404, so boosts, favourites, replies
and quotes across the network dangle. Old inbox URIs 404, so queued deliveries
fail permanently. There is no redirect, no `Tombstone` and no `Move`, so nothing
migrates. And the signing key changes while the old `keyId` becomes unresolvable,
so signature verification fails on the far side.

### 5.2 The root cause is that identity is derived, not stored

`ActorsRequestBuilder::parseActorsSelectSql()` **overwrites** the actor's id on
every read:

```php
$actor->setId($root . '@' . $actor->getPreferredUsername());
```

(`ActorsRequestBuilder::parseActorsSelectSql()`, with inbox, outbox, followers,
following, featured and sharedInbox all derived from it on the lines below.) Writing a
Mastodon-shaped id into the column achieves nothing, because hydration discards
it. Status ids are minted the same way in `StreamService::assignItem()`, and the
serving routes **reconstruct** the id from the URL path rather than looking it up
(`ActivityPubController::displayPost()`), so a post stored under a foreign
id has no URL that serves it.

Keys are always freshly generated (`AccountService::createActor()`), and
there is no setter reachable from outside. `SocialMigrator` refuses to carry a
private key deliberately and explains why at length
(`SocialMigrator`). The cipher itself
(`PrivateKeyCipher`) would seal any PEM handed to it, so this
is unimplemented rather than impossible.

### 5.3 What exists today is the lossy Move path

`occ social:account:move` builds a proper `Move` with an `alsoKnownAs`
back-reference check, and the inbound side handles it. `docs/OCC-Commands.md:145`
states the consequence: a move carries the followers, not the archive. And it
requires the old instance to still be running to send the Move, which contradicts
keeping the same domain.

The archive half has moved since this was written, in one direction. An account
can now take its data out and put it back: `SocialMigrator` — driven by
`occ user:export`, by Nextcloud's account migration, and by the Migration page's
two buttons — writes the profile, the follows and followers as CSV, the
bookmarks and favourites as URLs, the account's **own posts as an ActivityPub
`OrderedCollection`**, and **the files of those posts**, under
`media_attachments/` in the layout Mastodon's own archive uses, with each
attachment's `url` rewritten to point into the archive. What comes back on import
is the profile, the follows, the relations, the marks, the banner and the files
of the posts this server still has. That import counts the posts and does not
write them, and the key pair is deliberately never carried. Posts are brought
over separately: **Settings → Migration → Bring your posts with you** and
`occ social:account:import-posts` (`PostImportService`) read another server's
archive and write the account's own posts as new local posts, dated when they
were written, with their pictures, under ids of this server and with nothing
federated.

The follows *file* is read whichever network wrote it: Mastodon's CSV, or
Pixelfed's `pixelfed-following.json` — a JSON array of actor URLs, which the
importer used to read as a CSV with no handle in it and follow nobody.

Follower import is still the other half, and it is still impossible without
identity continuity: the relationship's other half lives on the follower's
server pointing at the old id. With identity continuity it becomes easy,
because nothing has to be federated at all — it is a local row insert.

### 5.4 What would have to be written

In order. The first three are the irreducible core; without them the fediverse
notices on the first signature check.

1. **Make actor identity stored rather than derived.** Stop overwriting `id` on
   read and on create; use the column when set, mint only when empty. Same for
   the derived collection URLs.
2. **Serve the Mastodon URL space and look up by stored id.** Add
   `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`,
   `/followers`, `/following`, and change the serving controllers to resolve the
   requested URI instead of rebuilding it. Document the root rewrites.
3. **Add a key-pair import**, root-only and loudly warned, validating that the
   public key matches the private one.
4. **Add a handle and id rename path.** This is the step most likely to be
   underestimated: the `*_prim` md5 columns mean an id change is a fan-out
   rewrite across roughly a dozen tables.
5. **Write the status importer** — original id, published time, `inReplyTo`,
   conversation, addressing, language — rejecting any id whose host is not ours.
   The export side of this exists (`SocialMigrator` writes the outbox); nothing
   reads it back.
6. **Write the media importer.** Half of it exists: the export carries every
   file, and the import stores one back through the upload path
   (`DocumentService::storeLocalAttachment()`) and hangs it on the post it
   belongs to — but only where this server already has that post, because
   nothing mints the posts. It becomes whole with 5.
7. **Write the follower-graph importer**, inserting rows directly rather than
   calling the follow service, which would re-send a `Follow` to everyone.
8. **Import the remaining per-actor state** with the same write-the-row,
   federate-nothing discipline `SocialMigrator::importRelations()` already models.
9. **Reconcile counters and threading** so like, boost and reply totals survive.
10. **Add a cutover verification command** that fetches our own actor and a
    sample of statuses over HTTPS as a remote server would, and checks that the
    served id matches the stored one, that `publicKey.id` and the PEM agree, and
    that a signature made with the imported key verifies.
11. **Write the operational runbook**: freeze, drain the delivery queue, dump,
    import, flip, keep the old inbox reachable while DNS settles.

---

## 6. Feature gaps a migrating admin would hit on day one

Genuinely absent, in rough order of how much they would be missed:

1. Web Push and streaming — every client polls, and both are announced as
   absent rather than left to time out.
2. Registration management: sign-up, approval queue, invites, email
   confirmation. Accounts are Nextcloud users, so provisioning lives in the
   server, and an approval queue and invite links have no equivalent anywhere.
   This one is not going to be built here; what changed is that
   `POST /api/v1/accounts` answers **403** with the server's registration
   address instead of a 404.
3. **Graded domain blocks, through a Mastodon admin client.** The app has a
   silence tier — `FediverseService::silenceAddress()`, reachable from the
   admin settings and `occ social:fediverse` — but the admin API does not
   expose it: `AdminApiService::assertSeverity()` refuses any severity but
   `suspend`, `AdminDomainBlock` reports every entry as `suspend`, and silenced
   domains are not in that list at all. An admin moving from Mastodon finds the
   feature present in the web UI and absent from their tooling.
4. Search is a substring match, not an index. `StreamRequest::searchContent()`
   is an unanchored `ILIKE` over `content`, which is honest at the instance
   sizes this app targets and will not survive a large one; see
   [Performance.md](Performance.md).
5. `tootctl` equivalents for `preview_cards remove` and media-only sweeps.
   `accounts cull` and `accounts prune` no longer belong on this list: the cache
   cron gives up on an unreachable remote actor after ten failed refreshes
   (`CacheActorService`, the cull) and evicts the cached remote accounts nobody
   here refers to after `cache_actor_days` (`CacheActorSweepService`, the
   prune), both of them continuous rather than a command an administrator has
   to remember. `occ social:media:usage` reports what the media costs but
   deletes nothing.
6. Canonical email blocks, which belong with registration.

**No longer on this list**, each verified against the code rather than assumed:
conversation mute; all four of the notification types that were missing —
`poll`, `status`, `moderation_warning` and `severed_relationships`; the three
standalone `instance` sub-routes; warnings and strikes; an account browser and a
post-takedown button; custom emoji; admin metrics; IP and email-domain blocks;
and a moderator role distinct from Nextcloud admin.

The moderation gap that was a correctness bug rather than a missing feature —
**suspending a local account purged its posts here and federated nothing**, so
every remote instance kept its copies and the takedown stopped at this
instance's own edge — is fixed in #2126: a suspension now sends the same
`Delete` the account's own deletion sends. Only for a local account, because a
`Delete` this instance signed for somebody else's actor is not one any peer
would act on.

---

## 7. Deliberate differences that are not gaps

Worth stating so they are not mistaken for work. Authentication, 2FA and sessions
come from Nextcloud. Accounts are Nextcloud users, so `accountEnable` is a
deliberate no-op and there is no per-actor login to disable. Notifications go
through the Nextcloud notification system — bell, mobile app, mail digest —
rather than Web Push, and they are emitted for mention, favourite,
reblog, follow, follow request and update from the single write path
(`NotificationService`). There is no materialised home feed
to rebuild, because timelines are queried live. Rules and retention are app
values and occ commands. Moderation lives in Nextcloud admin settings.

`POST /api/v1/media` accepts more than Mastodon does, on purpose. Beside the
pictures, video and audio, a post here may carry a **document** — a PDF, a
spreadsheet, a text file — which is what people on a Nextcloud actually have to
share, and Mastodon would answer one of those `422 File content type is
invalid`. The answer here is `200` with `type: "unknown"`, which is exactly what
Mastodon's own entity says about an attachment it cannot draw, so a Mastodon
client renders it as a link and a reader on another Nextcloud gets a file card.
Nothing a browser would execute is accepted: HTML and SVG are refused `422`, and
everything served carries `X-Content-Type-Options: nosniff`.

And in the other direction, Mastodon has no equivalent for: dashboard
widgets, profile-page integration, posting a picture straight from Nextcloud
Files, Aloha Social data in `occ user:export`, and occ commands as an admin
surface.

---

## 8. What landed since the last review

Credit where it is due. Of the nine defects listed at 0.11.63, seven are fully
fixed and two partially: local poll voting, `scheduled_at`, the character limit,
poll expiry, the single-choice duplicate vote, `?remote=true`, and notification
dismiss and clear are all correct now. Of the four moderation findings, all four
are fixed — takedowns federate a `Delete`, domain blocks purge stored content
through a background job, reports are forwarded outbound signed by the instance
actor, and a suspension of a local account federates its `Delete` (#2126).
Notifications, called the largest functional gap in that review, now reach the
Nextcloud bell.

Since that paragraph was written, four more waves landed. **#2134** filled the
small client gaps — the v1 filter routes, `instance/peers` and `instance/activity`,
`preferences`, `familiar_followers`. **#2135** went after what a peer would
notice — `Add` and `Remove` federate a pin, the WebFinger profile link points at
a Aloha Social profile, and `mediaType`, which §4 has now reopened because the fix
never reached the object a peer is served. **#2136** is the admin and
moderation tier almost entire: instance silencing, a moderator role that is
Nextcloud's own settings delegation, an account browser and a takedown button,
warnings and strikes, IP and email-domain blocks, admin metrics, the instance's
own custom emoji, announcement reactions, and authorized fetch with secure mode.
And the whole of tier 2b followed it: conversation mute, the `poll`, `status`,
`moderation_warning` and `severed_relationships` notifications, the three
`instance` sub-routes, the link timeline and the standalone card.

Three fields this server has long accepted have also stopped being reachable
only from somebody else's client: the web composer sends `language` with every
post, schedules one with `scheduled_at` and lists what is waiting through
`/api/v1/scheduled_statuses`, and writes a `focus` onto an attachment. Nothing
changed on the API for any of them; what changed is who can set them.

Of the tier-1 pair, one has moved: per-user OAuth tokens are done. The root
path has not.

Since then, 0.20.2 went after what a **Pixelfed** user and their app would
meet. Two silent failures on the wire: every video posted here went out as an
ActivityPub `Video`, which Pixelfed's inbox drops without a word, so it is a
`Note` by default now; and Pixelfed's follows export is a JSON array of actor
URLs that the importer read as an empty CSV, so it reads JSON. The official
app was read against the route table — forty endpoints of Pixelfed's own, ten
answered — and thirty-four are answered now, through one façade that reshapes
data this app already serves: the `v1.2` story carousel, `collections/self`,
`accounts/username` and `mutuals`, `v1.1/report`, `compose/settings`, the
direct-message thread routes, `tags/{tag}/related`, `push/*` told the truth
(off, no token), and Pixelfed's `/api/admin/*` screens behind the same gate as
Mastodon's admin API. The silence tier reached the Mastodon admin API on the
way (item 19). And the three Pixelfed-native features that were API-only —
stories, collections, places — have pages in the web app.

And of this document's own list, five items were done in #2126 — the `source`
leak, the suspension, the three dropped profile fields, the version string and
the three missing endpoints — with authorized fetch joining them in #2136 and
`redirect_uri` on the Application entity since. What is left of that list is
what it was always going to be: the root path, push, the attachment shape, and
the takeover.

---

## 9. What is still to do

This was a second file until this revision (`docs/Mastodon-Roadmap.md`), which meant
the same items were described twice and drifted apart — the table below said
both tier-1 blockers were open while §3.2, four screens up, said one of them
was fixed. It is one list now.

Fifty items in eight tiers, each with a rough size, what it actually fixes, and
whether it is done. Query and scalability work has its own list in
[Performance.md](Performance.md) and structural debt in
[Technical-Debt.md](Technical-Debt.md); nothing here repeats those.

### Tier 1 — the blocker. Nothing else is visible to a user until it lands

| # | Work | Effort | Why it is first | Status |
|---|---|---|---|---|
| 1 | **Serve `/api` and `/oauth` at the domain root**, or document the reverse-proxy rewrite and ship a setup check for it | Days | Every route is a `#[FrontpageRoute]` under `/apps/social/`, and the Mastodon client protocol has no way to be told about a non-root API base. No stock client can reach *any* of the surface below without it | done as the second: `contrib/webserver/`, [Admin.md](Admin.md#mastodon-apps-cannot-connect), the `ClientApiAtRoot` setup check and the in-app warning. The app cannot do it by itself: Nextcloud lets only a short list of apps claim root URLs |
| 2 | **Per-user OAuth tokens** — an authorization table keyed to (app, account) instead of one `token` column on `social_client` | Days | A second authorization against the same `client_id` revoked the first, so user B signing into Elk signed user A out | done (`social_client_auth`) |

### Tier 2 — days of work each, and each one a thing a client shows

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 3 | **Web Push** (`/api/v1/push/*`, a VAPID key, `configuration.vapid`) | Weeks | Third-party mobile apps get no notifications at all; every client polls. `vapid_key` is answered as `''`, which is how a client learns to stop asking | **open** |
| 4 | **`/api/v1/preferences`** | Hours | Clients read the posting defaults from it and fall back to guesses | done |
| 5 | **`/api/v1/custom_emojis`** — the instance's own emoji, and `Emoji` tags outbound | Days | Returned `[]` unconditionally: remote emoji rendered, this instance could publish none | done |
| 6 | **`/api/v1/accounts/familiar_followers`** | Hours | The "followed by people you know" line on a profile | done |
| 7 | **`/api/v1/instance/peers` and `/activity`** | Hours | Instance browsers and the about page showed nothing | done |
| 8 | **The v1 filter routes** | Hours | A client that has not moved to v2 filters got a 404 rather than an empty list | done |
| 9 | **`/api/saved_searches/list.json`** — implement or stop routing it | Hours | Initialises a viewer and returns `[]`. See "Two answers rather than a tick" below | **open, and a decision rather than a task** |
| 10 | **Streaming** (`wss://`, `/api/v1/streaming/*`) | Weeks | Deliberately absent and announced as absent (`urls` is an empty object), so clients poll immediately rather than after a timeout. A real timeline needs a process that outlives a PHP request | **open** |

### Tier 2b — what walking the route list turned up

These came out of reading Mastodon's documented client API against
`appinfo/routes.php` route by route, rather than from remembering what was
missing. All of them have since landed.

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 31 | **Conversation mute** — `/api/v1/statuses/{id}/mute` and `/unmute` | Days | `ActionService` refused both by name; a thread could not be muted | done |
| 32 | **The `poll` notification** | Days | A voter never learned that the poll closed | done |
| 33 | **The `status` notification and `notify` on follow** | Days | Mastodon's bell on a profile. `POST /accounts/{id}/follow` now takes `notify` and writes a per-account subscription | done |
| 34 | **`moderation_warning` as a client notification** | Hours | A warning reached a local account through Nextcloud's bell, which a Mastodon client cannot see | done |
| 35 | **`severed_relationships`** | Days | When a domain block cuts follows, the accounts that lost them are told | done |
| 36 | **The three `instance` sub-routes** — `/rules`, `/domain_blocks`, `/extended_description` | Hours | The rules were served only *inside* the instance entity; the standalone routes 404ed | done |
| 37 | **`/api/v1/timelines/link`** | Days | The posts behind a trending link, whose links were already at `/api/v1/trends/links` | done |
| 38 | **`/api/v1/statuses/{id}/card`** | Hours | A 405, because the path matched the POST-only action route | done |

### Tier 2c — the 2026-09-15 walk, against Mastodon's 145 documented routes

The 23 the walk found unserved, once the catch-alls were discounted. Everything
that is not Web Push, streaming or a sign-up this app does not own is done.

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 40 | **`POST /api/v1/statuses/{id}/translate`** — a real translation | Days | It used to return the post *unchanged*, which a client cannot tell from a translation: the button worked and did nothing. Now answered by whatever translation provider this Nextcloud has, as a Translation entity, with `instance/translation_languages` beside it and a 503 where there is no provider | done |
| 41 | **Grouped notifications** — `/api/v2/notifications` and its four routes | Days | "Eight people favourited your post" instead of eight rows. A client cannot group for itself: it would have to fetch every page to know how many there were | done |
| 42 | **The notification policy and requests inbox** — `/api/v1/notifications/policy`, `/requests*` | Days | An account that strangers write to could either read every notification or turn notifications off. Five questions about the sender; what is held waits per account, so the reader decides about the account once | done |
| 43 | **The status half of v2 filters** — `/api/v2/filters/{id}/statuses`, `/statuses/{id}` | Days | A client's "filter this post". The filter entity's `statuses` was hard-coded `[]` | done |
| 44 | **`DELETE /api/v1/profile/avatar` and `/header`** | Hours | Multipart has no way to send "none", so a client could offer "change picture" and not "remove picture" | done |
| 45 | **`DELETE /api/v1/suggestions/{id}`** | Hours | Without it the "who to follow" panel offers the same accounts on every visit, the ones already decided about included | done |
| 46 | **`instance/privacy_policy`, `/terms_of_service`, `/oembed`** | Hours | From Nextcloud's own Theming settings; oEmbed as `type: link`, since there is no embed page here to frame | done |
| 47 | **`reblogs` on the follow, and `showing_reblogs`** | Days | The flag was hardcoded `true` and nothing wrote it. Now a row in `social_actor_relation`, applied as a predicate of the home and list queries | done |

### Tier 2d — the web client, where most people read this app

A Mastodon user who switches keeps the web experience and loses their phone
app until tier 1 lands, so what the web client cannot do is what they actually
meet. These three were it.

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 48 | **Filters in Settings** | Days | The API had filters and this app's own client had no page for them, so the people most likely to be reading Aloha Social in a browser could not filter a word at all | done |
| 49 | **Delete & re-draft** | Days | The correction people actually make. Words, warning, audience, language and pictures come back in the composer; the pictures by id, since deleting a post does not delete the uploads | done |
| 50 | **The bell and hide-boosts on a profile** | Hours | Both relationship flags existed and neither had a control | done |

### Tier 3 — what a peer would still notice

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 11 | **Authorized fetch inbound** — verify the HTTP signature on GET and resolve the remote reader | Weeks | Signature verification ran on inbox POSTs only, so a followers-only object could not be served to an authorized remote reader and secure mode was impossible. It failed closed, so nothing leaked | done (`AuthorizedFetchService`) |
| 12 | **`Add` and `Remove` outbound** for pins | Days | A pin was only visible to a peer that re-polled `featured` | done |
| 13 | **`mediaType` on attachments** | Hours | The stored row (`MediaAttachment::asLocal()`) now carries `media_type`, `import()` reads it back — with a guess from the extension for rows written before it existed — and the Document a post is served as states it. Since 0.20.5 the Document also **leaves out what it does not know** rather than sending `"width": 0`, `"height": 0` and `"blurhash": ""`: Pixelfed validates all three as `nullable|min:…` *when the key is present* and drops the whole post when one fails, so an attachment with no stored dimensions took its post with it, silently | done |
| 14 | **The WebFinger profile-page link** | Hours | Pointed at the Nextcloud user profile rather than a Aloha Social one | done |
| 15 | **Emoji reactions** | Days | Announcement reactions are stored and served. Reactions to a *status* are a Misskey and Pleroma extension Mastodon does not handle either, and are deliberately not implemented | done (announcements) |
| 39 | **Serve attachments as ActivityPub `Document`s** | Hours | New, and verified on the wire rather than in a unit test: everything served on request — a single status, the outbox, `featured`, `replies`, and any re-fetch by a peer — carries Mastodon's *client* shape under `attachment` (`"type": "video"`, `preview_url`, `remote_url`, `meta`) instead of `{"type": "Document", "mediaType": "video/mp4", "name": …}`. `MediaAttachment::asDocument()` is correct and `ACore::FORMAT_ACTIVITYPUB` is set on the attachments of a freshly created post, so the original `Create` goes out right; but `StreamRequest::save()` stores `asLocal()` and hydration leaves the objects in the local format, so every later read of the same post is wrong. `WireCompatibilityTest` calls `asDocument()` directly and therefore passes | done — `Stream::jsonSerialize()` maps every attachment through `asDocument()` whatever format it was hydrated in, so a re-read post goes out the same as the original `Create`; `StreamTest::testAHydratedPostServesItsAttachmentsAsDocuments` pins it |
| 51 | **Publish a video as a `Note`, not a `Video`, by default** | Hours | `Note::asVideoIfItIsOne()` sent a sole-video post in PeerTube's shape, and Pixelfed's `HandlesCreates` processes only a `Note` with a parent or an attachment — a `Video` is dropped without a word, so no video posted here ever reached a Pixelfed follower. Mastodon draws both shapes, PeerTube only the `Video`, Pixelfed only the `Note` | done — `publish_video_objects` defaults to `0`; an instance whose audience is on PeerTube turns it on |

### Tier 4 — the admin and moderation surface

| # | Work | Effort | What it fixes | Status |
|---|---|---|---|---|
| 16 | **Registration management** — sign-up, an approval queue, invites, email confirmation | Weeks | Accounts are Nextcloud users, so provisioning lives in the server. See "Two answers rather than a tick" | answered, not built |
| 17 | **Warnings and strikes**, and "email this user" | Weeks | The ladder jumped from silence straight to suspend, with nothing in between and no record | done (`StrikeService`) |
| 18 | **An account browser in the admin UI**, and a button for post takedown | Days | Only *reported* accounts were actionable from the web | done |
| 19 | **Graded domain blocks** — a silence and a limit tier, and `reject_media` | Days | The app has a silence tier (`FediverseService::silenceAddress()`), but the *admin API* does not expose it: `AdminApiService::assertSeverity()` refuses any severity but `suspend`, `AdminDomainBlock` reports every entry as `suspend`, and silenced domains are not in that list at all. A Mastodon admin client still sees block-outright or nothing | done — `AdminApiService` lists the deny list as `suspend` and the silenced list as `silence`, takes both on create, moves a domain between them on update and lifts either on delete; `noop` stays a 422, there being no list for it. `reject_media`/`reject_reports` follow the tier. Pixelfed's own admin routes (`/api/admin/instances/*`) read the same two lists as `banned` and `unlisted` |
| 20 | **IP blocks, email-domain blocks, canonical email blocks** | Days | The first two are there (`/api/v1/admin/ip_blocks`, `/admin/email_domain_blocks`); canonical email blocks are not, and belong to a sign-up this app does not own | partly done |
| 21 | **A moderator role distinct from Nextcloud admin** | Days | Every admin route asked `IGroupManager::isAdmin()`, so moderating meant full server administration. It is now Nextcloud's own settings delegation rather than a second list of names | done |
| 22 | **Admin metrics** — trends, measures, dimensions, retention | Weeks | Absent | done |
| 23 | **`tootctl` equivalents** — `accounts cull`/`prune`, `preview_cards remove`, media-only sweeps | Days | `social:cache:refresh`, `social:stream:prune` and `social:domain:purge` cover neighbouring ground; the culls and the media-only sweep had no equivalent | **partly done** — both culls are now continuous rather than commands: `CacheActorService` stops refreshing a remote actor after ten consecutive failures (`accounts cull`), and `CacheActorSweepService` evicts the cached accounts nobody here follows, that follow nobody here and that wrote no stored post, with their avatars, after `cache_actor_days` (`accounts prune`). `occ social:media:usage` answers what the media is costing, split into local uploads and cached remote files; `preview_cards remove` and a media-only sweep are still open |

### Tier 5 — the takeover, which is a different project

Untouched, and item 24 gates the other six.

| # | Work | Effort | Why it is last |
|---|---|---|---|
| 24 | **Stored actor identity** — stop overwriting `id` and the collection URLs on read; mint only when the column is empty | Weeks | `ActorsRequestBuilder::parseActorsSelectSql()` recomputes identity from configuration on every read, so a Mastodon-shaped id cannot survive a round trip. Everything below depends on this |
| 25 | **Serve the Mastodon URL space** — `/users/{name}`, `/users/{name}/statuses/{id}`, root `/inbox`, `/outbox`, `/followers`, `/following` — and resolve the requested URI rather than rebuilding it | Weeks | Peers hold the old URIs as primary keys; after a domain swap every one of them 404s |
| 26 | **Key-pair import**, root-only and loudly warned | Days | Keys are always generated; the old `keyId` becomes unresolvable and every signature fails on the far side |
| 27 | **A handle and id rename path** | Weeks | The `*_prim` md5 columns mean an id change is a fan-out rewrite across roughly a dozen tables |
| 28 | **Status and follower-graph importers**, writing rows and federating nothing | Months | The *export* half is whole — `SocialMigrator` writes the account's own posts as an ActivityPub `OrderedCollection`, **their pictures and videos** under `media_attachments/`, and its followers and following as CSV — and the import side restores the profile, follows, relations, bookmarks, the banner and the files of the posts this server already has. Statuses are written back by the post import (`PostImportService`, `occ social:account:import-posts`) as new local posts under this server's ids, federating nothing — not under their original ids, which needs 24; followers cannot be imported at all until identity is continuous |
| 29 | **Counter and threading reconciliation**, and a **cutover verification command** that fetches our own actor over HTTPS as a peer would | Weeks | Without it, nobody can tell whether a cutover worked until the network says so |
| 30 | **The operational runbook** — freeze, drain, dump, import, flip, keep the old inbox reachable | Days | |

### Two answers rather than a tick

**9 — `/api/saved_searches/list.json`.** Not Mastodon's. It is a Twitter route
that arrived with an early client and has no place in a Mastodon-compatible
surface, so "implement it" would be implementing somebody else's API. Removing
the route is the other half of the choice and is a decision about breaking
whatever still calls it — which is why it is still here rather than quietly
done either way.

**16 — registration management.** An account on this server is a Nextcloud
account: the server creates it, through whatever provisioning it is configured
with, and this app is handed one that already exists. A sign-up form, an
approval queue, invite links and email confirmation all belong to the server,
and building a second one inside an app that does not own the account is how
two systems come to disagree about who exists. What this app owes a client is
an honest answer, and it gives one: `registrations: false` in the instance
entity, and `POST /api/v1/accounts` answering **403** in Mastodon's error shape
with the address of the server's own registration page. A 404 was the wrong
answer — a client reads it as "this server is broken".

### Deliberately not on the list

`/api/v1/emails/confirmations`, `/api/v1/notifications/requests`,
`/api/v2/notifications/policy`, `/api/v1/annual_reports` and
`/api/v1/terms_of_service`. The first belongs to a sign-up this app does not
own (see 16). `/api/v1/annual_reports` and `/api/v1/terms_of_service` arrived in
Mastodon 4.3; the rest of that release's surface is served and the version now
says `4.3.0`, so a client that reads it before it asks will ask.

### Where to start

**Item 1 is done as far as an app can do it.** The API answers at the domain
root once an administrator installs the shipped web-server rules, and the setup
check says whether they have; on an instance where they have not, no stock
client reaches *any* of the surface above.

**Item 39 next, ahead of anything larger.** It is hours of work, it is the only
thing on this list a *peer* currently gets wrong, and every post with a picture
or a video is affected by it. Then Web Push (3) and streaming (10), the two
weeks-long items left, in the order a user would notice them.

**Tier 5 should not be started** until somebody decides that "replace an
existing Mastodon instance on its own domain" is a product goal. While
`ActorsRequestBuilder` recomputes an actor's identity from configuration on
every read, no imported id survives a round trip and nothing downstream of it
is possible.

Tiers 1 and 2 are what "usable as a Mastodon server" means. Tier 5 is what
"replaces an existing Mastodon instance, on its own domain, without the network
noticing" means.

---

## 10. Documentation drift

`tests/DocumentationTest.php` checks that the route set matches the tables, not
that the prose around them is true, so four claims had drifted. Three were
corrected when this file was added (#2112, #2123): `docs/API.md` denying the
existence of an account-note route two lines above the row documenting it,
calling two instance counters permanently zero when they are counted and cached,
and calling local poll votes and poll creation unsupported when both work.

The fourth is the opposite kind and is **still true**: `README.md` says
third-party clients can log in. They cannot, until §3.1 is fixed — and that is a
promise the code does not keep rather than a stale limitation, which is the
worse of the two. The README now says what stands in the way, but the sentence
is only honest because it says so.

Two more were found on this pass, and they are worth naming because they are
different failure modes.

The first is duplication. This document and `docs/Mastodon-Roadmap.md`
described the same items in two places, and they disagreed: the roadmap had
per-user OAuth tokens done, §3.2 had them done, and §9's summary table still
called both tier-1 blockers open. Two documents that must agree will not, so
there is one now.

The second is worse, because a test was supposed to catch it. §4's attachment
finding — the wire carrying Mastodon's client entity where an ActivityPub
`Document` belongs — had been recorded as fixed, and
`WireCompatibilityTest::testWhatGoesBackToPixelfedStatesItsMediaType()` passes.
It passes because it calls `MediaAttachment::asDocument()` itself rather than
serialising a stored status the way a controller does, so it pins a method and
not a behaviour. **A federation claim is only worth what the assertion behind
it exercises.** The way this one was actually found was fetching a status from
a running instance with `Accept: application/activity+json` and reading what
came back, which is what the next pass over §4 should do again.

A third pass, 2026-09-15, found three more of the first kind — prose that had
gone stale under working code — and all three were in `README.md`: it listed
status translation and the instance's own custom emoji under "Not implemented
yet" when both work, and it carried two "Lists" bullets, the second of which
said the web client had no list editor months after Settings → Lists shipped.
It also carried three pairs of near-duplicate bullets that had been added twice
and edited once.

The lesson each time is the same and is worth stating once: **a feature list is
a claim, and a claim nothing tests goes stale in the direction that flatters
nobody.** The way these were found was reading the README against the code
rather than against the last revision of the README.
