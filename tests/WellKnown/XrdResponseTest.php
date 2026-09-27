<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\WellKnown;

use OCA\Social\WellKnown\XrdResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class XrdResponseTest extends TestCase {
	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createMock(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testEmptyDocumentIsAValidXrdEnvelope(): void {
		$http = (new XrdResponse())->toHttpResponse();

		$this->assertInstanceOf(TextPlainResponse::class, $http);
		$this->assertSame(Http::STATUS_OK, $http->getStatus());
		$this->assertSame(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<XRD xmlns=\"http://docs.oasis-open.org/ns/xri/xrd-1.0\">\n</XRD>\n",
			$http->render()
		);
	}

	public function testContentTypeIsXrdXmlInsteadOfTextPlain(): void {
		$http = (new XrdResponse())->toHttpResponse();

		$this->assertSame('application/xrd+xml', $http->getHeaders()['Content-Type']);
	}

	public function testLinksAreRenderedAsLinkElementsInOrder(): void {
		$response = (new XrdResponse())
			->addLink('lrdd', 'https://cloud.example/.well-known/webfinger?resource={uri}')
			->addLink('http://ostatus.org/schema/1.0/subscribe', 'https://cloud.example/follow?uri={uri}');

		$xml = $response->toHttpResponse()->render();

		$this->assertStringContainsString('  <Link rel="lrdd"  template="https://cloud.example/.well-known/webfinger?resource={uri}"/>' . "\n", $xml);
		$this->assertStringContainsString('  <Link rel="http://ostatus.org/schema/1.0/subscribe"  template="https://cloud.example/follow?uri={uri}"/>' . "\n", $xml);
		$this->assertLessThan(strpos($xml, 'ostatus.org'), strpos($xml, 'lrdd'));
		$this->assertSame(2, substr_count($xml, '<Link '));
	}

	public function testHttpCodeIsForwarded(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, (new XrdResponse(Http::STATUS_NOT_FOUND))->toHttpResponse()->getStatus());
	}
}
