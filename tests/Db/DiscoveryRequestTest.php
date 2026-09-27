<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The opt-in the directory rests on, and what the second query does with a
 * page the first one decided.
 *
 * `discoverable` is a predicate of the deciding statement and cannot be run
 * without a database, so it is read out of the source the way
 * `SchemaConventionsTest` reads index names: what is being defended against is
 * a later change that quietly stops applying it, and that is visible in the
 * source whether or not a server is available to run it.
 */
#[AllowMockObjectsWithoutExpectations]
class DiscoveryRequestTest extends TestCase {
	private function source(string $file): string {
		return (string)file_get_contents(__DIR__ . '/../../lib/Db/' . $file);
	}

	private function body(string $method): string {
		$reflected = new ReflectionMethod(DiscoveryRequest::class, $method);
		$lines = file((string)$reflected->getFileName()) ?: [];

		return implode('', array_slice(
			$lines,
			$reflected->getStartLine() - 1,
			$reflected->getEndLine() - $reflected->getStartLine() + 1
		));
	}

	/**
	 * The flag was stored and federated by `Version1000Date20260911000002`
	 * and read by nothing at all: a user could turn it off and it changed
	 * nothing, because there was no listing to be kept out of.
	 */
	public function testTheDirectoryQueryConstrainsTheDiscoverableFlag(): void {
		$source = $this->source('DiscoveryRequestBuilder.php');

		$this->assertMatchesRegularExpression(
			"/getDiscoverablePrimsSelectSql.*?eq\(\s*'a\.discoverable'/s",
			$source,
			'the directory must constrain social_actor.discoverable in the statement'
		);
	}

	/**
	 * The flag is applied where the rows are chosen, so an account that did
	 * not opt in is never read and then dropped — a filter applied afterwards
	 * is one forgotten `if` away from listing everybody.
	 */
	public function testEveryDirectoryPageIsChosenByThatQuery(): void {
		$this->assertStringContainsString(
			'getDiscoverablePrimsSelectSql()',
			$this->body('directoryPrims'),
			'directoryPrims() must build on the query that reads the opt-in flag'
		);
	}

	/** The suggestions fall back to the directory, so they inherit the opt-in. */
	public function testTheLocallyActiveFallbackIsTheDirectory(): void {
		$this->assertStringContainsString(
			'$this->directoryPrims(',
			$this->body('activeLocalPrims'),
			'suggesting a local account must go through the same opt-in'
		);
	}

	/** Accepted follows only: a pending request says nothing about trust. */
	public function testTheGraphWalkCountsAcceptedFollowsOnly(): void {
		$this->assertMatchesRegularExpression(
			"/getFollowedPrimsSelectSql.*?eq\(\s*\\\$alias \. '\.accepted'/s",
			$this->source('DiscoveryRequestBuilder.php')
		);
	}

	/**
	 * @return DiscoveryRequest&MockObject the store with only its two queries
	 *                                     replaced; the constructor takes an
	 *                                     IDBConnection, and mocking one needs
	 *                                     DBAL, which this suite cannot load
	 */
	private function request(array $actors) {
		$request = $this->getMockBuilder(DiscoveryRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetchActors'])
			->getMock();

		$request->method('fetchActors')->willReturn($actors);

		return $request;
	}

	private function person(string $handle): Person {
		$person = new Person();
		$person->setId('https://cloud.example/users/' . $handle);

		return $person;
	}

	/**
	 * The order is restored in PHP because there is no portable way to say "in
	 * the order of that IN list", and the order is the answer: it is what the
	 * deciding query ranked.
	 */
	public function testTheActorsComeBackInTheOrderTheyWereDecided(): void {
		$request = $this->request([$this->person('bob'), $this->person('alice')]);

		$actors = $request->actorsByPrims([
			md5('https://cloud.example/users/alice'),
			md5('https://cloud.example/users/bob'),
		]);

		$this->assertSame(
			['https://cloud.example/users/alice', 'https://cloud.example/users/bob'],
			array_map(static fn (Person $p): string => $p->getId(), $actors)
		);
	}

	/** A chosen account with no cached profile drops out rather than arriving half-filled. */
	public function testAnAccountWithNoCachedProfileDropsOutOfThePage(): void {
		$request = $this->request([$this->person('alice')]);

		$actors = $request->actorsByPrims([
			md5('https://cloud.example/users/alice'),
			md5('https://cloud.example/users/ghost'),
		]);

		$this->assertCount(1, $actors);
	}

	public function testAnEmptyPageAsksTheDatabaseNothing(): void {
		$request = $this->getMockBuilder(DiscoveryRequest::class)
			->disableOriginalConstructor()
			->onlyMethods(['fetchActors'])
			->getMock();
		$request->expects($this->never())->method('fetchActors');

		$this->assertSame([], $request->actorsByPrims([]));
	}
}
