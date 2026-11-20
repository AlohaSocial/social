<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Atproto\Move\BlueskyAfterMoveOut;
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
 * The account move for a token: what `POST /api/v1/migration/move` does for a
 * session, for the token the person's new instance obtained by consent.
 *
 * It takes exactly `write:migration`, never the broad `write` every phone app
 * holds, so a token that can post cannot move the account away. The token
 * must have been sent to an Aloha Social move-in callback, and the target
 * must be on that callback's host.
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
	public function moveAuthorized(string $target = '', string $bluesky = BlueskyAfterMoveOut::DEACTIVATE): DataResponse {
		try {
			$this->initViewer([MoveFinishService::SCOPE]);
			// the scope spelled out, on a token: the base accepts `write` for
			// any `write:*`, and a session has no scope at all
			if ($this->client === null || !in_array(MoveFinishService::SCOPE, $this->client->getAuthScopes(), true)) {
				throw new InsufficientScopeException('moving the account needs a token granted ' . MoveFinishService::SCOPE . ' itself');
			}

			// the token went to one move-in callback, and the move may only
			// go to an account on the server that callback belongs to
			$callback = $this->client->getAuthRedirectUri();
			if (!ClientService::isMoveInCallback($callback)) {
				throw new InsufficientScopeException('this token was not granted to an Aloha Social server finishing a move');
			}

			$userId = $this->viewer()->getUserId();
			$moved = $this->migrationService->move($userId, $target, true, $bluesky, (string)parse_url($callback, PHP_URL_HOST));

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
