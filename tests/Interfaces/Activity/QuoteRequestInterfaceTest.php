<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\QuoteGrant;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

/**
 * FEP-044f approval, both ways: answering somebody's QuoteRequest for one of
 * our posts, and recording their answer to ours.
 */
#[AllowMockObjectsWithoutExpectations]
class QuoteRequestInterfaceTest extends ActivityPubTestCase {
	private const ALICE = self::LOCAL_URL . '/users/alice';
	private const BOB = self::REMOTE_URL . '/users/bob';
	/** alice's post, here */
	private const LOCAL_POST = self::LOCAL_URL . '/notes/1';
	/** bob's post, quoting it */
	private const REMOTE_QUOTING = self::REMOTE_URL . '/notes/2';

	private StreamRequest|MockObject $streamRequest;
	private CacheActorService|Stub $cacheActorService;
	private ActivityService|MockObject $activityService;
	private QuoteGrantRequest|MockObject $quoteGrantRequest;
	private FollowsRequest|Stub $followsRequest;
	private QuoteRequestInterface $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);
		$this->quoteGrantRequest = $this->createMock(QuoteGrantRequest::class);
		$this->followsRequest = $this->createStub(FollowsRequest::class);
		$this->followsRequest->method('getByPersons')
			->willThrowException(new FollowNotFoundException());
		$this->handler = new QuoteRequestInterface(
			$this->streamRequest,
			$this->cacheActorService,
			$this->activityService,
			$this->quoteGrantRequest,
			$this->followsRequest,
			new NullLogger()
		);

		$this->cacheActorService->method('getFromId')
			->willReturnCallback(fn (string $id) => $this->person($id, $id === self::ALICE));
	}

	/** A local post of alice's, as this instance stores it. */
	private function localPost(string $visibility = Stream::TYPE_PUBLIC): Note {
		$note = new Note();
		$note->setId(self::LOCAL_POST);
		$note->setAttributedTo(self::ALICE);
		$note->setLocal(true);
		$note->setVisibility($visibility);
		if ($visibility === Stream::TYPE_PUBLIC) {
			$note->setToArray([ACore::CONTEXT_PUBLIC]);
		}

		return $note;
	}

	private function holding(?Stream $post): void {
		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($post): Stream {
				if ($post !== null && $id === $post->getId()) {
					return $post;
				}

				throw new StreamNotFoundException();
			});
	}

	/** bob's server asking to quote the post named by $object. */
	private function request(string $object = self::LOCAL_POST, string $instrument = self::REMOTE_QUOTING): QuoteRequest {
		/** @var QuoteRequest $request */
		$request = $this->incoming(
			QuoteRequest::TYPE,
			self::REMOTE_URL . '/quote_requests/1',
			self::BOB
		);
		$request->setObjectId($object);
		$request->setInstrument($instrument);

		return $request;
	}

	// --- answering a request for one of our posts

	public function testAQuoteRequestForAPublicLocalPostIsAccepted(): void {
		$this->holding($this->localPost());
		$sent = null;
		$this->activityService->expects($this->once())->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			});

		$request = $this->request();
		$this->handler->processIncomingRequest($request);

		$this->assertInstanceOf(Accept::class, $sent);
		// the answer comes from the author of the quoted post
		$this->assertSame(self::ALICE, $sent->getActorId());
		// the peer matches the answer against the request it sent
		$this->assertSame($request, $sent->getObject());
		$this->assertContains(self::BOB, $sent->getToArray());
		// the approval stamp the quoting post then carries as `quoteAuthorization`
		$this->assertNotSame('', $sent->exportAsActivityPub()['result']);
		$inboxes = array_map(
			static fn ($path): string => $path->getUri(),
			$sent->getInstancePaths()
		);
		$this->assertContains(self::BOB . '/inbox', $inboxes);
	}

	public function testTheApprovalStampHangsOffTheQuotedPost(): void {
		$this->holding($this->localPost());
		$sent = null;
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			}
		);

		$this->handler->processIncomingRequest($this->request());

		$this->assertStringStartsWith(self::LOCAL_POST, $sent->exportAsActivityPub()['result']);
	}

	/**
	 * The default policy is the rule the rest of the app applies to a post
	 * being carried into somebody else's audience: public and unlisted, yes;
	 * anything narrower, no.
	 */
	public function testAQuoteRequestForAFollowersOnlyPostIsRejected(): void {
		$this->holding($this->localPost(Stream::TYPE_FOLLOWERS));
		$sent = null;
		$this->activityService->expects($this->once())->method('request')
			->willReturnCallback(function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			});

		$this->handler->processIncomingRequest($this->request());

		$this->assertInstanceOf(Reject::class, $sent);
		$this->assertSame(self::ALICE, $sent->getActorId());
		$this->assertArrayNotHasKey('result', $sent->exportAsActivityPub());
	}

	/**
	 * The author's own choice, where they made one, rather than the visibility
	 * rule: a public post whose author said "nobody" is refused.
	 */
	public function testANobodyPolicyRefusesEvenAPublicPost(): void {
		$post = $this->localPost();
		$post->setQuotePolicy(Stream::QUOTE_POLICY_NOBODY);
		$this->holding($post);
		$sent = null;
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			}
		);

		$this->handler->processIncomingRequest($this->request());

		$this->assertInstanceOf(Reject::class, $sent);
	}

	public function testAFollowersPolicyAcceptsAFollowerAndRefusesAStranger(): void {
		$post = $this->localPost();
		$post->setQuotePolicy(Stream::QUOTE_POLICY_FOLLOWERS);
		$this->holding($post);

		$follow = new Follow();
		$follow->setActorId(self::BOB)->setObjectId(self::ALICE)->setAccepted(true);
		$follows = $this->createMock(FollowsRequest::class);
		$follows->method('getByPersons')->with(self::BOB, self::ALICE)->willReturn($follow);
		$handler = new QuoteRequestInterface(
			$this->streamRequest,
			$this->cacheActorService,
			$this->activityService,
			$this->quoteGrantRequest,
			$follows,
			new NullLogger()
		);

		$sent = null;
		$this->activityService->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			}
		);

		$handler->processIncomingRequest($this->request());

		$this->assertInstanceOf(Accept::class, $sent);
	}

	/**
	 * Taking a quote back means sending a `Reject` naming the request that was
	 * accepted, so the request has to be written down at the moment the grant
	 * is made — there is nothing to name afterwards otherwise.
	 */
	public function testAnAcceptedRequestIsWrittenDownSoItCanBeTakenBack(): void {
		$this->holding($this->localPost());
		$this->activityService->method('request')->willReturn('token');

		$saved = null;
		$this->quoteGrantRequest->expects($this->once())->method('save')
			->willReturnCallback(static function (QuoteGrant $grant) use (&$saved): void {
				$saved = $grant;
			});

		$this->handler->processIncomingRequest($this->request());

		$this->assertSame(self::LOCAL_POST, $saved->getTargetId());
		$this->assertSame(self::REMOTE_QUOTING, $saved->getQuotingId());
		$this->assertSame(self::BOB, $saved->getActorId());
		$this->assertSame(self::REMOTE_URL . '/quote_requests/1', $saved->getRequestId());
		$this->assertNotSame('', $saved->getAuthorization());
	}

	public function testARefusedRequestIsNotWrittenDown(): void {
		$this->holding($this->localPost(Stream::TYPE_FOLLOWERS));
		$this->activityService->method('request')->willReturn('token');
		$this->quoteGrantRequest->expects($this->never())->method('save');

		$this->handler->processIncomingRequest($this->request());
	}

	public function testAQuoteRequestForAPostWeDoNotHoldIsAnsweredWithNothing(): void {
		$this->holding(null);
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->request());
	}

	/** Somebody else's post is not ours to grant permission over. */
	public function testAQuoteRequestForARemotePostIsAnsweredWithNothing(): void {
		$remote = new Note();
		$remote->setId(self::REMOTE_URL . '/notes/9');
		$remote->setAttributedTo(self::BOB);
		$remote->setToArray([ACore::CONTEXT_PUBLIC]);
		$this->holding($remote);
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->request(self::REMOTE_URL . '/notes/9'));
	}

	public function testAQuoteRequestWithoutTheQuotingPostIsAnsweredWithNothing(): void {
		$this->holding($this->localPost());
		$this->activityService->expects($this->never())->method('request');

		$this->handler->processIncomingRequest($this->request(self::LOCAL_POST, ''));
	}

	/**
	 * The quoting post has to live on the server that asks: otherwise anyone
	 * could collect an approval for somebody else's post.
	 */
	public function testAQuoteRequestNamingAPostOnAnotherServerIsRefused(): void {
		$this->holding($this->localPost());
		$this->activityService->expects($this->never())->method('request');

		$this->expectException(InvalidOriginException::class);
		$this->handler->processIncomingRequest(
			$this->request(self::LOCAL_POST, 'https://elsewhere.example/notes/3')
		);
	}

	// --- recording the answer to a request of ours

	/** Our quoting post, quoting bob's. */
	private function ourQuotingPost(string $authorization = ''): Note {
		$note = new Note();
		$note->setId(self::LOCAL_URL . '/notes/mine');
		$note->setAttributedTo(self::ALICE);
		$note->setLocal(true);
		$note->setQuote(self::REMOTE_URL . '/notes/quoted');
		$note->setQuoteAuthorization($authorization);

		return $note;
	}

	/** The request we sent, as it comes back inside bob's answer. */
	private function ourRequest(): QuoteRequest {
		$request = new QuoteRequest();
		$request->setId(self::LOCAL_URL . '/notes/mine#quote-request');
		$request->setActorId(self::ALICE);
		$request->setObjectId(self::REMOTE_URL . '/notes/quoted');
		$request->setInstrument(self::LOCAL_URL . '/notes/mine');

		return $request;
	}

	private function answer(string $type, array $wire = []): ACore {
		$answer = $this->incoming(
			$type,
			self::REMOTE_URL . '/answers/1',
			self::BOB,
			null,
			self::REMOTE_HOST
		);
		$answer->setSource(json_encode($wire));

		return $answer;
	}

	public function testAnAcceptStampsTheApprovalOnOurQuotingPost(): void {
		$post = $this->ourQuotingPost();
		$this->holding($post);

		$stored = null;
		$this->streamRequest->expects($this->once())->method('update')
			->willReturnCallback(function (Stream $stream) use (&$stored): void {
				$stored = $stream;
			});
		$this->streamRequest->expects($this->once())->method('updateDetails');

		$this->handler->activity(
			$this->answer(Accept::TYPE, ['result' => self::REMOTE_URL . '/approvals/1']),
			$this->ourRequest()
		);

		$this->assertSame(self::REMOTE_URL . '/approvals/1', $stored->getQuoteAuthorization());
		$this->assertSame(Stream::QUOTE_ACCEPTED, $stored->getQuoteState());
		// the wire object is where the approval is stored, and what the next
		// delivery of the post carries
		$this->assertSame(
			self::REMOTE_URL . '/approvals/1',
			json_decode($stored->getSource(), true)['quoteAuthorization']
		);
	}

	public function testARejectMovesTheStoredStateToRejected(): void {
		$post = $this->ourQuotingPost();
		$this->holding($post);

		$stored = null;
		$this->streamRequest->expects($this->once())->method('updateDetails')
			->willReturnCallback(function (Stream $stream) use (&$stored): void {
				$stored = $stream;
			});

		$this->handler->activity($this->answer(Reject::TYPE), $this->ourRequest());

		$this->assertSame(Stream::QUOTE_REJECTED, $stored->getQuoteState());
	}

	/** A Reject of a quote that was already approved is a withdrawal. */
	public function testARejectAfterAnApprovalIsARevocation(): void {
		$post = $this->ourQuotingPost(self::REMOTE_URL . '/approvals/1');
		$this->holding($post);

		$stored = null;
		$this->streamRequest->method('updateDetails')
			->willReturnCallback(function (Stream $stream) use (&$stored): void {
				$stored = $stream;
			});
		$this->streamRequest->expects($this->once())->method('update');

		$this->handler->activity($this->answer(Reject::TYPE), $this->ourRequest());

		$this->assertSame(Stream::QUOTE_REVOKED, $stored->getQuoteState());
		$this->assertSame('', $stored->getQuoteAuthorization());
		// and off the wire object, so no later delivery claims the approval
		$this->assertArrayNotHasKey('quoteAuthorization', json_decode($stored->getSource(), true));
	}

	/** An answer from anyone but the quoted post's server decides nothing. */
	public function testAnAnswerFromSomebodyElseIsRefused(): void {
		$this->holding($this->ourQuotingPost());
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$answer = $this->incoming(
			Accept::TYPE,
			'https://elsewhere.example/answers/1',
			'https://elsewhere.example/users/mallory',
			null,
			'elsewhere.example'
		);

		$this->expectException(InvalidOriginException::class);
		$this->handler->activity($answer, $this->ourRequest());
	}

	public function testAnAnswerAboutAPostWeDoNotHoldIsIgnored(): void {
		$this->holding(null);
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->activity($this->answer(Accept::TYPE), $this->ourRequest());
	}

	/**
	 * What the store holds, by id: the answer path reads the quoted post as
	 * well as our own.
	 *
	 * @param Stream[] $posts
	 */
	private function holdingAll(array $posts): void {
		$byId = [];
		foreach ($posts as $post) {
			$byId[$post->getId()] = $post;
		}

		$this->streamRequest->method('getStreamById')
			->willReturnCallback(function (string $id) use ($byId): Stream {
				if (!isset($byId[$id])) {
					throw new StreamNotFoundException();
				}

				return $byId[$id];
			});
	}

	/** bob's post, the one ours quotes. */
	private function quotedPost(string $author = self::BOB): Note {
		$quoted = new Note();
		$quoted->setId(self::REMOTE_URL . '/notes/quoted');
		$quoted->setAttributedTo($author);

		return $quoted;
	}

	public function testTheQuotedAuthorsOwnAcceptIsApplied(): void {
		$this->holdingAll([$this->ourQuotingPost(), $this->quotedPost()]);

		$stored = null;
		$this->streamRequest->expects($this->once())->method('update')
			->willReturnCallback(function (Stream $stream) use (&$stored): void {
				$stored = $stream;
			});

		$this->handler->activity(
			$this->answer(Accept::TYPE, ['result' => self::REMOTE_URL . '/approvals/1']),
			$this->ourRequest()
		);

		$this->assertSame(Stream::QUOTE_ACCEPTED, $stored->getQuoteState());
	}

	/**
	 * The origin check stops at the host, so any account on the quoted post's
	 * server could approve — or revoke — a quote its author never decided on.
	 */
	public function testAnAnswerFromANeighbourOfTheQuotedAuthorIsRefused(): void {
		$this->holdingAll([$this->ourQuotingPost(), $this->quotedPost()]);
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$answer = $this->incoming(
			Accept::TYPE,
			self::REMOTE_URL . '/answers/1',
			self::REMOTE_URL . '/users/mallory',
			null,
			self::REMOTE_HOST
		);
		$answer->setSource(json_encode([]));

		$this->expectException(InvalidOriginException::class);
		$this->handler->activity($answer, $this->ourRequest());
	}

	/** The answer has to be about the quote the post actually carries. */
	public function testAnAnswerNamingAnotherQuoteIsIgnored(): void {
		$post = $this->ourQuotingPost();
		$post->setQuote(self::REMOTE_URL . '/notes/something-else');
		$this->holding($post);
		$this->streamRequest->expects($this->never())->method('update');
		$this->streamRequest->expects($this->never())->method('updateDetails');

		$this->handler->activity($this->answer(Accept::TYPE), $this->ourRequest());
	}
}
