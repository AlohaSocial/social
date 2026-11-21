<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * One permission this instance has given out to quote one of its posts,
 * recording the accepted `QuoteRequest`'s id, which a revoking `Reject`
 * (FEP-044f) must name.
 */
class QuoteGrant {
	private int $id = 0;
	private string $targetId = '';
	private string $quotingId = '';
	private string $actorId = '';
	private string $requestId = '';
	private string $authorization = '';

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	/** the local post that was quoted */
	public function getTargetId(): string {
		return $this->targetId;
	}

	public function setTargetId(string $targetId): self {
		$this->targetId = $targetId;

		return $this;
	}

	/** the post doing the quoting */
	public function getQuotingId(): string {
		return $this->quotingId;
	}

	public function setQuotingId(string $quotingId): self {
		$this->quotingId = $quotingId;

		return $this;
	}

	/** who asked, and therefore whose inbox a revocation goes to */
	public function getActorId(): string {
		return $this->actorId;
	}

	public function setActorId(string $actorId): self {
		$this->actorId = $actorId;

		return $this;
	}

	/** the QuoteRequest this grant answered */
	public function getRequestId(): string {
		return $this->requestId;
	}

	public function setRequestId(string $requestId): self {
		$this->requestId = $requestId;

		return $this;
	}

	/** the stamp that was issued, which the quoting post carries */
	public function getAuthorization(): string {
		return $this->authorization;
	}

	public function setAuthorization(string $authorization): self {
		$this->authorization = $authorization;

		return $this;
	}
}
