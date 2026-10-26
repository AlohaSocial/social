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
			<!-- The chart is decoration over a sentence, not instead of one:
			     a row of twelve bars is unreadable to anybody not looking at
			     it, so the same news is written out underneath and the bars
			     themselves are hidden from the accessibility tree. -->
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
				{{ summary }}<template v-if="rhythm">
					· {{ rhythm }}
				</template>
			</p>
		</div>

		<!-- The hashtags used to be drawn again here, under the chart, without
		     their counts. They are the same tags `FeaturedTags` draws above the
		     bio with counts, so the page said the same thing twice and the
		     second time said less. -->
	</InfoCard>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import InfoCard from './InfoCard.vue'

/**
 * What an account is like, over and above how much of it there is.
 *
 * A profile was a name, a picture and three counts, and a count says how much
 * of something there is and nothing about what the account does with it: "412
 * posts" reads the same for somebody who wrote them all last week and somebody
 * who has been here since 2019. This says when they started, how the last
 * twelve weeks went, and what they keep writing about.
 *
 * The server answers `available: false` for a remote account, and this then
 * renders nothing at all. That is deliberate and not a failure: this instance
 * holds a remote account's posts only from whenever somebody here started
 * following them, so a chart of that would show a quiet year for an account
 * that was busy — see ProfileHighlightsService.
 */
/** the recent weeks the rhythm is read off, against the weeks before them */
const RECENT_WEEKS = 4

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
		 * @return {boolean} whether there is anything worth a section. An
		 *         account that has posted nothing and tagged nothing gets the
		 *         "here since" line alone if it has one, and otherwise nothing
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
		 * The twelve weeks as points in the little chart's own 120x32 box.
		 *
		 * A line rather than the twelve bars it was: the bars were an honest
		 * chart and a joyless one, and what somebody reads off a profile is a
		 * shape -- busy then quiet, steady, just arrived -- which a line gives
		 * and twelve separate rectangles do not. A week with nothing in it is
		 * a point on the floor rather than a gap, so the line stays continuous.
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
				// 3 and 29 rather than 0 and 32: a full week would otherwise be
				// drawn half outside the box, and an empty one would sit on the
				// very edge where the line has no room to be a line
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
		 * Whether the account has been here long enough for "usual" to mean
		 * anything: at least twice the recent window, so the weeks before it
		 * are weeks the account existed. A twenty-minute-old account with
		 * one post was "busier than usual" against eleven empty weeks it was
		 * not there for.
		 *
		 * @return {boolean}
		 */
		hasAUsual() {
			return this.since > 0 && (Date.now() / 1000 - this.since) >= 2 * RECENT_WEEKS * 7 * 86400
		},

		/**
		 * One sentence about the shape of it.
		 *
		 * The chart says how much; this says what kind. Which of the three it
		 * is comes from comparing the last third of the weeks with the rest,
		 * so an account that has stopped posting says so rather than showing a
		 * line that trails off and leaving the reader to notice. Only once
		 * there is a "usual": an account old enough, with posts in the weeks
		 * before the recent ones.
		 *
		 * @return {string} the sentence, or '' when there is nothing to say
		 */
		rhythm() {
			const weeks = this.weeks
			if (!this.hasPosts || weeks.length < 2 * RECENT_WEEKS || !this.hasAUsual) {
				return ''
			}

			const recent = weeks.slice(-RECENT_WEEKS)
			const before = weeks.slice(0, -RECENT_WEEKS)
			const perWeek = (list) => list.reduce((total, count) => total + count, 0) / Math.max(list.length, 1)
			const now = perWeek(recent)
			const then = perWeek(before)

			// nothing before the recent weeks is nothing to compare them with
			if (then === 0) {
				return ''
			}
			if (now === 0) {
				return t('social', 'Quiet lately')
			}
			if (now > then * 1.6) {
				return t('social', 'Busier than usual')
			}
			if (now < then * 0.5) {
				return t('social', 'Slowing down')
			}

			return t('social', 'Posting steadily')
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
				// a profile is readable without this; it is an extra, and a
				// failed extra should not be an error message on somebody's page
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

/**
 * The twelve weeks as a line.
 *
 * Small, quiet and decorative: the sentence under it is what carries the
 * information, and this is the shape of it. The wash under the line is the
 * same colour at a tenth of the opacity, so the two read as one mark rather
 * than as a chart with a fill.
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
