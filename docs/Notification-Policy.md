<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Notification policy and conversation muting

This page describes the current behavior for deciding which incoming
notifications reach a person's Activities and Nextcloud notification channels.
It is separate from timeline visibility: a held notification does not hide the
underlying post from a timeline the reader is allowed to view.

## Reader controls

Under **Settings → Notifications**, readers can choose **Allow** or **Hold for
review** for five cases:

- accounts they do not follow;
- accounts that do not follow them;
- accounts less than 30 days old;
- unsolicited private mentions/direct messages;
- accounts limited by the instance.

The web interface does not offer `drop`. Mastodon-compatible API clients may
set it; it is treated as a permanently discarded notification. Readers can
also review held senders, accept a sender as always allowed, dismiss a sender,
or remove a sender from the always-allowed list. A waiting indicator appears
in Activities and contributes to its navigation count.

New accounts start with a calm policy unless the administrator changes the
defaults. Existing accounts without a stored policy continue to accept
notifications as before. The administrator defaults are available through the
admin notification-policy API; there is not currently a dedicated admin UI
card for them.

The direct-message audience control is shared with the Bluesky chat declaration
when that integration is enabled. Its choices are Everyone, People you follow,
and Nobody. Notification policy acceptance can allow a sender through the
review filter, but it does not create a follow relationship.

## Delivery and conversation mutes

When policy holds an incoming notification, it is not raised in Activities and
does not generate a Nextcloud bell, push or email notification. Requests can be
reviewed separately. Accepting releases eligible waiting notifications without
raising them as new alerts; dismissing discards them. Muting a conversation
suppresses notifications in that thread while leaving its posts in timelines.
Unmuting allows later notifications again.

The same policy rules are applied when the server builds notification pages,
counts unread items and decides whether to emit a Nextcloud notification. A
Mastodon `muted` status field reflects the reader's actual conversation state.

## API surface

The Mastodon-compatible routes expose notification policies and requests.
Social-specific routes manage the always-allowed list and the one-time notice;
administrator routes set defaults for new accounts. The API reference documents
the current paths, authentication and response fields:
[API.md — notifications](API.md).

The policy is stored per reader. Accepting or dismissing a sender is a local
decision, not an ActivityPub or AT Protocol block. Conversation mutes suppress
alerts; they do not delete posts, unfollow authors or stop federation.

## Related documentation

- [User guide](User-Guide.md#who-may-reach-you-and-requests)
- [Mastodon compatibility](Mastodon-Compatibility.md)
- [Architecture](Architecture.md)
