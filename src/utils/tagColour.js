/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A colour for a hashtag, derived from the tag itself.
 *
 * The hue is computed from the name so `#design` looks the same everywhere,
 * with nothing stored. Case and the leading `#` are ignored, as on the server;
 * saturation and lightness are fixed at values legible on both themes.
 */

/** how saturated a tag chip is; enough to read as a colour, not a highlighter */
const SATURATION = 62

/** the lightness the hue is drawn at on a light background */
const LIGHTNESS_LIGHT = 38

/** and on a dark one, where the same hue has to come forward instead of back */
const LIGHTNESS_DARK = 68

/**
 * The tag as the colour is keyed on: no `#`, no case, no surrounding space.
 *
 * @param {string} tag as it was typed or as the API sent it
 * @return {string} the key
 */
function normalise(tag) {
	return String(tag ?? '').trim().replace(/^#+/, '').toLowerCase()
}

/**
 * A hue for a tag, stable across reloads and machines.
 *
 * FNV-1a, because it is four lines and spreads short strings well; the hash is
 * not a security boundary and nothing is stored under it.
 *
 * @param {string} tag the hashtag, with or without its `#`
 * @return {number} degrees, 0–359
 */
export function tagHue(tag) {
	const key = normalise(tag)
	if (key === '') {
		return 0
	}

	let hash = 0x811c9dc5
	for (let i = 0; i < key.length; i++) {
		hash ^= key.charCodeAt(i)
		// the FNV prime, as shifts: a plain multiply would overflow into a float
		hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24)
		hash >>>= 0
	}

	return hash % 360
}

/**
 * The custom properties a tag chip or heading is drawn with.
 *
 * A style object, since the hue is a continuum; the dark value is a second
 * property, and the component's CSS picks between them for the theme.
 *
 * @param {string} tag the hashtag
 * @return {{'--tag-hue': string, '--tag-colour': string, '--tag-colour-dark': string}} the style object
 */
export function tagStyle(tag) {
	const hue = tagHue(tag)

	return {
		'--tag-hue': String(hue),
		'--tag-colour': `hsl(${hue}, ${SATURATION}%, ${LIGHTNESS_LIGHT}%)`,
		'--tag-colour-dark': `hsl(${hue}, ${SATURATION}%, ${LIGHTNESS_DARK}%)`,
	}
}

/**
 * How saturated a chart band is: lower than a tag chip, so six bands side by
 * side can be told apart without shouting over the numbers.
 */
const SERIES_SATURATION = 55

/** A band's lightness on a light background, and on a dark one. */
const SERIES_LIGHTNESS_LIGHT = 58
const SERIES_LIGHTNESS_DARK = 62

/**
 * Where the first band's hue starts: blue, the colour Nextcloud uses for
 * "this one".
 */
const SERIES_FIRST_HUE = 215

/**
 * How far apart consecutive bands are, in degrees: sixty walks the wheel once
 * in six bands and keeps them in order against the legend, which the golden
 * angle would not.
 */
const SERIES_STEP = 60

/**
 * The colours one chart band is drawn with: a hue rather than a fixed hex,
 * like `tagStyle`, so it works on both themes. Both values come back and the
 * stylesheet picks, as only it knows the theme.
 *
 * @param {number} index which band, from 0
 * @return {{'--series-colour': string, '--series-colour-dark': string}} the style object
 */
export function seriesStyle(index) {
	const hue = (SERIES_FIRST_HUE + (Number(index) || 0) * SERIES_STEP) % 360

	return {
		'--series-colour': `hsl(${hue}, ${SERIES_SATURATION}%, ${SERIES_LIGHTNESS_LIGHT}%)`,
		'--series-colour-dark': `hsl(${hue}, ${SERIES_SATURATION}%, ${SERIES_LIGHTNESS_DARK}%)`,
	}
}
