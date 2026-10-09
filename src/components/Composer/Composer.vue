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
					v-if="offerShort"
					:title="t('social', 'New short')"
					variant="tertiary"
					:aria-label="t('social', 'New short')"
					@click.prevent="composeShort">
					<template #icon>
						<CameraOutline :size="22" decorative title="" />
					</template>
				</NcButton>
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
					v-if="hasFiles"
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
				<!-- the author's own word that this was made with AI. It is a
				     hashtag in the text rather than a field of its own, so the
				     button reads its state off the words: typing the tag by
				     hand lights it, and pressing it writes or removes the tag -->
				<NcButton
					:title="t('social', 'Made with AI')"
					variant="tertiary"
					class="ai-toggle"
					:aria-label="t('social', 'Made with AI')"
					:aria-pressed="madeWithAi"
					@click.prevent="toggleAiMark">
					<template #icon>
						<CreationOutline :size="22" decorative title="" />
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
				<ReplyPolicySelect
					v-if="visibility !== 'direct'"
					:policy="replyPolicy"
					@update:policy="replyPolicy = $event" />
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
			<!-- Social's own limit stays the counter's; this is the other
			     network's, said once it matters and not before -->
			<p v-if="blueskyHint" class="composer-bluesky-hint">
				{{ t('social', 'Bluesky shows the first 280 characters and a link to the full post.') }}
			</p>
		</form>
		<!-- the way to a 24-hour short on the one line the composer is at rest; open,
		     the same button is in the toolbar above -->
		<NcButton
			v-if="offerShort && !expanded"
			class="new-post__short"
			variant="tertiary"
			:title="t('social', 'New short')"
			:aria-label="t('social', 'New short')"
			@click="composeShort">
			<template #icon>
				<CameraOutline :size="22" />
			</template>
		</NcButton>
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import CameraOutline from 'vue-material-design-icons/CameraOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderImage from 'vue-material-design-icons/FolderImage.vue'
import FileGifBox from 'vue-material-design-icons/FileGifBox.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import CreationOutline from 'vue-material-design-icons/CreationOutline.vue'
import PlacePicker from './PlacePicker.vue'
import SchedulePicker from './SchedulePicker.vue'
import { computed, defineAsyncComponent, getCurrentInstance, ref } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { showError, showSuccess } from '../../services/toast.js'
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
import ReplyPolicySelect from './ReplyPolicySelect.vue'
import { isKnownVisibility } from '../Visibility/VisibilitiesInfos.js'
import SubmitStatusButton from './SubmitStatusButton.vue'
import MessageContent from '../MessageContent.js'
import Tribute from 'tributejs'
import { mentionTributeOptions } from '../../utils/mentionTribute.js'
import eventBus, { SHORT_COMPOSE } from '../../services/eventBus.js'
import { emojiPickerModule } from '../../services/emojiPicker.js'
import logger from '../../services/logger.js'
import { feel } from '../../services/senses.js'
import { commandsIn, resolveCommands, tumble } from '../../utils/composerCommands.js'
import { accountHue } from '../../services/accountColour.js'
import { cardGradients, findGradient, gradientCss, renderTextCard } from '../../utils/textCard.js'
import { clearDraft, loadDraft, saveDraft } from '../../services/draft.js'
import { addAiMark, hasAiMark, removeAiMark } from '../../services/aiContent.js'
import { mapStores } from 'pinia'
import { useInstanceStore } from '../../store/instance.js'
import { useAccountStore } from '../../store/account.js'
import { useTimelineStore } from '../../store/timeline.js'
import { editableToPlainText, htmlToPlainText } from '../../utils/plainText.js'
import { mentionPills, participantsOf } from '../../utils/replyMentions.js'
import { statusPayload } from '../../utils/statusPayload.js'
import { defaultLanguage, isLanguageCode, rememberedLanguage } from '../../utils/postLanguage.js'
import { fullDateTime } from '../../utils/relativeTime.js'
import { datePickerModule, isTooSoon, proposedSchedule } from '../../utils/schedule.js'
import { useComposerAttachments } from '../../composables/useComposerAttachments.js'
import { useCurrentUser } from '../../composables/useCurrentUser.js'
import { useServerData } from '../../composables/useServerData.js'
import { userKey } from '../../utils/browserStore.js'

/*
 * The two limits -- characters per post, attachments per post -- used to be
 * constants here; they are the server's, read from the instance entity into
 * the instance store, and appear below as `maxLength` and `maxAttachments`.
 */

/** Where Bluesky cuts a public post from here and adds a link to the rest. */
const BLUESKY_GRAPHEMES = 280

/**
 * How many characters a person sees, which is how Bluesky counts: a flag or
 * a family emoji is one, not the four to seven code points behind it.
 *
 * @param {string} text what would be sent
 * @return {number}
 */
function graphemes(text) {
	if (typeof Intl.Segmenter !== 'function') {
		return Array.from(text).length
	}

	return [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text)].length
}

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
		CameraOutline,
		Close,
		FolderImage,
		AlertOutline,
		EyeOutline,
		PollIcon,
		CardTextOutline,
		CreationOutline,
		PollEditor,
		PreviewGrid,
		ComposerPreview,
		FileGifBox,
		LanguageSelect,
		VisibilitySelect,
		ReplyPolicySelect,
		SubmitStatusButton,
		MessageContent,
	},

	directives: {
		FocusOnCreate,
	},

	props: {
		/**
		 * Whether the camera for a new 24-hour short is offered: only above
		 * the home feed, where the Shorts bar that opens the dialog is.
		 */
		offerShort: {
			type: Boolean,
			default: false,
		},

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
		 */
		inReplyTo: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},

		/**
		 * Files to attach as soon as the composer is up: what "Share to
		 * Social" in the Files app hands over. Paths in the reader's own
		 * folder, the same the picker produces; attached through the same
		 * code, so the ceiling, the progress and a refusal look the same.
		 */
		initialPaths: {
			type: /** @type {import('vue').PropType<string[]>} */ (Array),
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
	setup(props) {
		const { hostname, serverData } = useServerData()
		const { currentUser } = useCurrentUser()
		// a self-registered external user has no Files to attach from
		const hasFiles = computed(() => !serverData.value?.externalMedia)

		// what a click into the box opens up; the composer is also expanded
		// by anything it already holds — see expanded()
		const openedByHand = ref(props.startExpanded)
		// and what the close button shuts again. It has to be a state of its
		// own rather than the absence of `openedByHand`, because a box with a
		// word in it is expanded by the word: without this there was no way to
		// close one except by deleting what was in it.
		const closedByHand = ref(false)
		const expand = () => {
			closedByHand.value = false
			openedByHand.value = true
		}
		const instance = getCurrentInstance()

		return {
			hostname,
			serverData,
			hasFiles,
			currentUser,
			openedByHand,
			closedByHand,
			expand,
			...useComposerAttachments({ expand, root: () => instance?.proxy?.$el }),
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
			/** who may reply to it, as `reply_policy` says it */
			replyPolicy: 'everyone',
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
			tributeOptions: mentionTributeOptions(),

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

		/** @return {boolean} whether the words carry the author's mark for a post made with AI */
		madeWithAi() {
			return hasAiMark(this.statusText)
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

		/**
		 * What the box asks for. With a picture above it, the post is the
		 * picture and the words underneath it are its caption. A post of its
		 * own is asked for by first name.
		 *
		 * @return {string}
		 */
		prompt() {
			if (this.hasAttachments) {
				return translate('social', 'Write a caption…')
			}
			if (this.replyTo !== null) {
				return translate('social', 'Write a reply…')
			}
			if (this.quoteOf !== null || this.firstName === '') {
				return translate('social', 'What would you like to share?')
			}

			return translate('social', 'Aloha, {name}. What’s new?', { name: this.firstName })
		},

		/**
		 * The first word of the reader's display name, or their user id when
		 * they have no display name.
		 *
		 * @return {string}
		 */
		firstName() {
			const name = (this.currentUser?.displayName || this.currentUser?.uid || '').trim()
			return name.split(/\s+/)[0]
		},

		/**
		 * Which draft this box is writing: a reply to one post, a quote of
		 * one, or a post of its own. A reply opened over a half-written post
		 * used to take its place on disk, and sending either threw the
		 * other away.
		 *
		 * @return {string} '' for a post of its own
		 */
		draftContext() {
			if (this.replyTo !== null) {
				return `reply.${this.replyTo.id}`
			}
			if (this.quoteOf !== null) {
				return `quote.${this.quoteOf.id}`
			}

			return ''
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

		/**
		 * Whether to say that Bluesky will cut this post: only a public post
		 * goes there, and only when it is longer than what Bluesky shows.
		 *
		 * @return {boolean}
		 */
		blueskyHint() {
			return this.serverData?.bluesky?.enabled === true
				&& this.visibility === 'public'
				&& graphemes(this.statusText) > BLUESKY_GRAPHEMES
		},
	},

	watch: {
		/**
		 * Another post's page, in the same component: the router reuses this
		 * view, so without this the box would still be replying to the post
		 * the reader has navigated away from.
		 *
		 * @param {object|null} post the post to reply to now
		 */
		inReplyTo(post) {
			this.replyTo = post
		},

		// the warning is part of the draft, and it has its own field
		spoilerText: 'rememberDraft',
		showWarning: 'rememberDraft',
		visibility: 'rememberDraft',

		/**
		 * The words stay in the box when it is pointed at another post, or
		 * back at none, so the draft on disk moves with them rather than
		 * staying behind as a copy that would come back on the next visit.
		 *
		 * @param {string} _now the context being written now
		 * @param {string} before the one just left
		 */
		draftContext(_now, before) {
			clearDraft(before)
			this.rememberDraft()
		},

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
		this.tributeTarget = this.inputElement()
		this.tribute.attach(this.tributeTarget)

		// Keep the handler so unmounted() removes only this one and not the
		// listeners other components registered for the same event.
		this.onComposerReply = (data) => {
			this.replyTo = data
			// everyone in the conversation, not only whoever wrote the post
			// being answered: a reply that named one of three people reached
			// one of three people
			this.prefillMessageWithMentions(participantsOf(data, this.currentUser.uid, this.hostname))
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
		/** Asks the Shorts bar for New short, on 24 hours. */
		composeShort() {
			eventBus.emit(SHORT_COMPOSE)
		},

		/** @return {HTMLElement} the element the post is written in */
		inputElement() {
			return /** @type {HTMLElement} */ (this.$refs.composerInput)
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
			if (this.inputElement() !== undefined) {
				this.inputElement().innerText = ''
			}
			this.clearAttachments()
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
			this.replyPolicy = 'everyone'
			clearDraft(this.draftContext)
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
			const button = /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.emojiButton)
			button?.$el?.click()
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
			const input = this.inputElement()
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
			if (accounts.length === 0 || !this.statusIsEmpty || this.inputElement() === undefined) {
				return
			}

			this.inputElement().replaceChildren(...mentionPills(accounts, this.hostname))
			this.updateStatusContent()
		},

		updateStatusContent() {
			this.statusContent = this.inputElement().innerHTML
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
			return editableToPlainText(this.inputElement())
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
			}, this.draftContext)
		},

		/**
		 * Puts back whatever the last attempt or the last session left, unless
		 * something else has already filled the composer (a reply mention).
		 */
		restoreDraft() {
			const draft = loadDraft(this.draftContext)
			if (draft === null || this.inputElement() === undefined) {
				return false
			}

			if (draft.text !== '') {
				this.inputElement().innerText = draft.text
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

			if (this.statusIsEmpty && this.inputElement() !== undefined) {
				this.inputElement().innerText = htmlToPlainText(post.content || '')
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

			this.restoreMedia(post.media_attachments ?? [])

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
			/** @type {HTMLInputElement} */ (this.$refs.fileUploadInput).click()
		},

		/**
		 * Attaches a picture from the instance's shared library. The picker
		 * stays open — choosing two is a normal thing to want — and the
		 * ceiling closes it instead.
		 *
		 * @param {{slug: string, title: string}} gif the one that was chosen
		 */
		async attachGif(gif) {
			if (this.attachmentsFull) {
				this.announceCeiling()
				this.showGifs = false
				return
			}

			await this.attachLibraryGif(gif)

			if (this.attachmentsFull) {
				this.showGifs = false
			}
		},

		insert(emoji) {
			if (typeof emoji === 'object') {
				const category = Object.keys(emoji)[0]
				const emojis = emoji[category]
				const firstEmoji = Object.keys(emojis)[0]
				emoji = emojis[firstEmoji]
			}

			const lastChild = /** @type {HTMLElement|null} */ (this.inputElement().lastChild)
			const div = document.createElement('div')
			div.textContent = emoji + ' '

			if (lastChild === null) {
				this.inputElement().innerHTML = div.innerHTML
			} else {
				switch (lastChild.tagName) {
					case 'BR':
						lastChild.before(div.firstChild)
						break
					case 'DIV': {
						const inner = /** @type {HTMLElement} */ (lastChild.lastChild)
						switch (inner.tagName) {
							case 'BR':
								inner.before(div.firstChild)
								break
							default:
								lastChild.append(div.firstChild)
						}
						break
					}
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
		async createPost() {
			if (!this.canPost || this.loading) {
				return
			}

			// filters are baked in here, once, and the filtered copies take
			// the place of the uploads before their ids are read below
			this.loading = true
			if (!(await this.bakeFilters())) {
				this.loading = false
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

			const statusData = statusPayload({
				text: status,
				warning,
				// only uploads the server actually took: a failed one used to
				// be read as `preview.data.id` and threw a TypeError here
				mediaIds: this.mediaIds,
				inReplyToId: this.replyTo?.id,
				quoteId: this.quoteOf?.id,
				visibility: this.visibility,
				postAs: this.postAs,
				replyPolicy: this.visibility === 'direct' ? 'everyone' : this.replyPolicy,
				language: this.language,
				video: this.isVideoPost
					? { title: this.videoTitle, category: this.videoCategory, licence: this.videoLicence }
					: null,
				place: this.place,
				scheduledAt: this.scheduling ? this.scheduledAt : null,
				poll: this.showPoll
					? { options: this.pollOptions, expiresIn: this.pollExpiresIn, multiple: this.pollMultiple }
					: null,
			})

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

		/**
		 * Plays the games in a post where the writer can watch: each one
		 * tumbles through what it could land on for half a second and then
		 * lands on what it did. The result was decided before the tumble
		 * started; this is only the showing of it.
		 *
		 * @param {Array<{ kind: string, result: string }>} results what was played
		 * @return {Promise<void>}
		 */
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

		/**
		 * Presses or releases the pin. Releasing it drops the place as well:
		 * a pin that is not pressed says the post has no place, and it should
		 * mean it.
		 */
		togglePlace() {
			this.placing = !this.placing
			if (!this.placing) {
				this.place = null
			}
		},

		/**
		 * Presses or releases the clock. Pressing it fetches the picker and
		 * proposes an hour from now, rounded to the picker's step, so there is
		 * a time to move rather than a blank to fill.
		 */
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

		/**
		 * Writes the mark for a post made with AI into the words, or takes it
		 * out, the way a restored draft fills the box: the text is set and
		 * read back, so the draft on disk and the preview follow.
		 */
		toggleAiMark() {
			const text = this.plainText()
			this.inputElement().innerText = this.madeWithAi ? removeAiMark(text) : addAiMark(text)
			this.updateStatusContent()
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
	// 400ms, the same span REFUSAL_DURATION in useComposerAttachments keeps the class on for
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
}

.new-post__short {
	flex-shrink: 0;
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

.composer-bluesky-hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-align: end;
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
