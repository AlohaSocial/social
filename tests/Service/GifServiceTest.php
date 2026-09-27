<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\GifRequest;
use OCA\Social\Exceptions\InvalidActionException;
use OCA\Social\Model\Gif;
use OCA\Social\Service\GifPackService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\ImageMetadataService;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The instance's shared picture library.
 *
 * The slug rules carry the weight here: a slug is a path segment of a public
 * URL *and* the stem of a filename in appdata, so anything it lets through is
 * something both of those have to survive.
 */
class GifServiceTest extends TestCase {
	private GifRequest&MockObject $gifRequest;
	private ISimpleFolder&MockObject $folder;
	private GifPackService&MockObject $pack;
	private GifService $service;

	protected function setUp(): void {
		$this->gifRequest = $this->createMock(GifRequest::class);
		$this->folder = $this->createMock(ISimpleFolder::class);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(
				static fn (string $route, array $args): string => 'https://cloud.example/gif/' . $args['slug']
			);

		$metadata = $this->createMock(ImageMetadataService::class);
		$metadata->method('strip')->willReturnArgument(0);

		// the shipped emoji have their own suite; here they would put 881
		// rows in front of every assertion about the handful an instance adds
		$this->pack = $this->createMock(GifPackService::class);
		$this->pack->method('all')->willReturn([]);
		$this->pack->method('search')->willReturn([]);
		$this->pack->method('owns')->willReturnCallback(
			static fn (string $slug): bool => str_starts_with($slug, 'noto-')
		);

		$this->service = new GifService(
			$this->pack, $this->gifRequest, $appData, $urlGenerator, $metadata, new NullLogger()
		);
	}

	/**
	 * The instance's own first, then the emoji every instance has: somebody
	 * put the first lot there on purpose and they are the ones nowhere else
	 * has.
	 */
	public function testTheLibraryIsTheInstanceOwnAndThenThePack(): void {
		$this->gifRequest->method('all')->willReturn($this->library());
		$packed = new Gif('noto-1f600', '1f600.webp', 'image/webp', 'smile');
		$service = $this->serviceWithPack([$packed], []);

		$slugs = array_map(static fn (Gif $gif): string => $gif->getSlug(), $service->offered());

		$this->assertCount(4, $slugs);
		$this->assertSame('noto-1f600', end($slugs));
	}

	/** A search asks both, and the instance's own answer comes first. */
	public function testSearchingReachesThePackToo(): void {
		$this->gifRequest->method('all')->willReturn($this->library());
		$packed = new Gif('noto-1f603', '1f603.webp', 'image/webp', 'happy face');
		$service = $this->serviceWithPack([], [$packed]);

		$slugs = array_map(static fn (Gif $gif): string => $gif->getSlug(), $service->search('happy'));

		$this->assertSame(['happy-cat', 'noto-1f603'], $slugs);
	}

	public function testAPackSlugIsAnsweredByThePack(): void {
		$pack = $this->createMock(GifPackService::class);
		$pack->method('owns')->willReturn(true);
		$pack->expects($this->once())
			->method('bySlug')
			->with('noto-1f600')
			->willReturn(new Gif('noto-1f600', '1f600.webp', 'image/webp', 'smile'));
		// the rows must not be read to answer for one of 881 the pack knows by
		// name
		$this->gifRequest->expects($this->never())->method('all');

		$this->assertNotNull($this->serviceWithMock($pack)->bySlug('noto-1f600'));
	}

	public static function slugProvider(): array {
		return [
			'letters' => ['cat', true],
			'letters and digits' => ['cat2', true],
			'a hyphen' => ['happy-cat', true],
			'an underscore' => ['happy_cat', true],
			'too short' => ['c', false],
			'nothing' => ['', false],
			// the reason the rule is this narrow: the slug is a path segment
			// and a filename stem, so a dot or a slash is a traversal waiting
			// to be tried
			'a dot' => ['cat.gif', false],
			'a traversal' => ['../../etc/passwd', false],
			'a slash' => ['cat/2', false],
			'a null byte' => ["cat\0", false],
			'uppercase' => ['Cat', false],
			'a space' => ['happy cat', false],
			'longer than the column' => [str_repeat('a', 65), false],
		];
	}

	#[DataProvider('slugProvider')]
	public function testWhatMayNameAPicture(string $slug, bool $allowed): void {
		$this->assertSame($allowed, Gif::isSlug($slug));
	}

	/**
	 * The service again, with a pack that answers with these.
	 *
	 * @param Gif[] $all what the pack holds
	 * @param Gif[] $found what it answers a search with
	 */
	private function serviceWithPack(array $all, array $found): GifService {
		$pack = $this->createMock(GifPackService::class);
		$pack->method('all')->willReturn($all);
		$pack->method('search')->willReturn($found);
		$pack->method('owns')->willReturnCallback(
			static fn (string $slug): bool => str_starts_with($slug, 'noto-')
		);

		return $this->serviceWithMock($pack);
	}

	private function serviceWithMock(GifPackService&MockObject $pack): GifService {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/gif/x');
		$metadata = $this->createMock(ImageMetadataService::class);
		$metadata->method('strip')->willReturnArgument(0);

		return new GifService(
			$pack, $this->gifRequest, $appData, $urlGenerator, $metadata, new NullLogger()
		);
	}

	/** @return Gif[] */
	private function library(): array {
		return [
			new Gif('happy-cat', 'happy-cat.gif', 'image/gif', 'A very happy cat', 1),
			new Gif('shipit', 'shipit.gif', 'image/gif', 'Ship it', 2),
			new Gif('nod', 'nod.webp', 'image/webp', '', 3),
		];
	}

	public function testEveryPictureKnowsWhereItIsServed(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$urls = array_map(static fn (Gif $gif): string => $gif->getUrl(), $this->service->all());

		$this->assertSame([
			'https://cloud.example/gif/happy-cat',
			'https://cloud.example/gif/shipit',
			'https://cloud.example/gif/nod',
		], $urls);
	}

	public function testAnEmptyTermIsTheWholeLibrary(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertCount(3, $this->service->search(''));
		$this->assertCount(3, $this->service->search('   '));
	}

	public function testSearchingMatchesTheTitle(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$found = array_map(static fn (Gif $gif): string => $gif->getSlug(), $this->service->search('happy'));

		$this->assertSame(['happy-cat'], $found);
	}

	/** "the one with the cat" is how anybody actually looks for one of these */
	public function testSearchingIgnoresCase(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertCount(1, $this->service->search('HAPPY'));
		$this->assertCount(1, $this->service->search('Ship It'));
	}

	public function testSearchingAlsoMatchesTheSlug(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$found = array_map(static fn (Gif $gif): string => $gif->getSlug(), $this->service->search('nod'));

		$this->assertSame(['nod'], $found);
	}

	public function testSearchingFindsNothingRatherThanEverything(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertSame([], $this->service->search('aardvark'));
	}

	public function testBySlugFindsOne(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertSame('Ship it', $this->service->bySlug('shipit')?->getTitle());
	}

	public function testBySlugIsCaseInsensitiveAndTrimmed(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertNotNull($this->service->bySlug('  SHIPIT '));
	}

	public function testBySlugAnswersNullForOneThatIsNotThere(): void {
		$this->gifRequest->method('all')->willReturn($this->library());

		$this->assertNull($this->service->bySlug('nope'));
	}

	public function testAddingRefusesASlugThatIsNotOne(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->add('../escape', __FILE__);
	}

	public function testAddingRefusesAFileThatIsNotThere(): void {
		$this->expectException(InvalidActionException::class);

		$this->service->add('cat', '/no/such/file.gif');
	}

	/** A library of stills is what the Files picker beside it is already for. */
	public function testAddingRefusesSomethingThatIsNotAnimated(): void {
		$path = tempnam(sys_get_temp_dir(), 'gif');
		// a one-pixel PNG, which is a picture but not an animation
		file_put_contents($path, base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true
		));

		try {
			$this->expectException(InvalidActionException::class);
			$this->service->add('cat', $path);
		} finally {
			unlink($path);
		}
	}

	public function testAddingRefusesAnEmptyFile(): void {
		$path = tempnam(sys_get_temp_dir(), 'gif');

		try {
			$this->expectException(InvalidActionException::class);
			$this->service->add('cat', $path);
		} finally {
			unlink($path);
		}
	}

	public function testRemovingSaysWhenThereWasNothingToRemove(): void {
		$this->gifRequest->method('delete')->willReturn('');

		$this->assertFalse($this->service->remove('nope'));
	}

	public function testRemovingTakesTheBytesWithIt(): void {
		$this->gifRequest->method('delete')->willReturn('cat.gif');
		$file = $this->createMock(\OCP\Files\SimpleFS\ISimpleFile::class);
		$file->expects($this->once())->method('delete');
		$this->folder->method('getFile')->with('cat.gif')->willReturn($file);

		$this->assertTrue($this->service->remove('cat'));
	}

	/**
	 * The row is what decides whether a picture is in the library. A file left
	 * behind is a file, not a broken entry, and failing the removal over it
	 * would leave the entry in the picker.
	 */
	public function testRemovingSucceedsEvenIfTheBytesWillNotGo(): void {
		$this->gifRequest->method('delete')->willReturn('cat.gif');
		$this->folder->method('getFile')->willThrowException(new \Exception('busy'));

		$this->assertTrue($this->service->remove('cat'));
	}
}
