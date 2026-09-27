<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- until the server has answered there is no state to show, and a button
	     that guesses would offer to unfollow what may not be followed -->
	<NcButton
		v-if="canFollow"
		class="hashtag-follow"
		:class="{ 'hashtag-follow--pending': loading }"
		:disabled="loading"
		:pressed="following"
		:aria-label="ariaLabel"
		@click="toggle">
		<template #icon>
			<Check v-if="following" :size="20" />
			<Pound v-else :size="20" />
		</template>
		{{ label }}
	</NcButton>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '../services/toast.js'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import Check from 'vue-material-design-icons/Check.vue'
import Pound from 'vue-material-design-icons/Pound.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'HashtagFollowButton',
	components: {
		Check,
		NcButton,
		Pound,
	},

	props: {
		/** the hashtag, without its '#', as the API names it */
		tag: {
			type: String,
			required: true,
		},

		/**
		 * The hashtags the reader follows, when whoever drew this button
		 * already knows — a list of them is one request for the page instead of
		 * one lookup per button, and twenty buttons asking separately is twenty
		 * requests before any of them can be drawn.
		 *
		 * `null` means nobody has said, and the button asks for itself.
		 */
		known: {
			type: /** @type {import('vue').PropType<string[]|null>} */ (Array),
			default: null,
		},
	},

	emits: ['changed'],
	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			following: false,
			/** whether the server has said what the state is */
			loaded: false,
			loading: false,
		}
	},

	computed: {
		/** @return {boolean} whether there is a viewer this can be answered for */
		canRequest() {
			return !this.serverData.public && this.tag !== ''
		},

		/** @return {boolean} */
		canFollow() {
			return this.canRequest && this.loaded
		},

		/** @return {string} */
		label() {
			if (this.loading) {
				return this.following
					? translate('social', 'Unfollowing …')
					: translate('social', 'Following …')
			}

			return this.following
				? translate('social', 'Following')
				: translate('social', 'Follow')
		},

		/** @return {string} the label with the hashtag it acts on */
		ariaLabel() {
			return this.following
				? translate('social', 'Unfollow the hashtag #{tag}', { tag: this.tag })
				: translate('social', 'Follow the hashtag #{tag}', { tag: this.tag })
		},
	},

	watch: {
		tag: {
			immediate: true,
			handler() {
				this.loaded = false
				this.following = false
				this.apply()
			},
		},

		// not immediate: the tag watcher above already draws the first state,
		// and two immediate watchers meant two lookups per button
		known: 'apply',
	},

	methods: {
		/**
		 * Takes the state from the list it was given, and asks the server when
		 * it was given none. Both watchers come through here, so a button that
		 * moves to another hashtag is answered the same way it was first drawn.
		 */
		apply() {
			if (this.known === null) {
				this.load()

				return
			}

			this.following = this.known.includes(this.tag)
			this.loaded = true
		},

		/** What the server says about this tag, for this viewer. */
		async load() {
			if (!this.canRequest) {
				return
			}

			const tag = this.tag
			try {
				const { data } = await axios.get(this.url())
				// the reader may have moved to another hashtag while this was
				// in flight; its answer is about the tag that is gone
				if (tag !== this.tag) {
					return
				}
				this.following = data?.following === true
				this.loaded = true
			} catch (error) {
				logger.error('Failed to read whether a hashtag is followed', { error, tag })
			}
		},

		async toggle() {
			// `disabled` is not enough on its own: a second click can land in
			// the same tick as the first, before the flag is on the button
			if (this.loading) {
				return
			}

			const follow = !this.following
			const tag = this.tag
			this.loading = true
			try {
				const { data } = await axios.post(this.url(follow ? 'follow' : 'unfollow'))
				if (tag !== this.tag) {
					return
				}
				// the state is what the server ends up holding, not what was asked for
				this.following = data?.following ?? follow
				this.$emit('changed', { tag, following: this.following })
			} catch (error) {
				logger.error('Failed to change whether a hashtag is followed', { error, tag, follow })
				showError(follow
					? translate('social', 'Could not follow the hashtag #{tag}', { tag })
					: translate('social', 'Could not unfollow the hashtag #{tag}', { tag }))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} action 'follow', 'unfollow', or none for the lookup
		 * @return {string}
		 */
		url(action = '') {
			const path = 'apps/social/api/v1/tags/' + encodeURIComponent(this.tag)

			return generateUrl(action === '' ? path : path + '/' + action)
		},
	},
}
</script>

<style scoped lang="scss">
.hashtag-follow {
	flex-shrink: 0;
}

/* waiting on the server, said in the label as well as in the dimming */
.hashtag-follow--pending {
	opacity: .7;
}
</style>
