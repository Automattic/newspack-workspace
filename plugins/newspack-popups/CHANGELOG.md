## newspack-popups [3.13.1](https://github.com/Automattic/newspack-workspace/compare/newspack-popups@3.13.0...newspack-popups@3.13.1) (2026-06-25)


### Bug Fixes

* **popups:** prevent stale prompt settings saves ([#266](https://github.com/Automattic/newspack-workspace/issues/266)) ([#427](https://github.com/Automattic/newspack-workspace/issues/427)) ([2f5e8cf](https://github.com/Automattic/newspack-workspace/commit/2f5e8cfa81b130d72eb175b2e4961d5fe2f9d7a3))

# newspack-popups [3.13.0](https://github.com/Automattic/newspack-workspace/compare/newspack-popups@3.12.2...newspack-popups@3.13.0) (2026-06-23)


### Features

* **popups:** emit prompt_has_checkout on prompt interaction event ([#398](https://github.com/Automattic/newspack-workspace/issues/398)) ([c7472f7](https://github.com/Automattic/newspack-workspace/commit/c7472f763747872d14b553afa1771491a5312f65))

## newspack-popups [3.12.2](https://github.com/Automattic/newspack-workspace/compare/newspack-popups@3.12.1...newspack-popups@3.12.2) (2026-06-18)


### Bug Fixes

* **popups:** drop disabled criteria from segment criteria array ([#209](https://github.com/Automattic/newspack-workspace/issues/209)) ([5b5d092](https://github.com/Automattic/newspack-workspace/commit/5b5d092af473c7ee0604d0d90e69f0eeb4fbd52c))

## newspack-popups [3.12.1](https://github.com/Automattic/newspack-workspace/compare/newspack-popups@3.12.0...newspack-popups@3.12.1) (2026-06-02)


### Bug Fixes

* **popups:** portal overlays to wp_footer to escape stacking contexts ([797c42c](https://github.com/Automattic/newspack-workspace/commit/797c42c6bd7af9245226205cd31382700b7827ad))

# [3.12.0](https://github.com/Automattic/newspack-popups/compare/v3.11.0...v3.12.0) (2026-05-18)


### Bug Fixes

* add is_home() gate to archive_page_types and fix return to continue ([#1551](https://github.com/Automattic/newspack-popups/issues/1551)) ([b6a51be](https://github.com/Automattic/newspack-popups/commit/b6a51be6fc08422d595e0c62ddf4f4e23c06f889))
* ensure background colour, size work in iframed editor (NEWS-1707) ([#1550](https://github.com/Automattic/newspack-popups/issues/1550)) ([7ebf73e](https://github.com/Automattic/newspack-popups/commit/7ebf73e3f4a70e5f8ea85119e0456d5462dda5a4))
* handle invalid post id in retrieve_preview_popup ([#1555](https://github.com/Automattic/newspack-popups/issues/1555)) ([a67ce47](https://github.com/Automattic/newspack-popups/commit/a67ce476036ba4408ac1cc5a045882af91aedb6d))


### Features

* add campaigns to block theme archives ([#1545](https://github.com/Automattic/newspack-popups/issues/1545)) ([f2944b0](https://github.com/Automattic/newspack-popups/commit/f2944b0a72a462cbb98f0b033cd396eccc61830e))

# [3.11.0](https://github.com/Automattic/newspack-popups/compare/v3.10.3...v3.11.0) (2026-05-04)


### Bug Fixes

* replace favorite categories matching ([#1542](https://github.com/Automattic/newspack-popups/issues/1542)) ([88391d2](https://github.com/Automattic/newspack-popups/commit/88391d2ae0920d191a5e256d66b6fda2eb3dfac4))


### Features

* reader activation segments ([#1539](https://github.com/Automattic/newspack-popups/issues/1539)) ([606d706](https://github.com/Automattic/newspack-popups/commit/606d706cb64a1bb8b01b03a06cd4ed8da899bed4))

## [3.10.3](https://github.com/Automattic/newspack-popups/compare/v3.10.2...v3.10.3) (2026-04-13)


### Bug Fixes

* trigger release ([b04af9e](https://github.com/Automattic/newspack-popups/commit/b04af9e5e506a302cdb291ab16c6ef4faf9a4b7e))

## [3.10.2](https://github.com/Automattic/newspack-popups/compare/v3.10.1...v3.10.2) (2026-04-06)


### Bug Fixes

* **inserter:** trim leading whitespace before content validation comparison ([e0b9f3f](https://github.com/Automattic/newspack-popups/commit/e0b9f3f9cc8a9c889ab4980bbe31aa7aa7c5d39a))

## [3.10.1](https://github.com/Automattic/newspack-popups/compare/v3.10.0...v3.10.1) (2026-03-30)


### Bug Fixes

* **block-theme:** move where above header campaign is inserted ([#1533](https://github.com/Automattic/newspack-popups/issues/1533)) ([eb6bfba](https://github.com/Automattic/newspack-popups/commit/eb6bfba2c7cd2560f7bf7aa04371aea467b5d291))

# [3.10.0](https://github.com/Automattic/newspack-popups/compare/v3.9.2...v3.10.0) (2026-03-16)


### Bug Fixes

* add is-layout-constrained and is-layout-flow CSS classes for block themes ([#1521](https://github.com/Automattic/newspack-popups/issues/1521)) ([bafa61f](https://github.com/Automattic/newspack-popups/commit/bafa61fef81bdd81489abde5b139bc3b471d4791))
* give more specific overlays priority by tweaking DOM order ([f0a64ef](https://github.com/Automattic/newspack-popups/commit/f0a64ef801e4987281619e9658d40e8379d06080))
* give more specific overlays priority by tweaking DOM order ([b8abd19](https://github.com/Automattic/newspack-popups/commit/b8abd1917ee1ec02800590eb76b9a72f16f51c57))
* iframe editor compatibility ([#1520](https://github.com/Automattic/newspack-popups/issues/1520)) ([222f33c](https://github.com/Automattic/newspack-popups/commit/222f33cea5ec039b501ab75b2753459d74b6d397))
* leave inline (non-overlay) popups in default non-sort ([a9c6f64](https://github.com/Automattic/newspack-popups/commit/a9c6f6483d24207b8989e20126218d9663492a71))
* run do_shortcode on manual, custom placements for the block theme ([#1526](https://github.com/Automattic/newspack-popups/issues/1526)) ([adbca1c](https://github.com/Automattic/newspack-popups/commit/adbca1cabc374591278b41f29749d2d0d0cbb6da))


### Features

* add AI agent guidelines ([#1530](https://github.com/Automattic/newspack-popups/issues/1530)) ([d0c4eb4](https://github.com/Automattic/newspack-popups/commit/d0c4eb4185fc005782f646ef5ce4ab8abb120691))

## [3.9.2](https://github.com/Automattic/newspack-popups/compare/v3.9.1...v3.9.2) (2026-02-13)


### Bug Fixes

* emoji encoding in DOM::loadHTML ([71a6d3f](https://github.com/Automattic/newspack-popups/commit/71a6d3fbc150fd2c8c53980fab975c603cea06db))

## [3.9.1](https://github.com/Automattic/newspack-popups/compare/v3.9.0...v3.9.1) (2026-02-05)


### Bug Fixes

* migrate expiry check from daily to hourly ([#1523](https://github.com/Automattic/newspack-popups/issues/1523)) ([14f30d7](https://github.com/Automattic/newspack-popups/commit/14f30d7e77fd0f5147bf2130ab2b8d9a6986a487)), closes [#1524](https://github.com/Automattic/newspack-popups/issues/1524)
* trigger release ([0fee161](https://github.com/Automattic/newspack-popups/commit/0fee16130f1cd7fadd485c71280643b38fd54374))

## [3.9.1](https://github.com/Automattic/newspack-popups/compare/v3.9.0...v3.9.1) (2026-02-05)


### Bug Fixes

* migrate expiry check from daily to hourly ([#1523](https://github.com/Automattic/newspack-popups/issues/1523)) ([14f30d7](https://github.com/Automattic/newspack-popups/commit/14f30d7e77fd0f5147bf2130ab2b8d9a6986a487)), closes [#1524](https://github.com/Automattic/newspack-popups/issues/1524)

# [3.9.0](https://github.com/Automattic/newspack-popups/compare/v3.8.4...v3.9.0) (2026-02-02)


### Bug Fixes

* force alpha release ([9dfc1fe](https://github.com/Automattic/newspack-popups/commit/9dfc1fe46bbb7de5424345afae078651f9459d04))
* remove form_submission from prompt data events ([#1510](https://github.com/Automattic/newspack-popups/issues/1510)) ([2c615e4](https://github.com/Automattic/newspack-popups/commit/2c615e4e909817f0232d2cbfd178a7fb992e7cbb))
* rename prompt_id to newspack_popup_id for consistency ([#1509](https://github.com/Automattic/newspack-popups/issues/1509)) ([b9b1048](https://github.com/Automattic/newspack-popups/commit/b9b10489578603c2f3a1f505ae7666ea0213468d))


### Features

* make prompt_title available to modal checkout data events when available ([#1508](https://github.com/Automattic/newspack-popups/issues/1508)) ([3ea5e50](https://github.com/Automattic/newspack-popups/commit/3ea5e50cee0fce54c50f54672672bb8022c48c29))

## [3.8.4](https://github.com/Automattic/newspack-popups/compare/v3.8.3...v3.8.4) (2026-01-19)


### Bug Fixes

* **apple-news:** disable prompts when exporting to apple news ([#1499](https://github.com/Automattic/newspack-popups/issues/1499)) ([cb6ed91](https://github.com/Automattic/newspack-popups/commit/cb6ed91c17d8e319598299dc8f62d7d12212b575))

## [3.8.3](https://github.com/Automattic/newspack-popups/compare/v3.8.2...v3.8.3) (2026-01-12)


### Bug Fixes

* ignore corrections when validating content for inline prompts ([#1511](https://github.com/Automattic/newspack-popups/issues/1511)) ([034ebed](https://github.com/Automattic/newspack-popups/commit/034ebed8e4cee5402aa10f2a3097ad57df0b4ac4))

## [3.8.2](https://github.com/Automattic/newspack-popups/compare/v3.8.1...v3.8.2) (2026-01-06)


### Bug Fixes

* **prompt-expiration:** evaluate expiration timestamp in site timezone ([#1507](https://github.com/Automattic/newspack-popups/issues/1507)) ([c4114b1](https://github.com/Automattic/newspack-popups/commit/c4114b1fd4d439b1d98d880ef2a4eb0ad8ecf7eb))

## [3.8.1](https://github.com/Automattic/newspack-popups/compare/v3.8.0...v3.8.1) (2025-12-08)


### Bug Fixes

* make sure custom placements follow post type restrictions ([#1500](https://github.com/Automattic/newspack-popups/issues/1500)) ([488b71d](https://github.com/Automattic/newspack-popups/commit/488b71d99e9ac63d983a229aa74888b2cef97131))

# [3.8.0](https://github.com/Automattic/newspack-popups/compare/v3.7.1...v3.8.0) (2025-11-24)


### Features

* trigger alpha release ([3860726](https://github.com/Automattic/newspack-popups/commit/38607266ccffbbf83d8db2c9999f8b4611faf9d4))

## [3.7.1](https://github.com/Automattic/newspack-popups/compare/v3.7.0...v3.7.1) (2025-11-10)


### Bug Fixes

* revert [#1485](https://github.com/Automattic/newspack-popups/issues/1485) ([#1487](https://github.com/Automattic/newspack-popups/issues/1487)) ([e811f4f](https://github.com/Automattic/newspack-popups/commit/e811f4fdb373edb4c106756e23fc0e6431a1fa81))
* update metering class namespace ([#1486](https://github.com/Automattic/newspack-popups/issues/1486)) ([8e13e05](https://github.com/Automattic/newspack-popups/commit/8e13e0506601608e8978a97921f9e3441ebe4c3c))

# [3.7.0](https://github.com/Automattic/newspack-popups/compare/v3.6.2...v3.7.0) (2025-10-30)


### Bug Fixes

* update download URL for db.php ([#1474](https://github.com/Automattic/newspack-popups/issues/1474)) ([6296100](https://github.com/Automattic/newspack-popups/commit/62961006f008964a17e18cbb7980f0d4223a5d4f))


### Features

* update ras default with modern style ([#1477](https://github.com/Automattic/newspack-popups/issues/1477)) ([96028e7](https://github.com/Automattic/newspack-popups/commit/96028e794ef7c6ed0a39f400b910d36359150e06))

## [3.6.2](https://github.com/Automattic/newspack-popups/compare/v3.6.1...v3.6.2) (2025-10-16)


### Bug Fixes

* don’t add overlays to ras store if locked via front-end metering ([#1480](https://github.com/Automattic/newspack-popups/issues/1480)) ([8abfc1d](https://github.com/Automattic/newspack-popups/commit/8abfc1d4c667626742aba445822f77b7a46865f4))

## [3.6.1](https://github.com/Automattic/newspack-popups/compare/v3.6.0...v3.6.1) (2025-09-08)


### Bug Fixes

* scheduling expiry check for midnight using AS ([#1467](https://github.com/Automattic/newspack-popups/issues/1467)) ([9ccb1fa](https://github.com/Automattic/newspack-popups/commit/9ccb1faebeaa16777780f67cddbb6f3e00df754b))

# [3.6.0](https://github.com/Automattic/newspack-popups/compare/v3.5.4...v3.6.0) (2025-08-25)


### Features

* add i18n ([#1460](https://github.com/Automattic/newspack-popups/issues/1460)) ([1462aca](https://github.com/Automattic/newspack-popups/commit/1462aca5a5e933a0c3f3024ead24c11cbf9c817f))

## [3.5.4](https://github.com/Automattic/newspack-popups/compare/v3.5.3...v3.5.4) (2025-08-11)


### Bug Fixes

* **previews:** handle categories ([#1456](https://github.com/Automattic/newspack-popups/issues/1456)) ([b81bac0](https://github.com/Automattic/newspack-popups/commit/b81bac07e8b80f55e69a3636ae8b8f3ddf30d21d))
* **rendering:** enqueue styles for jetpack form ([#1457](https://github.com/Automattic/newspack-popups/issues/1457)) ([71faf34](https://github.com/Automattic/newspack-popups/commit/71faf343d7148e8cf642bf2a2d48a2ea03050b32))
* Support Unit Tests for php 8.3 ([#1455](https://github.com/Automattic/newspack-popups/issues/1455)) ([65c657b](https://github.com/Automattic/newspack-popups/commit/65c657b59725c2d3ca72aac84f32c81968e52bdd))

## [3.5.3](https://github.com/Automattic/newspack-popups/compare/v3.5.2...v3.5.3) (2025-06-16)


### Bug Fixes

* correct URL for segments admin link ([#1444](https://github.com/Automattic/newspack-popups/issues/1444)) ([3e09294](https://github.com/Automattic/newspack-popups/commit/3e09294835d4f9f377ca6e0ed66b960d734a4d45))

## [3.5.2](https://github.com/Automattic/newspack-popups/compare/v3.5.1...v3.5.2) (2025-06-02)


### Bug Fixes

* **pageviews:** enqueue logPageView JS before segmentation JS ([#1436](https://github.com/Automattic/newspack-popups/issues/1436)) ([9191d68](https://github.com/Automattic/newspack-popups/commit/9191d6854eb419534c58685759ba6889bd988821))
* **preview:** enqueue styles and scripts in preview mode ([#1430](https://github.com/Automattic/newspack-popups/issues/1430)) ([44d02a4](https://github.com/Automattic/newspack-popups/commit/44d02a4377e7860732d3410e7c0b902e1f03c6ed))
* segmentation link ([#1427](https://github.com/Automattic/newspack-popups/issues/1427)) ([0512d58](https://github.com/Automattic/newspack-popups/commit/0512d5871fc79fac9c9ace24e8f6d9ee21f3d35a))
* **segmentation:** log page view even if page is not showing prompts ([#1428](https://github.com/Automattic/newspack-popups/issues/1428)) ([f99fa3e](https://github.com/Automattic/newspack-popups/commit/f99fa3e5a45aeacba77aeb697e0fa32cbb9ffd32))

## [3.5.1](https://github.com/Automattic/newspack-popups/compare/v3.5.0...v3.5.1) (2025-05-29)


### Bug Fixes

* **data:** hidden field integration with Checkout Button block ([#1441](https://github.com/Automattic/newspack-popups/issues/1441)) ([6fac0c7](https://github.com/Automattic/newspack-popups/commit/6fac0c763614f09e2ba93c33a6a697836548bf76))

# [3.5.0](https://github.com/Automattic/newspack-popups/compare/v3.4.3...v3.5.0) (2025-05-14)


### Bug Fixes

* pass popup ID param through order ([#1424](https://github.com/Automattic/newspack-popups/issues/1424)) ([2058424](https://github.com/Automattic/newspack-popups/commit/2058424119c2d35a214b7c572d1658a292775bfd))
* remove "-popup" suffix from method params, no longer needed ([#1431](https://github.com/Automattic/newspack-popups/issues/1431)) ([843282e](https://github.com/Automattic/newspack-popups/commit/843282e6485856caf1227d48103548dd11667364))
* **segmentation:** range criteria ([#1419](https://github.com/Automattic/newspack-popups/issues/1419)) ([c50d60a](https://github.com/Automattic/newspack-popups/commit/c50d60a7ce0f5da49b5b5f97e7a9dbcdd0348f45))


### Features

* **criteria:** option parameters ([#1421](https://github.com/Automattic/newspack-popups/issues/1421)) ([3ed2b4a](https://github.com/Automattic/newspack-popups/commit/3ed2b4aabd4f0eb2cc8795a45c718eb92bf2a15d))
* **segments:** add screen size criteria ([#1414](https://github.com/Automattic/newspack-popups/issues/1414)) ([5832984](https://github.com/Automattic/newspack-popups/commit/5832984257ea0a4f38e79a9ef8910e0a66979244))

## [3.4.3](https://github.com/Automattic/newspack-popups/compare/v3.4.2...v3.4.3) (2025-05-08)


### Bug Fixes

* **segmentation:** should prompt display logic ([#1433](https://github.com/Automattic/newspack-popups/issues/1433)) ([5ab76eb](https://github.com/Automattic/newspack-popups/commit/5ab76eb68b76b788c32fa92efdaa067c7e0911ad))

## [3.4.2](https://github.com/Automattic/newspack-popups/compare/v3.4.1...v3.4.2) (2025-05-05)


### Bug Fixes

* restore UTM suppression for overlay prompts ([#1429](https://github.com/Automattic/newspack-popups/issues/1429)) ([e6c82ff](https://github.com/Automattic/newspack-popups/commit/e6c82ff145e0daa30641b5a1db14656162545a44))

## [3.4.1](https://github.com/Automattic/newspack-popups/compare/v3.4.0...v3.4.1) (2025-04-14)


### Bug Fixes

* update merge tags data reference to use window ([#1410](https://github.com/Automattic/newspack-popups/issues/1410)) ([c66bdb9](https://github.com/Automattic/newspack-popups/commit/c66bdb9e19f3746de3c7ebfcc4d687c56ecb12e1))

# [3.4.0](https://github.com/Automattic/newspack-popups/compare/v3.3.0...v3.4.0) (2025-04-07)


### Features

* merge tags outside the feature flag ([#1417](https://github.com/Automattic/newspack-popups/issues/1417)) ([7821498](https://github.com/Automattic/newspack-popups/commit/7821498e54d765995dd24eab149adf1fef6ccf6a))

# [3.3.0](https://github.com/Automattic/newspack-popups/compare/v3.2.1...v3.3.0) (2025-03-31)


### Features

* information architecture ([4a7f3f6](https://github.com/Automattic/newspack-popups/commit/4a7f3f6e7e0b89f715ccfa2006a39e8208f8d28e))
* prompt merge tags ([#1400](https://github.com/Automattic/newspack-popups/issues/1400)) ([5bcb5c2](https://github.com/Automattic/newspack-popups/commit/5bcb5c2acfd218b751f608fd0347b6d79402f5be))

## [3.2.1](https://github.com/Automattic/newspack-popups/compare/v3.2.0...v3.2.1) (2025-03-05)


### Bug Fixes

* **data-events:** handle prompts with nested blocks in data events metadata ([#1401](https://github.com/Automattic/newspack-popups/issues/1401)) ([735d614](https://github.com/Automattic/newspack-popups/commit/735d614150eb4518b4ec34318d83326b716c8647))

# [3.2.0](https://github.com/Automattic/newspack-popups/compare/v3.1.7...v3.2.0) (2025-03-04)


### Bug Fixes

* make a real no-padding option for popups ([#1389](https://github.com/Automattic/newspack-popups/issues/1389)) ([fbfbe41](https://github.com/Automattic/newspack-popups/commit/fbfbe41ca6cd3ce18a87c23e1de54d588378f0e0))


### Features

* add control to change close button color ([#1396](https://github.com/Automattic/newspack-popups/issues/1396)) ([1689de9](https://github.com/Automattic/newspack-popups/commit/1689de94fdbbc076b29325b4ba0e4018ec85a80e))
* update brand colours ([#1394](https://github.com/Automattic/newspack-popups/issues/1394)) ([158adb3](https://github.com/Automattic/newspack-popups/commit/158adb3dfb6babe572973f664400f458e730447d))

## [3.1.7](https://github.com/Automattic/newspack-popups/compare/v3.1.6...v3.1.7) (2025-02-26)


### Bug Fixes

* restore UTM suppression feature ([#1399](https://github.com/Automattic/newspack-popups/issues/1399)) ([351d63a](https://github.com/Automattic/newspack-popups/commit/351d63afb568f9528619f6ffc7591d8a9639fd41))

## [3.1.6](https://github.com/Automattic/newspack-popups/compare/v3.1.5...v3.1.6) (2024-12-16)


### Bug Fixes

* copy original segments when duplicating prompt ([#1373](https://github.com/Automattic/newspack-popups/issues/1373)) ([96ba0ca](https://github.com/Automattic/newspack-popups/commit/96ba0ca989732614c43ca24cf85cd011f11e44ff))

## [3.1.5](https://github.com/Automattic/newspack-popups/compare/v3.1.4...v3.1.5) (2024-12-09)


### Bug Fixes

* hide cpt from menu ([#1365](https://github.com/Automattic/newspack-popups/issues/1365)) ([6ec34ee](https://github.com/Automattic/newspack-popups/commit/6ec34eed785a73b0f621120a029e56a8f38f74f7))
* prevent issue with double-scrollbars in really specific circumstances ([#1370](https://github.com/Automattic/newspack-popups/issues/1370)) ([c35772f](https://github.com/Automattic/newspack-popups/commit/c35772f65b429f3fdbfc9afe7cc0166685fd7a2a))

## [3.1.4](https://github.com/Automattic/newspack-popups/compare/v3.1.3...v3.1.4) (2024-11-25)


### Bug Fixes

* avoid fatal on get_segments ([#1359](https://github.com/Automattic/newspack-popups/issues/1359)) ([2fb88fb](https://github.com/Automattic/newspack-popups/commit/2fb88fbe7031d050397d46edffd7956902dd4e94))

## [3.1.3](https://github.com/Automattic/newspack-popups/compare/v3.1.2...v3.1.3) (2024-11-19)


### Bug Fixes

* ensure prompts with expiration dates expire correctly ([#1371](https://github.com/Automattic/newspack-popups/issues/1371)) ([85a63b6](https://github.com/Automattic/newspack-popups/commit/85a63b656208e6f73560dd975997d83462148682))

## [3.1.2](https://github.com/Automattic/newspack-popups/compare/v3.1.1...v3.1.2) (2024-11-13)


### Bug Fixes

* ensure localized segments object is an object, not array ([#1364](https://github.com/Automattic/newspack-popups/issues/1364)) ([3b2403f](https://github.com/Automattic/newspack-popups/commit/3b2403fd6ba1f65ed944120c3fa1961cf6a87208))

## [3.1.1](https://github.com/Automattic/newspack-popups/compare/v3.1.0...v3.1.1) (2024-11-11)


### Bug Fixes

* prevent extra scrollbar ([#1354](https://github.com/Automattic/newspack-popups/issues/1354)) ([5138051](https://github.com/Automattic/newspack-popups/commit/5138051e2b0ff4a7010b872546a799ab87a36498))
* remove inline-block display type ([#1356](https://github.com/Automattic/newspack-popups/issues/1356)) ([d2b0f21](https://github.com/Automattic/newspack-popups/commit/d2b0f211d1bd66fbae3862a3f38d4e8dc2507c89))

# [3.1.0](https://github.com/Automattic/newspack-popups/compare/v3.0.1...v3.1.0) (2024-10-08)


### Bug Fixes

* check for non-preview logged in user on account criteria ([#1347](https://github.com/Automattic/newspack-popups/issues/1347)) ([9f6f062](https://github.com/Automattic/newspack-popups/commit/9f6f062a9b121e626fca8043c97935b9493ea78a))
* prioritize ras overlays for delayed prompts ([21c3091](https://github.com/Automattic/newspack-popups/commit/21c30910affecefe0ce137cc9f3f6f5d00c2ce39))
* prioritize ras overlays for delayed prompts [#1341](https://github.com/Automattic/newspack-popups/issues/1341)  ([c0027d1](https://github.com/Automattic/newspack-popups/commit/c0027d1b28e5a503133898f33d55e8e3b377d635))
* verify delayed prompt can still be displayed before showing ([#1344](https://github.com/Automattic/newspack-popups/issues/1344)) ([235f36b](https://github.com/Automattic/newspack-popups/commit/235f36b4915177da4b2a7a438cc2a29b79a376c5))


### Features

* store prompt activation date ([#1340](https://github.com/Automattic/newspack-popups/issues/1340)) ([2c11424](https://github.com/Automattic/newspack-popups/commit/2c114246949b394e8307c5e53e608b173d55c8e4))

## [3.0.1](https://github.com/Automattic/newspack-popups/compare/v3.0.0...v3.0.1) (2024-09-04)


### Bug Fixes

* **segmentation:** handle bad MC API key ([aecc659](https://github.com/Automattic/newspack-popups/commit/aecc6591d57b1a6b560c264ba7145e95434ae7ff))

# [3.0.0](https://github.com/Automattic/newspack-popups/compare/v2.34.1...v3.0.0) (2024-08-13)


### Bug Fixes

* update dependencies to support `@wordpress/scripts` ([#1316](https://github.com/Automattic/newspack-popups/issues/1316)) ([a541907](https://github.com/Automattic/newspack-popups/commit/a541907351b03368df1c1810ae8ed6abae8d4987))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for updating dependencies in newspack-scripts

* chore: update NPM version to lts/*

* chore: update package-lock.json

* refactor: use proxy script for eslint and stylelint scripts

* fix: peer dependencies

* deps: newspack-scripts@5.6.0-alpha.1; remove ajv

* deps: update lint-staged (4066519d9c0f43aacb6961efa403994a4d8dd7a2)

* chore: update newspack-scripts to v5.6.0-alpha.3

* chore: add .stylelintrc.js

* fix: remove outdated "main" entrypoint from package.json

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* fix: stylelint scripts with newspack-scripts@5.6.0-alpha.6

* fix: stylelint version with newspack-scripts@5.6.0-alpha.7

* fix: reformat SCSS

* chore: update newspack-scripts to v5.6.0-alpha.8

* chore: update .blog URL

* chore: add NPM scripts for PHP

* chore: update newspack-components to v3.0.0

* chore: bump newspack-scripts to v5.5.2

## [2.34.1](https://github.com/Automattic/newspack-popups/compare/v2.34.0...v2.34.1) (2024-07-12)


### Bug Fixes

* use loose type comparison for list__in and list__not_in ([#1326](https://github.com/Automattic/newspack-popups/issues/1326)) ([1872c96](https://github.com/Automattic/newspack-popups/commit/1872c961189ae58becbb12c637e3866e421f3779))

# [2.34.0](https://github.com/Automattic/newspack-popups/compare/v2.33.1...v2.34.0) (2024-07-01)


### Bug Fixes

* update newspack-scripts to v5.5.1 ([#1320](https://github.com/Automattic/newspack-popups/issues/1320)) ([554ba18](https://github.com/Automattic/newspack-popups/commit/554ba186520ebf5566b204d162a6e22458c09080))


### Features

* ensure regenerator-runtime is available (for WP 6.6) ([8ee878e](https://github.com/Automattic/newspack-popups/commit/8ee878ede71517b270d8e164c6cef20c5af987be))

## [2.33.1](https://github.com/Automattic/newspack-popups/compare/v2.33.0...v2.33.1) (2024-06-18)


### Bug Fixes

* prevent prompt expiration with invalid expiration date ([#1318](https://github.com/Automattic/newspack-popups/issues/1318)) ([a83cf2a](https://github.com/Automattic/newspack-popups/commit/a83cf2af88feb1448b75313bb371b68e62898fc8))

# [2.33.0](https://github.com/Automattic/newspack-popups/compare/v2.32.0...v2.33.0) (2024-06-12)


### Features

* prompt expiration date ([#1305](https://github.com/Automattic/newspack-popups/issues/1305)) ([301c41c](https://github.com/Automattic/newspack-popups/commit/301c41cd55e8ed468afe7e6b614be11a61db441d))

# [2.32.0](https://github.com/Automattic/newspack-popups/compare/v2.31.3...v2.32.0) (2024-05-28)


### Bug Fixes

* improved attribute handling ([f3eb1ae](https://github.com/Automattic/newspack-popups/commit/f3eb1ae6007ed2ca3d76319c894f9d1830bfd740))


### Features

* update header hook to a more standard one ([#1302](https://github.com/Automattic/newspack-popups/issues/1302)) ([fe756a4](https://github.com/Automattic/newspack-popups/commit/fe756a4e00dd8fe74373df5d97bf001737a80b5c))


### Reverts

* automated file updates [skip ci] ([b59282c](https://github.com/Automattic/newspack-popups/commit/b59282cffa7284319ee85ee1d41cf8723086c4ea))

## [2.31.3](https://github.com/Automattic/newspack-popups/compare/v2.31.2...v2.31.3) (2024-04-26)


### Bug Fixes

* disable manual & custom placement prompts when prompts are disabled ([#1299](https://github.com/Automattic/newspack-popups/issues/1299)) ([aff5777](https://github.com/Automattic/newspack-popups/commit/aff5777873785055ce3e24a03ad7f549bf48feb3))

## [2.31.2](https://github.com/Automattic/newspack-popups/compare/v2.31.1...v2.31.2) (2024-04-23)


### Bug Fixes

* improved attribute handling ([b53415a](https://github.com/Automattic/newspack-popups/commit/b53415a95b8f7c65ecd421117d656c8e5effc3da))

## [2.31.1](https://github.com/Automattic/newspack-popups/compare/v2.31.0...v2.31.1) (2024-04-08)


### Bug Fixes

* ga4 events ([11ca3b8](https://github.com/Automattic/newspack-popups/commit/11ca3b8a0e8e5b586d76a6a27659d33b0246683c))

## [2.31.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.31.0...v2.31.1-alpha.1) (2024-03-28)


### Bug Fixes

* ga4 events ([11ca3b8](https://github.com/Automattic/newspack-popups/commit/11ca3b8a0e8e5b586d76a6a27659d33b0246683c))

# [2.31.0](https://github.com/Automattic/newspack-popups/compare/v2.30.1...v2.31.0) (2024-03-04)


### Bug Fixes

* **preview:** handle additional_classes ([c201f43](https://github.com/Automattic/newspack-popups/commit/c201f43809e0cec227f63fdfa9a3c69dbcabb827))


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#1271](https://github.com/Automattic/newspack-popups/issues/1271)) ([ed459fe](https://github.com/Automattic/newspack-popups/commit/ed459fe97a2183e22aab10d4a9047494c5e001ed))

# [2.31.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.31.0-alpha.1...v2.31.0-alpha.2) (2024-02-23)


### Bug Fixes

* **preview:** handle additional_classes ([c201f43](https://github.com/Automattic/newspack-popups/commit/c201f43809e0cec227f63fdfa9a3c69dbcabb827))

# [2.31.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.30.1...v2.31.0-alpha.1) (2024-02-08)


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#1271](https://github.com/Automattic/newspack-popups/issues/1271)) ([ed459fe](https://github.com/Automattic/newspack-popups/commit/ed459fe97a2183e22aab10d4a9047494c5e001ed))

## [2.30.1](https://github.com/Automattic/newspack-popups/compare/v2.30.0...v2.30.1) (2024-02-06)


### Reverts

* Revert "feat: use custom capability_type for the CPT (#1259)" ([9366118](https://github.com/Automattic/newspack-popups/commit/9366118c2c41c228f617d0f3bad7d10820d688db)), closes [#1259](https://github.com/Automattic/newspack-popups/issues/1259)

# [2.30.0](https://github.com/Automattic/newspack-popups/compare/v2.29.1...v2.30.0) (2024-02-06)


### Bug Fixes

* prompts and segments schema ([#1260](https://github.com/Automattic/newspack-popups/issues/1260)) ([25e02ec](https://github.com/Automattic/newspack-popups/commit/25e02ec6d90b9953aa8bbeb639844b81bc2401c8))


### Features

* use custom capability_type for the CPT ([#1259](https://github.com/Automattic/newspack-popups/issues/1259)) ([1d01212](https://github.com/Automattic/newspack-popups/commit/1d01212c54b8307ff0150242ffa0d8b787f9e9ea))

# [2.30.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.29.2-alpha.1...v2.30.0-alpha.1) (2024-02-01)


### Features

* use custom capability_type for the CPT ([#1259](https://github.com/Automattic/newspack-popups/issues/1259)) ([1d01212](https://github.com/Automattic/newspack-popups/commit/1d01212c54b8307ff0150242ffa0d8b787f9e9ea))

## [2.29.2-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.29.1...v2.29.2-alpha.1) (2024-01-26)


### Bug Fixes

* prompts and segments schema ([#1260](https://github.com/Automattic/newspack-popups/issues/1260)) ([25e02ec](https://github.com/Automattic/newspack-popups/commit/25e02ec6d90b9953aa8bbeb639844b81bc2401c8))

## [2.29.1](https://github.com/Automattic/newspack-popups/compare/v2.29.0...v2.29.1) (2024-01-25)


### Bug Fixes

* dont change global post before header ([#1258](https://github.com/Automattic/newspack-popups/issues/1258)) ([1c3d931](https://github.com/Automattic/newspack-popups/commit/1c3d931d237f4ee537c000d6d19225cb5f1087a1))
* prompts and segments schema ([#1260](https://github.com/Automattic/newspack-popups/issues/1260)) ([21fa990](https://github.com/Automattic/newspack-popups/commit/21fa99044de730287abad7924b990ea812b6cfe8))

## [2.29.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.29.0...v2.29.1-alpha.1) (2024-01-17)


### Bug Fixes

* dont change global post before header ([#1258](https://github.com/Automattic/newspack-popups/issues/1258)) ([1c3d931](https://github.com/Automattic/newspack-popups/commit/1c3d931d237f4ee537c000d6d19225cb5f1087a1))
* prompts and segments schema ([#1260](https://github.com/Automattic/newspack-popups/issues/1260)) ([21fa990](https://github.com/Automattic/newspack-popups/commit/21fa99044de730287abad7924b990ea812b6cfe8))

# [2.29.0](https://github.com/Automattic/newspack-popups/compare/v2.28.0...v2.29.0) (2024-01-17)


### Features

* fetch up to 1000 prompts ([#1262](https://github.com/Automattic/newspack-popups/issues/1262)) ([b6af288](https://github.com/Automattic/newspack-popups/commit/b6af2883ed1ef771eda771a9999d5018e1633064))

# [2.28.0](https://github.com/Automattic/newspack-popups/compare/v2.27.0...v2.28.0) (2024-01-08)


### Features

* add support for prompt revisions ([b2571a7](https://github.com/Automattic/newspack-popups/commit/b2571a7a4b4bf8bf755487683edc2a0ee5d3c8ef))

# [2.28.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.27.0...v2.28.0-alpha.1) (2023-12-15)


### Features

* add support for prompt revisions ([b2571a7](https://github.com/Automattic/newspack-popups/commit/b2571a7a4b4bf8bf755487683edc2a0ee5d3c8ef))

# [2.27.0](https://github.com/Automattic/newspack-popups/compare/v2.26.1...v2.27.0) (2023-12-11)


### Features

* don't enqueue criteria if popups are disabled for post ([3fbd4b6](https://github.com/Automattic/newspack-popups/commit/3fbd4b64191419b8becf15e6f55d6bfac21e47e4))
* **segmentation:** detect duplicated segments ([#1244](https://github.com/Automattic/newspack-popups/issues/1244)) ([3cfc89a](https://github.com/Automattic/newspack-popups/commit/3cfc89a2626c7e2c1eb6bf207301ddc7c4777d64))

# [2.27.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.26.1...v2.27.0-alpha.1) (2023-11-30)


### Features

* don't enqueue criteria if popups are disabled for post ([3fbd4b6](https://github.com/Automattic/newspack-popups/commit/3fbd4b64191419b8becf15e6f55d6bfac21e47e4))
* **segmentation:** detect duplicated segments ([#1244](https://github.com/Automattic/newspack-popups/issues/1244)) ([3cfc89a](https://github.com/Automattic/newspack-popups/commit/3cfc89a2626c7e2c1eb6bf207301ddc7c4777d64))

## [2.26.1](https://github.com/Automattic/newspack-popups/compare/v2.26.0...v2.26.1) (2023-11-17)


### Bug Fixes

* **settings:** get_setting method ([#1242](https://github.com/Automattic/newspack-popups/issues/1242)) ([e058644](https://github.com/Automattic/newspack-popups/commit/e0586441515a6380dafefc366171dff189c085b9))

# [2.26.0](https://github.com/Automattic/newspack-popups/compare/v2.25.0...v2.26.0) (2023-11-13)


### Features

* segmentation criteria for subscriptions and memberships ([#1230](https://github.com/Automattic/newspack-popups/issues/1230)) ([bef21e0](https://github.com/Automattic/newspack-popups/commit/bef21e0babaf2590d28b75177e171397d68d4d4e))

# [2.26.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.25.0...v2.26.0-alpha.1) (2023-10-31)


### Features

* segmentation criteria for subscriptions and memberships ([#1230](https://github.com/Automattic/newspack-popups/issues/1230)) ([bef21e0](https://github.com/Automattic/newspack-popups/commit/bef21e0babaf2590d28b75177e171397d68d4d4e))

# [2.25.0](https://github.com/Automattic/newspack-popups/compare/v2.24.0...v2.25.0) (2023-10-31)


### Bug Fixes

* deduplication strategy for Homepage Posts block ([#1228](https://github.com/Automattic/newspack-popups/issues/1228)) ([bbb952d](https://github.com/Automattic/newspack-popups/commit/bbb952d0f696bb72850607579574a2a0d19d82a3))


### Features

* implement ras overlays ([#1233](https://github.com/Automattic/newspack-popups/issues/1233)) ([6c86cd3](https://github.com/Automattic/newspack-popups/commit/6c86cd3c5689622160b7d118764b1c62a77b95e2))
* remove is_non_interactive method ([adabea2](https://github.com/Automattic/newspack-popups/commit/adabea225a04df880f0b6e120ee8dcad82c3445d))

# [2.25.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.25.0-alpha.1...v2.25.0-alpha.2) (2023-10-20)


### Features

* implement ras overlays ([#1233](https://github.com/Automattic/newspack-popups/issues/1233)) ([6c86cd3](https://github.com/Automattic/newspack-popups/commit/6c86cd3c5689622160b7d118764b1c62a77b95e2))

# [2.25.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.24.0...v2.25.0-alpha.1) (2023-10-16)


### Bug Fixes

* deduplication strategy for Homepage Posts block ([#1228](https://github.com/Automattic/newspack-popups/issues/1228)) ([bbb952d](https://github.com/Automattic/newspack-popups/commit/bbb952d0f696bb72850607579574a2a0d19d82a3))


### Features

* remove is_non_interactive method ([adabea2](https://github.com/Automattic/newspack-popups/commit/adabea225a04df880f0b6e120ee8dcad82c3445d))

# [2.24.0](https://github.com/Automattic/newspack-popups/compare/v2.23.1...v2.24.0) (2023-10-09)


### Bug Fixes

* **data-api:** handle errored default donation settings ([#1224](https://github.com/Automattic/newspack-popups/issues/1224)) ([76b70b6](https://github.com/Automattic/newspack-popups/commit/76b70b636b3f45f7fa7826073afc5eabaadaa461))


### Features

* newsletter subscription list criteria ([#1218](https://github.com/Automattic/newspack-popups/issues/1218)) ([22961a3](https://github.com/Automattic/newspack-popups/commit/22961a39db38d5d64a2839091497aee8fa6f40e5))
* remove GA related code ([#1220](https://github.com/Automattic/newspack-popups/issues/1220)) ([cb2ce82](https://github.com/Automattic/newspack-popups/commit/cb2ce82919cca4ca9f6812cf445d71982b8e3cab))

# [2.24.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.24.0-alpha.2...v2.24.0-alpha.3) (2023-09-29)


### Bug Fixes

* **data-api:** handle errored default donation settings ([#1224](https://github.com/Automattic/newspack-popups/issues/1224)) ([76b70b6](https://github.com/Automattic/newspack-popups/commit/76b70b636b3f45f7fa7826073afc5eabaadaa461))

# [2.24.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.24.0-alpha.1...v2.24.0-alpha.2) (2023-09-29)


### Features

* newsletter subscription list criteria ([#1218](https://github.com/Automattic/newspack-popups/issues/1218)) ([22961a3](https://github.com/Automattic/newspack-popups/commit/22961a39db38d5d64a2839091497aee8fa6f40e5))

# [2.24.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.23.1...v2.24.0-alpha.1) (2023-09-28)


### Features

* remove GA related code ([#1220](https://github.com/Automattic/newspack-popups/issues/1220)) ([cb2ce82](https://github.com/Automattic/newspack-popups/commit/cb2ce82919cca4ca9f6812cf445d71982b8e3cab))

## [2.23.1](https://github.com/Automattic/newspack-popups/compare/v2.23.0...v2.23.1) (2023-09-13)


### Bug Fixes

* avoid SQL error if legacy table doesn't exist ([#1211](https://github.com/Automattic/newspack-popups/issues/1211)) ([f091a24](https://github.com/Automattic/newspack-popups/commit/f091a24c4cb7e78c114fcb5d473a47c7ef1210aa))

## [2.23.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.23.0...v2.23.1-alpha.1) (2023-09-06)


### Bug Fixes

* avoid SQL error if legacy table doesn't exist ([#1211](https://github.com/Automattic/newspack-popups/issues/1211)) ([f091a24](https://github.com/Automattic/newspack-popups/commit/f091a24c4cb7e78c114fcb5d473a47c7ef1210aa))

# [2.23.0](https://github.com/Automattic/newspack-popups/compare/v2.22.0...v2.23.0) (2023-08-24)


### Bug Fixes

* account for frequency presets in printed config ([#1203](https://github.com/Automattic/newspack-popups/issues/1203)) ([a97bc8f](https://github.com/Automattic/newspack-popups/commit/a97bc8fdb187799bc4b9bc0fdb842de8396d5e3b))
* add .hidden styles for above header prompts ([#1207](https://github.com/Automattic/newspack-popups/issues/1207)) ([a19d64d](https://github.com/Automattic/newspack-popups/commit/a19d64dcd1a3b8cdd7cd9cc55839a60269b9e30b))
* ensure url param segment is set ([#1197](https://github.com/Automattic/newspack-popups/issues/1197)) ([dc6c130](https://github.com/Automattic/newspack-popups/commit/dc6c13027356dd6a0dbacddd654745cbe697bccf))
* eslint new rule declaration-block-no-redundant-longhand-properties ([d1bf215](https://github.com/Automattic/newspack-popups/commit/d1bf2158999e18c8329a008b2e78fb2c7cdbb80a))
* include missing file ([#1194](https://github.com/Automattic/newspack-popups/issues/1194)) ([d5fe344](https://github.com/Automattic/newspack-popups/commit/d5fe34425340b04c459d6f5a6f2ad5c7740ac12b))
* move the close button when Hide Padding is enabled ([#1165](https://github.com/Automattic/newspack-popups/issues/1165)) ([20d17d9](https://github.com/Automattic/newspack-popups/commit/20d17d94758d3e53508a02f4cbbde9e36f4bc628))
* remove string from overlay prompt ([#1204](https://github.com/Automattic/newspack-popups/issues/1204)) ([232f05a](https://github.com/Automattic/newspack-popups/commit/232f05a6badb06a155ddac4ad94d354f0bde3bdc))


### Features

* add segmentation README and more useful info to debug object ([#1195](https://github.com/Automattic/newspack-popups/issues/1195)) ([b501d9a](https://github.com/Automattic/newspack-popups/commit/b501d9adb8d5d095b77a3c7dca59926cc78cdcb6))
* campaigns segmentation API rearchitecture ([#1192](https://github.com/Automattic/newspack-popups/issues/1192)) ([d554151](https://github.com/Automattic/newspack-popups/commit/d554151bfd05d7278d86825385d337847956b037)), closes [#1155](https://github.com/Automattic/newspack-popups/issues/1155) [#1166](https://github.com/Automattic/newspack-popups/issues/1166) [#1167](https://github.com/Automattic/newspack-popups/issues/1167) [#1159](https://github.com/Automattic/newspack-popups/issues/1159) [#1175](https://github.com/Automattic/newspack-popups/issues/1175) [#1169](https://github.com/Automattic/newspack-popups/issues/1169)

# [2.23.0-alpha.4](https://github.com/Automattic/newspack-popups/compare/v2.23.0-alpha.3...v2.23.0-alpha.4) (2023-08-23)


### Bug Fixes

* add .hidden styles for above header prompts ([#1207](https://github.com/Automattic/newspack-popups/issues/1207)) ([a19d64d](https://github.com/Automattic/newspack-popups/commit/a19d64dcd1a3b8cdd7cd9cc55839a60269b9e30b))
* eslint new rule declaration-block-no-redundant-longhand-properties ([d1bf215](https://github.com/Automattic/newspack-popups/commit/d1bf2158999e18c8329a008b2e78fb2c7cdbb80a))

# [2.23.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.23.0-alpha.2...v2.23.0-alpha.3) (2023-08-21)


### Bug Fixes

* account for frequency presets in printed config ([#1203](https://github.com/Automattic/newspack-popups/issues/1203)) ([a97bc8f](https://github.com/Automattic/newspack-popups/commit/a97bc8fdb187799bc4b9bc0fdb842de8396d5e3b))
* ensure url param segment is set ([#1197](https://github.com/Automattic/newspack-popups/issues/1197)) ([dc6c130](https://github.com/Automattic/newspack-popups/commit/dc6c13027356dd6a0dbacddd654745cbe697bccf))
* remove string from overlay prompt ([#1204](https://github.com/Automattic/newspack-popups/issues/1204)) ([232f05a](https://github.com/Automattic/newspack-popups/commit/232f05a6badb06a155ddac4ad94d354f0bde3bdc))


### Features

* add segmentation README and more useful info to debug object ([#1195](https://github.com/Automattic/newspack-popups/issues/1195)) ([b501d9a](https://github.com/Automattic/newspack-popups/commit/b501d9adb8d5d095b77a3c7dca59926cc78cdcb6))

# [2.23.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.23.0-alpha.1...v2.23.0-alpha.2) (2023-08-18)


### Bug Fixes

* include missing file ([#1194](https://github.com/Automattic/newspack-popups/issues/1194)) ([d5fe344](https://github.com/Automattic/newspack-popups/commit/d5fe34425340b04c459d6f5a6f2ad5c7740ac12b))

# [2.23.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.22.0...v2.23.0-alpha.1) (2023-08-17)


### Bug Fixes

* move the close button when Hide Padding is enabled ([#1165](https://github.com/Automattic/newspack-popups/issues/1165)) ([20d17d9](https://github.com/Automattic/newspack-popups/commit/20d17d94758d3e53508a02f4cbbde9e36f4bc628))


### Features

* campaigns segmentation API rearchitecture ([#1192](https://github.com/Automattic/newspack-popups/issues/1192)) ([d554151](https://github.com/Automattic/newspack-popups/commit/d554151bfd05d7278d86825385d337847956b037)), closes [#1155](https://github.com/Automattic/newspack-popups/issues/1155) [#1166](https://github.com/Automattic/newspack-popups/issues/1166) [#1167](https://github.com/Automattic/newspack-popups/issues/1167) [#1159](https://github.com/Automattic/newspack-popups/issues/1159) [#1175](https://github.com/Automattic/newspack-popups/issues/1175) [#1169](https://github.com/Automattic/newspack-popups/issues/1169)

# [2.22.0](https://github.com/Automattic/newspack-popups/compare/v2.21.1...v2.22.0) (2023-07-17)


### Features

* refactor segments into a taxonomy ([#1106](https://github.com/Automattic/newspack-popups/issues/1106)) ([a3373e4](https://github.com/Automattic/newspack-popups/commit/a3373e4c2d033009e9284cfe2383622f31aba332))

# [2.22.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.22.0-alpha.1...v2.22.0-alpha.2) (2023-07-11)


### Bug Fixes

* when comparing filtered vs post content, compare only 1st paragraph ([#1160](https://github.com/Automattic/newspack-popups/issues/1160)) ([b47449e](https://github.com/Automattic/newspack-popups/commit/b47449e891f0a042365af22a848d82ad8c5cafcf))

## [2.21.1](https://github.com/Automattic/newspack-popups/compare/v2.21.0...v2.21.1) (2023-07-11)


### Bug Fixes

* when comparing filtered vs post content, compare only 1st paragraph ([#1160](https://github.com/Automattic/newspack-popups/issues/1160)) ([b47449e](https://github.com/Automattic/newspack-popups/commit/b47449e891f0a042365af22a848d82ad8c5cafcf))

# [2.21.0](https://github.com/Automattic/newspack-popups/compare/v2.20.0...v2.21.0) (2023-07-03)


### Bug Fixes

* ensure inline posts are inserted into main post content only ([#1137](https://github.com/Automattic/newspack-popups/issues/1137)) ([624bd56](https://github.com/Automattic/newspack-popups/commit/624bd56f4327ea92112cf465733837b1c343ac6c))
* remove dismissed event after form is submitted ([#1143](https://github.com/Automattic/newspack-popups/issues/1143)) ([b470efd](https://github.com/Automattic/newspack-popups/commit/b470efdc753f242b8470251345acea87a678414e))
* render prompts on metered content ([#1152](https://github.com/Automattic/newspack-popups/issues/1152)) ([05b5f76](https://github.com/Automattic/newspack-popups/commit/05b5f7621d4bf935904dd9a45a0832f1c3e34d90))


### Features

* front-end GA4 events for prompt interactions ([#1136](https://github.com/Automattic/newspack-popups/issues/1136)) ([ae274aa](https://github.com/Automattic/newspack-popups/commit/ae274aaed210e93a741d640f3d2b1c95ccacff8a)), closes [#1133](https://github.com/Automattic/newspack-popups/issues/1133)
* rearrange info on popup metadta events ([#1144](https://github.com/Automattic/newspack-popups/issues/1144)) ([dcac4b7](https://github.com/Automattic/newspack-popups/commit/dcac4b7823f9913572a67c9010d63a72a350d936))

# [2.21.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.20.0...v2.21.0-alpha.1) (2023-06-22)


### Bug Fixes

* ensure inline posts are inserted into main post content only ([#1137](https://github.com/Automattic/newspack-popups/issues/1137)) ([624bd56](https://github.com/Automattic/newspack-popups/commit/624bd56f4327ea92112cf465733837b1c343ac6c))
* remove dismissed event after form is submitted ([#1143](https://github.com/Automattic/newspack-popups/issues/1143)) ([b470efd](https://github.com/Automattic/newspack-popups/commit/b470efdc753f242b8470251345acea87a678414e))
* render prompts on metered content ([#1152](https://github.com/Automattic/newspack-popups/issues/1152)) ([05b5f76](https://github.com/Automattic/newspack-popups/commit/05b5f7621d4bf935904dd9a45a0832f1c3e34d90))


### Features

* front-end GA4 events for prompt interactions ([#1136](https://github.com/Automattic/newspack-popups/issues/1136)) ([ae274aa](https://github.com/Automattic/newspack-popups/commit/ae274aaed210e93a741d640f3d2b1c95ccacff8a)), closes [#1133](https://github.com/Automattic/newspack-popups/issues/1133)
* rearrange info on popup metadta events ([#1144](https://github.com/Automattic/newspack-popups/issues/1144)) ([dcac4b7](https://github.com/Automattic/newspack-popups/commit/dcac4b7823f9913572a67c9010d63a72a350d936))

# [2.20.0](https://github.com/Automattic/newspack-popups/compare/v2.19.0...v2.20.0) (2023-06-19)


### Bug Fixes

* attempt to create config file on every host ([#1121](https://github.com/Automattic/newspack-popups/issues/1121)) ([8ba33b7](https://github.com/Automattic/newspack-popups/commit/8ba33b733889068b709b5179beb3cc180901b9ad))
* escape value when creating config file ([#1123](https://github.com/Automattic/newspack-popups/issues/1123)) ([53232e5](https://github.com/Automattic/newspack-popups/commit/53232e5c431c0628bb0cca421277f2cb7faf8189))


### Features

* **ras:** add segmentation support for NRH donations ([#1124](https://github.com/Automattic/newspack-popups/issues/1124)) ([d2b0ca8](https://github.com/Automattic/newspack-popups/commit/d2b0ca8fa9d35c610f616af334fa555c425f421d))

# [2.20.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.19.0...v2.20.0-alpha.1) (2023-06-15)


### Bug Fixes

* attempt to create config file on every host ([#1121](https://github.com/Automattic/newspack-popups/issues/1121)) ([8ba33b7](https://github.com/Automattic/newspack-popups/commit/8ba33b733889068b709b5179beb3cc180901b9ad))
* escape value when creating config file ([#1123](https://github.com/Automattic/newspack-popups/issues/1123)) ([53232e5](https://github.com/Automattic/newspack-popups/commit/53232e5c431c0628bb0cca421277f2cb7faf8189))


### Features

* **ras:** add segmentation support for NRH donations ([#1124](https://github.com/Automattic/newspack-popups/issues/1124)) ([d2b0ca8](https://github.com/Automattic/newspack-popups/commit/d2b0ca8fa9d35c610f616af334fa555c425f421d))

# [2.19.0](https://github.com/Automattic/newspack-popups/compare/v2.18.1...v2.19.0) (2023-06-15)


### Bug Fixes

* remove dismissed event after form is submitted ([#1143](https://github.com/Automattic/newspack-popups/issues/1143)) ([1e85c26](https://github.com/Automattic/newspack-popups/commit/1e85c26e3c70716dfc4e319c6721cf372e00945f))


### Features

* front-end GA4 events for prompt interactions ([#1136](https://github.com/Automattic/newspack-popups/issues/1136)) ([15a6286](https://github.com/Automattic/newspack-popups/commit/15a6286fa72ec2c6cc62213840a903db3339828e)), closes [#1133](https://github.com/Automattic/newspack-popups/issues/1133)
* rearrange info on popup metadta events ([#1144](https://github.com/Automattic/newspack-popups/issues/1144)) ([f3267e1](https://github.com/Automattic/newspack-popups/commit/f3267e10c5890f9e974775a6e89b0910dbef2ec8))

## [2.18.1](https://github.com/Automattic/newspack-popups/compare/v2.18.0...v2.18.1) (2023-06-13)


### Bug Fixes

* don’t include inactive segments in API request payload ([#1127](https://github.com/Automattic/newspack-popups/issues/1127)) ([f913e13](https://github.com/Automattic/newspack-popups/commit/f913e1336469b5e95a4c9048d98307ec713c3d92))

# [2.18.0](https://github.com/Automattic/newspack-popups/compare/v2.17.0...v2.18.0) (2023-06-05)


### Features

* deprecate "display title" option ([#1101](https://github.com/Automattic/newspack-popups/issues/1101)) ([e3de495](https://github.com/Automattic/newspack-popups/commit/e3de495e1885bb1eebb7dd12d7d523ea2846f6fd))
* **ras:** add segmentation support for NRH donations ([#1124](https://github.com/Automattic/newspack-popups/issues/1124)) ([da1a088](https://github.com/Automattic/newspack-popups/commit/da1a088d43eb021b63e142772e702d59da678775))

# [2.18.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.17.0...v2.18.0-alpha.1) (2023-05-30)


### Features

* deprecate "display title" option ([#1101](https://github.com/Automattic/newspack-popups/issues/1101)) ([e3de495](https://github.com/Automattic/newspack-popups/commit/e3de495e1885bb1eebb7dd12d7d523ea2846f6fd))

# [2.17.0](https://github.com/Automattic/newspack-popups/compare/v2.16.0...v2.17.0) (2023-05-30)


### Features

* record timestamp when RAS setup wizard is completed ([#1118](https://github.com/Automattic/newspack-popups/issues/1118)) ([c6b225c](https://github.com/Automattic/newspack-popups/commit/c6b225cdf043a79fe3044052cb0c99a9a2a6425c))

# [2.16.0](https://github.com/Automattic/newspack-popups/compare/v2.15.0...v2.16.0) (2023-05-08)


### Bug Fixes

* adjust pre-commit hook ([#1086](https://github.com/Automattic/newspack-popups/issues/1086)) ([19de3f0](https://github.com/Automattic/newspack-popups/commit/19de3f0a70f03552a70dde0e944d7e5749475df0))
* pre commit and add pre push ([#1084](https://github.com/Automattic/newspack-popups/issues/1084)) ([1f0a59d](https://github.com/Automattic/newspack-popups/commit/1f0a59d508988bac13546eddf3e2384e598e6cf9))
* **ras-defaults:** allow override values via $_GET for previewing presets ([#1107](https://github.com/Automattic/newspack-popups/issues/1107)) ([58736a5](https://github.com/Automattic/newspack-popups/commit/58736a5fc955630eec079335ce0658ab1db1d78f))
* **ras-defaults:** turn on feature to always subscribe to newsletters by default ([#1111](https://github.com/Automattic/newspack-popups/issues/1111)) ([283e0d8](https://github.com/Automattic/newspack-popups/commit/283e0d845c54a8d4f122fe3f4da2fc692f07ccd5))


### Features

* add custom prompt taxonomies ([f75f6fc](https://github.com/Automattic/newspack-popups/commit/f75f6fcff8941b674cb8bb2573dd0ba2840d200c))
* add custom prompt taxonomies [#1100](https://github.com/Automattic/newspack-popups/issues/1100) ([1af879d](https://github.com/Automattic/newspack-popups/commit/1af879d7865466900754a5028bfdae8b87c97ec7))
* add filter to maybe display prompt check ([7b5bb9b](https://github.com/Automattic/newspack-popups/commit/7b5bb9bd0de864133d96714ef466608d384b27dc))
* add filter to maybe display prompt check [#1095](https://github.com/Automattic/newspack-popups/issues/1095) ([1bbe843](https://github.com/Automattic/newspack-popups/commit/1bbe84319456ec23de1214c980572e039f894716))
* filter the whole result ([40863dd](https://github.com/Automattic/newspack-popups/commit/40863ddb4c532d79cc2701f3ad8b223809d0102b))
* hide overlays on sign in modal ([#1094](https://github.com/Automattic/newspack-popups/issues/1094)) ([226bb5e](https://github.com/Automattic/newspack-popups/commit/226bb5e0472b125288d83e35f386af471ca188bc))
* increase max length of default prompt input fields ([#1109](https://github.com/Automattic/newspack-popups/issues/1109)) ([6b759a1](https://github.com/Automattic/newspack-popups/commit/6b759a13716359e79d822f726e0366c5e0063314))
* RAS setup UI and default Campaigns wizard ([#1103](https://github.com/Automattic/newspack-popups/issues/1103)) ([f595046](https://github.com/Automattic/newspack-popups/commit/f595046e0acf5e4678852fb9d3217a811a1abc42)), closes [#1078](https://github.com/Automattic/newspack-popups/issues/1078) [#1090](https://github.com/Automattic/newspack-popups/issues/1090) [#1096](https://github.com/Automattic/newspack-popups/issues/1096) [#1097](https://github.com/Automattic/newspack-popups/issues/1097) [#1102](https://github.com/Automattic/newspack-popups/issues/1102)

# [2.16.0-alpha.4](https://github.com/Automattic/newspack-popups/compare/v2.16.0-alpha.3...v2.16.0-alpha.4) (2023-05-08)


### Bug Fixes

* **ras-defaults:** turn on feature to always subscribe to newsletters by default ([#1111](https://github.com/Automattic/newspack-popups/issues/1111)) ([283e0d8](https://github.com/Automattic/newspack-popups/commit/283e0d845c54a8d4f122fe3f4da2fc692f07ccd5))

# [2.16.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.16.0-alpha.2...v2.16.0-alpha.3) (2023-05-03)


### Features

* increase max length of default prompt input fields ([#1109](https://github.com/Automattic/newspack-popups/issues/1109)) ([6b759a1](https://github.com/Automattic/newspack-popups/commit/6b759a13716359e79d822f726e0366c5e0063314))

# [2.16.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.16.0-alpha.1...v2.16.0-alpha.2) (2023-05-03)


### Bug Fixes

* **ras-defaults:** allow override values via $_GET for previewing presets ([#1107](https://github.com/Automattic/newspack-popups/issues/1107)) ([58736a5](https://github.com/Automattic/newspack-popups/commit/58736a5fc955630eec079335ce0658ab1db1d78f))

# [2.16.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.15.0...v2.16.0-alpha.1) (2023-04-28)


### Bug Fixes

* adjust pre-commit hook ([#1086](https://github.com/Automattic/newspack-popups/issues/1086)) ([19de3f0](https://github.com/Automattic/newspack-popups/commit/19de3f0a70f03552a70dde0e944d7e5749475df0))
* pre commit and add pre push ([#1084](https://github.com/Automattic/newspack-popups/issues/1084)) ([1f0a59d](https://github.com/Automattic/newspack-popups/commit/1f0a59d508988bac13546eddf3e2384e598e6cf9))


### Features

* add custom prompt taxonomies ([f75f6fc](https://github.com/Automattic/newspack-popups/commit/f75f6fcff8941b674cb8bb2573dd0ba2840d200c))
* add custom prompt taxonomies [#1100](https://github.com/Automattic/newspack-popups/issues/1100) ([1af879d](https://github.com/Automattic/newspack-popups/commit/1af879d7865466900754a5028bfdae8b87c97ec7))
* add filter to maybe display prompt check ([7b5bb9b](https://github.com/Automattic/newspack-popups/commit/7b5bb9bd0de864133d96714ef466608d384b27dc))
* add filter to maybe display prompt check [#1095](https://github.com/Automattic/newspack-popups/issues/1095) ([1bbe843](https://github.com/Automattic/newspack-popups/commit/1bbe84319456ec23de1214c980572e039f894716))
* filter the whole result ([40863dd](https://github.com/Automattic/newspack-popups/commit/40863ddb4c532d79cc2701f3ad8b223809d0102b))
* hide overlays on sign in modal ([#1094](https://github.com/Automattic/newspack-popups/issues/1094)) ([226bb5e](https://github.com/Automattic/newspack-popups/commit/226bb5e0472b125288d83e35f386af471ca188bc))
* RAS setup UI and default Campaigns wizard ([#1103](https://github.com/Automattic/newspack-popups/issues/1103)) ([f595046](https://github.com/Automattic/newspack-popups/commit/f595046e0acf5e4678852fb9d3217a811a1abc42)), closes [#1078](https://github.com/Automattic/newspack-popups/issues/1078) [#1090](https://github.com/Automattic/newspack-popups/issues/1090) [#1096](https://github.com/Automattic/newspack-popups/issues/1096) [#1097](https://github.com/Automattic/newspack-popups/issues/1097) [#1102](https://github.com/Automattic/newspack-popups/issues/1102)

# [2.15.0](https://github.com/Automattic/newspack-popups/compare/v2.14.0...v2.15.0) (2023-04-10)


### Bug Fixes

* ensure the plugin works when performance-lab plugin is active ([0f8bc55](https://github.com/Automattic/newspack-popups/commit/0f8bc554a4b5cd5956ff4c0ed06dabc3540f0137))
* fatal error if wp_cache_* functions do exist ([003b6c4](https://github.com/Automattic/newspack-popups/commit/003b6c4d019faa2af9ab88a61aa700fe08da5706))
* fatal error if wp_cache_* functions do exist [#1073](https://github.com/Automattic/newspack-popups/issues/1073) ([ce1f563](https://github.com/Automattic/newspack-popups/commit/ce1f56362b0b2a9e795a4a28d81364623e526aa8))
* hide lightbox when modal checkout ([#1077](https://github.com/Automattic/newspack-popups/issues/1077)) ([1da8f6b](https://github.com/Automattic/newspack-popups/commit/1da8f6b207baaed1ba753ec3beb4abd5b4ded7a0))
* **memberships:** restriction check for inline prompt insertion ([#1079](https://github.com/Automattic/newspack-popups/issues/1079)) ([89f4142](https://github.com/Automattic/newspack-popups/commit/89f4142c1928fa5797de4895ad87cb45ccdb379f))


### Features

* add feature to allow segments to be disabled ([#1076](https://github.com/Automattic/newspack-popups/issues/1076)) ([238aaa7](https://github.com/Automattic/newspack-popups/commit/238aaa77dd1182017c7113b50efb8c97a91ebefe))
* Update default newsletter prompt ([#1074](https://github.com/Automattic/newspack-popups/issues/1074)) ([428bb98](https://github.com/Automattic/newspack-popups/commit/428bb98636c4d97eb2312a3583108204543f6739))

# [2.15.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.14.0...v2.15.0-alpha.1) (2023-03-31)


### Bug Fixes

* ensure the plugin works when performance-lab plugin is active ([0f8bc55](https://github.com/Automattic/newspack-popups/commit/0f8bc554a4b5cd5956ff4c0ed06dabc3540f0137))
* fatal error if wp_cache_* functions do exist ([003b6c4](https://github.com/Automattic/newspack-popups/commit/003b6c4d019faa2af9ab88a61aa700fe08da5706))
* fatal error if wp_cache_* functions do exist [#1073](https://github.com/Automattic/newspack-popups/issues/1073) ([ce1f563](https://github.com/Automattic/newspack-popups/commit/ce1f56362b0b2a9e795a4a28d81364623e526aa8))
* hide lightbox when modal checkout ([#1077](https://github.com/Automattic/newspack-popups/issues/1077)) ([1da8f6b](https://github.com/Automattic/newspack-popups/commit/1da8f6b207baaed1ba753ec3beb4abd5b4ded7a0))
* **memberships:** restriction check for inline prompt insertion ([#1079](https://github.com/Automattic/newspack-popups/issues/1079)) ([89f4142](https://github.com/Automattic/newspack-popups/commit/89f4142c1928fa5797de4895ad87cb45ccdb379f))


### Features

* add feature to allow segments to be disabled ([#1076](https://github.com/Automattic/newspack-popups/issues/1076)) ([238aaa7](https://github.com/Automattic/newspack-popups/commit/238aaa77dd1182017c7113b50efb8c97a91ebefe))
* Update default newsletter prompt ([#1074](https://github.com/Automattic/newspack-popups/issues/1074)) ([428bb98](https://github.com/Automattic/newspack-popups/commit/428bb98636c4d97eb2312a3583108204543f6739))

# [2.14.0](https://github.com/Automattic/newspack-popups/compare/v2.13.0...v2.14.0) (2023-03-14)


### Bug Fixes

* avoid notice on php 8 ([6fde157](https://github.com/Automattic/newspack-popups/commit/6fde157a8f09d3828c486dae3011a84434a23223))
* prevent PHP warning when previewing prompts ([5a85939](https://github.com/Automattic/newspack-popups/commit/5a859396e6399575b90b9b6b7550ddb2c6d89e9a))


### Features

* add popup info to donation block ([85ec525](https://github.com/Automattic/newspack-popups/commit/85ec5257e2fbdb0700f9959ea4b34dba0ab2f41a))
* add popup info to donation block ([7a01172](https://github.com/Automattic/newspack-popups/commit/7a01172655c285e6e9d51f34c494e58a1364363d))
* add popups ids to forms in prompts ([b41aff9](https://github.com/Automattic/newspack-popups/commit/b41aff913999a91cc057260d8eaee39f377319ae))
* allow overlays to be dismissed on background tap ([#1062](https://github.com/Automattic/newspack-popups/issues/1062)) ([97a040b](https://github.com/Automattic/newspack-popups/commit/97a040b3afe8f8d448eb257a1132064cc744ff71))

# [2.14.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.13.0...v2.14.0-alpha.1) (2023-03-03)


### Bug Fixes

* avoid notice on php 8 ([6fde157](https://github.com/Automattic/newspack-popups/commit/6fde157a8f09d3828c486dae3011a84434a23223))
* prevent PHP warning when previewing prompts ([5a85939](https://github.com/Automattic/newspack-popups/commit/5a859396e6399575b90b9b6b7550ddb2c6d89e9a))


### Features

* add popup info to donation block ([85ec525](https://github.com/Automattic/newspack-popups/commit/85ec5257e2fbdb0700f9959ea4b34dba0ab2f41a))
* add popup info to donation block ([7a01172](https://github.com/Automattic/newspack-popups/commit/7a01172655c285e6e9d51f34c494e58a1364363d))
* add popups ids to forms in prompts ([b41aff9](https://github.com/Automattic/newspack-popups/commit/b41aff913999a91cc057260d8eaee39f377319ae))
* allow overlays to be dismissed on background tap ([#1062](https://github.com/Automattic/newspack-popups/issues/1062)) ([97a040b](https://github.com/Automattic/newspack-popups/commit/97a040b3afe8f8d448eb257a1132064cc744ff71))

# [2.13.0](https://github.com/Automattic/newspack-popups/compare/v2.12.0...v2.13.0) (2023-02-21)


### Features

* add feature to disable reader data caching via flag ([#1049](https://github.com/Automattic/newspack-popups/issues/1049)) ([cabe310](https://github.com/Automattic/newspack-popups/commit/cabe3105b8d2bd395435135d1a04e118b4133ec8))

# [2.12.0](https://github.com/Automattic/newspack-popups/compare/v2.11.0...v2.12.0) (2023-02-16)


### Bug Fixes

* force invalid "full" size value to correct "full-width" value ([#1043](https://github.com/Automattic/newspack-popups/issues/1043)) ([131aba8](https://github.com/Automattic/newspack-popups/commit/131aba82ab98ab6633af5be847c75c15aa9d1693))


### Features

* add prune data CLI command ([486ea9f](https://github.com/Automattic/newspack-popups/commit/486ea9fccf213fe9bc5741561484ba604bc42d9c))

# [2.12.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.11.0...v2.12.0-alpha.1) (2023-01-26)


### Bug Fixes

* force invalid "full" size value to correct "full-width" value ([#1043](https://github.com/Automattic/newspack-popups/issues/1043)) ([131aba8](https://github.com/Automattic/newspack-popups/commit/131aba82ab98ab6633af5be847c75c15aa9d1693))


### Features

* add prune data CLI command ([486ea9f](https://github.com/Automattic/newspack-popups/commit/486ea9fccf213fe9bc5741561484ba604bc42d9c))

# [2.11.0](https://github.com/Automattic/newspack-popups/compare/v2.10.0...v2.11.0) (2023-01-25)


### Features

* add prune data CLI command ([#1042](https://github.com/Automattic/newspack-popups/issues/1042)) ([6535f4b](https://github.com/Automattic/newspack-popups/commit/6535f4bc994ee89fb477e88ea3e9780d6730ddbf))

# [2.11.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.10.0...v2.11.0-alpha.1) (2023-01-13)


### Features

* add prune data CLI command ([#1042](https://github.com/Automattic/newspack-popups/issues/1042)) ([6535f4b](https://github.com/Automattic/newspack-popups/commit/6535f4bc994ee89fb477e88ea3e9780d6730ddbf))

# [2.10.0](https://github.com/Automattic/newspack-popups/compare/v2.9.2...v2.10.0) (2023-01-09)


### Bug Fixes

* allow every pageview frequency for overlays in editor ([#1036](https://github.com/Automattic/newspack-popups/issues/1036)) ([d792ea6](https://github.com/Automattic/newspack-popups/commit/d792ea6d6d41075f7227d08fb23a161a693d4211))
* hide the 'Prompts Visible' button in the admin panel ([a443eca](https://github.com/Automattic/newspack-popups/commit/a443ecaf84d3464c3c9f44e519aef83b0b0dfea5))


### Features

* add export cli command ([#1021](https://github.com/Automattic/newspack-popups/issues/1021)) ([835f117](https://github.com/Automattic/newspack-popups/commit/835f1174d597a2dd55b766ac3db6103b5491748f))
* add new field for custom CSS classes ([#1025](https://github.com/Automattic/newspack-popups/issues/1025)) ([364351b](https://github.com/Automattic/newspack-popups/commit/364351b0c6bd06dd3a3afc49841bfee78cd2fc61))
* add the Importer basic CLI command ([#1026](https://github.com/Automattic/newspack-popups/issues/1026)) ([8aa5ac2](https://github.com/Automattic/newspack-popups/commit/8aa5ac219ed3c6a6fab180af47a8b6df2f1813b9))
* allow admins to toggle prompts ([#1012](https://github.com/Automattic/newspack-popups/issues/1012)) ([7ed1d88](https://github.com/Automattic/newspack-popups/commit/7ed1d88d781835700379cb0bd8c9f4c633955697))
* CLI command to add ras defaults segments and prompts ([#1032](https://github.com/Automattic/newspack-popups/issues/1032)) ([7cf8898](https://github.com/Automattic/newspack-popups/commit/7cf88985e2792482bc6e013d5d79de655341ab86))
* Improved import and export cli commands ([#1029](https://github.com/Automattic/newspack-popups/issues/1029)) ([e8d3a22](https://github.com/Automattic/newspack-popups/commit/e8d3a22245c520776c29cc2e49d317cc78b9b671))

# [2.10.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.10.0-alpha.1...v2.10.0-alpha.2) (2022-12-22)


### Bug Fixes

* allow every pageview frequency for overlays in editor ([#1036](https://github.com/Automattic/newspack-popups/issues/1036)) ([d792ea6](https://github.com/Automattic/newspack-popups/commit/d792ea6d6d41075f7227d08fb23a161a693d4211))


### Features

* CLI command to add ras defaults segments and prompts ([#1032](https://github.com/Automattic/newspack-popups/issues/1032)) ([7cf8898](https://github.com/Automattic/newspack-popups/commit/7cf88985e2792482bc6e013d5d79de655341ab86))

# [2.10.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.9.2...v2.10.0-alpha.1) (2022-12-20)


### Bug Fixes

* hide the 'Prompts Visible' button in the admin panel ([a443eca](https://github.com/Automattic/newspack-popups/commit/a443ecaf84d3464c3c9f44e519aef83b0b0dfea5))


### Features

* add export cli command ([#1021](https://github.com/Automattic/newspack-popups/issues/1021)) ([835f117](https://github.com/Automattic/newspack-popups/commit/835f1174d597a2dd55b766ac3db6103b5491748f))
* add new field for custom CSS classes ([#1025](https://github.com/Automattic/newspack-popups/issues/1025)) ([364351b](https://github.com/Automattic/newspack-popups/commit/364351b0c6bd06dd3a3afc49841bfee78cd2fc61))
* add the Importer basic CLI command ([#1026](https://github.com/Automattic/newspack-popups/issues/1026)) ([8aa5ac2](https://github.com/Automattic/newspack-popups/commit/8aa5ac219ed3c6a6fab180af47a8b6df2f1813b9))
* allow admins to toggle prompts ([#1012](https://github.com/Automattic/newspack-popups/issues/1012)) ([7ed1d88](https://github.com/Automattic/newspack-popups/commit/7ed1d88d781835700379cb0bd8c9f4c633955697))
* Improved import and export cli commands ([#1029](https://github.com/Automattic/newspack-popups/issues/1029)) ([e8d3a22](https://github.com/Automattic/newspack-popups/commit/e8d3a22245c520776c29cc2e49d317cc78b9b671))

## [2.9.2](https://github.com/Automattic/newspack-popups/compare/v2.9.1...v2.9.2) (2022-12-20)


### Bug Fixes

* prevent API bot exit without referer ([#1035](https://github.com/Automattic/newspack-popups/issues/1035)) ([bc8ec5b](https://github.com/Automattic/newspack-popups/commit/bc8ec5bea8d0ecacab7f5deec9e747dfc5141e37))

## [2.9.2-hotfix.1](https://github.com/Automattic/newspack-popups/compare/v2.9.1...v2.9.2-hotfix.1) (2022-12-20)


### Bug Fixes

* prevent API bot exit without referer ([b9f5c56](https://github.com/Automattic/newspack-popups/commit/b9f5c5646580f7f354dac1279a33a7398bc6491c))

## [2.9.1](https://github.com/Automattic/newspack-popups/compare/v2.9.0...v2.9.1) (2022-12-20)


### Bug Fixes

* temporarily remove cd filter ([#1034](https://github.com/Automattic/newspack-popups/issues/1034)) ([c2c7f8e](https://github.com/Automattic/newspack-popups/commit/c2c7f8e47b1f738eea6c625db23e0314bd29066a))

# [2.9.0](https://github.com/Automattic/newspack-popups/compare/v2.8.3...v2.9.0) (2022-12-12)


### Bug Fixes

* **ga:** add custom dimensions via Newspack instead of a remote config ([#1014](https://github.com/Automattic/newspack-popups/issues/1014)) ([fc49f4d](https://github.com/Automattic/newspack-popups/commit/fc49f4de92ae17ea42e73421e61a6c69f843a8b2)), closes [#325](https://github.com/Automattic/newspack-popups/issues/325)
* update CI orb newspack-scripts ([2bec2ad](https://github.com/Automattic/newspack-popups/commit/2bec2ad608cd543e0e4223b00fe73925bcb2857a))


### Features

* create Json schemas ([#1019](https://github.com/Automattic/newspack-popups/issues/1019)) ([b244f9e](https://github.com/Automattic/newspack-popups/commit/b244f9e526cb3a18df6e6d5b69dbb7ca6224b7ee))
* support custom class names on inline prompt blocks ([#1015](https://github.com/Automattic/newspack-popups/issues/1015)) ([853de19](https://github.com/Automattic/newspack-popups/commit/853de19c974a70edba88d31487bb791e810f8876))

# [2.9.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.8.3...v2.9.0-alpha.1) (2022-12-01)


### Bug Fixes

* **ga:** add custom dimensions via Newspack instead of a remote config ([#1014](https://github.com/Automattic/newspack-popups/issues/1014)) ([fc49f4d](https://github.com/Automattic/newspack-popups/commit/fc49f4de92ae17ea42e73421e61a6c69f843a8b2)), closes [#325](https://github.com/Automattic/newspack-popups/issues/325)
* update CI orb newspack-scripts ([2bec2ad](https://github.com/Automattic/newspack-popups/commit/2bec2ad608cd543e0e4223b00fe73925bcb2857a))


### Features

* create Json schemas ([#1019](https://github.com/Automattic/newspack-popups/issues/1019)) ([b244f9e](https://github.com/Automattic/newspack-popups/commit/b244f9e526cb3a18df6e6d5b69dbb7ca6224b7ee))
* support custom class names on inline prompt blocks ([#1015](https://github.com/Automattic/newspack-popups/issues/1015)) ([853de19](https://github.com/Automattic/newspack-popups/commit/853de19c974a70edba88d31487bb791e810f8876))

## [2.8.3](https://github.com/Automattic/newspack-popups/compare/v2.8.2...v2.8.3) (2022-11-16)


### Bug Fixes

* also limit query to fetch reconciled session client IDs ([09422f2](https://github.com/Automattic/newspack-popups/commit/09422f2cf08a56cbffaa39fed8a2c83d189087ed))

## [2.8.2](https://github.com/Automattic/newspack-popups/compare/v2.8.1...v2.8.2) (2022-11-16)


### Bug Fixes

* limit reconciled sessions to max of 10 per reader ([a1e9cd9](https://github.com/Automattic/newspack-popups/commit/a1e9cd9b35e243c9e281b0042367b8eb2543a243))

## [2.8.1](https://github.com/Automattic/newspack-popups/compare/v2.8.0...v2.8.1) (2022-11-16)


### Bug Fixes

* handle ESP exception on login fetch ([#1008](https://github.com/Automattic/newspack-popups/issues/1008)) ([6565920](https://github.com/Automattic/newspack-popups/commit/6565920b2a89afb51051d8359dc4ce074e661319))

## [2.8.1-hotfix.1](https://github.com/Automattic/newspack-popups/compare/v2.8.0...v2.8.1-hotfix.1) (2022-11-16)


### Bug Fixes

* handle ESP exception on login fetch ([e7018da](https://github.com/Automattic/newspack-popups/commit/e7018da39bc58b043c62715f25847f8be7a2b425))

# [2.8.0](https://github.com/Automattic/newspack-popups/compare/v2.7.2...v2.8.0) (2022-11-14)


### Bug Fixes

* clarify padding vs. border styles in overlay vs. inline ([#998](https://github.com/Automattic/newspack-popups/issues/998)) ([7c86755](https://github.com/Automattic/newspack-popups/commit/7c8675577c7c6a918e21b0a85d1414b6196a2018))


### Features

* polyfill amp-access script ([4c41745](https://github.com/Automattic/newspack-popups/commit/4c417457df7023fa60c030b8de86f25610f5e7b1)), closes [#995](https://github.com/Automattic/newspack-popups/issues/995) [#193](https://github.com/Automattic/newspack-popups/issues/193) [#880](https://github.com/Automattic/newspack-popups/issues/880) [#749](https://github.com/Automattic/newspack-popups/issues/749) [#170](https://github.com/Automattic/newspack-popups/issues/170) [#569](https://github.com/Automattic/newspack-popups/issues/569)
* reconcile different reader sessions ([#978](https://github.com/Automattic/newspack-popups/issues/978)) ([38a486d](https://github.com/Automattic/newspack-popups/commit/38a486d658a8bd2d45e59e4a0a2c1a623bd7364e))

# [2.8.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.8.0-alpha.1...v2.8.0-alpha.2) (2022-11-11)


### Features

* polyfill amp-access script ([4c41745](https://github.com/Automattic/newspack-popups/commit/4c417457df7023fa60c030b8de86f25610f5e7b1)), closes [#995](https://github.com/Automattic/newspack-popups/issues/995) [#193](https://github.com/Automattic/newspack-popups/issues/193) [#880](https://github.com/Automattic/newspack-popups/issues/880) [#749](https://github.com/Automattic/newspack-popups/issues/749) [#170](https://github.com/Automattic/newspack-popups/issues/170) [#569](https://github.com/Automattic/newspack-popups/issues/569)

# [2.8.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.7.2...v2.8.0-alpha.1) (2022-11-03)


### Bug Fixes

* clarify padding vs. border styles in overlay vs. inline ([#998](https://github.com/Automattic/newspack-popups/issues/998)) ([7c86755](https://github.com/Automattic/newspack-popups/commit/7c8675577c7c6a918e21b0a85d1414b6196a2018))


### Features

* reconcile different reader sessions ([#978](https://github.com/Automattic/newspack-popups/issues/978)) ([38a486d](https://github.com/Automattic/newspack-popups/commit/38a486d658a8bd2d45e59e4a0a2c1a623bd7364e))

## [2.7.2](https://github.com/Automattic/newspack-popups/compare/v2.7.1...v2.7.2) (2022-10-25)


### Bug Fixes

* invoke wp polyfills for compatibility ([#997](https://github.com/Automattic/newspack-popups/issues/997)) ([3c8f790](https://github.com/Automattic/newspack-popups/commit/3c8f7909e63a93d8ba0b3e8f9335a8d743d71c38))

## [2.7.1](https://github.com/Automattic/newspack-popups/compare/v2.7.0...v2.7.1) (2022-10-20)


### Bug Fixes

* enqueue AMP scripts for non-AMP ([e13ecae](https://github.com/Automattic/newspack-popups/commit/e13ecaea25d9eeba0767bfc84f1945f54e229912))
* remove async attribute from script ([43a677f](https://github.com/Automattic/newspack-popups/commit/43a677f4a6d25eaa69d7a8882df4fb5656be9214))


### Reverts

* Revert "feat: polyfill amp-access script" ([7cb2467](https://github.com/Automattic/newspack-popups/commit/7cb246735704722d4a65e734c37398e08a788717))
* polyfill fallback behavior from PR [#961](https://github.com/Automattic/newspack-popups/issues/961) ([a6d0409](https://github.com/Automattic/newspack-popups/commit/a6d0409573a4ea23a5f7ad40246d12d3ab2d23b5))

# [2.7.0](https://github.com/Automattic/newspack-popups/compare/v2.6.2...v2.7.0) (2022-10-19)


### Bug Fixes

* **analytics:** only add form info to form submission events ([#960](https://github.com/Automattic/newspack-popups/issues/960)) ([72ff629](https://github.com/Automattic/newspack-popups/commit/72ff629ea4a6a50949db1d1de6c11e568e27ceca))
* lower visibility threshold for prompt_seen events ([#976](https://github.com/Automattic/newspack-popups/issues/976)) ([607c20d](https://github.com/Automattic/newspack-popups/commit/607c20d701b88a2d8d0b22ea71a8957e1e234168))
* **release:** use newspack-scripts with fixed semantic-release config ([52119cd](https://github.com/Automattic/newspack-popups/commit/52119cd9b4aa74ca275be4556077f3a6e4298a94))


### Features

* treat logged-in admins and editors as preview sessions ([#979](https://github.com/Automattic/newspack-popups/issues/979)) ([516d076](https://github.com/Automattic/newspack-popups/commit/516d07606a54e126eeb53914f3ae93783efdf988))

# [2.7.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.7.0-alpha.2...v2.7.0-alpha.3) (2022-10-13)


### Bug Fixes

* prompt visibility animation ([b278793](https://github.com/Automattic/newspack-popups/commit/b278793f79e59ec640fc1933dc322b434a14284b))

## [2.6.2](https://github.com/Automattic/newspack-popups/compare/v2.6.1...v2.6.2) (2022-10-13)


### Bug Fixes

* prompt visibility animation ([b278793](https://github.com/Automattic/newspack-popups/commit/b278793f79e59ec640fc1933dc322b434a14284b))

## [2.6.1](https://github.com/Automattic/newspack-popups/compare/v2.6.0...v2.6.1) (2022-10-10)


### Bug Fixes

* cron job and mock IDs ([d0d99b9](https://github.com/Automattic/newspack-popups/commit/d0d99b93c18e85bb024db58564aa56c6416007b1))
* handle purged cache ([59605be](https://github.com/Automattic/newspack-popups/commit/59605be1114573c2443d15af06390b6b28231d5d))
* when to validate cache ([30f0944](https://github.com/Automattic/newspack-popups/commit/30f0944eed595a510d65f029b78d5226554d85cc))

# [2.6.0](https://github.com/Automattic/newspack-popups/compare/v2.5.0...v2.6.0) (2022-09-27)


### Bug Fixes

* fallback to AMP script polyfills if AMP scripts are blocked ([#961](https://github.com/Automattic/newspack-popups/issues/961)) ([923e4fb](https://github.com/Automattic/newspack-popups/commit/923e4fbcc9ed1eed5984b2a4bfecb0ec2339a1ea))


### Features

* add concept of prompt styles and add large style ([0d3ad81](https://github.com/Automattic/newspack-popups/commit/0d3ad8181dff183eb686691e065e80b4ac856b64))
* polyfill amp-access script ([9271111](https://github.com/Automattic/newspack-popups/commit/92711113be26885aace8ffd7856155c2442b807d)), closes [#193](https://github.com/Automattic/newspack-popups/issues/193)
* remove wp-dom-ready, medialement dependencies for frontend script ([b4c930c](https://github.com/Automattic/newspack-popups/commit/b4c930cc8ca735f62c0f2cb4660e4da156dd75f8))
* **segmentation:** by former donor status ([#954](https://github.com/Automattic/newspack-popups/issues/954)) ([0859c54](https://github.com/Automattic/newspack-popups/commit/0859c5473523b7c11cf4b1bad970f4fcfd81ac06))

# [2.6.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.6.0-alpha.1...v2.6.0-alpha.2) (2022-09-26)


### Features

* add concept of prompt styles and add large style ([0d3ad81](https://github.com/Automattic/newspack-popups/commit/0d3ad8181dff183eb686691e065e80b4ac856b64))

# [2.6.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.5.0...v2.6.0-alpha.1) (2022-09-21)


### Bug Fixes

* fallback to AMP script polyfills if AMP scripts are blocked ([#961](https://github.com/Automattic/newspack-popups/issues/961)) ([923e4fb](https://github.com/Automattic/newspack-popups/commit/923e4fbcc9ed1eed5984b2a4bfecb0ec2339a1ea))


### Features

* polyfill amp-access script ([9271111](https://github.com/Automattic/newspack-popups/commit/92711113be26885aace8ffd7856155c2442b807d)), closes [#193](https://github.com/Automattic/newspack-popups/issues/193)
* remove wp-dom-ready, medialement dependencies for frontend script ([b4c930c](https://github.com/Automattic/newspack-popups/commit/b4c930cc8ca735f62c0f2cb4660e4da156dd75f8))
* **segmentation:** by former donor status ([#954](https://github.com/Automattic/newspack-popups/issues/954)) ([0859c54](https://github.com/Automattic/newspack-popups/commit/0859c5473523b7c11cf4b1bad970f4fcfd81ac06))

# [2.5.0](https://github.com/Automattic/newspack-popups/compare/v2.4.0...v2.5.0) (2022-09-21)


### Features

* improved frequency options no longer experimental; clearer UI (v2) ([#966](https://github.com/Automattic/newspack-popups/issues/966)) ([3fea259](https://github.com/Automattic/newspack-popups/commit/3fea259e7f1c790455d40a2b20be931e0131b5c1))

# [2.4.0](https://github.com/Automattic/newspack-popups/compare/v2.3.1...v2.4.0) (2022-09-15)


### Bug Fixes

* force release build ([e25a11e](https://github.com/Automattic/newspack-popups/commit/e25a11ea7fdb5de0df787d15520b5555dee3077c))
* remove duplicate newspack-popups class name ([#947](https://github.com/Automattic/newspack-popups/issues/947)) ([ff0d82f](https://github.com/Automattic/newspack-popups/commit/ff0d82fc770198185bb5d98157c3dec81da20802))


### Features

* allow custom frequencies as long as NEWSPACK_EXPERIMENTAL_READER_ACTIVATION is true ([#953](https://github.com/Automattic/newspack-popups/issues/953)) ([a8cfc42](https://github.com/Automattic/newspack-popups/commit/a8cfc42c4e9b84a79f9e53323e7543270a1b7599))
* **analytics:** prevent marking client as a subscriber if subscribing to only the master list ([0dd5390](https://github.com/Automattic/newspack-popups/commit/0dd5390adb3ca18067917fdd0c0a4a27c9812bd3))
* log reg form info to analytics events ([#951](https://github.com/Automattic/newspack-popups/issues/951)) ([026361b](https://github.com/Automattic/newspack-popups/commit/026361be6520b1d5941784384b88ac4fcee4ec80))
* polyfills for some amp scripts ([#946](https://github.com/Automattic/newspack-popups/issues/946)) ([245fcac](https://github.com/Automattic/newspack-popups/commit/245fcac10cb97de58f0599746b6f3a8c718e914a))
* simplify overlay max-height ([#950](https://github.com/Automattic/newspack-popups/issues/950)) ([c9c6001](https://github.com/Automattic/newspack-popups/commit/c9c60014ab8e98185d1ccd7f9b42f51a1e31899c))
* tapping on overlay background no longer dismisses prompt ([#948](https://github.com/Automattic/newspack-popups/issues/948)) ([fe34eef](https://github.com/Automattic/newspack-popups/commit/fe34eef40b0ff80931f5630ccdf59fffe8d6c0e9))

# [2.4.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.3.1...v2.4.0-alpha.1) (2022-09-06)


### Bug Fixes

* remove duplicate newspack-popups class name ([#947](https://github.com/Automattic/newspack-popups/issues/947)) ([ff0d82f](https://github.com/Automattic/newspack-popups/commit/ff0d82fc770198185bb5d98157c3dec81da20802))


### Features

* allow custom frequencies as long as NEWSPACK_EXPERIMENTAL_READER_ACTIVATION is true ([#953](https://github.com/Automattic/newspack-popups/issues/953)) ([a8cfc42](https://github.com/Automattic/newspack-popups/commit/a8cfc42c4e9b84a79f9e53323e7543270a1b7599))
* **analytics:** prevent marking client as a subscriber if subscribing to only the master list ([0dd5390](https://github.com/Automattic/newspack-popups/commit/0dd5390adb3ca18067917fdd0c0a4a27c9812bd3))
* log reg form info to analytics events ([#951](https://github.com/Automattic/newspack-popups/issues/951)) ([026361b](https://github.com/Automattic/newspack-popups/commit/026361be6520b1d5941784384b88ac4fcee4ec80))
* polyfills for some amp scripts ([#946](https://github.com/Automattic/newspack-popups/issues/946)) ([245fcac](https://github.com/Automattic/newspack-popups/commit/245fcac10cb97de58f0599746b6f3a8c718e914a))
* simplify overlay max-height ([#950](https://github.com/Automattic/newspack-popups/issues/950)) ([c9c6001](https://github.com/Automattic/newspack-popups/commit/c9c60014ab8e98185d1ccd7f9b42f51a1e31899c))
* tapping on overlay background no longer dismisses prompt ([#948](https://github.com/Automattic/newspack-popups/issues/948)) ([fe34eef](https://github.com/Automattic/newspack-popups/commit/fe34eef40b0ff80931f5630ccdf59fffe8d6c0e9))

## [2.3.1](https://github.com/Automattic/newspack-popups/compare/v2.3.0...v2.3.1) (2022-08-18)


### Bug Fixes

* update frequency options disabled status when prompt type is changed ([#941](https://github.com/Automattic/newspack-popups/issues/941)) ([d2c2b12](https://github.com/Automattic/newspack-popups/commit/d2c2b12e11877e57d649a5380e5a19096fe93383))

# [2.3.0](https://github.com/Automattic/newspack-popups/compare/v2.2.1...v2.3.0) (2022-08-16)


### Bug Fixes

* category/tag exclusion fields in block editor ([#935](https://github.com/Automattic/newspack-popups/issues/935)) ([3b4f85d](https://github.com/Automattic/newspack-popups/commit/3b4f85d746e72d47faaa340d8c8b2db5695cacc6))
* remove unneeded arg from preview pruning ([9fa5f4e](https://github.com/Automattic/newspack-popups/commit/9fa5f4e751b185569288c58b27329b2532d8cd89))
* segment matching when no segment is the best-priority ([#930](https://github.com/Automattic/newspack-popups/issues/930)) ([d1a46a7](https://github.com/Automattic/newspack-popups/commit/d1a46a775147bd4aab2550a059e627eecbd69ae2))
* simplify WC donation handling ([#922](https://github.com/Automattic/newspack-popups/issues/922)) ([43edced](https://github.com/Automattic/newspack-popups/commit/43edcedd757ea1be15a961f422c84bbf97038d50))


### Features

* featured images for overlay prompts ([#924](https://github.com/Automattic/newspack-popups/issues/924)) ([1e42665](https://github.com/Automattic/newspack-popups/commit/1e4266566e9b321ffbcd3ada019f2d1e4511361f))
* lookup unknown email addresses in ESP to determine status ([#923](https://github.com/Automattic/newspack-popups/issues/923)) ([c352517](https://github.com/Automattic/newspack-popups/commit/c3525171d1b2031470d5cfbfa7366b25603d2604))
* **newsletters-data:** handle no lists update ([#926](https://github.com/Automattic/newspack-popups/issues/926)) ([df77f1c](https://github.com/Automattic/newspack-popups/commit/df77f1cc2a76751d25754a25f410cd24fcd9009d))
* read ESP donation metadata on login and supply to segmentation ([#927](https://github.com/Automattic/newspack-popups/issues/927)) ([2778b53](https://github.com/Automattic/newspack-popups/commit/2778b53179238cb6d9aca4d55eecf742187953bc))
* **segmentation:** handle pre-authenticated users ([#911](https://github.com/Automattic/newspack-popups/issues/911)) ([211bbe0](https://github.com/Automattic/newspack-popups/commit/211bbe01ac705e4c00a032bdc958ae16edb455ff)), closes [#903](https://github.com/Automattic/newspack-popups/issues/903)

# [2.3.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.2.1...v2.3.0-alpha.1) (2022-08-05)


### Bug Fixes

* remove unneeded arg from preview pruning ([9fa5f4e](https://github.com/Automattic/newspack-popups/commit/9fa5f4e751b185569288c58b27329b2532d8cd89))
* segment matching when no segment is the best-priority ([#930](https://github.com/Automattic/newspack-popups/issues/930)) ([d1a46a7](https://github.com/Automattic/newspack-popups/commit/d1a46a775147bd4aab2550a059e627eecbd69ae2))
* simplify WC donation handling ([#922](https://github.com/Automattic/newspack-popups/issues/922)) ([43edced](https://github.com/Automattic/newspack-popups/commit/43edcedd757ea1be15a961f422c84bbf97038d50))


### Features

* featured images for overlay prompts ([#924](https://github.com/Automattic/newspack-popups/issues/924)) ([1e42665](https://github.com/Automattic/newspack-popups/commit/1e4266566e9b321ffbcd3ada019f2d1e4511361f))
* lookup unknown email addresses in ESP to determine status ([#923](https://github.com/Automattic/newspack-popups/issues/923)) ([c352517](https://github.com/Automattic/newspack-popups/commit/c3525171d1b2031470d5cfbfa7366b25603d2604))
* **newsletters-data:** handle no lists update ([#926](https://github.com/Automattic/newspack-popups/issues/926)) ([df77f1c](https://github.com/Automattic/newspack-popups/commit/df77f1cc2a76751d25754a25f410cd24fcd9009d))
* read ESP donation metadata on login and supply to segmentation ([#927](https://github.com/Automattic/newspack-popups/issues/927)) ([2778b53](https://github.com/Automattic/newspack-popups/commit/2778b53179238cb6d9aca4d55eecf742187953bc))
* **segmentation:** handle pre-authenticated users ([#911](https://github.com/Automattic/newspack-popups/issues/911)) ([211bbe0](https://github.com/Automattic/newspack-popups/commit/211bbe01ac705e4c00a032bdc958ae16edb455ff)), closes [#903](https://github.com/Automattic/newspack-popups/issues/903)

## [2.2.1](https://github.com/Automattic/newspack-popups/compare/v2.2.0...v2.2.1) (2022-08-02)


### Bug Fixes

* utm_source suppression if segment also matches ([#931](https://github.com/Automattic/newspack-popups/issues/931)) ([869054d](https://github.com/Automattic/newspack-popups/commit/869054d05763e37bf379185b7330993848846953))

# [2.2.0](https://github.com/Automattic/newspack-popups/compare/v2.1.0...v2.2.0) (2022-08-01)


### Bug Fixes

* check subscriber status earlier and restore utm_medium=email logic ([#892](https://github.com/Automattic/newspack-popups/issues/892)) ([d71c23e](https://github.com/Automattic/newspack-popups/commit/d71c23eeeb8f8d57898bf96c3f071eef590d2ec0))
* retrieve events from persistent cache, if available ([#915](https://github.com/Automattic/newspack-popups/issues/915)) ([96eb998](https://github.com/Automattic/newspack-popups/commit/96eb998dfbf12a67aecb827ec6d4b031805835a0))


### Features

* improved frequency options ([#908](https://github.com/Automattic/newspack-popups/issues/908)) ([f86d22f](https://github.com/Automattic/newspack-popups/commit/f86d22fae1ba3eb0dd431d32217bb8d5314c21f5))
* **newsletters:** handle subscribe form block event ([#917](https://github.com/Automattic/newspack-popups/issues/917)) ([e89cd14](https://github.com/Automattic/newspack-popups/commit/e89cd145ddceb3a6b61181fb4f6e6f8ffd042f28))
* **styling:** smaller padding on smaller viewports ([#909](https://github.com/Automattic/newspack-popups/issues/909)) ([81262ec](https://github.com/Automattic/newspack-popups/commit/81262ec28e01ffeba026b38c462a345e6e2aed18))
* use existing options to restrict display size of overlay prompts ([#897](https://github.com/Automattic/newspack-popups/issues/897)) ([15f3da8](https://github.com/Automattic/newspack-popups/commit/15f3da831adacae0ef944f1bd8dcd5cd3693230f))

# [2.2.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.2.0-alpha.2...v2.2.0-alpha.3) (2022-07-20)


### Features

* improved frequency options ([#908](https://github.com/Automattic/newspack-popups/issues/908)) ([f86d22f](https://github.com/Automattic/newspack-popups/commit/f86d22fae1ba3eb0dd431d32217bb8d5314c21f5))
* **newsletters:** handle subscribe form block event ([#917](https://github.com/Automattic/newspack-popups/issues/917)) ([e89cd14](https://github.com/Automattic/newspack-popups/commit/e89cd145ddceb3a6b61181fb4f6e6f8ffd042f28))

# [2.2.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v2.2.0-alpha.1...v2.2.0-alpha.2) (2022-07-18)


### Bug Fixes

* retrieve events from persistent cache, if available ([#915](https://github.com/Automattic/newspack-popups/issues/915)) ([96eb998](https://github.com/Automattic/newspack-popups/commit/96eb998dfbf12a67aecb827ec6d4b031805835a0))

# [2.2.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v2.1.0...v2.2.0-alpha.1) (2022-07-14)


### Bug Fixes

* check subscriber status earlier and restore utm_medium=email logic ([#892](https://github.com/Automattic/newspack-popups/issues/892)) ([d71c23e](https://github.com/Automattic/newspack-popups/commit/d71c23eeeb8f8d57898bf96c3f071eef590d2ec0))


### Features

* **styling:** smaller padding on smaller viewports ([#909](https://github.com/Automattic/newspack-popups/issues/909)) ([81262ec](https://github.com/Automattic/newspack-popups/commit/81262ec28e01ffeba026b38c462a345e6e2aed18))
* use existing options to restrict display size of overlay prompts ([#897](https://github.com/Automattic/newspack-popups/issues/897)) ([15f3da8](https://github.com/Automattic/newspack-popups/commit/15f3da831adacae0ef944f1bd8dcd5cd3693230f))

# [2.1.0](https://github.com/Automattic/newspack-popups/compare/v2.0.2...v2.1.0) (2022-06-27)


### Bug Fixes

* non-AMP signup forms ([#876](https://github.com/Automattic/newspack-popups/issues/876)) ([31e13e3](https://github.com/Automattic/newspack-popups/commit/31e13e36aea95e1407225c7e34d40435c2df3315))
* overlays should have a background by default ([#875](https://github.com/Automattic/newspack-popups/issues/875)) ([fbc181c](https://github.com/Automattic/newspack-popups/commit/fbc181cf3df50e8fc4fbd65369898243d8c66bc9))


### Features

* disable prompts by default for protected pages ([#879](https://github.com/Automattic/newspack-popups/issues/879)) ([410ec3e](https://github.com/Automattic/newspack-popups/commit/410ec3ee2199eb4ed32947813d6c882f26a625bd))

# [2.1.0-alpha.3](https://github.com/Automattic/newspack-popups/compare/v2.1.0-alpha.2...v2.1.0-alpha.3) (2022-06-24)


### Bug Fixes

* force release ([9eb0b0f](https://github.com/Automattic/newspack-popups/commit/9eb0b0fcb1e81c6bc19ea592634ab7db14068520))

## [2.0.2](https://github.com/Automattic/newspack-popups/compare/v2.0.1...v2.0.2) (2022-06-24)


### Bug Fixes

* force release ([9eb0b0f](https://github.com/Automattic/newspack-popups/commit/9eb0b0fcb1e81c6bc19ea592634ab7db14068520))

## [2.0.1](https://github.com/Automattic/newspack-popups/compare/v2.0.0...v2.0.1) (2022-06-24)


### Bug Fixes

* misnamed variable in GA non-AMP config filter ([40ae65b](https://github.com/Automattic/newspack-popups/commit/40ae65b9a24c19b6f0efba4d45a3a2a774c20920))

# [2.0.0](https://github.com/Automattic/newspack-popups/compare/v1.52.2...v2.0.0) (2022-05-30)


### Bug Fixes

* force alpha release ([5e7525c](https://github.com/Automattic/newspack-popups/commit/5e7525ced358e6bc7606782988df6f85b9905099))
* overlays should have a background by default ([#875](https://github.com/Automattic/newspack-popups/issues/875)) ([e831d4c](https://github.com/Automattic/newspack-popups/commit/e831d4cabc896b440852548beaf1c61c46598072))


### Features

* **api:** improve web crawler handling ([#863](https://github.com/Automattic/newspack-popups/issues/863)) ([a0a43e9](https://github.com/Automattic/newspack-popups/commit/a0a43e924264feadc8d5ab4cf1ea3511a2b7d7e8))
* new option to not display an overlay background ([#867](https://github.com/Automattic/newspack-popups/issues/867)) ([6a7fb47](https://github.com/Automattic/newspack-popups/commit/6a7fb474d0e2b25d1a9d70a6721821d2e6ab2109))
* remove permanent suppression features ([#866](https://github.com/Automattic/newspack-popups/issues/866)) ([1477205](https://github.com/Automattic/newspack-popups/commit/1477205b2ad04fd61e4e2c145e0a22bd7bc3647c))


### BREAKING CHANGES

* Removes permanent suppression options. All prompts will be displayed to all readers who meet both segmentation and frequency requirements for the prompt, even if those prompts were previously dismissed by those readers. Prompts containing newsletter signup or donation blocks will no longer be suppressed automatically under any circumstances.

* feat: remove permanent suppression features

* test: remove tests related to permanent suppression

# [2.0.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.53.0-alpha.1...v2.0.0-alpha.1) (2022-05-27)


### Features

* new option to not display an overlay background ([#867](https://github.com/Automattic/newspack-popups/issues/867)) ([6a7fb47](https://github.com/Automattic/newspack-popups/commit/6a7fb474d0e2b25d1a9d70a6721821d2e6ab2109))
* remove permanent suppression features ([#866](https://github.com/Automattic/newspack-popups/issues/866)) ([1477205](https://github.com/Automattic/newspack-popups/commit/1477205b2ad04fd61e4e2c145e0a22bd7bc3647c))


### BREAKING CHANGES

* Removes permanent suppression options. All prompts will be displayed to all readers who meet both segmentation and frequency requirements for the prompt, even if those prompts were previously dismissed by those readers. Prompts containing newsletter signup or donation blocks will no longer be suppressed automatically under any circumstances.

* feat: remove permanent suppression features

* test: remove tests related to permanent suppression

# [1.53.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.52.2...v1.53.0-alpha.1) (2022-05-19)


### Features

* **api:** improve web crawler handling ([#863](https://github.com/Automattic/newspack-popups/issues/863)) ([a0a43e9](https://github.com/Automattic/newspack-popups/commit/a0a43e924264feadc8d5ab4cf1ea3511a2b7d7e8))

## [1.52.2](https://github.com/Automattic/newspack-popups/compare/v1.52.1...v1.52.2) (2022-05-10)


### Bug Fixes

* prevent duplicated pageviews if amp-analytics was loaded externally ([2ca79ec](https://github.com/Automattic/newspack-popups/commit/2ca79ec884abbe1c9000229cb894766c8be57fb5))

## [1.52.1](https://github.com/Automattic/newspack-popups/compare/v1.52.0...v1.52.1) (2022-04-20)


### Bug Fixes

* revert group insertion fix ([#858](https://github.com/Automattic/newspack-popups/issues/858)) ([15af041](https://github.com/Automattic/newspack-popups/commit/15af04192c74d832423c226b031321fc7bac0c7b)), closes [#855](https://github.com/Automattic/newspack-popups/issues/855)

## [1.52.1-hotfix.1](https://github.com/Automattic/newspack-popups/compare/v1.52.0...v1.52.1-hotfix.1) (2022-04-20)


### Reverts

* Revert "fix: insertion of prompts around Group blocks (#855)" ([b9917f2](https://github.com/Automattic/newspack-popups/commit/b9917f2f7e9c17bad96c8bb0385dbf144d10344b)), closes [#855](https://github.com/Automattic/newspack-popups/issues/855)

# [1.52.0](https://github.com/Automattic/newspack-popups/compare/v1.51.0...v1.52.0) (2022-04-20)


### Bug Fixes

* insertion of prompts around Group blocks ([#855](https://github.com/Automattic/newspack-popups/issues/855)) ([6ae5ea9](https://github.com/Automattic/newspack-popups/commit/6ae5ea90c664e359d80e116253a2a675fe200b69))
* make "scroll" the default inline trigger_type ([#846](https://github.com/Automattic/newspack-popups/issues/846)) ([a2ffee4](https://github.com/Automattic/newspack-popups/commit/a2ffee42bdc44bec33f8769407f1d5c4344fbeb0))


### Features

* support other newsletter signup forms ([#836](https://github.com/Automattic/newspack-popups/issues/836)) ([c30702d](https://github.com/Automattic/newspack-popups/commit/c30702de0995204c9eea5bc02f1ad6066e20a796))

# [1.52.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.51.1-alpha.1...v1.52.0-alpha.1) (2022-04-20)


### Bug Fixes

* insertion of prompts around Group blocks ([#855](https://github.com/Automattic/newspack-popups/issues/855)) ([6ae5ea9](https://github.com/Automattic/newspack-popups/commit/6ae5ea90c664e359d80e116253a2a675fe200b69))


### Features

* support other newsletter signup forms ([#836](https://github.com/Automattic/newspack-popups/issues/836)) ([c30702d](https://github.com/Automattic/newspack-popups/commit/c30702de0995204c9eea5bc02f1ad6066e20a796))

## [1.51.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.51.0...v1.51.1-alpha.1) (2022-04-18)


### Bug Fixes

* make "scroll" the default inline trigger_type ([#846](https://github.com/Automattic/newspack-popups/issues/846)) ([a2ffee4](https://github.com/Automattic/newspack-popups/commit/a2ffee42bdc44bec33f8769407f1d5c4344fbeb0))

# [1.51.0](https://github.com/Automattic/newspack-popups/compare/v1.50.0...v1.51.0) (2022-04-05)


### Bug Fixes

* donation landing page setting ([#826](https://github.com/Automattic/newspack-popups/issues/826)) ([9b057b8](https://github.com/Automattic/newspack-popups/commit/9b057b805c728b3e29ff51426ff09393f6130f31))
* restore "Announcements" category and move segments to label ([9c429a6](https://github.com/Automattic/newspack-popups/commit/9c429a67478988845082a3ad300b09781acbbe5d))
* track logged-in users interactions ([#839](https://github.com/Automattic/newspack-popups/issues/839)) ([82f1296](https://github.com/Automattic/newspack-popups/commit/82f12966505dd9b11a77205d8b01bcd3f60a0098)), closes [#838](https://github.com/Automattic/newspack-popups/issues/838)


### Features

* add "undismissible" option for inline prompts ([#832](https://github.com/Automattic/newspack-popups/issues/832)) ([f766dac](https://github.com/Automattic/newspack-popups/commit/f766dac3d0e15b89a1d2d92dc0827654bb1d70b6))
* allow segmentation by user login status ([#825](https://github.com/Automattic/newspack-popups/issues/825)) ([affdbea](https://github.com/Automattic/newspack-popups/commit/affdbeae0de92c908e244cb20fb0658d4ee8f412))
* **analytics:** add segment name to event_category ([5a9f7c3](https://github.com/Automattic/newspack-popups/commit/5a9f7c3ac8d6c0c52b3e1e8a2faceddd126f52d4))
* show prompts to logged-in admin and editor users ([#824](https://github.com/Automattic/newspack-popups/issues/824)) ([cba27f5](https://github.com/Automattic/newspack-popups/commit/cba27f52c7114e8df3d92dfd74f4f5ab449b21d2))

# [1.51.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v1.51.0-alpha.1...v1.51.0-alpha.2) (2022-04-05)


### Bug Fixes

* track logged-in users interactions ([#839](https://github.com/Automattic/newspack-popups/issues/839)) ([82f1296](https://github.com/Automattic/newspack-popups/commit/82f12966505dd9b11a77205d8b01bcd3f60a0098)), closes [#838](https://github.com/Automattic/newspack-popups/issues/838)

# [1.51.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.50.0...v1.51.0-alpha.1) (2022-03-31)


### Bug Fixes

* donation landing page setting ([#826](https://github.com/Automattic/newspack-popups/issues/826)) ([9b057b8](https://github.com/Automattic/newspack-popups/commit/9b057b805c728b3e29ff51426ff09393f6130f31))
* restore "Announcements" category and move segments to label ([9c429a6](https://github.com/Automattic/newspack-popups/commit/9c429a67478988845082a3ad300b09781acbbe5d))


### Features

* add "undismissible" option for inline prompts ([#832](https://github.com/Automattic/newspack-popups/issues/832)) ([f766dac](https://github.com/Automattic/newspack-popups/commit/f766dac3d0e15b89a1d2d92dc0827654bb1d70b6))
* allow segmentation by user login status ([#825](https://github.com/Automattic/newspack-popups/issues/825)) ([affdbea](https://github.com/Automattic/newspack-popups/commit/affdbeae0de92c908e244cb20fb0658d4ee8f412))
* **analytics:** add segment name to event_category ([5a9f7c3](https://github.com/Automattic/newspack-popups/commit/5a9f7c3ac8d6c0c52b3e1e8a2faceddd126f52d4))
* show prompts to logged-in admin and editor users ([#824](https://github.com/Automattic/newspack-popups/issues/824)) ([cba27f5](https://github.com/Automattic/newspack-popups/commit/cba27f52c7114e8df3d92dfd74f4f5ab449b21d2))

# [1.50.0](https://github.com/Automattic/newspack-popups/compare/v1.49.1...v1.50.0) (2022-03-22)


### Features

* **segmentation:** add filter to designate additional product IDs as donations ([#818](https://github.com/Automattic/newspack-popups/issues/818)) ([c9a00c8](https://github.com/Automattic/newspack-popups/commit/c9a00c86ff509c1d5d67abae1fd78c716248f4f5))

# [1.50.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.49.1...v1.50.0-alpha.1) (2022-03-15)


### Features

* **segmentation:** add filter to designate additional product IDs as donations ([#818](https://github.com/Automattic/newspack-popups/issues/818)) ([c9a00c8](https://github.com/Automattic/newspack-popups/commit/c9a00c86ff509c1d5d67abae1fd78c716248f4f5))

## [1.49.1](https://github.com/Automattic/newspack-popups/compare/v1.49.0...v1.49.1) (2022-03-10)


### Bug Fixes

* prefix preview query param keys to avoid collisions ([b6b82ed](https://github.com/Automattic/newspack-popups/commit/b6b82ed639e45e40e6e08118eeb083d92b25ffbf))

## [1.49.1-hotfix.1](https://github.com/Automattic/newspack-popups/compare/v1.49.0...v1.49.1-hotfix.1) (2022-03-10)


### Bug Fixes

* prefix preview query param keys to avoid collisions ([b6b82ed](https://github.com/Automattic/newspack-popups/commit/b6b82ed639e45e40e6e08118eeb083d92b25ffbf))

# [1.49.0](https://github.com/Automattic/newspack-popups/compare/v1.48.0...v1.49.0) (2022-03-08)


### Bug Fixes

* allow overlay_opacity to be set to 0 for prompts ([#803](https://github.com/Automattic/newspack-popups/issues/803)) ([1e3f68b](https://github.com/Automattic/newspack-popups/commit/1e3f68b7f7315ecc511fc8e9d6fcf612de98f6b7)), closes [#632](https://github.com/Automattic/newspack-popups/issues/632)
* count inner blocks' content toward post length ([#807](https://github.com/Automattic/newspack-popups/issues/807)) ([f745419](https://github.com/Automattic/newspack-popups/commit/f745419308640d35e66f1d5c5fff41f96bdf45f3))
* ensure correct option is selected for trigger dropdown ([#802](https://github.com/Automattic/newspack-popups/issues/802)) ([b9210b2](https://github.com/Automattic/newspack-popups/commit/b9210b2d7db59faa0a6e82dac5e736c4ef1fbb0a))
* only count donation products for donor segmentation ([#814](https://github.com/Automattic/newspack-popups/issues/814)) ([b471dad](https://github.com/Automattic/newspack-popups/commit/b471dada7ba1f88b03caeddda96e9bafa61186cf))
* prevent default category on term deletion ([#806](https://github.com/Automattic/newspack-popups/issues/806)) ([465c907](https://github.com/Automattic/newspack-popups/commit/465c90705b7dd3e390e2a192dda13e4bdb66884a))
* prevent non-page post types from appearing in settings dropdown ([#813](https://github.com/Automattic/newspack-popups/issues/813)) ([bc1cead](https://github.com/Automattic/newspack-popups/commit/bc1cead574559d851f14a001117a9fc72e7f4a71))
* **preview:** pass url params to links within single-prompt preview iframes ([#792](https://github.com/Automattic/newspack-popups/issues/792)) ([6cad2c0](https://github.com/Automattic/newspack-popups/commit/6cad2c0a7689b5ac696c42724bcb9b3e682952d4)), closes [#663](https://github.com/Automattic/newspack-popups/issues/663)


### Features

* insertion at block count ([#755](https://github.com/Automattic/newspack-popups/issues/755)) ([9240d52](https://github.com/Automattic/newspack-popups/commit/9240d52ec4c4fd39d708520591ef0198fb9346bc)), closes [#623](https://github.com/Automattic/newspack-popups/issues/623)

# [1.49.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v1.49.0-alpha.1...v1.49.0-alpha.2) (2022-03-02)


### Bug Fixes

* only count donation products for donor segmentation ([#814](https://github.com/Automattic/newspack-popups/issues/814)) ([b471dad](https://github.com/Automattic/newspack-popups/commit/b471dada7ba1f88b03caeddda96e9bafa61186cf))
* prevent non-page post types from appearing in settings dropdown ([#813](https://github.com/Automattic/newspack-popups/issues/813)) ([bc1cead](https://github.com/Automattic/newspack-popups/commit/bc1cead574559d851f14a001117a9fc72e7f4a71))

# [1.49.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.48.0...v1.49.0-alpha.1) (2022-02-24)


### Bug Fixes

* allow overlay_opacity to be set to 0 for prompts ([#803](https://github.com/Automattic/newspack-popups/issues/803)) ([1e3f68b](https://github.com/Automattic/newspack-popups/commit/1e3f68b7f7315ecc511fc8e9d6fcf612de98f6b7)), closes [#632](https://github.com/Automattic/newspack-popups/issues/632)
* count inner blocks' content toward post length ([#807](https://github.com/Automattic/newspack-popups/issues/807)) ([f745419](https://github.com/Automattic/newspack-popups/commit/f745419308640d35e66f1d5c5fff41f96bdf45f3))
* ensure correct option is selected for trigger dropdown ([#802](https://github.com/Automattic/newspack-popups/issues/802)) ([b9210b2](https://github.com/Automattic/newspack-popups/commit/b9210b2d7db59faa0a6e82dac5e736c4ef1fbb0a))
* prevent default category on term deletion ([#806](https://github.com/Automattic/newspack-popups/issues/806)) ([465c907](https://github.com/Automattic/newspack-popups/commit/465c90705b7dd3e390e2a192dda13e4bdb66884a))
* **preview:** pass url params to links within single-prompt preview iframes ([#792](https://github.com/Automattic/newspack-popups/issues/792)) ([6cad2c0](https://github.com/Automattic/newspack-popups/commit/6cad2c0a7689b5ac696c42724bcb9b3e682952d4)), closes [#663](https://github.com/Automattic/newspack-popups/issues/663)


### Features

* insertion at block count ([#755](https://github.com/Automattic/newspack-popups/issues/755)) ([9240d52](https://github.com/Automattic/newspack-popups/commit/9240d52ec4c4fd39d708520591ef0198fb9346bc)), closes [#623](https://github.com/Automattic/newspack-popups/issues/623)

# [1.48.0](https://github.com/Automattic/newspack-popups/compare/v1.47.2...v1.48.0) (2022-02-22)


### Bug Fixes

* don't strip URL query params if there's a hash in the URL ([#798](https://github.com/Automattic/newspack-popups/issues/798)) ([4deea2e](https://github.com/Automattic/newspack-popups/commit/4deea2e2218d25b9d64aac558a80502baa434ed9))


### Features

* allow admins to define merge fields for donors ([#777](https://github.com/Automattic/newspack-popups/issues/777)) ([183aa59](https://github.com/Automattic/newspack-popups/commit/183aa59ff62a042f90e0b6ddba15899b567fae07))
* **api:** remove forcing HTTP; tweak referer verification ([5bd188b](https://github.com/Automattic/newspack-popups/commit/5bd188bd490572e5cfb158a170a2dd7535902e65))


### Performance Improvements

* **segmentation:** limit query results when fetching segmentation reach data ([#797](https://github.com/Automattic/newspack-popups/issues/797)) ([eb95a04](https://github.com/Automattic/newspack-popups/commit/eb95a04e0ebb974111e7780b8ad48c5262878314)), closes [#627](https://github.com/Automattic/newspack-popups/issues/627)

# [1.48.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.47.2-alpha.1...v1.48.0-alpha.1) (2022-02-10)


### Bug Fixes

* don't strip URL query params if there's a hash in the URL ([#798](https://github.com/Automattic/newspack-popups/issues/798)) ([4deea2e](https://github.com/Automattic/newspack-popups/commit/4deea2e2218d25b9d64aac558a80502baa434ed9))


### Features

* allow admins to define merge fields for donors ([#777](https://github.com/Automattic/newspack-popups/issues/777)) ([183aa59](https://github.com/Automattic/newspack-popups/commit/183aa59ff62a042f90e0b6ddba15899b567fae07))
* **api:** remove forcing HTTP; tweak referer verification ([5bd188b](https://github.com/Automattic/newspack-popups/commit/5bd188bd490572e5cfb158a170a2dd7535902e65))


### Performance Improvements

* **segmentation:** limit query results when fetching segmentation reach data ([#797](https://github.com/Automattic/newspack-popups/issues/797)) ([eb95a04](https://github.com/Automattic/newspack-popups/commit/eb95a04e0ebb974111e7780b8ad48c5262878314)), closes [#627](https://github.com/Automattic/newspack-popups/issues/627)

## [1.47.2](https://github.com/Automattic/newspack-popups/compare/v1.47.1...v1.47.2) (2022-02-01)

### Bug Fixes

* **class-newspack-popups-model.php:** fire the "Form Submission" event on submit, not submit success ([#790](https://github.com/Automattic/newspack-popups/issues/790)) ([c9bfbbb](https://github.com/Automattic/newspack-popups/commit/c9bfbbb525373400b97488422f6f7549a9b87082))

## [1.47.2-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.47.1...v1.47.2-alpha.1) (2022-02-01)


### Bug Fixes

* **class-newspack-popups-model.php:** fire the "Form Submission" event on submit, not submit success ([#790](https://github.com/Automattic/newspack-popups/issues/790)) ([c9bfbbb](https://github.com/Automattic/newspack-popups/commit/c9bfbbb525373400b97488422f6f7549a9b87082))

## [1.47.1](https://github.com/Automattic/newspack-popups/compare/v1.47.0...v1.47.1) (2022-01-25)


### Bug Fixes

* view.js conflict with mediaelement.js ([#771](https://github.com/Automattic/newspack-popups/issues/771)) ([748451e](https://github.com/Automattic/newspack-popups/commit/748451e9dcae71647c831359f8d679320ef42b4c))

## [1.47.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.47.0...v1.47.1-alpha.1) (2022-01-24)


### Bug Fixes

* view.js conflict with mediaelement.js ([#771](https://github.com/Automattic/newspack-popups/issues/771)) ([748451e](https://github.com/Automattic/newspack-popups/commit/748451e9dcae71647c831359f8d679320ef42b4c))

# [1.47.0](https://github.com/Automattic/newspack-popups/compare/v1.46.0...v1.47.0) (2021-12-15)


### Features

* add a filter for supported post types ([#726](https://github.com/Automattic/newspack-popups/issues/726)) ([bd94d2a](https://github.com/Automattic/newspack-popups/commit/bd94d2a235ebe90896f4856e5f1e15afe53ec4bf))


### Performance Improvements

* shorten query params for preview iframe URLs ([#732](https://github.com/Automattic/newspack-popups/issues/732)) ([42af58f](https://github.com/Automattic/newspack-popups/commit/42af58f7129cfe7896fc8adde3e40dd5ff46bf82))

# [1.47.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.46.0...v1.47.0-alpha.1) (2021-12-15)


### Features

* add a filter for supported post types ([#726](https://github.com/Automattic/newspack-popups/issues/726)) ([bd94d2a](https://github.com/Automattic/newspack-popups/commit/bd94d2a235ebe90896f4856e5f1e15afe53ec4bf))


### Performance Improvements

* shorten query params for preview iframe URLs ([#732](https://github.com/Automattic/newspack-popups/issues/732)) ([42af58f](https://github.com/Automattic/newspack-popups/commit/42af58f7129cfe7896fc8adde3e40dd5ff46bf82))

# [1.46.0](https://github.com/Automattic/newspack-popups/compare/v1.45.2...v1.46.0) (2021-12-14)


### Bug Fixes

* archive prompt spacing ([#715](https://github.com/Automattic/newspack-popups/issues/715)) ([248c093](https://github.com/Automattic/newspack-popups/commit/248c093cf78f44500ad1e9e4802b9ce9a1d701c7)), closes [#708](https://github.com/Automattic/newspack-popups/issues/708) [#708](https://github.com/Automattic/newspack-popups/issues/708)
* duplicated, grouped blocks ([#727](https://github.com/Automattic/newspack-popups/issues/727)) ([062be58](https://github.com/Automattic/newspack-popups/commit/062be58abad6e4cba9f050834a01885558e12d7d))
* naming duplicated prompts ([#706](https://github.com/Automattic/newspack-popups/issues/706)) ([3fa3be6](https://github.com/Automattic/newspack-popups/commit/3fa3be699b688d2423cdc7fe0a3b12111a782fae))


### Features

* **api:** add HTTP reponse codes to error reponses ([7572ff2](https://github.com/Automattic/newspack-popups/commit/7572ff2a423082d046075e9bf172971e3ff678cb))

# [1.46.0-alpha.2](https://github.com/Automattic/newspack-popups/compare/v1.46.0-alpha.1...v1.46.0-alpha.2) (2021-12-10)


### Bug Fixes

* duplicated, grouped blocks ([#727](https://github.com/Automattic/newspack-popups/issues/727)) ([062be58](https://github.com/Automattic/newspack-popups/commit/062be58abad6e4cba9f050834a01885558e12d7d))

# [1.46.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.45.2...v1.46.0-alpha.1) (2021-12-09)


### Bug Fixes

* archive prompt spacing ([#715](https://github.com/Automattic/newspack-popups/issues/715)) ([248c093](https://github.com/Automattic/newspack-popups/commit/248c093cf78f44500ad1e9e4802b9ce9a1d701c7)), closes [#708](https://github.com/Automattic/newspack-popups/issues/708) [#708](https://github.com/Automattic/newspack-popups/issues/708)
* naming duplicated prompts ([#706](https://github.com/Automattic/newspack-popups/issues/706)) ([3fa3be6](https://github.com/Automattic/newspack-popups/commit/3fa3be699b688d2423cdc7fe0a3b12111a782fae))


### Features

* **api:** add HTTP reponse codes to error reponses ([7572ff2](https://github.com/Automattic/newspack-popups/commit/7572ff2a423082d046075e9bf172971e3ff678cb))

## [1.45.2](https://github.com/Automattic/newspack-popups/compare/v1.45.1...v1.45.2) (2021-12-02)


### Bug Fixes

* stricter empty block check ([#717](https://github.com/Automattic/newspack-popups/issues/717)) ([86108a3](https://github.com/Automattic/newspack-popups/commit/86108a359bb0183c6d4df8932fba47991718f881))

## [1.45.2-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.45.1...v1.45.2-alpha.1) (2021-12-02)


### Bug Fixes

* stricter empty block check ([#717](https://github.com/Automattic/newspack-popups/issues/717)) ([86108a3](https://github.com/Automattic/newspack-popups/commit/86108a359bb0183c6d4df8932fba47991718f881))

## [1.45.1](https://github.com/Automattic/newspack-popups/compare/v1.45.0...v1.45.1) (2021-12-01)


### Bug Fixes

* path to config file ([2758ae1](https://github.com/Automattic/newspack-popups/commit/2758ae1f7354028628b82f6a1f44c2558cf92ab8))

## [1.45.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.45.0...v1.45.1-alpha.1) (2021-12-01)


### Bug Fixes

* path to config file ([2758ae1](https://github.com/Automattic/newspack-popups/commit/2758ae1f7354028628b82f6a1f44c2558cf92ab8))

# [1.45.0](https://github.com/Automattic/newspack-popups/compare/v1.44.0...v1.45.0) (2021-11-30)


### Bug Fixes

* prevent insertions which break layout; improve classic-content insertion ([#647](https://github.com/Automattic/newspack-popups/issues/647)) ([3a19928](https://github.com/Automattic/newspack-popups/commit/3a199287a36359493b311258ca1f47236f550e59)), closes [#555](https://github.com/Automattic/newspack-popups/issues/555)


### Features

* enable config path override from environment ([#660](https://github.com/Automattic/newspack-popups/issues/660)) ([8327ea3](https://github.com/Automattic/newspack-popups/commit/8327ea3ee6ca5eed93e3b8660ef2e44cd0520196))
* update link text ([#658](https://github.com/Automattic/newspack-popups/issues/658)) ([864e65e](https://github.com/Automattic/newspack-popups/commit/864e65ed0cc1018208698a2351eb84375e72092f)), closes [#454](https://github.com/Automattic/newspack-popups/issues/454)

# [1.45.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.44.1-alpha.1...v1.45.0-alpha.1) (2021-11-30)


### Features

* enable config path override from environment ([#660](https://github.com/Automattic/newspack-popups/issues/660)) ([8327ea3](https://github.com/Automattic/newspack-popups/commit/8327ea3ee6ca5eed93e3b8660ef2e44cd0520196))
* update link text ([#658](https://github.com/Automattic/newspack-popups/issues/658)) ([864e65e](https://github.com/Automattic/newspack-popups/commit/864e65ed0cc1018208698a2351eb84375e72092f)), closes [#454](https://github.com/Automattic/newspack-popups/issues/454)

## [1.44.1-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.44.0...v1.44.1-alpha.1) (2021-11-18)


### Bug Fixes

* prevent insertions which break layout; improve classic-content insertion ([#647](https://github.com/Automattic/newspack-popups/issues/647)) ([3a19928](https://github.com/Automattic/newspack-popups/commit/3a199287a36359493b311258ca1f47236f550e59)), closes [#555](https://github.com/Automattic/newspack-popups/issues/555)

# [1.44.0](https://github.com/Automattic/newspack-popups/compare/v1.43.0...v1.44.0) (2021-11-18)


### Bug Fixes

* **segmentation:** change MC API key option name ([#638](https://github.com/Automattic/newspack-popups/issues/638)) ([fcf8161](https://github.com/Automattic/newspack-popups/commit/fcf81615d3d438a9adaf9dddbf20f6ec7beeb40f))
* post type support conditions ([dfea3da](https://github.com/Automattic/newspack-popups/commit/dfea3da2f7e456d74aa19effc140dac02fdf0971))


### Features

* **popup:** add more placements ([#624](https://github.com/Automattic/newspack-popups/issues/624)) ([4ca4710](https://github.com/Automattic/newspack-popups/commit/4ca47100c144a78be36fb9d78fa25d8886e44378)), closes [#114](https://github.com/Automattic/newspack-popups/issues/114) [#114](https://github.com/Automattic/newspack-popups/issues/114)
* enable excluding categories per prompt ([#641](https://github.com/Automattic/newspack-popups/issues/641)) ([b5b65b2](https://github.com/Automattic/newspack-popups/commit/b5b65b2ee8c9503a4fb6d6ace36950591bba1de2))

# [1.44.0-alpha.1](https://github.com/Automattic/newspack-popups/compare/v1.43.0...v1.44.0-alpha.1) (2021-11-16)


### Bug Fixes

* **segmentation:** change MC API key option name ([#638](https://github.com/Automattic/newspack-popups/issues/638)) ([fcf8161](https://github.com/Automattic/newspack-popups/commit/fcf81615d3d438a9adaf9dddbf20f6ec7beeb40f))
* post type support conditions ([dfea3da](https://github.com/Automattic/newspack-popups/commit/dfea3da2f7e456d74aa19effc140dac02fdf0971))


### Features

* **popup:** add more placements ([#624](https://github.com/Automattic/newspack-popups/issues/624)) ([4ca4710](https://github.com/Automattic/newspack-popups/commit/4ca47100c144a78be36fb9d78fa25d8886e44378)), closes [#114](https://github.com/Automattic/newspack-popups/issues/114) [#114](https://github.com/Automattic/newspack-popups/issues/114)
* enable excluding categories per prompt ([#641](https://github.com/Automattic/newspack-popups/issues/641)) ([b5b65b2](https://github.com/Automattic/newspack-popups/commit/b5b65b2ee8c9503a4fb6d6ace36950591bba1de2))

# [1.43.0](https://github.com/Automattic/newspack-popups/compare/v1.42.1...v1.43.0) (2021-11-09)


### Bug Fixes

* check if gtm exists ([ef265c7](https://github.com/Automattic/newspack-popups/commit/ef265c7f8617a00775313f13ec1a1f5df81ba32c))


### Features

* **editor:** handle archive placement in preview ([ac67699](https://github.com/Automattic/newspack-popups/commit/ac6769991d2685b5ffe678768da54f3bbb2b5064))

## [1.42.1](https://github.com/Automattic/newspack-popups/compare/v1.42.0...v1.42.1) (2021-11-03)


### Bug Fixes

* displaying on custom post types ([05f6f8c](https://github.com/Automattic/newspack-popups/commit/05f6f8cd8111ca4c12ed4f6f1a3ac8f1e4464a0f))
* donor landing page ([bedcb4c](https://github.com/Automattic/newspack-popups/commit/bedcb4c47be7c3ea570543bf466bd3b0d7aad590))
* update ci images ([#648](https://github.com/Automattic/newspack-popups/issues/648)) ([7a05ed4](https://github.com/Automattic/newspack-popups/commit/7a05ed4d33451aec0eec2dc3cdb6c513f002fa5a))

# [1.42.0](https://github.com/Automattic/newspack-popups/compare/v1.41.0...v1.42.0) (2021-10-19)


### Features

* initialize prompt creation by manual placement ([#639](https://github.com/Automattic/newspack-popups/issues/639)) ([9e45159](https://github.com/Automattic/newspack-popups/commit/9e45159741643c4c32932060c70fd346c52c8cbc))

# [1.41.0](https://github.com/Automattic/newspack-popups/compare/v1.40.0...v1.41.0) (2021-10-05)


### Bug Fixes

* fix prompt previewe permalink for archive placement ([cb4a2ad](https://github.com/Automattic/newspack-popups/commit/cb4a2addea7164e58fb0c4c06dc9530aea5584fb))


### Features

* add archive page types filter for inline prompts ([bce176a](https://github.com/Automattic/newspack-popups/commit/bce176a81b4b29ace31f6e4ff249f3b6453b3bbf))
* handle in archive pages placement when adding a prompt ([c97baf7](https://github.com/Automattic/newspack-popups/commit/c97baf7260e434743ec9fcce94f758e81f815ce4))
* **inline prompt:** add a new placement option for the archive pages ([83ae398](https://github.com/Automattic/newspack-popups/commit/83ae398348910881145247ff4b6c970405650d74)), closes [#450](https://github.com/Automattic/newspack-popups/issues/450)


### Performance Improvements

* prune rows from DB tables with data larger than a certain size ([#634](https://github.com/Automattic/newspack-popups/issues/634)) ([4b4fc5a](https://github.com/Automattic/newspack-popups/commit/4b4fc5a4bcee9f72fdae0448ed23448ec64e382e))

# [1.40.0](https://github.com/Automattic/newspack-popups/compare/v1.39.1...v1.40.0) (2021-09-28)


### Features

* **segmentation:** improve how Mailchimp merge fields are handled ([f76db06](https://github.com/Automattic/newspack-popups/commit/f76db06ecdf5293ea0641bbeb9d862a99b36e5ee))

## [1.39.1](https://github.com/Automattic/newspack-popups/compare/v1.39.0...v1.39.1) (2021-09-08)


### Bug Fixes

* ensure only popup CPTs are handled ([fdc1bdd](https://github.com/Automattic/newspack-popups/commit/fdc1bdd32267ad59fcd03e61c4f7369af7b41768))

# [1.39.0](https://github.com/Automattic/newspack-popups/compare/v1.38.0...v1.39.0) (2021-08-31)


### Bug Fixes

* cpt handling ([91d48a4](https://github.com/Automattic/newspack-popups/commit/91d48a4ae09afb468367db9857eb425e33b668d1))
* disable until configured ([#608](https://github.com/Automattic/newspack-popups/issues/608)) ([aae712a](https://github.com/Automattic/newspack-popups/commit/aae712a43d7b581d23a1493ed6dad2c426f67070)), closes [#591](https://github.com/Automattic/newspack-popups/issues/591)
* include drafts in segment previews ([7c57ba9](https://github.com/Automattic/newspack-popups/commit/7c57ba92bef548d0a0aef5ecfe1713d87a9a8520))


### Features

* update icons and blocks ([#611](https://github.com/Automattic/newspack-popups/issues/611)) ([75f2018](https://github.com/Automattic/newspack-popups/commit/75f201801e61302179aa31defd8a3137e813c45f))

# [1.38.0](https://github.com/Automattic/newspack-popups/compare/v1.37.2...v1.38.0) (2021-08-25)


### Features

* targeting campaigns by post type ([91b056b](https://github.com/Automattic/newspack-popups/commit/91b056bfa7ea51ffffe9bd7312a395edb8de5be5)), closes [#567](https://github.com/Automattic/newspack-popups/issues/567)

## [1.37.2](https://github.com/Automattic/newspack-popups/compare/v1.37.1...v1.37.2) (2021-08-17)


### Bug Fixes

* amp analytics polyfill tweak ([e8de420](https://github.com/Automattic/newspack-popups/commit/e8de420cc1481f5b9dede20563a35412474c2b44))

## [1.37.1](https://github.com/Automattic/newspack-popups/compare/v1.37.0...v1.37.1) (2021-08-10)


### Bug Fixes

* email substitution in newsletter forms ([73bd4cf](https://github.com/Automattic/newspack-popups/commit/73bd4cfcfcd7f40161c2837138ea189ee7b5ec91))
* **donations:** update WC webhook if needed ([7a3e61d](https://github.com/Automattic/newspack-popups/commit/7a3e61de8ca32e63ee5e2cb6a008fd722cb1b245))

# [1.37.0](https://github.com/Automattic/newspack-popups/compare/v1.36.0...v1.37.0) (2021-08-03)


### Bug Fixes

* above-header popups on pages ([551e983](https://github.com/Automattic/newspack-popups/commit/551e983d00379e684c98c146487595bbc5fc0d64))
* display prompts in the customizer ([#587](https://github.com/Automattic/newspack-popups/issues/587)) ([fa47c38](https://github.com/Automattic/newspack-popups/commit/fa47c38e109ed5e0c6c6f278f107dca01e442619)), closes [#577](https://github.com/Automattic/newspack-popups/issues/577)


### Features

* remove options whitelist ([3f08781](https://github.com/Automattic/newspack-popups/commit/3f08781c583133e4c49c3cb60addbcb45707c829))

# [1.36.0](https://github.com/Automattic/newspack-popups/compare/v1.35.1...v1.36.0) (2021-07-27)


### Features

* sanitize utm suppression setting ([#585](https://github.com/Automattic/newspack-popups/issues/585)) ([95890c3](https://github.com/Automattic/newspack-popups/commit/95890c3b63ee7f0aa971f24fca1dc4992d82120c)), closes [#428](https://github.com/Automattic/newspack-popups/issues/428)
* **api:** remove reader data if no updates in 30 days ([#584](https://github.com/Automattic/newspack-popups/issues/584)) ([e5db8de](https://github.com/Automattic/newspack-popups/commit/e5db8dec3ac0dcb57838baf470dfd1ce8a7c55cf)), closes [#583](https://github.com/Automattic/newspack-popups/issues/583)
* enable retrieving prompts from trash ([736098f](https://github.com/Automattic/newspack-popups/commit/736098fc1945009c9c71feb1cdc44065ada38970))

## [1.35.1](https://github.com/Automattic/newspack-popups/compare/v1.35.0...v1.35.1) (2021-07-19)


### Bug Fixes

* allow custom placement prompts to be displayed if in widget blocks ([#578](https://github.com/Automattic/newspack-popups/issues/578)) ([aaedc03](https://github.com/Automattic/newspack-popups/commit/aaedc0355e8efbbb2c4dd240cd4d67439506b9f0))
* allow manual-only prompts to appear in widget blocks ([#574](https://github.com/Automattic/newspack-popups/issues/574)) ([a1c5f65](https://github.com/Automattic/newspack-popups/commit/a1c5f654968a308cf35fa4c72a350d5996cb63ec))
* assume DB prefix; ensure https ([fee1cae](https://github.com/Automattic/newspack-popups/commit/fee1cae44d35310021867604c7c81321e0aaea75))
* **wp-5.8:** avoid an editor crash due to missing data in widgets block editor ([#572](https://github.com/Automattic/newspack-popups/issues/572)) ([db47c64](https://github.com/Automattic/newspack-popups/commit/db47c6497f25f53612090231ee45448aef3ff839)), closes [#570](https://github.com/Automattic/newspack-popups/issues/570)

# [1.35.0](https://github.com/Automattic/newspack-popups/compare/v1.34.0...v1.35.0) (2021-06-30)


### Features

* improved duplication UI via modal ([#564](https://github.com/Automattic/newspack-popups/issues/564)) ([8e12b2e](https://github.com/Automattic/newspack-popups/commit/8e12b2efcc98d5cce5332cc2770791b7ba0a1af1))

# [1.34.0](https://github.com/Automattic/newspack-popups/compare/v1.33.0...v1.34.0) (2021-06-15)


### Bug Fixes

* prevent prompts in account related posts ([ef56ccc](https://github.com/Automattic/newspack-popups/commit/ef56ccca9925114a914330dab5873ef8bc0c8d06)), closes [#430](https://github.com/Automattic/newspack-popups/issues/430)


### Features

* allow shortcodes in prompt content ([#557](https://github.com/Automattic/newspack-popups/issues/557)) ([54b7d5d](https://github.com/Automattic/newspack-popups/commit/54b7d5d263c678f715975b310cac551491ba735b))
* duplicate a prompt ([#558](https://github.com/Automattic/newspack-popups/issues/558)) ([f9d8131](https://github.com/Automattic/newspack-popups/commit/f9d8131926ff79ef6471c6ba317b04445e3b72b8))

# [1.33.0](https://github.com/Automattic/newspack-popups/compare/v1.32.1...v1.33.0) (2021-05-26)


### Features

* single prompt block and manual-only placement option ([#547](https://github.com/Automattic/newspack-popups/issues/547)) ([1f1d83b](https://github.com/Automattic/newspack-popups/commit/1f1d83b0ffa5d5ab33344fa510e1b583b45a106e))

## [1.32.1](https://github.com/Automattic/newspack-popups/compare/v1.32.0...v1.32.1) (2021-05-13)


### Bug Fixes

* **analytics:** form selector ([9ba35b6](https://github.com/Automattic/newspack-popups/commit/9ba35b69309d739e181ee6b7ce6d159dc2ffba66))

# [1.32.0](https://github.com/Automattic/newspack-popups/compare/v1.31.1...v1.32.0) (2021-05-05)


### Bug Fixes

* bump query limit up to 100 for eligible prompts ([#536](https://github.com/Automattic/newspack-popups/issues/536)) ([bebf6ec](https://github.com/Automattic/newspack-popups/commit/bebf6ec3a6f1066c124af9313f2abca9eaeebac2))
* use filter to decide which post types to insert ads into content ([#537](https://github.com/Automattic/newspack-popups/issues/537)) ([dafa550](https://github.com/Automattic/newspack-popups/commit/dafa550b4381e5790863fd4f21ad81ca8777dadd))


### Features

* **analytics:** revert event encoding ([#535](https://github.com/Automattic/newspack-popups/issues/535)) ([f54a872](https://github.com/Automattic/newspack-popups/commit/f54a8721247c4e3809ed1983b3c0d0ffbc24dfb0)), closes [#479](https://github.com/Automattic/newspack-popups/issues/479)
* **api:** debugging parameter ([#539](https://github.com/Automattic/newspack-popups/issues/539)) ([e8fd529](https://github.com/Automattic/newspack-popups/commit/e8fd529b296b850ff0707af4d93dc9dae1511c0b))

## [1.31.1](https://github.com/Automattic/newspack-popups/compare/v1.31.0...v1.31.1) (2021-04-28)


### Bug Fixes

* interaction of above-header prompts in non-AMP mode ([0c3a7c5](https://github.com/Automattic/newspack-popups/commit/0c3a7c5ba1411b6f02f7b36e4a5193b03d476d7d))
* **segments:** filter out non-existing categories ([#529](https://github.com/Automattic/newspack-popups/issues/529)) ([d0160ef](https://github.com/Automattic/newspack-popups/commit/d0160eff6718d763910d27d1a941e1121796299f)), closes [#491](https://github.com/Automattic/newspack-popups/issues/491)
* deduplicate popups for amp-access ([9aaf006](https://github.com/Automattic/newspack-popups/commit/9aaf0063792b3ac3b71fd9e41522c465db304cd4))
* empty blocks handling ([#524](https://github.com/Automattic/newspack-popups/issues/524)) ([fba0154](https://github.com/Automattic/newspack-popups/commit/fba0154ed9878aae60da77a7cf2bacc1d4eabd3d))
* hide WC client ID field even if not on Newspack theme ([4c1ebe6](https://github.com/Automattic/newspack-popups/commit/4c1ebe65af6ff2716c506319e9c01df2e72ed225))
* prevent overlay prompts animation conflicts ([a8af34b](https://github.com/Automattic/newspack-popups/commit/a8af34b40228ad29945658514556935c8f583db9)), closes [#526](https://github.com/Automattic/newspack-popups/issues/526)

# [1.31.0](https://github.com/Automattic/newspack-popups/compare/v1.30.1...v1.31.0) (2021-04-13)


### Bug Fixes

* previewing drafts ([3673e20](https://github.com/Automattic/newspack-popups/commit/3673e20715f25ac57f378fdefb32e33d962fe0fe))
* single-prompt placement logic ([2690ed3](https://github.com/Automattic/newspack-popups/commit/2690ed34f47b492b95e6ae5745cc87ff333c60ce))


### Features

* handle classic content for inline prompt insertion ([#521](https://github.com/Automattic/newspack-popups/issues/521)) ([d361d5c](https://github.com/Automattic/newspack-popups/commit/d361d5c4e078ee9d4e6d4a5882fb476998e2aa48))
* new option to show border for inline prompts ([#513](https://github.com/Automattic/newspack-popups/issues/513)) ([78647ca](https://github.com/Automattic/newspack-popups/commit/78647ca8b5b65c2a040a8b3f72b398052449e0a8))

## [1.30.1](https://github.com/Automattic/newspack-popups/compare/v1.30.0...v1.30.1) (2021-04-09)


### Bug Fixes

* tags criterion for displaying popups ([#515](https://github.com/Automattic/newspack-popups/issues/515)) ([13d2be7](https://github.com/Automattic/newspack-popups/commit/13d2be7e4de7ae2c49a39e7d96c3d9faf7d24505))

# [1.30.0](https://github.com/Automattic/newspack-popups/compare/v1.29.0...v1.30.0) (2021-04-06)


### Bug Fixes

* shortcode, amp-access fixes ([#505](https://github.com/Automattic/newspack-popups/issues/505)) ([b17cb94](https://github.com/Automattic/newspack-popups/commit/b17cb94e9b312d473601817c56282e4c20547a46))
* tag filtering ([#506](https://github.com/Automattic/newspack-popups/issues/506)) ([e88e7c7](https://github.com/Automattic/newspack-popups/commit/e88e7c750c74e05985a6d65729e4c2ab7ae3d8f3))
* when previewing "everyone" segment, show only prompts with no segments ([#507](https://github.com/Automattic/newspack-popups/issues/507)) ([8c800d8](https://github.com/Automattic/newspack-popups/commit/8c800d8151b1615e0a8e9a1b79845a356633a39c))


### Features

* **api:** ignore web crawler traffic ([#509](https://github.com/Automattic/newspack-popups/issues/509)) ([95ada9d](https://github.com/Automattic/newspack-popups/commit/95ada9d205bad1224a914979a35d571e4aa09743))
* remove preview sessions data from DB daily; save last update date ([f0dea91](https://github.com/Automattic/newspack-popups/commit/f0dea918163b440bb9212f3144471ff35d349f03))
* retrieve widget popups ids from options ([ba0ad47](https://github.com/Automattic/newspack-popups/commit/ba0ad47261e92895eab746f7c4c55e4eb7d680cd)), closes [#470](https://github.com/Automattic/newspack-popups/issues/470)
* simplify retrieval ([b3299b2](https://github.com/Automattic/newspack-popups/commit/b3299b2f57f0f5adc7463df55d1496494becd612)), closes [#416](https://github.com/Automattic/newspack-popups/issues/416)

# [1.29.0](https://github.com/Automattic/newspack-popups/compare/v1.28.0...v1.29.0) (2021-03-30)


### Features

* use RadioControls for prompt type selection ([#501](https://github.com/Automattic/newspack-popups/issues/501)) ([ebb6776](https://github.com/Automattic/newspack-popups/commit/ebb6776788d0a9cda4bad92349d28fe9cbeefb4f))

# [1.28.0](https://github.com/Automattic/newspack-popups/compare/v1.27.0...v1.28.0) (2021-03-24)


### Bug Fixes

* allow filters to suppress popups better ([#487](https://github.com/Automattic/newspack-popups/issues/487)) ([12030e3](https://github.com/Automattic/newspack-popups/commit/12030e3790e4d5e1c0d37ad5135888046ab2f684))
* give blacklisted blocks a nominal length for 0% insertion logic ([2f66203](https://github.com/Automattic/newspack-popups/commit/2f66203ce31e01eba2bcc05934f2199e185c81cb))
* introduce override blocks to ignore when inserting prompts ([14c82b4](https://github.com/Automattic/newspack-popups/commit/14c82b493a9aa649c01effc028e0407f7687c0ea))
* remove default category on prompt activation ([#482](https://github.com/Automattic/newspack-popups/issues/482)) ([84d96db](https://github.com/Automattic/newspack-popups/commit/84d96db0c73cc4fb1f86044286cc663bb86a0525))


### Features

* custom placements ([#467](https://github.com/Automattic/newspack-popups/issues/467)) ([c51ff62](https://github.com/Automattic/newspack-popups/commit/c51ff628dea0022b22599209058b5e927777018e))
* leaner GA config ([fb30333](https://github.com/Automattic/newspack-popups/commit/fb303337ed9cfa07f1b2978950bc0bcdd4a2a672))

# [1.27.0](https://github.com/Automattic/newspack-popups/compare/v1.26.2...v1.27.0) (2021-03-17)


### Bug Fixes

* process custom dimensions as strings ([7443e67](https://github.com/Automattic/newspack-popups/commit/7443e673c33303876a511f54a9b8d29c98592f3a)), closes [#461](https://github.com/Automattic/newspack-popups/issues/461)


### Features

* add timestamp column to custom transients table ([fd2bc0b](https://github.com/Automattic/newspack-popups/commit/fd2bc0b6790105ce1d186f515aa8fca6b8aaf042))

## [1.26.2](https://github.com/Automattic/newspack-popups/compare/v1.26.1...v1.26.2) (2021-03-12)


### Bug Fixes

* render_block causes Homepage Posts to render wrong post ([#484](https://github.com/Automattic/newspack-popups/issues/484)) ([239368f](https://github.com/Automattic/newspack-popups/commit/239368f64456676dee753febc4012cccf5f6c4f3))

## [1.26.1](https://github.com/Automattic/newspack-popups/compare/v1.26.0...v1.26.1) (2021-03-09)


### Bug Fixes

* inline positions computation ([32836f1](https://github.com/Automattic/newspack-popups/commit/32836f12b5e498898125c3d320c6cfcf183165c9))
* no amp-access auth check for inactive prompts ([#474](https://github.com/Automattic/newspack-popups/issues/474)) ([95b7f63](https://github.com/Automattic/newspack-popups/commit/95b7f63d43e7e8db3bf0a6c6f6dbef8744d97ef5))
* prevents segmentation-derived custom dimensions reporting in non-interaction mode ([#464](https://github.com/Automattic/newspack-popups/issues/464)) ([c241e58](https://github.com/Automattic/newspack-popups/commit/c241e58014e52528799d492ae2292e570bdef4c4))

# [1.26.0](https://github.com/Automattic/newspack-popups/compare/v1.25.0...v1.26.0) (2021-03-03)


### Features

* enable visit logging by default ([#460](https://github.com/Automattic/newspack-popups/issues/460)) ([0ed17c2](https://github.com/Automattic/newspack-popups/commit/0ed17c2df42b7f7dc4bc5bd772b53687daa536c5))
* handle visit data when previewing ([#458](https://github.com/Automattic/newspack-popups/issues/458)) ([f58c0ec](https://github.com/Automattic/newspack-popups/commit/f58c0ec4f9a58bed6e6f9fdc38c5d467f1bb770a))
* non-cascading segment logic; multiple segments per prompt ([#432](https://github.com/Automattic/newspack-popups/issues/432), [#451](https://github.com/Automattic/newspack-popups/issues/451)) ([8c0e903](https://github.com/Automattic/newspack-popups/commit/8c0e903c4b72ea9d02e0a832e92721f91b2018c4))

# [1.25.0](https://github.com/Automattic/newspack-popups/compare/v1.24.1...v1.25.0) (2021-02-25)


### Bug Fixes

* show shortcoded prompts in previews ([#452](https://github.com/Automattic/newspack-popups/issues/452)) ([846df25](https://github.com/Automattic/newspack-popups/commit/846df254897441280808e35c8660550ff100cfd2))


### Features

* validate segmentation sort operations ([#455](https://github.com/Automattic/newspack-popups/issues/455)) ([8b17170](https://github.com/Automattic/newspack-popups/commit/8b17170b84de4a1315d5862ef66b5b8d4651d6da))

## [1.24.1](https://github.com/Automattic/newspack-popups/compare/v1.24.0...v1.24.1) (2021-02-16)


### Bug Fixes

* increase max number of prompts to retrieve ([#446](https://github.com/Automattic/newspack-popups/issues/446)) ([fb380bf](https://github.com/Automattic/newspack-popups/commit/fb380bf79b4f1a79645fa27d4156981fec22442a))

# [1.24.0](https://github.com/Automattic/newspack-popups/compare/v1.23.1...v1.24.0) (2021-02-11)


### Bug Fixes

* add cache control headers ([#434](https://github.com/Automattic/newspack-popups/issues/434)) ([3a0d7f5](https://github.com/Automattic/newspack-popups/commit/3a0d7f5d01db930cb4945b49435f8fb2681ac44c))
* prevent category overlays from being fetched erroneously ([#439](https://github.com/Automattic/newspack-popups/issues/439)) ([14f3c88](https://github.com/Automattic/newspack-popups/commit/14f3c88e12cc440459e5228b64c50002c1b45471))
* preview functionality by campaign/group ([#438](https://github.com/Automattic/newspack-popups/issues/438)) ([fb79dfa](https://github.com/Automattic/newspack-popups/commit/fb79dfaccbed418d6dcb26a36686f779660ee6fb))
* set segment id to null if segment doesn't exist ([#425](https://github.com/Automattic/newspack-popups/issues/425)) ([ef70393](https://github.com/Automattic/newspack-popups/commit/ef70393500bd7444849f247014ffe35b6a30074d))


### Features

* campaign management functions ([#427](https://github.com/Automattic/newspack-popups/issues/427)) ([df72141](https://github.com/Automattic/newspack-popups/commit/df7214139826a37ee8033d8d95654b72faef2b5c))
* create campaign function ([#429](https://github.com/Automattic/newspack-popups/issues/429)) ([52a0e23](https://github.com/Automattic/newspack-popups/commit/52a0e23e4676b3145280c79f995af9f4ffe0a269))
* retrieve existing WC orders data as donations ([#397](https://github.com/Automattic/newspack-popups/issues/397)) ([b2ab539](https://github.com/Automattic/newspack-popups/commit/b2ab5399d4a4d1c8db0f76141c42ee772f375030)), closes [#313](https://github.com/Automattic/newspack-popups/issues/313)
* segment priority UI and logic ([#416](https://github.com/Automattic/newspack-popups/issues/416)) ([31ffb38](https://github.com/Automattic/newspack-popups/commit/31ffb3888d8ea0e6a0cf86a1eb832f0b1d792ce6))
* specify group and segment in url for new campaigns ([#426](https://github.com/Automattic/newspack-popups/issues/426)) ([bcf4c3f](https://github.com/Automattic/newspack-popups/commit/bcf4c3f51896e663e5d84b0b908f365e28aa0e37))

## [1.23.1](https://github.com/Automattic/newspack-popups/compare/v1.23.0...v1.23.1) (2021-01-29)


### Bug Fixes

* insertion edge case bug ([6e783ed](https://github.com/Automattic/newspack-popups/commit/6e783ed45aeb35ac6089dd8724dcc807573e56f0))

# [1.23.0](https://github.com/Automattic/newspack-popups/compare/v1.22.2...v1.23.0) (2021-01-28)


### Features

* add donor landing page setting ([#408](https://github.com/Automattic/newspack-popups/issues/408)) ([a3be6ca](https://github.com/Automattic/newspack-popups/commit/a3be6caacf51f464c904aa42e8d6b61da17daf5e))
* **segmentation:** handle referrer exclusion segmentation ([#415](https://github.com/Automattic/newspack-popups/issues/415)) ([011e0eb](https://github.com/Automattic/newspack-popups/commit/011e0eb3fc629ed907bccd3da38eed9e99a42b41)), closes [#386](https://github.com/Automattic/newspack-popups/issues/386)

## [1.22.2](https://github.com/Automattic/newspack-popups/compare/v1.22.1...v1.22.2) (2021-01-22)


### Bug Fixes

* admin bar shown on front end logged out ([#411](https://github.com/Automattic/newspack-popups/issues/411)) ([e9c53fa](https://github.com/Automattic/newspack-popups/commit/e9c53fa1693cc936ca2ce2b202c8ae069905284e))

## [1.22.1](https://github.com/Automattic/newspack-popups/compare/v1.22.0...v1.22.1) (2021-01-21)


### Bug Fixes

* bug that causes admin bar to be hidden ([#409](https://github.com/Automattic/newspack-popups/issues/409)) ([4b67894](https://github.com/Automattic/newspack-popups/commit/4b67894f911cd2e7dc7ec4ecb967c696b32a1e20))

# [1.22.0](https://github.com/Automattic/newspack-popups/compare/v1.21.0...v1.22.0) (2021-01-21)


### Bug Fixes

* corrected logic for above header display ([#403](https://github.com/Automattic/newspack-popups/issues/403)) ([3ca5d71](https://github.com/Automattic/newspack-popups/commit/3ca5d7115f75f31d733f5498caac78d4acdc1040))
* handle missing GA id ([f27f84a](https://github.com/Automattic/newspack-popups/commit/f27f84a39785030481a7c07b5190555fb3e01f73))
* in preview tab, allow previewing without choosing groups ([#399](https://github.com/Automattic/newspack-popups/issues/399)) ([c9de7e9](https://github.com/Automattic/newspack-popups/commit/c9de7e9b1067f8ca70bafdfd3853ab26b72c88d8))
* install composer dependencies on release job ([#368](https://github.com/Automattic/newspack-popups/issues/368)) ([f814d27](https://github.com/Automattic/newspack-popups/commit/f814d270d701dec2997981b1cf1da3f9adf66d3b))
* remove dismiss button preview when empty ([#402](https://github.com/Automattic/newspack-popups/issues/402)) ([688d575](https://github.com/Automattic/newspack-popups/commit/688d57572dfec80300bf0906eff766b6c1f72010))
* remove inline class for above header campaigns ([#401](https://github.com/Automattic/newspack-popups/issues/401)) ([212f39e](https://github.com/Automattic/newspack-popups/commit/212f39ea47ca77cb707926010445a543df0e6f51))
* remove trailing question mark from cleaned non-AMP URLs ([#376](https://github.com/Automattic/newspack-popups/issues/376)) ([25363ae](https://github.com/Automattic/newspack-popups/commit/25363aed2e61d349bc8eb6831a303fb3a03d70e2))
* segmentation and frequency conflict ([#383](https://github.com/Automattic/newspack-popups/issues/383)) ([47a5af0](https://github.com/Automattic/newspack-popups/commit/47a5af09ffd941780fc6f55a2e54f4c45246299f)), closes [#379](https://github.com/Automattic/newspack-popups/issues/379)
* segmentation category affinity fixes ([16a6500](https://github.com/Automattic/newspack-popups/commit/16a65003a50b2e411dcb7995152ea941ac86d0e3))
* session read count in view-as logic ([#362](https://github.com/Automattic/newspack-popups/issues/362)) ([33b65c7](https://github.com/Automattic/newspack-popups/commit/33b65c7d6cf1d8a379246bfbbc4ce41fdde22554))
* view-as-segment should always take min count values ([#404](https://github.com/Automattic/newspack-popups/issues/404)) ([e69a97a](https://github.com/Automattic/newspack-popups/commit/e69a97a698f9c76552b5aa70d671fb0dc435ac67))


### Features

* deprecate test mode and never frequency ([#390](https://github.com/Automattic/newspack-popups/issues/390)) ([cc04314](https://github.com/Automattic/newspack-popups/commit/cc04314c8986734e658613ef1f5120be6bfa2012))
* handle category affinity segment ([fb01269](https://github.com/Automattic/newspack-popups/commit/fb012699d7e81d04fbb70b3888de205626f7e112))
* initialize campaigns by placement ([#374](https://github.com/Automattic/newspack-popups/issues/374)) ([5db8e97](https://github.com/Automattic/newspack-popups/commit/5db8e97f339ac7061b16c76939f35c12a46b71b0))
* manual placement ([#373](https://github.com/Automattic/newspack-popups/issues/373)) ([45b0c67](https://github.com/Automattic/newspack-popups/commit/45b0c677a52c76a3622305a59eb185e316be636d))
* support for campaign groups in campaigns wizard ([#363](https://github.com/Automattic/newspack-popups/issues/363)) ([fdb12db](https://github.com/Automattic/newspack-popups/commit/fdb12db88cc8c534d25c7a6db430864c866a209a))
* **segmentation:** add session read count handling ([b1a76cb](https://github.com/Automattic/newspack-popups/commit/b1a76cb83b4a24a2c0626d1a6810cd21865d2c02))
* **view-as:** handle groups ([#349](https://github.com/Automattic/newspack-popups/issues/349)) ([563f3ce](https://github.com/Automattic/newspack-popups/commit/563f3cef14959b5ad26fdd7c5d2f204752fe1443))
* shortcode ([#321](https://github.com/Automattic/newspack-popups/issues/321)) ([6da09cf](https://github.com/Automattic/newspack-popups/commit/6da09cfcc19b6275f3aa5f7f3e5321cc2f52d178))
* view-as feature ([#345](https://github.com/Automattic/newspack-popups/issues/345)) ([632bec7](https://github.com/Automattic/newspack-popups/commit/632bec7e882f9ed7b99f83d24e689cbad278e131))

# [1.21.0](https://github.com/Automattic/newspack-popups/compare/v1.20.0...v1.21.0) (2020-12-15)


### Bug Fixes

* editor colors ([69472f3](https://github.com/Automattic/newspack-popups/commit/69472f342c5a58e7e6064cd87361cdb7d73c3997))
* insert time-triggered overlay campaigns above header ([#297](https://github.com/Automattic/newspack-popups/issues/297)) ([d2c473b](https://github.com/Automattic/newspack-popups/commit/d2c473bc3f610953183e8abd319edbea1133dbad)), closes [#4](https://github.com/Automattic/newspack-popups/issues/4)


### Features

* **editor:** hide post visibility selector ([ce7aa98](https://github.com/Automattic/newspack-popups/commit/ce7aa98a409d7b45a9100d5d3faa75e7c7a7b51b))
* add above header placement option ([#292](https://github.com/Automattic/newspack-popups/issues/292)) ([06a863e](https://github.com/Automattic/newspack-popups/commit/06a863efcab8c60e46015d0354c34deca77f48c8)), closes [#260](https://github.com/Automattic/newspack-popups/issues/260)
* make campaign groups a hierarchical taxonomy ([#344](https://github.com/Automattic/newspack-popups/issues/344)) ([da4b26a](https://github.com/Automattic/newspack-popups/commit/da4b26ae3e4b62e03ed7c22cd067e1e6f00383b6))
* render a preview of the dismiss button in the editor ([#336](https://github.com/Automattic/newspack-popups/issues/336)) ([fa7dc05](https://github.com/Automattic/newspack-popups/commit/fa7dc05649f9fcbf66cc95b51cafc34acb1ad8df))

# [1.20.0](https://github.com/Automattic/newspack-popups/compare/v1.19.0...v1.20.0) (2020-12-08)


### Bug Fixes

* do create config file on the atomic platform ([14120a8](https://github.com/Automattic/newspack-popups/commit/14120a8d677dea5912cc0a6224d0de5f623aae89))


### Features

* move custom GA config endpoint to lightweight API ([326fb10](https://github.com/Automattic/newspack-popups/commit/326fb103d0eb9f05321c5598eac47ced378dcc34))

# [1.19.0](https://github.com/Automattic/newspack-popups/compare/v1.18.0...v1.19.0) (2020-12-02)


### Bug Fixes

* do not render popups with 'never' frequency ([f1c80f3](https://github.com/Automattic/newspack-popups/commit/f1c80f30da73f40750aec54ba80b143899f47ae7))


### Features

* report segmentation-related data as custom dimension to GA ([#325](https://github.com/Automattic/newspack-popups/issues/325)) ([648b70b](https://github.com/Automattic/newspack-popups/commit/648b70ba4155dc7f74b7485c3d5fbf98b2e2a482)), closes [#259](https://github.com/Automattic/newspack-popups/issues/259)
* **segmentation:** segment size computation ([#324](https://github.com/Automattic/newspack-popups/issues/324)) ([c152cd9](https://github.com/Automattic/newspack-popups/commit/c152cd9889140e6bfd6731e14ba8a279e84087bc))
* prune segmentation data ([c11c686](https://github.com/Automattic/newspack-popups/commit/c11c686ffd30940d042f354576ec3bc160d20db1)), closes [#251](https://github.com/Automattic/newspack-popups/issues/251)

# [1.19.0](https://github.com/Automattic/newspack-popups/compare/v1.18.0...v1.19.0) (2020-12-02)


### Bug Fixes

* do not render popups with 'never' frequency ([f1c80f3](https://github.com/Automattic/newspack-popups/commit/f1c80f30da73f40750aec54ba80b143899f47ae7))


### Features

* report segmentation-related data as custom dimension to GA ([#325](https://github.com/Automattic/newspack-popups/issues/325)) ([648b70b](https://github.com/Automattic/newspack-popups/commit/648b70ba4155dc7f74b7485c3d5fbf98b2e2a482)), closes [#259](https://github.com/Automattic/newspack-popups/issues/259)
* **segmentation:** segment size computation ([#324](https://github.com/Automattic/newspack-popups/issues/324)) ([c152cd9](https://github.com/Automattic/newspack-popups/commit/c152cd9889140e6bfd6731e14ba8a279e84087bc))
* prune segmentation data ([c11c686](https://github.com/Automattic/newspack-popups/commit/c11c686ffd30940d042f354576ec3bc160d20db1)), closes [#251](https://github.com/Automattic/newspack-popups/issues/251)

# [1.18.0](https://github.com/Automattic/newspack-popups/compare/v1.17.0...v1.18.0) (2020-11-24)


### Bug Fixes

* handle legacy config file path ([#317](https://github.com/Automattic/newspack-popups/issues/317)) ([7770f6a](https://github.com/Automattic/newspack-popups/commit/7770f6aec53e1a97bb43665e51e534a1b96d0626))


### Features

* Improve configuration file handling ([#315](https://github.com/Automattic/newspack-popups/issues/315)) ([be40334](https://github.com/Automattic/newspack-popups/commit/be403343f00963b9b754554f48fd19a06e068e96))
* polyfill amp-analytics script ([#306](https://github.com/Automattic/newspack-popups/issues/306)) ([6066651](https://github.com/Automattic/newspack-popups/commit/6066651d75642967be52c0f6261c64fc49f68408)), closes [#193](https://github.com/Automattic/newspack-popups/issues/193)
* reorganize editor sidebar and add notice when test mode is enabled ([#307](https://github.com/Automattic/newspack-popups/issues/307)) ([59e522c](https://github.com/Automattic/newspack-popups/commit/59e522c9a241da4d2b2e3a202af5fa52b81ba8a2)), closes [#310](https://github.com/Automattic/newspack-popups/issues/310)
* retrieve client email and donor status from Mailchimp ([#305](https://github.com/Automattic/newspack-popups/issues/305)) ([4ffc44d](https://github.com/Automattic/newspack-popups/commit/4ffc44d112e4daf7b2a911a68fc0ccc2228bdd17)), closes [#304](https://github.com/Automattic/newspack-popups/issues/304)

# [1.17.0](https://github.com/Automattic/newspack-popups/compare/v1.16.0...v1.17.0) (2020-11-11)


### Bug Fixes

* do not enqueue scripts if post has Campaigns disabled ([af32c62](https://github.com/Automattic/newspack-popups/commit/af32c623ec32e2cf27f831b615f14d980061b053))
* handle MC4WP forms when assessing if campaign has newsletter form ([74cfbae](https://github.com/Automattic/newspack-popups/commit/74cfbaee27aa6e67a9ba523dc33123f2d39f1d81))
* inject campaigns into posts and pages only ([#296](https://github.com/Automattic/newspack-popups/issues/296)) ([c162379](https://github.com/Automattic/newspack-popups/commit/c162379826ebaf799883f2db370d5f18af72405a))


### Features

* handle posts read count segmentation ([#289](https://github.com/Automattic/newspack-popups/issues/289)) ([c6024d2](https://github.com/Automattic/newspack-popups/commit/c6024d23ad02f5b874f3fe43982c6b5dcb199daf)), closes [#271](https://github.com/Automattic/newspack-popups/issues/271)
* handle subscription, donation segmentation ([5fb405d](https://github.com/Automattic/newspack-popups/commit/5fb405dbd0326bef4c4c55902878dafe184ffae0)), closes [#249](https://github.com/Automattic/newspack-popups/issues/249) [#250](https://github.com/Automattic/newspack-popups/issues/250)
* improve preview post ([#291](https://github.com/Automattic/newspack-popups/issues/291)) ([f7a5ba1](https://github.com/Automattic/newspack-popups/commit/f7a5ba1be80d25d6af8724b2de7e5e553515341f))
* settings interop ([#206](https://github.com/Automattic/newspack-popups/issues/206)) ([466db2a](https://github.com/Automattic/newspack-popups/commit/466db2a21d758b378c0d510996f99959e0973910))

# [1.16.0](https://github.com/Automattic/newspack-popups/compare/v1.15.1...v1.16.0) (2020-10-29)


### Bug Fixes

* allow only one overlay campaign per page ([#286](https://github.com/Automattic/newspack-popups/issues/286)) ([231d862](https://github.com/Automattic/newspack-popups/commit/231d862554a64c895b4af7c3238d8da5034c4199))


### Features

* handle non-amp amp-analytics submission event ([#283](https://github.com/Automattic/newspack-popups/issues/283)) ([44e7587](https://github.com/Automattic/newspack-popups/commit/44e7587143ac9389df6d627acbaa37729fe802dc)), closes [#200](https://github.com/Automattic/newspack-popups/issues/200) [#257](https://github.com/Automattic/newspack-popups/issues/257)
* suppression donation campaigns for donors ([1f53b60](https://github.com/Automattic/newspack-popups/commit/1f53b60a94f7d5be095d5212a98c184de60ada5f)), closes [#141](https://github.com/Automattic/newspack-popups/issues/141)

## [1.15.1](https://github.com/Automattic/newspack-popups/compare/v1.15.0...v1.15.1) (2020-10-28)


### Bug Fixes

* add posts_read array to legacy client data ([#281](https://github.com/Automattic/newspack-popups/issues/281)) ([126a5f3](https://github.com/Automattic/newspack-popups/commit/126a5f330f133dc6446dda427b36851f054e726f))

# [1.15.0](https://github.com/Automattic/newspack-popups/compare/v1.14.0...v1.15.0) (2020-10-27)


### Bug Fixes

* handle no WooCommerce installed ([d6b7b7c](https://github.com/Automattic/newspack-popups/commit/d6b7b7c1dd24f822c872f8d2a2212d97c02d7c96))
* non-post visits; prevent overwriting client data ([#276](https://github.com/Automattic/newspack-popups/issues/276)) ([6f13857](https://github.com/Automattic/newspack-popups/commit/6f1385738554adf62d634e20ee9dff7a9a29f771))
* **editor:** disable "Every page" frequency for overlay campaigns ([#268](https://github.com/Automattic/newspack-popups/issues/268)) ([700c07e](https://github.com/Automattic/newspack-popups/commit/700c07e97e23bd2671362119f29ce7118b1bceac)), closes [#105](https://github.com/Automattic/newspack-popups/issues/105)
* allow inline campaigns to appear before first block ([23b86ce](https://github.com/Automattic/newspack-popups/commit/23b86ce702108ebd8434b6f0e8734655971ad778)), closes [#209](https://github.com/Automattic/newspack-popups/issues/209)
* setting sitewide default ([8e3037f](https://github.com/Automattic/newspack-popups/commit/8e3037f7283154d0c98dee71428e8b577b576e11)), closes [#72](https://github.com/Automattic/newspack-popups/issues/72)
* testing of overlay popups ([79f9f0d](https://github.com/Automattic/newspack-popups/commit/79f9f0d29a77cd28b91ae2577069d7ae28c89c8d)), closes [#62](https://github.com/Automattic/newspack-popups/issues/62)


### Features

* **segmentation:** save WC donation data ([#269](https://github.com/Automattic/newspack-popups/issues/269)) ([3cc48d4](https://github.com/Automattic/newspack-popups/commit/3cc48d4f4ff5e23dbac6d36690a37b128e2ac5c9))
* disable native preview button ([2dd545b](https://github.com/Automattic/newspack-popups/commit/2dd545b28f29df9dcc1f6cf798f9a87cfdabdfcc)), closes [#130](https://github.com/Automattic/newspack-popups/issues/130)
* handle MC4WP; store email subscriptions as an array ([#258](https://github.com/Automattic/newspack-popups/issues/258)) ([baf4426](https://github.com/Automattic/newspack-popups/commit/baf44263f7f170ee7585c83c5572d0a3aaafa011))
* simplify previewing logic ([#266](https://github.com/Automattic/newspack-popups/issues/266)) ([97b17fe](https://github.com/Automattic/newspack-popups/commit/97b17fe62d49e1f4df4b245a871d26e0cba0d2ff)), closes [#110](https://github.com/Automattic/newspack-popups/issues/110)
* tag filtering ([#267](https://github.com/Automattic/newspack-popups/issues/267)) ([cf2b546](https://github.com/Automattic/newspack-popups/commit/cf2b54622b1a29a62876d5e289b502181bcc4aa0)), closes [#109](https://github.com/Automattic/newspack-popups/issues/109)
* use cacheing for client data ([b5c4cba](https://github.com/Automattic/newspack-popups/commit/b5c4cba7aeadc5899071955dfcc8cbefb46804bc))

# [1.14.0](https://github.com/Automattic/newspack-popups/compare/v1.13.0...v1.14.0) (2020-10-20)


### Bug Fixes

* inline markup ([#254](https://github.com/Automattic/newspack-popups/issues/254)) ([bc26e7b](https://github.com/Automattic/newspack-popups/commit/bc26e7b0d57d10bf8027c7cad740333e305b1af7)), closes [#246](https://github.com/Automattic/newspack-popups/issues/246)
* time-triggered popups ([#242](https://github.com/Automattic/newspack-popups/issues/242)) ([9bee014](https://github.com/Automattic/newspack-popups/commit/9bee0149c335b05ba2f1e544b357e1311e56ee25)), closes [#241](https://github.com/Automattic/newspack-popups/issues/241) [#247](https://github.com/Automattic/newspack-popups/issues/247)


### Features

* non-interactive mode ([#253](https://github.com/Automattic/newspack-popups/issues/253)) ([36774f8](https://github.com/Automattic/newspack-popups/commit/36774f830436300b1a62ab4ed76a114d84fc2c15)), closes [#248](https://github.com/Automattic/newspack-popups/issues/248)

# [1.13.0](https://github.com/Automattic/newspack-popups/compare/v1.12.2...v1.13.0) (2020-10-07)


### Features

* segmentation data collection ([ff5ffdc](https://github.com/Automattic/newspack-popups/commit/ff5ffdca2969418fa58a4dd59df8dabd1381b2cd)), closes [#233](https://github.com/Automattic/newspack-popups/issues/233)

## [1.12.2](https://github.com/Automattic/newspack-popups/compare/v1.12.1...v1.12.2) (2020-09-30)


### Bug Fixes

* scroll-triggered popups on non-AMP pages ([6b55e13](https://github.com/Automattic/newspack-popups/commit/6b55e1360aeb0c3d9896917cf10c39904ee29fac))

## [1.12.1](https://github.com/Automattic/newspack-popups/compare/v1.12.0...v1.12.1) (2020-09-29)


### Bug Fixes

* scroll-triggered campaigns ([#236](https://github.com/Automattic/newspack-popups/issues/236)) ([79b654a](https://github.com/Automattic/newspack-popups/commit/79b654a5b83afb59dca8bf80b81e135a6e1d5fc2))
* scroll-triggered popups ([#232](https://github.com/Automattic/newspack-popups/issues/232)) ([cff67c6](https://github.com/Automattic/newspack-popups/commit/cff67c6db04a7aafb9c092fe18f8ec499e93e0be)), closes [#217](https://github.com/Automattic/newspack-popups/issues/217) [#231](https://github.com/Automattic/newspack-popups/issues/231)

# [1.12.0](https://github.com/Automattic/newspack-popups/compare/v1.11.0...v1.12.0) (2020-09-22)


### Bug Fixes

* **api:** config creation ([#228](https://github.com/Automattic/newspack-popups/issues/228)) ([d06daa5](https://github.com/Automattic/newspack-popups/commit/d06daa5af2e2a076b5925f7de69f673253cb0ffe))
* prevent a test category popup from taking the place of a sitewide default ([c610ad0](https://github.com/Automattic/newspack-popups/commit/c610ad0ce9dbefe79fa8a6f52e6400b2d1fd71e6)), closes [#219](https://github.com/Automattic/newspack-popups/issues/219)
* reporting view on overlay campaigns ([#221](https://github.com/Automattic/newspack-popups/issues/221)) ([6f63f5b](https://github.com/Automattic/newspack-popups/commit/6f63f5ba8ff645e573540526f6f075198b6af287)), closes [#220](https://github.com/Automattic/newspack-popups/issues/220)
* **api:** enable drafts handling for setting options and categories ([4828355](https://github.com/Automattic/newspack-popups/commit/48283550208901658c8e6dd2c53e972294a75bc4))


### Features

* **api:** allow requests from AMP Cache ([#226](https://github.com/Automattic/newspack-popups/issues/226)) ([e0f7aaf](https://github.com/Automattic/newspack-popups/commit/e0f7aaff79aac68cf2db65e64c441211fb1a7154))
* create config if not exists ([#212](https://github.com/Automattic/newspack-popups/issues/212)) ([0bdc4a3](https://github.com/Automattic/newspack-popups/commit/0bdc4a3d3ef1291e0309ca98c5d9aaa4b1d98ea2))
* use single request for amp-access; lightweight POST endpoint ([#213](https://github.com/Automattic/newspack-popups/issues/213)) ([e7c3356](https://github.com/Automattic/newspack-popups/commit/e7c3356a75d19f3c163319e14d2380ebfa17028f))

# [1.11.0](https://github.com/Automattic/newspack-popups/compare/v1.10.0...v1.11.0) (2020-09-15)


### Bug Fixes

* handle false get_transient return value ([#211](https://github.com/Automattic/newspack-popups/issues/211)) ([11c9f3b](https://github.com/Automattic/newspack-popups/commit/11c9f3b17bcc125e9a87dfce942f03194e9c5657))


### Features

* lightweight GET API for campaigns ([#208](https://github.com/Automattic/newspack-popups/issues/208)) ([3bae873](https://github.com/Automattic/newspack-popups/commit/3bae8733a17e58db779c595ea2c2c82c9beb92f4))

# [1.10.0](https://github.com/Automattic/newspack-popups/compare/v1.9.4...v1.10.0) (2020-08-26)


### Features

* add interoperation features ([#201](https://github.com/Automattic/newspack-popups/issues/201)) ([7b5e941](https://github.com/Automattic/newspack-popups/commit/7b5e941975b63cc06b6a962063ad742049b11cf1))

## [1.9.4](https://github.com/Automattic/newspack-popups/compare/v1.9.3...v1.9.4) (2020-08-18)


### Bug Fixes

* dismissal events as non-interaction ([164ce5f](https://github.com/Automattic/newspack-popups/commit/164ce5fb91900c670a5ced2f632d7449d11538e0))

## [1.9.3](https://github.com/Automattic/newspack-popups/compare/v1.9.2...v1.9.3) (2020-08-11)


### Bug Fixes

* add permission_callback to REST route defn ([2626bb6](https://github.com/Automattic/newspack-popups/commit/2626bb6c70592fac741f0723a85f221c47782673))

## [1.9.2](https://github.com/Automattic/newspack-popups/compare/v1.9.1...v1.9.2) (2020-07-22)


### Bug Fixes

* a typo in one of the campaign settings options ([7ddd1c8](https://github.com/Automattic/newspack-popups/commit/7ddd1c8976c4583a44f7af6c984708dc8c83b57c))
* js error on non-AMP pages ([81e01fc](https://github.com/Automattic/newspack-popups/commit/81e01fceec66fa3d83c0d6b4559bc85ffb5b45f9))

## [1.9.1](https://github.com/Automattic/newspack-popups/compare/v1.9.0...v1.9.1) (2020-07-15)


### Bug Fixes

* utm paramers suppression conflict ([b2c577c](https://github.com/Automattic/newspack-popups/commit/b2c577c71fd910c3be6e7c0e621de34fcceaaaa2))

# [1.9.0](https://github.com/Automattic/newspack-popups/compare/v1.8.0...v1.9.0) (2020-07-14)


### Bug Fixes

* decode URL when checking utm_source suppression; fix transient ([#178](https://github.com/Automattic/newspack-popups/issues/178)) ([cd55311](https://github.com/Automattic/newspack-popups/commit/cd553112312c9f8974354193a4e025f0969848a1)), closes [#177](https://github.com/Automattic/newspack-popups/issues/177)
* **settings:** styling ([2c77bd4](https://github.com/Automattic/newspack-popups/commit/2c77bd4a03f369d2337533de249b890f50a111ce))


### Features

* hide non-test campagins for logged-in users ([#169](https://github.com/Automattic/newspack-popups/issues/169)) ([476e5c0](https://github.com/Automattic/newspack-popups/commit/476e5c030b10417d45bc3abc33925681669a2d24))
* **settings:** suppress all newsletter campaigns if one was dismissed ([#175](https://github.com/Automattic/newspack-popups/issues/175)) ([ed91a73](https://github.com/Automattic/newspack-popups/commit/ed91a7357ac94a0c82c3beda622230d99e2f8fd9))
* add option to suppress newsletter campaigns if visiting from email ([e1371f5](https://github.com/Automattic/newspack-popups/commit/e1371f58dee5331210009207ab59cfd5e2eee959))

# [1.8.0](https://github.com/Automattic/newspack-popups/compare/v1.7.2...v1.8.0) (2020-07-09)


### Bug Fixes

* make popupseen non-interactive event ([4278a17](https://github.com/Automattic/newspack-popups/commit/4278a17b948958b8f07ea3214c681fa449fb4bd9))


### Features

* mark load event as non interaction ([9abff5d](https://github.com/Automattic/newspack-popups/commit/9abff5d35666134e66b54cc81b33bfbccf86c2f2))

## [1.7.2](https://github.com/Automattic/newspack-popups/compare/v1.7.1...v1.7.2) (2020-07-07)


### Bug Fixes

* duplicate execution of the_content filter ([2692867](https://github.com/Automattic/newspack-popups/commit/26928678c00fb9993785ef049154579382387b1d))

## [1.7.1](https://github.com/Automattic/newspack-popups/compare/v1.7.0...v1.7.1) (2020-06-30)


### Bug Fixes

* ad insertion in overlay campaigns ([d036de1](https://github.com/Automattic/newspack-popups/commit/d036de19d81fd8f03b0a15263f0905258214852d)), closes [#158](https://github.com/Automattic/newspack-popups/issues/158)

# [1.7.0](https://github.com/Automattic/newspack-popups/compare/v1.6.0...v1.7.0) (2020-06-23)


### Bug Fixes

* insert all campaigns into content in one pass ([#150](https://github.com/Automattic/newspack-popups/issues/150)) ([3c30b8e](https://github.com/Automattic/newspack-popups/commit/3c30b8e9a4907ba08ab315538eed35f2990d2cde))
* non-amp form handling for inline campaigns ([#154](https://github.com/Automattic/newspack-popups/issues/154)) ([12b27b4](https://github.com/Automattic/newspack-popups/commit/12b27b48bf57e067c3372aa7c4b44652adbabe78))


### Features

* **analytics:** use newspack-plugin's filter where possible ([#155](https://github.com/Automattic/newspack-popups/issues/155)) ([0ee60bc](https://github.com/Automattic/newspack-popups/commit/0ee60bccbc1c7548927b4f44db1b0a884837c609))
* add class names for newsletter prompts, for analytics tracking ([9ca1f83](https://github.com/Automattic/newspack-popups/commit/9ca1f83c66755e868d537a95da65786ce405ffbe))

# [1.6.0](https://github.com/Automattic/newspack-popups/compare/v1.5.3...v1.6.0) (2020-06-18)


### Bug Fixes

* namespace use in register_rest_route ([5c69bd7](https://github.com/Automattic/newspack-popups/commit/5c69bd76f6f57661b6cdebb40889549cbae24635))
* related posts in campaigns ([#144](https://github.com/Automattic/newspack-popups/issues/144)) ([caef323](https://github.com/Automattic/newspack-popups/commit/caef323482cdea8e100681ddd028aa45dfc6ab45))


### Features

* clean up release zip ([bafe05e](https://github.com/Automattic/newspack-popups/commit/bafe05ed3dea557fcf10cf4b7d119ed564ce4766))

## [1.5.3](https://github.com/Automattic/newspack-popups/compare/v1.5.2...v1.5.3) (2020-06-09)


### Bug Fixes

* handle default background and overlay colors in the editor ([e51c5d8](https://github.com/Automattic/newspack-popups/commit/e51c5d840aa47b13020f2a1794c73b6218acddac)), closes [#56](https://github.com/Automattic/newspack-popups/issues/56)
* remove amp-form in non-amp requests ([#127](https://github.com/Automattic/newspack-popups/issues/127)) ([77c2f2f](https://github.com/Automattic/newspack-popups/commit/77c2f2fa656811c1edd4701011b9ff2ff3c70a5a))

## [1.5.2](https://github.com/Automattic/newspack-popups/compare/v1.5.1...v1.5.2) (2020-05-15)


### Bug Fixes

* **insertion:** fix insertion of overlay scroll-triggered popups ([6dc4427](https://github.com/Automattic/newspack-popups/commit/6dc4427e40d2722277d91ee1e11dd92c1fd7dd57)), closes [#124](https://github.com/Automattic/newspack-popups/issues/124)
* dont load popups on products because of amp-form ([8eb5ebc](https://github.com/Automattic/newspack-popups/commit/8eb5ebcc643df4d40f800af2402e70c906becb3d))

## [1.5.1](https://github.com/Automattic/newspack-popups/compare/v1.5.0...v1.5.1) (2020-05-12)


### Bug Fixes

* popup insertion logic ([#94](https://github.com/Automattic/newspack-popups/issues/94)) ([79ef273](https://github.com/Automattic/newspack-popups/commit/79ef2730d5930183b98c2f68d1dfa21bb32cf5a2)), closes [#92](https://github.com/Automattic/newspack-popups/issues/92)

# [1.5.0](https://github.com/Automattic/newspack-popups/compare/v1.4.1...v1.5.0) (2020-05-07)


### Bug Fixes

* fix couple issues with popup utm suppression ([c4f5a1b](https://github.com/Automattic/newspack-popups/commit/c4f5a1ba0ecb62c03bf3ef27c49954302ff0a1fe))
* resolve issue causing admin bar to display incorrectly ([#116](https://github.com/Automattic/newspack-popups/issues/116)) ([97270ea](https://github.com/Automattic/newspack-popups/commit/97270ea4fb2e1952bc9fcc502370efdd057c6ebc))


### Features

* convenience method for updating pop-up options ([#112](https://github.com/Automattic/newspack-popups/issues/112)) ([4f14604](https://github.com/Automattic/newspack-popups/commit/4f146041067aed7f50033fa2849040dd42ae1ada))
* support for draft popups ([c7c7fe5](https://github.com/Automattic/newspack-popups/commit/c7c7fe5e7cd7bdc27bcd0db60eba32b1611ada45))

## [1.4.1](https://github.com/Automattic/newspack-popups/compare/v1.4.0...v1.4.1) (2020-04-29)


### Bug Fixes

* prevent infinite loop with inline popups and paywalled content ([83633ee](https://github.com/Automattic/newspack-popups/commit/83633ee6ab04b5c773bfe482848688d5d803010a))

# [1.4.0](https://github.com/Automattic/newspack-popups/compare/v1.3.1...v1.4.0) (2020-04-24)


### Bug Fixes

* check if meta exists ([37786da](https://github.com/Automattic/newspack-popups/commit/37786daa4ce92d1b82b3d5938270d82c140ea719))


### Features

* add as filter ([9f74644](https://github.com/Automattic/newspack-popups/commit/9f74644494cc98813e6429e5c9b6333c0f059725))
* add assessment in insert_popups_amp_access ([09c9d5d](https://github.com/Automattic/newspack-popups/commit/09c9d5dcc464765c05003064f36e09cdc3d8b6a1))
* change settings UI ([e5bef8f](https://github.com/Automattic/newspack-popups/commit/e5bef8f67a2b6485648ddc024942accbc738ae71))
* enable disabling popups for specific posts and pages ([2cdaf31](https://github.com/Automattic/newspack-popups/commit/2cdaf316755e3f7f4e76650b8863b7026523d20b))
* enqueue script for posts and pages only ([4d15744](https://github.com/Automattic/newspack-popups/commit/4d1574409e2251afee3703ebce848a7cc5b6340a))

## [1.3.1](https://github.com/Automattic/newspack-popups/compare/v1.3.0...v1.3.1) (2020-04-22)


### Bug Fixes

* **amp:** disable popups on non-AMP pages with POST form elements ([366407d](https://github.com/Automattic/newspack-popups/commit/366407d4cebaede14c7bb10d0e3a8509fd86ab15))

# [1.3.0](https://github.com/Automattic/newspack-popups/compare/v1.2.0...v1.3.0) (2020-04-21)


### Bug Fixes

* dont count pageviews when initializing popup analytics ([969d3b6](https://github.com/Automattic/newspack-popups/commit/969d3b6c4b1e7d23ea49a02d25f49bfbbef61f85))
* prevent reporting analytics data for popup previews ([5eaec91](https://github.com/Automattic/newspack-popups/commit/5eaec9147eac9f3a5cc12c62ee3f05ddff061de7))


### Features

* add class to pop-up if display title is enabled ([#98](https://github.com/Automattic/newspack-popups/issues/98)) ([ee37d73](https://github.com/Automattic/newspack-popups/commit/ee37d7379c92fd0b40d5ca6bec2505940c673e73))

# [1.2.0](https://github.com/Automattic/newspack-popups/compare/v1.1.1...v1.2.0) (2020-04-01)


### Bug Fixes

* correct query reset that can reset the loop ([#91](https://github.com/Automattic/newspack-popups/issues/91)) ([66b6389](https://github.com/Automattic/newspack-popups/commit/66b6389b1e8d4478395c18fa0df9f0ca0f364e83))


### Features

* add style to subscribe pattern 1 ([#93](https://github.com/Automattic/newspack-popups/issues/93)) ([98ddaa1](https://github.com/Automattic/newspack-popups/commit/98ddaa1c8d984045302134dfc099e1baa3ae0723))
* handle MC4WP forms submissions ([95c8073](https://github.com/Automattic/newspack-popups/commit/95c807363a063759acc9ef96fffda196c7e30adc))
* handle multiple inline popups ([#89](https://github.com/Automattic/newspack-popups/issues/89)) ([6c303ca](https://github.com/Automattic/newspack-popups/commit/6c303ca02f047e870b10d3b5a32f344c49fbdcf8))

## [1.1.1](https://github.com/Automattic/newspack-popups/compare/v1.1.0...v1.1.1) (2020-03-24)


### Bug Fixes

* clear floats when using inline popup ([#87](https://github.com/Automattic/newspack-popups/issues/87)) ([6e7991f](https://github.com/Automattic/newspack-popups/commit/6e7991faf44d81b9db61d008e108a6a295dfaeb6))
* resolve inline popup collisions with super cool ad inserter content ([#86](https://github.com/Automattic/newspack-popups/issues/86)) ([2fbd806](https://github.com/Automattic/newspack-popups/commit/2fbd806b916d294c3557bc2325bab1465fc64928))
* **analytics:** handle non-AMP pages and Mailchimp ([#83](https://github.com/Automattic/newspack-popups/issues/83)) ([d53bb02](https://github.com/Automattic/newspack-popups/commit/d53bb02e5ada8188382e58bd97859f8496457e0d))
