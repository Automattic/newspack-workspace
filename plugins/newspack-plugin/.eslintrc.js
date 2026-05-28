module.exports = {
	extends: [ './node_modules/newspack-scripts/.eslintrc.js' ],
	globals: {
		newspack_urls: 'readonly',
		newspack_aux_data: 'readonly',
	},
	ignorePatterns: [ '*/dist/', '*/node_modules/', '*/release' ],
	rules: {
		'@wordpress/i18n-no-flanking-whitespace': 'off',
	},
};
