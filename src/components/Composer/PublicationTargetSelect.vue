<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="publication-target" :title="hint">
		<label class="hidden-visually" for="publication-target">{{ t('social', 'Where to publish') }}</label>
		<select
			id="publication-target"
			:value="target"
			:aria-label="t('social', 'Where to publish')"
			@change="changeTarget">
			<option value="both">
				{{ t('social', 'Fediverse + Bluesky') }}
			</option>
			<option value="fediverse">
				{{ t('social', 'Fediverse only') }}
			</option>
			<option value="atproto" :disabled="!available">
				{{ t('social', 'Bluesky only') }}
			</option>
		</select>
		<a v-if="!available" :href="settingsUrl">{{ t('social', 'Connect Bluesky') }}</a>
	</div>
</template>

<script>
import { t } from '@nextcloud/l10n'

export default {
	name: 'PublicationTargetSelect',
	props: {
		target: { type: String, required: true },
		available: { type: Boolean, default: false },
		settingsUrl: { type: String, required: true },
	},

	emits: ['update:target'],
	computed: {
		hint() {
			return this.available
				? t('social', 'Choose whether this post is published to the Fediverse, Bluesky, or both.')
				: t('social', 'Connect a Bluesky account to publish there.')
		},
	},

	methods: {
		t,
		changeTarget(event) {
			this.$emit('update:target', event.target.value)
		},
	},
}
</script>

<style scoped>
.publication-target { display: inline-flex; align-items: center; gap: 6px; }

.publication-target select { max-width: 190px; }

.publication-target a { font-size:  var(--font-size-small); }
</style>
