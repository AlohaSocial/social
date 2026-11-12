<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="use-in-app">
		<h3 v-if="titled" class="use-in-app__title">
			{{ t('social', 'Use it in an app') }}
		</h3>

		<p v-if="addresses === null" class="use-in-app__hint">
			{{ t('social', 'Loading …') }}
		</p>
		<p v-else-if="!addresses.mastodon && !addresses.bluesky" class="use-in-app__hint">
			{{ t('social', 'Apps cannot sign in to this server yet. The administrators can set it up for them.') }}
		</p>

		<template v-else>
			<div class="use-in-app__way">
				<template v-if="addresses.mastodon">
					<p class="use-in-app__lead">
						{{ t('social', 'In a Mastodon app, choose to sign in to another server and enter:') }}
					</p>
					<div class="use-in-app__address">
						<code class="use-in-app__code">{{ addresses.mastodon }}</code>
						<NcButton
							variant="tertiary"
							:title="copied === 'mastodon' ? t('social', 'Copied') : t('social', 'Copy')"
							:aria-label="copied === 'mastodon' ? t('social', 'Copied') : t('social', 'Copy the server address')"
							@click="copy('mastodon')">
							<template #icon>
								<IconCheck v-if="copied === 'mastodon'" :size="16" />
								<IconContentCopy v-else :size="16" />
							</template>
						</NcButton>
					</div>
					<p class="use-in-app__hint">
						{{ t('social', 'The app opens this server’s sign-in page in your browser, so your password is never given to the app.') }}
					</p>
				</template>
				<p v-else class="use-in-app__hint">
					{{ t('social', 'Mastodon apps cannot sign in to this server yet.') }}
				</p>
			</div>

			<div v-if="addresses.bluesky" class="use-in-app__way">
				<p class="use-in-app__lead">
					{{ t('social', 'In a Bluesky app, choose another hosting provider and enter:') }}
				</p>
				<div class="use-in-app__address">
					<code class="use-in-app__code">{{ addresses.bluesky }}</code>
					<NcButton
						variant="tertiary"
						:title="copied === 'bluesky' ? t('social', 'Copied') : t('social', 'Copy')"
						:aria-label="copied === 'bluesky' ? t('social', 'Copied') : t('social', 'Copy the hosting provider address')"
						@click="copy('bluesky')">
						<template #icon>
							<IconCheck v-if="copied === 'bluesky'" :size="16" />
							<IconContentCopy v-else :size="16" />
						</template>
					</NcButton>
				</div>
				<p class="use-in-app__hint">
					{{ t('social', 'Then sign in with your Bluesky handle and an app password, never your Nextcloud password.') }}
					<router-link class="use-in-app__link" :to="{ name: 'settings', hash: '#bluesky' }">
						{{ t('social', 'Make an app password') }}
					</router-link>
				</p>
			</div>

			<h4 class="use-in-app__subtitle">
				{{ t('social', 'Apps known to work') }}
			</h4>
			<ul class="use-in-app__apps">
				<li v-for="app in knownApps" :key="app.id">
					{{ app.name }}
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'

/**
 * What to type into a phone or desktop app to use this account there, and
 * which apps are known to work.
 *
 * The addresses are `AboutService::appAddresses()`: a Mastodon app's only
 * once the client API answers at the domain root, a Bluesky app's only while
 * Bluesky is on. A page that already has them from `/about/info` hands them
 * in; anywhere else the card asks for them itself.
 */
export default {
	name: 'UseInAppCard',

	components: {
		IconCheck,
		IconContentCopy,
		NcButton,
	},

	props: {
		/** `{mastodon, bluesky}` as `/about/info` answers them; null to fetch */
		apps: {
			type: Object,
			default: null,
		},

		/** whether the card carries its own heading */
		titled: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			/** @type {{mastodon: string, bluesky: string}|null} */
			fetched: null,
			/** @type {string} the address just copied, '' for none */
			copied: '',
			copyTimer: null,
		}
	},

	computed: {
		/** @return {{mastodon: string, bluesky: string}|null} null while loading */
		addresses() {
			if (this.apps !== null) {
				return { mastodon: this.apps.mastodon ?? '', bluesky: this.apps.bluesky ?? '' }
			}

			return this.fetched
		},

		/** @return {Array<{id: string, name: string}>} the apps that can use the addresses shown */
		knownApps() {
			const apps = []
			if (this.addresses?.mastodon) {
				apps.push(
					{ id: 'aloha-apple', name: t('social', 'Aloha Social for iPhone, iPad and Mac') },
					{ id: 'aloha-android', name: t('social', 'Aloha Social for Android') },
					{ id: 'web', name: t('social', 'Elk and Phanpy, in a browser') },
				)
			}
			if (this.addresses?.bluesky) {
				apps.push({ id: 'bluesky', name: t('social', 'The Bluesky app') })
			}

			return apps
		},
	},

	mounted() {
		if (this.apps === null) {
			this.load()
		}
	},

	beforeUnmount() {
		window.clearTimeout(this.copyTimer)
	},

	methods: {
		t,

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/about/info'))
				this.fetched = {
					mastodon: data?.apps?.mastodon ?? '',
					bluesky: data?.apps?.bluesky ?? '',
				}
			} catch (error) {
				logger.error('could not load the app addresses', { error })
				this.fetched = { mastodon: '', bluesky: '' }
			}
		},

		/**
		 * @param {'mastodon'|'bluesky'} which the address to copy
		 * @return {Promise<void>}
		 */
		async copy(which) {
			try {
				await navigator.clipboard.writeText(this.addresses[which])
				this.copied = which
				window.clearTimeout(this.copyTimer)
				this.copyTimer = window.setTimeout(() => {
					this.copied = ''
				}, 2000)
			} catch (error) {
				logger.debug('could not copy the app address', { error })
				showError(t('social', 'Could not copy — select the address and copy it yourself'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.use-in-app {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 3);
	max-width: 640px;

	&__title,
	&__subtitle {
		margin: 0;
		font-weight: bold;
	}

	&__title {
		font-size: 1.1em;
	}

	&__lead,
	&__hint {
		margin: 0;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__link {
		text-decoration: underline;
	}

	&__way {
		display: flex;
		flex-direction: column;
		gap: var(--default-grid-baseline);
	}

	&__address {
		display: flex;
		align-items: center;
		gap: var(--default-grid-baseline);
	}

	&__code {
		font-size: 1.1em;
		overflow-wrap: anywhere;
	}

	&__apps {
		margin: 0;
		padding-inline-start: calc(var(--default-grid-baseline) * 5);
		list-style: disc;
	}
}
</style>
