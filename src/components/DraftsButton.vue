<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="drafts-entry">
		<NcButton
			v-if="drafts.length > 0"
			ref="trigger"
			:disabled="disabled"
			variant="tertiary"
			@click="show">
			<template #icon>
				<FileDocumentEditOutline :size="20" />
			</template>
			{{ t('social', 'Drafts ({count})', { count: drafts.length }) }}
		</NcButton>
		<NcModal
			v-if="open"
			:name="t('social', 'Drafts')"
			size="normal"
			@close="close">
			<div class="drafts-list">
				<h2>{{ t('social', 'Drafts') }}</h2>
				<p>{{ t('social', 'Drafts are saved in this browser for your account.') }}</p>
				<p v-if="error" class="drafts-list__error" role="alert">
					{{ error }}
				</p>
				<p v-if="drafts.length === 0" role="status">
					{{ t('social', 'No drafts are available.') }}
				</p>
				<ul v-else>
					<li v-for="entry in drafts" :key="entry.context" class="drafts-list__row">
						<button
							type="button"
							class="drafts-list__item"
							:disabled="resuming"
							@click="resume(entry.context)">
							<strong>{{ preview(entry.draft) }}</strong>
							<span>{{ contextLabel(entry.context) }}</span>
							<span v-if="entry.draft.media.length">{{ n('social', '%n attachment', '%n attachments', entry.draft.media.length) }}</span>
							<time v-if="entry.draft.savedAt" :datetime="new Date(entry.draft.savedAt).toISOString()">{{ savedAt(entry.draft.savedAt) }}</time>
						</button>
						<NcButton
							variant="tertiary"
							:disabled="resuming"
							:aria-label="t('social', 'Delete draft: {draft}', { draft: preview(entry.draft) })"
							:title="t('social', 'Delete draft')"
							@click="askDelete(entry.context)">
							<template #icon>
								<DeleteOutline :size="20" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-if="resuming" role="status">
					{{ t('social', 'Opening draft…') }}
				</p>
			</div>
		</NcModal>
		<NcDialog
			:open="deleteDialogOpen"
			:name="t('social', 'Delete this draft?')"
			:buttons="deleteButtons"
			@update:open="deleteDialogOpen = $event">
			<p>{{ t('social', 'This draft will be permanently removed from this browser.') }}</p>
		</NcDialog>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcModal from '@nextcloud/vue/components/NcModal'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import { clearDraft, listDrafts, loadDraft } from '../services/draft.js'
import eventBus from '../services/eventBus.js'
import { useTimelineStore } from '../store/timeline.js'
import { userLocale } from '../utils/locale.js'

export default {
	name: 'DraftsButton',
	components: { NcButton, NcDialog, NcModal, DeleteOutline, FileDocumentEditOutline },
	props: { disabled: { type: Boolean, default: false } },
	data() {
		return { drafts: listDrafts(), open: false, resuming: false, error: '', resumeRequest: 0, deleteContext: null, deleteDialogOpen: false }
	},

	computed: {
		deleteButtons() {
			return [
				{ label: t('social', 'Cancel'), callback: () => { this.deleteDialogOpen = false } },
				{ label: t('social', 'Delete draft'), variant: 'error', callback: () => {
					this.deleteDialogOpen = false
					this.delete(this.deleteContext)
				} },
			]
		},
	},

	watch: {
		disabled(value) {
			if (!value) {
				this.refresh()
			}
		},
	},

	mounted() {
		window.addEventListener('storage', this.refresh)
		window.addEventListener('focus', this.refresh)
	},

	beforeUnmount() {
		this.resumeRequest++
		window.removeEventListener('storage', this.refresh)
		window.removeEventListener('focus', this.refresh)
	},

	methods: {
		t,
		n,
		refresh() {
			this.drafts = listDrafts()
		},

		show() {
			this.refresh()
			this.error = ''
			this.open = true
		},

		close() {
			this.resumeRequest++
			this.open = false
			this.resuming = false
		},

		askDelete(context) {
			this.deleteContext = context
			this.deleteDialogOpen = true
		},

		delete(context) {
			if (typeof context !== 'string' || !clearDraft(context)) {
				this.error = t('social', 'Could not delete this draft. Please try again.')
				return
			}
			this.deleteContext = null
			this.error = ''
			this.refresh()
		},

		contextLabel(context) {
			if (context.startsWith('reply.')) {
				return t('social', 'Reply draft')
			}
			if (context.startsWith('quote.')) {
				return t('social', 'Quote draft')
			}
			if (context.startsWith('redraft.')) {
				return t('social', 'Re-draft')
			}
			return t('social', 'New post')
		},

		preview(draft) {
			const text = draft.spoilerText || draft.text.trim() || draft.event?.name || t('social', 'Media draft')
			return Array.from(text).slice(0, 140).join('')
		},

		savedAt(value) {
			return new Intl.DateTimeFormat(userLocale(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
		},

		async resume(context) {
			if (this.resuming || this.disabled) {
				return
			}
			const request = ++this.resumeRequest
			const draft = loadDraft(context)
			this.error = ''
			if (draft === null) {
				this.refresh()
				this.error = t('social', 'This draft is no longer available.')
				return
			}
			this.resuming = true
			try {
				const trigger = /** @type {{$el?: HTMLElement}|undefined} */ (this.$refs.trigger)
				/** @type {{draft: import('../services/draft.js').ComposerDraft, context: string, opener?: HTMLElement, reply?: object, quote?: object}} */
				const intent = { draft, context, opener: trigger?.$el }
				const target = /^(reply|quote)\.([0-9]+)$/.exec(context)
				if (target) {
					const status = await useTimelineStore().fetchStatus(target[2])
					if (!status) {
						throw new Error('Original post unavailable')
					}
					if (target[1] === 'reply') {
						intent.reply = status
					} else {
						intent.quote = status
					}
				}
				if (!this.open || request !== this.resumeRequest) {
					return
				}
				this.open = false
				await this.$nextTick()
				if (request !== this.resumeRequest || this.disabled) {
					return
				}
				eventBus.emit('composer-open', intent)
			} catch {
				if (this.open && request === this.resumeRequest) {
					this.error = t('social', 'The original post could not be loaded. Your draft has been kept. Try again later.')
				}
			} finally {
				if (request === this.resumeRequest) {
					this.resuming = false
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.drafts-list {
	padding: 20px;

	h2 { margin-block: 0 12px; }
	p { margin-block: 8px 12px; }
	ul { padding: 0; margin: 0; list-style: none; }
	&__row {
		display: flex;
		align-items: center;
		gap: 4px;
	}
	&__row + &__row { margin-block-start: 8px; }

	&__item {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
		flex: 1;
		min-width: 0;
		height: auto;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-weight: 400;
		text-align: start;
		white-space: normal;
		overflow-wrap: anywhere;

		strong { font-weight: 600; }
		span, time { font-size: 13px; }

		&:hover { background: var(--color-background-hover); }
		&:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
	}
}
</style>
