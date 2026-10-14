module.exports = {
	extends: [ require.resolve( 'newspack-scripts/.eslintrc.js' ) ],
	globals: {
		newspack_urls: 'readonly',
		newspack_aux_data: 'readonly',
	},
	ignorePatterns: [ 'dist/', 'node_modules/' ],
};
