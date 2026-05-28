module.exports = {
	extends: [ './node_modules/newspack-scripts/.eslintrc.js' ],
	ignorePatterns: [ 'dist/', 'node_modules/' ],
	rules: {
		'no-nested-ternary': 'off',
		'react/display-name': 'off',
	},
};
