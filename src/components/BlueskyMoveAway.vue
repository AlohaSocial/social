<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="shown" class="migration__card migration__card--bluesky">
		<h4>
			<IconAccountArrowRight :size="20" />
			{{ t('social', 'Move your Bluesky account away') }}
		</h4>
		<p>
			{{ t('social', 'Your Bluesky account can live on another server (a PDS). Your followers, posts and handle history move with it; your Fediverse account here stays as it is. Afterwards this server no longer publishes to Bluesky for you.') }}
		</p>

		<template v-if="move && move.state === 'done'">
			<p class="migration__bluesky-done">
				{{ t('social', 'Your Bluesky account now lives on {pds} as {handle}.', { pds: move.pds, handle: move.handle }) }}
			</p>
			<p class="migration__bluesky-control">
				{{ hasRecoveryKey
					? t('social', 'You, with your recovery phrase, and {host} control it now. This server no longer does.', { host: newHost || hostOf(move.pds) })
					: t('social', 'Only {host} controls it now. This server no longer does.', { host: newHost || hostOf(move.pds) }) }}
			</p>
		</template>

		<NcNoteCard v-else-if="move && move.state === 'needs_decision'" type="warning" class="migration__bluesky-decision">
			<p>
				{{ move.progress.code === 'blobs-missing'
					? n('social', '{n} photo or video did not arrive on {pds}. Your account still points here.', '{n} photos or videos did not arrive on {pds}. Your account still points here.', move.progress.missing, { n: move.progress.missing, pds: move.pds })
					: t('social', 'Your posts did not all arrive on {pds}. Your account still points here.', { pds: move.pds }) }}
			</p>
			<ServerError v-if="actionError" :error="actionError" />
			<div class="migration__bluesky-actions">
				<NcButton :disabled="busy" @click="decide(false)">
					{{ t('social', 'Copy again') }}
				</NcButton>
				<NcButton v-if="move.progress.code === 'blobs-missing'" :disabled="busy" @click="confirmingWithout = true">
					{{ t('social', 'Switch without them') }}
				</NcButton>
				<NcButton :disabled="busy" @click="callOff">
					{{ t('social', 'Call it off') }}
				</NcButton>
			</div>
			<ConfirmDialog
				v-if="confirmingWithout"
				:open="true"
				:name="t('social', 'Switch without them?')"
				:message="n('social', '{n} photo or video did not arrive on {pds}. Your account is pointed at {pds} without it: the posts that show it lose it there, and it is not copied later.', '{n} photos or videos did not arrive on {pds}. Your account is pointed at {pds} without them: the posts that show them lose them there, and they are not copied later.', move.progress.missing, { n: move.progress.missing, pds: move.pds })"
				:confirmLabel="t('social', 'Switch without them')"
				@update:open="confirmingWithout = false"
				@confirm="decide(true)">
				<BlueskyMissingRecords :records="move.progress.missing_records ?? []" />
			</ConfirmDialog>
		</NcNoteCard>

		<div v-else-if="move && move.state === 'running'" class="migration__bluesky-progress" aria-live="polite">
			<NcLoadingIcon :size="20" />
			<div>
				<p class="migration__bluesky-moving">
					{{ t('social', 'Moving to {pds} …', { pds: move.pds }) }}
				</p>
				<p class="migration__bluesky-step">
					{{ stepText }}
				</p>
				<NcButton v-if="canCallOff" :disabled="busy" @click="callOff">
					{{ t('social', 'Call it off') }}
				</NcButton>
			</div>
		</div>

		<template v-else>
			<NcNoteCard v-if="move && move.state === 'failed'" type="error" class="migration__bluesky-failed">
				<p>{{ t('social', 'The move stopped.') }}</p>
				<ServerError :error="stoppedError" :alert="false" />
				<ServerError v-if="retryError" :error="retryError" />
				<NcButton :disabled="busy" @click="retry">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Try again') }}
				</NcButton>
				<NcButton v-if="canCallOff" :disabled="busy" @click="callOff">
					{{ t('social', 'Call it off') }}
				</NcButton>
			</NcNoteCard>

			<NcNoteCard v-if="move && move.state === 'cancelled'" type="info" class="migration__bluesky-cancelled">
				<p>{{ t('social', 'The move was called off. The account made on {host} is still there, switched off: delete it there if you do not want it.', { host: hostOf(move.pds) }) }}</p>
			</NcNoteCard>

			<template v-if="identityActive">
				<!-- the page may sit inside a form: Enter here asks to move, never submits that -->
				<div class="migration__bluesky-form">
					<NcTextField
						v-model="pds"
						class="migration__bluesky-field"
						:label="t('social', 'Server to move to')"
						placeholder="bsky.social"
						:disabled="busy"
						@keydown.enter.prevent="askToConfirm" />
					<NcTextField
						v-model="handle"
						class="migration__bluesky-field"
						:label="t('social', 'Your handle there')"
						placeholder="alice.bsky.social"
						:disabled="busy"
						@keydown.enter.prevent="askToConfirm" />
					<NcTextField
						v-model="email"
						class="migration__bluesky-field"
						type="email"
						:label="t('social', 'E-mail address for the account there')"
						:disabled="busy"
						@keydown.enter.prevent="askToConfirm" />
					<NcTextField
						v-model="password"
						class="migration__bluesky-field"
						type="password"
						autocomplete="new-password"
						:label="t('social', 'Password for the account there')"
						:disabled="busy"
						@keydown.enter.prevent="askToConfirm" />
					<NcTextField
						v-model="inviteCode"
						class="migration__bluesky-field"
						:label="t('social', 'Invite code, if the server needs one')"
						:disabled="busy"
						@keydown.enter.prevent="askToConfirm" />
				</div>
				<NcCheckboxRadioSwitch v-model="announce" class="migration__bluesky-announce" :disabled="busy">
					{{ t('social', 'Afterwards, post where my Bluesky account went') }}
				</NcCheckboxRadioSwitch>
				<ServerError v-if="startError" :error="startError" />
				<div v-if="confirming" class="migration__bluesky-actions">
					<p class="migration__bluesky-confirm">
						{{ t('social', 'Your Bluesky account is moved to {server}. This cannot be undone from here.', { server: pds.trim() }) }}
					</p>
					<p v-if="!hasRecoveryKey" class="migration__bluesky-confirm migration__bluesky-no-recovery">
						{{ t('social', 'You have no recovery phrase: afterwards only {server} controls your Bluesky account.', { server: pds.trim() }) }}
					</p>
					<NcButton variant="error" :disabled="busy" @click="start">
						<template v-if="busy" #icon>
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('social', 'Move it') }}
					</NcButton>
					<NcButton :disabled="busy" @click="confirming = false">
						{{ t('social', 'Not now') }}
					</NcButton>
				</div>
				<div v-else class="migration__bluesky-actions">
					<NcButton :disabled="!ready" @click="askToConfirm">
						{{ t('social', 'Move my Bluesky account') }}
					</NcButton>
				</div>
			</template>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import { confirmPassword } from '../services/externalApi.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import { n, t } from '@nextcloud/l10n'
import ServerError from './ServerError.vue'
import BlueskyMissingRecords from './BlueskyMissingRecords.vue'
import ConfirmDialog from './admin/ConfirmDialog.vue'
import logger from '../services/logger.js'
import { errorOf, stoppedMoveError } from '../utils/serverError.js'

/** How often a running move is asked about, in milliseconds. */
const POLL_INTERVAL = 5000

/**
 * Moving the person's Bluesky account from this server's PDS to another
 * one: the form, and where a move has got to. Draws nothing when the server
 * has no Bluesky identity for the person.
 */
export default {
	name: 'BlueskyMoveAway',

	components: {
		ServerError,
		BlueskyMissingRecords,
		ConfirmDialog,
		IconAccountArrowRight,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			/** @type {object|null} the Bluesky identity, or null when there is none */
			identity: null,
			/** @type {object|null} the latest move, as the server keeps it */
			move: null,
			pds: '',
			handle: '',
			email: '',
			password: '',
			inviteCode: '',
			/** whether a post says where the account went once it moved */
			announce: false,
			/** @type {string|null} the new server's host once a move away is done */
			newHost: null,
			confirming: false,
			/** whether switching without what did not arrive is being asked about */
			confirmingWithout: false,
			busy: false,
			/** @type {{text: string, details: string}|null} why the server would not start the move */
			startError: null,
			/** @type {{text: string, details: string}|null} why the server would not start it again */
			retryError: null,
			/** @type {{text: string, details: string}|null} why the server would not go on or call it off */
			actionError: null,
			/** @type {number|null} the timer behind the next poll, while a move runs */
			pollTimer: null,
		}
	},

	computed: {
		/** @return {boolean} */
		identityActive() {
			return this.identity?.state === 'active'
		},

		/** @return {boolean} whether the person holds a recovery key of the account */
		hasRecoveryKey() {
			return this.identity?.recovery_key === true
		},

		/** @return {boolean} whether the move can still be called off: the account has not been pointed at the new server */
		canCallOff() {
			return ['account_created', 'repo_copied'].includes(this.move?.progress?.stage)
				&& !(this.move?.state === 'running' && this.move?.step === 'identity')
		},

		/** @return {{text: string, details: string}} why the move stopped */
		stoppedError() {
			return stoppedMoveError(this.move)
		},

		/** @return {boolean} whether there is anything to show */
		shown() {
			return this.identity !== null && (this.identityActive || this.move !== null)
		},

		/** @return {boolean} whether everything the other server needs is filled in */
		ready() {
			return !this.busy
				&& this.pds.trim() !== ''
				&& this.handle.trim() !== ''
				&& this.email.trim() !== ''
				&& this.password !== ''
		},

		/** @return {string} the step a running move is on, in words */
		stepText() {
			const blobs = this.move?.progress?.blobs
			switch (this.move?.step) {
				case 'repo':
					return t('social', 'Copying your posts')
				case 'blobs':
					return typeof blobs === 'number'
						? t('social', 'Copying your photos and videos ({n} so far)', { n: blobs })
						: t('social', 'Copying your photos and videos')
				case 'prefs':
					return t('social', 'Copying your settings')
				case 'check':
					return t('social', 'Checking that everything arrived')
				case 'identity':
					return t('social', 'Pointing your account at the new server')
				case 'activate':
					return t('social', 'Switching your account on there')
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
		n,
		t,

		/**
		 * The identity first: without one, or with Bluesky off, there is no
		 * move to ask about either.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
				this.identity = data ?? null
			} catch (error) {
				logger.debug('No Bluesky identity to move', { error })
				return
			}
			await this.loadMove()
		},

		/** @return {Promise<void>} */
		async loadMove() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/move'))
				this.newHost = data?.new_host ?? null
				this.takeMove(data?.move)
			} catch (error) {
				logger.debug('Could not read the Bluesky move', { error })
				if (this.move?.state === 'running') {
					this.schedulePoll()
				}
			}
		},

		/**
		 * @param {object|null|undefined} move the move as the server answers it
		 */
		takeMove(move) {
			// a move here is the other card's
			this.move = move?.direction === 'away' ? move : null
			if (this.move?.state === 'running') {
				this.schedulePoll()
			} else {
				this.stopPolling()
			}
		},

		/** Asks again in a few seconds; one timer at a time. */
		schedulePoll() {
			this.stopPolling()
			this.pollTimer = window.setTimeout(() => this.loadMove(), POLL_INTERVAL)
		},

		stopPolling() {
			window.clearTimeout(this.pollTimer)
			this.pollTimer = null
		},

		askToConfirm() {
			if (this.ready) {
				this.confirming = true
			}
		},

		/**
		 * Starts the move, after the Nextcloud password.
		 *
		 * @return {Promise<void>}
		 */
		async start() {
			if (!this.ready) {
				return
			}
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.busy = true
			this.startError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move'), {
					pds: this.pds.trim(),
					handle: this.handle.trim(),
					email: this.email.trim(),
					password: this.password,
					inviteCode: this.inviteCode.trim(),
					without_recovery: !this.hasRecoveryKey,
					announce: this.announce ? 'post' : 'none',
				})
				this.password = ''
				this.confirming = false
				this.takeMove(data?.move)
			} catch (error) {
				const status = error?.response?.status
				if (status === 422) {
					this.startError = errorOf(error, t('social', 'Could not start the move'))
					this.confirming = false
				} else {
					showError(status === 403
						? t('social', 'Confirm your password again and retry.')
						: errorOf(error, t('social', 'Could not start the move')).text)
				}
			} finally {
				this.busy = false
			}
		},

		/** @param {string} url the pds as the server keeps it @return {string} its host */
		hostOf(url) {
			try {
				return new URL(url).host
			} catch {
				return url
			}
		},

		/**
		 * Goes on with a move that stopped before the switch: copying again,
		 * or switching without what is missing.
		 *
		 * @param {boolean} withoutMissing switch without what did not arrive
		 * @return {Promise<void>}
		 */
		async decide(withoutMissing) {
			this.busy = true
			this.actionError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move/continue'), { without_missing: withoutMissing })
				this.takeMove(data?.move)
			} catch (error) {
				this.actionError = errorOf(error, t('social', 'Could not go on with the move'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Calls the move off before the switch, after the Nextcloud password.
		 *
		 * @return {Promise<void>}
		 */
		async callOff() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.busy = true
			this.actionError = null
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/move'))
				this.takeMove(data?.move)
			} catch (error) {
				this.actionError = errorOf(error, t('social', 'Could not call the move off'))
				showError(this.actionError.text)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Starts a failed move again, from where it stopped.
		 *
		 * @return {Promise<void>}
		 */
		async retry() {
			this.busy = true
			this.retryError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move/retry'))
				this.takeMove(data?.move)
			} catch (error) {
				const reason = errorOf(error, t('social', 'Could not start the move'))
				if (error?.response?.status === 422) {
					this.retryError = reason
				} else {
					showError(reason.text)
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration__card--bluesky {
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

.migration__bluesky-form {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.migration__bluesky-field {
	flex: 1 1 45%;
	min-width: 220px;
}

.migration__bluesky-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 12px;

	.migration__bluesky-confirm {
		flex-basis: 100%;
		margin-bottom: 0;
	}
}

.migration__bluesky-progress {
	display: flex;
	align-items: flex-start;
	gap: 12px;

	.migration__bluesky-moving {
		margin-bottom: 4px;
		font-weight: 600;
	}

	.migration__bluesky-step {
		color: var(--color-text-maxcontrast);
	}
}

.migration__bluesky-done {
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.migration__bluesky-control {
	color: var(--color-text-maxcontrast);
}

.migration__bluesky-announce {
	margin-top: 8px;
}
</style>
