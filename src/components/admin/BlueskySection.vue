<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p class="social-admin__hint">
			{{ t('social', 'Every account on this server can also be a Bluesky account: the same person, the same posts, reachable as username.{host} from any Bluesky app. Every public post written here is also published there; nothing else is. Switching this on makes this server a Bluesky data server, which is what the requirements beside the switch are about.', { host: current.status.handle_host || '…' }) }}
		</p>
		<NcCheckboxRadioSwitch
			:modelValue="form.enabled"
			type="switch"
			:disabled="saving"
			@update:modelValue="toggle">
			{{ t('social', 'Give every account here a Bluesky identity') }}
		</NcCheckboxRadioSwitch>

		<NcNoteCard v-if="message !== ''" :type="saved ? 'success' : 'error'">
			{{ message }}
		</NcNoteCard>

		<!-- the switch refuses while one of these fails, so they are beside
		     it rather than on a page of their own -->
		<div class="bluesky__checks">
			<h4 class="bluesky__title">
				{{ t('social', 'What it needs') }}
			</h4>
			<p v-if="current.checks.length === 0" class="social-admin__hint">
				{{ t('social', 'Nothing has been checked yet. The switch runs the checks, and so does Re-check.') }}
			</p>
			<ul v-else class="bluesky__check-list">
				<li
					v-for="check in current.checks"
					:key="check.id"
					class="bluesky__check"
					:class="'bluesky__check--' + check.state">
					<CheckCircleOutline v-if="check.state === 'ok'" :size="18" :title="t('social', 'Fine')" />
					<AlertOutline v-else-if="check.state === 'warning'" :size="18" :title="t('social', 'Worth a look')" />
					<CloseCircleOutline v-else :size="18" :title="t('social', 'Failing')" />
					<span class="bluesky__check-name">{{ checkName(check.id) }}</span>
					<span v-if="check.detail" class="bluesky__check-detail">{{ check.detail }}</span>
				</li>
			</ul>
			<NcButton :disabled="checking" @click="recheck">
				<template v-if="checking" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Re-check') }}
			</NcButton>
		</div>

		<dl class="bluesky__facts">
			<div class="bluesky__fact">
				<dt>{{ t('social', 'Handle host') }}</dt>
				<dd><code>{{ current.status.handle_host || '—' }}</code></dd>
			</div>
			<div class="bluesky__fact">
				<dt>{{ t('social', 'PDS endpoint') }}</dt>
				<dd><code>{{ current.status.pds_endpoint || '—' }}</code></dd>
			</div>
			<div class="bluesky__fact">
				<dt>{{ t('social', 'Service DID') }}</dt>
				<dd><code>{{ current.status.service_did || '—' }}</code></dd>
			</div>
		</dl>

		<div class="bluesky__form">
			<NcTextArea
				v-model="form.relays"
				class="bluesky__field"
				:label="t('social', 'Relays')"
				placeholder="https://bsky.network"
				:helperText="t('social', 'One address per line. A relay is what carries this server\'s posts to the Bluesky apps; without one, a post written here is published and seen by nobody.')"
				rows="3" />
			<div class="bluesky__crawl">
				<NcButton :disabled="crawling || relaysChanged" @click="crawl">
					<template v-if="crawling" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Tell the relays now') }}
				</NcButton>
				<p v-if="relaysChanged" class="social-admin__hint">
					{{ t('social', 'Save the list first; the relays told are the ones saved.') }}
				</p>
			</div>
			<ul v-if="crawlResults" class="bluesky__crawl-results">
				<li v-for="(result, relay) in crawlResults" :key="relay" :class="{ 'bluesky__crawl-result--refused': result !== 'ok' }">
					<span class="bluesky__crawl-relay">{{ relay }}</span>
					<span>{{ result === 'ok' ? t('social', 'told') : result }}</span>
				</li>
			</ul>

			<NcTextField
				v-model="form.plcDirectory"
				class="bluesky__field"
				:label="t('social', 'PLC directory')"
				placeholder="https://plc.directory"
				:helperText="t('social', 'Where the identities are registered. The public directory, unless this is a development setup with one of its own.')" />
			<NcTextField
				v-model="form.appview"
				class="bluesky__field"
				:label="t('social', 'AppView')"
				placeholder="https://public.api.bsky.app"
				:helperText="t('social', 'Where Bluesky accounts and their posts are read from.')" />
			<NcTextField
				v-model="form.jetstream"
				class="bluesky__field"
				:label="t('social', 'Jetstream')"
				placeholder="wss://jetstream2.us-east.bsky.network"
				:helperText="t('social', 'Optional. With occ social:atproto:listen running, a Jetstream endpoint brings posts from followed Bluesky accounts within seconds instead of on the next poll.')" />
			<NcTextField
				v-model="form.chat"
				class="bluesky__field"
				:label="t('social', 'Direct messages')"
				placeholder="https://api.bsky.chat"
				:helperText="t('social', 'Bluesky\'s chat service, where direct messages with people on Bluesky are kept: they arrive in Messages here, and a Bluesky app signed in here with an app password that allows them reaches them too. Empty turns direct messages with Bluesky off.')" />
			<NcTextField
				v-model="form.syncCeiling"
				class="bluesky__number"
				type="number"
				min="1"
				max="100000"
				:label="t('social', 'Sync ceiling')"
				:helperText="t('social', 'How many AppView requests one polling pass may make. Every followed Bluesky account is asked for new posts in turn; the ceiling bounds what a pass costs.')" />

			<NcTextArea
				v-model="form.trustedClients"
				class="bluesky__field"
				:label="t('social', 'Trusted Bluesky apps')"
				placeholder="https://example.app/oauth-client-metadata.json"
				:helperText="t('social', 'Optional. One client ID per line. When one of these apps asks to sign in, the consent page shows its own name and logo; for any other app it shows only the address it is known by, because an app can call itself anything.')" />

			<div class="bluesky__actions">
				<NcButton variant="primary" :disabled="saving || !changed" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Save') }}
				</NcButton>
			</div>
		</div>

		<h4 class="bluesky__title">
			{{ t('social', 'Blocked on Bluesky') }}
		</h4>
		<p class="social-admin__hint">
			{{ t('social', 'An account by its DID, or a whole data server by its host. Nothing from it is read or stored here, and the people here who followed it stop following it.') }}
		</p>
		<p v-if="blocks.length === 0" class="social-admin__hint">
			{{ t('social', 'Nothing is blocked.') }}
		</p>
		<ul v-else class="bluesky__blocks">
			<li v-for="block in blocks" :key="block.kind + ' ' + block.value" class="bluesky__block">
				<span class="bluesky__block-kind">{{ block.kind === 'did' ? t('social', 'Account') : t('social', 'Data server') }}</span>
				<code class="bluesky__block-value">{{ block.value }}</code>
				<span v-if="block.reason" class="bluesky__block-reason">{{ block.reason }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					:disabled="blocking"
					@click="removeBlock(block)">
					{{ t('social', 'Remove') }}
				</NcButton>
			</li>
		</ul>
		<form class="bluesky__block-form" @submit.prevent="addBlock">
			<NcTextField
				v-model="blockTarget"
				class="bluesky__field"
				:label="t('social', 'DID or host to block')"
				placeholder="did:plc:… / pds.example.com"
				:disabled="blocking" />
			<NcTextField
				v-model="blockReason"
				class="bluesky__field"
				:label="t('social', 'Reason (optional)')"
				:disabled="blocking" />
			<div class="bluesky__actions">
				<NcButton type="submit" :disabled="blocking || blockTarget.trim() === ''">
					<template v-if="blocking" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Block') }}
				</NcButton>
			</div>
		</form>
		<p v-if="blockError !== ''" class="bluesky__block-error" role="alert">
			{{ blockError }}
		</p>
		<p v-else-if="purged > 0" class="social-admin__hint">
			{{ n('social', '%n followed account removed', '%n followed accounts removed', purged) }}
		</p>

		<h4 class="bluesky__title">
			{{ t('social', 'How it is doing') }}
		</h4>
		<dl class="bluesky__numbers">
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Identities') }}</dt>
				<dd>{{ current.status.identities }}</dd>
			</div>
			<!-- of those, the people who switched their presence there off -->
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Switched off by their owners') }}</dt>
				<dd>{{ current.status.identities_off ?? 0 }}</dd>
			</div>
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Repositories') }}</dt>
				<dd>{{ current.status.repositories }}</dd>
			</div>
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Events in the window') }}</dt>
				<dd>{{ current.status.events_in_window }}</dd>
			</div>
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Head sequence') }}</dt>
				<dd>{{ current.status.head_seq }}</dd>
			</div>
			<div class="bluesky__number-cell">
				<dt>{{ t('social', 'Rotation key age') }}</dt>
				<dd>{{ n('social', '%n day', '%n days', current.status.rotation_key_age) }}</dd>
			</div>
			<div class="bluesky__number-cell bluesky__number-cell--wide">
				<dt>{{ t('social', 'Firehose daemon') }}</dt>
				<dd :class="{ 'bluesky__daemon--down': !daemonRunning }">
					{{ daemonLabel }}
				</dd>
			</div>
		</dl>

		<!-- the other direction: what this server reads from Bluesky for the
		     people here, and how far behind the slowest of it is -->
		<template v-if="reading">
			<h4 class="bluesky__title">
				{{ t('social', 'Reading Bluesky') }}
			</h4>
			<dl class="bluesky__numbers">
				<div class="bluesky__number-cell">
					<dt>{{ t('social', 'Authors followed') }}</dt>
					<dd>{{ reading.watches }}</dd>
				</div>
				<div class="bluesky__number-cell">
					<dt>{{ t('social', 'Their posts') }}</dt>
					<dd>{{ lagLabel(reading.lag) }}</dd>
				</div>
				<div class="bluesky__number-cell">
					<dt>{{ t('social', 'Accounts asking for notifications') }}</dt>
					<dd>{{ reading.accounts }}</dd>
				</div>
				<div class="bluesky__number-cell">
					<dt>{{ t('social', 'Their notifications') }}</dt>
					<dd>{{ lagLabel(reading.lag_notifications) }}</dd>
				</div>
				<div v-if="current.settings.jetstream" class="bluesky__number-cell bluesky__number-cell--wide">
					<dt>{{ t('social', 'Jetstream listener') }}</dt>
					<dd :class="{ 'bluesky__daemon--down': !listener?.connected }">
						{{ listenerLabel }}
					</dd>
				</div>
			</dl>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import { blueskyUrl, errorMessage } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * @typedef {object} BlueskyAdmin what `AtprotoStatusService::current()` answers
 * @property {{enabled: boolean, relays: string[], plc_directory: string, appview: string, jetstream: string, chat: string, sync_ceiling: number, trusted_clients: string[]}} settings - what is set, in the app values' names
 * @property {{handle_host: string, pds_endpoint: string, service_did: string, identities: number, identities_off?: number, repositories: number, events_in_window: number, head_seq: number, rotation_key_age: number, daemon: {running: boolean, pid: number, started: number, seen: number, head: number, subscribers: number}|null, listener?: {running: boolean, connected: boolean, started: number, seen: number, last_event: number, cursor: number, accounts: number}|null, reading?: {watches: number, lag: number, accounts: number, lag_notifications: number}, blocks?: BlueskyBlock[]}} status - the facts and the numbers; the key age in days, the daemon's times in seconds since the epoch, the reading lags in seconds
 * @property {Array<{id: string, state: 'ok'|'warning'|'error', detail: string}>} checks - the requirements of §14.1, empty while nothing has been checked
 */

/**
 * @typedef {object} BlueskyBlock a DID or PDS host this server will not talk to
 * @property {'did'|'host'} kind - which of the two
 * @property {string} value - the DID or the host
 * @property {string} reason - what the administrator noted, '' for nothing
 * @property {number} creation - when, in seconds since the epoch
 */

/**
 * @param {BlueskyAdmin['settings']} settings what the server holds
 * @return {object} the form, as strings a field can bind to
 */
function formOf(settings) {
	return {
		enabled: settings.enabled === true,
		relays: (settings.relays ?? []).join('\n'),
		plcDirectory: settings.plc_directory ?? '',
		appview: settings.appview ?? '',
		jetstream: settings.jetstream ?? '',
		chat: settings.chat ?? '',
		syncCeiling: String(settings.sync_ceiling ?? 200),
		trustedClients: (settings.trusted_clients ?? []).join('\n'),
	}
}

/**
 * @param {string} text the textarea, one address per line
 * @return {string[]} the addresses, blank lines gone
 */
function relaysOf(text) {
	return text.split('\n').map((line) => line.trim()).filter((line) => line !== '')
}

/**
 * The Bluesky side of this server: whether it is one, what it needs to be
 * one, and how it is doing.
 *
 * Only the switch saves by itself. It is the one field whose answer can be
 * "no" — the server refuses to switch on while a requirement fails, and
 * answers with the checks that say which — so it is pressed and answered on
 * its own rather than folded into a Save that could half succeed. The other
 * fields go together, and only the ones that changed are sent.
 */
export default {
	name: 'BlueskySection',

	components: {
		AlertOutline,
		CheckCircleOutline,
		CloseCircleOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** `AtprotoStatusService::current()`, as initial state */
		settings: {
			type: /** @type {import('vue').PropType<BlueskyAdmin>} */ (Object),
			required: true,
		},
	},

	data() {
		return {
			/** @type {BlueskyAdmin} what the server last said */
			current: this.settings,
			form: formOf(this.settings.settings),
			message: '',
			saved: false,
			saving: false,
			checking: false,
			crawling: false,
			/** @type {Record<string, string>|null} what each relay answered, after Tell the relays */
			crawlResults: null,
			blockTarget: '',
			blockReason: '',
			blocking: false,
			blockError: '',
			/** how many follows the last block took away */
			purged: 0,
		}
	},

	computed: {
		/**
		 * The request as it would be sent now: the fields whose draft differs
		 * from what the server holds, in the route's own names.
		 *
		 * @return {object}
		 */
		payload() {
			const stored = this.current.settings
			const changes = {}
			const relays = relaysOf(this.form.relays)
			if (relays.join('\n') !== (stored.relays ?? []).join('\n')) {
				changes.relays = relays
			}
			for (const [field, key] of [['plcDirectory', 'plc_directory'], ['appview', 'appview'], ['jetstream', 'jetstream'], ['chat', 'chat']]) {
				if (this.form[field].trim() !== (stored[key] ?? '')) {
					changes[key] = this.form[field].trim()
				}
			}
			const ceiling = parseInt(this.form.syncCeiling, 10)
			if (!Number.isNaN(ceiling) && ceiling !== stored.sync_ceiling) {
				changes.sync_ceiling = ceiling
			}
			const trusted = relaysOf(this.form.trustedClients)
			if (trusted.join('\n') !== (stored.trusted_clients ?? []).join('\n')) {
				changes.trusted_clients = trusted
			}

			return changes
		},

		/** @return {boolean} whether there is anything worth sending */
		changed() {
			return Object.keys(this.payload).length > 0
		},

		/** @return {boolean} whether the relay list on the page is not the one saved */
		relaysChanged() {
			return 'relays' in this.payload
		},

		/** @return {{watches: number, lag: number, accounts: number, lag_notifications: number}|null} the reading side, on a server that reports it */
		reading() {
			return this.current.status.reading ?? null
		},

		/** @return {BlueskyBlock[]} the DIDs and hosts blocked, as the server last listed them */
		blocks() {
			return this.current.status.blocks ?? []
		},

		/** @return {boolean} */
		daemonRunning() {
			return this.current.status.daemon?.running === true
		},

		/** @return {{running: boolean, connected: boolean, started: number, seen: number, last_event: number, cursor: number, accounts: number}|null} */
		listener() {
			return this.current.status.listener ?? null
		},

		/** @return {string} the listener's state in one line */
		listenerLabel() {
			const listener = this.listener
			if (!listener?.running) {
				return t('social', 'Not running: start occ social:atproto:listen')
			}
			if (!listener.connected) {
				return t('social', 'Running, not connected to Jetstream')
			}
			const accounts = n('social', '%n account', '%n accounts', listener.accounts)
			if (listener.last_event === 0) {
				return t('social', 'Connected for {accounts}, nothing heard yet', { accounts })
			}

			return t('social', 'Connected for {accounts}, last heard {ago} ago', {
				accounts,
				ago: this.duration(Math.max(0, Math.floor(Date.now() / 1000) - listener.last_event)),
			})
		},

		/** @return {string} the daemon's state in one line */
		daemonLabel() {
			const daemon = this.current.status.daemon
			if (!daemon?.running) {
				return t('social', 'Not running')
			}
			const uptime = Math.max(0, Math.floor(Date.now() / 1000) - daemon.started)

			return t('social', 'Running as pid {pid} for {uptime}, serving {subscribers}', {
				pid: daemon.pid,
				uptime: this.duration(uptime),
				subscribers: n('social', '%n subscriber', '%n subscribers', daemon.subscribers),
			})
		},
	},

	watch: {
		settings(settings) {
			this.apply(settings)
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {string} id a check's id, as the status service names it
		 * @return {string} what it is about, in words
		 */
		checkName(id) {
			switch (id) {
				case 'https':
					return t('social', 'HTTPS')
				case 'trusted_domains':
					return t('social', 'Wildcard trusted domain')
				case 'xrpc_root':
					return t('social', 'XRPC at the root')
				case 'handle_host':
					return t('social', 'Handle hosts resolve')
				case 'daemon':
					return t('social', 'Firehose daemon')
				case 'firehose':
					return t('social', 'Firehose proxied')
				case 'relay_host':
					return t('social', 'Relays carry this server')
				default:
					return id
			}
		},

		/**
		 * @param {number} seconds a length of time
		 * @return {string} it in the largest unit that still says something
		 */
		duration(seconds) {
			if (seconds >= 86400) {
				return n('social', '%n day', '%n days', Math.round(seconds / 86400))
			}
			if (seconds >= 3600) {
				return n('social', '%n hour', '%n hours', Math.round(seconds / 3600))
			}
			if (seconds >= 60) {
				return n('social', '%n minute', '%n minutes', Math.round(seconds / 60))
			}

			return n('social', '%n second', '%n seconds', seconds)
		},

		/**
		 * @param {number} seconds how far behind a reader is
		 * @return {string} that, or that there is nothing to catch up on
		 */
		lagLabel(seconds) {
			if (!(seconds > 0)) {
				return t('social', 'up to date')
			}

			return t('social', '{duration} behind', { duration: this.duration(seconds) })
		},

		/**
		 * Takes what the server answered as the truth the form starts from.
		 *
		 * @param {BlueskyAdmin} data the whole object, as every route answers it
		 */
		apply(data) {
			this.current = data
			this.form = formOf(data.settings)
		},

		/**
		 * @param {object} fields the settings to write, in the route's names
		 * @return {Promise<boolean>} whether they were taken
		 */
		async write(fields) {
			this.saving = true
			try {
				const { data } = await axios.post(blueskyUrl(), fields)
				this.apply(data)
				this.saved = true
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'The Bluesky settings were saved'))

				return true
			} catch (error) {
				// a refusal carries the same object as a success, with the
				// checks that say why; the page follows them either way
				if (error?.response?.data?.settings) {
					this.apply(error.response.data)
				}
				this.saved = false
				this.message = errorMessage(error, t('social', 'Could not save the Bluesky settings'))
				showError(this.message)

				return false
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {boolean} enabled where the switch was pressed to
		 * @return {Promise<void>}
		 */
		async toggle(enabled) {
			this.form.enabled = enabled
			if (!await this.write({ enabled })) {
				this.form.enabled = this.current.settings.enabled
			}
		},

		/** @return {Promise<void>} */
		async save() {
			if (!this.changed || this.saving) {
				return
			}
			await this.write(this.payload)
		},

		/** @return {Promise<void>} */
		async recheck() {
			this.checking = true
			try {
				const { data } = await axios.get(blueskyUrl())
				this.apply(data)
			} catch {
				showError(t('social', 'Could not run the checks'))
			} finally {
				this.checking = false
			}
		},

		/**
		 * Puts the block list the server answered in place of the one shown.
		 *
		 * @param {BlueskyBlock[]|undefined} blocks what a block route answered
		 */
		applyBlocks(blocks) {
			this.current = { ...this.current, status: { ...this.current.status, blocks: blocks ?? [] } }
		},

		/** @return {Promise<void>} */
		async addBlock() {
			const target = this.blockTarget.trim()
			if (target === '' || this.blocking) {
				return
			}
			this.blocking = true
			this.blockError = ''
			this.purged = 0
			try {
				const { data } = await axios.post(blueskyUrl('/blocks'), { target, reason: this.blockReason.trim() })
				this.applyBlocks(data?.blocks)
				this.purged = Number(data?.purged) || 0
				this.blockTarget = ''
				this.blockReason = ''
			} catch (error) {
				this.blockError = errorMessage(error, t('social', 'Could not block that'))
			} finally {
				this.blocking = false
			}
		},

		/**
		 * @param {BlueskyBlock} block the entry to take off the list
		 * @return {Promise<void>}
		 */
		async removeBlock(block) {
			this.blocking = true
			this.blockError = ''
			this.purged = 0
			try {
				const { data } = await axios.delete(blueskyUrl('/blocks'), { data: { target: block.value } })
				this.applyBlocks(data?.blocks)
			} catch (error) {
				this.blockError = errorMessage(error, t('social', 'Could not remove that block'))
			} finally {
				this.blocking = false
			}
		},

		/** @return {Promise<void>} */
		async crawl() {
			this.crawling = true
			this.crawlResults = null
			try {
				const { data } = await axios.post(blueskyUrl('/crawl'))
				this.crawlResults = data?.results ?? {}
			} catch (error) {
				showError(errorMessage(error, t('social', 'Could not reach the relays')))
			} finally {
				this.crawling = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bluesky__title {
	margin: calc(var(--default-grid-baseline) * 4) 0 calc(var(--default-grid-baseline) * 2);
	font-size: 15px;
	font-weight: bold;
}

.bluesky__check-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}

.bluesky__check {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	line-height: 1.4;

	&--ok {
		color: var(--color-success);
	}

	&--warning {
		color: var(--color-warning);
	}

	&--error {
		color: var(--color-error);
	}
}

.bluesky__check-name {
	flex: none;
	color: var(--color-main-text);
}

.bluesky__check-detail {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.bluesky__facts,
.bluesky__numbers {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin-block: calc(var(--default-grid-baseline) * 3) 0;

	dt {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	dd {
		margin: 0;
		overflow-wrap: anywhere;
	}
}

.bluesky__numbers {
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
}

.bluesky__number-cell--wide {
	grid-column: 1 / -1;
}

.bluesky__daemon--down {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.bluesky__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block-start: calc(var(--default-grid-baseline) * 3);
}

.bluesky__field {
	max-width: 520px;
}

.bluesky__number {
	max-width: 240px;
}

.bluesky__crawl {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.bluesky__crawl-results {
	display: flex;
	flex-direction: column;
	gap: 4px;
	color: var(--color-text-maxcontrast);

	li {
		display: flex;
		gap: 8px;
		flex-wrap: wrap;
	}
}

.bluesky__crawl-relay {
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.bluesky__crawl-result--refused {
	color: var(--color-error-text, var(--color-error));
}

.bluesky__actions {
	display: flex;
	justify-content: flex-end;
}

.bluesky__blocks {
	display: flex;
	flex-direction: column;
	margin-block-end: calc(var(--default-grid-baseline) * 2);
}

.bluesky__block {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 4px 12px;
	padding-block: 4px;

	& + & {
		border-top: 1px solid var(--color-border);
	}
}

.bluesky__block-kind {
	flex: none;
	min-width: 96px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.bluesky__block-value {
	overflow-wrap: anywhere;
}

.bluesky__block-reason {
	flex: 1 1 160px;
	color: var(--color-text-maxcontrast);
}

.bluesky__block-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.bluesky__block-error {
	color: var(--color-error-text, var(--color-error));
}
</style>
