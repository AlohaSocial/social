/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate, translatePlural } from '@nextcloud/l10n'
import { computed, onBeforeUnmount, ref } from 'vue'
import logger from '../services/logger.js'
import { showError } from '../services/toast.js'
import { useInstanceStore } from '../store/instance.js'
import { useTimelineStore } from '../store/timeline.js'
import { focusParam, isFocalPoint } from '../utils/focalPoint.js'
import { isFilterActive, prepareImage } from '../utils/imageFilters.js'

/**
 * What the composer takes as an attachment. The file dialog is given these
 * as its `accept`, and a drop or a paste is held to the same list, so that
 * what can be dragged in is exactly what can be picked.
 */
const ACCEPTED_MEDIA_TYPES = ['image/', 'video/', 'audio/']

/**
 * The files a post may carry besides media, as CacheDocumentService::
 * DOCUMENT_MIME_TYPES has them: what people on a Nextcloud actually have to
 * share. The extensions are for a browser that reports no type for a file.
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
 * What the file picker offers. Narrower than what the composer takes from a
 * drop or an upload: Files is where the pictures are, and an audio file picked
 * out of a folder tree is not what this button is for.
 */
const PICKABLE_MEDIA_TYPES = ['image/*', 'video/*', ...ACCEPTED_DOCUMENT_TYPES]

/** how long the card says no for, in step with the refusal in TimelinePost */
const REFUSAL_DURATION = 400

/**
 * Everything the composer does with attachments: taking them from the file
 * dialog, a drop, a paste, Files or the picture library; uploading them with
 * progress; filters, descriptions and focal points; and the ceiling on how
 * many a post may carry.
 *
 * Attachments are keyed by the object URL of the file's preview, or by a
 * made-up key for one that has no file in the browser (Files, the library, a
 * re-draft), and each holds the server's answer in `data` once there is one.
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
	/** whether an attachment is on its way to the server */
	const uploading = ref(false)
	/** how far the current upload has got, 0..1 */
	const uploadProgress = ref(0)
	/** what the progress bar is working on, in words */
	const progressLabel = ref('')
	/** whether the Files dialog is open or its picks are being attached */
	const picking = ref(false)
	/** keeps two picks of the same file apart, since the path cannot */
	const pickCount = ref(0)
	/** whether files are being dragged over the card right now */
	const draggingFiles = ref(false)
	/** briefly true after a drop of something the composer cannot take */
	const refusedDrop = ref(false)
	/** when the refused-drop notice goes away */
	let refusalTimer = null

	/** what a post may carry, as the server holds it */
	const maxAttachments = computed(() => instanceStore.maxAttachments)

	/** the `accept` of the file dialog, from one list */
	const acceptedTypes = computed(() => [...ACCEPTED_MEDIA_TYPES.map((type) => `${type}*`), ...ACCEPTED_DOCUMENT_TYPES, ...ACCEPTED_DOCUMENT_EXTENSIONS].join(','))

	/** whether the composer holds a picture */
	const hasAttachments = computed(() => Object.keys(attachments.value).length > 0)

	/** whether the post is carrying all the server takes */
	const attachmentsFull = computed(() => Object.keys(attachments.value).length >= maxAttachments.value)

	/** attachments that can carry a description and have not been given one */
	const undescribed = computed(() => Object.values(attachments.value).filter((attachment) => attachment.data?.id !== undefined && (attachment.description || '').trim() === '').length)

	/** uploads the server refused */
	const failedUploads = computed(() => Object.values(attachments.value).filter((attachment) => attachment.failed === true).length)

	/** whether an upload has not come back yet */
	const hasPendingUploads = computed(() => Object.values(attachments.value).some((attachment) => attachment.failed !== true && attachment.data === null))

	/**
	 * Whether this post is a video: one attachment, and it a video.
	 *
	 * The same rule the wire form applies — a `Video` object *is* the
	 * video, so a post carrying a video and three photographs is a post.
	 */
	const isVideoPost = computed(() => {
		const all = Object.values(attachments.value)

		return all.length === 1
			&& (all[0].data?.type === 'video'
				|| (all[0].data?.media_type || '').startsWith('video/'))
	})

	/** the ids the post will carry */
	const mediaIds = computed(() => Object.values(attachments.value)
		.map((attachment) => attachment.data?.id)
		.filter((id) => id !== undefined && id !== null))

	const undescribedWarning = computed(() => translatePlural(
		'social',
		'%n attachment has no description',
		'%n attachments have no description',
		undescribed.value,
	))

	/** @param {Event} event the file dialog's change, with what was picked */
	async function handleFileChange(event) {
		const target = /** @type {HTMLInputElement} */ (event.target)
		const files = Array.from(target.files ?? [])
		// the input keeps its selection, so picking the same file twice in
		// a row would otherwise be ignored the second time
		target.value = ''

		await attachFiles(files)
	}

	/**
	 * Whether a drag is carrying files, as opposed to a selection being
	 * dragged around inside the composer — text moved from one line to the
	 * next is not an attachment and must not light the card up.
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

		// without this the browser keeps the drop for itself and opens the
		// file in the tab, which navigates away and takes the draft with it
		event.preventDefault()
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = 'copy'
		}
		draggingFiles.value = true
	}

	/**
	 * dragleave fires just as loudly when the pointer crosses from the card
	 * onto one of its own children, which is where the highlight usually
	 * starts flickering. Where the pointer went is the answer: it has only
	 * left when it went somewhere outside this element, or nowhere at all
	 * (relatedTarget is null when the drag leaves the window). A counter of
	 * enters and leaves would answer the same question, but it can only be
	 * repaired by an event that may never come — one missed leave and the
	 * card stays lit for good — while this is decided fresh every time.
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

		// same reason as dragover: an unhandled drop is a navigation
		event.preventDefault()
		draggingFiles.value = false
		// dropping a picture is a way of starting a post, so a closed
		// composer opens rather than swallowing the file out of sight
		expand()

		await attachDropped(Array.from(event.dataTransfer?.files ?? []))
	}

	/**
	 * A picture on the clipboard becomes an attachment; everything else is
	 * left to the contenteditable and its autocomplete, exactly as before.
	 *
	 * @param {ClipboardEvent} event the paste
	 */
	function handlePaste(event) {
		const files = Array.from(event.clipboardData?.files ?? []).filter((file) => acceptsFile(file))
		if (files.length === 0) {
			// text, a link, a mention pasted back in: none of our business
			return
		}

		// otherwise the browser drops the image into the box as markup the
		// post cannot carry
		event.preventDefault()
		attachFiles(files)
	}

	/**
	 * Attaches what the composer takes and turns the rest away, which is
	 * all the file dialog does with them — it never offers them at all.
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
	 * How many of these there is still room for, with a word about the rest.
	 *
	 * The server refuses the ninth attachment outright, so the refusal
	 * belongs here, where it can still be explained and where the eight
	 * that do fit are not lost with it.
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

	/** Says that the post is carrying as much as it can. */
	function announceCeiling() {
		showError(translatePlural(
			'social',
			'A post can carry %n attachment',
			'A post can carry %n attachments',
			maxAttachments.value,
		))
	}

	/**
	 * Attaches pictures the reader already has in Nextcloud, without a trip
	 * through the browser: the path is all that is sent.
	 */
	async function pickFromFiles() {
		if (attachmentsFull.value) {
			announceCeiling()
			return
		}

		let picked
		picking.value = true
		try {
			// imported here rather than at the top: the picker is most of
			// `@nextcloud/dialogs`, and it is wanted only by somebody who
			// has just clicked "attach from Files"
			const { getFilePickerBuilder } = await import('@nextcloud/dialogs')
			picked = await getFilePickerBuilder(translate('social', 'Pick files to attach'))
				.setMultiSelect(true)
				.setMimeTypeFilter(PICKABLE_MEDIA_TYPES)
				.allowDirectories(false)
				// Without this the dialog has **no confirm button at all**:
				// a picker built with neither `addButton` nor
				// `setButtonFactory` renders none, so a file could be
				// selected and there was nothing to press, and the only way
				// out was to close the dialog — which rejects, and attaches
				// nothing. `pick()` resolves with the selection when a
				// button is pressed, so the callback has nothing to do.
				.addButton({
					label: translate('social', 'Attach'),
					variant: 'primary',
					callback: () => {},
				})
				.build()
				.pick()
		} catch (error) {
			// closing the dialog without picking rejects, and changing one's
			// mind is not a failure to report
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
	 * Attaches a picture from the instance's shared library.
	 *
	 * The same shape as attaching from Files: a placeholder goes into the
	 * grid at once so the reader sees that something is happening, and the
	 * server's answer replaces it. Whether there is room for it is the
	 * caller's to ask, because the caller owns the picker it came from.
	 *
	 * @param {{slug: string, title: string}} gif the one that was chosen
	 */
	async function attachLibraryGif(gif) {
		expand()

		// the same picture may be chosen twice, and the slug cannot tell
		// those two attachments apart
		const key = `gif:${++pickCount.value}:${gif.slug}`
		attachments.value = {
			...attachments.value,
			[key]: { file: null, path: gif.title || gif.slug, data: null, failed: false },
		}

		uploading.value = true
		progressLabel.value = translate('social', 'Attaching…')
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
	 * Asks the server for one attachment per path, in order, keeping the
	 * ones it accepts. A path it refuses is marked and left in the grid:
	 * the others are already attached and must not go down with it.
	 *
	 * @param {string[]} paths files in the reader's own storage
	 */
	async function attachPaths(paths) {
		const accepted = roomFor(paths)

		picking.value = accepted.length > 0
		progressLabel.value = translate('social', 'Attaching from Files…')
		for (const [index, path] of accepted.entries()) {
			// the same picture may be picked twice, and the path cannot
			// tell those two attachments apart
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

	/**
	 * Remembers the filter chosen for an attachment. Nothing is uploaded:
	 * the preview is CSS and updates at once, and the filter is baked in
	 * once, as the post is sent (`bakeFilters()`).
	 *
	 * @param {object} change what was chosen
	 * @param {string} change.key the attachment's object URL
	 * @param {string} change.filter the filter id
	 */
	function applyFilter({ key, filter }) {
		const attachment = attachments.value[key]
		if (attachment === undefined) {
			return
		}

		attachments.value = {
			...attachments.value,
			[key]: { ...attachment, filter },
		}
	}

	/**
	 * Bakes each attachment's filter in and puts the filtered copy in place
	 * of the upload, just before the post is sent.
	 *
	 * At send rather than when the choice settles: a settle is a guess at
	 * when somebody has finished choosing, and every wrong guess was another
	 * full upload, with a race against Post while it was in flight. Here each
	 * filtered picture is uploaded exactly once more, and the post cannot go
	 * out carrying the copy it was meant to replace. The upload made on
	 * attaching stays what the post carries when no filter is chosen, and is
	 * what descriptions and focal points are saved against until then.
	 *
	 * A picture the browser could not filter comes back as it was and is
	 * posted as it was, as a filter is a decoration.
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
		progressLabel.value = translate('social', 'Applying filters…')
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
	 *
	 * The upload made on attaching is kept as `unfiltered`, so that going
	 * back to Original after a post that did not go out posts that upload
	 * again rather than the filtered one.
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

		// the description was typed against this picture and belongs to it
		// rather than to the upload it happened to be stored as, and so does
		// the focal point: a filter changes the colours, not where the face is
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
	 * Previews each file, uploads it, and remembers what came back. The one
	 * road in: the file dialog, a drop and a paste all arrive here.
	 *
	 * @param {File[]} allFiles the files to attach, in order
	 */
	async function attachFiles(allFiles) {
		const files = roomFor(allFiles)
		progressLabel.value = translate('social', 'Uploading…')
		for (const [index, file] of files.entries()) {
			const url = URL.createObjectURL(file)
			attachments.value = {
				...attachments.value,
				[url]: {
					file,
					data: null,
					failed: false,
				},
			}

			uploading.value = true
			// real progress, from the request itself: the bar used to be
			// hard-coded to 40% behind a `v-if="false"`
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
					// a failed upload is marked, never left as
					// `data: undefined` for the submit path to trip over
					data: mediaData?.id === undefined ? null : mediaData,
					failed: mediaData?.id === undefined,
				},
			}
		}
		progressLabel.value = ''
	}

	/**
	 * What of a file is uploaded: the file itself, or a picture shrunk to
	 * what the server and the canvas take, or nothing.
	 *
	 * The server holds every upload that is not a video to one size limit,
	 * and a video to another, and says so only after all of it has arrived;
	 * a picture is shrunk before it goes (`prepareImage()`), and anything
	 * still over its limit is refused here, where it costs nobody an upload
	 * — least of all a video, which cannot be shrunk and would otherwise
	 * travel whole before being turned away. The file kept on the attachment
	 * is the original, so a filter chosen later is drawn from it rather
	 * than from a copy already compressed once.
	 *
	 * @param {File} file what was attached
	 * @return {Promise<File|null>} what to upload, or null when it is too large
	 */
	async function fitForUpload(file) {
		if ((file.type || '').startsWith('video/')) {
			const limit = instanceStore.videoSizeLimit
			if (limit > 0 && file.size > limit) {
				showError(translate('social', 'This video is larger than the {size} MB this server takes', {
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

		showError(translate('social', 'This file is larger than the {size} MB this server takes', {
			size: Math.floor(limit / 1048576),
		}))

		return null
	}

	/**
	 * Takes an attachment off the post.
	 *
	 * @param {string} key which one
	 */
	function deletePreview(key) {
		const newAttachments = { ...attachments.value }
		delete newAttachments[key]
		attachments.value = newAttachments
		releasePreview(key)
	}

	/**
	 * Lets go of the blob URL a preview was drawn from. Without this the
	 * file stays in memory for the life of the document.
	 *
	 * @param {string} key the attachment key, which is that URL
	 */
	function releasePreview(key) {
		// an attachment picked out of Files is keyed by its path: there is
		// no object URL behind it to let go of
		if (!key.startsWith('blob:')) {
			return
		}

		try {
			URL.revokeObjectURL(key)
		} catch (error) {
			logger.debug('Could not release a preview URL', { error })
		}
	}

	/**
	 * Sends whatever descriptions were written, once, as the post goes.
	 *
	 * Saving per keystroke would be a request per letter; saving here means
	 * the description travels with the post that carries the picture.
	 */
	async function saveDescriptions() {
		const described = Object.values(attachments.value).filter((attachment) => attachment.data?.id
			&& (attachment.description || '').trim() !== ''
			&& (attachment.description || '').trim() !== attachment.saved)

		await Promise.all(described.map((attachment) => timelineStore.describeMedia({
			id: attachment.data.id,
			description: attachment.description.trim(),
		})))
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

		await timelineStore.describeMedia({ id: attachment.data.id, description: text })
	}

	/**
	 * Remembers what an attachment shows. Kept locally while the post is
	 * being written and sent when it goes, rather than on every keystroke.
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

	/** Every attachment gone, and the previews they were drawn from let go of. */
	function clearAttachments() {
		Object.keys(attachments.value).forEach((key) => releasePreview(key))
		attachments.value = {}
	}

	/**
	 * Puts back the pictures of a post that has just been deleted.
	 *
	 * They are the uploads the server still holds — deleting a post removes
	 * the post, not the media rows behind it — so they come back by id rather
	 * than being uploaded again, as many as the post has room for.
	 *
	 * @param {object[]} media the post's `media_attachments`
	 */
	function restoreMedia(media) {
		const restored = { ...attachments.value }
		for (const item of media) {
			if (item?.id === undefined || Object.keys(restored).length >= maxAttachments.value) {
				continue
			}

			// the same shape an upload leaves behind, with the server's
			// answer already in hand: `data.id` is what goes out as
			// `media_ids`, and `saved` is the description as the server
			// already holds it, so it is not written again unless it is
			// changed
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
	}
}
