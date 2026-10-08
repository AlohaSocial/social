/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const CopyPlugin = require('copy-webpack-plugin')

// the Twemoji pictures posts draw their emoji with. `twemoji` itself stopped
// at Unicode 12; Discord's fork carries the same file layout and keeps up
webpackConfig.plugins.push(new CopyPlugin({
	patterns: [
		{ from: 'node_modules/@discordapp/twemoji/dist/svg/', to: '../img/twemoji' },
	],
}))

webpackConfig.entry = {
	adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
	social: path.join(__dirname, 'src', 'main.js'),
	ostatus: path.join(__dirname, 'src', 'ostatus.js'),
	profilePage: path.join(__dirname, 'src', 'profile.js'),
	dashboard: path.join(__dirname, 'src', 'dashboard.js'),
	oauth: path.join(__dirname, 'src', 'oauth.js'),
	filesAction: path.join(__dirname, 'src', 'filesAction.js'),
	signup: path.join(__dirname, 'src', 'signup.js'),
}

// The one entry that stays self-contained. It registers "Share to Social" in
// the Files app and is loaded on every Files page, most of which will never
// post anything: a few KB of @nextcloud/files and l10n it can carry itself,
// the megabyte of framework it must not ask for. Excluded from the shared
// chunk below, so `FilesScriptsListener` loads it alone — on purpose, and
// `tests/js/bundles.test.js` pins that it works alone.
const SELF_CONTAINED = ['filesAction']

// Vue's build-time flags. Without them the bundle carries the devtools
// bridge into production — `@vue/devtools-api` pulls in `@vue/devtools-kit`,
// 170 KB of source that nothing in a released app can use — and Vue keeps the
// hydration-mismatch reporting it only needs while developing.
webpackConfig.plugins.push(new webpack.DefinePlugin({
	__VUE_OPTIONS_API__: 'true',
	__VUE_PROD_DEVTOOLS__: 'false',
	__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
}))

// Scope hoisting stays off, as it has been since 0.9.3. It is worth 1.1 KB of
// the main entry, and with it on two consecutive builds of the same source
// produce two different bundles — Terser mangles the merged scopes differently
// — which would make CI's check that the committed js/ matches src/ fail about
// half the time.
webpackConfig.optimization.concatenateModules = false

// Templates are compiled from source, not from the AST the SFC parser hands
// vue-loader. @vue/compiler-core 3.5 computes the line and column of nodes it
// creates while transforming (`cloneLoc` in `transformIf`) from the newline
// table of whatever it tokenized last, which during a build is some other
// file; the resulting source maps depend on build order, webpack hashes the
// map into the module, and a chunk hash that differs between two builds of
// the same source moves Terser's variable names around. A compiler of its
// own makes compileTemplate drop the AST and parse the template itself, right
// before transforming it.
const vueRule = webpackConfig.module.rules.find((rule) => rule.loader === 'vue-loader')
vueRule.options = {
	...(vueRule.options ?? {}),
	compiler: { ...require('@vue/compiler-dom') },
}

// The emoji picker is most of a megabyte, and it was landing in the same
// vendor chunk as NcActionButton — which every post's overflow menu needs — so
// everybody downloaded the whole emoji set to see a "..." button. Give it a
// chunk of its own and it arrives when somebody opens the picker.
webpackConfig.optimization.splitChunks = {
	...(webpackConfig.optimization.splitChunks ?? {}),
	cacheGroups: {
		...(webpackConfig.optimization.splitChunks?.cacheGroups ?? {}),
		// Vue, @nextcloud/vue, pinia and the rest of the framework were built
		// into each of the five entry points separately, so somebody who opened
		// the Dashboard and then the app downloaded all of it twice — 1,116 KB
		// and then 1,460 KB, most of it the same bytes. `chunks: 'initial'`
		// takes third-party code out of the entries and leaves it in one file
		// they share, which the browser then has cached. Async chunks keep the
		// splitting they already had, and the emoji group below still wins for
		// the picker because it has the higher priority.
		//
		// An entry is no longer self-contained, so every `Util::addScript()`
		// for this app loads `social-framework` first — see `templates/`,
		// `AdminSettings`, `SocialWidget` and `ProfileSectionListener`.
		framework: {
			test: /[\\/]node_modules[\\/]/,
			name: 'framework',
			chunks: (chunk) => chunk.canBeInitial() && !SELF_CONTAINED.includes(chunk.name),
			// only what more than one entry point needs: a library just one of
			// them uses stays in it, so opening the app alone downloads no more
			// than it did before
			minChunks: 2,
			priority: 20,
			reuseExistingChunk: true,
			enforce: true,
		},
		emoji: {
			test: /[\\/]node_modules[\\/](?:emoji-mart|emoji-mart-vue-fast)[\\/]/,
			name: 'emoji-picker',
			chunks: 'all',
			priority: 30,
			reuseExistingChunk: true,
			enforce: true,
		},
	},
}
webpackConfig.module.rules.unshift({
	test: /\.mjs$/,
	type: 'javascript/auto',
	resolve: {
		fullySpecified: false,
	},
})
webpackConfig.module.rules.unshift({
	test: /node_modules\/(?:axios|webdav|@vue\/devtools-shared)\/.*\.js$/,
	resolve: {
		fullySpecified: false,
	},
})
webpackConfig.resolve.extensions = ['.*', '.ts', '.js', '.vue', '.json']

// Preserve .htaccess when cleaning the output directory. It is the one file in
// js/ that is not build output; the administration page's script was the other
// one until that page became a Vue entry like every other.
webpackConfig.output.clean = {
	keep: /\.htaccess/,
}

module.exports = webpackConfig
