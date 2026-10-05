<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\Atproto\AtprotoAccountService;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCA\Social\Service\Atproto\AtprotoEngagementService;
use OCA\Social\Service\Atproto\AtprotoIdentity;
use OCA\Social\Service\Atproto\AtprotoProfileService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The Bluesky card of the settings page: what is linked, what is read, and
 * the two buttons that change it.
 *
 * Session routes with CSRF rather than client-API ones, for the same reason
 * the migration routes are: a link is a credential for an account on another
 * network, and a third-party token holding `write` should not be able to
 * hand this instance one.
 */
class AtprotoController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoAccountService $accountService,
		private AtprotoEngagementService $engagementService,
		private AtprotoEgress $egress,
		private AtprotoIdentity $identity,
		private FollowService $followService,
		private AtprotoRequest $atprotoRequest,
		private AccountService $localAccountService,
		private StreamService $streamService,
		private AtprotoProfileService $profileService,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * What the card draws: the switch, the account linked by whoever is
	 * signed in, and the profiles this instance reads.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto')]
	public function index(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		return new DataResponse($this->accountService->status($userId), Http::STATUS_OK);
	}

	/**
	 * Links an account after the PDS has accepted the pair, which is what
	 * makes a refusal worth showing: the handle was not a handle, the app
	 * password was not accepted, or the network that publishes the handle
	 * could not be asked.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/atproto/link')]
	public function link(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		$body = $this->request->getParams();

		try {
			$account = $this->accountService->link(
				$userId,
				(string)($body['handle'] ?? ''),
				(string)($body['appPassword'] ?? ''),
				(string)($body['pds'] ?? '')
			);
		} catch (AtprotoException $e) {
			$this->logger->info('a Bluesky link was refused', [
				'user' => $userId, 'reason' => $e->getMessage(),
			]);

			return new DataResponse(
				['message' => $e->getMessage()],
				$e->getStatus() >= 400 && $e->getStatus() < 500 ? $e->getStatus() : Http::STATUS_BAD_REQUEST
			);
		}

		return new DataResponse([
			'handle' => $account->getHandle(),
			'did' => $account->getDid(),
			'state' => $account->getState(),
		], Http::STATUS_OK);
	}

	/**
	 * Removes the link this person's own account holds. Removing a link that
	 * was never made is a **404** rather than a 200 that changed nothing.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/atproto')]
	public function unlink(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		if (!$this->accountService->unlink($userId)) {
			return new DataResponse(['message' => 'no Bluesky account is linked'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([], Http::STATUS_OK);
	}

	/** Return this account's locally known posts that have a Bluesky record. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto/profile')]
	public function profile(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}
		$account = $this->atprotoRequest->getAccount($userId);
		if ($account === null) {
			return new DataResponse(['statuses' => [], 'nextCursor' => ''], Http::STATUS_OK);
		}

		try {
			$actorId = $this->localAccountService->getActorFromUserId($userId)->getId();
		} catch (\Throwable $e) {
			$this->logger->debug('could not resolve local actor for ATProto profile', ['exception' => $e]);
			return new DataResponse([], Http::STATUS_OK);
		}

		$limit = min(max((int)$this->request->getParam('limit', 20), 1), 50);
		$offset = min(max((int)$this->request->getParam('cursor', 0), 0), 1000000);
		$links = $this->atprotoRequest->getLinksForDid($account->getDid(), $limit + 1, $offset);
		$hasMore = count($links) > $limit;
		$statuses = [];
		foreach (array_slice($links, 0, $limit) as $link) {
			try {
				$status = $this->streamService->getStreamById($link->getLocalId(), true, ACore::FORMAT_LOCAL);
				if ($status->getAttributedTo() === $actorId) {
					$statuses[] = $status;
				}
			} catch (\Throwable $e) {
				$this->logger->debug('stale ATProto profile link skipped', ['exception' => $e]);
			}
		}

		return new DataResponse([
			'statuses' => $statuses,
			'nextCursor' => $hasMore ? (string)($offset + $limit) : '',
		], Http::STATUS_OK);
	}

	/** Update the linked Bluesky account's public display metadata. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/atproto/profile')]
	public function updateProfile(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		try {
			$body = $this->request->getParams();
			$avatar = $this->request->getUploadedFile('avatar');
			$banner = $this->request->getUploadedFile('banner');
			return new DataResponse([
				'profile' => $this->accountService->updateProfile(
					$userId,
					(string)($body['displayName'] ?? ''),
					(string)($body['description'] ?? ''),
					is_array($avatar) ? $avatar : null,
					is_array($banner) ? $banner : null,
					in_array(strtolower((string)($body['removeAvatar'] ?? '')), ['1', 'true', 'yes'], true),
					in_array(strtolower((string)($body['removeBanner'] ?? '')), ['1', 'true', 'yes'], true),
				),
			], Http::STATUS_OK);
		} catch (AtprotoException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus() >= 400 ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		}
	}

	/** Delete one native post owned by the linked Bluesky account. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/atproto/post')]
	public function deletePost(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		$localId = trim((string)$this->request->getParam('id', ''));
		if ($localId === '') {
			return new DataResponse(['message' => 'a Bluesky post id is required'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->egress->deleteOwn($userId, $localId);
			return new DataResponse([], Http::STATUS_OK);
		} catch (AtprotoException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus() >= 400 ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		}
	}

	/** Update one native post owned by the linked Bluesky account. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/atproto/post')]
	public function updatePost(): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}
		$body = $this->request->getParams();
		$localId = trim((string)($body['id'] ?? ''));
		if ($localId === '' || !array_key_exists('text', $body)) {
			return new DataResponse(['message' => 'a Bluesky post id and text are required'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->egress->updateOwn($userId, $localId, (string)$body['text']);
			return new DataResponse(['updated' => true], Http::STATUS_OK);
		} catch (AtprotoException $e) {
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus() >= 400 ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		}
	}

	/** Public handle-based profile entry point, parallel to `/@account`. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto/profiles/{handle}')]
	public function publicProfile(string $handle): DataResponse {
		try {
			$userId = $this->currentUserId();
			$cursor = trim((string)$this->request->getParam('cursor', ''));
			$data = $this->profileService->read($handle, 20, $userId, $cursor);
			$data['following'] = false;
			$data['viewerCanFollow'] = false;
			$data['viewerCanEdit'] = false;
			if ($userId !== null) {
				// Do not make the profile's edit/delete controls depend on a second
				// network round-trip. The durable linked-account row is authoritative
				// for ownership; status() also probes the PDS and can temporarily mark
				// an otherwise valid account broken when the AppView is unavailable.
				$linkedAccount = $this->atprotoRequest->getAccount($userId);
				$linkedIsUsable = $linkedAccount !== null
					&& $linkedAccount->getState() === AtprotoAccount::STATE_LINKED;
				$data['viewerCanFollow'] = $linkedIsUsable;
				$data['viewerCanEdit'] = $linkedAccount !== null
					&& $linkedIsUsable
					&& $linkedAccount->getDid() !== ''
					&& $linkedAccount->getDid() === (string)($data['profile']['did'] ?? '');
				if ($data['viewerCanFollow']) {
					try {
						$data['following'] = $this->engagementService->isFollowing($userId, (string)($data['profile']['did'] ?? ''));
					} catch (AtprotoException $e) {
						$this->logger->info('could not read native Bluesky follow state', ['exception' => $e]);
					}
				}
			}
			return new DataResponse($data, Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->info('ATProto profile lookup failed', ['handle' => $handle, 'exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], $e instanceof AtprotoException ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		}
	}

	/** Read direct replies from the native Bluesky thread endpoint. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/atproto/thread')]
	public function thread(): DataResponse {
		try {
			$id = trim((string)$this->request->getParam('id', ''));
			if ($id === '') {
				return new DataResponse(['message' => 'a Bluesky post id is required'], Http::STATUS_BAD_REQUEST);
			}
			return new DataResponse([
				'descendants' => $this->profileService->thread($id, $this->currentUserId()),
			], Http::STATUS_OK);
		} catch (\Throwable $e) {
			$this->logger->info('ATProto thread lookup failed', ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], $e instanceof AtprotoException ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		}
	}

	/** Follow a Bluesky actor with the linked account and mirror the local watch. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/atproto/follow')]
	public function follow(): DataResponse {
		return $this->setFollowing(true);
	}

	/** Remove the linked account's native Bluesky follow and local relationship. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/atproto/follow')]
	public function unfollow(): DataResponse {
		return $this->setFollowing(false);
	}

	private function setFollowing(bool $following): DataResponse {
		$userId = $this->currentUserId();
		if ($userId === null) {
			return $this->signedOut();
		}

		$nativeChanged = false;
		$resolved = ['did' => ''];
		try {
			$handle = trim((string)$this->request->getParam('handle', ''));
			$resolved = $this->identity->resolve($handle);
			$this->engagementService->setFollowing($userId, $resolved['did'], $following);
			$nativeChanged = true;

			// Keep the existing Social relationship and shared ATProto watch in
			// step, so followed Bluesky posts enter the same home timeline.
			$actor = $this->localAccountService->getActorFromUserId($userId);
			$remote = $this->identity->actor($resolved['did'], $resolved['handle'], null, $resolved['pds']);
			if ($following) {
				$this->followService->followActor($actor, $remote);
			} else {
				$this->followService->unfollowAccount($actor, $resolved['handle']);
			}

			return new DataResponse(['following' => $following], Http::STATUS_OK);
		} catch (AtprotoException $e) {
			if ($nativeChanged) {
				try {
					$this->engagementService->setFollowing($userId, $resolved['did'], !$following);
				} catch (\Throwable $rollback) {
					$this->logger->error('could not roll back native Bluesky follow', ['exception' => $rollback]);
				}
			}
			return new DataResponse(['message' => $e->getMessage()], $e->getStatus() >= 400 ? $e->getStatus() : Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			if ($nativeChanged) {
				try {
					$this->engagementService->setFollowing($userId, $resolved['did'], !$following);
				} catch (\Throwable $rollback) {
					$this->logger->error('could not roll back native Bluesky follow', ['exception' => $rollback]);
				}
			}
			$this->logger->warning('could not change native Bluesky follow', ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * The Nextcloud account behind the reader, which is what a link is stored
	 * under — `null` when nobody is signed in, which these routes answer
	 * rather than let the settings service be asked about no one.
	 */
	private function currentUserId(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	private function signedOut(): DataResponse {
		return new DataResponse(['message' => 'no signed-in account'], Http::STATUS_UNAUTHORIZED);
	}
}
