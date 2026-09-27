<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="account-setup social__wrapper">
		<section class="account-setup__card" :aria-label="t('social', 'Your account on the fediverse')">
			<h1 class="account-setup__title">
				{{ t('social', 'Join the fediverse from your Nextcloud') }}
			</h1>
			<p class="account-setup__lead">
				{{ t('social', 'Social connects this Nextcloud to Mastodon, Pixelfed, PeerTube and every other server that speaks ActivityPub. Nothing has been created for you yet: an account here is a public identity with a key pair of its own, so it is yours to ask for.') }}
			</p>

			<div class="account-setup__choices">
				<!-- 1. an account here -->
				<form class="account-setup__choice" @submit.prevent="create">
					<h2 class="account-setup__heading">
						{{ t('social', 'Create my account here') }}
					</h2>
					<label class="account-setup__label" for="account-setup-handle">
						{{ t('social', 'Your handle') }}
					</label>
					<div class="account-setup__handle">
						<span class="account-setup__at" aria-hidden="true">@</span>
						<input
							id="account-setup-handle"
							v-model.trim="handle"
							type="text"
							class="account-setup__input"
							autocomplete="off"
							spellcheck="false"
							maxlength="64"
							pattern="[A-Za-z0-9_]+([A-Za-z0-9_.\-]*[A-Za-z0-9_])?"
							:disabled="busy !== ''"
							required>
						<span class="account-setup__host">@{{ hostname }}</span>
					</div>
					<p class="account-setup__hint">
						{{ t('social', 'Letters, digits and underscore, with dot and dash inside. This is your address on the fediverse and it cannot be changed later.') }}
					</p>
					<p v-if="createError" class="account-setup__error" role="alert">
						{{ createError }}
					</p>
					<NcButton type="submit" variant="primary" :disabled="busy !== '' || handle === ''">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'create'" :size="20" />
							<IconAccountPlus v-else :size="20" />
						</template>
						{{ t('social', 'Create @{handle}@{host}', { handle: handle || '…', host: hostname }) }}
					</NcButton>
				</form>

				<!-- 2. an account elsewhere -->
				<form class="account-setup__choice account-setup__choice--secondary" @submit.prevent="link">
					<h2 class="account-setup__heading">
						{{ t('social', 'I already have one somewhere else') }}
					</h2>
					<p class="account-setup__hint">
						{{ t('social', 'On Mastodon, say. Name it and it goes on your Nextcloud profile, where the people here can find and follow it. Nothing is created here; you can still make an account later.') }}
					</p>
					<label class="account-setup__label" for="account-setup-linked">
						{{ t('social', 'Your account') }}
					</label>
					<input
						id="account-setup-linked"
						v-model.trim="linked"
						type="text"
						class="account-setup__input account-setup__input--wide"
						autocomplete="off"
						spellcheck="false"
						placeholder="you@mastodon.social"
						:disabled="busy !== ''">
					<p v-if="linkError" class="account-setup__error" role="alert">
						{{ linkError }}
					</p>
					<p v-if="linkedNow" class="account-setup__done" role="status">
						{{ t('social', 'Your profile now says you are @{handle}. The people on this Nextcloud will see it on Discover.', { handle: linkedNow }) }}
					</p>
					<NcButton type="submit" variant="secondary" :disabled="busy !== '' || linked === ''">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'link'" :size="20" />
							<IconLinkVariant v-else :size="20" />
						</template>
						{{ t('social', 'Put it on my profile') }}
					</NcButton>
				</form>
			</div>
		</section>
	</div>
</template>

<script>
/**
 * What a person sees before they have an account: the question.
 *
 * The actor used to be created on the first click of the app icon, with a
 * handle derived from the user id and no way to say "I already have one on
 * mastodon.social". This asks. Two answers: an account here, with the handle
 * they choose (the address cannot be changed afterwards, so it is chosen
 * first), or the account they already have, named on their Nextcloud profile
 * so their colleagues find it -- and nothing created here.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconLinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'AccountSetup',
	components: {
		IconAccountPlus,
		IconLinkVariant,
		NcButton,
		NcLoadingIcon,
	},

	emits: ['created', 'linked'],

	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	data() {
		const serverData = useServerData().serverData.value

		return {
			handle: serverData.suggestedHandle ?? '',
			linked: serverData.linkedHandle ?? '',
			linkedNow: '',
			/** which of the two forms is talking to the server: 'create', 'link' or '' */
			busy: '',
			createError: '',
			linkError: '',
		}
	},

	methods: {
		async create() {
			this.busy = 'create'
			this.createError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/create'), { username: this.handle })
				this.$emit('created', data?.result?.account ?? null)
			} catch (error) {
				logger.warn('Could not create the account', { error })
				this.createError = error?.response?.data?.error || t('social', 'Could not create the account')
			} finally {
				this.busy = ''
			}
		},

		async link() {
			this.busy = 'link'
			this.linkError = ''
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/link'), { handle: this.linked })
				this.linkedNow = data?.result?.handle ?? this.linked
				this.$emit('linked', this.linkedNow)
			} catch (error) {
				logger.warn('Could not put the account on the profile', { error })
				this.linkError = error?.response?.data?.error || t('social', 'Could not save that')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.account-setup {
	display: flex;
	justify-content: center;
	padding: 24px 16px;

	&__card {
		width: 100%;
		max-width: 720px;
		padding: 32px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 16px);
	}

	&__title {
		margin: 0 0 8px;
		font-size: 24px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__lead {
		margin: 0 0 24px;
		line-height: 1.6;
	}

	&__choices {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
		gap: 16px;
	}

	&__choice {
		display: flex;
		flex-direction: column;
		gap: 8px;
		padding: 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);

		&--secondary {
			background: var(--color-background-hover);
		}
	}

	&__heading {
		margin: 0;
		font-size: 17px;
		font-weight: 600;
	}

	&__label {
		font-size: 13px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__handle {
		display: flex;
		align-items: center;
		gap: 4px;
		font-variant-numeric: tabular-nums;
	}

	&__at,
	&__host {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__input {
		flex: 1;
		min-width: 0;
		margin: 0;

		&--wide {
			width: 100%;
		}
	}

	&__hint {
		margin: 0;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}

	&__done {
		margin: 0;
		color: var(--color-success-text);
	}
}
</style>
