## [1.28.1](https://github.com/Automattic/newspack-block-theme/compare/v1.28.0...v1.28.1) (2026-05-18)


### Bug Fixes

* **block-theme:** remove MJML image-caption filter moved to newsletters plugin ([#442](https://github.com/Automattic/newspack-block-theme/issues/442)) ([93c9b4b](https://github.com/Automattic/newspack-block-theme/commit/93c9b4b352ce568539ca789a3275824fb1d31f7c))
* fine-tune code removing patterns to skip Woo Coming Soon patterns ([#439](https://github.com/Automattic/newspack-block-theme/issues/439)) ([1cf68d0](https://github.com/Automattic/newspack-block-theme/commit/1cf68d029cd4ea732fefbd3243fc18adefc789fe))

## [1.28.1-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.28.0...v1.28.1-alpha.1) (2026-05-07)


### Bug Fixes

* **block-theme:** remove MJML image-caption filter moved to newsletters plugin ([#442](https://github.com/Automattic/newspack-block-theme/issues/442)) ([93c9b4b](https://github.com/Automattic/newspack-block-theme/commit/93c9b4b352ce568539ca789a3275824fb1d31f7c))
* fine-tune code removing patterns to skip Woo Coming Soon patterns ([#439](https://github.com/Automattic/newspack-block-theme/issues/439)) ([1cf68d0](https://github.com/Automattic/newspack-block-theme/commit/1cf68d029cd4ea732fefbd3243fc18adefc789fe))

# [1.28.0](https://github.com/Automattic/newspack-block-theme/compare/v1.27.0...v1.28.0) (2026-05-04)


### Bug Fixes

* improve pagination, linking directly to comments ([#431](https://github.com/Automattic/newspack-block-theme/issues/431)) ([461def0](https://github.com/Automattic/newspack-block-theme/commit/461def0a3491733c023702dc2ce82595b89babf2))
* **post-date:** use block bindings instead of deprecated displayType ([#430](https://github.com/Automattic/newspack-block-theme/issues/430)) ([b65b8c3](https://github.com/Automattic/newspack-block-theme/commit/b65b8c34b93a2239f71330bda0f19bfd67ba5983))


### Features

* add newsletters templates ([#437](https://github.com/Automattic/newspack-block-theme/issues/437)) ([cc8d244](https://github.com/Automattic/newspack-block-theme/commit/cc8d244b3f4d1f8138058ddd406ee6855bb3b730))
* copy newsletters compatibility ([#436](https://github.com/Automattic/newspack-block-theme/issues/436)) ([214f73a](https://github.com/Automattic/newspack-block-theme/commit/214f73aa876184d7c036cfd3b919a34777df6347))
* swap in Overlay Menu block to replace template parts ([30be676](https://github.com/Automattic/newspack-block-theme/commit/30be67680cc32bad0c579a4cd04ede93bbbe80d1))

# [1.28.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.27.0...v1.28.0-alpha.1) (2026-04-16)


### Bug Fixes

* improve pagination, linking directly to comments ([#431](https://github.com/Automattic/newspack-block-theme/issues/431)) ([461def0](https://github.com/Automattic/newspack-block-theme/commit/461def0a3491733c023702dc2ce82595b89babf2))
* **post-date:** use block bindings instead of deprecated displayType ([#430](https://github.com/Automattic/newspack-block-theme/issues/430)) ([b65b8c3](https://github.com/Automattic/newspack-block-theme/commit/b65b8c34b93a2239f71330bda0f19bfd67ba5983))


### Features

* add newsletters templates ([#437](https://github.com/Automattic/newspack-block-theme/issues/437)) ([cc8d244](https://github.com/Automattic/newspack-block-theme/commit/cc8d244b3f4d1f8138058ddd406ee6855bb3b730))
* copy newsletters compatibility ([#436](https://github.com/Automattic/newspack-block-theme/issues/436)) ([214f73a](https://github.com/Automattic/newspack-block-theme/commit/214f73aa876184d7c036cfd3b919a34777df6347))
* swap in Overlay Menu block to replace template parts ([30be676](https://github.com/Automattic/newspack-block-theme/commit/30be67680cc32bad0c579a4cd04ede93bbbe80d1))

# [1.27.0](https://github.com/Automattic/newspack-block-theme/compare/v1.26.0...v1.27.0) (2026-04-13)


### Bug Fixes

* avoid race condition by setting up a reference for the subtitle ([82c430e](https://github.com/Automattic/newspack-block-theme/commit/82c430e341283e8d778e3b4b5ede72754133f6bb))
* clean up timeout and rework subtitle saves to prevent reruns from mangling input ([517a8d4](https://github.com/Automattic/newspack-block-theme/commit/517a8d4535f53a08c927324980e98597e56822f6))
* ensure context by setting in `block.json` and also adding a(n empty) default ([f0c478d](https://github.com/Automattic/newspack-block-theme/commit/f0c478dac9ebebaa4d36abfbfa5e8e58cf7edae5))
* escaped output and switched to `textContent` as an extra guard against naughty subtitles ([cb60c98](https://github.com/Automattic/newspack-block-theme/commit/cb60c98d0bb6eec7fce94b656bd74b3af9bb71e5))
* guard against `null` from `get_current_screen()` ([e298edb](https://github.com/Automattic/newspack-block-theme/commit/e298edb82ba156806e4ed3292eadbc26761b2ade))
* normalize subtitle to avoid race condition ([d26534d](https://github.com/Automattic/newspack-block-theme/commit/d26534d73a5dc97e9e245e621bc3647cd61f604d))
* separate mount and update effects ([d4d4892](https://github.com/Automattic/newspack-block-theme/commit/d4d48929c7a9a61e5bba21f189a1e89f6fa93851))
* **subtitle:** use CSS variable for content width in post editor ([#434](https://github.com/Automattic/newspack-block-theme/issues/434)) ([aed2e69](https://github.com/Automattic/newspack-block-theme/commit/aed2e693dd746a7e83a07b9724db57de3ba78718))
* update subtitle block for compatibility with iframed Post Editor ([36c1bd6](https://github.com/Automattic/newspack-block-theme/commit/36c1bd6a8dc27ec15f0382321ff654364d833d9e))
* update subtitle block for compatibility with iframed Post Editor ([76e9135](https://github.com/Automattic/newspack-block-theme/commit/76e913567869ea775a1bc234f6ad3fdbe26133f7))
* use asset file for dependencies ([4ecefa4](https://github.com/Automattic/newspack-block-theme/commit/4ecefa4537c83a08afaa76557c0fe0209bd8dd38))
* work around non-string subtitles ([e081213](https://github.com/Automattic/newspack-block-theme/commit/e081213e5b817aba9e7aa09b7a3982b265b5776a))


### Features

* **patterns:** use author profile block in author bio patterns ([#424](https://github.com/Automattic/newspack-block-theme/issues/424)) ([cf8d5e8](https://github.com/Automattic/newspack-block-theme/commit/cf8d5e837a00a289a56405c9b897907ee11c1257))
* **post-date:** add modified date block to post-meta patterns ([#428](https://github.com/Automattic/newspack-block-theme/issues/428)) ([656831c](https://github.com/Automattic/newspack-block-theme/commit/656831c1a3be5fbf423c8342cee062c6d98a7070))
* switch header/footer to use WordPress's block visibility ([#432](https://github.com/Automattic/newspack-block-theme/issues/432)) ([914a41c](https://github.com/Automattic/newspack-block-theme/commit/914a41c93208c2dfd76498606d43bb404e74dd82))

# [1.27.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.26.0...v1.27.0-alpha.1) (2026-04-02)


### Bug Fixes

* avoid race condition by setting up a reference for the subtitle ([82c430e](https://github.com/Automattic/newspack-block-theme/commit/82c430e341283e8d778e3b4b5ede72754133f6bb))
* clean up timeout and rework subtitle saves to prevent reruns from mangling input ([517a8d4](https://github.com/Automattic/newspack-block-theme/commit/517a8d4535f53a08c927324980e98597e56822f6))
* ensure context by setting in `block.json` and also adding a(n empty) default ([f0c478d](https://github.com/Automattic/newspack-block-theme/commit/f0c478dac9ebebaa4d36abfbfa5e8e58cf7edae5))
* escaped output and switched to `textContent` as an extra guard against naughty subtitles ([cb60c98](https://github.com/Automattic/newspack-block-theme/commit/cb60c98d0bb6eec7fce94b656bd74b3af9bb71e5))
* guard against `null` from `get_current_screen()` ([e298edb](https://github.com/Automattic/newspack-block-theme/commit/e298edb82ba156806e4ed3292eadbc26761b2ade))
* normalize subtitle to avoid race condition ([d26534d](https://github.com/Automattic/newspack-block-theme/commit/d26534d73a5dc97e9e245e621bc3647cd61f604d))
* separate mount and update effects ([d4d4892](https://github.com/Automattic/newspack-block-theme/commit/d4d48929c7a9a61e5bba21f189a1e89f6fa93851))
* **subtitle:** use CSS variable for content width in post editor ([#434](https://github.com/Automattic/newspack-block-theme/issues/434)) ([aed2e69](https://github.com/Automattic/newspack-block-theme/commit/aed2e693dd746a7e83a07b9724db57de3ba78718))
* update subtitle block for compatibility with iframed Post Editor ([36c1bd6](https://github.com/Automattic/newspack-block-theme/commit/36c1bd6a8dc27ec15f0382321ff654364d833d9e))
* update subtitle block for compatibility with iframed Post Editor ([76e9135](https://github.com/Automattic/newspack-block-theme/commit/76e913567869ea775a1bc234f6ad3fdbe26133f7))
* use asset file for dependencies ([4ecefa4](https://github.com/Automattic/newspack-block-theme/commit/4ecefa4537c83a08afaa76557c0fe0209bd8dd38))
* work around non-string subtitles ([e081213](https://github.com/Automattic/newspack-block-theme/commit/e081213e5b817aba9e7aa09b7a3982b265b5776a))


### Features

* **patterns:** use author profile block in author bio patterns ([#424](https://github.com/Automattic/newspack-block-theme/issues/424)) ([cf8d5e8](https://github.com/Automattic/newspack-block-theme/commit/cf8d5e837a00a289a56405c9b897907ee11c1257))
* **post-date:** add modified date block to post-meta patterns ([#428](https://github.com/Automattic/newspack-block-theme/issues/428)) ([656831c](https://github.com/Automattic/newspack-block-theme/commit/656831c1a3be5fbf423c8342cee062c6d98a7070))
* switch header/footer to use WordPress's block visibility ([#432](https://github.com/Automattic/newspack-block-theme/issues/432)) ([914a41c](https://github.com/Automattic/newspack-block-theme/commit/914a41c93208c2dfd76498606d43bb404e74dd82))

# [1.26.0](https://github.com/Automattic/newspack-block-theme/compare/v1.25.0...v1.26.0) (2026-03-16)


### Features

* add AGENTS.md and CLAUDE.md; update README.md ([#420](https://github.com/Automattic/newspack-block-theme/issues/420)) ([7494928](https://github.com/Automattic/newspack-block-theme/commit/7494928ab6d11c941bc153842fde0eb641b9f349))
* add featured image caption block to templates ([#415](https://github.com/Automattic/newspack-block-theme/issues/415)) ([7db45fe](https://github.com/Automattic/newspack-block-theme/commit/7db45fe737d7cdf36b4c4ca435c090d3efd9e90b))
* add new styles ([#416](https://github.com/Automattic/newspack-block-theme/issues/416)) ([37e82db](https://github.com/Automattic/newspack-block-theme/commit/37e82dbcdbd00a06adb4f70571783f5234ebb0a4))
* remove newspack-grid, newspack-grid-small, newspack-grid-sidebar ([#417](https://github.com/Automattic/newspack-block-theme/issues/417)) ([86b8292](https://github.com/Automattic/newspack-block-theme/commit/86b8292489d51fdab5430f70bace3c50d32eb332))
* use dynamic copyright date block in footer patterns ([#414](https://github.com/Automattic/newspack-block-theme/issues/414)) ([22c9bc8](https://github.com/Automattic/newspack-block-theme/commit/22c9bc826b385098379a5139511658c62880bba5))

# [1.26.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.25.0...v1.26.0-alpha.1) (2026-03-05)


### Features

* add AGENTS.md and CLAUDE.md; update README.md ([#420](https://github.com/Automattic/newspack-block-theme/issues/420)) ([7494928](https://github.com/Automattic/newspack-block-theme/commit/7494928ab6d11c941bc153842fde0eb641b9f349))
* add featured image caption block to templates ([#415](https://github.com/Automattic/newspack-block-theme/issues/415)) ([7db45fe](https://github.com/Automattic/newspack-block-theme/commit/7db45fe737d7cdf36b4c4ca435c090d3efd9e90b))
* add new styles ([#416](https://github.com/Automattic/newspack-block-theme/issues/416)) ([37e82db](https://github.com/Automattic/newspack-block-theme/commit/37e82dbcdbd00a06adb4f70571783f5234ebb0a4))
* remove newspack-grid, newspack-grid-small, newspack-grid-sidebar ([#417](https://github.com/Automattic/newspack-block-theme/issues/417)) ([86b8292](https://github.com/Automattic/newspack-block-theme/commit/86b8292489d51fdab5430f70bace3c50d32eb332))
* use dynamic copyright date block in footer patterns ([#414](https://github.com/Automattic/newspack-block-theme/issues/414)) ([22c9bc8](https://github.com/Automattic/newspack-block-theme/commit/22c9bc826b385098379a5139511658c62880bba5))

# [1.25.0](https://github.com/Automattic/newspack-block-theme/compare/v1.24.0...v1.25.0) (2026-03-02)


### Features

* allow parent to dictate position when it has the position class ([#409](https://github.com/Automattic/newspack-block-theme/issues/409)) ([94e3ce7](https://github.com/Automattic/newspack-block-theme/commit/94e3ce72de544214fadcfe9915de5029a70b5dd2))
* make My Account button only show icon on mobile ([#406](https://github.com/Automattic/newspack-block-theme/issues/406)) ([c04788b](https://github.com/Automattic/newspack-block-theme/commit/c04788b8b73f72e24fb1f4eccee14746e3bf5939))

# [1.25.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.24.0...v1.25.0-alpha.1) (2026-02-19)


### Features

* allow parent to dictate position when it has the position class ([#409](https://github.com/Automattic/newspack-block-theme/issues/409)) ([94e3ce7](https://github.com/Automattic/newspack-block-theme/commit/94e3ce72de544214fadcfe9915de5029a70b5dd2))
* make My Account button only show icon on mobile ([#406](https://github.com/Automattic/newspack-block-theme/issues/406)) ([c04788b](https://github.com/Automattic/newspack-block-theme/commit/c04788b8b73f72e24fb1f4eccee14746e3bf5939))

# [1.24.0](https://github.com/Automattic/newspack-block-theme/compare/v1.23.0...v1.24.0) (2026-02-16)


### Bug Fixes

* dequeue some Woo styles from the theme ([#401](https://github.com/Automattic/newspack-block-theme/issues/401)) ([821f81c](https://github.com/Automattic/newspack-block-theme/commit/821f81ceb53a396957e1bcbf5d1ab01e51d3ed1b))
* update single post template widths ([#407](https://github.com/Automattic/newspack-block-theme/issues/407)) ([a99b2cf](https://github.com/Automattic/newspack-block-theme/commit/a99b2cf2307380acf1ff386d08bcec96677400fe))


### Features

* Add My Account button block  ([#400](https://github.com/Automattic/newspack-block-theme/issues/400)) ([7c99f35](https://github.com/Automattic/newspack-block-theme/commit/7c99f35e6b01d412650e9c96b867e5fc654caa46))
* add My Account button block to all header patterns ([#404](https://github.com/Automattic/newspack-block-theme/issues/404)) ([d7b8b4a](https://github.com/Automattic/newspack-block-theme/commit/d7b8b4a3a501b77b6b9a404fa0fc155ea12dc43c))

# [1.24.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.23.0...v1.24.0-alpha.1) (2026-02-05)


### Bug Fixes

* dequeue some Woo styles from the theme ([#401](https://github.com/Automattic/newspack-block-theme/issues/401)) ([821f81c](https://github.com/Automattic/newspack-block-theme/commit/821f81ceb53a396957e1bcbf5d1ab01e51d3ed1b))
* update single post template widths ([#407](https://github.com/Automattic/newspack-block-theme/issues/407)) ([a99b2cf](https://github.com/Automattic/newspack-block-theme/commit/a99b2cf2307380acf1ff386d08bcec96677400fe))


### Features

* Add My Account button block  ([#400](https://github.com/Automattic/newspack-block-theme/issues/400)) ([7c99f35](https://github.com/Automattic/newspack-block-theme/commit/7c99f35e6b01d412650e9c96b867e5fc654caa46))
* add My Account button block to all header patterns ([#404](https://github.com/Automattic/newspack-block-theme/issues/404)) ([d7b8b4a](https://github.com/Automattic/newspack-block-theme/commit/d7b8b4a3a501b77b6b9a404fa0fc155ea12dc43c))

# [1.23.0](https://github.com/Automattic/newspack-block-theme/compare/v1.22.0...v1.23.0) (2026-02-02)


### Features

* **byline:** add byline block support to patterns and templates ([#399](https://github.com/Automattic/newspack-block-theme/issues/399)) ([c0d8a90](https://github.com/Automattic/newspack-block-theme/commit/c0d8a906c8c309f949bdb418ee58309eccd03b03))

# [1.22.0](https://github.com/Automattic/newspack-block-theme/compare/v1.21.0...v1.22.0) (2025-11-24)


### Features

* Merge pull request [#393](https://github.com/Automattic/newspack-block-theme/issues/393) from Automattic/trunk ([7c7531d](https://github.com/Automattic/newspack-block-theme/commit/7c7531df5ee70793ff1d0b054b47d577af62c232))

# [1.22.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.21.0...v1.22.0-alpha.1) (2025-11-13)


### Features

* Merge pull request [#393](https://github.com/Automattic/newspack-block-theme/issues/393) from Automattic/trunk ([7c7531d](https://github.com/Automattic/newspack-block-theme/commit/7c7531df5ee70793ff1d0b054b47d577af62c232))

# [1.21.0](https://github.com/Automattic/newspack-block-theme/compare/v1.20.0...v1.21.0) (2025-09-08)


### Bug Fixes

* add missing columns patterns to inserter ([#373](https://github.com/Automattic/newspack-block-theme/issues/373)) ([b34f0c9](https://github.com/Automattic/newspack-block-theme/commit/b34f0c978a82f14356164ea247b0ba0c0be010ea))


### Features

* move pattern functionalities to class-patterns ([e1ce70f](https://github.com/Automattic/newspack-block-theme/commit/e1ce70f875a5d7c1e564b7e556a414cc2bad0580))

# [1.21.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.20.0...v1.21.0-alpha.1) (2025-08-28)


### Bug Fixes

* add missing columns patterns to inserter ([#373](https://github.com/Automattic/newspack-block-theme/issues/373)) ([b34f0c9](https://github.com/Automattic/newspack-block-theme/commit/b34f0c978a82f14356164ea247b0ba0c0be010ea))


### Features

* move pattern functionalities to class-patterns ([e1ce70f](https://github.com/Automattic/newspack-block-theme/commit/e1ce70f875a5d7c1e564b7e556a414cc2bad0580))

# [1.20.0](https://github.com/Automattic/newspack-block-theme/compare/v1.19.0...v1.20.0) (2025-08-25)


### Features

* add basic woocommerce support ([#368](https://github.com/Automattic/newspack-block-theme/issues/368)) ([ce04831](https://github.com/Automattic/newspack-block-theme/commit/ce0483187f768925ae757d347309141802a33647))
* add i18n ([#364](https://github.com/Automattic/newspack-block-theme/issues/364)) ([ebfe854](https://github.com/Automattic/newspack-block-theme/commit/ebfe854cdb352e91b5cec57535fc8c6b0fb14151))

# [1.20.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.19.0...v1.20.0-alpha.1) (2025-08-14)


### Features

* add basic woocommerce support ([#368](https://github.com/Automattic/newspack-block-theme/issues/368)) ([ce04831](https://github.com/Automattic/newspack-block-theme/commit/ce0483187f768925ae757d347309141802a33647))
* add i18n ([#364](https://github.com/Automattic/newspack-block-theme/issues/364)) ([ebfe854](https://github.com/Automattic/newspack-block-theme/commit/ebfe854cdb352e91b5cec57535fc8c6b0fb14151))

# [1.19.0](https://github.com/Automattic/newspack-block-theme/compare/v1.18.1...v1.19.0) (2025-06-30)


### Features

* update menu position using JS ([#354](https://github.com/Automattic/newspack-block-theme/issues/354)) ([0227bb5](https://github.com/Automattic/newspack-block-theme/commit/0227bb51f02c887baf8ddcaf72cccbe56ce207a8))

# [1.19.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.18.1...v1.19.0-alpha.1) (2025-06-19)


### Features

* update menu position using JS ([#354](https://github.com/Automattic/newspack-block-theme/issues/354)) ([0227bb5](https://github.com/Automattic/newspack-block-theme/commit/0227bb51f02c887baf8ddcaf72cccbe56ce207a8))

## [1.18.1](https://github.com/Automattic/newspack-block-theme/compare/v1.18.0...v1.18.1) (2025-06-16)


### Bug Fixes

* have header inherit position if mobile or desktop header has a custom position ([#346](https://github.com/Automattic/newspack-block-theme/issues/346)) ([eb08ace](https://github.com/Automattic/newspack-block-theme/commit/eb08ace2689b7c94bc92b4d96ddc2c25e20c22aa))

## [1.18.1-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.18.0...v1.18.1-alpha.1) (2025-06-05)


### Bug Fixes

* have header inherit position if mobile or desktop header has a custom position ([#346](https://github.com/Automattic/newspack-block-theme/issues/346)) ([eb08ace](https://github.com/Automattic/newspack-block-theme/commit/eb08ace2689b7c94bc92b4d96ddc2c25e20c22aa))

# [1.18.0](https://github.com/Automattic/newspack-block-theme/compare/v1.17.0...v1.18.0) (2025-06-02)


### Features

* **featured-image:** overwrite link style when it's the caption ([#339](https://github.com/Automattic/newspack-block-theme/issues/339)) ([cad839e](https://github.com/Automattic/newspack-block-theme/commit/cad839e4e3d96de4b5067b428849d01b1a205edd))

# [1.18.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.17.0...v1.18.0-alpha.1) (2025-05-23)


### Features

* **featured-image:** overwrite link style when it's the caption ([#339](https://github.com/Automattic/newspack-block-theme/issues/339)) ([cad839e](https://github.com/Automattic/newspack-block-theme/commit/cad839e4e3d96de4b5067b428849d01b1a205edd))

# [1.17.0](https://github.com/Automattic/newspack-block-theme/compare/v1.16.0...v1.17.0) (2025-05-14)


### Bug Fixes

* only use text-wrap pretty for headings ([#338](https://github.com/Automattic/newspack-block-theme/issues/338)) ([7bf5940](https://github.com/Automattic/newspack-block-theme/commit/7bf5940b791e07889428fd3bd38a0822a99007f8))
* remove atomic-proxy-bar style ([e3f49c4](https://github.com/Automattic/newspack-block-theme/commit/e3f49c44781dc51483fbab2ca9e88ae937446ff9))


### Features

* add atomic-proxy-bar style ([#332](https://github.com/Automattic/newspack-block-theme/issues/332)) ([d95a9a8](https://github.com/Automattic/newspack-block-theme/commit/d95a9a8b9c866c989294a2de799682a32f3e6fec))
* **templates:** add wide and wide no-title ([#331](https://github.com/Automattic/newspack-block-theme/issues/331)) ([e10a4a5](https://github.com/Automattic/newspack-block-theme/commit/e10a4a573be4ec830e48fbc6538a853146f9526c))

# [1.17.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.16.0...v1.17.0-alpha.1) (2025-04-23)


### Bug Fixes

* only use text-wrap pretty for headings ([#338](https://github.com/Automattic/newspack-block-theme/issues/338)) ([7bf5940](https://github.com/Automattic/newspack-block-theme/commit/7bf5940b791e07889428fd3bd38a0822a99007f8))
* remove atomic-proxy-bar style ([e3f49c4](https://github.com/Automattic/newspack-block-theme/commit/e3f49c44781dc51483fbab2ca9e88ae937446ff9))


### Features

* add atomic-proxy-bar style ([#332](https://github.com/Automattic/newspack-block-theme/issues/332)) ([d95a9a8](https://github.com/Automattic/newspack-block-theme/commit/d95a9a8b9c866c989294a2de799682a32f3e6fec))
* **templates:** add wide and wide no-title ([#331](https://github.com/Automattic/newspack-block-theme/issues/331)) ([e10a4a5](https://github.com/Automattic/newspack-block-theme/commit/e10a4a573be4ec830e48fbc6538a853146f9526c))

# [1.16.0](https://github.com/Automattic/newspack-block-theme/compare/v1.15.1...v1.16.0) (2025-04-14)


### Features

* **harold:** new colours and typography ([#325](https://github.com/Automattic/newspack-block-theme/issues/325)) ([c31184f](https://github.com/Automattic/newspack-block-theme/commit/c31184ff742255ed2e4d23440daf644f2ebc22e9))
* **homepage-articles:** simplify and rename to content-loop ([#326](https://github.com/Automattic/newspack-block-theme/issues/326)) ([5ab0514](https://github.com/Automattic/newspack-block-theme/commit/5ab0514f0b0a15d6d9b0523a946e6a8fa218a92e))

# [1.16.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.15.1...v1.16.0-alpha.1) (2025-04-04)


### Features

* **harold:** new colours and typography ([#325](https://github.com/Automattic/newspack-block-theme/issues/325)) ([c31184f](https://github.com/Automattic/newspack-block-theme/commit/c31184ff742255ed2e4d23440daf644f2ebc22e9))
* **homepage-articles:** simplify and rename to content-loop ([#326](https://github.com/Automattic/newspack-block-theme/issues/326)) ([5ab0514](https://github.com/Automattic/newspack-block-theme/commit/5ab0514f0b0a15d6d9b0523a946e6a8fa218a92e))

## [1.15.1](https://github.com/Automattic/newspack-block-theme/compare/v1.15.0...v1.15.1) (2025-03-31)


### Bug Fixes

* force release ([51ccdf9](https://github.com/Automattic/newspack-block-theme/commit/51ccdf90f3587ca4a632b993f36f5acf00f561ae))

# [1.15.0](https://github.com/Automattic/newspack-block-theme/compare/v1.14.0...v1.15.0) (2025-03-24)


### Bug Fixes

* update block theme dependencies for WP 6.6 ([#314](https://github.com/Automattic/newspack-block-theme/issues/314)) ([13247ec](https://github.com/Automattic/newspack-block-theme/commit/13247eceaa7511daa48e2bdf87d30c3633ee6996))


### Features

* add new harold theme style variation and add body class based on active variation ([#323](https://github.com/Automattic/newspack-block-theme/issues/323)) ([4e07071](https://github.com/Automattic/newspack-block-theme/commit/4e070715aa956750dfaff30f599aa2883e12b9cf))
* update colours ([#311](https://github.com/Automattic/newspack-block-theme/issues/311)) ([c8e01ae](https://github.com/Automattic/newspack-block-theme/commit/c8e01ae9c2afef2c7c54da849e9ba006a10c27a9))
* update columns breakpoints and use mixins ([#324](https://github.com/Automattic/newspack-block-theme/issues/324)) ([bb0f61b](https://github.com/Automattic/newspack-block-theme/commit/bb0f61ba64bb649df2316a3182ba0cc0230fd053))

# [1.14.0](https://github.com/Automattic/newspack-block-theme/compare/v1.13.0...v1.14.0) (2024-11-25)


### Features

* add post subtitle support ([#290](https://github.com/Automattic/newspack-block-theme/issues/290)) ([593ffca](https://github.com/Automattic/newspack-block-theme/commit/593ffca3787ebffd665433d82dadea450bb9b7b9))

# [1.14.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.13.0...v1.14.0-alpha.1) (2024-11-15)


### Features

* add post subtitle support ([#290](https://github.com/Automattic/newspack-block-theme/issues/290)) ([593ffca](https://github.com/Automattic/newspack-block-theme/commit/593ffca3787ebffd665433d82dadea450bb9b7b9))

# [1.13.0](https://github.com/Automattic/newspack-block-theme/compare/v1.12.0...v1.13.0) (2024-10-08)


### Features

* **footer:** update template part to use desktop and mobile parts ([#282](https://github.com/Automattic/newspack-block-theme/issues/282)) ([f1ea195](https://github.com/Automattic/newspack-block-theme/commit/f1ea1956e8cbbd521bf6eacb38941dfec2b2167d))

# [1.13.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.12.0...v1.13.0-alpha.1) (2024-08-29)


### Features

* **footer:** update template part to use desktop and mobile parts ([#282](https://github.com/Automattic/newspack-block-theme/issues/282)) ([f1ea195](https://github.com/Automattic/newspack-block-theme/commit/f1ea1956e8cbbd521bf6eacb38941dfec2b2167d))

# [1.12.0](https://github.com/Automattic/newspack-block-theme/compare/v1.11.0...v1.12.0) (2024-07-30)


### Bug Fixes

* **buttons:** outline variations missing style with wp 6.6 ([#270](https://github.com/Automattic/newspack-block-theme/issues/270)) ([dabae5b](https://github.com/Automattic/newspack-block-theme/commit/dabae5b8fe6024d05b1f456350e3313ef073c5ed))
* **navigation:** correct gap between items when using responsive nav ([#243](https://github.com/Automattic/newspack-block-theme/issues/243)) ([b707642](https://github.com/Automattic/newspack-block-theme/commit/b707642519c592f39f46b4755a736dabb558ef2b))
* **newspack-grid:** adjust large and extra large screens ([#256](https://github.com/Automattic/newspack-block-theme/issues/256)) ([47b748c](https://github.com/Automattic/newspack-block-theme/commit/47b748c6e953ce10cf58988fda3fd1f35d4af105))
* **search-results:** no results apply alignwide to homepage articles ([#242](https://github.com/Automattic/newspack-block-theme/issues/242)) ([315bc59](https://github.com/Automattic/newspack-block-theme/commit/315bc5957b834c773b3962af7bdcbc291e062a31))
* sticky element with admin bar on small screens ([#238](https://github.com/Automattic/newspack-block-theme/issues/238)) ([2f2b056](https://github.com/Automattic/newspack-block-theme/commit/2f2b0563b476b3aafc07703a0e256dd5a28d295f))


### Features

* add 50/50 Image/Header single post template ([#268](https://github.com/Automattic/newspack-block-theme/issues/268)) ([a010920](https://github.com/Automattic/newspack-block-theme/commit/a010920985ff9cca02e5507770050d6a4a618df5))
* add blank screenshot ([#252](https://github.com/Automattic/newspack-block-theme/issues/252)) ([8839ea5](https://github.com/Automattic/newspack-block-theme/commit/8839ea5c4e00b26c2f5d9d9b0c013f7c627d44f4))
* add columns patterns ([#274](https://github.com/Automattic/newspack-block-theme/issues/274)) ([41290a7](https://github.com/Automattic/newspack-block-theme/commit/41290a70829967523198a08a643a82e3e2ab8631))
* add drawer menu pattern; reorganise overlays ([#244](https://github.com/Automattic/newspack-block-theme/issues/244)) ([4cdb255](https://github.com/Automattic/newspack-block-theme/commit/4cdb2554f198d05fc0e575199165bfbb9aabf02b))
* add footer patterns and update template part ([#177](https://github.com/Automattic/newspack-block-theme/issues/177)) ([615c2a2](https://github.com/Automattic/newspack-block-theme/commit/615c2a2d0ea5c36b214b6d4cf93af0822c998542))
* add header desktop patterns ([#254](https://github.com/Automattic/newspack-block-theme/issues/254)) ([ff6d296](https://github.com/Automattic/newspack-block-theme/commit/ff6d29602537b592acc476c385b4181f284fd76e))
* add header mobile patterns ([#255](https://github.com/Automattic/newspack-block-theme/issues/255)) ([e1f371d](https://github.com/Automattic/newspack-block-theme/commit/e1f371d04ed542c4626ff768e5f7d665777f1911))
* add margin to alignleft and alignright elements ([#261](https://github.com/Automattic/newspack-block-theme/issues/261)) ([97140de](https://github.com/Automattic/newspack-block-theme/commit/97140dee8fd11700bb2898dd92936ef8afa26a40))
* add optional mobile-only and desktop-only to fixed elements ([#246](https://github.com/Automattic/newspack-block-theme/issues/246)) ([2cdfc1b](https://github.com/Automattic/newspack-block-theme/commit/2cdfc1b9ad00df60f06068565687b71d84de45ec))
* add sidebar grid layout ([#258](https://github.com/Automattic/newspack-block-theme/issues/258)) ([cb29dc6](https://github.com/Automattic/newspack-block-theme/commit/cb29dc6197e20a1313cc2a6e78ea7b3bb62e56c3))
* add sidebar-contents template part and use it for single sidebar layout ([#276](https://github.com/Automattic/newspack-block-theme/issues/276)) ([488e43c](https://github.com/Automattic/newspack-block-theme/commit/488e43c3280ce58e8be69d3157188a2098ccadf2))
* add support for yoast blocks (faq, how-to) ([#241](https://github.com/Automattic/newspack-block-theme/issues/241)) ([d63260a](https://github.com/Automattic/newspack-block-theme/commit/d63260a45bf08624e624628e3ee6d260c5fb3a2a))
* add support for yoast breadcrumbs; move cap styles to subdirectory ([#239](https://github.com/Automattic/newspack-block-theme/issues/239)) ([463584e](https://github.com/Automattic/newspack-block-theme/commit/463584e0af35f074fe614ef3bd4749ef867d5c76))
* **author-list:** add block styles ([#250](https://github.com/Automattic/newspack-block-theme/issues/250)) ([7acd4a3](https://github.com/Automattic/newspack-block-theme/commit/7acd4a36c7a05cc1c471d87a4a117c35a99f23cb))
* **breadcrumbs:** ensure text and link colors are supported ([#240](https://github.com/Automattic/newspack-block-theme/issues/240)) ([fb3e939](https://github.com/Automattic/newspack-block-theme/commit/fb3e93921a67b99720d6ae0533d7d850f259f65c))
* **button:** x-small size adjust min-height and border-radius for consistency with newspack-icon ([#271](https://github.com/Automattic/newspack-block-theme/issues/271)) ([5fa1f7c](https://github.com/Automattic/newspack-block-theme/commit/5fa1f7c2b796356e1e9292dca629ffb908803b43))
* **footer:** add new pattern and adjust style 4 ([#269](https://github.com/Automattic/newspack-block-theme/issues/269)) ([9e826e9](https://github.com/Automattic/newspack-block-theme/commit/9e826e96f4acf11c07963003debee8e324f8f721))
* **footer:** update credits ([#278](https://github.com/Automattic/newspack-block-theme/issues/278)) ([cec87b1](https://github.com/Automattic/newspack-block-theme/commit/cec87b18fbe31fe10389ed28955b26bd8973aa29))
* **homepage-articles:** add transition to links ([#234](https://github.com/Automattic/newspack-block-theme/issues/234)) ([bdf924a](https://github.com/Automattic/newspack-block-theme/commit/bdf924af945517501c03bb5871033b88f5097ce0))
* **navigation:** update hover style; remove underline and use color-mix instead ([#247](https://github.com/Automattic/newspack-block-theme/issues/247)) ([08994a3](https://github.com/Automattic/newspack-block-theme/commit/08994a3daf634c7c673543c8ec5b6c123cc94396))
* **overlay:** update close button to 64px tall/wide ([#233](https://github.com/Automattic/newspack-block-theme/issues/233)) ([39e9dad](https://github.com/Automattic/newspack-block-theme/commit/39e9dad09d9f65824fb4895ce7c13581b44fe17a))
* **patterns:** add 1 new footer pattern ([#257](https://github.com/Automattic/newspack-block-theme/issues/257)) ([758e38c](https://github.com/Automattic/newspack-block-theme/commit/758e38c62c53ee531c0d5c3541251a4f626cbd94))
* **patterns:** improve columns preview; add 25/25/25/25 ([#275](https://github.com/Automattic/newspack-block-theme/issues/275)) ([0f5427f](https://github.com/Automattic/newspack-block-theme/commit/0f5427fb4230ec08bec07682a3d282c1d782caa3))
* **post-carousel:** update style ([#249](https://github.com/Automattic/newspack-block-theme/issues/249)) ([d87ac30](https://github.com/Automattic/newspack-block-theme/commit/d87ac3073c3d8fecb61cbf94252bdcdd4b03c82f))
* **post-meta:** update hover state ([#251](https://github.com/Automattic/newspack-block-theme/issues/251)) ([b1ba1d5](https://github.com/Automattic/newspack-block-theme/commit/b1ba1d56e41acf9263748bb9a8ad66ec7542580a))
* reorganise header parts ([#253](https://github.com/Automattic/newspack-block-theme/issues/253)) ([14ee203](https://github.com/Automattic/newspack-block-theme/commit/14ee20376225a7e3840906287014c894e9413fd8))
* **search:** update template part to match pagination with archive ([#279](https://github.com/Automattic/newspack-block-theme/issues/279)) ([6e3da20](https://github.com/Automattic/newspack-block-theme/commit/6e3da20874f92e3e59a451c7f38eedb80c7f053a))
* simplify home and index templates to match page and archive; remove archive pattern ([#277](https://github.com/Automattic/newspack-block-theme/issues/277)) ([5d09c60](https://github.com/Automattic/newspack-block-theme/commit/5d09c60145bf659446fd142bc6a3064fd6ec013c))
* update comments elements names ([#235](https://github.com/Automattic/newspack-block-theme/issues/235)) ([27e963e](https://github.com/Automattic/newspack-block-theme/commit/27e963eca74f5e2b51c6ff12becac42cb1ca2452))
* update mobile menu elements names ([#237](https://github.com/Automattic/newspack-block-theme/issues/237)) ([a1f5070](https://github.com/Automattic/newspack-block-theme/commit/a1f50701bb0df40b6a54805b37b4f9b4984ee497))
* update post header patterns; add wide option ([#267](https://github.com/Automattic/newspack-block-theme/issues/267)) ([1bd4d76](https://github.com/Automattic/newspack-block-theme/commit/1bd4d764ea32d9f15ec79aa5e68b078661618b41))
* update post header to use pattern; introduce sidebar post template ([#262](https://github.com/Automattic/newspack-block-theme/issues/262)) ([ff015a1](https://github.com/Automattic/newspack-block-theme/commit/ff015a1bac53f3cdefb3f83f69e612b6fae63cae))
* update post-content; adjust align left/right position ([#280](https://github.com/Automattic/newspack-block-theme/issues/280)) ([b1a5378](https://github.com/Automattic/newspack-block-theme/commit/b1a5378cd9dd5675e7da0675bfe2e2499a123253))
* update search elements names ([#236](https://github.com/Automattic/newspack-block-theme/issues/236)) ([0f926d2](https://github.com/Automattic/newspack-block-theme/commit/0f926d2b28a4a5b68b53b180e1c0fd0d52c78da3))

# [1.12.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.11.0...v1.12.0-alpha.1) (2024-07-19)


### Bug Fixes

* **buttons:** outline variations missing style with wp 6.6 ([#270](https://github.com/Automattic/newspack-block-theme/issues/270)) ([dabae5b](https://github.com/Automattic/newspack-block-theme/commit/dabae5b8fe6024d05b1f456350e3313ef073c5ed))
* **navigation:** correct gap between items when using responsive nav ([#243](https://github.com/Automattic/newspack-block-theme/issues/243)) ([b707642](https://github.com/Automattic/newspack-block-theme/commit/b707642519c592f39f46b4755a736dabb558ef2b))
* **newspack-grid:** adjust large and extra large screens ([#256](https://github.com/Automattic/newspack-block-theme/issues/256)) ([47b748c](https://github.com/Automattic/newspack-block-theme/commit/47b748c6e953ce10cf58988fda3fd1f35d4af105))
* **search-results:** no results apply alignwide to homepage articles ([#242](https://github.com/Automattic/newspack-block-theme/issues/242)) ([315bc59](https://github.com/Automattic/newspack-block-theme/commit/315bc5957b834c773b3962af7bdcbc291e062a31))
* sticky element with admin bar on small screens ([#238](https://github.com/Automattic/newspack-block-theme/issues/238)) ([2f2b056](https://github.com/Automattic/newspack-block-theme/commit/2f2b0563b476b3aafc07703a0e256dd5a28d295f))


### Features

* add 50/50 Image/Header single post template ([#268](https://github.com/Automattic/newspack-block-theme/issues/268)) ([a010920](https://github.com/Automattic/newspack-block-theme/commit/a010920985ff9cca02e5507770050d6a4a618df5))
* add blank screenshot ([#252](https://github.com/Automattic/newspack-block-theme/issues/252)) ([8839ea5](https://github.com/Automattic/newspack-block-theme/commit/8839ea5c4e00b26c2f5d9d9b0c013f7c627d44f4))
* add columns patterns ([#274](https://github.com/Automattic/newspack-block-theme/issues/274)) ([41290a7](https://github.com/Automattic/newspack-block-theme/commit/41290a70829967523198a08a643a82e3e2ab8631))
* add drawer menu pattern; reorganise overlays ([#244](https://github.com/Automattic/newspack-block-theme/issues/244)) ([4cdb255](https://github.com/Automattic/newspack-block-theme/commit/4cdb2554f198d05fc0e575199165bfbb9aabf02b))
* add footer patterns and update template part ([#177](https://github.com/Automattic/newspack-block-theme/issues/177)) ([615c2a2](https://github.com/Automattic/newspack-block-theme/commit/615c2a2d0ea5c36b214b6d4cf93af0822c998542))
* add header desktop patterns ([#254](https://github.com/Automattic/newspack-block-theme/issues/254)) ([ff6d296](https://github.com/Automattic/newspack-block-theme/commit/ff6d29602537b592acc476c385b4181f284fd76e))
* add header mobile patterns ([#255](https://github.com/Automattic/newspack-block-theme/issues/255)) ([e1f371d](https://github.com/Automattic/newspack-block-theme/commit/e1f371d04ed542c4626ff768e5f7d665777f1911))
* add margin to alignleft and alignright elements ([#261](https://github.com/Automattic/newspack-block-theme/issues/261)) ([97140de](https://github.com/Automattic/newspack-block-theme/commit/97140dee8fd11700bb2898dd92936ef8afa26a40))
* add optional mobile-only and desktop-only to fixed elements ([#246](https://github.com/Automattic/newspack-block-theme/issues/246)) ([2cdfc1b](https://github.com/Automattic/newspack-block-theme/commit/2cdfc1b9ad00df60f06068565687b71d84de45ec))
* add sidebar grid layout ([#258](https://github.com/Automattic/newspack-block-theme/issues/258)) ([cb29dc6](https://github.com/Automattic/newspack-block-theme/commit/cb29dc6197e20a1313cc2a6e78ea7b3bb62e56c3))
* add sidebar-contents template part and use it for single sidebar layout ([#276](https://github.com/Automattic/newspack-block-theme/issues/276)) ([488e43c](https://github.com/Automattic/newspack-block-theme/commit/488e43c3280ce58e8be69d3157188a2098ccadf2))
* add support for yoast blocks (faq, how-to) ([#241](https://github.com/Automattic/newspack-block-theme/issues/241)) ([d63260a](https://github.com/Automattic/newspack-block-theme/commit/d63260a45bf08624e624628e3ee6d260c5fb3a2a))
* add support for yoast breadcrumbs; move cap styles to subdirectory ([#239](https://github.com/Automattic/newspack-block-theme/issues/239)) ([463584e](https://github.com/Automattic/newspack-block-theme/commit/463584e0af35f074fe614ef3bd4749ef867d5c76))
* **author-list:** add block styles ([#250](https://github.com/Automattic/newspack-block-theme/issues/250)) ([7acd4a3](https://github.com/Automattic/newspack-block-theme/commit/7acd4a36c7a05cc1c471d87a4a117c35a99f23cb))
* **breadcrumbs:** ensure text and link colors are supported ([#240](https://github.com/Automattic/newspack-block-theme/issues/240)) ([fb3e939](https://github.com/Automattic/newspack-block-theme/commit/fb3e93921a67b99720d6ae0533d7d850f259f65c))
* **button:** x-small size adjust min-height and border-radius for consistency with newspack-icon ([#271](https://github.com/Automattic/newspack-block-theme/issues/271)) ([5fa1f7c](https://github.com/Automattic/newspack-block-theme/commit/5fa1f7c2b796356e1e9292dca629ffb908803b43))
* **footer:** add new pattern and adjust style 4 ([#269](https://github.com/Automattic/newspack-block-theme/issues/269)) ([9e826e9](https://github.com/Automattic/newspack-block-theme/commit/9e826e96f4acf11c07963003debee8e324f8f721))
* **footer:** update credits ([#278](https://github.com/Automattic/newspack-block-theme/issues/278)) ([cec87b1](https://github.com/Automattic/newspack-block-theme/commit/cec87b18fbe31fe10389ed28955b26bd8973aa29))
* **homepage-articles:** add transition to links ([#234](https://github.com/Automattic/newspack-block-theme/issues/234)) ([bdf924a](https://github.com/Automattic/newspack-block-theme/commit/bdf924af945517501c03bb5871033b88f5097ce0))
* **navigation:** update hover style; remove underline and use color-mix instead ([#247](https://github.com/Automattic/newspack-block-theme/issues/247)) ([08994a3](https://github.com/Automattic/newspack-block-theme/commit/08994a3daf634c7c673543c8ec5b6c123cc94396))
* **overlay:** update close button to 64px tall/wide ([#233](https://github.com/Automattic/newspack-block-theme/issues/233)) ([39e9dad](https://github.com/Automattic/newspack-block-theme/commit/39e9dad09d9f65824fb4895ce7c13581b44fe17a))
* **patterns:** add 1 new footer pattern ([#257](https://github.com/Automattic/newspack-block-theme/issues/257)) ([758e38c](https://github.com/Automattic/newspack-block-theme/commit/758e38c62c53ee531c0d5c3541251a4f626cbd94))
* **patterns:** improve columns preview; add 25/25/25/25 ([#275](https://github.com/Automattic/newspack-block-theme/issues/275)) ([0f5427f](https://github.com/Automattic/newspack-block-theme/commit/0f5427fb4230ec08bec07682a3d282c1d782caa3))
* **post-carousel:** update style ([#249](https://github.com/Automattic/newspack-block-theme/issues/249)) ([d87ac30](https://github.com/Automattic/newspack-block-theme/commit/d87ac3073c3d8fecb61cbf94252bdcdd4b03c82f))
* **post-meta:** update hover state ([#251](https://github.com/Automattic/newspack-block-theme/issues/251)) ([b1ba1d5](https://github.com/Automattic/newspack-block-theme/commit/b1ba1d56e41acf9263748bb9a8ad66ec7542580a))
* reorganise header parts ([#253](https://github.com/Automattic/newspack-block-theme/issues/253)) ([14ee203](https://github.com/Automattic/newspack-block-theme/commit/14ee20376225a7e3840906287014c894e9413fd8))
* **search:** update template part to match pagination with archive ([#279](https://github.com/Automattic/newspack-block-theme/issues/279)) ([6e3da20](https://github.com/Automattic/newspack-block-theme/commit/6e3da20874f92e3e59a451c7f38eedb80c7f053a))
* simplify home and index templates to match page and archive; remove archive pattern ([#277](https://github.com/Automattic/newspack-block-theme/issues/277)) ([5d09c60](https://github.com/Automattic/newspack-block-theme/commit/5d09c60145bf659446fd142bc6a3064fd6ec013c))
* update comments elements names ([#235](https://github.com/Automattic/newspack-block-theme/issues/235)) ([27e963e](https://github.com/Automattic/newspack-block-theme/commit/27e963eca74f5e2b51c6ff12becac42cb1ca2452))
* update mobile menu elements names ([#237](https://github.com/Automattic/newspack-block-theme/issues/237)) ([a1f5070](https://github.com/Automattic/newspack-block-theme/commit/a1f50701bb0df40b6a54805b37b4f9b4984ee497))
* update post header patterns; add wide option ([#267](https://github.com/Automattic/newspack-block-theme/issues/267)) ([1bd4d76](https://github.com/Automattic/newspack-block-theme/commit/1bd4d764ea32d9f15ec79aa5e68b078661618b41))
* update post header to use pattern; introduce sidebar post template ([#262](https://github.com/Automattic/newspack-block-theme/issues/262)) ([ff015a1](https://github.com/Automattic/newspack-block-theme/commit/ff015a1bac53f3cdefb3f83f69e612b6fae63cae))
* update post-content; adjust align left/right position ([#280](https://github.com/Automattic/newspack-block-theme/issues/280)) ([b1a5378](https://github.com/Automattic/newspack-block-theme/commit/b1a5378cd9dd5675e7da0675bfe2e2499a123253))
* update search elements names ([#236](https://github.com/Automattic/newspack-block-theme/issues/236)) ([0f926d2](https://github.com/Automattic/newspack-block-theme/commit/0f926d2b28a4a5b68b53b180e1c0fd0d52c78da3))

# [1.11.0](https://github.com/Automattic/newspack-block-theme/compare/v1.10.0...v1.11.0) (2024-07-15)


### Bug Fixes

* buttons without class; outline border-color ([#212](https://github.com/Automattic/newspack-block-theme/issues/212)) ([3f9342b](https://github.com/Automattic/newspack-block-theme/commit/3f9342b78ed381196b5068015177327105b20559))
* cover alignfull as last-child should not have margin with footer ([#218](https://github.com/Automattic/newspack-block-theme/issues/218)) ([dcd96a5](https://github.com/Automattic/newspack-block-theme/commit/dcd96a569ccab2d6c9391a739a043724acf0bb19))
* homepage articles row gap; use custom spacing ([#223](https://github.com/Automattic/newspack-block-theme/issues/223)) ([300920e](https://github.com/Automattic/newspack-block-theme/commit/300920e5a8ecbf259cb06cbd0d08d8cb8a24548e))
* variables typo ([#229](https://github.com/Automattic/newspack-block-theme/issues/229)) ([9bbd305](https://github.com/Automattic/newspack-block-theme/commit/9bbd3054f70498581cacdd7012bb92898760fb58))


### Features

* add author-profile styles ([#209](https://github.com/Automattic/newspack-block-theme/issues/209)) ([5d81cc1](https://github.com/Automattic/newspack-block-theme/commit/5d81cc11f23c647c5162890dac0828445cd4271d))
* add backward compatibility with classic theme ([#176](https://github.com/Automattic/newspack-block-theme/issues/176)) ([01b95f2](https://github.com/Automattic/newspack-block-theme/commit/01b95f2462fd0f9561d697bbdac9d57bbd5cba9c))
* add generic desktop-only and mobile-only classes ([#222](https://github.com/Automattic/newspack-block-theme/issues/222)) ([dc58d55](https://github.com/Automattic/newspack-block-theme/commit/dc58d5597c849161b1a3e8f119337b84ea6f44b2))
* add no-title page template; reduce gap with header when no-title ([#219](https://github.com/Automattic/newspack-block-theme/issues/219)) ([3824676](https://github.com/Automattic/newspack-block-theme/commit/3824676d6f225d2693545b1c02012374076d5dc0))
* **search-overlay:** update search input height ([#224](https://github.com/Automattic/newspack-block-theme/issues/224)) ([2cb32ad](https://github.com/Automattic/newspack-block-theme/commit/2cb32ad0f6ffab3a6567c57d7e8024a7196d78f5))
* tweak animations ([#231](https://github.com/Automattic/newspack-block-theme/issues/231)) ([d475cc3](https://github.com/Automattic/newspack-block-theme/commit/d475cc3c1dfc148b578fa2b113091e63e9431ecd))
* update desktop-header spacings ([#208](https://github.com/Automattic/newspack-block-theme/issues/208)) ([a7ba9b0](https://github.com/Automattic/newspack-block-theme/commit/a7ba9b0f355ab1c60396572517e8be0554255ac3))
* update header mobile and desktop spacing; introduce is-position ([#221](https://github.com/Automattic/newspack-block-theme/issues/221)) ([a92f8fa](https://github.com/Automattic/newspack-block-theme/commit/a92f8faf303705db0965978a0e96d2969bdb6d7c))
* update overlay ([#220](https://github.com/Automattic/newspack-block-theme/issues/220)) ([457347b](https://github.com/Automattic/newspack-block-theme/commit/457347b998dca352c8ca07346f499cfa7bf5cf50))
* update search overlay ([#215](https://github.com/Automattic/newspack-block-theme/issues/215)) ([27699f8](https://github.com/Automattic/newspack-block-theme/commit/27699f8e133c21bbc99297d68c5507d1016f618b))
* update sticky position to support mobile or desktop only ([#225](https://github.com/Automattic/newspack-block-theme/issues/225)) ([df1158e](https://github.com/Automattic/newspack-block-theme/commit/df1158e5be0d6d5cc9156af3572ca1937f04ab72))
* update various widths and spacings to use variables ([#227](https://github.com/Automattic/newspack-block-theme/issues/227)) ([00dcc02](https://github.com/Automattic/newspack-block-theme/commit/00dcc02375eb4a7c4688b5fcf0ce6638aa8c3a8d))
* use patterns instead of template parts for author-bio ([#210](https://github.com/Automattic/newspack-block-theme/issues/210)) ([147c315](https://github.com/Automattic/newspack-block-theme/commit/147c3156b613ec57a307430294de704870c0aab0))

# [1.11.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.10.0...v1.11.0-alpha.1) (2024-07-05)


### Bug Fixes

* buttons without class; outline border-color ([#212](https://github.com/Automattic/newspack-block-theme/issues/212)) ([3f9342b](https://github.com/Automattic/newspack-block-theme/commit/3f9342b78ed381196b5068015177327105b20559))
* cover alignfull as last-child should not have margin with footer ([#218](https://github.com/Automattic/newspack-block-theme/issues/218)) ([dcd96a5](https://github.com/Automattic/newspack-block-theme/commit/dcd96a569ccab2d6c9391a739a043724acf0bb19))
* homepage articles row gap; use custom spacing ([#223](https://github.com/Automattic/newspack-block-theme/issues/223)) ([300920e](https://github.com/Automattic/newspack-block-theme/commit/300920e5a8ecbf259cb06cbd0d08d8cb8a24548e))
* variables typo ([#229](https://github.com/Automattic/newspack-block-theme/issues/229)) ([9bbd305](https://github.com/Automattic/newspack-block-theme/commit/9bbd3054f70498581cacdd7012bb92898760fb58))


### Features

* add author-profile styles ([#209](https://github.com/Automattic/newspack-block-theme/issues/209)) ([5d81cc1](https://github.com/Automattic/newspack-block-theme/commit/5d81cc11f23c647c5162890dac0828445cd4271d))
* add backward compatibility with classic theme ([#176](https://github.com/Automattic/newspack-block-theme/issues/176)) ([01b95f2](https://github.com/Automattic/newspack-block-theme/commit/01b95f2462fd0f9561d697bbdac9d57bbd5cba9c))
* add generic desktop-only and mobile-only classes ([#222](https://github.com/Automattic/newspack-block-theme/issues/222)) ([dc58d55](https://github.com/Automattic/newspack-block-theme/commit/dc58d5597c849161b1a3e8f119337b84ea6f44b2))
* add no-title page template; reduce gap with header when no-title ([#219](https://github.com/Automattic/newspack-block-theme/issues/219)) ([3824676](https://github.com/Automattic/newspack-block-theme/commit/3824676d6f225d2693545b1c02012374076d5dc0))
* **search-overlay:** update search input height ([#224](https://github.com/Automattic/newspack-block-theme/issues/224)) ([2cb32ad](https://github.com/Automattic/newspack-block-theme/commit/2cb32ad0f6ffab3a6567c57d7e8024a7196d78f5))
* tweak animations ([#231](https://github.com/Automattic/newspack-block-theme/issues/231)) ([d475cc3](https://github.com/Automattic/newspack-block-theme/commit/d475cc3c1dfc148b578fa2b113091e63e9431ecd))
* update desktop-header spacings ([#208](https://github.com/Automattic/newspack-block-theme/issues/208)) ([a7ba9b0](https://github.com/Automattic/newspack-block-theme/commit/a7ba9b0f355ab1c60396572517e8be0554255ac3))
* update header mobile and desktop spacing; introduce is-position ([#221](https://github.com/Automattic/newspack-block-theme/issues/221)) ([a92f8fa](https://github.com/Automattic/newspack-block-theme/commit/a92f8faf303705db0965978a0e96d2969bdb6d7c))
* update overlay ([#220](https://github.com/Automattic/newspack-block-theme/issues/220)) ([457347b](https://github.com/Automattic/newspack-block-theme/commit/457347b998dca352c8ca07346f499cfa7bf5cf50))
* update search overlay ([#215](https://github.com/Automattic/newspack-block-theme/issues/215)) ([27699f8](https://github.com/Automattic/newspack-block-theme/commit/27699f8e133c21bbc99297d68c5507d1016f618b))
* update sticky position to support mobile or desktop only ([#225](https://github.com/Automattic/newspack-block-theme/issues/225)) ([df1158e](https://github.com/Automattic/newspack-block-theme/commit/df1158e5be0d6d5cc9156af3572ca1937f04ab72))
* update various widths and spacings to use variables ([#227](https://github.com/Automattic/newspack-block-theme/issues/227)) ([00dcc02](https://github.com/Automattic/newspack-block-theme/commit/00dcc02375eb4a7c4688b5fcf0ce6638aa8c3a8d))
* use patterns instead of template parts for author-bio ([#210](https://github.com/Automattic/newspack-block-theme/issues/210)) ([147c315](https://github.com/Automattic/newspack-block-theme/commit/147c3156b613ec57a307430294de704870c0aab0))

# [1.10.0](https://github.com/Automattic/newspack-block-theme/compare/v1.9.0...v1.10.0) (2024-07-01)


### Bug Fixes

* **button-block:** add border-style ([#199](https://github.com/Automattic/newspack-block-theme/issues/199)) ([7bec2be](https://github.com/Automattic/newspack-block-theme/commit/7bec2be737f0d6ad5b3e7fb00bf96fd22567db56))
* exclude styles for navigation submenu toggle ([#206](https://github.com/Automattic/newspack-block-theme/issues/206)) ([bdb5f6f](https://github.com/Automattic/newspack-block-theme/commit/bdb5f6fbae659e31b2161f34fe5900bbbaa6d825))
* **post-meta:** use block registry for conditional content ([#194](https://github.com/Automattic/newspack-block-theme/issues/194)) ([3e4c738](https://github.com/Automattic/newspack-block-theme/commit/3e4c73871ff99e79600217c457dfe85b5df4041d))
* root padding ([#178](https://github.com/Automattic/newspack-block-theme/issues/178)) ([ba76bf1](https://github.com/Automattic/newspack-block-theme/commit/ba76bf16ebd8c817301c2bbe549bdd8fdcf49037))
* update newspack-scripts to v5.5.1 ([72e9c48](https://github.com/Automattic/newspack-block-theme/commit/72e9c484fe6ecc60923843c82f7c99df4545f120))


### Features

*  add custom shadows ([#200](https://github.com/Automattic/newspack-block-theme/issues/200)) ([960080b](https://github.com/Automattic/newspack-block-theme/commit/960080b362dce4f46cae15ff202920142263a464))
* add blank with footer page template ([#172](https://github.com/Automattic/newspack-block-theme/issues/172)) ([ec1842b](https://github.com/Automattic/newspack-block-theme/commit/ec1842b8895f54d94a1b894672b5897ef4a0e232))
* add ghost-like button ([#197](https://github.com/Automattic/newspack-block-theme/issues/197)) ([2dc13c3](https://github.com/Automattic/newspack-block-theme/commit/2dc13c3e5c5aa95f5078931a265b10b81079cef9))
* add negative margin to last block in entry-content when fullwidth and background ([#204](https://github.com/Automattic/newspack-block-theme/issues/204)) ([1119cad](https://github.com/Automattic/newspack-block-theme/commit/1119cad86bcfb428abf51ab71d54980718aa415e))
* add post-content block styles ([#193](https://github.com/Automattic/newspack-block-theme/issues/193)) ([8bf31f5](https://github.com/Automattic/newspack-block-theme/commit/8bf31f5f0c8f6511e5e4bd9d88df4b28b3c47ce6))
* add single full width image template ([#195](https://github.com/Automattic/newspack-block-theme/issues/195)) ([39a8bde](https://github.com/Automattic/newspack-block-theme/commit/39a8bde4e11ee1a0e0e56b33810ef29722766573))
* add specific styles for the query block ([#184](https://github.com/Automattic/newspack-block-theme/issues/184)) ([61ea564](https://github.com/Automattic/newspack-block-theme/commit/61ea564b8d2ca1cd47ea5b2ccb0be0810099310b))
* add styles for some specific blocks (audio, verse, jp image compare) ([b20c33c](https://github.com/Automattic/newspack-block-theme/commit/b20c33ccd843af157adc107eeee455d7a1edfd14))
* adjust columns block and pagination ([#182](https://github.com/Automattic/newspack-block-theme/issues/182)) ([88d46d0](https://github.com/Automattic/newspack-block-theme/commit/88d46d01243f4a94f3ac6c5e6d38077802c57528))
* **column-block:** add rounded block style ([#170](https://github.com/Automattic/newspack-block-theme/issues/170)) ([9ac025f](https://github.com/Automattic/newspack-block-theme/commit/9ac025fd293050fda00bd2f3a46fd4c588a145fa))
* **columns:** force flex-basis when using newspack-grid and a 25/50/25 layout ([#191](https://github.com/Automattic/newspack-block-theme/issues/191)) ([b5c00f7](https://github.com/Automattic/newspack-block-theme/commit/b5c00f7c8f04d034f350170cbfe892790eb25eee))
* **list-block:** add checked block style ([#171](https://github.com/Automattic/newspack-block-theme/issues/171)) ([309dd6d](https://github.com/Automattic/newspack-block-theme/commit/309dd6d26023c1ffaf16093183570eae8bb6db2e))
* make sure newspack ui buttons are not overridden ([#205](https://github.com/Automattic/newspack-block-theme/issues/205)) ([adef881](https://github.com/Automattic/newspack-block-theme/commit/adef881e14b02543e141eab476c800a35bc43d35))
* move all block styles to JS ([#185](https://github.com/Automattic/newspack-block-theme/issues/185)) ([217be19](https://github.com/Automattic/newspack-block-theme/commit/217be197344bdffa5b94eb5e5e2a29e60733d1da))
* refactor query pagination; remove alignwide ([#196](https://github.com/Automattic/newspack-block-theme/issues/196)) ([17b3075](https://github.com/Automattic/newspack-block-theme/commit/17b3075be6038dd762d7a195fe46f83bc782b64a))
* remove spacers ([#179](https://github.com/Automattic/newspack-block-theme/issues/179)) ([bda489d](https://github.com/Automattic/newspack-block-theme/commit/bda489d1ad43e374034f6722a8d7e7476a8d2de7))
* update archive templates ([#183](https://github.com/Automattic/newspack-block-theme/issues/183)) ([af362ab](https://github.com/Automattic/newspack-block-theme/commit/af362abffd9736d01c769568ba2023a5b352b32e))
* update columns with borders style ([#187](https://github.com/Automattic/newspack-block-theme/issues/187)) ([0d91907](https://github.com/Automattic/newspack-block-theme/commit/0d91907cf9c58d4382b29faae8169d993ee01316))
* update custom shadows ([#201](https://github.com/Automattic/newspack-block-theme/issues/201)) ([e29a257](https://github.com/Automattic/newspack-block-theme/commit/e29a25743b39e038132c71aeeec3349adbefb503))
* update fonts from system to inter and from monospace to jetbrainsmono ([#190](https://github.com/Automattic/newspack-block-theme/issues/190)) ([a429c8d](https://github.com/Automattic/newspack-block-theme/commit/a429c8d054992926539e33410bd62c770914d532))
* update form elements ([#203](https://github.com/Automattic/newspack-block-theme/issues/203)) ([7395065](https://github.com/Automattic/newspack-block-theme/commit/73950659ff6b21859d187ae0071373ffbe2b44d0))
* update separator block; improve styles ([#180](https://github.com/Automattic/newspack-block-theme/issues/180)) ([b335e79](https://github.com/Automattic/newspack-block-theme/commit/b335e79b881295a4485595d4a667e5e48fc0c6e3))
* update single template ([#192](https://github.com/Automattic/newspack-block-theme/issues/192)) ([29fb0ca](https://github.com/Automattic/newspack-block-theme/commit/29fb0ca1b71e2ff3b217bd8a5718b549c6916adf))
* update theme description; add baseline utility class ([#198](https://github.com/Automattic/newspack-block-theme/issues/198)) ([81eb790](https://github.com/Automattic/newspack-block-theme/commit/81eb790a3daa796f6a49beb3f9dff8b67bd250ac))

# [1.10.0-alpha.3](https://github.com/Automattic/newspack-block-theme/compare/v1.10.0-alpha.2...v1.10.0-alpha.3) (2024-06-25)


### Bug Fixes

* exclude styles for navigation submenu toggle ([#206](https://github.com/Automattic/newspack-block-theme/issues/206)) ([bdb5f6f](https://github.com/Automattic/newspack-block-theme/commit/bdb5f6fbae659e31b2161f34fe5900bbbaa6d825))


### Features

* add negative margin to last block in entry-content when fullwidth and background ([#204](https://github.com/Automattic/newspack-block-theme/issues/204)) ([1119cad](https://github.com/Automattic/newspack-block-theme/commit/1119cad86bcfb428abf51ab71d54980718aa415e))
* make sure newspack ui buttons are not overridden ([#205](https://github.com/Automattic/newspack-block-theme/issues/205)) ([adef881](https://github.com/Automattic/newspack-block-theme/commit/adef881e14b02543e141eab476c800a35bc43d35))
* update form elements ([#203](https://github.com/Automattic/newspack-block-theme/issues/203)) ([7395065](https://github.com/Automattic/newspack-block-theme/commit/73950659ff6b21859d187ae0071373ffbe2b44d0))

# [1.10.0-alpha.2](https://github.com/Automattic/newspack-block-theme/compare/v1.10.0-alpha.1...v1.10.0-alpha.2) (2024-06-25)


### Bug Fixes

* update newspack-scripts to v5.5.1 ([72e9c48](https://github.com/Automattic/newspack-block-theme/commit/72e9c484fe6ecc60923843c82f7c99df4545f120))

# [1.10.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.9.0...v1.10.0-alpha.1) (2024-06-24)


### Bug Fixes

* **button-block:** add border-style ([#199](https://github.com/Automattic/newspack-block-theme/issues/199)) ([7bec2be](https://github.com/Automattic/newspack-block-theme/commit/7bec2be737f0d6ad5b3e7fb00bf96fd22567db56))
* **post-meta:** use block registry for conditional content ([#194](https://github.com/Automattic/newspack-block-theme/issues/194)) ([3e4c738](https://github.com/Automattic/newspack-block-theme/commit/3e4c73871ff99e79600217c457dfe85b5df4041d))
* root padding ([#178](https://github.com/Automattic/newspack-block-theme/issues/178)) ([ba76bf1](https://github.com/Automattic/newspack-block-theme/commit/ba76bf16ebd8c817301c2bbe549bdd8fdcf49037))


### Features

*  add custom shadows ([#200](https://github.com/Automattic/newspack-block-theme/issues/200)) ([960080b](https://github.com/Automattic/newspack-block-theme/commit/960080b362dce4f46cae15ff202920142263a464))
* add blank with footer page template ([#172](https://github.com/Automattic/newspack-block-theme/issues/172)) ([ec1842b](https://github.com/Automattic/newspack-block-theme/commit/ec1842b8895f54d94a1b894672b5897ef4a0e232))
* add ghost-like button ([#197](https://github.com/Automattic/newspack-block-theme/issues/197)) ([2dc13c3](https://github.com/Automattic/newspack-block-theme/commit/2dc13c3e5c5aa95f5078931a265b10b81079cef9))
* add post-content block styles ([#193](https://github.com/Automattic/newspack-block-theme/issues/193)) ([8bf31f5](https://github.com/Automattic/newspack-block-theme/commit/8bf31f5f0c8f6511e5e4bd9d88df4b28b3c47ce6))
* add single full width image template ([#195](https://github.com/Automattic/newspack-block-theme/issues/195)) ([39a8bde](https://github.com/Automattic/newspack-block-theme/commit/39a8bde4e11ee1a0e0e56b33810ef29722766573))
* add specific styles for the query block ([#184](https://github.com/Automattic/newspack-block-theme/issues/184)) ([61ea564](https://github.com/Automattic/newspack-block-theme/commit/61ea564b8d2ca1cd47ea5b2ccb0be0810099310b))
* add styles for some specific blocks (audio, verse, jp image compare) ([b20c33c](https://github.com/Automattic/newspack-block-theme/commit/b20c33ccd843af157adc107eeee455d7a1edfd14))
* adjust columns block and pagination ([#182](https://github.com/Automattic/newspack-block-theme/issues/182)) ([88d46d0](https://github.com/Automattic/newspack-block-theme/commit/88d46d01243f4a94f3ac6c5e6d38077802c57528))
* **column-block:** add rounded block style ([#170](https://github.com/Automattic/newspack-block-theme/issues/170)) ([9ac025f](https://github.com/Automattic/newspack-block-theme/commit/9ac025fd293050fda00bd2f3a46fd4c588a145fa))
* **columns:** force flex-basis when using newspack-grid and a 25/50/25 layout ([#191](https://github.com/Automattic/newspack-block-theme/issues/191)) ([b5c00f7](https://github.com/Automattic/newspack-block-theme/commit/b5c00f7c8f04d034f350170cbfe892790eb25eee))
* **list-block:** add checked block style ([#171](https://github.com/Automattic/newspack-block-theme/issues/171)) ([309dd6d](https://github.com/Automattic/newspack-block-theme/commit/309dd6d26023c1ffaf16093183570eae8bb6db2e))
* move all block styles to JS ([#185](https://github.com/Automattic/newspack-block-theme/issues/185)) ([217be19](https://github.com/Automattic/newspack-block-theme/commit/217be197344bdffa5b94eb5e5e2a29e60733d1da))
* refactor query pagination; remove alignwide ([#196](https://github.com/Automattic/newspack-block-theme/issues/196)) ([17b3075](https://github.com/Automattic/newspack-block-theme/commit/17b3075be6038dd762d7a195fe46f83bc782b64a))
* remove spacers ([#179](https://github.com/Automattic/newspack-block-theme/issues/179)) ([bda489d](https://github.com/Automattic/newspack-block-theme/commit/bda489d1ad43e374034f6722a8d7e7476a8d2de7))
* update archive templates ([#183](https://github.com/Automattic/newspack-block-theme/issues/183)) ([af362ab](https://github.com/Automattic/newspack-block-theme/commit/af362abffd9736d01c769568ba2023a5b352b32e))
* update columns with borders style ([#187](https://github.com/Automattic/newspack-block-theme/issues/187)) ([0d91907](https://github.com/Automattic/newspack-block-theme/commit/0d91907cf9c58d4382b29faae8169d993ee01316))
* update custom shadows ([#201](https://github.com/Automattic/newspack-block-theme/issues/201)) ([e29a257](https://github.com/Automattic/newspack-block-theme/commit/e29a25743b39e038132c71aeeec3349adbefb503))
* update fonts from system to inter and from monospace to jetbrainsmono ([#190](https://github.com/Automattic/newspack-block-theme/issues/190)) ([a429c8d](https://github.com/Automattic/newspack-block-theme/commit/a429c8d054992926539e33410bd62c770914d532))
* update separator block; improve styles ([#180](https://github.com/Automattic/newspack-block-theme/issues/180)) ([b335e79](https://github.com/Automattic/newspack-block-theme/commit/b335e79b881295a4485595d4a667e5e48fc0c6e3))
* update single template ([#192](https://github.com/Automattic/newspack-block-theme/issues/192)) ([29fb0ca](https://github.com/Automattic/newspack-block-theme/commit/29fb0ca1b71e2ff3b217bd8a5718b549c6916adf))
* update theme description; add baseline utility class ([#198](https://github.com/Automattic/newspack-block-theme/issues/198)) ([81eb790](https://github.com/Automattic/newspack-block-theme/commit/81eb790a3daa796f6a49beb3f9dff8b67bd250ac))

# [1.9.0](https://github.com/Automattic/newspack-block-theme/compare/v1.8.0...v1.9.0) (2024-06-12)


### Bug Fixes

* disqus captcha cropping ([#163](https://github.com/Automattic/newspack-block-theme/issues/163)) ([93d6d06](https://github.com/Automattic/newspack-block-theme/commit/93d6d066c6e2371dc1460861b2697bbc95417741))


### Features

* add basic styles for input submit ([#157](https://github.com/Automattic/newspack-block-theme/issues/157)) ([df2ace1](https://github.com/Automattic/newspack-block-theme/commit/df2ace1317b354d0c493cc8969eb53f7e3df2fe7))
* add blank page template ([#158](https://github.com/Automattic/newspack-block-theme/issues/158)) ([b56d2b6](https://github.com/Automattic/newspack-block-theme/commit/b56d2b65b1d77b0c90f826c27fb79235a06576a6))
* add jetpack compatibility file ([#160](https://github.com/Automattic/newspack-block-theme/issues/160)) ([dce13dd](https://github.com/Automattic/newspack-block-theme/commit/dce13dd29dfa798acad232b01d76970ffa76da93))
* add post-meta pattern ([#148](https://github.com/Automattic/newspack-block-theme/issues/148)) ([67d3860](https://github.com/Automattic/newspack-block-theme/commit/67d38600bfa128707c060ef510ce39271542a1d0))
* add specific styles for media & text block ([#154](https://github.com/Automattic/newspack-block-theme/issues/154)) ([99e77c9](https://github.com/Automattic/newspack-block-theme/commit/99e77c90226b26d86ef57d98185c9c859e5e0d84))
* add support for jetpack top posts ([#161](https://github.com/Automattic/newspack-block-theme/issues/161)) ([492538b](https://github.com/Automattic/newspack-block-theme/commit/492538b0ef5b30b7dde6f0c4a8b04359faa9a08d))
* add support for table block ([#166](https://github.com/Automattic/newspack-block-theme/issues/166)) ([1b3d85f](https://github.com/Automattic/newspack-block-theme/commit/1b3d85f2d60b3c6c8194f5eefdfb5ab240c577b8))
* **post-comments:** improve style for pingback and trackbacks ([#162](https://github.com/Automattic/newspack-block-theme/issues/162)) ([1f7cc01](https://github.com/Automattic/newspack-block-theme/commit/1f7cc012c5d26a6ea4b53e4f5e06524ef1c2cc21))

# [1.9.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.8.0...v1.9.0-alpha.1) (2024-05-31)


### Bug Fixes

* disqus captcha cropping ([#163](https://github.com/Automattic/newspack-block-theme/issues/163)) ([93d6d06](https://github.com/Automattic/newspack-block-theme/commit/93d6d066c6e2371dc1460861b2697bbc95417741))


### Features

* add basic styles for input submit ([#157](https://github.com/Automattic/newspack-block-theme/issues/157)) ([df2ace1](https://github.com/Automattic/newspack-block-theme/commit/df2ace1317b354d0c493cc8969eb53f7e3df2fe7))
* add blank page template ([#158](https://github.com/Automattic/newspack-block-theme/issues/158)) ([b56d2b6](https://github.com/Automattic/newspack-block-theme/commit/b56d2b65b1d77b0c90f826c27fb79235a06576a6))
* add jetpack compatibility file ([#160](https://github.com/Automattic/newspack-block-theme/issues/160)) ([dce13dd](https://github.com/Automattic/newspack-block-theme/commit/dce13dd29dfa798acad232b01d76970ffa76da93))
* add post-meta pattern ([#148](https://github.com/Automattic/newspack-block-theme/issues/148)) ([67d3860](https://github.com/Automattic/newspack-block-theme/commit/67d38600bfa128707c060ef510ce39271542a1d0))
* add specific styles for media & text block ([#154](https://github.com/Automattic/newspack-block-theme/issues/154)) ([99e77c9](https://github.com/Automattic/newspack-block-theme/commit/99e77c90226b26d86ef57d98185c9c859e5e0d84))
* add support for jetpack top posts ([#161](https://github.com/Automattic/newspack-block-theme/issues/161)) ([492538b](https://github.com/Automattic/newspack-block-theme/commit/492538b0ef5b30b7dde6f0c4a8b04359faa9a08d))
* add support for table block ([#166](https://github.com/Automattic/newspack-block-theme/issues/166)) ([1b3d85f](https://github.com/Automattic/newspack-block-theme/commit/1b3d85f2d60b3c6c8194f5eefdfb5ab240c577b8))
* **post-comments:** improve style for pingback and trackbacks ([#162](https://github.com/Automattic/newspack-block-theme/issues/162)) ([1f7cc01](https://github.com/Automattic/newspack-block-theme/commit/1f7cc012c5d26a6ea4b53e4f5e06524ef1c2cc21))

# [1.8.0](https://github.com/Automattic/newspack-block-theme/compare/v1.7.0...v1.8.0) (2024-05-28)


### Bug Fixes

* correct annoying search preview issue ([#147](https://github.com/Automattic/newspack-block-theme/issues/147)) ([7dde399](https://github.com/Automattic/newspack-block-theme/commit/7dde399d98aa05eee770e75c71c12b6f1bb0611d))


### Features

* add comments sidebar ([#135](https://github.com/Automattic/newspack-block-theme/issues/135)) ([5ffcd15](https://github.com/Automattic/newspack-block-theme/commit/5ffcd155b89346e61478198df3ee085a08e70a23))
* add support for co-author plus blocks ([#139](https://github.com/Automattic/newspack-block-theme/issues/139)) ([14e2893](https://github.com/Automattic/newspack-block-theme/commit/14e2893870daded17d5ee626aa1c260d3369e0b4))
* update search templates ([#137](https://github.com/Automattic/newspack-block-theme/issues/137)) ([5e89534](https://github.com/Automattic/newspack-block-theme/commit/5e89534b21517d8cbc59575eb05ce4494c7d0ffe))

# [1.8.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.7.0...v1.8.0-alpha.1) (2024-05-20)


### Bug Fixes

* correct annoying search preview issue ([#147](https://github.com/Automattic/newspack-block-theme/issues/147)) ([7dde399](https://github.com/Automattic/newspack-block-theme/commit/7dde399d98aa05eee770e75c71c12b6f1bb0611d))


### Features

* add comments sidebar ([#135](https://github.com/Automattic/newspack-block-theme/issues/135)) ([5ffcd15](https://github.com/Automattic/newspack-block-theme/commit/5ffcd155b89346e61478198df3ee085a08e70a23))
* add support for co-author plus blocks ([#139](https://github.com/Automattic/newspack-block-theme/issues/139)) ([14e2893](https://github.com/Automattic/newspack-block-theme/commit/14e2893870daded17d5ee626aa1c260d3369e0b4))
* update search templates ([#137](https://github.com/Automattic/newspack-block-theme/issues/137)) ([5e89534](https://github.com/Automattic/newspack-block-theme/commit/5e89534b21517d8cbc59575eb05ce4494c7d0ffe))

# [1.7.0](https://github.com/Automattic/newspack-block-theme/compare/v1.6.0...v1.7.0) (2024-05-15)


### Features

* homepage articles styles ([#118](https://github.com/Automattic/newspack-block-theme/issues/118)) ([532ad6d](https://github.com/Automattic/newspack-block-theme/commit/532ad6d88176f680ee77ac64f2565a53216627ec))
* update button styles ([#134](https://github.com/Automattic/newspack-block-theme/issues/134)) ([d888783](https://github.com/Automattic/newspack-block-theme/commit/d88878355665016778a5db1f98406b3d4f46151f))
* update input to match newspack components ([#133](https://github.com/Automattic/newspack-block-theme/issues/133)) ([094cf6f](https://github.com/Automattic/newspack-block-theme/commit/094cf6fcecd2e53584bbd2f44d65024e4a960ea8))

# [1.7.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.6.0...v1.7.0-alpha.1) (2024-04-25)


### Features

* homepage articles styles ([#118](https://github.com/Automattic/newspack-block-theme/issues/118)) ([532ad6d](https://github.com/Automattic/newspack-block-theme/commit/532ad6d88176f680ee77ac64f2565a53216627ec))
* update button styles ([#134](https://github.com/Automattic/newspack-block-theme/issues/134)) ([d888783](https://github.com/Automattic/newspack-block-theme/commit/d88878355665016778a5db1f98406b3d4f46151f))
* update input to match newspack components ([#133](https://github.com/Automattic/newspack-block-theme/issues/133)) ([094cf6f](https://github.com/Automattic/newspack-block-theme/commit/094cf6fcecd2e53584bbd2f44d65024e4a960ea8))

# [1.6.0](https://github.com/Automattic/newspack-block-theme/compare/v1.5.0...v1.6.0) (2024-02-20)


### Features

* add underline to navigation styles ([#112](https://github.com/Automattic/newspack-block-theme/issues/112)) ([d52e92d](https://github.com/Automattic/newspack-block-theme/commit/d52e92d52b1ce31ce1b315c8c2c6854b6fedb0df))
* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#119](https://github.com/Automattic/newspack-block-theme/issues/119)) ([b27b7d7](https://github.com/Automattic/newspack-block-theme/commit/b27b7d703ecad7181a3d8fb9b70fa25ad38d29d9))
* rename colour variables ([#117](https://github.com/Automattic/newspack-block-theme/issues/117)) ([d263ccd](https://github.com/Automattic/newspack-block-theme/commit/d263ccd5756a576964a373fe123f3eda91b95c0b))

# [1.6.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.5.0...v1.6.0-alpha.1) (2024-02-08)


### Features

* add underline to navigation styles ([#112](https://github.com/Automattic/newspack-block-theme/issues/112)) ([d52e92d](https://github.com/Automattic/newspack-block-theme/commit/d52e92d52b1ce31ce1b315c8c2c6854b6fedb0df))
* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#119](https://github.com/Automattic/newspack-block-theme/issues/119)) ([b27b7d7](https://github.com/Automattic/newspack-block-theme/commit/b27b7d703ecad7181a3d8fb9b70fa25ad38d29d9))
* rename colour variables ([#117](https://github.com/Automattic/newspack-block-theme/issues/117)) ([d263ccd](https://github.com/Automattic/newspack-block-theme/commit/d263ccd5756a576964a373fe123f3eda91b95c0b))

# [1.5.0](https://github.com/Automattic/newspack-block-theme/compare/v1.4.0...v1.5.0) (2024-02-06)


### Bug Fixes

* parenthesis ([a0c485f](https://github.com/Automattic/newspack-block-theme/commit/a0c485f30cd39286d2fd897620e8880b61dee409))
* remove link from post date archive ([#90](https://github.com/Automattic/newspack-block-theme/issues/90)) ([d08ba5e](https://github.com/Automattic/newspack-block-theme/commit/d08ba5efb043a326dd1fdb9899c847628acac957))


### Features

* add 2x-small font size ([#102](https://github.com/Automattic/newspack-block-theme/issues/102)) ([cb17fdc](https://github.com/Automattic/newspack-block-theme/commit/cb17fdcfbd988e04105e69615ec7b6f73f1b9abb))
* add new "Paul" style ([#88](https://github.com/Automattic/newspack-block-theme/issues/88)) ([5605dab](https://github.com/Automattic/newspack-block-theme/commit/5605dabfe6d27e434c2ba124c14638db07becd63))
* update archive template ([#89](https://github.com/Automattic/newspack-block-theme/issues/89)) ([3c8556d](https://github.com/Automattic/newspack-block-theme/commit/3c8556daa85b60ccbab6ac58df5d9a3513eb90e8))
* update button block ([#91](https://github.com/Automattic/newspack-block-theme/issues/91)) ([b66f75a](https://github.com/Automattic/newspack-block-theme/commit/b66f75a219fdab176a1191281e361e9e7abec5a6))
* update button style ([#103](https://github.com/Automattic/newspack-block-theme/issues/103)) ([4b686f9](https://github.com/Automattic/newspack-block-theme/commit/4b686f9b605591307f15257e89ca75cab8d24bc0))
* update desktop header template part ([#106](https://github.com/Automattic/newspack-block-theme/issues/106)) ([b5d45ba](https://github.com/Automattic/newspack-block-theme/commit/b5d45ba3f25fb94967d475fcc98cdc7cdeba0f04))
* update footer part and lock blocks ([#105](https://github.com/Automattic/newspack-block-theme/issues/105)) ([0490354](https://github.com/Automattic/newspack-block-theme/commit/0490354f93f0fbc253bee85dde36e803acb332c7))
* update grid and typography ([64c70d7](https://github.com/Automattic/newspack-block-theme/commit/64c70d7cb4c2c88a82e01a2f99a9091a278042a5))
* update grid and typography ([f5073ef](https://github.com/Automattic/newspack-block-theme/commit/f5073eff3068f8e37796f2ac5edaefc69f244094))
* update svg icons and search overlay ([#111](https://github.com/Automattic/newspack-block-theme/issues/111)) ([9ee5b6a](https://github.com/Automattic/newspack-block-theme/commit/9ee5b6af172f0e9f7fc4e14351590fbdcac86a8b))
* update the query pagination block ([#93](https://github.com/Automattic/newspack-block-theme/issues/93)) ([03e7fb6](https://github.com/Automattic/newspack-block-theme/commit/03e7fb64ccdab7ab7934a6f61ec4136618c71d9b))

# [1.5.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.4.0...v1.5.0-alpha.1) (2024-01-26)


### Bug Fixes

* parenthesis ([a0c485f](https://github.com/Automattic/newspack-block-theme/commit/a0c485f30cd39286d2fd897620e8880b61dee409))
* remove link from post date archive ([#90](https://github.com/Automattic/newspack-block-theme/issues/90)) ([d08ba5e](https://github.com/Automattic/newspack-block-theme/commit/d08ba5efb043a326dd1fdb9899c847628acac957))


### Features

* add 2x-small font size ([#102](https://github.com/Automattic/newspack-block-theme/issues/102)) ([cb17fdc](https://github.com/Automattic/newspack-block-theme/commit/cb17fdcfbd988e04105e69615ec7b6f73f1b9abb))
* add new "Paul" style ([#88](https://github.com/Automattic/newspack-block-theme/issues/88)) ([5605dab](https://github.com/Automattic/newspack-block-theme/commit/5605dabfe6d27e434c2ba124c14638db07becd63))
* update archive template ([#89](https://github.com/Automattic/newspack-block-theme/issues/89)) ([3c8556d](https://github.com/Automattic/newspack-block-theme/commit/3c8556daa85b60ccbab6ac58df5d9a3513eb90e8))
* update button block ([#91](https://github.com/Automattic/newspack-block-theme/issues/91)) ([b66f75a](https://github.com/Automattic/newspack-block-theme/commit/b66f75a219fdab176a1191281e361e9e7abec5a6))
* update button style ([#103](https://github.com/Automattic/newspack-block-theme/issues/103)) ([4b686f9](https://github.com/Automattic/newspack-block-theme/commit/4b686f9b605591307f15257e89ca75cab8d24bc0))
* update desktop header template part ([#106](https://github.com/Automattic/newspack-block-theme/issues/106)) ([b5d45ba](https://github.com/Automattic/newspack-block-theme/commit/b5d45ba3f25fb94967d475fcc98cdc7cdeba0f04))
* update footer part and lock blocks ([#105](https://github.com/Automattic/newspack-block-theme/issues/105)) ([0490354](https://github.com/Automattic/newspack-block-theme/commit/0490354f93f0fbc253bee85dde36e803acb332c7))
* update grid and typography ([64c70d7](https://github.com/Automattic/newspack-block-theme/commit/64c70d7cb4c2c88a82e01a2f99a9091a278042a5))
* update grid and typography ([f5073ef](https://github.com/Automattic/newspack-block-theme/commit/f5073eff3068f8e37796f2ac5edaefc69f244094))
* update svg icons and search overlay ([#111](https://github.com/Automattic/newspack-block-theme/issues/111)) ([9ee5b6a](https://github.com/Automattic/newspack-block-theme/commit/9ee5b6af172f0e9f7fc4e14351590fbdcac86a8b))
* update the query pagination block ([#93](https://github.com/Automattic/newspack-block-theme/issues/93)) ([03e7fb6](https://github.com/Automattic/newspack-block-theme/commit/03e7fb64ccdab7ab7934a6f61ec4136618c71d9b))

# [1.4.0](https://github.com/Automattic/newspack-block-theme/compare/v1.3.0...v1.4.0) (2024-01-08)


### Features

* add archive layout through pattern ([#55](https://github.com/Automattic/newspack-block-theme/issues/55)) ([8e6d912](https://github.com/Automattic/newspack-block-theme/commit/8e6d91292f8705822198d7a9d09693bc30789e47))

# [1.4.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.3.0...v1.4.0-alpha.1) (2023-09-15)


### Features

* add archive layout through pattern ([#55](https://github.com/Automattic/newspack-block-theme/issues/55)) ([8e6d912](https://github.com/Automattic/newspack-block-theme/commit/8e6d91292f8705822198d7a9d09693bc30789e47))

# [1.3.0](https://github.com/Automattic/newspack-block-theme/compare/v1.2.2...v1.3.0) (2023-07-17)


### Bug Fixes

* add bottom space to post title in editor ([#60](https://github.com/Automattic/newspack-block-theme/issues/60)) ([ca6c9a1](https://github.com/Automattic/newspack-block-theme/commit/ca6c9a1f1494d0df9bc5535ebc0444ef2e92a7d3))
* correct single post space; add tag styles ([#59](https://github.com/Automattic/newspack-block-theme/issues/59)) ([f3645d0](https://github.com/Automattic/newspack-block-theme/commit/f3645d07809099400dea86937299529cf7b28566))
* correct spacing values ([#46](https://github.com/Automattic/newspack-block-theme/issues/46)) ([12cf2b4](https://github.com/Automattic/newspack-block-theme/commit/12cf2b4209239d73c4bf68a346809933dfc2f051))
* hide author, date for pages in search ([#53](https://github.com/Automattic/newspack-block-theme/issues/53)) ([52b7a7f](https://github.com/Automattic/newspack-block-theme/commit/52b7a7f747a6e62bc30e5faeab1045d00edf3b62))
* make archive spacing more consistent ([#56](https://github.com/Automattic/newspack-block-theme/issues/56)) ([041ec88](https://github.com/Automattic/newspack-block-theme/commit/041ec88f711212e6df7b2e9c598644e50d1ce10a))
* make navigation block's overlayMenu default never ([#58](https://github.com/Automattic/newspack-block-theme/issues/58)) ([8fa02a6](https://github.com/Automattic/newspack-block-theme/commit/8fa02a645382c3b2777a223aee59aed1d4a8a576))
* make sure wide/full blocks can flow outside of the content ([#66](https://github.com/Automattic/newspack-block-theme/issues/66)) ([2e282b7](https://github.com/Automattic/newspack-block-theme/commit/2e282b7945d42a5bbb75d62eb97a4639266e37df))
* remove IDs from navigation blocks ([#50](https://github.com/Automattic/newspack-block-theme/issues/50)) ([1f140be](https://github.com/Automattic/newspack-block-theme/commit/1f140be93b6e315e879a78ff17f0d2a46e69e2e9))


### Features

* add a clamp to the larger spacing sizes ([#47](https://github.com/Automattic/newspack-block-theme/issues/47)) ([d3a01f4](https://github.com/Automattic/newspack-block-theme/commit/d3a01f45acc455d795c12a8172d127164b453ae0))
* add large image single post variation ([#54](https://github.com/Automattic/newspack-block-theme/issues/54)) ([838128a](https://github.com/Automattic/newspack-block-theme/commit/838128af2823b24ae5b5f212b65b08743e4d1ae0))
* add search toggle template parts ([#51](https://github.com/Automattic/newspack-block-theme/issues/51)) ([ad4e0a7](https://github.com/Automattic/newspack-block-theme/commit/ad4e0a7fed46fa42e8087b747d0ed438c3067f31))
* change search block's default settings ([#44](https://github.com/Automattic/newspack-block-theme/issues/44)) ([1487a69](https://github.com/Automattic/newspack-block-theme/commit/1487a698b1d0924a762092e9523cf178692d830c))
* update page, homepage templates to remove unneeded elements ([#49](https://github.com/Automattic/newspack-block-theme/issues/49)) ([2b888cd](https://github.com/Automattic/newspack-block-theme/commit/2b888cd83087348e590cbe42c506a28d53bb2128))
* update search block styles ([#52](https://github.com/Automattic/newspack-block-theme/issues/52)) ([6ed06fb](https://github.com/Automattic/newspack-block-theme/commit/6ed06fb18c7647c80d99a107ea32256c5118bd8d))
* update search styles ([#61](https://github.com/Automattic/newspack-block-theme/issues/61)) ([bf0d776](https://github.com/Automattic/newspack-block-theme/commit/bf0d776e2abdb7463e2d0251ec1822dfd422b19e))

# [1.3.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.2.2...v1.3.0-alpha.1) (2023-06-22)


### Bug Fixes

* add bottom space to post title in editor ([#60](https://github.com/Automattic/newspack-block-theme/issues/60)) ([ca6c9a1](https://github.com/Automattic/newspack-block-theme/commit/ca6c9a1f1494d0df9bc5535ebc0444ef2e92a7d3))
* correct single post space; add tag styles ([#59](https://github.com/Automattic/newspack-block-theme/issues/59)) ([f3645d0](https://github.com/Automattic/newspack-block-theme/commit/f3645d07809099400dea86937299529cf7b28566))
* correct spacing values ([#46](https://github.com/Automattic/newspack-block-theme/issues/46)) ([12cf2b4](https://github.com/Automattic/newspack-block-theme/commit/12cf2b4209239d73c4bf68a346809933dfc2f051))
* hide author, date for pages in search ([#53](https://github.com/Automattic/newspack-block-theme/issues/53)) ([52b7a7f](https://github.com/Automattic/newspack-block-theme/commit/52b7a7f747a6e62bc30e5faeab1045d00edf3b62))
* make archive spacing more consistent ([#56](https://github.com/Automattic/newspack-block-theme/issues/56)) ([041ec88](https://github.com/Automattic/newspack-block-theme/commit/041ec88f711212e6df7b2e9c598644e50d1ce10a))
* make navigation block's overlayMenu default never ([#58](https://github.com/Automattic/newspack-block-theme/issues/58)) ([8fa02a6](https://github.com/Automattic/newspack-block-theme/commit/8fa02a645382c3b2777a223aee59aed1d4a8a576))
* make sure wide/full blocks can flow outside of the content ([#66](https://github.com/Automattic/newspack-block-theme/issues/66)) ([2e282b7](https://github.com/Automattic/newspack-block-theme/commit/2e282b7945d42a5bbb75d62eb97a4639266e37df))
* remove IDs from navigation blocks ([#50](https://github.com/Automattic/newspack-block-theme/issues/50)) ([1f140be](https://github.com/Automattic/newspack-block-theme/commit/1f140be93b6e315e879a78ff17f0d2a46e69e2e9))


### Features

* add a clamp to the larger spacing sizes ([#47](https://github.com/Automattic/newspack-block-theme/issues/47)) ([d3a01f4](https://github.com/Automattic/newspack-block-theme/commit/d3a01f45acc455d795c12a8172d127164b453ae0))
* add large image single post variation ([#54](https://github.com/Automattic/newspack-block-theme/issues/54)) ([838128a](https://github.com/Automattic/newspack-block-theme/commit/838128af2823b24ae5b5f212b65b08743e4d1ae0))
* add search toggle template parts ([#51](https://github.com/Automattic/newspack-block-theme/issues/51)) ([ad4e0a7](https://github.com/Automattic/newspack-block-theme/commit/ad4e0a7fed46fa42e8087b747d0ed438c3067f31))
* change search block's default settings ([#44](https://github.com/Automattic/newspack-block-theme/issues/44)) ([1487a69](https://github.com/Automattic/newspack-block-theme/commit/1487a698b1d0924a762092e9523cf178692d830c))
* update page, homepage templates to remove unneeded elements ([#49](https://github.com/Automattic/newspack-block-theme/issues/49)) ([2b888cd](https://github.com/Automattic/newspack-block-theme/commit/2b888cd83087348e590cbe42c506a28d53bb2128))
* update search block styles ([#52](https://github.com/Automattic/newspack-block-theme/issues/52)) ([6ed06fb](https://github.com/Automattic/newspack-block-theme/commit/6ed06fb18c7647c80d99a107ea32256c5118bd8d))
* update search styles ([#61](https://github.com/Automattic/newspack-block-theme/issues/61)) ([bf0d776](https://github.com/Automattic/newspack-block-theme/commit/bf0d776e2abdb7463e2d0251ec1822dfd422b19e))

## [1.2.2](https://github.com/Automattic/newspack-block-theme/compare/v1.2.1...v1.2.2) (2023-06-20)


### Bug Fixes

* delete style.css again 2 ([6cbe462](https://github.com/Automattic/newspack-block-theme/commit/6cbe462197af363347cd9504e1a2a1b942ae3432))

## [1.2.1](https://github.com/Automattic/newspack-block-theme/compare/v1.2.0...v1.2.1) (2023-06-20)


### Bug Fixes

* delete style.css again ([7ea4e60](https://github.com/Automattic/newspack-block-theme/commit/7ea4e60d38ebdb5239a448ffe274b31c4ce86a8b))

# [1.2.0](https://github.com/Automattic/newspack-block-theme/compare/v1.1.0...v1.2.0) (2023-06-05)


### Bug Fixes

* correct block formatting error in the mobile header ([#22](https://github.com/Automattic/newspack-block-theme/issues/22)) ([96e727b](https://github.com/Automattic/newspack-block-theme/commit/96e727b636d35af941fedb704f0a99964ef293e8))
* correct spacer block values ([#42](https://github.com/Automattic/newspack-block-theme/issues/42)) ([05980d9](https://github.com/Automattic/newspack-block-theme/commit/05980d98a22de9d56af4d2d3ac3de7437de03103))
* remove style that doesn't work from theme.json ([#31](https://github.com/Automattic/newspack-block-theme/issues/31)) ([0762bac](https://github.com/Automattic/newspack-block-theme/commit/0762bacb0e520900d5a112c77002a1de6c8fb08b))
* remove style.css from the theme repo ([#32](https://github.com/Automattic/newspack-block-theme/issues/32)) ([51d849f](https://github.com/Automattic/newspack-block-theme/commit/51d849fc3e332a074a3d6c6e42bde60fbeb55ad5))
* remove unnecessary spacing from navigation menu ([#20](https://github.com/Automattic/newspack-block-theme/issues/20)) ([9e8fbdd](https://github.com/Automattic/newspack-block-theme/commit/9e8fbddf9592620bef6978273f913c2ac41f8394))
* replace query with HPB in no-results view ([#39](https://github.com/Automattic/newspack-block-theme/issues/39)) ([c77aa29](https://github.com/Automattic/newspack-block-theme/commit/c77aa29920e08cced877288378028d98b26dc779))


### Features

* add archive, author archive templates ([#35](https://github.com/Automattic/newspack-block-theme/issues/35)) ([927a982](https://github.com/Automattic/newspack-block-theme/commit/927a982e04388c14cf4da12a800178c664c593e5))
* add block style to 'flatten' mobile nav ([#37](https://github.com/Automattic/newspack-block-theme/issues/37)) ([7b00671](https://github.com/Automattic/newspack-block-theme/commit/7b00671356fc7315a5e13af941b6f5b2c790f88f))
* add block styles; tweak nav styles ([#21](https://github.com/Automattic/newspack-block-theme/issues/21)) ([7e2c22a](https://github.com/Automattic/newspack-block-theme/commit/7e2c22a32159cd2f750193d2e9cf4c8ef621129f))
* add content width and wide width, and apply the wide width to the header/footer ([#15](https://github.com/Automattic/newspack-block-theme/issues/15)) ([9d53a26](https://github.com/Automattic/newspack-block-theme/commit/9d53a26ed1696ad738d2f0144fa12ad4b6744d13))
* add copyright symbol to footer ([#34](https://github.com/Automattic/newspack-block-theme/issues/34)) ([7ce6f74](https://github.com/Automattic/newspack-block-theme/commit/7ce6f74a8d9e723759cd6a804c037c18e7105785))
* add front page template ([#23](https://github.com/Automattic/newspack-block-theme/issues/23)) ([7f44cfe](https://github.com/Automattic/newspack-block-theme/commit/7f44cfe9b96e956ea5b5ce8182dedb61dd4e91f8))
* add greys from WordPress to custom variables ([#25](https://github.com/Automattic/newspack-block-theme/issues/25)) ([08f2d7b](https://github.com/Automattic/newspack-block-theme/commit/08f2d7b73da52fdccd139c35b4483a7e5f91bc01))
* add initial search template ([#36](https://github.com/Automattic/newspack-block-theme/issues/36)) ([6c80dba](https://github.com/Automattic/newspack-block-theme/commit/6c80dba1b2eb020bf50ad696eaaf4f2b8f3ca51f))
* add spacing scale ([#19](https://github.com/Automattic/newspack-block-theme/issues/19)) ([8c7ae41](https://github.com/Automattic/newspack-block-theme/commit/8c7ae41e89e497a30b53d6e301ee7147036651e0))
* add styles to homepage posts block, post carousel ([#38](https://github.com/Automattic/newspack-block-theme/issues/38)) ([6d5f871](https://github.com/Automattic/newspack-block-theme/commit/6d5f87184eb72bd07792bf79f435c6c96b3b719c))
* adding desktop header MVP ([#17](https://github.com/Automattic/newspack-block-theme/issues/17)) ([f155af4](https://github.com/Automattic/newspack-block-theme/commit/f155af4719248376c7cfd8eec11e15a9815c2db4))
* building out 404 page ([#43](https://github.com/Automattic/newspack-block-theme/issues/43)) ([514111e](https://github.com/Automattic/newspack-block-theme/commit/514111e59e81fe135a4492a1f83c4f246d0dfcd4))
* first pass at base fonts, colours ([#11](https://github.com/Automattic/newspack-block-theme/issues/11)) ([bf6b91b](https://github.com/Automattic/newspack-block-theme/commit/bf6b91bb7566f07365225c9cb7b71c05a4664f7a))
* first pass at initial footer pattern ([#24](https://github.com/Automattic/newspack-block-theme/issues/24)) ([301852d](https://github.com/Automattic/newspack-block-theme/commit/301852df80b0de6b711aae3a49dde3d314b0f265))
* initial mobile header pattern ([#18](https://github.com/Automattic/newspack-block-theme/issues/18)) ([b088c54](https://github.com/Automattic/newspack-block-theme/commit/b088c547b1629c423bfe75a096a890f183105a0a))
* initial pass at comments styles ([#27](https://github.com/Automattic/newspack-block-theme/issues/27)) ([f04de4b](https://github.com/Automattic/newspack-block-theme/commit/f04de4b42f5646f18ee0623f925b34dedde46adf))
* initial single post layout ([#26](https://github.com/Automattic/newspack-block-theme/issues/26)) ([603abb7](https://github.com/Automattic/newspack-block-theme/commit/603abb7b7af0a111bfb3bf77f04391eb55bb6fa1))
* update dropcap height and size, pullquotes, captions ([3d8c3f0](https://github.com/Automattic/newspack-block-theme/commit/3d8c3f065ceba4594ef41d87548b333ce7d2704a))

# [1.2.0-alpha.2](https://github.com/Automattic/newspack-block-theme/compare/v1.2.0-alpha.1...v1.2.0-alpha.2) (2023-05-31)


### Bug Fixes

* correct spacer block values ([#42](https://github.com/Automattic/newspack-block-theme/issues/42)) ([05980d9](https://github.com/Automattic/newspack-block-theme/commit/05980d98a22de9d56af4d2d3ac3de7437de03103))
* remove style.css from the theme repo ([#32](https://github.com/Automattic/newspack-block-theme/issues/32)) ([51d849f](https://github.com/Automattic/newspack-block-theme/commit/51d849fc3e332a074a3d6c6e42bde60fbeb55ad5))


### Features

* add block style to 'flatten' mobile nav ([#37](https://github.com/Automattic/newspack-block-theme/issues/37)) ([7b00671](https://github.com/Automattic/newspack-block-theme/commit/7b00671356fc7315a5e13af941b6f5b2c790f88f))
* building out 404 page ([#43](https://github.com/Automattic/newspack-block-theme/issues/43)) ([514111e](https://github.com/Automattic/newspack-block-theme/commit/514111e59e81fe135a4492a1f83c4f246d0dfcd4))

# [1.2.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.1.0...v1.2.0-alpha.1) (2023-05-25)


### Bug Fixes

* correct block formatting error in the mobile header ([#22](https://github.com/Automattic/newspack-block-theme/issues/22)) ([96e727b](https://github.com/Automattic/newspack-block-theme/commit/96e727b636d35af941fedb704f0a99964ef293e8))
* remove style that doesn't work from theme.json ([#31](https://github.com/Automattic/newspack-block-theme/issues/31)) ([0762bac](https://github.com/Automattic/newspack-block-theme/commit/0762bacb0e520900d5a112c77002a1de6c8fb08b))
* remove unnecessary spacing from navigation menu ([#20](https://github.com/Automattic/newspack-block-theme/issues/20)) ([9e8fbdd](https://github.com/Automattic/newspack-block-theme/commit/9e8fbddf9592620bef6978273f913c2ac41f8394))
* replace query with HPB in no-results view ([#39](https://github.com/Automattic/newspack-block-theme/issues/39)) ([c77aa29](https://github.com/Automattic/newspack-block-theme/commit/c77aa29920e08cced877288378028d98b26dc779))


### Features

* add archive, author archive templates ([#35](https://github.com/Automattic/newspack-block-theme/issues/35)) ([927a982](https://github.com/Automattic/newspack-block-theme/commit/927a982e04388c14cf4da12a800178c664c593e5))
* add block styles; tweak nav styles ([#21](https://github.com/Automattic/newspack-block-theme/issues/21)) ([7e2c22a](https://github.com/Automattic/newspack-block-theme/commit/7e2c22a32159cd2f750193d2e9cf4c8ef621129f))
* add content width and wide width, and apply the wide width to the header/footer ([#15](https://github.com/Automattic/newspack-block-theme/issues/15)) ([9d53a26](https://github.com/Automattic/newspack-block-theme/commit/9d53a26ed1696ad738d2f0144fa12ad4b6744d13))
* add copyright symbol to footer ([#34](https://github.com/Automattic/newspack-block-theme/issues/34)) ([7ce6f74](https://github.com/Automattic/newspack-block-theme/commit/7ce6f74a8d9e723759cd6a804c037c18e7105785))
* add front page template ([#23](https://github.com/Automattic/newspack-block-theme/issues/23)) ([7f44cfe](https://github.com/Automattic/newspack-block-theme/commit/7f44cfe9b96e956ea5b5ce8182dedb61dd4e91f8))
* add greys from WordPress to custom variables ([#25](https://github.com/Automattic/newspack-block-theme/issues/25)) ([08f2d7b](https://github.com/Automattic/newspack-block-theme/commit/08f2d7b73da52fdccd139c35b4483a7e5f91bc01))
* add initial search template ([#36](https://github.com/Automattic/newspack-block-theme/issues/36)) ([6c80dba](https://github.com/Automattic/newspack-block-theme/commit/6c80dba1b2eb020bf50ad696eaaf4f2b8f3ca51f))
* add spacing scale ([#19](https://github.com/Automattic/newspack-block-theme/issues/19)) ([8c7ae41](https://github.com/Automattic/newspack-block-theme/commit/8c7ae41e89e497a30b53d6e301ee7147036651e0))
* add styles to homepage posts block, post carousel ([#38](https://github.com/Automattic/newspack-block-theme/issues/38)) ([6d5f871](https://github.com/Automattic/newspack-block-theme/commit/6d5f87184eb72bd07792bf79f435c6c96b3b719c))
* adding desktop header MVP ([#17](https://github.com/Automattic/newspack-block-theme/issues/17)) ([f155af4](https://github.com/Automattic/newspack-block-theme/commit/f155af4719248376c7cfd8eec11e15a9815c2db4))
* first pass at base fonts, colours ([#11](https://github.com/Automattic/newspack-block-theme/issues/11)) ([bf6b91b](https://github.com/Automattic/newspack-block-theme/commit/bf6b91bb7566f07365225c9cb7b71c05a4664f7a))
* first pass at initial footer pattern ([#24](https://github.com/Automattic/newspack-block-theme/issues/24)) ([301852d](https://github.com/Automattic/newspack-block-theme/commit/301852df80b0de6b711aae3a49dde3d314b0f265))
* initial mobile header pattern ([#18](https://github.com/Automattic/newspack-block-theme/issues/18)) ([b088c54](https://github.com/Automattic/newspack-block-theme/commit/b088c547b1629c423bfe75a096a890f183105a0a))
* initial pass at comments styles ([#27](https://github.com/Automattic/newspack-block-theme/issues/27)) ([f04de4b](https://github.com/Automattic/newspack-block-theme/commit/f04de4b42f5646f18ee0623f925b34dedde46adf))
* initial single post layout ([#26](https://github.com/Automattic/newspack-block-theme/issues/26)) ([603abb7](https://github.com/Automattic/newspack-block-theme/commit/603abb7b7af0a111bfb3bf77f04391eb55bb6fa1))
* update dropcap height and size, pullquotes, captions ([3d8c3f0](https://github.com/Automattic/newspack-block-theme/commit/3d8c3f065ceba4594ef41d87548b333ce7d2704a))

# [1.1.0](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0...v1.1.0) (2023-05-24)


### Features

* add mobile menu part proof of concept ([#3](https://github.com/Automattic/newspack-block-theme/issues/3)) ([3768123](https://github.com/Automattic/newspack-block-theme/commit/3768123019a5400509d145b033300934b901f55f))

# [1.1.0-alpha.1](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0...v1.1.0-alpha.1) (2023-05-11)


### Features

* add mobile menu part proof of concept ([#3](https://github.com/Automattic/newspack-block-theme/issues/3)) ([3768123](https://github.com/Automattic/newspack-block-theme/commit/3768123019a5400509d145b033300934b901f55f))

# 1.0.0 (2023-05-03)


### Bug Fixes

* config.yml for CI ([7b2d2ba](https://github.com/Automattic/newspack-block-theme/commit/7b2d2bafd284911c6e3d5a70632834b5ae1f3316))
* config.yml for CI ([48559ee](https://github.com/Automattic/newspack-block-theme/commit/48559ee42358722c6cfd52776ba7573827d48057))
* config.yml for CI ([99c9555](https://github.com/Automattic/newspack-block-theme/commit/99c95555c70411c66a2540431c7e8a935e8b093b))
* config.yml for CI ([8bc9f9d](https://github.com/Automattic/newspack-block-theme/commit/8bc9f9dbaec8c66a07b508279fb76fd202dc3dbe))
* config.yml for CI ([fe5421b](https://github.com/Automattic/newspack-block-theme/commit/fe5421b723e11859344723374699de0c34c4e2f4))
* config.yml for CI ([7e27a3c](https://github.com/Automattic/newspack-block-theme/commit/7e27a3c6edb49c24d44739a53e9706dca4a7ca77))
* config.yml for CI ([f1a5bc5](https://github.com/Automattic/newspack-block-theme/commit/f1a5bc5120193aa05e483dd745d7c1241b56a7ff))
* one more try ([916b1d2](https://github.com/Automattic/newspack-block-theme/commit/916b1d2e8dfccbe3a1e4413acb4f849dbaa85f5e))
* remove unnecessary jobs from CI config ([#5](https://github.com/Automattic/newspack-block-theme/issues/5)) ([a50f7bc](https://github.com/Automattic/newspack-block-theme/commit/a50f7bc6e2cbed7a0d8cb87db7db2cc33dc97e98))
* try alternate release job for additional version bump assets ([6035f48](https://github.com/Automattic/newspack-block-theme/commit/6035f48c2067095cfecd02f34cd20f450fd48e31))
* try semantic-release directly ([fa2a979](https://github.com/Automattic/newspack-block-theme/commit/fa2a979bd3527dcb71a33298c3ef691074561704))
* version bump in additional files ([8616144](https://github.com/Automattic/newspack-block-theme/commit/8616144c06da05f1e372838f8d67bca9d2c3ddc2))


### Features

* fork Block Canvas v. 0.0.26 as a theme starting point ([4cffa9f](https://github.com/Automattic/newspack-block-theme/commit/4cffa9fa53e4a8f4bbfab984363fb792ecc765d4))
* fork Block Canvas v. 0.0.26 as a theme starting point ([e5635a0](https://github.com/Automattic/newspack-block-theme/commit/e5635a0d86f2c3ca2818b36191c406532ceb65e1))
* setup repo ([#4](https://github.com/Automattic/newspack-block-theme/issues/4)) ([7d7b908](https://github.com/Automattic/newspack-block-theme/commit/7d7b908e026794944e5ec5437f36e98a14daf521))

# [1.0.0-alpha.5](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0-alpha.4...v1.0.0-alpha.5) (2023-05-03)


### Bug Fixes

* try semantic-release directly ([fa2a979](https://github.com/Automattic/newspack-block-theme/commit/fa2a979bd3527dcb71a33298c3ef691074561704))

# [1.0.0-alpha.4](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0-alpha.3...v1.0.0-alpha.4) (2023-04-19)


### Bug Fixes

* one more try ([916b1d2](https://github.com/Automattic/newspack-block-theme/commit/916b1d2e8dfccbe3a1e4413acb4f849dbaa85f5e))

# [1.0.0-alpha.3](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0-alpha.2...v1.0.0-alpha.3) (2023-04-19)


### Bug Fixes

* config.yml for CI ([7b2d2ba](https://github.com/Automattic/newspack-block-theme/commit/7b2d2bafd284911c6e3d5a70632834b5ae1f3316))
* config.yml for CI ([48559ee](https://github.com/Automattic/newspack-block-theme/commit/48559ee42358722c6cfd52776ba7573827d48057))
* config.yml for CI ([99c9555](https://github.com/Automattic/newspack-block-theme/commit/99c95555c70411c66a2540431c7e8a935e8b093b))
* config.yml for CI ([8bc9f9d](https://github.com/Automattic/newspack-block-theme/commit/8bc9f9dbaec8c66a07b508279fb76fd202dc3dbe))
* config.yml for CI ([fe5421b](https://github.com/Automattic/newspack-block-theme/commit/fe5421b723e11859344723374699de0c34c4e2f4))
* config.yml for CI ([7e27a3c](https://github.com/Automattic/newspack-block-theme/commit/7e27a3c6edb49c24d44739a53e9706dca4a7ca77))
* config.yml for CI ([f1a5bc5](https://github.com/Automattic/newspack-block-theme/commit/f1a5bc5120193aa05e483dd745d7c1241b56a7ff))
* try alternate release job for additional version bump assets ([6035f48](https://github.com/Automattic/newspack-block-theme/commit/6035f48c2067095cfecd02f34cd20f450fd48e31))

# [1.0.0-alpha.2](https://github.com/Automattic/newspack-block-theme/compare/v1.0.0-alpha.1...v1.0.0-alpha.2) (2023-04-19)


### Bug Fixes

* version bump in additional files ([8616144](https://github.com/Automattic/newspack-block-theme/commit/8616144c06da05f1e372838f8d67bca9d2c3ddc2))

# 1.0.0-alpha.1 (2023-04-19)


### Bug Fixes

* remove unnecessary jobs from CI config ([#5](https://github.com/Automattic/newspack-block-theme/issues/5)) ([a50f7bc](https://github.com/Automattic/newspack-block-theme/commit/a50f7bc6e2cbed7a0d8cb87db7db2cc33dc97e98))


### Features

* fork Block Canvas v. 0.0.26 as a theme starting point ([4cffa9f](https://github.com/Automattic/newspack-block-theme/commit/4cffa9fa53e4a8f4bbfab984363fb792ecc765d4))
* fork Block Canvas v. 0.0.26 as a theme starting point ([e5635a0](https://github.com/Automattic/newspack-block-theme/commit/e5635a0d86f2c3ca2818b36191c406532ceb65e1))
* setup repo ([#4](https://github.com/Automattic/newspack-block-theme/issues/4)) ([7d7b908](https://github.com/Automattic/newspack-block-theme/commit/7d7b908e026794944e5ec5437f36e98a14daf521))
