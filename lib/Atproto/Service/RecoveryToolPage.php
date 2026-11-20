<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Atproto\Service;

use OCP\IL10N;

/**
 * The offline recovery tool as one HTML file: the page in the viewer's
 * language, the BIP-39 word list, and the built `social-recovery.js`
 * inlined, so a saved copy runs in a browser with this server gone. It
 * moves a DID to another server with the person's recovery key; see
 * docs/Moving.md "If this server goes away".
 */
class RecoveryToolPage {
	private const SCRIPT = __DIR__ . '/../../../js/social-recovery.js';
	private const WORDLIST = __DIR__ . '/../../../resources/atproto/bip39-english.txt';

	public function __construct(
		private IL10N $l10n,
		private AtprotoConfig $config,
		private string $scriptPath = self::SCRIPT,
	) {
	}

	/** Whether the tool's script is built; an installation from a release always has it. */
	public function isBuilt(): bool {
		return is_file($this->scriptPath);
	}

	/**
	 * The inline script as it stands in the page: the licences of what it
	 * bundles first, as webpack wrote them beside it, and `</script`
	 * escaped.
	 */
	public function script(): string {
		$licences = $this->scriptPath . '.LICENSE.txt';
		$script = (is_file($licences) ? (string)file_get_contents($licences) . "\n" : '') . (string)file_get_contents($this->scriptPath);

		return str_ireplace('</script', '<\/script', $script);
	}

	/** the CSP source that lets the inline script run where the page is served */
	public function scriptSource(): string {
		return "'sha256-" . base64_encode(hash('sha256', $this->script(), true)) . "'";
	}

	public function render(): string {
		$l = $this->l10n;
		$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$words = preg_split('/\r?\n/', trim((string)file_get_contents(self::WORDLIST))) ?: [];
		$data = json_encode([
			'words' => $words,
			'strings' => $this->strings(),
		], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$directory = $this->config->plcDirectory();

		return '<!DOCTYPE html>
<html lang="' . $e($l->getLanguageCode()) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $e($l->t('Bluesky recovery tool')) . '</title>
<style>
body { font: 16px/1.5 system-ui, sans-serif; margin: 0; padding: 16px; background: #fff; color: #222; }
main { max-width: 760px; margin: 0 auto; }
section { border-top: 1px solid #ccc; margin-top: 24px; padding-top: 8px; }
label { display: block; margin-top: 12px; font-weight: 600; }
input, textarea { box-sizing: border-box; width: 100%; font: 14px ui-monospace, monospace; padding: 6px; }
button { margin-top: 12px; padding: 8px 14px; font: inherit; cursor: pointer; }
.warning { background: #fff4ce; border-left: 4px solid #c90; padding: 8px 12px; }
.result { min-height: 1.5em; overflow-wrap: anywhere; }
.error { color: #b00020; }
@media (prefers-color-scheme: dark) {
	body { background: #171717; color: #eee; }
	.warning { background: #3a3000; }
	.error { color: #ff8a80; }
}
</style>
</head>
<body>
<main>
<h1>' . $e($l->t('Bluesky recovery tool')) . '</h1>
<p>' . $e($l->t('This page moves your Bluesky identifier to another server with your recovery phrase, even when the server that made it is gone. It runs in your browser and works from a saved copy: nothing leaves this page except the requests you press a button for, to the PLC directory and to the new server.')) . '</p>
<p class="warning">' . $e($l->t('While the server that made your phrase still exists, its key ranks above yours: for 72 hours after a change made with the phrase, that server\'s key can undo it.')) . '</p>

<section>
<h2>' . $e($l->t('1. Your key')) . '</h2>
<label for="key">' . $e($l->t('Your twelve words, or the private key as hex or multibase (z…)')) . '</label>
<textarea id="key" rows="3" autocomplete="off" autocapitalize="off" spellcheck="false"></textarea>
<p id="key-result" class="result" aria-live="polite"></p>
</section>

<section>
<h2>' . $e($l->t('2. Your identifier')) . '</h2>
<label for="did">' . $e($l->t('Your DID (did:plc:…)')) . '</label>
<input id="did" autocomplete="off" spellcheck="false">
<label for="directory">' . $e($l->t('PLC directory')) . '</label>
<input id="directory" value="' . $e($directory) . '" spellcheck="false">
<button id="fetch-last" type="button">' . $e($l->t('Read its latest operation')) . '</button>
<label for="last">' . $e($l->t('Its latest operation, read from the directory or pasted (JSON)')) . '</label>
<textarea id="last" rows="6" spellcheck="false"></textarea>
<p id="last-result" class="result" aria-live="polite"></p>
</section>

<section>
<h2>' . $e($l->t('3. A signing key of your own')) . '</h2>
<p>' . $e($l->t('Only needed when the old server can no longer sign for your account: the new server creates the account only for a request signed with the key your identifier names. This makes one in your browser and registers it with your recovery key.')) . '</p>
<button id="make-signing" type="button">' . $e($l->t('Make a signing key and sign the change')) . '</button>
<p id="signing-result" class="result" aria-live="polite"></p>
<label for="signing-key">' . $e($l->t('Your signing key (z…): made here, or pasted from an earlier visit')) . '</label>
<input id="signing-key" autocomplete="off" spellcheck="false">
<textarea id="signing-op" rows="6" readonly spellcheck="false"></textarea>
<button id="send-signing" type="button">' . $e($l->t('Send it to the PLC directory')) . '</button>
<p id="send-signing-result" class="result" aria-live="polite"></p>
</section>

<section>
<h2>' . $e($l->t('4. The new server')) . '</h2>
<label for="pds">' . $e($l->t('Address of the new server (https://…)')) . '</label>
<input id="pds" spellcheck="false">
<button id="describe" type="button">' . $e($l->t('Look the server up')) . '</button>
<p id="pds-result" class="result" aria-live="polite"></p>
<label for="pds-did">' . $e($l->t('The server\'s DID')) . '</label>
<input id="pds-did" spellcheck="false">
<label for="handle">' . $e($l->t('Your handle there')) . '</label>
<input id="handle" spellcheck="false">
<label for="email">' . $e($l->t('Email address')) . '</label>
<input id="email" type="email">
<label for="password">' . $e($l->t('A password for the new account')) . '</label>
<input id="password" type="password" autocomplete="new-password">
<label for="invite">' . $e($l->t('Invite code, if the server asks for one')) . '</label>
<input id="invite" spellcheck="false">
<button id="create-account" type="button">' . $e($l->t('Create the account with your identifier')) . '</button>
<p id="account-result" class="result" aria-live="polite"></p>
<label for="token">' . $e($l->t('Access token on the new server: filled in when the account is created here, or paste one')) . '</label>
<input id="token" type="password" autocomplete="off">
</section>

<section>
<h2>' . $e($l->t('5. Your posts and photos')) . '</h2>
<p>' . $e($l->t('From the archive "Download your Bluesky account": repo.car and the files in blobs/, unpacked.')) . '</p>
<label for="repo">repo.car</label>
<input id="repo" type="file" accept=".car">
<label for="blobs">blobs/</label>
<input id="blobs" type="file" multiple>
<button id="import" type="button">' . $e($l->t('Upload them to the new server')) . '</button>
<p id="import-result" class="result" aria-live="polite"></p>
</section>

<section>
<h2>' . $e($l->t('6. Point your identifier at the new server')) . '</h2>
<button id="fetch-credentials" type="button">' . $e($l->t('Ask the new server for its recommended credentials')) . '</button>
<label for="credentials">' . $e($l->t('Its recommended credentials, read or pasted (JSON)')) . '</label>
<textarea id="credentials" rows="6" spellcheck="false"></textarea>
<button id="sign" type="button">' . $e($l->t('Build and sign the operation')) . '</button>
<p id="sign-result" class="result" aria-live="polite"></p>
<textarea id="signed" rows="10" readonly spellcheck="false"></textarea>
<button id="copy" type="button">' . $e($l->t('Copy')) . '</button>
<button id="submit" type="button">' . $e($l->t('Send it to the PLC directory')) . '</button>
<p id="submit-result" class="result" aria-live="polite"></p>
</section>

<section>
<h2>' . $e($l->t('7. Switch the account on')) . '</h2>
<button id="activate" type="button">' . $e($l->t('Activate the account on the new server')) . '</button>
<p id="activate-result" class="result" aria-live="polite"></p>
</section>
</main>
<script type="application/json" id="recovery-data">' . $data . '</script>
<script>' . $this->script() . '</script>
</body>
</html>
';
	}

	/**
	 * What the script says, by the codes it uses.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		$l = $this->l10n;

		return [
			'not-twelve-words' => $l->t('A recovery phrase is twelve words.'),
			'unknown-word' => $l->t('One of the words is not on the list: check the spelling.'),
			'phrase-does-not-check' => $l->t('The words do not check: one of them is wrong or in the wrong place.'),
			'not-a-key' => $l->t('This is not a secp256k1 private key.'),
			'no-key' => $l->t('Give your twelve words or your key first.'),
			'no-did' => $l->t('Give your DID, did:plc: and 24 characters.'),
			'not-json' => $l->t('This is not JSON.'),
			'not-an-operation' => $l->t('This is not a PLC operation.'),
			'did-tombstoned' => $l->t('This identifier was deleted for good; it cannot be moved.'),
			'key-not-listed' => $l->t('Your key is not one of the rotation keys of this identifier, so it cannot change it.'),
			'credentials-unusable' => $l->t('The new server\'s credentials lack a signing key, a handle or an address.'),
			'no-signing-key' => $l->t('Make a signing key of your own first (step 3).'),
			'network' => $l->t('{url} could not be reached: {reason}'),
			'refused' => $l->t('{url} answered {status}: {reason}'),
			'key-ok' => $l->t('Your key\'s public half: {key}'),
			'last-lists-key' => $l->t('Your key is one of its rotation keys: {keys}'),
			'last-keys' => $l->t('Its rotation keys: {keys}'),
			'signing-made' => $l->t('Signed with your key. Keep the signing key below until the move is done: the new account is created with it.'),
			'sent' => $l->t('The PLC directory took the operation ({cid}).'),
			'server-ok' => $l->t('The server is {did}; handles there end in {domains}.'),
			'account-created' => $l->t('The account is created, switched off until the move is done.'),
			'blobs-uploaded' => $l->t('{count} files uploaded …'),
			'imported' => $l->t('Uploaded: the repository if you chose it, and {count} files.'),
			'signed-ok' => $l->t('Signed with your key. Rotation keys, highest first: {keys}'),
			'copied' => $l->t('Copied'),
			'activated' => $l->t('The account is switched on at the new server.'),
		];
	}
}
