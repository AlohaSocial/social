<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Tools\Nid;
use OCA\Social\Tools\Traits\TArrayTools;

class Item {
	use TArrayTools;

	private string $urlSocial = '';
	private string $urlCloud = '';
	private string $address = '';
	private string $id = '';
	private int|string $nid = 0;
	private string $type = '';
	private string $subType = '';
	private string $url = '';
	private string $summary = '';
	/** @var InstancePath[] */
	private array $instancePaths = [];
	private string $to = '';
	private array $toArray = [];
	private array $cc = [];
	private array $bcc = [];
	private string $published = '';
	private array $tags = [];
	private ?Person $actor = null;
	private string $actorId = '';
	private string $iconId = '';
	private string $objectId = '';
	private string $target = '';
	private bool $completeDetails = false;
	private string $source = '';
	private bool $local = false;
	private string $origin = '';
	private int $originSource = 0;
	private int $originCreationTime = 0;

	public function getId(): string {
		return $this->id;
	}

	public function setId(string $id): static {
		$this->id = $id;

		return $this;
	}

	public function getNid(): int|string {
		return $this->nid;
	}

	public function setNid(int|string $nid): self {
		$this->nid = Nid::fromStorage($nid);

		return $this;
	}

	public function getType(): string {
		return $this->type;
	}

	public function setType(string $type): static {
		$this->type = $type;

		return $this;
	}

	public function getSubType(): string {
		return $this->subType;
	}

	public function setSubType(string $type): static {
		$this->subType = $type;

		return $this;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function setUrl(string $url): static {
		$this->url = $url;

		return $this;
	}

	public function addInstancePath(InstancePath $instancePath): Item {
		if ($instancePath->getUri() !== '') {
			$this->instancePaths[] = $instancePath;
		}

		return $this;
	}

	/**
	 * @param InstancePath[] $path
	 */
	public function addInstancePaths(array $path): Item {
		$this->instancePaths = array_merge($this->instancePaths, $path);

		return $this;
	}

	/**
	 * @return InstancePath[]
	 */
	public function getInstancePaths(): array {
		return $this->instancePaths;
	}

	/**
	 * @param InstancePath[] $instancePaths
	 *
	 * @return static
	 */
	public function setInstancePaths(array $instancePaths): static {
		$this->instancePaths = $instancePaths;

		return $this;
	}

	public function getSummary(): string {
		return $this->summary;
	}

	/**
	 * @param string $summary
	 *
	 * @return static
	 */
	public function setSummary(string $summary): static {
		$this->summary = $summary;

		return $this;
	}

	/**
	 * @return Person
	 */
	public function getActor(): ?Person {
		return $this->actor;
	}

	public function setActor(Person $actor): static {
		$this->actor = $actor;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function hasActor(): bool {
		if ($this->actor === null) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $actorId
	 *
	 * @return static
	 */
	public function setActorId(string $actorId): static {
		$this->actorId = $actorId;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getActorId(): string {
		if ($this->hasActor()) {
			return $this->getActor()
				->getId();
		}

		return $this->actorId;
	}

	/**
	 * @return string
	 */
	public function getUrlSocial(): string {
		return $this->urlSocial;
	}

	/**
	 * @param string $path
	 *
	 * @return static
	 */
	public function setUrlSocial(string $path): static {
		$this->urlSocial = $path;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUrlCloud(): string {
		return $this->urlCloud;
	}

	/**
	 * @param string $path
	 *
	 * @return static
	 */
	public function setUrlCloud(string $path): static {
		$this->urlCloud = $path;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getAddress(): string {
		return $this->address;
	}

	/**
	 * @param string $address
	 *
	 * @return Item
	 */
	public function setAddress(string $address) {
		$this->address = $address;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getTo(): string {
		return $this->to;
	}

	/**
	 * @param string $to
	 *
	 * @return static
	 */
	public function setTo(string $to): static {
		$this->to = $to;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getToArray(): array {
		return $this->toArray;
	}

	/**
	 * @return array
	 */
	public function getToAll(): array {
		$arr = $this->toArray;
		$arr[] = $this->to;

		return $arr;
	}

	/**
	 * Whether this names the public collection, in any of the four fields it
	 * could name it in.
	 *
	 * The same question `social_stream_dest` answers after the fact: a
	 * recipient row is written for each account a post names, the public
	 * collection among them, and every count of "how many posts does this
	 * account have" is a count of rows that carry it. Asking it here — before
	 * the rows exist, and after they have gone — is what lets the stored
	 * counter be moved by the same rule the recount uses. Public and unlisted
	 * both say yes, which is Mastodon's `statuses_count` as well.
	 */
	public function addressesPublic(): bool {
		return in_array(
			ACore::CONTEXT_PUBLIC,
			array_merge($this->getToAll(), $this->getCcArray(), $this->getBccArray()),
			true
		);
	}

	/**
	 * @param string $to
	 *
	 * @return Item
	 */
	public function addToArray(string $to): Item {
		if (!in_array($to, $this->toArray, true)) {
			$this->toArray[] = $to;
		}

		return $this;
	}

	/**
	 * @param array $toArray
	 *
	 * @return static
	 */
	public function setToArray(array $toArray): static {
		$this->toArray = $toArray;

		return $this;
	}

	/**
	 * @param string $cc
	 *
	 * @return Item
	 */
	public function addCc(string $cc): Item {
		if (!$this->hasCc($cc)) {
			$this->cc[] = $cc;
		}

		return $this;
	}

	/**
	 * @param string $cc
	 *
	 * @return Item
	 */
	public function removeCc(string $cc): Item {
		if (!in_array($cc, $this->cc, true)) {
			return $this;
		}

		$this->cc = array_diff($this->cc, [$cc]);

		return $this;
	}

	/**
	 * @param string $cc
	 *
	 * @return bool
	 */
	public function hasCc(string $cc): bool {
		return (in_array($cc, $this->cc, true));
	}

	/**
	 * @return array
	 */
	public function getCcArray(): array {
		return $this->cc;
	}

	/**
	 * @param array $cc
	 *
	 * @return static
	 */
	public function setCcArray(array $cc): static {
		$this->cc = $cc;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getBccArray(): array {
		return $this->bcc;
	}

	/**
	 * @param array $bcc
	 *
	 * @return static
	 */
	public function setBccArray(array $bcc): static {
		$this->bcc = $bcc;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getOrigin(): string {
		return $this->origin;
	}

	/**
	 * @return int
	 */
	public function getOriginSource(): int {
		return $this->originSource;
	}

	/**
	 * @return int
	 */
	public function getOriginCreationTime(): int {
		return $this->originCreationTime;
	}

	/**
	 * @param string $origin
	 *
	 * @param int $source
	 *
	 * @param int $creationTime
	 *
	 * @return static
	 */
	public function setOrigin(string $origin, int $source, int $creationTime): static {
		$this->origin = $origin;
		$this->originSource = $source;
		$this->originCreationTime = $creationTime;

		return $this;
	}

	/**
	 * @param string $published
	 *
	 * @return static
	 */
	public function setPublished(string $published): static {
		$this->published = $published;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getPublished(): string {
		return $this->published;
	}

	/**
	 * @param array $tag
	 *
	 * @return Item
	 */
	public function addTag(array $tag): Item {
		$this->tags[] = $tag;

		return $this;
	}

	/**
	 * @param string $type
	 *
	 * @return array
	 */
	public function getTags(string $type = ''): array {
		if ($type === '') {
			return $this->tags;
		}

		$result = [];
		foreach ($this->tags as $tag) {
			if ($this->get('type', $tag, '') === $type) {
				$result[] = $tag;
			}
		}

		return $result;
	}

	/**
	 * @param array $tag
	 *
	 * @return static
	 */
	public function setTags(array $tag): static {
		$this->tags = $tag;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getObjectId(): string {
		return $this->objectId;
	}

	/**
	 * @param string $objectId
	 *
	 * @return static
	 */
	public function setObjectId(string $objectId): static {
		$this->objectId = $objectId;

		return $this;
	}

	public function getTarget(): string {
		return $this->target;
	}

	public function setTarget(string $target): static {
		$this->target = $target;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getIconId(): string {
		return $this->iconId;
	}

	/**
	 * @param string $iconId
	 *
	 * @return static
	 */
	public function setIconId(string $iconId): static {
		$this->iconId = $iconId;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isLocal(): bool {
		return $this->local;
	}

	public function setLocal(bool $local): static {
		$this->local = $local;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isCompleteDetails(): bool {
		return $this->completeDetails;
	}

	/**
	 * @param bool $completeDetails
	 *
	 * @return static
	 */
	public function setCompleteDetails(bool $completeDetails): static {
		$this->completeDetails = $completeDetails;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getSource(): string {
		return $this->source;
	}

	/**
	 * @param string $source
	 *
	 * @return static
	 */
	public function setSource(string $source): static {
		$this->source = $source;

		return $this;
	}
}
