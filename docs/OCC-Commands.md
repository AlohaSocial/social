# Aloha Social — OCC Commands Reference

All commands are invoked via `php occ <command>` from the Nextcloud root directory.

This page documents the commands the app registers in
`appinfo/info.xml`. Every command extends `OCA\Social\Command\SocialCommand`,
the app's own base class, which extends Symfony's `Command` — nothing here
reaches into the server's private `core/`. That base class declares the generic
`--output plain|json|json_pretty` option, so the option exists on all of them,
but only `social:timeline` reads it (see below).

---

## Account Management

### `social:account:create`

Create the Aloha Social actor for an existing Nextcloud user.

```
php occ social:account:create [--handle HANDLE] <userId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud username of the account |

| Option | Value | Description |
|--------|-------|-------------|
| `--handle` | required | Aloha Social handle. If omitted, the `userId` is used as the handle. |

Fails with `Unknown user` if no such Nextcloud user exists. On success the command
prints nothing.

---

### `social:account:delete`

Delete a local Aloha Social account.

```
php occ social:account:delete <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `account` | Yes | Local Aloha Social account (the handle / preferred username) |

Prints nothing on success.

---

### `social:account:following`

Follow or unfollow an account on behalf of a local user.

```
php occ social:account:following [--local] [--unfollow] <userId> <account>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user performing the action |
| `account` | Yes | Account to follow (e.g. `user@example.org`) |

| Option | Value | Description |
|--------|-------|-------------|
| `--local` | none | Resolve `account` as a **local** account first, and use its canonical account name |
| `--unfollow` | none | Send an `Undo`/unfollow instead of a follow |

Prints progress lines (`Following account...`, the resolved local actor id and nid,
then the result). This is the only command that returns exit code `1` on a handled
failure; the rest let the exception surface.

---

### `social:account:alias`

Since 0.20.6 an account's own aliases are also settable by its owner, in **Settings → Migration → Accounts you also answer to** (`/api/v1/migration/aliases`) — setting one federates nothing. This command stays for an administrator acting on somebody else's account, and for scripting a migration.

Manage the `alsoKnownAs` list of a local account: the actor ids it also answers
to. Setting one is the first step of moving an account **to** this server — a
Mastodon (or other) server refuses to start a Move towards an account that does
not list the moving one here.

```
php occ social:account:alias [--add URL] [--remove URL] [--list] <userId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor is aliased |

| Option | Value | Description |
|--------|-------|-------------|
| `--add` | required | The old account, as its `alice@mastodon.example` handle (resolved through WebFinger) or its `https://mastodon.example/users/alice` actor id; the id is what is stored. Adding one that is already listed changes nothing. |
| `--remove` | required | Actor id to remove. Removing one that is not listed changes nothing. |
| `--list` | none | Print the current list. This is also what happens with no option at all. |

`--add` and `--remove` cannot be combined in one run. Every change refreshes the
actor cache, so the new list is on the actor document at once; the remote server
reads it when the Move is started there.

Prints `added <url>` / `removed <url>` followed by the list, or `no alias set.`.
Exit code `1` when `userId` has no actor or the value is not an `http(s)://` URL.

---

### `social:account:move`

Move a local account to one on another server, the way Mastodon's account
migration works: the new account has to list this one in its `alsoKnownAs`, every
follower is sent a `Move`, and the account here is marked as moved.

Since 0.26.113 a person can do this themselves, in **Settings → Migration → Move
your account away** (`POST /api/v1/migration/move`), behind their password, their
own handle typed back and a thirty-day cooldown between moves. This command stays
for an administrator acting on somebody else's account, and it **skips the
cooldown**. Undoing a move (clearing `movedTo`) is on the same page.

```
php occ social:account:move [-f|--force] <userId> <target>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor moves away |
| `target` | Yes | The new account, as `@you@new.example` or its `https://…` actor id |

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Do not ask for confirmation (required with `--no-interaction`) |

What happens, in order:

1. The target is fetched fresh from its server (not read from the cache) and
   must list this account's actor id in `alsoKnownAs` — the same check
   `MoveInterface` applies to an incoming Move. Without it the command stops
   with exit code `1` and nothing changes; add the alias on the new account
   first (in Mastodon: *Preferences → Account → Moving from a different
   account*).
2. A `Move{actor, object: this actor, target}` is signed and queued through the
   ordinary delivery queue to every follower's inbox and to the new account's
   inbox. Servers that support migration re-follow the target for their users
   and drop the follow of the old account.
3. `movedTo` is recorded on the actor: it appears on the actor document and as
   `moved` on the account entity, so clients show the "has moved" banner.
4. Followers **on this server** never receive the Move (deliveries to ourselves
   are dropped), so the command follows the new account on their behalf through
   the ordinary follow path. One follower that cannot be re-followed is logged
   and does not stop the others.

The command asks for confirmation first; under `--no-interaction` it refuses
unless `--force` is given, rather than moving an account because nobody was
there to say no. Posts stay where they are — like Mastodon, a move carries the
followers, not the content.

---

### `social:account:import-posts`

Bring an account's own posts over from the server it wrote them on, with their
pictures: the same importer the Migration page uses, for the archives a browser
cannot upload.

```
php occ social:account:import-posts [--no-media] [--limit LIMIT] <userId> <archive>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose account the posts are written as |
| `archive` | Yes | Path to the export: a zip with an `outbox.json` (this app's, under `social/`; Mastodon's and GoToSocial's, at the root), an **Instagram** archive (the JSON one — `content/posts_*.json` and the reels beside them, with the pictures under `media/`), or a JSON export on its own — an `outbox.json`, or Pixelfed's `pixelfed-statuses.json` |

| Option | Value | Description |
|--------|-------|-------------|
| `--no-media` | none | Do not fetch the pictures an export names only by their address. The ones inside the archive are still restored |
| `--limit` | number | How many posts to write at most (default and ceiling 2000). The command says when it stopped there; run it again to carry on |

Each post is written as a **new local post** of that account, dated when it was
written. **Nothing is federated** — not one delivery is queued — because
re-publishing somebody's years of posts would put them into the timeline of
every person who follows them, on every server, at once. The original id is
remembered in `social_import_post`, so running the command twice over the same
archive writes nothing the second time, and a reply keeps its parent where the
archive holds both. Deleting an imported post forgets it as well, so a later
run of the same archive brings that post over again.

What is left alone: boosts (somebody else's post), direct messages (addressed
to accounts on the old server, so a copy here would be addressed to nobody),
and items with neither words nor pictures. Every file goes through the same
upload path a post's own attachment does, so an imported picture is stripped of
its metadata and held to the sizes and types this instance accepts.

A picture named only by an address is fetched from the server it is still on,
which means that server learns the import is happening and that it has to be
running. `--no-media` is the way to import the words without either.

### `social:account:import-follows`

Follow every account of a follows export — Mastodon's `following_accounts.csv`
or Pixelfed's `pixelfed-following.json` — from a local account: the other half
of moving an account **to** this server.

```
php occ social:account:import-follows <userId> <csv>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose actor follows the accounts |
| `csv` | Yes | Path to the export. The current Mastodon format with the header `Account address,Show boosts,Notify on new posts,Languages` is read by its `Account address` column wherever it is; the older format — one handle per line, no header — works too. A file that parses as JSON is read as Pixelfed's export: an array of actor URLs (or handles, or objects naming one), each URL fetched and followed as the actor it resolves to. |

Each entry goes through the same path as `social:account:following`: the
account is resolved (WebFinger for a handle, an actor fetch for a URL), a
`Follow` is queued, and an account already followed is left alone. A leading
`@` is dropped, repeats are followed once, and an entry that is neither a
`user@host` handle nor an actor URL is ignored. The other columns (boosts,
notifications, languages) are not imported.

One handle that fails — an unreachable instance, an account that no longer
exists — is reported and does not stop the rest. The command prints how many
were followed, how many were skipped (the importing account's own handle) and
how many failed, each with its reason. Exit code `1` when the file cannot be
read, or when something was asked for and none of it could be followed.

---

## Post / Note Management

### `social:note:create`

Create a note (post) as a given user and federate it.

```
php occ social:note:create [-r|--replyTo REPLYTO] [-t|--to TO] [-y|--type TYPE] [-g|--hashtag HASHTAG] <user_id> <content>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID of the author |
| `content` | Yes | Content of the post |

| Option | Value | Description |
|--------|-------|-------------|
| `-r`, `--replyTo` | optional | Id of the post this one replies to |
| `-t`, `--to` | optional | A single mentioned account |
| `-y`, `--type` | optional | Visibility: `public`, `unlisted`, `followers` (Mastodon's `private` is accepted as a synonym) or `direct`. Anything else — including omitting the option — becomes **`direct`**, the most restrictive option, because `Post::setType()` maps a value it does not know through `Stream::visibilityFromClient()`. Note the option's own `--help` text still says "public (default)", which is not what happens. |
| `-g`, `--hashtag` | optional | A single hashtag, without the leading `#` |

`--to` and `--hashtag` each accept only one value. In addition,
`PostService::fixRecipientAndHashtags()` scans the
content for `@mentions` and `#hashtags` and adds those too.

Prints the resulting activity as pretty JSON followed by `token: <request token>`
(written with `echo`, so `--output json` does not change it).

---

### `social:note:boost`

Boost (`Announce`) a note, or undo a boost.

```
php occ social:note:boost [--unboost] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID boosting the note |
| `note_id` | Yes | Id of the note |

| Option | Value | Description |
|--------|-------|-------------|
| `--unboost` | none | Undo the boost instead of creating one |

Prints the activity as pretty JSON plus `token: <request token>`.

---

### `social:note:like`

Like a note, or undo a like.

```
php occ social:note:like [--unlike] <user_id> <note_id>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user_id` | Yes | Nextcloud user ID liking the note |
| `note_id` | Yes | Id of the note |

| Option | Value | Description |
|--------|-------|-------------|
| `--unlike` | none | Undo the like instead of creating one |

Prints the activity as pretty JSON plus `token: <request token>`.

---

## Timeline & Stream

### `social:timeline`

Print a timeline as seen by a given local viewer.

```
php occ social:timeline [--local] [--min_id MIN] [--max_id MAX] [--since SINCE] [--limit N] [--account ACCOUNT] [--crop N] <userId> <timeline>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `userId` | Yes | Nextcloud user whose view is used. Fails with `Unknown user` if unknown. |
| `timeline` | Yes | See the table of supported values below |

| Option | Value | Default | Description |
|--------|-------|---------|-------------|
| `--local` | none | off | Restrict to local content |
| `--min_id` | required | `0` | Pagination bound: a status id (`nid`); rows newer than it |
| `--max_id` | required | `0` | Pagination bound: a status id (`nid`); rows older than it |
| `--since` | required | `0` | Pagination bound: a status id (`nid`); rows newer than it |
| `--limit` | required | `5` | Number of items |
| `--account` | required | `''` | A **local** account, resolved with `CacheActorService::getFromLocalAccount()`; used as the account filter |
| `--crop` | required | `0` | Truncate the printed content to N characters (`0` = no cropping) |

The three bounds are taken as the decimal strings they are typed as, so a status id wider than a PHP integer is not rounded; one that is not a decimal integer fails with `--<option> must be a status id`.

Supported `timeline` values (the `switch` in `StreamRequest::getTimeline()`):

| Value | Meaning |
|-------|---------|
| `home` | Own posts plus posts of followed accounts |
| `public` | Public timeline |
| `direct` | Direct messages |
| `account` | Posts of one account (combine with `--account`) |
| `favourites` | Liked posts |
| `bookmarks` | Posts the viewer bookmarked |
| `notifications` | Notifications (rendered in the notification format) |
| `#<tag>` | A leading `#` selects the hashtag timeline for `<tag>` |

Matching is case-insensitive. `ProbeOptions` also defines `followers` and
`following`, but `getTimeline()` has no case for them and silently returns an
empty list. Any other value behaves the same way.

Output is a table (`Nid`, `Id`, `Source`, `Type`, `Author`, `Content`).
`--output json` switches this command to a JSON dump of the streams.

---

### `social:details`

Print who can see one stream item and which timelines it lands on.

```
php occ social:details [--json] <streamId>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `streamId` | Yes | Id of the stream item. Fails with `Unknown item` if it does not exist. |

| Option | Value | Description |
|--------|-------|-------------|
| `--json` | none | Dump the details object as pretty JSON |

Without `--json` it prints the item id, author and type, then `Affected Timelines`
with the `Home` viewers, the `Direct` viewers, and the `Public` / `Federated` flags.
This command uses its own `--json` flag; the inherited `--output` option is ignored.

Note the command name is `social:details`, not `social:stream:details`, even though
the class is `OCA\Social\Command\StreamDetails`.

---

### `social:stream:prune`

Deletes remote statuses older than the retention period, together with their
recipient/action/tag rows and cached attachments.

```bash
php occ social:stream:prune [-d|--days DAYS] [--dry-run]
```

- Without `--days`, the `retention_days` app setting decides the period; `0`
  (the default) disables status retention and no status is touched.
- Two sweeps run on every call whatever `retention_days` says: the queue rows
  nothing will act on again (exhausted deliveries, finished cache items) are
  purged, and in-app notification rows older than `notification_retention_days`
  (default 90; `0` keeps them) are deleted. The notification count is printed
  on its own line.
- `--dry-run` only counts what would be deleted, including the notifications.
- A status is kept when a local user liked, boosted, replied to or bookmarked
  it, when a local user follows its author, when a local status replies to it
  or boosts it, or when it is a direct message. Local content is never touched.
- The same pruning runs from the `Cron\Cache` background job (bounded to 5000
  statuses per run) whenever `retention_days` is greater than 0; the admin can
  change the period in the Aloha Social section of the administration settings.

---

## Queue Management

### `social:worker`

Drain the queues continuously, instead of once every twelve minutes.

```
php occ social:worker [--once] [--max-seconds SECONDS] [--quiet-log]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--once` | none | Drain what is queued now and return, instead of waiting for more — what a cron entry wants |
| `--max-seconds` | int (0) | Stop after this long, so a supervisor can restart it; `0` runs until stopped |
| `--quiet-log` | none | Do not print a line per batch |

**Why it matters:** `Cron\Queue` runs every twelve minutes with a 300-second
budget. It delivers twenty servers at a time and takes batch after batch of 200
while time is left — up to 6,000 deliveries a run when peers answer within a
second, and about 200 when every batch runs into a dead peer's 30-second
timeout — and Nextcloud runs one `cron.php` at a time, so that is its ceiling.
An instance whose accounts are followed across tens of thousands of servers
reaches it with one popular post.

This is the same delivery in a loop that does not stop, one row at a time. A healthy peer answers in
a fraction of a second, so one worker moves thousands of rows an hour rather than
a thousand a day — and because claiming a row is already atomic (an
`UPDATE … WHERE status = standby` that throws when it loses the race),
**several workers may run at once** and will not collide. Run as many as the
instance needs, under systemd:

```ini
[Unit]
Description=Aloha Social delivery worker %i
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/php /var/www/html/occ social:worker --max-seconds=3600 --quiet-log
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`systemctl enable --now social-worker@{1..4}` for four of them. It finishes the
row in hand on `SIGTERM` rather than abandoning a delivery the receiving server
may already have taken, sleeps (with a growing wait) when there is nothing to do,
and hands rows back to standby when an attempt fails outside the delivery
service's own error handling — without that a killed worker leaves a row
`running`, which nothing retries until the stale reaper an hour later.

**The cron job is unchanged.** An instance that will not run a daemon keeps
exactly the behaviour it has; one that will gets the throughput.

---

### `social:atproto:serve`

Serve the Bluesky firehose: the WebSocket a relay subscribes to.

```
php occ social:atproto:serve [--bind ADDRESS:PORT] [--max-seconds SECONDS] [--once]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--bind` | string (127.0.0.1:8787) | Where to listen; the web server proxies `/xrpc/com.atproto.sync.subscribeRepos` here with the WebSocket upgrade |
| `--max-seconds` | int (0) | Stop after this long, so a supervisor can restart it; `0` runs until stopped |
| `--once` | none | Serve what is queued now to whoever is connected and return — what a test wants |

**Why it matters:** a PDS is only a PDS while something serves its event stream. The
web requests append frames to `social_atproto_event`; this process reads them in
order and hands each to every connected relay, replaying from the cursor a relay
reconnects with out of the 72-hour window the table keeps. Run it under systemd
(see [Admin.md](Admin.md#bluesky)); the setup checks say when it is not running.

### `social:atproto:identities`

Give every local account its Bluesky identity now, rather than on first need.

```
php occ social:atproto:identities [--user USER] [--list]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--user` | string | Only this Nextcloud user |
| `--list` | none | List the identities there are instead of making any |

An identity is a `did:plc` registered with the PLC directory, a handle
`alice.<host>` and a signing key, and the profile record is written with it. An
account that already has one is left alone, so the command is safe to run
again; a registration the directory refused is logged and resent by the
maintenance job and by `social:atproto:plc --repair`.

### `social:atproto:plc`

This app's log of PLC directory operations beside what the directory holds, or
the resend of what never got through.

```
php occ social:atproto:plc [DID] [--repair]
```

| Argument | Required | Description |
|----------|----------|-------------|
| `did` | No | The DID or handle to show; none with `--repair` |

| Option | Value | Description |
|--------|-------|-------------|
| `--repair` | none | Send every operation the directory never confirmed again |

### `social:atproto:resolve`

What a handle or DID is: the local identity, and the DID document the directory
serves.

```
php occ social:atproto:resolve IDENTIFIER
```

| Argument | Required | Description |
|----------|----------|-------------|
| `identifier` | Yes | A handle (`alice.social.example.com`) or a `did:plc` |

Exits 1 when neither this instance nor the directory knows it.

### `social:atproto:repo`

A user's Bluesky repository: its head commit, what it holds, and whether it
checks out.

```
php occ social:atproto:repo USER [--verify]
```

| Argument | Required | Description |
|----------|----------|-------------|
| `user` | Yes | The Nextcloud user id, the handle or the DID |

| Option | Value | Description |
|--------|-------|-------------|
| `--verify` | none | Recompute the Merkle search tree from the records and check the head's signature; exits 1 when either differs |

### `social:atproto:crawl`

Ask the configured relays to subscribe to this instance's firehose now.

```
php occ social:atproto:crawl
```

Sends `com.atproto.sync.requestCrawl` to every relay in `atproto_relays`. A relay
keeps the subscription from then on; this is for the first time, and for after
the daemon was down longer than the replay window.

### `social:atproto:rotate-key`

A new instance rotation key, and a PLC update for every identity so the new key
is the one in charge.

```
php occ social:atproto:rotate-key [--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--force` | none | Do it without asking |

The old key is kept, retired, for the PLC's 72-hour recovery window and dropped
by the maintenance job after it. Each identity gets one directory operation,
paced so the directory's limits are respected; one the directory does not
confirm is resent by the maintenance job.

### `social:queue:process`

Process both queues once: the outbound request queue (federation delivery) and the
stream queue (caching of not-yet-resolved incoming objects).

```
php occ social:queue:process
```

No arguments or options. Prints how many items are in each queue, how many are
processable right now, and a `.` per processed item.

---

### `social:queue:status`

Report the state of the outbound delivery queue, or dump the rows belonging to
one request token.

```
php occ social:queue:status [-t|--token TOKEN]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--token` | optional | Token of the request; without it, the whole queue is summarised |

With a token, each matching row is printed as one line of JSON.

Without one, the command prints how many deliveries are waiting and how many are
being sent, how many have already failed at least once and how many are close to
being abandoned (a delivery is dropped after 16 attempts), followed by the worst
instances — how many deliveries are stacked up for each, the highest attempt
count so far and when it was last tried. The same figures appear in the
Federation health section of the administration settings.

Then the half that matters most: what this instance has **given up on**. An
abandoned delivery used to appear nowhere — it left the failing count the moment
it was abandoned, so the queue looked healthiest exactly when a peer had been
lost for good. The count covers the last seven days, which is how long a
finished row is kept, and is followed by the same per-instance table under
"given up". `occ social:queue:retry --instance HOST` puts those deliveries back
in the queue once the reason they failed has been dealt with.

---

### `social:queue:retry`

Put queued deliveries back on standby, or drop them.

```
php occ social:queue:retry [-t|--token TOKEN] [--min-tries N] [-i|--instance HOST]
                           [--limit N] [--stream] [--flush] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--token` | optional | Act on one delivery only, by the token `social:queue:status` prints |
| `--min-tries` | int (1) | Without a token, act on the rows that have already failed at least this many times |
| `-i`, `--instance` | hostname | Act on the deliveries for one host only, including the ones already given up on. Cannot be combined with `--stream`, which holds what came in rather than what was going out |
| `--limit` | int (500) | How many rows one run touches at most; run it again to work through the rest |
| `--stream` | none | Act on the inbound stream queue (`social_stream_queue`) instead of the outbound delivery queue |
| `--flush` | none | Delete the matching rows instead of queueing them again |
| `-f`, `--force` | none | Do not ask for confirmation (required with `--no-interaction`) |

Retrying clears the attempt count and sets the row back to standby, so it gets the
full run of retries again on the next queue cron (or `occ social:queue:process`)
rather than being abandoned on its next failure. `--flush` deletes the rows: those
activities are never delivered, which is what you want for a delivery that will
never succeed — a peer that is gone, or an activity it refuses.

Rows that already succeeded are never touched, so a retry cannot send an activity
twice. Rows that were *abandoned* are: they are exactly what this command is for,
and they are still in the table for seven days after the drain gave up on them.
`--instance` is the form that answers `social:queue:status`, which names the
hosts this instance has stopped delivering to:

```
php occ social:queue:retry --instance gone.example
```

The host is matched in PHP — it lives inside the JSON `instance` column — so the
table is read in pages of 1000 rows until `--limit` matching rows are found.

The command prints how many rows matched, across how many delivery tokens
and with what spread of attempt counts, and asks before it changes anything.

---

## Development

### `social:benchmark`

Seeds a plausible amount of content and times the queries behind the timelines, because a query's cost cannot be judged on the couple of dozen rows a development instance holds. **For development instances only** — it writes thousands of rows.

```
php occ social:benchmark [--actors=200] [--notes=5000] [--follows=150] [--viewer=USER]
                         [--seed-only] [--time-only] [--clean] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--actors` | int (200) | Remote actors to seed |
| `--notes` | int (5000) | Public notes to seed, spread over the preceding weeks |
| `--follows` | int (150) | How many of those actors the viewer follows, which is what the home timeline joins through |
| `--followers` | int (0) | How many of those actors follow the viewer **back**, which is what delivery reads: one queue row per distinct inbox when the viewer posts. An instance seeded without it measures reads and says nothing about writes |
| `--viewer` | username | The local account the timelines are read as; the first local actor by default |
| `--seed-only` | none | Write the rows without timing anything |
| `--time-only` | none | Time what is already seeded |
| `--clean` | none | Delete everything the command wrote and nothing else |

Seeding writes **multi-row `INSERT`s directly**, not through the model layer: a
`Note` built and saved one at a time is three statements and a transaction per
row, about 400 rows a second, which makes ten million posts a seven-hour wait —
so nobody ever seeded enough to find out what the queries cost. The rows are
still *shaped* like real ones (the prim hashes, the recipient rows, the follower
collections), because a query plan is only worth measuring against rows the
planner sees the way it sees real data. The cost is that a column added to
`social_stream` later is one this command forgets to write; that is the right
trade for a development-only command and would be the wrong one anywhere else.
| `-f`, `--force` | none | Seed without asking (required with `--no-interaction`) |

Seeding says how many rows it is about to write and asks before writing any of
them; under `--no-interaction` it refuses unless `--force` is given, rather than
seeding a production database because nobody was there to say no. `--clean` and
`--time-only` write nothing and do not ask.

Every row it writes carries `benchmark.invalid` in its id, which is what `--clean` matches on. The reported time is the second run of each query, so it measures a served request rather than a cold cache.

---

## Cache

### `social:cache:refresh`

Run the cache maintenance steps once, printing a counter per step.

```
php occ social:cache:refresh [-f|--force] [--rotate-keys]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Refresh cached remote actors even if they are not due — including the ones whose refresh has been given up on after ten consecutive failures, so this is also how an instance that has come back is asked again straight away. Every attempt is recorded either way, and one that works clears the failure count |
| `--rotate-keys` | none | Renew the RSA key pair of local actors older than `AccountService::KEY_PAIR_LIFESPAN` (60) days. Blind rotation: no `Update` is federated, remote servers pick the new key up when they next fetch the actor (most do so after a failed signature check), so expect a short delivery hiccup. Deliberately opt-in and never run by the cron |

Steps and their output lines: local accounts deleted, local accounts regenerated,
remote accounts created, remote accounts updated, remote accounts details updated,
documents cached, hashtags updated. With `--rotate-keys`, `N key pairs refreshed`
is printed first.

Rotation is the only step that is opt-in, and it is the only way to rotate a key
pair: nothing else calls `AccountService::blindKeyRotation()`, and the cron never
does.

### `social:counts:refresh`

Ask each remote server what the counts of the posts it owns are right now, and
store what it says.

```
php occ social:counts:refresh [-f|--force] [-l|--limit LIMIT]
```

| Option | Value | Description |
|--------|-------|-------------|
| `-f`, `--force` | none | Ask about every remote post rather than only those not asked about in the last `RemoteCountService::TTL` (7200) seconds |
| `-l`, `--limit` | required | Stop after this many posts. `0`, the default, means all that are due, which is what a backfill wants |

A post's counts were a snapshot of the moment it was imported and nothing ever
looked again, so a status sitting at two likes an hour ago still reads as two.
This asks each post's origin server for the `likes` and `shares` totals of its
own document and replaces the stored figure with what comes back — the answer
is only taken if the document's `id` is the one that was asked for, and a post
that answers with nothing keeps the count it had and is asked about again at
the next window rather than every pass.

The stated total is split as `stated - local` before it is stored, so what is
displayed stays the origin's number while the likes, boosts and replies made
here keep counting on top of it. Without `--force` only the posts past the
window are asked about; with it the whole timeline is, which is the backfill
after a version that never refreshed counts at all.

Reply counts are the limit of this: ActivityPub documents publish
`replies.totalItems` on almost no server, so the reply figure is what this
instance holds and not a number the origin supplies.

The cron (`OCA\Social\Cron\Cache`) runs the same work on its own schedule for
at most a minute per pass, `RemoteCountService::BATCH` posts at a time, so this
command is for the other moments. It prints `N post(s) asked, M answered with a
count`.

### `social:media:retry`

Retry one remote attachment which was previously rejected by the media cache.
This is useful after an administrator raises a size limit or the remote origin
recovers from a temporary access problem.

```
php occ social:media:retry <remote_url>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `remote_url` | Yes | Exact HTTP(S) URL shown in the attachment's `remote_url` field |

The argument must be the exact HTTP(S) `remote_url` exposed by Aloha Social. The
command finds the cache row by that media URL and only resets a row with a
stored media error and no local copy; it does not touch healthy cached files or
retry every failed attachment at once. It clears the old error and caching
timestamp, then fetches that one document immediately through the usual MIME,
size, quota, blocklist, and image-decode checks. Exit code 0 means the copy was
stored. If it is still refused, the command reports failure and the new
rejection remains recorded for diagnosis.

### `social:media:recover`

Restore missing pictures on remote posts whose saved ActivityPub original still
contains attachments. It does not rewrite post text, audience or date.

```
php occ social:media:recover [--dry-run] [--limit LIMIT]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--dry-run` | none | List affected posts without downloading or changing anything |
| `--limit` | required | Examine at most this many candidate posts; `0` (default) examines all |

Only remote posts with an empty stored attachment list are candidates. For
each original attachment, the normal federated media importer validates the
URL and caches the file, then the command updates the post's attachment list
and Photos/Videos index. Posts without usable media remain unchanged and are
reported; run the command again after fixing a remote or local cache error.

### `social:media:posters`

Make the poster frames of videos that have none, so a client shows a still
instead of a black rectangle before anybody presses play.

```
php occ social:media:posters [--dry-run] [--limit LIMIT]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--dry-run` | none | Print the videos that have no poster and change nothing |
| `--limit` | required | Stop after this many videos. `0`, the default, means all of them |

A video uploaded here is given a still on its way in. Videos stored before that
existed, or while the server had no ffmpeg, have none and would never get one:
the frame is taken from the bytes as they are written and they are only written
once. This reads them back and takes it now — the same frame, the same JPEG and
the same `resized_copy` an upload gets, with the duration and the dimensions
written into the document's `meta` alongside it. **The video itself is not
touched**: nothing is transcoded, re-encoded or re-written.

Exits 1 without doing anything if ffmpeg is not on the server. A video ffmpeg
cannot read is counted, reported and left alone, so the run finishes and the
video can be tried again after whatever was wrong with it is fixed.

It is a command rather than a background job on purpose: it is a one-off after
an upgrade, it spends a subprocess and a temporary copy of each video, and an
instance with a large media library should choose when that happens.

### `social:team`

Give a Nextcloud group an account to post from.

```
php occ social:team <group> <username>
php occ social:team --list
php occ social:team --remove <username>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `group` | No | The Nextcloud group. Required unless `--list` or `--remove` is used |
| `username` | No | The handle the team posts under. Required unless `--list` or `--remove` is used |

| Option | Value | Description |
|--------|-------|-------------|
| `--list` | none | Show the team accounts there are, group by group |
| `--remove` | required | Stop a handle being a team account. The account itself stays; delete it with `social:account:delete` if that is what you want |

**What it is.** Pixelfed's answer to "several people, one voice" is its
`Group*` family: twenty models, still in beta, and a second social graph beside
the one it already has. Nextcloud's answer is the one it has had all along — a
group of people who already work together — and this gives that group an
account. It is the one thing in this comparison Nextcloud can do and Pixelfed
cannot, because Pixelfed has no idea who works with whom.

A team account is **an actor like any other**: a key pair, a followers
collection, an inbox and an outbox, followable from Mastodon and Pixelfed,
moderatable and suspendable. What is new is only who may speak as it — and that
is whoever is in the group, asked live every time rather than copied into a list
that would drift.

**A command rather than a route, deliberately.** Creating an actor makes an
address other servers will follow, cache and keep, which is an administrator's
decision. Posting as one afterwards needs no administrator at all: everybody in
the group finds it in the composer.

Who wrote each post from a team account is recorded and shown to the team and to
moderators, and to nobody else — outside the team, one voice is the point of
having a team account; inside it, and in a report, the trail is.

A group that does not exist, and one that already has an account, are each
refused by name.

### `social:media:transcode`

Convert stored videos to H.264 in an MP4, which is the one format the rest of
the network plays.

```
php occ social:media:transcode [--limit LIMIT]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--limit` | required | Stop after this many videos. 25 by default |

**Why it matters:** Pixelfed's default `media_types` accepts `video/mp4` and
nothing else, so every `video/quicktime` posted from here — which is every video
straight off an iPhone — is dropped by its inbox without a word to anybody.
Safari will not play WebM either, and only Safari plays the HEVC that phones
write into an `.mp4`, so an MP4 whose video ffprobe does not report as H.264 is
converted too. An H.264 MP4 is never re-encoded; without ffprobe an MP4 is left
as it is.

On by default, and nothing happens on a server without ffmpeg. An administrator
can switch it off — re-encoding is lossy, and **the converted file replaces the
original** — under **Administration → Aloha Social → Server → Convert videos to MP4
in the background**, or with `occ config:app:set social video_transcode
--value=0`. The command exits 1 and changes nothing when it is off, or when the
server has no ffmpeg.

A new post does not wait for this command or the sweep: the video it carries is
converted by a job of its own at the next cron run, and the post's delivery is
held until then (at most ten minutes). The same work also runs by itself in the
background for everything else, one video every quarter of an hour, which is
about a hundred a day — fast enough that a backlog clears and slow enough that a
server converting one is never the reason its cron is late. An MP4 found to be
H.264 costs a copy and a probe rather than a conversion, and a run reads up to
five of those. This command is for an administrator who has just installed
ffmpeg and would rather not wait a week. Posts that went out before their video
was converted are not sent again.

### `social:media:ladder`

Write stored videos at a ladder of smaller sizes, as HLS, so a player can pick
the one that fits the connection.

```
php occ social:media:ladder [--limit LIMIT]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--limit` | required | Stop after this many videos. 10 by default |

**Why it matters:** a stored video is one file at whatever height it was
uploaded at, and a reader on a phone on a train downloads the 1080p of it or
nothing. A ladder is the same video written two or three more times, smaller,
plus a playlist that lets the player move between them as the connection
changes. It is also the shape PeerTube publishes, so a laddered video reaches a
PeerTube reader the way a native one does.

Each rung is **one file**: `-hls_flags single_file` writes the whole rendition
as a fragmented MP4 and the playlist addresses each segment as a byte range into
it, so a forty-minute video is three files rather than a thousand. Rungs at or
above a video's own height are skipped rather than upscaled.

Off unless an administrator turned it on: **Administration → Aloha Social → Server →
Build a ladder of video sizes**, or `occ config:app:set social video_ladder
--value=1`. Which heights, with `occ config:app:set social video_ladder_heights
--value=360,720,1080`. The command exits 1 and changes nothing when it is off,
or when the server has no ffmpeg and ffprobe.

Slower per video than `social:media:transcode` by however many rungs the ladder
has — worth knowing before starting it on a library of ten thousand. The
background job does one video every half-hour.

The converted file is written before the row is pointed at it and the original is
deleted last, so a failure anywhere leaves a document pointing at a file that
exists, and the posts carrying the video are pointed at the new file before the
original goes. A video ffmpeg cannot read is recorded as tried and left alone
rather than retried for ever, and one that is already an H.264 MP4 is passed
over without being re-encoded into a second generation of loss.

### `social:media:usage`

Report what the app's media occupies on disk, split into what was uploaded here
and what was cached off other servers.

```
php occ social:media:usage
```

No options of its own. `--output=json` (or `json_pretty`) prints the same
figures as a structure, for a monitoring script.

The two halves are very different things: an upload is the only copy there is,
and a cached remote file is a copy that can be thrown away and fetched again.
Each half is broken down into attachments and the avatars and headers of
accounts. Every row of `social_cache_doc` is walked and the size of each stored
copy — the original and the resized one — is read from appdata, file by file,
rather than taken from anything the row claims:

```
12043 document row(s) in social_cache_doc

Uploaded here                   842 files     2.1 GiB
  attachments                   808 files     2.0 GiB
  avatars and headers            34 files    41.3 MiB
Cached from other servers     19664 files     8.7 GiB
  attachments                 15912 files     8.4 GiB
  avatars and headers          3752 files   311.0 MiB

Total on disk                 20506 files    10.8 GiB
```

Three counts are printed after the total when they are not zero: copies that are
*streamed* (a pointer at a file on the server that holds it, so no bytes here),
copies served from Nextcloud's own avatar store rather than by this app, and
copies a row names that are **not in appdata** — reported rather than counted as
zero bytes, because a cached remote one is simply fetched again when it is next
asked for.

No quota accounting: this is what is on disk, not what anybody is allowed. It
also does not walk appdata looking for files no row names — the path of a copy
is derived from its own name, so finding an orphan means listing a four-level
tree with a directory per file, and there is no safe way to delete a file this
app cannot name.

The number to read it against is `cache_actor_days` (default 180), which is how
long a cached remote account nobody here refers to survives before the cache
cron evicts it with its avatar; `occ config:app:set social cache_actor_days
--value 0` turns that sweep off.

---

## Federation

### `social:fediverse`

Inspect and change the Fediverse access list.

```
php occ social:fediverse [-t|--type TYPE] [--dry-run] [-f|--force] [<action>] [<address>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `''` | One of `list`, `add`, `remove`, `import`, `test`, `reset`, `silence`, `unsilence`, `silenced`, or empty |
| `address` | No | `''` | Host for single-address actions, or a readable CSV path for `import` |

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--type` | required | Set the access type. Only `all_but` (deny-list, the default) and `none_but` (allow-list) are accepted; anything else throws `invalid type` (`FediverseService::setAccessType()`). |
| `--dry-run` | none | For `import`: read and validate the CSV, print the domains it would add, and change nothing. |
| `-f`, `--force` | none | For `import`: skip the confirmation prompt. Required with `--no-interaction`; without it a non-interactive run prints `Refusing to run non-interactively without --force` and returns 1. |

Passing `--type` **sets the type and exits** — the `action` argument is not executed
in the same invocation (`Fediverse::typeAccess()` returns true and the command
returns). Without `--type`, the
command first prints the current access type and then runs the action:

| Action | Effect |
|--------|--------|
| _(empty)_ | Print the access list under `- List:` |
| `list` | Print `- Known address:` followed by the access list |
| `add <address>` | Add the address to the list |
| `remove <address>` | Remove the address from the list |
| `import <csv_file>` | Read domains from the CSV's first column and add them to the existing `all_but` block list, after asking |
| `test <address>` | Print `Authorized` or `Unauthorized` for that address |
| `reset` | Empty the list |
| `silence <address>` | Silence the instance: keep it out of the public, global and hashtag timelines |
| `unsilence <address>` | Lift the silence |
| `silenced` | Print the silenced instances under `- Silenced:` |

`import` accepts a first-column `#domain`/`domain` header or a plain
one-domain-per-line file. It validates the entire file before mutation, skips
duplicates, caps an import at 10,000 unique domains and the file at 8 MB, and
refuses allow-list mode. Each new domain receives the same audit entry and
queued purge as an individual block — which is why it asks before writing, and
why `--dry-run` exists. A row naming a single label (`com`, `localhost`) is
refused: an entry covers every subdomain of itself, so one such row would block
a whole top-level domain. So is a row naming this instance. Neither is a
public-suffix check — `co.uk` still passes — so read a file with `--dry-run`
before applying it. It does not fetch remote sources; review an export before
running it. An unknown action throws
`specify action: add, remove, import, list, reset, silence, unsilence, silenced`.
`silence` and `unsilence` without an address throw `specify an address to
silence` / `... to unsilence`.

**Silencing, the tier between a block and nothing.** A block cuts the instance
off in both directions and `social:domain:purge` deletes what it already sent,
which also cuts off the local users who deliberately follow somebody there — so
the tool was too blunt to reach for and the nuisance stayed. A silence stores
the host in a second list (`silenced_list`) and changes one thing: posts whose
author is on that instance are left out of the **public**, **global**,
**hashtag** and **followed-tag** timelines (`StreamRequest::filterSilencedInstances()`).
Delivery, fetching, webfinger, search by address, following, and the home
timeline of somebody who follows the account are all untouched — a silence is
not enforced in `authorized()`. Nothing is deleted, so `unsilence` brings the
posts back.

The match is on the author's actor id, so it covers the domain **and everything
under it** the way a deny-list entry does: silencing `noisy.test` also silences
`sub.noisy.test`, and not `notnoisy.test`. The two lists are independent — an
instance can be silenced, blocked, both or neither.

**What is actually enforced.** There is a single list (`access_list`) whose meaning
depends on `access_type`: with `all_but` every address that is *not* listed is
allowed; with `none_but` only listed addresses and the local host are allowed.
`FediverseService::authorized()` is enforced on both inbox routes in
`ActivityPubController` and on every outgoing HTTP request in `CurlService`, so the
list does take effect for inbox delivery and for fetching remote data. A refused
inbox delivery is answered **403**, not 500, so the peer stops redelivering it —
see the rejection table in `docs/Architecture.md`.

**How an address is matched.** Case-insensitively, and without the trailing dot of
the absolute form. The two modes then read the list differently, on purpose:

- With `all_but`, a listed domain covers the domain itself **and everything under
  it** (`isListed()`). Blocking `evil.test` while `www.evil.test` walks straight
  back in is not a block.
- With `none_but`, an entry matches **exactly** (`isExactlyListed()`). A subdomain
  of an allowed domain is a different instance, and whoever runs the parent domain
  was never asked before it appeared.

There is no wildcard syntax; `add` stores what you type, and `remove` takes it away
by the same exact comparison.

**Known limitations:**

- `list` always prints an empty `Known address:` section, because
  `FediverseService::getKnownAddresses()` returns an empty array.
- The older two-list implementation (`blockAddress()`, `allowAddress()`,
  `isBlocked()`, `isAllowed()`, and the separate blacklist/whitelist config keys) is
  commented out at the end of `lib/Service/FediverseService.php`. Only the single
  `access_list` above exists; there is no separate block list.
- Webfinger lookups are not filtered per address; `WebfingerHandler` only calls
  `jailed()`, which refuses service when the instance is in `none_but` mode with an
  empty list.

### `social:emoji`

The custom emoji this instance publishes.

```
php occ social:emoji [-c|--category CATEGORY] [--hidden] [<action>] [<shortcode>] [<file>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `list` | One of `list`, `add`, `remove` |
| `shortcode` | No | `''` | Required by `add` and `remove`. The name between the colons: 2–64 characters of `a-z`, `0-9` and `_`. Read lowercase and trimmed, so `BlobCat` and `blobcat` are the same emoji |
| `file` | No | `''` | Required by `add`. A readable path to the picture |

| Option | Value | Description |
|--------|-------|-------------|
| `-c`, `--category` | required | Group it under this in a client's picker |
| `--hidden` | none | Store it usable by name but not offered in a picker (Mastodon's `visible_in_picker: false`) |

| Action | Effect |
|--------|--------|
| `list` _(the default)_ | Print every emoji with its category, whether a picker offers it, and the URL it is served from |
| `add <shortcode> <file>` | Publish the picture under that shortcode |
| `remove <shortcode>` | Stop publishing it |

An unknown action throws `specify action: list, add, remove`; removing one that
is not there throws `no emoji is published as :<shortcode>:`.

**What is accepted.** A PNG, GIF, WebP or JPEG of at most **256 KiB** — the
bytes decide, not the extension, because this is served to every reader of
every post that uses it. Anything else is refused with a reason.

**What `add` does to a shortcode already in use.** Replaces the picture. A
shortcode names one emoji, and an admin re-uploading under a name in use means
to replace it rather than to be told it exists.

**What a post carries.** The shortcode stays in the content as text, and an
`Emoji` tag beside it says where the picture is — which is how every fediverse
server does it, and why an instance that has never heard of `:blobcat:` still
renders the post. A shortcode this instance has no picture for is left as the
text it already was. Editing a post rebuilds its tags, so a shortcode added by
an edit renders and one removed by an edit takes its tag with it.

**What `remove` does to posts that used it.** Nothing. What they carry is the
tag they were federated with; this instance no longer offering the picture does
not rewrite what was already said. Locally, the picture stops being served, so
the shortcode shows as text again.

### `social:gif`

The shared pictures the composer offers in its GIF picker, on top of the 881
animated emoji every instance has (`gif_pack`, on by default — see
[Architecture](Architecture.md)). These are the ones that are *this* instance's:
the in-joke, the team's own, the thing nowhere else has. They are offered first,
above the emoji.

```
php occ social:gif [-t|--title TITLE] [<action>] [<slug>] [<file>]
```

| Argument | Required | Default | Description |
|----------|----------|---------|-------------|
| `action` | No | `list` | One of `list`, `add`, `remove` |
| `slug` | No | `''` | Required by `add` and `remove`. What the picture is served and searched under: 2–64 characters of `a-z`, `0-9`, `-` and `_`. Read lowercase and trimmed |
| `file` | No | `''` | Required by `add`. A readable path to the picture |

| Option | Value | Description |
|--------|-------|-------------|
| `-t`, `--title` | required | What the picker searches on — "the one with the cat" is how anybody actually looks for one of these |

| Action | Effect |
|--------|--------|
| `list` _(the default)_ | Print every picture in the library with its title and the URL it is served from |
| `add <slug> <file>` | Put the picture in the library under that slug |
| `remove <slug>` | Take it out |

An unknown action throws `specify action: list, add, remove`; removing one that
is not there throws `there is no library picture called <slug>`.

**What is accepted.** A GIF, an animated WebP or an MP4 of at most **8 MiB** —
the bytes decide, not the extension. Animated formats only: a library of stills
is what the Files picker beside it is already for. The metadata is stripped the
way every other upload's is, because these end up attached to posts that leave
this instance.

**Why there is no upload form.** This is a small set curated for a whole
instance rather than something every reader adds to, and the files an
administrator wants in it are already on a machine they have a shell on.

**Why there is no Giphy or Tenor.** Both would mean every composer on the
instance talking to a third party: the search terms people type go there, and
every thumbnail is a request from a reader's browser to a host the instance does
not control. That is not a decision this app should make on an administrator's
behalf, and a setting to turn it on would still be a setting that quietly sends
what people are looking for to somebody else's server.

**What `add` does to a slug already in use.** Replaces the picture, for the
reason `social:emoji add` does.

**What `remove` does to posts that used it.** Nothing. An attachment is a copy
taken when the post was written, so a post keeps the picture it was published
with.

### `social:domain:purge`

Delete everything an instance already sent this one. Blocking the domain
(`social:fediverse add`) is what stops it coming back; this removes what arrived
before the block.

```
php occ social:domain:purge [-b|--batches BATCHES] [--check] <domain>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `domain` | Yes | The instance to purge, e.g. `spam.example`. Accepts what a user would type — `https://spam.example/@someone`, `@someone@spam.example`, `Spam.Example:8443` — and reduces it to the host |

| Option | Value | Description |
|--------|-------|-------------|
| `-b`, `--batches` | required | Stop after this many batches of 50 accounts instead of running to the end. Without it the command runs until nothing of the domain is left |
| `--check` | none | Only report whether anything of the domain is still stored; deletes nothing |

**What goes.** For every account of that instance still known here: its cached
actor, its posts (with their recipient, tag, action, card, revision and cached
attachment rows), the notifications they caused, the follows in **both**
directions, the per-user blocks, mutes and notes about it, and the deliveries
still queued towards it. Leaving the follows behind kept the instance in the
delivery fan-out of every local post.

**This cannot be undone.** Unblocking the domain lets it reach the instance
again; it does not restore anything deleted here. The command says so before it
exits.

**Safe to interrupt and safe to repeat.** Each pass asks what of the domain is
still stored rather than counting off a position, so a run that is killed half
way is resumed by running it again, and a run against an already-purged domain
does nothing. Accounts are found from three places — the actor cache, the
authors of stored posts, and the follow rows — because a post can outlive the
actor it was cached from and a follow can name an account this instance never
cached.

**The exact host, not subdomains.** A deny-list entry covers everything under
the domain (`isListed()`), but the purge does not: refusing traffic from one
instance too many is undone by editing the list, and deleting one is not. Purge
each subdomain you mean to include.

**This normally runs on its own.** Adding a domain to the deny list queues
`OCA\Social\Cron\DomainPurge`, which does 10 batches per cron run and re-queues
itself until the instance is gone. Use this command for a domain blocked before
the purge existed, for a job that failed part way, or to finish without waiting
for cron.

---

## External Users

### `social:external`

Self-registered external users: list them, add one without the registration
form, or promote one to an ordinary local user.

```
php occ social:external list
OC_PASS=... php occ social:external add <handle> --email EMAIL --password-from-env
php occ social:external promote <handle>
```

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | Yes | `list`, `add` or `promote` |
| `handle` | No | The user id, which is also the Aloha Social handle; needed by `add` and `promote` |

| Option | Value | Description |
|--------|-------|-------------|
| `--email` | required | The email address of a new external user, for `add` |
| `--password-from-env` | none | Read the password of a new external user from the `OC_PASS` environment variable, for `add` |

`add` checks the handle, the email address and the room left under the
maximum the same way the registration page does, and needs external users to
be switched on. It skips email confirmation and approval. `promote` moves the
user to Nextcloud's database backend with the same user id and password hash;
from then on the normal app and group rules apply to them. A refusal prints
the reason and exits with 1.

---

## Installation & Maintenance

### `social:check:install`

Check the integrity of the installation, or regenerate the stream index.

```
php occ social:check:install [--index] [--offline] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--index` | none | Regenerate the stream index instead of running the checks |
| `--offline` | none | Leave out the WebFinger probe, the one check that goes out on the network |
| `-f`, `--force` | none | Skip the confirmation of `--index` (required with `--no-interaction`) |

Without `--index`:

- runs `CheckService::checkInstallationStatus()`,
- prints how many invalid followers and invalid notes were removed,
- runs the four checks that Administration → Overview shows (`lib/SetupChecks/`) and prints each with its severity: whether `.well-known/webfinger` answers for an account of this instance, whether the address Aloha Social builds ids from still matches the one the server reports, whether the delivery job has run lately, and whether anything in the outbound queue is stuck. A check that fails links to [Admin.md](Admin.md),
- prints a verdict line — `all N checks passed`, or `M of N checks reported an error` — and **exits 1** when any check reported an error, so the command can stand in a deployment script
- prints the current app configuration as pretty JSON.

The command exits `1` when any of those four reports an error, so a deployment
script can run it. `--offline` leaves out the WebFinger probe for a machine with
no route out; the other three read only the database and the configuration.

With `--index` the checks are skipped entirely. The command warns that the operation
takes a while, asks `Do you confirm this operation? (y/N)`, and on confirmation
empties `stream_dest` and `stream_tags` and rebuilds both from `social_stream`, a
few hundred rows at a time, with a progress bar. Answering anything but `y` exits
without changes; under `--no-interaction` it refuses unless `--force` is given
(exit code `1`), because the index tables are truncated before the rebuild starts
and a run that stops there leaves every timeline empty.

Rows it cannot parse are reported at the end (the first ten in full, then a count)
and make the command exit `1`; the rest of the index is still rebuilt.

---

### `social:reset`

Delete all Aloha Social data, or uninstall the app's database footprint.

```
php occ social:reset [--uninstall] [--uri ADDRESS] [-f|--force]
```

| Option | Value | Description |
|--------|-------|-------------|
| `--uninstall` | none | Full removal instead of a data flush |
| `--uri` | address | The cloud base address to rebuild every id from, instead of being asked for it. This is the option `social:check:install` names when the configured address no longer matches the server. After the flush `social_url`, the base ids are minted from, is derived from the cloud address again, with or without this option |
| `-f`, `--force` | none | Skip both confirmations (required with `--no-interaction`) |

The command asks **two** confirmations before doing anything:

1. `Do you confirm this operation? (y/N)`
2. `Operation is destructive. Are you sure about this? (y/N)`

Answering anything but `y` to either question aborts with exit code `0` and changes
nothing. Under `--no-interaction` the command refuses with exit code `1` unless
`--force` is given: a confirmation prompt answers itself with its default when
nobody is there, so without this the command would exit `0` having done nothing.

It refuses with exit code `1`, before asking anything, while self-registered
external users exist: their logins are rows of this app, and flushing them
would leave Nextcloud users nobody can log in as. Delete them, or promote them
with `social:external promote`, first.

Without `--uninstall`:

- empties every Aloha Social table (`CoreRequestBuilder::emptyAll()`),
- re-runs `checkInstallationStatus(true)`,
- sets the cloud base address to `--uri`, or, with somebody at the keyboard and no
  `--uri`, offers to change it, pre-filled with the current one; entering the same
  value leaves it unchanged. Non-interactively and without `--uri` the address is
  left as it was.

With `--uninstall`:

- drops the Aloha Social tables,
- removes the app's rows from the migrations table,
- removes the app's background jobs,
- unsets the app configuration.

The app files themselves are not removed, and the app is not disabled.

---

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Success, and also a confirmation prompt answered with "no" |
| 1 | A refusal to act non-interactively without `--force` (`social:reset`, `social:check:install --index`, `social:benchmark`, `social:queue:retry`), a failed flush or uninstall in `social:reset`, `social:reset` while external users exist, streams `social:check:install --index` could not parse, a setup check that `social:check:install` reports as an error, `social:account:following` handled failure, or an uncaught exception in any command |

---

## Background Jobs

The `TimedJob`s below are among those registered in `<background-jobs>` in
`appinfo/info.xml` (that list is the complete one), run by Nextcloud's cron;
the last four are queued on demand instead:

| Job | Class | Description |
|-----|-------|-------------|
| Cache maintenance | `OCA\Social\Cron\Cache` | Every 12 minutes, with a 300-second budget. Same steps as `social:cache:refresh` (deleted actors, local actor cache, remote actors and their details, documents, hashtags), and additionally closes polls, prunes remote statuses past retention, evicts cached remote accounts nobody here refers to, syncs the timelines of the cached remote actors somebody here follows, verifies profile links, reconciles group lists, finds out which servers the Discover page may ask and deletes the expired rows of the durable cache. A run that spends its budget logs which steps it skipped, and the next run starts with the first of them, so the steps at the end of the list are not the ones that never run. No key rotation is performed. |
| Queue processing | `OCA\Social\Cron\Queue` | Every 12 minutes. Processes the outbound request queue **and** the stream queue, like `social:queue:process`. |
| Expired stories | `OCA\Social\Cron\ExpiredStories` | Hourly. Deletes the stories whose day is up, at most 500 per run. The second of the two guards on a story's expiry: every read already filters on `expires_at`, so an instance whose cron has stopped shows nothing it should not — but without this the rows and their pictures would pile up for ever, and "it disappears after a day" would be true of what people can see and false of what is stored. |
| Scheduled posts | `OCA\Social\Cron\ScheduledPosts` | Every 5 minutes. Publishes the posts whose `scheduled_at` has passed, at most 50 per run. Shorter than the other two on purpose: a scheduled post may be published up to one cron period late, and a longer period would promise a precision the five-minute minimum on `scheduled_at` implies but the app could not keep. |
| Block lists | `OCA\Social\Cron\BlocklistSync` | Daily. Re-reads the block lists an administrator follows and applies what they say; does nothing while no source is on. |
| External registrations | `OCA\Social\Cron\ExternalSignups` | Hourly. Forgets registrations of external users whose email was not confirmed within a day, and invitations that expired or were used up. A registration waiting for an administrator is kept until somebody decides on it. |
| Bluesky maintenance | `OCA\Social\Cron\AtprotoMaintenance` | Every 5 minutes, only while Bluesky is on. Reconciles the last day's public posts with their Bluesky records — a post the listener missed is published, an edit within the grace period replaced, a deleted post's record removed — resends directory operations the PLC refused, prunes the firehose past its replay window and drops retired instance keys. |
| Domain purge | `OCA\Social\Cron\DomainPurge` | Queued with a domain when one is added to the deny list — not registered in `appinfo/info.xml`, because a job listed there is added once at install time with no argument. Does 10 batches of 50 accounts per run and re-queues itself while anything of the domain is left. |
| External promotion | `OCA\Social\Cron\ExternalPromoted` | Queued with a user id when an external user is promoted to a local one — not in `appinfo/info.xml`, for the same reason as the domain purge. Writes the user's system address book card, which external users do not have, in a request that sees them on their new backend. |
| Bluesky publish | `OCA\Social\Cron\AtprotoPublish` | Queued by the post listener with a post id and an action (publish, edit, delete) or by the account service for a profile — not in `appinfo/info.xml`, for the same reason. Does the work off the request that made the post; the maintenance job catches what a job that failed or was never queued left behind. |
| Actor cleanup | `OCA\Social\Cron\ActorCleanup` | Queued with an actor id when a deleted account is addressed by more posts than one inbox request should rewrite — not in `appinfo/info.xml`, for the same reason as the domain purge. Rewrites 2000 posts per run and re-queues itself while any remain. Without it, the rewrite ran inline in the request a peer was waiting on for its `Delete`, so the peer timed out, re-sent, and the work started over. |
