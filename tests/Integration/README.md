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
- `Db/ActorsKeyStorageTest` — private keys land encrypted, read back decrypted,
  legacy plaintext rows stay readable, the EncryptPrivateKeys repair converts
  them idempotently.
- `Db/ClientCredentialStorageTest` — OAuth secrets/codes/tokens land hashed,
  plaintext tokens resolve through the hashed lookup, re-authorization
  invalidates the old token, revocation works, a legacy plaintext row is
  refused and the hashing migration converts it.
- `Db/StreamActionsFlagsTest` — the per-viewer flags are written field-wise and
  idempotently.
- `Db/ReplyCountTest` — the reply counter on a post: a recount counts what is
  stored, counts one fewer after a delete, and carries the replies that live on
  the post's own server across untouched.
- `Db/RequestQueueLifecycleTest` — standby → running → deleted on success,
  failures counted and retried, abandoned after MAX_TRIES, stale RUNNING rows
  reaped back to standby.
- `Db/OnlyMediaTimelineTest` — the predicate behind the Photos view: posts with
  attachments are kept and all three spellings of "no media" (NULL, `''`, `'[]'`)
  are dropped, and a post carrying several pictures is still one post.
- `Db/FollowedTagsTest` — the followed-hashtag table and the second half of the
  home timeline: the unique index that makes following twice a no-op, paging on
  the row id, a 127-character multi-byte tag, and the join itself — a public
  post carrying a followed tag reaches home whatever case its author typed the
  tag in, a followers-only one does not, and a post carrying two followed tags
  is listed once.
- `Db/HomeMentionTest` — a post that names the reader is on their home timeline
  whether the fast page query or the old join answers it: the Loopback row is
  what carries a mention from an account they do not follow, and a set of
  recipient rows built without it drops every one of them.
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
- `Command/*` — the occ commands, driven through Symfony's `CommandTester` with
  each command built by the real container, so a constructor these tests cannot
  satisfy is one occ cannot satisfy either. `ResetTest` and `CheckInstallTest`
  cover the destructive paths without taking them: both refusal paths (a
  declined prompt, and `--no-interaction` without `--force`, which used to exit
  0 having done nothing) and the `--uri` option the app's own advice names.
  `QueueRetryTest` and `QueueStatusTest` drive the delivery queue through a
  failure and back out again; `StreamPruneTest` covers the retention guard and
  `--dry-run`.

## Running

**CI** runs the suite in every `phpunit-*` job (SQLite, MySQL, PostgreSQL across
the supported server versions) via `composer run test:integration`; the workflows
pick the script up automatically now that it is defined.

**Locally**, point the bootstrap at an installed server (the app directory may be
a symlink into it):

    NEXTCLOUD_ROOT=/var/www/nextcloud vendor/bin/phpunit -c tests/Integration/phpunit.xml

All rows the tests create carry unique test-only ids and are removed in
tearDown, so the suite is safe to run against an instance that holds other data.
