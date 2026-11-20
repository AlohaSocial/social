<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * A post request that has been accepted and not published: a scheduled post
 * or a post held for a moderator. Both store the client's request (see
 * `ScheduledStatus`) and replay it through
 * `StatusAssemblyService::fromParams()`, hence an interface.
 */
interface StatusParams {
	public function paramText(): string;

	public function paramString(string $key): string;

	public function paramBool(string $key): bool;

	/** @return array<string, mixed>|null */
	public function paramArray(string $key): ?array;

	/** @return array<string, mixed>|null */
	public function paramPoll(): ?array;

	/** @return array{name:string,start_time:?string,end_time:?string,location:string,status?:string,participation_url?:string}|null */
	public function paramEvent(): ?array;

	/** @return int[] */
	public function paramMediaIds(): array;
}
