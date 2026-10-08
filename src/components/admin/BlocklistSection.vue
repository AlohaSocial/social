<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'Blocking a server also deletes everything this server holds from it, so read a list before you apply it.') }}
		</p>

		<h4 class="blocklist__heading">
			{{ t('social', 'Import a file') }}
		</h4>
		<p class="social-admin__hint">
			{{ t('social', 'A CSV as Mastodon exports one — a #domain column, optionally a #severity column — or a plain file with one server a line.') }}
		</p>

		<div class="blocklist__file">
			<input
				ref="file"
				type="file"
				accept=".csv,text/csv,text/plain"
				class="blocklist__input"
				:aria-label="t('social', 'Block list file')"
				@change="read">
			<NcButton :disabled="busy" @click="pick">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Choose a file') }}
			</NcButton>
		</div>

		<p v-if="fileError" class="blocklist__error" role="alert">
			{{ fileError }}
		</p>

		<!-- The number in front of an administrator before they agree is the
		     whole point: a file from somebody else naming two hundred servers
		     is not a thing to apply and then read. -->
		<div v-if="preview" class="blocklist__preview">
			<p>
				<strong>{{ n('social', '%n server would be blocked', '%n servers would be blocked', preview.blocked) }}</strong>
				<span v-if="preview.silenced"> · {{ n('social', '%n silenced', '%n silenced', preview.silenced) }}</span>
				<span v-if="preview.alreadyBlocked + preview.alreadySilenced">
					· {{ n('social', '%n already listed', '%n already listed', preview.alreadyBlocked + preview.alreadySilenced) }}
				</span>
			</p>
			<ul class="blocklist__rows">
				<li v-for="row in preview.entries.slice(0, SHOWN)" :key="row.domain">
					<span class="blocklist__domain">{{ row.domain }}</span>
					<span class="blocklist__severity">{{ severityLabel(row.severity) }}</span>
				</li>
			</ul>
			<p v-if="preview.entries.length > SHOWN" class="social-admin__hint">
				{{ n('social', 'and %n more', 'and %n more', preview.entries.length - SHOWN) }}
			</p>
			<NcButton variant="primary" :disabled="busy" @click="apply">
				{{ t('social', 'Apply this list') }}
			</NcButton>
		</div>

		<h4 class="blocklist__heading">
			{{ t('social', 'Follow a published list') }}
		</h4>
		<p class="social-admin__hint">
			{{ t('social', 'Re-read once a day, and applied the same way. A server publishes its own list only if its administrators chose to; one that has not says so here. Turning a source off stops it being re-read — it does not undo what it already applied, because a block deletes what was held and that does not come back.') }}
		</p>

		<ul class="blocklist__sources">
			<li v-for="source in sources" :key="source.id" class="blocklist__source">
				<div class="blocklist__source-head">
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="source.enabled"
						:disabled="busy"
						@update:modelValue="toggle(source, $event)">
						{{ source.label }}
					</NcCheckboxRadioSwitch>
					<NcButton
						size="small"
						variant="tertiary"
						:disabled="busy"
						@click="check(source)">
						{{ t('social', 'Check now') }}
					</NcButton>
				</div>
				<p class="blocklist__source-url">
					{{ source.url }}
				</p>
				<p v-if="resultOf(source)" class="blocklist__source-result" :class="{ 'blocklist__source-result--error': resultOf(source).error }">
					{{ resultOf(source).error || summaryOf(resultOf(source)) }}
				</p>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorMessage, moderationUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/** How many rows of a preview are drawn before it says "and n more". */
const SHOWN = 25

/** The biggest file worth reading, matching the server's own ceiling. */
const MAX_BYTES = 8 * 1024 * 1024

/**
 * Block lists: one uploaded by hand, and the ones this instance follows.
 *
 * Both halves are the same code on the server, so a list imported here and the
 * same list subscribed to cannot turn out to have been read differently.
 */
export default {
	name: 'BlocklistSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
	},

	/** the access list above this card has to be told when one is applied */
	emits: ['changed'],

	data() {
		return {
			SHOWN,
			busy: false,
			/** the parsed file, as the server read it */
			preview: null,
			/** what was uploaded, kept so Apply does not need the file again */
			csv: '',
			fileError: '',
			sources: [],
			/** what a Check now answered, by source id */
			results: {},
		}
	},

	async mounted() {
		await this.loadSources()
	},

	methods: {
		t,
		n,

		pick() {
			/** @type {HTMLInputElement|undefined} */ (this.$refs.file)?.click?.()
		},

		/**
		 * Reads the chosen file and asks the server what it says.
		 *
		 * The file is read here and its text posted, rather than uploaded as a
		 * form: what the server needs is the text, and this keeps the size
		 * limit on both sides of the request.
		 *
		 * @param {Event} event the file input's change
		 */
		async read(event) {
			const file = /** @type {HTMLInputElement|null} */ (event?.target ?? null)?.files?.[0]
			this.preview = null
			this.fileError = ''
			if (!file) {
				return
			}

			if (file.size > MAX_BYTES) {
				this.fileError = t('social', 'That file is larger than 8 MB.')

				return
			}

			this.busy = true
			try {
				this.csv = await file.text()
				const { data } = await axios.post(moderationUrl('/fediverse/blocklist/preview'), { csv: this.csv })
				this.preview = data
				if (data.rejected?.length) {
					this.fileError = t('social', 'These rows name no server: {rows}', { rows: data.rejected.join(', ') })
					this.preview = null
				}
			} catch (error) {
				this.fileError = errorMessage(error, t('social', 'Could not read that file'))
			} finally {
				this.busy = false
			}
		},

		async apply() {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/blocklist/import'), { csv: this.csv })
				showSuccess(t('social', 'Blocked {blocked} servers and silenced {silenced}.', {
					blocked: data.blocked,
					silenced: data.silenced,
				}))
				this.preview = null
				this.csv = ''
				this.$emit('changed', data.list)
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not apply that list')))
			} finally {
				this.busy = false
			}
		},

		async loadSources() {
			try {
				const { data } = await axios.get(moderationUrl('/fediverse/blocklist/sources'))
				this.sources = data.sources ?? []
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not read the block list sources')))
			}
		},

		/**
		 * @param {object} source which one
		 * @param {boolean} enabled whether to follow it
		 */
		async toggle(source, enabled) {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/blocklist/sources'), {
					id: source.id,
					enabled,
				})
				this.sources = data.sources ?? []
				if (enabled) {
					// the source as the server has it now: the one handed in
					// is the row from before the switch, still marked off, and
					// checking it would only ask what it would do
					await this.check(this.sources.find((one) => one.id === source.id) ?? { ...source, enabled })
				}
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not change that source')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reads a source now. A source that is off is only asked what it would
		 * do, so an administrator can look before they follow it.
		 *
		 * @param {object} source which one
		 */
		async check(source) {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/fediverse/blocklist/fetch'), {
					id: source.id,
					dryRun: !source.enabled,
				})
				this.results = { ...this.results, [source.id]: data.result }
				this.sources = data.sources ?? this.sources
				this.$emit('changed', data.list)
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not read that list')))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} source which one
		 * @return {object|null} what it last did, from this page or from the job
		 */
		resultOf(source) {
			return this.results[source.id] ?? (Object.keys(source.lastResult ?? {}).length ? source.lastResult : null)
		},

		/**
		 * @param {object} result what a run did
		 * @return {string} it, in a sentence
		 */
		summaryOf(result) {
			if (result.dryRun) {
				return t('social', 'Would block {blocked} and silence {silenced} of {read} servers.', {
					blocked: result.blocked ?? 0,
					silenced: result.silenced ?? 0,
					read: result.read ?? 0,
				})
			}

			return t('social', 'Blocked {blocked} and silenced {silenced} of {read} servers.', {
				blocked: result.blocked ?? 0,
				silenced: result.silenced ?? 0,
				read: result.read ?? 0,
			})
		},

		/**
		 * @param {string} severity what the list said
		 * @return {string} what it means here
		 */
		severityLabel(severity) {
			return severity === 'silence' ? t('social', 'Silence') : t('social', 'Block')
		},
	},
}
</script>

<style scoped>
.blocklist__heading {
	margin: 1.25rem 0 0.35rem;
	font-size: 1rem;
	font-weight: 600;
}

.blocklist__file {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-block: 0.5rem;
}

.blocklist__input {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.blocklist__error {
	color: var(--color-error-text, var(--color-error));
}

.blocklist__preview {
	margin-block: 0.75rem;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.blocklist__rows {
	max-height: 14rem;
	margin: 0.5rem 0;
	padding: 0;
	list-style: none;
	overflow-y: auto;
}

.blocklist__rows li {
	display: flex;
	justify-content: space-between;
	gap: 1rem;
	padding: 0.15rem 0;
}

.blocklist__domain {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.blocklist__severity {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.blocklist__sources {
	margin: 0.5rem 0 0;
	padding: 0;
	list-style: none;
}

.blocklist__source {
	padding-block: 0.6rem;
	border-top: 1px solid var(--color-border);
}

.blocklist__source-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
}

.blocklist__source-url,
.blocklist__source-result {
	margin: 0.15rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.86rem;
	overflow-wrap: anywhere;
}

.blocklist__source-result--error {
	color: var(--color-error-text, var(--color-error));
}
</style>
