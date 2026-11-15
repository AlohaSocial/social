<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="account-setup social__wrapper">
		<header class="account-setup__header">
			<span class="account-setup__logo" aria-hidden="true" />
			<div class="account-setup__header-text">
				<h1 class="account-setup__title">
					{{ t('social', 'Welcome to Aloha Social') }}
				</h1>
				<p class="account-setup__lead">
					{{ t('social', 'You do not have an account here yet. Here is what this is:') }}
				</p>
			</div>
		</header>

		<!-- the explainer reads, the answers sit: two columns while there
		     is room, one below it -->
		<div class="account-setup__body">
			<div class="account-setup__about">
				<h2 class="account-setup__about-title">
					{{ t('social', 'What Aloha Social gives you') }}
				</h2>
				<ul class="account-setup__points">
					<li class="account-setup__point">
						<IconAt class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'An address of your own') }}
							</h3>
							<p>
								{{ t('social', 'Like an email address: @you@host. People on Mastodon, Pixelfed, PeerTube and other servers can follow it and reply.') }}
							</p>
						</div>
					</li>
					<li class="account-setup__point">
						<IconWeb class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'One fediverse, many servers') }}
							</h3>
							<p>
								{{ t('social', 'Thousands of social networks speak the same language, so an account here reaches an account anywhere. This Nextcloud runs one of those servers.') }}
							</p>
						</div>
					</li>
					<li class="account-setup__point">
						<IconSwapHorizontal class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'Yours to keep') }}
							</h3>
							<p>
								{{ t('social', 'Your profile, avatar and name come from your Nextcloud account — and you can move the whole thing to another server whenever you like.') }}
							</p>
						</div>
					</li>
					<!-- what the administrator has switched on, not what every
					     Aloha Social does: an instance that is also on Bluesky
					     says so here, and one that is not says nothing -->
					<li v-if="blueskyEnabled" class="account-setup__point">
						<IconAt class="account-setup__point-icon" :size="20" />
						<div class="account-setup__point-text">
							<h3 class="account-setup__point-title">
								{{ t('social', 'Also on Bluesky') }}
							</h3>
							<p>
								{{ t('social', 'Your account gets a Bluesky address as well, and your posts are shown there too. You can switch that off in Settings.') }}
							</p>
						</div>
					</li>
				</ul>
				<details class="account-setup__more">
					<summary>{{ t('social', 'What is the fediverse?') }}</summary>
					<p>
						{{ t('social', 'The fediverse is not one website but thousands of them, run by different people and communities, that all speak the same language. An account on any of them can follow, like and reply to an account on any other, the way email works across providers. Nobody owns it and nobody can switch it off.') }}
					</p>
				</details>
			</div>

			<!-- linking creates nothing here, so the page has nowhere to go
			     next: it says what happened and shows the way out -->
			<div v-if="linkedNow" class="account-setup__answers">
				<div class="account-setup__form account-setup__linked" role="status">
					<IconCheck class="account-setup__linked-icon" :size="32" />
					<h2 class="account-setup__heading">
						{{ t('social', 'Done') }}
					</h2>
					<p>
						{{ t('social', 'Your Nextcloud profile now says you are @{handle}, so the people on this Nextcloud can find you.', { handle: linkedNow }) }}
					</p>
					<p class="account-setup__hint">
						{{ t('social', 'Nothing was created here. You can still create an account here later: open Aloha Social again and choose your username.') }}
					</p>
					<NcButton variant="primary" :href="homeUrl">
						{{ t('social', 'Back to Nextcloud') }}
					</NcButton>
					<NcButton variant="tertiary" @click="createInstead">
						{{ t('social', 'Create an account here now') }}
					</NcButton>
				</div>
			</div>

			<div v-else class="account-setup__answers">
				<!-- a link made before, on the profile: opening the app again
				     is not the first time, and the page says so -->
				<p v-if="linkedBefore" class="account-setup__linked-note">
					{{ t('social', 'Your Nextcloud profile says you are @{handle}, so the people on this Nextcloud can find you. You can still create an account here.', { handle: linkedBefore }) }}
				</p>
				<!-- the two answers, one at a time: the question is which of them,
					     not two forms to read past each other -->
				<div class="account-setup__modes" role="group" :aria-label="t('social', 'How do you want to continue?')">
					<button
						type="button"
						class="account-setup__mode"
						:class="{ 'account-setup__mode--active': mode === 'create' }"
						:aria-pressed="mode === 'create'"
						:disabled="busy !== ''"
						@click="choose('create')">
						<IconAccountPlus class="account-setup__mode-icon" :size="20" />
						<span class="account-setup__mode-text">
							<span class="account-setup__mode-title">
								{{ t('social', 'Create a new account') }}
							</span>
							<span class="account-setup__mode-desc">
								{{ t('social', 'Your own address, on this server.') }}
							</span>
						</span>
					</button>
					<button
						type="button"
						class="account-setup__mode"
						:class="{ 'account-setup__mode--active': mode === 'link' }"
						:aria-pressed="mode === 'link'"
						:disabled="busy !== ''"
						@click="choose('link')">
						<IconLinkVariant class="account-setup__mode-icon" :size="20" />
						<span class="account-setup__mode-text">
							<span class="account-setup__mode-title">
								{{ t('social', 'Link an existing account') }}
							</span>
							<span class="account-setup__mode-desc">
								{{ t('social', 'An account on Mastodon or another server.') }}
							</span>
						</span>
					</button>
				</div>

				<AccountSetupForm
					v-if="mode === 'create'"
					v-model="handle"
					class="account-setup__form"
					:addressHost="addressHost"
					:blueskyEnabled="blueskyEnabled"
					:blueskyHost="blueskyHost"
					@busy="busy = $event ? 'create' : ''"
					@created="$emit('created', $event)" />

				<form v-else class="account-setup__form" @submit.prevent="link">
					<h2 class="account-setup__heading">
						{{ t('social', 'Your existing account') }}
					</h2>
					<p class="account-setup__hint">
						{{ t('social', 'This only shows your existing account on your Nextcloud profile so others can find it. You will not post from that external account here.') }}
					</p>
					<label class="account-setup__label" for="account-setup-linked">
						{{ t('social', 'Your address on the other server') }}
					</label>
					<input
						id="account-setup-linked"
						v-model.trim="linked"
						type="text"
						class="account-setup__input"
						autocomplete="off"
						spellcheck="false"
						placeholder="you@mastodon.social"
						:aria-invalid="linkError !== null"
						:disabled="busy !== ''">
					<ServerError v-if="linkError" class="account-setup__error" :error="linkError" />
					<NcButton type="submit" variant="secondary" :disabled="busy !== '' || linked === ''">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'link'" :size="20" />
							<IconLinkVariant v-else :size="20" />
						</template>
						{{ t('social', 'Link account') }}
					</NcButton>
				</form>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * Introduces the app before asking whether to create a local identity or
 * publish an existing external handle on the Nextcloud profile.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconAt from 'vue-material-design-icons/At.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconLinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import IconSwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import IconWeb from 'vue-material-design-icons/Web.vue'
import AccountSetupForm from '../components/AccountSetupForm.vue'
import ServerError from '../components/ServerError.vue'
import logger from '../services/logger.js'
import { useServerData } from '../composables/useServerData.js'
import { errorOf } from '../utils/serverError.js'

export default {
	name: 'AccountSetup',
	components: {
		AccountSetupForm,
		IconAccountPlus,
		IconAt,
		IconCheck,
		IconLinkVariant,
		IconSwapHorizontal,
		IconWeb,
		NcButton,
		NcLoadingIcon,
		ServerError,
	},

	emits: ['created'],

	/**
	 * Exposes instance configuration and the hostname used in address previews.
	 *
	 * @return {object} reactive server data and hostname
	 */
	setup() {
		const { serverData, hostname } = useServerData()

		return { serverData, hostname }
	},

	/**
	 * Seeds the forms from the suggested username and published external handle.
	 *
	 * @return {object} form values, selected path and request state
	 */
	data() {
		const serverData = useServerData().serverData.value

		return {
			handle: serverData.suggestedHandle ?? '',
			linked: serverData.linkedHandle ?? '',
			/** the account elsewhere the profile named when the page was opened */
			linkedBefore: serverData.linkedHandle ?? '',
			/** the account elsewhere just put on the profile from here */
			linkedNow: '',
			/** which of the two answers is on screen */
			mode: 'create',
			/** which of the two forms is talking to the server: 'create', 'link' or '' */
			busy: '',
			/** @type {{text: string, details: string}|null} */
			linkError: null,
		}
	},

	computed: {
		/**
		 * Controls whether the setup explains and previews the Bluesky identity.
		 *
		 * @return {boolean} whether this instance supports AT Protocol
		 */
		blueskyEnabled() {
			return this.serverData.bluesky?.enabled === true
		},

		/**
		 * The host the address ends in: the social address the administrator
		 * set, which may be another than this Nextcloud's own host.
		 *
		 * @return {string}
		 */
		addressHost() {
			return this.serverData.socialAddress || this.hostname
		},

		/**
		 * Resolves the Bluesky handle suffix, falling back to the instance hostname.
		 *
		 * @return {string} the host used by Bluesky handles
		 */
		blueskyHost() {
			return this.serverData.bluesky?.host ?? this.hostname
		},

		/**
		 * Where the way out of the app leads: the Nextcloud's own start page.
		 *
		 * @return {string}
		 */
		homeUrl() {
			return generateUrl('/')
		},
	},

	methods: {
		/**
		 * Selects a form and clears errors from the previous choice.
		 *
		 * @param {'create'|'link'} mode which form to show
		 * @return {void}
		 */
		choose(mode) {
			this.mode = mode
			this.linkError = null
		},

		/**
		 * Leaves the end state of linking for the creation form.
		 *
		 * @return {void}
		 */
		createInstead() {
			this.linkedNow = ''
			this.choose('create')
		},

		/**
		 * Publishes the external handle on the profile without creating an account.
		 *
		 * @return {Promise<void>}
		 */
		async link() {
			this.busy = 'link'
			this.linkError = null
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/account/link'), { handle: this.linked })
				this.linkedNow = data?.result?.handle ?? this.linked
				this.linkedBefore = this.linkedNow
			} catch (error) {
				logger.warn('Could not put the account on the profile', { error })
				this.linkError = errorOf(error, t('social', 'Could not save that'))
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
	flex-direction: column;
	align-items: center;
	padding: 80px 16px 24px;

	// the setup question fills the page, not the reading column: the app
	// centers its pages at 800px, and an explainer beside two answers
	// does not fit in it
	#app-content-vue &.social__wrapper {
		max-width: 1080px;
	}

	&__header {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 16px;
		width: 100%;
		max-width: 1024px;
		margin-bottom: 40px;
		text-align: center;
	}

	/* the app's own mark, drawn on the current colour like the sidebar's */
	&__logo {
		display: block;
		width: 48px;
		height: 48px;
		background-image: url('../../img/social-dark.svg');
		background-repeat: no-repeat;
		background-position: center;
		background-size: contain;
		filter: var(--background-invert-if-dark);
	}

	&__header-text {
		min-width: 0;
	}

	&__title {
		margin: 0 0 8px;
		font-size: 28px;
		font-weight: 700;
		line-height: 1.25;
		text-wrap: balance;
	}

	&__lead {
		margin: 0;
		line-height: 1.6;
		text-wrap: pretty;
	}

	// the explainer reads, the answers sit: two columns while there is
	// room, one below it
	&__body {
		display: grid;
		grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
		gap: 12px 32px;
		align-items: start;
		width: 100%;
		max-width: 1024px;

		@media (max-width: 760px) {
			grid-template-columns: minmax(0, 1fr);
		}
	}

	/* the explainer is quiet: one column of rows with an accent icon, no
	   card of its own, so the two answers stay the things that look
	   choosable */
	&__about {
		margin: 0;
		min-width: 0;
		text-align: center;
	}

	&__about-title {
		margin: 0 0 12px;
		font-size: 13px;
		font-weight: 600;
		letter-spacing: 0.02em;
		text-transform: uppercase;
		color: var(--color-text-maxcontrast);
	}

	&__points {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin: 0 0 12px;
		padding: 0;
		list-style: none;
	}

	&__point {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		text-align: center;
	}

	&__point-icon {
		flex: 0 0 auto;
		color: var(--color-primary-element);
	}

	&__point-text {
		min-width: 0;
	}

	&__point-title {
		margin: 0 0 2px;
		font-size: 14px;
		font-weight: 600;
	}

	&__point-text p {
		margin: 0;
		font-size: 13px;
		line-height: 1.45;
		color: var(--color-text-maxcontrast);
		text-wrap: pretty;
	}

	&__more {
		summary {
			cursor: pointer;
			font-size: 13px;
			font-weight: 600;
			color: var(--color-primary-element);
		}

		p {
			margin: 8px 0 0;
			font-size: 13px;
			line-height: 1.5;
			color: var(--color-text-maxcontrast);
			text-wrap: pretty;
		}
	}

	&__modes {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
		gap: 12px;
		max-width: 600px;
		margin: 0 auto;
	}

	&__mode {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 8px;
		padding: 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		text-align: center;
		color: var(--color-main-text);
		transition: border-color var(--social-motion-fast) var(--social-ease), background-color var(--social-motion-fast) var(--social-ease);

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}

		&--active {
			border-color: var(--color-primary-element);
			background: var(--color-background-hover);

			.account-setup__mode-icon {
				color: var(--color-primary-element);
			}
		}
	}

	&__mode-icon {
		flex: 0 0 auto;
		color: var(--color-text-maxcontrast);
	}

	&__mode-text {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__mode-title {
		font-weight: 600;
	}

	&__mode-desc {
		font-size: 13px;
		font-weight: 400;
		line-height: 1.4;
		color: var(--color-text-maxcontrast);
		text-wrap: pretty;
	}

	&__form {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		margin-top: 20px;
		padding: 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		max-width: 500px;
		width: 100%;
		text-align: center;
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

	&__input {
		width: 100%;
		min-width: 0;
		margin: 0;
		text-align: center;
	}

	&__hint {
		margin: 0;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
		text-align: center;
	}

	&__error {
		margin: 0;
		font-size: 13px;
		color: var(--color-error);
		text-align: center;
	}

	&__error {
		margin: 0;
	}

	&__linked p {
		margin: 0;
		line-height: 1.5;
		text-wrap: pretty;
	}

	&__linked-icon {
		color: var(--color-success-text);
	}

	&__linked-note {
		max-width: 600px;
		margin: 0 auto 12px;
		font-size: 13px;
		line-height: 1.5;
		color: var(--color-text-maxcontrast);
		text-align: center;
		text-wrap: pretty;
	}

	/* the chosen answer changing tint is the only movement on this page, and
	   it is one a reader who asked their system for less does without */
	@media (prefers-reduced-motion: reduce) {
		&__mode {
			transition: none;
		}
	}

	@media (max-width: 767px) {
		padding: 16px 8px;

		&__card {
			padding: 20px;
		}

		&__title {
			font-size: 24px;
		}
	}
}
</style>
