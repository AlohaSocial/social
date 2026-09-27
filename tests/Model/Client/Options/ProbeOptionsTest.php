<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client\Options;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ProbeOptionsTest extends TestCase {
	public function testDefaultsMatchTheMastodonTimelineDefaults(): void {
		$options = new ProbeOptions();

		$this->assertSame('', $options->getProbe());
		$this->assertFalse($options->isLocal());
		$this->assertFalse($options->isRemote());
		$this->assertFalse($options->isOnlyMedia());
		$this->assertSame(0, $options->getMinId());
		$this->assertSame(0, $options->getMaxId());
		$this->assertSame(0, $options->getSince());
		$this->assertSame(20, $options->getLimit());
		$this->assertFalse($options->isInverted());
		$this->assertSame(ACore::FORMAT_ACTIVITYPUB, $options->getFormat());
	}

	public function testFromArrayReadsTheQueryParametersAsStrings(): void {
		$options = new ProbeOptions();

		$options->fromArray([
			'local' => 'true',
			'remote' => '0',
			'only_media' => '1',
			'min_id' => '10',
			'max_id' => '99',
			'since' => '5',
			'limit' => '40',
			'argument' => 'nextcloud',
		]);

		$this->assertTrue($options->isLocal());
		$this->assertFalse($options->isRemote());
		$this->assertTrue($options->isOnlyMedia());
		$this->assertSame(10, $options->getMinId());
		$this->assertSame(99, $options->getMaxId());
		$this->assertSame(5, $options->getSince());
		$this->assertSame(40, $options->getLimit());
		$this->assertSame('nextcloud', $options->getArgument());
	}

	public function testFromArrayKeepsCurrentValuesForMissingParameters(): void {
		$options = new ProbeOptions();
		$options->setLimit(7)->setLocal(true)->setArgument('cats');

		$options->fromArray(['max_id' => '3']);

		$this->assertSame(7, $options->getLimit());
		$this->assertTrue($options->isLocal());
		$this->assertSame('cats', $options->getArgument());
		$this->assertSame(3, $options->getMaxId());
	}

	public function testConstructorReadsTheRequestParameters(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['limit' => '15', 'local' => 'true']);

		$options = new ProbeOptions($request);

		$this->assertSame(15, $options->getLimit());
		$this->assertTrue($options->isLocal());
	}

	public function testSetLimitClampsToBounds(): void {
		$this->assertSame(ProbeOptions::MAX_LIMIT, (new ProbeOptions())->setLimit(1000000)->getLimit());
		$this->assertSame(1, (new ProbeOptions())->setLimit(-5)->getLimit());
		$this->assertSame(20, (new ProbeOptions())->setLimit(20)->getLimit());
	}

	public function testRequestLimitIsClampedToMaxLimit(): void {
		// A request asking for a million items must not reach setMaxResults() unbounded.
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['limit' => '1000000']);

		$this->assertSame(ProbeOptions::MAX_LIMIT, (new ProbeOptions($request))->getLimit());
	}

	public function testProbeIsLowercased(): void {
		$options = new ProbeOptions();

		$options->setProbe('Home');

		$this->assertSame(ProbeOptions::HOME, $options->getProbe());
	}

	/**
	 * `media_type` narrows `only_media` to one kind of attachment, and arrives
	 * from a query string: anything that is not a kind an attachment can be is
	 * read as no preference rather than passed to a query.
	 */
	public function testMediaTypeTakesOnlyTheKindsAnAttachmentCanBe(): void {
		$options = new ProbeOptions();

		foreach (ProbeOptions::MEDIA_TYPES as $type) {
			$this->assertSame($type, $options->setMediaType($type)->getMediaType());
		}
	}

	public function testMediaTypeIgnoresAnythingElse(): void {
		$options = new ProbeOptions();

		foreach (['', 'photos', 'video/mp4', 'IMAGE', '"type":"image"'] as $rubbish) {
			$this->assertSame('', $options->setMediaType($rubbish)->getMediaType());
		}
	}

	public function testMediaTypeIsReadOffTheRequest(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getParams')->willReturn(['media_type' => 'video']);

		$this->assertSame('video', (new ProbeOptions($request))->getMediaType());
	}

	public function testJsonSerializeExposesTheProbeState(): void {
		$options = new ProbeOptions();
		$options->setProbe(ProbeOptions::HASHTAG)
			->setAccountId('3')
			->setArgument('nextcloud')
			->setLimit(5)
			->setTypes(['Note'])
			->setExcludeTypes(['Announce'])
			->setInverted(true);

		$this->assertSame([
			'probe' => 'hashtag',
			'accountId' => '3',
			'local' => false,
			'remote' => false,
			'only_media' => false,
			'only_video' => false,
			'only_news' => false,
			'media_type' => '',
			'min_id' => 0,
			'max_id' => 0,
			'since' => 0,
			'limit' => 5,
			'argument' => 'nextcloud',
		], $options->jsonSerialize());
		$this->assertSame(['Note'], $options->getTypes());
		$this->assertSame(['Announce'], $options->getExcludeTypes());
		$this->assertTrue($options->isInverted());
	}
	// originLimit()

	private function options(array $params): ProbeOptions {
		return (new ProbeOptions())->fromArray($params);
	}

	public function testRemoteIsEveryInstanceButThisOne(): void {
		$this->assertFalse($this->options(['remote' => 'true'])->originLimit());
	}

	public function testLocalIsThisInstanceOnly(): void {
		$this->assertTrue($this->options(['local' => 'true'])->originLimit());
	}

	public function testNeitherNarrowsTheTimeline(): void {
		$this->assertNull($this->options([])->originLimit());
	}

	public function testBothDoesNotNarrowTheTimelineToNothing(): void {
		// the intersection is empty, and an empty timeline is the one answer
		// the client cannot have meant
		$this->assertTrue($this->options(['local' => 'true', 'remote' => 'true'])->originLimit());
	}

	/**
	 * `remote` is parsed off the request and was read by nothing: a client
	 * asking the federated timeline for `remote=true` got this instance's own
	 * posts back among the rest.
	 */
	public function testThePublicTimelineNarrowsByWhereAPostCameFrom(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Db/StreamTimelines.php');
		$start = strpos($source, 'private function getTimelinePublic(');
		$this->assertNotFalse($start);
		$body = substr($source, $start, (int)strpos($source, "\n\t}", $start) - $start);

		$this->assertStringContainsString('originLimit()', $body);
	}

}
