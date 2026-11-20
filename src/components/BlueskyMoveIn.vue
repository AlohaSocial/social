<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section v-if="shown" class="migration__card migration__card--bluesky-in">
		<h4>
			<IconAccountArrowLeft :size="20" />
			{{ t('social', 'Bring your Bluesky account here') }}
		</h4>
		<p>
			{{ t('social', 'An account you have on Bluesky can live on this server instead. Your followers there keep following you, and your posts and the accounts you follow come along.') }}
		</p>

		<template v-if="move && move.state === 'done'">
			<p class="migration__bluesky-in-done">
				{{ t('social', 'Your Bluesky account now lives here, as {handle}.', { handle: identity.handle }) }}
			</p>
			<div v-if="republishable" class="migration__bluesky-in-actions">
				<p class="migration__bluesky-in-confirm">
					{{ n('social', '%n post you wrote here was taken off Bluesky by the move. It can be published again from your moved account.', '%n posts you wrote here were taken off Bluesky by the move. They can be published again from your moved account.', republishable) }}
				</p>
				<NcButton :disabled="busy" @click="republish">
					{{ t('social', 'Publish them again') }}
				</NcButton>
			</div>
			<p v-else-if="move.progress?.republish?.state" class="migration__bluesky-in-note">
				{{ t('social', 'Your posts are being published on Bluesky again.') }}
			</p>
		</template>

		<BlueskyMissingBlobs v-else-if="move && move.state === 'needs_decision'" :move="move" @move="takeMove" />

		<div v-else-if="move && move.state === 'waiting'" class="migration__bluesky-in-code">
			<p>
				{{ t('social', 'Bluesky e-mailed you a code to confirm the move. Enter it here.') }}
			</p>
			<p v-if="sentTo">
				{{ t('social', 'The code went to {address}.', { address: sentTo }) }}
			</p>
			<NcTextField
				v-model="code"
				class="migration__bluesky-in-field"
				:label="t('social', 'Code from the e-mail')"
				:disabled="busy"
				@keydown.enter.prevent="sendCode" />
			<ServerError v-if="codeError" :error="codeError" />
			<NcButton variant="primary" :disabled="busy || code.trim() === ''" @click="sendCode">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Confirm the move') }}
			</NcButton>
			<p v-if="codeNote" class="migration__bluesky-in-note" role="status">
				{{ codeNote }}
			</p>
			<div class="migration__bluesky-in-actions">
				<NcButton :disabled="busy" @click="newCode">
					{{ t('social', 'Send a new code') }}
				</NcButton>
				<NcButton :disabled="busy" @click="cancel">
					{{ t('social', 'Call off the move') }}
				</NcButton>
			</div>
		</div>

		<div v-else-if="move && move.state === 'running'" class="migration__bluesky-in-progress" aria-live="polite">
			<NcLoadingIcon :size="20" />
			<div>
				<p class="migration__bluesky-in-moving">
					{{ t('social', 'Bringing {handle} here', { handle: move.handle }) }}
				</p>
				<p class="migration__bluesky-in-step">
					{{ stepText }}
				</p>
			</div>
		</div>

		<template v-else>
			<NcNoteCard v-if="move && move.state === 'failed'" type="error">
				<p>{{ t('social', 'The move stopped.') }}</p>
				<ServerError :error="stoppedError" :alert="false" />
				<ServerError v-if="retryError" :error="retryError" />
				<NcButton :disabled="busy" @click="retry">
					{{ t('social', 'Try again') }}
				</NcButton>
			</NcNoteCard>

			<!-- the page may sit inside a form: Enter here asks to move, never submits that -->
			<div class="migration__bluesky-in-form">
				<NcTextField
					v-model="handle"
					class="migration__bluesky-in-field"
					:label="t('social', 'Your Bluesky handle')"
					placeholder="alice.bsky.social"
					:disabled="busy"
					@keydown.enter.prevent="askToConfirm" />
				<NcTextField
					v-model="password"
					class="migration__bluesky-in-field"
					type="password"
					autocomplete="current-password"
					:label="t('social', 'Your Bluesky password (not an app password)')"
					:disabled="busy"
					@keydown.enter.prevent="askToConfirm" />
				<NcTextField
					v-model="signInCode"
					class="migration__bluesky-in-field"
					:label="t('social', 'Sign-in code, if Bluesky e-mailed you one')"
					:disabled="busy"
					@keydown.enter.prevent="askToConfirm" />
			</div>
			<p class="migration__bluesky-in-note">
				{{ t('social', 'The password is used to sign in once and is not kept.') }}
			</p>
			<ServerError v-if="startError" :error="startError" />
			<div v-if="confirming" class="migration__bluesky-in-actions">
				<p class="migration__bluesky-in-confirm">
					{{ t('social', 'The Bluesky account you bring replaces {handle}, the one this server made for you: its posts and followers on Bluesky are not kept.', { handle: identity.handle }) }}
					<template v-if="postsOnBluesky > 0">
						{{ n('social', '%n post disappears from Bluesky; you can publish it again afterwards.', '%n posts disappear from Bluesky; you can publish them again afterwards.', postsOnBluesky) }}
					</template>
				</p>
				<NcButton variant="error" :disabled="busy" @click="start">
					<template v-if="busy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Bring it here') }}
				</NcButton>
				<NcButton :disabled="busy" @click="confirming = false">
					{{ t('social', 'Not now') }}
				</NcButton>
			</div>
			<div v-else class="migration__bluesky-in-actions">
				<NcButton :disabled="!ready" @click="askToConfirm">
					{{ t('social', 'Bring my Bluesky account here') }}
				</NcButton>
			</div>
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
import IconAccountArrowLeft from 'vue-material-design-icons/AccountArrowLeft.vue'
import BlueskyMissingBlobs from './BlueskyMissingBlobs.vue'
import ServerError from './ServerError.vue'
import { n, t } from '@nextcloud/l10n'
import logger from '../services/logger.js'
import { errorOf, stoppedMoveError } from '../utils/serverError.js'

/** How often a running move is asked about, in milliseconds. */
const POLL_INTERVAL = 5000

/**
 * Bringing a Bluesky account to this server's PDS: the form, the code the
 * old server e-mails, and where the move has got to. Draws nothing when
 * Bluesky is off here.
 */
export default {
	name: 'BlueskyMoveIn',

	components: {
		BlueskyMissingBlobs,
		ServerError,
		IconAccountArrowLeft,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			/** @type {object|null} the person's Bluesky identity here */
			identity: null,
			/** @type {object|null} the latest move here, as the server keeps it */
			move: null,
			/** how many of the person's posts a move here takes off Bluesky */
			postsOnBluesky: 0,
			handle: '',
			password: '',
			signInCode: '',
			code: '',
			confirming: false,
			busy: false,
			/** @type {{text: string, details: string}|null} */
			startError: null,
			/** @type {{text: string, details: string}|null} */
			codeError: null,
			/** what became of a new code, once one was asked for */
			codeNote: '',
			/** @type {{text: string, details: string}|null} */
			retryError: null,
			/** @type {number|null} */
			pollTimer: null,
		}
	},

	computed: {
		/** @return {boolean} */
		shown() {
			return this.identity !== null
		},

		/** @return {number} the posts a finished move took off Bluesky, while they can be published again */
		republishable() {
			const republish = this.move?.progress?.republish
			return republish && !republish.state ? republish.count ?? 0 : 0
		},

		/** @return {{text: string, details: string}} why the move stopped */
		stoppedError() {
			return stoppedMoveError(this.move)
		},

		/** @return {string} where the old server sent the code, '' when it did not say */
		sentTo() {
			return this.move?.progress?.code_sent_to ?? ''
		},

		/** @return {boolean} whether the sign-in is filled in */
		ready() {
			return !this.busy && this.handle.trim() !== '' && this.password !== ''
		},

		/** @return {string} the step a running move is on, in words */
		stepText() {
			switch (this.move?.step) {
				case 'repo':
					return t('social', 'Copying your posts')
				case 'blobs':
					return t('social', 'Copying your photos and videos')
				case 'prefs':
					return t('social', 'Copying your settings')
				case 'follows':
					return t('social', 'Following the accounts you follow')
				case 'code':
					return t('social', 'Asking Bluesky to e-mail you a code')
				case 'identity':
					return t('social', 'Pointing your account at this server')
				case 'activate':
					return t('social', 'Switching your account off on the old server')
				case 'posts':
					return t('social', 'Putting your posts in your timeline here')
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

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
				this.identity = data ?? null
			} catch (error) {
				logger.debug('Bluesky is not available here', { error })
				return
			}
			await this.loadMove()
		},

		/** @return {Promise<void>} */
		async loadMove() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/move'))
				const before = this.move?.state
				this.postsOnBluesky = data?.posts_on_bluesky ?? 0
				this.takeMove(data?.move)
				if (before !== 'done' && this.move?.state === 'done') {
					// the account goes by its handle here now
					const identity = await axios.get(generateUrl('apps/social/api/v1/social/bluesky/identity'))
					this.identity = identity.data ?? this.identity
				}
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
			// a move away is the other card's
			this.move = move?.direction === 'in' ? move : null
			if (this.move?.state === 'running') {
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
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-in'), {
					handle: this.handle.trim(),
					password: this.password,
					authFactorToken: this.signInCode.trim(),
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

		/**
		 * The e-mailed code: the move goes on.
		 *
		 * @return {Promise<void>}
		 */
		async sendCode() {
			if (this.busy || this.code.trim() === '') {
				return
			}
			this.busy = true
			this.codeError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move/code'), { code: this.code.trim() })
				this.code = ''
				this.takeMove(data?.move)
			} catch (error) {
				const reason = errorOf(error, t('social', 'Could not confirm the move'))
				if (error?.response?.status === 422) {
					this.codeError = reason
				} else {
					showError(reason.text)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Asks the old server again to e-mail a code.
		 *
		 * @return {Promise<void>}
		 */
		async newCode() {
			this.busy = true
			this.codeError = null
			this.codeNote = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-in/code'))
				this.takeMove(data?.move)
				this.codeNote = data?.sent_to
					? t('social', 'A new code is on its way to {address}.', { address: data.sent_to })
					: t('social', 'A new code is on its way to the e-mail address of your Bluesky account.')
			} catch (error) {
				const reason = errorOf(error, t('social', 'Could not ask for a new code'))
				if (error?.response?.status === 422) {
					this.codeError = reason
				} else {
					showError(reason.text)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Calls the move off, after the Nextcloud password.
		 *
		 * @return {Promise<void>}
		 */
		async cancel() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.busy = true
			this.codeError = null
			try {
				const { data } = await axios.delete(generateUrl('apps/social/api/v1/social/bluesky/move-in'))
				this.codeNote = ''
				this.takeMove(data?.move)
			} catch (error) {
				const reason = errorOf(error, t('social', 'Could not call off the move'))
				if (error?.response?.status === 422) {
					this.codeError = reason
				} else {
					showError(reason.text)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Publishes the posts the move took off Bluesky again.
		 *
		 * @return {Promise<void>}
		 */
		async republish() {
			this.busy = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/social/bluesky/move-in/republish'))
				this.takeMove(data?.move)
			} catch (error) {
				showError(errorOf(error, t('social', 'Could not publish the posts again')).text)
			} finally {
				this.busy = false
			}
		},

		/** @return {Promise<void>} */
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
.migration__card--bluesky-in {
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

.migration__bluesky-in-form {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.migration__bluesky-in-field {
	flex: 1 1 45%;
	min-width: 220px;
}

.migration__bluesky-in-note {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.migration__bluesky-in-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 12px;

	.migration__bluesky-in-confirm {
		flex-basis: 100%;
		margin-bottom: 0;
	}
}

.migration__bluesky-in-progress {
	display: flex;
	align-items: flex-start;
	gap: 12px;

	.migration__bluesky-in-moving {
		margin-bottom: 4px;
		font-weight: 600;
	}

	.migration__bluesky-in-step {
		color: var(--color-text-maxcontrast);
	}
}

.migration__bluesky-in-code {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.migration__bluesky-in-done {
	padding: 8px 12px;
	border-inline-start: 3px solid var(--color-primary-element);
	background: var(--color-primary-element-light);
	font-weight: 600;
}
</style>
