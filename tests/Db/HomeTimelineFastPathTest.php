<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * When the fast home-timeline query refuses to answer.
 *
 * It pages over the recipient rows' own sort key and is the reason a home
 * timeline is 50 ms rather than 1,661; the old query joins and sorts and is
 * slower and always correct. Every rule about when the fast one may *not* be
 * used is therefore a correctness rule, and each is asserted against the real
 * method rather than against a copy of its condition — a test that restated
 * the rule would pass whatever the code did.
 *
 * All four refusals happen before the query touches the database, which is what
 * makes them testable without one.
 */
#[AllowMockObjectsWithoutExpectations]
class HomeTimelineFastPathTest extends TestCase {
	private const ALICE = 'https://cloud.example/@alice';

	private ConfigService|Stub $configService;
	private StreamRequest $request;

	/** @var array<string, string> the app settings, as the request reads them */
	private array $settings = [ConfigService::SOCIAL_DEST_NID_FILLED => '1'];

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getAppValueBool')->willReturnCallback(
			fn (string $key): bool => ($this->settings[$key] ?? '0') === '1'
		);

		// the real class, with only the two collaborators the refusals consult
		$this->request = $this->getMockBuilder(StreamRequest::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		(new ReflectionProperty(StreamRequest::class, 'configService'))
			->setValue($this->request, $this->configService);
	}

	/** @return int[]|null what the fast query would answer with */
	private function page(ProbeOptions $options, ?Person $viewer): ?array {
		(new ReflectionProperty(StreamRequest::class, 'viewer'))
			->setValue($this->request, $viewer);
		(new ReflectionProperty(StreamRequest::class, 'recipientNidsFilled'))
			->setValue($this->request, null);

		return (new ReflectionMethod(StreamRequest::class, 'homeTimelineNidsFromRecipients'))
			->invoke($this->request, $options);
	}

	private function viewer(): Person {
		$actor = new Person();
		$actor->setId(self::ALICE);
		$actor->setFollowers(self::ALICE . '/followers');

		return $actor;
	}

	private function options(): ProbeOptions {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HOME)->setLimit(20);

		return $options;
	}

	/**
	 * A media narrowing is a question about the **post**, and this query reads
	 * only the recipient rows, which carry no such column. Answering anyway
	 * would read the newest rows and throw most of them away: on a seeded
	 * instance where 143 of 402,725 posts carry media, the Videos timeline came
	 * back **empty** while the videos sat a few thousand rows further down.
	 */
	#[DataProvider('provideNarrowings')]
	public function testANarrowedTimelineIsLeftToTheQueryThatCanAnswerIt(string $narrowing): void {
		$options = $this->options();
		match ($narrowing) {
			'only_media' => $options->setOnlyMedia(true),
			'only_video' => $options->setOnlyVideo(true),
			'only_news' => $options->setOnlyNews(true),
			default => $options->setMediaType($narrowing),
		};

		$this->assertNull($this->page($options, $this->viewer()));
	}

	/** @return array<string, array{string}> */
	public static function provideNarrowings(): array {
		return [
			'only_media' => ['only_media'],
			'only_video' => ['only_video'],
			// the same shape of question as the media ones, and a rarer kind
			// of post than a photograph: News would have come back empty
			'only_news' => ['only_news'],
			'media_type=image' => ['image'],
			'media_type=audio' => ['audio'],
		];
	}

	/** Nobody signed in has no follows for the page to be read from. */
	public function testWithoutAViewerThereIsNoFastPath(): void {
		$this->assertNull($this->page($this->options(), null));
	}

	/**
	 * Until the migration's backfill has finished, a recipient row may carry a
	 * zero nid — which sorts to the bottom and would silently shorten the head
	 * of every timeline. The flag is what says it is done; see
	 * `recipientNidsAreFilled()` for why it is a flag and not a query about the
	 * table.
	 */
	public function testAnUnfinishedBackfillKeepsTheOldQuery(): void {
		$this->settings[ConfigService::SOCIAL_DEST_NID_FILLED] = '0';

		$this->assertNull($this->page($this->options(), $this->viewer()));
	}
}
