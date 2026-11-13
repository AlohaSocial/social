<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AlohaDirectory\DirectoryApiException;
use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\DirectoryLists;
use OCA\Social\AlohaDirectory\DirectoryParticipation;
use OCA\Social\AlohaDirectory\ProfileBuilder;
use OCA\Social\AppInfo\Application;
use OCA\Social\Service\ExternalUserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Administration → Aloha Directory: whether this server takes part, its
 * listing profile, the conversation with the curators, and the signed lists.
 *
 * No route relaxes the server's checks, so they are dispatched for an
 * administrator with a session and a CSRF token only, not for a group the
 * Aloha Social section was delegated to: enrolling the server, holding its key
 * and withdrawing it are decisions about the whole server.
 */
class AlohaDirectoryController extends Controller {
	public function __construct(
		IRequest $request,
		private DirectoryConfig $config,
		private DirectoryParticipation $participation,
		private DirectoryLists $lists,
		private ProfileBuilder $profileBuilder,
		private ExternalUserService $externalUserService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Everything the section shows. */
	#[FrontpageRoute(verb: 'GET', url: '/admin/aloha-directory')]
	public function index(): DataResponse {
		return new DataResponse($this->view());
	}

	/** The settings of concept A.5 that are not the enrolment itself. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/settings')]
	public function settings(?string $advertisedAccounts = null, ?bool $alohaList = null, ?string $listMirror = null): DataResponse {
		return $this->act(function () use ($advertisedAccounts, $alohaList, $listMirror): void {
			$settings = [];
			if ($advertisedAccounts !== null) {
				$cap = trim($advertisedAccounts);
				if ($cap !== '' && !ctype_digit($cap)) {
					throw new InvalidArgumentException('The number of advertised accounts must be a whole number');
				}
				$settings['advertisedAccounts'] = $cap === '' ? null : (int)$cap;
			}
			if ($alohaList !== null) {
				$settings['alohaList'] = $alohaList;
			}
			if ($listMirror !== null) {
				$settings['listMirror'] = $listMirror;
			}
			$this->config->saveSettings($settings);
		});
	}

	/**
	 * Saves the operator's part of the profile; an enrolled server sends the
	 * changed profile at once.
	 *
	 * @param array<string, mixed> $profile
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/profile')]
	public function profile(array $profile = []): DataResponse {
		return $this->act(function () use ($profile): void {
			$this->profileBuilder->saveForm($profile);
			$this->participation->pushProfile(false);
		});
	}

	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/enrol')]
	public function enrol(): DataResponse {
		return $this->act(fn () => $this->participation->enrol());
	}

	/** Asks the directory to check the domain again after a failed verification. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/verify')]
	public function verify(): DataResponse {
		return $this->act(fn () => $this->participation->requestVerification());
	}

	/** Asks for the outcome of the verification, or sends profile, heartbeat and reads the status. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/refresh')]
	public function refresh(): DataResponse {
		return $this->act(function (): void {
			if ($this->participation->phase() === DirectoryParticipation::PHASE_VERIFYING) {
				$this->participation->checkVerification();

				return;
			}
			$this->participation->pushProfile();
			$this->participation->sendHeartbeat();
			$this->participation->refreshStatus();
		});
	}

	/** Answers the curators. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/comments')]
	public function comment(string $body = ''): DataResponse {
		return $this->act(fn () => $this->participation->comment($body));
	}

	/** Answers a complaint the curators shared. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/complaints/{id}/responses', requirements: ['id' => '\d+'])]
	public function respondToComplaint(int $id, string $body = ''): DataResponse {
		return $this->act(fn () => $this->participation->respondToComplaint($id, $body));
	}

	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/withdraw')]
	public function withdraw(): DataResponse {
		return $this->act(fn () => $this->participation->withdraw());
	}

	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/key')]
	public function rotateKey(): DataResponse {
		return $this->act(fn () => $this->participation->rotateKey());
	}

	/** Fetches and verifies the signed lists now. */
	#[FrontpageRoute(verb: 'POST', url: '/admin/aloha-directory/lists')]
	public function refreshLists(): DataResponse {
		return $this->act(fn () => $this->lists->refresh());
	}

	/**
	 * Runs $action and answers with the whole section, or with the reason it
	 * could not be done.
	 */
	private function act(callable $action): DataResponse {
		try {
			$action();
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()] + $this->view(), Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (DirectoryApiException $e) {
			return new DataResponse([
				'error' => $e->getMessage(),
				'errorCode' => $e->getErrorCode(),
				'details' => $e->getDetails(),
			] + $this->view(), $e->isTemporary() ? Http::STATUS_BAD_GATEWAY : Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse($this->view());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function view(): array {
		$domain = $this->participation->domain();
		$profile = $this->profileBuilder->build();
		$listed = null;
		foreach ($this->lists->servers() as $entry) {
			if (($entry['domain'] ?? null) === $domain) {
				$listed = $entry;
			}
		}

		return [
			'domain' => $domain,
			'directoryUrl' => $this->config->directoryUrl(),
			'participation' => $this->participation->state(),
			'settings' => [
				'advertise' => $this->config->advertises(),
				'advertisedAccounts' => $this->config->advertisedAccounts(),
				'alohaList' => $this->config->usesAlohaList(),
				'listMirror' => $this->config->listBaseUrl() === $this->config->directoryUrl() ? '' : $this->config->listBaseUrl(),
			],
			'signup' => [
				'enabled' => $this->externalUserService->isEnabled(),
				'open' => $this->externalUserService->acceptsPublicRegistrations(),
				'mode' => $this->externalUserService->mode(),
				'freeAccounts' => $this->externalUserService->freeAccounts(),
			],
			'profile' => [
				'form' => (object)$this->profileBuilder->form(),
				'sent' => $profile,
				'missing' => $this->profileBuilder->missing($profile),
			],
			'lists' => $this->lists->summary(),
			'publishedEntry' => $listed,
		];
	}
}
