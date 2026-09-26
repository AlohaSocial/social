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
		<div class="post-header">
			<div v-if="!hideAuthor" class="post-author-wrapper" :title="item.account.acct">
				<component
					:is="isLocalAccount(item.account) ? 'a' : 'router-link'"
					v-if="item.account"
					:href="isLocalAccount(item.account) ? localProfileUrl(item.account) : undefined"
					:to="isLocalAccount(item.account) ? undefined : { name: 'profile', params: { account: item.account.acct } }">
					<span class="post-author">
						<DisplayName :text="item.account.display_name" :emojis="item.account.emojis" />
					</span>
					<!-- The handle is what the byline falls back to, not what it
					     carries: it repeats under the pointer as the wrapper's
					     own `title`, and again in the card that opens when the
					     avatar or the name is hovered. An account with no
					     display name has nothing else to be called, so there it
					     is the byline. -->
					<span v-if="!hasDisplayName" class="post-author-id">
						@{{ item.account.username }}
					</span>
				</component>
			</div>
			<a
				v-if="postHref"
				:href="postHref"
				:data-timestamp="timestamp"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })">
				{{ relativeTimestamp }}
			</a>
			<button
				v-else
				:data-timestamp="timestamp"
				type="button"
				class="post-timestamp live-relative-timestamp"
				:title="formattedDate"
				:aria-label="t('social', 'Open this post, written {time}', { time: formattedDate })"
				@click="getSinglePostTimeline">
				{{ relativeTimestamp }}
			</button>
			<span v-if="item.pinned" class="post-pinned" :title="t('social', 'Pinned post')">
				<Pin :size="14" />
				{{ t('social', 'Pinned') }}
			</span>
			<!-- the byline is 12px text; a 22px globe beside it read as the
			     loudest thing in the row, and it is the least important -->
			<VisibilityIcon
				v-if="visibility"
				:title="visibility.text"
				class="post-visibility"
				:size="14"
				:visibility="visibility.id" />
			<!-- where it was taken, when the poster said: a place is never
			     inferred, so this is only ever what somebody chose to say -->
			<router-link
				v-if="item.place && item.place.id"
				class="post-place"
				:to="{ name: 'place', params: { id: item.place.id } }"
				:title="t('social', 'Posts from {place}', { place: placeLabel })">
				<MapMarkerOutline :size="14" />
				<span class="post-place__name">{{ placeLabel }}</span>
			</router-link>
		</div>
		<!-- who is in the picture, when the poster named anybody. Names rather
		     than boxes drawn over the image: what is stored is a fact about the
		     post, and a rectangle is a thing no client of this network draws -->
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
			<!-- the whole remedy for being in somebody else's photograph:
			     leaving it, which needs nobody's permission -->
			<NcButton
				v-if="isTagged"
				variant="tertiary-no-background"
				class="post-tagged__leave"
				:disabled="untagging"
				@click="untagMe">
				{{ t('social', 'Remove me') }}
			</NcButton>
		</p>
		<div v-if="isEditing" class="post-edit-inline">
			<input
				v-model="editSpoiler"
				type="text"
				class="post-edit-warning"
				maxlength="200"
				:aria-label="t('social', 'Content warning')"
				:placeholder="t('social', 'Content warning, e.g. what the post is about')">
			<textarea
				ref="editInput"
				v-model="editContent"
				class="post-edit-textarea"
				:maxlength="maxLength"
				:aria-describedby="editIsTooLong ? `post-edit-count-${item.id}` : undefined"
				:placeholder="t('social', 'Edit your post')"
				@keydown.ctrl.enter="saveEdit" />
			<div class="post-edit-actions">
				<span
					:id="`post-edit-count-${item.id}`"
					class="post-edit-count"
					:class="{ 'post-edit-count--over': editIsTooLong }"
					role="status">
					{{ editCharactersLeftLabel }}
				</span>
				<NcButton
					variant="primary"
					:disabled="!editCanSave"
					:aria-label="t('social', 'Save')"
					@click="saveEdit">
					{{ t('social', 'Save') }}
				</NcButton>
				<NcButton
					:aria-label="t('social', 'Cancel')"
					@click="cancelEdit">
					{{ t('social', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		<!--
		  A filter the reader wrote themselves matched this post. Same shape as
		  the content warning below, and for the same reason: the body is not in
		  the page until it is asked for, so a word somebody filtered cannot be
		  read by accident on the way past.

		  Only `warn` filters reach this. A `hide` filter is applied by the
		  server, which never sends the status at all.

		  Lifting it falls through to the branches below rather than rendering
		  the body here, so a post carrying both a filter and a content warning
		  is still covered by the warning afterwards — the author's cover is not
		  the reader's to lift.
		-->
		<div v-else-if="filterCovers" class="post-filtered">
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
		<!--
		  A content warning covers the post, not only its text: the pictures,
		  the poll and the link preview used to be siblings rendered
		  unconditionally, so the one thing the feature exists to prevent
		  happened anyway.
		-->
		<div v-else-if="hasSpoiler" class="post-warning">
			<p class="post-warning__text">
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
		  A post carrying pictures is read pictures first: they lead at the
		  width of the card and the text reads as their caption. A warned post
		  is not, because its cover has to come before anything it covers.
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
		<!-- a translation is somebody else's words put through a machine, and
		     a reader is entitled to know that is what they are reading. Not
		     under a post a filter is covering: there is no body there to have
		     been translated -->
		<p v-if="translation !== null && !filterCovers" class="post-translated">
			{{ translatedFrom === ''
				? t('social', 'Translated by {provider}', { provider: translation.provider || t('social', 'this server') })
				: t('social', 'Translated from {language} by {provider}', {
					language: translatedFrom,
					provider: translation.provider || t('social', 'this server'),
				}) }}
		</p>
		<template v-if="mediaRevealed && !filterCovers">
			<QuotedPost v-if="item.quote" :quote="item.quote" />
			<Poll v-if="localPoll" :poll="localPoll" @update:poll="updatePoll" />
			<PostAttachment
				v-if="hasAttachments && !mediaLeads"
				:to="mediaRoute"
				:video="item.video"
				:attachments="item.media_attachments || []" />
			<PostCard v-if="showCard" :card="item.card" />
		</template>
		<!-- not when there is a content warning: that already renders a
		     "Show more" for the very same flag, so a post with both offered
		     two buttons for one reveal. No aria-expanded either — this
		     control is gone the moment it would have to say "true". And not
		     when the media leads, which shows this same reveal in the place
		     the pictures will take. -->
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
		<!-- One row across the foot of the card: the reactions somebody left,
		     then the counts and the controls at the far end. They were two
		     rows while the second one was revealed by the pointer and had to
		     live in the padding; now that the counts are always drawn there is
		     no reason for the card to carry the height of both. -->
		<div
			v-if="$route && $route.params.type !== 'notifications' && type !== 'direct'"
			class="post-footer">
			<ReactionBar
				ref="reactionBar"
				:statusId="String(item.id || '')"
				:modelValue="item.reactions || []"
				:canReact="!serverData.public"
				@update:modelValue="onReactionsChanged" />
			<!-- The counts are always here; the controls arrive with the
			     pointer. Nothing widens and nothing moves — see the stylesheet
			     for what is at rest and what is revealed. -->
			<div
				v-if="!serverData.public"
				class="post-actions-reveal"
				:class="{ 'post-actions-reveal--held': menuOpen }">
				<div class="post-actions">
					<!-- first in the row, not last: it reserves its width either way,
					     and spending that width on the left leaves the counts flush
					     with the card's right edge. The menu opens in a portal, so the
					     pointer leaving the card while it is open would take the row it
					     belongs to away -->
					<PostMenu
						:item="item"
						:currentAccount="currentAccount"
						:isPublic="serverData.public"
						:canTranslate="canTranslate"
						:translating="translating"
						:translated="translation !== null"
						:archiving="archiving"
						:interests="serverData.interests"
						@update:open="menuOpen = $event"
						@quote="quote"
						@edit="editPost"
						@archive="toggleArchive"
						@manageQuotes="managingQuotes = true"
						@tagPeople="taggingPeople = true"
						@delete="askToDelete(false)"
						@redraft="askToDelete(true)"
						@translate="toggleTranslation"
						@delivery="showDeliveryDialog = true"
						@bookmark="toggleBookmark"
						@collect="showCollectionDialog = true"
						@pin="togglePin"
						@lessLikeThis="lessLikeThis"
						@mute="showMuteDialog = true"
						@block="showBlockDialog = true"
						@report="showReportDialog = true" />
					<div class="post-actions__groups">
						<div class="post-action-group">
							<NcButton
								v-if="canReply"
								:title="t('social', 'Reply')"
								:aria-label="t('social', 'Reply')"
								variant="tertiary"
								@click="reply">
								<template #icon>
									<Reply :size="20" />
								</template>
							</NcButton>
							<RollingCount :count="item.replies_count || 0" />
						</div>
						<div
							class="post-action-group"
							:class="{ 'post-action-group--refused': refused === 'boost' }">
							<NcButton
								v-if="canBoost"
								:title="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
								:aria-label="isBoosted ? t('social', 'Undo boost') : t('social', 'Boost')"
								:aria-pressed="isBoosted ? 'true' : 'false'"
								variant="tertiary"
								:class="{ 'post-action--spun': celebrate === 'boost' }"
								@click="boost">
								<template #icon>
									<Repeat :size="20" :fillColor="isBoosted ? 'var(--color-primary)' : 'var(--color-main-text)'" />
								</template>
							</NcButton>
							<RollingCount :count="item.reblogs_count || 0" />
						</div>
						<div
							class="post-action-group post-action-group--like"
							:class="{ 'post-action-group--refused': refused === 'like' }">
							<template v-if="celebrate === 'like'">
								<span class="post-action__burst" aria-hidden="true" />
								<!-- six sparks rather than one ring: a ring says
								     "pressed", sparks say "yes". They are the
								     author's colour, which is the same colour
								     their avatar, their story ring and their
								     messages are. -->
								<span
									v-for="spark in SPARKS"
									:key="spark"
									class="post-action__spark"
									:style="{ '--spark': spark }"
									aria-hidden="true" />
							</template>
							<!-- one button whose label changes, not two swapped by v-if:
							     unmounting the button someone just pressed drops their focus
							     to the body and loses their place in the timeline -->
							<NcButton
								v-if="canLike || isLiked"
								:title="isLiked ? t('social', 'Undo Like') : t('social', 'Like')"
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
							<RollingCount :count="item.favourites_count || 0" />
						</div>
						<!-- only ever on the author's own copy: the server sends
						     `view_count` as null on everybody else's, because how
						     many people read a post is the author's business -->
						<div
							v-if="item.view_count !== null && item.view_count !== undefined"
							class="post-action post-action--views"
							:title="n('social', '%n account here opened this post', '%n accounts here opened this post', item.view_count)">
							<IconEyeOutline :size="20" />
							<RollingCount :count="item.view_count" />
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
		<NcDialog
			v-model:open="showBlockDialog"
			:name="t('social', 'Block {account}?', { account: item.account.acct })"
			:buttons="blockButtons">
			<p class="report-hint">
				{{ t('social', 'Their posts leave your timelines, they are unfollowed both ways, and they can no longer follow you or see your posts.') }}
			</p>
		</NcDialog>
		<NcDialog
			v-model:open="showReportDialog"
			:name="t('social', 'Report {account}', { account: item.account.acct })"
			:buttons="reportButtons">
			<p class="report-hint">
				{{ t('social', 'The report goes to the moderators of this instance. It is never sent to the reported account or their server.') }}
			</p>
			<textarea
				v-model="reportComment"
				class="report-comment"
				:placeholder="t('social', 'Why are you reporting this post? (optional)')"
				rows="3" />
		</NcDialog>
		<DeliveryDialog
			v-model:open="showDeliveryDialog"
			:statusId="item.id" />
		<!-- deleting is irreversible and federates: it is not something to
		     do on the first click of a menu item sitting under "Edit" -->
		<NcDialog
			v-model:open="showDeleteDialog"
			:name="deleteToRedraft ? t('social', 'Delete and write it again?') : t('social', 'Delete this post?')"
			:buttons="deleteButtons">
			<p class="delete-hint">
				{{ deleteToRedraft
					? t('social', 'The post is removed everywhere it reached, and its words, pictures and content warning are put back in the composer. What you post next is a new post: the boosts, likes and replies this one collected stay with it and are gone.')
					: t('social', 'The post is removed from this server and a deletion is sent to every server that received it. This cannot be undone.') }}
			</p>
		</NcDialog>
	</article>
</template>

<script>

// side-effect imports: they register the mention plugin and the string
// interface that the rendered content relies on
import { fromNow, fullDateTime } from '../utils/relativeTime.js'
import 'linkify-plugin-mention'
import 'linkify-string'
import IconAccountBoxMultiple from 'vue-material-design-icons/AccountBoxMultiple.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import DeliveryDialog from './DeliveryDialog.vue'
import PostAttachment from './PostAttachment.vue'
import PostMenu from './PostMenu.vue'
import PostCard from './PostCard.vue'
import ReactionBar from './ReactionBar.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import IconEyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../services/toast.js'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import Reply from 'vue-material-design-icons/Reply.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import eventBus from '../services/eventBus.js'
import { accountStyle } from '../services/accountColour.js'
import logger from '../services/logger.js'
import { onTick } from '../services/clock.js'
import { filterCoverLabel, matchedFilters } from '../utils/filters.js'
import { localProfileUrl } from '../utils/accountProfileLink.js'
import { allowedByAuthor, isShareable } from '../utils/interactionPolicy.js'
import { lessLikeThisFromPost } from '../services/interestFeedback.js'
import MessageContent from './MessageContent.js'
import Poll from './Poll.vue'
import QuotedPost from './QuotedPost.vue'
import RollingCount from './RollingCount.vue'
import DisplayName from './DisplayName.js'
import visibilitiesInfo from './Visibility/VisibilitiesInfos.js'
import VisibilityIcon from './Visibility/VisibilityIcon.vue'
import { mapStores } from 'pinia'
import { htmlToPlainText } from '../utils/plainText.js'
import { defaultLanguage, languageName } from '../utils/postLanguage.js'
import { useAccountStore } from '../store/account.js'
import { feel } from '../services/senses.js'
import { useInstanceStore } from '../store/instance.js'
import { useTimelineStore } from '../store/timeline.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'
import { useServerData } from '../composables/useServerData.js'
import { defineAsyncComponent } from 'vue'

// The mute dialog is the same one the profile opens, and it is worth nothing
// until somebody asks for it: the post menu is on every post on the page.
const MuteDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./MuteDialog.vue'))
// fetched with the other dialogs a post rarely opens, for the same reason
const CollectionPickerDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./CollectionPickerDialog.vue'))
// and the same for naming the people in a photograph, which is a thing an
// author does once per post and no reader ever does
const TagPeopleDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./TagPeopleDialog.vue'))
// same chunk, and for the same reason: a dialog nobody opens until they ask for
// it, which brings framework form controls with it
const QuoteControlDialog = defineAsyncComponent(() => import(/* webpackChunkName: "account-dialogs" */'./QuoteControlDialog.vue'))

/** How long the heart is held before it offers the reactions. */
const HOLD_MS = 450

export default {
	name: 'TimelinePost',
	components: {
		DeliveryDialog,
		IconAccountBoxMultiple,
		Pin,
		QuoteControlDialog,
		TagPeopleDialog,
		IconEyeOutline,
		CollectionPickerDialog,
		MapMarkerOutline,
		MuteDialog,
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
		DisplayName,
		VisibilityIcon,
	},

	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
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
			isEditing: false,
			/** the spark directions, so the template does not build a list per render */
			SPARKS: [0, 1, 2, 3, 4, 5],
			/** the press-and-hold timer on the heart, null when nothing is held */
			holdTimer: null,
			/** set by a hold, so the click it ends with does not also like the post */
			held: false,
			/** which action is playing its confirmation, '' when none */
			celebrate: '',
			/** which action the server refused, so the button can say so */
			refused: '',
			/** whether j/k has this post, so l/b/r act on the right one */
			hasKeyboardFocus: false,
			/** whether the overflow menu is open, which holds the action row open with it */
			menuOpen: false,
			/** a warned post stays closed until the reader opens it */
			warningLifted: false,
			/** and so does one the reader's own keyword filters matched */
			filterLifted: false,
			editContent: '',
			editSpoiler: '',
			showReportDialog: false,
			showMuteDialog: false,
			showCollectionDialog: false,
			showBlockDialog: false,
			showDeleteDialog: false,
			archiving: false,
			untagging: false,
			taggingPeople: false,
			managingQuotes: false,
			/** whether the delete on screen is the first half of a re-draft */
			deleteToRedraft: false,
			/** the Translation entity once it has arrived, null before */
			translation: null,
			/** whether the provider is working on it right now */
			translating: false,
			showDeliveryDialog: false,
			reportComment: '',
			localPoll: this.item?.poll ?? null,
			/** re-read from the shared clock, so "5 minutes ago" stays true */
			now: Date.now(),
			/** unsubscribe from the shared clock */
			stopTicking: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useInstanceStore, useTimelineStore),

		/**
		 * Whether this is the post the reader has just published, so it
		 * arrives at the top of the timeline rather than simply being there.
		 *
		 * @return {boolean}
		 */
		hasJustArrived() {
			return this.timelineStore.arrivedId !== '' && this.timelineStore.arrivedId === String(this.item?.id ?? '')
		},

		/** @return {number} what the server accepts in one status, as the composer shows it */
		maxLength() {
			return this.instanceStore.maxCharacters
		},

		/**
		 * Whether the byline has a name of its own to show.
		 *
		 * Trimmed rather than tested for truth: a display name of one space is
		 * a name a remote server will happily federate, and it would draw a
		 * byline that is blank rather than one that falls back.
		 *
		 * @return {boolean}
		 */
		hasDisplayName() {
			return (this.item.account?.display_name ?? '').trim() !== ''
		},

		/**
		 * Where this post lives, or `null` when the reader is already there.
		 *
		 * A post in a timeline is a link to itself: a press anywhere on it
		 * that is not a link or a button opens it with its replies, and its
		 * pictures and videos open from there. The post whose page this is
		 * behaves the other way round — the picture opens full size, the video
		 * plays — because that is what the reader came for.
		 *
		 * A reply on that page is another post, so it links to its own page
		 * like any other.
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
		 * the post. Only `warn` filters reach a client — a status a `hide`
		 * filter matched is never sent — so anything matched is covered here
		 * rather than dropped.
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
			return this.hasAttachments || this.localPoll !== null || this.showCard || this.hasQuote
		},

		/** @return {boolean} the post embeds another one, whatever came of it */
		hasQuote() {
			return Boolean(this.item.quote)
		},

		/**
		 * What this reader has asked for, or what the instance does for
		 * somebody who has not asked. PeerTube's three NSFW policies, under
		 * the names Mastodon's `reading:expand:media` already uses for the
		 * same three states.
		 *
		 * @return {string} 'show_all', 'default' or 'hide_all'
		 */
		nsfwPolicy() {
			const policy = this.serverData?.nsfwPolicy

			return ['show_all', 'default', 'hide_all'].includes(policy) ? policy : 'default'
		},

		/**
		 * @return {boolean} whether the media sits behind a reveal. A warning
		 * covers the whole post; `sensitive` on its own covers only the media,
		 * which is what Mastodon shows for a post flagged without a warning.
		 *
		 * A content warning is **not** subject to the policy: the policy is
		 * about media somebody marked sensitive, and a warning is an author
		 * saying something about the whole post in their own words. A reader
		 * who asked to see sensitive media has not asked to be shown past
		 * every warning anybody writes.
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
		 * @return {boolean} whether there is a button to lift the cover.
		 * Under `hide_all` there is not: opening the post itself is what it
		 * takes, which is the difference between that policy and the covered
		 * one. A content warning keeps its own reveal either way.
		 */
		mediaRevealable() {
			return this.hasSpoiler || this.nsfwPolicy !== 'hide_all'
		},

		/** @return {boolean} */
		mediaRevealed() {
			return !this.hasGatedMedia || (this.warningLifted && this.mediaRevealable)
		},

		/**
		 * @return {boolean} whether the post is laid out around its pictures.
		 * Not while it is being edited, where the text is the thing being
		 * worked on, and not under a content warning, which owns the top of
		 * the post until the reader lifts it.
		 */
		mediaLeads() {
			return this.hasAttachments && !this.hasSpoiler && !this.isEditing
		},

		/** @return {number} how many characters the edit has left */
		editCharsLeft() {
			return this.maxLength - this.editContent.length
		},

		/** @return {boolean} */
		editIsTooLong() {
			return this.editCharsLeft < 0
		},

		/** @return {boolean} */
		editCanSave() {
			return this.editContent.trim() !== '' && !this.editIsTooLong
		},

		/** @return {string} */
		editCharactersLeftLabel() {
			return this.editIsTooLong
				? n('social', '%n character too many', '%n characters too many', -this.editCharsLeft)
				: n('social', '%n character left', '%n characters left', this.editCharsLeft)
		},

		/** Who wrote it, so moving between posts by landmark says something. */
		postLabel() {
			return t('social', 'Post by {account}', { account: this.item.account?.acct ?? '' })
		},

		/** @return {boolean} a link preview replaces nothing, so media wins */
		showCard() {
			return !this.hasAttachments && Boolean(this.item.card?.title)
		},

		/** @return {boolean} whether there is a picture to name anybody in */
		hasPictures() {
			return (this.item.media_attachments ?? []).length > 0
		},

		/** @return {Array} the people the poster named in this post's pictures */
		taggedPeople() {
			return this.item.tagged_people ?? []
		},

		/** @return {boolean} whether the reader is one of them */
		isTagged() {
			const me = this.currentAccount?.acct
			return Boolean(me) && this.taggedPeople.some((person) => person.acct === me)
		},

		/** @return {string} the place, with its country where one was given */
		placeLabel() {
			const place = this.item.place
			if (!place) {
				return ''
			}

			return place.country ? `${place.name}, ${place.country}` : place.name
		},

		/**
		 * @return {boolean} whether the author allows replies to this post.
		 * True unless their server said otherwise: most servers publish no
		 * policy at all, and a post with none is a post anybody may answer.
		 */
		canReply() {
			return allowedByAuthor(this.item, 'reply')
		},

		/**
		 * @return {boolean} whether this post may be boosted — public or
		 * unlisted, which is what the server grants, and not refused by the
		 * author's own policy.
		 */
		canBoost() {
			return isShareable(this.item) && allowedByAuthor(this.item, 'boost')
		},

		/** @return {boolean} whether the author allows this post to be liked. */
		canLike() {
			return allowedByAuthor(this.item, 'like')
		},

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

		reportButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showReportDialog = false
					},
				},
				{
					label: t('social', 'Report'),
					variant: 'error',
					callback: () => this.sendReport(),
				},
			]
		},

		/**
		 * The post as it is shown: the translation when one has been asked for
		 * and arrived, the post itself otherwise.
		 *
		 * A copy rather than a write into the store's object: the translation
		 * belongs to this reader looking at this card, and writing it into the
		 * status would put it on every other card showing the same post and
		 * leave it there after a refresh.
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
		 * Whether to offer a translation: only where the server has a provider,
		 * and only for a post written in another language than the reader's.
		 *
		 * A post with no language is not offered either. The server stores what
		 * the author declared and nothing else — guessing here would offer to
		 * translate English into English for every post that arrived without a
		 * `contentMap`.
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

		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.showDeleteDialog = false
					},
				},
				{
					label: this.deleteToRedraft ? t('social', 'Delete & re-draft') : t('social', 'Delete'),
					variant: 'error',
					callback: () => this.remove(),
				},
			]
		},

		/**
		 * @return {string}
		 */
		relativeTimestamp() {
			return fromNow(this.item.created_at, new Date(this.now))
		},

		/**
		 * @return {string}
		 */
		formattedDate() {
			return fullDateTime(this.item.created_at)
		},

		/**
		 * @return {number}
		 */
		timestamp() {
			return Date.parse(this.item.created_at)
		},

		/**
		 * @return {boolean}
		 */
		hasAttachments() {
			// a post read straight off the composer has no `media_attachments`
			// at all until the server answers, so the list is defaulted rather
			// than assumed
			return (this.item.media_attachments || []).length > 0
		},

		/**
		 * @return {boolean}
		 */
		isBoosted() {
			return this.item.reblogged === true
		},

		/**
		 * @return {boolean}
		 */
		/**
		 * The card's own colour, which everything inside it can use.
		 *
		 * The author's, not the reader's: a post is the author speaking, and the
		 * sparks a like throws are theirs. See services/accountColour.js.
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
			return !this.item.account.acct.includes('@')
		},

		/** @return {import('../types/Mastodon.js').Account} */
		currentAccount() {
			return this.accountStore.currentAccount
		},

		/** @return {boolean} */
		isNotification() {
			return this.item.type !== undefined
		},

		/** @return {object} */
		visibility() {
			return visibilitiesInfo.find(({ id }) => this.item.visibility === id)
		},
	},

	watch: {
		// a vote cast elsewhere (or reloaded from the server) has to reach the
		// copy this component renders, or navigating back shows the poll unvoted
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
		this.stopTicking = onTick((now) => {
			this.now = now
		})
	},

	unmounted() {
		eventBus.off('timeline:focused', this.rememberFocus)
		eventBus.off('shortcut:like', this.likeIfFocused)
		eventBus.off('shortcut:boost', this.boostIfFocused)
		eventBus.off('shortcut:reply', this.replyIfFocused)
		eventBus.off('shortcut:open', this.openIfFocused)
		this.stopTicking?.()
	},

	methods: {
		localProfileUrl,
		isLocalAccount(account) {
			return Boolean(account?.acct && !account.acct.includes('@') && account.username)
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
		 * Puts the post away, or brings it back.
		 *
		 * The row leaves the timeline it is in as soon as the server agrees:
		 * the post is out of every list this server builds, and the list the
		 * reader is looking at is one of them.
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
		 * The reaction bar came back from the server after a press. It goes to
		 * the store rather than onto this card, so the same post shown twice —
		 * a thread and the timeline behind it — cannot end up with two
		 * different bars.
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
		 * @function getSinglePostTimeline
		 * @description Opens the conversation the post belongs to.
		 *
		 * Remote posts used to return here with a logger.warn, which made the
		 * timestamp — the only affordance for opening a thread — silently dead
		 * on the Global and Federated timelines. The server serves the context
		 * of any status it has (`/api/v1/statuses/{nid}/context`), local or not.
		 */
		/**
		 * A press somewhere on the post that was not meant for something else.
		 *
		 * The whole card opens the post, the way a row in a list opens the
		 * thing it stands for.
		 *
		 * Everything interactive inside a post keeps its press: a mention, a
		 * hashtag, a link somebody wrote, the action buttons, the poll, the
		 * author's name, the media — which has a handler of its own that routes
		 * to the same place anyway. What is left is the body of the card, and
		 * pressing that opens the post.
		 *
		 * A selection is left alone as well: dragging across a post to copy a
		 * sentence ends in a click, and navigating away from what somebody has
		 * just highlighted is the worst possible answer to it.
		 *
		 * @param {MouseEvent} event the press
		 */
		onPostClick(event) {
			if (this.postHref && !event.defaultPrevented && event.button === 0
				&& !event.target?.closest?.('a, button, input, textarea, select, label, video, audio, [role="button"], .post-actions, .v-popper')
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
			// a modified click is the reader asking for a tab or a window, and
			// the card is not a link, so there is nothing to hand them
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			if (event.target?.closest?.('a, button, input, textarea, select, label, video, audio, [role="button"], .post-actions, .v-popper')) {
				return
			}
			if ((window.getSelection?.()?.toString() ?? '') !== '') {
				return
			}

			this.$router.push(this.postRoute)
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
		 * Blocks the author, once the dialog has agreed. The store takes their
		 * posts out of every timeline, this one included, so there is nothing
		 * left here to say afterwards.
		 */
		async blockAuthor() {
			const relationship = await this.accountStore.blockAccount({ id: this.item.account.id })
			// the store said what went wrong; the dialog stays for another try
			if (relationship?.id) {
				this.showBlockDialog = false
				showSuccess(t('social', 'You have blocked {account}', { account: this.item.account.acct }))
			}
		},

		async sendReport() {
			try {
				await axios.post(generateUrl('apps/social/api/v1/reports'), {
					account_id: this.item.account.id,
					status_ids: [this.item.id],
					comment: this.reportComment,
				})
				showSuccess(t('social', 'Post reported to the moderators'))
				this.showReportDialog = false
				this.reportComment = ''
			} catch (error) {
				logger.error('Failed to report the post', { error })
				showError(t('social', 'Failed to report the post'))
			}
		},

		async boost() {
			const undo = this.isBoosted
			await this.act('boost', undo ? 'postUnBoost' : 'postBoost', !undo)
		},

		editPost() {
			// never the author's bio: an image-only post has no content, and
			// seeding the editor from account.note offered to publish it
			this.editContent = htmlToPlainText(this.item.content || '')
			this.editSpoiler = this.item.spoiler_text || ''
			this.isEditing = true
			this.$nextTick(() => {
				if (this.$refs.editInput) {
					this.$refs.editInput.focus()
				}
			})
		},

		async saveEdit() {
			if (!this.editCanSave) {
				return
			}

			const warning = this.editSpoiler.trim()
			const response = await this.timelineStore.postEdit({
				status: this.item,
				content: this.editContent.trim(),
				// fixing a typo used to un-hide sensitive content for every
				// follower, because the warning was always sent back empty
				spoiler_text: warning,
				sensitive: warning !== '' || this.item.sensitive === true,
			})
			if (response === undefined) {
				// the store already said so; keep what was typed
				return
			}

			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		cancelEdit() {
			this.isEditing = false
			this.editContent = ''
			this.editSpoiler = ''
		},

		/**
		 * Opens the confirmation. Deleting federates and cannot be undone, so
		 * neither half of this happens on the first click of a menu item.
		 *
		 * @param {boolean} redraft whether the words come back in the composer
		 */
		askToDelete(redraft) {
			this.deleteToRedraft = redraft
			this.showDeleteDialog = true
		},

		/**
		 * Deletes the post, and — for a re-draft — hands its words, pictures
		 * and warning to the composer.
		 *
		 * The composer is filled *after* the delete rather than before: a
		 * delete the server refuses leaves the post where it is, and a
		 * composer already holding its words would invite somebody to post it
		 * twice.
		 */
		async remove() {
			this.showDeleteDialog = false
			const redraft = this.deleteToRedraft
			this.deleteToRedraft = false

			// the post as it stands, kept before the store forgets it
			const draft = redraft ? this.item : null

			await this.timelineStore.postDelete(this.item)

			if (draft !== null) {
				this.timelineStore.setComposerDisplayStatus(true)
				eventBus.emit('composer-redraft', draft)
			}
		},

		/**
		 * Asks the server to translate the post, or puts the original back.
		 *
		 * Asked once per card: the answer is kept here, so pressing "Show
		 * original" and then "Translate" again costs nothing and does not send
		 * the same text through a provider twice.
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
				// 503 is the server saying it has no translation provider,
				// which is a different thing from the request failing
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
		 * A vote is cast on the component's own copy of the poll; the store
		 * holds the one every other view reads, so it hears about it too.
		 *
		 * @param {object} poll the poll as the server returned it after voting
		 */
		updatePoll(poll) {
			this.localPoll = poll
			this.timelineStore.updateStatusPoll({ statusId: this.item.id, poll })
		},

		toggleBookmark() {
			this.timelineStore.postBookmark({ status: this.item, bookmarked: !this.item.bookmarked })
		},

		togglePin() {
			this.timelineStore.postPin({ status: this.item, pinned: !this.item.pinned })
		},

		async like() {
			// a press that turned into a hold has already done something; liking
			// as well would be two answers to one gesture
			if (this.held) {
				this.held = false

				return
			}
			const undo = this.isLiked
			await this.act('like', undo ? 'postUnlike' : 'postLike', !undo)
		},

		/**
		 * Holding the heart asks which kind of yes.
		 *
		 * A like says "yes" and a reaction says which kind, and the reactions
		 * were behind a button of their own at the other end of the card. Under
		 * the thumb that is already on the heart is where every messaging app
		 * puts them, and the right mouse button does the same thing with a
		 * pointer.
		 */
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

		/**
		 * Opens the one emoji picker the page has; see ReactionPicker for why it
		 * is asked for over the bus rather than imported here.
		 */
		askForReaction() {
			if (this.serverData.public) {
				return
			}

			// the bar below owns what a reaction does -- the request, the
			// counts it answers with, the failure -- so this asks it to open
			// its own picker rather than sending anything itself
			this.$refs.reactionBar?.askForPicker?.()
		},

		/**
		 * Both actions are applied optimistically and rolled back by the store
		 * when the server refuses — which used to happen invisibly. Confirming
		 * one animation and refusing the other makes the difference legible.
		 *
		 * @param {string} name 'like' or 'boost', the class hook
		 * @param {string} action the store action to dispatch
		 * @param {boolean} celebrating whether this is the doing, not the undoing
		 */
		async act(name, action, celebrating) {
			if (celebrating) {
				this.celebrate = name
				// heard and felt as well as seen, where the reader allows it
				feel(name)
				window.setTimeout(() => {
					if (this.celebrate === name) {
						this.celebrate = ''
					}
				}, 600)
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

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/* the like confirmation: a short overshoot, not a bounce */
@keyframes post-pop {
	0% { transform: scale(1); }
	40% { transform: scale(1.35); }
	70% { transform: scale(.92); }
	100% { transform: scale(1); }
}

@keyframes post-burst {
	0% { transform: scale(.2); opacity: .55; }
	100% { transform: scale(2.4); opacity: 0; }
}

/* the sparks: out and a little up, shrinking as they go */
@keyframes post-spark {
	0% { transform: rotate(var(--angle)) translateY(0) scale(1); opacity: 1; }
	100% { transform: rotate(var(--angle)) translateY(-18px) scale(.2); opacity: 0; }
}

@keyframes post-spin {
	0% { transform: rotate(0); }
	100% { transform: rotate(360deg); }
}

/* the server refused: the optimistic change is being taken back */
@keyframes post-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

@include layout.below(layout.$phone) {
	.post-content {
		// the screen's width is the post's: less of it goes to the frame
		padding: 14px 16px 12px;
	}
}

.post-content--openable {
	cursor: pointer;
}

.post-content {
	/* the foot of the card is one row in flow, so the padding is padding
	   again: it holds nothing and needs only to look like the sides */
	padding: 18px 20px 14px;
	font-size: 15px;
	line-height: 1.65;
	border-radius: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	position: relative;
	z-index: 1;
	box-shadow: var(--social-elevation-resting);
	transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;

	&:hover {
		border-color: var(--color-primary-element);
		box-shadow: var(--social-elevation-raised);
		transform: translateY(-1px);
	}

	&:focus-within {
		border-color: var(--color-primary-element);
		box-shadow: 0 0 0 2px var(--color-primary-element-light);
	}

	/* The card keeps its own shape now. What used to happen here — the bottom
	   corners squaring off and the bottom border going transparent — was the
	   seam between the card and a panel as wide as it was. The pill is not that
	   wide and is not part of the card's outline, so there is no seam to hide.
	   The stacking order still has to change: the pill hangs into the gap and
	   the card below it comes later in the document. */
	&:hover,
	&:focus-within,
	&:has(.post-actions-reveal--held) {
		z-index: 4;
	}

	.post-header {
		display: flex;
		gap: 8px;
		align-items: baseline;
		margin-bottom: 10px;

		.post-author-wrapper {
			flex-grow: 1;
			min-width: 0;
			display: flex;
			align-items: baseline;

			.post-author {
				font-weight: 650;
				font-size: 14px;
				color: var(--color-main-text);
				letter-spacing: -.01em;
			}

			.post-author-id {
				font-size: 13px;
				color: var(--color-text-lighter);
				margin-inline-start: 6px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
		}

		// The row is aligned on the text baseline, which is right for the name
		// and the handle and wrong for anything that is an icon: an icon has no
		// text baseline of its own, so flexbox hangs it from its bottom edge and
		// it sits below the line it belongs on. These two carry icons, so they
		// are centred on the line instead.
		.post-place {
			display: inline-flex;
			gap: 3px;
			align-items: center;
			max-width: 40%;
			color: var(--color-text-maxcontrast);
			font-size: 12px;

			&:hover,
			&:focus-visible {
				text-decoration: underline;
			}
		}

		.post-tagged {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 4px;
			margin-block: 4px 0;
			color: var(--color-text-maxcontrast);
			font-size: 90%;
		}

		.post-tagged__person {
			color: var(--color-main-text);

			&:hover,
			&:focus-visible {
				text-decoration: underline;
			}
		}

		.post-place__name {
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		.post-visibility {
			color: var(--color-text-lighter);
			flex-shrink: 0;
			align-self: center;
		}

		.post-pinned {
			display: inline-flex;
			align-items: center;
			align-self: center;
			gap: 4px;
			flex-shrink: 0;
			font-size: 12px;
			// the same weight as the rest of the byline: the pin already says
			// this is a state, and a bold word beside grey text reads as the
			// loudest thing in a row that is all supporting detail
			font-weight: normal;
			color: var(--color-text-lighter);
		}

		.post-timestamp {
			// It opens the thread, so it stays a real button — reachable by
			// keyboard and announced as one. What it must not keep is the
			// chrome a bare <button> inherits from the server, which drew a
			// filled box around four characters of grey byline.
			background: none;
			border: none;
			border-radius: 0;
			padding: 0;
			margin: 0;
			min-height: 0;
			font-family: inherit;
			font-weight: normal;
			font-size: 12px;
			text-align: end;
			color: var(--color-text-lighter);
			white-space: nowrap;
			cursor: pointer;
			flex-shrink: 0;

			&:hover {
				color: var(--color-primary-element);
				background: none;
			}

			// the box was also the focus indicator; without one, a keyboard
			// reader loses the only affordance for opening a thread
			&:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 2px;
				border-radius: var(--border-radius-small, 4px);
			}
		}
	}

	.post-message {
		margin-bottom: 10px;
		overflow-wrap: break-word;
		overflow: visible;

		:deep(p) {
			margin: 0 0 8px;
			&:last-child {
				margin-bottom: 0;
			}
		}

		:deep(a) {
			overflow-wrap: anywhere;

			&:hover {
				text-decoration: underline;
			}
		}

		:deep(.mention) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(.hashtag) {
			color: var(--color-primary-element);
			font-weight: 500;
		}

		:deep(img:not(.emoji):not(.custom-emoji)) {
			max-width: 100%;
			height: auto;
			border-radius: 8px;
			margin: 12px 0;
			display: block;
		}

		// Emoji are inline words, including in remote ActivityPub statuses.
		// The generic image rule above is for content images and must never turn
		// each emoji into a block with its own line and large vertical margins.
		:deep(img.emoji) {
			display: inline-block;
			width: 1em;
			height: 1em;
			max-width: none;
			margin: 0 .08em;
			border-radius: 0;
			vertical-align: -.15em;
		}

		:deep(img.custom-emoji) {
			display: inline-block;
			width: auto;
			height: 1.25em;
			max-width: none;
			margin: 0 .08em;
			border-radius: 0;
			vertical-align: -.2em;
		}
	}

	.post-edit-inline {
		margin-bottom: 10px;

		.post-edit-textarea {
			width: 100%;
			min-height: 100px;
			padding: 8px;
			border: 1px solid var(--color-border);
			border-radius: 8px;
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-family: inherit;
			font-size: 15px;
			line-height: 1.65;
			resize: vertical;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-warning {
			width: 100%;
			margin-bottom: 6px;
			padding: 8px 10px;
			border: 1px solid var(--color-border);
			border-radius: var(--border-radius, 8px);
			background: var(--color-main-background);
			color: var(--color-main-text);
			font-size: 14px;
			box-sizing: border-box;

			&:focus-visible {
				border-color: var(--color-primary-element);
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		}

		.post-edit-actions {
			display: flex;
			gap: 8px;
			margin-top: 8px;
			align-items: center;
			justify-content: flex-end;
		}

		.post-edit-count {
			margin-inline-end: auto;
			font-size: 12px;
			color: var(--color-text-lighter);

			&--over {
				color: var(--color-error);
				font-weight: 600;
			}
		}
	}

	/*
	 * The action row is the loudest thing in a card and the least often used:
	 * somebody scrolling a timeline is reading, not boosting. So a card at rest
	 * carries nothing at all, and what arrives when the pointer does is one
	 * pill in the bottom-right corner: it fades in at the width of the overflow
	 * menu and widens to the left to let the rest of the row out.
	 *
	 * Only the pill changes size. The card does not grow, nothing below it
	 * moves, and — unlike the full-width panel this replaces — the pill is
	 * short enough to live in the card's own bottom padding and the fourteen
	 * pixels of gap below it, so it covers no part of the next card.
	 *
	 * A grid whose single column goes from `0fr` to `1fr` is what animates the
	 * width, for the same reason the height of a thing like this cannot be
	 * animated any other way: the row's width is not knowable in advance. A
	 * reply count going from 9 to 10 is another pixel, the icons are a
	 * translation away from being wider, and `width: auto` does not interpolate
	 * anywhere this app can rely on yet.
	 *
	 * The menu is deliberately outside the rail: it is the part the pill is as
	 * wide as when it arrives, and keeping it out of the animating column is
	 * what stops it drifting sideways while the pill opens.
	 *
	 * Nothing here is discoverable without a pointer, which is a real cost and
	 * a deliberate one — a mark on every card in a timeline is a hundred marks
	 * on a screen. Touch does not pay it: see the `hover: none` block at the
	 * end of this file, where the row is not a pill at all.
	 *
	 * **What is at rest and what arrives.** How many replies, boosts and
	 * likes a post has is *about the post* — it belongs to a reader running
	 * down a timeline deciding what to open, and hiding it until the pointer
	 * lands meant the only way to see which posts had landed was to point at
	 * each of them in turn. So the counts are always drawn, as type: no
	 * button, no border, no surface, with the glyphs dimmed to a hairline.
	 *
	 * What arrives on hover is the *controls* — the pill fades in behind the
	 * row at the size the row already occupies, and the glyphs come up to
	 * full. Nothing moves: no track widens, no digit shifts, and the pointer
	 * is never chasing a button that is still travelling. The one thing that
	 * stays lit at rest is a like or a boost this reader has already given,
	 * because that is state rather than chrome.
	 */
	/*
	 * One row across the foot: the reactions at the near end, the counts and
	 * their controls at the far one. It wraps, so a post with a dozen
	 * reactions puts the controls on a line of their own rather than crushing
	 * them, and `align-items: center` keeps the two halves on one baseline
	 * whatever height the reactions take.
	 */
	.post-footer {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 2px;
	}

	.post-footer--profile-actions {
		display: block;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	.post-actions-reveal {
		/* in flow now, at the far end of the footer. `relative` is what the
		   surface below is drawn against */
		position: relative;
		margin-inline-start: auto;
		display: flex;
		padding: 1px;
		border: 1px solid transparent;
		border-radius: 999px;
	}

	/*
	 * The surface, drawn *behind* the row at the size the row already has.
	 * A pseudo-element rather than the background, border and shadow of the
	 * row itself, so the whole thing arrives as one opacity — one compositor
	 * property, on a page that can be showing a hundred of these.
	 */
	.post-actions-reveal::before {
		content: '';
		position: absolute;
		inset: -1px;
		border-radius: 999px;
		background: var(--color-main-background);
		border: 1px solid var(--color-primary-element);
		box-shadow: var(--social-elevation-raised);
		opacity: 0;
		/* out in a tenth of a second and flat: a pointer crossing four cards
		   on its way somewhere else must not leave four pills fading behind it */
		transition: opacity .1s linear;
		pointer-events: none;
	}

	/*
	 * `focus-within` is not decoration here: it is the whole of the keyboard
	 * path. Tabbing into a card has to open the same pill the pointer does, or
	 * the controls are focusable and unmarked.
	 */
	&:hover .post-actions-reveal::before,
	&:focus-within .post-actions-reveal::before,
	.post-actions-reveal--held::before {
		opacity: 1;
		transition: opacity .2s ease;
	}

	/* the menu is a control rather than a fact about the post, so it keeps to
	   the same rule as the glyphs: out of the way until it is asked for */
	.post-actions :deep(.action-item),
	.post-actions :deep(.actions) {
		opacity: 0;
		transition: opacity .16s ease;
	}

	&:hover .post-actions :deep(.action-item),
	&:hover .post-actions :deep(.actions),
	&:focus-within .post-actions :deep(.action-item),
	&:focus-within .post-actions :deep(.actions),
	.post-actions-reveal--held :deep(.action-item),
	.post-actions-reveal--held :deep(.actions) {
		opacity: 1;
	}

	.post-actions__groups {
		display: flex;
		align-items: center;
		gap: 2px;
		min-inline-size: 0;
	}

	/* it had a top margin from when it was a row of its own */
	.post-footer :deep(.reaction-bar) {
		margin-top: 0;
	}

	&:hover .post-actions :deep(.button-vue__icon),
	&:focus-within .post-actions :deep(.button-vue__icon),
	.post-actions-reveal--held :deep(.button-vue__icon) {
		opacity: 1;
	}

	.post-actions {
		display: flex;
		align-items: center;
		gap: 2px;

		.post-action-group {
			display: inline-flex;
			align-items: center;
			gap: 4px;
		}

		.post-action-count {
			font-size: 12px;
			color: var(--color-text-lighter);
			min-width: 16px;
			text-align: center;
		}

		:deep(.button-vue) {
			border-radius: 8px;

			&:hover {
				background: var(--color-background-dark);
			}
		}

		/* a hairline at rest: enough to say what the number counts, not enough
		   to read as a button somebody should press */
		:deep(.button-vue__icon) {
			opacity: .38;
			transition: opacity .2s ease;
		}

		/* except a like or a boost this reader has already given. That is the
		   state of the post as far as they are concerned, and it is the one
		   thing on this row worth seeing without pointing at it. */
		:deep(.button-vue[aria-pressed="true"] .button-vue__icon) {
			opacity: 1;
		}

		/* 28px rather than the 34px a button is elsewhere: this is a
		   secondary row, and the pill has to be short enough to fit between
		   the text and the next card. Still above the 24px floor a pointer
		   target has. */
		:deep(.button-vue--icon-only) {
			min-height: 28px;
			min-width: 28px;
		}

		:deep(.button-vue) {
			border-radius: 999px;
		}
	}
}

.post-action-group {
	position: relative;

	&--refused :deep(button) {
		animation: post-refused .4s ease;
	}
}

.post-action--popped :deep(.material-design-icon) {
	animation: post-pop .45s cubic-bezier(.34, 1.56, .64, 1);
}

.post-action--spun :deep(.material-design-icon) {
	animation: post-spin .5s cubic-bezier(.4, 0, .2, 1);
}

/* the ring that expands out of the heart once */
.post-action__burst {
	position: absolute;
	top: 50%;
	inset-inline-start: 22px;
	width: 20px;
	height: 20px;
	margin: -10px 0 0 -10px;
	border-radius: 50%;
	background: var(--color-element-error);
	pointer-events: none;
	animation: post-burst .5s ease-out forwards;
}

/**
 * Six sparks around the heart, in the author's colour.
 *
 * The ring above says "pressed"; these say "yes". The colour comes from
 * `--account-hue`, which the card carries for its author, so a like on Maya's
 * post throws Maya's colour -- the same one her avatar, her story ring and her
 * messages are. A card that somehow has no hue falls back to the heart's own
 * red rather than to black.
 */
.post-action__spark {
	position: absolute;
	top: 50%;
	inset-inline-start: 22px;
	width: 5px;
	height: 5px;
	margin: -2px 0 0 -2px;
	border-radius: 50%;
	background: hsl(var(--account-hue, 355) 75% 55%);
	pointer-events: none;
	/* each spark is turned a sixth of the way round, and the odd ones start
	   fractionally later so the six do not read as a single expanding ring */
	--angle: calc(var(--spark) * 60deg);
	transform-origin: center;
	animation: post-spark .52s cubic-bezier(.2, .7, .3, 1) forwards;
	animation-delay: calc(var(--spark) * 12ms);
}

.post-pinned {
	animation: none;
}

@media (prefers-reduced-motion: reduce) {
	.post-content,
	.post-content:hover {
		transition: none;
		transform: none;
	}

	.post-action-group--refused :deep(button),
	.post-action--popped :deep(.material-design-icon),
	.post-action--spun :deep(.material-design-icon) {
		animation: none;
	}

	.post-action__burst,
	.post-action__spark {
		display: none;
	}
}

/**
 * A content warning is the author asking for their post not to be shown
 * until the reader chooses to see it. Honouring that is the whole point,
 * so the body is not in the DOM until it is opened.
 */
.post-warning {
	margin: 4px 0 8px;

	&__text {
		margin-bottom: 8px;
		font-weight: 600;
	}
}

/* the text of a picture post is its caption: smaller, and nearer the picture */
.post-message--caption {
	margin-top: 2px;
	font-size: 14.5px;
	color: var(--color-main-text);
}

.post-sensitive--leading {
	min-height: 180px;
}

.post-message--behind-warning {
	margin-top: 10px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
}

/**
 * A post one of the reader's own keyword filters matched. Boxed rather than
 * written as plain text like a content warning: this cover is the reader's own
 * doing and not the author's, and the two should not be mistaken for each
 * other. Nothing of the post is behind it — the body is not rendered at all
 * until the button is pressed.
 */
.post-filtered {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin: 10px 0;
	padding: 12px 16px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-background-dark);

	&__reason {
		margin: 0;
		font-weight: 600;
		min-width: 0;
		overflow-wrap: anywhere;
	}
}

/**
 * A post flagged sensitive without a warning shows its text but not its
 * pictures until the reader asks for them.
 */
.post-sensitive__hidden {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.post-sensitive {
	display: flex;
	align-items: center;
	justify-content: center;
	margin: 10px 0;
	padding: 20px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 12px;
	background: var(--color-background-dark);
}

.delete-hint {
	padding: 0 12px 12px;
	color: var(--color-text-lighter);
	line-height: 1.5;
}

/*
 * A finger cannot hover, so on a touch screen there is no state in which the
 * pill would ever open. It is not a pill there: the row goes back into the
 * card's flow, open, where it takes its own space and pushes nothing, because
 * nothing is moving.
 */
@media (hover: none) {
	.post-content .post-actions-reveal {
		padding: 0;
		border: none;
		border-radius: 0;
	}

	/* no surface either: there is no pointer to arrive and reveal one, so the
	   row is a row */
	.post-content .post-actions-reveal::before {
		display: none;
	}

	/* and nothing is dimmed waiting for a hover that never comes */
	.post-content .post-actions :deep(.button-vue__icon),
	.post-content .post-actions :deep(.action-item),
	.post-content .post-actions :deep(.actions) {
		opacity: 1;
	}

	.post-content .post-actions {
		margin-top: 10px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	/* the menu goes back to the far end of a full-width row */
	.post-content .post-actions :deep(.actions) {
		margin-inline-start: auto;
	}
}

/*
 * Reduced motion takes the fade away, not the reveal: the pill and the glyphs
 * still have to arrive when the pointer does, they just stop easing into it.
 * Nothing here moves in the first place, so there is no movement left to cut.
 */
/*
 * A post the reader has just published drops into place from above and glows
 * once in their colour, so the thing they made is visibly the thing that
 * arrived. The glow is a pseudo-element ring rather than a shadow: a shadow in
 * a keyframe is exactly what the shared elevation tokens exist to prevent.
 */
@keyframes post-arrive {
	0% { opacity: 0; transform: translateY(-18px) scale(.97); }
	60% { opacity: 1; transform: translateY(3px) scale(1.005); }
	100% { opacity: 1; transform: none; }
}

@keyframes post-arrive-glow {
	0% { opacity: 0; }
	25% { opacity: .9; }
	100% { opacity: 0; }
}

.post-content--arrived {
	animation: post-arrive .56s cubic-bezier(.2, .9, .3, 1.15) both;

	&::after {
		content: '';
		position: absolute;
		inset: -3px;
		border-radius: inherit;
		border: 2px solid hsl(var(--account-hue, 210) 70% 55%);
		pointer-events: none;
		animation: post-arrive-glow 1.8s ease-out both;
	}
}

@media (prefers-reduced-motion: reduce) {
	.post-content--arrived,
	.post-content--arrived::after {
		animation: none;
	}

	.post-content .post-actions-reveal::before,
	.post-content .post-actions :deep(.button-vue__icon),
	.post-content .post-actions :deep(.action-item),
	.post-content .post-actions :deep(.actions) {
		transition-duration: .01ms !important;
	}
}
</style>
