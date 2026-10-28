<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration tests (run against a real Nextcloud + database)

Unlike the unit suite (`tests/`), which stubs OCP and needs no server, these
tests resolve real services from the container and hit the actual database, so
they exercise the migrations, the query SQL, the unique constraints and the
storage boundaries (key encryption, credential hashing) that mocks cannot reach.
On their first run they caught two shipped bugs the green unit suite missed —
an OAuth secret column too short for its hashed value on MySQL, and an UPDATE
built without a SET clause — which is exactly the class of regression they exist
to stop.

- `Db/TimelineSeedTest` — seeds actors, follows and notes through the production
  Request classes and asserts what each timeline returns: home via the follow
  join, direct-message isolation, favourites/bookmarks via the action join,
  hashtags, account visibility and id-based pagination.
- `Db/AtprotoChatMessageTest` — durable Bluesky DM mappings: idempotent writes,
  account isolation and removal through the message deletion cascade.
- `Db/UndoFollowCountTest` — an incoming `Undo{Follow}` moves the follower
  count only when the stored follow it names is the row removed; one naming a
  follow id that is not stored changes nothing.
- `Db/RejectFollowCountTest` — an incoming `Reject{Follow}` removes the stored
  follow it names and moves the counts back that the request and its
  acceptance moved; one naming a follow id that is not stored, or naming the
  follow as one of another server's account, changes nothing.
- `Db/ApproveReplyAuthorTest` — an `ApproveReply` moves a pending reply of
  ours to approved only when it comes from the author of the video it
  answers (the account behind the channel, as PeerTube sends it).
- `Db/ActorCountFloorTest` — an account counter bumped in SQL: a decrement
  stops at 0, and a counter never counted (`-1`) is moved by no step.
- `Db/ActorsKeyStorageTest` — private keys land encrypted, read back decrypted,
  legacy plaintext rows stay readable, the EncryptPrivateKeys repair converts
  them idempotently.
- `Db/TokenLifecycleTest` — an OAuth token through `ClientService`: a code
  exchanged once and refused after ten minutes, a revoked token that names
  nobody while another account's authorization of the same app survives, an
  app or account unable to revoke what is not theirs, and a `read` token
  refused by a write route before its service is reached.
- `Db/ClientCredentialStorageTest` — OAuth secrets/codes/tokens land hashed,
  plaintext tokens resolve through the hashed lookup, re-authorization
  invalidates the old token, revocation works, a legacy plaintext row is
  refused and the hashing migration converts it.
- `Db/ClientAuthMultiUserTest` — two accounts authorize one app without
  signing each other out; two app tokens, or two authorizations by one
  account, written at the same moment both succeed and the last one holds.
- `Db/StreamActionsFlagsTest` — the per-viewer flags are written field-wise and
  idempotently.
- `Db/ReplyCountTest` — the reply counter on a post: a recount counts what is
  stored, counts one fewer after a delete, and carries the replies that live on
  the post's own server across untouched.
- `Db/PublishedPostCountsTest` — a local post's counts on Bluesky: which of
  its likes, boosts, replies and quotes held here the AppView counts too
  (from bsky.app, or a local account's own record — never the Fediverse's),
  and which local posts with a post record the cron's pass takes: inside the
  window, due, oldest first.
- `Db/RequestQueueLifecycleTest` — standby → running → deleted on success,
  failures counted and retried, abandoned after MAX_TRIES, stale RUNNING rows
  reaped back to standby.
- `Db/OnlyMediaTimelineTest` — the predicate behind the Photos view: posts with
  attachments are kept and all three spellings of "no media" (NULL, `''`, `'[]'`)
  are dropped, and a post carrying several pictures is still one post.
- `Db/OnlyMediaTimelineTest`, `Db/OnlyVideoTimelineTest`,
  `Db/MediaTypeTimelineTest` also seed media at a realistic ratio
  (`Db/MediaUnderText`: three pictures and two videos under sixty newer posts
  of text) and page the Photos and Videos views of home, public and a profile
  — a page of twenty, then two at a time on `max_id` — with the home fast path
  on and off: a narrowing applied after the page was chosen finds nothing.
- `Db/ShortKindWriteTest` — `short_kind` written wherever the attachment
  copies are: a stored post, an edit, rebuilt copies, and a remote video that
  only becomes a short once caching has measured it.
- `Db/FollowedTagsTest` — the followed-hashtag table and the second half of the
  home timeline: the unique index that makes following twice a no-op, paging on
  the row id, a 127-character multi-byte tag, and the join itself — a public
  post carrying a followed tag reaches home whatever case its author typed the
  tag in, a followers-only one does not, a post carrying two followed tags
  is listed once, and a post read from Bluesky is in it like any other unless
  its author is muted — and which tags the background read of followed tags
  picks: each once, never read first, then read longest ago.
- `Db/HomeMentionTest` — a post that names the reader is on their home timeline
  whether the fast page query or the old join answers it: the Loopback row is
  what carries a mention from an account they do not follow, and a set of
  recipient rows built without it drops every one of them.
- `Db/PublicTimelineAuthorTest` — a page of the public timeline is `limit`
  posts when its newest posts have no cached author: they are left out where
  the page is chosen, not after, so they take no place on it.
- `Db/MarkedTimelineAuthorTest` — the same for a page of bookmarks or
  favourites: posts without a cached author are left out where the page is
  chosen, and the page is `limit` posts.
- `Db/DurableCacheTableTest` — the table behind `DurableCache` on an instance
  with no memcache: a read ignores an expired row, an insert over an existing
  key is skipped rather than raised, the purge takes only what has expired.
- `Db/ActorRelationRequestTest` / `Db/StreamFilterTest` — block/mute storage and
  the hidden-actor anti-join on every timeline.
- `Db/ListsRequestTest`, `Db/TrendsRequestTest`, `Db/DiscoveryRequestTest`,
  `Db/ConversationsRequestTest`, `Db/StreamInterestsTest`,
  `Db/CacheDocumentsRequestTest` and one `Db/*RequestTest` for each smaller
  table (reactions, interests, featured tags, portfolios, media tags, stories,
  places, post holds, quote grants, revisions, imports, emoji and GIFs,
  channels and teams, access blocks, account notes, mutes, stream views,
  watches, trend review, Discover categories) — the request classes whose SQL
  no test had run. `Db/InstanceStatsRequestTest` caught the weekly activity
  counting zero statuses: its window was bound as integers against a DATETIME
  column.
- `Db/ActorCascadeTest` — deleting a Nextcloud user (`IUser::delete()`, so the
  listener and the whole cascade run) leaves no row in any table that names
  their account, and another user's rows untouched. A schema guard fails when
  a table with an account-like column is neither cascaded nor listed as kept
  or as a known gap.
- `Db/BannerCascadeTest` — deleting a Nextcloud user takes their profile
  banner with it: the document row, its file and its resized copy.
- `Db/StreamMediaTest` — a post's stored attachment copies and the
  `social_stream_media` links: paging by nid, `carriesUpload()` (which failed
  on every database until this test ran it), relinking on a rewrite, remote
  recovery, and rebuilding a document's copy on the posts that carry it.
- `Db/StreamSaveAtomicityTest` — a post and its recipient rows are stored
  together or not at all; a post saved twice is stored once without an error
  in the log, and a post whose nid is already taken is stored under another.
- `Db/StreamDeletionTest` — `deleteRelatedTo()` empties every table that keys
  on a post and nothing of another post, `deleteByPrims()`, a domain's authors
  for the instance purge, and the notification sweep's selection.
- `Db/StreamThreadsTest` — the public `replies` collection and its
  `totalItems` agree and leave out every reply that is not public; the
  viewer-filtered reply list.
- `Db/TimelineValidatorsTest` — the newest-nid probes a timeline's entity tag
  is built from, and the unread notification badge.
- `Db/SearchHydrationTest` — a content search, through the word index and
  through the scan, finds what the viewer may see and not a stranger's
  followers-only post, and chooses those posts over the nid: no statement that
  reads the posts' columns joins the viewer's follows.
- `Db/SearchTermsRequestTest` / `Db/StreamTagsRequestTest` — the word index
  (index, edit, whole words and prefixes, backfill) and the hashtag rows.
- `Db/StreamStatisticsTest` — the grouped queries behind the statistics page
  and the activity chart: public posts only, conversation partners both ways,
  boosters once each.
- `Db/SocialCrossQueryBuilderTest` — the joins on their own; above all that a
  boost hands the viewer the post it repeats only where they may read it.
- `Migration/RowBackfillMigrationsTest` — the upgrade steps that fill rows
  (recipient nids, `media_kind`, `news_kind`, OAuth authorizations moved to
  `social_client_auth`) run on rows in their pre-upgrade shape, twice.
- `Migration/RepairMemoryTest` — the upgrade steps that walk a table (the
  recipient-nid backfill, `BackfillRemoteVisibility`, `BackfillStreamPostFields`,
  `CacheFeaturedCollections`) and the blind key rotation run over a table five
  times as large: the peak memory must not grow with it (`PeakMemory`: within
  a fifth of the smaller run, or 512 KB).
- `Db/ActorsWalkTest` — `ActorsRequest::walk()` visits every local account
  once across pages while each is written to, keyed on the stored `id_prim`
  even where the social URL changed since the accounts were made.
- `Db/ActorsRowIdentityTest` — accounts stored under an earlier social URL:
  a key rotation and a profile write land on the account's row and on no
  other, and the paged walk carries on from the prim the row is stored under.
- `Migration/BannerParentBackfillTest` — `Version1000Date20261012507100` hangs
  a banner stored without a parent off the account its handle names, leaves an
  upload and a banner of a handle no actor has alone, twice.
- `Migration/ShortKindBackfillTest` — `Version1000Date20261012500100` works out
  the stored videos once (a short, a long one, a landscape one, one without
  meta), leaves a stored answer and every non-video post alone, twice.
- `Migration/UpgradeFromOldReleasesTest` — the squash and
  `Version1000Date20261011500100` on tables put back in the shape a release
  before 0.23 left: duplicate follow pairs removed before the pair is made
  unique, the indexes the history dropped dropped, a second run a no-op.
- `UserMigration/AccountRoundTripTest` — a whole account (posts with an
  uploaded picture, a content warning and a poll, local and remote follows
  and followers, lists, a v2 filter, bookmarks, favourites, a profile with
  fields, banner, avatar and flags, a timed mute, one hiding notifications, a
  block, a blocked server) out through `SocialMigrator` via
  `MigrationArchiveService::export()`, into a fresh account and through the
  posts import, compared view by view through the Mastodon API
  (`UserMigration/RoundTripTestCase`). What is documented to stay behind is
  pinned as such: no private key or OAuth token in the archive, the avatar
  left to core's migrator, followers a record, no post re-created by the
  account import, a poll back as its text — and so are filters and a list's
  replies policy, which nothing carries. Caught an export that carried no
  banner, an archive without the lists and blocked servers its one-file
  downloads promise, and a banner Update signed with no key.
- `UserMigration/AccountRoundTripImportTest` — the import half of the above on
  a second server; skipped unless `SOCIAL_ROUNDTRIP_BUNDLE` names a bundle.
  See "Two servers" below.
- `UserMigration/MastodonExportImportTest` — an export shaped after
  Mastodon's documented format (`UserMigration/MastodonExport`: `outbox.json`,
  `actor.json`, `likes.json`, `bookmarks.json`, the media, and the six CSV
  files) through `PostImportService` and the `MigrationService` imports,
  compared through the API: the boost and the direct message skipped, the
  reply hung off its parent, a second run a no-op — and `likes.json`,
  `bookmarks.json` and `actor.json` pinned as read by nothing.
- `Helper/RowSeeder` — writes a row into any of the app's tables from the
  installed schema, filling what a test does not name.
- `PeakMemory` — the peak memory a block reached above what was in use when it
  began, and the tolerance two runs of a paged step may differ by.
- `QueryCounter` / `QueryCounterTest` — records every statement the server's
  connection executes during a block (through Doctrine's SQL logger, swapped in
  for the block and put back after), with its parameters; the test proves it
  counts what the server's own counter counts.
- `Api/QueryBudgetTest` — query budgets per page of the client API, driven
  through the controllers as a signed-in reader (`Api/ReaderTestCase`): home (fast
  and join path), public, notifications, an account's statuses and its boosts, a status and
  its thread, search, and a byte range of a media file. A page of twenty must
  cost what a page of five costs (no query per post) and stay within today's
  count plus two.
- `Api/LimitClampTest` — every `GET` route taking `int $limit`, found by
  reflection, asked for 500 (the most Nextcloud's dispatcher lets through),
  100000 and -1: each `LIMIT` that reaches the SQL must be one a request at
  the route's documented maximum, at 1 or at its default also produced. A
  route whose page is not read by a query is listed with what bounds it.
- `Api/BoostByIdTest` — a boost asked for by its own id carries the boosted
  status in `reblog`, decorated for the reader as on a timeline, where the
  reader's favourite now shows on the boosted status too; a boost of a status
  the reader may not see is a 404.
- `Service/AvatarChangeFederationTest` — a new profile picture reaches the
  followers as an Update{Person} whose icon address nobody has seen yet, also
  after another process (`Helper/rename-elsewhere.php`, a rename that makes
  Nextcloud draw the initials again) moved the picture's version where this
  process's copy of the account's settings could not see it.
- `Db/VideoViewsRequestTest` — the view count of a video posted here on the
  real unique indexes: a viewer seen twice on a day is a no-op rather than an
  error, the counter moves by one and is read for a page at once, and only the
  dedupe rows of earlier days are forgotten, never the number.
- `Service/VideoViewCountingTest` — `VideoViewService` against the database:
  a viewer (account or session) counts once a day and again the next, the
  author does not count, the stored rows name neither the account nor the
  session, and once the day is swept nothing about who watched is left.
- `Service/VideoPosterBackfillTest` — a stored video with no poster, a real
  ffmpeg and the real database: the video gets its frame, the post carrying it
  is rewritten to show it, and a file with no frame in it is passed over once
  and not tried again. Skipped where ffmpeg is not installed.
- `Api/FollowerCountsTest` — a new account's follower count moves from its
  first follow (its first cached row carries the counters rather than "never
  counted"), and a reader who hides the numbers is still sent every account's
  real `followers_count` through the real dispatcher and middlewares while a
  status's numbers are 0.
- `Api/PollInteractionsTest` — a poll is liked, boosted and taken back
  through `POST /api/v1/statuses/{id}/{act}` like any status, and the answer
  carries the reader's flags.
- `Api/AccountBoostsTest` — `GET /api/v1/accounts/{id}/statuses` lists the
  account's boosts beside its posts, each with the boosted post in `reblog`,
  to a follower, the boosted author, the booster and nobody; an account whose
  only status is a boost is not an empty list; `exclude_reblogs` and a page
  narrowed to media leave the boosts out; a boost of a post the reader may
  not see is left out; and paging two at a time on `max_id` walks boosts and
  posts together, each once.
- `Api/AccountStatusesNarrowingTest` — `exclude_replies` on an account's
  statuses keeps its threads and boosts and leaves out its replies to other
  accounts; `tagged` keeps its posts with the hashtag, with or without `#`
  and in any case, and no boosts; both a page of twenty and two at a time on
  `max_id`.
- `Api/OnlyNewsListTimelineTest` — `only_news` on a list timeline: three
  posts that link somewhere under forty newer text posts are the page of
  twenty, newest first, and paging two at a time keeps `only_news` in `Link`.
- `Api/ClientApiCorsTest` — the client API as a browser client of another
  origin meets it, through the real dispatcher and every middleware the app
  registers: a bearer call, an anonymous read and a refusal readable from any
  origin, the preflight answered without credentials, a web-app route outside
  the API bases closed, and a session cookie from another origin without its
  CSRF token answered 401 while the web app's own call still signs in.
- `Api/FirstSignInFromAppTest` — a Nextcloud user with no account here signs
  in from an app: `GET /oauth/authorize` asks for a username with the request
  kept, a forged `redirect_uri` is refused without that, the account made
  through `accountCreate()` gets the consent from the same request, then a
  code, a token and `verify_credentials`; before that, a Mastodon route, a
  client-API route and a web-app route answer 403 `account-not-set-up` with
  the setup page's address, by web session and by app password.
- `Db/DetailsCompareAndSwapTest` — the compare-and-swap an edit's `mentions`
  are written with: a `details` blob still as it was read is replaced, one
  changed in between (or NULL against `{}`) is left alone, and the mentions
  are set on top of what another writer stored.
- `Db/PublicPagePlanAtScaleTest` — slow, only with `SOCIAL_BENCHMARK=1`, on
  MySQL/MariaDB and PostgreSQL: seeds 50,000 posts with `occ social:benchmark`
  and asserts the page query of the public timeline and of its Photos
  narrowing is one index range with no sort, no temporary table and no full
  read; removes the seed afterwards.
- `Db/QueryPlanTest` — the plan of each hot query, recorded from the production
  method and handed to `EXPLAIN` verbatim: home (fast path on `social_sd_atn`,
  join path), Photos and Videos on home, public and a profile, an account's
  statuses, the hashtag page (whose follows must be probed in a subquery,
  not joined), notifications, the delivery queue's standby read, follower
  inboxes and the handle lookup. SQLite: every guarded table is searched
  through an index, never scanned. MySQL: a key and no full read, no filesort
  or temporary table unless the query is listed as one that sorts.
  PostgreSQL: no `Seq Scan` on a guarded table. MySQL and PostgreSQL are
  seeded to ~2,000 posts and `ANALYZE`d first.
- `Db/RepairWalkTest` — the repairs `occ social:check:install` runs, over 1,200
  notes and 600 follows: orphans removed, the rest kept, statements bounded by
  two a page plus the deletes, and the peak memory under 4 MB.
- `Cron/QueueWorkBudgetTest` — one pass of the delivery cron over a real
  queue of 0, 200 and 400 deliveries, with only the network edge replaced and
  the clock frozen: a constant cost per pass, two statements per delivery and
  three per batch, and the second batch costing exactly what the first did.
- `Cron/CacheWorkBudgetTest` — one pass of each `Cron\Cache` step with the
  network edge replaced (`OfflineContainer`: every server unreachable, the
  clock frozen) against a backlog three times its batch: the remote-actor and
  details refreshes (forty batches of fifty), the timeline sync, the sweep,
  the local-actor refresh, deleted accounts, the prune, the remote counts and
  the document cache each take at most their batch at a constant cost per
  item; the trends write only the hashtags that moved.
- `OfflineContainer` — builds the app's services from their constructors with
  chosen dependencies replaced at any depth, the rest from the app container.
- `Cron/RunImportMemoryTest` — a blocked-domains import run by `Cron\RunImport`
  the way cron runs it, over 1,000 lines and then 5,000: nothing is kept per
  row beyond the list the service reads whole by design.
- `Api/*` — the Mastodon client API called the way a client calls it: real
  accounts with a bearer token, each controller built by a fresh app
  container, the answer put through `json_encode()` as the JSON responder
  does (`ApiTestCase`). `Api/StatusMentionsTest` caught every status written
  here reaching clients with `mentions: []`; `Api/StoredSourceTest` an edit
  or a vote storing the client entity where the post's ActivityPub document
  belongs; `Api/FamiliarFollowersTest` the reader listed among their own
  familiar followers; `Api/ReactedByTest` every account of `favourited_by`
  and `reblogged_by` answered as `"id": "0"`, `Api/BoostedAuthorTest` the
  same of the author of every boosted post and notified-about status. `Api/InstanceConfigurationTest`
  holds the limits `/api/v2/instance` announces against what a post is
  held to, and caught URLs counted at their full length where the instance
  says 23. `Api/MastodonEntitySchemaTest` validates every Mastodon entity
  the routes answer with against the schemas in `tests/Api/Schema`, by type;
  `Api/PaginationTest` asks every paged list the same questions — `max_id`
  exclusive, `since_id` the newest page, `min_id` the adjacent one, the
  `Link` cursors — with three pages of rows behind it.
  `Api/OnlyShortTimelineTest` asks `only_short` of home, public, a hashtag,
  a list, a profile and For you, with three shorts under four other videos and sixty
  newer posts of text: a page of twenty is the three, and paged two at a
  time the `Link` header keeps the filter.
  `Api/MarkedPostVisibilityTest` holds `/api/v1/bookmarks` and
  `/api/v1/favourites` to what the reader may read now, and a bookmarks
  import to the posts the importing account may read.
  `Api/MarkOrderTest` lists favourites and bookmarks in the order they were
  marked — an old post marked today first — and pages them on the mark.
  `Api/NotificationSenderTest` narrows `/api/v1/notifications` and
  `/api/v2/notifications` to one sender with Mastodon's `account_id`.
  `Api/AppTokenTest` takes an app token from `/oauth/token` with the
  `client_credentials` grant and holds it to what anybody may read: the
  instance, the public timeline and the app's own credentials, and a 401
  from every route that acts for an account.
  `Api/MediaFromFileSessionTest` attaches a Nextcloud file through the web
  app's session and through a Nextcloud app password with `OCS-APIRequest:
  true`, and refuses a bearer token holding every scope and an app password
  sent without the header.
  `Api/ReplyParentVisibilityTest` answers a reply to a post its writer may not
  read exactly as a reply to a missing one, through the client API and the
  post service.
  `Api/SameStatusEverywhereTest` serves one reacted-to post through the home
  timeline, `GET /api/v1/statuses/{id}`, its thread, a list, a place, a
  collection, the trends, a favourite's answer, the photos somebody is in, a
  portfolio, the archive and the videos to continue, and holds each to the same
  `reactions` with `me` for the reader, and the single status to its place
  and tagged people; it caught the single status answering `reactions: []`.
  `Api/SignedInPagesTest` sends the reader's own pages — settings,
  statistics, migration, moving in, follow requests, blocks, and their own
  timelines — through Nextcloud's dispatcher and middleware: a visitor is sent
  to the login with the address to come back to, a signed-in reader gets the
  page, the public pages stay public, and every read those pages make of the
  reader's own data refuses somebody not signed in.
  `Api/PlaceKeptTest` posts with a `place_id` the search route handed out,
  of a place known without coordinates, and finds the place on the answer
  and on the post read back.
  `Api/SignInTokenRoutesTest` calls the account pages a client app opens —
  memories, statistics, channels, the migration exports, imports and move-in,
  the authorized apps — five ways: a bearer token granted the route's scope,
  one granted another (403), nobody (401), the web page's session with its
  CSRF token, and a Nextcloud app password signed in by Nextcloud with
  `OCS-APIRequest: true`; and a token revoking its own authorization but not
  another app's, which the session still may.
  `Api/VisibilityMatrixTest` asks every read path — the client API's
  statuses, timelines, search, notifications, marks and conversations, the
  ActivityPub object and collections signed and unsigned, RSS, oEmbed, the
  browser page, the unified search and reference providers and the dashboard
  widgets — what it shows of a public, unlisted, followers-only and direct
  post, a quote and a boost of a followers-only one, to the author, a follower,
  a pending follower, a mentioned account, a stranger, nobody, an account the
  author blocked and one that muted the author, against the one table in
  `Api/VisibilityMatrix`. The unit suite's `AppInfo/VisibilityMatrixRoutesTest`
  fails for a route that serves posts and is not in that table.
- `Atproto/OAuthStorageTest` — OAuth for Bluesky apps: a request approved once
  and its code exchanged once, a refresh token rotated only from the good one
  (the replaced one finds the session to end), one-time DPoP ids taken once by
  the unique index, a person removing only their own sessions, expired rows
  pruned.
- `Atproto/AtprotoBookkeepingStorageTest` — account moves with their JSON
  progress and lookup by direction, the PLC operation log in order with what is
  unconfirmed, firehose rows numbered in order with their bytes whole and pruned
  past the window, the instance blocklist and labeler subscriptions added once.
- `Atproto/AtprotoFiguresStorageTest` — the reads behind the administrators'
  Bluesky figures: the posts written here in a window, counted from its first
  id (a moved-in record and another collection left out), the repository
  totals summed from the heads, and the newest event's time.
- `Atproto/CommitBenchmarkTest` — skipped unless `SOCIAL_BENCHMARK` is set:
  seeds a repository of twenty thousand records and prints what one commit
  costs against reading every record and rebuilding the tree, asserting the
  commit is the cheaper of the two.
- `Atproto/RepoExportMemoryTest` — `com.atproto.sync.getRepo` writes the CAR
  out as the repository is read, a page of rows at a time: the peak over
  3,000 records of 4 KB is the peak over 600, and the pieces joined decode
  to the blocks the one-string export has.
- `Atproto/BlueskyArchiveTest` — "Download your Bluesky account" against a
  real repository: the zip's `repo.car` decodes to the blocks the repository
  has, rooted at its head commit, and `plc-log.json` holds the operations this
  server confirmed when the directory is not asked.
- `Atproto/ListReposQueryBudgetTest` — `com.atproto.sync.listRepos` asks for a
  page's identities and suspended accounts at once: a page of fifty costs the
  queries a page of five does, and a suspended account is listed `takendown`.
- `Command/*` — the occ commands, driven through Symfony's `CommandTester` with
  each command built by the real container, so a constructor these tests cannot
  satisfy is one occ cannot satisfy either. `ResetTest` and `CheckInstallTest`
  cover the destructive paths without taking them: both refusal paths (a
  declined prompt, and `--no-interaction` without `--force`, which used to exit
  0 having done nothing) and the `--uri` option the app's own advice names.
  `QueueRetryTest` and `QueueStatusTest` drive the delivery queue through a
  failure and back out again; `StreamPruneTest` covers the retention guard and
  `--dry-run`.
- `Federation/DeliveryUnderFaultsTest` — the delivery cron against fake peers
  that misbehave (`Helper/FaultServer`, a PHP built-in server per peer, each a
  host name of its own under `.localhost`): a peer that hangs does not hold up
  the others in its wave — which caught the waves going out one request after
  another — and costs one timeout however many rows it has; a 500, a 429 or
  503 with `Retry-After`, a timeout and a redirect loop each keep the row and
  open the breaker, for as long as `Retry-After` asked (seconds or a date) up
  to the breaker's hour; the retries follow the documented schedule (16 s, 31 s,
  96 s) while the breaker doubles (60, 120, 240 s) and lets go once the peer
  answers; across a mix of failing peers every row is acknowledged exactly
  once; and an answer cut off mid-body is sent again.
- `Federation/InboundCorpusTest` — the inbound fixture corpus (Misskey,
  GoToSocial, Akkoma, Friendica, Lemmy, Mastodon 4.5 quotes and polls, Block,
  Flag, account Delete, PeerTube Dislike/View/CacheFile, Threads, WordPress,
  Ghost, Bridgy Fed) delivered through `ImportService` and the real inbox
  interfaces, asserting the stored row and the Mastodon entity it is served
  as; it caught Mastodon's inlined `QuoteRequest.instrument` being dropped.
  `Federation/InboxTestCase` is the shared delivery harness.
- `Federation/RedeliveryOrderTest` — the same activity delivered twice leaves
  the rows of the first delivery and a usable connection; an Update before
  its Create, two edits in reverse order (caught an older edit overwriting a
  newer one), a Delete before its Create (caught the late Create resurrecting
  the post), an Undo{Like} before the Like (the late Like stays undone), and a
  rotated key arriving in an Update{Person} verifying the next signature.
- `Federation/OverlappingRedeliveryTest` — a Follow, Like, Announce and Create
  delivered again while the first copy is still being handled, replayed by
  making the second delivery's lookups miss the way the first copy's did: one
  row, counted once, no exception (the UNIQUE violation this used to raise
  was a 500 and a third delivery), the Accept sent again, and a usable
  connection afterwards. Worth running on PostgreSQL and MySQL too.

- `Helper/FreezesTheClock` — puts the server on a `FrozenClock`
  (`tests/Helper/FrozenClock.php`) that the test moves: registered on the
  server container and on each fresh app container a service or controller is
  built by, so the lifecycle tests below stand on the second before an expiry
  and on the second itself. The server container gets a `Helper/ServerClock`
  around it, which thawing switches back to the real time.
- `Helper/FreezesTheClockTest` — a service the boot container handed out
  before the freeze and first used during it is back on the real clock once
  the test thaws it (caught a frozen January clock leaking into later tests in
  random order: a story stamped then, an LD signature judged against it).
- `Api/TimedMuteExpiryTest` — a timed mute through its expiry: the
  relationship, `/api/v1/mutes` and the home timeline agree that it holds a
  second before it runs out and is gone on that second; muting again without
  a duration makes it permanent; an expiry at a leap day, a month or year end
  or a daylight-saving change comes back from the database as the same instant.

- `Api/ScheduledPostLifecycleTest` — a post scheduled through
  `POST /api/v1/statuses` and the cron's runs around it: still waiting a
  second before it is due, published on that second, not again on the next
  run; `scheduled_at` written with and without an offset at a leap day, a
  month and year end and in the hours a daylight-saving change skips or
  repeats goes out on the instant it names; the daily cap of 25 counts the
  day the posts are for.

- `Api/PollLifecycleTest` — a poll through its end on a frozen clock, today
  and with the end time on a leap day, a month or year end and after a
  daylight-saving change: a vote a second before the end is counted, the poll
  is `expired` and refuses votes on its end time, the sweep on that second
  tells the voter and a later one nobody, and the results stay the ones
  announced; a vote from another server is counted until the end time and
  swallowed on it. It caught a vote taken on the very second the sweep had
  announced the poll closed, and the poll notification's status served with
  `poll: null`.

- `Federation/EarlyDeleteExpiryTest` — the six hours a Delete that came
  before its Create is remembered, on a frozen clock threaded through the
  activity registry: a Create in the last second of them is dropped, one on
  the second they end is stored, and a Delete by another account of the same
  server holds nothing back.

- `Db/TokenExpiryTest` — the client API's OAuth lifetimes to the second on a
  frozen clock: a code exchanged on its six-hundredth second and refused on
  the next, a token idle exactly `TIME_TOKEN_TTL` still accepted and one idle
  a second longer refused and swept, and `token_max_days` counted from the
  grant across a leap day, a month end and a daylight-saving change — good on
  its last second however much it was used, revoked on the next.

- `Atproto/LoggedInOnlyPostTest` — a Bluesky post whose author carries the
  `!no-unauthenticated` self-label, stored through `PostStore`: followers-only
  in the stream, not addressed to the public, refused to an anonymous reader
  and to a stranger by the viewer-limited read, and read by a follower on
  their home timeline.
- `Atproto/AccountStatusTest` — a Bluesky account switched off, back and
  gone, against the real tables: deactivation takes the recipient rows the
  viewer-limited read joins on and leaves the post and the marked actor,
  reactivation writes the rows back and makes the watch due, a deletion takes
  the post, the cached actor and the watch.
- `Atproto/DpopReplayWindowTest` — a captured DPoP proof walked second by
  second, with the replay table pruned on each, through the three checks that
  refuse it again: its stored id until its nonce lapses, the nonce until the
  end of the window after its own, then its five-minute `iat`; a proof dated a
  minute ahead keeps its id exactly as long as it is fresh.

- `Db/StoryExpiryTest` — a story through its day on a frozen clock in the
  past, posted on ordinary days, leap days, month and year ends and before
  daylight-saving changes: the profile, the bar, the count and opening it all
  show it on its last second and none does on the second its day is up, when
  the hourly sweep takes it and not before.

- `Db/TrendWindowsTest` — the five trend windows to their edges on a frozen
  clock: a status trends until its interactions are exactly a window old and
  a link until the posts sharing it are, while the cron's hashtag count still
  takes in a post exactly a window old and leaves out one a second older.

## Running

**CI** runs the suite in every `phpunit-*` job via `composer run
test:integration`: on a pull request on SQLite and PostgreSQL with the newest
stable server (and SQLite on master for coverage), and every night on SQLite,
MySQL and PostgreSQL across the supported server versions, and MariaDB 10.11 on
the newest. Every night `upgrade.yml` runs it once more on an instance upgraded
from the latest release with data in it, so a test has to hold on an instance
that is not empty, and `integration-robustness.yml` runs it three times on one
database, on SQLite, MySQL and PostgreSQL: once, again on the same database, and
in random order with a fresh seed, which it prints so the order can be replayed:

    vendor/bin/phpunit -c tests/Integration/phpunit.xml --order-by=random --random-order-seed=<seed>

A test that only passes on a fresh database, after some other test or before
its own leftovers, fails there. Two more legs do the same on SQLite with a
memcache — APCu as `memcache.local`, and Redis as `memcache.distributed` and
`memcache.locking` — because code that caches takes other paths on a server
that has one.

**Locally**, use a disposable Nextcloud installation and database. The tests
create users, run repairs and temporarily change app configuration as well as
writing test rows. Cleanup is not a guarantee after an interrupted run; do not
point the suite at a production database or your everyday development data.
The app directory may be a symlink into the test installation:

    NEXTCLOUD_ROOT=/path/to/test-nextcloud vendor/bin/phpunit -c tests/Integration/phpunit.xml

An existing Nextcloud core can also serve an isolated test installation through
`NEXTCLOUD_CONFIG_DIR`. Create a separate configuration directory, SQLite data
directory and `apps_paths` entry pointing to the candidate Social checkout;
install Nextcloud and enable Social using that same configuration. Give it its
own CLI URL, distinct from the running application. Verify `occ app:getpath
social` resolves to the candidate before starting:

    NEXTCLOUD_CONFIG_DIR=/path/to/test-config php /path/to/nextcloud/occ app:getpath social
    NEXTCLOUD_CONFIG_DIR=/path/to/test-config NEXTCLOUD_ROOT=/path/to/nextcloud \
        vendor/bin/phpunit -c tests/Integration/phpunit.xml

Nextcloud's `tests/config/`, when present, takes precedence over
`NEXTCLOUD_CONFIG_DIR` during PHPUnit execution. In that case use a separate
server checkout with its own test configuration. A passing isolated SQLite run
does not replace the MySQL/PostgreSQL matrix or real-peer interoperability tests.

**Two servers.** `UserMigration/AccountRoundTripTest` imports on its own server
by default. To import on a really fresh one, install a second server with the
app, give it an address of its own (or the first server's accounts look local
to it) and name it:

    php occ config:app:set social cloud_url --value=http://second.localhost:8081
    php occ config:app:set social social_url --value=http://second.localhost:8081/apps/social/
    SOCIAL_ROUNDTRIP_TARGET_ROOT=/var/www/second NEXTCLOUD_ROOT=/var/www/nextcloud \
        vendor/bin/phpunit -c tests/Integration/phpunit.xml --filter AccountRoundTripTest

The export half then writes a bundle (the archive, the views of the exported
account, and the accounts and posts the second server would have fetched) and
runs `AccountRoundTripImportTest` against the second server in a phpunit of its
own. `SOCIAL_ROUNDTRIP_EXPORT_TO=<dir>` keeps the bundle; the import half runs
on its own with `SOCIAL_ROUNDTRIP_BUNDLE=<dir> NEXTCLOUD_ROOT=<second server>`.
