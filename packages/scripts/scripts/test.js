'use strict';

process.env.BABEL_ENV = 'test';
process.env.NODE_ENV = 'test';

const path = require( 'path' );

const spawn = require( 'cross-spawn' );
const modules = require( './utils/modules' );
const utils = require( './utils/index.js' );
const wpScripts = require.resolve( '@wordpress/scripts/bin/wp-scripts' );

utils.log( 'Starting tests…' );

const args = process.argv.slice( 2 );
args.push( 'test-unit-js' );

const JEST_CONFIG = {
	rootDir: modules.rootDirectory,
	setupFilesAfterEnv: [ path.resolve( __dirname, 'utils/jestSetup.js' ) ],
	testMatch: [ '<rootDir>/**/*test.js?(x)' ],
	transform: {
		'^.+\\.(j|t)sx?$': path.resolve( __dirname, 'utils/babelJestTransformer.js' ),
	},
	transformIgnorePatterns: [
		// Ignore all node_modules except for newspack-scripts, @wordpress/* packages, and
		// some transitive dependencies which distribute ES6 modules.
		'/node_modules/(?!(newspack-scripts|@wordpress|is-plain-obj|memize)/)',
	],
	moduleNameMapper: {
		'\\.(scss|css)$': path.resolve( __dirname, 'utils/babelJestTransformer.js' ),
	},
	modulePaths: [
		path.resolve( modules.rootDirectory, 'node_modules' ),
		path.resolve( __dirname, '../node_modules' ),
	],
	testEnvironment: 'jsdom',
	collectCoverageFrom: [
		'**/*.{js,jsx}',
		'!**/node_modules/**',
		'!**/dist/**',
		'!**/vendor/**',
	],
};

args.push( '--config', JSON.stringify( JEST_CONFIG ) );

const result = spawn.sync( wpScripts, args, {
	cwd: modules.rootDirectory,
	stdio: 'inherit',
	env: { ...process.env, NODE_ENV: 'development' },
} );

process.exit(result.status);