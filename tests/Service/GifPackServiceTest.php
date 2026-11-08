<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\Gif;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\GifPackService;
use OCA\Social\Tools\Exceptions\RequestResultSizeException;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The animated emoji every instance has in its picker.
 *
 * The weight here is on the fetch: it is this app asking a third party for a
 * file and then serving the answer to everybody, so what it will accept, what
 * it will not, and how often it asks are the things worth pinning.
 */
#[AllowMockObjectsWithoutExpectations]
class GifPackServiceTest extends TestCase {
	private ISimpleFolder&MockObject $folder;
	private CurlService&MockObject $curl;
	private ConfigService&Stub $config;
	private GifPackService $service;

	protected function setUp(): void {
		$this->folder = $this->createMock(ISimpleFolder::class);
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);

		$this->curl = $this->createMock(CurlService::class);

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args): string => 'https://cloud.example/gif/' . $args['slug']
		);

		$this->config = $this->createStub(ConfigService::class);
		$this->config->method('getAppValueBool')->willReturn(true);
		$this->config->method('hasInternetConnection')->willReturn(true);

		$this->service = new GifPackService(
			$appData, $this->curl, $urlGenerator, $this->config, new NullLogger()
		);
	}

	/**
	 * The manifest is a shipped file rather than a fixture: if it stops being
	 * readable, or stops being the shape this reads, every picker on every
	 * instance quietly empties and nothing else would say so.
	 */
	public function testTheShippedListIsThereAndIsRead(): void {
		$all = $this->service->all();

		$this->assertGreaterThan(500, count($all));
		$this->assertContainsOnlyInstancesOf(Gif::class, $all);
	}

	/**
	 * Every line of the shipped list is offered.
	 *
	 * The codepoint is checked before it is used, because it becomes both a
	 * URL this server fetches and a filename it writes — and the first version
	 * of that check wanted four hex digits, which silently dropped the two
	 * emoji whose codepoint has two: (C) and (R). A rule that throws entries
	 * away without a word is one that has to be counted.
	 */
	public function testNothingInTheShippedListIsSilentlyDropped(): void {
		$manifest = json_decode(
			(string)file_get_contents(dirname(__DIR__, 2) . '/data/noto-animated-emoji.json'),
			true
		);

		$this->assertCount(count($manifest['emoji']), $this->service->all());
		$this->assertNotNull($this->service->bySlug('noto-a9_fe0f'));
	}

	public function testTheMostAskedForComeFirst(): void {
		$first = $this->service->all()[0];

		// the picker opens on the first screenful and the warm-up job fetches
		// exactly that, so the order is load-bearing rather than cosmetic
		$this->assertSame('noto-1f600', $first->getSlug());
		$this->assertSame('smile', $first->getTitle());
	}

	public function testEveryOneIsServedFromThisInstance(): void {
		foreach (array_slice($this->service->all(), 0, 20) as $gif) {
			$this->assertStringStartsWith('https://cloud.example/gif/noto-', $gif->getUrl());
			$this->assertSame('image/webp', $gif->getMediaType());
		}
	}

	public function testSearchingMatchesTheName(): void {
		$found = $this->service->search('hedgehog');

		$this->assertNotSame([], $found);
		$this->assertStringContainsString('hedgehog', $found[0]->getTitle());
	}

	/** Google's other names for a picture, and its category, are searched too. */
	public function testSearchingMatchesTheKeywords(): void {
		$this->assertNotSame([], $this->service->search('animals and nature'));
	}

	public function testSearchingIgnoresCase(): void {
		$this->assertNotSame([], $this->service->search('HEDGEHOG'));
	}

	public function testAnEmptyTermIsTheWholePack(): void {
		$this->assertCount(count($this->service->all()), $this->service->search('  '));
	}

	public function testSearchingFindsNothingRatherThanEverything(): void {
		$this->assertSame([], $this->service->search('zzzaardvarkzzz'));
	}

	public function testItOwnsItsOwnSlugsAndNobodyElseIs(): void {
		$this->assertTrue($this->service->owns('noto-1f600'));
		$this->assertFalse($this->service->owns('happy-cat'));
	}

	public function testAnUnknownCodepointIsNotInTheLibrary(): void {
		$this->assertNull($this->service->bySlug('noto-ffffff'));
		$this->assertNull($this->service->bySlug('happy-cat'));
	}

	public function testATurnedOffPackOffersNothing(): void {
		$service = $this->serviceWithPackOff();

		$this->assertSame([], $service->all());
		$this->assertSame([], $service->search('smile'));
		$this->assertNull($service->bySlug('noto-1f600'));
	}

	/** A picture this instance already has is read, not asked for again. */
	public function testACachedPictureIsNotFetched(): void {
		$file = $this->createStub(ISimpleFile::class);
		$this->folder->method('getFile')->with('1f600.webp')->willReturn($file);
		$this->curl->expects($this->never())->method('doRequest');

		$this->assertSame($file, $this->service->file('noto-1f600'));
	}

	public function testOneThatIsNotHereYetIsFetchedAndKept(): void {
		$webp = 'RIFF' . str_repeat('x', 4) . 'WEBPVP8X' . str_repeat('y', 32);
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$asked = [];
		$this->curl->expects($this->once())
			->method('doRequest')
			->willReturnCallback($this->answering(200, $webp, $asked));

		$stored = $this->createStub(ISimpleFile::class);
		$this->folder->expects($this->once())
			->method('newFile')
			->with('1f600.webp', $webp)
			->willReturn($stored);

		$this->assertSame($stored, $this->service->file('noto-1f600'));
		$this->assertSame('https://fonts.gstatic.com/s/e/notoemoji/latest/1f600/512.webp', $asked['url']);
		// the app's own client: the address guard applies, the instance
		// access list does not, this being a URL the app ships
		$this->assertFalse($asked['options']['federation']);
		$this->assertFalse($asked['options']['json_headers']);
	}

	/**
	 * The bytes decide, as they do for a picture an administrator adds. This
	 * is served to everybody here and ends up attached to posts that federate,
	 * so "the URL said webp" is not good enough.
	 */
	public function testSomethingThatIsNotAWebPIsRefused(): void {
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->curl->method('doRequest')->willReturnCallback($this->answering(200, '<html>nope</html>'));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-1f600');
	}

	/**
	 * The transport reads an answer as a stream and gives up one byte past
	 * its ceiling; what arrives here is refused and nothing is stored.
	 */
	public function testAnAnswerPastTheCeilingIsRefused(): void {
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->curl->method('doRequest')->willThrowException(new RequestResultSizeException());
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-1f600');
	}

	public function testASourceThatRefusesIsNotStored(): void {
		$this->folder->method('getFile')->willThrowException(new NotFoundException());
		$this->curl->method('doRequest')->willReturnCallback($this->answering(404, ''));
		$this->folder->expects($this->never())->method('newFile');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-1f600');
	}

	/** Nothing is fetched for a slug the manifest does not have. */
	public function testAnUnknownSlugIsNeverFetched(): void {
		$this->curl->expects($this->never())->method('doRequest');

		$this->expectException(NotFoundException::class);
		$this->service->file('noto-ffffff');
	}

	public function testATurnedOffPackFetchesNothing(): void {
		$this->curl->expects($this->never())->method('doRequest');

		$this->expectException(NotFoundException::class);
		$this->serviceWithPackOff()->file('noto-1f600');
	}

	/** What CC BY asks for, read from the manifest so it cannot drift. */
	public function testTheCreditIsCarriedWithTheList(): void {
		$this->assertStringContainsString('CC BY 4.0', $this->service->attribution());
	}

	/**
	 * What the transport answers a request: the body, with the status by
	 * reference, and the request itself recorded in $asked.
	 */
	private function answering(int $status, string $body, ?array &$asked = null): callable {
		return static function (string $method, string $url, array $options, &$contentType = null, &$code = null) use ($status, $body, &$asked): string {
			$asked = ['method' => $method, 'url' => $url, 'options' => $options];
			$code = $status;

			return $body;
		};
	}

	/**
	 * Nextcloud's `has_internet_connection => false`: nothing is fetched, and
	 * no picker of pictures that could never arrive is offered.
	 */
	public function testAServerWithoutInternetOffersNoPackAndFetchesNothing(): void {
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn(true);
		$config->method('hasInternetConnection')->willReturn(false);
		$service = new GifPackService($appData, $this->curl, $this->createStub(IURLGenerator::class), $config, new NullLogger());
		$this->curl->expects($this->never())->method('doRequest');

		$this->assertFalse($service->enabled());
		$this->assertSame([], $service->all());
		$this->expectException(NotFoundException::class);
		$service->file('noto-1f600');
	}

	public function testTheSourceIsNamedForTheAdministrator(): void {
		$this->assertSame('fonts.gstatic.com', GifPackService::sourceHost());
	}

	private function serviceWithPackOff(): GifPackService {
		$appData = $this->createStub(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$config = $this->createStub(ConfigService::class);
		$config->method('getAppValueBool')->willReturn(false);

		return new GifPackService(
			$appData,
			$this->curl,
			$this->createStub(IURLGenerator::class),
			$config,
			new NullLogger()
		);
	}
}
