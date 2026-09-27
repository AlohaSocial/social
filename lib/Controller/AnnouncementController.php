<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\AnnouncementService;
use OCA\Social\Service\ClientService;
use OCA\Social\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mastodon's announcements: the instance-wide notice an admin posts and every
 * account is shown once.
 *
 * Two surfaces, one subsystem, and they are not authenticated the same way.
 *
 * The two client routes are `#[PublicPage]` with `#[NoCSRFRequired]`, like
 * `ApiController` and `ListController`, and for the same reason: a Mastodon
 * client authenticates with a bearer token and has no Nextcloud session or
 * CSRF token to present, so `#[NoAdminRequired]` would refuse every real
 * caller before the handler ran. Both then require a viewer themselves — no
 * token, no session, 401 — so nothing is public in fact.
 *
 * The three `admin*` routes carry no attribute at all, which in Nextcloud
 * means an admin session and a CSRF token, as every route of
 * `ModerationController` does. They are the administration page's, never part
 * of the client API, and they are here rather than in that controller because
 * they read and write the same table as the routes above them: an admin
 * surface that drifted from the client entity is how an announcement gets
 * posted that nobody is served.
 */
class AnnouncementController extends ClientApiController {

	public function __construct(
		IRequest $request,
		IUserSession $userSession,
		LoggerInterface $logger,
		AccountService $accountService,
		ClientService $clientService,
		private AnnouncementService $announcementService,
	) {
		parent::__construct($request, $userSession, $logger, $accountService, $clientService);
	}

	/**
	 * The announcements that apply now, each carrying whether the viewer has
	 * read it.
	 *
	 * Unpaged, as Mastodon's is, and a dismissed announcement is still in it:
	 * `read` is what changed, not what is served. Mastodon asks for no
	 * particular scope here — any user token may read what the instance is
	 * telling everybody — and neither does this.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/announcements')]
	public function index(): DataResponse {
		try {
			$this->initViewer([]);

			return new DataResponse(
				$this->announcementService->active($this->viewer->getId()), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Marks one announcement read for the viewer, and answers `{}` as Mastodon
	 * does. `write:accounts` is the scope Mastodon documents for it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/announcements/{id}/dismiss', requirements: ['id' => '\\d+'])]
	public function dismiss(int $id): DataResponse {
		try {
			$this->initViewer(['write:accounts']);
			$this->announcementService->dismiss($id, $this->viewer->getId());

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Puts an emoji on an announcement, for the viewer, and answers `{}` as
	 * Mastodon does.
	 *
	 * The only thing an account can say back about an instance-wide notice.
	 * Until this route existed, the only thing anybody could do with one was
	 * put it away, and `Announcement.reactions` was always `[]`.
	 *
	 * The emoji is in the path, as it is on Mastodon, so it arrives
	 * percent-encoded and the router has already decoded it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/announcements/{id}/reactions/{name}', requirements: ['id' => '\\d+', 'name' => '.+'])]
	public function react(int $id, string $name): DataResponse {
		try {
			$this->initViewer(['write:favourites']);
			$this->announcementService->react($id, $this->viewer->getId(), $name);

			return new DataResponse([], Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Takes one back. Taking back one that was never there answers `{}` too:
	 * a client that has lost track of what it sent should not be told the
	 * announcement does not exist.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/announcements/{id}/reactions/{name}', requirements: ['id' => '\\d+', 'name' => '.+'])]
	public function unreact(int $id, string $name): DataResponse {
		try {
			$this->initViewer(['write:favourites']);
			$this->announcementService->unreact($id, $this->viewer->getId(), $name);

			return new DataResponse([], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Every announcement there is, for the administration page. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'GET', url: '/admin/announcements')]
	public function adminIndex(): DataResponse {
		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}

	/**
	 * Posts one, and answers with the whole list so the page redraws from what
	 * is stored rather than from what it hoped was stored.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'POST', url: '/admin/announcements')]
	public function adminCreate(
		string $text = '',
		string $starts_at = '',
		string $ends_at = '',
		bool $all_day = false,
	): DataResponse {
		try {
			$this->announcementService->create($text, $starts_at, $ends_at, $all_day);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}

	/** Removes one, with every dismissal of it, and answers with the rest. */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/announcements/{id}', requirements: ['id' => '\\d+'])]
	public function adminDelete(int $id): DataResponse {
		try {
			$this->announcementService->delete($id);
		} catch (ItemNotFoundException $e) {
			return new DataResponse(['error' => 'announcement not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['announcements' => $this->announcementService->adminList()]);
	}
}
