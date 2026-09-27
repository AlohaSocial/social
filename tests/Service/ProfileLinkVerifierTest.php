<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\CacheDocumentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ProfileLinkVerifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ProfileLinkVerifierTest extends TestCase {
	private const ACTOR = 'https://cloud.example/apps/social/@alice';
	private const PROFILE = 'https://cloud.example/apps/social/@alice/profile';

	private CacheDocumentService|Stub $documents;
	private ConfigService|Stub $config;
	private ActorsRequest|MockObject $actorsRequest;
	private CacheActorsRequest|MockObject $cacheActorsRequest;
	private ProfileLinkVerifier $verifier;
	/** @var array<string, string> url => the page it serves */
	private array $pages = [];
	/** @var string[] every url fetched */
	private array $fetched = [];

	protected function setUp(): void {
		$this->documents = $this->createStub(CacheDocumentService::class);
		$this->documents->method('retrieveContent')->willReturnCallback(function (string $url): string {
			$this->fetched[] = $url;
			if (!isset($this->pages[$url])) {
				throw new \RuntimeException('unreachable');
			}
			return $this->pages[$url];
		});
		// the example hosts resolve to nothing, and a name that resolves to
		// nothing is refused as local, failing closed -- so the tests that
		// fetch run with the local network allowed, and the one that checks
		// the refusal builds its own verifier
		$this->config = $this->createStub(ConfigService::class);
		$this->config->method('isLocalNetworkAllowed')->willReturn(true);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->cacheActorsRequest = $this->createMock(CacheActorsRequest::class);
		$this->verifier = new ProfileLinkVerifier(
			$this->documents, $this->config, $this->actorsRequest, $this->cacheActorsRequest, new NullLogger()
		);
	}

	private function actor(array $fields = []): Person {
		$actor = new Person();
		$actor->setId(self::ACTOR);
		$actor->setUrl(self::PROFILE);
		$actor->setFields($fields);

		return $actor;
	}

	/** @return iterable<string, array{string, string}> */
	public static function values(): iterable {
		yield 'remote html' => ['<a href="https://alice.example/" rel="nofollow noopener">alice.example</a>', 'https://alice.example/'];
		yield 'entities decoded' => ['<a href="https://alice.example/?a=1&amp;b=2">x</a>', 'https://alice.example/?a=1&b=2'];
		yield 'bare url' => ['https://alice.example/about', 'https://alice.example/about'];
		yield 'url in text' => ['see https://alice.example/about for more', 'https://alice.example/about'];
		yield 'no link' => ['Berlin', ''];
		yield 'not a url' => ['<a href="javascript:alert(1)">x</a>', ''];
	}

	#[DataProvider('values')]
	public function testTheLinkIsReadOffTheValueHoweverItArrived(string $value, string $link): void {
		$this->assertSame($link, ProfileLinkVerifier::linkOf($value));
	}

	public function testAPageThatLinksBackWithRelMeVerifies(): void {
		$this->pages['https://alice.example/'] = '<html><body><p>hi</p><a rel="me" href="' . self::ACTOR . '">fedi</a></body></html>';

		$this->assertTrue($this->verifier->linksBack('https://alice.example/', $this->actor()));
	}

	public function testRelMeMayBeOneOfSeveralTokensAndPointAtTheProfilePage(): void {
		// `rel="nofollow me"`, the profile URL rather than the actor id, a
		// trailing slash and a different case in the host: all the same page
		$this->pages['https://alice.example/'] = '<link rel="ME nofollow" href="HTTPS://Cloud.Example/apps/social/@alice/profile/">';

		$this->assertTrue($this->verifier->linksBack('https://alice.example/', $this->actor()));
	}

	public function testAPageWithoutRelMeDoesNotVerify(): void {
		// a plain link to the profile is what anybody can put on any page
		$this->pages['https://alice.example/'] = '<a href="' . self::ACTOR . '">me</a> <a rel="me" href="https://somebody.else/">x</a>';

		$this->assertFalse($this->verifier->linksBack('https://alice.example/', $this->actor()));
	}

	public function testAnUnreachablePageDoesNotVerify(): void {
		$this->assertFalse($this->verifier->linksBack('https://gone.example/', $this->actor()));
	}

	public function testALocalAddressIsNeverFetched(): void {
		// a profile field is user input, and this would otherwise be a way to
		// make the server read its own network
		$config = $this->createStub(ConfigService::class);
		$config->method('isLocalNetworkAllowed')->willReturn(false);
		$verifier = new ProfileLinkVerifier($this->documents, $config, $this->actorsRequest, $this->cacheActorsRequest, new NullLogger());

		$this->assertFalse($verifier->linksBack('http://localhost/admin', $this->actor()));
		$this->assertFalse($verifier->linksBack('http://127.0.0.1:8080/', $this->actor()));
		$this->assertFalse($verifier->linksBack('ftp://alice.example/', $this->actor()));
		$this->assertSame([], $this->fetched);
	}

	public function testVerifyRecordsTheVerdictPerFieldAndWhenItLooked(): void {
		$this->pages['https://alice.example/'] = '<a rel="me" href="' . self::ACTOR . '">x</a>';
		$this->pages['https://blog.example/'] = '<p>nothing here</p>';
		$actor = $this->actor([
			['name' => 'Web', 'value' => '<a href="https://alice.example/">alice.example</a>'],
			['name' => 'Blog', 'value' => 'https://blog.example/'],
			['name' => 'City', 'value' => 'Berlin'],
		]);

		$this->assertTrue($this->verifier->verify($actor));

		$details = $actor->getDetailsAll();
		$this->assertSame(['<a href="https://alice.example/">alice.example</a>'], array_keys($details['fields_verified']));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $details['fields_verified']['<a href="https://alice.example/">alice.example</a>']);
		$this->assertEqualsWithDelta(time(), $details['fields_checked'], 5);
		// the city is not a link and was not fetched
		$this->assertSame(['https://alice.example/', 'https://blog.example/'], $this->fetched);

		// the tick shows on the entity, null where the page did not link back
		$account = $actor->exportAsLocal();
		$this->assertNotNull($account['fields'][0]['verified_at']);
		$this->assertNull($account['fields'][1]['verified_at']);
		$this->assertNull($account['fields'][2]['verified_at']);
	}

	public function testAVerdictStandsForADayBeforeThePageIsAskedAgain(): void {
		$actor = $this->actor([['name' => 'Web', 'value' => 'https://alice.example/']]);
		$actor->setDetailInt('fields_checked', time() - 3600);

		$this->assertFalse($this->verifier->verify($actor));
		$this->assertSame([], $this->fetched);

		$actor->setDetailInt('fields_checked', time() - ProfileLinkVerifier::RECHECK_SECONDS - 60);
		$this->assertTrue($this->verifier->verify($actor));
		$this->assertSame(['https://alice.example/'], $this->fetched);
	}

	public function testTheFirstVerifiedDateIsKeptWhileThePageKeepsLinkingBack(): void {
		$this->pages['https://alice.example/'] = '<a rel="me" href="' . self::ACTOR . '">x</a>';
		$actor = $this->actor([['name' => 'Web', 'value' => 'https://alice.example/']]);
		$actor->setDetailArray('fields_verified', ['https://alice.example/' => '2026-01-01T00:00:00+00:00']);

		$this->verifier->verify($actor, true);

		$this->assertSame('2026-01-01T00:00:00+00:00', $actor->getDetailsAll()['fields_verified']['https://alice.example/']);
	}

	public function testLocalAccountsAreCheckedThroughTheirCachedCopy(): void {
		$this->pages['https://alice.example/'] = '<a rel="me" href="' . self::ACTOR . '">x</a>';
		$local = $this->actor([['name' => 'Web', 'value' => 'https://alice.example/']]);
		$plain = new Person();
		$plain->setId('https://cloud.example/apps/social/@bob');
		$plain->setFields([['name' => 'City', 'value' => 'Berlin']]);
		$this->actorsRequest->method('getPage')->willReturnOnConsecutiveCalls([$plain, $local], []);
		$cached = $this->actor();
		$this->cacheActorsRequest->method('getFromId')->with(self::ACTOR)->willReturn($cached);
		$this->cacheActorsRequest->expects($this->once())->method('updateDetails')->with($this->identicalTo($cached));

		$this->assertSame(1, $this->verifier->verifyLocalActors());
		$this->assertArrayHasKey('https://alice.example/', $cached->getDetailsAll()['fields_verified']);
	}

	/**
	 * The walk reads a page of the accounts that carry a link, not the whole
	 * table, and the next pass carries on where this one stopped.
	 */
	public function testTheLocalWalkPagesAndRemembersWhereItStopped(): void {
		$stored = ['profile_link_cursor' => 'before'];
		$this->config->method('getAppValue')->willReturnCallback(fn (string $key): string => $stored[$key] ?? '');
		$this->config->method('setAppValue')->willReturnCallback(function (string $key, string $value) use (&$stored): void {
			$stored[$key] = $value;
		});

		$accounts = [];
		for ($i = 0; $i < 3; $i++) {
			$this->pages['https://site' . $i . '.example/'] = '<a rel="me" href="https://cloud.example/apps/social/@u' . $i . '">x</a>';
			$account = new Person();
			$account->setId('https://cloud.example/apps/social/@u' . $i);
			$account->setFields([['name' => 'Web', 'value' => 'https://site' . $i . '.example/']]);
			$accounts[] = $account;
		}
		$this->cacheActorsRequest->method('getFromId')->willReturnCallback(function (string $id): Person {
			$cached = new Person();
			$cached->setId($id);

			return $cached;
		});

		$this->actorsRequest->expects($this->never())->method('getAll');
		$this->actorsRequest->expects($this->once())->method('getPage')
			->with(ProfileLinkVerifier::LOCAL_PAGE, 'before', true)
			->willReturn($accounts);

		$this->assertSame(2, $this->verifier->verifyLocalActors(2));
		$this->assertSame(md5('https://cloud.example/apps/social/@u1'), $stored['profile_link_cursor']);
	}

	public function testTheWalkStartsOverAtTheEndOfTheTable(): void {
		$stored = ['profile_link_cursor' => 'somewhere'];
		$this->config->method('getAppValue')->willReturnCallback(fn (string $key): string => $stored[$key] ?? '');
		$this->config->method('setAppValue')->willReturnCallback(function (string $key, string $value) use (&$stored): void {
			$stored[$key] = $value;
		});
		$this->actorsRequest->method('getPage')->willReturn([]);

		$this->assertSame(0, $this->verifier->verifyLocalActors());
		$this->assertSame('', $stored['profile_link_cursor']);
	}
}
