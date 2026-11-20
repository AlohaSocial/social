/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Descriptions that travel with a file handed to the composer, such as the
 * weekly recap picture's, to prefill its description field. Held weakly on the
 * file, so a file nobody attaches takes its description with it.
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
