<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ol class="peertags">
		<li v-for="tag in tags" :key="tag.name" class="peertags__row">
			<router-link class="peertags__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
				<span class="peertags__name">#{{ tag.name }}</span>
				<!-- which servers said so: they differ wildly in size, so their counts
				     are not added up -->
				<span class="peertags__where">{{ whereText(tag) }}</span>
			</router-link>
			<HashtagFollowButton
				class="peertags__follow"
				:tag="tag.name"
				:known="followed"
				@changed="$emit('changed', $event)" />
		</li>
	</ol>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import HashtagFollowButton from './HashtagFollowButton.vue'

/** How many servers are named before the rest become "and N more". */
const NAMED = 2

/**
 * Hashtags as other servers report them, for search results and for what is
 * busy elsewhere. Following one only brings in what this instance already
 * federates with.
 */
export default {
	name: 'PeerTagRows',

	components: {
		HashtagFollowButton,
	},

	props: {
		/** `PeerTag` entities as the server serialises them */
		tags: {
			type: /** @type {import('vue').PropType<object[]>} */ (Array),
			required: true,
		},

		/** the hashtags this reader follows, or null while that is unknown */
		followed: {
			type: /** @type {import('vue').PropType<string[]|null>} */ (Array),
			default: null,
		},
	},

	emits: ['changed'],

	methods: {
		t,
		n,

		/**
		 * Where a tag is busy: servers are named while there is room, and counted
		 * once there is not.
		 *
		 * @param {object} tag one `PeerTag`
		 * @return {string}
		 */
		whereText(tag) {
			const servers = Array.isArray(tag.servers) ? tag.servers : []
			const elsewhere = tag.local ? servers.slice(1) : servers

			if (elsewhere.length === 0) {
				return t('social', 'Used here')
			}

			const named = elsewhere.slice(0, NAMED).join(', ')
			const rest = elsewhere.length - NAMED
			const where = rest > 0
				? n('social', '{servers} and %n other server', '{servers} and %n other servers', rest, { servers: named })
				: named

			return tag.local
				? t('social', 'Busy on {servers}, and used here', { servers: where })
				: t('social', 'Busy on {servers}', { servers: where })
		},
	},
}
</script>

<style scoped lang="scss">
.peertags {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.peertags__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-inline-end: calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-large);

	&:hover,
	&:focus-within {
		background-color: var(--color-background-hover);
	}
}

.peertags__tag {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 200px;
	min-width: 0;
	padding: calc(var(--default-grid-baseline) * 2);
	color: inherit;
}

.peertags__name {
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.peertags__where {
	font-size: var(--font-size-small, 0.85em);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.peertags__follow {
	flex: 0 0 auto;
}
</style>
