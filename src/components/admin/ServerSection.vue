<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<div class="server">
			<h4 class="server__heading">
				{{ t('social', 'How this server presents itself') }}
			</h4>
			<NcTextField
				v-model="form.contactEmail"
				class="server__field"
				type="email"
				:label="t('social', 'Contact address')"
				placeholder="admin@instance.example"
				:helperText="t('social', 'Shown to anybody asking this server what it is. Clients read it on their first request.')" />

			<NcTextField
				v-model="form.contactAccount"
				class="server__field"
				:label="t('social', 'Contact account')"
				:placeholder="t('social', 'Local Aloha Social username')"
				:helperText="t('social', 'Shown to Mastodon-compatible clients as the account responsible for this instance. Leave empty to omit it.')" />

			<NcTextArea
				v-model="form.extendedDescription"
				class="server__field"
				:label="t('social', 'About this instance')"
				:placeholder="t('social', 'Who runs this server, who it is for, and what is expected of the people on it.')"
				rows="4" />

			<h4 class="server__heading">
				{{ t('social', 'Uploads') }}
			</h4>
			<div class="server__sizes">
				<NcTextField
					v-model="form.maxSize"
					class="server__number"
					type="number"
					min="1"
					max="10240"
					:label="t('social', 'Largest picture or file (MB)')" />
				<NcTextField
					v-model="form.maxVideoSize"
					class="server__number"
					type="number"
					min="1"
					max="102400"
					:label="t('social', 'Largest video (MB)')" />
			</div>

			<div class="server__sizes">
				<NcTextField
					v-model="form.imageMaxEdge"
					class="server__number"
					type="number"
					min="0"
					max="16384"
					:label="t('social', 'Shrink pictures to (pixels)')"
					:helperText="t('social', '0 keeps every upload exactly as it arrived, which is the default. A ceiling saves disk and bandwidth.')" />
				<NcTextField
					v-model="form.imageQuality"
					class="server__number"
					type="number"
					min="40"
					max="100"
					:label="t('social', 'Stored at quality')"
					:helperText="t('social', 'Only used when a ceiling is set above, because otherwise nothing is re-encoded.')" />
			</div>

			<h4 class="server__heading">
				{{ t('social', 'Videos') }}
			</h4>

			<!-- the one thing that decides whether a video posted here plays
			     anywhere else: Pixelfed's default accepts video/mp4 and
			     nothing else, so a .mov straight off a phone is dropped by its
			     inbox without a word to anybody -->
			<NcCheckboxRadioSwitch v-model="form.videoTranscode" type="switch">
				{{ t('social', 'Convert videos to MP4 in the background') }}
			</NcCheckboxRadioSwitch>
			<p class="server__hint">
				{{ t('social', 'On by default when ffmpeg is installed on the server. Converts .mov, HEVC and other formats to an H.264 MP4, the one format every browser and Pixelfed plays. An MP4 that is already H.264 is never touched. The converted file replaces the original, which is deleted, so switch this off if uploads must be kept exactly as they are. A new post waits up to ten minutes for its video to be converted before it goes to other servers.') }}
			</p>
			<NcTextField
				v-if="form.videoTranscode"
				v-model="form.videoMaxHeight"
				class="server__number"
				type="number"
				min="240"
				max="2160"
				:label="t('social', 'Shrink videos to (pixels tall)')"
				:helperText="t('social', 'Only smaller, never larger: a 480p video is left at 480p.')" />

			<!-- the same video at two or three sizes, so a reader on a phone
			     on a train is not downloading the 1080p of it or nothing -->
			<NcCheckboxRadioSwitch v-model="form.videoLadder" type="switch">
				{{ t('social', 'Build a ladder of video sizes') }}
			</NcCheckboxRadioSwitch>
			<p class="server__hint">
				{{ t('social', 'Off by default, because it means several encodes per video. Writes each MP4 at smaller sizes as HLS, so a player picks the one that fits the connection, the way PeerTube does. The original is kept. Needs ffmpeg and ffprobe.') }}
			</p>
			<NcTextField
				v-if="form.videoLadder"
				v-model="form.videoLadderHeights"
				class="server__field"
				:label="t('social', 'Sizes to write (pixels tall, comma-separated)')"
				:helperText="t('social', 'Sizes at or above a video\'s own height are skipped rather than enlarged.')" />

			<NcTextField
				v-model="form.videoQuota"
				class="server__number"
				type="number"
				min="0"
				max="10485760"
				:label="t('social', 'Video one account may keep (MB)')"
				:helperText="t('social', '0 is no quota: the only limit is the disk. The smaller sizes this server writes itself do not count.')" />

			<h4 class="server__heading">
				{{ t('social', 'Sensitive media') }}
			</h4>

			<!-- PeerTube's three NSFW policies, under the names Mastodon's own
			     preference already uses for the same three states -->
			<label class="server__label" for="social-nsfw-policy">
				{{ t('social', 'Default for media marked sensitive') }}
			</label>
			<NcSelect
				id="social-nsfw-policy"
				v-model="nsfwChoice"
				class="server__field"
				:options="nsfwOptions"
				label="label"
				:clearable="false" />
			<p class="server__hint">
				{{ t('social', 'What a reader who has not chosen for themselves gets. Anybody can override it in their own settings. A content warning is a different thing and always covers its post.') }}
			</p>

			<h4 class="server__heading">
				{{ t('social', 'Federation and security') }}
			</h4>
			<NcTextField
				v-model="form.inboxThrottle"
				class="server__field"
				type="number"
				min="0"
				max="100000"
				:label="t('social', 'Inbox requests allowed per instance per minute')"
				:helperText="t('social', '0 accepts everything, which is what an instance behind its own rate limiter wants.')" />

			<NcCheckboxRadioSwitch v-model="form.secureMode" type="switch">
				{{ t('social', 'Secure mode') }}
			</NcCheckboxRadioSwitch>
			<p class="social-admin__hint">
				{{ t('social', 'Unsigned ActivityPub fetches are refused. Servers that do not sign what they ask for — and every anonymous reader — stop seeing anything from this one.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.publishBlocks" type="switch">
				{{ t('social', 'Publish the list of blocked instances') }}
			</NcCheckboxRadioSwitch>
			<p class="social-admin__hint">
				{{ t('social', 'Anybody can then read which instances this server refuses, the way Mastodon publishes it.') }}
			</p>

			<NcCheckboxRadioSwitch v-model="form.allowSelfSigned" type="switch">
				{{ t('social', 'Accept certificates that do not check out') }}
			</NcCheckboxRadioSwitch>
			<NcNoteCard type="warning">
				<strong>{{ t('social', 'For development only.') }}</strong>
				{{ t('social', 'On a server anybody else uses, this hands every federated request to whoever can answer for the address.') }}
			</NcNoteCard>

			<NcNoteCard v-if="message !== ''" :type="saved ? 'success' : 'error'">
				{{ message }}
			</NcNoteCard>

			<NcButton variant="primary" :disabled="saving" @click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('social', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, serverUrl } from '../../services/adminApi.js'
import { showError, showSuccess } from '../../services/toast.js'

/**
 * What this instance says about itself, and the limits it holds peers to.
 *
 * Rendered for an administrator and not for a delegate: what it holds is
 * administration rather than moderation, and its endpoint refuses anybody who
 * is not an administrator anyway. The parent decides that — the settings are
 * simply not in the initial state of a delegate.
 */
export default {
	name: 'ServerSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
		NcSelect,
		NcTextField,
	},

	props: {
		/** `ServerSettingsService::current()`, as initial state */
		settings: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			form: {
				contactEmail: this.settings.contact_email ?? '',
				contactAccount: this.settings.contact_account ?? '',
				extendedDescription: this.settings.extended_description ?? '',
				maxSize: String(this.settings.max_size ?? 10),
				maxVideoSize: String(this.settings.max_video_size ?? 2048),
				imageMaxEdge: String(this.settings.image_max_edge ?? 0),
				imageQuality: String(this.settings.image_quality ?? 85),
				videoTranscode: this.settings.video_transcode === true,
				videoMaxHeight: String(this.settings.video_max_height ?? 1080),
				videoLadder: this.settings.video_ladder === true,
				videoLadderHeights: String(this.settings.video_ladder_heights ?? '360,720,1080'),
				videoQuota: String(this.settings.video_quota ?? 0),
				nsfwPolicy: String(this.settings.nsfw_policy ?? 'default'),
				inboxThrottle: String(this.settings.inbox_throttle ?? 300),
				secureMode: this.settings.secure_mode === true,
				publishBlocks: this.settings.publish_blocks === true,
				allowSelfSigned: this.settings.allow_self_signed === true,
			},

			message: '',
			saved: false,
			saving: false,
		}
	},

	computed: {
		/** @return {Array<{id: string, label: string}>} the three policies */
		nsfwOptions() {
			return [
				{ id: 'show_all', label: t('social', 'Shown like anything else') },
				{ id: 'default', label: t('social', 'Covered, one press away') },
				{ id: 'hide_all', label: t('social', 'Not shown; open the post to see it') },
			]
		},

		/**
		 * The select works in objects and the form holds the word, so this
		 * translates between them rather than keeping the same state twice.
		 *
		 * @return {{id: string, label: string}}
		 */
		nsfwChoice: {
			get() {
				return this.nsfwOptions.find((option) => option.id === this.form.nsfwPolicy)
					?? this.nsfwOptions[1]
			},

			set(option) {
				this.form.nsfwPolicy = option?.id ?? 'default'
			},
		},
	},

	methods: {
		t,

		/**
		 * Writes the whole card, or none of it.
		 *
		 * The endpoint validates every field and writes all or nothing, so what
		 * is reported here is what stands: a card that saved six of its eight
		 * fields would leave an administrator guessing which.
		 *
		 * @return {Promise<boolean>} whether it was taken
		 */
		async save() {
			this.saving = true
			try {
				await axios.post(serverUrl(), {
					contactEmail: this.form.contactEmail.trim(),
					contactAccount: this.form.contactAccount.trim().replace(/^@/, ''),
					extendedDescription: this.form.extendedDescription,
					maxSize: parseInt(this.form.maxSize, 10),
					maxVideoSize: parseInt(this.form.maxVideoSize, 10),
					imageMaxEdge: parseInt(this.form.imageMaxEdge, 10),
					imageQuality: parseInt(this.form.imageQuality, 10),
					videoTranscode: this.form.videoTranscode,
					videoMaxHeight: parseInt(this.form.videoMaxHeight, 10),
					videoLadder: this.form.videoLadder,
					videoLadderHeights: this.form.videoLadderHeights.trim(),
					videoQuota: parseInt(this.form.videoQuota, 10),
					nsfwPolicy: this.form.nsfwPolicy,
					inboxThrottle: parseInt(this.form.inboxThrottle, 10),
					secureMode: this.form.secureMode,
					publishBlocks: this.form.publishBlocks,
					allowSelfSigned: this.form.allowSelfSigned,
				})
				this.saved = true
				this.message = t('social', 'Saved')
				showSuccess(t('social', 'The server settings were saved'))

				return true
			} catch (error) {
				// the endpoint names the field it would not take; it is the one
				// thing that tells the administrator what to change
				this.saved = false
				this.message = errorMessage(error, t('social', 'Could not save the server settings'))
				showError(t('social', 'Could not save the server settings'))

				return false
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.server {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	// stretch, not flex-start: a field sized to its own content is a field
	// whose floating label is cut off in the middle of a word, and the
	// longest label here is longer than the number it sits above
	align-items: stretch;
	max-width: 600px;

	&__sizes {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	// the two megabyte fields share a row until the column is too narrow for
	// both labels, and then they stack
	&__number {
		flex: 1 1 240px;
	}
}

.server__heading {
	margin-block: 16px 0;
	font-weight: bold;

	&:first-child {
		margin-block-start: 0;
	}
}

.server__label {
	display: block;
	margin-block: 12px 4px;
	font-weight: bold;
}

.server__hint {
	color: var(--color-text-maxcontrast);
	margin-block: -4px 4px;
}
</style>
