#!/usr/bin/env node
'use strict';

/**
 * Commit the package.json version bumps from a release run, keeping internal
 * `workspace:*` deps intact — in a single commit, after all packages released.
 *
 * Why this exists: the release configs deliberately DON'T list package.json in
 * the @semantic-release/git prepare commit. That keeps the concrete dependency
 * versions multi-semantic-release writes during `prepare` off the branch (they'd
 * break the next `pnpm install --frozen-lockfile`, since the root lockfile is
 * keyed to workspace:*), and it lets the npm `publish` phase read the
 * concretized working-tree manifest so published packages still get real dep
 * versions. The only thing not committing package.json loses is an up-to-date
 * `version` on the branch — which this restores.
 *
 * After multi-semantic-release finishes, each released package's working-tree
 * package.json carries msr's changes (bumped version + concretized deps),
 * uncommitted. This script takes that working tree, reverts the internal deps
 * back to `workspace:*` (so the branch stays lockfile-consistent), and commits.
 * The net committed change is therefore a pure version bump — the version is
 * msr's, which is unambiguously what this run released (unlike a git tag, whose
 * "latest" is ambiguous once alpha and release lines share history).
 *
 * Run from the repo root after multi-semantic-release, before pushing. Commits
 * the changed manifests with [skip ci]; the caller pushes.
 *
 * packages/* are intentionally not handled here — they have no internal
 * workspace deps, so their own release configs still commit package.json
 * directly (no lockfile hazard).
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execSync, execFileSync } = require( 'child_process' );

const root = execSync( 'git rev-parse --show-toplevel', { encoding: 'utf8' } ).trim();
process.chdir( root );

// Internal workspace package names, derived from packages/*/package.json.
const workspaceNames = new Set();
if ( fs.existsSync( 'packages' ) ) {
	for ( const entry of fs.readdirSync( 'packages' ) ) {
		const manifestPath = path.join( 'packages', entry, 'package.json' );
		if ( ! fs.existsSync( manifestPath ) ) {
			continue;
		}
		try {
			const pkgName = JSON.parse( fs.readFileSync( manifestPath, 'utf8' ) ).name;
			if ( pkgName ) {
				workspaceNames.add( pkgName );
			}
		} catch ( e ) {
			// Skip an unparseable package.json.
		}
	}
}
if ( workspaceNames.size === 0 ) {
	// In this monorepo there is always at least one shared package; an empty set
	// means the root misresolved. Fail loud rather than silently shipping
	// concretized deps.
	console.error( '[finalize-package-versions] no workspace package names found — aborting.' );
	process.exit( 1 );
}

const changedPaths = [];
for ( const group of [ 'plugins', 'themes' ] ) {
	if ( ! fs.existsSync( group ) ) {
		continue;
	}
	for ( const entry of fs.readdirSync( group ) ) {
		const rel = path.join( group, entry, 'package.json' );
		if ( ! fs.existsSync( rel ) ) {
			continue;
		}
		const source = fs.readFileSync( rel, 'utf8' );
		const indentMatch = source.match( /\n(\t+|[ ]+)"/ );
		const indent = indentMatch ? indentMatch[ 1 ] : '\t';
		const trailingNewline = source.endsWith( '\n' ) ? '\n' : '';
		const manifest = JSON.parse( source );
		let dirty = false;
		for ( const section of [ 'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies' ] ) {
			if ( ! manifest[ section ] ) {
				continue;
			}
			for ( const dep of Object.keys( manifest[ section ] ) ) {
				if ( workspaceNames.has( dep ) && manifest[ section ][ dep ] !== 'workspace:*' ) {
					manifest[ section ][ dep ] = 'workspace:*';
					dirty = true;
				}
			}
		}
		if ( dirty ) {
			fs.writeFileSync( rel, JSON.stringify( manifest, null, indent ) + trailingNewline );
		}
		changedPaths.push( rel );
	}
}

// Stage every plugin/theme manifest; git only records the ones that actually
// differ from HEAD (msr-bumped version and/or the reverted deps).
for ( const rel of changedPaths ) {
	execFileSync( 'git', [ 'add', '--', rel ] );
}

const staged = execSync( 'git diff --cached --name-only', { encoding: 'utf8' } ).split( '\n' ).filter( Boolean );
if ( staged.length === 0 ) {
	console.log( '[finalize-package-versions] no package.json changes to commit.' );
	process.exit( 0 );
}
execSync( 'git commit -m "chore(release): sync package.json versions [skip ci]"', { stdio: 'inherit' } );
console.log( `[finalize-package-versions] committed version sync for:\n  ${ staged.join( '\n  ' ) }` );
