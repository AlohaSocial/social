<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Db;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tools\Db\ExtendedQueryBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IURLGenerator;
use RuntimeException;

/**
 * Class SocialCoreQueryBuilder
 *
 * @package OCA\Social\Db
 */
class SocialCoreQueryBuilder extends ExtendedQueryBuilder {
	/** filterHiddenActors(): hide blocked AND muted actors (aggregated timelines, threads). */
	public const HIDDEN_TIMELINE = 'timeline';
	/** filterHiddenActors(): hide blocked actors and muted-with-notifications actors. */
	public const HIDDEN_NOTIFICATIONS = 'notifications';
	/** filterHiddenActors(): hide blocked actors only — a muted actor's own profile
	 * and a directly opened post stay visible, matching Mastodon's mute semantics. */
	public const HIDDEN_DIRECT = 'direct';

	private ?Person $viewer = null;

	/**
	 * The instances the viewer has blocked, as domains, compared as constants by
	 * `DomainBlocksRequestBuilder::filterDomainBlocked()`. Set by
	 * `CoreRequestBuilder::getQueryBuilder()` with the viewer.
	 *
	 * @var string[]
	 */
	private array $blockedDomains = [];

	/** Whether every `social_stream` row carries its `author_host`; see `filterDomainBlocked()`. */
	private bool $authorHostsFilled = false;
	private bool $mediaKindFilled = false;

	public function __construct(
		IQueryBuilder $queryBuilder,
		protected IURLGenerator $urlGenerator,
	) {
		parent::__construct($queryBuilder);
	}

	public function hasViewer(): bool {
		return ($this->viewer !== null);
	}

	public function setViewer(Person $viewer): void {
		$this->viewer = $viewer;
	}

	/**
	 * The actor the rows are being read for; guard with `hasViewer()`.
	 */
	public function getViewer(): Person {
		if ($this->viewer === null) {
			throw new RuntimeException('no viewer set on this query: guard with hasViewer()');
		}

		return $this->viewer;
	}

	/** @param string[] $domains the instances the viewer has blocked */
	public function setBlockedDomains(array $domains): void {
		$this->blockedDomains = $domains;
	}

	/** @return string[] the instances the viewer has blocked */
	public function blockedDomains(): array {
		return $this->blockedDomains;
	}

	public function setAuthorHostsFilled(bool $filled): void {
		$this->authorHostsFilled = $filled;
	}

	public function authorHostsAreFilled(): bool {
		return $this->authorHostsFilled;
	}

	public function setMediaKindFilled(bool $filled): void {
		$this->mediaKindFilled = $filled;
	}

	/** Whether every stream row carries a `media_kind`, so the media filters need no fallback. */
	public function mediaKindIsFilled(): bool {
		return $this->mediaKindFilled;
	}

	public function prim(string $id): string {
		if ($id === '' || substr($id, 0, 4) !== 'http') {
			return '';
		}

		return md5($id);
	}
}
