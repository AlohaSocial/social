<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Client\AppPasswordService;
use OCA\Social\Atproto\Identity\CustomHandleService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\Mnemonic;
use OCA\Social\Atproto\Identity\PresenceService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\Move;
use OCA\Social\Atproto\Moderation\LabelerService;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Atproto\Move\InboundMoveService;
use OCA\Social\Atproto\Move\MoveAwayService;
use OCA\Social\Atproto\Move\MoveFailure;
use OCA\Social\Atproto\Move\MoveInService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Atproto\Publisher\BlueskyBlocks;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AccountNotSetUpException;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\AtprotoKeysSealedException;
use OCA\Social\Exceptions\MoveErrorException;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A person's own Bluesky identity, for Settings → Your account: the handle
 * and DID, whether the person is on Bluesky at all, and the recovery phrase.
 */
class AtprotoAccountController extends Controller {
	use OwnAccount;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoConfig $config,
		private AccountService $accountService,
		private IdentityService $identities,
		private Publisher $publisher,
		private LabelerService $labelers,
		private AppPasswordService $appPasswords,
		private AuthorizationServer $oauth,
		private CustomHandleService $customHandles,
		private MoveAwayService $moveAway,
		private MoveInService $moveIn,
		private InboundMoveService $inbound,
		private BridgyTwin $bridgy,
		private BlueskyBlocks $blocks,
		private PresenceService $presence,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /api/v1/social/bluesky/identity`: the viewer's identity, made
	 * now if their account has none yet.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/identity')]
	public function identity(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->ownAccountOf($this->userId());
			$identity = $this->identities->forActor($actor);
			if ($identity === null) {
				return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
			}
			$this->publisher->publishProfile($actor);

			return new DataResponse(self::export($identity) + ['publish_blocks' => $this->blocks->isPublished($this->userId())]);
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * `POST /api/v1/social/bluesky/state`: switches the viewer's own presence
	 * on Bluesky off or on (§4.6). Off, the account is deactivated there and
	 * every Bluesky app is signed out of it; on, it is served as it was left,
	 * with the profile as it is now. The DID and the handle stay theirs.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/state')]
	public function state(bool $active): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->ownAccountOf($this->userId());
			$identity = $active ? $this->presence->switchOn($actor) : $this->presence->switchOff($actor);
			if ($active) {
				$this->publisher->publishProfile($actor);
			}

			return new DataResponse(self::export($identity) + ['publish_blocks' => $this->blocks->isPublished($this->userId())]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Whether the viewer's blocks of Bluesky accounts are published to
	 * Bluesky, where a block is public: on, the ones they hold are published
	 * now; off, every published one is withdrawn.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/publish-blocks')]
	public function publishBlocks(bool $publish): DataResponse {
		if ($this->ownIdentity() === null) {
			return $this->withoutIdentity();
		}
		try {
			$this->blocks->setPublished($this->accountService->getActorFromUserId($this->userId()), $publish);

			return new DataResponse(['publish_blocks' => $this->blocks->isPublished($this->userId())]);
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The viewer's Bluesky labelers, Bluesky's own first, each with its
	 * label values and the viewer's setting for each.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/labelers')]
	public function labelers(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * Subscribes the viewer to a labeler, by handle or DID.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/labelers')]
	public function subscribeLabeler(string $labeler): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$this->labelers->subscribe($this->userId(), $labeler);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * Unsubscribes the viewer from a labeler; Bluesky's own stays.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/labelers')]
	public function unsubscribeLabeler(string $did): DataResponse {
		$this->labelers->unsubscribe($this->userId(), $did);

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * What one label of a subscribed labeler does for the viewer.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/social/bluesky/labelers/setting')]
	public function labelerSetting(string $did, string $label, string $setting): DataResponse {
		try {
			$this->labelers->setSetting($this->userId(), $did, $label, $setting);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['labelers' => $this->labelers->forUser($this->userId())]);
	}

	/**
	 * The viewer's app passwords for Bluesky apps, by name; never the
	 * passwords themselves.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/app-passwords')]
	public function appPasswords(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * A new app password: the answer is the only time it is seen.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/app-passwords')]
	public function createAppPassword(string $name, bool $privileged = false): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$created = $this->appPasswords->create($this->userId(), $name, $privileged);
		} catch (MoveErrorException $e) {
			return $this->moveError($e);
		}

		return new DataResponse($created + ['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * Revokes an app password, and every Bluesky app signed in with it.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/app-passwords/{id}')]
	public function revokeAppPassword(int $id): DataResponse {
		$this->appPasswords->revoke($this->userId(), $id);

		return new DataResponse(['app_passwords' => $this->appPasswords->list($this->userId())]);
	}

	/**
	 * A handle on a domain the viewer owns, once the domain names their DID.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/handle')]
	public function setHandle(string $handle): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return $this->withoutIdentity();
		}
		try {
			$changed = $this->customHandles->set($identity, $handle);
			$this->handleChanged();

			return new DataResponse(self::export($changed));
		} catch (MoveErrorException $e) {
			return $this->moveError($e);
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The handle this server assigned, again.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/handle')]
	public function clearHandle(): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return $this->withoutIdentity();
		}
		try {
			$changed = $this->customHandles->clear($identity);
			$this->handleChanged();

			return new DataResponse(self::export($changed));
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The viewer's latest move of their Bluesky account, or null, who
	 * controls the account once it moved away (MoveAwayService::control()),
	 * and how many of their posts are on Bluesky now.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/move')]
	public function move(): DataResponse {
		if (!$this->config->isEnabled()) {
			return $this->blueskyOff();
		}

		$move = $this->inbound->latest($this->userId());
		$export = $move?->export();
		if ($move !== null && $export !== null && $move->direction === Move::AWAY) {
			$export['progress']['stage'] ??= MoveAwayService::stageOf($move);
		}

		return new DataResponse([
			'move' => $export,
			// what a move here would take off Bluesky, for the confirmation
			'posts_on_bluesky' => $this->moveIn->postsOnBluesky($this->userId()),
		] + MoveAwayService::control($this->ownIdentity(), $move));
	}

	/**
	 * Moves the viewer's Bluesky account to another PDS: the account is made
	 * there now, the rest follows in the background. The other server is
	 * waited for in the request, so a few tries an hour per account. Without
	 * a recovery key the person must send `without_recovery`. `announce`
	 * says where the account went: a `post`, a `profile` field, or `none`.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move')]
	public function moveAway(string $pds, string $handle, string $email, #[\SensitiveParameter] string $password, string $inviteCode = '', bool $without_recovery = false, string $announce = 'none'): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null) {
			return $this->noIdentity();
		}
		try {
			return new DataResponse(['move' => $this->moveAway->start($this->userId(), $identity, $pds, $handle, $email, $password, $inviteCode, $without_recovery, $announce)->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Calls off a move away before the DID moved. The answer says that the
	 * account made on the other server is left there, deactivated, for the
	 * person to delete there (`target-account-left`, with the host).
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/move')]
	public function cancelMoveAway(): DataResponse {
		try {
			$move = $this->moveAway->cancel($this->userId());

			return new DataResponse(['move' => $move->export(), 'code' => 'target-account-left', 'host' => (string)parse_url($move->pds, PHP_URL_HOST)]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Goes on with a move away that stopped for the viewer to decide (photos
	 * or videos did not arrive, or the repository there differs):
	 * `without_missing` switches without them, else they are copied again.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move/continue')]
	public function continueMove(bool $without_missing = false): DataResponse {
		try {
			return new DataResponse(['move' => $this->moveAway->proceed($this->userId(), $without_missing)->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * What a PDS the viewer may move to says of itself, for the form: the
	 * handle domains it gives out, whether it needs an invite code, its
	 * DID. Each call reaches out to the server typed, so a few an hour.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/move/target')]
	public function moveTarget(string $pds = ''): DataResponse {
		if ($this->ownIdentity() === null) {
			return $this->noIdentity();
		}
		try {
			return new DataResponse($this->moveAway->target($pds));
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Starts a failed move again, from where it stopped.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move/retry')]
	public function retryMove(): DataResponse {
		try {
			$latest = $this->moveAway->latest($this->userId());
			$move = $latest !== null && $latest->direction === Move::IN
				? $this->moveIn->retry($latest)
				: $this->moveAway->retry($this->userId());

			return new DataResponse(['move' => $move->export()]);
		} catch (\InvalidArgumentException $e) {
			return $this->moveError(new MoveErrorException($e->getMessage(), 'move-refused', $e));
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Moves a Bluesky account here: the viewer signs in to it with its own
	 * password, which is not kept, and the copy starts in the background.
	 * Their Bluesky account here so far is replaced once the move is done.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-in')]
	public function moveIn(string $handle, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $authFactorToken = ''): DataResponse {
		if (!$this->config->isEnabled()) {
			return $this->blueskyOff();
		}
		try {
			return new DataResponse(['move' => $this->moveIn->start($this->userId(), $handle, $password, $authFactorToken)->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Calls off the viewer's move here while the DID has not switched: the
	 * session on the old server and what was copied here are dropped.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/move-in')]
	public function cancelMoveIn(): DataResponse {
		try {
			return new DataResponse(['move' => $this->moveIn->cancel($this->userId())->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Asks the old Bluesky server again to e-mail the code a waiting move
	 * needs; `sent_to` is the address it went to, '' when the old server
	 * does not say.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-in/code')]
	public function newMoveInCode(): DataResponse {
		try {
			$sent = $this->moveIn->newCode($this->userId());

			return new DataResponse(['move' => $sent['move']->export(), 'sent_to' => $sent['sent_to']]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * The viewer's decision on a move here that stopped because photos or
	 * videos are missing: go on without them, or copy them again first.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-in/continue')]
	public function continueMoveIn(bool $without_missing = false): DataResponse {
		try {
			$latest = $this->inbound->latest($this->userId());
			$move = match ($latest?->direction) {
				Move::IN => $this->moveIn->decide($latest, $without_missing),
				Move::INBOUND => $this->inbound->decide($latest, $without_missing),
				default => throw new MoveErrorException('The move does not wait for a decision', 'no-decision-pending'),
			};

			return new DataResponse(['move' => $move->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Publishes the posts a finished move here took off Bluesky again,
	 * into the moved repository, in the background.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-in/republish')]
	public function republishMovedPosts(): DataResponse {
		try {
			return new DataResponse(['move' => $this->moveIn->republish($this->userId())->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * The code the old Bluesky server e-mailed: the move here goes on.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move/code')]
	public function moveCode(#[\SensitiveParameter] string $code): DataResponse {
		try {
			return new DataResponse(['move' => $this->moveIn->code($this->userId(), $code)->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * The Bluesky account Bridgy Fed made for the viewer's Fediverse account,
	 * or null. Asked to search, Bluesky's search is given the viewer's
	 * Fediverse address, for a twin they gave a domain of their own.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/bridgy-twin')]
	public function bridgyTwin(bool $search = false): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		try {
			$actor = $this->ownAccountOf($this->userId());
		} catch (AccountNotSetUpException $e) {
			return self::notSetUp($e);
		} catch (Throwable) {
			return new DataResponse(['twin' => null]);
		}

		return new DataResponse(['twin' => $this->bridgy->find($actor, $search)]);
	}

	/**
	 * Invites a Bluesky account to move here, driven by the other side: a
	 * Bridgy Fed twin is asked to by Bridgy, for anything else the answer
	 * carries what a migration tool needs, the one-time code once.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 5, period: 3600)]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/move-invite')]
	public function moveInvite(string $account): DataResponse {
		if (!$this->config->isEnabled()) {
			return $this->blueskyOff();
		}
		try {
			$invited = $this->inbound->invite($this->userId(), $account);

			return new DataResponse(['move' => $invited['move']->export()] + $invited);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * Calls off a move here the other side has not finished.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/move-invite')]
	public function cancelMoveInvite(): DataResponse {
		try {
			return new DataResponse(['move' => $this->inbound->cancel($this->userId())->export()]);
		} catch (Throwable $e) {
			return $this->moveError($e);
		}
	}

	/**
	 * The Bluesky apps the viewer signed in to through OAuth.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/oauth-sessions')]
	public function oauthSessions(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['sessions' => $this->oauth->sessionsOf($this->userId())]);
	}

	/**
	 * Signs one of those apps out.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/social/bluesky/oauth-sessions/{id}')]
	public function endOAuthSession(int $id): DataResponse {
		$this->oauth->endSession($this->userId(), $id);

		return new DataResponse(['sessions' => $this->oauth->sessionsOf($this->userId())]);
	}

	/**
	 * `POST /api/v1/social/bluesky/recovery`: issues a recovery phrase —
	 * the first, or a new one that replaces the last — and the key it
	 * stands for in the forms other atproto tools take (`key`: `did_key`,
	 * `multibase`, `hex`). Shown once, never stored, so the password is
	 * asked for first.
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/social/bluesky/recovery')]
	public function recovery(): DataResponse {
		if (!$this->config->isEnabled()) {
			return new DataResponse(['error' => 'Bluesky is not enabled on this server'], Http::STATUS_NOT_FOUND);
		}
		try {
			$actor = $this->ownAccountOf($this->userId());
			$identity = $this->identities->forActor($actor);
			if ($identity === null || !$identity->isActive()) {
				return new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND);
			}
			$phrase = $this->identities->issueRecoveryKey($identity);

			return new DataResponse(['phrase' => $phrase, 'key' => Mnemonic::keyForms($phrase)] + self::export($this->identities->getByDid($identity->did)));
		} catch (Throwable $e) {
			return $this->moveError($e, Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * The identity as the client sees it.
	 */
	public static function export(Identity $identity): array {
		return [
			'handle' => $identity->handle,
			'did' => $identity->did,
			'url' => 'https://bsky.app/profile/' . $identity->handle,
			'state' => $identity->state,
			// false while the person has switched their presence on Bluesky off
			'active' => $identity->isActive(),
			'recovery_key' => $identity->recoveryPublic !== '',
			// the handle this server gave, which keeps resolving, and the one
			// on the person's own domain when they set one
			'assigned_handle' => $identity->assignedHandle(),
			'custom_handle' => $identity->customHandle,
			'custom_handle_broken' => $identity->customHandleBroken(),
		];
	}

	private function blueskyOff(): DataResponse {
		return $this->moveError(new MoveErrorException('Bluesky is not enabled on this server', 'bluesky-off'), Http::STATUS_NOT_FOUND);
	}

	/**
	 * The profile published again, off the request, after the handle
	 * changed: a verification of the account goes out again with it, as one
	 * naming the old handle no longer counts.
	 */
	private function handleChanged(): void {
		try {
			$this->accountService->queueBlueskyProfile($this->accountService->getActorFromUserId($this->userId()));
		} catch (Throwable) {
		}
	}

	/**
	 * An error of a Bluesky route as the page reads it: the sentence and its
	 * code, 422 for what the person can do something about, 500 otherwise,
	 * and 503 `keys-sealed` for a key of this server that does not open.
	 * Anything but the app's own move and Bluesky errors is logged and
	 * answered `server-error` with a fixed sentence: its message is the
	 * server's, not the person's.
	 */
	private function moveError(Throwable $e, int $status = Http::STATUS_UNPROCESSABLE_ENTITY): DataResponse {
		if ($e instanceof AtprotoKeysSealedException) {
			return new DataResponse(['error' => $e->getMessage(), 'code' => AtprotoKeysSealedException::ERROR_CODE], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		if ($e instanceof AccountNotSetUpException) {
			return self::notSetUp($e);
		}
		if ($e instanceof MoveErrorException) {
			return new DataResponse($e->toArray(), $status);
		}
		if ($e instanceof MoveFailure) {
			return new DataResponse(['error' => $e->getMessage(), 'code' => $e->errorCode], $status);
		}
		if ($e instanceof AtprotoException) {
			return new DataResponse(['error' => $e->getMessage(), 'code' => MoveAwayService::codeOf($e)], $status);
		}

		$this->logger->error('Bluesky account route failed', ['exception' => $e]);

		return new DataResponse(['error' => 'Something went wrong on this server', 'code' => 'server-error'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}

	private function noIdentity(): DataResponse {
		return $this->unlessNotSetUp($this->userId(), $this->moveError(new MoveErrorException('No Bluesky identity for this account', 'no-identity'), Http::STATUS_NOT_FOUND));
	}

	/**
	 * What a route that needs an identity answers without one: the viewer
	 * has no account yet, or their account has no identity.
	 */
	private function withoutIdentity(): DataResponse {
		return $this->unlessNotSetUp($this->userId(), new DataResponse(['error' => 'No Bluesky identity for this account'], Http::STATUS_NOT_FOUND));
	}

	/**
	 * The viewer's identity, as it is; null when Bluesky is off, they have no
	 * account here or it has no identity.
	 */
	private function ownIdentity(): ?Identity {
		if (!$this->config->isEnabled()) {
			return null;
		}
		try {
			return $this->identities->forActor($this->accountService->getActorFromUserId($this->userId()), false);
		} catch (Throwable) {
			return null;
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('Not signed in');
		}

		return $user->getUID();
	}
}
