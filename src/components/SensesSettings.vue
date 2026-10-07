<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="senses-settings">
		<div class="senses-settings__row">
			<NcCheckboxRadioSwitch
				type="switch"
				:modelValue="sounds"
				@update:modelValue="setSounds">
				{{ t('social', 'Play sounds') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="tertiary" @click="preview">
				<template #icon>
					<IconPlay :size="20" />
				</template>
				{{ t('social', 'Listen') }}
			</NcButton>
		</div>
		<p class="senses-settings__lede">
			{{ t('social', 'A soft tick when you like something, a breath of air when a post goes out, and a two-note chime when a direct message arrives. Off until you turn it on.') }}
		</p>

		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="vibration"
			@update:modelValue="setVibration">
			{{ t('social', 'Vibrate') }}
		</NcCheckboxRadioSwitch>
		<p class="senses-settings__lede">
			{{ t('social', 'A short tap in the hand on a phone when you like, boost, react or post. Your system setting for less motion turns it off as well.') }}
		</p>

		<NcCheckboxRadioSwitch
			type="switch"
			:modelValue="videoSound"
			@update:modelValue="setVideoSound">
			{{ t('social', 'Start videos with sound') }}
		</NcCheckboxRadioSwitch>
		<p class="senses-settings__lede">
			{{ t('social', 'Shorts start with their sound on, where the browser allows it; otherwise one tap on the speaker turns it on. Muting one keeps the rest muted until you close the tab.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import IconPlay from 'vue-material-design-icons/Play.vue'
import { forgetMuteChoice } from '../composables/useSoundAutoplay.js'
import {
	buzz,
	play,
	setSoundsEnabled,
	setVibrationEnabled,
	setVideoSoundEnabled,
	soundsEnabled,
	vibrationEnabled,
	videoSoundEnabled,
} from '../services/senses.js'

/**
 * The switches for sound, vibration and whether videos start with sound.
 *
 * Nothing goes to the server: all three are about the device in front of the
 * reader, so they are kept in this browser -- see services/senses.js.
 */
export default {
	name: 'SensesSettings',

	components: {
		IconPlay,
		NcButton,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			sounds: soundsEnabled(),
			vibration: vibrationEnabled(),
			videoSound: videoSoundEnabled(),
		}
	},

	methods: {
		t,

		/** @param {boolean} on the new position */
		setSounds(on) {
			this.sounds = on
			setSoundsEnabled(on)
			if (on) {
				play('like')
			}
		},

		/** @param {boolean} on the new position */
		setVibration(on) {
			this.vibration = on
			setVibrationEnabled(on)
			if (on) {
				buzz('like')
			}
		},

		/**
		 * The mute choice made in this tab is forgotten, or the switch would
		 * seem to do nothing until the tab was closed.
		 *
		 * @param {boolean} on the new position
		 */
		setVideoSound(on) {
			this.videoSound = on
			setVideoSoundEnabled(on)
			forgetMuteChoice()
		},

		/** Plays a few of them one after another, whatever the switch says. */
		preview() {
			const order = ['like', 'boost', 'post', 'dm']
			order.forEach((moment, index) => {
				window.setTimeout(() => play(moment, { force: true }), index * 450)
			})
		},
	},
}
</script>

<style scoped lang="scss">
.senses-settings {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__lede {
		margin-block-start: calc(var(--default-grid-baseline) * -1);
		color: var(--color-text-maxcontrast);
	}
}
</style>
