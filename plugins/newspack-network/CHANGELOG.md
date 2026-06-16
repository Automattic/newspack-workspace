## newspack-network [2.20.3](https://github.com/Automattic/newspack-workspace/compare/newspack-network@2.20.2...newspack-network@2.20.3) (2026-06-11)


### Bug Fixes

* **network:** preserve node future schedule on origin publish re-sync ([#222](https://github.com/Automattic/newspack-workspace/issues/222)) ([888b5d8](https://github.com/Automattic/newspack-workspace/commit/888b5d82f2dd2fdf9a989d0017fdc9be69208f61))

## newspack-network [2.20.2](https://github.com/Automattic/newspack-workspace/compare/newspack-network@2.20.1...newspack-network@2.20.2) (2026-06-01)


### Bug Fixes

* **network:** harden the user-sync receive side and sign the Hub pull response ([#319](https://github.com/Automattic/newspack-workspace/issues/319)) ([0997396](https://github.com/Automattic/newspack-workspace/commit/0997396c7cdd9a69e62d9dfd29066dfb35a6fe8e))

## [2.20.1](https://github.com/Automattic/newspack-network/compare/v2.20.0...v2.20.1) (2026-05-18)


### Bug Fixes

* **memberships:** skip team-owned memberships in transfer_membership ([#314](https://github.com/Automattic/newspack-network/issues/314)) ([9f9d2aa](https://github.com/Automattic/newspack-network/commit/9f9d2aab16befe33a1c0bf360676eb92434d60ff))

# [2.20.0](https://github.com/Automattic/newspack-network/compare/v2.19.0...v2.20.0) (2026-05-04)


### Features

* **memberships:** improve integrity check and handle ownership transfers ([#306](https://github.com/Automattic/newspack-network/issues/306)) ([2f8c5a6](https://github.com/Automattic/newspack-network/commit/2f8c5a6f7f58e68c00ded19c58932221fe6247ff))

# [2.19.0](https://github.com/Automattic/newspack-network/compare/v2.18.4...v2.19.0) (2026-03-30)


### Bug Fixes

* **content-distribution:** keep node post in status_on_publish when hub schedules ([#302](https://github.com/Automattic/newspack-network/issues/302)) ([6ed98ad](https://github.com/Automattic/newspack-network/commit/6ed98adc45fd6948ab666489b2313e71fb67fa3a))


### Features

* **content-gate:** network product and access control ([#303](https://github.com/Automattic/newspack-network/issues/303)) ([f81db3b](https://github.com/Automattic/newspack-network/commit/f81db3b126e4195ec5b8ca0456bc8d8402a11c57))

## [2.18.4](https://github.com/Automattic/newspack-network/compare/v2.18.3...v2.18.4) (2026-03-16)


### Bug Fixes

* **content-distribution:** handle exceptions in incoming post filter ([#294](https://github.com/Automattic/newspack-network/issues/294)) ([c4d354b](https://github.com/Automattic/newspack-network/commit/c4d354b609dae8d9e5c300cbbcaeb7728d23c17e))

## [2.18.3](https://github.com/Automattic/newspack-network/compare/v2.18.2...v2.18.3) (2026-01-30)


### Bug Fixes

* **sync:** cast role to array for backwards compatibility ([#292](https://github.com/Automattic/newspack-network/issues/292)) ([1f5f34f](https://github.com/Automattic/newspack-network/commit/1f5f34f71452216f331d22000997aa5ab3700779))

## [2.18.2](https://github.com/Automattic/newspack-network/compare/v2.18.1...v2.18.2) (2026-01-19)


### Bug Fixes

* **content-distribution:** Image block lightbox and Jetpack carousel support ([#285](https://github.com/Automattic/newspack-network/issues/285)) ([90a85ac](https://github.com/Automattic/newspack-network/commit/90a85ac77e3dba0712ad5880c0d3ac9a8322bcc4))

## [2.18.1](https://github.com/Automattic/newspack-network/compare/v2.18.0...v2.18.1) (2025-12-12)


### Bug Fixes

* handle empty tags (trigger release) ([a013da1](https://github.com/Automattic/newspack-network/commit/a013da10ea86ba2da3037915f25813d050a44614))

# [2.18.0](https://github.com/Automattic/newspack-network/compare/v2.17.1...v2.18.0) (2025-11-24)


### Features

* Merge pull request [#283](https://github.com/Automattic/newspack-network/issues/283) from Automattic/trunk ([8385a9e](https://github.com/Automattic/newspack-network/commit/8385a9eed367339244bfbb39701f0642fbcdae77))

## [2.17.1](https://github.com/Automattic/newspack-network/compare/v2.17.0...v2.17.1) (2025-11-05)


### Bug Fixes

* improve CAP GA logic ([bc24364](https://github.com/Automattic/newspack-network/commit/bc24364183799656146d151943123af365e0f80d))
* improve guest author distribution ([da7f88e](https://github.com/Automattic/newspack-network/commit/da7f88ea05c2f2be684adf5248664122f57a889a))

# [2.17.0](https://github.com/Automattic/newspack-network/compare/v2.16.0...v2.17.0) (2025-10-06)


### Features

* network memberships integrity check ([#258](https://github.com/Automattic/newspack-network/issues/258)) ([94ae8b4](https://github.com/Automattic/newspack-network/commit/94ae8b4ab4cdc5bf672fc38af1952bff929d5656))

# [2.16.0](https://github.com/Automattic/newspack-network/compare/v2.15.1...v2.16.0) (2025-09-08)


### Bug Fixes

* don't show admin bar if network is not configured ([#270](https://github.com/Automattic/newspack-network/issues/270)) ([4fd4390](https://github.com/Automattic/newspack-network/commit/4fd43906b6b1906e84fed29bfaa5e76f727e8241))


### Features

* release content distribution ([#268](https://github.com/Automattic/newspack-network/issues/268)) ([d0f2626](https://github.com/Automattic/newspack-network/commit/d0f26260b0cb060e084de579f71bdf97064e8f71))
* **content-distribution:** add media data to the outgoing post payload ([#266](https://github.com/Automattic/newspack-network/issues/266)) ([bcc0c5c](https://github.com/Automattic/newspack-network/commit/bcc0c5cbbb42fa629518231f1e51eeda0047f903))
* **content-distribution:** block processors and Jetpack galleries handling ([#274](https://github.com/Automattic/newspack-network/issues/274)) ([7d919bb](https://github.com/Automattic/newspack-network/commit/7d919bbadf0aa9ebfe2609727647c71735e79b20))

# [2.15.1](https://github.com/Automattic/newspack-network/compare/v2.15.0...v2.15.1) (2025-09-03)

### Features

- add filter to story budget synced fields ([#276](https://github.com/Automattic/newspack-network/issues/276)) ([03db089](https://github.com/Automattic/newspack-network/commit/03db089cc7a3d3f4d61d0ca32a5dbf0bc91da7ca))

## [2.15.0](https://github.com/Automattic/newspack-network/compare/v2.14.2...v2.15.0) (2025-08-25)

### Features

- add i18n ([#263](https://github.com/Automattic/newspack-network/issues/263)) ([bc89850](https://github.com/Automattic/newspack-network/commit/bc898505cb6530a5742422ecdea6e094c3be1df3))
- release content distribution ([#268](https://github.com/Automattic/newspack-network/issues/268)) ([e48559e](https://github.com/Automattic/newspack-network/commit/e48559e925ec69420efee72850a1aa9c62816065))

## [2.14.2](https://github.com/Automattic/newspack-network/compare/v2.14.1...v2.14.2) (2025-08-19)

### Bug Fixes

- **content-distribution:** populate distributed featured image meta ([#272](https://github.com/Automattic/newspack-network/issues/272)) ([cc5388d](https://github.com/Automattic/newspack-network/commit/cc5388d255e9581cea59915313ea860d11fc4b6e))

## [2.14.1](https://github.com/Automattic/newspack-network/compare/v2.14.0...v2.14.1) (2025-08-19)

### Bug Fixes

- **content-distribution:** account for null get_post return value ([aebd3d6](https://github.com/Automattic/newspack-network/commit/aebd3d6abb6464f2424082ea677db1ae7428c947))

# [2.14.0](https://github.com/Automattic/newspack-network/compare/v2.13.0...v2.14.0) (2025-08-13)

### Features

- **content-distribution:** add media data to the outgoing post payload ([#266](https://github.com/Automattic/newspack-network/issues/266)) ([f17f67c](https://github.com/Automattic/newspack-network/commit/f17f67c99f75c45cd692f625630f69dd02aa8944))

# [2.13.0](https://github.com/Automattic/newspack-network/compare/v2.12.2...v2.13.0) (2025-08-11)

### Features

- propagate hub name ([#262](https://github.com/Automattic/newspack-network/issues/262)) ([498db0b](https://github.com/Automattic/newspack-network/commit/498db0b435259f1ef00dd4804842d3a1fd673c23))

## [2.12.2](https://github.com/Automattic/newspack-network/compare/v2.12.1...v2.12.2) (2025-07-31)

### Bug Fixes

- avoid post status collision ([#264](https://github.com/Automattic/newspack-network/issues/264)) ([95c413e](https://github.com/Automattic/newspack-network/commit/95c413e360abf8466c36bca7c277f8ed3d92f63a))

## [2.12.1](https://github.com/Automattic/newspack-network/compare/v2.12.0...v2.12.1) (2025-07-22)

### Bug Fixes

- **content-distribution:** disable photon for thumbnail url ([#261](https://github.com/Automattic/newspack-network/issues/261)) ([bef634b](https://github.com/Automattic/newspack-network/commit/bef634bc69ff64cdc1d8b8d5c260dde37c413793))

# [2.12.0](https://github.com/Automattic/newspack-network/compare/v2.11.1...v2.12.0) (2025-07-14)

### Bug Fixes

- check for woo ([#255](https://github.com/Automattic/newspack-network/issues/255)) ([cea8a71](https://github.com/Automattic/newspack-network/commit/cea8a71a0c00d87fb8f9c830caf56ab685c15840))
- dont show user acitvity link when misconfigured ([#253](https://github.com/Automattic/newspack-network/issues/253)) ([f456407](https://github.com/Automattic/newspack-network/commit/f456407e66e78ae56ec0d1d90b88f87c5bac4cb5))

### Features

- add network subscriptions to my account NPPM-350 ([#248](https://github.com/Automattic/newspack-network/issues/248)) ([a37fc57](https://github.com/Automattic/newspack-network/commit/a37fc57e7ce2bde201bd821e9f116633a2c72713))

## [2.11.1](https://github.com/Automattic/newspack-network/compare/v2.11.0...v2.11.1) (2025-07-09)

### Bug Fixes

- **content-distribution:** Distributor migration tweaks ([#259](https://github.com/Automattic/newspack-network/issues/259)) ([165b540](https://github.com/Automattic/newspack-network/commit/165b540f6eb6ddde9d24e5111b75c73e8000a5fa))

# [2.11.0](https://github.com/Automattic/newspack-network/compare/v2.10.0...v2.11.0) (2025-06-30)

### Bug Fixes

- **story-budget:** asset versioning ([#246](https://github.com/Automattic/newspack-network/issues/246)) ([1bdd274](https://github.com/Automattic/newspack-network/commit/1bdd274bd31342c924d46b10b6db508f566bcc09))

### Features

- **content-distribution:** distribute non-publish content ([#250](https://github.com/Automattic/newspack-network/issues/250)) ([88e1654](https://github.com/Automattic/newspack-network/commit/88e165429f19334ea190c483427448ab541d7b63))
- **content-distribution:** pull posts and Story Budget integration [NPPD-127] ([#243](https://github.com/Automattic/newspack-network/issues/243)) ([f48fa73](https://github.com/Automattic/newspack-network/commit/f48fa73844160fb71dd1a3a939d00df90a6e7279))
- **story-budget:** manage field syncing and props ([#247](https://github.com/Automattic/newspack-network/issues/247)) ([c3b56a8](https://github.com/Automattic/newspack-network/commit/c3b56a84aaeb53e0adad8a24973194fc8315e34d))

# [2.10.0](https://github.com/Automattic/newspack-network/compare/v2.9.0...v2.10.0) (2025-06-02)

### Features

- add option to not create new terms on content distrib ([#238](https://github.com/Automattic/newspack-network/issues/238)) ([0c4772a](https://github.com/Automattic/newspack-network/commit/0c4772a4a8b8987b899f199e17d24530dba91276))
- add support to yoast primary cat in content distribution ([#241](https://github.com/Automattic/newspack-network/issues/241)) ([be614cf](https://github.com/Automattic/newspack-network/commit/be614cfda1006797b9b812fe5ac650d0f90e97a6))
- sync post modified date on post ditribution ([#240](https://github.com/Automattic/newspack-network/issues/240)) ([eb123ce](https://github.com/Automattic/newspack-network/commit/eb123ce9992a4feea16f307bc4cdebae9eb83291))

# [2.9.0](https://github.com/Automattic/newspack-network/compare/v2.8.0...v2.9.0) (2025-04-14)

### Bug Fixes

- **event-log:** copy to clipboard ([e3f97c4](https://github.com/Automattic/newspack-network/commit/e3f97c49fa97a5093c4898757c5b96261b296903))
- **woocommerce:** tweaks for membership and subscription events ([#234](https://github.com/Automattic/newspack-network/issues/234)) ([74c717e](https://github.com/Automattic/newspack-network/commit/74c717e399a9d200958daa4f78fb329adcc0b849))

### Features

- sync membership end date ([#232](https://github.com/Automattic/newspack-network/issues/232)) ([f2b578b](https://github.com/Automattic/newspack-network/commit/f2b578ba44655a563ec0aa35e3beaab71df16e89))

# [2.8.0](https://github.com/Automattic/newspack-network/compare/v2.7.0...v2.8.0) (2025-03-18)

### Bug Fixes

- **content-distribution:** pages table column ([#230](https://github.com/Automattic/newspack-network/issues/230)) ([fe5fa5c](https://github.com/Automattic/newspack-network/commit/fe5fa5c55efc7edf92556152bea537a388b32604))

### Features

- **content-distribution:** remove distribution on incoming post deletion ([#225](https://github.com/Automattic/newspack-network/issues/225)) ([24a421a](https://github.com/Automattic/newspack-network/commit/24a421a65ee8e7ab724943005c583b60b951d6b8))
- **content-distribution:** support page post type ([#228](https://github.com/Automattic/newspack-network/issues/228)) ([1d21b4d](https://github.com/Automattic/newspack-network/commit/1d21b4d8a76a5fc775d0a0ff112fb989d15638f1))
- **event-log:** migrate `data` db column to `longtext` ([#227](https://github.com/Automattic/newspack-network/issues/227)) ([ef2353b](https://github.com/Automattic/newspack-network/commit/ef2353be324ce5c2d889739a44b92880ef54c1d7))

# [2.7.0](https://github.com/Automattic/newspack-network/compare/v2.6.1...v2.7.0) (2025-03-04)

### Bug Fixes

- build on release ([#219](https://github.com/Automattic/newspack-network/issues/219)) ([396cb61](https://github.com/Automattic/newspack-network/commit/396cb6113c85074d3b8a9fa14d822b95906665d1))
- **content-distribution:** always distribute on API request ([#224](https://github.com/Automattic/newspack-network/issues/224)) ([ea53b11](https://github.com/Automattic/newspack-network/commit/ea53b11c5391b81ba36af6587f511dba3ac64018))
- **content-distribution:** change link to edit origin post ([#215](https://github.com/Automattic/newspack-network/issues/215)) ([ed70561](https://github.com/Automattic/newspack-network/commit/ed70561ee40a3bd58d6eedf4de161193d921d2f2))
- **content-distribution:** ignore unchanged values when shortcircuiting meta ([#222](https://github.com/Automattic/newspack-network/issues/222)) ([ed1182a](https://github.com/Automattic/newspack-network/commit/ed1182aaecd01487a5d3f77fef9ba106038b4558))
- **content-distribution:** link to post on incoming ([#211](https://github.com/Automattic/newspack-network/issues/211)) ([638fa8a](https://github.com/Automattic/newspack-network/commit/638fa8ac26a81453bbd6ea1f3b16aeb7c75cb9bc))
- **content-distribution:** sync non-publish posts ([#213](https://github.com/Automattic/newspack-network/issues/213)) ([ba3cd6a](https://github.com/Automattic/newspack-network/commit/ba3cd6ab2362474a1afc60eef135f9ed8bc5b4ad))
- **content-distribution:** update icon to broadcast ([#214](https://github.com/Automattic/newspack-network/issues/214)) ([df0e740](https://github.com/Automattic/newspack-network/commit/df0e740a0030b3941332bbbdb5a674bb50ddbf41))

### Features

- **content-distribution:** default distribution status ([#216](https://github.com/Automattic/newspack-network/issues/216)) ([df19856](https://github.com/Automattic/newspack-network/commit/df19856f946d0ed062ffb39008e733dc38a826c6))

## [2.6.1](https://github.com/Automattic/newspack-network/compare/v2.6.0...v2.6.1) (2025-02-26)

### Bug Fixes

- **content-distribution:** handle incoming post object error ([#221](https://github.com/Automattic/newspack-network/issues/221)) ([b83b8f2](https://github.com/Automattic/newspack-network/commit/b83b8f21e921ac87de390dd85cba4a424e3f1c69))

# [2.6.0](https://github.com/Automattic/newspack-network/compare/v2.5.0...v2.6.0) (2025-02-17)

### Bug Fixes

- **content-distribution:** use payload hash on partial updates ([#207](https://github.com/Automattic/newspack-network/issues/207)) ([31b342d](https://github.com/Automattic/newspack-network/commit/31b342d71b54676c488eb6823baca0f9c392ce96))
- **event-log:** data css overflow ([#206](https://github.com/Automattic/newspack-network/issues/206)) ([f81adfe](https://github.com/Automattic/newspack-network/commit/f81adfed5a76047f9db3156a743411f4e1f11c67))

### Features

- **content-distribution:** migration tweaks ([#201](https://github.com/Automattic/newspack-network/issues/201)) ([9c61fa8](https://github.com/Automattic/newspack-network/commit/9c61fa8e059108ef1693a159bf356bd989b7d0df))
- **content-distribution:** partial payload ([#205](https://github.com/Automattic/newspack-network/issues/205)) ([b844d06](https://github.com/Automattic/newspack-network/commit/b844d06037bd49b5f0eb552d737d437d8a2fc40d))
- **content-distribution:** remove "Quick Edit" from linked posts ([#200](https://github.com/Automattic/newspack-network/issues/200)) ([6ad3a4a](https://github.com/Automattic/newspack-network/commit/6ad3a4a06a07b2bed506ce1f5cb183430e10354c))
- **content-distribution:** Sync authors ([#194](https://github.com/Automattic/newspack-network/issues/194)) ([3156d35](https://github.com/Automattic/newspack-network/commit/3156d358816114c9cd911f2a6f01a627e3fcf7e4))
- increase pull frequency and amount ([#209](https://github.com/Automattic/newspack-network/issues/209)) ([6791da1](https://github.com/Automattic/newspack-network/commit/6791da125999cf7e046aebb3a8b1e32194c89c80))

# [2.5.0](https://github.com/Automattic/newspack-network/compare/v2.4.0...v2.5.0) (2025-02-03)

### Bug Fixes

- **content-distribution:** block additional UI components ([#197](https://github.com/Automattic/newspack-network/issues/197)) ([3f30cdb](https://github.com/Automattic/newspack-network/commit/3f30cdbc2c49e1dfe57d5a03e9dc7477d38f249f))
- **content-distribution:** handling multiple post meta ([#199](https://github.com/Automattic/newspack-network/issues/199)) ([c93084d](https://github.com/Automattic/newspack-network/commit/c93084d3cf3c08b12e63e82fbd4120e50f1e03fd))
- **content-distribution:** Improve CSS for blocked editor ([#193](https://github.com/Automattic/newspack-network/issues/193)) ([295bdad](https://github.com/Automattic/newspack-network/commit/295bdad4b418ad4162a41c502bb58c0258793125)), closes [#181](https://github.com/Automattic/newspack-network/issues/181)
- **content-distribution:** persist site hash ([#186](https://github.com/Automattic/newspack-network/issues/186)) ([120b759](https://github.com/Automattic/newspack-network/commit/120b759553b8bd25371e2a928de8eb8acd47ddc9))
- **content-distribution:** prevent consecutive dispatches ([#198](https://github.com/Automattic/newspack-network/issues/198)) ([2149a62](https://github.com/Automattic/newspack-network/commit/2149a62e5cdc9cf5e7d05993306221c9bf8d9661))
- **content-distribution:** refactor outgoing post js ([#188](https://github.com/Automattic/newspack-network/issues/188)) ([cc08edc](https://github.com/Automattic/newspack-network/commit/cc08edccbda3a84a24586e3fd76a0d2409947e01))
- **memberships:** remove managed fields on cancel or expire ([#192](https://github.com/Automattic/newspack-network/issues/192)) ([67a5cf0](https://github.com/Automattic/newspack-network/commit/67a5cf0274802525bbb4a9e494ccc4fe3244f7ec))

### Features

- **content-distribution:** Block the editor for incoming posts ([#181](https://github.com/Automattic/newspack-network/issues/181)) ([48b4cae](https://github.com/Automattic/newspack-network/commit/48b4cae890dfbcb5bf3d90dd08b00f1cbec2b426))
- **content-distribution:** confirm dialog for unlinking and relinking posts ([#190](https://github.com/Automattic/newspack-network/issues/190)) ([f36bb28](https://github.com/Automattic/newspack-network/commit/f36bb284efba690261dbb985961bbc3839b96cc0))
- **content-distribution:** migrator ([#185](https://github.com/Automattic/newspack-network/issues/185)) ([06ec18a](https://github.com/Automattic/newspack-network/commit/06ec18a0259ce19b5aae050ea0f7a950683de353))
- **content-distribution:** post status on create ([#189](https://github.com/Automattic/newspack-network/issues/189)) ([1ad3e0c](https://github.com/Automattic/newspack-network/commit/1ad3e0ceaf7dc6599d5a0300e1330341da03ea60))
- sync multiple user roles ([#187](https://github.com/Automattic/newspack-network/issues/187)) ([9fa833c](https://github.com/Automattic/newspack-network/commit/9fa833c157fc795fda466c3bfcd00594b98f1543))

# [2.4.0](https://github.com/Automattic/newspack-network/compare/v2.3.4...v2.4.0) (2025-01-20)

### Bug Fixes

- **content-distribution:** post insertion hook and additional meta for incoming post event ([#173](https://github.com/Automattic/newspack-network/issues/173)) ([48df13c](https://github.com/Automattic/newspack-network/commit/48df13c65c06f3edabd88122c5a6816c006b70f6))
- load text domain on init hook ([#171](https://github.com/Automattic/newspack-network/issues/171)) ([01fb89c](https://github.com/Automattic/newspack-network/commit/01fb89ca5c97da18a3e0eb772e4b20afb24e8db7))

### Features

- content distribution - experimental ([#168](https://github.com/Automattic/newspack-network/issues/168)) ([dc837d8](https://github.com/Automattic/newspack-network/commit/dc837d884ab4992a90e99347e363cd61116db770))
- **content-distribution:** add CLI command for distribute post ([#159](https://github.com/Automattic/newspack-network/issues/159)) ([7a43b86](https://github.com/Automattic/newspack-network/commit/7a43b863cd11eadade73aabd060110c27576c6d4)), closes [#155](https://github.com/Automattic/newspack-network/issues/155) [#156](https://github.com/Automattic/newspack-network/issues/156) [#157](https://github.com/Automattic/newspack-network/issues/157) [#160](https://github.com/Automattic/newspack-network/issues/160) [#165](https://github.com/Automattic/newspack-network/issues/165)
- **content-distribution:** canonical url ([#177](https://github.com/Automattic/newspack-network/issues/177)) ([5ca60ce](https://github.com/Automattic/newspack-network/commit/5ca60cee132f811c5efadc8d91778410a562880d))
- **content-distribution:** capability and admin page ([#176](https://github.com/Automattic/newspack-network/issues/176)) ([5285285](https://github.com/Automattic/newspack-network/commit/52852851909b02f2876c737ec351f77ee263ea05))
- **content-distribution:** control distribution meta and prevent multiple dispatches ([#170](https://github.com/Automattic/newspack-network/issues/170)) ([e76a2dc](https://github.com/Automattic/newspack-network/commit/e76a2dc8d4c097d7e56943f2a904b4841020c62a))
- **content-distribution:** editor plugin for distribution ([#167](https://github.com/Automattic/newspack-network/issues/167)) ([e10aef4](https://github.com/Automattic/newspack-network/commit/e10aef43ce7842df1c48cafc17037700b7b9f49a))
- **content-distribution:** handle status changes ([#166](https://github.com/Automattic/newspack-network/issues/166)) ([4af5da1](https://github.com/Automattic/newspack-network/commit/4af5da1b0edbfcdf5330878cceed0349f91cc36e))
- **content-distribution:** log incoming post errors ([#182](https://github.com/Automattic/newspack-network/issues/182)) ([74c9119](https://github.com/Automattic/newspack-network/commit/74c9119435f32370838fd0b249a2dcec1189ae26))
- **content-distribution:** posts column ([#178](https://github.com/Automattic/newspack-network/issues/178)) ([8e07640](https://github.com/Automattic/newspack-network/commit/8e076407f6a837201e8773cf88635ee405311a4d))
- **content-distribution:** reserved taxonomies ([#174](https://github.com/Automattic/newspack-network/issues/174)) ([a2c54d2](https://github.com/Automattic/newspack-network/commit/a2c54d2f702f197b77c27fad7bde7dddaadafd5f))
- **content-distribution:** sync comment and ping statuses ([#179](https://github.com/Automattic/newspack-network/issues/179)) ([90c5425](https://github.com/Automattic/newspack-network/commit/90c5425a5d5e52172a5de2e26003199112e8dd22))
- **content-distribution:** sync post meta ([#163](https://github.com/Automattic/newspack-network/issues/163)) ([353a3d8](https://github.com/Automattic/newspack-network/commit/353a3d880077f9060544c8e764f780535d1ba6b8))
- **event-log:** collapse data ([#180](https://github.com/Automattic/newspack-network/issues/180)) ([956219d](https://github.com/Automattic/newspack-network/commit/956219dad76cd2b020fc96004b45d9075380b514))
- limit purchase of a network membership ([#169](https://github.com/Automattic/newspack-network/issues/169)) ([deb2683](https://github.com/Automattic/newspack-network/commit/deb268310406d4dfa42caa7b4c32a2927980ce62))

## [2.3.4](https://github.com/Automattic/newspack-network/compare/v2.3.3...v2.3.4) (2024-12-18)

### Bug Fixes

- Force Distributor to not override bylines ([#175](https://github.com/Automattic/newspack-network/issues/175)) ([efbc979](https://github.com/Automattic/newspack-network/commit/efbc9798cc7c13985e2a9a2e4920f485dbe8a11e))

## [2.3.3](https://github.com/Automattic/newspack-network/compare/v2.3.2...v2.3.3) (2024-12-16)

### Bug Fixes

- load text domain on init hook ([#171](https://github.com/Automattic/newspack-network/issues/171)) ([f8d03f6](https://github.com/Automattic/newspack-network/commit/f8d03f6e5a6b8dcea433eed2ba7d7d29ab07d70e))

## [2.3.2](https://github.com/Automattic/newspack-network/compare/v2.3.1...v2.3.2) (2024-11-25)

### Bug Fixes

- submenu highlights ([#146](https://github.com/Automattic/newspack-network/issues/146)) ([387d178](https://github.com/Automattic/newspack-network/commit/387d1783a5ae9216c0e4cffb95dfed7020da9116))

## [2.3.1](https://github.com/Automattic/newspack-network/compare/v2.3.0...v2.3.1) (2024-11-11)

### Bug Fixes

- merge pull request [#147](https://github.com/Automattic/newspack-network/issues/147) from Automattic/trunk ([7d0a118](https://github.com/Automattic/newspack-network/commit/7d0a118023ac87345c4fe3df360339cc55245ea1))

# [2.3.0](https://github.com/Automattic/newspack-network/compare/v2.2.1...v2.3.0) (2024-10-28)

### Bug Fixes

- remove recursive import `use` ([b03bb52](https://github.com/Automattic/newspack-network/commit/b03bb52f702f873f465f8b0d62d935301933689d))

### Features

- handle email change on propagated users ([#133](https://github.com/Automattic/newspack-network/issues/133)) ([2efa226](https://github.com/Automattic/newspack-network/commit/2efa2266dee3bb22d4b01ce8fbe0f0393e37cf2f))

## [2.2.1](https://github.com/Automattic/newspack-network/compare/v2.2.0...v2.2.1) (2024-10-10)

### Bug Fixes

- exclude refund orders from backfill ([cef82c1](https://github.com/Automattic/newspack-network/commit/cef82c11b2c95f62809a2ca537ea071c7a2c90cc))

# [2.2.0](https://github.com/Automattic/newspack-network/compare/v2.1.0...v2.2.0) (2024-10-08)

### Bug Fixes

- **sync:** use `newspack_esp_sync_contact` filter for the `network_registration_site` field ([#132](https://github.com/Automattic/newspack-network/issues/132)) ([3755d07](https://github.com/Automattic/newspack-network/commit/3755d07f83c813c1cc0b4599371722e9eecfbd18))

### Features

- subscriptions and memberships sync reimplementation ([#122](https://github.com/Automattic/newspack-network/issues/122)) ([04fc845](https://github.com/Automattic/newspack-network/commit/04fc845168865bb03e59c1972be319fc8f99d4a5))

# [2.1.0](https://github.com/Automattic/newspack-network/compare/v2.0.0...v2.1.0) (2024-08-26)

### Features

- consolidate data flows ([#127](https://github.com/Automattic/newspack-network/issues/127)) ([d5f9c8f](https://github.com/Automattic/newspack-network/commit/d5f9c8f06dd06c8ec7a76ba827c7b11f6dd65c36))

# [2.0.0](https://github.com/Automattic/newspack-network/compare/v1.10.1...v2.0.0) (2024-08-13)

### Bug Fixes

- **memberships-sync:** handle active subs from other nodes ([#114](https://github.com/Automattic/newspack-network/issues/114)) ([97f9a58](https://github.com/Automattic/newspack-network/commit/97f9a58b90e660ec9511833b84af881357176163))
- update dependencies to support `@wordpress/scripts` ([#104](https://github.com/Automattic/newspack-network/issues/104)) ([e9691b8](https://github.com/Automattic/newspack-network/commit/e9691b8edcdc58cd3466bff46d2cbe4eacba9eaf))

### Features

- **distributor:** sync comment status ([#116](https://github.com/Automattic/newspack-network/issues/116)) ([5844853](https://github.com/Automattic/newspack-network/commit/58448533aa562238a6ea080b866155cb13764853))
- ensure that membership plans have unique network id ([#108](https://github.com/Automattic/newspack-network/issues/108)) ([d2d63a0](https://github.com/Automattic/newspack-network/commit/d2d63a04127718cadca74a57ff074d5fcfebb300))

### BREAKING CHANGES

- Updates dependencies for compatibility with WordPress 6.6._, but breaks JS in WordPress 6.5._ and below. If you need support for WP 6.5.\*, please do not upgrade to this new major version.

- chore: refactor for newspack-scripts dependency updates

- chore: update composer

- fix: peer dependencies

- chore: update newspack-scripts to v5.6.0-alpha.3

- chore: update newspack-scripts to v5.6.0-alpha.4

- chore: remove unnecessary prettier config file

- chore: update newspack-scripts to v5.6.0-alpha.7

- fix: update phpcs.xml

- chore: bump newspack-scripts to v5.5.2

## [1.10.1](https://github.com/Automattic/newspack-network/compare/v1.10.0...v1.10.1) (2024-08-01)

### Bug Fixes

- **memberships-sync:** handle active subs from other nodes ([#114](https://github.com/Automattic/newspack-network/issues/114)) ([4255f8c](https://github.com/Automattic/newspack-network/commit/4255f8c7676cc22f9a354de163fea763986b6a7e))

# [1.10.0](https://github.com/Automattic/newspack-network/compare/v1.9.2...v1.10.0) (2024-07-30)

### Features

- **membership-plans:** improvements ([#110](https://github.com/Automattic/newspack-network/issues/110)) ([f994478](https://github.com/Automattic/newspack-network/commit/f994478f9033854f499ad5343c16fda43e460643))
- user discrepancies display ([#100](https://github.com/Automattic/newspack-network/issues/100)) ([a054967](https://github.com/Automattic/newspack-network/commit/a05496735e1e8f691e3e5f680dbcd8e3a3909228))
- **users:** bulk user network sync ([d1e9533](https://github.com/Automattic/newspack-network/commit/d1e953335bd92058319ca57ad801c2ee770c681e))

## [1.9.2](https://github.com/Automattic/newspack-network/compare/v1.9.1...v1.9.2) (2024-07-16)

### Bug Fixes

- **api:** improve /info endpoint ([70e2208](https://github.com/Automattic/newspack-network/commit/70e2208b9ca47ad71506f030f5a4765ab64b4bc9))

## [1.9.1](https://github.com/Automattic/newspack-network/compare/v1.9.0...v1.9.1) (2024-07-01)

### Bug Fixes

- update newspack-scripts to v5.5.1 ([#105](https://github.com/Automattic/newspack-network/issues/105)) ([32acd37](https://github.com/Automattic/newspack-network/commit/32acd377ae0bda3acca4bde970c8db46557ad256))

# [1.9.0](https://github.com/Automattic/newspack-network/compare/v1.8.0...v1.9.0) (2024-06-12)

### Features

- handle existing users by adding a role; username generation and membership dedupe command fixes ([#98](https://github.com/Automattic/newspack-network/issues/98)) ([9f74e75](https://github.com/Automattic/newspack-network/commit/9f74e75d65c6abeb8bcceca30299a533ddd1aed9)), closes [#84](https://github.com/Automattic/newspack-network/issues/84)

# [1.9.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.8.0...v1.9.0-alpha.1) (2024-05-31)

### Features

- handle existing users by adding a role; username generation and membership dedupe command fixes ([#98](https://github.com/Automattic/newspack-network/issues/98)) ([9f74e75](https://github.com/Automattic/newspack-network/commit/9f74e75d65c6abeb8bcceca30299a533ddd1aed9)), closes [#84](https://github.com/Automattic/newspack-network/issues/84)

# [1.8.0](https://github.com/Automattic/newspack-network/compare/v1.7.0...v1.8.0) (2024-05-28)

### Bug Fixes

- **backfill:** reader registered, membership updated ([b177538](https://github.com/Automattic/newspack-network/commit/b177538edded61491582cf72f254e51e026553f6))

### Features

- **admin:** subscriptions view ([cb5bcb7](https://github.com/Automattic/newspack-network/commit/cb5bcb7be0235c72a21d89ffcdac6ae154a3fa15))
- auditing features env variable name change ([e1df42b](https://github.com/Automattic/newspack-network/commit/e1df42b605e284723c9d8babeee4456125307e27))
- remove hub's subscriptions and orders ([e234425](https://github.com/Automattic/newspack-network/commit/e23442525922b95d66455ff48b40b1cb85ed7eeb))
- sync Network Registration Site on network reader creation or fetch ([eeba099](https://github.com/Automattic/newspack-network/commit/eeba099429d87e7012c1397d432140dadd8c2916))

# [1.8.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.7.0...v1.8.0-alpha.1) (2024-05-21)

### Bug Fixes

- **backfill:** reader registered, membership updated ([b177538](https://github.com/Automattic/newspack-network/commit/b177538edded61491582cf72f254e51e026553f6))

### Features

- **admin:** subscriptions view ([cb5bcb7](https://github.com/Automattic/newspack-network/commit/cb5bcb7be0235c72a21d89ffcdac6ae154a3fa15))
- auditing features env variable name change ([e1df42b](https://github.com/Automattic/newspack-network/commit/e1df42b605e284723c9d8babeee4456125307e27))
- remove hub's subscriptions and orders ([e234425](https://github.com/Automattic/newspack-network/commit/e23442525922b95d66455ff48b40b1cb85ed7eeb))
- sync Network Registration Site on network reader creation or fetch ([eeba099](https://github.com/Automattic/newspack-network/commit/eeba099429d87e7012c1397d432140dadd8c2916))

# [1.7.0](https://github.com/Automattic/newspack-network/compare/v1.6.0...v1.7.0) (2024-05-15)

### Features

- handle user deletion event on the network ([#91](https://github.com/Automattic/newspack-network/issues/91)) ([cf3df3b](https://github.com/Automattic/newspack-network/commit/cf3df3b5d2d61f2a1679823f80c4780467a0f4f5))

# [1.7.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.6.0...v1.7.0-alpha.1) (2024-04-25)

### Features

- handle user deletion event on the network ([#91](https://github.com/Automattic/newspack-network/issues/91)) ([cf3df3b](https://github.com/Automattic/newspack-network/commit/cf3df3b5d2d61f2a1679823f80c4780467a0f4f5))

# [1.6.0](https://github.com/Automattic/newspack-network/compare/v1.5.0...v1.6.0) (2024-04-24)

### Features

- experimental auditing features ([#79](https://github.com/Automattic/newspack-network/issues/79)) ([0c31fd6](https://github.com/Automattic/newspack-network/commit/0c31fd60eeb404ab36f0d5eff7482f4cd4a5085e))

# [1.6.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.5.0...v1.6.0-alpha.1) (2024-04-11)

### Features

- experimental auditing features ([#79](https://github.com/Automattic/newspack-network/issues/79)) ([0c31fd6](https://github.com/Automattic/newspack-network/commit/0c31fd60eeb404ab36f0d5eff7482f4cd4a5085e))

# [1.5.0](https://github.com/Automattic/newspack-network/compare/v1.4.2...v1.5.0) (2024-04-08)

### Bug Fixes

- cli commands ([#73](https://github.com/Automattic/newspack-network/issues/73)) ([dc2bef9](https://github.com/Automattic/newspack-network/commit/dc2bef9b3dc2de8357f8deb6c7efb6139eec404f))
- correct typo in manual sync description ([#74](https://github.com/Automattic/newspack-network/issues/74)) ([a0bf23f](https://github.com/Automattic/newspack-network/commit/a0bf23fe8837cf7eefcbb867aa75819dc2e1b531))
- webhook encryption error handling ([#77](https://github.com/Automattic/newspack-network/issues/77)) ([ceee31f](https://github.com/Automattic/newspack-network/commit/ceee31f78fe4452b79485369a38409e5ca32413a))
- woo links in wp-admin-bar ([#71](https://github.com/Automattic/newspack-network/issues/71)) ([d2a76e5](https://github.com/Automattic/newspack-network/commit/d2a76e51084176cef9b6549c0c7940b17411904f))

### Features

- add Network-specific custom metadata to ESP syncs ([#83](https://github.com/Automattic/newspack-network/issues/83)) ([153a733](https://github.com/Automattic/newspack-network/commit/153a733080a78b0035e3b3bd98b7093b9d20f8fb))
- **cli:** --yes option for sync-all command ([268f7fe](https://github.com/Automattic/newspack-network/commit/268f7fed7892ae922ac6106ee824f37058c2ecad))
- **cli:** membership de-duplication CLI command ([#84](https://github.com/Automattic/newspack-network/issues/84)) ([c7ece71](https://github.com/Automattic/newspack-network/commit/c7ece717c297f55e4ae9db0791346e27953dc651))
- **manual-user-sync:** sync user login ([#81](https://github.com/Automattic/newspack-network/issues/81)) ([3f9755e](https://github.com/Automattic/newspack-network/commit/3f9755ed5b75a280c0a0e23b6d91f319ad0b8fa4))

## [1.4.2](https://github.com/Automattic/newspack-network/compare/v1.4.1...v1.4.2) (2024-04-02)

### Bug Fixes

- add new filter callback for Network Registration Site field ([#87](https://github.com/Automattic/newspack-network/issues/87)) ([00facbc](https://github.com/Automattic/newspack-network/commit/00facbcfe9468493c480c80872ebcf7b13824fc1))

# [1.5.0-alpha.2](https://github.com/Automattic/newspack-network/compare/v1.5.0-alpha.1...v1.5.0-alpha.2) (2024-03-29)

### Bug Fixes

- **event-log:** only show users filter with environment constant ([#86](https://github.com/Automattic/newspack-network/issues/86)) ([cd4a91a](https://github.com/Automattic/newspack-network/commit/cd4a91ab65a81d7abc54359ff6e1537aae253b84))

# [1.5.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.4.0...v1.5.0-alpha.1) (2024-03-27)

### Features

- **event-log:** only show users filter with environment constant ([#86](https://github.com/Automattic/newspack-network/issues/86)) ([cd4a91a](https://github.com/Automattic/newspack-network/commit/cd4a91ab65a81d7abc54359ff6e1537aae253b84))

## [1.4.1](https://github.com/Automattic/newspack-network/compare/v1.4.0...v1.4.1) (2024-03-29)

### Bug Fixes

- cli commands ([#73](https://github.com/Automattic/newspack-network/issues/73)) ([dc2bef9](https://github.com/Automattic/newspack-network/commit/dc2bef9b3dc2de8357f8deb6c7efb6139eec404f))
- correct typo in manual sync description ([#74](https://github.com/Automattic/newspack-network/issues/74)) ([a0bf23f](https://github.com/Automattic/newspack-network/commit/a0bf23fe8837cf7eefcbb867aa75819dc2e1b531))
- webhook encryption error handling ([#77](https://github.com/Automattic/newspack-network/issues/77)) ([ceee31f](https://github.com/Automattic/newspack-network/commit/ceee31f78fe4452b79485369a38409e5ca32413a))
- woo links in wp-admin-bar ([#71](https://github.com/Automattic/newspack-network/issues/71)) ([d2a76e5](https://github.com/Automattic/newspack-network/commit/d2a76e51084176cef9b6549c0c7940b17411904f))

### Features

- add Network-specific custom metadata to ESP syncs ([#83](https://github.com/Automattic/newspack-network/issues/83)) ([153a733](https://github.com/Automattic/newspack-network/commit/153a733080a78b0035e3b3bd98b7093b9d20f8fb))
- **cli:** --yes option for sync-all command ([268f7fe](https://github.com/Automattic/newspack-network/commit/268f7fed7892ae922ac6106ee824f37058c2ecad))
- **cli:** membership de-duplication CLI command ([#84](https://github.com/Automattic/newspack-network/issues/84)) ([c7ece71](https://github.com/Automattic/newspack-network/commit/c7ece717c297f55e4ae9db0791346e27953dc651))
- **manual-user-sync:** sync user login ([#81](https://github.com/Automattic/newspack-network/issues/81)) ([3f9755e](https://github.com/Automattic/newspack-network/commit/3f9755ed5b75a280c0a0e23b6d91f319ad0b8fa4))

# [1.4.0](https://github.com/Automattic/newspack-network/compare/v1.3.0...v1.4.0) (2024-03-27)

### Features

- add Network-specific custom metadata to ESP syncs ([#83](https://github.com/Automattic/newspack-network/issues/83)) ([#85](https://github.com/Automattic/newspack-network/issues/85)) ([5850129](https://github.com/Automattic/newspack-network/commit/5850129b90109103fbe1f268f8f6f7256f394520))

# [1.4.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.3.0...v1.4.0-alpha.1) (2024-03-26)

### Bug Fixes

- cli commands ([#73](https://github.com/Automattic/newspack-network/issues/73)) ([dc2bef9](https://github.com/Automattic/newspack-network/commit/dc2bef9b3dc2de8357f8deb6c7efb6139eec404f))
- correct typo in manual sync description ([#74](https://github.com/Automattic/newspack-network/issues/74)) ([a0bf23f](https://github.com/Automattic/newspack-network/commit/a0bf23fe8837cf7eefcbb867aa75819dc2e1b531))
- webhook encryption error handling ([#77](https://github.com/Automattic/newspack-network/issues/77)) ([ceee31f](https://github.com/Automattic/newspack-network/commit/ceee31f78fe4452b79485369a38409e5ca32413a))
- woo links in wp-admin-bar ([#71](https://github.com/Automattic/newspack-network/issues/71)) ([d2a76e5](https://github.com/Automattic/newspack-network/commit/d2a76e51084176cef9b6549c0c7940b17411904f))

### Features

- add Network-specific custom metadata to ESP syncs ([#83](https://github.com/Automattic/newspack-network/issues/83)) ([153a733](https://github.com/Automattic/newspack-network/commit/153a733080a78b0035e3b3bd98b7093b9d20f8fb))
- **cli:** --yes option for sync-all command ([268f7fe](https://github.com/Automattic/newspack-network/commit/268f7fed7892ae922ac6106ee824f37058c2ecad))
- **cli:** membership de-duplication CLI command ([#84](https://github.com/Automattic/newspack-network/issues/84)) ([c7ece71](https://github.com/Automattic/newspack-network/commit/c7ece717c297f55e4ae9db0791346e27953dc651))
- **manual-user-sync:** sync user login ([#81](https://github.com/Automattic/newspack-network/issues/81)) ([3f9755e](https://github.com/Automattic/newspack-network/commit/3f9755ed5b75a280c0a0e23b6d91f319ad0b8fa4))

# [1.3.0](https://github.com/Automattic/newspack-network/compare/v1.2.0...v1.3.0) (2024-02-28)

### Bug Fixes

- backfill duplicate handling; woo links in wp-admin-bar ([#71](https://github.com/Automattic/newspack-network/issues/71), [#72](https://github.com/Automattic/newspack-network/issues/72)) ([bbce13b](https://github.com/Automattic/newspack-network/commit/bbce13b9963437d9ef802ac0f6f343fbe59da630))
- cli commands ([#73](https://github.com/Automattic/newspack-network/issues/73)) ([ff563ac](https://github.com/Automattic/newspack-network/commit/ff563acee53b899592269a1121e2552b678cf9c9))
- memberships sync ([#63](https://github.com/Automattic/newspack-network/issues/63)) ([0a54f1d](https://github.com/Automattic/newspack-network/commit/0a54f1dbeb2da8281324ed3fc6323dc5a9100337))
- missing woocommerce-memberships handling ([76dbdf7](https://github.com/Automattic/newspack-network/commit/76dbdf72ae44a27f25c2c35c75720eae637fd518))

### Features

- add hub bookmarks to nodes ([#56](https://github.com/Automattic/newspack-network/issues/56)) ([54700a0](https://github.com/Automattic/newspack-network/commit/54700a0ae4d272254902278bd208abb8df5a0805))
- add option to manually sync users ([#53](https://github.com/Automattic/newspack-network/issues/53)) ([3ec1b19](https://github.com/Automattic/newspack-network/commit/3ec1b1906a504ea83c8f99a673b994cb983b0f83))
- display membership plans from the network ([#59](https://github.com/Automattic/newspack-network/issues/59)) ([6fa01fd](https://github.com/Automattic/newspack-network/commit/6fa01fd0ee903ff3e9a3cd496128811cc1600de3))
- enhance network subscriptions and orders search ([#55](https://github.com/Automattic/newspack-network/issues/55)) ([bcb0615](https://github.com/Automattic/newspack-network/commit/bcb06155cb7d148f3dc735dbd88f566f8cf6f65c))
- Node connection using a link ([#58](https://github.com/Automattic/newspack-network/issues/58)) ([721f8b2](https://github.com/Automattic/newspack-network/commit/721f8b2ed62db8f941bf11789cd3ece577606931))
- **wp-cli:** add command to backfill events ([#51](https://github.com/Automattic/newspack-network/issues/51)) ([13d2498](https://github.com/Automattic/newspack-network/commit/13d24988ba15757f1a228ec87855122b13b45a59))
- **wp-cli:** add process pending webhook requests command ([#67](https://github.com/Automattic/newspack-network/issues/67)) ([7dbd8dc](https://github.com/Automattic/newspack-network/commit/7dbd8dc6813942ad8f457120cf4ad01b84882c3a))
- **wp-cli:** sync all events from the Hub ([#65](https://github.com/Automattic/newspack-network/issues/65)) ([dc595ca](https://github.com/Automattic/newspack-network/commit/dc595ca69ae5bae0da45cb9f96e581a1eb6b2579))

# [1.3.0-alpha.4](https://github.com/Automattic/newspack-network/compare/v1.3.0-alpha.3...v1.3.0-alpha.4) (2024-02-28)

### Bug Fixes

- cli commands ([#73](https://github.com/Automattic/newspack-network/issues/73)) ([ff563ac](https://github.com/Automattic/newspack-network/commit/ff563acee53b899592269a1121e2552b678cf9c9))

# [1.3.0-alpha.3](https://github.com/Automattic/newspack-network/compare/v1.3.0-alpha.2...v1.3.0-alpha.3) (2024-02-28)

### Bug Fixes

- backfill duplicate handling; woo links in wp-admin-bar ([#71](https://github.com/Automattic/newspack-network/issues/71), [#72](https://github.com/Automattic/newspack-network/issues/72)) ([bbce13b](https://github.com/Automattic/newspack-network/commit/bbce13b9963437d9ef802ac0f6f343fbe59da630))

# [1.3.0-alpha.2](https://github.com/Automattic/newspack-network/compare/v1.3.0-alpha.1...v1.3.0-alpha.2) (2024-02-27)

### Bug Fixes

- missing woocommerce-memberships handling ([76dbdf7](https://github.com/Automattic/newspack-network/commit/76dbdf72ae44a27f25c2c35c75720eae637fd518))

# [1.3.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.2.0...v1.3.0-alpha.1) (2024-02-23)

### Bug Fixes

- memberships sync ([#63](https://github.com/Automattic/newspack-network/issues/63)) ([0a54f1d](https://github.com/Automattic/newspack-network/commit/0a54f1dbeb2da8281324ed3fc6323dc5a9100337))

### Features

- add hub bookmarks to nodes ([#56](https://github.com/Automattic/newspack-network/issues/56)) ([54700a0](https://github.com/Automattic/newspack-network/commit/54700a0ae4d272254902278bd208abb8df5a0805))
- add option to manually sync users ([#53](https://github.com/Automattic/newspack-network/issues/53)) ([3ec1b19](https://github.com/Automattic/newspack-network/commit/3ec1b1906a504ea83c8f99a673b994cb983b0f83))
- display membership plans from the network ([#59](https://github.com/Automattic/newspack-network/issues/59)) ([6fa01fd](https://github.com/Automattic/newspack-network/commit/6fa01fd0ee903ff3e9a3cd496128811cc1600de3))
- enhance network subscriptions and orders search ([#55](https://github.com/Automattic/newspack-network/issues/55)) ([bcb0615](https://github.com/Automattic/newspack-network/commit/bcb06155cb7d148f3dc735dbd88f566f8cf6f65c))
- Node connection using a link ([#58](https://github.com/Automattic/newspack-network/issues/58)) ([721f8b2](https://github.com/Automattic/newspack-network/commit/721f8b2ed62db8f941bf11789cd3ece577606931))
- **wp-cli:** add command to backfill events ([#51](https://github.com/Automattic/newspack-network/issues/51)) ([13d2498](https://github.com/Automattic/newspack-network/commit/13d24988ba15757f1a228ec87855122b13b45a59))
- **wp-cli:** add process pending webhook requests command ([#67](https://github.com/Automattic/newspack-network/issues/67)) ([7dbd8dc](https://github.com/Automattic/newspack-network/commit/7dbd8dc6813942ad8f457120cf4ad01b84882c3a))
- **wp-cli:** sync all events from the Hub ([#65](https://github.com/Automattic/newspack-network/issues/65)) ([dc595ca](https://github.com/Automattic/newspack-network/commit/dc595ca69ae5bae0da45cb9f96e581a1eb6b2579))

# [1.2.0](https://github.com/Automattic/newspack-network/compare/v1.1.0...v1.2.0) (2024-02-20)

### Bug Fixes

- assorted error handling fixes ([#52](https://github.com/Automattic/newspack-network/issues/52)) ([234f883](https://github.com/Automattic/newspack-network/commit/234f883716dab7ce9ba2bac8a2bf93e58bbeb887))
- **data-listeners:** handle no user in wc data update ([4eeefc0](https://github.com/Automattic/newspack-network/commit/4eeefc088b12ffdb17db669d9b1f6605c546d1b1))
- dynamic class property deprecated warnings ([#47](https://github.com/Automattic/newspack-network/issues/47)) ([693865c](https://github.com/Automattic/newspack-network/commit/693865c71ea18e3b3aff2810cc61ec407144dd43))
- prevent coauthors capability check infinite loop ([#46](https://github.com/Automattic/newspack-network/issues/46)) ([9565cef](https://github.com/Automattic/newspack-network/commit/9565cefeedd7cfc518b35dd84cce21b171664781))
- race condition when creating a Node ([1946473](https://github.com/Automattic/newspack-network/commit/1946473d0d1baf2bef2c66b5377e0e48b630df75))
- set Yoast primary category ([#41](https://github.com/Automattic/newspack-network/issues/41)) ([3457d19](https://github.com/Automattic/newspack-network/commit/3457d19ab5aea56d13ea2f7179dd1296d563ae7e))

### Features

- **ci:** add epic/\* release workflow and rename `master` to `trunk` ([#39](https://github.com/Automattic/newspack-network/issues/39)) ([9cee51d](https://github.com/Automattic/newspack-network/commit/9cee51dfd407919631df144b11e9599a312cffce))
- sync billing and shipping addresses ([#50](https://github.com/Automattic/newspack-network/issues/50)) ([6a05580](https://github.com/Automattic/newspack-network/commit/6a055808f244ec2ad790a13d068431feb6acdc17))
- sync publish, trash post statuses ([#42](https://github.com/Automattic/newspack-network/issues/42)) ([fd5d8b9](https://github.com/Automattic/newspack-network/commit/fd5d8b9d2c613486a43f061df1caf73bd6fb3979))

# [1.2.0-alpha.3](https://github.com/Automattic/newspack-network/compare/v1.2.0-alpha.2...v1.2.0-alpha.3) (2024-02-16)

### Bug Fixes

- race condition when creating a Node ([1946473](https://github.com/Automattic/newspack-network/commit/1946473d0d1baf2bef2c66b5377e0e48b630df75))

# [1.2.0-alpha.2](https://github.com/Automattic/newspack-network/compare/v1.2.0-alpha.1...v1.2.0-alpha.2) (2024-02-15)

### Bug Fixes

- assorted error handling fixes ([#52](https://github.com/Automattic/newspack-network/issues/52)) ([234f883](https://github.com/Automattic/newspack-network/commit/234f883716dab7ce9ba2bac8a2bf93e58bbeb887))
- dynamic class property deprecated warnings ([#47](https://github.com/Automattic/newspack-network/issues/47)) ([693865c](https://github.com/Automattic/newspack-network/commit/693865c71ea18e3b3aff2810cc61ec407144dd43))
- prevent coauthors capability check infinite loop ([#46](https://github.com/Automattic/newspack-network/issues/46)) ([9565cef](https://github.com/Automattic/newspack-network/commit/9565cefeedd7cfc518b35dd84cce21b171664781))
- set Yoast primary category ([#41](https://github.com/Automattic/newspack-network/issues/41)) ([3457d19](https://github.com/Automattic/newspack-network/commit/3457d19ab5aea56d13ea2f7179dd1296d563ae7e))

### Features

- sync billing and shipping addresses ([#50](https://github.com/Automattic/newspack-network/issues/50)) ([6a05580](https://github.com/Automattic/newspack-network/commit/6a055808f244ec2ad790a13d068431feb6acdc17))
- sync publish, trash post statuses ([#42](https://github.com/Automattic/newspack-network/issues/42)) ([fd5d8b9](https://github.com/Automattic/newspack-network/commit/fd5d8b9d2c613486a43f061df1caf73bd6fb3979))

# [1.2.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.1.0...v1.2.0-alpha.1) (2024-02-08)

### Bug Fixes

- **data-listeners:** handle no user in wc data update ([4eeefc0](https://github.com/Automattic/newspack-network/commit/4eeefc088b12ffdb17db669d9b1f6605c546d1b1))

### Features

- **ci:** add epic/\* release workflow and rename `master` to `trunk` ([#39](https://github.com/Automattic/newspack-network/issues/39)) ([9cee51d](https://github.com/Automattic/newspack-network/commit/9cee51dfd407919631df144b11e9599a312cffce))

# [1.1.0](https://github.com/Automattic/newspack-network/compare/v1.0.1...v1.1.0) (2024-01-25)

### Bug Fixes

- add workaround for distributor bug ([#36](https://github.com/Automattic/newspack-network/issues/36)) ([123ac89](https://github.com/Automattic/newspack-network/commit/123ac894154a6b92321bff92c6b0413357840b2f))

### Features

- add event queue debug ([#34](https://github.com/Automattic/newspack-network/issues/34)) ([a5e48ae](https://github.com/Automattic/newspack-network/commit/a5e48ae516b1b33ace4047eb2ee7f640ff96d514))

# [1.1.0-alpha.1](https://github.com/Automattic/newspack-network/compare/v1.0.1...v1.1.0-alpha.1) (2024-01-25)

### Bug Fixes

- add workaround for distributor bug ([#36](https://github.com/Automattic/newspack-network/issues/36)) ([123ac89](https://github.com/Automattic/newspack-network/commit/123ac894154a6b92321bff92c6b0413357840b2f))

### Features

- add event queue debug ([#34](https://github.com/Automattic/newspack-network/issues/34)) ([a5e48ae](https://github.com/Automattic/newspack-network/commit/a5e48ae516b1b33ace4047eb2ee7f640ff96d514))

## [1.0.1](https://github.com/Automattic/newspack-network/compare/v1.0.0...v1.0.1) (2024-01-18)

### Bug Fixes

- namespace ([7c4d560](https://github.com/Automattic/newspack-network/commit/7c4d5608cbdd79ea0c63091ba04f6ad6e5798436))
- update author distribution on pull ([e9548a5](https://github.com/Automattic/newspack-network/commit/e9548a5dcb313abfc50b056a52a80a70d3432200))

# 1.0.0 (2023-12-22)

### Bug Fixes

- adjust node_id check logic ([9ceb09f](https://github.com/Automattic/newspack-network/commit/9ceb09f762c63f4a51119c310640e48bb511df82))
- canonical url processing ([c9ee2c9](https://github.com/Automattic/newspack-network/commit/c9ee2c9edeb998206882ecdbb8c9f046d0355192))
- dont sync authors terms ([#26](https://github.com/Automattic/newspack-network/issues/26)) ([a4a788b](https://github.com/Automattic/newspack-network/commit/a4a788b1f21ff0e330365d890434cc602c4b4b33))
- failing CI jobs ([#11](https://github.com/Automattic/newspack-network/issues/11)) ([c62248b](https://github.com/Automattic/newspack-network/commit/c62248b3e70cb6c7cdeb017ce2a012b0df7cfbcf))
- **newspack-ads:** parse targeting site url ([#22](https://github.com/Automattic/newspack-network/issues/22)) ([acb84c1](https://github.com/Automattic/newspack-network/commit/acb84c121db9bdb7bab68a267f7ed45ad333bd83))
- remove gam ad targeting ([#16](https://github.com/Automattic/newspack-network/issues/16)) ([c47cb15](https://github.com/Automattic/newspack-network/commit/c47cb15efb52d6a4470f85e5e1df9a1898faa2b6))

### Features

- add circle ci config ([3f9d752](https://github.com/Automattic/newspack-network/commit/3f9d752ad18819e9c3525e8fdec90166c817dfc4))
- Add site role selection and unify menus ([bbc8eb5](https://github.com/Automattic/newspack-network/commit/bbc8eb5cd6eba8b228eb5e54356e0a11537072db))
- add support to local Woo events ([1273ee1](https://github.com/Automattic/newspack-network/commit/1273ee1c52e711481cecb5401c3b965504d0e437))
- **ads:** support network targeting key-val ([#13](https://github.com/Automattic/newspack-network/issues/13)) ([37f677a](https://github.com/Automattic/newspack-network/commit/37f677aedd1cd9932bca942a84667c7e6315f784))
- allow editors to pull content ([#28](https://github.com/Automattic/newspack-network/issues/28)) ([3021f7e](https://github.com/Automattic/newspack-network/commit/3021f7e3d50876e2655a2955a5fe64cb25a7dba9))
- bump limit of events pulled by nodes from to 2 to 20 ([#7](https://github.com/Automattic/newspack-network/issues/7)) ([c5fbdc5](https://github.com/Automattic/newspack-network/commit/c5fbdc56526034760081156208a49820de0f3d85))
- network donation events as reader data ([#8](https://github.com/Automattic/newspack-network/issues/8)) ([9a114a4](https://github.com/Automattic/newspack-network/commit/9a114a49bd1fa6d47321e979e096587c481f183e))
- **node:** debug tools ([#12](https://github.com/Automattic/newspack-network/issues/12)) ([07b5470](https://github.com/Automattic/newspack-network/commit/07b5470b619a900a1cf264e24fead3ca6ff9cc5b))
- refactor to symetric crypto ([1f55551](https://github.com/Automattic/newspack-network/commit/1f555513cb282446c075f4e296711298feea12e8))
- sync author bio and meta ([#15](https://github.com/Automattic/newspack-network/issues/15)) ([f60236d](https://github.com/Automattic/newspack-network/commit/f60236dfb1324d8d7da7e09eb386047b7d02a3fa))
- sync user avatar ([#21](https://github.com/Automattic/newspack-network/issues/21)) ([70e4c83](https://github.com/Automattic/newspack-network/commit/70e4c834067cf3a2571a7b26b3b570e6c36ad376))
- update authors on post update ([#29](https://github.com/Automattic/newspack-network/issues/29)) ([25cb65f](https://github.com/Automattic/newspack-network/commit/25cb65f1f89cc6c4ac05b3be0f4aaab5029440c1))
- update menu icon and position ([#18](https://github.com/Automattic/newspack-network/issues/18)) ([11638b2](https://github.com/Automattic/newspack-network/commit/11638b22b50b555c4310336ebdd9df1f4df6ba23))

# [1.0.0-alpha.2](https://github.com/Automattic/newspack-network/compare/v1.0.0-alpha.1...v1.0.0-alpha.2) (2023-12-22)

### Bug Fixes

- dont sync authors terms ([#26](https://github.com/Automattic/newspack-network/issues/26)) ([a4a788b](https://github.com/Automattic/newspack-network/commit/a4a788b1f21ff0e330365d890434cc602c4b4b33))
- **newspack-ads:** parse targeting site url ([#22](https://github.com/Automattic/newspack-network/issues/22)) ([acb84c1](https://github.com/Automattic/newspack-network/commit/acb84c121db9bdb7bab68a267f7ed45ad333bd83))

### Features

- allow editors to pull content ([#28](https://github.com/Automattic/newspack-network/issues/28)) ([3021f7e](https://github.com/Automattic/newspack-network/commit/3021f7e3d50876e2655a2955a5fe64cb25a7dba9))
- network donation events as reader data ([#8](https://github.com/Automattic/newspack-network/issues/8)) ([9a114a4](https://github.com/Automattic/newspack-network/commit/9a114a49bd1fa6d47321e979e096587c481f183e))
- sync user avatar ([#21](https://github.com/Automattic/newspack-network/issues/21)) ([70e4c83](https://github.com/Automattic/newspack-network/commit/70e4c834067cf3a2571a7b26b3b570e6c36ad376))
- update authors on post update ([#29](https://github.com/Automattic/newspack-network/issues/29)) ([25cb65f](https://github.com/Automattic/newspack-network/commit/25cb65f1f89cc6c4ac05b3be0f4aaab5029440c1))
- update menu icon and position ([#18](https://github.com/Automattic/newspack-network/issues/18)) ([11638b2](https://github.com/Automattic/newspack-network/commit/11638b22b50b555c4310336ebdd9df1f4df6ba23))

# 1.0.0-alpha.1 (2023-12-01)

### Bug Fixes

- adjust node_id check logic ([9ceb09f](https://github.com/Automattic/newspack-network/commit/9ceb09f762c63f4a51119c310640e48bb511df82))
- canonical url processing ([c9ee2c9](https://github.com/Automattic/newspack-network/commit/c9ee2c9edeb998206882ecdbb8c9f046d0355192))
- failing CI jobs ([#11](https://github.com/Automattic/newspack-network/issues/11)) ([c62248b](https://github.com/Automattic/newspack-network/commit/c62248b3e70cb6c7cdeb017ce2a012b0df7cfbcf))
- remove gam ad targeting ([#16](https://github.com/Automattic/newspack-network/issues/16)) ([c47cb15](https://github.com/Automattic/newspack-network/commit/c47cb15efb52d6a4470f85e5e1df9a1898faa2b6))

### Features

- add circle ci config ([3f9d752](https://github.com/Automattic/newspack-network/commit/3f9d752ad18819e9c3525e8fdec90166c817dfc4))
- Add site role selection and unify menus ([bbc8eb5](https://github.com/Automattic/newspack-network/commit/bbc8eb5cd6eba8b228eb5e54356e0a11537072db))
- add support to local Woo events ([1273ee1](https://github.com/Automattic/newspack-network/commit/1273ee1c52e711481cecb5401c3b965504d0e437))
- bump limit of events pulled by nodes from to 2 to 20 ([#7](https://github.com/Automattic/newspack-network/issues/7)) ([c5fbdc5](https://github.com/Automattic/newspack-network/commit/c5fbdc56526034760081156208a49820de0f3d85))
- refactor to symetric crypto ([1f55551](https://github.com/Automattic/newspack-network/commit/1f555513cb282446c075f4e296711298feea12e8))
- **ads:** support network targeting key-val ([#13](https://github.com/Automattic/newspack-network/issues/13)) ([37f677a](https://github.com/Automattic/newspack-network/commit/37f677aedd1cd9932bca942a84667c7e6315f784))
- bump limit of events pulled by nodes from to 2 to 20 ([#7](https://github.com/Automattic/newspack-network/issues/7)) ([c5fbdc5](https://github.com/Automattic/newspack-network/commit/c5fbdc56526034760081156208a49820de0f3d85))
- **node:** debug tools ([#12](https://github.com/Automattic/newspack-network/issues/12)) ([07b5470](https://github.com/Automattic/newspack-network/commit/07b5470b619a900a1cf264e24fead3ca6ff9cc5b))
- refactor to symetric crypto ([1f55551](https://github.com/Automattic/newspack-network/commit/1f555513cb282446c075f4e296711298feea12e8))
- sync author bio and meta ([#15](https://github.com/Automattic/newspack-network/issues/15)) ([f60236d](https://github.com/Automattic/newspack-network/commit/f60236dfb1324d8d7da7e09eb386047b7d02a3fa))
