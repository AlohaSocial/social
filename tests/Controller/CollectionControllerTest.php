<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\CollectionController;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Collection;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\PlaceService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The two routes that name a post in a collection by its id.
 *
 * That id is the post's nid, which is wider than a PHP int on a 32-bit
 * server. Typed `int`, the framework clamps it to PHP_INT_MAX before the
 * service sees it, and the collection gains or loses a different post.
 */
class CollectionControllerTest extends TestCase {
	private const WIDE = '92233720368547758070';

	private CollectionService|MockObject $collectionService;

	protected function setUp(): void {
		parent::setUp();

		$this->collectionService = $this->createMock(CollectionService::class);
	}

	private function controller(): CollectionController {
		$request = $this->createStub(IRequest::class);
		$request->method('getId')->willReturn('test');
		$request->method('getHeader')->willReturn('');
		$request->method('passesCSRFCheck')->willReturn(true);
		$request->method('getParam')->willReturn('');
		$request->method('getParams')->willReturn([]);

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createStub(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$viewer = new Person();
		$viewer->setId('https://cloud.example/@alice');
		$accountService = $this->createStub(AccountService::class);
		$accountService->method('getActorFromUserId')->willReturn($viewer);

		return new CollectionController(
			$request,
			$userSession,
			new NullLogger(),
			$accountService,
			$this->createStub(ClientService::class),
			$this->createStub(CacheActorService::class),
			$this->collectionService,
			$this->createStub(LinkPreviewService::class),
			$this->createStub(PlaceService::class),
		);
	}

	public function testAWidePostIdIsAddedExactly(): void {
		$this->collectionService->expects($this->once())->method('addPost')
			->with($this->isInstanceOf(Person::class), 3, $this->identicalTo(self::WIDE))
			->willReturn(new Collection());

		$this->assertSame(Http::STATUS_OK, $this->controller()->addItem(3, self::WIDE)->getStatus());
	}

	public function testAWidePostIdIsRemovedExactly(): void {
		$this->collectionService->expects($this->once())->method('removePost')
			->with($this->isInstanceOf(Person::class), 3, $this->identicalTo(self::WIDE))
			->willReturn(new Collection());

		$this->assertSame(Http::STATUS_OK, $this->controller()->removeItem(3, self::WIDE)->getStatus());
	}
}
