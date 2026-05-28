const { exec } = require( 'child_process' );

const { version } = require( '../../package.json' );

const log = ( content, type ) => {
	console.log(
		`[newspack-scripts@${ version }]${ type ? `[${ type }]` : '' } ${ content }`
	);
};

const getGitBranch = () =>
	new Promise( ( resolve, reject ) => {
		return exec( 'git rev-parse --abbrev-ref HEAD', ( err, stdout ) => {
			if ( err ) {
				reject( `getGitBranch Error: ${ err }` );
			} else if ( typeof stdout === 'string' ) {
				resolve( stdout.trim() );
			}
		} );
	} );

module.exports = {
	log,
	getGitBranch,
};
