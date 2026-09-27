<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Actor\InstanceActor;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Report;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\HttpSignatureService;
use OCA\Social\Service\InstanceActorService;
use OCA\Social\Service\ReportForwardService;
use OCA\Social\Tools\Exceptions\RequestNetworkException;
use OCA\Social\Tools\Exceptions\RequestResultNotJsonException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Forwarding a report to the instance that hosts the reported account.
 *
 * The thing these tests exist to pin is *who the forward names*: Mastodon
 * forwards anonymised so that the reporter is not handed to the admins of the
 * instance they just reported, and a regression that put the reporter's id
 * back into the Flag would be invisible from here — it is the receiving
 * instance that would see it.
 */
#[AllowMockObjectsWithoutExpectations]
class ReportForwardServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/apps/social/@alice';
	private const SPAMMER = 'https://spam.example/users/spammer';
	private const INBOX = 'https://spam.example/users/spammer/inbox';
	private const INSTANCE = 'https://cloud.example/apps/social/actor';

	private InstanceActorService|Stub $instanceActorService;
	private CurlService|MockObject $curlService;
	private \OCA\Social\Db\StreamRequest|MockObject $streamRequest;
	private ReportForwardService $service;
	private string $privateKey = '';
	/** @var list<array{method: string, url: string, options: array}> every request handed to curl */
	private array $sent = [];

	protected function setUp(): void {
		$this->instanceActorService = $this->createStub(InstanceActorService::class);
		$this->curlService = $this->createMock(CurlService::class);

		$this->streamRequest = $this->createMock(\OCA\Social\Db\StreamRequest::class);
		$this->service = new ReportForwardService(
			$this->instanceActorService,
			new HttpSignatureService(
				$this->createStub(\OCA\Social\Db\ActorsRequest::class),
				$this->instanceActorService,
				new NullLogger()
			),
			$this->curlService,
			$this->streamRequest,
			new NullLogger()
		);
	}

	private function instanceActor(): InstanceActor {
		if ($this->privateKey === '') {
			$res = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
			openssl_pkey_export($res, $this->privateKey);
		}

		$actor = new InstanceActor();
		$actor->setId(self::INSTANCE)->setPrivateKey($this->privateKey)->setLocal(true);

		return $actor;
	}

	private function remote(string $id = self::SPAMMER, string $inbox = self::INBOX): Person {
		$person = new Person();
		$person->setId($id)->setLocal(false);
		$person->setInbox($inbox);

		return $person;
	}

	private function report(): Report {
		$report = new Report();
		$report->setId(7)
			->setActorId(self::ALICE)
			->setAccountId(self::SPAMMER)
			->setStatusIds([self::SPAMMER . '/statuses/1'])
			->setComment('this is spam')
			->setLocal(true);

		return $report;
	}

	private function captureDelivery(): void {
		$this->curlService->method('retrieveJson')
			->willReturnCallback(function (string $method, string $url, array $options): array {
				$this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

				return [];
			});
	}

	private function body(): string {
		return $this->sent[0]['options']['body'];
	}

	/** @return array<string, string> */
	private function headers(): array {
		return $this->sent[0]['options']['headers'];
	}

	public function testTheForwardedFlagNamesTheInstanceAndNotTheReporter(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->assertTrue($this->service->forward($this->report(), $this->remote()));

		$body = json_decode($this->body(), true);
		$this->assertSame('Flag', $body['type']);
		$this->assertSame(self::INSTANCE, $body['actor'], 'the instance forwards, not the reporter');
		$this->assertStringNotContainsString(
			self::ALICE, $this->body(),
			'the reporter must not be exposed to the instance they reported'
		);
	}

	public function testTheFlagCarriesTheAccountThenTheReportedStatusesAndTheComment(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$body = json_decode($this->body(), true);
		$this->assertSame([self::SPAMMER, self::SPAMMER . '/statuses/1'], $body['object']);
		$this->assertSame('this is spam', $body['content']);
		$this->assertSame(self::SPAMMER, $body['to']);
	}

	// --- the ids the other server can actually find ------------------------

	/** Builds a stream the lookup will answer with. */
	private function stream(string $id, bool $local = false): \OCA\Social\Model\ActivityPub\Stream {
		$stream = new \OCA\Social\Model\ActivityPub\Stream();
		$stream->setId($id)->setLocal($local);

		return $stream;
	}

	/**
	 * A client reports a post by the id **this** API gave it — a snowflake nid
	 * — and those were going into the `Flag` as they arrived. No other server
	 * has ever seen them, so a forwarded report named one account the
	 * receiving moderators could find and a list of numbers they could not. A
	 * report about a **video**, where the video is the whole complaint, thus
	 * carried nothing at all.
	 */
	public function testAReportedPostIsNamedByTheAddressTheOtherServerKnows(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();
		$this->streamRequest->method('getStreamByNid')->with(4242)
			->willReturn($this->stream('https://peertube.example/videos/watch/abc'));

		$report = $this->report();
		$report->setStatusIds(['4242']);

		$this->service->forward($report, $this->remote());

		$body = json_decode($this->body(), true);
		$this->assertSame(
			[self::SPAMMER, 'https://peertube.example/videos/watch/abc'], $body['object']
		);
	}

	/** An id that is already an address is left as it is. */
	public function testAnAddressIsPassedOnUnchanged(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();
		$this->streamRequest->expects($this->never())->method('getStreamByNid');

		$report = $this->report();
		$report->setStatusIds(['https://remote.example/users/spammer/statuses/9']);

		$this->service->forward($report, $this->remote());

		$body = json_decode($this->body(), true);
		$this->assertContains('https://remote.example/users/spammer/statuses/9', $body['object']);
	}

	/**
	 * The statuses in a report about a remote account are that account's
	 * posts; one of ours in the list is a reply somebody picked up by mistake,
	 * and naming it would be telling another instance's moderators about a
	 * post of our own.
	 */
	public function testOneOfOurOwnPostsIsNotNamedToAnotherInstancesModerators(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();
		$this->streamRequest->method('getStreamByNid')
			->willReturn($this->stream('https://cloud.example/@alice/1', local: true));

		$report = $this->report();
		$report->setStatusIds(['7']);

		$this->service->forward($report, $this->remote());

		$body = json_decode($this->body(), true);
		$this->assertSame([self::SPAMMER], $body['object']);
	}

	/** A post this instance no longer holds; the account is still named. */
	public function testAPostThatIsGoneIsLeftOutRatherThanFailingTheForward(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();
		$this->streamRequest->method('getStreamByNid')
			->willThrowException(new \Exception('gone'));

		$report = $this->report();
		$report->setStatusIds(['7']);

		$this->assertTrue($this->service->forward($report, $this->remote()));
		$this->assertSame([self::SPAMMER], json_decode($this->body(), true)['object']);
	}

	public function testTheDeliveryIsSignedWithTheInstanceActorsKeyOverTheBody(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$headers = $this->headers();
		$this->assertArrayHasKey('Signature', $headers);
		$this->assertStringContainsString(
			'keyId="' . self::INSTANCE . '#main-key"', $headers['Signature']
		);
		// Mastodon refuses a POST whose signature does not cover the body
		$this->assertStringContainsString(
			'headers="(request-target) content-length date host digest"', $headers['Signature']
		);
		$this->assertSame(
			'SHA-256=' . base64_encode(hash('sha256', $this->body(), true)),
			$headers['digest']
		);
		$this->assertSame((string)strlen($this->body()), $headers['content-length']);
	}

	/** The timeout the user's own request can afford to wait for. */
	public function testTheForwardHasAShortTimeout(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$this->assertSame(3, $this->sent[0]['options']['timeout']);
	}

	public function testTheDeliveryGoesToTheReportedAccountsInbox(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$this->service->forward($this->report(), $this->remote());

		$this->assertSame('post', $this->sent[0]['method']);
		$this->assertSame(self::INBOX, $this->sent[0]['url']);
	}

	public function testThePersonalInboxIsPreferredOverTheSharedOne(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$target = $this->remote();
		$target->setSharedInbox('https://spam.example/inbox');

		$this->service->forward($this->report(), $target);

		// a Flag is about one account; the shared inbox is the fallback for an
		// instance that publishes no personal one
		$this->assertSame(self::INBOX, $this->sent[0]['url']);
	}

	public function testAnEmptyBodyFromTheInboxIsASuccess(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->method('retrieveJson')->willThrowException(new RequestResultNotJsonException());

		// an inbox answers 202 with no body; that is acceptance, not failure
		$this->assertTrue($this->service->forward($this->report(), $this->remote()));
	}

	public function testAnUnreachableInstanceIsNotReportedAsForwarded(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->method('retrieveJson')->willThrowException(new RequestNetworkException());

		$this->assertFalse($this->service->forward($this->report(), $this->remote()));
	}

	public function testNothingIsSentWhenTheInstanceCannotSignAsItself(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn(null);
		$this->curlService->expects($this->never())->method('retrieveJson');

		$this->assertFalse($this->service->forward($this->report(), $this->remote()));
	}

	public function testALocalAccountIsNotForwardedAnywhere(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		$local = new Person();
		$local->setId(self::ALICE)->setLocal(true);
		$local->setInbox(self::ALICE . '/inbox');

		$this->assertFalse($this->service->forward($this->report(), $local));
	}

	public function testAReportThatArrivedAsAFlagIsNeverForwardedOn(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		// forwarding somebody else's report would put this instance's name on
		// it, and two instances doing that to each other is a loop
		$this->assertFalse($this->service->forward($this->report()->setLocal(false), $this->remote()));
	}

	public function testAnAccountWithNoInboxCannotBeForwardedTo(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->curlService->expects($this->never())->method('retrieveJson');

		$this->assertFalse($this->service->forward($this->report(), $this->remote(self::SPAMMER, '')));
	}

	public function testTheSharedInboxIsUsedWhenThereIsNoPersonalOne(): void {
		$this->instanceActorService->method('getSigningActor')->willReturn($this->instanceActor());
		$this->captureDelivery();

		$target = $this->remote(self::SPAMMER, '');
		$target->setSharedInbox('https://spam.example/inbox');

		$this->assertTrue($this->service->forward($this->report(), $target));
		$this->assertSame('https://spam.example/inbox', $this->sent[0]['url']);
	}
}
