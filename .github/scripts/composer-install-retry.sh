#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Aloha Social contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

max_attempts=5
attempt_log=$(mktemp)
trap 'rm -f "$attempt_log"' EXIT
github_archive_tls_failure=0

for ((attempt = 1; attempt <= max_attempts; attempt++)); do
	if COMPOSER_MAX_PARALLEL_HTTP="${COMPOSER_MAX_PARALLEL_HTTP:-2}" composer "$@" 2>&1 | tee "$attempt_log"; then
		exit 0
	fi
	if grep -Eq 'curl error 35 while downloading https://api\.github\.com/repos/[^ ]+/zipball/' "$attempt_log"; then
		github_archive_tls_failure=1
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

# GitHub archive downloads use api.github.com, while Composer's VCS source
# installs use the repository's git URL. When the archive endpoint keeps
# failing its TLS handshake, make one bounded source-based fallback instead of
# repeating the same dist request until the job gives up.
if [ "$github_archive_tls_failure" -eq 1 ]; then
	source_args=()
	skip_preference_value=0
	for arg in "$@"; do
		if [ "$skip_preference_value" -eq 1 ]; then
			skip_preference_value=0
			continue
		fi
		case "$arg" in
			--prefer-dist|--prefer-source|--prefer-install=*) continue ;;
			--prefer-install) skip_preference_value=1 ;;
			*) source_args+=("$arg") ;;
		esac
	done

	echo 'GitHub archive TLS failures persisted; retrying Composer with VCS sources.' >&2
	for source_attempt in 1 2; do
		if COMPOSER_MAX_PARALLEL_HTTP=1 COMPOSER_MAX_PARALLEL_PROCESSES=2 composer "${source_args[@]}" --prefer-install=source; then
			exit 0
		fi
		if [ "$source_attempt" -lt 2 ]; then
			echo 'Composer source fallback failed; retrying once in 10s.' >&2
			sleep 10
		fi
	done
fi

exit 1
