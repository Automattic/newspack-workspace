# newspack-scripts

Scripts for Newspack, heavily inspired by [`react-scripts`](https://github.com/facebook/create-react-app/blob/main/packages/react-scripts).

---

## Available scripts

Repos consuming the `newspack-scripts` package can use the following NPM scripts. Prefix each at the command line with `npm run` (or [`bun run`](https://bun.sh/)) to execute.

### start

Execute with `npm start`. This is the only script you run without the `run` prefix. This will install Composer and NPM dependencies, then run the `watch` command to start a development build. Best used when cloning a repo for the first time, or when you need to restore a locally cloned repo to a fresh state.

### build

Will run `wp-scripts build` to create optimised production builds.

### watch

Will run `wp-scripts start` to start a development build in devserver/watch mode.

### lint:js, lint:scss, lint

Will run `wp-scripts lint-js`, `wp-scripts lint-style`, or both. See the [`@wordpress/scripts` handbook](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/) for implementation details and additional options.

### fix:js

Will run `wp-scripts lint-js --fix`, allowing ESLint to correct any autofixable code quality errors it finds. Note that code quality errors are separate from formatting, which is handled by `format:js` (see below).

### format:js

Will run `wp-scripts format-js` to reformat JS files according to [Prettier](https://prettier.io/) rules. Note that formatting is separate from code quality errors, which are handled by `fix:js` (see above).

### format:scss

Will run `wp-scripts lint-style --fix` to reformat SCSS files according to [Stylelint](https://stylelint.io/) rules.

### test

Will run `jest` tests. Useful flags:

- `--watch` to run in file watch mode,
- `--coverage` to collect test coverage

### lint:php, fix:php

Will run `phpcs` or `phpcbf` to lint or autofix PHP files, respectively. Note that you must install these PHP packages via `composer install` or `npm start` before you can use these locally.

### commit

Uses [`commitizen`](https://www.npmjs.com/package/commitizen) to create a structured commit message.

### commitlint

Lints to commit message, to be used in a git `commit-msg` hook.

### release

Will run [`semantic-release`](semantic-release.gitbook.io/) based on a very opinionated configuration.

### typescript-check

Will validate TypeScript code in the project. This requires a `tsconfig.json` file to be placed in the project root. Example:

```json
{
	"extends": "newspack-scripts/config/tsconfig.json",
	"compilerOptions": {
		"rootDir": "src",
		"jsx": "react-jsx"
	},
	"include": [
		"src",
		"src/**/*.json"
	]
}
```

---

## Semantic Release

This package contains a configuration of [`semantic-release`](semantic-release.gitbook.io/), which can be used for automated software releases, published on Github. It's configured to work with the following repository branch setup:

1. `trunk` – ongoing development
1. `alpha` – release candidate
1. `hotfix/*` - for testing urgent bugfixes
1. `epic/*` - for testing large-scale features
1. `release` – the production-ready, released code

The following assumes that CI will run:

1. `npm run release` for `release`, `alpha`, `hotfix/*`, and `epic/*` branches
1. `post-release.sh` script on `release` branch, after the above command completes

### Regular release flow

1. Create a new branch off the `trunk` branch.
1. Commit changes to your branch using [structured commit messages](https://www.conventionalcommits.org/en/v1.0.0/).
1. Open a pull request for review based on `trunk`. Changes must be tested and approved before merging.
1. Merge approved changes to the `trunk` branch. When merging into `trunk`, SQUASH the merge.
1. Merge `trunk` into `alpha` to create a release candidate (e.g. `1.2.0-alpha.1`). When merging `trunk` into `alpha`, DO NOT SQUASH the merge.
1. Merge `alpha` into `release` to create a production release (e.g. `1.2.0`). When merging `alpha` into `release`, DO NOT SQUASH the merge.
1. `alpha` branch will be reset on top of `release`.
1. `trunk` branch will be updated with the changes from the `release` branch.

### Epic feature release flow

For large-scale features that require more than one interdependent branch throughout development.

1. Create a new `epic/*` branch off the `trunk` branch. Push the branch to GitHub so all engineers can work off it simultaneously. **Keep this branch up-to-date with `trunk`, to minimize the risk of merge conflicts.**
1. Create new sub-branches off the epic branch. **Keep sub-branches up-to-date with the `epic/*` branch, to minimize the risk of merge conflicts.**
1. Commit changes to your sub-branches using [structured commit messages](https://www.conventionalcommits.org/en/v1.0.0/).
1. Open pull requests for review based on the `epic/*` branch. Changes must be tested and approved before merging.
1. Merge approved changes to the `epic/*` branch. When merging into `epic/*`, DO NOT SQUASH the merge.
1. A new "epic" pre-release (e.g. `1.2.0-epic-feature.1`) will be tagged and published when changes are merged via PR. Use epic releases for QA and other pre-release testing.
1. Once all features in the `epic/*` branch have been tested and approved, open a pull request for final review based on `trunk`. Final review doesn't require full-scale functional testing, only a review of the changeset (as changes have already been tested in individual PRs).
1. Merge the `epic/*` branch to the `trunk` branch. When merging an epic branch into `trunk`, SQUASH the merge.
1. Once `epic/*` has been merged to `trunk`, follow the regular release flow to generate release candidates and production releases.

### Hotfix release flow

1. Create a new `hotfix/*` branch off the `release` branch.
1. Commit changes to your branch using [structured commit messages](https://www.conventionalcommits.org/en/v1.0.0/).
1. Push the branch to Github, so the CI can process it – _don't create a PR just yet!\*_
1. A new "hotfix" pre-release (e.g. `1.2.0-hotfix.1`) will be tagged and published.
1. Open a pull request for review based on `release`. Changes must be tested and approved before merging.
1. Merge the hotfix branch into `release` to create a release. When merging a hotfix into `release`, SQUASH the merge.
1. `alpha` & `trunk` branches will be updated with the changes from the `release` branch.

\* `semantic-release` [will not release if the CI job was triggered by a PR](https://github.com/semantic-release/semantic-release/blob/971a5e0d16f1a32e117e9ce382a1618c8256d0d9/index.js#L48-L51)

---

## Available configs

This package exposes a couple of configuration files.

### Webpack

The `webpack.config.js` file should use this package's config-extending function:

```js
const getBaseWebpackConfig = require("newspack-scripts/config/getWebpackConfig");

const webpackConfig = getBaseWebpackConfig(
	{
		entry: {
			'output-file': './src/entrypoint-file.js',
		},
	}
);

module.exports = webpackConfig;
```

### Babel

A basic `babel.config.js`:

```js
module.exports = api => {
	api.cache( true );
	return {
		extends: 'newspack-scripts/config/babel.config.js',
	};
};
```

### ESLint

`@wordpress/scripts` uses ESLint under the hood for JS code quality linting. Note that this is separate from code formatting, which is handled by Prettier (see below).

Because of ESLint's [issue](https://github.com/eslint/eslint/issues/3458) with resolving dependencies of extended configurations, a patch has to be used to use this config in a stand-alone fashion: install `@rushstack/eslint-patch` and set up the `.eslintrc.js` like so:

```js
require( '@rushstack/eslint-patch/modern-module-resolution' );

module.exports = {
	extends: [ './node_modules/newspack-scripts/config/eslintrc.js' ],
	// Additional options…
};
```

### Prettier

`@wordpress/scripts` uses [Prettier](https://prettier.io/) under the hood for JS formatting. Note that this is separate from code quality, which is handled by ESLint (see above).

To configure Prettier rules, extend this repo's config by creating a `.prettierrc.js` file like so:

```js
const baseConfig = require( './node_modules/newspack-scripts/config/prettier.config.js' );

module.exports = {
	...baseConfig,
	// Additional options…
};
```

You should also include a [`.prettierignore` file](https://prettier.io/docs/en/ignore.html) to tell Prettier which files and directories it should ignore, using [gitignore syntax](https://git-scm.com/docs/gitignore#_pattern_format):

```
dist
node_modules
release
vendor
```

### stylelint

`@wordpress/scripts` uses [Stylelint](https://stylelint.io/) under the hood for SCSS linting and formatting.

```shell
newspack-scripts wp-scripts lint-style '**/*.scss' --customSyntax postcss-scss
```

Extend this repo's config with a `.stylelintrc.js` file like so:

```js
module.exports = {
	extends: [ './node_modules/newspack-scripts/config/stylelint.config.js' ],
	// Additional options…
};
```

### TypeScript

See note about `typescript-check` script above.

---

## CircleCI Orb

This repository hosts a [CircleCI Orb](https://circleci.com/docs/2.0/orb-intro), in `/src` directory. An Orb is a re-usable configuration – here's an example of how to use it:

```yml
version: 2.1

orbs:
  newspack: newspack/newspack@1.0.0

workflows:
  version: 2
  all:
    jobs:
      - newspack/build
```

### Updating the Orb

To update the Orb, use [CircleCI's CLI's](https://circleci.com/docs/2.0/local-cli/) [`pack`](https://circleci-public.github.io/circleci-cli/circleci_orb_pack.html) and [`publish`](https://circleci-public.github.io/circleci-cli/circleci_orb_publish.html) commands:

```bash
# Replace the `version` at the end (e.g. 1.0.1)
circleci orb pack src/ > orb.yml && circleci orb publish orb.yml newspack/newspack@version
```

Note that before the first time updating you'll need to set the API key for CircleCI CLI by running `$ circleci setup`.

### Testing locally

1. Copy the path to this repository (e.g. `pwd | pbcopy`) and "install" it as an npm dependency in the repository on which you wish to test (e.g. `npm i /path/to/newspack-scripts`). You should end up with a `"newspack-scripts": "file:*"` entry in `package.json` instead of a version number.
2. Trigger a script and observe the results, e.g. `npm run semantic-release -- --dry-run`

---

## Misc

### `@wordpress/*` packages

This project lists [`@wordpress/*` packages](https://github.com/WordPress/gutenberg/tree/trunk/packages) as dependencies in order to provide them to consumers. In a project using `@wordpress/scripts` (e.g. a consumer of `newspack-scripts`), the `@wordpress/*` packages are sourced from WP Core, not `node_modules`. The packages should be included in `node_modules`, though, to be available in other environments – notably when running tests. See [Dependency Extraction Webpack Plugin](https://www.npmjs.com/package/@wordpress/dependency-extraction-webpack-plugin) for more information.
