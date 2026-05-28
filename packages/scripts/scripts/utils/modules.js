const fs = require( 'fs' );

const rootDirectory = fs.realpathSync( process.cwd() );

module.exports = {
	rootDirectory,
	// Get webpack bundle args for `build` and `start` commands.
	buildArgs: ( cmd, args = [] ) => {
		if ( 'build' !== cmd && 'start' !== cmd ) {
			return [ cmd, ...args ];
		}

		const defaults = [
			'--config',
			'webpack.config.js',
		];

		// Default build path: ./dist
		if ( ! args.includes( '--output-path' ) ) {
			defaults.push( '--output-path', 'dist' );
		}

		return [ cmd, ...defaults, ...args ];
	},
};
