<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use Closure;
use OCA\Social\AppInfo\Application;
use OCA\Social\Atproto\Identity\DidControlService;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Service\BlueskyArchiveService;
use OCA\Social\Atproto\Service\RecoveryToolPage;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\ClientGoneException;
use OCA\Social\Response\StreamedWriterResponse;
use OCA\Social\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a person needs to keep their Bluesky account without this server:
 * who controls its DID, the account as files to download, and the offline
 * recovery tool that moves it with the recovery phrase.
 */
class AtprotoRecoveryController extends Controller {
	use OwnAccount;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AtprotoConfig $config,
		private AccountService $accountService,
		private IdentityService $identities,
		private BlueskyArchiveService $archive,
		private DidControlService $control,
		private RecoveryToolPage $recoveryTool,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `GET /bluesky-recovery`: the offline recovery tool, one page with its
	 * script inline. Public — it holds nothing of anybody's — and allowed
	 * to ask any HTTPS server, since the person names the new one.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/bluesky-recovery')]
	public function recoveryTool(): Response {
		return $this->recoveryPage(false);
	}

	/**
	 * `GET /bluesky-recovery/download`: the same page as a file to keep,
	 * for when this server is gone.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/bluesky-recovery/download')]
	public function downloadRecoveryTool(): Response {
		return $this->recoveryPage(true);
	}

	private function recoveryPage(bool $download): Response {
		if (!$this->recoveryTool->isBuilt()) {
			return new DataResponse(['error' => 'The recovery tool is not built', 'code' => 'tool-missing'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$response = new DataDisplayResponse($this->recoveryTool->render(), Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedScriptDomain($this->recoveryTool->scriptSource());
		$policy->addAllowedConnectDomain('https:');
		$directory = $this->config->plcDirectory();
		if (!str_starts_with($directory, 'https://')) {
			$policy->addAllowedConnectDomain($directory);
		}
		$response->setContentSecurityPolicy($policy);
		if ($download) {
			$response->addHeader('Content-Disposition', 'attachment; filename="bluesky-recovery-tool.html"');
		}

		return $response;
	}

	/**
	 * `GET /api/v1/social/bluesky/control`: who controls the viewer's DID,
	 * as the PLC directory has it now — its rotation keys by role, the
	 * latest operation, and until when it can still be undone and by
	 * which keys. Read-only; the directory's answer is kept a minute.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/control')]
	public function control(): DataResponse {
		$identity = $this->ownIdentity();
		if ($identity === null || !BlueskyArchiveService::exportable($identity)) {
			return $this->withoutIdentity();
		}
		try {
			return new DataResponse($this->control->forIdentity($identity));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage(), 'code' => 'did-not-found'], Http::STATUS_NOT_FOUND);
		} catch (AtprotoException $e) {
			$this->logger->info('PLC directory not reached for the DID\'s keys', ['did' => $identity->did, 'exception' => $e]);

			return new DataResponse(['error' => 'The PLC directory could not be reached', 'code' => 'directory-unreachable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * `GET /api/v1/social/bluesky/archive`: the viewer's Bluesky account as a
	 * zip (repository, blobs, DID log and document, recovery script),
	 * streamed and never kept on disk. Browser-navigated, so no request
	 * token; the password is confirmed first and a few downloads an hour are
	 * allowed, as each reads the whole repository.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 3, period: 3600)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/social/bluesky/archive')]
	public function archive(): Response {
		$identity = $this->ownIdentity();
		if ($identity === null || !BlueskyArchiveService::exportable($identity)) {
			return $this->withoutIdentity();
		}

		return new StreamedWriterResponse(function (Closure $sink) use ($identity): void {
			try {
				$this->archive->zip($identity, $sink);
			} catch (ClientGoneException $e) {
				throw $e;
			} catch (Throwable $e) {
				// the status went out with the first bytes: the archive
				// ends without its central directory, which a zip reader
				// reports as broken
				$this->logger->error('Bluesky export failed', ['did' => $identity->did, 'exception' => $e]);
			}
		}, Http::STATUS_OK, [
			'Content-Type' => 'application/zip',
			// the name is reduced to [A-Za-z0-9._-] by filename()
			'Content-Disposition' => 'attachment; filename="' . $this->archive->filename($identity) . '"',
		]);
	}

	/**
	 * What a route answers for a viewer with no identity to export: no
	 * account here yet, or an account without one.
	 */
	private function withoutIdentity(): DataResponse {
		$answer = new DataResponse(['error' => 'No Bluesky account for this account', 'code' => 'no-identity'], Http::STATUS_NOT_FOUND);
		$user = $this->userSession->getUser();

		return ($user === null) ? $answer : $this->unlessNotSetUp($user->getUID(), $answer);
	}

	/**
	 * The viewer's identity as it is, never made, nor their account; null
	 * when Bluesky is off or there is none.
	 */
	private function ownIdentity(): ?Identity {
		if (!$this->config->isEnabled()) {
			return null;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}
		try {
			return $this->identities->forActor($this->accountService->getActorFromUserId($user->getUID()), false);
		} catch (Throwable) {
			return null;
		}
	}
}
