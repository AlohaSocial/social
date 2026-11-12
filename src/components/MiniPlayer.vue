<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<aside class="mini-player" :aria-label="t('social', 'Video playing')">
		<VideoPlayer
			class="mini-player__video"
			mini
			:status="status"
			:startAt="startAt"
			:autoplay="autoplay" />
		<div class="mini-player__bar">
			<span class="mini-player__title" :title="title">{{ title }}</span>
			<NcButton
				variant="tertiary"
				:aria-label="t('social', 'Back to the video')"
				:title="t('social', 'Back to the video')"
				@click="backToVideo">
				<template #icon>
					<ArrowExpand :size="20" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('social', 'Close the player')"
				:title="t('social', 'Close the player')"
				@click="close">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
	</aside>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowExpand from 'vue-material-design-icons/ArrowExpand.vue'
import Close from 'vue-material-design-icons/Close.vue'
import VideoPlayer from './VideoPlayer.vue'
import { closeMiniPlayer, miniPlayer } from '../services/miniPlayer.js'

/**
 * The small player in the corner: a video the reader left its page while it
 * played, going on where it was.
 *
 * Drawn by `App.vue` for as long as `services/miniPlayer.js` holds a video,
 * keyed by the post, so it outlives every navigation inside the app. It plays
 * on from where the watch page was and nothing else: it never starts a video
 * of its own. "Back to the video" opens the video's page, which takes the
 * video back from here; closing stops it.
 */
export default {
	name: 'MiniPlayer',

	components: {
		ArrowExpand,
		Close,
		NcButton,
		VideoPlayer,
	},

	data() {
		return {
			// read once: the player goes on from here and then says where it is
			startAt: miniPlayer.position,
			autoplay: miniPlayer.playing,
		}
	},

	computed: {
		/** @return {object} the video post */
		status() {
			return miniPlayer.status
		},

		/** @return {string} what it is called, for the bar */
		title() {
			return this.status?.video_card?.title || this.status?.video?.title || t('social', 'Video')
		},
	},

	methods: {
		t,

		backToVideo() {
			this.$router.push({
				name: 'single-post',
				params: { account: this.status.account?.acct ?? '', id: this.status.id },
			})
		},

		close() {
			closeMiniPlayer()
		},
	},
}
</script>

<style scoped lang="scss">
.mini-player {
	position: fixed;
	inset-inline-end: 16px;
	/* above the phone bar, where there is one */
	inset-block-end: calc(16px + var(--social-phone-bar-space, 0px));
	z-index: 2000;
	width: min(360px, calc(100vw - 32px));
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-raised);
	overflow: hidden;
	animation: mini-player-in 0.2s ease-out;
}

.mini-player__bar {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 4px 4px 12px;
}

.mini-player__title {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-weight: bold;
}

@keyframes mini-player-in {
	from {
		opacity: 0;
		transform: translateY(16px);
	}
}

@media (prefers-reduced-motion: reduce) {
	.mini-player {
		animation: none;
	}
}
</style>
