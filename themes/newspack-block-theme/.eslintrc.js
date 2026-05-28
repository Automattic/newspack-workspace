require( '@rushstack/eslint-patch/modern-module-resolution' );

module.exports = {
	extends: [ './node_modules/newspack-scripts/config/eslintrc.js' ],
	globals: {
		newspack_urls: 'readonly',
		newspack_aux_data: 'readonly',
	},
	rules: {
		'no-console': 'off',
	},
	ignorePatterns: [ 'dist/', 'node_modules/', 'assets/components/node_modules' ],
};
