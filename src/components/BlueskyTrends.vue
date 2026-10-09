<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="trends.length" class="bluesky-trends">
		<h3 class="bluesky-trends__title">
			{{ t('social', 'Trending on Bluesky') }}
		</h3>
		<ul class="bluesky-trends__list">
			<li v-for="trend in trends" :key="trend.topic">
				<router-link class="bluesky-trends__topic" :to="routeOf(trend)">
					<IconBluesky v-if="trend.feed" :size="16" />
					{{ trend.label }}
				</router-link>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import IconBluesky from 'vue-material-design-icons/ButterflyOutline.vue'
import { routeFor } from '../services/blueskyFeeds.js'
import logger from '../services/logger.js'

/**
 * What is trending on Bluesky, beside what is trending here: a topic that
 * is a custom feed opens as one, the others as a search.
 */
export default {
	name: 'BlueskyTrends',

	components: { IconBluesky },

	data() {
		return {
			trends: [],
		}
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/trends'))
			this.trends = Array.isArray(data?.trends) ? data.trends : []
		} catch (error) {
			logger.info('Bluesky trends not loaded', { error })
		}
	},

	methods: {
		t,

		/**
		 * @param {{feed: string, search: string}} trend one topic
		 * @return {object} where it opens
		 */
		routeOf(trend) {
			return (trend.feed && routeFor(trend.feed)) || { name: 'search', params: { term: trend.search } }
		},
	},
}
</script>

<style scoped lang="scss">
.bluesky-trends {
	margin-top: 24px;

	&__title {
		font-size: 1em;
		font-weight: bold;
		margin: 0 0 8px;
	}

	&__list {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__topic {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 4px 12px;
		border-radius: var(--border-radius-pill);
		background: var(--color-background-hover);

		&:hover,
		&:focus-visible {
			background: var(--color-primary-element-light);
		}
	}
}
</style>
