<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Protocol\Syntax;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * The two well-known documents of a PDS: a handle's DID, served on the
 * handle's own host, and the instance's `did:web` document.
 *
 * `/.well-known/atproto-did` is asked for at `https://alice.<host>/`, which
 * the web server routes here (contrib/webserver) and Nextcloud accepts
 * when `*.<host>` is a trusted domain. The handle is the request's host;
 * a handle this app never issued is a 404, so nothing is resolved by
 * pattern. For a proxy that cannot keep the host, `?handle=` says it.
 */
class AtprotoWellKnownController extends Controller {
	public function __construct(
		IRequest $request,
		private AtprotoConfig $config,
		private IdentityService $identities,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /.well-known/atproto-did`: the DID of the handle this host is.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/atproto-did')]
	public function atprotoDid(string $handle = ''): Response {
		if (!$this->config->isEnabled()) {
			return $this->text('Bluesky is not enabled on this server', Http::STATUS_NOT_FOUND);
		}
		$handle = Syntax::normalizeHandle($handle !== '' ? $handle : (string)preg_replace('/:\d+$/', '', $this->request->getInsecureServerHost()));
		if (!Syntax::isHandle($handle)) {
			return $this->text('Not a handle', Http::STATUS_NOT_FOUND);
		}
		try {
			$identity = $this->identities->getByHandle($handle);
		} catch (AtprotoIdentityNotFoundException) {
			return $this->text('No such handle', Http::STATUS_NOT_FOUND);
		}
		if (!$identity->isActive()) {
			return $this->text('No such handle', Http::STATUS_NOT_FOUND);
		}

		return $this->text($identity->did, Http::STATUS_OK);
	}

	/**
	 * `GET /.well-known/did.json`: this instance as `did:web:<host>`.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/.well-known/did.json')]
	public function didJson(): Response {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'NotFound'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataResponse($this->identities->instanceDocument());
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->cacheFor(300, false, true);

		return $response;
	}

	private function text(string $body, int $status): Response {
		$response = new DataDisplayResponse($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
		$response->addHeader('Access-Control-Allow-Origin', '*');
		if ($status === Http::STATUS_OK) {
			$response->cacheFor(300, false, true);
		}

		return $response;
	}
}
