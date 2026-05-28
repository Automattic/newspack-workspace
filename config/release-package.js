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
 * Note: multi-semantic-release namespaces tags as `<pkgName>@<version>` and
 * ignores any tagFormat here.
 */
module.exports = {
	branches: [
		'release',
		{ name: 'alpha', prerelease: true },
		{ name: 'hotfix/*', prerelease: '${name.replace(/\\//g, "-")}' },
		{ name: 'epic/*', prerelease: '${name.replace(/\\//g, "-")}' },
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
		{
			path: '@semantic-release/git',
			assets: [ 'package.json', 'CHANGELOG.md' ],
			message:
				'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
		},
	],
};
