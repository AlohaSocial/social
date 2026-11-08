<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="about">
		<h1>{{ t('social', 'About this server') }}</h1>

		<NcLoadingIcon v-if="loading" :size="32" />
		<NcNoteCard v-else-if="failed" type="error">
			{{ t('social', 'Could not load what this server says about itself.') }}
		</NcNoteCard>

		<template v-else>
			<p v-if="about.name" class="about__name">
				{{ about.name }}
			</p>
			<p v-if="about.description" class="about__description">
				{{ about.description }}
			</p>

			<section class="about__section" aria-labelledby="about-rules">
				<h2 id="about-rules">
					{{ t('social', 'Server rules') }}
				</h2>
				<ol v-if="about.rules.length > 0" class="about__rules">
					<li v-for="rule in about.rules" :key="rule.id">
						{{ rule.text }}
					</li>
				</ol>
				<p v-else class="about__empty">
					{{ t('social', 'The administrators have not written any rules yet.') }}
				</p>
			</section>

			<section class="about__section" aria-labelledby="about-contact">
				<h2 id="about-contact">
					{{ t('social', 'Contact') }}
				</h2>
				<ul v-if="hasContact" class="about__list">
					<li v-if="about.contact.email">
						<a :href="'mailto:' + about.contact.email">{{ about.contact.email }}</a>
					</li>
					<li v-if="about.contact.account">
						<router-link :to="{ name: 'profile', params: { account: about.contact.account.username } }">
							{{ about.contact.account.display_name || about.contact.account.username }}
							<span class="about__handle">@{{ about.contact.account.username }}</span>
						</router-link>
					</li>
				</ul>
				<p v-else class="about__empty">
					{{ t('social', 'No contact has been given.') }}
				</p>
			</section>

			<section v-if="about.privacy_policy_url || about.legal_notice_url" class="about__section" aria-labelledby="about-legal">
				<h2 id="about-legal">
					{{ t('social', 'Legal') }}
				</h2>
				<ul class="about__list">
					<li v-if="about.privacy_policy_url">
						<a :href="about.privacy_policy_url" target="_blank" rel="noopener noreferrer">{{ t('social', 'Privacy policy') }}</a>
					</li>
					<li v-if="about.legal_notice_url">
						<a :href="about.legal_notice_url" target="_blank" rel="noopener noreferrer">{{ t('social', 'Legal notice') }}</a>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import logger from '../services/logger.js'

/**
 * The server's own page for the people on it: its rules, who to contact,
 * and its privacy policy and legal notice where the administrators set them.
 */
export default {
	name: 'About',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			failed: false,
			about: {
				name: '',
				description: '',
				rules: [],
				contact: { email: '', account: null },
				privacy_policy_url: '',
				legal_notice_url: '',
			},
		}
	},

	computed: {
		hasContact() {
			return this.about.contact.email !== '' || this.about.contact.account !== null
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(generateUrl('apps/social/about/info'))
			this.about = { ...this.about, ...data, contact: { ...this.about.contact, ...(data?.contact ?? {}) } }
		} catch (error) {
			logger.error('Could not load the about page', { error })
			this.failed = true
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.about {
	max-width: 720px;
	margin: 0 auto;
	padding: calc(var(--default-grid-baseline) * 4);

	&__name {
		font-weight: bold;
	}

	&__description {
		white-space: pre-line;
	}

	&__section {
		margin-top: calc(var(--default-grid-baseline) * 6);
	}

	&__rules {
		padding-inline-start: calc(var(--default-grid-baseline) * 6);
		list-style: decimal;

		li {
			margin-bottom: var(--default-grid-baseline);
		}
	}

	&__list {
		list-style: none;
		padding: 0;

		a {
			text-decoration: underline;
		}
	}

	&__handle,
	&__empty {
		color: var(--color-text-maxcontrast);
	}
}
</style>
