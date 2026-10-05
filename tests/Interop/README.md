<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Interop tests (this app talking to a real Mastodon and a real PeerTube)

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

Covered against **Mastodon**: `Create` (a public post, and a post with a
content warning — `summary` is the field most likely to be quietly dropped),
`Update` (an edit has to carry `updated`, or the other side takes the edit in
and goes on showing the words that were replaced), `Delete`, and `Announce`.

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
that is where the refusal is.

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
MASTODON_BASE_URL=https://mastodon.test MASTODON_TOKEN=... \
PEERTUBE_BASE_URL=http://localhost:9000 \
PEERTUBE_USER=interop PEERTUBE_PASSWORD=... \
composer run test:interop
```

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

It is deliberately **not** on `pull_request`. It depends on a third-party image
whose startup this repository does not control, so a bad day for that image
would block every pull request on a failure that says nothing about the change
under review. It runs weekly and on demand from the Actions tab; make it
required once it has been green for a while.
