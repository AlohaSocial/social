<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\HistoryController;
use OCA\Social\Db\StatusRevisionsRequest;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\SocialClient;
use OCA\Social\Model\Client\StatusRevision;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamService;
use OCA\Social\Tools\Nid;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * `GET /api/v1/statuses/:id/history`.
 *
 * Two things are checked here that the service cannot check for itself: that
 * the status is resolved through the stream layer's visibility filter before
 * any revision is read — a status the caller may not see is a 404, not a
 * history — and that a bearer token presented with too narrow a grant is
 * refused rather than quietly downgraded to the anonymous read this route
 * otherwise allows.
 */
#[AllowMockObjectsWithoutExpectations]
class HistoryControllerTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';
	private const AUTHOR = 'https://cloud.example/users/bob';

	/** @var IRequest&Stub */
	private $request;
	private AccountService|Stub $accountService;
	private CacheActorService|Stub $cacheActorService;
	private ClientService|Stub $clientService;
	private StreamService|Stub $streamService;
	private IUserSession|MockObject $userSession;
	private StatusRevisionsRequest|MockObject $revisionsRequest;

	/** @var array<string, string> the request headers the controller will see */
	private array $headers = [];
	/** @var array<int, Note> the statuses the stream layer will hand over, by nid */
	private array $statuses = [];
	/** @var StatusRevision[] the recorded versions of the status */
	private array $stored = [];
	/** @var Person|null the viewer the stream layer was told about */
	private ?Person $streamViewer = null;
	private bool $csrf = true;
	private bool $hasSession = true;

	protected function setUp(): void {
		$this->request = $this->createStub(IRequest::class);
		$this->request->method('getId')->willReturn('test');
		$this->request->method('getHeader')
			->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$this->request->method('passesCSRFCheck')->willReturnCallback(fn (): bool => $this->csrf);
		$this->request->method('getRequestUri')->willReturn('/api/v1/statuses/1/history');
		$this->request->method('getParam')->willReturn('');
		$this->request->method('getParams')->willReturn([]);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')
			->willReturnCallback(fn (): ?IUser => $this->hasSession ? $user : null);

		$this->accountService = $this->createStub(AccountService::class);
		$this->accountService->method('getActorFromUserId')
			->willReturnCallback(fn (): Person => $this->person(self::VIEWER));

		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->cacheActorService->method('getFromLocalAccount')
			->willReturnCallback(fn (): Person => $this->person(self::VIEWER));
		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id): Person => $this->person($id));

		$this->clientService = $this->createStub(ClientService::class);

		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('setViewer')
			->willReturnCallback(function (Person $viewer): void {
				$this->streamViewer = $viewer;
			});
		$this->streamService->method('getStreamByNid')
			->willReturnCallback(function (int|string $nid): Note {
				// what the real one does with a `{nid}` that is not a decimal
				// number: Nid::normalize() refuses it
				Nid::normalize($nid);

				if (!isset($this->statuses[$nid])) {
					throw new StreamNotFoundException('Stream not found');
				}

				return $this->statuses[$nid];
			});

		$this->revisionsRequest = $this->createMock(StatusRevisionsRequest::class);
		$this->revisionsRequest->method('getByStreamId')
			->willReturnCallback(fn (): array => $this->stored);

		$this->userSession = $userSession;
	}

	private function controller(): HistoryController {
		return new HistoryController(
			$this->request,
			$this->userSession,
			new NullLogger(),
			$this->accountService,
			$this->cacheActorService,
			$this->clientService,
			$this->streamService,
			new StatusRevisionService(
				$this->revisionsRequest, $this->cacheActorService, new NullLogger()
			)
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function aStatus(int $nid, string $content, string $updated = ''): void {
		$note = new Note();
		$note->setNid($nid);
		$note->setId('https://cloud.example/posts/' . $nid);
		$note->setAttributedTo(self::AUTHOR);
		$note->setContent($content);
		$note->setPublished('2026-09-11T10:00:00Z');
		$note->setUpdated($updated);

		$this->statuses[$nid] = $note;
	}

	/** A token whose grant is exactly these scopes. */
	private function bearer(array $scopes): void {
		$client = new SocialClient();
		$client->setAuthUserId('alice');
		$client->setAuthScopes($scopes);

		$this->headers['Authorization'] = 'Bearer sometoken';
		$this->clientService->method('getFromToken')->willReturn($client);
	}

	public function testTheHistoryIsEveryVersionOldestFirst(): void {
		$this->aStatus(1, 'the third version', '2026-09-11T12:00:00Z');
		$this->stored = [
			(new StatusRevision())->setContent('the original')->setPublished('2026-09-11T10:00:00Z'),
			(new StatusRevision())->setContent('the second version')->setPublished('2026-09-11T11:00:00Z'),
			(new StatusRevision())->setContent('the third version')->setPublished('2026-09-11T12:00:00Z'),
		];

		$response = $this->controller()->history(1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertCount(3, $data);
		$this->assertSame('the original', $data[0]->jsonSerialize()['content']);
		$this->assertSame('the third version', $data[2]->jsonSerialize()['content']);
	}

	/**
	 * The first entry is the text that was posted. It is the one thing a
	 * revision history exists to say, and the reason the store keeps the
	 * version an edit replaces rather than only the version it produced.
	 */
	public function testTheFirstEntryIsNotTheCurrentText(): void {
		$this->aStatus(1, 'the current text', '2026-09-11T12:00:00Z');
		$this->stored = [
			(new StatusRevision())->setContent('the original')->setPublished('2026-09-11T10:00:00Z'),
			(new StatusRevision())->setContent('the current text')->setPublished('2026-09-11T12:00:00Z'),
		];

		$data = $this->controller()->history(1)->getData();

		$this->assertSame('the original', $data[0]->jsonSerialize()['content']);
		$this->assertNotSame(
			$data[0]->jsonSerialize()['content'], $this->statuses[1]->getContent()
		);
	}

	public function testAStatusNeverEditedHasOneVersion(): void {
		$this->aStatus(1, 'posted once');

		$data = $this->controller()->history(1)->getData();

		$this->assertCount(1, $data);
		$this->assertSame('posted once', $data[0]->jsonSerialize()['content']);
		$this->assertSame('2026-09-11T10:00:00Z', $data[0]->jsonSerialize()['created_at']);
	}

	public function testEveryVersionCarriesTheAuthorAccount(): void {
		$this->aStatus(1, 'posted once');

		$data = $this->controller()->history(1)->getData();

		$this->assertSame(self::AUTHOR, $data[0]->jsonSerialize()['account']->getId());
	}

	/**
	 * A status the stream layer refuses to hand over — deleted, or not visible
	 * to this caller — is a 404 with Mastodon's own wording, and no revision
	 * is read at all.
	 */
	public function testAStatusTheCallerMayNotReadIsNotFound(): void {
		$this->revisionsRequest->expects($this->never())->method('getByStreamId');

		$response = $this->controller()->history(404);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	/**
	 * A `{nid}` that is not a number names no post, which is the same answer
	 * as naming one that is not there. `Nid::normalize()` throws on it, and
	 * unmapped that came out as a 500 — this server saying it had broken over
	 * a request that was simply wrong.
	 *
	 * @param string $nid what the client put in the path
	 */
	#[DataProvider('idsThatAreNotNids')]
	public function testAnIdThatIsNotANumberIsNotFound(string $nid): void {
		$response = $this->controller()->history($nid);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Record not found'], $response->getData());
	}

	public static function idsThatAreNotNids(): array {
		return [
			'letters' => ['abc'],
			'empty' => [''],
			'negative' => ['-1'],
			'a decimal point' => ['1.5'],
		];
	}

	/** The viewer is given to the stream layer, which is what filters on it. */
	public function testTheViewerReachesTheStreamLayer(): void {
		$this->aStatus(1, 'posted once');

		$this->controller()->history(1);

		$this->assertNotNull($this->streamViewer);
		$this->assertSame(self::VIEWER, $this->streamViewer->getId());
	}

	/**
	 * Mastodon serves the history of a public status to anybody, and so does
	 * this app's own status route, so a caller with no credentials is answered
	 * rather than refused — the stream layer decides what they may see.
	 */
	public function testAnAnonymousCallerIsAnsweredAndNotRefused(): void {
		$this->hasSession = false;
		$this->csrf = false;
		$this->aStatus(1, 'a public post');

		$response = $this->controller()->history(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($this->streamViewer);
	}

	public function testABearerTokenWithTheGranularScopeIsAccepted(): void {
		$this->bearer(['read:statuses']);
		$this->aStatus(1, 'posted once');

		$this->assertSame(Http::STATUS_OK, $this->controller()->history(1)->getStatus());
	}

	public function testTheBroadReadScopeIsAccepted(): void {
		$this->bearer(['read']);
		$this->aStatus(1, 'posted once');

		$this->assertSame(Http::STATUS_OK, $this->controller()->history(1)->getStatus());
	}

	/**
	 * A token granted another granular variant of the same parent has not been
	 * granted this one, and must not be silently answered as an anonymous
	 * caller either: that would turn a refusal into a partial success.
	 */
	public function testASiblingGranularScopeIsRefused(): void {
		$this->bearer(['read:lists']);
		$this->aStatus(1, 'posted once');

		$response = $this->controller()->history(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('read:statuses', $response->getData()['error']);
	}

	/** A write grant is not a read grant. */
	public function testAWriteScopeIsRefused(): void {
		$this->bearer(['write']);
		$this->aStatus(1, 'posted once');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->history(1)->getStatus());
	}

	/**
	 * An unrecognised failure is a bug on this side: 500, and its message is
	 * not echoed on a public route.
	 */
	public function testAnUnexpectedFailureDoesNotLeakItsMessage(): void {
		$this->streamService = $this->createStub(StreamService::class);
		$this->streamService->method('getStreamByNid')
			->willThrowException(new RuntimeException('connection to 10.0.0.4 refused'));

		$response = $this->controller()->history(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'internal server error'], $response->getData());
	}
}
