<!--
  - SPDX-FileCopyrightText: 2026 Aloha Social contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Aloha Directory fixtures

`keys.json`, `servers.json`, `servers-older.json` and `search.json` were signed
with the Aloha Directory's own code (`App\Lists\KeySetService::sign()` and
`App\Crypto\SignedEnvelope::build()`), so `ListVerifierTest` and
`DirectoryListsTest` hold this app to what the directory publishes.

The keys are test keys derived from fixed seeds, not keys of any directory:
`meta.json` holds the root and list public keys and the secret key of a test
server.
