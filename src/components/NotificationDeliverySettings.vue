<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="delivery-settings" :aria-busy="saving ? 'true' : undefined">
		<p v-if="loading" class="delivery-settings__hint">
			{{ t('social', 'Loading …') }}
		</p>

		<template v-else>
			<fieldset class="delivery-settings__group">
				<legend class="delivery-settings__legend">
					{{ t('social', 'When to tell you') }}
				</legend>
				<NcCheckboxRadioSwitch
					type="radio"
					name="social-delivery-mode"
					value="instant"
					:modelValue="draft.mode"
					@update:modelValue="setMode">
					{{ t('social', 'As they arrive') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="radio"
					name="social-delivery-mode"
					value="digest"
					:modelValue="draft.mode"
					@update:modelValue="setMode">
					{{ t('social', 'In a digest, at the times I choose') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div v-if="isDigest" class="delivery-settings__group">
				<ul class="delivery-settings__times">
					<li v-for="(time, index) in draft.times" :key="index" class="delivery-settings__row">
						<label :for="fieldId('time', index)" class="delivery-settings__label">
							{{ t('social', 'Time {n}', { n: index + 1 }) }}
						</label>
						<input
							:id="fieldId('time', index)"
							class="delivery-settings__input"
							type="time"
							step="60"
							required
							:value="time"
							@input="typeTime(index, $event)"
							@blur="flushTimes">
						<NcButton
							v-if="draft.times.length > 1"
							variant="tertiary"
							:disabled="saving"
							:aria-label="t('social', 'Remove time {n}', { n: index + 1 })"
							@click="removeTime(index)">
							<template #icon>
								<IconClose :size="20" />
							</template>
						</NcButton>
					</li>
				</ul>
				<NcButton
					v-if="draft.times.length < maxTimes"
					variant="tertiary"
					:disabled="saving"
					@click="addTime">
					<template #icon>
						<IconPlus :size="20" />
					</template>
					{{ t('social', 'Add a time') }}
				</NcButton>
				<p class="delivery-settings__hint">
					{{ t('social', 'Phone and desktop get one summary per time, grouped by kind.') }}
				</p>
				<p v-if="timesError !== ''" class="delivery-settings__error" aria-live="polite">
					{{ timesError }}
				</p>
			</div>

			<!-- Only drawn while something is being held back, which is the
			     only time an exception means anything: a digest, or quiet
			     hours over an otherwise instant account. The state is kept
			     either way, so turning the digest off and on again does not
			     lose it. -->
			<fieldset v-if="holdsAnything" class="delivery-settings__group">
				<legend class="delivery-settings__legend">
					{{ t('social', 'Still at once') }}
				</legend>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="draft.passthrough.direct"
					@update:modelValue="setPassthrough('direct', $event)">
					{{ t('social', 'Direct messages') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="draft.passthrough.mentions_from_followed"
					@update:modelValue="setPassthrough('mentions_from_followed', $event)">
					{{ t('social', 'Mentions from people I follow') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div class="delivery-settings__group">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="quietOn"
					@update:modelValue="setQuiet">
					{{ t('social', 'Quiet hours') }}
				</NcCheckboxRadioSwitch>
				<div v-if="quietOn" class="delivery-settings__pair">
					<div class="delivery-settings__row">
						<label :for="fieldId('quiet-from')" class="delivery-settings__label">
							{{ t('social', 'From') }}
						</label>
						<input
							:id="fieldId('quiet-from')"
							class="delivery-settings__input"
							type="time"
							step="60"
							required
							:value="draft.quiet.from"
							@input="typeQuiet('from', $event)"
							@blur="flushQuiet">
					</div>
					<div class="delivery-settings__row">
						<label :for="fieldId('quiet-to')" class="delivery-settings__label">
							{{ t('social', 'Until') }}
						</label>
						<input
							:id="fieldId('quiet-to')"
							class="delivery-settings__input"
							type="time"
							step="60"
							required
							:value="draft.quiet.to"
							@input="typeQuiet('to', $event)"
							@blur="flushQuiet">
					</div>
				</div>
				<p class="delivery-settings__hint">
					{{ t('social', 'Nothing but the exceptions above between these times; a summary when they end.') }}
				</p>
				<p v-if="quietError !== ''" class="delivery-settings__error" aria-live="polite">
					{{ quietError }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import debounce from 'debounce'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import logger from '../services/logger.js'
import {
	DEFAULT_DELIVERY,
	MAX_TIMES,
	fetchNotificationDelivery,
	isValidTime,
	normaliseDelivery,
	saveNotificationDelivery,
	sortTimes,
} from '../services/notificationDelivery.js'
import { showError } from '../services/toast.js'

/** How long a time field is left alone after a keystroke before it is saved. */
const TYPING_PAUSE = 600

/** Where quiet hours start when the switch is first turned on. */
const QUIET_DEFAULT = Object.freeze({ from: '22:00', to: '07:00' })

/**
 * When Aloha Social may interrupt: as things happen, or in a digest at the
 * times the reader picked, with direct messages and mentions from people they
 * follow allowed through, and quiet hours over the lot.
 *
 * Everything still lands in the notifications page; this is only about the
 * bell, the phone and the mail. The server holds one object per account and
 * answers the whole of it to every change, so `state` is always what the
 * server last confirmed and `draft` is what the page shows. A switch moves
 * at once and is saved at once; a time field is saved after a short pause in
 * the typing, because a time arrives in pieces and each half-typed piece is
 * not a time the server would take.
 */
export default {
	name: 'NotificationDeliverySettings',

	components: {
		IconClose,
		IconPlus,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			loading: true,
			/** whether a save is in flight */
			saving: false,
			/** what the server last confirmed */
			state: normaliseDelivery(DEFAULT_DELIVERY),
			/** what the page shows, including half-typed times */
			draft: normaliseDelivery(DEFAULT_DELIVERY),
			/** the quiet hours switch, kept apart from the pair so a cleared field does not fold the pair away */
			quietOn: false,
			/** why the times are not being saved, in the reader's words */
			timesError: '',
			/** why the quiet hours are not being saved */
			quietError: '',
			/**
			 * The debounced saves, made once in `created()`. Declared so they
			 * are part of the instance rather than appearing on it.
			 *
			 * @type {((...args: unknown[]) => void) & {clear?: () => void, flush?: () => void}|null}
			 */
			saveTimesDebounced: null,
			/** @type {((...args: unknown[]) => void) & {clear?: () => void, flush?: () => void}|null} */
			saveQuietDebounced: null,
		}
	},

	computed: {
		/** @return {boolean} */
		isDigest() {
			return this.draft.mode === 'digest'
		},

		/** @return {boolean} whether anything is held back, so an exception means something */
		holdsAnything() {
			return this.isDigest || this.quietOn
		},

		/** @return {number} */
		maxTimes() {
			return MAX_TIMES
		},
	},

	created() {
		this.saveTimesDebounced = debounce(this.saveTimes, TYPING_PAUSE)
		this.saveQuietDebounced = debounce(this.saveQuiet, TYPING_PAUSE)
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		this.saveTimesDebounced?.clear?.()
		this.saveQuietDebounced?.clear?.()
	},

	methods: {
		t,

		/**
		 * @param {string} name which field
		 * @param {number} [index] which row, for the times
		 * @return {string} an id unique to this instance
		 */
		fieldId(name, index) {
			return `delivery-${this.$.uid}-${name}${index === undefined ? '' : `-${index}`}`
		},

		/**
		 * An unreadable setting is not worth a toast on a page the reader may
		 * only be passing through; the defaults are shown and still work.
		 */
		async load() {
			try {
				this.accept(await fetchNotificationDelivery())
			} catch (error) {
				logger.debug('Could not read the notification delivery settings', { error })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Takes the server's answer as both the confirmed state and the page.
		 *
		 * @param {ReturnType<typeof normaliseDelivery>} delivery what the server answered
		 */
		accept(delivery) {
			this.state = delivery
			this.draft = normaliseDelivery(delivery)
			this.quietOn = delivery.quiet.from !== '' && delivery.quiet.to !== ''
			this.timesError = ''
			this.quietError = ''
		},

		/** Puts the page back to what the server last confirmed. */
		revert() {
			this.draft = normaliseDelivery(this.state)
			this.quietOn = this.state.quiet.from !== '' && this.state.quiet.to !== ''
		},

		/**
		 * Sends a change and takes the whole answer back; on a refusal the
		 * page goes back to what the server has and the reason is shown.
		 *
		 * @param {object} changes any subset of the delivery object
		 */
		async save(changes) {
			this.saving = true
			try {
				this.accept(await saveNotificationDelivery(changes))
			} catch (error) {
				logger.error('Could not save the notification delivery settings', { error })
				showError(error?.response?.data?.error || t('social', 'Could not save that setting'))
				this.revert()
			} finally {
				this.saving = false
			}
		},

		/** @param {'instant'|'digest'} mode the radio that was picked */
		setMode(mode) {
			if (mode === this.draft.mode) {
				return
			}
			this.draft.mode = mode
			this.save({ mode })
		},

		/**
		 * @param {'direct'|'mentions_from_followed'} key which exception
		 * @param {boolean} on the new position
		 */
		setPassthrough(key, on) {
			this.draft.passthrough[key] = on
			this.save({ passthrough: { ...this.draft.passthrough } })
		},

		/**
		 * The one place a time is added, so what it is added as is decided
		 * once: the hour after the latest one, so the new row does not sit on
		 * top of an existing one and is saved straight away rather than
		 * waiting to be typed.
		 */
		addTime() {
			if (this.draft.times.length >= MAX_TIMES) {
				return
			}
			const taken = sortTimes(this.draft.times)
			const latest = taken.length > 0 ? Number(taken[taken.length - 1].slice(0, 2)) : 7
			let hour = (latest + 1) % 24
			while (taken.includes(`${String(hour).padStart(2, '0')}:00`)) {
				hour = (hour + 1) % 24
			}
			const time = `${String(hour).padStart(2, '0')}:00`
			this.draft.times = [...this.draft.times, time]
			this.saveTimesDebounced?.clear?.()
			this.save({ times: sortTimes(this.draft.times) })
		},

		/** @param {number} index the row to drop */
		removeTime(index) {
			if (this.draft.times.length <= 1) {
				return
			}
			this.draft.times = this.draft.times.filter((_, position) => position !== index)
			this.saveTimesDebounced?.clear?.()
			this.timesError = ''
			this.save({ times: sortTimes(this.draft.times) })
		},

		/**
		 * @param {number} index the row being typed in
		 * @param {Event} event the input event, whose target holds the time so far
		 */
		typeTime(index, event) {
			const value = /** @type {HTMLInputElement} */ (event.target).value
			this.draft.times = this.draft.times.map((time, position) => (position === index ? value : time))
			this.saveTimesDebounced?.()
		},

		/** Leaving a field saves it at once rather than after the pause. */
		flushTimes() {
			this.saveTimesDebounced?.flush?.()
		},

		/**
		 * Saves the times, once they are all times and all different.
		 *
		 * The refusal is written here rather than left to the server because
		 * a half-typed field is not worth a round trip, and the way out of
		 * "two are the same" is on this page.
		 */
		saveTimes() {
			if (!this.isDigest) {
				return
			}
			if (!this.draft.times.every(isValidTime)) {
				this.timesError = t('social', 'Each time needs hours and minutes, like 08:00.')

				return
			}
			const times = sortTimes(this.draft.times)
			if (times.length !== this.draft.times.length) {
				this.timesError = t('social', 'Two of the times are the same.')

				return
			}
			this.timesError = ''
			if (times.join() === this.state.times.join()) {
				return
			}
			this.save({ times })
		},

		/**
		 * The switch: on starts the pair at a late evening and an early
		 * morning, which is what most people mean by quiet hours and is saved
		 * straight away; off clears both ends.
		 *
		 * @param {boolean} on the new position
		 */
		setQuiet(on) {
			this.quietOn = on
			this.saveQuietDebounced?.clear?.()
			this.quietError = ''
			const quiet = on ? { ...QUIET_DEFAULT } : { from: '', to: '' }
			this.draft.quiet = quiet
			this.save({ quiet })
		},

		/**
		 * @param {'from'|'to'} end which end of the quiet hours
		 * @param {Event} event the input event, whose target holds the time so far
		 */
		typeQuiet(end, event) {
			const value = /** @type {HTMLInputElement} */ (event.target).value
			this.draft.quiet = { ...this.draft.quiet, [end]: value }
			this.saveQuietDebounced?.()
		},

		/** Leaving a field saves it at once rather than after the pause. */
		flushQuiet() {
			this.saveQuietDebounced?.flush?.()
		},

		/** Saves the pair, once both ends are times. */
		saveQuiet() {
			if (!this.quietOn) {
				return
			}
			const { from, to } = this.draft.quiet
			if (!isValidTime(from) || !isValidTime(to)) {
				this.quietError = t('social', 'Quiet hours need both a start and an end, like 22:00 and 07:00.')

				return
			}
			this.quietError = ''
			if (from === this.state.quiet.from && to === this.state.quiet.to) {
				return
			}
			this.save({ quiet: { from, to } })
		},
	},
}
</script>

<style scoped lang="scss">
.delivery-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);

	&__group {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
		border: 0;
		margin: 0;
		padding: 0;
		min-width: 0;
	}

	&__legend {
		margin: 0 0 4px;
		padding: 0;
		font-size: 14px;
		font-weight: bold;
	}

	&__times {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
	}

	&__row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		min-height: 44px;
	}

	&__pair {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 6);
	}

	&__label {
		flex: 0 0 auto;
		min-inline-size: 5em;
		color: var(--color-text-maxcontrast);
	}

	// the design system has no time field; this is its text field's chrome
	// on the browser's own time control, so the clock picker stays
	&__input {
		min-height: var(--default-clickable-area);
		padding-inline: 12px;
		border: 2px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-element, var(--border-radius-large));
		background: var(--color-main-background);
		color: var(--color-main-text);
		font: inherit;

		&:hover:not(:disabled) {
			border-color: var(--color-primary-element);
		}

		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: 2px solid var(--color-main-text);
			outline-offset: 2px;
		}

		&:invalid {
			border-color: var(--color-error);
		}
	}

	&__hint {
		margin: 4px 0 0;
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}

	&__error {
		margin: 4px 0 0;
		color: var(--color-error);
		font-size: 13px;
	}
}
</style>
