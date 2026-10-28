<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Technical debt and current maintenance status

**Reviewed against:** Aloha Social **0.26.280** (`appinfo/info.xml`) on **2026-10-27**; source
branch `main` at `33935993`. Counts below were measured from
that checkout. They describe code size and maintenance boundaries; they are not
a quality score, a claim that every test is green, or a production audit.

## Current snapshot

| Area | Current observation | What it means |
|---|---:|---|
| PHP under `lib/` | 1,097 files; 233,484 lines | Large backend surface spanning Nextcloud, ActivityPub and AT Protocol. |
| Vue/JavaScript under `src/` | 338 files; 101,686 lines | The web client has become a substantial product in its own right. |
| HTTP route attributes | 637 declarations | API documentation and route tests must stay synchronized; this is a declaration count, not a count of unique URLs. |
| Migration classes | 67 | Upgrade paths are part of the supported product; schema edits need fresh-install and upgrade coverage. |
| `TABLE_*` constants | 101 | The central table registry is a broad map of the schema, not a direct SQL table count. |
| `@deprecated` markers in `lib/` | 11 | Some compatibility helpers remain. Remove a marker only after callers and supported integrations no longer need it. |
| Explicit `TODO:` / `FIXME` comments in `lib/` and `src/` | 0 | This search does not rule out undocumented work or risks. |

Reproduce the source counts from the repository root:

```sh
find lib -name '*.php' | wc -l
find lib -name '*.php' -exec cat {} + | wc -l
find src -type f \( -name '*.js' -o -name '*.vue' \) | wc -l
find src -type f \( -name '*.js' -o -name '*.vue' \) -exec cat {} + | wc -l
rg -n '#\[(FrontpageRoute|ApiRoute)' lib | wc -l
rg -l 'class Version1000Date' lib/Migration | wc -l
rg -c 'public const TABLE_' lib/Db/CoreRequestBuilder.php
rg -n '@deprecated' lib | wc -l
rg -n '\b(TODO:|TODO -|FIXME)' lib src
```

The declared support range is Nextcloud 34–36 and PHP 8.3–8.5. `package.json`
requires Node 24 and npm 11. Local CLI versions are not a substitute for the
CI compatibility matrix.

## Maintenance items with ongoing cost

### PDS blob lifecycle (feature-branch review, 2026-10-28)

Concurrent blob registration and repository-byte accounting are deduplicated.
ATProto uploads now preserve their original bytes through the shared storage
policy instead of using Social's image conversion pipeline. A protocol-native
temporary blob state, reference-aware availability and last-reference cleanup remain
unimplemented. Completing these requires coordinated write, read, migration
and cleanup behavior; see [ATProto compatibility](Atproto-Compatibility.md#blob-lifecycle-boundary).

### Pixelfed remote edit rendering (feature-branch review, 2026-10-28)

The pinned Pixelfed v0.14.4 interop run accepts the video Update and stores its
new caption and `edited_at`, but its API continues to render the original HTML.
The upstream [update pipeline](https://github.com/pixelfed/pixelfed/blob/v0.14.4/app/Jobs/StatusPipeline/StatusRemoteUpdatePipeline.php)
changes `caption` without updating `rendered`, which the
[remote-status transformer](https://github.com/pixelfed/pixelfed/blob/v0.14.4/app/Transformer/Api/StatusStatelessTransformer.php)
prefers over the caption. The real-peer edit assertion remains enforced;
successful delivery or an updated database caption is not counted as successful
user-visible editing. Failure diagnostics include the peer's worker jobs and
stored caption/edit state. A peer fix or verified newer version is still needed.

### Compatibility surfaces must be maintained deliberately

Aloha Social exposes its own Nextcloud API, a broad but partial Mastodon client
API, ActivityPub federation and an optional native AT Protocol PDS/XRPC surface.
The legacy hashtag-interest API remains alongside the ranked For You API for
compatibility, and an administrator can still force that legacy feed. This
creates duplicated behavior and documentation. Keep the surfaces clearly
labelled; any retirement needs usage evidence, a migration path and an API
compatibility decision. See [API.md](API.md),
[Integrations.md](Integrations.md) and [For-You.md](For-You.md).

Remote behavior is also configuration-dependent: ActivityPub delivery and
profile imports rely on Nextcloud background work; Mastodon clients need root
rewrite rules; AT Protocol hosting needs reachable PLC, relay, AppView and
optional Chat services. Unit tests cannot establish those remote services are
available. Keep the operator checks and live-test procedures current rather
than describing a successful local route as proof of federation.

### Schema history is an upgrade contract

The 67 migration classes include paths for existing installations, not only
fresh installs. Do not squash or reorder migrations based solely on the current
schema fixture: an older supported app version may have applied a different
subset. Update the schema fixture and exercise upgrade paths when changing
storage. See [Architecture.md](Architecture.md) and
[tests/Integration/README.md](../tests/Integration/README.md).

### Deprecated helpers still need a caller-by-caller plan

There are 11 `@deprecated` markers today. They include older query-builder
helpers and traits/interfaces retained for compatibility. The count alone does
not show whether removal is safe. Before removing one, search production and
extension callers, replace internal use, and cover the behavior on all supported
databases. Do not treat the marker as a scheduled removal date unless one is
documented.

### Performance evidence is stronger, but not production evidence

A nightly benchmark now seeds 100,000 posts and times timeline/search queries
on MySQL and PostgreSQL. The most recent successful run available during this
review is dated 2026-10-27 and is documented in
[Performance.md](Performance.md). It uses app version 0.26.278, one patch before
this checkout's 0.26.280. The benchmark does not measure remote delivery,
AppView indexing, background queue delay, real user profiles, populated direct
messages or browser rendering. Treat the figures as a repeatable query baseline,
not a production service-level objective.

## Current priorities

1. Keep the API and integration matrix accurate as features evolve; clearly mark
   partial client parity and remote-service dependencies.
2. Continue reducing legacy compatibility code only when real usage and upgrade
   evidence support removal.
3. Preserve migration and repository invariants across SQLite, MySQL and
   PostgreSQL, including upgrades from supported releases.
4. Extend performance measurements to meaningful direct-message data and
   representative profile/feed shapes, and retain run artifacts for comparison.
5. Use live interoperability checks for DNS, TLS, proxying, background jobs and
   public indexing; unit tests cannot validate those deployment conditions.

## What this document does not claim

This is a source-based maintenance review, not a full dead-code proof, security
audit, benchmark of a production instance or proof that all CI checks pass. The
[API reference](API.md) is mechanically checked against registered routes;
other behavior claims still need review when the implementation changes.
