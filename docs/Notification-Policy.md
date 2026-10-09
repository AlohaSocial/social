# Who may reach you, and muting a conversation

Specification for [#2463](https://github.com/AlohaSocial/social/issues/2463): the notification policy and conversation muting in the Aloha Social web app. It records the product decisions, what the server already does, and what has to be built. The implementation follows this document; where they disagree, the document is fixed first.

Status: agreed 2026-10-07 and implemented. Where the implementation chose differently from the first draft — its own routes for the always-allowed list, the notice and the admin defaults; dismiss and drop keeping their earlier meaning — this document says what was built.

## 1. Why

Negative interactions on social media go with more depressive symptoms. Being reachable by anybody, and being pulled back into a thread that went wrong, are the two moments where the app can help most. The server already has the tools (`/api/v1|v2/notifications/policy`, the requests inbox, `POST /api/v1/statuses/{id}/mute`); the web app exposes almost none of them, and the bell ignores them.

## 2. Decisions

| Question | Decision |
|---|---|
| Defaults for a new account | **Calm:** hold people you don't follow, new accounts and unsolicited private mentions for review; accept everyone else. Limited (moderated) accounts are held as well. |
| Existing accounts | **Unchanged** (everything accepted) until the person chooses. A one-time notice points them to the setting. |
| Choices in the web UI | **Allow** / **Hold for review**. *Drop* stays available through the API for Mastodon apps but is not offered in the web UI. |
| What is held | **Everything a held sender does** — mentions, replies, direct messages, likes, boosts, follows, poll results — waits together. |
| A "new account" | **Younger than 30 days**, from the account's published creation date (remote accounts included). |
| Accept | **Releases what was waiting and always allows that person** from then on, until revoked in Settings. Accepting does not follow them. |
| Dismiss | Discards what was waiting from that person; they stay held from then on (as before this change). |
| Finding out someone waits | A **line at the top of Activities**, a mention **in the digest**, and the **count on Activities in the sidebar**. No bell notification. |
| Mute a conversation | **Stops notifications only** (as Mastodon): the thread stays on the timelines. |
| Mute duration | **Until unmuted.** |
| Where mute is offered | The **post menu** and the **mention/reply cards in Activities**. |
| Where the policy lives | **Inside the Notifications section of Settings**, together with digests and quiet hours: one place for everything about being interrupted. |

## 3. What exists today

- `NotificationPolicy` (`lib/Model/Client/NotificationPolicy.php`): five keys (`for_not_following`, `for_not_followers`, `for_new_accounts`, `for_private_mentions`, `for_limited_accounts`), each `accept` / `filter` / `drop`; **every key defaults to `accept`** in the model's constructor.
- `NotificationPolicyService`: stores the policy as the user value `notification_policy`; `partition()` splits a page of notifications into shown and held **when the list is read**; `accept()` / `dismiss()` record a per-sender decision through `AccountRelationService`; `NEW_ACCOUNT_DAYS = 30`; limited = silenced by the instance's moderation.
- Routes: `GET/PATCH /api/v1|v2/notifications/policy`, `GET /api/v1/notifications/requests`, accept/dismiss (single and bulk). The policy answer carries `summary.pending_requests_count`.
- Web: `NotificationRequests.vue` (the requests list) on the Blocking page only. Nothing reads or changes the policy.
- Conversation mute: `POST /api/v1/statuses/{id}/mute|unmute` → `ActionService::muteConversation()` stores the mute against the thread's root (`social_convo_state`).
- Delivery: `NotificationService::emit()` raises the Nextcloud notification (bell, push, mail) and applies the digest/quiet-hours hold (`NotificationDeliveryService`).

## 4. Gaps this specification closes

1. **The bell ignores the policy and the mute.** A held sender, and a reply in a muted thread, are filtered out of the Activities *list*, but `emit()` still rings the bell, sends push and mail. Holding has to apply where the interruption happens.
2. **The status's `muted` is hard-coded `false`**, so no client can show or toggle the state. (Fixed on `feat/notification-policy-web`.)
3. **New accounts cannot start calm without changing everybody**, because the defaults live in the model.
4. **Nothing in the web app** reads or changes the policy, shows that people are waiting, or mutes a conversation.

## 5. Behaviour

### 5.1 Policy and defaults

- The model's built-in defaults stay `accept` for every key. They are what an account without a stored policy has, so existing accounts are unchanged.
- **When a local account is created** (`AccountService` account creation; the first-run screen, `occ social:account:create`, external users), the calm policy is stored for it:

  | Key | Value |
  |---|---|
  | `for_not_following` | `filter` |
  | `for_not_followers` | `accept` |
  | `for_new_accounts` | `filter` |
  | `for_private_mentions` | `filter` |
  | `for_limited_accounts` | `filter` |

- An administrator can change these defaults for new accounts in the admin settings (one row per key, same two choices). The stored defaults are an app value; changing it does not touch existing accounts.
- A key a Mastodon app set to `drop` is shown in the web UI as **Hold for review** with a note "An app set this to discard; choosing here replaces it". Saving from the web never writes `drop`.

### 5.2 What is held, and where

- A notification is held when **any** policy key that is `filter` applies to its sender (or the notification, for `for_private_mentions`), and the sender is not on the viewer's accepted list. `drop` (API only) holds it for good, as today; neither ever rings.
- Held applies to **every notification type** from that sender.
- **Who may send you direct messages** (Settings → Profile and privacy, `/api/v1/social/direct_messages`) is `for_private_mentions` seen from the other side: *Everybody* is `accept`, *People you follow* is `filter`, and *Nobody* is `filter` plus the user value `direct_messages_nobody`, which holds a direct message from somebody followed as well. An accepted sender still reaches the person. Setting `for_private_mentions` to `accept` here is *Everybody* again.
- **A held notification is stored, never raised:** `emit()` checks the policy (the same rules as `partition()`, shared code, not a copy) before the digest/quiet-hours check; a held notification raises nothing — no bell, no push, no mail, and it is not counted in a digest's notification total.
- The Activities list and `/api/v1/notifications` keep excluding held notifications (as today); `/api/v1/notifications/requests` lists the waiting senders.
- **Accept** releases the sender's held notifications into Activities **without ringing the bell for them now** (they are old news; the count in the sidebar reflects them as unread) and records the sender as always allowed. **Dismiss** discards them.
- The always-allowed list is shown in Settings (5.4) with a "Stop allowing" action per person; stopping returns that person to the policy.

### 5.3 Telling the reader somebody waits

- **Activities**: a line at the top, above the first notification, while `pending_requests_count > 0`: "{count} people are waiting — Review" (plural-aware). Review opens the requests list in place (the existing `NotificationRequests` component, moved from the Blocking page, which keeps a link to it).
- **Sidebar**: the count on Activities includes waiting requests (unread notifications + waiting senders). It never shows a separate badge.
- **Digest**: when a digest is raised and requests are waiting, its message ends with "{count} people are waiting to reach you" (plural-aware). A digest is not raised for requests alone.
- No bell notification is ever raised for a request.

### 5.4 Settings → Notifications

The existing Notifications section (digests, quiet hours) gains a second part, **Who may reach you**:

- Five rows, each **Allow** / **Hold for review**:
  - People you don't follow
  - People who don't follow you
  - New accounts (less than 30 days old)
  - Private mentions you didn't ask for
  - Accounts this server limited
- Under the rows: **Always allowed** — the senders accepted from requests, each with **Stop allowing**; empty state "Nobody yet. People you accept from your requests appear here."
- A sentence explains what *held* means: "Held people can still write to you. Their posts, likes and follows wait in Activities until you look; they do not ring, push or mail."
- Saves on change, like the rest of the section; a refused value reverts with the server's reason.

### 5.5 One-time notice for existing accounts

Accounts that existed before this release see one dismissible notice at the top of Activities: "You can now choose who reaches your notifications — new accounts and people you don't follow can wait for your review. Choose in Settings." Dismissing it, or saving the policy once, removes it for good (user value). New accounts never see it.

### 5.6 Mute a conversation

- **Post menu**: "Mute conversation" on any post of a thread; "Unmute conversation" when the status's `muted` is true. Hidden for an anonymous reader and on public pages.
- **Activities**: the same action in the menu of a mention or reply card.
- Muting records the mute against the thread's root (as today). From then on, notifications whose post belongs to that thread are **not raised** (bell, push, mail — `emit()` checks the mute) **and not listed** in Activities; posts in the thread stay on every timeline. Unmuting restores listing of what arrived meanwhile, without ringing for it.
- The status entity carries the real `muted` for the viewer, computed for a page of statuses in one pass (walk reply chains to their roots, compare with the viewer's muted roots; the muted-roots memo is static per request and is dropped by every mute/unmute write — `ConversationsRequest::setMuted()` must drop it before its early return).
- A short confirmation after the action: "Conversation muted — you won't be notified about it" / "Conversation unmuted".

## 6. API

Changes visible to clients:

- `muted` on statuses is real (was always `false`).
- New accounts have a stored policy with the calm values (`GET /api/v2/notifications/policy` shows them).
- Held senders no longer cause Nextcloud push notifications to phone apps either, because those come from the same `emit()`.
- Routes of this app's own: `GET /api/v1/social/notifications/allowed` and `DELETE /api/v1/social/notifications/allowed/{account_id}` (the always-allowed list), `POST /api/v1/social/notifications/policy/notice/dismiss` (the one-time notice; the policy answer carries `notice`), and `GET`/`POST /admin/notification-policy` (the defaults for new accounts).
- `/api/v1/notifications/unread_count` leaves held and muted notifications out.
- `docs/API.md` and `docs/Mastodon-Compatibility.md` describe these; the "drop is API-only" rule is stated.

## 7. Strings (English; German in all four catalogues in the same commit)

"Who may reach you", "Allow", "Hold for review", "People you don't follow", "People who don't follow you", "New accounts (less than 30 days old)", "Private mentions you didn't ask for", "Accounts this server limited", "Always allowed", "Stop allowing", "{count} people are waiting — Review", "{count} people are waiting to reach you", "Mute conversation", "Unmute conversation", "Conversation muted — you won't be notified about it", "Conversation unmuted", the one-time notice, the held explanation, and "An app set this to discard; choosing here replaces it".

## 8. Tests

- **PHP unit:** calm policy stored at account creation and not for existing accounts; admin defaults; `emit()` raises nothing for a held sender (each key) and for a muted thread, and still raises for an always-allowed sender; accept releases without raising; dismiss discards; digest line only when requests wait and never a digest for requests alone; `muted` export per viewer incl. the update-path cache drop; `drop` never written from the web save path.
- **Web (vitest):** the policy rows and always-allowed list; Activities waiting line and requests panel; sidebar count includes requests; Mute/Unmute in the post menu and on mention/reply cards; the one-time notice.
- **Interop (real servers, every PR):** a Mastodon account we don't follow mentions a calm-default local user → held: not in `/api/v1/notifications`, listed in requests, no Nextcloud notification raised; accept → released and the next mention notifies; mute a thread, a Mastodon reply in it raises nothing, unmute.

## 9. Out of scope

Muting hides nothing from timelines (decided: notifications only). Timed conversation mutes. A bell notification for requests. Changing existing accounts' policies. An admin settings card for the new-account defaults (the route exists; the card is a follow-up).

## 10. Implementation order

1. Shared hold rules + `emit()` checks the policy and the conversation mute (closes gap 1, server only, with tests).
2. Calm defaults for new accounts + admin defaults (gap 3).
3. Port `feat/notification-policy-web` onto master: `muted` export, Mute/Unmute in the post menu, requests line atop Activities — adapted to this document (placement in Notifications, two choices, mute on mention/reply cards, sidebar count, digest line, always-allowed list, one-time notice).
4. Docs (User-Guide, API, Mastodon-Compatibility, Admin) and the interop tests.
