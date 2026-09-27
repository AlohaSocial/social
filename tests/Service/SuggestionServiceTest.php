<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\DiscoveryRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActorRelation;
use OCA\Social\Model\Client\Suggestion;
use OCA\Social\Service\AccountRelationService;
use OCA\Social\Service\ColleagueService;
use OCA\Social\Service\DirectoryService;
use OCA\Social\Service\SuggestionService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Who may not be suggested.
 *
 * That is the half of this feature that can be wrong in a way the user
 * notices: a suggestion to follow somebody you blocked is the instance
 * overruling the one decision you made about them, and a suggestion to follow
 * somebody you already follow is the instance admitting it did not look.
 *
 * Every exclusion is checked against both halves of the list — the follow
 * graph and the locally active fallback — because they are gathered by
 * different queries and a filter applied to only one of them would let the
 * same account back in through the other.
 */
class SuggestionServiceTest extends TestCase {
	private const VIEWER = 'https://cloud.example/users/alice';

	private DiscoveryRequest|Stub $discoveryRequest;
	private SuggestionService $service;

	/** @var string[] actor ids the graph walk offers */
	private array $friends = [];
	/** @var string[] actor ids the locally-active fallback offers */
	private array $active = [];
	/** @var string[] actor ids the viewer already follows */
	private array $follows = [];
	/** @var string[] actor ids blocked, muted, or blocking the viewer */
	private array $related = [];
	/** @var string[] actor ids a moderator has decided about */
	private array $moderated = [];
	/** @var string[] actor ids named in the fediverse field of local profiles */
	private array $colleagues = [];
	/** @var string the user id the colleague lookup was told to leave out */
	private string $colleaguesExcept = '';
	/** @var string[][] the prim lists the graph walk and the fallback were asked for */
	private array $asked = [];

	protected function setUp(): void {
		$this->discoveryRequest = $this->createStub(DiscoveryRequest::class);

		$this->discoveryRequest->method('friendsOfFriendsPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->friends));
		$this->discoveryRequest->method('activeLocalPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->active));
		$this->discoveryRequest->method('followedPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->follows));
		$this->discoveryRequest->method('relatedPrims')
			->willReturnCallback(fn (): array => array_map('md5', $this->related));
		$this->discoveryRequest->method('actorsByPrims')
			->willReturnCallback(function (array $prims): array {
				$this->asked[] = $prims;

				return array_map(static function (string $prim): Person {
					$person = new Person();
					$person->setId('actor:' . $prim);

					return $person;
				}, $prims);
			});

		$directoryService = $this->createStub(DirectoryService::class);
		$directoryService->method('withoutModerated')
			->willReturnCallback(function (array $prims): array {
				$moderated = array_map('md5', $this->moderated);

				return array_values(array_filter(
					$prims, static fn (string $prim): bool => !in_array($prim, $moderated, true)
				));
			});

		$colleagueService = $this->createStub(ColleagueService::class);
		$colleagueService->method('accounts')
			->willReturnCallback(function (string $exceptUserId): array {
				$this->colleaguesExcept = $exceptUserId;

				return array_map(static function (string $id): Person {
					$person = new Person();
					$person->setId($id);

					return $person;
				}, $this->colleagues);
			});

		$viewer = new Person();
		$viewer->setId(self::VIEWER);
		$viewer->setUserId('alice');
		$actorsRequest = $this->createStub(ActorsRequest::class);
		$actorsRequest->method('getFromId')->willReturn($viewer);

		$this->service = new SuggestionService(
			$this->discoveryRequest, $directoryService, $colleagueService, $actorsRequest
		);
	}

	/** @return string[] the actor ids suggested, in order */
	private function suggested(int $limit = SuggestionService::LIMIT): array {
		return array_map(
			static fn (Suggestion $s): string => $s->getAccount()->getId(),
			$this->service->suggestions(self::VIEWER, $limit)
		);
	}

	private function id(string $handle): string {
		return 'actor:' . md5($handle);
	}

	public function testTheGraphWalkIsTheFirstHalfOfTheList(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	public function testAnAccountAlreadyFollowedIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['bob', 'carol'];
		$this->follows = ['bob'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	public function testABlockedOrMutedAccountIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];
		$this->related = ['bob', 'carol'];

		$this->assertSame([], $this->suggested());
	}

	/** All three directions of `social_actor_relation` are asked for. */
	public function testBlocksMutesBeingBlockedAndDismissalsAreAllAskedFor(): void {
		$asked = null;
		$this->discoveryRequest = $this->createStub(DiscoveryRequest::class);
		$this->discoveryRequest->method('relatedPrims')
			->willReturnCallback(function (string $viewerId, array $types) use (&$asked): array {
				$asked = $types;

				return [];
			});

		$directoryService = $this->createStub(DirectoryService::class);
		$directoryService->method('withoutModerated')->willReturnArgument(0);

		$colleagues = $this->createStub(ColleagueService::class);
		$colleagues->method('accounts')->willReturn([]);
		(new SuggestionService(
			$this->discoveryRequest, $directoryService, $colleagues, $this->createStub(ActorsRequest::class)
		))->suggestions(self::VIEWER, SuggestionService::LIMIT);

		$this->assertSame(
			[
				ActorRelation::TYPE_BLOCK,
				ActorRelation::TYPE_MUTE,
				ActorRelation::TYPE_BLOCKED_BY,
				AccountRelationService::TYPE_SUGGESTION_DISMISSED,
			],
			$asked
		);
	}

	public function testTheViewerIsNeverSuggestedToThemselves(): void {
		$this->friends = [self::VIEWER];
		$this->active = [self::VIEWER, 'carol'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	public function testAModeratedAccountIsNeverSuggested(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];
		$this->moderated = ['bob'];

		$this->assertSame([$this->id('carol')], $this->suggested());
	}

	/** The fallback fills what the graph left, and never duplicates it. */
	public function testTheFallbackDoesNotRepeatTheGraph(): void {
		$this->friends = ['bob'];
		$this->active = ['bob', 'carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	public function testTheFallbackIsNotAskedForMoreThanTheListNeeds(): void {
		$this->friends = ['bob', 'carol'];
		$this->active = ['dave', 'erin'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested(2));
	}

	public function testANewAccountWithNoGraphStillGetsSuggestions(): void {
		$this->active = ['bob', 'carol'];

		$this->assertSame([$this->id('bob'), $this->id('carol')], $this->suggested());
	}

	/** Each half says where it came from, in both the old field and the new. */
	public function testEachSuggestionSaysWhereItCameFrom(): void {
		$this->friends = ['bob'];
		$this->active = ['carol'];

		$suggestions = $this->service->suggestions(self::VIEWER, SuggestionService::LIMIT);

		$this->assertSame(
			['friends_of_friends'], $suggestions[0]->jsonSerialize()['sources']
		);
		$this->assertSame('past_interactions', $suggestions[0]->jsonSerialize()['source']);
		$this->assertSame(['most_interactions'], $suggestions[1]->jsonSerialize()['sources']);
		$this->assertSame('global', $suggestions[1]->jsonSerialize()['source']);
	}

	public function testTheEntityIsASuggestion(): void {
		$this->active = ['bob'];

		$suggestion = $this->service->suggestions(self::VIEWER, SuggestionService::LIMIT)[0];

		$this->assertSame(
			['source', 'sources', 'account'], array_keys($suggestion->jsonSerialize())
		);
	}

	public function testTheListIsCappedAtWhatMastodonCapsItAt(): void {
		$this->active = array_map(static fn (int $i): string => 'user' . $i, range(1, 200));

		$this->assertCount(
			SuggestionService::MAX_LIMIT, $this->service->suggestions(self::VIEWER, 500)
		);
	}

	// the people who share this Nextcloud

	/**
	 * Between the graph and the fallback: a colleague is a better answer than
	 * somebody who merely posted recently, and a worse one than somebody the
	 * viewer's own follows vouch for.
	 */
	public function testColleaguesComeAfterTheGraphAndBeforeTheFallback(): void {
		$this->friends = ['friend'];
		$this->colleagues = ['colleague'];
		$this->active = ['stranger'];

		$this->assertSame(
			[$this->id('friend'), $this->id('colleague'), $this->id('stranger')],
			$this->suggested()
		);
	}

	public function testAColleagueIsLabelledAsOne(): void {
		$this->colleagues = ['colleague'];

		$sources = array_map(
			static fn (Suggestion $s): string => $s->getSource(),
			$this->service->suggestions(self::VIEWER, 10)
		);

		$this->assertSame([Suggestion::SOURCE_COLLEAGUES], $sources);
	}

	/** The same exclusions as everybody else: followed, blocked, muted, self. */
	public function testAColleagueTheViewerAlreadyFollowsIsNotSuggested(): void {
		$this->colleagues = ['followed', 'new'];
		$this->follows = ['followed'];

		$this->assertSame([$this->id('new')], $this->suggested());
	}

	public function testAColleagueTheViewerBlockedIsNotSuggested(): void {
		$this->colleagues = ['blocked', 'new'];
		$this->related = ['blocked'];

		$this->assertSame([$this->id('new')], $this->suggested());
	}

	/** Somebody the graph already offered is not offered a second time. */
	public function testAColleagueAlreadyInTheGraphIsListedOnce(): void {
		$this->friends = ['both'];
		$this->colleagues = ['both'];

		$suggestions = $this->service->suggestions(self::VIEWER, 10);

		$this->assertCount(1, $suggestions);
		$this->assertSame(Suggestion::SOURCE_FRIENDS, $suggestions[0]->getSource());
	}

	public function testColleaguesFillOnlyTheSlotsTheGraphLeft(): void {
		$this->friends = ['f1', 'f2'];
		$this->colleagues = ['c1', 'c2', 'c3'];

		$this->assertSame(
			[$this->id('f1'), $this->id('f2'), $this->id('c1')],
			$this->suggested(3)
		);
	}

	/** The viewer's own profile names them; that is not a suggestion. */
	public function testTheViewersOwnProfileIsLeftOutByTheirUserId(): void {
		$this->service->suggestions(self::VIEWER, 10);

		$this->assertSame('alice', $this->colleaguesExcept);
	}
}
