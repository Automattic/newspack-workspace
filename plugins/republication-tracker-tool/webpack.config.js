const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const { resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );

const entry = {
	index: resolveSourceFile( path.join( __dirname, 'src', 'index' ) ),
};

module.exports = getBaseWebpackConfig( { entry } );
