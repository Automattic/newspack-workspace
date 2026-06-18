## newspack-newsletters [3.34.1](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.34.0...newspack-newsletters@3.34.1) (2026-06-15)


### Bug Fixes

* **esp:** force Mailchimp segment_opts re-snapshot on segment swap ([#221](https://github.com/Automattic/newspack-workspace/issues/221)) ([9cad542](https://github.com/Automattic/newspack-workspace/commit/9cad5427351a118087c28a69354614faa1d36de3))

# newspack-newsletters [3.34.0](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.33.6...newspack-newsletters@3.34.0) (2026-06-15)


### Bug Fixes

* **editor:** keep legacy CSS vars in sync with newsletters-prefixed ones ([#2140](https://github.com/Automattic/newspack-workspace/issues/2140)) ([4f3c33a](https://github.com/Automattic/newspack-workspace/commit/4f3c33a823b2e2634ff298f2f4060b4c93af6dbd))
* **newsletters:** clear false "unsaved changes" prompt after save ([#190](https://github.com/Automattic/newspack-workspace/issues/190)) ([265f0dc](https://github.com/Automattic/newspack-workspace/commit/265f0dca2c41eeefe3c688b1030de31212bcc70f))
* render synced patterns inside Group blocks in newsletters ([#2069](https://github.com/Automattic/newspack-workspace/issues/2069)) ([b09da75](https://github.com/Automattic/newspack-workspace/commit/b09da75d3b30d1325ee6b84de5113516d22b494d))


### Features

* **content-gate:** add newsletter link access-control bypass ([#136](https://github.com/Automattic/newspack-workspace/issues/136)) ([508f57c](https://github.com/Automattic/newspack-workspace/commit/508f57c623cfb3e2a6ba8d06d89a4f6a7ad3b97f))
* **reader-auth:** unify auth + post-reg verification flows ([#135](https://github.com/Automattic/newspack-workspace/issues/135)) ([f67bb65](https://github.com/Automattic/newspack-workspace/commit/f67bb654374b291658a90dd9151dcc9e3cce43fc)), closes [#signin_modal](https://github.com/Automattic/newspack-workspace/issues/signin_modal) [#register_modal](https://github.com/Automattic/newspack-workspace/issues/register_modal)

# newspack-newsletters [3.34.0-alpha.2](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.34.0-alpha.1...newspack-newsletters@3.34.0-alpha.2) (2026-06-12)


### Features

* **content-gate:** add newsletter link access-control bypass ([#136](https://github.com/Automattic/newspack-workspace/issues/136)) ([508f57c](https://github.com/Automattic/newspack-workspace/commit/508f57c623cfb3e2a6ba8d06d89a4f6a7ad3b97f))

# newspack-newsletters [3.34.0-alpha.1](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.33.6...newspack-newsletters@3.34.0-alpha.1) (2026-06-05)


### Bug Fixes

* **editor:** keep legacy CSS vars in sync with newsletters-prefixed ones ([#2140](https://github.com/Automattic/newspack-workspace/issues/2140)) ([4f3c33a](https://github.com/Automattic/newspack-workspace/commit/4f3c33a823b2e2634ff298f2f4060b4c93af6dbd))
* **newsletters:** clear false "unsaved changes" prompt after save ([#190](https://github.com/Automattic/newspack-workspace/issues/190)) ([265f0dc](https://github.com/Automattic/newspack-workspace/commit/265f0dca2c41eeefe3c688b1030de31212bcc70f))
* render synced patterns inside Group blocks in newsletters ([#2069](https://github.com/Automattic/newspack-workspace/issues/2069)) ([b09da75](https://github.com/Automattic/newspack-workspace/commit/b09da75d3b30d1325ee6b84de5113516d22b494d))


### Features

* **reader-auth:** unify auth + post-reg verification flows ([#135](https://github.com/Automattic/newspack-workspace/issues/135)) ([f67bb65](https://github.com/Automattic/newspack-workspace/commit/f67bb654374b291658a90dd9151dcc9e3cce43fc)), closes [#signin_modal](https://github.com/Automattic/newspack-workspace/issues/signin_modal) [#register_modal](https://github.com/Automattic/newspack-workspace/issues/register_modal)

## newspack-newsletters [3.33.6](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.33.5...newspack-newsletters@3.33.6) (2026-06-03)


### Bug Fixes

* **posts-inserter:** show placeholder when block has zero posts ([#166](https://github.com/Automattic/newspack-workspace/issues/166)) ([c269ff2](https://github.com/Automattic/newspack-workspace/commit/c269ff2e9f937a406aaeae4a833da3046cbc61ab))

## newspack-newsletters [3.33.5](https://github.com/Automattic/newspack-workspace/compare/newspack-newsletters@3.33.4...newspack-newsletters@3.33.5) (2026-06-01)


### Bug Fixes

* **esp:** abort send when configured segment cannot be resolved ([#2131](https://github.com/Automattic/newspack-workspace/issues/2131)) ([020528c](https://github.com/Automattic/newspack-workspace/commit/020528c406e03240d34fb8d9721947a9f7f12cad))

## [3.33.4](https://github.com/Automattic/newspack-newsletters/compare/v3.33.3...v3.33.4) (2026-05-22)


### Bug Fixes

* update text alignment based on changes in WP 7.0 ([#2166](https://github.com/Automattic/newspack-newsletters/issues/2166)) ([635c3bb](https://github.com/Automattic/newspack-newsletters/commit/635c3bbf10a04904313ee186b70de37821da4523))

## [3.33.3](https://github.com/Automattic/newspack-newsletters/compare/v3.33.2...v3.33.3) (2026-05-20)


### Bug Fixes

* read script deps from .asset.php for WP 7.0 ([#2142](https://github.com/Automattic/newspack-newsletters/issues/2142)) ([a375fff](https://github.com/Automattic/newspack-newsletters/commit/a375fff25e64aab559f2ce4bae5ac597b71e85f6))

## [3.33.2](https://github.com/Automattic/newspack-newsletters/compare/v3.33.1...v3.33.2) (2026-05-19)


### Bug Fixes

* make sure newsletter styles are previewed in WP 7.0 ([#2115](https://github.com/Automattic/newspack-newsletters/issues/2115)) ([94b69e7](https://github.com/Automattic/newspack-newsletters/commit/94b69e7a19f6f91e68f976a4a1a5d3429ae51f28))

## [3.33.1](https://github.com/Automattic/newspack-newsletters/compare/v3.33.0...v3.33.1) (2026-05-19)


### Bug Fixes

* remove block-level CSS field from Newsletter editor ([#2116](https://github.com/Automattic/newspack-newsletters/issues/2116)) ([6789a29](https://github.com/Automattic/newspack-newsletters/commit/6789a29ecf95c26b3509e7e8de05f0e7a6c91018))

# [3.33.0](https://github.com/Automattic/newspack-newsletters/compare/v3.32.0...v3.33.0) (2026-05-18)


### Bug Fixes

* **block-theme:** override theme.json styles in the newsletter editor ([#2078](https://github.com/Automattic/newspack-newsletters/issues/2078)) ([49c857c](https://github.com/Automattic/newspack-newsletters/commit/49c857c11cf11da39c7e11cca830edb9e77c84ab))
* ensure editor preview and front end match for default subscription block style ([#2107](https://github.com/Automattic/newspack-newsletters/issues/2107)) ([9624edc](https://github.com/Automattic/newspack-newsletters/commit/9624edc3ee1d0a2bd32898fa9a2a2c8358e30587))
* hide font family option from newsletter ([#2099](https://github.com/Automattic/newspack-newsletters/issues/2099)) ([3a928a5](https://github.com/Automattic/newspack-newsletters/commit/3a928a5c4a1a8988ea40b68083f5495e735cb565))
* skip and remove malformed subscription intents ([#2102](https://github.com/Automattic/newspack-newsletters/issues/2102)) ([c0417c6](https://github.com/Automattic/newspack-newsletters/commit/c0417c68d33e0fcc1f98f1e0f59e1bc11d85e16a))


### Features

* **esp:** enrich contact field schema for integrations ([#2082](https://github.com/Automattic/newspack-newsletters/issues/2082)) ([3434e75](https://github.com/Automattic/newspack-newsletters/commit/3434e754817c33a3a6fb8171cf28d73c0b39627e))
* improve newsletter editor layout and post-inserter UX ([#2077](https://github.com/Automattic/newspack-newsletters/issues/2077)) ([d9927cc](https://github.com/Automattic/newspack-newsletters/commit/d9927cca85c0100d705908fc36de9f384656aa57))

# [3.32.0](https://github.com/Automattic/newspack-newsletters/compare/v3.31.1...v3.32.0) (2026-05-04)


### Bug Fixes

* **block-theme:** newsletters subscription form style fixes ([#2070](https://github.com/Automattic/newspack-newsletters/issues/2070)) ([d7e54ac](https://github.com/Automattic/newspack-newsletters/commit/d7e54ac41702d6bd9b3bc1d9e266a2ff018c4a4f))


### Features

* add newsletter editor check regression testing ([#2064](https://github.com/Automattic/newspack-newsletters/issues/2064)) ([998021f](https://github.com/Automattic/newspack-newsletters/commit/998021f2cc73b9588172e7ded083e858429b2b9b))

## [3.31.1](https://github.com/Automattic/newspack-newsletters/compare/v3.31.0...v3.31.1) (2026-04-15)


### Bug Fixes

* use active campaign saved segments summary endpoint ([#2079](https://github.com/Automattic/newspack-newsletters/issues/2079)) ([afa60be](https://github.com/Automattic/newspack-newsletters/commit/afa60be31ace595c6761a5f3c91606e748690d1c))

# [3.31.0](https://github.com/Automattic/newspack-newsletters/compare/v3.30.0...v3.31.0) (2026-04-13)


### Bug Fixes

* **dependencies:** rebuild package-lock.json ([#2066](https://github.com/Automattic/newspack-newsletters/issues/2066)) ([6e82e00](https://github.com/Automattic/newspack-newsletters/commit/6e82e00c0ab084d3a40787f397cd2e4349e1eae4))
* **posts-inserter:** handle missing featured images; improve performance ([#1969](https://github.com/Automattic/newspack-newsletters/issues/1969)) ([cf5a47d](https://github.com/Automattic/newspack-newsletters/commit/cf5a47d1fae7adf218592373fb8944a3da0b9976))
* update block support declaration ([#2060](https://github.com/Automattic/newspack-newsletters/issues/2060)) ([10a4468](https://github.com/Automattic/newspack-newsletters/commit/10a446809d1379afd1c4f16563d073aeef030a8d))
* update removing block visibility ([#2056](https://github.com/Automattic/newspack-newsletters/issues/2056)) ([93d5e80](https://github.com/Automattic/newspack-newsletters/commit/93d5e803236e42e33eddbca128dd1ea52975c2e0))


### Features

* **access-control:** filter the subscription lists available to readers for signup ([#2053](https://github.com/Automattic/newspack-newsletters/issues/2053)) ([f8aea0b](https://github.com/Automattic/newspack-newsletters/commit/f8aea0b28c4e6bedaad8767a309ee74317952a4e))

# [3.30.0](https://github.com/Automattic/newspack-newsletters/compare/v3.29.2...v3.30.0) (2026-03-30)


### Features

* add test_connection() to newsletter service providers ([#2047](https://github.com/Automattic/newspack-newsletters/issues/2047)) ([c0fbef5](https://github.com/Automattic/newspack-newsletters/commit/c0fbef521bece078bb2e03272ab7e5049a7bb938))

## [3.29.2](https://github.com/Automattic/newspack-newsletters/compare/v3.29.1...v3.29.2) (2026-03-26)


### Bug Fixes

* share block registration ([#2055](https://github.com/Automattic/newspack-newsletters/issues/2055)) ([03ecfa7](https://github.com/Automattic/newspack-newsletters/commit/03ecfa7726edcc7130c6b86c8ba30b42317f0f1c))

## [3.29.1](https://github.com/Automattic/newspack-newsletters/compare/v3.29.0...v3.29.1) (2026-03-23)


### Bug Fixes

* use URL params to determine email editing context ([#2051](https://github.com/Automattic/newspack-newsletters/issues/2051)) ([255dd0c](https://github.com/Automattic/newspack-newsletters/commit/255dd0c211c6d21060b089adee311f74b893c9dd))

# [3.29.0](https://github.com/Automattic/newspack-newsletters/compare/v3.28.6...v3.29.0) (2026-03-16)


### Bug Fixes

* iframe editor compatibility ([#2025](https://github.com/Automattic/newspack-newsletters/issues/2025)) ([cd15c02](https://github.com/Automattic/newspack-newsletters/commit/cd15c026eb25521c4004bf2d54e7ceb38fc9cd55))
* improve error handling in upsert contact method ([#2031](https://github.com/Automattic/newspack-newsletters/issues/2031)) ([89a5937](https://github.com/Automattic/newspack-newsletters/commit/89a593786c5528e36708e1440920b5e8a4504a1b))
* no parallel post-release jobs ([#2037](https://github.com/Automattic/newspack-newsletters/issues/2037)) ([8e4a738](https://github.com/Automattic/newspack-newsletters/commit/8e4a738b96584b331a1862997663418a3c971847))


### Features

* **init-modal:** apply new modal style to init screen in editor ([#2041](https://github.com/Automattic/newspack-newsletters/issues/2041)) ([3caafdc](https://github.com/Automattic/newspack-newsletters/commit/3caafdc27a01479cff1c2168a4fdee6f0ab3e175))

## [3.28.6](https://github.com/Automattic/newspack-newsletters/compare/v3.28.5...v3.28.6) (2026-03-03)


### Bug Fixes

* ensure current user lists are initialized as an empty array if not an array ([#2043](https://github.com/Automattic/newspack-newsletters/issues/2043)) ([0afb1c6](https://github.com/Automattic/newspack-newsletters/commit/0afb1c6e1c835539c706e8b8c4ff577ef34dea3c))

## [3.28.5](https://github.com/Automattic/newspack-newsletters/compare/v3.28.4...v3.28.5) (2026-02-17)


### Bug Fixes

* no parallel post-release jobs ([#2037](https://github.com/Automattic/newspack-newsletters/issues/2037)) ([90023db](https://github.com/Automattic/newspack-newsletters/commit/90023dbd68082e37e8bdba5dca54be0052f168fe))

## [3.28.4](https://github.com/Automattic/newspack-newsletters/compare/v3.28.3...v3.28.4) (2026-02-16)


### Bug Fixes

* release-wporg script ([#2036](https://github.com/Automattic/newspack-newsletters/issues/2036)) ([c991aae](https://github.com/Automattic/newspack-newsletters/commit/c991aae0d37b24578db1f05c308ae99f92c2c2a7))

## [3.28.3](https://github.com/Automattic/newspack-newsletters/compare/v3.28.2...v3.28.3) (2026-02-16)


### Bug Fixes

* try adding build-distributable job before release-wporg ([835ac05](https://github.com/Automattic/newspack-newsletters/commit/835ac05a424b3664f54bd6df06519e6acc903107))

## [3.28.2](https://github.com/Automattic/newspack-newsletters/compare/v3.28.1...v3.28.2) (2026-02-16)


### Bug Fixes

* pass plugin name to release-wporg job ([#2035](https://github.com/Automattic/newspack-newsletters/issues/2035)) ([a0ba538](https://github.com/Automattic/newspack-newsletters/commit/a0ba538bcd8e46b9436795419b6015b920839970))

## [3.28.1](https://github.com/Automattic/newspack-newsletters/compare/v3.28.0...v3.28.1) (2026-02-16)


### Bug Fixes

* force release ([b028a56](https://github.com/Automattic/newspack-newsletters/commit/b028a56f7a6704f966af958533b281f6e7aca9e3))

# [3.28.0](https://github.com/Automattic/newspack-newsletters/compare/v3.27.1...v3.28.0) (2026-02-16)


### Bug Fixes

* restore newsletter rendering of RDB blocks using get_value() ([2815bea](https://github.com/Automattic/newspack-newsletters/commit/2815bea44a5edc44328ed2da1847bf11125a25d0))


### Features

* add mastodon and whatsapp to social link icons ([#2008](https://github.com/Automattic/newspack-newsletters/issues/2008)) ([69843cf](https://github.com/Automattic/newspack-newsletters/commit/69843cf76c14e6d0678ce3d474baf08d0af11a75))

## [3.27.1](https://github.com/Automattic/newspack-newsletters/compare/v3.27.0...v3.27.1) (2026-02-02)


### Bug Fixes

* **newsletters:** hide Jetpack stats column on newsletters ([#2013](https://github.com/Automattic/newspack-newsletters/issues/2013)) ([4cdae19](https://github.com/Automattic/newspack-newsletters/commit/4cdae19ca40f4bb05b2732583311c5e0523e4730))

# [3.27.0](https://github.com/Automattic/newspack-newsletters/compare/v3.26.0...v3.27.0) (2026-01-19)


### Bug Fixes

* **ads:** start/end date value format ([#2005](https://github.com/Automattic/newspack-newsletters/issues/2005)) ([4cf8f4e](https://github.com/Automattic/newspack-newsletters/commit/4cf8f4e38f375d4a8f9d32011114f1be48a7f365))


### Features

* rename tracking ([#1999](https://github.com/Automattic/newspack-newsletters/issues/1999)) ([0ae58db](https://github.com/Automattic/newspack-newsletters/commit/0ae58db0e092f3ab919573c07db530ebee7da66f))

# [3.26.0](https://github.com/Automattic/newspack-newsletters/compare/v3.25.5...v3.26.0) (2026-01-05)


### Features

* add support for WP 6.9's Notes feature to Newsletters ([#1975](https://github.com/Automattic/newspack-newsletters/issues/1975)) ([52d2caf](https://github.com/Automattic/newspack-newsletters/commit/52d2cafaf64c773ff88030335f8d7d7ef59e4d14))
* default mailchimp footer ([#1977](https://github.com/Automattic/newspack-newsletters/issues/1977)) ([3239120](https://github.com/Automattic/newspack-newsletters/commit/3239120fd9cdbb4617342cbeda6680683264a3d3))

## [3.25.5](https://github.com/Automattic/newspack-newsletters/compare/v3.25.4...v3.25.5) (2025-12-17)


### Bug Fixes

* **memberships-cli:** avoid reuse of variable name ([#2001](https://github.com/Automattic/newspack-newsletters/issues/2001)) ([249c39d](https://github.com/Automattic/newspack-newsletters/commit/249c39d2a348dc7240cf87cf300f28a27346da40))

## [3.25.4](https://github.com/Automattic/newspack-newsletters/compare/v3.25.3...v3.25.4) (2025-12-09)


### Bug Fixes

* make sure a log file always exists ([#1996](https://github.com/Automattic/newspack-newsletters/issues/1996)) ([f1976a0](https://github.com/Automattic/newspack-newsletters/commit/f1976a0cac4a643733d95358145e5339a8d7ce19))

## [3.25.3](https://github.com/Automattic/newspack-newsletters/compare/v3.25.2...v3.25.3) (2025-12-08)


### Bug Fixes

* **my-account:** subscription button style ([#1978](https://github.com/Automattic/newspack-newsletters/issues/1978)) ([d92fe7e](https://github.com/Automattic/newspack-newsletters/commit/d92fe7e30e1e6edff40e28cc46f52f5842f71fb0))

## [3.25.2](https://github.com/Automattic/newspack-newsletters/compare/v3.25.1...v3.25.2) (2025-12-03)


### Bug Fixes

* rename blockVisibility to visibility ([#1991](https://github.com/Automattic/newspack-newsletters/issues/1991)) ([1e9cf1d](https://github.com/Automattic/newspack-newsletters/commit/1e9cf1d743bfa08b7c280d5765c20e63f44c172b))

## [3.25.1](https://github.com/Automattic/newspack-newsletters/compare/v3.25.0...v3.25.1) (2025-11-26)


### Bug Fixes

* **mjml:** escape values when converting post to MJML ([#1976](https://github.com/Automattic/newspack-newsletters/issues/1976)) ([8a61355](https://github.com/Automattic/newspack-newsletters/commit/8a61355813650901851b4218a79c66319feecdf9))

# [3.25.0](https://github.com/Automattic/newspack-newsletters/compare/v3.24.2...v3.25.0) (2025-11-24)


### Bug Fixes

* `alpha` conflicts with `release` ([da3938a](https://github.com/Automattic/newspack-newsletters/commit/da3938a214aff6e33a59959c8f3337ff1f62cd3d))
* `alpha` conflicts with `release` ([#1973](https://github.com/Automattic/newspack-newsletters/issues/1973)) ([ca54c51](https://github.com/Automattic/newspack-newsletters/commit/ca54c5198572dea428aa2961c378e259bba5a552))
* remove 'hide block' option for Newsletters ([#1968](https://github.com/Automattic/newspack-newsletters/issues/1968)) ([c7ae6e2](https://github.com/Automattic/newspack-newsletters/commit/c7ae6e24f18fddbe9b9eaf2fe71b3b1375efbc60))
* remove 'hide block' option for Newsletters ([#1968](https://github.com/Automattic/newspack-newsletters/issues/1968)) ([74d3c39](https://github.com/Automattic/newspack-newsletters/commit/74d3c396d5e7f64e6fdfbc37d4d6bb28e9cf8a9d))
* remove stretchy paragraphy, heading block variations from newsletter ([#1967](https://github.com/Automattic/newspack-newsletters/issues/1967)) ([0eaf738](https://github.com/Automattic/newspack-newsletters/commit/0eaf7385fc01889132ee83e97eab87ffd80a6183))
* remove stretchy paragraphy, heading block variations from newsletter ([#1967](https://github.com/Automattic/newspack-newsletters/issues/1967)) ([39ce7b8](https://github.com/Automattic/newspack-newsletters/commit/39ce7b82e3872cf3a1182d7e68c05b829582f341))


### Features

* Merge pull request [#1960](https://github.com/Automattic/newspack-newsletters/issues/1960) from Automattic/trunk ([79b9539](https://github.com/Automattic/newspack-newsletters/commit/79b95391af644956e3bb29828c69172e8bc51694))

## [3.24.2](https://github.com/Automattic/newspack-newsletters/compare/v3.24.1...v3.24.2) (2025-11-24)


### Bug Fixes

* allow updates to scheduled newsletter posts ([#1966](https://github.com/Automattic/newspack-newsletters/issues/1966)) ([3e165ff](https://github.com/Automattic/newspack-newsletters/commit/3e165ff0264cf4320a77174fe8a775338f70ded7))

## [3.24.1](https://github.com/Automattic/newspack-newsletters/compare/v3.24.0...v3.24.1) (2025-11-05)


### Bug Fixes

* potential fatal errors due to assumed data types ([#1959](https://github.com/Automattic/newspack-newsletters/issues/1959)) ([d42ad7d](https://github.com/Automattic/newspack-newsletters/commit/d42ad7d7699569e89cf6ea876732a1d3a63503ba))

# [3.24.0](https://github.com/Automattic/newspack-newsletters/compare/v3.23.7...v3.24.0) (2025-10-30)


### Bug Fixes

* better handling for padding and border style attributes in newsletters ([#1948](https://github.com/Automattic/newspack-newsletters/issues/1948)) ([42d8ee1](https://github.com/Automattic/newspack-newsletters/commit/42d8ee1766ea9253cd54c70ad25e7fcbbc495334))
* **dependencies:** restore last known working package version ([#1949](https://github.com/Automattic/newspack-newsletters/issues/1949)) ([a6d1c3e](https://github.com/Automattic/newspack-newsletters/commit/a6d1c3e08386ec2a8b3483ba5e1a2b366bb4493c))


### Features

* **visibility-attribute:** support email-only blocks ([#1941](https://github.com/Automattic/newspack-newsletters/issues/1941)) ([017673c](https://github.com/Automattic/newspack-newsletters/commit/017673c0d4e294cfb691902f92f153162e08a447))

## [3.23.7](https://github.com/Automattic/newspack-newsletters/compare/v3.23.6...v3.23.7) (2025-10-14)


### Bug Fixes

* revert borders and padding in email HTML hotfix ([#1947](https://github.com/Automattic/newspack-newsletters/issues/1947)) ([d7525eb](https://github.com/Automattic/newspack-newsletters/commit/d7525ebcacc678ae1fa262cba00195c1702c3948))

## [3.23.6](https://github.com/Automattic/newspack-newsletters/compare/v3.23.5...v3.23.6) (2025-10-13)


### Bug Fixes

* borders and padding in email HTML ([f2c4368](https://github.com/Automattic/newspack-newsletters/commit/f2c4368484dc79fc340d39f4291f909b8447a8ca))

## [3.23.5](https://github.com/Automattic/newspack-newsletters/compare/v3.23.4...v3.23.5) (2025-10-08)


### Bug Fixes

* **mailchimp:** strip protocol strings from link-based merge tags ([#1934](https://github.com/Automattic/newspack-newsletters/issues/1934)) ([dbd4e19](https://github.com/Automattic/newspack-newsletters/commit/dbd4e1960699908f2a975030d1b820abe689596b))

## [3.23.4](https://github.com/Automattic/newspack-newsletters/compare/v3.23.3...v3.23.4) (2025-10-07)


### Bug Fixes

* **activecampaign:** only trigger contact deletion when in change email context ([#1938](https://github.com/Automattic/newspack-newsletters/issues/1938)) ([e1a0cb0](https://github.com/Automattic/newspack-newsletters/commit/e1a0cb0306ac63c9629cfe9a53f406cd0a18c1a2))

## [3.23.3](https://github.com/Automattic/newspack-newsletters/compare/v3.23.2...v3.23.3) (2025-10-07)


### Bug Fixes

* **activecampaign:** add required sdate param ([#1940](https://github.com/Automattic/newspack-newsletters/issues/1940)) ([bb9fd09](https://github.com/Automattic/newspack-newsletters/commit/bb9fd0991a9c6425f535b4a92d68d15189320c7c))

## [3.23.2](https://github.com/Automattic/newspack-newsletters/compare/v3.23.1...v3.23.2) (2025-10-06)


### Bug Fixes

* avoid errors ([#1927](https://github.com/Automattic/newspack-newsletters/issues/1927)) ([8bad336](https://github.com/Automattic/newspack-newsletters/commit/8bad336aa3fcf0b9c4f02fc651c8fe52938dc83b))
* update download URL for db.php ([#1920](https://github.com/Automattic/newspack-newsletters/issues/1920)) ([bf89e79](https://github.com/Automattic/newspack-newsletters/commit/bf89e799440d7818fa74485f63be12e11697b3e6))

## [3.23.1](https://github.com/Automattic/newspack-newsletters/compare/v3.23.0...v3.23.1) (2025-09-22)


### Bug Fixes

* trigger save before opening send modal ([#1925](https://github.com/Automattic/newspack-newsletters/issues/1925)) ([7492915](https://github.com/Automattic/newspack-newsletters/commit/74929153a240eb0e6aa68093b3480e5510d8c466))

# [3.23.0](https://github.com/Automattic/newspack-newsletters/compare/v3.22.2...v3.23.0) (2025-09-22)


### Features

* keep line breaks on lists descriptions ([#1906](https://github.com/Automattic/newspack-newsletters/issues/1906)) ([b4416c4](https://github.com/Automattic/newspack-newsletters/commit/b4416c4dabaea342ddcef7575ceb1cdefa50fffb))

## [3.22.2](https://github.com/Automattic/newspack-newsletters/compare/v3.22.1...v3.22.2) (2025-09-11)


### Bug Fixes

* persist meta populated from layout for new drafts ([#1917](https://github.com/Automattic/newspack-newsletters/issues/1917)) ([f751b1e](https://github.com/Automattic/newspack-newsletters/commit/f751b1e980e22c1d5b9e3c367e8fb693c9a09469))

## [3.22.1](https://github.com/Automattic/newspack-newsletters/compare/v3.22.0...v3.22.1) (2025-09-09)


### Bug Fixes

* cast disable_auto_ads value as boolean ([#1915](https://github.com/Automattic/newspack-newsletters/issues/1915)) ([54f1914](https://github.com/Automattic/newspack-newsletters/commit/54f1914830bd4ad3cbda2cc774f25a45b4d0f58e))

# [3.22.0](https://github.com/Automattic/newspack-newsletters/compare/v3.21.3...v3.22.0) (2025-09-08)


### Bug Fixes

* **ads:** only apply click tracking to ad links ([#1895](https://github.com/Automattic/newspack-newsletters/issues/1895)) ([7d826c8](https://github.com/Automattic/newspack-newsletters/commit/7d826c8f95d582c361745118cc7613b915c5df46))
* respect block spacing when rendering ([#1894](https://github.com/Automattic/newspack-newsletters/issues/1894)) ([8293690](https://github.com/Automattic/newspack-newsletters/commit/8293690828eed85f4dd0276c6f833154dd97cc0c))


### Features

* add support for Foundation Community Remote Data Blocks ([#1898](https://github.com/Automattic/newspack-newsletters/issues/1898)) ([e5fd624](https://github.com/Automattic/newspack-newsletters/commit/e5fd62447a8c332d25340e1f6aed985a28516f14))
* **layouts:** persist auto ads option ([#1896](https://github.com/Automattic/newspack-newsletters/issues/1896)) ([2b3ffac](https://github.com/Automattic/newspack-newsletters/commit/2b3ffacd663f8a997dc67e1830a9f8b765e72e99))

## [3.21.3](https://github.com/Automattic/newspack-newsletters/compare/v3.21.2...v3.21.3) (2025-09-08)


### Bug Fixes

* email html update handling ([#1909](https://github.com/Automattic/newspack-newsletters/issues/1909)) ([2ce2e8c](https://github.com/Automattic/newspack-newsletters/commit/2ce2e8cac9084a8af76015b266d411bd2f16fbe1))

## [3.21.2](https://github.com/Automattic/newspack-newsletters/compare/v3.21.1...v3.21.2) (2025-09-04)


### Bug Fixes

* wait for fetch before clearing list or sublist ids ([#1907](https://github.com/Automattic/newspack-newsletters/issues/1907)) ([1435d61](https://github.com/Automattic/newspack-newsletters/commit/1435d61e133f6fe869d694dfb834d49725de13c8)), closes [#1905](https://github.com/Automattic/newspack-newsletters/issues/1905)

## [3.21.1](https://github.com/Automattic/newspack-newsletters/compare/v3.21.0...v3.21.1) (2025-09-02)


### Bug Fixes

* reset newsletter list when not found in ESP ([#1905](https://github.com/Automattic/newspack-newsletters/issues/1905)) ([3e09b6c](https://github.com/Automattic/newspack-newsletters/commit/3e09b6ccaaa5226cfcf8a4ba7a78b107ca2d6ec9))

# [3.21.0](https://github.com/Automattic/newspack-newsletters/compare/v3.20.0...v3.21.0) (2025-08-25)


### Bug Fixes

* **ads:** only apply click tracking to ad links ([#1895](https://github.com/Automattic/newspack-newsletters/issues/1895)) ([d20b763](https://github.com/Automattic/newspack-newsletters/commit/d20b76313137241e8ab3a589acc17db64bb39c01))
* email styling issues ([#1878](https://github.com/Automattic/newspack-newsletters/issues/1878)) ([e2f9726](https://github.com/Automattic/newspack-newsletters/commit/e2f972658ac287c784ef8949c522f1d489cab79c))
* handle scheduled in past sending ([#1886](https://github.com/Automattic/newspack-newsletters/issues/1886)) ([8143259](https://github.com/Automattic/newspack-newsletters/commit/8143259a342e9a57deca7c9a50b96f655ef99f60))


### Features

* ads placements ([#1880](https://github.com/Automattic/newspack-newsletters/issues/1880)) ([291c82f](https://github.com/Automattic/newspack-newsletters/commit/291c82f23530af041c8e6d815cbb200b968ad02b))
* improve CLI ([#1872](https://github.com/Automattic/newspack-newsletters/issues/1872)) ([aa0b684](https://github.com/Automattic/newspack-newsletters/commit/aa0b68410abc77c21b520ac9d55656aa48ca77ab))
* **subscribe-block:** add modern grid style variation ([#1869](https://github.com/Automattic/newspack-newsletters/issues/1869)) ([3eb1b5d](https://github.com/Automattic/newspack-newsletters/commit/3eb1b5d8acd9a4934de71d6dc78149e8d664887a))

# [3.20.0](https://github.com/Automattic/newspack-newsletters/compare/v3.19.1...v3.20.0) (2025-08-21)


### Features

* **activecampaign:** custom address ID via constant ([#1899](https://github.com/Automattic/newspack-newsletters/issues/1899)) ([47da6b7](https://github.com/Automattic/newspack-newsletters/commit/47da6b7dcb5c28c5f34f6ab03b0002f62117b662))

## [3.19.1](https://github.com/Automattic/newspack-newsletters/compare/v3.19.0...v3.19.1) (2025-08-12)


### Bug Fixes

* **ads:** ads in scheduled newsletters ([#1885](https://github.com/Automattic/newspack-newsletters/issues/1885)) ([a895989](https://github.com/Automattic/newspack-newsletters/commit/a895989acd94f3edeacfbfb342fd3a6158706e90))

# [3.19.0](https://github.com/Automattic/newspack-newsletters/compare/v3.18.0...v3.19.0) (2025-08-11)


### Bug Fixes

* Support Unit Tests for php 8.3 ([#1866](https://github.com/Automattic/newspack-newsletters/issues/1866)) ([03c5ceb](https://github.com/Automattic/newspack-newsletters/commit/03c5ceb311a4ab7718bbf3ca35e13c24b1e7bc7b))


### Features

* **posts-inserter-block:** handle custom bylines ([#1871](https://github.com/Automattic/newspack-newsletters/issues/1871)) ([60f2824](https://github.com/Automattic/newspack-newsletters/commit/60f28248fe8c7ef8cdb8a95f2a67cea9e841be61))
* typo ([#1870](https://github.com/Automattic/newspack-newsletters/issues/1870)) ([5933e4c](https://github.com/Automattic/newspack-newsletters/commit/5933e4ce7d3f73d010dcf16c65b64fea1d9056f0))

# [3.18.0](https://github.com/Automattic/newspack-newsletters/compare/v3.17.0...v3.18.0) (2025-07-28)


### Bug Fixes

* **layout:** only migrate layout defaults from legacy meta if esp is not set to manual ([#1859](https://github.com/Automattic/newspack-newsletters/issues/1859)) ([21d8b1b](https://github.com/Automattic/newspack-newsletters/commit/21d8b1bfd5eb6c3b59cad4936ec904d79a57380d))
* recaptcha settings link ([#1863](https://github.com/Automattic/newspack-newsletters/issues/1863)) ([d43d1e8](https://github.com/Automattic/newspack-newsletters/commit/d43d1e894ce9448079f71fa6101ac27cc80c6ae3))


### Features

* **admin-menu:** remove non-applicable admin menu items ([#1807](https://github.com/Automattic/newspack-newsletters/issues/1807)) ([2d9e379](https://github.com/Automattic/newspack-newsletters/commit/2d9e379cbc8525e2e26306861284f0cec4e89771))

# [3.17.0](https://github.com/Automattic/newspack-newsletters/compare/v3.16.0...v3.17.0) (2025-07-14)


### Bug Fixes

* prevent HTML overwrite when post taken over ([#1849](https://github.com/Automattic/newspack-newsletters/issues/1849)) ([6e95634](https://github.com/Automattic/newspack-newsletters/commit/6e9563408627ce64f9685618ce6ccab56bcc98a9))


### Features

* add a button to unschedule newsletters ([#1844](https://github.com/Automattic/newspack-newsletters/issues/1844)) ([cecbf8f](https://github.com/Automattic/newspack-newsletters/commit/cecbf8f88bbcb761935c44f5e1de005c202567c6))

# [3.16.0](https://github.com/Automattic/newspack-newsletters/compare/v3.15.3...v3.16.0) (2025-06-30)


### Features

* delete contact by email address, not user ID ([#1837](https://github.com/Automattic/newspack-newsletters/issues/1837)) ([5cbf055](https://github.com/Automattic/newspack-newsletters/commit/5cbf055135811b8836844ea01838bd89f06b0545))

## [3.15.3](https://github.com/Automattic/newspack-newsletters/compare/v3.15.2...v3.15.3) (2025-06-26)


### Bug Fixes

* register meta after class init ([#1851](https://github.com/Automattic/newspack-newsletters/issues/1851)) ([6613286](https://github.com/Automattic/newspack-newsletters/commit/661328646fcc8f2903708668b2f3bced6f40e92a))

## [3.15.2](https://github.com/Automattic/newspack-newsletters/compare/v3.15.1...v3.15.2) (2025-06-25)


### Bug Fixes

* trigger release for empty active campaign messages ([8ced47e](https://github.com/Automattic/newspack-newsletters/commit/8ced47e33ded9aefcb047ab63819e1381fd49ee2))

## [3.15.1](https://github.com/Automattic/newspack-newsletters/compare/v3.15.0...v3.15.1) (2025-06-16)


### Bug Fixes

* dont check for current provider before init ([#1836](https://github.com/Automattic/newspack-newsletters/issues/1836)) ([860e71a](https://github.com/Automattic/newspack-newsletters/commit/860e71aa20c66a72a8e10e0650445045111b71e9))

# [3.15.0](https://github.com/Automattic/newspack-newsletters/compare/v3.14.0...v3.15.0) (2025-06-02)


### Bug Fixes

* dont check for current provider before init ([#1836](https://github.com/Automattic/newspack-newsletters/issues/1836)) ([1d95e58](https://github.com/Automattic/newspack-newsletters/commit/1d95e582b68634886249896625829ed4a2cc5747))


### Features

* allow ESP provider extension ([#1828](https://github.com/Automattic/newspack-newsletters/issues/1828)) ([030c71c](https://github.com/Automattic/newspack-newsletters/commit/030c71cacc886751e6b680a7ba87d40706d65862)), closes [#1829](https://github.com/Automattic/newspack-newsletters/issues/1829)
* starting reorganizing esp abstraction ([#1758](https://github.com/Automattic/newspack-newsletters/issues/1758)) ([5fd89fc](https://github.com/Automattic/newspack-newsletters/commit/5fd89fc06d25d146017df61b9d8bd80466495565))

# [3.14.0](https://github.com/Automattic/newspack-newsletters/compare/v3.13.0...v3.14.0) (2025-05-27)


### Features

* dont allow redirect to arbitrary urls ([#1834](https://github.com/Automattic/newspack-newsletters/issues/1834)) ([251f6cc](https://github.com/Automattic/newspack-newsletters/commit/251f6cc3d23fe42a04ff75a72e7624885da3fddd)), closes [#1635](https://github.com/Automattic/newspack-newsletters/issues/1635)

# [3.13.0](https://github.com/Automattic/newspack-newsletters/compare/v3.12.2...v3.13.0) (2025-05-14)


### Bug Fixes

* **admin-menu:** hide advertisers menu from admin dashboard ([#1820](https://github.com/Automattic/newspack-newsletters/issues/1820)) ([a88e198](https://github.com/Automattic/newspack-newsletters/commit/a88e198d83cb8677a300653daa0749bb658951e6))
* **CC:** update existing contact when changing email address ([#1795](https://github.com/Automattic/newspack-newsletters/issues/1795)) ([c44d4d9](https://github.com/Automattic/newspack-newsletters/commit/c44d4d9e2d91be7a2f40177e90d95fd4c376d8ea))
* **editor:** ads config call ([#1813](https://github.com/Automattic/newspack-newsletters/issues/1813)) ([14cfcf0](https://github.com/Automattic/newspack-newsletters/commit/14cfcf0c5399115d8ffc2f425a891debedfa2092))
* **email-html:** button rendering ([#1812](https://github.com/Automattic/newspack-newsletters/issues/1812)) ([0fbb6f5](https://github.com/Automattic/newspack-newsletters/commit/0fbb6f5a295460e90ccc23eda15b4b9729a453a4))
* **email-html:** sanity check for img alt attr ([#1804](https://github.com/Automattic/newspack-newsletters/issues/1804)) ([63d20e6](https://github.com/Automattic/newspack-newsletters/commit/63d20e69ee15e5b7f75d5ff0512506f586c44136))
* **posts-inserter-block:** sponsorship scope handling ([#1802](https://github.com/Automattic/newspack-newsletters/issues/1802)) ([55e265a](https://github.com/Automattic/newspack-newsletters/commit/55e265ade7f0a5bd8fde54a380840f16517b5e19))
* **posts-inserter:** use post date in TZ (instead of GMT) ([#1803](https://github.com/Automattic/newspack-newsletters/issues/1803)) ([60f1b77](https://github.com/Automattic/newspack-newsletters/commit/60f1b7765e673a515edcec838516cba39307cdf5))
* revert [#1820](https://github.com/Automattic/newspack-newsletters/issues/1820) ([#1822](https://github.com/Automattic/newspack-newsletters/issues/1822)) ([0c8dea5](https://github.com/Automattic/newspack-newsletters/commit/0c8dea504349353b0612463040ce64761fd256fd))


### Features

* disable Mailchimp notes ([#1816](https://github.com/Automattic/newspack-newsletters/issues/1816)) ([f3b3f94](https://github.com/Automattic/newspack-newsletters/commit/f3b3f9422334bb7928baa9e4b55aa3f6b4fcdb52))
* **ga4:** dispatch newsletter_signup activity upon signup via block ([#1819](https://github.com/Automattic/newspack-newsletters/issues/1819)) ([548f1c3](https://github.com/Automattic/newspack-newsletters/commit/548f1c30b8b12cc3583b0f619dc482da177dde46))

## [3.12.2](https://github.com/Automattic/newspack-newsletters/compare/v3.12.1...v3.12.2) (2025-04-22)


### Bug Fixes

* ignore sticky posts in the post inserter query when not otherwise manipulated ([#1814](https://github.com/Automattic/newspack-newsletters/issues/1814)) ([1a03722](https://github.com/Automattic/newspack-newsletters/commit/1a03722a9bcbd59d0988962dcc6e929314bf5d81))

## [3.12.1](https://github.com/Automattic/newspack-newsletters/compare/v3.12.0...v3.12.1) (2025-04-21)


### Bug Fixes

* fix error handling for active campaign add tag ([#1818](https://github.com/Automattic/newspack-newsletters/issues/1818)) ([f9a31e1](https://github.com/Automattic/newspack-newsletters/commit/f9a31e101b5570c337292d552270fbdab575dd85))

# [3.12.0](https://github.com/Automattic/newspack-newsletters/compare/v3.11.4...v3.12.0) (2025-04-14)


### Bug Fixes

* update social icon editor CSS selector for WP 6.8 ([#1790](https://github.com/Automattic/newspack-newsletters/issues/1790)) ([0f253f9](https://github.com/Automattic/newspack-newsletters/commit/0f253f9a4b1d3e75f2b12049bc1dd5e9b6d0cc69))


### Features

* add POT file to the plugin ([#1789](https://github.com/Automattic/newspack-newsletters/issues/1789)) ([f3d0066](https://github.com/Automattic/newspack-newsletters/commit/f3d0066e04ae8f0a499e4858fbff9e58727044aa))

## [3.11.4](https://github.com/Automattic/newspack-newsletters/compare/v3.11.3...v3.11.4) (2025-04-11)


### Bug Fixes

* **local-lists:** create contact if adding to local lists only ([#1801](https://github.com/Automattic/newspack-newsletters/issues/1801)) ([08f788a](https://github.com/Automattic/newspack-newsletters/commit/08f788a3125d2669cadfe41cce5bee7b200247a3))

## [3.11.3](https://github.com/Automattic/newspack-newsletters/compare/v3.11.2...v3.11.3) (2025-04-08)


### Bug Fixes

* **ads:** separate sort by position logic ([f473d1e](https://github.com/Automattic/newspack-newsletters/commit/f473d1e012a095e00783aa9c1fe28a96c145d383))

## [3.11.2](https://github.com/Automattic/newspack-newsletters/compare/v3.11.1...v3.11.2) (2025-04-07)


### Bug Fixes

* **ads:** prevent ads with same position from replacing each other ([#1794](https://github.com/Automattic/newspack-newsletters/issues/1794)) ([b77d82f](https://github.com/Automattic/newspack-newsletters/commit/b77d82f1b7a3432741ef20599fb74bb7b858b3b9))

## [3.11.1](https://github.com/Automattic/newspack-newsletters/compare/v3.11.0...v3.11.1) (2025-04-03)


### Bug Fixes

* migrate tags and groups ([#1788](https://github.com/Automattic/newspack-newsletters/issues/1788)) ([efd3509](https://github.com/Automattic/newspack-newsletters/commit/efd35096a60e5d4bbae19c01d0c8a96a6837c226))

# [3.11.0](https://github.com/Automattic/newspack-newsletters/compare/v3.10.1...v3.11.0) (2025-03-31)


### Bug Fixes

* add UTM parameters to link URLs inside newsletter content ([#1776](https://github.com/Automattic/newspack-newsletters/issues/1776)) ([b04cba3](https://github.com/Automattic/newspack-newsletters/commit/b04cba375d51dd8fc79e2ba289447aaa762ed54e))
* **editor:** fix multiple-emails notice for AC and MC ESPs ([#1784](https://github.com/Automattic/newspack-newsletters/issues/1784)) ([974c133](https://github.com/Automattic/newspack-newsletters/commit/974c1330c74a4cdbab97098eac13de2964614176))
* **modal-checkout:** filter post-checkout signup lists ([#1783](https://github.com/Automattic/newspack-newsletters/issues/1783)) ([e4d7af2](https://github.com/Automattic/newspack-newsletters/commit/e4d7af24bea8da5df13d25e7409b506efa8524ec))


### Features

* incorporate the Mailchimp API with a few tweaks ([#1774](https://github.com/Automattic/newspack-newsletters/issues/1774)) ([ee9fb11](https://github.com/Automattic/newspack-newsletters/commit/ee9fb114780e150e7c48293f78e3052eead4d5f9))
* update buttons style in editor ([#1772](https://github.com/Automattic/newspack-newsletters/issues/1772)) ([0ffc150](https://github.com/Automattic/newspack-newsletters/commit/0ffc15095f9ccd5a959239bb44eefdbef09b69ed))

## [3.10.1](https://github.com/Automattic/newspack-newsletters/compare/v3.10.0...v3.10.1) (2025-03-25)


### Bug Fixes

* mailchimp email change sync strategy ([#1787](https://github.com/Automattic/newspack-newsletters/issues/1787)) ([341ad39](https://github.com/Automattic/newspack-newsletters/commit/341ad39175dd29ad6a9050655e05ca346045de47))

# [3.10.0](https://github.com/Automattic/newspack-newsletters/compare/v3.9.2...v3.10.0) (2025-03-18)


### Bug Fixes

* correct data fetching error ([#1765](https://github.com/Automattic/newspack-newsletters/issues/1765)) ([67d954f](https://github.com/Automattic/newspack-newsletters/commit/67d954fafc6e3b212e4e14ab7e9726f467ab6cad))
* handle email change esp sync errors ([#1769](https://github.com/Automattic/newspack-newsletters/issues/1769)) ([ac3b598](https://github.com/Automattic/newspack-newsletters/commit/ac3b59829d101e5e6968f59aa5e7a9ab4e0b6586))
* **modal-checkout:** filter post-checkout signup lists ([#1783](https://github.com/Automattic/newspack-newsletters/issues/1783)) ([6908cc3](https://github.com/Automattic/newspack-newsletters/commit/6908cc316a0a3ced8124aad34141fe6e54e37753))
* **premium:** conditions for premium lists auto-signup ([#1766](https://github.com/Automattic/newspack-newsletters/issues/1766)) ([a850c5e](https://github.com/Automattic/newspack-newsletters/commit/a850c5ed24d10cc258879d41b3ce4ca8d866e1d5))


### Features

* allow email address updates to ESPs ([#1764](https://github.com/Automattic/newspack-newsletters/issues/1764)) ([f472d24](https://github.com/Automattic/newspack-newsletters/commit/f472d245b89e8543a1697005cc8b654fd67a034b))

## [3.9.2](https://github.com/Automattic/newspack-newsletters/compare/v3.9.1...v3.9.2) (2025-03-18)


### Bug Fixes

* registration page event meta ([#1777](https://github.com/Automattic/newspack-newsletters/issues/1777)) ([062b8fb](https://github.com/Automattic/newspack-newsletters/commit/062b8fb3b8655ed03736199ff5c9aa15cee9c506))

## [3.9.1](https://github.com/Automattic/newspack-newsletters/compare/v3.9.0...v3.9.1) (2025-03-05)


### Bug Fixes

* allow for context-specific reader-facing error messsages ([#1754](https://github.com/Automattic/newspack-newsletters/issues/1754)) ([db4e742](https://github.com/Automattic/newspack-newsletters/commit/db4e742f407f2b8ae992f801c298913ca003de15))
* **editor:** allow newsletter editor JS to run for manual ESP ([#1770](https://github.com/Automattic/newspack-newsletters/issues/1770)) ([bc16567](https://github.com/Automattic/newspack-newsletters/commit/bc165678c7d0bece5f7468999152306cefe282b5))

# [3.9.0](https://github.com/Automattic/newspack-newsletters/compare/v3.8.1...v3.9.0) (2025-03-04)


### Features

* add global control for text color and support link color in paragraph block ([#1755](https://github.com/Automattic/newspack-newsletters/issues/1755)) ([b698829](https://github.com/Automattic/newspack-newsletters/commit/b698829e0de71b4242e37d072d56f911fc3b4ddf))
* add threads to supported social icons ([#1761](https://github.com/Automattic/newspack-newsletters/issues/1761)) ([d5b8f24](https://github.com/Automattic/newspack-newsletters/commit/d5b8f24570ca2ed5ff1d2e2919945a2f08b530e9))
* **embed:** update icons ([#1756](https://github.com/Automattic/newspack-newsletters/issues/1756)) ([5a2cf28](https://github.com/Automattic/newspack-newsletters/commit/5a2cf283a22c39516ff6740d822ec86c5109a0ff))
* update plugin with new brand ([#1752](https://github.com/Automattic/newspack-newsletters/issues/1752)) ([6d4b4be](https://github.com/Automattic/newspack-newsletters/commit/6d4b4be1c77405951d0b8d01071ada34b14c7f3a))

## [3.8.1](https://github.com/Automattic/newspack-newsletters/compare/v3.8.0...v3.8.1) (2025-02-17)


### Bug Fixes

* avoid moving cursor when editing Subject input field ([#1748](https://github.com/Automattic/newspack-newsletters/issues/1748)) ([34a5632](https://github.com/Automattic/newspack-newsletters/commit/34a56320bcbf19b750355e3840f9772cae2824ad))

# [3.8.0](https://github.com/Automattic/newspack-newsletters/compare/v3.7.5...v3.8.0) (2025-02-03)


### Bug Fixes

* **mailchimp:** only remove sender email on successful response ([#1739](https://github.com/Automattic/newspack-newsletters/issues/1739)) ([6d912f4](https://github.com/Automattic/newspack-newsletters/commit/6d912f432de4c8f95f8fffe387e6cd897136175b))


### Features

* better reader-facing error messages ([#1745](https://github.com/Automattic/newspack-newsletters/issues/1745)) ([26b39b6](https://github.com/Automattic/newspack-newsletters/commit/26b39b6affb9ba4562fce27e672bba645afcd606))

## [3.7.5](https://github.com/Automattic/newspack-newsletters/compare/v3.7.4...v3.7.5) (2025-01-20)


### Bug Fixes

* **subscribe-block:** add recaptcha error styles ([#1724](https://github.com/Automattic/newspack-newsletters/issues/1724)) ([7725918](https://github.com/Automattic/newspack-newsletters/commit/7725918d8863168d18abb6206f9bc0ae2fa7e32c))

## [3.7.4](https://github.com/Automattic/newspack-newsletters/compare/v3.7.3...v3.7.4) (2025-01-15)


### Bug Fixes

* always allow tracking redirects to site url ([#1738](https://github.com/Automattic/newspack-newsletters/issues/1738)) ([a97788f](https://github.com/Automattic/newspack-newsletters/commit/a97788fac774c7d9149cf0982bf8c60386268ef8))
* retrigger release for PR[#1738](https://github.com/Automattic/newspack-newsletters/issues/1738) ([69ed769](https://github.com/Automattic/newspack-newsletters/commit/69ed769ac1d27ce4b02de96ed12f63c864ea3a3c))

## [3.7.3](https://github.com/Automattic/newspack-newsletters/compare/v3.7.2...v3.7.3) (2025-01-14)


### Bug Fixes

* **recaptcha:** use filter for deciding whether to do reCAPTCHA check ([#1737](https://github.com/Automattic/newspack-newsletters/issues/1737)) ([e75ccfb](https://github.com/Automattic/newspack-newsletters/commit/e75ccfba7b6d31a9e6544c8ab5a2df08e4bbf9e9))

## [3.7.2](https://github.com/Automattic/newspack-newsletters/compare/v3.7.1...v3.7.2) (2025-01-08)


### Bug Fixes

* throw error if we fail to fetch merge fields ([#1732](https://github.com/Automattic/newspack-newsletters/issues/1732)) ([e855d45](https://github.com/Automattic/newspack-newsletters/commit/e855d45c935b0be57bf46571df2ed9ff7c78adaf))

## [3.7.1](https://github.com/Automattic/newspack-newsletters/compare/v3.7.0...v3.7.1) (2024-12-23)


### Bug Fixes

* avoid creating merge fields if we can't get merge fields ([#1729](https://github.com/Automattic/newspack-newsletters/issues/1729)) ([416ac62](https://github.com/Automattic/newspack-newsletters/commit/416ac6243cd0dcf0d77834ae37bb68297f4d0b1b))

# [3.7.0](https://github.com/Automattic/newspack-newsletters/compare/v3.6.0...v3.7.0) (2024-12-16)


### Bug Fixes

* add check to see if color is populated before adding to CSS ([#1719](https://github.com/Automattic/newspack-newsletters/issues/1719)) ([a64a0f2](https://github.com/Automattic/newspack-newsletters/commit/a64a0f29ca456275f16b97fdbffd8fb45db7930e))


### Features

* add support for BlueSky in social links blocks ([#1722](https://github.com/Automattic/newspack-newsletters/issues/1722)) ([b13fbb0](https://github.com/Automattic/newspack-newsletters/commit/b13fbb02feb0b0998969c264bd256a6ef888368d))

# [3.6.0](https://github.com/Automattic/newspack-newsletters/compare/v3.5.0...v3.6.0) (2024-12-09)


### Bug Fixes

* let the exceptions be thrown ([#1698](https://github.com/Automattic/newspack-newsletters/issues/1698)) ([85746b8](https://github.com/Automattic/newspack-newsletters/commit/85746b87de538ceccbc5141c0d6869b6071cad83))


### Features

* **ras-acc:** add reader account creation and login improvements ([#1716](https://github.com/Automattic/newspack-newsletters/issues/1716)) ([4640cae](https://github.com/Automattic/newspack-newsletters/commit/4640cae18706102bafaf2553df9eae93eddc20b9))
* rate limit requests for CC ([#1714](https://github.com/Automattic/newspack-newsletters/issues/1714)) ([ad092ca](https://github.com/Automattic/newspack-newsletters/commit/ad092cab16714da143c604615c4af328d3847535))

# [3.5.0](https://github.com/Automattic/newspack-newsletters/compare/v3.4.0...v3.5.0) (2024-11-25)


### Bug Fixes

* better handle error responses from AC api ([#1707](https://github.com/Automattic/newspack-newsletters/issues/1707)) ([17a21e2](https://github.com/Automattic/newspack-newsletters/commit/17a21e261695acfb229c0f9c13549abc9035aa77))
* **mailchimp:** fatal error with invalid API keys ([#1690](https://github.com/Automattic/newspack-newsletters/issues/1690)) ([b7fd7e6](https://github.com/Automattic/newspack-newsletters/commit/b7fd7e69446aa613bbec16d96002a8f0eb42d3dd))


### Features

* **cli:** add command to synchronize premium lists ([#1699](https://github.com/Automattic/newspack-newsletters/issues/1699)) ([21d15a7](https://github.com/Automattic/newspack-newsletters/commit/21d15a779a6affbe900effb104c2da2e23d0617d))

# [3.4.0](https://github.com/Automattic/newspack-newsletters/compare/v3.3.3...v3.4.0) (2024-11-11)


### Bug Fixes

* avoid double notice components ([#1703](https://github.com/Automattic/newspack-newsletters/issues/1703)) ([b8e9130](https://github.com/Automattic/newspack-newsletters/commit/b8e913013b035e824452566943a4576a304f7527))
* avoid race condition between post-save sync & test ([#1679](https://github.com/Automattic/newspack-newsletters/issues/1679)) ([7bde119](https://github.com/Automattic/newspack-newsletters/commit/7bde1190aa83e09e7b411db25c38884bddb92506))
* avoid Redux usage and related errors in non-newsletter email editors ([#1688](https://github.com/Automattic/newspack-newsletters/issues/1688)) ([d3f1f37](https://github.com/Automattic/newspack-newsletters/commit/d3f1f37a110d663cdd5fb31b8807f8e443eee3b5))
* correct button stacking on sent newsletters ([#1695](https://github.com/Automattic/newspack-newsletters/issues/1695)) ([4e2688e](https://github.com/Automattic/newspack-newsletters/commit/4e2688e01600376d7c0e8dc397c7c9c9f748860d))
* dont html encode ampersands in subject ([#1686](https://github.com/Automattic/newspack-newsletters/issues/1686)) ([f178b23](https://github.com/Automattic/newspack-newsletters/commit/f178b2355cd1198531972eee36939de70524eb93))
* **mailchimp:** avoid duplicate audiences in Mailchimp UIs ([#1685](https://github.com/Automattic/newspack-newsletters/issues/1685)) ([44c1b12](https://github.com/Automattic/newspack-newsletters/commit/44c1b12d54773a0badbaf462175ed42185e70839))
* move Preview, Send buttons to match Publish button location ([#1689](https://github.com/Automattic/newspack-newsletters/issues/1689)) ([72897f2](https://github.com/Automattic/newspack-newsletters/commit/72897f206058af5c63afdd7fd2ab9946a9a0b923))
* preview & send buttons on WP 6.7 ([49450d3](https://github.com/Automattic/newspack-newsletters/commit/49450d32c206435a60e0fbab36f179d75ee2bf46))
* remove behavior to hide post title in newsletter editor ([#1701](https://github.com/Automattic/newspack-newsletters/issues/1701)) ([8a15cf5](https://github.com/Automattic/newspack-newsletters/commit/8a15cf5393f6dcf6cbbe3a326d9e996610711649))


### Features

* display list remote name on settings page ([#1672](https://github.com/Automattic/newspack-newsletters/issues/1672)) ([562d396](https://github.com/Automattic/newspack-newsletters/commit/562d396ea5c5fef5683fe1e37bb2b26690ac4b75))

## [3.3.3](https://github.com/Automattic/newspack-newsletters/compare/v3.3.2...v3.3.3) (2024-11-07)


### Bug Fixes

* **send-lists:** temporary fix for searching many mailchimp audiences ([#1693](https://github.com/Automattic/newspack-newsletters/issues/1693)) ([e41f3b6](https://github.com/Automattic/newspack-newsletters/commit/e41f3b69ed58ea8a2442d56a5a4e7c24d4e81242))

## [3.3.2](https://github.com/Automattic/newspack-newsletters/compare/v3.3.1...v3.3.2) (2024-10-29)


### Bug Fixes

* strip Mailchimp merge tags from email preview text ([#1682](https://github.com/Automattic/newspack-newsletters/issues/1682)) ([ba1609a](https://github.com/Automattic/newspack-newsletters/commit/ba1609a1b803428f40b552088252157f20195428))

## [3.3.1](https://github.com/Automattic/newspack-newsletters/compare/v3.3.0...v3.3.1) (2024-10-29)


### Bug Fixes

* check for error type before logging ([#1684](https://github.com/Automattic/newspack-newsletters/issues/1684)) ([d1e8ef4](https://github.com/Automattic/newspack-newsletters/commit/d1e8ef46789d658ef66b50936c6bb2b3cc6a02fa))

# [3.3.0](https://github.com/Automattic/newspack-newsletters/compare/v3.2.1...v3.3.0) (2024-10-28)


### Bug Fixes

* bugs from Newsletters editor data refactor ([#1671](https://github.com/Automattic/newspack-newsletters/issues/1671)) ([f2917ec](https://github.com/Automattic/newspack-newsletters/commit/f2917ec2a2cf37caa222b03725860a11211b6213))
* fetching and display of sync errors after saving ([#1670](https://github.com/Automattic/newspack-newsletters/issues/1670)) ([cdf2e24](https://github.com/Automattic/newspack-newsletters/commit/cdf2e24b24cc431ee43f917b6a82e2c4cecfc417))
* ignore (but log) local list errors when updating contact lists ([#1664](https://github.com/Automattic/newspack-newsletters/issues/1664)) ([3255f0b](https://github.com/Automattic/newspack-newsletters/commit/3255f0b9e292175337b06b0e8b8b63ff7ab1969b))
* **mailchimp:** unsetting sublists in synced campaign ([#1673](https://github.com/Automattic/newspack-newsletters/issues/1673)) ([57d83fc](https://github.com/Automattic/newspack-newsletters/commit/57d83fc791dc2b42dadf9dee4ee7ebe6a6866bd9))


### Features

* disable newsletter tracking ([#1674](https://github.com/Automattic/newspack-newsletters/issues/1674)) ([c0de01a](https://github.com/Automattic/newspack-newsletters/commit/c0de01a4e947a822b7fd1074af4cf9c19de7e75b))

## [3.2.1](https://github.com/Automattic/newspack-newsletters/compare/v3.2.0...v3.2.1) (2024-10-23)


### Bug Fixes

* adjust nonce logic ([5da024d](https://github.com/Automattic/newspack-newsletters/commit/5da024dae55da707f1081dbfddee6693bce9ec39))

# [3.2.0](https://github.com/Automattic/newspack-newsletters/compare/v3.1.7...v3.2.0) (2024-10-08)


### Bug Fixes

* a typo that came after a refactor ([#1656](https://github.com/Automattic/newspack-newsletters/issues/1656)) ([0ae5b61](https://github.com/Automattic/newspack-newsletters/commit/0ae5b615e2123ca94ca84f795caaedbf2e4afc8b))
* dont send metadata on subscribe ([#1648](https://github.com/Automattic/newspack-newsletters/issues/1648)) ([5b4a059](https://github.com/Automattic/newspack-newsletters/commit/5b4a0591bf91e82c9a9e83c19275c588c9a2426e))
* handle missing Mailchimp API key ([83a0d6f](https://github.com/Automattic/newspack-newsletters/commit/83a0d6f124dd7ed6d4accadaf8403453384b11a8))
* **mailchimp:** allow contacts to resubscribe after unsubscribing ([#1654](https://github.com/Automattic/newspack-newsletters/issues/1654)) ([b76dbc2](https://github.com/Automattic/newspack-newsletters/commit/b76dbc2fefc67244084a060908abcf86bc11e70d))
* **phpcs:** specify path in custom ruleset ref ([#1637](https://github.com/Automattic/newspack-newsletters/issues/1637)) ([28f5b50](https://github.com/Automattic/newspack-newsletters/commit/28f5b509c7eedaa64db283539d604154cca8ce8a))


### Features

* **mailchimp:** parse contact name into merge fields ([#1628](https://github.com/Automattic/newspack-newsletters/issues/1628)) ([aeba65a](https://github.com/Automattic/newspack-newsletters/commit/aeba65aaefbf4aaf29ff18914f94d060d407fa14))
* **styling:** list styles ([e9ea4dd](https://github.com/Automattic/newspack-newsletters/commit/e9ea4dddd18671606fee38bb7affad51a4cbe69f)), closes [#1335](https://github.com/Automattic/newspack-newsletters/issues/1335)


### Reverts

* "chore(deps-dev): bump @wordpress/browserslist-config from 6.5.0 to 6.6.0" ([f08f271](https://github.com/Automattic/newspack-newsletters/commit/f08f271582750069cd38783f524dfdc9fd5d002e))

## [3.1.7](https://github.com/Automattic/newspack-newsletters/compare/v3.1.6...v3.1.7) (2024-10-08)


### Bug Fixes

* dynamically fetch message ID in tests ([#1666](https://github.com/Automattic/newspack-newsletters/issues/1666)) ([fd31f61](https://github.com/Automattic/newspack-newsletters/commit/fd31f61c8393ffe69f1c41ab874b0f6fb8f36cc7))

## [3.1.6](https://github.com/Automattic/newspack-newsletters/compare/v3.1.5...v3.1.6) (2024-09-18)


### Bug Fixes

* automatically retry failed scheduled sends ([#1660](https://github.com/Automattic/newspack-newsletters/issues/1660)) ([e0cca64](https://github.com/Automattic/newspack-newsletters/commit/e0cca64305df9380fd0cda91f68e61bee840f52e))

## [3.1.5](https://github.com/Automattic/newspack-newsletters/compare/v3.1.4...v3.1.5) (2024-09-16)


### Bug Fixes

* **cc:** urlencode query args before passing to CC API ([#1659](https://github.com/Automattic/newspack-newsletters/issues/1659)) ([4f645d2](https://github.com/Automattic/newspack-newsletters/commit/4f645d251e07ed0142f8111aa8e5f667639c9f81))

## [3.1.4](https://github.com/Automattic/newspack-newsletters/compare/v3.1.3...v3.1.4) (2024-09-12)


### Bug Fixes

* handle encoded URLs and block patterns in redirect check ([#1635](https://github.com/Automattic/newspack-newsletters/issues/1635)) ([2fa5d16](https://github.com/Automattic/newspack-newsletters/commit/2fa5d166e3794abdb5242a5e82ed0528f14eaaba))

## [3.1.3](https://github.com/Automattic/newspack-newsletters/compare/v3.1.2...v3.1.3) (2024-09-12)


### Bug Fixes

* active campaign usage report, fetch campaigns in batches ([#1649](https://github.com/Automattic/newspack-newsletters/issues/1649)) ([e8a4155](https://github.com/Automattic/newspack-newsletters/commit/e8a415534f7a2db0c7a308c47792d9583af7cc0a))

## [3.1.2](https://github.com/Automattic/newspack-newsletters/compare/v3.1.1...v3.1.2) (2024-09-09)


### Bug Fixes

* **mailchimp:** remove cache and improve logs when preparing merge fields ([029d92c](https://github.com/Automattic/newspack-newsletters/commit/029d92cae324525758e0c134454c2c2a13721776))

## [3.1.1](https://github.com/Automattic/newspack-newsletters/compare/v3.1.0...v3.1.1) (2024-09-04)


### Bug Fixes

* error handling, tests, CI builds ([#1641](https://github.com/Automattic/newspack-newsletters/issues/1641), [#1642](https://github.com/Automattic/newspack-newsletters/issues/1642)) ([791fdc3](https://github.com/Automattic/newspack-newsletters/commit/791fdc3f8dc2c32ccafe7acea0413acf1440c1bd))

# [3.1.0](https://github.com/Automattic/newspack-newsletters/compare/v3.0.1...v3.1.0) (2024-08-26)


### Bug Fixes

* add preview text fallback to make sure HTML is removed ([b4de251](https://github.com/Automattic/newspack-newsletters/commit/b4de25185894d88b51ea7c1fe7a8c2918c12ce18))
* human-readable timestamp of sent newsletters ([#1590](https://github.com/Automattic/newspack-newsletters/issues/1590)) ([c3ddd29](https://github.com/Automattic/newspack-newsletters/commit/c3ddd29b667151b88402931249c6c2939746836e))
* **subscription-block:** remove default list when misconfigured ([#1608](https://github.com/Automattic/newspack-newsletters/issues/1608)) ([6d0b440](https://github.com/Automattic/newspack-newsletters/commit/6d0b440d71bf09275ada4b0e15faf2781dd6f823))


### Features

* consolidate data flows ([#1602](https://github.com/Automattic/newspack-newsletters/issues/1602)) ([dd56ab8](https://github.com/Automattic/newspack-newsletters/commit/dd56ab89c8a03c7bfb4c3abd1c31b8cb66a0c89f)), closes [#1567](https://github.com/Automattic/newspack-newsletters/issues/1567) [#1593](https://github.com/Automattic/newspack-newsletters/issues/1593) [#1601](https://github.com/Automattic/newspack-newsletters/issues/1601)

## [3.0.1](https://github.com/Automattic/newspack-newsletters/compare/v3.0.0...v3.0.1) (2024-08-19)


### Bug Fixes

* **mailchimp:** selecting mailchimp segments with groups ([#1600](https://github.com/Automattic/newspack-newsletters/issues/1600)) ([a698aaa](https://github.com/Automattic/newspack-newsletters/commit/a698aaa2940a425416324fd7f0266eedc22dc2b1))

# [3.0.0](https://github.com/Automattic/newspack-newsletters/compare/v2.21.2...v3.0.0) (2024-08-13)


### Bug Fixes

* check for valid ID before post type check ([#1610](https://github.com/Automattic/newspack-newsletters/issues/1610)) ([96e21bc](https://github.com/Automattic/newspack-newsletters/commit/96e21bce7cb5beba199144a473fabb6c4169eb10))
* update dependencies to support `@wordpress/scripts` ([#1530](https://github.com/Automattic/newspack-newsletters/issues/1530)) ([2d732f2](https://github.com/Automattic/newspack-newsletters/commit/2d732f21b192ad9151167f93a543c755cd2fa4a1))


### Features

* **campaign-monitor:** deprecation warning and environment constant for CM ([#1579](https://github.com/Automattic/newspack-newsletters/issues/1579)) ([e0374ac](https://github.com/Automattic/newspack-newsletters/commit/e0374ac025bcbb9cdcf3f706c45e4ce31a64ee81))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for updating dependencies in newspack-scripts

* refactor: use proxy script for eslint and stylelint scripts

* fix: peer dependency conflicts

* chore: update newspack-scripts to v5.6.0-alpha.3

* chore: remove ajv dependency

* chore: update lint-staged

* chore: add .stylelintrc.js

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: package-lock.json

* fix: reformat SCSS

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* chore: update newspack-scripts to v5.6.0-alpha.7

* chore: update newspack-scripts to v5.6.0-alpha.8

* chore: add NPM scripts for PHP

* chore: update newspack-components to v3.0.0

* chore: bump newspack-scripts to v5.5.2

## [2.21.2](https://github.com/Automattic/newspack-newsletters/compare/v2.21.1...v2.21.2) (2024-08-12)


### Bug Fixes

* **data-report:** handle missing days in the data report ([#1591](https://github.com/Automattic/newspack-newsletters/issues/1591)) ([fa87cea](https://github.com/Automattic/newspack-newsletters/commit/fa87cea9fd0136b9dd33581ee77c6a8aa0b4377d))

## [2.21.1](https://github.com/Automattic/newspack-newsletters/compare/v2.21.0...v2.21.1) (2024-08-01)


### Bug Fixes

* **layouts:** settings defaults from layout (mailchimp) ([766578a](https://github.com/Automattic/newspack-newsletters/commit/766578a8734f7f425567fb4c5553471e2c715623))

# [2.21.0](https://github.com/Automattic/newspack-newsletters/compare/v2.20.4...v2.21.0) (2024-07-30)


### Bug Fixes

* error handling for Mailchimp `add_contact` method ([#1567](https://github.com/Automattic/newspack-newsletters/issues/1567)) ([eab0802](https://github.com/Automattic/newspack-newsletters/commit/eab08025540c43c07df2dd8bd434b11a049b9ec6))
* nonexistent method, and don't remove the submit button (just the spinner) ([#1565](https://github.com/Automattic/newspack-newsletters/issues/1565)) ([c6bbc8b](https://github.com/Automattic/newspack-newsletters/commit/c6bbc8badddc1868a0ad9798eb11e989f4ce4742))
* **recaptcha:** handle inactive recaptcha ([#1569](https://github.com/Automattic/newspack-newsletters/issues/1569)) ([dc72d91](https://github.com/Automattic/newspack-newsletters/commit/dc72d91c51311765a16b1f951f0663d0f6074ffc))


### Features

* **constant-contact:** enable subscription management in my account ([#1566](https://github.com/Automattic/newspack-newsletters/issues/1566)) ([cc79138](https://github.com/Automattic/newspack-newsletters/commit/cc79138969135c1df151a997716cd8aa2a05559f))

## [2.20.4](https://github.com/Automattic/newspack-newsletters/compare/v2.20.3...v2.20.4) (2024-07-22)


### Bug Fixes

* avoid fatal when provider is manual ([b245716](https://github.com/Automattic/newspack-newsletters/commit/b24571600a34f91cb0f5be9714fe5db71f1e9c0a))

## [2.20.3](https://github.com/Automattic/newspack-newsletters/compare/v2.20.2...v2.20.3) (2024-07-22)


### Bug Fixes

* sync newsletter campaigns on post meta update for all ESPs ([#1556](https://github.com/Automattic/newspack-newsletters/issues/1556)) ([6d028ff](https://github.com/Automattic/newspack-newsletters/commit/6d028ffc7e34101dd1fb30fd5c1d04736ce4ce78))

## [2.20.2](https://github.com/Automattic/newspack-newsletters/compare/v2.20.1...v2.20.2) (2024-07-22)


### Bug Fixes

* **usage-reports:** fix MC usage reports backfill inflated counts ([#1554](https://github.com/Automattic/newspack-newsletters/issues/1554)) ([6befa73](https://github.com/Automattic/newspack-newsletters/commit/6befa73feda77c6fb43b78cfbc046584d6eac53f))

## [2.20.1](https://github.com/Automattic/newspack-newsletters/compare/v2.20.0...v2.20.1) (2024-07-15)


### Bug Fixes

* refresh MJML on save for all newsletter editor CPTs ([#1570](https://github.com/Automattic/newspack-newsletters/issues/1570)) ([03a9365](https://github.com/Automattic/newspack-newsletters/commit/03a9365d995c8a01cda901b33e56be1e75ee0ed7))

# [2.20.0](https://github.com/Automattic/newspack-newsletters/compare/v2.19.2...v2.20.0) (2024-07-15)


### Bug Fixes

* avoid fatal errors on usage reports ([#1548](https://github.com/Automattic/newspack-newsletters/issues/1548)) ([8afa6fb](https://github.com/Automattic/newspack-newsletters/commit/8afa6fb3f65cdb41b5fb1d1257581c7a15b54e3b))
* ensure form_processed hook is always and correctly fired ([#1540](https://github.com/Automattic/newspack-newsletters/issues/1540)) ([e3f4268](https://github.com/Automattic/newspack-newsletters/commit/e3f4268e6fe28c1c645b428bfdb495d5e41dfb5a))
* nonexistent method, and don't remove the submit button (just the spinner) ([#1565](https://github.com/Automattic/newspack-newsletters/issues/1565)) ([406c2f4](https://github.com/Automattic/newspack-newsletters/commit/406c2f49bc8b3366687460ff0eb1568566a7d2eb))
* specifically set the subscription list colour ([#1535](https://github.com/Automattic/newspack-newsletters/issues/1535)) ([ae067c8](https://github.com/Automattic/newspack-newsletters/commit/ae067c8a2b400992225449f70c99095c9fbf88f9))


### Features

* add support for reCAPTCHA v2 ([#1514](https://github.com/Automattic/newspack-newsletters/issues/1514)) ([2f11748](https://github.com/Automattic/newspack-newsletters/commit/2f11748ac3469dce9eed62a1e4849fbca0eeb823))
* **subscribe-block:** update error style; display state-bar only when selected ([#1545](https://github.com/Automattic/newspack-newsletters/issues/1545)) ([07b36bd](https://github.com/Automattic/newspack-newsletters/commit/07b36bd6c46e97e34975cfc693506c53d495968e))

## [2.19.2](https://github.com/Automattic/newspack-newsletters/compare/v2.19.1...v2.19.2) (2024-07-10)


### Bug Fixes

* update header link selector for WP 6.6 ([#1561](https://github.com/Automattic/newspack-newsletters/issues/1561)) ([2282de7](https://github.com/Automattic/newspack-newsletters/commit/2282de7c9866d7df3b69fbb1f53510229f2eba0a))

## [2.19.1](https://github.com/Automattic/newspack-newsletters/compare/v2.19.0...v2.19.1) (2024-07-09)


### Bug Fixes

* remove grid block from newsletters ([#1560](https://github.com/Automattic/newspack-newsletters/issues/1560)) ([e6cb287](https://github.com/Automattic/newspack-newsletters/commit/e6cb287e15a6c1b8395e5e91f2d95609cbf0db4b))

# [2.19.0](https://github.com/Automattic/newspack-newsletters/compare/v2.18.0...v2.19.0) (2024-07-01)


### Bug Fixes

* disable autosaves for newsletters ([#1547](https://github.com/Automattic/newspack-newsletters/issues/1547)) ([2c5a48e](https://github.com/Automattic/newspack-newsletters/commit/2c5a48ef3ab79b4acbcb9c8bacdc3a2dd6572886))
* do not refresh HTML on autosave, and refresh when other meta is saved ([#1543](https://github.com/Automattic/newspack-newsletters/issues/1543)) ([dc26cfd](https://github.com/Automattic/newspack-newsletters/commit/dc26cfd40d75b560893fcd38981a364239720535))
* **email-rendering:** add side margin to list blocks ([#1517](https://github.com/Automattic/newspack-newsletters/issues/1517)) ([f348053](https://github.com/Automattic/newspack-newsletters/commit/f34805352ddb7980a02de85cf5050bc5a5dc1901))
* refresh email MJML + HTML after save, and refresh for preview without saving ([#1526](https://github.com/Automattic/newspack-newsletters/issues/1526)) ([04457a4](https://github.com/Automattic/newspack-newsletters/commit/04457a4b6d4ff04c8ddd7b9ae32e702000493c4d))
* **social-icons:** add x.com icon; filter social icons block icons ([#1516](https://github.com/Automattic/newspack-newsletters/issues/1516)) ([292a374](https://github.com/Automattic/newspack-newsletters/commit/292a3742464d5cdf04579bbad7e7d897813fdbb4))
* **test-email:** remove multi-recipient help text if ESP is AC (no support) ([40fc90c](https://github.com/Automattic/newspack-newsletters/commit/40fc90c2c5c188baf45e60983ffa3051447d3310))
* update newspack-scripts to v5.5.1 ([721d03c](https://github.com/Automattic/newspack-newsletters/commit/721d03c28a2997b2c233b409e1509ec162af6c4d))
* **usage-reports:** handle no lists from MC ([#1505](https://github.com/Automattic/newspack-newsletters/issues/1505)) ([00ab5f4](https://github.com/Automattic/newspack-newsletters/commit/00ab5f41267e45fad5921304730cde1eaf754f03))


### Features

* add spinner to subscribe block submit button ([#1529](https://github.com/Automattic/newspack-newsletters/issues/1529)) ([d22c62c](https://github.com/Automattic/newspack-newsletters/commit/d22c62ced3b2aa3cdddc795eb0eb47d4fae84419))
* ensure regenerator-runtime is available (for WP 6.6) ([#1536](https://github.com/Automattic/newspack-newsletters/issues/1536)) ([b45cbca](https://github.com/Automattic/newspack-newsletters/commit/b45cbcab4d73c5844edf8d1034b131d3d24c7714))

# [2.18.0](https://github.com/Automattic/newspack-newsletters/compare/v2.17.1...v2.18.0) (2024-06-28)


### Features

* **mailchimp:** get historical usage reports ([a238312](https://github.com/Automattic/newspack-newsletters/commit/a2383120f053e7b79a67d2636dd8673f574899b7))

## [2.17.1](https://github.com/Automattic/newspack-newsletters/compare/v2.17.0...v2.17.1) (2024-06-17)


### Bug Fixes

* **mailchimp-usage-reports:** fix total contacts counting ([#1533](https://github.com/Automattic/newspack-newsletters/issues/1533)) ([2eb9a70](https://github.com/Automattic/newspack-newsletters/commit/2eb9a7077a453c0fdb811b72699c7c85f4c2e688))

# [2.17.0](https://github.com/Automattic/newspack-newsletters/compare/v2.16.1...v2.17.0) (2024-06-12)


### Features

* add new modern style variation to block ([#1513](https://github.com/Automattic/newspack-newsletters/issues/1513)) ([a204510](https://github.com/Automattic/newspack-newsletters/commit/a2045104c21d3211d89dc783f09a66de3390ecdb))
* **error-codes:** non-500 error codes where applicable ([d4ec39c](https://github.com/Automattic/newspack-newsletters/commit/d4ec39c1a74b5aea16922c33f218924d922d1ed3))
* save a backup of subscription attempts in a custom DB table ([#1503](https://github.com/Automattic/newspack-newsletters/issues/1503)) ([be9ea3d](https://github.com/Automattic/newspack-newsletters/commit/be9ea3dc46ae58f6157893756d9261d4ef1ca09f))

## [2.16.1](https://github.com/Automattic/newspack-newsletters/compare/v2.16.0...v2.16.1) (2024-06-06)


### Bug Fixes

* **usage-reports:** fix MC reports ([#1521](https://github.com/Automattic/newspack-newsletters/issues/1521)) ([093527e](https://github.com/Automattic/newspack-newsletters/commit/093527e2e8e751d076f23ea35c10d5e920f907dc))

# [2.16.0](https://github.com/Automattic/newspack-newsletters/compare/v2.15.0...v2.16.0) (2024-06-06)


### Features

* enable disabling the newsletter send failure email notification ([b40c6b2](https://github.com/Automattic/newspack-newsletters/commit/b40c6b207eefd5a34c76c2320b440005b8569dae))

# [2.15.0](https://github.com/Automattic/newspack-newsletters/compare/v2.14.4...v2.15.0) (2024-05-28)


### Bug Fixes

* bad auto-merge ([fdfe15f](https://github.com/Automattic/newspack-newsletters/commit/fdfe15f0415754de41b6d7fd642766ed5ca5d837))
* **newsletter-editor:** tag/segment selection ([2589d3a](https://github.com/Automattic/newspack-newsletters/commit/2589d3a9185798dbc9d457fe2cc0bda74b04148c))
* respect buttons alignment and sizing ([#1492](https://github.com/Automattic/newspack-newsletters/issues/1492)) ([0a63a5a](https://github.com/Automattic/newspack-newsletters/commit/0a63a5a66b1328f2268bbf586ab1490ab7d44fbb))


### Features

* **layouts:** save sender and audience in the layout ([#1461](https://github.com/Automattic/newspack-newsletters/issues/1461)) ([aec7bce](https://github.com/Automattic/newspack-newsletters/commit/aec7bce882a422b4f3f77e18cb2a730def64ada1))
* newsletters color palette adjustments ([#1487](https://github.com/Automattic/newspack-newsletters/issues/1487)) ([2b0ecac](https://github.com/Automattic/newspack-newsletters/commit/2b0ecac434e9df877d0b062d36a9f4feb22b8127))
* notify site admin on campaign send failure ([#1434](https://github.com/Automattic/newspack-newsletters/issues/1434)) ([4e95283](https://github.com/Automattic/newspack-newsletters/commit/4e95283cad69e7b3e6d7ce2c0b90d72f48649113))
* **ras-acc:** add helper method to identify premium lists ([#1502](https://github.com/Automattic/newspack-newsletters/issues/1502)) ([2473ea7](https://github.com/Automattic/newspack-newsletters/commit/2473ea7704ba1e999a2b99e331887b0b483eab83))

# [2.15.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.15.0-alpha.2...v2.15.0-alpha.3) (2024-05-20)


### Bug Fixes

* **mailchimp:** handle missing interests in API contact data ([e613f11](https://github.com/Automattic/newspack-newsletters/commit/e613f110be7aad4782463232650b958c4715b226))
* **tracking:** log file processing ([51233a2](https://github.com/Automattic/newspack-newsletters/commit/51233a2823a64891714bb4105676a46e0624ada3))

## [2.14.4](https://github.com/Automattic/newspack-newsletters/compare/v2.14.3...v2.14.4) (2024-05-20)


### Bug Fixes

* **mailchimp:** handle missing interests in API contact data ([e613f11](https://github.com/Automattic/newspack-newsletters/commit/e613f110be7aad4782463232650b958c4715b226))
* **tracking:** log file processing ([51233a2](https://github.com/Automattic/newspack-newsletters/commit/51233a2823a64891714bb4105676a46e0624ada3))

## [2.14.3](https://github.com/Automattic/newspack-newsletters/compare/v2.14.2...v2.14.3) (2024-05-09)


### Bug Fixes

* **mailchimp:** handle lists error gracefully ([3f64323](https://github.com/Automattic/newspack-newsletters/commit/3f6432382a68a3f9238754d5475b083d5197277a))

## [2.14.2](https://github.com/Automattic/newspack-newsletters/compare/v2.14.1...v2.14.2) (2024-05-08)


### Bug Fixes

* **mailchimp:** adding to list(s) ([dcae035](https://github.com/Automattic/newspack-newsletters/commit/dcae0359cff3b60bbcd3fce7a6927d2174482558))

## [2.14.1](https://github.com/Automattic/newspack-newsletters/compare/v2.14.0...v2.14.1) (2024-04-24)


### Bug Fixes

* **mc:** handle multiple groups and multiple tags per API request ([#1490](https://github.com/Automattic/newspack-newsletters/issues/1490)) ([c66cbcf](https://github.com/Automattic/newspack-newsletters/commit/c66cbcf4b01bc568b31c273ac04605e92c4b142e))

# [2.14.0](https://github.com/Automattic/newspack-newsletters/compare/v2.13.3...v2.14.0) (2024-04-24)


### Bug Fixes

* bad auto-merge ([1093bb5](https://github.com/Automattic/newspack-newsletters/commit/1093bb575d5e78accf82898c9d0e7f2406a4d1dd))
* improve URL tracking validation ([#1472](https://github.com/Automattic/newspack-newsletters/issues/1472)) ([fe73d19](https://github.com/Automattic/newspack-newsletters/commit/fe73d1924fe4ffb8e107b1eb55de895e55592de4))


### Features

* **mailchimp:** support tags as subscription lists ([#1471](https://github.com/Automattic/newspack-newsletters/issues/1471)) ([3777737](https://github.com/Automattic/newspack-newsletters/commit/3777737067d00ea5f8b214949f27c15e15e2c60d))

## [2.13.3](https://github.com/Automattic/newspack-newsletters/compare/v2.13.2...v2.13.3) (2024-04-24)


### Bug Fixes

* flush rewrite rules only after verified option update ([fb2ea15](https://github.com/Automattic/newspack-newsletters/commit/fb2ea1553cec859c1abc778215d39f8f10f37a0f))
* obfuscate post ID when fetching HTML content ([e300b5a](https://github.com/Automattic/newspack-newsletters/commit/e300b5a0d2527ecbd5a1033688864bab901c0bd9))


# [2.14.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.14.0-alpha.2...v2.14.0-alpha.3) (2024-04-18)


### Bug Fixes

* **mailchimp:** handle not found list ([3fdfc01](https://github.com/Automattic/newspack-newsletters/commit/3fdfc01bc9e5144a942765f577311c6b6fb81597))
* multiple lists handling ([5722b07](https://github.com/Automattic/newspack-newsletters/commit/5722b0753e2a76a344557834845563e16266b97b)), closes [#1460](https://github.com/Automattic/newspack-newsletters/issues/1460)

## [2.13.2](https://github.com/Automattic/newspack-newsletters/compare/v2.13.1...v2.13.2) (2024-04-17)


### Bug Fixes

* **mailchimp:** handle not found list ([3fdfc01](https://github.com/Automattic/newspack-newsletters/commit/3fdfc01bc9e5144a942765f577311c6b6fb81597))
* multiple lists handling ([5722b07](https://github.com/Automattic/newspack-newsletters/commit/5722b0753e2a76a344557834845563e16266b97b)), closes [#1460](https://github.com/Automattic/newspack-newsletters/issues/1460)

## [2.13.1](https://github.com/Automattic/newspack-newsletters/compare/v2.13.0...v2.13.1) (2024-04-15)


### Bug Fixes

* adding contact to multiple groups within a list ([#1460](https://github.com/Automattic/newspack-newsletters/issues/1460)) ([90408b3](https://github.com/Automattic/newspack-newsletters/commit/90408b30d68abad02b11ca7f9976fdbf259faa89))
* bad auto-merge ([1093bb5](https://github.com/Automattic/newspack-newsletters/commit/1093bb575d5e78accf82898c9d0e7f2406a4d1dd))

# [2.14.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.13.0...v2.14.0-alpha.1) (2024-04-11)


### Bug Fixes

* improve URL tracking validation ([#1472](https://github.com/Automattic/newspack-newsletters/issues/1472)) ([fe73d19](https://github.com/Automattic/newspack-newsletters/commit/fe73d1924fe4ffb8e107b1eb55de895e55592de4))


### Features

* **mailchimp:** support tags as subscription lists ([#1471](https://github.com/Automattic/newspack-newsletters/issues/1471)) ([3777737](https://github.com/Automattic/newspack-newsletters/commit/3777737067d00ea5f8b214949f27c15e15e2c60d))

# [2.13.0](https://github.com/Automattic/newspack-newsletters/compare/v2.12.0...v2.13.0) (2024-04-08)


### Features

* flag to delete all local lists ([#1453](https://github.com/Automattic/newspack-newsletters/issues/1453)) ([d83be0d](https://github.com/Automattic/newspack-newsletters/commit/d83be0d06fbc32c854c9f8aa84df695b94d2d715))

# [2.13.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.12.0...v2.13.0-alpha.1) (2024-03-28)


### Features

* flag to delete all local lists ([#1453](https://github.com/Automattic/newspack-newsletters/issues/1453)) ([d83be0d](https://github.com/Automattic/newspack-newsletters/commit/d83be0d06fbc32c854c9f8aa84df695b94d2d715))

# [2.12.0](https://github.com/Automattic/newspack-newsletters/compare/v2.11.2...v2.12.0) (2024-03-25)


### Bug Fixes

* handle inactive woocommerce ([#1437](https://github.com/Automattic/newspack-newsletters/issues/1437)) ([b369d54](https://github.com/Automattic/newspack-newsletters/commit/b369d542d4884c1963c4d71bebbdac93286569b9))
* **mc:** log add_user_to_lists errors ([#1444](https://github.com/Automattic/newspack-newsletters/issues/1444)) ([c53b02a](https://github.com/Automattic/newspack-newsletters/commit/c53b02a3a1cd5999f7dcb937c5026e829b77614e))


### Features

* update translation strings  ([#1435](https://github.com/Automattic/newspack-newsletters/issues/1435)) ([822c7e1](https://github.com/Automattic/newspack-newsletters/commit/822c7e1ab4a423b32263230d676bf446871c7b20))


### Reverts

* chore(release): 2.12.0-alpha.1 to avoid conflicts [skip ci] ([4ba5000](https://github.com/Automattic/newspack-newsletters/commit/4ba5000cbec32b78bc8b1456d51f278fb20cb153))

## [2.11.2](https://github.com/Automattic/newspack-newsletters/compare/v2.11.1...v2.11.2) (2024-03-07)


### Bug Fixes

* **pixel-tracking:** better logfile handling ([#1450](https://github.com/Automattic/newspack-newsletters/issues/1450)) ([26278ff](https://github.com/Automattic/newspack-newsletters/commit/26278ff9b24a40c209799378aafaf6bd51946d98))

## [2.11.1](https://github.com/Automattic/newspack-newsletters/compare/v2.11.0...v2.11.1) (2024-03-07)


### Bug Fixes

* avoid fatal if no plan rules to read ([#1451](https://github.com/Automattic/newspack-newsletters/issues/1451)) ([d516676](https://github.com/Automattic/newspack-newsletters/commit/d516676206a26a6ec1e023c09666a34546975a24))

# [2.11.0](https://github.com/Automattic/newspack-newsletters/compare/v2.10.4...v2.11.0) (2024-03-04)


### Bug Fixes

* activate newsletter subscribers if needed ([#1421](https://github.com/Automattic/newspack-newsletters/issues/1421)) ([704c804](https://github.com/Automattic/newspack-newsletters/commit/704c804d641d18757c2995133dbece041b2f3384))
* add missing text domains ([#1436](https://github.com/Automattic/newspack-newsletters/issues/1436)) ([fcb76b5](https://github.com/Automattic/newspack-newsletters/commit/fcb76b56fa583a4c31d0f95648501a2f938d28f6))
* **custom-css:** code editor styling ([cb93c9a](https://github.com/Automattic/newspack-newsletters/commit/cb93c9a6c92c88b2399788644f1f846d008d77d1))
* deprecated class property warnings ([3d5efe9](https://github.com/Automattic/newspack-newsletters/commit/3d5efe9184e8975f87a29cbb6a92382770c97fce))
* dynamic class property deprecated warnings [#1431](https://github.com/Automattic/newspack-newsletters/issues/1431) ([d50643e](https://github.com/Automattic/newspack-newsletters/commit/d50643ea7279f3f3d49d6bb03087956c6cdea3ff))
* update translation text domains ([#1429](https://github.com/Automattic/newspack-newsletters/issues/1429)) ([dfbd687](https://github.com/Automattic/newspack-newsletters/commit/dfbd6872547e6c565ac6ab65c2c1156e4033b481))


### Features

* **ci:** add `epic/*` release workflow and rename `master` to `trunk` ([#1414](https://github.com/Automattic/newspack-newsletters/issues/1414)) ([c71ff69](https://github.com/Automattic/newspack-newsletters/commit/c71ff690e5b2c63c8201a52c3838ff888b840425))

# [2.11.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.11.0-alpha.2...v2.11.0-alpha.3) (2024-03-01)


### Bug Fixes

* activate newsletter subscribers if needed ([#1421](https://github.com/Automattic/newspack-newsletters/issues/1421)) ([#1445](https://github.com/Automattic/newspack-newsletters/issues/1445)) ([cac7bae](https://github.com/Automattic/newspack-newsletters/commit/cac7baeb545bf342a59be428b8314970d7c79037))
* decode newletter tracked link before redirect ([#1440](https://github.com/Automattic/newspack-newsletters/issues/1440)) ([0368546](https://github.com/Automattic/newspack-newsletters/commit/03685469559d34a833cf6510637596bea0d3fd54))

## [2.10.4](https://github.com/Automattic/newspack-newsletters/compare/v2.10.3...v2.10.4) (2024-02-29)


### Bug Fixes

* activate newsletter subscribers if needed ([#1421](https://github.com/Automattic/newspack-newsletters/issues/1421)) ([#1445](https://github.com/Automattic/newspack-newsletters/issues/1445)) ([cac7bae](https://github.com/Automattic/newspack-newsletters/commit/cac7baeb545bf342a59be428b8314970d7c79037))

## [2.10.3](https://github.com/Automattic/newspack-newsletters/compare/v2.10.2...v2.10.3) (2024-02-26)


### Bug Fixes

* decode newletter tracked link before redirect ([#1440](https://github.com/Automattic/newspack-newsletters/issues/1440)) ([0368546](https://github.com/Automattic/newspack-newsletters/commit/03685469559d34a833cf6510637596bea0d3fd54))

## [2.10.2](https://github.com/Automattic/newspack-newsletters/compare/v2.10.1...v2.10.2) (2024-02-08)


### Bug Fixes

* catch invalid subscription list errors ([#1411](https://github.com/Automattic/newspack-newsletters/issues/1411)) ([cb275c4](https://github.com/Automattic/newspack-newsletters/commit/cb275c462810e9dafb360a77eb98b17a56c6a90d))

## [2.10.1](https://github.com/Automattic/newspack-newsletters/compare/v2.10.0...v2.10.1) (2024-02-06)


### Reverts

* Revert "feat: use custom capability_type for CPTs (#1398)" ([ec7d7d8](https://github.com/Automattic/newspack-newsletters/commit/ec7d7d84aa34f4e38182b1a21613216cf1416ce7)), closes [#1398](https://github.com/Automattic/newspack-newsletters/issues/1398)

# [2.10.0](https://github.com/Automattic/newspack-newsletters/compare/v2.9.0...v2.10.0) (2024-02-06)


### Bug Fixes

* apply custom editor modal styles only to newspack modals ([#1404](https://github.com/Automattic/newspack-newsletters/issues/1404)) ([90633c5](https://github.com/Automattic/newspack-newsletters/commit/90633c5ef556a034a1a43b01a2fa60377835af01))


### Features

* use custom capability_type for CPTs ([#1398](https://github.com/Automattic/newspack-newsletters/issues/1398)) ([59403a9](https://github.com/Automattic/newspack-newsletters/commit/59403a92ae0ec2b14549dc9c72d8495fca1e926a))

# [2.10.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.9.1-alpha.1...v2.10.0-alpha.1) (2024-02-01)


### Features

* use custom capability_type for CPTs ([#1398](https://github.com/Automattic/newspack-newsletters/issues/1398)) ([59403a9](https://github.com/Automattic/newspack-newsletters/commit/59403a92ae0ec2b14549dc9c72d8495fca1e926a))

## [2.9.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.9.0...v2.9.1-alpha.1) (2024-01-26)


### Bug Fixes

* apply custom editor modal styles only to newspack modals ([#1404](https://github.com/Automattic/newspack-newsletters/issues/1404)) ([90633c5](https://github.com/Automattic/newspack-newsletters/commit/90633c5ef556a034a1a43b01a2fa60377835af01))

# [2.9.0](https://github.com/Automattic/newspack-newsletters/compare/v2.8.3...v2.9.0) (2024-01-25)


### Bug Fixes

* add lodash as dependency for editor.js ([c01aebb](https://github.com/Automattic/newspack-newsletters/commit/c01aebbd2e683ad3c9859c6c05c89a43c505b027))
* memberships & "other" ESP ([6e0402c](https://github.com/Automattic/newspack-newsletters/commit/6e0402cdab04ca918430c4291906c65e9a38f90a))
* prevent the use of `add_contact` for data sync purposes ([#1386](https://github.com/Automattic/newspack-newsletters/issues/1386)) ([2d68c1f](https://github.com/Automattic/newspack-newsletters/commit/2d68c1f3652496d80579e47b6ae82b728679ce6f))
* **renderer:** ensure group blocks use conditional tags ([#1380](https://github.com/Automattic/newspack-newsletters/issues/1380)) ([a1a9722](https://github.com/Automattic/newspack-newsletters/commit/a1a9722a60735205a1aa19fe32155c0015e5f792))
* store and recover lists on membership reactivation ([#1377](https://github.com/Automattic/newspack-newsletters/issues/1377)) ([aa8a8f4](https://github.com/Automattic/newspack-newsletters/commit/aa8a8f4592da689218704b387b058ba24b8ab935))


### Features

* display warning if custom fields meta box is visible ([d595ed4](https://github.com/Automattic/newspack-newsletters/commit/d595ed468f32bd61112927aeb94a84fc8505f40d))
* **tracking:** support UTM coming from the ESP ([#1388](https://github.com/Automattic/newspack-newsletters/issues/1388)) ([77d3a1e](https://github.com/Automattic/newspack-newsletters/commit/77d3a1e3fa73cf6f1fbe9f29d4889ed7718421f4))

# [2.9.0-alpha.5](https://github.com/Automattic/newspack-newsletters/compare/v2.9.0-alpha.4...v2.9.0-alpha.5) (2024-01-24)


### Bug Fixes

* improve AC data report perfomance ([#1405](https://github.com/Automattic/newspack-newsletters/issues/1405)) ([f9fb18d](https://github.com/Automattic/newspack-newsletters/commit/f9fb18d8b2af1abaa39f26fb3032a36d258fee04))

## [2.8.3](https://github.com/Automattic/newspack-newsletters/compare/v2.8.2...v2.8.3) (2024-01-24)


### Bug Fixes

* improve AC data report perfomance ([#1405](https://github.com/Automattic/newspack-newsletters/issues/1405)) ([f9fb18d](https://github.com/Automattic/newspack-newsletters/commit/f9fb18d8b2af1abaa39f26fb3032a36d258fee04))

## [2.8.2](https://github.com/Automattic/newspack-newsletters/compare/v2.8.1...v2.8.2) (2024-01-19)


### Bug Fixes

* get all verified domains from Mailchimp ([3ea41dc](https://github.com/Automattic/newspack-newsletters/commit/3ea41dc141222658a7c2d70522d15c1c755ff142))

## [2.8.1](https://github.com/Automattic/newspack-newsletters/compare/v2.8.0...v2.8.1) (2024-01-15)


### Bug Fixes

* always use cache to retrieve groups ([#1378](https://github.com/Automattic/newspack-newsletters/issues/1378)) ([6a4e59c](https://github.com/Automattic/newspack-newsletters/commit/6a4e59cf294ea05b0192bb0d49810363de12afb1))

# [2.8.0](https://github.com/Automattic/newspack-newsletters/compare/v2.7.0...v2.8.0) (2024-01-08)


### Features

* add Constant Contact usge report ([#1366](https://github.com/Automattic/newspack-newsletters/issues/1366)) ([a249b4c](https://github.com/Automattic/newspack-newsletters/commit/a249b4cc02ae1ad24860ddc4307f20ecc6bb498c))

# [2.8.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.7.0...v2.8.0-alpha.1) (2023-12-15)


### Features

* add Constant Contact usge report ([#1366](https://github.com/Automattic/newspack-newsletters/issues/1366)) ([a249b4c](https://github.com/Automattic/newspack-newsletters/commit/a249b4cc02ae1ad24860ddc4307f20ecc6bb498c))

# [2.7.0](https://github.com/Automattic/newspack-newsletters/compare/v2.6.2...v2.7.0) (2023-12-11)


### Bug Fixes

* add layout styles to newsletter ([#1353](https://github.com/Automattic/newspack-newsletters/issues/1353)) ([1abbfdf](https://github.com/Automattic/newspack-newsletters/commit/1abbfdf18210e3ded94e2adbfb44a4e575bbbbaf))
* **editor:** ensure localized variables while editing patterns ([#1360](https://github.com/Automattic/newspack-newsletters/issues/1360)) ([3ab1bdb](https://github.com/Automattic/newspack-newsletters/commit/3ab1bdb2ef3d3c214d89d71dda57942aab818e4c))


### Features

* active-campaigns data report ([#1338](https://github.com/Automattic/newspack-newsletters/issues/1338)) ([d368bbd](https://github.com/Automattic/newspack-newsletters/commit/d368bbd3ee4429549c255c914f734ddf61b22903))
* add campaign monitor usage report ([#1346](https://github.com/Automattic/newspack-newsletters/issues/1346)) ([1231510](https://github.com/Automattic/newspack-newsletters/commit/1231510a7612acec6be5a4a23fcb68b2dddeb9e5))

# [2.7.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.7.0-alpha.2...v2.7.0-alpha.3) (2023-12-05)


### Bug Fixes

* **mailchimp:** prevent merge fields fetch throwing fatal error ([#1376](https://github.com/Automattic/newspack-newsletters/issues/1376)) ([7d64225](https://github.com/Automattic/newspack-newsletters/commit/7d64225f6e7347eab8b0a6c1a9dd83dd6e8e7c9a))

## [2.6.2](https://github.com/Automattic/newspack-newsletters/compare/v2.6.1...v2.6.2) (2023-12-05)


### Bug Fixes

* **mailchimp:** prevent merge fields fetch throwing fatal error ([#1376](https://github.com/Automattic/newspack-newsletters/issues/1376)) ([7d64225](https://github.com/Automattic/newspack-newsletters/commit/7d64225f6e7347eab8b0a6c1a9dd83dd6e8e7c9a))

## [2.6.1](https://github.com/Automattic/newspack-newsletters/compare/v2.6.0...v2.6.1) (2023-12-04)


### Bug Fixes

* detect and ignore duplicate merge fields ([#1370](https://github.com/Automattic/newspack-newsletters/issues/1370)) ([46bb23a](https://github.com/Automattic/newspack-newsletters/commit/46bb23a32c4acfd0cae2d14001ac7e7fd13779f4)), closes [#1368](https://github.com/Automattic/newspack-newsletters/issues/1368)

# [2.6.0](https://github.com/Automattic/newspack-newsletters/compare/v2.5.1...v2.6.0) (2023-11-27)


### Bug Fixes

* add layout styles to newsletter ([#1353](https://github.com/Automattic/newspack-newsletters/issues/1353)) ([c6a4ce3](https://github.com/Automattic/newspack-newsletters/commit/c6a4ce34416b4dc507ee0b260d05d6780180dab4))


### Features

* add editorconfig file ([#1352](https://github.com/Automattic/newspack-newsletters/issues/1352)) ([0be9449](https://github.com/Automattic/newspack-newsletters/commit/0be94491f164bed436bf7a1cbd19eda6fa6703ee))
* change usage report signature ([#1349](https://github.com/Automattic/newspack-newsletters/issues/1349)) ([1db6532](https://github.com/Automattic/newspack-newsletters/commit/1db6532aeb0462354362eac201e18847bce59c6d))
* **subscription:** support async `add_contact()` ([#1344](https://github.com/Automattic/newspack-newsletters/issues/1344)) ([82e9dc1](https://github.com/Automattic/newspack-newsletters/commit/82e9dc1da26ca009f1f320f88f4b729df287b908))

# [2.6.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.6.0-alpha.2...v2.6.0-alpha.3) (2023-11-21)


### Bug Fixes

* add layout styles to newsletter ([#1353](https://github.com/Automattic/newspack-newsletters/issues/1353)) ([c6a4ce3](https://github.com/Automattic/newspack-newsletters/commit/c6a4ce34416b4dc507ee0b260d05d6780180dab4))

# [2.6.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v2.6.0-alpha.1...v2.6.0-alpha.2) (2023-11-16)


### Bug Fixes

* **ras:** contact syncing to mailchimp ([#1355](https://github.com/Automattic/newspack-newsletters/issues/1355)) ([3068d51](https://github.com/Automattic/newspack-newsletters/commit/3068d511b9f5e5632c22c7a964c970c968e66308))

## [2.5.1](https://github.com/Automattic/newspack-newsletters/compare/v2.5.0...v2.5.1) (2023-11-16)


### Bug Fixes

* **ras:** contact syncing to mailchimp ([#1355](https://github.com/Automattic/newspack-newsletters/issues/1355)) ([3068d51](https://github.com/Automattic/newspack-newsletters/commit/3068d511b9f5e5632c22c7a964c970c968e66308))

# [2.5.0](https://github.com/Automattic/newspack-newsletters/compare/v2.4.2...v2.5.0) (2023-11-13)


### Bug Fixes

* add margin to lists and list style position ([#1335](https://github.com/Automattic/newspack-newsletters/issues/1335)) ([e75bb42](https://github.com/Automattic/newspack-newsletters/commit/e75bb4214126fa3bece903b25b68c38a5d36c5b7))
* init modal header misalignment ([#1322](https://github.com/Automattic/newspack-newsletters/issues/1322)) ([5913c54](https://github.com/Automattic/newspack-newsletters/commit/5913c540542924208afa08554f0422ad7858dd37))
* restrict conditional content to newsletters ([#1340](https://github.com/Automattic/newspack-newsletters/issues/1340)) ([e8adde1](https://github.com/Automattic/newspack-newsletters/commit/e8adde134052c8a30ea12bc1f77a5bacf2b18366))


### Features

* mailchimp usage data report ([#1331](https://github.com/Automattic/newspack-newsletters/issues/1331)) ([4fa7386](https://github.com/Automattic/newspack-newsletters/commit/4fa738695f6c954330dc39b959a4e4714744fb43))

## [2.4.2](https://github.com/Automattic/newspack-newsletters/compare/v2.4.1...v2.4.2) (2023-11-06)


### Bug Fixes

* **tracking:** dont flush options cache on log processing ([#1341](https://github.com/Automattic/newspack-newsletters/issues/1341)) ([c693384](https://github.com/Automattic/newspack-newsletters/commit/c6933842c2c85c132d5f82e9b57bcb0edf581b4a))

## [2.4.1](https://github.com/Automattic/newspack-newsletters/compare/v2.4.0...v2.4.1) (2023-11-01)


### Bug Fixes

* active ad start/end date check ([#1337](https://github.com/Automattic/newspack-newsletters/issues/1337)) ([ebdad86](https://github.com/Automattic/newspack-newsletters/commit/ebdad869a690a06d6f276723628b6e7f5ba9234c))

# [2.4.0](https://github.com/Automattic/newspack-newsletters/compare/v2.3.2...v2.4.0) (2023-10-31)


### Bug Fixes

* correct newsletter editor alignment with FSE ([#1311](https://github.com/Automattic/newspack-newsletters/issues/1311)) ([2b44248](https://github.com/Automattic/newspack-newsletters/commit/2b4424852471ea791a0c3df97acb192ebec947c2))


### Features

* **subscription-form:** custom checkbox state for lists ([#1313](https://github.com/Automattic/newspack-newsletters/issues/1313)) ([ba27609](https://github.com/Automattic/newspack-newsletters/commit/ba2760987013f50f57ed1871f037c8cdea158c5c))

# [2.4.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.4.0-alpha.2...v2.4.0-alpha.3) (2023-10-31)


### Bug Fixes

* **tracking:** make the log cycling strategy default for pixel tracking ([#1336](https://github.com/Automattic/newspack-newsletters/issues/1336)) ([e08dd3a](https://github.com/Automattic/newspack-newsletters/commit/e08dd3a024eb62734377c154cb8065b40deb6fad))

## [2.3.2](https://github.com/Automattic/newspack-newsletters/compare/v2.3.1...v2.3.2) (2023-10-31)


### Bug Fixes

* **tracking:** make the log cycling strategy default for pixel tracking ([#1336](https://github.com/Automattic/newspack-newsletters/issues/1336)) ([e08dd3a](https://github.com/Automattic/newspack-newsletters/commit/e08dd3a024eb62734377c154cb8065b40deb6fad))

## [2.3.1](https://github.com/Automattic/newspack-newsletters/compare/v2.3.0...v2.3.1) (2023-10-30)


### Bug Fixes

* **activecampaign:** increase request timeout for APIs v1 and v3 ([#1333](https://github.com/Automattic/newspack-newsletters/issues/1333)) ([42545b2](https://github.com/Automattic/newspack-newsletters/commit/42545b22172c2b6ef56c2f7a720b304cb6d6203f))

# [2.3.0](https://github.com/Automattic/newspack-newsletters/compare/v2.2.1...v2.3.0) (2023-10-26)


### Features

* **tracking:** experimental pixel tracking through log files ([#1332](https://github.com/Automattic/newspack-newsletters/issues/1332)) ([b48d154](https://github.com/Automattic/newspack-newsletters/commit/b48d1546e64d5dca7297fb07ff3de47b02640b51))

## [2.2.1](https://github.com/Automattic/newspack-newsletters/compare/v2.2.0...v2.2.1) (2023-10-10)


### Bug Fixes

* **tracking:** exit on pixel validation checks ([#1315](https://github.com/Automattic/newspack-newsletters/issues/1315)) ([83fd227](https://github.com/Automattic/newspack-newsletters/commit/83fd227d92e1b24e6e49309eddc7ba7d755086f5))

# [2.2.0](https://github.com/Automattic/newspack-newsletters/compare/v2.1.4...v2.2.0) (2023-10-09)


### Bug Fixes

* newsletterData and ActiveCampaign editor tweaks ([#1316](https://github.com/Automattic/newspack-newsletters/issues/1316)) ([574b5e0](https://github.com/Automattic/newspack-newsletters/commit/574b5e0640212b35f90bddce5f761c21aa74e89b))
* newsletterData handling and editor improvements ([#1287](https://github.com/Automattic/newspack-newsletters/issues/1287)) ([f42b387](https://github.com/Automattic/newspack-newsletters/commit/f42b387cbac7ea4bca8d6f5d9524e634a7edd523))
* prevent duplicate js plugin registration ([#1298](https://github.com/Automattic/newspack-newsletters/issues/1298)) ([11efaa7](https://github.com/Automattic/newspack-newsletters/commit/11efaa7841374e0b8abe52a44bbe77f30f645e23))


### Features

* add alt attr to mj-image elements ([#1306](https://github.com/Automattic/newspack-newsletters/issues/1306)) ([f7f1fa5](https://github.com/Automattic/newspack-newsletters/commit/f7f1fa558307aac2a0d88b38eed66f23cff43c38))
* add color picker for the subscribe block button ([#1283](https://github.com/Automattic/newspack-newsletters/issues/1283)) ([000188a](https://github.com/Automattic/newspack-newsletters/commit/000188a13d67dbb41c9756f6e4e87c7d476d3403))
* log send errors ([#1299](https://github.com/Automattic/newspack-newsletters/issues/1299)) ([3c12596](https://github.com/Automattic/newspack-newsletters/commit/3c12596e2787703a93066f193a847a9e9e605e8c))

# [2.2.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v2.2.0-alpha.2...v2.2.0-alpha.3) (2023-10-09)


### Bug Fixes

* newsletterData and ActiveCampaign editor tweaks ([#1316](https://github.com/Automattic/newspack-newsletters/issues/1316)) ([574b5e0](https://github.com/Automattic/newspack-newsletters/commit/574b5e0640212b35f90bddce5f761c21aa74e89b))


### Features

* add alt attr to mj-image elements ([#1306](https://github.com/Automattic/newspack-newsletters/issues/1306)) ([f7f1fa5](https://github.com/Automattic/newspack-newsletters/commit/f7f1fa558307aac2a0d88b38eed66f23cff43c38))
* log send errors ([#1299](https://github.com/Automattic/newspack-newsletters/issues/1299)) ([3c12596](https://github.com/Automattic/newspack-newsletters/commit/3c12596e2787703a93066f193a847a9e9e605e8c))

# [2.2.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v2.2.0-alpha.1...v2.2.0-alpha.2) (2023-10-05)


### Bug Fixes

* execute tracking in an earlier hook ([#1314](https://github.com/Automattic/newspack-newsletters/issues/1314)) ([e48aeab](https://github.com/Automattic/newspack-newsletters/commit/e48aeabeacd0adc8892db054b9c2299abdb0f913))

## [2.1.4](https://github.com/Automattic/newspack-newsletters/compare/v2.1.3...v2.1.4) (2023-10-05)


### Bug Fixes

* execute tracking in an earlier hook ([#1314](https://github.com/Automattic/newspack-newsletters/issues/1314)) ([e48aeab](https://github.com/Automattic/newspack-newsletters/commit/e48aeabeacd0adc8892db054b9c2299abdb0f913))

## [2.1.3](https://github.com/Automattic/newspack-newsletters/compare/v2.1.2...v2.1.3) (2023-09-29)


### Bug Fixes

* remove honeypot field if using reCAPTCHA ([#1301](https://github.com/Automattic/newspack-newsletters/issues/1301)) ([772ca53](https://github.com/Automattic/newspack-newsletters/commit/772ca53847a8d1f6864a2ec9afc830ca0bd5a189))

## [2.1.2](https://github.com/Automattic/newspack-newsletters/compare/v2.1.1...v2.1.2) (2023-09-28)


### Bug Fixes

* **activecampaign:** allow link tracking to be disabled ([#1302](https://github.com/Automattic/newspack-newsletters/issues/1302)) ([670d856](https://github.com/Automattic/newspack-newsletters/commit/670d856591cd861741c2d10c901a651b71c2c489))

## [2.1.1](https://github.com/Automattic/newspack-newsletters/compare/v2.1.0...v2.1.1) (2023-09-26)


### Bug Fixes

* avoid duplicate firing of add_contact ([#1300](https://github.com/Automattic/newspack-newsletters/issues/1300)) ([80fe4c4](https://github.com/Automattic/newspack-newsletters/commit/80fe4c4e5e779c4a625ee8964fc3d47aed880a6b))

# [2.1.0](https://github.com/Automattic/newspack-newsletters/compare/v2.0.1...v2.1.0) (2023-09-25)


### Bug Fixes

* buttons' border radius, width, padding and colors ([#1276](https://github.com/Automattic/newspack-newsletters/issues/1276)) ([7b3e0f3](https://github.com/Automattic/newspack-newsletters/commit/7b3e0f38f64e5a8cdf75873872f5928b10a94d9d))


### Features

* add `newsletter_id` to the `newspack_newsletters_render_mjml_component` filter ([#1292](https://github.com/Automattic/newspack-newsletters/issues/1292)) ([392991b](https://github.com/Automattic/newspack-newsletters/commit/392991bc0eee8acce91817fd148a5e89f2d9f295))

# [2.1.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v2.0.1...v2.1.0-alpha.1) (2023-09-21)


### Bug Fixes

* buttons' border radius, width, padding and colors ([#1276](https://github.com/Automattic/newspack-newsletters/issues/1276)) ([7b3e0f3](https://github.com/Automattic/newspack-newsletters/commit/7b3e0f38f64e5a8cdf75873872f5928b10a94d9d))


### Features

* add `newsletter_id` to the `newspack_newsletters_render_mjml_component` filter ([#1292](https://github.com/Automattic/newspack-newsletters/issues/1292)) ([392991b](https://github.com/Automattic/newspack-newsletters/commit/392991bc0eee8acce91817fd148a5e89f2d9f295))

## [2.0.1](https://github.com/Automattic/newspack-newsletters/compare/v2.0.0...v2.0.1) (2023-09-18)


### Bug Fixes

* crash when adding underwritten posts to Posts Inserter ([#1289](https://github.com/Automattic/newspack-newsletters/issues/1289)) ([9964766](https://github.com/Automattic/newspack-newsletters/commit/996476689dd763a9010214151e921347f7744e0b))
* fatal error from nonexistent class in main plugin ([#1288](https://github.com/Automattic/newspack-newsletters/issues/1288)) ([ae27d30](https://github.com/Automattic/newspack-newsletters/commit/ae27d304d3783d0a103ed0fa3613cfa16bab83fd))

# [2.0.0](https://github.com/Automattic/newspack-newsletters/compare/v1.68.0...v2.0.0) (2023-09-13)


### Bug Fixes

* avoid reprocessing links ([#1277](https://github.com/Automattic/newspack-newsletters/issues/1277)) ([f1822d3](https://github.com/Automattic/newspack-newsletters/commit/f1822d3a12ff18e579d987b201af20a434caf60d))
* PHP warning if block is missing backgroundColor attribute ([afa4859](https://github.com/Automattic/newspack-newsletters/commit/afa48595fc69ed0c2ce3501d16f3bda1f5e33b6b))
* **posts-inserter:** avoid conflicting UI state ([#1280](https://github.com/Automattic/newspack-newsletters/issues/1280)) ([5f87423](https://github.com/Automattic/newspack-newsletters/commit/5f87423f9b72484e373057955ff96b727a6ebdfb))


* Merge pull request #1273 from Automattic/master ([f9185b3](https://github.com/Automattic/newspack-newsletters/commit/f9185b3ad5a40b1a8630cc824e4f27b957e6e466)), closes [#1273](https://github.com/Automattic/newspack-newsletters/issues/1273)


### Features

* add filters to support additional custom blocks ([#1253](https://github.com/Automattic/newspack-newsletters/issues/1253)) ([b113925](https://github.com/Automattic/newspack-newsletters/commit/b113925e081222768b31f59afe51138b628338c8))
* revenue optimizations ([#1272](https://github.com/Automattic/newspack-newsletters/issues/1272)) ([e26e5b9](https://github.com/Automattic/newspack-newsletters/commit/e26e5b9e7468af22a73c314493d83fc92ac14dc0)), closes [#1189](https://github.com/Automattic/newspack-newsletters/issues/1189) [#1232](https://github.com/Automattic/newspack-newsletters/issues/1232) [#1241](https://github.com/Automattic/newspack-newsletters/issues/1241) [#1237](https://github.com/Automattic/newspack-newsletters/issues/1237) [#1259](https://github.com/Automattic/newspack-newsletters/issues/1259) [#1256](https://github.com/Automattic/newspack-newsletters/issues/1256) [#1261](https://github.com/Automattic/newspack-newsletters/issues/1261) [#1249](https://github.com/Automattic/newspack-newsletters/issues/1249) [#1262](https://github.com/Automattic/newspack-newsletters/issues/1262) [#1269](https://github.com/Automattic/newspack-newsletters/issues/1269) [#1263](https://github.com/Automattic/newspack-newsletters/issues/1263)


### BREAKING CHANGES

* Temporarily removes the Letterhead API integration for fetching newsletter ads

Alpha release Sep 06
* Temporarily deprecates the Letterhead newsletter ads integration until we can test it more thoroughly.

# [2.0.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v2.0.0-alpha.1...v2.0.0-alpha.2) (2023-09-12)


### Bug Fixes

* avoid reprocessing links ([#1277](https://github.com/Automattic/newspack-newsletters/issues/1277)) ([f1822d3](https://github.com/Automattic/newspack-newsletters/commit/f1822d3a12ff18e579d987b201af20a434caf60d))
* PHP warning if block is missing backgroundColor attribute ([afa4859](https://github.com/Automattic/newspack-newsletters/commit/afa48595fc69ed0c2ce3501d16f3bda1f5e33b6b))

# [2.0.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.68.0...v2.0.0-alpha.1) (2023-09-06)


* Merge pull request #1273 from Automattic/master ([f9185b3](https://github.com/Automattic/newspack-newsletters/commit/f9185b3ad5a40b1a8630cc824e4f27b957e6e466)), closes [#1273](https://github.com/Automattic/newspack-newsletters/issues/1273)


### Features

* add filters to support additional custom blocks ([#1253](https://github.com/Automattic/newspack-newsletters/issues/1253)) ([b113925](https://github.com/Automattic/newspack-newsletters/commit/b113925e081222768b31f59afe51138b628338c8))
* revenue optimizations ([#1272](https://github.com/Automattic/newspack-newsletters/issues/1272)) ([e26e5b9](https://github.com/Automattic/newspack-newsletters/commit/e26e5b9e7468af22a73c314493d83fc92ac14dc0)), closes [#1189](https://github.com/Automattic/newspack-newsletters/issues/1189) [#1232](https://github.com/Automattic/newspack-newsletters/issues/1232) [#1241](https://github.com/Automattic/newspack-newsletters/issues/1241) [#1237](https://github.com/Automattic/newspack-newsletters/issues/1237) [#1259](https://github.com/Automattic/newspack-newsletters/issues/1259) [#1256](https://github.com/Automattic/newspack-newsletters/issues/1256) [#1261](https://github.com/Automattic/newspack-newsletters/issues/1261) [#1249](https://github.com/Automattic/newspack-newsletters/issues/1249) [#1262](https://github.com/Automattic/newspack-newsletters/issues/1262) [#1269](https://github.com/Automattic/newspack-newsletters/issues/1269) [#1263](https://github.com/Automattic/newspack-newsletters/issues/1263)


### BREAKING CHANGES

* Temporarily removes the Letterhead API integration for fetching newsletter ads

Alpha release Sep 06
* Temporarily deprecates the Letterhead newsletter ads integration until we can test it more thoroughly.

# [1.68.0](https://github.com/Automattic/newspack-newsletters/compare/v1.67.7...v1.68.0) (2023-09-06)


### Features

* add filters to support additional custom blocks ([#1270](https://github.com/Automattic/newspack-newsletters/issues/1270)) ([2fda0aa](https://github.com/Automattic/newspack-newsletters/commit/2fda0aaab9dfae2a82190187f8635efc7a21fafe))

## [1.67.7](https://github.com/Automattic/newspack-newsletters/compare/v1.67.6...v1.67.7) (2023-08-24)


### Bug Fixes

* register before adding contact ([#1250](https://github.com/Automattic/newspack-newsletters/issues/1250)) ([27dce29](https://github.com/Automattic/newspack-newsletters/commit/27dce292036447bb9a4f93a4c555e302b8e2837b))

## [1.67.7-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.67.6...v1.67.7-alpha.1) (2023-08-21)


### Bug Fixes

* register before adding contact ([#1250](https://github.com/Automattic/newspack-newsletters/issues/1250)) ([27dce29](https://github.com/Automattic/newspack-newsletters/commit/27dce292036447bb9a4f93a4c555e302b8e2837b))

## [1.67.6](https://github.com/Automattic/newspack-newsletters/compare/v1.67.5...v1.67.6) (2023-08-21)


### Bug Fixes

* **subscription-form:** return if unable to add contact ([#1242](https://github.com/Automattic/newspack-newsletters/issues/1242)) ([9b6923e](https://github.com/Automattic/newspack-newsletters/commit/9b6923e9dec6aced7fa20aa281a17414c1a8e6fe))

## [1.67.5](https://github.com/Automattic/newspack-newsletters/compare/v1.67.4...v1.67.5) (2023-08-16)


### Bug Fixes

* update api_v1 calls ([ad12e74](https://github.com/Automattic/newspack-newsletters/commit/ad12e74fe5c5e48591c01139bb76393c41d6d279))

## [1.67.4](https://github.com/Automattic/newspack-newsletters/compare/v1.67.3...v1.67.4) (2023-08-10)


### Bug Fixes

* allow multiple subscribe forms to be submitted on one page ([#1231](https://github.com/Automattic/newspack-newsletters/issues/1231)) ([0066ede](https://github.com/Automattic/newspack-newsletters/commit/0066ede0f0aa1fd46535baea97602b2beed4d2af))

## [1.67.3](https://github.com/Automattic/newspack-newsletters/compare/v1.67.2...v1.67.3) (2023-08-07)


### Bug Fixes

* disable autocomplete for recaptcha input ([#1203](https://github.com/Automattic/newspack-newsletters/issues/1203)) ([5d899c0](https://github.com/Automattic/newspack-newsletters/commit/5d899c0649cbb3a114b3ac6216e28741978849cf))
* **subscription-block:** css on tablet/mobile preview ([#1218](https://github.com/Automattic/newspack-newsletters/issues/1218)) ([d0539cc](https://github.com/Automattic/newspack-newsletters/commit/d0539cc093b45b99dade1963ba23c8977ff128ba))

## [1.67.3-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.67.2...v1.67.3-alpha.1) (2023-07-20)


### Bug Fixes

* disable autocomplete for recaptcha input ([#1203](https://github.com/Automattic/newspack-newsletters/issues/1203)) ([5d899c0](https://github.com/Automattic/newspack-newsletters/commit/5d899c0649cbb3a114b3ac6216e28741978849cf))
* **subscription-block:** css on tablet/mobile preview ([#1218](https://github.com/Automattic/newspack-newsletters/issues/1218)) ([d0539cc](https://github.com/Automattic/newspack-newsletters/commit/d0539cc093b45b99dade1963ba23c8977ff128ba))

## [1.67.2](https://github.com/Automattic/newspack-newsletters/compare/v1.67.1...v1.67.2) (2023-07-04)


### Bug Fixes

* preserve list order on subscribe block ([#1211](https://github.com/Automattic/newspack-newsletters/issues/1211)) ([e712735](https://github.com/Automattic/newspack-newsletters/commit/e71273582e2de5c3c77e45bc59c1c4cdc6f2c8b8))

## [1.67.1](https://github.com/Automattic/newspack-newsletters/compare/v1.67.0...v1.67.1) (2023-07-03)


### Bug Fixes

* add a width to the email inputs to prevent overflow ([#1196](https://github.com/Automattic/newspack-newsletters/issues/1196)) ([058a14a](https://github.com/Automattic/newspack-newsletters/commit/058a14aaf42244a9bf71804f57a92c4ddf43fb1d))
* allow public newsletters in author archives ([#1197](https://github.com/Automattic/newspack-newsletters/issues/1197)) ([d3eb018](https://github.com/Automattic/newspack-newsletters/commit/d3eb0184499e39520d1f4da30879994c98d24d11))
* **subscribe-block:** allow spaces in submit button ([#1202](https://github.com/Automattic/newspack-newsletters/issues/1202)) ([5ad7ab5](https://github.com/Automattic/newspack-newsletters/commit/5ad7ab5ff6979752bfa30f5e5e1dcad9bd7bd26d))

## [1.67.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.67.0...v1.67.1-alpha.1) (2023-06-22)


### Bug Fixes

* add a width to the email inputs to prevent overflow ([#1196](https://github.com/Automattic/newspack-newsletters/issues/1196)) ([058a14a](https://github.com/Automattic/newspack-newsletters/commit/058a14aaf42244a9bf71804f57a92c4ddf43fb1d))
* allow public newsletters in author archives ([#1197](https://github.com/Automattic/newspack-newsletters/issues/1197)) ([d3eb018](https://github.com/Automattic/newspack-newsletters/commit/d3eb0184499e39520d1f4da30879994c98d24d11))
* **subscribe-block:** allow spaces in submit button ([#1202](https://github.com/Automattic/newspack-newsletters/issues/1202)) ([5ad7ab5](https://github.com/Automattic/newspack-newsletters/commit/5ad7ab5ff6979752bfa30f5e5e1dcad9bd7bd26d))

# [1.67.0](https://github.com/Automattic/newspack-newsletters/compare/v1.66.0...v1.67.0) (2023-06-19)


### Bug Fixes

* remove important flag from font size rules ([#1185](https://github.com/Automattic/newspack-newsletters/issues/1185)) ([39031fd](https://github.com/Automattic/newspack-newsletters/commit/39031fd6a2bad728eb13a3b5dbb6d655887a640b))


### Features

* optional comments support ([#1170](https://github.com/Automattic/newspack-newsletters/issues/1170)) ([d0b7878](https://github.com/Automattic/newspack-newsletters/commit/d0b7878dca45cf3bb3ed6a98563d79b5866653df))
* **subscribe-form:** improve success message ([#1184](https://github.com/Automattic/newspack-newsletters/issues/1184)) ([4d11566](https://github.com/Automattic/newspack-newsletters/commit/4d11566a48a9e379616b341ff638d75e0e7e6a8d))
* support non-stacking on mobile ([#1178](https://github.com/Automattic/newspack-newsletters/issues/1178)) ([e68068e](https://github.com/Automattic/newspack-newsletters/commit/e68068e003e8f2d903d116ae9182384ce376bafc))

# [1.67.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.66.0...v1.67.0-alpha.1) (2023-06-08)


### Bug Fixes

* remove important flag from font size rules ([#1185](https://github.com/Automattic/newspack-newsletters/issues/1185)) ([39031fd](https://github.com/Automattic/newspack-newsletters/commit/39031fd6a2bad728eb13a3b5dbb6d655887a640b))


### Features

* optional comments support ([#1170](https://github.com/Automattic/newspack-newsletters/issues/1170)) ([d0b7878](https://github.com/Automattic/newspack-newsletters/commit/d0b7878dca45cf3bb3ed6a98563d79b5866653df))
* **subscribe-form:** improve success message ([#1184](https://github.com/Automattic/newspack-newsletters/issues/1184)) ([4d11566](https://github.com/Automattic/newspack-newsletters/commit/4d11566a48a9e379616b341ff638d75e0e7e6a8d))
* support non-stacking on mobile ([#1178](https://github.com/Automattic/newspack-newsletters/issues/1178)) ([e68068e](https://github.com/Automattic/newspack-newsletters/commit/e68068e003e8f2d903d116ae9182384ce376bafc))

# [1.66.0](https://github.com/Automattic/newspack-newsletters/compare/v1.65.0...v1.66.0) (2023-06-05)


### Features

* better feedback to the user about api error ([#1179](https://github.com/Automattic/newspack-newsletters/issues/1179)) ([43807e0](https://github.com/Automattic/newspack-newsletters/commit/43807e066180cec04da0b1641cb183c9cf543f26))

# [1.66.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.65.0...v1.66.0-alpha.1) (2023-05-25)


### Features

* better feedback to the user about api error ([#1179](https://github.com/Automattic/newspack-newsletters/issues/1179)) ([43807e0](https://github.com/Automattic/newspack-newsletters/commit/43807e066180cec04da0b1641cb183c9cf543f26))

# [1.65.0](https://github.com/Automattic/newspack-newsletters/compare/v1.64.0...v1.65.0) (2023-05-24)


### Bug Fixes

* **activecampaign:** prevent fatal error when fetching contact fields ([#1160](https://github.com/Automattic/newspack-newsletters/issues/1160)) ([4988fdd](https://github.com/Automattic/newspack-newsletters/commit/4988fdd7ac9fd14ff38cf362e595538d9382f4b4))
* display only Audiences in list metabox ([#1167](https://github.com/Automattic/newspack-newsletters/issues/1167)) ([6bc78bc](https://github.com/Automattic/newspack-newsletters/commit/6bc78bced3579b927e71eaf166c4c10741f60d81))


### Features

* conditional content tags ([#1165](https://github.com/Automattic/newspack-newsletters/issues/1165)) ([4d8f67f](https://github.com/Automattic/newspack-newsletters/commit/4d8f67fc7633a3712c190ed71f5f302a8723f131))
* fix SEND TO mailchimp audiences ([#1168](https://github.com/Automattic/newspack-newsletters/issues/1168)) ([6c0897b](https://github.com/Automattic/newspack-newsletters/commit/6c0897bd4e4de0ed5e30e2d73ca1dd67fa7ffae2))
* **mailchimp:** support double opt-in subscription ([#1161](https://github.com/Automattic/newspack-newsletters/issues/1161)) ([8183278](https://github.com/Automattic/newspack-newsletters/commit/8183278d351bf663ef48e1649481056602219ff1))
* offer groups as Subscription Lists ([#1156](https://github.com/Automattic/newspack-newsletters/issues/1156)) ([31e3969](https://github.com/Automattic/newspack-newsletters/commit/31e396981a95c42c3a264f18f4e99563971f8df3))

# [1.65.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.64.0...v1.65.0-alpha.1) (2023-05-11)


### Bug Fixes

* **activecampaign:** prevent fatal error when fetching contact fields ([#1160](https://github.com/Automattic/newspack-newsletters/issues/1160)) ([4988fdd](https://github.com/Automattic/newspack-newsletters/commit/4988fdd7ac9fd14ff38cf362e595538d9382f4b4))
* display only Audiences in list metabox ([#1167](https://github.com/Automattic/newspack-newsletters/issues/1167)) ([6bc78bc](https://github.com/Automattic/newspack-newsletters/commit/6bc78bced3579b927e71eaf166c4c10741f60d81))


### Features

* conditional content tags ([#1165](https://github.com/Automattic/newspack-newsletters/issues/1165)) ([4d8f67f](https://github.com/Automattic/newspack-newsletters/commit/4d8f67fc7633a3712c190ed71f5f302a8723f131))
* fix SEND TO mailchimp audiences ([#1168](https://github.com/Automattic/newspack-newsletters/issues/1168)) ([6c0897b](https://github.com/Automattic/newspack-newsletters/commit/6c0897bd4e4de0ed5e30e2d73ca1dd67fa7ffae2))
* **mailchimp:** support double opt-in subscription ([#1161](https://github.com/Automattic/newspack-newsletters/issues/1161)) ([8183278](https://github.com/Automattic/newspack-newsletters/commit/8183278d351bf663ef48e1649481056602219ff1))
* offer groups as Subscription Lists ([#1156](https://github.com/Automattic/newspack-newsletters/issues/1156)) ([31e3969](https://github.com/Automattic/newspack-newsletters/commit/31e396981a95c42c3a264f18f4e99563971f8df3))

# [1.64.0](https://github.com/Automattic/newspack-newsletters/compare/v1.63.0...v1.64.0) (2023-05-08)


### Bug Fixes

* avoid fatal error on Constant Contact ([155eb03](https://github.com/Automattic/newspack-newsletters/commit/155eb03a22d9bb584ea4754c57da585e850696d2))
* **subscribe-block:** optimize js dependencies ([#1142](https://github.com/Automattic/newspack-newsletters/issues/1142)) ([6b7c82d](https://github.com/Automattic/newspack-newsletters/commit/6b7c82d3f9deffb6a481af26dc963040d40f01d3))


### Features

* **subscribe-block:** support input labels ([#1151](https://github.com/Automattic/newspack-newsletters/issues/1151)) ([40ac87d](https://github.com/Automattic/newspack-newsletters/commit/40ac87de9f32de6ed5dce56dae6933c15447628d))

# [1.64.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.63.0...v1.64.0-alpha.1) (2023-04-28)


### Bug Fixes

* avoid fatal error on Constant Contact ([155eb03](https://github.com/Automattic/newspack-newsletters/commit/155eb03a22d9bb584ea4754c57da585e850696d2))
* **subscribe-block:** optimize js dependencies ([#1142](https://github.com/Automattic/newspack-newsletters/issues/1142)) ([6b7c82d](https://github.com/Automattic/newspack-newsletters/commit/6b7c82d3f9deffb6a481af26dc963040d40f01d3))


### Features

* **subscribe-block:** support input labels ([#1151](https://github.com/Automattic/newspack-newsletters/issues/1151)) ([40ac87d](https://github.com/Automattic/newspack-newsletters/commit/40ac87de9f32de6ed5dce56dae6933c15447628d))

# [1.63.0](https://github.com/Automattic/newspack-newsletters/compare/v1.62.0...v1.63.0) (2023-04-24)


### Bug Fixes

* commit hook and add pre push ([#1137](https://github.com/Automattic/newspack-newsletters/issues/1137)) ([94b20d2](https://github.com/Automattic/newspack-newsletters/commit/94b20d2dc191501fbc3a468e420978ad4c11c8b2))
* only add newsletters to categories and tags archives queries ([#1138](https://github.com/Automattic/newspack-newsletters/issues/1138)) ([612125f](https://github.com/Automattic/newspack-newsletters/commit/612125f5c4ec1282969ceacd84793c5ae14ccb29))
* restrict custom editor font sizes ([#1144](https://github.com/Automattic/newspack-newsletters/issues/1144)) ([3f53720](https://github.com/Automattic/newspack-newsletters/commit/3f53720fd7314f945ecda582c64f7c9b867f7215))
* **subscription-block:** use domReady for initializing block js ([#1130](https://github.com/Automattic/newspack-newsletters/issues/1130)) ([9f58caf](https://github.com/Automattic/newspack-newsletters/commit/9f58cafb4e3716a786bd674b2cefc4dd422f1696))


### Features

* **subscription:** add recaptcha panel in the editor ([#1139](https://github.com/Automattic/newspack-newsletters/issues/1139)) ([cbae932](https://github.com/Automattic/newspack-newsletters/commit/cbae93244f7ad3fab1727de2105d18ebd0467c2a))

# [1.63.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.62.0...v1.63.0-alpha.1) (2023-04-13)


### Bug Fixes

* commit hook and add pre push ([#1137](https://github.com/Automattic/newspack-newsletters/issues/1137)) ([94b20d2](https://github.com/Automattic/newspack-newsletters/commit/94b20d2dc191501fbc3a468e420978ad4c11c8b2))
* only add newsletters to categories and tags archives queries ([#1138](https://github.com/Automattic/newspack-newsletters/issues/1138)) ([612125f](https://github.com/Automattic/newspack-newsletters/commit/612125f5c4ec1282969ceacd84793c5ae14ccb29))
* restrict custom editor font sizes ([#1144](https://github.com/Automattic/newspack-newsletters/issues/1144)) ([3f53720](https://github.com/Automattic/newspack-newsletters/commit/3f53720fd7314f945ecda582c64f7c9b867f7215))
* **subscription-block:** use domReady for initializing block js ([#1130](https://github.com/Automattic/newspack-newsletters/issues/1130)) ([9f58caf](https://github.com/Automattic/newspack-newsletters/commit/9f58cafb4e3716a786bd674b2cefc4dd422f1696))


### Features

* **subscription:** add recaptcha panel in the editor ([#1139](https://github.com/Automattic/newspack-newsletters/issues/1139)) ([cbae932](https://github.com/Automattic/newspack-newsletters/commit/cbae93244f7ad3fab1727de2105d18ebd0467c2a))

# [1.62.0](https://github.com/Automattic/newspack-newsletters/compare/v1.61.1...v1.62.0) (2023-04-10)


### Bug Fixes

* editor title with selected header font ([#1119](https://github.com/Automattic/newspack-newsletters/issues/1119)) ([300180e](https://github.com/Automattic/newspack-newsletters/commit/300180eb1e58f86a85e221abf43e92a0630d26a6))
* **posts-inserter:** preview styles for WP 6.2 ([#1123](https://github.com/Automattic/newspack-newsletters/issues/1123)) ([afffc61](https://github.com/Automattic/newspack-newsletters/commit/afffc61be4f2f282fb4628739651c546faf63dd3))


### Features

* add customizable success message ([#1129](https://github.com/Automattic/newspack-newsletters/issues/1129)) ([84a1121](https://github.com/Automattic/newspack-newsletters/commit/84a11216570e72c314db45085d4d49b3845e190d))
* Change the default button label to Sign up ([#1125](https://github.com/Automattic/newspack-newsletters/issues/1125)) ([d1bd524](https://github.com/Automattic/newspack-newsletters/commit/d1bd524c294a051e5b0e6d8bd70c97a86805c658))

# [1.62.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.62.0-alpha.1...v1.62.0-alpha.2) (2023-04-03)


### Bug Fixes

* **subscription-block:** use domReady for initializing block js ([#1136](https://github.com/Automattic/newspack-newsletters/issues/1136)) ([cae88f8](https://github.com/Automattic/newspack-newsletters/commit/cae88f8c51abd7a6d3f781c0c5c5c584dd301ca4))

## [1.61.1](https://github.com/Automattic/newspack-newsletters/compare/v1.61.0...v1.61.1) (2023-04-03)


### Bug Fixes

* **subscription-block:** use domReady for initializing block js ([#1136](https://github.com/Automattic/newspack-newsletters/issues/1136)) ([cae88f8](https://github.com/Automattic/newspack-newsletters/commit/cae88f8c51abd7a6d3f781c0c5c5c584dd301ca4))

# [1.61.0](https://github.com/Automattic/newspack-newsletters/compare/v1.60.1...v1.61.0) (2023-03-27)


### Features

* adjust data passed by subscription hooks ([#1109](https://github.com/Automattic/newspack-newsletters/issues/1109)) ([641e5bc](https://github.com/Automattic/newspack-newsletters/commit/641e5bcc461da68fc128f251397475c1cd641858))
* update hook param ([#1111](https://github.com/Automattic/newspack-newsletters/issues/1111)) ([af6b7dd](https://github.com/Automattic/newspack-newsletters/commit/af6b7dde7288e2cc0df1203ca62480c56d61a833))

# [1.61.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.61.0-alpha.1...v1.61.0-alpha.2) (2023-03-21)


### Features

* update hook param ([#1111](https://github.com/Automattic/newspack-newsletters/issues/1111)) ([af6b7dd](https://github.com/Automattic/newspack-newsletters/commit/af6b7dde7288e2cc0df1203ca62480c56d61a833))

# [1.61.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.60.1...v1.61.0-alpha.1) (2023-03-16)


### Features

* adjust data passed by subscription hooks ([#1109](https://github.com/Automattic/newspack-newsletters/issues/1109)) ([641e5bc](https://github.com/Automattic/newspack-newsletters/commit/641e5bcc461da68fc128f251397475c1cd641858))

## [1.60.1](https://github.com/Automattic/newspack-newsletters/compare/v1.60.0...v1.60.1) (2023-03-15)


### Bug Fixes

* date field types for custom ESP metadata fields ([#1117](https://github.com/Automattic/newspack-newsletters/issues/1117)) ([b9bbd9d](https://github.com/Automattic/newspack-newsletters/commit/b9bbd9dd7a8f5e7eb1acbee1127487c59a316261))

# [1.60.0](https://github.com/Automattic/newspack-newsletters/compare/v1.59.2...v1.60.0) (2023-03-14)


### Bug Fixes

* disable JP Photon for newsletters ([#1085](https://github.com/Automattic/newspack-newsletters/issues/1085)) ([f443d99](https://github.com/Automattic/newspack-newsletters/commit/f443d9981ea496908419ad83171fa86da7cc0a40))
* local list description being deleted ([#1104](https://github.com/Automattic/newspack-newsletters/issues/1104)) ([d9b0d41](https://github.com/Automattic/newspack-newsletters/commit/d9b0d419a1373fdc335b3e2d1bc4c88ad23464d3))
* make Lists CPT menu item a sub-item of Newsletters CPT menu item ([271c916](https://github.com/Automattic/newspack-newsletters/commit/271c916b4d07a0d16bae3fa1edbf8e99ea9ac385))
* remove list from settings when deleted ([7530f15](https://github.com/Automattic/newspack-newsletters/commit/7530f15fecbc7f0cc2824b0b283985a4f10bd5ed))
* remove list from settings when deleted ([#1096](https://github.com/Automattic/newspack-newsletters/issues/1096)) ([e362c51](https://github.com/Automattic/newspack-newsletters/commit/e362c5153287880cc810822ea34ab50d6e96bcca))
* typo ([d71973c](https://github.com/Automattic/newspack-newsletters/commit/d71973c7cf98c46e271efb617dee2b0ab977a681))


### Features

* add missing popup_id to listeners ([d43123d](https://github.com/Automattic/newspack-newsletters/commit/d43123dd15f1fcd7dcd2b91e8ca21547f48733a6))
* add origin metadata to all contacts ([4eee238](https://github.com/Automattic/newspack-newsletters/commit/4eee23871365700538eb41413e5fbb8f205953c7))
* add signature to update tag methods ([5b71ec0](https://github.com/Automattic/newspack-newsletters/commit/5b71ec08bda5584ecc99e7c32dbf04942726e431))
* add support to campaign data event listeners ([bec03d2](https://github.com/Automattic/newspack-newsletters/commit/bec03d26aa34b113313ab3d4feab6254134cd0de))
* add support to update tags in Active Campaign ([c6d7916](https://github.com/Automattic/newspack-newsletters/commit/c6d7916b5e033452559a96918e03b7709403f1a4))
* add support to update tags in Constant Contact ([8105d74](https://github.com/Automattic/newspack-newsletters/commit/8105d744103b15696f7294a0d4efc89517d76922))
* add support to update tags in Mailchimp ([3230fcc](https://github.com/Automattic/newspack-newsletters/commit/3230fccc3295109282246712bfbbf6c595021d26))
* add tags support to constant contact ([ed4df72](https://github.com/Automattic/newspack-newsletters/commit/ed4df72cd8b0e097eae12f56b21467f9f79f6440))
* add warning to mailchimp local lists ([6d3b33b](https://github.com/Automattic/newspack-newsletters/commit/6d3b33b0ca926dda7e3c924413fa38d4f4ea4707))
* adjust class property ([0c0a0ce](https://github.com/Automattic/newspack-newsletters/commit/0c0a0ceb1b24ef9cfd3d6158d5aa6d51269aec92))
* change meta key to avoid conflict ([4f4d15f](https://github.com/Automattic/newspack-newsletters/commit/4f4d15feb0d4fc379e19559d48feb0a96116d23d))
* enable local lists and mailchimp groups ([6090937](https://github.com/Automattic/newspack-newsletters/commit/60909373db19906acab506cda14a2d6e85d0ce7c))
* Two way sync tag name ([05a5602](https://github.com/Automattic/newspack-newsletters/commit/05a560254967a343b5b3d03be3458abd6a84aef2))
* Two way sync tag names ([f8afb45](https://github.com/Automattic/newspack-newsletters/commit/f8afb45f1e53ff9ab197dcc9dd894596000f15a8))

# [1.60.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.59.2...v1.60.0-alpha.1) (2023-03-03)


### Bug Fixes

* disable JP Photon for newsletters ([#1085](https://github.com/Automattic/newspack-newsletters/issues/1085)) ([f443d99](https://github.com/Automattic/newspack-newsletters/commit/f443d9981ea496908419ad83171fa86da7cc0a40))
* local list description being deleted ([#1104](https://github.com/Automattic/newspack-newsletters/issues/1104)) ([d9b0d41](https://github.com/Automattic/newspack-newsletters/commit/d9b0d419a1373fdc335b3e2d1bc4c88ad23464d3))
* make Lists CPT menu item a sub-item of Newsletters CPT menu item ([271c916](https://github.com/Automattic/newspack-newsletters/commit/271c916b4d07a0d16bae3fa1edbf8e99ea9ac385))
* remove list from settings when deleted ([7530f15](https://github.com/Automattic/newspack-newsletters/commit/7530f15fecbc7f0cc2824b0b283985a4f10bd5ed))
* remove list from settings when deleted ([#1096](https://github.com/Automattic/newspack-newsletters/issues/1096)) ([e362c51](https://github.com/Automattic/newspack-newsletters/commit/e362c5153287880cc810822ea34ab50d6e96bcca))
* typo ([d71973c](https://github.com/Automattic/newspack-newsletters/commit/d71973c7cf98c46e271efb617dee2b0ab977a681))


### Features

* add missing popup_id to listeners ([d43123d](https://github.com/Automattic/newspack-newsletters/commit/d43123dd15f1fcd7dcd2b91e8ca21547f48733a6))
* add origin metadata to all contacts ([4eee238](https://github.com/Automattic/newspack-newsletters/commit/4eee23871365700538eb41413e5fbb8f205953c7))
* add signature to update tag methods ([5b71ec0](https://github.com/Automattic/newspack-newsletters/commit/5b71ec08bda5584ecc99e7c32dbf04942726e431))
* add support to campaign data event listeners ([bec03d2](https://github.com/Automattic/newspack-newsletters/commit/bec03d26aa34b113313ab3d4feab6254134cd0de))
* add support to update tags in Active Campaign ([c6d7916](https://github.com/Automattic/newspack-newsletters/commit/c6d7916b5e033452559a96918e03b7709403f1a4))
* add support to update tags in Constant Contact ([8105d74](https://github.com/Automattic/newspack-newsletters/commit/8105d744103b15696f7294a0d4efc89517d76922))
* add support to update tags in Mailchimp ([3230fcc](https://github.com/Automattic/newspack-newsletters/commit/3230fccc3295109282246712bfbbf6c595021d26))
* add tags support to constant contact ([ed4df72](https://github.com/Automattic/newspack-newsletters/commit/ed4df72cd8b0e097eae12f56b21467f9f79f6440))
* add warning to mailchimp local lists ([6d3b33b](https://github.com/Automattic/newspack-newsletters/commit/6d3b33b0ca926dda7e3c924413fa38d4f4ea4707))
* adjust class property ([0c0a0ce](https://github.com/Automattic/newspack-newsletters/commit/0c0a0ceb1b24ef9cfd3d6158d5aa6d51269aec92))
* change meta key to avoid conflict ([4f4d15f](https://github.com/Automattic/newspack-newsletters/commit/4f4d15feb0d4fc379e19559d48feb0a96116d23d))
* enable local lists and mailchimp groups ([6090937](https://github.com/Automattic/newspack-newsletters/commit/60909373db19906acab506cda14a2d6e85d0ce7c))
* Two way sync tag name ([05a5602](https://github.com/Automattic/newspack-newsletters/commit/05a560254967a343b5b3d03be3458abd6a84aef2))
* Two way sync tag names ([f8afb45](https://github.com/Automattic/newspack-newsletters/commit/f8afb45f1e53ff9ab197dcc9dd894596000f15a8))

## [1.59.2](https://github.com/Automattic/newspack-newsletters/compare/v1.59.1...v1.59.2) (2023-03-02)


### Bug Fixes

* typo causing fatal errors ([63b899d](https://github.com/Automattic/newspack-newsletters/commit/63b899d02297b1b5dec2ae1f78bc5fef98b0a7e5))

## [1.59.1](https://github.com/Automattic/newspack-newsletters/compare/v1.59.0...v1.59.1) (2023-02-28)


### Bug Fixes

* Revert changes in editor ([460199f](https://github.com/Automattic/newspack-newsletters/commit/460199ff7d32adcc4a6d2f6cb361203f46850837))

# [1.59.0](https://github.com/Automattic/newspack-newsletters/compare/v1.58.0...v1.59.0) (2023-02-28)


### Bug Fixes

* ensure images width are preserved ([#1074](https://github.com/Automattic/newspack-newsletters/issues/1074)) ([93882c9](https://github.com/Automattic/newspack-newsletters/commit/93882c920d3088253c4000619ec7b0d66fc98189))


### Features

* add groups support to mailchimp ([08b2740](https://github.com/Automattic/newspack-newsletters/commit/08b274086f70c068b7da89c3789e34829a5f4846))
* **mailchimp:** support folder selection ([#1075](https://github.com/Automattic/newspack-newsletters/issues/1075)) ([9c2b283](https://github.com/Automattic/newspack-newsletters/commit/9c2b28375513b451c54fe0c78a2e152e730ccc9c))
* **ras:** support custom contact metadata prefixes for RAS ([#1070](https://github.com/Automattic/newspack-newsletters/issues/1070)) ([2006528](https://github.com/Automattic/newspack-newsletters/commit/2006528ea1a2c36605f9cd0847ab4f2fedfbed7f))

# [1.59.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.58.0...v1.59.0-alpha.1) (2023-02-17)


### Bug Fixes

* ensure images width are preserved ([#1074](https://github.com/Automattic/newspack-newsletters/issues/1074)) ([93882c9](https://github.com/Automattic/newspack-newsletters/commit/93882c920d3088253c4000619ec7b0d66fc98189))


### Features

* add groups support to mailchimp ([08b2740](https://github.com/Automattic/newspack-newsletters/commit/08b274086f70c068b7da89c3789e34829a5f4846))
* **mailchimp:** support folder selection ([#1075](https://github.com/Automattic/newspack-newsletters/issues/1075)) ([9c2b283](https://github.com/Automattic/newspack-newsletters/commit/9c2b28375513b451c54fe0c78a2e152e730ccc9c))
* **ras:** support custom contact metadata prefixes for RAS ([#1070](https://github.com/Automattic/newspack-newsletters/issues/1070)) ([2006528](https://github.com/Automattic/newspack-newsletters/commit/2006528ea1a2c36605f9cd0847ab4f2fedfbed7f))

# [1.58.0](https://github.com/Automattic/newspack-newsletters/compare/v1.57.0...v1.58.0) (2023-02-16)


### Bug Fixes

* **activecampaign:** handle error on field creation ([#1069](https://github.com/Automattic/newspack-newsletters/issues/1069)) ([8d82cd2](https://github.com/Automattic/newspack-newsletters/commit/8d82cd2b648dab927226948acc00591c001fb79d))
* use transient and fix typos ([d868177](https://github.com/Automattic/newspack-newsletters/commit/d868177b45c8d4a1330b77f58f63e53281fd9b5c))


### Features

* add a new log to make debugging easier ([6639f42](https://github.com/Automattic/newspack-newsletters/commit/6639f42612ee7c934116b8c07e18db2db56d88a5))
* add caching to mailchimp ([190cf7e](https://github.com/Automattic/newspack-newsletters/commit/190cf7e28d5e62f4e8a70f827bb33f1b1c73c9de))

# [1.58.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.57.0...v1.58.0-alpha.1) (2023-01-26)


### Bug Fixes

* **activecampaign:** handle error on field creation ([#1069](https://github.com/Automattic/newspack-newsletters/issues/1069)) ([8d82cd2](https://github.com/Automattic/newspack-newsletters/commit/8d82cd2b648dab927226948acc00591c001fb79d))
* use transient and fix typos ([d868177](https://github.com/Automattic/newspack-newsletters/commit/d868177b45c8d4a1330b77f58f63e53281fd9b5c))


### Features

* add a new log to make debugging easier ([6639f42](https://github.com/Automattic/newspack-newsletters/commit/6639f42612ee7c934116b8c07e18db2db56d88a5))
* add caching to mailchimp ([190cf7e](https://github.com/Automattic/newspack-newsletters/commit/190cf7e28d5e62f4e8a70f827bb33f1b1c73c9de))

# [1.57.0](https://github.com/Automattic/newspack-newsletters/compare/v1.56.1...v1.57.0) (2023-01-25)


### Features

* add a max height and scroll to the lists ([b351946](https://github.com/Automattic/newspack-newsletters/commit/b351946b0e1cb0b500de504a92137e91f44add1e))
* allow constant contact to send to segments ([d7a33c0](https://github.com/Automattic/newspack-newsletters/commit/d7a33c00e784346c814ddf3d64b0fed036d96ee7))
* customize buttons ([dc1bda8](https://github.com/Automattic/newspack-newsletters/commit/dc1bda8030dadf46d90a2d9211255ddab9e04275))
* improve send mode UI ([fdc9982](https://github.com/Automattic/newspack-newsletters/commit/fdc9982b53e7ae5269bf709a327a2fedbf458cb4))
* improve UI ([8be5dc7](https://github.com/Automattic/newspack-newsletters/commit/8be5dc78179fa53f62922238bac78d057cc29a11))

# [1.57.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.56.1...v1.57.0-alpha.1) (2023-01-13)


### Features

* add a max height and scroll to the lists ([b351946](https://github.com/Automattic/newspack-newsletters/commit/b351946b0e1cb0b500de504a92137e91f44add1e))
* allow constant contact to send to segments ([d7a33c0](https://github.com/Automattic/newspack-newsletters/commit/d7a33c00e784346c814ddf3d64b0fed036d96ee7))
* customize buttons ([dc1bda8](https://github.com/Automattic/newspack-newsletters/commit/dc1bda8030dadf46d90a2d9211255ddab9e04275))
* improve send mode UI ([fdc9982](https://github.com/Automattic/newspack-newsletters/commit/fdc9982b53e7ae5269bf709a327a2fedbf458cb4))
* improve UI ([8be5dc7](https://github.com/Automattic/newspack-newsletters/commit/8be5dc78179fa53f62922238bac78d057cc29a11))

## [1.56.1](https://github.com/Automattic/newspack-newsletters/compare/v1.56.0...v1.56.1) (2023-01-11)


### Bug Fixes

* disable tag based lists ([6fce831](https://github.com/Automattic/newspack-newsletters/commit/6fce83134fa020f235787286b8ae9c110913d4cd))

# [1.56.0](https://github.com/Automattic/newspack-newsletters/compare/v1.55.1...v1.56.0) (2023-01-09)


### Bug Fixes

* handle post status on "public" meta update ([#1045](https://github.com/Automattic/newspack-newsletters/issues/1045)) ([3ce3373](https://github.com/Automattic/newspack-newsletters/commit/3ce337356b81fa6ed5cb8cbecb06d139eac9082c))
* list description ([9bf3981](https://github.com/Automattic/newspack-newsletters/commit/9bf398117dc59a407bda326decea878e3f3fb80a))
* remove unused variable ([a3fbdd2](https://github.com/Automattic/newspack-newsletters/commit/a3fbdd2b44954eb460ada5cc3811a9e42f30c5d2))


### Features

* add attributes and labels to providers ([#1019](https://github.com/Automattic/newspack-newsletters/issues/1019)) ([6653b7d](https://github.com/Automattic/newspack-newsletters/commit/6653b7d05707251121762a9b5f767905f953c19c))
* add my account support to mailchimp ([4d38bc8](https://github.com/Automattic/newspack-newsletters/commit/4d38bc87d5d6106c8c99c730730b1f652998edfd))
* Add subscription list metabox ([#1024](https://github.com/Automattic/newspack-newsletters/issues/1024)) ([3f54d84](https://github.com/Automattic/newspack-newsletters/commit/3f54d8436e4d9c1dff7f598b30e1fc5425fec7f4))
* add tags support to mailchimp ([#1008](https://github.com/Automattic/newspack-newsletters/issues/1008)) ([407976b](https://github.com/Automattic/newspack-newsletters/commit/407976b938ef1942a7f7fd132274101a53b2861f))
* bootstrapping lists CPT ([#1007](https://github.com/Automattic/newspack-newsletters/issues/1007)) ([e5fc722](https://github.com/Automattic/newspack-newsletters/commit/e5fc7225fa0ae4859b2e5d58187848f10563139a))
* **constant-contact:** improved oauth flow ([#1046](https://github.com/Automattic/newspack-newsletters/issues/1046)) ([c8f52ae](https://github.com/Automattic/newspack-newsletters/commit/c8f52ae6187d39e2cc157b834e98c42fe609a30e))
* disable local lists edits from selection ([abfb07c](https://github.com/Automattic/newspack-newsletters/commit/abfb07c3a644224db3825157cf1c536bf5eb529e))
* handle user creation on list update ([7fce947](https://github.com/Automattic/newspack-newsletters/commit/7fce947a2bc358adf2a911b89c0b723570a27b37))
* improve layout ([e8b9f36](https://github.com/Automattic/newspack-newsletters/commit/e8b9f36d437e6ba0b1c9d31bcd77d20d4b7c3fbc))
* integrate local lists to other lists ([294ea02](https://github.com/Automattic/newspack-newsletters/commit/294ea02d3ed2d4b65629b2cb5602f9a06abd4843))
* local subscription lists [#1051](https://github.com/Automattic/newspack-newsletters/issues/1051) ([fe8969a](https://github.com/Automattic/newspack-newsletters/commit/fe8969a32abe98279eb176bc59762275054e6417))
* merge master ([882c9a4](https://github.com/Automattic/newspack-newsletters/commit/882c9a48dcb1877c89bfd47f4b900fc8e2920113))
* normalize support for tags ([6f8bb4f](https://github.com/Automattic/newspack-newsletters/commit/6f8bb4f797024465bd2f3136dfc35fd57fd81f5a))
* notice that we cant create segments ([f242f57](https://github.com/Automattic/newspack-newsletters/commit/f242f57c13d3c0846312bd9b23d3bcb4932d1d79))
* notice that we cant create segments for active campaign ([59da173](https://github.com/Automattic/newspack-newsletters/commit/59da1737132669417f218eed46a2d6214821a264))
* save tag ID when saving lists ([e970d34](https://github.com/Automattic/newspack-newsletters/commit/e970d34e1894d54a8af6bc6e2e00edbfe8321414))
* support my account for active campaign ([60b74ec](https://github.com/Automattic/newspack-newsletters/commit/60b74ec4b6b03c7ae4d449295e8bd00240fbdf3c))

# [1.56.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.55.1...v1.56.0-alpha.1) (2022-12-14)


### Bug Fixes

* handle post status on "public" meta update ([#1045](https://github.com/Automattic/newspack-newsletters/issues/1045)) ([3ce3373](https://github.com/Automattic/newspack-newsletters/commit/3ce337356b81fa6ed5cb8cbecb06d139eac9082c))
* list description ([9bf3981](https://github.com/Automattic/newspack-newsletters/commit/9bf398117dc59a407bda326decea878e3f3fb80a))
* remove unused variable ([a3fbdd2](https://github.com/Automattic/newspack-newsletters/commit/a3fbdd2b44954eb460ada5cc3811a9e42f30c5d2))


### Features

* add attributes and labels to providers ([#1019](https://github.com/Automattic/newspack-newsletters/issues/1019)) ([6653b7d](https://github.com/Automattic/newspack-newsletters/commit/6653b7d05707251121762a9b5f767905f953c19c))
* add my account support to mailchimp ([4d38bc8](https://github.com/Automattic/newspack-newsletters/commit/4d38bc87d5d6106c8c99c730730b1f652998edfd))
* Add subscription list metabox ([#1024](https://github.com/Automattic/newspack-newsletters/issues/1024)) ([3f54d84](https://github.com/Automattic/newspack-newsletters/commit/3f54d8436e4d9c1dff7f598b30e1fc5425fec7f4))
* add tags support to mailchimp ([#1008](https://github.com/Automattic/newspack-newsletters/issues/1008)) ([407976b](https://github.com/Automattic/newspack-newsletters/commit/407976b938ef1942a7f7fd132274101a53b2861f))
* bootstrapping lists CPT ([#1007](https://github.com/Automattic/newspack-newsletters/issues/1007)) ([e5fc722](https://github.com/Automattic/newspack-newsletters/commit/e5fc7225fa0ae4859b2e5d58187848f10563139a))
* **constant-contact:** improved oauth flow ([#1046](https://github.com/Automattic/newspack-newsletters/issues/1046)) ([c8f52ae](https://github.com/Automattic/newspack-newsletters/commit/c8f52ae6187d39e2cc157b834e98c42fe609a30e))
* disable local lists edits from selection ([abfb07c](https://github.com/Automattic/newspack-newsletters/commit/abfb07c3a644224db3825157cf1c536bf5eb529e))
* handle user creation on list update ([7fce947](https://github.com/Automattic/newspack-newsletters/commit/7fce947a2bc358adf2a911b89c0b723570a27b37))
* improve layout ([e8b9f36](https://github.com/Automattic/newspack-newsletters/commit/e8b9f36d437e6ba0b1c9d31bcd77d20d4b7c3fbc))
* integrate local lists to other lists ([294ea02](https://github.com/Automattic/newspack-newsletters/commit/294ea02d3ed2d4b65629b2cb5602f9a06abd4843))
* local subscription lists [#1051](https://github.com/Automattic/newspack-newsletters/issues/1051) ([fe8969a](https://github.com/Automattic/newspack-newsletters/commit/fe8969a32abe98279eb176bc59762275054e6417))
* merge master ([882c9a4](https://github.com/Automattic/newspack-newsletters/commit/882c9a48dcb1877c89bfd47f4b900fc8e2920113))
* normalize support for tags ([6f8bb4f](https://github.com/Automattic/newspack-newsletters/commit/6f8bb4f797024465bd2f3136dfc35fd57fd81f5a))
* notice that we cant create segments ([f242f57](https://github.com/Automattic/newspack-newsletters/commit/f242f57c13d3c0846312bd9b23d3bcb4932d1d79))
* notice that we cant create segments for active campaign ([59da173](https://github.com/Automattic/newspack-newsletters/commit/59da1737132669417f218eed46a2d6214821a264))
* save tag ID when saving lists ([e970d34](https://github.com/Automattic/newspack-newsletters/commit/e970d34e1894d54a8af6bc6e2e00edbfe8321414))
* support my account for active campaign ([60b74ec](https://github.com/Automattic/newspack-newsletters/commit/60b74ec4b6b03c7ae4d449295e8bd00240fbdf3c))

## [1.55.1](https://github.com/Automattic/newspack-newsletters/compare/v1.55.0...v1.55.1) (2022-12-12)


### Bug Fixes

* **ActiveCampaign:** update fields API version ([#1036](https://github.com/Automattic/newspack-newsletters/issues/1036)) ([e85e0ed](https://github.com/Automattic/newspack-newsletters/commit/e85e0eda55ae752dcdb3cd3eb5def9670e9390d6))
* attribute types in posts inserter block.json ([#1038](https://github.com/Automattic/newspack-newsletters/issues/1038)) ([85a5606](https://github.com/Automattic/newspack-newsletters/commit/85a5606d0d837e800672386eaa5a5c5dfcf276ed))

## [1.55.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.55.0...v1.55.1-alpha.1) (2022-12-01)


### Bug Fixes

* **ActiveCampaign:** update fields API version ([#1036](https://github.com/Automattic/newspack-newsletters/issues/1036)) ([e85e0ed](https://github.com/Automattic/newspack-newsletters/commit/e85e0eda55ae752dcdb3cd3eb5def9670e9390d6))
* attribute types in posts inserter block.json ([#1038](https://github.com/Automattic/newspack-newsletters/issues/1038)) ([85a5606](https://github.com/Automattic/newspack-newsletters/commit/85a5606d0d837e800672386eaa5a5c5dfcf276ed))

# [1.55.0](https://github.com/Automattic/newspack-newsletters/compare/v1.54.4...v1.55.0) (2022-11-28)


### Bug Fixes

* **ci:** update newspack-scripts to fix CI builds ([448133c](https://github.com/Automattic/newspack-newsletters/commit/448133c4f4e046238ac47b722a44de5fd3568d3f))
* **constant-contact:** validate access token fetch response ([#998](https://github.com/Automattic/newspack-newsletters/issues/998)) ([ad55608](https://github.com/Automattic/newspack-newsletters/commit/ad556086c070966a90eba92bf5e231fc56b4679e))
* package lock ([db3b012](https://github.com/Automattic/newspack-newsletters/commit/db3b012de8831e4528adb17c8eae8371b76a45f7))
* update CI orb newspack-scripts ([#1014](https://github.com/Automattic/newspack-newsletters/issues/1014)) ([d0ed0a6](https://github.com/Automattic/newspack-newsletters/commit/d0ed0a63b26637e1dff72c80e1ba28794ff4a51c))


### Features

* active campaign tags support ([#1013](https://github.com/Automattic/newspack-newsletters/issues/1013)) ([a0f1dc4](https://github.com/Automattic/newspack-newsletters/commit/a0f1dc44872a6bf3f26fc59df8ac0718d448bbe0)), closes [#1011](https://github.com/Automattic/newspack-newsletters/issues/1011)

# [1.55.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.55.0-alpha.1...v1.55.0-alpha.2) (2022-11-22)


### Bug Fixes

* avoid nested mj-text elements ([8ec5cb8](https://github.com/Automattic/newspack-newsletters/commit/8ec5cb89d4b24b0737455bde5bd8110d8772dd65))
* avoid nesting mjml elements inside mj-text ([5b5b80c](https://github.com/Automattic/newspack-newsletters/commit/5b5b80c55aede86ec55c8f046520bd38997d862f))
* group blocks ([b62bd7e](https://github.com/Automattic/newspack-newsletters/commit/b62bd7efd76793d761b9a897367f22237e102244))
* nested list items and quote blocks in email ([860555d](https://github.com/Automattic/newspack-newsletters/commit/860555d3eccc4dac2eabb30ece77b48c0509d754))

## [1.54.4](https://github.com/Automattic/newspack-newsletters/compare/v1.54.3...v1.54.4) (2022-11-22)


### Bug Fixes

* avoid nested mj-text elements ([8ec5cb8](https://github.com/Automattic/newspack-newsletters/commit/8ec5cb89d4b24b0737455bde5bd8110d8772dd65))
* avoid nesting mjml elements inside mj-text ([5b5b80c](https://github.com/Automattic/newspack-newsletters/commit/5b5b80c55aede86ec55c8f046520bd38997d862f))
* group blocks ([b62bd7e](https://github.com/Automattic/newspack-newsletters/commit/b62bd7efd76793d761b9a897367f22237e102244))
* nested list items and quote blocks in email ([860555d](https://github.com/Automattic/newspack-newsletters/commit/860555d3eccc4dac2eabb30ece77b48c0509d754))

## [1.54.3](https://github.com/Automattic/newspack-newsletters/compare/v1.54.2...v1.54.3) (2022-11-16)


### Bug Fixes

* handle exception on reader registration ([#1020](https://github.com/Automattic/newspack-newsletters/issues/1020)) ([a8a5b0f](https://github.com/Automattic/newspack-newsletters/commit/a8a5b0fdc1e93554d75c54ef78add1666431c590))

## [1.54.2](https://github.com/Automattic/newspack-newsletters/compare/v1.54.1...v1.54.2) (2022-11-04)


### Bug Fixes

* **ci:** update newspack-scripts to fix CI builds ([#1004](https://github.com/Automattic/newspack-newsletters/issues/1004)) ([ff14763](https://github.com/Automattic/newspack-newsletters/commit/ff14763c8ca0228c6ba8aa54d52da1702bd89913))

## [1.54.2-hotfix.1](https://github.com/Automattic/newspack-newsletters/compare/v1.54.1...v1.54.2-hotfix.1) (2022-11-04)


### Bug Fixes

* **ci:** update newspack-scripts to fix CI builds ([af0f6ca](https://github.com/Automattic/newspack-newsletters/commit/af0f6ca8e9af4a7225e5999c9154ae51283362b5))

## [1.54.1](https://github.com/Automattic/newspack-newsletters/compare/v1.54.0...v1.54.1) (2022-11-03)


### Bug Fixes

* lists and list item blocks in WP 6.1 ([#1000](https://github.com/Automattic/newspack-newsletters/issues/1000)) ([ec7ef86](https://github.com/Automattic/newspack-newsletters/commit/ec7ef86f429b81f62c9487c58ecf0a914113702c))

# [1.54.0](https://github.com/Automattic/newspack-newsletters/compare/v1.53.0...v1.54.0) (2022-10-31)


### Bug Fixes

* register reader with name ([#987](https://github.com/Automattic/newspack-newsletters/issues/987)) ([3315485](https://github.com/Automattic/newspack-newsletters/commit/33154857392b7b4ae86af404dcf546e3f17b49ee))
* standardize api error status code ([#992](https://github.com/Automattic/newspack-newsletters/issues/992)) ([b05add4](https://github.com/Automattic/newspack-newsletters/commit/b05add45eb12d842ccd0d229f5d5917f06a451fd))


### Features

* constant contact support for the subscription block ([#985](https://github.com/Automattic/newspack-newsletters/issues/985)) ([fdb7d7f](https://github.com/Automattic/newspack-newsletters/commit/fdb7d7f09311c7aceef8b5d96694f478b804403b))
* name fields options for the subscription block ([#986](https://github.com/Automattic/newspack-newsletters/issues/986)) ([c58816b](https://github.com/Automattic/newspack-newsletters/commit/c58816b9d659927db120835ff2f86808cb9ca38d))

# [1.54.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.54.0-alpha.1...v1.54.0-alpha.2) (2022-10-24)


### Bug Fixes

* standardize api error status code ([#992](https://github.com/Automattic/newspack-newsletters/issues/992)) ([b05add4](https://github.com/Automattic/newspack-newsletters/commit/b05add45eb12d842ccd0d229f5d5917f06a451fd))


### Features

* constant contact support for the subscription block ([#985](https://github.com/Automattic/newspack-newsletters/issues/985)) ([fdb7d7f](https://github.com/Automattic/newspack-newsletters/commit/fdb7d7f09311c7aceef8b5d96694f478b804403b))

# [1.54.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.53.0...v1.54.0-alpha.1) (2022-10-21)


### Bug Fixes

* register reader with name ([#987](https://github.com/Automattic/newspack-newsletters/issues/987)) ([3315485](https://github.com/Automattic/newspack-newsletters/commit/33154857392b7b4ae86af404dcf546e3f17b49ee))


### Features

* name fields options for the subscription block ([#986](https://github.com/Automattic/newspack-newsletters/issues/986)) ([c58816b](https://github.com/Automattic/newspack-newsletters/commit/c58816b9d659927db120835ff2f86808cb9ca38d))

# [1.53.0](https://github.com/Automattic/newspack-newsletters/compare/v1.52.0...v1.53.0) (2022-10-19)


### Features

* update subscribe block design to match reader registration ([#977](https://github.com/Automattic/newspack-newsletters/issues/977)) ([f186464](https://github.com/Automattic/newspack-newsletters/commit/f186464457b753fd5566eae7e09e3084506cc63d))

# [1.53.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.52.0...v1.53.0-alpha.1) (2022-10-06)


### Features

* update subscribe block design to match reader registration ([#977](https://github.com/Automattic/newspack-newsletters/issues/977)) ([f186464](https://github.com/Automattic/newspack-newsletters/commit/f186464457b753fd5566eae7e09e3084506cc63d))

# [1.52.0](https://github.com/Automattic/newspack-newsletters/compare/v1.51.0...v1.52.0) (2022-09-27)


### Bug Fixes

* **active-campaign:** ensure all metadata fields are handled ([aecc577](https://github.com/Automattic/newspack-newsletters/commit/aecc57716e04a9f3d270517c9c1d600d1b666c62))
* adding contact w/out lists handling ([#949](https://github.com/Automattic/newspack-newsletters/issues/949)) ([f0513b8](https://github.com/Automattic/newspack-newsletters/commit/f0513b8168de76f65615917b3483371aae366d84))
* **constant-contact:** update api for oauth2 ([#948](https://github.com/Automattic/newspack-newsletters/issues/948)) ([fea9364](https://github.com/Automattic/newspack-newsletters/commit/fea9364c38bfe2e23335a68828528597b0241abc))


### Features

* **post-inserter:** post type selection ([#967](https://github.com/Automattic/newspack-newsletters/issues/967)) ([9de26b0](https://github.com/Automattic/newspack-newsletters/commit/9de26b0092a8266ed0f7f28e55a5ec93fd6eb8a4))
* **subscribe-block:** button text color ([bd67ac0](https://github.com/Automattic/newspack-newsletters/commit/bd67ac09043f6c9d813fe5a93e44b2d414486a98))

# [1.52.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.52.0-alpha.1...v1.52.0-alpha.2) (2022-09-26)


### Bug Fixes

* **active-campaign:** ensure all metadata fields are handled ([aecc577](https://github.com/Automattic/newspack-newsletters/commit/aecc57716e04a9f3d270517c9c1d600d1b666c62))

# [1.52.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.51.1-alpha.1...v1.52.0-alpha.1) (2022-09-21)


### Bug Fixes

* **constant-contact:** update api for oauth2 ([#948](https://github.com/Automattic/newspack-newsletters/issues/948)) ([fea9364](https://github.com/Automattic/newspack-newsletters/commit/fea9364c38bfe2e23335a68828528597b0241abc))


### Features

* **post-inserter:** post type selection ([#967](https://github.com/Automattic/newspack-newsletters/issues/967)) ([9de26b0](https://github.com/Automattic/newspack-newsletters/commit/9de26b0092a8266ed0f7f28e55a5ec93fd6eb8a4))
* **subscribe-block:** button text color ([bd67ac0](https://github.com/Automattic/newspack-newsletters/commit/bd67ac09043f6c9d813fe5a93e44b2d414486a98))

## [1.51.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.51.0...v1.51.1-alpha.1) (2022-09-16)


### Bug Fixes

* adding contact w/out lists handling ([#949](https://github.com/Automattic/newspack-newsletters/issues/949)) ([f0513b8](https://github.com/Automattic/newspack-newsletters/commit/f0513b8168de76f65615917b3483371aae366d84))

# [1.51.0](https://github.com/Automattic/newspack-newsletters/compare/v1.50.0...v1.51.0) (2022-09-14)


### Bug Fixes

* **active-campaign:** handle errors when fetching contact data ([5a612e2](https://github.com/Automattic/newspack-newsletters/commit/5a612e28db7fab91e103ca2b8426507ad59a1380))
* **ActiveCampaing:** handle error on test campaign ([#945](https://github.com/Automattic/newspack-newsletters/issues/945)) ([3a65c82](https://github.com/Automattic/newspack-newsletters/commit/3a65c82bf68e80e2f80a09f02d670cc166c87bf3))
* only process links with valid url ([#942](https://github.com/Automattic/newspack-newsletters/issues/942)) ([8868fc2](https://github.com/Automattic/newspack-newsletters/commit/8868fc2126e55092b3b8eeb5c0e3f0b703deb679))
* **subscribe-block:** remove horizontal scroll ([f7a36c4](https://github.com/Automattic/newspack-newsletters/commit/f7a36c4dcd72f646f321d02547fa6ce1c89225e0))
* tweak Subscribe form styles and behavior ([#937](https://github.com/Automattic/newspack-newsletters/issues/937)) ([48b285c](https://github.com/Automattic/newspack-newsletters/commit/48b285c021dc4b572d1e49ab21bb87246db4eb58))


### Features

* delete contact on reader account deletion ([#929](https://github.com/Automattic/newspack-newsletters/issues/929)) ([d0619f7](https://github.com/Automattic/newspack-newsletters/commit/d0619f765614e202c7505a52700140f141b7900f))
* give each subscribe form a unique ID ([#943](https://github.com/Automattic/newspack-newsletters/issues/943)) ([2b52e1a](https://github.com/Automattic/newspack-newsletters/commit/2b52e1a0890af8680778c080de61eeffff729cc2))
* handle reCAPTCHA on Subscribe form ([#935](https://github.com/Automattic/newspack-newsletters/issues/935)) ([d527b60](https://github.com/Automattic/newspack-newsletters/commit/d527b600b3d4d3ecdb126e75a0bb77f6ac73ae3f))
* handle updated from & name settings from Newspack plugin ([#938](https://github.com/Automattic/newspack-newsletters/issues/938)) ([4764a67](https://github.com/Automattic/newspack-newsletters/commit/4764a67024aeb1893deeffba57c0e5cc78652e05))
* honeypot field for Subscribe block form ([#932](https://github.com/Automattic/newspack-newsletters/issues/932)) ([cb836d0](https://github.com/Automattic/newspack-newsletters/commit/cb836d07e8c9cdbab7b1c1688574ad635d33635b))

# [1.51.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v1.51.0-alpha.2...v1.51.0-alpha.3) (2022-09-07)


### Bug Fixes

* **active-campaign:** handle errors when fetching contact data ([5a612e2](https://github.com/Automattic/newspack-newsletters/commit/5a612e28db7fab91e103ca2b8426507ad59a1380))
* **subscribe-block:** remove horizontal scroll ([f7a36c4](https://github.com/Automattic/newspack-newsletters/commit/f7a36c4dcd72f646f321d02547fa6ce1c89225e0))

# [1.51.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.51.0-alpha.1...v1.51.0-alpha.2) (2022-09-06)


### Bug Fixes

* **ActiveCampaing:** handle error on test campaign ([#945](https://github.com/Automattic/newspack-newsletters/issues/945)) ([3a65c82](https://github.com/Automattic/newspack-newsletters/commit/3a65c82bf68e80e2f80a09f02d670cc166c87bf3))
* only process links with valid url ([#942](https://github.com/Automattic/newspack-newsletters/issues/942)) ([8868fc2](https://github.com/Automattic/newspack-newsletters/commit/8868fc2126e55092b3b8eeb5c0e3f0b703deb679))


### Features

* give each subscribe form a unique ID ([#943](https://github.com/Automattic/newspack-newsletters/issues/943)) ([2b52e1a](https://github.com/Automattic/newspack-newsletters/commit/2b52e1a0890af8680778c080de61eeffff729cc2))
* handle updated from & name settings from Newspack plugin ([#938](https://github.com/Automattic/newspack-newsletters/issues/938)) ([4764a67](https://github.com/Automattic/newspack-newsletters/commit/4764a67024aeb1893deeffba57c0e5cc78652e05))

# [1.51.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.50.0...v1.51.0-alpha.1) (2022-08-26)


### Bug Fixes

* tweak Subscribe form styles and behavior ([#937](https://github.com/Automattic/newspack-newsletters/issues/937)) ([48b285c](https://github.com/Automattic/newspack-newsletters/commit/48b285c021dc4b572d1e49ab21bb87246db4eb58))


### Features

* delete contact on reader account deletion ([#929](https://github.com/Automattic/newspack-newsletters/issues/929)) ([d0619f7](https://github.com/Automattic/newspack-newsletters/commit/d0619f765614e202c7505a52700140f141b7900f))
* handle reCAPTCHA on Subscribe form ([#935](https://github.com/Automattic/newspack-newsletters/issues/935)) ([d527b60](https://github.com/Automattic/newspack-newsletters/commit/d527b600b3d4d3ecdb126e75a0bb77f6ac73ae3f))
* honeypot field for Subscribe block form ([#932](https://github.com/Automattic/newspack-newsletters/issues/932)) ([cb836d0](https://github.com/Automattic/newspack-newsletters/commit/cb836d07e8c9cdbab7b1c1688574ad635d33635b))

# [1.50.0](https://github.com/Automattic/newspack-newsletters/compare/v1.49.1...v1.50.0) (2022-08-16)


### Bug Fixes

* **active-campaign:** format date fields ([#914](https://github.com/Automattic/newspack-newsletters/issues/914)) ([395606d](https://github.com/Automattic/newspack-newsletters/commit/395606dbc5a83bb30d9f572bb611e79e11cee3f5))
* **activecampaign:** resubscribe on adding contact ([#925](https://github.com/Automattic/newspack-newsletters/issues/925)) ([4c5ee9d](https://github.com/Automattic/newspack-newsletters/commit/4c5ee9d075a6823481df3c46209c8245f4282ed2))
* get_contact_data handler default arg ([681078a](https://github.com/Automattic/newspack-newsletters/commit/681078a57e3e5ccf5b70896e5b533d04bc2c1429))
* **subscribe-block:** empty lists config handling ([bee823e](https://github.com/Automattic/newspack-newsletters/commit/bee823e42fefdbbcf670bad708b36befabdd8124))
* **subscriptions:** verify email on reader verification ([#922](https://github.com/Automattic/newspack-newsletters/issues/922)) ([1add7d7](https://github.com/Automattic/newspack-newsletters/commit/1add7d77707479793a8d62f25bcaf45696b4294b))
* trigger release ([38fb836](https://github.com/Automattic/newspack-newsletters/commit/38fb836e05c2226d9cf637a136c3f64e89176c58))
* updating a list member ([#907](https://github.com/Automattic/newspack-newsletters/issues/907)) ([eb8c9ba](https://github.com/Automattic/newspack-newsletters/commit/eb8c9bad37a77b01ed32b6f964d89e6dd5c744c5))
* urlencode email addresses passed as URL params to ActiveCampaign API ([#913](https://github.com/Automattic/newspack-newsletters/issues/913)) ([bca4782](https://github.com/Automattic/newspack-newsletters/commit/bca4782a5478a7bf7f649fec0182c169c0246f43))


### Features

* **active-campaign:** more date fields ([75ef40b](https://github.com/Automattic/newspack-newsletters/commit/75ef40bf2f6e8f1670415ab72a8222b50e39c1a1))
* **ActiveCampaign:** optimize and reduce API requests ([#926](https://github.com/Automattic/newspack-newsletters/issues/926)) ([d577c52](https://github.com/Automattic/newspack-newsletters/commit/d577c521ba221dd9d0b426f83d6f58d02eaadb32))
* add contact on reader activation, if lists selected ([#895](https://github.com/Automattic/newspack-newsletters/issues/895)) ([d4a99a4](https://github.com/Automattic/newspack-newsletters/commit/d4a99a443a47098053e9c1bdcb30e1a9b7ed7792))
* contact adding improvements ([#897](https://github.com/Automattic/newspack-newsletters/issues/897)) ([0c735c4](https://github.com/Automattic/newspack-newsletters/commit/0c735c4ef661b86022595ee1b587d39362c5f746))
* copy tweak ([#916](https://github.com/Automattic/newspack-newsletters/issues/916)) ([e71fa85](https://github.com/Automattic/newspack-newsletters/commit/e71fa8581048f32c7cd9e230f999914e975fa632))
* email verification and subscription management ([#891](https://github.com/Automattic/newspack-newsletters/issues/891)) ([08968dd](https://github.com/Automattic/newspack-newsletters/commit/08968ddefd7df9b0f4333890d02e246232f05baa))
* enable contact adding w/out lists, as opposed to w/ empty lists ([#900](https://github.com/Automattic/newspack-newsletters/issues/900)) ([9c93941](https://github.com/Automattic/newspack-newsletters/commit/9c9394155a06fa858eaeb1e221e5a8013fa80f10))
* filter contact lists and small fixes ([#902](https://github.com/Automattic/newspack-newsletters/issues/902)) ([578015b](https://github.com/Automattic/newspack-newsletters/commit/578015b4a13d5078b8832c43882821314799a44c))
* full contact data fetching ([b7a294e](https://github.com/Automattic/newspack-newsletters/commit/b7a294e0b4005a2b10b7d507f11e8447f0387178))
* mailchimp subscription management ([#903](https://github.com/Automattic/newspack-newsletters/issues/903)) ([cb3eb43](https://github.com/Automattic/newspack-newsletters/commit/cb3eb436ca0870d3b7721be0ea2e9a87729523b2))
* pass existing contact data to the add_contact hooks ([449396e](https://github.com/Automattic/newspack-newsletters/commit/449396e7d486c2df48fed1f808fe8b307f37bdb1))
* return all contact data for the add_contact hooks ([b3cedb8](https://github.com/Automattic/newspack-newsletters/commit/b3cedb81bedbf89984c8b6586af4a2282f57a185))
* **subscribe-block:** prefill email input ([b5afbdd](https://github.com/Automattic/newspack-newsletters/commit/b5afbdd413ff90ee4b6a3b9fb0c7a5460a7ed52d))
* use Reader Activation custom sender info for verification emails ([#927](https://github.com/Automattic/newspack-newsletters/issues/927)) ([dc40a8e](https://github.com/Automattic/newspack-newsletters/commit/dc40a8e3d590784f051969e2ce9cf536378e63da))

## [1.49.1](https://github.com/Automattic/newspack-newsletters/compare/v1.49.0...v1.49.1) (2022-08-16)


### Bug Fixes

* ensure query post type is an array ([#933](https://github.com/Automattic/newspack-newsletters/issues/933)) ([a179898](https://github.com/Automattic/newspack-newsletters/commit/a179898114e1fc0d0a6e01452e2ca9ec9150d50e))

# [1.49.0](https://github.com/Automattic/newspack-newsletters/compare/v1.48.0...v1.49.0) (2022-07-26)


### Features

* subscription lists and subscription block ([#873](https://github.com/Automattic/newspack-newsletters/issues/873)) ([815a630](https://github.com/Automattic/newspack-newsletters/commit/815a63049b8abae75d86864d9def37db18a53fd0))

# [1.49.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.48.0...v1.49.0-alpha.1) (2022-07-14)


### Features

* subscription lists and subscription block ([#873](https://github.com/Automattic/newspack-newsletters/issues/873)) ([815a630](https://github.com/Automattic/newspack-newsletters/commit/815a63049b8abae75d86864d9def37db18a53fd0))

# [1.48.0](https://github.com/Automattic/newspack-newsletters/compare/v1.47.2...v1.48.0) (2022-07-11)


### Bug Fixes

* **activecampaign:** paginate through segments ([#886](https://github.com/Automattic/newspack-newsletters/issues/886)) ([f8f839e](https://github.com/Automattic/newspack-newsletters/commit/f8f839e7808e197eb979da7a40b402ea84dc0610))
* display newsletters in archives ([#868](https://github.com/Automattic/newspack-newsletters/issues/868)) ([c31c642](https://github.com/Automattic/newspack-newsletters/commit/c31c642143a670aaac498f653c0038e3bc078a44))


### Features

* active campaign segments support ([#844](https://github.com/Automattic/newspack-newsletters/issues/844)) ([186f3b8](https://github.com/Automattic/newspack-newsletters/commit/186f3b8890acbf1b9f901d27a530f2a1d04137e7))

# [1.48.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.48.0-alpha.1...v1.48.0-alpha.2) (2022-07-11)


### Bug Fixes

* **activecampaign:** paginate through segments ([#886](https://github.com/Automattic/newspack-newsletters/issues/886)) ([f8f839e](https://github.com/Automattic/newspack-newsletters/commit/f8f839e7808e197eb979da7a40b402ea84dc0610))

# [1.48.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.47.2...v1.48.0-alpha.1) (2022-06-30)


### Bug Fixes

* display newsletters in archives ([#868](https://github.com/Automattic/newspack-newsletters/issues/868)) ([c31c642](https://github.com/Automattic/newspack-newsletters/commit/c31c642143a670aaac498f653c0038e3bc078a44))


### Features

* active campaign segments support ([#844](https://github.com/Automattic/newspack-newsletters/issues/844)) ([186f3b8](https://github.com/Automattic/newspack-newsletters/commit/186f3b8890acbf1b9f901d27a530f2a1d04137e7))

## [1.47.2](https://github.com/Automattic/newspack-newsletters/compare/v1.47.1...v1.47.2) (2022-06-27)


### Bug Fixes

* separator background rendering ([#853](https://github.com/Automattic/newspack-newsletters/issues/853)) ([a0fb714](https://github.com/Automattic/newspack-newsletters/commit/a0fb714fc033ec0d921c7d8d8ce27c0019bdeb6c))
* validate newsletter subject ([#852](https://github.com/Automattic/newspack-newsletters/issues/852)) ([da26471](https://github.com/Automattic/newspack-newsletters/commit/da2647176814121933ae9c06631c712210f3df47))


### Reverts

* "chore(revert): bump commitlint from 16.2.4 to 17.0.2 ([#856](https://github.com/Automattic/newspack-newsletters/issues/856))" ([755dcb1](https://github.com/Automattic/newspack-newsletters/commit/755dcb1f29de567331fcf0ea14d22a05c8865595))

## [1.47.2-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.47.1...v1.47.2-alpha.1) (2022-06-16)


### Bug Fixes

* separator background rendering ([#853](https://github.com/Automattic/newspack-newsletters/issues/853)) ([a0fb714](https://github.com/Automattic/newspack-newsletters/commit/a0fb714fc033ec0d921c7d8d8ce27c0019bdeb6c))
* validate newsletter subject ([#852](https://github.com/Automattic/newspack-newsletters/issues/852)) ([da26471](https://github.com/Automattic/newspack-newsletters/commit/da2647176814121933ae9c06631c712210f3df47))


### Reverts

* "chore(revert): bump commitlint from 16.2.4 to 17.0.2 ([#856](https://github.com/Automattic/newspack-newsletters/issues/856))" ([755dcb1](https://github.com/Automattic/newspack-newsletters/commit/755dcb1f29de567331fcf0ea14d22a05c8865595))

## [1.47.1](https://github.com/Automattic/newspack-newsletters/compare/v1.47.0...v1.47.1) (2022-06-14)


### Bug Fixes

* remove reminiscent action hook; handle layouts without titles([#860](https://github.com/Automattic/newspack-newsletters/issues/860)) ([29d777f](https://github.com/Automattic/newspack-newsletters/commit/29d777f78dac0e48ab38b1d71aba3b2f3804313a))

# [1.47.0](https://github.com/Automattic/newspack-newsletters/compare/v1.46.4...v1.47.0) (2022-06-13)


### Bug Fixes

* ensure controlled statuses after a post update ([#835](https://github.com/Automattic/newspack-newsletters/issues/835)) ([cbfd642](https://github.com/Automattic/newspack-newsletters/commit/cbfd64225375a55e47d192722681e54ed678e3a6))
* migrate newsletters post status ([#825](https://github.com/Automattic/newspack-newsletters/issues/825)) ([d59792d](https://github.com/Automattic/newspack-newsletters/commit/d59792d22f3e3e339ba0c050d9fd7cde60ed313f))


### Features

* handle post status through `is_public` meta ([#824](https://github.com/Automattic/newspack-newsletters/issues/824)) ([20065ae](https://github.com/Automattic/newspack-newsletters/commit/20065ae1a390da73f8fb7fb692bb027136e12bd8))
* update init modal sidebar design ([#828](https://github.com/Automattic/newspack-newsletters/issues/828)) ([3246ed6](https://github.com/Automattic/newspack-newsletters/commit/3246ed6a0fa25d39e0800fdc77196c559dc45bf1))

# [1.47.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.46.4...v1.47.0-alpha.1) (2022-06-02)


### Bug Fixes

* ensure controlled statuses after a post update ([#835](https://github.com/Automattic/newspack-newsletters/issues/835)) ([cbfd642](https://github.com/Automattic/newspack-newsletters/commit/cbfd64225375a55e47d192722681e54ed678e3a6))
* migrate newsletters post status ([#825](https://github.com/Automattic/newspack-newsletters/issues/825)) ([d59792d](https://github.com/Automattic/newspack-newsletters/commit/d59792d22f3e3e339ba0c050d9fd7cde60ed313f))


### Features

* handle post status through `is_public` meta ([#824](https://github.com/Automattic/newspack-newsletters/issues/824)) ([20065ae](https://github.com/Automattic/newspack-newsletters/commit/20065ae1a390da73f8fb7fb692bb027136e12bd8))
* update init modal sidebar design ([#828](https://github.com/Automattic/newspack-newsletters/issues/828)) ([3246ed6](https://github.com/Automattic/newspack-newsletters/commit/3246ed6a0fa25d39e0800fdc77196c559dc45bf1))

## [1.46.4](https://github.com/Automattic/newspack-newsletters/compare/v1.46.3...v1.46.4) (2022-05-30)


### Bug Fixes

* allow CoAuthors Plus assets on editor ([#826](https://github.com/Automattic/newspack-newsletters/issues/826)) ([df0fde7](https://github.com/Automattic/newspack-newsletters/commit/df0fde7d1dba5f9d11e7353eadddc2e0e9b89d7a))

## [1.46.4-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.46.3...v1.46.4-alpha.1) (2022-05-27)


### Bug Fixes

* allow CoAuthors Plus assets on editor ([#826](https://github.com/Automattic/newspack-newsletters/issues/826)) ([df0fde7](https://github.com/Automattic/newspack-newsletters/commit/df0fde7d1dba5f9d11e7353eadddc2e0e9b89d7a))

## [1.46.3](https://github.com/Automattic/newspack-newsletters/compare/v1.46.2...v1.46.3) (2022-05-27)


### Bug Fixes

* ensure scheduled sending only fires for newsletters ([#837](https://github.com/Automattic/newspack-newsletters/issues/837)) ([30d1cbd](https://github.com/Automattic/newspack-newsletters/commit/30d1cbde541ed63cde2410b37f88def70e876187))

## [1.46.2](https://github.com/Automattic/newspack-newsletters/compare/v1.46.1...v1.46.2) (2022-05-26)


### Bug Fixes

* ensure scheduled newsletter is sent through a lock meta ([#836](https://github.com/Automattic/newspack-newsletters/issues/836)) ([9f1a2ae](https://github.com/Automattic/newspack-newsletters/commit/9f1a2aedfc4ae3d7571bb228e1551e47fc31c275))

## [1.46.2-hotfix.1](https://github.com/Automattic/newspack-newsletters/compare/v1.46.1...v1.46.2-hotfix.1) (2022-05-26)


### Bug Fixes

* ensure scheduled newsletter is sent through a lock meta ([e5bcd89](https://github.com/Automattic/newspack-newsletters/commit/e5bcd89e3204f8df690709ad2b0c2b8563e983e8))

## [1.46.1](https://github.com/Automattic/newspack-newsletters/compare/v1.46.0...v1.46.1) (2022-05-25)


### Bug Fixes

* sending scheduled newsletters ([#831](https://github.com/Automattic/newspack-newsletters/issues/831)) ([f2028fb](https://github.com/Automattic/newspack-newsletters/commit/f2028fb7fd98163e78c00bc5c35bef20a05fde9f))

## [1.46.1-hotfix.1](https://github.com/Automattic/newspack-newsletters/compare/v1.46.0...v1.46.1-hotfix.1) (2022-05-25)


### Bug Fixes

* sending scheduled newsletters ([2a25225](https://github.com/Automattic/newspack-newsletters/commit/2a25225f79e032ae454fe3e47fd40708f3b8c6e9))

# [1.46.0](https://github.com/Automattic/newspack-newsletters/compare/v1.45.0...v1.46.0) (2022-05-18)


### Bug Fixes

* api middleware execution logic ([#817](https://github.com/Automattic/newspack-newsletters/issues/817)) ([089c319](https://github.com/Automattic/newspack-newsletters/commit/089c31981ccf20fceca50826ce005cd7bc9ece4f))
* ensure layout update has ID ([#797](https://github.com/Automattic/newspack-newsletters/issues/797)) ([2a099e9](https://github.com/Automattic/newspack-newsletters/commit/2a099e9ccc85177198ace23f63450e1a97933af9))


### Features

* add another layout and add scroll effect to layout picker ([#801](https://github.com/Automattic/newspack-newsletters/issues/801)) ([a17ad0d](https://github.com/Automattic/newspack-newsletters/commit/a17ad0d460924cf211f4bbf75ce29ec9aeccf651))
* update all layouts with block visibility ([#800](https://github.com/Automattic/newspack-newsletters/issues/800)) ([744aad1](https://github.com/Automattic/newspack-newsletters/commit/744aad1bfa379bf2ec4a852fc47900567a10c80a))
* update social links and add new block styles ([#806](https://github.com/Automattic/newspack-newsletters/issues/806)) ([59ff8b6](https://github.com/Automattic/newspack-newsletters/commit/59ff8b6cbba1c12a87beaddf8cbc9bdc6d6c814d))

# [1.46.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.46.0-alpha.1...v1.46.0-alpha.2) (2022-05-11)


### Bug Fixes

* api middleware execution logic ([#817](https://github.com/Automattic/newspack-newsletters/issues/817)) ([089c319](https://github.com/Automattic/newspack-newsletters/commit/089c31981ccf20fceca50826ce005cd7bc9ece4f))


### Features

* update social links and add new block styles ([#806](https://github.com/Automattic/newspack-newsletters/issues/806)) ([59ff8b6](https://github.com/Automattic/newspack-newsletters/commit/59ff8b6cbba1c12a87beaddf8cbc9bdc6d6c814d))

# [1.46.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.45.0...v1.46.0-alpha.1) (2022-05-05)


### Bug Fixes

* ensure layout update has ID ([#797](https://github.com/Automattic/newspack-newsletters/issues/797)) ([2a099e9](https://github.com/Automattic/newspack-newsletters/commit/2a099e9ccc85177198ace23f63450e1a97933af9))


### Features

* add another layout and add scroll effect to layout picker ([#801](https://github.com/Automattic/newspack-newsletters/issues/801)) ([a17ad0d](https://github.com/Automattic/newspack-newsletters/commit/a17ad0d460924cf211f4bbf75ce29ec9aeccf651))
* update all layouts with block visibility ([#800](https://github.com/Automattic/newspack-newsletters/issues/800)) ([744aad1](https://github.com/Automattic/newspack-newsletters/commit/744aad1bfa379bf2ec4a852fc47900567a10c80a))

# [1.45.0](https://github.com/Automattic/newspack-newsletters/compare/v1.44.0...v1.45.0) (2022-05-03)


### Bug Fixes

* add all accepted keys to get_post_type_labels() ([#743](https://github.com/Automattic/newspack-newsletters/issues/743)) ([5b64218](https://github.com/Automattic/newspack-newsletters/commit/5b64218cf3dc96cb08967d40d29d599371bae513))
* height of block visibility when using floats ([#789](https://github.com/Automattic/newspack-newsletters/issues/789)) ([4bab9da](https://github.com/Automattic/newspack-newsletters/commit/4bab9da3561d339e7b85b9216f642f71d076bd1c))
* **layouts:** updating layouts ([e75dbcc](https://github.com/Automattic/newspack-newsletters/commit/e75dbcc01dd90d3396737f510e3bc58b3e33a3d8)), closes [#607](https://github.com/Automattic/newspack-newsletters/issues/607)


### Features

* add 2 new layouts ([#788](https://github.com/Automattic/newspack-newsletters/issues/788)) ([3af1f2f](https://github.com/Automattic/newspack-newsletters/commit/3af1f2fb66c5013babec04c2a9a8c47a4050fa84))
* add new layout ([#795](https://github.com/Automattic/newspack-newsletters/issues/795)) ([d941c87](https://github.com/Automattic/newspack-newsletters/commit/d941c8752201cc0230505edfed8eeaab31cba9da))
* reorganise layouts and add header group to all of them ([#796](https://github.com/Automattic/newspack-newsletters/issues/796)) ([385294c](https://github.com/Automattic/newspack-newsletters/commit/385294c49a40d9f9cfce52519623f48b6433006d))

# [1.45.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.44.0...v1.45.0-alpha.1) (2022-05-02)


### Bug Fixes

* add all accepted keys to get_post_type_labels() ([#743](https://github.com/Automattic/newspack-newsletters/issues/743)) ([5b64218](https://github.com/Automattic/newspack-newsletters/commit/5b64218cf3dc96cb08967d40d29d599371bae513))
* height of block visibility when using floats ([#789](https://github.com/Automattic/newspack-newsletters/issues/789)) ([4bab9da](https://github.com/Automattic/newspack-newsletters/commit/4bab9da3561d339e7b85b9216f642f71d076bd1c))
* **layouts:** updating layouts ([e75dbcc](https://github.com/Automattic/newspack-newsletters/commit/e75dbcc01dd90d3396737f510e3bc58b3e33a3d8)), closes [#607](https://github.com/Automattic/newspack-newsletters/issues/607)


### Features

* add 2 new layouts ([#788](https://github.com/Automattic/newspack-newsletters/issues/788)) ([3af1f2f](https://github.com/Automattic/newspack-newsletters/commit/3af1f2fb66c5013babec04c2a9a8c47a4050fa84))
* add new layout ([#795](https://github.com/Automattic/newspack-newsletters/issues/795)) ([d941c87](https://github.com/Automattic/newspack-newsletters/commit/d941c8752201cc0230505edfed8eeaab31cba9da))
* reorganise layouts and add header group to all of them ([#796](https://github.com/Automattic/newspack-newsletters/issues/796)) ([385294c](https://github.com/Automattic/newspack-newsletters/commit/385294c49a40d9f9cfce52519623f48b6433006d))

# [1.44.0](https://github.com/Automattic/newspack-newsletters/compare/v1.43.0...v1.44.0) (2022-04-18)


### Bug Fixes

* **layouts:** ensure selected layout is saved ([#786](https://github.com/Automattic/newspack-newsletters/issues/786)) ([5c659d1](https://github.com/Automattic/newspack-newsletters/commit/5c659d1bc3952c6786d22b89b5022c58fad3777d))


### Features

* ActiveCampaign esp integration ([#775](https://github.com/Automattic/newspack-newsletters/issues/775)) ([0d38151](https://github.com/Automattic/newspack-newsletters/commit/0d381510dc86b8eb7c548c5e3c101dd1c3e6a42d))
* add block support for Site Logo, Site Tagline, and Site Title ([#679](https://github.com/Automattic/newspack-newsletters/issues/679)) ([714b498](https://github.com/Automattic/newspack-newsletters/commit/714b4982b96690f031a9a0c509ebd1fd992d774e))
* simplify visibility option ([#733](https://github.com/Automattic/newspack-newsletters/issues/733)) ([d7ea865](https://github.com/Automattic/newspack-newsletters/commit/d7ea86546ac0c62728362008669d65aa2e484dca))

# [1.44.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v1.44.0-alpha.2...v1.44.0-alpha.3) (2022-04-13)


### Bug Fixes

* **layouts:** ensure selected layout is saved ([#786](https://github.com/Automattic/newspack-newsletters/issues/786)) ([5c659d1](https://github.com/Automattic/newspack-newsletters/commit/5c659d1bc3952c6786d22b89b5022c58fad3777d))

# [1.44.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.44.0-alpha.1...v1.44.0-alpha.2) (2022-04-08)


### Features

* ActiveCampaign esp integration ([#775](https://github.com/Automattic/newspack-newsletters/issues/775)) ([0d38151](https://github.com/Automattic/newspack-newsletters/commit/0d381510dc86b8eb7c548c5e3c101dd1c3e6a42d))

# [1.44.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.43.0...v1.44.0-alpha.1) (2022-04-07)


### Features

* add block support for Site Logo, Site Tagline, and Site Title ([#679](https://github.com/Automattic/newspack-newsletters/issues/679)) ([714b498](https://github.com/Automattic/newspack-newsletters/commit/714b4982b96690f031a9a0c509ebd1fd992d774e))
* simplify visibility option ([#733](https://github.com/Automattic/newspack-newsletters/issues/733)) ([d7ea865](https://github.com/Automattic/newspack-newsletters/commit/d7ea86546ac0c62728362008669d65aa2e484dca))

# [1.43.0](https://github.com/Automattic/newspack-newsletters/compare/v1.42.1...v1.43.0) (2022-04-05)


### Bug Fixes

* disable row group block variation ([#772](https://github.com/Automattic/newspack-newsletters/issues/772)) ([82224ff](https://github.com/Automattic/newspack-newsletters/commit/82224ff1e372ac6cc0e585f9fd774cf86e245e33))
* move subject back to the sidebar ([#773](https://github.com/Automattic/newspack-newsletters/issues/773)) ([46aad75](https://github.com/Automattic/newspack-newsletters/commit/46aad752ba45f4b36ba9eaf7530a3a5952c57a2b))


### Features

* allow theme customization ([#766](https://github.com/Automattic/newspack-newsletters/issues/766)) ([ebf3cca](https://github.com/Automattic/newspack-newsletters/commit/ebf3ccaac14a0df5e8641ce03d9404141c939793))
* minify and notify clipable email body ([#764](https://github.com/Automattic/newspack-newsletters/issues/764)) ([2c1c6a3](https://github.com/Automattic/newspack-newsletters/commit/2c1c6a32c3437b447324b4b33c26121668c5801e))

# [1.43.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.42.1...v1.43.0-alpha.1) (2022-03-31)


### Bug Fixes

* disable row group block variation ([#772](https://github.com/Automattic/newspack-newsletters/issues/772)) ([82224ff](https://github.com/Automattic/newspack-newsletters/commit/82224ff1e372ac6cc0e585f9fd774cf86e245e33))
* move subject back to the sidebar ([#773](https://github.com/Automattic/newspack-newsletters/issues/773)) ([46aad75](https://github.com/Automattic/newspack-newsletters/commit/46aad752ba45f4b36ba9eaf7530a3a5952c57a2b))


### Features

* allow theme customization ([#766](https://github.com/Automattic/newspack-newsletters/issues/766)) ([ebf3cca](https://github.com/Automattic/newspack-newsletters/commit/ebf3ccaac14a0df5e8641ce03d9404141c939793))
* minify and notify clipable email body ([#764](https://github.com/Automattic/newspack-newsletters/issues/764)) ([2c1c6a3](https://github.com/Automattic/newspack-newsletters/commit/2c1c6a32c3437b447324b4b33c26121668c5801e))

## [1.42.1](https://github.com/Automattic/newspack-newsletters/compare/v1.42.0...v1.42.1) (2022-03-22)


### Bug Fixes

* color palette css rules ([#757](https://github.com/Automattic/newspack-newsletters/issues/757)) ([2d929ac](https://github.com/Automattic/newspack-newsletters/commit/2d929ac8277b833aaceada281bd6eae73c465805))

## [1.42.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.42.0...v1.42.1-alpha.1) (2022-03-15)


### Bug Fixes

* color palette css rules ([#757](https://github.com/Automattic/newspack-newsletters/issues/757)) ([2d929ac](https://github.com/Automattic/newspack-newsletters/commit/2d929ac8277b833aaceada281bd6eae73c465805))

# [1.42.0](https://github.com/Automattic/newspack-newsletters/compare/v1.41.0...v1.42.0) (2022-03-08)


### Bug Fixes

* **mailchimp:** check send status ([#746](https://github.com/Automattic/newspack-newsletters/issues/746)) ([0be0ad9](https://github.com/Automattic/newspack-newsletters/commit/0be0ad9eafeedcfce4763002ec266313cbb0826a))
* php warning when unregistering a block pattern that isn't registered ([#738](https://github.com/Automattic/newspack-newsletters/issues/738)) ([8a2d0f6](https://github.com/Automattic/newspack-newsletters/commit/8a2d0f601c18733df0068593d768d68b41fa4ed5))
* support for image caption inner html ([#745](https://github.com/Automattic/newspack-newsletters/issues/745)) ([eed512c](https://github.com/Automattic/newspack-newsletters/commit/eed512c5144822ddd337ebe47af06b3ba081b48b))


### Features

* remove subject input (title) from the sidebar ([#735](https://github.com/Automattic/newspack-newsletters/issues/735)) ([969ff3b](https://github.com/Automattic/newspack-newsletters/commit/969ff3bfd1495d15333b1bf1c7d93287c39cffa5))

# [1.42.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.41.0...v1.42.0-alpha.1) (2022-02-24)


### Bug Fixes

* **mailchimp:** check send status ([#746](https://github.com/Automattic/newspack-newsletters/issues/746)) ([0be0ad9](https://github.com/Automattic/newspack-newsletters/commit/0be0ad9eafeedcfce4763002ec266313cbb0826a))
* php warning when unregistering a block pattern that isn't registered ([#738](https://github.com/Automattic/newspack-newsletters/issues/738)) ([8a2d0f6](https://github.com/Automattic/newspack-newsletters/commit/8a2d0f601c18733df0068593d768d68b41fa4ed5))
* support for image caption inner html ([#745](https://github.com/Automattic/newspack-newsletters/issues/745)) ([eed512c](https://github.com/Automattic/newspack-newsletters/commit/eed512c5144822ddd337ebe47af06b3ba081b48b))


### Features

* remove subject input (title) from the sidebar ([#735](https://github.com/Automattic/newspack-newsletters/issues/735)) ([969ff3b](https://github.com/Automattic/newspack-newsletters/commit/969ff3bfd1495d15333b1bf1c7d93287c39cffa5))

# [1.41.0](https://github.com/Automattic/newspack-newsletters/compare/v1.40.0...v1.41.0) (2022-02-22)


### Bug Fixes

* font size vertical rhythm for quote and paragraph ([#725](https://github.com/Automattic/newspack-newsletters/issues/725)) ([d656902](https://github.com/Automattic/newspack-newsletters/commit/d65690250d8e7d6a133cf834cf3e73500dfd109b))
* iframe styles for post inserter ([#723](https://github.com/Automattic/newspack-newsletters/issues/723)) ([d448daf](https://github.com/Automattic/newspack-newsletters/commit/d448daff469df1573496be617e215bbc0d6bf8fd))
* remove duotone filters ([#732](https://github.com/Automattic/newspack-newsletters/issues/732)) ([b1763c0](https://github.com/Automattic/newspack-newsletters/commit/b1763c097962596ffbc6d96edf04c99c30c8f47b))


### Features

* **post-inserter:** add featured image size option ([#712](https://github.com/Automattic/newspack-newsletters/issues/712)) ([b20b132](https://github.com/Automattic/newspack-newsletters/commit/b20b132868b593e5e54646ead212cfd51ebf6e67))
* remove wp-bundled block patterns ([#731](https://github.com/Automattic/newspack-newsletters/issues/731)) ([de0be75](https://github.com/Automattic/newspack-newsletters/commit/de0be75dc4d059cf1721c981903b4b43a9975101))
* support block padding ([#724](https://github.com/Automattic/newspack-newsletters/issues/724)) ([877f07f](https://github.com/Automattic/newspack-newsletters/commit/877f07fa52ad06247d15bbde2c2e99ceb7fdc623))
* update init modal design ([#710](https://github.com/Automattic/newspack-newsletters/issues/710)) ([5c8e273](https://github.com/Automattic/newspack-newsletters/commit/5c8e273a275c4fb5bd9e93a8b899ebfd5761aff7))

# [1.41.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.40.0...v1.41.0-alpha.1) (2022-02-10)


### Bug Fixes

* font size vertical rhythm for quote and paragraph ([#725](https://github.com/Automattic/newspack-newsletters/issues/725)) ([d656902](https://github.com/Automattic/newspack-newsletters/commit/d65690250d8e7d6a133cf834cf3e73500dfd109b))
* iframe styles for post inserter ([#723](https://github.com/Automattic/newspack-newsletters/issues/723)) ([d448daf](https://github.com/Automattic/newspack-newsletters/commit/d448daff469df1573496be617e215bbc0d6bf8fd))
* remove duotone filters ([#732](https://github.com/Automattic/newspack-newsletters/issues/732)) ([b1763c0](https://github.com/Automattic/newspack-newsletters/commit/b1763c097962596ffbc6d96edf04c99c30c8f47b))


### Features

* **post-inserter:** add featured image size option ([#712](https://github.com/Automattic/newspack-newsletters/issues/712)) ([b20b132](https://github.com/Automattic/newspack-newsletters/commit/b20b132868b593e5e54646ead212cfd51ebf6e67))
* remove wp-bundled block patterns ([#731](https://github.com/Automattic/newspack-newsletters/issues/731)) ([de0be75](https://github.com/Automattic/newspack-newsletters/commit/de0be75dc4d059cf1721c981903b4b43a9975101))
* support block padding ([#724](https://github.com/Automattic/newspack-newsletters/issues/724)) ([877f07f](https://github.com/Automattic/newspack-newsletters/commit/877f07fa52ad06247d15bbde2c2e99ceb7fdc623))
* update init modal design ([#710](https://github.com/Automattic/newspack-newsletters/issues/710)) ([5c8e273](https://github.com/Automattic/newspack-newsletters/commit/5c8e273a275c4fb5bd9e93a8b899ebfd5761aff7))

# [1.40.0](https://github.com/Automattic/newspack-newsletters/compare/v1.39.0...v1.40.0) (2022-02-08)


### Bug Fixes

* require postcss explicitly ([#708](https://github.com/Automattic/newspack-newsletters/issues/708)) ([624e320](https://github.com/Automattic/newspack-newsletters/commit/624e320c566e1589ed102210f13cab401e2d380f))


### Features

* add new keys to settings list ([#694](https://github.com/Automattic/newspack-newsletters/issues/694)) ([d49afd2](https://github.com/Automattic/newspack-newsletters/commit/d49afd216e884ff3a9e977507fab21f89e7fc5e4))
* update merge tags style ([#680](https://github.com/Automattic/newspack-newsletters/issues/680)) ([2b76f29](https://github.com/Automattic/newspack-newsletters/commit/2b76f29b2538db1df05c9f6e7ad6638986bbb1e6))

# [1.40.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.39.0...v1.40.0-alpha.1) (2022-01-27)


### Bug Fixes

* require postcss explicitly ([#708](https://github.com/Automattic/newspack-newsletters/issues/708)) ([624e320](https://github.com/Automattic/newspack-newsletters/commit/624e320c566e1589ed102210f13cab401e2d380f))


### Features

* add new keys to settings list ([#694](https://github.com/Automattic/newspack-newsletters/issues/694)) ([d49afd2](https://github.com/Automattic/newspack-newsletters/commit/d49afd216e884ff3a9e977507fab21f89e7fc5e4))
* update merge tags style ([#680](https://github.com/Automattic/newspack-newsletters/issues/680)) ([2b76f29](https://github.com/Automattic/newspack-newsletters/commit/2b76f29b2538db1df05c9f6e7ad6638986bbb1e6))

# [1.39.0](https://github.com/Automattic/newspack-newsletters/compare/v1.38.0...v1.39.0) (2022-01-19)


### Bug Fixes

* append layout preview css to iframe head ([#688](https://github.com/Automattic/newspack-newsletters/issues/688)) ([231ebcc](https://github.com/Automattic/newspack-newsletters/commit/231ebccfda6bdab3a54cb3a9a33910d472c7b8b5))
* php warning when converting non-string attributes to mjml ([#697](https://github.com/Automattic/newspack-newsletters/issues/697)) ([7730e1b](https://github.com/Automattic/newspack-newsletters/commit/7730e1beb1222f19fe312628ded46533b0d424e4))
* **post-inserter:** preview hook on top level component ([#696](https://github.com/Automattic/newspack-newsletters/issues/696)) ([244bdef](https://github.com/Automattic/newspack-newsletters/commit/244bdefc4ee88abc270c6f53e4261fa50625d74d))
* **wp5.9:** layout and post inserter previews ([2abb6e2](https://github.com/Automattic/newspack-newsletters/commit/2abb6e224e8898ad0bda044739435664211203ff))


### Features

* reorganise sidebar ([#655](https://github.com/Automattic/newspack-newsletters/issues/655)) ([cfaa075](https://github.com/Automattic/newspack-newsletters/commit/cfaa0756c0ee7ad485b1fd8cc66f25f6497d7c12))

# [1.39.0-alpha.3](https://github.com/Automattic/newspack-newsletters/compare/v1.39.0-alpha.2...v1.39.0-alpha.3) (2022-01-18)


### Bug Fixes

* php warning when converting non-string attributes to mjml ([#697](https://github.com/Automattic/newspack-newsletters/issues/697)) ([7730e1b](https://github.com/Automattic/newspack-newsletters/commit/7730e1beb1222f19fe312628ded46533b0d424e4))
* **post-inserter:** preview hook on top level component ([#696](https://github.com/Automattic/newspack-newsletters/issues/696)) ([244bdef](https://github.com/Automattic/newspack-newsletters/commit/244bdefc4ee88abc270c6f53e4261fa50625d74d))

# [1.39.0-alpha.2](https://github.com/Automattic/newspack-newsletters/compare/v1.39.0-alpha.1...v1.39.0-alpha.2) (2022-01-18)


### Bug Fixes

* append layout preview css to iframe head ([#688](https://github.com/Automattic/newspack-newsletters/issues/688)) ([231ebcc](https://github.com/Automattic/newspack-newsletters/commit/231ebccfda6bdab3a54cb3a9a33910d472c7b8b5))
* **wp5.9:** layout and post inserter previews ([2abb6e2](https://github.com/Automattic/newspack-newsletters/commit/2abb6e224e8898ad0bda044739435664211203ff))

# [1.39.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.38.0...v1.39.0-alpha.1) (2022-01-06)


### Features

* reorganise sidebar ([#655](https://github.com/Automattic/newspack-newsletters/issues/655)) ([cfaa075](https://github.com/Automattic/newspack-newsletters/commit/cfaa0756c0ee7ad485b1fd8cc66f25f6497d7c12))

# [1.38.0](https://github.com/Automattic/newspack-newsletters/compare/v1.37.0...v1.38.0) (2022-01-04)


### Bug Fixes

* editor script data ([#650](https://github.com/Automattic/newspack-newsletters/issues/650)) ([54f01e7](https://github.com/Automattic/newspack-newsletters/commit/54f01e7a36bad303f110d7f6eefa3b17f7401b5f))


### Features

* move styling settings to its own sidebar ([#643](https://github.com/Automattic/newspack-newsletters/issues/643)) ([b610daa](https://github.com/Automattic/newspack-newsletters/commit/b610daae10fbe8398a2da5cdb69d84f50bedd7ec))
* update vertical rhythm and various blocks to match sent email ([#644](https://github.com/Automattic/newspack-newsletters/issues/644)) ([571c03d](https://github.com/Automattic/newspack-newsletters/commit/571c03d1d8df1c902b138bd5e20bfa4aa027ea8d))

# [1.38.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.37.0...v1.38.0-alpha.1) (2021-12-16)


### Bug Fixes

* editor script data ([#650](https://github.com/Automattic/newspack-newsletters/issues/650)) ([54f01e7](https://github.com/Automattic/newspack-newsletters/commit/54f01e7a36bad303f110d7f6eefa3b17f7401b5f))


### Features

* move styling settings to its own sidebar ([#643](https://github.com/Automattic/newspack-newsletters/issues/643)) ([b610daa](https://github.com/Automattic/newspack-newsletters/commit/b610daae10fbe8398a2da5cdb69d84f50bedd7ec))
* update vertical rhythm and various blocks to match sent email ([#644](https://github.com/Automattic/newspack-newsletters/issues/644)) ([571c03d](https://github.com/Automattic/newspack-newsletters/commit/571c03d1d8df1c902b138bd5e20bfa4aa027ea8d))

# [1.37.0](https://github.com/Automattic/newspack-newsletters/compare/v1.36.1...v1.37.0) (2021-12-14)


### Bug Fixes

* proper escaping function ([ca908c7](https://github.com/Automattic/newspack-newsletters/commit/ca908c7b9b93b1a2a6513ac8818de1f8817cd623))


### Features

* Letterhead advertising integration ([#399](https://github.com/Automattic/newspack-newsletters/issues/399)) ([3fc376a](https://github.com/Automattic/newspack-newsletters/commit/3fc376a810d4292697f4682156cf6bedfd931f0b))

# [1.37.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.36.1...v1.37.0-alpha.1) (2021-12-09)


### Bug Fixes

* proper escaping function ([ca908c7](https://github.com/Automattic/newspack-newsletters/commit/ca908c7b9b93b1a2a6513ac8818de1f8817cd623))


### Features

* Letterhead advertising integration ([#399](https://github.com/Automattic/newspack-newsletters/issues/399)) ([3fc376a](https://github.com/Automattic/newspack-newsletters/commit/3fc376a810d4292697f4682156cf6bedfd931f0b))

## [1.36.1](https://github.com/Automattic/newspack-newsletters/compare/v1.36.0...v1.36.1) (2021-11-30)


### Bug Fixes

* fix public newsletter queries ([5f403a8](https://github.com/Automattic/newspack-newsletters/commit/5f403a870d3cbb029c1d2cdbe0216c7cca41bf0b))
* trigger alpha release ([d402640](https://github.com/Automattic/newspack-newsletters/commit/d40264089ff3d7bea5acce51c0ffb4d159b39bb9))
* trigger release for author archives fix ([#622](https://github.com/Automattic/newspack-newsletters/issues/622)) ([ea51ada](https://github.com/Automattic/newspack-newsletters/commit/ea51ada110775c14b8c747c0759636ba0fc04726))

## [1.36.1-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.36.0...v1.36.1-alpha.1) (2021-11-30)


### Bug Fixes

* fix public newsletter queries ([5f403a8](https://github.com/Automattic/newspack-newsletters/commit/5f403a870d3cbb029c1d2cdbe0216c7cca41bf0b))
* trigger alpha release ([d402640](https://github.com/Automattic/newspack-newsletters/commit/d40264089ff3d7bea5acce51c0ffb4d159b39bb9))
* trigger release for author archives fix ([#622](https://github.com/Automattic/newspack-newsletters/issues/622)) ([ea51ada](https://github.com/Automattic/newspack-newsletters/commit/ea51ada110775c14b8c747c0759636ba0fc04726))

# [1.36.0](https://github.com/Automattic/newspack-newsletters/compare/v1.35.1...v1.36.0) (2021-11-18)


### Bug Fixes

* change MC API key option name ([#597](https://github.com/Automattic/newspack-newsletters/issues/597)) ([76275e8](https://github.com/Automattic/newspack-newsletters/commit/76275e8c540696eba342b473e8a1c1107d5f073b))
* non-public newsletters should not surface in search results ([7de9262](https://github.com/Automattic/newspack-newsletters/commit/7de92624ce117b78ac7785376fb13ad8d83596d8))


### Features

* allow custom hooks in the email editor ([21e5034](https://github.com/Automattic/newspack-newsletters/commit/21e5034693ddf7129b513c67e6a46477d8ad671a))

# [1.36.0-alpha.1](https://github.com/Automattic/newspack-newsletters/compare/v1.35.1...v1.36.0-alpha.1) (2021-11-16)


### Bug Fixes

* change MC API key option name ([#597](https://github.com/Automattic/newspack-newsletters/issues/597)) ([76275e8](https://github.com/Automattic/newspack-newsletters/commit/76275e8c540696eba342b473e8a1c1107d5f073b))
* non-public newsletters should not surface in search results ([7de9262](https://github.com/Automattic/newspack-newsletters/commit/7de92624ce117b78ac7785376fb13ad8d83596d8))


### Features

* allow custom hooks in the email editor ([21e5034](https://github.com/Automattic/newspack-newsletters/commit/21e5034693ddf7129b513c67e6a46477d8ad671a))

## [1.35.1](https://github.com/Automattic/newspack-newsletters/compare/v1.35.0...v1.35.1) (2021-11-03)


### Bug Fixes

* allow social-link inner block ([#603](https://github.com/Automattic/newspack-newsletters/issues/603)) ([ab2f892](https://github.com/Automattic/newspack-newsletters/commit/ab2f8926765ff6216bf87ef7be15ddad9159d7c2))
* **mailchimp:** segment selection and saved segments ([#605](https://github.com/Automattic/newspack-newsletters/issues/605)) ([e0b62e3](https://github.com/Automattic/newspack-newsletters/commit/e0b62e329ce83da7e7815d1c34a635e34d0038c3))
* enable prompts on public newsletters ([7e2290b](https://github.com/Automattic/newspack-newsletters/commit/7e2290b66bd85bae4d47a784fdc5a873c4ba1374))
* merge color palette values ([#608](https://github.com/Automattic/newspack-newsletters/issues/608)) ([9ba2bae](https://github.com/Automattic/newspack-newsletters/commit/9ba2bae41babf161ac89244946633cf116caf029))

# [1.35.0](https://github.com/Automattic/newspack-newsletters/compare/v1.34.0...v1.35.0) (2021-10-26)


### Bug Fixes

* handle empty button blocks ([#600](https://github.com/Automattic/newspack-newsletters/issues/600)) ([b6ec555](https://github.com/Automattic/newspack-newsletters/commit/b6ec555e5f204762190bb7c2fce195a572778e62))
* mailchimp segments timeout ([#601](https://github.com/Automattic/newspack-newsletters/issues/601)) ([0c99ead](https://github.com/Automattic/newspack-newsletters/commit/0c99eaddbc2163278e1a80b4773cd7f416465b31))


### Features

* **editor:** allow other post types to be treated as email ([1442a46](https://github.com/Automattic/newspack-newsletters/commit/1442a465b84aa08dfaa0c05e49363a414557cddc))

# [1.34.0](https://github.com/Automattic/newspack-newsletters/compare/v1.33.1...v1.34.0) (2021-10-19)


### Features

* block visibility attribute ([#591](https://github.com/Automattic/newspack-newsletters/issues/591)) ([300eae5](https://github.com/Automattic/newspack-newsletters/commit/300eae57f6617dc8721af6eb7eb99ce455aaa4dd))

## [1.33.1](https://github.com/Automattic/newspack-newsletters/compare/v1.33.0...v1.33.1) (2021-09-14)


### Bug Fixes

* handle unavailable lists ([#585](https://github.com/Automattic/newspack-newsletters/issues/585)) ([c84cc77](https://github.com/Automattic/newspack-newsletters/commit/c84cc779d4d55deb5a26fbe6b3a224dd414e2533))
* manual provider setting ([1c8811a](https://github.com/Automattic/newspack-newsletters/commit/1c8811a03635ed166007635af6086d8885a9f141))

# [1.33.0](https://github.com/Automattic/newspack-newsletters/compare/v1.32.1...v1.33.0) (2021-09-08)


### Bug Fixes

* list info in pre-send modal ([6a615fb](https://github.com/Automattic/newspack-newsletters/commit/6a615fb74cc6025c96c8fc04ca6a715c27619656))
* use WP_Http instead of Guzzle ([#582](https://github.com/Automattic/newspack-newsletters/issues/582)) ([5052004](https://github.com/Automattic/newspack-newsletters/commit/5052004ff75b2eb10d3559ee97fab2ce7bc7edf2))


### Features

* add support for embed blocks ([#583](https://github.com/Automattic/newspack-newsletters/issues/583)) ([53a2f57](https://github.com/Automattic/newspack-newsletters/commit/53a2f576b3924df62422ade8c9a263b12f1540f3))
* autocompleter for mailchimp merge tags ([#575](https://github.com/Automattic/newspack-newsletters/issues/575)) ([412d39b](https://github.com/Automattic/newspack-newsletters/commit/412d39b11d3505cf0c9cfe51625f1ce69ef92bfe))

## [1.32.1](https://github.com/Automattic/newspack-newsletters/compare/v1.32.0...v1.32.1) (2021-08-31)


### Bug Fixes

* mailchimp lists ([3d095c5](https://github.com/Automattic/newspack-newsletters/commit/3d095c560228f83a582e5ac630f84c68dca309ac))

# [1.32.0](https://github.com/Automattic/newspack-newsletters/compare/v1.31.5...v1.32.0) (2021-08-31)


### Bug Fixes

* post inserter default styles ([#576](https://github.com/Automattic/newspack-newsletters/issues/576)) ([93d44ed](https://github.com/Automattic/newspack-newsletters/commit/93d44ed706730e1f5d72ed6a4d6243f6542309d4))


### Features

* **esp:** lists getters and contanct adding ([#546](https://github.com/Automattic/newspack-newsletters/issues/546)) ([cc351cb](https://github.com/Automattic/newspack-newsletters/commit/cc351cbd2bf7bc5e3204e3c24504d4a8452e465e))

## [1.31.5](https://github.com/Automattic/newspack-newsletters/compare/v1.31.4...v1.31.5) (2021-08-27)


### Bug Fixes

* wporg release ([#572](https://github.com/Automattic/newspack-newsletters/issues/572)) ([4ad2208](https://github.com/Automattic/newspack-newsletters/commit/4ad2208a19abcdbe8cd43d048f8d7f336f27f8e7))

## [1.31.4](https://github.com/Automattic/newspack-newsletters/compare/v1.31.3...v1.31.4) (2021-08-26)


### Bug Fixes

* **npm:** list codemirror as a dependency ([bf66d95](https://github.com/Automattic/newspack-newsletters/commit/bf66d958abbf6f3a4ddc19b00ba75c417a651359))
* convert encoding before passing to DomDocument ([#565](https://github.com/Automattic/newspack-newsletters/issues/565)) ([bfd3915](https://github.com/Automattic/newspack-newsletters/commit/bfd3915d635bb687d52e3118252c9805f69a9fe4))
* do not sync on autosave ([#563](https://github.com/Automattic/newspack-newsletters/issues/563)) ([ffbca87](https://github.com/Automattic/newspack-newsletters/commit/ffbca87ede425400f49414549afefae1a86b0be5))
* modify selectorText instead of cssText ([#564](https://github.com/Automattic/newspack-newsletters/issues/564)) ([0400957](https://github.com/Automattic/newspack-newsletters/commit/0400957803d83b39a51cd5f9c4c9c1c65cbaad53))
* prevent PHPCS from checking vendor files during release ([beab342](https://github.com/Automattic/newspack-newsletters/commit/beab342e561d817b51bed63dc0fb11f4d6d5620b))

## [1.31.3](https://github.com/Automattic/newspack-newsletters/compare/v1.31.2...v1.31.3) (2021-08-25)


### Bug Fixes

* mailchimp recipients and segmentation options ([#556](https://github.com/Automattic/newspack-newsletters/issues/556)) ([7108d08](https://github.com/Automattic/newspack-newsletters/commit/7108d0886da290a65651ccb15bd5c8a8299b4e68))
* prevent sender data overwrite on updates ([54b46fa](https://github.com/Automattic/newspack-newsletters/commit/54b46fa5e62530d1f4f7cf7dd6ee0408591cad85))
* typos on "support" layout ([#559](https://github.com/Automattic/newspack-newsletters/issues/559)) ([e33833f](https://github.com/Automattic/newspack-newsletters/commit/e33833f13a5bd12d171f13fa8332ca2d3f70e9ea))
* update stylelint version ([#560](https://github.com/Automattic/newspack-newsletters/issues/560)) ([c7731c3](https://github.com/Automattic/newspack-newsletters/commit/c7731c3d366bee740dade0343582a553765997d1))

## [1.31.2](https://github.com/Automattic/newspack-newsletters/compare/v1.31.1...v1.31.2) (2021-08-17)


### Bug Fixes

* add custom css to layouts ([#540](https://github.com/Automattic/newspack-newsletters/issues/540)) ([a10afb6](https://github.com/Automattic/newspack-newsletters/commit/a10afb68d268bc6ba2522cf7d71c933f2d576edc))
* custom namespace for Guzzle ([#548](https://github.com/Automattic/newspack-newsletters/issues/548)) ([d5ec053](https://github.com/Automattic/newspack-newsletters/commit/d5ec05351e4e75ec083a57d47b2e9a9422ab64a2))
* unbind prefix command from composer dump event ([#553](https://github.com/Automattic/newspack-newsletters/issues/553)) ([cedc3b6](https://github.com/Automattic/newspack-newsletters/commit/cedc3b6a010dd6e9ad69d7d78ee889f49041c15b))

## [1.31.1](https://github.com/Automattic/newspack-newsletters/compare/v1.31.0...v1.31.1) (2021-08-10)


### Bug Fixes

* do not show mc_campaign_id in rest ([#537](https://github.com/Automattic/newspack-newsletters/issues/537)) ([e73cb8d](https://github.com/Automattic/newspack-newsletters/commit/e73cb8dd53ba3b551c25d2212335dafefa3ff107))
* ensure sync on test email and handle failed publishing ([#534](https://github.com/Automattic/newspack-newsletters/issues/534)) ([5550169](https://github.com/Automattic/newspack-newsletters/commit/55501699812e2d67167c7fde6d1127e4897178f0))
* renderer button styling ([#536](https://github.com/Automattic/newspack-newsletters/issues/536)) ([f9540e0](https://github.com/Automattic/newspack-newsletters/commit/f9540e0799fbe12cf6dbf390e08e3baecc00a847))

# [1.31.0](https://github.com/Automattic/newspack-newsletters/compare/v1.30.2...v1.31.0) (2021-08-03)


### Bug Fixes

* add campaign monitor to readme ([#531](https://github.com/Automattic/newspack-newsletters/issues/531)) ([79949f4](https://github.com/Automattic/newspack-newsletters/commit/79949f42eca33386605e491cb1a835bd27bcef79))


### Features

* subtitle support for post inserter ([#530](https://github.com/Automattic/newspack-newsletters/issues/530)) ([f4b1c60](https://github.com/Automattic/newspack-newsletters/commit/f4b1c60246b7746773fb986be8a3efa338acde36))

## [1.30.2](https://github.com/Automattic/newspack-newsletters/compare/v1.30.1...v1.30.2) (2021-07-29)


### Bug Fixes

* composer dependency bug ([0d0dc20](https://github.com/Automattic/newspack-newsletters/commit/0d0dc20d6c8377c6bd38ce2e1398ea7abc9dcce3))

## [1.30.1](https://github.com/Automattic/newspack-newsletters/compare/v1.30.0...v1.30.1) (2021-07-29)


### Bug Fixes

* trigger release build ([02493fc](https://github.com/Automattic/newspack-newsletters/commit/02493fcca5dcf94e9af02a6af24d87adf9bada5d))

# [1.30.0](https://github.com/Automattic/newspack-newsletters/compare/v1.29.2...v1.30.0) (2021-07-27)


### Bug Fixes

* allow text formatting assets in the editor ([#521](https://github.com/Automattic/newspack-newsletters/issues/521)) ([afa16ff](https://github.com/Automattic/newspack-newsletters/commit/afa16ffff6a30e78af277943c5c2acf83f1035ca))


### Features

* share to a friend block ([#518](https://github.com/Automattic/newspack-newsletters/issues/518)) ([6adf4ca](https://github.com/Automattic/newspack-newsletters/commit/6adf4ca7f1dffd95c46c783d651ba0310232e6ab)), closes [#349](https://github.com/Automattic/newspack-newsletters/issues/349)

## [1.29.2](https://github.com/Automattic/newspack-newsletters/compare/v1.29.1...v1.29.2) (2021-07-19)


### Bug Fixes

* ensure post object for syncs ([#507](https://github.com/Automattic/newspack-newsletters/issues/507)) ([9a3afc3](https://github.com/Automattic/newspack-newsletters/commit/9a3afc3b781398927cc5ddaf29a37a8c9853de9e))
* sync instead of erroring for missing campaign ([#505](https://github.com/Automattic/newspack-newsletters/issues/505)) ([7a0dd2f](https://github.com/Automattic/newspack-newsletters/commit/7a0dd2fee2fc05b07e7300d1ac188d68263c5e0a))
* **wp-5.8:** ensure string for displayTransform ([#504](https://github.com/Automattic/newspack-newsletters/issues/504)) ([ec4de19](https://github.com/Automattic/newspack-newsletters/commit/ec4de192ee1814eb613087b5cf4ad91577ab36ea))
* change allowed_block_types filter name (WARNING: incompatible w/ WP <5.8) ([#498](https://github.com/Automattic/newspack-newsletters/issues/498)) ([f7ba7f1](https://github.com/Automattic/newspack-newsletters/commit/f7ba7f129895f84c1038673153b990af2b53244a))

## [1.29.1](https://github.com/Automattic/newspack-newsletters/compare/v1.29.0...v1.29.1) (2021-07-13)


### Bug Fixes

* remove non-existent enqueued `adsEditor` styles ([#492](https://github.com/Automattic/newspack-newsletters/issues/492)) ([5f8aed2](https://github.com/Automattic/newspack-newsletters/commit/5f8aed2de5768e45e6969c37b6d568f5ed154fb8))

# [1.29.0](https://github.com/Automattic/newspack-newsletters/compare/v1.28.0...v1.29.0) (2021-07-06)


### Bug Fixes

* allow editor users to edit and send newsletters ([#485](https://github.com/Automattic/newspack-newsletters/issues/485)) ([4e74538](https://github.com/Automattic/newspack-newsletters/commit/4e745386c3b973ea1ef26f54dbf5f832b36af0bb))
* destructure testEmail and onChangeEmail along with other props ([113654e](https://github.com/Automattic/newspack-newsletters/commit/113654eba9063f3f3dfc8f079df55882f25a4800))
* ensure `testEmail` is string ([bdcf3ce](https://github.com/Automattic/newspack-newsletters/commit/bdcf3cebe54b5be6511e536179ea84763be4a159))
* grammar tweak on admin notice ([3fd4fa0](https://github.com/Automattic/newspack-newsletters/commit/3fd4fa08461c2748edbdc91f1a401ac251f849db))
* move `testEmail` state to `NewsletterEdit` component ([6272dd5](https://github.com/Automattic/newspack-newsletters/commit/6272dd5e71d8dfc6769ec6f3b68fdd2d19e91376))
* remove redundant status text ([d7aabc3](https://github.com/Automattic/newspack-newsletters/commit/d7aabc35b10a6b058a34d4cec08e15cfdfb802da))
* typo on admin notice and bulk action label ([0af9e23](https://github.com/Automattic/newspack-newsletters/commit/0af9e230f6086adfbdac1fa6655f77707210bb7c))
* typo on bulk action label ([379c1b9](https://github.com/Automattic/newspack-newsletters/commit/379c1b950170118b0e2dbec2fd8063ed8ee67b74))
* update js with new data attribute element ([67957bc](https://github.com/Automattic/newspack-newsletters/commit/67957bce7e577e4765d16d01cee62af32e6ea568))
* update phpcs:disable nonce verification with comments ([8aad986](https://github.com/Automattic/newspack-newsletters/commit/8aad9869b68bfcb2947ee86b65f0ca6e664213c9))
* use `$user_id` ([441532c](https://github.com/Automattic/newspack-newsletters/commit/441532cb676770335063aad7e3d9222971530c5d))
* use `save_post_${post_type}` for quick edit save ([50598c0](https://github.com/Automattic/newspack-newsletters/commit/50598c09751a3397944755a35332e15f5c15bdfa))
* use single element with data attribute for inline data ([9fd2559](https://github.com/Automattic/newspack-newsletters/commit/9fd2559a62a37b5faf61aac6195c1e9f4d6c4651))


### Features

* persist user test emails as user meta ([ad53b42](https://github.com/Automattic/newspack-newsletters/commit/ad53b42af90ec179d05e64c212f0283524f732c9))
* refactor bulk actions and quick edit ([60ed3cb](https://github.com/Automattic/newspack-newsletters/commit/60ed3cb8ed7730b6f8810d78d819d7ef883019a4))
* use user email address as default test email ([7e669fd](https://github.com/Automattic/newspack-newsletters/commit/7e669fd8c17865c7bb870a2200fc2d3e0463203f))

# [1.28.0](https://github.com/Automattic/newspack-newsletters/compare/v1.27.1...v1.28.0) (2021-06-22)


### Features

* update colors to use latest WP base-styles  ([#477](https://github.com/Automattic/newspack-newsletters/issues/477)) ([a3ce0cd](https://github.com/Automattic/newspack-newsletters/commit/a3ce0cd0beeebff778af217988d05b6410e095bd))

## [1.27.1](https://github.com/Automattic/newspack-newsletters/compare/v1.27.0...v1.27.1) (2021-06-15)


### Bug Fixes

* prevent gravityforms scripts ([72e47c9](https://github.com/Automattic/newspack-newsletters/commit/72e47c957180d4ea66f5adf31fe458bcc4df0cd3))

# [1.27.0](https://github.com/Automattic/newspack-newsletters/compare/v1.26.0...v1.27.0) (2021-06-08)


### Features

* register posts inserter block if editing a reusable block ([99ea55a](https://github.com/Automattic/newspack-newsletters/commit/99ea55a66c3035e34b68e21e40bd88209da064de))

# [1.26.0](https://github.com/Automattic/newspack-newsletters/compare/v1.25.0...v1.26.0) (2021-05-13)


### Features

* assorted fixes & improvements ([#456](https://github.com/Automattic/newspack-newsletters/issues/456)) ([9716465](https://github.com/Automattic/newspack-newsletters/commit/97164659d3993b89de089a74fdd3d610da51859d)), closes [#296](https://github.com/Automattic/newspack-newsletters/issues/296)

# [1.25.0](https://github.com/Automattic/newspack-newsletters/compare/v1.24.1...v1.25.0) (2021-05-04)


### Features

* custom CSS ([#454](https://github.com/Automattic/newspack-newsletters/issues/454)) ([748247c](https://github.com/Automattic/newspack-newsletters/commit/748247c14a1c3ca22bdf5d041162f00ddbda9f0a))

## [1.24.1](https://github.com/Automattic/newspack-newsletters/compare/v1.24.0...v1.24.1) (2021-04-28)


### Bug Fixes

* handle reusable blocks ([56c6d87](https://github.com/Automattic/newspack-newsletters/commit/56c6d87ab9c0c282d6628d0b35f99a5259e446dc)), closes [#344](https://github.com/Automattic/newspack-newsletters/issues/344)

# [1.24.0](https://github.com/Automattic/newspack-newsletters/compare/v1.23.1...v1.24.0) (2021-04-20)


### Bug Fixes

* minor bugfixes ([#447](https://github.com/Automattic/newspack-newsletters/issues/447)) ([0b1e976](https://github.com/Automattic/newspack-newsletters/commit/0b1e9767f37918b4a05ab0e2d62a86bec0e18f16))


### Features

* convert MJML using the browser ([#443](https://github.com/Automattic/newspack-newsletters/issues/443)) ([9171e49](https://github.com/Automattic/newspack-newsletters/commit/9171e498edcddd946d716e96dba93bcf259590a5)), closes [#400](https://github.com/Automattic/newspack-newsletters/issues/400)

## [1.23.1](https://github.com/Automattic/newspack-newsletters/compare/v1.23.0...v1.23.1) (2021-04-13)


### Bug Fixes

* avoid potentially undefined nested property ([#441](https://github.com/Automattic/newspack-newsletters/issues/441)) ([b6d12c9](https://github.com/Automattic/newspack-newsletters/commit/b6d12c9c28cf34d1d429551a9dfd99c22fd7c612))

# [1.23.0](https://github.com/Automattic/newspack-newsletters/compare/v1.22.0...v1.23.0) (2021-04-06)


### Features

* **ci:** releasing on WPORG ([b84dbc5](https://github.com/Automattic/newspack-newsletters/commit/b84dbc5a9fe4c9f50d9d42c716de2f1d087c84f2))

# [1.22.0](https://github.com/Automattic/newspack-newsletters/compare/v1.21.0...v1.22.0) (2021-03-30)


### Bug Fixes

* respect excerpt length setting while not messing up site excerpts ([#428](https://github.com/Automattic/newspack-newsletters/issues/428)) ([2c8d674](https://github.com/Automattic/newspack-newsletters/commit/2c8d6745b8be790deb5b5626a2fd769d1d17f676))


### Features

* add support for excerpts for newsletter CPTs ([#433](https://github.com/Automattic/newspack-newsletters/issues/433)) ([09f1800](https://github.com/Automattic/newspack-newsletters/commit/09f1800442e03aab2900dbfa74092a84a19e2f48))

# [1.21.0](https://github.com/Automattic/newspack-newsletters/compare/v1.20.0...v1.21.0) (2021-03-23)


### Features

* add filter for Mailchimp campaign sync payload ([#394](https://github.com/Automattic/newspack-newsletters/issues/394)) ([aac44bb](https://github.com/Automattic/newspack-newsletters/commit/aac44bb333222f88c84e3e0b6015b26fcf3c778a))
* featured images and pre_get_posts refactor ([#425](https://github.com/Automattic/newspack-newsletters/issues/425)) ([1a1f912](https://github.com/Automattic/newspack-newsletters/commit/1a1f91206227cb58b478b102ed2d456ff4a0ccda))

# [1.20.0](https://github.com/Automattic/newspack-newsletters/compare/v1.19.0...v1.20.0) (2021-03-16)


### Bug Fixes

* colors of headings with links; top block space in editor ([3a720a6](https://github.com/Automattic/newspack-newsletters/commit/3a720a6b4a4a73edbb62df701bf24ea8f8a4bab7))
* editor colors ([02e2057](https://github.com/Automattic/newspack-newsletters/commit/02e205706beca8d759c6ccb3f22ad83a91e2b8a6))
* only show public newsletters on CPT archive pages ([#406](https://github.com/Automattic/newspack-newsletters/issues/406)) ([ec42ceb](https://github.com/Automattic/newspack-newsletters/commit/ec42ceb479ad1073aaba54ee5655966fcfe98621))


### Features

* add revisions support for newsletter CPTs ([#420](https://github.com/Automattic/newspack-newsletters/issues/420)) ([3e51831](https://github.com/Automattic/newspack-newsletters/commit/3e518315f92b0b6fde38ddac66e918ab2e8c00cf))

# [1.19.0](https://github.com/Automattic/newspack-newsletters/compare/v1.18.0...v1.19.0) (2021-03-03)


### Bug Fixes

* **ads:** prevent draft ads insertion ([#410](https://github.com/Automattic/newspack-newsletters/issues/410)) ([381dc51](https://github.com/Automattic/newspack-newsletters/commit/381dc5174806118d50da07d7bb89d40f073146a1))


### Features

* add settings update method ([#407](https://github.com/Automattic/newspack-newsletters/issues/407)) ([158a2dc](https://github.com/Automattic/newspack-newsletters/commit/158a2dccd05ff37b100aa1b5619a31a21f10571e))
* provide settings values early ([e5e078d](https://github.com/Automattic/newspack-newsletters/commit/e5e078dd53825e003fd3fc1ce3224960ade4e314))

# [1.18.0](https://github.com/Automattic/newspack-newsletters/compare/v1.17.0...v1.18.0) (2021-01-28)


### Features

* make Newsletters use post categories + tags ([#398](https://github.com/Automattic/newspack-newsletters/issues/398)) ([23b97d4](https://github.com/Automattic/newspack-newsletters/commit/23b97d47a4bde471e34cd50447792f662963c2f1))

# [1.17.0](https://github.com/Automattic/newspack-newsletters/compare/v1.16.2...v1.17.0) (2021-01-19)


### Features

* add support for homepage posts block ([#393](https://github.com/Automattic/newspack-newsletters/issues/393)) ([710b674](https://github.com/Automattic/newspack-newsletters/commit/710b674e9e326079d113c54b87d1acf938224b78))

## [1.16.2](https://github.com/Automattic/newspack-newsletters/compare/v1.16.1...v1.16.2) (2021-01-12)


### Bug Fixes

* alignment for elements using textAlign ([#390](https://github.com/Automattic/newspack-newsletters/issues/390)) ([405c33e](https://github.com/Automattic/newspack-newsletters/commit/405c33e734ebcf9656b6ddba405df3f10ac440af))
* longer timeout for test send ([#389](https://github.com/Automattic/newspack-newsletters/issues/389)) ([0d18659](https://github.com/Automattic/newspack-newsletters/commit/0d1865902b9eed57cdb7e0c79f3ac9f20c67ea55))
* **mjml:** error handling ([f47fdf4](https://github.com/Automattic/newspack-newsletters/commit/f47fdf454f90682168c9f46e63b3547937de96af))

## [1.16.1](https://github.com/Automattic/newspack-newsletters/compare/v1.16.0...v1.16.1) (2020-12-09)


### Bug Fixes

* empty commit to trigger a release after CI job timeout ([0273e2a](https://github.com/Automattic/newspack-newsletters/commit/0273e2a5aaa4067f3f6671550fbd02bd3ab9e50b))
* image links ([23a6dc9](https://github.com/Automattic/newspack-newsletters/commit/23a6dc98626a48aac3e87ad1fe293d7031acfcb6))

# [1.16.0](https://github.com/Automattic/newspack-newsletters/compare/v1.15.1...v1.16.0) (2020-12-09)


### Features

* setting to opt-in to mailchimp tags ([#380](https://github.com/Automattic/newspack-newsletters/issues/380)) ([a9e0c66](https://github.com/Automattic/newspack-newsletters/commit/a9e0c66a75e917a853053b1c3c178b97ef5d6957))

## [1.15.1](https://github.com/Automattic/newspack-newsletters/compare/v1.15.0...v1.15.1) (2020-11-17)


### Bug Fixes

* editor crash when in code mode ([#370](https://github.com/Automattic/newspack-newsletters/issues/370)) ([143fe4c](https://github.com/Automattic/newspack-newsletters/commit/143fe4cd94ed19384d3bae68aa751c2ed641e45a))

# [1.15.0](https://github.com/Automattic/newspack-newsletters/compare/v1.14.0...v1.15.0) (2020-11-11)


### Features

* campaign monitor implementation ([#364](https://github.com/Automattic/newspack-newsletters/issues/364)) ([bd9c29a](https://github.com/Automattic/newspack-newsletters/commit/bd9c29acaccad8331c21a7957e095a0128e3d695)), closes [#369](https://github.com/Automattic/newspack-newsletters/issues/369)
* enable custom footer ([#369](https://github.com/Automattic/newspack-newsletters/issues/369)) ([8334fa5](https://github.com/Automattic/newspack-newsletters/commit/8334fa55784a5a08143b23b1e88e78d07c033f19))

# [1.14.0](https://github.com/Automattic/newspack-newsletters/compare/v1.13.0...v1.14.0) (2020-10-20)


### Bug Fixes

* handle updated gutenberg colors setting ([d9b3609](https://github.com/Automattic/newspack-newsletters/commit/d9b36091332bbe926233b556e5dd43ccfe77045a)), closes [#358](https://github.com/Automattic/newspack-newsletters/issues/358)
* published public posts fixes ([ddb5d28](https://github.com/Automattic/newspack-newsletters/commit/ddb5d28099538a69bd52a849951c4c9092bc4b05))


### Features

* enable categories and tags for newsletter CPT ([#340](https://github.com/Automattic/newspack-newsletters/issues/340)) ([b5fad81](https://github.com/Automattic/newspack-newsletters/commit/b5fad813e9a3df5b6f77853be27217ac1b289b87))

# [1.13.0](https://github.com/Automattic/newspack-newsletters/compare/v1.12.0...v1.13.0) (2020-09-30)


### Bug Fixes

* prevent draft ads from being inserted into newsletters ([#350](https://github.com/Automattic/newspack-newsletters/issues/350)) ([ddd5cfe](https://github.com/Automattic/newspack-newsletters/commit/ddd5cfe39ab61b261c21bc644aae6b3dbc7ec3b7))


### Features

* posts inserter enhancements ([#348](https://github.com/Automattic/newspack-newsletters/issues/348)) ([004ad27](https://github.com/Automattic/newspack-newsletters/commit/004ad27202b96284722243a9756670ad20982914))

# [1.12.0](https://github.com/Automattic/newspack-newsletters/compare/v1.11.2...v1.12.0) (2020-09-22)


### Features

* add author support for newsletter CPT ([#339](https://github.com/Automattic/newspack-newsletters/issues/339)) ([fb7cb6d](https://github.com/Automattic/newspack-newsletters/commit/fb7cb6d45154633ef0865fb5bb4f4d501428fbfe))

## [1.11.2](https://github.com/Automattic/newspack-newsletters/compare/v1.11.1...v1.11.2) (2020-09-15)


### Bug Fixes

* handle missing provider ([121630c](https://github.com/Automattic/newspack-newsletters/commit/121630c2c94a8438166d43f7875cae8a0f461b3e))
* increase timeout for api tag retrieval ([5b39839](https://github.com/Automattic/newspack-newsletters/commit/5b398393e4b6dc3130654cec56a8c284b752e707))
* take MJML creds into account for settings status ([cfab93a](https://github.com/Automattic/newspack-newsletters/commit/cfab93ac6270f7beb0e59bff04478d4401ce2c9f))

## [1.11.1](https://github.com/Automattic/newspack-newsletters/compare/v1.11.0...v1.11.1) (2020-09-08)


### Bug Fixes

* **posts-inserter:** remove empty category select ([1978bd5](https://github.com/Automattic/newspack-newsletters/commit/1978bd5c011793435f47b686ce73dc4c5fe29ed9))
* harden post type checks ([14a3de2](https://github.com/Automattic/newspack-newsletters/commit/14a3de23187f1eb11008b6e9af8842a9d102934c))

# [1.11.0](https://github.com/Automattic/newspack-newsletters/compare/v1.10.0...v1.11.0) (2020-09-01)


### Bug Fixes

* assorted bugfixes ([#317](https://github.com/Automattic/newspack-newsletters/issues/317)) ([01a72fb](https://github.com/Automattic/newspack-newsletters/commit/01a72fb6f418b7c46205e76536249cece7e57ff0)), closes [#300](https://github.com/Automattic/newspack-newsletters/issues/300) [#305](https://github.com/Automattic/newspack-newsletters/issues/305)
* escaped domain ([#315](https://github.com/Automattic/newspack-newsletters/issues/315)) ([5e83e8b](https://github.com/Automattic/newspack-newsletters/commit/5e83e8b0525633e2a1f83fc7068e45f5a7ab25f3))
* modal margin and header height ([#310](https://github.com/Automattic/newspack-newsletters/issues/310)) ([b87a768](https://github.com/Automattic/newspack-newsletters/commit/b87a7688e1185e4dba137a76d28f5cef2bbc4fee))


### Features

* add branding banner to the CPT wp-admin pages ([#316](https://github.com/Automattic/newspack-newsletters/issues/316)) ([285785c](https://github.com/Automattic/newspack-newsletters/commit/285785c7b008b1343c91d83fb0f3725cd43f3089))
* option to specify public newsletters slug ([77656dd](https://github.com/Automattic/newspack-newsletters/commit/77656dd551ec73c211da2a6f2beb3093b01f9bd6))
* remove Newspack UI from Ads screen ([#314](https://github.com/Automattic/newspack-newsletters/issues/314)) ([d68caa7](https://github.com/Automattic/newspack-newsletters/commit/d68caa7aee414ff68256e90a147349f1f16b4e55))

# [1.10.0](https://github.com/Automattic/newspack-newsletters/compare/v1.9.0...v1.10.0) (2020-08-25)


### Bug Fixes

* reduce padding between elements in newsletter emails ([#308](https://github.com/Automattic/newspack-newsletters/issues/308)) ([4806e76](https://github.com/Automattic/newspack-newsletters/commit/4806e7615c50a22c4af92fbded6f93c7ce0fb6c5))


### Features

* update icon ([#303](https://github.com/Automattic/newspack-newsletters/issues/303)) ([53d91f0](https://github.com/Automattic/newspack-newsletters/commit/53d91f094a9d9a9f47e99b9ca60aa9503cb1f965))

# [1.9.0](https://github.com/Automattic/newspack-newsletters/compare/v1.8.1...v1.9.0) (2020-08-20)


### Bug Fixes

* button custom colors ([#301](https://github.com/Automattic/newspack-newsletters/issues/301)) ([8ebcf5d](https://github.com/Automattic/newspack-newsletters/commit/8ebcf5d1d3f00b327974c78d75172fb10720b2a1))
* first time set up flow ([c16d6bf](https://github.com/Automattic/newspack-newsletters/commit/c16d6bfe09eb8e1586a6a183a1859388e9c9abff))
* pre-send info if no list set ([#297](https://github.com/Automattic/newspack-newsletters/issues/297)) ([f575d94](https://github.com/Automattic/newspack-newsletters/commit/f575d94d6bef04f9828f1870ee4360dc99056bdb))


### Features

* enable public newsletter posts ([#272](https://github.com/Automattic/newspack-newsletters/issues/272)) ([ef90bf9](https://github.com/Automattic/newspack-newsletters/commit/ef90bf96f8e56d06d9fd1cb47c9f136406099aa8))

## [1.8.1](https://github.com/Automattic/newspack-newsletters/compare/v1.8.0...v1.8.1) (2020-08-13)


### Bug Fixes

* group block background color ([56b96ea](https://github.com/Automattic/newspack-newsletters/commit/56b96ead54c08de09f4b1d4dae64c8f7f6e37554))
* use hex value for group background colors in default layouts ([#292](https://github.com/Automattic/newspack-newsletters/issues/292)) ([4cc9b84](https://github.com/Automattic/newspack-newsletters/commit/4cc9b841d23a9388cc1d7413f136dfb67dde0ce2))

# [1.8.0](https://github.com/Automattic/newspack-newsletters/compare/v1.7.0...v1.8.0) (2020-08-11)


### Bug Fixes

* links in heading color inheritance ([6c4e4dd](https://github.com/Automattic/newspack-newsletters/commit/6c4e4dd1880a49a542ab86426f24ea2dbc36aaf1)), closes [#279](https://github.com/Automattic/newspack-newsletters/issues/279)
* **posts-inserter:** handle excerpt ([4b92ab3](https://github.com/Automattic/newspack-newsletters/commit/4b92ab33ff3068196306ca253c726da0c70650a3)), closes [#278](https://github.com/Automattic/newspack-newsletters/issues/278)


### Features

* **posts-inserter:** font size, color options ([70f3a46](https://github.com/Automattic/newspack-newsletters/commit/70f3a462158edf90c750aae5ecfb312e30aa67fc))
* **posts-inserter:** increase posts count cap to 20 ([c622ce4](https://github.com/Automattic/newspack-newsletters/commit/c622ce46c43744e557a6cfa304f2e59ebb0067d9)), closes [#246](https://github.com/Automattic/newspack-newsletters/issues/246)

# [1.7.0](https://github.com/Automattic/newspack-newsletters/compare/v1.6.0...v1.7.0) (2020-08-04)


### Features

* add infrastructure for constant contact support ([f08ee75](https://github.com/Automattic/newspack-newsletters/commit/f08ee751aa4beb549e71b7f8d17d31d5312b2ebf))
* add preview text option ([#276](https://github.com/Automattic/newspack-newsletters/issues/276)) ([3ea6b30](https://github.com/Automattic/newspack-newsletters/commit/3ea6b302711755ac4f7fdc11d15ccaae9ca8a364))
* **ads:** handle setting ad position ([#260](https://github.com/Automattic/newspack-newsletters/issues/260)) ([7ad92dc](https://github.com/Automattic/newspack-newsletters/commit/7ad92dc9e21b705a9eb3e186cab70581c8f7bc5f)), closes [#237](https://github.com/Automattic/newspack-newsletters/issues/237)

# [1.6.0](https://github.com/Automattic/newspack-newsletters/compare/v1.5.0...v1.6.0) (2020-07-22)


### Bug Fixes

* styling of spinner in relation to button ([cd7b029](https://github.com/Automattic/newspack-newsletters/commit/cd7b029768934dc8061d23ca310119b6fbd72ae6))


### Features

* add spinner while sending test email ([05f67cd](https://github.com/Automattic/newspack-newsletters/commit/05f67cdb63eabf5736a57e984b515c17b1a68f66))

# [1.5.0](https://github.com/Automattic/newspack-newsletters/compare/v1.4.0...v1.5.0) (2020-07-14)


### Bug Fixes

* handle deeply nested image blocks ([d846beb](https://github.com/Automattic/newspack-newsletters/commit/d846beb4eb63bfeb1ebecf30e895ca49236a7eb6))
* support adding multiple buttons ([829d534](https://github.com/Automattic/newspack-newsletters/commit/829d53453992a2171117962aa5019b5ae6a08211))
* update state name ready to isReady ([0bbe397](https://github.com/Automattic/newspack-newsletters/commit/0bbe397b9d5ced5953728ab3aae10936dd836cc6))
* weird empty space when no specific posts selected and no spinner ([6fd0fd9](https://github.com/Automattic/newspack-newsletters/commit/6fd0fd9488e428bc1ae634a03ce8761cc410f81c))


### Features

* append utm_medium param to all links in a newsletter ([#265](https://github.com/Automattic/newspack-newsletters/issues/265)) ([e7b1f83](https://github.com/Automattic/newspack-newsletters/commit/e7b1f83fb8d119c0fe4aa5e4e9fd2f7db28716b4))
* handle specific posts and no image conditions ([278d259](https://github.com/Automattic/newspack-newsletters/commit/278d2591c20c23cd89041420390d38f8e4ebe08d))
* show spinner while Post Inserter is loading image blocks ([d0bda46](https://github.com/Automattic/newspack-newsletters/commit/d0bda46875f7d980f6bbf5c72d9112d48ff3ad2f))

# [1.4.0](https://github.com/Automattic/newspack-newsletters/compare/v1.3.0...v1.4.0) (2020-07-07)


### Bug Fixes

* resolve php notices ([#256](https://github.com/Automattic/newspack-newsletters/issues/256)) ([e854521](https://github.com/Automattic/newspack-newsletters/commit/e8545215d1ea94a8c6c05eafc19e81632d8a0a74))


### Features

* **ads:** allow no expiry date on ads ([#252](https://github.com/Automattic/newspack-newsletters/issues/252)) ([eacb5f7](https://github.com/Automattic/newspack-newsletters/commit/eacb5f73d2993c8d6fd8019ab2a9c4fa37f2327a)), closes [#251](https://github.com/Automattic/newspack-newsletters/issues/251)
* **mailchimp:** handle segmentation via tags ([#241](https://github.com/Automattic/newspack-newsletters/issues/241)) ([99f2f1f](https://github.com/Automattic/newspack-newsletters/commit/99f2f1f3d091e590123228142180ead02d5e65e9)), closes [#21](https://github.com/Automattic/newspack-newsletters/issues/21)
* **mjml:** handle social icons block aligment options ([#257](https://github.com/Automattic/newspack-newsletters/issues/257)) ([ce10875](https://github.com/Automattic/newspack-newsletters/commit/ce108750ee41106d2dfc6498300f2c97d2904333))
* support linked images ([#255](https://github.com/Automattic/newspack-newsletters/issues/255)) ([7f7ce8e](https://github.com/Automattic/newspack-newsletters/commit/7f7ce8e0bc3c6067d8fa9d6fd8885c4e6915743e))

# [1.3.0](https://github.com/Automattic/newspack-newsletters/compare/v1.2.0...v1.3.0) (2020-06-30)


### Bug Fixes

* apply custom styles even if styling tab in sidebar is closed ([#240](https://github.com/Automattic/newspack-newsletters/issues/240)) ([20b08bc](https://github.com/Automattic/newspack-newsletters/commit/20b08bca750434f52d0a4415b99ce4142e707eae))
* localize date formats and notice text ([0cb82f1](https://github.com/Automattic/newspack-newsletters/commit/0cb82f1ebad2a23e71e50d0daa9cd9feb30b1732))
* posts-inserter columns warning ([5db6b97](https://github.com/Automattic/newspack-newsletters/commit/5db6b97c30272d357f8bc780ce108814a4745a4c))
* store translated success note as prop ([5638aee](https://github.com/Automattic/newspack-newsletters/commit/5638aee41caaa4a368b3513c4dfbe4fd1fc759f9))


### Features

* add "sent" status to posts list in dashboard ([ed369e4](https://github.com/Automattic/newspack-newsletters/commit/ed369e46f71670ff1685b294af64ea0c46786feb))
* add a "sent" editor notice after a campaign has been sent ([188908e](https://github.com/Automattic/newspack-newsletters/commit/188908ede101860911f19c4f900a140cdcd31868))
* column middle alignment handling ([5f82db4](https://github.com/Automattic/newspack-newsletters/commit/5f82db4f14dd38845882935d7b9c246d83f91b4b)), closes [#231](https://github.com/Automattic/newspack-newsletters/issues/231)
* handle custom styling of layouts ([#238](https://github.com/Automattic/newspack-newsletters/issues/238)) ([2ba844d](https://github.com/Automattic/newspack-newsletters/commit/2ba844dc5f2d9d62c82217b3dd4897a6573ebe8d)), closes [#225](https://github.com/Automattic/newspack-newsletters/issues/225)

# [1.2.0](https://github.com/Automattic/newspack-newsletters/compare/v1.1.0...v1.2.0) (2020-06-18)


### Bug Fixes

* copy ([8fb1840](https://github.com/Automattic/newspack-newsletters/commit/8fb18407ec56c782856ffe83d7d245529aec9e29))
* namespace use in register_rest_route ([ccaebbc](https://github.com/Automattic/newspack-newsletters/commit/ccaebbc300f183685239817c00022e16eedd9635))
* raise list limit to maximum ([#224](https://github.com/Automattic/newspack-newsletters/issues/224)) ([863c8a7](https://github.com/Automattic/newspack-newsletters/commit/863c8a780bad91c479c26d8d4a4bf3c493c72f04))


### Features

* remove white background from layouts' groups ([0fd4468](https://github.com/Automattic/newspack-newsletters/commit/0fd4468931b7c7dfe94db30afa102973e126d2f2))
* support background color setting ([2b98984](https://github.com/Automattic/newspack-newsletters/commit/2b98984f6088943ad5969fbe4b607945f715c5a4)), closes [#219](https://github.com/Automattic/newspack-newsletters/issues/219)
* support background color setting ([#223](https://github.com/Automattic/newspack-newsletters/issues/223)) ([cb142eb](https://github.com/Automattic/newspack-newsletters/commit/cb142ebafb4359bdb470d8c7614a141577bdf391)), closes [#219](https://github.com/Automattic/newspack-newsletters/issues/219)
* **mjml:** reponsive images ([#222](https://github.com/Automattic/newspack-newsletters/issues/222)) ([c1867d9](https://github.com/Automattic/newspack-newsletters/commit/c1867d969b859d0e36fa00b8ba9d6de9d9c39511)), closes [#221](https://github.com/Automattic/newspack-newsletters/issues/221)

# [1.1.0](https://github.com/Automattic/newspack-newsletters/compare/v1.0.1...v1.1.0) (2020-06-09)


### Bug Fixes

* prevent deduplication in layout picker preview ([4a5721e](https://github.com/Automattic/newspack-newsletters/commit/4a5721e2289faa13c4663abb97624e3bd085c098)), closes [#199](https://github.com/Automattic/newspack-newsletters/issues/199)
* **posts-inserter:** prevent empty featured image rendering ([56a97c4](https://github.com/Automattic/newspack-newsletters/commit/56a97c43b51e02c266d579d3a42bd741437e3deb)), closes [#201](https://github.com/Automattic/newspack-newsletters/issues/201)
* resolve excerpt length issue ([d40f98a](https://github.com/Automattic/newspack-newsletters/commit/d40f98aebc10dd17ae3ceba523e6492c766e4d6e))
* situation that overwrote mc campaign id ([8ecf564](https://github.com/Automattic/newspack-newsletters/commit/8ecf56460561786a2a9971cd882149fa02fa905d))
* update editor block paddings ([#195](https://github.com/Automattic/newspack-newsletters/issues/195)) ([eb100b8](https://github.com/Automattic/newspack-newsletters/commit/eb100b86e04d953895047cbe66846c2aa6758ea1))
* when trashing post allow mailchimp api call to fail silently ([7e2d9c4](https://github.com/Automattic/newspack-newsletters/commit/7e2d9c4bd80fd0f136c9cc1b210fc139f3eab483))
* when trashing post allow mailchimp api call to fail silently ([8257451](https://github.com/Automattic/newspack-newsletters/commit/82574512bfc837be25c5572bc5338a46a737602c))


### Features

* add layouts management ([#165](https://github.com/Automattic/newspack-newsletters/issues/165)) ([446674f](https://github.com/Automattic/newspack-newsletters/commit/446674f193407e2dd4ad8f186c65f34ea4fa9b01)), closes [#31](https://github.com/Automattic/newspack-newsletters/issues/31) [#216](https://github.com/Automattic/newspack-newsletters/issues/216)
* font picker ([#210](https://github.com/Automattic/newspack-newsletters/issues/210)) ([0234bf9](https://github.com/Automattic/newspack-newsletters/commit/0234bf9491bd80c775458da92fb90050de9b1a79))

## [1.0.1](https://github.com/Automattic/newspack-newsletters/compare/v1.0.0...v1.0.1) (2020-05-07)


### Bug Fixes

* **settings:** fix settings url in post-activation nag ([863a16b](https://github.com/Automattic/newspack-newsletters/commit/863a16be3473d13875bcc64db11bb86c41628adb))

# 1.0.0 (2020-05-05)


### Bug Fixes

* add alpha to branches ([d3549f5](https://github.com/Automattic/newspack-newsletters/commit/d3549f5ea3984cd9b9344e8917f4e7406f81e859))
* better docblock description ([f0e592b](https://github.com/Automattic/newspack-newsletters/commit/f0e592b84d3189351e952600ac581c3f69a675cf))
* bring back groups ui ([df2da81](https://github.com/Automattic/newspack-newsletters/commit/df2da8186370ade3c849b181c92e0b97d810b6de))
* condition ([4083d80](https://github.com/Automattic/newspack-newsletters/commit/4083d80fa3cb3a1b5d62d7ce4309fee2b78cedd9))
* crash when editing 'from' fields ([934772a](https://github.com/Automattic/newspack-newsletters/commit/934772a7b10fc181402e416507e9390da2eded13))
* custom colors ([125f18c](https://github.com/Automattic/newspack-newsletters/commit/125f18ccf00904d7cf84f5068270a1100201ca73)), closes [#93](https://github.com/Automattic/newspack-newsletters/issues/93)
* default values for keys ([a5164dc](https://github.com/Automattic/newspack-newsletters/commit/a5164dc0c21e9c030fe95b7204347135893831ff)), closes [#97](https://github.com/Automattic/newspack-newsletters/issues/97)
* error on data retrieval if no mailchimp campaign is set ([203d64b](https://github.com/Automattic/newspack-newsletters/commit/203d64bcbbba81020df3c23a513e2bace1f8295b))
* layout preview viewport width ([#84](https://github.com/Automattic/newspack-newsletters/issues/84)) ([779b7cf](https://github.com/Automattic/newspack-newsletters/commit/779b7cf33634e6cb1ebda21f2b837822c31b183a))
* link colour and text-decoration ([#125](https://github.com/Automattic/newspack-newsletters/issues/125)) ([29be13d](https://github.com/Automattic/newspack-newsletters/commit/29be13d081667c16c0e2ae66b1b45513ef045885))
* missing externallink import, lost in rebase ([e289195](https://github.com/Automattic/newspack-newsletters/commit/e289195fde7ed7eba725d289eb2195d12e78ff53))
* missing import; formatting ([1bf80d4](https://github.com/Automattic/newspack-newsletters/commit/1bf80d4c25395198efdab965aaafb68ce50535b7))
* missing notice after campaign has been sent ([42a0889](https://github.com/Automattic/newspack-newsletters/commit/42a08891132fcc0bc820ac043102638487961070))
* newsletter layouts preview thumbnails ([#175](https://github.com/Automattic/newspack-newsletters/issues/175)) ([7280483](https://github.com/Automattic/newspack-newsletters/commit/7280483e792b2ec5502ec8a538a262616b2aa144))
* posts inserter infinite loop ([42508b0](https://github.com/Automattic/newspack-newsletters/commit/42508b06d5f88749e2361342da1bbcdde5c6b57f)), closes [#146](https://github.com/Automattic/newspack-newsletters/issues/146)
* posts inserter no-insertion workflow ([#174](https://github.com/Automattic/newspack-newsletters/issues/174)) ([53d8b7c](https://github.com/Automattic/newspack-newsletters/commit/53d8b7cbc93e6939191fb02d79684471f7323502))
* prevent duplication after insertion in Posts Inserted block ([#191](https://github.com/Automattic/newspack-newsletters/issues/191)) ([d4ac6d8](https://github.com/Automattic/newspack-newsletters/commit/d4ac6d80dfcde7a6568f8773e2c8747fc77751e6)), closes [#185](https://github.com/Automattic/newspack-newsletters/issues/185)
* prevent fetching data if post is not saved yet ([458c8a1](https://github.com/Automattic/newspack-newsletters/commit/458c8a11be477c729ba21507590658c7a411e6d1))
* publish button text to send|sending|sent ([6fcbefe](https://github.com/Automattic/newspack-newsletters/commit/6fcbefe3418eb3aaea6a1b1913042e0d6d0cbab8))
* sticky text controls ([81e85a7](https://github.com/Automattic/newspack-newsletters/commit/81e85a77d3bfaa0dfc7419fbb40ceaca105ce00c))
* **email:** fix custom column width issue ([#168](https://github.com/Automattic/newspack-newsletters/issues/168)) ([2624c48](https://github.com/Automattic/newspack-newsletters/commit/2624c48cc9dc41c38f9e3ed7e9edf4f570980820)), closes [#162](https://github.com/Automattic/newspack-newsletters/issues/162)
* prevent updating content of a sent campaign ([cce029e](https://github.com/Automattic/newspack-newsletters/commit/cce029eb5b64e16faed42fcf5ab297588f57a9cd))
* regression of test email error/success notifications ([55c12fe](https://github.com/Automattic/newspack-newsletters/commit/55c12fe6c52ae003aac37a835de1934b989f203f))
* remove data center prefix from mailchimp url ([c7f741c](https://github.com/Automattic/newspack-newsletters/commit/c7f741c925c6a72376d43db43d296b9e2f412d69))
* remove support for background gradients ([#152](https://github.com/Automattic/newspack-newsletters/issues/152)) ([f4b1120](https://github.com/Automattic/newspack-newsletters/commit/f4b112030cacb1b8666d170432d8adc96c7b6ea8))
* remove unneeded hr and wrap externallink in a paragraph ([fa9fd68](https://github.com/Automattic/newspack-newsletters/commit/fa9fd68604453ff9ab08877ca84d666205c97a2b))
* rename list->audience to reflect mailchimp ([7de6266](https://github.com/Automattic/newspack-newsletters/commit/7de6266883b7b61e17c1b9217e70a6b679f736da))
* **blocks:** remove wide alignment for group block ([216aa9f](https://github.com/Automattic/newspack-newsletters/commit/216aa9f7af886791f8eb83ed5a01c02ad1fee69c)), closes [#153](https://github.com/Automattic/newspack-newsletters/issues/153)
* remove unnecessary check ([ce6d767](https://github.com/Automattic/newspack-newsletters/commit/ce6d7676b4408dce9c376dc21ff61a3da2c5b5d9))
* remove unneeded logic ([57c8ec0](https://github.com/Automattic/newspack-newsletters/commit/57c8ec06d7e3faf8a123160a928eef2adc9554ae))
* **sidebar:** store sender data in post meta ([#110](https://github.com/Automattic/newspack-newsletters/issues/110)) ([9dcb9fa](https://github.com/Automattic/newspack-newsletters/commit/9dcb9fab2d9f03a2049157faca4ef739817471b6))
* simplify title of newsletter settings panel ([#95](https://github.com/Automattic/newspack-newsletters/issues/95)) ([2897b58](https://github.com/Automattic/newspack-newsletters/commit/2897b58baf294b1ba1429241e99f5c556822a7ae))
* support for reusable blocks in newsletters ([fdd72ae](https://github.com/Automattic/newspack-newsletters/commit/fdd72ae60551712cf8d9564bce60da454ae01395))
* **editor:** button block alignment ([0486aef](https://github.com/Automattic/newspack-newsletters/commit/0486aef66dc2ac21329840957f734f2b1adab573)), closes [#55](https://github.com/Automattic/newspack-newsletters/issues/55)
* add useEffect hook dependencies ([aee78b9](https://github.com/Automattic/newspack-newsletters/commit/aee78b94e577472d5293d294dc1349a26f7d387b))
* campaign updating logic; save after template insertion ([7ae882a](https://github.com/Automattic/newspack-newsletters/commit/7ae882a824ceda33a231f29d0c8a48040fa86407))
* change logo size to medium ([#50](https://github.com/Automattic/newspack-newsletters/issues/50)) ([db27e64](https://github.com/Automattic/newspack-newsletters/commit/db27e64064815553a6932898349c63014319ed47))
* condition ([6f44e03](https://github.com/Automattic/newspack-newsletters/commit/6f44e0303f81f69ada2b7ce981672d88c14a31eb))
* corrected license in package and composer files ([1a2fcd3](https://github.com/Automattic/newspack-newsletters/commit/1a2fcd3a2cccbd6bcbbc4f03054ac5da314e753c))
* duplicate plugin registration from merge conflict error ([6726994](https://github.com/Automattic/newspack-newsletters/commit/67269940bbbc86197f31a94f1e161653c6f83a60))
* ensure attribute exists in renderer ([dc6bbc9](https://github.com/Automattic/newspack-newsletters/commit/dc6bbc9def0b29f454ccd3d9a0cab5380f719e6c))
* gpl 2.0 license ([41e7738](https://github.com/Automattic/newspack-newsletters/commit/41e7738a9b4ed4a4f322b5851ad9dc3a93ba92a7))
* grid auto rows ([b9d0841](https://github.com/Automattic/newspack-newsletters/commit/b9d0841e444c47616c4013bdc511668b7980fc32))
* grid on small screens ([a603ebb](https://github.com/Automattic/newspack-newsletters/commit/a603ebbda4c8b7d35d0007155f2d88edafb495ab))
* identical placeholder text for blank template or no template ([7ff325b](https://github.com/Automattic/newspack-newsletters/commit/7ff325bd268c51a00ca75bc514ecc66732a073f2))
* image size from template ([07bc7ed](https://github.com/Automattic/newspack-newsletters/commit/07bc7ed124ee3539dc37d720760b5e86979892e0))
* include logo image ID in class ([1c7fbd3](https://github.com/Automattic/newspack-newsletters/commit/1c7fbd3960c031de7d7057eb7cbce247f8882c00))
* include vendor in release package ([01928b5](https://github.com/Automattic/newspack-newsletters/commit/01928b55353fa7af7a7cd591b29deb8b24bd21c2))
* increase mjml request timeout, handle errors ([aa1f3c4](https://github.com/Automattic/newspack-newsletters/commit/aa1f3c4a5cd3450698806bb22d71194e9de12b62))
* loading text improvement ([59703cf](https://github.com/Automattic/newspack-newsletters/commit/59703cfe513b32d1c40545ad1ee145ba46cab6b2))
* modal content background and preview items since gutenberg 7.9 ([#49](https://github.com/Automattic/newspack-newsletters/issues/49)) ([3eb5f1e](https://github.com/Automattic/newspack-newsletters/commit/3eb5f1e546c21d51324eb5509b366fe5c3a9a712))
* move settings menu item to newspack newsletters menu ([b562a39](https://github.com/Automattic/newspack-newsletters/commit/b562a397f605aef0331087debbd70ed8ed60eeb5))
* overflow scroll to preview ([cd0289f](https://github.com/Automattic/newspack-newsletters/commit/cd0289fcf8c3c13057005a605117f79b8bc69124))
* patterns overflow when screen height is smaller ([e443814](https://github.com/Automattic/newspack-newsletters/commit/e443814aa8fe700915c9687987fc1e02db152267))
* remove group block from template 1 to have focus on sitename ([1cd40ec](https://github.com/Automattic/newspack-newsletters/commit/1cd40ec8643e9fe9f8a9e2960eb993b4335193a4))
* remove pointer-events when layout is selected ([3fc35fb](https://github.com/Automattic/newspack-newsletters/commit/3fc35fb9a8bb50c396693f9c7ea3c1dbe7811b2d))
* remove todo that has been addressed ([fffbcd2](https://github.com/Automattic/newspack-newsletters/commit/fffbcd25eca2b7637f162566eee1725ba90de19a))
* rename interests to groups ([d8f8802](https://github.com/Automattic/newspack-newsletters/commit/d8f8802a144c3a4a91bcb52a1686665ab9963e86))
* rename labels ([0fd0ac2](https://github.com/Automattic/newspack-newsletters/commit/0fd0ac2c866062b2333e715d57430b53bc038eab))
* replace SVGs with PNG in social icons folder ([bf79d6f](https://github.com/Automattic/newspack-newsletters/commit/bf79d6f48fbfffe09aa8f57563880aa125b51d5f)), closes [#38](https://github.com/Automattic/newspack-newsletters/issues/38)
* resolve error occurring before list selection ([3aae4d4](https://github.com/Automattic/newspack-newsletters/commit/3aae4d4d3eaad66277c678bf9431bd5d414bba3c))
* **template-modal:** prevent modal closing after autosave ([12965c4](https://github.com/Automattic/newspack-newsletters/commit/12965c4c2aea11c7cd18b95836d76676b6057c56)), closes [#45](https://github.com/Automattic/newspack-newsletters/issues/45)
* send content to mailchimp immediately before sending campaign ([0d0bb5a](https://github.com/Automattic/newspack-newsletters/commit/0d0bb5a656688ad431cfb4e2c7364371ac41f2eb))
* template image ([e77d1b0](https://github.com/Automattic/newspack-newsletters/commit/e77d1b02e1b0d88d863dc604cad83ae49aa1711f))
* templates ([a1cb385](https://github.com/Automattic/newspack-newsletters/commit/a1cb38500709346c7942a6b0e207c1374510a9d9))
* **validation:** invalid by default ([89ce8a9](https://github.com/Automattic/newspack-newsletters/commit/89ce8a93470707170b02687e9d0ed7e0def95de4))
* settings update api supports individual fields ([cd7b18f](https://github.com/Automattic/newspack-newsletters/commit/cd7b18fa1483fd8adec65a6277d396ff5147b657))


### Features

*  remove any other editor modifications ([#8](https://github.com/Automattic/newspack-newsletters/issues/8)) ([2cb3f4c](https://github.com/Automattic/newspack-newsletters/commit/2cb3f4c72c59abbfb782dd97e37a074e7473b2a5))
* activation nag ([1cc6f04](https://github.com/Automattic/newspack-newsletters/commit/1cc6f040d94f33b605a0d830de5e14e07cf4902f))
* add back a single spinner during XHR requests ([3c0d097](https://github.com/Automattic/newspack-newsletters/commit/3c0d097a77507dbaffe4d6fad6a3454d405beb48))
* add class to wrapper when XHR request is in flight ([feb9db6](https://github.com/Automattic/newspack-newsletters/commit/feb9db64607d7137cc28a1753893fbfac4aa0da9))
* add email validation ([#79](https://github.com/Automattic/newspack-newsletters/issues/79)) ([9b1dbb1](https://github.com/Automattic/newspack-newsletters/commit/9b1dbb1681d8ebca53dadabcf6ff9a9ac1b23690))
* add Posts Inserter block ([#47](https://github.com/Automattic/newspack-newsletters/issues/47)) ([f36d8f3](https://github.com/Automattic/newspack-newsletters/commit/f36d8f35a1d6eb73364348b1efc115bddb42a666))
* add posts inserter to modal and strip placeholder style ([#98](https://github.com/Automattic/newspack-newsletters/issues/98)) ([9fc9f46](https://github.com/Automattic/newspack-newsletters/commit/9fc9f466827c9ebe81c9a2bed4192714b2405f00))
* add pre-send confirmation ([#181](https://github.com/Automattic/newspack-newsletters/issues/181)) ([3579363](https://github.com/Automattic/newspack-newsletters/commit/35793632d56c36096f9b422aa971bde4eda4eb03)), closes [#139](https://github.com/Automattic/newspack-newsletters/issues/139)
* add preview modal ([#59](https://github.com/Automattic/newspack-newsletters/issues/59)) ([515f281](https://github.com/Automattic/newspack-newsletters/commit/515f2812516b338d98b6a0d3f623b7ad2b38a584))
* add spacer block support ([996cb69](https://github.com/Automattic/newspack-newsletters/commit/996cb69b433f64a17d2b5abc31303f5dfd52be00)), closes [#100](https://github.com/Automattic/newspack-newsletters/issues/100)
* adjust style of the settings ([3b94145](https://github.com/Automattic/newspack-newsletters/commit/3b941450ff70c27c220fa834932bde5696974f97))
* api key ui in modal ([e6f9a6a](https://github.com/Automattic/newspack-newsletters/commit/e6f9a6a57df55b0ba1badb1c79ac19343a242fef))
* change order of sidebar panels ([405773d](https://github.com/Automattic/newspack-newsletters/commit/405773d346dcf4e1135422faa85d22e54769f934))
* clear notifications before showing success or error ([ab45d60](https://github.com/Automattic/newspack-newsletters/commit/ab45d604fefe1360d0646a3fbd57e0f394cd7aae))
* clearer error messages ([b165075](https://github.com/Automattic/newspack-newsletters/commit/b165075401ad82f317dd2d102dde4c547b0811ef))
* disabled options and empty subscribers info in select ([44cc68f](https://github.com/Automattic/newspack-newsletters/commit/44cc68f8bb7da02e266515462bbd27ff3d6642a4))
* enable selecting specific post ids ([#170](https://github.com/Automattic/newspack-newsletters/issues/170)) ([1781d60](https://github.com/Automattic/newspack-newsletters/commit/1781d60e0415e0a449032c6656a4e098bd705d0e))
* error catching and notification for lists and groups ([8582c04](https://github.com/Automattic/newspack-newsletters/commit/8582c04ebdd8c4c9ac50750e28d2d21dfc3135a5))
* error handling in save and publish operations ([eac62c2](https://github.com/Automattic/newspack-newsletters/commit/eac62c2d9ff4b89d82bafd8ebfb87acaa9d85bc5))
* error notifications for from name/email changes ([887afee](https://github.com/Automattic/newspack-newsletters/commit/887afeeef013d62c44dc0a3e68a8e3b3baa158e8))
* error notifications when retrieving mailchimp data ([ce4fb32](https://github.com/Automattic/newspack-newsletters/commit/ce4fb32951b02cb93cb9dc0be31be6e5811507d1))
* field-level error handling for sender email ([d1bcfaf](https://github.com/Automattic/newspack-newsletters/commit/d1bcfaf063b1bf8b92c0501b291f6ceb13069ce8))
* fullscreen modal show the edit-post-fullscreen-mode-close button ([0cc6d5d](https://github.com/Automattic/newspack-newsletters/commit/0cc6d5db4f111ee2f64443bc07eb6d9711fb42c8))
* handle campaign fetching in editor plugin ([a7a86e2](https://github.com/Automattic/newspack-newsletters/commit/a7a86e25b96e471683516b356c5b371accbf32f1))
* handle color palette as it appears in the editor ([#176](https://github.com/Automattic/newspack-newsletters/issues/176)) ([862d3cf](https://github.com/Automattic/newspack-newsletters/commit/862d3cf712ffa7bc7ce72f0649d343f32f877d4b)), closes [#173](https://github.com/Automattic/newspack-newsletters/issues/173)
* handle uninserted Posts Inserter block state ([#127](https://github.com/Automattic/newspack-newsletters/issues/127)) ([15b47b2](https://github.com/Automattic/newspack-newsletters/commit/15b47b2f8e80b5b5f9bfe7b45aa0906902c706d6)), closes [#114](https://github.com/Automattic/newspack-newsletters/issues/114)
* hoc to handle api requests, notifications ([1be5ada](https://github.com/Automattic/newspack-newsletters/commit/1be5adad8e5a8fcaee20143e3cf8407ddbd0e7cd))
* improved permissions ([a564813](https://github.com/Automattic/newspack-newsletters/commit/a564813485aee9eb5272210134b8f8513aef16b5))
* list management link ([aa90782](https://github.com/Automattic/newspack-newsletters/commit/aa90782582bc922f98621ac66416d68ff173e1ab))
* move preview button to post status slot ([1f450e2](https://github.com/Automattic/newspack-newsletters/commit/1f450e2c645aa56ca08049f3e7258ad4c63a2479))
* move testing panel above layout ([d35b2ad](https://github.com/Automattic/newspack-newsletters/commit/d35b2ad41320d0ce086dd2822fc4d4948a07b11b))
* override publish button ([2786afb](https://github.com/Automattic/newspack-newsletters/commit/2786afbc495506a60dc5f0701d9587dad78ffb16))
* pass color settings from group and column blocks ([9f8a73e](https://github.com/Automattic/newspack-newsletters/commit/9f8a73e2204d67eee716ef2f4c7236bc589086cb))
* prevent duplicate posts when multiple Posts Inserter instances ([#167](https://github.com/Automattic/newspack-newsletters/issues/167)) ([bbdbc3e](https://github.com/Automattic/newspack-newsletters/commit/bbdbc3e9820a65121711041dd9913ff16e0142a0)), closes [#147](https://github.com/Automattic/newspack-newsletters/issues/147)
* remove custom preview button ([#136](https://github.com/Automattic/newspack-newsletters/issues/136)) ([d0e3eff](https://github.com/Automattic/newspack-newsletters/commit/d0e3eff5e874cbc557dff33e5a17907a19f322da))
* remove post content before changing layout ([f253342](https://github.com/Automattic/newspack-newsletters/commit/f253342deae01247ddeb27713fecf060fbcf3bf4))
* remove spinners, disable submission while fields are empty ([5607807](https://github.com/Automattic/newspack-newsletters/commit/5607807fb62cb91a9fdd38a0676b2cab45e08ef8))
* reorder the sidebar settings ([#56](https://github.com/Automattic/newspack-newsletters/issues/56)) ([e9c4630](https://github.com/Automattic/newspack-newsletters/commit/e9c4630387910460bd6bff111f63f1a7d844d758))
* save post before previewing or sending test email ([#108](https://github.com/Automattic/newspack-newsletters/issues/108)) ([cc05ed9](https://github.com/Automattic/newspack-newsletters/commit/cc05ed9ab162d657edfd2ef2b0d2fe45abb37b1b)), closes [#25](https://github.com/Automattic/newspack-newsletters/issues/25)
* send button support for pending state ([db15bc6](https://github.com/Automattic/newspack-newsletters/commit/db15bc67fcc001d6dd3034e968122b2d82bb0df7))
* separate panel for test email ui ([28a3be5](https://github.com/Automattic/newspack-newsletters/commit/28a3be5abf4db35180f5f4a1d38da07cff35246b))
* sidebar ui to change template ([4b6da11](https://github.com/Automattic/newspack-newsletters/commit/4b6da11973355be91587bc8659cfb42dc2a8852d))
* success/error messages when sending test emails ([1afb952](https://github.com/Automattic/newspack-newsletters/commit/1afb95213196488b7feca41375754ce04716cf04))
* support image alignment in Posts Inserter block ([#137](https://github.com/Automattic/newspack-newsletters/issues/137)) ([0b1b446](https://github.com/Automattic/newspack-newsletters/commit/0b1b446661026a2f8649e5bc95d9b8250ea0a231)), closes [#128](https://github.com/Automattic/newspack-newsletters/issues/128)
* support post scheduling ([db5c5be](https://github.com/Automattic/newspack-newsletters/commit/db5c5be4ff86045c83be64b4d829b311a02cba29))
* template picker initial selection improvements ([af1bd2e](https://github.com/Automattic/newspack-newsletters/commit/af1bd2e208d0f75bc39a5d4286f45643919ef201))
* throw error for unverified reply-to email ([8ba7906](https://github.com/Automattic/newspack-newsletters/commit/8ba7906a471b58d819165316187bdcbf4832bf8d))
* update default layouts ([#192](https://github.com/Automattic/newspack-newsletters/issues/192)) ([5415704](https://github.com/Automattic/newspack-newsletters/commit/541570465307caf83bb62586ec8f3b35bfa62e89))
* update general padding of newsletter to increase white space ([#178](https://github.com/Automattic/newspack-newsletters/issues/178)) ([e18064f](https://github.com/Automattic/newspack-newsletters/commit/e18064f48d1d40bb64147bfe31a41b4536deb1ad))
* update post inserter placeholder style ([#83](https://github.com/Automattic/newspack-newsletters/issues/83)) ([916afa8](https://github.com/Automattic/newspack-newsletters/commit/916afa8d2c8a3fcb0306281472a999604559a277))
* update posts inserter defaults ([#99](https://github.com/Automattic/newspack-newsletters/issues/99)) ([05d761f](https://github.com/Automattic/newspack-newsletters/commit/05d761f07863a499880a7c70adeca827d16cfb7f))
* update posts inserter preview margins ([#182](https://github.com/Automattic/newspack-newsletters/issues/182)) ([7df27cc](https://github.com/Automattic/newspack-newsletters/commit/7df27ccd23cb30450a4e1a164b6c85a036f3650d))
* update style of the layout preview and modal ([05c592f](https://github.com/Automattic/newspack-newsletters/commit/05c592f72728cdfbccd8efa29c08520b53699622))
* update style when settings are in-flight ([fc46333](https://github.com/Automattic/newspack-newsletters/commit/fc463334d1fcf530686bf36a4d4d3ebac2db6e4a))
* update templates ([be014b4](https://github.com/Automattic/newspack-newsletters/commit/be014b44212ce6d420fcf25eee182257ff8260ec))
* wrap action buttons around a div for better positioning ([0b28524](https://github.com/Automattic/newspack-newsletters/commit/0b28524efd14655abf7ef2d0c2fdc84753a140da))
* **sidebar:** default values ([d9e0bef](https://github.com/Automattic/newspack-newsletters/commit/d9e0bef35eef3236a5124df97f8e7d831f4405f3))
* add blank layout ([70d9058](https://github.com/Automattic/newspack-newsletters/commit/70d9058871173d44571d8eb67f8d4cee1e181212))
* add new layouts: daily/weekly (no image), breaking news, breaking news (no image), and support ([#48](https://github.com/Automattic/newspack-newsletters/issues/48)) ([424b550](https://github.com/Automattic/newspack-newsletters/commit/424b55095979a8fe398d60e779bb8d15a63e4b06))
* add new template ([aa0336f](https://github.com/Automattic/newspack-newsletters/commit/aa0336ffceca95f5bb7e2254e8a0107840ddfea7))
* add preview modal ([41c7348](https://github.com/Automattic/newspack-newsletters/commit/41c73481bdde02db917ae11e800f94843fc95338))
* add template selection modal ([a765ddc](https://github.com/Automattic/newspack-newsletters/commit/a765ddc78eed8b419217a1db647fcd75aa3abba0))
* adjust layout modal style to be fullscreen ([2378fd0](https://github.com/Automattic/newspack-newsletters/commit/2378fd0cb7f459499422c612361cdd72a82a4af0))
* adjust ui if campaign is sent or sending ([69e60d0](https://github.com/Automattic/newspack-newsletters/commit/69e60d009c83187b67dd4f585cfa9dfe2644245f))
* allow unsetting interest groups ([5498f66](https://github.com/Automattic/newspack-newsletters/commit/5498f666771bc8f51a9b06eb89599838b0d93063))
* author newsletters in wordpress ([311de03](https://github.com/Automattic/newspack-newsletters/commit/311de0393b238f019bebe0ffed37fe1aa6f29bd9))
* block publish and notify for missing sender fields ([a3269a2](https://github.com/Automattic/newspack-newsletters/commit/a3269a22bc909756cd6898443a7e38b1b6793074))
* blocks to MJML conversion ([#5](https://github.com/Automattic/newspack-newsletters/issues/5)) ([5a30354](https://github.com/Automattic/newspack-newsletters/commit/5a30354418ca690ab97cd519e586ce0b3eb2c495))
* clear mailchimp campaign id when post is trashed ([47810eb](https://github.com/Automattic/newspack-newsletters/commit/47810eb8c6c7f00c95071b3c4ce2c733791970e6))
* custom field to assess template readiness ([5d8b6a3](https://github.com/Automattic/newspack-newsletters/commit/5d8b6a3642abb5495529f112322e61a9efe2c37c))
* delete unsent mailchimp campaigns when cpt trashed ([164009f](https://github.com/Automattic/newspack-newsletters/commit/164009f27e099027084700428053e70bada08479))
* disable column alignment ([a16876e](https://github.com/Automattic/newspack-newsletters/commit/a16876eb098e2b47fe614504608da386335ffcbb))
* disable editor styles ([7f592df](https://github.com/Automattic/newspack-newsletters/commit/7f592df4018a82d1ccfeb465db9bfce539f8a529))
* disable gradients for the Newsletter custom post type ([742e141](https://github.com/Automattic/newspack-newsletters/commit/742e1412b82a2603481590c074fa107566efa7fd))
* display modal on new newsletter posts ([1247c59](https://github.com/Automattic/newspack-newsletters/commit/1247c5980886ea609ac4313d6b18d206f1680d42))
* display validation errors in pre-publish slot ([92dd7d1](https://github.com/Automattic/newspack-newsletters/commit/92dd7d1cb82c4a7af5574b0e22dc7c5d7430f8f8))
* display warnings in editor for unsupported features ([#9](https://github.com/Automattic/newspack-newsletters/issues/9)) ([1edd153](https://github.com/Automattic/newspack-newsletters/commit/1edd153fcfca0d2eec2f55accfe087f981e7793f))
* handle campaign validation via post meta field ([58d090d](https://github.com/Automattic/newspack-newsletters/commit/58d090d6a54069ad7743b42f4876eccd34ee6e65))
* handle group block ([#10](https://github.com/Automattic/newspack-newsletters/issues/10)) ([eda73d2](https://github.com/Automattic/newspack-newsletters/commit/eda73d2d03027ade775c5c698a4964656055a39d))
* hide editor preview ui ([37315d0](https://github.com/Automattic/newspack-newsletters/commit/37315d00f4246d1b58039366a6a8d118e4116cd7))
* hide title, add subject field in sidebar ([33fc00a](https://github.com/Automattic/newspack-newsletters/commit/33fc00a389ea585f2107443efb0cc1e2755c0553))
* inputs for test email and sender ([09fa566](https://github.com/Automattic/newspack-newsletters/commit/09fa56610c696eaf7c2ae463cb49e503847c5906))
* live block preview ([7247383](https://github.com/Automattic/newspack-newsletters/commit/72473836c2a20bcf9dedf8bad874af1786972037))
* loading ui in pre publish checklist ([193edcb](https://github.com/Automattic/newspack-newsletters/commit/193edcbc8b8e41c8073abdce37c3114fe5ad4c3e))
* move MJML creds to plugin settings ([06840ad](https://github.com/Automattic/newspack-newsletters/commit/06840ad514f1fa9702a9db53f6035868f9f22ec5)), closes [#16](https://github.com/Automattic/newspack-newsletters/issues/16)
* multiple test emails ([8e0b444](https://github.com/Automattic/newspack-newsletters/commit/8e0b4443a2e72daa1c9ab91c58a7a4251c7ccc46))
* placeholder text for no template or blank template ([6bbfcfe](https://github.com/Automattic/newspack-newsletters/commit/6bbfcfe94d5ad87cb9a41540b33dce4743afe7a6))
* placeholder text for no template or blank template ([a378b54](https://github.com/Automattic/newspack-newsletters/commit/a378b54e6ba5d0c6b676d9c7020b0a32a8fbd352))
* publish button state, pre-publish message ([eb6ed6f](https://github.com/Automattic/newspack-newsletters/commit/eb6ed6f2e2c380c17d4263e92b2ab057e4538476))
* remove any other editor modifications ([946bc04](https://github.com/Automattic/newspack-newsletters/commit/946bc046b59e42e9be5b23ce4506a3988a0d5603))
* remove image and use blockpreview instead ([dd0e489](https://github.com/Automattic/newspack-newsletters/commit/dd0e4897e9cf8452fcbf8c214e92458def04eb19))
* remove is_ready meta ([1866393](https://github.com/Automattic/newspack-newsletters/commit/18663938dab2a3e93d16959978ffb9bec1e6730f))
* remove send btn, sender name/email from campaign ([397f41f](https://github.com/Automattic/newspack-newsletters/commit/397f41fd5074dbe5f660b2217bf3181aa5ef3621))
* Remove Unsupported Features From Core Blocks ([2184d1f](https://github.com/Automattic/newspack-newsletters/commit/2184d1f84cdc9d6cfd37f16c59fa7e52123ac5f2))
* remove unsupported features of core blocks ([69a0f10](https://github.com/Automattic/newspack-newsletters/commit/69a0f10c34845f8aa6fac0ee2823d96c68b27bf6))
* reorganize folders and remove images ([c2e643c](https://github.com/Automattic/newspack-newsletters/commit/c2e643cb8efcfba90431dac05976565fa47ae024))
* save/publish flow ([757162c](https://github.com/Automattic/newspack-newsletters/commit/757162cfbfe301d2fa06979c8d82f1b554b8075f))
* select mailchimp interests ([59f0643](https://github.com/Automattic/newspack-newsletters/commit/59f064315b7f1833212be6f3978461a9107cfedc))
* select to display mailchimp interests (groups) ([48d1366](https://github.com/Automattic/newspack-newsletters/commit/48d136601af73b154e05167306853381cef512b1))
* sender name/email updating ([85ac847](https://github.com/Automattic/newspack-newsletters/commit/85ac847d196ef3822d89164871b6d8438cb33803))
* sidebar subject input ([321f047](https://github.com/Automattic/newspack-newsletters/commit/321f047dc892f9c14cca4c697e414509fbf0a080))
* template insertion ([452c48b](https://github.com/Automattic/newspack-newsletters/commit/452c48b81fac539abde77598252558171f2e6ea4))
* template selection modal - display regardless of side panel status ([9656712](https://github.com/Automattic/newspack-newsletters/commit/9656712c31061d005d271732fa6640ec2feb7318))
* token replace function, logo or sitename token ([0bc8875](https://github.com/Automattic/newspack-newsletters/commit/0bc88750d8cf2c7167ddb745cbc4c8579df54393))
* update campagin on save, send campaign on publish ([c316923](https://github.com/Automattic/newspack-newsletters/commit/c3169239c668465130915d1af81ed033cb64fcae))
* update display of patterns using grid ([6e04641](https://github.com/Automattic/newspack-newsletters/commit/6e046419f8e2781405fbe26d7a3c80af11d8f62d))
* update menu icon to material's alternate_email ([#6](https://github.com/Automattic/newspack-newsletters/issues/6)) ([f8ecaa4](https://github.com/Automattic/newspack-newsletters/commit/f8ecaa4914dc1c9a0dc2ab0ba90913ca1851d9c4))
* update pre-check with notice and reorganize sidebar ([#42](https://github.com/Automattic/newspack-newsletters/issues/42)) ([ad9b6c1](https://github.com/Automattic/newspack-newsletters/commit/ad9b6c1fdf1461713d294a6a908915abbed21356))
* update settings description to match MJML api email ([#46](https://github.com/Automattic/newspack-newsletters/issues/46)) ([df826c7](https://github.com/Automattic/newspack-newsletters/commit/df826c74401e46d308a15d4b0901912a4c11ec2c))
* use current date as default title ([32ce655](https://github.com/Automattic/newspack-newsletters/commit/32ce65530965e303958ed8b0c5066e8091bc6541))
* warn about nested groups ([43fb115](https://github.com/Automattic/newspack-newsletters/commit/43fb115d3c7bd6cdf930a0843a18f6d7dd5ad690))
* **template:** thumbnail logo size ([65eba93](https://github.com/Automattic/newspack-newsletters/commit/65eba93bae2f35e1f1c686cad57115e0658217a2))
* wrap modal content around its own div ([6cf6c3b](https://github.com/Automattic/newspack-newsletters/commit/6cf6c3b11159977eb9aaadd0d0b9c637a7a00d3e))
