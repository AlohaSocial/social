<!--
 - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div
		class="new-post"
		:class="{
			'new-post--collapsed': !expanded,
			'new-post--one-line': oneLine,
			'new-post--drop-target': draggingFiles,
			'new-post--refused': refusedDrop,
			'new-post--posted': justPosted,
		}"
		data-id=""
		tabindex="-1"
		@focusin="onFocusIn"
		@keydown.esc="onEscape"
		@dragenter="handleDragEnter"
		@dragover="handleDragOver"
		@dragleave="handleDragLeave"
		@drop="onComposerDrop">
		<div v-if="oneLine" class="composer-line">
			<NcAvatar
				:url="ownAvatarUrl"
				:displayName="currentUser.displayName"
				:hideStatus="true"
				:disableMenu="true"
				:disableTooltip="true"
				:size="32"
				aria-hidden="true" />
			<div
				ref="composerInput"
				class="message composer-line__input"
				:class="{ 'too-long': statusIsTooLong }"
				:aria-invalid="statusIsTooLong"
				:title="charactersLeftLabel"
				:contenteditable="!loading && !editSaving"
				role="textbox"
				aria-multiline="true"
				dir="auto"
				:aria-label="prompt"
				:placeholder="prompt"
				@input="updateStatusContent"
				@keyup.prevent.enter="keyup"
				@paste="onLinePaste"
				@tribute-replaced="updatePostFromTribute" />
			<NcButton
				class="composer-line__expand"
				variant="tertiary"
				:disabled="loading"
				:aria-label="t('social', 'Open full composer')"
				:title="t('social', 'Open full composer')"
				aria-haspopup="dialog"
				@click="openFullComposer">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
			</NcButton>
			<NcButton
				class="composer-line__send"
				variant="primary"
				:disabled="loading || (!oneLineNeedsDetails && !canPost)"
				:aria-busy="loading"
				:aria-label="oneLineNeedsDetails ? t('social', 'Continue draft') : t('social', 'Send')"
				:title="oneLineNeedsDetails ? t('social', 'Continue draft') : t('social', 'Send')"
				@click="sendFromLine">
				<template #icon>
					<Send :size="20" />
				</template>
			</NcButton>
		</div>
		<template v-else>
			<!-- for the eye only; pointer-events: none so the pill never takes the drop -->
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
			<div v-if="!compactOptions || closable" class="new-post-author">
				<!-- `disableMenu`: no profile card for yourself over your own box -->
				<NcAvatar
					v-if="!compactOptions"
					:url="ownAvatarUrl"
					:displayName="currentUser.displayName"
					:hideStatus="true"
					:disableMenu="true"
					:disableTooltip="true"
					:size="32" />
				<!-- Collapses the box at any time; what is in it stays, and comes
				     back from the draft after a reload. -->
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
				:class="{ 'new-post-form--with-media': hasAttachments }"
				@submit.prevent>
				<VideoMetadataEditor
					v-if="!editItem && isVideoPost"
					v-model:title="videoTitle"
					v-model:category="videoCategory"
					v-model:licence="videoLicence" />
				<ContentWarningEditor
					v-if="showWarning"
					v-model:value="spoilerText"
					:presets="warningPresets"
					@remove="toggleWarning"
					@choose="chooseWarning" />
				<div
					ref="composerInput"
					:contenteditable="!loading && !editSaving"
					class="message"
					role="textbox"
					aria-multiline="true"
					dir="auto"
					:aria-label="prompt"
					:aria-describedby="statusIsTooLong ? 'composer-length' : undefined"
					:placeholder="prompt"
					:class="{'icon-loading': loading, 'too-long': statusIsTooLong, 'message--caption': hasAttachments}"
					@keyup.prevent.enter="keyup"
					@input="updateStatusContent"
					@paste="handlePaste"
					@tribute-replaced="updatePostFromTribute" />

				<!-- under the box, as on Mastodon and Bluesky, so photos do not push
				     it off a phone's screen; the DOM order matches the tab order -->
				<PreviewGrid
					:uploading="uploading"
					:uploadProgress="uploadProgress"
					:progressLabel="progressLabel"
					:miniatures="attachments"
					:allowEffects="!editItem"
					@deleted="deletePreview"
					@describe="describeAttachment"
					@commitDescription="!editItem && commitDescription($event)"
					@focus="focusAttachment"
					@commitFocus="commitFocus"
					@filter="applyFilter" />

				<!-- /dice, /flip and /pick are played when the post goes out -->
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

				<!-- what the server will publish, on request -->
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
					:valid="pollIsValid"
					:attempted="pollAttempted"
					@remove="togglePoll" />

				<EventEditor
					v-if="showEvent"
					v-model:name="eventName"
					v-model:start="eventStart"
					v-model:end="eventEnd"
					v-model:location="eventLocation"
					:editing="Boolean(editItem)"
					:valid="eventIsValid"
					:attempted="eventAttempted"
					:reachesBluesky="reachesBluesky" />

				<!-- the date picker is large and loads only when the clock is pressed -->
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

				<!-- above the toolbar, not in it: the toolbar's collapse
				     (`max-height`, `overflow: hidden`) would clip the picker -->
				<PlacePicker
					v-if="placing"
					:place="place"
					@update:place="place = $event"
					@close="togglePlace" />

				<label v-if="editItem" class="composer-sensitive">
					<input v-model="editSensitive" type="checkbox">
					{{ t('social', 'Mark media as sensitive') }}
				</label>
				<div class="options" :class="{ 'options--compact': compactOptions }">
					<NcButton
						v-if="offerShort && (!compactOptions || moreOptionsOpen)"
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
					<NcButton
						v-if="compactOptions"
						variant="tertiary"
						class="more-options-toggle"
						:aria-expanded="moreOptionsOpen"
						@click.prevent="moreOptionsOpen = !moreOptionsOpen">
						{{ moreOptionsOpen ? t('social', 'Fewer options') : t('social', 'More options') }}
					</NcButton>
					<div v-if="!compactOptions || moreOptionsOpen" class="composer-advanced-options">
						<span v-if="compactOptions" class="composer-advanced-options__label">{{ t('social', 'Post options') }}</span>
						<!-- the AI mark is a hashtag in the text: the button reads
						     its state off the words, and writes or removes the tag -->
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
							<span v-if="compactOptions">{{ t('social', 'Made with AI') }}</span>
						</NcButton>
						<NcButton
							:title="showGifs ? t('social', 'Close the GIF library') : t('social', 'Add from the GIF library')"
							variant="tertiary"
							:aria-label="showGifs ? t('social', 'Close the GIF library') : t('social', 'Add from the GIF library')"
							:aria-pressed="showGifs"
							:disabled="attachmentsFull"
							@click.prevent="showGifs = !showGifs">
							<template #icon>
								<FileGifBox :size="22" decorative title="" />
							</template>
							<span v-if="compactOptions">{{ t('social', 'Add from the GIF library') }}</span>
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
							<span v-if="compactOptions">{{ showPreview ? t('social', 'Hide preview') : t('social', 'Preview this post') }}</span>
						</NcButton>
						<!-- a short post, drawn big on colour; offered only while it is short -->
						<NcButton
							v-if="!editItem && canBeCard"
							:title="asCard ? t('social', 'Post as plain text') : t('social', 'Post as a card')"
							variant="tertiary"
							class="card-toggle"
							:aria-label="asCard ? t('social', 'Post as plain text') : t('social', 'Post as a card')"
							:aria-pressed="asCard"
							@click.prevent="asCard = !asCard">
							<template #icon>
								<CardTextOutline :size="22" decorative title="" />
							</template>
							<span v-if="compactOptions">{{ asCard ? t('social', 'Post as plain text') : t('social', 'Post as a card') }}</span>
						</NcButton>
						<NcButton
							v-if="!editItem"
							:title="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
							variant="tertiary"
							:disabled="showEvent"
							:aria-label="showPoll ? t('social', 'Remove poll') : t('social', 'Add poll')"
							@click.prevent="togglePoll">
							<template #icon>
								<PollIcon :size="22" decorative title="" />
							</template>
							<span v-if="compactOptions">{{ showPoll ? t('social', 'Remove poll') : t('social', 'Add poll') }}</span>
						</NcButton>
						<NcButton
							v-if="!editItem"
							:title="showEvent ? t('social', 'Remove event') : t('social', 'Create an event')"
							:aria-label="showEvent ? t('social', 'Remove event') : t('social', 'Create an event')"
							variant="tertiary"
							:aria-pressed="showEvent"
							@click.prevent="toggleEvent">
							<template #icon>
								<CalendarMonthOutline :size="22" decorative title="" />
							</template>
							<span v-if="compactOptions">{{ showEvent ? t('social', 'Remove event') : t('social', 'Create an event') }}</span>
						</NcButton>
						<span v-if="compactOptions && !editItem" class="composer-advanced-options__label">{{ t('social', 'Timing and place') }}</span>
						<NcButton
							v-if="!editItem"
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
							<span v-if="compactOptions">{{ scheduling ? t('social', 'Post now instead') : t('social', 'Schedule for later') }}</span>
						</NcButton>

						<NcButton
							v-if="!editItem"
							:title="placing ? t('social', 'No place') : t('social', 'Say where this was taken')"
							variant="tertiary"
							class="place-toggle"
							:aria-label="placing ? t('social', 'No place') : t('social', 'Say where this was taken')"
							:aria-pressed="placing"
							@click.prevent="togglePlace">
							<template #icon>
								<MapMarkerOutline :size="22" decorative title="" />
							</template>
							<span v-if="compactOptions">{{ placing ? t('social', 'No place') : t('social', 'Say where this was taken') }}</span>
						</NcButton>

						<span v-if="compactOptions" class="composer-advanced-options__label">{{ t('social', 'Publishing settings') }}</span>
						<!-- who is speaking, only when the writer is in a team that has an account -->
						<select
							v-if="!editItem && teams.length"
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
						<div class="composer-publishing-controls">
							<LanguageSelect :language="language" @update:language="language = $event" />
							<ReplyPolicySelect
								v-if="!editItem && visibility !== 'direct'"
								:policy="replyPolicy"
								@update:policy="replyPolicy = $event" />
						</div>
					</div>
					<span v-if="undescribed > 0" class="composer-alt-warning" role="status">
						{{ undescribedWarning }}
					</span>
					<VisibilitySelect
						v-if="!editItem"
						:visibility="visibility"
						:reachesBluesky="reachesBluesky"
						@update:visibility="chooseVisibility" />
					<div class="emptySpace" />
					<span
						v-if="statusText.length > 0"
						id="composer-length"
						class="char-ring"
						:class="{ 'char-ring--warning': charsLeft <= 50, 'char-ring--over': statusIsTooLong }"
						:style="{ '--char-progress': charProgress }"
						:title="charactersLeftLabel">
						<span v-if="charsLeft <= 50" class="char-ring__count" aria-hidden="true">{{ charsLeft }}</span>
						<!-- the ring's state in words, announced once it is worth interrupting for -->
						<span class="hidden-visually" role="status">
							{{ charsLeft <= 50 ? charactersLeftLabel : '' }}
						</span>
					</span>
					<NcButton
						v-if="editItem"
						variant="primary"
						:disabled="!canPost || editSaving"
						:aria-busy="editSaving"
						@click="createPost">
						{{ editSaving ? t('social', 'Saving …') : t('social', 'Save') }}
					</NcButton>
					<NcButton v-if="editItem" @click="$emit('cancel')">
						{{ t('social', 'Cancel') }}
					</NcButton>
					<SubmitStatusButton
						v-else
						:visibility="visibility"
						:scheduled="scheduling"
						:disabled="!canPost || loading"
						@click="createPost" />
				</div>
				<!-- Social's own limit is the counter's; this is Bluesky's -->
				<p
					v-if="blueskyHint"
					class="composer-bluesky-hint"
					role="status"
					aria-live="polite">
					{{ t('social', 'A long post may be split into a thread on Bluesky to fit its post limits.') }}
				</p>
			</form>
			<!-- New short on the collapsed line; when open, the button is in the toolbar -->
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
		</template>
		<!-- Escape on a box with words in it: the words are kept either way;
		     this guards against folding the composer away by accident -->
		<NcDialog
			v-model:open="confirmingClose"
			:name="t('social', 'Close the composer?')"
			:buttons="closeButtons">
			<p class="new-post__close-hint">
				{{ t('social', 'What you have written is kept, and is back in the box the next time you open it.') }}
			</p>
		</NcDialog>
		<!-- the draft keeps everything but an upload still on its way, which
		     has no id yet -->
		<ConfirmDialog
			:open="confirmingLeave"
			:name="t('social', 'Leave while an attachment is uploading?')"
			:message="t('social', 'The attachment that is still uploading will not be kept. Everything else you wrote is kept as a draft.')"
			:confirmLabel="t('social', 'Leave')"
			@update:open="answerLeave(false)"
			@confirm="answerLeave(true)" />
	</div>
</template>

<script>

import EmoticonOutline from 'vue-material-design-icons/EmoticonOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import CameraOutline from 'vue-material-design-icons/CameraOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Send from 'vue-material-design-icons/Send.vue'
import Close from 'vue-material-design-icons/Close.vue'
import FolderImage from 'vue-material-design-icons/FolderImage.vue'
import FileGifBox from 'vue-material-design-icons/FileGifBox.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import PollIcon from 'vue-material-design-icons/Poll.vue'
import CardTextOutline from 'vue-material-design-icons/CardTextOutline.vue'
import CreationOutline from 'vue-material-design-icons/CreationOutline.vue'
import PlacePicker from './PlacePicker.vue'
import SchedulePicker from './SchedulePicker.vue'
import { computed, defineAsyncComponent, getCurrentInstance, ref } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '../../services/toast.js'
import FocusOnCreate from '../../directives/focusOnCreate.js'
import axios from '@nextcloud/axios'
import ActorAvatar from '../ActorAvatar.vue'
import ConfirmDialog from '../admin/ConfirmDialog.vue'
import { generateUrl } from '@nextcloud/router'
import { ownAvatarUrl } from '../../services/avatar.js'
import PollEditor from './PollEditor.vue'
import EventEditor from './EventEditor.vue'
import ContentWarningEditor from './ContentWarningEditor.vue'
import VideoMetadataEditor from './VideoMetadataEditor.vue'
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
import { mayHave } from '../../services/startupHints.js'
import { feel } from '../../services/senses.js'
import { commandsIn, resolveCommands, tumble } from '../../utils/composerCommands.js'
import { accountHue } from '../../services/accountColour.js'
import { cardGradients, findGradient, gradientCss, renderTextCard } from '../../utils/textCard.js'
import { clearDraft, loadDraft, saveDraft } from '../../services/draft.js'
import { addAiMark, hasAiMark, removeAiMark } from '../../services/aiContent.js'
import { mapStores } from 'pinia'
import { useInstanceStore } from '../../store/instance.js'
import { useAccountStore } from '../../store/account.js'
import { isRanked, useTimelineStore } from '../../store/timeline.js'
import { editableToPlainText, htmlToPlainText } from '../../utils/plainText.js'
import { mentionPills, participantsOf } from '../../utils/replyMentions.js'
import { countableLength } from '../../utils/linkify.js'
import { idempotencyKeeper } from '../../utils/idempotency.js'
import { pollIsComplete, statusPayload } from '../../utils/statusPayload.js'
import { defaultLanguage, isLanguageCode, rememberedLanguage } from '../../utils/postLanguage.js'
import { fullDateTime } from '../../utils/relativeTime.js'
import { datePickerModule, isTooSoon, proposedSchedule } from '../../utils/schedule.js'
import { useComposerAttachments } from '../../composables/useComposerAttachments.js'
import { useCurrentUser } from '../../composables/useCurrentUser.js'
import { useServerData } from '../../composables/useServerData.js'
import { userKey } from '../../utils/browserStore.js'

/** Reserve room for the full-post link and details that Social adds on publish. */
const BLUESKY_WARNING_GRAPHEMES = 160
const BLUESKY_WARNING_BYTES = 2800

/**
 * How many characters a person sees, as Bluesky counts: a flag or a family
 * emoji is one.
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
 * The content warnings offered as one press, written the same way every time
 * so they can be filtered on. The box still takes anything.
 */
function contentWarningPresets() {
	return [
		t('social', 'Spoiler'),
		t('social', 'Food'),
		t('social', 'Politics'),
		t('social', 'Mental health'),
		t('social', 'Eye contact'),
		t('social', 'Work'),
	]
}

/**
 * The shared GIF library, loaded on first use: it pulls in `NcTextField` and
 * with it half a megabyte of `@nextcloud/vue` l10n.
 */
let gifPicker = null
const gifPickerModule = () => (gifPicker ??= import('./GifPicker.vue'))

/** the longest a post may be and still be offered as a card */
export const CARD_MAX = 120

export default {
	name: 'Composer',
	components: {
		OpenInNew,
		Send,
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
		NcDialog,
		NcLoadingIcon,
		ActorAvatar,
		ConfirmDialog,
		Paperclip,
		EmoticonOutline,
		ClockOutline,
		CalendarMonthOutline,
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
		EventEditor,
		ContentWarningEditor,
		VideoMetadataEditor,
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
		oneLine: { type: Boolean, default: false },
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

		/** Opened already, e.g. in the New post dialog. */
		startExpanded: {
			type: Boolean,
			default: false,
		},

		/** Whether secondary post controls stay grouped behind a disclosure. */
		compactOptions: {
			type: Boolean,
			default: false,
		},

		/**
		 * The post this composer replies to by default, for a composer under a
		 * post on its own page. Replying to another post retargets it; sending
		 * or closing that reply comes back here.
		 */
		inReplyTo: {
			type: /** @type {import('vue').PropType<object|null>} */ (Object),
			default: null,
		},

		/**
		 * Paths in the reader's folder to attach on mount, from "Share to
		 * Social" in Files; attached like files from the picker.
		 */
		initialPaths: {
			type: /** @type {import('vue').PropType<string[]>} */ (Array),
			default: () => [],
		},

		/**
		 * Files to attach on mount, from a picker opened before the composer
		 * ("New video" on the Videos page); attached like a drop.
		 */
		initialFiles: {
			type: /** @type {import('vue').PropType<File[]>} */ (Array),
			default: () => [],
		},

		/** The post being edited, available before the lazy dialog editor mounts. */
		editItem: { type: Object, default: null },
		editSaving: { type: Boolean, default: false },
		editMaxLength: { type: Number, default: null },
		initialQuote: { type: Object, default: null },
		initialDraft: { type: Object, default: null },
		initialDraftContext: { type: String, default: '' },
		showReplyContext: { type: Boolean, default: false },
		listenForIntents: { type: Boolean, default: true },

		/**
		 * Where floating-vue teleports the emoji picker. Inline, `#content`, so
		 * the toolbar's `overflow: hidden` does not clip it. In a modal, the
		 * overlay wrapper: outside the mask the picker sits behind it and the
		 * focus trap, and `.modal-composer` scrolls and would clip it.
		 */
		emojiPickerContainer: {
			type: String,
			default: '#content',
		},
	},

	emits: ['posted', 'save', 'cancel'],
	setup(props) {
		const { hostname, serverData } = useServerData()
		const { currentUser, username } = useCurrentUser()
		// a self-registered external user has no Files to attach from
		const hasFiles = computed(() => !serverData.value?.externalMedia)

		// what a click into the box opens; anything in it also keeps it open,
		// see expanded()
		const openedByHand = ref(props.startExpanded)
		// what the close button shuts: a box with words in it is otherwise
		// held open by the words
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
			username,
			openedByHand,
			closedByHand,
			expand,
			...useComposerAttachments({ expand, root: () => instance?.proxy?.$el }),
			// set in mounted(), not in data(), so the library's object is not
			// wrapped in a reactive proxy
			tribute: null,
			tributeTarget: null,
			idempotency: idempotencyKeeper(),
		}
	},

	data() {
		return {
			/** whether the emoji picker has been asked for, and so downloaded */
			emojiPickerLoaded: false,
			/** whether secondary controls are visible in the post dialog */
			moreOptionsOpen: false,
			/** whether that download is in flight, so a second press is ignored */
			emojiPickerLoading: false,
			originalEditSignature: '',
			editSensitive: this.editItem?.sensitive === true,
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
			// whoever opened the composer, then the post being answered, the
			// account default, the last one used, and otherwise the narrow choice
			visibility: this.defaultVisibility
				|| this.inReplyTo?.visibility
				|| useAccountStore().defaultPostVisibility
				|| rememberedVisibility()
				|| 'followers',

			/** Whether somebody chose the audience, so a late account default leaves it alone. */
			visibilityChosen: Boolean(this.defaultVisibility || this.inReplyTo?.visibility),

			// the last post's language, else Nextcloud's, so the poster can see
			// and correct it
			language: rememberedLanguage() || defaultLanguage(),
			/**
			 * The team accounts this account may post as, and which of them
			 * this post is being written as ('' for themselves). Empty, and the
			 * control absent, on instances without team accounts.
			 */
			teams: [],
			postAs: '',
			/**
			 * The team a restored draft was being written as, until the teams
			 * have been asked for and it can be checked against them.
			 */
			pendingPostAs: '',
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
			showEvent: false,
			eventName: '',
			eventStart: '',
			eventEnd: '',
			eventLocation: '',
			showWarning: false,
			/** what a video is called, and what it is; blank unless asked for */
			videoTitle: '',
			videoCategory: '',
			videoLicence: '',
			/** whether the "how this will read" pane is open */
			showPreview: false,
			/** whether Escape is being asked about, the box holding words */
			confirmingClose: false,
			/** whether leaving the page is being asked about, an upload being on its way */
			confirmingLeave: false,
			/** what answers the navigation waiting on that question */
			resolveLeave: null,
			/** whether the shared picture library is open */
			showGifs: false,
			spoilerText: '',
			pollOptions: ['', ''],
			pollMultiple: false,
			pollExpiresIn: 86400,
			search: '',
			replyTo: this.inReplyTo,
			/** the post this one quotes, as the timeline handed it over */
			quoteOf: this.initialQuote,
			tributeOptions: mentionTributeOptions(),

			// the eventBus and document handlers mounted() adds, kept so that
			// unmounted() removes only these and not other components' listeners
			onComposerReply: null,
			onComposerQuote: null,
			onComposerFocus: null,
			onComposerPublished: null,
			handedOffDraft: null,
			/** whether the draft handed to the full composer reached browser storage */
			handedOffStored: false,
			/** the mentions this box put in by itself, which alone are no draft */
			routingText: '',
			/** the draft context whose saved audience was restored, '' for none */
			resumedAudienceFor: '',
			/** whether sending was tried while the event was incomplete */
			eventAttempted: false,
			/** whether sending was tried with the poll incomplete */
			pollAttempted: false,
			onOutsideInteraction: null,
			onBeforeUnload: null,
			stopLeaveGuard: null,
		}
	},

	computed: {
		editPayload() {
			return {
				content: this.statusText.trim(),
				spoiler_text: this.showWarning ? this.spoilerText.trim() : '',
				sensitive: this.editSensitive,
				language: this.language,
				media_ids: this.mediaIds,
				media_attributes: Object.values(this.attachments).filter(({ data }) => data?.id).map(({ data, description }) => ({ id: data.id, description: description || '' })),
				...(this.editItem?.event
					? { event: {
							name: this.eventName.trim(),
							start_time: this.editTime(this.eventStart),
							end_time: this.editTime(this.eventEnd),
							location: this.eventLocation.trim(),
							...(this.editItem.event.status ? { status: this.editItem.event.status } : {}),
						} }
					: {}),
			}
		},

		isEditDirty() {
			return JSON.stringify(this.editPayload) !== this.originalEditSignature
		},

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
		 * The reader's own avatar by URL rather than `user`: with a `user`,
		 * `NcAvatar` caches one failed load in browser storage and then shows
		 * initials for good, and it builds a malformed URL for your own
		 * account (a second `?`).
		 *
		 * @return {string} the avatar of the account writing this post
		 */
		ownAvatarUrl() {
			return ownAvatarUrl(64)
		},

		/** @return {number} what the server accepts in one status */
		maxLength() {
			return this.editMaxLength ?? this.instanceStore.maxCharacters
		},

		/**
		 * What the box asks for: with photos, a caption; otherwise a prompt by
		 * first name.
		 *
		 * @return {string}
		 */
		prompt() {
			if (this.editItem) {
				return t('social', 'Edit your post')
			}
			if (this.hasAttachments) {
				return t('social', 'Write a caption…')
			}
			if (this.replyTo !== null) {
				return t('social', 'Write a reply…')
			}
			if (this.quoteOf !== null || this.firstName === '') {
				return t('social', 'What would you like to share?')
			}

			return t('social', 'Aloha, {name}. What’s new?', { name: this.firstName })
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
		 * one, or a post of its own, each kept separately.
		 *
		 * @return {string} '' for a post of its own
		 */
		draftContext() {
			// a re-draft from Drafts keeps its own context when its reply or
			// quote target is restored
			if (this.initialDraftContext.startsWith('redraft.')) {
				return this.initialDraftContext
			}
			if (this.replyTo !== null) {
				return `reply.${this.replyTo.id}`
			}
			if (this.quoteOf !== null) {
				return `quote.${this.quoteOf.id}`
			}

			return this.initialDraftContext
		},

		/**
		 * Whether the reply target is the post this composer is anchored
		 * under; the reply header would then repeat what is right above.
		 *
		 * @return {boolean}
		 */
		anchoredReply() {
			return !this.showReplyContext && this.inReplyTo !== null && this.replyTo?.id === this.inReplyTo.id
		},

		charactersLeftLabel() {
			return this.statusIsTooLong
				? n('social', '%n character too many', '%n characters too many', -this.charsLeft)
				: n('social', '%n character left', '%n characters left', this.charsLeft)
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

		oneLineNeedsDetails() {
			return this.hasAttachments || this.showPoll || this.showEvent || this.showWarning
				|| this.scheduling || this.placing || this.asCard || this.quoteOf !== null
				|| this.postAs !== '' || this.pendingPostAs !== '' || this.checkingMedia
		},

		canPost() {
			if (this.editSaving) {
				return false
			}
			// wait for uploads that have not answered yet
			if (this.hasPendingUploads) {
				return false
			}

			// a restored attachment may be gone from the server, and a photo
			// still being fetched would go out without its filter
			if (this.checkingMedia) {
				return false
			}

			if (this.scheduleTooSoon) {
				return false
			}

			if (this.statusIsTooLong) {
				return false
			}
			if (!this.eventIsValid) {
				return false
			}
			if (!this.pollIsValid) {
				return false
			}

			if (this.statusIsEmpty) {
				return false
			}

			if (!this.editItem && this.visibility === 'direct' && !this.hasMentions) {
				return false
			}

			return true
		},

		statusIsEmpty() {
			return this.statusText.trim().length === 0 && this.mediaIds.length === 0 && !this.showEvent
		},

		pollIsValid() {
			return !this.showPoll || pollIsComplete(this.pollOptions)
		},

		eventIsValid() {
			if (!this.showEvent) {
				return true
			}
			if (this.eventName.trim() === '' || this.eventStart === '') {
				return false
			}
			const start = new Date(this.eventStart).getTime()
			const end = this.eventEnd === '' ? null : new Date(this.eventEnd).getTime()
			return Number.isFinite(start) && (end === null || (Number.isFinite(end) && end > start))
		},

		/**
		 * Collapsed until clicked; stays open while it holds anything that
		 * closing would hide.
		 *
		 * @return {boolean}
		 */
		expanded() {
			if (this.oneLine) {
				return false
			}
			if (this.closedByHand) {
				// what was written stays in the box and the draft, just hidden
				return false
			}

			return this.openedByHand
				|| this.loading
				// the anchor post is not a reply in progress, or every post's
				// box would be open
				|| (this.replyTo !== null && !this.anchoredReply)
				|| this.quoteOf !== null
				|| this.showPoll
				|| this.showEvent
				|| this.scheduling
				|| this.showWarning
				|| !this.statusIsEmpty
				|| Object.keys(this.attachments).length > 0
		},

		/**
		 * Whether there is anything to close it to: not in the New post
		 * dialog, which has its own way out, nor while already collapsed.
		 *
		 * @return {boolean}
		 */
		closable() {
			return this.expanded && !this.startExpanded
		},

		/** @return {import('../../types/Nextcloud.js').DialogButton[]} */
		closeButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.confirmingClose = false
						this.$nextTick(() => this.focusInput({ atEnd: true }))
					},
				},
				{
					label: t('social', 'Close'),
					variant: 'primary',
					callback: () => {
						this.confirmingClose = false
						this.closeAndLeave()
					},
				},
			]
		},

		/**
		 * Whether the post is a few plain words, short enough for a card: no
		 * photos, no poll.
		 *
		 * @return {boolean}
		 */
		canBeCard() {
			const length = this.statusText.trim().length

			return length > 0 && length <= CARD_MAX && !this.hasAttachments && !this.showPoll && !this.showEvent
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

		/** Measured on the text sent, not the markup: a mention pill alone is ~200 characters of HTML. */
		statusIsTooLong() {
			return this.statusLength > this.maxLength
		},

		/**
		 * The length the server holds against the allowance: the text counted
		 * as `LinkifyService::countableLength()` counts it, and the content
		 * warning with it.
		 *
		 * @return {number}
		 */
		statusLength() {
			const warning = this.showWarning ? this.spoilerText.trim() : ''
			return countableLength(this.statusText) + [...warning].length
		},

		/** @return {number} how much of the allowance is spent, 0..1 */
		charProgress() {
			return Math.min(this.statusLength / this.maxLength, 1)
		},

		/** @return {number} how many characters remain, negative once over */
		charsLeft() {
			return this.maxLength - this.statusLength
		},

		hasMentions() {
			return /(?:^|\s)@[a-zA-Z0-9_.-]+/i.test(this.statusText)
		},

		/**
		 * Whether a public post from this account goes to Bluesky: the server
		 * offers it and the account's Bluesky identity is in use.
		 *
		 * @return {boolean}
		 */
		reachesBluesky() {
			const identity = this.accountStore.currentAccount?.bluesky
			return this.serverData?.bluesky?.enabled === true
				&& identity !== null && identity !== undefined && identity.active !== false
		},

		/**
		 * Everything in the box, as the draft on disk keeps it.
		 *
		 * @return {Omit<import('../../services/draft.js').ComposerDraft, 'savedAt'>}
		 */
		draft() {
			const scheduled = this.scheduling && this.scheduledAt instanceof Date && !Number.isNaN(this.scheduledAt.getTime())
			const video = { title: this.videoTitle, category: this.videoCategory, licence: this.videoLicence }
			const poll = { options: [...this.pollOptions], multiple: this.pollMultiple, expiresIn: this.pollExpiresIn }
			const event = { name: this.eventName, start: this.eventStart, end: this.eventEnd, location: this.eventLocation }

			return {
				text: this.statusText,
				spoilerText: this.showWarning ? this.spoilerText : '',
				visibility: this.visibility,
				// so a reload does not turn a team post back into a personal one
				postAs: this.postAs || this.pendingPostAs,
				media: this.draftMedia,
				poll: this.showPoll ? poll : null,
				event: this.showEvent ? event : null,
				scheduledAt: scheduled ? this.scheduledAt.toISOString() : '',
				language: this.language,
				replyPolicy: this.replyPolicy,
				place: this.placing ? this.place : null,
				video: Object.values(video).some((value) => value !== '') ? video : null,
				card: this.asCard ? this.cardGradientId : '',
				// a re-draft's context does not say what it answers
				inReplyToId: this.draftContext.startsWith('redraft.') ? String(this.replyTo?.id ?? '') : '',
				quoteId: this.draftContext.startsWith('redraft.') ? String(this.quoteOf?.id ?? '') : '',
			}
		},

		/**
		 * Whether leaving now would lose something the draft cannot keep: an
		 * upload that has not come back has no id to keep it by.
		 *
		 * @return {boolean}
		 */
		wouldLoseOnLeave() {
			return this.hasPendingUploads || (this.editItem !== null && this.isEditDirty)
		},

		/**
		 * Whether to say that Bluesky will cut this post: only a public post
		 * goes there, and only when it is longer than what Bluesky shows.
		 *
		 * @return {boolean}
		 */
		blueskyHint() {
			const text = this.statusText + (this.showWarning ? this.spoilerText : '')
				+ (this.showPoll ? this.pollOptions.join(' ') : '')
			const byteLength = new TextEncoder().encode(text).length

			return this.reachesBluesky
				&& this.visibility === 'public'
				&& (graphemes(text) > BLUESKY_WARNING_GRAPHEMES || byteLength > BLUESKY_WARNING_BYTES)
		},
	},

	watch: {
		loading(loading) {
			this.timelineStore.composerSubmitting = loading
		},

		/**
		 * The router reuses this view for another post's page, so the reply
		 * target follows it.
		 *
		 * @param {object|null} post the post to reply to now
		 */
		inReplyTo(post) {
			this.replyTo = post
		},

		// everything but the words, which updateStatusContent saves as typed
		draft: 'rememberDraft',

		/**
		 * The words stay in the box when it is retargeted, so the stored
		 * draft moves with them instead of coming back on the next visit.
		 *
		 * @param {string} _now the context being written now
		 * @param {string} before the one just left
		 */
		draftContext(_now, before) {
			if (this.editItem) {
				return
			}
			clearDraft(before)
			this.rememberDraft()
		},

		/**
		 * The account's default audience arrives with `verify_credentials`,
		 * possibly after the composer is up. It applies only if nobody chose
		 * an audience and this is not a reply.
		 *
		 * @param {string} visibility the account's default, '' until it comes
		 */
		'accountStore.defaultPostVisibility': function(visibility) {
			if (visibility !== '' && !this.visibilityChosen) {
				this.visibility = visibility
			}
		},

		/**
		 * The full composer this box handed its draft to closed without
		 * publishing; its words are newer than this box's, so they are
		 * reloaded before anything here overwrites them.
		 *
		 * @param {boolean} open whether the full composer is open
		 */
		'timelineStore.composerDisplayStatus': function(open) {
			if (!open && this.oneLine && this.handedOffDraft !== null) {
				this.reloadDraft()
			}
		},
	},

	mounted() {
		// the counter and the attachment ceiling are the server's numbers
		this.instanceStore.load()
		this.loadTeams()

		// tributejs attaches to the contenteditable and appends its menu to
		// the body, styled by the unscoped .tribute-container rule below
		this.tribute = new Tribute(this.tributeOptions)
		// kept: $refs is cleared before unmounted(), and detach() needs a node
		this.tributeTarget = this.inputElement()
		this.tribute.attach(this.tributeTarget)

		// kept so unmounted() removes only this handler
		this.onComposerReply = (data) => {
			if (this.oneLine) {
				if (this.draftContext === `reply.${data.id}`) {
					this.handedOffDraft = JSON.stringify(this.draft)
				}
				return
			}
			this.replyTo = data
			// mention everyone in the conversation, not only the author
			this.prefillMessageWithMentions(participantsOf(data, this.username, this.hostname))
			// a resumed draft of this reply keeps the audience it was given
			if (this.resumedAudienceFor !== `reply.${data.id}`) {
				this.visibility = data.visibility
				this.visibilityChosen = true
			}
			// opens the box even when the target is unchanged (the anchor
			// post); through expand(), so it also undoes a close by hand
			this.expand()
			// caret after the mentions, so typing goes into the box
			this.$nextTick(() => this.focusInput({ atEnd: true }))
		}
		if (this.listenForIntents || this.oneLine) {
			eventBus.on('composer-reply', this.onComposerReply)
		}

		// a quote adds no mention and does not take the quoted post's visibility
		this.onComposerQuote = (data) => {
			this.quoteOf = data
		}
		if (this.listenForIntents) {
			eventBus.on('composer-quote', this.onComposerQuote)
		}

		// the shortcuts help offers "n" to write a post; this is what answers it
		this.onComposerFocus = () => this.focusInput()
		if (this.listenForIntents) {
			eventBus.on('shortcut:compose', this.onComposerFocus)
		}

		this.onComposerPublished = ({ context }) => {
			if (this.oneLine && context === this.draftContext && this.handedOffDraft !== null) {
				if (this.handedOffDraft === JSON.stringify(this.draft)) {
					this.clearComposer()
				} else {
					// The modal's reset watcher clears the shared key in this tick.
					this.$nextTick(() => this.rememberDraft())
				}
				this.handedOffDraft = null
			}
		}
		eventBus.on('composer-published', this.onComposerPublished)

		// before the mention prefill, which does not overwrite a non-empty box
		if (this.editItem) {
			this.restoreEdit()
		} else {
			this.restoreDraft()
		}
		if (this.inReplyTo !== null && !this.listenForIntents) {
			if (this.oneLine) {
				this.prefillMessageWithMentions(participantsOf(this.inReplyTo, this.username, this.hostname))
			} else {
				this.onComposerReply(this.inReplyTo)
			}
		}

		if (this.initialMention !== null) {
			this.prefillMessageWithMention(this.initialMention)
		}

		// "Share to Social" from Files: open and start attaching
		const paths = this.initialPaths.filter((path) => typeof path === 'string' && path !== '' && path !== '/')
		if (paths.length > 0) {
			this.expand()
			this.attachPaths(paths)
		}
		if (this.initialFiles.length > 0) {
			this.expand()
			this.attachFiles(this.initialFiles)
		}

		// focusout alone cannot do this: the emoji picker is rendered outside
		// this element, so using it looks like leaving
		this.onOutsideInteraction = (event) => this.collapseIfIdle(event)
		document.addEventListener('pointerdown', this.onOutsideInteraction)
		document.addEventListener('focusin', this.onOutsideInteraction)

		// the draft keeps everything else; only a pending upload is asked about
		this.onBeforeUnload = (event) => {
			if (this.wouldLoseOnLeave || this.loading) {
				event.preventDefault()
				event.returnValue = ''
			}
		}
		window.addEventListener('beforeunload', this.onBeforeUnload)
		if (this.listenForIntents && typeof this.$router?.beforeEach === 'function') {
			this.stopLeaveGuard = this.$router.beforeEach((to, from) => this.confirmLeave(to, from))
		}
	},

	unmounted() {
		document.removeEventListener('pointerdown', this.onOutsideInteraction)
		document.removeEventListener('focusin', this.onOutsideInteraction)
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		this.stopLeaveGuard?.()
		// release a navigation still waiting on the question
		this.resolveLeave?.(true)
		if (this.tribute && this.tributeTarget) {
			this.tribute.detach(this.tributeTarget)
		}
		eventBus.off('composer-reply', this.onComposerReply)
		eventBus.off('composer-quote', this.onComposerQuote)
		eventBus.off('shortcut:compose', this.onComposerFocus)
		eventBus.off('composer-published', this.onComposerPublished)
	},

	methods: {
		editTime(value) {
			const date = new Date(value)
			return value && Number.isFinite(date.getTime()) ? date.toISOString() : null
		},

		restoreEdit() {
			const post = this.editItem
			this.inputElement().innerText = htmlToPlainText(post.content || '')
			this.updateStatusContent()
			this.spoilerText = post.spoiler_text || ''
			this.showWarning = this.spoilerText !== ''
			this.visibility = post.visibility || 'public'
			this.visibilityChosen = true
			this.language = post.language || defaultLanguage()
			this.restoreMedia(post.media_attachments || [])
			if (post.event) {
				const local = (value) => {
					const date = new Date(value)
					return value && Number.isFinite(date.getTime())
						? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
						: ''
				}
				this.showEvent = true
				this.eventName = post.event.name || ''
				this.eventStart = local(post.event.start_time)
				this.eventEnd = local(post.event.end_time)
				this.eventLocation = post.event.location || ''
			}
			this.originalEditSignature = JSON.stringify(this.editPayload)
			this.focusInput()
		},

		onLinePaste(event) {
			const files = Array.from(event.clipboardData?.files ?? [])
			if (files.length > 0) {
				event.preventDefault()
				this.openFullComposer(files)
			} else {
				this.handlePaste(event)
			}
		},

		onComposerDrop(event) {
			if (this.editSaving) {
				event.preventDefault()
				return
			}
			if (this.oneLine && event.dataTransfer?.files?.length > 0) {
				event.preventDefault()
				this.draggingFiles = false
				this.openFullComposer(Array.from(event.dataTransfer.files))
			} else {
				this.handleDrop(event)
			}
		},

		async sendFromLine() {
			await this.createPost()
		},

		openFullComposer(files = []) {
			if (this.loading) {
				return
			}
			this.handedOffStored = this.rememberDraft()
			this.handedOffDraft = JSON.stringify(this.draft)
			eventBus.emit('composer-open', { reply: this.inReplyTo, files: Array.isArray(files) ? files : [], draft: this.draft })
		},

		/** Asks the Shorts bar for New short, on 24 hours. */
		composeShort() {
			eventBus.emit(SHORT_COMPOSE)
		},

		/** @return {HTMLElement} the element the post is written in */
		inputElement() {
			return /** @type {HTMLElement} */ (this.$refs.composerInput)
		},

		/**
		 * Empties everything the reader put in: text, attachments, poll,
		 * warning, schedule, place and the stored draft. Used both after
		 * sending and on closing, so the two cannot disagree.
		 */
		clearComposer() {
			if (this.inputElement() !== undefined) {
				this.inputElement().innerText = ''
			}
			this.clearAttachments()
			this.showPoll = false
			this.showEvent = false
			this.eventName = ''
			this.eventStart = ''
			this.eventEnd = ''
			this.eventLocation = ''
			this.pollOptions = ['', '']
			this.pollMultiple = false
			this.showWarning = false
			this.spoilerText = ''
			this.videoTitle = ''
			this.videoCategory = ''
			this.videoLicence = ''
			this.scheduling = false
			this.scheduledAt = null
			this.placing = false
			this.place = null
			this.asCard = false
			this.replyPolicy = 'everyone'
			this.moreOptionsOpen = false
			this.eventAttempted = false
			this.pollAttempted = false
			clearDraft(this.draftContext)
			this.updateStatusContent()
		},

		/**
		 * A click or the caret in the box opens it; not the box's own edge,
		 * where focus is parked after a post.
		 *
		 * @param {FocusEvent} event where the focus landed
		 */
		onFocusIn(event) {
			if (event.target !== this.$el) {
				this.expand()
			}
		},

		/**
		 * Escape folds an empty box away and asks about one with words in it.
		 * Not in the New post dialog, which closes on Escape itself, and not
		 * while the mention list or a picker is taking the key.
		 *
		 * @param {KeyboardEvent} event the key press
		 */
		onEscape(event) {
			if (this.startExpanded || !this.expanded || event.defaultPrevented || this.tribute?.isActive) {
				return
			}
			event.stopPropagation()

			if (this.statusIsEmpty && Object.keys(this.attachments).length === 0) {
				this.closeAndLeave()
				return
			}
			// blur first: the dialog's focus trap would give focus back to the
			// box and reopen it after Close; Cancel restores the caret itself
			this.inputElement()?.blur()
			this.confirmingClose = true
		},

		/** Closes the box and parks the focus on its edge rather than on nothing. */
		closeAndLeave() {
			this.close()
			this.$el.focus?.()
		},

		/** The close button: collapses the box, keeping the words in it and in the draft. */
		close() {
			this.closedByHand = true
			this.openedByHand = false

			if (this.inReplyTo === null && this.statusIsEmpty) {
				// the sidebar's box is shown by the store: an empty one closes
				// all the way, a written one stays reachable
				this.timelineStore.setComposerDisplayStatus(false)
			}
		},

		/**
		 * Fills the warning box from a preset, or empties it when the chosen
		 * one is pressed again.
		 *
		 * @param {string} preset the warning that was pressed
		 */
		chooseWarning(preset) {
			this.spoilerText = this.spoilerText === preset ? '' : preset
		},

		/**
		 * Fetches the emoji picker and passes it the click that asked for it.
		 * Awaited, not `defineAsyncComponent`, so the picker is mounted before
		 * the click reaches it.
		 */
		async loadEmojiPicker() {
			if (this.emojiPickerLoading) {
				return
			}

			// the button stays until the picker is there, so no gap shows
			// while the chunk loads
			this.emojiPickerLoading = true
			try {
				await emojiPickerModule()
				this.emojiPickerLoaded = true
			} catch (error) {
				// the import service clears a rejected promise, so another press retries
				logger.error('Could not load the emoji picker', { error })
				return
			} finally {
				this.emojiPickerLoading = false
			}

			// pass the click on a frame after the tick: the popover binds its
			// trigger on mount
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

			// the emoji picker, the menus and the date picker are teleported out
			// of this element; using them is not leaving the composer
			if (target instanceof Element && target.closest('.v-popper__popper, .modal-mask, .dp__menu') !== null) {
				return
			}

			this.openedByHand = false
		},

		/**
		 * Puts the caret in the composer, scrolling it into view if need be.
		 *
		 * @param {{atEnd?: boolean}} [options] `atEnd` puts the caret after
		 *                                      what the box already holds
		 */
		focusInput({ atEnd = false } = {}) {
			const input = this.inputElement()
			if (input === undefined) {
				return
			}

			input.focus()
			const selection = window.getSelection()
			if (atEnd && selection !== null && document.activeElement === input) {
				const range = document.createRange()
				range.selectNodeContents(input)
				range.collapse(false)
				selection.removeAllRanges()
				selection.addRange(range)
			}
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
			const input = this.inputElement()
			if (accounts.length === 0 || input === undefined) {
				return
			}
			const mentions = mentionPills(accounts, this.hostname)
			const routingText = mentions.map((node) => node.textContent ?? '').join('').trim()
			this.routingText = routingText
			if (!this.statusIsEmpty && this.statusText !== routingText) {
				return
			}

			input.replaceChildren(...mentions)
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
		 * The team accounts this account may post as, asked fresh because
		 * membership can change.
		 *
		 * @return {Promise<void>}
		 */
		async loadTeams() {
			if (!mayHave('has_teams')) {
				this.pendingPostAs = ''
				return
			}

			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1.1/teams'))
				this.teams = data.teams ?? []
				if (this.postAs === '' && this.teams.some((team) => team.acct === this.pendingPostAs)) {
					this.postAs = this.pendingPostAs
				}
			} catch (error) {
				// an instance with none answers this too; the control just stays absent
				logger.debug('could not load the team accounts', { error })
			} finally {
				this.pendingPostAs = ''
			}
		},

		/**
		 * Keeps what is in the box, so a failed post or a reload cannot eat it.
		 *
		 * @return {boolean} whether browser storage took it
		 */
		rememberDraft() {
			if (this.editItem) {
				return false
			}
			// hidden behind the full composer it handed its draft to, this box
			// holds the older copy of that draft
			if (this.oneLine && this.handedOffDraft !== null && this.timelineStore.composerDisplayStatus) {
				return false
			}
			return saveDraft(this.draft, this.draftContext, this.routingText)
		},

		/**
		 * Shows the saved draft again after the full composer it was handed to
		 * closed, in place of the copy handed over.
		 */
		reloadDraft() {
			const stored = this.handedOffStored
			this.handedOffDraft = null
			const draft = loadDraft(this.draftContext)
			// without browser storage, the words handed over are the only copy
			if (draft === null && !stored) {
				return
			}
			this.clearComposer()
			if (draft !== null) {
				this.restoreDraft(draft)
			} else if (this.inReplyTo !== null) {
				this.prefillMessageWithMentions(participantsOf(this.inReplyTo, this.username, this.hostname))
			}
		},

		/**
		 * Puts back whatever the last attempt or the last session left, unless
		 * something else has already filled the composer (a reply mention).
		 *
		 * @param {import('../../services/draft.js').ComposerDraft|null} [draft] the draft, the
		 *   one handed over or kept for this context when not given
		 * @return {boolean} whether there was one
		 */
		restoreDraft(draft = this.initialDraft ?? loadDraft(this.draftContext)) {
			if (draft === null || this.inputElement() === undefined) {
				return false
			}
			this.moreOptionsOpen = draft.poll !== null
				|| draft.event !== null
				|| draft.scheduledAt !== ''
				|| draft.place !== null
				|| draft.card !== ''
				|| draft.postAs !== ''
				|| (draft.replyPolicy !== '' && draft.replyPolicy !== 'everyone')
				|| (isLanguageCode(draft.language) && draft.language !== defaultLanguage())
				|| hasAiMark(draft.text)

			if (draft.text !== '') {
				this.inputElement().innerText = draft.text
			}
			if (draft.spoilerText !== '') {
				this.showWarning = true
				this.spoilerText = draft.spoilerText
			}
			// a draft from another version may name an unknown visibility,
			// which VisibilitySelect cannot render
			if (isKnownVisibility(draft.visibility) && this.defaultVisibility === undefined) {
				this.visibility = draft.visibility
				this.visibilityChosen = true
				this.resumedAudienceFor = this.draftContext
			}
			// checked once the teams are known (loadTeams): the writer may have
			// left the team since
			this.pendingPostAs = draft.postAs
			if (isLanguageCode(draft.language)) {
				this.language = draft.language
			}
			if (draft.replyPolicy !== '') {
				this.replyPolicy = draft.replyPolicy
			}
			if (draft.poll !== null) {
				this.showPoll = true
				this.pollOptions = draft.poll.options.length >= 2 ? draft.poll.options : ['', '']
				this.pollMultiple = draft.poll.multiple
				this.pollExpiresIn = draft.poll.expiresIn
			}
			if (draft.event !== null) {
				this.showEvent = true
				this.eventName = draft.event.name
				this.eventStart = draft.event.start
				this.eventEnd = draft.event.end
				this.eventLocation = draft.event.location
			}
			if (draft.scheduledAt !== '') {
				// a time that has come too close is flagged by the picker, and
				// Post waits until it is moved
				this.scheduling = true
				this.scheduledAt = new Date(draft.scheduledAt)
				this.loadSchedulePicker()
			}
			if (draft.place !== null) {
				this.placing = true
				this.place = draft.place
			}
			if (draft.video !== null) {
				this.videoTitle = draft.video.title
				this.videoCategory = draft.video.category
				this.videoLicence = draft.video.licence
			}
			if (draft.card !== '') {
				this.asCard = true
				this.cardGradientId = draft.card
			}
			if (draft.media.length > 0) {
				this.restoreDraftMedia(draft.media)
			}
			if (this.draftContext.startsWith('redraft.')) {
				this.restoreRedraftTargets(draft.inReplyToId ?? '', draft.quoteId ?? '')
			}
			this.updateStatusContent()

			return true
		},

		/**
		 * Points a re-draft at the post it answered and the post it quoted,
		 * fetching them if needed; one that is gone is dropped.
		 *
		 * @param {string} inReplyToId the post it answered, '' for none
		 * @param {string} quoteId the post it quoted, '' for none
		 * @param {?object} [quote] the quoted post, when it is already in hand
		 * @return {Promise<void>}
		 */
		async restoreRedraftTargets(inReplyToId, quoteId, quote = null) {
			if (quote !== null && this.quoteOf === null) {
				this.quoteOf = quote
			}
			const wanted = [['replyTo', String(inReplyToId ?? '')], ['quoteOf', quote === null ? String(quoteId ?? '') : '']]
			for (const [field, id] of wanted) {
				if (id === '' || this[field] !== null) {
					continue
				}
				let status = this.timelineStore.getStatus(id) ?? null
				if (status === null) {
					try {
						status = await this.timelineStore.fetchStatus(id)
					} catch (error) {
						logger.debug('the post a re-draft answers or quotes could not be loaded', { error, id })
					}
				}
				if (status && this[field] === null) {
					this[field] = status
				}
			}
		},

		/**
		 * Asked by the router before every navigation: lets it go ahead, or
		 * holds it on a question when leaving would lose an upload.
		 *
		 * @param {import('vue-router').RouteLocationNormalized} to where the reader is going
		 * @param {import('vue-router').RouteLocationNormalized} from where they are
		 * @return {boolean|Promise<boolean>} whether to go
		 */
		confirmLeave(to, from) {
			if (!this.wouldLoseOnLeave || to.path === from.path) {
				return true
			}

			this.resolveLeave?.(false)
			this.confirmingLeave = true
			return new Promise((resolve) => {
				this.resolveLeave = resolve
			})
		},

		/**
		 * The answer to the question confirmLeave() asked. Leave closes the
		 * dialog and confirms in the same tick, so a no is held until the tick
		 * is over.
		 *
		 * @param {boolean} leave whether to go
		 */
		answerLeave(leave) {
			this.confirmingLeave = false
			const resolve = this.resolveLeave
			if (resolve === null) {
				return
			}
			if (leave) {
				this.resolveLeave = null
				resolve(true)
				return
			}
			this.$nextTick(() => {
				if (this.resolveLeave === resolve) {
					this.resolveLeave = null
					resolve(false)
				}
			})
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
		 * Attaches a GIF from the instance's shared library. The picker stays
		 * open for more until the attachment ceiling closes it.
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

		/**
		 * Enter is a new line; with Ctrl, or Cmd on a Mac, it sends.
		 *
		 * @param {KeyboardEvent} event the key press
		 */
		keyup(event) {
			if (event.ctrlKey || event.metaKey) {
				this.createPost()
			}
		},

		updatePostFromTribute() {
			this.updateStatusContent()
		},

		n,
		async createPost() {
			if (!this.eventIsValid) {
				this.eventAttempted = true
			}
			if (!this.pollIsValid) {
				this.pollAttempted = true
			}
			if (this.editItem) {
				if (this.canPost) {
					this.$emit('save', this.editPayload)
				}
				return
			}
			if (this.oneLine && this.oneLineNeedsDetails) {
				this.openFullComposer()
				return
			}
			if (!this.canPost || this.loading) {
				return
			}

			// filters are baked in once, and the filtered copies replace the
			// uploads before their ids are read
			this.loading = true
			if (!(await this.bakeFilters())) {
				this.loading = false
				return
			}

			// games are played before sending, so every server shows the same result
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
				// only uploads the server took
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
				event: this.showEvent
					? {
							name: this.eventName.trim(),
							start_time: new Date(this.eventStart).toISOString(),
							end_time: this.eventEnd === '' ? null : new Date(this.eventEnd).toISOString(),
							location: this.eventLocation.trim(),
						}
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
				// `post` rejects when the server refuses; the text is cleared
				// only after it resolves
				created = await this.timelineStore.post(statusData, {
					idempotencyKey: this.idempotency.keyFor(statusData),
				})
			} finally {
				this.loading = false
			}

			if (created === undefined) {
				// the store has reported the error; the draft is kept
				this.rememberDraft()
				return
			}
			this.idempotency.sent()

			const wasScheduled = statusData.scheduled_at !== undefined
			const publishedContext = this.draftContext

			this.replyTo = this.inReplyTo
			this.quoteOf = null
			// back to one line; focus is parked on the edge, since on the box
			// it would reopen it
			this.openedByHand = this.startExpanded
			this.clearComposer()
			if (!this.startExpanded) {
				this.$el.focus?.()
			}
			// tells the sidebar's modal to close
			this.$emit('posted')
			eventBus.emit('composer-published', { context: publishedContext })

			if (wasScheduled) {
				// a ScheduledStatus, not a Status: nothing to refresh or celebrate
				showSuccess(t('social', 'Scheduled for {date}', {
					date: fullDateTime(created.scheduled_at ?? statusData.scheduled_at),
				}))
				eventBus.emit('post-scheduled', created)

				return
			}

			if (created.held_for_review === true) {
				// in the review queue, which the store has already said
				return
			}

			// sound, haptics, and an entrance at the top of the timeline
			feel('post')
			this.justPosted = true
			window.setTimeout(() => {
				this.justPosted = false
			}, 700)
			if (created?.id) {
				this.timelineStore.markArrived(created.id)
			}
			// a ranked list has no top to arrive at, and reloading it would
			// reset the reader's cursor
			if (!isRanked({ type: this.timelineStore.type, params: this.timelineStore.params })) {
				this.timelineStore.refreshTimeline()
			}
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
		 *         failed; the post then does not go out
		 */
		async uploadCard(words) {
			this.loading = true
			const file = await renderTextCard(words, this.cardGradient, { width: 1080, height: 1080 })
			if (file === null) {
				showError(t('social', 'This browser could not draw the card'))
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
		 * Shows the games tumbling for half a second before landing on the
		 * results, which are already decided.
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

		/** Presses or releases the pin; releasing it drops the place as well. */
		togglePlace() {
			this.placing = !this.placing
			if (!this.placing) {
				this.place = null
			}
		},

		/**
		 * Presses or releases the clock. Pressing it fetches the picker and
		 * proposes an hour from now, rounded to the picker's step.
		 */
		async toggleSchedule() {
			if (this.scheduling) {
				this.scheduling = false
				this.scheduledAt = null

				return
			}

			this.scheduling = true
			this.scheduledAt = proposedSchedule()
			await this.loadSchedulePicker()
		},

		/** Fetches the date picker, which the clock and a restored draft both need. */
		async loadSchedulePicker() {
			if (this.schedulePickerLoaded || this.schedulePickerLoading) {
				return
			}

			this.schedulePickerLoading = true
			try {
				await datePickerModule()
				this.schedulePickerLoaded = true
			} catch (error) {
				// onError has logged it; without a picker the post goes out now
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
		 * Writes the AI mark into the words or takes it out, setting and
		 * reading back the text so the draft and preview follow.
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

		toggleEvent() {
			this.showEvent = !this.showEvent
			if (this.showEvent) {
				this.showPoll = false
				this.pollOptions = ['', '']
				this.pollMultiple = false
				if (this.eventStart === '') {
					const start = new Date()
					start.setHours(start.getHours() + 1, 0, 0, 0)
					this.eventStart = new Date(start.getTime() - start.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
					const end = new Date(start.getTime() + 60 * 60 * 1000)
					this.eventEnd = new Date(end.getTime() - end.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
				}
			} else {
				this.eventName = ''
				this.eventStart = ''
				this.eventEnd = ''
				this.eventLocation = ''
			}
		},

		closeReply() {
			this.replyTo = this.inReplyTo
			// an anchored composer has nothing to close back to, so it collapses
			if (this.inReplyTo === null) {
				this.timelineStore.setComposerDisplayStatus(false)

				return
			}

			this.openedByHand = this.startExpanded
		},

		removeQuote() {
			// only the quote goes, unlike closeReply(): the message is kept
			this.quoteOf = null
		},

	},
}

/**
 * The visibility the last post went out with; reading localStorage can throw
 * in a private window.
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

.composer-sensitive {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-block: 10px;
}

// one duration and curve for the whole opening, so its parts arrive together
$composer-ease: var(--social-ease);
$composer-duration: var(--social-motion-base);

.new-post {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 18px;
	margin: calc(var(--default-grid-baseline) * 3) auto;
	// the full column, a little wider than the posts inside the list's gutter
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

	// lifted, not outlined: the box with the caret draws the ring already
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

.new-post.new-post--one-line {
	position: relative;
	z-index: auto;
	margin: 12px auto 20px;
	padding: 10px 12px;
	border-width: 1px;
	border-radius: var(--border-radius-large, 12px);
	box-shadow: none;
}

.composer-line {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	min-width: 0;

	.message.composer-line__input {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 40px;
		max-height: 44px;
		overflow: auto;
		padding: 8px 12px;
		border-color: transparent;
		background: var(--color-background-hover);
		border-radius: 20px;
		font-size: 14px;
	}

	&__send,
	&__expand {
		flex: 0 0 auto;
	}
}

@include layout.below(layout.$phone) {
	.composer-line {
		gap: 4px;
	}
}

.new-post__close-hint {
	color: var(--color-text-maxcontrast);
}

// Collapsed: everything else stays in the document (measured, so the opening
// can be animated) but out of the tab order and accessibility tree.
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

// A file dragged over the card: a tint, the focus border and one pill
// naming what will happen.
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
	// must never take the drop, nor cause dragenter/dragleave churn under the pointer
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
	// matches REFUSAL_DURATION in useComposerAttachments
	animation: composer-refused var(--social-motion-slow) $composer-ease;
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

	// at the far end of the row
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

// With photos the words are a caption: the box gives up height so the photos
// stay within reach of the thumb.
.new-post-form--with-media {
	:deep(.upload-form) {
		margin-top: 10px;
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
	// the icons wrap rather than squeezing the Post button's label
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

.options--compact {
	max-height: none;
	overflow: visible;
}

.more-options-toggle {
	min-height: 36px;
	padding-inline: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
}

.composer-advanced-options {
	display: contents;
}

.options--compact .composer-advanced-options {
	display: flex;
	flex: 0 0 100%;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 8px;
	padding-block-start: 8px;
	border-top: 1px solid var(--color-border);
}

.composer-advanced-options__label {
	flex: 0 0 100%;
	padding-inline: 8px;
	margin-block-start: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: 600;
}

.composer-publishing-controls {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.composer-publishing-controls > * {
	min-width: 0;
}

.composer-publishing-controls :deep(.button-vue) {
	justify-content: center;
	min-width: 0;
}

.options--compact .composer-advanced-options :deep(.button-vue) {
	justify-content: flex-start;
	min-height: 36px;
	padding-inline: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
}

.emptySpace {
	flex-grow: 1;
}

/* the Post button keeps its width and stays at the end of the row when the icons wrap */
.options > :last-child {
	flex-shrink: 0;
	margin-inline-start: auto;
}

/*
 * Phone: the row wraps, the spacer goes, the visibility menu shows its icon
 * only, and Post stays at the end of its row.
 */
@include layout.below(layout.$phone) {
	.options {
		flex-wrap: wrap;
		row-gap: 4px;
		max-height: 120px;

		&--compact {
			max-height: none;
		}

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
	margin: 8px 0 0;
	padding: 8px 12px;
	border-radius: var(--border-radius);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	text-align: start;
}

.composer-alt-warning {
	align-self: center;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	font-weight: 600;
}

/* the allowance as a ring that fills, rather than a limit you discover */
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
	transition: background var(--social-motion-base) var(--social-ease);

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
 * A quick bright sweep across the box when a post goes out, as a
 * pseudo-element so layout and elevation are untouched.
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
		animation: composer-card-in var(--social-motion-slow) var(--social-ease-spring) both;
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
		animation: composer-land var(--social-motion-slow) var(--social-ease-spring) both;
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

.composer-post-as {
	max-width: 180px;
	height: 34px;
	margin-inline-end: 4px;
}

</style>
