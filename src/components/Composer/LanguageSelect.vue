<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<!-- The code as the badge, as in Mastodon's composer; the name is on the
	     title and the accessible name. No `menuName`: it would replace the
	     label and leave a screen reader announcing "DE". -->
	<NcActions
		variant="tertiary"
		class="language-select"
		:title="currentName"
		:ariaLabel="t('social', 'Language of the post: {language}', { language: currentName })">
		<template #icon>
			<span class="language-select__current" aria-hidden="true">{{ language }}</span>
		</template>
		<NcActionButton
			v-for="option of options"
			:key="option.code"
			:class="{ 'selected-language': option.code === language }"
			:closeAfterClick="true"
			@click="choose(option.code)">
			<template #icon>
				<Check
					v-if="option.code === language"
					:size="20"
					decorative
					title="" />
			</template>
			{{ option.name }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import Check from 'vue-material-design-icons/Check.vue'
import { translate as t } from '@nextcloud/l10n'
import { POST_LANGUAGES, languageName, rememberLanguage } from '../../utils/postLanguage.js'

export default {
	name: 'LanguageSelect',
	components: {
		Check,
		NcActions,
		NcActionButton,
	},

	props: {
		/** the language the post will be sent with, a two-letter code */
		language: {
			type: String,
			required: true,
		},
	},

	emits: ['update:language'],

	computed: {
		/** @return {string} the current language, named */
		currentName() {
			return languageName(this.language)
		},

		/**
		 * The languages on offer, named in the reader's language and sorted. The
		 * current one is always among them, even when it is not a known code.
		 *
		 * @return {Array<{code: string, name: string}>}
		 */
		options() {
			const codes = POST_LANGUAGES.includes(this.language)
				? POST_LANGUAGES
				: [this.language, ...POST_LANGUAGES]

			return codes
				.map((code) => ({ code, name: languageName(code) }))
				.sort((a, b) => a.name.localeCompare(b.name))
		},
	},

	methods: {
		t,

		/** @param {string} code the language chosen */
		choose(code) {
			this.$emit('update:language', code)
			rememberLanguage(code)
		},
	},
}
</script>

<style scoped lang="scss">
.selected-language {
	outline: 1px solid var(--color-success);
	border-radius: 6px;
	background: var(--color-background-hover);
}

.language-select__current {
	display: inline-block;
	min-width: 20px;
	font-size: 11px;
	font-weight: 600;
	line-height: 20px;
	text-align: center;
	text-transform: uppercase;
}
</style>
