
# SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name=social

project_dir=$(CURDIR)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
github_account=nextcloud
branch=main

# Read from appinfo/info.xml so it cannot drift from the released version.
version=$(shell sed -n 's/.*<version>\(.*\)<\/version>.*/\1/p' appinfo/info.xml)


all: dev-setup lint build-js-production composer

# Dev env management
dev-setup: clean clean-dev npm-init composer

# Release env management.
# npm ci and composer install use the committed lock files.
release-setup: clean clean-dev npm-ci composer

npm-init:
	npm install

npm-ci:
	npm ci

npm-update:
	npm update

# Building
build-js:
	npm run dev

build-js-production:
	npm run build

watch-js:
	npm run watch

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

# Optional complete local checks before requesting a final CI run.
.PHONY: check-pr
check-pr:
	composer run cs:check
	composer run psalm
	composer run test:unit
	npm run lint
	npm run typecheck
	npm run stylelint
	npm test
	npm run build
	@test -z "$$(git status --porcelain -- js/)" || { echo 'Commit the rebuilt js/ files and run check-pr again.'; exit 1; }

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

# Cleaning
# Remove generated release artifacts.
# Webpack handles cleaning stale JavaScript build assets.
clean:
	rm -rf $(build_dir)

clean-dev:
	rm -rf node_modules

composer:
	composer install --prefer-dist --no-dev

composer-dev:
	composer install --prefer-dist --dev

# Deliberately rewrites composer.lock.
# Never part of a release build.
composer-update:
	composer upgrade --prefer-dist

release: appstore

# The first release this repository tags: the generated changelog starts
# there, and cliff.toml's footer carries what was released before it.
changelog_since=v0.24.1

# Regenerate CHANGELOG.md from the conventional commits, labelled with the
# version in appinfo/info.xml. Needs git-cliff (https://git-cliff.org); the
# release dry run checks that the file is what this produces. See
# docs/Releasing.md.
changelog:
	git cliff $(changelog_since).. --tag $(version) -o CHANGELOG.md

# What the App Store package holds: the files the app reads at runtime, and
# nothing else. An allowlist, so a new file at the top level (an agent guide, a
# tool config, a screenshot folder) stays out until somebody adds it here.
# contrib/webserver is in it because the setup checks name its rules; docs/
# is not, because the app links to the guides on GitHub.
appstore_files = \
	appinfo \
	context \
	contrib \
	css \
	data \
	img \
	js \
	l10n \
	lib \
	resources \
	templates \
	vendor \
	AUTHORS.md \
	CHANGELOG.md \
	COPYING \
	LICENSES

# Left out of the folders above: source maps, the README screenshots (the App
# Store and the README load them from GitHub), a development probe, the
# pseudo-locale the browser tests write, and version-control and OS clutter.
appstore_excludes = \
	/js/*.map \
	/img/readme \
	/contrib/atproto-live-probe.sh \
	/l10n/en_XA.* \
	.git* \
	.DS_Store

appstore_rsync = rsync -a \
	$(foreach pattern,$(appstore_excludes),--exclude='$(pattern)') \
	$(foreach file,$(appstore_files),--include='/$(file)') \
	--exclude='/*'

# List what `make appstore` would package, without building or copying
# anything. vendor/ is listed as it is in this checkout; the release build
# installs it with --no-dev first.
appstore-list:
	@$(appstore_rsync) --dry-run --out-format='%n' $(project_dir)/ $(sign_dir)/$(app_name) | sort

# Create the Nextcloud App Store package.
# App signing is handled separately by the release workflow.
appstore: release-setup lint build-js-production composer
	mkdir -p $(sign_dir)
	$(appstore_rsync) $(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
