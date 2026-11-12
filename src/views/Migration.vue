<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__migration">
		<PageHeader
			:title="t('social', 'Migration')"
			:description="t('social', 'Your account is yours. Take a copy of it whenever you like, move it to another server, or bring one here from somewhere else — including the posts you wrote there, with their pictures.')"
			clearNavigation />

		<MigrationSettings />
	</div>
</template>

<script>
import PageHeader from '../components/PageHeader.vue'
import { translate as t } from '@nextcloud/l10n'
import MigrationSettings from '../components/MigrationSettings.vue'

/**
 * Everything that moves an account in or out of this server.
 *
 * A page of its own rather than the twelfth section of Settings. Exporting an
 * archive, importing one, and bringing a following list over from another
 * network are each a job somebody sits down to do — with a file manager open
 * and an account on another server in the other tab — and none of them is a
 * setting to be changed in passing. They were at the bottom of a page whose
 * other eleven sections are switches, which is both hard to find when it is
 * wanted and in the way when it is not.
 *
 * `/settings#migration` still lands here, because it is what has been linked
 * to and bookmarked.
 */
export default {
	name: 'Migration',

	components: {
		PageHeader,
		MigrationSettings,
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.social__migration {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;
}

.social__migration :deep(.migration) {
	max-width: none;
	margin: 0;
	padding: 0;
}

.social__migration :deep(.migration__card) {
	margin-bottom: 14px;
	padding: 14px 16px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.social__migration :deep(.migration__card h4) {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}

.social__migration :deep(.migration__card h4 > :first-child) {
	box-sizing: border-box;
	flex: 0 0 auto;
	width: 32px;
	height: 32px;
	padding: 6px;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.social__migration :deep(.migration__card h5) {
	margin: 20px 0 6px;
	font-size: inherit;
	font-weight: 700;
}

.social__migration :deep(.migration__card p) {
	margin-bottom: 12px;
}
</style>
