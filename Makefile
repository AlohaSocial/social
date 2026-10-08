
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
branch=master

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

# Create the Nextcloud App Store package.
# App signing is handled separately by the release workflow.
appstore: release-setup lint build-js-production composer
	mkdir -p $(sign_dir)
	rsync -a \
		--exclude=.git \
		--exclude=/.github \
		--exclude=/.gitignore \
		--exclude=/.l10nignore \
		--exclude=/.tx \
		--exclude=/.idea \
		--exclude=/.eslintrc.js \
		--exclude=/.php-cs-fixer.cache \
		--exclude=/.php-cs-fixer.dist.php \
		--exclude=/build \
		--exclude=/babel.config.js \
		--exclude=/build-package.sh \
		--exclude=/composer.json \
		--exclude=/composer.lock \
		--exclude=/deploy.sh \
		--exclude=/docs \
		--exclude=/node_modules \
		--exclude=/package.json \
		--exclude=/package-lock.json \
		--exclude=/psalm.xml \
		--exclude=/REUSE.toml \
		--exclude=/README.md \
		--exclude=/src \
		--exclude=/stylelint.config.js \
		--exclude=/tests \
		--exclude=/tools \
		--exclude=/translationfiles \
		--exclude=/vitest.config.js \
		--exclude=/webpack.*.js \
		--exclude=/Makefile \
		--exclude=js/*.map \
		$(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
