<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Migration;

use OCA\Social\Migration\Version1000Date20221118000002;
use OCA\Social\Migration\Version1000Date20261011500100;
use OCA\Social\Migration\Version1000Date20261114000001;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * That an instance upgraded from a published release ends up with the schema
 * a fresh install has.
 *
 * `schema-<release>.json` holds what that release left in the database: the
 * migrations it had recorded as run, and the shape its own `lib/Migration`
 * builds, replayed against {@see FakeSchema} from the release tag (the steps'
 * namespace renamed so they load beside the current ones). 0.10.0 stands for
 * every release before 0.23 — 0.6.0 to 0.10.0 build the same schema — and
 * 0.24.1 for 0.23.0, which builds the same one as it.
 *
 * The upgrade is what Nextcloud does: every current step the release had not
 * recorded, in version order, onto that schema. The squash adds only what is
 * missing, so whatever the history changed or dropped after creating it has
 * to be done by the steps around it; a difference here is a column, index or
 * key the upgraded instance has and a fresh one does not, or the other way.
 */
class UpgradeFromReleasesTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function releases(): array {
		return [
			'0.10.0 (and every release before 0.23)' => ['0.10.0'],
			'0.24.1 (and 0.23.0)' => ['0.24.1'],
		];
	}

	/** @return array{release: string, migrations: list<string>, schema: array<string, array{columns: array, indexes: array, primary: array}>} */
	private function release(string $release): array {
		return json_decode((string)file_get_contents(__DIR__ . '/schema-' . $release . '.json'), true);
	}

	/**
	 * Something of the right type for every interface a step is constructed
	 * with; only the schema half runs, so none of it is called.
	 *
	 * @return array<string, object>
	 */
	private function stand(): array {
		$stand = [];
		foreach (MigrationReplay::steps() as $class) {
			foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
				$type = $parameter->getType();
				if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || $type->getName() === IDBConnection::class) {
					continue;
				}
				if (interface_exists($type->getName())) {
					$stand[$type->getName()] ??= $this->createStub($type->getName());
				}
			}
		}

		return $stand;
	}

	/** @return list<string> the current steps the release had not run */
	private function pending(string $release): array {
		$recorded = array_map(
			static fn (string $name): string => 'OCA\\Social\\Migration\\' . $name,
			$this->release($release)['migrations']
		);

		return array_values(array_diff(MigrationReplay::steps(), $recorded));
	}

	private function upgraded(string $release): FakeSchema {
		return MigrationReplay::run(
			$this->pending($release),
			$this->stand(),
			FakeSchema::fromShape($this->release($release)['schema'])
		);
	}

	#[DataProvider('releases')]
	public function testAnUpgradedInstanceHasTheSchemaOfAFreshOne(string $release): void {
		$this->assertSame(
			MigrationReplay::everything($this->stand())->shape(),
			$this->upgraded($release)->shape()
		);
	}

	/**
	 * An upgrade that stopped half way is run again, and the steps that make
	 * up the difference must find their work done the second time. The squash
	 * still creates `social_portfolio`, so the step that drops it runs again
	 * after it, as it would on the instance.
	 */
	#[DataProvider('releases')]
	public function testRunningTheRepairsAgainChangesNothing(string $release): void {
		$schema = $this->upgraded($release);
		$once = $schema->shape();

		MigrationReplay::run(
			[Version1000Date20221118000002::class, Version1000Date20261011500100::class, Version1000Date20261114000001::class],
			$this->stand(),
			$schema
		);

		$this->assertSame($once, $schema->shape());
	}

	/**
	 * The squash itself, not a later step, widens the client secret: the
	 * secrets are hashed into it by `Version1000Date20261008000100`, which
	 * runs before any step numbered after it.
	 */
	public function testTheSquashWidensTheClientSecretBeforeTheSecretsAreHashed(): void {
		$schema = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			$this->stand(),
			FakeSchema::fromShape($this->release('0.10.0')['schema'])
		);

		$this->assertSame(127, $schema->getTable('social_client')->getColumn('app_client_secret')->getLength());
	}

	/**
	 * The `id` the squash adds to the two recipient tables is their primary
	 * key in the same change: MySQL refuses an autoincrement column that is
	 * not a key, and the upgrade would stop there.
	 */
	public function testTheSquashMakesTheIdItAddsThePrimaryKey(): void {
		$shape = MigrationReplay::run(
			[Version1000Date20221118000002::class],
			$this->stand(),
			FakeSchema::fromShape($this->release('0.10.0')['schema'])
		)->shape();

		$this->assertSame(['id'], $shape['social_stream_dest']['primary']);
		$this->assertSame(['id'], $shape['social_stream_tag']['primary']);
	}

	/**
	 * The old unique index on `social_stream.nid` was created without a name,
	 * so the step finds it by its columns, under whatever name the database
	 * gave it, and leaves the primary key and the unique `id_prim` alone.
	 */
	public function testTheUnnamedDuplicateOfTheStreamKeyIsDropped(): void {
		$schema = FakeSchema::fromShape($this->release('0.10.0')['schema']);
		$table = $schema->getTable('social_stream');
		$this->assertTrue($table->hasIndex('UNIQ_EEA2DCC043E84A97'), 'the name Doctrine gives it here, without a table prefix');

		MigrationReplay::run([Version1000Date20261011500100::class], [], $schema);

		$this->assertFalse($table->hasIndex('UNIQ_EEA2DCC043E84A97'));
		$this->assertFalse($table->hasIndex('ipoha'));
		$unique = array_values(array_filter(
			$schema->shape()['social_stream']['indexes'],
			static fn (array $index): bool => $index['unique']
		));
		$this->assertSame([['columns' => ['id_prim'], 'name' => null, 'unique' => true]], $unique);
		$this->assertSame(['nid'], $schema->shape()['social_stream']['primary']);
	}

	/** On a fresh install it asks for nothing at all. */
	public function testTheRepairStepIsANoOpOnAFreshInstall(): void {
		$schema = MigrationReplay::run(
			array_values(array_diff(MigrationReplay::steps(), [Version1000Date20261011500100::class])),
			$this->stand()
		);

		$step = new Version1000Date20261011500100();
		$output = $this->createStub(\OCP\Migration\IOutput::class);

		$this->assertNull($step->changeSchema($output, static fn (): FakeSchema => $schema, []));
	}
}
