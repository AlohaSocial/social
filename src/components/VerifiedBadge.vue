<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<span
		v-if="verified"
		class="verified-badge"
		role="img"
		:title="label"
		:aria-label="label">
		<svg
			class="verified-badge__check"
			viewBox="0 0 24 24"
			aria-hidden="true">
			<circle
				cx="12"
				cy="12"
				r="12"
				fill="currentColor" />
			<path fill="var(--color-main-background)" d="M10 16.2 6.4 12.6l1.4-1.4 2.2 2.2 6.2-6.2 1.4 1.4z" />
		</svg>
	</span>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'

/** Bluesky's own account, the verifier every Bluesky app trusts (`ActorMapper::BLUESKY_DID`) */
const BLUESKY_DID = 'did:plc:z72i7hdynmk6r22z27h6tvur'

/**
 * The check beside the name of a verified account.
 *
 * One mark for every way an account is verified: by this instance
 * (`verification`, naming who it verified in the name of) and by the
 * verifiers Bluesky trusts (`bluesky.verified`). The label says who; an
 * issuer that is this instance's own verifying account is named once.
 * Nothing is drawn for an account nobody verified.
 */
export default {
	name: 'VerifiedBadge',

	props: {
		/** the account, as a client entity */
		account: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/** @return {{by: string, issuer: string}|null} this instance's verification */
		here() {
			return this.account?.verification ?? null
		},

		/** @return {boolean} whether Bluesky shows the account as verified */
		elsewhere() {
			return this.account?.bluesky?.verified === true
		},

		/** @return {boolean} */
		verified() {
			return this.here !== null || this.elsewhere
		},

		/** @return {boolean} whether Bluesky itself is among the verifiers */
		byBluesky() {
			return this.elsewhere && this.account.bluesky.verified_by_bluesky === true
		},

		/** @return {number} the other trusted verifiers, this instance's own issuer left out */
		others() {
			if (!this.elsewhere) {
				return 0
			}
			const issuer = this.here?.issuer ?? ''

			return (this.account.bluesky.verified_by ?? [])
				.filter((did) => did !== issuer && did !== BLUESKY_DID)
				.length
		},

		/** @return {string} */
		label() {
			if (this.here !== null) {
				const name = this.here.by
				if (this.byBluesky) {
					return t('social', 'Verified by {name} and by Bluesky', { name })
				}
				if (this.others > 0) {
					return n('social', 'Verified by {name} and %n other trusted verifier', 'Verified by {name} and %n other trusted verifiers', this.others, { name })
				}

				return t('social', 'Verified by {name}', { name })
			}
			if (this.byBluesky) {
				return t('social', 'Verified by Bluesky')
			}

			return n('social', 'Verified by %n trusted verifier', 'Verified by %n trusted verifiers', Math.max(1, this.others))
		},
	},
}
</script>

<style lang="scss" scoped>
.verified-badge {
	display: inline-flex;
	align-items: center;
	vertical-align: -0.125em;
	margin-inline-start: 0.25em;
	// the blue people know a verified check by, readable on both themes
	color: #1083fe;
	line-height: 1;
}

.verified-badge__check {
	flex: 0 0 auto;
	width: 0.9em;
	height: 0.9em;
}
</style>
