<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
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
 * What a status used to say: `GET /api/v1/statuses/:id/history`.
 *
 * `#[PublicPage]` with `#[NoCSRFRequired]` like `ApiController`, since a
 * Mastodon client has a bearer token and no session. A viewer is optional, as
 * in Mastodon: the status is resolved through `StreamService::getStreamByNid()`,
 * whose visibility filter makes a status the caller may not read a 404 before
 * any revision is read. A presented bearer token still has its scope checked.
 */
class HistoryController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		private CacheActorService $cacheActorService,
		ClientService $clientService,
		private StreamService $streamService,
		private StatusRevisionService $revisionService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * Every version of the status, oldest first, as Mastodon `StatusEdit`
	 * entities: the first is what was posted, the last what shows now.
	 * Unpaged, as Mastodon's is.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/history')]
	public function history(int|string $nid): DataResponse {
		try {
			$this->initViewer(['read:statuses'], false);

			$stream = $this->streamService->getStreamByNid($nid);

			return new DataResponse($this->revisionService->history($stream), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The viewer as the stream layer reads them: the local cached actor, in
	 * the local export format, handed to the services that decide what a
	 * viewer may see. A caller with no credentials is left anonymous and
	 * answered as the public internet; a token without the scope is refused.
	 */
	#[\Override]
	protected function prepareViewer(Person $viewer): Person {
		$viewer = $this->cacheActorService->getFromLocalAccount($viewer->getPreferredUsername());
		$viewer->setExportFormat(ACore::FORMAT_LOCAL);

		$this->streamService->setViewer($viewer);
		$this->cacheActorService->setViewer($viewer);

		return $viewer;
	}

	/**
	 * A post that is not there, or an id that is not one, is a 404 here like
	 * any other missing record.
	 */
	#[\Override]
	protected function error(Throwable $e): DataResponse {
		if ($e instanceof StreamNotFoundException || $e instanceof InvalidArgumentException) {
			return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
		}

		return parent::error($e);
	}
}
