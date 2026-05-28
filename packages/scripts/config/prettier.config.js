const wpConfig = require( '@wordpress/prettier-config' );

module.exports = {
	...wpConfig,
	arrowParens: 'avoid',
	printWidth: 150,
};
