<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcActions variant="tertiary" :menuName="selectedVisibilityDetails.text" :aria-label="t('social', 'Choose a visibility')">
		<template #icon>
			<VisibilityIcon :visibility="selectedVisibilityDetails.id" :size="20" />
		</template>
		<NcActionButton
			v-for="visibilityDetails of visibilitiesInfo"
			:key="visibilityDetails.id"
			:class="{'selected-visibility': visibilityDetails.id === selectedVisibilityDetails.id}"
			:closeAfterClick="true"
			:description="reachesBluesky && !visibilityDetails.bluesky ? t('social', 'Not on Bluesky') : ''"
			@click="switchType(visibilityDetails)">
			<template #icon>
				<VisibilityIcon :visibility="visibilityDetails.id" :size="20" />
			</template>
			{{ visibilityDetails.description }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import { translate as t } from '@nextcloud/l10n'
import visibilitiesInfo from './VisibilitiesInfos.js'
import VisibilityIcon from './VisibilityIcon.vue'
import logger from '../../services/logger.js'
import { userKey } from '../../utils/browserStore.js'

export default {
	name: 'VisibilitySelect',
	components: {
		NcActions,
		NcActionButton,
		VisibilityIcon,
	},

	props: {
		visibility: {
			type: String,
			required: true,
		},

		/**
		 * Whether a public post from this account goes to Bluesky. The other
		 * audiences then say that they do not: Bluesky followers are
		 * followers too, and a followers-only post never reaches them.
		 */
		reachesBluesky: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:visibility'],

	data() {
		return {
			visibilitiesInfo,
		}
	},

	computed: {
		/**
		 * The visibility on the button, with a fallback for an unknown id (a
		 * draft written by another version), since the template reads `.text`.
		 *
		 * @return {import('./VisibilitiesInfos.js').Visibility}
		 */
		selectedVisibilityDetails() {
			return visibilitiesInfo.find(({ id }) => this.visibility === id)
				?? visibilitiesInfo.find(({ id }) => id === 'followers')
		},
	},

	methods: {
		switchType(visibility) {
			this.$emit('update:visibility', visibility.id)
			try {
				// throws in a private window or where site data is blocked; the
				// choice still takes effect
				localStorage.setItem(userKey('social.lastPostType'), visibility.id)
			} catch (error) {
				logger.debug('Could not remember the visibility', { error })
			}
		},

		t,
	},
}
</script>

<style scoped>
.selected-visibility {
	outline: 1px solid var(--color-success);
	border-radius: 6px;
	background: var(--color-background-hover);
}
</style>
