<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="poll-editor">
		<div class="poll-editor__head">
			<span class="poll-editor__title">{{ t('social', 'Poll') }}</span>
			<NcButton
				variant="tertiary"
				class="poll-editor__remove"
				:title="t('social', 'Remove the poll')"
				:aria-label="t('social', 'Remove the poll')"
				@click.prevent="$emit('remove')">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<div v-for="(option, index) in options" :key="index" class="poll-editor__option">
			<input
				:value="option"
				type="text"
				:placeholder="t('social', 'Poll option {number}', { number: index + 1 })"
				maxlength="100"
				@input="setOption(index, valueOf($event))">
			<NcButton
				v-if="options.length > 2"
				variant="tertiary"
				:aria-label="t('social', 'Remove option')"
				@click.prevent="removeOption(index)">
				<template #icon>
					<Close :size="18" />
				</template>
			</NcButton>
		</div>
		<div class="poll-editor__settings">
			<NcButton
				v-if="options.length < maxOptions"
				variant="tertiary"
				@click.prevent="addOption">
				{{ t('social', 'Add option') }}
			</NcButton>
			<label>
				<input
					:checked="multiple"
					type="checkbox"
					@change="$emit('update:multiple', checkedOf($event))">
				{{ t('social', 'Multiple choice') }}
			</label>
			<select
				:value="expiresIn"
				:aria-label="t('social', 'Poll duration')"
				@change="$emit('update:expiresIn', Number(valueOf($event)))">
				<option :value="1800">
					{{ t('social', '30 minutes') }}
				</option>
				<option :value="3600">
					{{ t('social', '1 hour') }}
				</option>
				<option :value="21600">
					{{ t('social', '6 hours') }}
				</option>
				<option :value="86400">
					{{ t('social', '1 day') }}
				</option>
				<option :value="259200">
					{{ t('social', '3 days') }}
				</option>
				<option :value="604800">
					{{ t('social', '7 days') }}
				</option>
			</select>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { checkedOf, valueOf } from '../../utils/dom.js'

/** What Mastodon accepts, and so what a poll written here may have. */
const MIN_OPTIONS = 2
const MAX_OPTIONS = 4

/**
 * The poll being written, inside the composer.
 *
 * It holds nothing of its own: the composer keeps the poll, because the poll
 * is part of the post and has to survive being saved as a draft, restored into
 * a re-draft and sent. What this owns is the shape of one — two options at
 * least and four at most, one of the six durations — and drawing it.
 */
export default {
	name: 'PollEditor',

	components: {
		Close,
		NcButton,
	},

	props: {
		/** the options as they have been typed, in order */
		options: {
			type: Array,
			required: true,
		},

		/** whether more than one may be chosen */
		multiple: {
			type: Boolean,
			default: false,
		},

		/** how long it runs, in seconds */
		expiresIn: {
			type: Number,
			default: 86400,
		},
	},

	emits: ['update:options', 'update:multiple', 'update:expiresIn', 'remove'],

	computed: {
		/** @return {number} how many options a poll may have */
		maxOptions() {
			return MAX_OPTIONS
		},
	},

	methods: {
		t,
		checkedOf,
		valueOf,

		/**
		 * @param {number} index which option was typed in
		 * @param {string} value what it now says
		 */
		setOption(index, value) {
			const options = [...this.options]
			options[index] = value
			this.$emit('update:options', options)
		},

		addOption() {
			if (this.options.length < MAX_OPTIONS) {
				this.$emit('update:options', [...this.options, ''])
			}
		},

		/**
		 * @param {number} index the option to take out
		 */
		removeOption(index) {
			if (this.options.length > MIN_OPTIONS) {
				this.$emit('update:options', this.options.filter((one, at) => at !== index))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.poll-editor {
	margin: 8px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 4px;
	}

	&__title {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
		text-transform: uppercase;
		letter-spacing: .04em;
	}

	&__remove {
		margin-inline-start: auto;
	}

	&__option {
		display: flex;
		gap: 4px;
		margin-bottom: 4px;

		input[type='text'] {
			flex-grow: 1;
		}
	}

	/* One row: add an option, say whether more than one may be picked, say how
	   long it runs. They are three controls of one sentence and they read as
	   one line or as nothing. */
	&__settings {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;

		/* The checkbox and its words are one control, so they are laid out as
		   a row that centres them on each other. Left to itself the label is
		   an inline box and the checkbox sits with its *bottom edge* on the
		   text's baseline — which is why the words read as though they had
		   slipped a line below everything beside them. */
		label {
			display: flex;
			align-items: center;
			gap: 4px;
			margin: 0;
			/* against a wrap that would leave the checkbox on one line and the
			   words it labels on the next */
			white-space: nowrap;
		}

		/* The server gives a bare checkbox and a bare select margins of their
		   own, and they are what tips each of them off the line its
		   neighbours sit on. */
		input[type='checkbox'] {
			margin: 0;
		}

		select {
			margin: 0;
		}
	}
}
</style>
