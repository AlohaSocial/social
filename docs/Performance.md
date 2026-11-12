<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Performance and scalability

**Reviewed against:** Aloha Social **0.26.298** on **2026-10-27**; source
branch `main` at `33935993`. The latest successful repeatable benchmark
available that day is the [Nightly performance run 40426921866](https://github.com/AlohaSocial/social/actions/runs/40426921866).
It ran the benchmark from `main` commit `2ab03f1` (app **0.26.278**), so it is
behind this checkout. No benchmark of 0.26.298 itself was available
at review time.

## Latest CI query snapshot

The workflow seeded 100,000 public posts from 2,000 actors, with 300 followed
accounts, enabled the search index and recipient `nid` backfill, then took the
median of five runs. It used PHP 8.3.36, PostgreSQL 14 and MySQL 8.4 on GitHub
Actions. Values are milliseconds for a page of 20 records:

| Query | MySQL 8.4 | PostgreSQL 14 |
|---|---:|---:|
| Home timeline | 27.96 | 57.80 |
| Public timeline | 8.74 | 12.07 |
| Notifications | 14.88 | 88.98 |
| Photos | 11.33 | 45.54 |
| Videos | 16.24 | 52.28 |
| Hashtag | 21.59 | 58.91 |
| Account posts | 10.08 | 42.32 |
| Search | 35.37 | 80.77 |

All populated probes returned the requested 20 rows and stayed under their
configured guardrails in that run. The direct-message probe returned **zero
rows** in both databases, so its timing is not evidence about a populated
inbox. Page-size-50 timings and row counts are in the run artifacts. The run
checks query behavior on a synthetic fixture; it does not represent the spread
of real media, follow graphs, blocks, retention or database hardware.

## What the benchmark covers

`occ social:debug:benchmark` seeds plausible actors, posts, recipients, hashtags,
notifications and search terms, runs an `ANALYZE`, then times the query and
hydration path for each probe. The [nightly workflow](../.github/workflows/perf-nightly.yml)
runs it on both supported CI database families and keeps the JSON reports as
90-day artifacts. A probe over its explicit ceiling or its configured baseline
factor fails that job.

The benchmark is read-focused. It does not measure ActivityPub delivery,
remote outbox imports, Bluesky relay/AppView indexing, cron backlog, media
transcoding, first paint, or production latency. The direct-message dataset is
currently empty. These are known coverage gaps, not known performance failures.

## Running a comparable local benchmark

Use a disposable development Nextcloud database: seeding writes 100,000 rows
and is not suitable for a production instance. The command and cleanup options
are documented in [OCC-Commands.md](OCC-Commands.md#socialbenchmark).

```sh
php occ social:debug:benchmark --viewer=admin --actors=2000 --notes=100000 \
  --follows=300 --seed-only --force --no-interaction
php occ social:debug:benchmark --viewer=admin --time-only --runs=5 --output=json_pretty
php occ social:debug:benchmark --clean --force --no-interaction
```

Record the Social commit, Nextcloud version, PHP version, database version,
cache/index state, seed parameters and run artifact with any new measurements.
Compare like with like; do not copy an older benchmark into the baseline file
without reviewing its guardrail effect.

## Counts of posts on screen

The web app keeps the like, boost, reply and quote numbers under visible posts
current without re-reading whole statuses:

- **What it asks.** `GET /api/v1/social/status_counts` with up to twenty ids
  answers the five numbers of each post and nothing else. It records no view,
  so scrolling writes no `social_stream_view` rows and sends no PeerTube
  `View`s.
- **When it asks.** A timeline watches its entries with an
  `IntersectionObserver` and asks once scrolling has rested for 1.5 s, then
  every 60 s for the posts still on screen. A post is never asked about twice
  within 50 s, and nothing runs per scroll event. An opened post is asked
  about every 30 s. Opening a post (`GET /api/v1/statuses/{nid}`) is still a
  full read and counts as a view.
- **When the origin is asked.** `CountService::VISIBLE_WINDOWS` sets how long
  a post's counts stand once read, by the post's age: 10 s under 15 minutes,
  30 s under an hour, 1 minute under 6 hours, 5 minutes under a day,
  15 minutes under a week, 1 hour after that. The window is shared by every
  reader through the durable cache, so it caps origin load per post, not per
  reader. The due posts of one request go to their networks together, at most
  one ActivityPub round and one AppView `getPosts` call, within the
  three-second interactive timeout.

For scale: before this, a timeline asked for up to four whole statuses every
5 s and on every scroll event, and each could start origin reads on a 5 s
window. That is about 80 requests a second for 100 idle readers. Now 100 idle
readers showing about eight posts each make under 2 requests a second, and an
old post costs its origin at most one read an hour however many people have
it on screen.

## Current performance risks to investigate

- **Synthetic query results may not match real distributions.** Follow graph
  shape, post visibility, notification policies and media density can change
  query plans. Compare query plans and latency at multiple instance sizes before
  adding indexes or denormalized data.
- **Federation freshness is a separate latency budget.** A fast timeline query
  can still show stale remote data when cron, a poller, relay or remote server is
  delayed. Diagnose queue and synchronization health before attributing that
  symptom to SQL.
- **Large multi-protocol reads have external dependencies.** Bluesky AppView,
  Chat, PLC and relay calls and ActivityPub remote imports must be timed and
  reported separately from local DB response time.
- **The Mastodon streaming daemon is one process polling for every
  connection.** Once per second it reads the newest stored post id once for
  all subscribers and reads a subscriber's timelines only when that moved; the
  notification, direct, public and hashtag probes are further gated by their
  own index-only newest id, edits by one batched membership query per
  subscriber, and everything is read regardless every 30 seconds. On a busy
  instance the newest id moves every second, so each subscribed home timeline
  is still read about once per second; up to 256 connections share one loop.
  Measure the pass time at that size before raising the connection limit.
- **The benchmark does not yet populate direct messages.** Add representative
  conversations before making claims about inbox scalability.

For schema and data-flow context, see [Architecture.md](Architecture.md). For
operator checks and queue health, see [Admin.md](Admin.md).
