<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\MigrationService;
use OCA\Social\Service\MoveFinishService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The move an account's owner grants another server the right to send.
 *
 * `POST /api/v1/migration/move` is the button: a session, a password, the
 * own handle typed back. This is the same move for a token — the one the
 * person's new instance obtained by sending them here to consent — and it
 * takes exactly `write:migration`, never the broad `write` every phone app
 * holds: a token that can post must not be a token that can move the
 * account away.
 */
class MigrationApiController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private MigrationService $migrationService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/migration/move/authorized')]
	public function moveAuthorized(string $target = ''): DataResponse {
		try {
			$this->initViewer([MoveFinishService::SCOPE]);
			// the scope spelled out, on a token: the base accepts `write` for
			// any `write:*`, and a session has no scope at all
			if ($this->client === null || !in_array(MoveFinishService::SCOPE, $this->client->getAuthScopes(), true)) {
				throw new InsufficientScopeException('moving the account needs a token granted ' . MoveFinishService::SCOPE . ' itself');
			}

			$userId = $this->viewer()->getUserId();
			$moved = $this->migrationService->move($userId, $target);

			return new DataResponse(
				$this->migrationService->moveStatus($userId)
				+ ['target' => ['acct' => $moved->getAccount(), 'url' => $moved->getUrl() !== '' ? $moved->getUrl() : $moved->getId()]],
				Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
