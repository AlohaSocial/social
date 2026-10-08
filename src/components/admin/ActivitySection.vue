<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="activity">
			<div class="activity__figure">
				<span class="activity__number">{{ day.posts }}</span>
				<span class="activity__label">{{ n('social', 'post in the last day', 'posts in the last day', day.posts) }}</span>
				<span class="activity__sub">
					{{ n('social', 'by %n account', 'by %n accounts', day.authors) }}
				</span>
			</div>
			<div class="activity__figure">
				<span class="activity__number">{{ week.posts }}</span>
				<span class="activity__label">{{ n('social', 'post in the last week', 'posts in the last week', week.posts) }}</span>
				<span class="activity__sub">
					{{ n('social', 'by %n account', 'by %n accounts', week.authors) }}
				</span>
			</div>
		</div>
		<p v-if="week.posts === 0" class="social-admin__hint">
			{{ t('social', 'Nobody here has posted this week. A server nobody posts from is also a server nobody follows back, so this is the number to move first.') }}
		</p>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Two numbers, and what they are counting.
 *
 * An administrator's first question about a social server is whether anybody
 * is using it, and this page answered every other question first. Deliberately
 * two figures rather than a chart: a chart of an instance with four accounts
 * is noise, and the shape of the week is not a decision anybody takes.
 */
export default {
	name: 'ActivitySection',

	components: {
	},

	props: {
		/** posts and authors in the last day and the last week */
		activity: {
			type: Object,
			required: true,
		},
	},

	computed: {
		day() {
			return this.activity.day ?? { posts: 0, authors: 0 }
		},

		week() {
			return this.activity.week ?? { posts: 0, authors: 0 }
		},
	},

	methods: {
		t,
		n,
	},
}
</script>

<style lang="scss" scoped>
.activity {
	display: flex;
	gap: 32px;
	flex-wrap: wrap;
}

.activity__figure {
	display: flex;
	flex-direction: column;
}

.activity__number {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.1;
}

.activity__label {
	font-weight: bold;
}

.activity__sub {
	color: var(--color-text-maxcontrast);
}
</style>
