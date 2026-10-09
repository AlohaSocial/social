<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Bluesky on a live host: the test

The interop job (`.github/workflows/interop-atproto.yml`) proves the protocol
against Bluesky's own software, run in CI: its PLC directory, PDS, AppView and
relay. It cannot prove the real network. The real `plc.directory`, the
`bsky.network` relay, the public AppView's handle checks, Bluesky's chat service,
its trends and its verifiers all come into play only once a public HTTPS host
takes part. Devel is plain HTTP and can never be a PDS. This document is the run
on such a host, by hand, in the order that makes each step's failure easy to
read.

Use **test accounts only**. A DID registered with `plc.directory` stays there.
A post that reached the relay has been copied by everybody who reads it. Nothing
here can be taken back from the network, only deleted or deactivated.

## 1. Before

- The host meets [§14.1 of the specification](Atproto-Compatibility.md#141-requirements):
  - HTTPS on the host;
  - a wildcard DNS record `*.<host>` and a wildcard certificate;
  - the root rules from `contrib/webserver`, including the firehose proxy;
  - `occ social:atproto:serve` under systemd;
  - cron, and PHP `gmp` and `sodium`.
- `occ social:check:install` passes, and the admin page's Bluesky section shows no failing check.
- Bluesky is switched on, and the relay is told: `occ social:atproto:crawl`.
- Two Nextcloud test accounts (here: `alice` and `bob`), each of which has opened Social once, so each has a Bluesky identity.
- A Bluesky account made at bsky.app, used from the Bluesky phone app or the web app (here: `@tester.bsky.social`). It is the other side of every exchange below.
- A backup of the database, per [docs/Admin.md](Admin.md).

## 2. From outside: the probe

```sh
contrib/atproto-live-probe.sh <host> alice.<host>
```

Run it from a machine outside the host's network. It only reads: GET requests and one WebSocket handshake. Each `FAIL` line says what is missing. The exit status is the number of failures.

| Failing check | Usual cause |
|---|---|
| `_health`, `describeServer`, `did.json` | the root rules for `/xrpc/` and `/.well-known/did.json` are not in the web server |
| `atproto-did` on the handle | the wildcard DNS record, the wildcard certificate, or the wildcard-host rule |
| the DID document's handle or PDS | `occ social:atproto:plc <did>` shows the log; `--repair` sends every operation the directory never confirmed |
| the firehose is not 101 | the daemon is not running, or the proxy rule for `subscribeRepos` is missing |
| the relay does not know the DID | `occ social:atproto:crawl`, then wait a minute |
| the AppView shows `handle.invalid` | the AppView could not fetch `https://<handle>/.well-known/atproto-did`; check from outside with curl |

Run the probe again after the first public post (§3.2). Its last checks need one.

## 3. By hand

Tick each line, and note the time of any failure, so the server log can be read beside it.

### 3.1 Identity

- [ ] `@alice.<host>` is found by search in the Bluesky app, shows her name, bio, avatar and banner, and has no "invalid handle" warning.
- [ ] Alice changes her avatar in Nextcloud. Within minutes the new one is shown on Bluesky, and on a Mastodon account that follows her.
- [ ] A custom handle, if a test domain is available: set it in Settings → Bluesky → Handle. The Bluesky app then shows the new handle, and `alice.<host>` still resolves to her.

### 3.2 Posting

- [ ] Each of these, posted publicly by alice, appears in her Bluesky profile looking as it does here:
  - a text post with a link, a hashtag and a mention of `@tester.bsky.social`;
  - a post with three pictures and alt text;
  - a video (it plays in the Bluesky app);
  - a post longer than 300 characters (cut, with a link back).
- [ ] The link card shows its picture.
- [ ] An edit within the first minutes replaces the post on Bluesky. A delete removes it.
- [ ] A reply to the tester's post appears in that thread. A quote of it shows the quoted post.

### 3.3 Who may reply, who may quote

- [ ] Alice posts with "Who can reply: People who follow me". The Bluesky app shows that replies are limited. The tester, who does not follow alice, cannot reply there.
- [ ] Alice changes it to "Nobody", then to "Anybody", from the post's menu. The Bluesky app follows each change after a reload.
- [ ] Alice sets "Who may quote it: Nobody but me". The Bluesky app shows quoting as disabled.
- [ ] Bob, here, cannot reply to alice's "Nobody" post. The reason is shown.
- [ ] The tester closes one of their posts to replies and another to quotes, in the Bluesky app. Alice's reply and alice's quote are each refused, with the reason.

### 3.4 What comes in

- [ ] The tester follows alice. Alice's Activities show the follow, and her followers list shows the tester.
- [ ] The tester likes, reposts, replies to and quotes alice's post, and mentions her. Each one reaches alice's Activities.
- [ ] Alice follows the tester. The tester's new post appears in alice's home timeline.
- [ ] Alice rings the bell on the tester's profile. The tester's next post reaches her notifications.

### 3.5 Reading Bluesky

- [ ] With a Jetstream endpoint set and `occ social:atproto:listen` running, a new post by an account alice follows appears in her home timeline within seconds. The card's "Jetstream listener" line says it is connected.
- [ ] A custom feed ("Discover", or any feed) opens as a timeline. A list does too.
- [ ] A starter pack link (`https://bsky.app/starter-pack/...`), pasted into search, opens here, and "Follow all" follows everybody in it.
- [ ] Discover shows Bluesky's trending topics and suggested accounts. The real AppView answers both; the dev one answers neither.
- [ ] A Bluesky account with Bluesky's blue check shows the check here.
- [ ] A link to a Bluesky feed, list or starter pack in a post shows as a card.

### 3.6 Bluesky apps signed in here

- [ ] Alice makes an app password in Settings → Bluesky. In the Bluesky app, signing in with hosting provider `https://<host>`, the handle `alice.<host>` and that password works. Posting from there shows up here.
- [ ] Signing in with OAuth works the same way (the app's sign-in without a password).
- [ ] With an app password made with "Allow direct messages", the Bluesky app's chat opens, and a message to the tester arrives.

### 3.6a Direct messages here

The development network has no chat service, so these lines are the only test of the chat bridge against Bluesky's.

- [ ] The tester follows alice and alice follows the tester. Alice writes the tester a direct message from Messages here. It arrives in the tester's Bluesky chat, without the `@` address, its link clickable.
- [ ] The tester answers in the Bluesky app. Within two minutes the answer is in alice's Messages, in the same conversation, with a notification.
- [ ] The tester deletes a message in the Bluesky app. It is gone here after the next read.
- [ ] The tester follows bob. Bob, here, writes one message to the tester and to a Mastodon account. The tester gets it on Bluesky, the Mastodon account over ActivityPub.

### 3.7 Blocks

- [ ] Alice blocks the tester here, with "Publish my blocks of Bluesky accounts" off. The tester notices nothing.
- [ ] Alice turns the switch on. The tester can no longer reply to or quote alice in the Bluesky app.
- [ ] Alice unblocks the tester. Both work again.

### 3.8 Staying in step

- [ ] After a day, the admin page shows the relay connected, with a lag of seconds.
- [ ] The probe still passes, with the relay at the same rev as the PDS, or one behind just after a post.
- [ ] The daemon is restarted. Posts made while it was down reach Bluesky afterwards.

### 3.9 Moving, last and with throwaway accounts only

- [ ] A throwaway Bluesky account moves here: Migration → "Bring your Bluesky account here", signed in as a fresh Nextcloud account. Its followers on Bluesky still follow it, and its posts are in its timeline.
- [ ] It moves away again, to bsky.social, from the Migration page.

## 4. Afterwards

- Write down:
  - the probe's output;
  - the server version and the app version;
  - every unticked line, with the time, a screenshot and `nextcloud.log` from around then.
- A finding is an issue in the app's repository, one per finding. A security finding is not an issue: report it as the security policy says.
- To end the test: switch Bluesky off on the admin page. The test accounts' DIDs stay in `plc.directory` and their repositories stop being served. Deactivate the Bluesky test account at bsky.app as well.
