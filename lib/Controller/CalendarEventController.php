<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\ActivityPubEvent;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as CalendarManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/** Downloads public Fediverse events and imports them into Nextcloud Calendar. */
class CalendarEventController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private ?CalendarManager $calendarManager = null,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/** Download a visible ActivityStreams Event as an interoperable calendar file. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/statuses/{nid}/calendar')]
	public function statusCalendar(int|string $nid): Response {
		try {
			$this->initViewer(false);
			$item = $this->streamService->getStreamByNid(Nid::fromStorage($nid));
			$calendar = ActivityPubEvent::calendar($item, time());
			if ($calendar === null) {
				throw new StreamNotFoundException('Event not found');
			}

			$filename = 'fediverse-event-' . substr(hash('sha256', $item->getId()), 0, 16) . '.ics';
			$response = new DataDisplayResponse($calendar, Http::STATUS_OK, [
				'Content-Type' => 'text/calendar; charset=utf-8',
				'Cache-Control' => 'private, no-store',
			]);
			$response->addHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** Import a visible ActivityStreams event into the signed-in user's calendar. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/statuses/{nid}/calendar/import')]
	public function importStatusCalendar(int|string $nid): Response {
		try {
			$this->requireWebSession();
			$user = $this->userSession->getUser();
			if ($user === null || $this->calendarManager === null) {
				return new DataResponse(['error' => 'Calendar import is unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			$this->initViewer(false);
			$item = $this->streamService->getStreamByNid(Nid::fromStorage($nid));
			$event = ActivityPubEvent::fromStream($item);
			$uid = hash('sha256', $item->getId()) . '@social';
			if ($event === null || $event['start_time'] === null || $item->getId() === '') {
				return new DataResponse(['error' => 'Event not found'], Http::STATUS_NOT_FOUND);
			}

			$principal = 'principals/users/' . $user->getUID();
			$calendars = $this->calendarManager->getCalendarsForPrincipal($principal);
			if (self::calendarContainsUid($calendars, $uid)) {
				return new DataResponse(['imported' => true, 'already_imported' => true]);
			}
			$writable = array_values(array_filter($calendars, static fn ($calendar): bool
				=> $calendar instanceof ICalendarIsWritable
				&& $calendar->isWritable()
				&& $calendar instanceof ICreateFromString
			));
			if ($writable === []) {
				return new DataResponse(['error' => 'No writable calendar is available'], Http::STATUS_CONFLICT);
			}

			$calendar = $writable[0];
			$filename = substr(hash('sha256', $item->getId()), 0, 32) . '.ics';
			$ics = ActivityPubEvent::calendar($item, time());
			if ($ics === null) {
				return new DataResponse(['error' => 'Event not found'], Http::STATUS_NOT_FOUND);
			}
			try {
				$calendar->createFromString($filename, $ics);
			} catch (Throwable $e) {
				// Two clicks can both pass the first UID lookup before either write
				// finishes. Treat the resulting duplicate-name conflict as success
				// once the winning request's UID is visible in the user's calendars.
				if (self::calendarContainsUid($calendars, $uid)) {
					return new DataResponse(['imported' => true, 'already_imported' => true]);
				}
				throw $e;
			}

			return new DataResponse(['imported' => true, 'calendar' => $calendar->getDisplayName() ?? '']);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/** @param array<array-key, mixed> $calendars */
	private static function calendarContainsUid(array $calendars, string $uid): bool {
		foreach ($calendars as $calendar) {
			if ($calendar instanceof ICalendar
				&& $calendar->search('', [], ['uid' => $uid], 1) !== []) {
				return true;
			}
		}

		return false;
	}
}
