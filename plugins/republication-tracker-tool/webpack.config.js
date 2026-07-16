const path = require( 'path' );
const getBaseWebpackConfig = require( 'newspack-scripts/config/getWebpackConfig' );

const entry = {
	index:                   path.join( __dirname, 'src', 'index.js' ),
	'republish-button':      path.join( __dirname, 'src', 'blocks', 'republish-button', 'index.js' ),
	'republish-button-view': path.join( __dirname, 'src', 'blocks', 'republish-button', 'view.js' ),
};

module.exports = getBaseWebpackConfig( { entry } );
