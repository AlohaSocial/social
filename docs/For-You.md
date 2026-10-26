# For you — feature specification

Status: living implementation record · Updated: 2026-10-24 · App: Aloha Social

Sections 1–7 preserve the initial hashtag-feed design and contain historical details. They are not the current product specification: readers cannot choose or configure recommendation topics, passive time on a post is not an interest signal, and the current automatically ranked timeline and its diversity rules are described in the implementation sections below. The API reference is the `For you` section of [API.md](API.md). User-facing copy says **For you** (German *Für dich*); the code, routes and tables retain the historical `interests` name for compatibility.

## 1. Summary

Aloha Social learns which hashtags a user cares about from how they read. It looks at
how long they linger on a post, what they skip, and what they like, boost,
reply to, bookmark, open or play. From that it keeps a private, ranked list of
**interest hashtags**. A new **For you** feed shows posts carrying those
hashtags, ranked by relevance blended with recency. In Settings the user can
see the list and add, remove, reorder and pin its entries.

## 2. Goals and non-goals

Goals
- A feed that surfaces interesting posts from everything the server knows, not
  only from followed accounts.
- Every post in the feed can say why it is there (a matching hashtag).
- The user can see and control the model completely: every learned interest is
  visible, editable and resettable.
- Learning never leaves the server and is never visible to anyone else.

Non-goals (v1)
- Topics inferred from untagged text. What is learned is hashtags; the only
  untagged posts in the feed are the popular ones that fill a short photo or
  video ranking (7.5), and they say so.
- Fetching posts from peer servers to fill the feed. v1 uses the local cache.
- Learning from accounts or links. Only hashtags are learned.
- Hiding posts already seen in other feeds.

## 3. Decisions from the interview

| Topic | Decision |
|---|---|
| Where signals are collected | Home, Local, Global, hashtag pages, Explore/Discover, post detail/thread |
| Signals | Explicit actions, negative actions, media interaction and measured Shorts playback; passive screen dwell is neutral |
| Storage | Server-side, private per user, aggregated scores only (no raw event log) |
| Consent | Opt-out per user; the default (on/off) is an admin setting, on out of the box |
| Passive dwell | No score; the time a post stays on screen is not treated as intent |
| Change over time | Exponential decay (half-life, default 30 days) |
| Listed interest | Score above a threshold, capped at top N (default 30); manual tags always listed |
| Multi-tag posts | A post's signal is split evenly across its hashtags |
| Candidate pool | Every post the viewer may see in the local cache |
| Ordering | Relevance × recency |
| Untagged posts | Excluded |
| Feed extras | "Why am I seeing this" chip, diversity/exploration, inline "less like this" |
| Settings display | A tag cloud: size and emphasis show priority, flowing in rank order |
| Reprioritize | Drag within the cloud, or Higher/Lower in a per-tag popover; pin |
| Remove | Resets the score to zero; the tag can be learned again |
| Followed hashtags | Kept as a separate list; followed tags seed interests |
| Settings controls | Pause learning, reset all, include in data export |
| Cold start | Followed tags, featured tags and local trending, topped up with what is popular here, with a "still learning" hint |
| Placement | Timeline switcher, second after My Feed; the web interface only — no Mastodon API for it |
| Safety | Mutes, blocks, domain blocks and filters respected; language filter |
| Admin | Enable/disable the feature; default for users; tuning parameters |

## 4. User experience

### 4.1 Feed
- **For you** is the second option in the timeline switcher: My Feed /
  For you / Local / Global ([src/views/Timeline.vue](../src/views/Timeline.vue)).
  Route `/timeline/interests`, which the existing `/timeline/{path}` server
  route already serves. The path keeps the internal name.
- **Photos** and **Videos** offer **For you** as a fourth scope beside My Feed,
  Local and Global (`?scope=interests`), and so does the **Shorts** stack,
  which opens on it once reading has taught the feed something (7.5).
- Each post shows a small chip: "Because you follow #photography" (for a
  followed tag) or "Because you're interested in #photography" (learned or
  manual). With two or more matches: "#photography, #analog +1". Tapping the
  chip opens the hashtag timeline.
- The post menu gains **Less like this** (see 5.2). In this feed, using it
  also hides the post at once and shows a toast with an Undo.
- While learning is thin (see 6.5), a dismissible banner at the top says:
  "Still learning what you like. Showing posts from hashtags you follow and
  what's trending here. [Manage interests]".
- Empty state (no interests, nothing trending, and no public post on the
  instance to fill with): explains how the feed learns
  and links to Settings → Interests.

### 4.2 First-use notice
Tracking is on by default, so users must be told about it. The first time the
web UI records a signal for a user, it shows a one-time notice once per
account. The acknowledgement is stored server-side:

> Aloha Social now learns which hashtags interest you from how you read, to build
> your For you feed. This stays on this server and is only visible to
> you. [Manage] [Turn off] [Got it]

### 4.3 Settings → Interests
A new section in [src/views/Settings.vue](../src/views/Settings.vue) with id
`interests`, placed after the followed-hashtags section.

- **Learn from my browsing** (toggle, default on). Off = opt-out: no signals
  are collected, the feed tab is hidden, the list is kept but frozen.
- **Pause learning** (toggle). The feed keeps working on the current list, but
  no new signals are recorded and decay stops. It is for "I'm browsing
  something unusual today". It is disabled while learning is off.
- **Interest cloud**: the interests are shown as a tag cloud, the main visual
  element of the section (see 4.4).
- **Add interest**: a hashtag input with autocomplete from known hashtags, at
  the end of the cloud (an inline "+ Add" pill that turns into the input). The
  tag is added as a manual interest. It floats with the listing threshold as
  its score, so it usually lands low in the cloud; the cloud brings it into
  view so the reader sees where it went.
- **Languages for For you**: the existing account language preference is reused
  by both the hashtag feed and For You. An empty list means all languages;
  posts whose language is unknown remain eligible.
- **Reset all interests**: clears learned scores, manual entries, pins and the
  legacy profile marker. Needs a confirmation step built into the page (the viewer has
  no `confirm()`).

Removing a learned tag resets its score to 0 (it may be learned again). The
row disappears and a toast offers Undo for 10 s. Removing a followed tag's
interest only removes the interest; the follow stays. Its seed (6.4) keeps it
listed while it is followed, so the settings page says "Unfollow #x to remove
it from interests" for followed tags.

### 4.4 The interest cloud
The cloud shows priority at a glance and lets the user change it in place.

**Layout.** Tags flow left to right and wrap, in effective rank order (6.5),
so the reading order is the priority order and the top interest comes first.
The layout is deliberately not a random scatter: an ordered flow keeps drag
targets predictable, works with a keyboard and a screen reader, and reflows
cleanly at phone width.

**Encoding.**
- *Size* follows the rank weight `w(rank)` (6.6): six steps from 2.0 rem for
  the top entries down to 0.85 rem for the tail, so the size change between
  neighbouring ranks is gradual rather than jumpy.
- *Weight and colour intensity* follow the same step: the top tags are bold
  on the primary colour, the tail is regular on a muted tint. Both themes are
  covered with Nextcloud colour variables, never fixed colours.
- *Source* is a small leading glyph rather than a badge, to keep the cloud
  calm: a pin for pinned, a hand/pencil for added by you, a bell for followed,
  no glyph for learned.
- *Trend* (learned tags only): a subtle up/down arrow when the score moved by
  more than 20 % since the week began. `score_week` holds the score as the
  current week of activity began: it is set when a signal arrives in a week
  after the previous one, so no job is needed.
- Tags with a negative score are not shown in the cloud.
- Hover/focus shows a tooltip: "Rank 4 · learned · score 12.3".

**Changing priority.**
- *Drag* a tag onto a new position in the flow. The other tags make room with
  a short animation, and the dropped tag resizes to its new rank. Dropping an
  unpinned learned tag pins it (6.5), shown by the pin glyph appearing.
- *Tag popover*: clicking a tag opens a small popover with
  - **Higher priority** / **Lower priority** (moves one rank, pins it),
  - **Move to top**,
  - **Pin / Unpin**,
  - **Open #tag** (the hashtag timeline),
  - **Remove**.
  This is how touch and keyboard users reprioritize without dragging.
- Keyboard: the cloud is one roving-tabindex group. Arrow keys move focus,
  Enter opens the popover, and Alt+←/→ moves the focused tag one rank.
  Every move is announced through `aria-live` ("#photography, now priority
  3 of 24").

**Removing.** A small × appears on hover/focus (always visible on touch) and
in the popover. The tag fades out, the cloud reflows, and a toast offers Undo
for 10 s.

**States.** Empty: a faint placeholder cloud with the text "Your interests
appear here as you read" and the Add pill. Thin learning (6.5): the cloud
shows what exists plus a "Still learning" note. The temporary trending seeds
are not shown. Learning off: the cloud is shown dimmed, still editable, with
a note that it is frozen.

**Size cap.** The cloud shows the listed entries (at most `N`, default 30).
Below the cloud, a "Show more candidates" disclosure lists learned tags that
are just under the threshold as small pills. Tapping one adds it as a manual
interest, which is a quick way to promote a tag.

**Motion.** Reordering and resizing animate with FLIP transitions of about
200 ms, which are turned off under `prefers-reduced-motion`.

Implementation: `src/components/InterestCloud.vue` (cloud + popover) inside
`InterestsSettings.vue`. It uses the existing `NcPopover`/`NcActions` and no new
drag library unless the one already in the bundle is insufficient. Check this
against the dependency list before adding one.

## 5. Signal collection

### 5.1 Exposure and skip (web UI only)
Tracked in `TimelineList.vue`, which already runs an `IntersectionObserver`
([TimelineList.vue:931](../src/components/TimelineList.vue#L931)), plus
`TimelineSinglePost.vue` for the detail view. Collection lives in one
`src/services/interestTracker.js` module so every view uses the same rules.

- A post is **in view** while ≥ 50 % of it is visible, `document.visibilityState`
  is `visible` and the window has focus.
- Visible time pauses after 20 s without scroll, pointer or key input and
  resumes on input. It is used only to qualify exposure and skip events; a
  timeline post's passive reading time is not sent to the ranker.
- A post collapsed behind a content warning counts only after it is expanded.
- A post counts as a **skip** when it passed through the viewport in under
  600 ms while the user was scrolling.
- Nothing is recorded for the user's own posts, for posts without hashtags, or
  for posts shown only as a filter warning.
- The tracker buffers events and flushes them every 30 s, on
  `visibilitychange → hidden` and on `pagehide` (via `navigator.sendBeacon`).
  At most 100 events per request.

Events sent to the server (the client never sends hashtags; the server reads
them from the stored post, so a client cannot inject tags):

```json
{ "status_id": "113…", "kind": "impression", "visible_ms": 900, "session_id": "…", "context": "home" }
{ "status_id": "113…", "kind": "skip", "context": "federated" }
{ "status_id": "113…", "kind": "media", "context": "tag" }
```

`kind` ∈ `impression | skip | open | media | link`. `context` ∈ `home | local |
federated | tag | explore | detail | interests | photos | videos | shorts`
(`reels`, which older cached bundles send for the Shorts stack, is accepted
as `shorts`).

In the Photos and Videos grids every tile is on screen at once, so time in
view says nothing about any one of them: there only opening a tile counts,
as `media`. The Shorts stack (`src/services/shortSignals.js`) measures the
player instead of the screen, under `shorts`: a slide left within 2 s that had
not played through is a `skip`; the separately consented recommendation
profile receives an actual playback fraction. The legacy hashtag profile only
uses Shorts `dwell` when the video has a known duration and treats it as watch
time (6.1), never as ordinary reading time.

### 5.2 Explicit and negative actions (server-side)
These are recorded on the server where the action happens, so they also count
when the action comes from a Mastodon app:

| Action | Where it is recorded | Signal |
|---|---|---|
| Favourite | `LikeService` | +2 |
| Boost | `BoostService` | +3 |
| Reply | status creation with `inReplyTo` | +3 |
| Bookmark | bookmark endpoint | +3 |
| Open detail/thread | web `open` event | +1.5 |
| Expand media / play video / open link | web `media`, `link` events | +1 |
| Passive screen dwell / skip | web `dwell`, `skip` events | 0 / −0.2; measured Shorts playback remains separate |
| Less like this | new endpoint | −3 |

Undoing an action (unfavourite, unboost, unbookmark) does not subtract. It is
not worth the bookkeeping, and decay covers it.

Dedupe: each `(user, post, kind)` counts at most once per 24 h, so rereading
a post does not inflate its tags. This uses a short-lived cache key
(`ICache`, TTL 24 h) instead of a table.

Nothing is recorded while learning is off or paused, or while the feature is
disabled by the admin.

## 6. Scoring

All scoring lives in one pure class, `InterestScorer`, with no database access,
so it can be unit-tested exhaustively.

### 6.1 Passive dwell and measured playback
Time a post remains on screen has no positive or negative score. The reader may
be reading slowly, interrupted, or simply have not scrolled past it yet. A
quick skip remains a weak negative signal; explicit actions such as opening,
saving, liking or boosting carry the positive intent.

A `dwell` from the Shorts stack (`context` `shorts`) on a video with a known
duration is measured player playback, not screen time, and is judged by the
fraction watched. When one post has several video slides, the client combines
their played and total durations into one duration-weighted post signal. The
legacy signal sender receives the summed playback milliseconds as `dwell`;
V4 receives the combined `video_watch_ratio`. Shorts uses the sum of all video
attachment durations as the expected duration, so both channels evaluate the
same post-level playback fraction. The ordinary video player still uses its
first playable attachment.

```
expected_ms = min(30 000, duration_ms)
share       = dwell_ms / expected_ms
```

`share ≥ 0.9` → +1, `0.5 ≤ share < 0.9` → +0.3, less → 0. Playback never
changes a reading-pace baseline. A video without a known duration and ordinary
timeline dwell are neutral.

### 6.2 Distribution across hashtags
A post with `n` hashtags (from `social_stream_tag`, normalised with
`FollowedTagsRequest::normalise`) gives each tag `signal / n`. Posts with more
than 15 hashtags produce no signal at all, because they are tag spam.

### 6.3 Decay
Scores decay exponentially with half-life `H` (admin, default 30 days). Decay
is applied lazily: a row stores `score` and `scored_at`, and

```
score_now = score × 2^(−(now − scored_at) / H)
```

is computed on read and when a new signal is folded in (the result is written
back with `scored_at = now`). No background job is needed. While learning is
paused, `scored_at` is shifted forward on resume by the paused duration, so a
pause does not decay the list.

Scores are clamped to [−10, 50]. Negative scores are kept: they push a tag's
posts down in the feed and stop weak positive signals from listing it again
right away. Pinned and manual entries do not decay.

### 6.4 Followed hashtags as seeds
While a tag is followed, its effective score is `max(score, T)`, where `T` is
the listing threshold. It is always listed, but real reading still moves it
above other entries. Unfollowing removes the floor; the learned score stays.

### 6.5 The listed interests
A **pinned** tag holds an absolute rank: the rank it was moved or pinned to.
Every other listed tag floats and fills the free ranks by score:

- manual and followed tags, with at least `T` (admin, default 3.0) as their
  score;
- learned tags whose score is at least `T`;

up to `N` (admin, default 30) listed tags. A pin is the reader's word and is
not counted against the cap. Two pins asking for one rank land next to each
other; a pin beyond the end of the list lands at its end.

Moving a tag to rank *p* (dragging it there, *Higher/Lower priority* or *Move
to top*) pins it at *p* and moves every pin at *p* or below down one, so it
lands exactly where it was dropped. Unpinning lets it float again. Learned tags
above the threshold that did not fit under the cap are the first candidates.

Learning is **thin** while fewer than 3 entries are listed. The feed then adds
the reader's featured tags and the top 10 local trending tags
(`HashtagService` trends) as temporary seeds with weight 0.5. These do not
appear in Settings.

### 6.6 Feed weight per tag
Weight follows rank rather than raw score, so dragging has a predictable
effect: `w(rank) = 1 / (1 + 0.15 × rank)` (rank 0 = top), which gives 1.0 for
the top entry, about 0.5 at rank 7, and 0.18 at rank 30. Tags with a negative
effective score get weight `−0.5`.

## 7. Feed ranking

### 7.1 Candidates
Posts the viewer is allowed to see (the same visibility limits as the public
and home timelines), created within the last `W` days (admin, default 7),
carrying at least one tag from the effective list. The query joins
`social_stream_tag` on `hashtag IN (…)` (index `social_st_ht` exists), and at
most 1 000 candidates are fetched, newest first.

Excluded: the user's own posts; posts from blocked or muted accounts and
blocked domains; posts matched by a filter with the *hide* action (a *warn*
filter shows the usual warning); posts marked "less like this"; posts not in
the chosen languages (posts without a language are kept); replies to posts the
viewer cannot see.

### 7.2 Score

```
relevance = Σ w(tag) over matched tags, at most 3 tags counted
recency   = 2^(−age_hours / 24)
rank      = relevance × recency
```

Candidates with `relevance ≤ 0` are dropped.

### 7.3 Diversity and exploration
Applied when a page is assembled:
- at most 2 posts per author per 20;
- at most 40 % of a page from the same top-matched tag;
- about 1 in 10 slots is an **exploration** post: a post with a tag that
  frequently co-occurs with the user's top 5 interests but is not listed
  itself. Its chip says "Related to #photography". Exploration is left out
  while learning is thin.

### 7.4 Pagination
Ranking is not chronological, so a page is cut from a **ranked snapshot** of
post ids, kept per reader in the distributed cache for an hour; every read
extends it. With `max_id` the page continues after that id's position in the
snapshot, so clients that page with the last post's id work unchanged;
`offset` works too. A request with no cursor builds a new snapshot. When no
snapshot is kept — it expired, or the instance has no memory cache — the
ranking is made again and the cursor looked up in it, and a cursor that is no
longer in it ends the feed. The `Link` header carries `next` only.

### 7.5 Photos, videos and Shorts
The same ranking comes narrowed to one kind: `media=photos` (a post with a
picture: `limitToMediaType('image')`), `media=videos` (a video post,
PeerTube's included: `limitToVideo()`), or Mastodon's `only_media=true` for
both (`limitToMedia()`) — the Photos and Videos timelines' own filters on the
indexed `media_kind` column. Anything else is ignored, as the other media
parameters ignore an unknown kind.

- Each kind is a snapshot of its own (`feed/{media}/{viewer}`), so paging one
  never lands in another.
- Pictures and videos carry fewer hashtags and are rarer, so a narrowed
  ranking looks back twice the window; hides are kept that long too.
- **Popular fill.** A newcomer has no reading yet, and few pictures carry the
  tags someone reads. Where a ranking — the plain feed as much as a narrowed
  one — holds fewer than 40 posts, the rest is what is trending in the same
  kind (`TrendService`, which feeds Explore; on a young instance the media
  rankings are topped up with the newest media and the plain feed with the
  newest public posts), less the reader's own, hidden and already ranked
  posts. Those carry `reason` `popular` and no tags, and the chip says
  *Popular right now*, links nowhere and names no hashtag. The whole feed is
  never filled this way.
- **Watching.** For a `shorts` dwell the expected time is the video's running
  time, capped at the 30 s one look can be worth: 90 % of it or more is +1,
  half +0.3, less nothing, and the reading pace is left alone. A video whose
  running time is unknown is weighed as a post.
- **Shorts default.** The page is told whether a reading pace has been
  written (`profile` in the page state); once it has, Shorts opens on For you.
- Nothing in the data says "short": a kept short is every video. A length or
  orientation filter would need those stored.
- **24-hour shorts are not ranked.** The Shorts stack puts the followed
  people's 24-hour shorts ahead of the ranked videos, in their own order (per
  person, unseen first). They live in their own table rather than the stream,
  so no For you ranking ever holds one.

## 8. API

All under the app's API routing, all the viewer's own, all a 404 while the
administrator has the feature off:

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/interests` | settings, automatically learned interests, candidates, `thin`, `cap` |
| POST | `/api/v1/interests/reset` | forget the automatic profile, hidden posts and reading pace |
| PUT | `/api/v1/interests/settings` | learning, paused, languages, notice acknowledged |
| POST | `/api/v1/interests/signals` | web events (5.1); 204; 30 a minute |
| POST / DELETE | `/api/v1/interests/less/{nid}` | less like this / undo |
| GET | `/api/v1/timelines/interests` | the feed; `limit`, `max_id`, `offset`, `media`, `only_media` |
| POST | `/admin/interests` | the admin card (administrators only) |

Every mutation answers the full state, so the page never works out ranks
itself. "Less like this" lives under `/api/v1/interests/` rather than beside
the status actions, because `POST /api/v1/statuses/{nid}/{act}` would match
it. The feed is a literal route of its own rather than a name for
`TimelineApiController::timelines()`: the router matches it exactly before it tries
the slashed `/api/v1/timelines/{timeline}/`, so which controller is read first
does not decide it.

Mastodon apps: there is no standard endpoint for this, and the feed is not
offered to them — it belongs to this app's own web interface. A pseudo-list
was built and taken out again: in a list the feed looked like something it is
not, and apps offered edits that could only fail. What a reader does in an app
still teaches their interests, through the explicit actions in 5.2; apps send
no dwell.

## 9. Data model

Two new tables, in a migration step of their own
(`Version1000Date20260925000020`) rather than in the squash: the squash is
recorded as run on every existing instance, so a table added to it would reach
fresh installs only.

`social_interest`

| Column | Type | Notes |
|---|---|---|
| id | bigint, autoincrement | |
| actor_id_prim | string(32) | the reader, as every other table keys it |
| hashtag | string(127) | normalised |
| score | float | clamped [−10, 50] |
| scored_at | bigint | unix time, for lazy decay |
| manual | smallint | legacy field; reader-selected interests are ignored |
| position | int, nullable | legacy pin; no longer affects ranking |
| score_week | float, nullable | the score as the current week began, for the trend arrow |

Unique index `(actor_id_prim, hashtag)`. Every read is one reader's whole set,
which stays small: past 500 rows the faintest learned ones are forgotten.

`social_interest_hide` — posts marked "less like this": `actor_id_prim`,
`stream_nid`, `creation`, unique `(actor_id_prim, stream_nid)`. A daily job
(`Cron\InterestHides`) forgets rows older than `W` days, which can no longer
keep anything out of the feed.

Per-user preferences (user config, app `social`): `interests_learning`
(empty until the reader chooses, then `1`/`0`), `interests_paused_at`,
`interests_languages` (JSON), `interests_baseline` (float),
`interests_notice_ack`.

Folding a signal into a score is an update, then an insert that skips a
conflicting row, then the update again — never an insert that catches a unique
violation, which aborts the whole transaction on PostgreSQL.

## 10. Administration

In `AdminSettings` (Aloha Social admin page):
- **Enable For you** (default on). Off: the switcher tab and settings
  section are hidden, all `/interests` endpoints return
  404, and nothing is collected. Existing data is kept, so
  switching the feature back on restores everyone's interests.
- **Learning default for users** (on / off, default on). It applies to every
  user who has not changed the *Learn from my browsing* toggle themselves; a
  user's own choice always wins. It lets operators who read the GDPR as
  requiring consent for profiling make learning opt-in. With the default off,
  the first-use notice (4.2) becomes an invitation ("Turn on" / "Not now")
  instead of an announcement.
- **Tuning**: half-life `H` (days, 7–180, default 30); listing threshold `T`
  (default 3.0); list cap `N` (5–100, default 30); candidate window `W` (1–30
  days, default 7). Validated server-side and exposed through `ConfigService`.

## 11. Privacy and data lifecycle

- Scores are private: never federated, never in the public profile, never in
  any API response for another user, and not visible to admins in the UI.
- No raw event log: signals are folded into scores and discarded. Dedupe keys
  expire after 24 h.
- **Data export** (`lib/UserMigration/SocialMigrator.php`): interests
  (hashtag, score, scored_at, manual, position) and the preferences in
  §9 are exported and imported. Imported scores keep their `scored_at`, so
  decay continues correctly. `social_interest_hide` is not exported.
- **Account deletion** (`ActorCascadeService`): both tables are purged for the
  actor.
- **Reset all** deletes the user's rows in both tables and the baseline.
- Opting out keeps the data (so opting back in restores the list), and the
  settings section offers **Reset all** next to the toggle.

## 12. Performance

- The signal endpoint does at most one lookup of post hashtags for the whole
  batch (`stream_id IN (…)` on `social_stream_tag`) and one upsert per
  affected tag. Target: < 30 ms for 100 events.
- The feed query is bounded by the top-N tag list, the `W`-day window and a
  1 000-row cap. Ranking happens in PHP on that set. The snapshot cache means
  one ranking per user per 15 minutes of scrolling. Target: first page < 150 ms
  at 1M users / 10M posts (the same scale as the #2210 perf work). This has to
  be measured with `log_query` on devel before merge.
- The tracker adds no request per post: batched, beacon on unload.

## 13. Accessibility and i18n
- Cloud reordering has keyboard and touch equivalents (4.4: popover
  Higher/Lower/Move to top, Alt+arrow keys, `aria-live` announcements). Rank
  is never conveyed by size alone: it is in each tag's accessible name
  ("#photography, priority 3 of 24, pinned").
- The chip is a real link with an accessible name ("Why you're seeing this:
  interested in #photography").
- All new strings go through `t('social', …)`. A new label in Navigation,
  TimelinePost or similar trips `TranslatableStringsTest`, so phpunit runs as
  well as the JS build.

## 14. Testing
- `InterestScorer`: signal splitting, the 15-tag cutoff, decay and pause
  shifting, clamping, followed-tag floor, list assembly (legacy manual/pinned
  rows ignored, learned ranking and cap), rank weights, and thin-learning.
- Feed ranking: relevance × recency, diversity caps, exploration slot,
  exclusion rules (mutes, blocks, filters, language, hidden, own posts),
  snapshot pagination by `offset` and by `max_id`.
- Controllers: every endpoint, including the disabled-by-admin and opted-out
  paths, rate limiting, and that one user can
  never read or change another user's interests.
- Signal hooks in Like/Boost/Bookmark/reply services, including dedupe.
- Migrator export/import round trip; cascade on account deletion.
- `interestTracker.js`: fake timers + a mocked IntersectionObserver for the
  visibility, focus, idle pause, 30 s cap, CW-collapsed and skip rules, and
  the flush on `pagehide`.
- `InterestsSettings.vue`: automatic learning and pause switches, language
  filters, reset confirmation, and absence of reader-selected topic controls.
- Playwright on devel: switcher tab, less like this + undo, automatic learning
  controls and reset at desktop and phone width, in light and dark themes.

## 15. Documentation
The README, `info.xml` description (with a fresh screenshot of the feed and
the settings section), `Architecture.md` (tables, scorer, tracker),
`Mastodon-Compatibility.md` (new endpoints) must be updated in
the same change as the code; `DocumentationTest` guards the checkable parts.

## 16. Delivery

Built as one branch in the order above — the scoring core and the server
hooks, the web tracker, the feed and its controls, the settings cloud, the
admin card. It is large; if it is to be reviewed in pieces, the server half
and the two frontend halves are separate commits.

## 17. Decisions
Resolved 2026-09-24:
1. **GDPR / on-by-default tracking**: an admin setting *Learning default for
   users* is added (§10). Out of the box it is on.
2. **Remove = reset only**: confirmed. A removed tag can be learned again;
   lasting suppression comes from "less like this" or a filter.
3. **Admin disable keeps data**: confirmed (§10).
4. **How the feed reaches Mastodon apps**: it does not. The feed is shown by
   Aloha Social's own web interface only; a pseudo-list for apps was tried and
   removed as confusing (§8). Actions taken in apps still count.

No open points remain.
# For You recommendations foundation (F0)

The ranked For You experience builds on the existing automatically learned hashtag profile. Its internal instance setting `rec_v4_enabled` defaults to off and is exposed in Administration → Social → For you. With it off, the established hashtag feed remains available. When enabled, the web For You timeline uses one shared ranker: learned hashtag relevance, content classification, freshness, followed accounts, local discovery and any separately consented behavioral profile all contribute to one candidate order. Readers do not choose topics or configure ranking weights. The API exposes no topic catalog, topic preference write or topic-specific feed route; legacy database columns are retained only for reversible migration. Behavioural learning requires the reader's separate consent; discovery telemetry contains instance-level aggregates only.

The established hashtag feed also groups similar post stories before spreading its snapshot. It uses the same bounded, sensitive-topic-aware story terms as the ranked feed, so posts about one event do not evade repetition limits just because they use different hashtags. A story is held to two placements while another story is available; when the remaining candidates all cover that same story, one additional post may fill the feed rather than leaving the page empty. The legacy spread also limits a classified topic to six placements across the snapshot while another topic is available, and the primary hashtag to ten placements while another tag is available. That topic limit keeps a stream of related security reports from bypassing diversity merely by using different hashtags. Raw story terms exist only during ranking and are not written to the cached snapshot; the legacy ranking cache stores owner-scoped HMAC story terms and bounded topic IDs for its one-hour lifetime.

## First-use setup and content balance (T05)

There is no first-use algorithm setup. The learned hashtag profile already supplies automatic interests and can be inspected, corrected, paused or reset in Settings → For You. New behavioral signals remain separately consent-gated. The language filter remains optional, with an empty selection meaning all languages; posts without a known language remain eligible.

The versioned topic catalogue contains more than 125 English and German topic labels and aliases across technology, software, science, culture, community, transport, food, health and other areas. It supplies features to the same automatic ranker; it is not a checklist or a parallel algorithm. Security notices and vulnerability language map to the IT-security topic alongside their software topic; the PHP2Shell incident name is an explicit security alias so reports that omit generic security terms still enter the same diversity group. Politics, health, identity and religion topics are marked sensitive and excluded from automatic text classification and behavioral profiling. Text classification ignores HTML/Markdown quotations and code blocks; the ambiguous word “Apple” needs a related product or company term in the same sentence, while explicit Apple hashtags and product names remain usable evidence.

The ranker uses its automatic weighting by default. Legacy topic-choice and content-balance columns are not readable or writable through the reader API and do not affect the web timeline. Safety, moderation and visibility controls remain hard requirements; exploration never bypasses them. Synthetic offline evaluations are exploratory and do not establish real-world satisfaction or justify claims of parity with large commercial recommenders. Activity-learning consent remains separate.

## Candidate discovery (F1)

Topic-specific candidate retrieval is not exposed to readers. The internal classifier and discovery services contribute to the automatic timeline without requiring a reader-selected topic. Optional ActivityPub and ATProto post-discovery paths share a hard cap of 120 outbound requests per fixed hour across workers. Each request claims a unique expiring slot in the shared durable cache table, so the cap remains shared with local-only APCu and across concurrent workers. The admin emergency pause is enforced inside the shared discovery service, so it also covers legacy hashtag fills and followed-tag refresh jobs. When the slot store is unavailable, the budget is exhausted or discovery is paused, remote discovery stops while cached candidates remain available.

## Ranked web timeline

The Aloha Social web interface uses `GET /api/v1/recommendations/timeline` when the administrator has enabled recommendations. The endpoint returns an envelope containing existing status objects, an opaque session cursor, profile and algorithm versions, and an honest coverage state. The frontend unwraps the items into the existing timeline store, preserving the current card, interaction, media-scope and infinite-scroll behavior. Mastodon-compatible clients continue to use the unchanged `/api/v1/timelines/interests` list response.

Every web session uses the same bounded candidate and ranking path. The pool combines the reader's automatically ranked hashtag candidates with classified content, followed accounts, local trends, second-degree follow posts and cached items from saved Bluesky feeds. When there are no learned topics, the bounded public discovery sample fills otherwise unused topic-retrieval capacity and can include uncaptioned images and videos. One score blends the hashtag ranker's learned relevance and recency with content match, freshness, feedback and any separately consented behavioral profile. The re-ranker uses the most specific classified topics for diversity, caps a topic at three placements in a rolling 20-post window and six placements across the ranked snapshot while another topic is available in the bounded lookahead, and caps an author at two placements in that window while another eligible author is available. These caps relax when their alternatives are exhausted. It also applies a soft concentration penalty after five posts of the same format in that window, so a mixed pool can surface other formats without hard-filtering a single-format feed. It groups similar stories using at most 60 transient terms per candidate from the post body and, where available, the locally fetched link-preview title and description. The title comes first so generic captions that only introduce a link can still be grouped by the incident they point to; it keeps up to 20 terms from this opening and samples the remainder across the full source, so long articles are not fingerprinted only by their introductions. The source scan is bounded to 20,000 characters; posts beyond that bound do not produce story fingerprints, because unseen text could contain a sensitive topic. Stories match either when at least three terms overlap and the full term sets have 0.60 Jaccard similarity, or when the first 20 opening terms share at least six terms and have 0.50 Sørensen–Dice similarity. This catches articles with the same headline and opening but different bodies without letting a short shared phrase merge an unrelated long post. Pairwise matches form connected story clusters, so a chain of headline variants cannot evade the limit. A cluster gets at most three placements across the whole ranked snapshot while another story is available; when the bounded lookahead has no alternative, one fourth placement may fill the remaining slot. Story terms are omitted when the post contains a recognized sensitive-topic alias or hashtag, are removed before ranked items are returned, and are never stored in the viewer-bound one-hour snapshot. For readers who separately consent to behavioral learning, qualified impressions also store owner-scoped HMAC story and headline fingerprints plus one deterministic, most-specific, non-sensitive classified topic per post for 30 days; no raw topic IDs, story terms or post identifiers are stored in that history. A later feed build applies a recency-weighted story penalty when two full-story bands or six headline samples match in one session, and a topic penalty when that topic was previously seen. The stronger penalty is used, avoiding double-counting the same impression. When that penalty reaches 0.75, the story yields to a lower-exposure candidate if one is eligible in the active lookahead; if all available candidates are similarly exposed, the feed may use it to avoid an empty page. Repeated impressions in one session count toward the penalty, while the maximum count across matching fingerprints prevents one impression from being counted multiple times. Without behavioral consent, the in-snapshot diversity caps still apply, but no exposure history is stored. A candidate yields to topic saturation only when all its classified topics have reached the cap; a saturated broad parent does not hide a post with an unsaturated specific topic. When no other topic is available in the active lookahead, the topic cap relaxes too. Saved-feed recall uses only posts already opened and cached locally, so the request never waits on Bluesky AppView. Follow edges, trends, graph results and saved-feed posts are retrieval hints rather than popularity bonuses; all candidates are rechecked for visibility, moderation, language and hard hides. Hidden or newly invisible posts are skipped on continuation pages; an invalid or stale cursor returns a refresh condition. Photos, Videos, Shorts and media-only scopes have separate snapshots. There is no switch between a legacy hashtag feed and a topic feed on the web.

In the current consented behavioral profile, passive reading time does not increase a topic score. A quick skip can lower affinity; explicit likes, saves, boosts, opening a post, and measured video playback can raise it. This avoids treating a post that stayed on screen for a moment as proof of interest. Recently stored aggregates from older versions may still contain dwell signals and decay over time; the bounded topic and story caps continue to limit repeated material while those older signals fade.

The profile stores at most 300 topic, 400 author and 20 format signals per reader. When a dimension is full, a signal that has not been updated for 180 days may be evicted to make room for a new one. The 180-day retention is four half-lives of the slow profile score, so a retained signal has already decayed to at most one sixteenth of its previous value; newer signals are not evicted to make room.

Qualified impressions are stored only when the reader has opted into behavioral learning. Exposure history uses owner-scoped HMAC fingerprints for stories and headlines, plus one deterministic, most-specific, non-sensitive classified topic per post; raw topic IDs, story terms, and post identifiers are never stored. Longer stories use 64 MinHash values grouped into 16 four-value body bands; at least two matching bands in one session count as a repeated story. When a post has a separate opening, it also gets 16 independent headline samples; at least six matching samples in one session count even if the article body differs. Three-to-five-term stories use 16 independent MinHash samples and need at least six matches, and retain the historical exact owner-scoped term-set key so old exposure rows still match. Posts without a safe classified topic retain the existing story or exact-post behavior; sensitive topics are never written as topic exposure keys. If the instance secret or owner key is unavailable, no exact-post or topic exposure key is written. Exposure rows expire after 30 days. A later feed build applies a 72-hour half-life penalty for matching a story or for having previously seen the same classified topic in a session; the stronger of the two penalties is used, so one impression is not double-counted. Algorithm version 18 expanded story-term sampling across full posts; version 19 adds the PHP2Shell security alias; version 20 adds headline-based story diversity and starts a fresh cursor mode so new pages use the updated re-ranker; version 21 adds a soft concentration penalty for repeated media formats; version 22 lets posts through when they have an unsaturated specific topic even if a broad parent topic has reached its cap; version 23 adds consented topic exposure penalties and starts a fresh cursor mode; version 24 uses locally available link-preview titles and descriptions in sensitive-aware story fingerprints; version 25 lowers the per-story limit from three placements to two before its single overflow placement.

This integrated version ranks the locally cached corpus. Underfilled coverage schedules bounded refresh through the existing ActivityPub and Bluesky background queue without delaying the page request. It does not claim remote sources are fully indexed or that the initial coefficients are calibrated.

### Explicit feedback (F3)

`POST /api/v1/recommendations/feedback` accepts an event UUID, a locally visible stream ID, and a post-specific feedback operation. Readers cannot submit topic choices or topic IDs. Similarity feedback applies to an automatically derived content cluster; author and format scopes are also derived server-side. `HIDE_POST` immediately excludes that post from new candidate pages and from later pages of an open snapshot. Feedback affects newly generated rankings only, so an open session keeps its order while scrolling. The feedback controls never follow, mute or block anyone. The response contains an owner-bound feedback ID that can reverse the exact action for ten minutes; event UUIDs deduplicate retries during that Undo window. Active feedback scopes and request rates are bounded. The owner-only export includes current feedback; reset and account deletion clear the stored feedback.

`GET /api/v1/recommendations/context/{postId}` supplies only whether similarity feedback can be offered and the automatically detected post format. It returns no topic IDs or labels.

The internal catalog has stable IDs, German and English labels, reviewed local aliases, version, policy tags and language hints. Policy tags classify catalog entries only; they are never inferred or applied to a reader. Legacy preference columns are retained for rollback compatibility, but the reader API cannot retrieve or change topic choices. Reset is revision-protected and clears the learned profile and feedback; account deletion purges account-scoped records.

## Transparent ranking baseline (F2)

The internal ranker uses a deterministic, versioned linear score and assembles pages with conditional author, topic and story caps. The initial weights are a documented, uncalibrated hypothesis, not a claim to reproduce another platform's algorithm. Non-finite inputs are replaced with neutral values, domains are clipped, ties are stable, and the ranker records its algorithm and feature versions. The public web timeline uses the automatic candidate pool and snapshot; it does not accept a selected topic.

## Routes

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/v1/recommendations/preferences` | Activity-learning status and revision counters only |
| PUT | `/api/v1/recommendations/learning` | Change the authenticated reader's separate activity-learning consent |
| POST | `/api/v1/recommendations/signals` | Record consented post interactions and qualified reading signals from supported timelines; the server rechecks visibility and the current consent generation |
| POST | `/api/v1/recommendations/reset` | Reset learned activity signals and explicit feedback with `if_revision` |
| GET | `/api/v1/recommendations/export` | Export activity-learning status and explicit feedback; no topic preferences |
| GET/PUT | `/admin/recommendations` | Administrator-only instance kill switch |

Learning event generations combine an account's reset/consent generation with the instance epoch. Changing the administrator learning switch advances the single-row epoch, so queued events and open cursors become stale without updating every account profile.

For local checks, run `composer test:unit -- --filter 'RecommendationPreferenceServiceTest|RecommendationPreferencesMigrationTest|UserDeletedListenerTest'`, then `npm test -- --run` and `npm run build`. Disable the instance switch to roll back the ranked feed; the additive schema is retained and the existing feed remains available.
