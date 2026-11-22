<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="schedule-editor">
		<!-- the picker names itself through `ariaLabel`; a `for` here
		     would point at the wrapper rather than the input -->
		<span class="schedule-editor__label">
			{{ t('social', 'Publish at') }}
		</span>
		<NcDateTimePicker
			v-if="ready"
			class="schedule-editor__picker"
			type="datetime"
			:modelValue="modelValue"
			:min="earliest"
			:minuteStep="5"
			:clearable="false"
			:ariaLabel="t('social', 'When to publish the post')"
			@update:modelValue="$emit('update:modelValue', $event)" />
		<NcLoadingIcon v-else :size="20" />
		<!-- the server refuses anything sooner -->
		<span v-if="tooSoon" class="schedule-editor__hint" role="status">
			{{ t('social', 'Pick a time at least five minutes from now.') }}
		</span>
		<slot />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import logger from '../../services/logger.js'
import { datePickerModule, earliestSchedule, isTooSoon } from '../../utils/schedule.js'

/**
 * When a post goes out: a date picker held to the server's rules for a
 * publication time, used by the composer and Settings → Scheduled posts. The
 * caller's buttons go in the default slot.
 */
export default {
	name: 'SchedulePicker',
	components: {
		NcDateTimePicker: defineAsyncComponent({
			loader: datePickerModule,
			onError: (error) => logger.error('Could not load the date picker', { error }),
		}),

		NcLoadingIcon,
	},

	props: {
		/** the time picked */
		modelValue: {
			type: Date,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			/** whether the picker's module has arrived */
			ready: false,
		}
	},

	computed: {
		earliest() {
			return earliestSchedule()
		},

		tooSoon() {
			return isTooSoon(this.modelValue)
		},
	},

	async created() {
		try {
			await datePickerModule()
			this.ready = true
		} catch (error) {
			logger.debug('The date picker is not available', { error })
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.schedule-editor {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin: 8px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);

	&__label {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__picker {
		flex: 1 1 200px;
		min-width: 0;
	}

	&__hint {
		flex-basis: 100%;
		font-size: 12px;
		color: var(--color-error);
	}
}
</style>
