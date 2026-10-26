<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Model;

/**
 * A post request that has been accepted and not published.
 *
 * Two things in this app keep one: a post scheduled for later, and a post held
 * for a moderator to look at. Both store the client's request rather than a
 * rendered note — the reasons are set out on `ScheduledStatus` — and both
 * replay it through `StatusAssemblyService::fromParams()`, which is why the
 * readers of `params` are an interface rather than a method on either class.
 */
interface StatusParams {
	public function paramText(): string;

	public function paramString(string $key): string;

	public function paramBool(string $key): bool;

	/** @return array<string, mixed>|null */
	public function paramArray(string $key): ?array;

	/** @return array<string, mixed>|null */
	public function paramPoll(): ?array;

	/** @return array{name:string,start_time:?string,end_time:?string,location:string,status?:string}|null */
	public function paramEvent(): ?array;

	/** @return int[] */
	public function paramMediaIds(): array;
}
