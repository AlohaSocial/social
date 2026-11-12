<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="about-card">
		<p class="about-card__readers">
			{{ t('social', 'Everything the About this server page shows the people here. Mastodon apps and other servers read the description, the contact and the rules too.') }}
		</p>

		<!-- what the "ready to be public" setup check asks for, a sentence each -->
		<NcNoteCard v-if="missingSentences.length > 0" type="warning" class="about-card__missing">
			<p v-for="sentence in missingSentences" :key="sentence">
				{{ sentence }}
			</p>
		</NcNoteCard>

		<h4 class="about-card__heading">
			{{ t('social', 'Name') }}
		</h4>
		<p class="about-card__value">
			{{ about.name || t('social', 'Not set') }}
			<a class="about-card__theming" :href="theming">{{ t('social', 'Change it in Theming') }}</a>
		</p>

		<template v-if="settings">
			<NcTextArea
				v-model="form.extendedDescription"
				class="about-card__field"
				:label="t('social', 'Description')"
				:placeholder="t('social', 'Who runs this server, who it is for, and what is expected of the people on it.')"
				:helperText="t('social', 'Shown on About this server and to Mastodon apps. Apps that list servers show the first paragraph.')"
				rows="4" />

			<h4 class="about-card__heading">
				{{ t('social', 'Contact') }}
			</h4>
			<NcTextField
				v-model="form.contactEmail"
				class="about-card__field"
				type="email"
				:label="t('social', 'Contact address')"
				placeholder="admin@instance.example"
				:helperText="t('social', 'Shown to anybody asking this server what it is. Clients read it on their first request.')" />

			<NcTextField
				v-model="form.contactAccount"
				class="about-card__field"
				:label="t('social', 'Contact account')"
				:placeholder="t('social', 'Local Aloha Social username')"
				:helperText="t('social', 'Shown to Mastodon-compatible clients as the account responsible for this instance. Leave empty to omit it.')" />

			<NcNoteCard v-if="message !== ''" :type="saved ? 'success' : 'error'">
				{{ message }}
			</NcNoteCard>

			<NcButton
				variant="primary"
				class="about-card__save"
				:disabled="saving"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</template>

		<h4 class="about-card__heading">
			{{ t('social', 'Server rules') }}
		</h4>
		<RulesSection />

		<h4 class="about-card__heading">
			{{ t('social', 'Privacy policy and legal notice') }}
		</h4>
		<ul class="about-card__links">
			<li>
				{{ t('social', 'Privacy policy:') }}
				<a
					v-if="about.privacy_policy_url"
					:href="about.privacy_policy_url"
					target="_blank"
					rel="noopener noreferrer">{{ about.privacy_policy_url }}</a>
				<span v-else class="about-card__unset">{{ t('social', 'Not set') }}</span>
			</li>
			<li>
				{{ t('social', 'Legal notice:') }}
				<a
					v-if="about.legal_notice_url"
					:href="about.legal_notice_url"
					target="_blank"
					rel="noopener noreferrer">{{ about.legal_notice_url }}</a>
				<span v-else class="about-card__unset">{{ t('social', 'Not set') }}</span>
			</li>
		</ul>
		<p class="about-card__value">
			<a class="about-card__theming" :href="theming">{{ t('social', 'Set the links in Theming') }}</a>
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import RulesSection from './RulesSection.vue'
import { aboutUrl, themingUrl } from '../../services/adminApi.js'
import { errorOf } from '../../utils/serverError.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * Everything the About this server page shows, in one place: the name, the
 * description, the contact, the rules, and the two legal links.
 *
 * The name and the links belong to Theming, and are shown here with a link
 * there rather than edited twice. The description and the contact are an
 * administrator's (`POST /admin/about`), so a delegate, who has no `settings`,
 * sees the rules, which moderators keep, and the rest read-only.
 */
export default {
	name: 'AboutSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcTextField,
		RulesSection,
	},

	props: {
		/** `ServerSettingsService::current()`, or null for a delegate */
		settings: {
			type: Object,
			default: null,
		},

		/** the name and the legal links, as Theming holds them */
		about: {
			type: Object,
			default: () => ({ name: '', privacy_policy_url: '', legal_notice_url: '' }),
		},

		/** what a public server should show and this one does not, as `AboutService::MISSING_*` */
		missing: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			form: {
				extendedDescription: this.settings?.extended_description ?? '',
				contactEmail: this.settings?.contact_email ?? '',
				contactAccount: this.settings?.contact_account ?? '',
			},

			message: '',
			saved: false,
			saving: false,
		}
	},

	computed: {
		/** @return {string[]} one sentence per missing item */
		missingSentences() {
			const sentences = {
				contact: t('social', 'No contact address or account is set.'),
				rules: t('social', 'No server rules are written.'),
				privacy_policy: t('social', 'No privacy policy link is set.'),
				legal_notice: t('social', 'No legal notice link is set.'),
			}

			return this.missing.map((basic) => sentences[basic]).filter(Boolean)
		},

		/** @return {string} Nextcloud's Theming settings */
		theming() {
			return themingUrl()
		},
	},

	methods: {
		t,

		/** @return {Promise<boolean>} whether it was taken */
		async save() {
			this.saving = true
			try {
				await axios.post(aboutUrl(), {
					extendedDescription: this.form.extendedDescription,
					contactEmail: this.form.contactEmail.trim(),
					contactAccount: this.form.contactAccount.trim().replace(/^@/, ''),
				})
				this.saved = true
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'What this server says about itself was saved'))

				return true
			} catch (error) {
				this.saved = false
				const reason = errorOf(error, t('social', 'Could not save what this server says about itself'), {
					contact_email: t('social', 'Contact address'),
					contact_account: t('social', 'Contact account'),
					extended_description: t('social', 'Description'),
				})
				this.message = reason.text
				showError(reason)

				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.about-card {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: stretch;
	max-width: 640px;

	&__readers,
	&__value {
		margin: 0;
	}

	&__readers,
	&__unset {
		color: var(--color-text-maxcontrast);
	}

	&__heading {
		margin-block: 16px 0;
		font-weight: bold;
	}

	&__theming {
		margin-inline-start: 8px;
		text-decoration: underline;
	}

	&__missing p {
		margin: 0;
	}

	&__save {
		align-self: flex-start;
	}

	&__links {
		margin: 0;
		padding: 0;
		list-style: none;

		a {
			text-decoration: underline;
			overflow-wrap: anywhere;
		}
	}
}
</style>
