const postcssFocusWithin = require( 'postcss-focus-within' );
const autoprefixer = require( 'autoprefixer' );

module.exports = {
	plugins: [ autoprefixer(), postcssFocusWithin() ],
};
