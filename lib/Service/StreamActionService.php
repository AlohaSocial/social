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

/**
 * Class StreamActionService
 *
 * @package OCA\Social\Service
 */
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
		$action->updateValueBool($key, $value);
		$this->saveAction($action);
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
	 * Two people liking the same post at the same moment is the ordinary case,
	 * not a rare one, and this used to UPDATE and then INSERT when the update
	 * reported no rows — so both requests inserted and one of them died on the
	 * unique index with a 500. The Db layer now inserts first and falls back to
	 * an update, which is what the other tables already do.
	 */
	private function saveAction(StreamAction $action): void {
		$this->streamActionsRequest->save($action);
	}
}
