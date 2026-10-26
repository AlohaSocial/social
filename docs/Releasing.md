# Releasing Aloha Social

How a version of this app gets from `main` to the Nextcloud App Store, and
what has to be in place before the first time. Everything here is run by a
person; the workflows do the building, signing and uploading, and one of them
waits for that person's approval before anything leaves GitHub.

**Where the version lives.** `appinfo/info.xml` is the release version. The
same number has to be in `package.json`, `package-lock.json`, `composer.json`
and the "App version" line of [Architecture.md](Architecture.md);
`tests/DocumentationTest.php` fails when they disagree, and so does the
release workflow. Releases are tagged `vX.Y.Z`; the App Store takes the
number without the `v`.

## Once, before the first release

1. **The signing key.** The repository secret `APP_PRIVATE_KEY` holds the
   private key whose certificate is registered for the app id `social` in
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests/tree/master/social).
   Both workflows below refuse a key that does not match that certificate.
2. **The environment `nextcloud-appstore`**, under *Settings → Environments*
   of the repository, with **Required reviewers** set to the people who may
   publish. The publish job of `appstore-build-publish.yml` names this
   environment; GitHub creates an environment it does not know **without
   reviewers**, and an environment without reviewers pauses nothing — the
   upload would go out the moment the build finished.
3. **The App Store token** as the environment secret `APPSTORE_TOKEN` of
   `nextcloud-appstore`: the API token of an apps.nextcloud.com account that
   owns the app `social`. A repository secret of the same name works too,
   but the environment keeps it behind the reviewers.
4. **git-cliff** on the machine that cuts the release, for the changelog:
   `brew install git-cliff`, `cargo install git-cliff`, or the binary from
   [git-cliff.org](https://git-cliff.org/docs/installation). The dry run
   installs its own copy and checks the result, so a missing local install
   only means the dry run says so.

## Every release

1. **Green `main`.** The pull request and push workflows are green on the
   commit to be released, and the open `nightly-failure` issues are either
   closed or understood.
2. **The changelog.** `make changelog` regenerates `CHANGELOG.md` from the
   conventional commits since `v0.24.1` (`changelog_since` in the Makefile;
   what came before is the hand-written history `cliff.toml` carries as its
   footer), labelled with the version in `appinfo/info.xml`. Commit it as
   `docs(changelog): X.Y.Z`. Only `feat`, `fix`, `perf` and `docs` commits
   become entries; `cliff.toml` says which types are left out.
3. **The dry run.** Dispatch *Test App Store Release (Dry Run)*
   (`test-release.yml`) on `main`. It is the release workflow without the
   publishing: it checks that the five version places agree, that
   `CHANGELOG.md` is what `make changelog` produces (an error on a tag, a
   warning on a branch), runs the unit, lint and type checks on the PHP
   version `appinfo/info.xml` declares as the floor, builds the tarball with
   `make appstore`, signs it with `APP_PRIVATE_KEY` against the registered
   certificate, and leaves the signed archive as a three-day Actions
   artifact. Nothing is published. A red dry run is fixed on `main` and
   dispatched again; do not tag over it.
4. **The tag and the GitHub release.** On the dry-run commit:

   ```bash
   git tag -s vX.Y.Z -m 'vX.Y.Z'
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title 'vX.Y.Z' --notes-from-tag
   ```

   or create the release in the GitHub interface from the tag. Publishing the
   release is what starts `appstore-build-publish.yml` (`release:
   published`); a draft starts nothing. A pre-release tag (`vX.Y.Z-beta.1`)
   is accepted by both workflows.
5. **The build job** (*Test, build, sign and attach release*) repeats the dry
   run on the tag, refuses a tag that disagrees with the version files, and
   attaches `social-vX.Y.Z.tar.gz` to the GitHub release. The App Store
   serves that file; the upgrade workflow installs it (below).
6. **The approval.** The publish job (*Publish to Nextcloud App Store*)
   waits in the `nextcloud-appstore` environment until a required reviewer
   approves it on the run's page. Check the attached tarball first — install
   it on a Nextcloud if in doubt. Rejecting leaves the GitHub release
   standing with its tarball and sends nothing to the App Store.
7. **After approval** the job uploads the release to apps.nextcloud.com with
   `APPSTORE_TOKEN` and the download URL of the attached tarball. The App
   Store fetches the archive, verifies the signature against the registered
   certificate and lists the version within a few minutes; a refusal is in
   the job's log.

## What the release changes elsewhere

- **The upgrade workflow.** `upgrade.yml`'s "latest" legs (`Upgrade on
  sqlite`, `Upgrade on pgsql`) install whatever GitHub marks as the latest
  release — the attached tarball, or the tag's source when a release has
  none — seed it with data, replace it with the checkout and run `occ
  upgrade`, then compare the schema with a fresh install's and run the
  integration suite. From the night after the release, the migrations are
  tested against that version's schema; the `v0.10.0` legs keep covering the
  oldest schema the squash has to bring up.
- **The changelog check.** `DocumentationTest` requires the top heading of
  `CHANGELOG.md` to be at or after the newest `v*` tag wherever git can list
  the tags, so a tag cut without step 2 fails the unit suite on every full
  checkout.
- **The version bumps** continue on `main` as before; the next release's
  changelog section covers everything since `v0.24.1` until a tag after that
  exists, and from the next tag on, everything since that tag.

## When it goes wrong

- *The build job failed after the release was published.* Fix on `main`,
  delete the GitHub release and the tag (`gh release delete vX.Y.Z
  --cleanup-tag`), bump the version and release again. Nothing reached the
  App Store: the publish job never ran.
- *The upload was refused.* The job log carries the App Store's answer —
  usually a signature that does not match the registered certificate, a
  version already published, or a `max-version` the App Store does not know.
  The GitHub release stays; fix, bump and release again rather than editing
  the release in place, because the App Store keys on the version number.
- *A published version is broken.* The App Store has no "unpublish"; release
  a fix with a higher version number.
