#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Aloha Social contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

for attempt in 1 2 3; do
	if COMPOSER_MAX_PARALLEL_HTTP="${COMPOSER_MAX_PARALLEL_HTTP:-4}" composer "$@"; then
		exit 0
	fi

	if [ "$attempt" -lt 3 ]; then
		delay=$((attempt * 10))
		echo "Composer failed; retrying in ${delay}s (attempt $((attempt + 1))/3)." >&2
		sleep "$delay"
	fi
done

exit 1
