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
 * Turns away everything an IP block at `no_access` covers, on every route of
 * this app, with a 403 so peers stop redelivering (see `docs/Architecture.md`).
 *
 * The list is read on every request from `AccessBlockService`'s distributed
 * cache, once per request; there is deliberately no "no blocks" flag that
 * could drift and silently stop enforcement.
 *
 * Nextcloud administrators are let through to the administration routes (the
 * admin API, `AdminApiControllerBase`, and every admin-only route), so a
 * block covering their address can still be lifted.
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
