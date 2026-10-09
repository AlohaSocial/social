<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="social__blocked">
		<h2 class="social__blocked-title">
			{{ t('social', 'Blocking') }}
		</h2>
		<p class="social__blocked-lede">
			{{ t('social', 'Everything you have decided not to read, in one place: the accounts you have blocked or muted, the servers you have hidden, the words you would rather not see — and the senders your notification settings are holding back, waiting on you.') }}
		</p>

		<!-- One card per decision, all of them the same shape: what it is, one
		     line saying what it does, then the list. They used to be four runs
		     of text with a bold word over each, and the two that are usually
		     empty took a screenful apiece to say so. -->
		<section class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<AccountCancelOutline :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Blocked') }}
				</h3>
				<span v-if="blocked.length" class="block-card__count">{{ blocked.length }}</span>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'They cannot see you and you cannot see them. Blocking severs the relationship in both directions, and the account knows.') }}
			</p>

			<p v-if="!loading && blocked.length === 0" class="block-card__empty">
				{{ t('social', 'No blocked accounts') }} — {{ t('social', 'Accounts you block from their profile show up here.') }}
			</p>
			<!-- keyed on the account id, never on the index: an index key makes
			     Vue patch the row above into the one below on removal and drop
			     the last row, so the wrong row would collapse -->
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="account in blocked" :key="`block-${account.id}`" class="blocked-account">
					<div class="blocked-account__user">
						<ActorAvatar :actor="account" />
						<router-link :to="{ name: 'profile', params: { account: account.acct } }">
							<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
							<span class="blocked-account__acct">{{ account.acct }}</span>
						</router-link>
					</div>
					<NcButton
						:disabled="busy.includes(account.id)"
						:aria-label="t('social', 'Unblock')"
						@click="unblock(account)">
						<template #icon>
							<Cancel :size="20" />
						</template>
						{{ t('social', 'Unblock') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<section class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<VolumeOff :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Muted') }}
				</h3>
				<span v-if="muted.length" class="block-card__count">{{ muted.length }}</span>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Out of your timelines, and they are never told. A mute leaves the follow alone, so you can undo it and pick up where you were.') }}
			</p>

			<p v-if="!loading && muted.length === 0" class="block-card__empty">
				{{ t('social', 'No muted accounts') }} — {{ t('social', 'Accounts you mute from their profile show up here.') }}
			</p>
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="account in muted" :key="`mute-${account.id}`" class="blocked-account">
					<div class="blocked-account__user">
						<ActorAvatar :actor="account" />
						<router-link :to="{ name: 'profile', params: { account: account.acct } }">
							<span class="blocked-account__name">{{ account.display_name || account.username }}</span>
							<span class="blocked-account__acct">{{ account.acct }}</span>
						</router-link>
					</div>
					<NcButton
						:disabled="busy.includes(account.id)"
						:aria-label="t('social', 'Unmute')"
						@click="unmute(account)">
						<template #icon>
							<VolumeHigh :size="20" />
						</template>
						{{ t('social', 'Unmute') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<section class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<DomainOff :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Hidden servers') }}
				</h3>
				<span v-if="domains.length" class="block-card__count">{{ domains.length }}</span>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Every account on it, and everything they post, and your follows in both directions. The answer to being bothered by a server rather than by one person.') }}
			</p>

			<form class="blocked-domain__add" @submit.prevent="hideDomain">
				<NcTextField
					v-model="domainDraft"
					class="blocked-domain__field"
					:label="t('social', 'The server to hide')"
					placeholder="example.social"
					:disabled="hidingDomain" />
				<NcButton type="submit" :disabled="hidingDomain || domainDraft.trim() === ''">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ t('social', 'Hide it') }}
				</NcButton>
			</form>

			<p v-if="!loading && domains.length === 0" class="block-card__empty">
				{{ t('social', 'No hidden servers') }} — {{ t('social', 'Nothing here is hidden from you by server.') }}
			</p>
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="domain in domains" :key="`domain-${domain}`" class="blocked-account">
					<div class="blocked-account__user">
						<span class="blocked-account__name">{{ domain }}</span>
					</div>
					<NcButton
						:disabled="busy.includes(domain)"
						:aria-label="t('social', 'Show again')"
						@click="showDomain(domain)">
						<template #icon>
							<VolumeHigh :size="20" />
						</template>
						{{ t('social', 'Show again') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<!-- the same decisions, made by somebody the reader trusts and kept
		     up to date by them -->
		<section v-if="blueskyOffered" id="moderation-lists" class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<FormatListBulleted :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Shared lists') }}
				</h3>
				<span v-if="lists.length" class="block-card__count">{{ lists.length }}</span>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Lists of accounts that somebody else keeps, to mute or block everybody on them. Whoever they add later is muted or blocked too; whoever they take off is not any more. Ending a subscription undoes only what the list did.') }}
			</p>

			<form class="blocked-domain__add" @submit.prevent="subscribeList">
				<NcTextField
					v-model="listDraft"
					class="blocked-domain__field"
					:label="t('social', 'Link to the list')"
					placeholder="https://bsky.app/profile/…/lists/…"
					:disabled="subscribing" />
				<NcSelect
					v-model="listKind"
					class="blocked-domain__kind"
					:inputLabel="t('social', 'Everybody on it is')"
					:options="listKinds"
					:clearable="false"
					:searchable="false"
					:disabled="subscribing"
					label="text" />
				<NcButton type="submit" :disabled="subscribing || listDraft.trim() === ''">
					{{ t('social', 'Subscribe') }}
				</NcButton>
			</form>

			<p v-if="!loading && lists.length === 0" class="block-card__empty">
				{{ t('social', 'No shared lists') }} — {{ t('social', 'Paste the link of a moderation list to subscribe to it.') }}
			</p>
			<transition-group name="collapse" tag="div" class="blocked-account-list">
				<div v-for="list in lists" :key="`list-${list.uri}`" class="blocked-account">
					<div class="blocked-account__user blocked-account__user--column">
						<span class="blocked-account__name">{{ list.name || list.uri }}</span>
						<span class="blocked-account__acct">
							{{ list.kind === 'block'
								? n('social', 'Blocks %n account', 'Blocks %n accounts', list.accounts)
								: n('social', 'Mutes %n account', 'Mutes %n accounts', list.accounts) }}
						</span>
					</div>
					<NcButton
						:disabled="busy.includes(list.uri)"
						:aria-label="t('social', 'Unsubscribe')"
						@click="unsubscribeList(list)">
						{{ t('social', 'Unsubscribe') }}
					</NcButton>
				</div>
			</transition-group>
		</section>

		<!-- last: the three above are people and servers, and this one applies
		     to everybody, the people you follow included -->
		<section id="filters" class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<FilterOutline :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Filtered words') }}
				</h3>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Words you would rather not read, wherever they appear — from the people you follow as much as from anybody else. A post carrying one is folded away behind the name of the filter, or taken out of your timelines altogether. Filters are yours alone, and a filter set in a phone app has been applying here all along.') }}
			</p>
			<FiltersSettings />
		</section>

		<!-- one more rule that applies to everybody: not a word but a kind of
		     post, and one switch rather than a list -->
		<section id="ai-content" class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<CreationOutline :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Posts made with AI') }}
				</h3>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Hides posts that are tagged as made with AI, that their author marked, or whose pictures say so in their metadata. It cannot recognise what nobody labelled.') }}
			</p>
			<NcCheckboxRadioSwitch
				type="switch"
				class="block-card__switch"
				:modelValue="hideAi"
				@update:modelValue="setHideAi">
				{{ t('social', 'Hide posts made with AI') }}
			</NcCheckboxRadioSwitch>
		</section>

		<!-- the same kind of rule, made by somebody else: a labeler marks
		     posts on Bluesky, and the reader decides what each mark does -->
		<section v-if="blueskyOffered && !labelersOff" id="labelers" class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<TagOutline :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Bluesky labelers') }}
				</h3>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Labelers mark posts on Bluesky. Choose what each label does for you: nothing, a warning, or hiding the post. Bluesky\'s own moderation always applies.') }}
			</p>
			<BlueskyLabelersSettings @unavailable="labelersOff = true" />
		</section>

		<!-- and after the standing rules, the one thing here that is still
		     waiting on the reader: the senders a notification policy is
		     holding. It was a sidebar entry of its own, which stayed empty
		     for anybody whose policy holds nothing. -->
		<section id="filtered-notifications" class="block-card">
			<header class="block-card__head">
				<span class="block-card__icon">
					<IconInboxOutline :size="20" />
				</span>
				<h3 class="block-card__title">
					{{ t('social', 'Filtered notifications') }}
				</h3>
			</header>
			<p class="block-card__lede">
				{{ t('social', 'Your notification settings can hold notifications back instead of showing them — from accounts nobody here follows, from brand-new accounts, from people you do not follow. The senders wait at the top of Activities now, where you decide about the person once; which of them are held is set under Who may reach you.') }}
			</p>
			<p class="block-card__links">
				<RouterLink :to="{ name: 'timeline', params: { type: 'notifications' }, query: { requests: '1' } }">
					{{ t('social', 'Open Activities') }}
				</RouterLink>
				<RouterLink :to="{ name: 'settings', hash: '#notification-policy' }">
					{{ t('social', 'Who may reach you') }}
				</RouterLink>
			</p>
		</section>

		<div v-if="loading" class="loading-indicator">
			{{ t('social', 'Loading…') }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '../services/toast.js'
import { fetchAiContent, saveAiContent } from '../services/aiContent.js'
import ActorAvatar from '../components/ActorAvatar.vue'
import BlueskyLabelersSettings from '../components/BlueskyLabelersSettings.vue'
import FiltersSettings from '../components/FiltersSettings.vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountCancelOutline from 'vue-material-design-icons/AccountCancelOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CreationOutline from 'vue-material-design-icons/CreationOutline.vue'
import DomainOff from 'vue-material-design-icons/DomainOff.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconInboxOutline from 'vue-material-design-icons/InboxOutline.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import VolumeHigh from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOff from 'vue-material-design-icons/VolumeOff.vue'
import logger from '../services/logger.js'
import { fetchModerationLists, subscribeModerationList, unsubscribeModerationList } from '../services/moderationLists.js'
import { mapStores } from 'pinia'
import { useAccountStore } from '../store/account.js'
import { useServerData } from '../composables/useServerData.js'

export default {
	name: 'BlockedAccounts',
	components: {
		AccountCancelOutline,
		ActorAvatar,
		BlueskyLabelersSettings,
		CreationOutline,
		DomainOff,
		FilterOutline,
		FiltersSettings,
		FormatListBulleted,
		IconInboxOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		Cancel,
		TagOutline,
		VolumeHigh,
		VolumeOff,
	},

	setup() {
		const { serverData } = useServerData()

		return { serverData }
	},

	data() {
		return {
			/** @type {import('../types/Mastodon.js').Account[]} */
			blocked: [],
			/** @type {import('../types/Mastodon.js').Account[]} */
			muted: [],
			/** @type {string[]} the servers hidden from this account */
			domains: [],
			domainDraft: '',
			hidingDomain: false,
			/** @type {import('../services/moderationLists.js').ModerationList[]} */
			lists: [],
			listDraft: '',
			listKind: null,
			subscribing: false,
			busy: [],
			loading: true,
			/** whether posts made with AI are hidden, as the server last confirmed it */
			hideAi: false,
			/** whether the labelers route said Bluesky is off after all */
			labelersOff: false,
		}
	},

	computed: {
		...mapStores(useAccountStore),

		/** @return {boolean} whether this instance offers Bluesky */
		blueskyOffered() {
			return this.serverData?.bluesky?.enabled === true
		},

		/** @return {{id: string, text: string}[]} what a shared list may do */
		listKinds() {
			return [
				{ id: 'mute', text: t('social', 'Muted') },
				{ id: 'block', text: t('social', 'Blocked') },
			]
		},
	},

	async mounted() {
		// side by side: the one switch is not one of the lists, and a server
		// that cannot answer for it must not take the lists down with it
		this.listKind = this.listKinds[0]
		await Promise.all([this.fetchAll(), this.loadAiContent(), this.loadLists()])
	},

	methods: {
		/**
		 * An unreadable setting is not worth a toast on a page the reader may
		 * only be passing through; the switch shows the default and still works.
		 */
		async loadAiContent() {
			try {
				this.hideAi = (await fetchAiContent()).hide
			} catch (error) {
				logger.debug('Could not read whether posts made with AI are hidden', { error })
			}
		},

		/**
		 * Flips the switch at once and sends the change; on a refusal the
		 * switch goes back to what the server has and the reason is shown.
		 *
		 * @param {boolean} hide what the reader set the switch to
		 */
		async setHideAi(hide) {
			const before = this.hideAi
			this.hideAi = hide
			try {
				this.hideAi = (await saveAiContent(hide)).hide
			} catch (error) {
				logger.error('Could not save whether posts made with AI are hidden', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
				this.hideAi = before
			}
		},

		/**
		 * The shared lists stand on their own: a server that cannot answer
		 * for them must not take the accounts down with them.
		 */
		async loadLists() {
			if (!this.blueskyOffered) {
				return
			}
			try {
				this.lists = await fetchModerationLists()
			} catch (error) {
				logger.debug('Could not read the shared lists', { error })
			}
		},

		/**
		 * Subscribes to the list the link names; what it muted or blocked is
		 * read again, as it is the server that knows.
		 */
		async subscribeList() {
			const link = this.listDraft.trim()
			if (link === '') {
				return
			}
			this.subscribing = true
			try {
				await subscribeModerationList(link, this.listKind?.id ?? 'mute')
				this.listDraft = ''
				await Promise.all([this.loadLists(), this.fetchAll()])
			} catch (error) {
				logger.error('Failed to subscribe to the list', { error })
				showError(error?.response?.status === 422
					? t('social', 'That is not a list that can be read here')
					: t('social', 'Could not subscribe to that list'))
			} finally {
				this.subscribing = false
			}
		},

		/**
		 * @param {import('../services/moderationLists.js').ModerationList} list the subscription to end
		 */
		async unsubscribeList(list) {
			this.busy.push(list.uri)
			try {
				this.lists = await unsubscribeModerationList(list.uri)
				await this.fetchAll()
			} catch (error) {
				logger.error('Failed to unsubscribe from the list', { error })
				showError(t('social', 'Could not unsubscribe from that list'))
			} finally {
				this.busy = this.busy.filter((one) => one !== list.uri)
			}
		},

		async fetchAll() {
			this.loading = true
			try {
				const [blocked, muted, domains] = await Promise.all([
					axios.get(generateUrl('apps/social/api/v1/blocks')),
					axios.get(generateUrl('apps/social/api/v1/mutes')),
					axios.get(generateUrl('apps/social/api/v1/domain_blocks')),
				])
				this.blocked = Array.isArray(blocked.data) ? blocked.data : []
				this.muted = Array.isArray(muted.data) ? muted.data : []
				this.domains = Array.isArray(domains.data) ? domains.data : []
			} catch (error) {
				logger.error('Failed to load the blocked and muted accounts', { error })
				showError(t('social', 'Failed to load the blocked and muted accounts'))
			} finally {
				this.loading = false
			}
		},

		/** @param {import('../types/Mastodon.js').Account} account the account to unblock */
		async unblock(account) {
			// the store action keeps the relationship state the profile page reads
			await this.act(account, 'unblockAccount', 'blocked')
		},

		/** @param {import('../types/Mastodon.js').Account} account the account to unmute */
		async unmute(account) {
			await this.act(account, 'unmuteAccount', 'muted')
		},

		/**
		 * Hides a whole server from this account.
		 *
		 * @return {Promise<void>}
		 */
		async hideDomain() {
			const domain = this.domainDraft.trim().replace(/^@/, '').toLowerCase()
			if (domain === '') {
				return
			}

			this.hidingDomain = true
			try {
				await axios.post(generateUrl('apps/social/api/v1/domain_blocks'), { domain })
				this.domainDraft = ''
				// re-read rather than push: the server decides what the domain
				// normalises to, and a row that said something else would be a
				// row the unhide button could not act on
				await this.fetchAll()
			} catch (error) {
				logger.error('Failed to hide the server', { error })
				showError(t('social', 'Could not hide that server'))
			} finally {
				this.hidingDomain = false
			}
		},

		/**
		 * @param {string} domain the server to show again
		 * @return {Promise<void>}
		 */
		async showDomain(domain) {
			this.busy.push(domain)
			try {
				await axios.delete(generateUrl('apps/social/api/v1/domain_blocks'), {
					data: { domain },
				})
				this.domains = this.domains.filter((one) => one !== domain)
			} catch (error) {
				logger.error('Failed to show the server again', { error })
				showError(t('social', 'Could not show that server again'))
			} finally {
				this.busy = this.busy.filter((one) => one !== domain)
			}
		},

		/**
		 * @param {import('../types/Mastodon.js').Account} account the account acted on
		 * @param {string} action the name of the account-store action to call
		 * @param {string} list the list to take the account off on success
		 */
		async act(account, action, list) {
			this.busy.push(account.id)
			try {
				const result = await this.accountStore[action]({ id: account.id })
				// the store reports its own failure; leave the row in place then,
				// so nothing claims an account was unblocked when it was not
				if (result) {
					this[list] = this[list].filter((entry) => entry.id !== account.id)
				}
			} finally {
				this.busy = this.busy.filter((id) => id !== account.id)
			}
		},
	},
}
</script>

<style scoped lang="scss">
.social__blocked {
	max-width: var(--social-column);
	margin: 15px auto;
	padding: 0 10px;
}

.social__blocked-title {
	margin-bottom: 4px;
}

.social__blocked-lede {
	margin-bottom: 18px;
	color: var(--color-text-maxcontrast);
}

/* One card per decision. A card rather than a heading and a rule because
   these four are parallel and independent -- a reader looking for the muted
   list should be able to find it by shape, not by reading down. */
.block-card {
	margin-bottom: 14px;
	padding: 14px 16px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);

	&__head {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	/* the icon says which of the four this is at a glance, and the disc keeps
	   the four heads the same height whatever the glyph */
	&__icon {
		display: flex;
		align-items: center;
		justify-content: center;
		flex: 0 0 auto;
		width: 32px;
		height: 32px;
		border-radius: 50%;
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}

	&__title {
		margin: 0;
		font-size: 16px;
		font-weight: bold;
	}

	/* how many, where the eye already is. Absent at zero: a 0 in a pill is
	   something to read and then discard */
	&__count {
		margin-inline-start: auto;
		padding: 1px 9px;
		border-radius: 12px;
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		font-size: 12px;
		font-weight: bold;
	}

	&__lede {
		margin: 8px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		line-height: 1.5;
	}

	/* One line rather than a full empty state. Two of these four are empty on
	   almost every instance, and an illustration apiece pushed the lists that
	   do have something in them off the bottom of the screen. */
	&__empty {
		margin: 10px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	/* the one card that is a switch rather than a list; the same breathing
	   room under the lede as the first row of a list gets */
	&__switch {
		margin-top: 6px;
	}
}

.blocked-account {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-top: 4px;
	padding: 8px;
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}

	/* a rule between rows, not under the last one: the card's own edge is
	   already the line at the bottom */
	& + & {
		border-top: 1px solid var(--color-border);
		border-radius: 0 0 var(--border-radius) var(--border-radius);
	}

	&__user {
		display: flex;
		align-items: center;
		gap: 12px;
		min-width: 0;

		a {
			display: flex;
			flex-direction: column;
			min-width: 0;
		}
	}

	&__user--column {
		flex-direction: column;
		align-items: flex-start;
		gap: 0;
	}

	&__name {
		font-weight: bold;
	}

	&__acct {
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
	}
}

.loading-indicator {
	text-align: center;
	padding: 20px;
}

/**
 * An unblocked or unmuted account collapses out of its list rather than
 * blinking away, so the row that answered stays visible for the moment it
 * takes to go and the rows below close the gap instead of jumping.
 *
 * Same motion as the shared `list` transition in App.vue — .2s ease for the
 * fade and the shift — with the height taken out over .3s ease-out, the way
 * the welcome banner in Timeline.vue collapses. The max-height is headroom
 * for one row, not a measurement: it only has to be larger than a row.
 */
.collapse-enter-active,
.collapse-leave-active {
	overflow: hidden;
	max-height: 120px;
	transition: opacity .2s ease, transform .2s ease, max-height .3s ease-out, padding .3s ease-out;
}

.collapse-enter-from,
.collapse-leave-to {
	opacity: 0;
	transform: translateY(-6px);
	max-height: 0;
	padding-top: 0;
	padding-bottom: 0;
}

/* an inserted row pushes the others down instead of displacing them */
.collapse-move {
	transition: transform .2s ease;
}

/* the empty state arrives as the last row finishes collapsing, not on top of it */
.empty-enter-active {
	transition: opacity .2s ease .25s, transform .2s ease .25s;
}

.empty-leave-active {
	transition: opacity .15s ease;
}

.empty-enter-from,
.empty-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
	.collapse-enter-active,
	.collapse-leave-active,
	.collapse-move,
	.empty-enter-active,
	.empty-leave-active {
		transition: none;
	}
}

.blocked-domain__add {
	margin-top: 12px;
	display: flex;
	align-items: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 8px;
}

.blocked-domain__field {
	max-width: 320px;
}

.blocked-domain__kind {
	min-width: 140px;
}

.block-card__links {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin: 0;
}
</style>
