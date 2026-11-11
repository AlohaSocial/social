<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="shown.length" class="announcements" aria-labelledby="announcements-title">
		<header class="announcements__header">
			<IconBullhorn :size="20" class="announcements__icon" />
			<h2 id="announcements-title" class="announcements__title">
				{{ n('social', 'Announcement', 'Announcements', shown.length) }}
			</h2>
		</header>

		<article
			v-for="announcement in shown"
			:key="announcement.id"
			class="announcement"
			:class="{ 'announcement--read': announcement.read }">
			<!-- the admin's own words, rendered through the same pipeline as a
			     post: parsed, reduced to an allowlist of tags and rebuilt as
			     elements, never handed to v-html -->
			<MessageContent class="announcement__text" :item="announcement" />

			<p class="announcement__meta">
				<time :datetime="announcement.published_at" :title="fullDateTime(announcement.published_at)">
					{{ fromNow(announcement.published_at) }}
				</time>
				<span v-if="until(announcement)" class="announcement__until">{{ until(announcement) }}</span>
				<span v-if="announcement.read" class="announcement__state">{{ t('social', 'Read') }}</span>
			</p>

			<div class="announcement__actions">
				<div class="reactions">
					<button
						v-for="reaction in announcement.reactions"
						:key="reaction.name"
						type="button"
						class="reaction"
						:class="{ 'reaction--mine': reaction.me }"
						:disabled="busy !== ''"
						:aria-pressed="reaction.me ? 'true' : 'false'"
						:aria-label="reactionLabel(reaction)"
						@click="toggle(announcement, reaction)">
						<!-- a reaction with a picture names a custom emoji this
						     instance publishes; one without is a character -->
						<img
							v-if="reaction.url"
							class="reaction__image"
							:src="reaction.url"
							:alt="reaction.name"
							draggable="false">
						<span v-else class="reaction__emoji" aria-hidden="true">{{ reaction.name }}</span>
						<span class="reaction__count" aria-hidden="true">{{ reaction.count }}</span>
					</button>

					<button
						type="button"
						class="reaction reaction--add"
						:disabled="busy !== ''"
						:aria-haspopup="true"
						:aria-label="t('social', 'Add a reaction')"
						:title="t('social', 'Add a reaction')"
						@click="askForPicker(announcement)">
						<EmoticonPlusOutline :size="16" />
					</button>
				</div>

				<NcButton
					v-if="!announcement.read"
					class="announcement__dismiss"
					variant="primary"
					:disabled="dismissing === announcement.id"
					@click="dismiss(announcement)">
					<template v-if="dismissing === announcement.id" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Got it') }}
				</NcButton>
			</div>
		</article>

		<NcButton
			v-if="earlier.length"
			class="announcements__earlier"
			variant="tertiary"
			@click="revealed = !revealed">
			{{ revealed
				? t('social', 'Hide the ones you have read')
				: n('social', 'Show %n announcement you have read', 'Show %n announcements you have read', earlier.length) }}
		</NcButton>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import IconBullhorn from 'vue-material-design-icons/Bullhorn.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MessageContent from './MessageContent.js'
import eventBus, { REACTION_PICK } from '../services/eventBus.js'
import logger from '../services/logger.js'
import { mayHave, setHint } from '../services/startupHints.js'
import { showError } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import { useServerData } from '../composables/useServerData.js'
import { fromNow, fullDate, fullDateTime } from '../utils/relativeTime.js'

/**
 * What the administrators of this server are telling everybody.
 *
 * An announcement was reachable only over the client API until this existed:
 * an admin could post "we are moving servers on Friday" and nobody reading the
 * web client would ever be told. It goes at the top of the timeline because
 * that is the page people are on, above the composer, where it is read before
 * anything is written.
 *
 * **An unread announcement interrupts; a read one does not.** The card is
 * drawn only when something in the list was unread at the moment the page
 * loaded, so a reader who has dealt with the notices never scrolls past this
 * again. What was already read is not shown, but while the card is up a
 * disclosure at its foot brings it back — somebody who dismissed one too
 * quickly is one press away from it for the rest of the visit.
 *
 * Dismissing does not take the announcement off the screen. `read` is what
 * changes: the notice stays where it was with its dismissal button gone and a
 * "Read" mark in its place, because a card that vanishes under the cursor
 * takes the sentence the reader was halfway through with it. It is gone on the
 * next visit, and gone on every other device the account uses — the dismissal
 * is stored per account by the server, not in this browser.
 *
 * Nothing of this is on the public page. An announcement is what the instance
 * tells its *accounts*, and the route behind it needs a viewer: a reader who is
 * not logged in would get a 401 for a card they are not meant to see.
 *
 * Nothing is done here about a window that has not opened or has closed:
 * `GET /api/v1/announcements` compares the bounds in its own query, so an
 * announcement outside its window is never in the answer. One with an end is
 * shown with it ("Until 21 September"), which is the half of a window a reader
 * can act on.
 */
export default {
	name: 'Announcements',

	components: {
		EmoticonPlusOutline,
		IconBullhorn,
		MessageContent,
		NcButton,
		NcLoadingIcon,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** every announcement that applies now, oldest effective first */
			announcements: [],
			/**
			 * The ids that were unread when the list came in.
			 *
			 * Held apart from `read` so that dismissing one keeps it on the
			 * screen: the flag flips, this does not.
			 *
			 * @type {string[]}
			 */
			interrupting: [],
			/** whether the ones that were already read are showing too */
			revealed: false,
			/** which announcement is being dismissed, '' when none */
			dismissing: '',
			/** which reaction is in flight, as `id|emoji`, '' when none */
			busy: '',
		}
	},

	computed: {
		/**
		 * The announcements this card is for: the ones that were unread when
		 * the page loaded, and the rest only once they are asked for.
		 *
		 * @return {object[]} in the order the server sent them
		 */
		shown() {
			return this.revealed
				? this.announcements
				: this.announcements.filter((announcement) => this.interrupting.includes(announcement.id))
		},

		/**
		 * @return {object[]} the ones that had already been read on arrival
		 */
		earlier() {
			return this.announcements.filter((announcement) => !this.interrupting.includes(announcement.id))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		fromNow,
		fullDateTime,

		/**
		 * When the announcement stops applying, for one that says.
		 *
		 * A whole-day window ends at midnight after its last day, so it is
		 * written as a date: the time of day is the shape of the bound rather
		 * than anything the reader needs. The server sets that midnight in
		 * UTC, so the date is UTC's: west of Greenwich it is still the day
		 * before.
		 *
		 * @param {object} announcement one entity
		 * @return {string} the line to show, or '' when it has no end
		 */
		until(announcement) {
			if (!announcement.ends_at) {
				return ''
			}

			return t('social', 'Until {date}', {
				date: announcement.all_day
					? fullDate(announcement.ends_at, 'UTC')
					: fullDateTime(announcement.ends_at),
			})
		},

		/**
		 * @param {object} reaction one entry of the bar
		 * @return {string} what a screen reader is told about pressing it
		 */
		reactionLabel(reaction) {
			const count = n('social', '%n reaction', '%n reactions', reaction.count)

			return reaction.me
				? t('social', 'Remove your {emoji} reaction ({count})', { emoji: reaction.name, count })
				: t('social', 'React with {emoji} ({count})', { emoji: reaction.name, count })
		},

		/**
		 * Reads what applies now.
		 *
		 * A failure is logged rather than announced: the timeline is the page
		 * and this is something extra above it, so a server that cannot answer
		 * costs the reader a notice they never knew was there, not an error
		 * message on every visit. Everything the reader presses below says so
		 * when it fails.
		 *
		 * @return {Promise<void>} once the list is in
		 */
		async load() {
			// nothing behind this route answers without a viewer
			if (this.serverData.public) {
				return
			}
			// the page said nothing applied when it was opened; that spares the
			// first read only, because one may be published while it is open
			if (!mayHave('has_announcements')) {
				setHint('has_announcements', true)
				return
			}

			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/announcements'))
				const announcements = Array.isArray(data) ? data : []

				this.announcements = announcements.map((announcement) => ({
					...announcement,
					read: announcement.read === true,
					reactions: Array.isArray(announcement.reactions) ? announcement.reactions : [],
				}))
				this.interrupting = this.announcements
					.filter((announcement) => !announcement.read)
					.map((announcement) => announcement.id)
			} catch (error) {
				logger.debug('Could not read the announcements', { error })
			}
		},

		/**
		 * Marks one read for this account, everywhere.
		 *
		 * It stays on the screen — see the note on the component — so nothing
		 * is said about it having worked beyond the card changing.
		 *
		 * @param {object} announcement the one that was dismissed
		 * @return {Promise<void>}
		 */
		async dismiss(announcement) {
			if (this.dismissing !== '') {
				return
			}

			this.dismissing = announcement.id
			try {
				await axios.post(generateUrl(`/apps/social/api/v1/announcements/${announcement.id}/dismiss`))
				announcement.read = true
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not dismiss that announcement')))
			} finally {
				this.dismissing = ''
			}
		},

		/**
		 * Asks for the page's one emoji picker, and hands it what to do with
		 * the answer.
		 *
		 * The picker is most of a megabyte and is mounted once by `App`; see
		 * ReactionPicker for why no card owns one.
		 *
		 * @param {object} announcement the one being reacted to
		 */
		askForPicker(announcement) {
			if (this.busy !== '') {
				return
			}

			eventBus.emit(REACTION_PICK, {
				react: (emoji) => this.send(announcement, emoji, true),
			})
		},

		/**
		 * @param {object} announcement the one being reacted to
		 * @param {object} reaction the entry that was pressed
		 */
		toggle(announcement, reaction) {
			this.send(announcement, reaction.name, !reaction.me)
		},

		/**
		 * Puts an emoji on an announcement or takes it back.
		 *
		 * The routes answer `{}` rather than the announcement, so the bar is
		 * redrawn from what was sent — after the write, not before it. An
		 * emoji the server refuses (it takes one emoji or a shortcode it
		 * publishes, and at most eight per account) therefore never appears in
		 * the bar at all, which is better than one that appears and is taken
		 * away again.
		 *
		 * @param {object} announcement the one being reacted to
		 * @param {string} name the emoji, or the shortcode of a custom one
		 * @param {boolean} add whether to put it on or take it back
		 * @return {Promise<void>}
		 */
		async send(announcement, name, add) {
			if (this.busy !== '' || name === '') {
				return
			}

			// the emoji is in the path, as it is on Mastodon, so it has to
			// survive the trip there
			const url = generateUrl(`/apps/social/api/v1/announcements/${announcement.id}/reactions/${encodeURIComponent(name)}`)

			this.busy = announcement.id + '|' + name
			try {
				await (add ? axios.put(url) : axios.delete(url))
				announcement.reactions = this.withReaction(announcement.reactions, name, add)
			} catch (error) {
				showError(errorOf(error, add
					? t('social', 'Could not add that reaction')
					: t('social', 'Could not remove that reaction')))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The bar with this account's own reaction added or removed, ordered
		 * as the server orders one: most-reacted first, alphabetical within a
		 * tie, so a redraw does not reshuffle it.
		 *
		 * @param {object[]} reactions the bar as it stands
		 * @param {string} name the emoji that moved
		 * @param {boolean} add whether it was put on or taken back
		 * @return {object[]} the bar as it now is
		 */
		withReaction(reactions, name, add) {
			const existing = reactions.find((reaction) => reaction.name === name)
			const rest = reactions.filter((reaction) => reaction.name !== name)

			if (add && !existing?.me) {
				rest.push({ ...(existing ?? { name }), count: (existing?.count ?? 0) + 1, me: true })
			} else if (!add && existing?.me) {
				// the last one holding it takes the entry with it
				if (existing.count > 1) {
					rest.push({ ...existing, count: existing.count - 1, me: false })
				}
			} else if (existing) {
				rest.push(existing)
			}

			return rest.sort((a, b) => (b.count - a.count) || a.name.localeCompare(b.name))
		},
	},
}
</script>

<style lang="scss" scoped>
.announcements {
	margin-bottom: 14px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	/* the notice is not one of the posts under it: the primary tint says so
	   before a word of it is read */
	background: var(--color-primary-element-light);
}

.announcements__header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.announcements__icon {
	color: var(--color-primary-element);
}

.announcements__title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.announcement + .announcement {
	margin-top: 8px;
	padding-top: 8px;
	border-block-start: 1px solid var(--color-border);
}

/* one that has been dealt with stays where it was, out of the way of the ones
   that have not */
.announcement--read {
	opacity: .7;
}

.announcement__text {
	margin-top: 4px;
	/* an announcement is written by an administrator and may be long; it wraps
	   rather than pushing the column wider */
	overflow-wrap: anywhere;

	:deep(p) {
		margin: 0 0 4px;
	}

	:deep(a) {
		text-decoration: underline;
	}
}

.announcement__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.announcement__state {
	font-weight: 600;
}

.announcement__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-top: 6px;
}

.reactions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.reaction {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 24px;
	padding: 1px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	line-height: 20px;
	cursor: pointer;

	&:hover:not(:disabled),
	&:focus-visible {
		border-color: var(--color-primary-element);
	}

	&:disabled {
		cursor: default;
		opacity: .6;
	}
}

/* the ones this account chose, so pressing again to undo is obvious rather
   than a discovery */
.reaction--mine {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.reaction--add {
	padding-inline: 6px;
	color: var(--color-text-maxcontrast);
}

.reaction__emoji {
	font-size: 14px;
	/* an emoji is a picture: it must not pick up the weight of what it sits in */
	font-style: normal;
	font-weight: normal;
}

.reaction__image {
	width: 16px;
	height: 16px;
	object-fit: contain;
}

.reaction__count {
	font-variant-numeric: tabular-nums;
}

.announcements__earlier {
	margin-top: 4px;
}
</style>
