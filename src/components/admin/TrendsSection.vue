<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<h4 class="trends__heading">
			{{ t('social', 'Trending now') }}
		</h4>

		<NcEmptyContent
			v-if="trending.length === 0"
			:name="t('social', 'Nothing is trending.')"
			:description="t('social', 'On a quiet server this is the usual answer.')">
			<template #icon>
				<IconTrendingUp :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="trends__list">
			<li v-for="tag in trending" :key="tag.hashtag" class="trends__item">
				<span class="trends__name">#{{ tag.hashtag }}</span>
				<NcButton :disabled="busy" @click="reject('tag', tag.hashtag)">
					{{ t('social', 'Keep out') }}
				</NcButton>
			</li>
		</ul>

		<h4 class="trends__heading">
			{{ t('social', 'Kept out') }}
		</h4>

		<NcEmptyContent
			v-if="rejected.length === 0"
			:name="t('social', 'Nothing is kept out.')">
			<template #icon>
				<IconCheckCircle :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="trends__list">
			<li v-for="one in rejected" :key="one.kind + one.ref" class="trends__item">
				<span class="trends__kind">{{ kindLabel(one.kind) }}</span>
				<span class="trends__name">{{ one.ref }}</span>
				<span v-if="one.moderator" class="trends__by">{{ one.moderator }}</span>
				<NcButton :disabled="busy" @click="allow(one)">
					{{ t('social', 'Let back in') }}
				</NcButton>
			</li>
		</ul>

		<div class="trends__add">
			<NcSelect
				v-model="newKind"
				class="trends__kind-select"
				:options="kinds"
				label="label"
				:clearable="false"
				:aria-label="t('social', 'What kind of thing')" />
			<NcTextField
				v-model="newRef"
				class="trends__ref"
				:label="t('social', 'The hashtag, link or post address')"
				:disabled="busy" />
			<NcButton :disabled="busy || newRef.trim() === ''" @click="rejectTyped">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Keep out') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import IconTrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { moderationUrl } from '../../services/adminApi.js'
import { showError } from '../../services/toast.js'

/**
 * Keeping something out of what is trending.
 *
 * What is rejected is shown as one list whatever kind it is, because the
 * question a moderator has is "what am I keeping out of Explore" and not "what
 * hashtags am I keeping out of Explore". Rejecting is offered against the live
 * trending list, which is where a moderator will be looking when they need it,
 * and by typing, which is how a link or a post gets kept out — neither of those
 * has a list here to click.
 */
export default {
	name: 'TrendsSection',

	components: {
		IconCheckCircle,
		IconTrendingUp,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			decisions: { tags: [], links: [], statuses: [] },
			trending: [],
			kinds: [
				{ id: 'tag', label: t('social', 'Hashtag') },
				{ id: 'link', label: t('social', 'Link') },
				{ id: 'status', label: t('social', 'Post') },
			],

			newKind: null,
			newRef: '',
			busy: false,
		}
	},

	computed: {
		/** @return {Array} everything kept out, of whatever kind */
		rejected() {
			const all = []
			for (const [plural, kind] of [['tags', 'tag'], ['links', 'link'], ['statuses', 'status']]) {
				for (const one of this.decisions[plural] ?? []) {
					if (!one.approved) {
						all.push({ ...one, kind })
					}
				}
			}

			return all
		},
	},

	mounted() {
		this.newKind = this.kinds[0]
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {string} kind one of the three
		 * @return {string} its name, for a reader
		 */
		kindLabel(kind) {
			return this.kinds.find((one) => one.id === kind)?.label ?? kind
		},

		/** @return {Promise<void>} */
		async load() {
			try {
				const { data } = await axios.get(moderationUrl('/trends'))
				this.decisions = {
					tags: data.tags ?? [],
					links: data.links ?? [],
					statuses: data.statuses ?? [],
				}
				this.trending = data.trending ?? []
			} catch {
				showError(t('social', 'Could not load what is trending'))
			}
		},

		/**
		 * @param {string} kind which of the three
		 * @param {string} ref what to keep out
		 * @return {Promise<void>}
		 */
		async reject(kind, ref) {
			this.busy = true
			try {
				const { data } = await axios.post(moderationUrl('/trends'), { kind, ref })
				this.apply(data)
			} catch (error) {
				showError(error.response?.data?.error ?? t('social', 'Could not keep that out'))
			} finally {
				this.busy = false
			}
		},

		/** @return {Promise<void>} */
		async rejectTyped() {
			await this.reject(this.newKind?.id ?? 'tag', this.newRef.trim())
			this.newRef = ''
		},

		/**
		 * @param {object} one the decision to forget
		 * @return {Promise<void>}
		 */
		async allow(one) {
			this.busy = true
			try {
				const { data } = await axios.delete(moderationUrl('/trends'), {
					data: { kind: one.kind, ref: one.ref },
				})
				this.apply(data)
			} catch {
				showError(t('social', 'Could not let that back in'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} data what the server answered with
		 */
		apply(data) {
			this.decisions = {
				tags: data.tags ?? [],
				links: data.links ?? [],
				statuses: data.statuses ?? [],
			}
			this.trending = data.trending ?? []
		},
	},
}
</script>

<style lang="scss" scoped>
.trends__heading {
	margin-block: 12px 4px;
}

.trends__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.trends__item {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.trends__kind,
.trends__by {
	color: var(--color-text-maxcontrast);
}

.trends__name {
	font-weight: bold;
	word-break: break-all;
}

.trends__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 12px;
}

.trends__kind-select {
	min-width: 140px;
}

.trends__ref {
	max-width: 420px;
}
</style>
