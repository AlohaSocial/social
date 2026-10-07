<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="oauth">
		<!--
			After the consent form is submitted, the out-of-band flow lands
			back here with a code. It used to be answered with a JSON body, so
			a browser drew `{"code":"..."}` on a blank document one click after
			being promised the code would be shown -- which reads as the button
			having done nothing at all.
		-->
		<div v-if="code" class="guest-box oauth__box">
			<header class="oauth__header">
				<span class="oauth__seal oauth__seal--success">
					<Check :size="32" />
				</span>
				<h1>{{ t('social', 'Authorized') }}</h1>
			</header>
			<p class="oauth__lead">
				{{ t('social', 'Paste this code into {appDisplayName} to finish signing in. It can be used once, and only by the application that asked for it.', { appDisplayName: appName }) }}
			</p>
			<!--
				A field rather than a <code> block: it is selectable, and a
				phone can pick it up with a tap. A textarea rather than an
				input, because the code is longer than the box is wide and an
				input answers that by cutting it off at an ellipsis -- so the
				one thing the page exists to show could not be read off screen.
			-->
			<textarea
				ref="code"
				class="code"
				rows="2"
				readonly
				:value="code"
				:aria-label="t('social', 'Authorization code')"
				@focus="selectCode" />
			<div class="button-row">
				<NcButton variant="primary" wide @click="copyCode">
					<template #icon>
						<Check v-if="copied" :size="20" />
						<ContentCopy v-else :size="20" />
					</template>
					{{ copied ? t('social', 'Copied') : t('social', 'Copy code') }}
				</NcButton>
			</div>
		</div>

		<form v-else class="guest-box oauth__box" method="post">
			<header class="oauth__header">
				<!--
					An application registers no logo, so the mark is the letter
					its name starts with -- enough to tell two requests apart at
					a glance, and honest about being no logo at all.
				-->
				<span class="oauth__seal oauth__seal--app" aria-hidden="true">
					{{ appInitial }}
				</span>
				<h1>{{ t('social', 'Authorization required') }}</h1>
				<a
					v-if="websiteLabel"
					class="oauth__website"
					:href="appWebsite"
					target="_blank"
					rel="noopener noreferrer">
					{{ websiteLabel }}
					<OpenInNew :size="14" />
				</a>
			</header>

			<p class="oauth__lead">
				{{ t('social', '{appDisplayName} would like permission to access your account. It is a third party application.', { appDisplayName: appName }) }}
			</p>

			<!-- which account is being handed over, not only which application asks -->
			<div v-if="account" class="account">
				<NcAvatar
					:user="account.uid"
					:displayName="account.displayName"
					:size="36"
					:disableMenu="true"
					:disableTooltip="true"
					:hideStatus="true" />
				<span class="account__text">
					<span class="account__name">{{ account.displayName }}</span>
					<span class="account__handle">{{ account.handle }}</span>
				</span>
			</div>

			<NcNoteCard type="warning">
				{{ t('social', 'If you do not trust it, then you should not authorize it.') }}
			</NcNoteCard>

			<h2>{{ t('social', 'This application will be able to:') }}</h2>
			<ul class="scopes">
				<li v-for="scope in scopes" :key="scope" class="scopes__item">
					<!--
						Two icons rather than one: a scope that only reads and a
						scope that acts as you are not the same grant, and a
						list where every line looks alike hides that.
					-->
					<span
						class="scopes__icon"
						:class="{ 'scopes__icon--write': changes(scope) }">
						<Pencil v-if="changes(scope)" :size="16" />
						<Eye v-else :size="16" />
					</span>
					<span class="scopes__text">
						<span class="scopes__label">{{ scopeLabel(scope) }}</span>
						<span class="scopes__name">{{ scope }}</span>
					</span>
				</li>
			</ul>

			<p class="target">
				<ArrowRightThin :size="20" />
				<span>{{ targetDescription }}</span>
			</p>

			<input
				type="hidden"
				name="requesttoken"
				:value="OC.requestToken">
			<div class="button-row">
				<!--
					Refusing is the safe answer, so it is not painted as the
					dangerous one; the grant is the button that carries weight.
				-->
				<NcButton :href="denyUrl">
					{{ t('social', 'Deny') }}
				</NcButton>
				<NcButton variant="primary" type="submit">
					{{ t('social', 'Authorize') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
import ArrowRightThin from 'vue-material-design-icons/ArrowRightThin.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const OUT_OF_BAND = 'urn:ietf:wg:oauth:2.0:oob'

export default {
	name: 'OAuth2Authorize',
	components: {
		ArrowRightThin,
		Check,
		ContentCopy,
		Eye,
		NcAvatar,
		NcButton,
		NcNoteCard,
		OpenInNew,
		Pencil,
	},

	data() {
		return {
			// present only on the page the consent form submits to, in the
			// out-of-band flow
			code: loadState('social', 'code', ''),
			copied: false,
			appName: loadState('social', 'appName'),
			appWebsite: loadState('social', 'appWebsite', ''),
			account: loadState('social', 'account', null),
			scopes: loadState('social', 'scopes', []),
			redirectUri: loadState('social', 'redirectUri', ''),
			// where refusing sends the browser: back to the client with
			// error=access_denied, which is the answer it is owed
			denyUrl: loadState('social', 'denyUrl', generateUrl('/apps/social/')),
		}
	},

	computed: {
		/**
		 * What the scopes mean, one line each. An unknown scope is shown as it
		 * was asked for rather than hidden — consent to something unnamed is
		 * not consent.
		 *
		 * @return {object} the label of every scope this server issues
		 */
		scopeLabels() {
			return {
				read: t('social', 'Read all of your data'),
				write: t('social', 'Act as you: post, upload and change anything of yours'),
				follow: t('social', 'Follow, block and mute accounts for you'),
				'read:accounts': t('social', 'Read your profile'),
				'read:blocks': t('social', 'See the accounts you block'),
				'read:bookmarks': t('social', 'See your bookmarks'),
				'read:collections': t('social', 'See your collections'),
				'read:favourites': t('social', 'See your favourites'),
				'read:filters': t('social', 'See your filters'),
				'read:follows': t('social', 'See who you follow and who follows you'),
				'read:lists': t('social', 'See your lists'),
				'read:mutes': t('social', 'See the accounts you mute'),
				'read:notifications': t('social', 'Read your notifications'),
				'read:search': t('social', 'Search as you'),
				'read:statuses': t('social', 'Read your posts and your timelines'),
				'read:stories': t('social', 'See your 24-hour shorts'),
				'write:accounts': t('social', 'Change your profile'),
				'write:blocks': t('social', 'Block and unblock accounts for you'),
				'write:bookmarks': t('social', 'Add and remove your bookmarks'),
				'write:collections': t('social', 'Create and change your collections'),
				'write:conversations': t('social', 'Manage your conversations'),
				'write:favourites': t('social', 'Favourite and unfavourite posts as you'),
				'write:filters': t('social', 'Create and change your filters'),
				'write:follows': t('social', 'Follow and unfollow accounts for you'),
				'write:lists': t('social', 'Create and change your lists'),
				'write:media': t('social', 'Upload files as you'),
				'write:mutes': t('social', 'Mute and unmute accounts and conversations for you'),
				'write:notifications': t('social', 'Dismiss your notifications'),
				'write:reports': t('social', 'Send reports as you'),
				'write:statuses': t('social', 'Publish, edit and delete posts as you'),
				'write:stories': t('social', 'Publish and delete 24-hour shorts as you'),
			}
		},

		/**
		 * @return {string} the letter the application's name starts with, for
		 *                  the mark above the heading
		 */
		appInitial() {
			return [...(this.appName ?? '')][0]?.toUpperCase() ?? '?'
		},

		/**
		 * @return {string} the application's website as a host, or '' when it
		 *                  registered none or registered something that is not
		 *                  a URL a browser would follow
		 */
		websiteLabel() {
			if (!this.appWebsite) {
				return ''
			}

			try {
				const url = new URL(this.appWebsite)
				if (url.protocol !== 'http:' && url.protocol !== 'https:') {
					return ''
				}

				return url.host
			} catch {
				return ''
			}
		},

		/**
		 * Where the authorization code is about to be sent — the one part of
		 * the request that decides who ends up holding it.
		 *
		 * @return {string} a sentence naming the destination
		 */
		targetDescription() {
			if (this.redirectUri === OUT_OF_BAND || this.redirectUri === '') {
				return t('social', 'The authorization code will be shown to you, to copy into the application yourself.')
			}

			return t('social', 'The authorization code will be sent to {target}.', { target: this.redirectTarget })
		},

		/**
		 * @return {string} the host the code travels to, or the whole URI when
		 *                  it has no host to name (a custom application scheme)
		 */
		redirectTarget() {
			try {
				const host = new URL(this.redirectUri).host
				if (host !== '') {
					return host
				}
			} catch {
				// not a URL this browser parses; show it whole
			}

			return this.redirectUri
		},
	},

	methods: {
		scopeLabel(scope) {
			return this.scopeLabels[scope] ?? scope
		},

		/**
		 * @param {string} scope one of the scopes being asked for
		 * @return {boolean} whether it lets the application act rather than
		 *                   only read
		 */
		changes(scope) {
			return scope === 'write'
				|| scope === 'follow'
				|| scope === 'push'
				|| scope.startsWith('write:')
				|| scope.startsWith('admin')
		},

		/** Focusing the field selects the whole code, so one tap picks it up. */
		selectCode() {
			/** @type {HTMLInputElement|undefined} */ (this.$refs.code)?.select()
		},

		async copyCode() {
			try {
				await navigator.clipboard.writeText(this.code)
				this.copied = true
			} catch {
				// no clipboard permission, or an insecure context: the field is
				// selectable and the code is on screen either way
				this.selectCode()
			}
		},
	},
}
</script>

<style lang="scss" scoped>
/* `scopped` for a long time, which quietly published all of this to every
   page that ever loaded this bundle. */
// `.wrapper` before, which is also what Nextcloud's guest layout calls its
// own container -- two elements one selector apart, which made this hard to
// look at in a browser.
.oauth {
	display: flex;
	flex-direction: column;
	align-items: center;
	// no viewport height here: the guest layout already places and centres
	// what it is given, and a second full-screen box inside it pushed the
	// form below the fold with its buttons off the bottom of the window
	inline-size: 100%;
}

.oauth__box {
	color: var(--color-main-text);
	background-color: var(--color-main-background);
	padding: 24px;
	border-radius: var(--border-radius-container, var(--border-radius-large));
	box-shadow: 0 0 10px var(--color-box-shadow);
	inline-size: 100%;
	max-width: 480px;
	// the guest layout centres everything it contains; a list of permissions
	// read as a stack of centred receipt lines, so the body is set back to
	// reading order and only the heading stays centred
	text-align: start;

	h1 {
		font-weight: bold;
		text-align: center;
		font-size: 20px;
		line-height: 140%;
		margin: 0;
	}

	h2 {
		font-weight: bold;
		font-size: 15px;
		margin-block: 20px 8px;
	}
}

.oauth__header {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	margin-block-end: 12px;
}

.oauth__seal {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	inline-size: 56px;
	block-size: 56px;
	border-radius: 50%;

	&--app {
		font-size: 24px;
		font-weight: bold;
		line-height: 1;
		color: var(--color-primary-element);
		background-color: var(--color-primary-element-light);
	}

	&--success {
		color: var(--color-success-text, var(--color-success));
		background-color: var(--color-success-hover, var(--color-background-hover));
	}
}

.oauth__website {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 90%;
	text-decoration: none;
	overflow-wrap: anywhere;

	&:hover,
	&:focus-visible {
		color: var(--color-main-text);
		text-decoration: underline;
	}
}

.oauth__lead {
	text-align: center;
	line-height: 150%;
}

.account {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-block-start: 16px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);

	&__text {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
	}

	&__name {
		font-weight: bold;
	}

	&__handle {
		color: var(--color-text-maxcontrast);
		font-size: 90%;
		overflow-wrap: anywhere;
	}
}

.scopes {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__item {
		display: flex;
		align-items: flex-start;
		gap: 10px;
	}

	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		inline-size: 24px;
		block-size: 24px;
		border-radius: 50%;
		color: var(--color-primary-element);
		background-color: var(--color-primary-element-light);

		&--write {
			color: var(--color-warning-text, var(--color-main-text));
			background-color: var(--color-warning-hover, var(--color-background-dark));
		}
	}

	&__text {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
	}

	&__label {
		line-height: 24px;
	}

	&__name {
		color: var(--color-text-maxcontrast);
		font-family: var(--font-face-monospace, monospace);
		font-size: 85%;
		overflow-wrap: anywhere;
	}
}

.target {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-block-start: 20px;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 90%;
	overflow-wrap: anywhere;

	:deep(.material-design-icon) {
		flex: 0 0 auto;
	}
}

.button-row {
	display: flex;
	gap: 8px;
	flex-direction: row;
	flex-wrap: wrap;
	margin-block-start: 20px;
	justify-content: end;

	// one full-width column rather than two buttons crowding one edge
	@media (max-width: 480px) {
		flex-direction: column-reverse;

		// NcButton sizes itself to its label, so stretching the row is not
		// enough to make the two buttons the same width
		:deep(.button-vue) {
			inline-size: 100%;
		}
	}
}

.code {
	display: block;
	inline-size: 100%;
	margin-block-start: 16px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 14px;
	line-height: 1.5;
	text-align: center;
	// a code with no spaces in it has nowhere to break on its own
	word-break: break-all;
	resize: none;
}
</style>
