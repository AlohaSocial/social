/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @nextcloud/eslint-config 9 dropped the .eslintrc format along with ESLint 9,
// so this is the flat-config successor to .eslintrc.js. `recommendedJavascript`
// is the Vue 3 ruleset that reads <script> blocks as Javascript; the plain
// `recommended` export parses them as Typescript, which this app does not use.
import { recommendedJavascript } from '@nextcloud/eslint-config'

/**
 * The three rules from the shared config that this codebase does not adopt.
 *
 * Everything else it turns on — about 1,100 reports across 126 files of
 * whitespace, blank lines, indentation, braces and attribute casing — has been
 * applied. What is left off is not formatting: each one would change what the
 * code says rather than how it looks.
 */
const notAdopted = {
	// 247 reports. Sorting imports detaches the comments that explain why
	// particular imports exist, and several here are side-effect imports whose
	// comment is the only thing saying so.
	'perfectionist/sort-imports': 'off',
	'perfectionist/sort-named-imports': 'off',
	// 8 reports, none fixable: this would rename components — Search.vue,
	// Poll.vue and friends — which is a change to what templates say, not to
	// how the file is laid out.
	'vue/multi-word-component-names': 'off',
	'vue/no-reserved-component-names': 'off',
	// 10 reports. Every boolean prop would have to default to false, which
	// turns `link` and `hoverCard` into `noLink` and `noHoverCard` at every
	// call site: a negated name to spell a default, where `:link="false"`
	// already says what it means.
	'vue/no-boolean-default': 'off',
}

export default [
	...recommendedJavascript,
	{
		languageOptions: {
			globals: {
				appName: 'readonly',
				// webpack replaces this at build time; src/store/index.js reads it
				process: 'readonly',
				__webpack_nonce__: 'writable',
				__webpack_public_path__: 'writable',
			},
		},
		rules: {
			...notAdopted,
			// this app mounts several roots; the rule is a Vue 2 leftover
			'vue/no-multiple-template-root': 'off',
			// the two webpack globals above are the only snake_case names allowed
			camelcase: ['error', {
				properties: 'never',
				ignoreDestructuring: true,
				allow: ['__webpack_nonce__', '__webpack_public_path__'],
			}],
			'no-console': 'warn',
			// Function declarations and module-level consts referenced from
			// bodies defined above them: hoisting-safe, because the functions
			// only run once the module has finished evaluating, and several
			// files here deliberately read top-down from entry point to helper.
			// `classes` stays on — a class read before its declaration is a
			// genuine temporal-dead-zone error.
			'no-use-before-define': ['error', { functions: false, classes: true, variables: false }],
		},
	},
	{
		// The build and tooling configuration at the repository root: CommonJS
		// modules Node runs directly, not browser code webpack bundles.
		// vitest.config.js and this file are ESM and are covered below.
		files: ['babel.config.js', 'stylelint.config.js', 'webpack.common.js'],
		languageOptions: {
			sourceType: 'commonjs',
			globals: {
				module: 'writable',
				require: 'readonly',
				process: 'readonly',
				__dirname: 'readonly',
			},
		},
	},
	{
		// the two root files that are ES modules
		files: ['vitest.config.js', 'eslint.config.mjs'],
		languageOptions: {
			sourceType: 'module',
			globals: { process: 'readonly', __dirname: 'readonly' },
		},
	},
	{
		// vitest runs with globals enabled, so the specs do not import these.
		// vitest.config.js collects specs from both trees.
		files: ['tests/js/**/*.js', 'src/**/*.{test,spec}.js'],
		languageOptions: {
			globals: {
				afterAll: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				beforeEach: 'readonly',
				describe: 'readonly',
				expect: 'readonly',
				it: 'readonly',
				test: 'readonly',
				vi: 'readonly',
			},
		},
	},
]
