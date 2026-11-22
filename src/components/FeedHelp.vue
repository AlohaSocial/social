<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcPopover v-if="description !== ''" class="feed-help" @show="onShow">
		<template #trigger>
			<NcButton
				class="feed-help__trigger"
				variant="tertiary"
				:aria-label="t('social', 'About this feed')"
				:title="t('social', 'About this feed')">
				<template #icon>
					<IconHelpCircleOutline :size="18" />
				</template>
			</NcButton>
		</template>
		<div class="feed-help__body">
			<p>{{ description }}</p>
			<p v-if="scope === 'interests' && (learning || underfilled)">
				{{ t('social', 'It is still learning, so for now it shows hashtags you follow and what is popular here.') }}
			</p>
			<RouterLink v-if="scope === 'interests'" class="feed-help__link" :to="{ name: 'settings', hash: '#interests' }">
				{{ t('social', 'For you settings') }}
			</RouterLink>
		</div>
	</NcPopover>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import IconHelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import { fetchInterests } from '../services/interests.js'
import logger from '../services/logger.js'

/**
 * A (?) beside the feed tabs that says in one sentence what the chosen feed
 * shows. For you also says when it is still learning.
 */
export default {
	name: 'FeedHelp',

	components: {
		IconHelpCircleOutline,
		NcButton,
		NcPopover,
	},

	props: {
		/** `home`, `interests`, `timeline` or `federated` */
		scope: { type: String, required: true },
		/** whether the ranked feed had fewer posts than it asked for */
		underfilled: { type: Boolean, default: false },
	},

	data() {
		return {
			learning: false,
			asked: false,
		}
	},

	computed: {
		/** @return {string} what the feed shows, '' for a feed this has nothing to say about */
		description() {
			return {
				home: t('social', 'Posts from the people and hashtags you follow, newest first.'),
				interests: t('social', 'Posts we think you will like, based on the hashtags of posts you read, like and save. It can include people you do not follow. What it learns stays on this server.'),
				timeline: t('social', 'Public posts from everybody on this server, newest first.'),
				federated: t('social', 'Public posts from other servers that reach this one, newest first.'),
			}[this.scope] ?? ''
		},
	},

	methods: {
		t,

		/** Asks once, when first opened on For you, whether it is still learning. */
		async onShow() {
			if (this.scope !== 'interests' || this.asked) {
				return
			}
			this.asked = true
			try {
				const state = await fetchInterests()
				this.learning = state?.thin === true
			} catch (error) {
				logger.debug('Could not ask whether For you is still learning', { error })
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* a quiet hint beside the switcher, not a control that competes with it:
   faded to about 3:1 against the page, the least a control may have, and
   whole again under the pointer or the keyboard. Nested to outweigh
   NcButton's own tertiary colour */
.feed-help .feed-help__trigger {
	color: var(--color-text-maxcontrast);
	opacity: 0.75;

	&:hover,
	&:focus-visible {
		color: var(--color-main-text);
		opacity: 1;
	}
}

.feed-help__body {
	max-width: 320px;
	padding: 12px 14px;

	p {
		margin: 0 0 8px;
	}
}

.feed-help__link {
	font-weight: 600;
	color: var(--color-primary-element);
}
</style>
