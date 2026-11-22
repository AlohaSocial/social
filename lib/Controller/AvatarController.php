<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Service\AvatarService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The avatar of a local account, addressed by its username.
 *
 * This is the icon of every local actor and the `avatar` of every local
 * account entity (`Person::localAvatarUrl()`): Nextcloud's own avatar route
 * names the login, and an account's username is chosen apart from it so that
 * the login is not published.
 */
class AvatarController extends Controller {
	/**
	 * A day, as Nextcloud's own avatar route: the address carries the
	 * avatar's version, so a new picture is a new address.
	 */
	private const CACHE_SECONDS = 86400;

	public function __construct(
		IRequest $request,
		private AvatarService $avatarService,
		private LoggerInterface $logger,
	) {
		parent::__construct('social', $request);
	}

	/**
	 * Unauthenticated, like every picture an actor document points at: other
	 * servers fetch it unsigned. The limits are `/media/{uuid}`'s, for the
	 * same reason: a timeline draws dozens of faces at once.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonymousRateLimit(limit: 600, period: 60)]
	#[UserRateLimit(limit: 3000, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/avatar/{username}/{size}', requirements: ['size' => '\d+'])]
	public function avatar(string $username, int $size): Response {
		// the two sizes Nextcloud stores, as its own route rounds them
		$size = ($size <= 64) ? 64 : 512;

		try {
			$file = $this->avatarService->published($username, $size);
		} catch (NotFoundException) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->warning('could not serve an avatar', ['username' => $username, 'exception' => $e]);

			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$response = new FileDisplayResponse($file, Http::STATUS_OK, ['Content-Type' => $file->getMimeType()]);
		// private: a reader who is signed in may be shown a picture a visitor is not
		$response->cacheFor(self::CACHE_SECONDS, false, true);

		return $response;
	}
}
