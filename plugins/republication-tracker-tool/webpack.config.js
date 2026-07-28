const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );
const { resolveSourceFile } = require( 'newspack-scripts/config/resolveSource' );

const entry = {
	index: resolveSourceFile( path.join( __dirname, 'src', 'index' ) ),
	'republish-button': resolveSourceFile(
		path.join( __dirname, 'src', 'blocks', 'republish-button', 'index' )
	),
	'republish-button-view': resolveSourceFile(
		path.join( __dirname, 'src', 'blocks', 'republish-button', 'view' )
	),
};

module.exports = getBaseWebpackConfig( { entry } );
