<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Traits;

use OCA\Social\Tools\Exceptions\ArrayNotFoundException;
use OCA\Social\Tools\Exceptions\ItemNotFoundException;
use OCA\Social\Tools\Exceptions\MalformedArrayException;
use OCA\Social\Tools\Exceptions\UnknownTypeException;
use OCA\Social\Tools\Model\SimpleDataStore;
use OCA\Social\Tools\Traits\TArrayTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TArrayToolsTest extends TestCase {
	/** Exposes the protected trait methods. */
	private object $tools;

	protected function setUp(): void {
		$this->tools = new class {
			use TArrayTools;

			public function __call(string $name, array $args) {
				return $this->$name(...$args);
			}

			public function clean(array $arr): array {
				$this->cleanArray($arr);

				return $arr;
			}
		};
	}

	private function sample(): array {
		return [
			'str' => 'value',
			'int' => 42,
			'zero' => 0,
			'float' => 1.5,
			'true' => true,
			'null' => null,
			'list' => ['a', 'b'],
			'json' => '{"k":"v"}',
			'nested' => ['deep' => ['key' => 'found', 'count' => '7', 'flag' => 'true', 'arr' => [1]]],
		];
	}

	public function testGetReturnsStringsAndIntsAsStrings(): void {
		$this->assertSame('value', $this->tools->get('str', $this->sample()));
		$this->assertSame('42', $this->tools->get('int', $this->sample()));
		$this->assertSame('0', $this->tools->get('zero', $this->sample()));
	}

	public static function nonStringProvider(): array {
		return [
			'missing' => ['missing'],
			'null' => ['null'],
			'float' => ['float'],
			'bool' => ['true'],
			'array' => ['list'],
			'nested through a scalar' => ['str.sub'],
			'nested missing leaf' => ['nested.deep.none'],
			'nested missing root' => ['none.deep'],
		];
	}

	#[DataProvider('nonStringProvider')]
	public function testGetFallsBackToTheDefaultForAnythingElse(string $key): void {
		$this->assertSame('dflt', $this->tools->get($key, $this->sample(), 'dflt'));
		$this->assertSame('', $this->tools->get($key, $this->sample()));
	}

	public function testGetWalksDottedPaths(): void {
		$this->assertSame('found', $this->tools->get('nested.deep.key', $this->sample()));
		$this->assertSame('7', $this->tools->get('nested.deep.count', $this->sample()));
	}

	public function testGetPrefersALiteralDottedKey(): void {
		$this->assertSame('literal', $this->tools->get('a.b', ['a.b' => 'literal', 'a' => ['b' => 'nested']]));
	}

	public function testGetIntCastsNumericStringsAndUsesTheDefaultForNullOrMissing(): void {
		$this->assertSame(42, $this->tools->getInt('int', $this->sample()));
		$this->assertSame(7, $this->tools->getInt('nested.deep.count', $this->sample()));
		$this->assertSame(0, $this->tools->getInt('zero', $this->sample(), 9));
		$this->assertSame(1, $this->tools->getInt('true', $this->sample()));
		$this->assertSame(0, $this->tools->getInt('str', $this->sample(), 9), 'non-numeric strings become 0, not the default');
		$this->assertSame(9, $this->tools->getInt('null', $this->sample(), 9));
		$this->assertSame(9, $this->tools->getInt('missing', $this->sample(), 9));
		$this->assertSame(9, $this->tools->getInt('str.sub', $this->sample(), 9));
	}

	public function testGetFloatReadsWholeNumbersAndFallsBack(): void {
		$this->assertSame(42.0, $this->tools->getFloat('int', $this->sample()));
		$this->assertSame(7.0, $this->tools->getFloat('nested.deep.count', $this->sample()));
		$this->assertSame(2.5, $this->tools->getFloat('null', $this->sample(), 2.5));
		$this->assertSame(2.5, $this->tools->getFloat('missing', $this->sample(), 2.5));
		$this->assertSame(2.5, $this->tools->getFloat('str.sub', $this->sample(), 2.5));
	}

	public static function boolProvider(): array {
		return [
			'bool true' => [true, true],
			'bool false' => [false, false],
			'"1"' => ['1', true],
			'"0"' => ['0', false],
			'int 1' => [1, true],
			'int 0' => [0, false],
			'"true"' => ['true', true],
			'"FALSE"' => ['FALSE', false],
			'"yes" is not understood' => ['yes', 'default'],
			'null' => [null, 'default'],
		];
	}

	#[DataProvider('boolProvider')]
	public function testGetBoolUnderstandsBooleansAndTheirStringForms($value, $expected): void {
		$expectDefault = ($expected === 'default');

		$this->assertSame($expectDefault ? true : $expected, $this->tools->getBool('k', ['k' => $value], true));
		$this->assertSame($expectDefault ? false : $expected, $this->tools->getBool('k', ['k' => $value], false));
	}

	public function testGetBoolWalksDottedPathsAndFallsBackWhenMissing(): void {
		$this->assertTrue($this->tools->getBool('nested.deep.flag', $this->sample()));
		$this->assertTrue($this->tools->getBool('missing', $this->sample(), true));
		$this->assertFalse($this->tools->getBool('none.deep', $this->sample()));
	}

	public function testGetArrayReturnsArraysAndDecodesJsonStrings(): void {
		$this->assertSame(['a', 'b'], $this->tools->getArray('list', $this->sample()));
		$this->assertSame(['k' => 'v'], $this->tools->getArray('json', $this->sample()));
		$this->assertSame([1], $this->tools->getArray('nested.deep.arr', $this->sample()));
	}

	public static function notAnArrayProvider(): array {
		return [
			'missing' => ['missing'],
			'null' => ['null'],
			'int' => ['int'],
			'bool' => ['true'],
			'plain string' => ['str'],
			'nested through scalar' => ['int.sub'],
		];
	}

	#[DataProvider('notAnArrayProvider')]
	public function testGetArrayFallsBackToTheDefault(string $key): void {
		$this->assertSame(['d'], $this->tools->getArray($key, $this->sample(), ['d']));
	}

	public function testGetArrayFallsBackForAJsonScalar(): void {
		$this->assertSame(['d'], $this->tools->getArray('k', ['k' => '"just a string"'], ['d']));
	}

	public function testValidKeyChecksLiteralAndDottedKeys(): void {
		$this->assertTrue($this->tools->validKey('null', $this->sample()), 'a null value still is a key');
		$this->assertTrue($this->tools->validKey('nested.deep.key', $this->sample()));
		$this->assertFalse($this->tools->validKey('nested.deep.none', $this->sample()));
		$this->assertFalse($this->tools->validKey('str.sub', $this->sample()));
		$this->assertFalse($this->tools->validKey('missing', $this->sample()));
	}

	public function testExtractArrayFindsTheEntryWithAMatchingValue(): void {
		$list = [['rel' => 'self', 'href' => 'https://a.example/users/alice'], ['rel' => 'http://webfinger.net/rel/profile-page', 'href' => 'https://a.example/@alice']];

		$this->assertSame($list[1], $this->tools->extractArray('rel', 'http://webfinger.net/rel/profile-page', $list));
	}

	public function testExtractArrayThrowsWhenNothingMatches(): void {
		$this->expectException(ArrayNotFoundException::class);

		$this->tools->extractArray('rel', 'other', [['rel' => 'self'], ['href' => 'x']]);
	}

	public static function typeProvider(): array {
		return [
			'null' => ['null', 'Null'],
			'string' => ['str', 'String'],
			'array' => ['list', 'Array'],
			'boolean' => ['true', 'Boolean'],
			'integer' => ['int', 'Integer'],
			'nested' => ['nested.deep.key', 'String'],
		];
	}

	#[DataProvider('typeProvider')]
	public function testTypeOfNamesTheType(string $key, string $expected): void {
		$this->assertSame($expected, $this->tools->typeOf($key, $this->sample()));
	}

	public function testTypeOfRecognisesSerializableObjects(): void {
		$this->assertSame('Serializable', $this->tools->typeOf('obj', ['obj' => new SimpleDataStore()]));
	}

	public function testTypeOfRejectsUnknownTypes(): void {
		$this->expectException(UnknownTypeException::class);

		$this->tools->typeOf('float', $this->sample());
	}

	public static function missingKeyProvider(): array {
		return [
			'missing' => ['missing'],
			'nested missing root' => ['none.key'],
			'nested missing leaf' => ['nested.none'],
			'nested through scalar' => ['str.key'],
		];
	}

	#[DataProvider('missingKeyProvider')]
	public function testTypeOfThrowsForMissingKeys(string $key): void {
		$this->expectException(ItemNotFoundException::class);

		$this->tools->typeOf($key, $this->sample());
	}

	public function testMustContainsAcceptsWhenAllKeysArePresent(): void {
		$this->tools->mustContains(['str', 'null'], $this->sample());

		$this->addToAssertionCount(1);
	}

	public function testMustContainsNamesTheMissingKey(): void {
		$this->expectException(MalformedArrayException::class);
		$this->expectExceptionMessage('missing key: nope');

		$this->tools->mustContains(['str', 'nope'], $this->sample());
	}

	public function testCleanArrayDropsEmptyStringsAndArraysOnly(): void {
		$cleaned = $this->tools->clean([
			'empty' => '',
			'none' => [],
			'zero' => 0,
			'false' => false,
			'null' => null,
			'str' => 'a',
			'list' => [1],
		]);

		$this->assertSame(['zero' => 0, 'false' => false, 'null' => null, 'str' => 'a', 'list' => [1]], $cleaned);
	}

	public function testGetFloatKeepsTheDecimals(): void {
		$this->assertSame(1.5, $this->tools->getFloat('float', $this->sample()));
		$this->assertSame(1.5, $this->tools->getFloat('k', ['k' => '1.5']));
	}

	public function testGetBoolFallsBackWhenADottedPathCrossesAScalar(): void {
		$this->assertTrue($this->tools->getBool('str.sub', $this->sample(), true));
		$this->assertFalse($this->tools->getBool('str.sub', $this->sample()));
	}
}
