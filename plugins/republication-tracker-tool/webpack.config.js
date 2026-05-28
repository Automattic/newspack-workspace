const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const entry = {
	index: path.join( __dirname, 'src', 'index.js' ),
};

module.exports = getBaseWebpackConfig( { entry } );
