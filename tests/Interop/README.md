<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Interop tests (this app talking to a real Mastodon, PeerTube and Pixelfed)

The unit suite proves this app emits the document it meant to. The integration
suite proves the database and the migrations hold what it thinks they hold.
Neither can prove the thing that actually matters on a federated network: that
**Mastodon accepts what we send**. That gap is where a silent drop lives — a
delivery that returns 202, is queued, is processed, and produces nothing on the
other side, with no error anywhere on ours.

The Pixelfed work found three of those by running our payloads through
Pixelfed's own validators. Nothing equivalent had ever been done for Mastodon,
which is what almost everybody on the other end is running.

## What it does

Nothing is stubbed. For each test the suite:

1. asks Mastodon to **resolve our account** (`/api/v2/search?resolve=true`),
   which already proves the actor document we serve is one Mastodon will fetch
   and accept;
2. makes Mastodon **follow** it, and waits for the `Follow` to arrive here and
   be accepted — a post reaches an instance because somebody there follows its
   author, so this is both the setup and an assertion;
3. writes a post through `PostService`, the way the API writes one;
4. **drains the delivery queue synchronously** — the real queue, signed by the
   real signer, because skipping it would not be testing the delivery path at
   all and waiting for cron would be a test that waits five minutes;
5. reads Mastodon back through **its own client API**, not its database: a row
   its serialiser refuses to render has not arrived either, and the API is the
   same answer a Mastodon user would get.

Covered against **Mastodon**, from here to there (`MastodonDeliveryTest`):
`Create` (a public post, and a post with a content warning — `summary` is the
field most likely to be quietly dropped), `Update` (an edit has to carry
`updated`, or the other side takes the edit in and goes on showing the words
that were replaced), `Delete`, and `Announce`.

Written through this app's own **client API** rather than its services
(`MastodonOutboundTest`), the way a phone app writes: a follow and Mastodon's
`Accept` of it; a follow of a locked Mastodon account, which waits until that
account says yes; a locked account here, whose follow request from Mastodon
waits for an answer given here; a picture with its description; a poll, and a
vote cast on Mastodon coming back to the count here; a reply threaded under
the Mastodon post it answers; a favourite and a boost counted there, and both
undone; a followers-only post read by a follower and by nobody else there; a
direct message read by its addressee and by nobody else there; a hashtag and
a mention arriving as a tag and a mention, links included; a name, bio and
profile fields edited here and shown there; and an account deleted here gone
there.

And from there to here (`MastodonInboundTest`), each read back through the
same client API our `admin` would use: a post on the home timeline of a
follower, with its content warning; an edit applied and a delete honoured; a
mention notified; a direct message that reaches its addressee and neither the
public timeline nor another account here; a reply threaded under the post
here it answers; a favourite and a boost counted and notified; a poll that can
be read and voted on, the vote counted on Mastodon; a picture with its
description; a hashtag post on that tag's timeline; a profile change (name,
bio, avatar) refreshing the copy held here; an unfollow ending the follow
here; and a block ending both follows. A quote is written only by Mastodon 4.4
and later, so against the 4.3 the workflow pins that test skips and says why.

The tests on this side talk to this app over HTTPS, at the address the other
servers know it by, with a token minted the way `/oauth/token` mints one
(`Here`); the same client class (`ClientApi`) reads Mastodon, so "it arrived"
means the same thing at both ends.

Covered for **moving**, both ways. Our `mover` moves to Mastodon's `landing`
(which the workflow made name `mover` in its `alsoKnownAs`, as Mastodon's own
form would): Mastodon's follower of `mover` is asked afterwards, through
Mastodon's relationships API, whether it now follows `landing` and no longer
`mover`. And Mastodon's `leaver` moves to our `arrival`: this side first names
`leaver` as an alias (through the same handle-resolving service the page uses)
and follows it from `admin`; the workflow then has Mastodon deliver a `Move`
signed by `leaver` to that inbox, and the last test reads whether the follow was
re-pointed at `arrival` and `leaver` is recorded as moved. The inbound half is
three steps in the workflow rather than one test, because the `Move` has to be
sent by Mastodon between what this side prepares and what it asserts.

Covered for **moving in from the handle alone** (`MoveInFromMastodonTest`):
our `puller` names Mastodon's `interop` as its old account, and the same
service the wizard runs reads `interop`'s public `following` and `outbox` and
brings both over — the test has `interop` write a post first, then finds the
copy here, written by `puller` with the same words, and sees `admin` followed
because `interop` follows it. This is the half of a move that is ours alone;
the `Move` tests above are the half the protocol carries.

Covered against **PeerTube**: a video published here, arriving as a `Video`,
filed under the right channel, with a duration and a file link that survived —
and a `Delete` that takes it away again. This is the one that had never been
run and the one that mattered most. PeerTube refuses a video it cannot make
sense of **silently and on its own side**: *"Cannot find associated video
channel"* goes into its log, the delivery from here answers 204, and nothing
here is any the wiser. Reading its validator told us what it wants; only this
tells us whether we send it. Its own log is printed when the job fails, because
that is where the refusal is. An edit of the video reaches PeerTube as one, its
title following the text.

And the other way round (`PeerTubeInboundTest`): PeerTube's account uploads a
real video — a few seconds made by PeerTube's own ffmpeg in the workflow — to
the channel our `admin` follows, and it arrives on the videos timeline here
with its title, description, duration and poster — as the video, or as the
channel's boost of it, which is how a channel tells its followers. An edit and
a delete on PeerTube apply here. A reply written here becomes a comment on the
video there; PeerTube's answer to that comment
notifies its author here; a like from here is counted there; and following a
channel puts our account among its followers there, unfollowing takes it out
again.

## What it found

Its first runs that got as far as delivering anything found five defects, all
silent on this side:

- every delivery through the parallel queue was read as "no response", sent
  again, and its host put behind the circuit breaker for a minute;
- a video never reached the followers of its channel, which is what a PeerTube
  follows;
- an edit federated the client-format object, so Mastodon dropped every one;
- PeerTube refused every `Video` on its version-5 `uuid`;
- a `Video` with no `likes`/`dislikes` crashed PeerTube outright.

Its later runs, with the inbound and client-API tests, found these:

- a PeerTube video's edit, and its deletion by Tombstone, are sent by the
  account behind the channel the video is filed under, and both were refused
  as coming from somebody other than the author;
- a followed PeerTube channel's videos never reached its follower's home or
  Videos timeline, because PeerTube addresses them to the followers of the
  account, not of the channel;
- a vote cast on Mastodon on a poll of ours was never counted, and was stored
  as a message to the poll's author instead;
- a display name, profile fields or a lock changed here never reached anybody
  who already followed the account;
- a profile edit sent one `Update` per field, and Mastodon kept whichever won
  its lock, so the fields of a combined edit never showed there;
- a remote account's new avatar was never shown here, the profile pointing
  at a picture nobody had stored.

## What it cannot prove

It runs against **one** version of each, on **one host**, with a certificate
authority made for the job. So it says nothing about instances running
`AUTHORIZED_FETCH`, about Mastodon or PeerTube versions other than the ones the
workflow pins, or about what a public certificate chain would change.

## Running it

Every test **skips with a reason** when `MASTODON_BASE_URL` and
`MASTODON_TOKEN` are unset, so running the suite without a Mastodon is a pass
that says so rather than a failure.

```
MASTODON_BASE_URL=https://mastodon.test MASTODON_HOST=mastodon.test \
MASTODON_TOKEN=... MASTODON_TOKEN_STRANGER=... MASTODON_TOKEN_GUARDED=... \
PEERTUBE_BASE_URL=http://localhost:9000 PEERTUBE_HOST=localhost:9000 \
PEERTUBE_USER=interop PEERTUBE_PASSWORD=... \
PEERTUBE_SAMPLE_VIDEO=/tmp/interop-sample.mp4 \
composer run test:interop
```

`MASTODON_TOKEN` is Mastodon's `interop`; `MASTODON_TOKEN_STRANGER` an account
that follows nobody here, and `MASTODON_TOKEN_GUARDED` a locked one. A test that
needs one of those two, or the sample video, skips without it. On this side the
tests act as `admin`, and as `shy`, `profile`, `goner`, `watcher` and
`bystander` where a test changes its own account or needs a second reader;
those have to exist.

Each peer is independent: setting only the Mastodon variables runs the Mastodon
tests and skips the PeerTube ones, and the other way round.

It needs the same real Nextcloud the integration suite does — the app inside a
server checkout, installed, with `cloud_url` and `social_url` set — and that
Nextcloud has to be **reachable from the Mastodon and the PeerTube over
https under a host name**. Both fetch another server's webfinger and actor
over https only, and Mastodon in production redirects every plain-http request
to https, its own API included. `allow_local_remote_servers` on our side,
`ALLOWED_PRIVATE_ADDRESSES` on Mastodon's and `PEERTUBE_FEDERATION_PREVENT_SSRF`
off on PeerTube's are what make servers on one host able to see each other.

## In CI

`.github/workflows/interop.yml` stands the whole thing up: Postgres, Redis,
Mastodon's web and Sidekiq containers, a PeerTube, a Nextcloud, and an account
on each side. Nextcloud is `https://nextcloud.test` and Mastodon
`https://mastodon.test`, both behind one Caddy proxy with a certificate from a
CA made in the job and trusted by the runner, by Nextcloud, by Mastodon and by
PeerTube; the proxy also does what Nextcloud's `.htaccess` does for
`/.well-known`, since PHP's built-in server serves files only. When a run
fails, the last step prints what each side saw: our notices, our delivery
queue and circuit breaker, every delivery through the proxy, what Mastodon
holds from us, the last `Update` run through Mastodon's own processing, the
last `Create` sent to PeerTube, and PeerTube's validator refusals, which it
logs only at debug level.

It runs on **every pull request** and every push to master, weekly to catch
the other end moving, and by hand from the Actions tab. It depends on
third-party images whose startup this repository does not control, so a red
run is worth reading for which side failed before blaming the change under
review; a newer push to the same pull request cancels the run still going for
the old one. One run takes about twenty minutes.

## Against Pixelfed

`.github/workflows/interop-pixelfed.yml` is a job of its own, so it runs
beside the Mastodon one rather than after it, and it runs on **every pull
request** as well as on pushes to master, weekly and on demand. It stands up
the official Pixelfed image (`ghcr.io/pixelfed/pixelfed`, pinned by tag and
digest) with MySQL, Redis and a second container running Horizon — every
delivery, fetch and fan-out on Pixelfed is a queued job, so without Horizon
nothing leaves it.

Two things are different from the Mastodon job:

- **Pixelfed refuses to federate with a private address**, and nothing in its
  configuration says otherwise. `nextcloud.test` and `pixelfed.test` both
  resolve to one globally routed address that the job puts on the runner's
  loopback, so Pixelfed's check passes and nothing leaves the machine.
- **Our side is driven through this app's own client API over HTTP**, signed
  in as `admin` with basic auth and `OCS-APIRequest`, not through the
  services: a photo goes through the real upload, the Exif strip and the
  `Create` the API builds, and what is asserted on our side is what the
  Photos page, a thread or the notifications would show. Only the delivery
  queue is drained in-process, as in the other suites.

`PixelfedDeliveryTest` covers what we send: our `Follow` accepted, a photo
with its description, an album whole and in order, caption hashtags, a
content warning, a like counted and notified, a comment threaded, a story — what
the web client calls a 24-hour short — on Pixelfed's story bar (fetched
through the bearcap), a profile `Update`, a
direct message landing in the conversation and not on a profile, a delete
and an unfollow. `PixelfedInboundTest` covers what Pixelfed sends: its
`Follow` accepted, a photo on our Photos timeline with its description, an
album, hashtags, a content warning, a like counted and notified, a comment
threaded under our post, a profile update, a direct message arriving as
direct, a delete and an unfollow. Every test writes words and pictures of its
own, polls with a bound instead of sleeping, and makes both follows again
itself when an earlier test took one away.

Skipped, with the reason in the test: **collections** (Pixelfed neither sends
nor ingests them) and **a Pixelfed story arriving here** (Pixelfed fans a
story out only to servers whose nodeinfo says `pixelfed`). Pixelfed sends no
`Update` when a profile is edited; the inbound profile test runs the one
command that does send it, `ap:update-actors`, through `docker exec`, and
skips where `PIXELFED_CONTAINER` is not set.

It found one defect on this side so far: a `Follow` for a follow this side
already counted was ignored, so a follower whose own record was lost stayed
pending for ever. A repeated `Follow` is now answered with the `Accept` again.

To run it by hand: `PIXELFED_BASE_URL`, `PIXELFED_TOKEN`, `NEXTCLOUD_URL`,
`NEXTCLOUD_USER` and `NEXTCLOUD_PASSWORD` (and optionally
`PIXELFED_CONTAINER`), then `composer run test:interop -- --filter Pixelfed`.
When a run fails, its last step prints Pixelfed's Horizon log, its follow
tables, every request through the proxy including what each side fetched,
and our delivery queue.

## Against Loops

`.github/workflows/interop-loops.yml` is a job of its own, run on every pull
request, on pushes to master, weekly and on demand. It builds Loops
(v1.0.0-beta.14) from its own repository with its queue workers and its
federation switch on, behind the same Caddy and job-made CA as the other jobs;
the test video is made with ffmpeg in the job.

`LoopsDeliveryTest` covers what we send and `LoopsInboundTest` what Loops
sends: follows both ways, videos with caption and poster, likes and unlikes,
comments threaded under the video, hashtags, edits, deletes and unfollows.
Skipped, with the reason in the test: a remote answer to a comment (Loops files
it as a new comment on the video) and a Loops profile update (Loops never
delivers an `Update` for a profile).

It found four defects on this side: an actor `Update` not addressed to the
public and the followers, a reply that did not name the author it answers
among its recipients, a document's transcode and ladder state not read back,
and posts going out with no `url` — Loops keeps a remote video's address only
from `url`, so likes and comments on our videos failed there.

When a run fails, its last step prints our log, our delivery queue and
uploads, every request through the proxy, Loops' log, failed jobs and queue
worker, and what Loops' own validators make of our last activities.

## Against Bluesky

`interop-atproto.yml` runs this app as a Bluesky PDS against Bluesky's own
software: the PLC directory, PDS and AppView of `@atproto/dev-env`
(`tests/Interop/atproto/network.mjs` starts them on fixed ports, points their
handle resolution at this app and subscribes the AppView to this app's
firehose), and the official `indigo` relay, which verifies every commit's
signature and revision. Nothing of the public network is touched.

`AtprotoVisibleTest` is phase 1 of [docs/Atproto-Compatibility.md](../../docs/Atproto-Compatibility.md)
demonstrated end to end: a local account's identity is registered with the
directory and resolved by the AppView, which verifies the handle against
this app's handle host (the dev PDS is not asked: it claims every `.test`
handle as its own by configuration and refuses the ones it lacks); a public
post reaches the AppView's author feed off the firehose, with its facets; the
profile is found by handle; a user on the dev PDS follows the account and
likes the post, both of which the AppView counts; the relay reports the
repository at this app's own head revision; an edit within the grace period
is a new record and a delete takes the post off the feed.

`AtprotoReadingTest` is phase 2: a local account resolves a dev-PDS user by
his bare handle (the account id is the bsky.app profile by DID), follows him
and reads his post in her home timeline off the AppView, with the hashtag
facet as a tag link; her like of it is counted by the AppView as a record of
her repository; his like of her post and his reply to it come in through the
AppView's notifications, asked for as her — the like in her Activities, the
reply under her post. The test polls the watch and the notification cursor
directly rather than the timed pass, because an empty first read backs a row
off past the test's wait.

What it cannot prove: indexing by `bsky.app` itself, which needs a public
https host with wildcard DNS and is done by hand; and anything of reading
Bluesky from here, which is phase 2.

Run it by hand with PostgreSQL and Redis at `DB_POSTGRES_URL` and
`REDIS_HOST`, `npm install` in `tests/Interop/atproto`, `node network.mjs`
with `SOCIAL_URL` set to the instance and `NODE_EXTRA_CA_CERTS` to its CA when
the certificate is private, then `ATPROTO_NETWORK_FILE=/tmp/atproto-network.json
composer run test:interop -- --filter AtprotoVisibleTest`.
