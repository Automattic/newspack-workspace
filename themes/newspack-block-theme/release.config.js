/* eslint-disable @typescript-eslint/no-var-requires */
module.exports = {
	branches: [
		'release',
		{
			name: 'alpha',
			prerelease: 'alpha',
		},
		{ name: 'hotfix/*', prerelease: '${name.replace(/\\//g, "-")}' },
		{ name: 'epic/*', prerelease: '${name.replace(/\\//g, "-")}' },
	],
	prepare: [
		'@semantic-release/changelog',
		'@semantic-release/npm',
		[
			'semantic-release-version-bump',
			{
				// build script is run before semantic-release, so the version in *.css files
				// have to be updated explicitly
				files: [ 'src/scss/_theme-description.scss', 'functions.php' ],
				callback: 'npm run release:archive',
			},
		],
		{
			path: '@semantic-release/git',
			assets: [ 'package.json', 'package-lock.json', 'CHANGELOG.md', 'src/scss/_theme-description.scss', 'functions.php' ],
			message: 'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
		},
	],
	plugins: [
		'@semantic-release/commit-analyzer',
		'@semantic-release/release-notes-generator',
		[
			'@semantic-release/npm',
			{
				npmPublish: false,
			},
		],
		'semantic-release-version-bump',
		[
			'@semantic-release/github',
			{
				// Migrated commits reference legacy-repo PR numbers absent from the
				// monorepo; disable PR/issue comment+label resolution so the release
				// job doesn't fail. Re-enable post-migration (NPPM-2752 Phase 6).
				successComment: false,
				releasedLabels: false,
				failComment: false,
				failTitle: false,
				assets: [
					{
						path: './release/newspack-block-theme.zip',
						label: 'newspack-block-theme.zip',
					},
				],
			},
		],
	],
};
