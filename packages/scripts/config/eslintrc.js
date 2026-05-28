/**
 *  Assume `@wordpress/*` packages are available. This is because `@wordpress/scripts` is using
 * Dependency Extraction Webpack Plugin to use core WP packages instead of those from
 * node_modules. The packages should still be part of the project (they are listed in this
 * project's package.json) so that they are available in testing environment.
 *
 * More on this:
 * - https: *www.npmjs.com/package/@wordpress/dependency-extraction-webpack-plugin
 * - https: *github.com/WordPress/gutenberg/issues/35630
 *
 * React is also included here as it's a peer dependency of @wordpress/scripts.
 */
const GLOBALLY_AVAILABLE_PACKAGES = [ '@wordpress/.*', 'react' ];

module.exports = {
	extends: [
		'plugin:import/errors',
		'plugin:import/warnings',
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:@wordpress/eslint-plugin/react',
	],
	env: {
		browser: true,
		jest: true,
	},
	parser: '@typescript-eslint/parser',
	settings: {
		'import/resolver': {
			node: {
				extensions: [ '.js', '.jsx', '.ts', '.tsx' ],
			},
		},
	},
	ignorePatterns: [
		'dist/',
		'node_modules/',
		'release/',
		'scripts/',
		'/vendor',
	],
	rules: {
		'arrow-parens': 'off',
		camelcase: 'off',
		// Disallow logging.
		'no-console': 'error',
		'no-mixed-operators': 'off',
		'wrap-iife': 'off',
		// Some dependencies are injected by WP, and should not be declared in package.json (won't be used anyway).
		// See https://github.com/WordPress/gutenberg/blob/e035f71/packages/dependency-extraction-webpack-plugin/README.md#behavior-with-scripts
		// Unfortunately there's no "ignore" option for this rule, so it's disabled altogether.
		'import/no-extraneous-dependencies': 'off',
		'import/no-unresolved': [
			'error',
			{ ignore: GLOBALLY_AVAILABLE_PACKAGES },
		],
		'import/namespace': 'off',
		// There's a conflict with prettier here:
		'react/jsx-curly-spacing': 'off',
		// Skip prop types validation for now
		'react/prop-types': 'off',
		'react/react-in-jsx-scope': 'off',
		'react/self-closing-comp': 'error',
		'react-hooks/exhaustive-deps': 'off',
		// JSDoc rules overrides
		'jsdoc/require-returns': 'off',
		'jsdoc/require-param': 'off',
		// Deprecated rules
		'jsx-a11y/no-onchange': 'off',
		'@typescript-eslint/no-empty-function': 'off',
		// Fail on unused vars.
		'@typescript-eslint/no-unused-vars': 'error',
		// Handle the issue where no-shadow is a false positive when declaring TS enums.
		// See https://github.com/typescript-eslint/typescript-eslint/issues/2483
		'no-shadow': 'off',
		'@typescript-eslint/no-shadow': 'error',
		'@typescript-eslint/ban-ts-comment': 'warn',
		'@typescript-eslint/no-explicit-any': 'warn',
		'@typescript-eslint/no-require-imports': 'off',
	},
};
