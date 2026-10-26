# For You offline evaluation

The offline evaluator compares deterministic feed baselines against the current
For You ranker. It does not read user data, send network requests, collect
impressions, alter serving weights, or enable recommendation features.

## Run it

From the app root:

```sh
composer run recommendations:evaluate
```

To evaluate another JSON fixture and write the report to a file:

```sh
php tools/recommendations/evaluate.php path/to/dataset.json report.json
```

The committed `synthetic-v1.json` fixture contains 12 synthetic reader
scenarios and 33 synthetic posts spanning ActivityPub and ATProto sources,
languages, formats, follow-graph sizes and sparse provider availability. It
includes hidden, unlisted, followers-only, deleted and language-ineligible
records to exercise hard controls. Example post text and people are not copied
from live accounts. The Nextcloud and open-source reader scenario includes a
four-post release-story cluster alongside separate PHP-security, migration,
and Linux-desktop stories, so its story metrics measure whether the ranker
uses available alternatives rather than only measuring repetition within one
story.

## Compared variants and metrics

The report compares the legacy hashtag ordering, chronological followed feed,
pure topic match, topic plus follow prior, balanced H0 ranker, and H0 with the
existing diversity re-ranker. It also reports the following and discovery
weight profiles, both before and after page re-ranking. Each uses the same
eligible candidate pool.

For each variant, the report includes NDCG@20, relevant results returned,
candidate count and corpus coverage, effective and unique topic diversity,
unique story clusters, the number and rate of repeated story posts, and the
share occupied by the largest story cluster, hard-control compliance,
duplicate rate, and local evaluator runtime median and p95. Story metrics
include only posts with enough text terms to identify a story;
`story_identifiable_posts_at_20` reports that denominator. Candidate source
recall is reported once for the retrieval fixture,
including a breakdown by protocol; it is separate from ranking quality.
The dataset summary also gives aggregate counts by primary candidate exclusion
reason (privacy, discoverability, visibility, hidden topic, language, or source
availability). These mutually exclusive profile-post counts contain no post
content or profile identifiers.
Paired profile-cluster bootstrap intervals compare each variant with pure
topic match. The fixed seed makes those intervals reproducible.

The fixture labels are synthetic scenario judgements authored by one
contributor. They are not multi-rater human judgements, real-user satisfaction,
or statistically representative data. Bootstrap intervals describe variation
across these profiles only. Runtime numbers measure this local PHP process and
are not production-serving latency. Optional embeddings are intentionally not
included; they remain gated on F1-F6 evidence.

Do not promote weights or describe a model as better from this report alone.
Use it to catch regressions, expose hard-control failures, compare approaches
on explicit scenarios, and decide which variants need independent human review
and a larger consent-reviewed dataset.
