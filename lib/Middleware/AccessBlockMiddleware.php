<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Middleware;

use OCA\Social\Controller\AdminApiControllerBase;
use OCA\Social\Service\AccessBlockService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Turns away everything an IP block at `no_access` covers.
 *
 * Here rather than at the two or three routes somebody would remember: "no
 * access" is a statement about the whole app, and a block that held on the
 * inbox but not on the API — or on last month's routes but not on the ones
 * added since — is not the thing an admin thought they were switching on.
 * Every request this app serves passes through one middleware; this is it.
 *
 * **403, not 429 or 404.** The address is being refused deliberately and
 * permanently, and telling it so is what makes a peer stop redelivering rather
 * than queue for days — the same reasoning as the refused inbox delivery in
 * `docs/Architecture.md`.
 *
 * The check costs one query per request, whether or not anything is blocked:
 * an empty list is still a list that has to be read. It is read once and then
 * lives for the length of the request, so the cost does not grow with the
 * number of blocks or the number of times the list is consulted.
 *
 * Skipping the read on an instance with no blocks would want a flag saying so,
 * and a flag that drifted out of step with the table would stop enforcing an
 * access list without saying anything — which is the wrong way round for a
 * security control to fail, for one query out of the fifteen or so a request
 * already makes.
 *
 * **Nextcloud administrators are let through to the administration
 * routes**: the admin API (`AdminApiControllerBase`) and every route only an
 * administrator may call (no `NoAdminRequired`, no `PublicPage`), such as the
 * settings page's own. That is where a block is lifted, and a block that
 * covers the administrator's address must not be one only SQL can undo.
 */
class AccessBlockMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private AccessBlockService $accessBlockService,
		private LoggerInterface $logger,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		$ip = $this->request->getRemoteAddress();
		if ($ip === '' || !$this->accessBlockService->isBlockedIp($ip)) {
			return;
		}
		if ($this->isAdministration($controller, $methodName)) {
			$this->logger->info('a blocked address was let through to an administration route', [
				'ip' => $ip, 'controller' => $controller::class, 'method' => $methodName,
			]);

			return;
		}

		$this->logger->info('a blocked address was refused', [
			'ip' => $ip, 'controller' => $controller::class, 'method' => $methodName,
		]);

		throw new AccessBlockedException();
	}

	/** Whether a Nextcloud administrator is calling an administration route. */
	private function isAdministration(Controller $controller, string $methodName): bool {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			return false;
		}
		if ($controller instanceof AdminApiControllerBase) {
			return true;
		}
		if (!method_exists($controller, $methodName)) {
			return false;
		}

		$method = new \ReflectionMethod($controller, $methodName);
		$doc = (string)$method->getDocComment();

		return $method->getAttributes(NoAdminRequired::class) === [] && $method->getAttributes(PublicPage::class) === []
			&& !str_contains($doc, '@NoAdminRequired') && !str_contains($doc, '@PublicPage');
	}

	#[\Override]
	public function afterException(
		Controller $controller, string $methodName, \Exception $exception,
	): Response {
		if (!($exception instanceof AccessBlockedException)) {
			throw $exception;
		}

		return new JSONResponse(
			['error' => 'This server does not accept requests from your address.'],
			Http::STATUS_FORBIDDEN
		);
	}
}
