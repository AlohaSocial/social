<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcDialog
		:open="open"
		:name="t('social', 'Delivery status')"
		:buttons="buttons"
		class="delivery-dialog"
		@update:open="$emit('update:open', $event)">
		<p v-if="loading" class="delivery-hint">
			{{ t('social', 'Asking the delivery queue …') }}
		</p>
		<p v-else-if="error" class="delivery-hint delivery-hint--error">
			{{ error }}
		</p>
		<template v-else-if="delivery">
			<p class="delivery-hint">
				{{ summary }}
			</p>
			<ul v-if="delivery.instances.length || bluesky" class="delivery-list">
				<li
					v-for="entry in delivery.instances"
					:key="entry.host + entry.state + entry.last"
					class="delivery-list__row"
					:class="'delivery-list__row--' + entry.state">
					<span class="delivery-list__dot" aria-hidden="true" />
					<span class="delivery-list__host">{{ entry.host }}</span>
					<span class="delivery-list__state">{{ stateLabel(entry) }}</span>
				</li>
				<!-- not a server among servers: Bluesky is a network the post
				     is written into rather than sent to, so it is the one row
				     that can be opened -->
				<li
					v-if="bluesky"
					class="delivery-list__row delivery-list__row--bluesky"
					:class="'delivery-list__row--' + (bluesky.state === 'published' ? 'delivered' : 'waiting')">
					<span class="delivery-list__dot" aria-hidden="true" />
					<span class="delivery-list__host">{{ t('social', 'Bluesky') }}</span>
					<a
						v-if="bluesky.state === 'published'"
						class="delivery-list__state delivery-list__link"
						:href="bluesky.url"
						target="_blank"
						rel="noopener">{{ t('social', 'Open on Bluesky') }} ↗</a>
					<span v-else class="delivery-list__state">{{ t('social', 'Waiting') }}</span>
				</li>
			</ul>
			<p v-if="!delivery.instances.length" class="delivery-hint delivery-hint--muted">
				{{ t('social', 'No server deliveries are on record. Public posts go to your followers, mentioned accounts, and subscribed relays; Aloha Social does not broadcast them to every known server. Delivery records are kept for {days} days, so an older post may no longer have records.', { days: retentionDays }) }}
			</p>
		</template>
	</NcDialog>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** What the queue keeps a finished delivery for, when it did not say. */
const DEFAULT_RETENTION = 7 * 86400

/**
 * Where a post got to, server by server.
 *
 * The author is the only person this is answered for, and the only person it
 * would mean anything to: everybody else sees a post either arrive or not.
 * Asked fresh every time it opens, because a delivery that was failing a
 * minute ago may have gone through since.
 */
export default {
	name: 'DeliveryDialog',

	components: {
		NcDialog,
	},

	props: {
		/** whether the dialog is showing */
		open: {
			type: Boolean,
			default: false,
		},

		/** the post to ask about, by the id its own routes use */
		statusId: {
			type: [Number, String],
			required: true,
		},
	},

	emits: ['update:open'],

	data() {
		return {
			/** the answer of /statuses/{nid}/delivery, or null before it came */
			delivery: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		buttons() {
			return [
				{
					label: t('social', 'Close'),
					callback: () => this.$emit('update:open', false),
				},
			]
		},

		/**
		 * @return {{state: string, uri: string, url: string}|null} where the
		 * post stands on Bluesky, or null when it was never meant to go there
		 */
		bluesky() {
			const bluesky = this.delivery?.bluesky
			if (!bluesky || bluesky.state === 'not_applicable') {
				return null
			}

			return bluesky
		},

		/** @return {number} how many days the queue keeps a finished delivery */
		retentionDays() {
			return Math.round((this.delivery?.retention ?? DEFAULT_RETENTION) / 86400)
		},

		/**
		 * @return {string} the counts as one sentence — the author reads this
		 * line and, most of the time, needs nothing under it
		 */
		summary() {
			const d = this.delivery
			if (!d || d.total === 0) {
				return ''
			}

			const parts = []
			if (d.delivered) {
				parts.push(n('social', 'delivered to %n server', 'delivered to %n servers', d.delivered))
			}
			if (d.sending) {
				parts.push(n('social', 'being sent to %n server', 'being sent to %n servers', d.sending))
			}
			if (d.waiting) {
				parts.push(n('social', 'waiting for %n server', 'waiting for %n servers', d.waiting))
			}
			if (d.failing) {
				parts.push(n('social', 'failing against %n server', 'failing against %n servers', d.failing))
			}
			if (d.abandoned) {
				parts.push(n('social', 'given up on %n server', 'given up on %n servers', d.abandoned))
			}

			return t('social', 'Of {total}: {parts}.', {
				total: n('social', '%n delivery', '%n deliveries', d.total),
				parts: parts.join(', '),
			})
		},
	},

	watch: {
		open: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		t,
		n,

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/social/api/v1/statuses/{nid}/delivery', { nid: this.statusId })
				const { data } = await axios.get(url)
				this.delivery = data
			} catch {
				this.delivery = null
				this.error = t('social', 'Could not read the delivery status of this post.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {{state: string, tries: number, last: number}} entry one server's row
		 * @return {string} its state, with the detail the state calls for
		 */
		stateLabel(entry) {
			switch (entry.state) {
				case 'delivered':
					return t('social', 'Delivered')
				case 'sending':
					return t('social', 'Sending')
				case 'waiting':
					return t('social', 'Waiting')
				case 'failing':
					return n('social', 'Failing (%n attempt)', 'Failing (%n attempts)', entry.tries)
				case 'abandoned':
					return n('social', 'Given up after %n attempt', 'Given up after %n attempts', entry.tries)
				default:
					return entry.state
			}
		},
	},
}
</script>

<style scoped lang="scss">
.delivery-hint {
	padding: 0 12px 12px;
	color: var(--color-main-text);
	line-height: 1.5;

	&--muted {
		color: var(--color-text-lighter);
	}

	&--error {
		color: var(--color-error-text);
	}
}

/*
 * One row per server, the state carried by a dot as well as the word so the
 * list reads at a glance: green got there, amber is still trying, red was
 * given up on.
 */
.delivery-list {
	margin: 0 12px 12px;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 6px;

	&__row {
		display: flex;
		align-items: center;
		gap: 10px;
		min-height: 28px;
		font-size: 14px;
	}

	&__dot {
		flex: none;
		width: 10px;
		height: 10px;
		border-radius: 50%;
		background: var(--color-text-maxcontrast);
	}

	&__host {
		flex: 1;
		min-width: 0;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-variant-numeric: tabular-nums;
	}

	&__state {
		flex: none;
		color: var(--color-text-lighter);
		font-size: 13px;
	}

	&__link {
		color: var(--color-primary-element);
		text-decoration: none;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__row--delivered &__dot {
		background: var(--color-success);
	}

	&__row--sending &__dot,
	&__row--waiting &__dot {
		background: var(--color-warning);
	}

	&__row--failing &__dot {
		background: var(--color-warning);
		box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-warning) 30%, transparent);
	}

	&__row--abandoned &__dot {
		background: var(--color-error);
	}
}
</style>
