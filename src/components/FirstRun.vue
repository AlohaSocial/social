<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="first-run" :aria-label="t('social', 'Getting started on the fediverse')">
		<header class="first-run__header">
			<ol class="first-run__steps" :aria-label="t('social', 'Steps')">
				<li
					v-for="(name, index) in stepNames"
					:key="name"
					class="first-run__dot"
					:class="{ 'first-run__dot--done': index < step, 'first-run__dot--current': index === step }"
					:aria-current="index === step ? 'step' : undefined">
					<span class="hidden-visually">{{ name }}</span>
				</li>
			</ol>
			<NcButton
				variant="tertiary"
				class="first-run__skip"
				:aria-label="t('social', 'Skip the introduction')"
				@click="finish(false)">
				<template #icon>
					<IconClose :size="20" />
				</template>
			</NcButton>
		</header>

		<Transition :name="reducedMotion ? '' : 'first-run-step'" mode="out-in">
			<!-- 1. the address -->
			<div v-if="step === 0" key="address" class="first-run__body">
				<h2 class="first-run__title">
					{{ t('social', 'You are on the fediverse') }}
				</h2>
				<p class="first-run__lead">
					{{ t('social', 'Your Nextcloud made you an account. This is your address — anyone on Mastodon, Pixelfed, PeerTube or any other server that speaks ActivityPub can follow it:') }}
				</p>
				<div class="first-run__address">
					<code class="first-run__handle">{{ socialId }}</code>
					<NcButton variant="secondary" @click="copyHandle">
						<template #icon>
							<IconCheck v-if="copied" :size="20" />
							<IconContentCopy v-else :size="20" />
						</template>
						{{ copied ? t('social', 'Copied') : t('social', 'Copy') }}
					</NcButton>
				</div>
				<p class="first-run__hint">
					{{ t('social', 'Give it to people the way you would an email address. Your profile, your avatar and your name come from your Nextcloud account and can be changed on your profile page.') }}
				</p>
			</div>

			<!-- 2. people -->
			<div v-else-if="step === 1" key="people" class="first-run__body">
				<h2 class="first-run__title">
					{{ t('social', 'A feed is only as good as who is in it') }}
				</h2>
				<p class="first-run__lead">
					{{ t('social', 'Nothing shows up here until you follow somebody. Two places to start:') }}
				</p>

				<h3 class="first-run__subtitle">
					{{ t('social', 'People on your Nextcloud') }}
				</h3>
				<ul v-if="colleagues.length" class="first-run__people">
					<li v-for="account in colleagues" :key="account.acct" class="first-run__person">
						<ActorAvatar :actor="account" :size="36" :link="false" />
						<span class="first-run__person-names">
							<span class="first-run__person-name">{{ account.display_name || account.username }}</span>
							<span class="first-run__person-handle">@{{ account.acct }}</span>
						</span>
						<NcButton
							:variant="followed.includes(account.acct) ? 'success' : 'primary'"
							:disabled="followed.includes(account.acct) || busy === account.acct"
							@click="follow(account)">
							<template #icon>
								<NcLoadingIcon v-if="busy === account.acct" :size="20" />
								<IconCheck v-else-if="followed.includes(account.acct)" :size="20" />
								<IconAccountPlus v-else :size="20" />
							</template>
							{{ followed.includes(account.acct) ? t('social', 'Following') : t('social', 'Follow') }}
						</NcButton>
					</li>
				</ul>
				<p v-else-if="loading" class="first-run__hint">
					{{ t('social', 'Looking around …') }}
				</p>
				<p v-else class="first-run__hint">
					{{ t('social', 'Nobody on this Nextcloud has said where they are on the fediverse yet. You are the first — your handle is now on your Nextcloud profile, so the next person will see you here.') }}
				</p>

				<h3 class="first-run__subtitle">
					{{ t('social', 'Starter packs') }}
				</h3>
				<ul v-if="packs.length" class="first-run__packs">
					<li v-for="pack in packs" :key="pack.id" class="first-run__pack">
						<span class="first-run__pack-text">
							<span class="first-run__pack-name">{{ pack.name }}</span>
							<span v-if="pack.description" class="first-run__pack-description">{{ pack.description }}</span>
						</span>
						<NcButton
							:variant="followedPacks.includes(pack.id) ? 'success' : 'secondary'"
							:disabled="followedPacks.includes(pack.id) || busy === pack.id || !pack.size"
							@click="followPack(pack)">
							<template #icon>
								<NcLoadingIcon v-if="busy === pack.id" :size="20" />
								<IconCheck v-else-if="followedPacks.includes(pack.id)" :size="20" />
								<IconAccountMultiplePlus v-else :size="20" />
							</template>
							{{ followedPacks.includes(pack.id)
								? t('social', 'Following')
								: n('social', 'Follow %n account', 'Follow all %n', pack.size) }}
						</NcButton>
					</li>
				</ul>
				<p v-else-if="!loading" class="first-run__hint">
					{{ t('social', 'No starter packs on this server. Discover has suggestions and what is trending.') }}
				</p>
			</div>

			<!-- 3. what you had -->
			<div v-else-if="step === 2" key="follows" class="first-run__body">
				<h2 class="first-run__title">
					{{ t('social', 'Already somewhere else?') }}
				</h2>
				<p class="first-run__lead">
					{{ t('social', 'If you have an account on Mastodon or another server, type its handle. Everyone it follows is followed from here and its public posts are brought along, in the background. Moving your followers over as well is the last step, done on your old server; Settings → Migration tells you how.') }}
				</p>
				<div class="first-run__move">
					<NcTextField
						v-model="oldHandle"
						class="first-run__move-field"
						:label="t('social', 'Your old account')"
						placeholder="@you@mastodon.example"
						:disabled="moveBusy"
						@keydown.enter="moveIn" />
					<NcButton variant="primary" :disabled="moveBusy || oldHandle.trim() === ''" @click="moveIn">
						<template #icon>
							<NcLoadingIcon v-if="moveBusy" :size="20" />
							<IconAccountArrowRight v-else :size="20" />
						</template>
						{{ moveBusy ? t('social', 'Moving …') : t('social', 'Move here') }}
					</NcButton>
				</div>
				<p v-if="moveResult" class="first-run__result" role="status">
					{{ moveResult }}
				</p>
				<p class="first-run__hint">
					{{ t('social', 'Or upload the file your old server exports: Mastodon and servers like it write following_accounts.csv, Pixelfed pixelfed-following.json; each account in it is followed from here.') }}
				</p>
				<!-- opened by the button below, which is the control a keyboard
				     and a screen reader reach; the input stays out of both -->
				<input
					ref="follows"
					type="file"
					accept=".csv,text/csv,.json,application/json"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importFollows">
				<div class="first-run__import">
					<NcButton variant="primary" :disabled="followsBusy" @click="pickFollows">
						<template #icon>
							<NcLoadingIcon v-if="followsBusy" :size="20" />
							<IconUpload v-else :size="20" />
						</template>
						{{ followsBusy ? t('social', 'Following …') : t('social', 'Upload your follows') }}
					</NcButton>
					<p v-if="followsResult" class="first-run__result" role="status">
						{{ followsResult }}
					</p>
				</div>
				<p class="first-run__hint">
					{{ t('social', 'On Mastodon: Preferences → Import and export → Data export → Follows (CSV). Moving your followers over as well is in Settings, under Migration — that one is a one-way move, so it is not a button here.') }}
				</p>
			</div>

			<!-- 4. go -->
			<div v-else key="ready" class="first-run__body">
				<h2 class="first-run__title">
					{{ t('social', 'That is all there is to it') }}
				</h2>
				<p class="first-run__lead">
					{{ followedAnything
						? t('social', 'Your feed fills up as the people you follow post. Meanwhile, say hello — a first post is how the fediverse finds out you are here.')
						: t('social', 'You have not followed anyone yet, and that is fine: Discover is always a click away in the sidebar. Say hello in the meantime — a first post is how the fediverse finds out you are here.') }}
				</p>
			</div>
		</Transition>

		<footer class="first-run__footer">
			<NcButton v-if="step > 0" variant="tertiary" @click="step--">
				<template #icon>
					<IconArrowLeft :size="20" />
				</template>
				{{ t('social', 'Back') }}
			</NcButton>
			<span class="first-run__spacer" />
			<NcButton v-if="step < stepNames.length - 1" variant="primary" @click="step++">
				{{ t('social', 'Next') }}
				<template #icon>
					<IconArrowRight :size="20" />
				</template>
			</NcButton>
			<NcButton v-else variant="primary" @click="finish(true)">
				<template #icon>
					<IconPencil :size="20" />
				</template>
				{{ t('social', 'Write your first post') }}
			</NcButton>
		</footer>
	</section>
</template>

<script>
/**
 * The first thing a new account sees, in place of a paragraph about beta.
 *
 * The account was made a moment ago by the server, and the reader has three
 * questions the old banner did not answer: what is my address, who do I
 * follow, and can I bring what I had. Four short steps, each skippable,
 * and it is gone for good once closed -- `firstrun` is true on exactly one
 * page load, the one right after the actor was created.
 *
 * Nothing here is a second implementation of anything: the people come from
 * the suggestions the Discover page reads (the colleagues among them, marked
 * `featured`), the packs from the same starter-pack routes, and the CSV goes
 * to the same import the Settings page posts to.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { mapStores } from 'pinia'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import IconArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import ActorAvatar from './ActorAvatar.vue'
import eventBus from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { useAccountStore } from '../store/account.js'
import { useCurrentUser } from '../composables/useCurrentUser.js'

/** how many colleagues to show; Discover has the rest */
const MAX_COLLEAGUES = 6

export default {
	name: 'FirstRun',
	components: {
		ActorAvatar,
		IconAccountArrowRight,
		IconAccountMultiplePlus,
		IconAccountPlus,
		IconArrowLeft,
		IconArrowRight,
		IconCheck,
		IconClose,
		IconContentCopy,
		IconPencil,
		IconUpload,
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},

	emits: ['done'],

	setup() {
		const { socialId } = useCurrentUser()

		return { socialId }
	},

	data() {
		return {
			step: 0,
			stepNames: [
				t('social', 'Your address'),
				t('social', 'People to follow'),
				t('social', 'Bring your follows'),
				t('social', 'Ready'),
			],

			loading: true,
			/** accounts on this Nextcloud that said where they are, as Discover marks them */
			colleagues: [],
			packs: [],
			/** the handles followed from here */
			followed: [],
			/** the pack ids followed from here */
			followedPacks: [],
			/** what is being followed right now: a handle or a pack id */
			busy: '',
			copied: false,
			copyTimer: null,
			followsBusy: false,
			followsResult: '',
			/** @type {string} the old account, as typed */
			oldHandle: '',
			moveBusy: false,
			moveResult: '',
			reducedMotion: window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		followedAnything() {
			return this.followed.length > 0 || this.followedPacks.length > 0 || this.followsResult !== '' || this.moveResult !== ''
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		window.clearTimeout(this.copyTimer)
	},

	methods: {
		/** Opens the file chooser for the follows export. */
		pickFollows() {
			/** @type {HTMLInputElement} */ (this.$refs.follows).click()
		},

		/** Who there is to follow, both kinds at once; either failing leaves the other. */
		async load() {
			this.loading = true
			const [suggestions, packs] = await Promise.allSettled([
				axios.get(generateUrl('apps/social/api/v2/suggestions')),
				axios.get(generateUrl('apps/social/api/v1/starter_packs')),
			])
			if (suggestions.status === 'fulfilled' && Array.isArray(suggestions.value.data)) {
				// the colleagues among the suggestions: `featured` is what
				// Suggestion::SOURCE_COLLEAGUES is called on the wire
				this.colleagues = suggestions.value.data
					.filter((suggestion) => Array.isArray(suggestion.sources) && suggestion.sources.includes('featured'))
					.map((suggestion) => suggestion.account)
					.filter((account) => account && account.acct)
					.slice(0, MAX_COLLEAGUES)
			} else if (suggestions.status === 'rejected') {
				logger.debug('No suggestions for the first run', { error: suggestions.reason })
			}
			if (packs.status === 'fulfilled' && Array.isArray(packs.value.data)) {
				this.packs = packs.value.data
			} else if (packs.status === 'rejected') {
				logger.debug('No starter packs for the first run', { error: packs.reason })
			}
			this.loading = false
		},

		async copyHandle() {
			try {
				await navigator.clipboard.writeText(this.socialId)
				this.copied = true
				window.clearTimeout(this.copyTimer)
				this.copyTimer = window.setTimeout(() => {
					this.copied = false
				}, 2000)
			} catch (error) {
				logger.debug('Could not copy the handle', { error })
				showError(t('social', 'Could not copy — select the address and copy it yourself'))
			}
		},

		/** @param {object} account a colleague, as an Account entity */
		async follow(account) {
			this.busy = account.acct
			try {
				await this.accountStore.followAccount({ accountToFollow: account.acct })
				this.followed.push(account.acct)
			} catch (error) {
				logger.error('Could not follow from the first run', { error })
				showError(t('social', 'Could not follow {account}', { account: account.acct }))
			} finally {
				this.busy = ''
			}
		},

		/** @param {object} pack a starter pack, as the server lists them */
		async followPack(pack) {
			this.busy = pack.id
			try {
				const { data } = await axios.post(generateUrl(`apps/social/api/v1/starter_packs/${pack.id}/follow`))
				const count = Array.isArray(data?.followed) ? data.followed.length : 0

				// nobody was followed: every handle in the pack is on a server
				// this one could not reach. Marking it "Following" and saying
				// "Followed 0 accounts" was the app reporting success for
				// something that did not happen
				if (count === 0) {
					showError(t('social', 'None of these accounts could be reached. Their servers may be busy — Discover has the pack, and says which ones.'))

					return
				}

				this.followedPacks.push(pack.id)
				showSuccess(n('social', 'Followed %n account', 'Followed %n accounts', count))
			} catch (error) {
				logger.error('Could not follow the starter pack', { error, pack: pack.id })
				showError(t('social', 'Could not follow these accounts'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The same upload the Settings page makes, to the same route.
		 *
		 * @param {Event} event the file input's change
		 */
		/**
		 * Queues the follows file, then watches the import until it is done:
		 * the server keeps the file and follows each account in the background,
		 * where a long list outlives no request.
		 *
		 * @param {Event} event the file input's change
		 * @return {Promise<void>}
		 */
		async importFollows(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.followsBusy = true
			this.followsResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/follows'), form)
				const job = await this.awaitImport(data?.import?.id)
				if (job === null) {
					this.followsResult = t('social', 'Your follows are being imported in the background; Settings → Migration shows where it gets to.')
				} else if (job.status === 'failed') {
					showError(job.report?.error || t('social', 'Could not import those follows'))
				} else {
					this.followsResult = t(
						'social',
						'{followed} followed, {skipped} skipped, {failed} could not be reached',
						{ followed: job.done ?? 0, skipped: job.skipped ?? 0, failed: job.failed ?? 0 },
					)
				}
			} catch (error) {
				logger.error('Importing follows failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import those follows'))
			} finally {
				this.followsBusy = false
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * Moves here from the old handle: the alias is set, and following who
		 * it follows and bringing its posts over run in the background.
		 *
		 * @return {Promise<void>}
		 */
		async moveIn() {
			const handle = this.oldHandle.trim()
			if (handle === '' || this.moveBusy) {
				return
			}
			this.moveBusy = true
			this.moveResult = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/migration/move-in'), {
					handle,
					follows: '1',
					posts: '1',
					fetch_media: '1',
				})
				const job = await this.awaitImport(data?.import?.id)
				if (job === null) {
					this.moveResult = t('social', 'Your move is running in the background; Settings → Migration shows where it gets to, and names the last step on your old server.')
				} else if (job.status === 'failed') {
					showError(job.report?.error || t('social', 'Could not move that account here'))
				} else {
					this.moveResult = t(
						'social',
						'{followed} accounts followed and {imported} posts brought over. The last step is on your old server; Settings → Migration names it.',
						{ followed: job.report?.followed ?? 0, imported: job.report?.imported ?? 0 },
					)
				}
			} catch (error) {
				logger.error('Moving in failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not move that account here'))
			} finally {
				this.moveBusy = false
			}
		},

		/**
		 * Polls the imports until the one named is finished, for as long as a
		 * person will wait on an introduction screen.
		 *
		 * @param {number|undefined} id the queued import
		 * @return {Promise<object|null>} the finished import, or null when it is still running
		 */
		async awaitImport(id) {
			if (!id) {
				return null
			}
			for (let attempt = 0; attempt < 20; attempt++) {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/imports'))
				const job = (Array.isArray(data?.imports) ? data.imports : []).find((candidate) => candidate.id === id)
				if (job && (job.status === 'done' || job.status === 'failed')) {
					return job
				}
				await new Promise((resolve) => window.setTimeout(resolve, 3000))
			}
			return null
		},

		/**
		 * Closes the introduction for good.
		 *
		 * @param {boolean} compose whether to put the caret in the composer:
		 *   what the last step's button promises, and not what Skip does
		 */
		finish(compose) {
			this.$emit('done')
			if (compose) {
				// the shortcuts help offers "n" for this; the composer answers it
				eventBus.emit('shortcut:compose')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.first-run__move {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.first-run__move-field {
	flex: 1;
	min-width: 200px;
}

/*
 * A card in the column, the width of the timeline. Quiet: a hairline, the
 * app's background, one accent — the step dots and the primary button — so
 * it reads as part of the page and not as a modal in its way.
 */
.first-run {
	position: relative;
	margin: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 5);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 16px);
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
	}

	&__steps {
		display: flex;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	/* the dot that is current stretches into a short bar, which is what
	   says "you are here" without a number */
	&__dot {
		width: 8px;
		height: 8px;
		border-radius: 999px;
		background: var(--color-border-dark);
		transition: width .3s cubic-bezier(.22, 1.2, .48, 1), background-color .2s ease;

		&--done {
			background: var(--color-primary-element-light-hover);
		}

		&--current {
			width: 28px;
			background: var(--color-primary-element);
		}
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__title {
		margin: 0;
		font-size: 22px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__subtitle {
		margin: 12px 0 0;
		font-size: 14px;
		font-weight: 600;
		letter-spacing: .02em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__lead {
		margin: 0;
		line-height: 1.6;
	}

	&__hint,
	&__result {
		margin: 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.6;
	}

	&__address {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin: 8px 0;
	}

	&__handle {
		flex: 1 1 auto;
		min-width: 0;
		padding: 10px 14px;
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: 16px;
		font-weight: 700;
		overflow-wrap: anywhere;
		user-select: all;
	}

	&__people,
	&__packs {
		margin: 0;
		padding: 0;
		list-style: none;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__person,
	&__pack {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: 44px;
	}

	&__person-names,
	&__pack-text {
		flex: 1;
		min-width: 0;
		display: flex;
		flex-direction: column;
		line-height: 1.3;
	}

	&__person-name,
	&__pack-name {
		font-weight: 600;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__person-handle,
	&__pack-description {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__import {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin: 4px 0;
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: 8px;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}

	&__spacer {
		flex: 1;
	}
}

/* one step slides out to the left as the next comes in from the right */
.first-run-step-enter-active,
.first-run-step-leave-active {
	transition: opacity .18s ease, transform .22s cubic-bezier(.22, 1.2, .48, 1);
}

.first-run-step-enter-from {
	opacity: 0;
	transform: translateX(16px);
}

.first-run-step-leave-to {
	opacity: 0;
	transform: translateX(-16px);
}

@media (prefers-reduced-motion: reduce) {
	.first-run__dot,
	.first-run-step-enter-active,
	.first-run-step-leave-active {
		transition: none;
	}
}
</style>
