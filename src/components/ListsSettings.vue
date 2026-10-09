<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="lists-settings">
		<form class="lists-settings__create" @submit.prevent="create">
			<NcTextField
				v-model="newTitle"
				class="lists-settings__create-title"
				:label="t('social', 'New list')"
				:placeholder="t('social', 'What to call it')"
				maxlength="255" />
			<NcButton type="submit" variant="primary" :disabled="newTitle.trim() === '' || creating">
				<template #icon>
					<NcLoadingIcon v-if="creating" :size="20" />
					<IconPlus v-else :size="20" />
				</template>
				{{ t('social', 'Create') }}
			</NcButton>
		</form>

		<p v-if="loading" class="lists-settings__hint">
			{{ t('social', 'Loading your lists …') }}
		</p>
		<p v-else-if="lists.length === 0" class="lists-settings__hint">
			{{ t('social', 'You have no lists yet. A list is a few of the people you follow, read as a timeline of their own.') }}
		</p>

		<ul v-else class="lists-settings__list">
			<li
				v-for="list in lists"
				:key="list.id"
				class="lists-settings__item"
				:class="{ 'lists-settings__item--group': list.nextcloud_group }">
				<div class="lists-settings__row">
					<IconAccountGroup v-if="list.nextcloud_group" :size="20" class="lists-settings__icon" />
					<IconFormatListBulleted v-else :size="20" class="lists-settings__icon" />

					<form
						v-if="renaming === list.id"
						class="lists-settings__rename"
						@submit.prevent="rename(list)">
						<NcTextField
							v-model="renameTitle"
							:label="t('social', 'Name of the list')"
							:showTrailingButton="false"
							maxlength="255" />
						<NcButton type="submit" variant="primary" :disabled="renameTitle.trim() === '' || busy">
							{{ t('social', 'Save') }}
						</NcButton>
						<NcButton variant="tertiary" @click="renaming = null">
							{{ t('social', 'Cancel') }}
						</NcButton>
					</form>
					<template v-else>
						<router-link
							class="lists-settings__title"
							:to="{ name: 'list', params: { id: list.id } }">
							{{ list.title }}
						</router-link>
						<!-- nobody made a group list and nobody can change it:
						     its name and its members are the group's -->
						<span v-if="list.nextcloud_group" class="lists-settings__badge">
							{{ t('social', 'Nextcloud group') }}
						</span>
						<span v-else-if="list.public" class="lists-settings__badge">
							{{ t('social', 'Public') }}
						</span>
					</template>

					<div class="lists-settings__row-actions">
						<NcButton
							variant="tertiary"
							:pressed="expanded === list.id"
							:aria-label="t('social', 'Members of {list}', { list: list.title })"
							@click="toggleMembers(list)">
							<template #icon>
								<IconAccountMultiple :size="20" />
							</template>
							{{ t('social', 'Members') }}
						</NcButton>
						<NcActions
							v-if="!list.nextcloud_group"
							:aria-label="t('social', 'More actions for {list}', { list: list.title })">
							<NcActionButton closeAfterClick @click="startRename(list)">
								<template #icon>
									<IconPencil :size="20" />
								</template>
								{{ t('social', 'Rename') }}
							</NcActionButton>
							<NcActionButton closeAfterClick @click="deleting = list">
								<template #icon>
									<IconDelete :size="20" />
								</template>
								{{ t('social', 'Delete') }}
							</NcActionButton>
						</NcActions>
					</div>
				</div>

				<div v-if="expanded === list.id" class="lists-settings__members">
					<!-- a group's members are the group's to show, so a group
					     list stays private -->
					<div v-if="!list.nextcloud_group" class="lists-settings__visibility">
						<NcCheckboxRadioSwitch
							type="switch"
							:modelValue="Boolean(list.public)"
							:disabled="busy"
							@update:modelValue="setPublic(list, $event)">
							{{ t('social', 'Public list — anyone can see it and who is on it') }}
						</NcCheckboxRadioSwitch>
						<p class="lists-settings__hint">
							{{ t('social', 'A list you pick under “Who can reply” on a post can be seen from that post, even while it is not public.') }}
						</p>
					</div>
					<p v-if="members[list.id] === undefined" class="lists-settings__hint">
						{{ t('social', 'Loading …') }}
					</p>
					<p v-else-if="members[list.id].length === 0" class="lists-settings__hint">
						{{ list.nextcloud_group
							? t('social', 'Nobody in this group has a Aloha Social account yet.')
							: t('social', 'Nobody is in this list yet.') }}
					</p>
					<ul v-else class="lists-settings__member-list">
						<li v-for="account in members[list.id]" :key="account.id" class="lists-settings__member">
							<ActorAvatar :actor="account" :size="32" />
							<router-link
								class="lists-settings__member-name"
								:to="{ name: 'profile', params: { account: account.acct } }">
								<span>{{ account.display_name || account.username }}</span>
								<span class="lists-settings__member-acct">@{{ account.acct }}</span>
							</router-link>
							<NcButton
								v-if="!list.nextcloud_group"
								variant="tertiary"
								:disabled="busy"
								:aria-label="t('social', 'Remove {account} from the list', { account: account.acct })"
								@click="remove(list, account)">
								<template #icon>
									<IconClose :size="20" />
								</template>
							</NcButton>
						</li>
					</ul>
					<div v-if="membersCursor[list.id]" class="lists-settings__more">
						<NcButton :disabled="loadingMore === list.id" @click="fetchMoreMembers(list)">
							{{ loadingMore === list.id ? t('social', 'Loading …') : t('social', 'Show more') }}
						</NcButton>
					</div>

					<!-- a list is a view of what the reader follows, so what is
					     offered here is anybody the search knows; the server says
					     no to somebody they do not follow, and so does the toast -->
					<div v-if="!list.nextcloud_group" class="lists-settings__add">
						<NcTextField
							v-model="search"
							:label="t('social', 'Add somebody you follow')"
							:placeholder="t('social', 'Name or @handle')"
							:showTrailingButton="false"
							@update:modelValue="onSearch" />
						<ul v-if="results.length > 0" class="lists-settings__results" role="listbox">
							<li v-for="hit in results" :key="hit.id">
								<button
									type="button"
									class="lists-settings__result"
									:disabled="busy"
									@click="add(list, hit)">
									<span>{{ hit.name || hit.preferredUsername }}</span>
									<span class="lists-settings__member-acct">@{{ hit.account }}</span>
								</button>
							</li>
						</ul>
						<p v-else-if="searched && search.trim() !== ''" class="lists-settings__hint">
							{{ t('social', 'Nobody by that name.') }}
						</p>
					</div>
					<!-- A group list has no box to type in, because its members
					     are whoever is in the Nextcloud group: anything added
					     here would be taken away again the next time the group
					     changed. Saying so is the point — without it the panel
					     is a list of people with no way to add one and no
					     reason given. -->
					<p v-else class="lists-settings__hint">
						{{ t('social', 'Who is in this list follows the “{group}” group in Nextcloud. Add or remove people there and this list follows.', { group: list.nextcloud_group }) }}
					</p>
				</div>
			</li>
		</ul>

		<NcDialog
			:open="deleting !== null"
			:name="t('social', 'Delete the list {list}?', { list: deleting?.title ?? '' })"
			:buttons="deleteButtons"
			@update:open="deleting = $event ? deleting : null">
			<p class="lists-settings__hint">
				{{ t('social', 'The list goes, and so does its timeline. Nobody is unfollowed: the people in it are only taken out of the list.') }}
			</p>
		</NcDialog>
	</div>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconAccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import IconAccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconFormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import ActorAvatar from './ActorAvatar.vue'
import { nextCursor } from '../utils/linkHeader.js'
import eventBus, { LISTS_CHANGED } from '../services/eventBus.js'
import logger from '../services/logger.js'
import { showError, showSuccess } from '../services/toast.js'
import { latestLoad } from '../utils/latestLoad.js'

/** How long a pause in typing is before the search is asked. */
const SEARCH_DELAY_MS = 250

/**
 * How many members one request asks for.
 *
 * The server's own ceiling, and also the most Nextcloud will let through: its
 * dispatcher refuses any `limit` outside 1–500 before the route is reached.
 * A longer list is paged with Show more, on the cursor the server's `Link`
 * header names — the membership row, not the account, because taking
 * somebody out and putting them back gives them a new place in the list.
 */
const MEMBERS_PER_REQUEST = 500

/**
 * The reader's lists, and everything that can be done to them: made, renamed,
 * made public or private, deleted, and filled or emptied one person at a
 * time. A section of Settings.
 *
 * The sidebar only names the lists; it fetches them itself once per page, so
 * every change made here is announced on the event bus and the sidebar asks
 * again. Group lists are shown because they are lists — a reader looks for
 * them here when they wonder who is in "Design" — but they take no rename,
 * no delete and no members: the group decides all three, and the server
 * answers 422 to anybody who tries.
 */
export default {
	name: 'ListsSettings',

	components: {
		ActorAvatar,
		IconAccountGroup,
		IconAccountMultiple,
		IconClose,
		IconDelete,
		IconFormatListBulleted,
		IconPencil,
		IconPlus,
		NcActionButton,
		NcActions,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},

	data() {
		return {
			/** @type {object[]} the lists, group ones first as the sidebar has them */
			lists: [],
			loading: true,
			newTitle: '',
			creating: false,
			/** the id of the list whose name is being edited, or null */
			renaming: null,
			renameTitle: '',
			/** the list a delete is being confirmed for, or null */
			deleting: null,
			/** the id of the list whose members are open, or null */
			expanded: null,
			/** @type {Record<string, import('../types/Mastodon.js').Account[]>} members by list id, once asked */
			members: {},
			/** @type {Record<string, string>} where each list's next page of members starts, '' once there is none */
			membersCursor: {},
			/** the id of the list whose next page of members is being fetched, or null */
			loadingMore: null,
			/** whether a request that changes a list is in flight */
			busy: false,
			search: '',
			searchTimer: null,
			/** @type {object[]} what the last search answered: actors, as the search route sends them */
			results: [],
			/** whether a search has been answered for the term in the box */
			searched: false,
			/** only the newest search may answer, whether it succeeds or fails */
			searches: latestLoad(),
		}
	},

	computed: {
		/** @return {import('../types/Nextcloud.js').DialogButton[]} */
		deleteButtons() {
			return [
				{
					label: t('social', 'Cancel'),
					callback: () => {
						this.deleting = null
					},
				},
				{
					label: t('social', 'Delete'),
					variant: 'error',
					disabled: this.busy,
					callback: () => this.remove_list(),
				},
			]
		},
	},

	mounted() {
		this.fetchLists()
	},

	beforeUnmount() {
		if (this.searchTimer !== null) {
			window.clearTimeout(this.searchTimer)
		}
	},

	methods: {
		t,

		async fetchLists() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/lists'))
				const lists = Array.isArray(data) ? data : []
				this.lists = [
					...lists.filter((list) => list.nextcloud_group),
					...lists.filter((list) => !list.nextcloud_group),
				]
			} catch (error) {
				logger.error('Failed to load the lists', { error })
				showError(t('social', 'Could not load your lists'))
			} finally {
				this.loading = false
			}
		},

		/** Tells the sidebar, which draws the lists from a fetch of its own. */
		announce() {
			eventBus.emit(LISTS_CHANGED)
		},

		async create() {
			const title = this.newTitle.trim()
			if (title === '' || this.creating) {
				return
			}
			this.creating = true
			try {
				const { data } = await axios.post(generateUrl('apps/social/api/v1/lists'), { title })
				this.lists = [...this.lists, data]
				this.newTitle = ''
				showSuccess(t('social', 'The list {list} has been created', { list: data.title }))
				this.announce()
			} catch (error) {
				logger.error('Failed to create the list', { error })
				showError(error?.response?.data?.error || t('social', 'Could not create the list'))
			} finally {
				this.creating = false
			}
		},

		/** @param {object} list the list to rename */
		startRename(list) {
			this.renaming = list.id
			this.renameTitle = list.title
		},

		/** @param {object} list the list being renamed */
		async rename(list) {
			const title = this.renameTitle.trim()
			if (title === '' || this.busy) {
				return
			}
			if (title === list.title) {
				this.renaming = null
				return
			}
			this.busy = true
			try {
				// the route requires the title on every update, and the policy
				// travels with it so nothing is reset by leaving it out
				const { data } = await axios.put(generateUrl(`apps/social/api/v1/lists/${list.id}`), {
					title,
					replies_policy: list.replies_policy,
				})
				this.lists = this.lists.map((entry) => (entry.id === list.id ? { ...entry, ...data } : entry))
				this.renaming = null
				this.announce()
			} catch (error) {
				logger.error('Failed to rename the list', { error })
				showError(error?.response?.data?.error || t('social', 'Could not rename the list'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} list the list to make public or private
		 * @param {boolean} isPublic whether anyone may see it and who is on it
		 */
		async setPublic(list, isPublic) {
			if (this.busy || Boolean(list.public) === isPublic) {
				return
			}
			this.busy = true
			try {
				// the route requires the title on every update
				const { data } = await axios.put(generateUrl(`apps/social/api/v1/lists/${list.id}`), {
					title: list.title,
					replies_policy: list.replies_policy,
					public: isPublic,
				})
				this.lists = this.lists.map((entry) => (entry.id === list.id ? { ...entry, ...data } : entry))
			} catch (error) {
				logger.error('Failed to change who can see the list', { error })
				showError(error?.response?.data?.error || t('social', 'Could not change who can see the list'))
			} finally {
				this.busy = false
			}
		},

		/** Deletes `deleting`, once the dialog has agreed. */
		async remove_list() {
			const list = this.deleting
			if (!list || this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.delete(generateUrl(`apps/social/api/v1/lists/${list.id}`))
				this.lists = this.lists.filter((entry) => entry.id !== list.id)
				if (this.expanded === list.id) {
					this.expanded = null
				}
				this.deleting = null
				showSuccess(t('social', 'The list {list} has been deleted', { list: list.title }))
				this.announce()
			} catch (error) {
				logger.error('Failed to delete the list', { error })
				showError(error?.response?.data?.error || t('social', 'Could not delete the list'))
			} finally {
				this.busy = false
			}
		},

		/** @param {object} list the list whose members to show or hide */
		async toggleMembers(list) {
			if (this.expanded === list.id) {
				this.expanded = null
				return
			}
			this.expanded = list.id
			this.search = ''
			this.results = []
			this.searched = false
			if (this.members[list.id] === undefined) {
				await this.fetchMembers(list)
			}
		},

		/** @param {object} list the list to ask about */
		async fetchMembers(list) {
			try {
				// As many as one request may carry, which is 500.
				//
				// Not `limit=0` — Mastodon's "all of them, no paging" — which
				// is what this asked for and why the panel only ever showed an
				// error. Nextcloud's dispatcher applies a range of 1–500 to
				// any parameter called `limit` and throws before the route is
				// reached, so 0 came back as an HTML error page.
				const { data, headers } = await axios.get(generateUrl(`apps/social/api/v1/lists/${list.id}/accounts`), {
					params: { limit: MEMBERS_PER_REQUEST },
				})
				this.members = { ...this.members, [list.id]: Array.isArray(data) ? data : [] }
				this.membersCursor = { ...this.membersCursor, [list.id]: nextCursor(headers) }
			} catch (error) {
				logger.error('Failed to load the members of the list', { error })
				showError(t('social', 'Could not load who is in the list'))
				this.members = { ...this.members, [list.id]: [] }
				this.membersCursor = { ...this.membersCursor, [list.id]: '' }
			}
		},

		/** @param {object} list the list whose next page of members to add */
		async fetchMoreMembers(list) {
			const cursor = this.membersCursor[list.id]
			if (!cursor || this.loadingMore === list.id) {
				return
			}
			this.loadingMore = list.id
			try {
				const { data, headers } = await axios.get(generateUrl(`apps/social/api/v1/lists/${list.id}/accounts`), {
					params: { limit: MEMBERS_PER_REQUEST, max_id: cursor },
				})
				// the first page was fetched again meanwhile, after an add,
				// and this one no longer follows on from what is shown
				if (this.membersCursor[list.id] !== cursor) {
					return
				}
				const shown = this.members[list.id] ?? []
				const seen = new Set(shown.map((member) => member.id))
				const more = (Array.isArray(data) ? data : []).filter((member) => !seen.has(member.id))
				this.members = { ...this.members, [list.id]: [...shown, ...more] }
				this.membersCursor = { ...this.membersCursor, [list.id]: nextCursor(headers) }
			} catch (error) {
				logger.error('Failed to load more of the members of the list', { error })
				showError(t('social', 'Could not load who is in the list'))
			} finally {
				this.loadingMore = null
			}
		},

		/**
		 * @param {object} list the list
		 * @param {import('../types/Mastodon.js').Account} account who to take out
		 */
		async remove(list, account) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				// as query parameters: the ids of a DELETE ride on the address
				await axios.delete(generateUrl(`apps/social/api/v1/lists/${list.id}/accounts`), {
					params: { account_ids: [account.id] },
				})
				this.members = {
					...this.members,
					[list.id]: (this.members[list.id] ?? []).filter((member) => member.id !== account.id),
				}
			} catch (error) {
				logger.error('Failed to remove the account from the list', { error })
				showError(error?.response?.data?.error || t('social', 'Could not remove {account} from the list', { account: account.acct }))
			} finally {
				this.busy = false
			}
		},

		/**
		 * @param {object} list the list
		 * @param {object} hit a search result: an actor, whose `id` is its URL
		 */
		async add(list, hit) {
			if (this.busy) {
				return
			}
			this.busy = true
			try {
				await axios.post(generateUrl(`apps/social/api/v1/lists/${list.id}/accounts`), {
					account_ids: [hit.id],
				})
				this.search = ''
				this.results = []
				this.searched = false
				await this.fetchMembers(list)
			} catch (error) {
				logger.error('Failed to add the account to the list', { error })
				// the one refusal a reader can do something about is said in
				// their terms; the server's wording is Mastodon's "Record not found"
				showError(error?.response?.status === 404
					? t('social', 'You can only add people you follow to a list')
					: (error?.response?.data?.error || t('social', 'Could not add {account} to the list', { account: hit.account })))
			} finally {
				this.busy = false
			}
		},

		/** @param {string} term what is in the box now */
		onSearch(term) {
			if (this.searchTimer !== null) {
				window.clearTimeout(this.searchTimer)
			}
			this.searched = false
			if ((term ?? '').trim() === '') {
				this.results = []
				return
			}
			this.searchTimer = window.setTimeout(() => this.runSearch(term.trim()), SEARCH_DELAY_MS)
		},

		/** @param {string} term the trimmed search */
		async runSearch(term) {
			const isNewest = this.searches.begin()
			// the box may have moved on while this was in flight, or a newer
			// search for the same words may have been asked since
			const answers = () => isNewest() && this.search.trim() === term
			try {
				const { data } = await axios.get(generateUrl('apps/social/api/v1/global/accounts/search'), {
					params: { search: term },
				})
				if (!answers()) {
					return
				}
				this.results = (data?.result?.accounts ?? []).slice(0, 8)
				this.searched = true
			} catch (error) {
				logger.error('The account search failed', { error })
				if (!answers()) {
					return
				}
				this.results = []
				this.searched = true
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

.lists-settings {
	&__create {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		margin-bottom: 16px;
		flex-wrap: wrap;
	}

	&__create-title {
		flex: 1 1 240px;
		max-width: 420px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 4px 0;
	}

	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		border-top: 1px solid var(--color-border);
		padding: 6px 0;
	}

	&__row {
		display: flex;
		align-items: center;
		gap: 8px;
		min-height: 44px;
		flex-wrap: wrap;
	}

	&__icon {
		flex-shrink: 0;
		color: var(--color-text-maxcontrast);
	}

	&__title {
		flex: 1 1 auto;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-weight: bold;
	}

	&__badge {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__rename {
		display: flex;
		align-items: flex-end;
		gap: 8px;
		flex: 1 1 auto;
		flex-wrap: wrap;
	}

	&__row-actions {
		display: flex;
		align-items: center;
		gap: 4px;
		margin-inline-start: auto;
	}

	&__members {
		padding: 4px 0 8px 28px;
	}

	&__visibility {
		margin-bottom: 8px;
	}

	&__member-list {
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__member {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 4px 0;
	}

	&__member-name {
		display: flex;
		flex-direction: column;
		min-width: 0;
		flex: 1 1 auto;
	}

	&__member-acct {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	&__more {
		margin-top: 4px;
	}

	&__add {
		margin-top: 8px;
		max-width: 420px;
	}

	&__results {
		list-style: none;
		margin: 4px 0 0;
		padding: 4px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background: var(--color-main-background);
	}

	&__result {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		width: 100%;
		padding: 6px 8px;
		border: 0;
		border-radius: var(--border-radius);
		background: transparent;
		text-align: start;
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
		}
	}
}

@include layout.below(layout.$phone) {
	.lists-settings__members {
		padding-inline-start: 0;
	}
}
</style>
