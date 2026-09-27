<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Dashboard;

use OCA\Social\Dashboard\SocialBookmarksWidget;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class SocialBookmarksWidgetTest extends TestCase {
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var AccountService&Stub */
	private $accountService;
	/** @var CacheActorService&Stub */
	private $cacheActorService;
	/** @var StreamService&Stub */
	private $streamService;
	private SocialBookmarksWidget $widget;

	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->accountService = $this->createStub(AccountService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);
		$this->streamService = $this->createStub(StreamService::class);

		$this->widget = new SocialBookmarksWidget(
			$l10n,
			$this->urlGenerator,
			$this->accountService,
			$this->cacheActorService,
			$this->streamService,
			new NullLogger()
		);
	}

	public function testIdentity(): void {
		$this->assertInstanceOf(IAPIWidgetV2::class, $this->widget);
		$this->assertSame('social_bookmarks', $this->widget->getId());
		$this->assertSame('Social bookmarks', $this->widget->getTitle());
		$this->assertSame(14, $this->widget->getOrder());
	}

	public function testItReadsTheBookmarksProbeAndOpensThatTimeline(): void {
		$account = $this->createStub(Person::class);
		$account->method('getPreferredUsername')->willReturn('alice');
		$this->accountService->method('getActorFromUserId')->willReturn($account);
		$this->cacheActorService->method('getFromLocalAccount')->willReturn($this->createStub(Person::class));
		$this->urlGenerator->method('linkToRoute')
			->with('social.Navigation.timeline', ['path' => 'bookmarks'])
			->willReturn('/apps/social/timeline/bookmarks');
		$options = null;
		$this->streamService->method('getTimeline')->willReturnCallback(function (ProbeOptions $o) use (&$options): array {
			$options = $o;

			return [];
		});

		$items = $this->widget->getItemsV2('alice');

		$this->assertSame(ProbeOptions::BOOKMARKS, $options->getProbe());
		$this->assertSame('/apps/social/timeline/bookmarks', $this->widget->getUrl());
		$this->assertSame('Bookmark a post to keep it here', $items->getEmptyContentMessage());
	}
}
