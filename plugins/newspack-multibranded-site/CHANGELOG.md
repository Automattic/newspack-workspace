# [2.2.0](https://github.com/Automattic/newspack-multibranded-site/compare/v2.1.0...v2.2.0) (2025-11-24)


### Features

* Merge pull request [#87](https://github.com/Automattic/newspack-multibranded-site/issues/87) from Automattic/trunk ([0155b5b](https://github.com/Automattic/newspack-multibranded-site/commit/0155b5be2d2cae92232d087b8e33e76aef030091))

# [2.1.0](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.6...v2.1.0) (2025-08-25)


### Bug Fixes

* phpunit tests compat with php 8.3 ([#80](https://github.com/Automattic/newspack-multibranded-site/issues/80)) ([ac2076d](https://github.com/Automattic/newspack-multibranded-site/commit/ac2076d9cad38353bb67ba72a19d43d67d826016))


### Features

* add i18n ([#81](https://github.com/Automattic/newspack-multibranded-site/issues/81)) ([2e9bf9c](https://github.com/Automattic/newspack-multibranded-site/commit/2e9bf9ca5cb87c113f3a27f40198a9c47da9e951))

## [2.0.6](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.5...v2.0.6) (2025-06-02)


### Bug Fixes

* add customization class to fix site title url ([#73](https://github.com/Automattic/newspack-multibranded-site/issues/73)) ([aff3784](https://github.com/Automattic/newspack-multibranded-site/commit/aff37847cffbfaf07e1f7cc25584d1f4a1dcd76d))
* **admin:** campaigns admin page detection ([#74](https://github.com/Automattic/newspack-multibranded-site/issues/74)) ([b5382f8](https://github.com/Automattic/newspack-multibranded-site/commit/b5382f85530ca852ff946d9686182caed5a06745))

## [2.0.5](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.4...v2.0.5) (2025-05-14)


### Bug Fixes

* force alpha build ([1e2b0de](https://github.com/Automattic/newspack-multibranded-site/commit/1e2b0de184ed8326906b893076f6256865f3d70c))

## [2.0.4](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.3...v2.0.4) (2025-03-31)


### Bug Fixes

* **posts-listing:** don't show empty brand dropdown ([#69](https://github.com/Automattic/newspack-multibranded-site/issues/69)) ([98bf17a](https://github.com/Automattic/newspack-multibranded-site/commit/98bf17af7ca508d82d175cbd447e27a1fdfa15cb))

## [2.0.3](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.2...v2.0.3) (2024-12-16)


### Bug Fixes

* load text domain on init hook ([#66](https://github.com/Automattic/newspack-multibranded-site/issues/66)) ([f7d8261](https://github.com/Automattic/newspack-multibranded-site/commit/f7d8261b6d6e1172d4442a8413cfcb7d7ab2d70f))

## [2.0.2](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.1...v2.0.2) (2024-11-11)


### Bug Fixes

* missing globals ([#60](https://github.com/Automattic/newspack-multibranded-site/issues/60)) ([10c3f17](https://github.com/Automattic/newspack-multibranded-site/commit/10c3f17ae2823be1efd91177e26a9ade50122cd3))
* **wp-6.7:** update radio control styles [#58](https://github.com/Automattic/newspack-multibranded-site/issues/58)  ([2c586cd](https://github.com/Automattic/newspack-multibranded-site/commit/2c586cd95b4dc94602a325caedfd7315395db444))

## [2.0.1](https://github.com/Automattic/newspack-multibranded-site/compare/v2.0.0...v2.0.1) (2024-10-09)


### Bug Fixes

* Preserve RSS feed for brands with page on front ([46f8524](https://github.com/Automattic/newspack-multibranded-site/commit/46f85248ecca7a10ec98a385ea4376082f59f7fc))
* Preserve RSS feed for brands with page on front Merge pull request [#55](https://github.com/Automattic/newspack-multibranded-site/issues/55) from Automattic/hotfix/rss-feed ([eb117c4](https://github.com/Automattic/newspack-multibranded-site/commit/eb117c451d8f5a6ee38394eb0fb8d5fb12502a8b))

# [2.0.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.4.0...v2.0.0) (2024-08-13)


### Bug Fixes

* do not break wp_update_term ([#51](https://github.com/Automattic/newspack-multibranded-site/issues/51)) ([70c0ea4](https://github.com/Automattic/newspack-multibranded-site/commit/70c0ea414aa87e2386865011767cb8252b430ef7))
* update dependencies to support `@wordpress/scripts` ([#45](https://github.com/Automattic/newspack-multibranded-site/issues/45)) ([de1a172](https://github.com/Automattic/newspack-multibranded-site/commit/de1a1725911c7a3d2b711099f24f12f11b58ec5e))


### Features

* **ga:** add brand as custom parameter to GA4 ([bff4df3](https://github.com/Automattic/newspack-multibranded-site/commit/bff4df371e5c29d5cd412f7c874547e31f1b0f5c))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for newspack-scripts dependency updates

* chore: update newspack-scripts to v5.6.0-alpha.3

* chore: add .stylelintrc.js

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* chore: update newspack-scripts to v5.6.0-alpha.7

* fix: format SCSS

* chore: update newspack-scripts to v5.6.0-alpha.8

* fix: phpcs errors

* chore: update newspack-components to v3.0.0

* fix: phpcs error

* chore: bump newspack-scripts to v5.5.2

# [1.4.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.3.0...v1.4.0) (2024-07-01)


### Bug Fixes

* update newspack-scripts to v5.5.1 ([#47](https://github.com/Automattic/newspack-multibranded-site/issues/47)) ([89f82e8](https://github.com/Automattic/newspack-multibranded-site/commit/89f82e84e5094d3b0c9037e4a5dc9e6de86ff190))


### Features

* ensure regenerator-runtime is available (for WP 6.6) ([4dff4ef](https://github.com/Automattic/newspack-multibranded-site/commit/4dff4ef170cdf315218c4302b1b1e805e6a12f9f))

# [1.3.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.2.0...v1.3.0) (2024-04-08)


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#41](https://github.com/Automattic/newspack-multibranded-site/issues/41)) ([290e390](https://github.com/Automattic/newspack-multibranded-site/commit/290e390af56a8af20a90d952c2d6596714a5045c))

# [1.3.0-alpha.1](https://github.com/Automattic/newspack-multibranded-site/compare/v1.2.0...v1.3.0-alpha.1) (2024-02-08)


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#41](https://github.com/Automattic/newspack-multibranded-site/issues/41)) ([290e390](https://github.com/Automattic/newspack-multibranded-site/commit/290e390af56a8af20a90d952c2d6596714a5045c))

# [1.2.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.1.0...v1.2.0) (2023-12-11)


### Bug Fixes

* release process ([5a93b1f](https://github.com/Automattic/newspack-multibranded-site/commit/5a93b1f5ba4449f9ed85a1635f62cd77ce8ee6e5))


### Features

* use brand in pages set as brand fronts ([#29](https://github.com/Automattic/newspack-multibranded-site/issues/29)) ([7ab39d6](https://github.com/Automattic/newspack-multibranded-site/commit/7ab39d69801ac8639675106f307a75e88ff9511a))

# [1.2.0-alpha.1](https://github.com/Automattic/newspack-multibranded-site/compare/v1.1.0...v1.2.0-alpha.1) (2023-11-30)


### Bug Fixes

* release process ([5a93b1f](https://github.com/Automattic/newspack-multibranded-site/commit/5a93b1f5ba4449f9ed85a1635f62cd77ce8ee6e5))


### Features

* use brand in pages set as brand fronts ([#29](https://github.com/Automattic/newspack-multibranded-site/issues/29)) ([7ab39d6](https://github.com/Automattic/newspack-multibranded-site/commit/7ab39d69801ac8639675106f307a75e88ff9511a))

# [1.1.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.0.0...v1.1.0) (2023-11-30)


### Bug Fixes

* release process ([b42ff85](https://github.com/Automattic/newspack-multibranded-site/commit/b42ff85805ec68782124e56e914d58d2e93f945f))
* update help site URL ([#32](https://github.com/Automattic/newspack-multibranded-site/issues/32)) ([6369b10](https://github.com/Automattic/newspack-multibranded-site/commit/6369b101af36044fb6cf9b4f223fe04fbc8cf767))


### Features

* add auto updater ([#31](https://github.com/Automattic/newspack-multibranded-site/issues/31)) ([86bf6a3](https://github.com/Automattic/newspack-multibranded-site/commit/86bf6a3c8d1f659c4f92bc8d5dd307a061bd6074))
* change menu position ([0e46fbf](https://github.com/Automattic/newspack-multibranded-site/commit/0e46fbfc8f40251dc927744862ef07e7d1532516))
* filter posts by brand in admin ([#28](https://github.com/Automattic/newspack-multibranded-site/issues/28)) ([9094911](https://github.com/Automattic/newspack-multibranded-site/commit/9094911de161628deb823e668eb814c4610f6074))
* trigger new release ([33a173e](https://github.com/Automattic/newspack-multibranded-site/commit/33a173e95674c537ad34a6b4d7b83317a3fa3302))

# [1.2.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.1.0...v1.2.0) (2023-11-29)


### Features

* trigger new release ([33a173e](https://github.com/Automattic/newspack-multibranded-site/commit/33a173e95674c537ad34a6b4d7b83317a3fa3302))

# [1.1.0](https://github.com/Automattic/newspack-multibranded-site/compare/v1.0.1...v1.1.0) (2023-11-29)


### Features

* add auto updater ([#31](https://github.com/Automattic/newspack-multibranded-site/issues/31)) ([86bf6a3](https://github.com/Automattic/newspack-multibranded-site/commit/86bf6a3c8d1f659c4f92bc8d5dd307a061bd6074))
* change menu position ([0e46fbf](https://github.com/Automattic/newspack-multibranded-site/commit/0e46fbfc8f40251dc927744862ef07e7d1532516))
* filter posts by brand in admin ([#28](https://github.com/Automattic/newspack-multibranded-site/issues/28)) ([9094911](https://github.com/Automattic/newspack-multibranded-site/commit/9094911de161628deb823e668eb814c4610f6074))

## [1.0.1](https://github.com/Automattic/newspack-multibranded-site/compare/v1.0.0...v1.0.1) (2023-10-11)


### Bug Fixes

* update help site URL ([#32](https://github.com/Automattic/newspack-multibranded-site/issues/32)) ([6369b10](https://github.com/Automattic/newspack-multibranded-site/commit/6369b101af36044fb6cf9b4f223fe04fbc8cf767))

# 1.0.0 (2023-08-17)


### Bug Fixes

* add missing dependency ([b31a7ba](https://github.com/Automattic/newspack-multibranded-site/commit/b31a7ba476b88d529f2cc2e643a3cbd09958f71a))
* **autoload:** fix autoloader path ([3ca2286](https://github.com/Automattic/newspack-multibranded-site/commit/3ca2286bd9513b53915766bc6f2cc2d6483c1372))
* better use of selector ([80fa247](https://github.com/Automattic/newspack-multibranded-site/commit/80fa24716c9c47e07800fe471e4731ae4b38ffbd))
* **Brand:** add confirmation message and delete brand ([#11](https://github.com/Automattic/newspack-multibranded-site/issues/11)) ([9ebe51e](https://github.com/Automattic/newspack-multibranded-site/commit/9ebe51e44a362f8981311ff3779bb373297aa08d))
* hook registration ([49341b7](https://github.com/Automattic/newspack-multibranded-site/commit/49341b7ef79f0b59e2e2160d7429e8e4206f56ed))
* ignore menus when value is zero ([0b741b6](https://github.com/Automattic/newspack-multibranded-site/commit/0b741b6d40da7762e7e30cff3b20d6fff802865e))
* js lint directory ([435ee70](https://github.com/Automattic/newspack-multibranded-site/commit/435ee70ab534a0f2c3a3de49bb8cd6135972eb0c))
* lint ([6491074](https://github.com/Automattic/newspack-multibranded-site/commit/649107423817ac933b910ce9b0694720e55a8f3e))
* load right template when page is on front ([c3f7088](https://github.com/Automattic/newspack-multibranded-site/commit/c3f7088ce81bdba3ddea26c39aaf93249cd5954d))
* only add post meta to associated post types ([#20](https://github.com/Automattic/newspack-multibranded-site/issues/20)) ([72131be](https://github.com/Automattic/newspack-multibranded-site/commit/72131be76c7d3d3f4c12e39a69d37f530365275e))
* php linting ([514b759](https://github.com/Automattic/newspack-multibranded-site/commit/514b7591093f1c5dcdfc0fed292ce77bc5927e5a))
* rename option ([68dd2e6](https://github.com/Automattic/newspack-multibranded-site/commit/68dd2e6cd77e2e9519ddb78398d69d84964a99dc))
* template tags calls ([580b5ea](https://github.com/Automattic/newspack-multibranded-site/commit/580b5ea7048730fd0276a07f3df994704c860f4c))
* typo in function name ([4e31e26](https://github.com/Automattic/newspack-multibranded-site/commit/4e31e26de92d20891305ea3d6a2c1c8115d01019))
* unsued hook on admin ([8a449ee](https://github.com/Automattic/newspack-multibranded-site/commit/8a449eec7364d3b9c932094d29e287e9190a1880))
* use filter and filter out yoast ([62a2772](https://github.com/Automattic/newspack-multibranded-site/commit/62a2772b9ee1f10905a5c9df40d036f0b5778677))


### Features

* adapt filter based on final changes to popups plugin ([ae5b2d4](https://github.com/Automattic/newspack-multibranded-site/commit/ae5b2d4442da32b7df36662c889da4cb46c2c1c5))
* Add brands for campaign prompts ([c39f91c](https://github.com/Automattic/newspack-multibranded-site/commit/c39f91cd16f57cfcaf331d33965e49298b461022))
* add category and tags primary brand ([760193b](https://github.com/Automattic/newspack-multibranded-site/commit/760193b035fb67fe7f402df80ccc5f859cdf65c1))
* Add custom logo option ([dfc7b43](https://github.com/Automattic/newspack-multibranded-site/commit/dfc7b4350c55da2a4d4242c4c0414f8c218604b4))
* Add menu option and customization ([c851c5e](https://github.com/Automattic/newspack-multibranded-site/commit/c851c5e223cf27f5e46051335c4f371e3f2dd4b8))
* Add menu option and customization ([7dadd30](https://github.com/Automattic/newspack-multibranded-site/commit/7dadd3037277ea8d522683e7afcbd2b5638053c3))
* add menu options to the UI ([9febdb4](https://github.com/Automattic/newspack-multibranded-site/commit/9febdb440208a59bcc1957f641d5311f6356e374))
* add post primary brand UI ([9d21083](https://github.com/Automattic/newspack-multibranded-site/commit/9d210835ee1ffc978a32f40ad495f22aa0f29f71))
* add post primary brand UI [#12](https://github.com/Automattic/newspack-multibranded-site/issues/12) ([e7e602b](https://github.com/Automattic/newspack-multibranded-site/commit/e7e602b3f76851b1baa767acc9576f1413ea7db4))
* Add theme colors ([c4572d1](https://github.com/Automattic/newspack-multibranded-site/commit/c4572d10891ea9bb957606dbb998ea4a0683f071))
* **brand:** add clear button to theme color picker ([#21](https://github.com/Automattic/newspack-multibranded-site/issues/21)) ([deff3b9](https://github.com/Automattic/newspack-multibranded-site/commit/deff3b9685331f1a3d5c558efa1d955cd6bc2c41))
* **Brand:** add/edit a brand ([#7](https://github.com/Automattic/newspack-multibranded-site/issues/7)) ([51d50c3](https://github.com/Automattic/newspack-multibranded-site/commit/51d50c331687c55ba7737df711bf4dc539df8c1b))
* **brands:** add brands list UI ([dc0979c](https://github.com/Automattic/newspack-multibranded-site/commit/dc0979cab2f8cbeff95ee4fb35ba8a1016efc61c))
* check for post primary brand ([c8db9c3](https://github.com/Automattic/newspack-multibranded-site/commit/c8db9c38067cdf0759e96404be78f11dd1d372c3))
* convert it to a stand-alone sidebar panel ([4e64456](https://github.com/Automattic/newspack-multibranded-site/commit/4e6445630697057eff03e63ea4d1443a4d923d16))
* **customization:** add brand identifier in body class ([bce6906](https://github.com/Automattic/newspack-multibranded-site/commit/bce6906f846a6e245484f1a984c45cbc96f30026))
* filter the blog name ([0a64b98](https://github.com/Automattic/newspack-multibranded-site/commit/0a64b9869bccfb6a286db1fc6d4e34c70361fc5c))
* filters custom logo link ([#18](https://github.com/Automattic/newspack-multibranded-site/issues/18)) ([eedf593](https://github.com/Automattic/newspack-multibranded-site/commit/eedf593228416fe604b2886c41ac93fa62b49938))
* manage prompt conflicts ([#19](https://github.com/Automattic/newspack-multibranded-site/issues/19)) ([5bc0631](https://github.com/Automattic/newspack-multibranded-site/commit/5bc06310663381ddb0f4ed2f3a236308a88de2b1))
* Merge pull request [#24](https://github.com/Automattic/newspack-multibranded-site/issues/24) from Automattic/test/ci-release ([5545986](https://github.com/Automattic/newspack-multibranded-site/commit/5545986519cdd9864145e2015a48384d0c6d70dd))
* refactor post meta into its own class and register meta ([0213cfb](https://github.com/Automattic/newspack-multibranded-site/commit/0213cfb6168197a36dc12a771b7800121903bbad))
* refactor primary brand into an option ([b71876f](https://github.com/Automattic/newspack-multibranded-site/commit/b71876f8831cf2f0280dad93bbdc8d37157295e2))
* remove current brand support ([0c6d2c6](https://github.com/Automattic/newspack-multibranded-site/commit/0c6d2c68b9fa6740bc5830192a12f77f2c33a3af))
* update newspack-components ([5e8694c](https://github.com/Automattic/newspack-multibranded-site/commit/5e8694cce55f16820a19f2121edf53781e75a50e))
