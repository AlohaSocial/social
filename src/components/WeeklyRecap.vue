<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="show" class="weekly-recap">
		<ChartLine :size="20" class="weekly-recap__icon" />
		<p class="weekly-recap__text">
			{{ headline }}
			<span v-if="aside" class="weekly-recap__aside">{{ aside }}</span>
		</p>
		<NcButton
			v-if="quiet"
			variant="tertiary"
			:to="{ name: 'timeline', params: { type: 'timeline' } }">
			{{ t('social', 'See what people on your Nextcloud shared') }}
		</NcButton>
		<NcButton
			variant="tertiary"
			:title="t('social', 'Hide this week')"
			:aria-label="t('social', 'Hide this week')"
			@click="dismiss">
			<template #icon>
				<Close :size="20" />
			</template>
		</NcButton>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { mayHave } from '../services/startupHints.js'

/** where a dismissal is remembered, as the week it was dismissed in */
const DISMISSED_KEY = 'social.weeklyRecap.dismissed'

/** a week, in milliseconds, counted the way the server counts it */
const WEEK_MS = 7 * 24 * 3600 * 1000

/**
 * How the reader's week went, if they asked to be told.
 *
 * Off until it is switched on in the settings. A card that turns up in
 * somebody's feed to comment on how much they have posted is something to ask
 * for, not something to be given, and this one is deliberately dull about it:
 * a count, the week before for comparison, and nothing that frames a quiet
 * week as a failure.
 *
 * There is no streak. A run of consecutive weeks is a thing that can be lost,
 * and a feed that tells somebody they have broken one is asking them for posts
 * rather than offering them anything. A quiet week gets a way into the local
 * timeline instead — what other people have been writing is the useful answer
 * to "you have not posted", and it asks nothing.
 */
export default {
	name: 'WeeklyRecap',

	components: {
		ChartLine,
		Close,
		NcButton,
	},

	data() {
		return {
			enabled: false,
			thisWeek: 0,
			lastWeek: 0,
			dismissed: false,
		}
	},

	computed: {
		/** @return {boolean} whether there is a card at all */
		show() {
			return this.enabled && !this.dismissed
		},

		/** @return {boolean} whether nothing was posted this week */
		quiet() {
			return this.thisWeek === 0
		},

		/** @return {string} the week, in one sentence */
		headline() {
			if (this.quiet) {
				return t('social', 'You have not posted this week.')
			}

			return n('social', 'You posted %n time this week.', 'You posted %n times this week.', this.thisWeek)
		},

		/**
		 * The week before, for scale. Only when there was one — "0 last week"
		 * next to "0 this week" is a sentence about nothing.
		 *
		 * @return {string}
		 */
		aside() {
			if (this.lastWeek === 0) {
				return ''
			}

			return n('social', '%n the week before.', '%n the week before.', this.lastWeek)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/** @return {string} which week it is, as the key a dismissal is stored under */
		thisWeekKey() {
			return String(Math.floor(Date.now() / WEEK_MS))
		},

		/** @return {boolean} whether this week's card was already put away */
		dismissedThisWeek() {
			try {
				return window.localStorage.getItem(DISMISSED_KEY) === this.thisWeekKey()
			} catch {
				// private windows and blocked site data throw on access
				return false
			}
		},

		/** Puts it away until next week. */
		dismiss() {
			this.dismissed = true

			try {
				window.localStorage.setItem(DISMISSED_KEY, this.thisWeekKey())
			} catch {
				// nothing here depends on it having worked
			}
		},

		/** Reads the recap, and shows nothing if it cannot. */
		async load() {
			if (this.dismissedThisWeek()) {
				this.dismissed = true
				return
			}
			if (!mayHave('recap_enabled')) {
				this.enabled = false
				return
			}

			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/memories/recap'))

				this.enabled = data?.enabled === true
				this.thisWeek = Number(data?.this_week) || 0
				this.lastWeek = Number(data?.last_week) || 0
			} catch (error) {
				logger.debug('Could not read the weekly recap', { error })
				this.enabled = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
@use '../styles/layout.scss' as layout;

.weekly-recap {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 14px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
}

.weekly-recap__icon {
	flex: 0 0 auto;
	color: var(--color-primary-element);
}

.weekly-recap__text {
	flex: 1 1 auto;
	margin: 0;
	font-size: 14px;
}

.weekly-recap__aside {
	color: var(--color-text-maxcontrast);
}

@include layout.below(layout.$phone) {
	.weekly-recap {
		flex-wrap: wrap;
	}

	.weekly-recap__text {
		flex-basis: 100%;
	}
}
</style>
