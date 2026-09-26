# User guide

How to use Nextcloud Social as a person with an account on this Nextcloud:
getting onto the fediverse, following people, writing and reading posts, and
looking after the account. It describes the web client that comes with the
app; where something is only reachable through the Mastodon-compatible API or
a Mastodon client, it says so.

**Verified against:** app version 0.19.95, `master`, 2026-09-15 — every claim
below was checked against the code of that tree — and 0.26.60, 2026-09-25, for
*Getting an account*, the group lists under Explore and *What the web client
does not do yet* only; the rest has not been re-checked since. Menu and button
names are the English strings of the web client.

## Contents

- [Getting an account](#getting-an-account)
- [Finding and following people](#finding-and-following-people)
- [Writing a post](#writing-a-post)
- [Reading](#reading)
- [Filtering out words](#filtering-out-words)
- [Managing your account](#managing-your-account)
- [Keyboard shortcuts](#keyboard-shortcuts)
- [What the web client does not do yet](#what-the-web-client-does-not-do-yet)

---

## Getting an account

There is no sign-up. A Nextcloud account is the account: the first time you
open **Social** from the app menu, a short screen asks whether you want a
fediverse identity here, or would rather name the one you already have
somewhere else. Nothing is created until you choose. You pick your handle on
that screen — it starts out as a suggestion made from your Nextcloud user id,
may use letters, digits and underscore with dot and dash inside, and **cannot
be changed later** — and your address is `@handle@your-nextcloud-host`. Anyone
on Mastodon, Pixelfed, PeerTube or any other server that speaks ActivityPub can
follow it.

Your display name and avatar come from your Nextcloud account and are changed
there. The rest of the profile — banner, bio, profile fields — is edited in
Social (see [Managing your account](#managing-your-account)).

On the first visit the app shows a four-step introduction: your address with a
copy button, people to follow, a way to bring the follows you already have from
another server, and a button that puts you in the composer. Every step can be
skipped; once closed it stays closed, and **Settings → Introduction** shows it
again.

## Finding and following people

- **Search.** The search box at the top of the sidebar looks through the
  people, hashtags and posts this server knows. Somebody this server has never
  met is found by their full handle, `@user@example.org`: the app resolves it
  over WebFinger and shows the account.
- **Discover** (in the sidebar) has five tabs: **People** (suggestions, with
  the people who share your Nextcloud and have said where they are on the
  fediverse listed first under *On your Nextcloud*), **Starter packs**,
  **Pictures** and **Videos** being looked at, and **Hashtags** that are
  trending, ranked over a window you pick.
- **Find people across the fediverse** sits at the top of the People tab. Type a
  name, a handle or a subject and this server asks several directories at once —
  its own first, then the ones its administrator configured — and offers a
  **Follow** on every row. Pick one directory with the buttons under the box to
  ask only that one. Servers that did not answer are named under the results, so
  a short list is never mistaken for an empty fediverse. Somebody this server
  already knows opens here; a stranger opens on their own server.
- **Look at who they follow**, on the People tab, suggests the people the
  people you follow follow. Once it has answered, **Constellation** shows the
  same suggestions as a small sky: you in the middle, the people you follow who
  led somewhere around you, and the suggestions at the edge, tied to whoever
  they came through. It settles by itself; drag a star anywhere and it stays
  there, and press one to follow. **List** puts it back. Every star is a
  button that says who it is and why, so a keyboard gets there too.
- **Starter packs** are named handfuls of accounts with a **Follow everyone**
  button. Two ship with the app — the projects behind the network, and
  photography — and an administrator can curate more.
- **Follow** on a profile, in search results or on a Discover card sends a
  follow request. On an open account it is accepted at once; on a locked
  account the button reads **Requested** until the owner answers. **Unfollow**
  asks you to confirm, because on a locked account you would have to ask
  again.
- **Followed hashtags.** A hashtag timeline (`#tag` in a post, or a trending
  tag) has a follow button; following a tag puts the *public* posts carrying
  it into your home feed. It is not a relationship with anybody, and the tags
  you follow are listed above any hashtag timeline.
- **Coming from another server?** Every Mastodon-like server exports the
  people you follow as `following_accounts.csv`, and Pixelfed as
  `pixelfed-following.json`. Upload it in the first-run
  introduction or under **Settings → Migration** and each account is followed
  again from here.

## Writing a post

**New post** at the top of the sidebar, or `n`, puts the caret in the
composer; the composer also sits above most timelines. A post may be up to
5,000 characters, content warning included. What you type is kept as a draft
across a failed post, a reload or a navigation, so nothing is lost to a
network hiccup.

- **Visibility** is chosen with the globe menu next to the post button. The
  choice decides who receives the post on the fediverse, not only who sees it
  here:
  - **Public** — visible to everyone; appears in the Local and Global
    timelines of every server it reaches, and on your profile to anyone.
  - **Unlisted** — visible to everyone who has the link or follows you, but
    kept out of public timelines and discovery features.
  - **Followers** — delivered to your followers only. It cannot be boosted,
    pinned or quoted, because those would carry it to people it was not
    written for.
  - **Direct message** — delivered to the people mentioned in it and nobody
    else. The button will not send a direct post without at least one
    `@mention`.
- **Mentions and hashtags.** Type `@` and the first letters of a name to pick
  an account, or `#` for a hashtag; both are also recognised when typed in
  full. A mention makes that person a recipient of the post; a hashtag makes
  the post part of that tag's timeline everywhere it is delivered.
- **Content warning.** The speech-bubble button adds a warning field. Readers
  see the warning and a **Show more** button; the body stays out of the page
  until they ask. Attachments on a post with a warning are treated as
  sensitive. Warnings written on other servers are honoured here the same way.
- **Attachments.** Up to ten per post: pictures (JPEG, PNG, GIF, WebP, AVIF,
  and HEIC/HEIF from a phone, converted on the way in), video (MP4, WebM,
  QuickTime) and audio (MP3, AAC, OGG/Opus, WAV, FLAC). Drag them in, use
  **Add attachment**, or **Add from Files** to attach something already in
  your Nextcloud without uploading it again — the Files app's own menu also
  has **Share to Social** for the same thing. Every picture has its Exif
  metadata (including the location a phone writes) removed before it is
  stored or sent anywhere. A picture can be given one of eight mild filters,
  previewed live and baked into the copy that is posted.
- **The GIF button** opens a picker with 881 animated emoji in it, searchable by
  name — "party", "sad", "hedgehog" — plus whatever your administrator has added
  for this instance, which comes first. Nothing you type goes anywhere but your
  own Nextcloud, and neither does your browser: your server keeps its own copy of
  every picture it has been asked for. Picking one attaches it to the post you are
  writing, exactly as though you had uploaded it.
- **Alt text.** Each attachment has a **Describe this for people who cannot
  see it** field. Posting with an undescribed attachment shows a nudge, never
  a refusal.
- **Polls.** **Add poll** gives two to four options, a **Multiple choice**
  switch and a duration from 30 minutes to 7 days. Readers on any server
  vote; the totals come back and are shown under the poll, with when it
  closes or that it has.
- **Emoji.** The smiley opens a Unicode emoji picker. A custom emoji this
  instance publishes is written by its shortcode, `:name:`, and renders here
  and on other servers.
- **Send it later.** The clock button turns **Post** into **Schedule**: pick a
  time at least five minutes from now and the post waits on the server until
  then. What is waiting is listed under **Settings → Scheduled posts**, where
  one can be cancelled or moved: the clock beside an entry opens the same
  picker at the time it has, and **Save** sends the new one. The five-minute
  rule applies again, and so does the limit of 25 posts a day; a time the
  server refuses leaves the post where it was, and the reason is shown.
  What a waiting post says cannot be changed there.
- **A card.** While a post is short (up to 120 characters) and has nothing
  attached, the card button draws it big on one of six backgrounds. It goes
  out as a picture of the words, described with those words, and the words
  stay in the post as well.
- **Games.** `/dice` rolls a six-sided die (`/dice 20` a twenty-sided one),
  `/flip` tosses a coin, and `/pick tea, coffee` picks one of the options on
  the rest of the line. A line under the box says they will be played; when
  you post, the dice tumble for a moment, and what is sent is the result as
  plain text, so everybody sees the same one.
- **Replying and quoting.** **Reply** under a post opens the composer
  addressed to its author; **Quote** in a post's menu embeds it in yours. Only
  public and unlisted posts held by this server can be quoted, and the quoted
  author receives the post that carries their words.

Your own posts carry more in their `…` menu: **Edit** (saved and sent to the
servers that have the post as an update), **Delete** (removed here, and a
deletion is sent to every server that received it — this cannot be undone),
**Pin to profile** (up to five public or unlisted posts), and **Delivery
status**, which says which servers received the post, which are still being
tried and which gave up.

### Putting a post away

A post you no longer want on your profile does not have to be deleted. **Archive**,
in the post's own `…` menu, takes it off your profile and out of every timeline,
search and hashtag page on this server, and leaves it exactly where it is
otherwise: nobody is told, nothing is sent anywhere, and you can put it back at
any time from **Settings → Archived posts**.

What archiving is not is a way to take a post back from the fediverse. Other
servers that received it still have it, and somebody who kept the link still
opens it — that is what **Delete** is for, and deleting cannot be undone.

### If your post is kept back

Your server may show some posts to a moderator before they go out. There are
two reasons it does: the **first post** of an account that has not published
anything here yet, and a post that tripped one of the server's spam rules — a
lot of links, or a lot of mentions from an account nobody follows yet. Your
administrator can turn either off, and neither applies to a **direct
message**: nobody reads those but the people you wrote to. If you are an
administrator of this server, nothing of yours is ever held — you are the
person the queue is waiting for.

When it happens the app says so at once, and your writing is kept — there is
no need to write it again, and writing it again only finds the copy already
waiting. Nobody but you and the moderators can see it in the meantime: it is
in no timeline, not even your own. **Settings → Waiting to be looked at** is
where yours are, and you can take one back from there, which deletes it and
tells nobody.

If it is approved, it goes out as an ordinary post, dated the moment it was
approved rather than when you wrote it. If it is refused, it is deleted and
you are told.

### Where it was taken

The pin in the composer's toolbar says where a post was taken. It offers the
places people on this server have already posted from as you type; a name
nobody here has used yet becomes a place the moment your post goes out, with
a country if you add one. **Nothing is looked up on a map service** — the app
never sends your location anywhere to work out what it is, which is the same
promise it keeps by stripping the camera's location from your pictures. The
place shows as a small marker under the post, and tapping it opens the page
of everything public that was taken there. Release the pin and the post has
no place.

## Reading

The sidebar is the map:

- **My Feed**, **Local**, **Global** — a switcher above the posts. My Feed is
  what the people and hashtags you follow post; Local is every public post
  written on this Nextcloud; Global is every public post this server has
  received. **Photos** and **Videos** carry the same switcher over posts that
  are pictures, or videos — including videos from PeerTube channels anyone
  here follows.
- **Activities** — your notifications: mentions, favourites, boosts, new
  followers and follow requests, poll results, edits of posts you boosted.
  It is in the account menu at the foot of the sidebar, under **My profile**,
  because it is about you rather than a place to go and read. The badge
  counts what arrived since you last looked, and sits on your name while the
  menu is shut so it is never out of sight; the same
  events reach the Nextcloud bell and its mail digest; a follow request can be
  accepted or declined from the bell itself. The page says how many that is at
  the top — *3 new activities*, with a **Mark all as read** button that clears
  the badge whichever filter you are looking at — and marks the activities
  themselves: each new one carries a **New** tag beside its timestamp, and a
  **New** heading separates them from **Earlier** where the page holds both.
  Otherwise the page marks itself read after a couple of seconds in front of
  you, and that position is the one every other Fediverse client shares: a
  badge cleared on your phone is cleared here too.
- **Direct messages** — private conversations, one chat per person. **New
  message** first offers people from your existing chats and the accounts you
  follow. Search by name, `@handle` or profile link to find somebody else:
  the people you know — accounts you follow and people from your chats — are
  listed first under **People you know**, and every other account the search
  finds follows under **Other accounts**. A search failure is shown separately
  from a genuine empty result. Selecting somebody with an
  existing conversation opens that chat instead of creating another one.
  The new chat uses the same person header and message field as an existing
  conversation. Messages are sent privately to that person, so there is no
  public, follower-only or other audience to choose and no repeated mention to
  type. The inbox shows a conversation preview and unread state; opening a chat
  marks it read. The chat uses a single message area with incoming and outgoing
  bubbles, date breaks and message times. Its search, conversation list and
  message field stay in the same view without extra cards around them. On a
  phone, the conversation list and chat open as separate screens.
- **Explore** — one collapsible entry holding everything there is to look at
  besides your own feed: the hashtags you follow, your **lists**, and the tags
  this server is busy with right now, in that order. Each list is a timeline of
  its own; a Nextcloud group you belong to (up to 500 members) is a list too
  when your administrator has chosen that group for it — none are, until they
  do — kept in step with the group, and your own are made in
  **Settings → Lists**. Explore shows as much as the sidebar has room for and
  no more — make the window taller and more appears. What you follow is never
  pushed out by what happens to be trending: the trending tags take only the
  room the rest leave, and a tag you already follow is not offered twice.
- **Liked posts** and **Bookmarks** — what you favourited, and what you saved
  with **Bookmark** in a post's menu. Bookmarks are private and never leave
  this server.
- **Statistics** — what you have posted here and what came back: likes,
  boosts and replies per post, your best posts, when your posts do best, and
  which of your hashtags work.

Open a post (its timestamp, `o` or `Enter`) for its own page: the thread, a
reply box, who boosted and favourited it, and the details a card leaves out —
exact time, audience, language, whether it was edited, and a link to the
original on its home server. **Like**, **Boost** and **Reply** sit under every
post; a post's `…` menu adds **Bookmark**, **Open on original instance** and
**Report**.

A post that links somewhere gets a preview card, read by this server rather
than by your browser. With the `notify_push` app installed, new posts arrive
live; without it the page checks every 30 seconds.

### My interests

**My interests** is the second feed in the switcher above the posts, right
after My Feed and before Local and Global. It is made of the hashtags you read: Social notices
which posts you stay on longer than you usually do, which you scroll past, and
what you like, boost, reply to, bookmark, open, or whose pictures and links you
open. From that it keeps a list of hashtags, and the feed shows the posts of
the last week that carry them — the best matches first, fresher ones ahead of
older ones, with nobody allowed to fill it, and now and then a post from a
hashtag that often comes up alongside yours. Each post says why it is there:
*Because you're interested in #photography*, *Because you follow #nextcloud*,
*Related to #film*. Hashtags you follow count from the start, so the feed has
something to show on the first day; until it has learned a little, it also
shows what is trending here, and says so.

**Less like this**, in a post's menu, takes that post out of the feed and
counts its hashtags down. On the feed itself the post goes at once and *Undo*
brings it back. Muting somebody from one of their posts counts too.

**Where you change it.** **Settings → My interests** shows the list as a cloud:
the bigger and bolder a hashtag, the more it counts. Drag one to where you want
it, or click it for *Higher priority*, *Lower priority*, *Move to top*, *Pin*
and *Remove*; with the keyboard, the arrow keys move between hashtags, Enter
opens that menu and Alt with ← or → moves one. A moved hashtag is **pinned**:
it stays where you put it, while the others move as you read. *+ Add* puts a
hashtag of your own on the list, and the ones Social has almost learned are
offered underneath. Removing a hashtag forgets it — reading more about it can
bring it back — and a hashtag you follow stays for as long as you follow it.
*Languages* limits the feed to posts in the languages you choose.

**Turning it off.** *Learn from my browsing* stops the learning and takes the
feed away; what was learned is kept, frozen, in case you turn it back on.
*Pause learning* keeps the feed as it is and stops learning for a while — for
the day you are reading about something you do not want more of — and nothing
fades while it is paused. *Reset all interests* forgets everything. None of it
leaves this server, nobody else can see it, and it travels with your account
when you export it. Your administrator may have turned the feature off, or made
learning something you switch on yourself.

**In a Mastodon app** there is no My interests feed: it is part of this web
interface. What you like, boost, reply to and bookmark in an app still counts;
only the web interface knows how long you looked at something.

### Announcements from your administrators

When the administrators of this Nextcloud post a notice — a maintenance
window, a move, a new rule — it appears at the top of the timeline, above the
composer, on every page except a single post's own. It is there because you
have not read it yet.

**Got it** marks it read. It does not disappear under you: it stays where it
was, greyed, with a **Read** mark, so you can finish the sentence you were on.
It is gone the next time you open the app, and gone on your phone too — being
read is remembered by the server for your account, not by this browser.

Announcements you have already read are not shown. While the card is up —
that is, while something in it is still unread — a line at its foot, **Show 2
announcements you have read**, brings them back; once everything is read the
card is gone entirely and so are they. An announcement with an end says when
it runs out ("Until 21 September"), and one that has not started yet, or has
run out, is never shown at all.

The emoji buttons under an announcement are the one thing you can say back to
one. Press one to add yours or to take it back, or press the smiley with a
plus on it to choose another; you may put up to eight different emoji on the
same announcement.

Administrators post and remove announcements in **Administration → Social**.

### Stories

Above your own feed is a row of faces: whose stories are up. A story is one
picture or video that the people who follow the account can see for a day,
and then it is gone. A face with a coloured ring around it still holds
something you have not seen; tap it and the stories play one after another,
each for the seconds its poster gave it, and the ring goes grey. Hold the
picture to pause it, tap the left or right of it to go back or forward, and
use the arrows at the sides to move to the next account.

Your own place is always first in the row, with a **+** on it. It opens a
small dialog: choose a picture or a video, add a caption if you like, and
say how many seconds a picture should show for. On a picture you can add
**stickers** — eight emoji, or a line of your own words — drag them where you
want them, move a selected one with the arrow keys and remove it with Delete;
they are drawn into the picture before it goes up. Or choose **Text** at the
top instead: write a few words, pick one of six backgrounds (the first is your
own colour), and the story is a card with those words on it, sent with the
words as its description so somebody who cannot see it is told what it says.
Either way the picture goes up the way every attachment does — stripped of
the camera's metadata — and your story is up for a day, to your followers
and nobody else. While it is up, you can see how many people watched it and
take it down early from the same player.

Somebody watching a story can answer it: a row of emoji under the picture for a
reaction, and a box beside them for a line of text. Either one goes to the
person who posted it and to nobody else — a reply to a story is a message, not a
comment on a page — and only somebody who follows them can send one, which is
the same condition as being able to see the story at all. Five answers to one
story is the limit, because without one what a story hands somebody is a
private channel to its poster that the poster cannot close. What was said shows
under your own story while it is up, and goes with it when it expires: a reply
is not a post, and nothing keeps it after the thing it was about has gone.

Stories travel. Yours go to the people who follow you on other servers that
have stories — Pixelfed is the one that does. Stories from a Pixelfed account
do not come the other way, and that is Pixelfed's decision rather than this
server's: it sends them only to servers it recognises as Pixelfed. Taking
yours down early takes it down there too. What another server does with its copy after the day is up is that
server's business, which is why a story that arrives here is kept for a day
at the most, whatever the sender says, and one that has already expired is
not kept at all. Watching a story from another server sends a receipt to its
author so that their own server can count it, and reactions and replies travel
the same way in both directions, in the shape Pixelfed's own inbox reads.

### Collections

A profile has a **Collections** tab beside Posts, Photos, Videos and Tagged: the
account's albums. Each is a page of the posts the owner gathered into it, drawn
as the same grid of squares the Photos tab uses, with a lock on the ones that
are for followers only.

Your own shelf has a **New collection** field at the top. To put a post into
one, open the post's menu (**…**) and choose **Add to a collection**; only your
own posts with a picture or a video in them offer it, because that is all a
collection may hold. On a collection's page you can **Edit** its title,
description and audience, **Remove posts** from their own squares, or
**Delete** it — the posts stay where they are.

### Posting as a team

If an administrator has given one of your Nextcloud groups an account, a small
picker appears in the composer beside the language and the audience: **As
myself**, or the team. Choose the team and the post goes out from the team's
account — its name, its picture, its followers — rather than from yours.

Everybody in the group can post as it, and the list is checked at the moment
you press post: leaving the group takes the account away with it, with nothing
to remember to undo.

Who actually wrote each post is recorded. The team can see it, and so can a
moderator looking at a report; nobody else can. Outside the team, the team
speaks with one voice, which is the point of having a team account.

An administrator makes one with `occ social:team <group> <username>`.

### A page of your work

**Settings → Portfolio** makes a page with its own address, to put on a CV.
A profile is a feed: everything you posted, newest first, with the follow
button and the boosts and the replies around it. A portfolio is the opposite —
a title, a sentence, the pictures you chose, and nothing else on it.

Give it a title and a line about the work, choose a grid of squares or one
picture at a time, and say where the pictures come from: your most recent
public photos, or one of your collections. Then decide what goes under each
one — the caption, where it was taken, the year — and whether your own picture
is at the top.

Until you turn **Publish my portfolio** on, the page is a draft only you can
see. Once it is on, the address appears under the switch and anybody with it
can read the page without signing in, which is the whole point. **Only your
public photos are ever on it**, whatever the rest of the settings say: the page
is built by asking for your posts as an anonymous reader, so a followers-only
photograph cannot reach it. The draft shows you exactly what will be published,
which is why a followers-only photo is missing from it too.

### Who is in the photo

Open one of your own posts with a picture in it, choose **Tag people** from its
menu (**…**), and type the handles of the people in it, separated by commas.
Their names appear under the post, linked to their profiles, and each of them is
told — including somebody on another server, because the name is written onto
the post as a mention and the post is sent out again.

The field holds the whole list rather than only what you are adding, so taking
somebody out of the photo is deleting their handle. Only the person who posted a
photo can name anybody in it: if anybody could, writing your own name onto
somebody else's picture would put their post in front of an audience that never
asked for it.

Naming somebody does **not** change who may see the post. A followers-only
photograph stays followers-only.

Each profile has a **Tagged** tab: the photographs that account is named in,
whoever took them. If you are named in one and would rather not be, **Remove me**
under the post takes your name off, and needs nobody's permission.

### Notifications your settings held back

**Settings → Blocking**, under **Filtered notifications**. Your notification
settings can hold some notifications back rather than show them — from accounts
nobody here follows, from brand-new accounts, from people you do not follow.
They wait in that card, one row per sender with how many they have sent and the
first words of their most recent post.

You decide about the **person**, not about each notification: **Show these**
settles everything that account has sent and will send, and **Dismiss** stops
you being asked about them again while leaving what they sent hidden. There are
buttons for the whole list too. Until this page existed the setting held things
back with nowhere to see them, which is worse than not having the setting.

### Hiding a whole server

**Settings → Blocking** now has a third list: the servers you
have hidden. Hiding one hides every account on it and everything they post, and
takes your follows in both directions with it. It is the answer to being
bothered by a server rather than by one person — the alternative was blocking
accounts one at a time as they appeared.

### What a post used to say

A post that has been edited says so under it. That line is a button now: it
opens every version, oldest first, with the content warning and the pictures
each one carried. A reader who has been quoted or replied to can see what
changed rather than being told only that something did.

## Filtering out words

Some words are not worth reading. **Blocking → Filtered words** is where you say
so — with the accounts you have blocked and muted and the servers you have hidden,
because it is the same decision aimed at a word rather than at a person. It is the
same list a Mastodon app on your phone writes to: a filter you made there has been
applying here all along, and this is the first page that shows it.

A filter is four things:

- **A name.** Whatever reminds you later what it was for.
- **The words.** One or more; a post matches if *any* of them appears in its text,
  its content warning, the description of one of its pictures, or an option of a
  poll. Upper and lower case never matter, and a boost is read as the post it
  boosts, so nothing escapes a filter by being boosted. Each word can be marked
  **whole word only**, which is the difference between filtering "cat" and also
  filtering every "catalogue".
- **Where it applies.** **My Feed**, **Activities** (your notifications), the
  **Local, Global and hashtag** timelines, **Conversations** (the replies above and
  below an opened post), and **Profiles**. Somewhere you did not tick, a matching
  post arrives as usual. Ticking nothing is refused: a filter with no place applies
  nowhere. Two exceptions worth knowing: **list timelines are never filtered**, even
  with My Feed ticked, and in **Activities** only taking a post out has an effect —
  a notification is never folded.
- **What happens to a matching post.** Either it is **folded away** — the post keeps
  its place in the timeline, with the name of the filter where its text would be and
  a **Show anyway** button; nothing of the post is on the page until you press it, so
  the words you filtered cannot be read on the way past. In the Photos and Videos
  grids the same post shows a covered tile with no picture. Or it is **taken out of
  the timeline**, and never reaches this app at all. Either way nothing is deleted
  and nobody is told; lift the filter and the post is back as it was.

  A post that carries a content warning of its own is still warned about after you
  press Show anyway: the author's cover is not yours to lift.

A filter can also be given an expiry — thirty minutes up to a week — after which it
simply stops applying. It stays in the list, marked **Expired**, and saving it again
starts it over. Editing a filter that is still counting down leaves its expiry alone
unless you change it.

Because a filter that removes posts is easy to forget and looks exactly like a
conversation with a hole in it, the page says at the top which of your filters are
taking posts away right now, and where.

Filters are private: they are never sent to another server, and they change nothing
for anybody else reading the same post.

## Managing your account

**Pronouns and a support link.** Two of the four profile fields on **Edit
profile** have boxes of their own, because this app draws them differently: the
pronouns appear beside your name rather than in the table at the bottom, and a
support address (`https://…`) becomes a button on your profile. They are still
ordinary profile fields underneath, so Mastodon and the rest show them in their
table as they always did — and if you already wrote a row called "Pronouns" by
hand, it is picked up as one.


- **Sound and touch.** **Settings → Sound and touch** has two switches:
  **Play sounds** (a tick on a like, a breath of air when a post goes out, a
  chime when a direct message arrives; off until you turn it on, with
  **Listen** to hear them first) and **Vibrate** (a short tap on a phone; on
  unless you turn it off, and off whenever your system asks for less motion).
  Both belong to the device you are on, not to your account.
- **Profile.** **My profile** (behind your portrait at the bottom of the
  sidebar) → **Edit profile**: a banner (upload one or give the address of
  one), a bio, and up to four name/value fields shown under it — your website,
  your pronouns, where you work. All of it is shared with other servers. A
  fifth field is dropped by the server without saying so, so the editor stops
  at four, and a row with only one half filled in is dropped the same way;
  emptying the table and saving removes it from your profile everywhere.
- **Your Nextcloud profile page.** The native `/u/username` page shows your
  custom Social banner across the profile header, behind your profile picture,
  and your posts below. The banner starts at the top of the native profile
  content and meets the profile details without an extra coloured strip.
  Without a native profile header, the banner appears at
  the top of the Social section. Its **Posts**, **My Feed**, **Local** and
  **Global** tabs read Social's feeds; **My Feed** is available only on your
  own profile. This page is for reading. To publish, open the Social app.
- **Missing pictures on older remote posts.** If a federated post shows its text
  but none of its pictures, an administrator can run
  `occ social:media:recover --dry-run` to list posts whose stored ActivityPub
  original still names attachments. Run `occ social:media:recover` to fetch
  those attachments and restore the post's media and Photos index. The command
  only examines remote posts with an empty attachment list; posts with no
  attachment in their original are left alone. `--limit=N` bounds how many
  posts are examined in one run. A failed download is reported and can be
  retried by running the command again.
- **Verified links.** A field whose value is a full web address — written out,
  starting with `http://` or `https://` — can carry a **verified tick**, and
  the editor shows where each of yours stands: verified and when it was
  proved, not verified yet, or, for a bare `example.org`, that nothing written
  that way can be verified at all.

  To earn one, put a link back to your profile on that page:
  `<a rel="me" href="https://your-server/@you">…</a>` anywhere in its HTML, or
  `<link rel="me" href="…">` in its `<head>`. `me` only has to be one of the
  `rel` words, and a trailing slash or a `#fragment` on the address makes no
  difference. The editor shows the exact line to paste, with a copy button, as
  soon as a value looks like an address.

  This server fetches the page itself, in the background, at most once a day
  per account and only over http or https, and marks the field when it finds
  the link back. A page it cannot reach is left unverified and asked again
  later; a page that stops linking back loses the tick. Editing a field's value
  drops that field's tick straight away — the tick belongs to the address, not
  to the row — and it returns once the page at the new address has been
  checked. Your other fields keep theirs.
- **Featured hashtags.** **Settings → Featured hashtags** decides which tags sit under
  your bio — up to ten, and anybody reading your profile can click one to see what you
  posted under it. It opens with the hashtags you post with most and have not featured
  yet, so you pick from your own writing rather than guessing; anything else goes in
  the box beside them, with or without the `#`. The **×** on a row stops featuring that
  tag and does nothing else — your posts keep their hashtags. Your own profile links
  here from where the tags are shown, whether you feature any or not.
- **Blocking and muting.** Both are in the menu on an account's profile.
  **Block** severs the relationship in both directions, hides the account
  everywhere, and tells the other server (unless the administrator turned
  that off). **Mute** hides the account from your timelines and notifications
  without it ever knowing. **Blocking**, under **Settings**
  in the sidebar, lists both with unblock and unmute inline.
- **Follow requests.** When your account is locked, people asking to follow
  you appear under **Follow requests** in the sidebar — **Accept** or
  **Reject** — and on the Nextcloud bell. Locking the account itself is the
  **Approve who follows you** switch under **Settings → Your account**, which
  is `locked` on `PATCH /api/v1/accounts/update_credentials` underneath.
- **Reporting.** **Report** in a post's menu sends the post, with an optional
  note, to the moderators of this instance. From the web client it is never
  sent to the reported account or their server.
- **Your year.** `GET /api/v1/annual_reports` serves what Mastodon calls
  `#Wrapstodon`: one report per year you wrote anything in, with twelve months
  of what you posted and who arrived, the hashtags you used most, your three
  posts that travelled furthest, and a word for how you use the account. It is
  worked out from the posts already here whenever it is asked for, so there is
  nothing to wait for and nothing to go stale. The web client has no page for
  it yet; a phone client that has the feature shows it.
- **Who may quote your post.** A post's menu has **Quotes of this post** on
  your own posts. The top half is who may quote it from now on — anybody, the
  people who follow you, or nobody but you — and the bottom half is who already
  has, with a button that detaches one. The two are separate on purpose:
  changing the setting does not take back a quote somebody has already posted
  and other people have read. Detaching tells the other server, which then
  shows the quote as withdrawn; the post itself is theirs and stays where it is.
- **Authorized apps.** **Settings → Authorized apps** lists every app you have
  signed in to with this account — a phone client, a cross-poster, anything
  that asked — with what it may do, when you granted it and when it was last
  used. **Sign this app out** takes the key back, and the app asks you to sign
  in again the next time you open it. It is the page to open after losing a
  phone, and the only page where signing an app out does not depend on still
  having the app.
- **Export and migration.** **Settings → Migration** has three sections.
  **Export** downloads a zip of your profile, follows, followers, blocks,
  mutes, bookmarks, likes and every post you wrote — your private key is
  deliberately not in it. **Import** reads such an archive (or a Nextcloud
  account export) back in without deleting anything; posts in it are listed,
  not published again. The third section imports the follows from another
  network's export — a `following_accounts.csv`, or Pixelfed's
  `pixelfed-following.json`.
- **One list at a time, out.** Under the Export button, **Or one list at a
  time** downloads your follows, your followers, your blocks, your mutes or
  your lists as a single CSV, each written the way Mastodon writes it. It is
  the same content as the files inside the zip, for when the other end wants
  one file rather than an archive. The followers file is a record rather than
  something an import can re-create: a follower follows you again, or their
  server is told by the move.
- **Blocks, mutes and lists, in.** **Bring your blocks, mutes and lists** reads
  the other three files from the same export. Blocks and mutes are decisions
  your account makes on its own, so they apply as soon as the file is read —
  and a block federates, exactly as blocking somebody from here does. Do the
  follows first and the lists after: a list here can only hold accounts you
  follow, as on Mastodon, so anybody you have not followed again yet is counted
  as skipped rather than followed by a button that says lists. A list you
  already have is filled rather than made a second time, and a list that
  follows a Nextcloud group is left alone — its members are the group's.
- **Naming your old account.** Your old server will not send your followers
  here until this account says it is also you. **Settings → Migration →
  Accounts you also answer to** is where you say it: paste the old account's
  own address (`https://pixelfed.social/users/you`, not the handle) and press
  Add. Nothing is sent to anybody — it is a note this server keeps about an
  account it owns — and you can take it off again whenever you like. Moving
  the followers themselves is the half that cannot be undone, and stays with
  an administrator (`occ social:account:move` on the old server).
- **Bringing your posts.** The fourth section of **Settings → Migration** reads
  the export from your old server — this app's own archive, Mastodon's or
  GoToSocial's `outbox.json`, or Pixelfed's `pixelfed-statuses.json` — and
  writes the posts in it here as yours, dated when you wrote them, with their
  pictures. Nothing is sent to anybody: none of it is published again, so the
  people who follow you do not receive years of posts in one afternoon. Boosts
  and direct messages are left out, and a reply keeps the post it answers when
  the file holds both. A picture the export names only by its address is
  fetched from the old server, which has to still be running; the switch above
  the button turns that off. At most 2000 posts a run — upload the same file
  again to carry on — and an archive too large for a browser can be imported by
  an administrator with `occ social:account:import-posts`.
- **Coming from Instagram.** The same button reads an Instagram archive. Ask
  Instagram to download your information and **choose JSON**: the HTML download
  is a set of web pages with the posts taken out, and this says so rather than
  failing quietly. Your posts and reels arrive with their pictures, their
  captions and the hashtags you wrote in them. Two things are worth knowing
  before you press it. An Instagram post does not record who could see it, so
  every one of them is posted here with **your own default visibility** — set
  that first, in **Settings → Your account**, if you would rather they were not
  public. And your **stories, your archived posts and anything you deleted are
  not imported**: you put those away on purpose.
- **Leaving.** **Settings → Delete your Social account** deletes your fediverse
  account and keeps your Nextcloud one. Everything you posted goes, your
  followers and the people you follow are let go, and every server that knew
  the account is told it is gone — a post already on somebody else's server is
  deleted by asking that server to delete it, which almost all of them do and
  none of them can be made to. You are asked to type your handle first, because
  it cannot be undone; take an archive from **Settings → Migration → Export**
  first if you might want one. Afterwards you are back at the setup screen and
  can make a new account straight away, under a different handle: the old one
  is held for an hour so that nobody can take it the moment you let it go.
  Deleting the Nextcloud user does the same thing to the Social account along
  the way.

## Keyboard shortcuts

Press `?` anywhere, or open **Settings → Keyboard shortcuts**, for the list.
Shortcuts are off while you are typing in a field or the composer, and while a
modifier key is held.

| Key | Does |
|---|---|
| `j` | Next post |
| `k` | Previous post |
| `l` or `f` | Like the post in focus |
| `b` | Boost the post in focus |
| `r` | Reply to the post in focus |
| `o` or `Enter` | Open the post in focus |
| `n` | Write a new post |
| `g` | Go to the home timeline |
| `?` | Show these shortcuts |

`Enter` on a focused button or link still presses it — the shortcut only
applies where nothing else claims the key.

## What the web client does not do yet

Mastodon apps on a phone or desktop can connect only once the web server
maps `/api` and `/oauth` at the domain root onto the app: the API is served
under the app's own path, and a Mastodon app looks for it at the root. The
rules are in `contrib/webserver/` and [Admin.md](Admin.md#mastodon-apps-cannot-connect);
ask your administrator whether they are in place. There is no streaming API,
so the page polls.
