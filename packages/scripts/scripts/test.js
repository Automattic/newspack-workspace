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
	// Match .js, .jsx, .ts, and .tsx test files. Colocated TypeScript tests
	// (e.g. `Component.test.tsx`) live throughout the monorepo and would be
	// silently skipped by a JS-only glob.
	testMatch: [ '<rootDir>/**/*test.[jt]s?(x)' ],
	// Skip `release/` — newspack-plugin's `release:archive` script rsyncs the
	// source tree into `release/newspack-plugin/` for packaging, which would
	// otherwise duplicate every test run and fail on stale module paths.
	testPathIgnorePatterns: [ '/node_modules/', '/release/' ],
	transform: {
		'^.+\\.(j|t)sx?$': path.resolve( __dirname, 'utils/babelJestTransformer.js' ),
	},
	transformIgnorePatterns: [
		// Ignore all node_modules except for newspack-scripts, @wordpress/* packages, and
		// some transitive dependencies which distribute ES6 modules.
		//
		// The optional `.pnpm/<name>@<ver>/node_modules/` segment makes the allowlist
		// match under pnpm's nested store too: those packages resolve to a real path
		// like `node_modules/.pnpm/@wordpress+components@30.9.0_.../node_modules/@wordpress/components/...`,
		// where the leading `.pnpm/` segment would otherwise short-circuit the match
		// and leave the ES module source un-transformed (jest "unexpected token").
		'/node_modules/(?!(\\.pnpm/[^/]+/node_modules/)?(newspack-scripts|@wordpress|is-plain-obj|memize|uuid)/)',
	],
	moduleNameMapper: {
		'\\.(scss|css)$': path.resolve( __dirname, 'utils/babelJestTransformer.js' ),
	},
	modulePaths: [ path.resolve( modules.rootDirectory, 'node_modules' ), path.resolve( __dirname, '../node_modules' ) ],
	testEnvironment: 'jsdom',
	collectCoverageFrom: [ '**/*.{js,jsx}', '!**/node_modules/**', '!**/dist/**', '!**/vendor/**' ],
};

args.push( '--config', JSON.stringify( JEST_CONFIG ) );

const result = spawn.sync( wpScripts, args, {
	cwd: modules.rootDirectory,
	stdio: 'inherit',
	env: { ...process.env, NODE_ENV: 'development' },
} );

process.exit( result.status );
