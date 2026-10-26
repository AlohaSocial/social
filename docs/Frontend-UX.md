<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Frontend interoperability and UX work

This tracks the frontend requirements without treating a component or a passing
build as proof of complete end-to-end interoperability. Changes reuse the Vue,
Pinia and Nextcloud implementation. Intermediate stacked PRs can have pending
checks; the final consolidation still needs its required checks.

## Public profile presentation (FR-01 / FR-02)

Settings → Public profile layout selects Posts, Photos or Videos as the default,
and hides optional navigation tabs. Posts remain available. Existing accounts
retain their current default and all five tabs until they save a preference.

The existing profile routes and `profileKinds` switcher are reused. An unspecified
profile URL resolves the owner's default; an explicit `media=posts`, `image` or
`video` keeps the reader's selection, including back navigation from a post.
The parent passes the same layout to Posts/Photos/Videos, Tagged and Collections;
Collections no longer adds an extra horizontal inset. An explicitly opened hidden
tab remains available in its own navigation for keyboard access.

These are public presentation preferences, stored per Nextcloud user. They do not
change post audiences, authorize a team identity or bypass album/post permission
checks. Hiding a tab is not a way to make its posts private. Native ActivityPub
actors publish the optional `profileDefaultTab` and `profileTabs` Social extension;
other servers may ignore it. The local profile API exposes `profile_layout`. Public local lookups use the bare username and receive the configured cloud address; aliases such as `localhost` no longer produce an invented remote handle. Guest profile navigation does not read or write private home read markers.

## Shared composer and progressive entry (FR-03, revised interaction)

The latest requested interaction keeps a compact one-line field on feeds and the
owner's profile, plus a compact reply field below a post. These are a mode of the
existing Composer, with the same validation, default visibility, publisher and
account-scoped draft; there is no second sending implementation. Simple text can
be sent directly. Expand opens the existing full popup with the same draft.
Rich drafts (media, warnings, polls, events, scheduling, team identities and
other hidden settings) require review in the popup before sending. This one-line
entry supersedes the earlier strict modal-only creation requirement.

The compact instance unmounts while the popup is open, avoiding two editors
writing the same draft. Replies, quotes, delete-and-re-draft, Files handoffs,
video uploads and the keyboard shortcut use the shared modal host. Search no
longer owns a hidden editor. PostEditForm adapts the same Composer inside that
modal for editing supported fields. Stories keep their existing dialog.

Initial context is supplied before the lazy editor mounts. New posts, replies,
quotes and re-drafts keep separate drafts. Closing preserves ordinary drafts;
unfinished uploads and unsaved edits require confirmation. Failed edits retain
their values. Publishing/saving prevents duplicate submissions and unsafe route
changes. The Nextcloud dialog provides its focus trap and Escape handling, with
focus restored to an available opening control or compact field afterwards.

## Remote profile synchronization (FR-04 / FR-13)

Cached profile posts remain visible during background synchronization. The shared
list/grid parent shows localized pending, failed and taking-longer notices. A
failed or overdue read is never presented as an empty account; recovery checks
the first page while preserving the list and deduplicating new posts.

ActivityPub outbox jobs expose a safe failure state, with a 60-second backoff
before re-queuing. Empty filtered first pages may continue an existing outbox
cursor, so older photos/videos can be found behind recent non-media items. The
frontend also handles failed HTTP polls and bounded polling exhaustion. It
cancels scheduled checks on navigation and keeps only one scheduled check for
the active timeline. Existing Bluesky pending-profile headers receive the same
bounded waiting UX; detailed AppView failure reporting still belongs to FR-07.

The API state distinguishes a pending or failed operation from no current
operation (`idle`); idle does not promise full remote indexing. Guest reads keep
the existing cache-only behavior. Tests cover the local queue/state contracts;
this does not certify availability or completeness of every public remote server.

## Photos feed presentation (FR-01 / FR-11)

The Photos timeline offers Media feed (the existing full post cards, with media
before the caption) and Tiles (the existing shared media grid). The reader's
choice is remembered per account in browser storage; unavailable storage does not
prevent switching. Changing the layout does not reset the feed scope, cursor or
loaded post index. Profile Photos tabs retain their separate image-only grid.

The Photos page uses a compact Share photos or a video picker instead of a plain
text field. It reuses the Videos entry component and hands the selected files to
the one existing modal composer, preserving drafts, alt text and publishing
validation. Dismissing the picker does not open another editor.

Both timeline presentations request the same visual feed: images, videos and
animated images, including native PeerTube Video objects. Text-only, audio-only
and document-only posts are excluded by the database **before pagination**. The
ordinary timeline remains unrestricted. For You uses its existing combined visual
media filter; legacy candidate and trend fallback queries follow the same rule.

## Continuing drafts from Photos

Photos shows Drafts (with a count) beside its media picker when this account has
valid saved composer drafts in this browser. The existing account-scoped storage
is enumerated without adopting unscoped legacy drafts or other readers' entries.
The list includes new-post, reply, quote and re-draft contexts, sorted by save time;
existing validation and seven-day expiry still apply. This is not server-side or
cross-device draft synchronization, nor a separate draft archive.

Selecting a draft reuses the shared modal. Replies and quotes first reload their
original post through the current viewer's status API; unavailable or inaccessible
targets leave the draft intact and show a retryable error instead of converting it
into a standalone post. Re-drafts retain their original storage slot even after
the deleted source post is gone. Draft selection does not publish or delete it.
Existing attachment revalidation, team permissions and unsaved-content protection
remain authoritative. The button refreshes after composing, tab focus and storage
changes, and disappears when no drafts remain.

## Composer attachment gallery (FR-03 / FR-11)

Attachments are reviewed one at a time with previous/next controls and an announced
position. The description, filter and focal-point controls belong to the selected
attachment. All files and descriptions remain in the existing composer state;
changing slides does not replace the post text or lose an upload. Removal keeps a
valid selected attachment. The full image source is used instead of a potentially
cropped thumbnail. The preview frame follows decoded image dimensions within the
modal height limit and uses contain, so portrait images are shown completely.
Focal-point coordinates continue to use the full image frame. Original file and
publishing crop settings are unchanged. Published media-first posts now page from
two attachments onwards as well. Their gallery indicators paint small circular
markers inside explicit 24px targets, rather than letting Nextcloud's global
button minimum stretch them into large bars. Previous/next arrows keep 44px touch
targets; the compact counter and keyboard navigation remain available.

## Shorts playback (FR-11 / FR-13)

The existing one-player stack remains shared across scopes, with native scroll
snap, sound preference and learning consent intact. Standard Nextcloud controls
below the player now expose previous, play/pause and next, plus the current
position/loading state. Their space is reserved in the responsive player sizing,
including dynamic mobile viewport height. The same controls work for held
24-hour pictures. Opening creation holds the current picture/video.

Paging failures retain the current clip and expose retry outside the video frame;
player network/decoding failures offer a separate retry without removing the post.
Recommendation feedback uses an icon-only menu with an accessible label, rather than a wide named button over the video. A public reader is not offered a creation action. Keyboard shortcuts no longer
intercept keys intended for buttons, links, forms or menus. Next waits for a new
page when necessary and discards navigation after a scope/current-clip change.

## Optional explanatory and activity cards

Feed explanations and recommendation-coverage hints are localized cards with an
explicit close action instead of permanent loose text. Closing remembers the
reader's choice in this browser, separately for their Nextcloud account and card
context; an unavailable browser store still permits closing for the current app
session. Cards do not vanish while someone is reading them. Existing one-time
learning notices and daily/weekly recap dismissal keep their established behavior.

The profile posting-activity chart uses the same card, independently dismissible
per viewed profile. This changes only the reader's presentation, not the profile
owner's data. Explanations attached to recommended posts remain part of the feed
snapshot; they are not temporary popups or timed hints. Error/retry notices remain
operation states rather than educational cards.

## Completed stabilization

- Author-only view counts are text on the post detail page. There is no eye icon
  in the floating post action menu.
- Repeated empty thread-context responses no longer scroll back to the main
  post. Initial ancestor loading avoids interrupting a focused text input and
  respects reduced motion.
- Production runtime autoloading and local recovery are documented in Admin.md.

## Remaining work and dependencies

| Requirement | Existing pieces / next work |
|---|---|
| FR-01 | Shared media grid, switcher and route history exist. Continue checking loading/failure consistency and transitions across all five views. |
| FR-02 | Public default and optional-tab preferences are implemented in this stack; direct links keep normal access checks. |
| FR-03 | Shared Composer: compact one-line sending plus the existing full modal, as subsequently requested. Draft contexts, pending-save protection and dirty-edit confirmation are covered. |
| FR-04 | Cached posts stay visible with pending/failure/overdue notices and a deduplicating first-page recheck. Outbox failures, retry backoff and empty filtered-page continuation are implemented; live remote availability is separate. |
| FR-05 | Existing Event, poll, video, audio and link components remain the rendering foundation; audit safe fallback and original-source actions. |
| FR-06 | Event metadata, Calendar import with stable-UID deduplication and ICS download exist. Status/timezone and warning-cover improvements are documented in Events.md. Imported copies are not automatically updated; organizer enrichment remains to be completed. RSVP remains backend-dependent and must not be presented as Calendar import. |
| FR-07 | Network badges, ordered profile links, feeds/lists/starter packs and moderation exist; improve discoverability and failure feedback. |
| FR-08 | Recommendation explanations such as “Based on your interests” now belong to the feed snapshot and survive ordinary status/count refreshes, including explicit `interest: null`. They appear only in personalized feeds, are reset on a fresh feed and are pruned with the bounded navigation history. Optional explicit interests are now authorized as an additional ranking signal. No compulsory topic setup; behavioral learning and discovery must still work without selections. Existing feedback/consent controls remain authoritative. |
| FR-09 | Existing Group/organization/community metadata and thread views need clearer presentation; do not invent unsupported moderation or membership actions. |
| FR-10 | Reuse existing team identities and permission checks; improve selected-identity clarity and prevent accidental identity changes. |
| FR-11 | Reuse media grids, lightbox, alt text and album management. Photo albums and account recommendation collections have different semantics. |
| FR-12 | Improve real asynchronous outcome and notification feedback using the existing API error handling. |
| FR-13 | Reuse synchronization and identity health state; user-facing messages must not expose credentials or sensitive server diagnostics. |

New controls must be localized, keyboard reachable and responsive. Each major
block includes focused regression coverage; browser evidence and backend/service
availability are reported separately. This table is a work list, not a claim
that every requirement is finished.


### Shared published-post editor

The Edit action now opens the same `Composer` used for new posts, inside the
existing modal host. Text, mention/emoji tools, content warnings, the attachment
carousel and descriptions, and supported event fields share the creation UI.
`PostEditForm` only adapts save/cancel events and unsaved-change/upload guards.
Editing never reads, replaces or clears a creation/reply draft.

Save sends text, explicit warning/sensitive values, language, the full replacement
attachment list and descriptions through the existing status-update API. Media
descriptions remain local until Save, including an explicitly empty description.
A failed save keeps the editor and changes available; pending saves and uploads
block another submission. Escape/cancel asks before discarding changes.

Published-post editing does not offer creation-only settings that the update API
cannot apply: audience/identity changes, polls, scheduling, new event creation,
location, video metadata, image filters and focal-point writes. Existing event
dates and location remain editable. Existing polls and other untouched metadata
are preserved by omitting them from the update request. This is UI/API reuse, not
a new publication path or a claim of remote server edit propagation.
