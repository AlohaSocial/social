<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Exceptions\InsufficientScopeException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Middleware\AnonymousRateLimit;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ClientService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The channels an account publishes videos under.
 *
 * PeerTube requires a `Group` in a video's `attributedTo`, so a channel is
 * made automatically with an account's first video; these routes add or
 * rename one. `read:accounts` to read, `write:accounts` to change, as a
 * channel is an actor like the profile.
 */
class ChannelController extends ClientApiController {
	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private ChannelService $channelService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/** Every channel this account has, oldest first. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/channels')]
	public function index(): DataResponse {
		return $this->answer(
			['read:accounts'],
			fn (): array => $this->channelService->forOwner($this->viewer())
		);
	}

	/**
	 * Makes one. The handle cannot be changed afterwards, so it is asked for
	 * separately from the name.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 5, period: 3600)]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/channels')]
	public function create(string $handle = '', string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			['write:accounts'],
			fn (): array => [$this->channelService->create(
				$this->viewer(), $handle, $name, $description
			)]
		);
	}

	/** Renames one, or changes what it says it is about. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonymousRateLimit(limit: 30, period: 3600)]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/channels/{id}')]
	public function update(int $id, string $name = '', string $description = ''): DataResponse {
		return $this->answer(
			['write:accounts'],
			fn (): array => [$this->channelService->update($this->viewer(), $id, $name, $description)]
		);
	}

	/**
	 * Runs one of the three routes and answers the caller's channels as they now
	 * are.
	 *
	 * @param string[] $scopes
	 * @param callable(): array $action
	 */
	private function answer(array $scopes, callable $action): DataResponse {
		try {
			$this->initViewer($scopes);

			return new DataResponse(['channels' => $action()], Http::STATUS_OK);
		} catch (ClientNotFoundException|InsufficientScopeException $e) {
			return $this->error($e);
		} catch (InvalidResourceException $e) {
			// A taken or invalid handle; the message says which.
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->warning('a channel request failed', [
				'userId' => $this->viewer?->getUserId(), 'exception' => $e,
			]);

			return new DataResponse(['error' => 'request failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
