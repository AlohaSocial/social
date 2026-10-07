<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use InvalidArgumentException;
use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Service\NotificationPolicyService;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\ServerSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * The Server card of the Social settings page.
 *
 * Administrators only, and not by accident: this controller carries none of
 * the relaxing attributes, so the server dispatches it for an administrator
 * with a session and a CSRF token and for nobody else. In particular a group
 * the Social section was delegated to — a moderator — passes
 * `ModerationController` and not this. Whether a peer's unsigned fetches are
 * answered, or how large an upload may be, is a decision about the server,
 * not about a report.
 */
class ServerSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private ServerSettingsService $serverSettingsService,
		private RelayService $relayService,
		private NotificationPolicyService $notificationPolicyService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Writes every setting on the card, or none of them.
	 *
	 * @param string $contactEmail empty, or an address
	 * @param string $contactAccount the preferred username of a local Social account, or empty to clear
	 * @param int $maxSize megabytes an attachment may have
	 * @param int $maxVideoSize megabytes a video may have
	 * @param int $imageMaxEdge longest edge a stored picture may have; 0 keeps every upload as it arrived
	 * @param int $imageQuality what a re-encoded picture is stored at, when the above is set
	 * @param bool $videoTranscode re-encode stored videos to H.264 MP4 in the background
	 * @param int $videoMaxHeight the tallest a converted video is written
	 * @param bool $videoLadder also write each video at a ladder of smaller sizes, as HLS
	 * @param string $videoLadderHeights which sizes, comma-separated
	 * @param int $videoQuota megabytes of video one account may keep here; 0 is no quota
	 * @param string $nsfwPolicy show_all, default or hide_all — what a reader who has not chosen gets
	 * @param int $inboxThrottle inbox requests per origin host per minute; 0 disables
	 * @param bool $secureMode refuse ActivityPub fetches that are not signed
	 * @param bool $publishBlocks publish the domain deny list on the instance API
	 * @param bool $allowSelfSigned accept peers with certificates that do not check out
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/server')]
	public function save(
		string $contactEmail = '',
		string $extendedDescription = '',
		int $maxSize = 10,
		int $maxVideoSize = 2048,
		int $imageMaxEdge = 0,
		int $imageQuality = 85,
		bool $videoTranscode = false,
		int $videoMaxHeight = 1080,
		bool $videoLadder = false,
		string $videoLadderHeights = '360,720,1080',
		int $videoQuota = 0,
		string $nsfwPolicy = 'default',
		int $inboxThrottle = 300,
		bool $secureMode = false,
		bool $publishBlocks = false,
		bool $allowSelfSigned = false,
		string $contactAccount = '',
	): DataResponse {
		try {
			return new DataResponse($this->serverSettingsService->save(
				$contactEmail,
				$extendedDescription,
				$maxSize,
				$maxVideoSize,
				$imageMaxEdge,
				$imageQuality,
				$videoTranscode,
				$videoMaxHeight,
				$videoLadder,
				$videoLadderHeights,
				$videoQuota,
				$nsfwPolicy,
				$inboxThrottle,
				$secureMode,
				$publishBlocks,
				$allowSelfSigned,
				$contactAccount,
			));
		} catch (InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * Who may reach an account created from now on: the notification policy
	 * every new local account is given, five keys each `accept` or `filter`.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/admin/notification-policy')]
	public function notificationPolicy(): DataResponse {
		return new DataResponse($this->notificationPolicyService->defaults()->getDecisions(), Http::STATUS_OK);
	}

	/**
	 * Changes it. Any subset of the five keys may be sent; one with a value
	 * other than `accept` or `filter` refuses the whole change with a 422 and
	 * writes nothing. Accounts that exist already keep their policy.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/notification-policy')]
	public function notificationPolicyUpdate(): DataResponse {
		try {
			return new DataResponse(
				$this->notificationPolicyService->saveDefaults($this->request->getParams())->getDecisions(),
				Http::STATUS_OK
			);
		} catch (InvalidResourceException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/**
	 * The relays this instance subscribes to.
	 *
	 * A decision about the server rather than about a report, so it sits here
	 * with the rest of the Server card and not in `ModerationController`: a
	 * relay changes what everybody's federated timeline holds and where every
	 * public post written here is sent.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/admin/relays')]
	public function relays(): DataResponse {
		return new DataResponse($this->relayService->all(), Http::STATUS_OK);
	}

	/**
	 * Subscribes to one, by the address of its actor.
	 *
	 * Answers with the row as it now stands, which will say `pending`: the
	 * `Follow` has gone out and the relay answers in its own time, usually
	 * seconds and sometimes when a human has looked at it.
	 */
	#[FrontpageRoute(verb: 'POST', url: '/admin/relays')]
	public function relaySubscribe(string $address = ''): DataResponse {
		try {
			return new DataResponse($this->relayService->subscribe($address), Http::STATUS_OK);
		} catch (InvalidResourceException $e) {
			// the message says what is wrong with the address, which is the
			// whole of the help there is
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** Unsubscribes and forgets it. */
	#[FrontpageRoute(verb: 'DELETE', url: '/admin/relays/{id}')]
	public function relayUnsubscribe(int $id): DataResponse {
		if (!$this->relayService->unsubscribe($id)) {
			return new DataResponse(['error' => 'no such relay'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse([], Http::STATUS_OK);
	}
}
