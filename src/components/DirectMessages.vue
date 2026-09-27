<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section
		class="direct-messages"
		:class="{
			'direct-messages--selected': selectedConversationId !== '' || newMessageOpen,
			'direct-messages--composing': newMessageOpen,
		}">
		<aside class="direct-messages__list-panel" :aria-label="t('social', 'Direct message conversations')">
			<header class="direct-messages__list-heading">
				<h2>{{ t('social', 'Messages') }}</h2>
				<NcButton
					variant="tertiary"
					class="direct-messages__new-button"
					:aria-label="t('social', 'New message')"
					@click="newMessageOpen = true">
					<template #icon>
						<MessagePlusOutline :size="20" />
					</template>
				</NcButton>
			</header>
			<div class="direct-messages__list-tools">
				<NcTextField
					v-model="searchQuery"
					class="direct-messages__search"
					:label="t('social', 'Search conversations')"
					:placeholder="t('social', 'Search conversations')"
					type="search" />
				<nav class="direct-messages__filters" :aria-label="t('social', 'Filter conversations')">
					<button
						type="button"
						:aria-pressed="filterMode === 'all'"
						:class="{ 'direct-messages__filter--active': filterMode === 'all' }"
						@click="filterMode = 'all'">
						{{ t('social', 'All') }}
					</button>
					<button
						type="button"
						:aria-pressed="filterMode === 'unread'"
						:class="{ 'direct-messages__filter--active': filterMode === 'unread' }"
						@click="filterMode = 'unread'">
						{{ t('social', 'Unread ({count})', { count: unreadCount }) }}
					</button>
				</nav>
			</div>

			<!--
				Above the chain rather than inside it. A removal that failed is
				something that happened to one conversation, not a state the
				inbox is in — and a `v-if` here broke the chain in two, so the
				empty state rendered under "Loading…", the error and the empty
				state rendered together, and a failed removal took the whole
				list off the screen.
			-->
			<p v-if="removeError" class="direct-messages__state" role="alert">
				{{ t('social', 'Could not remove conversation') }}
			</p>

			<p v-if="loadingList" class="direct-messages__state" role="status">
				{{ t('social', 'Loading conversations…') }}
			</p>
			<div v-else-if="listError" class="direct-messages__retry-state" role="alert">
				<p>{{ t('social', 'Could not load conversations') }}</p>
				<NcButton variant="tertiary" @click="loadConversations()">
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>
			<div v-else-if="conversations.length === 0" class="direct-messages__inbox-empty">
				<MessageOutline :size="24" aria-hidden="true" />
				<strong>{{ t('social', 'No conversations yet') }}</strong>
				<NcButton class="direct-messages__mobile-start" variant="tertiary" @click="newMessageOpen = true">
					{{ t('social', 'Start a conversation') }}
				</NcButton>
			</div>
			<div v-else-if="filteredConversations.length === 0" class="direct-messages__state direct-messages__no-matches">
				<p>{{ t('social', 'No conversations match your search') }}</p>
				<NcButton
					v-if="cursor"
					variant="tertiary"
					:disabled="loadingMore"
					@click="loadConversations(true)">
					{{ loadingMore ? t('social', 'Loading…') : t('social', 'Load older conversations') }}
				</NcButton>
			</div>

			<ul v-else class="direct-messages__list">
				<li v-for="conversation in filteredConversations" :key="conversation.id">
					<NcListItem
						class="direct-messages__conversation"
						:forceDisplayActions="true"
						:name="conversationName(conversation)"
						:details="formatTime(conversation.last_status?.created_at)"
						:active="String(conversation.id) === selectedConversationId"
						:bold="conversation.unread"
						:actionsAriaLabel="t('social', 'Conversation actions')"
						:linkAriaLabel="t('social', 'Conversation with {name}', { name: conversationName(conversation) })"
						@click="selectConversation(String(conversation.id), $event)">
						<template #icon>
							<ActorAvatar
								v-if="conversationPeer(conversation)"
								:actor="conversationPeer(conversation)"
								:size="40"
								:link="false"
								class="direct-messages__conversation-avatar" />
						</template>
						<template #subname>
							<span class="direct-messages__preview">{{ preview(conversation.last_status, conversation) || t('social', 'No messages yet') }}</span>
						</template>
						<template #indicator>
							<span v-if="conversation.unread" class="direct-messages__unread-dot" :aria-label="t('social', 'Unread')" />
						</template>
						<template #actions>
							<NcActionButton
								:closeAfterClick="true"
								:disabled="removingConversationId === String(conversation.id)"
								@click.stop="removeConversation(conversation)">
								<template #icon>
									<DeleteOutline :size="20" />
								</template>
								{{ t('social', 'Remove conversation') }}
							</NcActionButton>
						</template>
					</NcListItem>
				</li>
				<li v-if="cursor" class="direct-messages__more">
					<NcButton
						variant="tertiary"
						:disabled="loadingMore"
						@click="loadConversations(true)">
						{{ loadingMore ? t('social', 'Loading…') : t('social', 'Load older conversations') }}
					</NcButton>
				</li>
			</ul>
		</aside>

		<section v-if="newMessageOpen" class="direct-messages__thread-panel direct-messages__new-message-panel" :aria-label="t('social', 'New direct message')">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="newMessageOpen = false">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<ActorAvatar
					v-if="newRecipient"
					:actor="newRecipient"
					:size="40"
					:link="false" />
				<div class="direct-messages__thread-person">
					<h2>{{ newRecipient ? newRecipient.display_name || newRecipient.username || newRecipient.acct : t('social', 'New message') }}</h2>
					<p>{{ newRecipient ? t('social', 'Private conversation') : t('social', 'Choose a person to start a private chat') }}</p>
				</div>
				<NcButton
					v-if="newRecipient"
					variant="tertiary"
					class="direct-messages__change-person"
					@click="newRecipient = null">
					{{ t('social', 'Change person') }}
				</NcButton>
			</header>
			<div v-if="!newRecipient" class="direct-messages__recipient-picker">
				<div class="direct-messages__recipient-intro">
					<h3>{{ t('social', 'Who would you like to message?') }}</h3>
				</div>
				<NcTextField
					v-model="recipientQuery"
					class="direct-messages__recipient-search"
					:label="t('social', 'Search for a person by name or @username')"
					:placeholder="t('social', 'Search for a person by name or @username')"
					autocomplete="off"
					type="search" />
				<p v-if="searchError" class="direct-messages__state direct-messages__recipient-feedback" role="alert">
					{{ t('social', 'Could not search for people. Please try again.') }}
				</p>
				<template v-for="group in recipientGroups" :key="group.key">
					<div class="direct-messages__people-heading">
						<h3 :id="`direct-messages-recipients-${group.key}`">
							{{ group.title }}
						</h3>
					</div>
					<ul
						class="direct-messages__recipient-results"
						:class="`direct-messages__recipient-results--${group.key}`"
						:aria-labelledby="`direct-messages-recipients-${group.key}`">
						<li v-for="account in group.accounts" :key="account.id || account.acct">
							<NcListItem
								class="direct-messages__recipient-option"
								:name="account.display_name || account.username || account.acct"
								:linkAriaLabel="t('social', 'Start a conversation with {name}', { name: account.display_name || account.acct })"
								@click="startConversation(account, $event)">
								<template #icon>
									<ActorAvatar :actor="account" :size="40" :link="false" />
								</template>
								<template #subname>
									@{{ account.acct }}
								</template>
							</NcListItem>
						</li>
					</ul>
				</template>
				<p v-if="searchingAccounts || loadingSuggestions" class="direct-messages__state direct-messages__recipient-feedback" role="status">
					{{ t('social', 'Searching…') }}
				</p>
				<p v-else-if="hasRecipientQuery && !recipientGroups.length && !searchError" class="direct-messages__state direct-messages__recipient-feedback">
					{{ t('social', 'No people found') }}
				</p>
				<p v-else-if="!hasRecipientQuery && !recipientGroups.length" class="direct-messages__state direct-messages__recipient-feedback">
					{{ t('social', 'Search for someone to start a conversation') }}
				</p>
			</div>
			<div v-else class="direct-messages__new-chat">
				<div class="direct-messages__new-chat-intro">
					<MessageOutline :size="36" aria-hidden="true" />
					<h3>{{ t('social', 'Start a conversation with {name}', { name: newRecipient.display_name || newRecipient.username || newRecipient.acct }) }}</h3>
					<p>{{ t('social', 'Private conversation') }}</p>
				</div>
				<form class="direct-messages__message-form" @submit.prevent="sendMessage">
					<NcTextArea
						v-model="messageText"
						class="direct-messages__message-input"
						labelOutside
						:aria-label="t('social', 'Write a message…')"
						:disabled="sendingMessage"
						:placeholder="t('social', 'Write a message…')"
						resize="vertical" />
					<NcButton variant="primary" type="submit" :disabled="sendingMessage || !messageText.trim()">
						{{ t('social', 'Send') }}
					</NcButton>
				</form>
				<p v-if="sendError" class="direct-messages__send-error" role="alert">
					{{ t('social', 'Could not send the message. Please try again.') }}
				</p>
			</div>
		</section>

		<section v-else-if="activeConversation" class="direct-messages__thread-panel" :aria-label="threadLabel">
			<header class="direct-messages__thread-heading">
				<NcButton class="direct-messages__back" variant="tertiary" @click="$emit('select', '')">
					{{ t('social', 'Back to conversations') }}
				</NcButton>
				<ActorAvatar
					v-if="conversationPeer(activeConversation)"
					:actor="conversationPeer(activeConversation)"
					:size="40"
					:link="false" />
				<div class="direct-messages__thread-person">
					<h2>{{ conversationName(activeConversation) }}</h2>
					<p>{{ t('social', 'Private conversation') }}</p>
				</div>
			</header>

			<p v-if="loadingThread" class="direct-messages__state" role="status">
				{{ t('social', 'Loading messages…') }}
			</p>
			<div v-else-if="threadError" class="direct-messages__retry-state" role="alert">
				<p>{{ t('social', 'Could not load this conversation') }}</p>
				<NcButton variant="tertiary" :disabled="loadingThread" @click="loadThread(selectedConversationId)">
					{{ t('social', 'Try again') }}
				</NcButton>
			</div>
			<div
				v-else
				ref="threadContainer"
				class="direct-messages__thread"
				aria-live="polite">
				<div
					v-for="(message, index) in messages"
					:key="message.id"
					class="direct-messages__message-row"
					:class="{
						'direct-messages__message--outgoing': isOutgoing(message),
						'direct-messages__message--grouped': !showMessageAuthor(message, index),
					}">
					<time v-if="showDaySeparator(message, index)" class="direct-messages__day" :datetime="message.created_at">
						{{ formatDay(message.created_at) }}
					</time>
					<div class="direct-messages__message">
						<TimelineEntry
							:item="messageForDisplay(message)"
							type="direct"
							element="article"
							:hideAvatar="true"
							:hideAuthor="true" />
						<time class="direct-messages__message-time" :datetime="message.created_at" :title="formatDay(message.created_at)">
							{{ formatMessageTime(message.created_at) }}
						</time>
					</div>
				</div>
				<p v-if="messages.length === 0" class="direct-messages__state">
					{{ t('social', 'No messages in this conversation') }}
				</p>
			</div>

			<form class="direct-messages__message-form" @submit.prevent="sendMessage">
				<NcTextArea
					v-model="messageText"
					class="direct-messages__message-input"
					labelOutside
					:aria-label="t('social', 'Write a message…')"
					:disabled="sendingMessage"
					:placeholder="t('social', 'Write a message…')"
					resize="vertical" />
				<NcButton variant="primary" type="submit" :disabled="sendingMessage || !messageText.trim()">
					{{ t('social', 'Send') }}
				</NcButton>
			</form>
			<p v-if="sendError" class="direct-messages__send-error" role="alert">
				{{ t('social', 'Could not send the message. Please try again.') }}
			</p>
		</section>

		<section v-else class="direct-messages__thread-panel direct-messages__thread-panel--empty">
			<div class="direct-messages__welcome">
				<div class="direct-messages__welcome-mark" aria-hidden="true">
					<MessageOutline :size="32" />
				</div>
				<h2>{{ t('social', 'Start a private chat') }}</h2>
				<p>{{ t('social', 'Choose a conversation or find someone to message.') }}</p>
				<NcButton variant="primary" @click="newMessageOpen = true">
					{{ t('social', 'New message') }}
				</NcButton>
			</div>
		</section>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import axios from '@nextcloud/axios'
import ActorAvatar from './ActorAvatar.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessagePlusOutline from 'vue-material-design-icons/MessagePlusOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import TimelineEntry from './TimelineEntry.vue'
import { nextCursor } from '../utils/linkHeader.js'
import { htmlToPlainText } from '../utils/plainText.js'
import logger from '../services/logger.js'

/** How many conversations one request asks for. */
const PAGE_SIZE = 40

/**
 * A handle at the very start of a message, as the composer writes one.
 *
 * `\w` is ASCII whatever else is set, so `@müller@remote.example` — and every
 * handle on an internationalised domain — was not recognised and the routing
 * mention stayed on screen. What a handle may hold is "not whitespace and not
 * another @", which is the same rule the recipient box uses.
 */
const LEADING_HANDLE = /^(\s*)@([^\s@]+(?:@[^\s@]+)?)(?=\s|$)/u

/**
 * The host a link names, or '' where it names none.
 *
 * @param {string} url the link
 * @return {string} its host, lowercased
 */
function hostOf(url) {
	try {
		return new URL(String(url)).host.toLocaleLowerCase()
	} catch {
		return ''
	}
}

/**
 * Whether what somebody typed addresses an account elsewhere, and so is worth
 * asking the other server about.
 *
 * A pasted profile link was the one that did not work: `resolve` was sent only
 * for a leading '@', and the account search behind it looks at the account
 * column, not at URLs — so pasting `https://remote.example/@bob`, which is how
 * one person sends another a profile, found nobody at all.
 *
 * Handles are here for the sake of saying what the rule is; they already
 * resolve, because the account search fetches an unknown `user@host` whether
 * or not it was asked to.
 *
 * @param {string} query what was typed
 * @return {boolean} whether to ask the other server
 */
function isHandle(query) {
	const typed = query.trim()

	return typed.startsWith('@') || typed.startsWith('http')
		|| /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(typed)
}

export default {
	name: 'DirectMessages',
	components: {
		ActorAvatar,
		MessageOutline,
		MessagePlusOutline,
		DeleteOutline,
		NcActionButton,
		NcButton,
		NcListItem,
		NcTextArea,
		NcTextField,
		TimelineEntry,
	},

	props: {
		selectedConversationId: {
			type: String,
			default: '',
		},
	},

	emits: ['select'],
	data() {
		return {
			conversations: [],
			/** the `max_id` of the next page, '' once the server says there is none */
			cursor: '',
			loadingMore: false,
			searchQuery: '',
			filterMode: 'all',
			currentUserId: window.OC?.getCurrentUser?.()?.uid ?? '',
			loadingList: true,
			listError: false,
			loadingThread: false,
			threadError: false,
			thread: { ancestors: [], descendants: [] },
			newMessageOpen: false,
			recipientQuery: '',
			accountResults: [],
			followedResults: [],
			suggestedAccounts: [],
			loadingSuggestions: false,
			searchingAccounts: false,
			searchError: false,
			newRecipient: null,
			messageText: '',
			sendingMessage: false,
			sendError: false,
			accountSearchTimer: null,
			accountSearchRequest: 0,
			suggestionsRequest: 0,
			threadRequest: 0,
			removingConversationId: '',
			removeError: false,
		}
	},

	computed: {
		filteredConversations() {
			const query = this.searchQuery.trim().toLocaleLowerCase()
			return this.conversations.filter((conversation) => (this.filterMode !== 'unread' || conversation.unread)
				&& (!query || this.conversationName(conversation).toLocaleLowerCase().includes(query)
					|| this.preview(conversation.last_status, conversation).toLocaleLowerCase().includes(query)))
		},

		unreadCount() {
			return this.conversations.filter((conversation) => conversation.unread).length
		},

		activeConversation() {
			return this.conversations.find((conversation) => String(conversation.id) === this.selectedConversationId) ?? null
		},

		hasRecipientQuery() {
			return this.recipientQuery.trim().replace(/^@/, '').length >= 2
		},

		/**
		 * Who the recipient box offers, in two groups: the people the reader
		 * knows — accounts they follow and the people they already talk to —
		 * and after them, under their own heading, every other account the
		 * search found. A stranger from the directory used to be listed among
		 * the reader's own contacts with nothing to tell them apart.
		 *
		 * @return {{key: string, title: string, accounts: object[]}[]} the groups that have anybody in them
		 */
		recipientGroups() {
			const query = this.recipientQuery.trim().replace(/^@/, '').toLocaleLowerCase()
			const searching = this.hasRecipientQuery
			const seen = new Set()
			const keys = (account) => [account?.id, account?.acct]
				.filter(Boolean)
				.map((value) => String(value).toLocaleLowerCase())
			const take = (account) => {
				const accountKeys = keys(account)
				if (!account?.acct || this.isOwnAccount(account) || accountKeys.some((key) => seen.has(key))) {
					return false
				}
				accountKeys.forEach((key) => seen.add(key))
				return true
			}
			// the server already matched what it returns, however it was typed
			// — a pasted profile link is in neither the name nor the handle —
			// so only the accounts known locally are matched here
			const matches = (account) => !query
				|| String(account?.display_name || account?.username || '').toLocaleLowerCase().includes(query)
				|| String(account?.acct ?? '').toLocaleLowerCase().includes(query)

			const known = [
				...(searching ? this.followedResults : []),
				...[
					...this.conversations.flatMap((conversation) => conversation.accounts ?? []),
					...this.suggestedAccounts,
				].filter(matches),
			].filter(take).slice(0, 12)
			const others = (searching ? this.accountResults : []).filter(take).slice(0, 8)

			return [
				{ key: 'known', title: t('social', 'People you know'), accounts: known },
				{ key: 'others', title: t('social', 'Other accounts'), accounts: others },
			].filter((group) => group.accounts.length > 0)
		},

		messages() {
			if (!this.activeConversation?.last_status) {
				return []
			}

			const ordered = [
				...(this.thread.ancestors ?? []),
				this.activeConversation.last_status,
				...(this.thread.descendants ?? []),
			]
			const unique = new Map()
			for (const message of ordered) {
				unique.set(String(message.id), message)
			}
			return [...unique.values()]
		},

		threadLabel() {
			return t('social', 'Conversation with {name}', { name: this.activeConversation ? this.conversationName(this.activeConversation) : '' })
		},
	},

	watch: {
		newMessageOpen(open) {
			if (open) {
				this.newRecipient = null
				this.recipientQuery = ''
				this.searchError = false
				this.loadSuggestedAccounts()
			} else {
				clearTimeout(this.accountSearchTimer)
				this.accountSearchRequest++
				this.suggestionsRequest++
				this.searchingAccounts = false
				this.newRecipient = null
			}
		},

		recipientQuery(query) {
			clearTimeout(this.accountSearchTimer)
			this.accountSearchRequest++
			this.accountResults = []
			this.followedResults = []
			this.searchError = false
			if (!this.hasRecipientQuery) {
				this.searchingAccounts = false
				return
			}
			this.searchingAccounts = true
			this.accountSearchTimer = setTimeout(() => this.searchAccounts(query.trim()), 250)
		},

		selectedConversationId(id) {
			if (id !== '') {
				this.loadThread(id)
			} else {
				this.threadRequest++
				this.thread = { ancestors: [], descendants: [] }
				this.loadingThread = false
				this.threadError = false
			}
		},

		messages() {
			this.$nextTick(() => {
				const thread = this.$refs.threadContainer
				if (thread) {
					thread.scrollTop = thread.scrollHeight
				}
			})
		},
	},

	async mounted() {
		await this.loadConversations()
		if (this.selectedConversationId !== '') {
			await this.loadThread(this.selectedConversationId)
		}
	},

	methods: {
		isOwnAccount(account) {
			return account.acct === this.currentUserId
				|| (account.username === this.currentUserId && !String(account.acct ?? '').includes('@'))
		},

		async loadSuggestedAccounts() {
			const request = ++this.suggestionsRequest
			this.loadingSuggestions = true
			try {
				const { data: me } = await axios.get(generateUrl('apps/social/api/v1/accounts/verify_credentials'))
				if (!me?.id || !this.newMessageOpen || request !== this.suggestionsRequest) {
					return
				}
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/accounts/${encodeURIComponent(String(me.id))}/following`), { params: { limit: 20 } })
				if (this.newMessageOpen && request === this.suggestionsRequest) {
					this.suggestedAccounts = Array.isArray(data) ? data : []
				}
			} catch (error) {
				logger.error('Failed to load suggested direct message recipients', { error })
			} finally {
				if (request === this.suggestionsRequest) {
					this.loadingSuggestions = false
				}
			}
		},

		/**
		 * The inbox, a page at a time.
		 *
		 * It used to ask for forty and stop there, with no way to reach the
		 * forty-first: an account that has been using this for a while simply
		 * could not open its older conversations. The server has been paging
		 * and sending the cursor in its `Link` header all along.
		 *
		 * @param {boolean} more whether this is the reader asking for the page
		 *                       after the one they have
		 */
		async loadConversations(more = false) {
			if (more && (this.loadingMore || this.cursor === '')) {
				return
			}

			if (more) {
				this.loadingMore = true
			} else {
				this.loadingList = true
				this.listError = false
			}

			const params = { limit: PAGE_SIZE }
			if (more) {
				params.max_id = this.cursor
			}

			try {
				const { data, headers } = await axios.get(
					generateUrl('apps/social/api/v1/conversations'),
					{ params },
				)
				const page = Array.isArray(data) ? data : []
				this.cursor = nextCursor(headers)
				this.conversations = this.uniqueConversations(more ? [...this.conversations, ...page] : page)
			} catch (error) {
				if (!more) {
					this.listError = true
				}
				logger.error('Failed to load direct message conversations', { error, more })
			} finally {
				this.loadingList = false
				this.loadingMore = false
			}
		},

		/**
		 * The same conversation twice is one row; two conversations with the
		 * same person are two.
		 *
		 * The key used to be the peer, so a second thread with somebody was
		 * dropped on the floor — unreachable from the inbox, however many
		 * messages were in it. The server defines a conversation by its thread
		 * root and hands that root's id back as the conversation's, which is
		 * the thing that is actually unique.
		 *
		 * @param {object[]} conversations what the server sent
		 * @return {object[]} the same, without any true duplicate
		 */
		uniqueConversations(conversations) {
			const unique = new Map()
			for (const [index, conversation] of conversations.entries()) {
				// one without an id cannot be opened, but hiding it is worse
				const key = conversation.id === undefined || conversation.id === null
					? `index:${index}`
					: String(conversation.id)
				if (!unique.has(key)) {
					unique.set(key, conversation)
				}
			}

			return [...unique.values()]
		},

		/**
		 * One search, asked twice at once: narrowed to the accounts the reader
		 * follows, and not narrowed. The narrowed one is not a filter over the
		 * other — the server searches the follows themselves — so somebody the
		 * reader follows is found even when a page of strangers matches first.
		 *
		 * @param {string} query what was typed
		 */
		async searchAccounts(query) {
			const request = ++this.accountSearchRequest
			const url = generateUrl('apps/social/api/v1/accounts/search')
			const resolve = isHandle(query)
			try {
				const [followed, all] = await Promise.all([
					axios.get(url, { params: { q: query, limit: 8, resolve, following: true } }),
					axios.get(url, { params: { q: query, limit: 8, resolve } }),
				])
				if (request !== this.accountSearchRequest) {
					return
				}
				this.followedResults = Array.isArray(followed.data) ? followed.data : []
				this.accountResults = Array.isArray(all.data) ? all.data : []
			} catch (error) {
				if (request === this.accountSearchRequest) {
					this.searchError = true
					logger.error('Failed to search for direct message recipients', { error })
				}
			} finally {
				if (request === this.accountSearchRequest) {
					this.searchingAccounts = false
				}
			}
		},

		startConversation(account, event) {
			event?.preventDefault?.()
			// a handle is not case-sensitive, and the filtering beside this
			// lowercases one — so `Bob@remote.example` opened a second chat
			// beside the one with `bob@remote.example`
			const wanted = String(account.acct ?? '').toLocaleLowerCase()
			const existing = this.conversations.find((conversation) => (conversation.accounts ?? []).some((candidate) => (candidate.id && account.id && String(candidate.id) === String(account.id))
				|| (wanted !== '' && String(candidate.acct ?? '').toLocaleLowerCase() === wanted)))
			if (existing) {
				this.selectConversation(String(existing.id))
				return
			}
			this.newRecipient = account
			this.messageText = ''
			this.sendError = false
			this.accountResults = []
			this.followedResults = []
			this.recipientQuery = ''
		},

		async sendMessage() {
			const newRecipient = this.newMessageOpen ? this.newRecipient : null
			const recipient = newRecipient || this.activeConversation?.accounts?.find((account) => account.acct !== this.currentUserId && account.username !== this.currentUserId)
			const text = this.messageText.trim()
			if (!recipient?.acct || !text || this.sendingMessage) {
				return
			}
			this.sendingMessage = true
			this.sendError = false
			try {
				// A new-message composer can be opened while another thread remains
				// selected in the route. That thread is not the parent of this post.
				const replyTo = newRecipient ? null : this.activeConversation?.last_status?.id
				await axios.post(generateUrl('apps/social/api/v1/statuses'), {
					status: `${this.routingMentions(recipient)} ${text}`,
					visibility: 'direct',
					...(replyTo ? { in_reply_to_id: replyTo } : {}),
				})
				this.messageText = ''
				await this.loadConversations()
				if (newRecipient) {
					const conversation = this.conversations.find((item) => (item.accounts ?? []).some((candidate) => candidate.acct === recipient.acct || (candidate.id && recipient.id && String(candidate.id) === String(recipient.id))))
					this.newRecipient = null
					this.newMessageOpen = false
					if (conversation) {
						this.$emit('select', String(conversation.id))
					}
				} else if (this.selectedConversationId) {
					await this.loadThread(this.selectedConversationId)
				}
			} catch (error) {
				this.sendError = true
				logger.error('Failed to send a direct message', { error })
			} finally {
				this.sendingMessage = false
			}
		},

		async loadThread(id) {
			const conversation = this.conversations.find((item) => String(item.id) === id)
			const request = ++this.threadRequest
			if (!conversation?.last_status?.id) {
				this.thread = { ancestors: [], descendants: [] }
				this.loadingThread = false
				this.threadError = false
				return
			}

			this.loadingThread = true
			this.threadError = false
			try {
				const statusId = encodeURIComponent(String(conversation.last_status.id))
				const { data } = await axios.get(generateUrl(`apps/social/api/v1/statuses/${statusId}/context`))
				if (request !== this.threadRequest || id !== this.selectedConversationId) {
					return
				}
				this.thread = {
					ancestors: Array.isArray(data?.ancestors) ? data.ancestors : [],
					descendants: Array.isArray(data?.descendants) ? data.descendants : [],
				}
				if (conversation.unread) {
					try {
						await axios.post(generateUrl(`apps/social/api/v1/conversations/${encodeURIComponent(id)}/read`))
						conversation.unread = false
					} catch (error) {
						logger.error('Failed to mark direct message conversation as read', { error, conversationId: id })
					}
				}
			} catch (error) {
				if (request === this.threadRequest) {
					this.threadError = true
					logger.error('Failed to load direct message conversation', { error, conversationId: id })
				}
			} finally {
				if (request === this.threadRequest) {
					this.loadingThread = false
				}
			}
		},

		conversationName(conversation) {
			const peer = this.conversationPeer(conversation)
			return peer?.display_name || peer?.acct || peer?.username || t('social', 'Unknown account')
		},

		conversationPeer(conversation) {
			return (conversation?.accounts ?? []).find((account) => account.acct !== this.currentUserId && account.username !== this.currentUserId) ?? conversation?.accounts?.[0] ?? null
		},

		selectConversation(id, event) {
			event?.preventDefault?.()
			this.newMessageOpen = false
			this.messageText = ''
			this.sendError = false
			this.$emit('select', id)
		},

		preview(status, conversation = null) {
			// the open conversation when the caller named none: a mention is
			// only hidden where there is a peer to compare it against, and a
			// preview asked for without one would otherwise keep the routing
			// handle it is there to leave out
			const peer = this.conversationPeer(conversation ?? this.activeConversation)

			return htmlToPlainText(this.withoutProtocolRecipient(status, peer)).replace(/\s+/g, ' ').trim()
		},

		/**
		 * Direct posts carry a leading account mention for ActivityPub delivery.
		 * It is routing metadata in this view; the chat header already identifies
		 * the peer, so repeating that mention in every bubble is noise.
		 *
		 * @param {object} message Direct message status.
		 * @return {object} the original message unless a leading protocol mention was removed
		 */
		messageForDisplay(message) {
			const content = this.withoutProtocolRecipient(message, this.conversationPeer(this.activeConversation))
			return content === message.content ? message : { ...message, content }
		},

		/**
		 * Remove only the first ActivityPub h-card at the start of a direct
		 * message. Other mentions in the message body keep their meaning.
		 *
		 * @param {object} message Direct message status.
		 * @param {object|null} recipient Conversation partner whose routing mention is hidden.
		 * @return {string}
		 */
		withoutProtocolRecipient(message, recipient = null) {
			const content = message?.content ?? ''
			if (message?.visibility !== 'direct' || !content || typeof document === 'undefined') {
				return content
			}

			const wrapper = document.createElement('div')
			wrapper.innerHTML = content
			const paragraph = wrapper.firstElementChild?.tagName === 'P' ? wrapper.firstElementChild : wrapper
			let first = paragraph.firstChild
			while (first?.nodeType === Node.TEXT_NODE && !first.textContent.trim()) {
				first = first.nextSibling
			}
			if (first?.nodeType === Node.TEXT_NODE) {
				const leadingMention = first.textContent.match(LEADING_HANDLE)
				if (!leadingMention || !this.isProtocolRecipient(leadingMention[2], recipient)) {
					return content
				}
				first.textContent = first.textContent.slice(leadingMention[0].length).replace(/^\s+/, '')
				if (!first.textContent.trim()) {
					first.remove()
				}
				return wrapper.innerHTML
			}
			if (first?.nodeType !== Node.ELEMENT_NODE || !first.matches('.h-card, .mention, a.mention, span.mention')) {
				return content
			}
			const linkedAccount = first.querySelector('a[href]')?.getAttribute('href') ?? first.getAttribute('href') ?? ''
			const visibleMention = first.textContent.replace(/^@/, '').trim()
			// and only when it names the peer. The `.h-card` exemption here
			// stripped the first mention of *anybody*, so a message opening
			// "@carol look at this" lost the name it was about.
			if (!this.isProtocolRecipient(visibleMention, recipient, linkedAccount)) {
				return content
			}

			const next = first.nextSibling
			first.remove()
			if (next?.nodeType === Node.TEXT_NODE) {
				next.textContent = next.textContent.replace(/^\s+/, '')
			}
			if (paragraph !== wrapper && !paragraph.textContent.trim() && !paragraph.children.length) {
				paragraph.remove()
			}

			return wrapper.innerHTML
		},

		/**
		 * Whether a mention is the routing one — the peer's own handle, which
		 * the composer puts in front of every direct message.
		 *
		 * @param {string} mention what the mention says
		 * @param {object|null} recipient the other party, when it is known
		 * @param {string} href where the mention links, when it is a link
		 * @return {boolean} whether it may be hidden
		 */
		isProtocolRecipient(mention, recipient, href = '') {
			// Nothing is hidden when there is nobody to compare against. This
			// said "yes" instead, so in a conversation whose peer had not
			// loaded the first mention of the message disappeared whoever it
			// named.
			if (!recipient) {
				return false
			}

			const handles = [recipient.acct, recipient.username, recipient.preferred_username]
				.filter(Boolean)
				.map((value) => String(value).replace(/^@/, '').toLocaleLowerCase())
			const asked = String(mention).replace(/^@/, '').toLocaleLowerCase()
			if (handles.includes(asked)) {
				return true
			}

			// A mention shortened to its local part — which is how a renderer
			// writes one — counts only where the link beside it points at the
			// peer's own server. `includes()` was matching `bob` anywhere in
			// the href, so a message to bobby lost its mention of bob.
			const sameServer = hostOf(href) !== ''
				&& hostOf(href) === hostOf(recipient.url ?? recipient.id ?? '')
			const locals = handles.map((handle) => handle.split('@')[0]).filter(Boolean)
			if (sameServer && locals.includes(asked)) {
				return true
			}

			const segment = (String(href).toLocaleLowerCase().split(/[/?#]/).filter(Boolean).at(-1) ?? '')
				.replace(/^@/, '')

			return segment !== ''
				&& (handles.includes(segment) || (sameServer && locals.includes(segment)))
		},

		/**
		 * The mentions a reply has to carry to reach everybody it is a reply to.
		 *
		 * A conversation of three people was answered with one mention — the
		 * first account that was not the reader — so the third person dropped
		 * out of the exchange at the first reply, without anybody being told.
		 *
		 * @param {object} recipient the account the composer was opened for
		 * @return {string} the mentions, in the order the conversation lists them
		 */
		routingMentions(recipient) {
			// While composing a new chat, the route may still point at the thread
			// that was open before the recipient picker appeared. Only address the
			// person explicitly chosen for this new message.
			if (this.newMessageOpen && this.newRecipient) {
				return `@${recipient.acct}`
			}

			const accounts = this.activeConversation?.accounts ?? []
			const handles = accounts
				.map((account) => String(account.acct ?? ''))
				.filter((acct) => acct !== '' && acct !== this.currentUserId)

			if (handles.length === 0) {
				return `@${recipient.acct}`
			}

			const seen = new Set()
			const unique = handles.filter((acct) => {
				const key = acct.toLocaleLowerCase()
				if (seen.has(key)) {
					return false
				}
				seen.add(key)

				return true
			})

			return unique.map((acct) => `@${acct}`).join(' ')
		},

		async removeConversation(conversation) {
			const id = String(conversation.id)
			this.removingConversationId = id
			this.removeError = false
			try {
				await axios.delete(generateUrl(`/apps/social/api/v1/conversations/${encodeURIComponent(id)}`))
				this.conversations = this.conversations.filter((item) => String(item.id) !== id)
				if (this.selectedConversationId === id) {
					this.$emit('select', '')
				}
			} catch (error) {
				logger.error('Could not remove direct message conversation', { error, id })
				this.removeError = true
			} finally {
				this.removingConversationId = ''
			}
		},

		formatTime(value) {
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			const now = new Date()
			return date.toDateString() === now.toDateString()
				? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
				: date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
		},

		formatDay(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString(undefined, { dateStyle: 'medium' })
		},

		formatMessageTime(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
		},

		showDaySeparator(message, index) {
			if (index === 0) {
				return true
			}
			const current = new Date(message.created_at)
			const previous = new Date(this.messages[index - 1]?.created_at)
			return current.toDateString() !== previous.toDateString()
		},

		isOutgoing(message) {
			const account = message?.account
			if (!this.currentUserId || !account) {
				return false
			}
			return account.acct === this.currentUserId
				|| (account.username === this.currentUserId && !String(account.acct ?? '').includes('@'))
		},

		showMessageAuthor(message, index) {
			if (index === 0) {
				return true
			}
			const current = message?.account?.id || message?.account?.acct
			const previous = this.messages[index - 1]?.account?.id || this.messages[index - 1]?.account?.acct
			return current !== previous
		},

	},
}
</script>

<style scoped lang="scss">
@use '../styles/layout.scss' as layout;

/*
 * No `min-height`: the panes scroll inside the height below, and a floor under
 * it only ever made the box taller than the viewport, which pushed the message
 * field off a short screen and scrolled the whole page instead. Below
 * `$folded` the page starts under the navigation toggle (Timeline.vue), and
 * that distance comes off the height too, or the Send button is below the
 * bottom edge.
 */
.direct-messages {
	display: grid;
	grid-template-columns: clamp(17.5rem, 23vw, 20rem) minmax(0, 1fr);
	width: 100%;
	height: calc(100dvh - var(--header-height, 50px) - 0.6rem - var(--social-toggle-clearance, 0px));
	background: var(--color-main-background);
}

.direct-messages__list-panel,
.direct-messages__thread-panel {
	display: flex;
	min-width: 0;
	min-height: 0;
	flex-direction: column;
}

.direct-messages__list-panel {
	border-inline-end: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.direct-messages__list-heading,
.direct-messages__thread-heading {
	display: flex;
	min-height: 4.75rem;
	align-items: center;
	gap: 0.8rem;
	padding: 0.75rem 1.25rem;
	border-bottom: 1px solid var(--color-border);
}

.direct-messages__list-heading {
	justify-content: space-between;
}

/*
 * Room for Nextcloud's app-navigation toggle. Where the navigation is pinned
 * the toggle sits over the top-left corner of the content — that corner is
 * this heading, open or closed — and without the room it covers the first
 * letters of "Messages". Below `$folded` the page already starts beneath the
 * toggle (Timeline.vue), so the room there would be empty.
 */
@media (min-width: layout.$folded + 1px) {
	.direct-messages__list-heading {
		padding-inline-start: 3.5rem;
	}
}

.direct-messages__list-heading h2,
.direct-messages__thread-heading h2 {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 650;
}

.direct-messages__new-button {
	flex: 0 0 auto;
}

.direct-messages__list-tools {
	padding: 0.8rem 1rem 0;
}

.direct-messages__search {
	display: block;
}

.direct-messages__search :deep(.input-field__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 2.3rem;
	padding-block: 0.4rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.direct-messages__search :deep(input) {
	min-height: 2.3rem;
	padding-block: 0.4rem;
}

.direct-messages__filters {
	display: flex;
	width: fit-content;
	gap: 0.2rem;
	margin-block-start: 0.5rem;
	padding: 0.2rem;
	border-radius: 999px;
	background: var(--color-background-hover);
}

.direct-messages__filters button {
	min-height: 1.9rem;
	padding: 0.25rem 0.7rem;
	border: 0;
	border-radius: 999px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font: inherit;
	font-size: 0.82rem;
	font-weight: 600;
	line-height: 1.2;
	cursor: pointer;
}

.direct-messages__filters button:hover,
.direct-messages__filters .direct-messages__filter--active {
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 1px 3px var(--color-box-shadow);
}

.direct-messages__filters button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.direct-messages__list {
	flex: 1;
	min-height: 0;
	margin: 0;
	padding: 0.35rem 0;
	list-style: none;
	overflow-y: auto;
}

.direct-messages :deep(.direct-messages__conversation.list-item__wrapper) {
	padding: 0;
}

.direct-messages :deep(.direct-messages__conversation .list-item__anchor) {
	min-height: 4.75rem;
	padding: 0.6rem 1rem;
	border-radius: 0;
}

.direct-messages__more {
	display: flex;
	justify-content: center;
	padding: 0.5rem 1rem 1rem;
}

.direct-messages__preview {
	max-width: 100%;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 0.86rem;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.direct-messages__unread-dot {
	width: 0.5rem;
	height: 0.5rem;
	border-radius: 50%;
	background: var(--color-primary-element);
}

.direct-messages__state {
	margin: auto 0;
	padding: 1.5rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__no-matches {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
}

.direct-messages__no-matches p {
	margin: 0;
}

.direct-messages__retry-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	margin: auto 0;
	padding: 1.5rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__retry-state p {
	margin: 0;
}

.direct-messages__inbox-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.65rem;
	padding: 2.5rem 1rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__inbox-empty strong {
	font-size: 0.9rem;
	font-weight: 500;
}

.direct-messages__mobile-start {
	display: none;
}

.direct-messages__thread-heading {
	flex: 0 0 auto;
}

.direct-messages__thread-person {
	min-width: 0;
}

.direct-messages__thread-person h2 {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.direct-messages__thread-person p {
	margin: 0.1rem 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.82rem;
}

.direct-messages__thread {
	display: flex;
	flex: 1;
	min-height: 0;
	flex-direction: column;
	gap: 0.9rem;
	padding: 1.5rem clamp(1.25rem, 5vw, 4rem);
	overflow-y: auto;
	background: var(--color-main-background);
}

.direct-messages__message-row {
	display: flex;
	width: 100%;
	flex-direction: column;
	align-items: flex-start;
}

.direct-messages__message--grouped {
	margin-block-start: -0.55rem;
}

.direct-messages__message {
	display: flex;
	width: fit-content;
	max-width: min(76%, 42rem);
	flex-direction: column;
	align-items: flex-start;
}

.direct-messages__message--outgoing .direct-messages__message {
	align-self: flex-end;
	align-items: flex-end;
}

.direct-messages__day {
	align-self: center;
	margin: 0.5rem 0 1.4rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
}

.direct-messages__message-time {
	margin: 0.2rem 0.4rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.72rem;
	line-height: 1.25;
}

.direct-messages__thread :deep(.timeline-entry),
.direct-messages__thread :deep(.wrapper),
.direct-messages__thread :deep(.entry__content) {
	width: 100%;
	max-width: 100%;
	margin: 0;
	padding: 0;
	gap: 0;
	border: 0;
	animation: none;
}

.direct-messages__thread :deep(.post-content) {
	width: 100%;
	max-width: 100%;
	padding: 0.7rem 1rem;
	border: 0;
	border-radius: 1rem 1rem 1rem 0.3rem;
	background: var(--color-background-hover);
	box-shadow: none;
	font-size: 0.94rem;
	line-height: 1.45;
	transition: none;
}

.direct-messages__thread :deep(.post-content:hover),
.direct-messages__thread :deep(.post-content:focus-within) {
	border: 0;
	box-shadow: none;
	transform: none;
}

.direct-messages__message--outgoing :deep(.post-content) {
	border-radius: 1rem 1rem 0.3rem 1rem;
	background: var(--color-primary-element-light);
}

.direct-messages__thread :deep(.post-header) {
	display: none;
}

.direct-messages__thread :deep(.post-message) {
	margin: 0;
}

.direct-messages__recipient-picker {
	flex: 1;
	min-height: 0;
	padding: clamp(1.5rem, 4vw, 3rem) clamp(1.25rem, 5vw, 4rem);
	overflow-y: auto;
}

.direct-messages__recipient-intro {
	max-width: 42rem;
	margin: 0 auto 1.5rem;
}

.direct-messages__recipient-intro h3 {
	margin: 0 0 0.25rem;
	color: var(--color-main-text);
	font-size: 1.25rem;
	font-weight: 650;
}

.direct-messages__recipient-search {
	display: block;
	max-width: 42rem;
	margin: 0 auto;
}

.direct-messages__recipient-search :deep(.input-field__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 3rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.direct-messages__people-heading {
	display: flex;
	max-width: 42rem;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	margin: 2rem auto 0.5rem;
	padding-inline: 0.5rem;
	color: var(--color-text-maxcontrast);
}

.direct-messages__people-heading h3 {
	margin: 0;
	color: var(--color-main-text);
	font-size: 0.9rem;
	font-weight: 650;
}

.direct-messages__recipient-results {
	max-width: 42rem;
	margin: 0 auto;
	padding: 0;
	list-style: none;
}

.direct-messages :deep(.direct-messages__recipient-option.list-item__wrapper) {
	padding: 0;
}

.direct-messages :deep(.direct-messages__recipient-option .list-item__anchor) {
	min-height: 4.5rem;
	padding: 0.6rem 0.75rem;
	border-radius: var(--border-radius-large);
}

.direct-messages__recipient-feedback {
	max-width: 42rem;
	margin: 0 auto;
	padding: 1.25rem 0.5rem;
	text-align: start;
}

.direct-messages__new-chat {
	display: flex;
	flex: 1;
	min-height: 0;
	flex-direction: column;
}

.direct-messages__change-person {
	margin-inline-start: auto;
}

.direct-messages__new-chat-intro {
	display: flex;
	flex: 1;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 0.45rem;
	padding: 2rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.direct-messages__new-chat-intro h3 {
	margin: 0.75rem 0 0;
	color: var(--color-main-text);
	font-size: 1.2rem;
}

.direct-messages__new-chat-intro p {
	margin: 0;
}

.direct-messages__message-form {
	display: flex;
	align-items: flex-end;
	gap: 0.75rem;
	padding: 0.85rem clamp(1rem, 3vw, 2rem);
	border-top: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.direct-messages__message-input {
	flex: 1;
	min-width: 0;
}

/*
 * The composer is a chat box, so it is shorter than a form field and the
 * placeholder is the whole of its label. `labelOutside` is what makes that
 * legal: without it `NcTextArea` draws a floating <label> absolutely
 * positioned 11px from the top of the input, and the padding below — which is
 * half what the component reserves — leaves it sitting on top of the text
 * somebody is typing.
 */
.direct-messages__message-form :deep(.textarea__input) {
	box-sizing: border-box;
	width: 100%;
	min-height: 2.3rem;
	max-height: 7rem;
	padding: 0.45rem 0.75rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	resize: vertical;
}

.direct-messages__send-error {
	margin: 0;
	padding: 0 1rem 0.75rem;
	color: var(--color-error);
}

.direct-messages__back {
	display: none;
}

.direct-messages__thread-panel--empty {
	color: var(--color-text-maxcontrast);
}

.direct-messages__welcome {
	max-width: 28rem;
	margin: auto;
	padding: 2rem 1.5rem;
	text-align: center;
}

.direct-messages__welcome-mark {
	margin: 0 auto 1rem;
	color: var(--color-primary-element);
}

.direct-messages__welcome h2 {
	margin: 0 0 0.5rem;
	color: var(--color-main-text);
	font-size: 1.3rem;
}

.direct-messages__welcome p {
	margin: 0 0 1.25rem;
	line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
	.direct-messages__thread :deep(.timeline-entry),
	.direct-messages__thread :deep(.post-content) {
		animation: none;
		transition: none;
		scroll-behavior: auto;
	}
}

/*
 * One pane at a time from here down. Above it the list keeps its 17.5rem and
 * the thread has at least 32rem, which is wider than the thread already is
 * beside a pinned navigation at 1024px.
 */
@include layout.below(layout.$crowded) {
	.direct-messages {
		grid-template-columns: minmax(0, 1fr);
	}

	.direct-messages__list-panel {
		border-inline-end: 0;
	}

	.direct-messages--selected .direct-messages__list-panel,
	.direct-messages:not(.direct-messages--selected) .direct-messages__thread-panel {
		display: none;
	}

	.direct-messages__back {
		display: inline-flex;
	}

	.direct-messages__mobile-start {
		display: inline-flex;
	}

	.direct-messages__thread-heading {
		padding-inline: 0.75rem;
	}

	.direct-messages__message {
		max-width: 88%;
	}
}
</style>
