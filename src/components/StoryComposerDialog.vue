<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Add to your story')"
		:buttons="buttons"
		class="story-composer"
		@update:open="$emit('update:open', $event)">
		<p class="story-composer__hint">
			{{ t('social', 'A picture, a video or a few words, for the people who follow you, gone after a day.') }}
		</p>

		<div class="story-composer__kinds" role="radiogroup" :aria-label="t('social', 'What kind of story')">
			<button
				type="button"
				role="radio"
				class="story-composer__kind"
				:aria-checked="kind === 'media'"
				@click="kind = 'media'">
				<IconImagePlus :size="20" />
				{{ t('social', 'Picture or video') }}
			</button>
			<button
				type="button"
				role="radio"
				class="story-composer__kind"
				:aria-checked="kind === 'text'"
				@click="kind = 'text'">
				<IconFormatText :size="20" />
				{{ t('social', 'Text') }}
			</button>
		</div>

		<!-- opened by the button below, which is the control a keyboard and a
		     screen reader reach; the input stays out of both -->
		<input
			ref="file"
			type="file"
			accept="image/*,video/mp4,video/webm,video/quicktime"
			class="hidden-visually"
			tabindex="-1"
			aria-hidden="true"
			@change="choose">

		<template v-if="kind === 'text'">
			<!-- the card as it will be drawn: the same background, and type
			     that shrinks as the words grow, the way the picture will -->
			<div
				class="story-composer__card"
				:style="{ background: cardBackground, color: gradient.ink }"
				aria-hidden="true">
				<p class="story-composer__card-text" :style="{ fontSize: cardFontSize }">
					{{ storyText.trim() || t('social', 'Your words here') }}
				</p>
			</div>
			<NcTextArea
				v-model="storyText"
				class="story-composer__words"
				:label="t('social', 'What you want to say')"
				:maxlength="TEXT_STORY_MAX"
				resize="vertical" />
			<div class="story-composer__gradients" role="radiogroup" :aria-label="t('social', 'Background')">
				<button
					v-for="option in gradients"
					:key="option.id"
					type="button"
					role="radio"
					class="story-composer__gradient"
					:aria-checked="option.id === gradientId"
					:aria-label="option.name"
					:title="option.name"
					:style="{ background: backgroundOf(option) }"
					@click="gradientId = option.id" />
			</div>
		</template>

		<template v-else>
			<button
				v-if="!file"
				type="button"
				class="story-composer__pick"
				@click="$refs.file.click()">
				<IconImagePlus :size="32" />
				<span>{{ t('social', 'Choose a picture or a video') }}</span>
			</button>

			<div v-else class="story-composer__preview">
				<div ref="stage" class="story-composer__stage">
					<video
						v-if="isVideo"
						class="story-composer__media"
						:src="previewUrl"
						muted
						playsinline
						controls />
					<img
						v-else
						class="story-composer__media"
						:src="previewUrl"
						alt=""
						@load="measureStage">
					<!-- each sticker is a button: dragged with a finger or a
					     pointer, moved with the arrow keys, removed with Delete -->
					<button
						v-for="sticker in stickers"
						:key="sticker.id"
						type="button"
						class="story-composer__sticker"
						:class="{
							'story-composer__sticker--text': sticker.kind === 'text',
							'story-composer__sticker--selected': sticker.id === selected,
						}"
						:style="stickerStyle(sticker)"
						:aria-label="t('social', 'Sticker {what}. Drag or use the arrow keys to move it, Delete to remove it.', { what: sticker.value })"
						@pointerdown="startDrag(sticker, $event)"
						@keydown="nudge(sticker, $event)"
						@focus="selected = sticker.id">
						{{ sticker.value }}
					</button>
				</div>

				<div v-if="!isVideo" class="story-composer__stickers">
					<span class="story-composer__stickers-label">{{ t('social', 'Stickers') }}</span>
					<button
						v-for="emoji in STICKERS"
						:key="emoji"
						type="button"
						class="story-composer__add"
						:aria-label="t('social', 'Add {emoji}', { emoji })"
						@click="addSticker('emoji', emoji)">
						{{ emoji }}
					</button>
					<form class="story-composer__add-text" @submit.prevent="addTextSticker">
						<NcTextField
							v-model="stickerText"
							:label="t('social', 'Words on the picture')"
							maxlength="60" />
						<NcButton type="submit" variant="tertiary" :disabled="stickerText.trim() === ''">
							{{ t('social', 'Add') }}
						</NcButton>
					</form>
					<NcButton v-if="selected !== null" variant="tertiary" @click="removeSticker(selected)">
						<template #icon>
							<IconDelete :size="20" />
						</template>
						{{ t('social', 'Remove sticker') }}
					</NcButton>
				</div>

				<NcButton variant="tertiary" @click="$refs.file.click()">
					{{ t('social', 'Choose another') }}
				</NcButton>
			</div>
		</template>

		<NcTextField
			v-if="kind === 'media'"
			v-model="caption"
			class="story-composer__caption"
			:label="t('social', 'Caption')"
			:placeholder="t('social', 'Optional')"
			maxlength="500" />

		<fieldset v-if="!isVideo" class="story-composer__duration">
			<legend>{{ t('social', 'Shown for') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in durations"
				:key="option"
				v-model="duration"
				type="radio"
				name="story-duration"
				:value="String(option)">
				{{ n('social', '%n second', '%n seconds', option) }}
			</NcCheckboxRadioSwitch>
		</fieldset>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { n, t } from '@nextcloud/l10n'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconDelete from 'vue-material-design-icons/DeleteOutline.vue'
import IconFormatText from 'vue-material-design-icons/FormatText.vue'
import IconImagePlus from 'vue-material-design-icons/ImagePlus.vue'
import { accountHue } from '../services/accountColour.js'
import logger from '../services/logger.js'
import { feel } from '../services/senses.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'
import { useTimelineStore } from '../store/timeline.js'
import { bakeStickers, cardGradients, findGradient, gradientCss, renderTextCard } from '../utils/textCard.js'

/** the seconds a picture may be shown for; the server clamps to 3–30 */
export const STORY_DURATIONS = [5, 10, 15]

/** the most a text story may say: enough for a thought, too little for an essay */
export const TEXT_STORY_MAX = 280

/** the emoji offered as stickers, the ones people reach for on a picture */
export const STICKERS = ['😂', '😍', '🔥', '🎉', '👍', '❤️', '😮', '✨']

/** how far one arrow key press moves a sticker, as a fraction of the picture */
const NUDGE = 0.02

let stickerSerial = 0

/**
 * Posts one story: a picture or a video with stickers on it, or a few words
 * on a coloured card, and how long it stays on screen.
 *
 * A text story and the stickers are drawn onto a picture in the browser
 * (utils/textCard.js) and uploaded as one, so they reach other servers as what
 * they look like. The words of a text story go with it as its description.
 *
 * The file goes up through the same upload every attachment takes
 * (`/api/v1/media`), so it is stripped of its metadata like any other
 * picture posted here, and the story is then made of that upload. A story
 * is for followers and lasts a day; the dialog says so, since neither is
 * how a post behaves.
 */
export default {
	name: 'StoryComposerDialog',

	components: {
		IconDelete,
		IconFormatText,
		IconImagePlus,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextArea,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'posted'],

	data() {
		return {
			/** @type {File|null} */
			file: null,
			previewUrl: '',
			caption: '',
			duration: '5',
			posting: false,
			durations: STORY_DURATIONS,
			/** 'media' for a picture or a video, 'text' for words on a card */
			kind: 'media',
			storyText: '',
			gradientId: 'account',
			/** @type {Array<{ id: number, kind: string, value: string, x: number, y: number }>} */
			stickers: [],
			/** the sticker being moved or about to be removed, by id */
			selected: null,
			stickerText: '',
			/** the shorter side of the picture on screen, which sticker sizes follow */
			stageEdge: 0,
			/** the sticker under the pointer, by id, while it is dragged */
			dragging: null,
			/** the window listeners of a drag in progress, so they can be taken off */
			onDrag: null,
			onDrop: null,
			TEXT_STORY_MAX,
			STICKERS,
		}
	},

	computed: {
		...mapStores(useAccountStore, useTimelineStore),

		/** @return {boolean} */
		isVideo() {
			return this.kind === 'media' && Boolean(this.file && this.file.type.startsWith('video/'))
		},

		/** @return {number} the writer's own hue, for the background that is theirs */
		hue() {
			return accountHue(this.accountStore.currentAccount?.acct ?? '')
		},

		/** @return {object[]} the backgrounds a text story can have */
		gradients() {
			return cardGradients(this.hue)
		},

		/** @return {object} the one chosen */
		gradient() {
			return findGradient(this.gradientId, this.hue)
		},

		/** @return {string} it, as CSS */
		cardBackground() {
			return gradientCss(this.gradient)
		},

		/**
		 * Roughly what the drawn card does: three words fill it, three
		 * sentences are small. The picture works it out exactly; this only has
		 * to look like it will.
		 *
		 * @return {string} a font size
		 */
		cardFontSize() {
			const length = this.storyText.trim().length
			if (length <= 24) {
				return '28px'
			}
			if (length <= 80) {
				return '21px'
			}
			if (length <= 160) {
				return '16px'
			}

			return '13px'
		},

		/** @return {boolean} whether there is anything to post */
		ready() {
			return this.kind === 'text' ? this.storyText.trim() !== '' : Boolean(this.file)
		},

		buttons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => this.$emit('update:open', false) },
				{
					label: this.posting ? t('social', 'Posting …') : t('social', 'Post'),
					variant: 'primary',
					disabled: !this.ready || this.posting,
					callback: () => this.post(),
				},
			]
		},
	},

	beforeUnmount() {
		this.releasePreview()
		this.stopDrag()
	},

	methods: {
		t,
		n,

		/**
		 * @param {Event} event the file input's change
		 */
		choose(event) {
			const file = event.target?.files?.[0] ?? null
			// the input keeps its selection, so picking the same file twice in
			// a row would otherwise be ignored the second time
			event.target.value = ''
			if (!file) {
				return
			}

			this.releasePreview()
			this.file = file
			this.previewUrl = URL.createObjectURL(file)
			// stickers were placed on the last picture, not this one
			this.stickers = []
			this.selected = null
		},

		/** @param {object} option a background @return {string} it as CSS */
		backgroundOf(option) {
			return gradientCss(option)
		},

		/** Reads how big the picture is drawn, which the sticker sizes follow. */
		measureStage() {
			const box = /** @type {HTMLElement|undefined} */ (this.$refs.stage)?.getBoundingClientRect?.()
			this.stageEdge = box ? Math.min(box.width, box.height) : 0
		},

		/**
		 * @param {object} sticker a sticker
		 * @return {object} where it sits and how big, as a style binding
		 */
		stickerStyle(sticker) {
			const edge = this.stageEdge || 240
			const size = sticker.kind === 'emoji' ? edge * 0.16 : edge * 0.055

			return {
				left: (sticker.x * 100) + '%',
				top: (sticker.y * 100) + '%',
				fontSize: Math.max(12, Math.round(size)) + 'px',
			}
		},

		/**
		 * Puts a sticker in the middle, a little off where the last one went
		 * so two do not land exactly on top of each other.
		 *
		 * @param {'emoji'|'text'} kind what it is
		 * @param {string} value the emoji or the words
		 */
		addSticker(kind, value) {
			const offset = (this.stickers.length % 5) * 0.06
			const sticker = { id: ++stickerSerial, kind, value, x: 0.5 + offset - 0.12, y: 0.5 + offset - 0.12 }
			this.stickers.push(sticker)
			this.selected = sticker.id
			feel('react')
		},

		addTextSticker() {
			const words = this.stickerText.trim()
			if (words === '') {
				return
			}

			this.addSticker('text', words)
			this.stickerText = ''
		},

		/** @param {number} id the sticker to take off */
		removeSticker(id) {
			this.stickers = this.stickers.filter((sticker) => sticker.id !== id)
			if (this.selected === id) {
				this.selected = null
			}
		},

		/**
		 * @param {object} sticker the sticker being pressed
		 * @param {PointerEvent} event the press
		 */
		startDrag(sticker, event) {
			this.selected = sticker.id
			this.dragging = sticker.id
			this.measureStage()
			const target = /** @type {HTMLElement|null} */ (event.target)
			target?.setPointerCapture?.(event.pointerId)
			this.onDrag = (move) => this.drag(move)
			this.onDrop = () => this.stopDrag()
			window.addEventListener('pointermove', this.onDrag)
			window.addEventListener('pointerup', this.onDrop)
		},

		/** @param {PointerEvent} event where the pointer is now */
		drag(event) {
			const box = /** @type {HTMLElement|undefined} */ (this.$refs.stage)?.getBoundingClientRect?.()
			const sticker = this.stickers.find((one) => one.id === this.dragging)
			if (!box || !sticker || box.width === 0 || box.height === 0) {
				return
			}

			sticker.x = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width))
			sticker.y = Math.min(1, Math.max(0, (event.clientY - box.top) / box.height))
		},

		stopDrag() {
			this.dragging = null
			if (this.onDrag) {
				window.removeEventListener('pointermove', this.onDrag)
				window.removeEventListener('pointerup', this.onDrop)
				this.onDrag = null
				this.onDrop = null
			}
		},

		/**
		 * The keyboard's way of doing what the finger does.
		 *
		 * @param {object} sticker the focused sticker
		 * @param {KeyboardEvent} event the key
		 */
		nudge(sticker, event) {
			const moves = {
				ArrowLeft: [-NUDGE, 0],
				ArrowRight: [NUDGE, 0],
				ArrowUp: [0, -NUDGE],
				ArrowDown: [0, NUDGE],
			}
			if (event.key === 'Delete' || event.key === 'Backspace') {
				event.preventDefault()
				this.removeSticker(sticker.id)

				return
			}

			const move = moves[event.key]
			if (move === undefined) {
				return
			}

			event.preventDefault()
			sticker.x = Math.min(1, Math.max(0, sticker.x + move[0]))
			sticker.y = Math.min(1, Math.max(0, sticker.y + move[1]))
		},

		/**
		 * The file that goes up: a text story drawn to a picture, a picture with
		 * its stickers baked in, or the chosen file as it is.
		 *
		 * @return {Promise<File|null>} the file, or null when it could not be drawn
		 */
		async fileToPost() {
			if (this.kind === 'text') {
				return renderTextCard(this.storyText.trim(), this.gradient, { width: 1080, height: 1920 })
			}

			if (!this.isVideo && this.stickers.length > 0) {
				return bakeStickers(this.file, this.stickers)
			}

			return this.file
		},

		/** Back to an empty dialog, for the next story. */
		reset() {
			this.releasePreview()
			this.file = null
			this.caption = ''
			this.storyText = ''
			this.stickers = []
			this.selected = null
			this.stickerText = ''
		},

		releasePreview() {
			if (this.previewUrl !== '') {
				URL.revokeObjectURL(this.previewUrl)
				this.previewUrl = ''
			}
		},

		/** @return {Promise<void>} */
		async post() {
			if (!this.ready || this.posting) {
				return
			}

			this.posting = true
			try {
				const upload = await this.fileToPost()
				if (!upload) {
					showError(t('social', 'This browser could not draw the story'))
					return
				}

				const media = await this.timelineStore.createMedia(upload)
				if (!media?.id) {
					// the store has already said what went wrong
					return
				}

				// the words of a text story are what the picture shows, and
				// the only way somebody who cannot see it will know
				if (this.kind === 'text') {
					await this.timelineStore.describeMedia({ id: media.id, description: this.storyText.trim() })
				}

				const { data } = await axios.post(generateUrl('apps/social/api/v1/stories'), {
					media_id: media.id,
					caption: this.kind === 'text' ? '' : this.caption.trim(),
					duration: Number(this.duration) || 5,
				})
				showSuccess(t('social', 'Your story is up for a day'))
				feel('post')
				this.$emit('posted', data)
				this.$emit('update:open', false)
				this.reset()
			} catch (error) {
				logger.error('could not post the story', { error })
				showError(error?.response?.data?.error || t('social', 'Could not post the story'))
			} finally {
				this.posting = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.story-composer__hint {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.story-composer__pick {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	justify-content: center;
	width: 100%;
	min-height: 160px;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	cursor: pointer;

	&:hover,
	&:focus-visible {
		border-color: var(--color-primary-element);
		color: var(--color-main-text);
	}
}

.story-composer__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
}

.story-composer__media {
	display: block;
	max-width: 100%;
	max-height: 50vh;
	border-radius: var(--border-radius-large, 12px);
	object-fit: contain;
}

.story-composer__kinds {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.story-composer__kind {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;

	&[aria-checked="true"] {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-weight: 600;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

/* the text card, drawn tall like the story it will be */
.story-composer__card {
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 9 / 16;
	max-height: 42vh;
	margin-inline: auto;
	padding: 10%;
	border-radius: var(--border-radius-large, 12px);
	transition: background .3s ease;
}

.story-composer__card-text {
	margin: 0;
	font-weight: 700;
	line-height: 1.25;
	text-align: center;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	transition: font-size .2s ease;
}

.story-composer__words {
	margin-top: 12px;
}

.story-composer__gradients {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin-top: 8px;
}

.story-composer__gradient {
	inline-size: 36px;
	block-size: 36px;
	padding: 0;
	border: 3px solid var(--color-main-background);
	border-radius: 50%;
	outline: 2px solid transparent;
	cursor: pointer;
	transition: transform .15s ease;

	&[aria-checked="true"] {
		outline-color: var(--color-primary-element);
		transform: scale(1.1);
	}

	&:focus-visible {
		outline-color: var(--color-main-text);
	}
}

/* the picture and its stickers: the stage shrinks to the picture, so a
   sticker's place as a fraction of the stage is its place on the picture */
.story-composer__stage {
	position: relative;
	display: inline-block;
	max-width: 100%;
	touch-action: none;
}

.story-composer__sticker {
	position: absolute;
	transform: translate(-50%, -50%);
	padding: 0;
	border: 2px dashed transparent;
	border-radius: 8px;
	background: none;
	line-height: 1;
	cursor: grab;
	user-select: none;
	animation: story-sticker-in .3s cubic-bezier(.3, 1.5, .5, 1) both;

	&--text {
		padding: .35em .6em;
		border-radius: 999px;
		background: rgba(255, 255, 255, .92);
		color: #111;
		font-weight: 700;
	}

	&--selected {
		border-color: var(--color-primary-element);
	}

	&:active {
		cursor: grabbing;
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

@keyframes story-sticker-in {
	from { transform: translate(-50%, -50%) scale(.3) rotate(-15deg); opacity: 0; }
	to { transform: translate(-50%, -50%); opacity: 1; }
}

.story-composer__stickers {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: 4px 6px;
}

.story-composer__stickers-label {
	inline-size: 100%;
	text-align: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.story-composer__add {
	inline-size: 40px;
	block-size: 40px;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: var(--color-background-hover);
	font-size: 22px;
	cursor: pointer;
	transition: transform .15s ease;

	&:hover {
		transform: scale(1.15);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}
}

.story-composer__add-text {
	display: flex;
	align-items: flex-end;
	gap: 6px;
	inline-size: 100%;
	margin-top: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.story-composer__card,
	.story-composer__card-text,
	.story-composer__gradient,
	.story-composer__add {
		transition: none;
	}

	.story-composer__sticker {
		animation: none;
	}
}

.story-composer__caption {
	margin-top: 12px;
}

.story-composer__duration {
	margin-top: 12px;
	padding: 0;
	border: 0;

	legend {
		margin-bottom: 4px;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}
</style>
