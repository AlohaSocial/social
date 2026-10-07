# Native publication interoperability

This check uses the official `@atproto/repo` SDK against a CAR exported from the
real Nextcloud database after the shared Social posting service publishes a parent
and reply. The SQL integration suite also exercises image ownership/alt text/blob
CIDs, private visibility exclusion, all three transport choices, deletion proofs,
large-batch sync events and account deactivation. No production PLC write occurs
in these tests. Use a disposable installed Nextcloud instance, not a live database.

```sh
composer install
export NEXTCLOUD_ROOT=/path/to/disposable/nextcloud
export ATPROTO_INTEROP_EXPORT_DIR=/tmp/social-native-public-export
vendor/bin/phpunit -c tests/Integration/phpunit.xml --filter PublicationTest
npm ci --prefix tests/Interop/atproto
node tests/Interop/atproto/verify-publication.mjs "$ATPROTO_INTEROP_EXPORT_DIR"
```

The export contains public records, a signed commit and a public verification key.
It contains no private signing key or recovery phrase. The reference SDK reads the
CAR/MST, verifies the native K256 commit and verifies the reply's parent URI/CID.
The SQLite CI matrix runs this check after the complete real Nextcloud suite.
GMP and Sodium are included in all three PHP database matrices.

## Real PLC/relay acceptance performed on 2026-10-07

The local network test additionally used:

- Nextcloud 35.0.0.8 / PHP 8.5.9 with its own SQLite database/config/data directory.
- Unmodified `did-method-plc` server, source commit
  `9c8ea2fe23b89a5c1011246cbb4957dad9dbf7db`, with disposable PostgreSQL 17.
- Unmodified `bluesky-social/indigo` relay, source commit
  `ae9297b7d0f4b61f778306aedbdf72b346d6f234`.
- A dedicated Docker network and Caddy internal TLS certificate for `pds.test`
  and wildcard handles. That test certificate was trusted only by the disposable
  relay and test client. Production containers/configuration were not changed.

The app's actual IdentityService submitted a signed genesis operation to PLC;
PLC confirmed the DID/document. A signed-in request to the ordinary
`/api/v1/statuses` endpoint with `publish_target: "atproto"` returned a normal
Social status. The outbox committed it, the native WebSocket streamed it, and
Indigo's public `sync.listRepos` head matched the native SQL head. HTTP handle
resolution was also checked through wildcard HTTPS.

Run database fixture suites separately from a live relay test: fixture teardown
removes test events and is not a production event-retention operation. A running
relay must never be pointed at a database that is being reset by tests.

These are local interoperability results. Public DNS, trusted TLS, an externally
reachable root PDS API/firehose and public Bluesky AppView indexing require a
separate deployment check. The test does not prove external client login, OAuth,
account migration or the complete third-party PDS write API.
