# Technical debt and legacy code

What in this app is old, borrowed or load-bearing in a way nobody would choose
today, what is simply dead, and what it would cost to change. Written for
whoever has to decide where refactoring effort goes.

**Verified against:** app version 0.17.0, 2026-09-12, after the wave described
in [What has been done](#what-has-been-done). Every number was measured on that
tree rather than carried over — except the migration, repair-step, table,
class, controller, import and `src/` file counts, which were re-measured on
0.19.49 on 2026-09-14 — and
[Reproducing the measurements](#reproducing-the-measurements) gives the command
for each.

**The support floors are whatever `appinfo/info.xml` declares.** Nothing else in
the repository holds them: the PHPUnit, lint and appstore workflows read the PHP
and Nextcloud ranges out of that file, through
`icewind1991/nextcloud-version-matrix` and a direct `grep php min-version`, so
changing two attributes there changes what CI tests against. They now say **PHP
8.3–8.5 and Nextcloud 35–36**, and `composer.json`, `psalm.xml` and the server
API stubs agree with them rather than contradicting them.

Like [Performance.md](Performance.md), this file is **not** enforced by
`tests/DocumentationTest.php` beyond its title: its claims are structural rather
than checkable against a route table, so nothing fails when it goes stale.
Re-measure before quoting it. Query and scalability behaviour lives in
`Performance.md`; the schema is described in [Architecture.md](Architecture.md).

---

## The shape of what is left

The app is 76,325 lines of PHP across 415 files in `lib/`, and 17,779 lines of
JavaScript and Vue across 81 files in `src/`.

| Theme | Severity | Size |
|---|---|---|
| Translation catalogue covers under 40 % of source strings | Low | — |
| PHPUnit 12 | Low | ~3,100 stub migrations |

The two largest items in every previous version of this document are both gone.
The vendored toolkit's query builder no longer extends a private core class, and
the 856-line request model in front of the OCP HTTP client has been deleted
rather than ported. `lib/Tools/` is 25 files and 2,657 lines of helpers and
traits now — a third of what it was, and nothing in it reaches outside `OCP\`.

**Nothing in `lib/` names a class outside `OCP\` any more,** and a unit test
holds it there — not the server's private classes, not Doctrine, not Guzzle.
`\OC::$server`, `OC\SystemConfig`, `OC\DB\Connection`, `OC\DB\SchemaWrapper`,
`OC\DB\QueryBuilder\QueryBuilder`, `OC\User\NoUserException`,
`OC\Core\Command\Base`, every `Doctrine\` class and
`GuzzleHttp\Exception\ClientException` are all gone; the matches that remain
for those names are sentences in docblocks explaining what used to be there.

---

## Age of the code

Lines of `lib/` by the year they were last touched, from `git blame`:

| Year | Lines | Share |
|------|-------|-------|
| 2018 | 7,751 | 10.2% |
| 2019 | 8,506 | 11.1% |
| 2020 | 3,944 | 5.2% |
| 2021 | 10 | 0.0% |
| 2022 | 5,394 | 7.1% |
| 2023 | 3,491 | 4.6% |
| 2024 | 1,018 | 1.3% |
| 2025 | 50 | 0.1% |
| 2026 | 46,161 | 60.5% |

Run it on a complete checkout: `git blame` can only attribute lines it has
history for, so a shallow or out-of-date clone silently understates the recent
share. Measured on a checkout hundreds of commits behind, 2026 came out at
29 % rather than 60 %.

---

## Two API generations in one URL namespace

The app serves two API designs, and they share the `/api/v1/` prefix.

- **Mastodon-compatible** — the controllers built on `MastodonApiController`, `ListController`,
  `FilterController` and others, through `StreamService::getTimeline(ProbeOptions)`.
- **Custom local** — `LocalController` (1,068 lines), through the five
  `@deprecated` `StreamService::getStream*()` methods and the five
  `StreamRequest::getTimeline*_dep()` query methods behind them.

So `/api/v1/stream/home` (local: `since`/`limit` cursor, default page size 5, no
`Link` header, `{status, result}` envelope) sits directly beside
`/api/v1/timelines/home` (Mastodon: `max_id`/`since_id`, page size 20, `Link`
header, bare entity).

**Nineteen of `LocalController`'s 31 routes had no caller anywhere in `src/`**,
including all eight `/api/v1/stream/*` and `/account/{username}/stream`
endpoints — the ones backed by the `_dep` query methods. They were marked
deprecated in [API.md](API.md) for a release and are **removed in 0.19.39**,
with the whole query layer behind them: the nine `StreamService::getStream*()`
methods and the seven `StreamRequest::getTimeline*_dep()` / `getTimelineLiked()`
/ `getTimelineTag()` queries. API.md keeps the table of what replaced them. The
twelve routes the frontend does call stay.

### Routes are attributes now

201 of the 202 routes moved onto the methods they belong to; one stays in
`appinfo/routes.php`, and the file explains why. `/api/v1/accounts/{id}` accepts
slashes, so it also matches two routes that live in other controllers and has to
be offered to the matcher after them — and attribute routes are contributed one
controller at a time in filesystem order, so no arrangement of attributes can
put it last. The array file is loaded after every attribute route, which is the
guarantee that one route needs.

`tests/DocumentationTest.php` reads the attributes by reflection the way the
server does, and treats an empty route table as a failure rather than a pass.

---

## Schema and migrations

`Performance.md` covers query behaviour and the index and schema-shape hazards.
What follows is the debt in the migration set itself.

All 43 schema steps use the frozen prefix `Version1000Date`, so only the date
orders them:

| Era | Steps |
|---|---|
| 2022-11-18 | 1 (creates all 14 original tables) |
| 2026-06-11 | 1 (drops 14 legacy `social_3_*` tables) |
| 2026-09-07 onward | 40 |

There are numbering gaps at `20260911000003`, `000012` and `000015`–`000019`,
and most steps were authored in an eight-day window.

The 2023 block is gone: its three steps repaired instances the 2022 step had
created, and the 2022 step had been edited over the years to produce the
repaired shape directly, so what they still did for a new instance was one
column. Running both paths through the test doubles and diffing the schemas is
what established that.

One squash candidate is left and is deliberately not taken.
`Version1000Date20260908000001` widens a column introduced one day earlier, and
both shipped in 0.16.0 — but an instance on 0.15 running Nextcloud 35 has run
neither, so folding the width into the creating step would leave it with a
column too narrow for its own client secrets. The argument that retired the 2023
steps does not transfer: the Nextcloud floor says which server an instance is
on, not which version of this app.

The drop of fourteen `social_3_*` tables that **no other file in the tree ever
creates** went with the squash: the creating migrations had been deleted long
before, only the drop survived, and it was dead weight on every fresh install.
The legacy naming split it addressed is finished — there is no `social_a2_*`
prefix anywhere, and all 61 current tables are both read and written.

`tests/Migration/schema.json` records what the history produced, and
`SquashedSchemaTest` holds the one step that replaces it to that. That is what
any further change to the schema has to go through.

### Two columns written and never read

`social_stream_dest.id` and `social_stream_tag.id` are autoincrement surrogate
keys. The new tests record why they exist: both tables shipped in 2022 with no
primary key at all, only a unique index over the columns that identify a row.
Neither `id` appears in the column lists in `CoreRequestBuilder` nor in the
select lists of their request builders. These are the two highest-insert-rate
side tables in the app, and every row pays for a sequence and an index write
nothing reads. The keys are still worth having — this is a note on the cost.

---

## Frontend

**Still Options API.** There are `setup()` blocks now, because the composables
need them, but no `<script setup>` and no wholesale move to composition. That is
a style question rather than debt: the components are consistent with each other.

**The JSDoc typedefs are checked.** `jsconfig.json` runs `checkJs` over the
types, services, stores and utilities, and `npm run typecheck` is a script.
The single-file components are checked by `vue-tsc` in the same script, and
both checks allow no errors. There was a baseline of 265 while the components
were brought up to it; it is gone, along with the tooling that compared against
it.

**Five ESLint rules are switched off**, and `eslint.config.mjs` says why next to
each. Sorting imports and named imports (247 reports) detaches
the comments that explain the side-effect imports; the two component-naming
rules (8 reports) would rename `Search.vue`, `Poll.vue` and friends, which
changes what templates say. Neither pair is formatting, which is why they were
not taken with the rest. `vue/no-boolean-default` (10 reports) would turn every
prop that defaults to true into a negation — `link` into `noLink` — at every
call site. And `vue/no-multiple-template-root` is off because this app mounts
several roots and the rule is a Vue 2 leftover. Everything else is on, and the
tree reports no warnings.

---

## Toolchain

| Tool | Pinned | Status |
|---|---|---|
| `nextcloud/ocp` | `dev-stable35` | Matches the declared minimum, so analysis checks this app against the oldest server it claims to support. |
| PHPUnit | `^11.5` (11.5.56) | Current major minus one. See below. |
| Psalm | `^6.17` | Current, running on supported PHP. Its baseline covers six files and seven issues, none of them in `lib/Db`. |
| ESLint | 10.10.0 with `@nextcloud/eslint-config` 9 | Current, flat config, five rules deliberately off, no warnings. |
| Stylelint | `^17.15` | Current. |
| Vitest / jsdom | 5.0 / 30.0 | Current. |
| webpack / vue-router / vite | 5.110 / 5.3 / 8.3 | Current. |
| `node-polyfill-webpack-plugin` | exactly `4.0.0` | **Blocked upstream.** `@nextcloud/webpack-vue-config` 6.3.2 peer-pins it; 4.1.0 needs a release there first. |

**PHPUnit 12 is the one version behind, and the gap is measured.** Under 12 this
suite reports about 3,100 notices — "no expectations were configured for the
mock object, consider a test stub" — which is a suite-wide `createMock` to
`createStub` migration, and it needs roughly 2 GB to run where 11 needs 90 MB.
Nothing in `tests/` blocks it otherwise: providers are static, metadata is
attributes rather than annotations, and the `onConsecutiveCalls()` calls 12
removes are gone. It is a day of mechanical work with no correctness payoff,
which is why it is last.

`terser-webpack-plugin` is a direct devDependency because webpack 5.110 stopped
hoisting it where `@nextcloud/webpack-vue-config` expects to find it. Worth
knowing that the build depends on the shared webpack config's internal layout in
more places than that one.

---

## Remaining odds and ends

**20 `@deprecated` markers in `lib/`, 12 of them on methods with live callers**,
led by `CoreRequestBuilder::leftJoinStreamAction` (17) and
`SocialLimitsQueryBuilder::limitPaginate` (8). The worst of them went with the
request model — `Request::getUrl` had 55 callers and had been deprecated since
toolkit version 19 — and what is left is concentrated in the query layer.
`ACore::verify` still carries its `// TODO - Compare this with checkOrigin() -
and delete this method.` and is down to three callers.

**Two joins that look like one.** `CoreRequestBuilder::leftJoinCacheActors()`
joins case-insensitively on the full ActivityPub URL with a hand-written column
list; `SocialCrossQueryBuilder::leftJoinCacheActor()` joins on the indexed
hashed id, generates its column list from the schema, and pulls the actor's icon
with it. The first is the older, slower path — `LOWER()` on unindexed text, as
`Performance.md` describes. Collapsing them changes which rows match, in
timeline queries, so it wants a database to check against.

**Constructor promotion is at 151 of 206 (73 %).** The remaining 56 are the ones
an automated pass should not touch: constructors that do real work in the body,
that forward a parameter to a parent as well as storing it, or whose property
name differs from the parameter.

---

## Translations

The frontend contains 295 distinct translatable strings. The best-covered
locales (`de`, `de_DE`, `en_GB`) carry 135. The median locale across 98
languages carries 21, and 47 carry fewer than 20. The strings `Quote`,
`Bookmark`, `Scheduled`, `Announcement`, `Follow requests` and `Photos` appear
in **zero** translation files.

The cause is diagnosed in the header of `.github/workflows/l10n.yml`: nothing in
the repository ever extracted the source strings, so nothing was ever pushed for
translation. `.tx/config` is correct as written — `translationfiles/` is what
the extractor produces, not something the repository holds. The workflow now
exists; the catalogue has not caught up, and that is a Transifex round trip
rather than a code change.

The extraction itself has been run and checked: it reads all 1,625 source
strings, and `tests/TranslatableStringsTest.php` now guards the three ways a
string can be written so that it never reaches a translator — a message used
both as a singular and as the singular of a plural, which gettext folds into
one entry `t()` cannot look up; a message that is only a URL or an address,
which ninety-eight locales can only copy back; and a message that is not a
literal, which `xgettext` cannot see at all. Two of those were present and are
fixed.

What remains is the one thing outside the repository: `TRANSIFEX_API_TOKEN` is
not set, so `l10n.yml` extracts and uploads the template as a build artefact
but pushes nothing, and `l10n-pull.yml` does nothing at all. Setting that
secret — or registering this app with the organisation's own translation sync —
is the whole of the remaining work.

---

## What is not debt

Each item here is a search that does not need repeating.

**Classes from outside `OCP\`: none.** No `OC\DB\QueryBuilder\QueryBuilder`, no
`\OC::$server`, no `OC\SystemConfig`, no `Doctrine\DBAL\Query\QueryBuilder`. The
only matches are docblock sentences explaining what was removed.

**Untyped properties: none.** All 35 were typed, including six mutable public
statics in `TArrayTools` that were constants in everything but name.

**Deprecated server APIs: none.** `IConfig`'s app-value methods (deprecated in
29) and user-value methods (deprecated in 33) are gone in favour of `IAppConfig`
and `IUserConfig`; `IConfig` is used only for system values, which are not
deprecated.

**Vue 2 leftovers: none.** `Vue.prototype`, `new Vue(`, `$listeners`,
`$set`/`$delete`, `.sync`, `filters:`, `beforeDestroy`, `functional: true` and
`slot-scope` all return zero hits in `src/`. The only occurrences anywhere are
the regexes in `tests/js/vue3.test.js`, which holds each at zero. Vuex is gone
too.

**PHPDoc route annotations: none.** Access control is 100 % PHP 8 attributes,
with a regression test asserting the old form is not reintroduced.

**Legacy PHP constructs: none.** Zero occurrences of `array()`, `list()`,
`strftime`, `utf8_encode`, `each()`, `create_function`, `ereg*` or
`money_format`. No dynamic property creation.

**Raw SQL string concatenation: effectively none.** Not one
`SELECT`/`INSERT`/`UPDATE`/`DELETE` string literal in `lib/`; all `IDBConnection`
uses go through the query builder. The single exception is the visibility filter
in `SocialCrossQueryBuilder`, which binds its parameters.

**Legacy bootstrap: none.** No `appinfo/app.php`, no `appinfo/application.php`.
`lib/AppInfo/Application.php` is 71 lines of correct `IBootstrap` registration.

**`@nextcloud/vue` v9 usage: current.** All 74 imports use the v9 subpath-export
style; zero deep `dist/` imports, zero removed or renamed components.

**Unlinted code: none.** `npm run lint` covers `src`, `tests/js`, the end-to-end
tests and the root configuration; `npm run stylelint` covers `src` and `css`.
Nothing in `js/` is hand-written any more: the administration page was the last
script there, and it is a webpack entry like every other now.

**Dead frontend code: none.** The import graph over all 100 files in `src/`
resolves, and there are no unused exports.

**Dead model classes: none** (77 checked). **Dead exception classes: none** (48
checked). **Unused controllers: none** (28 checked; `ClientApiController` is the
abstract base of sixteen of them and `SocialPubController` is called by
`ActivityPubController` rather than routed to).

**Repair steps that re-scan on every upgrade: none.** All five carry a version
marker in app config and return early.

### A trap worth knowing before you audit this app

Nextcloud controller methods are **admin-required by default** —
`SecurityMiddleware` enforces it unless the method carries `#[NoAdminRequired]`
or `#[PublicPage]`. The *absence* of an attribute is the guard, not a missing
one. Reading the routes as if the attributes grant access rather than relax it
produces a long list of false "unauthenticated endpoint" findings.

### And one about measuring

Do not measure this app on a working copy without checking it against a fresh
clone first. An earlier pass of this report was taken on a checkout hundreds of
commits behind `origin/master`: it reported ten untested controllers that in
fact have tests, put 45 % of `lib/` in 2023 or earlier when the real figure is
different, and recorded a failing test suite that is green on master. Counting
"dead" methods without treating tests as callers likewise put the figure near
sixty when twenty survive the check.

---

## What has been done

### In [#2127](https://github.com/nextcloud/social/pull/2127)

| Item | Outcome |
|---|---|
| Source maps in the release tarball | Excluded. The tarball was ~22 MB of maps against 6.7 MB of bundles. |
| Three disagreeing packaging lists | One list. `build-package.sh` calls `make appstore`; `.nextcloudignore` deleted. |
| The `elliptic` stub | Replaced with an `overrides` pin on the real `^6.6.1`. The stub claimed a version that has never existed on the registry. |
| Cypress husk | Deleted: 8 files, 1 config, 4 npm scripts, 6 packages, 0 tests. |
| Unused npm dependencies | `ical.js`, `uuid`, `cypress-wait-until`, `@nextcloud/cypress` dropped; `buffer` and `webpack-dev-server` declared. |
| Dead code | 3 files and 20 methods with no caller anywhere, 4 dead config constants, an 80-line commented-out block, 4 obsolete Psalm suppressions. |
| Repair steps re-scanning on every upgrade | Markers added; `RenameDocumentLocalCopy` unregistered and deleted. |
| Missing indexes | `social_actor.user_id` and the four unindexed `trend_*` windows. |
| Status-id collisions | Width 1e6 -> 1e9, `rand()` -> `random_int()`, and a collision is retried instead of silently dropping the post. |
| `IInitialStateService` | Migrated to `IInitialState` in four controllers. |
| `Cron\Queue` | Given the wall-clock budget `QueueController` already had. |
| The `AP` static registry | Resolved lazily instead of by an `AP::init();` at file scope. |
| Unreachable controller methods | Six deleted from `LocalController`. |
| Two unbounded loops | `NotificationService::clear()` and `MigrationService::refollowLocalFollowers()` bounded. |
| The rest of the unbounded work | Closed in [#2132](https://github.com/nextcloud/social/pull/2132), with the N+1 behind `accounts/relationships` and the missing transaction around `StreamRequest::save()`. See [Performance.md](Performance.md), which tracks that category. |
| `tests/stub.phpstub` | `QueryBuilder::SELECT` was the string `'select'`; Doctrine assigns the int `0`, so 28 comparisons were verified against fiction. Fixed and pinned by a test. |
| Psalm 5 -> 6 | Now runs on supported PHP. Found five real type defects and five orphaned access-control attributes. |
| Stylelint 15 -> 17 | Seven deprecated CSS declarations removed. |
| Constructor promotion | 107 -> 148 of 204, and 264 redundant `@param` tags removed with it. |
| `HtmlSanitizer`, `RemoteAddress` | Moved from `lib/Tools/` to `lib/Security/`. |

### Since then

- **Delivery sends the stored bytes** (0.19.39). `ActivityService::bodyFromQueue()`
  returned `json_encode(json_decode(...))`; it returns the queued string. What
  this instance writes is encoded once at queue time, so nothing it sends
  changes; what `ForwardService` queues verbatim now arrives verbatim, which is
  the only way a third party's Linked Data signature survives.
- **The superseded Custom Local API is gone** (0.19.39): nineteen routes, the
  nine `getStream*()` methods and the seven `_dep` queries, and their tests.
- **A browser-test layer** (0.19.58): `tests/e2e/` and `.github/workflows/e2e.yml`
  — Playwright against a throwaway Nextcloud on every pull request. 1,500 unit
  tests and an integration suite could not see a page that stays empty because
  a bundle did not load; the smoke suite can.

| Item | Outcome |
|---|---|
| ESLint 8 (end of life) | [#2129](https://github.com/nextcloud/social/pull/2129): ESLint 10, `@nextcloud/eslint-config` 9, flat config. |
| Nine conflicting dependabot bumps | [#2130](https://github.com/nextcloud/social/pull/2130): vue 3.5.42, webpack 5.110, jsdom 30, vue-router 5 with vite 8 behind it, dialogs 7.5. |

### In this wave

| Item | Outcome |
|---|---|
| **The five post fields with no column** | `tag`, `language`, `updated`, `quote` and `quoteAuthorization` are columns on `social_stream`, written where a row is written and read with a per-field fallback to the stored JSON, with a repair step backfilling existing rows behind a version marker. `language` is indexed, which is what a language filter needs. |
| **The private core class under the whole data layer** | `ExtendedQueryBuilder` holds a builder from `IDBConnection::getQueryBuilder()` and delegates all 60 `IQueryBuilder` methods instead of extending `OC\DB\QueryBuilder\QueryBuilder`. The `\OC::$server` reach that fed its private parent is gone, and so are the four `Doctrine\DBAL\Query\QueryBuilder` imports that existed only to read four constants. Nextcloud 35 had already broken the old arrangement by adding `forUpdate()` to the interface. |
| **The support floors** | PHP 8.1 -> 8.3, Nextcloud 28 -> 35, with `composer.json`'s platform pin and constraint following. |
| `nextcloud/ocp` two years stale | `dev-master` at a 2024-10-23 commit -> `dev-stable35`. |
| `#[\Override]` unavailable | 343 methods carry it; the psalm suppression that named the 8.1 floor is gone. |
| `IConfig` deprecated methods | `IAppConfig` and `IUserConfig` in five files. |
| PHPUnit 9 (end of life) | 11.5, via 10. All 112 data providers static, 127 annotations to attributes, 11 `withConsecutive()` sites rewritten, config migrated. |
| Test doubles drifting from the interfaces they double | `FakeConnection` completed; ten hand-rolled anonymous table classes replaced by one `FakeTable`; the test container serves an anonymous session because `Response` resolves one on every render from 35 onward. |
| Vuex 4, no Pinia | Five Pinia stores, `mapStores` at the call sites, `vuex` removed. |
| Three mixins, four spellings of one import | Three composables in `src/composables/`. |
| Dead pre-Nextcloud-25 profile branch | Deleted. |
| `OC.Notification.showTemporary` | `@nextcloud/dialogs`, imported lazily so the Vue-free admin bundle stays 28 KiB rather than 761 KiB. |
| Nineteen deferred ESLint rules | Sixteen adopted, ~1,100 reports fixed. Three stay off with the reason next to each. |
| Unlinted code | The hand-written admin script and the root configuration are linted; `css/` is stylelinted. |
| 35 untyped properties | Zero. Six mutable public statics became constants. |
| The 2022–2023 migrations, untested | `CoreSchemaTest`, which asks the installed schema rather than one step. |
| Private core classes elsewhere in lib/ | `OC\DB\Connection` (a dead import), `OC\DB\SchemaWrapper` (two `occ social:reset` paths, now `IDBConnection::tableExists()`/`dropTable()`), `Doctrine\DBAL\Schema\SchemaException` (the OCP one exists) and `OC\User\NoUserException` (thrown by this app at this app, now its own). Six analysis suppressions went with them. |
| Every occ command on a private core class | All twenty-one extend Symfony's `Command` through the app's own `SocialCommand`, whose output is byte-identical to the server's `Base` across 109 diffed cases and the rendered `--help` of every command. |
| Routes as an array | 201 of 202 moved onto the methods; `DocumentationTest` reads the attributes by reflection and fails on an empty table. |
| The 2023 migration repairs | Squashed into the step they repair, after measuring that the whole difference they still made was one column. |
| 73 migration files | Seven. The sixty-seven that only shape tables are one generated step, `Version1000Date20221118000002`; the six that move rows are untouched. It was generated by replaying the history against a recording schema, `tests/Migration/schema.json` is that replay's output, and `SquashedSchemaTest` fails if the two ever part. Every step in it is guarded, so it brings up an empty database, completes a half-upgraded one and does nothing on a current one — there is no floor on the version an instance may upgrade from. |
| `CoreRequestBuilder` reimplementing the builder's helpers | 36 methods gone — eleven with no caller, twenty-five identical to the builder's — and 84 call sites now say `$qb->limitToId($id)`. The file lost 450 lines. |
| Typedefs nothing checked | `jsconfig.json` plus `npm run typecheck`, which found a placeholder relationship missing two fields the server always sends, and a pagination cursor typed as a number while ids are strings. |
| The hand-rolled HTTP request model | Deleted. Requests go to `IClient` directly, and the signing string was pinned by a test run against both trees before and after — byte for byte identical. |
| Real defects found on the way | An instance's `local` flag was bound as a string because a parameter type was passed to the wrong function. `Profile.vue`'s "User not found" panel could never appear because one query was being asked twice under two names. And GET `/@{username}/outbox` was shadowed by its own POST, because two route attributes on one method registered under one name — the endpoint a remote server fetches an outbox from was served by the registration the documentation calls not implemented. Pinning the federation wire found five more: a delivery that signed one path and sent to another, a non-default port silently dropped when fetching a remote object, a `?tag[]=` in an id that was a TypeError, a report forward whose digest covered different bytes from the ones sent, and a webfinger response with no subject writing a garbage account name. |

---

## Rough order of value for what is left

1. Collapse the two cache-actor joins, with a database to check against.
2. Finish the l10n round trip — a Transifex round trip, not a code change.
3. PHPUnit 12, which means `createMock` -> `createStub` across the suite.
4. Web Push and a streaming API, so mobile clients stop polling.

---

## Reproducing the measurements

```bash
# The support floors, and the places that should agree with them
grep -oE '(php|nextcloud) min-version="[^"]+" max-version="[^"]+"' appinfo/info.xml
grep -nE '"php": |platform' composer.json | head -3
grep -rn 'php min-version\|nextcloud-version-matrix' .github/workflows/*.yml | wc -l

# Sizes
find lib -name '*.php' | wc -l && find lib -name '*.php' -exec cat {} + | wc -l
find src -type f \( -name '*.js' -o -name '*.vue' \) | wc -l

# Classes from outside OCP\ (expect only docblock prose)
grep -rn 'OC\\DB\\QueryBuilder\\QueryBuilder\|OC::\$server\|OC\\SystemConfig\|Doctrine\\DBAL\\Query' lib --include='*.php'

# Untyped properties (expect 0)
grep -rnE '^\s*(var|private|protected|public|static|readonly)(\s+(static|readonly))*\s+\$\w+' lib --include='*.php' | wc -l

# Deprecated server config APIs (expect 0)
grep -rn 'config->getAppValue\|config->setAppValue\|config->getUserValue\|config->setUserValue' lib --include='*.php' | wc -l

# @deprecated methods that still have callers
grep -rn '@deprecated' lib --include='*.php' | wc -l
grep -rn 'leftJoinStreamAction(' lib --include='*.php' | grep -v 'function ' | wc -l

# Constructor promotion adoption
python3 - <<'PY'
import re, glob
p = n = 0
for f in glob.glob('lib/**/*.php', recursive=True):
    for m in re.finditer(r'function\s+__construct\s*\((.*?)\)\s*[:{]', open(f).read(), re.S):
        a = m.group(1)
        if re.search(r'\b(private|protected|public|readonly)\s', a): p += 1
        elif a.strip(): n += 1
print('promoted', p, 'of', p + n)
PY

# Custom Local API routes with no caller in src/
python3 - <<'PY'
import re, glob
routes = [m.groups() for l in open('appinfo/routes.php')
          if (m := re.search(r"'name' => 'Local#(\w+)'.*?'url' => '([^']+)'", l))]
src = ''.join(open(p, errors='replace').read() for p in glob.glob('src/**/*', recursive=True)
              if p.endswith(('.js', '.vue')))
for name, url in routes:
    if url.split('{')[0].rstrip('/').lstrip('/') not in src:
        print('uncalled:', url)
PY

# Data providers that are not static (expect 0). Matching on the name alone
# also finds methods like getDatabaseProvider(), so ask which methods the
# #[DataProvider] attributes actually name.
python3 - <<'PY'
import re, glob
named = set()
for f in glob.glob('tests/**/*.php', recursive=True):
    named |= set(re.findall(r"#\[DataProvider\('(\w+)'\)\]", open(f).read()))
for f in glob.glob('tests/**/*.php', recursive=True):
    for m in re.finditer(r'public (static )?function (\w+)\(', open(f).read()):
        if m.group(2) in named and not m.group(1):
            print('not static:', f, m.group(2))
PY

# ESLint rules this codebase does not adopt
grep -c "': 'off'" eslint.config.mjs

# Translation coverage
grep -rhoE "\b[tn]\('social',\s*'[^']*'" src | sort -u | wc -l
python3 -c "import json,glob; print(sorted((len(json.load(open(f))['translations']), f) for f in glob.glob('l10n/*.json'))[-3:])"

# Suites
composer test:unit && composer psalm && composer lint \
  && vendor/bin/php-cs-fixer fix --dry-run \
  && npm run lint && npm run stylelint && npm test && npm run build
```
