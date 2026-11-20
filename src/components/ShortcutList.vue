<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="shortcut-list">
		<dl>
			<div v-for="shortcut in shortcuts" :key="shortcut.event" class="shortcut-list__row">
				<dt>
					<kbd v-for="key in shortcut.keys" :key="key">{{ key }}</kbd>
				</dt>
				<dd>{{ shortcut.label }}</dd>
			</div>
		</dl>
		<h3 class="shortcut-list__heading">
			{{ t('social', 'Watching a video') }}
		</h3>
		<dl>
			<div v-for="shortcut in playerShortcuts" :key="shortcut.label" class="shortcut-list__row">
				<dt>
					<kbd v-for="key in shortcut.keys" :key="key">{{ key }}</kbd>
				</dt>
				<dd>{{ shortcut.label }}</dd>
			</div>
		</dl>
		<p class="shortcut-list__hint">
			{{ t('social', 'Shortcuts are off while you are writing.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { PLAYER_SHORTCUTS, SHORTCUTS } from '../services/shortcuts.js'

/**
 * The keys and what they do, as one list: the `?` dialog and the Settings
 * page both draw this, so the two cannot disagree.
 */
export default {
	name: 'ShortcutList',

	computed: {
		shortcuts() {
			return SHORTCUTS
		},

		playerShortcuts() {
			return PLAYER_SHORTCUTS
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.shortcut-list {
	&__row {
		display: flex;
		align-items: baseline;
		gap: 16px;
		padding: 6px 0;
		border-bottom: 1px solid var(--color-border);

		dt {
			flex: 0 0 96px;
			display: flex;
			gap: 4px;
		}

		dd {
			margin: 0;
		}
	}

	&__heading {
		margin-block: 16px 4px;
		font-size: 15px;
		font-weight: bold;
	}

	&__hint {
		margin-top: 16px;
		color: var(--color-text-maxcontrast);
	}

	kbd {
		display: inline-block;
		min-width: 20px;
		padding: 2px 6px;
		border: 1px solid var(--color-border-dark);
		border-bottom-width: 2px;
		border-radius: 4px;
		background: var(--color-background-dark);
		font-family: monospace;
		font-size: 12px;
		text-align: center;
	}
}
</style>
