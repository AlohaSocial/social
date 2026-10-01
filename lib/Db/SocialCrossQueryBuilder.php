<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\AP;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Class SocialCrossQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialCrossQueryBuilder extends SocialCoreQueryBuilder {
	/**
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 */
	public function selectDestFollowing(string $aliasDest = 'sd', string $aliasFollowing = 'f') {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		if ($aliasDest !== '') {
			$this->from(CoreRequestBuilder::TABLE_STREAM_DEST, $aliasDest);
		}
		if ($aliasFollowing !== '') {
			$this->from(CoreRequestBuilder::TABLE_FOLLOWS, $aliasFollowing);
		}
	}

	/**
	 * The viewer's accepted follows as a LEFT JOIN. A plain `FROM social_follow`
	 * next to the stream table is a cartesian product: with an *empty* follow
	 * table it collapses every row — so on a fresh instance even the public and
	 * hashtag timelines came back empty until somebody followed someone. Bound
	 * as a join, an OR-branch that does not use the follows (public, DM) is
	 * unaffected by whether any exist.
	 */
	public function leftJoinFollowing(string $alias = 'f'): void {
		if ($this->getType() !== self::SELECT || !$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_FOLLOWS, $alias,
			$expr->andX(
				$expr->eq(
					$alias . '.actor_id_prim',
					$this->createNamedParameter($this->prim($this->getViewer()->getId()))
				),
				$expr->eq($alias . '.accepted', $this->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			)
		);
	}

	/**
	 * @param string $alias
	 * @param string $link
	 */
	public function linkToStreamTags(string $alias = 'st', string $link = '') {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$this->from(CoreRequestBuilder::TABLE_STREAM_TAGS, $alias);
		if ($link !== '') {
			$expr = $this->expr();
			$this->andWhere($expr->eq($alias . '.stream_id', $link));
		}
	}

	/**
	 * The cached actor, joined but not selected.
	 *
	 * A query that only wants to *constrain* on the author — which is what
	 * every page-selection query does, since it ends up projecting a list of
	 * nids — pays for the twenty columns `linkToCacheActors()` appends,
	 * `source`, `details`, `summary` and `public_key` among them. Those go
	 * through the `SELECT DISTINCT` the recipient join forces, so the database
	 * sorts or hashes several kilobytes a row to deduplicate integers.
	 *
	 * Measured on the home timeline of an instance with 22,000 posts: 94.5 ms
	 * with the columns, 56.2 ms without them, everything else equal.
	 *
	 * @param string $alias the alias to join under
	 * @param string $link what the actor's `id_prim` is compared with
	 * @param bool $innerJoin false for a left join
	 */
	public function joinCacheActors(string $alias = 'ca', string $link = '', bool $innerJoin = true): void {
		$this->linkToCacheActors($alias, $link, $innerJoin, false);
	}

	/**
	 * @param string $alias
	 * @param string $link
	 * @param bool $innerJoin
	 * @param bool $select whether the actor's columns are wanted in the result
	 */
	public function linkToCacheActors(
		string $alias = 'ca',
		string $link = '',
		bool $innerJoin = true,
		bool $select = true,
	) {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);

		$expr = $this->expr();
		if ($link !== '') {
			if ($innerJoin) {
				$this->innerJoin(
					$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf,
					$expr->eq('ca.id_prim', $link)
				);
			} else {
				$this->leftJoin(
					$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf,
					$expr->eq('ca.id_prim', $link)
				);
			}
		} else {
			$this->from(CoreRequestBuilder::TABLE_CACHE_ACTORS, $pf);
		}

		if (!$select) {
			return;
		}

		$this->selectAlias($pf . '.id', 'ca_id')
			->selectAlias($pf . '.nid', 'ca_nid')
			->selectAlias($pf . '.type', 'ca_type')
			->selectAlias($pf . '.icon_id', 'ca_icon_id')
			->selectAlias($pf . '.account', 'ca_account')
			->selectAlias($pf . '.following', 'ca_following')
			->selectAlias($pf . '.followers', 'ca_followers')
			->selectAlias($pf . '.inbox', 'ca_inbox')
			->selectAlias($pf . '.shared_inbox', 'ca_shared_inbox')
			->selectAlias($pf . '.outbox', 'ca_outbox')
			->selectAlias($pf . '.featured', 'ca_featured')
			->selectAlias($pf . '.url', 'ca_url')
			->selectAlias($pf . '.preferred_username', 'ca_preferred_username')
			->selectAlias($pf . '.name', 'ca_name')
			->selectAlias($pf . '.summary', 'ca_summary')
			->selectAlias($pf . '.public_key', 'ca_public_key')
			->selectAlias($pf . '.source', 'ca_source')
			->selectAlias($pf . '.details', 'ca_details')
			->selectAlias($pf . '.creation', 'ca_creation')
			->selectAlias($pf . '.local', 'ca_local')
			// the counters the increments move, laid over `details` by
			// `Person::importFromDatabase()`; an author beside a post is read
			// through here, so without them a profile opened from a timeline
			// showed a different number from the same profile opened directly
			->selectAlias($pf . '.count_followers', 'ca_count_followers')
			->selectAlias($pf . '.count_following', 'ca_count_following')
			->selectAlias($pf . '.count_posts', 'ca_count_posts');

		$this->leftJoinCacheDocuments('icon_id', $pf, 'ca_cd_', 'cacd');
	}

	/**
	 * @param array $data
	 * @param string $prefix
	 *
	 * @return Stream
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinStream(
		array $data,
		string $prefix = '',
		int $exportFormat = 0,
	): Stream {
		$new = [];
		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
			}
		}

		if (($new['nid'] ?? '') === '') {
			throw new InvalidResourceException();
		}

		$stream = new Stream();
		$stream->importFromDatabase($new);
		$stream->setExportFormat($exportFormat);

		$actor = $this->parseLeftJoinCacheActors($data, $prefix . 'ca_', $exportFormat);
		$stream->setActor($actor);

		return $stream;
	}

	/**
	 * @param array $data
	 *
	 * @return Person
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheActors(
		array $data,
		string $prefix = '',
		int $exportFormat = 0,
	): Person {
		$new = [];

		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
			}
		}

		$actor = new Person();
		$actor->importFromDatabase($new);
		$actor->setExportFormat($exportFormat);

		if (!AP::instance()->isActor($actor)) {
			throw new InvalidResourceException('actor not actor');
		}

		try {
			$icon = $this->parseLeftJoinCacheDocuments($data, $prefix);
			$actor->setIcon($icon);
			// the address of the picture is built per row from the joined
			// document: the thumbnail where one has been made, the full copy
			// until then, and nothing at all while it is still being fetched
			$uuid = ($icon->getResizedCopy() === '') ? $icon->getLocalCopy() : $icon->getResizedCopy();
			if ($uuid !== '') {
				$actor->setAvatar(
					$this->urlGenerator->linkToRouteAbsolute(
						'social.MediaApi.mediaOpen',
						['uuid' => $uuid]
					)
				);
			}
		} catch (InvalidResourceException $e) {
		}

		return $actor;
	}

	/**
	 * @param string $linkField
	 * @param string $linkAlias
	 */
	public function leftJoinCacheDocuments(
		string $linkField,
		string $linkAlias = '',
		string $prefix = 'cd_',
		string $alias = 'cd',
	) {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$expr = $this->expr();
		$pf = (($linkAlias === '') ? $this->getDefaultSelectAlias() : $linkAlias);

		$this->selectAlias($alias . '.id', $prefix . 'id')
			->selectAlias($alias . '.type', $prefix . 'type')
			->selectAlias($alias . '.mime_type', $prefix . 'mime_type')
			->selectAlias($alias . '.media_type', $prefix . 'media_type')
			->selectAlias($alias . '.url', $prefix . 'url')
			->selectAlias($alias . '.local_copy', $prefix . 'local_copy')
			->selectAlias($alias . '.resized_copy', $prefix . 'resized_copy')
			->selectAlias($alias . '.caching', $prefix . 'caching')
			->selectAlias($alias . '.public', $prefix . 'public')
			->selectAlias($alias . '.error', $prefix . 'error')
			->selectAlias($alias . '.creation', $prefix . 'creation')
			->leftJoin(
				$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_CACHE_DOCUMENTS, $alias,
				$expr->eq($pf . '.' . $linkField, $alias . '.id_prim')
			);
	}

	/**
	 * @param array $data
	 *
	 * @return Document
	 * @throws InvalidResourceException
	 */
	public function parseLeftJoinCacheDocuments(array $data, string $prefix = ''): Document {
		$new = [];
		$prefix .= 'cd_';

		foreach ($data as $k => $v) {
			if (str_starts_with($k, $prefix)) {
				$new[substr($k, strlen($prefix))] = $v;
			}
		}

		$document = new Document();
		$document->importFromDatabase($new);

		if ($document->getType() !== Image::TYPE) {
			throw new InvalidResourceException();
		}

		return $document;
	}

	/**
	 * The status a row points at — the post a boost repeats, the post a
	 * notification is about — joined so it can be exported alongside.
	 *
	 * The object is joined only when the viewer may see it: a boost carries
	 * the audience of the booster, not of the post, so a remote Announce of a
	 * followers-only status would otherwise hand that status to everyone the
	 * booster reaches. When the viewer is not entitled to it the `os_` columns
	 * come back empty, which reads as "no object": the row stays, its content
	 * does not.
	 *
	 * @param string $alias
	 */
	public function leftJoinObjectStatus(
		string $link = 'object_id_prim',
		string $alias = '',
		string $leftAlias = 'os',
	) {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias) . '.';

		foreach (CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_STREAM] as $field) {
			$this->selectAlias($leftAlias . '.' . $field, 'os_' . $field);
		}

		$this->leftJoin(
			$this->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_STREAM,
			$leftAlias,
			$this->expr()->andX(
				$this->expr()->eq($pf . $link, $leftAlias . '.id_prim'),
				$this->exprVisibleToViewer($leftAlias)
			)
		);

		$this->leftJoinCacheActor(
			'attributed_to_prim',
			$leftAlias,
			'osca',
			'os_'
		);
	}

	/**
	 * The rule limitToViewer() applies to the rows of a request, expressed
	 * against another alias: addressed to the public collection (public and
	 * unlisted), written by the viewer, addressed to the viewer, or written by
	 * an account the viewer follows.
	 *
	 * Correlated EXISTS rather than joins: the dest and follow tables are
	 * already in the request under their own aliases, and a second FROM entry
	 * for either is a cartesian product (see leftJoinFollowing()) — and a join
	 * condition cannot be moved to the WHERE without dropping the whole row.
	 */
	private function exprVisibleToViewer(string $alias): ICompositeExpression {
		$dest = $this->getTableName(CoreRequestBuilder::TABLE_STREAM_DEST);
		// These stay `IParameter` objects rather than strings: the expression
		// builder passes a parameter through untouched and quotes a string as a
		// column name, so `eq()` on the cast form asks the database for a column
		// called `:dcValue5`. Only the raw SQL fragments below take the string.
		$recipient = $this->createNamedParameter('recipient');
		$public = $this->createNamedParameter($this->prim(Stream::CONTEXT_PUBLIC));

		$conditions = [
			'EXISTS (SELECT 1 FROM ' . $dest . ' vdp WHERE vdp.stream_id = ' . $alias . '.id_prim'
			. ' AND vdp.type = ' . (string)$recipient . ' AND vdp.actor_id = ' . (string)$public . ')',
		];

		if ($this->hasViewer()) {
			$follows = $this->getTableName(CoreRequestBuilder::TABLE_FOLLOWS);
			$viewer = $this->createNamedParameter($this->prim($this->getViewer()->getId()));

			$conditions[] = $this->expr()->eq($alias . '.attributed_to_prim', $viewer);
			$conditions[] = 'EXISTS (SELECT 1 FROM ' . $dest . ' vdv WHERE vdv.stream_id = ' . $alias
				. '.id_prim AND vdv.actor_id = ' . (string)$viewer . ')';
			$conditions[] = 'EXISTS (SELECT 1 FROM ' . $dest . ' vdf, ' . $follows . ' vff'
				. ' WHERE vdf.stream_id = ' . $alias . '.id_prim AND vdf.type = ' . (string)$recipient
				. ' AND vff.follow_id_prim = vdf.actor_id AND vff.actor_id_prim = ' . (string)$viewer
				// quoted literal: `accepted` is a boolean column on PostgreSQL, an int elsewhere
				. " AND vff.accepted = '1')";
		}

		return $this->expr()->orX(...$conditions);
	}

	/**
	 * @param string $link
	 * @param string $alias
	 * @param string $leftAlias
	 * @param string $prefix
	 * @param Person|null $author
	 */
	protected function leftJoinCacheActor(
		string $link = 'attributed_to_prim',
		string $alias = '',
		string $leftAlias = 'ca',
		string $prefix = '',
		?Person $author = null,
	) {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);

		foreach (CoreRequestBuilder::$tables[CoreRequestBuilder::TABLE_CACHE_ACTORS] as $field) {
			$this->selectAlias($leftAlias . '.' . $field, $prefix . 'ca_' . $field);
		}

		$this->leftJoin(
			$this->getDefaultSelectAlias(),
			CoreRequestBuilder::TABLE_CACHE_ACTORS,
			$leftAlias,
			$this->expr()->eq($pf . '.' . $link, $leftAlias . '.id_prim')
		);

		$this->leftJoinCacheDocuments(
			'icon_id',
			$leftAlias,
			$prefix . 'ca_cd_',
			$leftAlias . 'cacd'
		);
	}

	/**
	 * @param string $alias
	 */
	public function leftJoinFollowStatus(string $alias = 'fs') {
		if ($this->getType() !== self::SELECT || !$this->hasViewer()) {
			return;
		}

		$expr = $this->expr();
		$actor = $this->getViewer();
		$pf = $this->getDefaultSelectAlias() . '.';

		$idPrim = $this->prim($actor->getId());

		$on = $expr->andX(
			$this->exprLimitToDBFieldInt('accepted', 1, $alias),
			$this->exprLimitToDBField('actor_id_prim', $idPrim, true, true, $alias),
			$expr->eq($pf . 'attributed_to_prim', $alias . '.object_id_prim')
		);

		$this->leftJoin($this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_FOLLOWS, $alias, $on);
	}

	/**
	 * @param string $alias
	 */
	public function selectStreamActions(string $alias = 'sa'): void {
		if ($this->getType() !== self::SELECT) {
			return;
		}

		$pf = (($alias === '') ? $this->getDefaultSelectAlias() : $alias);
		$this->from(CoreRequestBuilder::TABLE_STREAM_ACTIONS, $pf);
		$this->selectAlias('sa.id', 'streamaction_id')
			->selectAlias('sa.actor_id', 'streamaction_actor_id')
			->selectAlias('sa.stream_id', 'streamaction_stream_id')
			->selectAlias('sa.liked', 'streamaction_liked')
			->selectAlias('sa.boosted', 'streamaction_boosted')
			->selectAlias('sa.replied', 'streamaction_replied')
			->selectAlias('sa.bookmarked', 'streamaction_bookmarked')
			->selectAlias('sa.values', 'streamaction_values');
	}

	/**
	 * @param string $alias
	 */
	public function leftJoinStreamAction(string $alias = 'sa'): void {
		if ($this->getType() !== self::SELECT || !$this->hasViewer()) {
			return;
		}

		$pf = $this->getDefaultSelectAlias();
		$expr = $this->expr();

		$this->selectAlias($alias . '.id', 'streamaction_id')
			->selectAlias($alias . '.actor_id', 'streamaction_actor_id')
			->selectAlias($alias . '.stream_id', 'streamaction_stream_id')
			->selectAlias($alias . '.liked', 'streamaction_liked')
			->selectAlias($alias . '.boosted', 'streamaction_boosted')
			->selectAlias($alias . '.replied', 'streamaction_replied')
			->selectAlias($alias . '.bookmarked', 'streamaction_bookmarked')
			->selectAlias($alias . '.values', 'streamaction_values');

		$viewer = $this->getViewer();
		$idPrim = $this->prim($viewer->getId());

		$orX = $expr->orX(
			$expr->eq($alias . '.stream_id_prim', $pf . '.id_prim'),
			$expr->eq($alias . '.stream_id_prim', $pf . '.object_id_prim')
		);

		$on = $expr->andX(
			$expr->eq($alias . '.actor_id_prim', $this->createNamedParameter($idPrim)),
			$orX
		);

		$this->leftJoin(
			$this->getDefaultSelectAlias(), CoreRequestBuilder::TABLE_STREAM_ACTIONS, $alias, $on
		);
	}

	/**
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $alias
	 */
	public function innerJoinStreamDest(
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = '',
	) {
		$this->andWhere($this->exprInnerJoinStreamDest($type, $field, $aliasDest, $alias));
	}

	/**
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprInnerJoinStreamDest(
		string $type, string $field = 'id_prim', string $aliasDest = 'sd', string $alias = '',
	): ICompositeExpression {
		$expr = $this->expr();
		$pf = (($alias === '') ? $this->getdefaultSelectAlias() : $alias) . '.';
		$andX = $expr->andX(
			$expr->eq($aliasDest . '.stream_id', $pf . $field),
			$expr->eq($aliasDest . '.type', $this->createNamedParameter($type))
		);

		return $andX;
	}

	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 * @param string $alias
	 */
	public function innerJoinStreamDestFollowing(
		string $actorId, string $type, string $field = 'id_prim', string $aliasDest = 'sd',
		string $aliasFollowing = 'f', string $alias = '',
	) {
		$this->andWhere(
			$this->exprInnerJoinStreamDestFollowing(
				$actorId, $type, $field, $aliasDest, $aliasFollowing, $alias
			)
		);
	}

	/**
	 * @param string $actorId
	 * @param string $type
	 * @param string $field
	 * @param string $aliasDest
	 * @param string $aliasFollowing
	 * @param string $alias
	 *
	 * @return ICompositeExpression
	 */
	public function exprInnerJoinStreamDestFollowing(
		string $actorId, string $type, string $field = 'id_prim', string $aliasDest = 'sd',
		string $aliasFollowing = 'f', string $alias = '',
	): ICompositeExpression {
		$expr = $this->expr();

		$pf = (($alias === '') ? $this->getdefaultSelectAlias() : $alias) . '.';

		$idPrim = $this->prim($actorId);
		$andX = $expr->andX(
			$this->exprLimitToDBField('actor_id_prim', $idPrim, true, true, $aliasFollowing),
			$this->exprLimitToDBFieldInt('accepted', 1, $aliasFollowing),
			$expr->eq($aliasFollowing . '.follow_id_prim', $aliasDest . '.actor_id'),
			$expr->eq($aliasDest . '.stream_id', $pf . $field),
			$expr->eq($aliasDest . '.type', $this->createNamedParameter($type))
		);

		return $andX;
	}
}
