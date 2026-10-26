<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Security policy

## Reporting a vulnerability

**Do not open a GitHub issue for a security problem.** Report it through
Nextcloud's HackerOne programme at <https://hackerone.com/nextcloud>, which
covers this app, or by mail to <security@nextcloud.com>; the process, what is
in scope and how disclosure works are described at
<https://nextcloud.com/security/>. A report there reaches the people who can
fix it without telling everybody else first.

This app is a federated server: it accepts signed requests from any server
on the network, serves a Mastodon-compatible client API with OAuth, and acts
as a Bluesky personal data server. Anything that lets one of those parties do
more than it is allowed to — read a post it was not addressed, act as an
account it does not hold, make this server fetch an address it should not —
is a security problem and belongs on HackerOne. How the trust boundaries are
drawn is in [docs/Architecture.md](docs/Architecture.md) under *Security*.

Reports written with an AI tool are welcome once a person has verified them;
an unverified generated report costs the people reading it more than it
saves the person sending it.

## Supported versions

The latest release on the [Nextcloud App Store](https://apps.nextcloud.com/apps/social)
and `main` get security fixes; a fix is released as a new version of the
app. Older releases are not patched: upgrade to the latest. The Nextcloud
server versions a release supports are in `appinfo/info.xml` and in
[docs/Architecture.md](docs/Architecture.md).
