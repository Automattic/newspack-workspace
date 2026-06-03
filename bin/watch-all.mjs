#!/usr/bin/env node
//
// Global, lazy watch dispatcher for the monorepo.
//
// Run from the monorepo root (via `pnpm run watch`, which `n watch` invokes in
// the container). One chokidar process watches build-relevant source files
// across every plugin, theme and package. The FIRST time a unit's source
// changes, the dispatcher reacts based on that unit's package.json scripts:
//
//   - has a `watch` script  -> spawn the unit's own `npm run watch` once. That
//     persistent webpack watcher (wp-scripts start) then owns every later
//     rebuild for the unit incrementally, so the dispatcher steps back. This is
//     identical to `n watch <unit>` and keeps the module graph warm.
//   - no `watch`, has `build` -> run a debounced, serialized one-shot
//     `pnpm --filter <path> run --if-present build` on each change.
//   - neither -> log once and skip (nothing to compile).
//
// Webpack watchers are spawned lazily, only for units actually edited this
// session -- never all of them up front.
//
import chokidar from 'chokidar';
import { spawn } from 'node:child_process';
import readline from 'node:readline';
import { readFileSync } from 'node:fs';
import path from 'node:path';

const ROOT = process.cwd();
const AREAS = [ 'plugins', 'themes', 'packages' ];
// Source files worth reacting to. PHP is interpreted (no build step) and is
// served live off the bind mount, so it is intentionally excluded. block.json
// is included: it is imported by block entrypoints (`import metadata from
// './block.json'`) and is a real webpack input, so a cold unit whose first edit
// is block metadata must still start its watcher.
const BUILD_RELEVANT = /\.(jsx?|tsx?|scss|css)$|(^|\/)block\.json$/;
// Directories never worth watching: VCS, dependencies and build outputs.
const IGNORED_DIR = /(^|\/)(node_modules|vendor|\.git|dist|build)(\/|$)/;
const DEBOUNCE_MS = 300;

// Per-unit lifecycle state: 'warm' (own watcher running), 'oneshot' (no watch
// script, building on demand) or 'skip' (nothing to build).
const unitState = new Map();
const warmChildren = new Map(); // unit key -> spawned `npm run watch` child.
const debounceTimers = new Map(); // unit key -> pending one-shot timer.
const buildQueue = []; // units awaiting a serialized one-shot build.
let building = false;
let activeBuild = null; // the in-flight one-shot build child, for shutdown.
// One-shot units whose non-src change we've already explained, so the notice
// (see onChange) is logged at most once per unit rather than on every edit.
const oneShotNonSrcNoticed = new Set();

function log( message ) {
	process.stdout.write( `[watch-all] ${ message }\n` );
}

// Map a changed path (relative to ROOT, e.g. plugins/newspack-popups/src/x.js)
// to its workspace unit.
function resolveUnit( relPath ) {
	const [ area, name ] = relPath.split( '/' );
	if ( ! AREAS.includes( area ) || ! name ) {
		return null;
	}
	return {
		key: `${ area }/${ name }`,
		name,
		dir: path.join( ROOT, area, name ),
		// pnpm path filter -- the package name often differs from the directory
		// name (e.g. plugins/newspack-plugin is the `newspack` package), so a
		// path filter is the only reliable selector.
		filter: `./${ area }/${ name }`,
	};
}

function readScripts( dir ) {
	try {
		return JSON.parse( readFileSync( path.join( dir, 'package.json' ), 'utf8' ) ).scripts || {};
	} catch {
		return {};
	}
}

// Forward a child's output line-by-line, tagged with the unit name so
// interleaved watcher output stays readable.
function prefixStream( stream, label ) {
	readline.createInterface( { input: stream } ).on( 'line', ( line ) => {
		process.stdout.write( `[${ label }] ${ line }\n` );
	} );
}

function spawnWarmWatcher( unit ) {
	log( `${ unit.name }: starting incremental watcher (npm run watch)` );
	// detached so the child leads its own process group; killing -pid on
	// shutdown takes down npm and the webpack process it spawns together.
	const child = spawn( 'npm', [ 'run', 'watch' ], { cwd: unit.dir, detached: true } );
	warmChildren.set( unit.key, child );
	prefixStream( child.stdout, unit.name );
	prefixStream( child.stderr, unit.name );
	// Disarm only if this exact child is still the unit's tracked watcher, so a
	// late event from a replaced child can't delete a freshly re-armed one. Both
	// handlers may run for the same child (Node doesn't guarantee 'error' and
	// 'exit' are exclusive); the identity check makes that idempotent.
	const disarm = () => {
		if ( warmChildren.get( unit.key ) === child ) {
			warmChildren.delete( unit.key );
			unitState.delete( unit.key ); // allow a fresh spawn on next change.
		}
	};
	// Without an 'error' listener a failed spawn (ENOENT/EACCES) throws as an
	// unhandled exception and takes the whole dispatcher down.
	child.on( 'error', ( error ) => {
		log( `${ unit.name }: failed to start watcher (${ error.message }); will re-arm on next change` );
		disarm();
	} );
	child.on( 'exit', ( code ) => {
		log( `${ unit.name }: watcher exited (code ${ code }); will re-arm on next change` );
		disarm();
	} );
}

function scheduleOneShotBuild( unit ) {
	clearTimeout( debounceTimers.get( unit.key ) );
	debounceTimers.set(
		unit.key,
		setTimeout( () => {
			debounceTimers.delete( unit.key );
			if ( ! buildQueue.some( ( queued ) => queued.key === unit.key ) ) {
				buildQueue.push( unit );
			}
			drainBuildQueue();
		}, DEBOUNCE_MS )
	);
}

// One build at a time, to bound CPU when several no-watch units change at once.
function drainBuildQueue() {
	if ( building ) {
		return;
	}
	const unit = buildQueue.shift();
	if ( ! unit ) {
		return;
	}
	building = true;
	log( `${ unit.name }: building (one-shot)` );
	// detached so the child leads its own process group; killing -pid on
	// shutdown takes down pnpm and the build tooling it spawns together.
	const child = spawn(
		'pnpm',
		[ '--filter', unit.filter, 'run', '--if-present', 'build' ],
		{ cwd: ROOT, stdio: 'inherit', detached: true }
	);
	activeBuild = child;
	// Release the queue whether the build finishes or fails to spawn; without
	// the 'error' handler a spawn failure would leave `building` stuck true and
	// silently wedge every later one-shot build. Guard against running twice:
	// Node does not guarantee 'error' and 'exit' are mutually exclusive, and a
	// double release would null a *later* build's activeBuild and start a second
	// concurrent build, breaking the one-build-at-a-time invariant.
	let released = false;
	const release = () => {
		if ( released ) {
			return;
		}
		released = true;
		if ( activeBuild === child ) {
			activeBuild = null;
		}
		building = false;
		drainBuildQueue();
	};
	child.on( 'error', ( error ) => {
		log( `${ unit.name }: build failed to start (${ error.message })` );
		release();
	} );
	child.on( 'exit', ( code ) => {
		log( `${ unit.name }: build ${ code === 0 ? 'done' : `failed (code ${ code })` }` );
		release();
	} );
}

function onChange( relPath ) {
	if ( ! BUILD_RELEVANT.test( relPath ) ) {
		return;
	}
	const unit = resolveUnit( relPath );
	if ( ! unit ) {
		return;
	}
	let state = unitState.get( unit.key );
	if ( state === undefined ) {
		// First change for this unit -- classify it from its package.json scripts.
		const scripts = readScripts( unit.dir );
		state = scripts.watch ? 'warm' : scripts.build ? 'oneshot' : 'skip';
		unitState.set( unit.key, state );
		if ( state === 'warm' ) {
			spawnWarmWatcher( unit );
			return;
		}
		if ( state === 'skip' ) {
			log( `${ unit.name }: no watch/build script -- skipping (build manually with \`n build ${ unit.name }\`)` );
			return;
		}
	}
	if ( state !== 'oneshot' ) {
		return; // warm watcher owns rebuilds; skipped units have nothing to do.
	}
	// One-shot units have no incremental watcher to own their output, so a build
	// that writes build-relevant files (e.g. copied .scss) would re-trigger
	// itself endlessly. Gate on src/ -- build outputs land in dist/build/etc.,
	// never in src/ -- so only genuine source edits drive a rebuild. A one-shot
	// unit whose sources live outside src/ (e.g. at the package root or in lib/)
	// is unsupported by this gate; surface that once so it isn't silent.
	if ( ! relPath.startsWith( `${ unit.key }/src/` ) ) {
		if ( ! oneShotNonSrcNoticed.has( unit.key ) ) {
			oneShotNonSrcNoticed.add( unit.key );
			log( `${ unit.name }: ignoring change outside src/ (${ relPath }); one-shot builds only react to ${ unit.key }/src/` );
		}
		return;
	}
	scheduleOneShotBuild( unit );
}

function shutdown() {
	log( 'shutting down watchers' );
	// Drop pending debounced builds so nothing new is spawned mid-shutdown.
	for ( const timer of debounceTimers.values() ) {
		clearTimeout( timer );
	}
	debounceTimers.clear();
	for ( const child of warmChildren.values() ) {
		try {
			// Guard against an undefined pid when spawn failed before a child
			// process existed (e.g. ENOENT).
			if ( child.pid ) {
				process.kill( -child.pid, 'SIGTERM' );
			}
		} catch {
			// Child already gone -- nothing to clean up.
		}
	}
	// Kill the in-flight one-shot build's process group too. Like the warm
	// watchers it is spawned detached, so -pid takes down pnpm and the build
	// tooling it spawns together (a direct kill would leave those descendants
	// orphaned on the programmatic SIGTERM path).
	if ( activeBuild && activeBuild.pid ) {
		try {
			process.kill( -activeBuild.pid, 'SIGTERM' );
		} catch {
			// Already exited -- nothing to clean up.
		}
	}
	process.exit( 0 );
}

const watcher = chokidar.watch( AREAS, {
	cwd: ROOT,
	// pnpm symlinks workspace packages back under plugin dirs (e.g.
	// plugins/newspack-plugin/packages/components -> packages/components). Without
	// this, one edit fires twice -- once per path -- and the symlinked copy is
	// misattributed to the wrong unit. All real sources live under real dirs.
	followSymlinks: false,
	ignored: ( testPath ) => IGNORED_DIR.test( testPath ),
	ignoreInitial: true,
	awaitWriteFinish: { stabilityThreshold: 200, pollInterval: 50 },
} );

watcher
	.on( 'add', onChange )
	.on( 'change', onChange )
	.on( 'ready', () => {
		log( 'watching plugins/, themes/, packages/ for source changes' );
		log( 'each unit\'s watcher starts on its first edit (Ctrl-C to stop)' );
	} )
	.on( 'error', ( error ) => log( `watcher error: ${ error.message }` ) );

process.on( 'SIGINT', shutdown );
process.on( 'SIGTERM', shutdown );
