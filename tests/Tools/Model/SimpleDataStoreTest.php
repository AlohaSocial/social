<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Model;

use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Model\SimpleDataStore;
use PHPUnit\Framework\TestCase;

class SimpleDataStoreTest extends TestCase {
	public function testConstructorAcceptsInitialDataOrNothing(): void {
		$this->assertSame(['a' => 1], (new SimpleDataStore(['a' => 1]))->gAll());
		$this->assertSame([], (new SimpleDataStore())->gAll());
		$this->assertSame([], (new SimpleDataStore(null))->gAll());
	}

	public function testStringValues(): void {
		$store = new SimpleDataStore();

		$store->s('host', 'cloud.example.org')->a('tags', 'a')->a('tags', 'b');

		$this->assertSame('cloud.example.org', $store->g('host'));
		$this->assertSame('', $store->g('missing'));
		$this->assertSame(['a', 'b'], $store->gArray('tags'));
	}

	public function testArrayValues(): void {
		$store = new SimpleDataStore();

		$store->sArray('list', ['a'])->aArray('list', ['b', 'c']);
		$store->aArray('fresh', ['x']);

		$this->assertSame(['a', 'b', 'c'], $store->gArray('list'));
		$this->assertSame(['x'], $store->gArray('fresh'));
		$this->assertSame([], $store->gArray('missing'));
	}

	public function testKeysAndHasKey(): void {
		$store = new SimpleDataStore(['a' => 1, 'b' => null]);

		$this->assertSame(['a', 'b'], $store->keys());
		$this->assertTrue($store->hasKey('a'));
		$this->assertTrue($store->hasKey('b'), 'a null value still counts as a key');
		$this->assertFalse($store->hasKey('c'));
		$this->assertTrue($store->haveKey('a'));
	}

	public function testObjectValues(): void {
		$store = new SimpleDataStore();
		$first = new InstancePath('https://a.example/inbox');
		$second = new InstancePath('https://b.example/inbox');

		$store->sObj('main', $first)->aObj('all', $first)->aObj('all', $second);

		$this->assertSame($first, $store->gAll()['main']);
		$this->assertSame([$first, $second], $store->gAll()['all']);
	}

	public function testDottedPathsReachIntoNestedData(): void {
		$store = new SimpleDataStore(['one' => ['k' => 'v']]);

		$this->assertSame('v', $store->g('one.k'));
	}

	public function testHasKeysChecksAllAndCanInsist(): void {
		$store = new SimpleDataStore(['a' => 1, 'b' => 2]);

		$this->assertTrue($store->hasKeys(['a', 'b']));
		$this->assertFalse($store->hasKeys(['a', 'c']));

		$this->expectException(MalformedArrayException::class);
		$this->expectExceptionMessage('c missing in ["a","b"]');
		$store->hasKeys(['a', 'c'], true);
	}

	public function testJsonSerializeExposesTheData(): void {
		$store = new SimpleDataStore(['new' => true]);

		$this->assertSame(['new' => true], $store->jsonSerialize());
		$this->assertSame('{"new":true}', json_encode($store));
	}
}
