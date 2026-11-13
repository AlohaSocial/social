<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="aloha-directory">
		<p v-if="loading" class="social-admin__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<template v-else-if="view">
			<NcNoteCard v-if="error.text !== ''" type="error">
				<p>{{ error.text }}</p>
				<p v-if="error.details !== '' && error.details !== error.text" class="aloha-directory__details">
					{{ error.details }}
				</p>
			</NcNoteCard>

			<!-- Taking part -->
			<h4 class="aloha-directory__heading">
				{{ t('social', 'Listing') }}
			</h4>

			<template v-if="phase === 'none'">
				<p class="social-admin__hint">
					{{ t('social', 'The Aloha apps suggest a server from the directory to people who sign up. Curators review every server before it is listed, and check it every hour afterwards.') }}
				</p>
				<ul class="aloha-directory__checklist">
					<li :class="readyClass(view.signup.open)">
						{{ signupLine }}
					</li>
					<li :class="readyClass(missing.length === 0)">
						{{ missingLine }}
					</li>
				</ul>
				<NcButton variant="primary" :disabled="busy || !canEnrol" @click="act('/enrol')">
					{{ t('social', 'Ask to be listed') }}
				</NcButton>
			</template>

			<template v-else-if="phase === 'pending' || phase === 'verifying'">
				<p class="social-admin__hint">
					{{ verifyingLine }}
				</p>
				<NcNoteCard v-if="verificationFailed" type="warning">
					{{ verificationMessage }}
				</NcNoteCard>
				<div class="aloha-directory__actions">
					<NcButton
						v-if="phase === 'pending'"
						variant="primary"
						:disabled="busy"
						@click="act('/verify')">
						{{ t('social', 'Check the domain again') }}
					</NcButton>
					<NcButton v-else :disabled="busy" @click="act('/refresh')">
						{{ t('social', 'Ask for the result') }}
					</NcButton>
					<NcButton :disabled="busy" @click="withdraw">
						{{ t('social', 'Cancel') }}
					</NcButton>
				</div>
			</template>

			<template v-else>
				<dl class="aloha-directory__facts">
					<dt>{{ t('social', 'In the directory') }}</dt>
					<dd>{{ listingLine }}</dd>
					<dt>{{ t('social', 'Last heartbeat') }}</dt>
					<dd>{{ heartbeatLine }}</dd>
					<template v-if="nextReview !== ''">
						<dt>{{ t('social', 'Next review') }}</dt>
						<dd>{{ nextReview }}</dd>
					</template>
				</dl>
				<NcNoteCard v-if="pauseReason !== ''" type="warning">
					{{ pauseReason }}
				</NcNoteCard>
				<NcNoteCard v-if="profileError !== ''" type="error">
					<p>{{ profileError }}</p>
					<ul>
						<li v-for="(problems, field) in profileDetails" :key="field">
							{{ field }}: {{ problems.join(', ') }}
						</li>
					</ul>
				</NcNoteCard>
				<NcNoteCard v-else-if="pendingReview" type="info">
					{{ t('social', 'The curators look at the changes to the terms, jurisdiction, operator or moderation before they are published.') }}
				</NcNoteCard>
				<ul v-if="failingChecks.length > 0" class="aloha-directory__checks">
					<li v-for="check in failingChecks" :key="check.name">
						{{ check.message }}
					</li>
				</ul>
				<div class="aloha-directory__actions">
					<NcButton :disabled="busy" @click="act('/refresh')">
						{{ t('social', 'Send heartbeat and profile now') }}
					</NcButton>
					<NcButton :disabled="busy" @click="rotateKey">
						{{ t('social', 'Replace the key') }}
					</NcButton>
					<NcButton :disabled="busy" @click="withdraw">
						{{ t('social', 'Leave the directory') }}
					</NcButton>
				</div>

				<template v-if="comments.length > 0 || complaints.length > 0">
					<h4 class="aloha-directory__heading">
						{{ t('social', 'The curators') }}
					</h4>
					<ul class="aloha-directory__thread">
						<li v-for="comment in comments" :key="'c' + comment.id" :class="'aloha-directory__from--' + comment.from">
							<span class="aloha-directory__who">{{ comment.from === 'server' ? t('social', 'You') : t('social', 'Curators') }}</span>
							{{ comment.body }}
						</li>
					</ul>
					<NcTextArea v-model="reply" :label="t('social', 'Answer the curators')" :disabled="busy" />
					<NcButton :disabled="busy || reply.trim() === ''" @click="send('/comments', reply)">
						{{ t('social', 'Send') }}
					</NcButton>

					<div v-for="complaint in complaints" :key="'k' + complaint.id" class="aloha-directory__complaint">
						<p class="aloha-directory__who">
							{{ t('social', 'A complaint the curators shared') }}
						</p>
						<p>{{ complaint.body }}</p>
						<p v-for="(response, index) in complaint.responses" :key="index" :class="'aloha-directory__from--' + response.from">
							<span class="aloha-directory__who">{{ response.from === 'server' ? t('social', 'You') : t('social', 'Curators') }}</span>
							{{ response.body }}
						</p>
						<NcTextArea v-model="answers[complaint.id]" :label="t('social', 'Your answer')" :disabled="busy" />
						<NcButton :disabled="busy || (answers[complaint.id] ?? '').trim() === ''" @click="send('/complaints/' + complaint.id + '/responses', answers[complaint.id])">
							{{ t('social', 'Send') }}
						</NcButton>
					</div>
				</template>
			</template>

			<!-- Profile -->
			<h4 class="aloha-directory__heading">
				{{ t('social', 'Profile') }}
			</h4>
			<p class="social-admin__hint">
				{{ t('social', 'The directory and the apps show this. Empty fields use what this server already says about itself; the grey text shows that value.') }}
			</p>
			<NcNoteCard v-if="missing.length > 0" type="warning">
				{{ missingLine }}
			</NcNoteCard>
			<form class="aloha-directory__form" @submit.prevent="saveProfile">
				<fieldset v-for="group in groups" :key="group.id" class="aloha-directory__group">
					<legend>{{ group.title }}</legend>
					<template v-for="field in group.fields" :key="field.key">
						<NcCheckboxRadioSwitch
							v-if="field.type === 'bool'"
							v-model="form[group.id][field.key]"
							type="switch"
							:disabled="busy">
							{{ field.label }}
						</NcCheckboxRadioSwitch>
						<NcSelect
							v-else-if="field.type === 'choice'"
							v-model="form[group.id][field.key]"
							:inputLabel="field.label"
							:options="field.options"
							:reduce="(option) => option.id"
							label="label"
							:disabled="busy" />
						<NcTextArea
							v-else-if="field.type === 'text'"
							v-model="form[group.id][field.key]"
							:label="field.label"
							:placeholder="placeholder(group.id, field.key)"
							:disabled="busy" />
						<NcTextField
							v-else
							v-model="form[group.id][field.key]"
							:label="field.label"
							:type="field.type === 'number' ? 'number' : 'text'"
							:placeholder="placeholder(group.id, field.key)"
							:error="missing.includes(group.id + '.' + field.key)"
							:disabled="busy" />
					</template>
				</fieldset>
				<p class="social-admin__hint">
					{{ t('social', 'The rules come from "About this server", the signup mode, age and email check from "Who may sign up".') }}
				</p>
				<NcButton type="submit" variant="primary" :disabled="busy">
					{{ t('social', 'Save profile') }}
				</NcButton>
			</form>

			<!-- Settings and lists -->
			<h4 class="aloha-directory__heading">
				{{ t('social', 'Settings') }}
			</h4>
			<NcTextField
				v-model="advertisedAccounts"
				:label="t('social', 'Free accounts to advertise at most')"
				type="number"
				:placeholder="String(view.signup.freeAccounts)"
				:disabled="busy" />
			<NcCheckboxRadioSwitch v-model="alohaList" type="switch" :disabled="busy">
				{{ t('social', 'Search the servers the curators recommend when people look for somebody to follow') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="listMirror"
				:label="t('social', 'Read the lists from a mirror (optional)')"
				placeholder="https://"
				:disabled="busy" />
			<NcButton :disabled="busy" @click="saveSettings">
				{{ t('social', 'Save settings') }}
			</NcButton>

			<h4 class="aloha-directory__heading">
				{{ t('social', 'Signed lists') }}
			</h4>
			<NcNoteCard v-if="!view.lists.rootKeyConfigured" type="info">
				{{ t('social', 'No root key of the Aloha Directory is configured yet, so its lists are not read.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="view.lists.error !== ''" type="warning">
				{{ view.lists.error }}
			</NcNoteCard>
			<ul class="aloha-directory__lists">
				<li v-for="(list, name) in view.lists.lists" :key="name">
					<strong>{{ name }}.json</strong>
					{{ listLine(list) }}
				</li>
			</ul>
			<NcButton :disabled="busy || !view.lists.rootKeyConfigured" @click="act('/lists')">
				{{ t('social', 'Read the lists now') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { showSuccess } from '../../services/toast.js'
import { errorOf } from '../../utils/serverError.js'

/** How long a verification is polled for, and how often. */
const POLL_MS = 5000
const POLL_TRIES = 24

/**
 * Administration → Aloha Directory: asking to be listed, the domain proof,
 * the listing profile, the conversation with the curators, and the signed
 * lists this server reads.
 */
export default {
	name: 'AlohaDirectorySection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			busy: false,
			/** @type {object|null} what GET /aloha-directory answered */
			view: null,
			error: { text: '', details: '' },
			form: {},
			advertisedAccounts: '',
			alohaList: true,
			listMirror: '',
			reply: '',
			/** @type {Record<number, string>} */
			answers: {},
			polls: 0,
			timer: null,
		}
	},

	computed: {
		phase() {
			return this.view?.participation?.phase ?? 'none'
		},

		missing() {
			return this.view?.profile?.missing ?? []
		},

		canEnrol() {
			return this.view.signup.open && this.missing.length === 0
		},

		status() {
			return this.view?.participation?.status ?? {}
		},

		comments() {
			return [...(this.status.comments ?? [])].reverse()
		},

		complaints() {
			return this.status.complaints ?? []
		},

		failingChecks() {
			return (this.status.checks ?? []).filter((check) => !check.passed)
		},

		signupLine() {
			return this.view.signup.open
				? n('social', 'Sign-ups are open, with %n free account.', 'Sign-ups are open, with %n free accounts.', this.view.signup.freeAccounts)
				: t('social', 'Open sign-ups under "Who may sign up" first, with or without approval.')
		},

		missingLine() {
			return this.missing.length === 0
				? t('social', 'The profile is complete.')
				: t('social', 'The profile still needs: {fields}', { fields: this.missing.join(', ') })
		},

		verifyingLine() {
			return this.phase === 'pending'
				? t('social', 'The directory is waiting for its domain proof, which this server serves at /.well-known/aloha-directory.')
				: t('social', 'The directory is checking this server. This usually takes less than a minute.')
		},

		verificationFailed() {
			return this.view.participation.verification?.status === 'failed'
		},

		verificationMessage() {
			return this.view.participation.verification?.message || t('social', 'The directory could not verify this server.')
		},

		listingLine() {
			const states = {
				applied: t('social', 'Applied; the automatic checks run first'),
				in_review: t('social', 'Waiting for the curators'),
				waitlisted: t('social', 'On the waiting list'),
				listed: t('social', 'Listed'),
				paused: t('social', 'Paused'),
				delisted: t('social', 'Delisted'),
				rejected: t('social', 'Not accepted'),
				withdrawn: t('social', 'Withdrawn'),
			}
			const state = this.view.participation.listing ?? this.status.state ?? ''

			return states[state] ?? t('social', 'Enrolled')
		},

		heartbeatLine() {
			const heartbeat = this.view.participation.heartbeat ?? {}
			if (heartbeat.error) {
				return t('social', 'Failed: {error}', { error: heartbeat.error })
			}

			return heartbeat.at ? new Date(heartbeat.at * 1000).toLocaleString() : t('social', 'Not sent yet')
		},

		nextReview() {
			return this.status.next_review_at ? new Date(this.status.next_review_at).toLocaleDateString() : ''
		},

		pauseReason() {
			return this.status.pause?.reason ?? this.status.decision_reason ?? ''
		},

		profileError() {
			return this.view.participation.profile?.error ?? ''
		},

		profileDetails() {
			return this.view.participation.profile?.details ?? {}
		},

		pendingReview() {
			return this.view.participation.profile?.pendingReview === true
		},

		groups() {
			const operatorTypes = [
				{ id: 'person', label: t('social', 'A person') },
				{ id: 'association', label: t('social', 'An association') },
				{ id: 'company', label: t('social', 'A company') },
				{ id: 'public_body', label: t('social', 'A public body') },
			]

			return [
				{
					id: 'identity',
					title: t('social', 'This server'),
					fields: [
						{ key: 'name', type: 'string', label: t('social', 'Name') },
						{ key: 'description', type: 'string', label: t('social', 'One line about it (at most 280 characters)') },
						{ key: 'long_description', type: 'text', label: t('social', 'More about it') },
					],
				},
				{
					id: 'audience',
					title: t('social', 'Who it is for'),
					fields: [
						{ key: 'languages', type: 'string', label: t('social', 'Languages, such as de, en') },
						{ key: 'topic', type: 'string', label: t('social', 'Topic') },
						{ key: 'audience', type: 'string', label: t('social', 'Audience') },
					],
				},
				{
					id: 'terms',
					title: t('social', 'Terms'),
					fields: [
						{ key: 'terms_url', type: 'string', label: t('social', 'Terms of service') },
						{ key: 'privacy_url', type: 'string', label: t('social', 'Privacy policy') },
					],
				},
				{
					id: 'operator',
					title: t('social', 'Who runs it'),
					fields: [
						{ key: 'name', type: 'string', label: t('social', 'Operator') },
						{ key: 'type', type: 'choice', label: t('social', 'Kind of operator'), options: operatorTypes },
						{ key: 'website', type: 'string', label: t('social', 'Website') },
						{ key: 'funding', type: 'string', label: t('social', 'How it is paid for') },
						{ key: 'commitment_until', type: 'string', label: t('social', 'Committed to run it until (YYYY-MM)') },
						{ key: 'shutdown_notice_months', type: 'number', label: t('social', 'Months of notice before shutting down (at least 3)') },
					],
				},
				{
					id: 'jurisdiction',
					title: t('social', 'Where it is'),
					fields: [
						{ key: 'hosting_country', type: 'string', label: t('social', 'Hosted in (two-letter country code)') },
						{ key: 'operator_seat', type: 'string', label: t('social', 'Operator based in (two-letter country code)') },
						{ key: 'gdpr', type: 'bool', label: t('social', 'The GDPR applies') },
						{ key: 'dpa_url', type: 'string', label: t('social', 'Data processing agreement (optional)') },
					],
				},
				{
					id: 'moderation',
					title: t('social', 'Moderation'),
					fields: [
						{ key: 'moderators', type: 'number', label: t('social', 'Moderators') },
						{ key: 'response_hours', type: 'number', label: t('social', 'Hours to answer a report, at most') },
						{ key: 'abuse_email', type: 'string', label: t('social', 'Address for abuse reports') },
						{ key: 'policy_url', type: 'string', label: t('social', 'Moderation policy') },
						{ key: 'blocklist_policy', type: 'text', label: t('social', 'Which servers you block, and why (optional)') },
					],
				},
				{
					id: 'account',
					title: t('social', 'Accounts'),
					fields: [
						{ key: 'storage_mb', type: 'number', label: t('social', 'Storage per account in MB') },
					],
				},
			]
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,

		url(path = '') {
			return generateUrl('/apps/social/admin/aloha-directory' + path)
		},

		readyClass(ready) {
			return ready ? 'aloha-directory__ready' : 'aloha-directory__not-ready'
		},

		placeholder(group, key) {
			const value = this.view?.profile?.sent?.[group]?.[key]

			return Array.isArray(value) ? value.join(', ') : (value ?? '').toString()
		},

		listLine(list) {
			if (list.serial === 0) {
				return t('social', 'not read yet')
			}
			const when = new Date(list.fetchedAt * 1000).toLocaleString()

			return list.tooOld
				? t('social', 'serial {serial}, too old to be used', { serial: list.serial })
				: n('social', 'serial %n, read {when}', 'serial %n, read {when}', list.serial, { when })
		},

		/** @param {object} data what the server answered */
		show(data) {
			if (typeof data?.participation !== 'object' || typeof data?.signup !== 'object' || typeof data?.profile !== 'object' || typeof data?.lists !== 'object') {
				return
			}
			this.view = data
			const form = {}
			for (const group of this.groups) {
				form[group.id] = { ...(data.profile.form?.[group.id] ?? {}) }
			}
			if (Array.isArray(form.audience.languages)) {
				form.audience.languages = form.audience.languages.join(', ')
			}
			this.form = form
			this.advertisedAccounts = data.settings.advertisedAccounts === null ? '' : String(data.settings.advertisedAccounts)
			this.alohaList = data.settings.alohaList
			this.listMirror = data.settings.listMirror
			this.schedulePoll()
		},

		schedulePoll() {
			clearTimeout(this.timer)
			if (this.phase !== 'verifying') {
				this.polls = 0

				return
			}
			if (this.polls++ < POLL_TRIES) {
				this.timer = setTimeout(() => this.act('/refresh', {}, true), POLL_MS)
			}
		},

		/** @return {Promise<void>} */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(this.url())
				this.show(data)
			} catch (error) {
				this.error = errorOf(error, t('social', 'Could not load the Aloha Directory settings'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} path below /aloha-directory
		 * @param {object} [body] what is posted
		 * @param {boolean} [quiet] no success message
		 * @return {Promise<boolean>} whether it worked
		 */
		async act(path, body = {}, quiet = false) {
			this.busy = true
			this.error = { text: '', details: '' }
			try {
				const { data } = await axios.post(this.url(path), body)
				this.show(data)
				if (!quiet) {
					showSuccess(t('social', 'Done'))
				}

				return true
			} catch (error) {
				if (error?.response?.data?.participation) {
					this.show(error.response.data)
				}
				this.error = errorOf(error, t('social', 'The Aloha Directory did not accept this'))

				return false
			} finally {
				this.busy = false
			}
		},

		async send(path, body) {
			if (await this.act(path, { body })) {
				this.reply = ''
				this.answers = {}
			}
		},

		saveProfile() {
			const profile = JSON.parse(JSON.stringify(this.form))
			profile.audience.languages = (profile.audience.languages ?? '').toString()

			return this.act('/profile', { profile })
		},

		saveSettings() {
			return this.act('/settings', {
				advertisedAccounts: this.advertisedAccounts.toString(),
				alohaList: this.alohaList,
				listMirror: this.listMirror,
			})
		},

		withdraw() {
			if (window.confirm(t('social', 'Leave the Aloha Directory? Apps stop suggesting this server; you can ask to be listed again later.'))) {
				this.act('/withdraw')
			}
		},

		rotateKey() {
			if (window.confirm(t('social', 'Replace the key this server signs its requests to the directory with?'))) {
				this.act('/key')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.aloha-directory__heading {
	margin-block: 20px 8px;
	font-weight: bold;
}

.aloha-directory__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-block: 8px;
}

.aloha-directory__checklist {
	margin-block: 8px;

	li::before {
		margin-inline-end: 6px;
	}
}

.aloha-directory__ready::before {
	content: '✓';
	color: var(--color-success-text);
}

.aloha-directory__not-ready::before {
	content: '•';
	color: var(--color-warning-text);
}

.aloha-directory__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-block: 8px;

	dt {
		color: var(--color-text-maxcontrast);
	}
}

.aloha-directory__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 640px;
}

.aloha-directory__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;

	legend {
		font-weight: bold;
		padding-inline: 4px;
	}
}

.aloha-directory__thread,
.aloha-directory__checks,
.aloha-directory__lists {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block: 8px;
}

.aloha-directory__complaint {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-block: 8px;
}

.aloha-directory__who {
	font-weight: bold;
	margin-inline-end: 6px;
}

.aloha-directory__details {
	color: var(--color-text-maxcontrast);
}
</style>
