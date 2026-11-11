<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="skeleton"
		:class="[
			`skeleton--${variant}`,
			{ 'skeleton--standalone': standalone, 'skeleton--video': variant === 'grid' && shape === 'video' },
		]"
		aria-hidden="true">
		<span v-if="standalone || variant === 'page'" class="skeleton__heading skeleton__shape" />

		<ul v-if="variant === 'rows'" class="skeleton__rows">
			<li v-for="index in shown" :key="index" class="skeleton__row">
				<span class="skeleton__avatar skeleton__shape" />
				<div class="skeleton__body">
					<div class="skeleton__header">
						<span class="skeleton__name skeleton__shape" />
						<span class="skeleton__time skeleton__shape" />
					</div>
					<span class="skeleton__line skeleton__shape" />
					<span class="skeleton__line skeleton__shape" />
					<span class="skeleton__line skeleton__line--short skeleton__shape" />
				</div>
			</li>
		</ul>

		<ul v-else-if="variant === 'grid'" class="skeleton__grid">
			<li v-for="index in shown" :key="index" class="skeleton__cell">
				<template v-if="shape === 'video'">
					<span class="skeleton__thumb skeleton__shape" />
					<span class="skeleton__line skeleton__shape" />
					<span class="skeleton__line skeleton__line--short skeleton__shape" />
				</template>
				<span v-else class="skeleton__tile skeleton__shape" />
			</li>
		</ul>

		<div v-else class="skeleton__page">
			<span v-for="index in shown" :key="index" class="skeleton__block skeleton__shape" />
		</div>
	</div>
</template>

<script>
/** How many of each a placeholder draws unless told: about a screenful. */
const DEFAULT_COUNTS = {
	rows: 3,
	grid: 12,
	video: 8,
	page: 3,
}

/**
 * The shape of what is loading, instead of a spinner on an empty page, so
 * the content settles into the places it was drawn rather than appearing from
 * nothing.
 *
 * - `rows`: the flat rows of a timeline, with the same padding, hairlines
 *   and face as TimelineEntry, so a post lands where its row was.
 * - `grid`: the tiles of a photo grid (`square`, as ProfileMediaGrid draws
 *   them) or the cards of the Videos page (`video`, 16:9, as VideoBrowse
 *   draws them).
 * - `page`: a heading and a few blocks, for a page whose shape is its own.
 *
 * `standalone` is the placeholder for a whole page (App.vue, while a page's
 * code loads): it takes the column a page of that kind takes, and starts
 * with a heading where the page's title will be.
 */
export default {
	name: 'TimelineSkeleton',
	props: {
		variant: {
			type: String,
			default: 'rows',
			validator: (value) => ['rows', 'grid', 'page'].includes(String(value)),
		},

		/** the grid's cells: `square` tiles or `video` cards */
		shape: {
			type: String,
			default: 'square',
			validator: (value) => ['square', 'video'].includes(String(value)),
		},

		/** how many rows, cells or blocks; the variant's own number when null */
		count: {
			type: Number,
			default: null,
		},

		standalone: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		/** @return {number} */
		shown() {
			if (this.count !== null) {
				return Math.max(0, this.count)
			}

			return DEFAULT_COUNTS[this.variant === 'grid' && this.shape === 'video' ? 'video' : this.variant]
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

@keyframes skeleton-sheen {
	0% { background-position: 200% 0; }
	100% { background-position: -200% 0; }
}

.skeleton {
	ul {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__shape {
		display: block;
		border-radius: var(--border-radius, 3px);
		/* the sheen travels across a gradient wider than the element */
		background: linear-gradient(
			90deg,
			var(--color-background-dark) 25%,
			var(--color-background-hover) 37%,
			var(--color-background-dark) 63%
		);
		background-size: 400% 100%;
		animation: skeleton-sheen 1.4s ease-in-out infinite;
	}

	&__line {
		block-size: 12px;
		// a 12px bar in the middle of a 23px line, the height of a line of
		// a post (15px at 1.55)
		margin-block: 6px 5px;

		&--short {
			inline-size: 65%;
		}
	}

	/* a whole page: the column its page takes, and a heading */
	&--standalone {
		box-sizing: border-box;
		max-width: var(--social-column);
		margin-inline: auto;
		padding-inline: var(--social-column-gutter);
	}

	&--standalone.skeleton--rows {
		--social-column: 616px;
	}

	&--standalone.skeleton--video {
		--social-column: 1440px;
	}

	&__heading {
		inline-size: min(240px, 40%);
		block-size: 22px;
		margin-block: 12px 24px;
	}
}

/*
 * One row, measured off TimelineEntry's flat row: 14px above and 13px below
 * the content, 8px at the sides, a 13px gap with a hairline in its middle,
 * a 40px face 2px down and 10px from the words, and a name line of 20px.
 */
.skeleton__row {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: 10px;
	margin-bottom: 13px;
	padding-block: 14px 13px;
	padding-inline: 8px;

	&:not(:last-child)::after {
		content: '';
		position: absolute;
		inset-inline: 8px;
		inset-block-end: -7px;
		block-size: 1px;
		background: var(--color-border);
	}

	&:last-child {
		margin-bottom: 0;
	}

	.skeleton__avatar {
		flex-shrink: 0;
		inline-size: 40px;
		block-size: 40px;
		margin-top: 2px;
		border-radius: 50%;
	}

	.skeleton__body {
		flex-grow: 1;
		min-width: 0;
	}

	.skeleton__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		block-size: 20px;
		margin-bottom: 2px;
	}

	.skeleton__name {
		inline-size: 32%;
		block-size: 12px;
	}

	.skeleton__time {
		inline-size: 28px;
		block-size: 10px;
	}

	/* a phone: the face sits in the row's corner, 36px, with the name
	   beside it; the words run the full width beneath */
	@include layout.below(layout.$phone) {
		display: block;
		padding-block-start: 16px;

		.skeleton__avatar {
			position: absolute;
			inset-block-start: 16px;
			inset-inline-start: 8px;
			inline-size: 36px;
			block-size: 36px;
			margin-top: 0;
		}

		.skeleton__header {
			block-size: 36px;
			padding-inline-start: 46px;
		}
	}
}

/* the photo grid's squares: ProfileMediaGrid's columns and 2px gaps */
.skeleton__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 2px;
}

.skeleton__tile {
	aspect-ratio: 1 / 1;
	border-radius: 0;
}

/* the Videos page's cards: VideoBrowse's columns, gaps, 16:9 picture and
   two lines of title */
.skeleton--video .skeleton__grid {
	grid-template-columns: repeat(var(--video-columns, 4), minmax(0, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 10) calc(var(--default-grid-baseline, 4px) * 4);
}

/* on its own the page has not said yet how many columns fit */
.skeleton--standalone.skeleton--video .skeleton__grid {
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
}

.skeleton__thumb {
	aspect-ratio: 16 / 9;
	border-radius: var(--border-radius-large, 8px);
}

.skeleton__thumb + .skeleton__line {
	margin-block-start: calc(var(--default-grid-baseline, 4px) * 3);
}

/* a page of its own shape: a few quiet blocks under the heading */
.skeleton__page {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.skeleton__block {
	block-size: 96px;
	border-radius: var(--border-radius-large, 8px);

	&:first-child {
		block-size: 44px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.skeleton__shape {
		animation: none;
		background: var(--color-background-dark);
	}
}
</style>
