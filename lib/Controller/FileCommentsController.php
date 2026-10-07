<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FileCommentsService;
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
 * Whether replies to posts made from Files show up as comments on the files,
 * and the author's comments there go out as replies. On unless turned off.
 */
class FileCommentsController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private FileCommentsService $fileCommentsService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/files_comments')]
	public function filesComments(): DataResponse {
		try {
			$this->initViewer(['read:accounts', 'read']);

			return new DataResponse($this->fileCommentsService->export($this->viewer->getUserId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Turns the switch, and answers with it. `enabled` is required. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/social/files_comments')]
	public function filesCommentsUpdate(mixed $enabled = null): DataResponse {
		try {
			$this->initViewer(['write:accounts', 'write']);

			$flag = (is_scalar($enabled) && $enabled !== '')
				? filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
			if ($flag === null) {
				throw new InvalidResourceException('enabled must be true or false');
			}

			$this->fileCommentsService->setEnabled($this->viewer->getUserId(), $flag);

			return new DataResponse($this->fileCommentsService->export($this->viewer->getUserId()), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
