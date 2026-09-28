<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- while there is too little to go on, the feed is padded with followed
	     and trending hashtags, and says so: otherwise it looks as if it had
	     learned something it has not -->
	<div v-if="thin && !dismissed" class="interests-banner">
		<p class="interests-banner__text">
			{{ t('social', 'Still learning what you like. Showing posts from hashtags you follow and what\'s trending here.') }}
			<router-link class="interests-banner__link" :to="{ name: 'settings', hash: '#interests' }">
				{{ t('social', 'Manage interests') }}
			</router-link>
		</p>
		<NcButton
			variant="tertiary"
			:aria-label="t('social', 'Dismiss')"
			:title="t('social', 'Dismiss')"
			@click="dismiss">
			<template #icon>
				<Close :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Close from 'vue-material-design-icons/Close.vue'
import { fetchInterests } from '../services/interests.js'
import logger from '../services/logger.js'

/** Where the browser remembers that the note was put away. */
export const DISMISSED_KEY = 'social:interests:learning-dismissed'

/**
 * The "still learning" note over For you.
 *
 * It asks for the state once, when the feed opens: whether learning is thin
 * changes as the reader reads, not while they look at one page of it. Put
 * away, it stays away in this browser — it is a hint, and a hint that returns
 * on every visit is a nag.
 */
export default {
	name: 'InterestsLearningBanner',

	components: {
		Close,
		NcButton,
	},

	data() {
		return {
			thin: false,
			dismissed: wasDismissed(),
		}
	},

	async mounted() {
		if (this.dismissed) {
			return
		}

		try {
			const state = await fetchInterests()
			this.thin = state?.thin === true
		} catch (error) {
			// nothing to say without an answer; the feed itself still works
			logger.debug('Could not ask whether For you is still learning', { error })
		}
	},

	methods: {
		t,

		dismiss() {
			this.dismissed = true
			try {
				window.localStorage.setItem(DISMISSED_KEY, '1')
			} catch {
				// a browser that will not store it shows the note next time
			}
		},
	},
}

/** @return {boolean} whether this browser has put the note away */
function wasDismissed() {
	try {
		return window.localStorage.getItem(DISMISSED_KEY) !== null
	} catch {
		return false
	}
}
</script>

<style scoped lang="scss">
.interests-banner {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 calc(var(--default-grid-baseline) * 2) 12px;
	padding: 4px 4px 4px 14px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&__text {
		flex: 1;
		margin: 0;
		color: var(--color-main-text);
	}

	&__link {
		font-weight: 600;
		color: var(--color-primary-element);
		white-space: nowrap;
	}
}
</style>
