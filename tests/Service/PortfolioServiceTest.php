<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\PortfoliosRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Model\Client\Portfolio;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\CollectionService;
use OCA\Social\Service\PortfolioService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * A page anybody may read, built only out of posts anybody may read.
 */
#[AllowMockObjectsWithoutExpectations]
class PortfolioServiceTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	private PortfoliosRequest|MockObject $portfoliosRequest;
	private StreamRequest|MockObject $streamRequest;
	private CollectionService|Stub $collectionService;
	private CacheActorService|Stub $cacheActorService;
	private PortfolioService $service;

	/** the options every timeline read was asked with */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();

		$this->portfoliosRequest = $this->createMock(PortfoliosRequest::class);
		$this->streamRequest = $this->createMock(StreamRequest::class);
		$this->collectionService = $this->createStub(CollectionService::class);
		$this->cacheActorService = $this->createStub(CacheActorService::class);

		$this->streamRequest->method('getTimeline')->willReturnCallback(
			function (ProbeOptions $options): array {
				$this->asked[] = $options;

				return [];
			}
		);

		$this->service = new PortfolioService(
			$this->portfoliosRequest,
			$this->streamRequest,
			$this->collectionService,
			$this->cacheActorService,
			$this->createStub(StreamService::class),
		);
	}

	private function person(string $id): Person {
		$person = new Person();
		$person->setId($id);

		return $person;
	}

	private function portfolio(bool $active = true): Portfolio {
		return (new Portfolio())->setActorId(self::ALICE)->setActive($active)->setTitle('Work');
	}

	/**
	 * The guarantee the whole page rests on: it is asked for with **no
	 * viewer**, and the account query answers an anonymous reader with public
	 * posts and nothing else. Not a switch somebody can get wrong once.
	 */
	public function testAPublishedPageIsBuiltWithNoViewerAtAll(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::ALICE));
		$this->portfoliosRequest->method('getByActor')->willReturn($this->portfolio());

		$this->streamRequest->expects($this->once())->method('resetViewer');
		$this->streamRequest->expects($this->never())->method('setViewer');

		$this->service->published('alice');
	}

	/** A row that exists is a draft until its owner says otherwise. */
	public function testAPageThatWasNeverTurnedOnIsNotThere(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::ALICE));
		$this->portfoliosRequest->method('getByActor')->willReturn($this->portfolio(false));

		$this->expectException(ItemNotFoundException::class);

		$this->service->published('alice');
	}

	/** And a handle this server does not know is the same answer. */
	public function testAnUnknownHandleIsTheSameAnswerAsNoPage(): void {
		$this->cacheActorService->method('getFromAccount')
			->willThrowException(new \RuntimeException('no such account'));

		$this->expectException(ItemNotFoundException::class);

		$this->service->published('ghost@nowhere.example');
	}

	/** A portfolio is pictures; a text post on one is a paragraph in a gallery. */
	public function testItAsksForPicturesOnly(): void {
		$this->cacheActorService->method('getFromAccount')->willReturn($this->person(self::ALICE));
		$this->portfoliosRequest->method('getByActor')->willReturn($this->portfolio());

		$this->service->published('alice');

		$this->assertSame('image', $this->asked[0]->getMediaType());
		$this->assertSame(self::ALICE, $this->asked[0]->getAccountId());
	}

	/**
	 * The owner's preview is the page, not a version of it only they can see.
	 *
	 * Found on devel: previewing a draft showed the owner their own
	 * followers-only pictures among the rest, and none of those would have
	 * been on the published page. A preview that shows a photograph which will
	 * not appear is worse than no preview.
	 */
	public function testTheOwnersPreviewIsWhatTheInternetWillGet(): void {
		$this->portfoliosRequest->method('getByActor')->willReturn($this->portfolio(false));

		$this->streamRequest->expects($this->once())->method('resetViewer');
		$this->streamRequest->expects($this->never())->method('setViewer');

		$this->service->own($this->person(self::ALICE));
	}

	/**
	 * An account that has never opened the editor gets the defaults, not a
	 * 404: there is nothing to find, and an editor should not need a "create
	 * it first" step.
	 */
	public function testAnAccountWithNoPageGetsTheDefaults(): void {
		$this->portfoliosRequest->method('getByActor')
			->willThrowException(new ItemNotFoundException('none'));

		$portfolio = $this->service->own($this->person(self::ALICE));

		$this->assertFalse($portfolio->isActive());
		$this->assertSame(Portfolio::LAYOUT_GRID, $portfolio->getLayout());
		$this->assertSame(Portfolio::SOURCE_RECENT, $portfolio->getSource());
	}

	/**
	 * Refused when it is asked for rather than rendered as an empty page
	 * afterwards, which is the difference between an error somebody can act on
	 * and a page that is mysteriously blank.
	 */
	public function testAPageBuiltFromSomebodyElsesCollectionIsRefused(): void {
		$this->portfoliosRequest->method('getByActor')
			->willThrowException(new ItemNotFoundException('none'));
		$this->collectionService->method('own')
			->willThrowException(new \RuntimeException('not yours'));

		$this->portfoliosRequest->expects($this->never())->method('save');
		$this->expectException(InvalidResourceException::class);

		$this->service->save($this->person(self::ALICE), [
			'source' => 'collection', 'collection_id' => 9,
		]);
	}

	public function testAskingForACollectionWithoutNamingOneIsRefused(): void {
		$this->portfoliosRequest->method('getByActor')
			->willThrowException(new ItemNotFoundException('none'));

		$this->expectException(InvalidResourceException::class);

		$this->service->save($this->person(self::ALICE), ['source' => 'collection']);
	}

	/** Anything that is not one of the two layouts is the default, not stored. */
	public function testANonsenseLayoutBecomesTheDefault(): void {
		$this->portfoliosRequest->method('getByActor')
			->willThrowException(new ItemNotFoundException('none'));
		$this->portfoliosRequest->method('save')->willReturnArgument(0);

		$saved = $this->service->save($this->person(self::ALICE), [
			'layout' => 'carousel', 'source' => 'whatever',
		]);

		$this->assertSame(Portfolio::LAYOUT_GRID, $saved->getLayout());
		$this->assertSame(Portfolio::SOURCE_RECENT, $saved->getSource());
	}

	public function testTheTitleAndIntroAreCutToWhatTheyMayBe(): void {
		$this->portfoliosRequest->method('getByActor')
			->willThrowException(new ItemNotFoundException('none'));
		$this->portfoliosRequest->method('save')->willReturnArgument(0);

		$saved = $this->service->save($this->person(self::ALICE), [
			'title' => str_repeat('a', Portfolio::MAX_TITLE + 50),
			'intro' => str_repeat('b', Portfolio::MAX_INTRO + 50),
		]);

		$this->assertSame(Portfolio::MAX_TITLE, mb_strlen($saved->getTitle()));
		$this->assertSame(Portfolio::MAX_INTRO, mb_strlen($saved->getIntro()));
	}
}
