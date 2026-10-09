<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Db\QuoteGrantRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\QuoteGrant;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\QuoteService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An author's control over who quotes their posts.
 *
 * The reason to be able to quote somebody is also the reason to be able to
 * stop them, so what is held still here is: the policy is the author's own to
 * set, changing it does not silently withdraw what was already granted, and
 * taking one back reaches the server that holds the quote.
 */
#[AllowMockObjectsWithoutExpectations]
class QuoteServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const BOB = 'https://cloud.example/apps/social/@bob';
	private const POST = 'https://cloud.example/apps/social/@alice/1';
	private const REMOTE_QUOTING = 'https://remote.example/notes/2';
	private const REMOTE_CAROL = 'https://remote.example/users/carol';

	private StreamRequest|MockObject $streamRequest;
	private QuoteGrantRequest|MockObject $quoteGrantRequest;
	private CacheActorService|Stub $cacheActorService;
	private ActivityService|MockObject $activityService;
	private QuoteService $service;

	protected function setUp(): void {
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->quoteGrantRequest = $this->createMock(QuoteGrantRequest::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->activityService = $this->createMock(ActivityService::class);

		$this->service = new QuoteService(
			$this->streamRequest,
			$this->quoteGrantRequest,
			$this->cacheActorService,
			$this->activityService,
			new NullLogger(),
		);
	}

	private function alice(): Person {
		$alice = new Person();
		$alice->setId(self::ALICE)->setLocal(true);

		return $alice;
	}

	private function post(string $attributedTo = self::ALICE): Note {
		$note = new Note();
		$note->setId(self::POST)->setNid(7)->setLocal(true)->setAttributedTo($attributedTo);
		$note->setVisibility(Stream::TYPE_PUBLIC);

		return $note;
	}

	private function quoting(bool $local, string $attributedTo = self::REMOTE_CAROL): Note {
		$note = new Note();
		$note->setId($local ? self::BOB . '/9' : self::REMOTE_QUOTING)
			->setNid(9)->setLocal($local)->setAttributedTo($attributedTo);
		$note->setQuote(self::POST);

		return $note;
	}

	// --- the policy -------------------------------------------------------

	public function testTheAuthorSetsWhoMayQuoteTheirOwnPost(): void {
		$post = $this->post();
		$this->streamRequest->method('getStreamByNid')->with(7)->willReturn($post);

		$written = null;
		$this->streamRequest->method('update')
			->willReturnCallback(static function (Stream $stream) use (&$written): void {
				$written = $stream->getQuotePolicy();
			});

		$result = $this->service->setPolicy(7, $this->alice(), Stream::QUOTE_POLICY_FOLLOWERS);

		$this->assertSame(Stream::QUOTE_POLICY_FOLLOWERS, $result->getQuotePolicy());
		$this->assertSame(Stream::QUOTE_POLICY_FOLLOWERS, $written);
	}

	/**
	 * Who may quote somebody else's post is their decision, and a different
	 * answer here would say whose post it is.
	 */
	public function testSomebodyElsesPostIsNotFound(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->post(self::BOB));
		$this->streamRequest->expects($this->never())->method('update');

		$this->expectException(InvalidResourceException::class);

		$this->service->setPolicy(7, $this->alice(), Stream::QUOTE_POLICY_NOBODY);
	}

	public function testAPostThatIsNotThereIsNotFound(): void {
		$this->streamRequest->method('getStreamByNid')->willThrowException(new StreamNotFoundException());

		$this->expectException(InvalidResourceException::class);

		$this->service->setPolicy(7, $this->alice(), Stream::QUOTE_POLICY_NOBODY);
	}

	/**
	 * A quote that has been published and read is not undone by a switch being
	 * flipped, so changing the policy tells nobody and takes nothing back.
	 */
	public function testChangingThePolicyWithdrawsNothingAlreadyGranted(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->post());
		$this->quoteGrantRequest->expects($this->never())->method('delete');
		$this->activityService->expects($this->never())->method('request');

		$this->service->setPolicy(7, $this->alice(), Stream::QUOTE_POLICY_NOBODY);
	}

	// --- revoking ---------------------------------------------------------

	public function testRevokingARemoteQuoteRejectsTheRequestThatWasAccepted(): void {
		$post = $this->post();
		$quoting = $this->quoting(false);
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(static fn (int $nid): Stream => ($nid === 7) ? $post : $quoting);

		$grant = new QuoteGrant();
		$grant->setTargetId(self::POST)->setQuotingId(self::REMOTE_QUOTING)
			->setActorId(self::REMOTE_CAROL)->setRequestId('https://remote.example/quote-requests/5');
		$this->quoteGrantRequest->method('get')->willReturn($grant);

		$carol = new Person();
		$carol->setId(self::REMOTE_CAROL)->setInbox('https://remote.example/inbox');
		$this->cacheActorService->method('getFromId')->willReturn($carol);

		$sent = null;
		$this->activityService->expects($this->once())->method('request')
			->willReturnCallback(static function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			});
		$this->quoteGrantRequest->expects($this->once())->method('delete')
			->with(self::POST, self::REMOTE_QUOTING);

		$this->assertTrue($this->service->revoke(7, $this->alice(), 9));

		$this->assertSame('Reject', $sent->getType());
		$this->assertSame(self::ALICE, $sent->getActorId());
		$request = $sent->getObject();
		$this->assertSame('https://remote.example/quote-requests/5', $request->getId());
		$this->assertSame(self::POST, $request->getObjectId());
		$this->assertSame(self::REMOTE_QUOTING, $request->getInstrument());
	}

	/** There is nobody to tell about a quote that lives here. */
	public function testRevokingALocalQuoteTellsNobodyAndMarksItRevoked(): void {
		$post = $this->post();
		$quoting = $this->quoting(true, self::BOB);
		$quoting->setQuoteAuthorization(self::POST . '/quote_authorizations/x');
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(static fn (int $nid): Stream => ($nid === 7) ? $post : $quoting);
		$this->activityService->expects($this->never())->method('request');

		$this->assertTrue($this->service->revoke(7, $this->alice(), 9));

		$this->assertSame('', $quoting->getQuoteAuthorization());
		$this->assertSame(Stream::QUOTE_REVOKED, $quoting->getQuoteState());
	}

	/**
	 * Bluesky asks nobody's permission, so there is no request to refuse: the
	 * quote is listed in the post's postgate, which every AppView honours.
	 */
	public function testRevokingABlueskyQuoteDetachesItInThePostgate(): void {
		$post = $this->post();
		$quoting = new Note();
		$quoting->setId('https://bsky.app/profile/did:plc:bob/post/3kq')->setNid(9)->setAttributedTo('https://bsky.app/profile/did:plc:bob');
		$quoting->setQuote(self::POST);
		$quoting->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://did:plc:bob/app.bsky.feed.post/3kq']);
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(static fn (int $nid): Stream => ($nid === 7) ? $post : $quoting);
		$this->activityService->expects($this->never())->method('request');
		$publisher = $this->createMock(Publisher::class);
		$publisher->expects($this->once())->method('updateGates')->with($this->callback(static fn (Stream $gated): bool => $gated->getDetachedQuotes() === ['at://did:plc:bob/app.bsky.feed.post/3kq']));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(Publisher::class)->willReturn($publisher);
		$service = new QuoteService($this->streamRequest, $this->quoteGrantRequest, $this->cacheActorService, $this->activityService, new NullLogger(), $container);

		$this->assertTrue($service->revoke(7, $this->alice(), 9));

		$this->assertSame(Stream::QUOTE_REVOKED, $quoting->getQuoteState());
	}

	/** A post that does not quote this one is nothing to take back. */
	public function testRevokingAPostThatQuotesSomethingElseIsNotFound(): void {
		$post = $this->post();
		$other = $this->quoting(false);
		$other->setQuote('https://cloud.example/apps/social/@alice/99');
		$this->streamRequest->method('getStreamByNid')
			->willReturnCallback(static fn (int $nid): Stream => ($nid === 7) ? $post : $other);
		$this->activityService->expects($this->never())->method('request');

		$this->assertFalse($this->service->revoke(7, $this->alice(), 9));
	}

	public function testRevokingOnSomebodyElsesPostIsNotFound(): void {
		$this->streamRequest->method('getStreamByNid')->willReturn($this->post(self::BOB));

		$this->expectException(InvalidResourceException::class);

		$this->service->revoke(7, $this->alice(), 9);
	}

	// --- the list ---------------------------------------------------------

	public function testTheQuotesOfAPostAreTheOnesThisServerHolds(): void {
		$post = $this->post();
		$quoting = $this->quoting(false);
		$this->streamRequest->expects($this->once())->method('getQuotesOf')
			->with(self::POST, 20, 0)->willReturn([$quoting]);

		$this->assertSame([$quoting], $this->service->quotesOf($post));
	}
}
