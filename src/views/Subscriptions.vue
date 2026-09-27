<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="subs social__wrapper">
		<h1 class="subs__title">
			{{ t('social', 'Subscriptions') }}
		</h1>
		<p class="subs__lead">
			{{ t('social', 'Channels and blogs that are not on the fediverse, followed by their feed. What comes out appears here with a link to where it is — nothing is copied onto this server, and nothing here is a post: it cannot be boosted, replied to or federated, because it is not ours to publish.') }}
		</p>

		<form class="subs__add" @submit.prevent="add">
			<NcTextField
				v-model="url"
				class="subs__field"
				:label="t('social', 'A feed address, or a YouTube channel')"
				:placeholder="t('social', 'https://example.org/feed — or a YouTube channel link')"
				:disabled="busy !== ''" />
			<NcButton variant="primary" type="submit" :disabled="busy !== '' || url === ''">
				<template #icon>
					<NcLoadingIcon v-if="busy === 'add'" :size="20" />
					<IconPlus v-else :size="20" />
				</template>
				{{ t('social', 'Follow') }}
			</NcButton>
		</form>

		<details class="subs__takeout">
			<summary>{{ t('social', 'Bring your YouTube subscriptions over') }}</summary>
			<p>
				{{ t('social', 'Google Takeout → YouTube and YouTube Music → subscriptions. The file is called subscriptions.csv and names every channel you follow; each one becomes a subscription here. Your own uploads are not imported — they are whole videos, and each belongs on a post you write.') }}
			</p>
			<input
				ref="takeout"
				type="file"
				class="subs__file"
				accept=".csv,text/csv"
				@change="importTakeout">
			<NcButton :disabled="busy !== ''" @click="$refs.takeout.click()">
				<template #icon>
					<NcLoadingIcon v-if="busy === 'takeout'" :size="20" />
					<IconUpload v-else :size="20" />
				</template>
				{{ t('social', 'Choose subscriptions.csv') }}
			</NcButton>
		</details>

		<section v-if="feeds.length" class="subs__feeds" :aria-label="t('social', 'What you follow')">
			<h2 class="subs__heading">
				{{ t('social', 'What you follow') }}
			</h2>
			<ul class="feeds">
				<li v-for="feed in feeds" :key="feed.id" class="feeds__item">
					<span class="feeds__what">
						<a
							v-if="feed.site_url"
							class="feeds__name"
							:href="feed.site_url"
							target="_blank"
							rel="noopener noreferrer">{{ feed.title }}</a>
						<span v-else class="feeds__name">{{ feed.title }}</span>
						<span class="feeds__meta">
							{{ n('social', '%n entry', '%n entries', feed.items) }}
						</span>
						<span v-if="feed.error" class="feeds__error">{{ feed.error }}</span>
						<span v-else-if="feed.read && !feed.items" class="feeds__quiet">
							{{ t('social', 'Read fine, but it lists nothing') }}
						</span>
						<span v-else-if="!feed.read" class="feeds__quiet">
							{{ t('social', 'Not read yet') }}
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('social', 'Unfollow {title}', { title: feed.title })"
						:disabled="busy !== ''"
						@click="remove(feed)">
						<template #icon>
							<IconClose :size="20" />
						</template>
					</NcButton>
				</li>
			</ul>
		</section>

		<section class="subs__items" :aria-label="t('social', 'Latest')">
			<h2 class="subs__heading">
				{{ t('social', 'Latest') }}
			</h2>

			<!-- a list that could not be fetched is not a list with nothing in it -->
			<div v-if="failed" class="subs__failed" role="alert">
				<p>{{ t('social', 'Your subscriptions could not be loaded.') }}</p>
				<NcButton :disabled="busy !== ''" @click="retry">
					<template #icon>
						<NcLoadingIcon v-if="busy === 'load'" :size="20" />
						<IconRefresh v-else :size="20" />
					</template>
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="items.length === 0 && busy !== 'load'"
				:name="t('social', 'Nothing yet')"
				:description="t('social', 'Follow a channel or a blog above, and what it publishes turns up here.')">
				<template #icon>
					<IconRssBox :size="20" />
				</template>
			</NcEmptyContent>

			<ul v-if="items.length" class="entries">
				<li v-for="item in items" :key="item.id" class="entries__item">
					<div v-if="item.video_id" class="entries__video">
						<iframe
							v-if="activeVideo === item.id"
							class="entries__player"
							:src="embedUrl(item)"
							:title="item.title"
							allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
							allowfullscreen
							loading="lazy"
							referrerpolicy="strict-origin-when-cross-origin" />
						<button
							v-if="activeVideo === item.id"
							class="entries__close"
							type="button"
							:aria-label="t('social', 'Close player')"
							@click="activeVideo = null">
							<span aria-hidden="true">×</span>
						</button>
						<button
							v-else
							class="entries__poster"
							type="button"
							:aria-label="t('social', 'Play {title}', { title: item.title })"
							@click="play(item)">
							<img
								v-if="item.thumbnail"
								:src="item.thumbnail"
								alt=""
								loading="lazy"
								referrerpolicy="no-referrer">
							<span v-if="item.thumbnail" class="entries__play" aria-hidden="true">▶</span>
							<span v-else class="entries__play-label">▶ {{ t('social', 'Play video') }}</span>
						</button>
					</div>
					<div class="entries__body">
						<a
							v-if="item.link"
							class="entries__title"
							:href="item.link"
							target="_blank"
							rel="noopener noreferrer"
							referrerpolicy="no-referrer">{{ item.title }}</a>
						<span v-else class="entries__title">{{ item.title }}</span>
						<span class="entries__meta">
							{{ item.feed_title }}
							<template v-if="item.published">· {{ when(item.published) }}</template>
						</span>
						<span v-if="item.summary" class="entries__summary">{{ item.summary }}</span>
						<a
							v-if="item.video_id"
							class="entries__source"
							:href="item.link"
							target="_blank"
							rel="noopener noreferrer"
							referrerpolicy="no-referrer">{{ t('social', 'Open on YouTube, including comments') }}</a>
					</div>
				</li>
			</ul>

			<NcButton v-if="items.length && !allLoaded && !failed" :disabled="busy !== ''" @click="load">
				<template #icon>
					<NcLoadingIcon v-if="busy === 'load'" :size="20" />
					<IconChevronDown v-else :size="20" />
				</template>
				{{ t('social', 'Older') }}
			</NcButton>
		</section>
	</div>
</template>

<script>
/**
 * What somebody watches that is not on the fediverse.
 *
 * Nobody mirrors YouTube on PeerTube reliably, and telling somebody who
 * watches twelve channels that leaving means giving up all twelve is what
 * makes them not leave. Every channel publishes an Atom feed, so the channels
 * come along.
 *
 * The page is deliberately not a timeline. An entry is a headline, a summary
 * and a link out — there is no boost, no reply, no favourite, because this server
 * has no right to publish somebody else's video and an id minted for one would
 * be an address pretending to be the thing. Everything anybody does with an
 * entry happens on the site it came from.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t, n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconRefresh from 'vue-material-design-icons/Refresh.vue'
import IconRssBox from 'vue-material-design-icons/RssBox.vue'
import IconUpload from 'vue-material-design-icons/Upload.vue'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { fromNow } from '../utils/relativeTime.js'

export default {
	name: 'Subscriptions',
	components: {
		IconChevronDown,
		IconClose,
		IconPlus,
		IconRefresh,
		IconRssBox,
		IconUpload,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			url: '',
			feeds: [],
			items: [],
			/** One player at a time; loading the iframe contacts YouTube. */
			activeVideo: null,
			allLoaded: false,
			/** Keep independent request failures separate so retry can do the right one. */
			feedsFailed: false,
			itemsFailed: false,
			/** '', 'add', 'remove', 'load', 'takeout' */
			busy: '',
		}
	},

	computed: {
		failed() {
			return this.feedsFailed || this.itemsFailed
		},
	},

	async mounted() {
		// These are independent requests; do not serialize an extra round trip
		// before the first entries can render.
		await Promise.all([this.refresh(), this.load()])
	},

	methods: {
		t,
		n,

		when(published) {
			return fromNow(published)
		},

		embedUrl(item) {
			return `https://www.youtube-nocookie.com/embed/${item.video_id}?autoplay=1`
		},

		play(item) {
			this.activeVideo = item.id
		},

		async refresh() {
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/subscriptions'))
				this.feeds = data.feeds ?? []
				this.feedsFailed = false
			} catch (error) {
				logger.warn('Could not list subscriptions', { error })
				this.feedsFailed = true
			}
		},

		/**
		 * The next page of entries, paged on publication date and row id.
		 *
		 * Entries from different feeds can share a timestamp, so the row id is
		 * the stable tie-breaker after the publication date.
		 */
		async load() {
			if (this.busy !== '' || this.allLoaded) {
				return
			}

			this.busy = 'load'
			try {
				await this.fetchPage()
			} finally {
				this.busy = ''
			}
		},

		/**
		 * One page appended, with no check of `busy`: `reload()` runs inside
		 * an add, a remove or an import, which hold `busy` for their own
		 * spinner, and the list they emptied has to be filled again.
		 */
		async fetchPage() {
			try {
				const params = { limit: 40 }
				const last = this.items.at(-1)
				if (last) {
					params.before = last.cursor
					params.before_id = last.id
				}

				const { data } = await axios.get(
					generateUrl('apps/social/api/v1/subscriptions/timeline'),
					{ params },
				)
				const page = data.items ?? []
				this.items.push(...page)
				this.allLoaded = page.length === 0
				this.itemsFailed = false
			} catch (error) {
				logger.warn('Could not load feed entries', { error })
				this.itemsFailed = true
			}
		},

		async add() {
			this.busy = 'add'
			try {
				await axios.post(generateUrl('apps/social/api/v1/subscriptions'), { url: this.url })
				this.url = ''
				await this.reload()
				showSuccess(t('social', 'Following'))
			} catch (error) {
				logger.warn('Could not follow that feed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not follow that'))
			} finally {
				this.busy = ''
			}
		},

		async remove(feed) {
			this.busy = 'remove'
			try {
				await axios.delete(generateUrl('apps/social/api/v1/subscriptions/' + feed.id))
				await this.reload()
			} catch (error) {
				logger.warn('Could not unfollow that feed', { error })
				showError(error?.response?.data?.error || t('social', 'Could not unfollow that'))
			} finally {
				this.busy = ''
			}
		},

		async importTakeout(event) {
			const file = event.target.files?.[0]
			if (!file) {
				return
			}

			this.busy = 'takeout'
			try {
				const body = new FormData()
				body.append('file', file)
				const { data } = await axios.post(
					generateUrl('apps/social/api/v1/subscriptions/takeout'),
					body,
				)
				await this.reload()
				showSuccess(n(
					'social',
					'Now following %n channel',
					'Now following %n channels',
					data.subscribed ?? 0,
				))
			} catch (error) {
				logger.warn('Could not import subscriptions', { error })
				const reason = error?.response?.data?.error
				showError(reason === 'that file is larger than 5 MB'
					? t('social', 'That file is larger than 5 MB')
					: reason || t('social', 'That file could not be read'))
			} finally {
				this.busy = ''
				event.target.value = ''
			}
		},

		/** What failed, asked again: both lists when nothing is shown yet, else the next page. */
		async retry() {
			if (this.itemsFailed && this.items.length > 0 && !this.feedsFailed) {
				this.itemsFailed = false
				await this.load()
				return
			}

			this.busy = 'load'
			try {
				await this.reload()
			} finally {
				this.busy = ''
			}
		},

		/** Both lists again from the top: what is followed decides what is listed. */
		async reload() {
			this.items = []
			this.allLoaded = false
			this.activeVideo = null
			this.feedsFailed = false
			this.itemsFailed = false
			await Promise.all([this.refresh(), this.fetchPage()])
		},
	},
}
</script>

<style scoped lang="scss">
.subs {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;

	&__title {
		margin: 0;
		font-size: 22px;
		font-weight: 700;
	}

	&__heading {
		margin: 0 0 8px;
		font-size: 17px;
		font-weight: 700;
	}

	&__lead {
		margin: 0;
		color: var(--color-text-maxcontrast);
		line-height: 1.5;
	}

	&__add {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		flex-wrap: wrap;
	}

	&__field {
		flex: 1 1 260px;
	}

	&__takeout {
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);

		summary {
			cursor: pointer;
			font-weight: bold;
		}

		p {
			margin: 8px 0;
			color: var(--color-text-maxcontrast);
			line-height: 1.5;
		}
	}

	&__file {
		display: none;
	}

	&__failed {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 12px;
		padding: 24px 20px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		text-align: center;

		p {
			margin: 0;
			color: var(--color-text-maxcontrast);
		}
	}
}

.feeds {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;

	&__item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 8px;
		padding: 6px 8px;
		border-radius: var(--border-radius-large, 8px);

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__what {
		display: flex;
		flex-direction: column;
		min-inline-size: 0;
	}

	&__name {
		font-weight: bold;
		color: var(--color-main-text);
		/* a feed's name is as long as its author made it, and a table row is
		   not a place for it to wrap into four lines */
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 90%;
	}

	&__quiet {
		color: var(--color-text-maxcontrast);
	}

	&__error {
		color: var(--color-error-text, var(--color-error));
		font-size: 90%;
	}
}

.entries {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0 0 12px;
	padding: 0;
	list-style: none;

	&__item {
		padding: 8px;
		border-radius: var(--border-radius-large, 12px);

		&:hover,
		&:focus-within {
			background: var(--color-background-hover);
		}
	}

	&__video {
		position: relative;
		width: min(100%, 640px);
		aspect-ratio: 16 / 9;
		overflow: hidden;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
	}

	&__player,
	&__poster,
	&__poster img {
		width: 100%;
		height: 100%;
	}

	&__player {
		border: 0;
	}

	&__close {
		position: absolute;
		inset-block-start: 8px;
		inset-inline-end: 8px;
		z-index: 1;
		width: 36px;
		height: 36px;
		border: 0;
		border-radius: 50%;
		color: #fff;
		background: rgb(0 0 0 / 75%);
		font-size: 24px;
		cursor: pointer;
	}

	&__poster {
		display: grid;
		place-items: center;
		padding: 0;
		border: 0;
		cursor: pointer;
		background: var(--color-background-dark);
	}

	&__poster img {
		position: absolute;
		inset: 0;
		object-fit: cover;
	}

	&__play {
		grid-area: 1 / 1;
		z-index: 1;
		padding: 8px 18px;
		border-radius: 12px;
		color: #fff;
		background: rgb(0 0 0 / 75%);
		font-size: 22px;
		transform: translate(-50%, -50%);
	}

	&__play-label {
		grid-area: 1 / 1;
		z-index: 1;
		padding: 8px 12px;
		border-radius: 12px;
		color: #fff;
		background: rgb(0 0 0 / 75%);
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 4px;
		min-inline-size: 0;
		margin-top: 6px;
	}

	&__title {
		font-weight: bold;
		color: var(--color-main-text);
		text-decoration: none;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__meta {
		color: var(--color-text-maxcontrast);
		font-size: 90%;
	}

	&__summary {
		color: var(--color-text-maxcontrast);
		display: -webkit-box;
		-webkit-line-clamp: 2;
		-webkit-box-orient: vertical;
		overflow: hidden;
	}

	&__source {
		font-size: 90%;
	}
}
</style>
