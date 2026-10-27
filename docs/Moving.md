<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Moving to Aloha Social, and moving away

One page for the whole move, in the order it happens. Each button it names
is described on its own in the [User Guide](User-Guide.md#managing-your-account) and
each route in the [API reference](API.md#migration); this is the order, and
what does not move.

## What a move is, on the fediverse

A move carries **followers**, never posts. Your old server tells every server
that knows you to follow your new account instead; your posts stay where they
were written, under the old server's addresses. That is how Mastodon does it
too, and it is the right design: a post is signed by the server it was written
on, and nobody else can re-issue it.

Two things follow from that. Your new account can **copy** your old posts, as
new posts of its own, so your profile here is not empty — but they are copies,
nothing is sent to anybody, and the replies and boosts on the originals stay on
the originals. And the old server has to be **running** for any of this: it
sends the move, and it serves your posts and the people you follow. An account
on a server that is gone cannot be moved; only a follows file exported in time
survives it.

The same happens in the other direction when somebody *you* follow moves:
their old server sends the move here, this server checks that the new account
names the old one, follows the new account on your behalf, and shows the
redirect on the old account's profile from then on. Their old posts stay on
the old account, where their old server can still edit or delete them. If you
already followed the new account too, you keep that one follow.

## Moving to Aloha Social

Three steps, two of them here.

1. **Make your account here**, on the setup screen. On its third step,
   *Already somewhere else?*, type the handle of your old account —
   `@you@mastodon.example` — and press **Move here**. You can do the same
   later, at the top of the **Migration** page, where you are first shown
   who that account is and what its server lets us read.

   What happens: this account is marked as also being the old one (the
   `alsoKnownAs` the old server will ask for), and in the background everyone
   the old account follows is followed from here and its public posts are
   written here as yours, dated when you wrote them, with their pictures.
   Nothing is sent to anybody. The list on the Migration page shows where the
   run has got to. Posts you write on the old account after that are not
   picked up by anything: **Copy newer posts**, under the finished run, runs
   the copy again and brings over only what is new.

2. **If the old server hides your follows or your posts** — Mastodon does
   when you turned on *Hide your social graph*, Pixelfed always does — the
   page says so, and the file imports further down are for that: upload the
   old server's `following_accounts.csv` (Pixelfed: `pixelfed-following.json`)
   and, for the posts, its archive. Blocks, mutes, lists, bookmarks and blocked
   domains come the same way.
   Every upload runs in the background too.

3. **On the old server, move your followers here.** On Mastodon that is
   *Preferences → Account → Move to a different account*; enter your handle
   here. The Migration page shows it once the run is done. Your old server
   then tells every server that knows you to follow this account instead, and
   you are moved.

Pixelfed cannot do step 3: it has no account migration. Your follows and your
posts come over; your followers have to follow you again.

**From another Aloha Social server, step 3 is here too.** Mastodon has no way
to ask for a move from outside, which is why the last step is always done on
the old server. Another Aloha Social does: once the run is through, the
Migration page offers **Finish the move from here**. You are sent to your old
Nextcloud to log in and to agree — the only permission asked for is to move
the account, nothing else — and the old server moves your followers here.
It checks, as it would for its own button, that this account names the old
one; the thirty days between moves apply there as everywhere.

## Moving away

1. **On the new account, name this one** as an account you also answer to.
   On Mastodon: *Preferences → Account → Moving from a different account*,
   with your handle here.

2. **Here, in Migration → Move your account away**, type the new
   account's handle, type your own handle to confirm, and give your password
   when asked. Every server that knows you is told to follow the new account
   instead; this account is marked as moved, shows where you went, and refuses
   to post or follow while it stays moved. Your posts stay here.

You can move again after thirty days. **Undo the move** takes the redirect off
and lets you post and follow from here again — but your followers do not come
back by themselves, because their servers acted on the move when it arrived.
Take an export from the same page first if you want a copy of everything.

An administrator can move an account for somebody with
`occ social:account:move`, which skips the thirty days.

**When a step is refused**, the page says why in your language and what to
do next: the old account cannot be found, the new one does not name this one
yet, an import of that kind is still running. The server's own words, in
English, are behind *Details*, for whoever helps you. The codes behind those
sentences are listed in the [API reference](API.md#migration-error-codes).

## Your Bluesky account

If this server puts you on Bluesky too, your Bluesky account is a second
account of the same person, and it can move on its own.

**Moving it to another Bluesky server.** In *Migration → Move
your Bluesky account away*, name the server, the handle you want there (one
ending in a domain that server gives out), an e-mail address and a password
for the account there. The account is made there at once; your posts,
pictures and settings are copied in the background; then your account is
pointed at the new server and switched on there. Your followers on Bluesky
follow you there without doing anything.

- **Who controls the account afterwards.** You, with your recovery phrase if
  you made one, and the new server. This server keeps no say. Without a
  recovery phrase only the new server controls the account, and the page
  asks you to confirm that before the move starts.
- **Posts you write here during the move** wait, and go along right before
  your account is pointed at the new server.
- **Missing pictures.** Before the switch the new server is asked whether
  everything arrived. If pictures or videos are missing, the move waits for
  you: copy again, or switch without them.
- **Calling it off.** Until your account is pointed at the new server you can
  call the move off. The account made at the new server stays there,
  switched off; delete it there. Once the switch happened, the move can only
  be finished: *Try again* goes on from where it stopped.
- **Telling your other followers.** You can ask for one public post, or a
  profile field *Also at*, saying where your Bluesky account went. Both are
  ordinary posts and fields, yours to edit or delete.

**Following the same people without moving.** If your Bluesky account lives
elsewhere and stays there, you can still follow from here everyone it
follows: give its handle, and the people it follows are followed from your
account here in the background (its follows are public, so no password is
asked). Your Bluesky followers do not come along; only a move brings them.

**Moving your Fediverse account away** leaves the Bluesky account behind on
this server, and a moved account posts nothing more here. So the move asks
what becomes of it: switch it off (the default — it is shown as inactive on
Bluesky; your handle stays yours and Settings can switch it on again), keep
it as it is, or say that it moves too — which is only possible once its own
move above is done or under way. `occ social:account:move` takes the same
choice as `--bluesky`.

## If this server goes away

**On the fediverse**, an account on a server that is gone cannot be moved:
the old server has to send the move. Take the export from **Settings →
Migration** while the server runs; its `following_accounts.csv` brings your
follows to a new account anywhere, and the archive keeps your posts.

**On Bluesky** your account is a DID, `did:plc:…`, registered with the PLC
directory, and whoever holds one of its *rotation keys* can point it at
another server — this server's own key, and, if you made one, your
**recovery phrase** (Settings, Bluesky section). That is what the phrase is
for, and it works without this server.

**While the server runs, keep two files**, both in Settings' Bluesky section:
**Download your Bluesky account** (your repository as `repo.car`, the
pictures and videos under `blobs/`, your DID's history; the archive from
the Migration page has the same under `social/bluesky/`) and **Download the
recovery tool**, one HTML page that also comes inside that archive as
`recovery/bluesky-recovery-tool.html`. Without the archive the posts are gone
with the server; the DID and your followers still move with the phrase.

**With the recovery tool.** Open the saved page in a browser — it needs no
server of its own, and nothing leaves it except the requests you press a
button for — and go through its steps:

1. **Your key**: type the twelve words (or the key, hex or `z…`). It shows
   the `did:key` they stand for.
2. **Your identifier**: your DID; *Read its latest operation* asks the PLC
   directory (or paste it). The page checks that your key is one of its
   rotation keys.
3. **A signing key of your own** — only when the old server cannot sign
   any more: the page makes one, signs with your recovery key an operation
   that names it, and sends it to the directory when you press *Send*.
   Keep the signing key it shows until the move is done.
4. **The new server**: its address; *Look the server up* fills in its DID;
   handle, e-mail and password for the account there; *Create the account
   with your identifier* signs the request with your signing key.
5. **Your posts and pictures**: pick `repo.car` and the files of `blobs/`
   from the unpacked archive; they are uploaded to the new account.
6. **Point your identifier at the new server**: *Ask the new server for its
   recommended credentials*, *Build and sign the operation* (your key first,
   then the new server's), and *Send it to the PLC directory* — or copy it
   and send it any other way.
7. *Activate the account on the new server.*

The page is served here too, at `/apps/social/bluesky-recovery`, for
when this server still runs. It signs exactly as this server does — the
same bytes, the same CID, a signature this server's code accepts, which the
tests check against each other — but it has not been run end to end against
the live directory from this app's tests.

**With [goat](https://github.com/bluesky-social/goat)**, the same without a
browser:

1. **Turn the phrase into its key**, offline:
   `python3 recovery/recovery-key.py recovery/bip39-english.txt`, then type
   the twelve words. It prints the key as hex and as multibase (`z…`). If
   you saved the key when you made the phrase, you already have it.
2. **Give the DID a signing key of your own** and drop this server's
   rotation key (the other `did:key` in `rotationKeys` of the last operation
   in `plc-log.json`): `goat key generate --type secp256k1`, then
   `goat plc update --atproto-key <its did:key> --remove-rotation-key <this server's key> <your DID> > op.json`,
   `goat plc sign --plc-signing-key <your recovery key> op.json > signed.json`
   and `goat plc submit --did <your DID> signed.json`.
3. **Create the account on the new server with your DID**:
   `goat account service-auth-offline --atproto-signing-key <the new signing key> --iss <your DID> --aud <the new server's did:web> --lxm com.atproto.server.createAccount`
   gives a token for
   `goat account create --pds-host https://<new server> --existing-did <your DID> --service-auth <token> --handle … --email … --password …`.
4. **Bring your data**: `goat account login`, `goat repo import repo.car`,
   and `goat blob upload blobs/<cid>` for each blob `goat account missing-blobs`
   lists.
5. **Point the DID at the new server**: `goat account plc recommended` names
   its signing key, rotation key, handle and address; build the operation
   with `goat plc update` (`--atproto-key`, `--pds`, `--handle`), put your
   recovery key first in its `rotationKeys` and the new server's after it,
   sign it with your recovery key and submit it as in step 2. Then
   `goat account activate`.

The `README.txt` in the archive carries both with your DID filled in. The
goat steps follow goat's own commands as of October 2026 (`goat <command> --help`
has the flags).

**The 72-hour rule.** This server's key ranks above your recovery key on the
DID. For 72 hours after an operation signed with your phrase, a key that
ranks above it can undo it — so while this server still exists, its
administrator can reverse a change you made with the phrase, a move
included. Once the server is gone, nobody can. A move made from this
server's own **Move your Bluesky account away** puts your recovery key first
on the new server, so the order is yours from then on.

## What does not move, whatever you do

- **Posts.** Copied, never moved: see above.
- **Followers from a server that does not implement `Move`.** Pixelfed is the
  one that matters.
- **Your handle.** `@you@old.example` stays on the old server; here you are
  `@you@this.server`. Keeping a handle across a change of domain is a server
  migration, not an account one — see
  [Mastodon-Compatibility.md](Mastodon-Compatibility.md).
- **Direct messages and boosts** are left out of the post copy on purpose.
