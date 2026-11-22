<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\PeerTubeApiController;
use OCA\Social\Exceptions\ClientNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ChannelService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\PeerTubeApiService;
use OCA\Social\Service\SearchService;
use OCA\Social\Service\StreamService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The PeerTube client API (`/api/v1/videos`, `/api/v1/users/me`, …) as an
 * anonymous caller sees it.
 *
 * Its video routes answer without a viewer, so what keeps them to public
 * posts is that the timeline is probed as `public` and the stream layer is
 * never handed a viewer from here: its visibility filter then admits only
 * posts addressed to the public collection. An unusable token is answered
 * as nobody rather than as a fault, and the one route about the caller
 * refuses an anonymous one.
 */
#[AllowMockObjectsWithoutExpectations]
class PeerTubeApiControllerTest extends TestCase {
	private array $headers = [];
	private ?IUser $user = null;
	private ClientService&MockObject $clientService;
	private StreamService&MockObject $streamService;
	private PeerTubeApiService&MockObject $peerTube;

	protected function setUp(): void {
		$this->clientService = $this->createMock(ClientService::class);
		$this->streamService = $this->createMock(StreamService::class);
		$this->peerTube = $this->createMock(PeerTubeApiService::class);
		$this->peerTube->method('page')->willReturnCallback(fn (array $rows): array => ['total' => count($rows), 'data' => $rows]);
		$this->peerTube->method('video')->willReturnCallback(fn (Note $post): array => ['uuid' => $post->getId()]);
	}

	public function testAnAnonymousVideoListIsAPublicProbeWithNoViewer(): void {
		$probed = null;
		$this->streamService->expects($this->never())->method('setViewer');
		$this->streamService->expects($this->once())->method('getTimeline')
			->willReturnCallback(function (ProbeOptions $options) use (&$probed): array {
				$probed = $options;

				return [$this->post('https://cloud.example/@alice/1')];
			});

		$response = $this->controller()->videos();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertInstanceOf(ProbeOptions::class, $probed);
		$this->assertSame(ProbeOptions::PUBLIC, $probed->getProbe());
		$this->assertSame(ACore::FORMAT_LOCAL, $probed->getFormat());
		$this->assertTrue($probed->isOnlyVideo());
		$this->assertSame(['total' => 1, 'data' => [['uuid' => 'https://cloud.example/@alice/1']]], $response->getData());
	}

	/** A made-up token is the anonymous answer, not an error and not a viewer. */
	public function testAnUnusableTokenIsAnsweredAsNobody(): void {
		$this->clientService->method('getFromTokenOfRequest')->willThrowException(new ClientNotFoundException('no'));
		$this->streamService->expects($this->never())->method('setViewer');
		$this->streamService->method('getTimeline')->willReturn([]);

		$response = $this->controller('Bearer forged')->videos();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUsersMeRefusesAnAnonymousCaller(): void {
		$this->peerTube->expects($this->never())->method('user');

		$response = $this->controller()->me();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	/** A post that carries no video is not a video, whoever asks for it. */
	public function testAPostWithoutAVideoIsNotFoundOnTheVideoRoutes(): void {
		$this->streamService->method('getStreamByNid')->willReturn($this->post('https://cloud.example/@alice/2', 'image'));
		$this->streamService->expects($this->never())->method('getContextByNid');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->video('2')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->comments('2')->getStatus());
	}

	private function post(string $id, string $kind = 'video'): Note {
		$post = new Note();
		$post->setId($id);
		$post->setAttachments([['type' => $kind, 'url' => $id . '.bin']]);

		return $post;
	}

	private function controller(string $authorization = ''): PeerTubeApiController {
		$this->headers = ['Authorization' => $authorization];

		$request = $this->createStub(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$request->method('passesCSRFCheck')->willReturn(true);

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->user);

		return new PeerTubeApiController(
			$request,
			$session,
			new NullLogger(),
			$this->createStub(AccountService::class),
			$this->clientService,
			$this->peerTube,
			$this->streamService,
			$this->createStub(ChannelService::class),
			$this->createStub(SearchService::class),
		);
	}
}
