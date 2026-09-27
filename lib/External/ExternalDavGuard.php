<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\External;

use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Keeps external users off WebDAV, CalDAV and CardDAV.
 *
 * WebDAV is served by `remote.php`, not by a controller, so the middleware
 * never sees it. Two checks cover it together:
 *
 * - `refuses()`, from `Application::boot()`, which `remote.php` runs for this
 *   app right before it hands the request to the DAV server: a browser
 *   session or HTTP basic credentials naming an external user. This is the
 *   only hook the legacy `/remote.php/caldav` and `/remote.php/carddav`
 *   endpoints have, and they would otherwise list the system address book.
 * - `ExternalDavListener`, inside the DAV server of `/remote.php/dav` and
 *   `/remote.php/webdav`, after authentication: whatever credential it was,
 *   a bearer token included.
 *
 * A basic-auth login name is refused before its password is checked, which
 * tells anybody that the name is an external user's. That is no secret: it is
 * the user's public fediverse handle.
 */
class ExternalDavGuard {
	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private IUserManager $userManager,
	) {
	}

	/** Whether this request is to `remote.php` and comes from an external user. */
	public function refuses(): bool {
		if (!str_ends_with($this->request->getScriptName(), '/remote.php')) {
			return false;
		}

		if (ExternalUserBackend::isExternal($this->userSession->getUser())) {
			return true;
		}

		$login = $this->basicAuthLogin();

		return $login !== '' && ExternalUserBackend::isExternal($this->resolve($login));
	}

	/**
	 * Answers the request with 403 and ends it, in the XML shape a DAV client
	 * expects an error in.
	 *
	 * @codeCoverageIgnore it ends the process
	 */
	public function refuse(): never {
		http_response_code(403);
		header('Content-Type: application/xml; charset=utf-8');
		echo '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">'
			. '<s:exception>Sabre\DAV\Exception\Forbidden</s:exception>'
			. '<s:message>This account can only use the Social app.</s:message>'
			. '</d:error>';
		exit();
	}

	private function basicAuthLogin(): string {
		$header = $this->request->getHeader('Authorization');
		if (stripos($header, 'basic ') !== 0) {
			return '';
		}

		$decoded = base64_decode(substr($header, 6), true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return '';
		}

		return explode(':', $decoded, 2)[0];
	}

	private function resolve(string $login): ?IUser {
		$user = $this->userManager->get($login);
		if ($user !== null || !str_contains($login, '@')) {
			return $user;
		}

		$byEmail = $this->userManager->getByEmail($login);

		return (count($byEmail) === 1) ? $byEmail[0] : null;
	}
}
