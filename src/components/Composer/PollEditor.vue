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
				:maxlength="maxCharacters"
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
		<p v-if="!valid && attempted" class="poll-editor__error" role="status">
			{{ t('social', 'Give the poll at least two options.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import Close from 'vue-material-design-icons/Close.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { checkedOf, valueOf } from '../../utils/dom.js'
import { POLL_MAX_CHARACTERS_PER_OPTION } from '../../utils/statusPayload.js'

/** What Mastodon accepts, and so what a poll written here may have. */
const MIN_OPTIONS = 2
const MAX_OPTIONS = 4

/**
 * The poll being written, inside the composer. The composer holds the poll's
 * state, so it survives drafts; this draws it and enforces its shape.
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

		/** whether the poll can be sent as it is */
		valid: {
			type: Boolean,
			default: true,
		},

		/** whether sending was tried with the poll incomplete */
		attempted: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:options', 'update:multiple', 'update:expiresIn', 'remove'],

	computed: {
		/** @return {number} how many options a poll may have */
		maxOptions() {
			return MAX_OPTIONS
		},

		/** @return {number} how long one option may be */
		maxCharacters() {
			return POLL_MAX_CHARACTERS_PER_OPTION
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

	/* add an option, multiple choice and duration on one line */
	&__settings {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;

		/* a row, so the checkbox centres on its words instead of sitting
		   on their baseline */
		label {
			display: flex;
			align-items: center;
			gap: 4px;
			margin: 0;
			/* keeps the checkbox and its words on one line */
			white-space: nowrap;
		}

		/* the server's margins on a bare checkbox and select push them off the line */
		input[type='checkbox'] {
			margin: 0;
		}

		select {
			margin: 0;
		}
	}

	&__error {
		margin: 4px 0 0;
		color: var(--color-error);
		font-size: 0.85rem;
	}
}
</style>
