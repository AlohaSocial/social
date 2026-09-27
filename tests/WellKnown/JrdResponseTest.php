<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\WellKnown;

use OCA\Social\WellKnown\JrdResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JrdResponseTest extends TestCase {
	protected function setUp(): void {
		\OC::$server->register(IRequest::class, $this->createStub(IRequest::class));
	}

	protected function tearDown(): void {
		\OC::$server->reset();
	}

	public function testANewResponseIsEmpty(): void {
		$this->assertTrue((new JrdResponse('acct:alice@cloud.example'))->isEmpty());
	}

	/** @return iterable<string, array{callable(JrdResponse): void}> */
	public static function fillers(): iterable {
		yield 'alias' => [fn (JrdResponse $r) => $r->addAlias('https://cloud.example/@alice')];
		yield 'property' => [fn (JrdResponse $r) => $r->addProperty('http://schema/name', 'Alice')];
		yield 'link' => [fn (JrdResponse $r) => $r->addLink('self', null, null)];
		yield 'expires' => [fn (JrdResponse $r) => $r->setExpires('2030-01-01T00:00:00Z')];
	}

	#[DataProvider('fillers')]
	public function testAnyContentMakesTheResponseNonEmpty(callable $fill): void {
		$response = new JrdResponse();
		$fill($response);

		$this->assertFalse($response->isEmpty());
	}

	public function testEmptyResponseRendersAsBodylessDataResponseWithItsStatus(): void {
		$http = (new JrdResponse('', Http::STATUS_NOT_FOUND))->toHttpResponse();

		$this->assertInstanceOf(DataResponse::class, $http);
		$this->assertNotInstanceOf(JSONResponse::class, $http);
		$this->assertSame(Http::STATUS_NOT_FOUND, $http->getStatus());
		$this->assertSame('', $http->getData());
	}

	public function testSubjectAloneIsEnoughForAJsonDocument(): void {
		$http = (new JrdResponse('acct:alice@cloud.example'))->toHttpResponse();

		$this->assertInstanceOf(JSONResponse::class, $http);
		$this->assertSame(['subject' => 'acct:alice@cloud.example'], $http->getData());
		$this->assertSame('application/json; charset=utf-8', $http->getHeaders()['Content-Type']);
	}

	public function testFullDocumentSerialisation(): void {
		$response = (new JrdResponse('acct:alice@cloud.example'))
			->setExpires('2030-01-01T00:00:00Z')
			->addAlias('https://cloud.example/@alice')
			->addAlias('https://cloud.example/u/alice')
			->addProperty('http://schema/name', 'Alice')
			->addProperty('http://schema/empty', null)
			->addLink('self', 'application/activity+json', 'https://cloud.example/@alice')
			->addLink('http://ostatus.org/schema/1.0/subscribe', '', '', null, null, ['template' => 'https://cloud.example/follow?uri={uri}'])
			->addLink('http://webfinger.net/rel/avatar', 'image/png', 'https://cloud.example/avatar.png', ['en' => 'Avatar'], ['size' => '64']);

		$this->assertSame([
			'subject' => 'acct:alice@cloud.example',
			'expires' => '2030-01-01T00:00:00Z',
			'aliases' => ['https://cloud.example/@alice', 'https://cloud.example/u/alice'],
			'properties' => ['http://schema/name' => 'Alice', 'http://schema/empty' => null],
			'links' => [
				['rel' => 'self', 'type' => 'application/activity+json', 'href' => 'https://cloud.example/@alice'],
				['rel' => 'http://ostatus.org/schema/1.0/subscribe', 'template' => 'https://cloud.example/follow?uri={uri}'],
				[
					'rel' => 'http://webfinger.net/rel/avatar',
					'type' => 'image/png',
					'href' => 'https://cloud.example/avatar.png',
					'titles' => ['en' => 'Avatar'],
					'properties' => ['size' => '64'],
				],
			],
		], $response->toHttpResponse()->getData());
	}

	public function testEmptyLinkAttributesAreDropped(): void {
		$response = (new JrdResponse('s'))->addLink('rel-only', null, null, [], []);

		$this->assertSame([['rel' => 'rel-only']], $response->toHttpResponse()->getData()['links']);
	}

	public function testHttpCodeCanBeChangedAfterConstruction(): void {
		$response = (new JrdResponse('s'))->setHttpCode(Http::STATUS_GONE);

		$this->assertSame(Http::STATUS_GONE, $response->toHttpResponse()->getStatus());
	}
}
