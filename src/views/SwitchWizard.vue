<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="switch social__wrapper">
		<header class="switch__header">
			<h2 class="switch__title">
				{{ t('social', 'Move in from somewhere else') }}
			</h2>
			<p class="switch__lead">
				{{ t('social', 'Your posts, the people you follow and a way to tell everyone where you went. Nothing here is posted for you, and nothing leaves this server except the lookups you ask for.') }}
			</p>
		</header>

		<!-- 1. which network -->
		<section class="switch__step" :aria-label="t('social', 'Where are you coming from?')">
			<h3 class="switch__heading">
				{{ t('social', 'Where are you coming from?') }}
			</h3>
			<ul class="networks">
				<li v-for="one in networks" :key="one.id">
					<button
						type="button"
						class="networks__item"
						:class="{ 'networks__item--chosen': network === one.id }"
						:aria-pressed="network === one.id"
						@click="choose(one.id)">
						<span class="networks__mark" :style="{ background: one.tint }" aria-hidden="true">
							{{ one.mark }}
						</span>
						<span class="networks__name">{{ one.name }}</span>
					</button>
				</li>
			</ul>
		</section>

		<template v-if="chosen">
			<!-- 2. the archive -->
			<section class="switch__step" :aria-label="t('social', 'Bring your posts')">
				<h3 class="switch__heading">
					{{ t('social', 'Bring your posts') }}
				</h3>
				<p>{{ chosen.how }}</p>

				<template v-if="chosen.readsPosts">
					<input
						ref="archive"
						type="file"
						class="switch__file"
						accept=".zip,application/zip"
						@change="onArchive">
					<NcButton variant="primary" :disabled="busy !== ''" @click="pickArchive">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'posts'" :size="20" />
							<IconUpload v-else :size="20" />
						</template>
						{{ busy === 'posts' ? t('social', 'Reading your archive …') : t('social', 'Choose your archive') }}
					</NcButton>
					<p class="switch__note">
						{{ t('social', 'Your posts are written here with the dates they were written on, and nothing is sent to anyone: importing cannot put years of old posts into other people\'s timelines.') }}
					</p>
					<p v-if="postsResult" class="switch__done" role="status">
						{{ postsResult }}
					</p>
				</template>
				<NcNoteCard v-else type="warning">
					{{ chosen.noPosts }}
				</NcNoteCard>
			</section>

			<!-- 3. the people -->
			<section v-if="chosen.readsPeople" class="switch__step" :aria-label="t('social', 'Find the people you followed')">
				<h3 class="switch__heading">
					{{ t('social', 'Find the people you followed') }}
				</h3>
				<p>
					{{ t('social', 'Instagram names are Threads names, and Threads is part of the fediverse — so the accounts you already followed can be looked up here, one at a time, from the same archive. Everyone who answers is shown; nobody is followed until you say so.') }}
				</p>

				<NcButton
					v-if="candidates.length === 0"
					:disabled="busy !== '' || !archiveFile"
					@click="readPeople">
					<template #icon>
						<NcLoadingIcon v-if="busy === 'people'" :size="20" />
						<IconAccountSearch v-else :size="20" />
					</template>
					{{ archiveFile ? t('social', 'Read the list from my archive') : t('social', 'Choose your archive above first') }}
				</NcButton>

				<template v-else>
					<p class="switch__progress" role="status">
						{{ n('social', 'Looked up %n name so far', 'Looked up %n names so far', probed) }}
						·
						{{ n('social', 'found %n', 'found %n', found.length) }}
						<template v-if="probed < candidates.length">
							· {{ n('social', '%n left', '%n left', candidates.length - probed) }}
						</template>
					</p>

					<ul v-if="found.length" class="people">
						<li v-for="person in found" :key="person.handle" class="people__item">
							<NcCheckboxRadioSwitch
								:modelValue="selected.includes(person.handle)"
								@update:modelValue="toggle(person.handle, $event)">
								<span class="people__who">
									<span class="people__name">{{ person.name || person.handle }}</span>
									<span class="people__handle">@{{ person.handle }}</span>
								</span>
							</NcCheckboxRadioSwitch>
						</li>
					</ul>

					<div class="switch__buttons">
						<NcButton v-if="probed < candidates.length" :disabled="busy !== ''" @click="probeMore">
							<template #icon>
								<NcLoadingIcon v-if="busy === 'probe'" :size="20" />
								<IconAccountSearch v-else :size="20" />
							</template>
							{{ t('social', 'Keep looking') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="busy !== '' || selected.length === 0"
							@click="followSelected">
							<template #icon>
								<NcLoadingIcon v-if="busy === 'follow'" :size="20" />
								<IconAccountPlus v-else :size="20" />
							</template>
							{{ n('social', 'Follow %n account', 'Follow %n accounts', selected.length) }}
						</NcButton>
					</div>
				</template>
			</section>

			<!-- 4. fill the feed -->
			<section class="switch__step" :aria-label="t('social', 'Fill your feed')">
				<h3 class="switch__heading">
					{{ t('social', 'Fill your feed') }}
				</h3>
				<p>
					{{ t('social', 'A timeline here is what you followed and nothing else — there is no algorithm deciding what you get. Which means the first day is yours to fill: the starter packs are handfuls of accounts by subject, and following a hashtag brings in everyone who writes about it, whether you follow them or not.') }}
				</p>
				<div class="switch__buttons">
					<NcButton :to="{ name: 'discover' }">
						<template #icon>
							<IconCompass :size="20" />
						</template>
						{{ t('social', 'Browse starter packs') }}
					</NcButton>
					<NcButton :to="{ name: 'timeline', params: { type: 'tags' } }">
						<template #icon>
							<IconPound :size="20" />
						</template>
						{{ t('social', 'Follow hashtags') }}
					</NcButton>
				</div>
			</section>

			<!-- 5. say where you went -->
			<section class="switch__step" :aria-label="t('social', 'Tell people where you went')">
				<h3 class="switch__heading">
					{{ t('social', 'Tell people where you went') }}
				</h3>
				<p>
					{{ t('social', 'The one part nobody can do for you: none of those networks lets another site post on your behalf. Here is the card and the words — put them where you used to be, once, and the people still there can find you.') }}
				</p>

				<canvas
					ref="card"
					class="switch__card"
					width="1200"
					height="675"
					role="img"
					:aria-label="t('social', 'A card naming your new account')" />

				<p v-if="announcementFailed" class="switch__note" role="alert">
					{{ t('social', 'Your address could not be read, so the card and the words are not ready yet.') }}
					<NcButton variant="tertiary" @click="loadAnnouncement">
						{{ t('social', 'Try again') }}
					</NcButton>
				</p>

				<div class="switch__buttons">
					<NcButton variant="primary" :disabled="!announcementReady" @click="downloadCard">
						<template #icon>
							<IconDownload :size="20" />
						</template>
						{{ t('social', 'Save the card') }}
					</NcButton>
					<NcButton :disabled="!announcementReady" @click="copyAnnouncement">
						<template #icon>
							<IconCheck v-if="copied" :size="20" />
							<IconContentCopy v-else :size="20" />
						</template>
						{{ copied ? t('social', 'Copied') : t('social', 'Copy the words') }}
					</NcButton>
				</div>
				<p class="switch__announce">
					{{ announcement.text }}
				</p>
			</section>
		</template>
	</div>
</template>

<script>
/**
 * The way in from X, Instagram, TikTok and YouTube.
 *
 * Five steps, and the order is what the page is for: the posts first because
 * that is what people are afraid of losing, then the people — the step that
 * decides whether the account is opened a second time — then somewhere to
 * find more, and last the announcement, which is the only thing here that
 * reaches back to the network being left.
 *
 * Every step says plainly what it cannot do. Three of the four networks
 * federate nothing, so no follower comes along from any of them, and a wizard
 * that implied otherwise would be found out on the first day.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import IconAccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import IconAccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconCompass from 'vue-material-design-icons/Compass.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import IconDownload from 'vue-material-design-icons/Download.vue'
import IconPound from 'vue-material-design-icons/Pound.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'

export default {
	name: 'SwitchWizard',
	components: {
		IconAccountPlus,
		IconAccountSearch,
		IconCheck,
		IconCompass,
		IconContentCopy,
		IconDownload,
		IconPound,
		IconUpload,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			network: '',
			/** the archive the person picked, kept so both steps can read it */
			archiveFile: null,
			postsResult: '',
			/** every handle worth looking up, and how far down the list we are */
			candidates: [],
			probed: 0,
			found: [],
			selected: [],
			announcement: { handle: '', url: '', text: '' },
			/** whether the announcement could not be fetched */
			announcementFailed: false,
			copied: false,
			copiedTimer: null,
			/** which step is talking to the server: '', 'posts', 'people', 'probe', 'follow' */
			busy: '',
		}
	},

	computed: {
		/**
		 * The four networks people arrive from, and the truth about each.
		 *
		 * @return {object[]} one entry per network
		 */
		networks() {
			return [
				{
					id: 'twitter',
					name: 'X',
					mark: '𝕏',
					tint: '#222',
					readsPosts: true,
					readsPeople: false,
					how: t('social', 'Settings → Your account → Download an archive of your data, and wait for the mail. Your posts and their pictures are in it. The accounts you followed are in it only as numbers, so there is nobody in that file to look up — and nothing on X federates, so no follower can come with you.'),
				},
				{
					id: 'instagram',
					name: 'Instagram',
					mark: '◎',
					tint: '#c13584',
					readsPosts: true,
					readsPeople: true,
					how: t('social', 'Settings → Accounts Centre → Your information and permissions → Download your information, and choose JSON. The HTML download holds the pages and not the posts. Your posts, your reels and the list of accounts you follow are all in that one file.'),
				},
				{
					id: 'tiktok',
					name: 'TikTok',
					mark: '♪',
					tint: '#010101',
					readsPosts: false,
					readsPeople: false,
					how: t('social', 'Settings → Account → Download your data. The archive holds your videos as files rather than as posts, so there is nothing here that can read it as a timeline yet.'),
					noPosts: t('social', 'TikTok posts cannot be imported from the archive yet. A video at a time can still be brought over by its address from Settings → Moving in, and everything below this step works the same.'),
				},
				{
					id: 'youtube',
					name: 'YouTube',
					mark: '▶',
					tint: '#c00',
					readsPosts: false,
					readsPeople: false,
					how: t('social', 'Google Takeout → YouTube and YouTube Music. The archive holds your videos as files rather than as posts, so there is nothing here that can read it as a timeline yet.'),
					noPosts: t('social', 'Your own uploads are yours to re-publish rather than something to import in bulk — they are whole videos, and each one belongs on a post you write.'),
				},
			]
		},

		/** @return {object|null} the network picked, if any */
		chosen() {
			return this.networks.find((one) => one.id === this.network) ?? null
		},

		/**
		 * Whether there is an account to name. Before the answer arrives the
		 * card says `@you` and the words are empty, and neither is worth
		 * saving or copying.
		 *
		 * @return {boolean}
		 */
		announcementReady() {
			return Boolean(this.announcement.handle) && Boolean(this.announcement.text)
		},
	},

	watch: {
		// a reader who picks a network before the answer lands would otherwise
		// keep the card drawn without their name on it
		announcement() {
			this.$nextTick(() => this.drawCard())
		},
	},

	mounted() {
		this.loadAnnouncement()
	},

	beforeUnmount() {
		window.clearTimeout(this.copiedTimer)
	},

	methods: {
		/** Opens the file chooser for the export archive. */
		pickArchive() {
			/** @type {HTMLInputElement} */ (this.$refs.archive).click()
		},

		t,
		n,

		async loadAnnouncement() {
			this.announcementFailed = false
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/migration/announcement'))
				this.announcement = data
			} catch (error) {
				logger.warn('Could not read the announcement', { error })
				this.announcementFailed = true
			}
		},

		/**
		 * @param {string} id the network picked. What was read and found for
		 *        another one belongs to that one's archive, not this.
		 */
		choose(id) {
			if (id !== this.network) {
				this.archiveFile = null
				this.postsResult = ''
				this.candidates = []
				this.probed = 0
				this.found = []
				this.selected = []
			}
			this.network = id
			this.$nextTick(() => this.drawCard())
		},

		async onArchive(event) {
			const file = event.target.files?.[0]
			if (!file) {
				return
			}

			this.archiveFile = file
			this.busy = 'posts'
			this.postsResult = ''
			try {
				const body = new FormData()
				body.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/posts'),
					body,
				)
				this.postsResult = n(
					'social',
					'Brought over %n post.',
					'Brought over %n posts.',
					data.imported ?? 0,
				)
				if (data.capped) {
					this.postsResult += ' ' + t('social', 'There are more: run it again to carry on.')
				}
			} catch (error) {
				logger.warn('Could not import posts', { error })
				showError(error?.response?.data?.error || t('social', 'That archive could not be read'))
			} finally {
				this.busy = ''
				// so picking the same file again still fires a change
				event.target.value = ''
			}
		},

		async readPeople() {
			if (!this.archiveFile) {
				return
			}

			this.busy = 'people'
			try {
				const body = new FormData()
				body.append('file', this.archiveFile)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/people'),
					body,
				)
				this.candidates = data.handles ?? []
				this.probed = 0
				this.found = []
				this.selected = []
				if (this.candidates.length === 0) {
					showError(t('social', 'That archive lists nobody to look up'))
				}
			} catch (error) {
				logger.warn('Could not read the follow list', { error })
				showError(error?.response?.data?.error || t('social', 'That archive could not be read'))
			} finally {
				this.busy = ''
			}

			if (this.candidates.length > 0) {
				await this.probeMore()
			}
		},

		/**
		 * One batch of lookups. The server decides how many it will do, so the
		 * cursor moves by what it says it checked rather than by a number
		 * agreed here — the two drifting apart is how a loop like this ends up
		 * asking about the same names forever.
		 */
		async probeMore() {
			this.busy = 'probe'
			try {
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/people/find'),
					{ handles: this.candidates.slice(this.probed) },
				)
				const checked = data.checked ?? 0
				this.probed += (checked > 0) ? checked : this.candidates.length
				for (const person of data.found ?? []) {
					this.found.push(person)
					this.selected.push(person.handle)
				}
			} catch (error) {
				logger.warn('Could not look up names', { error })
				showError(error?.response?.data?.error || t('social', 'Could not look those names up'))
			} finally {
				this.busy = ''
			}
		},

		toggle(handle, on) {
			this.selected = on
				? [...this.selected, handle]
				: this.selected.filter((one) => one !== handle)
		},

		/**
		 * Follows the ticked accounts through the same endpoint a Mastodon
		 * export goes through, by handing it the same CSV — one follow request
		 * per account, which is what a follow is.
		 */
		async followSelected() {
			this.busy = 'follow'
			try {
				const csv = 'Account address\n' + this.selected.join('\n') + '\n'
				const body = new FormData()
				body.append('file', new Blob([csv], { type: 'text/csv' }), 'following_accounts.csv')
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/migration/follows'),
					body,
				)
				showSuccess(n(
					'social',
					'Followed %n account',
					'Followed %n accounts',
					data.followed ?? 0,
				))
			} catch (error) {
				logger.warn('Could not follow the chosen accounts', { error })
				showError(error?.response?.data?.error || t('social', 'Could not follow those accounts'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The card, drawn rather than fetched: it names an account on this
		 * server and nothing else, so there is nothing for a server to render
		 * that a canvas cannot.
		 *
		 * No `ctx.filter` anywhere in here — Safari does not have it and
		 * assigning it fails silently, which would leave one browser drawing a
		 * subtly different card with no error to go on.
		 */
		drawCard() {
			const canvas = /** @type {HTMLCanvasElement|undefined} */ (this.$refs.card)
			const ctx = canvas?.getContext?.('2d')
			if (!ctx) {
				return
			}

			const { width, height } = canvas
			const sweep = ctx.createLinearGradient(0, 0, width, height)
			sweep.addColorStop(0, '#1a1a2e')
			sweep.addColorStop(1, '#16213e')
			ctx.fillStyle = sweep
			ctx.fillRect(0, 0, width, height)

			ctx.textAlign = 'center'

			ctx.fillStyle = '#8ab4f8'
			ctx.font = '600 44px system-ui, -apple-system, sans-serif'
			ctx.fillText(t('social', 'I have moved to the fediverse'), width / 2, 220)

			ctx.fillStyle = '#fff'
			ctx.font = '700 72px system-ui, -apple-system, sans-serif'
			this.fitText(ctx, this.announcement.handle || '@you', width - 120, 72, 340)

			ctx.fillStyle = '#c9d1d9'
			ctx.font = '400 34px system-ui, -apple-system, sans-serif'
			this.fitText(ctx, this.announcement.url || '', width - 160, 34, 430)

			ctx.fillStyle = '#8b949e'
			ctx.font = '400 30px system-ui, -apple-system, sans-serif'
			ctx.fillText(
				t('social', 'Follow me from any fediverse account — no account here needed'),
				width / 2,
				520,
			)
		},

		/**
		 * One line of text shrunk until it fits, because a handle and a URL are
		 * both as long as somebody's server name makes them and a card with the
		 * address running off the side is the one thing it must not do.
		 *
		 * @param {CanvasRenderingContext2D} ctx the card being drawn on
		 * @param {string} text the line to draw
		 * @param {number} maxWidth how wide it may be, in canvas pixels
		 * @param {number} size the size to start from, in canvas pixels
		 * @param {number} y the baseline to draw it on
		 */
		fitText(ctx, text, maxWidth, size, y) {
			let at = size
			while (at > 16 && ctx.measureText(text).width > maxWidth) {
				at -= 2
				ctx.font = ctx.font.replace(/\d+px/, at + 'px')
			}
			ctx.fillText(text, ctx.canvas.width / 2, y)
		},

		downloadCard() {
			/** @type {HTMLCanvasElement|undefined} */ (this.$refs.card)?.toBlob((blob) => {
				if (!blob) {
					return
				}

				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = 'fediverse.png'
				link.click()
				URL.revokeObjectURL(url)
			})
		},

		async copyAnnouncement() {
			try {
				await navigator.clipboard.writeText(this.announcement.text)
				this.copied = true
				// so the button goes back to saying what it does, not what it once did
				window.clearTimeout(this.copiedTimer)
				this.copiedTimer = window.setTimeout(() => {
					this.copied = false
				}, 3000)
			} catch (error) {
				// no clipboard permission, or a page served over plain HTTP:
				// the words are on screen and selectable either way
				logger.debug('clipboard refused', { error })
				showError(t('social', 'Could not copy — the words are below, to select'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.switch {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	max-width: 720px;
	margin-inline: auto;

	&__title {
		margin: 0 0 4px;
		font-size: 22px;
		font-weight: 700;
	}

	&__lead,
	&__note,
	&__announce {
		line-height: 1.5;
	}

	&__lead {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__step {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
	}

	&__heading {
		margin: 0;
		font-size: 17px;
		font-weight: 700;
	}

	&__note {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 90%;
	}

	&__done {
		margin: 0;
		font-weight: bold;
	}

	&__progress {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__file {
		display: none;
	}

	&__buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	&__card {
		inline-size: 100%;
		block-size: auto;
		border-radius: var(--border-radius-large, 12px);
		border: 1px solid var(--color-border);
	}

	&__announce {
		margin: 0;
		padding: 12px;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-hover);
		font-style: italic;
	}
}

.networks {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;

	&__item {
		display: flex;
		align-items: center;
		gap: 10px;
		inline-size: 100%;
		padding: 12px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		&--chosen {
			border-color: var(--color-primary-element);
			background: var(--color-primary-element-light);
		}
	}

	&__mark {
		display: flex;
		align-items: center;
		justify-content: center;
		inline-size: 36px;
		block-size: 36px;
		flex: 0 0 auto;
		border-radius: 50%;
		color: #fff;
		font-size: 18px;
	}

	&__name {
		font-weight: bold;
	}
}

.people {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
	max-block-size: 320px;
	overflow-y: auto;

	&__who {
		display: flex;
		flex-direction: column;
	}

	&__name {
		font-weight: bold;
	}

	&__handle {
		color: var(--color-text-maxcontrast);
		font-size: 90%;
	}
}
</style>
