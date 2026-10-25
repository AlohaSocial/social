<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<template v-if="enabled">
		<NcActionButton
			:disabled="loading || context?.similarity_available !== true"
			closeAfterClick
			@click="$emit('feedback', 'MORE_SIMILAR')">
			<template #icon>
				<ThumbUpOutline :size="20" />
			</template>
			{{ t('social', 'Show me more like this post') }}
		</NcActionButton>
		<NcActionButton
			:disabled="loading || context?.similarity_available !== true"
			closeAfterClick
			@click="$emit('feedback', 'LESS_SIMILAR')">
			<template #icon>
				<ThumbDownOutline :size="20" />
			</template>
			{{ t('social', 'Show me less like this post') }}
		</NcActionButton>
		<NcActionButton closeAfterClick @click="$emit('feedback', 'HIDE_POST')">
			<template #icon>
				<EyeOffOutline :size="20" />
			</template>
			{{ t('social', 'Hide this post from For You') }}
		</NcActionButton>
		<NcActionButton closeAfterClick @click="$emit('feedback', 'MORE_AUTHOR')">
			<template #icon>
				<AccountPlusOutline :size="20" />
			</template>
			{{ t('social', 'Show me more from this author') }}
		</NcActionButton>
		<NcActionButton
			:disabled="loading || !context?.format"
			closeAfterClick
			@click="$emit('feedback', 'MORE_FORMAT')">
			<template #icon>
				<ShapeOutline :size="20" />
			</template>
			{{ t('social', 'Show me more {format}', { format: formatLabel(context?.format ?? '') }) }}
		</NcActionButton>
	</template>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ThumbDownOutline from 'vue-material-design-icons/ThumbDownOutline.vue'
import ThumbUpOutline from 'vue-material-design-icons/ThumbUpOutline.vue'

export default {
	name: 'RecommendationFeedbackActions',
	components: {
		AccountPlusOutline,
		EyeOffOutline,
		NcActionButton,
		ShapeOutline,
		ThumbDownOutline,
		ThumbUpOutline,
	},

	props: {
		enabled: { type: Boolean, default: false },
		loading: { type: Boolean, default: false },
		context: { type: Object, default: null },
	},

	emits: ['feedback'],
	methods: {
		t,
		/** @param {string} format the server-detected post format @return {string} */
		formatLabel(format) {
			return {
				audio: t('social', 'audio posts'),
				image: t('social', 'photo posts'),
				poll: t('social', 'polls'),
				short: t('social', 'short videos'),
				text: t('social', 'text posts'),
				video: t('social', 'video posts'),
			}[format] || t('social', 'posts like this')
		},
	},
}
</script>
