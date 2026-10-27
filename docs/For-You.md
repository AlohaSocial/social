<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# For You recommendations

This page describes the current ranked For You feed, not the earlier hashtag-
only design. It is an optional web-app timeline built from content already
available to the instance and bounded remote discovery. It does not promise
that every remote post has been indexed or that the ranking is better than a
chronological feed.

## What readers see

- For You is available beside My Feed and the local/federated timelines when
  the administrator enables Ranked For You.
- Photos, Videos and Shorts can use the same personalized ranking with their
  media constraints. When eligible personalized candidates are sparse, the
  feed may use bounded discovery or popular cached media to fill a page.
- Post menus provide recommendation feedback such as hiding a post, seeing
  more from an author, or seeing less like similar content. These actions do
  not follow, mute or block anyone.
- Explanations and temporary guidance are tied to the personalized feed
  context. A recommendation reason does not leak into ordinary timelines.

The current web UI does **not** let readers add or select topics manually.
That is a separate future capability. The ranker does not require manually
chosen topics: it can use content classification, followed accounts, freshness,
discovery and cached sources to build a useful candidate pool while learning is
off or sparse.

## How the feed is assembled

Candidate sources include learned/followed hashtag activity, classified post
content, followed accounts, local trends, second-degree follow relationships,
cached posts from saved Bluesky feeds and a bounded public discovery sample.
Saved-feed candidates are read from the local cache; loading a page does not
wait for an AppView request. The system applies visibility, moderation, language
and hide filters before returning posts.

One ranking combines relevance, freshness, follow relationships, explicit
feedback and (only with separate consent) behavioral signals. Re-ranking limits
repeated authors, topics, formats and similar stories while alternatives are
available. Those limits relax when the available pool is too small, to avoid
turning diversity into an empty feed. Photos, Videos, Shorts and other media
scopes have separate cursors and snapshots.

The implementation is deliberately bounded: remote discovery has a shared
request budget, feed snapshots expire, and pagination uses opaque cursors.
Underfilled pages can schedule background refresh without holding the current
request open. A failed or stale cursor asks the client to refresh rather than
silently repeating a different ranking.

## Signals, consent and privacy

The feed can learn from explicit interactions and supported reading actions,
including skips and measured video playback. Passive time spent with a post on
screen is neutral; a dwell timer is not treated as evidence of interest.
Existing legacy aggregates may still contain older dwell-based signals and
decay over time.

Behavioral learning has its own user consent and can be paused or reset. The
administrator's Ranked For You switch is separate: it controls availability of
the ranked endpoint and does not silently opt a person into behavioral
learning. Learned data is private to the account and is not federated. Export,
reset and account deletion are owner-scoped.

Qualified exposure history is stored only with behavioral consent. It uses
owner-scoped keyed fingerprints rather than raw post identifiers or story
terms, and expires after 30 days. Sensitive-topic fingerprints are excluded.
Feed snapshots and recommendation explanations are short-lived serving data,
separate from the shared status index.

## API and operations

The web app uses the ranked endpoint when the administrator enables it. The
Mastodon-compatible `/api/v1/timelines/interests` endpoint remains a separate
legacy response for clients that use it.

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/v1/recommendations/timeline` | Ranked page, media scope, coverage state and cursor |
| GET | `/api/v1/recommendations/preferences` | Read learning consent and revision state |
| PUT | `/api/v1/recommendations/learning` | Change separate behavioral-learning consent |
| POST | `/api/v1/recommendations/signals` | Record supported, consented interaction signals |
| POST | `/api/v1/recommendations/feedback` | Store reversible post/author/similarity feedback |
| DELETE | `/api/v1/recommendations/feedback/{feedbackId}` | Undo the named feedback action within its window |
| POST | `/api/v1/recommendations/reset` | Clear owner-scoped learned state with revision checking |
| GET | `/api/v1/recommendations/export` | Export supported owner-scoped recommendation data |
| GET/PUT | `/admin/recommendations` | Administrator rollout, learning and discovery switches |

The exact request and response schemas are in
[API.md](API.md). The ranked feed uses a persistent
Nextcloud memory cache for page snapshots; a supported shared backend such as
Redis is recommended for multi-worker installations. Run `occ upgrade` after
updating a release that adds a recommendation migration. See
[Admin.md](Admin.md#ranked-for-you-recommendations-switch).

## Evaluation and known limits

The deterministic offline evaluator uses synthetic profiles and posts. It is
useful for catching regressions in candidate eligibility, diversity and hard
filters; it is not a measure of real-user satisfaction or production latency.
See [ForYouEvaluation.md](ForYouEvaluation.md).

Weights are not claimed to reproduce Bluesky, Mastodon or another platform.
Recommendation quality depends on local data, remote synchronization, user
consent and available candidate diversity. Manual interest creation, changing
the ordering of an already-open feed, and proof of quality with real users are
not currently implemented.
