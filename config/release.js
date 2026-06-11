const { gitCommitStep } = require( './release-helpers' );

/**
 * Shared release config factory for multi-semantic-release.
 *
 * Each plugin/theme calls this with its own options; everything else
 * (branches, plugin chain, prepare steps) is identical across the monorepo.
 *
 * @param {Object}  opts
 * @param {string}  opts.name        Package directory name, used for the zip asset path and label. multi-semantic-release derives git tags from the package's npm name as `<npmName>@<version>` (patched to strip the npm scope, so `@automattic/newspack-blocks` tags as `newspack-blocks@<version>`); this `name` does not affect tagging.
 * @param {string}  opts.phpFile     Main PHP file to bump the version in.
 * @param {boolean} [opts.npmPublish=false] Whether to publish to npm.
 */
module.exports = function releaseConfig( { name, phpFile, npmPublish = false } ) {
	return {
		branches: [
			'release',
			{ name: 'alpha', prerelease: true },
			{ name: 'hotfix/*', prerelease: '${name.replace(/\\//g, "-")}' },
			{ name: 'epic/*', prerelease: '${name.replace(/\\//g, "-")}' },
		],
		plugins: [
			'@semantic-release/commit-analyzer',
			'@semantic-release/release-notes-generator',
			[ '@semantic-release/npm', { npmPublish } ],
			[
				'semantic-release-version-bump',
				{
					files: [ phpFile ],
					callback: 'npm run release:archive',
				},
			],
			[
				'@semantic-release/github',
				{
					// Migrated commits reference legacy-repo PR numbers that don't
					// exist as monorepo issues; the success step resolves those refs
					// to comment on AND label them, failing the release job. Disable
					// both. Re-enable post-migration (NPPM-2752 Phase 6).
					successComment: false,
					releasedLabels: false,
					failComment: false,
					failTitle: false,
					assets: [
						{
							path: `./release/${ name }.zip`,
							label: `${ name }.zip`,
						},
					],
				},
			],
		],
		prepare: [
			'@semantic-release/changelog',
			'@semantic-release/npm',
			[
				'semantic-release-version-bump',
				{
					files: [ phpFile ],
					callback: 'npm run release:archive',
				},
			],
			...gitCommitStep( [ phpFile, 'CHANGELOG.md' ] ),
		],
	};
};
