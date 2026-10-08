<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<p v-if="!storage" class="social-admin__hint">
			{{ t('social', 'Nothing has been measured yet. The background job adds this up once a day; it will appear after the next run.') }}
		</p>
		<template v-else>
			<div class="storage">
				<div class="storage__figure">
					<span class="storage__number">{{ human(localBytes) }}</span>
					<span class="storage__label">{{ t('social', 'posted from here') }}</span>
					<span class="storage__sub">{{ n('social', '%n file', '%n files', localFiles) }}</span>
				</div>
				<div class="storage__figure">
					<span class="storage__number">{{ human(remoteBytes) }}</span>
					<span class="storage__label">{{ t('social', 'cached from other servers') }}</span>
					<span class="storage__sub">{{ n('social', '%n file', '%n files', remoteFiles) }}</span>
				</div>
			</div>
			<p class="social-admin__hint">
				{{ t('social', 'What was posted from here is somebody\'s own work and is not going anywhere. The cached half is copies of other servers\' pictures, and is what Retention above removes — a shorter retention is the lever for this number.') }}
			</p>
			<p class="social-admin__hint">
				{{ t('social', 'Measured {when}.', { when: measuredAt }) }}
				<span v-if="storage.missing > 0">
					{{ n('social', '%n file named in the database was not on disk.', '%n files named in the database were not on disk.', storage.missing) }}
				</span>
			</p>
		</template>

		<!-- who is holding the video. "Social is using 400 GB" is not
		     actionable; "this account is holding 380 of it" is -->
		<template v-if="videoStorage">
			<h4 class="storage__heading">
				{{ t('social', 'Video') }}
			</h4>
			<p class="social-admin__hint">
				{{ quotaLine }}
				<span v-if="videoStorage.renditions > 0">
					{{ t('social', 'The ladders of smaller sizes hold {size} on top of that; they are this server\'s own copies and are rebuilt if deleted.', { size: human(videoStorage.renditions) }) }}
				</span>
			</p>
			<p v-if="videoStore" class="social-admin__hint">
				{{ videoStore }}
			</p>
			<p v-if="videoAccounts.length === 0" class="social-admin__hint">
				{{ t('social', 'No account here is holding any video.') }}
			</p>
			<table v-else class="storage__accounts">
				<thead>
					<tr>
						<th scope="col">
							{{ t('social', 'Account') }}
						</th>
						<th scope="col" class="storage__amount">
							{{ t('social', 'Video held') }}
						</th>
						<th scope="col" class="storage__amount">
							{{ t('social', 'Files') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in videoAccounts" :key="row.account">
						<td>{{ row.account }}</td>
						<td class="storage__amount">
							{{ human(row.bytes) }}
						</td>
						<td class="storage__amount">
							{{ row.files }}
						</td>
					</tr>
				</tbody>
			</table>
			<p class="social-admin__hint">
				{{ t('social', 'Counted from the size recorded on each stored file. A video uploaded before this app recorded sizes counts as nothing until the background job has been past it, which it does once a day.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { humanSize } from '../../utils/humanSize.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * Two numbers about disk, and which of them an administrator can act on.
 *
 * The split is the point. "Social is using 40 GB" is not actionable; "31 GB of
 * it is cached copies of other servers' pictures, and Retention is the lever"
 * is.
 */
export default {
	name: 'StorageSection',

	components: {
	},

	props: {
		/** the last measurement, or null when none has been taken */
		storage: {
			type: Object,
			default: null,
		},

		/**
		 * Who is holding video, the quota they are held to, and where the
		 * store actually is. Null for a delegate, who moderates rather than
		 * administers.
		 */
		videoStorage: {
			type: Object,
			default: null,
		},
	},

	computed: {
		localBytes() {
			return this.sideBytes('local')
		},

		localFiles() {
			return this.sideFiles('local')
		},

		remoteBytes() {
			return this.sideBytes('remote')
		},

		remoteFiles() {
			return this.sideFiles('remote')
		},

		/** @return {Array<{account: string, bytes: number, files: number}>} */
		videoAccounts() {
			return Array.isArray(this.videoStorage?.accounts) ? this.videoStorage.accounts : []
		},

		/** @return {string} what the quota is, or that there is not one */
		quotaLine() {
			const quota = this.videoStorage?.quota ?? 0

			return quota > 0
				? t('social', 'Each account may keep {quota} of video here.', { quota: this.human(quota * 1048576) })
				: t('social', 'No per-account video quota is set, so the only limit is the disk. Set one under Server above.')
		},

		/**
		 * Where the files are. Nextcloud has no per-app object store — this
		 * says whether the instance-wide one is in force, which is the
		 * supported way to put this somewhere other than the data directory.
		 *
		 * @return {string}
		 */
		videoStore() {
			const store = this.videoStorage?.store
			if (!store) {
				return ''
			}

			if (!store.object_store) {
				return t('social', 'Stored in this instance\'s data directory. To put it on object storage, configure "objectstore" in config.php — that covers this app along with everything else; there is no per-app setting.')
			}

			return store.bucket
				? t('social', 'Stored on object storage ({class}, bucket {bucket}).', { class: store.class, bucket: store.bucket })
				: t('social', 'Stored on object storage ({class}).', { class: store.class })
		},

		/** @return {string} when it was measured, in the reader's own format */
		measuredAt() {
			const when = new Date((this.storage?.measured ?? 0) * 1000)

			return isNaN(when.getTime()) ? '' : when.toLocaleString()
		},
	},

	methods: {
		t,
		n,

		/**
		 * @param {string} side 'local' or 'remote'
		 * @return {number} the bytes on that side, attachments and avatars
		 */
		sideBytes(side) {
			const half = this.storage?.[side] ?? {}

			return (half.attachments?.bytes ?? 0) + (half.avatars?.bytes ?? 0)
		},

		/**
		 * @param {string} side 'local' or 'remote'
		 * @return {number} the files on that side
		 */
		sideFiles(side) {
			const half = this.storage?.[side] ?? {}

			return (half.attachments?.files ?? 0) + (half.avatars?.files ?? 0)
		},

		human: humanSize,
	},
}
</script>

<style lang="scss" scoped>
.storage {
	display: flex;
	gap: 32px;
	flex-wrap: wrap;
	margin-block-end: 8px;
}

.storage__figure {
	display: flex;
	flex-direction: column;
}

.storage__number {
	font-size: 32px;
	font-weight: bold;
	line-height: 1.1;
}

.storage__label {
	font-weight: bold;
}

.storage__sub {
	color: var(--color-text-maxcontrast);
}

.storage__heading {
	margin-block: 16px 4px;
	font-weight: bold;
}

.storage__accounts {
	width: 100%;
	max-width: 600px;
	border-collapse: collapse;
	margin-block: 8px;

	th,
	td {
		padding: 4px 8px;
		border-block-end: 1px solid var(--color-border);
		text-align: start;
	}
}

.storage__amount {
	text-align: end !important;
	white-space: nowrap;
}
</style>
