<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__migration">
		<h2 class="social__migration-title">
			{{ t('social', 'Migration') }}
		</h2>
		<p class="social__migration-lede">
			{{ t('social', 'Your account is yours. Take a copy of it whenever you like, move it to another server, or bring one here from somewhere else — including the posts you wrote there, with their pictures.') }}
		</p>

		<MigrationSettings />
	</div>
</template>

<script>
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
		MigrationSettings,
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.social__migration {
	max-width: 47rem;
	margin: 0 auto;
	padding: 1.5rem 1rem 3rem;
}

.social__migration-title {
	margin: 0 0 0.35rem;
	font-size: 1.6rem;
	font-weight: 700;
}

.social__migration-lede {
	max-width: 42rem;
	margin: 0 0 1.75rem;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}
</style>
