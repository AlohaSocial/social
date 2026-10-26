<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Federated event cards and Calendar copies

ActivityStreams Events reuse the ordinary post permissions, sanitized description,
attachments and author header. The dedicated card adds the event title, venue,
start/end time, lifecycle status and original event link. It remains behind content
warnings, keyword filters and sensitive-content covers, including its Calendar
controls. A cancelled event remains visible as cancelled; it is not a deleted post.

Times use the signed-in reader's Nextcloud timezone and locale. Guests use the
browser timezone. An invalid stored timezone falls back safely to the browser.
Upcoming, ongoing and past are derived from validated dates and the shared app
clock. Without an end time the card says Started rather than assuming the event
has ended. A post update is identified separately from these time states.

Recognized `ical:status` values are CONFIRMED, TENTATIVE and CANCELLED. Schema.org
EventCancelled/EventScheduled URLs are also normalized. Unknown statuses are
ignored. The API exposes the optional lowercase `event.status`; ActivityPub export
retains `ical:status`. ICS exports preserve the status and valid LAST-MODIFIED.
Mobilizon documents its iCalendar extension in its
[ActivityPub vocabulary](https://docs.mobilizon.org/5.%20Interoperability/1.activity_pub/).

## Import is not attendance

The existing authenticated Calendar import uses a writable calendar and a stable
UID to avoid duplicate imports, including repeated requests. The UI reports success
only when the backend explicitly confirms `imported: true`. Missing calendars,
permission failures and expired sessions produce persistent recovery feedback.
The ICS download remains available before and after import. Guests receive the
download action only. Original links allow only HTTP(S) without URL credentials.

A calendar entry is a **copy**, not a subscription or RSVP. Re-import currently
recognizes the existing UID without overwriting the user's entry. Changes and
cancellations therefore do not automatically update an imported copy. Downloading
the current ICS makes the source revision available; handling that file is up to
the chosen calendar client. There are no unsupported attendance buttons.

## Remaining scope

The publishing ActivityPub actor is not necessarily the real-world organizer.
Gancio may publish all events through an instance actor. Explicit organizer
metadata still needs a validated mapping; the UI must not invent an organizer from
the publishing account. Automatic calendar-copy updates and federated RSVP require
additional backend work. These are not claimed as completed by the card changes.

## Verification

Focused component tests cover warning/filter covers, import confirmation and
failures, download availability, safe source links, timezone and clock transitions.
PHP tests cover date validation, status normalization and ActivityPub/ICS export.
Local rendering checks do not prove delivery or availability of remote Gancio or
Mobilizon servers.
