<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="migration">
		<!--
			First, because it is what somebody arriving is looking for and the
			rest of this page is written for people who already know what an
			export is.
		-->
		<section class="migration__card migration__card--lead">
			<h4>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Moving in') }}
			</h4>
			<p>
				{{ t('social', 'Coming from X, Instagram, TikTok or YouTube? The step-by-step way in: your posts, the accounts you followed where they can be found again, and a card to post where you used to be.') }}
			</p>
			<NcButton variant="primary" :to="{ name: 'switch' }">
				<template #icon>
					<IconAccountArrowRight :size="20" />
				</template>
				{{ t('social', 'Move in from another network') }}
			</NcButton>
		</section>

		<!-- out -->
		<section class="migration__card">
			<h4>
				<IconDownload :size="20" />
				{{ t('social', 'Export your data') }}
			</h4>
			<p>
				{{ t('social', 'A zip file holding your profile, the people you follow, your followers, the accounts you block and mute, your bookmarks and likes, and every post you have written — with the pictures and videos on those posts, and your profile banner, as files inside the archive rather than as links back to this server.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Your private key is deliberately not in it. An archive is an ordinary file that can be copied anywhere, and a key that could sign as you cannot be taken back once it has been seen.') }}
			</p>
			<NcButton
				variant="primary"
				:disabled="exporting"
				@click="exportArchive">
				<template #icon>
					<NcLoadingIcon v-if="exporting" :size="20" />
					<IconDownload v-else :size="20" />
				</template>
				{{ exporting ? t('social', 'Preparing the archive …') : t('social', 'Export') }}
			</NcButton>

			<h5>{{ t('social', 'Or one list at a time') }}</h5>
			<p>
				{{ t('social', 'The same lists of accounts as single CSV files, each written the way Mastodon writes it — so another server\'s importer reads them without being asked to understand a whole archive. The followers file is a record rather than something an import can re-create: a follower follows again, or their server is told by the move.') }}
			</p>
			<div class="migration__csv-buttons">
				<NcButton
					v-for="kind in csvKinds"
					:key="kind.name"
					:disabled="csvBusy !== ''"
					@click="downloadCsv(kind.name)">
					<template #icon>
						<NcLoadingIcon v-if="csvBusy === kind.name" :size="20" />
						<IconDownload v-else :size="20" />
					</template>
					{{ kind.label }}
				</NcButton>
			</div>
		</section>

		<!-- in -->
		<section class="migration__card">
			<h4>
				<IconUpload :size="20" />
				{{ t('social', 'Import an archive') }}
			</h4>
			<p>
				{{ t('social', 'Reads an archive from the Export button — or from a Nextcloud account export — back into this account. Nothing is deleted: your profile, follows, blocks, mutes, bookmarks and likes are restored alongside what is already here.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Posts in the archive are listed rather than published again, so importing cannot flood the timelines of people who follow you. Their pictures are put back on the posts this server still has, and your banner is restored.') }}
			</p>
			<!-- each file input here is opened by the button after it, which is
			     the control a keyboard and a screen reader reach; the input
			     itself is kept out of both, or it is an unnamed "Browse…" -->
			<input
				ref="archive"
				type="file"
				accept=".zip,application/zip"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importArchive">
			<NcButton :disabled="importing" @click="pick('archive')">
				<template #icon>
					<NcLoadingIcon v-if="importing" :size="20" />
					<IconUpload v-else :size="20" />
				</template>
				{{ importing ? t('social', 'Importing …') : t('social', 'Import') }}
			</NcButton>

			<ul v-if="importLog.length" class="migration__log">
				<li v-for="(line, index) in importLog" :key="index">
					{{ line }}
				</li>
			</ul>
		</section>

		<!-- from elsewhere -->
		<section class="migration__card">
			<h4>
				<IconAccountArrowRight :size="20" />
				{{ t('social', 'Coming from another network') }}
			</h4>
			<p>
				{{ t('social', 'The fediverse is one network with many doors. Mastodon, Pixelfed, GoToSocial, Akkoma, Misskey and this app all speak ActivityPub, so an account here can follow and be followed by any of them — and what you bring with you is mostly the list of people you had found.') }}
			</p>

			<h5>{{ t('social', 'Bring your follows with you') }}</h5>
			<p>
				{{ t('social', 'Every one of those servers exports the people you follow — most as a following_accounts.csv, Pixelfed as pixelfed-following.json. Upload that file and each account is followed again from here. A follow is an agreement between two servers, so it has to be asked for again — it cannot be copied out of a file.') }}
			</p>
			<input
				ref="follows"
				type="file"
				accept=".csv,text/csv,.json,application/json"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importFollows">
			<NcButton :disabled="followsBusy" @click="pick('follows')">
				<template #icon>
					<NcLoadingIcon v-if="followsBusy" :size="20" />
					<IconAccountMultiplePlus v-else :size="20" />
				</template>
				{{ followsBusy ? t('social', 'Following …') : t('social', 'Import follows from a file') }}
			</NcButton>
			<p v-if="followsResult" class="migration__result">
				{{ followsResult }}
			</p>

			<h5>{{ t('social', 'Bring your blocks, mutes and lists') }}</h5>
			<p>
				{{ t('social', 'The rest of what the same export holds. Blocks and mutes are decisions this account makes on its own, so they apply the moment the file is read — and a block federates, exactly as blocking somebody from here does.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Import your follows first and your lists after. A list here can only hold accounts you follow, as on Mastodon, so anybody you have not followed again yet is counted as skipped rather than followed by a button that says lists. A list you already have is filled rather than made twice.') }}
			</p>
			<div class="migration__csv-buttons">
				<input
					ref="blocks"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'blocks')">
				<NcButton :disabled="csvImport !== ''" @click="pick('blocks')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'blocks'" :size="20" />
						<IconCancel v-else :size="20" />
					</template>
					{{ t('social', 'Import blocks') }}
				</NcButton>
				<input
					ref="mutes"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'mutes')">
				<NcButton :disabled="csvImport !== ''" @click="pick('mutes')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'mutes'" :size="20" />
						<IconVolumeOff v-else :size="20" />
					</template>
					{{ t('social', 'Import mutes') }}
				</NcButton>
				<input
					ref="lists"
					type="file"
					accept=".csv,text/csv"
					class="hidden-visually"
					tabindex="-1"
					aria-hidden="true"
					@change="importCsv($event, 'lists')">
				<NcButton :disabled="csvImport !== ''" @click="pick('lists')">
					<template #icon>
						<NcLoadingIcon v-if="csvImport === 'lists'" :size="20" />
						<IconFormatListBulleted v-else :size="20" />
					</template>
					{{ t('social', 'Import lists') }}
				</NcButton>
			</div>
			<p v-if="csvResult" class="migration__result">
				{{ csvResult }}
			</p>

			<h5>{{ t('social', 'Bring your posts with you') }}</h5>
			<p>
				{{ t('social', 'The one thing moving has never carried. Upload the export from your old server and the posts in it are written here as yours, dated when you wrote them, with their pictures.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Nothing is sent to anybody: your followers do not get years of posts in one afternoon, because nothing here is published again. Boosts and direct messages are left out, and a reply keeps the post it answers where the file holds both. Importing the same file twice changes nothing the second time.') }}
			</p>
			<NcCheckboxRadioSwitch v-model="fetchMedia" type="switch" class="migration__media-switch">
				{{ t('social', 'Fetch the pictures from the old server') }}
			</NcCheckboxRadioSwitch>
			<p class="migration__note">
				{{ t('social', 'An archive usually holds the files themselves and they are used as they are. A file that only lists where its pictures are — Pixelfed writes one — needs them fetched, which tells that server the import is happening and only works while it is still running.') }}
			</p>
			<input
				ref="posts"
				type="file"
				accept=".zip,application/zip,.json,application/json"
				class="hidden-visually"
				tabindex="-1"
				aria-hidden="true"
				@change="importPosts">
			<NcButton :disabled="postsBusy" @click="pick('posts')">
				<template #icon>
					<NcLoadingIcon v-if="postsBusy" :size="20" />
					<IconPostOutline v-else :size="20" />
				</template>
				{{ postsBusy ? t('social', 'Writing your posts …') : t('social', 'Import posts from an export') }}
			</NcButton>
			<p v-if="postsResult" class="migration__result">
				{{ postsResult }}
			</p>
			<p class="migration__note">
				{{ t('social', 'At most 2000 posts at a time; run it again to carry on. An archive too large for a browser to upload can be imported by an administrator with occ social:account:import-posts.') }}
			</p>
			<p class="migration__note">
				{{ t('social', 'Coming from Instagram? The same button reads its archive — ask Instagram for your information in JSON, not HTML. Your posts and reels arrive with their pictures and captions. An Instagram post does not record who could see it, so each one is posted with your own default visibility; your stories, archived posts and deleted ones are left where they are.') }}
			</p>

			<h5>{{ t('social', 'Where to find that file') }}</h5>
			<ul class="migration__list">
				<li>
					<strong>{{ t('social', 'Mastodon') }}</strong>
					{{ t('social', '— Preferences → Import and export → Data export → Follows (CSV). The archive there also holds your posts and media.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Pixelfed') }}</strong>
					{{ t('social', '— Settings → Data export → Following (JSON), which writes pixelfed-following.json: a list of account addresses rather than a CSV, and read here all the same. Photos come across as posts once you follow the accounts again.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Instagram') }}</strong>
					{{ t('social', '— Settings → Accounts Centre → Your information and permissions → Download your information, and choose JSON. The HTML download holds the pages and not the posts. The posts and reels in it come over with the button above, and the accounts it says you follow can be looked up on Threads — which does federate — in Moving in.') }}
				</li>
				<li>
					<strong>{{ t('social', 'GoToSocial and Akkoma') }}</strong>
					{{ t('social', '— Settings → Export, which writes the same Mastodon-shaped CSV.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Threads') }}</strong>
					{{ t('social', '— Threads accounts that have turned fediverse sharing on can be followed from here directly, and a Threads name is the same as the Instagram one. There is no follow list to export, so Moving in looks them up from the Instagram archive instead.') }}
				</li>
				<li>
					<strong>{{ t('social', 'Bluesky and X') }}</strong>
					{{ t('social', '— neither carries a follow list this can read: X exports the accounts it follows as numbers rather than names, and nothing on either side federates a follow. X posts can still be imported with the button above. Bluesky accounts can be followed through a bridge if the other side has opted in.') }}
				</li>
			</ul>

			<h5>{{ t('social', 'Accounts you also answer to') }}</h5>
			<p>
				{{ t('social', 'Before your old server will send your followers here, it wants this account to say it is also you. Add the old account\'s address and it does. Nothing is sent to anybody by this — it is a note this server keeps about an account it owns — and you can take it off again at any time.') }}
			</p>
			<ul v-if="aliases.length > 0" class="migration__aliases">
				<li v-for="alias in aliases" :key="alias" class="migration__alias">
					<span class="migration__alias-id">{{ alias }}</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('social', 'Remove this alias')"
						:title="t('social', 'Remove this alias')"
						:disabled="aliasBusy"
						@click="removeAlias(alias)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
			<div class="migration__alias-add">
				<NcTextField
					v-model="aliasInput"
					class="migration__alias-field"
					:label="t('social', 'The old account')"
					placeholder="@you@pixelfed.social"
					:disabled="aliasBusy"
					@keydown.enter="addAlias" />
				<NcButton :disabled="aliasBusy || aliasInput.trim() === ''" @click="addAlias">
					<template v-if="aliasBusy" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('social', 'Add') }}
				</NcButton>
			</div>
			<p class="migration__note">
				{{ t('social', 'The handle as you would give it to somebody — or, if you have it, the address its server publishes, like https://pixelfed.social/users/you.') }}
			</p>

			<h5>{{ t('social', 'Moving your whole account') }}</h5>
			<p>
				{{ t('social', 'With the alias above in place, your old server can send your followers here. That is the half that cannot be taken back: it federates to every server that knows you, so it stays an administrator action — ask for occ social:account:move on the old server.') }}
			</p>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '../services/toast.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import IconAccountMultiplePlus from 'vue-material-design-icons/AccountMultiplePlus.vue'
import IconCancel from 'vue-material-design-icons/Cancel.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconDownload from 'vue-material-design-icons/Download.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconPostOutline from 'vue-material-design-icons/PostOutline.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import IconVolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import { t } from '@nextcloud/l10n'
import logger from '../services/logger.js'

/**
 * Taking your account out, and bringing one in: the body of the Migration
 * page. The heading and the sentence under it belong to the page, so they
 * live in `views/Migration.vue` rather than being repeated here.
 */
export default {
	name: 'MigrationSettings',

	components: {
		IconAccountArrowRight,
		IconAccountMultiplePlus,
		IconCancel,
		IconClose,
		IconDownload,
		IconFormatListBulleted,
		IconPostOutline,
		IconUpload,
		IconVolumeOff,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			exporting: false,
			importing: false,
			followsBusy: false,
			postsBusy: false,
			/** whether a picture named only by its address may be fetched from the old server */
			fetchMedia: true,
			/** @type {string} what the last post import came to */
			postsResult: '',
			/** @type {string[]} what the last import reported */
			importLog: [],
			/** @type {string} what the last CSV import came to */
			followsResult: '',
			/** @type {string} which single-file export is being prepared */
			csvBusy: '',
			/** @type {string} which CSV is being read in */
			csvImport: '',
			/** @type {string} what the last blocks, mutes or lists import came to */
			csvResult: '',
			/** @type {string[]} the accounts this one also answers to */
			aliases: [],
			/** @type {string} the address being added */
			aliasInput: '',
			aliasBusy: false,
		}
	},

	computed: {
		/**
		 * The lists of accounts that can be downloaded one at a time.
		 *
		 * Named here rather than in the loop so the labels are translated
		 * strings in the source and not something built out of a route name.
		 *
		 * @return {Array<{name: string, label: string}>}
		 */
		csvKinds() {
			return [
				{ name: 'following', label: t('social', 'Follows') },
				{ name: 'followers', label: t('social', 'Followers') },
				{ name: 'blocks', label: t('social', 'Blocks') },
				{ name: 'mutes', label: t('social', 'Mutes') },
				{ name: 'lists', label: t('social', 'Lists') },
			]
		},
	},

	mounted() {
		this.loadAliases()
	},

	methods: {
		/** @param {string} name the ref of the file input behind a button */
		pick(name) {
			/** @type {HTMLInputElement} */ (this.$refs[name]).click()
		},

		t,

		/**
		 * The accounts this one also answers to.
		 *
		 * Read on mount rather than handed over with the page: the section is
		 * below the fold of a settings page most people never open, and a
		 * request that costs nothing until then is cheaper than state on every
		 * page load.
		 *
		 * @return {Promise<void>}
		 */
		async loadAliases() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/aliases'))
				this.aliases = Array.isArray(data.aliases) ? data.aliases : []
			} catch (error) {
				logger.error('Failed to load the aliases', { error })
			}
		},

		/** @return {Promise<void>} */
		async addAlias() {
			const alias = this.aliasInput.trim()
			if (alias === '' || this.aliasBusy) {
				return
			}

			this.aliasBusy = true
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/aliases'),
					{ alias },
				)
				this.aliases = data.aliases
				this.aliasInput = ''
				showSuccess(t('social', 'This account now says it is also that one'))
			} catch (error) {
				// the server refuses an address that is not an account's own,
				// and says which; that sentence is the whole of the help there is
				showError(error.response?.data?.error ?? t('social', 'Could not add the alias'))
			} finally {
				this.aliasBusy = false
			}
		},

		/**
		 * @param {string} alias the address to stop answering to
		 * @return {Promise<void>}
		 */
		async removeAlias(alias) {
			this.aliasBusy = true
			try {
				const { data } = await axios.delete(
					generateUrl('apps/social/api/v1/migration/aliases'),
					{ data: { alias } },
				)
				this.aliases = data.aliases
			} catch {
				showError(t('social', 'Could not remove the alias'))
			} finally {
				this.aliasBusy = false
			}
		},

		/**
		 * The archive is built on the server and handed over as a blob, then
		 * saved through a link this code makes and clicks: the route needs the
		 * session, so a plain `window.open` of it would work, but an error
		 * would then replace the page with a JSON body instead of being caught
		 * here and said out loud.
		 */
		async exportArchive() {
			this.exporting = true
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/migration/export'),
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const link = document.createElement('a')
				link.href = url
				link.download = this.filenameOf(response) || 'social-export.zip'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
				showSuccess(t('social', 'Your archive is downloading'))
			} catch (error) {
				logger.error('The export failed', { error })
				showError(t('social', 'Could not export your data'))
			} finally {
				this.exporting = false
			}
		},

		/**
		 * @param {object} response what the server answered
		 * @return {string} the name the server gave the file, or ''
		 */
		filenameOf(response) {
			const disposition = response?.headers?.['content-disposition'] ?? ''
			const match = /filename="?([^";]+)"?/.exec(disposition)

			return match ? match[1] : ''
		},

		/**
		 * @param {Event} event the file input's change
		 */
		async importArchive(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.importing = true
			this.importLog = []
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/import'),
					form,
				)
				this.importLog = Array.isArray(data?.log) ? data.log : []
				showSuccess(t('social', 'Your archive has been imported'))
			} catch (error) {
				logger.error('The import failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import that archive'))
			} finally {
				this.importing = false
				// cleared, or choosing the same file twice fires no change
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * @param {Event} event the file input's change
		 */
		/**
		 * Reads an export and writes the posts in it as this account's own.
		 *
		 * The heavy one: the server reads the file, writes up to two thousand
		 * posts and may fetch a picture for each, so the button says what it
		 * is doing for as long as it takes.
		 *
		 * @param {Event} event the file input's change
		 * @return {Promise<void>}
		 */
		async importPosts(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.postsBusy = true
			this.postsResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				form.append('fetch_media', this.fetchMedia ? '1' : '0')
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/posts'),
					form,
				)
				this.postsResult = t(
					'social',
					'{imported} posts written with {media} of their pictures; {already} were already here, {skipped} were not posts to bring over, {failed} could not be written.',
					{
						imported: data?.imported ?? 0,
						media: data?.media ?? 0,
						already: data?.already ?? 0,
						skipped: data?.skipped ?? 0,
						failed: data?.failed ?? 0,
					},
				)
				if (data?.capped) {
					this.postsResult += ' ' + t('social', 'The run stopped at its limit — import the same file again to carry on.')
				}
				showSuccess(t('social', 'Your posts have been imported'))
			} catch (error) {
				logger.error('Importing posts failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import those posts'))
			} finally {
				this.postsBusy = false
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * One list of accounts, saved as the CSV the server writes.
		 *
		 * Fetched as a blob and saved through a link this code makes, the same
		 * way the archive is: the route needs the session, so opening it in a
		 * tab would work — and an error would then replace the page with a
		 * JSON body instead of being caught here and said out loud.
		 *
		 * @param {string} kind which list: following, followers, blocks, mutes or lists
		 * @return {Promise<void>}
		 */
		async downloadCsv(kind) {
			this.csvBusy = kind
			try {
				const response = await axios.get(
					generateUrl('apps/social/api/v1/migration/export/{kind}', { kind }),
					{ responseType: 'blob' },
				)
				const url = URL.createObjectURL(response.data)
				const link = document.createElement('a')
				link.href = url
				link.download = this.filenameOf(response) || kind + '.csv'
				document.body.appendChild(link)
				link.click()
				link.remove()
				URL.revokeObjectURL(url)
			} catch (error) {
				logger.error('A CSV export failed', { error, kind })
				showError(t('social', 'Could not export that list'))
			} finally {
				this.csvBusy = ''
			}
		},

		/**
		 * Reads a blocks, mutes or lists CSV back in.
		 *
		 * @param {Event} event the file input's change
		 * @param {string} kind blocks, mutes or lists
		 * @return {Promise<void>}
		 */
		async importCsv(event, kind) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.csvImport = kind
			this.csvResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/{kind}', { kind }),
					form,
				)
				this.csvResult = this.csvSummary(kind, data)
				showSuccess(t('social', 'That file has been read'))
			} catch (error) {
				logger.error('Importing a CSV failed', { error, kind })
				showError(error?.response?.data?.error || t('social', 'Could not read that file'))
			} finally {
				this.csvImport = ''
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * @param {string} kind blocks, mutes or lists
		 * @param {object} data what the server counted
		 * @return {string} what to tell the person who pressed the button
		 */
		csvSummary(kind, data) {
			const failed = Object.keys(data?.failed ?? {}).length
			if (kind === 'lists') {
				return t(
					'social',
					'{lists} lists made, {added} accounts added, {skipped} skipped because you do not follow them, {failed} could not be reached',
					{
						lists: data?.lists ?? 0,
						added: data?.added ?? 0,
						skipped: data?.skipped ?? 0,
						failed,
					},
				)
			}

			return t(
				'social',
				'{done} applied, {skipped} skipped, {failed} could not be reached',
				{ done: data?.blocked ?? data?.muted ?? 0, skipped: data?.skipped ?? 0, failed },
			)
		},

		async importFollows(event) {
			const input = /** @type {HTMLInputElement|null} */ (event?.target ?? null)
			const file = input?.files?.[0]
			if (!file) {
				return
			}

			this.followsBusy = true
			this.followsResult = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/follows'),
					form,
				)
				const failed = Object.keys(data?.failed ?? {}).length
				this.followsResult = t(
					'social',
					'{followed} followed, {skipped} skipped, {failed} could not be reached',
					{ followed: data?.followed ?? 0, skipped: data?.skipped ?? 0, failed },
				)
				showSuccess(t('social', 'Your follows have been imported'))
			} catch (error) {
				logger.error('Importing follows failed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not import those follows'))
			} finally {
				this.followsBusy = false
				if (input) {
					input.value = ''
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.migration {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	h2 {
		margin-bottom: 8px;
	}
}

.migration__hint {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.migration__csv-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* the way in, told apart from the four cards of machinery below it */
.migration__card--lead {
	border-inline-start: 4px solid var(--color-primary-element);
}

.migration__card {
	margin-bottom: 16px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-shadow: var(--social-elevation-resting);

	/* the card's own heading, one level below the section's */
	h4 {
		display: flex;
		gap: 8px;
		align-items: center;
		margin-bottom: 8px;
		font-size: 17px;
		font-weight: bold;
	}

	h5 {
		margin: 20px 0 6px;
		font-size: inherit;
		font-weight: bold;
	}

	p {
		margin-bottom: 12px;
	}
}

.migration__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.migration__aliases {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
}

.migration__alias {
	display: flex;
	align-items: center;
	gap: 8px;
}

.migration__alias-id {
	// an actor id is a URL and will not break on its own
	overflow-wrap: anywhere;
}

.migration__alias-add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 4px;
}

.migration__alias-field {
	max-width: 420px;
}

.migration__list {
	margin: 0 0 12px;
	padding: 0;
	list-style: none;

	li {
		margin-bottom: 6px;
		padding-inline-start: 14px;
		position: relative;

		&::before {
			content: '·';
			position: absolute;
			inset-inline-start: 2px;
		}
	}
}

.migration__log {
	margin-top: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	font-size: 13px;
	list-style: none;
	max-height: 240px;
	overflow-y: auto;
}

.migration__media-switch {
	margin: 4px 0 2px;
}

.migration__result {
	margin-top: 10px;
	font-weight: 500;
}
</style>
