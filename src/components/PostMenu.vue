<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions @update:open="onOpen">
		<!-- one level down: For you feedback, or the details of a post of your own -->
		<template v-if="submenu !== ''">
			<NcActionButton @click="submenu = ''">
				<template #icon>
					<IconArrowLeft :size="20" />
				</template>
				{{ t('social', 'Back') }}
			</NcActionButton>
			<NcActionSeparator />
			<template v-if="submenu === 'tune'">
				<NcActionButton :disabled="recommendationFeedbackLoading || feedbackContext?.similarity_available !== true" closeAfterClick @click="$emit('recommendationFeedback', 'MORE_SIMILAR')">
					<template #icon>
						<ThumbUpOutline :size="20" />
					</template>
					{{ t('social', 'More like this') }}
				</NcActionButton>
				<NcActionButton :disabled="recommendationFeedbackLoading || feedbackContext?.similarity_available !== true" closeAfterClick @click="$emit('recommendationFeedback', 'LESS_SIMILAR')">
					<template #icon>
						<ThumbDownOutline :size="20" />
					</template>
					{{ t('social', 'Less like this') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="$emit('recommendationFeedback', 'MORE_AUTHOR')">
					<template #icon>
						<AccountPlusOutline :size="20" />
					</template>
					{{ t('social', 'More from this author') }}
				</NcActionButton>
				<NcActionButton :disabled="recommendationFeedbackLoading || !feedbackContext?.format" closeAfterClick @click="$emit('recommendationFeedback', 'MORE_FORMAT')">
					<template #icon>
						<ShapeOutline :size="20" />
					</template>
					{{ t('social', 'More {format}', { format: recommendationFormatLabel }) }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="$emit('recommendationFeedback', 'HIDE_POST')">
					<template #icon>
						<IconEyeOffOutline :size="20" />
					</template>
					{{ t('social', 'Hide from For you') }}
				</NcActionButton>
			</template>
			<template v-else-if="submenu === 'details'">
				<NcActionButton v-if="isMine && isLocal" closeAfterClick @click="$emit('delivery')">
					<template #icon>
						<SendCheck :size="20" />
					</template>
					{{ t('social', 'Delivery status') }}
				</NcActionButton>
				<NcActionButton v-if="canEmbed" closeAfterClick @click="copyEmbedCode">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('social', 'Copy embed code') }}
				</NcActionButton>
			</template>
		</template>

		<template v-else>
			<NcActionButton v-if="canQuote" closeAfterClick @click="$emit('quote')">
				<template #icon>
					<FormatQuoteClose :size="20" />
				</template>
				{{ t('social', 'Quote') }}
			</NcActionButton>
			<NcActionButton
				v-if="canTranslate"
				:disabled="translating"
				closeAfterClick
				@click="$emit('translate')">
				<template #icon>
					<Translate :size="20" />
				</template>
				{{ translated ? t('social', 'Show original') : t('social', 'Translate') }}
			</NcActionButton>
			<!-- NcActionLink sets rel="nofollow noreferrer noopener" itself -->
			<NcActionLink
				v-if="(!origin.local || onBluesky) && item.url"
				:href="item.url"
				target="_blank">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ onBluesky ? t('social', 'Open on Bluesky') : t('social', 'Open on original instance') }}
			</NcActionLink>
			<!-- the keyboard's way to a reaction: the heart offers them on a hold or a right-click -->
			<NcActionButton v-if="!isPublic" closeAfterClick @click="$emit('react')">
				<template #icon>
					<EmoticonPlusOutline :size="20" />
				</template>
				{{ t('social', 'Add a reaction') }}
			</NcActionButton>
			<NcActionButton v-if="!isPublic" closeAfterClick @click="$emit('muteConversation')">
				<template #icon>
					<BellOutline v-if="item.muted" :size="20" />
					<BellOffOutline v-else :size="20" />
				</template>
				{{ item.muted ? t('social', 'Unmute conversation') : t('social', 'Mute conversation') }}
			</NcActionButton>
			<!-- a reply in a thread of the reader's own, which they may hide wherever it was written -->
			<NcActionButton
				v-if="item.can_hide === true && item.in_reply_to_id"
				closeAfterClick
				@click="$emit('hideReply', !item.hidden_by_author)">
				<template #icon>
					<IconEyeOutline v-if="item.hidden_by_author" :size="20" />
					<IconEyeOffOutline v-else :size="20" />
				</template>
				{{ item.hidden_by_author ? t('social', 'Show reply') : t('social', 'Hide reply') }}
			</NcActionButton>

			<template v-if="isMine">
				<NcActionCaption :name="t('social', 'Your post')" />
				<NcActionButton icon="icon-rename" closeAfterClick @click="$emit('edit')">
					{{ t('social', 'Edit') }}
				</NcActionButton>
				<NcActionButton v-if="isLocal" closeAfterClick @click="$emit('manageReplies')">
					<template #icon>
						<IconCommentAccountOutline :size="20" />
					</template>
					{{ t('social', 'Who can reply') }}
				</NcActionButton>
				<!-- who may quote it, and who already has, in one dialog -->
				<NcActionButton v-if="isLocal" closeAfterClick @click="$emit('manageQuotes')">
					<template #icon>
						<FormatQuoteClose :size="20" />
					</template>
					{{ t('social', 'Quotes of this post') }}
				</NcActionButton>
				<!-- only the author may say who is in a photo -->
				<NcActionButton v-if="hasPhotos" closeAfterClick @click="$emit('tagPeople')">
					<template #icon>
						<IconAccountBoxMultiple :size="20" />
					</template>
					{{ t('social', 'Tag people') }}
				</NcActionButton>
				<NcActionButton v-if="canPin" closeAfterClick @click="$emit('pin')">
					<template #icon>
						<Pin v-if="!item.pinned" :size="20" />
						<PinOff v-else :size="20" />
					</template>
					{{ item.pinned ? t('social', 'Unpin from profile') : t('social', 'Pin to profile') }}
				</NcActionButton>
				<NcActionButton v-if="canCollect" closeAfterClick @click="$emit('collect')">
					<template #icon>
						<FolderMultiplePlusOutline :size="20" />
					</template>
					{{ t('social', 'Add to a collection') }}
				</NcActionButton>
				<!-- off the profile without deleting: the post stays on every server that received it -->
				<NcActionButton
					v-if="isLocal"
					:disabled="archiving"
					closeAfterClick
					@click="$emit('archive')">
					<template #icon>
						<IconArchiveOutline :size="20" />
					</template>
					{{ item.archived ? t('social', 'Put back on my profile') : t('social', 'Archive') }}
				</NcActionButton>
				<NcActionButton
					v-if="isLocal || canEmbed"
					isMenu
					@click="submenu = 'details'">
					<template #icon>
						<IconInformationOutline :size="20" />
					</template>
					{{ t('social', 'Details') }}
				</NcActionButton>
				<NcActionButton icon="icon-delete" closeAfterClick @click="$emit('delete')">
					{{ t('social', 'Delete') }}
				</NcActionButton>
			</template>
			<NcActionButton v-else-if="canEmbed" closeAfterClick @click="copyEmbedCode">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('social', 'Copy embed code') }}
			</NcActionButton>

			<template v-if="canOfferRecommendationFeedback">
				<NcActionSeparator />
				<NcActionButton isMenu @click="submenu = 'tune'">
					<template #icon>
						<IconTune :size="20" />
					</template>
					{{ t('social', 'Tune For you') }}
				</NcActionButton>
			</template>
			<template v-else-if="canLessLikeThis">
				<NcActionSeparator />
				<NcActionButton closeAfterClick @click="$emit('lessLikeThis')">
					<template #icon>
						<ThumbDownOutline :size="20" />
					</template>
					{{ t('social', 'Less like this') }}
				</NcActionButton>
			</template>

			<template v-if="!isMine && !isPublic">
				<NcActionCaption :name="t('social', 'About {account}', { account: item.account.acct })" />
				<NcActionButton v-if="canModerateAuthor" closeAfterClick @click="$emit('mute')">
					<template #icon>
						<VolumeOff :size="20" />
					</template>
					{{ t('social', 'Mute') }}
				</NcActionButton>
				<NcActionButton v-if="canModerateAuthor" closeAfterClick @click="$emit('block')">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ t('social', 'Block') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="$emit('report')">
					<template #icon>
						<Flag :size="20" />
					</template>
					{{ t('social', 'Report') }}
				</NcActionButton>
			</template>
		</template>
	</NcActions>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '../services/toast.js'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconInformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import IconTune from 'vue-material-design-icons/TuneVariant.vue'
import BellOffOutline from 'vue-material-design-icons/BellOffOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Flag from 'vue-material-design-icons/Flag.vue'
import FolderMultiplePlusOutline from 'vue-material-design-icons/FolderMultiplePlusOutline.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import IconCommentAccountOutline from 'vue-material-design-icons/CommentAccountOutline.vue'
import IconEyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import IconEyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import SendCheck from 'vue-material-design-icons/SendCheck.vue'
import ThumbDownOutline from 'vue-material-design-icons/ThumbDownOutline.vue'
import ThumbUpOutline from 'vue-material-design-icons/ThumbUpOutline.vue'
import Translate from 'vue-material-design-icons/Translate.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { hasInterestsFeed } from '../services/interests.js'
import { isBlueskyAccount } from '../utils/accountLocality.js'
import { originOf } from '../utils/instanceIdentity.js'
import { embedCode } from '../utils/embedCode.js'

/**
 * The post's overflow menu. It decides which items to offer from the post
 * itself; each item emits what was asked for and TimelinePost carries it out.
 */
export default {
	name: 'PostMenu',

	components: {
		BellOffOutline,
		BellOutline,
		EmoticonPlusOutline,
		Cancel,
		ContentCopy,
		Flag,
		FolderMultiplePlusOutline,
		FormatQuoteClose,
		IconAccountBoxMultiple,
		IconArchiveOutline,
		IconCommentAccountOutline,
		IconEyeOffOutline,
		IconEyeOutline,
		AccountPlusOutline,
		ThumbDownOutline,
		ThumbUpOutline,
		ShapeOutline,
		IconArrowLeft,
		IconInformationOutline,
		IconTune,
		NcActionButton,
		NcActionCaption,
		NcActionLink,
		NcActions,
		NcActionSeparator,
		OpenInNew,
		Pin,
		PinOff,
		SendCheck,
		Translate,
		VolumeOff,
	},

	props: {
		/** the post, as the client API sends one */
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			required: true,
		},

		/** the reader's own account, or null on a public page */
		currentAccount: {
			type: Object,
			default: null,
		},

		/** whether this is a page with nobody signed in */
		isPublic: {
			type: Boolean,
			default: false,
		},

		/** whether this server has a translation provider for this post */
		canTranslate: {
			type: Boolean,
			default: false,
		},

		/** whether a translation is being fetched */
		translating: {
			type: Boolean,
			default: false,
		},

		/** whether the post is showing its translation rather than its words */
		translated: {
			type: Boolean,
			default: false,
		},

		/** whether an archive or unarchive is in flight */
		archiving: {
			type: Boolean,
			default: false,
		},

		/** `serverData.interests`: whether For you is on for the reader */
		interests: {
			type: Object,
			default: null,
		},

		recommendationFeedbackEnabled: {
			type: Boolean,
			default: false,
		},

		recommendationFeedbackLoading: {
			type: Boolean,
			default: false,
		},

		feedbackContext: {
			type: Object,
			default: null,
		},
	},

	emits: [
		'update:open',
		'quote',
		'edit',
		'archive',
		'manageQuotes',
		'manageReplies',
		'hideReply',
		'tagPeople',
		'delete',
		'translate',
		'delivery',
		'react',
		'muteConversation',
		'collect',
		'pin',
		'lessLikeThis',
		'recommendationFeedback',
		'mute',
		'block',
		'report',
	],

	data() {
		return {
			/** '' for the main menu, 'tune' or 'details' for a submenu */
			submenu: '',
		}
	},

	computed: {
		/** @return {boolean} whether recommendation feedback applies to this author's post in For You */
		canOfferRecommendationFeedback() {
			return this.recommendationFeedbackEnabled
				&& !this.isPublic
				&& !!this.currentAccount
				&& !this.isMine
				&& !!this.item.nid
		},

		/** @return {string} readable name for a supported recommendation format */
		recommendationFormatLabel() {
			return {
				audio: t('social', 'audio posts'),
				image: t('social', 'photo posts'),
				poll: t('social', 'polls'),
				short: t('social', 'short videos'),
				text: t('social', 'text posts'),
				video: t('social', 'video posts'),
			}[this.feedbackContext?.format] || t('social', 'posts like this')
		},

		/** @return {boolean} whether the reader wrote this post */
		isMine() {
			return this.item.account.acct === this.currentAccount?.acct
		},

		/**
		 * @return {boolean} whether the post is this server's own; older posts
		 * lack `local` and are local, so only `false` says otherwise
		 */
		isLocal() {
			return this.item.local !== false
		},

		/** @return {boolean} only public posts served by this instance have an embed endpoint */
		canEmbed() {
			return this.isLocal && this.item.visibility === 'public' && !!this.item.url
		},

		/**
		 * @return {boolean} whether this post may be quoted: the server allows
		 * it only for public or unlisted posts that the author lets be quoted
		 */
		canQuote() {
			return isShareable(this.item) && allowedByAuthor(this.item, 'quote')
		},

		/** @return {boolean} whether there is a photo to tag anybody in */
		hasPhotos() {
			return (this.item.media_attachments ?? []).length > 0
		},

		/**
		 * @return {boolean} whether this post can go into one of the reader's
		 * collections, which hold only their owner's local media posts
		 */
		canCollect() {
			return this.isMine && this.isLocal && this.hasPhotos
		},

		/**
		 * @return {boolean} own local posts can be pinned if public or unlisted:
		 * the featured collection is served to anyone
		 */
		canPin() {
			return this.isMine && this.isLocal && isShareable(this.item)
		},

		/**
		 * @return {boolean} whether the reader can mute or block this post's
		 * author: signed in, and not their own post
		 */
		canModerateAuthor() {
			return !this.isPublic && !!this.currentAccount && !this.isMine
		},

		/**
		 * @return {boolean} whether "Less like this" applies: For you is on,
		 * somebody else wrote the post, and it has a hashtag to lower
		 */
		canLessLikeThis() {
			return !this.isPublic
				&& hasInterestsFeed(this.interests)
				&& !!this.currentAccount
				&& !this.isMine
				&& (this.item.tags ?? []).length > 0
		},

		/**
		 * Where the author lives, to offer opening the original instance.
		 *
		 * @return {{instance: string, colour: string, local: boolean}}
		 */
		origin() {
			return originOf(this.item.account ?? '')
		},

		/**
		 * @return {boolean} whether the post's home is Bluesky rather than
		 * another instance: a Bluesky author, or a post address on bsky.app
		 */
		onBluesky() {
			if (isBlueskyAccount(this.item.account)) {
				return true
			}
			try {
				return new URL(this.item.url ?? '').hostname === 'bsky.app'
			} catch {
				return false
			}
		},
	},

	methods: {
		t,
		onOpen(open) {
			if (!open) {
				this.submenu = ''
			}
			this.$emit('update:open', open)
		},

		async copyEmbedCode() {
			try {
				const embedUrl = new URL(this.item.url)
				embedUrl.pathname = `${embedUrl.pathname.replace(/\/$/, '')}/embed`
				embedUrl.search = ''
				embedUrl.hash = ''
				await navigator.clipboard.writeText(embedCode(embedUrl.href, t('social', 'Aloha Social post')))
				await showSuccess(t('social', 'Copied'))
			} catch {
				await showError(t('social', 'Could not copy — select the address and copy it yourself'))
			}
		},
	},
}
</script>
