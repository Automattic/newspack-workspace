/**
 * Shared semantic-release config for the workspace's published npm libraries:
 * newspack-scripts, newspack-components, newspack-colors, newspack-icons.
 *
 * Mirrors config/release.js (used by the plugins) but without a PHP version
 * file — a library's version lives only in package.json. @semantic-release/git
 * commits the bumped package.json (and CHANGELOG.md) back so the in-repo
 * manifest tracks the published version. Without it the manifest stays frozen
 * while the npm/git-tag version advances, which is how newspack-scripts drifted
 * to 5.8.0 in-repo while publishing 5.9.x before the monorepo.
 *
 * Note: multi-semantic-release derives tags from the package's npm name as
 * `<npmName>@<version>` (with the npm scope stripped via patch) and ignores any
 * tagFormat here. These libraries are all unscoped, so the patch is a no-op for
 * them.
 */
const { gitCommitStep } = require( './release-helpers' );

module.exports = {
	branches: [
		'release',
		{ name: 'alpha', prerelease: true },
		// hotfix/* and epic/* branches no longer publish prerelease tags:
		// CI's build-zips job already produces an installable zip for every
		// commit, so the tags and their builds were redundant.
	],
	plugins: [
		'@semantic-release/commit-analyzer',
		'@semantic-release/release-notes-generator',
		'@semantic-release/npm',
		[ '@semantic-release/github', { successComment: false, releasedLabels: false, failComment: false, failTitle: false } ],
	],
	prepare: [
		'@semantic-release/changelog',
		'@semantic-release/npm',
		// CHANGELOG.md only, and only on `release` — same contract as the plugins
		// in config/release.js. package.json is deliberately NOT committed here:
		// @semantic-release/npm concretizes internal `workspace:*` deps in the
		// working tree before publishing, so committing the manifest would put
		// those concrete pins on the branch. That breaks the next
		// `pnpm install --frozen-lockfile` (the root lockfile is keyed to
		// workspace:*) and, once installed, makes pnpm resolve the shared
		// packages from the npm registry instead of linking packages/ — the
		// registry tarballs ship raw JSX in src/, outside every plugin's
		// babel-loader scope. The published tarballs still get real dep versions,
		// because the npm publish phase reads the concretized working tree.
		// The version bump itself is committed by
		// .github/scripts/finalize-package-versions.cjs, which reverts the deps
		// back to workspace:* first.
		...gitCommitStep( [ 'CHANGELOG.md' ] ),
	],
};
