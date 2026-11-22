/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, ref } from 'vue'
import logger from '../services/logger.js'
import { descriptionOf } from '../services/fileDescriptions.js'
import { showError, showWarning } from '../services/toast.js'
import { useInstanceStore } from '../store/instance.js'
import { useTimelineStore } from '../store/timeline.js'
import { focusParam, isFocalPoint } from '../utils/focalPoint.js'
import { isFilterActive, prepareImage } from '../utils/imageFilters.js'

/**
 * What the composer takes as an attachment, from the file dialog, a drop or a paste alike.
 */
const ACCEPTED_MEDIA_TYPES = ['image/', 'video/', 'audio/']

/**
 * The documents a post may carry, as in CacheDocumentService::DOCUMENT_MIME_TYPES.
 * The extensions are for a browser that reports no type for a file.
 */
const ACCEPTED_DOCUMENT_TYPES = [
	'application/pdf',
	'text/plain',
	'text/markdown',
	'text/csv',
	'application/zip',
	'application/epub+zip',
	'application/vnd.oasis.opendocument.text',
	'application/vnd.oasis.opendocument.spreadsheet',
	'application/vnd.oasis.opendocument.presentation',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation',
]
const ACCEPTED_DOCUMENT_EXTENSIONS = ['.pdf', '.txt', '.md', '.csv', '.zip', '.epub', '.odt', '.ods', '.odp', '.docx', '.xlsx', '.pptx']

/**
 * What the Files picker offers: narrower than a drop or an upload, without audio.
 */
const PICKABLE_MEDIA_TYPES = ['image/*', 'video/*', ...ACCEPTED_DOCUMENT_TYPES]

/** how long the card says no for, in step with the refusal in TimelinePost */
const REFUSAL_DURATION = 400

/**
 * The composer's attachments: picking, dropping, pasting and uploading them,
 * filters, descriptions, focal points and the per-post limit.
 *
 * Attachments are keyed by the object URL of the file's preview, or by a
 * made-up key for one that has no file in the browser (Files, the library, an
 * edit or a draft), and each holds the server's answer in `data` once there is one.
 *
 * @param {object} composer what the attachments need from the composer
 * @param {() => void} composer.expand opens it, as attaching something does
 * @param {() => Element|undefined} composer.root its root element, for telling a drag that left it from one that moved inside it
 */
export function useComposerAttachments({ expand, root }) {
	const timelineStore = useTimelineStore()
	const instanceStore = useInstanceStore()

	/** @type {import('vue').Ref<Record<string, import('../types/Composer.js').LocalAttachment>>} */
	const attachments = ref({})
	const uploading = ref(false)
	/** how far the current upload has got, 0..1 */
	const uploadProgress = ref(0)
	const progressLabel = ref('')
	/** whether the Files dialog is open or its picks are being attached */
	const picking = ref(false)
	/** keeps two picks of the same file apart, since the path cannot */
	const pickCount = ref(0)
	const draggingFiles = ref(false)
	/** briefly true after a drop of something the composer cannot take */
	const refusedDrop = ref(false)
	let refusalTimer = null

	const maxAttachments = computed(() => instanceStore.maxAttachments)

	/** the `accept` of the file dialog */
	const acceptedTypes = computed(() => [...ACCEPTED_MEDIA_TYPES.map((type) => `${type}*`), ...ACCEPTED_DOCUMENT_TYPES, ...ACCEPTED_DOCUMENT_EXTENSIONS].join(','))

	const hasAttachments = computed(() => Object.keys(attachments.value).length > 0)

	const attachmentsFull = computed(() => Object.keys(attachments.value).length >= maxAttachments.value)

	/** attachments that can carry a description and have not been given one */
	const undescribed = computed(() => Object.values(attachments.value).filter((attachment) => attachment.data?.id !== undefined && (attachment.description || '').trim() === '').length)

	const failedUploads = computed(() => Object.values(attachments.value).filter((attachment) => attachment.failed === true).length)

	const hasPendingUploads = computed(() => Object.values(attachments.value).some((attachment) => attachment.failed !== true && attachment.data === null))

	/** whether an attachment is still being rechecked (from a draft) or fetched (for a filter); the post waits */
	const checkingMedia = computed(() => Object.values(attachments.value).some((attachment) => attachment.checking === true || attachment.fetching === true))

	/**
	 * The attachments as a draft keeps them. The unfiltered upload is kept,
	 * because the filter is baked in again from the file when the post goes out.
	 *
	 * @type {import('vue').ComputedRef<import('../services/draft.js').DraftMedia[]>}
	 */
	const draftMedia = computed(() => Object.values(attachments.value)
		.filter((attachment) => attachment.data?.id !== undefined)
		.map((attachment) => {
			const upload = attachment.unfiltered ?? attachment.data
			return {
				data: {
					id: upload.id,
					type: upload.type,
					url: upload.url,
					preview_url: upload.preview_url,
					media_type: upload.media_type,
					description: upload.description,
					blurhash: upload.blurhash,
					meta: upload.meta,
				},
				description: attachment.description ?? '',
				focus: isFocalPoint(attachment.focus) ? attachment.focus : null,
				filter: isFilterActive(attachment.filter) ? attachment.filter : '',
			}
		}))

	/**
	 * Whether this post is a video: exactly one attachment, and it a video,
	 * as in the federated `Video` object.
	 */
	const isVideoPost = computed(() => {
		const all = Object.values(attachments.value)

		return all.length === 1
			&& (all[0].data?.type === 'video'
				|| (all[0].data?.media_type || '').startsWith('video/'))
	})

	const mediaIds = computed(() => Object.values(attachments.value)
		.map((attachment) => attachment.data?.id)
		.filter((id) => id !== undefined && id !== null))

	const undescribedWarning = computed(() => n(
		'social',
		'%n attachment has no description',
		'%n attachments have no description',
		undescribed.value,
	))

	/** @param {Event} event the file dialog's change, with what was picked */
	async function handleFileChange(event) {
		const target = /** @type {HTMLInputElement} */ (event.target)
		const files = Array.from(target.files ?? [])
		// the input keeps its selection, so picking the same file again would be ignored
		target.value = ''

		await attachFiles(files)
	}

	/**
	 * Whether a drag is carrying files, as opposed to text dragged inside the composer.
	 *
	 * @param {Event} event a drag event
	 * @return {boolean}
	 */
	function carriesFiles(event) {
		return Array.from(/** @type {DragEvent} */ (event).dataTransfer?.types ?? []).includes('Files')
	}

	/**
	 * @param {File} file a dropped or pasted file
	 * @return {boolean} whether the file dialog would have offered it
	 */
	function acceptsFile(file) {
		const type = file.type || ''
		return ACCEPTED_MEDIA_TYPES.some((prefix) => type.startsWith(prefix))
			|| ACCEPTED_DOCUMENT_TYPES.includes(type)
			|| ACCEPTED_DOCUMENT_EXTENSIONS.some((extension) => (file.name || '').toLowerCase().endsWith(extension))
	}

	/** @param {DragEvent} event a drag arriving over the card */
	function handleDragEnter(event) {
		if (!carriesFiles(event)) {
			return
		}

		event.preventDefault()
		draggingFiles.value = true
	}

	/** @param {DragEvent} event a drag moving over the card */
	function handleDragOver(event) {
		if (!carriesFiles(event)) {
			return
		}

		// without this the browser opens the file in the tab and the draft is lost
		event.preventDefault()
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy'
		}
		draggingFiles.value = true
	}

	/**
	 * dragleave also fires when the pointer moves onto a child of the card, so
	 * the drag has only left when relatedTarget is outside the card or null
	 * (out of the window). Unlike an enter/leave counter, one missed event
	 * cannot leave the card lit.
	 *
	 * @param {DragEvent} event the drag leaving something
	 */
	function handleDragLeave(event) {
		if (!draggingFiles.value) {
			return
		}

		const movedTo = event.relatedTarget
		if (movedTo instanceof Node && root()?.contains(movedTo)) {
			return
		}

		draggingFiles.value = false
	}

	/** @param {DragEvent} event the drop itself */
	async function handleDrop(event) {
		if (!carriesFiles(event)) {
			return
		}

		// an unhandled drop is a navigation
		event.preventDefault()
		draggingFiles.value = false
		// a drop onto a closed composer opens it
		expand()

		await attachDropped(Array.from(event.dataTransfer?.files ?? []))
	}

	/**
	 * A file on the clipboard becomes an attachment; everything else is left
	 * to the contenteditable and its autocomplete.
	 *
	 * @param {ClipboardEvent} event the paste
	 */
	function handlePaste(event) {
		const files = Array.from(event.clipboardData?.files ?? []).filter((file) => acceptsFile(file))
		if (files.length === 0) {
			return
		}

		// otherwise the browser inserts the image as markup the post cannot carry
		event.preventDefault()
		attachFiles(files)
	}

	/**
	 * Attaches what the composer takes and refuses the rest.
	 *
	 * @param {File[]} files everything that was dropped
	 */
	async function attachDropped(files) {
		const accepted = files.filter((file) => acceptsFile(file))

		if (accepted.length < files.length) {
			logger.debug('Refused files the composer does not take', { refused: files.length - accepted.length })
			refuseDrop()
		}

		if (accepted.length === 0) {
			return
		}

		await attachFiles(accepted)
	}

	/** Says no to a drop, briefly and once. */
	function refuseDrop() {
		refusedDrop.value = true
		window.clearTimeout(refusalTimer)
		refusalTimer = window.setTimeout(() => {
			refusedDrop.value = false
		}, REFUSAL_DURATION)
	}

	/**
	 * The items there is still room for, with an error about the rest. The
	 * server would refuse the whole request when over the limit.
	 *
	 * @param {Array} items files or paths, in the order they were offered
	 * @return {Array} the ones the post can still carry
	 */
	function roomFor(items) {
		const room = Math.max(maxAttachments.value - Object.keys(attachments.value).length, 0)
		if (items.length > room) {
			announceCeiling()
		}

		return items.slice(0, room)
	}

	/** Says that the post carries as many attachments as it can. */
	function announceCeiling() {
		showError(n(
			'social',
			'A post can carry %n attachment',
			'A post can carry %n attachments',
			maxAttachments.value,
		))
	}

	/**
	 * Attaches files from the reader's Nextcloud by path, without a trip through the browser.
	 */
	async function pickFromFiles() {
		if (attachmentsFull.value) {
			announceCeiling()
			return
		}

		let picked
		picking.value = true
		try {
			// loaded on demand: the picker is most of `@nextcloud/dialogs`
			const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
			picked = await getFilePickerBuilder(t('social', 'Pick files to attach'))
				.setMultiSelect(true)
				.setMimeTypeFilter(PICKABLE_MEDIA_TYPES)
				.allowDirectories(false)
				// a picker without `addButton` or `setButtonFactory` has no
				// confirm button; `pick()` resolves with the selection, so
				// the callback has nothing to do
				.addButton({
					label: t('social', 'Attach'),
					variant: 'primary',
					callback: () => {},
				})
				.build()
				.pick()
		} catch (error) {
			// closing the dialog without picking rejects; not an error
			logger.debug('The file picker was closed', { error })
			return
		} finally {
			picking.value = false
		}

		const paths = (Array.isArray(picked) ? picked : [picked])
			.filter((path) => typeof path === 'string' && path !== '' && path !== '/')

		if (paths.length === 0) {
			return
		}

		expand()
		await attachPaths(paths)
	}

	/**
	 * Attaches a picture from the instance's shared library, showing a
	 * placeholder until the server answers. The caller checks for room.
	 *
	 * @param {{slug: string, title: string}} gif the one that was chosen
	 */
	async function attachLibraryGif(gif) {
		expand()

		// the counter keeps two picks of the same picture apart
		const key = `gif:${++pickCount.value}:${gif.slug}`
		attachments.value = {
			...attachments.value,
			[key]: { file: null, path: gif.title || gif.slug, data: null, failed: false },
		}

		uploading.value = true
		progressLabel.value = t('social', 'Attaching…')
		const mediaData = await timelineStore.createMediaFromGif({ slug: gif.slug })
		uploading.value = false

		if (attachments.value[key] === undefined) {
			// deleted while the server was copying it
			return
		}

		attachments.value = {
			...attachments.value,
			[key]: {
				...attachments.value[key],
				data: mediaData?.id === undefined ? null : mediaData,
				failed: mediaData?.id === undefined,
			},
		}
	}

	/**
	 * Asks the server for one attachment per path, in order. A path it
	 * refuses is marked failed without affecting the others.
	 *
	 * @param {string[]} paths files in the reader's own storage
	 */
	async function attachPaths(paths) {
		const accepted = roomFor(paths)

		picking.value = accepted.length > 0
		progressLabel.value = t('social', 'Attaching from Files…')
		for (const [index, path] of accepted.entries()) {
			// the counter keeps two picks of the same file apart
			const key = `nextcloud:${++pickCount.value}:${path}`
			attachments.value = {
				...attachments.value,
				[key]: { file: null, path, data: null, failed: false },
			}

			uploading.value = true
			uploadProgress.value = index / accepted.length
			const mediaData = await timelineStore.createMediaFromFile({ path })
			uploadProgress.value = (index + 1) / accepted.length

			if (attachments.value[key] === undefined) {
				// deleted while the server was fetching it
				continue
			}

			attachments.value = {
				...attachments.value,
				[key]: {
					...attachments.value[key],
					data: mediaData?.id === undefined ? null : mediaData,
					failed: mediaData?.id === undefined,
				},
			}
		}
		uploading.value = false
		uploadProgress.value = 0
		progressLabel.value = ''
		picking.value = false
	}

	/** the filter wanted for each attachment whose file is being fetched for one */
	const wantedFilters = new Map()

	/**
	 * Remembers the filter chosen for an attachment; the preview is CSS and
	 * `bakeFilters()` applies it when the post is sent. An attachment with no
	 * file in the browser (from Files, an edit or a draft) has its file
	 * fetched first; if that fails, the filter is dropped with an error.
	 *
	 * @param {object} change what was chosen
	 * @param {string} change.key the attachment's key
	 * @param {string} change.filter the filter id
	 */
	async function applyFilter({ key, filter }) {
		const attachment = attachments.value[key]
		if (attachment === undefined) {
			return
		}
		if (wantedFilters.has(key)) {
			// the file is already being fetched; the last choice wins
			wantedFilters.set(key, filter)
			return
		}
		if (!isFilterActive(filter) || attachment.file instanceof File || !attachment.data?.url) {
			attachments.value = {
				...attachments.value,
				[key]: { ...attachment, filter },
			}
			return
		}

		wantedFilters.set(key, filter)
		attachments.value = {
			...attachments.value,
			[key]: { ...attachment, fetching: true },
		}
		const loaded = await loadFile(key)
		const wanted = wantedFilters.get(key)
		wantedFilters.delete(key)

		const current = attachments.value[key]
		if (current === undefined) {
			return
		}
		if (!loaded) {
			showError(t('social', 'Could not load the photo to apply the filter, so it stays as it is'))
		}
		attachments.value = {
			...attachments.value,
			[key]: { ...current, fetching: false, filter: loaded ? wanted : undefined },
		}
	}

	/**
	 * Fetches the file of an attachment that has none in the browser, and
	 * keeps it with an object URL for the filter swatches to draw.
	 *
	 * @param {string} key the attachment
	 * @return {Promise<boolean>} whether the file is there now
	 */
	async function loadFile(key) {
		const file = await timelineStore.fetchMediaFile(attachments.value[key].data)
		const current = attachments.value[key]
		if (file === undefined || current === undefined) {
			return false
		}

		attachments.value = {
			...attachments.value,
			[key]: { ...current, file, objectUrl: URL.createObjectURL(file) },
		}

		return true
	}

	/**
	 * Bakes each attachment's filter in and puts the filtered copy in place
	 * of the upload, just before the post is sent, so each filtered picture
	 * is uploaded exactly once more. A picture the browser cannot filter is
	 * posted unfiltered.
	 *
	 * @return {Promise<boolean>} false when a filtered copy would not upload,
	 *         so the post should not go out without it
	 */
	async function bakeFilters() {
		const pending = Object.keys(attachments.value).filter((key) => {
			const attachment = attachments.value[key]
			return attachment.file instanceof File
				&& attachment.data?.id !== undefined
				&& (attachment.bakedFilter ?? 'none') !== chosenFilter(attachment)
		})
		if (pending.length === 0) {
			return true
		}

		uploading.value = true
		progressLabel.value = t('social', 'Applying filters…')
		try {
			for (const [index, key] of pending.entries()) {
				uploadProgress.value = index / pending.length
				if (!(await bakeFilter(key))) {
					return false
				}
			}
		} finally {
			uploading.value = false
			uploadProgress.value = 0
			progressLabel.value = ''
		}

		return true
	}

	/**
	 * @param {import('../types/Composer.js').LocalAttachment} attachment an attachment
	 * @return {string} the filter it should be posted with, 'none' for none
	 */
	function chosenFilter(attachment) {
		return isFilterActive(attachment.filter) ? attachment.filter : 'none'
	}

	/**
	 * Puts the copy an attachment's filter asks for in place of its upload.
	 * The original upload is kept as `unfiltered`, for going back to Original
	 * after a failed post.
	 *
	 * @param {string} key the attachment's object URL
	 * @return {Promise<boolean>} whether the attachment is ready to be posted
	 */
	async function bakeFilter(key) {
		const attachment = attachments.value[key]
		const filter = chosenFilter(attachment)
		const unfiltered = attachment.unfiltered ?? attachment.data

		let mediaData = unfiltered
		if (filter !== 'none') {
			const filtered = await prepareImage(attachment.file, {
				filter,
				sizeLimit: instanceStore.imageSizeLimit,
			})
			if (filtered === attachment.file) {
				// the browser could not draw it; posted as it is
				return true
			}

			mediaData = await timelineStore.createMedia({ file: filtered })
			if (mediaData?.id === undefined) {
				logger.warn('Could not upload the filtered copy')
				return false
			}
		}

		const current = attachments.value[key]
		if (current === undefined) {
			return true
		}

		// the description and focal point carry over to the filtered copy
		const description = (current.description || '').trim()
		await Promise.all([
			description !== '' ? timelineStore.describeMedia({ id: mediaData.id, description }) : null,
			isFocalPoint(current.focus) ? timelineStore.focusMedia({ id: mediaData.id, focus: focusParam(current.focus) }) : null,
		])

		attachments.value = {
			...attachments.value,
			[key]: {
				...current,
				data: mediaData,
				unfiltered,
				failed: false,
				bakedFilter: filter,
				saved: description !== '' ? description : current.saved,
			},
		}

		return true
	}

	/**
	 * Previews each file, uploads it, and remembers what came back. The file
	 * dialog, a drop and a paste all arrive here.
	 *
	 * @param {File[]} allFiles the files to attach, in order
	 */
	async function attachFiles(allFiles) {
		const files = roomFor(allFiles)
		progressLabel.value = t('social', 'Uploading…')
		for (const [index, file] of files.entries()) {
			const url = URL.createObjectURL(file)
			attachments.value = {
				...attachments.value,
				[url]: {
					file,
					data: null,
					failed: false,
					...(descriptionOf(file) !== '' ? { description: descriptionOf(file) } : {}),
				},
			}

			uploading.value = true
			uploadProgress.value = index / files.length
			const upload = await fitForUpload(file)
			if (upload === null) {
				uploading.value = false
				uploadProgress.value = 0
				if (attachments.value[url] !== undefined) {
					attachments.value = {
						...attachments.value,
						[url]: { ...attachments.value[url], failed: true },
					}
				}
				continue
			}

			const mediaData = await timelineStore.createMedia({
				file: upload,
				onProgress: (fraction) => {
					uploadProgress.value = (index + fraction) / files.length
				},
			})
			uploading.value = false
			uploadProgress.value = 0

			if (attachments.value[url] === undefined) {
				// deleted while it was uploading
				continue
			}

			attachments.value = {
				...attachments.value,
				[url]: {
					...attachments.value[url],
					// never `data: undefined`, which the submit path would trip over
					data: mediaData?.id === undefined ? null : mediaData,
					failed: mediaData?.id === undefined,
				},
			}
		}
		progressLabel.value = ''
	}

	/**
	 * What of a file is uploaded: the file itself, a picture shrunk by
	 * `prepareImage()`, or nothing when it is over the server's size limit
	 * (refused here, before a wasted upload). The attachment keeps the
	 * original, so a later filter is drawn from it.
	 *
	 * @param {File} file what was attached
	 * @return {Promise<File|null>} what to upload, or null when it is too large
	 */
	async function fitForUpload(file) {
		if ((file.type || '').startsWith('video/')) {
			const limit = instanceStore.videoSizeLimit
			if (limit > 0 && file.size > limit) {
				showError(t('social', 'This video is larger than the {size} MB this server takes', {
					size: Math.floor(limit / 1048576),
				}))

				return null
			}

			return file
		}

		const limit = instanceStore.imageSizeLimit
		const upload = await prepareImage(file, { sizeLimit: limit })
		if ((upload.type || '').startsWith('video/') || limit <= 0 || upload.size <= limit) {
			return upload
		}

		showError(t('social', 'This file is larger than the {size} MB this server takes', {
			size: Math.floor(limit / 1048576),
		}))

		return null
	}

	/**
	 * @param {string} key which one
	 */
	function deletePreview(key) {
		const newAttachments = { ...attachments.value }
		const attachment = newAttachments[key]
		delete newAttachments[key]
		attachments.value = newAttachments
		releasePreview(key, attachment)
	}

	/**
	 * Revokes the blob URLs of a preview, which otherwise keep the file in memory.
	 *
	 * @param {string} key the attachment key, which is the URL of a file
	 *                     attached from this browser
	 * @param {import('../types/Composer.js').LocalAttachment} [attachment] the
	 *        attachment, whose file may have been fetched later
	 */
	function releasePreview(key, attachment) {
		// keys that are not blob URLs (Files, library, edit, draft) may still
		// have an objectUrl from a file fetched for a filter
		const urls = [key, attachment?.objectUrl ?? ''].filter((url) => url.startsWith('blob:'))

		for (const url of urls) {
			try {
				URL.revokeObjectURL(url)
			} catch (error) {
				logger.debug('Could not release a preview URL', { error })
			}
		}
	}

	/** @type {Set<Promise<void>>} descriptions still being saved since their field was left */
	const savingDescriptions = new Set()

	/**
	 * Saves unsaved descriptions as the post goes, and waits for those still
	 * being saved from a field just left, so the post never goes out first.
	 */
	async function saveDescriptions() {
		const described = Object.values(attachments.value).filter((attachment) => attachment.data?.id
			&& (attachment.description || '').trim() !== ''
			&& (attachment.description || '').trim() !== attachment.saved)

		await Promise.all([
			...savingDescriptions,
			...described.map((attachment) => timelineStore.describeMedia({
				id: attachment.data.id,
				description: attachment.description.trim(),
			})),
		])
	}

	/**
	 * Saves what an attachment shows as soon as the field is left, so a
	 * description outlives a post that never went out.
	 *
	 * @param {object} update what was written
	 * @param {string} update.key which attachment
	 * @param {string} update.description what it shows
	 */
	async function commitDescription({ key, description }) {
		const attachment = attachments.value[key]
		const text = (description || '').trim()
		if (attachment?.data?.id === undefined || text === '' || text === attachment.saved) {
			return
		}

		attachments.value = {
			...attachments.value,
			[key]: { ...attachment, description, saved: text },
		}

		const saving = timelineStore.describeMedia({ id: attachment.data.id, description: text })
		savingDescriptions.add(saving)
		try {
			await saving
		} finally {
			savingDescriptions.delete(saving)
		}
	}

	/**
	 * Remembers an attachment's description locally; it is saved when the
	 * field is left or the post is sent.
	 *
	 * @param {object} update what changed
	 * @param {string} update.key which attachment
	 * @param {string} update.description what it shows
	 */
	function describeAttachment({ key, description }) {
		if (attachments.value[key] === undefined) {
			return
		}

		attachments.value = {
			...attachments.value,
			[key]: { ...attachments.value[key], description },
		}
	}

	/**
	 * Moves an attachment's focal point, locally: what the crosshair shows
	 * while it is being dragged.
	 *
	 * @param {object} update what changed
	 * @param {string} update.key which attachment
	 * @param {import('../utils/focalPoint.js').FocalPoint} update.focus where the subject is
	 */
	function focusAttachment({ key, focus }) {
		if (attachments.value[key] === undefined || !isFocalPoint(focus)) {
			return
		}

		attachments.value = {
			...attachments.value,
			[key]: { ...attachments.value[key], focus },
		}
	}

	/**
	 * Saves where the subject is once the drag is over, through the same
	 * request the description takes.
	 *
	 * @param {object} update what was set
	 * @param {string} update.key which attachment
	 * @param {import('../utils/focalPoint.js').FocalPoint} update.focus where the subject is
	 */
	async function commitFocus({ key, focus }) {
		const attachment = attachments.value[key]
		if (attachment?.data?.id === undefined || !isFocalPoint(focus)) {
			return
		}

		focusAttachment({ key, focus })
		await timelineStore.focusMedia({ id: attachment.data.id, focus: focusParam(focus) })
	}

	/** Removes every attachment and releases its preview. */
	function clearAttachments() {
		Object.entries(attachments.value).forEach(([key, attachment]) => releasePreview(key, attachment))
		attachments.value = {}
	}

	/**
	 * Puts back the attachments of a post being edited, by id rather than
	 * uploading them again, as many as there is room for.
	 *
	 * @param {object[]} media the post's `media_attachments`
	 */
	function restoreMedia(media) {
		const restored = { ...attachments.value }
		for (const item of media) {
			if (item?.id === undefined || Object.keys(restored).length >= maxAttachments.value) {
				continue
			}

			// `saved` is the description the server holds, so it is only
			// written again when changed
			restored[`redraft:${++pickCount.value}:${item.id}`] = {
				file: null,
				path: item.description || item.url || String(item.id),
				data: item,
				failed: false,
				description: item.description || '',
				saved: item.description || '',
			}
		}
		attachments.value = restored
	}

	/**
	 * Puts back the attachments a draft kept and rechecks each with the
	 * server, which may have cleaned up unposted uploads. One the server no
	 * longer has is removed with a warning; Post waits for all the answers.
	 *
	 * @param {import('../services/draft.js').DraftMedia[]} kept what the draft held, in order
	 */
	async function restoreDraftMedia(kept) {
		const restored = { ...attachments.value }
		const keys = []
		for (const item of kept) {
			if (Object.keys(restored).length >= maxAttachments.value) {
				break
			}

			const key = `draft:${++pickCount.value}:${item.data.id}`
			restored[key] = {
				file: null,
				path: item.description || item.data.url || String(item.data.id),
				data: item.data,
				failed: false,
				description: item.description,
				saved: item.data.description || '',
				focus: item.focus,
				filter: item.filter || undefined,
				checking: true,
			}
			keys.push(key)
		}
		attachments.value = restored

		const answers = await Promise.all(keys.map((key) => recheckDraftMedia(key)))
		const gone = answers.filter((stillThere) => !stillThere).length
		if (gone > 0) {
			showWarning(n(
				'social',
				'%n attachment of your draft is no longer on the server and was removed',
				'%n attachments of your draft are no longer on the server and were removed',
				gone,
			))
		}
	}

	/**
	 * Rechecks one attachment a draft came back with. A filtered picture has
	 * its file fetched again for baking; if that fails, the filter is dropped.
	 *
	 * @param {string} key the attachment
	 * @return {Promise<boolean>} false when the server no longer has it
	 */
	async function recheckDraftMedia(key) {
		const kept = attachments.value[key]
		const media = await timelineStore.fetchMedia(String(kept.data.id))
		if (attachments.value[key] === undefined) {
			// taken off while the server was being asked
			return true
		}
		if (media === null) {
			deletePreview(key)
			return false
		}

		const data = media ?? kept.data
		attachments.value = {
			...attachments.value,
			[key]: { ...attachments.value[key], data, saved: data.description || '' },
		}
		if (isFilterActive(kept.filter)) {
			await loadFile(key)
		}

		const current = attachments.value[key]
		if (current === undefined) {
			return true
		}
		attachments.value = {
			...attachments.value,
			[key]: {
				...current,
				filter: current.file instanceof File ? current.filter : undefined,
				checking: false,
			},
		}

		return true
	}

	onBeforeUnmount(() => window.clearTimeout(refusalTimer))

	return {
		attachments,
		uploading,
		uploadProgress,
		progressLabel,
		picking,
		draggingFiles,
		refusedDrop,
		maxAttachments,
		acceptedTypes,
		hasAttachments,
		attachmentsFull,
		undescribed,
		failedUploads,
		hasPendingUploads,
		checkingMedia,
		draftMedia,
		isVideoPost,
		mediaIds,
		undescribedWarning,
		handleFileChange,
		handleDragEnter,
		handleDragOver,
		handleDragLeave,
		handleDrop,
		handlePaste,
		announceCeiling,
		pickFromFiles,
		attachLibraryGif,
		attachPaths,
		attachFiles,
		applyFilter,
		bakeFilters,
		deletePreview,
		saveDescriptions,
		commitDescription,
		describeAttachment,
		focusAttachment,
		commitFocus,
		clearAttachments,
		restoreMedia,
		restoreDraftMedia,
	}
}
