## [4.26.2](https://github.com/Automattic/newspack-blocks/compare/v4.26.1...v4.26.2) (2026-05-04)


### Bug Fixes

* **modal-checkout:** retrieve order from query vars in thankyou template ([#2324](https://github.com/Automattic/newspack-blocks/issues/2324)) ([c483abd](https://github.com/Automattic/newspack-blocks/commit/c483abdc471e844e75fd0732b8cc878b52e58100))

## [4.26.1](https://github.com/Automattic/newspack-blocks/compare/v4.26.0...v4.26.1) (2026-04-13)


### Bug Fixes

* **modal-checkout:** register NYP AJAX handler for logged-out users ([#2323](https://github.com/Automattic/newspack-blocks/issues/2323)) ([291223e](https://github.com/Automattic/newspack-blocks/commit/291223e5f52bf3af096a07466199d625bdd68e50))
* reinstate WP 7.0 enqueue_block_assets workaround ([#2319](https://github.com/Automattic/newspack-blocks/issues/2319)) ([4cff5a6](https://github.com/Automattic/newspack-blocks/commit/4cff5a625e5244e2c5b4f78e32328af956446af5))
* track seen blocks to prevent infinite recursion in cache checks ([#2327](https://github.com/Automattic/newspack-blocks/issues/2327)) ([9116fc0](https://github.com/Automattic/newspack-blocks/commit/9116fc0e1a9e6b4cf75cd30f3d6286f63a3d744c))

# [4.26.0](https://github.com/Automattic/newspack-blocks/compare/v4.25.4...v4.26.0) (2026-03-30)


### Bug Fixes

* **author-profile:** correct avatar-right mobile stacking order ([#2312](https://github.com/Automattic/newspack-blocks/issues/2312)) ([7bd89c2](https://github.com/Automattic/newspack-blocks/commit/7bd89c2d481b18f78c3b9f95f9445e7a3be724b1))
* **block-theme:** modal checkout for block theme ([#2311](https://github.com/Automattic/newspack-blocks/issues/2311)) ([eefa9ab](https://github.com/Automattic/newspack-blocks/commit/eefa9ab8f5c31ed020f7228857c8f47ace4740dd))


### Features

* add dedupe option to Carousel block ([#2309](https://github.com/Automattic/newspack-blocks/issues/2309)) ([18f625e](https://github.com/Automattic/newspack-blocks/commit/18f625e16dbfa0ff61e46f2fa3b2bb8effe62f44))
* **author-profile:** add block variations for layout selection ([#2302](https://github.com/Automattic/newspack-blocks/issues/2302)) ([1ba67ba](https://github.com/Automattic/newspack-blocks/commit/1ba67ba0bdfd8339c8209257e45c089edc48f3ae))

## [4.25.4](https://github.com/Automattic/newspack-blocks/compare/v4.25.3...v4.25.4) (2026-03-26)


### Bug Fixes

* **modal-checkout:** prevent empty error notices and fix padding ([#2322](https://github.com/Automattic/newspack-blocks/issues/2322)) ([0c9307d](https://github.com/Automattic/newspack-blocks/commit/0c9307db777f0e3343e0c1e2129f8ecd887c26ab))

## [4.25.3](https://github.com/Automattic/newspack-blocks/compare/v4.25.2...v4.25.3) (2026-03-23)


### Bug Fixes

* update payment gateway check to make more specific ([#2321](https://github.com/Automattic/newspack-blocks/issues/2321)) ([205ae60](https://github.com/Automattic/newspack-blocks/commit/205ae602a1cec474bddc287143fe548fa9035070))

## [4.25.2](https://github.com/Automattic/newspack-blocks/compare/v4.25.1...v4.25.2) (2026-03-17)


### Bug Fixes

* restore editing tools to Carousel block ([ee1354a](https://github.com/Automattic/newspack-blocks/commit/ee1354ae5f97475dc2afebfea05f6019599959dd))

## [4.25.1](https://github.com/Automattic/newspack-blocks/compare/v4.25.0...v4.25.1) (2026-03-17)


### Bug Fixes

* reinstate enqueue block editor assets for the blocks ([#2314](https://github.com/Automattic/newspack-blocks/issues/2314)) ([3695d09](https://github.com/Automattic/newspack-blocks/commit/3695d09465fe07d626da39b4d0a1eb454d21722b))

# [4.25.0](https://github.com/Automattic/newspack-blocks/compare/v4.24.1...v4.25.0) (2026-03-16)


### Bug Fixes

* iframe editor compatibility ([#2289](https://github.com/Automattic/newspack-blocks/issues/2289)) ([cdc0858](https://github.com/Automattic/newspack-blocks/commit/cdc085887f01c28ae1684dde07097951a21ae52e))
* remove styles config ([#2301](https://github.com/Automattic/newspack-blocks/issues/2301)) ([60eae46](https://github.com/Automattic/newspack-blocks/commit/60eae467b685cacd3f0d36ce62f93aaa934968ec))
* tweak the payment gateway to include enabled gateways not in get_available_payment_gateways() ([#2306](https://github.com/Automattic/newspack-blocks/issues/2306)) ([f82fe2d](https://github.com/Automattic/newspack-blocks/commit/f82fe2dfe1e20b59efb3a4016afcf9c7d860dc95))


### Features

* **author-profile:** add block theme compatibility ([#2286](https://github.com/Automattic/newspack-blocks/issues/2286)) ([d10fe2f](https://github.com/Automattic/newspack-blocks/commit/d10fe2fd525616ad77003afec9db0c901554d349))
* **author-profile:** allow spacer and separator blocks ([#2300](https://github.com/Automattic/newspack-blocks/issues/2300)) ([0b77d71](https://github.com/Automattic/newspack-blocks/commit/0b77d71292a8e0a2fe56981a39135d68177d828d))

## [4.24.1](https://github.com/Automattic/newspack-blocks/compare/v4.24.0...v4.24.1) (2026-03-02)


### Bug Fixes

* **modal-checkout:** reduce gap between text and chevron ([#2291](https://github.com/Automattic/newspack-blocks/issues/2291)) ([645b92e](https://github.com/Automattic/newspack-blocks/commit/645b92e5529492ad1f0eff65b294308296492bfd))
* **modal-checkout:** show transaction details for subscription renewals ([#2295](https://github.com/Automattic/newspack-blocks/issues/2295)) ([41268cb](https://github.com/Automattic/newspack-blocks/commit/41268cbd55dc55cbccd466cd78c38d6db871dd66))

# [4.24.0](https://github.com/Automattic/newspack-blocks/compare/v4.23.0...v4.24.0) (2026-02-02)


### Bug Fixes

* force alpha release ([7e6ace4](https://github.com/Automattic/newspack-blocks/commit/7e6ace4c8ae429c93556da173ef30a4c2f3cbd64))


### Features

* add prompt_title to the modal checkout data events ([#2271](https://github.com/Automattic/newspack-blocks/issues/2271)) ([238c7e9](https://github.com/Automattic/newspack-blocks/commit/238c7e931e7168ab3d0755ad73702746418d706a))

# [4.23.0](https://github.com/Automattic/newspack-blocks/compare/v4.22.0...v4.23.0) (2026-01-19)


### Features

* trigger release ([9124c04](https://github.com/Automattic/newspack-blocks/commit/9124c047c944f4bea016e5eae53060f86ff62a4a))

# [4.22.0](https://github.com/Automattic/newspack-blocks/compare/v4.21.0...v4.22.0) (2026-01-19)


### Features

* update release process ([aa1048c](https://github.com/Automattic/newspack-blocks/commit/aa1048c971048363932db8e4bb7c31e69d523651))

# [4.21.0](https://github.com/Automattic/newspack-blocks/compare/v4.20.1...v4.21.0) (2026-01-19)


### Bug Fixes

* add Link to express payment array ([#2266](https://github.com/Automattic/newspack-blocks/issues/2266)) ([fdd6fd5](https://github.com/Automattic/newspack-blocks/commit/fdd6fd540538062be4161cd4968be7a73ffccd23))
* add space to donate block label in editor ([#2270](https://github.com/Automattic/newspack-blocks/issues/2270)) ([d18cf42](https://github.com/Automattic/newspack-blocks/commit/d18cf4202a2ca484ac86a6e294da0654b5bd2e48))


### Features

* improve express checkout handling by surfacing errors ([#2273](https://github.com/Automattic/newspack-blocks/issues/2273)) ([1d98b9b](https://github.com/Automattic/newspack-blocks/commit/1d98b9b54dc5d200689674be2f185289ad93067a))

## [4.20.1](https://github.com/Automattic/newspack-blocks/compare/v4.20.0...v4.20.1) (2026-01-07)


### Bug Fixes

* increase log level required to log caching status ([3f6be67](https://github.com/Automattic/newspack-blocks/commit/3f6be670dfcbcdec3d9450c222cebe48448f5bb1))

# [4.20.0](https://github.com/Automattic/newspack-blocks/compare/v4.19.0...v4.20.0) (2026-01-05)


### Bug Fixes

* avoid redeclaration of global function ([#2264](https://github.com/Automattic/newspack-blocks/issues/2264)) ([3af1ea7](https://github.com/Automattic/newspack-blocks/commit/3af1ea720849058e4fea15aa4adb498c8aa564a0))


### Features

* supress carousel block from feeds ([#2263](https://github.com/Automattic/newspack-blocks/issues/2263)) ([d0c6ad1](https://github.com/Automattic/newspack-blocks/commit/d0c6ad1cacc0c6aebfaec584de598dcc9e91294d))

# [4.19.0](https://github.com/Automattic/newspack-blocks/compare/v4.18.0...v4.19.0) (2025-12-08)


### Bug Fixes

* add a max-width to the Content Carousel block ([#2253](https://github.com/Automattic/newspack-blocks/issues/2253)) ([b791a3a](https://github.com/Automattic/newspack-blocks/commit/b791a3a23f5050638760f083911403f5dfeab13d))
* handle checkout form inline errors and expired sessions ([#2255](https://github.com/Automattic/newspack-blocks/issues/2255)) ([9beb64e](https://github.com/Automattic/newspack-blocks/commit/9beb64e8be0296e9c039fb5e19b6c30628af90ff))
* linting issues ([#2254](https://github.com/Automattic/newspack-blocks/issues/2254)) ([776514b](https://github.com/Automattic/newspack-blocks/commit/776514bef7618d8043ef9f358b8383ef9555c9cc))
* **modal-checkout:** require change payment class ([#2257](https://github.com/Automattic/newspack-blocks/issues/2257)) ([4d606ef](https://github.com/Automattic/newspack-blocks/commit/4d606ef536ac91dc05433314b67ea055b52491a6))


### Features

* **donate-block:** default frequency for tiered layout ([#2248](https://github.com/Automattic/newspack-blocks/issues/2248)) ([df0e2f9](https://github.com/Automattic/newspack-blocks/commit/df0e2f9c9305ca1318b913ca9b19511ae572f429))

# [4.18.0](https://github.com/Automattic/newspack-blocks/compare/v4.17.6...v4.18.0) (2025-11-24)


### Bug Fixes

* linting issues ([#2254](https://github.com/Automattic/newspack-blocks/issues/2254)) ([ae580a3](https://github.com/Automattic/newspack-blocks/commit/ae580a3a5eb864c20e99c0fc692d19c2c3fec55f))


### Features

* **content-loop:** setting labels more generic ([#2246](https://github.com/Automattic/newspack-blocks/issues/2246)) ([9a635e9](https://github.com/Automattic/newspack-blocks/commit/9a635e9ffd86fd558365df1fec08d7943e08941c))
* **donate-block:** default frequency for tiered layout ([#2248](https://github.com/Automattic/newspack-blocks/issues/2248)) ([2d07c11](https://github.com/Automattic/newspack-blocks/commit/2d07c111cd678138ad09cec2b768fc7c98e1d35b))
* **modal-checkout:** processing payment screen ([#2247](https://github.com/Automattic/newspack-blocks/issues/2247)) ([8beea1e](https://github.com/Automattic/newspack-blocks/commit/8beea1e4fc67b150fc06b5a3b46c827a7d908f1c))


### Reverts

* Revert "fix: render modal checkout with filtered supported gateways ([#2240](https://github.com/Automattic/newspack-blocks/issues/2240))" ([#2243](https://github.com/Automattic/newspack-blocks/issues/2243)) ([200412b](https://github.com/Automattic/newspack-blocks/commit/200412bf969fc825090ffbe9b567886827e8eac4))

## [4.17.6](https://github.com/Automattic/newspack-blocks/compare/v4.17.5...v4.17.6) (2025-11-19)


### Bug Fixes

* don’t show trial info in modal checkout if not applicable ([#2245](https://github.com/Automattic/newspack-blocks/issues/2245)) ([26de0b9](https://github.com/Automattic/newspack-blocks/commit/26de0b9cf223dd3e5c5d4ee56ba6a8a915554baf))

## [4.17.5](https://github.com/Automattic/newspack-blocks/compare/v4.17.4...v4.17.5) (2025-11-18)


### Bug Fixes

* **modal-checkout:** check product type to get recurrence data ([#2259](https://github.com/Automattic/newspack-blocks/issues/2259)) ([0c2ed81](https://github.com/Automattic/newspack-blocks/commit/0c2ed81a0ec2f68ae1aff3eb98633ed52def3218))

## [4.17.4](https://github.com/Automattic/newspack-blocks/compare/v4.17.3...v4.17.4) (2025-11-13)


### Bug Fixes

* content gate metadata methods and params ([163e07f](https://github.com/Automattic/newspack-blocks/commit/163e07f56653b39eee68145659419d917606693d))
* content gate metadata methods and params ([#2249](https://github.com/Automattic/newspack-blocks/issues/2249)) ([fe6cc18](https://github.com/Automattic/newspack-blocks/commit/fe6cc18898349d978af8f0dd9d62b65ed92df652))

## [4.17.3](https://github.com/Automattic/newspack-blocks/compare/v4.17.2...v4.17.3) (2025-11-11)


### Bug Fixes

* content loop load more for custom taxs ([#2244](https://github.com/Automattic/newspack-blocks/issues/2244)) ([a2c5040](https://github.com/Automattic/newspack-blocks/commit/a2c5040cc27fc4a0adb6826505eda736edee5552))

## [4.17.2](https://github.com/Automattic/newspack-blocks/compare/v4.17.1...v4.17.2) (2025-11-03)


### Bug Fixes

* handle multiple responsive iframe blocks ([#2242](https://github.com/Automattic/newspack-blocks/issues/2242)) ([dc218ea](https://github.com/Automattic/newspack-blocks/commit/dc218ea08ecc5f134b5a1dd4e7d4d6a25a8d71e5))

## [4.17.1](https://github.com/Automattic/newspack-blocks/compare/v4.17.0...v4.17.1) (2025-11-03)


### Bug Fixes

* render modal checkout with filtered supported gateways ([#2240](https://github.com/Automattic/newspack-blocks/issues/2240)) ([ba74667](https://github.com/Automattic/newspack-blocks/commit/ba74667f28f674eb028dd1dc613e6b3c90206efa))

# [4.17.0](https://github.com/Automattic/newspack-blocks/compare/v4.16.1...v4.17.0) (2025-10-30)


### Bug Fixes

* correct RTL styles for the Content Loop ([#2215](https://github.com/Automattic/newspack-blocks/issues/2215)) ([a8e2f42](https://github.com/Automattic/newspack-blocks/commit/a8e2f42655c63dbea3a6a8cf3fd0c497ae038365))
* ga4 events for tiered modal ([#2218](https://github.com/Automattic/newspack-blocks/issues/2218)) ([a08981d](https://github.com/Automattic/newspack-blocks/commit/a08981dd7c7ea168825a12bddc74840493560d3e))
* handle invalid file errors when processing archive ([#2221](https://github.com/Automattic/newspack-blocks/issues/2221)) ([2ba3d26](https://github.com/Automattic/newspack-blocks/commit/2ba3d26fc2864f8f28705d26866deb136d32a7e8))
* **modal-checkout:** skip newsletter signup on subscription switch ([#2235](https://github.com/Automattic/newspack-blocks/issues/2235)) ([e6feccc](https://github.com/Automattic/newspack-blocks/commit/e6feccc5277dfdfcf909603e134a1f21d64fc938))
* **modal-checkout:** style improvements to single-gateway ([#2223](https://github.com/Automattic/newspack-blocks/issues/2223)) ([3c0014a](https://github.com/Automattic/newspack-blocks/commit/3c0014a463649545a3d5680c4ca16eca2c23de5e))


### Features

* add newspack-colors and newspack-icons packages ([#2222](https://github.com/Automattic/newspack-blocks/issues/2222)) ([f6bdf20](https://github.com/Automattic/newspack-blocks/commit/f6bdf20434f34e813b13856266372ad355f31f19))

## [4.16.1](https://github.com/Automattic/newspack-blocks/compare/v4.16.0...v4.16.1) (2025-10-27)


### Bug Fixes

* update subscriptions gifting method ([#2232](https://github.com/Automattic/newspack-blocks/issues/2232)) ([6b5e665](https://github.com/Automattic/newspack-blocks/commit/6b5e665c6797cb26228613fb7cdc96c8889455c5))

# [4.16.0](https://github.com/Automattic/newspack-blocks/compare/v4.15.4...v4.16.0) (2025-10-06)


### Bug Fixes

* **checkout-button:** editor's align center ([#2207](https://github.com/Automattic/newspack-blocks/issues/2207)) ([0279b3d](https://github.com/Automattic/newspack-blocks/commit/0279b3debf80c626d8b98008545ac88aa4f83808))
* clarify NRH dependency for Campaign ID sidebar attribute ([#2214](https://github.com/Automattic/newspack-blocks/issues/2214)) ([a25087c](https://github.com/Automattic/newspack-blocks/commit/a25087c7c85b32d06caeb80589627f2312046347))
* ga4 events for tiered modal ([#2218](https://github.com/Automattic/newspack-blocks/issues/2218)) ([74cf571](https://github.com/Automattic/newspack-blocks/commit/74cf571dbeb5e963f817f9f3cb02ecd117f9ab50))
* input hardening ([12a87f1](https://github.com/Automattic/newspack-blocks/commit/12a87f1b03c6c2f762aa9a65e8d14b0ae437953b))
* update download URL for db.php ([#2208](https://github.com/Automattic/newspack-blocks/issues/2208)) ([d334f82](https://github.com/Automattic/newspack-blocks/commit/d334f8275ade2c1a8b5d53d9ca44bb44db5d28e8))


### Features

* better error messages for limited products ([#2202](https://github.com/Automattic/newspack-blocks/issues/2202)) ([5572d62](https://github.com/Automattic/newspack-blocks/commit/5572d623d5cdfc04be9dc4f317d512797d248dfd))
* **checkout-button:** grouped product support ([#2201](https://github.com/Automattic/newspack-blocks/issues/2201)) ([f053be1](https://github.com/Automattic/newspack-blocks/commit/f053be1d730a99289f6f8b981e3604095033775c))
* **modal-checkout:** switch subscription support ([#2204](https://github.com/Automattic/newspack-blocks/issues/2204)) ([6906e23](https://github.com/Automattic/newspack-blocks/commit/6906e234dfa23e3ce0dcfd92cb337f63d1b239e1))
* replace buttongroup with togglegroupcontrol ([#2197](https://github.com/Automattic/newspack-blocks/issues/2197)) ([26920db](https://github.com/Automattic/newspack-blocks/commit/26920db7295d41eb62ce0835e1250576aab2e919))
* support for iframe auto-resizing ([541af11](https://github.com/Automattic/newspack-blocks/commit/541af116a128dc4fe43281fd717308349f5dfdf8))

## [4.15.4](https://github.com/Automattic/newspack-blocks/compare/v4.15.3...v4.15.4) (2025-10-01)


### Bug Fixes

* **iframe-block:** validate file extensions before allowing upload ([#2220](https://github.com/Automattic/newspack-blocks/issues/2220)) ([263e419](https://github.com/Automattic/newspack-blocks/commit/263e419b56814d3f848d6629a5442f54b1dad28d))

## [4.15.3](https://github.com/Automattic/newspack-blocks/compare/v4.15.2...v4.15.3) (2025-09-25)


### Bug Fixes

* **modal-checkout:** handling of tiered donation block ([#2216](https://github.com/Automattic/newspack-blocks/issues/2216)) ([5f74453](https://github.com/Automattic/newspack-blocks/commit/5f744533f6a1f14a8adc4fe1304f5912cb691905))

## [4.15.2](https://github.com/Automattic/newspack-blocks/compare/v4.15.1...v4.15.2) (2025-09-22)


### Bug Fixes

* **checkout-button:** editor width settings ([#2196](https://github.com/Automattic/newspack-blocks/issues/2196)) ([4862c37](https://github.com/Automattic/newspack-blocks/commit/4862c3796d0b5197c56f2ab004d83ac8bf919ea6))
* **donate:** modern style variation wrong border-radius ([#2195](https://github.com/Automattic/newspack-blocks/issues/2195)) ([b756404](https://github.com/Automattic/newspack-blocks/commit/b756404ce53e430583c30a79650ee611a5082720))

## [4.15.1](https://github.com/Automattic/newspack-blocks/compare/v4.15.0...v4.15.1) (2025-09-08)


### Bug Fixes

* **modal-checkout:** apply newspack-ui styles to subscription confirmation checkbox ([#2193](https://github.com/Automattic/newspack-blocks/issues/2193)) ([02f1e4e](https://github.com/Automattic/newspack-blocks/commit/02f1e4e2cbdd5788230d581582718fcbc7fc8b0c))
* **modal-checkout:** support stripe optimized checkout ([#2189](https://github.com/Automattic/newspack-blocks/issues/2189)) ([d42e8ca](https://github.com/Automattic/newspack-blocks/commit/d42e8caa7028a612e34543f41834b45d64b77413))
* show updated date if later than published ([6df3883](https://github.com/Automattic/newspack-blocks/commit/6df388375b0d5b7d58d3025233ee59730f2aa395))

# [4.15.0](https://github.com/Automattic/newspack-blocks/compare/v4.14.8...v4.15.0) (2025-08-25)


### Bug Fixes

* skip acct creation on verification request ([#2182](https://github.com/Automattic/newspack-blocks/issues/2182)) [trigger release] ([caec396](https://github.com/Automattic/newspack-blocks/commit/caec3965df53a97b603e289ec0e32eb2b0d46655))


### Features

* add hooks around the content loop's content ([#2187](https://github.com/Automattic/newspack-blocks/issues/2187)) ([a2c4c37](https://github.com/Automattic/newspack-blocks/commit/a2c4c37f69b8b02afe9c9a78c98a388b14c97fc3))

## [4.14.8](https://github.com/Automattic/newspack-blocks/compare/v4.14.7...v4.14.8) (2025-08-12)


### Bug Fixes

* **modal-checkout:** account for signup fee in price summary ([#2183](https://github.com/Automattic/newspack-blocks/issues/2183)) ([9ba0a6a](https://github.com/Automattic/newspack-blocks/commit/9ba0a6a84fd727e42bec3db2813b2530cab0dbfa))

## [4.14.7](https://github.com/Automattic/newspack-blocks/compare/v4.14.6...v4.14.7) (2025-08-12)


### Bug Fixes

* **author-block:** classname application ([#2172](https://github.com/Automattic/newspack-blocks/issues/2172)) ([b689c66](https://github.com/Automattic/newspack-blocks/commit/b689c667d219199e5ae3a4d5a849eb7fb4334b07))
* skip acct creation on verification request ([#2182](https://github.com/Automattic/newspack-blocks/issues/2182)) ([8aaef1d](https://github.com/Automattic/newspack-blocks/commit/8aaef1db423c8b1d9cbcc3f3f1a53220b0d82743))

## [4.14.6](https://github.com/Automattic/newspack-blocks/compare/v4.14.5...v4.14.6) (2025-07-28)


### Bug Fixes

* Fix/author list roles and exclusion ([#2166](https://github.com/Automattic/newspack-blocks/issues/2166)) ([f2dfe4d](https://github.com/Automattic/newspack-blocks/commit/f2dfe4d1297740d483e25ef680f4cf5735ba2a28))
* remove current page deduplication from logic when deduplication is disabled ([#2169](https://github.com/Automattic/newspack-blocks/issues/2169)) ([4000d85](https://github.com/Automattic/newspack-blocks/commit/4000d858b6931137d883e06b2b6c1200a420dfa7))

## [4.14.5](https://github.com/Automattic/newspack-blocks/compare/v4.14.4...v4.14.5) (2025-07-14)


### Bug Fixes

* **modal-checkout:** price formatting with interval and trial handling ([#2153](https://github.com/Automattic/newspack-blocks/issues/2153)) ([6570cd4](https://github.com/Automattic/newspack-blocks/commit/6570cd46d07c9c030c9a5769a66ba646e2a21308))

## [4.14.4](https://github.com/Automattic/newspack-blocks/compare/v4.14.3...v4.14.4) (2025-07-14)


### Bug Fixes

* make sure RTL styles are loaded ([#2164](https://github.com/Automattic/newspack-blocks/issues/2164)) ([c4387f5](https://github.com/Automattic/newspack-blocks/commit/c4387f530c46c924d6181bab5962b59d0700ed6d))

## [4.14.3](https://github.com/Automattic/newspack-blocks/compare/v4.14.2...v4.14.3) (2025-07-08)


### Bug Fixes

* **modal-checkout:** return array when getting event handlers ([#2162](https://github.com/Automattic/newspack-blocks/issues/2162)) ([c9ba6ec](https://github.com/Automattic/newspack-blocks/commit/c9ba6ec285e2da43d1178451e9d80bba8044974a))

## [4.14.2](https://github.com/Automattic/newspack-blocks/compare/v4.14.1...v4.14.2) (2025-07-02)


### Bug Fixes

* revert payment gateway check changes from [#2135](https://github.com/Automattic/newspack-blocks/issues/2135) ([#2160](https://github.com/Automattic/newspack-blocks/issues/2160)) ([0bb9977](https://github.com/Automattic/newspack-blocks/commit/0bb9977a3b1328013b16f59f030faacb64fed9c8))

## [4.14.1](https://github.com/Automattic/newspack-blocks/compare/v4.14.0...v4.14.1) (2025-07-02)


### Bug Fixes

* update supported gateway check to improve accuracy ([#2135](https://github.com/Automattic/newspack-blocks/issues/2135)) ([bca370e](https://github.com/Automattic/newspack-blocks/commit/bca370e64ecf0003e4e83213e393a9389518fef4))

# [4.14.0](https://github.com/Automattic/newspack-blocks/compare/v4.13.1...v4.14.0) (2025-06-30)


### Bug Fixes

* **homepage-articles:** add styles outside of the block markup ([#2145](https://github.com/Automattic/newspack-blocks/issues/2145)) ([f16ea93](https://github.com/Automattic/newspack-blocks/commit/f16ea93855b33a6513ca018899e8afe89759f51e))
* **modal-checkout:** better management of modal states ([#2146](https://github.com/Automattic/newspack-blocks/issues/2146)) ([30f3ccc](https://github.com/Automattic/newspack-blocks/commit/30f3ccc0238923c4333d8fb418e91f55b47b46e4))


### Features

* **modal-checkout:** change subscription payment method ([#2141](https://github.com/Automattic/newspack-blocks/issues/2141)) ([797223c](https://github.com/Automattic/newspack-blocks/commit/797223c58a25277a9aa3d66be3b2613c2ffb8866))

## [4.13.1](https://github.com/Automattic/newspack-blocks/compare/v4.13.0...v4.13.1) (2025-06-25)


### Bug Fixes

* **modal-checkout:** price summary recurrence ([#2152](https://github.com/Automattic/newspack-blocks/issues/2152)) ([35ebab2](https://github.com/Automattic/newspack-blocks/commit/35ebab2771bbb5682bd0a59d32b117942ffa3b50))

# [4.13.0](https://github.com/Automattic/newspack-blocks/compare/v4.12.3...v4.13.0) (2025-06-16)


### Bug Fixes

* donate block formatting ([#2133](https://github.com/Automattic/newspack-blocks/issues/2133)) ([f27cb76](https://github.com/Automattic/newspack-blocks/commit/f27cb761a800999e526e197f2b4c1b47895e20bc))
* update stripe express checkout divider ([#2131](https://github.com/Automattic/newspack-blocks/issues/2131)) ([b907452](https://github.com/Automattic/newspack-blocks/commit/b907452a8b91cf162afabee9af9e3313a1e27223))


### Features

* **modal-checkout:** checkout from "My Account" dashboard ([#2121](https://github.com/Automattic/newspack-blocks/issues/2121)) ([0c5301b](https://github.com/Automattic/newspack-blocks/commit/0c5301b38e93b2c958dc04c8bba6302ec397dc17))

## [4.12.3](https://github.com/Automattic/newspack-blocks/compare/v4.12.2...v4.12.3) (2025-06-03)


### Bug Fixes

* **homepage-articles:** revert block inline style enqueue strategy ([#2139](https://github.com/Automattic/newspack-blocks/issues/2139)) ([290edd5](https://github.com/Automattic/newspack-blocks/commit/290edd51f246a797ec47a035fbad0aa5ae086d31))

## [4.12.2](https://github.com/Automattic/newspack-blocks/compare/v4.12.1...v4.12.2) (2025-06-02)


### Bug Fixes

* **homepage-articles:** add style to head ([#2123](https://github.com/Automattic/newspack-blocks/issues/2123)) ([9305927](https://github.com/Automattic/newspack-blocks/commit/9305927466817d7287d2e6a4f2f3dcf9d473f941))
* the warning generated by get_term_classes because the get_the_terms might return null element ([#2115](https://github.com/Automattic/newspack-blocks/issues/2115)) ([04808de](https://github.com/Automattic/newspack-blocks/commit/04808de9aa37f810a74aa1335261422013b316e7))

## [4.12.1](https://github.com/Automattic/newspack-blocks/compare/v4.12.0...v4.12.1) (2025-05-29)


### Bug Fixes

* **checkout-button:** action hook for hidden fields ([#2134](https://github.com/Automattic/newspack-blocks/issues/2134)) ([c5ee4d4](https://github.com/Automattic/newspack-blocks/commit/c5ee4d4c7f2cf68abd97466b2dd570b77ec06365))

# [4.12.0](https://github.com/Automattic/newspack-blocks/compare/v4.11.0...v4.12.0) (2025-05-14)


### Bug Fixes

* adjust cache handling for feeds ([#2112](https://github.com/Automattic/newspack-blocks/issues/2112)) ([f880061](https://github.com/Automattic/newspack-blocks/commit/f8800618a7f61317f0a3e661a469d31982f6e49c))
* update how we check for the express checkout in the is_modal check ([#2109](https://github.com/Automattic/newspack-blocks/issues/2109)) ([652daf7](https://github.com/Automattic/newspack-blocks/commit/652daf763301079c2ad8c99bf0db14d5ee9c2830))


### Features

* add body class depending on theme type ([#2101](https://github.com/Automattic/newspack-blocks/issues/2101)) ([efcda76](https://github.com/Automattic/newspack-blocks/commit/efcda76d08b589657a99fc48774991c4ecc2d458))
* **content-loop:** support custom bylines ([#2105](https://github.com/Automattic/newspack-blocks/issues/2105)) ([6f70449](https://github.com/Automattic/newspack-blocks/commit/6f704494045e6950c49b2d9ccd11a2c07dd5c5b4))
* **wcsg:** respect checkbox label option in WCSG settings ([#2053](https://github.com/Automattic/newspack-blocks/issues/2053)) ([58e40c5](https://github.com/Automattic/newspack-blocks/commit/58e40c5c61fe339987a13de28a910b7d9c39d395))

# [4.11.0](https://github.com/Automattic/newspack-blocks/compare/v4.10.2...v4.11.0) (2025-05-08)


### Features

* update styles for the Subscription Confirmation checkbox ([#2117](https://github.com/Automattic/newspack-blocks/issues/2117)) ([e61c430](https://github.com/Automattic/newspack-blocks/commit/e61c43045d4fcff282baac6c18b1c3d711c7cc46))

## [4.10.2](https://github.com/Automattic/newspack-blocks/compare/v4.10.1...v4.10.2) (2025-04-24)


### Bug Fixes

* add the timezone to the Content Loop datetime attribute ([#2113](https://github.com/Automattic/newspack-blocks/issues/2113)) ([6fbd004](https://github.com/Automattic/newspack-blocks/commit/6fbd0044bd7b5822d464bd569cc4941df5d81e19))

## [4.10.1](https://github.com/Automattic/newspack-blocks/compare/v4.10.0...v4.10.1) (2025-04-21)


### Bug Fixes

* **recaptcha:** move clone button code to Newspack\Recaptcha class ([#2111](https://github.com/Automattic/newspack-blocks/issues/2111)) ([a7bcdbd](https://github.com/Automattic/newspack-blocks/commit/a7bcdbd221b19fdbc4d565b0233d1b6de954c1b0))

# [4.10.0](https://github.com/Automattic/newspack-blocks/compare/v4.9.2...v4.10.0) (2025-04-14)


### Bug Fixes

* correct Content Loop button width in WP 6.8 ([#2090](https://github.com/Automattic/newspack-blocks/issues/2090)) ([c39a46c](https://github.com/Automattic/newspack-blocks/commit/c39a46c74a0aee7334c0d7bb824ba0b02f00d7f4))
* remove unneeded editor-style-wrapper class ([#2089](https://github.com/Automattic/newspack-blocks/issues/2089)) ([e13ea9e](https://github.com/Automattic/newspack-blocks/commit/e13ea9ebecd68305f80a820c573e8ed805ad214c))


### Features

* **content-loop:** Allow for AND (inter-join) logic ([#2092](https://github.com/Automattic/newspack-blocks/issues/2092)) ([02053aa](https://github.com/Automattic/newspack-blocks/commit/02053aa2d9029cf5a4f91cff8612af38f849e77b))

## [4.9.2](https://github.com/Automattic/newspack-blocks/compare/v4.9.1...v4.9.2) (2025-04-10)


### Bug Fixes

* tweak button update functionality for Braintree ([#2104](https://github.com/Automattic/newspack-blocks/issues/2104)) ([0248e82](https://github.com/Automattic/newspack-blocks/commit/0248e82a6a5be4b9dbf9c37fdca706a9cc86cbd0))

## [4.9.1](https://github.com/Automattic/newspack-blocks/compare/v4.9.0...v4.9.1) (2025-04-02)


### Bug Fixes

* **donate-block:** handle decimals in custom amount input, fixed ([8090da1](https://github.com/Automattic/newspack-blocks/commit/8090da1d99da9b1e576c7ca0057bb4fbd93e00e8))

# [4.9.0](https://github.com/Automattic/newspack-blocks/compare/v4.8.1...v4.9.0) (2025-03-31)


### Features

* trigger relase ([4be679e](https://github.com/Automattic/newspack-blocks/commit/4be679ebaf3aa6b7d0cf4917f52bcae1a8a71a5f))

## [4.8.1](https://github.com/Automattic/newspack-blocks/compare/v4.8.0...v4.8.1) (2025-03-31)


### Reverts

* Revert "fix(donate-block): handle decimals in custom amount input (#2074)" (#2094) ([bc4efc2](https://github.com/Automattic/newspack-blocks/commit/bc4efc2a4d6af25ae177ebd5902f69c42495df67)), closes [#2074](https://github.com/Automattic/newspack-blocks/issues/2074) [#2094](https://github.com/Automattic/newspack-blocks/issues/2094)

# [4.8.0](https://github.com/Automattic/newspack-blocks/compare/v4.7.1...v4.8.0) (2025-03-31)


### Bug Fixes

* **api:** avoid warning newspack blocks api accessing attachment images ([#2069](https://github.com/Automattic/newspack-blocks/issues/2069)) ([93ffebf](https://github.com/Automattic/newspack-blocks/commit/93ffebf19ae195aa23fd175635c16644d67a33bf))
* better handle deleted terms ([#2076](https://github.com/Automattic/newspack-blocks/issues/2076)) ([7d7a639](https://github.com/Automattic/newspack-blocks/commit/7d7a6396e1e5e388c178e0ce58bcfa8962565075))
* correct checkout button block width in WP 6.8 ([#2087](https://github.com/Automattic/newspack-blocks/issues/2087)) ([a9def73](https://github.com/Automattic/newspack-blocks/commit/a9def73c86aac2eb8c71b5e75890b2c09732c54b))
* **donate-block:** handle decimals in custom amount input ([#2074](https://github.com/Automattic/newspack-blocks/issues/2074)) ([9db74fe](https://github.com/Automattic/newspack-blocks/commit/9db74fe6f703e104b5703065ce86ac17e1abad07))
* make sure five column border layout works ([#2072](https://github.com/Automattic/newspack-blocks/issues/2072)) ([a1dbfbc](https://github.com/Automattic/newspack-blocks/commit/a1dbfbce9091072e64b763c9a13316a7d1bf8943))
* **modal-checkout:** trigger REST API endpoint to refresh post-checko… ([#2080](https://github.com/Automattic/newspack-blocks/issues/2080)) ([e534446](https://github.com/Automattic/newspack-blocks/commit/e5344463fc07207e7406f7a4b9d0a7d4708a6359))


### Features

* information architecture ([#2083](https://github.com/Automattic/newspack-blocks/issues/2083)) ([2d75fd1](https://github.com/Automattic/newspack-blocks/commit/2d75fd165ea26b4e2f4febebfb7ee28cb9f749ea))

## [4.7.1](https://github.com/Automattic/newspack-blocks/compare/v4.7.0...v4.7.1) (2025-03-18)


### Bug Fixes

* **modal-checkout:** trigger REST API endpoint to refresh post-checko… ([#2080](https://github.com/Automattic/newspack-blocks/issues/2080)) ([8258e52](https://github.com/Automattic/newspack-blocks/commit/8258e52f31edff73ff9a676a34df7bf200cb98e5))
* prevent php notice when filtering scripts ([77408f3](https://github.com/Automattic/newspack-blocks/commit/77408f36f654a74e390d4b8c88c5d110198239ae))
* remove coming soon mode message from the modal checkout ([#2067](https://github.com/Automattic/newspack-blocks/issues/2067)) ([c27937d](https://github.com/Automattic/newspack-blocks/commit/c27937d6ec51a9f0235b9aeee6d8111d6efe1574))
* remove OneSignal v2 from modal checkout ([#2061](https://github.com/Automattic/newspack-blocks/issues/2061)) ([9727e02](https://github.com/Automattic/newspack-blocks/commit/9727e0247eb5f3599cdf44eefbbe40fc3516d86f))

# [4.7.0](https://github.com/Automattic/newspack-blocks/compare/v4.6.1...v4.7.0) (2025-03-04)


### Bug Fixes

* **carousel:** avoid editor crash on empty ([#2058](https://github.com/Automattic/newspack-blocks/issues/2058)) ([c316801](https://github.com/Automattic/newspack-blocks/commit/c316801b54059dcac79fa463dc0df7a8e78bdc01))
* **modal-checkout:** better iframe sizing ([#2052](https://github.com/Automattic/newspack-blocks/issues/2052)) ([aa308f2](https://github.com/Automattic/newspack-blocks/commit/aa308f2acd956e5f0e5282493f9b33e83ae8e391))
* **recaptcha:** use clone of #place_order button to trigger checkout ([#2028](https://github.com/Automattic/newspack-blocks/issues/2028)) ([46eb8b5](https://github.com/Automattic/newspack-blocks/commit/46eb8b5b2a99e907eabbfab9cfed1fd4a44687da)), closes [#2030](https://github.com/Automattic/newspack-blocks/issues/2030) [#2030](https://github.com/Automattic/newspack-blocks/issues/2030)


### Features

* add styles to fix Braintree modal appearance ([#2036](https://github.com/Automattic/newspack-blocks/issues/2036)) ([9ab2c62](https://github.com/Automattic/newspack-blocks/commit/9ab2c62d705879cc6164fc179093d37e562dfbac))
* add toggle for transaction details ([#2049](https://github.com/Automattic/newspack-blocks/issues/2049)) ([d254aca](https://github.com/Automattic/newspack-blocks/commit/d254aca605a6e90b6e6b4b586527376cda1f86f8))
* **carousel:** rename block and reorganise settings ([#1962](https://github.com/Automattic/newspack-blocks/issues/1962)) ([9905717](https://github.com/Automattic/newspack-blocks/commit/9905717a90b660ff3136871aaf3f97a915a48f23))
* update blocks with new brand ([#2050](https://github.com/Automattic/newspack-blocks/issues/2050)) ([2711302](https://github.com/Automattic/newspack-blocks/commit/27113027329484675efce2d009c4c59f56074ee3))

## [4.6.1](https://github.com/Automattic/newspack-blocks/compare/v4.6.0...v4.6.1) (2025-02-27)


### Bug Fixes

* restore non-API version of the editor styles ([#2062](https://github.com/Automattic/newspack-blocks/issues/2062)) ([b726d4b](https://github.com/Automattic/newspack-blocks/commit/b726d4b15dee9d3fae653bdd57246b219edd11e0))

# [4.6.0](https://github.com/Automattic/newspack-blocks/compare/v4.5.10...v4.6.0) (2025-02-17)


### Bug Fixes

* enforce the password strength on checkout ([#2037](https://github.com/Automattic/newspack-blocks/issues/2037)) ([b9dcf03](https://github.com/Automattic/newspack-blocks/commit/b9dcf03e9d1ceefcb481eabb59f5837ad56788d4))
* make sure membership gate refreshes after variation purchase ([#2044](https://github.com/Automattic/newspack-blocks/issues/2044)) ([1555b0f](https://github.com/Automattic/newspack-blocks/commit/1555b0ff5a65286bfbf0cc1f19a1eb6df2e9d97c))
* remove OneSignal script from the modal checkout ([#2029](https://github.com/Automattic/newspack-blocks/issues/2029)) ([343ca5e](https://github.com/Automattic/newspack-blocks/commit/343ca5edf001acfd83a91b9726d5dd0687d749d5))
* return no posts for authors without term ([#2033](https://github.com/Automattic/newspack-blocks/issues/2033)) ([e331139](https://github.com/Automattic/newspack-blocks/commit/e331139ea60fa1a784179e3261f36cd94630a67d))


### Features

* **homepage-articles:** rename block and reorganise settings ([#2000](https://github.com/Automattic/newspack-blocks/issues/2000)) ([841de19](https://github.com/Automattic/newspack-blocks/commit/841de1975232307640e730f32715dd5d223600a1))
* rename all homepage posts patterns to content loop patterns ([#2046](https://github.com/Automattic/newspack-blocks/issues/2046)) ([9b6bdee](https://github.com/Automattic/newspack-blocks/commit/9b6bdeed4df250753963f7bbaeca5d85ed76193a))

## [4.5.10](https://github.com/Automattic/newspack-blocks/compare/v4.5.9...v4.5.10) (2025-02-04)


### Bug Fixes

* **modal-checkout:** check WC before enqueue the modal ([#2043](https://github.com/Automattic/newspack-blocks/issues/2043)) ([7a13d37](https://github.com/Automattic/newspack-blocks/commit/7a13d3799c70a926a30518e0a943170b0100394d))

## [4.5.9](https://github.com/Automattic/newspack-blocks/compare/v4.5.8...v4.5.9) (2025-02-03)


### Bug Fixes

* add supported gateways check ([#2009](https://github.com/Automattic/newspack-blocks/issues/2009)) ([1462233](https://github.com/Automattic/newspack-blocks/commit/1462233821b4f41ef6b80f6929243dc1b805cdb1))
* return no posts for authors without term ([#2033](https://github.com/Automattic/newspack-blocks/issues/2033)) ([3b72633](https://github.com/Automattic/newspack-blocks/commit/3b7263349707326bf988ff6a815cf92ffb061972))

## [4.5.8](https://github.com/Automattic/newspack-blocks/compare/v4.5.7...v4.5.8) (2025-02-03)


### Bug Fixes

* **checkout-button:** handle missing saved product ([#2038](https://github.com/Automattic/newspack-blocks/issues/2038)) ([b251f99](https://github.com/Automattic/newspack-blocks/commit/b251f999a2dd71a5c6aedfeb3bf9d03bef24e3f4))

## [4.5.7](https://github.com/Automattic/newspack-blocks/compare/v4.5.6...v4.5.7) (2025-01-31)


### Bug Fixes

* improve formatting of donation amount options ([#2032](https://github.com/Automattic/newspack-blocks/issues/2032)) ([a86472c](https://github.com/Automattic/newspack-blocks/commit/a86472c8068d873c31cb6bb37e08bcfd9d487be3))

## [4.5.6](https://github.com/Automattic/newspack-blocks/compare/v4.5.5...v4.5.6) (2025-01-23)


### Bug Fixes

* make sure fields after floated inputs clear floats in the modal checkout ([#2030](https://github.com/Automattic/newspack-blocks/issues/2030)) ([274b4d4](https://github.com/Automattic/newspack-blocks/commit/274b4d41aa63dc818a3da49a7fc8315dbdf08757))

## [4.5.5](https://github.com/Automattic/newspack-blocks/compare/v4.5.4...v4.5.5) (2025-01-20)


### Bug Fixes

* **modal-checkout:** force checkout registration option ([#2007](https://github.com/Automattic/newspack-blocks/issues/2007)) ([acad91c](https://github.com/Automattic/newspack-blocks/commit/acad91c18a3bef5be22387a09af0207f87df3023))
* tweak modal iframe size ([#2006](https://github.com/Automattic/newspack-blocks/issues/2006)) ([fef66e1](https://github.com/Automattic/newspack-blocks/commit/fef66e1b34345cc0634741367bb2ae0879d8099c))

## [4.5.4](https://github.com/Automattic/newspack-blocks/compare/v4.5.3...v4.5.4) (2025-01-15)


### Bug Fixes

* make new debugging arg optional to avoid potential fatals ([#2022](https://github.com/Automattic/newspack-blocks/issues/2022)) ([54df06a](https://github.com/Automattic/newspack-blocks/commit/54df06a30d2221aa39a8eb2c8ca2705dd7b79b43))

## [4.5.3](https://github.com/Automattic/newspack-blocks/compare/v4.5.2...v4.5.3) (2025-01-14)


### Bug Fixes

* **recaptcha:** support reCAPTCHA v2 on form submit, not button click ([#2021](https://github.com/Automattic/newspack-blocks/issues/2021)) ([1e483a5](https://github.com/Automattic/newspack-blocks/commit/1e483a5412bbf38962700c09e4fa59fabc29f170))

## [4.5.2](https://github.com/Automattic/newspack-blocks/compare/v4.5.1...v4.5.2) (2024-12-16)


### Bug Fixes

* remove CAP compatibility mode ([414691d](https://github.com/Automattic/newspack-blocks/commit/414691d048a59a2c72ddcb1689289f07ab2102d8))
* removed another unused constant ([298cf88](https://github.com/Automattic/newspack-blocks/commit/298cf884228522a6d93e328d10391f5d7cfbac88))
* show x.com URLs and icons instead of Twitter in Author blocks ([036517b](https://github.com/Automattic/newspack-blocks/commit/036517b5a80342711f4dd571ae5de02c2d588c69))

## [4.5.1](https://github.com/Automattic/newspack-blocks/compare/v4.5.0...v4.5.1) (2024-12-09)


### Bug Fixes

* **modal-checkout:** remove any body outline ([#1994](https://github.com/Automattic/newspack-blocks/issues/1994)) ([75d5ad1](https://github.com/Automattic/newspack-blocks/commit/75d5ad1c651ab1218863b9443f02130d07ca3dae))

# [4.5.0](https://github.com/Automattic/newspack-blocks/compare/v4.4.0...v4.5.0) (2024-12-09)


### Bug Fixes

* also search for coauthor posts by term slug ([#1954](https://github.com/Automattic/newspack-blocks/issues/1954)) ([49357ff](https://github.com/Automattic/newspack-blocks/commit/49357ffa12bd2197d08379db0cd084f1cc5deb2a))
* **modal-checkout:** allow all gateway assets ([#1988](https://github.com/Automattic/newspack-blocks/issues/1988)) ([e371e30](https://github.com/Automattic/newspack-blocks/commit/e371e309a41dd0239088e5fc8914093073f0e23a))
* **modal-checkout:** handle paypal ([#1985](https://github.com/Automattic/newspack-blocks/issues/1985)) ([9bb2b8c](https://github.com/Automattic/newspack-blocks/commit/9bb2b8c9d82dbb9e5e110845c2a2f2d077aa7ee7))
* **ras-acc:** correct spacing issue around saved credit cards ([#1980](https://github.com/Automattic/newspack-blocks/issues/1980)) ([52a5c57](https://github.com/Automattic/newspack-blocks/commit/52a5c57ffe037b3de9471c9a4587a2c62c917712))
* **ras-acc:** fix display issues with Additional Fields ([#1979](https://github.com/Automattic/newspack-blocks/issues/1979)) ([b9390ef](https://github.com/Automattic/newspack-blocks/commit/b9390effc8096fc9faea48b49e90efdeabbe1513))
* **ras-acc:** remove space caused by empty divs ([#1978](https://github.com/Automattic/newspack-blocks/issues/1978)) ([8cb6ead](https://github.com/Automattic/newspack-blocks/commit/8cb6ead13aaa86743bb83ba9afb859277394d1df))
* remove reCaptcha for WooCommere code from modal checkout ([#1984](https://github.com/Automattic/newspack-blocks/issues/1984)) ([8e250eb](https://github.com/Automattic/newspack-blocks/commit/8e250eb8ef95ca960509beba9dcd6e7526aa9dc6))


### Features

* add Bluesky support to the Author Profile, List blocks ([#1969](https://github.com/Automattic/newspack-blocks/issues/1969)) ([d26a7e4](https://github.com/Automattic/newspack-blocks/commit/d26a7e4001e0645cba031143e2fbb18d4749def6))
* add CSS class to variation buttons for tracking ([#1989](https://github.com/Automattic/newspack-blocks/issues/1989)) ([910e6b1](https://github.com/Automattic/newspack-blocks/commit/910e6b157fd6412b37c7b0ffb56589412a0058a2))
* add support for Newspack Guest Contributor in HPP blocks ([#1934](https://github.com/Automattic/newspack-blocks/issues/1934)) ([c16849e](https://github.com/Automattic/newspack-blocks/commit/c16849e2cf62236b55a99fe63c72f68d727b3437))
* merge RAS-ACC work into trunk ([#1977](https://github.com/Automattic/newspack-blocks/issues/1977)) ([2eeaa89](https://github.com/Automattic/newspack-blocks/commit/2eeaa8933cd4ac877389362aa0815e36d50bc729))

# [4.4.0](https://github.com/Automattic/newspack-blocks/compare/v4.3.7...v4.4.0) (2024-11-25)


### Bug Fixes

* revert "feat(homepage-articles): rename block and reorganise settings ([#1943](https://github.com/Automattic/newspack-blocks/issues/1943))" ([#1971](https://github.com/Automattic/newspack-blocks/issues/1971)) ([fb161b9](https://github.com/Automattic/newspack-blocks/commit/fb161b983b799c2cfa745e8714bbef883ee38b62))


### Features

* add Bluesky support to the Author Profile, List blocks ([#1969](https://github.com/Automattic/newspack-blocks/issues/1969)) ([f1c29ab](https://github.com/Automattic/newspack-blocks/commit/f1c29ab43a6800db284719ef42cbe3a542d8ed6a))
* **donate-block:** hide buttons if only one frequency is available ([#1853](https://github.com/Automattic/newspack-blocks/issues/1853)) ([043591e](https://github.com/Automattic/newspack-blocks/commit/043591efef1278575fabeac1bc99000db7a63ed6))
* **homepage-articles:** rename block and reorganise settings ([#1943](https://github.com/Automattic/newspack-blocks/issues/1943)) ([1af11a4](https://github.com/Automattic/newspack-blocks/commit/1af11a45742dd313c2cbe35c9af8da479c9cb569))

## [4.3.7](https://github.com/Automattic/newspack-blocks/compare/v4.3.6...v4.3.7) (2024-11-21)


### Bug Fixes

* do not invalidate tax queries ([#1968](https://github.com/Automattic/newspack-blocks/issues/1968)) ([a0c1915](https://github.com/Automattic/newspack-blocks/commit/a0c191561e3bd6f16135590e66e5d695f2c3b3d3))

## [4.3.6](https://github.com/Automattic/newspack-blocks/compare/v4.3.5...v4.3.6) (2024-11-18)


### Bug Fixes

* trigger release with HPP fix ([2c8c1e6](https://github.com/Automattic/newspack-blocks/commit/2c8c1e6030e6a2b0777ee2756bb2d7196f74fca9))

## [4.3.5](https://github.com/Automattic/newspack-blocks/compare/v4.3.4...v4.3.5) (2024-11-11)


### Bug Fixes

* add check if product can be purchased ([#1916](https://github.com/Automattic/newspack-blocks/issues/1916)) ([cbe0cdb](https://github.com/Automattic/newspack-blocks/commit/cbe0cdb805354ae9dea6c0c35659d401198a30c2))
* **modal-checkout:** disable Continue button until JS is loaded ([d616ca3](https://github.com/Automattic/newspack-blocks/commit/d616ca38503d3c425332d8724c03109c985e26d4))
* revert check for is product purchaseable ([#1924](https://github.com/Automattic/newspack-blocks/issues/1924)) ([bcbe089](https://github.com/Automattic/newspack-blocks/commit/bcbe089711e4b7e8a9e88edddb245866b5e32bdb))
* update block classes for the site editor ([#1920](https://github.com/Automattic/newspack-blocks/issues/1920)) ([26136e1](https://github.com/Automattic/newspack-blocks/commit/26136e11527cd196db7a81735f57f73512f66950))

## [4.3.4](https://github.com/Automattic/newspack-blocks/compare/v4.3.3...v4.3.4) (2024-10-30)


### Bug Fixes

* name branch correctly ([a6fffd6](https://github.com/Automattic/newspack-blocks/commit/a6fffd620945666bcb6e10427309ea9b94baecb9))

## [4.3.3](https://github.com/Automattic/newspack-blocks/compare/v4.3.2...v4.3.3) (2024-10-29)


### Bug Fixes

* remove expensive query before registering video playlist block ([#1930](https://github.com/Automattic/newspack-blocks/issues/1930)) ([9d967e0](https://github.com/Automattic/newspack-blocks/commit/9d967e0e415ca78018540c2c033c06fd2fe64fab))

## [4.3.2](https://github.com/Automattic/newspack-blocks/compare/v4.3.1...v4.3.2) (2024-10-29)


### Bug Fixes

* dont cache blocks for staff ([2d30450](https://github.com/Automattic/newspack-blocks/commit/2d30450aa4047e92275e5d78c8ccb31ca96b345e))

## [4.3.1](https://github.com/Automattic/newspack-blocks/compare/v4.3.0...v4.3.1) (2024-10-28)


### Bug Fixes

* articles block fail to get all inner blocks ([#1878](https://github.com/Automattic/newspack-blocks/issues/1878)) ([c699724](https://github.com/Automattic/newspack-blocks/commit/c699724443ea916379c280fbc6208ecb9a3f5599))
* deprecate video playlist block ([#1903](https://github.com/Automattic/newspack-blocks/issues/1903)) ([4876de6](https://github.com/Automattic/newspack-blocks/commit/4876de65f2ad2d689ba93507a7a8d041fcc030ba))
* potential fatal with invalid attribute combo ([#1900](https://github.com/Automattic/newspack-blocks/issues/1900)) ([adf2e9d](https://github.com/Automattic/newspack-blocks/commit/adf2e9d82f90c8b9839f107bd2b4c249ff3045e8))
* update block classes for the site editor ([#1920](https://github.com/Automattic/newspack-blocks/issues/1920)) ([1a07ad0](https://github.com/Automattic/newspack-blocks/commit/1a07ad0dd88b68122b76f5f86c80d52d842e3c20))

# [4.3.0](https://github.com/Automattic/newspack-blocks/compare/v4.2.4...v4.3.0) (2024-10-28)


### Features

* **hpb:** remove blocks caching feature flag ([1d749de](https://github.com/Automattic/newspack-blocks/commit/1d749dedae9c48c1a3a38c4c13dad77528c7ea4e))

## [4.2.4](https://github.com/Automattic/newspack-blocks/compare/v4.2.3...v4.2.4) (2024-10-28)


### Bug Fixes

* let "Cover fees" checkbox work with any payment method ([#1915](https://github.com/Automattic/newspack-blocks/issues/1915)) ([0a3da2e](https://github.com/Automattic/newspack-blocks/commit/0a3da2eb38a9cd69bcd2a3348954b1fa3f9c9f59))

## [4.2.3](https://github.com/Automattic/newspack-blocks/compare/v4.2.2...v4.2.3) (2024-10-22)


### Bug Fixes

* carousel style when caching ([afb7f57](https://github.com/Automattic/newspack-blocks/commit/afb7f57c5aa115d7bcc626c120d34a93a46dec6e))

## [4.2.2](https://github.com/Automattic/newspack-blocks/compare/v4.2.1...v4.2.2) (2024-10-11)


### Bug Fixes

* **block-caching:** only serve from cache if all blocks are cached ([#1899](https://github.com/Automattic/newspack-blocks/issues/1899)) ([fb64d0c](https://github.com/Automattic/newspack-blocks/commit/fb64d0ca4ffc2f16870bcc51db83f090054a5d26))

## [4.2.1](https://github.com/Automattic/newspack-blocks/compare/v4.2.0...v4.2.1) (2024-10-09)


### Bug Fixes

* re-add block caching ([937ca53](https://github.com/Automattic/newspack-blocks/commit/937ca530fa46e3e404425eb0b8e1e40dea672a69))

# [4.2.0](https://github.com/Automattic/newspack-blocks/compare/v4.1.2...v4.2.0) (2024-10-08)


### Bug Fixes

* we know how long the cache is ([ec5083d](https://github.com/Automattic/newspack-blocks/commit/ec5083d233a2c2b534efd08b669d2274e8f3b570))


### Features

* block caching ([62ba143](https://github.com/Automattic/newspack-blocks/commit/62ba1439e420420cca940fab9c863d58fc2d0b70))
* **homepage-posts:** add a show-full-content attribute ([#1886](https://github.com/Automattic/newspack-blocks/issues/1886)) ([09619a9](https://github.com/Automattic/newspack-blocks/commit/09619a992d46b332e1b6358e7021cc5d4ce90d3b))
* **homepage-posts:** infinite scroll ([#1845](https://github.com/Automattic/newspack-blocks/issues/1845)) ([f5f1c20](https://github.com/Automattic/newspack-blocks/commit/f5f1c20c9033b9de116c96f1579417fe52060631))

## [4.1.2](https://github.com/Automattic/newspack-blocks/compare/v4.1.1...v4.1.2) (2024-09-17)


### Bug Fixes

* **modal-checkout:** tweak HTML to satisfy Woo Payments field validation ([#1883](https://github.com/Automattic/newspack-blocks/issues/1883)) ([16b5e9d](https://github.com/Automattic/newspack-blocks/commit/16b5e9db840d7dfccd064776285221c66d92e7f2))

## [4.1.1](https://github.com/Automattic/newspack-blocks/compare/v4.1.0...v4.1.1) (2024-09-16)


### Bug Fixes

* support new is_my_account() method from main plugin ([#1869](https://github.com/Automattic/newspack-blocks/issues/1869)) ([b9a3aba](https://github.com/Automattic/newspack-blocks/commit/b9a3aba064073f97bca5e08dd96ff450fe287faf))

# [4.1.0](https://github.com/Automattic/newspack-blocks/compare/v4.0.1...v4.1.0) (2024-08-26)


### Bug Fixes

* **homepage-posts:** honor allowing duplicates in load-more ([5ccecbd](https://github.com/Automattic/newspack-blocks/commit/5ccecbd86129e870a183dcb4fe65c18e412a1f48))


### Features

* consolidate data flows ([#1836](https://github.com/Automattic/newspack-blocks/issues/1836)) ([ee35511](https://github.com/Automattic/newspack-blocks/commit/ee355117b7c7ba9bee32cfd0e5402cc928559c75)), closes [#1804](https://github.com/Automattic/newspack-blocks/issues/1804) [#1827](https://github.com/Automattic/newspack-blocks/issues/1827)

## [4.0.1](https://github.com/Automattic/newspack-blocks/compare/v4.0.0...v4.0.1) (2024-08-21)


### Bug Fixes

* **homepage-posts:** exclude current post only on posts and pages ([3259da8](https://github.com/Automattic/newspack-blocks/commit/3259da85cba03818b3f94901a1c74d85279c443e))

# [4.0.0](https://github.com/Automattic/newspack-blocks/compare/v3.6.1...v4.0.0) (2024-08-13)


### Bug Fixes

* update dependencies to support `@wordpress/scripts` ([#1774](https://github.com/Automattic/newspack-blocks/issues/1774)) ([fb239f5](https://github.com/Automattic/newspack-blocks/commit/fb239f5684f1693da4d5f0d2c6544cf75ea9c141))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for dependency updates in newspack-scripts

* chore: proxy stylelint from newspack-scripts

* refactor: use proxy script for eslint and stylelint scripts

* fix: peer dependency conflicct

* fix: peer dependency conflicts

* fix: config conflicts

* fix: remove proxy scripts

* chore: test commitlint script

* fix: make everything work good

* fix: stylelint scripts

* fix: start command

* chore: update newspack-scripts to v5.6.0-alpha.3

* chore: remove ajv dependency

* chore: clear TS errors

* fix: reformat scss

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* chore: update newspack-scripts to v5.6.0-alpha.7

* fix: add fix:js script; temporarily remove format:js

* chore: update newspack-scripts to v5.6.0-alpha.8

* fix: composer.lock

* chore: restore format:js script

* chore: add NPM scripts for PHP

* chore: fixes for PHPCS errors

* chore: update newspack-components to v3.0.0

* chore: bump newspack-scripts to v5.5.2

## [3.6.1](https://github.com/Automattic/newspack-blocks/compare/v3.6.0...v3.6.1) (2024-08-12)


### Bug Fixes

* **wc-subs-limiting:** handle not-logged-in user ([#1816](https://github.com/Automattic/newspack-blocks/issues/1816)) ([d85a818](https://github.com/Automattic/newspack-blocks/commit/d85a8188515cf1e90256b7ecb2b3dbdb5e83ec10))

# [3.6.0](https://github.com/Automattic/newspack-blocks/compare/v3.5.0...v3.6.0) (2024-07-30)


### Features

* **donate:** update modern style tiered ([#1790](https://github.com/Automattic/newspack-blocks/issues/1790)) ([54c5159](https://github.com/Automattic/newspack-blocks/commit/54c5159bf0385c58779a00ad87663af229b10ed1))

# [3.5.0](https://github.com/Automattic/newspack-blocks/compare/v3.4.1...v3.5.0) (2024-07-15)


### Bug Fixes

* remove references to reCAPTCHA v3 (because we now support v2 too) ([#1744](https://github.com/Automattic/newspack-blocks/issues/1744)) ([b7fb752](https://github.com/Automattic/newspack-blocks/commit/b7fb752c4f5e903485939ccf025d0a7270c1e157))


### Features

* **checkout-button:** add wp-block-button to the wrapperclasses ([#1777](https://github.com/Automattic/newspack-blocks/issues/1777)) ([88bc28c](https://github.com/Automattic/newspack-blocks/commit/88bc28c70a3d214d38e05e50df403e0ac48dbf83))

## [3.4.1](https://github.com/Automattic/newspack-blocks/compare/v3.4.0...v3.4.1) (2024-07-11)


### Bug Fixes

* account for patterns when rendering hpp in editor ([#1794](https://github.com/Automattic/newspack-blocks/issues/1794)) ([17556c2](https://github.com/Automattic/newspack-blocks/commit/17556c2cb4eeb718aecbbd7258a986dac06df5ef))

# [3.4.0](https://github.com/Automattic/newspack-blocks/compare/v3.3.0...v3.4.0) (2024-07-01)


### Bug Fixes

* **block-styles:** columns shouldn't enforce gap for block themes ([#1770](https://github.com/Automattic/newspack-blocks/issues/1770)) ([05d4cc9](https://github.com/Automattic/newspack-blocks/commit/05d4cc9d08affbf9f8f15742a78c4109302f384a))
* **block-styles:** make sure columns style targets direct children ([#1769](https://github.com/Automattic/newspack-blocks/issues/1769)) ([0eebe14](https://github.com/Automattic/newspack-blocks/commit/0eebe14d204480444ad81dd3acf7f5fd69361b30))
* update newspack-scripts to v5.5.1 ([2a59c56](https://github.com/Automattic/newspack-blocks/commit/2a59c561b56ba7c032be1039f114746d9be67ec8))


### Features

* **donate:** add setting to change the style of tiered donations (grid or list) ([#1755](https://github.com/Automattic/newspack-blocks/issues/1755)) ([ce04cea](https://github.com/Automattic/newspack-blocks/commit/ce04ceab1e21a19d5be25d91abef06c87c2173c7))
* ensure regenerator-runtime is available (for WP 6.6) ([#1778](https://github.com/Automattic/newspack-blocks/issues/1778)) ([42236d7](https://github.com/Automattic/newspack-blocks/commit/42236d7366bfd1ba97fa441c462a0e033efa69ad))

# [3.3.0](https://github.com/Automattic/newspack-blocks/compare/v3.2.0...v3.3.0) (2024-06-12)


### Features

* improve subscribe patterns and use new style variation ([#1745](https://github.com/Automattic/newspack-blocks/issues/1745)) ([c55e830](https://github.com/Automattic/newspack-blocks/commit/c55e830d5de41647049c09b4228aa4f9dcd96eea))
* update donations patterns to match subscription pattern; add squeeze pattern ([#1752](https://github.com/Automattic/newspack-blocks/issues/1752)) ([818d89d](https://github.com/Automattic/newspack-blocks/commit/818d89d1f1cc1da2a4fc65178d2f02512ce791ee))

# [3.2.0](https://github.com/Automattic/newspack-blocks/compare/v3.1.0...v3.2.0) (2024-05-28)


### Bug Fixes

* **donate-block:** fix typo in textdomain and color variables ([#1738](https://github.com/Automattic/newspack-blocks/issues/1738)) ([7f7de88](https://github.com/Automattic/newspack-blocks/commit/7f7de8847f254c4f55b875b6476ff0334fdeba11))
* modal checkout continue button ([#1733](https://github.com/Automattic/newspack-blocks/issues/1733)) ([c672d4a](https://github.com/Automattic/newspack-blocks/commit/c672d4a26547f7948799c86b1ab7569bf31c40f2))
* respect subscriptions limiter, and handle cart errors in modal ([#1728](https://github.com/Automattic/newspack-blocks/issues/1728)) ([8a828fa](https://github.com/Automattic/newspack-blocks/commit/8a828fae726757c748ff97562c1a4fb6e8536e0c))


### Features

* **donate:** add new "Modern" style variation ([#1734](https://github.com/Automattic/newspack-blocks/issues/1734)) ([b83d840](https://github.com/Automattic/newspack-blocks/commit/b83d8400957c179c2c228bc8c1ea87deb047c2aa))
* update donations patterns and add new one ([#1731](https://github.com/Automattic/newspack-blocks/issues/1731)) ([607c93a](https://github.com/Automattic/newspack-blocks/commit/607c93ad5a207615eb79e9ff4871b2542caaed0b))

# [3.2.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.1.0...v3.2.0-alpha.1) (2024-05-20)


### Bug Fixes

* **donate-block:** fix typo in textdomain and color variables ([#1738](https://github.com/Automattic/newspack-blocks/issues/1738)) ([7f7de88](https://github.com/Automattic/newspack-blocks/commit/7f7de8847f254c4f55b875b6476ff0334fdeba11))
* modal checkout continue button ([#1733](https://github.com/Automattic/newspack-blocks/issues/1733)) ([c672d4a](https://github.com/Automattic/newspack-blocks/commit/c672d4a26547f7948799c86b1ab7569bf31c40f2))
* respect subscriptions limiter, and handle cart errors in modal ([#1728](https://github.com/Automattic/newspack-blocks/issues/1728)) ([8a828fa](https://github.com/Automattic/newspack-blocks/commit/8a828fae726757c748ff97562c1a4fb6e8536e0c))


### Features

* **donate:** add new "Modern" style variation ([#1734](https://github.com/Automattic/newspack-blocks/issues/1734)) ([b83d840](https://github.com/Automattic/newspack-blocks/commit/b83d8400957c179c2c228bc8c1ea87deb047c2aa))
* update donations patterns and add new one ([#1731](https://github.com/Automattic/newspack-blocks/issues/1731)) ([607c93a](https://github.com/Automattic/newspack-blocks/commit/607c93ad5a207615eb79e9ff4871b2542caaed0b))

# [3.1.0](https://github.com/Automattic/newspack-blocks/compare/v3.0.11...v3.1.0) (2024-05-15)


### Bug Fixes

* **homepage-articles:** author URL ([#1710](https://github.com/Automattic/newspack-blocks/issues/1710)) ([2f80876](https://github.com/Automattic/newspack-blocks/commit/2f80876dbdf1a380789b5c8110fca7f28ef3685f))
* **iframe-block:** prevent fatal error on blocks without attributes ([#1708](https://github.com/Automattic/newspack-blocks/issues/1708)) ([4077ee8](https://github.com/Automattic/newspack-blocks/commit/4077ee8d83716cfb66448efa51f4e9904bbc034a))
* make sure font scale 5 previews in editor ([#1706](https://github.com/Automattic/newspack-blocks/issues/1706)) ([43bda7c](https://github.com/Automattic/newspack-blocks/commit/43bda7c42e8bd683cbd66f6de6930d4cd6ee9fb9))


### Features

* homepage articles update loading animation ([#1709](https://github.com/Automattic/newspack-blocks/issues/1709)) ([d2ca12e](https://github.com/Automattic/newspack-blocks/commit/d2ca12e366755a129b57e6ee6739f6db37f3691b))

# [3.1.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.10...v3.1.0-alpha.1) (2024-04-25)


### Bug Fixes

* **homepage-articles:** author URL ([#1710](https://github.com/Automattic/newspack-blocks/issues/1710)) ([2f80876](https://github.com/Automattic/newspack-blocks/commit/2f80876dbdf1a380789b5c8110fca7f28ef3685f))
* **iframe-block:** prevent fatal error on blocks without attributes ([#1708](https://github.com/Automattic/newspack-blocks/issues/1708)) ([4077ee8](https://github.com/Automattic/newspack-blocks/commit/4077ee8d83716cfb66448efa51f4e9904bbc034a))
* make sure font scale 5 previews in editor ([#1706](https://github.com/Automattic/newspack-blocks/issues/1706)) ([43bda7c](https://github.com/Automattic/newspack-blocks/commit/43bda7c42e8bd683cbd66f6de6930d4cd6ee9fb9))


### Features

* homepage articles update loading animation ([#1709](https://github.com/Automattic/newspack-blocks/issues/1709)) ([d2ca12e](https://github.com/Automattic/newspack-blocks/commit/d2ca12e366755a129b57e6ee6739f6db37f3691b))

## [3.0.10](https://github.com/Automattic/newspack-blocks/compare/v3.0.9...v3.0.10) (2024-04-24)


### Bug Fixes

* make sure image scale CSS class is applied in editor ([#1717](https://github.com/Automattic/newspack-blocks/issues/1717)) ([1f5c3e2](https://github.com/Automattic/newspack-blocks/commit/1f5c3e263a3aae1149dd200e88399595b75379fe))

## [3.0.9](https://github.com/Automattic/newspack-blocks/compare/v3.0.8...v3.0.9) (2024-04-24)


### Bug Fixes

* assorted hotfixes ([eb9b6e5](https://github.com/Automattic/newspack-blocks/commit/eb9b6e5f13ecd055113baa2c53620775f930b2da))

## [3.0.8](https://github.com/Automattic/newspack-blocks/compare/v3.0.7...v3.0.8) (2024-04-19)


### Bug Fixes

* fix wrapping issue happening in Firefox ([#1712](https://github.com/Automattic/newspack-blocks/issues/1712)) ([82c676e](https://github.com/Automattic/newspack-blocks/commit/82c676e62f6832d5ca38c8b149377c45701ffb86))

## [3.0.7](https://github.com/Automattic/newspack-blocks/compare/v3.0.6...v3.0.7) (2024-04-08)


### Bug Fixes

* add encoding to all attributes in rest URL ([#1690](https://github.com/Automattic/newspack-blocks/issues/1690)) ([e0f74d7](https://github.com/Automattic/newspack-blocks/commit/e0f74d7a6318a34d21a05b16937975f1b61f0664))

## [3.0.7-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.6...v3.0.7-alpha.1) (2024-03-28)


### Bug Fixes

* add encoding to all attributes in rest URL ([#1690](https://github.com/Automattic/newspack-blocks/issues/1690)) ([e0f74d7](https://github.com/Automattic/newspack-blocks/commit/e0f74d7a6318a34d21a05b16937975f1b61f0664))

## [3.0.6](https://github.com/Automattic/newspack-blocks/compare/v3.0.5...v3.0.6) (2024-03-28)


### Bug Fixes

* add encoding to all attributes in rest URL ([#1690](https://github.com/Automattic/newspack-blocks/issues/1690)) ([e0f74d7](https://github.com/Automattic/newspack-blocks/commit/e0f74d7a6318a34d21a05b16937975f1b61f0664))
* translate formatted date for hpp block ([#1697](https://github.com/Automattic/newspack-blocks/issues/1697)) ([5cbb2fd](https://github.com/Automattic/newspack-blocks/commit/5cbb2fd75866818e11f42966341156b48d347d09))

## [3.0.5](https://github.com/Automattic/newspack-blocks/compare/v3.0.4...v3.0.5) (2024-03-25)


### Bug Fixes

* **author-profile:** only display guest author profile when linked ([#1688](https://github.com/Automattic/newspack-blocks/issues/1688)) ([ee1bb21](https://github.com/Automattic/newspack-blocks/commit/ee1bb2138ad1d8705746585916c371d3b3243089))
* correct carousel button styles in editor ([#1680](https://github.com/Automattic/newspack-blocks/issues/1680)) ([d4aba73](https://github.com/Automattic/newspack-blocks/commit/d4aba73ee6a65115122d35b1587afbfc28d08fcd))
* **modal-checkout:** ensure customer location when state is required but country is not ([#1683](https://github.com/Automattic/newspack-blocks/issues/1683)) ([27badf8](https://github.com/Automattic/newspack-blocks/commit/27badf8690dfcc84819bd495a1e0a947756b7739))

## [3.0.5-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.4...v3.0.5-alpha.1) (2024-03-07)


### Bug Fixes

* **author-profile:** only display guest author profile when linked ([#1688](https://github.com/Automattic/newspack-blocks/issues/1688)) ([ee1bb21](https://github.com/Automattic/newspack-blocks/commit/ee1bb2138ad1d8705746585916c371d3b3243089))
* correct carousel button styles in editor ([#1680](https://github.com/Automattic/newspack-blocks/issues/1680)) ([d4aba73](https://github.com/Automattic/newspack-blocks/commit/d4aba73ee6a65115122d35b1587afbfc28d08fcd))
* **modal-checkout:** ensure customer location when state is required but country is not ([#1683](https://github.com/Automattic/newspack-blocks/issues/1683)) ([27badf8](https://github.com/Automattic/newspack-blocks/commit/27badf8690dfcc84819bd495a1e0a947756b7739))

## [3.0.4-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.3...v3.0.4-alpha.1) (2024-03-07)

### Bug Fixes

* correct carousel button styles in editor ([#1680](https://github.com/Automattic/newspack-blocks/issues/1680)) ([d4aba73](https://github.com/Automattic/newspack-blocks/commit/d4aba73ee6a65115122d35b1587afbfc28d08fcd))
* **modal-checkout:** ensure customer location when state is required but country is not ([#1683](https://github.com/Automattic/newspack-blocks/issues/1683)) ([27badf8](https://github.com/Automattic/newspack-blocks/commit/27badf8690dfcc84819bd495a1e0a947756b7739))


## [3.0.3](https://github.com/Automattic/newspack-blocks/compare/v3.0.2...v3.0.3) (2024-03-04)


### Bug Fixes

* **author-blocks:** handle HTML in author bio ([#1672](https://github.com/Automattic/newspack-blocks/issues/1672)) ([4b6c3c4](https://github.com/Automattic/newspack-blocks/commit/4b6c3c4e7a1480e7a68f2737d55fa25389034afa))

## [3.0.3-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.2...v3.0.3-alpha.1) (2024-02-29)


### Bug Fixes

* **author-blocks:** handle HTML in author bio ([#1672](https://github.com/Automattic/newspack-blocks/issues/1672)) ([4b6c3c4](https://github.com/Automattic/newspack-blocks/commit/4b6c3c4e7a1480e7a68f2737d55fa25389034afa))

## [3.0.2-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.1...v3.0.2-alpha.1) (2024-02-23)


### Bug Fixes

* **author-blocks:** handle HTML in author bio ([#1672](https://github.com/Automattic/newspack-blocks/issues/1672)) ([4b6c3c4](https://github.com/Automattic/newspack-blocks/commit/4b6c3c4e7a1480e7a68f2737d55fa25389034afa))

## [3.0.2](https://github.com/Automattic/newspack-blocks/compare/v3.0.1...v3.0.2) (2024-02-29)


### Bug Fixes

* donate block layout for NRH ([#1682](https://github.com/Automattic/newspack-blocks/issues/1682)) ([230889e](https://github.com/Automattic/newspack-blocks/commit/230889ed5e42389ffc989d5b3b234494e230d7d0))


## [3.0.1](https://github.com/Automattic/newspack-blocks/compare/v3.0.0...v3.0.1) (2024-02-20)


### Bug Fixes

* handle inactive woocommerce ([36a61a2](https://github.com/Automattic/newspack-blocks/commit/36a61a2384002ded40d7ec75f5c9c3f2854a4418))
* **hpp:** filter invalid autocomplete tokens ([#1671](https://github.com/Automattic/newspack-blocks/issues/1671)) ([b1f9f39](https://github.com/Automattic/newspack-blocks/commit/b1f9f3996aadadf3afd093fac5157e7a04708f3c))

# [3.0.0](https://github.com/Automattic/newspack-blocks/compare/v2.6.2...v3.0.0) (2024-02-20)


### Bug Fixes

* **homepage-articles:** use map_deep to construct articles_rest_url and resolve PHP 8.1 warnings ([#1655](https://github.com/Automattic/newspack-blocks/issues/1655)) ([24085d8](https://github.com/Automattic/newspack-blocks/commit/24085d82c6eacf5b31956d3e07303badeaccbe6d))
* prevent error in modal-checkout check ([7e2a6c7](https://github.com/Automattic/newspack-blocks/commit/7e2a6c75a28b5a1a6316e45151dbe51c5ae8f72b))


### Features

* **ci:** add `epic/*` release workflow and rename `master` to `trunk` ([#1656](https://github.com/Automattic/newspack-blocks/issues/1656)) ([c788e55](https://github.com/Automattic/newspack-blocks/commit/c788e55d58cd7c72310f65d83f14f9e0960c6871))
* deprecate streamlined (Stripe) Donate block version ([#1638](https://github.com/Automattic/newspack-blocks/issues/1638)) ([11bd0d6](https://github.com/Automattic/newspack-blocks/commit/11bd0d620d882a72f631dfe08f808ddde3308665))
* **homepage-posts:** add custom taxonomy exclusions filter ([#1641](https://github.com/Automattic/newspack-blocks/issues/1641)) ([b140a99](https://github.com/Automattic/newspack-blocks/commit/b140a99c86cd1a3e825bb0a585225a95652ef331))
* **reader-revenue:** make NYP and Stripe Gateway optional ([#1645](https://github.com/Automattic/newspack-blocks/issues/1645)) ([1322d7c](https://github.com/Automattic/newspack-blocks/commit/1322d7c4c9c1bab175a2c48ab2d359555d459eea))


### BREAKING CHANGES

* streamlined (Stripe) Donate block version is no more

# [3.0.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.6.2...v3.0.0-alpha.1) (2024-02-08)


### Bug Fixes

* **homepage-articles:** use map_deep to construct articles_rest_url and resolve PHP 8.1 warnings ([#1655](https://github.com/Automattic/newspack-blocks/issues/1655)) ([24085d8](https://github.com/Automattic/newspack-blocks/commit/24085d82c6eacf5b31956d3e07303badeaccbe6d))
* prevent error in modal-checkout check ([7e2a6c7](https://github.com/Automattic/newspack-blocks/commit/7e2a6c75a28b5a1a6316e45151dbe51c5ae8f72b))


### Features

* **ci:** add `epic/*` release workflow and rename `master` to `trunk` ([#1656](https://github.com/Automattic/newspack-blocks/issues/1656)) ([c788e55](https://github.com/Automattic/newspack-blocks/commit/c788e55d58cd7c72310f65d83f14f9e0960c6871))
* deprecate streamlined (Stripe) Donate block version ([#1638](https://github.com/Automattic/newspack-blocks/issues/1638)) ([11bd0d6](https://github.com/Automattic/newspack-blocks/commit/11bd0d620d882a72f631dfe08f808ddde3308665))
* **homepage-posts:** add custom taxonomy exclusions filter ([#1641](https://github.com/Automattic/newspack-blocks/issues/1641)) ([b140a99](https://github.com/Automattic/newspack-blocks/commit/b140a99c86cd1a3e825bb0a585225a95652ef331))
* **reader-revenue:** make NYP and Stripe Gateway optional ([#1645](https://github.com/Automattic/newspack-blocks/issues/1645)) ([1322d7c](https://github.com/Automattic/newspack-blocks/commit/1322d7c4c9c1bab175a2c48ab2d359555d459eea))


### BREAKING CHANGES

* streamlined (Stripe) Donate block version is no more

## [2.6.2](https://github.com/Automattic/newspack-blocks/compare/v2.6.1...v2.6.2) (2024-02-08)


### Bug Fixes

* properly handle states when Country field is disabled ([#1667](https://github.com/Automattic/newspack-blocks/issues/1667)) ([eecce3b](https://github.com/Automattic/newspack-blocks/commit/eecce3bca1f1ace6da63bb4aba83425f3adb4586))

## [2.6.1](https://github.com/Automattic/newspack-blocks/compare/v2.6.0...v2.6.1) (2024-01-31)


### Bug Fixes

* **modal-checkout:** treat express checkouts as modal checkouts ([#1654](https://github.com/Automattic/newspack-blocks/issues/1654)) ([a352511](https://github.com/Automattic/newspack-blocks/commit/a3525115aab8f0c6de8038de6e94c1705c7afe9e))

# [2.6.0](https://github.com/Automattic/newspack-blocks/compare/v2.5.0...v2.6.0) (2024-01-25)


### Features

* add filter when building WP_Query arguments ([#1412](https://github.com/Automattic/newspack-blocks/issues/1412)) ([f960bd6](https://github.com/Automattic/newspack-blocks/commit/f960bd6d5b9582cc4fcdeea11cae3b7123f976ce))

# [2.6.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.5.0...v2.6.0-alpha.1) (2024-01-11)


### Features

* add filter when building WP_Query arguments ([#1412](https://github.com/Automattic/newspack-blocks/issues/1412)) ([f960bd6](https://github.com/Automattic/newspack-blocks/commit/f960bd6d5b9582cc4fcdeea11cae3b7123f976ce))

# [2.5.0](https://github.com/Automattic/newspack-blocks/compare/v2.4.0...v2.5.0) (2024-01-08)


### Bug Fixes

* avoid duplicity with linked guest authors ([#1632](https://github.com/Automattic/newspack-blocks/issues/1632)) ([608979c](https://github.com/Automattic/newspack-blocks/commit/608979c1e9cb63a7098da27c69c337ec233b7429))
* **modal-checkout:** show order details table with fees ([#1633](https://github.com/Automattic/newspack-blocks/issues/1633)) ([07c0642](https://github.com/Automattic/newspack-blocks/commit/07c0642e77a075750c6f436f12af99cd3e2ef360))


### Features

* accessibility improvements to the donate block tabs ([#1622](https://github.com/Automattic/newspack-blocks/issues/1622)) ([115e9fb](https://github.com/Automattic/newspack-blocks/commit/115e9fb95c78a13f1d87f4d086e767311dc7007d))
* **donate:** support empty value for "other" tier ([#1604](https://github.com/Automattic/newspack-blocks/issues/1604)) ([61ffdbc](https://github.com/Automattic/newspack-blocks/commit/61ffdbc57e6fda320766d4de99f097edac9e58f7))
* **modal-checkout:** allow anonymous purchase for registered email ([#1615](https://github.com/Automattic/newspack-blocks/issues/1615)) ([a0040b4](https://github.com/Automattic/newspack-blocks/commit/a0040b43a3f97c889e5bb5b5b96f07777b52a670))

# [2.5.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v2.5.0-alpha.1...v2.5.0-alpha.2) (2023-12-22)


### Bug Fixes

* avoid duplicity with linked guest authors ([#1632](https://github.com/Automattic/newspack-blocks/issues/1632)) ([608979c](https://github.com/Automattic/newspack-blocks/commit/608979c1e9cb63a7098da27c69c337ec233b7429))
* **modal-checkout:** show order details table with fees ([#1633](https://github.com/Automattic/newspack-blocks/issues/1633)) ([07c0642](https://github.com/Automattic/newspack-blocks/commit/07c0642e77a075750c6f436f12af99cd3e2ef360))


### Features

* **modal-checkout:** allow anonymous purchase for registered email ([#1615](https://github.com/Automattic/newspack-blocks/issues/1615)) ([a0040b4](https://github.com/Automattic/newspack-blocks/commit/a0040b43a3f97c889e5bb5b5b96f07777b52a670))

# [2.5.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.4.0...v2.5.0-alpha.1) (2023-12-15)


### Features

* accessibility improvements to the donate block tabs ([#1622](https://github.com/Automattic/newspack-blocks/issues/1622)) ([115e9fb](https://github.com/Automattic/newspack-blocks/commit/115e9fb95c78a13f1d87f4d086e767311dc7007d))
* **donate:** support empty value for "other" tier ([#1604](https://github.com/Automattic/newspack-blocks/issues/1604)) ([61ffdbc](https://github.com/Automattic/newspack-blocks/commit/61ffdbc57e6fda320766d4de99f097edac9e58f7))

# [2.4.0](https://github.com/Automattic/newspack-blocks/compare/v2.3.0...v2.4.0) (2023-12-11)


### Bug Fixes

* modal checkout template markup ([#1608](https://github.com/Automattic/newspack-blocks/issues/1608)) ([4d593db](https://github.com/Automattic/newspack-blocks/commit/4d593db4e80b57570e13db2ed203586bab974fb6))
* **modal-checkout:** align Stripe's "save payment" checkbox ([#1623](https://github.com/Automattic/newspack-blocks/issues/1623)) ([69e0e42](https://github.com/Automattic/newspack-blocks/commit/69e0e42b3d6f6ab8b1a918346e0d2f8e90eeabcb))
* **modal-checkout:** prevent initial render of details table ([#1601](https://github.com/Automattic/newspack-blocks/issues/1601)) ([06d4ccd](https://github.com/Automattic/newspack-blocks/commit/06d4ccdc799cb4233a1a85efdbd1264399d85291))
* replace FILTER_SANITIZE_STRING ([6f805b0](https://github.com/Automattic/newspack-blocks/commit/6f805b0806d24f9e44d293a13ca486fdf23c73ab))


### Features

* improve performance of modal checkout ([#1607](https://github.com/Automattic/newspack-blocks/issues/1607)) ([a48d190](https://github.com/Automattic/newspack-blocks/commit/a48d19068cc0fc22d36f6371bdee447f72177ed7))
* **modal-checkout:** add filter to cart item data ([#1590](https://github.com/Automattic/newspack-blocks/issues/1590)) ([1e83dc1](https://github.com/Automattic/newspack-blocks/commit/1e83dc119aac531db18dbdcc7aaa4c781aa8576e))


### Performance Improvements

* **modal-checkout:** process checkout request earlier ([#1612](https://github.com/Automattic/newspack-blocks/issues/1612)) ([5c58f5e](https://github.com/Automattic/newspack-blocks/commit/5c58f5e811bb24098afb093c3a4efbb8efa83d4a))

# [2.4.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.3.0...v2.4.0-alpha.1) (2023-12-07)


### Bug Fixes

* modal checkout template markup ([#1608](https://github.com/Automattic/newspack-blocks/issues/1608)) ([4d593db](https://github.com/Automattic/newspack-blocks/commit/4d593db4e80b57570e13db2ed203586bab974fb6))
* **modal-checkout:** align Stripe's "save payment" checkbox ([#1623](https://github.com/Automattic/newspack-blocks/issues/1623)) ([69e0e42](https://github.com/Automattic/newspack-blocks/commit/69e0e42b3d6f6ab8b1a918346e0d2f8e90eeabcb))
* **modal-checkout:** prevent initial render of details table ([#1601](https://github.com/Automattic/newspack-blocks/issues/1601)) ([06d4ccd](https://github.com/Automattic/newspack-blocks/commit/06d4ccdc799cb4233a1a85efdbd1264399d85291))
* replace FILTER_SANITIZE_STRING ([6f805b0](https://github.com/Automattic/newspack-blocks/commit/6f805b0806d24f9e44d293a13ca486fdf23c73ab))


### Features

* improve performance of modal checkout ([#1607](https://github.com/Automattic/newspack-blocks/issues/1607)) ([a48d190](https://github.com/Automattic/newspack-blocks/commit/a48d19068cc0fc22d36f6371bdee447f72177ed7))
* **modal-checkout:** add filter to cart item data ([#1590](https://github.com/Automattic/newspack-blocks/issues/1590)) ([1e83dc1](https://github.com/Automattic/newspack-blocks/commit/1e83dc119aac531db18dbdcc7aaa4c781aa8576e))


### Performance Improvements

* **modal-checkout:** process checkout request earlier ([#1612](https://github.com/Automattic/newspack-blocks/issues/1612)) ([5c58f5e](https://github.com/Automattic/newspack-blocks/commit/5c58f5e811bb24098afb093c3a4efbb8efa83d4a))

# [2.3.0](https://github.com/Automattic/newspack-blocks/compare/v2.2.4...v2.3.0) (2023-12-07)


### Features

* update thank you translation strings in modal checkout ([#1625](https://github.com/Automattic/newspack-blocks/issues/1625)) ([b571c29](https://github.com/Automattic/newspack-blocks/commit/b571c29a00f2d05f88cee75916984372f8fa9b8e))

## [2.2.4](https://github.com/Automattic/newspack-blocks/compare/v2.2.3...v2.2.4) (2023-12-06)


### Bug Fixes

* **homepage-posts:** handle empty block when printing inline styles ([#1621](https://github.com/Automattic/newspack-blocks/issues/1621)) ([c3aa957](https://github.com/Automattic/newspack-blocks/commit/c3aa9577c2284f2139c8fa1170785db9fe4edfe9))

## [2.2.3](https://github.com/Automattic/newspack-blocks/compare/v2.2.2...v2.2.3) (2023-12-04)


### Bug Fixes

* update selected tier behavior for donate block ([#1617](https://github.com/Automattic/newspack-blocks/issues/1617)) ([8a9a76f](https://github.com/Automattic/newspack-blocks/commit/8a9a76fb970ffac6f00f67f83a17a931a169c103))

## [2.2.2](https://github.com/Automattic/newspack-blocks/compare/v2.2.1...v2.2.2) (2023-11-29)


### Bug Fixes

* update title, subtitle CSS selectors in homepage posts block ([#1614](https://github.com/Automattic/newspack-blocks/issues/1614)) ([0ccb973](https://github.com/Automattic/newspack-blocks/commit/0ccb9730c96a598b873fca6ff15147e2fe053930))

## [2.2.1](https://github.com/Automattic/newspack-blocks/compare/v2.2.0...v2.2.1) (2023-11-27)


### Bug Fixes

* **homepage-posts:** placement for inline styles ([#1611](https://github.com/Automattic/newspack-blocks/issues/1611)) ([1831ba0](https://github.com/Automattic/newspack-blocks/commit/1831ba0a2d0eb44a00e65a891c9fdf11bdd99f0a))

# [2.2.0](https://github.com/Automattic/newspack-blocks/compare/v2.1.0...v2.2.0) (2023-11-27)


### Bug Fixes

* add checks for Newspack plugin ([#1579](https://github.com/Automattic/newspack-blocks/issues/1579)) ([b516a6f](https://github.com/Automattic/newspack-blocks/commit/b516a6f2a5111361f6180c98e09814c95f5e9861))
* **homepage-posts:** terms handling in category list ([#1596](https://github.com/Automattic/newspack-blocks/issues/1596)) ([55dbf42](https://github.com/Automattic/newspack-blocks/commit/55dbf42c969d33029ea6531cf6f5db1e5b4953a5))
* **modal-checkout:** place order button width ([b48efcb](https://github.com/Automattic/newspack-blocks/commit/b48efcb6391d7282322f63f9f8aeeb860bff2cf8)), closes [#1572](https://github.com/Automattic/newspack-blocks/issues/1572)


### Features

* add width control to the Checkout block ([#1583](https://github.com/Automattic/newspack-blocks/issues/1583)) ([3f75c1e](https://github.com/Automattic/newspack-blocks/commit/3f75c1e074554ead923f7007e9d026811cb186dd))
* **cwv:** inline HPB styles in a style tag ([#1548](https://github.com/Automattic/newspack-blocks/issues/1548)) ([dea8d93](https://github.com/Automattic/newspack-blocks/commit/dea8d93fb518fc815d00bbd3e926f052b1638250))
* **modal-checkout:** display item name on success ([a3a03df](https://github.com/Automattic/newspack-blocks/commit/a3a03df36a5bb18556d3c643a119e8401e9123fd))
* update subscribe patterns to use Newspack block ([#1580](https://github.com/Automattic/newspack-blocks/issues/1580)) ([70088e2](https://github.com/Automattic/newspack-blocks/commit/70088e2628e9045715f0b6df925b39290ecf00ed))

# [2.2.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.1.0...v2.2.0-alpha.1) (2023-11-16)


### Bug Fixes

* add checks for Newspack plugin ([#1579](https://github.com/Automattic/newspack-blocks/issues/1579)) ([b516a6f](https://github.com/Automattic/newspack-blocks/commit/b516a6f2a5111361f6180c98e09814c95f5e9861))
* **homepage-posts:** terms handling in category list ([#1596](https://github.com/Automattic/newspack-blocks/issues/1596)) ([55dbf42](https://github.com/Automattic/newspack-blocks/commit/55dbf42c969d33029ea6531cf6f5db1e5b4953a5))
* **modal-checkout:** place order button width ([b48efcb](https://github.com/Automattic/newspack-blocks/commit/b48efcb6391d7282322f63f9f8aeeb860bff2cf8)), closes [#1572](https://github.com/Automattic/newspack-blocks/issues/1572)


### Features

* add width control to the Checkout block ([#1583](https://github.com/Automattic/newspack-blocks/issues/1583)) ([3f75c1e](https://github.com/Automattic/newspack-blocks/commit/3f75c1e074554ead923f7007e9d026811cb186dd))
* **cwv:** inline HPB styles in a style tag ([#1548](https://github.com/Automattic/newspack-blocks/issues/1548)) ([dea8d93](https://github.com/Automattic/newspack-blocks/commit/dea8d93fb518fc815d00bbd3e926f052b1638250))
* **modal-checkout:** display item name on success ([a3a03df](https://github.com/Automattic/newspack-blocks/commit/a3a03df36a5bb18556d3c643a119e8401e9123fd))
* update subscribe patterns to use Newspack block ([#1580](https://github.com/Automattic/newspack-blocks/issues/1580)) ([70088e2](https://github.com/Automattic/newspack-blocks/commit/70088e2628e9045715f0b6df925b39290ecf00ed))

# [2.1.0](https://github.com/Automattic/newspack-blocks/compare/v2.0.0...v2.1.0) (2023-11-13)


### Bug Fixes

* **homepage-posts:** change copy for deduplication block option ([#1578](https://github.com/Automattic/newspack-blocks/issues/1578)) ([dff89a0](https://github.com/Automattic/newspack-blocks/commit/dff89a040e45109eaa0ae04248cb41f2d6543aca))
* **modal-checkout:** add id attributes to hidden inputs ([90f077c](https://github.com/Automattic/newspack-blocks/commit/90f077ce49c164014e7779140e207a1278afda3e))
* **modal-checkout:** place order button width ([9b1b04e](https://github.com/Automattic/newspack-blocks/commit/9b1b04e6c5b5ec7931c69eef859b93397735291f)), closes [#1572](https://github.com/Automattic/newspack-blocks/issues/1572) [#1586](https://github.com/Automattic/newspack-blocks/issues/1586)


### Features

* **donate-block:** changes to button-after-success  ([#1571](https://github.com/Automattic/newspack-blocks/issues/1571)) ([ac1c6a6](https://github.com/Automattic/newspack-blocks/commit/ac1c6a63f028f6cafa1e5cd852520ebf6ce75845))
* expose is_modal_checkout method ([4315730](https://github.com/Automattic/newspack-blocks/commit/43157303d800e1447088c40f283d670c9fc6682e))
* **modal-checkout:** dequeue WC styles ([#1572](https://github.com/Automattic/newspack-blocks/issues/1572)) ([9aa0039](https://github.com/Automattic/newspack-blocks/commit/9aa003954bb5676d009f0ef60765a82c9ec811d9))
* **modal-checkout:** handle non-redirect-based flow (e.g. Apple Pay) ([#1573](https://github.com/Automattic/newspack-blocks/issues/1573)) ([671b551](https://github.com/Automattic/newspack-blocks/commit/671b551bfedd4724fc85005e21387b1ef462c97c))
* **modal-checkout:** update "place order" text to "donate now" ([#1591](https://github.com/Automattic/newspack-blocks/issues/1591)) ([63fc202](https://github.com/Automattic/newspack-blocks/commit/63fc20204b95d9213e99481edd1b4549f011c448))

# [2.1.0-alpha.4](https://github.com/Automattic/newspack-blocks/compare/v2.1.0-alpha.3...v2.1.0-alpha.4) (2023-11-10)


### Features

* **modal-checkout:** update "place order" text to "donate now" ([#1591](https://github.com/Automattic/newspack-blocks/issues/1591)) ([63fc202](https://github.com/Automattic/newspack-blocks/commit/63fc20204b95d9213e99481edd1b4549f011c448))

# [2.1.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v2.1.0-alpha.2...v2.1.0-alpha.3) (2023-11-08)


### Bug Fixes

* **modal-checkout:** place order button width ([9b1b04e](https://github.com/Automattic/newspack-blocks/commit/9b1b04e6c5b5ec7931c69eef859b93397735291f)), closes [#1572](https://github.com/Automattic/newspack-blocks/issues/1572) [#1586](https://github.com/Automattic/newspack-blocks/issues/1586)

# [2.1.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v2.1.0-alpha.1...v2.1.0-alpha.2) (2023-11-03)


### Bug Fixes

* **homepage-posts:** change copy for deduplication block option ([#1578](https://github.com/Automattic/newspack-blocks/issues/1578)) ([dff89a0](https://github.com/Automattic/newspack-blocks/commit/dff89a040e45109eaa0ae04248cb41f2d6543aca))
* **modal-checkout:** add id attributes to hidden inputs ([90f077c](https://github.com/Automattic/newspack-blocks/commit/90f077ce49c164014e7779140e207a1278afda3e))


### Features

* expose is_modal_checkout method ([4315730](https://github.com/Automattic/newspack-blocks/commit/43157303d800e1447088c40f283d670c9fc6682e))
* **modal-checkout:** dequeue WC styles ([#1572](https://github.com/Automattic/newspack-blocks/issues/1572)) ([9aa0039](https://github.com/Automattic/newspack-blocks/commit/9aa003954bb5676d009f0ef60765a82c9ec811d9))
* **modal-checkout:** handle non-redirect-based flow (e.g. Apple Pay) ([#1573](https://github.com/Automattic/newspack-blocks/issues/1573)) ([671b551](https://github.com/Automattic/newspack-blocks/commit/671b551bfedd4724fc85005e21387b1ef462c97c))

# [2.1.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v2.0.0...v2.1.0-alpha.1) (2023-10-31)


### Features

* **donate-block:** changes to button-after-success  ([#1571](https://github.com/Automattic/newspack-blocks/issues/1571)) ([ac1c6a6](https://github.com/Automattic/newspack-blocks/commit/ac1c6a63f028f6cafa1e5cd852520ebf6ce75845))

# [2.0.0](https://github.com/Automattic/newspack-blocks/compare/v1.75.6...v2.0.0) (2023-10-31)


### Bug Fixes

* **donate:** allow "once" in tiers-based layout ([ad7268a](https://github.com/Automattic/newspack-blocks/commit/ad7268a320b70a85189cc36055744c3b6aca712f))
* **homepage-posts:** check existing "specific posts" recursively for deduplication ([#1541](https://github.com/Automattic/newspack-blocks/issues/1541)) ([9755e40](https://github.com/Automattic/newspack-blocks/commit/9755e4090ce645c56ff26183684a07503c92fc65))
* newspack.pub > newspack.com ([#1552](https://github.com/Automattic/newspack-blocks/issues/1552)) ([343b80b](https://github.com/Automattic/newspack-blocks/commit/343b80b49ac5516cb8ca877b1ca4a6ce6bcaaedf))
* update WC's variation methods ([860f9fa](https://github.com/Automattic/newspack-blocks/commit/860f9fa2c8b5cda48c9310c958abcf8a3567d5ad))


### Features

* add option for a button after checkout ([#1521](https://github.com/Automattic/newspack-blocks/issues/1521)) ([bdfb3c2](https://github.com/Automattic/newspack-blocks/commit/bdfb3c2cceeb52097494884935546655a4a1650e))
* **homepage-posts:** support deduplication toggling ([#1543](https://github.com/Automattic/newspack-blocks/issues/1543)) ([6acc719](https://github.com/Automattic/newspack-blocks/commit/6acc71959a28d213e1f9064a28a84ebeda44f636))
* **modal checkout:** post-checkout newsletter signup ([#1561](https://github.com/Automattic/newspack-blocks/issues/1561)) ([092007a](https://github.com/Automattic/newspack-blocks/commit/092007a59ed89ce8ea3ef309851c26fde88b7e9f))
* **modal-checkout:** implement ras overlay ([#1562](https://github.com/Automattic/newspack-blocks/issues/1562)) ([8270c9b](https://github.com/Automattic/newspack-blocks/commit/8270c9be4dc513ec317c0c256063cf12c936a0a7))
* remove AMP compatibility ([27ecf88](https://github.com/Automattic/newspack-blocks/commit/27ecf88abecaa935ec931df397c340cba19de978))


### BREAKING CHANGES

* AMP will not be supported anymore by this plugin.

# [2.0.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v2.0.0-alpha.2...v2.0.0-alpha.3) (2023-10-20)


### Bug Fixes

* update WC's variation methods ([860f9fa](https://github.com/Automattic/newspack-blocks/commit/860f9fa2c8b5cda48c9310c958abcf8a3567d5ad))


### Features

* **modal checkout:** post-checkout newsletter signup ([#1561](https://github.com/Automattic/newspack-blocks/issues/1561)) ([092007a](https://github.com/Automattic/newspack-blocks/commit/092007a59ed89ce8ea3ef309851c26fde88b7e9f))
* **modal-checkout:** implement ras overlay ([#1562](https://github.com/Automattic/newspack-blocks/issues/1562)) ([8270c9b](https://github.com/Automattic/newspack-blocks/commit/8270c9be4dc513ec317c0c256063cf12c936a0a7))

# [2.0.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v2.0.0-alpha.1...v2.0.0-alpha.2) (2023-10-19)


### Bug Fixes

* hide WC's login prompt ([124fee7](https://github.com/Automattic/newspack-blocks/commit/124fee794e1e81490d95b822e132841e720bedf9))
* **modal-checkout:** remove login prompt from initial flow ([4a8d48f](https://github.com/Automattic/newspack-blocks/commit/4a8d48f05b5d15760c8cf7365ed50d90ae22c639)), closes [/github.com/Automattic/newspack-blocks/pull/1550#issuecomment-1755996086](https://github.com//github.com/Automattic/newspack-blocks/pull/1550/issues/issuecomment-1755996086)

## [1.75.6](https://github.com/Automattic/newspack-blocks/compare/v1.75.5...v1.75.6) (2023-10-19)


### Bug Fixes

* hide WC's login prompt ([124fee7](https://github.com/Automattic/newspack-blocks/commit/124fee794e1e81490d95b822e132841e720bedf9))
* **modal-checkout:** remove login prompt from initial flow ([4a8d48f](https://github.com/Automattic/newspack-blocks/commit/4a8d48f05b5d15760c8cf7365ed50d90ae22c639)), closes [/github.com/Automattic/newspack-blocks/pull/1550#issuecomment-1755996086](https://github.com//github.com/Automattic/newspack-blocks/pull/1550/issues/issuecomment-1755996086)

## [1.75.5](https://github.com/Automattic/newspack-blocks/compare/v1.75.4...v1.75.5) (2023-10-12)


### Bug Fixes

* always show email address for order summaries ([#1554](https://github.com/Automattic/newspack-blocks/issues/1554)) ([33952d1](https://github.com/Automattic/newspack-blocks/commit/33952d1dd8a879d26566b09c4cc3b8f132d54014))

## [1.75.4](https://github.com/Automattic/newspack-blocks/compare/v1.75.3...v1.75.4) (2023-10-10)


### Bug Fixes

* match thankyou template when using existing customer email ([#1550](https://github.com/Automattic/newspack-blocks/issues/1550)) ([4b0dbf2](https://github.com/Automattic/newspack-blocks/commit/4b0dbf29a98aa7b95b0cda30e4bd03c3ea31285b))

## [1.75.3](https://github.com/Automattic/newspack-blocks/compare/v1.75.2...v1.75.3) (2023-10-10)


### Bug Fixes

* **donate:** reset "other" value when switching tiers ([#1549](https://github.com/Automattic/newspack-blocks/issues/1549)) ([844505f](https://github.com/Automattic/newspack-blocks/commit/844505fdefb445e4d68b5a1df5b980d4eae21e0e))

## [1.75.2](https://github.com/Automattic/newspack-blocks/compare/v1.75.1...v1.75.2) (2023-10-04)


### Bug Fixes

* allow order details wrapper to render ([#1547](https://github.com/Automattic/newspack-blocks/issues/1547)) ([b76f196](https://github.com/Automattic/newspack-blocks/commit/b76f196dc4b73d881237c3c015d60db00ee49877))

## [1.75.1](https://github.com/Automattic/newspack-blocks/compare/v1.75.0...v1.75.1) (2023-09-28)


### Bug Fixes

* **checkout-button:** handle 10+ product variations ([#1536](https://github.com/Automattic/newspack-blocks/issues/1536)) ([5df5065](https://github.com/Automattic/newspack-blocks/commit/5df5065011d6779a689358844212628757b6b5b9))
* query for CAP terms ([#1535](https://github.com/Automattic/newspack-blocks/issues/1535)) ([49406b9](https://github.com/Automattic/newspack-blocks/commit/49406b9bf1075897939cad2e4c30f34efcf841da))

# [1.75.0](https://github.com/Automattic/newspack-blocks/compare/v1.74.2...v1.75.0) (2023-09-25)


### Features

* add category filter for the categories in the blocks ([#1528](https://github.com/Automattic/newspack-blocks/issues/1528)) ([ebbfce9](https://github.com/Automattic/newspack-blocks/commit/ebbfce99dd78521fe5624be81df43c3ba3f8c731))

# [1.75.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v1.75.0-alpha.2...v1.75.0-alpha.3) (2023-09-19)


### Bug Fixes

* undefined variable error ([#1534](https://github.com/Automattic/newspack-blocks/issues/1534)) ([b0ed7f9](https://github.com/Automattic/newspack-blocks/commit/b0ed7f911982f914678c466c07bc5f0659aaca86))

## [1.74.2](https://github.com/Automattic/newspack-blocks/compare/v1.74.1...v1.74.2) (2023-09-19)


### Bug Fixes

* undefined variable error ([#1534](https://github.com/Automattic/newspack-blocks/issues/1534)) ([b0ed7f9](https://github.com/Automattic/newspack-blocks/commit/b0ed7f911982f914678c466c07bc5f0659aaca86))

## [1.74.1](https://github.com/Automattic/newspack-blocks/compare/v1.74.0...v1.74.1) (2023-09-18)


### Bug Fixes

* harden usage and output of attribute values ([#1530](https://github.com/Automattic/newspack-blocks/issues/1530)) ([c157395](https://github.com/Automattic/newspack-blocks/commit/c15739539d73884b36284e9d1c2eb854202269f8))

# [1.74.0](https://github.com/Automattic/newspack-blocks/compare/v1.73.0...v1.74.0) (2023-08-24)


### Bug Fixes

* make sure carousels without images aren't collapsed ([#1511](https://github.com/Automattic/newspack-blocks/issues/1511)) ([ee8c1cd](https://github.com/Automattic/newspack-blocks/commit/ee8c1cd8f181b97d2795ec6c6aa69283cf4067a9))
* reduce post carousel font size for 5-6 slides ([#1510](https://github.com/Automattic/newspack-blocks/issues/1510)) ([daabad4](https://github.com/Automattic/newspack-blocks/commit/daabad4635925bb5e1a432102b65149f3b9ec71a))
* support account creation on modal checkout ([#1516](https://github.com/Automattic/newspack-blocks/issues/1516)) ([51ae19d](https://github.com/Automattic/newspack-blocks/commit/51ae19d30f7b60428ebc293189e18d8a4abf5b76))


### Features

* Merge pull request [#1509](https://github.com/Automattic/newspack-blocks/issues/1509) from Automattic/master ([1280d07](https://github.com/Automattic/newspack-blocks/commit/1280d073b7ff12af84c7dc9907a80918e778038a))

# [1.74.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.74.0-alpha.1...v1.74.0-alpha.2) (2023-08-24)


### Bug Fixes

* make sure carousels without images aren't collapsed ([#1511](https://github.com/Automattic/newspack-blocks/issues/1511)) ([ee8c1cd](https://github.com/Automattic/newspack-blocks/commit/ee8c1cd8f181b97d2795ec6c6aa69283cf4067a9))
* reduce post carousel font size for 5-6 slides ([#1510](https://github.com/Automattic/newspack-blocks/issues/1510)) ([daabad4](https://github.com/Automattic/newspack-blocks/commit/daabad4635925bb5e1a432102b65149f3b9ec71a))
* support account creation on modal checkout ([#1516](https://github.com/Automattic/newspack-blocks/issues/1516)) ([51ae19d](https://github.com/Automattic/newspack-blocks/commit/51ae19d30f7b60428ebc293189e18d8a4abf5b76))

# [1.74.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.73.0...v1.74.0-alpha.1) (2023-08-17)


### Features

* Merge pull request [#1509](https://github.com/Automattic/newspack-blocks/issues/1509) from Automattic/master ([1280d07](https://github.com/Automattic/newspack-blocks/commit/1280d073b7ff12af84c7dc9907a80918e778038a))

# [1.73.0](https://github.com/Automattic/newspack-blocks/compare/v1.72.2...v1.73.0) (2023-07-17)


### Features

* add updated translation files ([#1486](https://github.com/Automattic/newspack-blocks/issues/1486)) ([3a44410](https://github.com/Automattic/newspack-blocks/commit/3a44410e43fcc00510a317e527e2acf782dac4d1))
* allow Homepage Posts and Carousel blocks to include subcategories ([#1482](https://github.com/Automattic/newspack-blocks/issues/1482)) ([faa8734](https://github.com/Automattic/newspack-blocks/commit/faa87346869dce0503b269c23f453b08cdd5724f))
* **modal-checkout:** render order details under different conditions ([#1485](https://github.com/Automattic/newspack-blocks/issues/1485)) ([ae62734](https://github.com/Automattic/newspack-blocks/commit/ae62734ee8ca769b23d14a2cb5067fb87dea37e7))

# [1.73.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v1.73.0-alpha.2...v1.73.0-alpha.3) (2023-07-17)


### Bug Fixes

* only show Additional Fields panel when using Stripe platform ([#1499](https://github.com/Automattic/newspack-blocks/issues/1499)) ([1a08513](https://github.com/Automattic/newspack-blocks/commit/1a085131f881ac62d2ac4879298c3a47d86df684))

## [1.72.2](https://github.com/Automattic/newspack-blocks/compare/v1.72.1...v1.72.2) (2023-07-17)


### Bug Fixes

* only show Additional Fields panel when using Stripe platform ([#1499](https://github.com/Automattic/newspack-blocks/issues/1499)) ([1a08513](https://github.com/Automattic/newspack-blocks/commit/1a085131f881ac62d2ac4879298c3a47d86df684))

## [1.72.1](https://github.com/Automattic/newspack-blocks/compare/v1.72.0...v1.72.1) (2023-07-13)


### Bug Fixes

* **post-carousel:** set aspect ratio on init ([#1497](https://github.com/Automattic/newspack-blocks/issues/1497)) ([8a48982](https://github.com/Automattic/newspack-blocks/commit/8a489829dbe7882210b211cbde109d930d4e770b))

# [1.72.0](https://github.com/Automattic/newspack-blocks/compare/v1.71.0...v1.72.0) (2023-07-03)


### Bug Fixes

* **donate-block:** allow spaces in button label ([#1479](https://github.com/Automattic/newspack-blocks/issues/1479)) ([96adc82](https://github.com/Automattic/newspack-blocks/commit/96adc822cb92201298a53a05df2867d31a6a4efa))


### Features

* display purchase details in checkout modal when taxes are enabled ([#1480](https://github.com/Automattic/newspack-blocks/issues/1480)) ([b69f8f3](https://github.com/Automattic/newspack-blocks/commit/b69f8f3dbaabfa4648cab97131e8795c24c07af2))

# [1.72.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.71.0...v1.72.0-alpha.1) (2023-06-29)


### Bug Fixes

* **donate-block:** allow spaces in button label ([#1479](https://github.com/Automattic/newspack-blocks/issues/1479)) ([96adc82](https://github.com/Automattic/newspack-blocks/commit/96adc822cb92201298a53a05df2867d31a6a4efa))


### Features

* display purchase details in checkout modal when taxes are enabled ([#1480](https://github.com/Automattic/newspack-blocks/issues/1480)) ([b69f8f3](https://github.com/Automattic/newspack-blocks/commit/b69f8f3dbaabfa4648cab97131e8795c24c07af2))

# [1.71.0](https://github.com/Automattic/newspack-blocks/compare/v1.70.0...v1.71.0) (2023-06-29)


### Features

* **modal-checkout:** render order details under different conditions ([#1485](https://github.com/Automattic/newspack-blocks/issues/1485)) ([#1488](https://github.com/Automattic/newspack-blocks/issues/1488)) ([aa569ad](https://github.com/Automattic/newspack-blocks/commit/aa569ad470e66a93cbdd145ef80c6f96c9ae5457))

# [1.70.0](https://github.com/Automattic/newspack-blocks/compare/v1.69.0...v1.70.0) (2023-06-19)


### Bug Fixes

* **checkout-button:** overlay class for modal stacking ([#1461](https://github.com/Automattic/newspack-blocks/issues/1461)) ([594095b](https://github.com/Automattic/newspack-blocks/commit/594095b02f957ccb974c0c40ea9e76685ae32844))


### Features

* **checkout-button:** front-end product variation selection ([#1459](https://github.com/Automattic/newspack-blocks/issues/1459)) ([a978160](https://github.com/Automattic/newspack-blocks/commit/a978160c3e453b60bf9bcd92ad53c9b6c39fb23b))
* **modal-checkout:** always use donation billing fields ([#1463](https://github.com/Automattic/newspack-blocks/issues/1463)) ([ab176bf](https://github.com/Automattic/newspack-blocks/commit/ab176bfebf6c5a12c8bf1fde3ba52db4c1f9454d))

# [1.70.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.69.0...v1.70.0-alpha.1) (2023-06-08)


### Bug Fixes

* **checkout-button:** overlay class for modal stacking ([#1461](https://github.com/Automattic/newspack-blocks/issues/1461)) ([594095b](https://github.com/Automattic/newspack-blocks/commit/594095b02f957ccb974c0c40ea9e76685ae32844))


### Features

* **checkout-button:** front-end product variation selection ([#1459](https://github.com/Automattic/newspack-blocks/issues/1459)) ([a978160](https://github.com/Automattic/newspack-blocks/commit/a978160c3e453b60bf9bcd92ad53c9b6c39fb23b))
* **modal-checkout:** always use donation billing fields ([#1463](https://github.com/Automattic/newspack-blocks/issues/1463)) ([ab176bf](https://github.com/Automattic/newspack-blocks/commit/ab176bfebf6c5a12c8bf1fde3ba52db4c1f9454d))

# [1.69.0](https://github.com/Automattic/newspack-blocks/compare/v1.68.2...v1.69.0) (2023-06-05)


### Bug Fixes

* **checkout-button:** overlay class for modal stacking ([#1461](https://github.com/Automattic/newspack-blocks/issues/1461)) ([21b6a44](https://github.com/Automattic/newspack-blocks/commit/21b6a4465d6182ad64f36d065cf7c35241dd5d5e))


### Features

* adding link to the author bio avatar ([#1449](https://github.com/Automattic/newspack-blocks/issues/1449)) ([60d9f1c](https://github.com/Automattic/newspack-blocks/commit/60d9f1c61306b9c76be3f487d56f95a04a4565f0))
* **checkout-button:** front-end product variation selection ([#1459](https://github.com/Automattic/newspack-blocks/issues/1459)) ([d492a9a](https://github.com/Automattic/newspack-blocks/commit/d492a9ade4ef5030fbda6e2b156e3d823813383d))
* **checkout-button:** support product variations ([#1442](https://github.com/Automattic/newspack-blocks/issues/1442)) ([aa70495](https://github.com/Automattic/newspack-blocks/commit/aa70495940a6c85e9eb9747eb91a989df97e9921))
* **modal-checkout:** always use donation billing fields ([#1463](https://github.com/Automattic/newspack-blocks/issues/1463)) ([de674c7](https://github.com/Automattic/newspack-blocks/commit/de674c7732f2dccd4e508efb6fabde0816a01fb7))

# [1.69.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v1.69.0-alpha.2...v1.69.0-alpha.3) (2023-06-01)


### Bug Fixes

* **checkout-button:** overlay class for modal stacking ([#1461](https://github.com/Automattic/newspack-blocks/issues/1461)) ([21b6a44](https://github.com/Automattic/newspack-blocks/commit/21b6a4465d6182ad64f36d065cf7c35241dd5d5e))

# [1.69.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.69.0-alpha.1...v1.69.0-alpha.2) (2023-05-31)


### Features

* **checkout-button:** front-end product variation selection ([#1459](https://github.com/Automattic/newspack-blocks/issues/1459)) ([d492a9a](https://github.com/Automattic/newspack-blocks/commit/d492a9ade4ef5030fbda6e2b156e3d823813383d))

# [1.69.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.68.2...v1.69.0-alpha.1) (2023-05-25)


### Features

* adding link to the author bio avatar ([#1449](https://github.com/Automattic/newspack-blocks/issues/1449)) ([60d9f1c](https://github.com/Automattic/newspack-blocks/commit/60d9f1c61306b9c76be3f487d56f95a04a4565f0))
* **checkout-button:** support product variations ([#1442](https://github.com/Automattic/newspack-blocks/issues/1442)) ([aa70495](https://github.com/Automattic/newspack-blocks/commit/aa70495940a6c85e9eb9747eb91a989df97e9921))

## [1.68.2](https://github.com/Automattic/newspack-blocks/compare/v1.68.1...v1.68.2) (2023-05-24)


### Bug Fixes

* bump up font size on smallest blocks ([#1443](https://github.com/Automattic/newspack-blocks/issues/1443)) ([97c77c3](https://github.com/Automattic/newspack-blocks/commit/97c77c3129c352b541be47e7b842f0b048165a81))

## [1.68.2-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.68.1...v1.68.2-alpha.1) (2023-05-11)


### Bug Fixes

* bump up font size on smallest blocks ([#1443](https://github.com/Automattic/newspack-blocks/issues/1443)) ([97c77c3](https://github.com/Automattic/newspack-blocks/commit/97c77c3129c352b541be47e7b842f0b048165a81))

## [1.68.1](https://github.com/Automattic/newspack-blocks/compare/v1.68.0...v1.68.1) (2023-05-09)


### Bug Fixes

* **checkout-button:** ensure modal "order received" url ([#1447](https://github.com/Automattic/newspack-blocks/issues/1447)) ([0b9bce5](https://github.com/Automattic/newspack-blocks/commit/0b9bce5e6f4c795fc427b0c3a45fde7db05d5e69))

# [1.68.0](https://github.com/Automattic/newspack-blocks/compare/v1.67.0...v1.68.0) (2023-05-08)


### Bug Fixes

* **checkout-button:** add to block list ([#1435](https://github.com/Automattic/newspack-blocks/issues/1435)) ([4376952](https://github.com/Automattic/newspack-blocks/commit/4376952c58e9b0a045d4afd72d77afd03f54b5d4))
* correct linting errors ([05d3e12](https://github.com/Automattic/newspack-blocks/commit/05d3e128f6552f0e9c7d50a63da06597c2aa0517))
* correct linting errors ([70f26d6](https://github.com/Automattic/newspack-blocks/commit/70f26d62ad116d8f014105ed76ab1ee622bd1683))
* **donate:** tiers based layout support check ([#1428](https://github.com/Automattic/newspack-blocks/issues/1428)) ([284f7a4](https://github.com/Automattic/newspack-blocks/commit/284f7a4afe102f99dcf2438d9567399f5f36c419))
* fixing linting errors ([7448cd2](https://github.com/Automattic/newspack-blocks/commit/7448cd251ef7a64c1d9d342d25df7777e034dbb7))


### Features

* Add brand query support to the blocks [#1427](https://github.com/Automattic/newspack-blocks/issues/1427) ([acf3ad8](https://github.com/Automattic/newspack-blocks/commit/acf3ad8fdce9fbfc092e1c4102527a20ac51f53d))
* add brands to post carousel ([e354d49](https://github.com/Automattic/newspack-blocks/commit/e354d49369b30d5e586c421f0ce3d9f9a99a5b50))
* add check for plugin before rendering fields ([9f237bb](https://github.com/Automattic/newspack-blocks/commit/9f237bbe07fc1bba5c280fe3f17441f2cbb59415))
* checkout button block ([#1421](https://github.com/Automattic/newspack-blocks/issues/1421)) ([4890a26](https://github.com/Automattic/newspack-blocks/commit/4890a265ae142adf312ac20e529b90c85e1149a3))
* initial pass at allowing multi-brand querying ([ce842f2](https://github.com/Automattic/newspack-blocks/commit/ce842f276d31fb4d201e5a78bcf3d9bc15673d7e))
* reorder query fields to move brands below tags ([8d91cff](https://github.com/Automattic/newspack-blocks/commit/8d91cff9ae6a8bc70a4bdc2c5557ef7b7b3bc108))

# [1.68.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.67.0...v1.68.0-alpha.1) (2023-04-28)


### Bug Fixes

* **checkout-button:** add to block list ([#1435](https://github.com/Automattic/newspack-blocks/issues/1435)) ([4376952](https://github.com/Automattic/newspack-blocks/commit/4376952c58e9b0a045d4afd72d77afd03f54b5d4))
* correct linting errors ([05d3e12](https://github.com/Automattic/newspack-blocks/commit/05d3e128f6552f0e9c7d50a63da06597c2aa0517))
* correct linting errors ([70f26d6](https://github.com/Automattic/newspack-blocks/commit/70f26d62ad116d8f014105ed76ab1ee622bd1683))
* **donate:** tiers based layout support check ([#1428](https://github.com/Automattic/newspack-blocks/issues/1428)) ([284f7a4](https://github.com/Automattic/newspack-blocks/commit/284f7a4afe102f99dcf2438d9567399f5f36c419))
* fixing linting errors ([7448cd2](https://github.com/Automattic/newspack-blocks/commit/7448cd251ef7a64c1d9d342d25df7777e034dbb7))


### Features

* Add brand query support to the blocks [#1427](https://github.com/Automattic/newspack-blocks/issues/1427) ([acf3ad8](https://github.com/Automattic/newspack-blocks/commit/acf3ad8fdce9fbfc092e1c4102527a20ac51f53d))
* add brands to post carousel ([e354d49](https://github.com/Automattic/newspack-blocks/commit/e354d49369b30d5e586c421f0ce3d9f9a99a5b50))
* add check for plugin before rendering fields ([9f237bb](https://github.com/Automattic/newspack-blocks/commit/9f237bbe07fc1bba5c280fe3f17441f2cbb59415))
* checkout button block ([#1421](https://github.com/Automattic/newspack-blocks/issues/1421)) ([4890a26](https://github.com/Automattic/newspack-blocks/commit/4890a265ae142adf312ac20e529b90c85e1149a3))
* initial pass at allowing multi-brand querying ([ce842f2](https://github.com/Automattic/newspack-blocks/commit/ce842f276d31fb4d201e5a78bcf3d9bc15673d7e))
* reorder query fields to move brands below tags ([8d91cff](https://github.com/Automattic/newspack-blocks/commit/8d91cff9ae6a8bc70a4bdc2c5557ef7b7b3bc108))

# [1.67.0](https://github.com/Automattic/newspack-blocks/compare/v1.66.1...v1.67.0) (2023-04-24)


### Bug Fixes

* local git hooks ([#1414](https://github.com/Automattic/newspack-blocks/issues/1414)) ([bb99969](https://github.com/Automattic/newspack-blocks/commit/bb9996926af87eb76e0c5ab6b6a78c621021f701))
* modal checkout outside of ras ([#1434](https://github.com/Automattic/newspack-blocks/issues/1434)) ([da5e1e2](https://github.com/Automattic/newspack-blocks/commit/da5e1e2af6e9e9be9c2627b857d489eee46b4fbe))


### Features

* **donate:** add recaptcha panel in the editor ([#1419](https://github.com/Automattic/newspack-blocks/issues/1419)) ([6784bc8](https://github.com/Automattic/newspack-blocks/commit/6784bc89d109e02e72e390e261865829ef2c9044))

# [1.67.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.67.0-alpha.1...v1.67.0-alpha.2) (2023-04-18)


### Bug Fixes

* modal checkout outside of ras ([#1434](https://github.com/Automattic/newspack-blocks/issues/1434)) ([da5e1e2](https://github.com/Automattic/newspack-blocks/commit/da5e1e2af6e9e9be9c2627b857d489eee46b4fbe))

# [1.67.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.66.1...v1.67.0-alpha.1) (2023-04-13)


### Bug Fixes

* local git hooks ([#1414](https://github.com/Automattic/newspack-blocks/issues/1414)) ([bb99969](https://github.com/Automattic/newspack-blocks/commit/bb9996926af87eb76e0c5ab6b6a78c621021f701))


### Features

* **donate:** add recaptcha panel in the editor ([#1419](https://github.com/Automattic/newspack-blocks/issues/1419)) ([6784bc8](https://github.com/Automattic/newspack-blocks/commit/6784bc89d109e02e72e390e261865829ef2c9044))

## [1.66.1](https://github.com/Automattic/newspack-blocks/compare/v1.66.0...v1.66.1) (2023-04-11)


### Bug Fixes

* bugs for Post Carousel ([#1422](https://github.com/Automattic/newspack-blocks/issues/1422)) ([24f3c80](https://github.com/Automattic/newspack-blocks/commit/24f3c8000d251f2272fc09d8ba7837108751506d))

# [1.66.0](https://github.com/Automattic/newspack-blocks/compare/v1.65.0...v1.66.0) (2023-04-10)


### Features

* **donate:** add body class for modal checkout ([#1411](https://github.com/Automattic/newspack-blocks/issues/1411)) ([8fc436e](https://github.com/Automattic/newspack-blocks/commit/8fc436ef92e1edf8bc37a45bdaf20757c4686ae8))

# [1.66.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.65.0...v1.66.0-alpha.1) (2023-03-31)


### Features

* **donate:** add body class for modal checkout ([#1411](https://github.com/Automattic/newspack-blocks/issues/1411)) ([8fc436e](https://github.com/Automattic/newspack-blocks/commit/8fc436ef92e1edf8bc37a45bdaf20757c4686ae8))

# [1.65.0](https://github.com/Automattic/newspack-blocks/compare/v1.64.0...v1.65.0) (2023-03-27)


### Bug Fixes

* **modal-checkout:** z-index when used w/ Campaigns ([ef30e95](https://github.com/Automattic/newspack-blocks/commit/ef30e95885bbe950196583c54fb8f16d66423ee0))
* prevent enqueuing non-existent stylesheet ([916eaea](https://github.com/Automattic/newspack-blocks/commit/916eaea046670caf176b5022f23e2820132a4e26))


### Features

* update links to documentation to help.newspack [#1390](https://github.com/Automattic/newspack-blocks/issues/1390) ([f6e01b9](https://github.com/Automattic/newspack-blocks/commit/f6e01b9dc73fc9a61afaf8b873f36d13f4c79e45))

# [1.65.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.64.0...v1.65.0-alpha.1) (2023-03-16)


### Bug Fixes

* **modal-checkout:** z-index when used w/ Campaigns ([ef30e95](https://github.com/Automattic/newspack-blocks/commit/ef30e95885bbe950196583c54fb8f16d66423ee0))
* prevent enqueuing non-existent stylesheet ([916eaea](https://github.com/Automattic/newspack-blocks/commit/916eaea046670caf176b5022f23e2820132a4e26))


### Features

* update links to documentation to help.newspack [#1390](https://github.com/Automattic/newspack-blocks/issues/1390) ([f6e01b9](https://github.com/Automattic/newspack-blocks/commit/f6e01b9dc73fc9a61afaf8b873f36d13f4c79e45))

# [1.64.0](https://github.com/Automattic/newspack-blocks/compare/v1.63.0...v1.64.0) (2023-03-14)


### Bug Fixes

* don't strip HTML from Homepage Posts Block excerpts ([#1364](https://github.com/Automattic/newspack-blocks/issues/1364)) ([46ee78c](https://github.com/Automattic/newspack-blocks/commit/46ee78c9acd10397ac602e3d972d4c10386accea))
* **modal-checkout:** z-index when used w/ Campaigns ([d47c1e8](https://github.com/Automattic/newspack-blocks/commit/d47c1e84269a0e20fbbc8df204ab189cd3fc1144))
* remove unneeded group & columns blocks from patterns ([#1377](https://github.com/Automattic/newspack-blocks/issues/1377)) ([7b9483a](https://github.com/Automattic/newspack-blocks/commit/7b9483a0179136a6bb401998ddd514bfad983fde))


### Features

* add popup info to donation block ([9493990](https://github.com/Automattic/newspack-blocks/commit/94939908765dcd06c77fd456ed597d9b8fbe61b3))
* add popup info to donation block ([949c2bc](https://github.com/Automattic/newspack-blocks/commit/949c2bce9077b744cf5f78bca008b2489a344787))
* **donate:** checkout modal for woocommerce ([#1355](https://github.com/Automattic/newspack-blocks/issues/1355)) ([0413ace](https://github.com/Automattic/newspack-blocks/commit/0413ace0d37a5175043c7843e450bb637a800ef8))
* **donate:** enquque modal checkout JS only if necessary ([f877408](https://github.com/Automattic/newspack-blocks/commit/f877408ab6d168276474bdf6ca87b8dc7cfa403f))
* process donation - make sure referer is relative path ([777685a](https://github.com/Automattic/newspack-blocks/commit/777685a82a261f21f2a09a5743fb86ee67b3c715))

# [1.64.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.64.0-alpha.1...v1.64.0-alpha.2) (2023-03-08)


### Bug Fixes

* **modal-checkout:** z-index when used w/ Campaigns ([d47c1e8](https://github.com/Automattic/newspack-blocks/commit/d47c1e84269a0e20fbbc8df204ab189cd3fc1144))

# [1.64.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.63.0...v1.64.0-alpha.1) (2023-03-03)


### Bug Fixes

* don't strip HTML from Homepage Posts Block excerpts ([#1364](https://github.com/Automattic/newspack-blocks/issues/1364)) ([46ee78c](https://github.com/Automattic/newspack-blocks/commit/46ee78c9acd10397ac602e3d972d4c10386accea))
* remove unneeded group & columns blocks from patterns ([#1377](https://github.com/Automattic/newspack-blocks/issues/1377)) ([7b9483a](https://github.com/Automattic/newspack-blocks/commit/7b9483a0179136a6bb401998ddd514bfad983fde))


### Features

* add popup info to donation block ([9493990](https://github.com/Automattic/newspack-blocks/commit/94939908765dcd06c77fd456ed597d9b8fbe61b3))
* add popup info to donation block ([949c2bc](https://github.com/Automattic/newspack-blocks/commit/949c2bce9077b744cf5f78bca008b2489a344787))
* **donate:** checkout modal for woocommerce ([#1355](https://github.com/Automattic/newspack-blocks/issues/1355)) ([0413ace](https://github.com/Automattic/newspack-blocks/commit/0413ace0d37a5175043c7843e450bb637a800ef8))
* **donate:** enquque modal checkout JS only if necessary ([f877408](https://github.com/Automattic/newspack-blocks/commit/f877408ab6d168276474bdf6ca87b8dc7cfa403f))
* process donation - make sure referer is relative path ([777685a](https://github.com/Automattic/newspack-blocks/commit/777685a82a261f21f2a09a5743fb86ee67b3c715))

# [1.63.0](https://github.com/Automattic/newspack-blocks/compare/v1.62.0...v1.63.0) (2023-02-28)


### Bug Fixes

* attribute types for custom author fields ([#1365](https://github.com/Automattic/newspack-blocks/issues/1365)) ([483e49c](https://github.com/Automattic/newspack-blocks/commit/483e49cd2923ad0d9f0e5c8030df23e8e64138cb))
* remove mark background in Newspack blocks ([#1363](https://github.com/Automattic/newspack-blocks/issues/1363)) ([295b7d1](https://github.com/Automattic/newspack-blocks/commit/295b7d18df51f4bb6991c415d5e288af0dbb647f))


### Features

* **donate-block:** native wc subs ([#1359](https://github.com/Automattic/newspack-blocks/issues/1359)) ([9834aa0](https://github.com/Automattic/newspack-blocks/commit/9834aa0ade7d74fe2ab832ccdd96859917ca2ac0))

# [1.63.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.62.0...v1.63.0-alpha.1) (2023-02-17)


### Bug Fixes

* attribute types for custom author fields ([#1365](https://github.com/Automattic/newspack-blocks/issues/1365)) ([483e49c](https://github.com/Automattic/newspack-blocks/commit/483e49cd2923ad0d9f0e5c8030df23e8e64138cb))
* remove mark background in Newspack blocks ([#1363](https://github.com/Automattic/newspack-blocks/issues/1363)) ([295b7d1](https://github.com/Automattic/newspack-blocks/commit/295b7d18df51f4bb6991c415d5e288af0dbb647f))


### Features

* **donate-block:** native wc subs ([#1359](https://github.com/Automattic/newspack-blocks/issues/1359)) ([9834aa0](https://github.com/Automattic/newspack-blocks/commit/9834aa0ade7d74fe2ab832ccdd96859917ca2ac0))

# [1.62.0](https://github.com/Automattic/newspack-blocks/compare/v1.61.0...v1.62.0) (2023-02-16)


### Bug Fixes

* show HPP dates in correct timezone in editor ([#1348](https://github.com/Automattic/newspack-blocks/issues/1348)) ([1f3245f](https://github.com/Automattic/newspack-blocks/commit/1f3245f6f93c8982ea43539a999a0defba7489b1))


### Features

* **hpb:** fetch priority attr; attrs filtering; remove disableImageLazyLoad UI ([#1351](https://github.com/Automattic/newspack-blocks/issues/1351)) ([386ae33](https://github.com/Automattic/newspack-blocks/commit/386ae33749bf1f35ccc058425c7c943ed96d890b))

# [1.62.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.61.0...v1.62.0-alpha.1) (2023-01-26)


### Bug Fixes

* show HPP dates in correct timezone in editor ([#1348](https://github.com/Automattic/newspack-blocks/issues/1348)) ([1f3245f](https://github.com/Automattic/newspack-blocks/commit/1f3245f6f93c8982ea43539a999a0defba7489b1))


### Features

* **hpb:** fetch priority attr; attrs filtering; remove disableImageLazyLoad UI ([#1351](https://github.com/Automattic/newspack-blocks/issues/1351)) ([386ae33](https://github.com/Automattic/newspack-blocks/commit/386ae33749bf1f35ccc058425c7c943ed96d890b))

# [1.61.0](https://github.com/Automattic/newspack-blocks/compare/v1.60.1...v1.61.0) (2023-01-25)


### Bug Fixes

* **donate-stripe:** initialize Stripe only if element is visible ([#1344](https://github.com/Automattic/newspack-blocks/issues/1344)) ([207bff7](https://github.com/Automattic/newspack-blocks/commit/207bff7cadca0102e02f152875425ca608149cfe))
* **HPB:** author query if CAP is unavailable ([#1336](https://github.com/Automattic/newspack-blocks/issues/1336)) ([b3ad96f](https://github.com/Automattic/newspack-blocks/commit/b3ad96f0112e05c8f7530c2e632e7a1754d760cc))
* make author block font sizing more specific ([#1343](https://github.com/Automattic/newspack-blocks/issues/1343)) ([a84034f](https://github.com/Automattic/newspack-blocks/commit/a84034f81c0d04ddbfe4eeb52fbcaac33d2ef158))
* make sure correct avatar is loaded when guests and users share IDs ([#1339](https://github.com/Automattic/newspack-blocks/issues/1339)) ([ebab2eb](https://github.com/Automattic/newspack-blocks/commit/ebab2eb9a3695336c394f01dd6835b46b248732c))


### Features

* **homepage-posts-block:** filter image sizes based on the maximum image width ([#1322](https://github.com/Automattic/newspack-blocks/issues/1322)) ([6bed199](https://github.com/Automattic/newspack-blocks/commit/6bed1993216c31705e1ff59b55b1119aee13c4fc))

# [1.61.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.60.1...v1.61.0-alpha.1) (2023-01-25)


### Bug Fixes

* **donate-stripe:** initialize Stripe only if element is visible ([#1344](https://github.com/Automattic/newspack-blocks/issues/1344)) ([207bff7](https://github.com/Automattic/newspack-blocks/commit/207bff7cadca0102e02f152875425ca608149cfe))
* **HPB:** author query if CAP is unavailable ([#1336](https://github.com/Automattic/newspack-blocks/issues/1336)) ([b3ad96f](https://github.com/Automattic/newspack-blocks/commit/b3ad96f0112e05c8f7530c2e632e7a1754d760cc))
* make author block font sizing more specific ([#1343](https://github.com/Automattic/newspack-blocks/issues/1343)) ([a84034f](https://github.com/Automattic/newspack-blocks/commit/a84034f81c0d04ddbfe4eeb52fbcaac33d2ef158))
* make sure correct avatar is loaded when guests and users share IDs ([#1339](https://github.com/Automattic/newspack-blocks/issues/1339)) ([ebab2eb](https://github.com/Automattic/newspack-blocks/commit/ebab2eb9a3695336c394f01dd6835b46b248732c))


### Features

* **homepage-posts-block:** filter image sizes based on the maximum image width ([#1322](https://github.com/Automattic/newspack-blocks/issues/1322)) ([6bed199](https://github.com/Automattic/newspack-blocks/commit/6bed1993216c31705e1ff59b55b1119aee13c4fc))

## [1.60.1](https://github.com/Automattic/newspack-blocks/compare/v1.60.0...v1.60.1) (2023-01-12)


### Bug Fixes

* use release version number instead of filemtime for JS asset versions ([623b620](https://github.com/Automattic/newspack-blocks/commit/623b620ffa50c8bd7907188db7529b44cfdf9209))

## [1.60.1-hotfix.1](https://github.com/Automattic/newspack-blocks/compare/v1.60.0...v1.60.1-hotfix.1) (2023-01-12)


### Bug Fixes

* use release version number instead of filemtime for JS asset versions ([623b620](https://github.com/Automattic/newspack-blocks/commit/623b620ffa50c8bd7907188db7529b44cfdf9209))

# [1.60.0](https://github.com/Automattic/newspack-blocks/compare/v1.59.1...v1.60.0) (2023-01-09)


### Bug Fixes

* allow all img tag attributes in avatar output ([#1333](https://github.com/Automattic/newspack-blocks/issues/1333)) ([2f57019](https://github.com/Automattic/newspack-blocks/commit/2f570192c4fc4e29a7b532c53309bdcef5cab9cb))


### Features

* **donate block:** tiers layout ([#1311](https://github.com/Automattic/newspack-blocks/issues/1311)) ([9228ee4](https://github.com/Automattic/newspack-blocks/commit/9228ee46e4059fecb0a964919a295a72f5691032))
* **donate:** additional fields on the form ([#1330](https://github.com/Automattic/newspack-blocks/issues/1330)) ([1f6869d](https://github.com/Automattic/newspack-blocks/commit/1f6869d2c11c374eda7e56e9aa1425383d9dcd97))
* handle 'other' reader revenue platform in the editor ([0e6ca8a](https://github.com/Automattic/newspack-blocks/commit/0e6ca8a4e78f66db35e9d95a1ba9810a08aeaebb))
* unregister Jetpack Subscriptions block to avoid confusion with Newspack blocks ([#1337](https://github.com/Automattic/newspack-blocks/issues/1337)) ([7dab5fc](https://github.com/Automattic/newspack-blocks/commit/7dab5fcff9bcf38636becc030bdab1c612a8d0ab))

# [1.60.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.60.0-alpha.1...v1.60.0-alpha.2) (2022-12-22)


### Features

* **donate:** additional fields on the form ([#1330](https://github.com/Automattic/newspack-blocks/issues/1330)) ([1f6869d](https://github.com/Automattic/newspack-blocks/commit/1f6869d2c11c374eda7e56e9aa1425383d9dcd97))
* unregister Jetpack Subscriptions block to avoid confusion with Newspack blocks ([#1337](https://github.com/Automattic/newspack-blocks/issues/1337)) ([7dab5fc](https://github.com/Automattic/newspack-blocks/commit/7dab5fcff9bcf38636becc030bdab1c612a8d0ab))

# [1.60.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.59.1...v1.60.0-alpha.1) (2022-12-20)


### Bug Fixes

* allow all img tag attributes in avatar output ([#1333](https://github.com/Automattic/newspack-blocks/issues/1333)) ([2f57019](https://github.com/Automattic/newspack-blocks/commit/2f570192c4fc4e29a7b532c53309bdcef5cab9cb))


### Features

* **donate block:** tiers layout ([#1311](https://github.com/Automattic/newspack-blocks/issues/1311)) ([9228ee4](https://github.com/Automattic/newspack-blocks/commit/9228ee46e4059fecb0a964919a295a72f5691032))
* handle 'other' reader revenue platform in the editor ([0e6ca8a](https://github.com/Automattic/newspack-blocks/commit/0e6ca8a4e78f66db35e9d95a1ba9810a08aeaebb))

## [1.59.1](https://github.com/Automattic/newspack-blocks/compare/v1.59.0...v1.59.1) (2022-12-12)


### Bug Fixes

* **donate:** override defaults with manual config ([#1313](https://github.com/Automattic/newspack-blocks/issues/1313)) ([637284f](https://github.com/Automattic/newspack-blocks/commit/637284f4e42868125785a7139f440a364fc516e8))
* update CI orb newspack-scripts ([a6675b7](https://github.com/Automattic/newspack-blocks/commit/a6675b780e58f995bea6bc4c58fa11c56a99679f))

## [1.59.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.59.0...v1.59.1-alpha.1) (2022-12-01)


### Bug Fixes

* **donate:** override defaults with manual config ([#1313](https://github.com/Automattic/newspack-blocks/issues/1313)) ([637284f](https://github.com/Automattic/newspack-blocks/commit/637284f4e42868125785a7139f440a364fc516e8))
* update CI orb newspack-scripts ([a6675b7](https://github.com/Automattic/newspack-blocks/commit/a6675b780e58f995bea6bc4c58fa11c56a99679f))

# [1.59.0](https://github.com/Automattic/newspack-blocks/compare/v1.58.1...v1.59.0) (2022-11-14)


### Bug Fixes

* update newspack-scripts and CI orb ([#1304](https://github.com/Automattic/newspack-blocks/issues/1304)) ([25852a9](https://github.com/Automattic/newspack-blocks/commit/25852a90f121ac588dfe3b892bad433aedd5a20a))


### Features

* display newspack's author custom fields ([#1302](https://github.com/Automattic/newspack-blocks/issues/1302)) ([081f228](https://github.com/Automattic/newspack-blocks/commit/081f228f0655b36c7ab5891a7756a4259435239a)), closes [#1166](https://github.com/Automattic/newspack-blocks/issues/1166)

# [1.59.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.59.0-alpha.1...v1.59.0-alpha.2) (2022-11-11)


### Bug Fixes

* update newspack-scripts and CI orb ([#1304](https://github.com/Automattic/newspack-blocks/issues/1304)) ([25852a9](https://github.com/Automattic/newspack-blocks/commit/25852a90f121ac588dfe3b892bad433aedd5a20a))

# [1.59.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.58.1...v1.59.0-alpha.1) (2022-11-03)


### Features

* display newspack's author custom fields ([#1302](https://github.com/Automattic/newspack-blocks/issues/1302)) ([081f228](https://github.com/Automattic/newspack-blocks/commit/081f228f0655b36c7ab5891a7756a4259435239a)), closes [#1166](https://github.com/Automattic/newspack-blocks/issues/1166)

## [1.58.1](https://github.com/Automattic/newspack-blocks/compare/v1.58.0...v1.58.1) (2022-10-19)


### Bug Fixes

* linter errors caused by $sponsor_classes ([#1286](https://github.com/Automattic/newspack-blocks/issues/1286)) ([21c6545](https://github.com/Automattic/newspack-blocks/commit/21c65453d66c5e279a8f258c83bf4851bb072236))
* make sure sponsor flag previews in editor ([#1279](https://github.com/Automattic/newspack-blocks/issues/1279)) ([e63c3ab](https://github.com/Automattic/newspack-blocks/commit/e63c3ab04b0b01545f54a6c8df741e3b7f082cd8))

## [1.58.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.58.0...v1.58.1-alpha.1) (2022-10-06)


### Bug Fixes

* linter errors caused by $sponsor_classes ([#1286](https://github.com/Automattic/newspack-blocks/issues/1286)) ([21c6545](https://github.com/Automattic/newspack-blocks/commit/21c65453d66c5e279a8f258c83bf4851bb072236))
* make sure sponsor flag previews in editor ([#1279](https://github.com/Automattic/newspack-blocks/issues/1279)) ([e63c3ab](https://github.com/Automattic/newspack-blocks/commit/e63c3ab04b0b01545f54a6c8df741e3b7f082cd8))

# [1.58.0](https://github.com/Automattic/newspack-blocks/compare/v1.57.0...v1.58.0) (2022-09-27)


### Bug Fixes

* **donate:** handle zero fees ([744fa50](https://github.com/Automattic/newspack-blocks/commit/744fa50eb4b4c1585a078c5cfc773be1f491920a))
* **donate:** user id handling ([29efec8](https://github.com/Automattic/newspack-blocks/commit/29efec84644b7b1ddcc2b72734b58d03c760f30c))


### Features

* **donations:** pass client metadata when creating a WC membership ([764a4e4](https://github.com/Automattic/newspack-blocks/commit/764a4e4bbcb5777ab5be7f17894907c709bf424c))

# [1.58.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.57.0...v1.58.0-alpha.1) (2022-09-26)


### Bug Fixes

* **donate:** handle zero fees ([744fa50](https://github.com/Automattic/newspack-blocks/commit/744fa50eb4b4c1585a078c5cfc773be1f491920a))
* **donate:** user id handling ([29efec8](https://github.com/Automattic/newspack-blocks/commit/29efec84644b7b1ddcc2b72734b58d03c760f30c))


### Features

* **donations:** pass client metadata when creating a WC membership ([764a4e4](https://github.com/Automattic/newspack-blocks/commit/764a4e4bbcb5777ab5be7f17894907c709bf424c))

# [1.57.0](https://github.com/Automattic/newspack-blocks/compare/v1.56.0...v1.57.0) (2022-09-14)


### Bug Fixes

* add button block class to the Homepage Posts more button ([#1252](https://github.com/Automattic/newspack-blocks/issues/1252)) ([c4d565c](https://github.com/Automattic/newspack-blocks/commit/c4d565c1dcac3c62d4ce299bc74d422bc2b686cc))
* correct Donate thank you message alignment ([#1258](https://github.com/Automattic/newspack-blocks/issues/1258)) ([e838e61](https://github.com/Automattic/newspack-blocks/commit/e838e61c58ad8f3aef85b8c6b73ef61526e7bfa0))
* disambiguate users and guest authors in Author List exclusions ([#1154](https://github.com/Automattic/newspack-blocks/issues/1154)) ([c59056b](https://github.com/Automattic/newspack-blocks/commit/c59056bbd21359b1167998f961f59e607cc3bd67))
* **donate:** default value not below minimum donation ([#1248](https://github.com/Automattic/newspack-blocks/issues/1248)) ([368e856](https://github.com/Automattic/newspack-blocks/commit/368e85634df97bf011ae2274460695fb6d1b6cc3))
* **donate:** use first & last name as default name ([#1255](https://github.com/Automattic/newspack-blocks/issues/1255)) ([105d95b](https://github.com/Automattic/newspack-blocks/commit/105d95b7679daae21b204783e25cd6659d787b3e))
* re-add excerpt length preview in editor ([#1247](https://github.com/Automattic/newspack-blocks/issues/1247)) ([bdbe86e](https://github.com/Automattic/newspack-blocks/commit/bdbe86eb0811152dbac2f621e481bb19403b5837))
* update Homepage Posts grid spacing and add control  ([#1245](https://github.com/Automattic/newspack-blocks/issues/1245)) ([9fa6972](https://github.com/Automattic/newspack-blocks/commit/9fa6972793d3ae82b008e098736337e4dd332dba))


### Features

* handle minimum donation option in Donate block ([#1239](https://github.com/Automattic/newspack-blocks/issues/1239)) ([10dfefe](https://github.com/Automattic/newspack-blocks/commit/10dfefe43d1130490a18aa51e2509a8fae7d12a0))
* if donation via a prompt, add prompt ID to Stripe payment metadata ([#1253](https://github.com/Automattic/newspack-blocks/issues/1253)) ([b8cb0e9](https://github.com/Automattic/newspack-blocks/commit/b8cb0e9ffa7a42d6d8cf1c7dbcbce824f900e8a3))

# [1.57.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.57.0-alpha.1...v1.57.0-alpha.2) (2022-09-06)


### Bug Fixes

* correct Donate thank you message alignment ([#1258](https://github.com/Automattic/newspack-blocks/issues/1258)) ([e838e61](https://github.com/Automattic/newspack-blocks/commit/e838e61c58ad8f3aef85b8c6b73ef61526e7bfa0))
* update Homepage Posts grid spacing and add control  ([#1245](https://github.com/Automattic/newspack-blocks/issues/1245)) ([9fa6972](https://github.com/Automattic/newspack-blocks/commit/9fa6972793d3ae82b008e098736337e4dd332dba))

# [1.57.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.56.0...v1.57.0-alpha.1) (2022-08-26)


### Bug Fixes

* add button block class to the Homepage Posts more button ([#1252](https://github.com/Automattic/newspack-blocks/issues/1252)) ([c4d565c](https://github.com/Automattic/newspack-blocks/commit/c4d565c1dcac3c62d4ce299bc74d422bc2b686cc))
* disambiguate users and guest authors in Author List exclusions ([#1154](https://github.com/Automattic/newspack-blocks/issues/1154)) ([c59056b](https://github.com/Automattic/newspack-blocks/commit/c59056bbd21359b1167998f961f59e607cc3bd67))
* **donate:** default value not below minimum donation ([#1248](https://github.com/Automattic/newspack-blocks/issues/1248)) ([368e856](https://github.com/Automattic/newspack-blocks/commit/368e85634df97bf011ae2274460695fb6d1b6cc3))
* **donate:** use first & last name as default name ([#1255](https://github.com/Automattic/newspack-blocks/issues/1255)) ([105d95b](https://github.com/Automattic/newspack-blocks/commit/105d95b7679daae21b204783e25cd6659d787b3e))
* re-add excerpt length preview in editor ([#1247](https://github.com/Automattic/newspack-blocks/issues/1247)) ([bdbe86e](https://github.com/Automattic/newspack-blocks/commit/bdbe86eb0811152dbac2f621e481bb19403b5837))


### Features

* handle minimum donation option in Donate block ([#1239](https://github.com/Automattic/newspack-blocks/issues/1239)) ([10dfefe](https://github.com/Automattic/newspack-blocks/commit/10dfefe43d1130490a18aa51e2509a8fae7d12a0))
* if donation via a prompt, add prompt ID to Stripe payment metadata ([#1253](https://github.com/Automattic/newspack-blocks/issues/1253)) ([b8cb0e9](https://github.com/Automattic/newspack-blocks/commit/b8cb0e9ffa7a42d6d8cf1c7dbcbce824f900e8a3))

# [1.56.0](https://github.com/Automattic/newspack-blocks/compare/v1.55.0...v1.56.0) (2022-08-16)


### Bug Fixes

* ensure amount is always defined for totalAmount calculation ([#1227](https://github.com/Automattic/newspack-blocks/issues/1227)) ([b1910dc](https://github.com/Automattic/newspack-blocks/commit/b1910dc117140c489d6a8ff960722e9693cd3327))
* force a new alpha build ([8bf60a8](https://github.com/Automattic/newspack-blocks/commit/8bf60a8ca1ffc739b3b0f0f62c595c44c4e7615f))
* merge conflicts and force new build again ([b9020c6](https://github.com/Automattic/newspack-blocks/commit/b9020c67eff7bf09af7b10117b33b34592501356))
* resolve merge conflicts with [#1234](https://github.com/Automattic/newspack-blocks/issues/1234) ([15fe247](https://github.com/Automattic/newspack-blocks/commit/15fe2472a98d2938e64cdcf711eb61cfa15a40e8))


### Features

* **donate:** add current_page_url to client metadata ([32e18a4](https://github.com/Automattic/newspack-blocks/commit/32e18a4f74a59723ab96ea9905fba0837a75c935))
* **donate:** customise donate button texts ([#1219](https://github.com/Automattic/newspack-blocks/issues/1219)) ([8bda0f0](https://github.com/Automattic/newspack-blocks/commit/8bda0f0ef23895455fd99aa17069aeed2c8468a4))
* **donate:** mobile style; UX tweaks ([#1201](https://github.com/Automattic/newspack-blocks/issues/1201)) ([65a1285](https://github.com/Automattic/newspack-blocks/commit/65a1285b49ffa86b145d24eac6968cdfd0530770))
* **donate:** refresh reader authentication ([#1236](https://github.com/Automattic/newspack-blocks/issues/1236)) ([f21904b](https://github.com/Automattic/newspack-blocks/commit/f21904b59a3a9d4295f74f34eebf3e9b8773cb4c))

# [1.56.0-alpha.4](https://github.com/Automattic/newspack-blocks/compare/v1.56.0-alpha.3...v1.56.0-alpha.4) (2022-08-12)


### Bug Fixes

* merge conflicts and force new build again ([b9020c6](https://github.com/Automattic/newspack-blocks/commit/b9020c67eff7bf09af7b10117b33b34592501356))

# [1.56.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v1.56.0-alpha.2...v1.56.0-alpha.3) (2022-08-12)


### Bug Fixes

* force a new alpha build ([8bf60a8](https://github.com/Automattic/newspack-blocks/commit/8bf60a8ca1ffc739b3b0f0f62c595c44c4e7615f))

# [1.56.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.56.0-alpha.1...v1.56.0-alpha.2) (2022-08-12)


### Bug Fixes

* ensure amount is always defined for totalAmount calculation ([#1227](https://github.com/Automattic/newspack-blocks/issues/1227)) ([b1910dc](https://github.com/Automattic/newspack-blocks/commit/b1910dc117140c489d6a8ff960722e9693cd3327))
* resolve merge conflicts with [#1234](https://github.com/Automattic/newspack-blocks/issues/1234) ([15fe247](https://github.com/Automattic/newspack-blocks/commit/15fe2472a98d2938e64cdcf711eb61cfa15a40e8))


### Features

* **donate:** refresh reader authentication ([#1236](https://github.com/Automattic/newspack-blocks/issues/1236)) ([f21904b](https://github.com/Automattic/newspack-blocks/commit/f21904b59a3a9d4295f74f34eebf3e9b8773cb4c))

# [1.56.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.55.0...v1.56.0-alpha.1) (2022-08-10)


### Features

* **donate:** add current_page_url to client metadata ([32e18a4](https://github.com/Automattic/newspack-blocks/commit/32e18a4f74a59723ab96ea9905fba0837a75c935))
* **donate:** customise donate button texts ([#1219](https://github.com/Automattic/newspack-blocks/issues/1219)) ([8bda0f0](https://github.com/Automattic/newspack-blocks/commit/8bda0f0ef23895455fd99aa17069aeed2c8468a4))
* **donate:** mobile style; UX tweaks ([#1201](https://github.com/Automattic/newspack-blocks/issues/1201)) ([65a1285](https://github.com/Automattic/newspack-blocks/commit/65a1285b49ffa86b145d24eac6968cdfd0530770))

# [1.55.0](https://github.com/Automattic/newspack-blocks/compare/v1.54.1...v1.55.0) (2022-08-10)


### Bug Fixes

* check that grecaptcha library exists before using its methods ([97b7832](https://github.com/Automattic/newspack-blocks/commit/97b783251c0f1f8d9d6921b6fc6453757a442bd1))
* esc user-inputted key value before appending to URL ([be1acb1](https://github.com/Automattic/newspack-blocks/commit/be1acb15e424f1077ad6b54d9688f182e0c49600))
* phpcs lint ([cd8fe6f](https://github.com/Automattic/newspack-blocks/commit/cd8fe6f3c7b2529adc00f17b2e8ba354ebb611be))
* replace hardcoded test secret key ([4fd9d8f](https://github.com/Automattic/newspack-blocks/commit/4fd9d8fecb718918da41f08330eb51edf747c858))


### Features

* use reCaptcha v3 to secure Stripe donate forms ([e3c6639](https://github.com/Automattic/newspack-blocks/commit/e3c6639a066694f15404ec2c9910c71509143fec))

## [1.54.1](https://github.com/Automattic/newspack-blocks/compare/v1.54.0...v1.54.1) (2022-07-29)


### Bug Fixes

* **donate-block:** handle default frequency from attributes ([4de797a](https://github.com/Automattic/newspack-blocks/commit/4de797a396c7ca3f7f07416c44a9f87a8ce37af1))

# [1.54.0](https://github.com/Automattic/newspack-blocks/compare/v1.53.1...v1.54.0) (2022-07-26)


### Bug Fixes

* resolve merge conflict with release ([#1199](https://github.com/Automattic/newspack-blocks/issues/1199)) ([ce82826](https://github.com/Automattic/newspack-blocks/commit/ce82826fdabda6a68caf9c65eae46b762caf6eef))


### Features

* **donate-block:** amounts and frequencies customisation ([#1191](https://github.com/Automattic/newspack-blocks/issues/1191)) ([99d967f](https://github.com/Automattic/newspack-blocks/commit/99d967f46f7a0da2f8f74ad799456193252ffc31))
* homepage posts patterns with ad unit ([#1170](https://github.com/Automattic/newspack-blocks/issues/1170)) ([a2e652e](https://github.com/Automattic/newspack-blocks/commit/a2e652ebe363adcd7e15484bf5b72b7d6d63bc38))
* support new sponsor options to show authors and categories ([#1156](https://github.com/Automattic/newspack-blocks/issues/1156)) ([67e8834](https://github.com/Automattic/newspack-blocks/commit/67e8834e5a1bfe0dbe1d3eb9d899be412c3197ac))

# [1.54.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.53.1...v1.54.0-alpha.1) (2022-07-14)


### Bug Fixes

* resolve merge conflict with release ([#1199](https://github.com/Automattic/newspack-blocks/issues/1199)) ([ce82826](https://github.com/Automattic/newspack-blocks/commit/ce82826fdabda6a68caf9c65eae46b762caf6eef))


### Features

* **donate-block:** amounts and frequencies customisation ([#1191](https://github.com/Automattic/newspack-blocks/issues/1191)) ([99d967f](https://github.com/Automattic/newspack-blocks/commit/99d967f46f7a0da2f8f74ad799456193252ffc31))
* homepage posts patterns with ad unit ([#1170](https://github.com/Automattic/newspack-blocks/issues/1170)) ([a2e652e](https://github.com/Automattic/newspack-blocks/commit/a2e652ebe363adcd7e15484bf5b72b7d6d63bc38))
* support new sponsor options to show authors and categories ([#1156](https://github.com/Automattic/newspack-blocks/issues/1156)) ([67e8834](https://github.com/Automattic/newspack-blocks/commit/67e8834e5a1bfe0dbe1d3eb9d899be412c3197ac))

## [1.53.1](https://github.com/Automattic/newspack-blocks/compare/v1.53.0...v1.53.1) (2022-07-05)


### Bug Fixes

* guest author selection in Author Profile block ([#1188](https://github.com/Automattic/newspack-blocks/issues/1188)) ([085efaf](https://github.com/Automattic/newspack-blocks/commit/085efaf431b70ad15514a0d33c7ad0ce0697387e))

# [1.53.0](https://github.com/Automattic/newspack-blocks/compare/v1.52.0...v1.53.0) (2022-06-27)


### Bug Fixes

* **donate:** amount formatting ([c766ce3](https://github.com/Automattic/newspack-blocks/commit/c766ce38fd8aba8fb1c6a688f96f7d6b1ddb05bf))
* **homepage posts block:** align items when an odd number of items is inserted ([#1173](https://github.com/Automattic/newspack-blocks/issues/1173)) ([7139567](https://github.com/Automattic/newspack-blocks/commit/71395679c60f4ce544d35ced95a97eff0d423539))
* **iframe-block:** handle errors from server-side fetch ([#1162](https://github.com/Automattic/newspack-blocks/issues/1162)) ([8378b41](https://github.com/Automattic/newspack-blocks/commit/8378b417c279dd03bcaa11d7676248f67a77d812))
* make sure block exists before unregistering ([#1169](https://github.com/Automattic/newspack-blocks/issues/1169)) ([6d3deed](https://github.com/Automattic/newspack-blocks/commit/6d3deed0918851172560956ab8bc59d918caaae5))


### Features

* unregister the Jetpack Donation block ([#1163](https://github.com/Automattic/newspack-blocks/issues/1163)) ([6acbcac](https://github.com/Automattic/newspack-blocks/commit/6acbcac378b267f726efcde06552b9fdd2fb8218))

# [1.53.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.52.0...v1.53.0-alpha.1) (2022-06-16)


### Bug Fixes

* **donate:** amount formatting ([c766ce3](https://github.com/Automattic/newspack-blocks/commit/c766ce38fd8aba8fb1c6a688f96f7d6b1ddb05bf))
* **homepage posts block:** align items when an odd number of items is inserted ([#1173](https://github.com/Automattic/newspack-blocks/issues/1173)) ([7139567](https://github.com/Automattic/newspack-blocks/commit/71395679c60f4ce544d35ced95a97eff0d423539))
* **iframe-block:** handle errors from server-side fetch ([#1162](https://github.com/Automattic/newspack-blocks/issues/1162)) ([8378b41](https://github.com/Automattic/newspack-blocks/commit/8378b417c279dd03bcaa11d7676248f67a77d812))
* make sure block exists before unregistering ([#1169](https://github.com/Automattic/newspack-blocks/issues/1169)) ([6d3deed](https://github.com/Automattic/newspack-blocks/commit/6d3deed0918851172560956ab8bc59d918caaae5))


### Features

* unregister the Jetpack Donation block ([#1163](https://github.com/Automattic/newspack-blocks/issues/1163)) ([6acbcac](https://github.com/Automattic/newspack-blocks/commit/6acbcac378b267f726efcde06552b9fdd2fb8218))

# [1.52.0](https://github.com/Automattic/newspack-blocks/compare/v1.51.0...v1.52.0) (2022-06-13)


### Bug Fixes

* correct donate block tab spacing in editor ([#1153](https://github.com/Automattic/newspack-blocks/issues/1153)) ([c17221f](https://github.com/Automattic/newspack-blocks/commit/c17221fae28e651189893e6536471772b7b73a55))
* disambiguate WP users vs. guest authors with same ID ([#1143](https://github.com/Automattic/newspack-blocks/issues/1143)) ([d3c5920](https://github.com/Automattic/newspack-blocks/commit/d3c5920ad0520976b2ff7b0b2ad9f89ab028bc3a))
* echo closing link tags on sponsor bylines ([#1152](https://github.com/Automattic/newspack-blocks/issues/1152)) ([f80893f](https://github.com/Automattic/newspack-blocks/commit/f80893f271dfdf91a359278d5b2365f619bdad4b))
* remove custom column block styles ([#1133](https://github.com/Automattic/newspack-blocks/issues/1133)) ([bd79783](https://github.com/Automattic/newspack-blocks/commit/bd797830364c2db88375a9239bd18a9c1145d98f))
* skipped linked images when navigating blocks by keyboard ([#1144](https://github.com/Automattic/newspack-blocks/issues/1144)) ([8975787](https://github.com/Automattic/newspack-blocks/commit/8975787776e4aef4c14b2ae26ac37fb2fb6e0dd0))


### Features

* add new subscribe pattern ([#1142](https://github.com/Automattic/newspack-blocks/issues/1142)) ([97d632e](https://github.com/Automattic/newspack-blocks/commit/97d632e3ed815d402bf1876d269cad87a572332b))
* remove support for the Aside post format ([#1139](https://github.com/Automattic/newspack-blocks/issues/1139)) ([9f9cdf4](https://github.com/Automattic/newspack-blocks/commit/9f9cdf4a9f2119faf4cd698f305d58a4f0e4bcd3))

# [1.52.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.51.0...v1.52.0-alpha.1) (2022-06-02)


### Bug Fixes

* correct donate block tab spacing in editor ([#1153](https://github.com/Automattic/newspack-blocks/issues/1153)) ([c17221f](https://github.com/Automattic/newspack-blocks/commit/c17221fae28e651189893e6536471772b7b73a55))
* disambiguate WP users vs. guest authors with same ID ([#1143](https://github.com/Automattic/newspack-blocks/issues/1143)) ([d3c5920](https://github.com/Automattic/newspack-blocks/commit/d3c5920ad0520976b2ff7b0b2ad9f89ab028bc3a))
* echo closing link tags on sponsor bylines ([#1152](https://github.com/Automattic/newspack-blocks/issues/1152)) ([f80893f](https://github.com/Automattic/newspack-blocks/commit/f80893f271dfdf91a359278d5b2365f619bdad4b))
* remove custom column block styles ([#1133](https://github.com/Automattic/newspack-blocks/issues/1133)) ([bd79783](https://github.com/Automattic/newspack-blocks/commit/bd797830364c2db88375a9239bd18a9c1145d98f))
* skipped linked images when navigating blocks by keyboard ([#1144](https://github.com/Automattic/newspack-blocks/issues/1144)) ([8975787](https://github.com/Automattic/newspack-blocks/commit/8975787776e4aef4c14b2ae26ac37fb2fb6e0dd0))


### Features

* add new subscribe pattern ([#1142](https://github.com/Automattic/newspack-blocks/issues/1142)) ([97d632e](https://github.com/Automattic/newspack-blocks/commit/97d632e3ed815d402bf1876d269cad87a572332b))
* remove support for the Aside post format ([#1139](https://github.com/Automattic/newspack-blocks/issues/1139)) ([9f9cdf4](https://github.com/Automattic/newspack-blocks/commit/9f9cdf4a9f2119faf4cd698f305d58a4f0e4bcd3))

# [1.51.0](https://github.com/Automattic/newspack-blocks/compare/v1.50.1...v1.51.0) (2022-05-30)


### Features

* **homepage-posts:** query by post statuses ([#1114](https://github.com/Automattic/newspack-blocks/issues/1114)) ([7ec20a3](https://github.com/Automattic/newspack-blocks/commit/7ec20a39a562c9bdc90e4d10008f0b20113b9cf6)), closes [#38](https://github.com/Automattic/newspack-blocks/issues/38)

# [1.51.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.51.0-alpha.1...v1.51.0-alpha.2) (2022-05-25)


### Bug Fixes

* force release build ([f58a2ed](https://github.com/Automattic/newspack-blocks/commit/f58a2ed069e8f1f1a0521e502180671fc6d19e58))
* update columns block styles for WP 6.0 ([#1141](https://github.com/Automattic/newspack-blocks/issues/1141)) ([0d9583e](https://github.com/Automattic/newspack-blocks/commit/0d9583e0e7dd6f185f2fbb921af249a2252bfe94))

## [1.50.1](https://github.com/Automattic/newspack-blocks/compare/v1.50.0...v1.50.1) (2022-05-25)


### Bug Fixes

* force release build ([f58a2ed](https://github.com/Automattic/newspack-blocks/commit/f58a2ed069e8f1f1a0521e502180671fc6d19e58))
* update columns block styles for WP 6.0 ([#1141](https://github.com/Automattic/newspack-blocks/issues/1141)) ([0d9583e](https://github.com/Automattic/newspack-blocks/commit/0d9583e0e7dd6f185f2fbb921af249a2252bfe94))

## [1.50.1-hotfix.1](https://github.com/Automattic/newspack-blocks/compare/v1.50.0...v1.50.1-hotfix.1) (2022-05-25)


### Bug Fixes

* remove custom column block styles ([e08db2a](https://github.com/Automattic/newspack-blocks/commit/e08db2a7ea5eb73d454c53db5ed42f75b260ca86))

# [1.50.0](https://github.com/Automattic/newspack-blocks/compare/v1.49.0...v1.50.0) (2022-05-18)


### Features

* improve alt text on images linked to posts ([#1118](https://github.com/Automattic/newspack-blocks/issues/1118)) ([d98f8ad](https://github.com/Automattic/newspack-blocks/commit/d98f8adc3a18b34cecd0e9d1c8b8bcf938c0e2d1))
* update donate block styles to support keyboard navigation ([#1117](https://github.com/Automattic/newspack-blocks/issues/1117)) ([0f65dd7](https://github.com/Automattic/newspack-blocks/commit/0f65dd7e8293f611aa6d2d81e663b72ae7317931))

# [1.50.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.49.0...v1.50.0-alpha.1) (2022-05-05)


### Features

* improve alt text on images linked to posts ([#1118](https://github.com/Automattic/newspack-blocks/issues/1118)) ([d98f8ad](https://github.com/Automattic/newspack-blocks/commit/d98f8adc3a18b34cecd0e9d1c8b8bcf938c0e2d1))
* update donate block styles to support keyboard navigation ([#1117](https://github.com/Automattic/newspack-blocks/issues/1117)) ([0f65dd7](https://github.com/Automattic/newspack-blocks/commit/0f65dd7e8293f611aa6d2d81e663b72ae7317931))

# [1.49.0](https://github.com/Automattic/newspack-blocks/compare/v1.48.0...v1.49.0) (2022-05-03)


### Features

* **donate:** handle woocommerce-memberships ([#1096](https://github.com/Automattic/newspack-blocks/issues/1096)) ([22afbbf](https://github.com/Automattic/newspack-blocks/commit/22afbbfe9658c1b8510f33f48d444b5f0270ebc3))

# [1.49.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.48.0...v1.49.0-alpha.1) (2022-05-02)


### Features

* **donate:** handle woocommerce-memberships ([#1096](https://github.com/Automattic/newspack-blocks/issues/1096)) ([22afbbf](https://github.com/Automattic/newspack-blocks/commit/22afbbfe9658c1b8510f33f48d444b5f0270ebc3))

# [1.48.0](https://github.com/Automattic/newspack-blocks/compare/v1.47.2...v1.48.0) (2022-04-18)


### Features

* add 3 new subscribe patterns ([#1091](https://github.com/Automattic/newspack-blocks/issues/1091)) ([1d7f688](https://github.com/Automattic/newspack-blocks/commit/1d7f688bec956381c3395ee429d2c85c4a670620))
* **donate-streamlined:** set user ID on Stripe client ([#1095](https://github.com/Automattic/newspack-blocks/issues/1095)) ([e9f4bd5](https://github.com/Automattic/newspack-blocks/commit/e9f4bd54fd577e2afb7bdf1456be48eeb9a95ac8))
* publish on npm when releasing ([e77fdf6](https://github.com/Automattic/newspack-blocks/commit/e77fdf618fd9cbfba1285952e52a89044edc93a2)), closes [#849](https://github.com/Automattic/newspack-blocks/issues/849)

# [1.48.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.47.2...v1.48.0-alpha.1) (2022-04-14)


### Features

* add 3 new subscribe patterns ([#1091](https://github.com/Automattic/newspack-blocks/issues/1091)) ([1d7f688](https://github.com/Automattic/newspack-blocks/commit/1d7f688bec956381c3395ee429d2c85c4a670620))
* **donate-streamlined:** set user ID on Stripe client ([#1095](https://github.com/Automattic/newspack-blocks/issues/1095)) ([e9f4bd5](https://github.com/Automattic/newspack-blocks/commit/e9f4bd54fd577e2afb7bdf1456be48eeb9a95ac8))
* publish on npm when releasing ([e77fdf6](https://github.com/Automattic/newspack-blocks/commit/e77fdf618fd9cbfba1285952e52a89044edc93a2)), closes [#849](https://github.com/Automattic/newspack-blocks/issues/849)

## [1.47.2](https://github.com/Automattic/newspack-blocks/compare/v1.47.1...v1.47.2) (2022-04-07)


### Bug Fixes

* force release ([af2c2ba](https://github.com/Automattic/newspack-blocks/commit/af2c2ba7279a6aa083deb2ed41dd00c50ae2b5ea))
* restore post__in sort order for specific posts mode ([#1093](https://github.com/Automattic/newspack-blocks/issues/1093)) ([84dfa8f](https://github.com/Automattic/newspack-blocks/commit/84dfa8f637e12ff6c9cd31ee13cfe727715c308a))

## [1.47.2-hotfix.1](https://github.com/Automattic/newspack-blocks/compare/v1.47.1...v1.47.2-hotfix.1) (2022-04-07)


### Bug Fixes

* restore post__in sort order for specific posts mode ([a7e52e7](https://github.com/Automattic/newspack-blocks/commit/a7e52e757d0aec56e3300995ad0f5fdd4a199959))

## [1.47.1](https://github.com/Automattic/newspack-blocks/compare/v1.47.0...v1.47.1) (2022-04-07)


### Bug Fixes

* **posts-query:** specific posts getting ([#1090](https://github.com/Automattic/newspack-blocks/issues/1090)) ([231d62d](https://github.com/Automattic/newspack-blocks/commit/231d62d95a66986ad8f6e1490ee9324045b3416e))

## [1.47.1-hotfix.1](https://github.com/Automattic/newspack-blocks/compare/v1.47.0...v1.47.1-hotfix.1) (2022-04-07)


### Bug Fixes

* **posts-query:** specific posts getting ([f9326db](https://github.com/Automattic/newspack-blocks/commit/f9326dbe931522f4e7c66b44d8aee3ed095a378a))

# [1.47.0](https://github.com/Automattic/newspack-blocks/compare/v1.46.1...v1.47.0) (2022-04-05)


### Bug Fixes

* **author-list:** handle alphabetizing last names containing spaces ([#1057](https://github.com/Automattic/newspack-blocks/issues/1057)) ([1af7e19](https://github.com/Automattic/newspack-blocks/commit/1af7e198fcd2abe189ef61f7d206451a8ca0512d))
* authors and guest authors in homepage posts ([#1083](https://github.com/Automattic/newspack-blocks/issues/1083)) ([038d9a4](https://github.com/Automattic/newspack-blocks/commit/038d9a42a59a84d30dbba5f6d7f4036113bbc2e8))
* donate block non-default styles grid breakpoint ([#1078](https://github.com/Automattic/newspack-blocks/issues/1078)) ([5450e57](https://github.com/Automattic/newspack-blocks/commit/5450e5775dbadb28020a49090533c3dd4ddc82da))
* **homepage-posts:** post fetch for widget blocks ([#1066](https://github.com/Automattic/newspack-blocks/issues/1066)) ([3734a3f](https://github.com/Automattic/newspack-blocks/commit/3734a3f2e8db788dc493b84100fcc7aaf7db1733))
* incorrect nesting of tax_query args ([#1087](https://github.com/Automattic/newspack-blocks/issues/1087)) ([a613874](https://github.com/Automattic/newspack-blocks/commit/a613874a398ffed6cfbeb699a3d49c95bb559a5a))


### Features

* add alternate and minimal styles to donate block ([#1068](https://github.com/Automattic/newspack-blocks/issues/1068)) ([3c914d2](https://github.com/Automattic/newspack-blocks/commit/3c914d2a5e95b62834fd27670f449d7c34686ab2))

# [1.47.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.47.0-alpha.1...v1.47.0-alpha.2) (2022-04-05)


### Bug Fixes

* incorrect nesting of tax_query args ([#1087](https://github.com/Automattic/newspack-blocks/issues/1087)) ([a613874](https://github.com/Automattic/newspack-blocks/commit/a613874a398ffed6cfbeb699a3d49c95bb559a5a))

# [1.47.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.46.1...v1.47.0-alpha.1) (2022-03-31)


### Bug Fixes

* **author-list:** handle alphabetizing last names containing spaces ([#1057](https://github.com/Automattic/newspack-blocks/issues/1057)) ([1af7e19](https://github.com/Automattic/newspack-blocks/commit/1af7e198fcd2abe189ef61f7d206451a8ca0512d))
* authors and guest authors in homepage posts ([#1083](https://github.com/Automattic/newspack-blocks/issues/1083)) ([038d9a4](https://github.com/Automattic/newspack-blocks/commit/038d9a42a59a84d30dbba5f6d7f4036113bbc2e8))
* donate block non-default styles grid breakpoint ([#1078](https://github.com/Automattic/newspack-blocks/issues/1078)) ([5450e57](https://github.com/Automattic/newspack-blocks/commit/5450e5775dbadb28020a49090533c3dd4ddc82da))
* **homepage-posts:** post fetch for widget blocks ([#1066](https://github.com/Automattic/newspack-blocks/issues/1066)) ([3734a3f](https://github.com/Automattic/newspack-blocks/commit/3734a3f2e8db788dc493b84100fcc7aaf7db1733))


### Features

* add alternate and minimal styles to donate block ([#1068](https://github.com/Automattic/newspack-blocks/issues/1068)) ([3c914d2](https://github.com/Automattic/newspack-blocks/commit/3c914d2a5e95b62834fd27670f449d7c34686ab2))

## [1.46.1](https://github.com/Automattic/newspack-blocks/compare/v1.46.0...v1.46.1) (2022-03-22)


### Bug Fixes

* add alignment classes to iframe block ([#1050](https://github.com/Automattic/newspack-blocks/issues/1050)) ([854d7cc](https://github.com/Automattic/newspack-blocks/commit/854d7cc355f94dbe36319bd9984e48c4a9b5f324))
* handle inactive newspack-popups ([#1056](https://github.com/Automattic/newspack-blocks/issues/1056)) ([cb894b3](https://github.com/Automattic/newspack-blocks/commit/cb894b3c0f9260a561b4366eb14431bd16efdec1))
* update listings class name to match after refactor ([#1064](https://github.com/Automattic/newspack-blocks/issues/1064)) ([ac87922](https://github.com/Automattic/newspack-blocks/commit/ac87922c44f04d02105642a08afc7dad23b142dc))

## [1.46.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.46.0...v1.46.1-alpha.1) (2022-03-15)


### Bug Fixes

* add alignment classes to iframe block ([#1050](https://github.com/Automattic/newspack-blocks/issues/1050)) ([854d7cc](https://github.com/Automattic/newspack-blocks/commit/854d7cc355f94dbe36319bd9984e48c4a9b5f324))
* handle inactive newspack-popups ([#1056](https://github.com/Automattic/newspack-blocks/issues/1056)) ([cb894b3](https://github.com/Automattic/newspack-blocks/commit/cb894b3c0f9260a561b4366eb14431bd16efdec1))
* update listings class name to match after refactor ([#1064](https://github.com/Automattic/newspack-blocks/issues/1064)) ([ac87922](https://github.com/Automattic/newspack-blocks/commit/ac87922c44f04d02105642a08afc7dad23b142dc))

# [1.46.0](https://github.com/Automattic/newspack-blocks/compare/v1.45.3...v1.46.0) (2022-03-08)


### Bug Fixes

* disable ngg shortcode manager ([#1045](https://github.com/Automattic/newspack-blocks/issues/1045)) ([af5e516](https://github.com/Automattic/newspack-blocks/commit/af5e516bf6ec6c56887979918cbc6a277e1ce974))


### Features

* **donate-block:** stripe payment request button ([#1035](https://github.com/Automattic/newspack-blocks/issues/1035)) ([3d5c273](https://github.com/Automattic/newspack-blocks/commit/3d5c27331f68e54618e6886ebac284bf197478b9))

# [1.46.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.45.3...v1.46.0-alpha.1) (2022-02-24)


### Bug Fixes

* disable ngg shortcode manager ([#1045](https://github.com/Automattic/newspack-blocks/issues/1045)) ([af5e516](https://github.com/Automattic/newspack-blocks/commit/af5e516bf6ec6c56887979918cbc6a277e1ce974))


### Features

* **donate-block:** stripe payment request button ([#1035](https://github.com/Automattic/newspack-blocks/issues/1035)) ([3d5c273](https://github.com/Automattic/newspack-blocks/commit/3d5c27331f68e54618e6886ebac284bf197478b9))

## [1.45.3](https://github.com/Automattic/newspack-blocks/compare/v1.45.2...v1.45.3) (2022-02-22)


### Bug Fixes

* **carousel:** width issues ([a15ba38](https://github.com/Automattic/newspack-blocks/commit/a15ba38143a5f53d1cbb0c8d30ce6d3dc07d2fd8)), closes [#504](https://github.com/Automattic/newspack-blocks/issues/504)

## [1.45.3-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.45.2...v1.45.3-alpha.1) (2022-02-10)


### Bug Fixes

* **carousel:** width issues ([a15ba38](https://github.com/Automattic/newspack-blocks/commit/a15ba38143a5f53d1cbb0c8d30ce6d3dc07d2fd8)), closes [#504](https://github.com/Automattic/newspack-blocks/issues/504)

## [1.45.2](https://github.com/Automattic/newspack-blocks/compare/v1.45.1...v1.45.2) (2022-02-08)


### Bug Fixes

* handle unavailable Newspack plugin ([#1026](https://github.com/Automattic/newspack-blocks/issues/1026)) ([b9350e7](https://github.com/Automattic/newspack-blocks/commit/b9350e7938c1771b9cc5b9b5f4a2c44b960b20bb))

## [1.45.2-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.45.1...v1.45.2-alpha.1) (2022-01-27)


### Bug Fixes

* handle unavailable Newspack plugin ([#1026](https://github.com/Automattic/newspack-blocks/issues/1026)) ([b9350e7](https://github.com/Automattic/newspack-blocks/commit/b9350e7938c1771b9cc5b9b5f4a2c44b960b20bb))

## [1.45.1](https://github.com/Automattic/newspack-blocks/compare/v1.45.0...v1.45.1) (2022-01-25)


### Bug Fixes

* newspack-scripts usage ([af1b7d0](https://github.com/Automattic/newspack-blocks/commit/af1b7d00c70dc2660113221d3c3f49bcc8b7d558))
* **video-playlist:** youtube embeds fetching ([5a6cd75](https://github.com/Automattic/newspack-blocks/commit/5a6cd7533008915bf8fcf9bb04d55ce0dee27ba7)), closes [#577](https://github.com/Automattic/newspack-blocks/issues/577)

## [1.45.1-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.45.1-alpha.1...v1.45.1-alpha.2) (2022-01-25)


### Bug Fixes

* newspack-scripts usage ([af1b7d0](https://github.com/Automattic/newspack-blocks/commit/af1b7d00c70dc2660113221d3c3f49bcc8b7d558))

## [1.45.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.45.0...v1.45.1-alpha.1) (2022-01-24)


### Bug Fixes

* **video-playlist:** youtube embeds fetching ([5a6cd75](https://github.com/Automattic/newspack-blocks/commit/5a6cd7533008915bf8fcf9bb04d55ce0dee27ba7)), closes [#577](https://github.com/Automattic/newspack-blocks/issues/577)

# [1.45.0](https://github.com/Automattic/newspack-blocks/compare/v1.44.1...v1.45.0) (2022-01-19)


### Bug Fixes

* add Author List block to manifest of blocks to build in production ([#1011](https://github.com/Automattic/newspack-blocks/issues/1011)) ([abd5b7c](https://github.com/Automattic/newspack-blocks/commit/abd5b7cbb18865f3d76259c185b5d70940abc6d9))
* add simplified CSS class to carousel to reduce style size ([#983](https://github.com/Automattic/newspack-blocks/issues/983)) ([f8b1b0a](https://github.com/Automattic/newspack-blocks/commit/f8b1b0a093a4b8530e0984e197b47df182388551))
* **author-list:** user query when CAP is not installed ([#1015](https://github.com/Automattic/newspack-blocks/issues/1015)) ([e798d9b](https://github.com/Automattic/newspack-blocks/commit/e798d9b167bff934c8ec7e01bf462ed9cf790cf1))
* force alpha rebuild ([1e55132](https://github.com/Automattic/newspack-blocks/commit/1e551329b16ae68d28959eaaabdfd5e3ef7e505f))
* lint errors ([#1010](https://github.com/Automattic/newspack-blocks/issues/1010)) ([c50a1f5](https://github.com/Automattic/newspack-blocks/commit/c50a1f57c6ef8fd1bdb815c763be37d0e6235587))
* re-add space between stacked columns ([#1022](https://github.com/Automattic/newspack-blocks/issues/1022)) ([d560199](https://github.com/Automattic/newspack-blocks/commit/d560199d0e568a20eaab24cb8131cdeaa14ea9a7))
* update columns styles for WordPress 5.9 ([#1020](https://github.com/Automattic/newspack-blocks/issues/1020)) ([a38ce32](https://github.com/Automattic/newspack-blocks/commit/a38ce32ce3c6f9a199a7d16b6bcbb40d49bdae97))
* update Subscribe patterns for WordPress 5.9 ([#1017](https://github.com/Automattic/newspack-blocks/issues/1017)) ([5cef283](https://github.com/Automattic/newspack-blocks/commit/5cef28393db292e01d4916be062a0a261ea6d905))


### Features

* author list block ([#947](https://github.com/Automattic/newspack-blocks/issues/947)) ([b98b8b8](https://github.com/Automattic/newspack-blocks/commit/b98b8b8dd100e4f247a532fe3ae364617d807f95))
* **donate-stripe:** hide fee checkbox if fees are zero ([#985](https://github.com/Automattic/newspack-blocks/issues/985)) ([04e7892](https://github.com/Automattic/newspack-blocks/commit/04e7892922e39425479c2da37c889fed895f2eda))

# [1.45.0-alpha.7](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.6...v1.45.0-alpha.7) (2022-01-18)


### Bug Fixes

* re-add space between stacked columns ([#1022](https://github.com/Automattic/newspack-blocks/issues/1022)) ([d560199](https://github.com/Automattic/newspack-blocks/commit/d560199d0e568a20eaab24cb8131cdeaa14ea9a7))

# [1.45.0-alpha.6](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.5...v1.45.0-alpha.6) (2022-01-18)


### Bug Fixes

* update columns styles for WordPress 5.9 ([#1020](https://github.com/Automattic/newspack-blocks/issues/1020)) ([a38ce32](https://github.com/Automattic/newspack-blocks/commit/a38ce32ce3c6f9a199a7d16b6bcbb40d49bdae97))
* update Subscribe patterns for WordPress 5.9 ([#1017](https://github.com/Automattic/newspack-blocks/issues/1017)) ([5cef283](https://github.com/Automattic/newspack-blocks/commit/5cef28393db292e01d4916be062a0a261ea6d905))

# [1.45.0-alpha.5](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.4...v1.45.0-alpha.5) (2022-01-14)


### Bug Fixes

* **author-list:** user query when CAP is not installed ([#1015](https://github.com/Automattic/newspack-blocks/issues/1015)) ([e798d9b](https://github.com/Automattic/newspack-blocks/commit/e798d9b167bff934c8ec7e01bf462ed9cf790cf1))

# [1.45.0-alpha.4](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.3...v1.45.0-alpha.4) (2022-01-11)


### Bug Fixes

* add Author List block to manifest of blocks to build in production ([#1011](https://github.com/Automattic/newspack-blocks/issues/1011)) ([abd5b7c](https://github.com/Automattic/newspack-blocks/commit/abd5b7cbb18865f3d76259c185b5d70940abc6d9))

# [1.45.0-alpha.3](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.2...v1.45.0-alpha.3) (2022-01-11)


### Bug Fixes

* force alpha rebuild ([1e55132](https://github.com/Automattic/newspack-blocks/commit/1e551329b16ae68d28959eaaabdfd5e3ef7e505f))

# [1.45.0-alpha.2](https://github.com/Automattic/newspack-blocks/compare/v1.45.0-alpha.1...v1.45.0-alpha.2) (2022-01-10)


### Bug Fixes

* lint errors ([#1010](https://github.com/Automattic/newspack-blocks/issues/1010)) ([c50a1f5](https://github.com/Automattic/newspack-blocks/commit/c50a1f57c6ef8fd1bdb815c763be37d0e6235587))


### Features

* author list block ([#947](https://github.com/Automattic/newspack-blocks/issues/947)) ([b98b8b8](https://github.com/Automattic/newspack-blocks/commit/b98b8b8dd100e4f247a532fe3ae364617d807f95))

# [1.45.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.44.1...v1.45.0-alpha.1) (2022-01-07)


### Bug Fixes

* add simplified CSS class to carousel to reduce style size ([#983](https://github.com/Automattic/newspack-blocks/issues/983)) ([f8b1b0a](https://github.com/Automattic/newspack-blocks/commit/f8b1b0a093a4b8530e0984e197b47df182388551))


### Features

* **donate-stripe:** hide fee checkbox if fees are zero ([#985](https://github.com/Automattic/newspack-blocks/issues/985)) ([04e7892](https://github.com/Automattic/newspack-blocks/commit/04e7892922e39425479c2da37c889fed895f2eda))

## [1.44.1](https://github.com/Automattic/newspack-blocks/compare/v1.44.0...v1.44.1) (2022-01-04)


### Bug Fixes

* validation errors for invalid attributes on amp-base-carousel ([#979](https://github.com/Automattic/newspack-blocks/issues/979)) ([cdce22f](https://github.com/Automattic/newspack-blocks/commit/cdce22f9d1df8ce7ec687e1aa26bc9bfa49660ac))

## [1.44.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.44.0...v1.44.1-alpha.1) (2021-12-16)


### Bug Fixes

* validation errors for invalid attributes on amp-base-carousel ([#979](https://github.com/Automattic/newspack-blocks/issues/979)) ([cdce22f](https://github.com/Automattic/newspack-blocks/commit/cdce22f9d1df8ce7ec687e1aa26bc9bfa49660ac))

# [1.44.0](https://github.com/Automattic/newspack-blocks/compare/v1.43.0...v1.44.0) (2021-12-15)


### Features

* disabling image lazy load for homepage articles ([#970](https://github.com/Automattic/newspack-blocks/issues/970)) ([de68cb2](https://github.com/Automattic/newspack-blocks/commit/de68cb22402dc67725df9cb8ffe3ad34678108a7))

# [1.44.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.43.0...v1.44.0-alpha.1) (2021-12-15)


### Features

* disabling image lazy load for homepage articles ([#970](https://github.com/Automattic/newspack-blocks/issues/970)) ([de68cb2](https://github.com/Automattic/newspack-blocks/commit/de68cb22402dc67725df9cb8ffe3ad34678108a7))

# [1.43.0](https://github.com/Automattic/newspack-blocks/compare/v1.42.3...v1.43.0) (2021-12-14)


### Bug Fixes

* homepage posts block in AMP Plus mode ([#954](https://github.com/Automattic/newspack-blocks/issues/954)) ([8834fb6](https://github.com/Automattic/newspack-blocks/commit/8834fb69aeedb2d98b255f67fad07b6be8f3ee69)), closes [#953](https://github.com/Automattic/newspack-blocks/issues/953)
* update mobile styles for the post carousel ([#946](https://github.com/Automattic/newspack-blocks/issues/946)) ([ea213e9](https://github.com/Automattic/newspack-blocks/commit/ea213e982e12bdd93f0925feb75c07e9878505c9))


### Features

* **donate-block:** defer Stripe scripts loading ([045a867](https://github.com/Automattic/newspack-blocks/commit/045a8671dbca6d2643fcb2c30231a2412f8b6d9d))
* **donate:** agree to pay fees feature ([#928](https://github.com/Automattic/newspack-blocks/issues/928)) ([221c061](https://github.com/Automattic/newspack-blocks/commit/221c061848877da8963cc05c915623cc9fca3245))

# [1.43.0-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.42.3...v1.43.0-alpha.1) (2021-12-09)


### Bug Fixes

* homepage posts block in AMP Plus mode ([#954](https://github.com/Automattic/newspack-blocks/issues/954)) ([8834fb6](https://github.com/Automattic/newspack-blocks/commit/8834fb69aeedb2d98b255f67fad07b6be8f3ee69)), closes [#953](https://github.com/Automattic/newspack-blocks/issues/953)
* update mobile styles for the post carousel ([#946](https://github.com/Automattic/newspack-blocks/issues/946)) ([ea213e9](https://github.com/Automattic/newspack-blocks/commit/ea213e982e12bdd93f0925feb75c07e9878505c9))


### Features

* **donate-block:** defer Stripe scripts loading ([045a867](https://github.com/Automattic/newspack-blocks/commit/045a8671dbca6d2643fcb2c30231a2412f8b6d9d))
* **donate:** agree to pay fees feature ([#928](https://github.com/Automattic/newspack-blocks/issues/928)) ([221c061](https://github.com/Automattic/newspack-blocks/commit/221c061848877da8963cc05c915623cc9fca3245))

## [1.42.3](https://github.com/Automattic/newspack-blocks/compare/v1.42.2...v1.42.3) (2021-12-07)


### Bug Fixes

* **author-profile:** corrected CAP author links; linked WP users ([#908](https://github.com/Automattic/newspack-blocks/issues/908)) ([e4583d1](https://github.com/Automattic/newspack-blocks/commit/e4583d19942aa230eed743c41ea7bb0c193af7e0))

## [1.42.3-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.42.2...v1.42.3-alpha.1) (2021-12-02)


### Bug Fixes

* **author-profile:** corrected CAP author links; linked WP users ([#908](https://github.com/Automattic/newspack-blocks/issues/908)) ([e4583d1](https://github.com/Automattic/newspack-blocks/commit/e4583d19942aa230eed743c41ea7bb0c193af7e0))

## [1.42.2](https://github.com/Automattic/newspack-blocks/compare/v1.42.1...v1.42.2) (2021-11-30)


### Bug Fixes

* add wptexturize to the article subtitle ([#910](https://github.com/Automattic/newspack-blocks/issues/910)) ([1bec09c](https://github.com/Automattic/newspack-blocks/commit/1bec09cd716f2899509ed15938f1b06014bbef21))
* update newspack-components dep version ([#923](https://github.com/Automattic/newspack-blocks/issues/923)) ([7678c75](https://github.com/Automattic/newspack-blocks/commit/7678c75b9b71a7a796aa68d5738203a48669c2a6))

## [1.42.2-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.42.1...v1.42.2-alpha.1) (2021-11-18)


### Bug Fixes

* add wptexturize to the article subtitle ([#910](https://github.com/Automattic/newspack-blocks/issues/910)) ([1bec09c](https://github.com/Automattic/newspack-blocks/commit/1bec09cd716f2899509ed15938f1b06014bbef21))
* update newspack-components dep version ([#923](https://github.com/Automattic/newspack-blocks/issues/923)) ([7678c75](https://github.com/Automattic/newspack-blocks/commit/7678c75b9b71a7a796aa68d5738203a48669c2a6))

## [1.42.1](https://github.com/Automattic/newspack-blocks/compare/v1.42.0...v1.42.1) (2021-11-18)


### Bug Fixes

* **iframe-block:** add a trailing slash to iframe URL ([#906](https://github.com/Automattic/newspack-blocks/issues/906)) ([e4b7b7d](https://github.com/Automattic/newspack-blocks/commit/e4b7b7d1de767ffc1d09af448974f4c2362a5299))

## [1.42.1-alpha.1](https://github.com/Automattic/newspack-blocks/compare/v1.42.0...v1.42.1-alpha.1) (2021-11-16)


### Bug Fixes

* **iframe-block:** add a trailing slash to iframe URL ([#906](https://github.com/Automattic/newspack-blocks/issues/906)) ([e4b7b7d](https://github.com/Automattic/newspack-blocks/commit/e4b7b7d1de767ffc1d09af448974f4c2362a5299))

# [1.42.0](https://github.com/Automattic/newspack-blocks/compare/v1.41.1...v1.42.0) (2021-11-09)


### Features

* **homepage-posts:** enable showing previously rendered posts too ([#896](https://github.com/Automattic/newspack-blocks/issues/896)) ([c8528fc](https://github.com/Automattic/newspack-blocks/commit/c8528fc6631d2c52195f8fb4928b3bd5033328a3))

## [1.41.1](https://github.com/Automattic/newspack-blocks/compare/v1.41.0...v1.41.1) (2021-10-26)


### Bug Fixes

* correct clash between centered text and image behind alignment ([ff50346](https://github.com/Automattic/newspack-blocks/commit/ff503461fbb607193228e60a3efe0c9561f81c50))

# [1.41.0](https://github.com/Automattic/newspack-blocks/compare/v1.40.0...v1.41.0) (2021-10-19)


### Features

* add iframe block ([#859](https://github.com/Automattic/newspack-blocks/issues/859)) ([08a2712](https://github.com/Automattic/newspack-blocks/commit/08a271202a6f3c0dd6b9c0ce1975b8b2c779776c))
* **homepage-posts:** add a filter to enable duplicating posts ([#886](https://github.com/Automattic/newspack-blocks/issues/886)) ([4daf27d](https://github.com/Automattic/newspack-blocks/commit/4daf27d286379521ad4e96bce4618a7d90d3d8d0))

# [1.40.0](https://github.com/Automattic/newspack-blocks/compare/v1.39.1...v1.40.0) (2021-10-05)


### Features

* add text alignment option to the homepage posts block ([514907f](https://github.com/Automattic/newspack-blocks/commit/514907fb9a08ff91b48fafbfac15ffda002a0926))

## [1.39.1](https://github.com/Automattic/newspack-blocks/compare/v1.39.0...v1.39.1) (2021-09-28)


### Bug Fixes

* do not apply the_content filter to homepage post excerpts ([#883](https://github.com/Automattic/newspack-blocks/issues/883)) ([aa9e667](https://github.com/Automattic/newspack-blocks/commit/aa9e6671ed673248b168dbdd69f7a43e1c405c6e))

# [1.39.0](https://github.com/Automattic/newspack-blocks/compare/v1.38.0...v1.39.0) (2021-09-28)


### Bug Fixes

* if a post has a custom excerpt, use it ([#880](https://github.com/Automattic/newspack-blocks/issues/880)) ([57b5cdd](https://github.com/Automattic/newspack-blocks/commit/57b5cdd4fe29c72103d247afd1f7b1830586e4f7))
* limit excerpt lengths even for custom excerpts ([#874](https://github.com/Automattic/newspack-blocks/issues/874)) ([85f5bf4](https://github.com/Automattic/newspack-blocks/commit/85f5bf47bd18e8493fbfd96d168e518f63f808c3))


### Features

* **donate:** remove NRH metadata ([080430c](https://github.com/Automattic/newspack-blocks/commit/080430ccf87f98f85595b4ede74af5e3821c5ca4))

# [1.38.0](https://github.com/Automattic/newspack-blocks/compare/v1.37.1...v1.38.0) (2021-09-21)


### Features

* add a filter on terms so other plugins can add classes ([#872](https://github.com/Automattic/newspack-blocks/issues/872)) ([28c0fde](https://github.com/Automattic/newspack-blocks/commit/28c0fde9a7b8875acee247e7f2e59a872f5d1d39))
* allow some html in the homepage posts subtitle ([#869](https://github.com/Automattic/newspack-blocks/issues/869)) ([82cea66](https://github.com/Automattic/newspack-blocks/commit/82cea66f948f6197b322c1783c3fc02e0230915d))
* disable Jetpack donations when using Newspack donations ([#875](https://github.com/Automattic/newspack-blocks/issues/875)) ([54ad5b7](https://github.com/Automattic/newspack-blocks/commit/54ad5b70d224725d268a57b48dff80e9ff80ff18))

## [1.37.1](https://github.com/Automattic/newspack-blocks/compare/v1.37.0...v1.37.1) (2021-09-09)


### Bug Fixes

* specify whitespace between author prefix and byline ([#866](https://github.com/Automattic/newspack-blocks/issues/866)) ([a91ffb7](https://github.com/Automattic/newspack-blocks/commit/a91ffb7050bd2089b48bc3e7d7e16f7d0dcac7db))

# [1.37.0](https://github.com/Automattic/newspack-blocks/compare/v1.36.0...v1.37.0) (2021-09-08)


### Bug Fixes

* currency symbol handling ([#850](https://github.com/Automattic/newspack-blocks/issues/850)) ([9637d89](https://github.com/Automattic/newspack-blocks/commit/9637d896eb4b9bc16d05b141b72d0ab22eb73737))
* only show category link anchor tags if the category has a link ([#863](https://github.com/Automattic/newspack-blocks/issues/863)) ([549f03a](https://github.com/Automattic/newspack-blocks/commit/549f03a41c531c5580a0bf7af34b734a0db2c719))
* show all saved post types in specific posts field ([#857](https://github.com/Automattic/newspack-blocks/issues/857)) ([42c25a9](https://github.com/Automattic/newspack-blocks/commit/42c25a94f2a210ea054dc9550ae5e0b42fdbc546))


### Features

* make Donate Block 'thank you' text editable ([#854](https://github.com/Automattic/newspack-blocks/issues/854)) ([7e8f1ea](https://github.com/Automattic/newspack-blocks/commit/7e8f1ea46fd95fdc38601fee6be4fd031a7c1a5a))

# [1.36.0](https://github.com/Automattic/newspack-blocks/compare/v1.35.0...v1.36.0) (2021-08-31)


### Features

* **donate:** handle newsletter opt-in ([#839](https://github.com/Automattic/newspack-blocks/issues/839)) ([3e36d90](https://github.com/Automattic/newspack-blocks/commit/3e36d900847bbb611e6e9b588d018e0800952822))
* update author profile icon and fix loading alignment ([#848](https://github.com/Automattic/newspack-blocks/issues/848)) ([040b145](https://github.com/Automattic/newspack-blocks/commit/040b1452c9a7da8e91102fd8c1182f248eb6d96b))
* **donate:** donor name input; initial form hiding ([7ab2854](https://github.com/Automattic/newspack-blocks/commit/7ab2854723db12e493d707a07add1cdabe38a595))

# [1.35.0](https://github.com/Automattic/newspack-blocks/compare/v1.34.0...v1.35.0) (2021-08-25)


### Features

* add span to 'by' in Homepage Posts, Post Carousel bylines ([#843](https://github.com/Automattic/newspack-blocks/issues/843)) ([fc82a19](https://github.com/Automattic/newspack-blocks/commit/fc82a19afd41faec47cc3577009835fb98679039))
* **donate-block:** streamlined handling ([#835](https://github.com/Automattic/newspack-blocks/issues/835)) ([b9acb40](https://github.com/Automattic/newspack-blocks/commit/b9acb40d86f9c65db1bc51a5bab5365b0c199e58))

# [1.34.0](https://github.com/Automattic/newspack-blocks/compare/v1.33.0...v1.34.0) (2021-08-17)


### Bug Fixes

* **co-authors plus:** add co-authors in the authors filter list ([e598259](https://github.com/Automattic/newspack-blocks/commit/e5982596b2f2f5b920909bafed4c6c2e6da2767a)), closes [#601](https://github.com/Automattic/newspack-blocks/issues/601)
* fix a bad copy/paste ([c2afde1](https://github.com/Automattic/newspack-blocks/commit/c2afde19128cf80f9b25fc280eaafb5729a47479))
* **co-authors plus:** filter homepage posts block posts by co-authors ([7f7e65a](https://github.com/Automattic/newspack-blocks/commit/7f7e65ab084f839364bf2ce010bcbef5d9931f61)), closes [#831](https://github.com/Automattic/newspack-blocks/issues/831)


### Features

* **donate:** handle recurring donations ([#829](https://github.com/Automattic/newspack-blocks/issues/829)) ([50039c6](https://github.com/Automattic/newspack-blocks/commit/50039c6c7f28391293935b8b353faa74bb36b7df))

# [1.33.0](https://github.com/Automattic/newspack-blocks/compare/v1.32.0...v1.33.0) (2021-08-10)


### Bug Fixes

* select specific posts with white space at the end of the title ([a0c0075](https://github.com/Automattic/newspack-blocks/commit/a0c007532aee151c2e3b093573ecc1f3a7e8b279))
* **homepage posts:** fix inconsistent column sizing when border enabled ([361fed5](https://github.com/Automattic/newspack-blocks/commit/361fed5ae8ef5a41d63845dad387e00b3776ebc6)), closes [#825](https://github.com/Automattic/newspack-blocks/issues/825)
* **sponsor logo:** fix sponsor logo accessibility ([901db8c](https://github.com/Automattic/newspack-blocks/commit/901db8c2402d6f3add2c7221c68461355748d162)), closes [#678](https://github.com/Automattic/newspack-blocks/issues/678)


### Features

* **homepage-posts:** handle category exclusion ([45a3001](https://github.com/Automattic/newspack-blocks/commit/45a3001cab4b19ce6f638258180355003c02018f)), closes [#600](https://github.com/Automattic/newspack-blocks/issues/600)

# [1.32.0](https://github.com/Automattic/newspack-blocks/compare/v1.31.0...v1.32.0) (2021-08-03)


### Features

* show term classes for Post Carousel blocks ([#819](https://github.com/Automattic/newspack-blocks/issues/819)) ([ca93168](https://github.com/Automattic/newspack-blocks/commit/ca9316898ae699db8bde5a43a384a5d82c3143ab))

# [1.31.0](https://github.com/Automattic/newspack-blocks/compare/v1.30.0...v1.31.0) (2021-07-27)


### Features

* **donate-streamlined:** add Client ID to payment meta ([#806](https://github.com/Automattic/newspack-blocks/issues/806)) ([181e5b7](https://github.com/Automattic/newspack-blocks/commit/181e5b7833d796e852da917c96cdf714dd5b5d56))

# [1.30.0](https://github.com/Automattic/newspack-blocks/compare/v1.29.2...v1.30.0) (2021-07-19)


### Bug Fixes

* remove autoload dependency from donation class ([#811](https://github.com/Automattic/newspack-blocks/issues/811)) ([c37c885](https://github.com/Automattic/newspack-blocks/commit/c37c885e0dcf1d36a72d70168cfa7b60d0a66d6d))
* **class-newspack-blocks.php:** fixes assets meta file path for scripts ([#780](https://github.com/Automattic/newspack-blocks/issues/780)) ([a37ff23](https://github.com/Automattic/newspack-blocks/commit/a37ff23c18d7c63f9a947d5b7592852bc2f0dbe0)), closes [#779](https://github.com/Automattic/newspack-blocks/issues/779)
* allow swiper to reinitialize carousel on attribute changes ([#807](https://github.com/Automattic/newspack-blocks/issues/807)) ([cc9fa30](https://github.com/Automattic/newspack-blocks/commit/cc9fa30d612daeaff0ecbc715aea759e442870ec))
* do not initialize swiper instances if component is hidden ([#804](https://github.com/Automattic/newspack-blocks/issues/804)) ([fe599e1](https://github.com/Automattic/newspack-blocks/commit/fe599e1061bd37c763603e8d8324ca0f21ea1c42))
* **carousel:** preview performance ([#803](https://github.com/Automattic/newspack-blocks/issues/803)) ([e5fc989](https://github.com/Automattic/newspack-blocks/commit/e5fc989d50eaf50e379c35bec4fe741b11d12387))


### Features

* **donate:** streamlined block option w/ Stripe ([#784](https://github.com/Automattic/newspack-blocks/issues/784)) ([10bfb0b](https://github.com/Automattic/newspack-blocks/commit/10bfb0bdcea23e31d190b31631d1a30911e955a7))
* **nrh:** donations handling ([#805](https://github.com/Automattic/newspack-blocks/issues/805)) ([821f6db](https://github.com/Automattic/newspack-blocks/commit/821f6db1ae9c4ed0eed043598e4689e886e1416e))

## [1.29.2](https://github.com/Automattic/newspack-blocks/compare/v1.29.1...v1.29.2) (2021-07-13)


### Bug Fixes

* correct path to placeholder image ([#791](https://github.com/Automattic/newspack-blocks/issues/791)) ([e4f1e47](https://github.com/Automattic/newspack-blocks/commit/e4f1e47aafe7fc382de278f1820f248b45b1d10f))
* height of post carousel blocks in editor preview ([fc69708](https://github.com/Automattic/newspack-blocks/commit/fc69708e08118f79b5d27ab27659962219798dcb))
* make carousel slide image width styles more specific ([#792](https://github.com/Automattic/newspack-blocks/issues/792)) ([9cc4bd1](https://github.com/Automattic/newspack-blocks/commit/9cc4bd15a11f6d227edfceb17899e6773a0b4ec6))
* override the default carousel arrows from swiper ([#794](https://github.com/Automattic/newspack-blocks/issues/794)) ([2e4e735](https://github.com/Automattic/newspack-blocks/commit/2e4e7357e2aa29eb9958e283d4991c7776e4c8cd))

## [1.29.1](https://github.com/Automattic/newspack-blocks/compare/v1.29.0...v1.29.1) (2021-07-06)


### Bug Fixes

* add max width to avatar placeholder ([#786](https://github.com/Automattic/newspack-blocks/issues/786)) ([99f0535](https://github.com/Automattic/newspack-blocks/commit/99f05356e0cda1b5da233caab03101b0f192ec4c))
* add paragraph markup to excerpt, content placeholders ([#785](https://github.com/Automattic/newspack-blocks/issues/785)) ([48b43eb](https://github.com/Automattic/newspack-blocks/commit/48b43ebdc61205e98c9d18fa24234ed850fae855))

# [1.29.0](https://github.com/Automattic/newspack-blocks/compare/v1.28.0...v1.29.0) (2021-06-15)


### Features

* add avatar alignment option to author profile block ([#773](https://github.com/Automattic/newspack-blocks/issues/773)) ([9322b01](https://github.com/Automattic/newspack-blocks/commit/9322b0165f72aa64e26e068319af9332a8174c5b))
* add centered style to author profile block ([#774](https://github.com/Automattic/newspack-blocks/issues/774)) ([602b5d4](https://github.com/Automattic/newspack-blocks/commit/602b5d47b8a18d9746abd71d954a9b30e62d7fa5))
* blog posts block force image to be the right size when used behind ([#768](https://github.com/Automattic/newspack-blocks/issues/768)) ([d8dd2a9](https://github.com/Automattic/newspack-blocks/commit/d8dd2a98d89bbc3addc43b41a03ad263c3889fd5))

# [1.28.0](https://github.com/Automattic/newspack-blocks/compare/v1.27.0...v1.28.0) (2021-06-08)


### Bug Fixes

* include author profile block in release packages ([91200de](https://github.com/Automattic/newspack-blocks/commit/91200de8cb5e00de06c280f95ac3e79d5b44be46))


### Features

* author profile block ([#763](https://github.com/Automattic/newspack-blocks/issues/763)) ([2cb2249](https://github.com/Automattic/newspack-blocks/commit/2cb2249f42b7d23e2cea55bee583df0e96477185))

# [1.27.0](https://github.com/Automattic/newspack-blocks/compare/v1.26.0...v1.27.0) (2021-06-02)


### Features

* support Newspack Listings hide entry meta options ([#762](https://github.com/Automattic/newspack-blocks/issues/762)) ([217f5ce](https://github.com/Automattic/newspack-blocks/commit/217f5cecfe2d8627317f5987340f5f7076e271c3))

# [1.26.0](https://github.com/Automattic/newspack-blocks/compare/v1.25.1...v1.26.0) (2021-05-13)


### Features

* add carousel block attribute for slidesPerView ([#750](https://github.com/Automattic/newspack-blocks/issues/750)) ([fd41ab9](https://github.com/Automattic/newspack-blocks/commit/fd41ab9a4716a2d555eb9393f3b11ba94d9e686f))

## [1.25.1](https://github.com/Automattic/newspack-blocks/compare/v1.25.0...v1.25.1) (2021-05-05)


### Bug Fixes

* **carousel:** add missing block attribute ([#754](https://github.com/Automattic/newspack-blocks/issues/754)) ([2fcbec4](https://github.com/Automattic/newspack-blocks/commit/2fcbec469fe0df7db2afd39a7cba54ff9079d494))

# [1.25.0](https://github.com/Automattic/newspack-blocks/compare/v1.24.0...v1.25.0) (2021-05-05)


### Features

* allow different post types in carousel; plus new block options ([#746](https://github.com/Automattic/newspack-blocks/issues/746)) ([f30a5a5](https://github.com/Automattic/newspack-blocks/commit/f30a5a5a33560de4385cf0a1e6724da11f744564))

# [1.24.0](https://github.com/Automattic/newspack-blocks/compare/v1.23.0...v1.24.0) (2021-04-13)


### Features

* update block icons to use Gutenberg style instead of Material ([#724](https://github.com/Automattic/newspack-blocks/issues/724)) ([e8a0eae](https://github.com/Automattic/newspack-blocks/commit/e8a0eae31f2a4ecae2ca6bee62986fa4dc419d27))

# [1.23.0](https://github.com/Automattic/newspack-blocks/compare/v1.22.1...v1.23.0) (2021-04-06)


### Features

* add new patterns for donation and subscribe ([#730](https://github.com/Automattic/newspack-blocks/issues/730)) ([a00cfd6](https://github.com/Automattic/newspack-blocks/commit/a00cfd6e54258afacdb8c5bb68152f38dbc04761))
* deduplication across homepage posts & carousel ([#725](https://github.com/Automattic/newspack-blocks/issues/725)) ([427c4e0](https://github.com/Automattic/newspack-blocks/commit/427c4e087d57dee1fba9f4d81ffc8486f735d2c0)), closes [#664](https://github.com/Automattic/newspack-blocks/issues/664)
* handle posts with no featured image ([#731](https://github.com/Automattic/newspack-blocks/issues/731)) ([0db34be](https://github.com/Automattic/newspack-blocks/commit/0db34be070f842acdc69f4b64212dcc2df4be2d8)), closes [#443](https://github.com/Automattic/newspack-blocks/issues/443)

## [1.22.1](https://github.com/Automattic/newspack-blocks/compare/v1.22.0...v1.22.1) (2021-03-30)


### Bug Fixes

* a PHP warning when editing newsletters ([#722](https://github.com/Automattic/newspack-blocks/issues/722)) ([41ce40e](https://github.com/Automattic/newspack-blocks/commit/41ce40e2e7843bb8a9bc58db7a37886eb86f84cc))

# [1.22.0](https://github.com/Automattic/newspack-blocks/compare/v1.21.1...v1.22.0) (2021-03-23)


### Features

* add new homepage posts patterns ([#717](https://github.com/Automattic/newspack-blocks/issues/717)) ([7957a9a](https://github.com/Automattic/newspack-blocks/commit/7957a9aed1f87e6572d22919b40315ef8fa84e61))

## [1.21.1](https://github.com/Automattic/newspack-blocks/compare/v1.21.0...v1.21.1) (2021-02-25)


### Bug Fixes

* update donate thank you message in Portuguese ([#706](https://github.com/Automattic/newspack-blocks/issues/706)) ([93c9da5](https://github.com/Automattic/newspack-blocks/commit/93c9da5a05f7666c1d7d4ef625ad252a00d7c14e))

# [1.21.0](https://github.com/Automattic/newspack-blocks/compare/v1.20.1...v1.21.0) (2021-02-16)


### Features

* add Portuguese translation to the blocks ([#698](https://github.com/Automattic/newspack-blocks/issues/698)) ([dedf0a3](https://github.com/Automattic/newspack-blocks/commit/dedf0a3bb2d9e898203c9a6c489aa550d7c66635))

## [1.20.1](https://github.com/Automattic/newspack-blocks/compare/v1.20.0...v1.20.1) (2021-02-11)


### Bug Fixes

* override WooCommerce Memberships excerpt length in block ([#694](https://github.com/Automattic/newspack-blocks/issues/694)) ([5695de6](https://github.com/Automattic/newspack-blocks/commit/5695de651fe6abda90506315d0a4eceb6091234c))

# [1.20.0](https://github.com/Automattic/newspack-blocks/compare/v1.19.0...v1.20.0) (2021-02-05)


### Features

* pass attributes to donate block render hook ([#671](https://github.com/Automattic/newspack-blocks/issues/671)) ([2d1ae81](https://github.com/Automattic/newspack-blocks/commit/2d1ae8115b911aab54b7aa692d7c873e55d69a25))

# [1.19.0](https://github.com/Automattic/newspack-blocks/compare/v1.18.1...v1.19.0) (2021-01-28)


### Bug Fixes

* carousel block renders authors like homepage articles ([#680](https://github.com/Automattic/newspack-blocks/issues/680)) ([0b574c7](https://github.com/Automattic/newspack-blocks/commit/0b574c7da03a91262c2cd529139db74a32f9dad0))
* in specific posts mode, post order should match input order ([#681](https://github.com/Automattic/newspack-blocks/issues/681)) ([1cfea8d](https://github.com/Automattic/newspack-blocks/commit/1cfea8de49bb80d7abce05b60240792fcc67a587))


### Features

* add article class for post types ([#679](https://github.com/Automattic/newspack-blocks/issues/679)) ([f493a01](https://github.com/Automattic/newspack-blocks/commit/f493a017796c9573ca8f70925e9f4c97ca740727))

## [1.18.1](https://github.com/Automattic/newspack-blocks/compare/v1.18.0...v1.18.1) (2021-01-19)


### Bug Fixes

* load more button functionality ([#672](https://github.com/Automattic/newspack-blocks/issues/672)) ([4116df5](https://github.com/Automattic/newspack-blocks/commit/4116df5f3bbe303866a7b0dde8807f838c374b15))
* **homepage-post:** handle duplicated categories ([57b6323](https://github.com/Automattic/newspack-blocks/commit/57b6323d32dd6aa96c83f07cc4e96598c009ec7e)), closes [#669](https://github.com/Automattic/newspack-blocks/issues/669)

# [1.18.0](https://github.com/Automattic/newspack-blocks/compare/v1.17.1...v1.18.0) (2021-01-12)


### Features

* add optional 'continue reading' link underneath excerpts ([#656](https://github.com/Automattic/newspack-blocks/issues/656)) ([3c55a69](https://github.com/Automattic/newspack-blocks/commit/3c55a69d23d17c2cfb5e26385ffd27b0e31ba168))
* custom endpoint for homepage posts in editor ([#661](https://github.com/Automattic/newspack-blocks/issues/661)) ([1f4880a](https://github.com/Automattic/newspack-blocks/commit/1f4880ad57e78098b719dc308f2358d5b84a0d13))

## [1.17.1](https://github.com/Automattic/newspack-blocks/compare/v1.17.0...v1.17.1) (2020-12-15)


### Bug Fixes

* **api:** fallback to standard if post_format is false ([#649](https://github.com/Automattic/newspack-blocks/issues/649)) ([77028aa](https://github.com/Automattic/newspack-blocks/commit/77028aa23a96325398d437a2461a864641034b9b))
* respect excerpt length attribute in load more display ([#648](https://github.com/Automattic/newspack-blocks/issues/648)) ([aef87ba](https://github.com/Automattic/newspack-blocks/commit/aef87baeebc46e9068855eec62b74c14a20f2619))
* sanitize post id arrays in includes and excludes ([#651](https://github.com/Automattic/newspack-blocks/issues/651)) ([b1b50bd](https://github.com/Automattic/newspack-blocks/commit/b1b50bd6e35bb19103c5076703866a92d2770930))

# [1.17.0](https://github.com/Automattic/newspack-blocks/compare/v1.16.0...v1.17.0) (2020-12-09)


### Bug Fixes

* disable paging in specific posts mode ([#640](https://github.com/Automattic/newspack-blocks/issues/640)) ([9ea01e5](https://github.com/Automattic/newspack-blocks/commit/9ea01e55d0621687c26bf044331bfcaa4eb59b87))


### Features

* control to select default tab in donate block ([#643](https://github.com/Automattic/newspack-blocks/issues/643)) ([46c730b](https://github.com/Automattic/newspack-blocks/commit/46c730b1f5af5732876cb66236993b2800b43199))

# [1.16.0](https://github.com/Automattic/newspack-blocks/compare/v1.15.0...v1.16.0) (2020-11-24)


### Features

* optimize sponsor queries ([#633](https://github.com/Automattic/newspack-blocks/issues/633)) ([1509951](https://github.com/Automattic/newspack-blocks/commit/150995197e39ade7bb3f71cbf6325b6fa60fd2f8))

# [1.15.0](https://github.com/Automattic/newspack-blocks/compare/v1.14.0...v1.15.0) (2020-11-17)


### Features

* Add French (Belgium) translation to the blocks ([#626](https://github.com/Automattic/newspack-blocks/issues/626)) ([e2f9dc6](https://github.com/Automattic/newspack-blocks/commit/e2f9dc62ddab5f5c146d83ced822fb2edbc16482))

# [1.14.0](https://github.com/Automattic/newspack-blocks/compare/v1.13.2...v1.14.0) (2020-11-11)


### Features

* add option to change excerpt length ([#607](https://github.com/Automattic/newspack-blocks/issues/607)) ([a1968d9](https://github.com/Automattic/newspack-blocks/commit/a1968d905cb2117a35ad969804d685076fb292e4))
* update blocks icons style and colour ([#615](https://github.com/Automattic/newspack-blocks/issues/615)) ([f4d68cb](https://github.com/Automattic/newspack-blocks/commit/f4d68cb74210e28cec17deec1d16b35ede68e901))

## [1.13.2](https://github.com/Automattic/newspack-blocks/compare/v1.13.1...v1.13.2) (2020-10-27)


### Bug Fixes

* suppress password protected posts ([#609](https://github.com/Automattic/newspack-blocks/issues/609)) ([ff667d7](https://github.com/Automattic/newspack-blocks/commit/ff667d714bb6ac92ad5d8961e8d59a6230261e5e))

## [1.13.1](https://github.com/Automattic/newspack-blocks/compare/v1.13.0...v1.13.1) (2020-09-29)


### Bug Fixes

* add alignment options to post carousel block ([#597](https://github.com/Automattic/newspack-blocks/issues/597)) ([19a3965](https://github.com/Automattic/newspack-blocks/commit/19a3965b6b502f3e74f6697c43c0fdadf87176d7))

# [1.13.0](https://github.com/Automattic/newspack-blocks/compare/v1.12.2...v1.13.0) (2020-09-15)


### Bug Fixes

* **homepage-posts:** display info about load more posts if blog is private ([11e2934](https://github.com/Automattic/newspack-blocks/commit/11e293408c8d37aec3ae5709c7586fcc420c5a66)), closes [#507](https://github.com/Automattic/newspack-blocks/issues/507)
* **sponsors:** prevent warnings when sponsor image is not set ([b715706](https://github.com/Automattic/newspack-blocks/commit/b7157068b1246098908b3b91caab2cc9e1c07e9e))


### Features

* add support for the aside post format ([dd69bc2](https://github.com/Automattic/newspack-blocks/commit/dd69bc2939f5200b9fa94a66d60af666f70f13bf))

## [1.12.2](https://github.com/Automattic/newspack-blocks/compare/v1.12.1...v1.12.2) (2020-09-08)


### Bug Fixes

* make sure sponsored content styles survive tree shaking ([#585](https://github.com/Automattic/newspack-blocks/issues/585)) ([b15e7d7](https://github.com/Automattic/newspack-blocks/commit/b15e7d7b66400108faddd58a8dafae7a016b5e27))

## [1.12.1](https://github.com/Automattic/newspack-blocks/compare/v1.12.0...v1.12.1) (2020-08-26)


### Bug Fixes

* prevent errors when there is no sponsor logo, link ([#581](https://github.com/Automattic/newspack-blocks/issues/581)) ([e1337a2](https://github.com/Automattic/newspack-blocks/commit/e1337a2fb1a6e0bf6cbd221a387f9eb4e4ec7330))

# [1.12.0](https://github.com/Automattic/newspack-blocks/compare/v1.11.1...v1.12.0) (2020-08-25)


### Features

* trigger release ([419ffe7](https://github.com/Automattic/newspack-blocks/commit/419ffe7f9e168abadbc5fe91b68d7100b087d476))

## [1.11.1](https://github.com/Automattic/newspack-blocks/compare/v1.11.0...v1.11.1) (2020-08-18)


### Bug Fixes

* moving check for sponsors to prevent notices ([#573](https://github.com/Automattic/newspack-blocks/issues/573)) ([0cd9810](https://github.com/Automattic/newspack-blocks/commit/0cd98108aff171d5cd2c077be1c84d9b056d5f6e))

# [1.11.0](https://github.com/Automattic/newspack-blocks/compare/v1.10.2...v1.11.0) (2020-08-18)


### Features

* add sponsor labels to homepage posts and post carousel blocks ([#563](https://github.com/Automattic/newspack-blocks/issues/563)) ([cbcd65f](https://github.com/Automattic/newspack-blocks/commit/cbcd65f7932e61c4a63a1ac72a54aa819bd13e9a))

## [1.10.2](https://github.com/Automattic/newspack-blocks/compare/v1.10.1...v1.10.2) (2020-08-11)


### Bug Fixes

* don't render homepage posts blocks in feeds ([51f72eb](https://github.com/Automattic/newspack-blocks/commit/51f72ebd6a8db158bc93ea2a4ceaf2b044653944))

## [1.10.1](https://github.com/Automattic/newspack-blocks/compare/v1.10.0...v1.10.1) (2020-08-04)


### Bug Fixes

* make sure Post Carousel text colors aren't overridden ([#553](https://github.com/Automattic/newspack-blocks/issues/553)) ([4b246fe](https://github.com/Automattic/newspack-blocks/commit/4b246fe11b2c1159cb041a3fecb99e79ce8c711a))

# [1.10.0](https://github.com/Automattic/newspack-blocks/compare/v1.9.1...v1.10.0) (2020-07-28)


### Features

* experimental title-only specific posts lookup endpoint ([#556](https://github.com/Automattic/newspack-blocks/issues/556)) ([5816ef9](https://github.com/Automattic/newspack-blocks/commit/5816ef90e52a72d5e79c07e1432025ff62d5e303))

## [1.9.1](https://github.com/Automattic/newspack-blocks/compare/v1.9.0...v1.9.1) (2020-07-22)


### Bug Fixes

* php notices in gutenberg 8.6.0 ([#549](https://github.com/Automattic/newspack-blocks/issues/549)) ([c129449](https://github.com/Automattic/newspack-blocks/commit/c129449fa250e9920273128b4a2adbe776cd506e))

# [1.9.0](https://github.com/Automattic/newspack-blocks/compare/v1.8.1...v1.9.0) (2020-06-30)


### Bug Fixes

* make load more button styles more specific ([#527](https://github.com/Automattic/newspack-blocks/issues/527)) ([68089aa](https://github.com/Automattic/newspack-blocks/commit/68089aad84661c4db52a6b2096264b7475560cb9))
* php warnings, phpcs warnings ([#540](https://github.com/Automattic/newspack-blocks/issues/540)) ([23b34a1](https://github.com/Automattic/newspack-blocks/commit/23b34a10e557d36a89e6c426962a6bde0ce5ee05))


### Features

* editable donate button text ([#535](https://github.com/Automattic/newspack-blocks/issues/535)) ([6261e3f](https://github.com/Automattic/newspack-blocks/commit/6261e3ff50d325471e981bd6dd0bc4b133facdfa))
* exclude current post in homepage posts block ([#539](https://github.com/Automattic/newspack-blocks/issues/539)) ([030024d](https://github.com/Automattic/newspack-blocks/commit/030024d70135fa655985aff801568bee2550caa0))

## [1.8.1](https://github.com/Automattic/newspack-blocks/compare/v1.8.0...v1.8.1) (2020-06-23)


### Bug Fixes

* fall back to author if coauthor fails ([18501f6](https://github.com/Automattic/newspack-blocks/commit/18501f6482c3ee881c68da9afcc7523e6c2726a0))

# [1.8.0](https://github.com/Automattic/newspack-blocks/compare/v1.7.2...v1.8.0) (2020-06-18)


### Bug Fixes

* correct count ([19c7a6c](https://github.com/Automattic/newspack-blocks/commit/19c7a6c5946eb9cbd60e838f6b9b9db06dda0855))
* donations wizard link ([84e0092](https://github.com/Automattic/newspack-blocks/commit/84e0092a47d2bc1f01e041521844bfca921798c1))


### Features

* improve Homepage Posts block editor performance ([#499](https://github.com/Automattic/newspack-blocks/issues/499)) ([e1c68c5](https://github.com/Automattic/newspack-blocks/commit/e1c68c56741dc4d193aa6ceea9462191c7163d35)), closes [#493](https://github.com/Automattic/newspack-blocks/issues/493)

## [1.7.2](https://github.com/Automattic/newspack-blocks/compare/v1.7.1...v1.7.2) (2020-06-13)


### Bug Fixes

* **homepage-posts:** exclude posts already on the page from query ([bbbbc3e](https://github.com/Automattic/newspack-blocks/commit/bbbbc3e7fb84f28c02293c10e0cd49faa7c6b819)), closes [#510](https://github.com/Automattic/newspack-blocks/issues/510)

## [1.7.1](https://github.com/Automattic/newspack-blocks/compare/v1.7.0...v1.7.1) (2020-06-09)


### Bug Fixes

* always display query controls; disallow choosing 0 posts ([#497](https://github.com/Automattic/newspack-blocks/issues/497)) ([b956111](https://github.com/Automattic/newspack-blocks/commit/b9561113e822530eec74e30ca4df2139515716ab))
* correct order of arguments in implodes ([0f45074](https://github.com/Automattic/newspack-blocks/commit/0f45074216e939438165d2621e6152797164e1ec))
* don't use main wp_query when rendering homepage posts ([9c804f6](https://github.com/Automattic/newspack-blocks/commit/9c804f60d37b85e1caa0dd5b22549d478623e1e1))
* reset postdata after restoring wp_query ([d9d01a6](https://github.com/Automattic/newspack-blocks/commit/d9d01a664b7a1132bf67b1c5f49fed965b763fee))
* **homepage-posts:** exclude specific posts from other blocks from query ([#500](https://github.com/Automattic/newspack-blocks/issues/500)) ([31181a5](https://github.com/Automattic/newspack-blocks/commit/31181a5536aef03779cf789d2dcd4d9de05b4371)), closes [#498](https://github.com/Automattic/newspack-blocks/issues/498)
* make bottom margin styles less specific for easier overrides ([#483](https://github.com/Automattic/newspack-blocks/issues/483)) ([93c3aff](https://github.com/Automattic/newspack-blocks/commit/93c3aff087f30c955591770f514094604f0bc829))

# [1.7.0](https://github.com/Automattic/newspack-blocks/compare/v1.6.0...v1.7.0) (2020-06-02)


### Features

* add category and tag classes to each homepage post ([#487](https://github.com/Automattic/newspack-blocks/issues/487)) ([d7c4fcd](https://github.com/Automattic/newspack-blocks/commit/d7c4fcd8d3caa2bbdda970e913265e3da7144b76))

# [1.6.0](https://github.com/Automattic/newspack-blocks/compare/v1.5.0...v1.6.0) (2020-05-19)


### Bug Fixes

* decode entities in categories when shown ([#474](https://github.com/Automattic/newspack-blocks/issues/474)) ([5347ba3](https://github.com/Automattic/newspack-blocks/commit/5347ba3904b12a7d4b7de5527927a3353fd198a1))
* decode entities in categories when shown in carousel ([#475](https://github.com/Automattic/newspack-blocks/issues/475)) ([20dcfd3](https://github.com/Automattic/newspack-blocks/commit/20dcfd382134989a55a3d9c40889fadbb3dd79ff))
* disallow 0 as post to show value ([80db497](https://github.com/Automattic/newspack-blocks/commit/80db497f1d734f8bb9b60d4441670d6deab5e7f7))
* initializing swiper for multiple blocks ([#476](https://github.com/Automattic/newspack-blocks/issues/476)) ([f480d54](https://github.com/Automattic/newspack-blocks/commit/f480d5480b801d21490cf406f8c55bcfb5620fbc))


### Features

* add border style to group block ([#463](https://github.com/Automattic/newspack-blocks/issues/463)) ([1c1f89d](https://github.com/Automattic/newspack-blocks/commit/1c1f89dbb8ec6cbdf39e0303457551c43612480a))
* add image shape classes to the front end ([#473](https://github.com/Automattic/newspack-blocks/issues/473)) ([c90df2e](https://github.com/Automattic/newspack-blocks/commit/c90df2e2e89d685a979b20e4d7ff005af2356869))

# [1.5.0](https://github.com/Automattic/newspack-blocks/compare/v1.4.0...v1.5.0) (2020-05-07)


### Bug Fixes

* carousel image being distorted ([36f72c6](https://github.com/Automattic/newspack-blocks/commit/36f72c6afcaef9f15e957b0dc8d23fb6a566136c))
* make sure content options don't affect blocks ([91ecd2c](https://github.com/Automattic/newspack-blocks/commit/91ecd2cede297021472d411c381fef0ab2cc85e4))


### Features

* add a max-height to the slider ([ea477a2](https://github.com/Automattic/newspack-blocks/commit/ea477a2d2530df8726acd996a7984f2a4e3f37f1))

# [1.4.0](https://github.com/Automattic/newspack-blocks/compare/v1.3.1...v1.4.0) (2020-05-05)


### Bug Fixes

* byline, avatar and date in carousel block ([#455](https://github.com/Automattic/newspack-blocks/issues/455)) ([85b7865](https://github.com/Automattic/newspack-blocks/commit/85b78651a1ed270d5a316c7abf4bf9060a77e890))
* patterns preview thumbnails ([#453](https://github.com/Automattic/newspack-blocks/issues/453)) ([3ca80e0](https://github.com/Automattic/newspack-blocks/commit/3ca80e056f0df5f0db978f6065625963739ef050))
* update editor selector for reordering columns ([#435](https://github.com/Automattic/newspack-blocks/issues/435)) ([396826a](https://github.com/Automattic/newspack-blocks/commit/396826adc88217b9ea02a7d60512c9fb6e497bfe))


### Features

* use swiper for non-amp carousels ([94c0a2b](https://github.com/Automattic/newspack-blocks/commit/94c0a2bf3f203f08a72326e578724f06bc0464cc))

## [1.3.1](https://github.com/Automattic/newspack-blocks/compare/v1.3.0...v1.3.1) (2020-04-29)


### Bug Fixes

* add isset to avoid warnings ([8c791da](https://github.com/Automattic/newspack-blocks/commit/8c791da4a50a5f979a13feee9c7de43a92bf7fc0))

# [1.3.0](https://github.com/Automattic/newspack-blocks/compare/v1.2.1...v1.3.0) (2020-04-21)


### Bug Fixes

* patterns sidebar background color ([#431](https://github.com/Automattic/newspack-blocks/issues/431)) ([d2dfda3](https://github.com/Automattic/newspack-blocks/commit/d2dfda3679947dbf6f4b20bf97d5ca3e005c21de))


### Features

* add 2 new subscribe patterns ([#426](https://github.com/Automattic/newspack-blocks/issues/426)) ([07e8239](https://github.com/Automattic/newspack-blocks/commit/07e82391ec208c2daff78596953defd63ce53931))
* update carousel style since amp 1.5.1 ([#427](https://github.com/Automattic/newspack-blocks/issues/427)) ([94312d2](https://github.com/Automattic/newspack-blocks/commit/94312d2d23394471d15e713cec8c2ba444f89946))

## [1.2.1](https://github.com/Automattic/newspack-blocks/compare/v1.2.0...v1.2.1) (2020-03-31)


### Bug Fixes

* fix broken author URL on WP.com ([#407](https://github.com/Automattic/newspack-blocks/issues/407)) ([9614350](https://github.com/Automattic/newspack-blocks/commit/9614350286d1a0124aab7ee61de2cec8849434fd))
* pattern sidebar to match gutenberg patterns sidebar ([#424](https://github.com/Automattic/newspack-blocks/issues/424)) ([fb2d209](https://github.com/Automattic/newspack-blocks/commit/fb2d209581881d39921d22db121974133d0746af))

# [1.2.0](https://github.com/Automattic/newspack-blocks/compare/v1.1.0...v1.2.0) (2020-03-24)


### Bug Fixes

* make sure styles hiding updated date doesn't hide all dates  ([#406](https://github.com/Automattic/newspack-blocks/issues/406)) ([d1bb53c](https://github.com/Automattic/newspack-blocks/commit/d1bb53ca82a67e56b1dea616e86a9c099c5498b4))


### Features

* display subscribe pattern when mailchimp is inactive ([#418](https://github.com/Automattic/newspack-blocks/issues/418)) ([09716c1](https://github.com/Automattic/newspack-blocks/commit/09716c10005483d919a8332e92d462c32a992625))
* update the pattern's icon with newspack logo ([#419](https://github.com/Automattic/newspack-blocks/issues/419)) ([8532c6f](https://github.com/Automattic/newspack-blocks/commit/8532c6f1a1a8f3de2ccb9c6c8d5d2216798aa2a3))

# [1.1.0](https://github.com/Automattic/newspack-blocks/compare/v1.0.0...v1.1.0) (2020-03-17)


### Bug Fixes

* double-encoded load more urls ([#415](https://github.com/Automattic/newspack-blocks/issues/415)) ([9d7d7d5](https://github.com/Automattic/newspack-blocks/commit/9d7d7d522cf10da7b1b75c0e95d9d34518d979ec))


### Features

* add homepage posts, donation, and subscribe patterns ([98ab74c](https://github.com/Automattic/newspack-blocks/commit/98ab74cc6f3541d9052bd946538f996fb1e470a2))
* update default carousel style for focused elements ([#410](https://github.com/Automattic/newspack-blocks/issues/410)) ([02335c4](https://github.com/Automattic/newspack-blocks/commit/02335c4c65ddd628168a32ecdfe4411675a25d4e))

# 1.0.0 (2020-03-02)


### Bug Fixes

* correct 'Load more posts' German translation ([8913258](https://github.com/Automattic/newspack-blocks/commit/8913258ff32b32e0aaf4f20194002f78bbee89a3))
* fix video attribute handling ([cfe96e8](https://github.com/Automattic/newspack-blocks/commit/cfe96e82983f4b341f18120017456c287d1294fa))
* load more button - remove amp-layout container, append each new article individually. ([#375](https://github.com/Automattic/newspack-blocks/issues/375)) ([efee9dc](https://github.com/Automattic/newspack-blocks/commit/efee9dc47704253036961ba8fb06b7518c25bb80))
* Manual video input ([72efba4](https://github.com/Automattic/newspack-blocks/commit/72efba48972a2d5283ce03a702b9b594a3c68efa))
* remove background color behind load more button in editor. fixes [#367](https://github.com/Automattic/newspack-blocks/issues/367). ([#374](https://github.com/Automattic/newspack-blocks/issues/374)) ([f5758fc](https://github.com/Automattic/newspack-blocks/commit/f5758fc79d0e2dad7efb4386bb87677f8a55a58d))


### Features

* add alignment options for youtube playlist block ([11c54f2](https://github.com/Automattic/newspack-blocks/commit/11c54f26b695a5d390a7ce29cb21297286173a9c))
* Add campaign field to Donate block ([8268836](https://github.com/Automattic/newspack-blocks/commit/8268836f648bb67f293ac4477b9ffd747192be0b))
* Add settings and flesh out further ([f343d99](https://github.com/Automattic/newspack-blocks/commit/f343d998d5bc23090bb91a8ef9e32bb500faa053))
* Basic video playlist block ([d66485a](https://github.com/Automattic/newspack-blocks/commit/d66485ab1c6cab6378aa16a02c71f360d0211f65))
* Get basic everything working well ([b5537c8](https://github.com/Automattic/newspack-blocks/commit/b5537c8f9c1893d334edda3cf2601a7d459c5d80))
* Make block player interactive in editor. ([d1dce97](https://github.com/Automattic/newspack-blocks/commit/d1dce97dcb33233ff5ef9e3ba612e2238c680b58))
