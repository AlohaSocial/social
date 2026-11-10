/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/**
 * What each code of an error says to the person, with what they can do next:
 * the codes of the Bluesky routes (Atproto-Compatibility §13.5: a move, the
 * handle, an app password), of the Migration routes and of the other routes
 * whose refusal is advice (API.md, Migration error codes and Other refusal
 * codes). The server's own sentence is English and names the step that
 * failed, so it is kept for the details. A sentence that needs a number or a
 * name — a limit, the field refused — reads it from the answer beside the
 * code.
 *
 * @type {Record<string, (said: Record<string, string|number>) => string>}
 */
const SENTENCES = {
	'bluesky-off': () => t('social', 'Bluesky is not switched on on this server. Ask your administrator about it.'),
	'no-identity': () => t('social', 'Your account has no Bluesky account on this server, so there is nothing to move.'),
	'identity-inactive': () => t('social', 'Your Bluesky account is switched off. Switch on "Be on Bluesky" first, then try again.'),
	'move-in-progress': () => t('social', 'Another move of your account is under way. Wait for it to finish, or call it off first.'),
	'pds-address-invalid': () => t('social', 'That server address does not look right. Check it and try again.'),
	'pds-not-public': () => t('social', 'That server cannot be reached from the internet. Check the address and try again.'),
	'pds-unreachable': () => t('social', 'The other server could not be reached. Check the address, or try again later.'),
	'pds-unknown': () => t('social', 'That address is not a server Bluesky accounts can live on. Check it and try again.'),
	'pds-refused': () => t('social', 'The other server refused a step of the move. Try again; if it happens again, the details say which step.'),
	'pds-session-expired': () => t('social', 'The sign-in on the other server ran out. Try again.'),
	'handle-not-allowed': () => t('social', 'That server does not give out handles like this one. Pick a handle that ends in one of its domains; the details name them.'),
	'handle-taken': () => t('social', 'That handle is taken on the other server. Pick another one.'),
	'invite-code-required': () => t('social', 'That server needs an invite code. Ask the people who run it for one and enter it.'),
	'invite-code-invalid': () => t('social', 'The other server did not accept the invite code. Check it and try again.'),
	'password-not-accepted': () => t('social', 'The other server did not accept that password. Pick a longer one.'),
	'rate-limited': () => t('social', 'There were too many tries for now. Wait a while and try again.'),
	'account-not-created': () => t('social', 'The other server did not make the account. The details say why.'),
	'keys-sealed': () => t('social', 'This server cannot open its keys right now. Ask your administrator about it.'),
	'directory-unreachable': () => t('social', 'The directory that keeps Bluesky identifiers did not answer. Try again later.'),
	'export-failed': () => t('social', 'The archive could not be made. Try again later.'),
	'tool-missing': () => t('social', 'The recovery tool is not available on this server. Ask your administrator about it.'),
	'plc-unreachable': () => t('social', 'The directory that keeps Bluesky identifiers did not answer. Try again later.'),
	'plc-refused': () => t('social', 'The directory that keeps Bluesky identifiers refused the change. Try again; if it happens again, the details say why.'),
	'recovery-key-missing': () => t('social', 'You have no recovery phrase. Make one first, or confirm that only the new server will control your account.'),
	'no-move-to-call-off': () => t('social', 'There is no move to call off. Reload the page to see where it stands.'),
	'move-switching': () => t('social', 'Your account is being pointed at the new server right now. Wait a moment: the move then goes on or stops.'),
	'move-past-switch': () => t('social', 'Your account already points at the new server, so the move can no longer be called off. Use "Try again" to finish it.'),
	'move-called-off': () => t('social', 'The move was called off.'),
	'blobs-missing': () => t('social', 'Some pictures or videos did not arrive. Copy them again, or go on without them.'),
	'repo-incomplete': () => t('social', 'Your posts did not all arrive on the other server. Copy them again.'),
	'no-decision-pending': () => t('social', 'The move is not waiting for a decision any more. Reload the page to see where it stands.'),
	'announce-invalid': () => t('social', 'This server does not know that way of saying where your account went. Reload the page and choose again.'),
	'profile-fields-full': () => t('social', 'Your profile had no free field for your new address. Add it to your profile yourself.'),
	'announce-failed': () => t('social', 'Your new address could not be posted. Post it yourself.'),
	'bluesky-move-first': () => t('social', 'Move your Bluesky account first, or choose to switch it off or keep it.'),
	'bluesky-choice-invalid': () => t('social', 'Choose what happens to your Bluesky account: move it, switch it off or keep it.'),
	'handle-invalid': () => t('social', 'That is not a Bluesky handle. Check it and try again.'),
	'handle-not-found': () => t('social', 'No Bluesky account goes by that handle. Check it and try again.'),
	'did-not-supported': () => t('social', 'This kind of Bluesky account cannot be moved or read from here.'),
	'did-not-found': () => t('social', 'The directory that keeps Bluesky identifiers does not know that account. Check the handle and try again.'),
	'import-in-progress': () => t('social', 'These follows are being taken over already. See how far it got on the list of imports.'),
	'no-failed-move': () => t('social', 'There is no stopped move to start again. Reload the page to see where it stands.'),
	'move-refused': () => t('social', 'The move could not be started again. The details say why.'),
	'account-here-already': () => t('social', 'That Bluesky account is on this server already. There is nothing to move.'),
	'pds-not-named': () => t('social', 'That account does not say which server it lives on, so it cannot be moved.'),
	'pds-blocked': () => t('social', 'This server does not talk to the server that account lives on. Ask your administrator about it.'),
	'wrong-password': () => t('social', 'The password was not accepted. Use the account\'s own password, not an app password.'),
	'auth-factor-required': () => t('social', 'Bluesky e-mailed you a sign-in code. Enter it and start again.'),
	'sign-in-code-invalid': () => t('social', 'That sign-in code is not right. Start again to get a new one.'),
	'sign-in-code-expired': () => t('social', 'That sign-in code is too old. Start again to get a new one.'),
	'sign-in-refused': () => t('social', 'The old server did not let you sign in. The details say why.'),
	'no-move-waiting': () => t('social', 'No move is waiting for a code. Reload the page to see where it stands.'),
	'code-invalid': () => t('social', 'That code is not right. Check it, or ask for a new one.'),
	'code-expired': () => t('social', 'That code is too old. Ask for a new one.'),
	'repo-unavailable': () => t('social', 'The old server did not hand over your posts. Try again later.'),
	'repo-too-large': () => t('social', 'Your account is larger than this server takes. Ask your administrator about it.'),
	'blob-too-large': () => t('social', 'A picture or video is larger than this server takes. Go on without it.'),
	'repo-invalid': () => t('social', 'What the old server handed over is not your account. Ask the people who run that server.'),
	'operation-mismatch': () => t('social', 'The old server confirmed something other than what was asked. Try again, and tell your administrator if it happens again.'),
	'nothing-to-republish': () => t('social', 'There are no posts waiting to be published again.'),
	'republish-queued': () => t('social', 'Your posts are being published again already.'),
	'move-replaced': () => t('social', 'This invitation was replaced by a newer one.'),
	'invite-expired': () => t('social', 'The invitation ran out of time. Invite the account again.'),
	'move-expired': () => t('social', 'The move ran out of time. Invite the account again.'),
	'bridgy-unreachable': () => t('social', 'Bridgy Fed could not be reached. Try again later.'),
	'move-interrupted': () => t('social', 'It was cut off before it finished. Try again: it goes on from where it stopped.'),
	'move-failed': () => t('social', 'Something went wrong on the way. Try again; if it happens again, the details say what.'),
	'server-error': () => t('social', 'Something went wrong on this server. Try again; if it happens again, ask your administrator.'),
	'handle-domain-invalid': () => t('social', 'That is not a domain name. Type a domain you own, such as example.com.'),
	'handle-reserved': () => t('social', 'Handles under this server\'s own address are given out by the server. Use a domain of your own.'),
	'handle-in-use': () => t('social', 'Another account here already uses that domain as its handle. Use another domain.'),
	'handle-not-verified': () => t('social', 'That domain does not point at your account yet. Add the record shown below, wait a few minutes for it to spread, and try again.'),
	'app-password-name-invalid': () => t('social', 'Give the app password a name of up to 64 characters.'),
	'app-password-name-taken': () => t('social', 'You have an app password with that name already. Pick another name.'),
	'app-password-limit': () => t('social', 'You have as many app passwords as you can have. Revoke one you no longer use first.'),
	'not-signed-in': () => t('social', 'You are not signed in any more. Reload the page, sign in and try again.'),
	'no-file': () => t('social', 'No file arrived. Pick the file again and try again.'),
	'file-too-large': () => t('social', 'That file is larger than this server takes. The details say how large it may be; ask your administrator about a larger one.'),
	'file-unreadable': () => t('social', 'That file could not be read. Pick it again and try again.'),
	'archive-unreadable': () => t('social', 'That file is not an archive this page can read. Pick the zip file the export made.'),
	'archive-not-social': () => t('social', 'That archive holds no account of this app. Pick the zip file the export made.'),
	'import-running': () => t('social', 'That is under way already. See how far it got on the list of imports.'),
	'import-not-found': () => t('social', 'That import cannot be taken off the list now. Reload the page to see where it stands.'),
	'import-failed': () => t('social', 'The import stopped because of a problem on this server. Try again; if it happens again, ask your administrator.'),
	'import-file-missing': () => t('social', 'The file of this import could not be read back on the server. Upload it again.'),
	'export-unreadable': () => t('social', 'That file is not an export this page can read. Pick the archive or the JSON file your old server made.'),
	'export-not-posts': () => t('social', 'That archive holds no posts. Pick the export of your posts from your old server.'),
	'export-instagram-html': () => t('social', 'That is Instagram\'s HTML download, which holds pages and not posts. Ask Instagram for your information in JSON and import that.'),
	'export-peertube-no-videos': () => t('social', 'That PeerTube export was made without the video files. Ask for the export again with the videos included.'),
	'move-confirm-expired': () => t('social', 'The move was not confirmed in time. Start it again.'),
	'list-unknown': () => t('social', 'There is no such list to download.'),
	'account-not-set-up': () => t('social', 'Your account is not set up yet. Open Aloha Social and choose your username to set it up, then try again.'),
	'account-address-invalid': () => t('social', 'That is not an account address. Type it like @you@example.social.'),
	'account-not-found': () => t('social', 'No account answers to that address. Check it and try again.'),
	'account-not-verifiable': () => t('social', 'That server only answers servers it can check, and it could not check this one. Ask your administrator to make this server reachable from the internet.'),
	'alias-self': () => t('social', 'That is this account itself. Type the address of your other account.'),
	'confirm-handle': () => t('social', 'Type your own handle to confirm the move. The details show it.'),
	'move-cooldown': () => t('social', 'This account moved not long ago. The details say when it can move again.'),
	'move-onto-self': () => t('social', 'An account cannot move onto itself. Type the address of your new account.'),
	'alias-missing': () => t('social', 'Your new account does not name this one yet. Add this account as an alias on the new account first, then try again.'),
	'finish-not-possible': () => t('social', 'The last step cannot be done from here for that server. Finish the move there, with its own button.'),
	'old-server-refused': () => t('social', 'The old server did not let this one finish the move. Try again later, or finish the move there.'),
	'video-address-invalid': () => t('social', 'That is not a video address. Copy the address of the video page and try again.'),
	'video-here-already': () => t('social', 'That video is on this server already. There is nothing to bring over.'),
	'video-unreachable': () => t('social', 'That address could not be read. Check it, or try again later.'),
	'video-unsupported': () => t('social', 'That address is not a video that can be brought over.'),
	'featured-tags-full': (said) => n('social', 'You can feature at most %n hashtag. Remove one first.', 'You can feature at most %n hashtags. Remove one first.', Number(said.limit) || 0),
	'shorts-full': (said) => n('social', 'You can have at most %n 24-hour short up at a time. Wait for one to run out, or delete one first.', 'You can have at most %n 24-hour shorts up at a time. Wait for one to run out, or delete one first.', Number(said.limit) || 0),
	'handle-length': (said) => t('social', 'A username can have at most {max} characters. Pick a shorter one.', { max: String(said.max ?? '') }),
	'handle-characters': () => t('social', 'A username can have letters, numbers and underscores, with dots and dashes in between. Pick another one.'),
	'handle-other-user': () => t('social', 'That is the user name of another person on this Nextcloud. Pick another one.'),
	'handle-in-use-here': () => t('social', 'That username is taken. Pick another one.'),
	'handle-held': () => t('social', 'That username was given up not long ago and is kept free for a while. Pick another one, or try again later.'),
	'account-exists': () => t('social', 'You have an account here already. Reload the page.'),
	'email-domain-refused': () => t('social', 'This server does not make accounts for email addresses at your domain. Ask your administrator about it.'),
	'linked-handle-invalid': () => t('social', 'Write the address of your other account like you@server.example.'),
	'linked-handle-local': () => t('social', 'That address is on this server. Create an account here instead.'),
	'banner-too-large': (said) => t('social', 'That picture is larger than this server takes (at most {limit}). Pick a smaller one.', { limit: String(said.limit ?? '') }),
	'banner-unusable': () => t('social', 'That file cannot be used as a banner. Pick another picture.'),
	'upload-interrupted': () => t('social', 'The upload did not finish. Try again.'),
	'setting-not-email': (said) => t('social', '"{field}" has to be an email address.', { field: said.field }),
	'setting-too-long': (said) => t('social', '"{field}" can have at most {max} characters.', { field: said.field, max: String(said.max ?? '') }),
	'setting-not-local-account': (said) => t('social', '"{field}" has to be an account on this server.', { field: said.field }),
	'setting-out-of-range': (said) => t('social', '"{field}" has to be between {min} and {max}.', { field: said.field, min: String(said.min ?? ''), max: String(said.max ?? '') }),
	'setting-zero-or-range': (said) => t('social', '"{field}" has to be 0, or between {min} and {max}.', { field: said.field, min: String(said.min ?? ''), max: String(said.max ?? '') }),
	'setting-heights': (said) => t('social', '"{field}" has to list heights between {min} and {max}, separated by commas.', { field: said.field, min: String(said.min ?? ''), max: String(said.max ?? '') }),
	'setting-not-choice': (said) => t('social', '"{field}" has to be one of: {choices}.', { field: said.field, choices: String(said.choices ?? '') }),
	'group-unknown': (said) => t('social', 'There is no group "{group}" on this server. Pick another one.', { group: String(said.group ?? '') }),
	'groups-too-many': (said) => n('social', 'At most %n group can become a list.', 'At most %n groups can become lists.', Number(said.max) || 0),
	'push-auth-required': () => t('social', 'Sign in before setting up notifications.'),
	'push-endpoint-unreachable': () => t('social', 'The notification service for this device could not be reached. Set it up again or try later.'),
	'push-keys-invalid': () => t('social', 'The notification setup for this device is invalid. Remove it and add it again.'),
	'push-policy-invalid': () => t('social', 'Choose a supported way to receive notifications.'),
	'push-alerts-invalid': () => t('social', 'One or more notification choices are invalid. Check them and try again.'),
	'push-standard-invalid': () => t('social', 'Choose whether this device should use the standard notification format.'),
	'push-subscriptions-full': (said) => n('social', 'You can have at most %n device receiving notifications. Remove one first.', 'You can have at most %n devices receiving notifications. Remove one first.', Number(said.limit) || 0),
	'push-subscription-invalid': () => t('social', 'This device sent incomplete notification setup. Set it up again.'),
	'push-data-invalid': () => t('social', 'These notification settings could not be read. Check them and try again.'),
}

/**
 * @param {string|null|undefined} code a code the server sends beside its sentence
 * @param {Record<string, string|number>} [said] the rest of the answer: the numbers and names the sentence needs
 * @param {Record<string, string>} [labels] what the page calls each field a refusal can name, by the name the server uses
 * @return {string|null} what it means to the person, or null for a code this page does not know
 */
export function errorSentence(code, said = {}, labels = {}) {
	if (!Object.hasOwn(SENTENCES, code ?? '')) {
		return null
	}
	const field = typeof said?.field === 'string' ? said.field : ''

	return SENTENCES[code]({ ...said, field: Object.hasOwn(labels, field) ? labels[field] : field })
}

/**
 * Why a request was refused, from the error axios threw.
 *
 * A route that sends no code, or one this page does not know, gets the
 * fallback: a sentence about what the person was doing, in their language.
 * What the server said is kept for the details: `message` where the answer
 * has one — the framework's own, and the routes whose `error` is a fixed
 * "request failed" — and `error` otherwise.
 *
 * @param {object} error what axios threw
 * @param {string} fallback what to say when the code is none this page knows
 * @param {Record<string, string>} [labels] what the page calls each of its fields, by the name the server uses
 * @return {{text: string, details: string}} the sentence, and the server's own words for the details
 */
export function errorOf(error, fallback, labels = {}) {
	const data = error?.response?.data
	const said = typeof data?.message === 'string' && data.message !== '' ? data.message : data?.error

	return {
		text: errorSentence(data?.code, data ?? {}, labels) ?? fallback,
		details: typeof said === 'string' ? said : '',
	}
}

/**
 * Why a background job failed, from the code and sentence it kept: an import
 * on the Migration page, or a move finished from here. A job kept before it
 * had a code says so in general terms, with what it kept for the details.
 *
 * @param {{code?: string, error?: string}|null|undefined} failure what the server kept
 * @param {string} fallback the code to explain when there is none this page knows
 * @return {{text: string, details: string}} the sentence, and the server's own words for the details
 */
export function failureOf(failure, fallback) {
	return {
		text: errorSentence(failure?.code) ?? SENTENCES[fallback]({}),
		details: typeof failure?.error === 'string' ? failure.error : '',
	}
}

/**
 * Why a move stopped, from the move as the server keeps it.
 *
 * @param {{error?: string, progress?: {code?: string}}} move the move
 * @return {{text: string, details: string}} the sentence, and the server's own words for the details
 */
export function stoppedMoveError(move) {
	return {
		text: errorSentence(move?.progress?.code) ?? SENTENCES['move-failed']({}),
		details: move?.error ?? '',
	}
}
