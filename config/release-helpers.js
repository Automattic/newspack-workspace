/**
 * Whether the release is running on a `hotfix/*` or `epic/*` branch.
 *
 * The branch name is read from GITHUB_REF_NAME (set by GitHub Actions). Outside
 * CI it is undefined and this returns false, so local config evaluation is a
 * no-op.
 *
 * @return {boolean} True on a hotfix/* or epic/* branch.
 */
function isHotfixOrEpicBranch() {
	const branch = process.env.GITHUB_REF_NAME || '';
	return /^(hotfix|epic)\//.test( branch );
}

/**
 * The `@semantic-release/git` prepare step that commits a release's version
 * bumps back to the branch — or nothing at all on `hotfix/*` and `epic/*`.
 *
 * Out-of-schedule hotfix/epic prereleases build their zip and tag from the
 * working-tree bump, so committing the bumped files (PHP/CSS version headers,
 * CHANGELOG.md) back to the branch would only add `chore(release)` bot commits
 * and version churn to the hotfix PR diff. Legacy newspack-scripts skipped the
 * commit step on these branches for the same reason. (package.json is committed
 * separately by .github/scripts/finalize-package-versions.cjs, which release.yml
 * likewise skips on these branches.)
 *
 * Spread the result into a `prepare` array:
 *   prepare: [ ...otherSteps, ...gitCommitStep( [ phpFile, 'CHANGELOG.md' ] ) ]
 *
 * @param {string[]} assets Files to include in the release commit.
 * @return {Array} `[]` on hotfix/epic branches, otherwise `[ gitStep ]`.
 */
function gitCommitStep( assets ) {
	if ( isHotfixOrEpicBranch() ) {
		return [];
	}
	return [
		{
			path: '@semantic-release/git',
			assets,
			message:
				'chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}',
		},
	];
}

module.exports = { gitCommitStep };
