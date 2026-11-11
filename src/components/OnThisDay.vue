<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="memories.length" class="on-this-day">
		<header class="on-this-day__header">
			<CalendarHeart :size="20" class="on-this-day__icon" />
			<h2 class="on-this-day__title">
				{{ t('social', 'On this day') }}
			</h2>
			<NcButton
				variant="tertiary"
				:title="t('social', 'Hide until tomorrow')"
				:aria-label="t('social', 'Hide until tomorrow')"
				@click="dismiss">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</header>

		<ol class="on-this-day__list">
			<li v-for="memory in memories" :key="memory.id" class="on-this-day__memory">
				<router-link class="on-this-day__link" :to="linkTo(memory)">
					<span class="on-this-day__when">{{ yearsAgo(memory) }}</span>
					<span class="on-this-day__excerpt">{{ excerpt(memory) }}</span>
				</router-link>
			</li>
		</ol>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import CalendarHeart from 'vue-material-design-icons/CalendarHeart.vue'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { mayHave } from '../services/startupHints.js'

/** how much of a post is shown before it is left to the post itself */
const EXCERPT = 140

/** where a dismissal is remembered, as the day it was dismissed on */
const DISMISSED_KEY = 'social.onThisDay.dismissed'

/**
 * What the reader wrote on this day in years gone by.
 *
 * An internal feed is a company's memory and nearly all of it is unreachable
 * the moment it scrolls past: a post is found again only by somebody who
 * remembers enough of it to search for it. This is the one place old posts
 * come back without being asked for.
 *
 * Excerpts rather than cards. Six full posts at the top of a timeline would be
 * the timeline; a line each, linking through, is a reminder that something is
 * there rather than a second feed above the first.
 *
 * Dismissal is kept in `localStorage`, as the day it happened on: it is a
 * convenience for this browser and nothing depends on it — a reader who
 * dismisses it on their phone and opens their laptop sees it again, which is
 * a better failure than a preference round-trip for a card that is gone in a
 * day anyway.
 */
export default {
	name: 'OnThisDay',

	components: {
		CalendarHeart,
		Close,
		NcButton,
	},

	data() {
		return {
			memories: [],
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/** @return {string} today, as the key a dismissal is stored under */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/** @return {boolean} whether this was already put away today */
		dismissedToday() {
			try {
				return window.localStorage.getItem(DISMISSED_KEY) === this.today()
			} catch {
				// private windows and blocked site data throw on access; a
				// card that cannot remember being dismissed still works
				return false
			}
		},

		/** Puts it away until tomorrow. */
		dismiss() {
			this.memories = []

			try {
				window.localStorage.setItem(DISMISSED_KEY, this.today())
			} catch {
				// as above: nothing here depends on it having worked
			}
		},

		/**
		 * @param {object} memory a post
		 * @return {object} where pressing it goes
		 */
		linkTo(memory) {
			return {
				name: 'single-post',
				params: { account: memory.account?.acct ?? '', id: memory.id },
			}
		},

		/**
		 * @param {object} memory a post
		 * @return {string} how long ago it was, in whole years
		 */
		yearsAgo(memory) {
			const then = new Date(memory.created_at)
			if (Number.isNaN(then.getTime())) {
				return ''
			}

			const years = Math.max(1, new Date().getFullYear() - then.getFullYear())

			return n('social', '%n year ago', '%n years ago', years)
		},

		/**
		 * The post as a line of plain text.
		 *
		 * `content` is HTML the server built, and this is the one place it is
		 * not rendered as HTML: the excerpt is a label inside a link, so it is
		 * parsed for its text and the text alone is shown. Nothing from the
		 * post can become markup here.
		 *
		 * @param {object} memory a post
		 * @return {string} its first line or so
		 */
		excerpt(memory) {
			const parsed = new DOMParser().parseFromString(memory.content ?? '', 'text/html')
			const text = (memory.spoiler_text || parsed.body.textContent || '').replace(/\s+/g, ' ').trim()

			if (text === '') {
				return t('social', '(no text)')
			}

			return text.length > EXCERPT ? text.slice(0, EXCERPT).trimEnd() + '…' : text
		},

		/** Reads the reader's own anniversaries, and shows nothing if it cannot. */
		async load() {
			if (this.dismissedToday() || !mayHave('has_memories')) {
				return
			}

			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/memories/on_this_day'))

				this.memories = Array.isArray(data) ? data : []
			} catch (error) {
				// the timeline is the page; this is an extra on top of it and
				// a failed extra is not worth an error message
				logger.debug('Could not read the memories', { error })
				this.memories = []
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../styles/layout.scss' as layout;

.on-this-day {
	margin-bottom: 14px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
}

.on-this-day__header {
	display: flex;
	align-items: center;
	gap: 6px;
}

.on-this-day__icon {
	color: var(--color-primary-element);
}

.on-this-day__title {
	flex: 1 1 auto;
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.on-this-day__list {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
}

.on-this-day__memory + .on-this-day__memory {
	border-block-start: 1px solid var(--color-border);
}

.on-this-day__link {
	display: flex;
	gap: 8px;
	padding: 6px 2px;
	color: var(--color-main-text);
	text-decoration: none;

	&:hover,
	&:focus-visible {
		text-decoration: underline;
	}
}

.on-this-day__when {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	/* the years column stays a column however long the excerpts are */
	min-width: 84px;
}

.on-this-day__excerpt {
	flex: 1 1 auto;
	font-size: 13px;
	/* one line each: the point is that the post is there, not to read it here */
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}

@include layout.below(layout.$phone) {
	/* at phone width there is no room for two columns of text */
	.on-this-day__link {
		flex-direction: column;
		gap: 0;
	}

	.on-this-day__excerpt {
		white-space: normal;
	}
}
</style>
