<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\MediaApiController;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What the routes of `MediaApiController` promise, read off the attributes themselves.
 *
 * Nothing here calls the controller. These are the guards that are easy to
 * leave off a new route and impossible to notice missing afterwards, which is
 * exactly what happened to `/media/{uuid}`.
 */
class MediaApiControllerTest extends TestCase {
	/**
	 * Every method of the controller with the routes declared on it.
	 *
	 * @return array<string, string[]> method name => the urls it answers
	 */
	private function routes(): array {
		$routes = [];

		foreach ((new ReflectionClass(MediaApiController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			foreach ($method->getAttributes(FrontpageRoute::class) as $attribute) {
				$arguments = $attribute->getArguments();
				$url = (string)($arguments['url'] ?? $arguments[1] ?? '');
				if ($url !== '') {
					$routes[$method->getName()][] = $url;
				}
			}
		}

		return $routes;
	}

	private function has(string $method, string $attribute): bool {
		return (new ReflectionMethod(MediaApiController::class, $method))->getAttributes($attribute) !== [];
	}

	/**
	 * Every route that hands back stored bytes carries a ceiling.
	 *
	 * `/media/{uuid}` was the one that did not, while the six beside it — the
	 * stream, the playlists and the ladder — all did. A uuid is unguessable but
	 * not secret: it travels with the post it belongs to, so one harvested from
	 * a public timeline could be asked for in a loop, with a `Range` header
	 * each time, for as long as anybody cared to.
	 *
	 * Asserted over *every* `/media/` GET rather than over a list of names, so
	 * that the next one added is covered without anybody remembering to add it
	 * here.
	 */
	public function testEveryByteRouteCarriesARateLimit(): void {
		$bare = [];

		foreach ($this->routes() as $method => $urls) {
			foreach ($urls as $url) {
				if (!str_starts_with($url, '/media/')) {
					continue;
				}

				if (!$this->has($method, AnonRateLimit::class) || !$this->has($method, UserRateLimit::class)) {
					$bare[] = $method . ' (' . $url . ')';
				}
			}
		}

		$this->assertSame(
			[],
			$bare,
			'these routes serve stored bytes to anybody holding the address, with no ceiling'
		);
	}

	/**
	 * A write without a ceiling is a write anybody with a token can repeat.
	 *
	 * `mediaUpdate()` was one: its POST sibling has carried a limit since it
	 * was written, and the PUT beside it had none.
	 */
	public function testEveryWriteCarriesARateLimit(): void {
		$bare = [];

		foreach ($this->routes() as $method => $urls) {
			foreach ($urls as $url) {
				$verb = $this->verbOf($method, $url);
				if (!in_array($verb, ['POST', 'PUT', 'DELETE'], true)) {
					continue;
				}

				if (!$this->has($method, AnonRateLimit::class) || !$this->has($method, UserRateLimit::class)) {
					$bare[] = $verb . ' ' . $url;
				}
			}
		}

		$this->assertSame([], $bare, 'these writes have no ceiling');
	}

	public function testAvifImagesAreInlineAndUnsupportedImageFormatsAreDownloads(): void {
		$method = new ReflectionMethod(MediaApiController::class, 'isDownloadOnly');
		$streamed = static fn (string $mime): Document => (new Document())
			->setLocalCopy(Document::COPY_STREAMED)
			->setMediaType($mime);

		$this->assertFalse($method->invoke(null, $streamed('image/avif')));
		$this->assertTrue($method->invoke(null, $streamed('image/heic')));
		$this->assertTrue($method->invoke(null, $streamed('application/pdf')));
		$this->assertFalse($method->invoke(null, $streamed('video/mp4')));
	}

	private function storedFile(string $bytes): ISimpleFile {
		$file = $this->createStub(ISimpleFile::class);
		$file->method('read')->willReturnCallback(static function () use ($bytes) {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $bytes);
			rewind($stream);

			return $stream;
		});

		return $file;
	}

	public function testEachCopyIsServedAsWhatItIs(): void {
		$method = new ReflectionMethod(MediaApiController::class, 'servedMediaType');
		$webp = 'RIFF' . pack('V', 100) . 'WEBPVP8 ' . str_repeat("\0", 20);
		$png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 20);
		$icon = (new Document())->setLocalCopy('local')->setResizedCopy('small');
		$icon->setMediaType('image/png');

		$this->assertSame('image/png', $method->invoke(null, $icon, 'local', $this->storedFile($png)));
		$this->assertSame('image/webp', $method->invoke(null, $icon, 'small', $this->storedFile($webp)), 'an icon thumbnail is WebP');
		$this->assertSame('image/png', $method->invoke(null, $icon, 'small', $this->storedFile($png)));

		$upload = (new Document())->setLocalCopy('local')->setResizedCopy('small');
		$upload->setMediaType('image/png');
		$upload->setAccount('alice');
		$this->assertSame('image/png', $method->invoke(null, $upload, 'small', $this->storedFile($webp)), 'an upload is not sniffed');

		$video = (new Document())->setLocalCopy('local')->setResizedCopy('poster');
		$video->setMediaType('video/mp4');
		$this->assertSame('image/jpeg', $method->invoke(null, $video, 'poster', $this->storedFile($png)));
		$this->assertSame('video/mp4', $method->invoke(null, $video, 'local', $this->storedFile($png)));
	}

	/** The verb a route was declared with. */
	private function verbOf(string $method, string $url): string {
		foreach ((new ReflectionMethod(MediaApiController::class, $method))->getAttributes(FrontpageRoute::class) as $attribute) {
			$arguments = $attribute->getArguments();
			if ((string)($arguments['url'] ?? $arguments[1] ?? '') === $url) {
				return strtoupper((string)($arguments['verb'] ?? $arguments[0] ?? ''));
			}
		}

		return '';
	}
}
