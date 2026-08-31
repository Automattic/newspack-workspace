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
		// languages/** carries the translation files release.yml regenerates just
		// before multi-semantic-release runs (see config/release.js).
		...gitCommitStep( [ 'CHANGELOG.md', 'src/scss/_theme-description.scss', 'functions.php', 'languages/**' ] ),
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
				// A release failure is surfaced by the workflow itself, so
				// semantic-release does not also open an issue for it.
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
