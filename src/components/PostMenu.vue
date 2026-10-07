<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions @update:open="$emit('update:open', $event)">
		<NcActionButton v-if="canQuote" @click="$emit('quote')">
			<template #icon>
				<FormatQuoteClose :size="20" />
			</template>
			{{ t('social', 'Quote') }}
		</NcActionButton>
		<NcActionButton
			v-if="isMine"
			icon="icon-rename"
			@click="$emit('edit')">
			{{ t('social', 'Edit') }}
		</NcActionButton>
		<!-- the answer to "this no longer belongs on my profile" that is not
		     destroying it. Nothing federates: the post stays on every server
		     that received it, which is what deleting is for -->
		<NcActionButton
			v-if="isMine && isLocal"
			:disabled="archiving"
			closeAfterClick
			@click="$emit('archive')">
			<template #icon>
				<IconArchiveOutline :size="20" />
			</template>
			{{ item.archived ? t('social', 'Put back on my profile') : t('social', 'Archive') }}
		</NcActionButton>
		<!-- who may quote it, and who already has. The two are deliberately one
		     dialog: the reason to let people quote you is the same reason to be
		     able to stop one of them -->
		<NcActionButton
			v-if="isMine && isLocal"
			closeAfterClick
			@click="$emit('manageQuotes')">
			<template #icon>
				<FormatQuoteClose :size="20" />
			</template>
			{{ t('social', 'Quotes of this post') }}
		</NcActionButton>
		<!-- who is in the picture, which only the author may say: anybody able
		     to write a name onto anybody's photograph could put a post in front
		     of an audience that did not ask for it -->
		<NcActionButton
			v-if="isMine && hasPictures"
			closeAfterClick
			@click="$emit('tagPeople')">
			<template #icon>
				<IconAccountBoxMultiple :size="20" />
			</template>
			{{ t('social', 'Tag people') }}
		</NcActionButton>
		<NcActionButton
			v-if="isMine"
			icon="icon-delete"
			@click="$emit('delete')">
			{{ t('social', 'Delete') }}
		</NcActionButton>
		<!-- the correction people actually make: the post goes and its words
		     come back in the composer, to be posted again as a new post -->
		<NcActionButton v-if="isMine" @click="$emit('redraft')">
			<template #icon>
				<PencilBoxOutline :size="20" />
			</template>
			{{ t('social', 'Delete & re-draft') }}
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
		<!-- where the post got to: the queue knows, and this asks it for the
		     author, who is the only one it is answered for -->
		<NcActionButton v-if="isMine && isLocal" @click="$emit('delivery')">
			<template #icon>
				<SendCheck :size="20" />
			</template>
			{{ t('social', 'Delivery status') }}
		</NcActionButton>
		<!-- NcActionLink sets rel="nofollow noreferrer noopener" itself -->
		<NcActionLink
			v-if="!origin.local && item.url"
			:href="item.url"
			target="_blank">
			<template #icon>
				<OpenInNew :size="20" />
			</template>
			{{ t('social', 'Open on original instance') }}
		</NcActionLink>
		<NcActionButton @click="$emit('bookmark')">
			<template #icon>
				<Bookmark v-if="item.bookmarked" :size="20" />
				<BookmarkOutline v-else :size="20" />
			</template>
			{{ item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark') }}
		</NcActionButton>
		<!-- the keyboard's way to a reaction: the heart offers them on a hold
		     or a right-click, neither of which a keyboard has -->
		<NcActionButton v-if="!isPublic" @click="$emit('react')">
			<template #icon>
				<EmoticonPlusOutline :size="20" />
			</template>
			{{ t('social', 'Add a reaction') }}
		</NcActionButton>
		<!-- the thread, not the person: muting an account is too blunt when
		     one conversation has gone wrong, and it is possible only after
		     the unwanted mention has arrived -->
		<NcActionButton v-if="!isPublic" @click="$emit('muteConversation')">
			<template #icon>
				<BellOutline v-if="item.muted" :size="20" />
				<BellOffOutline v-else :size="20" />
			</template>
			{{ item.muted ? t('social', 'Unmute conversation') : t('social', 'Mute conversation') }}
		</NcActionButton>
		<!-- an album is made of the reader's own pictures; where the picture
		     is, is where it is put into one -->
		<NcActionButton v-if="canCollect" @click="$emit('collect')">
			<template #icon>
				<FolderMultiplePlusOutline :size="20" />
			</template>
			{{ t('social', 'Add to a collection') }}
		</NcActionButton>
		<NcActionButton v-if="canPin" @click="$emit('pin')">
			<template #icon>
				<Pin v-if="!item.pinned" :size="20" />
				<PinOff v-else :size="20" />
			</template>
			{{ item.pinned ? t('social', 'Unpin from profile') : t('social', 'Pin to profile') }}
		</NcActionButton>
		<!-- teaches For you about the post's hashtags; only offered where
		     there is something to teach it -->
		<NcActionButton v-if="canLessLikeThis" closeAfterClick @click="$emit('lessLikeThis')">
			<template #icon>
				<ThumbDownOutline :size="20" />
			</template>
			{{ t('social', 'Less like this') }}
		</NcActionButton>
		<!-- what to do about somebody else, from the post that made the reader
		     want to: both take their posts out of every timeline at once -->
		<NcActionButton v-if="canModerateAuthor" @click="$emit('mute')">
			<template #icon>
				<VolumeOff :size="20" />
			</template>
			{{ t('social', 'Mute {account}', { account: item.account.acct }) }}
		</NcActionButton>
		<NcActionButton v-if="canModerateAuthor" @click="$emit('block')">
			<template #icon>
				<Cancel :size="20" />
			</template>
			{{ t('social', 'Block {account}', { account: item.account.acct }) }}
		</NcActionButton>
		<NcActionButton v-if="!isMine" @click="$emit('report')">
			<template #icon>
				<Flag :size="20" />
			</template>
			{{ t('social', 'Report') }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import BellOffOutline from 'vue-material-design-icons/BellOffOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import EmoticonPlusOutline from 'vue-material-design-icons/EmoticonPlusOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Flag from 'vue-material-design-icons/Flag.vue'
import FolderMultiplePlusOutline from 'vue-material-design-icons/FolderMultiplePlusOutline.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import IconArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PencilBoxOutline from 'vue-material-design-icons/PencilBoxOutline.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import PinOff from 'vue-material-design-icons/PinOff.vue'
import SendCheck from 'vue-material-design-icons/SendCheck.vue'
import ThumbDownOutline from 'vue-material-design-icons/ThumbDownOutline.vue'
import Translate from 'vue-material-design-icons/Translate.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { hasInterestsFeed } from '../services/interests.js'
import { originOf } from '../utils/instanceIdentity.js'

/**
 * Everything a post can be told to do that is not one of the four buttons
 * beside it.
 *
 * The menu decides for itself which of its items to offer, because every one
 * of those decisions is about the post rather than about the page it is on:
 * whether the reader wrote it, whether it lives here, whether its author said
 * it may be quoted. What it does not decide is what happens next — each item
 * says what was asked for and TimelinePost carries it out, which is where the
 * dialogs and the store already are.
 */
export default {
	name: 'PostMenu',

	components: {
		BellOffOutline,
		BellOutline,
		Bookmark,
		BookmarkOutline,
		EmoticonPlusOutline,
		Cancel,
		Flag,
		FolderMultiplePlusOutline,
		FormatQuoteClose,
		IconAccountBoxMultiple,
		IconArchiveOutline,
		NcActionButton,
		NcActionLink,
		NcActions,
		OpenInNew,
		PencilBoxOutline,
		Pin,
		PinOff,
		SendCheck,
		ThumbDownOutline,
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
	},

	emits: [
		'update:open',
		'quote',
		'edit',
		'archive',
		'manageQuotes',
		'tagPeople',
		'delete',
		'redraft',
		'translate',
		'delivery',
		'bookmark',
		'react',
		'muteConversation',
		'collect',
		'pin',
		'lessLikeThis',
		'mute',
		'block',
		'report',
	],

	computed: {
		/** @return {boolean} whether the reader wrote this post */
		isMine() {
			return this.item.account.acct === this.currentAccount?.acct
		},

		/**
		 * @return {boolean} whether the post is this server's own. `local` is
		 * absent on a post from before the column existed, and those are local
		 * — only `false` says otherwise.
		 */
		isLocal() {
			return this.item.local !== false
		},

		/**
		 * @return {boolean} whether this post may be quoted at all. A quote
		 * carries the audience of the quoter, so the server grants one only for
		 * a public or unlisted post — the same set a boost is allowed for — and
		 * offering the action on anything narrower would be offering a refusal.
		 */
		canQuote() {
			return isShareable(this.item) && allowedByAuthor(this.item, 'quote')
		},

		/** @return {boolean} whether there is a picture to name anybody in */
		hasPictures() {
			return (this.item.media_attachments ?? []).length > 0
		},

		/**
		 * @return {boolean} whether this post can go into one of the reader's
		 * collections: their own, written here, and with a picture or a video
		 * in it — a collection holds only its owner's own media posts, so
		 * offering the action on anything else would be offering a refusal
		 */
		canCollect() {
			return this.isMine && this.isLocal && this.hasPictures
		},

		/**
		 * @return {boolean} own local posts can be pinned to the profile, and
		 * only the ones anyone may see: a pinned followers-only post was
		 * served in full to the anonymous internet through the featured
		 * collection, so the server now refuses anything that is not public
		 * or unlisted — the same set a boost is allowed for.
		 */
		canPin() {
			return this.isMine && this.isLocal && isShareable(this.item)
		},

		/**
		 * @return {boolean} whether this post's author is somebody the reader
		 * can act on: not themselves, and not on the public pages, where there
		 * is nobody signed in to do the blocking
		 */
		canModerateAuthor() {
			return !this.isPublic && !!this.currentAccount && !this.isMine
		},

		/**
		 * @return {boolean} whether "Less like this" can teach For you
		 * anything: the feed is on, somebody else wrote the post, and it
		 * carries a hashtag to be lowered
		 */
		canLessLikeThis() {
			return !this.isPublic
				&& hasInterestsFeed(this.interests)
				&& !!this.currentAccount
				&& !this.isMine
				&& (this.item.tags ?? []).length > 0
		},

		/**
		 * Where the author lives. Only asked whether there *is* an original
		 * instance to open.
		 *
		 * @return {{instance: string, colour: string, local: boolean}}
		 */
		origin() {
			return originOf(this.item.account?.acct ?? '')
		},
	},

	methods: {
		t,
	},
}
</script>
