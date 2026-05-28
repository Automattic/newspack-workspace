'use strict';

const utils = require( './utils/index.js' );

const semanticRelease = require( 'semantic-release' );

const { files, ...otherArgs } = require( 'yargs/yargs' )(
	process.argv.slice( 2 )
).parse();

const filesList = files.split( ',' );

utils.log( `Releasing ${ process.env.CIRCLE_PROJECT_REPONAME }…` );

const getConfig = ({ gitBranchName }) => {
	const branchType = gitBranchName.split("/")[0];
	const githubConfig = {
		assets: [
			{
				path: `./release/${process.env.CIRCLE_PROJECT_REPONAME}.zip`,
				label: `${process.env.CIRCLE_PROJECT_REPONAME}.zip`,
			},
		],
	};

	// Only post GH PR comments for alpha, hotfix/*, and release branches.
	if ( ! ["alpha", "hotfix", "release"].includes(branchType) ) {
		githubConfig.successComment = false;
		githubConfig.failComment = false;
	}

	// Only publish alpha and release branches to NPM.
	const shouldPublishOnNPM = Boolean( process.env.NPM_TOKEN ) && ["alpha", "release"].includes(branchType);
	if ( shouldPublishOnNPM ) {
		utils.log( `Will publish to npm.` );
	}

	const config = {
		dryRun: otherArgs.dryRun,
		ci: otherArgs.ci,
		debug: otherArgs.debug,

		branches: [
			// `release` branch is published on the main distribution channel (a new version on GH).
			"release",
			// `alpha` branch – for regular pre-releases.
			{
				name: "alpha",
				prerelease: true,
			},
			// `hotfix/*` branches – for releases outside of the release schedule.
			{
				name: "hotfix/*",
				// With `prerelease: true`, the `name` would be used for the pre-release tag. A name with a `/`
				// is not valid, though. See https://semver.org/#spec-item-9.
				prerelease: '${name.replace(/\\//g, "-")}',
			},
			// `epic/*` branches – for beta testing/QA pre-release builds.
			{
				name: "epic/*",
				// With `prerelease: true`, the `name` would be used for the pre-release tag. A name with a `/`
				// is not valid, though. See https://semver.org/#spec-item-9.
				prerelease: '${name.replace(/\\//g, "-")}',
			},
		],
		prepare: ["@semantic-release/changelog", "@semantic-release/npm"],
		plugins: [
			"@semantic-release/commit-analyzer",
			"@semantic-release/release-notes-generator",
			[
				// Whether to publish on npm.
				"@semantic-release/npm",
				{
					npmPublish: shouldPublishOnNPM,
				},
			],
			"semantic-release-version-bump",
			// Add the built ZIP archive to GH release.
			[
				"@semantic-release/github",
				githubConfig,
			],
		],
	};

	// Bump the semver and prepare a build package.
	config.prepare.push( [
		// Increment the version in additional files, and the create the release archive.
		'semantic-release-version-bump',
		{
			files: filesList,
			callback: 'npm run release:archive',
		},
	] );

	// Unless on a hotfix or epic branch, add a commit that updates the files.
	if ( [ 'hotfix', 'epic' ].indexOf( branchType ) === -1 ) {
		let assets = filesList;
		// These assets should be added to source control after a release.
		if ( branchType === 'release' ) {
			assets = [
				...filesList,
				'package.json',
				'package-lock.json',
				'CHANGELOG.md',
			];
		}
		utils.log(
			`On ${ branchType } branch, following files will be updated: ${ assets.join(
				', '
			) }`
		);
		config.prepare.push( {
			path: '@semantic-release/git',
			assets,
			message:
				'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
		} );
	} else {
		utils.log(
			`This branch is ${ branchType }, plugin files and the changelog will *not* be updated.`
		);
	}

	return config;
};

const run = async () => {
	try {
		const gitBranch = await utils.getGitBranch();

		const result = await semanticRelease.default(
			getConfig( { gitBranchName: gitBranch } )
		);

		if ( result ) {
			const { lastRelease, commits, nextRelease, releases } = result;

			utils.log(
				`Published ${ nextRelease.type } release version ${ nextRelease.version } containing ${ commits.length } commits.`
			);

			if ( lastRelease.version ) {
				utils.log( `The last release was "${ lastRelease.version }".` );
			}

			for ( const release of releases ) {
				utils.log(
					`The release was published with plugin "${ release.pluginName }".`
				);
			}
		} else {
			utils.log( 'No release published.' );
		}
	} catch ( err ) {
		console.error( 'The automated release failed with %O', err );
		process.exit( 1 );
	}
};

run();
