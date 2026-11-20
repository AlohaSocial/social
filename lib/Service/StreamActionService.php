<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;
use OCA\Social\Tools\Nid;

class StreamActionService {
	private StreamActionsRequest $streamActionsRequest;

	public function __construct(StreamActionsRequest $streamActionsRequest) {
		$this->streamActionsRequest = $streamActionsRequest;
	}

	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param string $value
	 */
	public function setAction(string $actorId, string $streamId, string $key, string $value) {
		$action = $this->loadAction($actorId, $streamId);
		$action->updateValue($key, $value);
		$this->saveAction($action);
	}

	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param int $value
	 */
	public function setActionInt(string $actorId, string $streamId, string $key, int $value) {
		$action = $this->loadAction($actorId, $streamId);
		$action->updateValueInt($key, $value);
		$this->saveAction($action);
	}

	/**
	 * @param string $actorId
	 * @param string $streamId
	 * @param string $key
	 * @param bool $value
	 */
	public function setActionBool(string $actorId, string $streamId, string $key, bool $value): void {
		$action = $this->loadAction($actorId, $streamId);
		$this->dateMark($action, $key, $value);
		$action->updateValueBool($key, $value);
		$this->saveAction($action);
	}

	/** Whether the reader currently holds a boolean action on this post. */
	public function getActionBool(string $actorId, string $streamId, string $key): bool {
		try {
			$action = $this->streamActionsRequest->getAction($actorId, $streamId);
		} catch (StreamActionDoesNotExistException) {
			return false;
		}

		return $action->hasValue($key) && $action->getValueBool($key);
	}

	/**
	 * A like or a bookmark carries the moment it was set, as an id the
	 * favourites and bookmarks lists are paged by: minted when the flag is set,
	 * cleared with it, and kept when it is set again, so liking twice does not
	 * move a post to the top.
	 */
	private function dateMark(StreamAction $action, string $key, bool $value): void {
		$mark = match ($key) {
			StreamAction::LIKED => StreamAction::LIKED_NID,
			StreamAction::BOOKMARKED => StreamAction::BOOKMARKED_NID,
			default => '',
		};
		if ($mark === '') {
			return;
		}

		$set = $action->hasValue($key) && $action->getValueBool($key);
		if ($set === $value) {
			return;
		}

		$action->updateValue($mark, $value ? Nid::now() : '0');
	}

	/**
	 * @param string $actorId
	 * @param string $streamId
	 *
	 * @return StreamAction
	 */
	private function loadAction(string $actorId, string $streamId): StreamAction {
		try {
			$action = $this->streamActionsRequest->getAction($actorId, $streamId);
		} catch (StreamActionDoesNotExistException $e) {
			$action = new StreamAction($actorId, $streamId);
		}

		return $action;
	}

	/**
	 * Concurrent likes of one post are ordinary: the Db layer inserts first and
	 * falls back to an update, so neither request fails on the unique index.
	 */
	private function saveAction(StreamAction $action): void {
		$this->streamActionsRequest->save($action);
	}
}
