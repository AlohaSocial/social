<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<InfoCard
		v-if="show"
		:dismissKey="'profile-activity-v1:' + accountId"
		:title="t('social', 'Posting activity')"
		class="profile-highlights">
		<p v-if="sinceLabel" class="profile-highlights__since">
			{{ sinceLabel }}
		</p>

		<div v-if="hasPosts" class="profile-highlights__chart">
			<!-- decoration over a sentence: the same news is written out underneath,
			     and the chart is hidden from the accessibility tree -->
			<svg
				class="profile-highlights__spark"
				viewBox="0 0 120 32"
				preserveAspectRatio="none"
				aria-hidden="true">
				<!-- the area first, so the line sits on top of it -->
				<path class="profile-highlights__spark-area" :d="sparkArea" />
				<path class="profile-highlights__spark-line" :d="sparkLine" />
				<circle
					v-if="lastPoint"
					class="profile-highlights__spark-dot"
					:cx="lastPoint.x"
					:cy="lastPoint.y"
					r="2.5" />
			</svg>
			<p class="profile-highlights__summary">
				{{ summary }}
			</p>
		</div>
	</InfoCard>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import InfoCard from './InfoCard.vue'

/**
 * What an account is like: when it started and how the last twelve weeks
 * went.
 *
 * The server answers `available: false` for a remote account and this renders
 * nothing: this instance holds a remote account's posts only from when
 * somebody here followed it (see ProfileHighlightsService).
 */
export default {
	name: 'ProfileHighlights',
	components: { InfoCard },

	props: {
		/** whose profile this is, as the API addresses accounts */
		accountId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			available: false,
			since: 0,
			weeks: [],
		}
	},

	computed: {
		/**
		 * @return {boolean} whether there is anything worth a section: the
		 *         "here since" line, the chart, or both
		 */
		show() {
			return this.available && (this.sinceLabel !== '' || this.hasPosts)
		},

		/** @return {boolean} whether the window holds any posts at all */
		hasPosts() {
			return this.weeks.some((count) => count > 0)
		},

		/** @return {number} the busiest week, which the line is drawn against */
		busiest() {
			return this.weeks.reduce((highest, count) => Math.max(highest, count), 0)
		},

		/**
		 * The twelve weeks as points in the little chart's own 120x32 box. A week
		 * with nothing in it is a point on the floor, so the line stays continuous.
		 *
		 * @return {{x: number, y: number}[]}
		 */
		points() {
			const weeks = this.weeks
			if (weeks.length === 0) {
				return []
			}
			const top = Math.max(this.busiest, 1)
			const step = weeks.length === 1 ? 0 : 120 / (weeks.length - 1)

			return weeks.map((count, index) => ({
				x: Math.round(index * step * 10) / 10,
				// 3 and 29 rather than 0 and 32, so a full week is not drawn half outside the
				// box and an empty one is not on the very edge
				y: Math.round((29 - (count / top) * 26) * 10) / 10,
			}))
		},

		/** @return {string} the line itself */
		sparkLine() {
			return this.points
				.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x} ${point.y}`)
				.join(' ')
		},

		/** @return {string} the same line, closed along the floor, for the wash under it */
		sparkArea() {
			const points = this.points
			if (points.length === 0) {
				return ''
			}

			return `${this.sparkLine} L${points[points.length - 1].x} 32 L${points[0].x} 32 Z`
		},

		/** @return {object|null} the most recent week, which gets a dot */
		lastPoint() {
			const points = this.points

			return points.length === 0 ? null : points[points.length - 1]
		},

		/**
		 * @return {string} when this account started, as a month and a year —
		 *         a day would be more precision than the sentence wants
		 */
		sinceLabel() {
			if (!this.since) {
				return ''
			}

			const when = new Date(this.since * 1000)
			if (Number.isNaN(when.getTime())) {
				return ''
			}

			return t('social', 'Here since {date}', {
				date: when.toLocaleDateString(undefined, { year: 'numeric', month: 'long' }),
			})
		},

		/**
		 * The chart in words, which is the version most readers get.
		 *
		 * @return {string} the summary
		 */
		summary() {
			const total = this.weeks.reduce((sum, count) => sum + count, 0)

			return n(
				'social',
				'%n post in the last twelve weeks',
				'%n posts in the last twelve weeks',
				total,
			)
		},
	},

	watch: {
		accountId: {
			handler: 'load',
			immediate: true,
		},
	},

	methods: {
		t,
		n,

		async load() {
			this.available = false

			if (this.accountId === '') {
				return
			}

			try {
				const { data } = await axios.get(generateUrl(`/apps/social/api/v1/accounts/${encodeURIComponent(this.accountId)}/highlights`))

				this.available = data?.available === true
				this.since = Number(data?.since) || 0
				this.weeks = Array.isArray(data?.weeks) ? data.weeks.map((count) => Number(count) || 0) : []
			} catch (error) {
				// an extra on top of the profile is not worth an error message
				logger.debug('Could not read the profile highlights', { error })
				this.available = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.profile-highlights {
	margin-block: 8px;

	:deep(.info-card__body) {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}
}

.profile-highlights__since,
.profile-highlights__summary {
	margin: 0;
	color: var(--color-main-text);
	font-size: 13px;
}

/*
 * The twelve weeks as a line, decorative: the sentence under it carries the
 * information. The wash is the same colour at a tenth of the opacity.
 */
.profile-highlights__spark {
	inline-size: 120px;
	block-size: 32px;
	flex: 0 0 auto;
	overflow: visible;
}

.profile-highlights__spark-line {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
	vector-effect: non-scaling-stroke;
}

.profile-highlights__spark-area {
	fill: var(--color-primary-element);
	opacity: .12;
	stroke: none;
}

.profile-highlights__spark-dot {
	fill: var(--color-primary-element);
}

</style>
