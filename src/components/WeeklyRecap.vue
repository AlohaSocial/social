<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="loaded" class="weekly-recap">
		<ChartLine :size="20" class="weekly-recap__icon" />
		<p class="weekly-recap__text">
			{{ headline }}
			<span v-if="aside" class="weekly-recap__aside">{{ aside }}</span>
		</p>
		<NcButton
			v-if="!quiet"
			variant="tertiary"
			:disabled="sharing"
			@click="share">
			<template #icon>
				<ImageOutline :size="20" />
			</template>
			{{ t('social', 'Share as image') }}
		</NcButton>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ImageOutline from 'vue-material-design-icons/ImageOutline.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import eventBus from '../services/eventBus.js'
import { describeFile } from '../services/fileDescriptions.js'
import logger from '../services/logger.js'
import { renderRecapImage } from '../utils/recapImage.js'

/**
 * How the reader's week went, at the top of their Statistics.
 *
 * Deliberately plain: a count and the week before, no streaks, and nothing
 * that frames a quiet week as a failure.
 */
export default {
	name: 'WeeklyRecap',

	components: {
		ChartLine,
		ImageOutline,
		NcButton,
	},

	data() {
		return {
			loaded: false,
			thisWeek: 0,
			lastWeek: 0,
			sharing: false,
		}
	},

	computed: {
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
		 * The week before, for scale, only when something was posted in it.
		 *
		 * @return {string}
		 */
		aside() {
			if (this.lastWeek === 0) {
				return ''
			}

			return n('social', '%n the week before.', '%n the week before.', this.lastWeek)
		},

		/** @return {string} what the shared picture shows, for its description */
		imageDescription() {
			const summary = [this.headline, this.aside].filter(Boolean).join(' ')

			return t('social', 'Weekly recap from {instance}: {summary}', { instance: window.location.host, summary })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Draws the week as a picture and opens the composer with it attached
		 * and described, for the reader to edit and post.
		 */
		async share() {
			this.sharing = true
			try {
				const file = await renderRecapImage({
					count: this.thisWeek,
					headline: this.headline,
					aside: this.aside,
					instance: window.location.host,
				})
				if (file === null) {
					logger.debug('This browser cannot draw the weekly recap')
					return
				}

				eventBus.emit('composer-open', { files: [describeFile(file, this.imageDescription)] })
			} finally {
				this.sharing = false
			}
		},

		/** Reads the recap, and shows nothing if it cannot. */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('/apps/social/api/v1/memories/recap'))

				this.thisWeek = Number(data?.this_week) || 0
				this.lastWeek = Number(data?.last_week) || 0
				this.loaded = true
			} catch (error) {
				logger.debug('Could not read the weekly recap', { error })
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
