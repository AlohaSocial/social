<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<article
		class="post-content"
		:class="{ 'post-content--openable': postRoute !== null, 'post-content--arrived': hasJustArrived }"
		:style="authorStyle"
		:data-social-status="item.id"
		:aria-label="postLabel"
		@click="onPostClick">
		<p v-if="item.hidden_by_author === true" class="post-hidden-by-author">
			{{ t('social', 'Hidden by the author of the conversation') }}
		</p>
		<TimelinePostHeader
			:item="item"
			:hideAuthor="hideAuthor"
			:compactAuthor="compactAuthor"
			:postHref="postHref"
			:isBoosted="isBoosted"
			:isLiked="isLiked"
			@open="getSinglePostTimeline" />

		<!-- who is in the photo, when the poster named anybody: names, not
		     boxes drawn over the image -->
		<p v-if="taggedPeople.length" class="post-tagged">
			<IconAccountBoxMultiple :size="14" />
			<span class="post-tagged__with">{{ t('social', 'With') }}</span>
			<component
				:is="isLocalAccount(person) ? 'a' : 'router-link'"
				v-for="(person, index) in taggedPeople"
				:key="person.acct"
				class="post-tagged__person"
				:href="isLocalAccount(person) ? localProfileUrl(person) : undefined"
				:to="isLocalAccount(person) ? undefined : { name: 'profile', params: { account: person.acct } }">
				{{ person.display_name || person.username }}<span v-if="index < taggedPeople.length - 1">,</span>
			</component>
			<!-- anybody named in a photo can take their name off it -->
			<NcButton
				v-if="isTagged"
				variant="tertiary-no-background"
				class="post-tagged__leave"
				:disabled="untagging"
				@click="untagMe">
				{{ t('social', 'Remove me') }}
			</NcButton>
		</p>

		<!--
		  A `warn` filter of the reader's own matched this post (`hide` filters
		  are applied by the server). Like a content warning, the body is not
		  rendered until asked for. Lifting it falls through to the branches
		  below, so a content warning still covers the post afterwards.
		-->
		<div v-if="filterCovers" class="post-filtered">
			<p class="post-filtered__reason">
				{{ filterLabel }}
			</p>
			<NcButton variant="secondary" @click="filterLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show anyway') }}
			</NcButton>
		</div>
		<!-- a content warning covers the whole post, media, poll and link preview included -->
		<div v-else-if="hasSpoiler" class="post-warning">
			<p class="post-warning__text" dir="auto">
				{{ item.spoiler_text }}
			</p>
			<NcButton
				variant="secondary"
				:aria-expanded="warningLifted ? 'true' : 'false'"
				@click="warningLifted = !warningLifted">
				{{ warningLifted ? t('social', 'Show less') : t('social', 'Show more') }}
			</NcButton>
			<div v-if="warningLifted" class="post-message post-message--behind-warning">
				<MessageContent v-if="item.content" :item="displayedItem" />
			</div>
		</div>
		<!--
		  A post with photos leads with them at the width of the card, the text
		  as their caption; not under a content warning, which has to come first.
		-->
		<template v-else-if="mediaLeads">
			<PostAttachment
				v-if="mediaRevealed"
				mediaFirst
				:to="mediaRoute"
				:video="item.video"
				:attachments="item.media_attachments || []" />
			<div v-else class="post-sensitive post-sensitive--leading">
				<NcButton
					v-if="mediaRevealable"
					variant="secondary"
					@click="warningLifted = true">
					<template #icon>
						<EyeOff :size="20" />
					</template>
					{{ t('social', 'Show sensitive content') }}
				</NcButton>
				<span v-else class="post-sensitive__hidden">
					{{ t('social', 'Sensitive media, hidden by your settings') }}
				</span>
			</div>
			<div v-if="item.content" class="post-message post-message--caption">
				<MessageContent :item="displayedItem" />
			</div>
		</template>
		<div v-else-if="item.content" class="post-message">
			<MessageContent :item="displayedItem" />
		</div>
		<!-- says the reader is reading a machine translation; not under a
		     filter cover, where there is no body -->
		<p v-if="translation !== null && !filterCovers" class="post-translated">
			{{ translatedFrom === ''
				? t('social', 'Translated by {provider}', { provider: translation.provider || t('social', 'this server') })
				: t('social', 'Translated from {language} by {provider}', {
					language: translatedFrom,
					provider: translation.provider || t('social', 'this server'),
				}) }}
		</p>
		<template v-if="mediaRevealed && !filterCovers">
			<TimelineEventCard
				v-if="item.event"
				:event="item.event"
				:statusId="item.id"
				:originalUrl="item.url || item.uri || ''"
				:updatedAt="item.edited_at || null"
				:isPublic="serverData.public" />
			<QuotedPost v-if="item.quote" :quote="item.quote" />
			<Poll v-if="localPoll" :poll="localPoll" @update:poll="updatePoll" />
			<PostAttachment
				v-if="hasAttachments && !mediaLeads"
				:to="mediaRoute"
				:video="item.video"
				:attachments="item.media_attachments || []" />
			<PostCard v-if="showCard" :card="item.card" />
		</template>
		<!-- not with a content warning, which has its own "Show more" for the
		     same flag, nor when the media leads, which shows this reveal in
		     place of the photos. No aria-expanded: the control is gone once
		     it would be true. -->
		<div v-else-if="!hasSpoiler && !mediaLeads && !filterCovers" class="post-sensitive">
			<NcButton
				v-if="mediaRevealable"
				variant="secondary"
				@click="warningLifted = true">
				<template #icon>
					<EyeOff :size="20" />
				</template>
				{{ t('social', 'Show sensitive content') }}
			</NcButton>
			<span v-else class="post-sensitive__hidden">
				{{ t('social', 'Sensitive media, hidden by your settings') }}
			</span>
		</div>
		<!-- one row across the foot of the card: the reactions, then the
		     counts and controls at the far end -->
		<div
			v-if="$route && $route.params.type !== 'notifications' && type !== 'direct'"
			class="post-footer">
			<ReactionBar
				ref="reactionBar"
				:statusId="String(item.id || '')"
				:modelValue="item.reactions || []"
				:canReact="!serverData.public"
				:offerAdd="false"
				@update:modelValue="onReactionsChanged" />
			<!-- the counts are always shown, at the end of the row; the other
			     controls join them from the start side with the pointer (see
			     the stylesheet) -->
			<div
				v-if="!serverData.public"
				class="post-actions-reveal"
				:class="{ 'post-actions-reveal--held': menuOpen }">
				<div class="post-actions">
					<PostMenu
						:item="item"
						:currentAccount="currentAccount"
						:isPublic="serverData.public"
						:canTranslate="canTranslate"
						:translating="translating"
						:translated="translation !== null"
						:archiving="archiving"
						:interests="serverData.interests"
						:timelineType="type"
						:recommendationFeedbackEnabled="canUseRecommendationFeedback"
						:recommendationFeedbackLoading="recommendationContextLoading"
						:feedbackContext="recommendationContext"
						@update:open="onMenuToggle"
						@quote="quote"
						@edit="editPost"
						@archive="toggleArchive"
						@manageQuotes="managingQuotes = true"
						@manageReplies="managingReplies = true"
						@hideReply="hideReply"
						@tagPeople="taggingPeople = true"
						@delete="showDeleteDialog = true"
						@translate="toggleTranslation"
						@delivery="showDeliveryDialog = true"
						@react="askForReaction"
						@muteConversation="toggleConversationMute"
						@collect="showCollectionDialog = true"
						@pin="togglePin"
						@lessLikeThis="lessLikeThis"
						@recommendationFeedback="applyRecommendationFeedback"
						@mute="showMuteDialog = true"
						@block="showBlockDialog = true"
						@report="showReportDialog = true" />
					<div class="post-actions__groups">
						<div class="post-action-group">
							<NcButton
								:title="item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark')"
								:aria-label="item.bookmarked ? t('social', 'Remove bookmark') : t('social', 'Bookmark')"
								:aria-pressed="item.bookmarked ? 'true' : 'false'"
								variant="tertiary"
								@click="toggleBookmark">
								<template #icon>
									<Bookmark v-if="item.bookmarked" :size="20" fillColor="var(--color-primary)" />
									<BookmarkOutline v-else :size="20" />
								</template>
							</NcButton>
						</div>
						<div v-if="postLink !== ''" class="post-action-group">
							<NcButton
								:title="t('social', 'Share')"
								:aria-label="t('social', 'Share')"
								variant="tertiary"
								@click="share">
								<template #icon>
									<ShareVariantOutline :size="20" />
								</template>
							</NcButton>
						</div>
						<!-- the same three controls on every post, so nothing moves
						     between posts; one that cannot be used is disabled and
						     says why -->
						<div
							class="post-action-group"
							:class="{ 'post-action-group--refused': refused === 'boost' }"
							:title="refusedBecause.boost || undefined">
							<NcButton
								:disabled="!canBoost"
								:title="refusedBecause.boost || (isBoosted ? t('social', 'Undo boost') : t('social', 'Boost'))"
								:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
								:aria-pressed="isBoosted ? 'true' : 'false'"
								variant="tertiary"
								:class="{ 'post-action--spun': celebrate === 'boost' }"
								@click="boost">
								<template #icon>
									<Repeat :size="20" :fillColor="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
								</template>
							</NcButton>
							<RollingCount v-if="!hidesCounts" :count="item.reblogs_count || 0" />
						</div>
						<div
							class="post-action-group post-action-group--like"
							:class="{ 'post-action-group--refused': refused === 'like' }"
							:title="refusedBecause.like || undefined">
							<template v-if="celebrate === 'like'">
								<span class="post-action__burst" aria-hidden="true" />
								<!-- a plumeria opening, in the author's colour -->
								<span
									v-for="petal in PETALS"
									:key="petal"
									class="post-action__petal"
									:style="{ '--petal': petal }"
									aria-hidden="true" />
							</template>
							<!-- one button whose label changes, not two swapped by v-if:
							     unmounting the pressed button would drop the focus -->
							<NcButton
								:disabled="!canLike && !isLiked"
								:title="refusedBecause.like || (isLiked ? t('social', 'Undo Like') : t('social', 'Like — hold for a reaction'))"
								:aria-label="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
								:aria-pressed="isLiked ? 'true' : 'false'"
								variant="tertiary"
								:class="{ 'post-action--popped': isLiked && celebrate === 'like' }"
								@click="like"
								@pointerdown="startHold"
								@pointerup="endHold"
								@pointerleave="endHold"
								@pointercancel="endHold"
								@contextmenu.prevent="askForReaction">
								<template #icon>
									<Heart v-if="isLiked" :size="20" fillColor="var(--color-element-error)" />
									<HeartOutline v-else :size="20" />
								</template>
							</NcButton>
							<RollingCount v-if="!hidesCounts" :count="item.favourites_count || 0" />
						</div>
						<div class="post-action-group post-action-group--reply" :title="refusedBecause.reply || undefined">
							<NcButton
								:title="refusedBecause.reply || t('social', 'Reply')"
								:aria-label="t('social', 'Reply')"
								:disabled="!canReply"
								variant="tertiary"
								@click="reply">
								<template #icon>
									<Reply :size="20" />
								</template>
							</NcButton>
							<RollingCount v-if="!hidesCounts" :count="item.replies_count || 0" />
						</div>
					</div>
				</div>
			</div>
		</div>
		<div v-if="embeddedActions" class="post-footer post-footer--profile-actions">
			<slot name="profileActions" />
		</div>
		<MuteDialog
			v-if="showMuteDialog"
			v-model:open="showMuteDialog"
			:account="item.account"
			:status="item"
			:timelineType="type" />
		<CollectionPickerDialog
			v-if="showCollectionDialog"
			v-model:open="showCollectionDialog"
			:status="item" />
		<TagPeopleDialog
			v-if="taggingPeople"
			:nid="item.nid"
			:people="taggedPeople"
			@close="taggingPeople = false"
			@tagged="onTagged" />
		<QuoteControlDialog
			v-if="managingQuotes"
			:nid="item.nid"
			:approval="item.quote_approval"
			@close="managingQuotes = false" />
		<ReplyControlDialog
			v-if="managingReplies"
			:nid="item.nid"
			:replyPolicy="item.reply_policy ?? 'everyone'"
			@changed="onReplyPolicy"
			@close="managingReplies = false" />
		<NcDialog
			v-model:open="showBlockDialog"
			:name="t('social', 'Block {account}?', { account: item.account.acct })"
			:buttons="blockButtons">
			<p class="report-hint">
				{{ t('social', 'Their posts leave your timelines, they are unfollowed both ways, and they can no longer follow you or see your posts.') }}
			</p>
		</NcDialog>
		<ReportDialog
			v-if="showReportDialog"
			v-model:open="showReportDialog"
			:account="item.account"
			:status="item" />
		<DeliveryDialog
			v-if="showDeliveryDialog"
			v-model:open="showDeliveryDialog"
			:statusId="item.id" />
		<!-- deleting is irreversible and federates, so it asks first -->
		<NcDialog
			v-model:open="showDeleteDialog"
			:name="t('social', 'Delete this post?')"
			:buttons="deleteButtons">
			<p class="delete-hint">
				{{ t('social', 'The post is removed from this server and a deletion is sent to every server that received it. This cannot be undone.') }}
			</p>
		</NcDialog>
	</article>
</template>

<script>

import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import PostAttachment from './PostAttachment.vue'
import PostMenu from './PostMenu.vue'
import PostCard from './PostCard.vue'
import TimelineEventCard from './TimelineEventCard.vue'
import TimelinePostHeader from './TimelinePostHeader.vue'
import ReactionBar from './ReactionBar.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess, showUndo } from '../services/toast.js'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Bookmark from 'vue-material-design-icons/Bookmark.vue'
import BookmarkOutline from 'vue-material-design-icons/BookmarkOutline.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import { accountStyle } from '../services/accountColour.js'
import logger from '../services/logger.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'
import { localProfileUrl } from '../utils/accountProfileLink.js'
import { isBlueskyAccount, isLocalAccount } from '../utils/accountLocality.js'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { lessLikeThisFromPost } from '../services/interestFeedback.js'
import { fetchRecommendationContext, recordRecommendationFeedback, undoRecommendationFeedback } from '../services/recommendationFeedback.js'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import QuotedPost from './QuotedPost.vue'
import RollingCount from './RollingCount.vue'
import { mapStores } from 'pinia'
import { focusedElement, returnFocusTo } from '../utils/focus.js'
import { defaultLanguage, languageName } from '../utils/postLanguage.js'
import { isPostControl } from '../utils/postControls.js'
import { useAccountStore } from '../store/account.js'
import { feel } from '../services/senses.js'
import { useInstanceStore } from '../store/instance.js'
import { isPersonalizedFeed, useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'
import { defineAsyncComponent } from 'vue'

// the dialogs below are loaded only when a post first opens one
const DeliveryDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./DeliveryDialog.vue'))
const MuteDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./MuteDialog.vue'))
const ReportDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./ReportDialog.vue'))
const CollectionPickerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./CollectionPickerDialog.vue'))
const TagPeopleDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./TagPeopleDialog.vue'))
const QuoteControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./QuoteControlDialog.vue'))
const ReplyControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./ReplyControlDialog.vue'))

/** How long the heart is held before it offers the reactions. */
const HOLD_MS = 450

export default {
	name: 'TimelinePost',
	components: {
		Bookmark,
		BookmarkOutline,
		DeliveryDialog,
		ShareVariantOutline,
		IconAccountBoxMultiple,
		TimelineEventCard,
		TimelinePostHeader,
		QuoteControlDialog,
		ReplyControlDialog,
		TagPeopleDialog,
		CollectionPickerDialog,
		MuteDialog,
		ReportDialog,
		ReactionBar,
		PostAttachment,
		PostMenu,
		PostCard,
		NcDialog,
		EyeOff,
		NcButton,
		Repeat,
		Reply,
		Heart,
		HeartOutline,
		MessageContent,
		Poll,
		QuotedPost,
		RollingCount,
	},

	props: {
		item: {
			type: /** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */ (Object),
			default: () => {},
		},

		type: {
			type: String,
			required: true,
		},

		postHref: {
			type: String,
			default: '',
		},

		hideAuthor: {
			type: Boolean,
			default: false,
		},

		/** whether the post above is by the same author, who is then not named again on screen */
		compactAuthor: {
			type: Boolean,
			default: false,
		},

		embeddedActions: {
			type: Boolean,
			default: false,
		},
	},

	setup() {
		const { serverData } = useServerData()
		const { currentUser } = useCurrentUser()

		return { serverData, currentUser }
	},

	data() {
		return {
			/** the petal directions, so the template does not build a list per render */
			PETALS: [0, 1, 2, 3, 4],
			/** the press-and-hold timer on the heart, null when nothing is held */
			holdTimer: null,
			/** set by a hold, so the click it ends with does not also like the post */
			held: false,
			/** which action is playing its confirmation, '' when none */
			celebrate: '',
			/** which action the server refused, so the button can say so */
			refused: '',
			/**
			 * the actions with a request on its way, so a second press
			 * while it is pending is ignored
			 *
			 * @type {Set<string>}
			 */
			acting: new Set(),
			/** whether j/k has this post, so l/b/r act on the right one */
			hasKeyboardFocus: false,
			/** whether the overflow menu is open, which holds the action row open with it */
			menuOpen: false,
			/** a warned post stays closed until the reader opens it */
			warningLifted: false,
			/** and so does one the reader's own keyword filters matched */
			filterLifted: false,
			showReportDialog: false,
			showMuteDialog: false,
			/** the menu's own button, which the focus goes back to after a dialog */
			menuOpener: null,
			showCollectionDialog: false,
			showBlockDialog: false,
			showDeleteDialog: false,
			archiving: false,
			untagging: false,
			taggingPeople: false,
			managingQuotes: false,
			managingReplies: false,
			/** the Translation entity once it has arrived, null before */
			translation: null,
			/** whether the provider is working on it right now */
			translating: false,
			showDeliveryDialog: false,
			recommendationContext: null,
			recommendationContextLoading: false,
			localPoll: this.item?.poll ?? null,
		}
	},

	computed: {
		/** @return {boolean} whether the recommendation feedback controls can be used on this For You post */
		canUseRecommendationFeedback() {
			return isPersonalizedFeed({ type: this.type, params: this.timelineStore.params })
				&& this.serverData?.public !== true
				&& this.serverData?.interests?.recommendationsEnabled === true
				&& !!this.currentAccount
				&& this.item.account?.acct !== this.currentAccount.acct
				&& !!this.item.nid
		},

		/** @return {boolean} whether the reader has the like, boost and follower numbers hidden */
		hidesCounts() {
			return this.serverData.hideCounts === true
		},

		...mapStores(useAccountStore, useInstanceStore, useTimelineStore),

		/**
		 * Whether this is the post the reader has just published, so it
		 * arrives at the top of the timeline.
		 *
		 * @return {boolean}
		 */
		hasJustArrived() {
			return this.timelineStore.arrivedId !== '' && this.timelineStore.arrivedId === String(this.item?.id ?? '')
		},

		/**
		 * Where this post lives, or `null` on the post's own page. In a
		 * timeline a press on the post opens it with its replies; on its own
		 * page the photo opens full size and the video plays instead.
		 *
		 * @return {object|null}
		 */
		postRoute() {
			if (!this.item?.account?.acct || this.item?.id === undefined) {
				return null
			}

			const isTheOneBeingRead = this.$route?.name === 'single-post'
				&& String(this.$route.params?.id) === String(this.item.id)

			return isTheOneBeingRead
				? null
				: {
						name: 'single-post',
						params: {
						// acct, not username: two remote accounts can share a
						// username, and the route has to name one of them
							account: this.item.account.acct,
							id: this.item.id,
							type: 'single-post',
						},
					}
		},

		/** @return {object|null} where a press on the media goes */
		mediaRoute() {
			return this.postRoute
		},

		/**
		 * @return {boolean} whether a keyword filter of the reader's own covers
		 * the post; only `warn` filters reach a client
		 */
		filterCovers() {
			return !this.filterLifted && matchedFilters(this.item).length > 0
		},

		/** @return {string} what the cover says, which is where to go to change it */
		filterLabel() {
			return filterCoverLabel(this.item)
		},

		/** @return {boolean} the author asked for the post to be covered */
		hasSpoiler() {
			return Boolean(this.item.spoiler_text)
		},

		/** @return {boolean} anything a warning is supposed to cover */
		hasMedia() {
			return this.hasAttachments || this.localPoll !== null || this.showCard || this.hasQuote || Boolean(this.item.event)
		},

		/** @return {boolean} the post embeds another one, whatever came of it */
		hasQuote() {
			return Boolean(this.item.quote)
		},

		/**
		 * The reader's NSFW media policy, or the instance default: PeerTube's
		 * three policies under Mastodon's `reading:expand:media` names.
		 *
		 * @return {string} 'show_all', 'default' or 'hide_all'
		 */
		nsfwPolicy() {
			const policy = this.serverData?.nsfwPolicy

			return ['show_all', 'default', 'hide_all'].includes(policy) ? policy : 'default'
		},

		/**
		 * @return {boolean} whether the media sits behind a reveal: a content
		 * warning covers it whatever the policy, `sensitive` alone follows
		 * the reader's NSFW policy
		 */
		hasGatedMedia() {
			if (this.hasSpoiler) {
				return this.hasMedia
			}

			return this.item.sensitive === true
				&& this.hasMedia
				&& this.nsfwPolicy !== 'show_all'
		},

		/**
		 * @return {boolean} whether there is a button to lift the cover; under
		 * `hide_all` only opening the post shows the media
		 */
		mediaRevealable() {
			return this.hasSpoiler || this.nsfwPolicy !== 'hide_all'
		},

		/** @return {boolean} */
		mediaRevealed() {
			return !this.hasGatedMedia || (this.warningLifted && this.mediaRevealable)
		},

		/**
		 * @return {boolean} whether the post is laid out around its photos:
		 * not while it is being edited, and not under a content warning
		 */
		mediaLeads() {
			return this.hasAttachments && !this.hasSpoiler
		},

		/** Who wrote it, so moving between posts by landmark says something. */
		postLabel() {
			return t('social', 'Post by {account}', { account: this.item.account?.acct ?? '' })
		},

		/** @return {boolean} a link preview replaces nothing, so media wins */
		showCard() {
			return !this.hasAttachments && Boolean(this.item.card?.title)
		},

		/** @return {boolean} whether there is a photo to name anybody in */
		hasPictures() {
			return (this.item.media_attachments ?? []).length > 0
		},

		/** @return {Array} the people the poster named in this post's photos */
		taggedPeople() {
			return this.item.tagged_people ?? []
		},

		/** @return {boolean} whether the reader is one of them */
		isTagged() {
			const me = this.currentAccount?.acct
			return Boolean(me) && this.taggedPeople.some((person) => person.acct === me)
		},

		/**
		 * @return {boolean} whether the author allows replies to this post;
		 * a post with no published policy may be answered by anybody
		 */
		canReply() {
			return allowedByAuthor(this.item, 'reply')
		},

		/**
		 * @return {boolean} whether this post may be boosted: public or
		 * unlisted, and not refused by the author's policy
		 */
		canBoost() {
			return isShareable(this.item) && allowedByAuthor(this.item, 'boost')
		},

		/** @return {boolean} whether the author allows this post to be liked. */
		canLike() {
			return allowedByAuthor(this.item, 'like')
		},

		/**
		 * @return {boolean} whether one of the dialogs the menu opens, or the
		 * edit form, is on screen
		 */
		dialogOpen() {
			return this.showBlockDialog || this.showReportDialog || this.showDeleteDialog
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		blockButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showBlockDialog = false
					},
				},
				{
					label: t('social', 'Block'),
					variant: 'error',
					callback: () => this.blockAuthor(),
				},
			]
		},

		/**
		 * The post as shown: a copy carrying the translation once it has
		 * arrived, so it stays on this card and out of the store.
		 *
		 * @return {object}
		 */
		displayedItem() {
			if (this.translation === null) {
				return this.item
			}

			return {
				...this.item,
				content: this.translation.content || this.item.content,
				spoiler_text: this.translation.spoiler_text || this.item.spoiler_text,
			}
		},

		/**
		 * Whether to offer a translation: the server has a provider and the
		 * post declares a language other than the reader's.
		 *
		 * @return {boolean}
		 */
		canTranslate() {
			return this.instanceStore.translation
				&& Boolean(this.item.content)
				&& Boolean(this.item.language)
				&& this.item.language !== defaultLanguage()
		},

		/** @return {string} what the translation says it was translated from */
		translatedFrom() {
			const code = this.translation?.detected_source_language || this.item.language || ''

			return code === '' || code === 'und' ? '' : languageName(code)
		},

		/** @return {string} the post's own address, '' when it has none */
		postLink() {
			return String(this.item.url || this.item.uri || '')
		},

		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showDeleteDialog = false
					},
				},
				{
					label: t('social', 'Delete'),
					variant: 'error',
					callback: () => this.remove(),
				},
			]
		},

		/**
		 * @return {boolean}
		 */
		hasAttachments() {
			// a post fresh from the composer has no `media_attachments` until
			// the server answers
			return (this.item.media_attachments || []).length > 0
		},

		/**
		 * @return {boolean}
		 */
		isBoosted() {
			return this.item.reblogged === true
		},

		/**
		 * The card's colour, the author's (see services/accountColour.js).
		 *
		 * @return {object} a style binding carrying `--account-hue`
		 */
		authorStyle() {
			return accountStyle(this.item.account)
		},

		isLiked() {
			return this.item.favourited === true
		},

		/**
		 * @return {object}
		 */
		richParameters() {
			return {}
		},

		/**
		 * @return {boolean}
		 */
		isLocal() {
			return isLocalAccount(this.item.account)
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {{reply: string, boost: string, like: string}} why each of reply, boost and like cannot be used here; empty where it can */
		refusedBecause() {
			return {
				reply: this.canReply ? '' : t('social', 'The author does not take replies to this post'),
				boost: this.canBoost
					? ''
					: (isShareable(this.item) ? t('social', 'The author does not allow boosts of this post') : t('social', 'Only public and unlisted posts can be boosted')),

				like: (this.canLike || this.isLiked) ? '' : t('social', 'The author does not allow likes of this post'),
			}
		},
	},

	watch: {
		// a closed dialog returns focus to the menu item that opened it, which
		// is gone by then, so the menu's button takes it
		dialogOpen(open) {
			if (!open) {
				this.$nextTick(() => returnFocusTo(this.menuOpener))
			}
		},

		// a vote cast elsewhere has to reach this copy of the poll
		'item.poll': function(poll) {
			this.localPoll = poll ?? null
		},
	},

	mounted() {
		eventBus.on('timeline:focused', this.rememberFocus)
		eventBus.on('shortcut:like', this.likeIfFocused)
		eventBus.on('shortcut:boost', this.boostIfFocused)
		eventBus.on('shortcut:reply', this.replyIfFocused)
		eventBus.on('shortcut:open', this.openIfFocused)
	},

	unmounted() {
		eventBus.off('timeline:focused', this.rememberFocus)
		eventBus.off('shortcut:like', this.likeIfFocused)
		eventBus.off('shortcut:boost', this.boostIfFocused)
		eventBus.off('shortcut:reply', this.replyIfFocused)
		eventBus.off('shortcut:open', this.openIfFocused)
	},

	methods: {
		localProfileUrl,
		isBlueskyAccount,

		/**
		 * @param {object} account an Account entity
		 * @return {boolean} whether it has a native Nextcloud profile to link to
		 */
		isLocalAccount(account) {
			return Boolean(account?.acct && isLocalAccount(account) && account.username)
		},

		/**
		 * Hides a reply from a conversation of the reader's own, or shows it
		 * again.
		 *
		 * @param {boolean} hidden whether it is to be hidden
		 */
		async hideReply(hidden) {
			try {
				await axios.post(generateUrl(`apps/social/api/v1/statuses/${this.item.id}/${hidden ? 'hide_reply' : 'unhide_reply'}`))
				this.timelineStore.updateStatusHiddenByAuthor({ statusId: this.item.id, hidden })
				showSuccess(hidden ? t('social', 'The reply is hidden from the conversation') : t('social', 'The reply is shown again'))
			} catch (error) {
				logger.error('Could not hide or show a reply', { error })
				showError(t('social', 'Could not change whether the reply is shown'))
			}
		},

		/**
		 * @param {string} policy who may reply now
		 */
		onReplyPolicy(policy) {
			this.timelineStore.updateStatusReplyPolicy({ statusId: this.item.id, replyPolicy: policy })
		},

		/**
		 * @param {Array} people who the post names now, as the server says
		 */
		onTagged(people) {
			this.timelineStore.updateStatusTagged({ statusId: this.item.id, taggedPeople: people })
		},

		/**
		 * Takes the reader's own name off this photograph.
		 *
		 * @return {Promise<void>}
		 */
		async untagMe() {
			if (this.untagging) {
				return
			}

			this.untagging = true
			try {
				const url = generateUrl('apps/social/api/v1.1/compose/tag/untagme')
				await axios.post(url, { status_id: this.item.nid })
				const me = this.currentAccount?.acct
				const left = this.taggedPeople.filter((person) => person.acct !== me)
				this.timelineStore.updateStatusTagged({ statusId: this.item.id, taggedPeople: left })
				showSuccess(t('social', 'Your name is off this photo.'))
			} catch (error) {
				logger.error('could not take a name off a photo', { error })
				showError(t('social', 'Could not remove your name'))
			} finally {
				this.untagging = false
			}
		},

		/** @return {Promise<void>} "Less like this", from the menu */
		lessLikeThis() {
			return lessLikeThisFromPost(this.item, this.type)
		},

		/**
		 * Archives the post, or brings it back. It leaves the current
		 * timeline as soon as the server agrees.
		 *
		 * @return {Promise<void>}
		 */
		async toggleArchive() {
			if (this.archiving) {
				return
			}

			this.archiving = true
			const archived = this.item.archived === true
			try {
				await axios.post(generateUrl('apps/social/api/pixelfed/v1/archive/' + (archived ? 'remove' : 'add') + '/' + this.item.id))
				if (archived) {
					this.timelineStore.updateStatusArchived({ statusId: this.item.id, archived: false })
					showSuccess(t('social', 'The post is back on your profile'))
				} else {
					this.timelineStore.removeStatus(this.item)
					this.timelineStore.forgetBoosts(this.item)
					showSuccess(t('social', 'Archived. It is off your profile and out of the timelines; nobody else was told.'))
				}
			} catch (error) {
				logger.error('Failed to archive a post', { error })
				showError(t('social', 'Could not archive the post'))
			} finally {
				this.archiving = false
			}
		},

		/**
		 * Puts the reaction bar the server returned into the store, so every
		 * card showing the same post agrees.
		 *
		 * @param {Array<{name: string, count: number, me: boolean}>} reactions the bar
		 */
		onReactionsChanged(reactions) {
			this.timelineStore.updateStatusReactions({ statusId: this.item.id, reactions })
		},

		/**
		 * @param {import('../types/Mastodon.js').Status} status the post the keyboard moved to
		 */
		rememberFocus(status) {
			this.hasKeyboardFocus = status?.id === this.item.id
		},

		likeIfFocused() {
			if (this.hasKeyboardFocus) {
				this.like()
			}
		},

		boostIfFocused() {
			if (this.hasKeyboardFocus && (this.item.visibility === 'public' || this.item.visibility === 'unlisted')) {
				this.boost()
			}
		},

		replyIfFocused() {
			if (this.hasKeyboardFocus) {
				this.reply()
			}
		},

		openIfFocused() {
			if (this.hasKeyboardFocus) {
				this.getSinglePostTimeline()
			}
		},

		/**
		 * A press on the card opens the post, unless it was meant for something
		 * interactive inside it or ends a text selection.
		 *
		 * @param {MouseEvent} event the press
		 */
		onPostClick(event) {
			if (this.postHref && !event.defaultPrevented && event.button === 0
				&& !this.pressedControl(event)
				&& (window.getSelection?.()?.toString() ?? '') === '') {
				if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
					return
				}
				window.location.assign(this.postHref)
				return
			}
			if (this.postRoute === null || event.defaultPrevented || event.button !== 0) {
				return
			}
			// a modified click asks for a tab, and the card is not a link
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			if (this.pressedControl(event)) {
				return
			}
			if ((window.getSelection?.()?.toString() ?? '') !== '') {
				return
			}

			this.$router.push(this.postRoute)
		},

		/**
		 * Whether a press was meant for something interactive inside the post.
		 * Read from the event's path, because the heart swaps its icon while
		 * the click is still bubbling and a detached target is in no button.
		 *
		 * @param {MouseEvent} event the press
		 * @return {boolean}
		 */
		pressedControl(event) {
			return isPostControl(event)
		},

		getSinglePostTimeline() {
			if (!this.item.account?.acct || this.item.id === undefined) {
				logger.warn('Cannot open a post without an account and an id', { post: this.item })
				return
			}

			this.$router.push({
				name: 'single-post',
				params: {
					// acct, not username: two remote accounts can share a
					// username, and the route has to name one of them
					account: this.item.account.acct,
					id: this.item.id,
					type: 'single-post',
				},
			})
		},

		userDisplayName(actorInfo) {
			return actorInfo.name !== '' ? actorInfo.name : actorInfo.preferredUsername
		},

		reply() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-reply', this.item)
		},

		quote() {
			this.timelineStore.setComposerDisplayStatus(true)
			eventBus.emit('composer-quote', this.item)
		},

		/**
		 * Blocks the author, once the dialog has agreed; the store takes their
		 * posts out of every timeline.
		 */
		async blockAuthor() {
			const relationship = await this.accountStore.blockAccount({ id: this.item.account.id })
			// the store said what went wrong; the dialog stays for another try
			if (relationship?.id) {
				this.showBlockDialog = false
				showSuccess(t('social', 'You have blocked {account}', { account: this.item.account.acct }))
			}
		},

		async boost() {
			const undo = this.isBoosted
			await this.act('boost', undo ? 'postUnBoost' : 'postBoost', !undo)
		},

		/**
		 * @param {boolean} open whether the menu was opened or closed
		 */
		onMenuToggle(open) {
			this.menuOpen = open
			if (open) {
				this.menuOpener = focusedElement()
				this.loadRecommendationContext()
			}
		},

		async loadRecommendationContext() {
			if (!this.canUseRecommendationFeedback || this.recommendationContext !== null || this.recommendationContextLoading) {
				return
			}
			this.recommendationContextLoading = true
			try {
				this.recommendationContext = await fetchRecommendationContext(this.item.nid)
			} catch (error) {
				logger.warn('Could not load recommendation feedback context', { error })
			} finally {
				this.recommendationContextLoading = false
			}
		},

		async applyRecommendationFeedback(kind) {
			if (!this.canUseRecommendationFeedback || !this.item.nid) {
				return
			}
			try {
				const result = await recordRecommendationFeedback(this.item.nid, kind)
				const store = this.timelineStore
				const identity = store.getTimelineIdentity
				const entryId = store.timeline.find((id) => id === this.item.id || store.statuses[id]?.reblog?.id === this.item.id)
				const entry = entryId === undefined ? null : (store.statuses[entryId] ?? null)
				const index = entryId === undefined ? -1 : store.timeline.indexOf(entryId)
				const inPersonalizedFeed = isPersonalizedFeed({ type: this.type, params: store.params })
				if (kind === 'HIDE_POST' && entry !== null && inPersonalizedFeed) {
					store.removeStatus(entry, false)
					store.forgetRemoval(entry)
				}

				const restore = () => {
					if (kind === 'HIDE_POST' && entry !== null && inPersonalizedFeed && store.getTimelineIdentity === identity) {
						store.restoreStatus(entry, index)
					}
				}
				const message = kind === 'HIDE_POST'
					? t('social', 'Post hidden from For you')
					: t('social', 'Your feedback was saved for future recommendations')
				showUndo(message, async () => {
					try {
						await undoRecommendationFeedback(result.feedback_id)
						restore()
					} catch (error) {
						logger.error('Could not undo recommendation feedback', { error })
						showError(t('social', 'Could not undo this recommendation feedback'))
					}
				})
			} catch (error) {
				logger.error('Could not save recommendation feedback', { error })
				showError(t('social', 'Could not save your recommendation feedback'))
			}
		},

		editPost() {
			eventBus.emit('composer-edit', { edit: this.item, opener: this.menuOpener })
		},

		/** Deletes the post here and everywhere it was delivered. */
		async remove() {
			this.showDeleteDialog = false
			await this.timelineStore.postDelete(this.item)
		},

		/**
		 * Asks the server to translate the post, or puts the original back.
		 * The translation is kept on the card, so it is asked for once.
		 */
		async toggleTranslation() {
			if (this.translation !== null) {
				this.translation = null

				return
			}

			if (this.translating) {
				return
			}

			this.translating = true
			try {
				const { data } = await axios.post(generateUrl('/apps/social/api/v1/statuses/{id}/translate', { id: this.item.id }))
				this.translation = data
			} catch (error) {
				// 503: the server has no translation provider
				if (error?.response?.status === 503) {
					showError(t('social', 'This server cannot translate posts yet'))
				} else {
					showError(t('social', 'Could not translate this post'))
					logger.error('Failed to translate a post', { error })
				}
			} finally {
				this.translating = false
			}
		},

		/**
		 * Tells the store about a vote cast on this component's copy of the poll.
		 *
		 * @param {object} poll the poll as the server returned it after voting
		 */
		updatePoll(poll) {
			this.localPoll = poll
			this.timelineStore.updateStatusPoll({ statusId: this.item.id, poll })
		},

		/** Hands the post's address to the system share sheet on touch devices, or copies it. */
		async share() {
			const url = this.postLink
			try {
				if (typeof navigator.share === 'function' && window.matchMedia?.('(pointer: coarse)').matches) {
					await navigator.share({ url })
					return
				}
				await navigator.clipboard.writeText(url)
				showSuccess(t('social', 'Link copied'))
			} catch (error) {
				if (error?.name !== 'AbortError') {
					showError(t('social', 'Could not copy — select the address and copy it yourself'))
				}
			}
		},

		toggleBookmark() {
			this.timelineStore.postBookmark({ status: this.item, bookmarked: !this.item.bookmarked })
		},

		toggleConversationMute() {
			this.timelineStore.postMuteConversation({ status: this.item, muted: !this.item.muted })
		},

		togglePin() {
			this.timelineStore.postPin({ status: this.item, pinned: !this.item.pinned })
		},

		async like() {
			// a hold has already acted; this click must not also like
			if (this.held) {
				this.held = false

				return
			}
			const undo = this.isLiked
			await this.act('like', undo ? 'postUnlike' : 'postLike', !undo)
		},

		/** Holding the heart (or the right mouse button) offers the reactions. */
		startHold() {
			this.endHold()
			this.holdTimer = window.setTimeout(() => {
				this.holdTimer = null
				this.held = true
				this.askForReaction()
			}, HOLD_MS)
		},

		/** The hold ended, one way or another. */
		endHold() {
			if (this.holdTimer !== null) {
				window.clearTimeout(this.holdTimer)
				this.holdTimer = null
			}
		},

		/** Opens the reaction bar's emoji picker. */
		askForReaction() {
			if (this.serverData.public) {
				return
			}

			// the reaction bar owns the request, the counts and the failure
			/** @type {{askForPicker?: () => void}|undefined} */ (this.$refs.reactionBar)?.askForPicker?.()
		},

		/**
		 * Applies like or boost optimistically; the store rolls it back if the
		 * server refuses, and the button shows the refusal.
		 *
		 * @param {string} name 'like' or 'boost', the class hook
		 * @param {string} action the store action to dispatch
		 * @param {boolean} celebrating whether this is the doing, not the undoing
		 */
		async act(name, action, celebrating) {
			// ignore a second press while the first is on its way
			if (this.acting.has(name)) {
				return
			}
			this.acting.add(name)
			try {
				await this.send(name, action, celebrating)
			} finally {
				this.acting.delete(name)
			}
		},

		/**
		 * @param {string} name 'like' or 'boost', the class hook
		 * @param {string} action the store action to dispatch
		 * @param {boolean} celebrating whether this is the doing, not the undoing
		 */
		async send(name, action, celebrating) {
			if (celebrating) {
				this.celebrate = name
				// heard and felt as well as seen, where the reader allows it
				feel(name)
				// long enough for the like's petals to fall away
				window.setTimeout(() => {
					if (this.celebrate === name) {
						this.celebrate = ''
					}
				}, 1000)
			}

			const response = await this.timelineStore[action]({ status: this.item })
			if (response === undefined) {
				this.celebrate = ''
				this.refused = name
				window.setTimeout(() => {
					if (this.refused === name) {
						this.refused = ''
					}
				}, 400)
			}
		},
	},
}

</script>

<style scoped lang="scss" src="./TimelinePost.scss"></style>
