<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Moving to Aloha Social, and moving away

One page for the whole move, in the order it happens. Each button it names
is described on its own in the [User Guide](User-Guide.md#settings) and
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

## Moving to Aloha Social

Three steps, two of them here.

1. **Make your account here**, on the setup screen. On its third step,
   *Already somewhere else?*, type the handle of your old account —
   `@you@mastodon.example` — and press **Move here**. You can do the same
   later, at the top of **Settings → Migration**, where you are first shown
   who that account is and what its server lets us read.

   What happens: this account is marked as also being the old one (the
   `alsoKnownAs` the old server will ask for), and in the background everyone
   the old account follows is followed from here and its public posts are
   written here as yours, dated when you wrote them, with their pictures.
   Nothing is sent to anybody. The list on the Migration page shows where the
   run has got to.

2. **If the old server hides your follows or your posts** — Mastodon does
   when you turned on *Hide your social graph*, Pixelfed always does — the
   page says so, and the file imports further down are for that: upload the
   old server's `following_accounts.csv` (Pixelfed: `pixelfed-following.json`)
   and, for the posts, its archive. Blocks, mutes and lists come the same way.
   Every upload runs in the background too.

3. **On the old server, move your followers here.** On Mastodon that is
   *Preferences → Account → Move to a different account*; enter your handle
   here. The Migration page shows it once the run is done. Your old server
   then tells every server that knows you to follow this account instead, and
   you are moved.

Pixelfed cannot do step 3: it has no account migration. Your follows and your
posts come over; your followers have to follow you again.

## Moving away

1. **On the new account, name this one** as an account you also answer to.
   On Mastodon: *Preferences → Account → Moving from a different account*,
   with your handle here.

2. **Here, in Settings → Migration → Move your account away**, type the new
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

## What does not move, whatever you do

- **Posts.** Copied, never moved: see above.
- **Followers from a server that does not implement `Move`.** Pixelfed is the
  one that matters.
- **Your handle.** `@you@old.example` stays on the old server; here you are
  `@you@this.server`. Keeping a handle across a change of domain is a server
  migration, not an account one — see
  [Mastodon-Compatibility.md](Mastodon-Compatibility.md#54-what-would-have-to-be-written).
- **Direct messages and boosts** are left out of the post copy on purpose.
