<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="posts.length > 0" class="profile-mosaic" aria-labelledby="profile-mosaic-title">
		<h2 id="profile-mosaic-title" class="profile-mosaic__title">
			<router-link :to="to" class="profile-mosaic__link">
				{{ t('social', 'Photos') }}
				<IconChevronRight class="profile-mosaic__chevron" :size="20" />
			</router-link>
		</h2>
		<ProfileMediaGrid :posts="posts" :account="account" :columns="COLUMNS" />
	</section>
</template>

<script>
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ProfileMediaGrid from './ProfileMediaGrid.vue'
import { t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import { useTimelineStore } from '../store/timeline.js'

/** squares across, as on Pixelfed */
const COLUMNS = 3
/** the most rows the preview takes before the posts start */
const ROWS = 3

/**
 * The latest pictures of an account, three rows of them above its posts,
 * opening on the Photos tab.
 *
 * The pictures are the first page of that tab, fetched ahead through the
 * timeline store: opening Photos afterwards puts the same page on screen
 * without asking the server again, and coming back to Posts finds it held.
 * An account with fewer pictures than fill a row has no preview at all.
 */
export default {
	name: 'ProfileMosaic',

	components: {
		IconChevronRight,
		ProfileMediaGrid,
	},

	props: {
		/** the handle in the route */
		account: {
			type: String,
			required: true,
		},

		/** where the heading leads: the account's Photos tab */
		to: {
			type: Object,
			required: true,
		},
	},

	setup() {
		return { COLUMNS }
	},

	computed: {
		...mapStores(useTimelineStore),

		/** @return {string} the identity the Photos tab has in the store */
		identity() {
			return JSON.stringify(['account', this.account, { media: 'image' }])
		},

		/**
		 * The newest posts with pictures, in whole rows.
		 *
		 * @return {object[]}
		 */
		posts() {
			const withMedia = this.timelineStore.getHeldTimeline(this.identity)
				.filter((post) => Array.isArray(post.media_attachments) && post.media_attachments.length > 0)
			const rows = Math.min(ROWS, Math.floor(withMedia.length / COLUMNS))

			return withMedia.slice(0, rows * COLUMNS)
		},
	},

	watch: {
		account: {
			immediate: true,
			handler(account) {
				if (account !== '') {
					this.timelineStore.prefetchTimeline({ type: 'account', account, params: { media: 'image' } })
				}
			},
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.profile-mosaic {
	margin-block-end: calc(var(--default-grid-baseline) * 4);
	animation: profile-mosaic-in .25s ease-out both;

	&__title {
		margin: 0 0 calc(var(--default-grid-baseline) * 2);
		font-size: 1rem;
		font-weight: 600;
	}

	&__link {
		display: inline-flex;
		align-items: center;
		gap: 2px;
		border-radius: var(--border-radius-element, 8px);
		color: var(--color-main-text);

		&:hover {
			text-decoration: underline;
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}

	&__chevron:dir(rtl) {
		transform: scaleX(-1);
	}
}

@keyframes profile-mosaic-in {
	from {
		opacity: 0;
	}

	to {
		opacity: 1;
	}
}

@media (prefers-reduced-motion: reduce) {
	.profile-mosaic {
		animation: none;
	}
}

@media (forced-colors: active) {
	.profile-mosaic__link:focus-visible {
		outline-color: Highlight;
	}
}
</style>
