<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="new-post"
		:class="{
			'new-post--collapsed': !expanded,
			'new-post--drop-target': draggingFiles,
			'new-post--refused': refusedDrop,
			'new-post--posted': justPosted,
		}"
		data-id=""
		@focusin="expand"
		@dragenter="handleDragEnter"
		@dragover="handleDragOver"
		@dragleave="handleDragLeave"
		@drop="handleDrop">
		<!-- announced only to the eye: a drag is not something a screen reader
		     is in the middle of, and the pill must never take the drop it
		     announces, hence pointer-events: none -->
		<div v-if="draggingFiles" class="new-post__drop-hint" aria-hidden="true">
			<span>{{ t('social', 'Drop to attach') }}</span>
		</div>
		<input
			id="file-upload"
			ref="fileUploadInput"
			type="file"
			:accept="acceptedTypes"
			multiple="true"
			tabindex="-1"
			aria-hidden="true"
			class="hidden-visually"
			@change="handleFileChange($event)">
		<div class="new-post-author">
			<!-- the reader's own face, and `disableMenu` because a card about
			     yourself, over the box you are typing in, is nobody's idea of
			     a preview -->
			<NcAvatar
				:url="ownAvatarUrl"
				:displayName="currentUser.displayName"
				:hideStatus="true"
				:disableMenu="true"
				:disableTooltip="true"
				:size="32" />
			<div class="post-author">
				<span class="post-author-name">
					{{ currentUser.displayName }}
				</span>
			</div>
			<!-- The way out. The box opens on a click and closes again when the
			     reader clicks elsewhere — but only while it holds nothing worth
			     keeping, so as soon as a word is typed the only way back to a
			     one-line box was to delete that word by hand. Nothing is thrown
			     away here: what is in the box stays in it, and is put back from
			     the draft after a reload. -->
			<NcButton
				v-if="closable"
				variant="tertiary"
				class="new-post-author__close"
				:aria-label="t('social', 'Close the composer')"
				:title="t('social', 'Close the composer. What you have written is kept.')"
				@click="close">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
		<div v-if="replyTo && !anchoredReply" class="reply-to">
			<p class="reply-info">
				<span>{{ t('social', 'In reply to') }}</span>
				<ActorAvatar :actor="replyTo.account" :size="16" :link="false" />
				<strong>{{ replyTo.account.acct }}</strong>
				<NcButton
					variant="tertiary"
					class="close-button"
					:aria-label="t('social', 'Close reply')"
					@click="closeReply">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</p>
			<MessageContent :item="replyTo" />
		</div>
		<div v-if="quoteOf" class="quote-of">
			<p class="quote-info">
				<span>{{ t('social', 'Quoting') }}</span>
				<ActorAvatar :actor="quoteOf.account" :size="16" :link="false" />
				<strong>{{ quoteOf.account.acct }}</strong>
				<NcButton
					variant="tertiary"
					class="close-button"
					:aria-label="t('social', 'Remove quote')"
					@click="removeQuote">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</p>
			<MessageContent :item="quoteOf" />
		</div>
		<form
			class="new-post-form"
			:class="{ 'new-post-form--media-first': hasAttachments }"
			@submit.prevent>
			<!-- a post whose only attachment is a video is a video, and a video
			     has a name. Without this the title was guessed out of the first
			     line of the post, which is right for somebody who wrote one and
			     wrong for somebody who did not — and PeerTube lists a video by
			     its name and nothing else -->
			<div v-if="isVideoPost" class="video-row">
				<input
					v-model="videoTitle"
					type="text"
					class="video-row__title"
					maxlength="120"
					:aria-label="t('social', 'Video title')"
					:placeholder="t('social', 'Video title — what it is called on other servers')">
				<div class="video-row__pair">
					<input
						v-model="videoCategory"
						type="text"
						maxlength="60"
						:aria-label="t('social', 'Category')"
						:placeholder="t('social', 'Category, e.g. Music')">
					<input
						v-model="videoLicence"
						type="text"
						maxlength="60"
						:aria-label="t('social', 'Licence')"
						:placeholder="t('social', 'Licence, e.g. CC BY-SA')">
				</div>
			</div>
			<div v-if="showWarning" class="content-warning-row">
				<div class="content-warning-row__field">
					<input
						v-model="spoilerText"
						type="text"
						class="content-warning"
						maxlength="200"
						:aria-label="t('social', 'Content warning')"
						:placeholder="t('social', 'Content warning, e.g. what the post is about')">
					<!-- the toolbar button that opened this row is the other way
					     to close it, and it is a row further down and a guess:
					     the way out of a thing belongs on the thing -->
					<NcButton
						variant="tertiary"
						class="content-warning-row__remove"
						:title="t('social', 'Remove the content warning')"
						:aria-label="t('social', 'Remove the content warning')"
						@click.prevent="toggleWarning">
						<template #icon>
							<Close :size="18" />
						</template>
					</NcButton>
				</div>
				<!-- the warnings people actually write, as one press each: the
				     box stays, because the list cannot cover what a post is
				     about, and pressing one only fills the box in -->
				<ul class="content-warning-presets" :aria-label="t('social', 'Common content warnings')">
					<li v-for="preset in warningPresets" :key="preset">
						<button
							type="button"
							class="content-warning-presets__item"
							:class="{ 'content-warning-presets__item--active': spoilerText === preset }"
							:aria-pressed="spoilerText === preset ? 'true' : 'false'"
							@click="chooseWarning(preset)">
							{{ preset }}
						</button>
					</li>
				</ul>
			</div>
			<!-- above the box, not below it: once there is a picture the post
			     is the picture, and what is typed underneath is its caption -->
			<PreviewGrid
				:uploading="uploading"
				:uploadProgress="uploadProgress"
				:progressLabel="progressLabel"
				:miniatures="attachments"
				@deleted="deletePreview"
				@describe="describeAttachment"
				@commitDescription="commitDescription"
				@focus="focusAttachment"
				@commitFocus="commitFocus"
				@filter="applyFilter" />

			<div
				ref="composerInput"
				:contenteditable="!loading"
				class="message"
				role="textbox"
				aria-multiline="true"
				:aria-label="prompt"
				:aria-describedby="statusIsTooLong ? 'composer-length' : undefined"
				:placeholder="prompt"
				:class="{'icon-loading': loading, 'too-long': statusIsTooLong, 'message--caption': hasAttachments}"
				@keyup.prevent.enter="keyup"
				@input="updateStatusContent"
				@paste="handlePaste"
				@tribute-replaced="updatePostFromTribute" />

			<!-- /dice, /flip and /pick are played when the post goes out; this
			     says so while one is in the box, and shows the roll itself -->
			<div
				v-if="rolling.length > 0"
				class="composer-roll"
				:class="{ 'composer-roll--landed': rollLanded }"
				role="status"
				aria-live="polite">
				<span v-for="(die, index) in rolling" :key="index" class="composer-roll__die">
					<span aria-hidden="true">{{ die.icon }}</span> {{ die.shown }}
				</span>
			</div>
			<p v-else-if="gamesInPost.length > 0" class="composer-games">
				{{ t('social', 'Played when you post. Everybody sees the same result.') }}
			</p>

			<div v-if="asCard && canBeCard" class="composer-card">
				<div
					class="composer-card__preview"
					:style="{ background: cardBackground, color: cardGradient.ink, '--card-length': statusText.length }"
					aria-hidden="true">
					<p class="composer-card__text">
						{{ statusText }}
					</p>
				</div>
				<div class="composer-card__gradients" role="radiogroup" :aria-label="t('social', 'Background')">
					<button
						v-for="option in cardGradients"
						:key="option.id"
						type="button"
						role="radio"
						class="composer-card__gradient"
						:aria-checked="option.id === cardGradientId"
						:aria-label="option.name"
						:title="option.name"
						:style="{ background: backgroundOf(option) }"
						@click="cardGradientId = option.id" />
				</div>
				<p class="composer-card__hint">
					{{ t('social', 'Sent as a picture of these words, with the words as its description and as the post, so every server shows the card and every reader can still hear it.') }}
				</p>
			</div>

			<GifPicker
				v-if="showGifs"
				@close="showGifs = false"
				@chosen="attachGif" />

			<!-- what the server will publish, once the writer asks to see it;
			     under the box, above everything the post is being given -->
			<ComposerPreview
				v-if="showPreview"
				:text="statusText"
				:warning="showWarning ? spoilerText : ''"
				@close="showPreview = false" />

			<PollEditor
				v-if="showPoll"
				v-model:options="pollOptions"
				v-model:multiple="pollMultiple"
				v-model:expiresIn="pollExpiresIn"
				@remove="togglePoll" />

			<!-- When the post goes out, if not now. The picker is most of a
			     date library and arrives when the clock is pressed, not with
			     every composer; until then this block is not here at all. -->
			<SchedulePicker v-if="scheduling" v-model="scheduledAt">
				<NcButton
					variant="tertiary"
					class="schedule-editor__remove"
					:aria-label="t('social', 'Post now instead')"
					:title="t('social', 'Post now instead')"
					@click.prevent="toggleSchedule">
					<template #icon>
						<Close :size="18" />
					</template>
				</NcButton>
			</SchedulePicker>

			<!-- A panel, and so above the toolbar with the others rather than
			     inside it: the toolbar row is `max-height: 120px; overflow:
			     hidden` for its collapse, which cut the picker off — the
			     country box under the suggestions was simply not there. -->
			<PlacePicker
				v-if="placing"
				:place="place"
				@update:place="place = $event"
				@close="togglePlace" />

			<div class="options">
				<NcButton
					:title="t('social', 'Add attachment')"
					variant="tertiary"
					:aria-label="t('social', 'Add attachment')"
					:disabled="attachmentsFull"
					@click.prevent="clickImportInput">
					<template #icon>
						<Paperclip :size="22" decorative title="" />
					</template>
				</NcButton>

				<NcButton
					:title="t('social', 'Add from Files')"
					variant="tertiary"
					:aria-label="t('social', 'Add from Files')"
					:disabled="attachmentsFull || picking"
					@click.prevent="pickFromFiles">
					<template #icon>
						<FolderImage :size="22" decorative title="" />
					</template>
				</NcButton>

				<NcButton
					:title="showWarning ? t('social', 'Remove content warning') : t('social', 'Add content warning')"
					variant="tertiary"
					:aria-label="showWarning ? t('social', 'Remove content warning') : t('social', 'Add content warning')"
					:aria-pressed="showWarning"
					@click.prevent="toggleWarning">
					<template #icon>
						<AlertOutline :size="22" decorative title="" />
					</template>
				</NcButton>
				<NcButton
					:title="showGifs ? t('social', 'Close the picture library') : t('social', 'Add from the picture library')"
					variant="tertiary"
					:aria-label="showGifs ? t('social', 'Close the picture library') : t('social', 'Add from the picture library')"
					:aria-pressed="showGifs"
					:disabled="attachmentsFull"
					@click.prevent="showGifs = !showGifs">
					<template #icon>
						<FileGifBox :size="22" decorative title="" />
					</template>
				</NcButton>

				<NcButton
					:title="showPreview ? t('social', 'Hide preview') : t('social', 'Preview this post')"
					variant="tertiary"
					:aria-label="showPreview ? t('social', 'Hide preview') : t('social', 'Preview this post')"
					:aria-pressed="showPreview"
					@click.prevent="showPreview = !showPreview">
					<template #icon>
						<EyeOutline :size="22" decorative title="" />
					</template>
				</NcButton>
				<!-- a short post, drawn big on colour; only offered while the
				     post is short enough to look good that way -->
				<NcButton
					v-if="canBeCard"
					:title="asCard ? t('social', 'Post as plain text') : t('social', 'Post as a card')"
					variant="tertiary"
					class="card-toggle"
					:aria-label="asCard ? t('social', 'Post as plain text') : t('social', 'Post as a card')"
					:aria-pressed="asCard"
					@click.prevent="asCard = !asCard">
					<template #icon>
						<CardTextOutline :size="22" decorative title="" />
					</template>
				</NcButton>
				<NcButton
					:title="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
					variant="tertiary"
					:aria-label="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
					@click.prevent="togglePoll">
					<template #icon>
						<PollIcon :size="22" decorative title="" />
					</template>
				</NcButton>
				<NcButton
					:title="scheduling ? t('social', 'Post now instead') : t('social', 'Schedule for later')"
					variant="tertiary"
					class="schedule-toggle"
					:aria-label="scheduling ? t('social', 'Post now instead') : t('social', 'Schedule for later')"
					:aria-pressed="scheduling"
					@click.prevent="toggleSchedule">
					<template #icon>
						<NcLoadingIcon v-if="schedulePickerLoading" :size="22" />
						<ClockOutline
							v-else
							:size="22"
							decorative
							title="" />
					</template>
				</NcButton>

				<NcButton
					:title="placing ? t('social', 'No place') : t('social', 'Say where this was taken')"
					variant="tertiary"
					class="place-toggle"
					:aria-label="placing ? t('social', 'No place') : t('social', 'Say where this was taken')"
					:aria-pressed="placing"
					@click.prevent="togglePlace">
					<template #icon>
						<MapMarkerOutline :size="22" decorative title="" />
					</template>
				</NcButton>

				<!-- The picker carries the whole emoji set — 130 KB over the wire
				     — and it was a static import, so every reader downloaded it
				     to have a composer. Until the button is pressed there is a
				     plain button in its place that looks the same. -->
				<div class="new-post-form__emoji-picker">
					<NcEmojiPicker
						v-if="emojiPickerLoaded"
						:search="search"
						:closeOnSelect="false"
						:container="emojiPickerContainer"
						@select="insert">
						<NcButton
							ref="emojiButton"
							:title="t('social', 'Add emoji')"
							variant="tertiary"
							:aria-haspopup="true"
							:aria-label="t('social', 'Add emoji')">
							<template #icon>
								<EmoticonOutline :size="22" decorative title="" />
							</template>
						</NcButton>
					</NcEmojiPicker>
					<NcButton
						v-else
						:title="t('social', 'Add emoji')"
						variant="tertiary"
						:aria-haspopup="true"
						:aria-label="t('social', 'Add emoji')"
						@click="loadEmojiPicker">
						<template #icon>
							<NcLoadingIcon v-if="emojiPickerLoading" :size="22" />
							<EmoticonOutline
								v-else
								:size="22"
								decorative
								title="" />
						</template>
					</NcButton>
				</div>

				<span v-if="undescribed > 0" class="composer-alt-warning" role="status">
					{{ undescribedWarning }}
				</span>
				<!-- who is speaking, when the writer is in a team that has an
				     account. Absent on every instance that has none, which is
				     most of them, rather than a control that always says "me" -->
				<select
					v-if="teams.length"
					v-model="postAs"
					class="composer-post-as"
					:aria-label="t('social', 'Post as')">
					<option value="">
						{{ t('social', 'As myself') }}
					</option>
					<option v-for="team in teams" :key="team.acct" :value="team.acct">
						{{ team.display_name || team.username }}
					</option>
				</select>
				<LanguageSelect :language="language" @update:language="language = $event" />
				<VisibilitySelect :visibility="visibility" @update:visibility="chooseVisibility" />
				<div class="emptySpace" />
				<span
					v-if="statusText.length > 0"
					id="composer-length"
					class="char-ring"
					:class="{ 'char-ring--warning': charsLeft <= 50, 'char-ring--over': statusIsTooLong }"
					:style="{ '--char-progress': charProgress }"
					:title="charactersLeftLabel">
					<span v-if="charsLeft <= 50" class="char-ring__count" aria-hidden="true">{{ charsLeft }}</span>
					<!-- the ring is a colour and an arc; this is the same news in words,
					     announced only once it is worth interrupting for -->
					<span class="hidden-visually" role="status">
						{{ charsLeft <= 50 ? charactersLeftLabel : '' }}
					</span>
				</span>
				<SubmitStatusButton
					:visibility="visibility"
					:scheduled="scheduling"
					:disabled="!canPost || loading"
					@click="createPost" />
			</div>
		</form>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderImage from 'vue-material-design-icons/FolderImage.vue'
import FileGifBox from 'vue-material-design-icons/FileGifBox.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import debounce from 'debounce'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import PlacePicker from './PlacePicker.vue'
import SchedulePicker from './SchedulePicker.vue'
import { defineAsyncComponent } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { showError, showSuccess } from '../../services/toast.js'
import he from 'he'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import { generateUrl } from '@nextcloud/router'
import { ownAvatarUrl } from '../../services/avatar.js'
import PollEditor from './PollEditor.vue'
import PreviewGrid from './PreviewGrid.vue'
import ComposerPreview from './ComposerPreview.vue'
import LanguageSelect from './LanguageSelect.vue'
import VisibilitySelect from '../Visibility/VisibilitySelect.vue'
import { isKnownVisibility } from '../Visibility/VisibilitiesInfos.js'
import SubmitStatusButton from './SubmitStatusButton.vue'
import MessageContent from '../MessageContent.js'
import Tribute from 'tributejs'
import eventBus from '../../services/eventBus.js'
import { emojiPickerModule } from '../../services/emojiPicker.js'
import logger from '../../services/logger.js'
import { feel } from '../../services/senses.js'
import { commandsIn, resolveCommands, tumble } from '../../utils/composerCommands.js'
import { accountHue } from '../../services/accountColour.js'
import { cardGradients, findGradient, gradientCss, renderTextCard } from '../../utils/textCard.js'
import { clearDraft, loadDraft, saveDraft } from '../../services/draft.js'
import { mapStores } from 'pinia'
import { useInstanceStore } from '../../store/instance.js'
import { useAccountStore } from '../../store/account.js'
import { useTimelineStore } from '../../store/timeline.js'
import { applyFilterToFile } from '../../utils/imageFilters.js'
import { focusParam, isFocalPoint } from '../../utils/focalPoint.js'
import { htmlToPlainText } from '../../utils/plainText.js'
import { defaultLanguage, isLanguageCode, rememberedLanguage } from '../../utils/postLanguage.js'
import { fullDateTime } from '../../utils/relativeTime.js'
import { datePickerModule, isTooSoon, proposedSchedule } from '../../utils/schedule.js'
import { useCurrentUser } from '../../composables/useCurrentUser.js'
import { useServerData } from '../../composables/useServerData.js'
import { userKey } from '../../utils/browserStore.js'

/*
 * The two limits -- characters per post, attachments per post -- used to be
 * constants here; they are the server's, read from the instance entity into
 * the instance store, and appear below as `maxLength` and `maxAttachments`.
 */

/**
 * What the composer takes as an attachment. The file dialog is given these
 * as its `accept`, and a drop or a paste is held to the same list, so that
 * what can be dragged in is exactly what can be picked.
 */
const ACCEPTED_MEDIA_TYPES = ['image/', 'video/', 'audio/']

/**
 * The files a post may carry besides media, as CacheDocumentService::
 * DOCUMENT_MIME_TYPES has them: what people on a Nextcloud actually have to
 * share. The extensions are for a browser that reports no type for a file.
 */
const ACCEPTED_DOCUMENT_TYPES = [
	'application/pdf',
	'text/plain',
	'text/markdown',
	'text/csv',
	'application/zip',
	'application/epub+zip',
	'application/vnd.oasis.opendocument.text',
	'application/vnd.oasis.opendocument.spreadsheet',
	'application/vnd.oasis.opendocument.presentation',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation',
]
const ACCEPTED_DOCUMENT_EXTENSIONS = ['.pdf', '.txt', '.md', '.csv', '.zip', '.epub', '.odt', '.ods', '.odp', '.docx', '.xlsx', '.pptx']

/**
 * What the file picker offers. Narrower than what the composer takes from a
 * drop or an upload: Files is where the pictures are, and an audio file picked
 * out of a folder tree is not what this button is for.
 */
const PICKABLE_MEDIA_TYPES = ['image/*', 'video/*', ...ACCEPTED_DOCUMENT_TYPES]

/**
 * How long to wait before uploading a filtered copy. Flicking through the
 * filters to see them is the normal way to use them, and each stop should not
 * be an upload.
 */
const FILTER_DEBOUNCE = 600

/** how long the card says no for, in step with the refusal in TimelinePost */
const REFUSAL_DURATION = 400

/**
 * The content warnings worth one press.
 *
 * Not a taxonomy and not a moderation policy — the box is still there and
 * still takes anything. These are the handful that come up often enough that
 * typing them again is friction, and having them written the same way every
 * time is what makes a warning filterable by the people who filter on them.
 *
 * Translated, because a warning is read by the people on this instance.
 */
function contentWarningPresets() {
	return [
		translate('social', 'Spoiler'),
		translate('social', 'Food'),
		translate('social', 'Politics'),
		translate('social', 'Mental health'),
		translate('social', 'Eye contact'),
		translate('social', 'Work'),
	]
}

/**
 * The emoji picker's module, fetched at most once.
 *
 * It carries the whole emoji set — most of a megabyte of source — so it is its
 * own chunk and arrives when somebody asks for an emoji rather than with every
 * composer.
 *
 * @return {Promise<object>} the module
 */

/**
 * The shared picture library, fetched when somebody first asks for it.
 *
 * Same reason as the emoji and date pickers: it brings `NcTextField` with it, and
 * that pulls `@nextcloud/vue`'s l10n chunk — half a megabyte — into whatever
 * chunk it lands in. Statically imported here it landed in the app's initial
 * bundle and nearly doubled it, for a panel most readers never open.
 */
let gifPicker = null
const gifPickerModule = () => (gifPicker ??= import('./GifPicker.vue'))

/** the longest a post may be and still be offered as a card */
export const CARD_MAX = 120

export default {
	name: 'Composer',
	components: {
		NcAvatar,
		NcEmojiPicker: defineAsyncComponent({
			loader: emojiPickerModule,
			onError: (error) => logger.error('Could not load the emoji picker', { error }),
		}),

		GifPicker: defineAsyncComponent({
			loader: gifPickerModule,
			onError: (error) => logger.error('Could not load the picture library', { error }),
		}),

		NcButton,
		NcLoadingIcon,
		ActorAvatar,
		Paperclip,
		EmoticonOutline,
		ClockOutline,
		MapMarkerOutline,
		PlacePicker,
		SchedulePicker,
		Close,
		FolderImage,
		AlertOutline,
		EyeOutline,
		PollIcon,
		CardTextOutline,
		PollEditor,
		PreviewGrid,
		ComposerPreview,
		FileGifBox,
		LanguageSelect,
		VisibilitySelect,
		SubmitStatusButton,
		MessageContent,
	},

	directives: {
		FocusOnCreate,
	},

	props: {
		initialMention: {
			type: Object,
			default: null,
		},

		defaultVisibility: {
			type: String,
			default: undefined,
		},

		/**
		 * Opened already, for the places where writing a post is the whole
		 * reason the composer is on screen — the New post dialog, say, where
		 * asking for another click would be asking twice.
		 */
		startExpanded: {
			type: Boolean,
			default: false,
		},

		/**
		 * The post this composer replies to by default — what a composer
		 * anchored under a post on its own page is for.
		 *
		 * It is a default and not a fixed target: pressing reply on another
		 * post in the same thread retargets this composer like any other, and
		 * sending or closing that reply comes back here rather than leaving
		 * the box pointed at a post further down the page.
		 *
		 * @type {import('vue').PropType<object|null>}
		 */
		inReplyTo: {
			type: Object,
			default: null,
		},

		/**
		 * Files to attach as soon as the composer is up: what "Share to
		 * Social" in the Files app hands over. Paths in the reader's own
		 * folder, the same the picker produces; attached through the same
		 * code, so the ceiling, the progress and a refusal look the same.
		 *
		 * @type {import('vue').PropType<string[]>}
		 */
		initialPaths: {
			type: Array,
			default: () => [],
		},

		/**
		 * Where floating-vue teleports the emoji picker popper. The inline
		 * composer keeps it in the themed app root (`#content`): inside the
		 * toolbar row (`.options`, `max-height` + `overflow: hidden`) an
		 * un-teleported popper would be cut off. The composer in the "New
		 * post" dialog instead has to stay inside the modal mask: anything
		 * teleported out (to `#content`, to `body`) lands behind the dimmed
		 * mask and outside the focus trap, so the picker shows behind the
		 * popup and takes no clicks. Callers in a modal pass the overlay wrapper
		 * (not `.modal-composer`): the dialog's content panel has `overflow: auto`,
		 * which clips a 420px picker when it opens above the toolbar. The overlay
		 * wrapper stays inside the mask and focus layer without clipping it.
		 */
		emojiPickerContainer: {
			type: String,
			default: '#content',
		},
	},

	emits: ['posted'],
	setup() {
		const { hostname } = useServerData()
		const { currentUser } = useCurrentUser()

		return {
			hostname,
			currentUser,
			// set in mounted(); here rather than in data() so that the
			// library's own object is not wrapped in a reactive proxy
			tribute: null,
			tributeTarget: null,
		}
	},

	data() {
		return {
			/** whether the emoji picker has been asked for, and so downloaded */
			emojiPickerLoaded: false,
			/** whether that download is in flight, so a second press is ignored */
			emojiPickerLoading: false,
			statusContent: '',
			/** what would actually be sent — the string the counter measures */
			statusText: '',
			/** true for the moment after a post went out, for the flourish */
			justPosted: false,
			/** whether a short post goes out drawn as a card */
			asCard: false,
			cardGradientId: 'account',
			/** the games in the post while they are being played, [] otherwise */
			rolling: [],
			/** whether the dice have come to rest */
			rollLanded: false,
			// what a click into the box opens up; the composer is also expanded
			// by anything it already holds — see expanded()
			openedByHand: this.startExpanded,
			// and what the close button shuts again. It has to be a state of
			// its own rather than the absence of `openedByHand`, because a box
			// with a word in it is expanded by the word: without this there was
			// no way to close one except by deleting what was in it.
			closedByHand: false,
			// a reply goes where the post it answers went, which is also what
			// the reply flow does when a composer is retargeted by hand
			// the audience, in order of who gets to say: whoever opened this
			// composer, the post being answered, the account's own default,
			// the last one the reader used, and failing all of those the
			// narrow choice rather than the public one
			visibility: this.defaultVisibility
				|| this.inReplyTo?.visibility
				|| useAccountStore().defaultPostVisibility
				|| rememberedVisibility()
				|| 'followers',

			/**
			 * Whether the audience above has been settled by somebody rather
			 * than merely defaulted to, so that an account default arriving
			 * late leaves it alone.
			 */
			visibilityChosen: Boolean(this.defaultVisibility || this.inReplyTo?.visibility),

			// what the last post went out in, else what Nextcloud is set to:
			// the server would guess the same, but a guess the poster can see
			// is one they can correct
			language: rememberedLanguage() || defaultLanguage(),
			/**
			 * The team accounts this account may post as, and which of them
			 * this post is being written as ('' for themselves).
			 *
			 * Empty on every instance with no team accounts, which is most of
			 * them, and the control is absent rather than a picker that only
			 * ever says "me".
			 */
			teams: [],
			postAs: '',
			/** when the post is to go out, or null for now */
			scheduledAt: null,
			/** whether the clock is pressed: the picker is shown, Post reads Schedule */
			scheduling: false,
			/** whether the pin is pressed: the place picker is shown */
			placing: false,
			/** where the post was taken: `{id}` for a known place, `{name, country}` for a new one, or null */
			place: null,
			/** whether the date picker has been fetched */
			schedulePickerLoaded: false,
			/** whether that fetch is in flight */
			schedulePickerLoading: false,
			loading: false,
			/** whether an attachment is on its way to the server */
			uploading: false,
			/** how far the current upload has got, 0..1 */
			uploadProgress: 0,
			/** what the progress bar is working on, in words */
			progressLabel: '',
			/** whether the Files dialog is open or its picks are being attached */
			picking: false,
			/** keeps two picks of the same file apart, since the path cannot */
			pickCount: 0,
			/** pending re-uploads, one per attachment, keyed by its object URL */
			filterTimers: {},
			/** whether files are being dragged over the card right now */
			draggingFiles: false,
			/** briefly true after a drop of something the composer cannot take */
			refusedDrop: false,
			attachments: {},
			showPoll: false,
			showWarning: false,
			/** what a video is called, and what it is; blank unless asked for */
			videoTitle: '',
			videoCategory: '',
			videoLicence: '',
			/** whether the "how this will read" pane is open */
			showPreview: false,
			/** whether the shared picture library is open */
			showGifs: false,
			spoilerText: '',
			pollOptions: ['', ''],
			pollMultiple: false,
			pollExpiresIn: 86400,
			search: '',
			replyTo: this.inReplyTo,
			/** the post this one quotes, as the timeline handed it over */
			quoteOf: null,
			tributeOptions: {
				spaceSelectsMatch: true,
				collection: [
					{
						trigger: '@',
						lookup(item) {
							return item.key + item.value
						},

						menuItemTemplate(item) {
							return '<img src="' + item.original.avatar + '" /><div>'
								+ '<span class="displayName">' + item.original.key + '</span>'
								+ '<span class="account">' + item.original.value + '</span>'
								+ '</div>'
						},

						selectTemplate(item) {
							return '<span class="mention" contenteditable="false">'
								+ `<a href="${item.original.url}" target="_blank">`
								+ `<img src="${item.original.avatar}"/>`
								+ `@${item.original.value}`
								+ '</a>'
								+ '</span>&nbsp;'
						},

						values: debounce(async (text, populate) => {
							if (text.length < 1) {
								populate([])
							}

							const response = await this.remoteSearchAccounts(text)

							const users = response.data.result.accounts.map((user) => ({
								key: user.preferredUsername,
								value: user.account,
								url: user.url,
								avatar: user.local ? generateUrl(`/avatar/${user.preferredUsername}/32`) : generateUrl(`apps/social/api/v1/global/actor/avatar?id=${user.id}`),
							}))

							logger.debug('Found accounts for a mention', { count: users.length })
							populate(users)
						}, 200),
					},
					{
						trigger: '#',
						menuItemTemplate(item) {
							return item.original.value
						},

						selectTemplate(item) {
							let tag
							if (typeof item === 'undefined') {
								tag = this.currentMentionTextSnapshot
							} else {
								tag = item.original.value
							}
							return '<span class="hashtag" contenteditable="false">'
								+ '<a href="' + generateUrl('/timeline/tags/' + tag) + '" target="_blank">#' + tag + '</a></span>'
						},

						values: debounce(async (text, populate) => {
							if (text.length < 1) {
								populate([])
							}

							const response = await this.remoteSearchHashtags(text)
							const tags = [
								...(response.data.result.exact && !Array.isArray(response.data.result.exact) ? [{ key: response.data.result.exact, value: response.data.result.exact }] : []),
								...response.data.result.tags.map(({ hashtag }) => ({ key: hashtag, value: hashtag })),
							]

							logger.debug('Found hashtags for a mention', { count: tags.length })
							populate(tags)
						}, 200),
					},
				],

				noMatchTemplate() {
					if (this.current.collection.trigger === '#') {
						if (this.current.mentionText === '') {
							return undefined
						} else {
							return '<li data-index="0">#' + this.current.mentionText + '</li>'
						}
					}
				},
			},

			/** when the refused-drop notice goes away */
			refusalTimer: null,
			// the eventBus and document handlers mounted() adds, kept so that
			// unmounted() removes only these and not other components' listeners
			onComposerReply: null,
			onComposerQuote: null,
			onComposerRedraft: null,
			onComposerFocus: null,
			onOutsideInteraction: null,
		}
	},

	computed: {
		...mapStores(useAccountStore, useInstanceStore, useTimelineStore),

		/** @return {string[]} the warnings offered as one press each */
		warningPresets() {
			return contentWarningPresets()
		},

		/**
		 * The reader's own face, addressed directly rather than by account name.
		 *
		 * `NcAvatar` given a `user` keeps a per-account note in browser storage
		 * saying whether that account has a picture, and on every later render it
		 * trusts the note instead of the image: one failed load, from a restart
		 * or a deploy, and the note says no picture for as long as the browser
		 * keeps it -- in this browser only, which is why it looks like the
		 * picture simply went. Given a `url` it validates the image each time and
		 * falls back to the initials when it truly cannot be had.
		 *
		 * It also avoids the malformed address that path builds for your own
		 * account: the component appends its cache-buster with a second `?`.
		 *
		 * @return {string} the avatar of the account writing this post
		 */
		ownAvatarUrl() {
			return ownAvatarUrl(64)
		},

		/** @return {number} what the server accepts in one status */
		maxLength() {
			return this.instanceStore.maxCharacters
		},

		/** @return {number} what a post may carry, as the server holds it */
		maxAttachments() {
			return this.instanceStore.maxAttachments
		},

		/** @return {string} the `accept` of the file dialog, from one list */
		acceptedTypes() {
			return [...ACCEPTED_MEDIA_TYPES.map((type) => `${type}*`), ...ACCEPTED_DOCUMENT_TYPES, ...ACCEPTED_DOCUMENT_EXTENSIONS].join(',')
		},

		/** @return {boolean} whether the composer holds a picture */
		hasAttachments() {
			return Object.keys(this.attachments).length > 0
		},

		/** @return {boolean} whether the post is carrying all the server takes */
		attachmentsFull() {
			return Object.keys(this.attachments).length >= this.maxAttachments
		},

		/**
		 * What the box asks for. With a picture above it, the post is the
		 * picture and the words underneath it are its caption.
		 *
		 * @return {string}
		 */
		prompt() {
			if (this.hasAttachments) {
				return translate('social', 'Write a caption…')
			}

			return this.replyTo !== null
				? translate('social', 'Write a reply…')
				: translate('social', 'What would you like to share?')
		},

		/**
		 * Whether the post being replied to is the one this composer is
		 * anchored under. The header saying who is being replied to, with the
		 * post quoted inside it, is then a copy of what is directly above the
		 * box — and the button for closing it would leave a reply box replying
		 * to nothing.
		 *
		 * @return {boolean}
		 */
		anchoredReply() {
			return this.inReplyTo !== null && this.replyTo?.id === this.inReplyTo.id
		},

		/** Attachments that can carry a description and have not been given one. */
		undescribed() {
			return Object.values(this.attachments).filter((attachment) => attachment.data?.id !== undefined && (attachment.description || '').trim() === '').length
		},

		/** @return {number} uploads the server refused */
		failedUploads() {
			return Object.values(this.attachments).filter((attachment) => attachment.failed === true).length
		},

		/** @return {boolean} whether an upload has not come back yet */
		hasPendingUploads() {
			return Object.values(this.attachments).some((attachment) => attachment.failed !== true && attachment.data === null)
		},

		/** @return {string[]} the ids the post will carry */
		/**
		 * Whether this post is a video: one attachment, and it a video.
		 *
		 * The same rule the wire form applies — a `Video` object *is* the
		 * video, so a post carrying a video and three photographs is a post.
		 *
		 * @return {boolean}
		 */
		isVideoPost() {
			const attachments = Object.values(this.attachments)

			return attachments.length === 1
				&& (attachments[0].data?.type === 'video'
					|| (attachments[0].data?.media_type || '').startsWith('video/'))
		},

		mediaIds() {
			return Object.values(this.attachments)
				.map((attachment) => attachment.data?.id)
				.filter((id) => id !== undefined && id !== null)
		},

		undescribedWarning() {
			return translatePlural(
				'social',
				'%n attachment has no description',
				'%n attachments have no description',
				this.undescribed,
			)
		},

		charactersLeftLabel() {
			return this.statusIsTooLong
				? translatePlural('social', '%n character too many', '%n characters too many', -this.charsLeft)
				: translatePlural('social', '%n character left', '%n characters left', this.charsLeft)
		},

		/**
		 * Whether the time picked is one the server would refuse: less than
		 * five minutes out, or not a time at all.
		 *
		 * @return {boolean}
		 */
		scheduleTooSoon() {
			if (!this.scheduling) {
				return false
			}

			return isTooSoon(this.scheduledAt)
		},

		canPost() {
			// an upload that has not answered yet is worth waiting for; one
			// that failed used to leave `data: undefined`, which passed this
			// check and then threw on `preview.data.id` before the try block
			if (this.hasPendingUploads) {
				return false
			}

			if (this.scheduleTooSoon) {
				return false
			}

			if (this.statusIsTooLong) {
				return false
			}

			if (this.statusIsEmpty) {
				return false
			}

			if (this.visibility === 'direct' && !this.hasMentions) {
				return false
			}

			return true
		},

		statusIsEmpty() {
			return this.statusText.trim().length === 0 && this.mediaIds.length === 0
		},

		/**
		 * A composer with nothing in it is a placeholder and a portrait; the
		 * eight controls underneath it are answers to a question nobody has
		 * asked yet. It opens on a click, and stays open for as long as it holds
		 * anything that would be lost by closing it.
		 *
		 * @return {boolean}
		 */
		expanded() {
			if (this.closedByHand) {
				// what was written is still in the box and still on disk; it is
				// simply not on screen until the reader asks for it again
				return false
			}

			return this.openedByHand
				|| this.loading
				// the post it is anchored under is not a reply in progress: a
				// box under every post would otherwise be open on every post,
				// which is the one thing a box that is always there must not be
				|| (this.replyTo !== null && !this.anchoredReply)
				|| this.quoteOf !== null
				|| this.showPoll
				|| this.scheduling
				|| this.showWarning
				|| !this.statusIsEmpty
				|| Object.keys(this.attachments).length > 0
		},

		/**
		 * Whether there is anything to close it *to*.
		 *
		 * Not in the New post dialog, where writing a post is the whole reason
		 * the composer is on screen and the dialog has its own way out; and not
		 * while it is collapsed already.
		 *
		 * @return {boolean}
		 */
		closable() {
			return this.expanded && !this.startExpanded
		},

		/**
		 * Whether the post is short enough, and plain enough, to be a card: a
		 * few words and nothing else. A picture already has its own look, a
		 * poll has its own shape, and three paragraphs on a gradient are a
		 * poster nobody reads.
		 *
		 * @return {boolean}
		 */
		canBeCard() {
			const length = this.statusText.trim().length

			return length > 0 && length <= CARD_MAX && !this.hasAttachments && !this.showPoll
		},

		/** @return {object[]} the backgrounds a card can have, the writer's own first */
		cardGradients() {
			return cardGradients(accountHue(this.accountStore.currentAccount?.acct ?? ''))
		},

		/** @return {object} the one chosen */
		cardGradient() {
			return findGradient(this.cardGradientId, accountHue(this.accountStore.currentAccount?.acct ?? ''))
		},

		/** @return {string} it, as CSS */
		cardBackground() {
			return gradientCss(this.cardGradient)
		},

		/** @return {string[]} the games typed into the box, for the hint under it */
		gamesInPost() {
			return commandsIn(this.statusText)
		},

		/**
		 * Measured on what is sent, not on the markup that produces it. A
		 * mention pill from a reply is ~200 characters of HTML and every line
		 * break adds a <div>, so counting innerHTML burned half the allowance
		 * before a word was typed.
		 */
		statusIsTooLong() {
			return this.statusText.length > this.maxLength
		},

		/** @return {number} how much of the allowance is spent, 0..1 */
		charProgress() {
			return Math.min(this.statusText.length / this.maxLength, 1)
		},

		/** @return {number} how many characters remain, negative once over */
		charsLeft() {
			return this.maxLength - this.statusText.length
		},

		hasMentions() {
			return /(?:^|\s)@[a-zA-Z0-9_.-]+/i.test(this.statusText)
		},
	},

	watch: {
		/**
		 * Another post's page, in the same component: the router reuses this
		 * view, so without this the box would still be replying to the post
		 * the reader has navigated away from.
		 *
		 * @param post
		 */
		inReplyTo(post) {
			this.replyTo = post
		},

		// the warning is part of the draft, and it has its own field
		spoilerText: 'rememberDraft',
		showWarning: 'rememberDraft',
		visibility: 'rememberDraft',

		/**
		 * `verify_credentials` can land after a composer is already on screen
		 * — the timeline draws one as the page opens — and the account's
		 * default audience only comes with it. A composer nobody has spoken
		 * for takes it; one that was opened as a reply, or whose audience the
		 * reader has already named, keeps what it has.
		 *
		 * @param {string} visibility the account's default, '' until it comes
		 */
		'accountStore.defaultPostVisibility': function(visibility) {
			if (visibility !== '' && !this.visibilityChosen) {
				this.visibility = visibility
			}
		},
	},

	mounted() {
		// the counter and the attachment ceiling are the server's numbers;
		// answered from the first call on this page, whoever made it
		this.instanceStore.load()
		this.loadTeams()

		// tributejs is a plain DOM library, not a component: it attaches to the
		// contenteditable and appends its menu to the body, which the unscoped
		// .tribute-container rule at the end of this file styles.
		this.tribute = new Tribute(this.tributeOptions)
		// Kept, because $refs is cleared before unmounted() runs and detach() rejects
		// anything that is not a node.
		this.tributeTarget = this.$refs.composerInput
		this.tribute.attach(this.tributeTarget)

		// Keep the handler so unmounted() removes only this one and not the
		// listeners other components registered for the same event.
		this.onComposerReply = (data) => {
			this.replyTo = data
			// everyone in the conversation, not only whoever wrote the post
			// being answered: a reply that named one of three people reached
			// one of three people
			this.prefillMessageWithMentions(this.participantsOf(data))
			this.visibility = data.visibility
			this.visibilityChosen = true
			// somebody pressed reply, which is a request to write one — including
			// on the post this box is anchored under, where the target does not
			// change and the box opening is the whole of the answer. Through
			// expand(), so that it also undoes a close by hand.
			this.expand()
		}
		eventBus.on('composer-reply', this.onComposerReply)

		// a quote carries no mention and does not take the quoted post's
		// visibility: it is addressed by whoever writes it, not by whoever
		// is being quoted
		this.onComposerQuote = (data) => {
			this.quoteOf = data
		}
		eventBus.on('composer-quote', this.onComposerQuote)

		// a post that was just deleted, coming back to be written again
		this.onComposerRedraft = (post) => this.redraft(post)
		eventBus.on('composer-redraft', this.onComposerRedraft)

		// the shortcuts help offers "n" to write a post; this is what answers it
		this.onComposerFocus = () => this.focusInput()
		eventBus.on('shortcut:compose', this.onComposerFocus)

		// before the mention prefill, which declines to overwrite a non-empty
		// composer: whatever the last attempt left is what the reader wants back
		this.restoreDraft()

		if (this.initialMention !== null) {
			this.prefillMessageWithMention(this.initialMention)
		}

		// somebody arrived here from Files with pictures in hand: open, and
		// start attaching them before they have to do anything
		const paths = this.initialPaths.filter((path) => typeof path === 'string' && path !== '' && path !== '/')
		if (paths.length > 0) {
			this.expand()
			this.attachPaths(paths)
		}

		// a click anywhere else closes it again, which focusout cannot do on its
		// own: the emoji picker is rendered outside this element, so following it
		// with the caret looks exactly like leaving
		this.onOutsideInteraction = (event) => this.collapseIfIdle(event)
		document.addEventListener('pointerdown', this.onOutsideInteraction)
		document.addEventListener('focusin', this.onOutsideInteraction)
	},

	unmounted() {
		window.clearTimeout(this.refusalTimer)
		document.removeEventListener('pointerdown', this.onOutsideInteraction)
		document.removeEventListener('focusin', this.onOutsideInteraction)
		if (this.tribute && this.tributeTarget) {
			this.tribute.detach(this.tributeTarget)
		}
		eventBus.off('composer-reply', this.onComposerReply)
		eventBus.off('composer-quote', this.onComposerQuote)
		eventBus.off('composer-redraft', this.onComposerRedraft)
		eventBus.off('shortcut:compose', this.onComposerFocus)
	},

	methods: {
		expand() {
			this.closedByHand = false
			this.openedByHand = true
		},

		/**
		 * Everything the reader put in, gone — the text, the attachments and
		 * their previews, the poll, the warning, the schedule, the place, and
		 * the copy on disk.
		 *
		 * One method rather than two lists, because it is run in two places
		 * that must agree: after a post has been sent, and when the reader
		 * closes the box on one that has not. A field added to the composer and
		 * to only one of those lists is a field that survives posting.
		 */
		clearComposer() {
			if (this.$refs.composerInput !== undefined) {
				this.$refs.composerInput.innerText = ''
			}
			Object.keys(this.attachments).forEach((key) => this.releasePreview(key))
			this.attachments = {}
			this.showPoll = false
			this.pollOptions = ['', '']
			this.pollMultiple = false
			this.showWarning = false
			this.spoilerText = ''
			this.scheduling = false
			this.scheduledAt = null
			this.placing = false
			this.place = null
			this.asCard = false
			clearDraft()
			this.updateStatusContent()
		},

		/**
		 * The close button: back to a line of placeholder, with everything
		 * still in the box.
		 *
		 * **Nothing is thrown away.** What was typed stays in the box and stays
		 * in the draft on disk, so opening it again — a click, a reply, the
		 * compose shortcut, or the next visit to the page — puts the reader
		 * back where they were. A half-written post is not something to ask
		 * somebody about at the moment they are trying to get it out of their
		 * way; it is something to still be there when they come back.
		 *
		 * Clicking elsewhere collapses an idle composer and may do no more than
		 * that, because a stray click must not close a box somebody is writing
		 * in. This is that click made deliberate.
		 */
		close() {
			this.closedByHand = true
			this.openedByHand = false

			if (this.inReplyTo === null && this.statusIsEmpty) {
				// the sidebar's box is shown by the store rather than by this
				// component; an empty one closes all the way, a written one
				// stays where the reader can get back to it
				this.timelineStore.setComposerDisplayStatus(false)
			}
		},

		/**
		 * Fills the warning box in from the presets, or empties it when the
		 * one already chosen is pressed again — the same press undoing itself
		 * is what the pressed state promises.
		 *
		 * @param {string} preset the warning that was pressed
		 */
		chooseWarning(preset) {
			this.spoilerText = this.spoilerText === preset ? '' : preset
		},

		/**
		 * Fetches the emoji picker and opens it, which is what the button the
		 * reader actually pressed would have done if it had been there.
		 *
		 * The import is awaited rather than left to `defineAsyncComponent`, so
		 * the picker is mounted by the time the click is passed on to it; doing
		 * it the other way round put the click into a component that did not
		 * exist yet and the reader had to press twice.
		 */
		async loadEmojiPicker() {
			if (this.emojiPickerLoading) {
				return
			}

			// the button stays where it is until the picker is really there:
			// swapping it out first left an empty space for as long as the
			// chunk took, which on a busy connection is seconds
			this.emojiPickerLoading = true
			try {
				await emojiPickerModule()
				this.emojiPickerLoaded = true
			} catch (error) {
				// Keep the lightweight button mounted. The import service clears a
				// rejected promise, so another press retries the chunk request.
				logger.error('Could not load the emoji picker', { error })
				return
			} finally {
				this.emojiPickerLoading = false
			}

			// and the click the reader already made is passed on to the picker,
			// which is now mounted, rather than being spent on fetching it. A
			// frame after the tick: the popover binds its trigger on mount and
			// a click in the same tick lands before the listener does
			await this.$nextTick()
			await new Promise((resolve) => window.requestAnimationFrame(resolve))
			this.$refs.emojiButton?.$el?.click()
		},

		/**
		 * @param {Event} event a click or a focus somewhere in the document
		 */
		collapseIfIdle(event) {
			if (!this.openedByHand) {
				return
			}

			const target = event.target
			if (!(target instanceof Node) || this.$el.contains(target)) {
				return
			}

			// the emoji picker, the two menus and the date picker's calendar
			// are teleported out of this element; using one of them is not
			// leaving the composer
			if (target instanceof Element && target.closest('.v-popper__popper, .modal-mask, .dp__menu') !== null) {
				return
			}

			this.openedByHand = false
		},

		/** Puts the caret in the composer, scrolling it into view if need be. */
		focusInput() {
			const input = this.$refs.composerInput
			if (input === undefined) {
				return
			}

			input.focus()
			// the composer sits at the top of the timeline, which may be scrolled away
			if (typeof input.scrollIntoView === 'function') {
				input.scrollIntoView({ block: 'nearest' })
			}
		},

		prefillMessageWithMention(account) {
			this.prefillMessageWithMentions([account])
		},

		/**
		 * Starts the message with a mention pill per account, in the order
		 * given, unless something is already being written.
		 *
		 * @param {Array<{acct: string, url: string, avatar?: string}>} accounts who to address
		 */
		prefillMessageWithMentions(accounts) {
			if (accounts.length === 0 || !this.statusIsEmpty || this.$refs.composerInput === undefined) {
				return
			}

			const nodes = accounts.flatMap((account) => {
				const mention = document.createElement('span')
				mention.className = 'mention'
				mention.contentEditable = 'false'

				const link = document.createElement('a')
				link.href = account.url
				link.target = '_blank'

				// a Mention entity off a post carries no picture; the pill
				// then carries none either rather than a broken one
				if (account.avatar) {
					const avatar = document.createElement('img')
					avatar.src = account.avatar
					link.append(avatar)
				}
				link.append(document.createTextNode(`@${this.fullHandle(account.acct)}`))
				mention.append(link)

				return [mention, document.createTextNode('\u00a0')]
			})

			this.$refs.composerInput.replaceChildren(...nodes)
			this.updateStatusContent()
		},

		/**
		 * @param {string} acct a handle, with or without its host
		 * @return {string} the handle with its host, the way a mention is typed
		 */
		fullHandle(acct) {
			return acct.includes('@') ? acct : `${acct}@${this.hostname}`
		},

		/**
		 * Everyone a reply to this post should reach: its author, then
		 * everyone it mentioned, each once, and never the reader — a reply
		 * that addresses its own author is talking to itself.
		 *
		 * @param {object} post the post being answered, as the timeline holds it
		 * @return {Array<{acct: string, url: string, avatar?: string}>}
		 */
		participantsOf(post) {
			const self = `${this.currentUser.uid}@${this.hostname}`.toLowerCase()
			const seen = new Set()

			return [post.account, ...(Array.isArray(post.mentions) ? post.mentions : [])]
				.filter((account) => typeof account?.acct === 'string' && account.acct !== '')
				.filter((account) => {
					const handle = this.fullHandle(account.acct).toLowerCase()
					if (handle === self || seen.has(handle)) {
						return false
					}

					seen.add(handle)

					return true
				})
		},

		updateStatusContent() {
			this.statusContent = this.$refs.composerInput.innerHTML
			this.statusText = this.plainText()
			this.rememberDraft()
		},

		/**
		 * The composer's contents as the string that would be sent: emoji
		 * images replaced by their alt text, entities decoded, markup gone.
		 *
		 * @return {string}
		 */
		plainText() {
			const input = this.$refs.composerInput
			if (input === undefined || input === null) {
				return ''
			}

			const element = input.cloneNode(true)
			Array.from(element.getElementsByClassName('emoji')).forEach((emoji) => {
				emoji.replaceWith(document.createTextNode(emoji.getAttribute('alt') ?? ''))
			})

			return he.decode(nodeToPlainText(element).trim())
		},

		/**
		 * The team accounts this account may post as.
		 *
		 * Asked of the server rather than remembered: membership of a group is
		 * a live fact, and a composer that offered a team somebody had left
		 * would be offering a post the server is about to refuse.
		 *
		 * @return {Promise<void>}
		 */
		async loadTeams() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1.1/teams'))
				this.teams = data.teams ?? []
			} catch (error) {
				// an instance with none answers this too; a composer without
				// the control is the composer it has always been
				logger.debug('could not load the team accounts', { error })
			}
		},

		/** Keeps what is in the box, so a failed post or a reload cannot eat it. */
		rememberDraft() {
			saveDraft({
				text: this.statusText,
				spoilerText: this.showWarning ? this.spoilerText : '',
				visibility: this.visibility,
				// who it was being written as, so a reload does not quietly
				// turn a team post back into a personal one
				postAs: this.postAs,
			})
		},

		/**
		 * Puts back whatever the last attempt or the last session left, unless
		 * something else has already filled the composer (a reply mention).
		 */
		restoreDraft() {
			const draft = loadDraft()
			if (draft === null || this.$refs.composerInput === undefined) {
				return false
			}

			if (draft.text !== '') {
				this.$refs.composerInput.innerText = draft.text
			}
			if (draft.spoilerText !== '') {
				this.showWarning = true
				this.spoilerText = draft.spoilerText
			}
			// a draft written by another version can name a visibility this one
			// does not have, and VisibilitySelect renders `.text` off the entry
			// it looks up: an unknown id took the whole composer down
			if (isKnownVisibility(draft.visibility) && this.defaultVisibility === undefined) {
				this.visibility = draft.visibility
				this.visibilityChosen = true
			}
			// only a team this account is actually in: a draft can outlive
			// leaving one, and a handle the server would refuse is worse than
			// a draft that opens as yourself
			if (this.teams.some((team) => team.acct === draft.postAs)) {
				this.postAs = draft.postAs
			}
			this.updateStatusContent()

			return true
		},

		/**
		 * Fills the composer from a post that has just been deleted.
		 *
		 * Everything the post carried that this box can hold: the words, the
		 * content warning, the audience, the language and the pictures. The
		 * pictures are the uploads the server still holds — deleting a post
		 * removes the post, not the media rows behind it — so they are put
		 * back by id rather than uploaded again, which is what makes this
		 * different from copying the text out by hand.
		 *
		 * A poll is not carried. Its votes belong to the post that was
		 * deleted, and a new poll with the old options and no votes is a
		 * different thing wearing its clothes; whoever wants one adds it here.
		 *
		 * Whatever is already in the box wins. A re-draft arrives from a menu
		 * two clicks away, and overwriting half-written words with an old post
		 * is not a correction anybody asked for.
		 *
		 * @param {object} post the post as the timeline held it
		 */
		redraft(post) {
			this.expand()

			if (this.statusIsEmpty && this.$refs.composerInput !== undefined) {
				this.$refs.composerInput.innerText = htmlToPlainText(post.content || '')
				this.updateStatusContent()
			}

			const warning = (post.spoiler_text || '').trim()
			if (warning !== '') {
				this.showWarning = true
				this.spoilerText = warning
			}

			if (isKnownVisibility(post.visibility)) {
				this.visibility = post.visibility
				this.visibilityChosen = true
			}

			if (isLanguageCode(post.language || '')) {
				this.language = post.language
			}

			const attachments = { ...this.attachments }
			for (const media of post.media_attachments ?? []) {
				if (media?.id === undefined || Object.keys(attachments).length >= this.maxAttachments) {
					continue
				}

				// the same shape an upload leaves behind, with the server's
				// answer already in hand: `data.id` is what goes out as
				// `media_ids`, and `saved` is the description as the server
				// already holds it, so it is not written again unless it is
				// changed
				attachments[`redraft:${++this.pickCount}:${media.id}`] = {
					file: null,
					path: media.description || media.url || String(media.id),
					data: media,
					failed: false,
					description: media.description || '',
					saved: media.description || '',
				}
			}
			this.attachments = attachments

			this.focusInput()
		},

		/**
		 * The reader naming an audience for this post, which settles it.
		 *
		 * @param {string} visibility the id they picked
		 */
		chooseVisibility(visibility) {
			this.visibility = visibility
			this.visibilityChosen = true
		},

		clickImportInput() {
			this.$refs.fileUploadInput.click()
		},

		async handleFileChange(event) {
			const target = event.target
			const files = Array.from(target.files)
			// the input keeps its selection, so picking the same file twice in
			// a row would otherwise be ignored the second time
			target.value = ''

			await this.attachFiles(files)
		},

		/**
		 * Whether a drag is carrying files, as opposed to a selection being
		 * dragged around inside the composer — text moved from one line to the
		 * next is not an attachment and must not light the card up.
		 *
		 * @param {Event} event a drag event
		 * @return {boolean}
		 */
		carriesFiles(event) {
			return Array.from(event.dataTransfer?.types ?? []).includes('Files')
		},

		/**
		 * @param {File} file a dropped or pasted file
		 * @return {boolean} whether the file dialog would have offered it
		 */
		acceptsFile(file) {
			const type = file.type || ''
			return ACCEPTED_MEDIA_TYPES.some((prefix) => type.startsWith(prefix))
				|| ACCEPTED_DOCUMENT_TYPES.includes(type)
				|| ACCEPTED_DOCUMENT_EXTENSIONS.some((extension) => (file.name || '').toLowerCase().endsWith(extension))
		},

		/** @param {DragEvent} event a drag arriving over the card */
		handleDragEnter(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			event.preventDefault()
			this.draggingFiles = true
		},

		/** @param {DragEvent} event a drag moving over the card */
		handleDragOver(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			// without this the browser keeps the drop for itself and opens the
			// file in the tab, which navigates away and takes the draft with it
			event.preventDefault()
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy'
			}
			this.draggingFiles = true
		},

		/**
		 * dragleave fires just as loudly when the pointer crosses from the card
		 * onto one of its own children, which is where the highlight usually
		 * starts flickering. Where the pointer went is the answer: it has only
		 * left when it went somewhere outside this element, or nowhere at all
		 * (relatedTarget is null when the drag leaves the window). A counter of
		 * enters and leaves would answer the same question, but it can only be
		 * repaired by an event that may never come — one missed leave and the
		 * card stays lit for good — while this is decided fresh every time.
		 *
		 * @param {DragEvent} event the drag leaving something
		 */
		handleDragLeave(event) {
			if (!this.draggingFiles) {
				return
			}

			const movedTo = event.relatedTarget
			if (movedTo instanceof Node && this.$el.contains(movedTo)) {
				return
			}

			this.draggingFiles = false
		},

		/** @param {DragEvent} event the drop itself */
		async handleDrop(event) {
			if (!this.carriesFiles(event)) {
				return
			}

			// same reason as dragover: an unhandled drop is a navigation
			event.preventDefault()
			this.draggingFiles = false
			// dropping a picture is a way of starting a post, so a closed
			// composer opens rather than swallowing the file out of sight
			this.expand()

			await this.attachDropped(Array.from(event.dataTransfer?.files ?? []))
		},

		/**
		 * A picture on the clipboard becomes an attachment; everything else is
		 * left to the contenteditable and its autocomplete, exactly as before.
		 *
		 * @param {ClipboardEvent} event the paste
		 */
		handlePaste(event) {
			const files = Array.from(event.clipboardData?.files ?? []).filter((file) => this.acceptsFile(file))
			if (files.length === 0) {
				// text, a link, a mention pasted back in: none of our business
				return
			}

			// otherwise the browser drops the image into the box as markup the
			// post cannot carry
			event.preventDefault()
			this.attachFiles(files)
		},

		/**
		 * Attaches what the composer takes and turns the rest away, which is
		 * all the file dialog does with them — it never offers them at all.
		 *
		 * @param {File[]} files everything that was dropped
		 */
		async attachDropped(files) {
			const accepted = files.filter((file) => this.acceptsFile(file))

			if (accepted.length < files.length) {
				logger.debug('Refused files the composer does not take', { refused: files.length - accepted.length })
				this.refuseDrop()
			}

			if (accepted.length === 0) {
				return
			}

			await this.attachFiles(accepted)
		},

		/** Says no to a drop, briefly and once. */
		refuseDrop() {
			this.refusedDrop = true
			window.clearTimeout(this.refusalTimer)
			this.refusalTimer = window.setTimeout(() => {
				this.refusedDrop = false
			}, REFUSAL_DURATION)
		},

		/**
		 * How many of these there is still room for, with a word about the rest.
		 *
		 * The server refuses the ninth attachment outright, so the refusal
		 * belongs here, where it can still be explained and where the eight
		 * that do fit are not lost with it.
		 *
		 * @param {Array} items files or paths, in the order they were offered
		 * @return {Array} the ones the post can still carry
		 */
		roomFor(items) {
			const room = Math.max(this.maxAttachments - Object.keys(this.attachments).length, 0)
			if (items.length > room) {
				this.announceCeiling()
			}

			return items.slice(0, room)
		},

		/** Says that the post is carrying as much as it can. */
		announceCeiling() {
			showError(translatePlural(
				'social',
				'A post can carry %n attachment',
				'A post can carry %n attachments',
				this.maxAttachments,
			))
		},

		/**
		 * Attaches pictures the reader already has in Nextcloud, without a trip
		 * through the browser: the path is all that is sent.
		 */
		async pickFromFiles() {
			if (this.attachmentsFull) {
				this.announceCeiling()
				return
			}

			let picked
			this.picking = true
			try {
				// imported here rather than at the top: the picker is most of
				// `@nextcloud/dialogs`, and it is wanted only by somebody who
				// has just clicked "attach from Files"
				const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
				picked = await getFilePickerBuilder(translate('social', 'Pick files to attach'))
					.setMultiSelect(true)
					.setMimeTypeFilter(PICKABLE_MEDIA_TYPES)
					.allowDirectories(false)
					// Without this the dialog has **no confirm button at all**:
					// a picker built with neither `addButton` nor
					// `setButtonFactory` renders none, so a file could be
					// selected and there was nothing to press, and the only way
					// out was to close the dialog — which rejects, and attaches
					// nothing. `pick()` resolves with the selection when a
					// button is pressed, so the callback has nothing to do.
					.addButton({
						label: translate('social', 'Attach'),
						variant: 'primary',
						callback: () => {},
					})
					.build()
					.pick()
			} catch (error) {
				// closing the dialog without picking rejects, and changing one's
				// mind is not a failure to report
				logger.debug('The file picker was closed', { error })
				return
			} finally {
				this.picking = false
			}

			const paths = (Array.isArray(picked) ? picked : [picked])
				.filter((path) => typeof path === 'string' && path !== '' && path !== '/')

			if (paths.length === 0) {
				return
			}

			this.expand()
			await this.attachPaths(paths)
		},

		/**
		 * Attaches a picture from the instance's shared library.
		 *
		 * The same shape as attaching from Files: a placeholder goes into the
		 * grid at once so the reader sees that something is happening, and the
		 * server's answer replaces it. The picker stays open — choosing two is
		 * a normal thing to want, and the ceiling closes it instead.
		 *
		 * @param {{slug: string, title: string}} gif the one that was chosen
		 */
		async attachGif(gif) {
			if (this.attachmentsFull) {
				this.announceCeiling()
				this.showGifs = false
				return
			}

			this.expand()

			// the same picture may be chosen twice, and the slug cannot tell
			// those two attachments apart
			const key = `gif:${++this.pickCount}:${gif.slug}`
			this.attachments = {
				...this.attachments,
				[key]: { file: null, path: gif.title || gif.slug, data: null, failed: false },
			}

			this.uploading = true
			this.progressLabel = t('social', 'Attaching…')
			const mediaData = await this.timelineStore.createMediaFromGif({ slug: gif.slug })
			this.uploading = false

			if (this.attachments[key] === undefined) {
				// deleted while the server was copying it
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: {
					...this.attachments[key],
					data: mediaData?.id === undefined ? null : mediaData,
					failed: mediaData?.id === undefined,
				},
			}

			if (this.attachmentsFull) {
				this.showGifs = false
			}
		},

		/**
		 * Asks the server for one attachment per path, in order, keeping the
		 * ones it accepts. A path it refuses is marked and left in the grid:
		 * the others are already attached and must not go down with it.
		 *
		 * @param {string[]} paths files in the reader's own storage
		 */
		async attachPaths(paths) {
			const accepted = this.roomFor(paths)

			this.picking = accepted.length > 0
			this.progressLabel = translate('social', 'Attaching from Files…')
			for (const [index, path] of accepted.entries()) {
				// the same picture may be picked twice, and the path cannot
				// tell those two attachments apart
				const key = `nextcloud:${++this.pickCount}:${path}`
				this.attachments = {
					...this.attachments,
					[key]: { file: null, path, data: null, failed: false },
				}

				this.uploading = true
				this.uploadProgress = index / accepted.length
				const mediaData = await this.timelineStore.createMediaFromFile({ path })
				this.uploadProgress = (index + 1) / accepted.length

				if (this.attachments[key] === undefined) {
					// deleted while the server was fetching it
					continue
				}

				this.attachments = {
					...this.attachments,
					[key]: {
						...this.attachments[key],
						data: mediaData?.id === undefined ? null : mediaData,
						failed: mediaData?.id === undefined,
					},
				}
			}
			this.uploading = false
			this.uploadProgress = 0
			this.progressLabel = ''
			this.picking = false
		},

		/**
		 * Previews each file, uploads it, and remembers what came back. The one
		 * road in: the file dialog, a drop and a paste all arrive here.
		 *
		 * @param {File[]} allFiles the files to attach, in order
		 */
		/**
		 * Bakes a filter into an attachment and replaces the uploaded copy.
		 *
		 * The picture was uploaded the moment it was attached, so choosing a
		 * filter has to replace what is on the server -- the alternative, baking
		 * every filter in at send time, would upload each picture twice and make
		 * pressing Post the slow part.
		 *
		 * Debounced, because flicking through eight filters to see them is the
		 * normal way to use this and should not be eight uploads. The preview is
		 * CSS and updates immediately either way, so the wait is invisible.
		 *
		 * @param {object} change what was chosen
		 * @param {string} change.key the attachment's object URL
		 * @param {string} change.filter the filter id
		 */
		applyFilter({ key, filter }) {
			const attachment = this.attachments[key]
			if (attachment === undefined) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...attachment, filter },
			}

			window.clearTimeout(this.filterTimers[key])
			this.filterTimers[key] = window.setTimeout(() => {
				this.reuploadFiltered(key)
			}, FILTER_DEBOUNCE)
		},

		/**
		 * @param {string} key the attachment's object URL
		 */
		async reuploadFiltered(key) {
			const attachment = this.attachments[key]
			if (attachment?.file === undefined) {
				return
			}

			const filtered = await applyFilterToFile(attachment.file, attachment.filter || 'none')
			// still there? the reader may have deleted it while this ran
			if (this.attachments[key] === undefined) {
				return
			}

			const mediaData = await this.timelineStore.createMedia({ file: filtered })
			if (this.attachments[key] === undefined) {
				return
			}

			if (mediaData?.id === undefined) {
				// the filtered copy would not upload; the unfiltered one is
				// still attached and still perfectly postable
				logger.warn('Could not upload the filtered copy; keeping the original')

				return
			}

			// the description was typed against this picture and belongs to it
			// rather than to the upload it happened to be stored as
			const description = (attachment.description || '').trim()
			if (description !== '') {
				this.timelineStore.describeMedia({ id: mediaData.id, description })
			}
			// and so does the focal point: a filter changes the colours, not
			// where the face is
			if (isFocalPoint(attachment.focus)) {
				this.timelineStore.focusMedia({ id: mediaData.id, focus: focusParam(attachment.focus) })
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...this.attachments[key], data: mediaData, failed: false },
			}
		},

		async attachFiles(allFiles) {
			const files = this.roomFor(allFiles)
			this.progressLabel = translate('social', 'Uploading…')
			for (const [index, file] of files.entries()) {
				const url = URL.createObjectURL(file)
				this.attachments = {
					...this.attachments,
					[url]: {
						file,
						data: null,
						failed: false,
					},
				}

				this.uploading = true
				// real progress, from the request itself: the bar used to be
				// hard-coded to 40% behind a `v-if="false"`
				this.uploadProgress = index / files.length
				const mediaData = await this.timelineStore.createMedia({
					file,
					onProgress: (fraction) => {
						this.uploadProgress = (index + fraction) / files.length
					},
				})
				this.uploading = false
				this.uploadProgress = 0

				if (this.attachments[url] === undefined) {
					// deleted while it was uploading
					continue
				}

				this.attachments = {
					...this.attachments,
					[url]: {
						...this.attachments[url],
						// a failed upload is marked, never left as
						// `data: undefined` for the submit path to trip over
						data: mediaData?.id === undefined ? null : mediaData,
						failed: mediaData?.id === undefined,
					},
				}
			}
			this.progressLabel = ''
		},

		insert(emoji) {
			if (typeof emoji === 'object') {
				const category = Object.keys(emoji)[0]
				const emojis = emoji[category]
				const firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}

			const lastChild = this.$refs.composerInput.lastChild
			const div = document.createElement('div')
			div.textContent = emoji + ' '

			if (lastChild === null) {
				this.$refs.composerInput.innerHTML = div.innerHTML
			} else {
				switch (lastChild.tagName) {
					case 'BR':
						lastChild.before(div.firstChild)
						break
					case 'DIV':
						switch (lastChild.lastChild.tagName) {
							case 'BR':
								lastChild.lastChild.before(div.firstChild)
								break
							default:
								lastChild.append(div.firstChild)
						}
						break
					default:
						lastChild.after(div.firstChild)
				}
			}
			this.updateStatusContent()
		},

		keyup(event) {
			if (event.ctrlKey) {
				this.createPost()
			}
		},

		updatePostFromTribute() {
			this.updateStatusContent()
		},

		n: translatePlural,
		/**
		 * What the poster said the video is, leaving out what they did not say.
		 *
		 * @return {object}
		 */
		videoFields() {
			const fields = {}
			for (const [key, value] of [
				['video_title', this.videoTitle],
				['video_category', this.videoCategory],
				['video_licence', this.videoLicence],
			]) {
				if (value.trim() !== '') {
					fields[key] = value.trim()
				}
			}

			return fields
		},

		async createPost() {
			if (!this.canPost || this.loading) {
				return
			}

			// the games are played before anything is sent, so the result is
			// part of the post and every server shows the same one
			const played = resolveCommands(this.plainText())
			if (played.results.length > 0) {
				this.loading = true
				await this.roll(played.results)
			}
			const status = played.text
			const warning = this.showWarning ? this.spoilerText.trim() : ''

			const statusData = {
				content_type: '',
				// only uploads the server actually took: a failed one used to
				// be read as `preview.data.id` and threw a TypeError here
				media_ids: this.mediaIds,
				// a warning means the body is hidden until asked for, which is
				// what `sensitive` says about the post as a whole
				sensitive: warning !== '',
				spoiler_text: warning,
				status,
				in_reply_to_id: this.replyTo?.id,
				quote_id: this.quoteOf?.id,
				visibility: this.visibility,
				// the team this is written as, when it is written as one. Left
				// out entirely otherwise, so a post as yourself is the request
				// it always was.
				...(this.postAs === '' ? {} : { post_as: this.postAs }),
				// always, so the post is never without one: the server would
				// fill in the same default, but what the poster saw is what goes
				language: this.language,
				// only where this is a video and the poster filled something
				// in: an empty title is not an answer, and the server falls
				// back to the first line of the post as it always did
				...(this.isVideoPost ? this.videoFields() : {}),
			}

			// where it was taken, only ever as the poster said: a known place
			// by its id, a new one by its name
			if (this.place?.id) {
				statusData.place_id = this.place.id
			} else if (this.place?.name) {
				statusData.place_name = this.place.name
				if (this.place.country) {
					statusData.place_country = this.place.country
				}
			}

			// ISO 8601 in UTC, which is what `scheduled_at` takes; the picker
			// works in the reader's zone and the Date carries the conversion
			if (this.scheduling && this.scheduledAt instanceof Date) {
				statusData.scheduled_at = this.scheduledAt.toISOString()
			}

			// a short post as a card: the words drawn on colour and attached
			// as a picture, described with those same words
			if (this.asCard && this.canBeCard) {
				const card = await this.uploadCard(status)
				if (card === null) {
					this.loading = false
					return
				}
				statusData.media_ids = [...statusData.media_ids, card]
			}

			const pollOptions = this.pollOptions.map((option) => option.trim()).filter((option) => option !== '')
			if (this.showPoll && pollOptions.length >= 2) {
				statusData.poll = {
					options: pollOptions,
					expires_in: this.pollExpiresIn,
					multiple: this.pollMultiple,
				}
			}

			logger.debug('Posting status', {
				visibility: statusData.visibility,
				attachments: statusData.media_ids.length,
				scheduled: statusData.scheduled_at !== undefined,
			})

			let created
			try {
				this.loading = true
				await this.saveDescriptions()
				// `post` resolves with the created status and rejects when the
				// server said no; clearing in a `finally` used to throw the
				// text away on every failure, offline included
				created = await this.timelineStore.post(statusData)
			} finally {
				this.loading = false
			}

			if (created === undefined) {
				// the store has already said what went wrong; the draft is
				// still on disk and still in the box
				this.rememberDraft()
				return
			}

			const wasScheduled = statusData.scheduled_at !== undefined

			this.replyTo = this.inReplyTo
			this.quoteOf = null
			if (this.inReplyTo !== null) {
				// back to a line under the post, as it was before the reader
				// clicked into it
				this.openedByHand = this.startExpanded
			}
			this.clearComposer()
			// the sidebar's modal has no other way of knowing: it cleared the
			// box and stayed open, which reads as if nothing had happened
			this.$emit('posted')

			if (wasScheduled) {
				// nothing is on any timeline yet, so there is nothing to
				// refresh and no post to celebrate; what there is to say is
				// when it will be — the answer is a ScheduledStatus, not a Status
				showSuccess(translate('social', 'Scheduled for {date}', {
					date: fullDateTime(created.scheduled_at ?? statusData.scheduled_at),
				}))
				eventBus.emit('post-scheduled', created)

				return
			}

			if (created.held_for_review === true) {
				// there is nothing on any timeline to refresh and nothing to
				// celebrate: the post is in the review queue, and the store has
				// already said so
				return
			}

			// heard, felt and seen: the box answers, and the post makes an
			// entrance at the top of the timeline instead of simply being there
			feel('post')
			this.justPosted = true
			window.setTimeout(() => {
				this.justPosted = false
			}, 700)
			if (created?.id) {
				this.timelineStore.markArrived(created.id)
			}
			this.timelineStore.refreshTimeline()
			eventBus.emit('post-published', created)
		},

		/**
		 * Presses or releases the clock. Pressing it fetches the picker and
		 * proposes an hour from now, rounded to the picker's step, so there is
		 * a time to move rather than a blank to fill.
		 */
		/**
		 * Presses or releases the pin. Releasing it drops the place as well:
		 * a pin that is not pressed says the post has no place, and it should
		 * mean it.
		 */
		/**
		 * Plays the games in a post where the writer can watch: each one
		 * tumbles through what it could land on for half a second and then
		 * lands on what it did. The result was decided before the tumble
		 * started; this is only the showing of it.
		 *
		 * @param {Array<{ kind: string, result: string }>} results what was played
		 * @return {Promise<void>}
		 */
		/** @param {object} option a background @return {string} it as CSS */
		backgroundOf(option) {
			return gradientCss(option)
		},

		/**
		 * Draws the post as a card and uploads it.
		 *
		 * @param {string} words what the card says
		 * @return {Promise<string|null>} the attachment's id, or null when it
		 *         could not be drawn or uploaded -- the post then does not go
		 *         out, rather than going out without the card it was meant to be
		 */
		async uploadCard(words) {
			this.loading = true
			const file = await renderTextCard(words, this.cardGradient, { width: 1080, height: 1080 })
			if (file === null) {
				showError(translate('social', 'This browser could not draw the card'))
				return null
			}

			const media = await this.timelineStore.createMedia(file)
			if (!media?.id) {
				return null
			}

			await this.timelineStore.describeMedia({ id: media.id, description: words })

			return media.id
		},

		async roll(results) {
			const icons = { dice: '🎲', flip: '🪙', pick: '🎯' }
			const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms))
			const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true

			this.rollLanded = false
			this.rolling = results.map((played) => ({ icon: icons[played.kind], shown: tumble(played), played }))
			feel('roll')

			if (!still) {
				for (let step = 0; step < 8; step++) {
					await wait(65)
					this.rolling = this.rolling.map((die) => ({ ...die, shown: tumble(die.played) }))
				}
			}

			this.rolling = this.rolling.map((die) => ({ ...die, shown: die.played.result }))
			this.rollLanded = true
			await wait(still ? 250 : 500)
			this.rolling = []
		},

		togglePlace() {
			this.placing = !this.placing
			if (!this.placing) {
				this.place = null
			}
		},

		async toggleSchedule() {
			if (this.scheduling) {
				this.scheduling = false
				this.scheduledAt = null

				return
			}

			this.scheduling = true
			this.scheduledAt = proposedSchedule()
			if (this.schedulePickerLoaded || this.schedulePickerLoading) {
				return
			}

			this.schedulePickerLoading = true
			try {
				await datePickerModule()
				this.schedulePickerLoaded = true
			} catch (error) {
				// the module's onError has logged it; without a picker there is
				// no way to choose a time, so the post goes out now after all
				logger.debug('The date picker is not available', { error })
				this.scheduling = false
				this.scheduledAt = null
			} finally {
				this.schedulePickerLoading = false
			}
		},

		toggleWarning() {
			this.showWarning = !this.showWarning
			if (!this.showWarning) {
				this.spoilerText = ''
			}
		},

		togglePoll() {
			this.showPoll = !this.showPoll
			if (!this.showPoll) {
				this.pollOptions = ['', '']
				this.pollMultiple = false
			}
		},

		closeReply() {
			this.replyTo = this.inReplyTo
			// an anchored composer is part of the page rather than something
			// opened over it: there is nothing to close it back to, so it
			// closes itself instead
			if (this.inReplyTo === null) {
				this.timelineStore.setComposerDisplayStatus(false)

				return
			}

			this.openedByHand = this.startExpanded
		},

		removeQuote() {
			// only the quote goes, unlike closeReply(): the message is the
			// reader's own and taking the embed back is no reason to lose it
			this.quoteOf = null
		},

		remoteSearchAccounts(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), { params: { search: text } })
		},

		remoteSearchHashtags(text) {
			return axios.get(generateUrl('apps/social/api/v1/global/tags/search'), { params: { search: text } })
		},

		deletePreview(key) {
			const newAttachments = { ...this.attachments }
			delete newAttachments[key]
			this.attachments = newAttachments
			this.releasePreview(key)
		},

		/**
		 * Lets go of the blob URL a preview was drawn from. Without this the
		 * file stays in memory for the life of the document.
		 *
		 * @param {string} key the attachment key, which is that URL
		 */
		releasePreview(key) {
			// an attachment picked out of Files is keyed by its path: there is
			// no object URL behind it to let go of
			if (!key.startsWith('blob:')) {
				return
			}

			try {
				URL.revokeObjectURL(key)
			} catch (error) {
				logger.debug('Could not release a preview URL', { error })
			}
		},

		/**
		 * Remembers what an attachment shows. Kept locally while the post is
		 * being written and sent when it goes, rather than on every keystroke.
		 *
		 * @param {object} update what changed
		 * @param {string} update.key which attachment
		 * @param {string} update.description what it shows
		 */
		/**
		 * Sends whatever descriptions were written, once, as the post goes.
		 *
		 * Saving per keystroke would be a request per letter; saving here means
		 * the description travels with the post that carries the picture.
		 */
		async saveDescriptions() {
			const described = Object.values(this.attachments).filter((attachment) => attachment.data?.id
				&& (attachment.description || '').trim() !== ''
				&& (attachment.description || '').trim() !== attachment.saved)

			await Promise.all(described.map((attachment) => this.timelineStore.describeMedia({
				id: attachment.data.id,
				description: attachment.description.trim(),
			})))
		},

		/**
		 * Saves what an attachment shows as soon as the field is left, so a
		 * description outlives a post that never went out.
		 *
		 * @param {object} update what was written
		 * @param {string} update.key which attachment
		 * @param {string} update.description what it shows
		 */
		async commitDescription({ key, description }) {
			const attachment = this.attachments[key]
			const text = (description || '').trim()
			if (attachment?.data?.id === undefined || text === '' || text === attachment.saved) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...attachment, description, saved: text },
			}

			await this.timelineStore.describeMedia({ id: attachment.data.id, description: text })
		},

		describeAttachment({ key, description }) {
			if (this.attachments[key] === undefined) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...this.attachments[key], description },
			}
		},

		/**
		 * Moves an attachment's focal point, locally: what the crosshair shows
		 * while it is being dragged.
		 *
		 * @param {object} update what changed
		 * @param {string} update.key which attachment
		 * @param {import('../../utils/focalPoint.js').FocalPoint} update.focus where the subject is
		 */
		focusAttachment({ key, focus }) {
			if (this.attachments[key] === undefined || !isFocalPoint(focus)) {
				return
			}

			this.attachments = {
				...this.attachments,
				[key]: { ...this.attachments[key], focus },
			}
		},

		/**
		 * Saves where the subject is once the drag is over, through the same
		 * request the description takes.
		 *
		 * @param {object} update what was set
		 * @param {string} update.key which attachment
		 * @param {import('../../utils/focalPoint.js').FocalPoint} update.focus where the subject is
		 */
		async commitFocus({ key, focus }) {
			const attachment = this.attachments[key]
			if (attachment?.data?.id === undefined || !isFocalPoint(focus)) {
				return
			}

			this.focusAttachment({ key, focus })
			await this.timelineStore.focusMedia({ id: attachment.data.id, focus: focusParam(focus) })
		},
	},
}

/**
 * The visibility the last post went out with.
 *
 * Reading localStorage throws outright in a private window and where site
 * data is blocked, and an unguarded read here took the whole composer down
 * with it.
 *
 * @return {string} the remembered visibility, or '' when there is none
 */
function rememberedVisibility() {
	let remembered
	try {
		remembered = window.localStorage.getItem(userKey('social.lastPostType')) ?? ''
	} catch {
		return ''
	}

	return isKnownVisibility(remembered) ? remembered : ''
}

/**
 *
 * @param node
 */
function nodeToPlainText(node) {
	let text = ''
	for (const child of Array.from(node.childNodes)) {
		if (child.nodeType === Node.TEXT_NODE) {
			text += child.textContent || ''
			continue
		}

		if (child.nodeType !== Node.ELEMENT_NODE) {
			continue
		}

		const element = child
		if (element.tagName === 'BR') {
			text += '\n'
			continue
		}

		const isBlock = ['DIV', 'P', 'LI', 'BLOCKQUOTE', 'PRE'].includes(element.tagName)
		if (isBlock && text !== '' && !text.endsWith('\n')) {
			text += '\n'
		}

		text += nodeToPlainText(element)
		if (isBlock && !text.endsWith('\n')) {
			text += '\n'
		}
	}

	return text
}
</script>

<style scoped lang="scss">
@use '../../styles/layout.scss' as layout;

.video-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block-end: 8px;
}

.video-row__pair {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;

	> input {
		flex: 1 1 10em;
		min-width: 0;
	}
}

.video-row__title {
	width: 100%;
}

// one duration and one curve for the whole opening, so the parts of it arrive
// together rather than each on its own schedule
$composer-ease: cubic-bezier(0.25, 0.8, 0.35, 1);
$composer-duration: 220ms;

.new-post {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 18px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	// the full column, where the list only fills it inside its own gutter: the
	// box you write in reaches a little past the posts it will join on both
	// sides, so it reads as the thing that makes them rather than one of them
	max-width: var(--social-column);
	position: sticky;
	top: 0;
	z-index: 100;
	box-shadow: var(--social-elevation-resting);
	transition:
		padding $composer-duration $composer-ease,
		border-color $composer-duration $composer-ease,
		background-color $composer-duration $composer-ease,
		box-shadow $composer-duration $composer-ease;

	// lifted, not outlined: the box the caret is in draws the ring, and two
	// nested rings around the same caret is one too many
	&:focus-within {
		box-shadow: var(--social-elevation-raised);
	}

	&-form {
		margin-top: 12px;
		margin-inline-start: 0;
		transition: margin-top $composer-duration $composer-ease;

		&__emoji-picker {
			z-index: 1;
		}
	}
}

// Closed: a portrait and a line to write on. Everything else is still in the
// document — it is measured, not removed, so the opening can be animated — but
// it is out of the tab order and out of the accessibility tree until it is.
.new-post--collapsed {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 12px;

	.new-post-author {
		padding-bottom: 0;
		margin-bottom: 0;
		border-bottom: none;
	}

	.new-post-form {
		flex: 1 1 auto;
		min-width: 0;
		margin-top: 0;
	}

	.message {
		min-height: 0;
		padding: 9px 16px;
		border-radius: 999px;
	}

	.options {
		max-height: 0;
		margin-top: 0;
		opacity: 0;
		visibility: hidden;
		pointer-events: none;
		transform: translateY(-4px);
	}
}

// A file is being dragged over the card: the whole thing is the target, said
// with a tint and the primary border it already uses for focus, plus one pill
// naming what will happen. No dashes, no bounce — it is an invitation, not an
// alarm, and it borrows the composer's own duration and curve.
.new-post--drop-target {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light, var(--color-background-hover));

	.message {
		border-color: var(--color-primary-element);
	}
}

.new-post__drop-hint {
	position: absolute;
	inset: 0;
	z-index: 2;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: inherit;
	// it must never take the drop it is announcing, and it must not turn the
	// card into a storm of dragenter/dragleave by sitting under the pointer
	pointer-events: none;

	span {
		padding: 6px 14px;
		border-radius: var(--border-radius-pill, 999px);
		background: var(--color-primary-element);
		color: var(--color-primary-element-text, var(--color-primary-text));
		font-size: 13px;
		font-weight: 600;
		box-shadow: var(--social-elevation-raised);
		animation: composer-drop-hint $composer-duration $composer-ease;
	}
}

@keyframes composer-drop-hint {
	0% { opacity: 0; transform: scale(.94); }
	100% { opacity: 1; transform: scale(1); }
}

/* what was dropped is not something the composer can take */
@keyframes composer-refused {
	0%, 100% { transform: translateX(0); }
	25% { transform: translateX(-4px); }
	75% { transform: translateX(4px); }
}

.new-post--refused {
	// 400ms, the same span REFUSAL_DURATION keeps the class on for
	animation: composer-refused 400ms $composer-ease;
}

@media (prefers-reduced-motion: reduce) {
	.new-post,
	.new-post .new-post-form,
	.new-post .message,
	.new-post .options {
		transition: none;
	}

	.new-post--refused,
	.new-post__drop-hint span {
		animation: none;
	}
}

.new-post-author {
	display: flex;
	align-items: center;
	gap: 10px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 10px;

	// pushed to the far end of the row, where a close button is looked for
	&__close {
		margin-inline-start: auto;
	}

	.post-author {
		display: flex;
		align-items: center;

		.post-author-name {
			font-weight: 700;
			font-size: 14px;
			line-height: 1.3;
		}
	}
}

.reply-to {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 12px 12px 12px 36px;
	margin-bottom: 12px;
	position: relative;

	&::before {
		content: '';
		position: absolute;
		inset-inline-start: 12px;
		top: 12px;
		width: 16px;
		height: 16px;
		background-image: url(../../../img/reply.svg);
		background-size: contain;
		background-repeat: no-repeat;
	}

	.avatardiv {
		margin: 0 4px;
		vertical-align: middle;
	}

	.reply-info {
		display: flex;
		align-items: center;
		gap: 4px;
		font-size: 13px;
		color: var(--color-text-lighter);
		margin-bottom: 4px;
	}

	.close-button {
		margin-inline-start: auto;
		min-width: 28px;
		min-height: 28px;
		height: 28px;
		width: 28px !important;
	}
}

/* set in and ruled off, the same way a quote reads in the timeline */
.quote-of {
	background: var(--color-background-hover);
	border-inline-start: 3px solid var(--color-border-dark);
	border-radius: 8px;
	padding: 12px;
	margin-bottom: 12px;
	font-size: 14px;

	.avatardiv {
		margin: 0 4px;
		vertical-align: middle;
	}

	.quote-info {
		display: flex;
		align-items: center;
		gap: 4px;
		font-size: 13px;
		color: var(--color-text-lighter);
		margin-bottom: 4px;
	}

	.close-button {
		margin-inline-start: auto;
		min-width: 28px;
		min-height: 28px;
		height: 28px;
		width: 28px !important;
	}
}

.message {
	width: 100%;
	min-height: 80px;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	transition:
		min-height $composer-duration $composer-ease,
		padding $composer-duration $composer-ease,
		border-radius $composer-duration $composer-ease,
		border-color $composer-duration $composer-ease;
	background: var(--color-main-background);
	font-size: 14px;
	line-height: 1.6;
	color: var(--color-main-text);
	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}

	&.too-long {
		color: var(--color-error);
		border-color: var(--color-error);
	}

	:deep(.mention) {
		color: var(--color-primary-element);
		background-color: var(--color-background-dark);
		border-radius: 4px;
		padding: 1px 6px 1px 2px;
		display: inline-flex;
		align-items: center;

		img {
			width: 16px;
			height: 16px;
			border-radius: 50%;
			margin-inline-end: 3px;
		}
	}
}

// Photo-first: the picture is the post and the box under it is its caption,
// so the box gives up the height it was holding for a post that has no picture.
// Nothing is moved or hidden — the same controls in the same order, weighted
// the other way round.
.new-post-form--media-first {
	:deep(.preview-grid) {
		margin-bottom: 10px;
	}

	.message--caption {
		min-height: 44px;
	}
}

[contenteditable=true]:empty:before {
	content: attr(placeholder);
	display: block;
	color: var(--color-text-lighter);
}

.options {
	display: flex;
	align-items: center;
	// the row has grown a control at a time -- attachments, files, a warning,
	// a picture library, a preview, a poll, a clock, emoji, a language, an
	// audience -- and the last thing in it is the button that sends the post.
	// Left in one line they all shrink together and the button loses its
	// words: "Post to followers" became "P". The icons wrap instead, and the
	// button keeps the width its label needs.
	flex-wrap: wrap;
	gap: 4px 8px;
	margin-top: 10px;
	max-height: 120px;
	opacity: 1;
	overflow: hidden;
	transform: translateY(0);
	transition:
		max-height $composer-duration $composer-ease,
		margin-top $composer-duration $composer-ease,
		opacity $composer-duration $composer-ease,
		transform $composer-duration $composer-ease,
		visibility $composer-duration step-start;
}

.emptySpace {
	flex-grow: 1;
}

/*
 * Whatever else has to give, the button that sends the post does not: it
 * keeps its width, and when the icons above it wrap it stays at the end of
 * the row rather than starting a new one on the left.
 */
.options > :last-child {
	flex-shrink: 0;
	margin-inline-start: auto;
}

/*
 * A phone. Seven controls and a Post button do not fit one row of 358px, and
 * the row used to end with half the button off the screen. The row wraps: the
 * spacer goes, the visibility menu shows its icon only, and Post keeps the
 * end of whatever row it lands on.
 */
@include layout.below(layout.$phone) {
	.options {
		flex-wrap: wrap;
		row-gap: 4px;
		max-height: 120px;

		.emptySpace {
			display: none;
		}

		:deep(.action-item__menutoggle .button-vue__text) {
			display: none;
		}

		> :last-child {
			margin-inline-start: auto;
		}
	}
}

.hashtag {
	color: var(--color-primary-element);
	text-decoration: none;
}
</style>

<style lang="scss">
.tribute-container {
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	height: auto;
	max-height: 300px;
	max-width: 500px;
	min-width: 200px;
	overflow: auto;
	display: block;
	z-index: 999999;
	border-radius: 8px;
	border: 1px solid var(--color-border);

	ul {
		margin: 0;
		margin-top: 2px;
		padding: 4px;
		list-style: none;
		background: var(--color-main-background);
		border-radius: 8px;
		background-clip: padding-box;
		overflow: hidden;

		li {
			color: var(--color-text);
			padding: 6px 10px;
			cursor: pointer;
			font-size: 14px;
			display: flex;
			border-radius: 6px;
			margin: 2px 0;

			span {
				display: block;
				font-weight: bold;
			}

			&.highlight,
			&:hover {
				background: var(--color-primary);
				color: var(--color-primary-text);
			}

			img {
				width: 32px;
				height: 32px;
				border-radius: 50%;
				overflow: hidden;
				margin-inline: -3px 10px;
				margin-top: 3px;
			}

			&.no-match {
				cursor: default;
			}
		}
	}

	.menu-highlighted {
		font-weight: bold;
	}

	.account,
	li.highlight .account,
	li:hover .account {
		font-weight: normal;
		color: var(--color-text-light);
	}

	li.highlight .account,
	li:hover .account {
		color: var(--color-primary-text) !important;
	}
}

.schedule-editor__remove {
	margin-inline-start: auto;
}

/* the allowance as a ring that fills, rather than a limit you discover */
.composer-alt-warning {
	align-self: center;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	font-weight: 600;
}

.char-ring {
	position: relative;
	width: 24px;
	height: 24px;
	flex-shrink: 0;
	align-self: center;
	border-radius: 50%;
	background: conic-gradient(
		var(--color-primary-element) calc(var(--char-progress) * 360deg),
		var(--color-background-dark) 0
	);
	transition: background .2s ease;

	&::after {
		content: '';
		position: absolute;
		inset: 3px;
		border-radius: 50%;
		background: var(--color-main-background);
	}

	&--warning {
		background: conic-gradient(
			var(--color-warning) calc(var(--char-progress) * 360deg),
			var(--color-background-dark) 0
		);
	}

	&--over {
		background: var(--color-error);
	}

	&__count {
		position: absolute;
		inset: 0;
		z-index: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 10px;
		font-weight: bold;
		color: var(--color-main-text);
	}
}

@media (prefers-reduced-motion: reduce) {
	.char-ring {
		transition: none;
	}
}

/*
 * The box answers a post that went out: a quick bright sweep across it, in the
 * primary colour, the way a stamp lands. A pseudo-element so the composer's
 * own layout and elevation are left alone.
 */
@keyframes new-post-sent {
	0% { opacity: 0; transform: translateX(-100%); }
	30% { opacity: .5; }
	100% { opacity: 0; transform: translateX(100%); }
}

.new-post--posted {
	overflow: hidden;

	&::after {
		content: '';
		position: absolute;
		inset: 0;
		background: linear-gradient(100deg, transparent 20%, var(--color-primary-element-light) 50%, transparent 80%);
		pointer-events: none;
		animation: new-post-sent .6s ease-out both;
	}
}

/* a short post as a card: the square it will be drawn as, and its colours */
.composer-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	margin-block: 10px 0;

	&__preview {
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: min(100%, 280px);
		aspect-ratio: 1;
		padding: 10%;
		border-radius: var(--border-radius-large, 12px);
		animation: composer-card-in .35s cubic-bezier(.3, 1.3, .5, 1) both;
	}

	&__text {
		margin: 0;
		font-size: clamp(15px, calc(34px - var(--card-length, 0) * .15px), 30px);
		font-weight: 700;
		line-height: 1.25;
		text-align: center;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__gradients {
		display: flex;
		flex-wrap: wrap;
		justify-content: center;
		gap: 8px;
	}

	&__gradient {
		inline-size: 30px;
		block-size: 30px;
		padding: 0;
		border: 3px solid var(--color-main-background);
		border-radius: 50%;
		outline: 2px solid transparent;
		cursor: pointer;

		&[aria-checked="true"] {
			outline-color: var(--color-primary-element);
		}

		&:focus-visible {
			outline-color: var(--color-main-text);
		}
	}

	&__hint {
		max-inline-size: 42ch;
		margin: 0;
		font-size: 12px;
		text-align: center;
		color: var(--color-text-maxcontrast);
	}
}

@keyframes composer-card-in {
	from { opacity: 0; transform: scale(.85) rotate(-3deg); }
	to { opacity: 1; transform: none; }
}

@media (prefers-reduced-motion: reduce) {
	.composer-card__preview {
		animation: none;
	}
}

/* the games: a quiet line while one is typed, a row of tumbling dice when played */
.composer-games {
	margin-block: 4px 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

@keyframes composer-tumble {
	0%, 100% { transform: rotate(0); }
	25% { transform: rotate(-14deg) translateY(-2px); }
	75% { transform: rotate(12deg) translateY(1px); }
}

@keyframes composer-land {
	0% { transform: scale(1); }
	45% { transform: scale(1.25); }
	100% { transform: scale(1); }
}

.composer-roll {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block: 8px 0;

	&__die {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 4px 12px;
		border-radius: var(--border-radius-pill, 999px);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: 600;
		font-variant-numeric: tabular-nums;
		animation: composer-tumble .26s linear infinite;
	}

	&--landed &__die {
		animation: composer-land .32s cubic-bezier(.3, 1.4, .5, 1) both;
	}
}

@media (prefers-reduced-motion: reduce) {
	.composer-roll__die,
	.composer-roll--landed .composer-roll__die {
		animation: none;
	}
}

@media (prefers-reduced-motion: reduce) {
	.new-post--posted::after {
		animation: none;
		display: none;
	}
}

.content-warning {
	width: 100%;
	margin-bottom: 6px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;

	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

/* the box and the presses that fill it in are one thing on the form */
.content-warning-row {
	margin-bottom: 6px;

	&__field {
		display: flex;
		align-items: center;
		gap: 4px;
	}

	.content-warning {
		flex-grow: 1;
		margin-bottom: 4px;
	}
}

.content-warning-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.content-warning-presets__item {
	margin: 0;
	padding: 2px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	line-height: 20px;
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		color: var(--color-main-text);
	}

	/* the one the box is currently showing, so pressing it again to clear it
	   is an obvious thing to do rather than a discovery */
	&--active {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
		color: var(--color-main-text);
	}
}

.composer-post-as {
	max-width: 180px;
	height: 34px;
	margin-inline-end: 4px;
}

</style>
