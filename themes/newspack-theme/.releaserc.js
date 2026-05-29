const THEMES = [
	'newspack-theme',
	'newspack-joseph',
	'newspack-katharine',
	'newspack-nelson',
	'newspack-sacha',
	'newspack-scott',
];

module.exports = {
	branches: [
		// `release` branch is published on the main distribution channel (a new version on GH).
		'release',
		// `alpha` branch – for regular pre-releases.
		{
			name: 'alpha',
			prerelease: true,
		},
		// `hotfix/*` branches – for releases outside of the release schedule.
		{
			name: 'hotfix/*',
			// With `prerelease: true`, the `name` would be used for the pre-release tag. A name with a `/`
			// is not valid, though. See https://semver.org/#spec-item-9.
			prerelease: '${name.replace(/\\//g, "-")}',
		},
		// `epic/*` branches – for beta testing/QA pre-release builds.
		{
			name: 'epic/*',
			// With `prerelease: true`, the `name` would be used for the pre-release tag. A name with a `/`
			// is not valid, though. See https://semver.org/#spec-item-9.
			prerelease: '${name.replace(/\\//g, "-")}',
		},
	],
	prepare: [
		'@semantic-release/changelog',
		'@semantic-release/npm',
		[
			'semantic-release-version-bump',
			{
				// build script is run before semantic-release, so the version in *.css files
				// have to be updated explicitly
				files: [ 'newspack-*/sass/theme-description.scss', 'newspack-*/style.css' ],
				callback: 'npm run release:archive',
			},
		],
		{
			path: '@semantic-release/git',
			assets: [
				...THEMES.map( name => `${ name }/sass/theme-description.scss` ),
				'CHANGELOG.md',
			],
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
				assets: THEMES.map( name => ( {
					path: `./release/${ name }.zip`,
					label: `${ name }.zip`,
				} ) ),
			},
		],
	],
};
