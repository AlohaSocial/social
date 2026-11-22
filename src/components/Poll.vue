<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="poll">
		<template v-if="showResults">
			<div v-for="(option, index) in poll.options" :key="index" class="poll__result">
				<span class="poll__result-header">
					<strong>{{ percentage(option) }}%</strong>
					<span class="poll__result-title">{{ option.title }}</span>
					<Check v-if="poll.own_votes && poll.own_votes.includes(index)" :size="16" />
				</span>
				<span class="poll__result-bar">
					<span
						class="poll__result-fill"
						:style="{ width: revealed ? percentage(option) + '%' : 0, transitionDelay: index * 80 + 'ms' }" />
				</span>
			</div>
		</template>
		<template v-else>
			<label v-for="(option, index) in poll.options" :key="index" class="poll__option">
				<input
					v-model="selected"
					:type="poll.multiple ? 'checkbox' : 'radio'"
					:value="index"
					:name="groupName">
				{{ option.title }}
			</label>
			<!-- secondary until there is a choice: a disabled primary button is
			     unreadable in the dark theme -->
			<NcButton
				class="poll__vote"
				:disabled="selectedIndices.length === 0 || voting"
				:variant="selectedIndices.length === 0 ? 'secondary' : 'primary'"
				@click="vote">
				{{ t('social', 'Vote') }}
			</NcButton>
		</template>
		<div class="poll__footer">
			{{ n('social', '%n vote', '%n votes', poll.votes_count) }}
			<template v-if="poll.expired">
				· {{ t('social', 'Closed') }}
			</template>
			<template v-else-if="poll.expires_at">
				· {{ t('social', 'Ends {date}', { date: expiry }) }}
			</template>
		</div>
	</div>
</template>

<script>
import { fromNow } from '../utils/relativeTime.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import { errorOf } from '../utils/serverError.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import Check from 'vue-material-design-icons/Check.vue'
import logger from '../services/logger.js'

/** what makes each poll on the page its own radio group */
let nextGroup = 0

export default {
	name: 'Poll',
	components: {
		NcButton,
		Check,
	},

	props: {
		poll: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Poll>} */ (Object),
			required: true,
		},
	},

	emits: ['update:poll'],
	data() {
		return {
			// unique per poll: the inputs are not in a <form>, so a shared name
			// would make every poll on the page one radio group
			groupName: `poll-option-${nextGroup++}`,
			/** @type {number|number[]|null} the option picked, or all of them on a multiple-choice poll */
			selected: this.poll.multiple ? [] : null,
			voting: false,
			// the bars grow from nothing; they need one frame at zero width
			// before the real width is applied, or there is nothing to animate
			revealed: false,
		}
	},

	computed: {
		/** @return {boolean} */
		showResults() {
			return this.poll.voted || this.poll.expired
		},

		/** @return {number[]} */
		selectedIndices() {
			if (Array.isArray(this.selected)) {
				return this.selected
			}
			return this.selected === null ? [] : [this.selected]
		},

		/** @return {string} */
		expiry() {
			return fromNow(this.poll.expires_at)
		},
	},

	watch: {
		showResults: {
			handler(shown) {
				if (shown) {
					this.revealed = false
					window.requestAnimationFrame(() => {
						this.revealed = true
					})
				}
			},

			immediate: true,
		},
	},

	methods: {
		/**
		 * @param {object} option a poll option
		 * @return {number} its share of the votes
		 */
		percentage(option) {
			if (!this.poll.votes_count) {
				return 0
			}
			return Math.round((option.votes_count / this.poll.votes_count) * 100)
		},

		async vote() {
			this.voting = true
			try {
				const response = await axios.post(
					generateUrl(`apps/social/api/v1/polls/${this.poll.id}/votes`),
					{ choices: this.selectedIndices },
				)
				this.$emit('update:poll', response.data)
			} catch (error) {
				logger.error('Failed to vote', { error })
				showError(errorOf(error, t('social', 'Failed to vote')))
			} finally {
				this.voting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.poll {
	margin-top: 8px;

	&__option {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 4px 0;
		cursor: pointer;

		/* Nextcloud's input rules give it a field's height and margins */
		input {
			flex: 0 0 auto;
			width: 16px;
			height: 16px;
			min-height: 0;
			margin: 0;
			accent-color: var(--color-primary-element);
			cursor: pointer;
		}
	}

	&__vote {
		margin-top: 8px;
	}

	&__result {
		margin-bottom: 6px;

		&-header {
			display: flex;
			gap: 8px;
			align-items: center;
		}

		&-bar {
			display: block;
			height: 6px;
			border-radius: 3px;
			background-color: var(--color-background-dark);
			overflow: hidden;
		}

		&-fill {
			display: block;
			height: 100%;
			background-color: var(--color-primary-element);
			transition: width .55s cubic-bezier(.22, 1, .36, 1);
		}
	}

	&__footer {
		margin-top: 6px;
		color: var(--color-text-maxcontrast);
	}
}

@media (prefers-reduced-motion: reduce) {
	.poll__result-fill {
		transition: none;
	}
}
</style>
