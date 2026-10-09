<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<a
		v-if="card && card.title"
		class="post-card"
		:href="href"
		:target="inside ? null : '_blank'"
		:rel="inside ? null : 'nofollow noopener noreferrer'"
		@click="open">
		<img
			v-if="image"
			class="post-card__image"
			:src="image"
			alt=""
			loading="lazy"
			@error="image = ''">
		<div class="post-card__text">
			<span class="post-card__provider">{{ provider }}</span>
			<strong class="post-card__title">{{ card.title }}</strong>
			<span v-if="card.description" class="post-card__description">{{ card.description }}</span>
		</div>
	</a>
</template>

<script>
import { starterPackRoute } from '../utils/starterPack.js'

export default {
	name: 'PostCard',
	props: {
		card: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Card>} */ (Object),
			default: null,
		},
	},

	data() {
		return {
			// the image comes from the linked site, so it may simply be gone
			image: this.card?.image ?? '',
		}
	},

	computed: {
		/**
		 * Where the card opens here rather than on its site: a Bluesky
		 * starter pack, inside the app, where there is a router to open it.
		 *
		 * @return {object|null} the route
		 */
		inside() {
			const route = starterPackRoute(this.card?.url)

			return route !== null && this.$router ? route : null
		},

		/** @return {string} what the card links to */
		href() {
			return this.inside ? this.$router.resolve(this.inside).href : this.card.url
		},

		provider() {
			if (this.card.provider_name) {
				return this.card.provider_name
			}
			try {
				return new URL(this.card.url).host
			} catch {
				return this.card.url
			}
		},
	},

	watch: {
		card(value) {
			this.image = value?.image ?? ''
		},
	},

	methods: {
		/**
		 * @param {MouseEvent} event the click
		 */
		open(event) {
			if (this.inside && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
				event.preventDefault()
				this.$router.push(this.inside)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.post-card {
	display: flex;
	margin-top: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
	color: var(--color-main-text);
	text-decoration: none;
	background: var(--color-background-hover);

	&:hover,
	&:focus {
		border-color: var(--color-primary-element);
		text-decoration: none;
	}

	&__image {
		width: 30%;
		max-width: 200px;
		object-fit: cover;
		flex-shrink: 0;
		background: var(--color-background-dark);
	}

	&__text {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 10px 12px;
		min-width: 0;
	}

	&__provider {
		font-size: 11px;
		text-transform: uppercase;
		letter-spacing: .04em;
		color: var(--color-text-lighter);
	}

	&__title {
		font-size: 14px;
		overflow-wrap: anywhere;
	}

	&__description {
		font-size: 13px;
		color: var(--color-text-lighter);
		overflow: hidden;
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
	}
}
</style>
