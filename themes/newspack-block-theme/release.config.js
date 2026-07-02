/* eslint-disable @typescript-eslint/no-var-requires */
const { gitCommitStep } = require( '../../config/release-helpers' );

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
				// build script is run before semantic-release, so the version in the
				// built (gitignored) *.css files has to be bumped explicitly here, before
				// release:archive zips them — otherwise the theme's style.css Version
				// header ships stale.
				files: [ 'src/scss/_theme-description.scss', 'functions.php', 'style.css', 'style-rtl.css' ],
				callback: 'npm run release:archive',
			},
		],
		...gitCommitStep( [ 'CHANGELOG.md', 'src/scss/_theme-description.scss', 'functions.php' ] ),
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
