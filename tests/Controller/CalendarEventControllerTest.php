<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\AP;
use OCA\Social\Controller\CalendarEventController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Details;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as CalendarManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class CalendarEventControllerTest extends TestCase {
	private IRequest|Stub $request;
	private IUserSession|Stub $userSession;
	private StreamService|Stub $streams;
	private AccountService|Stub $accounts;
	private CacheActorService|Stub $cacheActors;
	private LoggerInterface|MockObject $logger;
	private Note $event;
	private string $authorization = '';

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('calendar-test');
		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string
			=> $name === 'Authorization' ? $this->authorization : '');
		$this->request->method('passesCSRFCheck')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		\OC::$server->register(IRequest::class, $this->request);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createStub(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$actor = (new Person())->setId('https://cloud.example/users/alice')->setUserId('alice')->setPreferredUsername('alice');
		$this->accounts = $this->createStub(AccountService::class);
		$this->accounts->method('getActorFromUserId')->willReturn($actor);
		$this->cacheActors = $this->createStub(CacheActorService::class);
		$this->cacheActors->method('getFromLocalAccount')->willReturn($actor);

		$this->event = (new Note())
			->setId('https://events.example/event/123')
			->setUrl('https://events.example/event/123')
			->setSubType('Event')
			->setContent('<p>Community gathering</p>');
		$this->event->setDetailArray(Details::EVENT, [
			'name' => 'Community gathering',
			'start_time' => '2026-11-02T18:00:00Z',
			'end_time' => '2026-11-02T20:00:00Z',
			'location' => 'Library',
		]);
		$this->streams = $this->createStub(StreamService::class);
		$this->streams->method('getStreamByNid')->willReturnCallback(fn (): Note => $this->event);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('error')->willReturnCallback(static function (string $message, array $context): void {
			if (($context['exception'] ?? null) instanceof \Throwable) {
				throw $context['exception'];
			}
		});
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testImportsVisibleEventIntoFirstWritableCalendarWithStableUid(): void {
		$calendar = $this->writableCalendar();
		$calendar->expects(self::once())->method('search')->with('', [], ['uid' => $this->uid()], 1)->willReturn([]);
		$calendar->expects(self::once())->method('createFromString')->with(
			substr(hash('sha256', $this->event->getId()), 0, 32) . '.ics',
			self::callback(static fn (string $ics): bool
				=> str_contains($ics, 'UID:' . hash('sha256', 'https://events.example/event/123') . '@social')
					&& str_contains($ics, 'SUMMARY:Community gathering')),
		);
		$manager = $this->calendarManager([$calendar]);

		$response = $this->controller($manager)->importStatusCalendar('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()) ?: '');
		self::assertSame(['imported' => true, 'calendar' => 'Personal'], $response->getData(), json_encode($response->getData()) ?: '');
	}

	public function testImportsAParsedGancioEventIntoNextcloudCalendar(): void {
		$activityData = json_decode(
			file_get_contents(__DIR__ . '/../Federation/fixtures/gancio-create-event.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$activity = AP::instance()->getItemFromData($activityData);
		self::assertSame('Create', $activity->getType());
		$this->event = $activity->getObject();

		$calendar = $this->writableCalendar();
		$calendar->expects(self::once())->method('search')->with('', [], ['uid' => $this->uid()], 1)->willReturn([]);
		$calendar->expects(self::once())->method('createFromString')->with(
			substr(hash('sha256', $this->event->getId()), 0, 32) . '.ics',
			self::callback(static fn (string $ics): bool
				=> str_contains($ics, 'UID:' . hash('sha256', 'https://gancio.example/events/42') . '@social')
					&& str_contains($ics, 'SUMMARY:Community assembly')
					&& str_contains($ics, 'DTSTART:20261114T170000Z')
					&& str_contains($ics, 'DTEND:20261114T190000Z')
					&& str_contains($ics, 'LOCATION:Community Hall\\, Main Street 12\\, Berlin')),
		);

		$response = $this->controller($this->calendarManager([$calendar]))->importStatusCalendar('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()) ?: '');
		self::assertSame(['imported' => true, 'calendar' => 'Personal'], $response->getData());
	}

	public function testAnAlreadyImportedEventIsNotWrittenAgain(): void {
		$calendar = $this->writableCalendar();
		$calendar->expects(self::once())->method('search')->with('', [], ['uid' => $this->uid()], 1)->willReturn([['uid' => $this->uid()]]);
		$calendar->expects(self::never())->method('createFromString');

		$response = $this->controller($this->calendarManager([$calendar]))->importStatusCalendar('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['imported' => true, 'already_imported' => true], $response->getData());
	}

	public function testAnEventInAReadOnlyCalendarIsNotImportedAgain(): void {
		$readOnlyCalendar = $this->createMock(ICalendar::class);
		$readOnlyCalendar->expects(self::once())->method('search')->with('', [], ['uid' => $this->uid()], 1)->willReturn([['uid' => $this->uid()]]);
		$writableCalendar = $this->writableCalendar();
		$writableCalendar->expects(self::never())->method('createFromString');
		$manager = $this->calendarManager([$readOnlyCalendar, $writableCalendar]);

		$response = $this->controller($manager)->importStatusCalendar('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['imported' => true, 'already_imported' => true], $response->getData());
	}

	public function testImportExplainsWhenNoWritableCalendarIsAvailable(): void {
		$manager = $this->calendarManager([]);

		$response = $this->controller($manager)->importStatusCalendar('123');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('No writable calendar is available', $response->getData()['error']);
	}

	public function testImportRefusesBearerTokensEvenWhenTheyHaveWriteScope(): void {
		$this->authorization = 'Bearer api-token';
		$manager = $this->createMock(CalendarManager::class);
		$manager->expects(self::never())->method('getCalendarsForPrincipal');

		$response = $this->controller($manager)->importStatusCalendar('123');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCalendarDownloadUsesTheVisibilityGatedEventAndCalendarContentType(): void {
		$response = $this->controller()->statusCalendar('123');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertStringStartsWith('BEGIN:VCALENDAR', $response->getData());
		self::assertSame('text/calendar; charset=utf-8', $response->getHeaders()['Content-Type']);
		self::assertSame(
			'attachment; filename="fediverse-event-' . substr(hash('sha256', $this->event->getId()), 0, 16) . '.ics"',
			$response->getHeaders()['Content-Disposition'],
		);
	}

	private function controller(?CalendarManager $calendarManager = null): CalendarEventController {
		return new CalendarEventController(
			$this->request,
			$this->createStub(IURLGenerator::class),
			$this->userSession,
			$this->logger,
			$this->createStub(ClientService::class),
			$this->accounts,
			$this->cacheActors,
			$this->streams,
			$this->createStub(FollowService::class),
			$calendarManager,
		);
	}

	private function calendarManager(array $calendars): CalendarManager {
		$manager = $this->createMock(CalendarManager::class);
		$manager->expects(self::once())->method('getCalendarsForPrincipal')->with('principals/users/alice')->willReturn($calendars);

		return $manager;
	}

	private function writableCalendar(): ICreateFromString&ICalendarIsWritable {
		$calendar = $this->createMockForIntersectionOfInterfaces([ICreateFromString::class, ICalendarIsWritable::class]);
		$calendar->method('getDisplayName')->willReturn('Personal');
		$calendar->method('isWritable')->willReturn(true);

		return $calendar;
	}

	private function uid(): string {
		return hash('sha256', $this->event->getId()) . '@social';
	}
}
