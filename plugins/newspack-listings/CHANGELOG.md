## [3.6.1](https://github.com/Automattic/newspack-listings/compare/v3.6.0...v3.6.1) (2026-04-13)


### Bug Fixes

* correct mismatched `htmlFor` and `id` attributes ([bee1091](https://github.com/Automattic/newspack-listings/commit/bee10917eeae012580c82ef49a81b0795d87a6ed))
* keep wrapper `<div>` because it anchors tab styles ([f34724b](https://github.com/Automattic/newspack-listings/commit/f34724b7989c48a59a061374ef09e751d20b1324))
* move InspectorControls out of blockProps ([1558a8a](https://github.com/Automattic/newspack-listings/commit/1558a8a17b8cfcfd4e337413a49027c1b0f58b9e))
* properly mark display-only text fields in editor-side block previews ([ed95005](https://github.com/Automattic/newspack-listings/commit/ed950056a571025b13053be846c29765efefe09e))
* redo `htmlFor` and `id` patch ([d450b8e](https://github.com/Automattic/newspack-listings/commit/d450b8e84c03a019f992ec70e0fbb75d94928f83))
* update blocks for compatibility with iframe-based Post Editor ([e6a5181](https://github.com/Automattic/newspack-listings/commit/e6a51810493988769aad00d4f200694276a638db))
* update blocks for compatibility with iframe-based Post Editor ([5babc7d](https://github.com/Automattic/newspack-listings/commit/5babc7d053c468b16d1cb6b58e9d9f3707ac9b11))
* update existing code to use `className` to silence React warnings ([94a446b](https://github.com/Automattic/newspack-listings/commit/94a446beab30f2fc18c67585c6019812e55f6e0d))

# [3.6.0](https://github.com/Automattic/newspack-listings/compare/v3.5.0...v3.6.0) (2026-03-30)


### Features

* add AI agent guidelines ([#576](https://github.com/Automattic/newspack-listings/issues/576)) ([00300ab](https://github.com/Automattic/newspack-listings/commit/00300ab986820a87744d89a24f2375131cee0037))

# [3.5.0](https://github.com/Automattic/newspack-listings/compare/v3.4.1...v3.5.0) (2025-11-24)


### Features

* Merge pull request [#566](https://github.com/Automattic/newspack-listings/issues/566) from Automattic/trunk ([0bd2509](https://github.com/Automattic/newspack-listings/commit/0bd2509571893f6c84494b59c5ac031dadb6372c))

## [3.4.1](https://github.com/Automattic/newspack-listings/compare/v3.4.0...v3.4.1) (2025-10-30)


### Bug Fixes

* update download URL for db.php ([#549](https://github.com/Automattic/newspack-listings/issues/549)) ([995a289](https://github.com/Automattic/newspack-listings/commit/995a2898e2d7083b6da4e431111e29b5d41997a0))

# [3.4.0](https://github.com/Automattic/newspack-listings/compare/v3.3.1...v3.4.0) (2025-08-25)


### Features

* add i18n ([#539](https://github.com/Automattic/newspack-listings/issues/539)) ([af9a3ae](https://github.com/Automattic/newspack-listings/commit/af9a3ae2c6ab571386f84a50c363485361166445))

## [3.3.1](https://github.com/Automattic/newspack-listings/compare/v3.3.0...v3.3.1) (2025-05-14)


### Bug Fixes

* force alpha build ([b206521](https://github.com/Automattic/newspack-listings/commit/b2065217883196ad1552f42ed5e2652b16487da4))

# [3.3.0](https://github.com/Automattic/newspack-listings/compare/v3.2.0...v3.3.0) (2025-03-04)


### Features

* update to new brand ([#509](https://github.com/Automattic/newspack-listings/issues/509)) ([4fb7253](https://github.com/Automattic/newspack-listings/commit/4fb725302d7a153d4204d955aa975ba7460bd0c4))

# [3.2.0](https://github.com/Automattic/newspack-listings/compare/v3.1.0...v3.2.0) (2024-11-11)


### Features

* **ia-epic:** Adjustments to Menu for Information Architecture UI ([#487](https://github.com/Automattic/newspack-listings/issues/487)) ([22b70d4](https://github.com/Automattic/newspack-listings/commit/22b70d479439b0390d835dca948b88492599d5f3))

# [3.1.0](https://github.com/Automattic/newspack-listings/compare/v3.0.0...v3.1.0) (2024-10-28)


### Features

* **wc:** handle HPOS ([#484](https://github.com/Automattic/newspack-listings/issues/484)) ([48f21bc](https://github.com/Automattic/newspack-listings/commit/48f21bc0a064a78af4ea4c188a22b50dc60cc4c3))

# [3.0.0](https://github.com/Automattic/newspack-listings/compare/v2.16.0...v3.0.0) (2024-08-13)


### Bug Fixes

* update dependencies to support `@wordpress/scripts` ([#457](https://github.com/Automattic/newspack-listings/issues/457)) ([703e281](https://github.com/Automattic/newspack-listings/commit/703e281051b730292aa05e97f690e7168a2336c5))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for newspack-scripts dependency updates

* chore: update webpack config

* refactor: use proxy script for eslint and stylelint scripts

* fix: peer dependencies

* chore: update newspack-scripts to v5.6.0-alpha.3

* chore: remove ajv dependency

* chore: add .stylelintrc.js

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* chore: update newspack-scripts to v5.6.0-alpha.7

* chore: update newspack-scripts to v5.6.0-alpha.8

* chore: add NPM scripts for PHP

* fix: phpcs

* fix: phpcs errors

* chore: update newspack-components to v3.0.0

* chore: bump newspack-scripts to v5.5.2

# [2.16.0](https://github.com/Automattic/newspack-listings/compare/v2.15.0...v2.16.0) (2024-07-01)


### Features

* ensure regenerator-runtime is available (for WP 6.6) ([#459](https://github.com/Automattic/newspack-listings/issues/459)) ([58666bb](https://github.com/Automattic/newspack-listings/commit/58666bba68f5825a89b04bea3a45aca3f08d1a3f))

# [2.15.0](https://github.com/Automattic/newspack-listings/compare/v2.14.1...v2.15.0) (2024-04-08)


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#431](https://github.com/Automattic/newspack-listings/issues/431)) ([c404365](https://github.com/Automattic/newspack-listings/commit/c404365c7038538ccf802fcb802013b795927fed))

# [2.15.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.14.1...v2.15.0-alpha.1) (2024-02-08)


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#431](https://github.com/Automattic/newspack-listings/issues/431)) ([c404365](https://github.com/Automattic/newspack-listings/commit/c404365c7038538ccf802fcb802013b795927fed))

## [2.14.1](https://github.com/Automattic/newspack-listings/compare/v2.14.0...v2.14.1) (2023-10-31)


### Bug Fixes

* newspack.pub > newspack.com ([#413](https://github.com/Automattic/newspack-listings/issues/413)) ([e0ff70e](https://github.com/Automattic/newspack-listings/commit/e0ff70e5ada6585e0669fdd6990cba1269bdf36c))

## [2.14.1-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.14.0...v2.14.1-alpha.1) (2023-10-16)


### Bug Fixes

* newspack.pub > newspack.com ([#413](https://github.com/Automattic/newspack-listings/issues/413)) ([e0ff70e](https://github.com/Automattic/newspack-listings/commit/e0ff70e5ada6585e0669fdd6990cba1269bdf36c))

# [2.14.0](https://github.com/Automattic/newspack-listings/compare/v2.13.0...v2.14.0) (2023-09-13)


### Features

* add internationalization to event dates ([#391](https://github.com/Automattic/newspack-listings/issues/391)) ([315b68e](https://github.com/Automattic/newspack-listings/commit/315b68e2aeffbfdd2a366955ab291fe15791717d))
* add option to change the event date/time format ([#399](https://github.com/Automattic/newspack-listings/issues/399)) ([d4fcc33](https://github.com/Automattic/newspack-listings/commit/d4fcc33b00d10a9ba6818cc381bd4d108290e217))

# [2.14.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.13.0...v2.14.0-alpha.1) (2023-09-06)


### Features

* add internationalization to event dates ([#391](https://github.com/Automattic/newspack-listings/issues/391)) ([315b68e](https://github.com/Automattic/newspack-listings/commit/315b68e2aeffbfdd2a366955ab291fe15791717d))
* add option to change the event date/time format ([#399](https://github.com/Automattic/newspack-listings/issues/399)) ([d4fcc33](https://github.com/Automattic/newspack-listings/commit/d4fcc33b00d10a9ba6818cc381bd4d108290e217))

# [2.13.0](https://github.com/Automattic/newspack-listings/compare/v2.12.8...v2.13.0) (2023-06-19)


### Features

* add POT file to Listings plugin ([#371](https://github.com/Automattic/newspack-listings/issues/371)) ([641e61b](https://github.com/Automattic/newspack-listings/commit/641e61b1676ade685714e69c11de985379ca81e2))

# [2.13.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.8...v2.13.0-alpha.1) (2023-06-08)


### Features

* add POT file to Listings plugin ([#371](https://github.com/Automattic/newspack-listings/issues/371)) ([641e61b](https://github.com/Automattic/newspack-listings/commit/641e61b1676ade685714e69c11de985379ca81e2))

## [2.12.8](https://github.com/Automattic/newspack-listings/compare/v2.12.7...v2.12.8) (2023-05-08)


### Bug Fixes

* add pre push hook ([#352](https://github.com/Automattic/newspack-listings/issues/352)) ([a271f9d](https://github.com/Automattic/newspack-listings/commit/a271f9da10b1048537afd144c88c697da4bb4efc))

## [2.12.8-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.7...v2.12.8-alpha.1) (2023-05-02)


### Bug Fixes

* add pre push hook ([#352](https://github.com/Automattic/newspack-listings/issues/352)) ([a271f9d](https://github.com/Automattic/newspack-listings/commit/a271f9da10b1048537afd144c88c697da4bb4efc))

## [2.12.7](https://github.com/Automattic/newspack-listings/compare/v2.12.6...v2.12.7) (2023-05-02)


### Bug Fixes

* only run self-serve-related actions if enabled ([#364](https://github.com/Automattic/newspack-listings/issues/364)) ([560a3f7](https://github.com/Automattic/newspack-listings/commit/560a3f7efe12c06672dc38b92e6c4418a37f6bd0))

## [2.12.6](https://github.com/Automattic/newspack-listings/compare/v2.12.5...v2.12.6) (2023-04-10)


### Bug Fixes

* remove unneeded editor CSS for Event Dates block ([#345](https://github.com/Automattic/newspack-listings/issues/345)) ([fb388ba](https://github.com/Automattic/newspack-listings/commit/fb388ba443bbafba793a0cc21689f3c8b1f2bac3))

## [2.12.6-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.5...v2.12.6-alpha.1) (2023-03-31)


### Bug Fixes

* remove unneeded editor CSS for Event Dates block ([#345](https://github.com/Automattic/newspack-listings/issues/345)) ([fb388ba](https://github.com/Automattic/newspack-listings/commit/fb388ba443bbafba793a0cc21689f3c8b1f2bac3))

## [2.12.5](https://github.com/Automattic/newspack-listings/compare/v2.12.4...v2.12.5) (2023-03-01)


### Bug Fixes

* resolve merge conflicts ([401bf74](https://github.com/Automattic/newspack-listings/commit/401bf74f2fca05aedb4a9e82d1bff6ab87031a64))

## [2.12.5-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.4...v2.12.5-alpha.1) (2023-03-01)


### Bug Fixes

* resolve merge conflicts ([401bf74](https://github.com/Automattic/newspack-listings/commit/401bf74f2fca05aedb4a9e82d1bff6ab87031a64))

## [2.12.4](https://github.com/Automattic/newspack-listings/compare/v2.12.3...v2.12.4) (2023-03-01)


### Bug Fixes

* manually bump asset version so CI can pick it up again ([#338](https://github.com/Automattic/newspack-listings/issues/338)) ([d665af4](https://github.com/Automattic/newspack-listings/commit/d665af45aa869b6da733d91dba05833c17b89a7c))

## [2.12.3](https://github.com/Automattic/newspack-listings/compare/v2.12.2...v2.12.3) (2023-03-01)


### Bug Fixes

* accommodate any valid date string in newspack_listings_expiration_date ([#337](https://github.com/Automattic/newspack-listings/issues/337)) ([2150707](https://github.com/Automattic/newspack-listings/commit/215070768a1854d86ff725ccf4352c330309696c))

## [2.12.2](https://github.com/Automattic/newspack-listings/compare/v2.12.1...v2.12.2) (2022-09-27)


### Bug Fixes

* prevent dupes of self-serve products; manual creation and deletion ([#281](https://github.com/Automattic/newspack-listings/issues/281)) ([2739cf3](https://github.com/Automattic/newspack-listings/commit/2739cf398cf48d458809b79248d5c3492c0bec70))
* remove content check before loading Curated List styles ([#298](https://github.com/Automattic/newspack-listings/issues/298)) ([62a9422](https://github.com/Automattic/newspack-listings/commit/62a9422ed9aca00b9fdb12e873bb29e22309044d))

## [2.12.2-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.1...v2.12.2-alpha.1) (2022-09-26)


### Bug Fixes

* prevent dupes of self-serve products; manual creation and deletion ([#281](https://github.com/Automattic/newspack-listings/issues/281)) ([2739cf3](https://github.com/Automattic/newspack-listings/commit/2739cf398cf48d458809b79248d5c3492c0bec70))
* remove content check before loading Curated List styles ([#298](https://github.com/Automattic/newspack-listings/issues/298)) ([62a9422](https://github.com/Automattic/newspack-listings/commit/62a9422ed9aca00b9fdb12e873bb29e22309044d))

## [2.12.1](https://github.com/Automattic/newspack-listings/compare/v2.12.0...v2.12.1) (2022-09-14)


### Bug Fixes

* add custom CSS classes to the Curated List block ([#297](https://github.com/Automattic/newspack-listings/issues/297)) ([6594fce](https://github.com/Automattic/newspack-listings/commit/6594fce3ab5a4788ca74c2d29a66a001832a9ff1))

## [2.12.1-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.12.0...v2.12.1-alpha.1) (2022-08-26)


### Bug Fixes

* add custom CSS classes to the Curated List block ([#297](https://github.com/Automattic/newspack-listings/issues/297)) ([6594fce](https://github.com/Automattic/newspack-listings/commit/6594fce3ab5a4788ca74c2d29a66a001832a9ff1))

# [2.12.0](https://github.com/Automattic/newspack-listings/compare/v2.11.2...v2.12.0) (2022-08-16)


### Bug Fixes

* force alpha build ([775af78](https://github.com/Automattic/newspack-listings/commit/775af7881697a139dd6bad211655a0398e7112ff))


### Features

* add featured listing support to curated lists ([#285](https://github.com/Automattic/newspack-listings/issues/285)) ([3ea027d](https://github.com/Automattic/newspack-listings/commit/3ea027d98bfb85bc65585c6e04bc03085daef7d6))

# [2.12.0-alpha.2](https://github.com/Automattic/newspack-listings/compare/v2.12.0-alpha.1...v2.12.0-alpha.2) (2022-08-12)


### Bug Fixes

* force alpha build ([775af78](https://github.com/Automattic/newspack-listings/commit/775af7881697a139dd6bad211655a0398e7112ff))

# [2.12.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.11.2...v2.12.0-alpha.1) (2022-08-05)


### Features

* add featured listing support to curated lists ([#285](https://github.com/Automattic/newspack-listings/issues/285)) ([3ea027d](https://github.com/Automattic/newspack-listings/commit/3ea027d98bfb85bc65585c6e04bc03085daef7d6))

## [2.11.2](https://github.com/Automattic/newspack-listings/compare/v2.11.1...v2.11.2) (2022-07-26)


### Bug Fixes

* allow featured listings features to be enabled independently of self-serve ([#269](https://github.com/Automattic/newspack-listings/issues/269)) ([157e816](https://github.com/Automattic/newspack-listings/commit/157e816b0edccc654fdc6536de88f22adc92e2cd))

## [2.11.2-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.11.1...v2.11.2-alpha.1) (2022-07-14)


### Bug Fixes

* allow featured listings features to be enabled independently of self-serve ([#269](https://github.com/Automattic/newspack-listings/issues/269)) ([157e816](https://github.com/Automattic/newspack-listings/commit/157e816b0edccc654fdc6536de88f22adc92e2cd))

## [2.11.1](https://github.com/Automattic/newspack-listings/compare/v2.11.0...v2.11.1) (2022-06-15)


### Bug Fixes

* allow featured listings features to be enabled independently of self-serve ([#269](https://github.com/Automattic/newspack-listings/issues/269)) ([5d424c8](https://github.com/Automattic/newspack-listings/commit/5d424c84098a9dd52ce5ad5700eca74727ff3413))
* force release build ([ba865df](https://github.com/Automattic/newspack-listings/commit/ba865dfd1edf553536aedf993006ed242bfb0806))

## [2.11.1-hotfix.1](https://github.com/Automattic/newspack-listings/compare/v2.11.0...v2.11.1-hotfix.1) (2022-06-14)


### Bug Fixes

* allow featured listings features to be enabled independently of self-serve ([868c6ff](https://github.com/Automattic/newspack-listings/commit/868c6ffa166ec4ab84baa3a03a1101731772076b))

# [2.11.0](https://github.com/Automattic/newspack-listings/compare/v2.10.0...v2.11.0) (2022-05-30)


### Bug Fixes

* restore Related Content settings section, but only if Jetpack Related Posts is on ([#263](https://github.com/Automattic/newspack-listings/issues/263)) ([877b71b](https://github.com/Automattic/newspack-listings/commit/877b71b9fc2a4067920766e22086a8078c562e10))


### Features

* enable jetpack share buttons on listings ([#257](https://github.com/Automattic/newspack-listings/issues/257)) ([0dac9fb](https://github.com/Automattic/newspack-listings/commit/0dac9fb482b577de74e886900a7cf85eed2bd571))
* remove related listings functionality ([#256](https://github.com/Automattic/newspack-listings/issues/256)) ([25f4be8](https://github.com/Automattic/newspack-listings/commit/25f4be8dd216b00ee73cc952dd2e9f93728def03))

# [2.11.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.10.0...v2.11.0-alpha.1) (2022-05-25)


### Features

* enable jetpack share buttons on listings ([#257](https://github.com/Automattic/newspack-listings/issues/257)) ([0dac9fb](https://github.com/Automattic/newspack-listings/commit/0dac9fb482b577de74e886900a7cf85eed2bd571))
* remove related listings functionality ([#256](https://github.com/Automattic/newspack-listings/issues/256)) ([25f4be8](https://github.com/Automattic/newspack-listings/commit/25f4be8dd216b00ee73cc952dd2e9f93728def03))

# [2.10.0](https://github.com/Automattic/newspack-listings/compare/v2.9.4...v2.10.0) (2022-05-03)


### Bug Fixes

* **featured:** set or unset featured status on manual post save ([#246](https://github.com/Automattic/newspack-listings/issues/246)) ([b3a1fa0](https://github.com/Automattic/newspack-listings/commit/b3a1fa03dcf034c2745cb418238c587be8bd3f6c))


### Features

* **self-serve:** enable subscription-only self-serve purchase forms ([#244](https://github.com/Automattic/newspack-listings/issues/244)) ([4ca3428](https://github.com/Automattic/newspack-listings/commit/4ca3428f79422188db05097c4b2657cb9c793ab8))

# [2.10.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.9.4...v2.10.0-alpha.1) (2022-05-02)


### Bug Fixes

* **featured:** set or unset featured status on manual post save ([#246](https://github.com/Automattic/newspack-listings/issues/246)) ([b3a1fa0](https://github.com/Automattic/newspack-listings/commit/b3a1fa03dcf034c2745cb418238c587be8bd3f6c))


### Features

* **self-serve:** enable subscription-only self-serve purchase forms ([#244](https://github.com/Automattic/newspack-listings/issues/244)) ([4ca3428](https://github.com/Automattic/newspack-listings/commit/4ca3428f79422188db05097c4b2657cb9c793ab8))

## [2.9.4](https://github.com/Automattic/newspack-listings/compare/v2.9.3...v2.9.4) (2022-04-18)


### Bug Fixes

* force build ([a8881cc](https://github.com/Automattic/newspack-listings/commit/a8881ccb8fa390ec23c7ff836b80d219dee0cc68))
* **self-serve:** patterns class name after refactor ([#245](https://github.com/Automattic/newspack-listings/issues/245)) ([a8bad4e](https://github.com/Automattic/newspack-listings/commit/a8bad4ee3bd6ca9bbf770fe1b61b43017bf70de7))
* separate checks for self-serve vs. featured listings ([#242](https://github.com/Automattic/newspack-listings/issues/242)) ([d9f5d5a](https://github.com/Automattic/newspack-listings/commit/d9f5d5a0b4397e21841cbb39936ed27fe028863f))

## [2.9.4-hotfix.1](https://github.com/Automattic/newspack-listings/compare/v2.9.3...v2.9.4-hotfix.1) (2022-04-15)

### Bug Fixes

* **self-serve:** patterns class name after refactor ([d53f2d3](https://github.com/Automattic/newspack-listings/commit/d53f2d31ec7bcac9729841a4a924941d05f78055))
* separate checks for self-serve vs. featured listings ([dc20e96](https://github.com/Automattic/newspack-listings/commit/dc20e9672fa59e89b82978a36dd16f153c280913))

## [2.9.3](https://github.com/Automattic/newspack-listings/compare/v2.9.2...v2.9.3) (2022-03-22)


### Bug Fixes

* force alpha release ([08fec0c](https://github.com/Automattic/newspack-listings/commit/08fec0c655792f72386393d4cd86754244581a01))

## [2.9.3-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.9.2...v2.9.3-alpha.1) (2022-03-21)


### Bug Fixes

* force alpha release ([08fec0c](https://github.com/Automattic/newspack-listings/commit/08fec0c655792f72386393d4cd86754244581a01))

## [2.9.2](https://github.com/Automattic/newspack-listings/compare/v2.9.1...v2.9.2) (2022-03-08)


### Bug Fixes

* allow customers to access block editor components ([#228](https://github.com/Automattic/newspack-listings/issues/228)) ([d481e64](https://github.com/Automattic/newspack-listings/commit/d481e6464e337dc5cfc320ad138b6889b1f55aac))
* **curated-list:** when sorting by event date, treat date strings as plain strings ([#227](https://github.com/Automattic/newspack-listings/issues/227)) ([f3deb92](https://github.com/Automattic/newspack-listings/commit/f3deb9261b4575c9f28d7bd5c0a9bf5c9f9b0b6f))
* implement a failsafe check before flagging an order as a listing purchase ([#229](https://github.com/Automattic/newspack-listings/issues/229)) ([152bc3e](https://github.com/Automattic/newspack-listings/commit/152bc3edca68894678fe0561e65520b0639f33f1))


### Performance Improvements

* enqueue front-end assets only when necessary ([#198](https://github.com/Automattic/newspack-listings/issues/198)) ([89c66c6](https://github.com/Automattic/newspack-listings/commit/89c66c6bf522455798b47731d74ddb6cf74af8b3))

## [2.9.2-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.9.1...v2.9.2-alpha.1) (2022-03-04)


### Bug Fixes

* allow customers to access block editor components ([#228](https://github.com/Automattic/newspack-listings/issues/228)) ([d481e64](https://github.com/Automattic/newspack-listings/commit/d481e6464e337dc5cfc320ad138b6889b1f55aac))
* **curated-list:** when sorting by event date, treat date strings as plain strings ([#227](https://github.com/Automattic/newspack-listings/issues/227)) ([f3deb92](https://github.com/Automattic/newspack-listings/commit/f3deb9261b4575c9f28d7bd5c0a9bf5c9f9b0b6f))
* implement a failsafe check before flagging an order as a listing purchase ([#229](https://github.com/Automattic/newspack-listings/issues/229)) ([152bc3e](https://github.com/Automattic/newspack-listings/commit/152bc3edca68894678fe0561e65520b0639f33f1))


### Performance Improvements

* enqueue front-end assets only when necessary ([#198](https://github.com/Automattic/newspack-listings/issues/198)) ([89c66c6](https://github.com/Automattic/newspack-listings/commit/89c66c6bf522455798b47731d74ddb6cf74af8b3))

## [2.9.1](https://github.com/Automattic/newspack-listings/compare/v2.9.0...v2.9.1) (2022-02-08)


### Bug Fixes

* prevent showing 0 for listings with no categories ([#199](https://github.com/Automattic/newspack-listings/issues/199)) ([ae52e60](https://github.com/Automattic/newspack-listings/commit/ae52e605aa58c0afc2b66c64e1408d8a6dcbea9a))
* require postcss explicitly ([#212](https://github.com/Automattic/newspack-listings/issues/212)) ([0b46eb3](https://github.com/Automattic/newspack-listings/commit/0b46eb3c98dd2bd141ca6de15569ff040cdb9760))

## [2.9.1-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.9.0...v2.9.1-alpha.1) (2022-01-27)


### Bug Fixes

* prevent showing 0 for listings with no categories ([#199](https://github.com/Automattic/newspack-listings/issues/199)) ([ae52e60](https://github.com/Automattic/newspack-listings/commit/ae52e605aa58c0afc2b66c64e1408d8a6dcbea9a))
* require postcss explicitly ([#212](https://github.com/Automattic/newspack-listings/issues/212)) ([0b46eb3](https://github.com/Automattic/newspack-listings/commit/0b46eb3c98dd2bd141ca6de15569ff040cdb9760))

# [2.9.0](https://github.com/Automattic/newspack-listings/compare/v2.8.0...v2.9.0) (2022-01-19)


### Bug Fixes

* a fatal error when self-serve listings env variable is not defined ([#196](https://github.com/Automattic/newspack-listings/issues/196)) ([59f6ab6](https://github.com/Automattic/newspack-listings/commit/59f6ab6216b80ab32f9aa30ccc8d77eb54b26865))
* minor bugfixes for featured and self-serve listings ([#181](https://github.com/Automattic/newspack-listings/issues/181)) ([3609e7a](https://github.com/Automattic/newspack-listings/commit/3609e7a031793179e46e0374385bea499b234779))


### Features

* **ugc:** handle self-serve purchase form submission ([#135](https://github.com/Automattic/newspack-listings/issues/135)) ([7a62bd8](https://github.com/Automattic/newspack-listings/commit/7a62bd8bca95ac47ff1ef8fbb5899854df71f07c))

# [2.9.0-alpha.3](https://github.com/Automattic/newspack-listings/compare/v2.9.0-alpha.2...v2.9.0-alpha.3) (2022-01-11)


### Bug Fixes

* a fatal error when self-serve listings env variable is not defined ([#196](https://github.com/Automattic/newspack-listings/issues/196)) ([59f6ab6](https://github.com/Automattic/newspack-listings/commit/59f6ab6216b80ab32f9aa30ccc8d77eb54b26865))

# [2.9.0-alpha.2](https://github.com/Automattic/newspack-listings/compare/v2.9.0-alpha.1...v2.9.0-alpha.2) (2022-01-07)


### Bug Fixes

* minor bugfixes for featured and self-serve listings ([#181](https://github.com/Automattic/newspack-listings/issues/181)) ([3609e7a](https://github.com/Automattic/newspack-listings/commit/3609e7a031793179e46e0374385bea499b234779))

# [2.9.0-alpha.1](https://github.com/Automattic/newspack-listings/compare/v2.8.0...v2.9.0-alpha.1) (2022-01-06)


### Features

* **ugc:** handle self-serve purchase form submission ([#135](https://github.com/Automattic/newspack-listings/issues/135)) ([7a62bd8](https://github.com/Automattic/newspack-listings/commit/7a62bd8bca95ac47ff1ef8fbb5899854df71f07c))

# [2.8.0](https://github.com/Automattic/newspack-listings/compare/v2.7.0...v2.8.0) (2021-11-09)


### Features

* **ugc:** self-serve listings purchase block ([#128](https://github.com/Automattic/newspack-listings/issues/128)) ([0ff8290](https://github.com/Automattic/newspack-listings/commit/0ff8290a8c8cc3524a663955e0ad0f22fb1d23e0))

# [2.7.0](https://github.com/Automattic/newspack-listings/compare/v2.6.0...v2.7.0) (2021-11-03)


### Bug Fixes

* ci build command typo ([#141](https://github.com/Automattic/newspack-listings/issues/141)) ([371ce2d](https://github.com/Automattic/newspack-listings/commit/371ce2d13ec7f9d7a25360fb92a062ad86e4ae21))
* prevent shadow taxonomies from being editable via quick edit ([#138](https://github.com/Automattic/newspack-listings/issues/138)) ([4e588ac](https://github.com/Automattic/newspack-listings/commit/4e588acd4aa63a10075d3d30d2fef66358e48671))
* update ci images ([#139](https://github.com/Automattic/newspack-listings/issues/139)) ([c07ac9b](https://github.com/Automattic/newspack-listings/commit/c07ac9b7fd21ecd5c6c9af2a6c8a4ff45e4211c0))


### Features

* **ugc:** featured listings ([#124](https://github.com/Automattic/newspack-listings/issues/124)) ([e1ac8d2](https://github.com/Automattic/newspack-listings/commit/e1ac8d2337602ec3646f4e7c3294d8e8e22fe0a0))

# [2.6.0](https://github.com/Automattic/newspack-listings/compare/v2.5.0...v2.6.0) (2021-10-19)


### Features

* add new setting to disable Jetpack Related Posts module ([#118](https://github.com/Automattic/newspack-listings/issues/118)) ([ac39c1e](https://github.com/Automattic/newspack-listings/commit/ac39c1e4e04db1f667d3a9540e78fc80ba486d24))

# [2.5.0](https://github.com/Automattic/newspack-listings/compare/v2.4.0...v2.5.0) (2021-09-21)


### Bug Fixes

* editor crash when switching months in event dates block ([#123](https://github.com/Automattic/newspack-listings/issues/123)) ([cee0d53](https://github.com/Automattic/newspack-listings/commit/cee0d5368cd52a2b91792c3c746394945f128adc))
* fatal error when WooCommerce Subscriptions is not active ([#122](https://github.com/Automattic/newspack-listings/issues/122)) ([16ac166](https://github.com/Automattic/newspack-listings/commit/16ac166dd794ec6ab237fdd7bf0df70aee12b673))


### Features

* feature extensions from gdg ([#120](https://github.com/Automattic/newspack-listings/issues/120)) ([b2c3cc8](https://github.com/Automattic/newspack-listings/commit/b2c3cc8855acf80bdaa930714b3ffb44935c4426))
* **ugc:** automated WC products for self-serve listings ([#117](https://github.com/Automattic/newspack-listings/issues/117)) ([6a44d86](https://github.com/Automattic/newspack-listings/commit/6a44d867f57fc94b957cd40396d6ec22c3a975fb))

# [2.4.0](https://github.com/Automattic/newspack-listings/compare/v2.3.0...v2.4.0) (2021-09-08)


### Features

* allow empty prefix slug; tweaks for GDG import ([#112](https://github.com/Automattic/newspack-listings/issues/112)) ([a5a6be6](https://github.com/Automattic/newspack-listings/commit/a5a6be6ef20b349e4ddae88385af2901ff820a44))

# [2.3.0](https://github.com/Automattic/newspack-listings/compare/v2.2.0...v2.3.0) (2021-08-10)


### Features

* add CSS classes for hidden date, author ([#108](https://github.com/Automattic/newspack-listings/issues/108)) ([3091c12](https://github.com/Automattic/newspack-listings/commit/3091c1292fe76c3a57f034b9465a397ce53fcac6))

# [2.2.0](https://github.com/Automattic/newspack-listings/compare/v2.1.0...v2.2.0) (2021-08-03)


### Bug Fixes

* satisfy DatePicker prop dependency ([#105](https://github.com/Automattic/newspack-listings/issues/105)) ([fec19d8](https://github.com/Automattic/newspack-listings/commit/fec19d87403c79dceef93df9d8927d97d5963155))


### Features

* show classes for each category and tag on every listing ([#103](https://github.com/Automattic/newspack-listings/issues/103)) ([309c046](https://github.com/Automattic/newspack-listings/commit/309c046521397b2a06af7f0a108326579417167b))

# [2.1.0](https://github.com/Automattic/newspack-listings/compare/v2.0.1...v2.1.0) (2021-07-19)


### Bug Fixes

* avoid meta sync update error ([#95](https://github.com/Automattic/newspack-listings/issues/95)) ([cab16aa](https://github.com/Automattic/newspack-listings/commit/cab16aa7c0a09519003372d838df7165223a5926))
* do not register post-specific sidebars in widgets page ([#93](https://github.com/Automattic/newspack-listings/issues/93)) ([7716775](https://github.com/Automattic/newspack-listings/commit/771677534562734c368794f9fb42b13794371d1c))


### Features

* bump max number of items per list from 20 to 50 ([#97](https://github.com/Automattic/newspack-listings/issues/97)) ([009deab](https://github.com/Automattic/newspack-listings/commit/009deab753ea8dcb86e2745483e9792c5c44ae27))
* more block patterns (real estate, classified ads) ([#84](https://github.com/Automattic/newspack-listings/issues/84)) ([a51f5af](https://github.com/Automattic/newspack-listings/commit/a51f5afb6d6d929290df5013f1398546f455ad10))

## [2.0.1](https://github.com/Automattic/newspack-listings/compare/v2.0.0...v2.0.1) (2021-07-06)


### Bug Fixes

* editor errors with reusable blocks ([#89](https://github.com/Automattic/newspack-listings/issues/89)) ([fdc46d3](https://github.com/Automattic/newspack-listings/commit/fdc46d3a628313d94f0bc52a18ccaed9af296eb9))

# [2.0.0](https://github.com/Automattic/newspack-listings/compare/v1.2.2...v2.0.0) (2021-07-06)


* v2 release (#85) ([748810d](https://github.com/Automattic/newspack-listings/commit/748810d7c1d817e2a1c218b41b3ad10d74939260)), closes [#85](https://github.com/Automattic/newspack-listings/issues/85) [#40](https://github.com/Automattic/newspack-listings/issues/40) [#39](https://github.com/Automattic/newspack-listings/issues/39) [#32](https://github.com/Automattic/newspack-listings/issues/32) [#41](https://github.com/Automattic/newspack-listings/issues/41) [#49](https://github.com/Automattic/newspack-listings/issues/49) [#43](https://github.com/Automattic/newspack-listings/issues/43) [#56](https://github.com/Automattic/newspack-listings/issues/56) [#51](https://github.com/Automattic/newspack-listings/issues/51) [#57](https://github.com/Automattic/newspack-listings/issues/57) [#61](https://github.com/Automattic/newspack-listings/issues/61) [#67](https://github.com/Automattic/newspack-listings/issues/67) [#60](https://github.com/Automattic/newspack-listings/issues/60) [#70](https://github.com/Automattic/newspack-listings/issues/70) [#65](https://github.com/Automattic/newspack-listings/issues/65) [#71](https://github.com/Automattic/newspack-listings/issues/71) [#66](https://github.com/Automattic/newspack-listings/issues/66) [#58](https://github.com/Automattic/newspack-listings/issues/58) [#77](https://github.com/Automattic/newspack-listings/issues/77) [#81](https://github.com/Automattic/newspack-listings/issues/81)


### Bug Fixes

* errors and bugs related to WP 5.8 ([#83](https://github.com/Automattic/newspack-listings/issues/83)) ([90da6c5](https://github.com/Automattic/newspack-listings/commit/90da6c5449e7649bec90206537994c93d0e576a9))


### BREAKING CHANGES

* This feature will deprecate existing custom taxonomies, so any existing terms for those taxonomies will be lost.

To fix, we can convert terms from the deprecated taxonomies to standard post categories/tags via a migration script.

## [1.2.2](https://github.com/Automattic/newspack-listings/compare/v1.2.1...v1.2.2) (2021-06-22)


### Bug Fixes

* missing condition for block appender in list container ([#74](https://github.com/Automattic/newspack-listings/issues/74)) ([2c49896](https://github.com/Automattic/newspack-listings/commit/2c498961b80324d8cbea61c7fbc356ca7ccb8de5))
* use synced attributes for ListContainer directly ([#73](https://github.com/Automattic/newspack-listings/issues/73)) ([f8641a7](https://github.com/Automattic/newspack-listings/commit/f8641a726ce7c2949c01b232829f8923f06b61ad))

## [1.2.1](https://github.com/Automattic/newspack-listings/compare/v1.2.0...v1.2.1) (2021-06-08)


### Bug Fixes

* syncing attributes from curated list block to inner blocks ([#64](https://github.com/Automattic/newspack-listings/issues/64)) ([cdbc0bb](https://github.com/Automattic/newspack-listings/commit/cdbc0bb63bf4d8b18dc30fab87ce1a3ab68c7ddd))

# [1.2.0](https://github.com/Automattic/newspack-listings/compare/v1.1.0...v1.2.0) (2021-02-11)


### Features

* add block patterns ([#23](https://github.com/Automattic/newspack-listings/issues/23)) ([a273a40](https://github.com/Automattic/newspack-listings/commit/a273a40c0056cf09879d491083c2ca2321413896))

# [1.1.0](https://github.com/Automattic/newspack-listings/compare/v1.0.0...v1.1.0) (2020-12-18)


### Bug Fixes

* minor bug fixes ([#21](https://github.com/Automattic/newspack-listings/issues/21)) ([5f90bc7](https://github.com/Automattic/newspack-listings/commit/5f90bc7d027a2693a9dc4d804484ce0a78c4e4ff))


### Features

* remove borders and padding in editor to match front-end styles ([#14](https://github.com/Automattic/newspack-listings/issues/14)) ([6c47a17](https://github.com/Automattic/newspack-listings/commit/6c47a1760ea9429facb089f2be7bd71a91924cf0))

# 1.0.0 (2020-12-16)


### Features

* initial post type and block setup ([#1](https://github.com/Automattic/newspack-listings/issues/1)) ([47dc0c1](https://github.com/Automattic/newspack-listings/commit/47dc0c11cb8041117d5229e49ac14f49cee1b1ff))
* listing taxonomies and query mode ([#6](https://github.com/Automattic/newspack-listings/issues/6)) ([528e1e5](https://github.com/Automattic/newspack-listings/commit/528e1e5a25000c7746b62b88566803424879da14))
* new Curated List block, block pattern, and map functionality ([#3](https://github.com/Automattic/newspack-listings/issues/3)) ([9be6e7e](https://github.com/Automattic/newspack-listings/commit/9be6e7ebae9028407d67071e13857ab7827deff9))
