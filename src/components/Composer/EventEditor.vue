<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="event-editor" :aria-label="editing ? t('social', 'Edit event') : t('social', 'Create an event')">
		<h3>{{ editing ? t('social', 'Edit event') : t('social', 'Create an event') }}</h3>
		<label>
			{{ t('social', 'Event title') }}
			<input
				:value="name"
				type="text"
				maxlength="300"
				required
				:placeholder="t('social', 'What is happening?')"
				@input="$emit('update:name', valueOf($event))">
		</label>
		<div class="event-editor__times">
			<label>
				{{ t('social', 'Starts') }}
				<input
					:value="start"
					type="datetime-local"
					required
					@input="$emit('update:start', valueOf($event))">
			</label>
			<label>
				{{ t('social', 'Ends (optional)') }}
				<input
					:value="end"
					type="datetime-local"
					@input="$emit('update:end', valueOf($event))">
			</label>
		</div>
		<label>
			{{ t('social', 'Event location (optional)') }}
			<input
				:value="location"
				type="text"
				maxlength="500"
				:placeholder="t('social', 'Venue or meeting place')"
				@input="$emit('update:location', valueOf($event))">
		</label>
		<p class="event-editor__hint">
			{{ t('social', 'Your event is shared with the audience selected below. Times use your local timezone.') }}
		</p>
		<p v-if="reachesBluesky" class="event-editor__hint">
			{{ t('social', 'The event details are shared with the Fediverse. Bluesky receives the post text, without the event dates or location.') }}
		</p>
		<p v-if="!valid" class="event-editor__error" role="status">
			{{ t('social', 'Add a title and start time; the end must be after the start.') }}
		</p>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { valueOf } from '../../utils/dom.js'

/** The event fields, with values and validation owned by the composer. */
export default {
	name: 'EventEditor',

	props: {
		editing: { type: Boolean, default: false },
		name: { type: String, default: '' },
		start: { type: String, default: '' },
		end: { type: String, default: '' },
		location: { type: String, default: '' },
		valid: { type: Boolean, default: true },
		reachesBluesky: { type: Boolean, default: false },
	},

	emits: ['update:name', 'update:start', 'update:end', 'update:location'],

	setup() {
		return { t, valueOf }
	},
}
</script>

<style lang="scss" scoped>
.event-editor {
	display: grid;
	gap: 10px;
	margin-block: 8px 12px;
	padding: 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);

	h3 {
		margin: 0;
		font-size: 1rem;
	}

	label {
		display: grid;
		gap: 4px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	input {
		width: 100%;
		min-width: 0;
	}
}

.event-editor__times {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 10px;
}

.event-editor__hint,
.event-editor__error {
	margin: 0;
	font-size: 0.85rem;
}

.event-editor__hint {
	color: var(--color-text-maxcontrast);
}

.event-editor__error {
	color: var(--color-error);
}

@media (max-width: 600px) {
	.event-editor__times {
		grid-template-columns: 1fr;
	}
}
</style>
