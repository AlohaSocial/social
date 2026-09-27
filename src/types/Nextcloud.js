/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * @typedef DialogButton - one entry of NcDialog's `buttons`
 * @property {string} label - what the button says
 * @property {() => unknown} [callback] - what pressing it does; returning false keeps the dialog open
 * @property {import('@nextcloud/vue/components/NcButton').ButtonVariant} [variant] - how it is drawn
 * @property {boolean} [disabled] - whether it can be pressed
 * @property {string} [icon] - an SVG to draw beside the label
 * @property {'button'|'submit'|'reset'} [type] - its HTML type, for a dialog that is a form
 */

export {}
