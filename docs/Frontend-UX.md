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
longer owns a hidden editor. The established PostEditForm is reused inside that
same modal for editing supported fields. Stories keep their existing dialog.

Initial context is supplied before the lazy editor mounts. New posts, replies,
quotes and re-drafts keep separate drafts. Closing preserves ordinary drafts;
unfinished uploads and unsaved edits require confirmation. Failed edits retain
their values. Publishing/saving prevents duplicate submissions and unsafe route
changes. The Nextcloud dialog provides its focus trap and Escape handling, with
focus restored to an available opening control or compact field afterwards.

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
| FR-04 | Cached remote profiles, bounded outbox jobs and filling headers exist; refine incomplete, failed and retry states. |
| FR-05 | Existing Event, poll, video, audio and link components remain the rendering foundation; audit safe fallback and original-source actions. |
| FR-06 | Event metadata, Calendar import/update and ICS fallback exist. RSVP remains backend-dependent and must not be presented as Calendar import. |
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
