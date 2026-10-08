# Performance and scalability

A survey of the query and scalability behaviour of this app, and of what the
browser has to download before any of it is on screen: what costs what, what is
bounded, and what is still known to be wrong. Every claim below was checked
against the code rather than carried over from a previous survey.

**Verified against:** app version 0.19.14, `master`, 2026-09-13 — the queries
re-measured end to end on a seeded instance (22,642 posts, 65,014 recipient
rows, 916 follows, 435 cached actors) after the read-path wave below, and the
bundle sizes measured from `npm run build` on this version.

This file is **not** enforced by `tests/DocumentationTest.php` — its claims are
about behaviour rather than about routes or schema rows, and a test that tried
to check them would either be brittle or would be the fix. It therefore goes
stale silently. Re-verify before trusting a line of it, and update it in the
same change as any work on a read path.

## What a page costs

Measured with `occ social:benchmark --time-only`, which times
`StreamRequest::getTimeline()` itself — the query builders and the hydration,
not just the SQL. Two datasets: a demo instance of 650 posts, and the same
instance with `--actors=400 --notes=20000 --follows=300` seeded on top.

| timeline | 22k posts, before | 22k posts, now | 650 posts, now |
|---|---|---|---|
| home (My Feed) | 114.7 ms | **49.6 ms** | 16.6 ms |
| public (Local/Global) | 52.6 ms | **3.6 ms** | 4.6 ms |
| notifications | 29.6 ms | **7.4 ms** | 7.0 ms |
| direct | 1.3 ms | 1.3 ms | 1.2 ms |

Three changes did that, and all three are about what a *page-selection* query
asks for rather than about indexes:

1. **The page queries stopped projecting columns nobody reads.**
   `linkToCacheActors()` appends twenty select aliases — `source`, `details`,
   `summary`, `public_key` among them — plus eleven for the cached document,
   and the home timeline's `SELECT DISTINCT` had to deduplicate over all of
   them. `joinCacheActors()` is the same join without the select list; the
   home query went from 94.5 ms to 56.2 ms on nothing else.
2. **`SELECT DISTINCT` is asked for only where a page can actually
   duplicate.** `social_stream_dest` is unique on `(stream_id, actor_id,
   type)`, so a query that fixes the actor *and* the type — public,
   notifications, direct, the marked timelines — cannot match a post twice.
   Those now select without it: the public page query went from **37.4 ms to
   0.21 ms**, for the same twenty rows, with no duplicates among them. The
   home timeline keeps it, because a post addressed to three accounts you
   follow really does match three rows. `getNidsFromRequest()` deduplicates the
   twenty integers in PHP as well, which covers the one case SQL uniqueness
   does not: the left join on expired mutes can match twice for a viewer who
   timed-muted both a booster and the account they boosted.
3. **Notifications moved onto the two-query pattern** the home and public
   timelines already used — choose a page of ids, then hydrate them. It was
   carrying two full stream column sets, two cached-actor sets and two
   cached-document sets through a `DISTINCT` to pick twenty rows, and every
   client polls it on a timer.

And one that is not about reads at all: **a viewer's domain blocks are no
longer joined per row.** The filter was a `LEFT JOIN` whose `ON` clause held
four `LIKE`s against `LOWER(attributed_to)` — an unindexed text column —
evaluated for every candidate row of every timeline, for every account,
including the overwhelming majority who have blocked nothing. The list is read
once per request and compared as constants; an account that has blocked nothing
now adds no clause at all. Measured at 56.2 ms → 41.5 ms on the home timeline
of an instance with a single block row.

## What is still open

### Unbounded work

Nothing is unbounded any more. What is left is bounded work that
could still be cheaper, and the schema items at the end.

| Where | What it costs now |
|-------|-------------------|
| `StreamRequest::getDescendants()` | A thread is walked a level at a time, each level one query, bounded by depth and by page size. Fine for a conversation; a thread thousands deep is still thousands of levels. |
| `CacheActorService::getFromId()` | An uncached actor is fetched over HTTP inside the request that asked for it. Bounded by the access list and a timeout, but it is network in a read path. |
| `StreamPruneService` | The retention pass is `NOT EXISTS` over the largest table, which is a sequential scan by design. It runs from cron and from `occ`, never from a request. |

### Transactions

`StreamRequest::save()` is now one transaction: the post, its recipient rows
and its tags go in together or not at all, and `StreamDestRequest::createRecipients()`
raises instead of logging a failure and carrying on. It was the one place where
a partial write was both permanent and silent — the recipient rows are what put
a post in a timeline, so the post existed and was in nobody's.

Inside a transaction, a *tolerated* failure is not free either. Recipients and
hashtags repeat in ordinary posts — `getToAll()` returns `to` alongside
`toArray`, the unique index on the recipient rows does not include the subtype
so the same actor in `to` and `cc` collides too — and both inserts used to
catch the violation and carry on. PostgreSQL aborts the whole transaction on
any refused statement, so that caught violation took the commit with it and the
post was lost. Both now ask the database to skip the duplicate row, nothing
fails, and the raise is left to mean what it says. The recipient rows go in as
one statement per post (`CoreRequestBuilder::insertIgnoringConflicts()`: the
multi-row `INSERT IGNORE` / `ON CONFLICT DO NOTHING` / `INSERT OR IGNORE` that
`insertIgnoreConflict()` issues for one row), not one round trip per recipient
with the post's locks held.

The two places this section used to name are settled: `StreamActionsRequest::save()`
inserts first and updates on the unique violation (`StreamActionsFlagsTest` pins
the race), and `ModerationRequest::save()` does the same rather than delete-then-
insert, so a decision is replaced or kept, never lost.

### Wide `SELECT DISTINCT`

`getStreamNidsSelectSql()` exists precisely to avoid deduplicating over every
column — it selects nids, then hydrates. **Every branch of `getTimeline()` now
goes through it**: home (both halves), public, direct, account, hashtag,
favourites and bookmarks, notifications, and the list timeline in `ListsRequest`
— each a `*TimelineNids()` method that decides the page over one indexed
column, then `streamsByNids()` for exactly those rows
(`tests/Db/TwoQueryTimelinesTest.php` pins the shape for the three moved last).
What still pairs `selectDistinct('s.id')` with the full stream column set is
the single-row lookups (`getStreamById()` and friends, which return one row and
have nothing to deduplicate), `searchContent()` (which hydrates only the nids
the word index picked, but still through the wide select), `getDescendants()` /
`getRepliesTo()`, `getAnnouncesAndRepliesTo()`, and the `*_dep()` methods behind
the uncalled Custom Local API routes. Of those, the thread walk is the one worth
moving next; the `_dep` ones go with their routes.

### Lookups that cannot use an index

`DomainBlocksRequestBuilder::filterDomainBlocked()` is no longer one of them:
the four `LIKE`s it built are now compared against constants read once per
request, and skipped entirely for an account that has blocked nothing. They are
still `LIKE`s on an unindexed column for an account that has blocked something,
bounded at `CoreRequestBuilder::BLOCKED_DOMAINS_IN_A_QUERY`; storing the
author's host in its own indexed column would make them equalities, and would
serve the silenced-instance filter next door as well.


The `*_prim` columns (md5 of the lower-cased id) exist so a lookup can be an
indexed equality. The hot paths now use them, but `ActorsRequest`'s
`preferred_username` lookups still compare `LOWER(column)` against
`LOWER(?)` on an unindexed column — the public ActivityPub actor endpoint and
webfinger both land there.

### Paging by offset

An offset page reads and throws away every row before it, so page *n* costs
*n* pages. Everything this app walks for itself pages on a keyset: the
timelines on the nid, the follower and following lists on (`creation`,
`id_prim`) through `FollowsRequest::cursorAfter()` — which is also how the
export of both lists and the re-follow after a Move walk them. The offsets
that are left are ones a contract outside this app asks for:

- the numbered `?page=N` of the ActivityPub outbox, followers, following and
  replies collections (`getPublicByAuthor()`, `getFollowersByActorId()`,
  `getFollowingByActorId()`, `getPublicRepliesTo()`), because those are the
  addresses peers already hold. Every `next` link those pages hand out is a
  cursor, so a peer that follows them never reads by offset past the first;
- Mastodon's `offset` on `/api/v1/trends/*` (`TrendsRequest`), where the rows
  are a ranking over a window and the grouping, not the offset, is the cost;
- Mastodon's `offset` on `/api/v2/search`, capped at
  `StreamRequest::SEARCH_MAX_OFFSET`, with `max_id` the way further back.

`favourited_by` and `reblogged_by` answer one page of up to 80 accounts and do
not page at all.

### Schema shape

- ~~`social_follow`'s unique indexes lead with the `accepted` boolean~~ —
  fixed by `Version1000Date20260913000001`, which drops both, adds
  `social_f_oa_u` unique on `(object_id_prim, actor_id_prim)` and
  `social_f_foa` on the three id columns, and removes the duplicate rows that
  the old shape allowed before adding them. That migration also drops
  `social_stream_dest.ts (type, subtype)`: three values against three across
  the second-largest table, never the best index for any query the app makes,
  and pure write cost — 27.7 MB of index over 8 MB of data on a 65,000-row
  table.
- Several indexes from the 2022 migration are unnamed, so Doctrine names them
  per install and a later `hasIndex()`/`dropIndex()` cannot refer to them. Index
  names from that era are also not app-prefixed (`sa`, `ts`, `aoa`, …) and live
  in PostgreSQL's schema-global namespace, where another app can collide with
  them. Migrations from 2026 use `social_*`.
- Every `NOT NULL` column these migrations add carries an explicit default,
  including `social_cache_doc.account`. PostgreSQL refuses a `NOT NULL` column
  on a table that already has rows unless there is one ("column contains null
  values") where MySQL quietly invents the empty string, so the default is what
  makes such a column safe to add at all. `CoreSchemaTest` holds the four
  columns on that table to it.
- ~~The queue drain's sort had no index behind it~~ — `getStandby()` selects on
  `status` and orders `priority DESC, tries ASC, last ASC, id ASC` to take 200
  rows, and the only index on the table was `social_rq_si (status, id)`: every
  drain, every twelve minutes, sorted the whole standby set to pick its window.
  `Version1000Date20260914000003` adds `social_rq_sptl (status, priority, tries,
  last)`, which is the select and the first three sort keys in order, so the
  window is read off the index. `id` is left out on purpose — it only breaks
  ties between rows that agree on the other three, and a fifth column would be
  write cost on the hottest-written table in the app for nothing.
- The same migration adds `social_cache_actor.sync_attempt`/`sync_failures` and
  `social_ca_lsa (local, sync_attempt)`. Both are integers rather than
  datetimes so that "never" is 0 and sorts identically on every database, where
  a NULL datetime does not.
- `social_stream_act.bookmarked` is `SMALLINT` while its three sibling flags are
  `BOOLEAN`.
- `social_stream` carries nine JSON-in-TEXT columns — `to_array`, `cc`, `bcc`,
  `hashtags`, `tags`, `details`, `instances`, `attachments` and `cache` — plus
  `source`, the whole ActivityPub wire object. None of them can be filtered,
  indexed or sorted on by any database this app supports, so anything that has
  to be queried needs a column or a side table of its own, and every insert
  writes both copies: `hashtags` duplicates `social_stream_tag`, and
  `to_array`/`cc`/`bcc` duplicate `social_stream_dest`. That is the cost of the
  arrangement, and it is paid knowingly — the side tables exist *because* the
  JSON is unqueryable.
- Five post fields used to be readable only by `json_decode`ing `source` once
  per timeline row: `tag`, `language`, `updated`, `quote` and
  `quoteAuthorization`. `Version1000Date20260912000007` gave each a column, so
  `language` is now an indexed equality (`social_s_lang`) and the other four are
  read without parsing. `tags` stayed JSON, because the one facet of the `tag`
  array worth querying — the hashtags — is already `social_stream_tag`, and a
  second side table would be a second source of truth for it. `quote` and
  `quote_authorization` have no `_prim` companions: no query filters on either,
  and an md5 column plus index on the largest table in the app costs every
  insert for a read nobody makes. Rows written before that step still fall back
  to `source` until `BackfillStreamPostFields` reaches them.
- The four `NOT EXISTS` clauses in `StreamPruneService` are assembled by calling
  `getSQL()` on separate query builders and concatenating the strings. The
  sub-builders have their own parameter namespaces, so a
  `createNamedParameter()` added inside one later would silently vanish from the
  outer query.

## The frontend bundle

Every byte here is downloaded before the first post is on screen, so this is
the part of the app's speed that a fast database cannot help with. Sizes are
gzipped, which is what the wire actually carries.

Since 0.19.13 the third-party code that more than one entry needs lives in one
shared `social-framework` chunk rather than being built into each entry, so the
honest figure for a page is that chunk **plus** the entry — which is what this
table gives.

| Page | 0.19.13 | 0.19.14 |
|------|--------:|--------:|
| the app (`framework` + `social`) | 357.5 KB | 265.1 KB |
| a public profile (`framework` + `profilePage`) | 356.5 KB | 269.2 KB |
| the remote-follow page (`framework` + `ostatus`) | 313.2 KB | 223.5 KB |
| the dashboard widget (`framework` + `dashboard`) | 309.7 KB | 220.0 KB |
| the consent screen (`framework` + `oauth`) | 305.2 KB | 215.4 KB |

Almost all of it is the shared chunk, which went from 294.8 KB to 204.8 KB —
a saving every page gets once, and every page after the first gets for free.
Three changes account for it:

- **Toasts are fetched when one is shown** (`src/services/toast.js`).
  `@nextcloud/dialogs` re-exports the file picker, the conflict picker and the
  dialog builder from the same module as `showError`, so importing the toast
  cannot be tree-shaken down to the toast: it put 259 KB of source into the
  shared chunk so that a page could eventually say "Could not load the
  timeline". Nothing waits for a toast, so the wrapper returns a promise
  callers are free to ignore and the library arrives in a chunk of its own the
  first time one is needed. The one caller that needs more than a toast — the
  composer's file picker — imports the library where it opens the picker.
- **The emoji picker is fetched when somebody asks for one.** It was already
  its own chunk — 130 KB gzipped, nearly all of it the emoji set — but the
  composer imported it statically, so the browser fetched it alongside the
  composer on the home timeline whether or not anybody wanted an emoji. It is a
  `defineAsyncComponent` now, and the button that opens it stays on screen with
  a spinner until the chunk is there, so one press still opens the picker. A
  failed chunk request leaves that button available, clears the rejected import
  promise, and lets the next press retry instead of leaving a permanently
  rejected picker on the page.
- **Vue's production flags** (`__VUE_PROD_DEVTOOLS__` and friends, set in
  `webpack.common.js`). Without them the devtools bridge is compiled in:
  `@vue/devtools-api` pulls `@vue/devtools-kit`, which nothing in a released
  app can reach. Measured on its own by building with and without: **31.0 KB**
  of the shared chunk and 2.1 KB of the main entry.

Neither `@nextcloud/dialogs` nor the emoji set appears in `social-framework.js`
or `social-social.js` any more, which is the check worth repeating after any
work here: read the source maps rather than the sizes.

Scope hoisting was tried and left off, where it has been since 0.9.3: it is
worth about 1 KB, and with it on two consecutive builds of the same source
produce two different bundles, because Terser mangles the merged scopes
differently each time. CI checks that the committed `js/` matches `src/`, so a
build that is not reproducible is worse than a kilobyte.

The `buffer` polyfill went with them: nothing had required it since the
dependency that did was dropped, and the fallback resolved a package into the
build for no caller.

### What is left, and why it is still there

- **`@nextcloud/vue` is a third of the main entry** — 924 KB of the 2.7 MB of
  source webpack puts into it — and half of that is one file: `_l10n.mjs`
  ships the library's own translations for forty languages and picks one at
  runtime. Components are already imported one by one
  (`@nextcloud/vue/components/NcButton`, never the package root), so this is
  not ours to tree-shake — it needs a change upstream.
- **The date picker** is its own chunk, fetched when a poll's end date is set.
  So are the routes: every view behind the router is an `import()`.
- **DOMPurify** (130 KB of source, 5% of the entry) is there for profile bios
  only — post bodies go through `MessageContent.js`, which builds vnodes rather
  than markup. It could be deferred the way toasts were, but a bio that renders
  a frame late is the worse trade.

## What was fixed, and what fixed it

The previous survey (2026-09-10, against 0.11.49) is largely answered. Kept
here so a reader who finds that report knows why the code no longer matches it.

| Then | Now |
|------|-----|
| Anonymous reads cross-joined the whole `social_follow` table: the no-viewer branch called `selectDestFollowing($aliasDest)` without the second argument, so the join had no predicate | Both branches pass `''`. On an instance with no follow rows, anonymous status fetches and public timelines work |
| `social_cache_doc.id_prim` — the join key for every avatar and attachment — had no index | `Version1000Date20260910000001` adds `social_cd_idp` and `social_cd_pidp` |
| Every id/account lookup wrapped the column in `LOWER()` on unindexed text | The hot lookups take the `*_prim` path (`limitToIdPrimString()` and friends) |
| Missing indexes on favourites, bookmarks, hashtag timelines, like/boost counts, both queue drains, and `social_client.token` — the last one a full scan of the client table on every API request | All added by `Version1000Date20260910000001`, which also drops the redundant five-column `ipoha` index |
| Deleting a post left rows in five tables and files on disk | `deleteById()`/`deleteByAuthor()` go through the related-row deletion |
| `occ social:reset --uninstall` left two tables behind | Every declared table is in `$tables`, and `tests/Db/SchemaConventionsTest.php` fails if a new constant is not |
| Two cron loops did unbounded outbound HTTP per row | `getRemoteActorsToUpdateDetails()` and `getStandby()` are capped (one remains — see above) |
| The admin report list resolved each reported account one at a time, fetching over the network when uncached: one unreachable instance hung the page | `ReportService::getReports()` resolves them in one batch from the cache only |
| `filterSilencedActors()` was dead code, so a silenced account was not silenced | Called from the three read paths that need it |
| `HashtagService::manageHashtags()` ran five hydrated wide queries and one write per hashtag, and reported the same number for every window | `StreamRequest::countHashtagsInWindows()` is one grouped aggregate over the widest window, with a conditional sum per window |
| `CacheActorsRequest::getSharedInboxes()` returned `''` for actors with no shared inbox, which the caller turned into a delivery to the host `''` | Empty values and local actors are excluded in SQL |
| An account deletion was queued for every shared inbox ever cached, read with a `SELECT DISTINCT` over the unindexed TEXT column, dead servers included | `getSharedInboxesSeenSince()` groups on the indexed `host` and keeps servers heard from in the last 90 days; `ActivityService` drops those whose breaker is open |
| The delivery fan-out hydrated every follower into a `Follow` with a `Person` and its details to read one string off each | `ActivityService::generateInstancePathsFollowers()` asks `FollowsRequest::getFollowerInboxes()` for one row per distinct inbox, resolved in the database — the number of *instances*, not of followers |
| `MigrationService` re-followed on behalf of every local follower from one unbounded read | Paged at `REFOLLOW_PAGE`, bounded at `REFOLLOW_MAX` |
| `social_actor.user_id` had no index, and it is what resolves the logged-in user's actor on every authenticated request | `Version1000Date20260912000001` adds `social_a_uid`, and the four `social_hashtag` trend columns got theirs |
| `ReportService` and the account entity leaked `source` on every read, so every Account query carried `follow_requests_count` | `source` is built by the two credentials routes only; nothing else asks the database for it |
| `getNotCachedDocuments()` read every uncached document and fetched each one over HTTP in a single cron slot | Capped at `CACHE_BATCH`; the rest is the next run's, which is what its two siblings already did |
| `deleteStreamFromActor()` walked every post that ever addressed an account, inline in the inbox request a peer was waiting on — so the peer timed out, re-sent the `Delete`, and the walk started again | Paged by keyset (`DETACH_PAGE`), bounded per request (`DETACH_INLINE`), and finished by `Cron\ActorCleanup`, which re-queues itself while rows remain |
| A page of relationships cost six queries per account — two follow rows, the blocks and mutes, the note, the mute's expiry | Five queries for any number of accounts (`getBetweenMany()`, `getNotes()`, `getExpiries()`), and the single-account route goes down the same path so the two cannot disagree |
| `HashtagService::manageHashtags()` read every hashtag the instance had ever seen on every cron run | `getWithAnyTrend()` reads only the rows that claim a trend — the only ones it can change |
| `FollowService::getFollowers()` hydrated every follower for a route with no cursor | Bounded at `FOLLOWERS_PAGE`; the paging route is `/api/v1/accounts/{account}/followers` |
| One dead instance froze the actor-cache refresh: `getRemoteActorsToUpdate()` took 50 stale rows with no order and no record of having tried, and a failed refresh wrote nothing — so the same 50 unreachable rows came back every twelve minutes and no live profile was refreshed again | Every attempt is stamped in `sync_attempt`, the oldest attempt goes first, a failure doubles the wait from an hour, and after ten failures the refresh leaves the actor alone. Nothing is deleted: the row, its followers and its posts stay, and an on-demand fetch still resets the count |
| `Cron\Cache` had no wall-clock budget while `Cron\Queue` had one: eleven steps, two of them a request per remote actor, ran until they were done — so a handful of slow peers could hold a cron slot open past the twelve-minute interval | `MAX_DURATION` of 300 seconds, threaded through the steps and into the two loops. A skipped step is named in the log and the next run starts with it, so the tail of the list is not the part that never runs |
| Nothing ever evicted a cached remote actor: a row was written the first time this instance saw an account and only a remote `Delete` or a domain purge removed one | `CacheActorSweepService`, bounded per cron pass, removes the ones nobody here follows, that follow nobody here, that wrote no stored post and have no pending relation, after `cache_actor_days` (180) — with their avatars. `occ social:media:usage` is what says how much that is worth |
| `StreamRequest::save()` wrote the post, then its recipients, then its tags, outside any transaction, and the recipient insert swallowed its failure | One transaction, and `StreamDestRequest::createRecipients()` raises. A post that cannot have recipients is not stored at all, so the delivery can be retried into a clean state. The duplicate recipient and hashtag rows an ordinary post produces are skipped by the database rather than caught, which a transaction on PostgreSQL does not survive |

## The home timeline, and one thing that did not work

Home is now the slowest page and the reason is structural: it asks for "the
newest posts addressed to anyone I follow", where the selection lives in
`social_stream_dest` and the order in `social_stream`. The database drives from
the follow rows, walks each followed account's recipient rows, joins the post to
every one of them and sorts the result — at 22,642 posts and 325 follows that
join produced **16,111 rows to return 20**, with `Using temporary; Using
filesort` over all of them.

The obvious fix looked like copying the post's `nid` onto its recipient row, so
that `(actor_id, type, nid)` could select *and* order a page from one index. On
a stripped query that measured 25.9 ms → 2.95 ms. **It does not work on the real
one**, and the plan says why: with the filters attached — the hidden-actor
anti-joins, the mute expiry, the author join — MariaDB still drives from the
follows and still sorts, so the sort key on the recipient row changes nothing it
can use. The column, its index, the write and a backfill repair step were
written, measured at **no improvement**, and removed again rather than shipped.
Anyone reaching for that idea again should measure the *whole* query first.

What is left, in order of how much it would cost to try:

- **A semi-join.** `WHERE EXISTS (SELECT 1 FROM social_stream_dest sd JOIN
  social_follow f …)` emits each post once, which removes the deduplication and
  lets the database walk `social_stream` backwards by primary key and stop after
  twenty — exactly what made the public timeline 178× faster. The trade is the
  opposite one: it is fast when the people you follow post often and slow when
  they do not, because then it walks a long way back for twenty rows. Worth
  measuring on both shapes of instance before choosing.
- **Fan-out on write.** One row per (viewer, post) in a table indexed
  `(viewer, nid)` makes the home timeline a single index range. It is what the
  large implementations do, and it costs a write per follower on every post.

## What to do next

1. **Move the thread walk (`getDescendants()`, `getRepliesTo()`) onto
   `getStreamNidsSelectSql()`.** Every timeline is on it now, and the search
   picks its page from the word index before it hydrates; the thread walk is
   what is left of the wide `SELECT DISTINCT`, and also the one read that is
   still a query per level.
2. **Retire the `*_dep()` methods with the Custom Local API routes** that call
   them (Technical-Debt.md, item 2): that removes the last wide timeline reads
   without rewriting them.
3. **The schema items**, next time a migration touches those tables. The
   `social_follow` index order is the one worth doing deliberately: it is why a
   duplicate accepted/pending pair can exist at all.
4. **An index on `social_actor.preferred_username`**, or a `*_prim` column for
   it, if the public actor endpoint and webfinger ever show up in a profile.
   Both still compare `LOWER(column)` against `LOWER(?)`.
