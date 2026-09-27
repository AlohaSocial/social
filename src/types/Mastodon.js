/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @typedef Field
 * @property {string} name - Ex: "Patreon"
 * @property {string} value - Ex: "<a href=\"https://www.patreon.com/mastodon\" rel=\"me nofollow noopener noreferrer\" target=\"_blank\"><span class=\"invisible\">https://www.</span><span class=\"\">patreon.com/mastodon</span><span class=\"invisible\"></span}"
 * @property {string} [verified_at] - Ex: "2019-12-08T03:48:33.901Z"
 */

/**
 * @typedef Card
 * @property {string} url - Ex: "https://www.theguardian.com/money/2019/dec/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code"
 * @property {string} title - Ex: "‘I lost my £193,000 inheritance – with one wrong digit on my sort code’"
 * @property {string} description - Ex: "When Peter Teich’s money went to another Barclays customer, the bank offered £25 as a token gesture"
 * @property {string} type - Ex: "link"
 * @property {string} author_name -
 * @property {string} author_url -
 * @property {string} provider_name -
 * @property {string} provider_url -
 * @property {string} html -
 * @property {number} width - Ex: 0
 * @property {number} height - Ex: 0
 * @property {string?} [image] - Ex: "https://example.org/img/hero.png"
 * @property {string} embed_url -
 */

/**
 * @typedef Poll - https://docs.joinmastodon.org/entities/Poll/
 * @property {string} id - Ex: "34830"
 * @property {string} expires_at - Ex: "2019-12-05T04:05:08.302Z"
 * @property {boolean} expired - Ex: true
 * @property {boolean} multiple - Ex: false
 * @property {number} votes_count - Ex: 10
 * @property {number} [voters_count] - null
 * @property {boolean} voted - Ex: true
 * @property {number[]} own_votes - Ex: [1]
 * @property {PollOption[]} options - Ex: []
 * @property {CustomEmoji[]} emojis - []
 */

/**
 * @typedef PollOption
 * @property {string} title - Ex: "accept"
 * @property {number} votes_count - 6
 */

/**
 * @typedef StatusMention - https://docs.joinmastodon.org/entities/Status/#Mention
 * @property {string} id -
 * @property {string} username -
 * @property {string} url -
 * @property {string} acct -
 */

/**
 * @typedef StatusTag - https://docs.joinmastodon.org/entities/Status/#Tag
 * @property {string} name -
 * @property {string} url -
 */

/**
 * @typedef MediaAttachment - https://docs.joinmastodon.org/entities/MediaAttachment
   @property {string} id - Ex: "22345792"
   @property {string} type - Ex: "image"
   @property {string} url - Ex: "22345792"
   @property {string} preview_url - Ex: "https://files.mastodon.social/media_attachments/files/022/345/792/small/57859aede991da25.jpeg"
   @property {string} [remote_url] -
   @property {number|null} [cache_error] - Social's finite remote-cache rejection code (1=size, 2=type, 3=access, 4=decode)
   @property {string} [media_type] - the stored file's MIME type
   @property {?string} [hls_url] - the adaptive stream's master playlist, null when the video has no ladder
   @property {object} meta -
   @property {string} description - Ex: "test media description"
   @property {string} blurhash - Ex: "UFBWY:8_0Jxv4mx]t8t64.%M-:IUWGWAt6M}"
 */

/**
 * @typedef CustomEmoji
 * @property {string} shortcode - Ex: "blobaww"
 * @property {string} url - Ex: "https://files.mastodon.social/custom_emojis/images/000/011/739/original/blobaww.png"
 * @property {string} static_url - Ex: "static_url": "https://files.mastodon.social/custom_emojis/images/000/011/739/static/blobaww.png"
 * @property {boolean} visible_in_picker - Ex: "true"
 * @property {string} category - Ex: "Blobs"
 */

/**
 * @typedef Account - https://docs.joinmastodon.org/entities/Account
   @property {string} id - Ex: "22345792"
 * @property {string} username - Ex: "Gargron"
 * @property {string} acct - Ex: "Gargron@example.com or Gargron for local users"
 * @property {string} display_name - Ex: "Eugen"
 * @property {boolean} locked - Ex: false
 * @property {boolean} bot - Ex: false
 * @property {number} discoverable - Ex: true
 * @property {boolean} group - Ex: false
 * @property {string} created_at - Ex: "2016-03-16T14:34:26.392Z"
 * @property {string} note - Ex: "<p>Developer of Mastodon and administrator of mastodon.social. I post service announcements, development updates, and personal stuff.</p>"
 * @property {string} url - Ex: "https://mastodon.social/@Gargron"
 * @property {string} avatar - Ex: "https://files.mastodon.social/accounts/avatars/000/000/001/original/d96d39a0abb45b92.jpg"
 * @property {string} avatar_static - Ex: "https://files.mastodon.social/accounts/avatars/000/000/001/original/d96d39a0abb45b92.jpg"
 * @property {string} header - Ex: "https://files.mastodon.social/accounts/headers/000/000/001/original/c91b871f294ea63e.png"
 * @property {string} header_static - Ex: "https://files.mastodon.social/accounts/headers/000/000/001/original/c91b871f294ea63e.png"
 * @property {number} followers_count - Ex: 322930
 * @property {number} following_count - Ex: 459
 * @property {number} statuses_count - Ex: 61323
 * @property {string} last_status_at - Ex: "2019-12-10T08:14:44.811Z"
 * @property {CustomEmoji[]} emojis - Ex: []
 * @property {Field[]} fields - Ex: []
 */

/**
 * @typedef Status - https://docs.joinmastodon.org/entities/Status
 * @property {string} id - Ex: "103270115826048975"
 * @property {string} created_at - Ex: "2019-12-08T03:48:33.901Z"
 * @property {string} [in_reply_to_id] - Ex: Ex: "103270115826048975"
 * @property {number} [in_reply_to_account_id] - Ex: "1"
 * @property {boolean} sensitive - Ex: false
 * @property {string} spoiler_text -
 * @property {string} visibility - Ex: "public"
 * @property {string} language - Ex: "en"
 * @property {string} uri - Ex: "https://mastodon.social/users/Gargron/statuses/103270115826048975"
 * @property {string} url - Ex: "https://mastodon.social/@Gargron/103270115826048975"
 * @property {number} replies_count - Ex: 5
 * @property {number} reblogs_count - Ex: 6
 * @property {number} favourites_count - Ex: 11
 * @property {boolean} [favourited] - Ex: false
 * @property {boolean} [reblogged] - Ex: false
 * @property {boolean} [muted] - Ex: false
 * @property {boolean} [bookmarked] - Ex: false
 * @property {boolean} [pinned] - Ex: false
 * @property {string} content - Ex: "<p>&quot;I lost my inheritance with one wrong digit on my sort code&quot;</p><p><a href=\"https://www.theguardian.com/money/2019/dec/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code\" rel=\"nofollow noopener noreferrer\" target=\"_blank\"><span class=\"invisible\">https://www.</span><span class=\"ellipsis\">theguardian.com/money/2019/dec</span><span class=\"invisible\">/07/i-lost-my-193000-inheritance-with-one-wrong-digit-on-my-sort-code</span}</p>"
 * @property {Status?} reblog - Ex: null
 * @property {object} [application] -
 * @property {string} application.name - Ex: "Web"
 * @property {string} [application.website] - Ex: null
 * @property {Account} account -
 * @property {MediaAttachment[]} media_attachments - Ex: []
 * @property {StatusMention[]} mentions - Ex: []
 * @property {StatusTag[]} tags - Ex: []
 * @property {CustomEmoji[]} emojis - Ex: []
 * @property {Card?} card - the link preview, null when the post links nowhere
 * @property {Reaction[]} reactions - the emoji reactions on the post, most used first; always an array, `[]` when there are none
 * @property {?{state: string, quoted_status: ?Status}} quote - the post this one quotes, null when it quotes none; `state` is pending, accepted, rejected or revoked
 * @property {Poll} [poll] - Ex: null
 * @property {?number} [view_count] - how many accounts here opened the post; only on the author's own copy, null on everybody else's
 * @property {FilterResult[]} [filtered] - one entry per filter this post matched; only `warn` filters ever arrive, a `hide` match is not sent at all
 * @property {?{tags: string[], reason: string}} [interest] - why the My interests feed is showing this post: the reader's matching hashtags and which kind of match (`InterestFeedService`). Absent on every other timeline
 * @property {?{can_reply?: {always?: string[], approval_required?: boolean}, can_quote?: {always?: string[]}}} [interaction_policy] - who the author allows to reply and quote
 * @property {string} [nid] - this app's own id for the post; the same string as `id`
 * @property {boolean} [local] - written on this server
 * @property {boolean} [archived] - put away by its author; only ever on the author's own copy
 * @property {?string} [edited_at] - when it was last edited, null when never
 * @property {Account[]} [tagged_people] - who is in the picture (Pixelfed's key)
 * @property {?Place} [place] - where it was taken, null when nobody said
 * @property {?object} [quote_approval] - who may quote it; only on this server's own posts
 * @property {?object} [video] - PeerTube's metadata for a video post, null for anything else
 * @property {string} [type] - set when a timeline entry is a notification about the post rather than the post itself
 */

/**
 * @typedef Place - where a post was taken; not a Mastodon entity
 * @property {string} id - this server's id for the place
 * @property {string} name - what the place is called
 * @property {string} country - ISO 3166-1 alpha-2, or '' when unknown
 * @property {?number} lat - latitude, null when nobody gave one
 * @property {?number} long - longitude, null when nobody gave one
 */

/**
 * @typedef Filter - https://docs.joinmastodon.org/entities/Filter (v2)
 * @property {string} id - the filter's id
 * @property {string} title - what the reader called it
 * @property {string[]} context - home, notifications, public, thread, account
 * @property {?string} expires_at - null for a filter that never expires
 * @property {string} filter_action - `warn` (cover the post) or `hide` (never send it)
 * @property {{id: string, keyword: string, whole_word: boolean}[]} [keywords] - the words it matches
 * @property {{id: string, status_id: string}[]} [statuses] - the posts it covers by name
 */

/**
 * @typedef FilterResult - https://docs.joinmastodon.org/entities/FilterResult
 * @property {Filter} filter - the filter that matched
 * @property {string[]} [keyword_matches] - the post's own text that matched, not the reader's keyword
 * @property {string[]} [status_matches] - the ids of the covered posts that matched
 */

/**
 * @typedef Reaction - one emoji under a post, and who used it
 *
 * Not a Mastodon entity: Mastodon has no reactions. The shape is the one the
 * Misskey-family servers and their clients use, and it is what this app's own
 * API answers — see docs/API.md.
 *
 * @property {string} name - the emoji itself; always a unicode emoji, never a `:shortcode:`
 * @property {number} count - how many accounts used it
 * @property {boolean} me - whether the reader is one of them; false when anonymous
 */

/**
 * @typedef Context - https://docs.joinmastodon.org/entities/Context
 * @property {Status[]} ancestors - Parents in the thread.
 * @property {Status[]} descendants - Children in the thread.
 */

/**
 * @typedef Notification - https://docs.joinmastodon.org/entities/Notification
 * @property {string} id - Ex: "34975861"
 * @property {"mention"|"status"|"reblog"|"follow"|"follow_request"|"favourite"|"poll"|"update"|"admin.sign_up"|"admin.report"} type - Ex: "mention"
 * @property {string} created_at - Ex: "2016-03-16T14:34:26.392Z"
 * @property {Account} account -
 * @property {Status} [status] -
 * @property {object} [report] - the report, on an admin.report notification
 * @property {Account[]} [accounts] - everyone a grouped card stands for, most recent first
 */

/**
 * @typedef Relationship - https://docs.joinmastodon.org/entities/Relationship
 * @property {string} id - The account ID. Ex: "https://example.com/users/@tommy""
 * @property {boolean} following - Are you following this user?
 * @property {boolean} showing_reblogs - Are you receiving this user’s boosts in your home timeline?
 * @property {boolean} notifying - Have you enabled notifications for this user?
 * @property {string[]} languages - Which languages are you following from this user?
 * @property {boolean} followed_by - Are you followed by this user?
 * @property {boolean} blocking - Are you blocking this user?
 * @property {boolean} blocked_by - Is this user blocking you?
 * @property {boolean} muting - Are you muting this user?
 * @property {boolean} muting_notifications - Are you muting notifications from this user?
 * @property {boolean} requested - Do you have a pending follow request for this user?
 * @property {boolean} domain_blocking - Are you blocking this user’s domain?
 * @property {boolean} endorsed - Are you featuring this user on your profile?
 * @property {string} note - This user’s profile bio
 */

export default {}
