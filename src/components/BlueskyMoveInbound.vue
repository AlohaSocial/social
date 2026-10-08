<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="shown" class="migration__card migration__card--bluesky-inbound">
		<h4>
			<IconBridge :size="20" />
			{{ t('social', 'Bring your bridged Bluesky account here') }}
		</h4>

		<p v-if="move && move.state === 'done'" class="migration__bluesky-inbound-done">
			{{ t('social', 'Your Bluesky account now lives here, as {handle}.', { handle: identity.handle }) }}
		</p>

		<div v-else-if="move && (move.state === 'waiting' || move.state === 'running')" class="migration__bluesky-inbound-progress" aria-live="polite">
			<NcLoadingIcon :size="20" />
			<div>
				<p class="migration__bluesky-inbound-moving">
					{{ t('social', 'Bringing {handle} here', { handle: move.handle }) }}
				</p>
				<p class="migration__bluesky-inbound-step">
					{{ stepText }}
				</p>
				<dl v-if="tool" class="migration__bluesky-inbound-tool">
					<dt>{{ t('social', 'Server') }}</dt>
					<dd>{{ tool.pds }}</dd>
					<dt>{{ t('social', 'Handle') }}</dt>
					<dd>{{ tool.handle }}</dd>
					<dt>{{ t('social', 'E-mail address') }}</dt>
					<dd>{{ tool.email }}</dd>
					<dt>{{ t('social', 'Password and invite code') }}</dt>
					<dd><code>{{ tool.code }}</code></dd>
				</dl>
				<p v-if="tool" class="migration__bluesky-inbound-note">
					{{ t('social', 'The code is shown only now, works once, and only for this account.') }}
				</p>
				<NcButton v-if="move.state === 'waiting'" :disabled="busy" @click="cancel">
					{{ t('social', 'Call it off') }}
				</NcButton>
			</div>
		</div>

		<template v-else>
			<NcNoteCard v-if="move && move.state === 'failed'" type="error">
				<p>{{ t('social', 'The move stopped: {error}', { error: move.error }) }}</p>
			</NcNoteCard>

			<template v-if="twin">
				<p>
					{{ t('social', 'Bridgy Fed bridges your account to Bluesky as {handle}. That account can live here instead: its followers on Bluesky come along, and Bridgy Fed stops bridging you, since this server reaches Bluesky itself.', { handle: twin.handle }) }}
				</p>
				<div v-if="confirming === 'bridgy'" class="migration__bluesky-inbound-actions">
					<p class="migration__bluesky-inbound-confirm">
						{{ t('social', 'Your account sends Bridgy Fed a direct message asking it to move {twin} here. It replaces {handle}, the Bluesky account this server made for you: that one\'s posts and followers are not kept.', { twin: twin.handle, handle: identity.handle }) }}
					</p>
					<NcButton variant="error" :disabled="busy" @click="invite(twin.did)">
						<template v-if="busy" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Ask Bridgy Fed') }}
					</NcButton>
					<NcButton :disabled="busy" @click="confirming = ''">
						{{ t('social', 'Not now') }}
					</NcButton>
				</div>
				<div v-else class="migration__bluesky-inbound-actions">
					<NcButton :disabled="busy" @click="confirming = 'bridgy'">
						{{ t('social', 'Bring it here') }}
					</NcButton>
				</div>
			</template>
			<p v-else>
				{{ t('social', 'A Bluesky account that Bridgy Fed or a migration tool moves can live on this server. Name it, and the server answers what the tool needs.') }}
			</p>

			<details class="migration__bluesky-inbound-other" :open="!twin">
				<summary>{{ t('social', 'Another account, with a migration tool') }}</summary>
				<!-- the page may sit inside a form: Enter here asks to invite, never submits that -->
				<NcTextField
					v-model="account"
					class="migration__bluesky-inbound-field"
					:label="t('social', 'The Bluesky handle or DID to move here')"
					placeholder="alice.bsky.social"
					:disabled="busy"
					@keydown.enter.prevent="askToConfirm" />
				<div v-if="confirming === 'tool'" class="migration__bluesky-inbound-actions">
					<p class="migration__bluesky-inbound-confirm">
						{{ t('social', 'The account you move here replaces {handle}, the Bluesky account this server made for you: that one\'s posts and followers are not kept.', { handle: identity.handle }) }}
					</p>
					<NcButton variant="error" :disabled="busy" @click="invite(account.trim())">
						<template v-if="busy" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Invite it') }}
					</NcButton>
					<NcButton :disabled="busy" @click="confirming = ''">
						{{ t('social', 'Not now') }}
					</NcButton>
				</div>
				<div v-else class="migration__bluesky-inbound-actions">
					<NcButton :disabled="busy || account.trim() === ''" @click="askToConfirm">
						{{ t('social', 'Invite the account') }}
					</NcButton>
				</div>
			</details>
			<p v-if="inviteError" class="migration__bluesky-inbound-error" role="alert">
				{{ inviteError }}
			</p>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import { confirmPassword } from '../services/externalApi.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconBridge from 'vue-material-design-icons/Bridge.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/** How often a move under way is asked about, in milliseconds. */
const POLL_INTERVAL = 10000

/**
 * A Bluesky account moved here by the other side: the twin Bridgy Fed made
 * for the person's Fediverse account, which Bridgy is asked to move, or any
 * account a migration tool moves, with what the tool needs. Draws nothing
 * when Bluesky is off here.
 */
export default {
	name: 'BlueskyMoveInbound',

	components: {
		IconBridge,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			/** @type {object|null} the person's Bluesky identity here */
			identity: null,
			/** @type {{handle: string, did: string}|null} the account Bridgy Fed made for them */
			twin: null,
			/** @type {object|null} the latest move here by the other side */
			move: null,
			/** @type {object|null} what a migration tool needs, as the invitation answered it */
			tool: null,
			account: '',
			/** which confirmation is open: 'bridgy', 'tool' or none */
			confirming: '',
			busy: false,
			inviteError: '',
			/** @type {number|null} */
			pollTimer: null,
		}
	},

	computed: {
		/** @return {boolean} */
		shown() {
			return this.identity !== null
		},

		/** @return {string} where a move under way has got to, in words */
		stepText() {
			const progress = this.move?.progress ?? {}
			switch (this.move?.step) {
				case 'invited':
					return this.move.pds.includes('brid.gy')
						? t('social', 'Waiting for Bridgy Fed to start')
						: t('social', 'Waiting for the migration tool to start')
				case 'repo':
					return t('social', 'Waiting for your posts')
				case 'blobs':
					return t('social', '{records} posts arrived, {blobs} pictures and videos so far', { records: progress.records ?? 0, blobs: progress.blobs ?? 0 })
				case 'follows':
					return t('social', 'Following the accounts you follow')
				default:
					return ''
			}
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
				this.identity = data ?? null
			} catch (error) {
				logger.debug('Bluesky is not available here', { error })
				return
			}
			await Promise.all([this.loadTwin(), this.loadMove()])
		},

		/** @return {Promise<void>} */
		async loadTwin() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/bridgy-twin'))
				this.twin = data?.twin ?? null
			} catch (error) {
				logger.debug('Could not look for a Bridgy Fed twin', { error })
			}
		},

		/** @return {Promise<void>} */
		async loadMove() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/move'))
				const before = this.move?.state
				this.takeMove(data?.move)
				if (before !== undefined && before !== 'done' && this.move?.state === 'done') {
					// the account goes by its handle here now
					const identity = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
					this.identity = identity.data ?? this.identity
				}
			} catch (error) {
				logger.debug('Could not read the Bluesky move', { error })
				if (this.isUnderWay()) {
					this.schedulePoll()
				}
			}
		},

		/** @return {boolean} */
		isUnderWay() {
			return this.move?.state === 'waiting' || this.move?.state === 'running'
		},

		/**
		 * @param {object|null|undefined} move the move as the server answers it
		 */
		takeMove(move) {
			// the other moves have cards of their own
			this.move = move?.direction === 'inbound' ? move : null
			if (this.move?.step !== 'invited') {
				this.tool = null
			}
			if (this.isUnderWay()) {
				this.schedulePoll()
			} else {
				this.stopPolling()
			}
		},

		schedulePoll() {
			this.stopPolling()
			this.pollTimer = window.setTimeout(() => this.loadMove(), POLL_INTERVAL)
		},

		stopPolling() {
			window.clearTimeout(this.pollTimer)
			this.pollTimer = null
		},

		askToConfirm() {
			if (!this.busy && this.account.trim() !== '') {
				this.confirming = 'tool'
			}
		},

		/**
		 * Invites an account to move here, after the Nextcloud password.
		 *
		 * @param {string} account a handle or DID
		 * @return {Promise<void>}
		 */
		async invite(account) {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.busy = true
			this.inviteError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-invite'), { account })
				this.confirming = ''
				this.account = ''
				this.takeMove(data?.move)
				this.tool = data?.bridgy || !data?.code ? null : { pds: data.pds, handle: data.handle, email: data.email, code: data.code }
			} catch (error) {
				const status = error?.response?.status
				if (status === 422) {
					this.inviteError = error.response.data?.error || t('social', 'Could not invite the account')
					this.confirming = ''
				} else {
					showError(status === 403
						? t('social', 'Confirm your password again and retry.')
						: t('social', 'Could not invite the account'))
				}
			} finally {
				this.busy = false
			}
		},

		/** @return {Promise<void>} */
		async cancel() {
			this.busy = true
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/move-invite'))
				this.tool = null
				this.takeMove(data?.move)
			} catch (error) {
				showError(error?.response?.data?.error || t('social', 'Could not call the move off'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration__card--bluesky-inbound {
	h4 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	p {
		margin-bottom: 12px;
	}
}

.migration__bluesky-inbound-field {
	max-width: 420px;
	margin-top: 8px;
}

.migration__bluesky-inbound-note {
	color: var(--color-text-maxcontrast);
}

.migration__bluesky-inbound-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 12px;

	.migration__bluesky-inbound-confirm {
		flex-basis: 100%;
		margin-bottom: 0;
	}
}

.migration__bluesky-inbound-other {
	margin-top: 16px;

	summary {
		cursor: pointer;
		font-weight: 600;
	}
}

.migration__bluesky-inbound-progress {
	display: flex;
	align-items: flex-start;
	gap: 12px;

	.migration__bluesky-inbound-moving {
		margin-bottom: 4px;
		font-weight: 600;
	}

	.migration__bluesky-inbound-step {
		color: var(--color-text-maxcontrast);
	}
}

.migration__bluesky-inbound-tool {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin-bottom: 8px;

	dt {
		color: var(--color-text-maxcontrast);
	}

	dd {
		overflow-wrap: anywhere;
	}
}

.migration__bluesky-inbound-done {
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.migration__bluesky-inbound-error {
	color: var(--color-error-text, var(--color-error));
}
</style>
