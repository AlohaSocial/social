/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Descriptions that travel with a file handed to the composer.
 *
 * A picture the app made itself, such as the weekly recap, knows what is in
 * it before anybody has looked: the composer fills the description field
 * with it, where the author can still change it. Keyed on the file itself and
 * held weakly, so a file nobody attaches takes its description with it.
 */

/** @type {WeakMap<Blob, string>} */
const descriptions = new WeakMap()

/**
 * @param {Blob} file the file that will be attached
 * @param {string} description what is in it
 * @return {Blob} the same file, for chaining
 */
export function describeFile(file, description) {
	descriptions.set(file, String(description ?? ''))

	return file
}

/**
 * @param {Blob} file a file being attached
 * @return {string} its description, or '' where it came without one
 */
export function descriptionOf(file) {
	return (file instanceof Blob && descriptions.get(file)) || ''
}
