#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Aloha Social contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

max_attempts=5
for ((attempt = 1; attempt <= max_attempts; attempt++)); do
	if COMPOSER_MAX_PARALLEL_HTTP="${COMPOSER_MAX_PARALLEL_HTTP:-2}" composer "$@"; then
		exit 0
	fi

	if [ "$attempt" -lt "$max_attempts" ]; then
		delay=$((10 * (2 ** (attempt - 1))))
		if [ "$delay" -gt 60 ]; then
			delay=60
		fi
		echo "Composer failed; retrying in ${delay}s (attempt $((attempt + 1))/${max_attempts})." >&2
		sleep "$delay"
	fi
done

exit 1
