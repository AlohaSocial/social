<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="preview-item-wrapper">
		<div class="preview-item">
			<!-- a refused attachment has no picture behind it, and the spinner
			     MediaAttachment shows for `null` would never stop -->
			<div class="preview-item__filtered" :style="{ filter: filterCss(preview.filter) }">
				<MediaAttachment v-if="!preview.failed" :attachment="preview.data" />
			</div>

			<!-- Where the subject is. A square crop — the profile grid here, the
			     timeline on Mastodon — is cut around this point rather than
			     around the middle, which is where a face is not. The point is
			     set by pressing, or dragging, on the picture itself, and moved
			     by the arrow keys for anyone who is not using a pointer. -->
			<div
				v-if="focusing"
				ref="focusPad"
				class="preview-item__focus-pad"
				role="group"
				tabindex="0"
				:aria-label="t('social', 'Focal point. Click where the subject is, or move it with the arrow keys.')"
				@pointerdown="startFocusing"
				@pointermove="moveFocus"
				@pointerup="finishFocusing"
				@pointercancel="finishFocusing"
				@keydown="nudgeByKey" />

			<!-- the point itself, drawn whenever there is one and while one is
			     being set; a picture with no point is cropped at the middle -->
			<span
				v-if="showsCrosshair"
				class="preview-item__crosshair"
				:style="{ insetInlineStart: crosshairPosition.left, insetBlockStart: crosshairPosition.top }"
				aria-hidden="true" />

			<div class="preview-item__actions">
				<NcButton variant="tertiary-no-background" @click="$emit('delete', randomKey)">
					<template #icon>
						<Close :size="16" fillColor="white" />
					</template>
					<span>{{ t('social', 'Delete') }}</span>
				</NcButton>
				<!-- pictures the server holds only: the point is saved against
				     the upload, and there is nothing to save it against until
				     the upload has come back -->
				<NcButton
					v-if="canFocus"
					variant="tertiary-no-background"
					class="preview-item__focus-toggle"
					:aria-label="focusing ? t('social', 'Done setting the focal point') : t('social', 'Set the focal point')"
					:title="focusing ? t('social', 'Done setting the focal point') : t('social', 'Set the focal point')"
					:aria-pressed="focusing"
					@click="toggleFocusing">
					<template #icon>
						<ImageFilterCenterFocus :size="16" fillColor="white" />
					</template>
				</NcButton>
			</div>

			<!-- a picture nobody described is a picture some readers never see -->
			<span v-if="!described && !preview.failed" class="preview-item__missing" aria-hidden="true">
				{{ t('social', 'No description') }}
			</span>

			<!-- says that a point is set without drawing attention to where:
			     the crosshair is the where, and this is the badge that outlives
			     the editor -->
			<span v-if="hasFocus && !focusing" class="preview-item__focal" aria-hidden="true">
				{{ t('social', 'Focal point') }}
			</span>

			<!-- one attachment out of several can be refused, and the grid is
			     the only place that can say which one -->
			<span v-if="preview.failed" class="preview-item__failed" role="status">
				{{ t('social', 'Could not be attached') }}
			</span>
		</div>

		<!-- Pictures only: there is nothing a filter could do to a video or an
		     audio file, and offering one would be a button that does nothing.
		     Not until the upload has landed either: choosing a filter replaces
		     the uploaded copy, and starting that while the first upload is
		     still in flight is a race with no winner. -->
		<FilterPicker
			v-if="!preview.failed && isPicture && preview.data"
			class="preview-item__filters"
			:modelValue="preview.filter || 'none'"
			:preview="previewUrl"
			@update:modelValue="$emit('filter', { key: randomKey, filter: $event })" />

		<label v-if="!preview.failed" class="preview-item__label" :for="fieldId">
			{{ t('social', 'Describe this for people who cannot see it') }}
		</label>
		<textarea
			v-if="!preview.failed"
			:id="fieldId"
			class="preview-item__description"
			rows="2"
			maxlength="1500"
			:value="preview.description || ''"
			:placeholder="t('social', 'A cat asleep on a keyboard')"
			@input="$emit('describe', { key: randomKey, description: valueOf($event) })"
			@change="$emit('commitDescription', { key: randomKey, description: valueOf($event) })" />
	</div>
</template>

<script>
import Close from 'vue-material-design-icons/Close.vue'
import ImageFilterCenterFocus from 'vue-material-design-icons/ImageFilterCenterFocus.vue'
import FilterPicker from './FilterPicker.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { filterCss } from '../../utils/imageFilters.js'
import { NUDGE, focusFromPoint, isFocalPoint, nudgeFocus, positionOfFocus } from '../../utils/focalPoint.js'
import { translate } from '@nextcloud/l10n'
import { valueOf } from '../../utils/dom.js'
import MediaAttachment from '../MediaAttachment.vue'

/** which way each arrow key moves the point, in focus units (y points up) */
const ARROWS = {
	ArrowLeft: [-NUDGE, 0],
	ArrowRight: [NUDGE, 0],
	ArrowUp: [0, NUDGE],
	ArrowDown: [0, -NUDGE],
}

export default {
	name: 'PreviewGridItem',
	components: {
		Close,
		FilterPicker,
		ImageFilterCenterFocus,
		NcButton,
		MediaAttachment,
	},

	props: {
		preview: {
			type: /** @type {import('vue').PropType<import('../../types/Composer.js').LocalAttachment>} */ (Object),
			required: true,
		},

		randomKey: {
			type: String,
			required: true,
		},
	},

	emits: ['delete', 'describe', 'commitDescription', 'focus', 'commitFocus', 'filter'],

	data() {
		return {
			/** whether the picture is showing its focal point editor */
			focusing: false,
			/** whether a pointer is down on the editor and dragging the point */
			dragging: false,
		}
	},

	computed: {
		described() {
			return (this.preview.description || '').trim() !== ''
		},

		/** @return {boolean} whether the attachment has a focal point set */
		hasFocus() {
			return isFocalPoint(this.preview.focus)
		},

		/**
		 * Whether a focal point can be set at all: an uploaded picture. A
		 * video is cropped by its poster and an audio file by nothing.
		 *
		 * @return {boolean}
		 */
		canFocus() {
			const type = this.preview?.file?.type || this.preview?.data?.type || ''

			return !this.preview.failed && Boolean(this.preview.data?.id)
				&& (type.startsWith('image/') || type === 'image')
		},

		/** @return {boolean} */
		showsCrosshair() {
			return this.canFocus && (this.focusing || this.hasFocus)
		},

		/** @return {{left: string, top: string}} where the crosshair is drawn */
		crosshairPosition() {
			return positionOfFocus(this.preview.focus)
		},

		/** Unique per attachment, so the label points at its own field. */
		fieldId() {
			return 'composer-alt-' + this.randomKey
		},

		/**
		 * Whether a filter would do anything. Video and audio have no filter
		 * to apply, and an animated picture would come back as its first frame.
		 *
		 * @return {boolean}
		 */
		isPicture() {
			const type = this.preview?.file?.type || this.preview?.data?.type || ''

			return type.startsWith('image/')
				? !['image/gif', 'image/webp'].includes(type)
				: type === 'image'
		},

		/** @return {string} the object URL the swatches draw, which is the key */
		previewUrl() {
			return this.randomKey
		},
	},

	methods: {
		t: translate,
		filterCss,
		valueOf,

		toggleFocusing() {
			this.focusing = !this.focusing
			if (this.focusing) {
				// the pad takes the keys, so it takes the focus: pressing the
				// button and then an arrow should move the point, not the page
				this.$nextTick(() => /** @type {HTMLElement|undefined} */ (this.$refs.focusPad)?.focus())
			}
		},

		/**
		 * @param {PointerEvent} event a press on the picture
		 */
		startFocusing(event) {
			if (event.button !== undefined && event.button !== 0) {
				return
			}

			event.preventDefault()
			this.dragging = true
			// keeps the moves coming even once the pointer has left the pad,
			// which is exactly where a drag towards the edge ends up
			const pad = /** @type {HTMLElement|null} */ (event.currentTarget)
			if (typeof pad?.setPointerCapture === 'function') {
				pad.setPointerCapture(event.pointerId)
			}
			this.setFocusFromEvent(event)
		},

		/**
		 * @param {PointerEvent} event the pointer moving over the picture
		 */
		moveFocus(event) {
			if (!this.dragging) {
				return
			}

			this.setFocusFromEvent(event)
		},

		/**
		 * The drag is over, and this is the value worth a request: the moves
		 * in between were the preview.
		 *
		 * @param {PointerEvent} event the release
		 */
		finishFocusing(event) {
			if (!this.dragging) {
				return
			}

			this.dragging = false
			if (event.type === 'pointerup') {
				this.setFocusFromEvent(event)
			}
			this.$emit('commitFocus', { key: this.randomKey, focus: this.preview.focus })
		},

		/**
		 * @param {PointerEvent} event a pointer somewhere over the pad
		 */
		setFocusFromEvent(event) {
			const pad = /** @type {HTMLElement} */ (event.currentTarget)
			const bounds = pad.getBoundingClientRect()
			const focus = focusFromPoint(
				event.clientX - bounds.left,
				event.clientY - bounds.top,
				bounds.width,
				bounds.height,
			)
			this.$emit('focus', { key: this.randomKey, focus })
		},

		/**
		 * @param {KeyboardEvent} event a key pressed on the pad
		 */
		nudgeByKey(event) {
			const step = ARROWS[event.key]
			if (step === undefined) {
				return
			}

			event.preventDefault()
			const focus = nudgeFocus(this.preview.focus, step[0], step[1])
			this.$emit('focus', { key: this.randomKey, focus })
			// a key press is a decision in itself; there is no release to wait for
			this.$emit('commitFocus', { key: this.randomKey, focus })
		},
	},
}
</script>

<style scoped lang="scss">
.preview-item-wrapper {
	flex: 1 1 0;
	min-width: 40%;
	margin: 5px;
}

.preview-item__missing {
	position: absolute;
	inset-inline-start: 8px;
	inset-block-end: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 12px;
	font-weight: 600;
}

.preview-item__focal {
	position: absolute;
	inset-inline-end: 8px;
	inset-block-end: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: rgba(0, 0, 0, .6);
	color: white;
	font-size: 12px;
	font-weight: 600;
}

// the whole picture is the control; above the badges and under the buttons
.preview-item__focus-pad {
	position: absolute;
	inset: 0;
	z-index: 2;
	cursor: crosshair;
	touch-action: none;

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: -2px;
	}
}

// a ring with a dot, centred on the point, that takes no clicks of its own.
// The dark outline is what keeps it visible over a white sky; it is an
// outline rather than a shadow, which on a card means the elevation App.vue
// defines and not a rim.
.preview-item__crosshair {
	position: absolute;
	z-index: 3;
	width: 28px;
	height: 28px;
	margin-inline-start: -14px;
	margin-block-start: -14px;
	border: 2px solid white;
	border-radius: 50%;
	outline: 1px solid rgba(0, 0, 0, .5);
	pointer-events: none;

	&::after {
		content: '';
		position: absolute;
		inset: 50%;
		width: 6px;
		height: 6px;
		margin: -3px;
		border-radius: 50%;
		background: white;
		outline: 1px solid rgba(0, 0, 0, .5);
	}
}

.preview-item__failed {
	position: absolute;
	inset-inline-start: 8px;
	inset-block-end: 8px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-error);
	color: var(--color-primary-element-text, white);
	font-size: 12px;
	font-weight: 600;
}

.preview-item__label {
	display: block;
	margin: 6px 2px 2px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.preview-item__description {
	width: 100%;
	min-height: 44px;
	resize: vertical;
	box-sizing: border-box;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	padding: 6px 8px;

	&:focus-visible {
		border-color: var(--color-primary-element);
		outline: 2px solid var(--color-primary-element);
		outline-offset: 1px;
	}
}

.preview-item {
	border-radius: var(--border-radius-large);
	background: var(--color-background-darker);
	background-position: 50%;
	background-size: cover;
	background-repeat: no-repeat;
	height: 140px;
	width: 100%;
	overflow: hidden;
	position: relative;

	.button-vue--tertiary-no-background {
		color: white !important;
	}

	&__actions {
		position: absolute;
		top: 0;
		z-index: 4;
		width: 100%;
		background: linear-gradient(180deg,rgba(0,0,0,.8),rgba(0,0,0,.35) 80%,transparent);
		display: flex;
		align-items: flex-start;
		justify-content: space-between;

		.button-vue__text {
			color: white !important;
		}
	}

	.description-warning {
		position: absolute;
		z-index: 2;
		bottom: 0;
		inset-inline: 0;
		box-sizing: border-box;
		background: linear-gradient(0deg,rgba(0,0,0,.8),rgba(0,0,0,.35) 80%,transparent);
		color: white;
		padding: 10px;
	}
}

.modal__content {
	padding: 20px;
}

textarea {
	width: 100%;
	height: 100px;
	margin-bottom: 20px;
}
</style>
