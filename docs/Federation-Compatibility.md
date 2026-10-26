<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Remote profiles and content compatibility

This inventory describes the implemented code paths, not a claim that every
public server has been tested. ActivityPub and ATProto use their own identity,
authentication and transport; they meet in Social's local profile and status API.

## ActivityPub profiles

Profiles are resolved through WebFinger and the actor document. The actor cache
supports Person, Group, Service, Organization and Application actors. This covers
people, community/channel accounts and automated accounts without treating every
profile as a Mastodon user.

Opening a remote profile while signed in queues a bounded outbox import. The API
returns cached posts immediately and reports a pending import through
`X-Social-Remote-Profile-Filling`. The web timeline polls for up to five minutes;
older profile pages can resume the saved outbox continuation. The Nextcloud
background jobs and media worker must run for these jobs to finish. Anonymous
profile reads serve the cache and do not start arbitrary remote imports.

| Outbox content | Import behavior |
|---|---|
| `Note`, including image posts | Text, warning, language, tags, mentions, reply/quote metadata and attachment metadata use the inbox parser. Image bytes are cached separately. |
| `Question` | Poll choices, vote totals, multiple-choice mode and closing time are preserved. |
| `Article`, `Page` | Titles and link attachments are rendered using the same mapping as inbox deliveries, including community link posts. |
| `Audio`, `Video` | The supported object mapping is reused; PeerTube metadata and streamed sources remain available. |
| `Event` | Event facts are retained for the event card and Calendar import. |
| `Update` | Existing posts use the inbox handler's author and edit-time checks. An update of a post not cached yet can import its current object. |
| `Delete` | The outbox owner is checked before the normal idempotent deletion handler is called. |

Collection `first` and `next` may be URI strings, reference objects, ActivityStreams
Links, or embedded pages. Relative continuations use the embedded page's own ID
when it has one. Page links cannot change the outbox origin, carry credentials,
or point back to the current page indefinitely.

WebFinger advertises the existing remote-follow confirmation page using the
FEP-3b86 `Follow` relation for both local users and the instance actor. Opening
the intent does not follow an account automatically; the person confirms the
action in Social.

## Bluesky client writes

The existing PDS surface already supports sessions, OAuth, repository writes,
identity resolution, custom handles, blob uploads, preferences and AppView/Chat
proxying. See [ATProto compatibility](Atproto-Compatibility.md) and the
[XRPC API](API.md#bluesky-at-protocol) for their boundaries.

Both `createRecord` and `putRecord` now import `app.bsky.embed.gallery` through
the same owned-upload path as images, including galleries beside a quoted record
in `recordWithMedia`. Galleries accept up to ten images; the older images embed
accepts four. Invalid or oversized lists are refused before upload descriptions
are changed. An empty image/video alt text clears the previous description.

### Ordered profile links

`app.bsky.actor.link` records can be created, replaced and deleted through the
ordinary repository endpoints, including batches. A profile's `links` property
selects up to ten strong references to link records in the same repository.
Replacing the profile updates this selection; publishing a later bio/avatar edit
preserves it. The links are stored independently of the four Mastodon profile
fields, so a six-link Bluesky profile does not silently lose two links.

The profile API exposes the selected links as `bluesky.links`, each with `url`
and `title`, in display order. Remote AppView profile links use the same shape.
The profile page renders HTTPS destinations as direct links and omits hidden,
taken-down and unsafe destinations. Link icons are not fetched for this view.

## Confirmed remaining gaps

- Outbox entries and `Create.object` references expressed only as remote URIs
  are not dereferenced by this profile importer. Embedded objects work. A future
  resolver needs a bounded network budget and retry cursor, rather than an
  unbounded fetch per listed entry.
- Outbox `Announce` activities are not imported by the profile pull; inbox
  boosts and boosts already cached here are supported.
- Changing a post's reply target through ATProto `putRecord` is explicitly
  refused. The shared conversation state must be updated atomically before
  that replacement can be accepted.
- General XRPC account activation/deactivation, deletion, email and password
  management are not all ordinary-session handlers. Migration has its own
  restricted activation/status flow; Nextcloud owns the account credentials.

These gaps are distinct from an implemented endpoint returning a remote-service
error. PDS, AppView, Chat, ActivityPub delivery and background jobs require their
own failure evidence when diagnosing a live instance.

Protocol references:
[ActivityPub outboxes](https://www.w3.org/TR/activitypub/#outbox),
[ActivityStreams vocabulary](https://www.w3.org/TR/activitystreams-vocabulary/),
[Bluesky gallery design](https://github.com/bluesky-social/atproto/discussions/5032),
[record-with-media lexicon](https://github.com/bluesky-social/atproto/blob/main/lexicons/app/bsky/embed/recordWithMedia.json).
The profile-link schema is vendored from the installed official ATProto SDK;
see also the [upstream link record](https://github.com/bluesky-social/atproto/blob/a6f5a3c7f5e294e96971810d61f23b8f5998a5e2/lexicons/app/bsky/actor/link.json)
and [FEP-3b86](https://fediverse.codeberg.page/fep/fep/3b86/).
