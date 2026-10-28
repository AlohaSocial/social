<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

use OCA\Social\Tools\ActivityPubEvent;

/**
 * Reading a stored post request, for the two models that keep one.
 *
 * Every accessor is defensive about what it finds. `params` is JSON that was
 * written by an earlier version of this app from a body a client sent, and the
 * code reading it back runs without the client there to be told anything: a
 * poll that is a string, or a `media_ids` holding an object, has to become
 * "no poll" and "no media" rather than a type error inside a cron run.
 */
trait TStatusParams {
	/** @var array<string, mixed> */
	protected array $params = [];

	/** @return array<string, mixed> */
	public function getParams(): array {
		return $this->params;
	}

	public function paramText(): string {
		$text = $this->params['text'] ?? '';

		return is_scalar($text) ? (string)$text : '';
	}

	public function paramString(string $key): string {
		$value = $this->params[$key] ?? '';

		return is_scalar($value) ? (string)$value : '';
	}

	public function paramBool(string $key): bool {
		return ($this->params[$key] ?? false) === true;
	}

	/** @return array<string, mixed>|null */
	public function paramArray(string $key): ?array {
		$value = $this->params[$key] ?? null;

		return is_array($value) ? $value : null;
	}

	/** @return array<string, mixed>|null */
	public function paramPoll(): ?array {
		$poll = $this->params['poll'] ?? null;

		return (is_array($poll) && $poll !== []) ? $poll : null;
	}

	/** @return array{name:string,start_time:?string,end_time:?string,location:string,status?:string,participation_url?:string}|null */
	public function paramEvent(): ?array {
		$event = $this->params['event'] ?? null;
		if (!is_array($event)) {
			return null;
		}

		return ActivityPubEvent::metadata([
			'name' => $event['name'] ?? '',
			'startTime' => $event['start_time'] ?? null,
			'endTime' => $event['end_time'] ?? null,
			'location' => $event['location'] ?? '',
			'ical:status' => $event['status'] ?? null,
		]);
	}

	/**
	 * The attachments the post will carry, as the numeric media ids this app
	 * uses. Held as strings in `params`, because that is how Mastodon holds
	 * every id a client sees, and because the entity is echoed verbatim.
	 *
	 * @return int[]
	 */
	public function paramMediaIds(): array {
		$ids = [];
		foreach ((array)($this->params['media_ids'] ?? []) as $id) {
			if (is_scalar($id) && (int)$id > 0) {
				$ids[] = (int)$id;
			}
		}

		return $ids;
	}

	/** The `params` column as it is written. */
	public function exportParams(): string {
		return (string)json_encode($this->getParams());
	}
}
