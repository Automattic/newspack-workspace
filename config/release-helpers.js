/**
 * Whether the current release run is on the stable `release` branch.
 *
 * The branch name is read from GITHUB_REF_NAME (set by GitHub Actions). Outside
 * CI it is undefined and this returns false, so local config evaluation is a
 * no-op.
 *
 * @return {boolean} True only on the `release` branch.
 */
function isReleaseBranch() {
	return ( process.env.GITHUB_REF_NAME || '' ) === 'release';
}

/**
 * The `@semantic-release/git` prepare step that commits a release's version
 * bumps back to the branch — but ONLY on the stable `release` branch.
 *
 * Version and changelog stamps are authored on a single channel. The `release`
 * branch is the source of truth for CHANGELOG.md and the committed version
 * headers; prerelease channels (`alpha`, `hotfix/*`, `epic/*`) still compute
 * their version and build their zip/tag from the working-tree bump, but they do
 * NOT commit it back to the branch. This buys two things:
 *
 *   1. The committed CHANGELOG.md only ever contains stable release sections —
 *      no `-alpha.N` / hotfix prerelease entries leak in as cross-channel noise.
 *      Individual `fix:` / `feat:` commits are not lost: they roll up into the
 *      next stable version's notes when promoted to `release`.
 *   2. Only one branch ever edits the version-stamp lines, so syncing `release`
 *      back into `alpha` / the default branch (post-release.sh) no longer
 *      collides on CHANGELOG.md / version headers — the recurring post-release
 *      merge conflict is eliminated at the source.
 *
 * (package.json is committed separately by
 * .github/scripts/finalize-package-versions.cjs, which release.yml likewise
 * runs only on the `release` branch.)
 *
 * Spread the result into a `prepare` array:
 *   prepare: [ ...otherSteps, ...gitCommitStep( [ phpFile, 'CHANGELOG.md' ] ) ]
 *
 * @param {string[]} assets Files to include in the release commit.
 * @return {Array} `[ gitStep ]` on the `release` branch, otherwise `[]`.
 */
function gitCommitStep( assets ) {
	if ( ! isReleaseBranch() ) {
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
