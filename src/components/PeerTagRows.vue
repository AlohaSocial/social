<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<ol class="peertags">
		<li v-for="tag in tags" :key="tag.name" class="peertags__row">
			<router-link class="peertags__tag" :to="{ name: 'tags', params: { tag: tag.name } }">
				<span class="peertags__name">#{{ tag.name }}</span>
				<!--
					Which servers said so, because that is the whole claim this
					row makes. A count on its own would be a number out of
					nowhere: these servers are of wildly different sizes and
					adding their figures together would rank the biggest one's
					opinion as everybody's.
				-->
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
 * Hashtags as other servers report them.
 *
 * Shared by the two places that show them — what a search found, and what is
 * busy elsewhere but not here — because they are the same row and differ only
 * in what put them on the screen.
 *
 * A tag here is followed exactly as a tag from this instance's own trending
 * list is: the button is the same one, and what fills that timeline afterwards
 * is still only what this instance federates with. Nothing about a tag being
 * busy on somebody else's server brings their posts here.
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
		 * Where a tag is busy, named rather than counted.
		 *
		 * "Busy on mastodon.social" is checkable and "busy on 1 server" is
		 * not, so the servers are named while there is room to name them and
		 * counted only once there is not.
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
