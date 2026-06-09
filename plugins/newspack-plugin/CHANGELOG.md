## newspack [6.42.4](https://github.com/Automattic/newspack-workspace/compare/newspack@6.42.3...newspack@6.42.4) (2026-06-04)


### Bug Fixes

* **integrations:** skip unconfigured + dedupe health-check alerts ([#207](https://github.com/Automattic/newspack-workspace/issues/207)) ([63eebb9](https://github.com/Automattic/newspack-workspace/commit/63eebb9a30b54f3c265db4be3584e69218239441))

## newspack [6.42.3](https://github.com/Automattic/newspack-workspace/compare/newspack@6.42.2...newspack@6.42.3) (2026-06-04)


### Bug Fixes

* **jetpack:** disable Newsletter (subscriptions) module ([#208](https://github.com/Automattic/newspack-workspace/issues/208)) ([7d56e9d](https://github.com/Automattic/newspack-workspace/commit/7d56e9d92988bf95640fb6924b7a9eb1c66c71d0))

## newspack [6.42.2](https://github.com/Automattic/newspack-workspace/compare/newspack@6.42.1...newspack@6.42.2) (2026-06-03)


### Bug Fixes

* **group-subscription:** update params; avoid secondary My Account redirect ([#151](https://github.com/Automattic/newspack-workspace/issues/151)) ([c5f6249](https://github.com/Automattic/newspack-workspace/commit/c5f6249ac76a717ff0def63081f45d490c560b19))

## newspack [6.42.1](https://github.com/Automattic/newspack-workspace/compare/newspack@6.42.0...newspack@6.42.1) (2026-06-03)


### Bug Fixes

* add contrasting colour to the my account mobile menu button ([#164](https://github.com/Automattic/newspack-workspace/issues/164)) ([94b765c](https://github.com/Automattic/newspack-workspace/commit/94b765ca602aad63df2b12edd15c3208ed3d1e70))

# newspack [6.42.0](https://github.com/Automattic/newspack-workspace/compare/newspack@6.41.3...newspack@6.42.0) (2026-06-01)


### Bug Fixes

* prevent content-gate editor.scss styles from getting chunked into the common.css file ([#4716](https://github.com/Automattic/newspack-plugin/issues/4716)) ([4d4c557](https://github.com/Automattic/newspack-plugin/commit/4d4c5572fb32a73b54c76e916273acc695f00163))

# [6.42.0](https://github.com/Automattic/newspack-plugin/compare/v6.41.3...v6.42.0) (2026-06-01)


### Features

* **wc-subscriptions:** recover switch proration when no amount paid ([#4745](https://github.com/Automattic/newspack-plugin/issues/4745)) ([c21009d](https://github.com/Automattic/newspack-plugin/commit/c21009d310a6385800c0ac44514565d048abf87c))

## [6.41.3](https://github.com/Automattic/newspack-plugin/compare/v6.41.2...v6.41.3) (2026-05-25)


### Bug Fixes

* **editor:** exclude Customizer Additional CSS from iframed editor ([#4750](https://github.com/Automattic/newspack-plugin/issues/4750)) ([3aaf8f6](https://github.com/Automattic/newspack-plugin/commit/3aaf8f627974a282b7c74ef29d65b94af005d1e2))

## [6.41.2](https://github.com/Automattic/newspack-plugin/compare/v6.41.1...v6.41.2) (2026-05-21)


### Bug Fixes

* **reader-activation:** Content Gate metadata for legacy ESP sync ([#4742](https://github.com/Automattic/newspack-plugin/issues/4742)) ([28f543a](https://github.com/Automattic/newspack-plugin/commit/28f543ab8571c74793dfa32df10113cfef508498))

## [6.41.1](https://github.com/Automattic/newspack-plugin/compare/v6.41.0...v6.41.1) (2026-05-21)


### Bug Fixes

* **access-control:** increase expiration of IP access cookie to 1 year ([#4749](https://github.com/Automattic/newspack-plugin/issues/4749)) ([d8e5b64](https://github.com/Automattic/newspack-plugin/commit/d8e5b64c246e23b9ea04f82af237c3f404d2c662))

# [6.41.0](https://github.com/Automattic/newspack-plugin/compare/v6.40.1...v6.41.0) (2026-05-21)


### Features

* **access-control:** add group subscription identifier to metadata ([#4746](https://github.com/Automattic/newspack-plugin/issues/4746)) ([d201b9a](https://github.com/Automattic/newspack-plugin/commit/d201b9af6f57c3f736dd50f733817e53b2f8a524)), closes [#4697](https://github.com/Automattic/newspack-plugin/issues/4697) [#4697](https://github.com/Automattic/newspack-plugin/issues/4697) [#8217](https://github.com/Automattic/newspack-plugin/issues/8217) [#4697](https://github.com/Automattic/newspack-plugin/issues/4697)

## [6.40.1](https://github.com/Automattic/newspack-plugin/compare/v6.40.0...v6.40.1) (2026-05-18)


### Bug Fixes

* **woocommerce:** persist custom meta on new product first save ([#4715](https://github.com/Automattic/newspack-plugin/issues/4715)) ([3a4e756](https://github.com/Automattic/newspack-plugin/commit/3a4e756fa39f9bd39f861428d99e97a2003b068e))

# [6.40.0](https://github.com/Automattic/newspack-plugin/compare/v6.39.4...v6.40.0) (2026-05-18)


### Bug Fixes

* **access-control:** allow `0` member limit values to be shown ([#4670](https://github.com/Automattic/newspack-plugin/issues/4670)) ([b7ea1e3](https://github.com/Automattic/newspack-plugin/commit/b7ea1e3df82223835996c5e1b3bd78fe569d7615))
* **access-control:** allow access rules with `supports_anonymous` to evaluate anonymous readers ([#4695](https://github.com/Automattic/newspack-plugin/issues/4695)) ([3219d63](https://github.com/Automattic/newspack-plugin/commit/3219d636dd936836b35fbec0fa9f4ea81cf209c1))
* **access-control:** bump content rule suggestion limit to 100 ([#4669](https://github.com/Automattic/newspack-plugin/issues/4669)) ([64629eb](https://github.com/Automattic/newspack-plugin/commit/64629eb3f2ae0cb6499e0cd4564ddcc536494b11))
* **access-control:** ignore content rules with empty value ([#4675](https://github.com/Automattic/newspack-plugin/issues/4675)) ([c01e97d](https://github.com/Automattic/newspack-plugin/commit/c01e97d0cd264be685140d27bee30492da494e70))
* add `use` statements for clarity ([b0074c0](https://github.com/Automattic/newspack-plugin/commit/b0074c0e8183d13e000b5624dd7389d8911bf0c9))
* add 30s timeout to v2 invisible token acquisition to prevent hang ([8fd1a17](https://github.com/Automattic/newspack-plugin/commit/8fd1a1730c4e2ec5534b07fa192995dd41a032a5))
* add isolated flag to v2 invisible widget to prevent interference ([9cfcc30](https://github.com/Automattic/newspack-plugin/commit/9cfcc309cafa5ba975e166523421bf0652d25e20))
* address potential race condition on multiple registrations with same email ([4fbb990](https://github.com/Automattic/newspack-plugin/commit/4fbb990f1c021833015832d7dc77ca42c3502205))
* **advertising:** toggle spacing under category autocomplete ([1c65593](https://github.com/Automattic/newspack-plugin/commit/1c65593f003b44dddedf1494ff8776fca2b80f92))
* **block-theme:** ensure Overlay Menu block panel opens in editor after template part switch ([#4642](https://github.com/Automattic/newspack-plugin/issues/4642)) ([e45cfd0](https://github.com/Automattic/newspack-plugin/commit/e45cfd09c689f03dcad7e5f8a9967808bd12f7aa))
* **campaigns:** remove wrapper div around add campaign modal input ([#4705](https://github.com/Automattic/newspack-plugin/issues/4705)) ([e04d4af](https://github.com/Automattic/newspack-plugin/commit/e04d4aff350833f6a0fd71afe18292bbd35ee7c6))
* centralize, normalize definition of `$referer` ([e38a94f](https://github.com/Automattic/newspack-plugin/commit/e38a94f357348201108bef32f7de09a3cd2a7e47))
* condition config output on RAS ([22c87af](https://github.com/Automattic/newspack-plugin/commit/22c87afd60656923a6615b1f67b7d52f00ec844a))
* condition reCAPTCHA v3 actions on their `ready()` ([a70d5ff](https://github.com/Automattic/newspack-plugin/commit/a70d5ff94250e7ff2208a46cc8b06d5eb43107d0))
* **content-gate:** pass redirectToLayout explicitly on Save ([#4702](https://github.com/Automattic/newspack-plugin/issues/4702)) ([a95cd4d](https://github.com/Automattic/newspack-plugin/commit/a95cd4d136c93bc3ba639a8095ef8f5266c869d2))
* ensure idempotency by making sure callers get current reader data for logged-in readers ([be59918](https://github.com/Automattic/newspack-plugin/commit/be599187a2247d4381d8df9491647d5e01b1e603))
* gracefully reject calls if essential config is missing ([dd4c46b](https://github.com/Automattic/newspack-plugin/commit/dd4c46b88ba54222690ad133c17cbb97e4ddaf8e))
* **indesign:** exclude rich media blocks from export output ([#4614](https://github.com/Automattic/newspack-plugin/issues/4614)) ([6e45232](https://github.com/Automattic/newspack-plugin/commit/6e452325d1668c4b6c02cf3a8b14b262d1d1bca6))
* make endpoint available only when RAS is enabled, per Copilot ([18de923](https://github.com/Automattic/newspack-plugin/commit/18de923b96ca6eb10c041e235c4e6cc474e3651b))
* **memberships:** prevent duplicate teams on stripped-meta renewals ([#4661](https://github.com/Automattic/newspack-plugin/issues/4661)) ([84f6c99](https://github.com/Automattic/newspack-plugin/commit/84f6c995bcc96427bf29b53f7a0975c2377ab2cc))
* merge into existing `reader` now that we are out of POC ([3200cc0](https://github.com/Automattic/newspack-plugin/commit/3200cc09c2173d3abd62df4c0374b22594c66217))
* move grecaptcha.execute inside try block to prevent Promise leak ([f224df8](https://github.com/Automattic/newspack-plugin/commit/f224df807aba6f7ee994218a28bc2949624a4ed9))
* move reCAPTCHA behind rate limiting to protect metered service from floods ([0ec69a2](https://github.com/Automattic/newspack-plugin/commit/0ec69a2ca39d9a18bc6117dbd8332c1800134655))
* **recaptcha:** skip script registration on TEC Community Events pages ([#4666](https://github.com/Automattic/newspack-plugin/issues/4666)) ([d9a57c5](https://github.com/Automattic/newspack-plugin/commit/d9a57c5da4c36dd8a69476d321e61ed1738ebec6))
* reject Promise and provide helpful error if reCAPTCHA not happy ([099a109](https://github.com/Automattic/newspack-plugin/commit/099a1098effbcff8faf75b8f6c3d3b170eaf20d2))
* send nonce, if available, with registration request ([ab57d81](https://github.com/Automattic/newspack-plugin/commit/ab57d81c405e0fc894693b5796301fa65f7b09ae))
* textarea support ([8676e2d](https://github.com/Automattic/newspack-plugin/commit/8676e2d2a5d3e4ed2d0ad0f41ba1d7be19c9680d))
* use returned status rather than hardcoded value ([ea9af30](https://github.com/Automattic/newspack-plugin/commit/ea9af30d6b1260d2acd47587273346a125bfdb4f))
* use server-side email, not submitted email, for logged-in users ([56e699f](https://github.com/Automattic/newspack-plugin/commit/56e699f315c7fdb5f21f54c5ec35e81d4ef64df2))


### Features

* **access-control:** add Specific posts content rule ([#4674](https://github.com/Automattic/newspack-plugin/issues/4674)) ([6735309](https://github.com/Automattic/newspack-plugin/commit/6735309003142ece5ded2b7d6a9e64857128c677))
* **access-control:** auto-signup on renewal only for already subscribed lists ([#4621](https://github.com/Automattic/newspack-plugin/issues/4621)) ([23934c6](https://github.com/Automattic/newspack-plugin/commit/23934c6d6599c5d0ac58c9611ee370920e9cb3ee))
* **access-control:** human-readable group subscription names ([#4667](https://github.com/Automattic/newspack-plugin/issues/4667)) ([a817304](https://github.com/Automattic/newspack-plugin/commit/a81730455203ca73fc2a03ba155cba5d69af090a))
* **access-control:** tweaks to Access Control UI components ([#4659](https://github.com/Automattic/newspack-plugin/issues/4659)) ([3c08943](https://github.com/Automattic/newspack-plugin/commit/3c089431c35d12fc11c727e2fc07e727be808aa7))
* add block theme support to lite site ([#4628](https://github.com/Automattic/newspack-plugin/issues/4628)) ([71632b9](https://github.com/Automattic/newspack-plugin/commit/71632b939142be0529d37c8701e66fcaa1c74022))
* add overridable registration key methods to Integration base class ([2b4d04f](https://github.com/Automattic/newspack-plugin/commit/2b4d04fd813b9b1df0f55f30432c7fe43b0506ae))
* add reCAPTCHA v2 invisible support to register() ([60b0e77](https://github.com/Automattic/newspack-plugin/commit/60b0e772bfc023b4f88053d21100f5778f6993ba))
* **block-theme:** add size options to the Overlay Menu ([#4652](https://github.com/Automattic/newspack-plugin/issues/4652)) ([3b11b75](https://github.com/Automattic/newspack-plugin/commit/3b11b758be663cdb71dd7177e3101d43c0b69bdd))
* **block-theme:** move co-authors RSS feed code to the Newspack Plugin ([#4629](https://github.com/Automattic/newspack-plugin/issues/4629)) ([50a160c](https://github.com/Automattic/newspack-plugin/commit/50a160c738155d7e69849a861e5d18f16776fb59))
* **co-authors:** support CAP core entity store alongside legacy store ([#4673](https://github.com/Automattic/newspack-plugin/issues/4673)) ([b80c49a](https://github.com/Automattic/newspack-plugin/commit/b80c49a4700fc711364b0727df59b96a04059051))
* **components:** addToolbarBackButton util in newspack-components ([#4619](https://github.com/Automattic/newspack-plugin/issues/4619)) ([ec36aa3](https://github.com/Automattic/newspack-plugin/commit/ec36aa33a67ee751b120c33ed194210a3d55f078))
* **content-gate:** expose institutional IP allowlist endpoint ([#4685](https://github.com/Automattic/newspack-plugin/issues/4685)) ([c6a054a](https://github.com/Automattic/newspack-plugin/commit/c6a054abbbb5098ebc836c6e0183e69693744d50))
* **data-events:** woo transactional events ([#4687](https://github.com/Automattic/newspack-plugin/issues/4687)) ([544d718](https://github.com/Automattic/newspack-plugin/commit/544d718cc6b7f18a8696e93b894fdd197264af1b))
* delegate key generation and validation to Integration instances ([6aba746](https://github.com/Automattic/newspack-plugin/commit/6aba7468915e603f278e3ff7b3a8684cae6ef646))
* **group-subs:** polish invite-links UX in My Account ([#4719](https://github.com/Automattic/newspack-plugin/issues/4719)) ([4044541](https://github.com/Automattic/newspack-plugin/commit/4044541e7ac5c803e091f3d60d5528b004330599))
* **group-subs:** shareable invite links ([#4704](https://github.com/Automattic/newspack-plugin/issues/4704)) ([f5cafdb](https://github.com/Automattic/newspack-plugin/commit/f5cafdb7d94c1ddf927342e29d5251da31cab653))
* initial reader registration API rollup from working branch ([0250b2a](https://github.com/Automattic/newspack-plugin/commit/0250b2a746f8fa4bd30219706ca7f031f93cfb5c))
* **integrations:** add activity logs view ([#4671](https://github.com/Automattic/newspack-plugin/issues/4671)) ([fb98062](https://github.com/Automattic/newspack-plugin/commit/fb98062f80d6280bc214f81e957877ecc54353ed))
* **integrations:** add content gate metadata ([#4605](https://github.com/Automattic/newspack-plugin/issues/4605)) ([dcd2a09](https://github.com/Automattic/newspack-plugin/commit/dcd2a09c86b86423bd0616384deb8a61ccf74b78))
* **integrations:** contact cron for push and pull ([#4608](https://github.com/Automattic/newspack-plugin/issues/4608)) ([29e6668](https://github.com/Automattic/newspack-plugin/commit/29e6668621dd0c72aa2c37464e9c7fdffab7f3ac))
* **integrations:** redesign configure view ([#4668](https://github.com/Automattic/newspack-plugin/issues/4668)) ([472dd06](https://github.com/Automattic/newspack-plugin/commit/472dd06aa9cfd70a17cdb2094515aee6b4dec3e8))
* **integrations:** redesign dashboard with CardFeature grid ([#4665](https://github.com/Automattic/newspack-plugin/issues/4665)) ([d20aae8](https://github.com/Automattic/newspack-plugin/commit/d20aae8bdafc6da68e0d3862f694fbf9680b8179))
* localize reCAPTCHA site key and version for both v2 and v3 ([56ba6ea](https://github.com/Automattic/newspack-plugin/commit/56ba6ea0747e5cabfd53b6d2449e984406d805e2))
* more options for registration ([4c23648](https://github.com/Automattic/newspack-plugin/commit/4c23648a7e4c56e54076ce907d06cabba0387fd2))
* **newspack-ui:** refactor newsletter signup form with Newspack UI utilities ([#4491](https://github.com/Automattic/newspack-plugin/issues/4491)) ([1859473](https://github.com/Automattic/newspack-plugin/commit/1859473b3434f97bf8a51460781106a18c1b03e4))
* **reader-activation:** configure ESP incoming fields from schema ([#4676](https://github.com/Automattic/newspack-plugin/issues/4676)) ([8bc84cc](https://github.com/Automattic/newspack-plugin/commit/8bc84cccecf968b0addbc36e31fc5e1357b22184))
* **reader-activation:** frontend registration API for integrations ([2d7a1bc](https://github.com/Automattic/newspack-plugin/commit/2d7a1bc5c84646015b36f20ddd1ca55b72403495))
* **settings:** Experimental Tools framework (NPPM-2692) ([#4591](https://github.com/Automattic/newspack-plugin/issues/4591)) ([5a300eb](https://github.com/Automattic/newspack-plugin/commit/5a300eb6c98ce2c8face1cef668045938ad8819d))
* trigger a hook and invoke an integration callback when logged in user registers ([167383a](https://github.com/Automattic/newspack-plugin/commit/167383ae864de4761ff1544353676f0b24f7a64b))

## [6.39.4](https://github.com/Automattic/newspack-plugin/compare/v6.39.3...v6.39.4) (2026-05-18)


### Bug Fixes

* **stripe:** prevent re-stamping `_stripe_customer_id` on non-Stripe subscriptions ([#4714](https://github.com/Automattic/newspack-plugin/issues/4714)) ([1fbd0e9](https://github.com/Automattic/newspack-plugin/commit/1fbd0e9fdde7882650621ad6861c76eb5861d24b)), closes [woocommerce/woocommerce-gateway-stripe#2661](https://github.com/woocommerce/woocommerce-gateway-stripe/issues/2661) [#4718](https://github.com/Automattic/newspack-plugin/issues/4718) [#2](https://github.com/Automattic/newspack-plugin/issues/2)

## [6.39.3](https://github.com/Automattic/newspack-plugin/compare/v6.39.2...v6.39.3) (2026-05-11)


### Bug Fixes

* add spce above 'save' button in ad settings ([#4710](https://github.com/Automattic/newspack-plugin/issues/4710)) ([01bcd34](https://github.com/Automattic/newspack-plugin/commit/01bcd343fe2e828d2b992ab94cd017ae780b9694))

## [6.39.2](https://github.com/Automattic/newspack-plugin/compare/v6.39.1...v6.39.2) (2026-05-07)


### Bug Fixes

* **subscriptions:** allow migrated subs to resubscribe ([#4706](https://github.com/Automattic/newspack-plugin/issues/4706)) ([05095d4](https://github.com/Automattic/newspack-plugin/commit/05095d434d55d9784b3eb071310fec77fbf0c5c0))

## [6.39.1](https://github.com/Automattic/newspack-plugin/compare/v6.39.0...v6.39.1) (2026-05-05)


### Bug Fixes

* **my-account:** remove v1 script dependency ([#4696](https://github.com/Automattic/newspack-plugin/issues/4696)) ([8820e98](https://github.com/Automattic/newspack-plugin/commit/8820e98307a4ff47550450bbfeced4d235799304))

# [6.39.0](https://github.com/Automattic/newspack-plugin/compare/v6.38.2...v6.39.0) (2026-05-05)


### Features

* **co-authors:** support CAP core entity store alongside legacy store ([#4673](https://github.com/Automattic/newspack-plugin/issues/4673)) ([d5d77ca](https://github.com/Automattic/newspack-plugin/commit/d5d77ca7a698639bbb65c3cf333198851da24e98))

## [6.38.2](https://github.com/Automattic/newspack-plugin/compare/v6.38.1...v6.38.2) (2026-05-04)


### Bug Fixes

* **integrations:** conditionally enable ESP on first registration ([#4699](https://github.com/Automattic/newspack-plugin/issues/4699)) ([5605699](https://github.com/Automattic/newspack-plugin/commit/5605699bc63d8d18967d8e2eea2ae28487262409))

## [6.38.1](https://github.com/Automattic/newspack-plugin/compare/v6.38.0...v6.38.1) (2026-05-04)


### Bug Fixes

* **reader-revenue:** handle PayPal Payments 4.x container service IDs ([#4694](https://github.com/Automattic/newspack-plugin/issues/4694)) ([5bd0a8a](https://github.com/Automattic/newspack-plugin/commit/5bd0a8a984e1d733b96a233b75dcfe476479663f))

# [6.38.0](https://github.com/Automattic/newspack-plugin/compare/v6.37.0...v6.38.0) (2026-05-04)


### Bug Fixes

* `is_donor` read-only only on WooCommerce-backed donations ([1dde930](https://github.com/Automattic/newspack-plugin/commit/1dde9300b91dbe225584d35261069784c367dcd9))
* **access-control:** fix incorrect class name ([#4638](https://github.com/Automattic/newspack-plugin/issues/4638)) ([a7a7908](https://github.com/Automattic/newspack-plugin/commit/a7a79080986742ef0e26d97154fe732cb677ac03))
* **access-control:** hide My Account group features if Memberships is still active ([#4650](https://github.com/Automattic/newspack-plugin/issues/4650)) ([214fa0d](https://github.com/Automattic/newspack-plugin/commit/214fa0dfa8e760a2fd373ae30a680e76a89d494c))
* add object-level authorization to corrections REST endpoint ([#4643](https://github.com/Automattic/newspack-plugin/issues/4643)) ([a39a62d](https://github.com/Automattic/newspack-plugin/commit/a39a62d59feaaff2337dad60d097988fce1e2e2e))
* address false positives in subscription status alerts on My Account ([f7d6bb8](https://github.com/Automattic/newspack-plugin/commit/f7d6bb8077a2f817e5498360b2bcf8eb000175ba))
* address false positives in subscription status alerts on My Account ([31c8313](https://github.com/Automattic/newspack-plugin/commit/31c831321b787e022828fc113ac1708ccdcc2e08))
* clear stored referrer data where referrer is none or local ([c7ef6f3](https://github.com/Automattic/newspack-plugin/commit/c7ef6f37ff145d505308aeb02fbb6e468076e518))
* count `pending-cancel` by testing against our canonical constant ([2a1f85b](https://github.com/Automattic/newspack-plugin/commit/2a1f85b7564c9692a1b2d12d3a8b59751342b7f6))
* count `pending-cancel` by testing against our canonical constant ([8906b64](https://github.com/Automattic/newspack-plugin/commit/8906b64a4b51d2166957384cc6bc2499fac2634e))
* include products without children when reviewing active subscriptions ([d714f3c](https://github.com/Automattic/newspack-plugin/commit/d714f3c707cd9baae02505c5a082a54622864628))
* include products without children when reviewing active subscriptions ([aae3da2](https://github.com/Automattic/newspack-plugin/commit/aae3da2f0406a6e7d6a2c99585ca42ec39fa552d))
* limit success and notice snackbar treatments to My Account page ([3b40bec](https://github.com/Automattic/newspack-plugin/commit/3b40bece1b8f655a3fc36cf7a60510ab6b5c8915))
* **my-account:** limit notice/success snackbar overrides to My Account ([49517c1](https://github.com/Automattic/newspack-plugin/commit/49517c1bab0e5f562cd14dee375bbf424d7f55ff))
* normalize referrer data when comparing ([d377afb](https://github.com/Automattic/newspack-plugin/commit/d377afb002778ea461f35692a33713be0cf6da8b))
* **reader-activation:** clear referrer data when none provided ([696012b](https://github.com/Automattic/newspack-plugin/commit/696012bb0ca920f6130412457cff06014458abfc))
* **reader-data:** make is_donor read-only only when platform has server-side tracking ([92b0d34](https://github.com/Automattic/newspack-plugin/commit/92b0d34ac6e3bc2ce57d4592ea80118377747cf7))
* update docblock to reflect simple product support ([ea1f03e](https://github.com/Automattic/newspack-plugin/commit/ea1f03e957ebc3af70fef63eb9aa654f09fcc2f3))
* update docblock to reflect simple product support ([ba14f85](https://github.com/Automattic/newspack-plugin/commit/ba14f852c1032b66d6eec6c661399513a50e0c27))
* **woocommerce:** image handling in paginated block ([#4149](https://github.com/Automattic/newspack-plugin/issues/4149)) ([1c91f6c](https://github.com/Automattic/newspack-plugin/commit/1c91f6cbfff15e00c6a7c5677e4680dbdc8874d9))


### Features

* **access-control:** advanced settings for RSS content restriction ([#4613](https://github.com/Automattic/newspack-plugin/issues/4613)) ([85aa560](https://github.com/Automattic/newspack-plugin/commit/85aa560f72c022f864b5be497e4eca68f5ec4da5))
* **access-control:** update default patterns ([#4569](https://github.com/Automattic/newspack-plugin/issues/4569)) ([7f9a6c9](https://github.com/Automattic/newspack-plugin/commit/7f9a6c9d14b2479a4e7352c3926e2d1f0c01263e))
* add filter for future integrations to self-declare server-side (secure?) donor tracking ([76e40fc](https://github.com/Automattic/newspack-plugin/commit/76e40fcd1f565e6d87b9c5cf8c0339ba234253a8))
* add README.md for the Overlay Block ([#4651](https://github.com/Automattic/newspack-plugin/issues/4651)) ([6d7de6e](https://github.com/Automattic/newspack-plugin/commit/6d7de6ef7561844bec7b62ed27e72463e2169c54))
* **advertising:** replace placements UI with inline expandable cards ([#4625](https://github.com/Automattic/newspack-plugin/issues/4625)) ([e5de003](https://github.com/Automattic/newspack-plugin/commit/e5de0033ed2c1b09af5c7b3bcab4bacff9f75c34))
* **block-theme:** add overlay block for the block theme ([#4578](https://github.com/Automattic/newspack-plugin/issues/4578)) ([af1e4b9](https://github.com/Automattic/newspack-plugin/commit/af1e4b9288f258d6d93569c0c6b8b4d22c8d4ee7))
* **components:** update CardFeature button size and variant ([#4609](https://github.com/Automattic/newspack-plugin/issues/4609)) ([1d03d4c](https://github.com/Automattic/newspack-plugin/commit/1d03d4c52fed7c0d4d45c434a0d1411bbbfd1128))
* **content-gate:** add per-block access control for Group, Stack, and Row blocks ([#4646](https://github.com/Automattic/newspack-plugin/issues/4646)) ([5bdf458](https://github.com/Automattic/newspack-plugin/commit/5bdf45835bd1883fd282da4f9cf98aeb40cfd3fb))
* **content-gate:** add POST method for external IP access checks ([#4598](https://github.com/Automattic/newspack-plugin/issues/4598)) ([36bfeea](https://github.com/Automattic/newspack-plugin/commit/36bfeea7f011a41cbb2674da103126411a4a778c))
* **google-site-kit:** enable custom GA frontend params by default ([#4664](https://github.com/Automattic/newspack-plugin/issues/4664)) ([19830ce](https://github.com/Automattic/newspack-plugin/commit/19830ce266e48808aa9cb8e4e870ad64694258a6))
* **handoff:** add URL-based handoff with customizable banner text ([#4603](https://github.com/Automattic/newspack-plugin/issues/4603)) ([be59c68](https://github.com/Automattic/newspack-plugin/commit/be59c682ec3153bf7879f9f5409cb95603fa74fb))
* **integrations:** add My Account menu hook to integration abstraction ([#4640](https://github.com/Automattic/newspack-plugin/issues/4640)) ([4ef2c91](https://github.com/Automattic/newspack-plugin/commit/4ef2c9169dc4a105cb900ac2ddc2fc7e5ff4accf))
* **integrations:** add subscription and donation metadata ([#4597](https://github.com/Automattic/newspack-plugin/issues/4597)) ([ca928f8](https://github.com/Automattic/newspack-plugin/commit/ca928f8abe318010c80023691a9cf23758198a47))
* **integrations:** implement profile metadata ([#4624](https://github.com/Automattic/newspack-plugin/issues/4624)) ([b1daf85](https://github.com/Automattic/newspack-plugin/commit/b1daf8584cc4ff344824e12ff45a1717e9e40fa7))
* make it easier to do "Block until consent given" setups in Complianz and improve blocking ([#4549](https://github.com/Automattic/newspack-plugin/issues/4549)) ([44dda72](https://github.com/Automattic/newspack-plugin/commit/44dda725776e249d7bd6a5861db5dcd451b970dc))
* reader activation segments ([#4604](https://github.com/Automattic/newspack-plugin/issues/4604)) ([3821fed](https://github.com/Automattic/newspack-plugin/commit/3821fed7e21181136b381e78627792de202ba134))
* **reader-data:** add engagement fields ([#4594](https://github.com/Automattic/newspack-plugin/issues/4594)) ([1cba4ef](https://github.com/Automattic/newspack-plugin/commit/1cba4efb3738592233c1a72c29c4c7abdb9dbaf4))
* **reader-data:** store sync reconciliation ([#4633](https://github.com/Automattic/newspack-plugin/issues/4633)) ([69bdda4](https://github.com/Automattic/newspack-plugin/commit/69bdda4348d3a53144b6d48956b09db2729847ab))
* require reader to set name when commenting ([#4647](https://github.com/Automattic/newspack-plugin/issues/4647)) ([db5ad73](https://github.com/Automattic/newspack-plugin/commit/db5ad7339ea185f758f243e9f47c85f7e75adbc1))
* session hydration ([#4618](https://github.com/Automattic/newspack-plugin/issues/4618)) ([665b152](https://github.com/Automattic/newspack-plugin/commit/665b1522b8ead579340d2be3fea9440703c31517))


### Reverts

* address false positives in subscription status alerts on My Account ([9d203b0](https://github.com/Automattic/newspack-plugin/commit/9d203b00f2c8b83c36d3fda0365f0a8d807f625b))

# [6.37.0](https://github.com/Automattic/newspack-plugin/compare/v6.36.3...v6.37.0) (2026-04-13)


### Bug Fixes

* **card-settings-group:** change default actionType from chevron to none ([#4610](https://github.com/Automattic/newspack-plugin/issues/4610)) ([00505ed](https://github.com/Automattic/newspack-plugin/commit/00505ed73e470d496561717e75ce7523d632ce2d))
* **post-date:** preserve classic theme markup and fix archive titles ([#4602](https://github.com/Automattic/newspack-plugin/issues/4602)) ([c5fb825](https://github.com/Automattic/newspack-plugin/commit/c5fb8254d88b57436a5e6061bc5837faf0dd5feb))
* remove removal of block visibility ([#4595](https://github.com/Automattic/newspack-plugin/issues/4595)) ([9396379](https://github.com/Automattic/newspack-plugin/commit/9396379eeeff64439b73692467a1d7c929bcd91c))


### Features

* **access-control:** filter available lists by content restrictions ([#4589](https://github.com/Automattic/newspack-plugin/issues/4589)) ([959127f](https://github.com/Automattic/newspack-plugin/commit/959127f56729dd60da5ec7f0df7e5a357a872135)), closes [#4581](https://github.com/Automattic/newspack-plugin/issues/4581) [#4583](https://github.com/Automattic/newspack-plugin/issues/4583) [#4590](https://github.com/Automattic/newspack-plugin/issues/4590)
* **access-control:** premium newsletters UI ([#4577](https://github.com/Automattic/newspack-plugin/issues/4577)) ([6f8c891](https://github.com/Automattic/newspack-plugin/commit/6f8c8915a66bacc743a57196e719762a1b15b877)), closes [#4581](https://github.com/Automattic/newspack-plugin/issues/4581) [#4583](https://github.com/Automattic/newspack-plugin/issues/4583) [#4590](https://github.com/Automattic/newspack-plugin/issues/4590)
* **author-profile-social:** add support for colors, block spacing, brand style ([#4509](https://github.com/Automattic/newspack-plugin/issues/4509)) ([21cf4c9](https://github.com/Automattic/newspack-plugin/commit/21cf4c94e31bb053506c213865a55c4dd8949be8))
* campaigns wizard light UI refresh ([#4588](https://github.com/Automattic/newspack-plugin/issues/4588)) ([6078c4b](https://github.com/Automattic/newspack-plugin/commit/6078c4ba5153c046071840d8bbcf2d73607fd1b0))
* **color-picker:** simplify component to use basecontrol ([#4581](https://github.com/Automattic/newspack-plugin/issues/4581)) ([ff677ea](https://github.com/Automattic/newspack-plugin/commit/ff677ea1b6f740f9b8d3559bdc635afce31f9a1a))
* **components:** add CardFeature component ([#4583](https://github.com/Automattic/newspack-plugin/issues/4583)) ([5aabb18](https://github.com/Automattic/newspack-plugin/commit/5aabb184e918b59b42eb75a761e15168e99272cd))
* **content-gate:** institution management ui ([#4582](https://github.com/Automattic/newspack-plugin/issues/4582)) ([ae88750](https://github.com/Automattic/newspack-plugin/commit/ae887509819cf7ac8d41ca4470a83fc9b5dca0f2))
* **content-gate:** institutional access redirect and loading UX ([#4593](https://github.com/Automattic/newspack-plugin/issues/4593)) ([548d236](https://github.com/Automattic/newspack-plugin/commit/548d236aa30366511048bc787e8cef77941e2a2e))
* **content-gate:** institutions ([#4574](https://github.com/Automattic/newspack-plugin/issues/4574)) ([49b0c05](https://github.com/Automattic/newspack-plugin/commit/49b0c05f74e4936ae4f425084f9fb6d1fabb78c8))
* **content-gate:** personalized institutional access verification page ([#4596](https://github.com/Automattic/newspack-plugin/issues/4596)) ([0eed591](https://github.com/Automattic/newspack-plugin/commit/0eed5916835882e9dc7f973234e2aa12322fafe1))
* **image-upload:** simplify component to use basecontrol; remove info prop ([#4580](https://github.com/Automattic/newspack-plugin/issues/4580)) ([d51eb54](https://github.com/Automattic/newspack-plugin/commit/d51eb541e9af3cc22f8a15d10b270ed9a4e06a4f))
* **integrations:** add ActionScheduler group handling ([#4559](https://github.com/Automattic/newspack-plugin/issues/4559)) ([411732a](https://github.com/Automattic/newspack-plugin/commit/411732a102e38cc4de05124a8e2ab7889162ff53))
* **integrations:** promoted fields for content gate and campaign segmentation ([#4601](https://github.com/Automattic/newspack-plugin/issues/4601)) ([f943df2](https://github.com/Automattic/newspack-plugin/commit/f943df2bd6d80429302951634a09cb721c3720ba))
* **newspack-ui:** add stack layout and color utility classes ([#4600](https://github.com/Automattic/newspack-plugin/issues/4600)) ([1934067](https://github.com/Automattic/newspack-plugin/commit/1934067f655bfa8f6d6af6648aeb376df2f411ff))
* **post-date:** centralize date features from theme into plugin ([#4579](https://github.com/Automattic/newspack-plugin/issues/4579)) ([19f15eb](https://github.com/Automattic/newspack-plugin/commit/19f15eb49511617d03cb2dab44fb9422667859ff))
* **sync:** prevent stale data on retry, improve logging and error handling ([#4562](https://github.com/Automattic/newspack-plugin/issues/4562)) ([5467f34](https://github.com/Automattic/newspack-plugin/commit/5467f34a0733c0495588da36e9b2c4b50fbd293d))
* **tags:** add private tags feature ([#4507](https://github.com/Automattic/newspack-plugin/issues/4507)) ([06d7711](https://github.com/Automattic/newspack-plugin/commit/06d771105ae8030d5f5f33fe6c6a21de269453f1))
* **yoast:** add primary category utility and settings toggle ([#4563](https://github.com/Automattic/newspack-plugin/issues/4563)) ([4b396c3](https://github.com/Automattic/newspack-plugin/commit/4b396c35c8019574082fd250aefaa23d377b2669))

## [6.36.3](https://github.com/Automattic/newspack-plugin/compare/v6.36.2...v6.36.3) (2026-04-13)


### Bug Fixes

* hotfix release of privacy features ([#4653](https://github.com/Automattic/newspack-plugin/issues/4653)) ([22cc5d3](https://github.com/Automattic/newspack-plugin/commit/22cc5d3011782975f97df391d0baba40e46c9196))

## [6.36.2](https://github.com/Automattic/newspack-plugin/compare/v6.36.1...v6.36.2) (2026-04-06)


### Bug Fixes

* **reader-activation:** gate password reset email override on AM enabled ([#4622](https://github.com/Automattic/newspack-plugin/issues/4622)) ([ff761f9](https://github.com/Automattic/newspack-plugin/commit/ff761f9634dc6c6b5bcdc749ca67f57e15d36591))

## [6.36.1](https://github.com/Automattic/newspack-plugin/compare/v6.36.0...v6.36.1) (2026-04-02)


### Bug Fixes

* **jetpack:** disable Image Studio to restore Media Library custom fields ([#4616](https://github.com/Automattic/newspack-plugin/issues/4616)) ([3861d1f](https://github.com/Automattic/newspack-plugin/commit/3861d1faca270367712132b35537c019d3d9ef98))

# [6.36.0](https://github.com/Automattic/newspack-plugin/compare/v6.35.2...v6.36.0) (2026-03-30)


### Bug Fixes

* **access-rules:** normalize rules to individual OR groups ([#4584](https://github.com/Automattic/newspack-plugin/issues/4584)) ([40a7259](https://github.com/Automattic/newspack-plugin/commit/40a7259db182e0cda777ce761ee6745491913324))
* apply max-width to add payment method iframe ([#4575](https://github.com/Automattic/newspack-plugin/issues/4575)) ([de27bb5](https://github.com/Automattic/newspack-plugin/commit/de27bb5fb24b020e58718fc588e7ec0895d33353))
* **block-theme:** ensure content gate contents inserted in time for styles to be rendered ([#4539](https://github.com/Automattic/newspack-plugin/issues/4539)) ([2be2f3b](https://github.com/Automattic/newspack-plugin/commit/2be2f3bb382a56f23dccadc23794f523b04862df))
* **block-theme:** load block styles when needed ([#4555](https://github.com/Automattic/newspack-plugin/issues/4555)) ([ec4c70b](https://github.com/Automattic/newspack-plugin/commit/ec4c70b76e177992735b26b8a6f70f2efeec501e))
* **block-theme:** migrate newspack-theme modal checkout fixes ([#4557](https://github.com/Automattic/newspack-plugin/issues/4557)) ([6eb5f48](https://github.com/Automattic/newspack-plugin/commit/6eb5f4816e366b7750ce1bc4de33796e4788f719))
* **content-gating:** allow for metering with paid access only ([#4571](https://github.com/Automattic/newspack-plugin/issues/4571)) ([e2d8e01](https://github.com/Automattic/newspack-plugin/commit/e2d8e01a276ace26ab31a1b838e15f31200039fa))
* exclude Jetpack Swiper CSS from perfmatters unused CSS feature ([#4538](https://github.com/Automattic/newspack-plugin/issues/4538)) ([0d64190](https://github.com/Automattic/newspack-plugin/commit/0d64190f8b686b102d3a96073f7751c8f9a5f506))
* guard against null, but enumerated, storage keys ([adcec53](https://github.com/Automattic/newspack-plugin/commit/adcec53ff7106829a621d9d95102549cc916492b))
* **integrations:** integrations settings registration ([#4573](https://github.com/Automattic/newspack-plugin/issues/4573)) ([a4d90c2](https://github.com/Automattic/newspack-plugin/commit/a4d90c2d1aa625c741b6721ac7dd5e0630ae3174))
* json_decode and notice style fixes ([#4531](https://github.com/Automattic/newspack-plugin/issues/4531)) ([8fc6ada](https://github.com/Automattic/newspack-plugin/commit/8fc6ada4ae48211f8372a616ed029ce0e8d7a67c))
* use correct is_enabled method ([#4564](https://github.com/Automattic/newspack-plugin/issues/4564)) ([6396e41](https://github.com/Automattic/newspack-plugin/commit/6396e4120e40fbce2e30427c4c5f87998a938178)), closes [#4541](https://github.com/Automattic/newspack-plugin/issues/4541)


### Features

* **alert-manager:** failure pattern detection ([#4527](https://github.com/Automattic/newspack-plugin/issues/4527)) ([9a0bb3d](https://github.com/Automattic/newspack-plugin/commit/9a0bb3def2012604c6182d6ae5f617c5a43e711f))
* alerting system ([#4511](https://github.com/Automattic/newspack-plugin/issues/4511)) ([1df65a0](https://github.com/Automattic/newspack-plugin/commit/1df65a0cfa86b588581f9438cb47d09b0075a2eb))
* **content-gate:** default block patterns ([#4540](https://github.com/Automattic/newspack-plugin/issues/4540)) ([4852a9a](https://github.com/Automattic/newspack-plugin/commit/4852a9a0ca53d48e9b92294dc729174ffbc084ac))
* **content-gate:** display matching gates in post editor ([#4572](https://github.com/Automattic/newspack-plugin/issues/4572)) ([eb275eb](https://github.com/Automattic/newspack-plugin/commit/eb275eb6aadd9971bfbcba20893ba5014db4e3da))
* **content-gating:** group subscriptions members in my account ([#4543](https://github.com/Automattic/newspack-plugin/issues/4543)) ([202781a](https://github.com/Automattic/newspack-plugin/commit/202781a9f188792431b5e920b4fc893e2817f28b))
* **content-gating:** i3 designs for Metered Countdown + Content Gifting ([#4526](https://github.com/Automattic/newspack-plugin/issues/4526)) ([ba5b4ee](https://github.com/Automattic/newspack-plugin/commit/ba5b4eeda4df1c7447f4957a66581f16333ee26e))
* **group-subscription:** invite email and acceptance flow ([#4546](https://github.com/Automattic/newspack-plugin/issues/4546)) ([ac50a98](https://github.com/Automattic/newspack-plugin/commit/ac50a98dccb301dfc9b7435ef11f6c7e6dc26789))
* **integrations:** add integrations settings framework ([#4541](https://github.com/Automattic/newspack-plugin/issues/4541)) ([735e640](https://github.com/Automattic/newspack-plugin/commit/735e6403f66029ec7cd4f32e43f6a47750c3832c))
* **integrations:** refactor metadata for integration specific selection ([#4544](https://github.com/Automattic/newspack-plugin/issues/4544)) ([eb747fc](https://github.com/Automattic/newspack-plugin/commit/eb747fc5d6e0d8b4fb71b6118214c0e71a678c8d))
* new `getAll()` method to fetch all reader data from store at once ([fbe68db](https://github.com/Automattic/newspack-plugin/commit/fbe68db49ccd7083f17036ecd212c201fd2b2ab2))
* new `getAll()` method to retrieve all items from the reader data store ([b7d6664](https://github.com/Automattic/newspack-plugin/commit/b7d6664f0fa50892ad91a1873184d079ead07d0b))
* **reader-activation:** add integration health check system ([#4550](https://github.com/Automattic/newspack-plugin/issues/4550)) ([4993f21](https://github.com/Automattic/newspack-plugin/commit/4993f2142308d0fdd5013f9207047c30a66b0ab3))
* **yoast:** add Bluesky contact method registration ([#4554](https://github.com/Automattic/newspack-plugin/issues/4554)) ([aa143d7](https://github.com/Automattic/newspack-plugin/commit/aa143d72356e11f1872a6ecdf27a3feaa847dde9))

## [6.35.2](https://github.com/Automattic/newspack-plugin/compare/v6.35.1...v6.35.2) (2026-03-30)


### Bug Fixes

* **memberships:** force non-empty message to allow wc_memberships_notice_html to fire ([0e6f1c0](https://github.com/Automattic/newspack-plugin/commit/0e6f1c0d7600267f4524eec3411b1e2f15254d3e))

## [6.35.1](https://github.com/Automattic/newspack-plugin/compare/v6.35.0...v6.35.1) (2026-03-18)


### Bug Fixes

* membership cancellation bug ([4eb5397](https://github.com/Automattic/newspack-plugin/commit/4eb53974d52eb51f422eb5eb6696d61e3742ecf7))
* membership cancellation bug ([#4566](https://github.com/Automattic/newspack-plugin/issues/4566)) ([0bd82fa](https://github.com/Automattic/newspack-plugin/commit/0bd82fa394b71c64d90340b1f049a2a0c66c91bb))

# [6.35.0](https://github.com/Automattic/newspack-plugin/compare/v6.34.4...v6.35.0) (2026-03-16)


### Bug Fixes

* allow deletion of payment method for Braintree ([63ceec3](https://github.com/Automattic/newspack-plugin/commit/63ceec3b6dfcc74cb11bb685b9dd17ff404144d8))
* apply Copilot suggestion for tests ([6133636](https://github.com/Automattic/newspack-plugin/commit/6133636efaeaaf34c9a4605ae4bd47ca416de7db))
* **avatar:** correct duotone selector to support custom colors ([#4513](https://github.com/Automattic/newspack-plugin/issues/4513)) ([c4458d4](https://github.com/Automattic/newspack-plugin/commit/c4458d411c17c48ff713fe0669f345f735a1e4ba))
* **byline:** update placeholder text to "Author Name" ([#4510](https://github.com/Automattic/newspack-plugin/issues/4510)) ([727b1e8](https://github.com/Automattic/newspack-plugin/commit/727b1e88d2aaf55b6eac48ddb42ee39378358d0a))
* **co-authors-plus:** sanitize user_login before building dummy email… ([#4520](https://github.com/Automattic/newspack-plugin/issues/4520)) ([32c2602](https://github.com/Automattic/newspack-plugin/commit/32c26026ffad3417d3c97314716cbd083ffe81f3))
* do not depend on _get() to return an array ([2816470](https://github.com/Automattic/newspack-plugin/commit/28164704b0c3306e2065277eafd29d8893362912))
* **dropdown-menu:** avoid overflow at bottom of viewport ([#4495](https://github.com/Automattic/newspack-plugin/issues/4495)) ([a5ce464](https://github.com/Automattic/newspack-plugin/commit/a5ce464d00ebda5598f36a5b31fcdfd73226c357))
* enable payment method deletion for Braintree ([c50f094](https://github.com/Automattic/newspack-plugin/commit/c50f094805dd1d9389ccf4f890aeffb0b998320a))
* iframe editor compatiblity ([#4444](https://github.com/Automattic/newspack-plugin/issues/4444)) ([12dfaba](https://github.com/Automattic/newspack-plugin/commit/12dfababdcb1d42678a25b17abe1f15587ab5c28))
* **my-account:** don't show subscription payment notice in modal checkout ([#4500](https://github.com/Automattic/newspack-plugin/issues/4500)) ([2cb3324](https://github.com/Automattic/newspack-plugin/commit/2cb3324cdf5592fa4b51fac11573d85be3b4adab))
* **my-account:** force white background color ([#4514](https://github.com/Automattic/newspack-plugin/issues/4514)) ([02e2555](https://github.com/Automattic/newspack-plugin/commit/02e2555eb9b714268314fac5703d7a4a2306594b))
* **my-account:** resubscription validation in order-again template ([#4498](https://github.com/Automattic/newspack-plugin/issues/4498)) ([36871fc](https://github.com/Automattic/newspack-plugin/commit/36871fc47db8f99b29912eea3b201a106a623d14))
* **newspack-icon:** background color ([#4516](https://github.com/Automattic/newspack-plugin/issues/4516)) ([8c5dc9c](https://github.com/Automattic/newspack-plugin/commit/8c5dc9c3017b6095b7d82abefe15dcfa62f21e66))
* **newspack-ui:** remove overflow when modal is open ([#4515](https://github.com/Automattic/newspack-plugin/issues/4515)) ([0b2cbd3](https://github.com/Automattic/newspack-plugin/commit/0b2cbd31d397252d2bcb635048d0b8b2f16515b6))
* prune read-only keys from sync queue ([bf81734](https://github.com/Automattic/newspack-plugin/commit/bf817349e0c62857ff0e165419f340d56b51f182))
* remove unused 'edit' and 'save' actions from Braintree payment methods ([8f72a8d](https://github.com/Automattic/newspack-plugin/commit/8f72a8dc4c38a02dadd5263d3814b360468a8161))
* **snackbar:** autohide progress bar not always appearing ([#4504](https://github.com/Automattic/newspack-plugin/issues/4504)) ([4eda60f](https://github.com/Automattic/newspack-plugin/commit/4eda60f8cc3df7492505e04dbbef2bbd2c68d109))


### Features

*  identify and enforce read-only items for Reader Data Library ([4f2ef8a](https://github.com/Automattic/newspack-plugin/commit/4f2ef8a7e6054bf352ed349f2c828b0f40686df8))
* add featured image caption block ([#4519](https://github.com/Automattic/newspack-plugin/issues/4519)) ([0b744ff](https://github.com/Automattic/newspack-plugin/commit/0b744ffbfc9e3d1be6c3318038e3ab3517c6e222))
* **author-profile:** avatar and social blocks for nested author profile ([#4448](https://github.com/Automattic/newspack-plugin/issues/4448)) ([cff670b](https://github.com/Automattic/newspack-plugin/commit/cff670ba928adb5967989dcee53bc69db6a2a4e1))
* **content-gate:** comment restriction with metering support ([#4529](https://github.com/Automattic/newspack-plugin/issues/4529)) ([714c8b7](https://github.com/Automattic/newspack-plugin/commit/714c8b79f66a83f099a906d829833c3118ba2dee))
* **content-gate:** per-post access control exemption ([#4530](https://github.com/Automattic/newspack-plugin/issues/4530)) ([636679b](https://github.com/Automattic/newspack-plugin/commit/636679b8deace53d79980e3ee7174a8a531ee724))
* **content-gate:** user access information ([#4542](https://github.com/Automattic/newspack-plugin/issues/4542)) ([b49347e](https://github.com/Automattic/newspack-plugin/commit/b49347e4ee769f9cdb76049f63484ac5c822e567))
* **content-gating:** group subscription invitations data structure + admin UI ([#4536](https://github.com/Automattic/newspack-plugin/issues/4536)) ([e609e83](https://github.com/Automattic/newspack-plugin/commit/e609e83304bdcbcafefea02bd743652869d0fd81))
* **content-gating:** i3 designs for content gates list view ([#4512](https://github.com/Automattic/newspack-plugin/issues/4512)) ([dcbb239](https://github.com/Automattic/newspack-plugin/commit/dcbb2398ef0437f1e92270569b22345149623b9b))
* **corrections:** append corrections to Republication Tracker Tool content ([#4501](https://github.com/Automattic/newspack-plugin/issues/4501)) ([71bd177](https://github.com/Automattic/newspack-plugin/commit/71bd1771a5c4c9b74032e105d6a40e9e537872f9))
* data event handler registration and dispatching to integrations ([#4481](https://github.com/Automattic/newspack-plugin/issues/4481)) ([7c79da6](https://github.com/Automattic/newspack-plugin/commit/7c79da6930997c5918f895fe8c5c64319f837b9f))
* **data-events:** add ActionScheduler logging and retry reason ([#4488](https://github.com/Automattic/newspack-plugin/issues/4488)) ([e8af964](https://github.com/Automattic/newspack-plugin/commit/e8af9647005169978f259b6fefc48746921a57b1))
* **integrations:** make sure contact data is read from persistent data ([#4505](https://github.com/Automattic/newspack-plugin/issues/4505)) ([c2ee26a](https://github.com/Automattic/newspack-plugin/commit/c2ee26a30de06db2036457be175fff3792193282))
* **integrations:** start get fields abstraction and pull contact data ([#4470](https://github.com/Automattic/newspack-plugin/issues/4470)) ([8ddcf23](https://github.com/Automattic/newspack-plugin/commit/8ddcf23b8c0a930984f90e3d23d7aa99768f1661)), closes [#4477](https://github.com/Automattic/newspack-plugin/issues/4477)
* **reader-registration-block:** password, OTP, and verification flows ([#4452](https://github.com/Automattic/newspack-plugin/issues/4452)) ([fc4567e](https://github.com/Automattic/newspack-plugin/commit/fc4567e61ab70b538bc239c9da3078837ef756db))
* reject changes to specific reader data keys ([fe52e12](https://github.com/Automattic/newspack-plugin/commit/fe52e12c0ef6a14424c14211628c94b2adf1835e))

## [6.34.4](https://github.com/Automattic/newspack-plugin/compare/v6.34.3...v6.34.4) (2026-03-11)


### Bug Fixes

* change ip check cookie lifetime ([#4556](https://github.com/Automattic/newspack-plugin/issues/4556)) ([0be6eab](https://github.com/Automattic/newspack-plugin/commit/0be6eab56381b0c3bfdc66df921f7df376c354a6))

## [6.34.3](https://github.com/Automattic/newspack-plugin/compare/v6.34.2...v6.34.3) (2026-03-06)


### Bug Fixes

* setcookie call ([#4534](https://github.com/Automattic/newspack-plugin/issues/4534)) ([27f473a](https://github.com/Automattic/newspack-plugin/commit/27f473a988793081d4a9cda6c043f058f3c6a91e))

## [6.34.2](https://github.com/Automattic/newspack-plugin/compare/v6.34.1...v6.34.2) (2026-03-04)


### Bug Fixes

* **wizard:** restore PluginInstaller routing when required plugins unsatisfied ([#4545](https://github.com/Automattic/newspack-plugin/issues/4545)) ([f7105ce](https://github.com/Automattic/newspack-plugin/commit/f7105ce1d2fa28c5dd546d0e04878ad72e786ccb))

## [6.34.1](https://github.com/Automattic/newspack-plugin/compare/v6.34.0...v6.34.1) (2026-03-02)


### Bug Fixes

* enable payment method deletion for Braintree ([5eb5797](https://github.com/Automattic/newspack-plugin/commit/5eb5797a38b7c1557350cde783e6abde1e11bbf7))
* enable payment method deletion for Braintree ([f0600c8](https://github.com/Automattic/newspack-plugin/commit/f0600c8f194a7444fbd48c9509aed8976dae3ba6))

# [6.34.0](https://github.com/Automattic/newspack-plugin/compare/v6.33.0...v6.34.0) (2026-03-02)


### Bug Fixes

* add bock theme check before switching templates to prevent warnings ([#4412](https://github.com/Automattic/newspack-plugin/issues/4412)) ([dfb5b63](https://github.com/Automattic/newspack-plugin/commit/dfb5b63cf1daaae3b1950cd312bebc1211bc3151))
* add check for my account before switching error notice ([#4484](https://github.com/Automattic/newspack-plugin/issues/4484)) ([0cb3ae8](https://github.com/Automattic/newspack-plugin/commit/0cb3ae81af5dd4b13530e092ac52c7a8556eec07))
* add fallback selector for the content gate in block theme ([#4431](https://github.com/Automattic/newspack-plugin/issues/4431)) ([dd0b2b1](https://github.com/Automattic/newspack-plugin/commit/dd0b2b1e58dc6e4dba30422d7b899d2960909a7d))
* **avatar:** show placeholder for text-only custom bylines in editor ([#4456](https://github.com/Automattic/newspack-plugin/issues/4456)) ([56d3278](https://github.com/Automattic/newspack-plugin/commit/56d3278ffdb22a46f8544dff7561688e426d36d1))
* **content-gate:** create gate layout with 'publish' status ([#4483](https://github.com/Automattic/newspack-plugin/issues/4483)) ([108215a](https://github.com/Automattic/newspack-plugin/commit/108215a0adc3a4c9d7fafbaae9e0a090ddf30131))
* **content-gate:** prevent metering from bypassing account verification requirement ([#4459](https://github.com/Automattic/newspack-plugin/issues/4459)) ([90aed19](https://github.com/Automattic/newspack-plugin/commit/90aed1952b35894fe67712b4b0c9df825e876865))
* **my-account:** hide payment method dropdown if only has one child and is disabled ([#4472](https://github.com/Automattic/newspack-plugin/issues/4472)) ([6e3c3f5](https://github.com/Automattic/newspack-plugin/commit/6e3c3f5e7f3fd7fc927da75ec1f6c3934b653362))
* **my-account:** missing padding on labels ([#4479](https://github.com/Automattic/newspack-plugin/issues/4479)) ([6c6d183](https://github.com/Automattic/newspack-plugin/commit/6c6d18378bcbf2409c21110ad9705a2abd3bb42a))
* tweak inline gate styles for block theme ([#4445](https://github.com/Automattic/newspack-plugin/issues/4445)) ([d867a53](https://github.com/Automattic/newspack-plugin/commit/d867a53b8ce26577c0ddb4cee709adca8ba05b11))


### Features

* **content-gating:** new UI for adding/editing content gates ([#4474](https://github.com/Automattic/newspack-plugin/issues/4474)) ([a193ecc](https://github.com/Automattic/newspack-plugin/commit/a193eccaba844ab61691beb747b3020b6d830fdf))
* **data-events:** handler retry and ActionScheduler support ([#4469](https://github.com/Automattic/newspack-plugin/issues/4469)) ([c997f38](https://github.com/Automattic/newspack-plugin/commit/c997f389841abc0ef1b5762c71f23f837066055f))
* integrations - rename classes and move can_sync ([#4451](https://github.com/Automattic/newspack-plugin/issues/4451)) ([877ce4f](https://github.com/Automattic/newspack-plugin/commit/877ce4fae49c499e7451f83f5eed21100b349fe0))
* integrations barebones ([#4433](https://github.com/Automattic/newspack-plugin/issues/4433)) ([79bf9a7](https://github.com/Automattic/newspack-plugin/commit/79bf9a78070e73ef22d8158bb639bfea58aa5af7))
* **my-account:** block theme styles ([#4430](https://github.com/Automattic/newspack-plugin/issues/4430)) ([1629465](https://github.com/Automattic/newspack-plugin/commit/16294656e4c7f60e1a603226a4bede792cfb6f67))
* **my-account:** improve navigation on small screens ([#4471](https://github.com/Automattic/newspack-plugin/issues/4471)) ([3404a66](https://github.com/Automattic/newspack-plugin/commit/3404a66dd75a9ef52933286040756af194212438))
* **newspack-components:** add divider component ([#4462](https://github.com/Automattic/newspack-plugin/issues/4462)) ([f080d72](https://github.com/Automattic/newspack-plugin/commit/f080d721fd2c1d93ac2b8248c242d477b38298f4))

# [6.33.0](https://github.com/Automattic/newspack-plugin/compare/v6.32.0...v6.33.0) (2026-02-27)


### Features

* trigger release with ip check landing page ([7fbcdcd](https://github.com/Automattic/newspack-plugin/commit/7fbcdcda69e43ef70fe1cfba00db30bbbc13647c))

# [6.32.0](https://github.com/Automattic/newspack-plugin/compare/v6.31.1...v6.32.0) (2026-02-16)


### Bug Fixes

* add check for my account before switching error notice ([#4484](https://github.com/Automattic/newspack-plugin/issues/4484)) ([9e13eca](https://github.com/Automattic/newspack-plugin/commit/9e13eca7441cfdd29652bf7333ed87c2c3cbccef))
* **collections:** hide indicator for unpublished collections ([#4434](https://github.com/Automattic/newspack-plugin/issues/4434)) ([3eda622](https://github.com/Automattic/newspack-plugin/commit/3eda622316f57a41edd4c6fc81292b30d5548e5f))
* **content-gate:** persist restricted content ([#4420](https://github.com/Automattic/newspack-plugin/issues/4420)) ([856695d](https://github.com/Automattic/newspack-plugin/commit/856695da41637cc93cf48493783c61d094bdeab8))
* decrease image size for Collections in certain cases ([#4395](https://github.com/Automattic/newspack-plugin/issues/4395)) ([de3c001](https://github.com/Automattic/newspack-plugin/commit/de3c0014bc95909130ef8154f3ce5138cf261657))
* **my-account:** "order again" checkout redirect ([#4427](https://github.com/Automattic/newspack-plugin/issues/4427)) ([3a9e7fb](https://github.com/Automattic/newspack-plugin/commit/3a9e7fb9be42131e947b3a3294f696035c13b731))
* remove alignments from the My Account Block button ([#4438](https://github.com/Automattic/newspack-plugin/issues/4438)) ([8de3526](https://github.com/Automattic/newspack-plugin/commit/8de352691441601b1ce1141a0c4719a651b6be2e))


### Features

* Add My Account button block ([#4409](https://github.com/Automattic/newspack-plugin/issues/4409)) ([b0c414f](https://github.com/Automattic/newspack-plugin/commit/b0c414fcc2746a49cf204295dd08e9922b92f3f7))
* **avatar:** add custom byline support ([#4424](https://github.com/Automattic/newspack-plugin/issues/4424)) ([152b1af](https://github.com/Automattic/newspack-plugin/commit/152b1af8b72a20752f8dfc7e375a033b7de65de2))
* **content-gate:** grouped access rules evaluation and normalization ([#4435](https://github.com/Automattic/newspack-plugin/issues/4435)) ([aa182c9](https://github.com/Automattic/newspack-plugin/commit/aa182c9800f24f64c47b7c1f7eb58980b346ea56))
* **content-gate:** layout deletion and default content handling ([#4436](https://github.com/Automattic/newspack-plugin/issues/4436)) ([f0b3b18](https://github.com/Automattic/newspack-plugin/commit/f0b3b18378f3106500511aa044a41c89602a85a8))
* **content-gate:** support group subscriptions access rule ([#4442](https://github.com/Automattic/newspack-plugin/issues/4442)) ([416cd13](https://github.com/Automattic/newspack-plugin/commit/416cd13462dbefe3025875ee5f0e2e75ca462f17))
* **content-gate:** support new metering schema ([#4419](https://github.com/Automattic/newspack-plugin/issues/4419)) ([e3f2f56](https://github.com/Automattic/newspack-plugin/commit/e3f2f568e5593ee999c6eda493e71778322ba39d))
* **my-account:** new layout for single subscription pages ([#4425](https://github.com/Automattic/newspack-plugin/issues/4425)) ([6425dbf](https://github.com/Automattic/newspack-plugin/commit/6425dbfc4604a9f4534a4ecc89c95a60ad81647d)), closes [#4428](https://github.com/Automattic/newspack-plugin/issues/4428) [#4432](https://github.com/Automattic/newspack-plugin/issues/4432) [#4409](https://github.com/Automattic/newspack-plugin/issues/4409) [#4419](https://github.com/Automattic/newspack-plugin/issues/4419) [#4413](https://github.com/Automattic/newspack-plugin/issues/4413) [#4434](https://github.com/Automattic/newspack-plugin/issues/4434)

## [6.31.1](https://github.com/Automattic/newspack-plugin/compare/v6.31.0...v6.31.1) (2026-02-16)


### Bug Fixes

* adjust lazy loading logic ([#4485](https://github.com/Automattic/newspack-plugin/issues/4485)) ([29f0458](https://github.com/Automattic/newspack-plugin/commit/29f0458a717568b170fc98e098d7bf0d359218e6))

# [6.31.0](https://github.com/Automattic/newspack-plugin/compare/v6.30.2...v6.31.0) (2026-02-10)


### Features

* release the redesign of the My Account dashboard ([#4468](https://github.com/Automattic/newspack-plugin/issues/4468)) ([20a374c](https://github.com/Automattic/newspack-plugin/commit/20a374c1ef5b97e2487d55b07b76bbb747e2410b))

## [6.30.2](https://github.com/Automattic/newspack-plugin/compare/v6.30.1...v6.30.2) (2026-02-09)


### Bug Fixes

* **my-account:** activate first payment method on load ([#4463](https://github.com/Automattic/newspack-plugin/issues/4463)) ([680a48e](https://github.com/Automattic/newspack-plugin/commit/680a48ecf308ce4b4348d709738876e83a5aa008))

## [6.30.1](https://github.com/Automattic/newspack-plugin/compare/v6.30.0...v6.30.1) (2026-02-05)


### Bug Fixes

* **my-account:** teams for memberships navigation ([#4457](https://github.com/Automattic/newspack-plugin/issues/4457)) ([e776e4d](https://github.com/Automattic/newspack-plugin/commit/e776e4d641942491ce692e20da6fb21380530278))

# [6.30.0](https://github.com/Automattic/newspack-plugin/compare/v6.29.5...v6.30.0) (2026-02-02)


### Bug Fixes

* **content-gate:** handle floating elements within content gate excerpt ([#4394](https://github.com/Automattic/newspack-plugin/issues/4394)) ([7b66b1b](https://github.com/Automattic/newspack-plugin/commit/7b66b1bfe2e37aefb0e9c6f59b4f0db8b657522b))
* **guest-contributors:** preserve special characters in guest contributor display names ([#4411](https://github.com/Automattic/newspack-plugin/issues/4411)) ([20a612b](https://github.com/Automattic/newspack-plugin/commit/20a612bbf9f2a5301cf3e8dca7964cdc495bf9b4))
* **memberships:** handle expired team subscription resubscribes ([#4387](https://github.com/Automattic/newspack-plugin/issues/4387)) ([155f85b](https://github.com/Automattic/newspack-plugin/commit/155f85b6ea27305680f840f2670eeccb7c31b0a1))
* merge conflict ([52c2ae1](https://github.com/Automattic/newspack-plugin/commit/52c2ae18fed736ef1153f2de8f3c80d557fd7a83))
* **my-acount:** navigation margin without favicon ([#4388](https://github.com/Automattic/newspack-plugin/issues/4388)) ([e1ae7e1](https://github.com/Automattic/newspack-plugin/commit/e1ae7e18b72e39e57b5cbe49eca0c985c19a890b))
* remove duplicate method ([0619143](https://github.com/Automattic/newspack-plugin/commit/0619143a0f56d81ddbca1b5b6ecb27625ba2c41f))
* show_if_newspack_group_subsription_enabled misspelling ([#4447](https://github.com/Automattic/newspack-plugin/issues/4447)) ([54b128f](https://github.com/Automattic/newspack-plugin/commit/54b128f452ad19a98bb6d64ee8bcbf35db981d97))
* update prompt_id to newspack_popup_id in the data events readme ([#4378](https://github.com/Automattic/newspack-plugin/issues/4378)) ([57cb730](https://github.com/Automattic/newspack-plugin/commit/57cb730696259a8694876a21cea39c316ac4bfa5))
* verify reader accounts post checkokut ([#4410](https://github.com/Automattic/newspack-plugin/issues/4410)) ([25073e3](https://github.com/Automattic/newspack-plugin/commit/25073e343ccdc37800c038ed3d049f9a30514631))


### Features

* **byline:** add byline block with custom byline and CAP support ([#4405](https://github.com/Automattic/newspack-plugin/issues/4405)) ([2746005](https://github.com/Automattic/newspack-plugin/commit/274600563036c7e3cb7bd1c0ce1e2273c9904f09))
* **content-gate:** create layout and improve asset management ([#4399](https://github.com/Automattic/newspack-plugin/issues/4399)) ([edf4613](https://github.com/Automattic/newspack-plugin/commit/edf461399828a729cf93102a3fa459b5757e40b7))
* **content-gate:** new schema and layout trait ([#4370](https://github.com/Automattic/newspack-plugin/issues/4370)) ([abd748e](https://github.com/Automattic/newspack-plugin/commit/abd748e855398f630f4bc1274495b276c9bef0f3))
* **content-gating:** add group subscription product options ([#4371](https://github.com/Automattic/newspack-plugin/issues/4371)) ([f715be5](https://github.com/Automattic/newspack-plugin/commit/f715be55ed0d45b5f55893a67b30581a83e188ec))
* enable authors filter ([#4393](https://github.com/Automattic/newspack-plugin/issues/4393)) ([e2e0d7f](https://github.com/Automattic/newspack-plugin/commit/e2e0d7fbabf39c323bb8f01283c32034b3455a88))
* **group-subscriptions:** handle group subscription members ([#4397](https://github.com/Automattic/newspack-plugin/issues/4397)) ([da5f2fa](https://github.com/Automattic/newspack-plugin/commit/da5f2fad5cf2b310827ce49e2cc9ba3e1fba30ec))
* include prompt_title with newspack_popup_id for data events ([#4377](https://github.com/Automattic/newspack-plugin/issues/4377)) ([83597ed](https://github.com/Automattic/newspack-plugin/commit/83597ed95204fd2311211b54761d82fea498fa52))
* reinstate the Appearance > Editor link for block themes ([#4407](https://github.com/Automattic/newspack-plugin/issues/4407)) ([be897c4](https://github.com/Automattic/newspack-plugin/commit/be897c412a83c683262c62c9260a903e48d428bc))

## [6.29.5](https://github.com/Automattic/newspack-plugin/compare/v6.29.4...v6.29.5) (2026-01-29)


### Bug Fixes

* **subscriptions:** skip free trial tweak during switches ([#4432](https://github.com/Automattic/newspack-plugin/issues/4432)) ([4277a3c](https://github.com/Automattic/newspack-plugin/commit/4277a3c2b03795d17cef4b86d0abec80f7814e7b))

## [6.29.4](https://github.com/Automattic/newspack-plugin/compare/v6.29.3...v6.29.4) (2026-01-28)


### Bug Fixes

* **memberships:** allow free trial members to comment ([#4428](https://github.com/Automattic/newspack-plugin/issues/4428)) ([32d455e](https://github.com/Automattic/newspack-plugin/commit/32d455eb299a486011e71c22c2e564005b689e0d))

## [6.29.3](https://github.com/Automattic/newspack-plugin/compare/v6.29.2...v6.29.3) (2026-01-26)


### Bug Fixes

* **countdown-banner:** cache result of `is_enabled()` check ([#4422](https://github.com/Automattic/newspack-plugin/issues/4422)) ([19d27fd](https://github.com/Automattic/newspack-plugin/commit/19d27fdc1ced97b2cf0132c86263bf4ccd253e85))

## [6.29.2](https://github.com/Automattic/newspack-plugin/compare/v6.29.1...v6.29.2) (2026-01-22)


### Bug Fixes

* lite site updates ([#4417](https://github.com/Automattic/newspack-plugin/issues/4417)) ([b65e7bc](https://github.com/Automattic/newspack-plugin/commit/b65e7bc88d97ead83718e13ba68dfdaabcbfa5ea))

## [6.29.1](https://github.com/Automattic/newspack-plugin/compare/v6.29.0...v6.29.1) (2026-01-22)


### Bug Fixes

* **content-gate:** support zero paragraph excerpt ([#4408](https://github.com/Automattic/newspack-plugin/issues/4408)) ([56fb741](https://github.com/Automattic/newspack-plugin/commit/56fb7415ad0ca8189808dd5672c91095ce49a7d9))

# [6.29.0](https://github.com/Automattic/newspack-plugin/compare/v6.28.2...v6.29.0) (2026-01-19)


### Bug Fixes

* add default reader meta ([#4360](https://github.com/Automattic/newspack-plugin/issues/4360)) ([01564ad](https://github.com/Automattic/newspack-plugin/commit/01564ad51a3d06129ea7acf30b1c728bb8ac8d6d))
* **ads:** placement control value reset ([#4375](https://github.com/Automattic/newspack-plugin/issues/4375)) ([72d7b68](https://github.com/Automattic/newspack-plugin/commit/72d7b686544e73af3347c6f0ddce581215e64ed0))
* **autocomplete:** improve value parsing in AutocompleteTokenField and ListsControl components ([#4376](https://github.com/Automattic/newspack-plugin/issues/4376)) ([84e388d](https://github.com/Automattic/newspack-plugin/commit/84e388daba81f013fe9f939d1921a30994670e12))
* clarify the media credit placeholder image's labelling ([#4352](https://github.com/Automattic/newspack-plugin/issues/4352)) ([4420b65](https://github.com/Automattic/newspack-plugin/commit/4420b65c5df7d0db526eb2b2934085376928b5f5))
* **content-gating:** stop running `restrict_post` after gate is rendered ([#4368](https://github.com/Automattic/newspack-plugin/issues/4368)) ([8489a6c](https://github.com/Automattic/newspack-plugin/commit/8489a6c859f03603bbf0dba211ffb29d8057630c))
* fallback password reset URL ([#4382](https://github.com/Automattic/newspack-plugin/issues/4382)) ([06d1503](https://github.com/Automattic/newspack-plugin/commit/06d1503c886acfb9d7dd07e48237471a30b13f05))
* hide 'trashed' subscriptions from My Account ([#4351](https://github.com/Automattic/newspack-plugin/issues/4351)) ([e759378](https://github.com/Automattic/newspack-plugin/commit/e759378bc0564a91fc6cb661fe052fde5584800a))
* **my-account:** top position on desktop ([#4355](https://github.com/Automattic/newspack-plugin/issues/4355)) ([1b3255a](https://github.com/Automattic/newspack-plugin/commit/1b3255a4986bc4b6507ae7ee787215038e1f4829))
* prevent doubled URL in reader registration block magic link ([#4379](https://github.com/Automattic/newspack-plugin/issues/4379)) ([ba4d855](https://github.com/Automattic/newspack-plugin/commit/ba4d855bc23a6116d947ee793f2cd874bb6acd6d))
* remove newspack-ui CSS class from my account when not logged in ([#4384](https://github.com/Automattic/newspack-plugin/issues/4384)) ([2bb8630](https://github.com/Automattic/newspack-plugin/commit/2bb86303367ed6daaef7632256663fa7fbacdd04))
* remove space if no time ([#4380](https://github.com/Automattic/newspack-plugin/issues/4380)) ([05c57e9](https://github.com/Automattic/newspack-plugin/commit/05c57e93b6cef9977179fec365981a73c1fba129))
* select specific product for content gifting and countdown banners ([#4366](https://github.com/Automattic/newspack-plugin/issues/4366)) ([809c230](https://github.com/Automattic/newspack-plugin/commit/809c23043f0c7e57566a1643624714fd97beaeb0))
* **sso:** change Jetpack configuration notice from error to warning ([#4383](https://github.com/Automattic/newspack-plugin/issues/4383)) ([e5e0643](https://github.com/Automattic/newspack-plugin/commit/e5e06431f72f8f4abf8c79ff2d1c401a8b12cdd4))
* **subscriptions:** remove actions from gifted subscription ([#4362](https://github.com/Automattic/newspack-plugin/issues/4362)) ([8fbc243](https://github.com/Automattic/newspack-plugin/commit/8fbc24352c588412498c1762de974d1e43814a7e))
* **update-payment-notice:** don't render on checkout ([#4356](https://github.com/Automattic/newspack-plugin/issues/4356)) ([dd80c06](https://github.com/Automattic/newspack-plugin/commit/dd80c06b440144276f2be3f83b85b269fbcbcb90))


### Features

* Add Managed Nextdoor Auth ([#4359](https://github.com/Automattic/newspack-plugin/issues/4359)) ([fee2480](https://github.com/Automattic/newspack-plugin/commit/fee248049bf4235921dbb67b2806b663e643a75d))
* add media credits to RSS feeds ([#4357](https://github.com/Automattic/newspack-plugin/issues/4357)) ([e638447](https://github.com/Automattic/newspack-plugin/commit/e638447fd5e0346b53eb78719401a2b0fd36ed7d))
* **content-gating:** add Content Gate settings page ([#4363](https://github.com/Automattic/newspack-plugin/issues/4363)) ([822c363](https://github.com/Automattic/newspack-plugin/commit/822c36337d73c74becae1db13d73e142344cd830))
* **content-gating:** enable content rule exclusions ([#4346](https://github.com/Automattic/newspack-plugin/issues/4346)) ([1e5718b](https://github.com/Automattic/newspack-plugin/commit/1e5718b5b540c7ed48e42df8160c463f678bfb05))
* **content-gating:** update content gates UI ([#4345](https://github.com/Automattic/newspack-plugin/issues/4345)) ([4e22075](https://github.com/Automattic/newspack-plugin/commit/4e22075c4d4fd741de327d9b8ff867b080e4052f))
* rename newsletter tracking ([#4358](https://github.com/Automattic/newspack-plugin/issues/4358)) ([3da6434](https://github.com/Automattic/newspack-plugin/commit/3da643495cb734b41b4e2306ba9a97c87f13176d))
* **web-preview:** add ESC key support and dynamic titles for segments and prompts ([#4294](https://github.com/Automattic/newspack-plugin/issues/4294)) ([7238d2f](https://github.com/Automattic/newspack-plugin/commit/7238d2f47c0531cb29f8198d6cebfc651aef7b30))

## [6.28.2](https://github.com/Automattic/newspack-plugin/compare/v6.28.1...v6.28.2) (2026-01-15)


### Bug Fixes

* **memberships:** restrict commenting to relevant statuses ([#4396](https://github.com/Automattic/newspack-plugin/issues/4396)) ([a712266](https://github.com/Automattic/newspack-plugin/commit/a71226604982fb794008183e6bc23be50deb4f7a))

## [6.28.1](https://github.com/Automattic/newspack-plugin/compare/v6.28.0...v6.28.1) (2026-01-12)


### Bug Fixes

* return block markup when outputting corrections ([#4385](https://github.com/Automattic/newspack-plugin/issues/4385)) ([c542b6d](https://github.com/Automattic/newspack-plugin/commit/c542b6d43df7bee62831a3edc688e1405b59d115))

# [6.28.0](https://github.com/Automattic/newspack-plugin/compare/v6.27.4...v6.28.0) (2026-01-05)


### Bug Fixes

* **content-gating:** never gate special pages ([#4340](https://github.com/Automattic/newspack-plugin/issues/4340)) ([a4dcfdd](https://github.com/Automattic/newspack-plugin/commit/a4dcfdd99cebe7f37bc1c67c5d06a34d9382b085))
* **countdown-banner:** don't show on unrestricted posts ([#4349](https://github.com/Automattic/newspack-plugin/issues/4349)) ([66d2c94](https://github.com/Automattic/newspack-plugin/commit/66d2c947c4967c92f56c1ffbda291d3fe55f8139))
* **countdown-banner:** never show more views than total ([#4369](https://github.com/Automattic/newspack-plugin/issues/4369)) ([0ef3a24](https://github.com/Automattic/newspack-plugin/commit/0ef3a2404e89cfc6fede33e723e9a12de1f15f60))
* **indesign-export:** only register the attribute for allowed blocks ([#4330](https://github.com/Automattic/newspack-plugin/issues/4330)) ([e1801cb](https://github.com/Automattic/newspack-plugin/commit/e1801cb4fc23ed82098b934bed2796d81bb2e6b6))
* **my-account:** safe content argument to skip sanitization ([#4326](https://github.com/Automattic/newspack-plugin/issues/4326)) ([b066de5](https://github.com/Automattic/newspack-plugin/commit/b066de523e524ff50c1c340eff67aa3b0d3ecf20))
* **my-account:** set new payment method as default ([#4343](https://github.com/Automattic/newspack-plugin/issues/4343)) ([6ebcaf9](https://github.com/Automattic/newspack-plugin/commit/6ebcaf933b0fc05cdf5b538532f29b7526b86eeb))
* **my-account:** support dynamic content around shortcode ([#4328](https://github.com/Automattic/newspack-plugin/issues/4328)) ([36b9524](https://github.com/Automattic/newspack-plugin/commit/36b95246f8d55e7cf395ceea65e872765b86ae88))
* **subscription-tiers-modal:** skip private products ([#4337](https://github.com/Automattic/newspack-plugin/issues/4337)) ([564d803](https://github.com/Automattic/newspack-plugin/commit/564d8032858291b466ba41e6466511af2e124c9f))


### Features

* **content-gate:** content rules ([#4265](https://github.com/Automattic/newspack-plugin/issues/4265)) ([b5b8cd9](https://github.com/Automattic/newspack-plugin/commit/b5b8cd994fcb2cefc45aed26aae7a555a0d49450))
* **content-gate:** implement restriction rules ([#4251](https://github.com/Automattic/newspack-plugin/issues/4251)) ([4034103](https://github.com/Automattic/newspack-plugin/commit/4034103d217382f0aa9f6f9679d7a95420ef492d))
* metered content countdown banner ([#4315](https://github.com/Automattic/newspack-plugin/issues/4315)) ([c9a68cc](https://github.com/Automattic/newspack-plugin/commit/c9a68cc3acc1e3799641c9c75f83ed768a48d21f))
* **payment-notice:** detect equivalent subscription ([#4333](https://github.com/Automattic/newspack-plugin/issues/4333)) ([9a98889](https://github.com/Automattic/newspack-plugin/commit/9a988895b477c36555f6c4778a58f2c70a0407bf))
* **ras:** OAuth OTP flow improvements ([#4341](https://github.com/Automattic/newspack-plugin/issues/4341)) ([8b345fa](https://github.com/Automattic/newspack-plugin/commit/8b345fafcb927432cba0b0bd9b1a1b2e32e14b68))

## [6.27.4](https://github.com/Automattic/newspack-plugin/compare/v6.27.3...v6.27.4) (2025-12-17)


### Bug Fixes

* less aggressive free trial abuse prevention ([#4365](https://github.com/Automattic/newspack-plugin/issues/4365)) ([c613622](https://github.com/Automattic/newspack-plugin/commit/c6136228d3ec06865d5dbfc303435ca7d2a686f9))

## [6.27.3](https://github.com/Automattic/newspack-plugin/compare/v6.27.2...v6.27.3) (2025-12-16)


### Bug Fixes

* **recaptcha:** log verification responses and errors ([#4361](https://github.com/Automattic/newspack-plugin/issues/4361)) ([1a280fb](https://github.com/Automattic/newspack-plugin/commit/1a280fbfbab27c5ba8eb9d392a71764ce5a40876))

## [6.27.2](https://github.com/Automattic/newspack-plugin/compare/v6.27.1...v6.27.2) (2025-12-16)


### Bug Fixes

* **indesign-exporter:** caption and credit character encoding ([#4364](https://github.com/Automattic/newspack-plugin/issues/4364)) ([5868d27](https://github.com/Automattic/newspack-plugin/commit/5868d2789a2726f808674928c853ce9b2461711f))

## [6.27.1](https://github.com/Automattic/newspack-plugin/compare/v6.27.0...v6.27.1) (2025-12-08)


### Bug Fixes

* **subscriptions:** migrated subscription switch ([#4335](https://github.com/Automattic/newspack-plugin/issues/4335)) ([4438b9a](https://github.com/Automattic/newspack-plugin/commit/4438b9ab495d532783b102998a4d7b9e1ed20481))

# [6.27.0](https://github.com/Automattic/newspack-plugin/compare/v6.26.4...v6.27.0) (2025-12-08)


### Bug Fixes

* **content-gifting:** ux tweaks and other fixes ([#4318](https://github.com/Automattic/newspack-plugin/issues/4318)) ([952dd83](https://github.com/Automattic/newspack-plugin/commit/952dd834819ffc6219aa154dda65bad3f850e627))
* **contrib-meter:** ensure percentage follows bar progress when using linear style ([#4320](https://github.com/Automattic/newspack-plugin/issues/4320)) ([cbe8665](https://github.com/Automattic/newspack-plugin/commit/cbe8665db66058fcf2c711a6ac331cd251351f02))
* hide Content Gate CPT from WP Admin menu ([#4321](https://github.com/Automattic/newspack-plugin/issues/4321)) ([70bbe7e](https://github.com/Automattic/newspack-plugin/commit/70bbe7e019ee28f9022acc6224e38a6a8ab0f8ab))
* make some Collections spacing more specific to archive pages ([#4293](https://github.com/Automattic/newspack-plugin/issues/4293)) ([21daeaf](https://github.com/Automattic/newspack-plugin/commit/21daeafc79100d86533358b3b069a0b81ca2bcb1))
* **media:** move default image UI to Advanced Settings ([#4296](https://github.com/Automattic/newspack-plugin/issues/4296)) ([a22f06d](https://github.com/Automattic/newspack-plugin/commit/a22f06d21ab68209df245d905ce8c7387ae78f7b))
* **modal-checkout:** prevent session reload after new customer account ([#4310](https://github.com/Automattic/newspack-plugin/issues/4310)) ([89208fc](https://github.com/Automattic/newspack-plugin/commit/89208fcb2453427dde47ad2c31bea7031dd45c6d))
* **my-account:** add payment method query var ([#4325](https://github.com/Automattic/newspack-plugin/issues/4325)) ([a7f2cb1](https://github.com/Automattic/newspack-plugin/commit/a7f2cb1600a557e3416ca2fda6d7691cd2644d2c))
* **my-account:** select font size ([#4324](https://github.com/Automattic/newspack-plugin/issues/4324)) ([31a48c0](https://github.com/Automattic/newspack-plugin/commit/31a48c03dcf0a999f6579262c551a372f51be381))
* repeat button block styles in collections for WP 6.9 ([#4319](https://github.com/Automattic/newspack-plugin/issues/4319)) ([6e3c5f8](https://github.com/Automattic/newspack-plugin/commit/6e3c5f85307e8e6bbf3fbce43ff49c16c024f670))
* **subscription-modal:** limited subscriptions ([#4309](https://github.com/Automattic/newspack-plugin/issues/4309)) ([715f3c9](https://github.com/Automattic/newspack-plugin/commit/715f3c9898319905f1a148f42934f7b9b2089ce4))


### Features

* add autocomplete + token + multiselect component ([#4289](https://github.com/Automattic/newspack-plugin/issues/4289)) ([84a8eda](https://github.com/Automattic/newspack-plugin/commit/84a8edabc5b527faed2183d1af8fcf8e6067f401))
* **content-gifting:** custom article link expiration ([#4313](https://github.com/Automattic/newspack-plugin/issues/4313)) ([c00f054](https://github.com/Automattic/newspack-plugin/commit/c00f054004fc5fc85cd859e836ce5943a7491f9b))
* listen to gforms events for newsletters ([#4312](https://github.com/Automattic/newspack-plugin/issues/4312)) ([2f0e0e3](https://github.com/Automattic/newspack-plugin/commit/2f0e0e376a1caf10ac0115fc8ac83cc1b9441965))
* **my-account:** add "Product" column to the Subscriptions page ([#4323](https://github.com/Automattic/newspack-plugin/issues/4323)) ([ba91e4c](https://github.com/Automattic/newspack-plugin/commit/ba91e4c1055b0eb3e2e246e53e0d3de4c711f485))

## [6.26.4](https://github.com/Automattic/newspack-plugin/compare/v6.26.3...v6.26.4) (2025-12-04)


### Bug Fixes

* add thumbnail position to rss feeds ([#4336](https://github.com/Automattic/newspack-plugin/issues/4336)) ([cf4a668](https://github.com/Automattic/newspack-plugin/commit/cf4a668f7c502b0b4800f2d4da0c5610ab584652))

## [6.26.3](https://github.com/Automattic/newspack-plugin/compare/v6.26.2...v6.26.3) (2025-12-04)


### Bug Fixes

* workaround issue and conflict with teams ([#4342](https://github.com/Automattic/newspack-plugin/issues/4342)) ([c9b3f5c](https://github.com/Automattic/newspack-plugin/commit/c9b3f5cca05fd1f9507fd551f17047712c6dd2d8))

## [6.26.2](https://github.com/Automattic/newspack-plugin/compare/v6.26.1...v6.26.2) (2025-12-04)


### Bug Fixes

* hide block visibility from all blocks ([#4339](https://github.com/Automattic/newspack-plugin/issues/4339)) ([9cadf45](https://github.com/Automattic/newspack-plugin/commit/9cadf45d8b51df494f8f34b9191be5f9271a1fd1))

## [6.26.1](https://github.com/Automattic/newspack-plugin/compare/v6.26.0...v6.26.1) (2025-12-03)


### Bug Fixes

* Hotfix/active subscription checks ([#4334](https://github.com/Automattic/newspack-plugin/issues/4334)) ([486a1e7](https://github.com/Automattic/newspack-plugin/commit/486a1e766f506d0103234a93e7a05c21df0da351))

# [6.26.0](https://github.com/Automattic/newspack-plugin/compare/v6.25.1...v6.26.0) (2025-12-02)


### Features

* **content-gifting:** metering support and CTA auth links ([#4331](https://github.com/Automattic/newspack-plugin/issues/4331)) ([8d5f500](https://github.com/Automattic/newspack-plugin/commit/8d5f50058fc5661f985066306cacc8d03f1c3d20))

## [6.25.1](https://github.com/Automattic/newspack-plugin/compare/v6.25.0...v6.25.1) (2025-11-25)


### Bug Fixes

* **memberships:** use gate ID for metering meta key ([#4316](https://github.com/Automattic/newspack-plugin/issues/4316)) ([a190a47](https://github.com/Automattic/newspack-plugin/commit/a190a477ad7af079af4fd27659cc8f735f735f42))

# [6.25.0](https://github.com/Automattic/newspack-plugin/compare/v6.24.3...v6.25.0) (2025-11-24)


### Bug Fixes

* **content-gifting:** ux tweaks and other fixes ([#4318](https://github.com/Automattic/newspack-plugin/issues/4318)) ([ae2de8f](https://github.com/Automattic/newspack-plugin/commit/ae2de8f4cbdf1bd44f5418d7d4fccc468fb82c2d))
* **contrib-meter:** ensure percentage follows bar progress when using linear style ([#4320](https://github.com/Automattic/newspack-plugin/issues/4320)) ([3b9208b](https://github.com/Automattic/newspack-plugin/commit/3b9208b739997a5f56cab7e8eca435bef800740a))
* display an error when a Collection title already exists ([#4268](https://github.com/Automattic/newspack-plugin/issues/4268)) ([8055c83](https://github.com/Automattic/newspack-plugin/commit/8055c8339f3ea291a912a83b3a2f45e011000c44))
* **newspack-ui:** remove margin-top for helper text when inside input-card ([#4285](https://github.com/Automattic/newspack-plugin/issues/4285)) ([007d2c1](https://github.com/Automattic/newspack-plugin/commit/007d2c19d5ca54ba2cd988c02265553255a4c285))
* prevent spawning of Accessibility Statement pages ([#4237](https://github.com/Automattic/newspack-plugin/issues/4237)) ([e1d12b4](https://github.com/Automattic/newspack-plugin/commit/e1d12b481df8cb33391f0750405389747d1ecc75))
* repeat button block styles in collections for WP 6.9 ([#4319](https://github.com/Automattic/newspack-plugin/issues/4319)) ([e1386c9](https://github.com/Automattic/newspack-plugin/commit/e1386c927ed7814fecfbb0e1420b3e036c881e43))
* **stripe-gateway:** disable Link by default on new installs ([#4288](https://github.com/Automattic/newspack-plugin/issues/4288)) ([213a252](https://github.com/Automattic/newspack-plugin/commit/213a252b1b1af424687152e24a82ba93c0e12808))


### Features

* content gifting ([#4207](https://github.com/Automattic/newspack-plugin/issues/4207)) ([f0c4d8f](https://github.com/Automattic/newspack-plugin/commit/f0c4d8fbc312cbbf427fdfaa9747b144418e6a5d))
* content gifting ([#4207](https://github.com/Automattic/newspack-plugin/issues/4207)) ([3880833](https://github.com/Automattic/newspack-plugin/commit/38808330077008994eff46d59566fcef1c064571))
* **content-gifting:** custom article link expiration ([#4313](https://github.com/Automattic/newspack-plugin/issues/4313)) ([566ad6d](https://github.com/Automattic/newspack-plugin/commit/566ad6d5a23b50062f6f48417f3023f6db4b5836))
* **contrib-meter:** add contribution meter block ([#4305](https://github.com/Automattic/newspack-plugin/issues/4305)) ([5634314](https://github.com/Automattic/newspack-plugin/commit/56343140bee7f674353129997d442ec8d038d127))
* **contrib-meter:** add contribution meter block ([#4305](https://github.com/Automattic/newspack-plugin/issues/4305)) ([dc894bd](https://github.com/Automattic/newspack-plugin/commit/dc894bd130ca68667f345536df1f964c571a1b44))
* **contrib-meter:** create block patterns ([#4311](https://github.com/Automattic/newspack-plugin/issues/4311)) ([e318c01](https://github.com/Automattic/newspack-plugin/commit/e318c0178e901875f26400035b51cd3396749621))
* **contrib-meter:** update cache invalidation logic and end date ([#4314](https://github.com/Automattic/newspack-plugin/issues/4314)) ([d7b54aa](https://github.com/Automattic/newspack-plugin/commit/d7b54aa36ab26fadf9d6aea24a40dfa239bec147))
* **cover-fees:** support non-donation purchases ([#4298](https://github.com/Automattic/newspack-plugin/issues/4298)) ([87e126f](https://github.com/Automattic/newspack-plugin/commit/87e126f71b9518438f1ba97f2a54074cf886af88))
* fix user login problem ([f834b3a](https://github.com/Automattic/newspack-plugin/commit/f834b3a2e4911aa22652b63651754e6cdb63e721))
* merge remote-tracking branch 'origin/trunk' into alpha ([748702f](https://github.com/Automattic/newspack-plugin/commit/748702fc0d7c89294b1481a025bb5b0884e23c6a))
* **my-account:** update mobile navigation to use hamburger menu and display current page title ([#4303](https://github.com/Automattic/newspack-plugin/issues/4303)) ([764a7d3](https://github.com/Automattic/newspack-plugin/commit/764a7d3dc41bb86bfa0e970d2d6acfe070431ebc))
* **my-account:** use newspack-ui in the sidebar ([#4302](https://github.com/Automattic/newspack-plugin/issues/4302)) ([87cf937](https://github.com/Automattic/newspack-plugin/commit/87cf937d848f8690c923959cc3e430aee2aad19d))
* **newspack-icons:** add key ([#4278](https://github.com/Automattic/newspack-plugin/issues/4278)) ([f275328](https://github.com/Automattic/newspack-plugin/commit/f27532845616e39dd229db4a185c6e6dd38da98f))
* **newspack-ui-icons:** align icons with WordPress/Newspack set and use shared class ([#4281](https://github.com/Automattic/newspack-plugin/issues/4281)) ([52a0eac](https://github.com/Automattic/newspack-plugin/commit/52a0eacfd915f7eb9a822eb5e2d118fb630fc82d))
* **ras:** implement OAuth redirect handling for RAS login ([#4290](https://github.com/Automattic/newspack-plugin/issues/4290)) ([b84aae1](https://github.com/Automattic/newspack-plugin/commit/b84aae16740d1d9f8986168ed64e9c75bb8e0e81))
* **ras:** implement OAuth redirect handling for RAS login ([#4290](https://github.com/Automattic/newspack-plugin/issues/4290)) ([22586e1](https://github.com/Automattic/newspack-plugin/commit/22586e1deb47a9e5d472bf08ddf23a890e1731a7))
* **rss:** allow RSS feed customization with new extra tags filters ([#4231](https://github.com/Automattic/newspack-plugin/issues/4231)) ([34c235e](https://github.com/Automattic/newspack-plugin/commit/34c235e7ca5b98ed6b92a61afa5ae7ff85b218eb))
* **rss:** change rss media hook and use CDATA on media desc ([#4280](https://github.com/Automattic/newspack-plugin/issues/4280)) ([8292c7a](https://github.com/Automattic/newspack-plugin/commit/8292c7a0d8868510792a884079a5e6f4e28f5ae1))
* **subscriptions:** change frequency intervals strategy ([#4301](https://github.com/Automattic/newspack-plugin/issues/4301)) ([b652dba](https://github.com/Automattic/newspack-plugin/commit/b652dbaa2a79e2ff4bf015e677aee780b5d58208))
* trigger alpha release ([617fa29](https://github.com/Automattic/newspack-plugin/commit/617fa2914bf6e16f909cc51bca68e840029b3127))
* **ui:** spinner tweaks ([#4300](https://github.com/Automattic/newspack-plugin/issues/4300)) ([31f3e5e](https://github.com/Automattic/newspack-plugin/commit/31f3e5eb8519715b61e03f0d9651f34a42dcb24a))
* **variation-modal:** display variation description ([#4286](https://github.com/Automattic/newspack-plugin/issues/4286)) ([0f5e017](https://github.com/Automattic/newspack-plugin/commit/0f5e017f66353ea89a972f0a3d5005b6e007a07f))
* **web-preview:** update components with new dropdown menu and title ([#4292](https://github.com/Automattic/newspack-plugin/issues/4292)) ([6015eae](https://github.com/Automattic/newspack-plugin/commit/6015eae8cd2ae58468ef6fd808e6032e9d45b461))

## [6.24.3](https://github.com/Automattic/newspack-plugin/compare/v6.24.2...v6.24.3) (2025-11-13)


### Bug Fixes

* **nrh:** validation error in Donate block when no woocommerce ([#4297](https://github.com/Automattic/newspack-plugin/issues/4297)) ([224fcea](https://github.com/Automattic/newspack-plugin/commit/224fcea40cee7b2453cc82e9121ce629d5745186))

## [6.24.2](https://github.com/Automattic/newspack-plugin/compare/v6.24.1...v6.24.2) (2025-11-13)


### Bug Fixes

* content gate metadata methods and params ([d6344b2](https://github.com/Automattic/newspack-plugin/commit/d6344b29d1f83009c1ca9f680141906920c85351))
* content gate metadata methods and params [#4306](https://github.com/Automattic/newspack-plugin/issues/4306) from Automattic/hotfix/gate-post-metadata-nppm-2389 ([96615a1](https://github.com/Automattic/newspack-plugin/commit/96615a1368b569387cabc927f1c735c210351655))

## [6.24.1](https://github.com/Automattic/newspack-plugin/compare/v6.24.0...v6.24.1) (2025-11-10)


### Bug Fixes

* **memberships-gate:** incorrect ternery operator fails to get stored gate ID ([#4299](https://github.com/Automattic/newspack-plugin/issues/4299)) ([3873aa1](https://github.com/Automattic/newspack-plugin/commit/3873aa1b91518d90b35db88efbe7965602b9cd17))

# [6.24.0](https://github.com/Automattic/newspack-plugin/compare/v6.23.0...v6.24.0) (2025-11-10)


### Bug Fixes

* **action-card:** don't create new component for its children ([#4267](https://github.com/Automattic/newspack-plugin/issues/4267)) ([5cc6c55](https://github.com/Automattic/newspack-plugin/commit/5cc6c55b1de2a655ff37a9fda14e840f0b8d81c3))
* draggable collapsable action card styles ([#4254](https://github.com/Automattic/newspack-plugin/issues/4254)) ([e19bc49](https://github.com/Automattic/newspack-plugin/commit/e19bc499ec02cd753a04edcda56039df92c958b0))
* handle TS in components ([#4259](https://github.com/Automattic/newspack-plugin/issues/4259)) ([c7a613f](https://github.com/Automattic/newspack-plugin/commit/c7a613f157a738efdc6a68d1ab1780d9973bbb5b))
* **memberships-for-teams:** sync contact data for team members ([#4226](https://github.com/Automattic/newspack-plugin/issues/4226)) ([2fb974c](https://github.com/Automattic/newspack-plugin/commit/2fb974c26141a01d30ff88947d30dc05c00d4b7c))
* **nextdoor:** wizard component dependencies ([#4272](https://github.com/Automattic/newspack-plugin/issues/4272)) ([97f3753](https://github.com/Automattic/newspack-plugin/commit/97f375358f69fd0649a7b884e595ff1538c7566f))
* prevent an infinite loop/error transforming galleries to image blocks when they have credits ([1e402be](https://github.com/Automattic/newspack-plugin/commit/1e402be9d46958656b7d5254ecd65d8a0cb3cc6a))
* update content gate wizard ui ([#4252](https://github.com/Automattic/newspack-plugin/issues/4252)) ([8f4d40c](https://github.com/Automattic/newspack-plugin/commit/8f4d40c07a90a00729d33237d12f9fc375457f96))


### Features

* add content gate settings array ([#4249](https://github.com/Automattic/newspack-plugin/issues/4249)) ([b03243f](https://github.com/Automattic/newspack-plugin/commit/b03243f4281d03b9af807f0b9b73976898276d59))
* add content gate settings skeleton ([#4246](https://github.com/Automattic/newspack-plugin/issues/4246)) ([82f0946](https://github.com/Automattic/newspack-plugin/commit/82f094653856336ae6dee13d550a361dbda1b70b))
* add drag to action card component ([#4248](https://github.com/Automattic/newspack-plugin/issues/4248)) ([fe6b912](https://github.com/Automattic/newspack-plugin/commit/fe6b912df19fc75da4e572385f5d903cd86d0d7e))
* **content-gate:** decouple content gate from WC Memberships ([#4196](https://github.com/Automattic/newspack-plugin/issues/4196)) ([495fb18](https://github.com/Automattic/newspack-plugin/commit/495fb184c95df13c8c85db02b2d2d4f148343480))
* **content-gating:** access rules management ([#4202](https://github.com/Automattic/newspack-plugin/issues/4202)) ([b6bc817](https://github.com/Automattic/newspack-plugin/commit/b6bc81796660cc91cf8bc9b4837c1a7b8b152048))
* **newspack-icons:** add activity, output, target; replace countdown ([#4274](https://github.com/Automattic/newspack-plugin/issues/4274)) ([cdb7791](https://github.com/Automattic/newspack-plugin/commit/cdb779138e0b094f357e186050e0c098ef6d9efe))
* **newspack-icons:** add new `hand` icon ([#4247](https://github.com/Automattic/newspack-plugin/issues/4247)) ([ea8601a](https://github.com/Automattic/newspack-plugin/commit/ea8601ac4fa907fbba2b3e77df6bc6b8d4650445))
* Nextdoor Integration ([#4165](https://github.com/Automattic/newspack-plugin/issues/4165)) ([bd79457](https://github.com/Automattic/newspack-plugin/commit/bd79457100fff36632a81ee5398cf02be9d61435))
* remove nextdoor feature flag ([#4270](https://github.com/Automattic/newspack-plugin/issues/4270)) ([928a8c0](https://github.com/Automattic/newspack-plugin/commit/928a8c06a82294d1ed268554b6150d55c1215cb3))

# [6.23.0](https://github.com/Automattic/newspack-plugin/compare/v6.22.3...v6.23.0) (2025-11-10)


### Features

* **subscription-tiers:** introduce "Sign In" link ([#4271](https://github.com/Automattic/newspack-plugin/issues/4271)) ([cc2df73](https://github.com/Automattic/newspack-plugin/commit/cc2df73e7aafa1e5fe39c299ce0849c572b0c4ed))

## [6.22.3](https://github.com/Automattic/newspack-plugin/compare/v6.22.2...v6.22.3) (2025-11-05)


### Bug Fixes

* **ga4:** always send reader-related custom params ([#4282](https://github.com/Automattic/newspack-plugin/issues/4282)) ([efb08eb](https://github.com/Automattic/newspack-plugin/commit/efb08eb391f5b2a3f2655f8f4117d5073492c0ea))

## [6.22.2](https://github.com/Automattic/newspack-plugin/compare/v6.22.1...v6.22.2) (2025-11-05)


### Bug Fixes

* **my-account:** support variable subscription when switching ([#4284](https://github.com/Automattic/newspack-plugin/issues/4284)) ([991cbc5](https://github.com/Automattic/newspack-plugin/commit/991cbc5698a5371957112b9793ff2239eb7233ad))

## [6.22.1](https://github.com/Automattic/newspack-plugin/compare/v6.22.0...v6.22.1) (2025-11-05)


### Bug Fixes

* **my-account:** filter the My Account version ([025c3bb](https://github.com/Automattic/newspack-plugin/commit/025c3bb2594e26cfd5a64aa59ae046ac24bc1149))

# [6.22.0](https://github.com/Automattic/newspack-plugin/compare/v6.21.4...v6.22.0) (2025-10-30)


### Bug Fixes

* add feature flag to countdown block and email enhancements ([#4262](https://github.com/Automattic/newspack-plugin/issues/4262)) ([b8691d8](https://github.com/Automattic/newspack-plugin/commit/b8691d8ecc3ea20ee6250c3982dd72cf6c9fe372))
* **collections:** remove collection-related taxonomies from quick edit ([#4222](https://github.com/Automattic/newspack-plugin/issues/4222)) ([e327230](https://github.com/Automattic/newspack-plugin/commit/e3272301556c3f038ed37c410bcb98be11bd2518))
* **content-gate-countdown-block:** update block design ([fda287c](https://github.com/Automattic/newspack-plugin/commit/fda287c09d91e3d19d707af7e68aa214567ed115))
* ga4 events for gate interactions and tiered modal ([#4209](https://github.com/Automattic/newspack-plugin/issues/4209)) ([162f34a](https://github.com/Automattic/newspack-plugin/commit/162f34aaf0e1ad05376eb06b68d2fac7ab90f67f))
* keep prevent one and two col Collections block grid from being increased on smaller screens ([#4224](https://github.com/Automattic/newspack-plugin/issues/4224)) ([482eef6](https://github.com/Automattic/newspack-plugin/commit/482eef6913f63bc7519847c4b2570367e6b5614a))
* **lite-site:** allow line breaks ([#4221](https://github.com/Automattic/newspack-plugin/issues/4221)) ([ead3283](https://github.com/Automattic/newspack-plugin/commit/ead328335a9ce1940012a77d7abd82a985340cb0))
* **metering:** get current user metered views ([#4227](https://github.com/Automattic/newspack-plugin/issues/4227)) ([ebc32b7](https://github.com/Automattic/newspack-plugin/commit/ebc32b730171a2c6f044bb517a229e34c6f8c686))
* **my-account:** new payment method style tweaks ([#4220](https://github.com/Automattic/newspack-plugin/issues/4220)) ([88a638c](https://github.com/Automattic/newspack-plugin/commit/88a638c974d61fa692d46d1961e74fc80e8d1bfa))
* **subscription-switch:** unhook direction text from checkout ([#4229](https://github.com/Automattic/newspack-plugin/issues/4229)) ([69915ef](https://github.com/Automattic/newspack-plugin/commit/69915efa4cb626c652e64bf50d002111724f4f8e))
* **subscriptions-tiers:** standardize product input name ([#4261](https://github.com/Automattic/newspack-plugin/issues/4261)) ([4194267](https://github.com/Automattic/newspack-plugin/commit/41942671283eaf26c59b9de22305d531aa400822))
* tweak configuration labels for the primary subscription product ([#4260](https://github.com/Automattic/newspack-plugin/issues/4260)) ([ab5a5c7](https://github.com/Automattic/newspack-plugin/commit/ab5a5c74fb90498a48780ac15a184ce983af67e5))
* **upgrade-subscription-link:** remove query arg onload ([#4263](https://github.com/Automattic/newspack-plugin/issues/4263)) ([87fe3d4](https://github.com/Automattic/newspack-plugin/commit/87fe3d4d14bfcb349fc6df568a6046fb08c7124d))


### Features

* **audience:** primary subscription product ([#4191](https://github.com/Automattic/newspack-plugin/issues/4191)) ([1137770](https://github.com/Automattic/newspack-plugin/commit/1137770f4badf58b7fa6f92b7846fe57670a2e73))
* **collections:** add archive link in settings page ([#4203](https://github.com/Automattic/newspack-plugin/issues/4203)) ([8aad8ca](https://github.com/Automattic/newspack-plugin/commit/8aad8ca995a8bf9daeac45140c8e5b95e14bd5ac))
* **collections:** add css classes to meta elements ([#4208](https://github.com/Automattic/newspack-plugin/issues/4208)) ([2a13af6](https://github.com/Automattic/newspack-plugin/commit/2a13af6615a245a63580db0549a90956d9b9919d))
* **collections:** implement design feedback for archive link ([#4219](https://github.com/Automattic/newspack-plugin/issues/4219)) ([da35672](https://github.com/Automattic/newspack-plugin/commit/da356720db14ab4a5863fd7db6fcfb8bee986158))
* **InDesign:** inline image caption, horizontal rule, headings and blockquotes ([#4201](https://github.com/Automattic/newspack-plugin/issues/4201)) ([c39b9f9](https://github.com/Automattic/newspack-plugin/commit/c39b9f9bc4810903ebaf629336ea166a31410df3))
* **my-account:** add delete payment method modal confirmation ([#4216](https://github.com/Automattic/newspack-plugin/issues/4216)) ([3220af2](https://github.com/Automattic/newspack-plugin/commit/3220af2b31e2945ef8cf152346da767a51258418))
* **my-account:** addresses ([#4206](https://github.com/Automattic/newspack-plugin/issues/4206)) ([d57b1c9](https://github.com/Automattic/newspack-plugin/commit/d57b1c91066faabd3adb03ff04f165fb7315a3b5))
* **my-account:** edit donation amount ([#4200](https://github.com/Automattic/newspack-plugin/issues/4200)) ([f84d121](https://github.com/Automattic/newspack-plugin/commit/f84d1213541e91afa63fe8e111fa3de4195323bd))
* **newspack-icons:** add ai and ai-text to npm package ([#4235](https://github.com/Automattic/newspack-plugin/issues/4235)) ([06789a9](https://github.com/Automattic/newspack-plugin/commit/06789a9c8eaa03ec2201b3798a9087a06197b2b7))
* **newspack-icons:** add typescript support; add new icons ([#4088](https://github.com/Automattic/newspack-plugin/issues/4088)) ([bb2cb82](https://github.com/Automattic/newspack-plugin/commit/bb2cb82fed48f053118e1886f18f23c794ee28dc))
* validate donation products ([#4163](https://github.com/Automattic/newspack-plugin/issues/4163)) ([de24ff4](https://github.com/Automattic/newspack-plugin/commit/de24ff42f447f48ee420cff9a51e3e9e93981ae5))
* **woocommerce:** enable block emails ([#4218](https://github.com/Automattic/newspack-plugin/issues/4218)) ([0643158](https://github.com/Automattic/newspack-plugin/commit/06431589a815609f21d28559a78a489e9b4e7850))

## [6.21.4](https://github.com/Automattic/newspack-plugin/compare/v6.21.3...v6.21.4) (2025-10-27)


### Bug Fixes

* add corrections on earlier the_content filter ([#4258](https://github.com/Automattic/newspack-plugin/issues/4258)) ([95c584a](https://github.com/Automattic/newspack-plugin/commit/95c584a81b8a811a57de1616b5aec716895a88e4))

## [6.21.3](https://github.com/Automattic/newspack-plugin/compare/v6.21.2...v6.21.3) (2025-10-27)


### Bug Fixes

* hide dummy email ([#4255](https://github.com/Automattic/newspack-plugin/issues/4255)) ([d302e20](https://github.com/Automattic/newspack-plugin/commit/d302e20aff7748ce8fa54be900cc92624fbddbdb))

## [6.21.2](https://github.com/Automattic/newspack-plugin/compare/v6.21.1...v6.21.2) (2025-10-16)


### Bug Fixes

* don’t add overlays to ras store if locked via front-end emtering ([#4230](https://github.com/Automattic/newspack-plugin/issues/4230)) ([de89d33](https://github.com/Automattic/newspack-plugin/commit/de89d337be471eb786290f885edaa342178aabe9))

## [6.21.1](https://github.com/Automattic/newspack-plugin/compare/v6.21.0...v6.21.1) (2025-10-15)


### Bug Fixes

* order meta method shouldn use ID ([#4236](https://github.com/Automattic/newspack-plugin/issues/4236)) ([354618d](https://github.com/Automattic/newspack-plugin/commit/354618d890fa7c791f6007a018f528504a6b38bf))

# [6.21.0](https://github.com/Automattic/newspack-plugin/compare/v6.20.2...v6.21.0) (2025-10-15)


### Features

* **InDesign:** inline image caption, horizontal rule, headings and blockquotes ([#4201](https://github.com/Automattic/newspack-plugin/issues/4201)) ([#4228](https://github.com/Automattic/newspack-plugin/issues/4228)) ([ff7af5c](https://github.com/Automattic/newspack-plugin/commit/ff7af5c83e91f093113abf192fb82ede8a4b2528))

## [6.20.2](https://github.com/Automattic/newspack-plugin/compare/v6.20.1...v6.20.2) (2025-10-14)


### Bug Fixes

* **gam:** account for undefined path in wizard ([#4233](https://github.com/Automattic/newspack-plugin/issues/4233)) ([d9c515e](https://github.com/Automattic/newspack-plugin/commit/d9c515ea8b542400828a3845cc8736f944b716bc))

## [6.20.1](https://github.com/Automattic/newspack-plugin/compare/v6.20.0...v6.20.1) (2025-10-08)


### Bug Fixes

* **collections:** quick edit hotfix ([#4225](https://github.com/Automattic/newspack-plugin/issues/4225)) ([e8423de](https://github.com/Automattic/newspack-plugin/commit/e8423de00d7f2d54bc92c5c8e42316e056bfd22a))

# [6.20.0](https://github.com/Automattic/newspack-plugin/compare/v6.19.0...v6.20.0) (2025-10-06)


### Bug Fixes

* ga4 events for gate interactions and tiered modal ([#4209](https://github.com/Automattic/newspack-plugin/issues/4209)) ([2d35768](https://github.com/Automattic/newspack-plugin/commit/2d3576801dfa473a89f26e760c7b9e6c01a60dc1))
* Improve help text for Guest Contributor checkbox ([#4187](https://github.com/Automattic/newspack-plugin/issues/4187)) ([5790f3d](https://github.com/Automattic/newspack-plugin/commit/5790f3da197d56b5fea8e085b926bfc6d627525c))
* newspack-plugin delay ([#4184](https://github.com/Automattic/newspack-plugin/issues/4184)) ([22e8dc2](https://github.com/Automattic/newspack-plugin/commit/22e8dc275c683ff35b934dcfd1df1ad102d49196))
* remove content gate countdown block ([0204e58](https://github.com/Automattic/newspack-plugin/commit/0204e589beff18928ce703d07461405893e889f3))
* update download URL for db.php ([#4193](https://github.com/Automattic/newspack-plugin/issues/4193)) ([4d363db](https://github.com/Automattic/newspack-plugin/commit/4d363dbfa6617c9780b2c163696cf8f746fef9b6))


### Features

* **collections:** add archive link in settings page ([#4203](https://github.com/Automattic/newspack-plugin/issues/4203)) ([42694ec](https://github.com/Automattic/newspack-plugin/commit/42694eccc6df540262cfd25c02b5a6e8439eb892))
* **collections:** add Collections block ([#4166](https://github.com/Automattic/newspack-plugin/issues/4166)) ([1185157](https://github.com/Automattic/newspack-plugin/commit/1185157263da3d44ebf74e7f4f386413c1843b23))
* **collections:** add css classes to meta elements ([#4208](https://github.com/Automattic/newspack-plugin/issues/4208)) ([7fbf7e9](https://github.com/Automattic/newspack-plugin/commit/7fbf7e91c2279ddec041ac3385c1d6aefd8b18f7))
* **collections:** add logic for opening links in new tabs ([#4174](https://github.com/Automattic/newspack-plugin/issues/4174)) ([07a5545](https://github.com/Automattic/newspack-plugin/commit/07a5545185c0bae1958ee496e31b255991b822ff))
* **collections:** collections block feedback ([#4185](https://github.com/Automattic/newspack-plugin/issues/4185)) ([0d0210c](https://github.com/Automattic/newspack-plugin/commit/0d0210ca2e62a6f2719827d28dda452c423f3e0c))
* **collections:** remove feature flag ([#4195](https://github.com/Automattic/newspack-plugin/issues/4195)) ([b1619ef](https://github.com/Automattic/newspack-plugin/commit/b1619efd520fe5b0b93c0c14bb0b5c978736b8a5))
* **collections:** replace archive grid with collections block ([#4178](https://github.com/Automattic/newspack-plugin/issues/4178)) ([d0cbadd](https://github.com/Automattic/newspack-plugin/commit/d0cbaddad4484e11d3b7fe827d3ce4998e6d5473))
* **content-gate:** add countdown block ([#4176](https://github.com/Automattic/newspack-plugin/issues/4176)) ([f8fe757](https://github.com/Automattic/newspack-plugin/commit/f8fe757543b34ec5ef12636ccf23abd35b7dbf42))
* **my-account:** subscription switch modal ([#4177](https://github.com/Automattic/newspack-plugin/issues/4177)) ([28c26e7](https://github.com/Automattic/newspack-plugin/commit/28c26e77516c075629bd4cb81d0ea2fc17633323))
* subscription tier modal ([#4164](https://github.com/Automattic/newspack-plugin/issues/4164)) ([4d6ebe2](https://github.com/Automattic/newspack-plugin/commit/4d6ebe2d551d9967d4c92251d6642a32ac652374))

# [6.19.0](https://github.com/Automattic/newspack-plugin/compare/v6.18.3...v6.19.0) (2025-09-22)


### Bug Fixes

* **indesign-export:** remove feature flag ([#4180](https://github.com/Automattic/newspack-plugin/issues/4180)) ([e3c5c7e](https://github.com/Automattic/newspack-plugin/commit/e3c5c7ef29e01fa4c059b83fbe7730b5f6a19a89))
* **my-account:** missing variable and template hook priority ([#4150](https://github.com/Automattic/newspack-plugin/issues/4150)) ([9886618](https://github.com/Automattic/newspack-plugin/commit/9886618acc6c38b77af305d78ad9b6cbceaa9ed9))
* **newspack-ui:** border radius and padding for buttons, modals, and segmented controls ([#4162](https://github.com/Automattic/newspack-plugin/issues/4162)) ([be750ef](https://github.com/Automattic/newspack-plugin/commit/be750efdb79f8779031ce0c650bb032a0e27798d))
* register with empty name fields ([#4175](https://github.com/Automattic/newspack-plugin/issues/4175)) ([7d6680c](https://github.com/Automattic/newspack-plugin/commit/7d6680ca72d66c440d10108c056a07339bf83d99))


### Features

* **collections:** add Collections block ([#4166](https://github.com/Automattic/newspack-plugin/issues/4166)) ([ea0917b](https://github.com/Automattic/newspack-plugin/commit/ea0917b6638c0f84cacecba2ee67dbc634212478))
* **collections:** add logic for opening links in new tabs ([#4174](https://github.com/Automattic/newspack-plugin/issues/4174)) ([ab71461](https://github.com/Automattic/newspack-plugin/commit/ab714610296d7c4bab74439141e6d169ebfc69d4))
* **collections:** collections block feedback ([#4185](https://github.com/Automattic/newspack-plugin/issues/4185)) ([2f203c1](https://github.com/Automattic/newspack-plugin/commit/2f203c1276efa18cfc80d2b861782aa7dea60a1a))
* **collections:** replace archive grid with collections block ([#4178](https://github.com/Automattic/newspack-plugin/issues/4178)) ([d601445](https://github.com/Automattic/newspack-plugin/commit/d601445d13c796d487ca7a5e7952c6cbfd44578e))
* **newspack-ui:** add standalone dropdown button; reorganise dropdown box; add generic spacing ([#4169](https://github.com/Automattic/newspack-plugin/issues/4169)) ([863da1e](https://github.com/Automattic/newspack-plugin/commit/863da1e75ce325b505c170f904112cf603cb86bf))
* **woocommerce:** add custom currency symbol option ([#4155](https://github.com/Automattic/newspack-plugin/issues/4155)) ([8811a7e](https://github.com/Automattic/newspack-plugin/commit/8811a7ed84154710917cc4b6d9cafc53831da680))

## [6.18.3](https://github.com/Automattic/newspack-plugin/compare/v6.18.2...v6.18.3) (2025-09-19)


### Bug Fixes

* keep statuses when saving linked memberships ([#4194](https://github.com/Automattic/newspack-plugin/issues/4194)) ([f5417ac](https://github.com/Automattic/newspack-plugin/commit/f5417acbac3210ec51cc0630d698fe5cbf25d532))

## [6.18.2](https://github.com/Automattic/newspack-plugin/compare/v6.18.1...v6.18.2) (2025-09-18)


### Bug Fixes

* change sitekit check cadence ([5b2cd55](https://github.com/Automattic/newspack-plugin/commit/5b2cd552cf225d18bea2128979459c8ef6d8ebdc))

## [6.18.1](https://github.com/Automattic/newspack-plugin/compare/v6.18.0...v6.18.1) (2025-09-16)


### Bug Fixes

* **bylines:** display inline name for missing users ([#4190](https://github.com/Automattic/newspack-plugin/issues/4190)) ([0b2b5e3](https://github.com/Automattic/newspack-plugin/commit/0b2b5e39a21e3797d2749ef142f633734b32c35c))

# [6.18.0](https://github.com/Automattic/newspack-plugin/compare/v6.17.1...v6.18.0) (2025-09-08)


### Bug Fixes

* correctly filter sync mailchimp audience ([#4147](https://github.com/Automattic/newspack-plugin/issues/4147)) ([6eb019e](https://github.com/Automattic/newspack-plugin/commit/6eb019e7af5e176de4161d3ad4647a4badd8d34e))
* correctly identify lists by id ([#4160](https://github.com/Automattic/newspack-plugin/issues/4160)) ([ffbb8ed](https://github.com/Automattic/newspack-plugin/commit/ffbb8ed733f2d4cf04c2a597b95c2960fe184fd2))


### Features

* add feed support to custom bylines ([#4152](https://github.com/Automattic/newspack-plugin/issues/4152)) ([ea7b6fb](https://github.com/Automattic/newspack-plugin/commit/ea7b6fba6d4487ddfd220b6fb838c6f87bbb312b))
* add guardrails to distributed bylines ([#4151](https://github.com/Automattic/newspack-plugin/issues/4151)) ([59e7fdf](https://github.com/Automattic/newspack-plugin/commit/59e7fdf7288f8399cadd033c837384028c502a6e))
* **pwa:** display mode setting ([#4154](https://github.com/Automattic/newspack-plugin/issues/4154)) ([bfba20f](https://github.com/Automattic/newspack-plugin/commit/bfba20f7c64afb76cfef1607c5d600031e9da0e0))

## [6.17.1](https://github.com/Automattic/newspack-plugin/compare/v6.17.0...v6.17.1) (2025-09-08)


### Bug Fixes

* make sure settingsTabs exists before checking for setup wizard ([#4170](https://github.com/Automattic/newspack-plugin/issues/4170)) ([7a5db33](https://github.com/Automattic/newspack-plugin/commit/7a5db339634f3c5ba4ab1a434c533093765f7671))

# [6.17.0](https://github.com/Automattic/newspack-plugin/compare/v6.16.5...v6.17.0) (2025-09-08)


### Features

* **indesign-export:** photo caption and credit ([#4167](https://github.com/Automattic/newspack-plugin/issues/4167)) ([26839fe](https://github.com/Automattic/newspack-plugin/commit/26839fe55878bd4aed375c8d9e6c39d93f3f9e4c))

## [6.16.5](https://github.com/Automattic/newspack-plugin/compare/v6.16.4...v6.16.5) (2025-09-02)


### Bug Fixes

* **collections:** update all category/year url params ([#4168](https://github.com/Automattic/newspack-plugin/issues/4168)) ([e829dfb](https://github.com/Automattic/newspack-plugin/commit/e829dfb68033c026a2c72be65a41f558a909e9f2)), closes [#4153](https://github.com/Automattic/newspack-plugin/issues/4153)

## [6.16.4](https://github.com/Automattic/newspack-plugin/compare/v6.16.3...v6.16.4) (2025-09-02)


### Bug Fixes

* **collections:** make filter params unique ([#4153](https://github.com/Automattic/newspack-plugin/issues/4153)) ([87bb14c](https://github.com/Automattic/newspack-plugin/commit/87bb14c22ab97ac3fccfc3a5b823be1cc057f266))

## [6.16.3](https://github.com/Automattic/newspack-plugin/compare/v6.16.2...v6.16.3) (2025-09-01)


### Bug Fixes

* **indesign:** exporter formatting fixes ([#4158](https://github.com/Automattic/newspack-plugin/issues/4158)) ([a680c21](https://github.com/Automattic/newspack-plugin/commit/a680c21964ccec5facab4ebedcdb718d711cd94d))

## [6.16.2](https://github.com/Automattic/newspack-plugin/compare/v6.16.1...v6.16.2) (2025-08-29)


### Bug Fixes

* update markup around Collections header ([#4157](https://github.com/Automattic/newspack-plugin/issues/4157)) ([aa88c76](https://github.com/Automattic/newspack-plugin/commit/aa88c76392b160b0385d3629323ed5655583f498))

## [6.16.1](https://github.com/Automattic/newspack-plugin/compare/v6.16.0...v6.16.1) (2025-08-26)


### Bug Fixes

* dont do any checks before init ([#4159](https://github.com/Automattic/newspack-plugin/issues/4159)) ([930e6c6](https://github.com/Automattic/newspack-plugin/commit/930e6c69ee8154f57d6932fcde77a2f45ac78f0a))

# [6.16.0](https://github.com/Automattic/newspack-plugin/compare/v6.15.3...v6.16.0) (2025-08-25)


### Bug Fixes

* lint issues and improve query condition for pattern filtering ([#4146](https://github.com/Automattic/newspack-plugin/issues/4146)) ([490896a](https://github.com/Automattic/newspack-plugin/commit/490896a6180adeff304d63c1d5027cd023fd4c3d))
* **newsletters:** update plugin API ([#4131](https://github.com/Automattic/newspack-plugin/issues/4131)) ([4383efa](https://github.com/Automattic/newspack-plugin/commit/4383efaa9f0e3a87c1dc4f839310755fcfa140b2))
* pattern categories count and add filter ([#4134](https://github.com/Automattic/newspack-plugin/issues/4134)) ([738edea](https://github.com/Automattic/newspack-plugin/commit/738edeac7b1a8c7561587096028f89d8e5c945fd))
* pattern categories count and add filter ([#4139](https://github.com/Automattic/newspack-plugin/issues/4139)) ([ca1815b](https://github.com/Automattic/newspack-plugin/commit/ca1815b4d36b540a6108d979d8c384daa024634f))
* **rss:** use consistent featured image size ([#4133](https://github.com/Automattic/newspack-plugin/issues/4133)) ([595184c](https://github.com/Automattic/newspack-plugin/commit/595184cc42101d0d08a10d91f89742c091a11568))
* sanitize newspack_reader_data_item fields on save ([#4138](https://github.com/Automattic/newspack-plugin/issues/4138)) ([a648e6d](https://github.com/Automattic/newspack-plugin/commit/a648e6df4d6560612f0cce9c6d18df31d52775fc))
* typo in RSS Enhancements ([#4132](https://github.com/Automattic/newspack-plugin/issues/4132)) ([2af30a5](https://github.com/Automattic/newspack-plugin/commit/2af30a5c3998b74c2d6dc29bc69f16c56f4b7036))
* **woocommerce:** hide duplicated tab headings ([#4142](https://github.com/Automattic/newspack-plugin/issues/4142)) ([a1869ae](https://github.com/Automattic/newspack-plugin/commit/a1869ae5f4d6b242dbea979c26463e7be61c16d0))


### Features

* add flag to disable sitekit check ([#4140](https://github.com/Automattic/newspack-plugin/issues/4140)) ([b68bd98](https://github.com/Automattic/newspack-plugin/commit/b68bd98b8f2945b83ea4cb0bf3792362e391c525))
* **ads:** parent ad unit inventory ([#4084](https://github.com/Automattic/newspack-plugin/issues/4084)) ([844e120](https://github.com/Automattic/newspack-plugin/commit/844e1200d9906ff4eb0056fbb0ae34fd8a9ad841))
* **ads:** support GAM MCM (parent network code) ([#4117](https://github.com/Automattic/newspack-plugin/issues/4117)) ([f4a8262](https://github.com/Automattic/newspack-plugin/commit/f4a826226ceb914c41825b3d08544bff3f5802b6))
* **collections:** add cover story image visibility settings ([#4128](https://github.com/Automattic/newspack-plugin/issues/4128)) ([765303f](https://github.com/Automattic/newspack-plugin/commit/765303fec7b81847bbf941e2d31fb94c5a7b870d))
* **network:** release content distribution ([#4144](https://github.com/Automattic/newspack-plugin/issues/4144)) ([472a0c9](https://github.com/Automattic/newspack-plugin/commit/472a0c9d4195f80ec140d6c28735136a6a9af5a2))

## [6.15.3](https://github.com/Automattic/newspack-plugin/compare/v6.15.2...v6.15.3) (2025-08-15)


### Bug Fixes

* byline network support release ([3a8bd7a](https://github.com/Automattic/newspack-plugin/commit/3a8bd7abc6f3e74d7373eb704fd451258cf0ac5a))

## [6.15.2](https://github.com/Automattic/newspack-plugin/compare/v6.15.1...v6.15.2) (2025-08-14)


### Bug Fixes

* coerce number strings on segment criteria save ([#4143](https://github.com/Automattic/newspack-plugin/issues/4143)) ([9645633](https://github.com/Automattic/newspack-plugin/commit/9645633e9b6a31c616a3ff29c32ca54a640102c4))

## [6.15.1](https://github.com/Automattic/newspack-plugin/compare/v6.15.0...v6.15.1) (2025-08-11)


### Bug Fixes

* **emails:** handle magic link error ([#4137](https://github.com/Automattic/newspack-plugin/issues/4137)) ([f3157ad](https://github.com/Automattic/newspack-plugin/commit/f3157add8382fd29ea750a68f81e31782b5b84a2))

# [6.15.0](https://github.com/Automattic/newspack-plugin/compare/v6.14.4...v6.15.0) (2025-08-11)


### Bug Fixes

* adjust feed media URL encoding to avoid html entities ([#4110](https://github.com/Automattic/newspack-plugin/issues/4110)) ([6353859](https://github.com/Automattic/newspack-plugin/commit/6353859816850c3a30519f58e54567d42fabed69))
* Fix CLI module activation logic ([#4101](https://github.com/Automattic/newspack-plugin/issues/4101)) ([264b723](https://github.com/Automattic/newspack-plugin/commit/264b723f3bb4a95931da07f8a6db899c6390e62a))


### Features

* **bylines:** add get_custom_byline_html method ([#4109](https://github.com/Automattic/newspack-plugin/issues/4109)) ([eede9e4](https://github.com/Automattic/newspack-plugin/commit/eede9e4e7db284b6a2341e1d4607634c187dcca9))
* collections feedback ([#4097](https://github.com/Automattic/newspack-plugin/issues/4097)) ([461d438](https://github.com/Automattic/newspack-plugin/commit/461d438ab1b3f1e0b7101148b3032fc3ed216b6b))
* **collections:** add global setting for showing categories ([#4108](https://github.com/Automattic/newspack-plugin/issues/4108)) ([e16b3fa](https://github.com/Automattic/newspack-plugin/commit/e16b3fa09a459cb6ee1f84d992415b81b3806b02))
* **collections:** improve rewrite rules flushing logic ([#4104](https://github.com/Automattic/newspack-plugin/issues/4104)) ([c56cf31](https://github.com/Automattic/newspack-plugin/commit/c56cf3171a81c2f5558cd5b625703b4393fb1954))
* **collections:** use custom label in document title for archives ([#4105](https://github.com/Automattic/newspack-plugin/issues/4105)) ([af91243](https://github.com/Automattic/newspack-plugin/commit/af91243d4d227a3789068e359b154f773b947832))
* **newspack-ui:** update buttons accent color and border-radius ([#4102](https://github.com/Automattic/newspack-plugin/issues/4102)) ([5870917](https://github.com/Automattic/newspack-plugin/commit/587091771d64851c3c1b8cd6188517f864eea605))
* package action scheduler into the plugin ([b0b579e](https://github.com/Automattic/newspack-plugin/commit/b0b579ec250075b1c0ebc78f515548fb1fd42692))
* package action scheduler into the plugin ([#4113](https://github.com/Automattic/newspack-plugin/issues/4113)) ([66dabdf](https://github.com/Automattic/newspack-plugin/commit/66dabdf8c3210ee0d2920ec770be9203a11b96e7))
* put InDesign behind feature flag ([#4111](https://github.com/Automattic/newspack-plugin/issues/4111)) ([d909dba](https://github.com/Automattic/newspack-plugin/commit/d909dba7bdb737da53fcf4b6dec4146ed19a98aa))
* **rss:** category and tag inclusion: AND or OR condition ([#4107](https://github.com/Automattic/newspack-plugin/issues/4107)) ([832bd67](https://github.com/Automattic/newspack-plugin/commit/832bd67b777ac790caeed13c98c8a834e78f2c63))
* **rss:** Filtering via custom taxonomies ([#4106](https://github.com/Automattic/newspack-plugin/issues/4106)) ([ccf2c3c](https://github.com/Automattic/newspack-plugin/commit/ccf2c3cfa3f3999c137e114a4f14906f77f9972e))
* update translations, add i18n on CI ([#4114](https://github.com/Automattic/newspack-plugin/issues/4114)) ([2a50b0c](https://github.com/Automattic/newspack-plugin/commit/2a50b0c4c326fec7609e845201b7095bacd26f71))

## [6.14.4](https://github.com/Automattic/newspack-plugin/compare/v6.14.3...v6.14.4) (2025-08-06)


### Bug Fixes

* **sitekit-logger:** also check GA from Tag Manager ([#4129](https://github.com/Automattic/newspack-plugin/issues/4129)) ([a36725b](https://github.com/Automattic/newspack-plugin/commit/a36725b543b2176b9702b3f997819640d993f8e8))

## [6.14.3](https://github.com/Automattic/newspack-plugin/compare/v6.14.2...v6.14.3) (2025-08-04)


### Bug Fixes

* **sitekit-logger:** improve sitekit issues detection ([#4119](https://github.com/Automattic/newspack-plugin/issues/4119)) ([27f6032](https://github.com/Automattic/newspack-plugin/commit/27f6032050d00ca94203b7756f0b92904c5e5667))

## [6.14.2](https://github.com/Automattic/newspack-plugin/compare/v6.14.1...v6.14.2) (2025-07-30)


### Bug Fixes

* revert image tag encoding changes ([#4116](https://github.com/Automattic/newspack-plugin/issues/4116)) ([cbb4efa](https://github.com/Automattic/newspack-plugin/commit/cbb4efaa3d5b84acca22f45d6cf10074fc94d2f5))

## [6.14.1](https://github.com/Automattic/newspack-plugin/compare/v6.14.0...v6.14.1) (2025-07-30)


### Bug Fixes

* **rss:** enhance allowed attributes ([#4115](https://github.com/Automattic/newspack-plugin/issues/4115)) ([feead1b](https://github.com/Automattic/newspack-plugin/commit/feead1b5bc88d845d2c5d08a2ced614cc937f6b8))

# [6.14.0](https://github.com/Automattic/newspack-plugin/compare/v6.13.1...v6.14.0) (2025-07-28)


### Bug Fixes

* adjust feed media URL encoding to avoid html entities ([#4110](https://github.com/Automattic/newspack-plugin/issues/4110)) ([55bf084](https://github.com/Automattic/newspack-plugin/commit/55bf084f6a1d3d2d6cf881e761e121d0597be649))
* **admin-menu:** handle newsletter ads permissions ([#4071](https://github.com/Automattic/newspack-plugin/issues/4071)) ([3dac94b](https://github.com/Automattic/newspack-plugin/commit/3dac94b02fb50b26598c12981d5168a2f71874a3))
* ensure my-account is working w/out woocommerce ([#4065](https://github.com/Automattic/newspack-plugin/issues/4065)) ([8487dab](https://github.com/Automattic/newspack-plugin/commit/8487dab43e952956f199ff76585b59892bb6d892))
* Fix/scripts add hooks in Lite Site ([#4093](https://github.com/Automattic/newspack-plugin/issues/4093)) ([0e126cf](https://github.com/Automattic/newspack-plugin/commit/0e126cfa7c7a6148c1aed365bfce76f0491fe940))
* **googlesitekit-logger:** site kit status detection ([#4087](https://github.com/Automattic/newspack-plugin/issues/4087)) ([67eb73f](https://github.com/Automattic/newspack-plugin/commit/67eb73f93a5abbdc04b858ae179d8250503638f7))
* make hr styles more specific and account for < 6 issues ([#4096](https://github.com/Automattic/newspack-plugin/issues/4096)) ([fbbeddb](https://github.com/Automattic/newspack-plugin/commit/fbbeddb425ea457f5ce8c5aa303b90e4eea6ed24))
* make hr styles more specific and account for < 6 issues ([#4096](https://github.com/Automattic/newspack-plugin/issues/4096)) ([07f35d8](https://github.com/Automattic/newspack-plugin/commit/07f35d89a857328765628dadda3c2b1998496fb4))
* **rss:** allow script tag ([#4099](https://github.com/Automattic/newspack-plugin/issues/4099)) ([2560578](https://github.com/Automattic/newspack-plugin/commit/25605782b7212ff10f974f41a0e25a61b1c552ec))


### Features

* add 'newspack_blocks' support to Collections to appear in Content Blocks ([#4083](https://github.com/Automattic/newspack-plugin/issues/4083)) ([7067fe3](https://github.com/Automattic/newspack-plugin/commit/7067fe3de075339d89305b3640d06d03e3a753c9))
* add collections to block filters (NPPD-717)  ([#4100](https://github.com/Automattic/newspack-plugin/issues/4100)) ([3b3b7bf](https://github.com/Automattic/newspack-plugin/commit/3b3b7bf7ac3d22f606265327dd3ad73afb178312))
* add collections to block filters (NPPD-717)  ([#4100](https://github.com/Automattic/newspack-plugin/issues/4100)) ([4aecb4a](https://github.com/Automattic/newspack-plugin/commit/4aecb4a29fe8e3fec0f524a013ccd0a513a2c78a))
* add support for guest contributor in author list block ([#4090](https://github.com/Automattic/newspack-plugin/issues/4090)) ([c2d338b](https://github.com/Automattic/newspack-plugin/commit/c2d338bb068495e2e3ee0de3aa592b485077349f))
* Adobe InDesign Post converter class ([54a6c76](https://github.com/Automattic/newspack-plugin/commit/54a6c767782d759ed3c480eedb5bfb255d41db9d))
* **collections:** templates and frontend ([#4066](https://github.com/Automattic/newspack-plugin/issues/4066), [#4073](https://github.com/Automattic/newspack-plugin/issues/4073), [#4075](https://github.com/Automattic/newspack-plugin/issues/4075), [#4076](https://github.com/Automattic/newspack-plugin/issues/4076), [#4077](https://github.com/Automattic/newspack-plugin/issues/4077)) ([a48a822](https://github.com/Automattic/newspack-plugin/commit/a48a822feb6856badc6c4e9ab15ac69b21372416))
* **collections:** update cpt icon ([#4082](https://github.com/Automattic/newspack-plugin/issues/4082)) ([d5792d9](https://github.com/Automattic/newspack-plugin/commit/d5792d9e75087bebb1637e4a2a9616ca2adf9dd9))
* Indesign post export actions ([#4081](https://github.com/Automattic/newspack-plugin/issues/4081)) ([b7a5c96](https://github.com/Automattic/newspack-plugin/commit/b7a5c96df9ef3f2ca2305770dbef09d2760e26e8))
* **memberships:** prevent membership editing if linked to a subscription ([#4044](https://github.com/Automattic/newspack-plugin/issues/4044)) ([1d8ff46](https://github.com/Automattic/newspack-plugin/commit/1d8ff46a71967c9ab5275ecc87f1007a43cb61eb))
* prepare colors npm package ([#4072](https://github.com/Automattic/newspack-plugin/issues/4072)) ([b27164f](https://github.com/Automattic/newspack-plugin/commit/b27164f808fc49d91efcf45612f2ceb6a626f798))
* prepare icons npm package ([#3984](https://github.com/Automattic/newspack-plugin/issues/3984)) ([9c5ac71](https://github.com/Automattic/newspack-plugin/commit/9c5ac71356234f52599853f7aaf3880ab9989cae))
* Print Settings panel ([#4085](https://github.com/Automattic/newspack-plugin/issues/4085)) ([74fcd37](https://github.com/Automattic/newspack-plugin/commit/74fcd37aba353fecf9ec73f04e0b287475513d5e))
* put InDesign behind feature flag ([#4111](https://github.com/Automattic/newspack-plugin/issues/4111)) ([ca86856](https://github.com/Automattic/newspack-plugin/commit/ca86856e82b3b241e9550f224811f35852368c6c))
* **rss:** add tag inclusion filter to RSS ([#4091](https://github.com/Automattic/newspack-plugin/issues/4091)) ([cfd1fd5](https://github.com/Automattic/newspack-plugin/commit/cfd1fd566fe1305bec92fbfd8a8125b7022e4cbf))
* **rss:** category and tag inclusion: AND or OR condition ([#4107](https://github.com/Automattic/newspack-plugin/issues/4107)) ([59fe3ff](https://github.com/Automattic/newspack-plugin/commit/59fe3ff3dba24dfe8694449d09b3ed3fbff34880))
* **rss:** Filtering via custom taxonomies ([#4106](https://github.com/Automattic/newspack-plugin/issues/4106)) ([409f076](https://github.com/Automattic/newspack-plugin/commit/409f076d619477beb8dcc87d0619c6acc9b27eb3))

## [6.13.1](https://github.com/Automattic/newspack-plugin/compare/v6.13.0...v6.13.1) (2025-07-22)


### Bug Fixes

* **google-oauth:** race conditions and cross-site issues ([#4095](https://github.com/Automattic/newspack-plugin/issues/4095)) ([f18e64a](https://github.com/Automattic/newspack-plugin/commit/f18e64a82205553413f68be47052f1389de0aed9))

# [6.13.0](https://github.com/Automattic/newspack-plugin/compare/v6.12.3...v6.13.0) (2025-07-17)


### Features

* remove http og url override ([#4032](https://github.com/Automattic/newspack-plugin/issues/4032)) ([c97d41d](https://github.com/Automattic/newspack-plugin/commit/c97d41d7f8fea7e3db78a0f4fcff2fa92495c015))

## [6.12.3](https://github.com/Automattic/newspack-plugin/compare/v6.12.2...v6.12.3) (2025-07-16)


### Bug Fixes

* ensure my-account is working w/out woocommerce ([#4065](https://github.com/Automattic/newspack-plugin/issues/4065)) ([1ae57a3](https://github.com/Automattic/newspack-plugin/commit/1ae57a354f461c0612d34af6766e1458b5a60bff))
* **sitekit-logger:** prevent logging if not connected to prod manager ([cb51633](https://github.com/Automattic/newspack-plugin/commit/cb51633f8f6303f3028a38d358f7788b34fa0801))

## [6.12.2](https://github.com/Automattic/newspack-plugin/compare/v6.12.1...v6.12.2) (2025-07-16)


### Bug Fixes

* user deletion w/out WC ([#4080](https://github.com/Automattic/newspack-plugin/issues/4080)) ([4b7ad7f](https://github.com/Automattic/newspack-plugin/commit/4b7ad7fa45c7730c98fa7e5ba039007313f07fbd))

## [6.12.1](https://github.com/Automattic/newspack-plugin/compare/v6.12.0...v6.12.1) (2025-07-15)


### Bug Fixes

* update the plugin's translations template (POT) file ([#4079](https://github.com/Automattic/newspack-plugin/issues/4079)) ([f30c5a1](https://github.com/Automattic/newspack-plugin/commit/f30c5a1e68027b53626d4af8350be41c5fffe5df))

# [6.12.0](https://github.com/Automattic/newspack-plugin/compare/v6.11.3...v6.12.0) (2025-07-14)


### Bug Fixes

* **menu:** don't hide sponsors menu if the user can't see the newspack dashboard ([#4041](https://github.com/Automattic/newspack-plugin/issues/4041)) ([f988bd2](https://github.com/Automattic/newspack-plugin/commit/f988bd2a56f51f84260cccbe269501ee209c6cdc))
* saving name when registering user ([#4050](https://github.com/Automattic/newspack-plugin/issues/4050)) ([498028e](https://github.com/Automattic/newspack-plugin/commit/498028efb139ec1eef771e908cd009c236646bba))
* **woocommerce-memberships:** prevent membership expiry if there's another active subscription ([#4009](https://github.com/Automattic/newspack-plugin/issues/4009)) ([6b42cbd](https://github.com/Automattic/newspack-plugin/commit/6b42cbdf44a19f5ebf10afadecb7444e78324c70))


### Features

* **capabilities:** add caps for RSS Feeds ([#3908](https://github.com/Automattic/newspack-plugin/issues/3908)) ([2620c54](https://github.com/Automattic/newspack-plugin/commit/2620c5492e940b4c052dc22da76934ac68a06843))
* **collections:** add hierarchical fields ([#4051](https://github.com/Automattic/newspack-plugin/issues/4051)) ([3f5a704](https://github.com/Automattic/newspack-plugin/commit/3f5a704286733836d763407db89d0f7632be1be3))
* **collections:** allow overriding "collection" names and slugs ([#4033](https://github.com/Automattic/newspack-plugin/issues/4033)) ([f5523b0](https://github.com/Automattic/newspack-plugin/commit/f5523b0a0196f15fbde2052286d581f2d0483d99))
* **collections:** support multiple CTAs in collections meta ([#4049](https://github.com/Automattic/newspack-plugin/issues/4049)) ([d445444](https://github.com/Automattic/newspack-plugin/commit/d445444def17b777fceb36442aea9ab5480cc1e1))
* modal checkout for My Account's reorders ([#3988](https://github.com/Automattic/newspack-plugin/issues/3988)) ([fd347bc](https://github.com/Automattic/newspack-plugin/commit/fd347bc25ff88d113ebc2159b89f8e1370365cf0))
* **my-account:** subscription payment notice ([#4029](https://github.com/Automattic/newspack-plugin/issues/4029)) ([4b78d7f](https://github.com/Automattic/newspack-plugin/commit/4b78d7fa92606d0da60839f7477f2741c67fed55))
* **rss:** Custom Tracking snippet ([#4047](https://github.com/Automattic/newspack-plugin/issues/4047)) ([2e682c5](https://github.com/Automattic/newspack-plugin/commit/2e682c5bf5cafd70c45950811348f7536b14e324))
* **rss:** Hooks extending RSS ([#4055](https://github.com/Automattic/newspack-plugin/issues/4055)) ([da97ff4](https://github.com/Automattic/newspack-plugin/commit/da97ff4c21bf7ffbe9bf773ba6c5703de224c66d))
* **rss:** Skip non-distributable images ([#4052](https://github.com/Automattic/newspack-plugin/issues/4052)) ([9fdfe78](https://github.com/Automattic/newspack-plugin/commit/9fdfe780448eacfc334177cf0624a8beadb552ea))
* show "deleted" label on segments if list, subscription, or plan was deleted [NPPM-2031] ([#4021](https://github.com/Automattic/newspack-plugin/issues/4021)) ([4dec8bb](https://github.com/Automattic/newspack-plugin/commit/4dec8bb37d2a221bbdaa3155ca1e5a35ac95d9b8))
* **woocommerce:** rename WooCommerce Payments to WooPay ([#4048](https://github.com/Automattic/newspack-plugin/issues/4048)) ([1528cad](https://github.com/Automattic/newspack-plugin/commit/1528cadb8eba826c52e4c28db2b0d5fbb05cb743))

## [6.11.3](https://github.com/Automattic/newspack-plugin/compare/v6.11.2...v6.11.3) (2025-07-07)


### Bug Fixes

* update custom byline markup to match regular bylines in theme ([#4070](https://github.com/Automattic/newspack-plugin/issues/4070)) ([ef5ce7d](https://github.com/Automattic/newspack-plugin/commit/ef5ce7d9e977e37f9f47267a7038c888467c03ba))

## [6.11.2](https://github.com/Automattic/newspack-plugin/compare/v6.11.1...v6.11.2) (2025-07-02)


### Bug Fixes

* **log:** ensure `user_email` is always a string ([#4064](https://github.com/Automattic/newspack-plugin/issues/4064)) ([4f1ece5](https://github.com/Automattic/newspack-plugin/commit/4f1ece577ea089e4a31249a3dd1c75cf78e28b8a))

## [6.11.1](https://github.com/Automattic/newspack-plugin/compare/v6.11.0...v6.11.1) (2025-07-01)


### Bug Fixes

* **audience-wizard:** salesforce settings rendering ([#4045](https://github.com/Automattic/newspack-plugin/issues/4045)) ([fd9c3bc](https://github.com/Automattic/newspack-plugin/commit/fd9c3bc7b39874cc71c7995a034ef29b96c3ebf1))

# [6.11.0](https://github.com/Automattic/newspack-plugin/compare/v6.10.0...v6.11.0) (2025-07-01)


### Features

* **rss:** add 1 minute update frequency ([#4063](https://github.com/Automattic/newspack-plugin/issues/4063)) ([b9896b9](https://github.com/Automattic/newspack-plugin/commit/b9896b9007306847ee7cfaca83ac1cb102eaece7))

# [6.10.0](https://github.com/Automattic/newspack-plugin/compare/v6.9.1...v6.10.0) (2025-06-30)


### Bug Fixes

* **esp-sync:** control to sync account deletion; unsubscribe reader on delete ([#4008](https://github.com/Automattic/newspack-plugin/issues/4008)) ([a027e06](https://github.com/Automattic/newspack-plugin/commit/a027e06d9138a86a1cb991c5fee0c9f76a779c60))
* return to dashboard link ([#4005](https://github.com/Automattic/newspack-plugin/issues/4005)) ([1045782](https://github.com/Automattic/newspack-plugin/commit/1045782ff31e895094130801f5edfb96d7b92a31))
* update text in accessibility statement ([#4030](https://github.com/Automattic/newspack-plugin/issues/4030)) ([3e71b75](https://github.com/Automattic/newspack-plugin/commit/3e71b750c004b763670d9d80e5816907a8232f95))


### Features

* add an option to create an Accessibility Statement page ([#4022](https://github.com/Automattic/newspack-plugin/issues/4022)) ([754da19](https://github.com/Automattic/newspack-plugin/commit/754da1975cac79862b3b8e1ef0872b836d062d19))
* **collections:** add module global settings ([#4031](https://github.com/Automattic/newspack-plugin/issues/4031)) ([d6cc707](https://github.com/Automattic/newspack-plugin/commit/d6cc70722fc460d9b4fe0aecafcee8102a2c9498))
* **collections:** register collection category taxonomy ([#4001](https://github.com/Automattic/newspack-plugin/issues/4001)) ([cb94d8c](https://github.com/Automattic/newspack-plugin/commit/cb94d8c7debe44efb9a638b166aab441c4ee5c8b))
* **collections:** register collection CPT and taxonomy ([#4000](https://github.com/Automattic/newspack-plugin/issues/4000)) ([f08c928](https://github.com/Automattic/newspack-plugin/commit/f08c92872e5a5fd162bff98b5837d994d91029ea))
* **collections:** register collection section taxonomy ([#4003](https://github.com/Automattic/newspack-plugin/issues/4003)) ([4846618](https://github.com/Automattic/newspack-plugin/commit/48466183294a87689730497b805fa329deb232f7)), closes [#4017](https://github.com/Automattic/newspack-plugin/issues/4017) [#4018](https://github.com/Automattic/newspack-plugin/issues/4018)
* **dashboard:** update card style to be more inline with core button card ([#4002](https://github.com/Automattic/newspack-plugin/issues/4002)) ([2e551d3](https://github.com/Automattic/newspack-plugin/commit/2e551d3a112a938bb19db021e5402d8cccdbf5b9))
* **my-account:** change payment method modal ([#4016](https://github.com/Automattic/newspack-plugin/issues/4016)) ([714a073](https://github.com/Automattic/newspack-plugin/commit/714a0730fcb887ea8224eee46f67beb48878d79a))
* **my-account:** payment information custom page template ([#3991](https://github.com/Automattic/newspack-plugin/issues/3991)) ([de03d6c](https://github.com/Automattic/newspack-plugin/commit/de03d6c4df6d4928867648dabb4bcbd779426f46))
* remove custom bylines flag ([#4039](https://github.com/Automattic/newspack-plugin/issues/4039)) ([da83c21](https://github.com/Automattic/newspack-plugin/commit/da83c2187b9a4fefc9bf81e70187bdec97ef347f))

## [6.9.1](https://github.com/Automattic/newspack-plugin/compare/v6.9.0...v6.9.1) (2025-06-27)


### Bug Fixes

* lite site error without sticky posts ([#4054](https://github.com/Automattic/newspack-plugin/issues/4054)) ([cdba629](https://github.com/Automattic/newspack-plugin/commit/cdba629f86ee6406e1fc31b819b33ff3bc2d75a3))

# [6.9.0](https://github.com/Automattic/newspack-plugin/compare/v6.8.0...v6.9.0) (2025-06-25)


### Features

* release for Liste site sticky posts support ([a2f7b2d](https://github.com/Automattic/newspack-plugin/commit/a2f7b2dd4606e92ecd39c51a5167bfaff348b753))

# [6.8.0](https://github.com/Automattic/newspack-plugin/compare/v6.7.0...v6.8.0) (2025-06-16)


### Bug Fixes

* **campaigns:** segments: min/max values for articles read count defaults ([2efe437](https://github.com/Automattic/newspack-plugin/commit/2efe4372931a5441d4d7ca27d5727dcfcee2760d))
* prevent auto-publishing corrections when scheduling posts ([#4006](https://github.com/Automattic/newspack-plugin/issues/4006)) ([1a3dc5b](https://github.com/Automattic/newspack-plugin/commit/1a3dc5b9d3a46e693069642cc071a4c7ae3e5b79))
* sync membership status to ESP when reader deletes account ([#4004](https://github.com/Automattic/newspack-plugin/issues/4004)) ([2cff33b](https://github.com/Automattic/newspack-plugin/commit/2cff33be1cc28cf6eb59125a642b7927f0e143a9))
* update path to verify template in WooCommerce My Account class ([#3992](https://github.com/Automattic/newspack-plugin/issues/3992)) ([d54e67f](https://github.com/Automattic/newspack-plugin/commit/d54e67fadebfd68d10320b8a3c7642129afaccaa))
* update text in accessibility statement ([#4030](https://github.com/Automattic/newspack-plugin/issues/4030)) ([391d12b](https://github.com/Automattic/newspack-plugin/commit/391d12b0737846684741dc545921f9467ab4a784))


### Features

* add an option to create an Accessibility Statement page ([#4022](https://github.com/Automattic/newspack-plugin/issues/4022)) ([b802442](https://github.com/Automattic/newspack-plugin/commit/b802442045e4ce8501263055c37bed517aeed637))
* **collections:** add optional collections module ([#3990](https://github.com/Automattic/newspack-plugin/issues/3990)) ([48b4a45](https://github.com/Automattic/newspack-plugin/commit/48b4a45af91f01f1ebcdf6b4b78b809a457f4185))
* **my-account:** custom confirmation modal flows ([#3985](https://github.com/Automattic/newspack-plugin/issues/3985)) ([90990aa](https://github.com/Automattic/newspack-plugin/commit/90990aaeedf72b2dd55e7711ae47ea1da221ce3d))
* **my-account:** resubscribe and renew early via modal checkout ([#3973](https://github.com/Automattic/newspack-plugin/issues/3973)) ([5a664a1](https://github.com/Automattic/newspack-plugin/commit/5a664a1a11805740083a738b30061f9368516a0d))

# [6.7.0](https://github.com/Automattic/newspack-plugin/compare/v6.6.4...v6.7.0) (2025-06-02)


### Bug Fixes

* **404-images:** use JS without modifying content ([#3963](https://github.com/Automattic/newspack-plugin/issues/3963)) ([9f5646b](https://github.com/Automattic/newspack-plugin/commit/9f5646bf02ebcef283f38c88d0f8ec8dcd534fde))
* add missing namespace ([#3980](https://github.com/Automattic/newspack-plugin/issues/3980)) ([6d58793](https://github.com/Automattic/newspack-plugin/commit/6d58793b95fc75f5c36bee7336eb22b47b532016))
* **emails:** add missing HTML markup in the change-email-cancel template ([#3981](https://github.com/Automattic/newspack-plugin/issues/3981)) ([040ae30](https://github.com/Automattic/newspack-plugin/commit/040ae30695a61865a2a72ae0978ceef82f2324d1))
* **ga4:** fire login/registration activities via SSO ([#3965](https://github.com/Automattic/newspack-plugin/issues/3965)) ([8c97515](https://github.com/Automattic/newspack-plugin/commit/8c975151b39109514c4230763e65011d62f1c8f0))
* hide modal content gate when modal checkout is opened ([#3953](https://github.com/Automattic/newspack-plugin/issues/3953)) ([a503973](https://github.com/Automattic/newspack-plugin/commit/a503973ada8dcf4ce9dae2011894eccb6060de14))
* **jetpack:** handle the related posts max age option ([#3964](https://github.com/Automattic/newspack-plugin/issues/3964)) ([8aad2b8](https://github.com/Automattic/newspack-plugin/commit/8aad2b8b6010690b93c19bd68c6c66ed9d0a4603))
* make sure fix duplcate fields apply filters ([#3971](https://github.com/Automattic/newspack-plugin/issues/3971)) ([f361a4e](https://github.com/Automattic/newspack-plugin/commit/f361a4edc2c5214be93a16191076fa6e69e97d88))
* namespace Lite Site ([#3975](https://github.com/Automattic/newspack-plugin/issues/3975)) ([e4665ae](https://github.com/Automattic/newspack-plugin/commit/e4665aeeca38319aa03bfef9d18bd6e7018f9257))
* prevent auto-publishing corrections when scheduling posts ([#4006](https://github.com/Automattic/newspack-plugin/issues/4006)) ([7531832](https://github.com/Automattic/newspack-plugin/commit/753183230025967e41a50f969fb2f8d562672657))
* sync correction status with parent post status ([#3978](https://github.com/Automattic/newspack-plugin/issues/3978)) ([dcd5a12](https://github.com/Automattic/newspack-plugin/commit/dcd5a128a66f52cb359366e1b1106f78617b7aaf))


### Features

* add compatibility to network in custom bylines ([#3972](https://github.com/Automattic/newspack-plugin/issues/3972)) ([199a993](https://github.com/Automattic/newspack-plugin/commit/199a993d84df0da8da883b7d0cedc10672db0295))
* add icons repository and remove custom icons ([#3883](https://github.com/Automattic/newspack-plugin/issues/3883)) ([e56d2e0](https://github.com/Automattic/newspack-plugin/commit/e56d2e0faa47539184f66eed329ff29f5eab41cd))
* **analytics:** "My Account" dashboard interactions ([#3949](https://github.com/Automattic/newspack-plugin/issues/3949)) ([22e9590](https://github.com/Automattic/newspack-plugin/commit/22e959022980ac27b3f5cb8b4b3ba1a0c969edb9))
* **donations:** update notice style and type ([#3962](https://github.com/Automattic/newspack-plugin/issues/3962)) ([3f60ef3](https://github.com/Automattic/newspack-plugin/commit/3f60ef3ffdf15f0fde13424e6b13748b413c31c4))
* **email-change:** remove env constant requirement ([#3943](https://github.com/Automattic/newspack-plugin/issues/3943)) ([4158bf1](https://github.com/Automattic/newspack-plugin/commit/4158bf1b36ab2ad40fe3451a7a73e49bcda4d144))
* **my-account:** apply Newspack UI styles to My Account w/ env constant ([#3951](https://github.com/Automattic/newspack-plugin/issues/3951)) ([e4aa5a2](https://github.com/Automattic/newspack-plugin/commit/e4aa5a2e8cd5d8fc318fad1e1577dd21a2c00cbd))
* **my-account:** full-site takeover template and custom nav menu ([#3974](https://github.com/Automattic/newspack-plugin/issues/3974)) ([5cf8403](https://github.com/Automattic/newspack-plugin/commit/5cf84031df14884c5f9337cff8df6b1278573fd0))
* **woocommerce:** log error notices ([#3952](https://github.com/Automattic/newspack-plugin/issues/3952)) ([1654007](https://github.com/Automattic/newspack-plugin/commit/1654007b8abb358ad036290db0fd8416ef4d96c2))

## [6.6.4](https://github.com/Automattic/newspack-plugin/compare/v6.6.3...v6.6.4) (2025-05-29)


### Bug Fixes

* **autocomplete-orders:** apply option to variations, not parent product ([#3958](https://github.com/Automattic/newspack-plugin/issues/3958)) ([f8bf431](https://github.com/Automattic/newspack-plugin/commit/f8bf431763377a4432f23b177c24514e79ea9f9b))

## [6.6.3](https://github.com/Automattic/newspack-plugin/compare/v6.6.2...v6.6.3) (2025-05-27)


### Bug Fixes

* **corrections:** rewrite rule flush check ([#3998](https://github.com/Automattic/newspack-plugin/issues/3998)) ([e2023db](https://github.com/Automattic/newspack-plugin/commit/e2023db4f10304c7b4fd062439fd580ffa113dcd))

## [6.6.2](https://github.com/Automattic/newspack-plugin/compare/v6.6.1...v6.6.2) (2025-05-21)


### Bug Fixes

* ensure compatibility with woo memberships 1.27.2 ([#3987](https://github.com/Automattic/newspack-plugin/issues/3987)) ([a52224a](https://github.com/Automattic/newspack-plugin/commit/a52224ae16096c555b1979ce4b4b508d834a5410))

## [6.6.1](https://github.com/Automattic/newspack-plugin/compare/v6.6.0...v6.6.1) (2025-05-15)


### Bug Fixes

* remove nonexistent filter use ([#3976](https://github.com/Automattic/newspack-plugin/issues/3976)) ([ace5f7a](https://github.com/Automattic/newspack-plugin/commit/ace5f7a711572665918e72420e8666d3d8a4a950))

# [6.6.0](https://github.com/Automattic/newspack-plugin/compare/v6.5.1...v6.6.0) (2025-05-14)


### Bug Fixes

* add white background to Newspack admin screens ([#3909](https://github.com/Automattic/newspack-plugin/issues/3909)) ([4c8409e](https://github.com/Automattic/newspack-plugin/commit/4c8409e6a3da3cc5a7462224fdb12d90c79f4d2d))
* allow guest contributors to have empty archives ([#3902](https://github.com/Automattic/newspack-plugin/issues/3902)) ([af8bed9](https://github.com/Automattic/newspack-plugin/commit/af8bed9b4e3cdfbfad34c3220bf82a36fa55aa41))
* **analytics:** migrate back-end GA4 events to front-end ([#3895](https://github.com/Automattic/newspack-plugin/issues/3895)) ([7e18628](https://github.com/Automattic/newspack-plugin/commit/7e1862887acbdf1895e34a87754184d1c1253f06))
* **auth-form:** check user before is_user_reader() call ([#3944](https://github.com/Automattic/newspack-plugin/issues/3944)) ([1cbb760](https://github.com/Automattic/newspack-plugin/commit/1cbb760fb4b33e71fdbe023ecfdd3193684b387f))
* avoid warning for network event ([#3942](https://github.com/Automattic/newspack-plugin/issues/3942)) ([60d0aa3](https://github.com/Automattic/newspack-plugin/commit/60d0aa3ccdb54829387ee3da536ddef12436eefd))
* **bylines:** avoid rerendering of editable area ([#3896](https://github.com/Automattic/newspack-plugin/issues/3896)) ([9fdf659](https://github.com/Automattic/newspack-plugin/commit/9fdf659b7369250298c246dd22635cfe68a4909b))
* **bylines:** custom edited state for modal ([#3919](https://github.com/Automattic/newspack-plugin/issues/3919)) ([2a1a800](https://github.com/Automattic/newspack-plugin/commit/2a1a8007a3a28e4f4296b775f006ea1e590032c4))
* **bylines:** get CAP data from the plugin's store ([#3906](https://github.com/Automattic/newspack-plugin/issues/3906)) ([0745113](https://github.com/Automattic/newspack-plugin/commit/0745113167a647bb38bd8939154322126e5f63be))
* **corrections:** Limit CPT to posts only ([#3927](https://github.com/Automattic/newspack-plugin/issues/3927)) ([1d49c39](https://github.com/Automattic/newspack-plugin/commit/1d49c39947895f1c8d7694092dd7f24185c0b915))
* **corrections:** Use Corrections Archive template by default ([#3929](https://github.com/Automattic/newspack-plugin/issues/3929)) ([c4c1925](https://github.com/Automattic/newspack-plugin/commit/c4c1925d6e7841ad19879fabce2b931c275b1207))
* **email-change:** ensure success and error messages persist after redirect ([#3913](https://github.com/Automattic/newspack-plugin/issues/3913)) ([caf82a5](https://github.com/Automattic/newspack-plugin/commit/caf82a572fedf602612012ff10c0967a8bbc4c04))
* **email-change:** improve messaging for in-progress or expired change requests ([#3886](https://github.com/Automattic/newspack-plugin/issues/3886)) ([02d2ea0](https://github.com/Automattic/newspack-plugin/commit/02d2ea0d8f103089b56599251ba7ce4fd1cc53f6))
* **email-change:** typo in variable name ([#3941](https://github.com/Automattic/newspack-plugin/issues/3941)) ([941dc21](https://github.com/Automattic/newspack-plugin/commit/941dc2168fd0045e30e54d77d370aa17068390f2))
* **ga4:** ensure gate_post_id and newspack_popup_id are passed to activities ([#3956](https://github.com/Automattic/newspack-plugin/issues/3956)) ([fadaa4d](https://github.com/Automattic/newspack-plugin/commit/fadaa4d4c1e93ffe59269516573177c74ab26b24))
* **google-login:** handle google oauth disabled state in the editor ([#3946](https://github.com/Automattic/newspack-plugin/issues/3946)) ([0f9f7c6](https://github.com/Automattic/newspack-plugin/commit/0f9f7c6e69c5f7ae0190cf2ecf385f351876f552))
* hide campaigns menu when the Newspack Campaigns plugin is not activated ([#3922](https://github.com/Automattic/newspack-plugin/issues/3922)) ([adcc21f](https://github.com/Automattic/newspack-plugin/commit/adcc21f4e4476ef6e272a552230add780d90ed29))
* make front end not dependent on coauthors plus ([#3905](https://github.com/Automattic/newspack-plugin/issues/3905)) ([dc96e5b](https://github.com/Automattic/newspack-plugin/commit/dc96e5b33cf9d328dbdcbb36f8504628e4e52349))
* **newsletters-wizard:** remove advertisers menu item ([#3948](https://github.com/Automattic/newspack-plugin/issues/3948)) ([2fddbd6](https://github.com/Automattic/newspack-plugin/commit/2fddbd6aaa3e6a266ff626fdaeb05d39c546c4e9))
* **otp-input:** enhance OTP input handling for clipboard ([#3898](https://github.com/Automattic/newspack-plugin/issues/3898)) ([a56328b](https://github.com/Automattic/newspack-plugin/commit/a56328b8c6c802a304733c9c18618b2d135fb7d4))
* reinstate the Add New Newsletter link in the admin menu ([#3926](https://github.com/Automattic/newspack-plugin/issues/3926)) ([1c8c7d7](https://github.com/Automattic/newspack-plugin/commit/1c8c7d7fd1ea2e62e4947c2a52ef0df02d284c0c))
* remove extra space above empty help text ([#3904](https://github.com/Automattic/newspack-plugin/issues/3904)) ([6a4fe4f](https://github.com/Automattic/newspack-plugin/commit/6a4fe4f7590d9d772af0486ede40283376ac34d9))
* remove import and route for Suppression ([#3894](https://github.com/Automattic/newspack-plugin/issues/3894)) ([a2034e7](https://github.com/Automattic/newspack-plugin/commit/a2034e73ca8d284f73278c4f9c361313c0b4e292))
* **setup-wizard:** prevent Yoast redirect ([#3940](https://github.com/Automattic/newspack-plugin/issues/3940)) ([3461b5f](https://github.com/Automattic/newspack-plugin/commit/3461b5f6bf76cf5e747b136f2030243724807653))


### Features

* **auth:** OTP autocomplete integration ([#3888](https://github.com/Automattic/newspack-plugin/issues/3888)) ([5a1d7a8](https://github.com/Automattic/newspack-plugin/commit/5a1d7a8cdc9e33eb54a2f5cbed5f74801734e264))
* **bylines:** get post byline authors ([#3911](https://github.com/Automattic/newspack-plugin/issues/3911)) ([17942a2](https://github.com/Automattic/newspack-plugin/commit/17942a2e2e497a23540cf1bbcbfcf29779193290))
* **bylines:** useAuthorTokens() hook with user entity validation ([#3907](https://github.com/Automattic/newspack-plugin/issues/3907)) ([3d1feee](https://github.com/Automattic/newspack-plugin/commit/3d1feee1a8b2ff80dde66659a3757bf1f0eb5028))
* **email-change:** allow old email to cancel email change only, not confirm ([#3912](https://github.com/Automattic/newspack-plugin/issues/3912)) ([92413e3](https://github.com/Automattic/newspack-plugin/commit/92413e35a5e08ade9d401366eb31215f3ae6b91f))
* **email-change:** remove env constant requirement ([#3943](https://github.com/Automattic/newspack-plugin/issues/3943)) ([f04e58f](https://github.com/Automattic/newspack-plugin/commit/f04e58ffd1f4f64b428f788acb755d3922dd0e1f))
* **ga4:** np_newsletter_subscribed event upon front-end signup ([#3938](https://github.com/Automattic/newspack-plugin/issues/3938)) ([2295277](https://github.com/Automattic/newspack-plugin/commit/2295277de20c6bf61435ea1f96a31d0a9f95224f))
* **ras-newsletter:** Newsletters list progressive disclosure ([#3887](https://github.com/Automattic/newspack-plugin/issues/3887)) ([53f4eed](https://github.com/Automattic/newspack-plugin/commit/53f4eed0b8993245a97a4f43877e7767d9ba5c2b))
* **reader-auth:** send WP login reminder email to non-reader accounts ([#3796](https://github.com/Automattic/newspack-plugin/issues/3796)) ([d1af25e](https://github.com/Automattic/newspack-plugin/commit/d1af25e3ab28488f355ba91568483054c856dd94)), closes [#3823](https://github.com/Automattic/newspack-plugin/issues/3823)
* **scss:** export colors as module for JS consumption ([#3910](https://github.com/Automattic/newspack-plugin/issues/3910)) ([81da8f0](https://github.com/Automattic/newspack-plugin/commit/81da8f0df1e1f70b6430302f8d74c314ced286b1))
* **segments:** add filter for device segment ([#3880](https://github.com/Automattic/newspack-plugin/issues/3880)) ([d465726](https://github.com/Automattic/newspack-plugin/commit/d4657261bf9da8b47f527d68a0dfc3e7d8e78f5e))
* update colors and add all design system variables ([#3882](https://github.com/Automattic/newspack-plugin/issues/3882)) ([6ee8e30](https://github.com/Automattic/newspack-plugin/commit/6ee8e3019d21c1b5faa3214c97604ce12ac752a9))

## [6.5.1](https://github.com/Automattic/newspack-plugin/compare/v6.5.0...v6.5.1) (2025-05-13)


### Bug Fixes

* **stripe-gateway:** transaction metadata for renewals ([#3939](https://github.com/Automattic/newspack-plugin/issues/3939)) ([5929618](https://github.com/Automattic/newspack-plugin/commit/59296184c0130c10f3c9c15908ae117fe2105831))

# [6.5.0](https://github.com/Automattic/newspack-plugin/compare/v6.4.4...v6.5.0) (2025-05-08)


### Features

* add Subscription Confirmation checkboxes for FTC compliance ([#3961](https://github.com/Automattic/newspack-plugin/issues/3961)) ([90d4bf6](https://github.com/Automattic/newspack-plugin/commit/90d4bf610d47379811677a40a39d12e4f2622381))

## [6.4.4](https://github.com/Automattic/newspack-plugin/compare/v6.4.3...v6.4.4) (2025-04-21)


### Bug Fixes

* **recaptcha-v2:** clone Place Order button for all checkouts, not just modal ([#3930](https://github.com/Automattic/newspack-plugin/issues/3930)) ([90287ab](https://github.com/Automattic/newspack-plugin/commit/90287ab84e42762816633fd11fc7e677c7fa7780))

## [6.4.3](https://github.com/Automattic/newspack-plugin/compare/v6.4.2...v6.4.3) (2025-04-21)


### Bug Fixes

* register reader data handlers after listeners ([#3901](https://github.com/Automattic/newspack-plugin/issues/3901)) ([df71105](https://github.com/Automattic/newspack-plugin/commit/df71105c276bb3f872c0f3876287e15022416a99))

## [6.4.2](https://github.com/Automattic/newspack-plugin/compare/v6.4.1...v6.4.2) (2025-04-17)


### Bug Fixes

* change nonce strategy for data events ([#3924](https://github.com/Automattic/newspack-plugin/issues/3924)) ([dba7e6b](https://github.com/Automattic/newspack-plugin/commit/dba7e6b8113c55db8c8091755cacb5ceed94209e))

## [6.4.1](https://github.com/Automattic/newspack-plugin/compare/v6.4.0...v6.4.1) (2025-04-16)


### Bug Fixes

* **wizards:** react render strategy and ref forwarding ([#3923](https://github.com/Automattic/newspack-plugin/issues/3923)) ([730ffc3](https://github.com/Automattic/newspack-plugin/commit/730ffc397c23a7da7808202092f684ed16ef414b))

# [6.4.0](https://github.com/Automattic/newspack-plugin/compare/v6.3.1...v6.4.0) (2025-04-14)


### Bug Fixes

* **guest-contributors:** return them in authors query ([3703559](https://github.com/Automattic/newspack-plugin/commit/3703559142297d3b111a089a97ce4a1b2d1b718a))
* handle missing Mailchimp API key in auth status ([#3873](https://github.com/Automattic/newspack-plugin/issues/3873)) ([6df6fda](https://github.com/Automattic/newspack-plugin/commit/6df6fda4c057bb9840c736ba7c52fcf5b7d01fd9))
* **ia:** render all emails on reset ([#3867](https://github.com/Automattic/newspack-plugin/issues/3867)) ([c6a71c0](https://github.com/Automattic/newspack-plugin/commit/c6a71c0c7206e1645491a19701e45415610a050c))
* **notices:** fix PHP notice ([#3872](https://github.com/Automattic/newspack-plugin/issues/3872)) ([cc928b2](https://github.com/Automattic/newspack-plugin/commit/cc928b27a8eb2536d3119dbb62fd24c14cbb03ff))
* path to autoload ([#3808](https://github.com/Automattic/newspack-plugin/issues/3808)) ([97fa24a](https://github.com/Automattic/newspack-plugin/commit/97fa24aa47c6efefde9fe7cf52d93a9e351a0036))
* remove Mailchimp for WooCommerce from wizard ([#3876](https://github.com/Automattic/newspack-plugin/issues/3876)) ([f6b2484](https://github.com/Automattic/newspack-plugin/commit/f6b248427f745724e6dd265533d32c5fadc3b78d))
* remove the Design link from the Appearance menu ([#3878](https://github.com/Automattic/newspack-plugin/issues/3878)) ([e24f147](https://github.com/Automattic/newspack-plugin/commit/e24f147b96594c7a1ad57879119f7001a551e504))


### Features

* custom byline interface ([#3746](https://github.com/Automattic/newspack-plugin/issues/3746)) ([289f55e](https://github.com/Automattic/newspack-plugin/commit/289f55eff543c6966ebcd9fe3eeb05f239b9ff95))
* **esp-sync:** add constant contact support ([#3832](https://github.com/Automattic/newspack-plugin/issues/3832)) ([8198956](https://github.com/Automattic/newspack-plugin/commit/8198956df36968240c97b54454f7d30c11237183))
* frontend display of bylines ([#3856](https://github.com/Automattic/newspack-plugin/issues/3856)) ([9feeba8](https://github.com/Automattic/newspack-plugin/commit/9feeba87dcccd31067ac8efb1df080b59f7dd4c9))

## [6.3.1](https://github.com/Automattic/newspack-plugin/compare/v6.3.0...v6.3.1) (2025-04-09)


### Bug Fixes

* update Lite Site URL base ([#3900](https://github.com/Automattic/newspack-plugin/issues/3900)) ([9b5d708](https://github.com/Automattic/newspack-plugin/commit/9b5d70865985e5df9c945abe0ab16b73f65f94a8))

# [6.3.0](https://github.com/Automattic/newspack-plugin/compare/v6.2.2...v6.3.0) (2025-04-09)


### Features

* **woocommerce:** add metadata to Stripe txns ([#3903](https://github.com/Automattic/newspack-plugin/issues/3903)) ([32076eb](https://github.com/Automattic/newspack-plugin/commit/32076ebba55a4e33c672cc84f92eae8d101c0370))

## [6.2.2](https://github.com/Automattic/newspack-plugin/compare/v6.2.1...v6.2.2) (2025-04-03)


### Bug Fixes

* restore Media Kit components in Ads wizard ([#3881](https://github.com/Automattic/newspack-plugin/issues/3881)) ([86fa89a](https://github.com/Automattic/newspack-plugin/commit/86fa89acbeffe3c5361567b776fc226035e9e1be))

## [6.2.1](https://github.com/Automattic/newspack-plugin/compare/v6.2.0...v6.2.1) (2025-04-03)


### Bug Fixes

* force release build ([71b44d9](https://github.com/Automattic/newspack-plugin/commit/71b44d91b7b767f972ad6365833aa7ac57025560))

# [6.2.0](https://github.com/Automattic/newspack-plugin/compare/v6.1.3...v6.2.0) (2025-03-31)


### Bug Fixes

* add check if product before gating content ([#3850](https://github.com/Automattic/newspack-plugin/issues/3850)) ([b9e385d](https://github.com/Automattic/newspack-plugin/commit/b9e385d0d6e5e838c89c0473cf843882a5a42c20))
* **ga:** check if post is not null before reading properties ([#3817](https://github.com/Automattic/newspack-plugin/issues/3817)) ([9dfa47a](https://github.com/Automattic/newspack-plugin/commit/9dfa47ab2ae5d30589504e9be891c692163caf92))
* handle missing Mailchimp API key in auth status ([#3873](https://github.com/Automattic/newspack-plugin/issues/3873)) ([81a6e51](https://github.com/Automattic/newspack-plugin/commit/81a6e51cb375b9faf6c345ceb2dff958b352e22f))
* **ia:** render all emails on reset ([#3867](https://github.com/Automattic/newspack-plugin/issues/3867)) ([519890f](https://github.com/Automattic/newspack-plugin/commit/519890f8bc5bad102fb125f0fb00724a958a973b))
* **image-404-handling:** handle 404 from other sites ([51998b8](https://github.com/Automattic/newspack-plugin/commit/51998b86af95b2837c51ec87ac88b243c242297f))
* **modal-checkout:** endpoint to refresh newsletter lists via REST ([#3841](https://github.com/Automattic/newspack-plugin/issues/3841)) ([79ea458](https://github.com/Automattic/newspack-plugin/commit/79ea458556594e756abe898a447850e47b0e144e))
* **notices:** fix PHP notice ([#3872](https://github.com/Automattic/newspack-plugin/issues/3872)) ([9cda1ca](https://github.com/Automattic/newspack-plugin/commit/9cda1ca475016b63c3fa919a1e33921f16996c85))
* **reader-revenue:** fatal when using woocommerce-paypal-payments@3.0.0 ([#3848](https://github.com/Automattic/newspack-plugin/issues/3848)) ([1d73e17](https://github.com/Automattic/newspack-plugin/commit/1d73e1777af0b5ad0ae01ed9b678f8f1a958d423))
* remove Mailchimp for WooCommerce from wizard ([#3876](https://github.com/Automattic/newspack-plugin/issues/3876)) ([4353a98](https://github.com/Automattic/newspack-plugin/commit/4353a9847fc0d88b6e08c5b584edf3613728c1b7))
* update paths to share button, social CSS in Perfmatters ([#3810](https://github.com/Automattic/newspack-plugin/issues/3810)) ([eb2ca7e](https://github.com/Automattic/newspack-plugin/commit/eb2ca7ecd25a9a95071f030f02d8f029cd16d3cf))


### Features

* add canonical url to lite site single posts ([#3865](https://github.com/Automattic/newspack-plugin/issues/3865)) ([471ad81](https://github.com/Automattic/newspack-plugin/commit/471ad81f40420a41e96d507efd34982ed079caea))
* add custom check for media visibility ([#3823](https://github.com/Automattic/newspack-plugin/issues/3823)) ([c1d81dc](https://github.com/Automattic/newspack-plugin/commit/c1d81dcfd79835ce1d620037eb50eafe6b07fc2c))
* add Lite sites feature ([#3807](https://github.com/Automattic/newspack-plugin/issues/3807)) ([cbd3e61](https://github.com/Automattic/newspack-plugin/commit/cbd3e617723597089dd450e705c31643882c88dc))
* **correction:** Add Priority setting & refactor Block ([#3844](https://github.com/Automattic/newspack-plugin/issues/3844)) ([9232750](https://github.com/Automattic/newspack-plugin/commit/923275015aad793ec46629ad2268f63200c494a2))
* **corrections-location:** integrate Correction location logic ([#3829](https://github.com/Automattic/newspack-plugin/issues/3829)) ([6a7cd45](https://github.com/Automattic/newspack-plugin/commit/6a7cd450ebf8071897531ec834aca1868cc11ec9))
* **corrections-modal:** fix modal action buttons & state handling ([#3852](https://github.com/Automattic/newspack-plugin/issues/3852)) ([220d5d6](https://github.com/Automattic/newspack-plugin/commit/220d5d6f8f21b5d5718f353a63ec550834fc2169))
* **corrections-modal:** Improve Corrections Modal UX ([#3835](https://github.com/Automattic/newspack-plugin/issues/3835)) ([afc9844](https://github.com/Automattic/newspack-plugin/commit/afc9844914c34b035a873535d0e0475971b19644))
* enable email change for newspack users ([#3824](https://github.com/Automattic/newspack-plugin/issues/3824)) ([1bfb458](https://github.com/Automattic/newspack-plugin/commit/1bfb45830132d9107e7c043416350e21e08cdc1b))
* information architecture ([#3857](https://github.com/Automattic/newspack-plugin/issues/3857)) ([6fb5951](https://github.com/Automattic/newspack-plugin/commit/6fb5951f55f11cdc9ce99a1a0d63ec563de97c85))
* **media:** enable setting a default image for 404 images ([#3811](https://github.com/Automattic/newspack-plugin/issues/3811)) ([465928e](https://github.com/Automattic/newspack-plugin/commit/465928e2f89e8a3b167948ca33689c77c74e61a6))
* **memberships:** status reevaluation ([#3845](https://github.com/Automattic/newspack-plugin/issues/3845)) ([ba4bea9](https://github.com/Automattic/newspack-plugin/commit/ba4bea9011eb8482b35afe8e6c7b18f5a566818e))
* **my-account:** disable WC password nag ([a22e756](https://github.com/Automattic/newspack-plugin/commit/a22e756e627ab7dda9b7289abd2b73041905e7dd))
* **rss-feed:** add feed enhancements for republication tracker; Atom feed URL ([#3801](https://github.com/Automattic/newspack-plugin/issues/3801)) ([719983f](https://github.com/Automattic/newspack-plugin/commit/719983f86c62525950a8bcf87c56c9e82735e213))
* update custom bylines data structure ([#3863](https://github.com/Automattic/newspack-plugin/issues/3863)) ([9b66c1f](https://github.com/Automattic/newspack-plugin/commit/9b66c1fc24c615b36b4b5e85dc716e37c7a83feb))
* **woo-member-commenting:** optional module for member commenting ([#3783](https://github.com/Automattic/newspack-plugin/issues/3783)) ([262f8bf](https://github.com/Automattic/newspack-plugin/commit/262f8bf9f49b264ab373b13fed3af783c6400c99))


### Reverts

* Revert "refactor(corrections): remove corrections feature flag (#3797)" (#3825) ([f5f6a5c](https://github.com/Automattic/newspack-plugin/commit/f5f6a5cba589215a3697ab4fe39173c9a73e42e9)), closes [#3797](https://github.com/Automattic/newspack-plugin/issues/3797) [#3825](https://github.com/Automattic/newspack-plugin/issues/3825)

## [6.1.3](https://github.com/Automattic/newspack-plugin/compare/v6.1.2...v6.1.3) (2025-03-25)


### Bug Fixes

* **esp-sync:** account for wp errors in email change sync ([#3860](https://github.com/Automattic/newspack-plugin/issues/3860)) ([d5d576d](https://github.com/Automattic/newspack-plugin/commit/d5d576d207ead6f520efce6925566e2260ebbf65))

## [6.1.2](https://github.com/Automattic/newspack-plugin/compare/v6.1.1...v6.1.2) (2025-03-25)


### Bug Fixes

* **reader-revenue:** fatal when using woocommerce-paypal-payments@3.0.0 ([#3848](https://github.com/Automattic/newspack-plugin/issues/3848)) ([a3f6d6a](https://github.com/Automattic/newspack-plugin/commit/a3f6d6ac4ea2f277e888691b52e3c2dae865e52a))
* **woocommerce-email:** fix the subscription cancellation email ([fc1cc8c](https://github.com/Automattic/newspack-plugin/commit/fc1cc8cb2948512d1e6b279696595da784ade0bf))

## [6.1.1](https://github.com/Automattic/newspack-plugin/compare/v6.1.0...v6.1.1) (2025-03-20)


### Bug Fixes

* **woocommerce:** my-account redirect when resubscribing ([#3822](https://github.com/Automattic/newspack-plugin/issues/3822)) ([1b24e9c](https://github.com/Automattic/newspack-plugin/commit/1b24e9cb7f3ccf0fc603ef319ff379b232f43970))

# [6.1.0](https://github.com/Automattic/newspack-plugin/compare/v6.0.5...v6.1.0) (2025-03-18)


### Bug Fixes

* **modal-checkout:** endpoint to refresh newsletter lists via REST ([#3841](https://github.com/Automattic/newspack-plugin/issues/3841)) ([2b294e0](https://github.com/Automattic/newspack-plugin/commit/2b294e0c8bcde03e3fe88cf45ca4843bfda55eb7))
* **modal-checkout:** password setup ([8fbfabd](https://github.com/Automattic/newspack-plugin/commit/8fbfabdf070337dd2873315fd1d22139ad08d6b0))
* **my-account:** handle email change esp sync errors ([#3792](https://github.com/Automattic/newspack-plugin/issues/3792)) ([3d9f294](https://github.com/Automattic/newspack-plugin/commit/3d9f294c2a17c2a37f3405f20073a8cc24bf7e1d))
* **my-account:** send change email to old and new emails ([#3786](https://github.com/Automattic/newspack-plugin/issues/3786)) ([710d53d](https://github.com/Automattic/newspack-plugin/commit/710d53d7e01318c8a0e1d2ea66ecf78b2a9f086a))
* **premium-newsletters:** show premium lists in post-checkout signup ([#3788](https://github.com/Automattic/newspack-plugin/issues/3788)) ([ccb1526](https://github.com/Automattic/newspack-plugin/commit/ccb15262de34948def8072f7c6e2961a0327e15a))
* spacer block handling with registration block ([e9b7beb](https://github.com/Automattic/newspack-plugin/commit/e9b7beb21efbc835ee72d5eaf3c5e1e908141c8c))


### Features

* **correction-blocks:** Correction box & Loop item + Template ([#3787](https://github.com/Automattic/newspack-plugin/issues/3787)) ([c215dc2](https://github.com/Automattic/newspack-plugin/commit/c215dc26b061fc952a4bb877c872e742307b9b45))
* **correction-blocks:** update corrections template ([#3793](https://github.com/Automattic/newspack-plugin/issues/3793)) ([c7aea33](https://github.com/Automattic/newspack-plugin/commit/c7aea338d821522f5e14cc646d996e8730c05fc9))
* enable email change for newspack users ([#3824](https://github.com/Automattic/newspack-plugin/issues/3824)) ([9c152a8](https://github.com/Automattic/newspack-plugin/commit/9c152a896a4b2649b3bb6ae0d9e4bc7ced2f65ae))
* **my-account:** add email change cancellation option ([#3778](https://github.com/Automattic/newspack-plugin/issues/3778)) ([600ad61](https://github.com/Automattic/newspack-plugin/commit/600ad6199f38b89a5be1b50f518159fa4cd5011d))
* **my-account:** sync admin email change with ESP/stripe ([#3799](https://github.com/Automattic/newspack-plugin/issues/3799)) ([7179ffd](https://github.com/Automattic/newspack-plugin/commit/7179ffd512b5589bee0149d3a27fe46fc662dec3))
* **my-account:** sync email change with esp ([#3780](https://github.com/Automattic/newspack-plugin/issues/3780)) ([983c087](https://github.com/Automattic/newspack-plugin/commit/983c087ce6aff32174db49a44f606269071cfb3c))
* **my-account:** sync email change with stripe ([#3789](https://github.com/Automattic/newspack-plugin/issues/3789)) ([4f45795](https://github.com/Automattic/newspack-plugin/commit/4f4579543b290c9f189982b421032a0f505c3e8d))
* **woo-member-commenting:** optional module for member commenting ([#3783](https://github.com/Automattic/newspack-plugin/issues/3783)) ([90746c8](https://github.com/Automattic/newspack-plugin/commit/90746c8f5c6f22e98daeb4455c3aa98b895e2d4b))


### Reverts

* Revert "refactor(corrections): remove corrections feature flag (#3797)" (#3825) ([afd01f2](https://github.com/Automattic/newspack-plugin/commit/afd01f2c126884303ac85c35f77686765d9107c7)), closes [#3797](https://github.com/Automattic/newspack-plugin/issues/3797) [#3825](https://github.com/Automattic/newspack-plugin/issues/3825)

## [6.0.5](https://github.com/Automattic/newspack-plugin/compare/v6.0.4...v6.0.5) (2025-03-18)


### Bug Fixes

* dont sync info about failder orders ([#3842](https://github.com/Automattic/newspack-plugin/issues/3842)) ([1cb927b](https://github.com/Automattic/newspack-plugin/commit/1cb927b664a848e5aca8e31cf1be0c67fd1ede38))

## [6.0.4](https://github.com/Automattic/newspack-plugin/compare/v6.0.3...v6.0.4) (2025-03-12)


### Bug Fixes

* **nrh:** fixes for ESP contact syncing w/ NRH, newsletter signups ([#3779](https://github.com/Automattic/newspack-plugin/issues/3779)) ([88c8dc6](https://github.com/Automattic/newspack-plugin/commit/88c8dc63805e3d94d69be9af4719434947309d02))

## [6.0.3](https://github.com/Automattic/newspack-plugin/compare/v6.0.2...v6.0.3) (2025-03-07)


### Bug Fixes

* avoid fatal when sitekit is not configured ([#3809](https://github.com/Automattic/newspack-plugin/issues/3809)) ([fd0fa40](https://github.com/Automattic/newspack-plugin/commit/fd0fa400cb4aa0cbe29034d3da85b014ba8d285b))

## [6.0.2](https://github.com/Automattic/newspack-plugin/compare/v6.0.1...v6.0.2) (2025-03-07)


### Bug Fixes

* **google-site-kit:** handle updated analytics option name ([b2c98f3](https://github.com/Automattic/newspack-plugin/commit/b2c98f32e9a435a6fdeecf912fa688a55a2b0d1e))

## [6.0.1](https://github.com/Automattic/newspack-plugin/compare/v6.0.0...v6.0.1) (2025-03-06)


### Bug Fixes

* is-without-password condition ([707c245](https://github.com/Automattic/newspack-plugin/commit/707c2451c9c75e9e12190aa726ec3de5eb5b80e9))

# [6.0.0](https://github.com/Automattic/newspack-plugin/compare/v5.15.0...v6.0.0) (2025-03-04)


### Bug Fixes

* **campaigns:** remove placeholder Analytics admin page ([#3729](https://github.com/Automattic/newspack-plugin/issues/3729)) ([abd1bc2](https://github.com/Automattic/newspack-plugin/commit/abd1bc2b4128415a0c0c26a861448de6d5f6b203))
* **corrections:** address feedbacks on improving code ([b84fab2](https://github.com/Automattic/newspack-plugin/commit/b84fab24033280e98eee692a8b204099b16906a0))
* **corrections:** improve code formatting ([11ed58c](https://github.com/Automattic/newspack-plugin/commit/11ed58c1b8490870104595b391b38f63a8dd7860))
* **donations:** handle trashed products and avoid creating dupes ([#3760](https://github.com/Automattic/newspack-plugin/issues/3760)) ([5e78832](https://github.com/Automattic/newspack-plugin/commit/5e788327a8471ee67df92d2f9a2d0ffa0eaf62f8))
* **esp-sync:** transform outgoing dates to site timzeone ([#3728](https://github.com/Automattic/newspack-plugin/issues/3728)) ([77dc361](https://github.com/Automattic/newspack-plugin/commit/77dc36149292aefc8f0b3a593ebadaea0870e084))
* **perfmatters:** default for lazyload img setting parent selector ([#3753](https://github.com/Automattic/newspack-plugin/issues/3753)) ([491e335](https://github.com/Automattic/newspack-plugin/commit/491e335e39b4fba83a38b63ed071905e78308b33))
* **recaptcha:** improvements for reCAPTCHA v2 + modal checkout ([#3692](https://github.com/Automattic/newspack-plugin/issues/3692)) ([c4738a7](https://github.com/Automattic/newspack-plugin/commit/c4738a7f68f4335d5774e9cb8e9ce5f7b590848f))
* **recaptcha:** no need to scroll to top when showing v2 widget ([#3741](https://github.com/Automattic/newspack-plugin/issues/3741)) ([882d55c](https://github.com/Automattic/newspack-plugin/commit/882d55cf09a04941f5dc2166ffce86730763e1aa))
* undo forcing WC order attribution to off ([#3771](https://github.com/Automattic/newspack-plugin/issues/3771)) ([c9cb52a](https://github.com/Automattic/newspack-plugin/commit/c9cb52ac484b11a3f508436cb4061da5a16a9001))
* **woocommerce:** add team name to checkouts for memberships-for-teams ([#3752](https://github.com/Automattic/newspack-plugin/issues/3752)) ([e3661c6](https://github.com/Automattic/newspack-plugin/commit/e3661c6c3dfd6237d4e700a6ba60ca982ea23eb6))


### Features

* add corrections customize settings ([#3751](https://github.com/Automattic/newspack-plugin/issues/3751)) ([11dbc5e](https://github.com/Automattic/newspack-plugin/commit/11dbc5e1ef49b51d2a0915b9655c9bb6f9cfec4c))
* **corrections-modal:** refactor corrections admin UI ([0adac63](https://github.com/Automattic/newspack-plugin/commit/0adac63184addc7e6ea9fd5fb4256caa023c6d42))
* **corrections:** add date handling to corrections and enhance UI ([4c77020](https://github.com/Automattic/newspack-plugin/commit/4c77020229250801d34d30b2bf48417a2616e360))
* **corrections:** add site timezone handling logic for correction date ([28d3bb9](https://github.com/Automattic/newspack-plugin/commit/28d3bb9bda5941a8eea9ce6aa9036e68e3879f03))
* **corrections:** Unit tests for Corrections functionality ([#3776](https://github.com/Automattic/newspack-plugin/issues/3776)) ([ae58933](https://github.com/Automattic/newspack-plugin/commit/ae58933aa708e034bb7402c353a584a9a9f16d82))
* **corrections:** update style of modal in the editor ([#3766](https://github.com/Automattic/newspack-plugin/issues/3766)) ([0aee542](https://github.com/Automattic/newspack-plugin/commit/0aee5427077bd3d53e147e07b2095e9cdcf579e1))
* **esp-sync:** queue data events sync to run once ([#3661](https://github.com/Automattic/newspack-plugin/issues/3661)) ([dd2b499](https://github.com/Automattic/newspack-plugin/commit/dd2b4996e54600ea0c1243545d90c89a1d0d8dc6))
* fixes and improvements for WooCommerce Subscriptions Gifting ([#3747](https://github.com/Automattic/newspack-plugin/issues/3747)) ([49c4b35](https://github.com/Automattic/newspack-plugin/commit/49c4b3579a5a1283a4e4fccf507a6fa7cea59020))
* handle user nicename change ([#3725](https://github.com/Automattic/newspack-plugin/issues/3725)) ([cb045a3](https://github.com/Automattic/newspack-plugin/commit/cb045a36aaf8fa2312dc141d89cf594934e8c201))
* **my-account:** add change email template ([#3772](https://github.com/Automattic/newspack-plugin/issues/3772)) ([32bef3c](https://github.com/Automattic/newspack-plugin/commit/32bef3c75fa09b85323d7e99be603bc808efd6a1))
* **my-account:** add email change feature flag ([#3758](https://github.com/Automattic/newspack-plugin/issues/3758)) ([21f2c30](https://github.com/Automattic/newspack-plugin/commit/21f2c3089c2501a2eaec544811feb24069b92f9e))
* **my-account:** add pending email change state ([#3763](https://github.com/Automattic/newspack-plugin/issues/3763)) ([c9ba046](https://github.com/Automattic/newspack-plugin/commit/c9ba046347b198cda6d74ea2acc02f76411d0e63))
* **my-account:** verify email change ([#3764](https://github.com/Automattic/newspack-plugin/issues/3764)) ([b50c980](https://github.com/Automattic/newspack-plugin/commit/b50c9800fe29f17432abd446e6d539b727c7fc37))
* rate limit adding new payment methods by user ([#3679](https://github.com/Automattic/newspack-plugin/issues/3679)) ([0fd5ea5](https://github.com/Automattic/newspack-plugin/commit/0fd5ea5947b5da04b2d69b836272e111b78a8306))


### BREAKING CHANGES

* **recaptcha:** shippable product orders will auto-complete by default after this change.

* chore: undo unwanted change

# [5.15.0](https://github.com/Automattic/newspack-plugin/compare/v5.14.4...v5.15.0) (2025-02-27)


### Features

* trigger release ([2f3b937](https://github.com/Automattic/newspack-plugin/commit/2f3b937060b557185f1c85de19264ca9945147b6))

## [5.14.4](https://github.com/Automattic/newspack-plugin/compare/v5.14.3...v5.14.4) (2025-02-25)


### Bug Fixes

* **woo:** page template meta leaking to other types ([#3782](https://github.com/Automattic/newspack-plugin/issues/3782)) ([325a21c](https://github.com/Automattic/newspack-plugin/commit/325a21cd0076af68b3057bae43fbda74943addc3))

## [5.14.3](https://github.com/Automattic/newspack-plugin/compare/v5.14.2...v5.14.3) (2025-02-24)


### Bug Fixes

* **memberships:** ensure user membership is linked to the correct subscription ([#3768](https://github.com/Automattic/newspack-plugin/issues/3768)) ([a942616](https://github.com/Automattic/newspack-plugin/commit/a942616d4633ed48c33396b6c90ffa31a6a67bb6))

## [5.14.2](https://github.com/Automattic/newspack-plugin/compare/v5.14.1...v5.14.2) (2025-02-20)


### Bug Fixes

* **esp-sync:** transform outgoing dates to site timzeone ([#3728](https://github.com/Automattic/newspack-plugin/issues/3728)) ([#3765](https://github.com/Automattic/newspack-plugin/issues/3765)) ([7867af3](https://github.com/Automattic/newspack-plugin/commit/7867af3b0e3c73b6905415700dfccc32916e8cdf))

## [5.14.1](https://github.com/Automattic/newspack-plugin/compare/v5.14.0...v5.14.1) (2025-02-18)


### Bug Fixes

* **esp-sync:** broken CLI sync command ([#3762](https://github.com/Automattic/newspack-plugin/issues/3762)) ([f609670](https://github.com/Automattic/newspack-plugin/commit/f6096706dfba077b13dd3e91687cb4e949ed50b5))

# [5.14.0](https://github.com/Automattic/newspack-plugin/compare/v5.13.5...v5.14.0) (2025-02-17)


### Features

* **esp-sync:** queue data events sync to run once ([#3661](https://github.com/Automattic/newspack-plugin/issues/3661)) ([#3759](https://github.com/Automattic/newspack-plugin/issues/3759)) ([74c7122](https://github.com/Automattic/newspack-plugin/commit/74c7122b06e64819fdef1b251f25cb35cc1ddb30))

## [5.13.5](https://github.com/Automattic/newspack-plugin/compare/v5.13.4...v5.13.5) (2025-02-17)


### Bug Fixes

* add check for newsletter modal and fix typo ([#3727](https://github.com/Automattic/newspack-plugin/issues/3727)) ([5fdcab6](https://github.com/Automattic/newspack-plugin/commit/5fdcab688bb5654011fd34b0357bf3d4d28c7ee4))
* check for WC as well as WCS ([#3720](https://github.com/Automattic/newspack-plugin/issues/3720)) ([f91803a](https://github.com/Automattic/newspack-plugin/commit/f91803a7f482893d663b0e16fa67a5d8122152f8))
* make the cart, checkout pages use shortcodes on install ([#3699](https://github.com/Automattic/newspack-plugin/issues/3699)) ([954e01b](https://github.com/Automattic/newspack-plugin/commit/954e01b792d425f670f415fc4d3142e1b4bcb8ba))
* update corrections post type slug ([#3702](https://github.com/Automattic/newspack-plugin/issues/3702)) ([5f100ba](https://github.com/Automattic/newspack-plugin/commit/5f100ba9f155d5b9a158bd2008107938f6e3eb15))

## [5.13.4](https://github.com/Automattic/newspack-plugin/compare/v5.13.3...v5.13.4) (2025-02-14)


### Bug Fixes

* **wcs:** account for pagination and failed scheduled retries in on-hold migration script ([#3750](https://github.com/Automattic/newspack-plugin/issues/3750)) ([171b29e](https://github.com/Automattic/newspack-plugin/commit/171b29ea6ce5caaf812f2ed93e8fbbdac58ff5d1))

## [5.13.3](https://github.com/Automattic/newspack-plugin/compare/v5.13.2...v5.13.3) (2025-02-12)


### Bug Fixes

* **wcs:** remove cli error call ([#3745](https://github.com/Automattic/newspack-plugin/issues/3745)) ([6c98fa0](https://github.com/Automattic/newspack-plugin/commit/6c98fa08e4ae87cf112ff11d01716f353272c50c))

## [5.13.2](https://github.com/Automattic/newspack-plugin/compare/v5.13.1...v5.13.2) (2025-02-11)


### Bug Fixes

* **wcs:** account for other on-hold cases ([#3723](https://github.com/Automattic/newspack-plugin/issues/3723)) ([0b3406a](https://github.com/Automattic/newspack-plugin/commit/0b3406a35bf9f7a8c74881ebe493a8db1bc0c77c))

## [5.13.1](https://github.com/Automattic/newspack-plugin/compare/v5.13.0...v5.13.1) (2025-02-06)


### Bug Fixes

* **esp-sync:** get last payment amount from actual completed order ([#3726](https://github.com/Automattic/newspack-plugin/issues/3726)) ([db284c1](https://github.com/Automattic/newspack-plugin/commit/db284c11a3c5f3308e17c799733d8a1d680ffaa3))

# [5.13.0](https://github.com/Automattic/newspack-plugin/compare/v5.12.5...v5.13.0) (2025-02-03)


### Bug Fixes

* add supported gateways check ([#3650](https://github.com/Automattic/newspack-plugin/issues/3650)) ([74f7773](https://github.com/Automattic/newspack-plugin/commit/74f77735a5135b004516f8d1217d8752d2c8fadd))
* **corrections:** replace deprecated sanitize method ([#3694](https://github.com/Automattic/newspack-plugin/issues/3694)) ([ce50e24](https://github.com/Automattic/newspack-plugin/commit/ce50e247462672d024a6f93e37d7a9f5e4c934ee))
* remove support for legacy form checkout ([#3691](https://github.com/Automattic/newspack-plugin/issues/3691)) ([46a3c16](https://github.com/Automattic/newspack-plugin/commit/46a3c160a0146e78cc5b8213b105d10fa523f797))
* **wcs:** expire manual subscriptions after on-hold duration ([#3681](https://github.com/Automattic/newspack-plugin/issues/3681)) ([658416c](https://github.com/Automattic/newspack-plugin/commit/658416c889c868d0c36c538fba63cd422425dfd4))


### Features

* add custom bylines ([#3667](https://github.com/Automattic/newspack-plugin/issues/3667)) ([3f45a6f](https://github.com/Automattic/newspack-plugin/commit/3f45a6fa9075bab583f30a03680eaa287436b7da))
* rate limit checkout attempts ([#3678](https://github.com/Automattic/newspack-plugin/issues/3678)) ([d275524](https://github.com/Automattic/newspack-plugin/commit/d275524695f72b97b6d92b7c97ab5639aba9674c))
* **reader-revenue:** add PayPal Payments gateway to wizard ([#3665](https://github.com/Automattic/newspack-plugin/issues/3665)) ([1476eed](https://github.com/Automattic/newspack-plugin/commit/1476eed84788dd28b1852519d5aafa793159f1f3))

## [5.12.5](https://github.com/Automattic/newspack-plugin/compare/v5.12.4...v5.12.5) (2025-01-30)


### Bug Fixes

* **reader-revenue:** collect transaction fee settings ([#3697](https://github.com/Automattic/newspack-plugin/issues/3697)) ([28ff2cd](https://github.com/Automattic/newspack-plugin/commit/28ff2cdcdee78684de23d5cbf1fda12d471f721b))

## [5.12.4](https://github.com/Automattic/newspack-plugin/compare/v5.12.3...v5.12.4) (2025-01-28)


### Bug Fixes

* create guest contributors dummy emails ([#3705](https://github.com/Automattic/newspack-plugin/issues/3705)) ([528712c](https://github.com/Automattic/newspack-plugin/commit/528712c2638c105f15557facb3611585efda2398))

## [5.12.3](https://github.com/Automattic/newspack-plugin/compare/v5.12.2...v5.12.3) (2025-01-23)


### Bug Fixes

* **reader-data:** reduce number of sync requests ([c23c580](https://github.com/Automattic/newspack-plugin/commit/c23c5805a72557b7e684b1bc50939603239733b8))

## [5.12.2](https://github.com/Automattic/newspack-plugin/compare/v5.12.1...v5.12.2) (2025-01-23)


### Bug Fixes

* hotfix checkout tweaks ([#3693](https://github.com/Automattic/newspack-plugin/issues/3693)) ([7be88aa](https://github.com/Automattic/newspack-plugin/commit/7be88aa53786e2916151bd427980bdd1bcf56c6b))

## [5.12.1](https://github.com/Automattic/newspack-plugin/compare/v5.12.0...v5.12.1) (2025-01-21)


### Bug Fixes

* **ras-sync:** account for missing creation data and first/last name ([#3677](https://github.com/Automattic/newspack-plugin/issues/3677)) ([9ed12bc](https://github.com/Automattic/newspack-plugin/commit/9ed12bc86eca956b2e4a047feba9042db168e46f))

# [5.12.0](https://github.com/Automattic/newspack-plugin/compare/v5.11.3...v5.12.0) (2025-01-20)


### Bug Fixes

* **cli:** verify-reader CLI command ([#3660](https://github.com/Automattic/newspack-plugin/issues/3660)) ([c639af7](https://github.com/Automattic/newspack-plugin/commit/c639af78b9ba7cf73ef01e7f499a2389e0626343))
* **recaptcha:** replace alerts with generic errors ([#3627](https://github.com/Automattic/newspack-plugin/issues/3627)) ([44ef2d2](https://github.com/Automattic/newspack-plugin/commit/44ef2d2a4b38483bce979a4c68b0aa2afb4d0a9a))
* remove newspack_corrections_ids meta ([#3675](https://github.com/Automattic/newspack-plugin/issues/3675)) ([dad258b](https://github.com/Automattic/newspack-plugin/commit/dad258b2e113310fd312e345f80fc54bb84cf928))
* **wcs:** migrate-expired-subscriptions handle manual subscriptions ([#3663](https://github.com/Automattic/newspack-plugin/issues/3663)) ([e0f32e8](https://github.com/Automattic/newspack-plugin/commit/e0f32e87eba3e9562f6f7b40d185c9308d86ae84))


### Features

* **corrections:** add corrections and clarifications behind feature flag ([#3638](https://github.com/Automattic/newspack-plugin/issues/3638)) ([ea745cf](https://github.com/Automattic/newspack-plugin/commit/ea745cff1a77bf422bb6f1c77b4dd2ca83e8ff69))


### Performance Improvements

* **data-events:** queue dispatches to execute on shutdown ([#3616](https://github.com/Automattic/newspack-plugin/issues/3616)) ([510a1a0](https://github.com/Automattic/newspack-plugin/commit/510a1a04881c9bccf3389ecd6dbf6b82a13d461b))

## [5.11.3](https://github.com/Automattic/newspack-plugin/compare/v5.11.2...v5.11.3) (2025-01-17)


### Bug Fixes

* **esp-sync:** sync non-donation subscription data even if no completed orders ([#3680](https://github.com/Automattic/newspack-plugin/issues/3680)) ([dd0898f](https://github.com/Automattic/newspack-plugin/commit/dd0898fe92c851631687f78a24c42b4b74d2f0b1))

## [5.11.2](https://github.com/Automattic/newspack-plugin/compare/v5.11.1...v5.11.2) (2025-01-16)


### Bug Fixes

* **mc:** handle standard MC merge fields when fixing duplicates ([#3637](https://github.com/Automattic/newspack-plugin/issues/3637)) ([80248a2](https://github.com/Automattic/newspack-plugin/commit/80248a235c6aa295bc38fe77e0ab489ac39ed46c))

## [5.11.1](https://github.com/Automattic/newspack-plugin/compare/v5.11.0...v5.11.1) (2025-01-14)


### Bug Fixes

* **recaptcha:** remove modal checkout restriction from v2 ([#3674](https://github.com/Automattic/newspack-plugin/issues/3674)) ([66bd2c8](https://github.com/Automattic/newspack-plugin/commit/66bd2c8df79f94b8ed1aca943e3081c1bd4e7529))

# [5.11.0](https://github.com/Automattic/newspack-plugin/compare/v5.10.6...v5.11.0) (2025-01-13)


### Features

* **my-account:** reCAPTCHA check on add payment method ([#3673](https://github.com/Automattic/newspack-plugin/issues/3673)) ([4a46c8e](https://github.com/Automattic/newspack-plugin/commit/4a46c8e77f4b785bf5317812f8390cb7accddbc2))

## [5.10.6](https://github.com/Automattic/newspack-plugin/compare/v5.10.5...v5.10.6) (2025-01-09)


### Bug Fixes

* **esp-sync:** get inactive subscriptions with failed or pending orders ([#3658](https://github.com/Automattic/newspack-plugin/issues/3658)) ([cca29d3](https://github.com/Automattic/newspack-plugin/commit/cca29d31d4b489a0c23fcb1dfbc50ae10e4bdc85))

## [5.10.5](https://github.com/Automattic/newspack-plugin/compare/v5.10.4...v5.10.5) (2025-01-07)


### Bug Fixes

* **recaptcha:** verify v2 on all modal checkout requests ([#3644](https://github.com/Automattic/newspack-plugin/issues/3644)) ([88277e9](https://github.com/Automattic/newspack-plugin/commit/88277e9b77151b8dc176cae09b2a92989ac4ddf4))

## [5.10.4](https://github.com/Automattic/newspack-plugin/compare/v5.10.3...v5.10.4) (2024-12-23)


### Bug Fixes

* dont try to get orders if Woo is not active ([#3649](https://github.com/Automattic/newspack-plugin/issues/3649)) ([608f017](https://github.com/Automattic/newspack-plugin/commit/608f017000b59e8b3bdcd81b14d81969bc72b379))

## [5.10.3](https://github.com/Automattic/newspack-plugin/compare/v5.10.2...v5.10.3) (2024-12-20)


### Bug Fixes

* **stripe:** when adding new card, update subs of all statuses ([#3643](https://github.com/Automattic/newspack-plugin/issues/3643)) ([62fe022](https://github.com/Automattic/newspack-plugin/commit/62fe02231e891fa7a9bc9d5f573ba336b6cb03f3))

## [5.10.2](https://github.com/Automattic/newspack-plugin/compare/v5.10.1...v5.10.2) (2024-12-17)


### Bug Fixes

* **recaptcha:** various optimizations ([#3631](https://github.com/Automattic/newspack-plugin/issues/3631)) ([5d816bc](https://github.com/Automattic/newspack-plugin/commit/5d816bcc94f49538399bd44572f6d78e739806da)), closes [#3627](https://github.com/Automattic/newspack-plugin/issues/3627)

## [5.10.1](https://github.com/Automattic/newspack-plugin/compare/v5.10.0...v5.10.1) (2024-12-16)


### Bug Fixes

* **sync:** strip unsuffixed UTM meta keys from normalized contact data ([#3623](https://github.com/Automattic/newspack-plugin/issues/3623)) ([5bbcc7b](https://github.com/Automattic/newspack-plugin/commit/5bbcc7bc505c1ee022b66285f5753b41705b5c22))

# [5.10.0](https://github.com/Automattic/newspack-plugin/compare/v5.9.2...v5.10.0) (2024-12-16)


### Bug Fixes

* dont load textdomain too early ([#3629](https://github.com/Automattic/newspack-plugin/issues/3629)) ([76c1f97](https://github.com/Automattic/newspack-plugin/commit/76c1f975a93fab6bbcbe97c886126a2b25afeb49))
* duplicate orders save on cron ([#3604](https://github.com/Automattic/newspack-plugin/issues/3604)) ([ec69167](https://github.com/Automattic/newspack-plugin/commit/ec691677e60a55f63f7832e195d8301649bb52ee))
* hide duplicate notices if all was dismissed ([#3630](https://github.com/Automattic/newspack-plugin/issues/3630)) ([cf48188](https://github.com/Automattic/newspack-plugin/commit/cf481888db82f45ea3a350e129ed4041b2be988d))
* **ras-acc:** re-add recaptcha to the WooCommerce checkout ([#3605](https://github.com/Automattic/newspack-plugin/issues/3605)) ([f42a75b](https://github.com/Automattic/newspack-plugin/commit/f42a75b598b08a92352ec18afd5d2a8f9178c04e))
* **ras:** do not require Woo plugins if using NRH ([#3614](https://github.com/Automattic/newspack-plugin/issues/3614)) ([363a834](https://github.com/Automattic/newspack-plugin/commit/363a834539652e9eb358d42121ee07880e8861ae))
* **wcs:** remove subscriptions expiration feature flag ([#3618](https://github.com/Automattic/newspack-plugin/issues/3618)) ([7c175d9](https://github.com/Automattic/newspack-plugin/commit/7c175d92fa4776979565dabd9041c74a50cae859))
* **wcs:** update subscription expiration feature ([#3613](https://github.com/Automattic/newspack-plugin/issues/3613)) ([ebf6e6d](https://github.com/Automattic/newspack-plugin/commit/ebf6e6d8cdf0c7eecf9e7918675b38405433deeb))
* **wcs:** update subscriptions expiration cli behavior ([#3617](https://github.com/Automattic/newspack-plugin/issues/3617)) ([07e768c](https://github.com/Automattic/newspack-plugin/commit/07e768c74343a2877fb9bdb4a6a59b30f8fe4543))


### Features

* **subscriptions:** add cancellation reason metadata ([#3568](https://github.com/Automattic/newspack-plugin/issues/3568)) ([de83e02](https://github.com/Automattic/newspack-plugin/commit/de83e02e6b3160c520f30b7d5bcd1f6e96db8058))
* **wc:** duplicate orders admin notice ([#3555](https://github.com/Automattic/newspack-plugin/issues/3555)) ([cb764e3](https://github.com/Automattic/newspack-plugin/commit/cb764e33b4cb0b6cfed4930121db230efd2a5832))
* **wcs:** add expired subscription cli tool ([#3593](https://github.com/Automattic/newspack-plugin/issues/3593)) ([5d39398](https://github.com/Automattic/newspack-plugin/commit/5d39398e681555cd030bd8b47c60eb0eef26d6f6))
* **webhooks:** filter request priority ([#3587](https://github.com/Automattic/newspack-plugin/issues/3587)) ([1928a6a](https://github.com/Automattic/newspack-plugin/commit/1928a6a5ac627c35a7a9d2f16e3dec5ae9c459b7))
* **woocommerce-subscriptions:** add url redirect for wc subscription renewals ([#3525](https://github.com/Automattic/newspack-plugin/issues/3525)) ([5b14aeb](https://github.com/Automattic/newspack-plugin/commit/5b14aebd98a7513ca3f254339f1315175389aee6))


### Reverts

* Revert "feat: command to initialize cron job to slowly backfill CAP term data (#3425)" (#3620) ([c9a9d45](https://github.com/Automattic/newspack-plugin/commit/c9a9d45f697c337d280828f36318f8ed57c94ea8)), closes [#3425](https://github.com/Automattic/newspack-plugin/issues/3425) [#3620](https://github.com/Automattic/newspack-plugin/issues/3620)

## [5.9.2](https://github.com/Automattic/newspack-plugin/compare/v5.9.1...v5.9.2) (2024-12-13)


### Bug Fixes

* **recaptcha:** add safeguards against duplicate renders ([#3624](https://github.com/Automattic/newspack-plugin/issues/3624)) ([d95ee7e](https://github.com/Automattic/newspack-plugin/commit/d95ee7e05dc303c0a8fa6f50029e0ac7135e86b7))

## [5.9.1](https://github.com/Automattic/newspack-plugin/compare/v5.9.0...v5.9.1) (2024-12-11)


### Bug Fixes

* couple bug fixes for esp sync cli ([#3615](https://github.com/Automattic/newspack-plugin/issues/3615)) ([4557dda](https://github.com/Automattic/newspack-plugin/commit/4557ddab9b422c4d2293b6fea29fe7d3cbbfcb6d))

# [5.9.0](https://github.com/Automattic/newspack-plugin/compare/v5.8.2...v5.9.0) (2024-12-09)


### Bug Fixes

* **emails:** account for false order value ([#3590](https://github.com/Automattic/newspack-plugin/issues/3590)) ([a2e4042](https://github.com/Automattic/newspack-plugin/commit/a2e404287c7bdeba44689b1a98df9644322d4a71))
* **ras-acc:** correct My Account custom font sizing clash ([#3588](https://github.com/Automattic/newspack-plugin/issues/3588)) ([080f1ce](https://github.com/Automattic/newspack-plugin/commit/080f1ce9e3ac102bdc475b1e5fac4016d48dee2a))
* **ras-acc:** make helper text size more specific ([#3584](https://github.com/Automattic/newspack-plugin/issues/3584)) ([5bcc688](https://github.com/Automattic/newspack-plugin/commit/5bcc68812937ea979b9d28a08ca95e71fb19a341))
* **ras-acc:** re-add recaptcha to the WooCommerce checkout ([#3605](https://github.com/Automattic/newspack-plugin/issues/3605)) ([07f46b3](https://github.com/Automattic/newspack-plugin/commit/07f46b3bfd7581a6e1e05490e1b2df06677ae59e))
* undefined var ([#3585](https://github.com/Automattic/newspack-plugin/issues/3585)) ([00d8bc7](https://github.com/Automattic/newspack-plugin/commit/00d8bc7f4db78602e948a4ba7dfe5dad2b606672))
* **woocommerce-emails:** use the default email payload if there are no donation products ([#3545](https://github.com/Automattic/newspack-plugin/issues/3545)) ([60c21f3](https://github.com/Automattic/newspack-plugin/commit/60c21f31641a9bbc906d4cc4319fa7d418f8fdf1))


### Features

* mark perfmatters as a required plugin ([#3578](https://github.com/Automattic/newspack-plugin/issues/3578)) ([f20291c](https://github.com/Automattic/newspack-plugin/commit/f20291c7899e99c5d52b8663a10a8e57b7ff1029))
* **ras-acc:** add reader account creation and login improvements ([#3582](https://github.com/Automattic/newspack-plugin/issues/3582)) ([b66de08](https://github.com/Automattic/newspack-plugin/commit/b66de08dcf0a901063577c715257d7ffecd9908d))
* **reader-data:** add a CLI command to align reader membership data ([#3548](https://github.com/Automattic/newspack-plugin/issues/3548)) ([8e49bf0](https://github.com/Automattic/newspack-plugin/commit/8e49bf0a2c9e74958f80de13a52b5fa720e87949))
* **subscriptions:** add setting to reattempt payment after final retry ([#3560](https://github.com/Automattic/newspack-plugin/issues/3560)) ([553c3ac](https://github.com/Automattic/newspack-plugin/commit/553c3ac4327691b3f1826a7de6e3bd5f3d335ccf))
* **woocommerce:** remove internal metadata from REST API response ([6b659a6](https://github.com/Automattic/newspack-plugin/commit/6b659a6afb38d235cf36e116a655352523a09725))

## [5.8.2](https://github.com/Automattic/newspack-plugin/compare/v5.8.1...v5.8.2) (2024-12-09)


### Bug Fixes

* **esp-sync:** schedule second sync upon subscription reactivation, just in case ([#3603](https://github.com/Automattic/newspack-plugin/issues/3603)) ([9334295](https://github.com/Automattic/newspack-plugin/commit/93342955db2f70afa9feac6eb8fa349f3703fd72))

## [5.8.1](https://github.com/Automattic/newspack-plugin/compare/v5.8.0...v5.8.1) (2024-11-26)


### Bug Fixes

* cast values for transaction fee options ([#3580](https://github.com/Automattic/newspack-plugin/issues/3580)) ([82a513f](https://github.com/Automattic/newspack-plugin/commit/82a513fd28d5db9a09de871527bc191923e20957))

# [5.8.0](https://github.com/Automattic/newspack-plugin/compare/v5.7.0...v5.8.0) (2024-11-25)


### Bug Fixes

* **reader-registration-block:** fix styles of newsletter lists ([1e1d7a4](https://github.com/Automattic/newspack-plugin/commit/1e1d7a4a0e9945490d83b2f7e29a39dc4b3a09e6))
* **salesforce:** handle woocommerce inactive ([#3521](https://github.com/Automattic/newspack-plugin/issues/3521)) ([bf868a4](https://github.com/Automattic/newspack-plugin/commit/bf868a4b9cc69b7ced5cbe7dd1bb6197f8de6573))
* use user email for esp sync purposes ([#3520](https://github.com/Automattic/newspack-plugin/issues/3520)) ([d6925ff](https://github.com/Automattic/newspack-plugin/commit/d6925ffe8687da0a99ba05ce7ce588e850cd7d43))
* **wc-memberships:** WC for Teams join-team link; team in my-account ([#3540](https://github.com/Automattic/newspack-plugin/issues/3540)) ([489ce0f](https://github.com/Automattic/newspack-plugin/commit/489ce0fb7390f9fabc5956844ac7b437c2c701ac))


### Features

* also trigger subscription change events on switch ([#3514](https://github.com/Automattic/newspack-plugin/issues/3514)) ([5a84949](https://github.com/Automattic/newspack-plugin/commit/5a849497afd67dd73dbf81a8df4d8a68fd8cf53a))
* **pwa:** enable SW file caching ([82af7d4](https://github.com/Automattic/newspack-plugin/commit/82af7d4785e430c6d99b4399ea4e4ed5f1e09a17))
* **starter-content:** e2e improvements; removal CLI command ([35431ac](https://github.com/Automattic/newspack-plugin/commit/35431ac7f1efc4f5048bc511e6b072d0b28e65f0))
* update Stripe Settings dashboard UI ([#3488](https://github.com/Automattic/newspack-plugin/issues/3488)) ([7974fac](https://github.com/Automattic/newspack-plugin/commit/7974faccabff7dc85ce83c9f5d628e0bba20fd9d))
* **wc-memberships:** support team data in import ([63c78b2](https://github.com/Automattic/newspack-plugin/commit/63c78b2a9303b5d0fb342ee27a5560f477cef884))

# [5.7.0](https://github.com/Automattic/newspack-plugin/compare/v5.6.1...v5.7.0) (2024-11-11)


### Bug Fixes

* avoid duplicate info notices in email editors ([#3512](https://github.com/Automattic/newspack-plugin/issues/3512)) ([d38fc1a](https://github.com/Automattic/newspack-plugin/commit/d38fc1aa18f0571b2a48d1ead128347f10dce57b))
* **co-authors-plus:** CLI for migrating from CAP GA ([9a81584](https://github.com/Automattic/newspack-plugin/commit/9a81584e5771ea15d2d9e3e96a9d782ec9936f26))
* command to fix active subs w/ missing next_payment dates ([#3484](https://github.com/Automattic/newspack-plugin/issues/3484)) ([2e05fd4](https://github.com/Automattic/newspack-plugin/commit/2e05fd4a9e82c995be2a4d354112d41326017d38))
* php fatal and warning ([#3502](https://github.com/Automattic/newspack-plugin/issues/3502)) ([e089172](https://github.com/Automattic/newspack-plugin/commit/e089172f75ccf7bd16e641a257ddcf29687c6a9f))
* **site-kit:** update logger cron to hourly interval ([#3485](https://github.com/Automattic/newspack-plugin/issues/3485)) ([e3823e7](https://github.com/Automattic/newspack-plugin/commit/e3823e7b858c356b3e5e4a490e119b94e7f12285))
* **webhooks:** deprecate global endpoint ([#3492](https://github.com/Automattic/newspack-plugin/issues/3492)) ([63e8ab2](https://github.com/Automattic/newspack-plugin/commit/63e8ab22674e001b27e5158afc25c83793aed824))
* **wp-6.7:** update radio control styles ([#3518](https://github.com/Automattic/newspack-plugin/issues/3518)) ([831756e](https://github.com/Automattic/newspack-plugin/commit/831756e23c74357c88b9eb0a8d40007b7835f0cc))


### Features

* add user name to woocommerce data events ([#3473](https://github.com/Automattic/newspack-plugin/issues/3473)) ([5312d30](https://github.com/Automattic/newspack-plugin/commit/5312d30ba56d9f105d2548bc0dd6430b215cf877))
* automatically disable guest authors ([#3345](https://github.com/Automattic/newspack-plugin/issues/3345)) ([d0db6ba](https://github.com/Automattic/newspack-plugin/commit/d0db6ba6500b140c672e544b8ea04c5e92bebfca))
* **connections:** jetpack sso ([#3486](https://github.com/Automattic/newspack-plugin/issues/3486)) ([123408e](https://github.com/Automattic/newspack-plugin/commit/123408e1338491b5561b5e588d663d4184fa226b))
* display list remote name on newsletter wizard ([#3478](https://github.com/Automattic/newspack-plugin/issues/3478)) ([cd0b859](https://github.com/Automattic/newspack-plugin/commit/cd0b859fad407f5de6b840bbe2da8c596a7793a5))
* **site-kit:** add logging when site kit disconnects ([#3472](https://github.com/Automattic/newspack-plugin/issues/3472)) ([62bf98c](https://github.com/Automattic/newspack-plugin/commit/62bf98c16a17c877324e218c877ad7c33265b3bd))

## [5.6.1](https://github.com/Automattic/newspack-plugin/compare/v5.6.0...v5.6.1) (2024-11-07)


### Bug Fixes

* **my-account:** show Orders & Payment Methods for migrated subscribers ([#3504](https://github.com/Automattic/newspack-plugin/issues/3504)) ([0673c24](https://github.com/Automattic/newspack-plugin/commit/0673c24a07f806326b51922946308a2fd84f1d89))

# [5.6.0](https://github.com/Automattic/newspack-plugin/compare/v5.5.2...v5.6.0) (2024-10-28)


### Bug Fixes

* cancelled subscriptions sync ([#3466](https://github.com/Automattic/newspack-plugin/issues/3466)) ([6cad50a](https://github.com/Automattic/newspack-plugin/commit/6cad50a5254b55933bfe2ef17ee0d50782b9f5f7))
* **woocommerce:** update how order meta are updated ([#2711](https://github.com/Automattic/newspack-plugin/issues/2711)) ([ae75548](https://github.com/Automattic/newspack-plugin/commit/ae755489148dac69720c264a79323d597dcd7ac7))


### Features

* add user name to woocommerce data events ([#3473](https://github.com/Automattic/newspack-plugin/issues/3473)) ([2b57d27](https://github.com/Automattic/newspack-plugin/commit/2b57d2745ef4359ff0d286e3b463071d98cb1290))
* command to initialize cron job to slowly backfill CAP term data ([#3425](https://github.com/Automattic/newspack-plugin/issues/3425)) ([0b0d79a](https://github.com/Automattic/newspack-plugin/commit/0b0d79abb8a8747abf6b8dc30326f57e11423aa6))
* **metering:** dispatch RAS activity on content restriction ([#3437](https://github.com/Automattic/newspack-plugin/issues/3437)) ([4e1c262](https://github.com/Automattic/newspack-plugin/commit/4e1c26226035af51e41752e1188d8f58140b7ff4))

## [5.5.2](https://github.com/Automattic/newspack-plugin/compare/v5.5.1...v5.5.2) (2024-10-28)


### Bug Fixes

* let "Cover fees" checkbox work with any payment method ([#3491](https://github.com/Automattic/newspack-plugin/issues/3491)) ([74ea6cc](https://github.com/Automattic/newspack-plugin/commit/74ea6cc76a45cc76eb06d73444a9890031f0507c))

## [5.5.1](https://github.com/Automattic/newspack-plugin/compare/v5.5.0...v5.5.1) (2024-10-11)


### Bug Fixes

* **oauth:** start php session if no session available ([#3469](https://github.com/Automattic/newspack-plugin/issues/3469)) ([d3e8265](https://github.com/Automattic/newspack-plugin/commit/d3e82658b8d96fc3c08f21da0fd9a338b8de5b1e))

# [5.5.0](https://github.com/Automattic/newspack-plugin/compare/v5.4.1...v5.5.0) (2024-10-08)


### Bug Fixes

* cancelled subscriptions sync ([#3466](https://github.com/Automattic/newspack-plugin/issues/3466)) ([b605a7f](https://github.com/Automattic/newspack-plugin/commit/b605a7f621e33f0f7d7bf4be44311cc477274da6))
* change the current product criteria for sync ([#3416](https://github.com/Automattic/newspack-plugin/issues/3416)) ([28a84bc](https://github.com/Automattic/newspack-plugin/commit/28a84bc81260adb4fcbffc54f384692a6fa6f50c))
* **esp-meta:** handle state of the 'Woo Team' meta ([#3352](https://github.com/Automattic/newspack-plugin/issues/3352)) ([ba5ea1e](https://github.com/Automattic/newspack-plugin/commit/ba5ea1e2562d511e1e57e8abe158b043ba283bdb))
* **esp-sync:** sync Connected Account field ([#3414](https://github.com/Automattic/newspack-plugin/issues/3414)) ([61c02bc](https://github.com/Automattic/newspack-plugin/commit/61c02bc861a850eee93519b6ae49b309272286bc))
* **esp-wc-metadata:** last payment date & amount handling ([#3363](https://github.com/Automattic/newspack-plugin/issues/3363)) ([d1abbfe](https://github.com/Automattic/newspack-plugin/commit/d1abbfea06dcc6803a0ddce36d595692ec6280a1))
* **guest-author:** enqueue the guest author admin script selectively ([0bf37af](https://github.com/Automattic/newspack-plugin/commit/0bf37af11279c4c5a662ac93fb76bd8bd92a2acf))
* hide My Account links if not relevant ([#3394](https://github.com/Automattic/newspack-plugin/issues/3394)) ([0d039d8](https://github.com/Automattic/newspack-plugin/commit/0d039d86e101f718cc75095204a867321dc239bd))
* make email template fetching deterministic ([#3341](https://github.com/Automattic/newspack-plugin/issues/3341)) ([ace91aa](https://github.com/Automattic/newspack-plugin/commit/ace91aa72a65dd582b705b38c037a75e029aa643))
* **phpcs:** specify path in custom ruleset ref ([#3384](https://github.com/Automattic/newspack-plugin/issues/3384)) ([b143e74](https://github.com/Automattic/newspack-plugin/commit/b143e74aae1792578de8d713c67356a8f040e7f3))
* prevent PHP notice while checking my-account page ([#3435](https://github.com/Automattic/newspack-plugin/issues/3435)) ([146a26f](https://github.com/Automattic/newspack-plugin/commit/146a26ffa89198823e0bc097c8f1e48364f1c042))
* **ras-sync:** deprecate redundant Signup_Page meta field ([#3439](https://github.com/Automattic/newspack-plugin/issues/3439)) ([61d6de8](https://github.com/Automattic/newspack-plugin/commit/61d6de83c8572be91690e856bef657630df8718d))
* **reader-registration-block:** fix initial newsletter checkbox state ([1890efe](https://github.com/Automattic/newspack-plugin/commit/1890efee2870ca1cf4012518f7153787562107f7))
* replace `newspack_image_credits_placeholder` default value ([#3433](https://github.com/Automattic/newspack-plugin/issues/3433)) ([c754fc2](https://github.com/Automattic/newspack-plugin/commit/c754fc251d874ebbc2733d144ddd31c8eeece12b))
* **sync:** method name for `membership_saved` handler ([#3399](https://github.com/Automattic/newspack-plugin/issues/3399)) ([2c0bf26](https://github.com/Automattic/newspack-plugin/commit/2c0bf26f81a28fd2ca43ba394ded77246a44e8c4))
* **sync:** place esp sync admin features behind a constant ([#3438](https://github.com/Automattic/newspack-plugin/issues/3438)) ([20a0970](https://github.com/Automattic/newspack-plugin/commit/20a09705b0cc98c52b4da6d5d6d338187b673919))
* **sync:** remove localized number format ([#3434](https://github.com/Automattic/newspack-plugin/issues/3434)) ([2243a5d](https://github.com/Automattic/newspack-plugin/commit/2243a5d175f3387adc193371024fa2710288517c))
* wizards - update type check conditional for `custom_logo` ([#3442](https://github.com/Automattic/newspack-plugin/issues/3442)) ([125f756](https://github.com/Automattic/newspack-plugin/commit/125f7562f9e85eb7205325669debde7c9ef95dfa))
* woocommerce connection tests ([#3372](https://github.com/Automattic/newspack-plugin/issues/3372)) ([5cea128](https://github.com/Automattic/newspack-plugin/commit/5cea128bbc250d39a942a19b464fa1dd8dce3cd1))
* **woocommerce-connection:** handle explicit UTM meta fields meta ([#3371](https://github.com/Automattic/newspack-plugin/issues/3371)) ([bf9e997](https://github.com/Automattic/newspack-plugin/commit/bf9e9978d76cd3e99a2fb2774d80e3a65f8369ca))


### Features

* add a new action to when a ras setting is updated ([#3357](https://github.com/Automattic/newspack-plugin/issues/3357)) ([35d3492](https://github.com/Automattic/newspack-plugin/commit/35d3492c849c238c09552a632ecde12a458e4841))
* **esp-sync:** sync membership data regardless of subscription ([#3353](https://github.com/Automattic/newspack-plugin/issues/3353)) ([9f7d1de](https://github.com/Automattic/newspack-plugin/commit/9f7d1def5d786e565b2cccb019e1ed0fcc90360c))
* **ga4:** detect gate interaction blocks ([#3408](https://github.com/Automattic/newspack-plugin/issues/3408)) ([e14913c](https://github.com/Automattic/newspack-plugin/commit/e14913c56222e8c842de4d5212072755c01860da))
* **ga:** disable tracking for editors regardless of RA status ([81323c3](https://github.com/Automattic/newspack-plugin/commit/81323c3fe4135dee3adba045b56679161aaeb793))
* media kit page handling ([#3358](https://github.com/Automattic/newspack-plugin/issues/3358)) ([4454850](https://github.com/Automattic/newspack-plugin/commit/4454850ccdec38b052599c09a7847714498f9388))
* **memberships:** add memberships-related body classes ([b56b9d8](https://github.com/Automattic/newspack-plugin/commit/b56b9d84aec54b4b927b1386e1528b18bc3c9bb5))
* **ras:** esp sync tools ([#3359](https://github.com/Automattic/newspack-plugin/issues/3359)) ([d7dd754](https://github.com/Automattic/newspack-plugin/commit/d7dd754b16430e005e576e4f9397457c5a5343a6))
* **ras:** helper method for ESP master list ([#3355](https://github.com/Automattic/newspack-plugin/issues/3355)) ([ec56d5b](https://github.com/Automattic/newspack-plugin/commit/ec56d5bc62b887659ba4ec6e8c76b8922983e690))
* **ras:** sync class ([#3362](https://github.com/Automattic/newspack-plugin/issues/3362)) ([88acbee](https://github.com/Automattic/newspack-plugin/commit/88acbeec177490dd0194eff8c7185e05d7096b2d))
* **ras:** unify ESP connector strategy for data events ([#3360](https://github.com/Automattic/newspack-plugin/issues/3360)) ([7080864](https://github.com/Automattic/newspack-plugin/commit/70808642116689518e24e6661917120de4bb706b))
* **reader-activation:** ESP-related tweaks ([#3381](https://github.com/Automattic/newspack-plugin/issues/3381)) ([ac68b67](https://github.com/Automattic/newspack-plugin/commit/ac68b67de4dd0f5ca26fefa6da4d8da0b6e96555))
* remove Woo Membersip sync fields ([#3411](https://github.com/Automattic/newspack-plugin/issues/3411)) ([28052e8](https://github.com/Automattic/newspack-plugin/commit/28052e8e3f396e17c80b6c331ace747cbabf3fa6))
* **sync:** add ESP sync notice to RAS wizard ([#3400](https://github.com/Automattic/newspack-plugin/issues/3400)) ([f9acd56](https://github.com/Automattic/newspack-plugin/commit/f9acd569db43e3612960beeff9e8627c9516fb28))

## [5.4.1](https://github.com/Automattic/newspack-plugin/compare/v5.4.0...v5.4.1) (2024-10-07)


### Bug Fixes

* remove Newspack Elections requirement from patterns ([049c9b6](https://github.com/Automattic/newspack-plugin/commit/049c9b6a3a23f92f9f34a1b15a3037adb29eb26f))

# [5.4.0](https://github.com/Automattic/newspack-plugin/compare/v5.3.14...v5.4.0) (2024-10-03)


### Bug Fixes

* update community placeholder and add help docs ([26dfda2](https://github.com/Automattic/newspack-plugin/commit/26dfda218ca876c5379cbb6cc4d28eba2f5b982a))


### Features

* add elections block patterns ([de84fee](https://github.com/Automattic/newspack-plugin/commit/de84fee4c11184bda2a5ccbdc7a9f563fbec16fc))

## [5.3.14](https://github.com/Automattic/newspack-plugin/compare/v5.3.13...v5.3.14) (2024-10-03)


### Bug Fixes

* cache bust the /login/google API request ([ee92ded](https://github.com/Automattic/newspack-plugin/commit/ee92dedfdd365c7c69b877cd98cad15f976b6627))

## [5.3.13](https://github.com/Automattic/newspack-plugin/compare/v5.3.12...v5.3.13) (2024-10-02)


### Bug Fixes

* **oauth:** tweak precision of success google auth log ([#3453](https://github.com/Automattic/newspack-plugin/issues/3453)) ([bed1e67](https://github.com/Automattic/newspack-plugin/commit/bed1e673be1e48444ad09680647f9f0fd80472ba))

## [5.3.12](https://github.com/Automattic/newspack-plugin/compare/v5.3.11...v5.3.12) (2024-10-01)


### Bug Fixes

* handle zero cover fee percentage ([#3452](https://github.com/Automattic/newspack-plugin/issues/3452)) ([742b378](https://github.com/Automattic/newspack-plugin/commit/742b378d0399ab9359e82ad7aa4d999d78566ebd))

## [5.3.11](https://github.com/Automattic/newspack-plugin/compare/v5.3.10...v5.3.11) (2024-10-01)


### Bug Fixes

* **oauth:** validate ID, prevent early deletion and improve logs ([#3450](https://github.com/Automattic/newspack-plugin/issues/3450)) ([763848e](https://github.com/Automattic/newspack-plugin/commit/763848e38f3eec3dbc6107cbed29277cdba4c040))

## [5.3.10](https://github.com/Automattic/newspack-plugin/compare/v5.3.9...v5.3.10) (2024-09-17)


### Bug Fixes

* **my-account:** check if `is_account_page()` exists ([#3427](https://github.com/Automattic/newspack-plugin/issues/3427)) ([d115641](https://github.com/Automattic/newspack-plugin/commit/d115641a03a3bed4197b5d6281536cfc40725da2))

## [5.3.9](https://github.com/Automattic/newspack-plugin/compare/v5.3.8...v5.3.9) (2024-09-16)


### Bug Fixes

* required billing fields in My Account ([#3389](https://github.com/Automattic/newspack-plugin/issues/3389)) ([eb58c6b](https://github.com/Automattic/newspack-plugin/commit/eb58c6b6b4cac568d27f088cdd4d95663b4aed08))

## [5.3.8](https://github.com/Automattic/newspack-plugin/compare/v5.3.7...v5.3.8) (2024-09-16)


### Bug Fixes

* **onboarding:** add expected localized variable for GAM setup ([#3420](https://github.com/Automattic/newspack-plugin/issues/3420)) ([ed29b2b](https://github.com/Automattic/newspack-plugin/commit/ed29b2ba19912dff7dc427ebc3d22180e4956113))

## [5.3.7](https://github.com/Automattic/newspack-plugin/compare/v5.3.6...v5.3.7) (2024-09-10)


### Bug Fixes

* **esp-wc-metadata:** last payment date & amount handling ([#3363](https://github.com/Automattic/newspack-plugin/issues/3363)) ([#3409](https://github.com/Automattic/newspack-plugin/issues/3409)) ([155fd65](https://github.com/Automattic/newspack-plugin/commit/155fd65e7a4fb59e8da4292a489e09bf2756e420))

## [5.3.6](https://github.com/Automattic/newspack-plugin/compare/v5.3.5...v5.3.6) (2024-09-09)


### Bug Fixes

* woo subs vs memberships expiration ([#3393](https://github.com/Automattic/newspack-plugin/issues/3393)) ([96e57e0](https://github.com/Automattic/newspack-plugin/commit/96e57e06e4c4ea03a5dec78fb01d35662995d9ea))

## [5.3.5](https://github.com/Automattic/newspack-plugin/compare/v5.3.4...v5.3.5) (2024-09-07)


### Bug Fixes

* expedite email template fix ([#3403](https://github.com/Automattic/newspack-plugin/issues/3403)) ([92c57a9](https://github.com/Automattic/newspack-plugin/commit/92c57a9b10daf64b01bee52271938ec0dabb0d1f))

## [5.3.4](https://github.com/Automattic/newspack-plugin/compare/v5.3.3...v5.3.4) (2024-09-03)


### Bug Fixes

* **woocommerce-memberships:** membership status check with post status instead of type ([#3380](https://github.com/Automattic/newspack-plugin/issues/3380)) ([39e67f7](https://github.com/Automattic/newspack-plugin/commit/39e67f798550f8a76b3dbda135faabce90656662))

## [5.3.3](https://github.com/Automattic/newspack-plugin/compare/v5.3.2...v5.3.3) (2024-08-29)


### Bug Fixes

* hide dummy guest author email address ([#3375](https://github.com/Automattic/newspack-plugin/issues/3375)) ([3d899cc](https://github.com/Automattic/newspack-plugin/commit/3d899cc19d8091e6a79f7e60a7bd1ddddf506c79))

## [5.3.2](https://github.com/Automattic/newspack-plugin/compare/v5.3.1...v5.3.2) (2024-08-28)


### Reverts

* Revert "fix: hide dummy guest author email on archives & posts (#3370)" (#3374) ([01c0c24](https://github.com/Automattic/newspack-plugin/commit/01c0c2426ea8023d38f2186bd48029933fe1ec82)), closes [#3370](https://github.com/Automattic/newspack-plugin/issues/3370) [#3374](https://github.com/Automattic/newspack-plugin/issues/3374)

## [5.3.1](https://github.com/Automattic/newspack-plugin/compare/v5.3.0...v5.3.1) (2024-08-28)


### Bug Fixes

* hide dummy guest author email on archives & posts ([#3370](https://github.com/Automattic/newspack-plugin/issues/3370)) ([3dcd1ec](https://github.com/Automattic/newspack-plugin/commit/3dcd1ecaca5c9cb5ad3b595ed883132b57456cd7))

# [5.3.0](https://github.com/Automattic/newspack-plugin/compare/v5.2.1...v5.3.0) (2024-08-26)


### Bug Fixes

* **data-events:** gate interaction for registration form ([#3327](https://github.com/Automattic/newspack-plugin/issues/3327)) ([eb06194](https://github.com/Automattic/newspack-plugin/commit/eb0619470beb2a3765a4d7fac67169c85f260bf2))
* **ras:** destroy sessions on account verification ([#3328](https://github.com/Automattic/newspack-plugin/issues/3328)) ([ab6efeb](https://github.com/Automattic/newspack-plugin/commit/ab6efebe21493b2f4a2bb28dbc4d996ec3580eff))


### Features

* add phpcs sniff for newsletter methods ([#3337](https://github.com/Automattic/newspack-plugin/issues/3337)) ([15f237c](https://github.com/Automattic/newspack-plugin/commit/15f237cd6b34c496e1ebd838e2e0609f0e167539))
* add woo team sync metadata (WIP) ([#3325](https://github.com/Automattic/newspack-plugin/issues/3325)) ([e5cc5e3](https://github.com/Automattic/newspack-plugin/commit/e5cc5e37e3b7955794f9e92e058cc3fced575fd1))

## [5.2.1](https://github.com/Automattic/newspack-plugin/compare/v5.2.0...v5.2.1) (2024-08-21)


### Bug Fixes

* **membership-status-check:** add property check ([3fae988](https://github.com/Automattic/newspack-plugin/commit/3fae988af64602c8fe1daa1f37ecffbd4819857e))

# [5.2.0](https://github.com/Automattic/newspack-plugin/compare/v5.1.0...v5.2.0) (2024-08-16)


### Bug Fixes

* improve compatibility with Members plugin ([a30b337](https://github.com/Automattic/newspack-plugin/commit/a30b33725b79fdab165229d80e7e80dacc3697ca))


### Features

* hide Guest contributor checkbox if Members is active ([b8c8dba](https://github.com/Automattic/newspack-plugin/commit/b8c8dba58d6b41a5aa6f0403567020522665c543))

# [5.1.0](https://github.com/Automattic/newspack-plugin/compare/v5.0.1...v5.1.0) (2024-08-15)


### Features

* disable GA4 front end custom params by default ([c11978d](https://github.com/Automattic/newspack-plugin/commit/c11978d9754c048da0913be6dbe0e9b99f097634))

## [5.0.1](https://github.com/Automattic/newspack-plugin/compare/v5.0.0...v5.0.1) (2024-08-14)


### Bug Fixes

* **woocommerce-memberships:** membership status check ([#3338](https://github.com/Automattic/newspack-plugin/issues/3338)) ([d424f53](https://github.com/Automattic/newspack-plugin/commit/d424f53a98cf92092aab75e6007bb9e9d0ac1ff7))

# [5.0.0](https://github.com/Automattic/newspack-plugin/compare/v4.7.0...v5.0.0) (2024-08-13)


### Bug Fixes

* **data-events:** ensure get_last_successful_order returns WC_Order ([222f58c](https://github.com/Automattic/newspack-plugin/commit/222f58c3e28bdf9c6acc33d1c9afa0d88df94025))
* **data-events:** ensure get_last_successful_order returns WC_Order ([#3299](https://github.com/Automattic/newspack-plugin/issues/3299)) ([da21561](https://github.com/Automattic/newspack-plugin/commit/da21561290e722910707653d2f32f3949d3d0cb8))
* **data-events:** race condition between `order_completed` and `subscription_updated` ([#3314](https://github.com/Automattic/newspack-plugin/issues/3314)) ([afbf2e8](https://github.com/Automattic/newspack-plugin/commit/afbf2e890a69a7bbbdc91eeacd0902a45dd7db4d))
* force active membership if the user has active subs ([#3268](https://github.com/Automattic/newspack-plugin/issues/3268)) ([aebe581](https://github.com/Automattic/newspack-plugin/commit/aebe581d451dc27429b40a222a9c712227fa44d9))
* namespacing issue ([7b1506a](https://github.com/Automattic/newspack-plugin/commit/7b1506a9855349698b211fb296d57c59bc3becdd))
* update dependencies to support `@wordpress/scripts` ([#3181](https://github.com/Automattic/newspack-plugin/issues/3181)) ([0d35ac8](https://github.com/Automattic/newspack-plugin/commit/0d35ac862242d35e29127988cce003f4a64bd2c5))
* **woocommerce:** prevent /shop redirect from author archives ([46db669](https://github.com/Automattic/newspack-plugin/commit/46db669d9faa19c6350768ac1cca692ecfe05b80))


### Features

* enhancements for the Non editing contributor role ([#3277](https://github.com/Automattic/newspack-plugin/issues/3277)) ([e47e422](https://github.com/Automattic/newspack-plugin/commit/e47e42274cf446ec5882c01edc2b4a081be846b7))
* **ga4:** filter custom params ([eda5553](https://github.com/Automattic/newspack-plugin/commit/eda5553bf5fcf84b060aa433894a16e50827dee1))
* rename guest contributor role ([#3302](https://github.com/Automattic/newspack-plugin/issues/3302)) ([76ac05e](https://github.com/Automattic/newspack-plugin/commit/76ac05e740f007c5894d538226beea06ee097a0c))
* rename guest contributor role ([#3302](https://github.com/Automattic/newspack-plugin/issues/3302)) ([c7f6917](https://github.com/Automattic/newspack-plugin/commit/c7f691753f62605178caccd4bf486d6490d5b718))
* show deprecation warning for CM in Engagement wizard ([#3264](https://github.com/Automattic/newspack-plugin/issues/3264)) ([0813369](https://github.com/Automattic/newspack-plugin/commit/0813369e0a904ebf4b4f0a17d7bbc8c59f29d749))


### BREAKING CHANGES

* Updates dependencies for compatibility with WordPress 6.6.*, but breaks JS in WordPress 6.5.* and below. If you need support for WP 6.5.*, please do not upgrade to this new major version.

* chore: refactor for updating dependencies in newspack-scripts

* chore: JS formatting fixes for eslint errors

* chore: update NPM scripts and other dependencies

* chore: fix jest unit tests

* chore: update dependencies in newspack-components

* fix: peer dependency conflicts with @wordp
ress/* packages

* chore: update newspack-components dependencies

* chore: proxy stylelint from newspack-scripts

* chore: remove unnecessary stylelint

* refactor: use proxy script for eslint and stylelint scripts

* chore: remove console.log

* chore: update newspack-scripts to NPM alpha version

* chore: update newspack-scripts to git branch

* chore: update newspack-scripts to alpha.3

* test: remove unneeded mocks

* deps: newspack-scripts@5.6.0-alpha.1

* chore: fix scripts

* fix: update newspack-scripts to v5.6.0-alpha.2

* fix: add newspack-revisions.js to webpack build for linting

* fix: update stylelint and format CSS for errors

* chore: clear TS check errors

* fix: start command

* fix: remove i18n script (Blocks only)

* temporarily revert stylelint autoformat to avoid merge conflicts

* chore: add .stylelintrc.js

* fix: reformat SCSS

* chore: update newspack-scripts to v5.6.0-alpha.4

* fix: add missing Prettier config files

* chore: update newspack-scripts to 5.6.0-alpha.5

* chore: update newspack-scripts to v5.6.0-alpha.7

* style: stylelint autofixes

* fix: add fix:js script; temporarily remove format:js

* fix: update newspack-scripts to v5.5.0-alpha.8 and restore format:js script

* chore: add NPM scripts for PHP

* refactor: move recaptcha JS to src directory

* fix: ignore dist and node_modules inside src/components, too

* fix: update Babel configs for components

* fix(components): output TS and JS, and publish new major version to NPM

* fix: do not delete node_modules after post-publish

* chore: bump newspack-scripts to v5.5.2

# [4.7.0](https://github.com/Automattic/newspack-plugin/compare/v4.6.1...v4.7.0) (2024-07-30)


### Bug Fixes

* **reader-activation:** handle user login clash ([10dfcd1](https://github.com/Automattic/newspack-plugin/commit/10dfcd166eb533c0fc89f73487f6dd4f45237275))


### Features

* **cli-co-authors-plus:** ensure correct author slug is set ([#3248](https://github.com/Automattic/newspack-plugin/issues/3248)) ([14ad71b](https://github.com/Automattic/newspack-plugin/commit/14ad71bd98a24466da188a56f8e5f7e6d6e80930))

## [4.6.1](https://github.com/Automattic/newspack-plugin/compare/v4.6.0...v4.6.1) (2024-07-29)


### Bug Fixes

* use percentage rather than value for cover fee text ([#3280](https://github.com/Automattic/newspack-plugin/issues/3280)) ([e76d8af](https://github.com/Automattic/newspack-plugin/commit/e76d8af7d0a239e46bceb8de44accbf9a7063b1e))

# [4.6.0](https://github.com/Automattic/newspack-plugin/compare/v4.5.0...v4.6.0) (2024-07-15)


### Bug Fixes

* display transaction fee as an amount instead of a percentage ([#3206](https://github.com/Automattic/newspack-plugin/issues/3206)) ([b977c01](https://github.com/Automattic/newspack-plugin/commit/b977c018b6ae8004aa96e78dd73141a4e05fe5c8))
* **js:** skip regenerator-runtime fix for some files ([#3205](https://github.com/Automattic/newspack-plugin/issues/3205)) ([0a7d5c5](https://github.com/Automattic/newspack-plugin/commit/0a7d5c5e18fbd420dcd04497804af19bfbcc1c63))


### Features

* add support for reCAPTCHA v2 ([#3126](https://github.com/Automattic/newspack-plugin/issues/3126)) ([1a1bd30](https://github.com/Automattic/newspack-plugin/commit/1a1bd30682366c2546789bba94c0b8a8de1cb608))
* **cli:** cap-migration tweaks ([#3198](https://github.com/Automattic/newspack-plugin/issues/3198)) ([9b372e9](https://github.com/Automattic/newspack-plugin/commit/9b372e9e866c9584c62e9aec04c250ce3396486d))
* **cli:** setup - set permalinks to pretty unless already set ([f0c44d9](https://github.com/Automattic/newspack-plugin/commit/f0c44d90b91f7cb64f4067859a322365bb86c59a))
* **ras:** don't require WC Subscriptions for displaying auth link ([b6d6589](https://github.com/Automattic/newspack-plugin/commit/b6d65890d76586d3fc57d329319e9db6e810397d))
* update membership patterns ([#3193](https://github.com/Automattic/newspack-plugin/issues/3193)) ([c595329](https://github.com/Automattic/newspack-plugin/commit/c5953294b16df0ed813796b52bfceb238c3442f8))
* **wc-memberships:** update two and three tiers patterns with checkout-button border radius ([#3208](https://github.com/Automattic/newspack-plugin/issues/3208)) ([c57b58b](https://github.com/Automattic/newspack-plugin/commit/c57b58ba6601b449b4666ae96dc3303725f6b701))

# [4.5.0](https://github.com/Automattic/newspack-plugin/compare/v4.4.2...v4.5.0) (2024-07-11)


### Features

* remove 'Patterns' link from Appearance menu ([#3236](https://github.com/Automattic/newspack-plugin/issues/3236)) ([b1150f5](https://github.com/Automattic/newspack-plugin/commit/b1150f54eee24b07ecc9d083b64b787cefa8e414))

## [4.4.2](https://github.com/Automattic/newspack-plugin/compare/v4.4.1...v4.4.2) (2024-07-10)


### Bug Fixes

* add credits meta attributes check and dependencies to image block effect hook ([#3230](https://github.com/Automattic/newspack-plugin/issues/3230)) ([b257d2b](https://github.com/Automattic/newspack-plugin/commit/b257d2bfcd6127908c015963c467c596a7cbc89b))

## [4.4.1](https://github.com/Automattic/newspack-plugin/compare/v4.4.0...v4.4.1) (2024-07-08)


### Bug Fixes

* update text domain ([#3222](https://github.com/Automattic/newspack-plugin/issues/3222)) ([ac9da56](https://github.com/Automattic/newspack-plugin/commit/ac9da56d80d1362cc8cc6c5ab0264ce55c2e2ed4))

# [4.4.0](https://github.com/Automattic/newspack-plugin/compare/v4.3.4...v4.4.0) (2024-07-01)

### Bug Fixes

* add modified check before updating donation product ([#3183](https://github.com/Automattic/newspack-plugin/issues/3183)) ([208c55e](https://github.com/Automattic/newspack-plugin/commit/208c55e21ac8f6a4b6736f89c25cf12994f2cbaf))
* allow `exact` prop on Wizard Route ([9da6da9](https://github.com/Automattic/newspack-plugin/commit/9da6da9b755321ec44c01f1f48ab579a1035ab56))
* ci / eslint ([1d6adcd](https://github.com/Automattic/newspack-plugin/commit/1d6adcd1d3ef3a47f1fe3e4e4651e838b77cff62))
* ci / eslint ([b95580a](https://github.com/Automattic/newspack-plugin/commit/b95580a73ab559bd1b3c7d599c4479eb26c8c93d))
* ci / eslint ([beadc45](https://github.com/Automattic/newspack-plugin/commit/beadc456c8b427124b74ac0dca5fccb324d7bd17))
* ci / typescript ([2c1e092](https://github.com/Automattic/newspack-plugin/commit/2c1e0926784d7682dfcfc57b3b8235833e684361))
* circle ci / eslint ([ab74e90](https://github.com/Automattic/newspack-plugin/commit/ab74e90e6c4a7151b2ae2ffc2f94e9a8f20212fd))
* circle ci / eslint ([4d383da](https://github.com/Automattic/newspack-plugin/commit/4d383dab162e74ef185d1a8951066ab221c4e902))
* circle ci / eslint ([6389972](https://github.com/Automattic/newspack-plugin/commit/638997213fa962474cb2eeb1fc80e9d5457741c0))
* **cli/migrate-co-authors-guest-authors:** handle existing email address ([#3172](https://github.com/Automattic/newspack-plugin/issues/3172)) ([1946bd0](https://github.com/Automattic/newspack-plugin/commit/1946bd0b31d86f56503cdd7a2cc303b55d45d9ec))
* **js:** skip regenerator-runtime fix for some files ([#3205](https://github.com/Automattic/newspack-plugin/issues/3205)) ([1ecfd11](https://github.com/Automattic/newspack-plugin/commit/1ecfd11dec7a14554dd4e661cb1056a5ac2e484d))
* load optional modules after settings ([#3165](https://github.com/Automattic/newspack-plugin/issues/3165)) ([69cc5ac](https://github.com/Automattic/newspack-plugin/commit/69cc5ac4c7a88201143f23b9d1bdffb219228b6e))
* merge conflicts and various minor refactoring ([ca4fea8](https://github.com/Automattic/newspack-plugin/commit/ca4fea81fb72326b1d18c2008b99519256d2a4d0))
* **pwa:** disable post request interception ([1587232](https://github.com/Automattic/newspack-plugin/commit/158723235ee4e3d23e1f21d4ec09aeb8b07c13e3))
* **reader-revenue-wizard:** separate billing fields section ([#3140](https://github.com/Automattic/newspack-plugin/issues/3140)) ([41fb347](https://github.com/Automattic/newspack-plugin/commit/41fb347d4c2e81030bf8409ca85d8e74a559d279))
* remove icons import ([136a19a](https://github.com/Automattic/newspack-plugin/commit/136a19a155b4817e4617fc3e1031e25d2487c40f))
* small tweaks to migrate-co-authors-guest-authors command ([2baa524](https://github.com/Automattic/newspack-plugin/commit/2baa524cca1ad4d3387faf69a6dde274f66e89d4))
* text overflow ellipse ([d35df0b](https://github.com/Automattic/newspack-plugin/commit/d35df0bfa77e25fd533ad23b2b0e13f11b4eecdd))
* udpate package-lock.json ([0054142](https://github.com/Automattic/newspack-plugin/commit/00541424fe562bd208c561a471817eebe878f6e4))
* update newspack-scripts to v5.5.1 ([be81e62](https://github.com/Automattic/newspack-plugin/commit/be81e62308ff8a20715ebadd68c78312d7bf992c))
* update newspack-scripts to v5.5.1 ([896caab](https://github.com/Automattic/newspack-plugin/commit/896caab814fb3b1dcc54d278874e29c1667cbdc4))


### Features

* add BoxContrast to components-demo ([23a8e5f](https://github.com/Automattic/newspack-plugin/commit/23a8e5f6ea55e3511accdf0e5b1cd4839e2d54ed))
* add spinner to registration block submit button ([#3180](https://github.com/Automattic/newspack-plugin/issues/3180)) ([ff1278f](https://github.com/Automattic/newspack-plugin/commit/ff1278f4e5c5d56c3e90441e328f1f26e0fb417b))
* added `<hr/>` between site status and quick action components ([982f67b](https://github.com/Automattic/newspack-plugin/commit/982f67b449654076f22e05e7dbb446aad0b628ec))
* added correct icon type to window.newspack_dashboard ([b5a5130](https://github.com/Automattic/newspack-plugin/commit/b5a5130cac594002d07f77448f25637592ee747c))
* added dash card section components ([f9f3992](https://github.com/Automattic/newspack-plugin/commit/f9f3992967763467a35ab668f63971b86e215585))
* added quick actions component ([d37e3f3](https://github.com/Automattic/newspack-plugin/commit/d37e3f33f1c4b3ddfc0d39b8a0be9efe5a768056))
* adding brand header and box-contrast component ([afb2e08](https://github.com/Automattic/newspack-plugin/commit/afb2e0864ad1e12c1f458c9b1a5faffbd8bf6687))
* adding site status component ([ee01d09](https://github.com/Automattic/newspack-plugin/commit/ee01d0987347b2ad6be8c5b2a3509ea02848d69a))
* applied PR feedback, error configuration ([7ef613e](https://github.com/Automattic/newspack-plugin/commit/7ef613ea283a415967b9b65a2fe6839167ee7287))
* boilerplate stylesheet for settings ([5f3f640](https://github.com/Automattic/newspack-plugin/commit/5f3f6404148daefbef2fa2503207d90c5b53a4a6))
* centralized wizards script initial ([6faf3d2](https://github.com/Automattic/newspack-plugin/commit/6faf3d26211105f7e59cd87eb131b00c4be35965))
* dashboard & section initial ([eb3d1e6](https://github.com/Automattic/newspack-plugin/commit/eb3d1e6943e39431e012ca40bc8edad6fa785fbc))
* dynamic component root loader ([389646a](https://github.com/Automattic/newspack-plugin/commit/389646aef71652fb52e0c29abb29b697cd4aa355))
* ensure regenerator-runtime is available (for WP 6.6) ([#3196](https://github.com/Automattic/newspack-plugin/issues/3196)) ([9693e37](https://github.com/Automattic/newspack-plugin/commit/9693e374f1fdc26f458ad7668961f39c11f634cd))
* **everlit:** added Everlit to plugins + refactor plugins in connections wizard ([#3188](https://github.com/Automattic/newspack-plugin/issues/3188)) ([88124f8](https://github.com/Automattic/newspack-plugin/commit/88124f8f98fa582c5f7f9ef25de1c747802ab7d4))
* icons ([5f3c740](https://github.com/Automattic/newspack-plugin/commit/5f3c740e955f25f15403be64f8fc949003176069))
* initial app boilerplate ([9a3740c](https://github.com/Automattic/newspack-plugin/commit/9a3740cd215763ead1a187f972d63b5b7fedf807))
* initial settings and refactor ([d7d7da8](https://github.com/Automattic/newspack-plugin/commit/d7d7da8eacefc405b8aff1b0adb76b1670758ca0))
* **pwa:** pr feedback. moved anonymous function to static method ([1143a11](https://github.com/Automattic/newspack-plugin/commit/1143a11761546df37c10c04ed097456980a04ae4))
* RSS & WC Memberships ([#3146](https://github.com/Automattic/newspack-plugin/issues/3146)) ([e649199](https://github.com/Automattic/newspack-plugin/commit/e6491995ab1ce6fa826146e6391c964164f4ee65))
* settings php initial ([e039c4b](https://github.com/Automattic/newspack-plugin/commit/e039c4b48e29f40bfd7f9e06ab5328fd7951c870))
* **wizards:** new dashboard config and localize script ([2938b32](https://github.com/Automattic/newspack-plugin/commit/2938b329b518ae9b4c396fef0330946d5ef48ee2))


### Reverts

* **ia:** back to `trunk` ([69b2ba0](https://github.com/Automattic/newspack-plugin/commit/69b2ba09a222e7c1b84b9cba0b97c36881cda63f))

# [4.4.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v4.3.3...v4.4.0-alpha.1) (2024-06-24)


### Bug Fixes

* add modified check before updating donation product ([#3183](https://github.com/Automattic/newspack-plugin/issues/3183)) ([208c55e](https://github.com/Automattic/newspack-plugin/commit/208c55e21ac8f6a4b6736f89c25cf12994f2cbaf))
* allow `exact` prop on Wizard Route ([9da6da9](https://github.com/Automattic/newspack-plugin/commit/9da6da9b755321ec44c01f1f48ab579a1035ab56))
* ci / eslint ([1d6adcd](https://github.com/Automattic/newspack-plugin/commit/1d6adcd1d3ef3a47f1fe3e4e4651e838b77cff62))
* ci / eslint ([b95580a](https://github.com/Automattic/newspack-plugin/commit/b95580a73ab559bd1b3c7d599c4479eb26c8c93d))
* ci / eslint ([beadc45](https://github.com/Automattic/newspack-plugin/commit/beadc456c8b427124b74ac0dca5fccb324d7bd17))
* ci / typescript ([2c1e092](https://github.com/Automattic/newspack-plugin/commit/2c1e0926784d7682dfcfc57b3b8235833e684361))
* circle ci / eslint ([ab74e90](https://github.com/Automattic/newspack-plugin/commit/ab74e90e6c4a7151b2ae2ffc2f94e9a8f20212fd))
* circle ci / eslint ([4d383da](https://github.com/Automattic/newspack-plugin/commit/4d383dab162e74ef185d1a8951066ab221c4e902))
* circle ci / eslint ([6389972](https://github.com/Automattic/newspack-plugin/commit/638997213fa962474cb2eeb1fc80e9d5457741c0))
* load optional modules after settings ([#3165](https://github.com/Automattic/newspack-plugin/issues/3165)) ([69cc5ac](https://github.com/Automattic/newspack-plugin/commit/69cc5ac4c7a88201143f23b9d1bdffb219228b6e))
* merge conflicts and various minor refactoring ([ca4fea8](https://github.com/Automattic/newspack-plugin/commit/ca4fea81fb72326b1d18c2008b99519256d2a4d0))
* **pwa:** disable post request interception ([1587232](https://github.com/Automattic/newspack-plugin/commit/158723235ee4e3d23e1f21d4ec09aeb8b07c13e3))
* **reader-revenue-wizard:** separate billing fields section ([#3140](https://github.com/Automattic/newspack-plugin/issues/3140)) ([41fb347](https://github.com/Automattic/newspack-plugin/commit/41fb347d4c2e81030bf8409ca85d8e74a559d279))
* remove icons import ([136a19a](https://github.com/Automattic/newspack-plugin/commit/136a19a155b4817e4617fc3e1031e25d2487c40f))
* small tweaks to migrate-co-authors-guest-authors command ([2baa524](https://github.com/Automattic/newspack-plugin/commit/2baa524cca1ad4d3387faf69a6dde274f66e89d4))
* text overflow ellipse ([d35df0b](https://github.com/Automattic/newspack-plugin/commit/d35df0bfa77e25fd533ad23b2b0e13f11b4eecdd))


### Features

* add BoxContrast to components-demo ([23a8e5f](https://github.com/Automattic/newspack-plugin/commit/23a8e5f6ea55e3511accdf0e5b1cd4839e2d54ed))
* add spinner to registration block submit button ([#3180](https://github.com/Automattic/newspack-plugin/issues/3180)) ([ff1278f](https://github.com/Automattic/newspack-plugin/commit/ff1278f4e5c5d56c3e90441e328f1f26e0fb417b))
* added `<hr/>` between site status and quick action components ([982f67b](https://github.com/Automattic/newspack-plugin/commit/982f67b449654076f22e05e7dbb446aad0b628ec))
* added correct icon type to window.newspack_dashboard ([b5a5130](https://github.com/Automattic/newspack-plugin/commit/b5a5130cac594002d07f77448f25637592ee747c))
* added dash card section components ([f9f3992](https://github.com/Automattic/newspack-plugin/commit/f9f3992967763467a35ab668f63971b86e215585))
* added quick actions component ([d37e3f3](https://github.com/Automattic/newspack-plugin/commit/d37e3f33f1c4b3ddfc0d39b8a0be9efe5a768056))
* adding brand header and box-contrast component ([afb2e08](https://github.com/Automattic/newspack-plugin/commit/afb2e0864ad1e12c1f458c9b1a5faffbd8bf6687))
* adding site status component ([ee01d09](https://github.com/Automattic/newspack-plugin/commit/ee01d0987347b2ad6be8c5b2a3509ea02848d69a))
* applied PR feedback, error configuration ([7ef613e](https://github.com/Automattic/newspack-plugin/commit/7ef613ea283a415967b9b65a2fe6839167ee7287))
* boilerplate stylesheet for settings ([5f3f640](https://github.com/Automattic/newspack-plugin/commit/5f3f6404148daefbef2fa2503207d90c5b53a4a6))
* centralized wizards script initial ([6faf3d2](https://github.com/Automattic/newspack-plugin/commit/6faf3d26211105f7e59cd87eb131b00c4be35965))
* dashboard & section initial ([eb3d1e6](https://github.com/Automattic/newspack-plugin/commit/eb3d1e6943e39431e012ca40bc8edad6fa785fbc))
* dynamic component root loader ([389646a](https://github.com/Automattic/newspack-plugin/commit/389646aef71652fb52e0c29abb29b697cd4aa355))
* icons ([5f3c740](https://github.com/Automattic/newspack-plugin/commit/5f3c740e955f25f15403be64f8fc949003176069))
* initial app boilerplate ([9a3740c](https://github.com/Automattic/newspack-plugin/commit/9a3740cd215763ead1a187f972d63b5b7fedf807))
* initial settings and refactor ([d7d7da8](https://github.com/Automattic/newspack-plugin/commit/d7d7da8eacefc405b8aff1b0adb76b1670758ca0))
* **pwa:** pr feedback. moved anonymous function to static method ([1143a11](https://github.com/Automattic/newspack-plugin/commit/1143a11761546df37c10c04ed097456980a04ae4))
* RSS & WC Memberships ([#3146](https://github.com/Automattic/newspack-plugin/issues/3146)) ([e649199](https://github.com/Automattic/newspack-plugin/commit/e6491995ab1ce6fa826146e6391c964164f4ee65))
* settings php initial ([e039c4b](https://github.com/Automattic/newspack-plugin/commit/e039c4b48e29f40bfd7f9e06ab5328fd7951c870))
* **wizards:** new dashboard config and localize script ([2938b32](https://github.com/Automattic/newspack-plugin/commit/2938b329b518ae9b4c396fef0330946d5ef48ee2))


### Reverts

* **ia:** back to `trunk` ([69b2ba0](https://github.com/Automattic/newspack-plugin/commit/69b2ba09a222e7c1b84b9cba0b97c36881cda63f))

## [4.3.4](https://github.com/Automattic/newspack-plugin/compare/v4.3.3...v4.3.4) (2024-06-27)

### Bug Fixes

* variable name > constant ([#3203](https://github.com/Automattic/newspack-plugin/issues/3203)) ([46c5651](https://github.com/Automattic/newspack-plugin/commit/46c5651cf48e88abef3b8f3855b8fd3f5860c2a3))


## [4.3.3](https://github.com/Automattic/newspack-plugin/compare/v4.3.2...v4.3.3) (2024-06-24)


### Bug Fixes

* more namespacing ([#3195](https://github.com/Automattic/newspack-plugin/issues/3195)) ([d792503](https://github.com/Automattic/newspack-plugin/commit/d792503a827fe1952f6d7923dc1686ed988b3e11))

## [4.3.2](https://github.com/Automattic/newspack-plugin/compare/v4.3.1...v4.3.2) (2024-06-24)


### Bug Fixes

* slash get_option ([#3194](https://github.com/Automattic/newspack-plugin/issues/3194)) ([2a3955e](https://github.com/Automattic/newspack-plugin/commit/2a3955ee98f7c46099a5d029822df4ad2098499a))

## [4.3.1](https://github.com/Automattic/newspack-plugin/compare/v4.3.0...v4.3.1) (2024-06-24)


### Bug Fixes

* dont sync to ESP on staging sites ([#3192](https://github.com/Automattic/newspack-plugin/issues/3192)) ([07ae90f](https://github.com/Automattic/newspack-plugin/commit/07ae90f99249f67e6bdff90047a7977486355a0f))

# [4.3.0](https://github.com/Automattic/newspack-plugin/compare/v4.2.0...v4.3.0) (2024-06-12)


### Bug Fixes

* **newsletters:** missing UTM params passing ([#3145](https://github.com/Automattic/newspack-plugin/issues/3145)) ([0688fa0](https://github.com/Automattic/newspack-plugin/commit/0688fa002700d479c69d56ee3325a74ba63cc39d))
* **ras-setup:** redirect to init screen after setup ([#3142](https://github.com/Automattic/newspack-plugin/issues/3142)) ([b86580b](https://github.com/Automattic/newspack-plugin/commit/b86580b6c0926cbddb5c621409d4cc448e1f5e1e))
* **ras:** handle RAS disabled in newsletters signup handling ([3d70a1d](https://github.com/Automattic/newspack-plugin/commit/3d70a1d3700f10b1e9aa9e37035bd6e8c5ebb6e3))
* **ras:** prevent email address exposure via user login ([#3139](https://github.com/Automattic/newspack-plugin/issues/3139)) ([9b534d3](https://github.com/Automattic/newspack-plugin/commit/9b534d3c51ac93f269e1baa3577e426b5b4be7a5))
* **reader-revenue:** prevent sending duplicate receipt emails ([aa91890](https://github.com/Automattic/newspack-plugin/commit/aa9189023bd03f0a117c5bd0c4d2db60d28c66d2))
* **tracking:** handling user role in pixel ([#3137](https://github.com/Automattic/newspack-plugin/issues/3137)) ([a041764](https://github.com/Automattic/newspack-plugin/commit/a0417642806ae7be499e9b1d2d571831d07d6331))


### Features

* **cli:** enable running the setup with a site import ([#3122](https://github.com/Automattic/newspack-plugin/issues/3122)) ([c6cc10e](https://github.com/Automattic/newspack-plugin/commit/c6cc10efd5e2ccfa2f0eba11fa11ac083d2213d4))
* expand memberships perf to archives ([#3148](https://github.com/Automattic/newspack-plugin/issues/3148)) ([bdbdf1d](https://github.com/Automattic/newspack-plugin/commit/bdbdf1db6b816f424053c9b6fdb86fd05b250150))
* **memberships:** remove content restriction handling on the homepage ([b63a3fa](https://github.com/Automattic/newspack-plugin/commit/b63a3fa8ad9317aa5bf92416a398e58808502f40))
* update donation landing page ([#3109](https://github.com/Automattic/newspack-plugin/issues/3109)) ([96218c1](https://github.com/Automattic/newspack-plugin/commit/96218c18f206c330e36c0472253b6c79b70c9791))

# [4.2.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v4.1.0...v4.2.0-alpha.1) (2024-05-31)


### Bug Fixes

* **newsletters:** missing UTM params passing ([#3145](https://github.com/Automattic/newspack-plugin/issues/3145)) ([0688fa0](https://github.com/Automattic/newspack-plugin/commit/0688fa002700d479c69d56ee3325a74ba63cc39d))
* **ras-setup:** redirect to init screen after setup ([#3142](https://github.com/Automattic/newspack-plugin/issues/3142)) ([b86580b](https://github.com/Automattic/newspack-plugin/commit/b86580b6c0926cbddb5c621409d4cc448e1f5e1e))
* **ras:** handle RAS disabled in newsletters signup handling ([3d70a1d](https://github.com/Automattic/newspack-plugin/commit/3d70a1d3700f10b1e9aa9e37035bd6e8c5ebb6e3))
* **ras:** prevent email address exposure via user login ([#3139](https://github.com/Automattic/newspack-plugin/issues/3139)) ([9b534d3](https://github.com/Automattic/newspack-plugin/commit/9b534d3c51ac93f269e1baa3577e426b5b4be7a5))
* **reader-revenue:** prevent sending duplicate receipt emails ([aa91890](https://github.com/Automattic/newspack-plugin/commit/aa9189023bd03f0a117c5bd0c4d2db60d28c66d2))
* **tracking:** handling user role in pixel ([#3137](https://github.com/Automattic/newspack-plugin/issues/3137)) ([a041764](https://github.com/Automattic/newspack-plugin/commit/a0417642806ae7be499e9b1d2d571831d07d6331))


### Features

* **cli:** enable running the setup with a site import ([#3122](https://github.com/Automattic/newspack-plugin/issues/3122)) ([c6cc10e](https://github.com/Automattic/newspack-plugin/commit/c6cc10efd5e2ccfa2f0eba11fa11ac083d2213d4))
* expand memberships perf to archives ([#3148](https://github.com/Automattic/newspack-plugin/issues/3148)) ([bdbdf1d](https://github.com/Automattic/newspack-plugin/commit/bdbdf1db6b816f424053c9b6fdb86fd05b250150))
* **memberships:** remove content restriction handling on the homepage ([b63a3fa](https://github.com/Automattic/newspack-plugin/commit/b63a3fa8ad9317aa5bf92416a398e58808502f40))
* update donation landing page ([#3109](https://github.com/Automattic/newspack-plugin/issues/3109)) ([96218c1](https://github.com/Automattic/newspack-plugin/commit/96218c18f206c330e36c0472253b6c79b70c9791))

# [4.2.0](https://github.com/Automattic/newspack-plugin/compare/v4.1.0...v4.2.0) (2024-06-11)


### Bug Fixes

* **ga:** add custom parameters to FE-originating GA requests ([#3178](https://github.com/Automattic/newspack-plugin/issues/3178)) ([860791f](https://github.com/Automattic/newspack-plugin/commit/860791fe9e0f77aa34e85a9a155316868ad77844))


# [4.1.0](https://github.com/Automattic/newspack-plugin/compare/v4.0.1...v4.1.0) (2024-05-30)


### Features

* **ga4-events:** add author name(s), category, RAS statuses to event payload ([#3129](https://github.com/Automattic/newspack-plugin/issues/3129)) ([d1af9bb](https://github.com/Automattic/newspack-plugin/commit/d1af9bbc3d57f4460f1e9d4fb4dee74f5ffb77a4))

## [4.0.1](https://github.com/Automattic/newspack-plugin/compare/v4.0.0...v4.0.1) (2024-05-29)


### Bug Fixes

* **ras-sync:** allow active subscription to take precendence over orders ([#3141](https://github.com/Automattic/newspack-plugin/issues/3141)) ([eef6dfb](https://github.com/Automattic/newspack-plugin/commit/eef6dfb18448c036f45ac4246664a752d8e4388e))

# [4.0.0](https://github.com/Automattic/newspack-plugin/compare/v3.8.8...v4.0.0) (2024-05-28)


### Bug Fixes

* autocomplete orders only for virtual products ([#3111](https://github.com/Automattic/newspack-plugin/issues/3111)) ([bfbe554](https://github.com/Automattic/newspack-plugin/commit/bfbe5541c7dcee270a6c2116344ffaa18742f612))
* **data-events:** handle no ga_client_id in data ([fc4fc9f](https://github.com/Automattic/newspack-plugin/commit/fc4fc9f5241cc8a9a316ae4d7e62f8f63f528b72))
* ensure only admins can reset starter content and newspack options ([#3081](https://github.com/Automattic/newspack-plugin/issues/3081)) ([4606721](https://github.com/Automattic/newspack-plugin/commit/46067217985f904286ddb2c952ba0e1deb954e56))
* **registration-block:** prevent undefined variable warning ([8af9d89](https://github.com/Automattic/newspack-plugin/commit/8af9d898a3f81059599b00fcd18e5e5ec01423f2))
* **starter-content:** make the starter content generation idempotent ([d5e10ff](https://github.com/Automattic/newspack-plugin/commit/d5e10ff1c3935f921677f7f3bacb8d35c9709cb0))


### Features

* add product option to autocomplete orders ([#3072](https://github.com/Automattic/newspack-plugin/issues/3072)) ([4a2859b](https://github.com/Automattic/newspack-plugin/commit/4a2859b894eab499e194102b0636dc20466e0c22))
* **cli:** command to migrate CAP guest authors to WP users ([#3068](https://github.com/Automattic/newspack-plugin/issues/3068)) ([7ea8273](https://github.com/Automattic/newspack-plugin/commit/7ea8273fceaccf4109c0de5dd4620eaf4d907520))
* custom role for assignable authors who don't edit posts ([#3066](https://github.com/Automattic/newspack-plugin/issues/3066)) ([7b89053](https://github.com/Automattic/newspack-plugin/commit/7b89053aa8a828abbfd1220e194758c766e27ca1))
* **ras:** skip campaign setup ([#3051](https://github.com/Automattic/newspack-plugin/issues/3051)) ([9ef0e6d](https://github.com/Automattic/newspack-plugin/commit/9ef0e6d1de8d4dafda4d2af1304206d0f8190b15))
* support for wrapping rss titles in cdata ([#3104](https://github.com/Automattic/newspack-plugin/issues/3104)) ([8b3a3be](https://github.com/Automattic/newspack-plugin/commit/8b3a3be48cf4ab0150f6e141be41460c0acfbb48))


### BREAKING CHANGES

* Changes order autocompletion behavior for existing products!

* feat: add product option to autocomplete orders

* chore: update outdated docblock description

# [4.0.0-alpha.7](https://github.com/Automattic/newspack-plugin/compare/v4.0.0-alpha.6...v4.0.0-alpha.7) (2024-05-27)


### Bug Fixes

* **ras:** handle newsletters subscribe form selector ([5699466](https://github.com/Automattic/newspack-plugin/commit/5699466252fdbd4ea551506f3c9f26a71f964a66))

## [3.8.8](https://github.com/Automattic/newspack-plugin/compare/v3.8.7...v3.8.8) (2024-05-27)


### Bug Fixes

* **ras:** handle newsletters subscribe form selector ([5699466](https://github.com/Automattic/newspack-plugin/commit/5699466252fdbd4ea551506f3c9f26a71f964a66))

## [3.8.7](https://github.com/Automattic/newspack-plugin/compare/v3.8.6...v3.8.7) (2024-05-17)


### Bug Fixes

* **google-login:** use wp_generate_password for CSRF; handle CSRF saving failure ([37bab22](https://github.com/Automattic/newspack-plugin/commit/37bab2256a02a1a473c8cbcf2ec7b35309b283f3))
* **oauth:** prevent false warning when opening Google OAuth ([22723f2](https://github.com/Automattic/newspack-plugin/commit/22723f256c1a8687fdb3d5fec3ed444be6f75c00))

# [4.0.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v4.0.0-alpha.3...v4.0.0-alpha.4) (2024-05-15)


### Bug Fixes

* autocomplete orders only for virtual products ([#3111](https://github.com/Automattic/newspack-plugin/issues/3111)) ([bfbe554](https://github.com/Automattic/newspack-plugin/commit/bfbe5541c7dcee270a6c2116344ffaa18742f612))
* **google-login:** get the email from the /tokeninfo endpoint ([#3117](https://github.com/Automattic/newspack-plugin/issues/3117)) ([3296f1a](https://github.com/Automattic/newspack-plugin/commit/3296f1a0b36c7992749d6209bce3ed3ef2ea5ec5))
* **google-oauth:** use a custom table for transients ([#3106](https://github.com/Automattic/newspack-plugin/issues/3106)) ([d4a2f5c](https://github.com/Automattic/newspack-plugin/commit/d4a2f5ce8ff6931b98593dafe759eab8b59d7ab2))
* **oauth-transients:** remove redundant cleanup ([#3112](https://github.com/Automattic/newspack-plugin/issues/3112)) ([c123c02](https://github.com/Automattic/newspack-plugin/commit/c123c0248ecf9aab8f16e5ab63e84f9900b3d6f5))
* **ras:** sync purchase data only for most recent order/subscription ([#3086](https://github.com/Automattic/newspack-plugin/issues/3086)) ([2c7763a](https://github.com/Automattic/newspack-plugin/commit/2c7763a53894e5c350d6ce76e0f4ab3b1e768bc5))

# [4.0.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v4.0.0-alpha.2...v4.0.0-alpha.3) (2024-04-26)


### Bug Fixes

* enable Memberships fix cron job only when environment constant is defined ([#3087](https://github.com/Automattic/newspack-plugin/issues/3087)) ([5d40297](https://github.com/Automattic/newspack-plugin/commit/5d40297252a9022bb9b63b72cf805a08c37eb553))

# [4.0.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v4.0.0-alpha.1...v4.0.0-alpha.2) (2024-04-25)


### Bug Fixes

* remove deprecated filter callback ([#3090](https://github.com/Automattic/newspack-plugin/issues/3090)) ([5d7d0bf](https://github.com/Automattic/newspack-plugin/commit/5d7d0bf71d16907d3aa98def372a7d9ede8949c6))

## [3.8.1](https://github.com/Automattic/newspack-plugin/compare/v3.8.0...v3.8.1) (2024-04-25)


### Bug Fixes

* remove deprecated filter callback ([#3090](https://github.com/Automattic/newspack-plugin/issues/3090)) ([5d7d0bf](https://github.com/Automattic/newspack-plugin/commit/5d7d0bf71d16907d3aa98def372a7d9ede8949c6))


# [4.0.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v3.8.0...v4.0.0-alpha.1) (2024-04-25)


### Bug Fixes

* ensure only admins can reset starter content and newspack options ([#3081](https://github.com/Automattic/newspack-plugin/issues/3081)) ([4606721](https://github.com/Automattic/newspack-plugin/commit/46067217985f904286ddb2c952ba0e1deb954e56))


### Features

* add product option to autocomplete orders ([#3072](https://github.com/Automattic/newspack-plugin/issues/3072)) ([4a2859b](https://github.com/Automattic/newspack-plugin/commit/4a2859b894eab499e194102b0636dc20466e0c22))
* **ras:** skip campaign setup ([#3051](https://github.com/Automattic/newspack-plugin/issues/3051)) ([9ef0e6d](https://github.com/Automattic/newspack-plugin/commit/9ef0e6d1de8d4dafda4d2af1304206d0f8190b15))


### BREAKING CHANGES

* Changes order autocompletion behavior for existing products!

* feat: add product option to autocomplete orders

* chore: update outdated docblock description

# [3.8.0](https://github.com/Automattic/newspack-plugin/compare/v3.7.0...v3.8.0) (2024-04-25)


### Features

* **google-login:** add action for reporting issues; increase transient time ([#3084](https://github.com/Automattic/newspack-plugin/issues/3084)) ([4875040](https://github.com/Automattic/newspack-plugin/commit/487504053ba44ab91b8624bd11e9bc9c71f7e56a))

# [3.7.0](https://github.com/Automattic/newspack-plugin/compare/v3.6.13...v3.7.0) (2024-04-24)


### Bug Fixes

* prevent multiple inputs from resetting in autocompletetokenfield component ([#3023](https://github.com/Automattic/newspack-plugin/issues/3023)) ([7f30d79](https://github.com/Automattic/newspack-plugin/commit/7f30d79bb4385f1a6bc28c275fda3bca2772fc71))
* **reader-reg-block:** respect newsletter subscription checkbox state ([#3024](https://github.com/Automattic/newspack-plugin/issues/3024)) ([02728d3](https://github.com/Automattic/newspack-plugin/commit/02728d3cbd4355d8f09db7a44cb42c99f08d9da6))
* revert [#3050](https://github.com/Automattic/newspack-plugin/issues/3050) due to performance issues ([#3059](https://github.com/Automattic/newspack-plugin/issues/3059)) ([a986c17](https://github.com/Automattic/newspack-plugin/commit/a986c174dc6e815bd72f15a51ea37d245266996b))


### Features

* support for MC tags as subscription lists ([#3035](https://github.com/Automattic/newspack-plugin/issues/3035)) ([c363630](https://github.com/Automattic/newspack-plugin/commit/c363630971464f2023c3fe9cd67e58707d68931b))

# [3.7.0-alpha.7](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.6...v3.7.0-alpha.7) (2024-04-22)


### Bug Fixes

* add option to display subscriptionless memberships ([#3058](https://github.com/Automattic/newspack-plugin/issues/3058)) ([aa493c9](https://github.com/Automattic/newspack-plugin/commit/aa493c95e609853c8380830c72faf39c69713759))

## [3.6.13](https://github.com/Automattic/newspack-plugin/compare/v3.6.12...v3.6.13) (2024-04-22)


### Bug Fixes

* add option to display subscriptionless memberships ([#3058](https://github.com/Automattic/newspack-plugin/issues/3058)) ([aa493c9](https://github.com/Automattic/newspack-plugin/commit/aa493c95e609853c8380830c72faf39c69713759))

# [3.7.0-alpha.6](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.5...v3.7.0-alpha.6) (2024-04-22)


### Bug Fixes

* **woo:** include all active statuses ([#3074](https://github.com/Automattic/newspack-plugin/issues/3074)) ([b9b622f](https://github.com/Automattic/newspack-plugin/commit/b9b622fc4aae68843b4bf4ecc0ae6783f00d426f))

## [3.6.12](https://github.com/Automattic/newspack-plugin/compare/v3.6.11...v3.6.12) (2024-04-22)


### Bug Fixes

* **woo:** include all active statuses ([#3074](https://github.com/Automattic/newspack-plugin/issues/3074)) ([b9b622f](https://github.com/Automattic/newspack-plugin/commit/b9b622fc4aae68843b4bf4ecc0ae6783f00d426f))

# [3.7.0-alpha.5](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.4...v3.7.0-alpha.5) (2024-04-19)


### Bug Fixes

* minor bugs in reCAPTCHA + Campaigns admin UIs ([#3073](https://github.com/Automattic/newspack-plugin/issues/3073)) ([876d0a9](https://github.com/Automattic/newspack-plugin/commit/876d0a9a1baa26480e399d52199d62d8a8f6bed5))


## [3.6.12](https://github.com/Automattic/newspack-plugin/compare/v3.6.11...v3.6.12) (2024-04-22)

### Bug Fixes

## [3.6.11](https://github.com/Automattic/newspack-plugin/compare/v3.6.10...v3.6.11) (2024-04-19)


### Bug Fixes

* minor bugs in reCAPTCHA + Campaigns admin UIs ([#3073](https://github.com/Automattic/newspack-plugin/issues/3073)) ([876d0a9](https://github.com/Automattic/newspack-plugin/commit/876d0a9a1baa26480e399d52199d62d8a8f6bed5))

# [3.7.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.3...v3.7.0-alpha.4) (2024-04-18)

### Bug Fixes

* safer approach to comment metering fix ([#3067](https://github.com/Automattic/newspack-plugin/issues/3067)) ([e73657d](https://github.com/Automattic/newspack-plugin/commit/e73657d75fabf2bd56943d4c556f40abf4ba0aef))


## [3.6.11](https://github.com/Automattic/newspack-plugin/compare/v3.6.10...v3.6.11) (2024-04-19)

### Bug Fixes

* minor bugs in reCAPTCHA + Campaigns admin UIs ([#3073](https://github.com/Automattic/newspack-plugin/issues/3073)) ([876d0a9](https://github.com/Automattic/newspack-plugin/commit/876d0a9a1baa26480e399d52199d62d8a8f6bed5))

## [3.6.10](https://github.com/Automattic/newspack-plugin/compare/v3.6.9...v3.6.10) (2024-04-15)


### Bug Fixes

* safer approach to comment metering fix ([#3067](https://github.com/Automattic/newspack-plugin/issues/3067)) ([e73657d](https://github.com/Automattic/newspack-plugin/commit/e73657d75fabf2bd56943d4c556f40abf4ba0aef))

# [3.7.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.2...v3.7.0-alpha.3) (2024-04-12)


### Bug Fixes

* revert 3057 ([#3063](https://github.com/Automattic/newspack-plugin/issues/3063)) ([25eea61](https://github.com/Automattic/newspack-plugin/commit/25eea61980422f3f684429b57cdc3ca42234bd10))

## [3.6.9](https://github.com/Automattic/newspack-plugin/compare/v3.6.8...v3.6.9) (2024-04-12)


### Bug Fixes

* revert 3057 ([#3063](https://github.com/Automattic/newspack-plugin/issues/3063)) ([25eea61](https://github.com/Automattic/newspack-plugin/commit/25eea61980422f3f684429b57cdc3ca42234bd10))

# [3.7.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v3.7.0-alpha.1...v3.7.0-alpha.2) (2024-04-11)

### Bug Fixes

* revert 3057 ([#3063](https://github.com/Automattic/newspack-plugin/issues/3063)) ([25eea61](https://github.com/Automattic/newspack-plugin/commit/25eea61980422f3f684429b57cdc3ca42234bd10))

* hourly cron job to check for prematurely expired memberships ([#3060](https://github.com/Automattic/newspack-plugin/issues/3060)) ([77eeee3](https://github.com/Automattic/newspack-plugin/commit/77eeee374723a2780ace91669f848b2d24a436c4))

# [3.7.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v3.6.7...v3.7.0-alpha.1) (2024-04-11)

### Bug Fixes

* prevent multiple inputs from resetting in autocompletetokenfield component ([#3023](https://github.com/Automattic/newspack-plugin/issues/3023)) ([7f30d79](https://github.com/Automattic/newspack-plugin/commit/7f30d79bb4385f1a6bc28c275fda3bca2772fc71))
* **reader-reg-block:** respect newsletter subscription checkbox state ([#3024](https://github.com/Automattic/newspack-plugin/issues/3024)) ([02728d3](https://github.com/Automattic/newspack-plugin/commit/02728d3cbd4355d8f09db7a44cb42c99f08d9da6))
* revert [#3050](https://github.com/Automattic/newspack-plugin/issues/3050) due to performance issues ([#3059](https://github.com/Automattic/newspack-plugin/issues/3059)) ([a986c17](https://github.com/Automattic/newspack-plugin/commit/a986c174dc6e815bd72f15a51ea37d245266996b))


### Features

* support for MC tags as subscription lists ([#3035](https://github.com/Automattic/newspack-plugin/issues/3035)) ([c363630](https://github.com/Automattic/newspack-plugin/commit/c363630971464f2023c3fe9cd67e58707d68931b))

## [3.6.8](https://github.com/Automattic/newspack-plugin/compare/v3.6.7...v3.6.8) (2024-04-11)

### Bug Fixes

* hourly cron job to check for prematurely expired memberships ([#3060](https://github.com/Automattic/newspack-plugin/issues/3060)) ([77eeee3](https://github.com/Automattic/newspack-plugin/commit/77eeee374723a2780ace91669f848b2d24a436c4))

## [3.6.7](https://github.com/Automattic/newspack-plugin/compare/v3.6.6...v3.6.7) (2024-04-11)


### Bug Fixes

* fallback to subscription for sync data when customer has no orders ([#3053](https://github.com/Automattic/newspack-plugin/issues/3053)) ([df4d1b0](https://github.com/Automattic/newspack-plugin/commit/df4d1b06ac77356cf6cfdfb45a5f8234aac0888f))

## [3.6.6](https://github.com/Automattic/newspack-plugin/compare/v3.6.5...v3.6.6) (2024-04-11)


### Bug Fixes

* force release ([d9ee12a](https://github.com/Automattic/newspack-plugin/commit/d9ee12afe2e862485dcb1376f749c71c74ae86df))

## [3.6.5](https://github.com/Automattic/newspack-plugin/compare/v3.6.4...v3.6.5) (2024-04-11)


### Bug Fixes

* revert [#3050](https://github.com/Automattic/newspack-plugin/issues/3050) due to performance issues ([#3059](https://github.com/Automattic/newspack-plugin/issues/3059)) ([1d45d9e](https://github.com/Automattic/newspack-plugin/commit/1d45d9ee34b72b17dee41332389d890cd8f96423))

## [3.6.4](https://github.com/Automattic/newspack-plugin/compare/v3.6.3...v3.6.4) (2024-04-10)


### Bug Fixes

* dont hide comments on metered paywall posts ([#3057](https://github.com/Automattic/newspack-plugin/issues/3057)) ([f06e86f](https://github.com/Automattic/newspack-plugin/commit/f06e86f01aef0b794a3c18684abc5333690a5362))

## [3.6.3](https://github.com/Automattic/newspack-plugin/compare/v3.6.2...v3.6.3) (2024-04-10)


### Bug Fixes

* fatal error when checking memberships for users with no memberships ([#3055](https://github.com/Automattic/newspack-plugin/issues/3055)) ([65024d1](https://github.com/Automattic/newspack-plugin/commit/65024d1813fe9bd5bc88e73cd93ca23c1bb51619))

## [3.6.2](https://github.com/Automattic/newspack-plugin/compare/v3.6.1...v3.6.2) (2024-04-10)


### Bug Fixes

* **ras:** email to login conversion ([#3031](https://github.com/Automattic/newspack-plugin/issues/3031)) ([075d7f1](https://github.com/Automattic/newspack-plugin/commit/075d7f147427b536903c29f0349c249a314f1e2a))

## [3.6.1](https://github.com/Automattic/newspack-plugin/compare/v3.6.0...v3.6.1) (2024-04-09)


### Bug Fixes

* ensure active status for memberships tied to active subs ([#3050](https://github.com/Automattic/newspack-plugin/issues/3050)) ([b74a06d](https://github.com/Automattic/newspack-plugin/commit/b74a06dac95b3f635795285ab38c6e4ceb1b40ee))

# [3.6.0](https://github.com/Automattic/newspack-plugin/compare/v3.5.4...v3.6.0) (2024-04-08)


### Bug Fixes

* allow + character in reader account email ([#2980](https://github.com/Automattic/newspack-plugin/issues/2980)) ([2a8530c](https://github.com/Automattic/newspack-plugin/commit/2a8530c9e89cb56b3a7fdf47d558c8dbb542f0c6))
* fix fallback logic in get_order_data() util method ([6de9b0f](https://github.com/Automattic/newspack-plugin/commit/6de9b0f6942ecd6cf7d2db80e60e935d1ceedc07))
* **health-check:** fix call to undefined function get_plugins() ([#2998](https://github.com/Automattic/newspack-plugin/issues/2998)) ([ff9ccb3](https://github.com/Automattic/newspack-plugin/commit/ff9ccb315973ff7809078f9d38ef487800e65b3e))
* **ras:** reset otp inputs when switching login actions ([#2971](https://github.com/Automattic/newspack-plugin/issues/2971)) ([538d00b](https://github.com/Automattic/newspack-plugin/commit/538d00b71c66b59b36eafe081466cc4545b3e9d5))
* render correct gam onboarding flow and update service account support link ([#2986](https://github.com/Automattic/newspack-plugin/issues/2986)) ([b6a643f](https://github.com/Automattic/newspack-plugin/commit/b6a643ffafe75940de2d80492c23c3291bdd9cf5))


### Features

* add ID to ad unit dropdown labels ([#2988](https://github.com/Automattic/newspack-plugin/issues/2988)) ([44a5048](https://github.com/Automattic/newspack-plugin/commit/44a5048f7e038d4336a71c9c635938ac6c1427e2))
* add option to choose which RAS meta fields to sync ([#2989](https://github.com/Automattic/newspack-plugin/issues/2989)) ([f920115](https://github.com/Automattic/newspack-plugin/commit/f920115ddf8173b6cb069dbf270e1dd83efeb367))

# [3.6.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v3.5.2...v3.6.0-alpha.1) (2024-03-28)


### Bug Fixes

* allow + character in reader account email ([#2980](https://github.com/Automattic/newspack-plugin/issues/2980)) ([2a8530c](https://github.com/Automattic/newspack-plugin/commit/2a8530c9e89cb56b3a7fdf47d558c8dbb542f0c6))
* fix fallback logic in get_order_data() util method ([6de9b0f](https://github.com/Automattic/newspack-plugin/commit/6de9b0f6942ecd6cf7d2db80e60e935d1ceedc07))
* **health-check:** fix call to undefined function get_plugins() ([#2998](https://github.com/Automattic/newspack-plugin/issues/2998)) ([ff9ccb3](https://github.com/Automattic/newspack-plugin/commit/ff9ccb315973ff7809078f9d38ef487800e65b3e))
* **ras:** reset otp inputs when switching login actions ([#2971](https://github.com/Automattic/newspack-plugin/issues/2971)) ([538d00b](https://github.com/Automattic/newspack-plugin/commit/538d00b71c66b59b36eafe081466cc4545b3e9d5))
* render correct gam onboarding flow and update service account support link ([#2986](https://github.com/Automattic/newspack-plugin/issues/2986)) ([b6a643f](https://github.com/Automattic/newspack-plugin/commit/b6a643ffafe75940de2d80492c23c3291bdd9cf5))


### Features

* add ID to ad unit dropdown labels ([#2988](https://github.com/Automattic/newspack-plugin/issues/2988)) ([44a5048](https://github.com/Automattic/newspack-plugin/commit/44a5048f7e038d4336a71c9c635938ac6c1427e2))
* add option to choose which RAS meta fields to sync ([#2989](https://github.com/Automattic/newspack-plugin/issues/2989)) ([f920115](https://github.com/Automattic/newspack-plugin/commit/f920115ddf8173b6cb069dbf270e1dd83efeb367))


## [3.5.4](https://github.com/Automattic/newspack-plugin/compare/v3.5.3...v3.5.4) (2024-04-02)


### Bug Fixes

* add filters for Data Events reader_registered handlers ([#3030](https://github.com/Automattic/newspack-plugin/issues/3030)) ([a27bf90](https://github.com/Automattic/newspack-plugin/commit/a27bf901197492a1bae583896f2122cfbf8a067f))


## [3.5.3](https://github.com/Automattic/newspack-plugin/compare/v3.5.2...v3.5.3) (2024-03-28)


### Bug Fixes

* **sitekit:** use analytics 4 module ([#3022](https://github.com/Automattic/newspack-plugin/issues/3022)) ([94dbc18](https://github.com/Automattic/newspack-plugin/commit/94dbc18dc3ac523847727c6a4263442b12c26185))

## [3.5.2](https://github.com/Automattic/newspack-plugin/compare/v3.5.1...v3.5.2) (2024-03-27)


### Bug Fixes

* update template for woo 8.7.0 ([#3001](https://github.com/Automattic/newspack-plugin/issues/3001)) ([76b205b](https://github.com/Automattic/newspack-plugin/commit/76b205bf795026c94ccefc949005aed7dfa440f8))

## [3.5.1](https://github.com/Automattic/newspack-plugin/compare/v3.5.0...v3.5.1) (2024-03-26)


### Bug Fixes

* cherry-pick new features for Network sites ([#3011](https://github.com/Automattic/newspack-plugin/issues/3011)) ([7316d62](https://github.com/Automattic/newspack-plugin/commit/7316d622d7d19787f7e275bfc439c079e645aa20)), closes [#2989](https://github.com/Automattic/newspack-plugin/issues/2989)

# [3.5.0](https://github.com/Automattic/newspack-plugin/compare/v3.4.0...v3.5.0) (2024-03-25)


### Features

* **ras:** updates to metadata fields synced to ESP ([#2993](https://github.com/Automattic/newspack-plugin/issues/2993)) ([f28f436](https://github.com/Automattic/newspack-plugin/commit/f28f436ad64026b2d012ac9f0990e7381c90ded4))

# [3.4.0](https://github.com/Automattic/newspack-plugin/compare/v3.3.5...v3.4.0) (2024-03-25)


### Bug Fixes

* allow media lib access if explicitly set ([63ba98c](https://github.com/Automattic/newspack-plugin/commit/63ba98c10456954d07b2fb64455a79d00e40ff33))
* correct text domains for handoff, healthcheck wizards ([#2961](https://github.com/Automattic/newspack-plugin/issues/2961)) ([a8f5de3](https://github.com/Automattic/newspack-plugin/commit/a8f5de30d22a8a9062409579727340ced31d706d))
* correct text domains for popup wizard ([#2962](https://github.com/Automattic/newspack-plugin/issues/2962)) ([dba7f6c](https://github.com/Automattic/newspack-plugin/commit/dba7f6c9a4fd22ccbfa63d589d29b1d9a5092730))
* correct text domains in wizards/engagement ([#2957](https://github.com/Automattic/newspack-plugin/issues/2957)) ([4db61b0](https://github.com/Automattic/newspack-plugin/commit/4db61b03e407cb1298df7391355ce91f320a42b6))
* data-events tweaks ([#2935](https://github.com/Automattic/newspack-plugin/issues/2935)) ([828f5f2](https://github.com/Automattic/newspack-plugin/commit/828f5f24c11affcea1cd7433062bd70dbe2eff7b))
* fix fallback logic in get_order_data() util method ([0ac5b35](https://github.com/Automattic/newspack-plugin/commit/0ac5b35f127540e1a16fe972405256fcb677dd44))
* **GA:** update spelling of referrer param ([#2917](https://github.com/Automattic/newspack-plugin/issues/2917)) ([fc2bd26](https://github.com/Automattic/newspack-plugin/commit/fc2bd26280abc62992e18315ce7b95864986783a))
* handle no themes are available ([#2967](https://github.com/Automattic/newspack-plugin/issues/2967)) ([6ae6c7c](https://github.com/Automattic/newspack-plugin/commit/6ae6c7cea2059835cd7de87369303f3b7ddfbc48))
* **image-credits:** handle errors when fetching metadata ([#2968](https://github.com/Automattic/newspack-plugin/issues/2968)) ([6e9459f](https://github.com/Automattic/newspack-plugin/commit/6e9459f7bf4bf08a0b4c259677162fcdba6276eb))
* **media-partners:** handle skip-in-feeds when exporting to apple news ([7226e42](https://github.com/Automattic/newspack-plugin/commit/7226e4223ea1c98bca57f22319a86c9e285d487a))
* **memberships-gate:** handle no comments ([#2999](https://github.com/Automattic/newspack-plugin/issues/2999)) ([1791ca4](https://github.com/Automattic/newspack-plugin/commit/1791ca4afcec087b095eb1d4903cf24aa253f995))
* **newsletters:** handle status metadata ([1c8e069](https://github.com/Automattic/newspack-plugin/commit/1c8e069051b191899bd232a97ef5669c50f221c7))
* **ras:** check for auth status before updating my account label ([#2970](https://github.com/Automattic/newspack-plugin/issues/2970)) ([3a0fb8b](https://github.com/Automattic/newspack-plugin/commit/3a0fb8b9a748fa2b67cbc43f9cf334788d8c4d57))
* revert credits display when editing image block ([#2994](https://github.com/Automattic/newspack-plugin/issues/2994)) ([54ca247](https://github.com/Automattic/newspack-plugin/commit/54ca24705300353d13d504a3bd3d688d46e29d73)), closes [#2936](https://github.com/Automattic/newspack-plugin/issues/2936)
* update text domain in wizards/connections directory ([#2956](https://github.com/Automattic/newspack-plugin/issues/2956)) ([2acb12d](https://github.com/Automattic/newspack-plugin/commit/2acb12dcd0a60d38390c950872ee4bdd073e7e37))
* update text domains for the advertising wizard ([#2952](https://github.com/Automattic/newspack-plugin/issues/2952)) ([cb45da2](https://github.com/Automattic/newspack-plugin/commit/cb45da20d409ec92b5deb521d27261e27f6dc330))
* update text domains for the analytics, components ([#2953](https://github.com/Automattic/newspack-plugin/issues/2953)) ([04ff6a9](https://github.com/Automattic/newspack-plugin/commit/04ff6a9a49908c0276d8fad828e60e86ed0fb769))


### Features

* add a filter to esp normalized contact ([#2940](https://github.com/Automattic/newspack-plugin/issues/2940)) ([6ce05be](https://github.com/Automattic/newspack-plugin/commit/6ce05beb8ab40000a0560d1e1ec59abfdd035d09))
* **core-image-block:** display media credit in editor; handle distributed posts ([#2936](https://github.com/Automattic/newspack-plugin/issues/2936)) ([6c8d9ff](https://github.com/Automattic/newspack-plugin/commit/6c8d9ff0e1059f41c90819c6ef5537d0930cc55e))
* jetpack default modules ([#2959](https://github.com/Automattic/newspack-plugin/issues/2959)) ([90e918e](https://github.com/Automattic/newspack-plugin/commit/90e918e7db5a4b55b5778989831a8ea4590d8b41))
* **webhooks:** add `newspack_webhooks_process_request_errors` action ([#2955](https://github.com/Automattic/newspack-plugin/issues/2955)) ([20c6b29](https://github.com/Automattic/newspack-plugin/commit/20c6b299f5bf518bcf6e5e120ab5e38d14501bd9))

## [3.3.5](https://github.com/Automattic/newspack-plugin/compare/v3.3.4...v3.3.5) (2024-03-25)


### Bug Fixes

* update WC's required fields if using other RR platforms ([#2997](https://github.com/Automattic/newspack-plugin/issues/2997)) ([a0d6632](https://github.com/Automattic/newspack-plugin/commit/a0d6632ddd76fc55a6f4b6ef0dd1387c31f8e90a))

## [3.3.4](https://github.com/Automattic/newspack-plugin/compare/v3.3.3...v3.3.4) (2024-03-20)


### Bug Fixes

* **memberships-gate:** handle no comments ([#2999](https://github.com/Automattic/newspack-plugin/issues/2999)) ([7973c8e](https://github.com/Automattic/newspack-plugin/commit/7973c8ea85faea4fcfb50a69a9ad8d03324a09f9))

## [3.3.3](https://github.com/Automattic/newspack-plugin/compare/v3.3.2...v3.3.3) (2024-03-12)


### Bug Fixes

* add a check for setup complete before adding homepage pattern ([#2987](https://github.com/Automattic/newspack-plugin/issues/2987)) ([36238e9](https://github.com/Automattic/newspack-plugin/commit/36238e96a863723720979520400a9afc65dd0cfa))

## [3.3.3](https://github.com/Automattic/newspack-plugin/compare/v3.3.2...v3.3.3) (2024-03-12)


### Bug Fixes

* allow media lib access if explicitly set ([63ba98c](https://github.com/Automattic/newspack-plugin/commit/63ba98c10456954d07b2fb64455a79d00e40ff33))
* correct text domains for handoff, healthcheck wizards ([#2961](https://github.com/Automattic/newspack-plugin/issues/2961)) ([a8f5de3](https://github.com/Automattic/newspack-plugin/commit/a8f5de30d22a8a9062409579727340ced31d706d))
* correct text domains for popup wizard ([#2962](https://github.com/Automattic/newspack-plugin/issues/2962)) ([dba7f6c](https://github.com/Automattic/newspack-plugin/commit/dba7f6c9a4fd22ccbfa63d589d29b1d9a5092730))
* correct text domains in wizards/engagement ([#2957](https://github.com/Automattic/newspack-plugin/issues/2957)) ([4db61b0](https://github.com/Automattic/newspack-plugin/commit/4db61b03e407cb1298df7391355ce91f320a42b6))
* data-events tweaks ([#2935](https://github.com/Automattic/newspack-plugin/issues/2935)) ([828f5f2](https://github.com/Automattic/newspack-plugin/commit/828f5f24c11affcea1cd7433062bd70dbe2eff7b))
* **GA:** update spelling of referrer param ([#2917](https://github.com/Automattic/newspack-plugin/issues/2917)) ([fc2bd26](https://github.com/Automattic/newspack-plugin/commit/fc2bd26280abc62992e18315ce7b95864986783a))
* handle no themes are available ([#2967](https://github.com/Automattic/newspack-plugin/issues/2967)) ([6ae6c7c](https://github.com/Automattic/newspack-plugin/commit/6ae6c7cea2059835cd7de87369303f3b7ddfbc48))
* **image-credits:** handle errors when fetching metadata ([#2968](https://github.com/Automattic/newspack-plugin/issues/2968)) ([6e9459f](https://github.com/Automattic/newspack-plugin/commit/6e9459f7bf4bf08a0b4c259677162fcdba6276eb))
* **media-partners:** handle skip-in-feeds when exporting to apple news ([7226e42](https://github.com/Automattic/newspack-plugin/commit/7226e4223ea1c98bca57f22319a86c9e285d487a))
* **newsletters:** handle status metadata ([1c8e069](https://github.com/Automattic/newspack-plugin/commit/1c8e069051b191899bd232a97ef5669c50f221c7))
* **ras:** check for auth status before updating my account label ([#2970](https://github.com/Automattic/newspack-plugin/issues/2970)) ([3a0fb8b](https://github.com/Automattic/newspack-plugin/commit/3a0fb8b9a748fa2b67cbc43f9cf334788d8c4d57))
* update text domain in wizards/connections directory ([#2956](https://github.com/Automattic/newspack-plugin/issues/2956)) ([2acb12d](https://github.com/Automattic/newspack-plugin/commit/2acb12dcd0a60d38390c950872ee4bdd073e7e37))
* update text domains for the advertising wizard ([#2952](https://github.com/Automattic/newspack-plugin/issues/2952)) ([cb45da2](https://github.com/Automattic/newspack-plugin/commit/cb45da20d409ec92b5deb521d27261e27f6dc330))
* update text domains for the analytics, components ([#2953](https://github.com/Automattic/newspack-plugin/issues/2953)) ([04ff6a9](https://github.com/Automattic/newspack-plugin/commit/04ff6a9a49908c0276d8fad828e60e86ed0fb769))
* add a check for setup complete before adding homepage pattern ([#2987](https://github.com/Automattic/newspack-plugin/issues/2987)) ([36238e9](https://github.com/Automattic/newspack-plugin/commit/36238e96a863723720979520400a9afc65dd0cfa))


### Features

* add a filter to esp normalized contact ([#2940](https://github.com/Automattic/newspack-plugin/issues/2940)) ([6ce05be](https://github.com/Automattic/newspack-plugin/commit/6ce05beb8ab40000a0560d1e1ec59abfdd035d09))
* **core-image-block:** display media credit in editor; handle distributed posts ([#2936](https://github.com/Automattic/newspack-plugin/issues/2936)) ([6c8d9ff](https://github.com/Automattic/newspack-plugin/commit/6c8d9ff0e1059f41c90819c6ef5537d0930cc55e))
* jetpack default modules ([#2959](https://github.com/Automattic/newspack-plugin/issues/2959)) ([90e918e](https://github.com/Automattic/newspack-plugin/commit/90e918e7db5a4b55b5778989831a8ea4590d8b41))
* **webhooks:** add `newspack_webhooks_process_request_errors` action ([#2955](https://github.com/Automattic/newspack-plugin/issues/2955)) ([20c6b29](https://github.com/Automattic/newspack-plugin/commit/20c6b299f5bf518bcf6e5e120ab5e38d14501bd9))

## [3.3.2](https://github.com/Automattic/newspack-plugin/compare/v3.3.1...v3.3.2) (2024-03-07)


### Bug Fixes

* **ras:** check for woo when generating password reset link ([#2975](https://github.com/Automattic/newspack-plugin/issues/2975)) ([bf5150c](https://github.com/Automattic/newspack-plugin/commit/bf5150ce32c51324f408e112ebb6f1da71ea46a9))

## [3.3.1](https://github.com/Automattic/newspack-plugin/compare/v3.3.0...v3.3.1) (2024-03-05)


### Bug Fixes

* **woo:** disable related products ([#2972](https://github.com/Automattic/newspack-plugin/issues/2972)) ([f60b966](https://github.com/Automattic/newspack-plugin/commit/f60b966d3a1a07354d921c512300e254d3a24ea4))

# [3.3.0](https://github.com/Automattic/newspack-plugin/compare/v3.2.1...v3.3.0) (2024-03-05)


### Features

* new option for auto-populating iamge credits ([#2973](https://github.com/Automattic/newspack-plugin/issues/2973)) ([b6c62ea](https://github.com/Automattic/newspack-plugin/commit/b6c62ead416894cdb9e2b7b74d9093968e780211))

## [3.2.1](https://github.com/Automattic/newspack-plugin/compare/v3.2.0...v3.2.1) (2024-03-05)


### Bug Fixes

* correct issue with media sizes not saving ([#2958](https://github.com/Automattic/newspack-plugin/issues/2958)) ([64a8833](https://github.com/Automattic/newspack-plugin/commit/64a88334f19647b68787d5039dd413c2b8f531d7))

# [3.2.0](https://github.com/Automattic/newspack-plugin/compare/v3.1.2...v3.2.0) (2024-03-04)


### Bug Fixes

* correct translation strings for assets/blocks ([#2926](https://github.com/Automattic/newspack-plugin/issues/2926)) ([bb5504d](https://github.com/Automattic/newspack-plugin/commit/bb5504d6cf708c80679801df84b15f955df05662))
* correct translation strings for half of assets/components ([#2927](https://github.com/Automattic/newspack-plugin/issues/2927)) ([664aa4a](https://github.com/Automattic/newspack-plugin/commit/664aa4a84132a20334ad6479ef1c804f21554b49))
* correct translation strings for number of assets files ([#2929](https://github.com/Automattic/newspack-plugin/issues/2929)) ([858d9e4](https://github.com/Automattic/newspack-plugin/commit/858d9e40745bcb6a4226ea365826bce8342e28d8))
* correct translation strings for second half of assets/components ([#2928](https://github.com/Automattic/newspack-plugin/issues/2928)) ([087b1b0](https://github.com/Automattic/newspack-plugin/commit/087b1b0416e0aa327deaf81a972a58b3ccf9e07b))
* data-events tweaks ([#2935](https://github.com/Automattic/newspack-plugin/issues/2935)) ([80354d2](https://github.com/Automattic/newspack-plugin/commit/80354d25ce99b285a9221e6b9138463c9d429014))
* **ga4:** add default client id ([274c238](https://github.com/Automattic/newspack-plugin/commit/274c238f06e4b3bf9fc16a4849f3606fee9343f6))
* **my-account:** always update all subs when updating payment method ([#2934](https://github.com/Automattic/newspack-plugin/issues/2934)) ([e1618f8](https://github.com/Automattic/newspack-plugin/commit/e1618f8bc8e49ff63b39f0e5a9d3e114297e9944))


### Features

* add a filter to esp normalized contact ([#2940](https://github.com/Automattic/newspack-plugin/issues/2940)) ([6101165](https://github.com/Automattic/newspack-plugin/commit/6101165c82ea39fe55f1f77b4e1e5af0a1dd5a4f))
* add link to block pattern category page ([#2932](https://github.com/Automattic/newspack-plugin/issues/2932)) ([24a2adc](https://github.com/Automattic/newspack-plugin/commit/24a2adc61c4a942f2d28382dd022512855303b7d))
* **core-image-block:** display media credit in editor; handle distributed posts ([#2936](https://github.com/Automattic/newspack-plugin/issues/2936)) ([b0d2dff](https://github.com/Automattic/newspack-plugin/commit/b0d2dff56318bd463684ed3dec04d7875a98f420))
* **rss:** enable control over feed update frequency and guid ([#2925](https://github.com/Automattic/newspack-plugin/issues/2925)) ([948972c](https://github.com/Automattic/newspack-plugin/commit/948972c01d4bba313df5b41bb347a330e1b96431))

# [3.2.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v3.2.0-alpha.1...v3.2.0-alpha.2) (2024-02-29)


### Bug Fixes

* donate block layout for NRH ([#2954](https://github.com/Automattic/newspack-plugin/issues/2954)) ([8595985](https://github.com/Automattic/newspack-plugin/commit/8595985d6ce1273a5484d2f3ede1ee9596bd53b9))

# [3.2.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v3.1.1...v3.2.0-alpha.1) (2024-02-23)


### Bug Fixes

* correct translation strings for assets/blocks ([#2926](https://github.com/Automattic/newspack-plugin/issues/2926)) ([bb5504d](https://github.com/Automattic/newspack-plugin/commit/bb5504d6cf708c80679801df84b15f955df05662))
* correct translation strings for half of assets/components ([#2927](https://github.com/Automattic/newspack-plugin/issues/2927)) ([664aa4a](https://github.com/Automattic/newspack-plugin/commit/664aa4a84132a20334ad6479ef1c804f21554b49))
* correct translation strings for number of assets files ([#2929](https://github.com/Automattic/newspack-plugin/issues/2929)) ([858d9e4](https://github.com/Automattic/newspack-plugin/commit/858d9e40745bcb6a4226ea365826bce8342e28d8))
* correct translation strings for second half of assets/components ([#2928](https://github.com/Automattic/newspack-plugin/issues/2928)) ([087b1b0](https://github.com/Automattic/newspack-plugin/commit/087b1b0416e0aa327deaf81a972a58b3ccf9e07b))
* data-events tweaks ([#2935](https://github.com/Automattic/newspack-plugin/issues/2935)) ([80354d2](https://github.com/Automattic/newspack-plugin/commit/80354d25ce99b285a9221e6b9138463c9d429014))
* **ga4:** add default client id ([274c238](https://github.com/Automattic/newspack-plugin/commit/274c238f06e4b3bf9fc16a4849f3606fee9343f6))
* **my-account:** always update all subs when updating payment method ([#2934](https://github.com/Automattic/newspack-plugin/issues/2934)) ([e1618f8](https://github.com/Automattic/newspack-plugin/commit/e1618f8bc8e49ff63b39f0e5a9d3e114297e9944))


### Features

* add a filter to esp normalized contact ([#2940](https://github.com/Automattic/newspack-plugin/issues/2940)) ([6101165](https://github.com/Automattic/newspack-plugin/commit/6101165c82ea39fe55f1f77b4e1e5af0a1dd5a4f))
* add link to block pattern category page ([#2932](https://github.com/Automattic/newspack-plugin/issues/2932)) ([24a2adc](https://github.com/Automattic/newspack-plugin/commit/24a2adc61c4a942f2d28382dd022512855303b7d))
* **core-image-block:** display media credit in editor; handle distributed posts ([#2936](https://github.com/Automattic/newspack-plugin/issues/2936)) ([b0d2dff](https://github.com/Automattic/newspack-plugin/commit/b0d2dff56318bd463684ed3dec04d7875a98f420))
* **rss:** enable control over feed update frequency and guid ([#2925](https://github.com/Automattic/newspack-plugin/issues/2925)) ([948972c](https://github.com/Automattic/newspack-plugin/commit/948972c01d4bba313df5b41bb347a330e1b96431))

## [3.1.2](https://github.com/Automattic/newspack-plugin/compare/v3.1.1...v3.1.2) (2024-02-29)


### Bug Fixes

* donate block layout for NRH ([#2954](https://github.com/Automattic/newspack-plugin/issues/2954)) ([8595985](https://github.com/Automattic/newspack-plugin/commit/8595985d6ce1273a5484d2f3ede1ee9596bd53b9))

## [3.1.1](https://github.com/Automattic/newspack-plugin/compare/v3.1.0...v3.1.1) (2024-02-21)


### Bug Fixes

* avoid a reCAPTCHA API error when script is enqueued with a version param ([#2943](https://github.com/Automattic/newspack-plugin/issues/2943)) ([c7d8bff](https://github.com/Automattic/newspack-plugin/commit/c7d8bffd87a0ffb0f7ef3bf380967fae7f5d4c1c))

# [3.1.0](https://github.com/Automattic/newspack-plugin/compare/v3.0.5...v3.1.0) (2024-02-20)


### Bug Fixes

* add frequency tab options for donations, even when tiers are disabled ([#2930](https://github.com/Automattic/newspack-plugin/issues/2930)) ([cb7eb7b](https://github.com/Automattic/newspack-plugin/commit/cb7eb7b7f86697af9a648b10845bf23d2be91de3))
* **categories:** fix pager urls ([#2913](https://github.com/Automattic/newspack-plugin/issues/2913)) ([bb7e534](https://github.com/Automattic/newspack-plugin/commit/bb7e534afb224a2c03888eb5e953a8fc27a10719))
* **categories:** fix pager urls ([#2913](https://github.com/Automattic/newspack-plugin/issues/2913)) ([c851bb6](https://github.com/Automattic/newspack-plugin/commit/c851bb6d6fd35a0593877e34aaba091470fd56c5))
* **engagement-wizard:** handle error when retrieving subscription lists ([e85c108](https://github.com/Automattic/newspack-plugin/commit/e85c108ce6dd470a41ca9330fdbbdfbae4ed562c))
* **ras:** only sync spend total and last payment amounts for completed orders ([#2886](https://github.com/Automattic/newspack-plugin/issues/2886)) ([68aaf39](https://github.com/Automattic/newspack-plugin/commit/68aaf39fb744245cd91c9e041aa038e81c653b2a))
* redirect to origin from magic link ([9f41947](https://github.com/Automattic/newspack-plugin/commit/9f4194709502baeabbbf66233453795516f099c1))
* typescript errors ([dc27973](https://github.com/Automattic/newspack-plugin/commit/dc27973dedb0bc373a7c9e8877003845a6bf3adb))
* TypeScript usage; add to CI ([#2884](https://github.com/Automattic/newspack-plugin/issues/2884)) ([6f5e7a6](https://github.com/Automattic/newspack-plugin/commit/6f5e7a63594377eb9fa07589a08bb5dcbb96d043))
* update newsletter scroll appearance in Sign Up modal ([#2897](https://github.com/Automattic/newspack-plugin/issues/2897)) ([496723a](https://github.com/Automattic/newspack-plugin/commit/496723aa2e6519c803da615bbc494b22c57e6804))
* update path to wide template file ([#2918](https://github.com/Automattic/newspack-plugin/issues/2918)) ([fdd6b69](https://github.com/Automattic/newspack-plugin/commit/fdd6b69ef66908d8672215454765a2de62e9bf79))


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#2895](https://github.com/Automattic/newspack-plugin/issues/2895)) ([ea02075](https://github.com/Automattic/newspack-plugin/commit/ea020753c85e9ff881b08510effbcbe3b90b7b86)), closes [#2897](https://github.com/Automattic/newspack-plugin/issues/2897) [#2886](https://github.com/Automattic/newspack-plugin/issues/2886)
* **reader-revenue:** make NYP and Stripe Gateway optional ([#2866](https://github.com/Automattic/newspack-plugin/issues/2866)) ([fcfa88c](https://github.com/Automattic/newspack-plugin/commit/fcfa88c9d1b9053557b48e2cbd6172f58e0bac10))
* remove new tab default on image credits ([#2880](https://github.com/Automattic/newspack-plugin/issues/2880)) ([3c996b1](https://github.com/Automattic/newspack-plugin/commit/3c996b1fa7cae99675d455c446cfa425091f372f))
* **wc:** override cart, checkout, and my-account page templates ([#2893](https://github.com/Automattic/newspack-plugin/issues/2893)) ([68b1836](https://github.com/Automattic/newspack-plugin/commit/68b1836395e0b16536509c6e41bb1dfbc4de3571))

# [3.1.0-alpha.5](https://github.com/Automattic/newspack-plugin/compare/v3.1.0-alpha.4...v3.1.0-alpha.5) (2024-02-20)


### Bug Fixes

* **perfmatters:** use ignore-defaults constant when applying unused CSS ([#2863](https://github.com/Automattic/newspack-plugin/issues/2863)) ([6d52be3](https://github.com/Automattic/newspack-plugin/commit/6d52be38e8266d13506676675ffc57ec509b393d))
* **woocommerce:** fix order-pay page when using woocommerce-memberships-for-teams plugin ([#2923](https://github.com/Automattic/newspack-plugin/issues/2923)) ([e87e2dc](https://github.com/Automattic/newspack-plugin/commit/e87e2dc2192771e151dbc78c2b30a9c431a6a976))

# [3.1.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v3.1.0-alpha.3...v3.1.0-alpha.4) (2024-02-15)


### Bug Fixes

* add frequency tab options for donations, even when tiers are disabled ([#2930](https://github.com/Automattic/newspack-plugin/issues/2930)) ([cb7eb7b](https://github.com/Automattic/newspack-plugin/commit/cb7eb7b7f86697af9a648b10845bf23d2be91de3))

# [3.1.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v3.1.0-alpha.2...v3.1.0-alpha.3) (2024-02-09)
* **perfmatters:** use ignore-defaults constant when applying unused CSS ([#2863](https://github.com/Automattic/newspack-plugin/issues/2863)) ([6d52be3](https://github.com/Automattic/newspack-plugin/commit/6d52be38e8266d13506676675ffc57ec509b393d))

## [3.0.4](https://github.com/Automattic/newspack-plugin/compare/v3.0.3...v3.0.4) (2024-02-15)


### Bug Fixes

* **categories:** fix pager urls ([#2913](https://github.com/Automattic/newspack-plugin/issues/2913)) ([c851bb6](https://github.com/Automattic/newspack-plugin/commit/c851bb6d6fd35a0593877e34aaba091470fd56c5))
* update path to wide template file ([#2918](https://github.com/Automattic/newspack-plugin/issues/2918)) ([fdd6b69](https://github.com/Automattic/newspack-plugin/commit/fdd6b69ef66908d8672215454765a2de62e9bf79))

# [3.1.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v3.1.0-alpha.1...v3.1.0-alpha.2) (2024-02-09)


### Bug Fixes

* **categories:** fix pager urls ([#2913](https://github.com/Automattic/newspack-plugin/issues/2913)) ([bb7e534](https://github.com/Automattic/newspack-plugin/commit/bb7e534afb224a2c03888eb5e953a8fc27a10719))

# [3.1.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v3.0.3...v3.1.0-alpha.1) (2024-02-08)


### Bug Fixes

* **engagement-wizard:** handle error when retrieving subscription lists ([e85c108](https://github.com/Automattic/newspack-plugin/commit/e85c108ce6dd470a41ca9330fdbbdfbae4ed562c))
* **ras:** only sync spend total and last payment amounts for completed orders ([#2886](https://github.com/Automattic/newspack-plugin/issues/2886)) ([68aaf39](https://github.com/Automattic/newspack-plugin/commit/68aaf39fb744245cd91c9e041aa038e81c653b2a))
* redirect to origin from magic link ([9f41947](https://github.com/Automattic/newspack-plugin/commit/9f4194709502baeabbbf66233453795516f099c1))
* typescript errors ([dc27973](https://github.com/Automattic/newspack-plugin/commit/dc27973dedb0bc373a7c9e8877003845a6bf3adb))
* TypeScript usage; add to CI ([#2884](https://github.com/Automattic/newspack-plugin/issues/2884)) ([6f5e7a6](https://github.com/Automattic/newspack-plugin/commit/6f5e7a63594377eb9fa07589a08bb5dcbb96d043))
* update newsletter scroll appearance in Sign Up modal ([#2897](https://github.com/Automattic/newspack-plugin/issues/2897)) ([496723a](https://github.com/Automattic/newspack-plugin/commit/496723aa2e6519c803da615bbc494b22c57e6804))


### Features

* **ci:** add epic/* release workflow and rename `master` to `trunk` ([#2895](https://github.com/Automattic/newspack-plugin/issues/2895)) ([ea02075](https://github.com/Automattic/newspack-plugin/commit/ea020753c85e9ff881b08510effbcbe3b90b7b86)), closes [#2897](https://github.com/Automattic/newspack-plugin/issues/2897) [#2886](https://github.com/Automattic/newspack-plugin/issues/2886)
* **reader-revenue:** make NYP and Stripe Gateway optional ([#2866](https://github.com/Automattic/newspack-plugin/issues/2866)) ([fcfa88c](https://github.com/Automattic/newspack-plugin/commit/fcfa88c9d1b9053557b48e2cbd6172f58e0bac10))
* remove new tab default on image credits ([#2880](https://github.com/Automattic/newspack-plugin/issues/2880)) ([3c996b1](https://github.com/Automattic/newspack-plugin/commit/3c996b1fa7cae99675d455c446cfa425091f372f))
* **wc:** override cart, checkout, and my-account page templates ([#2893](https://github.com/Automattic/newspack-plugin/issues/2893)) ([68b1836](https://github.com/Automattic/newspack-plugin/commit/68b1836395e0b16536509c6e41bb1dfbc4de3571))
* **woocommerce:** fix order-pay page when using woocommerce-memberships-for-teams plugin ([#2923](https://github.com/Automattic/newspack-plugin/issues/2923)) ([e87e2dc](https://github.com/Automattic/newspack-plugin/commit/e87e2dc2192771e151dbc78c2b30a9c431a6a976))

## [3.0.3](https://github.com/Automattic/newspack-plugin/compare/v3.0.2...v3.0.3) (2024-02-08)


### Bug Fixes

* turn off WooCommerce's Order Attribution ([#2911](https://github.com/Automattic/newspack-plugin/issues/2911)) ([f3387e3](https://github.com/Automattic/newspack-plugin/commit/f3387e35df946ea48e49ac0b6553d7724cb20816))

## [3.0.2](https://github.com/Automattic/newspack-plugin/compare/v3.0.1...v3.0.2) (2024-02-07)


### Bug Fixes

* prevent fatal when redirecting for pixel tracking ([#2910](https://github.com/Automattic/newspack-plugin/issues/2910)) ([ef22a9b](https://github.com/Automattic/newspack-plugin/commit/ef22a9b5e236051ad8f25bd523157ce67c3d02cc))

## [3.0.1](https://github.com/Automattic/newspack-plugin/compare/v3.0.0...v3.0.1) (2024-02-06)


### Bug Fixes

* remove unneeded hook ([e863a3e](https://github.com/Automattic/newspack-plugin/commit/e863a3e2477df4eb7b05fe2b444dfc09d42285f7))


### Reverts

* Revert "feat: per-wizard capabilities (#2864)" ([353d9fd](https://github.com/Automattic/newspack-plugin/commit/353d9fd36191324981725f46eb52ac97e50c7aed)), closes [#2864](https://github.com/Automattic/newspack-plugin/issues/2864)

# [3.0.0](https://github.com/Automattic/newspack-plugin/compare/v2.16.1...v3.0.0) (2024-02-06)


### Bug Fixes

* assorted tweaks ([#2857](https://github.com/Automattic/newspack-plugin/issues/2857)) ([870bf48](https://github.com/Automattic/newspack-plugin/commit/870bf48c241bebb19f1cca977d44c2e35bc0b308))
* **auth-modal:** fatal error in auth modal with "other" ESP ([#2874](https://github.com/Automattic/newspack-plugin/issues/2874)) ([4addeec](https://github.com/Automattic/newspack-plugin/commit/4addeecc28e22b9dbfa729fb90eb2c811bf83369))
* fixes for prod release feb 5 ([#2905](https://github.com/Automattic/newspack-plugin/issues/2905)) ([1531ac3](https://github.com/Automattic/newspack-plugin/commit/1531ac3c38436c5021a10c99313bd513940258c8))
* make CLI comand more reliable ([#2855](https://github.com/Automattic/newspack-plugin/issues/2855)) ([bc5dd2c](https://github.com/Automattic/newspack-plugin/commit/bc5dd2c5395aefdeb09ff52a7181ef18072ce8d9))
* **ras:** consolidate RAS <> ESP data syncs in data event connectors ([#2821](https://github.com/Automattic/newspack-plugin/issues/2821)) ([76d73eb](https://github.com/Automattic/newspack-plugin/commit/76d73eb38196e3e9c2b957c7e0d1184ab88b9358))


### Features

* automatically fill the credit meta for images ([#2858](https://github.com/Automattic/newspack-plugin/issues/2858)) ([515dfc7](https://github.com/Automattic/newspack-plugin/commit/515dfc799fd147992d04441a2c6496ef58184ab2))
* deprecate Stripe Reader Revenue separate platform ([#2315](https://github.com/Automattic/newspack-plugin/issues/2315)) ([9db8e4b](https://github.com/Automattic/newspack-plugin/commit/9db8e4bc26a11181afc77c8bd538094fd02a4df7))
* **fivetran:** remove FiveTran connectors other than Stripe ([#2875](https://github.com/Automattic/newspack-plugin/issues/2875)) ([9ba4dbf](https://github.com/Automattic/newspack-plugin/commit/9ba4dbf28c1f0fb4d7ee1b3ab987d362ad679aed))
* **my-account:** require confirmation before cancelling subscription ([#2856](https://github.com/Automattic/newspack-plugin/issues/2856)) ([e970657](https://github.com/Automattic/newspack-plugin/commit/e970657f4254692a10ebc1212b8375eea4dc72d2))
* **optional-modules:** remove switching from standalone plugin versions ([08a74b9](https://github.com/Automattic/newspack-plugin/commit/08a74b982b54b45e0a24667caf22fb2697f7655e))
* per-wizard capabilities ([#2864](https://github.com/Automattic/newspack-plugin/issues/2864)) ([4de015e](https://github.com/Automattic/newspack-plugin/commit/4de015ec2aa474b3666e150269af85ab137ce863))
* **ras:** better reader display/nice names ([#2845](https://github.com/Automattic/newspack-plugin/issues/2845)) ([92ce455](https://github.com/Automattic/newspack-plugin/commit/92ce455186b85327e453b46eb63deaae610f6def))
* **webhooks:** endpoint authentication bearer token ([#2831](https://github.com/Automattic/newspack-plugin/issues/2831)) ([9df0b39](https://github.com/Automattic/newspack-plugin/commit/9df0b392ed0f9ccc7bd9ac46ab80557ccb959e95))


### BREAKING CHANGES

* Stripe will no longer be used to process recurring payments.

# [3.0.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v3.0.0-alpha.1...v3.0.0-alpha.2) (2024-02-06)


### Bug Fixes

* fixes for prod release feb 5 ([#2905](https://github.com/Automattic/newspack-plugin/issues/2905)) ([1531ac3](https://github.com/Automattic/newspack-plugin/commit/1531ac3c38436c5021a10c99313bd513940258c8))

# [3.0.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v2.16.1...v3.0.0-alpha.1) (2024-01-26)


### Bug Fixes

* assorted tweaks ([#2857](https://github.com/Automattic/newspack-plugin/issues/2857)) ([870bf48](https://github.com/Automattic/newspack-plugin/commit/870bf48c241bebb19f1cca977d44c2e35bc0b308))
* **auth-modal:** fatal error in auth modal with "other" ESP ([#2874](https://github.com/Automattic/newspack-plugin/issues/2874)) ([4addeec](https://github.com/Automattic/newspack-plugin/commit/4addeecc28e22b9dbfa729fb90eb2c811bf83369))
* make CLI comand more reliable ([#2855](https://github.com/Automattic/newspack-plugin/issues/2855)) ([bc5dd2c](https://github.com/Automattic/newspack-plugin/commit/bc5dd2c5395aefdeb09ff52a7181ef18072ce8d9))
* **ras:** consolidate RAS <> ESP data syncs in data event connectors ([#2821](https://github.com/Automattic/newspack-plugin/issues/2821)) ([76d73eb](https://github.com/Automattic/newspack-plugin/commit/76d73eb38196e3e9c2b957c7e0d1184ab88b9358))


### Features

* automatically fill the credit meta for images ([#2858](https://github.com/Automattic/newspack-plugin/issues/2858)) ([515dfc7](https://github.com/Automattic/newspack-plugin/commit/515dfc799fd147992d04441a2c6496ef58184ab2))
* deprecate Stripe Reader Revenue separate platform ([#2315](https://github.com/Automattic/newspack-plugin/issues/2315)) ([9db8e4b](https://github.com/Automattic/newspack-plugin/commit/9db8e4bc26a11181afc77c8bd538094fd02a4df7))
* **fivetran:** remove FiveTran connectors other than Stripe ([#2875](https://github.com/Automattic/newspack-plugin/issues/2875)) ([9ba4dbf](https://github.com/Automattic/newspack-plugin/commit/9ba4dbf28c1f0fb4d7ee1b3ab987d362ad679aed))
* **my-account:** require confirmation before cancelling subscription ([#2856](https://github.com/Automattic/newspack-plugin/issues/2856)) ([e970657](https://github.com/Automattic/newspack-plugin/commit/e970657f4254692a10ebc1212b8375eea4dc72d2))
* **optional-modules:** remove switching from standalone plugin versions ([08a74b9](https://github.com/Automattic/newspack-plugin/commit/08a74b982b54b45e0a24667caf22fb2697f7655e))
* per-wizard capabilities ([#2864](https://github.com/Automattic/newspack-plugin/issues/2864)) ([4de015e](https://github.com/Automattic/newspack-plugin/commit/4de015ec2aa474b3666e150269af85ab137ce863))
* **ras:** better reader display/nice names ([#2845](https://github.com/Automattic/newspack-plugin/issues/2845)) ([92ce455](https://github.com/Automattic/newspack-plugin/commit/92ce455186b85327e453b46eb63deaae610f6def))
* **webhooks:** endpoint authentication bearer token ([#2831](https://github.com/Automattic/newspack-plugin/issues/2831)) ([9df0b39](https://github.com/Automattic/newspack-plugin/commit/9df0b392ed0f9ccc7bd9ac46ab80557ccb959e95))


### BREAKING CHANGES

* Stripe will no longer be used to process recurring payments.

## [2.16.1](https://github.com/Automattic/newspack-plugin/compare/v2.16.0...v2.16.1) (2024-01-26)


### Bug Fixes

* ensure WC session is present if triggering WC notice ([3857c56](https://github.com/Automattic/newspack-plugin/commit/3857c564a4e9bf50c61851a24328e5975b1edd6e))

# [2.16.0](https://github.com/Automattic/newspack-plugin/compare/v2.15.0...v2.16.0) (2024-01-25)


### Bug Fixes

* **connections-wizard:** error display ([7b20899](https://github.com/Automattic/newspack-plugin/commit/7b208997a3480455ad06c41983d7c9cb706b96f7))
* prevent switch order synchronisation ([f08295f](https://github.com/Automattic/newspack-plugin/commit/f08295f608d2ae2b287bddf9b1b50b2820e633a4))
* **ras:** handle invalid order sync request ([#2865](https://github.com/Automattic/newspack-plugin/issues/2865)) ([38f265f](https://github.com/Automattic/newspack-plugin/commit/38f265feccfdba3ef7916a820ffcd5eba0ebe917))


### Features

* add support for revisions to content gate CPT ([#2812](https://github.com/Automattic/newspack-plugin/issues/2812)) ([87bd78b](https://github.com/Automattic/newspack-plugin/commit/87bd78b7bbf695bd14d5ff5ea3b0be8ff6fea815))
* approved plugins list retrieval method ([ea79dce](https://github.com/Automattic/newspack-plugin/commit/ea79dce0a67db8f5a8814aa0f09c0548f3562184))
* **supported-plugins:** add Newspack Network & Multibranded Site ([#2824](https://github.com/Automattic/newspack-plugin/issues/2824)) ([857ebae](https://github.com/Automattic/newspack-plugin/commit/857ebae0d45a07ae4f3cbe97c54caa6f774a059a))

# [2.16.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v2.15.0...v2.16.0-alpha.1) (2024-01-23)


### Bug Fixes

* **connections-wizard:** error display ([7b20899](https://github.com/Automattic/newspack-plugin/commit/7b208997a3480455ad06c41983d7c9cb706b96f7))
* prevent switch order synchronisation ([f08295f](https://github.com/Automattic/newspack-plugin/commit/f08295f608d2ae2b287bddf9b1b50b2820e633a4))
* **ras:** handle invalid order sync request ([#2865](https://github.com/Automattic/newspack-plugin/issues/2865)) ([38f265f](https://github.com/Automattic/newspack-plugin/commit/38f265feccfdba3ef7916a820ffcd5eba0ebe917))


### Features

* add support for revisions to content gate CPT ([#2812](https://github.com/Automattic/newspack-plugin/issues/2812)) ([87bd78b](https://github.com/Automattic/newspack-plugin/commit/87bd78b7bbf695bd14d5ff5ea3b0be8ff6fea815))
* approved plugins list retrieval method ([ea79dce](https://github.com/Automattic/newspack-plugin/commit/ea79dce0a67db8f5a8814aa0f09c0548f3562184))
* **supported-plugins:** add Newspack Network & Multibranded Site ([#2824](https://github.com/Automattic/newspack-plugin/issues/2824)) ([857ebae](https://github.com/Automattic/newspack-plugin/commit/857ebae0d45a07ae4f3cbe97c54caa6f774a059a))

# [2.15.0](https://github.com/Automattic/newspack-plugin/compare/v2.14.1...v2.15.0) (2024-01-23)


### Features

* add get_recurrence_of_order method ([3232c70](https://github.com/Automattic/newspack-plugin/commit/3232c70d2a5cb3b38f9aa3de55d3e66e3e4464c8))

## [2.14.1](https://github.com/Automattic/newspack-plugin/compare/v2.14.0...v2.14.1) (2024-01-15)


### Bug Fixes

* **plugins:** incorrect plugin slug for Distributor ([#2846](https://github.com/Automattic/newspack-plugin/issues/2846)) ([ba6dc97](https://github.com/Automattic/newspack-plugin/commit/ba6dc97f84bb6d2bc1fd6326d0d65c9e4bf2946c))

# [2.14.0](https://github.com/Automattic/newspack-plugin/compare/v2.13.0...v2.14.0) (2024-01-15)


### Features

* add cli command to fix stripe covered fees ([#2848](https://github.com/Automattic/newspack-plugin/issues/2848)) ([3f5874f](https://github.com/Automattic/newspack-plugin/commit/3f5874fe579f9a53953fd348188036801cea1b8e))

# [2.13.0](https://github.com/Automattic/newspack-plugin/compare/v2.12.2...v2.13.0) (2024-01-08)


### Bug Fixes

* alpha fixes ([#2835](https://github.com/Automattic/newspack-plugin/issues/2835)) ([49efd8b](https://github.com/Automattic/newspack-plugin/commit/49efd8b4643112f14e29a99f528345eae5b2c830))
* newsletters Lists indentation ([#2797](https://github.com/Automattic/newspack-plugin/issues/2797)) ([1af3c23](https://github.com/Automattic/newspack-plugin/commit/1af3c230f4747ff3b8fd7ad13989a8d1db015776))
* **ras:** remove modal checkout logic ([#2781](https://github.com/Automattic/newspack-plugin/issues/2781)) ([6aad17d](https://github.com/Automattic/newspack-plugin/commit/6aad17d54712d1878d559503112980a737667b6e))
* use Woo's cart fee for covering transaction fees ([#2820](https://github.com/Automattic/newspack-plugin/issues/2820)) ([fded027](https://github.com/Automattic/newspack-plugin/commit/fded02763314c386907d677d9359ef41cb85789b))


### Features

* add media partners module ([#2753](https://github.com/Automattic/newspack-plugin/issues/2753)) ([70f7dcb](https://github.com/Automattic/newspack-plugin/commit/70f7dcbeba33e825655caf0805c2092eef0ecb07))
* don’t send OTP via preauth flow when signing up for newsletter ([#2795](https://github.com/Automattic/newspack-plugin/issues/2795)) ([686af03](https://github.com/Automattic/newspack-plugin/commit/686af034ec7fad95109b5d6341fb0115f031dfa6))
* **donation:** additional receipt email template variables ([#2799](https://github.com/Automattic/newspack-plugin/issues/2799)) ([0c9c373](https://github.com/Automattic/newspack-plugin/commit/0c9c37381ca5fad1b0e46fc3c55f0c93c1436f46))
* force allow subscription switching ([#2784](https://github.com/Automattic/newspack-plugin/issues/2784)) ([ae7523f](https://github.com/Automattic/newspack-plugin/commit/ae7523f9fd14702de8c53d1726c7865e350ed67e))
* force option to enble retries of failed payments ([#2808](https://github.com/Automattic/newspack-plugin/issues/2808)) ([f8d35ec](https://github.com/Automattic/newspack-plugin/commit/f8d35ec6b092a2078a742a0ee04926801ef74fdc))
* give editors permission for restricted content ([#2806](https://github.com/Automattic/newspack-plugin/issues/2806)) ([64d7817](https://github.com/Automattic/newspack-plugin/commit/64d781741e5a9806afd6597bf1d5a4656da9e256))
* **my-account:** support edit address ([#2733](https://github.com/Automattic/newspack-plugin/issues/2733)) ([92d5778](https://github.com/Automattic/newspack-plugin/commit/92d577894a6118c2894c21fb8bfad8d9b8c897d8))
* remove commenting from engagement tab ([#2726](https://github.com/Automattic/newspack-plugin/issues/2726)) ([f51c7bc](https://github.com/Automattic/newspack-plugin/commit/f51c7bc9205b7f3d537591de5b63ac1a90433b3c))

# [2.13.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v2.13.0-alpha.2...v2.13.0-alpha.3) (2024-01-04)


### Bug Fixes

* **cover-fees:** save order after setting total ([7bf58e9](https://github.com/Automattic/newspack-plugin/commit/7bf58e9bb142007521f748ab04d2a49b62bb8641))

## [2.12.2](https://github.com/Automattic/newspack-plugin/compare/v2.12.1...v2.12.2) (2024-01-04)


### Bug Fixes

* **cover-fees:** save order after setting total ([7bf58e9](https://github.com/Automattic/newspack-plugin/commit/7bf58e9bb142007521f748ab04d2a49b62bb8641))

## [2.12.1](https://github.com/Automattic/newspack-plugin/compare/v2.12.0...v2.12.1) (2023-12-12)


### Bug Fixes

* **ras:** handle UTM metadata field keys with arbitrary suffixes ([#2804](https://github.com/Automattic/newspack-plugin/issues/2804)) ([98e4694](https://github.com/Automattic/newspack-plugin/commit/98e469465be828fa0a0dc0f1bd7f7be09617a5af))

# [2.12.0](https://github.com/Automattic/newspack-plugin/compare/v2.11.6...v2.12.0) (2023-12-11)


### Bug Fixes

* **checkout:** move stripe's cover fee placement ([#2767](https://github.com/Automattic/newspack-plugin/issues/2767)) ([5f8b539](https://github.com/Automattic/newspack-plugin/commit/5f8b53928ed712853aba1d1838222f8271eb99fc))
* **data-events:** no longer use ActionScheduler for dispatches ([#2755](https://github.com/Automattic/newspack-plugin/issues/2755)) ([975ab96](https://github.com/Automattic/newspack-plugin/commit/975ab96032694ff5f5c25b6f0f72f25f3cdb9ca9))
* **metering:** restrict comments on gated content ([#2751](https://github.com/Automattic/newspack-plugin/issues/2751)) ([1bfc6f0](https://github.com/Automattic/newspack-plugin/commit/1bfc6f0cbdbb8a64fcf252a85c427a30e203b097))
* **recaptcha:** refresh token on checkout error ([#2769](https://github.com/Automattic/newspack-plugin/issues/2769)) ([f22e8bd](https://github.com/Automattic/newspack-plugin/commit/f22e8bd0ce51b5795f9e2a6d14b2ee59496deeef))


### Features

* add filters for assets enqueueing ([#2768](https://github.com/Automattic/newspack-plugin/issues/2768)) ([fcad059](https://github.com/Automattic/newspack-plugin/commit/fcad059abbe0314ba8a6f3eaaf92d5c96350e248))
* **authentication:** rate limit magic links and OTP generation ([#2765](https://github.com/Automattic/newspack-plugin/issues/2765)) ([1252515](https://github.com/Automattic/newspack-plugin/commit/12525151a369d3c1785bad5b3edf5a6a5eb5cfcf))
* **campaigns:** mark duplicate segments ([cb5b527](https://github.com/Automattic/newspack-plugin/commit/cb5b5273a52546a5478d258859d893491f1e1311))
* **data-events:** track content gate interactions ([#2740](https://github.com/Automattic/newspack-plugin/issues/2740)) ([298fd7c](https://github.com/Automattic/newspack-plugin/commit/298fd7c958450cebae8803b334394eea63de501b))
* **donations:** disable coupons for donation checkout ([#2770](https://github.com/Automattic/newspack-plugin/issues/2770)) ([6051429](https://github.com/Automattic/newspack-plugin/commit/60514291a895d0c96ca9483cdc1dedb890f0c510))

# [2.12.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v2.12.0-alpha.3...v2.12.0-alpha.4) (2023-12-05)


### Bug Fixes

* **mailchimp:** prevent merge fields fetch throwing fatal error ([#2793](https://github.com/Automattic/newspack-plugin/issues/2793)) ([9b9d3ab](https://github.com/Automattic/newspack-plugin/commit/9b9d3abf9190fe195825b77bc33be92a602c395a))

## [2.11.6](https://github.com/Automattic/newspack-plugin/compare/v2.11.5...v2.11.6) (2023-12-05)


### Bug Fixes

* **mailchimp:** prevent merge fields fetch throwing fatal error ([#2793](https://github.com/Automattic/newspack-plugin/issues/2793)) ([9b9d3ab](https://github.com/Automattic/newspack-plugin/commit/9b9d3abf9190fe195825b77bc33be92a602c395a))

## [2.11.5](https://github.com/Automattic/newspack-plugin/compare/v2.11.4...v2.11.5) (2023-12-04)


### Bug Fixes

* more fixes for Mailchimp RAS data sync ([#2780](https://github.com/Automattic/newspack-plugin/issues/2780)) ([ef4bfe7](https://github.com/Automattic/newspack-plugin/commit/ef4bfe754ebd101b483f6b934b7acd60ef778770))

## [2.11.4](https://github.com/Automattic/newspack-plugin/compare/v2.11.3...v2.11.4) (2023-12-01)


### Bug Fixes

* add a filter to the lists offered on register ([04192f5](https://github.com/Automattic/newspack-plugin/commit/04192f5bba185ee62269e597689db3df037fb73b))

## [2.11.3](https://github.com/Automattic/newspack-plugin/compare/v2.11.2...v2.11.3) (2023-11-28)


### Bug Fixes

* don’t sync empty contact fields to ESP ([#2779](https://github.com/Automattic/newspack-plugin/issues/2779)) ([f0866bd](https://github.com/Automattic/newspack-plugin/commit/f0866bdbec04dd95b0a06a907fec31aff0ab4142))

## [2.11.2](https://github.com/Automattic/newspack-plugin/compare/v2.11.1...v2.11.2) (2023-11-27)


### Bug Fixes

* **auth-modal:** handle very long newsletter lists list ([ad6895e](https://github.com/Automattic/newspack-plugin/commit/ad6895e03dbde96ecf7dd4c09dca85bba6ad3c50))
* trigger alpha release ([fc88a05](https://github.com/Automattic/newspack-plugin/commit/fc88a05462865d3d008670ac608363bd4b481bc2))

## [2.11.2-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v2.11.2-alpha.1...v2.11.2-alpha.2) (2023-11-21)


### Bug Fixes

* trigger alpha release ([fc88a05](https://github.com/Automattic/newspack-plugin/commit/fc88a05462865d3d008670ac608363bd4b481bc2))

## [2.11.2-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v2.11.1...v2.11.2-alpha.1) (2023-11-20)


### Bug Fixes

* **auth-modal:** handle very long newsletter lists list ([ad6895e](https://github.com/Automattic/newspack-plugin/commit/ad6895e03dbde96ecf7dd4c09dca85bba6ad3c50))

## [2.11.1](https://github.com/Automattic/newspack-plugin/compare/v2.11.0...v2.11.1) (2023-11-20)


### Bug Fixes

* don't sync non-donation purchases to the ESP ([#2758](https://github.com/Automattic/newspack-plugin/issues/2758)) ([1e483ae](https://github.com/Automattic/newspack-plugin/commit/1e483ae333da4538564d54c0ff395899beefa665))

# [2.11.0](https://github.com/Automattic/newspack-plugin/compare/v2.10.3...v2.11.0) (2023-11-13)


### Bug Fixes

* get subscriptions from any order type ([#2744](https://github.com/Automattic/newspack-plugin/issues/2744)) ([5f0bef3](https://github.com/Automattic/newspack-plugin/commit/5f0bef3cc39f60ca1df96dd2644076223e27ff8a))


### Features

* add donation processing filters ([#2720](https://github.com/Automattic/newspack-plugin/issues/2720)) ([d041fa8](https://github.com/Automattic/newspack-plugin/commit/d041fa80a3916f384d2e598c3eda545fe791aece))
* **donations:** process after-checkout-button params ([167a20e](https://github.com/Automattic/newspack-plugin/commit/167a20e500c565a7e549285d723741ae511dd332))
* **emails:** support billing name on receipt email ([#2729](https://github.com/Automattic/newspack-plugin/issues/2729)) ([c9ad368](https://github.com/Automattic/newspack-plugin/commit/c9ad3684f072dce3d831ca477f35c51aa66ec8e8))
* **modal-checkout:** allow covering Stripe's fees  ([#2695](https://github.com/Automattic/newspack-plugin/issues/2695)) ([420ca32](https://github.com/Automattic/newspack-plugin/commit/420ca32ce675fc976961591164963b73882e9644))
* new options for Stripe fees ([#2745](https://github.com/Automattic/newspack-plugin/issues/2745)) ([c974352](https://github.com/Automattic/newspack-plugin/commit/c974352a0a9da2387b3e1e3e1cbc76e7879b201a))
* **recaptcha:** make recaptcha threshold configurable ([#2705](https://github.com/Automattic/newspack-plugin/issues/2705)) ([f3ec1a6](https://github.com/Automattic/newspack-plugin/commit/f3ec1a6fae04201014d7880328b4e3413758d47a))
* remove MC4WP from managed plugins ([#2723](https://github.com/Automattic/newspack-plugin/issues/2723)) ([8026688](https://github.com/Automattic/newspack-plugin/commit/80266880dda048ad8e580fd789f43d2cf95a079c))
* segmentation criteria for subscriptions and memberships ([#2696](https://github.com/Automattic/newspack-plugin/issues/2696)) ([4631b51](https://github.com/Automattic/newspack-plugin/commit/4631b51e443fd8b3cd78d14ecd800194e25ac2bc))
* **stripe-migration:** support mapping of products by SKU ([#2725](https://github.com/Automattic/newspack-plugin/issues/2725)) ([08f9569](https://github.com/Automattic/newspack-plugin/commit/08f95691e7facda5555f8003b19dbeb66fb2e276))

# [2.11.0-alpha.8](https://github.com/Automattic/newspack-plugin/compare/v2.11.0-alpha.7...v2.11.0-alpha.8) (2023-11-13)


### Features

* new options for Stripe fees ([#2745](https://github.com/Automattic/newspack-plugin/issues/2745)) ([c974352](https://github.com/Automattic/newspack-plugin/commit/c974352a0a9da2387b3e1e3e1cbc76e7879b201a))

# [2.11.0-alpha.7](https://github.com/Automattic/newspack-plugin/compare/v2.11.0-alpha.6...v2.11.0-alpha.7) (2023-11-09)


### Bug Fixes

* get subscriptions from any order type ([#2744](https://github.com/Automattic/newspack-plugin/issues/2744)) ([5f0bef3](https://github.com/Automattic/newspack-plugin/commit/5f0bef3cc39f60ca1df96dd2644076223e27ff8a))

# [2.11.0-alpha.6](https://github.com/Automattic/newspack-plugin/compare/v2.11.0-alpha.5...v2.11.0-alpha.6) (2023-11-07)


### Bug Fixes

* **password-reset:** redirect non-readers to WP login ([#2742](https://github.com/Automattic/newspack-plugin/issues/2742)) ([0645073](https://github.com/Automattic/newspack-plugin/commit/0645073ae0ea3dcc63835ae53eaba0e88a742e4c))

## [2.10.3](https://github.com/Automattic/newspack-plugin/compare/v2.10.2...v2.10.3) (2023-11-07)


### Bug Fixes

* **password-reset:** redirect non-readers to WP login ([#2742](https://github.com/Automattic/newspack-plugin/issues/2742)) ([0645073](https://github.com/Automattic/newspack-plugin/commit/0645073ae0ea3dcc63835ae53eaba0e88a742e4c))

## [2.10.2](https://github.com/Automattic/newspack-plugin/compare/v2.10.1...v2.10.2) (2023-11-06)


### Bug Fixes

* restore method used by migration scripts ([#2741](https://github.com/Automattic/newspack-plugin/issues/2741)) ([d8d0860](https://github.com/Automattic/newspack-plugin/commit/d8d0860f294e79a48f3ce003e08cdc3675ef0682))

## [2.10.1](https://github.com/Automattic/newspack-plugin/compare/v2.10.0...v2.10.1) (2023-11-06)


### Bug Fixes

* sync order to ac after payment ([#2731](https://github.com/Automattic/newspack-plugin/issues/2731)) ([8960127](https://github.com/Automattic/newspack-plugin/commit/8960127580b68f1950dab5750585001a38e401b6))

# [2.10.0](https://github.com/Automattic/newspack-plugin/compare/v2.9.1...v2.10.0) (2023-10-31)


### Features

* add Perfmatters to managed plugins ([#2684](https://github.com/Automattic/newspack-plugin/issues/2684)) ([eb657d8](https://github.com/Automattic/newspack-plugin/commit/eb657d801d4d07cee2b4844da827ecb9953210c4))
* **data-events:** activecampaign connector ([#2663](https://github.com/Automattic/newspack-plugin/issues/2663)) ([377a51f](https://github.com/Automattic/newspack-plugin/commit/377a51fbe6dcb51484f88ea5dedc710769e8511c))
* **design-wizard:** allow bulk update of featured image, post template ([#2670](https://github.com/Automattic/newspack-plugin/issues/2670)) ([37a4656](https://github.com/Automattic/newspack-plugin/commit/37a46562a215afb92b7a2a24a628770c5ab2b4ee))
* newspack_managed_plugins filter ([#2605](https://github.com/Automattic/newspack-plugin/issues/2605)) ([81ba5f2](https://github.com/Automattic/newspack-plugin/commit/81ba5f2c154c60f542adf8c67dfe3b5e1def95f3))
* **ras:** custom newsletter list selection on registration ([#2706](https://github.com/Automattic/newspack-plugin/issues/2706)) ([57b2871](https://github.com/Automattic/newspack-plugin/commit/57b2871c7d3261e7cddd97e32fdf7c4102e868c1))
* **ras:** overlay management for content gate refresh ([#2708](https://github.com/Automattic/newspack-plugin/issues/2708)) ([6767740](https://github.com/Automattic/newspack-plugin/commit/67677406213737d38127881e75376bdc5eb10deb))
* **reader-registration:** custom checkbox state for lists ([#2682](https://github.com/Automattic/newspack-plugin/issues/2682)) ([b8ce865](https://github.com/Automattic/newspack-plugin/commit/b8ce8653a864db97b573a92444c0231bbb8239c9))
* remove order-received text ([#2707](https://github.com/Automattic/newspack-plugin/issues/2707)) ([a386524](https://github.com/Automattic/newspack-plugin/commit/a386524274a8f3736384e9435737cb8678fe6105))

# [2.10.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v2.10.0-alpha.2...v2.10.0-alpha.3) (2023-10-26)


### Bug Fixes

* **perfmatters:** force stylesheet exclusion in unused CSS feature ([f0c291f](https://github.com/Automattic/newspack-plugin/commit/f0c291f6900464eb5a5ce412217d39bd60e8828f))

## [2.9.1](https://github.com/Automattic/newspack-plugin/compare/v2.9.0...v2.9.1) (2023-10-26)


### Bug Fixes

* **perfmatters:** force stylesheet exclusion in unused CSS feature ([f0c291f](https://github.com/Automattic/newspack-plugin/commit/f0c291f6900464eb5a5ce412217d39bd60e8828f))

# [2.9.0](https://github.com/Automattic/newspack-plugin/compare/v2.8.3...v2.9.0) (2023-10-17)


### Features

* **reader-data:** set newsletter subscriber status on form submission ([#2703](https://github.com/Automattic/newspack-plugin/issues/2703)) ([35c13cc](https://github.com/Automattic/newspack-plugin/commit/35c13cc565074364ca7b2e33d88eae7aaaf970f3))

## [2.8.3](https://github.com/Automattic/newspack-plugin/compare/v2.8.2...v2.8.3) (2023-10-11)


### Bug Fixes

* update support link to help site ([#2693](https://github.com/Automattic/newspack-plugin/issues/2693)) ([1ab741d](https://github.com/Automattic/newspack-plugin/commit/1ab741dfddbfdfd541496167e443f979bcbb1060))

## [2.8.2](https://github.com/Automattic/newspack-plugin/compare/v2.8.1...v2.8.2) (2023-10-10)


### Bug Fixes

* **media-credit:** use post ids selection for search query ([#2692](https://github.com/Automattic/newspack-plugin/issues/2692)) ([6c9fa3e](https://github.com/Automattic/newspack-plugin/commit/6c9fa3ee3ebc498c52f0fd4accef0b9db3de9a5f))

## [2.8.1](https://github.com/Automattic/newspack-plugin/compare/v2.8.0...v2.8.1) (2023-10-10)


### Bug Fixes

* **media-credit:** refactor clauses for credit search ([#2691](https://github.com/Automattic/newspack-plugin/issues/2691)) ([6547fe1](https://github.com/Automattic/newspack-plugin/commit/6547fe1989cf39814827840b95e678f16ad0eb2d))

# [2.8.0](https://github.com/Automattic/newspack-plugin/compare/v2.7.5...v2.8.0) (2023-10-09)


### Bug Fixes

* inline documentation for `Donations::get_donation_settings()` ([#2675](https://github.com/Automattic/newspack-plugin/issues/2675)) ([c6c7916](https://github.com/Automattic/newspack-plugin/commit/c6c79165b1579739b6428b612b5e141903656c88))
* **woocommerce:** check method exists before syncing reader ([#2673](https://github.com/Automattic/newspack-plugin/issues/2673)) ([063509c](https://github.com/Automattic/newspack-plugin/commit/063509c12a322f72818c21d3cb10e4b20f5ab243))
* **woocommerce:** hook for rendering UTM inputs ([#2671](https://github.com/Automattic/newspack-plugin/issues/2671)) ([634f7bf](https://github.com/Automattic/newspack-plugin/commit/634f7bfd8b9b96e572151a5aa2554e9a6098fc0c))


### Features

* add RAS CLI commands ([#2666](https://github.com/Automattic/newspack-plugin/issues/2666)) ([5afc049](https://github.com/Automattic/newspack-plugin/commit/5afc04934077661e78fe56c0d006868e1364d447))
* **campaigns:** newsletter subscription list criteria ([#2658](https://github.com/Automattic/newspack-plugin/issues/2658)) ([69c8f88](https://github.com/Automattic/newspack-plugin/commit/69c8f88e794a52952ddbb7f9ef519aabb82558ed))
* remove unused GA code ([#2664](https://github.com/Automattic/newspack-plugin/issues/2664)) ([1fa99fa](https://github.com/Automattic/newspack-plugin/commit/1fa99fa6f0833416b71ef864eaedb3488d57c6c8))
* search credit metadata on media library ([#2594](https://github.com/Automattic/newspack-plugin/issues/2594)) ([c33c5b2](https://github.com/Automattic/newspack-plugin/commit/c33c5b2a83bc12777aeac66a13ea38b4d13c19d9))
* **woocommerce:** store UTM parameters as order meta ([#2665](https://github.com/Automattic/newspack-plugin/issues/2665)) ([39dfc31](https://github.com/Automattic/newspack-plugin/commit/39dfc31de6795fa91cfe79f5505b1eecd651c9ad))

# [2.8.0-alpha.7](https://github.com/Automattic/newspack-plugin/compare/v2.8.0-alpha.6...v2.8.0-alpha.7) (2023-10-05)


### Bug Fixes

* **data-events:** earlier priority for webhook registration ([#2683](https://github.com/Automattic/newspack-plugin/issues/2683)) ([3467434](https://github.com/Automattic/newspack-plugin/commit/3467434cf6008326766b01c3f777ef2d1c23cd3e))

## [2.7.5](https://github.com/Automattic/newspack-plugin/compare/v2.7.4...v2.7.5) (2023-10-05)


### Bug Fixes

* **data-events:** earlier priority for webhook registration ([#2683](https://github.com/Automattic/newspack-plugin/issues/2683)) ([3467434](https://github.com/Automattic/newspack-plugin/commit/3467434cf6008326766b01c3f777ef2d1c23cd3e))

## [2.7.4](https://github.com/Automattic/newspack-plugin/compare/v2.7.3...v2.7.4) (2023-10-02)


### Bug Fixes

* avoid deepMapKeys NPM package ([#2680](https://github.com/Automattic/newspack-plugin/issues/2680)) ([60b9828](https://github.com/Automattic/newspack-plugin/commit/60b9828f5ae41bc11763b4867f44000a4394d1c6))

## [2.7.3](https://github.com/Automattic/newspack-plugin/compare/v2.7.2...v2.7.3) (2023-09-29)


### Bug Fixes

* remove honeypot field if using reCAPTCHA ([#2667](https://github.com/Automattic/newspack-plugin/issues/2667)) ([9c6c325](https://github.com/Automattic/newspack-plugin/commit/9c6c3259c7638c917790705b6d76bbfdc9ebcb50))

## [2.7.2](https://github.com/Automattic/newspack-plugin/compare/v2.7.1...v2.7.2) (2023-09-28)


### Bug Fixes

* **ads:** stick to top toggle ([#2669](https://github.com/Automattic/newspack-plugin/issues/2669)) ([4085ba7](https://github.com/Automattic/newspack-plugin/commit/4085ba7309369481cf242962c086514683d2eb52))

## [2.7.1](https://github.com/Automattic/newspack-plugin/compare/v2.7.0...v2.7.1) (2023-09-28)


### Bug Fixes

* **perfmatters:** handling false values when applying defaults ([7169d5d](https://github.com/Automattic/newspack-plugin/commit/7169d5dffd531bde6b2d46ca16d2a55aa61de856))

# [2.7.0](https://github.com/Automattic/newspack-plugin/compare/v2.6.4...v2.7.0) (2023-09-25)


### Bug Fixes

* partial text domain update for the newspack plugin ([#2646](https://github.com/Automattic/newspack-plugin/issues/2646)) ([7d92092](https://github.com/Automattic/newspack-plugin/commit/7d92092340e6559e0676e42b42561546e43550ef))


### Features

* add ads.txt manager to plugin manager ([#2639](https://github.com/Automattic/newspack-plugin/issues/2639)) ([06eccaa](https://github.com/Automattic/newspack-plugin/commit/06eccaa75daaafa915df03f5758e776ba763c1a0))
* **amp-deprecation:** remove AMP from supported list ([#2647](https://github.com/Automattic/newspack-plugin/issues/2647)) ([ef98476](https://github.com/Automattic/newspack-plugin/commit/ef98476e834f672504d40f554411e647af3b1707))
* disable deactivate and delete for Akismet ([#2593](https://github.com/Automattic/newspack-plugin/issues/2593)) ([136752a](https://github.com/Automattic/newspack-plugin/commit/136752a6f86c7d8de6d77921ddd841380405a231))
* replace recommended cookie plugin ([#2223](https://github.com/Automattic/newspack-plugin/issues/2223)) ([4a309be](https://github.com/Automattic/newspack-plugin/commit/4a309be2141a18333f6ce3c2346450224c555028))

# [2.7.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v2.7.0-alpha.3...v2.7.0-alpha.4) (2023-09-22)


### Bug Fixes

* remove Stripe migration scripts ([#2657](https://github.com/Automattic/newspack-plugin/issues/2657)) ([13ad632](https://github.com/Automattic/newspack-plugin/commit/13ad63274c5d4a80a23f25306b5e4ce9c35f3141))

## [2.6.4](https://github.com/Automattic/newspack-plugin/compare/v2.6.3...v2.6.4) (2023-09-22)


### Bug Fixes

* remove Stripe migration scripts ([#2657](https://github.com/Automattic/newspack-plugin/issues/2657)) ([13ad632](https://github.com/Automattic/newspack-plugin/commit/13ad63274c5d4a80a23f25306b5e4ce9c35f3141))

## [2.6.3](https://github.com/Automattic/newspack-plugin/compare/v2.6.2...v2.6.3) (2023-09-21)


### Bug Fixes

* allow set-next-payment-dates-for-migrated-subscriptions to reset past dates ([#2644](https://github.com/Automattic/newspack-plugin/issues/2644)) ([72f147e](https://github.com/Automattic/newspack-plugin/commit/72f147e5f480e395eead1b12ede84c5a2d968d8c))

## [2.6.2](https://github.com/Automattic/newspack-plugin/compare/v2.6.1...v2.6.2) (2023-09-20)


### Bug Fixes

* partial text domain update for the newspack plugin ([#2646](https://github.com/Automattic/newspack-plugin/issues/2646)) ([#2654](https://github.com/Automattic/newspack-plugin/issues/2654)) ([3b5b713](https://github.com/Automattic/newspack-plugin/commit/3b5b713e06b0e8a7abd7d92688fd25402e8c44b4))

## [2.6.1](https://github.com/Automattic/newspack-plugin/compare/v2.6.0...v2.6.1) (2023-09-15)


### Bug Fixes

* restore audience selector for mailchimp data sync ([#2645](https://github.com/Automattic/newspack-plugin/issues/2645)) ([8ce676c](https://github.com/Automattic/newspack-plugin/commit/8ce676c0ca8d74c1d4464ad3af15b88e2e7ffd30))

# [2.6.0](https://github.com/Automattic/newspack-plugin/compare/v2.5.1...v2.6.0) (2023-09-13)


### Bug Fixes

* sync scripts for Stripe-to-Woo migrations ([#2632](https://github.com/Automattic/newspack-plugin/issues/2632)) ([34beb15](https://github.com/Automattic/newspack-plugin/commit/34beb159d4d8a5eb2ce2c6a931989708752a59fd))


### Features

* add anonymized email to ga4 events ([#2585](https://github.com/Automattic/newspack-plugin/issues/2585)) ([9ac1c33](https://github.com/Automattic/newspack-plugin/commit/9ac1c3378f188794de63c9947ca7e46f5dd8644c))
* add filter to the lists in the reg block ([#2611](https://github.com/Automattic/newspack-plugin/issues/2611)) ([d960541](https://github.com/Automattic/newspack-plugin/commit/d96054167c428f0d7f3a36e99f7c1b3fd1c3bbd3))
* new option to require all membership plans for restricted content ([#2623](https://github.com/Automattic/newspack-plugin/issues/2623)) ([60b361e](https://github.com/Automattic/newspack-plugin/commit/60b361e78b86a77ab2502a91a6d1bcbc9218e533))

# [2.6.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v2.6.0-alpha.1...v2.6.0-alpha.2) (2023-09-11)


### Bug Fixes

* sync scripts for Stripe-to-Woo migrations ([#2632](https://github.com/Automattic/newspack-plugin/issues/2632)) ([34beb15](https://github.com/Automattic/newspack-plugin/commit/34beb159d4d8a5eb2ce2c6a931989708752a59fd))

# [2.6.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v2.5.1...v2.6.0-alpha.1) (2023-09-06)


### Features

* add anonymized email to ga4 events ([#2585](https://github.com/Automattic/newspack-plugin/issues/2585)) ([9ac1c33](https://github.com/Automattic/newspack-plugin/commit/9ac1c3378f188794de63c9947ca7e46f5dd8644c))
* add filter to the lists in the reg block ([#2611](https://github.com/Automattic/newspack-plugin/issues/2611)) ([d960541](https://github.com/Automattic/newspack-plugin/commit/d96054167c428f0d7f3a36e99f7c1b3fd1c3bbd3))
* new option to require all membership plans for restricted content ([#2623](https://github.com/Automattic/newspack-plugin/issues/2623)) ([60b361e](https://github.com/Automattic/newspack-plugin/commit/60b361e78b86a77ab2502a91a6d1bcbc9218e533))

## [2.5.1](https://github.com/Automattic/newspack-plugin/compare/v2.5.0...v2.5.1) (2023-09-06)


### Bug Fixes

* **reader-data:** `gettype` typo while checking newsletter subscription ([#2629](https://github.com/Automattic/newspack-plugin/issues/2629)) ([f95850e](https://github.com/Automattic/newspack-plugin/commit/f95850ec7546e1a92d4e05a7633c67c339aaaa3d))

# [2.5.0](https://github.com/Automattic/newspack-plugin/compare/v2.4.0...v2.5.0) (2023-08-30)


### Features

* **reader-data:** check newsletter subscription on login ([#2619](https://github.com/Automattic/newspack-plugin/issues/2619)) ([34afeb3](https://github.com/Automattic/newspack-plugin/commit/34afeb36560f38007e84dfeec8a970a7fb0e3b23))

# [2.4.0](https://github.com/Automattic/newspack-plugin/compare/v2.3.0...v2.4.0) (2023-08-24)


### Bug Fixes

* correct thank you message text domain ([#2596](https://github.com/Automattic/newspack-plugin/issues/2596)) ([6765737](https://github.com/Automattic/newspack-plugin/commit/6765737e598064c65e5d3045d7e379bb3f1e9ab2))
* only redirect to setup if not completed already ([#2591](https://github.com/Automattic/newspack-plugin/issues/2591)) ([ed682b6](https://github.com/Automattic/newspack-plugin/commit/ed682b6f7816c174f60425006d4ce907e1c3d527))
* **wp-6.3:** rename Reusable Blocks to Patterns ([#2581](https://github.com/Automattic/newspack-plugin/issues/2581)) ([8c66a45](https://github.com/Automattic/newspack-plugin/commit/8c66a45a3ca5562daf685c3ad3b043480ef00861))


### Features

* **ads:** display parent ad unit path on ad unit editor ([#2592](https://github.com/Automattic/newspack-plugin/issues/2592)) ([c8437d8](https://github.com/Automattic/newspack-plugin/commit/c8437d888eb751d4b2474057f8a65924dd0ddd8f))
* changes required for Campaigns rearchitecture ([#2558](https://github.com/Automattic/newspack-plugin/issues/2558)) ([336521f](https://github.com/Automattic/newspack-plugin/commit/336521f18fa4e686c7943bdcc0eef67a5eebcfe4)), closes [#2537](https://github.com/Automattic/newspack-plugin/issues/2537) [#2582](https://github.com/Automattic/newspack-plugin/issues/2582) [#2583](https://github.com/Automattic/newspack-plugin/issues/2583)
* **ras:** replace newsletters verification email ([#2538](https://github.com/Automattic/newspack-plugin/issues/2538)) ([cc87736](https://github.com/Automattic/newspack-plugin/commit/cc87736873a94fdb6ade5c615f80bd9b3e90c6c0))

# [2.4.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v2.3.0...v2.4.0-alpha.1) (2023-08-17)


### Bug Fixes

* correct thank you message text domain ([#2596](https://github.com/Automattic/newspack-plugin/issues/2596)) ([6765737](https://github.com/Automattic/newspack-plugin/commit/6765737e598064c65e5d3045d7e379bb3f1e9ab2))
* only redirect to setup if not completed already ([#2591](https://github.com/Automattic/newspack-plugin/issues/2591)) ([ed682b6](https://github.com/Automattic/newspack-plugin/commit/ed682b6f7816c174f60425006d4ce907e1c3d527))
* **wp-6.3:** rename Reusable Blocks to Patterns ([#2581](https://github.com/Automattic/newspack-plugin/issues/2581)) ([8c66a45](https://github.com/Automattic/newspack-plugin/commit/8c66a45a3ca5562daf685c3ad3b043480ef00861))


### Features

* **ads:** display parent ad unit path on ad unit editor ([#2592](https://github.com/Automattic/newspack-plugin/issues/2592)) ([c8437d8](https://github.com/Automattic/newspack-plugin/commit/c8437d888eb751d4b2474057f8a65924dd0ddd8f))
* changes required for Campaigns rearchitecture ([#2558](https://github.com/Automattic/newspack-plugin/issues/2558)) ([336521f](https://github.com/Automattic/newspack-plugin/commit/336521f18fa4e686c7943bdcc0eef67a5eebcfe4)), closes [#2537](https://github.com/Automattic/newspack-plugin/issues/2537) [#2582](https://github.com/Automattic/newspack-plugin/issues/2582) [#2583](https://github.com/Automattic/newspack-plugin/issues/2583)
* **ras:** replace newsletters verification email ([#2538](https://github.com/Automattic/newspack-plugin/issues/2538)) ([cc87736](https://github.com/Automattic/newspack-plugin/commit/cc87736873a94fdb6ade5c615f80bd9b3e90c6c0))

# [2.3.0](https://github.com/Automattic/newspack-plugin/compare/v2.2.5...v2.3.0) (2023-08-07)


### Bug Fixes

* avoid webhooks to break with json encoded data ([#2579](https://github.com/Automattic/newspack-plugin/issues/2579)) ([8fdbc74](https://github.com/Automattic/newspack-plugin/commit/8fdbc74f3d9e1834e41c906528ee9d8cf178d2d2))
* **content-gate:** custom filter for 'do_blocks()' ([#2577](https://github.com/Automattic/newspack-plugin/issues/2577)) ([98b5f9f](https://github.com/Automattic/newspack-plugin/commit/98b5f9f6d1c27e598c324c5a2504ed48dac99c62))
* **content-gate:** custom filter for 'do_blocks()' ([#2577](https://github.com/Automattic/newspack-plugin/issues/2577)) ([b25c010](https://github.com/Automattic/newspack-plugin/commit/b25c010d5c7094412ff0f024677ab03dfdab9f41))
* **content-gate:** use custom filter for gate content ([#2551](https://github.com/Automattic/newspack-plugin/issues/2551)) ([714cfec](https://github.com/Automattic/newspack-plugin/commit/714cfec4792db5699935eda9a8e3c5ea950014eb))
* ensure generated id always have given length ([#2557](https://github.com/Automattic/newspack-plugin/issues/2557)) ([84c018b](https://github.com/Automattic/newspack-plugin/commit/84c018bb4c3f485209296a97e0f15bfc5290ef50))
* ensure not null in currency string ([#2566](https://github.com/Automattic/newspack-plugin/issues/2566)) ([fdd84a9](https://github.com/Automattic/newspack-plugin/commit/fdd84a9f868ef3a6c4e3a2ed8de4d31bce70ce0d))
* **pwa:** improve "server offline" message ([#2550](https://github.com/Automattic/newspack-plugin/issues/2550)) ([dbe4519](https://github.com/Automattic/newspack-plugin/commit/dbe451944675282479464ffbc76586af9cef8ba8))
* **ras:** reorganize script initialization ([#2576](https://github.com/Automattic/newspack-plugin/issues/2576)) ([76e0e5d](https://github.com/Automattic/newspack-plugin/commit/76e0e5dae764d41cbff7a2f0e78bd7e363ed98f9))
* **reader-data:** ensure author on 'article_view' push ([#2584](https://github.com/Automattic/newspack-plugin/issues/2584)) ([fbe62c3](https://github.com/Automattic/newspack-plugin/commit/fbe62c3ef98116f555d8e5c5d07d0c18b1a64d52))


### Features

* add an image size control for the RSS images ([#2553](https://github.com/Automattic/newspack-plugin/issues/2553)) ([dbc8358](https://github.com/Automattic/newspack-plugin/commit/dbc83580551212b144b07d7d0a6c6301ddab746b))
* **auth:** listen cookie changes for store state ([#2569](https://github.com/Automattic/newspack-plugin/issues/2569)) ([4df1a95](https://github.com/Automattic/newspack-plugin/commit/4df1a951f6110df7ed0e1131279876b1117b1b9a))
* **content-gate:** support gate per plan ([#2531](https://github.com/Automattic/newspack-plugin/issues/2531)) ([eaf5d50](https://github.com/Automattic/newspack-plugin/commit/eaf5d501514bd18914a7fc7c33837e7977954cde))
* Remove GA Fivetran connection ([2100013](https://github.com/Automattic/newspack-plugin/commit/210001340db82964973a0947769e6702742011be))
* trigger reader_registered for Woo checkout ([#2527](https://github.com/Automattic/newspack-plugin/issues/2527)) ([8e2f244](https://github.com/Automattic/newspack-plugin/commit/8e2f244c11bb1f577ad730001024b5f45a54595b))
* update analytics wizard ([#2580](https://github.com/Automattic/newspack-plugin/issues/2580)) ([ec89736](https://github.com/Automattic/newspack-plugin/commit/ec89736f8a61acb768924009e9dcae68bd0d0286))

# [2.3.0-alpha.6](https://github.com/Automattic/newspack-plugin/compare/v2.3.0-alpha.5...v2.3.0-alpha.6) (2023-08-07)


### Bug Fixes

* avoid webhooks to break with json encoded data ([#2579](https://github.com/Automattic/newspack-plugin/issues/2579)) ([8fdbc74](https://github.com/Automattic/newspack-plugin/commit/8fdbc74f3d9e1834e41c906528ee9d8cf178d2d2))
* **content-gate:** custom filter for 'do_blocks()' ([#2577](https://github.com/Automattic/newspack-plugin/issues/2577)) ([98b5f9f](https://github.com/Automattic/newspack-plugin/commit/98b5f9f6d1c27e598c324c5a2504ed48dac99c62))
* **content-gate:** custom filter for 'do_blocks()' ([#2577](https://github.com/Automattic/newspack-plugin/issues/2577)) ([b25c010](https://github.com/Automattic/newspack-plugin/commit/b25c010d5c7094412ff0f024677ab03dfdab9f41))
* ensure not null in currency string ([#2566](https://github.com/Automattic/newspack-plugin/issues/2566)) ([fdd84a9](https://github.com/Automattic/newspack-plugin/commit/fdd84a9f868ef3a6c4e3a2ed8de4d31bce70ce0d))
* **ras:** reorganize script initialization ([#2576](https://github.com/Automattic/newspack-plugin/issues/2576)) ([76e0e5d](https://github.com/Automattic/newspack-plugin/commit/76e0e5dae764d41cbff7a2f0e78bd7e363ed98f9))
* **reader-data:** ensure author on 'article_view' push ([#2584](https://github.com/Automattic/newspack-plugin/issues/2584)) ([fbe62c3](https://github.com/Automattic/newspack-plugin/commit/fbe62c3ef98116f555d8e5c5d07d0c18b1a64d52))


### Features

* **auth:** listen cookie changes for store state ([#2569](https://github.com/Automattic/newspack-plugin/issues/2569)) ([4df1a95](https://github.com/Automattic/newspack-plugin/commit/4df1a951f6110df7ed0e1131279876b1117b1b9a))
* update analytics wizard ([#2580](https://github.com/Automattic/newspack-plugin/issues/2580)) ([ec89736](https://github.com/Automattic/newspack-plugin/commit/ec89736f8a61acb768924009e9dcae68bd0d0286))

# [2.3.0-alpha.5](https://github.com/Automattic/newspack-plugin/compare/v2.3.0-alpha.4...v2.3.0-alpha.5) (2023-08-02)


### Bug Fixes

* check for the ga4 connector ([#2587](https://github.com/Automattic/newspack-plugin/issues/2587)) ([4fc21ab](https://github.com/Automattic/newspack-plugin/commit/4fc21abd6d172665b7ef7d8f4dcac2020fbaf684))

## [2.2.5](https://github.com/Automattic/newspack-plugin/compare/v2.2.4...v2.2.5) (2023-08-02)


### Bug Fixes

* check for the ga4 connector ([#2587](https://github.com/Automattic/newspack-plugin/issues/2587)) ([4fc21ab](https://github.com/Automattic/newspack-plugin/commit/4fc21abd6d172665b7ef7d8f4dcac2020fbaf684))

## [2.2.4](https://github.com/Automattic/newspack-plugin/compare/v2.2.3...v2.2.4) (2023-07-24)


### Bug Fixes

* **wp-6.3:** destructured lodash module import error ([#2578](https://github.com/Automattic/newspack-plugin/issues/2578)) ([18ccf54](https://github.com/Automattic/newspack-plugin/commit/18ccf540bef664cee9afeb832d0764145cd398b6))

## [2.2.3](https://github.com/Automattic/newspack-plugin/compare/v2.2.2...v2.2.3) (2023-07-21)


### Bug Fixes

* **auth:** restore sign-in otp ui ([#2568](https://github.com/Automattic/newspack-plugin/issues/2568)) ([16c4b02](https://github.com/Automattic/newspack-plugin/commit/16c4b024fdedaa9aee7b2f9a5d4fa812a0184ae0))

## [2.2.2](https://github.com/Automattic/newspack-plugin/compare/v2.2.1...v2.2.2) (2023-07-20)


### Bug Fixes

* perfmatters default settings updates ([#2509](https://github.com/Automattic/newspack-plugin/issues/2509)) ([c5a9e15](https://github.com/Automattic/newspack-plugin/commit/c5a9e15b54db27af599ebaa7294928f9a26cb594))

## [2.2.1](https://github.com/Automattic/newspack-plugin/compare/v2.2.0...v2.2.1) (2023-07-17)


### Bug Fixes

* **recaptcha:** prevent token timeout on WC ([#2562](https://github.com/Automattic/newspack-plugin/issues/2562)) ([64611fd](https://github.com/Automattic/newspack-plugin/commit/64611fd2c05cf743873c00c6627ff5c0594acf35))

# [2.2.0](https://github.com/Automattic/newspack-plugin/compare/v2.1.3...v2.2.0) (2023-07-17)


### Bug Fixes

* **content-gate:** close excerpt tags before inline gate ([#2529](https://github.com/Automattic/newspack-plugin/issues/2529)) ([f5c8b15](https://github.com/Automattic/newspack-plugin/commit/f5c8b154f66ede8b906542e6c3027d5e5416a534))
* disable autofill for recaptcha inputs ([#2517](https://github.com/Automattic/newspack-plugin/issues/2517)) ([c8c07a6](https://github.com/Automattic/newspack-plugin/commit/c8c07a638a75b8dd3f0df8e0bf9c89d28157315f))
* don’t assume the site URL is at the root domain ([#2521](https://github.com/Automattic/newspack-plugin/issues/2521)) ([8250b26](https://github.com/Automattic/newspack-plugin/commit/8250b267edf96ce0b5e275513477afe54e999f33))
* **reader-data:** format of activity push array ([#2555](https://github.com/Automattic/newspack-plugin/issues/2555)) ([67a33a9](https://github.com/Automattic/newspack-plugin/commit/67a33a94efcc08799b42d53493b3981c65edb101))
* remove reCaptcha for WC plugin ([#2513](https://github.com/Automattic/newspack-plugin/issues/2513)) ([827ef0b](https://github.com/Automattic/newspack-plugin/commit/827ef0b59b6d43956a0a9cae50f4e6602eb73578))
* tweak content gate styles for mobile ([#2552](https://github.com/Automattic/newspack-plugin/issues/2552)) ([ad02d79](https://github.com/Automattic/newspack-plugin/commit/ad02d791f8fde0f96b9d344937b370eb0411fceb))


### Features

* add 'article_view' reader activity data ([#2520](https://github.com/Automattic/newspack-plugin/issues/2520)) ([0b2617a](https://github.com/Automattic/newspack-plugin/commit/0b2617a14ada11fb2ea3b33e2a839ebe80d2c52a))
* **data-events:** broader coverage for 'reader_registered' ([#2526](https://github.com/Automattic/newspack-plugin/issues/2526)) ([9bf9c49](https://github.com/Automattic/newspack-plugin/commit/9bf9c4936f1cd5b0febc8aa664363304cd12c2f7))
* **reader-data:** add newsletter subscription and donation ([#2539](https://github.com/Automattic/newspack-plugin/issues/2539)) ([00f4880](https://github.com/Automattic/newspack-plugin/commit/00f4880da82f7ab144420e6379a0898729f6dd3c))
* **reader-data:** set referrer ([#2541](https://github.com/Automattic/newspack-plugin/issues/2541)) ([cb2993a](https://github.com/Automattic/newspack-plugin/commit/cb2993a075884757bd4c5fecea21feaf6f1fd122))

# [2.2.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v2.2.0-alpha.3...v2.2.0-alpha.4) (2023-07-13)


### Bug Fixes

* **perfmatters:** add TEC CSS to excluded stylesheets ([#2554](https://github.com/Automattic/newspack-plugin/issues/2554)) ([8c9b5ed](https://github.com/Automattic/newspack-plugin/commit/8c9b5eddf40b266c35b1a81f90bdbb97cb261ae5))

## [2.1.3](https://github.com/Automattic/newspack-plugin/compare/v2.1.2...v2.1.3) (2023-07-13)


### Bug Fixes

* **perfmatters:** add TEC CSS to excluded stylesheets ([#2554](https://github.com/Automattic/newspack-plugin/issues/2554)) ([8c9b5ed](https://github.com/Automattic/newspack-plugin/commit/8c9b5eddf40b266c35b1a81f90bdbb97cb261ae5))

## [2.1.2](https://github.com/Automattic/newspack-plugin/compare/v2.1.1...v2.1.2) (2023-07-12)


### Bug Fixes

* **auth:** render otp when registering with existing account ([#2542](https://github.com/Automattic/newspack-plugin/issues/2542)) ([55e7b0a](https://github.com/Automattic/newspack-plugin/commit/55e7b0a10484391c1286d7339033735cbd0c01b3))

## [2.1.1](https://github.com/Automattic/newspack-plugin/compare/v2.1.0...v2.1.1) (2023-07-04)


### Bug Fixes

* preserve newsletters lists order on reg block ([4586fc8](https://github.com/Automattic/newspack-plugin/commit/4586fc810bf9a2f0a791bce1259e18be55540935))

# [2.1.0](https://github.com/Automattic/newspack-plugin/compare/v2.0.3...v2.1.0) (2023-07-03)


### Bug Fixes

* allow campaign prompts to render on metered content ([#2516](https://github.com/Automattic/newspack-plugin/issues/2516)) ([bba79ef](https://github.com/Automattic/newspack-plugin/commit/bba79ef89723b1cebf7bc09ba63cdcb68ae1bfbd))
* allow high-res youtube thumbs via constant ([#2507](https://github.com/Automattic/newspack-plugin/issues/2507)) ([235757b](https://github.com/Automattic/newspack-plugin/commit/235757b8b021870610669b267dd700f13c3232f1))
* **donations:** empty cart on donation checkout ([#2505](https://github.com/Automattic/newspack-plugin/issues/2505)) ([35d9a91](https://github.com/Automattic/newspack-plugin/commit/35d9a917d267c085833ac7f940f2fa451de998d0))


### Features

* do not override data on popup events ([#2506](https://github.com/Automattic/newspack-plugin/issues/2506)) ([051769b](https://github.com/Automattic/newspack-plugin/commit/051769bea935977ce2f482210aac170fe3b6e5c8))
* move RSS image to plugin and increase size ([#2504](https://github.com/Automattic/newspack-plugin/issues/2504)) ([3dab16f](https://github.com/Automattic/newspack-plugin/commit/3dab16f02070749a2d08813f408105832966f483))
* **plugin-installer:** handle premium plugins in PluginInstaller ([#2482](https://github.com/Automattic/newspack-plugin/issues/2482)) ([b35aeeb](https://github.com/Automattic/newspack-plugin/commit/b35aeebf4970f20ae41b6497364e0e4794dfd686))
* reader data library ([#2451](https://github.com/Automattic/newspack-plugin/issues/2451)) ([2ad5f06](https://github.com/Automattic/newspack-plugin/commit/2ad5f06c3f87e9b3c7bd17fed5092364fbf38bdb))

## [2.0.3](https://github.com/Automattic/newspack-plugin/compare/v2.0.2...v2.0.3) (2023-06-29)


### Bug Fixes

* **auth:** stop redirect registration from hash link ([#2528](https://github.com/Automattic/newspack-plugin/issues/2528)) ([d462341](https://github.com/Automattic/newspack-plugin/commit/d46234175b065f8f1e4df643aa8ae1ad2df0b6e2))

## [2.0.2](https://github.com/Automattic/newspack-plugin/compare/v2.0.1...v2.0.2) (2023-06-26)


### Bug Fixes

* update required fields for editing My Account details ([#2519](https://github.com/Automattic/newspack-plugin/issues/2519)) ([becc207](https://github.com/Automattic/newspack-plugin/commit/becc2076336561faa1efab9a4707d3f5a1f82162))

## [2.0.1](https://github.com/Automattic/newspack-plugin/compare/v2.0.0...v2.0.1) (2023-06-20)


### Bug Fixes

* **jetpack:** preserve image src for photon srcset filter ([#2515](https://github.com/Automattic/newspack-plugin/issues/2515)) ([2b8c94b](https://github.com/Automattic/newspack-plugin/commit/2b8c94b05f06368c0c4d1a6e0d56fbaa603df1dd))

# [2.0.0](https://github.com/Automattic/newspack-plugin/compare/v1.117.0...v2.0.0) (2023-06-19)


### Bug Fixes

* force alpha release ([3312ac6](https://github.com/Automattic/newspack-plugin/commit/3312ac6775326fbb5d4a0b370af4a92b4ecf79ad))
* **ras-defaults:** warn if navigating away from wizard with unsaved changes ([#2453](https://github.com/Automattic/newspack-plugin/issues/2453)) ([029e89a](https://github.com/Automattic/newspack-plugin/commit/029e89a386c42ddc190659d40009833df6fd3ff1))
* **ras:** check that unblock exists before calling it ([#2483](https://github.com/Automattic/newspack-plugin/issues/2483)) ([8ab9a8f](https://github.com/Automattic/newspack-plugin/commit/8ab9a8fca6aec9563c164ae3e3d1805941a585fe))


### Features

* **content-gate:** updated block patterns ([#2474](https://github.com/Automattic/newspack-plugin/issues/2474)) ([244240d](https://github.com/Automattic/newspack-plugin/commit/244240d92380cb95b7e77cf1ec2c76bc34423776))
* customizable receipt emails for Woo ([#2463](https://github.com/Automattic/newspack-plugin/issues/2463)) ([6c5c38d](https://github.com/Automattic/newspack-plugin/commit/6c5c38d48bf7156cd4241e14c9f18f2fce1045ba))
* **jetpack-photon:** additional srcset sizes ([#2471](https://github.com/Automattic/newspack-plugin/issues/2471)) ([35ac697](https://github.com/Automattic/newspack-plugin/commit/35ac697baa12894beb240848028287ede143991c))
* only display author filter for posts ([#2470](https://github.com/Automattic/newspack-plugin/issues/2470)) ([2461e49](https://github.com/Automattic/newspack-plugin/commit/2461e49a80d0e07156ddf86d8d6540b469dde016))
* **ras:** add NRH support for setup wizard ([#2484](https://github.com/Automattic/newspack-plugin/issues/2484)) ([efd1c66](https://github.com/Automattic/newspack-plugin/commit/efd1c669443c6ce372d1f07380757d6ac2f059d3))
* rename ga4 events ([#2489](https://github.com/Automattic/newspack-plugin/issues/2489)) ([d1b0a3d](https://github.com/Automattic/newspack-plugin/commit/d1b0a3dc6db7ef86b6992a55ebe8b35b4556941e))


### BREAKING CHANGES

* Turns on customizable receipt emails for sites using WooCommerce as a Reader Revenue platform. This can be turned off in Reader Revenue settings, if desired.

* feat: use customizable receipt emails for Woo and start feature to enable/disable

* feat: allow customizable receipt to be turned on or off

# [2.0.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v2.0.0-alpha.1...v2.0.0-alpha.2) (2023-06-15)


### Features

* do not override data on popup events ([#2506](https://github.com/Automattic/newspack-plugin/issues/2506)) ([34068ff](https://github.com/Automattic/newspack-plugin/commit/34068ffe67d8c28ce1b524b5abb322c7deb17d6b))
* rename ga4 events ([#2489](https://github.com/Automattic/newspack-plugin/issues/2489)) ([1e81a15](https://github.com/Automattic/newspack-plugin/commit/1e81a15971a5835807bf9a7f15bcc6a948375c1b))

# [1.117.0](https://github.com/Automattic/newspack-plugin/compare/v1.116.1...v1.117.0) (2023-06-15)


### Features

* do not override data on popup events ([#2506](https://github.com/Automattic/newspack-plugin/issues/2506)) ([34068ff](https://github.com/Automattic/newspack-plugin/commit/34068ffe67d8c28ce1b524b5abb322c7deb17d6b))
* rename ga4 events ([#2489](https://github.com/Automattic/newspack-plugin/issues/2489)) ([1e81a15](https://github.com/Automattic/newspack-plugin/commit/1e81a15971a5835807bf9a7f15bcc6a948375c1b))

## [1.116.1](https://github.com/Automattic/newspack-plugin/compare/v1.116.0...v1.116.1) (2023-06-07)


### Bug Fixes

* **recaptcha:** selector for response input in WC checkout ([#2490](https://github.com/Automattic/newspack-plugin/issues/2490)) ([bf4ac17](https://github.com/Automattic/newspack-plugin/commit/bf4ac1710a51d60c65d7e09c97b9b984193924f4))

# [1.116.0](https://github.com/Automattic/newspack-plugin/compare/v1.115.1...v1.116.0) (2023-06-05)


### Bug Fixes

* remove 'username' from Twitter menu label ([#2460](https://github.com/Automattic/newspack-plugin/issues/2460)) ([e85d69c](https://github.com/Automattic/newspack-plugin/commit/e85d69c8f48240ce10df0516f019a92730f506dc))
* remove unneeded blocks from patterns ([#2441](https://github.com/Automattic/newspack-plugin/issues/2441)) ([ea334ab](https://github.com/Automattic/newspack-plugin/commit/ea334ab8eb0762a6c92babe92f8006cc1e986d53))


### Features

* **content-gate:** metering ([#2423](https://github.com/Automattic/newspack-plugin/issues/2423)) ([60b0d6d](https://github.com/Automattic/newspack-plugin/commit/60b0d6d47629a15d2e7a4bab363acd5b6362872e))
* **content-gate:** updated block patterns ([#2474](https://github.com/Automattic/newspack-plugin/issues/2474)) ([161f935](https://github.com/Automattic/newspack-plugin/commit/161f935368be4305dba28631fd9142c3c84f6758))
* hook reCAPTCHA v3 to WooCommerce checkout ([#2447](https://github.com/Automattic/newspack-plugin/issues/2447)) ([6650b82](https://github.com/Automattic/newspack-plugin/commit/6650b8298d872aceb213fa0280553e6327ffeb75))
* **ras:** add NRH support for setup wizard ([#2484](https://github.com/Automattic/newspack-plugin/issues/2484)) ([d1255c9](https://github.com/Automattic/newspack-plugin/commit/d1255c9d0cf3923701293138610192f4b09ba531))
* **ras:** mailchimp audience config ([#2462](https://github.com/Automattic/newspack-plugin/issues/2462)) ([8ee4440](https://github.com/Automattic/newspack-plugin/commit/8ee444030b6e5171db7165f9789fdff2a58cf123))
* **ras:** remove env flag requirements for RAS functionality ([#2461](https://github.com/Automattic/newspack-plugin/issues/2461)) ([e25dcf3](https://github.com/Automattic/newspack-plugin/commit/e25dcf3baa7afd7b79c6ad2cdb67400b2dc5ef0d))

# [1.116.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.116.0-alpha.2...v1.116.0-alpha.3) (2023-05-29)


### Features

* **content-gate:** updated block patterns ([#2474](https://github.com/Automattic/newspack-plugin/issues/2474)) ([161f935](https://github.com/Automattic/newspack-plugin/commit/161f935368be4305dba28631fd9142c3c84f6758))

# [1.116.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.116.0-alpha.1...v1.116.0-alpha.2) (2023-05-26)


### Bug Fixes

* **performance:** exclude main jetpack css from delay ([4e7aa5e](https://github.com/Automattic/newspack-plugin/commit/4e7aa5e8a076bb37d5378a007c83c5f26c22f7e9))

## [1.115.1](https://github.com/Automattic/newspack-plugin/compare/v1.115.0...v1.115.1) (2023-05-26)


### Bug Fixes

* **performance:** exclude main jetpack css from delay ([4e7aa5e](https://github.com/Automattic/newspack-plugin/commit/4e7aa5e8a076bb37d5378a007c83c5f26c22f7e9))

# [1.115.0](https://github.com/Automattic/newspack-plugin/compare/v1.114.5...v1.115.0) (2023-05-24)


### Bug Fixes

* add filter to prompt conflicts check ([#2421](https://github.com/Automattic/newspack-plugin/issues/2421)) ([8d1c1d3](https://github.com/Automattic/newspack-plugin/commit/8d1c1d3e17ae51f97a7d783b05c4fef4a2b6660e))
* check for required plugins in RAS setup wizard ([#2442](https://github.com/Automattic/newspack-plugin/issues/2442)) ([0d5bb63](https://github.com/Automattic/newspack-plugin/commit/0d5bb63e028a13f47712150787f707593a68e969))
* check for required plugins in RAS setup wizard ([#2442](https://github.com/Automattic/newspack-plugin/issues/2442)) ([11d682a](https://github.com/Automattic/newspack-plugin/commit/11d682a01ca3d4ed3abb1ec0a29b0ab528a03385))
* ensure .hooks is excluded from the dist ZIP ([#2420](https://github.com/Automattic/newspack-plugin/issues/2420)) ([75f8f36](https://github.com/Automattic/newspack-plugin/commit/75f8f3665bc19ee65333726906e42a1a8724ef25))
* **ga4:** donation event conditional typo ([#2439](https://github.com/Automattic/newspack-plugin/issues/2439)) ([d2a9373](https://github.com/Automattic/newspack-plugin/commit/d2a9373b5edaea74b2f7cbeaf3cf8f3ecd19e091))
* Improved handling of trial subscriptions in ESP sync ([#2385](https://github.com/Automattic/newspack-plugin/issues/2385)) ([6fe3368](https://github.com/Automattic/newspack-plugin/commit/6fe33680cc1e67dd440c9148990fcd6ca6019215))
* **newsletters:** only attempt token verification for Constant Contact ([#2407](https://github.com/Automattic/newspack-plugin/issues/2407)) ([c58f9a8](https://github.com/Automattic/newspack-plugin/commit/c58f9a8c8036d5c86987768a20117bf7d3c00d68))
* **perfmatters:** exclude Jetpack and Newsletters css delay ([#2410](https://github.com/Automattic/newspack-plugin/issues/2410)) ([d2b167c](https://github.com/Automattic/newspack-plugin/commit/d2b167c19ecc851ef8a3a3a1d052d28c23a1ac83))
* **ras-defaults:** feedback from QA ([#2437](https://github.com/Automattic/newspack-plugin/issues/2437)) ([1dd1f36](https://github.com/Automattic/newspack-plugin/commit/1dd1f369427c137f9c24a72a559370a264b77def))
* **ras-defaults:** updates for design review feedback ([#2435](https://github.com/Automattic/newspack-plugin/issues/2435)) ([3aa6de7](https://github.com/Automattic/newspack-plugin/commit/3aa6de7e9a77d9640f3b42c2e9bf8182f3dc2d1b))


### Features

* add indentation to groups newsletters ([#2424](https://github.com/Automattic/newspack-plugin/issues/2424)) ([d423151](https://github.com/Automattic/newspack-plugin/commit/d423151a86dac5bd21c46128f5da78afde780be7))
* **auth:** support "register modal" hash url ([#2427](https://github.com/Automattic/newspack-plugin/issues/2427)) ([fcb1199](https://github.com/Automattic/newspack-plugin/commit/fcb119964dbe6e977efa0ce5d236b736a6af79b0))
* filter existing webhooks endpoints ([#2446](https://github.com/Automattic/newspack-plugin/issues/2446)) ([d63a296](https://github.com/Automattic/newspack-plugin/commit/d63a296ed18f9efda6afe524dd41eec31722fc1b))
* memberships block patterns ([#2406](https://github.com/Automattic/newspack-plugin/issues/2406)) ([c96555a](https://github.com/Automattic/newspack-plugin/commit/c96555a7297b82644dfa67fb873692411890debc))
* RAS setup UI and default Campaigns wizard ([#2426](https://github.com/Automattic/newspack-plugin/issues/2426)) ([a1d4eb7](https://github.com/Automattic/newspack-plugin/commit/a1d4eb7aad13449cb1d847fb25ee2414028fdb69)), closes [#2381](https://github.com/Automattic/newspack-plugin/issues/2381) [#2366](https://github.com/Automattic/newspack-plugin/issues/2366) [#2382](https://github.com/Automattic/newspack-plugin/issues/2382)
* **ras:** mailchimp audience config ([#2462](https://github.com/Automattic/newspack-plugin/issues/2462)) ([3ca7e52](https://github.com/Automattic/newspack-plugin/commit/3ca7e52140346305f635bb37a1a4875e13fdf62c))
* **ras:** remove env flag requirements for RAS functionality ([#2461](https://github.com/Automattic/newspack-plugin/issues/2461)) ([ddc875f](https://github.com/Automattic/newspack-plugin/commit/ddc875fb4b7b5b58a2855ee22e38cce6518864cb))
* **sign-in:** body class when in modal and url hash ([#2414](https://github.com/Automattic/newspack-plugin/issues/2414)) ([aea4dc9](https://github.com/Automattic/newspack-plugin/commit/aea4dc949439f459465fa15c695639a65dcb26f8))
* update NRH settings page and redirect behavior ([#2425](https://github.com/Automattic/newspack-plugin/issues/2425)) ([f7a6c6b](https://github.com/Automattic/newspack-plugin/commit/f7a6c6b5d9ef5876c1128407b541a6d8cd986a95))

# [1.115.0-alpha.8](https://github.com/Automattic/newspack-plugin/compare/v1.115.0-alpha.7...v1.115.0-alpha.8) (2023-05-17)


### Bug Fixes

* **ras:** total paid sum race condition ([#2464](https://github.com/Automattic/newspack-plugin/issues/2464)) ([eea0e81](https://github.com/Automattic/newspack-plugin/commit/eea0e816a7b7e99087712de665553d10407ff7cb))

## [1.114.5](https://github.com/Automattic/newspack-plugin/compare/v1.114.4...v1.114.5) (2023-05-17)


### Bug Fixes

* **ras:** total paid sum race condition ([#2464](https://github.com/Automattic/newspack-plugin/issues/2464)) ([eea0e81](https://github.com/Automattic/newspack-plugin/commit/eea0e816a7b7e99087712de665553d10407ff7cb))

## [1.114.4](https://github.com/Automattic/newspack-plugin/compare/v1.114.3...v1.114.4) (2023-05-16)


### Bug Fixes

* **perfmatters:** exlcude Newspack CSS from being delayed ([#2459](https://github.com/Automattic/newspack-plugin/issues/2459)) ([ee649f5](https://github.com/Automattic/newspack-plugin/commit/ee649f5e2929f4aba8c38fac9f76991e391ed991))

## [1.114.3](https://github.com/Automattic/newspack-plugin/compare/v1.114.2...v1.114.3) (2023-05-15)


### Bug Fixes

* **onboarding:** handoff message component ([#2450](https://github.com/Automattic/newspack-plugin/issues/2450)) ([e010876](https://github.com/Automattic/newspack-plugin/commit/e0108764a3272555e1b882abaa794b10bd52fc03))

## [1.114.2](https://github.com/Automattic/newspack-plugin/compare/v1.114.1...v1.114.2) (2023-05-12)


### Bug Fixes

* **perfmatters:** do not delay CSS from Newspack Campaigns or Perfmatters’ own cache ([#2449](https://github.com/Automattic/newspack-plugin/issues/2449)) ([8075b5e](https://github.com/Automattic/newspack-plugin/commit/8075b5edc8a1902cd45f972dc505303f51fa9cfa))

## [1.114.1](https://github.com/Automattic/newspack-plugin/compare/v1.114.0...v1.114.1) (2023-05-10)


### Bug Fixes

* ensure migrated Stripe subs have next_payment scheduled ([#2434](https://github.com/Automattic/newspack-plugin/issues/2434)) ([b3b32f0](https://github.com/Automattic/newspack-plugin/commit/b3b32f0bfc09a6cf296153f783778c146e3b8291))

# [1.114.0](https://github.com/Automattic/newspack-plugin/compare/v1.113.1...v1.114.0) (2023-05-08)


### Features

* force release version bump ([323da0a](https://github.com/Automattic/newspack-plugin/commit/323da0a010a1d1b1689b65aefbf4236707dc66cd))

## [1.113.1](https://github.com/Automattic/newspack-plugin/compare/v1.113.0...v1.113.1) (2023-05-08)


### Bug Fixes

* force release build ([5691429](https://github.com/Automattic/newspack-plugin/commit/56914296a4cf92fe6c061e4f7f1bb592997a15f1))

# [1.114.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.114.0-alpha.3...v1.114.0-alpha.4) (2023-05-08)


### Bug Fixes

* check for required plugins in RAS setup wizard ([#2442](https://github.com/Automattic/newspack-plugin/issues/2442)) ([0d5bb63](https://github.com/Automattic/newspack-plugin/commit/0d5bb63e028a13f47712150787f707593a68e969))

# [1.114.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.114.0-alpha.2...v1.114.0-alpha.3) (2023-05-03)


### Bug Fixes

* **ras-defaults:** feedback from QA ([#2437](https://github.com/Automattic/newspack-plugin/issues/2437)) ([1dd1f36](https://github.com/Automattic/newspack-plugin/commit/1dd1f369427c137f9c24a72a559370a264b77def))


### Features

* **auth:** support "register modal" hash url ([#2427](https://github.com/Automattic/newspack-plugin/issues/2427)) ([fcb1199](https://github.com/Automattic/newspack-plugin/commit/fcb119964dbe6e977efa0ce5d236b736a6af79b0))

# [1.114.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.114.0-alpha.1...v1.114.0-alpha.2) (2023-05-03)


### Bug Fixes

* **ras-defaults:** updates for design review feedback ([#2435](https://github.com/Automattic/newspack-plugin/issues/2435)) ([3aa6de7](https://github.com/Automattic/newspack-plugin/commit/3aa6de7e9a77d9640f3b42c2e9bf8182f3dc2d1b))


### Features

* update NRH settings page and redirect behavior ([#2425](https://github.com/Automattic/newspack-plugin/issues/2425)) ([f7a6c6b](https://github.com/Automattic/newspack-plugin/commit/f7a6c6b5d9ef5876c1128407b541a6d8cd986a95))

# [1.114.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.113.0...v1.114.0-alpha.1) (2023-04-28)


### Bug Fixes

* add filter to prompt conflicts check ([#2421](https://github.com/Automattic/newspack-plugin/issues/2421)) ([8d1c1d3](https://github.com/Automattic/newspack-plugin/commit/8d1c1d3e17ae51f97a7d783b05c4fef4a2b6660e))
* ensure .hooks is excluded from the dist ZIP ([#2420](https://github.com/Automattic/newspack-plugin/issues/2420)) ([75f8f36](https://github.com/Automattic/newspack-plugin/commit/75f8f3665bc19ee65333726906e42a1a8724ef25))
* **newsletters:** only attempt token verification for Constant Contact ([#2407](https://github.com/Automattic/newspack-plugin/issues/2407)) ([c58f9a8](https://github.com/Automattic/newspack-plugin/commit/c58f9a8c8036d5c86987768a20117bf7d3c00d68))
* **perfmatters:** exclude Jetpack and Newsletters css delay ([#2410](https://github.com/Automattic/newspack-plugin/issues/2410)) ([d2b167c](https://github.com/Automattic/newspack-plugin/commit/d2b167c19ecc851ef8a3a3a1d052d28c23a1ac83))


### Features

* memberships block patterns ([#2406](https://github.com/Automattic/newspack-plugin/issues/2406)) ([c96555a](https://github.com/Automattic/newspack-plugin/commit/c96555a7297b82644dfa67fb873692411890debc))
* RAS setup UI and default Campaigns wizard ([#2426](https://github.com/Automattic/newspack-plugin/issues/2426)) ([a1d4eb7](https://github.com/Automattic/newspack-plugin/commit/a1d4eb7aad13449cb1d847fb25ee2414028fdb69)), closes [#2381](https://github.com/Automattic/newspack-plugin/issues/2381) [#2366](https://github.com/Automattic/newspack-plugin/issues/2366) [#2382](https://github.com/Automattic/newspack-plugin/issues/2382)
* **sign-in:** body class when in modal and url hash ([#2414](https://github.com/Automattic/newspack-plugin/issues/2414)) ([aea4dc9](https://github.com/Automattic/newspack-plugin/commit/aea4dc949439f459465fa15c695639a65dcb26f8))

# [1.113.0](https://github.com/Automattic/newspack-plugin/compare/v1.112.1...v1.113.0) (2023-04-24)


### Features

* **reader-registration:** add recaptcha panel in the editor ([#2397](https://github.com/Automattic/newspack-plugin/issues/2397)) ([336ee94](https://github.com/Automattic/newspack-plugin/commit/336ee942f31cba9613f2c3065c7475165ee5fa9d))

# [1.113.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.112.1...v1.113.0-alpha.1) (2023-04-13)


### Features

* **reader-registration:** add recaptcha panel in the editor ([#2397](https://github.com/Automattic/newspack-plugin/issues/2397)) ([336ee94](https://github.com/Automattic/newspack-plugin/commit/336ee942f31cba9613f2c3065c7475165ee5fa9d))

## [1.112.1](https://github.com/Automattic/newspack-plugin/compare/v1.112.0...v1.112.1) (2023-04-12)


### Bug Fixes

* sync reader to ESP on subscription update ([#2401](https://github.com/Automattic/newspack-plugin/issues/2401)) ([cdc8799](https://github.com/Automattic/newspack-plugin/commit/cdc8799957fabed3b3ac473083bbabd6e5aefa16))

# [1.112.0](https://github.com/Automattic/newspack-plugin/compare/v1.111.1...v1.112.0) (2023-04-10)


### Bug Fixes

* add pre push hook ([#2395](https://github.com/Automattic/newspack-plugin/issues/2395)) ([04a6e57](https://github.com/Automattic/newspack-plugin/commit/04a6e5727332aa19fd88ba8ae822d9f00e8ab684))
* Avoid falal error on Reader Revenue wizard ([#2382](https://github.com/Automattic/newspack-plugin/issues/2382)) ([646d212](https://github.com/Automattic/newspack-plugin/commit/646d212d7e4f3df022a472c30dfa052aa8795f48))
* bail silently when recaptcha key is not set ([#2363](https://github.com/Automattic/newspack-plugin/issues/2363)) ([de18369](https://github.com/Automattic/newspack-plugin/commit/de183695019b3e29f5f404ed3faace976ca3b86d))
* don't show gate unless attached to a specific post ([#2400](https://github.com/Automattic/newspack-plugin/issues/2400)) ([c45097c](https://github.com/Automattic/newspack-plugin/commit/c45097ca4ec6ba1682254093ef50b978e1a74ca2))
* if My Account is set to shown in RAS, show in Customizer at all breakpoints ([#2379](https://github.com/Automattic/newspack-plugin/issues/2379)) ([1052923](https://github.com/Automattic/newspack-plugin/commit/10529238fe0475c9f4933231d4f037f7687559af))
* **memberships:** remove content filters from excerpt ([#2398](https://github.com/Automattic/newspack-plugin/issues/2398)) ([987df5b](https://github.com/Automattic/newspack-plugin/commit/987df5b88868018a86dfee0ce0e9f0db5876dc23))
* **stripe-sync-script:** process only customers with successful transactions ([#2355](https://github.com/Automattic/newspack-plugin/issues/2355)) ([1020663](https://github.com/Automattic/newspack-plugin/commit/1020663c0dbe1cfd81679d0ce1cca716a795993e))


### Features

* **amp-deprecation:** polyfill amp-vimeo tag ([#2372](https://github.com/Automattic/newspack-plugin/issues/2372)) ([4a70b65](https://github.com/Automattic/newspack-plugin/commit/4a70b65470f62c89135d1dc5f27d4060989b736a))
* **amp:** polyfill lightbox effect ([#2324](https://github.com/Automattic/newspack-plugin/issues/2324)) ([b31288c](https://github.com/Automattic/newspack-plugin/commit/b31288c3bf1538889f9ba6c0e49e62bca5ebe2d0))
* **memberships:** content gate page ([#2366](https://github.com/Automattic/newspack-plugin/issues/2366)) ([15026ab](https://github.com/Automattic/newspack-plugin/commit/15026ab36f118a0447d882f42ff0b2ca148266c1))
* **memberships:** overlay style for content gate ([#2377](https://github.com/Automattic/newspack-plugin/issues/2377)) ([dd2ff5c](https://github.com/Automattic/newspack-plugin/commit/dd2ff5c8fe177d5cac8a5af5dadb50e77bb127b9))
* **popups:** support disabled segments ([#2376](https://github.com/Automattic/newspack-plugin/issues/2376)) ([bfd65b2](https://github.com/Automattic/newspack-plugin/commit/bfd65b23dc5da98f692efcceb738c512b85b8f4b))

# [1.112.0-alpha.5](https://github.com/Automattic/newspack-plugin/compare/v1.112.0-alpha.4...v1.112.0-alpha.5) (2023-04-10)


### Bug Fixes

* don't show gate unless attached to a specific post ([#2400](https://github.com/Automattic/newspack-plugin/issues/2400)) ([c45097c](https://github.com/Automattic/newspack-plugin/commit/c45097ca4ec6ba1682254093ef50b978e1a74ca2))

# [1.112.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.112.0-alpha.3...v1.112.0-alpha.4) (2023-04-05)


### Bug Fixes

* **memberships:** remove content filters from excerpt ([#2398](https://github.com/Automattic/newspack-plugin/issues/2398)) ([987df5b](https://github.com/Automattic/newspack-plugin/commit/987df5b88868018a86dfee0ce0e9f0db5876dc23))

# [1.112.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.112.0-alpha.2...v1.112.0-alpha.3) (2023-04-04)


### Bug Fixes

* add pre push hook ([#2395](https://github.com/Automattic/newspack-plugin/issues/2395)) ([04a6e57](https://github.com/Automattic/newspack-plugin/commit/04a6e5727332aa19fd88ba8ae822d9f00e8ab684))
* Avoid falal error on Reader Revenue wizard ([#2382](https://github.com/Automattic/newspack-plugin/issues/2382)) ([646d212](https://github.com/Automattic/newspack-plugin/commit/646d212d7e4f3df022a472c30dfa052aa8795f48))


### Features

* **memberships:** overlay style for content gate ([#2377](https://github.com/Automattic/newspack-plugin/issues/2377)) ([dd2ff5c](https://github.com/Automattic/newspack-plugin/commit/dd2ff5c8fe177d5cac8a5af5dadb50e77bb127b9))

# [1.112.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.112.0-alpha.1...v1.112.0-alpha.2) (2023-04-03)


### Bug Fixes

* **amp:** improved iframe polyfill and include vimeo ([592eba7](https://github.com/Automattic/newspack-plugin/commit/592eba753eea728f830cf6e65666a912b0319f81))

## [1.111.1](https://github.com/Automattic/newspack-plugin/compare/v1.111.0...v1.111.1) (2023-04-03)


### Bug Fixes

* **amp:** improved iframe polyfill and include vimeo ([592eba7](https://github.com/Automattic/newspack-plugin/commit/592eba753eea728f830cf6e65666a912b0319f81))

# [1.111.0](https://github.com/Automattic/newspack-plugin/compare/v1.110.0...v1.111.0) (2023-03-28)


### Features

* (perfmatters) add fonts settings to defaults ([#2370](https://github.com/Automattic/newspack-plugin/issues/2370)) ([025595f](https://github.com/Automattic/newspack-plugin/commit/025595f63916ccc44faeadd7807bf76fc26c600e))

# [1.110.0](https://github.com/Automattic/newspack-plugin/compare/v1.109.0...v1.110.0) (2023-03-27)


### Bug Fixes

* **ads:** proper usage of useEffect cleanup function ([#2335](https://github.com/Automattic/newspack-plugin/issues/2335)) ([58e4c10](https://github.com/Automattic/newspack-plugin/commit/58e4c10c212a294137128071fae5a9463cf3d3e6))
* **cli:** setup config path ([6de5d36](https://github.com/Automattic/newspack-plugin/commit/6de5d3624e17a62da1e2fb1fbb6abac472c866a5))
* **data-events:** mailchimp metadata keys ([#2331](https://github.com/Automattic/newspack-plugin/issues/2331)) ([de82e06](https://github.com/Automattic/newspack-plugin/commit/de82e063c5461354e6b65da2627411022a44b859))
* **modal-checkout:** provide conversion URL for ESP ([feca20d](https://github.com/Automattic/newspack-plugin/commit/feca20df57f483ee5bb159b74a05c3245890a4b7))
* only show perfmatters notice if defaults are used ([bdae0b2](https://github.com/Automattic/newspack-plugin/commit/bdae0b2a452d870579c886b6283289dac4be957a))
* **perfmatters:** add twitter.com to JS delay list ([34bac52](https://github.com/Automattic/newspack-plugin/commit/34bac52e258f1f464e100998e77e9c91bcce858e))
* post-release merge conflict ([3d03cd2](https://github.com/Automattic/newspack-plugin/commit/3d03cd256ef3513596c03c6001f6d6a8c1b769e8))
* show handoff to finish Newspack setup only if setup is incomplete ([#2343](https://github.com/Automattic/newspack-plugin/issues/2343)) ([b0a85ef](https://github.com/Automattic/newspack-plugin/commit/b0a85efe181b33d00ce1b98d93f1bb7d1aee8874))
* toggling settings section ([#2349](https://github.com/Automattic/newspack-plugin/issues/2349)) ([87d5218](https://github.com/Automattic/newspack-plugin/commit/87d5218bc599f38221def09abf5f866fd9894379))
* **webhooks:** endpoint requests modal label ([#2332](https://github.com/Automattic/newspack-plugin/issues/2332)) ([fd99ff2](https://github.com/Automattic/newspack-plugin/commit/fd99ff27ceda43f6fb28b5c57ef398a7b7601905))
* **webhooks:** grid layout ([4aa898f](https://github.com/Automattic/newspack-plugin/commit/4aa898fa1f52cc5ff759d7a7aded79925b973ede))
* **wizards:** broken rendering in WP 6.2 ([a6cef5a](https://github.com/Automattic/newspack-plugin/commit/a6cef5a1f2fc785f31cc2c478ebde7791db1c2f9))


### Features

* add GA4 donation events ([#2326](https://github.com/Automattic/newspack-plugin/issues/2326)) ([a1377a2](https://github.com/Automattic/newspack-plugin/commit/a1377a26300caa8d3eb0eae4afbc5817667ec74a))
* Add GA4 Newsletters subscriptions events ([#2330](https://github.com/Automattic/newspack-plugin/issues/2330)) ([86ffc66](https://github.com/Automattic/newspack-plugin/commit/86ffc66c62492801c57bc06aeb20b9d0ce4e5fb5))
* Add popup events to GA4 ([#2337](https://github.com/Automattic/newspack-plugin/issues/2337)) ([62c738e](https://github.com/Automattic/newspack-plugin/commit/62c738e86c9599ed166b908bd827a3d8d09597b6))
* Add popups donation events ([#2310](https://github.com/Automattic/newspack-plugin/issues/2310)) ([53e0c4a](https://github.com/Automattic/newspack-plugin/commit/53e0c4afdef1a564cd7069ff63f9afeca77327ad))
* **amp-deprecation:** polyfills for amp-img, amp-iframe, amp-fit-text, amp-youtube ([#2308](https://github.com/Automattic/newspack-plugin/issues/2308)) ([7419cd5](https://github.com/Automattic/newspack-plugin/commit/7419cd5977e4a226669f4a23f05e576945bd9bd0))
* **health-check:** mark some plugins as supported ([#2329](https://github.com/Automattic/newspack-plugin/issues/2329)) ([c1774e3](https://github.com/Automattic/newspack-plugin/commit/c1774e3d42bec0f3f51e265613fd5ca84d49c27b))

# [1.110.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.109.0...v1.110.0-alpha.1) (2023-03-24)


### Bug Fixes

* **ads:** proper usage of useEffect cleanup function ([#2335](https://github.com/Automattic/newspack-plugin/issues/2335)) ([58e4c10](https://github.com/Automattic/newspack-plugin/commit/58e4c10c212a294137128071fae5a9463cf3d3e6))
* **cli:** setup config path ([6de5d36](https://github.com/Automattic/newspack-plugin/commit/6de5d3624e17a62da1e2fb1fbb6abac472c866a5))
* **data-events:** mailchimp metadata keys ([#2331](https://github.com/Automattic/newspack-plugin/issues/2331)) ([de82e06](https://github.com/Automattic/newspack-plugin/commit/de82e063c5461354e6b65da2627411022a44b859))
* **modal-checkout:** provide conversion URL for ESP ([feca20d](https://github.com/Automattic/newspack-plugin/commit/feca20df57f483ee5bb159b74a05c3245890a4b7))
* only show perfmatters notice if defaults are used ([bdae0b2](https://github.com/Automattic/newspack-plugin/commit/bdae0b2a452d870579c886b6283289dac4be957a))
* **perfmatters:** add twitter.com to JS delay list ([34bac52](https://github.com/Automattic/newspack-plugin/commit/34bac52e258f1f464e100998e77e9c91bcce858e))
* post-release merge conflict ([3d03cd2](https://github.com/Automattic/newspack-plugin/commit/3d03cd256ef3513596c03c6001f6d6a8c1b769e8))
* show handoff to finish Newspack setup only if setup is incomplete ([#2343](https://github.com/Automattic/newspack-plugin/issues/2343)) ([b0a85ef](https://github.com/Automattic/newspack-plugin/commit/b0a85efe181b33d00ce1b98d93f1bb7d1aee8874))
* toggling settings section ([#2349](https://github.com/Automattic/newspack-plugin/issues/2349)) ([87d5218](https://github.com/Automattic/newspack-plugin/commit/87d5218bc599f38221def09abf5f866fd9894379))
* **webhooks:** endpoint requests modal label ([#2332](https://github.com/Automattic/newspack-plugin/issues/2332)) ([fd99ff2](https://github.com/Automattic/newspack-plugin/commit/fd99ff27ceda43f6fb28b5c57ef398a7b7601905))
* **webhooks:** grid layout ([4aa898f](https://github.com/Automattic/newspack-plugin/commit/4aa898fa1f52cc5ff759d7a7aded79925b973ede))
* **wizards:** broken rendering in WP 6.2 ([a6cef5a](https://github.com/Automattic/newspack-plugin/commit/a6cef5a1f2fc785f31cc2c478ebde7791db1c2f9))


### Features

* add GA4 donation events ([#2326](https://github.com/Automattic/newspack-plugin/issues/2326)) ([a1377a2](https://github.com/Automattic/newspack-plugin/commit/a1377a26300caa8d3eb0eae4afbc5817667ec74a))
* Add GA4 Newsletters subscriptions events ([#2330](https://github.com/Automattic/newspack-plugin/issues/2330)) ([86ffc66](https://github.com/Automattic/newspack-plugin/commit/86ffc66c62492801c57bc06aeb20b9d0ce4e5fb5))
* Add popup events to GA4 ([#2337](https://github.com/Automattic/newspack-plugin/issues/2337)) ([62c738e](https://github.com/Automattic/newspack-plugin/commit/62c738e86c9599ed166b908bd827a3d8d09597b6))
* Add popups donation events ([#2310](https://github.com/Automattic/newspack-plugin/issues/2310)) ([53e0c4a](https://github.com/Automattic/newspack-plugin/commit/53e0c4afdef1a564cd7069ff63f9afeca77327ad))
* **amp-deprecation:** polyfills for amp-img, amp-iframe, amp-fit-text, amp-youtube ([#2308](https://github.com/Automattic/newspack-plugin/issues/2308)) ([7419cd5](https://github.com/Automattic/newspack-plugin/commit/7419cd5977e4a226669f4a23f05e576945bd9bd0))
* **health-check:** mark some plugins as supported ([#2329](https://github.com/Automattic/newspack-plugin/issues/2329)) ([c1774e3](https://github.com/Automattic/newspack-plugin/commit/c1774e3d42bec0f3f51e265613fd5ca84d49c27b))

# [1.109.0](https://github.com/Automattic/newspack-plugin/compare/v1.108.0...v1.109.0) (2023-03-24)


### Features

* **amp-deprecation:** polyfills for amp-img, amp-iframe, amp-fit-text, amp-youtube ([#2308](https://github.com/Automattic/newspack-plugin/issues/2308)) ([29b043b](https://github.com/Automattic/newspack-plugin/commit/29b043bdc25795fea4be08cebdf07894057888f8))

# [1.108.0](https://github.com/Automattic/newspack-plugin/compare/v1.107.1...v1.108.0) (2023-03-20)


### Features

* **perfmatters:** add ads, recaptcha JS to script delay list ([#2354](https://github.com/Automattic/newspack-plugin/issues/2354)) ([e8d1703](https://github.com/Automattic/newspack-plugin/commit/e8d1703b8333bd110456fcbd30f36edd8208801c))

## [1.107.1](https://github.com/Automattic/newspack-plugin/compare/v1.107.0...v1.107.1) (2023-03-15)


### Bug Fixes

* prevent page auto-scroll when RAS is enabled ([3cdfb45](https://github.com/Automattic/newspack-plugin/commit/3cdfb45338352ff5d8bbe3cf66a41667266cbd80))

# [1.107.0](https://github.com/Automattic/newspack-plugin/compare/v1.106.0...v1.107.0) (2023-03-14)


### Features

* remove AMP as a required plugin ([#2346](https://github.com/Automattic/newspack-plugin/issues/2346)) ([102ed04](https://github.com/Automattic/newspack-plugin/commit/102ed04723bb4a6918cfac4a9dd913d2cc6cdf25))

# [1.106.0](https://github.com/Automattic/newspack-plugin/compare/v1.105.1...v1.106.0) (2023-03-14)


### Bug Fixes

* **ads:** gam api availability according to error type ([#2289](https://github.com/Automattic/newspack-plugin/issues/2289)) ([024fe08](https://github.com/Automattic/newspack-plugin/commit/024fe08b4f72d2f675b0ecc88511ea5fc917c2a0))
* show handoff to finish Newspack setup only if setup is incomplete ([#2343](https://github.com/Automattic/newspack-plugin/issues/2343)) ([1173b5b](https://github.com/Automattic/newspack-plugin/commit/1173b5b099bdbe35d89360825341c8d6796f6b95))


### Features

* add a Add new button to subscription lists ([#2314](https://github.com/Automattic/newspack-plugin/issues/2314)) ([9543ad2](https://github.com/Automattic/newspack-plugin/commit/9543ad204421909defd4f816a86a9c44c1d19efe))
* add ga4 user registered handler ([#2281](https://github.com/Automattic/newspack-plugin/issues/2281)) ([5eb2336](https://github.com/Automattic/newspack-plugin/commit/5eb2336559f5485e917789893ecf761540abce91))
* add pid to Logger ([#2290](https://github.com/Automattic/newspack-plugin/issues/2290)) ([fd3011c](https://github.com/Automattic/newspack-plugin/commit/fd3011cdb4811f13506775ac0d4d6e646c956a41))
* Add popup info to donations ([#2300](https://github.com/Automattic/newspack-plugin/issues/2300)) ([7ea800b](https://github.com/Automattic/newspack-plugin/commit/7ea800b4d18fc9ce32df6821581777188c2809e3))
* allow external links in dashboard via a filter ([#2279](https://github.com/Automattic/newspack-plugin/issues/2279)) ([3943b1a](https://github.com/Automattic/newspack-plugin/commit/3943b1adab20d84b04dff7dae91772f2a0f72d8e))
* campaigns listeners for the data events api ([#2291](https://github.com/Automattic/newspack-plugin/issues/2291)) ([ab407d4](https://github.com/Automattic/newspack-plugin/commit/ab407d4bbcb7aa8ced97c811babe257a806186cb))
* disable save button for unchanged settings ([#2259](https://github.com/Automattic/newspack-plugin/issues/2259)) ([e06d72f](https://github.com/Automattic/newspack-plugin/commit/e06d72fed248f88c6dd9aacc0810572287093445)), closes [#1531](https://github.com/Automattic/newspack-plugin/issues/1531)
* **donate-block:** support modal checkout ([#2256](https://github.com/Automattic/newspack-plugin/issues/2256)) ([34226dd](https://github.com/Automattic/newspack-plugin/commit/34226dd67311bd7246a5ddb96079ae7472f8bc45))
* Normalize donation events ([#2299](https://github.com/Automattic/newspack-plugin/issues/2299)) ([2624d53](https://github.com/Automattic/newspack-plugin/commit/2624d53e6c1cb8a28de7ea61f861a40f3bf9ad8c))
* **perfmatters:** improve config ([267306e](https://github.com/Automattic/newspack-plugin/commit/267306e017448fb15f59f36c49472f0d97b8e764))
* prevent homepage from being unpublished ([#2307](https://github.com/Automattic/newspack-plugin/issues/2307)) ([a151d53](https://github.com/Automattic/newspack-plugin/commit/a151d53212fb005ee5e95d0a76f7a815f97f9166))
* Remove the campaign rendered event ([#2301](https://github.com/Automattic/newspack-plugin/issues/2301)) ([23caa1d](https://github.com/Automattic/newspack-plugin/commit/23caa1db8f62ecbf663b01c9e17f79d26d7be46e))
* Stripe Subscriptions to WC subscriptions migrator ([#2298](https://github.com/Automattic/newspack-plugin/issues/2298)) ([6904356](https://github.com/Automattic/newspack-plugin/commit/6904356f15d10c49b81e1eb1ccfb40452d3ac871)), closes [#2251](https://github.com/Automattic/newspack-plugin/issues/2251)
* **wc:** force allowing subscription switching ([#2305](https://github.com/Automattic/newspack-plugin/issues/2305)) ([c13e741](https://github.com/Automattic/newspack-plugin/commit/c13e741ecacea764965aa7ce407f2621d3ddc068))

# [1.106.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.106.0-alpha.2...v1.106.0-alpha.3) (2023-03-13)


### Bug Fixes

* show handoff to finish Newspack setup only if setup is incomplete ([#2343](https://github.com/Automattic/newspack-plugin/issues/2343)) ([1173b5b](https://github.com/Automattic/newspack-plugin/commit/1173b5b099bdbe35d89360825341c8d6796f6b95))

# [1.106.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.106.0-alpha.1...v1.106.0-alpha.2) (2023-03-06)


### Bug Fixes

* **perfmatters:** adjust defaults ([b03833d](https://github.com/Automattic/newspack-plugin/commit/b03833d639779dcb47084c1dc25cc2255f0c3821))

## [1.105.1](https://github.com/Automattic/newspack-plugin/compare/v1.105.0...v1.105.1) (2023-03-06)


### Bug Fixes

* **perfmatters:** adjust defaults ([b03833d](https://github.com/Automattic/newspack-plugin/commit/b03833d639779dcb47084c1dc25cc2255f0c3821))

# [1.105.0](https://github.com/Automattic/newspack-plugin/compare/v1.104.1...v1.105.0) (2023-02-28)


### Bug Fixes

* **ads-wizard:** cancelled state of unit displayed on the list ([#2272](https://github.com/Automattic/newspack-plugin/issues/2272)) ([e10b592](https://github.com/Automattic/newspack-plugin/commit/e10b592d821937073fafdb6b9a2193738cb63278))
* **ads:** remove fixed height setting ([#2255](https://github.com/Automattic/newspack-plugin/issues/2255)) ([0031b89](https://github.com/Automattic/newspack-plugin/commit/0031b890926d4f0b8412549e51cd4b64838d0184))
* change default auth strategy to "link" ([#2261](https://github.com/Automattic/newspack-plugin/issues/2261)) ([c4b2e2c](https://github.com/Automattic/newspack-plugin/commit/c4b2e2c698db390543d6bc8e8f604c25fcb6c5c1))
* **webhooks:** check if endpoint exists ([#2276](https://github.com/Automattic/newspack-plugin/issues/2276)) ([5a95e2c](https://github.com/Automattic/newspack-plugin/commit/5a95e2c3aa0a0b06596f7e1ae4d8cf55cfa8a7cf))


### Features

* bootstrap GA4 connector ([#2241](https://github.com/Automattic/newspack-plugin/issues/2241)) ([f7582a4](https://github.com/Automattic/newspack-plugin/commit/f7582a4582e24e64d2477f88328350ca7999f469))
* **data-events:** mailchimp connector ([#2233](https://github.com/Automattic/newspack-plugin/issues/2233)) ([08060c6](https://github.com/Automattic/newspack-plugin/commit/08060c6888dfcb7e0edc53b06c096d9e58aec710))
* **data-events:** standardize the use of "user_id" and "email" ([#2263](https://github.com/Automattic/newspack-plugin/issues/2263)) ([d6b6903](https://github.com/Automattic/newspack-plugin/commit/d6b69030acab8be0027de87dea05d0ada1d8e91f))
* **donations:** add payment metadata and update renewal description ([#2254](https://github.com/Automattic/newspack-plugin/issues/2254)) ([b039304](https://github.com/Automattic/newspack-plugin/commit/b03930468876a5f43c50da30f8c72776574f9557))
* **my-account:** display stripe billing link regardless of RR platform ([df540fe](https://github.com/Automattic/newspack-plugin/commit/df540fec890f0cf0930aa294c55821887202bd62))
* **perfmatters:** defaults adjustments, remove feature flag ([#2271](https://github.com/Automattic/newspack-plugin/issues/2271)) ([cf5ad51](https://github.com/Automattic/newspack-plugin/commit/cf5ad510ef5b962e0f3ffc5c515dbf11697d890e))
* **performance:** minify all inline CSS ([#2239](https://github.com/Automattic/newspack-plugin/issues/2239)) ([fbb415f](https://github.com/Automattic/newspack-plugin/commit/fbb415fcea0331a2bc79e48c288ce91c22c7ab10))
* **ras:** enable custom contact metadata prefixes ([#2249](https://github.com/Automattic/newspack-plugin/issues/2249)) ([e9843e4](https://github.com/Automattic/newspack-plugin/commit/e9843e4b6d48923009e56936dbf71eeeb18b6aeb))
* require all woo plugins for RAS ([#2260](https://github.com/Automattic/newspack-plugin/issues/2260)) ([5f6a818](https://github.com/Automattic/newspack-plugin/commit/5f6a8180a33f6e1df35b128f321ab3aa8c49032a))
* **stripe:** create native WC Subscriptions (if feature flag is set) ([#2251](https://github.com/Automattic/newspack-plugin/issues/2251)) ([009a8a5](https://github.com/Automattic/newspack-plugin/commit/009a8a58340bf76343dc2346938cfda561f02c59))
* **webhooks:** support label for endpoints ([#2262](https://github.com/Automattic/newspack-plugin/issues/2262)) ([39da7be](https://github.com/Automattic/newspack-plugin/commit/39da7be8319b2caf77607251436f59a7c2aa1cf0))

# [1.105.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.105.0-alpha.1...v1.105.0-alpha.2) (2023-02-27)


### Bug Fixes

* skip HPB optimisations on AMP ([b4f5fad](https://github.com/Automattic/newspack-plugin/commit/b4f5fad1793f7a464208506b278333aaac8a9baf))

## [1.104.1](https://github.com/Automattic/newspack-plugin/compare/v1.104.0...v1.104.1) (2023-02-27)


### Bug Fixes

* skip HPB optimisations on AMP ([b4f5fad](https://github.com/Automattic/newspack-plugin/commit/b4f5fad1793f7a464208506b278333aaac8a9baf))

# [1.104.0](https://github.com/Automattic/newspack-plugin/compare/v1.103.0...v1.104.0) (2023-02-20)


### Features

* **perfmatters:** defaults adjustments, remove feature flag ([#2271](https://github.com/Automattic/newspack-plugin/issues/2271)) ([1e451e5](https://github.com/Automattic/newspack-plugin/commit/1e451e57c776ce5ec8e46e5b1d8aa5fa8ae01838))

# [1.103.0](https://github.com/Automattic/newspack-plugin/compare/v1.102.0...v1.103.0) (2023-02-17)


### Bug Fixes

* add template versions to Woo templates ([#2240](https://github.com/Automattic/newspack-plugin/issues/2240)) ([38f75b4](https://github.com/Automattic/newspack-plugin/commit/38f75b43bb298cf59fc6567a5f7fbafa3e400d5a))
* merge conflicts ([8dd0e87](https://github.com/Automattic/newspack-plugin/commit/8dd0e87b2fa3311cce72d103ce694090bf94f583))
* trigger release ([0c8471b](https://github.com/Automattic/newspack-plugin/commit/0c8471bc3862408c9674736461ddbff16b750969))
* update package.json to resolve a merge conflict with master ([f2408a3](https://github.com/Automattic/newspack-plugin/commit/f2408a32dbb2889f1850020221fc14e8b837b4a8))
* **wc-to-esp:** total amount field value ([83e6a42](https://github.com/Automattic/newspack-plugin/commit/83e6a4254b10a7bbb213384a0961f3f3371b6831))


### Features

* **ads:** optimise inline JS of newspack-ads ([#2238](https://github.com/Automattic/newspack-plugin/issues/2238)) ([7d02837](https://github.com/Automattic/newspack-plugin/commit/7d02837a59353f96b52f47b0286ac5e5104313d1))
* **data-events:** donation listeners ([#2225](https://github.com/Automattic/newspack-plugin/issues/2225)) ([9c5d4aa](https://github.com/Automattic/newspack-plugin/commit/9c5d4aa85012b9da408456a5f8f5b554ad5c8da3))
* optimise the HP block that renders first on a page ([7559986](https://github.com/Automattic/newspack-plugin/commit/75599862880bf42d4069aa01e8435bfaa3ff9eab))
* **perfmatters:** add newspack theme files to unused CSS exclusions list ([4571387](https://github.com/Automattic/newspack-plugin/commit/45713877475ceffd11c39ab8c53e38f2d83e11b7))
* **perfmatters:** add some scripts to script delay list ([989c9fd](https://github.com/Automattic/newspack-plugin/commit/989c9fdd54adf3200aa8c886570f2f213847fe9b))
* **stripe:** disable WC emails sent on sub. renewal for Stripe sync'd subs. ([2e75135](https://github.com/Automattic/newspack-plugin/commit/2e75135ba87943a458eeb16c1cca515d7397a239))

# [1.103.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.102.0...v1.103.0-alpha.1) (2023-01-26)


### Bug Fixes

* add template versions to Woo templates ([#2240](https://github.com/Automattic/newspack-plugin/issues/2240)) ([38f75b4](https://github.com/Automattic/newspack-plugin/commit/38f75b43bb298cf59fc6567a5f7fbafa3e400d5a))
* merge conflicts ([8dd0e87](https://github.com/Automattic/newspack-plugin/commit/8dd0e87b2fa3311cce72d103ce694090bf94f583))
* **wc-to-esp:** total amount field value ([83e6a42](https://github.com/Automattic/newspack-plugin/commit/83e6a4254b10a7bbb213384a0961f3f3371b6831))


### Features

* **ads:** optimise inline JS of newspack-ads ([#2238](https://github.com/Automattic/newspack-plugin/issues/2238)) ([7d02837](https://github.com/Automattic/newspack-plugin/commit/7d02837a59353f96b52f47b0286ac5e5104313d1))
* **data-events:** donation listeners ([#2225](https://github.com/Automattic/newspack-plugin/issues/2225)) ([9c5d4aa](https://github.com/Automattic/newspack-plugin/commit/9c5d4aa85012b9da408456a5f8f5b554ad5c8da3))
* optimise the HP block that renders first on a page ([7559986](https://github.com/Automattic/newspack-plugin/commit/75599862880bf42d4069aa01e8435bfaa3ff9eab))
* **perfmatters:** add newspack theme files to unused CSS exclusions list ([4571387](https://github.com/Automattic/newspack-plugin/commit/45713877475ceffd11c39ab8c53e38f2d83e11b7))
* **perfmatters:** add some scripts to script delay list ([989c9fd](https://github.com/Automattic/newspack-plugin/commit/989c9fdd54adf3200aa8c886570f2f213847fe9b))
* **stripe:** disable WC emails sent on sub. renewal for Stripe sync'd subs. ([2e75135](https://github.com/Automattic/newspack-plugin/commit/2e75135ba87943a458eeb16c1cca515d7397a239))

# [1.102.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.101.0...v1.102.0-alpha.1) (2023-01-26)

### Bug Fixes

* if rendering media tags, ensure proper nesting ([#2250](https://github.com/Automattic/newspack-plugin/issues/2250)) ([794be65](https://github.com/Automattic/newspack-plugin/commit/794be65a6a65a99aeaf91a7e4e57a48ac409674a))

# [1.102.0](https://github.com/Automattic/newspack-plugin/compare/v1.101.0...v1.102.0) (2023-01-26)

### Bug Fixes

* add template versions to Woo templates ([#2240](https://github.com/Automattic/newspack-plugin/issues/2240)) ([38f75b4](https://github.com/Automattic/newspack-plugin/commit/38f75b43bb298cf59fc6567a5f7fbafa3e400d5a))
* **wc-to-esp:** total amount field value ([83e6a42](https://github.com/Automattic/newspack-plugin/commit/83e6a4254b10a7bbb213384a0961f3f3371b6831))

### Features

* **ads:** optimise inline JS of newspack-ads ([#2238](https://github.com/Automattic/newspack-plugin/issues/2238)) ([7d02837](https://github.com/Automattic/newspack-plugin/commit/7d02837a59353f96b52f47b0286ac5e5104313d1))
* **data-events:** donation listeners ([#2225](https://github.com/Automattic/newspack-plugin/issues/2225)) ([9c5d4aa](https://github.com/Automattic/newspack-plugin/commit/9c5d4aa85012b9da408456a5f8f5b554ad5c8da3))
* optimise the HP block that renders first on a page ([7559986](https://github.com/Automattic/newspack-plugin/commit/75599862880bf42d4069aa01e8435bfaa3ff9eab))
* **perfmatters:** add newspack theme files to unused CSS exclusions list ([4571387](https://github.com/Automattic/newspack-plugin/commit/45713877475ceffd11c39ab8c53e38f2d83e11b7))
* **perfmatters:** add some scripts to script delay list ([989c9fd](https://github.com/Automattic/newspack-plugin/commit/989c9fdd54adf3200aa8c886570f2f213847fe9b))
* **stripe:** disable WC emails sent on sub. renewal for Stripe sync'd subs. ([2e75135](https://github.com/Automattic/newspack-plugin/commit/2e75135ba87943a458eeb16c1cca515d7397a239))

# [1.101.0](https://github.com/Automattic/newspack-plugin/compare/v1.100.2...v1.101.0) (2023-01-25)

### Bug Fixes

* **stripe:** WC Subs scheduled actions errors suppression ([3ef55b3](https://github.com/Automattic/newspack-plugin/commit/3ef55b389a3ce27b5e26492d28b4957c80e3a75b))
* **stripe:** webhooks list edge-cases ([#2209](https://github.com/Automattic/newspack-plugin/issues/2209)) ([25f24ed](https://github.com/Automattic/newspack-plugin/commit/25f24ed9fb758eb9e3f046a118781faa3c60a853))


### Features

* **data-events:** use WC's Action Scheduler if available ([#2217](https://github.com/Automattic/newspack-plugin/issues/2217)) ([08c0532](https://github.com/Automattic/newspack-plugin/commit/08c053275971c78f654169b18e654bb43d6df99e))
* **donations:** remove donation products from cart if WC is not the donations platform ([#2224](https://github.com/Automattic/newspack-plugin/issues/2224)) ([b759d7e](https://github.com/Automattic/newspack-plugin/commit/b759d7e9eede4c6f3d0c79150ae3e211ba3cec20))

# [1.101.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.101.0-alpha.2...v1.101.0-alpha.3) (2023-01-19)


### Bug Fixes

* only enqueue salesforce admin JS when appropriate ([14dfc74](https://github.com/Automattic/newspack-plugin/commit/14dfc7488ae177e824dc712f7a90f0cdc0c8c020))

## [1.100.2](https://github.com/Automattic/newspack-plugin/compare/v1.100.1...v1.100.2) (2023-01-19)


### Bug Fixes

* only enqueue salesforce admin JS when appropriate ([14dfc74](https://github.com/Automattic/newspack-plugin/commit/14dfc7488ae177e824dc712f7a90f0cdc0c8c020))

## [1.100.1](https://github.com/Automattic/newspack-plugin/compare/v1.100.0...v1.100.1) (2023-01-18)


### Bug Fixes

* use newer stripe api version ([b5d1fc7](https://github.com/Automattic/newspack-plugin/commit/b5d1fc7804df7329bc3ad43b19341962705e46e8))

# [1.100.0](https://github.com/Automattic/newspack-plugin/compare/v1.99.1...v1.100.0) (2023-01-09)


### Bug Fixes

* fatal due to different class property ([#2207](https://github.com/Automattic/newspack-plugin/issues/2207)) ([7536800](https://github.com/Automattic/newspack-plugin/commit/7536800ceac80e8fbb2763c643827367984f9807))
* handle email sender when not under RAS ([#2208](https://github.com/Automattic/newspack-plugin/issues/2208)) ([deb9e02](https://github.com/Automattic/newspack-plugin/commit/deb9e0228859d3c0de716611e2b1ac8d3313a530))
* **perfmatters:** display defaults for admin only if URL param is set ([ecca448](https://github.com/Automattic/newspack-plugin/commit/ecca448b9c788d473694960fa890ad023e05ee8c))
* revert remove autorloader dependency ([ed575fa](https://github.com/Automattic/newspack-plugin/commit/ed575fa9ec8cd48e7cff62bb590daaf9484f77e1))
* typo in setup command ([#2192](https://github.com/Automattic/newspack-plugin/issues/2192)) ([ebe04cd](https://github.com/Automattic/newspack-plugin/commit/ebe04cdc328d01c3f8548bbef830bf50dd8ab17f))
* webpack build issue ([#2210](https://github.com/Automattic/newspack-plugin/issues/2210)) ([d54bb25](https://github.com/Automattic/newspack-plugin/commit/d54bb2592e660878d07a64bda182c2b3f7f6e9d8))
* **woocommerce-sync:** ignore payment error notifications for sync'd subscriptions ([12c479b](https://github.com/Automattic/newspack-plugin/commit/12c479bb5018c3c51c11418f84d9ab8fcae64e74))


### Features

* add setup cli command ([#2108](https://github.com/Automattic/newspack-plugin/issues/2108)) ([350e16e](https://github.com/Automattic/newspack-plugin/commit/350e16e4a4ee68d5c6afafc81e17c03b1410d23e))
* allow RAS library to load async ([#2196](https://github.com/Automattic/newspack-plugin/issues/2196)) ([3131425](https://github.com/Automattic/newspack-plugin/commit/3131425aa88f807da47cefa59ada4ed90d5116ae))
* **analytics:** prevent Site Kit's false positive notice ([05a4707](https://github.com/Automattic/newspack-plugin/commit/05a470724039d050bb439bfb18d1272f4020643b))
* **data-events:** agnostic data integration tool ([#2173](https://github.com/Automattic/newspack-plugin/issues/2173)) ([5ced920](https://github.com/Automattic/newspack-plugin/commit/5ced920ec6470e781f3dbaac59b1a67b94303f81))
* **data-events:** webhooks ([#2182](https://github.com/Automattic/newspack-plugin/issues/2182)) ([cf14aa2](https://github.com/Automattic/newspack-plugin/commit/cf14aa2a2f06cf3de1af8ae2a919c60e6d855b89))
* **newsletters:** support constant contact oauth ([#2179](https://github.com/Automattic/newspack-plugin/issues/2179)) ([a896a62](https://github.com/Automattic/newspack-plugin/commit/a896a629ba15feb0a8514332ec3dcfc0aa454b6c))
* **perfmatters:** add an environment feature flag ([774a710](https://github.com/Automattic/newspack-plugin/commit/774a7109ee750dc22d8812d3ce53a7b2687f0dbd))
* **reader-revenue:** add "other" reader revenue platform ([#2160](https://github.com/Automattic/newspack-plugin/issues/2160)) ([1eebe79](https://github.com/Automattic/newspack-plugin/commit/1eebe7970d60bfadca78809b17b4bd70ed024f6d))
* **registration-block:** newsletter subscription by default ([#2198](https://github.com/Automattic/newspack-plugin/issues/2198)) ([b2d5116](https://github.com/Automattic/newspack-plugin/commit/b2d51163054faa2a78dfecaef8d523a840fc71f4))
* **stripe-sync-cli:** create WC subscriptions ([#2180](https://github.com/Automattic/newspack-plugin/issues/2180)) ([0cebe24](https://github.com/Automattic/newspack-plugin/commit/0cebe24be60842e7ff689436ef3f8ac120927311))
* **stripe:** handle donation additional fields ([#2187](https://github.com/Automattic/newspack-plugin/issues/2187)) ([64b96e2](https://github.com/Automattic/newspack-plugin/commit/64b96e2f4c779a92d77f4366131fc801662427d1))
* **stripe:** webhooks management ([#2143](https://github.com/Automattic/newspack-plugin/issues/2143)) ([5ded1f9](https://github.com/Automattic/newspack-plugin/commit/5ded1f95d27f9598b0df74ef60a0f4e9f64774a5))
* use privacy policy URL as default terms URL ([#2176](https://github.com/Automattic/newspack-plugin/issues/2176)) ([9a08231](https://github.com/Automattic/newspack-plugin/commit/9a0823137cfc93cfc27052ccbed76dcc32bc27be))
* **webhooks:** endpoint management ui ([#2197](https://github.com/Automattic/newspack-plugin/issues/2197)) ([f781206](https://github.com/Automattic/newspack-plugin/commit/f781206f4681186cdc35754d2d225f5e34cefe99))


### Performance Improvements

* optimize analytics JS code ([#2157](https://github.com/Automattic/newspack-plugin/issues/2157)) ([f324500](https://github.com/Automattic/newspack-plugin/commit/f3245008d5f16bd8dcd1b15f38cbecee9e5e91c8))


### Reverts

* hotfix-related auto-changes ([89c0d07](https://github.com/Automattic/newspack-plugin/commit/89c0d072fb5718b5dadb31397ec72779b80cb72c))

# [1.100.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.100.0-alpha.1...v1.100.0-alpha.2) (2022-12-22)


### Bug Fixes

* handle email sender when not under RAS ([#2208](https://github.com/Automattic/newspack-plugin/issues/2208)) ([deb9e02](https://github.com/Automattic/newspack-plugin/commit/deb9e0228859d3c0de716611e2b1ac8d3313a530))
* webpack build issue ([#2210](https://github.com/Automattic/newspack-plugin/issues/2210)) ([d54bb25](https://github.com/Automattic/newspack-plugin/commit/d54bb2592e660878d07a64bda182c2b3f7f6e9d8))


### Features

* **webhooks:** endpoint management ui ([#2197](https://github.com/Automattic/newspack-plugin/issues/2197)) ([f781206](https://github.com/Automattic/newspack-plugin/commit/f781206f4681186cdc35754d2d225f5e34cefe99))

# [1.100.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.99.1...v1.100.0-alpha.1) (2022-12-20)


### Bug Fixes

* fatal due to different class property ([#2207](https://github.com/Automattic/newspack-plugin/issues/2207)) ([7536800](https://github.com/Automattic/newspack-plugin/commit/7536800ceac80e8fbb2763c643827367984f9807))
* **perfmatters:** display defaults for admin only if URL param is set ([ecca448](https://github.com/Automattic/newspack-plugin/commit/ecca448b9c788d473694960fa890ad023e05ee8c))
* revert remove autorloader dependency ([ed575fa](https://github.com/Automattic/newspack-plugin/commit/ed575fa9ec8cd48e7cff62bb590daaf9484f77e1))
* typo in setup command ([#2192](https://github.com/Automattic/newspack-plugin/issues/2192)) ([ebe04cd](https://github.com/Automattic/newspack-plugin/commit/ebe04cdc328d01c3f8548bbef830bf50dd8ab17f))
* **woocommerce-sync:** ignore payment error notifications for sync'd subscriptions ([12c479b](https://github.com/Automattic/newspack-plugin/commit/12c479bb5018c3c51c11418f84d9ab8fcae64e74))


### Features

* add setup cli command ([#2108](https://github.com/Automattic/newspack-plugin/issues/2108)) ([350e16e](https://github.com/Automattic/newspack-plugin/commit/350e16e4a4ee68d5c6afafc81e17c03b1410d23e))
* allow RAS library to load async ([#2196](https://github.com/Automattic/newspack-plugin/issues/2196)) ([3131425](https://github.com/Automattic/newspack-plugin/commit/3131425aa88f807da47cefa59ada4ed90d5116ae))
* **analytics:** prevent Site Kit's false positive notice ([05a4707](https://github.com/Automattic/newspack-plugin/commit/05a470724039d050bb439bfb18d1272f4020643b))
* **data-events:** agnostic data integration tool ([#2173](https://github.com/Automattic/newspack-plugin/issues/2173)) ([5ced920](https://github.com/Automattic/newspack-plugin/commit/5ced920ec6470e781f3dbaac59b1a67b94303f81))
* **data-events:** webhooks ([#2182](https://github.com/Automattic/newspack-plugin/issues/2182)) ([cf14aa2](https://github.com/Automattic/newspack-plugin/commit/cf14aa2a2f06cf3de1af8ae2a919c60e6d855b89))
* **newsletters:** support constant contact oauth ([#2179](https://github.com/Automattic/newspack-plugin/issues/2179)) ([a896a62](https://github.com/Automattic/newspack-plugin/commit/a896a629ba15feb0a8514332ec3dcfc0aa454b6c))
* **perfmatters:** add an environment feature flag ([774a710](https://github.com/Automattic/newspack-plugin/commit/774a7109ee750dc22d8812d3ce53a7b2687f0dbd))
* **reader-revenue:** add "other" reader revenue platform ([#2160](https://github.com/Automattic/newspack-plugin/issues/2160)) ([1eebe79](https://github.com/Automattic/newspack-plugin/commit/1eebe7970d60bfadca78809b17b4bd70ed024f6d))
* **registration-block:** newsletter subscription by default ([#2198](https://github.com/Automattic/newspack-plugin/issues/2198)) ([b2d5116](https://github.com/Automattic/newspack-plugin/commit/b2d51163054faa2a78dfecaef8d523a840fc71f4))
* **stripe-sync-cli:** create WC subscriptions ([#2180](https://github.com/Automattic/newspack-plugin/issues/2180)) ([0cebe24](https://github.com/Automattic/newspack-plugin/commit/0cebe24be60842e7ff689436ef3f8ac120927311))
* **stripe:** handle donation additional fields ([#2187](https://github.com/Automattic/newspack-plugin/issues/2187)) ([64b96e2](https://github.com/Automattic/newspack-plugin/commit/64b96e2f4c779a92d77f4366131fc801662427d1))
* **stripe:** webhooks management ([#2143](https://github.com/Automattic/newspack-plugin/issues/2143)) ([5ded1f9](https://github.com/Automattic/newspack-plugin/commit/5ded1f95d27f9598b0df74ef60a0f4e9f64774a5))
* use privacy policy URL as default terms URL ([#2176](https://github.com/Automattic/newspack-plugin/issues/2176)) ([9a08231](https://github.com/Automattic/newspack-plugin/commit/9a0823137cfc93cfc27052ccbed76dcc32bc27be))


### Performance Improvements

* optimize analytics JS code ([#2157](https://github.com/Automattic/newspack-plugin/issues/2157)) ([f324500](https://github.com/Automattic/newspack-plugin/commit/f3245008d5f16bd8dcd1b15f38cbecee9e5e91c8))


### Reverts

* hotfix-related auto-changes ([89c0d07](https://github.com/Automattic/newspack-plugin/commit/89c0d072fb5718b5dadb31397ec72779b80cb72c))

## [1.99.1](https://github.com/Automattic/newspack-plugin/compare/v1.99.0...v1.99.1) (2022-12-19)


### Bug Fixes

* **pixel:** proper usage of action hooks ([#2205](https://github.com/Automattic/newspack-plugin/issues/2205)) ([cde052e](https://github.com/Automattic/newspack-plugin/commit/cde052e0fdb0e49297e8016af4cc7760c1ec5147))

# [1.99.0](https://github.com/Automattic/newspack-plugin/compare/v1.98.0...v1.99.0) (2022-12-15)


### Bug Fixes

* **perfmatters:** display defaults for admin only if URL param is set ([1d53a37](https://github.com/Automattic/newspack-plugin/commit/1d53a374f6e5006b0560f097bd9e45fe31b8c31a))


### Features

* **perfmatters:** add an environment feature flag ([b7fcec3](https://github.com/Automattic/newspack-plugin/commit/b7fcec3e46c0d31269ebe8ecc3de1bcb3d62a2fd))

# [1.99.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.98.0...v1.99.0-hotfix.1) (2022-12-15)


### Bug Fixes

* **perfmatters:** display defaults for admin only if URL param is set ([1d53a37](https://github.com/Automattic/newspack-plugin/commit/1d53a374f6e5006b0560f097bd9e45fe31b8c31a))


### Features

* **perfmatters:** add an environment feature flag ([b7fcec3](https://github.com/Automattic/newspack-plugin/commit/b7fcec3e46c0d31269ebe8ecc3de1bcb3d62a2fd))

# [1.98.0](https://github.com/Automattic/newspack-plugin/compare/v1.97.3...v1.98.0) (2022-12-12)


### Bug Fixes

* **analytics:** improve the condition for using Site Kit's Analytics module ([5bc1c36](https://github.com/Automattic/newspack-plugin/commit/5bc1c36f5612896d624ed5ba3089d948c2c7c5a2))
* **auth:** prevent autocomplete for honeypot field ([#2145](https://github.com/Automattic/newspack-plugin/issues/2145)) ([9225726](https://github.com/Automattic/newspack-plugin/commit/92257269ae76f7204878973434e73ff8b270594c))
* harden subs meta box logic ([#2146](https://github.com/Automattic/newspack-plugin/issues/2146)) ([35ea9fe](https://github.com/Automattic/newspack-plugin/commit/35ea9fed48dfbd41a0949b59d7f5dfe270f890f7))
* improved existing Woo user subs handling ([#2162](https://github.com/Automattic/newspack-plugin/issues/2162)) ([bdc25f5](https://github.com/Automattic/newspack-plugin/commit/bdc25f586ea2e8d989edd5e63cd718f2d6785f7d))
* logic to update existing contact on subscription cancellation or deletion ([#2165](https://github.com/Automattic/newspack-plugin/issues/2165)) ([68d7d30](https://github.com/Automattic/newspack-plugin/commit/68d7d3093701635a85a4f2a4a70b5e7ee528ad1c))
* **my-account:** hide account deletion for non-reader users ([85b2267](https://github.com/Automattic/newspack-plugin/commit/85b226740014252f8bcc26ea18db16127f71092a))
* **stripe:** wizard interaction issues ([#2142](https://github.com/Automattic/newspack-plugin/issues/2142)) ([87918a7](https://github.com/Automattic/newspack-plugin/commit/87918a7c2d47b6769f522e47b8206e166db44707))
* test emails ([11ea35a](https://github.com/Automattic/newspack-plugin/commit/11ea35a2acda851c2499392476436028e3e83cee))


### Features

* add support to local lists in the newsletters wizard ([#2153](https://github.com/Automattic/newspack-plugin/issues/2153)) ([9f81194](https://github.com/Automattic/newspack-plugin/commit/9f81194a4072553d734a9686186c447c7b356e36))
* add twitter pixel field ([#2144](https://github.com/Automattic/newspack-plugin/issues/2144)) ([ee30061](https://github.com/Automattic/newspack-plugin/commit/ee30061ac65aefe1aa53c12ed34ffbb9928a573a))
* **amp-plus:** allow OneSignal ([#1076](https://github.com/Automattic/newspack-plugin/issues/1076)) ([790439a](https://github.com/Automattic/newspack-plugin/commit/790439a81444e2db840a74246aa0fe05bcbc2187))
* **analytics:** allow adding custom dimensions' values via filter ([1e20268](https://github.com/Automattic/newspack-plugin/commit/1e202689600b54f94b264eefd85d89104c8a4f41))
* disable jetpack's google analytics ([#2129](https://github.com/Automattic/newspack-plugin/issues/2129)) ([1435b2a](https://github.com/Automattic/newspack-plugin/commit/1435b2a994126c4fb46742fbae4b70f5e813042a))
* perfmatters plugin defaults (with a special URL param) ([#2156](https://github.com/Automattic/newspack-plugin/issues/2156)) ([da00a9b](https://github.com/Automattic/newspack-plugin/commit/da00a9beba8fdb611d8a6cf35b7792754d17fbda))
* **setup-wizard:** disambiguate starter vs. demo content ([ef125ca](https://github.com/Automattic/newspack-plugin/commit/ef125ca2f0fa2d68057d63a7747629083f8988b0)), closes [#1701](https://github.com/Automattic/newspack-plugin/issues/1701)

# [1.98.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.98.0-alpha.3...v1.98.0-alpha.4) (2022-12-09)


### Bug Fixes

* **stripe:** force sync'd subscriptions to manual renewal ([7151986](https://github.com/Automattic/newspack-plugin/commit/715198610d0139fa74181cc2b8cb54c7ad64c085))

## [1.97.3](https://github.com/Automattic/newspack-plugin/compare/v1.97.2...v1.97.3) (2022-12-09)


### Bug Fixes

* **stripe:** force sync'd subscriptions to manual renewal ([7151986](https://github.com/Automattic/newspack-plugin/commit/715198610d0139fa74181cc2b8cb54c7ad64c085))

## [1.97.3-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.97.2...v1.97.3-hotfix.1) (2022-12-07)


### Bug Fixes

* **stripe:** force sync'd subscriptions to manual renewal ([7151986](https://github.com/Automattic/newspack-plugin/commit/715198610d0139fa74181cc2b8cb54c7ad64c085))

## [1.97.2](https://github.com/Automattic/newspack-plugin/compare/v1.97.1...v1.97.2) (2022-12-02)


### Bug Fixes

* fatal if no woo ([#2163](https://github.com/Automattic/newspack-plugin/issues/2163)) ([5284e5b](https://github.com/Automattic/newspack-plugin/commit/5284e5b7dcef6e94fa857ce4631f732e55a29d20))
* force release ([4cb3762](https://github.com/Automattic/newspack-plugin/commit/4cb37621dd768d508e18db1c93ec075efea04cc9))

## [1.97.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.97.1...v1.97.2-hotfix.1) (2022-12-01)


### Bug Fixes

* fatal if no woo ([54fe144](https://github.com/Automattic/newspack-plugin/commit/54fe14461d50d52eb53e8249bddd7e050be7b42e))

## [1.97.1](https://github.com/Automattic/newspack-plugin/compare/v1.97.0...v1.97.1) (2022-11-30)


### Bug Fixes

* force release build ([04c20ff](https://github.com/Automattic/newspack-plugin/commit/04c20ffb595d4e418471781013928353629e2147))
* override woo subscriptions login requirement on checkout ([#2158](https://github.com/Automattic/newspack-plugin/issues/2158)) ([3b17690](https://github.com/Automattic/newspack-plugin/commit/3b17690165635171bd2a273865fcf26f3118a9d1))

## [1.97.1-hotfix.2](https://github.com/Automattic/newspack-plugin/compare/v1.97.1-hotfix.1...v1.97.1-hotfix.2) (2022-11-30)


### Bug Fixes

* only do temp login if Woo intends to create an account ([1fbe56c](https://github.com/Automattic/newspack-plugin/commit/1fbe56c33c08c3fae714de152a92aaab881e0f9d))

## [1.97.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.97.0...v1.97.1-hotfix.1) (2022-11-30)


### Bug Fixes

* allow WC subscription purchase without logging in ([7362c56](https://github.com/Automattic/newspack-plugin/commit/7362c562104e5c410417752e0e7e1502d14b5a9e))
* update default registration success message ([c971b14](https://github.com/Automattic/newspack-plugin/commit/c971b14bf70d940b091fbc347bc2a6f0c1cbc67b))

# [1.97.0](https://github.com/Automattic/newspack-plugin/compare/v1.96.0...v1.97.0) (2022-11-28)


### Bug Fixes

* delay receipt sending until Stripe webhook handler succeeds ([#2112](https://github.com/Automattic/newspack-plugin/issues/2112)) ([3f49eca](https://github.com/Automattic/newspack-plugin/commit/3f49ecad75287675929ee2d0cfe800c69e54ba99))
* revert non reader login ([#2133](https://github.com/Automattic/newspack-plugin/issues/2133)) ([3da696b](https://github.com/Automattic/newspack-plugin/commit/3da696b8e74815bbaa8941d3487b1fe16b553ab9)), closes [#2131](https://github.com/Automattic/newspack-plugin/issues/2131)
* update CI orb newspack-scripts ([a474dc7](https://github.com/Automattic/newspack-plugin/commit/a474dc7314006357646324170d8bed8e118df1ac))
* **woo:** disable new order email on subscription renewals ([2fee169](https://github.com/Automattic/newspack-plugin/commit/2fee169d9c7be1cd3af49b7d76a690afea7ea863))
* **woo:** use variable post meta table name ([203593f](https://github.com/Automattic/newspack-plugin/commit/203593fabc894e57d132a46dad2201693aba7bab))


### Features

* capture donor names in Stripe description field ([#2130](https://github.com/Automattic/newspack-plugin/issues/2130)) ([f1e16c8](https://github.com/Automattic/newspack-plugin/commit/f1e16c8a1fb446b7c6fbeadc44b56aff9535e324))
* **plugin-screen:** improve the pre-install prompt ([20e5dc5](https://github.com/Automattic/newspack-plugin/commit/20e5dc5c7c2be15c30ac63ec493e3f560ff9b994))
* remove Stripe subscriptions on WP account deletion ([#2021](https://github.com/Automattic/newspack-plugin/issues/2021)) ([0acbed5](https://github.com/Automattic/newspack-plugin/commit/0acbed5f30cf7128ee02c82c7c45b98ed3a2cc31))

# [1.97.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.96.0...v1.97.0-alpha.1) (2022-11-17)


### Bug Fixes

* delay receipt sending until Stripe webhook handler succeeds ([#2112](https://github.com/Automattic/newspack-plugin/issues/2112)) ([3f49eca](https://github.com/Automattic/newspack-plugin/commit/3f49ecad75287675929ee2d0cfe800c69e54ba99))
* revert non reader login ([#2133](https://github.com/Automattic/newspack-plugin/issues/2133)) ([3da696b](https://github.com/Automattic/newspack-plugin/commit/3da696b8e74815bbaa8941d3487b1fe16b553ab9)), closes [#2131](https://github.com/Automattic/newspack-plugin/issues/2131)
* update CI orb newspack-scripts ([a474dc7](https://github.com/Automattic/newspack-plugin/commit/a474dc7314006357646324170d8bed8e118df1ac))
* **woo:** disable new order email on subscription renewals ([2fee169](https://github.com/Automattic/newspack-plugin/commit/2fee169d9c7be1cd3af49b7d76a690afea7ea863))
* **woo:** use variable post meta table name ([203593f](https://github.com/Automattic/newspack-plugin/commit/203593fabc894e57d132a46dad2201693aba7bab))


### Features

* capture donor names in Stripe description field ([#2130](https://github.com/Automattic/newspack-plugin/issues/2130)) ([f1e16c8](https://github.com/Automattic/newspack-plugin/commit/f1e16c8a1fb446b7c6fbeadc44b56aff9535e324))
* **plugin-screen:** improve the pre-install prompt ([20e5dc5](https://github.com/Automattic/newspack-plugin/commit/20e5dc5c7c2be15c30ac63ec493e3f560ff9b994))
* remove Stripe subscriptions on WP account deletion ([#2021](https://github.com/Automattic/newspack-plugin/issues/2021)) ([0acbed5](https://github.com/Automattic/newspack-plugin/commit/0acbed5f30cf7128ee02c82c7c45b98ed3a2cc31))

# [1.96.0](https://github.com/Automattic/newspack-plugin/compare/v1.95.4...v1.96.0) (2022-11-14)


### Bug Fixes

* allow Stripe Billing portal to render without RAS ([b6e22cd](https://github.com/Automattic/newspack-plugin/commit/b6e22cdbc021a475cf2c347513fd9d80b6759082))
* apply ex-donor status only if user has no active subscriptions ([#2101](https://github.com/Automattic/newspack-plugin/issues/2101)) ([40399ea](https://github.com/Automattic/newspack-plugin/commit/40399eaf763809062ed2248f3837b7c565ece2cd))
* apply My Account template changes only when RAS is active ([cd7b304](https://github.com/Automattic/newspack-plugin/commit/cd7b30404ce35eed28bb7b2f7de07047077e267b))
* package lock ([8707e53](https://github.com/Automattic/newspack-plugin/commit/8707e53ad0d63daab93d503ecb302240ace40c86))
* **stripe:** handle failed invoice fatal error ([#2103](https://github.com/Automattic/newspack-plugin/issues/2103)) ([f865428](https://github.com/Automattic/newspack-plugin/commit/f865428a9a1e2ae5027a19e6461940d4d1b6f4c4))
* **Stripe:** improve handling of invoice error ([#2117](https://github.com/Automattic/newspack-plugin/issues/2117)) ([609bf7d](https://github.com/Automattic/newspack-plugin/commit/609bf7d9a23afaa92374403c23fec6e072e5e636))
* update newspack-scripts to fix CI builds ([#2109](https://github.com/Automattic/newspack-plugin/issues/2109)) ([ec1ee7a](https://github.com/Automattic/newspack-plugin/commit/ec1ee7a4abf0964015bb6c2dd7aea5b0424cfc2e))
* use default terms text in reg block if no text defined ([#2078](https://github.com/Automattic/newspack-plugin/issues/2078)) ([443c59c](https://github.com/Automattic/newspack-plugin/commit/443c59c251f53ac590cd578f50312937eb85cec2))


### Features

* enable setting user custom fields for job title etc. ([#2102](https://github.com/Automattic/newspack-plugin/issues/2102)) ([4018af6](https://github.com/Automattic/newspack-plugin/commit/4018af61acbc8a952355a5b138808618de3efc26)), closes [#1723](https://github.com/Automattic/newspack-plugin/issues/1723)
* **newsletter-contact:** disallow overriding membership status field ([#2093](https://github.com/Automattic/newspack-plugin/issues/2093)) ([8b8e134](https://github.com/Automattic/newspack-plugin/commit/8b8e134c75cfd2ae956e640615106c884d8d3204))

# [1.96.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.96.0-alpha.3...v1.96.0-alpha.4) (2022-11-07)


### Bug Fixes

* **stripe:** invoice error handling ([#2119](https://github.com/Automattic/newspack-plugin/issues/2119)) ([8855681](https://github.com/Automattic/newspack-plugin/commit/8855681d92794c4b01835627328d9a39c8fc2d10))

## [1.95.4](https://github.com/Automattic/newspack-plugin/compare/v1.95.3...v1.95.4) (2022-11-07)


### Bug Fixes

* **stripe:** invoice error handling ([#2119](https://github.com/Automattic/newspack-plugin/issues/2119)) ([8855681](https://github.com/Automattic/newspack-plugin/commit/8855681d92794c4b01835627328d9a39c8fc2d10))

## [1.95.3](https://github.com/Automattic/newspack-plugin/compare/v1.95.2...v1.95.3) (2022-11-04)


### Bug Fixes

* update newspack-scripts to fix CI builds ([#2110](https://github.com/Automattic/newspack-plugin/issues/2110)) ([c395ddb](https://github.com/Automattic/newspack-plugin/commit/c395ddb84064bc17ed78330ce8b24371455aa083))

## [1.95.3-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.95.2...v1.95.3-hotfix.1) (2022-11-04)


### Bug Fixes

* update newspack-scripts to fix CI builds ([#2109](https://github.com/Automattic/newspack-plugin/issues/2109)) ([bc1e44a](https://github.com/Automattic/newspack-plugin/commit/bc1e44a6cf9eb561b08a7b717c9de7d2c9bb160b))

## [1.95.2](https://github.com/Automattic/newspack-plugin/compare/v1.95.1...v1.95.2) (2022-11-03)


### Bug Fixes

* **oauth:** change Google's `dfp` scope to `admanager` ([#2106](https://github.com/Automattic/newspack-plugin/issues/2106)) ([703623e](https://github.com/Automattic/newspack-plugin/commit/703623ebfa19c2beedb2ae9d1535300c01298a61))

## [1.95.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.95.1...v1.95.2-hotfix.1) (2022-11-03)


### Bug Fixes

* **oauth:** change Google's `dfp` scope to `admanager` ([46e5eea](https://github.com/Automattic/newspack-plugin/commit/46e5eea6da738e59c2d56d3d34c2f8c1abe25bc0))

## [1.95.1](https://github.com/Automattic/newspack-plugin/compare/v1.95.0...v1.95.1) (2022-10-31)


### Bug Fixes

* **oauth:** log missing google scopes ([#2099](https://github.com/Automattic/newspack-plugin/issues/2099)) ([642b1c5](https://github.com/Automattic/newspack-plugin/commit/642b1c5a02f45d94fcf5d5173aac9998d4ae4e53))

## [1.95.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.95.0...v1.95.1-hotfix.1) (2022-10-31)


### Bug Fixes

* **oauth:** log missing google scopes ([831d475](https://github.com/Automattic/newspack-plugin/commit/831d475a175f55eff3c89a7e3bf403cee1e0626b))

# [1.95.0](https://github.com/Automattic/newspack-plugin/compare/v1.94.0...v1.95.0) (2022-10-31)


### Bug Fixes

* "Sign In" page styles ([#2075](https://github.com/Automattic/newspack-plugin/issues/2075)) ([04ce12c](https://github.com/Automattic/newspack-plugin/commit/04ce12c9a1423e66aaa6953d7c8ac5e921bf9e1c))
* **ads:** rename GAM methods ([#2074](https://github.com/Automattic/newspack-plugin/issues/2074)) ([6e7cb90](https://github.com/Automattic/newspack-plugin/commit/6e7cb90e3b8a5d39e128bbf58cb91b3d8c1b8fe5))
* check Woo plugins status before enabling RAS ([#2076](https://github.com/Automattic/newspack-plugin/issues/2076)) ([3aedbd4](https://github.com/Automattic/newspack-plugin/commit/3aedbd486ac1e3522256bb0a4a9765e01988d8c6))
* overzealous woo check for RAS front-end ([#2080](https://github.com/Automattic/newspack-plugin/issues/2080)) ([6b4c253](https://github.com/Automattic/newspack-plugin/commit/6b4c253f5974012572d7adce40d21dd5adff1aff))
* show display name in My Account UI ([#2079](https://github.com/Automattic/newspack-plugin/issues/2079)) ([91fd1b8](https://github.com/Automattic/newspack-plugin/commit/91fd1b88045b565446801425314318dfd4add6a3))
* sql error with starter content queries ([#2077](https://github.com/Automattic/newspack-plugin/issues/2077)) ([84665ed](https://github.com/Automattic/newspack-plugin/commit/84665ed398e9daea60c8f76ddbb5cb340f75d117))


### Features

* Add Meta pixel settings section (aka Facebook pixel) ([f6e8bca](https://github.com/Automattic/newspack-plugin/commit/f6e8bca4c1ba6c40d370cf3b2deb0a56d5a9d343)), closes [#1994](https://github.com/Automattic/newspack-plugin/issues/1994)
* **ads:** publisher media kit add-on ([#2048](https://github.com/Automattic/newspack-plugin/issues/2048)) ([709e66c](https://github.com/Automattic/newspack-plugin/commit/709e66cb7037fb8e54b6e93830e287f5a22aaec1))
* Allow user to mark revisions as Major revisions and they'll never be deleted ([#2033](https://github.com/Automattic/newspack-plugin/issues/2033)) ([174ea7f](https://github.com/Automattic/newspack-plugin/commit/174ea7f121dc08729296b6381e766771825d5025))
* **reader-auth:** improved otp ux ([#2043](https://github.com/Automattic/newspack-plugin/issues/2043)) ([c89a467](https://github.com/Automattic/newspack-plugin/commit/c89a4674809fe723b7400919ffe9c3e6400b99f4))
* **stripe:** synchronise with WooCommerce Subscriptions ([#1936](https://github.com/Automattic/newspack-plugin/issues/1936)) ([6526770](https://github.com/Automattic/newspack-plugin/commit/652677029d6ea6ce0b7c7d1b2121eff76ed6f0b6))

# [1.95.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.94.0...v1.95.0-alpha.1) (2022-10-21)


### Bug Fixes

* "Sign In" page styles ([#2075](https://github.com/Automattic/newspack-plugin/issues/2075)) ([04ce12c](https://github.com/Automattic/newspack-plugin/commit/04ce12c9a1423e66aaa6953d7c8ac5e921bf9e1c))
* **ads:** rename GAM methods ([#2074](https://github.com/Automattic/newspack-plugin/issues/2074)) ([6e7cb90](https://github.com/Automattic/newspack-plugin/commit/6e7cb90e3b8a5d39e128bbf58cb91b3d8c1b8fe5))
* check Woo plugins status before enabling RAS ([#2076](https://github.com/Automattic/newspack-plugin/issues/2076)) ([3aedbd4](https://github.com/Automattic/newspack-plugin/commit/3aedbd486ac1e3522256bb0a4a9765e01988d8c6))
* overzealous woo check for RAS front-end ([#2080](https://github.com/Automattic/newspack-plugin/issues/2080)) ([6b4c253](https://github.com/Automattic/newspack-plugin/commit/6b4c253f5974012572d7adce40d21dd5adff1aff))
* show display name in My Account UI ([#2079](https://github.com/Automattic/newspack-plugin/issues/2079)) ([91fd1b8](https://github.com/Automattic/newspack-plugin/commit/91fd1b88045b565446801425314318dfd4add6a3))
* sql error with starter content queries ([#2077](https://github.com/Automattic/newspack-plugin/issues/2077)) ([84665ed](https://github.com/Automattic/newspack-plugin/commit/84665ed398e9daea60c8f76ddbb5cb340f75d117))


### Features

* Add Meta pixel settings section (aka Facebook pixel) ([f6e8bca](https://github.com/Automattic/newspack-plugin/commit/f6e8bca4c1ba6c40d370cf3b2deb0a56d5a9d343)), closes [#1994](https://github.com/Automattic/newspack-plugin/issues/1994)
* **ads:** publisher media kit add-on ([#2048](https://github.com/Automattic/newspack-plugin/issues/2048)) ([709e66c](https://github.com/Automattic/newspack-plugin/commit/709e66cb7037fb8e54b6e93830e287f5a22aaec1))
* Allow user to mark revisions as Major revisions and they'll never be deleted ([#2033](https://github.com/Automattic/newspack-plugin/issues/2033)) ([174ea7f](https://github.com/Automattic/newspack-plugin/commit/174ea7f121dc08729296b6381e766771825d5025))
* **reader-auth:** improved otp ux ([#2043](https://github.com/Automattic/newspack-plugin/issues/2043)) ([c89a467](https://github.com/Automattic/newspack-plugin/commit/c89a4674809fe723b7400919ffe9c3e6400b99f4))
* **stripe:** synchronise with WooCommerce Subscriptions ([#1936](https://github.com/Automattic/newspack-plugin/issues/1936)) ([6526770](https://github.com/Automattic/newspack-plugin/commit/652677029d6ea6ce0b7c7d1b2121eff76ed6f0b6))

# [1.94.0](https://github.com/Automattic/newspack-plugin/compare/v1.93.2...v1.94.0) (2022-10-19)


### Bug Fixes

* "Sign In" page styles ([#2075](https://github.com/Automattic/newspack-plugin/issues/2075)) ([b7a453a](https://github.com/Automattic/newspack-plugin/commit/b7a453a2be38dd102dcf319f633d663677687012))
* **auth:** ensure OTP on reader state changes ([#2053](https://github.com/Automattic/newspack-plugin/issues/2053)) ([0f8d7ed](https://github.com/Automattic/newspack-plugin/commit/0f8d7ed45e9d90beee6d8fde0f34ce7268b74785))
* **reader-activation:** checkbox alignment ([#2057](https://github.com/Automattic/newspack-plugin/issues/2057)) ([6059956](https://github.com/Automattic/newspack-plugin/commit/6059956d77efc4e21106e6ea8069978219c2a79e))
* **reader-activation:** vertical alignment of the icon ([#2056](https://github.com/Automattic/newspack-plugin/issues/2056)) ([4257f60](https://github.com/Automattic/newspack-plugin/commit/4257f6064857bfd3f415baaac04d7cd078c16a39))
* **wizards:** rename advertising scripts and API paths so they're not blocked by adblocker ([dba4119](https://github.com/Automattic/newspack-plugin/commit/dba41193f43481e4182f688893cbf787712e5fcc))


### Features

* **ads:** expand suppression options ([#1915](https://github.com/Automattic/newspack-plugin/issues/1915)) ([f6e012b](https://github.com/Automattic/newspack-plugin/commit/f6e012bb104ef28d4a3da8d3e08cd9e2428884d8))
* more prominent already-have-account section ([#2025](https://github.com/Automattic/newspack-plugin/issues/2025)) ([23f5d7f](https://github.com/Automattic/newspack-plugin/commit/23f5d7f6c76e9df6573979d97be1087fd5725a63))
* **plugins-screen:** update button to use primary style ([#2037](https://github.com/Automattic/newspack-plugin/issues/2037)) ([9827ff3](https://github.com/Automattic/newspack-plugin/commit/9827ff311384278fdd7d2f178272b7e476949243))
* show unregistered RAS UI to admins ([#2064](https://github.com/Automattic/newspack-plugin/issues/2064)) ([9352c23](https://github.com/Automattic/newspack-plugin/commit/9352c2322059f313b1cea2dc79c37bdc022c4015))
* update woocommerce account details and use cards ([#2063](https://github.com/Automattic/newspack-plugin/issues/2063)) ([81bb8be](https://github.com/Automattic/newspack-plugin/commit/81bb8bec9608c6f4145cc332bb4931415a456fa4))

# [1.94.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.94.0-alpha.3...v1.94.0-alpha.4) (2022-10-19)


### Bug Fixes

* "Sign In" page styles ([#2075](https://github.com/Automattic/newspack-plugin/issues/2075)) ([b7a453a](https://github.com/Automattic/newspack-plugin/commit/b7a453a2be38dd102dcf319f633d663677687012))

# [1.94.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.94.0-alpha.2...v1.94.0-alpha.3) (2022-10-10)


### Bug Fixes

* **stripe-wc:** prevent duplicate orders creation ([041d711](https://github.com/Automattic/newspack-plugin/commit/041d7116d0158e3e8e84e4192e98aee00eca7e42))
* **stripe:** handle sync errors ([329de96](https://github.com/Automattic/newspack-plugin/commit/329de960cae933d1b0341433dd20b71942abfec0))

## [1.93.2](https://github.com/Automattic/newspack-plugin/compare/v1.93.1...v1.93.2) (2022-10-10)


### Bug Fixes

* **stripe-wc:** prevent duplicate orders creation ([041d711](https://github.com/Automattic/newspack-plugin/commit/041d7116d0158e3e8e84e4192e98aee00eca7e42))
* **stripe:** handle sync errors ([329de96](https://github.com/Automattic/newspack-plugin/commit/329de960cae933d1b0341433dd20b71942abfec0))

## [1.93.1](https://github.com/Automattic/newspack-plugin/compare/v1.93.0...v1.93.1) (2022-10-06)


### Bug Fixes

* fire ini-load analytics events on DOMContentLoaded, not load ([cc2485d](https://github.com/Automattic/newspack-plugin/commit/cc2485d20cc80afabf760fa154d5a198fcb552b5))
* force hotfix release ([e68b169](https://github.com/Automattic/newspack-plugin/commit/e68b169a5df18bb66c2183f70441cb21ca39fd3f))

# [1.93.0](https://github.com/Automattic/newspack-plugin/compare/v1.92.1...v1.93.0) (2022-09-28)


### Features

* custom email sender for reader activation ([#2052](https://github.com/Automattic/newspack-plugin/issues/2052)) ([5fde94f](https://github.com/Automattic/newspack-plugin/commit/5fde94f4afb29dedf4ef735ab1a762e401338d91))

# [1.93.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.92.1...v1.93.0-hotfix.1) (2022-09-28)


### Features

* custom email sender for reader activation ([3ee10b7](https://github.com/Automattic/newspack-plugin/commit/3ee10b79bafc1eac2e3995f880cc7d80c476efcc))
* custom from name ([63186d2](https://github.com/Automattic/newspack-plugin/commit/63186d24a47089f3c47b860cad8e6ab7c060b47b))
* custom receipts sender ([378f944](https://github.com/Automattic/newspack-plugin/commit/378f944622d1c7b27527959c0c2fd18959dbc4bb))

## [1.92.1](https://github.com/Automattic/newspack-plugin/compare/v1.92.0...v1.92.1) (2022-09-28)


### Bug Fixes

* disable author filter for co-authors ([#2050](https://github.com/Automattic/newspack-plugin/issues/2050)) ([695e16f](https://github.com/Automattic/newspack-plugin/commit/695e16f24a27310df6ceddbbefd311f8572128d2))

# [1.92.0](https://github.com/Automattic/newspack-plugin/compare/v1.91.2...v1.92.0) (2022-09-27)


### Bug Fixes

* **analytics:** remove illegal reference on wizard ([#1996](https://github.com/Automattic/newspack-plugin/issues/1996)) ([bec14a2](https://github.com/Automattic/newspack-plugin/commit/bec14a22ebb6d8c2c37de6bdd920d5fb13e0c440))
* **reader-activation-auth:** header link color ([6e85ada](https://github.com/Automattic/newspack-plugin/commit/6e85adafa814e0149062e589d24969467d5b53db))
* **stripe:** handle error if customer LTV is not retrievable ([2f9f4a4](https://github.com/Automattic/newspack-plugin/commit/2f9f4a44e45c80eb01a326baaa78be866e9b55ab))
* **stripe:** validate webhook existence on donation ([#2026](https://github.com/Automattic/newspack-plugin/issues/2026)) ([c505fbc](https://github.com/Automattic/newspack-plugin/commit/c505fbcc19a98f73e55fbbd421f343e12ac3dd7f))
* **wc-to-ac-metadata:** add last payment date field for one-time donors ([0106915](https://github.com/Automattic/newspack-plugin/commit/01069153abfd24dc61f01e82aa43dd6ceb6a5d93))
* **wc-to-ac-metadata:** disregard `0` next payment date ([6e43db1](https://github.com/Automattic/newspack-plugin/commit/6e43db1c52f41e51316aa6b5233e8e445d7a506b))


### Features

* Adds a filter by Author in the Posts admin page ([bc49686](https://github.com/Automattic/newspack-plugin/commit/bc496868e9a2d438303699db45972f438ad2ff5c))
* **auth:** otp embedded to magic links ([#1988](https://github.com/Automattic/newspack-plugin/issues/1988)) ([d6e072c](https://github.com/Automattic/newspack-plugin/commit/d6e072ccd18b2cff2e3dda97e371eedb1187afab))
* do not redirect resubscribe requests ([#1989](https://github.com/Automattic/newspack-plugin/issues/1989)) ([53da790](https://github.com/Automattic/newspack-plugin/commit/53da790dbc0f4b06fb5a18b15f03e7a121a1909d))
* enable limit post revisions kept in the database ([#2030](https://github.com/Automattic/newspack-plugin/issues/2030)) ([44680c5](https://github.com/Automattic/newspack-plugin/commit/44680c5c8b43a85de44e8d70d7ffce6a2ef69528))
* **plugins-screen:** display a plugin review form, if URL supplied ([#2005](https://github.com/Automattic/newspack-plugin/issues/2005)) ([77d3aa0](https://github.com/Automattic/newspack-plugin/commit/77d3aa06054627bd5d6d1d6f2385be6746f02beb))
* **plugins:** handle missing organic-profile-block plugin ([2475ad0](https://github.com/Automattic/newspack-plugin/commit/2475ad08f5191608401e2f2b486bd670dd82c732))
* stripe-to-stripe migrator ([a82f326](https://github.com/Automattic/newspack-plugin/commit/a82f326b98bab5b08581dfd347505f7f42f237aa))
* **stripe:** send data about cancelled subscription to Campaigns ([#1966](https://github.com/Automattic/newspack-plugin/issues/1966)) ([0554487](https://github.com/Automattic/newspack-plugin/commit/055448741cba1e7830fc839bd3a76f5b6521ecf1))
* sync from Stripe to ESP CLI tool ([#1984](https://github.com/Automattic/newspack-plugin/issues/1984)) ([c10fbc7](https://github.com/Automattic/newspack-plugin/commit/c10fbc7c8d17d11dfbffe1a36dc1e4cfa5e6332c))
* **woocommerce:** handle metadata when creating a membership ([af75438](https://github.com/Automattic/newspack-plugin/commit/af75438dca6305b77d2d034f67199c5ead34dbce))

# [1.92.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.92.0-alpha.3...v1.92.0-alpha.4) (2022-09-26)


### Bug Fixes

* **reader-activation-auth:** header link color ([6e85ada](https://github.com/Automattic/newspack-plugin/commit/6e85adafa814e0149062e589d24969467d5b53db))
* **stripe:** handle error if customer LTV is not retrievable ([2f9f4a4](https://github.com/Automattic/newspack-plugin/commit/2f9f4a44e45c80eb01a326baaa78be866e9b55ab))
* **stripe:** validate webhook existence on donation ([#2026](https://github.com/Automattic/newspack-plugin/issues/2026)) ([c505fbc](https://github.com/Automattic/newspack-plugin/commit/c505fbcc19a98f73e55fbbd421f343e12ac3dd7f))
* **wc-to-ac-metadata:** add last payment date field for one-time donors ([0106915](https://github.com/Automattic/newspack-plugin/commit/01069153abfd24dc61f01e82aa43dd6ceb6a5d93))


### Features

* enable limit post revisions kept in the database ([#2030](https://github.com/Automattic/newspack-plugin/issues/2030)) ([44680c5](https://github.com/Automattic/newspack-plugin/commit/44680c5c8b43a85de44e8d70d7ffce6a2ef69528))
* **plugins-screen:** display a plugin review form, if URL supplied ([#2005](https://github.com/Automattic/newspack-plugin/issues/2005)) ([77d3aa0](https://github.com/Automattic/newspack-plugin/commit/77d3aa06054627bd5d6d1d6f2385be6746f02beb))
* stripe-to-stripe migrator ([a82f326](https://github.com/Automattic/newspack-plugin/commit/a82f326b98bab5b08581dfd347505f7f42f237aa))
* sync from Stripe to ESP CLI tool ([#1984](https://github.com/Automattic/newspack-plugin/issues/1984)) ([c10fbc7](https://github.com/Automattic/newspack-plugin/commit/c10fbc7c8d17d11dfbffe1a36dc1e4cfa5e6332c))
* **woocommerce:** handle metadata when creating a membership ([af75438](https://github.com/Automattic/newspack-plugin/commit/af75438dca6305b77d2d034f67199c5ead34dbce))

# [1.92.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.92.0-alpha.2...v1.92.0-alpha.3) (2022-09-23)


### Bug Fixes

* **analytics:** improve reliability of custom events reporting in AMP ([#2034](https://github.com/Automattic/newspack-plugin/issues/2034)) ([746fdda](https://github.com/Automattic/newspack-plugin/commit/746fdda6c1e2f7ac07192bf30bbf50c88b6f767e))

## [1.91.2](https://github.com/Automattic/newspack-plugin/compare/v1.91.1...v1.91.2) (2022-09-23)


### Bug Fixes

* **analytics:** improve reliability of custom events reporting in AMP ([#2034](https://github.com/Automattic/newspack-plugin/issues/2034)) ([746fdda](https://github.com/Automattic/newspack-plugin/commit/746fdda6c1e2f7ac07192bf30bbf50c88b6f767e))

## [1.91.2-hotfix.2](https://github.com/Automattic/newspack-plugin/compare/v1.91.2-hotfix.1...v1.91.2-hotfix.2) (2022-09-23)


### Bug Fixes

* remove filter priority ([92ad712](https://github.com/Automattic/newspack-plugin/commit/92ad71276e78da40ad96486f0036c3101e00a494))

## [1.91.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.91.1...v1.91.2-hotfix.1) (2022-09-23)


### Bug Fixes

* **analytics:** improve reliability of custom events reporting in AMP ([a3d5982](https://github.com/Automattic/newspack-plugin/commit/a3d59829ed9c53e81f5ca89899b87e68cb19b43e))

## [1.91.1](https://github.com/Automattic/newspack-plugin/compare/v1.91.0...v1.91.1) (2022-09-21)


### Bug Fixes

* move improved frequency options out of experimental state ([45b0fe6](https://github.com/Automattic/newspack-plugin/commit/45b0fe62ae51023a21bf789873fc441d1bbea47d))

# [1.91.0](https://github.com/Automattic/newspack-plugin/compare/v1.90.0...v1.91.0) (2022-09-15)


### Features

* stop auto-email on registration and rate limit unverified accounts ([#2004](https://github.com/Automattic/newspack-plugin/issues/2004)) ([b518874](https://github.com/Automattic/newspack-plugin/commit/b518874f3935a1397daf999d934b06ce0e795a51))

# [1.91.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.90.0...v1.91.0-hotfix.1) (2022-09-15)


### Features

* stop auto-email on registration and rate limit unverified accounts ([c6a2566](https://github.com/Automattic/newspack-plugin/commit/c6a256660ffaaeed115a6933377ee768f76e2487))

# [1.90.0](https://github.com/Automattic/newspack-plugin/compare/v1.89.1...v1.90.0) (2022-09-14)


### Bug Fixes

* **ac-master-list:** handle empty lists ([cfabb5a](https://github.com/Automattic/newspack-plugin/commit/cfabb5a7a90848c209c40b35749504f2feaded4c))
* add check to only change theme if not empty ([#1978](https://github.com/Automattic/newspack-plugin/issues/1978)) ([b9835f1](https://github.com/Automattic/newspack-plugin/commit/b9835f194de5759bf975343e5a3cb9bfe79b8f16))
* allow Register block to be edited even if RAS is not enabled ([#1962](https://github.com/Automattic/newspack-plugin/issues/1962)) ([649f47b](https://github.com/Automattic/newspack-plugin/commit/649f47b57965121aa0b11f11a7e1c3aaeebd00f1))
* **emails:** don't create post if pluggable functions are not available ([#1979](https://github.com/Automattic/newspack-plugin/issues/1979)) ([d8aac4f](https://github.com/Automattic/newspack-plugin/commit/d8aac4fb7d18313efb025592e25d1187d1a298e3))
* **emails:** editor message ([62dec52](https://github.com/Automattic/newspack-plugin/commit/62dec5226b7413afd62da173ace49624425cc0a8))
* **google-oauth:** missing email message ([#1925](https://github.com/Automattic/newspack-plugin/issues/1925)) ([93260a3](https://github.com/Automattic/newspack-plugin/commit/93260a368717b36d9f14f835856bbc3790557b38))
* horizontal scrollbar on auth modal ([#1919](https://github.com/Automattic/newspack-plugin/issues/1919)) ([08a7bb6](https://github.com/Automattic/newspack-plugin/commit/08a7bb612a58d7d02b69ed241fe82273ab7c5104))
* if logging in from an overlay prompt, dismiss the prompt after login ([#1927](https://github.com/Automattic/newspack-plugin/issues/1927)) ([999437d](https://github.com/Automattic/newspack-plugin/commit/999437db7fed57ea43e5edde9daba574c2c27283))
* localized reader auth error messages ([#1948](https://github.com/Automattic/newspack-plugin/issues/1948)) ([fb58a7f](https://github.com/Automattic/newspack-plugin/commit/fb58a7f6b7b2cfdfe13845330de9ed87f83e8f8b))
* post-logout messaging ([#1934](https://github.com/Automattic/newspack-plugin/issues/1934)) ([11e6917](https://github.com/Automattic/newspack-plugin/commit/11e691728dd9f46e29a89e9548703de5ed5a4afb))
* prefix WC My Account actions so we can decide which hooks to support ([#1963](https://github.com/Automattic/newspack-plugin/issues/1963)) ([0fa8c0a](https://github.com/Automattic/newspack-plugin/commit/0fa8c0ada1d7d5fc9ef8ce2388c48d0fac2d0c4a))
* **reader-activation:** send payment contact metadata only if RA is enabled ([#1957](https://github.com/Automattic/newspack-plugin/issues/1957)) ([3e14777](https://github.com/Automattic/newspack-plugin/commit/3e1477742f1c8cc6819282054c99f35e36b47803))
* register and auth form tweaks ([#1935](https://github.com/Automattic/newspack-plugin/issues/1935)) ([3b76d3f](https://github.com/Automattic/newspack-plugin/commit/3b76d3f70c2b3cbb761e6d56f5432143cc7075a4))
* **registration-block:** allow any markup in the success state ([977e77a](https://github.com/Automattic/newspack-plugin/commit/977e77a75c266e636cf764bc67061b7d51f563f1))
* **registration-block:** column layout in editor ([#1920](https://github.com/Automattic/newspack-plugin/issues/1920)) ([f1ae0c5](https://github.com/Automattic/newspack-plugin/commit/f1ae0c5894f9fc571e9e50b44d984d90476243a6))
* **registration-block:** fix empty success state ([272aee5](https://github.com/Automattic/newspack-plugin/commit/272aee5c7b291240beb067afcc2401c3992264f4))
* show My Account messages using custom messaging instead of WC messaging ([#1932](https://github.com/Automattic/newspack-plugin/issues/1932)) ([c9802fe](https://github.com/Automattic/newspack-plugin/commit/c9802fe1ea494872253fe148b86ccb0dfaa9660b))
* **stripe:** handle WC-originating Stripe transactions ([674b278](https://github.com/Automattic/newspack-plugin/commit/674b2788a45a13c8fae8d0b2fd8ec4b22b1a04ae))
* **WooCommerce:** error notice text color ([#1954](https://github.com/Automattic/newspack-plugin/issues/1954)) ([2e4b95f](https://github.com/Automattic/newspack-plugin/commit/2e4b95f52602529faef051c804d95891f02ed6e2))


### Features

* **ac-metadata:** send SSO provider name as "NP_Connected Account" field ([56b6597](https://github.com/Automattic/newspack-plugin/commit/56b6597426f75b75205490acad7284119045b03d))
* **ac-metadata:** send Stripe customer LTV as "NP_Total Paid" field ([5f6da59](https://github.com/Automattic/newspack-plugin/commit/5f6da59f02897284ea5cefdc45d5dfb4a2e5bbd1))
* after account deletion message ([2547b76](https://github.com/Automattic/newspack-plugin/commit/2547b76373b620e9852040bdcb68c28e49a3657d))
* **analytics:** prevent sending NTG newsletter event if subscribing to master list ([#1946](https://github.com/Automattic/newspack-plugin/issues/1946)) ([806a8b0](https://github.com/Automattic/newspack-plugin/commit/806a8b0a07c4496fd2f209536d0b78ad42b7ce1a))
* custom messaging for reader without password ([#1965](https://github.com/Automattic/newspack-plugin/issues/1965)) ([a584b59](https://github.com/Automattic/newspack-plugin/commit/a584b597f6d0e067e60734c42b9b03eac1346e4c))
* disable mailchimp-for-woocommerce plugin campaign tracking cookie ([#618](https://github.com/Automattic/newspack-plugin/issues/618)) ([99310cb](https://github.com/Automattic/newspack-plugin/commit/99310cb6f343a9801c0ba0c3b8916e5ff5331ae2))
* for donations via a prompt, add prompt ID to event label ([#1928](https://github.com/Automattic/newspack-plugin/issues/1928)) ([4704cfd](https://github.com/Automattic/newspack-plugin/commit/4704cfd21f793717c10433056a6e425a84b0e574))
* give each registration form a unique ID ([#1953](https://github.com/Automattic/newspack-plugin/issues/1953)) ([29fb515](https://github.com/Automattic/newspack-plugin/commit/29fb515534ff5036b7c734de1267d8e5f3f3c05d))
* **google-sitekit:** prevent excluding logged-in users from GA if RA is enabled ([#1960](https://github.com/Automattic/newspack-plugin/issues/1960)) ([3b18bcc](https://github.com/Automattic/newspack-plugin/commit/3b18bcc400439b0036440994f0db226ce7eee559))
* honeypot trap for auth and registration forms ([#1896](https://github.com/Automattic/newspack-plugin/issues/1896)) ([d5d713c](https://github.com/Automattic/newspack-plugin/commit/d5d713ca0b4715924136265d36dfdeb19954fa1a))
* move have account text below SSO and adjust font-size ([#1917](https://github.com/Automattic/newspack-plugin/issues/1917)) ([869913d](https://github.com/Automattic/newspack-plugin/commit/869913dec1156492569ccd4eda56b29401384c3c))
* new option for minimum donation amount ([#1895](https://github.com/Automattic/newspack-plugin/issues/1895)) ([0b9618b](https://github.com/Automattic/newspack-plugin/commit/0b9618b25d76b64619d25f2077466030f126b477))
* reader account deletion and ESP sync options ([#1884](https://github.com/Automattic/newspack-plugin/issues/1884)) ([b9fd209](https://github.com/Automattic/newspack-plugin/commit/b9fd209191acd8cc460adb6e06c568e76298645b))
* **reader-activation:** check lists in auth modal by default ([#1933](https://github.com/Automattic/newspack-plugin/issues/1933)) ([77bce1d](https://github.com/Automattic/newspack-plugin/commit/77bce1d56e6b5169db639ac5c9f5c4c42dd251f1))
* **reader-activation:** customizable account deletion, password reset emails ([#1938](https://github.com/Automattic/newspack-plugin/issues/1938)) ([c121e5c](https://github.com/Automattic/newspack-plugin/commit/c121e5c3ceb531fae58154585f85d2978b4f187e))
* **reader-activation:** customizable verification email ([#1929](https://github.com/Automattic/newspack-plugin/issues/1929)) ([d293701](https://github.com/Automattic/newspack-plugin/commit/d2937017d15b4e49070de2342384d1ebefcce01f))
* **reader-activation:** handle global auth success in registration block ([debf5d2](https://github.com/Automattic/newspack-plugin/commit/debf5d2da397a24362134020959cf6a85c942b39))
* **reader-activation:** improve password reset flow ([a05a9b6](https://github.com/Automattic/newspack-plugin/commit/a05a9b6cea9d4d1a15918d0e88848b97423655fb))
* **registration-block:** hidden input for subscription ([#1949](https://github.com/Automattic/newspack-plugin/issues/1949)) ([fc4f4d5](https://github.com/Automattic/newspack-plugin/commit/fc4f4d5725d557086acbcbf35051ce4ad067c863))
* reorganise reader registration header section ([#1967](https://github.com/Automattic/newspack-plugin/issues/1967)) ([f3f54e1](https://github.com/Automattic/newspack-plugin/commit/f3f54e109fab6fcb18c5e664a4c9a6205de80fe7))
* set from details for password reset emails ([#1926](https://github.com/Automattic/newspack-plugin/issues/1926)) ([8b8607a](https://github.com/Automattic/newspack-plugin/commit/8b8607a968f2eccbf3dcd51e3746ef1a032f6fb0))
* **stripe:** lookup stripe customer ID on registration ([#1860](https://github.com/Automattic/newspack-plugin/issues/1860)) ([490cd97](https://github.com/Automattic/newspack-plugin/commit/490cd97aced223ee48f6413b9a8529de45ab882b)), closes [#1853](https://github.com/Automattic/newspack-plugin/issues/1853)
* subscription metadata for ESP; Stripe webhook creation tweak ([#1859](https://github.com/Automattic/newspack-plugin/issues/1859)) ([e094b15](https://github.com/Automattic/newspack-plugin/commit/e094b159742fc12d0b05921b411bbf54545ce7ad))
* title and description for registration block ([#1924](https://github.com/Automattic/newspack-plugin/issues/1924)) ([1c9fba6](https://github.com/Automattic/newspack-plugin/commit/1c9fba61e9dd7cfcdf41af0a9d72fdc55dfab4aa))
* update billing portal copy ([8040b80](https://github.com/Automattic/newspack-plugin/commit/8040b80d2d2567885d3a97e2292ad91b8488e525))
* update reader-facing language on auth fail ([#1974](https://github.com/Automattic/newspack-plugin/issues/1974)) ([a05ca37](https://github.com/Automattic/newspack-plugin/commit/a05ca37f84574a21f040c278ffcf75982d66e1db))
* update woocommerce account message/notice ([#1956](https://github.com/Automattic/newspack-plugin/issues/1956)) ([db18b95](https://github.com/Automattic/newspack-plugin/commit/db18b9546d3ca73e6fb35d6355669e718fbb6232))
* use reCAPTCHA to secure all Reader Activation-related forms ([#1910](https://github.com/Automattic/newspack-plugin/issues/1910)) ([cc8ef79](https://github.com/Automattic/newspack-plugin/commit/cc8ef79aa1cc59a708f0b2f6c5ff3c104cac44a0))
* use universal "from" email, customizable magic link email ([#1937](https://github.com/Automattic/newspack-plugin/issues/1937)) ([3b269aa](https://github.com/Automattic/newspack-plugin/commit/3b269aa6e62a3edc936c94d2af7fac78e536ccbc))
* Woo sync to ActiveCampaign ([#1968](https://github.com/Automattic/newspack-plugin/issues/1968)) ([630b24e](https://github.com/Automattic/newspack-plugin/commit/630b24eb160ee97a554f7d00746c3ac59a118df3))

# [1.90.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.90.0-alpha.2...v1.90.0-alpha.3) (2022-09-07)


### Bug Fixes

* **ac-master-list:** handle empty lists ([cfabb5a](https://github.com/Automattic/newspack-plugin/commit/cfabb5a7a90848c209c40b35749504f2feaded4c))
* add check to only change theme if not empty ([#1978](https://github.com/Automattic/newspack-plugin/issues/1978)) ([b9835f1](https://github.com/Automattic/newspack-plugin/commit/b9835f194de5759bf975343e5a3cb9bfe79b8f16))
* **emails:** don't create post if pluggable functions are not available ([#1979](https://github.com/Automattic/newspack-plugin/issues/1979)) ([d8aac4f](https://github.com/Automattic/newspack-plugin/commit/d8aac4fb7d18313efb025592e25d1187d1a298e3))
* prefix WC My Account actions so we can decide which hooks to support ([#1963](https://github.com/Automattic/newspack-plugin/issues/1963)) ([0fa8c0a](https://github.com/Automattic/newspack-plugin/commit/0fa8c0ada1d7d5fc9ef8ce2388c48d0fac2d0c4a))


### Features

* Woo sync to ActiveCampaign ([#1968](https://github.com/Automattic/newspack-plugin/issues/1968)) ([630b24e](https://github.com/Automattic/newspack-plugin/commit/630b24eb160ee97a554f7d00746c3ac59a118df3))

# [1.90.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.90.0-alpha.1...v1.90.0-alpha.2) (2022-09-06)


### Bug Fixes

* allow Register block to be edited even if RAS is not enabled ([#1962](https://github.com/Automattic/newspack-plugin/issues/1962)) ([649f47b](https://github.com/Automattic/newspack-plugin/commit/649f47b57965121aa0b11f11a7e1c3aaeebd00f1))
* **emails:** editor message ([62dec52](https://github.com/Automattic/newspack-plugin/commit/62dec5226b7413afd62da173ace49624425cc0a8))
* localized reader auth error messages ([#1948](https://github.com/Automattic/newspack-plugin/issues/1948)) ([fb58a7f](https://github.com/Automattic/newspack-plugin/commit/fb58a7f6b7b2cfdfe13845330de9ed87f83e8f8b))
* **reader-activation:** send payment contact metadata only if RA is enabled ([#1957](https://github.com/Automattic/newspack-plugin/issues/1957)) ([3e14777](https://github.com/Automattic/newspack-plugin/commit/3e1477742f1c8cc6819282054c99f35e36b47803))
* **registration-block:** allow any markup in the success state ([977e77a](https://github.com/Automattic/newspack-plugin/commit/977e77a75c266e636cf764bc67061b7d51f563f1))
* **registration-block:** fix empty success state ([272aee5](https://github.com/Automattic/newspack-plugin/commit/272aee5c7b291240beb067afcc2401c3992264f4))
* **WooCommerce:** error notice text color ([#1954](https://github.com/Automattic/newspack-plugin/issues/1954)) ([2e4b95f](https://github.com/Automattic/newspack-plugin/commit/2e4b95f52602529faef051c804d95891f02ed6e2))


### Features

* **ac-metadata:** send SSO provider name as "NP_Connected Account" field ([56b6597](https://github.com/Automattic/newspack-plugin/commit/56b6597426f75b75205490acad7284119045b03d))
* **ac-metadata:** send Stripe customer LTV as "NP_Total Paid" field ([5f6da59](https://github.com/Automattic/newspack-plugin/commit/5f6da59f02897284ea5cefdc45d5dfb4a2e5bbd1))
* after account deletion message ([2547b76](https://github.com/Automattic/newspack-plugin/commit/2547b76373b620e9852040bdcb68c28e49a3657d))
* **analytics:** prevent sending NTG newsletter event if subscribing to master list ([#1946](https://github.com/Automattic/newspack-plugin/issues/1946)) ([806a8b0](https://github.com/Automattic/newspack-plugin/commit/806a8b0a07c4496fd2f209536d0b78ad42b7ce1a))
* custom messaging for reader without password ([#1965](https://github.com/Automattic/newspack-plugin/issues/1965)) ([a584b59](https://github.com/Automattic/newspack-plugin/commit/a584b597f6d0e067e60734c42b9b03eac1346e4c))
* give each registration form a unique ID ([#1953](https://github.com/Automattic/newspack-plugin/issues/1953)) ([29fb515](https://github.com/Automattic/newspack-plugin/commit/29fb515534ff5036b7c734de1267d8e5f3f3c05d))
* **google-sitekit:** prevent excluding logged-in users from GA if RA is enabled ([#1960](https://github.com/Automattic/newspack-plugin/issues/1960)) ([3b18bcc](https://github.com/Automattic/newspack-plugin/commit/3b18bcc400439b0036440994f0db226ce7eee559))
* **reader-activation:** customizable account deletion, password reset emails ([#1938](https://github.com/Automattic/newspack-plugin/issues/1938)) ([c121e5c](https://github.com/Automattic/newspack-plugin/commit/c121e5c3ceb531fae58154585f85d2978b4f187e))
* **reader-activation:** customizable verification email ([#1929](https://github.com/Automattic/newspack-plugin/issues/1929)) ([d293701](https://github.com/Automattic/newspack-plugin/commit/d2937017d15b4e49070de2342384d1ebefcce01f))
* **reader-activation:** handle global auth success in registration block ([debf5d2](https://github.com/Automattic/newspack-plugin/commit/debf5d2da397a24362134020959cf6a85c942b39))
* **reader-activation:** improve password reset flow ([a05a9b6](https://github.com/Automattic/newspack-plugin/commit/a05a9b6cea9d4d1a15918d0e88848b97423655fb))
* **registration-block:** hidden input for subscription ([#1949](https://github.com/Automattic/newspack-plugin/issues/1949)) ([fc4f4d5](https://github.com/Automattic/newspack-plugin/commit/fc4f4d5725d557086acbcbf35051ce4ad067c863))
* reorganise reader registration header section ([#1967](https://github.com/Automattic/newspack-plugin/issues/1967)) ([f3f54e1](https://github.com/Automattic/newspack-plugin/commit/f3f54e109fab6fcb18c5e664a4c9a6205de80fe7))
* update billing portal copy ([8040b80](https://github.com/Automattic/newspack-plugin/commit/8040b80d2d2567885d3a97e2292ad91b8488e525))
* update reader-facing language on auth fail ([#1974](https://github.com/Automattic/newspack-plugin/issues/1974)) ([a05ca37](https://github.com/Automattic/newspack-plugin/commit/a05ca37f84574a21f040c278ffcf75982d66e1db))
* update woocommerce account message/notice ([#1956](https://github.com/Automattic/newspack-plugin/issues/1956)) ([db18b95](https://github.com/Automattic/newspack-plugin/commit/db18b9546d3ca73e6fb35d6355669e718fbb6232))
* use universal "from" email, customizable magic link email ([#1937](https://github.com/Automattic/newspack-plugin/issues/1937)) ([3b269aa](https://github.com/Automattic/newspack-plugin/commit/3b269aa6e62a3edc936c94d2af7fac78e536ccbc))

# [1.90.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.89.1...v1.90.0-alpha.1) (2022-08-26)


### Bug Fixes

* **google-oauth:** missing email message ([#1925](https://github.com/Automattic/newspack-plugin/issues/1925)) ([93260a3](https://github.com/Automattic/newspack-plugin/commit/93260a368717b36d9f14f835856bbc3790557b38))
* horizontal scrollbar on auth modal ([#1919](https://github.com/Automattic/newspack-plugin/issues/1919)) ([08a7bb6](https://github.com/Automattic/newspack-plugin/commit/08a7bb612a58d7d02b69ed241fe82273ab7c5104))
* if logging in from an overlay prompt, dismiss the prompt after login ([#1927](https://github.com/Automattic/newspack-plugin/issues/1927)) ([999437d](https://github.com/Automattic/newspack-plugin/commit/999437db7fed57ea43e5edde9daba574c2c27283))
* post-logout messaging ([#1934](https://github.com/Automattic/newspack-plugin/issues/1934)) ([11e6917](https://github.com/Automattic/newspack-plugin/commit/11e691728dd9f46e29a89e9548703de5ed5a4afb))
* register and auth form tweaks ([#1935](https://github.com/Automattic/newspack-plugin/issues/1935)) ([3b76d3f](https://github.com/Automattic/newspack-plugin/commit/3b76d3f70c2b3cbb761e6d56f5432143cc7075a4))
* **registration-block:** column layout in editor ([#1920](https://github.com/Automattic/newspack-plugin/issues/1920)) ([f1ae0c5](https://github.com/Automattic/newspack-plugin/commit/f1ae0c5894f9fc571e9e50b44d984d90476243a6))
* show My Account messages using custom messaging instead of WC messaging ([#1932](https://github.com/Automattic/newspack-plugin/issues/1932)) ([c9802fe](https://github.com/Automattic/newspack-plugin/commit/c9802fe1ea494872253fe148b86ccb0dfaa9660b))
* **stripe:** handle WC-originating Stripe transactions ([674b278](https://github.com/Automattic/newspack-plugin/commit/674b2788a45a13c8fae8d0b2fd8ec4b22b1a04ae))


### Features

* disable mailchimp-for-woocommerce plugin campaign tracking cookie ([#618](https://github.com/Automattic/newspack-plugin/issues/618)) ([99310cb](https://github.com/Automattic/newspack-plugin/commit/99310cb6f343a9801c0ba0c3b8916e5ff5331ae2))
* for donations via a prompt, add prompt ID to event label ([#1928](https://github.com/Automattic/newspack-plugin/issues/1928)) ([4704cfd](https://github.com/Automattic/newspack-plugin/commit/4704cfd21f793717c10433056a6e425a84b0e574))
* honeypot trap for auth and registration forms ([#1896](https://github.com/Automattic/newspack-plugin/issues/1896)) ([d5d713c](https://github.com/Automattic/newspack-plugin/commit/d5d713ca0b4715924136265d36dfdeb19954fa1a))
* move have account text below SSO and adjust font-size ([#1917](https://github.com/Automattic/newspack-plugin/issues/1917)) ([869913d](https://github.com/Automattic/newspack-plugin/commit/869913dec1156492569ccd4eda56b29401384c3c))
* new option for minimum donation amount ([#1895](https://github.com/Automattic/newspack-plugin/issues/1895)) ([0b9618b](https://github.com/Automattic/newspack-plugin/commit/0b9618b25d76b64619d25f2077466030f126b477))
* reader account deletion and ESP sync options ([#1884](https://github.com/Automattic/newspack-plugin/issues/1884)) ([b9fd209](https://github.com/Automattic/newspack-plugin/commit/b9fd209191acd8cc460adb6e06c568e76298645b))
* **reader-activation:** check lists in auth modal by default ([#1933](https://github.com/Automattic/newspack-plugin/issues/1933)) ([77bce1d](https://github.com/Automattic/newspack-plugin/commit/77bce1d56e6b5169db639ac5c9f5c4c42dd251f1))
* set from details for password reset emails ([#1926](https://github.com/Automattic/newspack-plugin/issues/1926)) ([8b8607a](https://github.com/Automattic/newspack-plugin/commit/8b8607a968f2eccbf3dcd51e3746ef1a032f6fb0))
* **stripe:** lookup stripe customer ID on registration ([#1860](https://github.com/Automattic/newspack-plugin/issues/1860)) ([490cd97](https://github.com/Automattic/newspack-plugin/commit/490cd97aced223ee48f6413b9a8529de45ab882b)), closes [#1853](https://github.com/Automattic/newspack-plugin/issues/1853)
* subscription metadata for ESP; Stripe webhook creation tweak ([#1859](https://github.com/Automattic/newspack-plugin/issues/1859)) ([e094b15](https://github.com/Automattic/newspack-plugin/commit/e094b159742fc12d0b05921b411bbf54545ce7ad))
* title and description for registration block ([#1924](https://github.com/Automattic/newspack-plugin/issues/1924)) ([1c9fba6](https://github.com/Automattic/newspack-plugin/commit/1c9fba61e9dd7cfcdf41af0a9d72fdc55dfab4aa))
* use reCAPTCHA to secure all Reader Activation-related forms ([#1910](https://github.com/Automattic/newspack-plugin/issues/1910)) ([cc8ef79](https://github.com/Automattic/newspack-plugin/commit/cc8ef79aa1cc59a708f0b2f6c5ff3c104cac44a0))

## [1.89.1](https://github.com/Automattic/newspack-plugin/compare/v1.89.0...v1.89.1) (2022-08-18)


### Bug Fixes

* version for cache busting ([1db4ee2](https://github.com/Automattic/newspack-plugin/commit/1db4ee255d0131c1713cbae6a37eaed9d9447603))
* version for cache busting ([#1918](https://github.com/Automattic/newspack-plugin/issues/1918)) ([17949da](https://github.com/Automattic/newspack-plugin/commit/17949da88767610d5a4411bcf7d8e03b9b443daa))

## [1.89.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.89.0...v1.89.1-hotfix.1) (2022-08-18)


### Bug Fixes

* version for cache busting ([1db4ee2](https://github.com/Automattic/newspack-plugin/commit/1db4ee255d0131c1713cbae6a37eaed9d9447603))

# [1.89.0](https://github.com/Automattic/newspack-plugin/compare/v1.88.0...v1.89.0) (2022-08-16)


### Bug Fixes

* **active-campaign:** legacy contacts detection ([#1858](https://github.com/Automattic/newspack-plugin/issues/1858)) ([67640a5](https://github.com/Automattic/newspack-plugin/commit/67640a5f2c35361ac40784d752e413cc3d80a150))
* **campaigns-wizard:** segmentation wording ([ddf61ad](https://github.com/Automattic/newspack-plugin/commit/ddf61ad30e7b22cc4022e24bc411a5cb3f576fd5))
* ensure scroll on smaller height ([#1813](https://github.com/Automattic/newspack-plugin/issues/1813)) ([e234e8b](https://github.com/Automattic/newspack-plugin/commit/e234e8bd6445de7c32190bdd5af00d9e369f25fe))
* fix fatal error when debug mode active ([#1826](https://github.com/Automattic/newspack-plugin/issues/1826)) ([d9388ee](https://github.com/Automattic/newspack-plugin/commit/d9388ee5e33d5d3fcdaa39cb415c04eb24242a9c))
* **ga:** cookie parsing ([#1857](https://github.com/Automattic/newspack-plugin/issues/1857)) ([a936abd](https://github.com/Automattic/newspack-plugin/commit/a936abdf72d97e9c4c702ae1aefefe57aec672d4))
* google auth button type ([#1829](https://github.com/Automattic/newspack-plugin/issues/1829)) ([3704d9f](https://github.com/Automattic/newspack-plugin/commit/3704d9f735de97fd4edd25b7775577f3cd6b4c7d))
* **google-auth:** catch and display errors ([#1871](https://github.com/Automattic/newspack-plugin/issues/1871)) ([67cbcfd](https://github.com/Automattic/newspack-plugin/commit/67cbcfdbe53ec48539a1f1fb4d9af4b81ab9ca12))
* **google-auth:** ensure popup on user click event ([#1831](https://github.com/Automattic/newspack-plugin/issues/1831)) ([0af9abf](https://github.com/Automattic/newspack-plugin/commit/0af9abfd15b777b062befbec6bd510ac585b6139))
* **magic-links:** fix email encoding on sent link ([#1833](https://github.com/Automattic/newspack-plugin/issues/1833)) ([8d4756c](https://github.com/Automattic/newspack-plugin/commit/8d4756cbdc86cbf7b63e212b4d0887c74771f2fc))
* **my account:** handle legacy data ([#1823](https://github.com/Automattic/newspack-plugin/issues/1823)) ([6816799](https://github.com/Automattic/newspack-plugin/commit/68167997eaa342bd15bf7abf2a100401562a2eac))
* **newsletters:** use international date format ([#1855](https://github.com/Automattic/newspack-plugin/issues/1855)) ([4cda57d](https://github.com/Automattic/newspack-plugin/commit/4cda57d48656b41d5567a1cee7b593fe369ef208))
* **oauth:** csrf token lifespan ([#1869](https://github.com/Automattic/newspack-plugin/issues/1869)) ([52e0f8b](https://github.com/Automattic/newspack-plugin/commit/52e0f8bf1dba1a9ac887727e8a90d7912d4b5109))
* parse CID from _ga cookie if it only contains CID string ([#1874](https://github.com/Automattic/newspack-plugin/issues/1874)) ([dc1fb52](https://github.com/Automattic/newspack-plugin/commit/dc1fb5265ac240b071b792e5ad97b1770a8d3133))
* **popups:** use new Campaigns method for creating donation events on new orders ([#1794](https://github.com/Automattic/newspack-plugin/issues/1794)) ([49dc14c](https://github.com/Automattic/newspack-plugin/commit/49dc14cbeb89bc4dc0b2614c14f8a923590ff44a))
* **reader-activation:** add metadata to reader registered on donation ([722724c](https://github.com/Automattic/newspack-plugin/commit/722724cc49b3aac35b81a3fc0da2f62a317c3cd1))
* **reader-activation:** handle modal conflict when auth is triggered from a prompt ([c2a0141](https://github.com/Automattic/newspack-plugin/commit/c2a014186d252fcc84bef560c0ac22f9c6f0c5da)), closes [#1835](https://github.com/Automattic/newspack-plugin/issues/1835)
* **reader-activation:** handle no lists config available ([23b0249](https://github.com/Automattic/newspack-plugin/commit/23b02491e9c2b954726437371d610fe64909463f))
* **reader-activation:** reinitialize auth links after DOM load ([#1812](https://github.com/Automattic/newspack-plugin/issues/1812)) ([0a4b499](https://github.com/Automattic/newspack-plugin/commit/0a4b49905c3fb9d9296fd171d8914f91df4f92c7))
* **reader-activation:** remove async prop from library ([#1846](https://github.com/Automattic/newspack-plugin/issues/1846)) ([4131ca6](https://github.com/Automattic/newspack-plugin/commit/4131ca675eae7db7ee6468af85392b678fb43b76))
* **reader-activation:** username generation handling ([#1789](https://github.com/Automattic/newspack-plugin/issues/1789)) ([17edf2a](https://github.com/Automattic/newspack-plugin/commit/17edf2adc8f4022d26757467e7d4066f61cdfd91))
* redirecting to My Account after logging in while pre-authed ([#1863](https://github.com/Automattic/newspack-plugin/issues/1863)) ([ddf111e](https://github.com/Automattic/newspack-plugin/commit/ddf111ec302e4d571c96369dd145b3292134fed9))
* **registration-block:** don't escape html for sign in labels ([#1834](https://github.com/Automattic/newspack-plugin/issues/1834)) ([871300d](https://github.com/Automattic/newspack-plugin/commit/871300d8ac0cb127300bcd784c1f934780e6e887))
* **registration-block:** margin for success message ([#1808](https://github.com/Automattic/newspack-plugin/issues/1808)) ([1bfe546](https://github.com/Automattic/newspack-plugin/commit/1bfe546aa5cbc550cff975bc5f2fc73f553558f0))
* **registration-block:** render on preview ([#1844](https://github.com/Automattic/newspack-plugin/issues/1844)) ([87b9be9](https://github.com/Automattic/newspack-plugin/commit/87b9be9f8f26c61bc9e793318e0870b9fb5d309c))
* tweak arguments for magic link client hash ([#1862](https://github.com/Automattic/newspack-plugin/issues/1862)) ([8dcd45e](https://github.com/Automattic/newspack-plugin/commit/8dcd45e8b342869f04b5bdde3d29792fd4c196b3))
* verify reader on google authentication ([#1873](https://github.com/Automattic/newspack-plugin/issues/1873)) ([c9c4eef](https://github.com/Automattic/newspack-plugin/commit/c9c4eef03ac27cf6110a1c1b7a0ae45898b30ae1))


### Features

* **active-campaign:** metadata improvements ([#1851](https://github.com/Automattic/newspack-plugin/issues/1851)) ([48883af](https://github.com/Automattic/newspack-plugin/commit/48883afe7598e43463e76eee08d738da259035fe))
* **active-campaigns:** override is-new-contact for legacy contacts ([34dd9a2](https://github.com/Automattic/newspack-plugin/commit/34dd9a2d9a08c33005e94cc55ad585a65983f22d))
* **analytics:** send GA events on the server side ([#1828](https://github.com/Automattic/newspack-plugin/issues/1828)) ([3e384e1](https://github.com/Automattic/newspack-plugin/commit/3e384e16d390c11d1dd38c28e254b2c0e9dcc00d))
* authenticated reader cookie ([#1882](https://github.com/Automattic/newspack-plugin/issues/1882)) ([352316b](https://github.com/Automattic/newspack-plugin/commit/352316b0e589db4f83b841d57cf1aab701947487))
* better welcome email copy for initial verification ([#1880](https://github.com/Automattic/newspack-plugin/issues/1880)) ([604ebf7](https://github.com/Automattic/newspack-plugin/commit/604ebf7bd4d99d1503b1b46ec60035e95d3c33d6))
* cookie reader's preferred auth strategy ([#1875](https://github.com/Automattic/newspack-plugin/issues/1875)) ([fc47f41](https://github.com/Automattic/newspack-plugin/commit/fc47f41d93eeb028d862838c75b6bbad996e4f8d))
* disable woocomerce welcome emails in favor of verification email ([#1876](https://github.com/Automattic/newspack-plugin/issues/1876)) ([1e470e3](https://github.com/Automattic/newspack-plugin/commit/1e470e349f5467dc54e09e7358339f15edf970a4))
* **donations:** remove defaultFrequency from the configuration ([#1814](https://github.com/Automattic/newspack-plugin/issues/1814)) ([b6aa894](https://github.com/Automattic/newspack-plugin/commit/b6aa894bcf3088e2c679f594faf95d5f0ff72581))
* handle contact update w/out lists selection ([#1816](https://github.com/Automattic/newspack-plugin/issues/1816)) ([67574d1](https://github.com/Automattic/newspack-plugin/commit/67574d15438de7dd76839613ea5612b750d4cd5c))
* handle new frequency options in Campaigns dashbaord ([#1779](https://github.com/Automattic/newspack-plugin/issues/1779)) ([c770a7d](https://github.com/Automattic/newspack-plugin/commit/c770a7d15804ab70817a640a71b34bfe9ceba62f))
* if registering an email that already has an account, show different message ([#1849](https://github.com/Automattic/newspack-plugin/issues/1849)) ([bf48bc4](https://github.com/Automattic/newspack-plugin/commit/bf48bc462298b6df9cf36a8b97d7e72654e7ac64))
* lock access to My Account UI until account is verified ([#1877](https://github.com/Automattic/newspack-plugin/issues/1877)) ([a850f48](https://github.com/Automattic/newspack-plugin/commit/a850f4898ea83b0e358a763f4e4eefaf7d2ea97e))
* **my-account:** stripe billing portal link ([#1761](https://github.com/Automattic/newspack-plugin/issues/1761)) ([3e69af1](https://github.com/Automattic/newspack-plugin/commit/3e69af1956dd24c89c2c2b313100bc01fa07df90)), closes [#1742](https://github.com/Automattic/newspack-plugin/issues/1742) [#1739](https://github.com/Automattic/newspack-plugin/issues/1739) [#1740](https://github.com/Automattic/newspack-plugin/issues/1740) [#1741](https://github.com/Automattic/newspack-plugin/issues/1741) [#1782](https://github.com/Automattic/newspack-plugin/issues/1782)
* **reader-activation:** account link and auth form ([#1754](https://github.com/Automattic/newspack-plugin/issues/1754)) ([b163664](https://github.com/Automattic/newspack-plugin/commit/b1636644e134724b2235e23e75c14b9af0e38091))
* **reader-activation:** activecampaign master list ([#1818](https://github.com/Automattic/newspack-plugin/issues/1818)) ([ecbbc47](https://github.com/Automattic/newspack-plugin/commit/ecbbc474930ce420dfe293e339f5c6d354f81f7d))
* **reader-activation:** disable 3rd party login buttons initially ([#1806](https://github.com/Automattic/newspack-plugin/issues/1806)) ([c806bfe](https://github.com/Automattic/newspack-plugin/commit/c806bfe005121e1a907b94b9954d917976805c22))
* **reader-activation:** optimistic account link ([#1847](https://github.com/Automattic/newspack-plugin/issues/1847)) ([85c550a](https://github.com/Automattic/newspack-plugin/commit/85c550a9aaa9156469efd59cc1a30b69164a0646))
* **reader-activation:** prevent updating user email in my-account ([7d49db4](https://github.com/Automattic/newspack-plugin/commit/7d49db4fa54738b5962302080661d9d76f9aebee))
* **reader-activation:** registration auth cookie control ([#1787](https://github.com/Automattic/newspack-plugin/issues/1787)) ([aeb0b5b](https://github.com/Automattic/newspack-plugin/commit/aeb0b5bbef9dc13d57872c90c7f5d87762745298))
* **reader-activation:** settings wizard ([#1773](https://github.com/Automattic/newspack-plugin/issues/1773)) ([aaff0de](https://github.com/Automattic/newspack-plugin/commit/aaff0deb1cd2c6f4b711c904c88051a198c6a6cd))
* **reader-auth:** make password login the first option, instead of login link ([1fe5ffa](https://github.com/Automattic/newspack-plugin/commit/1fe5ffae6aca9070465c58c0f51825ef3df911f6)), closes [#1809](https://github.com/Automattic/newspack-plugin/issues/1809)
* register anonymous single donors ([#1795](https://github.com/Automattic/newspack-plugin/issues/1795)) ([9e4f2f6](https://github.com/Automattic/newspack-plugin/commit/9e4f2f6cc9748dafc322f4c3c6d23b83fb021f83))
* **registration-block:** add success icon ([#1804](https://github.com/Automattic/newspack-plugin/issues/1804)) ([86c38f8](https://github.com/Automattic/newspack-plugin/commit/86c38f8a40e821fa40a1e3c1885c1736d38e6b84))
* **registration-block:** editable success state ([#1785](https://github.com/Automattic/newspack-plugin/issues/1785)) ([7dcea82](https://github.com/Automattic/newspack-plugin/commit/7dcea826a788d3219943137da64eb61fb6f623da)), closes [#1768](https://github.com/Automattic/newspack-plugin/issues/1768)
* **registration-block:** login with Google ([#1781](https://github.com/Automattic/newspack-plugin/issues/1781)) ([ed79c5c](https://github.com/Automattic/newspack-plugin/commit/ed79c5ca275b4353146f3e2d1975a642ab02ca02)), closes [#1774](https://github.com/Automattic/newspack-plugin/issues/1774)
* **registration-block:** newsletter subscription ([#1778](https://github.com/Automattic/newspack-plugin/issues/1778)) ([717b5b8](https://github.com/Automattic/newspack-plugin/commit/717b5b8f20660efd27c2351d60830b288996b8b9))
* reorganise donations wizard and use buttongroup for donation type ([#1824](https://github.com/Automattic/newspack-plugin/issues/1824)) ([f7b58ae](https://github.com/Automattic/newspack-plugin/commit/f7b58ae0fbc28524031c533855aa7c4c8c558f8e))
* replace WooCommerce’s login form with our own ([#1854](https://github.com/Automattic/newspack-plugin/issues/1854)) ([f5b24c4](https://github.com/Automattic/newspack-plugin/commit/f5b24c4dfd216e188a22439434bb2c0f56cb9b88))
* **rss:** adds offset feature ([#1790](https://github.com/Automattic/newspack-plugin/issues/1790)) ([321eff5](https://github.com/Automattic/newspack-plugin/commit/321eff533b5140986c5a7fd52546319dfb8b2125))
* send user metadata to AC ([#1793](https://github.com/Automattic/newspack-plugin/issues/1793)) ([03a15ba](https://github.com/Automattic/newspack-plugin/commit/03a15ba8b8e435d70a72250ae27f68a6042eb54c))
* set client id cookie; reader activation tweaks ([#1780](https://github.com/Automattic/newspack-plugin/issues/1780)) ([96a07ae](https://github.com/Automattic/newspack-plugin/commit/96a07ae3873d23775da826606582bd1a84342515))
* **stripe:** webhook auto-creation and validation ([365aed9](https://github.com/Automattic/newspack-plugin/commit/365aed937ccc8f7b03efe99d0ff3097149a6b37b))
* tweak registration block styling ([d83448e](https://github.com/Automattic/newspack-plugin/commit/d83448e4f69dfbcdda639df5b474c90fed348037))


### Reverts

* Revert "chore(release): 1.87.0 [skip ci]" ([ca8d55c](https://github.com/Automattic/newspack-plugin/commit/ca8d55cc239d26538a231b770c82a9c98a8d4400))

# [1.89.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.89.0-alpha.1...v1.89.0-alpha.2) (2022-08-12)


### Bug Fixes

* **google-auth:** catch and display errors ([#1871](https://github.com/Automattic/newspack-plugin/issues/1871)) ([67cbcfd](https://github.com/Automattic/newspack-plugin/commit/67cbcfdbe53ec48539a1f1fb4d9af4b81ab9ca12))
* **oauth:** csrf token lifespan ([#1869](https://github.com/Automattic/newspack-plugin/issues/1869)) ([52e0f8b](https://github.com/Automattic/newspack-plugin/commit/52e0f8bf1dba1a9ac887727e8a90d7912d4b5109))
* parse CID from _ga cookie if it only contains CID string ([#1874](https://github.com/Automattic/newspack-plugin/issues/1874)) ([dc1fb52](https://github.com/Automattic/newspack-plugin/commit/dc1fb5265ac240b071b792e5ad97b1770a8d3133))
* redirecting to My Account after logging in while pre-authed ([#1863](https://github.com/Automattic/newspack-plugin/issues/1863)) ([ddf111e](https://github.com/Automattic/newspack-plugin/commit/ddf111ec302e4d571c96369dd145b3292134fed9))
* verify reader on google authentication ([#1873](https://github.com/Automattic/newspack-plugin/issues/1873)) ([c9c4eef](https://github.com/Automattic/newspack-plugin/commit/c9c4eef03ac27cf6110a1c1b7a0ae45898b30ae1))


### Features

* authenticated reader cookie ([#1882](https://github.com/Automattic/newspack-plugin/issues/1882)) ([352316b](https://github.com/Automattic/newspack-plugin/commit/352316b0e589db4f83b841d57cf1aab701947487))
* better welcome email copy for initial verification ([#1880](https://github.com/Automattic/newspack-plugin/issues/1880)) ([604ebf7](https://github.com/Automattic/newspack-plugin/commit/604ebf7bd4d99d1503b1b46ec60035e95d3c33d6))
* cookie reader's preferred auth strategy ([#1875](https://github.com/Automattic/newspack-plugin/issues/1875)) ([fc47f41](https://github.com/Automattic/newspack-plugin/commit/fc47f41d93eeb028d862838c75b6bbad996e4f8d))
* disable woocomerce welcome emails in favor of verification email ([#1876](https://github.com/Automattic/newspack-plugin/issues/1876)) ([1e470e3](https://github.com/Automattic/newspack-plugin/commit/1e470e349f5467dc54e09e7358339f15edf970a4))
* lock access to My Account UI until account is verified ([#1877](https://github.com/Automattic/newspack-plugin/issues/1877)) ([a850f48](https://github.com/Automattic/newspack-plugin/commit/a850f4898ea83b0e358a763f4e4eefaf7d2ea97e))

# [1.89.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.88.0...v1.89.0-alpha.1) (2022-08-10)


### Bug Fixes

* **active-campaign:** legacy contacts detection ([#1858](https://github.com/Automattic/newspack-plugin/issues/1858)) ([67640a5](https://github.com/Automattic/newspack-plugin/commit/67640a5f2c35361ac40784d752e413cc3d80a150))
* **campaigns-wizard:** segmentation wording ([ddf61ad](https://github.com/Automattic/newspack-plugin/commit/ddf61ad30e7b22cc4022e24bc411a5cb3f576fd5))
* ensure scroll on smaller height ([#1813](https://github.com/Automattic/newspack-plugin/issues/1813)) ([e234e8b](https://github.com/Automattic/newspack-plugin/commit/e234e8bd6445de7c32190bdd5af00d9e369f25fe))
* fix fatal error when debug mode active ([#1826](https://github.com/Automattic/newspack-plugin/issues/1826)) ([d9388ee](https://github.com/Automattic/newspack-plugin/commit/d9388ee5e33d5d3fcdaa39cb415c04eb24242a9c))
* **ga:** cookie parsing ([#1857](https://github.com/Automattic/newspack-plugin/issues/1857)) ([a936abd](https://github.com/Automattic/newspack-plugin/commit/a936abdf72d97e9c4c702ae1aefefe57aec672d4))
* google auth button type ([#1829](https://github.com/Automattic/newspack-plugin/issues/1829)) ([3704d9f](https://github.com/Automattic/newspack-plugin/commit/3704d9f735de97fd4edd25b7775577f3cd6b4c7d))
* **google-auth:** ensure popup on user click event ([#1831](https://github.com/Automattic/newspack-plugin/issues/1831)) ([0af9abf](https://github.com/Automattic/newspack-plugin/commit/0af9abfd15b777b062befbec6bd510ac585b6139))
* **magic-links:** fix email encoding on sent link ([#1833](https://github.com/Automattic/newspack-plugin/issues/1833)) ([8d4756c](https://github.com/Automattic/newspack-plugin/commit/8d4756cbdc86cbf7b63e212b4d0887c74771f2fc))
* **my account:** handle legacy data ([#1823](https://github.com/Automattic/newspack-plugin/issues/1823)) ([6816799](https://github.com/Automattic/newspack-plugin/commit/68167997eaa342bd15bf7abf2a100401562a2eac))
* **newsletters:** use international date format ([#1855](https://github.com/Automattic/newspack-plugin/issues/1855)) ([4cda57d](https://github.com/Automattic/newspack-plugin/commit/4cda57d48656b41d5567a1cee7b593fe369ef208))
* **popups:** use new Campaigns method for creating donation events on new orders ([#1794](https://github.com/Automattic/newspack-plugin/issues/1794)) ([49dc14c](https://github.com/Automattic/newspack-plugin/commit/49dc14cbeb89bc4dc0b2614c14f8a923590ff44a))
* **reader-activation:** add metadata to reader registered on donation ([722724c](https://github.com/Automattic/newspack-plugin/commit/722724cc49b3aac35b81a3fc0da2f62a317c3cd1))
* **reader-activation:** handle modal conflict when auth is triggered from a prompt ([c2a0141](https://github.com/Automattic/newspack-plugin/commit/c2a014186d252fcc84bef560c0ac22f9c6f0c5da)), closes [#1835](https://github.com/Automattic/newspack-plugin/issues/1835)
* **reader-activation:** handle no lists config available ([23b0249](https://github.com/Automattic/newspack-plugin/commit/23b02491e9c2b954726437371d610fe64909463f))
* **reader-activation:** reinitialize auth links after DOM load ([#1812](https://github.com/Automattic/newspack-plugin/issues/1812)) ([0a4b499](https://github.com/Automattic/newspack-plugin/commit/0a4b49905c3fb9d9296fd171d8914f91df4f92c7))
* **reader-activation:** remove async prop from library ([#1846](https://github.com/Automattic/newspack-plugin/issues/1846)) ([4131ca6](https://github.com/Automattic/newspack-plugin/commit/4131ca675eae7db7ee6468af85392b678fb43b76))
* **reader-activation:** username generation handling ([#1789](https://github.com/Automattic/newspack-plugin/issues/1789)) ([17edf2a](https://github.com/Automattic/newspack-plugin/commit/17edf2adc8f4022d26757467e7d4066f61cdfd91))
* **registration-block:** don't escape html for sign in labels ([#1834](https://github.com/Automattic/newspack-plugin/issues/1834)) ([871300d](https://github.com/Automattic/newspack-plugin/commit/871300d8ac0cb127300bcd784c1f934780e6e887))
* **registration-block:** margin for success message ([#1808](https://github.com/Automattic/newspack-plugin/issues/1808)) ([1bfe546](https://github.com/Automattic/newspack-plugin/commit/1bfe546aa5cbc550cff975bc5f2fc73f553558f0))
* **registration-block:** render on preview ([#1844](https://github.com/Automattic/newspack-plugin/issues/1844)) ([87b9be9](https://github.com/Automattic/newspack-plugin/commit/87b9be9f8f26c61bc9e793318e0870b9fb5d309c))
* tweak arguments for magic link client hash ([#1862](https://github.com/Automattic/newspack-plugin/issues/1862)) ([8dcd45e](https://github.com/Automattic/newspack-plugin/commit/8dcd45e8b342869f04b5bdde3d29792fd4c196b3))


### Features

* **active-campaign:** metadata improvements ([#1851](https://github.com/Automattic/newspack-plugin/issues/1851)) ([48883af](https://github.com/Automattic/newspack-plugin/commit/48883afe7598e43463e76eee08d738da259035fe))
* **active-campaigns:** override is-new-contact for legacy contacts ([34dd9a2](https://github.com/Automattic/newspack-plugin/commit/34dd9a2d9a08c33005e94cc55ad585a65983f22d))
* **analytics:** send GA events on the server side ([#1828](https://github.com/Automattic/newspack-plugin/issues/1828)) ([3e384e1](https://github.com/Automattic/newspack-plugin/commit/3e384e16d390c11d1dd38c28e254b2c0e9dcc00d))
* **donations:** remove defaultFrequency from the configuration ([#1814](https://github.com/Automattic/newspack-plugin/issues/1814)) ([b6aa894](https://github.com/Automattic/newspack-plugin/commit/b6aa894bcf3088e2c679f594faf95d5f0ff72581))
* handle contact update w/out lists selection ([#1816](https://github.com/Automattic/newspack-plugin/issues/1816)) ([67574d1](https://github.com/Automattic/newspack-plugin/commit/67574d15438de7dd76839613ea5612b750d4cd5c))
* handle new frequency options in Campaigns dashbaord ([#1779](https://github.com/Automattic/newspack-plugin/issues/1779)) ([c770a7d](https://github.com/Automattic/newspack-plugin/commit/c770a7d15804ab70817a640a71b34bfe9ceba62f))
* if registering an email that already has an account, show different message ([#1849](https://github.com/Automattic/newspack-plugin/issues/1849)) ([bf48bc4](https://github.com/Automattic/newspack-plugin/commit/bf48bc462298b6df9cf36a8b97d7e72654e7ac64))
* **my-account:** stripe billing portal link ([#1761](https://github.com/Automattic/newspack-plugin/issues/1761)) ([3e69af1](https://github.com/Automattic/newspack-plugin/commit/3e69af1956dd24c89c2c2b313100bc01fa07df90)), closes [#1742](https://github.com/Automattic/newspack-plugin/issues/1742) [#1739](https://github.com/Automattic/newspack-plugin/issues/1739) [#1740](https://github.com/Automattic/newspack-plugin/issues/1740) [#1741](https://github.com/Automattic/newspack-plugin/issues/1741) [#1782](https://github.com/Automattic/newspack-plugin/issues/1782)
* **reader-activation:** account link and auth form ([#1754](https://github.com/Automattic/newspack-plugin/issues/1754)) ([b163664](https://github.com/Automattic/newspack-plugin/commit/b1636644e134724b2235e23e75c14b9af0e38091))
* **reader-activation:** activecampaign master list ([#1818](https://github.com/Automattic/newspack-plugin/issues/1818)) ([ecbbc47](https://github.com/Automattic/newspack-plugin/commit/ecbbc474930ce420dfe293e339f5c6d354f81f7d))
* **reader-activation:** disable 3rd party login buttons initially ([#1806](https://github.com/Automattic/newspack-plugin/issues/1806)) ([c806bfe](https://github.com/Automattic/newspack-plugin/commit/c806bfe005121e1a907b94b9954d917976805c22))
* **reader-activation:** optimistic account link ([#1847](https://github.com/Automattic/newspack-plugin/issues/1847)) ([85c550a](https://github.com/Automattic/newspack-plugin/commit/85c550a9aaa9156469efd59cc1a30b69164a0646))
* **reader-activation:** prevent updating user email in my-account ([7d49db4](https://github.com/Automattic/newspack-plugin/commit/7d49db4fa54738b5962302080661d9d76f9aebee))
* **reader-activation:** registration auth cookie control ([#1787](https://github.com/Automattic/newspack-plugin/issues/1787)) ([aeb0b5b](https://github.com/Automattic/newspack-plugin/commit/aeb0b5bbef9dc13d57872c90c7f5d87762745298))
* **reader-activation:** settings wizard ([#1773](https://github.com/Automattic/newspack-plugin/issues/1773)) ([aaff0de](https://github.com/Automattic/newspack-plugin/commit/aaff0deb1cd2c6f4b711c904c88051a198c6a6cd))
* **reader-auth:** make password login the first option, instead of login link ([1fe5ffa](https://github.com/Automattic/newspack-plugin/commit/1fe5ffae6aca9070465c58c0f51825ef3df911f6)), closes [#1809](https://github.com/Automattic/newspack-plugin/issues/1809)
* register anonymous single donors ([#1795](https://github.com/Automattic/newspack-plugin/issues/1795)) ([9e4f2f6](https://github.com/Automattic/newspack-plugin/commit/9e4f2f6cc9748dafc322f4c3c6d23b83fb021f83))
* **registration-block:** add success icon ([#1804](https://github.com/Automattic/newspack-plugin/issues/1804)) ([86c38f8](https://github.com/Automattic/newspack-plugin/commit/86c38f8a40e821fa40a1e3c1885c1736d38e6b84))
* **registration-block:** editable success state ([#1785](https://github.com/Automattic/newspack-plugin/issues/1785)) ([7dcea82](https://github.com/Automattic/newspack-plugin/commit/7dcea826a788d3219943137da64eb61fb6f623da)), closes [#1768](https://github.com/Automattic/newspack-plugin/issues/1768)
* **registration-block:** login with Google ([#1781](https://github.com/Automattic/newspack-plugin/issues/1781)) ([ed79c5c](https://github.com/Automattic/newspack-plugin/commit/ed79c5ca275b4353146f3e2d1975a642ab02ca02)), closes [#1774](https://github.com/Automattic/newspack-plugin/issues/1774)
* **registration-block:** newsletter subscription ([#1778](https://github.com/Automattic/newspack-plugin/issues/1778)) ([717b5b8](https://github.com/Automattic/newspack-plugin/commit/717b5b8f20660efd27c2351d60830b288996b8b9))
* reorganise donations wizard and use buttongroup for donation type ([#1824](https://github.com/Automattic/newspack-plugin/issues/1824)) ([f7b58ae](https://github.com/Automattic/newspack-plugin/commit/f7b58ae0fbc28524031c533855aa7c4c8c558f8e))
* replace WooCommerce’s login form with our own ([#1854](https://github.com/Automattic/newspack-plugin/issues/1854)) ([f5b24c4](https://github.com/Automattic/newspack-plugin/commit/f5b24c4dfd216e188a22439434bb2c0f56cb9b88))
* **rss:** adds offset feature ([#1790](https://github.com/Automattic/newspack-plugin/issues/1790)) ([321eff5](https://github.com/Automattic/newspack-plugin/commit/321eff533b5140986c5a7fd52546319dfb8b2125))
* send user metadata to AC ([#1793](https://github.com/Automattic/newspack-plugin/issues/1793)) ([03a15ba](https://github.com/Automattic/newspack-plugin/commit/03a15ba8b8e435d70a72250ae27f68a6042eb54c))
* set client id cookie; reader activation tweaks ([#1780](https://github.com/Automattic/newspack-plugin/issues/1780)) ([96a07ae](https://github.com/Automattic/newspack-plugin/commit/96a07ae3873d23775da826606582bd1a84342515))
* **stripe:** webhook auto-creation and validation ([365aed9](https://github.com/Automattic/newspack-plugin/commit/365aed937ccc8f7b03efe99d0ff3097149a6b37b))
* tweak registration block styling ([d83448e](https://github.com/Automattic/newspack-plugin/commit/d83448e4f69dfbcdda639df5b474c90fed348037))


### Reverts

* Revert "chore(release): 1.87.0 [skip ci]" ([ca8d55c](https://github.com/Automattic/newspack-plugin/commit/ca8d55cc239d26538a231b770c82a9c98a8d4400))

# [1.88.0](https://github.com/Automattic/newspack-plugin/compare/v1.87.0...v1.88.0) (2022-08-10)


### Bug Fixes

* trigger ci ([e8bf33a](https://github.com/Automattic/newspack-plugin/commit/e8bf33aa5bcfb7bac71a68daa06038b3a6ce6f7c))


### Features

* add UI to manage reCaptcha v3 settings in Reader Revenue wizard ([9b88366](https://github.com/Automattic/newspack-plugin/commit/9b88366303b181c61860c2626811c863663b066b))

# [1.88.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.87.0...v1.88.0-hotfix.1) (2022-08-10)


### Features

* add UI to manage reCaptcha v3 settings in Reader Revenue wizard ([9b88366](https://github.com/Automattic/newspack-plugin/commit/9b88366303b181c61860c2626811c863663b066b))

# [1.87.0](https://github.com/Automattic/newspack-plugin/compare/v1.86.0...v1.87.0) (2022-07-26)


### Bug Fixes

* **active-campaign:** legacy contacts detection ([#1858](https://github.com/Automattic/newspack-plugin/issues/1858)) ([67640a5](https://github.com/Automattic/newspack-plugin/commit/67640a5f2c35361ac40784d752e413cc3d80a150))
* **campaigns-wizard:** segmentation wording ([ddf61ad](https://github.com/Automattic/newspack-plugin/commit/ddf61ad30e7b22cc4022e24bc411a5cb3f576fd5))
* ensure scroll on smaller height ([#1813](https://github.com/Automattic/newspack-plugin/issues/1813)) ([e234e8b](https://github.com/Automattic/newspack-plugin/commit/e234e8bd6445de7c32190bdd5af00d9e369f25fe))
* fix fatal error when debug mode active ([#1826](https://github.com/Automattic/newspack-plugin/issues/1826)) ([d9388ee](https://github.com/Automattic/newspack-plugin/commit/d9388ee5e33d5d3fcdaa39cb415c04eb24242a9c))
* **ga:** cookie parsing ([#1857](https://github.com/Automattic/newspack-plugin/issues/1857)) ([a936abd](https://github.com/Automattic/newspack-plugin/commit/a936abdf72d97e9c4c702ae1aefefe57aec672d4))
* google auth button type ([#1829](https://github.com/Automattic/newspack-plugin/issues/1829)) ([3704d9f](https://github.com/Automattic/newspack-plugin/commit/3704d9f735de97fd4edd25b7775577f3cd6b4c7d))
* **google-auth:** ensure popup on user click event ([#1831](https://github.com/Automattic/newspack-plugin/issues/1831)) ([0af9abf](https://github.com/Automattic/newspack-plugin/commit/0af9abfd15b777b062befbec6bd510ac585b6139))
* **magic-links:** fix email encoding on sent link ([#1833](https://github.com/Automattic/newspack-plugin/issues/1833)) ([8d4756c](https://github.com/Automattic/newspack-plugin/commit/8d4756cbdc86cbf7b63e212b4d0887c74771f2fc))
* **my account:** handle legacy data ([#1823](https://github.com/Automattic/newspack-plugin/issues/1823)) ([6816799](https://github.com/Automattic/newspack-plugin/commit/68167997eaa342bd15bf7abf2a100401562a2eac))
* **newsletters:** use international date format ([#1855](https://github.com/Automattic/newspack-plugin/issues/1855)) ([4cda57d](https://github.com/Automattic/newspack-plugin/commit/4cda57d48656b41d5567a1cee7b593fe369ef208))
* **popups:** use new Campaigns method for creating donation events on new orders ([#1794](https://github.com/Automattic/newspack-plugin/issues/1794)) ([49dc14c](https://github.com/Automattic/newspack-plugin/commit/49dc14cbeb89bc4dc0b2614c14f8a923590ff44a))
* **reader-activation:** add metadata to reader registered on donation ([722724c](https://github.com/Automattic/newspack-plugin/commit/722724cc49b3aac35b81a3fc0da2f62a317c3cd1))
* **reader-activation:** handle modal conflict when auth is triggered from a prompt ([c2a0141](https://github.com/Automattic/newspack-plugin/commit/c2a014186d252fcc84bef560c0ac22f9c6f0c5da)), closes [#1835](https://github.com/Automattic/newspack-plugin/issues/1835)
* **reader-activation:** handle no lists config available ([23b0249](https://github.com/Automattic/newspack-plugin/commit/23b02491e9c2b954726437371d610fe64909463f))
* **reader-activation:** reinitialize auth links after DOM load ([#1812](https://github.com/Automattic/newspack-plugin/issues/1812)) ([0a4b499](https://github.com/Automattic/newspack-plugin/commit/0a4b49905c3fb9d9296fd171d8914f91df4f92c7))
* **reader-activation:** remove async prop from library ([#1846](https://github.com/Automattic/newspack-plugin/issues/1846)) ([4131ca6](https://github.com/Automattic/newspack-plugin/commit/4131ca675eae7db7ee6468af85392b678fb43b76))
* **reader-activation:** username generation handling ([#1789](https://github.com/Automattic/newspack-plugin/issues/1789)) ([17edf2a](https://github.com/Automattic/newspack-plugin/commit/17edf2adc8f4022d26757467e7d4066f61cdfd91))
* **registration-block:** don't escape html for sign in labels ([#1834](https://github.com/Automattic/newspack-plugin/issues/1834)) ([871300d](https://github.com/Automattic/newspack-plugin/commit/871300d8ac0cb127300bcd784c1f934780e6e887))
* **registration-block:** margin for success message ([#1808](https://github.com/Automattic/newspack-plugin/issues/1808)) ([1bfe546](https://github.com/Automattic/newspack-plugin/commit/1bfe546aa5cbc550cff975bc5f2fc73f553558f0))
* **registration-block:** render on preview ([#1844](https://github.com/Automattic/newspack-plugin/issues/1844)) ([87b9be9](https://github.com/Automattic/newspack-plugin/commit/87b9be9f8f26c61bc9e793318e0870b9fb5d309c))
* tweak arguments for magic link client hash ([#1862](https://github.com/Automattic/newspack-plugin/issues/1862)) ([8dcd45e](https://github.com/Automattic/newspack-plugin/commit/8dcd45e8b342869f04b5bdde3d29792fd4c196b3))


### Features

* **active-campaign:** metadata improvements ([#1851](https://github.com/Automattic/newspack-plugin/issues/1851)) ([48883af](https://github.com/Automattic/newspack-plugin/commit/48883afe7598e43463e76eee08d738da259035fe))
* **active-campaigns:** override is-new-contact for legacy contacts ([34dd9a2](https://github.com/Automattic/newspack-plugin/commit/34dd9a2d9a08c33005e94cc55ad585a65983f22d))
* **analytics:** send GA events on the server side ([#1828](https://github.com/Automattic/newspack-plugin/issues/1828)) ([3e384e1](https://github.com/Automattic/newspack-plugin/commit/3e384e16d390c11d1dd38c28e254b2c0e9dcc00d))
* **donations:** remove defaultFrequency from the configuration ([#1814](https://github.com/Automattic/newspack-plugin/issues/1814)) ([b6aa894](https://github.com/Automattic/newspack-plugin/commit/b6aa894bcf3088e2c679f594faf95d5f0ff72581))
* handle contact update w/out lists selection ([#1816](https://github.com/Automattic/newspack-plugin/issues/1816)) ([67574d1](https://github.com/Automattic/newspack-plugin/commit/67574d15438de7dd76839613ea5612b750d4cd5c))
* handle new frequency options in Campaigns dashbaord ([#1779](https://github.com/Automattic/newspack-plugin/issues/1779)) ([c770a7d](https://github.com/Automattic/newspack-plugin/commit/c770a7d15804ab70817a640a71b34bfe9ceba62f))
* if registering an email that already has an account, show different message ([#1849](https://github.com/Automattic/newspack-plugin/issues/1849)) ([bf48bc4](https://github.com/Automattic/newspack-plugin/commit/bf48bc462298b6df9cf36a8b97d7e72654e7ac64))
* **my-account:** stripe billing portal link ([#1761](https://github.com/Automattic/newspack-plugin/issues/1761)) ([3e69af1](https://github.com/Automattic/newspack-plugin/commit/3e69af1956dd24c89c2c2b313100bc01fa07df90)), closes [#1742](https://github.com/Automattic/newspack-plugin/issues/1742) [#1739](https://github.com/Automattic/newspack-plugin/issues/1739) [#1740](https://github.com/Automattic/newspack-plugin/issues/1740) [#1741](https://github.com/Automattic/newspack-plugin/issues/1741) [#1782](https://github.com/Automattic/newspack-plugin/issues/1782)
* **reader-activation:** account link and auth form ([#1754](https://github.com/Automattic/newspack-plugin/issues/1754)) ([b163664](https://github.com/Automattic/newspack-plugin/commit/b1636644e134724b2235e23e75c14b9af0e38091))
* **reader-activation:** activecampaign master list ([#1818](https://github.com/Automattic/newspack-plugin/issues/1818)) ([ecbbc47](https://github.com/Automattic/newspack-plugin/commit/ecbbc474930ce420dfe293e339f5c6d354f81f7d))
* **reader-activation:** disable 3rd party login buttons initially ([#1806](https://github.com/Automattic/newspack-plugin/issues/1806)) ([c806bfe](https://github.com/Automattic/newspack-plugin/commit/c806bfe005121e1a907b94b9954d917976805c22))
* **reader-activation:** optimistic account link ([#1847](https://github.com/Automattic/newspack-plugin/issues/1847)) ([85c550a](https://github.com/Automattic/newspack-plugin/commit/85c550a9aaa9156469efd59cc1a30b69164a0646))
* **reader-activation:** prevent updating user email in my-account ([7d49db4](https://github.com/Automattic/newspack-plugin/commit/7d49db4fa54738b5962302080661d9d76f9aebee))
* **reader-activation:** registration auth cookie control ([#1787](https://github.com/Automattic/newspack-plugin/issues/1787)) ([aeb0b5b](https://github.com/Automattic/newspack-plugin/commit/aeb0b5bbef9dc13d57872c90c7f5d87762745298))
* **reader-activation:** settings wizard ([#1773](https://github.com/Automattic/newspack-plugin/issues/1773)) ([aaff0de](https://github.com/Automattic/newspack-plugin/commit/aaff0deb1cd2c6f4b711c904c88051a198c6a6cd))
* **reader-auth:** make password login the first option, instead of login link ([1fe5ffa](https://github.com/Automattic/newspack-plugin/commit/1fe5ffae6aca9070465c58c0f51825ef3df911f6)), closes [#1809](https://github.com/Automattic/newspack-plugin/issues/1809)
* register anonymous single donors ([#1795](https://github.com/Automattic/newspack-plugin/issues/1795)) ([9e4f2f6](https://github.com/Automattic/newspack-plugin/commit/9e4f2f6cc9748dafc322f4c3c6d23b83fb021f83))
* **registration-block:** add success icon ([#1804](https://github.com/Automattic/newspack-plugin/issues/1804)) ([86c38f8](https://github.com/Automattic/newspack-plugin/commit/86c38f8a40e821fa40a1e3c1885c1736d38e6b84))
* **registration-block:** editable success state ([#1785](https://github.com/Automattic/newspack-plugin/issues/1785)) ([7dcea82](https://github.com/Automattic/newspack-plugin/commit/7dcea826a788d3219943137da64eb61fb6f623da)), closes [#1768](https://github.com/Automattic/newspack-plugin/issues/1768)
* **registration-block:** login with Google ([#1781](https://github.com/Automattic/newspack-plugin/issues/1781)) ([ed79c5c](https://github.com/Automattic/newspack-plugin/commit/ed79c5ca275b4353146f3e2d1975a642ab02ca02)), closes [#1774](https://github.com/Automattic/newspack-plugin/issues/1774)
* **registration-block:** newsletter subscription ([#1778](https://github.com/Automattic/newspack-plugin/issues/1778)) ([717b5b8](https://github.com/Automattic/newspack-plugin/commit/717b5b8f20660efd27c2351d60830b288996b8b9))
* reorganise donations wizard and use buttongroup for donation type ([#1824](https://github.com/Automattic/newspack-plugin/issues/1824)) ([f7b58ae](https://github.com/Automattic/newspack-plugin/commit/f7b58ae0fbc28524031c533855aa7c4c8c558f8e))
* replace WooCommerce’s login form with our own ([#1854](https://github.com/Automattic/newspack-plugin/issues/1854)) ([f5b24c4](https://github.com/Automattic/newspack-plugin/commit/f5b24c4dfd216e188a22439434bb2c0f56cb9b88))
* **rss:** adds offset feature ([#1790](https://github.com/Automattic/newspack-plugin/issues/1790)) ([321eff5](https://github.com/Automattic/newspack-plugin/commit/321eff533b5140986c5a7fd52546319dfb8b2125))
* send user metadata to AC ([#1793](https://github.com/Automattic/newspack-plugin/issues/1793)) ([03a15ba](https://github.com/Automattic/newspack-plugin/commit/03a15ba8b8e435d70a72250ae27f68a6042eb54c))
* set client id cookie; reader activation tweaks ([#1780](https://github.com/Automattic/newspack-plugin/issues/1780)) ([96a07ae](https://github.com/Automattic/newspack-plugin/commit/96a07ae3873d23775da826606582bd1a84342515))
* **stripe:** webhook auto-creation and validation ([365aed9](https://github.com/Automattic/newspack-plugin/commit/365aed937ccc8f7b03efe99d0ff3097149a6b37b))
* tweak registration block styling ([d83448e](https://github.com/Automattic/newspack-plugin/commit/d83448e4f69dfbcdda639df5b474c90fed348037))


### Reverts

* Revert "chore(release): 1.87.0 [skip ci]" ([ca8d55c](https://github.com/Automattic/newspack-plugin/commit/ca8d55cc239d26538a231b770c82a9c98a8d4400))

# [1.87.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.86.0...v1.87.0-alpha.1) (2022-07-14)


### Bug Fixes

* **donations:** numbers formatting if NRH is the platform ([525c166](https://github.com/Automattic/newspack-plugin/commit/525c16678e3aa14805c76f2657caacd15253e0b6)), closes [#1650](https://github.com/Automattic/newspack-plugin/issues/1650)
* fatal in RR wizard if not passing all params ([#1777](https://github.com/Automattic/newspack-plugin/issues/1777)) ([de2cfd1](https://github.com/Automattic/newspack-plugin/commit/de2cfd14ca5dd324b1fa64afd54ba3131118d2ce))
* include blocks' files in release ([a662d91](https://github.com/Automattic/newspack-plugin/commit/a662d91b472ae0fd1f83c46b79ff96a8d88d4466))
* linking buttons ([00e908a](https://github.com/Automattic/newspack-plugin/commit/00e908a005eac49daa24bd68edd89039941d35df))
* **woocommerce:** product creation ([#1763](https://github.com/Automattic/newspack-plugin/issues/1763)) ([0fb580d](https://github.com/Automattic/newspack-plugin/commit/0fb580d192c11eddc6651ef229bb83a880921e8c))


### Features

* **donations:** amounts and frequencies customisation ([#1753](https://github.com/Automattic/newspack-plugin/issues/1753)) ([cb1f888](https://github.com/Automattic/newspack-plugin/commit/cb1f888c3055e71d9c121fb5823cbdc5de6ff63d))
* **engagement:** manage newsletters subscription lists ([#1734](https://github.com/Automattic/newspack-plugin/issues/1734)) ([f514935](https://github.com/Automattic/newspack-plugin/commit/f514935e2d11c451c48e3278b425936b9ae18456))
* **reader-activation:** extended auth expiration ([#1752](https://github.com/Automattic/newspack-plugin/issues/1752)) ([4920a4d](https://github.com/Automattic/newspack-plugin/commit/4920a4d27809dd2ceddac028abe93347076204eb))
* **reader-activation:** registration block ([#1724](https://github.com/Automattic/newspack-plugin/issues/1724)) ([06e60ab](https://github.com/Automattic/newspack-plugin/commit/06e60aba65bf47d9fdd28c31b2af2fbddd291b55))
* **reader-activation:** restricted reader roles ([#1770](https://github.com/Automattic/newspack-plugin/issues/1770)) ([41682f2](https://github.com/Automattic/newspack-plugin/commit/41682f28f5f10268d43cc25a1ada481778c02657))

# [1.86.0](https://github.com/Automattic/newspack-plugin/compare/v1.85.2...v1.86.0) (2022-07-11)


### Bug Fixes

* **reader-revenue:** disable WC email if module will send email ([#1709](https://github.com/Automattic/newspack-plugin/issues/1709)) ([48e1613](https://github.com/Automattic/newspack-plugin/commit/48e16134c45bb0482cd59e224ec9a84fd20520f6)), closes [#1699](https://github.com/Automattic/newspack-plugin/issues/1699)


### Features

* ads onboarding ([#1678](https://github.com/Automattic/newspack-plugin/issues/1678)) ([80c0bf4](https://github.com/Automattic/newspack-plugin/commit/80c0bf4561071ca701cb2d74e8a5d370487c97d8))
* disable deactivate and delete for required plugins ([#1712](https://github.com/Automattic/newspack-plugin/issues/1712)) ([75afee8](https://github.com/Automattic/newspack-plugin/commit/75afee8a437abbaeabc593f1e7f67ec326654049))
* **experimental:** magic links ([#1668](https://github.com/Automattic/newspack-plugin/issues/1668)) ([02d9f82](https://github.com/Automattic/newspack-plugin/commit/02d9f82ea83296818439f53c5fe2a1ae52a0116c))
* **reader-revenue:** prevent creating duplicate stripe webhooks ([#1710](https://github.com/Automattic/newspack-plugin/issues/1710)) ([586e693](https://github.com/Automattic/newspack-plugin/commit/586e69311a689406b4657a349c6bd5b59e943719))

# [1.86.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.86.0-alpha.2...v1.86.0-alpha.3) (2022-07-08)


### Bug Fixes

* **donations:** numbers formatting if NRH is the platform ([924d6b9](https://github.com/Automattic/newspack-plugin/commit/924d6b93a8bbb57bb4e499949f5a54be8ad79208)), closes [#1650](https://github.com/Automattic/newspack-plugin/issues/1650)

## [1.85.2](https://github.com/Automattic/newspack-plugin/compare/v1.85.1...v1.85.2) (2022-07-08)


### Bug Fixes

* **donations:** numbers formatting if NRH is the platform ([924d6b9](https://github.com/Automattic/newspack-plugin/commit/924d6b93a8bbb57bb4e499949f5a54be8ad79208)), closes [#1650](https://github.com/Automattic/newspack-plugin/issues/1650)

## [1.85.1](https://github.com/Automattic/newspack-plugin/compare/v1.85.0...v1.85.1) (2022-07-07)


### Bug Fixes

* **amp-plus:** handle complianz-gdpr plugin ([0f7ee8a](https://github.com/Automattic/newspack-plugin/commit/0f7ee8a545216f048646c7e16f0e11ff420029af))

# [1.85.0](https://github.com/Automattic/newspack-plugin/compare/v1.84.1...v1.85.0) (2022-06-27)


### Bug Fixes

* **ads:** always allow service account credentials ([#1694](https://github.com/Automattic/newspack-plugin/issues/1694)) ([5f1c55d](https://github.com/Automattic/newspack-plugin/commit/5f1c55df776cc1016dedc1f60c1d716f9c5776c7))
* compare both IDs and labels for autocomplete selections ([#1679](https://github.com/Automattic/newspack-plugin/issues/1679)) ([cfec9b3](https://github.com/Automattic/newspack-plugin/commit/cfec9b30b00da9b6b6be9498b5461d2d1359f642))
* deactivate Salesforce syncing if RR platform is not Newspack ([#1669](https://github.com/Automattic/newspack-plugin/issues/1669)) ([32b4768](https://github.com/Automattic/newspack-plugin/commit/32b476837e14c1616dd1a879046c30b8c0e17808))
* oauth error handling ([#1687](https://github.com/Automattic/newspack-plugin/issues/1687)) ([902061c](https://github.com/Automattic/newspack-plugin/commit/902061c842ed621ec6e6090f87801edfb33ba389))


### Features

* **ads:** fixed height support for placements ([#1697](https://github.com/Automattic/newspack-plugin/issues/1697)) ([f71bb37](https://github.com/Automattic/newspack-plugin/commit/f71bb375e294dc50dda786f0aaef73822163e5d3))
* refactor protected pages handling, and make donation page protected ([#1686](https://github.com/Automattic/newspack-plugin/issues/1686)) ([2b2abf8](https://github.com/Automattic/newspack-plugin/commit/2b2abf8c5d53535d0acb54c888fa74ec3d02ec09))
* **rss:** add RSS Enhancements plugin to the core ([#1688](https://github.com/Automattic/newspack-plugin/issues/1688)) ([c38bfd6](https://github.com/Automattic/newspack-plugin/commit/c38bfd6864ba66e28e76c56a43c6a9d1f558c15a))
* simplify components and use Gutenberg's ([#1676](https://github.com/Automattic/newspack-plugin/issues/1676)) ([39a2474](https://github.com/Automattic/newspack-plugin/commit/39a247459b4151901799093e8bcd311d4f97abe7))

# [1.85.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.85.0-alpha.1...v1.85.0-alpha.2) (2022-06-24)


### Bug Fixes

* set HTTPS transport mode to "beacon" for non-AMP GA ([e350d84](https://github.com/Automattic/newspack-plugin/commit/e350d84afdee385edc5a460c52bf87340d64dfa1))

## [1.84.1](https://github.com/Automattic/newspack-plugin/compare/v1.84.0...v1.84.1) (2022-06-24)


### Bug Fixes

* set HTTPS transport mode to "beacon" for non-AMP GA ([e350d84](https://github.com/Automattic/newspack-plugin/commit/e350d84afdee385edc5a460c52bf87340d64dfa1))

# [1.84.0](https://github.com/Automattic/newspack-plugin/compare/v1.83.3...v1.84.0) (2022-06-13)


### Bug Fixes

* **ads:** resolve conflicts from hotfix merge ([#1685](https://github.com/Automattic/newspack-plugin/issues/1685)) ([8ce12cd](https://github.com/Automattic/newspack-plugin/commit/8ce12cd3b898fd89ed51331c6d2ef298bbab7f05))
* **reader-revenue:** initial order state with total of 0 ([7c30b09](https://github.com/Automattic/newspack-plugin/commit/7c30b09351cc2292726404bd3b7db95652bf1bc2))


### Features

* **ads:** handle gam default ad units ([#1654](https://github.com/Automattic/newspack-plugin/issues/1654)) ([321b98e](https://github.com/Automattic/newspack-plugin/commit/321b98e426bb74e03299878accab04ac8dcb7e08))
* **analytics:** automatically link GA4 with Site Kit ([#1698](https://github.com/Automattic/newspack-plugin/issues/1698)) ([266135f](https://github.com/Automattic/newspack-plugin/commit/266135fb9de7e88ec3ca097b29d7988b4f2f817c))
* reader registration on donate ([#1655](https://github.com/Automattic/newspack-plugin/issues/1655)) ([5821b57](https://github.com/Automattic/newspack-plugin/commit/5821b5785910baa14d7fcd774fad1dd34392e1ba))
* remove theme selection from setup wizard ([#1656](https://github.com/Automattic/newspack-plugin/issues/1656)) ([94e4580](https://github.com/Automattic/newspack-plugin/commit/94e4580042ddf8d55b93ca199a1cb4d64d678ca4))

# [1.84.0-alpha.5](https://github.com/Automattic/newspack-plugin/compare/v1.84.0-alpha.4...v1.84.0-alpha.5) (2022-06-10)


### Features

* **analytics:** automatically link GA4 with Site Kit ([#1698](https://github.com/Automattic/newspack-plugin/issues/1698)) ([266135f](https://github.com/Automattic/newspack-plugin/commit/266135fb9de7e88ec3ca097b29d7988b4f2f817c))

# [1.84.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.84.0-alpha.3...v1.84.0-alpha.4) (2022-06-07)


### Bug Fixes

* oauth error handling ([#1687](https://github.com/Automattic/newspack-plugin/issues/1687)) ([d2bfd9f](https://github.com/Automattic/newspack-plugin/commit/d2bfd9f163fe06b439009f8f2a885ea013c489af))

## [1.83.3](https://github.com/Automattic/newspack-plugin/compare/v1.83.2...v1.83.3) (2022-06-07)


### Bug Fixes

* oauth error handling ([#1687](https://github.com/Automattic/newspack-plugin/issues/1687)) ([d2bfd9f](https://github.com/Automattic/newspack-plugin/commit/d2bfd9f163fe06b439009f8f2a885ea013c489af))

## [1.83.3-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.83.2...v1.83.3-hotfix.1) (2022-06-07)


### Bug Fixes

* oauth error handling ([#1687](https://github.com/Automattic/newspack-plugin/issues/1687)) ([d2bfd9f](https://github.com/Automattic/newspack-plugin/commit/d2bfd9f163fe06b439009f8f2a885ea013c489af))

## [1.83.2](https://github.com/Automattic/newspack-plugin/compare/v1.83.1...v1.83.2) (2022-06-02)


### Bug Fixes

* **ads:** handle units from disconnected gam ([#1684](https://github.com/Automattic/newspack-plugin/issues/1684)) ([62ffa2b](https://github.com/Automattic/newspack-plugin/commit/62ffa2b5fd1cdea802ff4421d0c68c3c36c09d81))

## [1.83.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.83.1...v1.83.2-hotfix.1) (2022-06-02)


### Bug Fixes

* **ads:** handle units from disconnected gam ([a7236d7](https://github.com/Automattic/newspack-plugin/commit/a7236d7e194ce52168c29ab8387c10e3f0d2be7a))

## [1.83.1](https://github.com/Automattic/newspack-plugin/compare/v1.83.0...v1.83.1) (2022-05-30)


### Bug Fixes

* force alpha release ([34971d8](https://github.com/Automattic/newspack-plugin/commit/34971d807e19641388ebbf65216ce0a9e7fede92))

## [1.83.1-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.83.0...v1.83.1-alpha.1) (2022-05-27)


### Bug Fixes

* force alpha release ([34971d8](https://github.com/Automattic/newspack-plugin/commit/34971d807e19641388ebbf65216ce0a9e7fede92))

# [1.83.0](https://github.com/Automattic/newspack-plugin/compare/v1.82.2...v1.83.0) (2022-05-24)


### Features

* **amp:** add support for GA4 ([#1653](https://github.com/Automattic/newspack-plugin/issues/1653)) ([2beb990](https://github.com/Automattic/newspack-plugin/commit/2beb990d793710b6001526b5ce40c6405ca5edc8))

## [1.82.2](https://github.com/Automattic/newspack-plugin/compare/v1.82.1...v1.82.2) (2022-05-23)


### Bug Fixes

* **rss-feeds:** skip Jetpack lazy loading on RSS feeds ([58c64bf](https://github.com/Automattic/newspack-plugin/commit/58c64bfc1e73654e135fcc162e2ea5a12e290599))

## [1.82.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.82.1...v1.82.2-hotfix.1) (2022-05-23)


### Bug Fixes

* **rss-feeds:** skip Jetpack lazy loading on RSS feeds ([58c64bf](https://github.com/Automattic/newspack-plugin/commit/58c64bfc1e73654e135fcc162e2ea5a12e290599))

## [1.82.1](https://github.com/Automattic/newspack-plugin/compare/v1.82.0...v1.82.1) (2022-05-18)


### Bug Fixes

* **ads:** check plugin before using method ([#1651](https://github.com/Automattic/newspack-plugin/issues/1651)) ([c694059](https://github.com/Automattic/newspack-plugin/commit/c6940594dc4fe181b5956b9bfc1be24f4c6354ed))
* **woocommerce:** ensure task list exists before hiding ([#1636](https://github.com/Automattic/newspack-plugin/issues/1636)) ([06df216](https://github.com/Automattic/newspack-plugin/commit/06df2160f5d529ef0b6b392c5e394d08deba4b8d))

## [1.82.1-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.82.0...v1.82.1-alpha.1) (2022-05-05)


### Bug Fixes

* **woocommerce:** ensure task list exists before hiding ([#1636](https://github.com/Automattic/newspack-plugin/issues/1636)) ([06df216](https://github.com/Automattic/newspack-plugin/commit/06df2160f5d529ef0b6b392c5e394d08deba4b8d))

# [1.82.0](https://github.com/Automattic/newspack-plugin/compare/v1.81.0...v1.82.0) (2022-05-03)


### Bug Fixes

* **ads:** update refresh control help text ([#1601](https://github.com/Automattic/newspack-plugin/issues/1601)) ([fc70afc](https://github.com/Automattic/newspack-plugin/commit/fc70afc1f93fd59819f5d71569f64c764446ffdf))
* crashes with autocomplete inputs in Campaigns wizard and CategoryAutocomplete ([#1609](https://github.com/Automattic/newspack-plugin/issues/1609)) ([101d1d6](https://github.com/Automattic/newspack-plugin/commit/101d1d68497a0a0a843900531667e4ac42467d32))
* handle Yoast Premium as a replacement for Yoast ([#1614](https://github.com/Automattic/newspack-plugin/issues/1614)) ([9a503c0](https://github.com/Automattic/newspack-plugin/commit/9a503c06a9445b35f0c3af00c997ef5a1236356c)), closes [#298](https://github.com/Automattic/newspack-plugin/issues/298)
* **popups:** improve formatting of human-readable numbers ([#1603](https://github.com/Automattic/newspack-plugin/issues/1603)) ([3106f18](https://github.com/Automattic/newspack-plugin/commit/3106f180afb365d6dca55eb76bca2442d64ff51c))
* relax capabilities required to interact with Newspack ([04eb0be](https://github.com/Automattic/newspack-plugin/commit/04eb0be8fedbda216a07da9c2fe64a42bc9c0a73)), closes [#543](https://github.com/Automattic/newspack-plugin/issues/543)
* **salesforce:** a PHP warning on sync completion due to incorrect variable ([#1616](https://github.com/Automattic/newspack-plugin/issues/1616)) ([492a439](https://github.com/Automattic/newspack-plugin/commit/492a43955678af3421d919d952dd0105d0797591))


### Features

* **ads:** use ad sizes from plugin ([#1577](https://github.com/Automattic/newspack-plugin/issues/1577)) ([d238b08](https://github.com/Automattic/newspack-plugin/commit/d238b080da89441883fb5a0f7f4ec78a52a76e70))
* **donations-stripe:** integrate w/ woocommerce-memberships ([#1599](https://github.com/Automattic/newspack-plugin/issues/1599)) ([807e224](https://github.com/Automattic/newspack-plugin/commit/807e22484660df3182c62339928f3e0444862342))
* **setup:** notice on Setup wizard after setup completed ([#1610](https://github.com/Automattic/newspack-plugin/issues/1610)) ([22d6b1c](https://github.com/Automattic/newspack-plugin/commit/22d6b1c943a530195220d0fb0b9f322ff09daf10)), closes [#1561](https://github.com/Automattic/newspack-plugin/issues/1561)
* **starter-content:** add excerpt & subtitle ([36c4452](https://github.com/Automattic/newspack-plugin/commit/36c44521ace54dc39b2bf6ad4dcfc0d874112cc6)), closes [#514](https://github.com/Automattic/newspack-plugin/issues/514)
* update debug mode notice style and position ([#1605](https://github.com/Automattic/newspack-plugin/issues/1605)) ([b28075c](https://github.com/Automattic/newspack-plugin/commit/b28075cb504af1da3b37a487eca1316eb7bae629))
* **woocommerce:** hide setup task list ([#1615](https://github.com/Automattic/newspack-plugin/issues/1615)) ([78854e8](https://github.com/Automattic/newspack-plugin/commit/78854e8015bfb5ca023e2a216e7b0a3b27b74375)), closes [#1156](https://github.com/Automattic/newspack-plugin/issues/1156)

# [1.82.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.81.0...v1.82.0-alpha.1) (2022-05-02)


### Bug Fixes

* **ads:** update refresh control help text ([#1601](https://github.com/Automattic/newspack-plugin/issues/1601)) ([fc70afc](https://github.com/Automattic/newspack-plugin/commit/fc70afc1f93fd59819f5d71569f64c764446ffdf))
* crashes with autocomplete inputs in Campaigns wizard and CategoryAutocomplete ([#1609](https://github.com/Automattic/newspack-plugin/issues/1609)) ([101d1d6](https://github.com/Automattic/newspack-plugin/commit/101d1d68497a0a0a843900531667e4ac42467d32))
* handle Yoast Premium as a replacement for Yoast ([#1614](https://github.com/Automattic/newspack-plugin/issues/1614)) ([9a503c0](https://github.com/Automattic/newspack-plugin/commit/9a503c06a9445b35f0c3af00c997ef5a1236356c)), closes [#298](https://github.com/Automattic/newspack-plugin/issues/298)
* **popups:** improve formatting of human-readable numbers ([#1603](https://github.com/Automattic/newspack-plugin/issues/1603)) ([3106f18](https://github.com/Automattic/newspack-plugin/commit/3106f180afb365d6dca55eb76bca2442d64ff51c))
* relax capabilities required to interact with Newspack ([04eb0be](https://github.com/Automattic/newspack-plugin/commit/04eb0be8fedbda216a07da9c2fe64a42bc9c0a73)), closes [#543](https://github.com/Automattic/newspack-plugin/issues/543)
* **salesforce:** a PHP warning on sync completion due to incorrect variable ([#1616](https://github.com/Automattic/newspack-plugin/issues/1616)) ([492a439](https://github.com/Automattic/newspack-plugin/commit/492a43955678af3421d919d952dd0105d0797591))


### Features

* **ads:** use ad sizes from plugin ([#1577](https://github.com/Automattic/newspack-plugin/issues/1577)) ([d238b08](https://github.com/Automattic/newspack-plugin/commit/d238b080da89441883fb5a0f7f4ec78a52a76e70))
* **donations-stripe:** integrate w/ woocommerce-memberships ([#1599](https://github.com/Automattic/newspack-plugin/issues/1599)) ([807e224](https://github.com/Automattic/newspack-plugin/commit/807e22484660df3182c62339928f3e0444862342))
* **setup:** notice on Setup wizard after setup completed ([#1610](https://github.com/Automattic/newspack-plugin/issues/1610)) ([22d6b1c](https://github.com/Automattic/newspack-plugin/commit/22d6b1c943a530195220d0fb0b9f322ff09daf10)), closes [#1561](https://github.com/Automattic/newspack-plugin/issues/1561)
* **starter-content:** add excerpt & subtitle ([36c4452](https://github.com/Automattic/newspack-plugin/commit/36c44521ace54dc39b2bf6ad4dcfc0d874112cc6)), closes [#514](https://github.com/Automattic/newspack-plugin/issues/514)
* update debug mode notice style and position ([#1605](https://github.com/Automattic/newspack-plugin/issues/1605)) ([b28075c](https://github.com/Automattic/newspack-plugin/commit/b28075cb504af1da3b37a487eca1316eb7bae629))
* **woocommerce:** hide setup task list ([#1615](https://github.com/Automattic/newspack-plugin/issues/1615)) ([78854e8](https://github.com/Automattic/newspack-plugin/commit/78854e8015bfb5ca023e2a216e7b0a3b27b74375)), closes [#1156](https://github.com/Automattic/newspack-plugin/issues/1156)

# [1.81.0](https://github.com/Automattic/newspack-plugin/compare/v1.80.1...v1.81.0) (2022-04-18)


### Bug Fixes

* enable block-based widgets ([#1550](https://github.com/Automattic/newspack-plugin/issues/1550)) ([270c2ea](https://github.com/Automattic/newspack-plugin/commit/270c2ea2336d26dcdb643e1b4d28483a82655af4))
* **setup-wizard:** hide header on completed step ([#1591](https://github.com/Automattic/newspack-plugin/issues/1591)) ([9e6fbc7](https://github.com/Automattic/newspack-plugin/commit/9e6fbc7de8d720a7742e080787dde436cd34b2c4))
* **stripe:** newsletters wizard typo ([#1594](https://github.com/Automattic/newspack-plugin/issues/1594)) ([391f1c1](https://github.com/Automattic/newspack-plugin/commit/391f1c110207fdb39420500a1aa40e035d85c531))


### Features

* remove support wizard ([fccc3b8](https://github.com/Automattic/newspack-plugin/commit/fccc3b8f2f1b2a1c50619ed37a9e846c620e5045))
* **stripe-donations:** update WooCommerce on successful donation ([#1593](https://github.com/Automattic/newspack-plugin/issues/1593)) ([6f440c3](https://github.com/Automattic/newspack-plugin/commit/6f440c313fa6d58114a0e428ae193db339ea428c))

# [1.81.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.81.0-alpha.2...v1.81.0-alpha.3) (2022-04-11)


### Bug Fixes

* **stripe:** newsletters wizard typo ([#1594](https://github.com/Automattic/newspack-plugin/issues/1594)) ([391f1c1](https://github.com/Automattic/newspack-plugin/commit/391f1c110207fdb39420500a1aa40e035d85c531))


### Features

* **stripe-donations:** update WooCommerce on successful donation ([#1593](https://github.com/Automattic/newspack-plugin/issues/1593)) ([6f440c3](https://github.com/Automattic/newspack-plugin/commit/6f440c313fa6d58114a0e428ae193db339ea428c))

# [1.81.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.81.0-alpha.1...v1.81.0-alpha.2) (2022-04-11)


### Bug Fixes

* incorrect regex pattern ([d885517](https://github.com/Automattic/newspack-plugin/commit/d885517895156c8dc79f37de3f867c4e30862d76))
* look for post ID as the last regex match ([2bfc1bd](https://github.com/Automattic/newspack-plugin/commit/2bfc1bd1bdaf05f7c8c011d59245b528e9b702d9))
* **popups:** analytics popups report label regex ([56e72f2](https://github.com/Automattic/newspack-plugin/commit/56e72f223cf3c211bac7cb94f37f35d7822136db))
* update regex to look for end-of-line OR hyphen separator ([111b63a](https://github.com/Automattic/newspack-plugin/commit/111b63a9319ed3a78dc10479e30daf83df54d2e3))

## [1.80.1](https://github.com/Automattic/newspack-plugin/compare/v1.80.0...v1.80.1) (2022-04-11)


### Bug Fixes

* incorrect regex pattern ([d885517](https://github.com/Automattic/newspack-plugin/commit/d885517895156c8dc79f37de3f867c4e30862d76))
* look for post ID as the last regex match ([2bfc1bd](https://github.com/Automattic/newspack-plugin/commit/2bfc1bd1bdaf05f7c8c011d59245b528e9b702d9))
* **popups:** analytics popups report label regex ([56e72f2](https://github.com/Automattic/newspack-plugin/commit/56e72f223cf3c211bac7cb94f37f35d7822136db))
* update regex to look for end-of-line OR hyphen separator ([111b63a](https://github.com/Automattic/newspack-plugin/commit/111b63a9319ed3a78dc10479e30daf83df54d2e3))

## [1.80.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.80.0...v1.80.1-hotfix.1) (2022-04-08)


### Bug Fixes

* **popups:** analytics popups report label regex ([56e72f2](https://github.com/Automattic/newspack-plugin/commit/56e72f223cf3c211bac7cb94f37f35d7822136db))

# [1.80.0](https://github.com/Automattic/newspack-plugin/compare/v1.79.1...v1.80.0) (2022-04-05)


### Bug Fixes

* **ads:** add-ons download methods ([#1585](https://github.com/Automattic/newspack-plugin/issues/1585)) ([e93dbcb](https://github.com/Automattic/newspack-plugin/commit/e93dbcbfa4f7fa4b85d14aa9c09c164c58bcccb1))
* **ads:** clearing ad refresh control input ([#1587](https://github.com/Automattic/newspack-plugin/issues/1587)) ([8e6723f](https://github.com/Automattic/newspack-plugin/commit/8e6723fcc2584fd07131736b98ead9bebfed959e))
* handle gam connection error ([#1573](https://github.com/Automattic/newspack-plugin/issues/1573)) ([574eeb3](https://github.com/Automattic/newspack-plugin/commit/574eeb372aac7dff98f1e304693b89834f28006f))


### Features

* **ads:** Add-Ons and Ad Refresh Control integration ([#1564](https://github.com/Automattic/newspack-plugin/issues/1564)) ([2964da6](https://github.com/Automattic/newspack-plugin/commit/2964da6ec7c1745efc65658d3fdc3b6106292210))
* **ads:** integrate Broadstreet into the providers wizard ([#1465](https://github.com/Automattic/newspack-plugin/issues/1465)) ([93edf0f](https://github.com/Automattic/newspack-plugin/commit/93edf0ff3911d943d7572dd17e1f9001d598875d))
* allow segmentation by user login status ([#1563](https://github.com/Automattic/newspack-plugin/issues/1563)) ([4fd7ee9](https://github.com/Automattic/newspack-plugin/commit/4fd7ee922534576ac033215561de896b8f5a0944))
* **donations:** remove sidebar for default donations page ([bf10c27](https://github.com/Automattic/newspack-plugin/commit/bf10c27631e8dd6f410552fd89af4268fdbcd435))
* **popups:** add category/tag exclusion fields to campaigns wizard UI ([#1553](https://github.com/Automattic/newspack-plugin/issues/1553)) ([6b80fb8](https://github.com/Automattic/newspack-plugin/commit/6b80fb8840f12dee93faf020e13abb0a9d7794b6))

# [1.80.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.80.0-alpha.2...v1.80.0-alpha.3) (2022-04-05)


### Bug Fixes

* **ads:** clearing ad refresh control input ([#1587](https://github.com/Automattic/newspack-plugin/issues/1587)) ([8e6723f](https://github.com/Automattic/newspack-plugin/commit/8e6723fcc2584fd07131736b98ead9bebfed959e))

# [1.80.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.80.0-alpha.1...v1.80.0-alpha.2) (2022-04-04)


### Bug Fixes

* **ads:** add-ons download methods ([#1585](https://github.com/Automattic/newspack-plugin/issues/1585)) ([e93dbcb](https://github.com/Automattic/newspack-plugin/commit/e93dbcbfa4f7fa4b85d14aa9c09c164c58bcccb1))

# [1.80.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.79.1...v1.80.0-alpha.1) (2022-03-31)


### Bug Fixes

* handle gam connection error ([#1573](https://github.com/Automattic/newspack-plugin/issues/1573)) ([574eeb3](https://github.com/Automattic/newspack-plugin/commit/574eeb372aac7dff98f1e304693b89834f28006f))


### Features

* **ads:** Add-Ons and Ad Refresh Control integration ([#1564](https://github.com/Automattic/newspack-plugin/issues/1564)) ([2964da6](https://github.com/Automattic/newspack-plugin/commit/2964da6ec7c1745efc65658d3fdc3b6106292210))
* **ads:** integrate Broadstreet into the providers wizard ([#1465](https://github.com/Automattic/newspack-plugin/issues/1465)) ([93edf0f](https://github.com/Automattic/newspack-plugin/commit/93edf0ff3911d943d7572dd17e1f9001d598875d))
* allow segmentation by user login status ([#1563](https://github.com/Automattic/newspack-plugin/issues/1563)) ([4fd7ee9](https://github.com/Automattic/newspack-plugin/commit/4fd7ee922534576ac033215561de896b8f5a0944))
* **donations:** remove sidebar for default donations page ([bf10c27](https://github.com/Automattic/newspack-plugin/commit/bf10c27631e8dd6f410552fd89af4268fdbcd435))
* **popups:** add category/tag exclusion fields to campaigns wizard UI ([#1553](https://github.com/Automattic/newspack-plugin/issues/1553)) ([6b80fb8](https://github.com/Automattic/newspack-plugin/commit/6b80fb8840f12dee93faf020e13abb0a9d7794b6))

## [1.79.1](https://github.com/Automattic/newspack-plugin/compare/v1.79.0...v1.79.1) (2022-03-24)


### Bug Fixes

* **jetpack:** ensure instant search display filters ([#1572](https://github.com/Automattic/newspack-plugin/issues/1572)) ([a9320d7](https://github.com/Automattic/newspack-plugin/commit/a9320d76c06bf1687234df063825468b48af447a))

## [1.79.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.79.0...v1.79.1-hotfix.1) (2022-03-23)


### Bug Fixes

* **jetpack:** ensure instant search display filters ([8744151](https://github.com/Automattic/newspack-plugin/commit/874415166242199d851dc079812880df20457ea9))

# [1.79.0](https://github.com/Automattic/newspack-plugin/compare/v1.78.0...v1.79.0) (2022-03-22)


### Bug Fixes

* a bad merge after the last post-release job ([c269195](https://github.com/Automattic/newspack-plugin/commit/c269195bc5ae8904711b22feece0f3aca4523e2b))
* **design:** header defaults in line with the theme ([6c63d1a](https://github.com/Automattic/newspack-plugin/commit/6c63d1a7269d5cc2126733ab98a6a4529cc814f3))
* fix TEC posts block date issues ([#1518](https://github.com/Automattic/newspack-plugin/issues/1518)) ([1c4c501](https://github.com/Automattic/newspack-plugin/commit/1c4c501f7b94d96e73b0b03cdd3ab14301aa6f69))
* logic for PluginSettings styles and functionality ([#1533](https://github.com/Automattic/newspack-plugin/issues/1533)) ([b05ba72](https://github.com/Automattic/newspack-plugin/commit/b05ba72da7288417e295c9714cc7e0f31ffb9391))
* remove 'www' from parse.ly api key generation ([#1542](https://github.com/Automattic/newspack-plugin/issues/1542)) ([7831868](https://github.com/Automattic/newspack-plugin/commit/78318689ffc669cf85266b9cce5daf83f5a75714))
* reusable Blocks menu item minimum capability ([#1549](https://github.com/Automattic/newspack-plugin/issues/1549)) ([ec142b7](https://github.com/Automattic/newspack-plugin/commit/ec142b7fe7d4db1419e17ea40e96e3c4d11c074d))
* **starter-content:** prevent starter homepage deletion ([a82f22a](https://github.com/Automattic/newspack-plugin/commit/a82f22af3b54213547b9c8669ecfaa3315deaa77)), closes [#1538](https://github.com/Automattic/newspack-plugin/issues/1538)
* stripe data setting ([0851592](https://github.com/Automattic/newspack-plugin/commit/085159247475f56ab7fa529b57c8fedaffcf1b04))


### Features

* **ads:** placement providers ([#1521](https://github.com/Automattic/newspack-plugin/issues/1521)) ([2d60688](https://github.com/Automattic/newspack-plugin/commit/2d60688b072536d2d3f0c9cc7fe6b96869d54840))
* restrict access to others' posts for non-admin/editor users ([#1541](https://github.com/Automattic/newspack-plugin/issues/1541)) ([dee8fe8](https://github.com/Automattic/newspack-plugin/commit/dee8fe8b39e247f5c9d89c917c7ef4677cbd316c)), closes [#1518](https://github.com/Automattic/newspack-plugin/issues/1518)
* update plugin-settings ([#1525](https://github.com/Automattic/newspack-plugin/issues/1525)) ([a3649c8](https://github.com/Automattic/newspack-plugin/commit/a3649c8629c6a6ae81421bf7f4137a62e4036756))

# [1.79.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.78.0...v1.79.0-alpha.1) (2022-03-15)


### Bug Fixes

* a bad merge after the last post-release job ([c269195](https://github.com/Automattic/newspack-plugin/commit/c269195bc5ae8904711b22feece0f3aca4523e2b))
* **design:** header defaults in line with the theme ([6c63d1a](https://github.com/Automattic/newspack-plugin/commit/6c63d1a7269d5cc2126733ab98a6a4529cc814f3))
* fix TEC posts block date issues ([#1518](https://github.com/Automattic/newspack-plugin/issues/1518)) ([1c4c501](https://github.com/Automattic/newspack-plugin/commit/1c4c501f7b94d96e73b0b03cdd3ab14301aa6f69))
* logic for PluginSettings styles and functionality ([#1533](https://github.com/Automattic/newspack-plugin/issues/1533)) ([b05ba72](https://github.com/Automattic/newspack-plugin/commit/b05ba72da7288417e295c9714cc7e0f31ffb9391))
* remove 'www' from parse.ly api key generation ([#1542](https://github.com/Automattic/newspack-plugin/issues/1542)) ([7831868](https://github.com/Automattic/newspack-plugin/commit/78318689ffc669cf85266b9cce5daf83f5a75714))
* reusable Blocks menu item minimum capability ([#1549](https://github.com/Automattic/newspack-plugin/issues/1549)) ([ec142b7](https://github.com/Automattic/newspack-plugin/commit/ec142b7fe7d4db1419e17ea40e96e3c4d11c074d))
* **starter-content:** prevent starter homepage deletion ([a82f22a](https://github.com/Automattic/newspack-plugin/commit/a82f22af3b54213547b9c8669ecfaa3315deaa77)), closes [#1538](https://github.com/Automattic/newspack-plugin/issues/1538)
* stripe data setting ([0851592](https://github.com/Automattic/newspack-plugin/commit/085159247475f56ab7fa529b57c8fedaffcf1b04))


### Features

* **ads:** placement providers ([#1521](https://github.com/Automattic/newspack-plugin/issues/1521)) ([2d60688](https://github.com/Automattic/newspack-plugin/commit/2d60688b072536d2d3f0c9cc7fe6b96869d54840))
* restrict access to others' posts for non-admin/editor users ([#1541](https://github.com/Automattic/newspack-plugin/issues/1541)) ([dee8fe8](https://github.com/Automattic/newspack-plugin/commit/dee8fe8b39e247f5c9d89c917c7ef4677cbd316c)), closes [#1518](https://github.com/Automattic/newspack-plugin/issues/1518)
* update plugin-settings ([#1525](https://github.com/Automattic/newspack-plugin/issues/1525)) ([a3649c8](https://github.com/Automattic/newspack-plugin/commit/a3649c8629c6a6ae81421bf7f4137a62e4036756))

# [1.78.0](https://github.com/Automattic/newspack-plugin/compare/v1.77.3...v1.78.0) (2022-03-08)


### Bug Fixes

* initial theme mods setting ([#1500](https://github.com/Automattic/newspack-plugin/issues/1500)) ([2d3de6b](https://github.com/Automattic/newspack-plugin/commit/2d3de6bb674d3617d74661a456ac236fa2b45625)), closes [#1093](https://github.com/Automattic/newspack-plugin/issues/1093)
* remove animation fill mode for modals ([#1517](https://github.com/Automattic/newspack-plugin/issues/1517)) ([a5c0459](https://github.com/Automattic/newspack-plugin/commit/a5c04593e3ddeaca40d7a41969d1a1ad5c68cbf8))
* **setup-wizard:** hide navigation on welcome screen ([a6fad4e](https://github.com/Automattic/newspack-plugin/commit/a6fad4e5b5da065a02dfe83bb89cf00a908b16b7))
* stripe data setting ([ceec544](https://github.com/Automattic/newspack-plugin/commit/ceec544cc1f4dd7b56014863c83e82f978f4884e))


### Features

* **ad-units:** move edit/archive links to popover ([#1505](https://github.com/Automattic/newspack-plugin/issues/1505)) ([5177fe6](https://github.com/Automattic/newspack-plugin/commit/5177fe65bbc8fa382244b1f4e0d78e462623a5ff))
* **stripe:** donate flow; location code ([#1483](https://github.com/Automattic/newspack-plugin/issues/1483)) ([8cd28f9](https://github.com/Automattic/newspack-plugin/commit/8cd28f923f572ac34d6e1b96c0f821a9f432e09a))

# [1.78.0-alpha.4](https://github.com/Automattic/newspack-plugin/compare/v1.78.0-alpha.3...v1.78.0-alpha.4) (2022-03-03)


### Bug Fixes

* stripe data setting ([ceec544](https://github.com/Automattic/newspack-plugin/commit/ceec544cc1f4dd7b56014863c83e82f978f4884e))

# [1.78.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.78.0-alpha.2...v1.78.0-alpha.3) (2022-03-01)


### Bug Fixes

* **analytics:** avoid Site Kit crash due to conflict with HandoffBanner ([#1537](https://github.com/Automattic/newspack-plugin/issues/1537)) ([68d3947](https://github.com/Automattic/newspack-plugin/commit/68d394727c66d22c2daf3480d163132f8dd2d66c))

## [1.77.3](https://github.com/Automattic/newspack-plugin/compare/v1.77.2...v1.77.3) (2022-03-01)


### Bug Fixes

* **analytics:** avoid Site Kit crash due to conflict with HandoffBanner ([#1537](https://github.com/Automattic/newspack-plugin/issues/1537)) ([68d3947](https://github.com/Automattic/newspack-plugin/commit/68d394727c66d22c2daf3480d163132f8dd2d66c))

## [1.77.2](https://github.com/Automattic/newspack-plugin/compare/v1.77.1...v1.77.2) (2022-02-24)


### Bug Fixes

* **jetpack:** modules scripts behind constant ([#1527](https://github.com/Automattic/newspack-plugin/issues/1527)) ([951d4d3](https://github.com/Automattic/newspack-plugin/commit/951d4d35c1605cb5dc0ad8758fee1ea1b606f546))

## [1.77.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.77.1...v1.77.2-hotfix.1) (2022-02-24)


### Bug Fixes

* **jetpack:** modules scripts behind constant ([53e088b](https://github.com/Automattic/newspack-plugin/commit/53e088b1c601c8197c41e9c2b8c65ae4ee7675f2))

## [1.77.1](https://github.com/Automattic/newspack-plugin/compare/v1.77.0...v1.77.1) (2022-02-23)


### Bug Fixes

* **reader-revenue:** return saved settings ([#1526](https://github.com/Automattic/newspack-plugin/issues/1526)) ([04e8efa](https://github.com/Automattic/newspack-plugin/commit/04e8efa88fc8b6049cc520b0cbc9811985ab0f37))

## [1.77.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.77.0...v1.77.1-hotfix.1) (2022-02-23)


### Bug Fixes

* **reader-revenue:** return saved settings ([1a81dae](https://github.com/Automattic/newspack-plugin/commit/1a81daec62d5db062751fa41e4257581e1aade57))

# [1.77.0](https://github.com/Automattic/newspack-plugin/compare/v1.76.0...v1.77.0) (2022-02-22)


### Bug Fixes

* **donations:** filter saved settings ([f09a19d](https://github.com/Automattic/newspack-plugin/commit/f09a19d11817b042c5639600d5088920d809f946)), closes [#1392](https://github.com/Automattic/newspack-plugin/issues/1392)
* **parsely:** configuring Parse.ly ([9815511](https://github.com/Automattic/newspack-plugin/commit/9815511fd6db34d4b9e41c4a6f5b3fa0dc4abf7d))
* **setup-wizard:** donation data ([#1481](https://github.com/Automattic/newspack-plugin/issues/1481)) ([14618d1](https://github.com/Automattic/newspack-plugin/commit/14618d1f36f12bd37bf1bd6667bd9606cf6df945))
* **setup-wizard:** hide navigation on welcome screen (see [#1509](https://github.com/Automattic/newspack-plugin/issues/1509)) ([183757b](https://github.com/Automattic/newspack-plugin/commit/183757b903823d5c12382523df20699cadc3ee6c))
* **stripe:** prevent webhook processing if platform is not Stripe ([3a8cfa7](https://github.com/Automattic/newspack-plugin/commit/3a8cfa794806ee7d7b61ab4481587f51e631f60f))
* tooltip position in header and remove duplicated css ([#1467](https://github.com/Automattic/newspack-plugin/issues/1467)) ([a869a8e](https://github.com/Automattic/newspack-plugin/commit/a869a8e8a14cb66e690dd6b9fd9db108345b288c))


### Features

* add sticky position to tabbednavigation ([#1496](https://github.com/Automattic/newspack-plugin/issues/1496)) ([67da609](https://github.com/Automattic/newspack-plugin/commit/67da60984907723ef0ec4b715c5046b2b0996ece))
* **block-editor:** utility to relink the editor close button to a wizard screen ([#1482](https://github.com/Automattic/newspack-plugin/issues/1482)) ([870f630](https://github.com/Automattic/newspack-plugin/commit/870f6308f60806935ac86f5f0c40e8195388cffe)), closes [#1205](https://github.com/Automattic/newspack-plugin/issues/1205)
* enable AMP Plus for Jetpack Instant Search ([#1486](https://github.com/Automattic/newspack-plugin/issues/1486)) ([e62c5ba](https://github.com/Automattic/newspack-plugin/commit/e62c5ba0710017a4dd788954804545edb8d4383f))
* support multiple control for plugin settings ([#1475](https://github.com/Automattic/newspack-plugin/issues/1475)) ([e00064c](https://github.com/Automattic/newspack-plugin/commit/e00064c3940b20b7bc580d5593da758aebcac05a))
* use PluginSettings components for Campaigns wizard ([#1450](https://github.com/Automattic/newspack-plugin/issues/1450)) ([fe8e8aa](https://github.com/Automattic/newspack-plugin/commit/fe8e8aac524fd6d741cfc105968a3b13a7224757))

# [1.77.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.76.0...v1.77.0-alpha.1) (2022-02-15)


### Bug Fixes

* **donations:** filter saved settings ([f09a19d](https://github.com/Automattic/newspack-plugin/commit/f09a19d11817b042c5639600d5088920d809f946)), closes [#1392](https://github.com/Automattic/newspack-plugin/issues/1392)
* **parsely:** configuring Parse.ly ([9815511](https://github.com/Automattic/newspack-plugin/commit/9815511fd6db34d4b9e41c4a6f5b3fa0dc4abf7d))
* **setup-wizard:** donation data ([#1481](https://github.com/Automattic/newspack-plugin/issues/1481)) ([14618d1](https://github.com/Automattic/newspack-plugin/commit/14618d1f36f12bd37bf1bd6667bd9606cf6df945))
* **setup-wizard:** hide navigation on welcome screen (see [#1509](https://github.com/Automattic/newspack-plugin/issues/1509)) ([183757b](https://github.com/Automattic/newspack-plugin/commit/183757b903823d5c12382523df20699cadc3ee6c))
* **stripe:** prevent webhook processing if platform is not Stripe ([3a8cfa7](https://github.com/Automattic/newspack-plugin/commit/3a8cfa794806ee7d7b61ab4481587f51e631f60f))
* tooltip position in header and remove duplicated css ([#1467](https://github.com/Automattic/newspack-plugin/issues/1467)) ([a869a8e](https://github.com/Automattic/newspack-plugin/commit/a869a8e8a14cb66e690dd6b9fd9db108345b288c))


### Features

* add sticky position to tabbednavigation ([#1496](https://github.com/Automattic/newspack-plugin/issues/1496)) ([67da609](https://github.com/Automattic/newspack-plugin/commit/67da60984907723ef0ec4b715c5046b2b0996ece))
* **block-editor:** utility to relink the editor close button to a wizard screen ([#1482](https://github.com/Automattic/newspack-plugin/issues/1482)) ([870f630](https://github.com/Automattic/newspack-plugin/commit/870f6308f60806935ac86f5f0c40e8195388cffe)), closes [#1205](https://github.com/Automattic/newspack-plugin/issues/1205)
* enable AMP Plus for Jetpack Instant Search ([#1486](https://github.com/Automattic/newspack-plugin/issues/1486)) ([e62c5ba](https://github.com/Automattic/newspack-plugin/commit/e62c5ba0710017a4dd788954804545edb8d4383f))
* support multiple control for plugin settings ([#1475](https://github.com/Automattic/newspack-plugin/issues/1475)) ([e00064c](https://github.com/Automattic/newspack-plugin/commit/e00064c3940b20b7bc580d5593da758aebcac05a))
* use PluginSettings components for Campaigns wizard ([#1450](https://github.com/Automattic/newspack-plugin/issues/1450)) ([fe8e8aa](https://github.com/Automattic/newspack-plugin/commit/fe8e8aac524fd6d741cfc105968a3b13a7224757))

# [1.76.0](https://github.com/Automattic/newspack-plugin/compare/v1.75.2...v1.76.0) (2022-02-15)


### Features

* **fivetran-connection:** initial schema changes handling ([#1515](https://github.com/Automattic/newspack-plugin/issues/1515)) ([adcd904](https://github.com/Automattic/newspack-plugin/commit/adcd9041c31e42ba52d69614aa8eec28503cf10e))

# [1.76.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.75.2...v1.76.0-hotfix.1) (2022-02-14)


### Features

* **fivetran-connection:** initial schema changes handling ([664d93c](https://github.com/Automattic/newspack-plugin/commit/664d93cb601fec5c207639327313f5c882118a67))
* remove initial handling of connectors ([6ab5755](https://github.com/Automattic/newspack-plugin/commit/6ab5755c6ae069c12c1355606c4f98d786dde5b3))
* tweak TOS checkbox ([8a6d365](https://github.com/Automattic/newspack-plugin/commit/8a6d3653de540e86a6a358ddee9340d4585cb245))
* UI tweak ([63cf883](https://github.com/Automattic/newspack-plugin/commit/63cf8830ff3756bd5eb059e52a06b4a959641606))

## [1.75.2](https://github.com/Automattic/newspack-plugin/compare/v1.75.1...v1.75.2) (2022-02-10)


### Bug Fixes

* **woocommerce:** disable publicize sharing for WooCommerce product post types ([6cf0060](https://github.com/Automattic/newspack-plugin/commit/6cf006092d0097a7fd7a7adf5aa375fe8151bbaf))

## [1.75.2-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.75.1...v1.75.2-hotfix.1) (2022-02-10)


### Bug Fixes

* **woocommerce:** disable publicize sharing for WooCommerce product post types ([6cf0060](https://github.com/Automattic/newspack-plugin/commit/6cf006092d0097a7fd7a7adf5aa375fe8151bbaf))

## [1.75.1](https://github.com/Automattic/newspack-plugin/compare/v1.75.0...v1.75.1) (2022-02-09)


### Bug Fixes

* lookup user by queried slug, not by login ([f18709c](https://github.com/Automattic/newspack-plugin/commit/f18709cc9564b810185e88f10bbe7ad3462b2580))

# [1.75.0](https://github.com/Automattic/newspack-plugin/compare/v1.74.0...v1.75.0) (2022-02-08)


### Features

* **connections-fivetran:** add TOS acceptance ([#1423](https://github.com/Automattic/newspack-plugin/issues/1423)) ([27334d9](https://github.com/Automattic/newspack-plugin/commit/27334d92921251aae0d8be81c1c1bef611f6ffce))
* display all ESPs during the onboarding ([#1449](https://github.com/Automattic/newspack-plugin/issues/1449)) ([40cb86f](https://github.com/Automattic/newspack-plugin/commit/40cb86fc71ff168d0b2139ce7f99ab8426da7a19))
* remove integrations from onboarding and add them to connections wizard ([#1453](https://github.com/Automattic/newspack-plugin/issues/1453)) ([053675b](https://github.com/Automattic/newspack-plugin/commit/053675bd70f8dc764f9671d87687863254180dd0))
* **salesforce:** check duplicate site status and disable syncs if clone ([#1425](https://github.com/Automattic/newspack-plugin/issues/1425)) ([2197c93](https://github.com/Automattic/newspack-plugin/commit/2197c9338fcc375f5b37e7fd307d32d6e3468c96))
* settings section hooks ([#1378](https://github.com/Automattic/newspack-plugin/issues/1378)) ([0a26533](https://github.com/Automattic/newspack-plugin/commit/0a26533be5361729ddfd649196822ec23b72abe9))
* update wizard overall design and reinstate sub-header text ([#1457](https://github.com/Automattic/newspack-plugin/issues/1457)) ([29271ab](https://github.com/Automattic/newspack-plugin/commit/29271ab9f802e9ff7591a8cd4e58e34f21fbcfa3))

# [1.74.0](https://github.com/Automattic/newspack-plugin/compare/v1.73.0...v1.74.0) (2022-02-02)


### Features

* **salesforce:** ability to manually sync WC orders to salesforce ([#1485](https://github.com/Automattic/newspack-plugin/issues/1485)) ([be8a062](https://github.com/Automattic/newspack-plugin/commit/be8a0626c6a09fc869caadeee2b8db3e776f1e3a))

# [1.73.0](https://github.com/Automattic/newspack-plugin/compare/v1.72.1...v1.73.0) (2022-01-31)


### Features

* settings section hooks ([#1480](https://github.com/Automattic/newspack-plugin/issues/1480)) ([61efdc4](https://github.com/Automattic/newspack-plugin/commit/61efdc4146a1f3841699bb72cc379c8c2642cd81))

# [1.73.0-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.72.1...v1.73.0-hotfix.1) (2022-01-31)


### Features

* settings section hooks ([#1378](https://github.com/Automattic/newspack-plugin/issues/1378)) ([dea6f6f](https://github.com/Automattic/newspack-plugin/commit/dea6f6f85facd964ffe409c47f87b17b4036250b))

## [1.72.1](https://github.com/Automattic/newspack-plugin/compare/v1.72.0...v1.72.1) (2022-01-27)


### Bug Fixes

* force new release ([665559b](https://github.com/Automattic/newspack-plugin/commit/665559b2e431b22b10103bd0bd1734d8e1339d69))

## [1.72.1-hotfix.1](https://github.com/Automattic/newspack-plugin/compare/v1.72.0...v1.72.1-hotfix.1) (2022-01-27)


### Bug Fixes

* disable author archive pages for non-staff users ([3ec9fc7](https://github.com/Automattic/newspack-plugin/commit/3ec9fc77fe12d6539560b4939dc9d3897f048935))

# [1.72.0](https://github.com/Automattic/newspack-plugin/compare/v1.71.0...v1.72.0) (2022-01-25)


### Bug Fixes

* **ads:** ad unit error handling ([#1424](https://github.com/Automattic/newspack-plugin/issues/1424)) ([a1ef5f6](https://github.com/Automattic/newspack-plugin/commit/a1ef5f6f2a680c4729093632d26f5fea75604cc1))
* campaigns, categories autocomplete UI ([89a26b2](https://github.com/Automattic/newspack-plugin/commit/89a26b2ef6e78cb6c7ffbdfc700c73759a131cae)), closes [#1126](https://github.com/Automattic/newspack-plugin/issues/1126)
* color picker ([#1438](https://github.com/Automattic/newspack-plugin/issues/1438)) ([beea9a3](https://github.com/Automattic/newspack-plugin/commit/beea9a36411453d0647bbeb729a01e57c60f9939))
* **engagement-wizard:** strip HTML from setting labels ([a181374](https://github.com/Automattic/newspack-plugin/commit/a181374be8bc46dc2831823145a391c9d4665fcc))
* modal header ([#1439](https://github.com/Automattic/newspack-plugin/issues/1439)) ([91c90fe](https://github.com/Automattic/newspack-plugin/commit/91c90fee07f1961b5977601bfb30decb61688467))
* restore accidentally-deleted rest route ([#1443](https://github.com/Automattic/newspack-plugin/issues/1443)) ([472c8ff](https://github.com/Automattic/newspack-plugin/commit/472c8ffb7404020e1afd1da47a48cb3ac05b3637))
* timeouts due to use of get_post when checking whether to allow deletion ([#1442](https://github.com/Automattic/newspack-plugin/issues/1442)) ([4c3c932](https://github.com/Automattic/newspack-plugin/commit/4c3c9326669a14e4e342ed7bbecf50d8ef3d4e9d))


### Features

* add a completion screen to the onboarding ([#1377](https://github.com/Automattic/newspack-plugin/issues/1377)) ([8e3ca01](https://github.com/Automattic/newspack-plugin/commit/8e3ca01d176ab14985ab1aeee80935ef1cadd0b8))
* allow oauth proxy overrides ([#1389](https://github.com/Automattic/newspack-plugin/issues/1389)) ([603e96d](https://github.com/Automattic/newspack-plugin/commit/603e96d182fd9c285b2791009e420f35c7feb0c1))
* **amp:** enable disallowing explicitly kept scripts, for debugging ([302abcc](https://github.com/Automattic/newspack-plugin/commit/302abcc100d1adad57b37e5697824b261e32f27a))
* remove setting footer copyright to site tagline by default ([7cba0b6](https://github.com/Automattic/newspack-plugin/commit/7cba0b63bcf91623d31a8167e72ccb10b846d80e)), closes [#1148](https://github.com/Automattic/newspack-plugin/issues/1148)
* update plugin list ([#1451](https://github.com/Automattic/newspack-plugin/issues/1451)) ([e3e6a68](https://github.com/Automattic/newspack-plugin/commit/e3e6a6856a704d1a6d3f4e0af529fe7ad07b6cf7))

# [1.72.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.71.0...v1.72.0-alpha.1) (2022-01-24)


### Bug Fixes

* **ads:** ad unit error handling ([#1424](https://github.com/Automattic/newspack-plugin/issues/1424)) ([a1ef5f6](https://github.com/Automattic/newspack-plugin/commit/a1ef5f6f2a680c4729093632d26f5fea75604cc1))
* campaigns, categories autocomplete UI ([89a26b2](https://github.com/Automattic/newspack-plugin/commit/89a26b2ef6e78cb6c7ffbdfc700c73759a131cae)), closes [#1126](https://github.com/Automattic/newspack-plugin/issues/1126)
* color picker ([#1438](https://github.com/Automattic/newspack-plugin/issues/1438)) ([beea9a3](https://github.com/Automattic/newspack-plugin/commit/beea9a36411453d0647bbeb729a01e57c60f9939))
* **engagement-wizard:** strip HTML from setting labels ([a181374](https://github.com/Automattic/newspack-plugin/commit/a181374be8bc46dc2831823145a391c9d4665fcc))
* modal header ([#1439](https://github.com/Automattic/newspack-plugin/issues/1439)) ([91c90fe](https://github.com/Automattic/newspack-plugin/commit/91c90fee07f1961b5977601bfb30decb61688467))
* restore accidentally-deleted rest route ([#1443](https://github.com/Automattic/newspack-plugin/issues/1443)) ([472c8ff](https://github.com/Automattic/newspack-plugin/commit/472c8ffb7404020e1afd1da47a48cb3ac05b3637))
* timeouts due to use of get_post when checking whether to allow deletion ([#1442](https://github.com/Automattic/newspack-plugin/issues/1442)) ([4c3c932](https://github.com/Automattic/newspack-plugin/commit/4c3c9326669a14e4e342ed7bbecf50d8ef3d4e9d))


### Features

* add a completion screen to the onboarding ([#1377](https://github.com/Automattic/newspack-plugin/issues/1377)) ([8e3ca01](https://github.com/Automattic/newspack-plugin/commit/8e3ca01d176ab14985ab1aeee80935ef1cadd0b8))
* allow oauth proxy overrides ([#1389](https://github.com/Automattic/newspack-plugin/issues/1389)) ([603e96d](https://github.com/Automattic/newspack-plugin/commit/603e96d182fd9c285b2791009e420f35c7feb0c1))
* **amp:** enable disallowing explicitly kept scripts, for debugging ([302abcc](https://github.com/Automattic/newspack-plugin/commit/302abcc100d1adad57b37e5697824b261e32f27a))
* remove setting footer copyright to site tagline by default ([7cba0b6](https://github.com/Automattic/newspack-plugin/commit/7cba0b63bcf91623d31a8167e72ccb10b846d80e)), closes [#1148](https://github.com/Automattic/newspack-plugin/issues/1148)
* update plugin list ([#1451](https://github.com/Automattic/newspack-plugin/issues/1451)) ([e3e6a68](https://github.com/Automattic/newspack-plugin/commit/e3e6a6856a704d1a6d3f4e0af529fe7ad07b6cf7))

# [1.71.0](https://github.com/Automattic/newspack-plugin/compare/v1.70.0...v1.71.0) (2022-01-19)


### Bug Fixes

* change how we check post validity when preventing page deletion ([#1407](https://github.com/Automattic/newspack-plugin/issues/1407)) ([1d86ac6](https://github.com/Automattic/newspack-plugin/commit/1d86ac61e8f77ae16887cc86671d0f842db6bd45))
* setup wizard fixes ([#1316](https://github.com/Automattic/newspack-plugin/issues/1316)) ([03de101](https://github.com/Automattic/newspack-plugin/commit/03de101eb2ac34915fad3ac5c20d6856b38f88a8))


### Features

* **reader-revenue/stripe:** allow setting custom fee parameters ([#1376](https://github.com/Automattic/newspack-plugin/issues/1376)) ([e1f97fc](https://github.com/Automattic/newspack-plugin/commit/e1f97fc8aea58dd519d673ba8e8c8b704defa85c))

# [1.71.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.70.0...v1.71.0-alpha.1) (2022-01-06)


### Bug Fixes

* change how we check post validity when preventing page deletion ([#1407](https://github.com/Automattic/newspack-plugin/issues/1407)) ([1d86ac6](https://github.com/Automattic/newspack-plugin/commit/1d86ac61e8f77ae16887cc86671d0f842db6bd45))
* setup wizard fixes ([#1316](https://github.com/Automattic/newspack-plugin/issues/1316)) ([03de101](https://github.com/Automattic/newspack-plugin/commit/03de101eb2ac34915fad3ac5c20d6856b38f88a8))


### Features

* **reader-revenue/stripe:** allow setting custom fee parameters ([#1376](https://github.com/Automattic/newspack-plugin/issues/1376)) ([e1f97fc](https://github.com/Automattic/newspack-plugin/commit/e1f97fc8aea58dd519d673ba8e8c8b704defa85c))

# [1.70.0](https://github.com/Automattic/newspack-plugin/compare/v1.69.0...v1.70.0) (2021-12-20)


### Bug Fixes

* **oauth:** google token refresh ([078f18e](https://github.com/Automattic/newspack-plugin/commit/078f18e377f7fc646b5ca0a5247c53e7b4e8c3e7))


### Features

* **stripe:** handle card update; prepare apple pay integration ([#1305](https://github.com/Automattic/newspack-plugin/issues/1305)) ([3e6f523](https://github.com/Automattic/newspack-plugin/commit/3e6f5237df1469ae0ebaaeb65aeb835168954511))

# [1.70.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.69.0...v1.70.0-alpha.1) (2021-12-20)


### Bug Fixes

* **oauth:** google token refresh ([078f18e](https://github.com/Automattic/newspack-plugin/commit/078f18e377f7fc646b5ca0a5247c53e7b4e8c3e7))


### Features

* **stripe:** handle card update; prepare apple pay integration ([#1305](https://github.com/Automattic/newspack-plugin/issues/1305)) ([3e6f523](https://github.com/Automattic/newspack-plugin/commit/3e6f5237df1469ae0ebaaeb65aeb835168954511))

# [1.69.0](https://github.com/Automattic/newspack-plugin/compare/v1.68.0...v1.69.0) (2021-12-15)


### Bug Fixes

* use new shortened preview query keys form Campaigns ([#1366](https://github.com/Automattic/newspack-plugin/issues/1366)) ([8baca00](https://github.com/Automattic/newspack-plugin/commit/8baca00b5a35db62cc600256681099dac66cc0a2))


### Features

* **ads:** stick to top placements ([#1365](https://github.com/Automattic/newspack-plugin/issues/1365)) ([90fbf3d](https://github.com/Automattic/newspack-plugin/commit/90fbf3d3ce8dda9972a56c6ae679f4f8e278ab49))

# [1.69.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.68.0...v1.69.0-alpha.1) (2021-12-15)


### Bug Fixes

* use new shortened preview query keys form Campaigns ([#1366](https://github.com/Automattic/newspack-plugin/issues/1366)) ([8baca00](https://github.com/Automattic/newspack-plugin/commit/8baca00b5a35db62cc600256681099dac66cc0a2))


### Features

* **ads:** stick to top placements ([#1365](https://github.com/Automattic/newspack-plugin/issues/1365)) ([90fbf3d](https://github.com/Automattic/newspack-plugin/commit/90fbf3d3ce8dda9972a56c6ae679f4f8e278ab49))

# [1.68.0](https://github.com/Automattic/newspack-plugin/compare/v1.67.1...v1.68.0) (2021-12-14)


### Bug Fixes

* **campaigns:** duplicating a prompt needs both original and parent prompt IDs ([#1326](https://github.com/Automattic/newspack-plugin/issues/1326)) ([59eaa07](https://github.com/Automattic/newspack-plugin/commit/59eaa07143463d4d538156462d058357f88fcb2e))
* shared relative path ([1339b09](https://github.com/Automattic/newspack-plugin/commit/1339b095396ad032ff9a7871ca0f6771f2e955dc))
* use access token instead of default token ([#1348](https://github.com/Automattic/newspack-plugin/issues/1348)) ([16ee208](https://github.com/Automattic/newspack-plugin/commit/16ee20809e5a0644be7b069fadd59605182082b0))


### Features

* **ads:** handle multiple GAM accounts ([#1334](https://github.com/Automattic/newspack-plugin/issues/1334)) ([5db31ea](https://github.com/Automattic/newspack-plugin/commit/5db31ea6b1d6197c399bd307eadbf76bf9d9d558))
* **ads:** placement bidders ([#1285](https://github.com/Automattic/newspack-plugin/issues/1285)) ([cae1251](https://github.com/Automattic/newspack-plugin/commit/cae12510fb691f55cabd05f43aeac66527865ce0))
* reorganise Add New Segment wizard  ([#1350](https://github.com/Automattic/newspack-plugin/issues/1350)) ([0c35572](https://github.com/Automattic/newspack-plugin/commit/0c35572b41a3ddc7e3e6affd7a41133fd8454fa2))
* update badge and notice design ([#1340](https://github.com/Automattic/newspack-plugin/issues/1340)) ([ad5c82e](https://github.com/Automattic/newspack-plugin/commit/ad5c82ef611892c53583ecea365a5439f8775d20))
* use newspack manager plugin for authentication ([#1247](https://github.com/Automattic/newspack-plugin/issues/1247)) ([5a01d87](https://github.com/Automattic/newspack-plugin/commit/5a01d871caaa4f3eabe7a443bd360adb60c117e9))

# [1.68.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.68.0-alpha.2...v1.68.0-alpha.3) (2021-12-10)


### Features

* **ads:** placement bidders ([#1285](https://github.com/Automattic/newspack-plugin/issues/1285)) ([cae1251](https://github.com/Automattic/newspack-plugin/commit/cae12510fb691f55cabd05f43aeac66527865ce0))

# [1.68.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.68.0-alpha.1...v1.68.0-alpha.2) (2021-12-09)


### Bug Fixes

* **campaigns:** duplicating a prompt needs both original and parent prompt IDs ([#1326](https://github.com/Automattic/newspack-plugin/issues/1326)) ([59eaa07](https://github.com/Automattic/newspack-plugin/commit/59eaa07143463d4d538156462d058357f88fcb2e))
* use access token instead of default token ([#1348](https://github.com/Automattic/newspack-plugin/issues/1348)) ([16ee208](https://github.com/Automattic/newspack-plugin/commit/16ee20809e5a0644be7b069fadd59605182082b0))


### Features

* reorganise Add New Segment wizard  ([#1350](https://github.com/Automattic/newspack-plugin/issues/1350)) ([0c35572](https://github.com/Automattic/newspack-plugin/commit/0c35572b41a3ddc7e3e6affd7a41133fd8454fa2))

# [1.68.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.67.1...v1.68.0-alpha.1) (2021-12-08)


### Bug Fixes

* shared relative path ([1339b09](https://github.com/Automattic/newspack-plugin/commit/1339b095396ad032ff9a7871ca0f6771f2e955dc))


### Features

* **ads:** handle multiple GAM accounts ([#1334](https://github.com/Automattic/newspack-plugin/issues/1334)) ([5db31ea](https://github.com/Automattic/newspack-plugin/commit/5db31ea6b1d6197c399bd307eadbf76bf9d9d558))
* update badge and notice design ([#1340](https://github.com/Automattic/newspack-plugin/issues/1340)) ([ad5c82e](https://github.com/Automattic/newspack-plugin/commit/ad5c82ef611892c53583ecea365a5439f8775d20))
* use newspack manager plugin for authentication ([#1247](https://github.com/Automattic/newspack-plugin/issues/1247)) ([5a01d87](https://github.com/Automattic/newspack-plugin/commit/5a01d871caaa4f3eabe7a443bd360adb60c117e9))

## [1.67.1](https://github.com/Automattic/newspack-plugin/compare/v1.67.0...v1.67.1) (2021-12-01)


### Bug Fixes

* plugin settings checkbox ([#1329](https://github.com/Automattic/newspack-plugin/issues/1329)) ([79dcdf2](https://github.com/Automattic/newspack-plugin/commit/79dcdf26530a0e6c24f1d3afdde2ad0703e6975c))

## [1.67.1-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.67.0...v1.67.1-alpha.1) (2021-12-01)


### Bug Fixes

* plugin settings checkbox ([#1329](https://github.com/Automattic/newspack-plugin/issues/1329)) ([79dcdf2](https://github.com/Automattic/newspack-plugin/commit/79dcdf26530a0e6c24f1d3afdde2ad0703e6975c))

# [1.67.0](https://github.com/Automattic/newspack-plugin/compare/v1.66.0...v1.67.0) (2021-11-30)


### Bug Fixes

* add moment-range to newspack-components dependencies ([#1277](https://github.com/Automattic/newspack-plugin/issues/1277)) ([a8524dc](https://github.com/Automattic/newspack-plugin/commit/a8524dc7371c0f7a164a7d061b8d45ec8503e607))
* mailchimp connection destructive link and update external link ([#1283](https://github.com/Automattic/newspack-plugin/issues/1283)) ([a11a804](https://github.com/Automattic/newspack-plugin/commit/a11a80473a5a63703fcdf8f8d4cf608ee822aed9))
* **stripe:** variable name ([a33794a](https://github.com/Automattic/newspack-plugin/commit/a33794a7c293b5934ab4df5c722981819048d475))


### Features

* add multiple support for select-control ([#1287](https://github.com/Automattic/newspack-plugin/issues/1287)) ([9d052c5](https://github.com/Automattic/newspack-plugin/commit/9d052c5cfc5d2d9ae0b0d8589119f94b94f353d5))
* add WP migration option to onboarding ([#1077](https://github.com/Automattic/newspack-plugin/issues/1077)) ([d8c5e69](https://github.com/Automattic/newspack-plugin/commit/d8c5e69cb5c621b9118d2c9b3644392fe88cd678))
* **ads:** update placements ui ([#1225](https://github.com/Automattic/newspack-plugin/issues/1225)) ([3adbe06](https://github.com/Automattic/newspack-plugin/commit/3adbe063383e9f0ed181ef903ea48827ce184064))
* **google-oauth:** display notice if there's no refresh token ([#1217](https://github.com/Automattic/newspack-plugin/issues/1217)) ([4b0d433](https://github.com/Automattic/newspack-plugin/commit/4b0d4338e092d83ff9cbc85056e04401ce8cf108))
* reduce section-header margin when followed by another component ([#1268](https://github.com/Automattic/newspack-plugin/issues/1268)) ([b297c06](https://github.com/Automattic/newspack-plugin/commit/b297c0688b9a0fa1ba56cf6745361069938cb298))
* remove Updates wizard; replace with a link to manual release notes ([#1262](https://github.com/Automattic/newspack-plugin/issues/1262)) ([761dbd6](https://github.com/Automattic/newspack-plugin/commit/761dbd6f5ba803c1a62c5278d6bd859c6156bf8d))
* update various elements of the onboarding wizard ([#1282](https://github.com/Automattic/newspack-plugin/issues/1282)) ([8b8dd37](https://github.com/Automattic/newspack-plugin/commit/8b8dd371f535430b23f0ef6e2a1dcc750cba11aa))


### Reverts

* "chore(deps-dev): bump @typescript-eslint/eslint-plugin" ([2e078ea](https://github.com/Automattic/newspack-plugin/commit/2e078ea3cc487d0b9afdafe5d5946ccc2e7af898))

# [1.67.0-alpha.3](https://github.com/Automattic/newspack-plugin/compare/v1.67.0-alpha.2...v1.67.0-alpha.3) (2021-11-30)


### Bug Fixes

* mailchimp connection destructive link and update external link ([#1283](https://github.com/Automattic/newspack-plugin/issues/1283)) ([a11a804](https://github.com/Automattic/newspack-plugin/commit/a11a80473a5a63703fcdf8f8d4cf608ee822aed9))
* **stripe:** variable name ([a33794a](https://github.com/Automattic/newspack-plugin/commit/a33794a7c293b5934ab4df5c722981819048d475))


### Features

* add multiple support for select-control ([#1287](https://github.com/Automattic/newspack-plugin/issues/1287)) ([9d052c5](https://github.com/Automattic/newspack-plugin/commit/9d052c5cfc5d2d9ae0b0d8589119f94b94f353d5))
* add WP migration option to onboarding ([#1077](https://github.com/Automattic/newspack-plugin/issues/1077)) ([d8c5e69](https://github.com/Automattic/newspack-plugin/commit/d8c5e69cb5c621b9118d2c9b3644392fe88cd678))
* **google-oauth:** display notice if there's no refresh token ([#1217](https://github.com/Automattic/newspack-plugin/issues/1217)) ([4b0d433](https://github.com/Automattic/newspack-plugin/commit/4b0d4338e092d83ff9cbc85056e04401ce8cf108))
* remove Updates wizard; replace with a link to manual release notes ([#1262](https://github.com/Automattic/newspack-plugin/issues/1262)) ([761dbd6](https://github.com/Automattic/newspack-plugin/commit/761dbd6f5ba803c1a62c5278d6bd859c6156bf8d))
* update various elements of the onboarding wizard ([#1282](https://github.com/Automattic/newspack-plugin/issues/1282)) ([8b8dd37](https://github.com/Automattic/newspack-plugin/commit/8b8dd371f535430b23f0ef6e2a1dcc750cba11aa))

# [1.67.0-alpha.2](https://github.com/Automattic/newspack-plugin/compare/v1.67.0-alpha.1...v1.67.0-alpha.2) (2021-11-18)


### Bug Fixes

* add moment-range to newspack-components dependencies ([#1277](https://github.com/Automattic/newspack-plugin/issues/1277)) ([a8524dc](https://github.com/Automattic/newspack-plugin/commit/a8524dc7371c0f7a164a7d061b8d45ec8503e607))

# [1.67.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.66.0...v1.67.0-alpha.1) (2021-11-18)


### Features

* **ads:** update placements ui ([#1225](https://github.com/Automattic/newspack-plugin/issues/1225)) ([3adbe06](https://github.com/Automattic/newspack-plugin/commit/3adbe063383e9f0ed181ef903ea48827ce184064))
* reduce section-header margin when followed by another component ([#1268](https://github.com/Automattic/newspack-plugin/issues/1268)) ([b297c06](https://github.com/Automattic/newspack-plugin/commit/b297c0688b9a0fa1ba56cf6745361069938cb298))


### Reverts

* "chore(deps-dev): bump @typescript-eslint/eslint-plugin" ([2e078ea](https://github.com/Automattic/newspack-plugin/commit/2e078ea3cc487d0b9afdafe5d5946ccc2e7af898))

# [1.66.0](https://github.com/Automattic/newspack-plugin/compare/v1.65.0...v1.66.0) (2021-11-18)


### Bug Fixes

* **ads:** network code visibility ([#1253](https://github.com/Automattic/newspack-plugin/issues/1253)) ([cb4e263](https://github.com/Automattic/newspack-plugin/commit/cb4e263acf1e3fc7e51593a74a25be22c99fe5cb))
* use greyHeader and fix toggle on null active ([#1248](https://github.com/Automattic/newspack-plugin/issues/1248)) ([03aa4f1](https://github.com/Automattic/newspack-plugin/commit/03aa4f10ed20ce0f349e0a71e2c65ba30fcf188b))


### Features

* **connections:** add MailChimp API key setting in connection screen ([#1168](https://github.com/Automattic/newspack-plugin/issues/1168)) ([a58f415](https://github.com/Automattic/newspack-plugin/commit/a58f41598f83b1e16394a1e14aa5009099b011fb))
* **google-oauth:** handle invalid token; add GA scopes ([#1227](https://github.com/Automattic/newspack-plugin/issues/1227)) ([479a6f2](https://github.com/Automattic/newspack-plugin/commit/479a6f2a2d25752a570ab2ceb050b68b1c99b903))
* integrate fluid to ad unit size control ([#1202](https://github.com/Automattic/newspack-plugin/issues/1202)) ([d2c299e](https://github.com/Automattic/newspack-plugin/commit/d2c299eeb2c0164760ac359e853e434c08556c23))
* **prompt settings:** add prompt new placements and size options ([#1192](https://github.com/Automattic/newspack-plugin/issues/1192)) ([b1ba88d](https://github.com/Automattic/newspack-plugin/commit/b1ba88dfe06b701dbd967d805dfdedef166d619e))
* **reader-revenue:** donations mailing ([#1187](https://github.com/Automattic/newspack-plugin/issues/1187)) ([7163a4b](https://github.com/Automattic/newspack-plugin/commit/7163a4b23d66a8b0a45db3a5d93c879bf5ad89a1))
* update ad suppression layout ([#1256](https://github.com/Automattic/newspack-plugin/issues/1256)) ([c1bb929](https://github.com/Automattic/newspack-plugin/commit/c1bb929a9f16d718be4f207fa6e0956a9edb6ca4))
* update button-card and button-group focus/pressed style ([#1221](https://github.com/Automattic/newspack-plugin/issues/1221)) ([2940d60](https://github.com/Automattic/newspack-plugin/commit/2940d60095e7a897bb57c126cc9d25306e43582e))

# [1.66.0-alpha.1](https://github.com/Automattic/newspack-plugin/compare/v1.65.0...v1.66.0-alpha.1) (2021-11-16)


### Bug Fixes

* **ads:** network code visibility ([#1253](https://github.com/Automattic/newspack-plugin/issues/1253)) ([cb4e263](https://github.com/Automattic/newspack-plugin/commit/cb4e263acf1e3fc7e51593a74a25be22c99fe5cb))
* use greyHeader and fix toggle on null active ([#1248](https://github.com/Automattic/newspack-plugin/issues/1248)) ([03aa4f1](https://github.com/Automattic/newspack-plugin/commit/03aa4f10ed20ce0f349e0a71e2c65ba30fcf188b))


### Features

* **connections:** add MailChimp API key setting in connection screen ([#1168](https://github.com/Automattic/newspack-plugin/issues/1168)) ([a58f415](https://github.com/Automattic/newspack-plugin/commit/a58f41598f83b1e16394a1e14aa5009099b011fb))
* **google-oauth:** handle invalid token; add GA scopes ([#1227](https://github.com/Automattic/newspack-plugin/issues/1227)) ([479a6f2](https://github.com/Automattic/newspack-plugin/commit/479a6f2a2d25752a570ab2ceb050b68b1c99b903))
* integrate fluid to ad unit size control ([#1202](https://github.com/Automattic/newspack-plugin/issues/1202)) ([d2c299e](https://github.com/Automattic/newspack-plugin/commit/d2c299eeb2c0164760ac359e853e434c08556c23))
* **prompt settings:** add prompt new placements and size options ([#1192](https://github.com/Automattic/newspack-plugin/issues/1192)) ([b1ba88d](https://github.com/Automattic/newspack-plugin/commit/b1ba88dfe06b701dbd967d805dfdedef166d619e))
* **reader-revenue:** donations mailing ([#1187](https://github.com/Automattic/newspack-plugin/issues/1187)) ([7163a4b](https://github.com/Automattic/newspack-plugin/commit/7163a4b23d66a8b0a45db3a5d93c879bf5ad89a1))
* update ad suppression layout ([#1256](https://github.com/Automattic/newspack-plugin/issues/1256)) ([c1bb929](https://github.com/Automattic/newspack-plugin/commit/c1bb929a9f16d718be4f207fa6e0956a9edb6ca4))
* update button-card and button-group focus/pressed style ([#1221](https://github.com/Automattic/newspack-plugin/issues/1221)) ([2940d60](https://github.com/Automattic/newspack-plugin/commit/2940d60095e7a897bb57c126cc9d25306e43582e))

# [1.65.0](https://github.com/Automattic/newspack-plugin/compare/v1.64.0...v1.65.0) (2021-11-09)


### Bug Fixes

* disable Newspack events if Site Kit's GTM module is active ([ea11629](https://github.com/Automattic/newspack-plugin/commit/ea1162910d5f3b7399058cb53824f36360ca5562))


### Features

* **popups wizard:** add archive page permalink as preview URL ([#1133](https://github.com/Automattic/newspack-plugin/issues/1133)) ([3b3ae9c](https://github.com/Automattic/newspack-plugin/commit/3b3ae9ccde43b64284b9ba10624d107dc0432658))
* newspack plugin settings component ([#1208](https://github.com/Automattic/newspack-plugin/issues/1208)) ([5ab1676](https://github.com/Automattic/newspack-plugin/commit/5ab1676d764a06a8ed37e7ecd504c97f12729bf8))
* **ads:** service account connection ([#1212](https://github.com/Automattic/newspack-plugin/issues/1212)) ([00bddb5](https://github.com/Automattic/newspack-plugin/commit/00bddb5c1d1ed516baef78f1206d626151b1feb8))

# [1.64.0](https://github.com/Automattic/newspack-plugin/compare/v1.63.0...v1.64.0) (2021-11-03)


### Bug Fixes

* update ci images ([#1210](https://github.com/Automattic/newspack-plugin/issues/1210)) ([c476f08](https://github.com/Automattic/newspack-plugin/commit/c476f08a629790ef2b682ae0bc082be6a4bf7149))


### Features

* parsely config manager ([#1207](https://github.com/Automattic/newspack-plugin/issues/1207)) ([7f33cdb](https://github.com/Automattic/newspack-plugin/commit/7f33cdbe40c7c797167f73f3ad9020adbefe67ba))
* **ntg:** add NTG social events for reddit and telegram ([#1206](https://github.com/Automattic/newspack-plugin/issues/1206)) ([bf2c589](https://github.com/Automattic/newspack-plugin/commit/bf2c5892f84fdfcccafab3a9d48db617d23ecccd))

# [1.63.0](https://github.com/Automattic/newspack-plugin/compare/v1.62.0...v1.63.0) (2021-10-26)


### Bug Fixes

* ensure support variables are configured ([#1201](https://github.com/Automattic/newspack-plugin/issues/1201)) ([ba44c58](https://github.com/Automattic/newspack-plugin/commit/ba44c5892729d5e30fd1fa4bb07bf0871a365353))


### Features

* update the edit ad unit design ([#1199](https://github.com/Automattic/newspack-plugin/issues/1199)) ([1999878](https://github.com/Automattic/newspack-plugin/commit/19998780cae3aa9723284dffe2378081c4e574df))

# [1.62.0](https://github.com/Automattic/newspack-plugin/compare/v1.61.0...v1.62.0) (2021-10-19)


### Bug Fixes

* google oauth ([#1191](https://github.com/Automattic/newspack-plugin/issues/1191)) ([502f156](https://github.com/Automattic/newspack-plugin/commit/502f1564af115478f02537631adc6fcc089ebba5))


### Features

* **ads:** fluid ad unit sizing ([#1181](https://github.com/Automattic/newspack-plugin/issues/1181)) ([8c7f57e](https://github.com/Automattic/newspack-plugin/commit/8c7f57ef327be7fbeaf6924e3187eb75fd6b890f))
* **connections:** add Fivetran connection via a proxy ([#1173](https://github.com/Automattic/newspack-plugin/issues/1173)) ([883bad4](https://github.com/Automattic/newspack-plugin/commit/883bad40844632c1c1c167ca82ec8f1c77c84d74))
* **gam:** use oauth; remove service account flow ([#1188](https://github.com/Automattic/newspack-plugin/issues/1188)) ([a15c385](https://github.com/Automattic/newspack-plugin/commit/a15c385809e2fbfce5ee0d77f7a8c54fc7fe129d))
* **oauth:** store oauth as option, available to admin users ([caf81a2](https://github.com/Automattic/newspack-plugin/commit/caf81a2836a7ba337a4015cd4e4c4aa28ac70134))


### Performance Improvements

* optimize maybe disable ads verification ([#1185](https://github.com/Automattic/newspack-plugin/issues/1185)) ([1cc6c74](https://github.com/Automattic/newspack-plugin/commit/1cc6c74ddc089d03462f5b3bc6bd44fd85905664))

# [1.61.0](https://github.com/Automattic/newspack-plugin/compare/v1.60.0...v1.61.0) (2021-10-12)


### Bug Fixes

* **donations:** error handling ([#1176](https://github.com/Automattic/newspack-plugin/issues/1176)) ([a03a45e](https://github.com/Automattic/newspack-plugin/commit/a03a45eaa0870e99a0fb63ee0b3ef2004e570552))


### Features

* **ads:** standardize ad unit sizes ([#1174](https://github.com/Automattic/newspack-plugin/issues/1174)) ([89af48c](https://github.com/Automattic/newspack-plugin/commit/89af48c4a5ce8f8007a63f23b6bf26468ac7a79d))

# [1.60.0](https://github.com/Automattic/newspack-plugin/compare/v1.59.0...v1.60.0) (2021-10-05)


### Bug Fixes

* **campaigns-wizard:** segmentation configuration ([fbb5f1b](https://github.com/Automattic/newspack-plugin/commit/fbb5f1b71239cdbb287d24096744d2eb85d04203))


### Features

* update GAM wizard design ([#1177](https://github.com/Automattic/newspack-plugin/issues/1177)) ([5635f9e](https://github.com/Automattic/newspack-plugin/commit/5635f9e5fe39093351bb808725c98f99b75785b0))
* update header and footer style ([#1161](https://github.com/Automattic/newspack-plugin/issues/1161)) ([bbab72f](https://github.com/Automattic/newspack-plugin/commit/bbab72f46d356d596a2f111f090a577bbc3345e2))

# [1.59.0](https://github.com/Automattic/newspack-plugin/compare/v1.58.0...v1.59.0) (2021-09-30)


### Bug Fixes

* revert scroll restoration in wizards ([#1170](https://github.com/Automattic/newspack-plugin/issues/1170)) ([8f28012](https://github.com/Automattic/newspack-plugin/commit/8f2801290aeb68db3bfb236c0ff1ac0c6ed20a5b)), closes [#1165](https://github.com/Automattic/newspack-plugin/issues/1165) [#1157](https://github.com/Automattic/newspack-plugin/issues/1157)


### Features

* connections wizard ([#1145](https://github.com/Automattic/newspack-plugin/issues/1145)) ([577eee4](https://github.com/Automattic/newspack-plugin/commit/577eee4bd88b4ac0acaf22a7ee0231407150b91a))
* multiple badges for action card ([#1163](https://github.com/Automattic/newspack-plugin/issues/1163)) ([fa45f45](https://github.com/Automattic/newspack-plugin/commit/fa45f45e4cd58e7d79cc88b018f4cc55a923c844))

# [1.58.0](https://github.com/Automattic/newspack-plugin/compare/v1.57.0...v1.58.0) (2021-09-28)


### Bug Fixes

* react router crash from useLocation in HOC ([#1165](https://github.com/Automattic/newspack-plugin/issues/1165)) ([c954fe2](https://github.com/Automattic/newspack-plugin/commit/c954fe266d622d3eba1509191a98bff9ec395cc0))
* **health-check:** AMP status ([3345261](https://github.com/Automattic/newspack-plugin/commit/3345261e035fced18f64d07e137805700b146a6c))
* restore scroll for wizard screens navigation ([#1157](https://github.com/Automattic/newspack-plugin/issues/1157)) ([315a47b](https://github.com/Automattic/newspack-plugin/commit/315a47ba0415075507ab1e0a6cac262d26418e1c))


### Features

* **engagement:** simplify commenting settings ([#1159](https://github.com/Automattic/newspack-plugin/issues/1159)) ([bf79e26](https://github.com/Automattic/newspack-plugin/commit/bf79e26aa968287696329a5753b6ff531d10e6ec))
* **supported-plugins:** remove Newspack Image Credits ([#1158](https://github.com/Automattic/newspack-plugin/issues/1158)) ([e5c1faa](https://github.com/Automattic/newspack-plugin/commit/e5c1faa3f7eb868fa76bc38e268bc189a94a973a))

# [1.57.0](https://github.com/Automattic/newspack-plugin/compare/v1.56.1...v1.57.0) (2021-09-22)


### Bug Fixes

* **ads:** network code and credentials control ([#1151](https://github.com/Automattic/newspack-plugin/issues/1151)) ([cd911a5](https://github.com/Automattic/newspack-plugin/commit/cd911a5a01ebf2f001acadaac3c2a5aa8c394879))
* **setup:** install WC only if Reader Revenue is enabled ([9953c30](https://github.com/Automattic/newspack-plugin/commit/9953c3091ce1d652c58c6752a01d3bf2a520c703)), closes [#1106](https://github.com/Automattic/newspack-plugin/issues/1106)


### Features

* integrate newspack-image-credits into main plugin and wizard UI ([#1147](https://github.com/Automattic/newspack-plugin/issues/1147)) ([3244c07](https://github.com/Automattic/newspack-plugin/commit/3244c070eeaf31e15b3302cec47359b454899a43))
* **ads:** gam service account credentials upload ([#1149](https://github.com/Automattic/newspack-plugin/issues/1149)) ([66c440d](https://github.com/Automattic/newspack-plugin/commit/66c440d0ec9447787cc2f79bce2196e728adcb53))
* **Ads:** Custom targeting keys integration ([#1146](https://github.com/Automattic/newspack-plugin/issues/1146)) ([e561381](https://github.com/Automattic/newspack-plugin/commit/e5613811ac513f814c2ff489f23044e28b334dbc))
* **ads-wizard:** sanitize ads suppression config ([7526ea9](https://github.com/Automattic/newspack-plugin/commit/7526ea9918a67d31a632d9c311057de606b421f1)), closes [#1112](https://github.com/Automattic/newspack-plugin/issues/1112)
* **health-check:** supported yet unmanaged plugins ([#1139](https://github.com/Automattic/newspack-plugin/issues/1139)) ([f392d25](https://github.com/Automattic/newspack-plugin/commit/f392d25de6eef6465a33b5022ed4b9b27d08357b))
* **oauth:** use a self-hosted proxy for authenication ([#1122](https://github.com/Automattic/newspack-plugin/issues/1122)) ([9afb4ab](https://github.com/Automattic/newspack-plugin/commit/9afb4ab4a059dc6f3389f71d93670be3349a5aa6))
* disable block-based widget editing ([804cc29](https://github.com/Automattic/newspack-plugin/commit/804cc29ae8441b3543e9ec043e63f533b02f71b5)), closes [#1124](https://github.com/Automattic/newspack-plugin/issues/1124)

## [1.56.1](https://github.com/Automattic/newspack-plugin/compare/v1.56.0...v1.56.1) (2021-09-14)


### Bug Fixes

* **stripe:** error handling ([#1128](https://github.com/Automattic/newspack-plugin/issues/1128)) ([3ce5378](https://github.com/Automattic/newspack-plugin/commit/3ce5378da41498c65b8663dc1cdc7469e3f89d5b)), closes [#1116](https://github.com/Automattic/newspack-plugin/issues/1116)

# [1.56.0](https://github.com/Automattic/newspack-plugin/compare/v1.55.0...v1.56.0) (2021-09-08)


### Bug Fixes

* stricter plugin area restriction ([#1127](https://github.com/Automattic/newspack-plugin/issues/1127)) ([37fc1ea](https://github.com/Automattic/newspack-plugin/commit/37fc1eaa6126286b2c1df551b32efe9a982e04b1))
* **campaigns-wizard:** prompt duplication ([8927ef4](https://github.com/Automattic/newspack-plugin/commit/8927ef4d6e5b62447d4abf2581bf467e6a731611))


### Features

* **stripe:** handle subscriber status in campaigns data update ([f939bb6](https://github.com/Automattic/newspack-plugin/commit/f939bb66eec0467f1f9e4a61656a51644a6a95a0))
* allow multiple GAM network codes ([#1123](https://github.com/Automattic/newspack-plugin/issues/1123)) ([12cccca](https://github.com/Automattic/newspack-plugin/commit/12ccccaa859715448e3c8f4878cf4a05f5b7099c))
* plugin screen access restriction ([61d3f59](https://github.com/Automattic/newspack-plugin/commit/61d3f5982f4bbf63bc2251661bc89a13303bc25b))

# [1.55.0](https://github.com/Automattic/newspack-plugin/compare/v1.54.0...v1.55.0) (2021-08-31)


### Features

* **stripe:** handle adding subscriber when donating ([#1098](https://github.com/Automattic/newspack-plugin/issues/1098)) ([1a2de16](https://github.com/Automattic/newspack-plugin/commit/1a2de16efdaff472708dca2f5e89d43b148e63b8))
* **stripe:** handle donor name ([d7cbc20](https://github.com/Automattic/newspack-plugin/commit/d7cbc2081ac782a09e4ed0c8b0fbbd97eebd2b89))

# [1.54.0](https://github.com/Automattic/newspack-plugin/compare/v1.53.0...v1.54.0) (2021-08-25)


### Bug Fixes

* disable WooCommerce image regeneration ([#1105](https://github.com/Automattic/newspack-plugin/issues/1105)) ([e4f9f7d](https://github.com/Automattic/newspack-plugin/commit/e4f9f7d834456b088c28574afec4106235550fea))
* increase pwa network timeout limit ([#1107](https://github.com/Automattic/newspack-plugin/issues/1107)) ([cc32462](https://github.com/Automattic/newspack-plugin/commit/cc32462057f620a8407caba88f7326c49d518087))


### Features

* **ads:** global ad suppression settings ([#1100](https://github.com/Automattic/newspack-plugin/issues/1100)) ([8725392](https://github.com/Automattic/newspack-plugin/commit/8725392031b8230cd11e1f137dd39402e790a60f))
* revert redirect patch ([558b9d2](https://github.com/Automattic/newspack-plugin/commit/558b9d2a1abc43ee2537d2f9b27c86f6ee4316cd))
* **donations:** stripe as platform ([#1095](https://github.com/Automattic/newspack-plugin/issues/1095)) ([7df2371](https://github.com/Automattic/newspack-plugin/commit/7df2371090506252e31c5204222038f9868070b9))
* update focus/focus-visible on buttons ([#1101](https://github.com/Automattic/newspack-plugin/issues/1101)) ([c944c76](https://github.com/Automattic/newspack-plugin/commit/c944c76d3a1ab40316762ca3b1e0d8743ea247eb))

# [1.53.0](https://github.com/Automattic/newspack-plugin/compare/v1.52.0...v1.53.0) (2021-08-17)


### Bug Fixes

* handoff banner style when admin has meta links ([#1088](https://github.com/Automattic/newspack-plugin/issues/1088)) ([dacc554](https://github.com/Automattic/newspack-plugin/commit/dacc5549bc1d93a12ed1c0d4d81f4f752eae6e83))
* remove missing CSS from Engagement wizard ([#1092](https://github.com/Automattic/newspack-plugin/issues/1092)) ([4342026](https://github.com/Automattic/newspack-plugin/commit/4342026b20206d4eae72af95589309b50b454ca6))


### Features

* **stripe:** handle recurring donations ([#1087](https://github.com/Automattic/newspack-plugin/issues/1087)) ([4437a79](https://github.com/Automattic/newspack-plugin/commit/4437a7976167de8162c850010f1403e6bd91a24d))

# [1.52.0](https://github.com/Automattic/newspack-plugin/compare/v1.51.1...v1.52.0) (2021-08-10)


### Features

* update campaigns analytics style ([#1085](https://github.com/Automattic/newspack-plugin/issues/1085)) ([d32d583](https://github.com/Automattic/newspack-plugin/commit/d32d583a7b1e6786d91ce8524cf2c48277bcb540))

## [1.51.1](https://github.com/Automattic/newspack-plugin/compare/v1.51.0...v1.51.1) (2021-08-04)


### Bug Fixes

* update Google imports namespaces ([#1081](https://github.com/Automattic/newspack-plugin/issues/1081)) ([2d1e2ab](https://github.com/Automattic/newspack-plugin/commit/2d1e2ab2c2aab03ecd21cabd0d02165d8ba9bd6e))

# [1.51.0](https://github.com/Automattic/newspack-plugin/compare/v1.50.1...v1.51.0) (2021-08-03)


### Bug Fixes

* **stripe:** error namespace, listing webhooks condition ([5cd841b](https://github.com/Automattic/newspack-plugin/commit/5cd841ba035655be6a9f84458772f458ce2178f7))
* namespace wp_error correctly ([ea2a528](https://github.com/Automattic/newspack-plugin/commit/ea2a528b66cb56af51bd42b777fd554484d972d3))


### Features

* **campaigns-wizard:** analytics - enable setting precise date range ([#1062](https://github.com/Automattic/newspack-plugin/issues/1062)) ([c08ad8d](https://github.com/Automattic/newspack-plugin/commit/c08ad8d60804f7d224508f978d79643e56310d39)), closes [#991](https://github.com/Automattic/newspack-plugin/issues/991)
* add RSS Enhancement to Syndication Wizard ([#1068](https://github.com/Automattic/newspack-plugin/issues/1068)) ([d5fd533](https://github.com/Automattic/newspack-plugin/commit/d5fd5333c30646115c3a9113d10445f710ff6d84))
* reorganize campaigns settings modal ([#1073](https://github.com/Automattic/newspack-plugin/issues/1073)) ([1ff9147](https://github.com/Automattic/newspack-plugin/commit/1ff91473d27a9a656686c29f8c55a32bbc4efc32))
* update engagement wizard, reorganize recirculation and remove UGC ([#1074](https://github.com/Automattic/newspack-plugin/issues/1074)) ([68b3a26](https://github.com/Automattic/newspack-plugin/commit/68b3a263fe41aee8963aca0ae8e5b806138afa2f))
* **campaigns-wizard:** manage prompt settings in a modal ([#1065](https://github.com/Automattic/newspack-plugin/issues/1065)) ([2bb3d19](https://github.com/Automattic/newspack-plugin/commit/2bb3d194e3433c8814f9157dfc34f8c86252684d)), closes [#926](https://github.com/Automattic/newspack-plugin/issues/926)
* **reader-revenue-wizard:** clarify donation tiers description ([3ab4dc7](https://github.com/Automattic/newspack-plugin/commit/3ab4dc790a3642f676e4bf156e4437d2ae843ded)), closes [#457](https://github.com/Automattic/newspack-plugin/issues/457)

## [1.50.1](https://github.com/Automattic/newspack-plugin/compare/v1.50.0...v1.50.1) (2021-07-27)


### Bug Fixes

* namespace wp_error correctly ([#1071](https://github.com/Automattic/newspack-plugin/issues/1071)) ([80028f0](https://github.com/Automattic/newspack-plugin/commit/80028f05e4fbdfe7e571f23889b4cec821674924))

# [1.50.0](https://github.com/Automattic/newspack-plugin/compare/v1.49.0...v1.50.0) (2021-07-27)


### Bug Fixes

* **engagement-wizard:** grid columns and provider selector ([#1055](https://github.com/Automattic/newspack-plugin/issues/1055)) ([03a0fc8](https://github.com/Automattic/newspack-plugin/commit/03a0fc8d710f778773792ee5e1c3263bb02e0b82))


### Features

* add isSmall prop to text-control and select-control ([#1064](https://github.com/Automattic/newspack-plugin/issues/1064)) ([e180bd0](https://github.com/Automattic/newspack-plugin/commit/e180bd0fa75c8cd7436d2f42063c0c3929f40785))
* update campaigns action card style for analytics data ([#1063](https://github.com/Automattic/newspack-plugin/issues/1063)) ([f1eb89e](https://github.com/Automattic/newspack-plugin/commit/f1eb89e544605a1f125fd401a24e98d41838e313))
* **amp:** skip AMP on WC pages ([dcb0a38](https://github.com/Automattic/newspack-plugin/commit/dcb0a3831c2b87befdfdbe75b57bb133611458bd)), closes [#967](https://github.com/Automattic/newspack-plugin/issues/967)
* **campaigns-wizard:** all prompts view, trash handling ([#1052](https://github.com/Automattic/newspack-plugin/issues/1052)) ([5d4af0f](https://github.com/Automattic/newspack-plugin/commit/5d4af0f61cff0179c0b8509f4c591e1f62b910ae)), closes [#784](https://github.com/Automattic/newspack-plugin/issues/784) [#869](https://github.com/Automattic/newspack-plugin/issues/869)
* **campaigns-wizard:** display GA insights in prompts list ([#1057](https://github.com/Automattic/newspack-plugin/issues/1057)) ([4fe84dc](https://github.com/Automattic/newspack-plugin/commit/4fe84dcab594b55b67442c1f805e49758e42efff)), closes [#993](https://github.com/Automattic/newspack-plugin/issues/993)
* **setup-wizard:** prevent installation on non-HTTPS sites ([cb8eab2](https://github.com/Automattic/newspack-plugin/commit/cb8eab24076029ceb25c2a14eb0057c99c6db1ee)), closes [#186](https://github.com/Automattic/newspack-plugin/issues/186)
* **starter-content:** set primary category on posts ([dbdbd36](https://github.com/Automattic/newspack-plugin/commit/dbdbd3674076e4198e83f0ec05169a45e72d805c)), closes [#682](https://github.com/Automattic/newspack-plugin/issues/682)
* **stripe:** handle webhooks; update Campaigns, GA data ([#1047](https://github.com/Automattic/newspack-plugin/issues/1047)) ([a78c6d4](https://github.com/Automattic/newspack-plugin/commit/a78c6d4d8ecd0ace8c4f2abd670254bd44c8c5ad))

# [1.49.0](https://github.com/Automattic/newspack-plugin/compare/v1.48.1...v1.49.0) (2021-07-19)


### Bug Fixes

* **design-wizard:** correct path to update theme mods ([63ffe7d](https://github.com/Automattic/newspack-plugin/commit/63ffe7d31b63518de314955cd62433cd46cdd8cc))
* **donations:** nrh tweaks ([#1046](https://github.com/Automattic/newspack-plugin/issues/1046)) ([f0e8c13](https://github.com/Automattic/newspack-plugin/commit/f0e8c13ff9c0b3b084d622f17140c18f21e4b1b9))
* **reader-revenue:** don't require Salesforce Campaign ID in NRH settings ([#1050](https://github.com/Automattic/newspack-plugin/issues/1050)) ([4206262](https://github.com/Automattic/newspack-plugin/commit/4206262d29b36ec37aa9be248ddf36e9b51f757d)), closes [#753](https://github.com/Automattic/newspack-plugin/issues/753) [#1051](https://github.com/Automattic/newspack-plugin/issues/1051)
* modal style in WordPress 5.8 ([#1037](https://github.com/Automattic/newspack-plugin/issues/1037)) ([2633162](https://github.com/Automattic/newspack-plugin/commit/2633162a00784d2e3d8e87a8ac45e03fbcb1bad1))
* remove missing wizard CSS ([#1045](https://github.com/Automattic/newspack-plugin/issues/1045)) ([bd96b95](https://github.com/Automattic/newspack-plugin/commit/bd96b95b8e1ced322a217d2e0c0d6e714ecf2312))


### Features

* add Stripe connection - for streamlined Donate block ([#1029](https://github.com/Automattic/newspack-plugin/issues/1029)) ([7c9c396](https://github.com/Automattic/newspack-plugin/commit/7c9c3962f1477cc3337a175fd5a0b00295d5b5e6))
* simplify handoff banner style ([#1041](https://github.com/Automattic/newspack-plugin/issues/1041)) ([d74d045](https://github.com/Automattic/newspack-plugin/commit/d74d045035e871c53fe51ee5ae72ba54cbabe1ce))
* update style of the autocomplete with suggestions component ([#1048](https://github.com/Automattic/newspack-plugin/issues/1048)) ([94815d1](https://github.com/Automattic/newspack-plugin/commit/94815d1b5a089fce4e978639efd08af62d041f22))

## [1.48.1](https://github.com/Automattic/newspack-plugin/compare/v1.48.0...v1.48.1) (2021-07-13)


### Bug Fixes

* apply correct plugin slug for Distributor in the Syndication Wizard ([#1038](https://github.com/Automattic/newspack-plugin/issues/1038)) ([32adb6d](https://github.com/Automattic/newspack-plugin/commit/32adb6de9ade47d80d446263dfdf40851d1a9ef6))

# [1.48.0](https://github.com/Automattic/newspack-plugin/compare/v1.47.0...v1.48.0) (2021-07-06)


### Bug Fixes

* prevent newspack plugins from being flagged as unsupported ([6046c1c](https://github.com/Automattic/newspack-plugin/commit/6046c1ce9764348693098114f84e03e074419857)), closes [#1031](https://github.com/Automattic/newspack-plugin/issues/1031)
* use the new token after refreshing ([0d91e4b](https://github.com/Automattic/newspack-plugin/commit/0d91e4b412cd9229a94f7b6db2f0f7ed4f78602b))


### Features

* **ga-events:** make NTG events reporting enabled by default ([3301b01](https://github.com/Automattic/newspack-plugin/commit/3301b0116cb81d2f7f709e7ba1b3bc30a7e1a474))
* prevent accidental deletion of essential pages ([#1030](https://github.com/Automattic/newspack-plugin/issues/1030)) ([eac3ee2](https://github.com/Automattic/newspack-plugin/commit/eac3ee202fa53413157ec5a26cd0a31f75951306))
* remove unused global ad placements ([3d8d6e3](https://github.com/Automattic/newspack-plugin/commit/3d8d6e3ba2ffddfb9d6548609071dba5ee92e665))
* update the style of the Modal component ([#1013](https://github.com/Automattic/newspack-plugin/issues/1013)) ([a979210](https://github.com/Automattic/newspack-plugin/commit/a979210b2c21c718a5efad8f4ce22087a08f045a)), closes [#1014](https://github.com/Automattic/newspack-plugin/issues/1014) [#1017](https://github.com/Automattic/newspack-plugin/issues/1017) [#1019](https://github.com/Automattic/newspack-plugin/issues/1019) [#1012](https://github.com/Automattic/newspack-plugin/issues/1012) [#1026](https://github.com/Automattic/newspack-plugin/issues/1026) [#861](https://github.com/Automattic/newspack-plugin/issues/861)

# [1.47.0](https://github.com/Automattic/newspack-plugin/compare/v1.46.1...v1.47.0) (2021-06-30)


### Features

* prompt duplication UI in a modal in the Campaigns wizard ([#1012](https://github.com/Automattic/newspack-plugin/issues/1012)) ([087004f](https://github.com/Automattic/newspack-plugin/commit/087004f2150279cd0c7c532daea7373f5398f092))

## [1.46.1](https://github.com/Automattic/newspack-plugin/compare/v1.46.0...v1.46.1) (2021-06-29)


### Bug Fixes

* make autocreated donation products downloadable for better checkout ([#1017](https://github.com/Automattic/newspack-plugin/issues/1017)) ([0e11b5c](https://github.com/Automattic/newspack-plugin/commit/0e11b5c6c4ba67ca02f100f73322e9657bb47555))
* use http ogurl for consistency ([#1019](https://github.com/Automattic/newspack-plugin/issues/1019)) ([791bcee](https://github.com/Automattic/newspack-plugin/commit/791bceec98d9eafa033928d04c636e04f868f6ef))

# [1.46.0](https://github.com/Automattic/newspack-plugin/compare/v1.45.0...v1.46.0) (2021-06-22)


### Bug Fixes

* distributor plugin URL and slug in plugins whitelist ([#1014](https://github.com/Automattic/newspack-plugin/issues/1014)) ([ab46f1c](https://github.com/Automattic/newspack-plugin/commit/ab46f1c759b661f95fcb578608c45c21b4085c68))


### Features

* remove organic-profile-block from supported plugins ([05f8ebf](https://github.com/Automattic/newspack-plugin/commit/05f8ebfe9f3e6b5d496298871bed3096963255c3))
* update "add new prompt" icons to match Gutenberg style ([#1007](https://github.com/Automattic/newspack-plugin/issues/1007)) ([f831198](https://github.com/Automattic/newspack-plugin/commit/f831198dd78f4a6113a576a5217083f4510353d3))

# [1.45.0](https://github.com/Automattic/newspack-plugin/compare/v1.44.0...v1.45.0) (2021-06-16)


### Bug Fixes

* handle no description in action card component ([55664f6](https://github.com/Automattic/newspack-plugin/commit/55664f6c7ad9c02b094aa58b019711f415d20bea))


### Features

* **amp-plus:** selectively allow JS on AMP pages ([#990](https://github.com/Automattic/newspack-plugin/issues/990)) ([40d181a](https://github.com/Automattic/newspack-plugin/commit/40d181a2cb20c8ed059b320bfa83df4affa8e880))

# [1.44.0](https://github.com/Automattic/newspack-plugin/compare/v1.43.0...v1.44.0) (2021-06-15)


### Features

* add checkbox prop to action-card ([#1002](https://github.com/Automattic/newspack-plugin/issues/1002)) ([167ab07](https://github.com/Automattic/newspack-plugin/commit/167ab07dd1ea4d3c7cb476a8e4d3e68a1c526aeb))
* campaign analytics update design ([#995](https://github.com/Automattic/newspack-plugin/issues/995)) ([ea89802](https://github.com/Automattic/newspack-plugin/commit/ea898024007ae8812f908fb8dcb35021765f6efa))
* duplicate a prompt ([#1001](https://github.com/Automattic/newspack-plugin/issues/1001)) ([e25cdcd](https://github.com/Automattic/newspack-plugin/commit/e25cdcd40813b1636574fb071e1765b1edde87f7))
* handle GAM ad units ([#936](https://github.com/Automattic/newspack-plugin/issues/936)) ([ecd0179](https://github.com/Automattic/newspack-plugin/commit/ecd0179dbf4b17e7610ef4549e5dddfbec114e2b))
* setup wizard use action cards for integrations ([#1003](https://github.com/Automattic/newspack-plugin/issues/1003)) ([2bfdaf5](https://github.com/Automattic/newspack-plugin/commit/2bfdaf51c6a837b7951f7d7bea2f861856ae8266))
* **design-wizard:** allow custom font importing ([#999](https://github.com/Automattic/newspack-plugin/issues/999)) ([1e1a77c](https://github.com/Automattic/newspack-plugin/commit/1e1a77c4fd2e119354522228ca4b5dd90cae7bc5))

# [1.43.0](https://github.com/Automattic/newspack-plugin/compare/v1.42.0...v1.43.0) (2021-06-08)


### Features

* design update to the SEO wizard ([#979](https://github.com/Automattic/newspack-plugin/issues/979)) ([a259fec](https://github.com/Automattic/newspack-plugin/commit/a259fec94c16e9d2c40c6799b07a71786def05e1))
* remove material icons and rework Analytics and Ads Wizards ([#982](https://github.com/Automattic/newspack-plugin/issues/982)) ([1fb2eee](https://github.com/Automattic/newspack-plugin/commit/1fb2eee537b460cec6513785acb7126c49d0703d))
* update campaigns wording ([#992](https://github.com/Automattic/newspack-plugin/issues/992)) ([e83d9a8](https://github.com/Automattic/newspack-plugin/commit/e83d9a854acca0d00ef675bd17c55f4775c842fd))
* use WPCOM endpoint for ticket submission ([#819](https://github.com/Automattic/newspack-plugin/issues/819)) ([ffc9567](https://github.com/Automattic/newspack-plugin/commit/ffc95678c66afe2bfe9df429d59f818605bb8f46))

# [1.42.0](https://github.com/Automattic/newspack-plugin/compare/v1.41.0...v1.42.0) (2021-06-02)


### Features

* add multi-select capabillity to AutocompleteWithSuggestions ([#975](https://github.com/Automattic/newspack-plugin/issues/975)) ([d7aebe2](https://github.com/Automattic/newspack-plugin/commit/d7aebe22ffce8426049728a262055da8fd800251))
* use WPCOM as a proxy for Google OAuth2 flow ([#962](https://github.com/Automattic/newspack-plugin/issues/962)) ([b95fcc0](https://github.com/Automattic/newspack-plugin/commit/b95fcc0b9994b1073b193e6e05b57e3cda210c74))

# [1.41.0](https://github.com/Automattic/newspack-plugin/compare/v1.40.0...v1.41.0) (2021-05-26)


### Bug Fixes

* prevent external links from ad dashboard UI ([#976](https://github.com/Automattic/newspack-plugin/issues/976)) ([68b9fbd](https://github.com/Automattic/newspack-plugin/commit/68b9fbd4684ff457826102907728bf431888ab94))


### Features

* add more fonts to design options ([#972](https://github.com/Automattic/newspack-plugin/issues/972)) ([70cec4f](https://github.com/Automattic/newspack-plugin/commit/70cec4fef3b88a140dd3794e867e19e4aa9cb7a9))
* reinstate manual-only placement option ([#960](https://github.com/Automattic/newspack-plugin/issues/960)) ([2438755](https://github.com/Automattic/newspack-plugin/commit/243875506f3ef39bcd8db8dabd363a5da7f6e8f1))
* update advertizing global settings and use action-card ([#974](https://github.com/Automattic/newspack-plugin/issues/974)) ([c0a4853](https://github.com/Automattic/newspack-plugin/commit/c0a4853ffdc05d4e8185000154d9c92eaa34f5e7))
* update gray colors using latest WordPress base-style ([#971](https://github.com/Automattic/newspack-plugin/issues/971)) ([dd64f4e](https://github.com/Automattic/newspack-plugin/commit/dd64f4ec1dc98f4ed6cf9e8b72bd8ef1f354a127))

# [1.40.0](https://github.com/Automattic/newspack-plugin/compare/v1.39.0...v1.40.0) (2021-05-18)


### Bug Fixes

* replace WP User Avatar with Simple Local Avatars ([#966](https://github.com/Automattic/newspack-plugin/issues/966)) ([f980412](https://github.com/Automattic/newspack-plugin/commit/f98041216d857d72bd373b1377b97aa7e4c68b23))
* **oauth:** wpcom token saving ([24052d6](https://github.com/Automattic/newspack-plugin/commit/24052d65ba9e15635b4f21d9d6e02d0433351ada))
* **progress-bar:** radius when having headings ([#963](https://github.com/Automattic/newspack-plugin/issues/963)) ([8347362](https://github.com/Automattic/newspack-plugin/commit/8347362c1a9918d729187d28214d91687c8065b8))
* loading quiet anim time/height and margin when admin menu is folded ([#958](https://github.com/Automattic/newspack-plugin/issues/958)) ([f297780](https://github.com/Automattic/newspack-plugin/commit/f297780803c05dc86eb2ff63e18a559d1eb144a9))


### Features

* add an AutocompleteWithSuggestions component for reusability ([#952](https://github.com/Automattic/newspack-plugin/issues/952)) ([460728d](https://github.com/Automattic/newspack-plugin/commit/460728da79ceffd3d8b53f44d8772f5d1223617b))
* add new ButtonCard component ([#961](https://github.com/Automattic/newspack-plugin/issues/961)) ([eff9edf](https://github.com/Automattic/newspack-plugin/commit/eff9edfd8e8a054ab304543f5e3d7397de9a6f4e))
* add reusable blocks extended as supported plugin ([#968](https://github.com/Automattic/newspack-plugin/issues/968)) ([10f9758](https://github.com/Automattic/newspack-plugin/commit/10f97585930eb66f88deb2708bd8301b5a68286c))

# [1.39.0](https://github.com/Automattic/newspack-plugin/compare/v1.38.1...v1.39.0) (2021-05-13)


### Features

* add link to manage reusable blocks ([#949](https://github.com/Automattic/newspack-plugin/issues/949)) ([101baa2](https://github.com/Automattic/newspack-plugin/commit/101baa2245696d535a09a77fd5dc2d9987826d8a))

## [1.38.1](https://github.com/Automattic/newspack-plugin/compare/v1.38.0...v1.38.1) (2021-05-07)


### Bug Fixes

* trigger release build ([95d0c54](https://github.com/Automattic/newspack-plugin/commit/95d0c54a2f36ec6cd2275e4cfbfc2ab8859ba06f))

# [1.38.0](https://github.com/Automattic/newspack-plugin/compare/v1.37.0...v1.38.0) (2021-05-04)


### Bug Fixes

* use guest authors in Slack preview when needed ([#947](https://github.com/Automattic/newspack-plugin/issues/947)) ([e42680e](https://github.com/Automattic/newspack-plugin/commit/e42680e0705f56d78b88c5da7fa20a2750c82442))


### Features

* batch amp-analytics events ([481dc97](https://github.com/Automattic/newspack-plugin/commit/481dc9778ca0288b295d4c011f912e604dbe99bb)), closes [#914](https://github.com/Automattic/newspack-plugin/issues/914)
* integrate Newspack Scheduled Post Checker into main plugin ([#940](https://github.com/Automattic/newspack-plugin/issues/940)) ([c6adc1b](https://github.com/Automattic/newspack-plugin/commit/c6adc1b6fc4904e38b410b71a7c3ee1e2f7a68b8))

# [1.37.0](https://github.com/Automattic/newspack-plugin/compare/v1.36.0...v1.37.0) (2021-04-28)


### Bug Fixes

* **google-oauth:** credentials refreshment ([92c4fce](https://github.com/Automattic/newspack-plugin/commit/92c4fce2f02ae3a45e9c55737534f55baaa471f4))


### Features

* reorganize segment UI ([#862](https://github.com/Automattic/newspack-plugin/issues/862)) ([bbf2d35](https://github.com/Automattic/newspack-plugin/commit/bbf2d35c2f6f22506e09c8a663cc52013b57132d))
* **google:** set up OAuth2 authentication ([#935](https://github.com/Automattic/newspack-plugin/issues/935)) ([98ee47b](https://github.com/Automattic/newspack-plugin/commit/98ee47bdee1222b3985bfde9f5f9f20021d088a8))

# [1.36.0](https://github.com/Automattic/newspack-plugin/compare/v1.35.0...v1.36.0) (2021-04-21)


### Bug Fixes

* **onboarding:** flow issues ([#931](https://github.com/Automattic/newspack-plugin/issues/931)) ([794a5f0](https://github.com/Automattic/newspack-plugin/commit/794a5f04034b7ce94164d8bc82c088096a746966))


### Features

* **engagement:** remove MJML settings ([#934](https://github.com/Automattic/newspack-plugin/issues/934)) ([fbf5f7d](https://github.com/Automattic/newspack-plugin/commit/fbf5f7d34bd8d20422eb88279dc6e0e00ef3a0e5)), closes [Automattic/newspack-newsletters#443](https://github.com/Automattic/newspack-newsletters/issues/443)
* **onboarding:** homepage patterns ([#932](https://github.com/Automattic/newspack-plugin/issues/932)) ([4c42f54](https://github.com/Automattic/newspack-plugin/commit/4c42f541ad716c7d1a3a6083ae5ce4a711ad0e69))

# [1.35.0](https://github.com/Automattic/newspack-plugin/compare/v1.34.1...v1.35.0) (2021-04-06)


### Bug Fixes

* **campaigns-analytics:** fetch next pages of analytics reports ([e95bbe7](https://github.com/Automattic/newspack-plugin/commit/e95bbe74038db3f7b6b5ee81641624e71d2a6367))


### Features

* add web stories to supported plugin list ([#927](https://github.com/Automattic/newspack-plugin/issues/927)) ([d7250f5](https://github.com/Automattic/newspack-plugin/commit/d7250f5784a80e85c59dade3dbc7ad9dda01ebd4))
* **analytics:** make NTG events reporting disabled by default ([19a8682](https://github.com/Automattic/newspack-plugin/commit/19a868212361fddaa4b33b28db550f50fbd1e560))

## [1.34.1](https://github.com/Automattic/newspack-plugin/compare/v1.34.0...v1.34.1) (2021-03-30)


### Bug Fixes

* **campaigns-wizard:** analytics reporting ([4ad398c](https://github.com/Automattic/newspack-plugin/commit/4ad398c31d2b117dbf56bd6fa7c14fffc9c0a4c1))

# [1.34.0](https://github.com/Automattic/newspack-plugin/compare/v1.33.2...v1.34.0) (2021-03-24)


### Bug Fixes

* apply correct color to jetpack logo ([#910](https://github.com/Automattic/newspack-plugin/issues/910)) ([686362c](https://github.com/Automattic/newspack-plugin/commit/686362c308432b5df2071de64817d82ed51575af))
* redirect after setup complete ([#908](https://github.com/Automattic/newspack-plugin/issues/908)) ([5624822](https://github.com/Automattic/newspack-plugin/commit/5624822a9318802f58ad3a7a36db012fb7a70e79))
* starter content issues ([#905](https://github.com/Automattic/newspack-plugin/issues/905)) ([6cbc40d](https://github.com/Automattic/newspack-plugin/commit/6cbc40d83f46ed6423f3f122783f9e65194d90d8)), closes [#899](https://github.com/Automattic/newspack-plugin/issues/899) [#904](https://github.com/Automattic/newspack-plugin/issues/904) [#903](https://github.com/Automattic/newspack-plugin/issues/903)


### Features

* custom placements ([#898](https://github.com/Automattic/newspack-plugin/issues/898)) ([2cadb45](https://github.com/Automattic/newspack-plugin/commit/2cadb45b0a0897b282de34a8e4eb5137a7b7f617))
* leaner GA config ([#914](https://github.com/Automattic/newspack-plugin/issues/914)) ([81cdff6](https://github.com/Automattic/newspack-plugin/commit/81cdff6c46089f3db66f6794528d0c4927c4b7db)), closes [#911](https://github.com/Automattic/newspack-plugin/issues/911)
* remove woocommerce-one-page-checkout from supported plugins list  ([#913](https://github.com/Automattic/newspack-plugin/issues/913)) ([7fa2e31](https://github.com/Automattic/newspack-plugin/commit/7fa2e315bd5fbbd60b250324232e70df53fc9653))
* update welcome wizard card footer ([#909](https://github.com/Automattic/newspack-plugin/issues/909)) ([134a0e9](https://github.com/Automattic/newspack-plugin/commit/134a0e9cff782e6b68125e5551e697048e47e470))
* **setup:** design step ([#892](https://github.com/Automattic/newspack-plugin/issues/892)) ([254f08b](https://github.com/Automattic/newspack-plugin/commit/254f08b14f2f2d85bd559ca904f89e816c96d86f))

## [1.33.2](https://github.com/Automattic/newspack-plugin/compare/v1.33.1...v1.33.2) (2021-03-16)


### Bug Fixes

* categories handling in segment settings ([#902](https://github.com/Automattic/newspack-plugin/issues/902)) ([ab77f70](https://github.com/Automattic/newspack-plugin/commit/ab77f7066a147af076dd6f4f9fb43f44283fb7ed))
* redirect loop when plug sign in url ([#842](https://github.com/Automattic/newspack-plugin/issues/842)) ([c9d2c3c](https://github.com/Automattic/newspack-plugin/commit/c9d2c3cca43f04988e4a4c38af4e20462da5970b))

## [1.33.1](https://github.com/Automattic/newspack-plugin/compare/v1.33.0...v1.33.1) (2021-03-09)


### Bug Fixes

* draggable ActionCard styling ([16820e3](https://github.com/Automattic/newspack-plugin/commit/16820e337b9184f9985f1daa9ee58d0ac9adf48a))
* **campaigns-wizard:** settings reading ([29b7020](https://github.com/Automattic/newspack-plugin/commit/29b702096c063f031b96fcc0ee87937e3b379586))

# [1.33.0](https://github.com/Automattic/newspack-plugin/compare/v1.32.0...v1.33.0) (2021-03-03)


### Features

* allow disabling NTG events reporting ([1f8a1ea](https://github.com/Automattic/newspack-plugin/commit/1f8a1eaf755d8e0e8a6211516d8535690f640c4a))
* non-cascading segment logic; multiple segments per prompt ([#853](https://github.com/Automattic/newspack-plugin/issues/853), [#874](https://github.com/Automattic/newspack-plugin/issues/874)) ([8ed836a](https://github.com/Automattic/newspack-plugin/commit/8ed836ad0c41a51694ffa0266b1614c193881812))
* remove all cookies prior to setting preview cookie ([57748ee](https://github.com/Automattic/newspack-plugin/commit/57748ee1706fdd71316a835576e753da41a0b51f))
* remove cookie on preview close ([6082910](https://github.com/Automattic/newspack-plugin/commit/6082910153a4f31d5d014b7b11ea25c5cdc982f9))
* replace CID cookie on preview triggering ([c1d1561](https://github.com/Automattic/newspack-plugin/commit/c1d1561ed842618e860c96cce12b5e5256c1b49b))
* replace CID cookie on preview triggering ([#891](https://github.com/Automattic/newspack-plugin/issues/891)) ([3dc451b](https://github.com/Automattic/newspack-plugin/commit/3dc451bb75348777a0ffe79f2229fa1706319d1d))
* update footer to support simple style ([#885](https://github.com/Automattic/newspack-plugin/issues/885)) ([0b2c3f2](https://github.com/Automattic/newspack-plugin/commit/0b2c3f2705224c81bff3f489dfda31db72480eb2))
* use timestamp as a unique CID suffix ([b70abe6](https://github.com/Automattic/newspack-plugin/commit/b70abe6d3a0657ca955d616545f76845ae925974))
* **onboarding:** Services step; Newsletters wizard ([#870](https://github.com/Automattic/newspack-plugin/issues/870)) ([b82bd0a](https://github.com/Automattic/newspack-plugin/commit/b82bd0a52d44c1afaf0808ba200bcdd28cda763b))
* **web-preview:** update design ([#890](https://github.com/Automattic/newspack-plugin/issues/890)) ([eceaf28](https://github.com/Automattic/newspack-plugin/commit/eceaf282442326cfacde59b9ac6e8de440e7e805))

# [1.32.0](https://github.com/Automattic/newspack-plugin/compare/v1.31.0...v1.32.0) (2021-02-25)


### Bug Fixes

* duplication of segments when dragging while re-sorting ([#881](https://github.com/Automattic/newspack-plugin/issues/881)) ([5f9a760](https://github.com/Automattic/newspack-plugin/commit/5f9a760f258eac0d6f80ccb7241ceee582036811))
* only show sticky ad at mobile viewports ([#873](https://github.com/Automattic/newspack-plugin/issues/873)) ([a0fed02](https://github.com/Automattic/newspack-plugin/commit/a0fed02176dbd01ccd546a6020e6911de2d9f4b2))


### Features

* update style of the segments and prompts ([#860](https://github.com/Automattic/newspack-plugin/issues/860)) ([4b66384](https://github.com/Automattic/newspack-plugin/commit/4b6638444bd6e7fa6db4f2479172ffffdeebb6c0))
* validated segmentation sort and error handling ([#886](https://github.com/Automattic/newspack-plugin/issues/886)) ([275fb71](https://github.com/Automattic/newspack-plugin/commit/275fb71f8697b8d03df4f96e883a92efaf04a7b9))
* visually update integrations step ([#877](https://github.com/Automattic/newspack-plugin/issues/877)) ([9b82fdb](https://github.com/Automattic/newspack-plugin/commit/9b82fdb55195d6b9af6076d803294c22c38a7395))

# [1.31.0](https://github.com/Automattic/newspack-plugin/compare/v1.30.1...v1.31.0) (2021-02-19)


### Bug Fixes

* don't include site domain in linker ([#868](https://github.com/Automattic/newspack-plugin/issues/868)) ([ee435cd](https://github.com/Automattic/newspack-plugin/commit/ee435cd2e4831bb2cc75a014291dd6d8241f2f42))


### Features

* starter content removal ([#864](https://github.com/Automattic/newspack-plugin/issues/864)) ([3516cde](https://github.com/Automattic/newspack-plugin/commit/3516cde6a4095c7f0608095e3eb8f550947294ae))
* **setup-wizard:** settings step ([#863](https://github.com/Automattic/newspack-plugin/issues/863)) ([fff2ec5](https://github.com/Automattic/newspack-plugin/commit/fff2ec537382e38ac7c78db4d2dcd177bb588c77))

## [1.30.1](https://github.com/Automattic/newspack-plugin/compare/v1.30.0...v1.30.1) (2021-02-16)


### Bug Fixes

* display uncategorized category in campaigns wizard ([#865](https://github.com/Automattic/newspack-plugin/issues/865)) ([df59340](https://github.com/Automattic/newspack-plugin/commit/df5934084060bf50ffdc95f25ba18d6bac49cf56))

# [1.30.0](https://github.com/Automattic/newspack-plugin/compare/v1.29.0...v1.30.0) (2021-02-11)


### Bug Fixes

* button group style ([#843](https://github.com/Automattic/newspack-plugin/issues/843)) ([8c4b056](https://github.com/Automattic/newspack-plugin/commit/8c4b05660f105ca17a43d5166f9bdb0fe90e447f))
* change in how to retrieve property id from site kit ([#849](https://github.com/Automattic/newspack-plugin/issues/849)) ([fd117f8](https://github.com/Automattic/newspack-plugin/commit/fd117f8613b449e029df094abc3061d901cfbfe0))
* patrons logo rename DOM properties ([#841](https://github.com/Automattic/newspack-plugin/issues/841)) ([3f897c3](https://github.com/Automattic/newspack-plugin/commit/3f897c3c87e51d9b56b0778fdad65ff7eefa9c5b))
* preview functionality by campaign/group ([#856](https://github.com/Automattic/newspack-plugin/issues/856)) ([4eae456](https://github.com/Automattic/newspack-plugin/commit/4eae456db8f114eb7bad6920b1a518873ddaf9d6))
* **campaigns-wizard:** fix site kit connection ([83fd493](https://github.com/Automattic/newspack-plugin/commit/83fd493ca5555c1ee2084cf20747394aff2fc254))


### Features

* add segment descriptions to Campaigns and Segments tabs ([#847](https://github.com/Automattic/newspack-plugin/issues/847)) ([0317d1f](https://github.com/Automattic/newspack-plugin/commit/0317d1fde45517bc562f4c16487db1b01cc7e003))
* campaigns wizard overhaul ([#833](https://github.com/Automattic/newspack-plugin/issues/833)) ([39f495e](https://github.com/Automattic/newspack-plugin/commit/39f495e8301d9cfbd8f513d74a2f3ea510633a03))
* increase segment border color and increase add prompt button width ([#857](https://github.com/Automattic/newspack-plugin/issues/857)) ([a03635f](https://github.com/Automattic/newspack-plugin/commit/a03635f04ae315e54098d0cdb65a5837cf90bc3e))
* onboarding overhaul ([#851](https://github.com/Automattic/newspack-plugin/issues/851)) ([2704728](https://github.com/Automattic/newspack-plugin/commit/27047288c376fa71a7c0fdca57bfd36976e60d78))
* segment priority UI and logic ([#832](https://github.com/Automattic/newspack-plugin/issues/832)) ([ad5692d](https://github.com/Automattic/newspack-plugin/commit/ad5692dff44469e653a9b554220d8ac922d44fb5))
* Update header actions wizards (Campaigns and Segments) ([#845](https://github.com/Automattic/newspack-plugin/issues/845)) ([ed3bc73](https://github.com/Automattic/newspack-plugin/commit/ed3bc73220644239c227e4dd9e83bab0ee7bed1b))

# [1.29.0](https://github.com/Automattic/newspack-plugin/compare/v1.28.0...v1.29.0) (2021-01-28)


### Bug Fixes

* hide footer while loading to prevent overlap ([#835](https://github.com/Automattic/newspack-plugin/issues/835)) ([98ae903](https://github.com/Automattic/newspack-plugin/commit/98ae903630459e74344b4ada04502462312512b1))
* show campaigns with pending and future status in UI ([#757](https://github.com/Automattic/newspack-plugin/issues/757)) ([e8528a6](https://github.com/Automattic/newspack-plugin/commit/e8528a6580242ba8c48a02919b8be4eb93dcabaa))


### Features

* **campaigns-wizard:** donor landing page setting ([#829](https://github.com/Automattic/newspack-plugin/issues/829)) ([8829c00](https://github.com/Automattic/newspack-plugin/commit/8829c003612f5cebdb87d4c5649663f58e4e80d9))
* hide "Uncategorized" category from Campaigns UI ([#836](https://github.com/Automattic/newspack-plugin/issues/836)) ([63912c9](https://github.com/Automattic/newspack-plugin/commit/63912c98feebc529d4b1dd126b6334faea948b8b))
* new onboarding menu UI (v3) ([#739](https://github.com/Automattic/newspack-plugin/issues/739)) ([dcb191b](https://github.com/Automattic/newspack-plugin/commit/dcb191b32b6af93afa908927f28456a3934b90a1))
* **campaigns-wizard:** support referrer exclusion segmentation ([a164fa8](https://github.com/Automattic/newspack-plugin/commit/a164fa83fb993cd1ed0052ded35d4250ccfed79c))

# [1.28.0](https://github.com/Automattic/newspack-plugin/compare/v1.27.0...v1.28.0) (2021-01-21)


### Bug Fixes

* bug preventing new segments from being savable ([#823](https://github.com/Automattic/newspack-plugin/issues/823)) ([5884897](https://github.com/Automattic/newspack-plugin/commit/58848976e8a090f99422f9d9056e1b5bf600e7dc))
* in preview tab, allow previewing without choosing groups ([#820](https://github.com/Automattic/newspack-plugin/issues/820)) ([a486f26](https://github.com/Automattic/newspack-plugin/commit/a486f26c2a6c6b29c5bb29eb01a1e2dd0b8730cf))
* use "all" in view-as spec ([#813](https://github.com/Automattic/newspack-plugin/issues/813)) ([b1962fd](https://github.com/Automattic/newspack-plugin/commit/b1962fdfd2153bed4c24ebda03604203d82a3fad))
* **salesforce:** handle missing webhook ([#802](https://github.com/Automattic/newspack-plugin/issues/802)) ([07f2b91](https://github.com/Automattic/newspack-plugin/commit/07f2b91d057ab2572b7fec5e2ca9948be2af3658))
* add min-width to popover and fix alignment ([#778](https://github.com/Automattic/newspack-plugin/issues/778)) ([7107b97](https://github.com/Automattic/newspack-plugin/commit/7107b972bb769cc18666c93894083a622e84f55b)), closes [#762](https://github.com/Automattic/newspack-plugin/issues/762)
* radio control label font size and line height ([#746](https://github.com/Automattic/newspack-plugin/issues/746)) ([c9a4b31](https://github.com/Automattic/newspack-plugin/commit/c9a4b318b0edddd986ab1c15245fa2e912329bbe))


### Features

* campaign group management ui ([#822](https://github.com/Automattic/newspack-plugin/issues/822)) ([4c67b86](https://github.com/Automattic/newspack-plugin/commit/4c67b861216921e65242eef2f918c80f348e6bb4))
* **campaigns-wizard:** selects instead of checkboxes ([7a4c0a9](https://github.com/Automattic/newspack-plugin/commit/7a4c0a979c183680ee727a6ed3bb114b3309362d)), closes [#710](https://github.com/Automattic/newspack-plugin/issues/710)
* add a sticky ad slot to the Advertising wizard ([#812](https://github.com/Automattic/newspack-plugin/issues/812)) ([fd4eb6f](https://github.com/Automattic/newspack-plugin/commit/fd4eb6f5b6d9aee89833a8a821bca0fa2a9290a1))
* campaign group filter ([#769](https://github.com/Automattic/newspack-plugin/issues/769)) ([b790e06](https://github.com/Automattic/newspack-plugin/commit/b790e065375b42a1f4f15dc6beea8035065c29ad))
* campaign group filter with preview ([#771](https://github.com/Automattic/newspack-plugin/issues/771)) ([f3a23d9](https://github.com/Automattic/newspack-plugin/commit/f3a23d9cbb6a9c3038575f1baad006e529176c47))
* campaign groups in popup action card ([#767](https://github.com/Automattic/newspack-plugin/issues/767)) ([6d25f06](https://github.com/Automattic/newspack-plugin/commit/6d25f06a6d6cbf7d217ccb2ae8d03183abd00c0f))
* consolidate campaigns into one tab ([#768](https://github.com/Automattic/newspack-plugin/issues/768)) ([49bba50](https://github.com/Automattic/newspack-plugin/commit/49bba5099de4f1b68c48963b99644a616350344e))
* deprecate test mode and never frequency ([#809](https://github.com/Automattic/newspack-plugin/issues/809)) ([e79c728](https://github.com/Automattic/newspack-plugin/commit/e79c72823077e8ae48be2b166c28b3b9d2bd1fcf))
* hide the confusing help text in category autocomplete field ([#765](https://github.com/Automattic/newspack-plugin/issues/765)) ([5107df7](https://github.com/Automattic/newspack-plugin/commit/5107df794ba6e29077e8f7103626cf74bdb863f8))
* increase button small height, adjust secondary and focus ([#780](https://github.com/Automattic/newspack-plugin/issues/780)) ([1bbe4be](https://github.com/Automattic/newspack-plugin/commit/1bbe4bebb36f51588b8abe05c9157e1180586d48))
* linked titles in campaigns wizard action cards ([#795](https://github.com/Automattic/newspack-plugin/issues/795)) ([6cc8bcd](https://github.com/Automattic/newspack-plugin/commit/6cc8bcd8d2bcdc4299d511b38f3a21d0c37275a0))
* pass UTM params to Salesforce opportunities ([#785](https://github.com/Automattic/newspack-plugin/issues/785)) ([7af0bad](https://github.com/Automattic/newspack-plugin/commit/7af0bad235694ccfdafb666cf79540f98b2a907c))
* popover to choose type of new campaign ([#797](https://github.com/Automattic/newspack-plugin/issues/797)) ([393a557](https://github.com/Automattic/newspack-plugin/commit/393a557cf9813f80288270bfb5dc99c0aa459d96))
* quiet loading class ([#798](https://github.com/Automattic/newspack-plugin/issues/798)) ([a12f874](https://github.com/Automattic/newspack-plugin/commit/a12f874bd80c19b1a7c4891e0aec4ee99f861c32))
* segment picker in campaign popover ([#772](https://github.com/Automattic/newspack-plugin/issues/772)) ([fdcd248](https://github.com/Automattic/newspack-plugin/commit/fdcd248c2baff694570ed4ee46983e13d7e6dd9d))
* segmentation webview ([#770](https://github.com/Automattic/newspack-plugin/issues/770)) ([fb223fd](https://github.com/Automattic/newspack-plugin/commit/fb223fd3b5e833e4745e8e4e1334a55e844ea772))
* small visual update to the campaign and segmentation wizards ([#800](https://github.com/Automattic/newspack-plugin/issues/800)) ([1fa60a2](https://github.com/Automattic/newspack-plugin/commit/1fa60a26c799a9e1b899adb022d23e8e0905eaaa))
* support manual-only placement ([#796](https://github.com/Automattic/newspack-plugin/issues/796)) ([a810207](https://github.com/Automattic/newspack-plugin/commit/a81020772f3036f69dd902ac39f34c1da4a9a235))
* taxonomy, segment and placement to popover ([#794](https://github.com/Automattic/newspack-plugin/issues/794)) ([0acaff6](https://github.com/Automattic/newspack-plugin/commit/0acaff64fe1c5604d1d89994973891e4d65898f1))
* update grid component and wizards using it ([#747](https://github.com/Automattic/newspack-plugin/issues/747)) ([4f291db](https://github.com/Automattic/newspack-plugin/commit/4f291db873013dc9b86005fa8c43d1b9aa5777a6))
* update icons for web preview and popup action card ([#752](https://github.com/Automattic/newspack-plugin/issues/752)) ([33df8ae](https://github.com/Automattic/newspack-plugin/commit/33df8ae3a418be6ed9792c69ed36a32a6daae894))
* update is-loading animation ([#751](https://github.com/Automattic/newspack-plugin/issues/751)) ([354b87a](https://github.com/Automattic/newspack-plugin/commit/354b87add8ac7ee2d3417ab5b9a8ca439815d6ff))
* update notice component ([#748](https://github.com/Automattic/newspack-plugin/issues/748)) ([3cd439a](https://github.com/Automattic/newspack-plugin/commit/3cd439a144b04d7cba8e9f8704833236634bde67))
* update style card design ([#814](https://github.com/Automattic/newspack-plugin/issues/814)) ([fab3ddb](https://github.com/Automattic/newspack-plugin/commit/fab3ddb0c2abf1e52a75a62676ce155995eedb7f))
* update style of the group's select control in the campaign wizard ([#792](https://github.com/Automattic/newspack-plugin/issues/792)) ([e9401ee](https://github.com/Automattic/newspack-plugin/commit/e9401eeb3fdb4fa69a05d382cde62e8a8bda18ed))
* update the new segmentation wizard style to use grid and card ([#783](https://github.com/Automattic/newspack-plugin/issues/783)) ([4743bc4](https://github.com/Automattic/newspack-plugin/commit/4743bc4839f1455f3ee71f4687f7c9a3845ddf06))
* **campaigns-wizard:** add session read count segmentation handling ([4194198](https://github.com/Automattic/newspack-plugin/commit/41941983c3ef182155d4bb226d73babf8cf26734))
* **campaigns-wizard:** display popup ID ([#706](https://github.com/Automattic/newspack-plugin/issues/706)) ([f6cfa87](https://github.com/Automattic/newspack-plugin/commit/f6cfa87fb024a18bc613af50e27f0b04e90794cc))
* **campaigns-wizard:** handle favorite category segmentation ([#707](https://github.com/Automattic/newspack-plugin/issues/707)) ([37b069e](https://github.com/Automattic/newspack-plugin/commit/37b069e062db6202aec7cefdd1675dca41cb14d3))
* **campaigns-wizard:** preview ("view as") ([#741](https://github.com/Automattic/newspack-plugin/issues/741)) ([e1af6ec](https://github.com/Automattic/newspack-plugin/commit/e1af6ec787eaa0f0d7d36673facc0eed2244109c))

# [1.27.0](https://github.com/Automattic/newspack-plugin/compare/v1.26.0...v1.27.0) (2020-12-15)


### Bug Fixes

* **campaigns-wizard:** empty section ([33f055b](https://github.com/Automattic/newspack-plugin/commit/33f055bcad7d7d303fe6efddb92f2b9cf0553995))
* update newspack-popups constant name ([#733](https://github.com/Automattic/newspack-plugin/issues/733)) ([2c800fe](https://github.com/Automattic/newspack-plugin/commit/2c800feac26f0363707bb448ea179fd1ea844ac6))


### Features

* improve debug mode ([56992a7](https://github.com/Automattic/newspack-plugin/commit/56992a70cb6abc9e1401948f6a0d5b346ba267bb))
* update dashboard header style ([#732](https://github.com/Automattic/newspack-plugin/issues/732)) ([a4ac3da](https://github.com/Automattic/newspack-plugin/commit/a4ac3da8c9be6a27ca785f194fb79c4faf6ecded))
* update info button icon to use Gutenberg ([#735](https://github.com/Automattic/newspack-plugin/issues/735)) ([5b11ce5](https://github.com/Automattic/newspack-plugin/commit/5b11ce577bea601c0cf5643a856e98001d90c40d))
* update SelectControl and Popover style since WP 5.6 ([#731](https://github.com/Automattic/newspack-plugin/issues/731)) ([c06d444](https://github.com/Automattic/newspack-plugin/commit/c06d44437b032a992dca15e1be84e9bb0d9de430)), closes [#736](https://github.com/Automattic/newspack-plugin/issues/736)
* update toggle group and toggle control vertical alignment ([e35bf7f](https://github.com/Automattic/newspack-plugin/commit/e35bf7f2d73f6cad1c8fb7a9844fdebd46c7a7ac))
* **popups-wizard:** handle above header placement ([#691](https://github.com/Automattic/newspack-plugin/issues/691)) ([a1f2125](https://github.com/Automattic/newspack-plugin/commit/a1f21256a4fd113e3701b8f5db4bd93887b38c53))

# [1.26.0](https://github.com/Automattic/newspack-plugin/compare/v1.25.0...v1.26.0) (2020-12-08)


### Features

* design adjustments to the wizards ([#728](https://github.com/Automattic/newspack-plugin/issues/728)) ([b5be729](https://github.com/Automattic/newspack-plugin/commit/b5be7298544b8f56276d7b551ee43f9db4ca01dc))
* **ga:** report single category if primary category is set ([57d3b01](https://github.com/Automattic/newspack-plugin/commit/57d3b011fd2814c5ccf9b6b2d211f29261d0f914))
* necessary WooComm settings for Checkout to work correctly ([c01e515](https://github.com/Automattic/newspack-plugin/commit/c01e51534476666c8a9c112027316edb8473a156))
* remove laterpay ([#723](https://github.com/Automattic/newspack-plugin/issues/723)) ([f1ed5c3](https://github.com/Automattic/newspack-plugin/commit/f1ed5c3a6954ddcf8e1168a5c24a326c1ca1cbd0))
* update checkbox style to match Gutenberg ([#724](https://github.com/Automattic/newspack-plugin/issues/724)) ([e2fadf5](https://github.com/Automattic/newspack-plugin/commit/e2fadf5e8f0d840e1d8a6169d48ad9a121a94ff9))
* update radio style to match Gutenberg ([#725](https://github.com/Automattic/newspack-plugin/issues/725)) ([2249ad5](https://github.com/Automattic/newspack-plugin/commit/2249ad567ad5f488c07087e2b8e52c5a41462da4))
* wizards redesign and cleanup ([#705](https://github.com/Automattic/newspack-plugin/issues/705)) ([40e6288](https://github.com/Automattic/newspack-plugin/commit/40e62883b7b125c3fc073ca854dd05f514063789))

# [1.25.0](https://github.com/Automattic/newspack-plugin/compare/v1.24.0...v1.25.0) (2020-12-02)


### Bug Fixes

* typo ([abaa10a](https://github.com/Automattic/newspack-plugin/commit/abaa10a740e97e451455ffba879834eaea8a3f63))


### Features

* allow adding external custom dimensions ([#685](https://github.com/Automattic/newspack-plugin/issues/685)) ([b11b7e6](https://github.com/Automattic/newspack-plugin/commit/b11b7e6dc0e767ee3b1a28809eed9066524e58fc))
* update Dashboard icons to use G2 ([#712](https://github.com/Automattic/newspack-plugin/issues/712)) ([97d8986](https://github.com/Automattic/newspack-plugin/commit/97d89861e2855b876023f3cd18a0cec28be647b2))
* update Wizards icons to use G2 ([#714](https://github.com/Automattic/newspack-plugin/issues/714)) ([e66c19b](https://github.com/Automattic/newspack-plugin/commit/e66c19bf7a9e769bd43c411259285c49ae7ace24))
* **campaigns-wizard:** display segment reach ([#709](https://github.com/Automattic/newspack-plugin/issues/709)) ([b700292](https://github.com/Automattic/newspack-plugin/commit/b70029251b081f69fe1215f7607f6df792e88883))

# [1.25.0](https://github.com/Automattic/newspack-plugin/compare/v1.24.0...v1.25.0) (2020-12-02)


### Bug Fixes

* typo ([abaa10a](https://github.com/Automattic/newspack-plugin/commit/abaa10a740e97e451455ffba879834eaea8a3f63))


### Features

* allow adding external custom dimensions ([#685](https://github.com/Automattic/newspack-plugin/issues/685)) ([b11b7e6](https://github.com/Automattic/newspack-plugin/commit/b11b7e6dc0e767ee3b1a28809eed9066524e58fc))
* update Dashboard icons to use G2 ([#712](https://github.com/Automattic/newspack-plugin/issues/712)) ([97d8986](https://github.com/Automattic/newspack-plugin/commit/97d89861e2855b876023f3cd18a0cec28be647b2))
* update Wizards icons to use G2 ([#714](https://github.com/Automattic/newspack-plugin/issues/714)) ([e66c19b](https://github.com/Automattic/newspack-plugin/commit/e66c19bf7a9e769bd43c411259285c49ae7ace24))
* **campaigns-wizard:** display segment reach ([#709](https://github.com/Automattic/newspack-plugin/issues/709)) ([b700292](https://github.com/Automattic/newspack-plugin/commit/b70029251b081f69fe1215f7607f6df792e88883))

# [1.24.0](https://github.com/Automattic/newspack-plugin/compare/v1.23.0...v1.24.0) (2020-11-11)


### Bug Fixes

* **campaigns-wizard:** layout issues ([#692](https://github.com/Automattic/newspack-plugin/issues/692)) ([9fd3a59](https://github.com/Automattic/newspack-plugin/commit/9fd3a59ab98379cbc3ae2ee3b1779020589bfed2))


### Features

* add a note about donation segment and WC ([e04ba0f](https://github.com/Automattic/newspack-plugin/commit/e04ba0f59a8ba877ed334126fb943085f5f1dd56))
* allow gam scripts in amp pages ([#688](https://github.com/Automattic/newspack-plugin/issues/688)) ([7d3a842](https://github.com/Automattic/newspack-plugin/commit/7d3a842df63ecc57e364413410a51d7b3c64399a))
* **campaigns-wizard:** add segment options for subscriptions, donations ([#693](https://github.com/Automattic/newspack-plugin/issues/693)) ([519e79a](https://github.com/Automattic/newspack-plugin/commit/519e79a16f7a66982636b08df879f2553cdbc259)), closes [#249](https://github.com/Automattic/newspack-plugin/issues/249) [#250](https://github.com/Automattic/newspack-plugin/issues/250)
* **campaigns-wizard:** display segment name in campaign card ([ece13d6](https://github.com/Automattic/newspack-plugin/commit/ece13d6c756041ab766eead40fc99758944fa876))
* set up Campaigns segmentation UI ([#689](https://github.com/Automattic/newspack-plugin/issues/689)) ([cd1ef3f](https://github.com/Automattic/newspack-plugin/commit/cd1ef3f7ab9e85476dfb591dcfbca2c77d227efd))
* update React Router Dom to its latest version ([#694](https://github.com/Automattic/newspack-plugin/issues/694)) ([45ad7fa](https://github.com/Automattic/newspack-plugin/commit/45ad7fad80fab46245fc5fb7d55cb6c0402edfb0))
* use newspack-popups' preview post link ([02910dc](https://github.com/Automattic/newspack-plugin/commit/02910dc904412c80c3bc4441d808b0bc8a907d2a))

# [1.23.0](https://github.com/Automattic/newspack-plugin/compare/v1.22.0...v1.23.0) (2020-10-27)


### Features

* trigger release ([660f40d](https://github.com/Automattic/newspack-plugin/commit/660f40d2bbc8396056074af4ce538a6bc6183a91)), closes [#684](https://github.com/Automattic/newspack-plugin/issues/684)

# [1.22.0](https://github.com/Automattic/newspack-plugin/compare/v1.21.1...v1.22.0) (2020-10-07)


### Bug Fixes

* improve generated product settings ([#233](https://github.com/Automattic/newspack-plugin/issues/233)) ([8e28865](https://github.com/Automattic/newspack-plugin/commit/8e2886507e8862b79b037e002a0d20f26fabcdb8))
* increase timeout of Salesforce API requests to 30s ([#679](https://github.com/Automattic/newspack-plugin/issues/679)) ([64a4293](https://github.com/Automattic/newspack-plugin/commit/64a4293cc822ca0addcb5a7bcf96f63f4bd6e409))
* salesforce opportunities should be set to closed/won instead of new ([#675](https://github.com/Automattic/newspack-plugin/issues/675)) ([fdea57f](https://github.com/Automattic/newspack-plugin/commit/fdea57f49a8e71d2a18e065ba00e056e95ab5e20))


### Features

* add AutocompleteTokenfield component ([#674](https://github.com/Automattic/newspack-plugin/issues/674)) ([7edb565](https://github.com/Automattic/newspack-plugin/commit/7edb5651132a614ea65c6b001dec2bb1fdb108bd))
* remove gutenberg from list of managed plugins ([#677](https://github.com/Automattic/newspack-plugin/issues/677)) ([12b5d84](https://github.com/Automattic/newspack-plugin/commit/12b5d84df6784618241d7ce01dd52b1a1b91797f))

## [1.21.1](https://github.com/Automattic/newspack-plugin/compare/v1.21.0...v1.21.1) (2020-09-22)


### Bug Fixes

* **campaigns-wizard:** prevent edge case errors ([7d261c0](https://github.com/Automattic/newspack-plugin/commit/7d261c0d90b3172ab4b4bfb06a3759a25f99dcc9))

# [1.21.0](https://github.com/Automattic/newspack-plugin/compare/v1.20.0...v1.21.0) (2020-09-15)


### Bug Fixes

* ignore Yoast weight limit to prevent missing og:image tags ([#666](https://github.com/Automattic/newspack-plugin/issues/666)) ([8d8bdaa](https://github.com/Automattic/newspack-plugin/commit/8d8bdaa9259926511079a3a7c11ceacbf50f5058))
* override site kit _gl query param behavior ([37500f5](https://github.com/Automattic/newspack-plugin/commit/37500f5542372e73913f8c7d91fcf17e884d76aa))
* select-related layouts ([483de25](https://github.com/Automattic/newspack-plugin/commit/483de25ecf91eb1d0cea2f299b9a6a61e340f1ad))


### Features

* add author, word count, publish date custom dimensions ([#655](https://github.com/Automattic/newspack-plugin/issues/655)) ([7f8662d](https://github.com/Automattic/newspack-plugin/commit/7f8662df5e75278295269626f6fe998a66572a96))
* **analytics:** report User ID ([e1c26d0](https://github.com/Automattic/newspack-plugin/commit/e1c26d07f470e15a9ccd80a55de80fbd05d046ee))

# [1.20.0](https://github.com/Automattic/newspack-plugin/compare/v1.19.0...v1.20.0) (2020-09-08)


### Features

* add support wizard to dashboard ([#650](https://github.com/Automattic/newspack-plugin/issues/650)) ([87ac1dd](https://github.com/Automattic/newspack-plugin/commit/87ac1dd88d05ccb92713edc930a84e7a1f7a8b1b))
* append categories, tags data to WC order; sync w/ SF ([c357155](https://github.com/Automattic/newspack-plugin/commit/c3571556d5d5baec0980721249f6390470bab8e7))

# [1.19.0](https://github.com/Automattic/newspack-plugin/compare/v1.18.0...v1.19.0) (2020-08-25)


### Features

* "quiet" support for yoast premium and yoast news ([0b04912](https://github.com/Automattic/newspack-plugin/commit/0b04912dc9e387963cfe10724b4b7f370f069daf))
* add newspack-sponsors as a managed plugin ([#641](https://github.com/Automattic/newspack-plugin/issues/641)) ([1f0f60b](https://github.com/Automattic/newspack-plugin/commit/1f0f60bc61e5375980e2a75c8d2eda70c1e8ca11))
* add support to distributor ([#635](https://github.com/Automattic/newspack-plugin/issues/635)) ([b7e602f](https://github.com/Automattic/newspack-plugin/commit/b7e602f288d09f6d4ae3819444b49c786a4df907))
* remove performance wizard ([#645](https://github.com/Automattic/newspack-plugin/issues/645)) ([b0774e2](https://github.com/Automattic/newspack-plugin/commit/b0774e2bfd6f0e22d51387881439a78d2a555f8b))

# [1.18.0](https://github.com/Automattic/newspack-plugin/compare/v1.17.0...v1.18.0) (2020-08-18)


### Bug Fixes

* notice in analtyics wizard ([45be0ad](https://github.com/Automattic/newspack-plugin/commit/45be0ad10c58c8572685bf5e81e7332c113ae392))


### Features

* add AutomateWoo and AW Refer Friend to the list of vetted plugins ([#637](https://github.com/Automattic/newspack-plugin/issues/637)) ([018b0ff](https://github.com/Automattic/newspack-plugin/commit/018b0ffe2de29b7fb64399eacbc25b26acec2942))
* update dashboard default view to grid ([#634](https://github.com/Automattic/newspack-plugin/issues/634)) ([77e2e2a](https://github.com/Automattic/newspack-plugin/commit/77e2e2adcb58fa18018e22a85c4cdb4f0a65f07e))

# [1.17.0](https://github.com/Automattic/newspack-plugin/compare/v1.16.2...v1.17.0) (2020-08-11)


### Bug Fixes

* add permission_callback to REST route defn ([8dacf2c](https://github.com/Automattic/newspack-plugin/commit/8dacf2cc2249e603c4ea05d1f580cff5c586cad7))
* remove un-cacheable ajax call in AMP mode from WP GDPR Cookie Notice ([#622](https://github.com/Automattic/newspack-plugin/issues/622)) ([d3be717](https://github.com/Automattic/newspack-plugin/commit/d3be71702b0c2c549ba30cde58c0c555edd3228d))


### Features

* add custom events adding UI ([#611](https://github.com/Automattic/newspack-plugin/issues/611)) ([8f4483a](https://github.com/Automattic/newspack-plugin/commit/8f4483a04bec6d090c661f6026a68fe854afd7bd)), closes [#601](https://github.com/Automattic/newspack-plugin/issues/601)
* update updates wizard details style ([#625](https://github.com/Automattic/newspack-plugin/issues/625)) ([a22beef](https://github.com/Automattic/newspack-plugin/commit/a22beefd51b2ed8569deffb9ba6e3e0e2bc64ac0))

## [1.16.2](https://github.com/Automattic/newspack-plugin/compare/v1.16.1...v1.16.2) (2020-08-04)


### Bug Fixes

* prevent vendor contents exclusion in zip ([#619](https://github.com/Automattic/newspack-plugin/issues/619)) ([c8d6e35](https://github.com/Automattic/newspack-plugin/commit/c8d6e355d335388416910f0eca0d222edadad603))

## [1.16.1](https://github.com/Automattic/newspack-plugin/compare/v1.16.0...v1.16.1) (2020-08-04)


### Bug Fixes

* revert setting version in release zip file name ([d8e421b](https://github.com/Automattic/newspack-plugin/commit/d8e421bfae03cb15cb566e04536c058353fac952))

# [1.16.0](https://github.com/Automattic/newspack-plugin/compare/v1.15.0...v1.16.0) (2020-08-04)


### Bug Fixes

* dont output uninstalled managed plugins in WP CLI ([66a050e](https://github.com/Automattic/newspack-plugin/commit/66a050e206d012d2399a83f9e2bda2a3bcd08fca))
* empty space in three-column wizard grid ([#612](https://github.com/Automattic/newspack-plugin/issues/612)) ([61ef44a](https://github.com/Automattic/newspack-plugin/commit/61ef44a8cdb79d2bc298a85ff94800f44a6d404d))
* site kit connection error handling ([#606](https://github.com/Automattic/newspack-plugin/issues/606)) ([13ad3ae](https://github.com/Automattic/newspack-plugin/commit/13ad3ae7833d747ff981bd24fe5cb29ee75246b0))


### Features

* sync country code of WooCommerce order to Salesforce ([#608](https://github.com/Automattic/newspack-plugin/issues/608)) ([c92c5d8](https://github.com/Automattic/newspack-plugin/commit/c92c5d8180645b234faf3d58f140b543ef35e63f))

# [1.15.0](https://github.com/Automattic/newspack-plugin/compare/v1.14.2...v1.15.0) (2020-07-28)


### Features

* **analytics:** report category as custom dimension ([#600](https://github.com/Automattic/newspack-plugin/issues/600)) ([762c70d](https://github.com/Automattic/newspack-plugin/commit/762c70d455e9fac5e0e9761c4995b18bc712b04a)), closes [#588](https://github.com/Automattic/newspack-plugin/issues/588)

## [1.14.2](https://github.com/Automattic/newspack-plugin/compare/v1.14.1...v1.14.2) (2020-07-23)


### Reverts

* Revert "feat(analytics): report category as custom dimension (#588)" ([bb914c6](https://github.com/Automattic/newspack-plugin/commit/bb914c67142f12eb4f3119e1c7f7d548e79a4fe6)), closes [#588](https://github.com/Automattic/newspack-plugin/issues/588)

## [1.14.1](https://github.com/Automattic/newspack-plugin/compare/v1.14.0...v1.14.1) (2020-07-22)


### Bug Fixes

* allow newspack to install site kit ([#596](https://github.com/Automattic/newspack-plugin/issues/596)) ([728175a](https://github.com/Automattic/newspack-plugin/commit/728175a06c7bdbbea04f6557c0c1a4146bf52075))

# [1.14.0](https://github.com/Automattic/newspack-plugin/compare/v1.13.0...v1.14.0) (2020-07-22)


### Features

* **analytics:** report category as custom dimension ([#588](https://github.com/Automattic/newspack-plugin/issues/588)) ([bdbcbbd](https://github.com/Automattic/newspack-plugin/commit/bdbcbbd97507a86d8eec2ad45adcd66451aa7fbf))
* display latest releases info in plugin dashboard ([#552](https://github.com/Automattic/newspack-plugin/issues/552)) ([72d6086](https://github.com/Automattic/newspack-plugin/commit/72d608672bc72981963c30d9e6016830403556ec))

# [1.13.0](https://github.com/Automattic/newspack-plugin/compare/v1.12.1...v1.13.0) (2020-07-14)


### Bug Fixes

* merge conflicts with master ([b469891](https://github.com/Automattic/newspack-plugin/commit/b469891b02136c38b1af5589adbb5fb2d7b8899c))
* **analytics:** report milestone events for articles only ([#584](https://github.com/Automattic/newspack-plugin/issues/584)) ([de2a24c](https://github.com/Automattic/newspack-plugin/commit/de2a24cfae05e663ed35ebc6adbb6ba66fa4a794))


### Features

* handle pre-launch tickets; enhanced ticket creation ([#585](https://github.com/Automattic/newspack-plugin/issues/585)) ([6f0fc2b](https://github.com/Automattic/newspack-plugin/commit/6f0fc2b209dd89db591e51284167a49ea39775d1)), closes [#548](https://github.com/Automattic/newspack-plugin/issues/548)
* track NTG event when comment form is submitted ([52ac3fd](https://github.com/Automattic/newspack-plugin/commit/52ac3fd4292f22c78de34c731e08d0098d24864d))
* use WC hooks to add NTG account events ([bfa612e](https://github.com/Automattic/newspack-plugin/commit/bfa612ee8765e2fcb2db92f06be11417fed43b60))

## [1.12.1](https://github.com/Automattic/newspack-plugin/compare/v1.12.0...v1.12.1) (2020-07-09)


### Bug Fixes

* **analytics:** non-interaction handling ([9013ad8](https://github.com/Automattic/newspack-plugin/commit/9013ad8c0ae8d40bdda7e2668d5fdb64cbcbc865))

# [1.12.0](https://github.com/Automattic/newspack-plugin/compare/v1.11.0...v1.12.0) (2020-07-07)


### Features

* clean up release zip ([75d2167](https://github.com/Automattic/newspack-plugin/commit/75d2167f011d3fe7daed1bb67b25cd13f00ff568))
* ntg mailing list events in amp ([#575](https://github.com/Automattic/newspack-plugin/issues/575)) ([9017dda](https://github.com/Automattic/newspack-plugin/commit/9017ddab56cc45e982283d2421c9287605bcff5d))

# [1.11.0](https://github.com/Automattic/newspack-plugin/compare/v1.10.0...v1.11.0) (2020-06-30)


### Bug Fixes

* add non_interaction: true for scroll events ([a5660ae](https://github.com/Automattic/newspack-plugin/commit/a5660aecc425eaa5e3a4aaf82b0fde758e016742))
* add text domain to text strings ([159256f](https://github.com/Automattic/newspack-plugin/commit/159256f02a16183e34b767ed0d7434502fe35851))
* bug fixes from PR ([99dce9f](https://github.com/Automattic/newspack-plugin/commit/99dce9fb9eac85557d0b7c6088d9092b831e755c))
* cleaner handling of redirectURI without using props ([37c8893](https://github.com/Automattic/newspack-plugin/commit/37c88936a6da32b847f1cac3746291f000ae7f90))
* eslint error ([b2852ed](https://github.com/Automattic/newspack-plugin/commit/b2852ed206916110f03e2f7bffb064d16d9746e2))
* eslint errors ([2558b34](https://github.com/Automattic/newspack-plugin/commit/2558b347a2e8c0f42433a43e298411392f6d429e))
* explicitly set admin user ID when creating Salesforce webhooks ([7bb5246](https://github.com/Automattic/newspack-plugin/commit/7bb52462db0e1ea84388852412199606d61353d9))
* make gam toggle work correctly ([7467c88](https://github.com/Automattic/newspack-plugin/commit/7467c88c2700b04b7723763db53b05cb00281350))
* missing close quote ([d373f27](https://github.com/Automattic/newspack-plugin/commit/d373f27724a3ede863c4dd680da11da934f99717))
* mop up a couple of remain instances of "lead" in text ([0340cc8](https://github.com/Automattic/newspack-plugin/commit/0340cc8eec2abdd8bf3dc15fa8b54dd0e320aa90))
* move getTokens call into lifecycle function instead of render ([22bddef](https://github.com/Automattic/newspack-plugin/commit/22bddefeb7eea5e464b6ce2de4b631fdb891e5b0))
* only create webhooks when SF is connected (and delete when reset) ([5ac347c](https://github.com/Automattic/newspack-plugin/commit/5ac347c351aa6d79148dba19459ec546833e102e))
* properly map WC fields to SF fields on webhook delivery ([0af5581](https://github.com/Automattic/newspack-plugin/commit/0af558132312fd80e49f784351fc4771e8f3d30a))
* remove extra translation wrapper ([85ed3ae](https://github.com/Automattic/newspack-plugin/commit/85ed3ae83597553f0bc9c56448ff82f4ffe19dfc))
* remove unused var to fix lint error ([118eff7](https://github.com/Automattic/newspack-plugin/commit/118eff7c0040bafc3a6cd4436fdffcab45085997))
* sync to Contact, not Lead, and format donation line items ([6970926](https://github.com/Automattic/newspack-plugin/commit/6970926e09aa9d3c5ce50fa2143f164675cdf0da))
* update help copy; await update promise to resolve before validating ([c4edac0](https://github.com/Automattic/newspack-plugin/commit/c4edac0325a7cbb04f639321d6d2044035ea2be5))


### Features

* add ActionCardSections component (from PopupGroup) ([#563](https://github.com/Automattic/newspack-plugin/issues/563)) ([883f22e](https://github.com/Automattic/newspack-plugin/commit/883f22e473995f943fdc15c05f05f9f5b8e0f5f4))
* add request handlers for updating/adding Leads via Salesforce API ([2f7ae6b](https://github.com/Automattic/newspack-plugin/commit/2f7ae6b95992f0e23ebe3bcc12e57b2fa96ef276))
* add some handling for invalid client id/secret and error responses ([a930f7c](https://github.com/Automattic/newspack-plugin/commit/a930f7c1f80f12ee7fc5c596a1e67b1a8c6f312f))
* check refresh token before assuming connection is valid ([a5976e6](https://github.com/Automattic/newspack-plugin/commit/a5976e6e1487b92a855b3f8f5074c59e85e3c14d))
* finish SalesForce OAuth flow in admin dashboard ([92e5805](https://github.com/Automattic/newspack-plugin/commit/92e58051160616bf372924fd688fdfba65c41b87))
* re-enable disabling of text field sonce Salesforce is connected ([d279790](https://github.com/Automattic/newspack-plugin/commit/d279790dcfc1c172d84b400c236b8009398e0372))
* start SalesForce admin UI ([a349b37](https://github.com/Automattic/newspack-plugin/commit/a349b375a7b681c29306740a06a48bc49a9c09e1))
* update SalesForce settings page, store settings as options ([2658022](https://github.com/Automattic/newspack-plugin/commit/26580229117eefbcd2d6536e27f13854361effba))

# [1.10.0](https://github.com/Automattic/newspack-plugin/compare/v1.9.0...v1.10.0) (2020-06-23)


### Features

* **analytics:** add non-AMP submit, ini-load events handling ([#558](https://github.com/Automattic/newspack-plugin/issues/558)) ([fd3edc4](https://github.com/Automattic/newspack-plugin/commit/fd3edc4633351af2735f21601e6e3f892a39adac))

# [1.9.0](https://github.com/Automattic/newspack-plugin/compare/v1.8.0...v1.9.0) (2020-06-18)


### Features

* **campaign-analytics:** better error handling ([900644b](https://github.com/Automattic/newspack-plugin/commit/900644b40eebf95d17fb9dfbb780102225b4ee9b))

# [1.8.0](https://github.com/Automattic/newspack-plugin/compare/v1.7.0...v1.8.0) (2020-06-09)


### Bug Fixes

* add non-interactive event handling ([c0763b1](https://github.com/Automattic/newspack-plugin/commit/c0763b1c3079771315ffe80d98dd97caa78150c0))
* determine non-interactive based on ga event type ([90655bb](https://github.com/Automattic/newspack-plugin/commit/90655bb9c2ab9c8e152324076b027292cda8c9eb))
* ie11 compatibility ([20eab91](https://github.com/Automattic/newspack-plugin/commit/20eab910ada47fc6d38ed4b575f32321731d2e4c))
* non-AMP events ([3a6981d](https://github.com/Automattic/newspack-plugin/commit/3a6981d6c88bf5d5eed60ba504a3e064c0ff66c7))
* remove short-circuit ([6cc0ed7](https://github.com/Automattic/newspack-plugin/commit/6cc0ed7807ebf01c99667e1f7bb5021042fbf8f5))
* scroll event reporting ([3b095b9](https://github.com/Automattic/newspack-plugin/commit/3b095b9e8eabb71ef1d782a95c27fcc271d82a27))


### Features

* add up Campaigns Analytics view ([#516](https://github.com/Automattic/newspack-plugin/issues/516)) ([dd0608d](https://github.com/Automattic/newspack-plugin/commit/dd0608d7d2f6a01c9e94cd75ee6fcd2fca842a1a))
* add value to NTG scroll events ([0a365d6](https://github.com/Automattic/newspack-plugin/commit/0a365d6f4b5d317514efb3d9523e5c78e0c9699c))
* analytics events framework ([4b876a4](https://github.com/Automattic/newspack-plugin/commit/4b876a495f97760f1f6e0a6c174ec2feea087c78))

# [1.7.0](https://github.com/Automattic/newspack-plugin/compare/v1.6.0...v1.7.0) (2020-06-04)


### Bug Fixes

* duplicate key ([f18c0ae](https://github.com/Automattic/newspack-plugin/commit/f18c0ae17a93f811102f853cffabbb2dbf291cd2))
* **support:** validate token before starting chat ([#520](https://github.com/Automattic/newspack-plugin/issues/520)) ([1112600](https://github.com/Automattic/newspack-plugin/commit/1112600549128e01b6314a82866efbe499c6f99e))
* handle empty title for campaigns ([3d4f6df](https://github.com/Automattic/newspack-plugin/commit/3d4f6df817e615e24beaf319854afa1c0ed333b6))
* plugin messages in wizard pluralization ([#518](https://github.com/Automattic/newspack-plugin/issues/518)) ([5661b0a](https://github.com/Automattic/newspack-plugin/commit/5661b0aec5d17ea41147fdf804eab940de553be1)), closes [#453](https://github.com/Automattic/newspack-plugin/issues/453)


### Features

* **support:** enable removing WPCOM token in settings; change auth scope to global ([#546](https://github.com/Automattic/newspack-plugin/issues/546)) ([1210967](https://github.com/Automattic/newspack-plugin/commit/1210967c1f49f3c91603b95e2c462986f044a550))
* **support:** list user's support tickets ([#534](https://github.com/Automattic/newspack-plugin/issues/534)) ([dca3497](https://github.com/Automattic/newspack-plugin/commit/dca34970c207d276f72d7b54293f678659b2e176))
* add small action card and plugin installer ([cb08ab0](https://github.com/Automattic/newspack-plugin/commit/cb08ab0c95570cb2ab793be03267481266bea743))

# [1.6.0](https://github.com/Automattic/newspack-plugin/compare/v1.5.0...v1.6.0) (2020-05-06)


### Bug Fixes

* previewing for inline popups in wizard ([e909372](https://github.com/Automattic/newspack-plugin/commit/e909372d2014e636112faf7241a7c5f2566f8fe4))
* remove pop-ups ui from engagement wizard ([#517](https://github.com/Automattic/newspack-plugin/issues/517)) ([68367b5](https://github.com/Automattic/newspack-plugin/commit/68367b585f9fecab96dc4adec1f82d65b4991ab9))


### Features

* pop-ups wizard ([ae033d0](https://github.com/Automattic/newspack-plugin/commit/ae033d0d0a6a5ebd61abdef1fb70e702bf6abe81))
* popup wizard ui for draft popups ([8095ba4](https://github.com/Automattic/newspack-plugin/commit/8095ba4b32f01cff13768e57e1a2e4b38ebffe92))
* whitelist newspack newsletters plugin ([f1d1ff6](https://github.com/Automattic/newspack-plugin/commit/f1d1ff681855bf1d145275bb529599e9b2fd9203))

# [1.5.0](https://github.com/Automattic/newspack-plugin/compare/v1.4.0...v1.5.0) (2020-04-21)


### Bug Fixes

* admin color scheme conflict with newspack buttons ([#503](https://github.com/Automattic/newspack-plugin/issues/503)) ([c248078](https://github.com/Automattic/newspack-plugin/commit/c248078899a3f4f1c3bf5c829ecde9197aefd07d))


### Features

* **components:**  change mobile preset width of WebPreview ([#509](https://github.com/Automattic/newspack-plugin/issues/509)) ([29e0877](https://github.com/Automattic/newspack-plugin/commit/29e08770120fdf2fa9ad5ed9cd739ae1d2d70dd0))

# [1.4.0](https://github.com/Automattic/newspack-plugin/compare/v1.3.0...v1.4.0) (2020-04-01)


### Features

* add grid view option to the dashboard ([#496](https://github.com/Automattic/newspack-plugin/issues/496)) ([03b0646](https://github.com/Automattic/newspack-plugin/commit/03b06467a7c3d5a7e503c232b1a3dff749c2d6aa))

# [1.3.0](https://github.com/Automattic/newspack-plugin/compare/v1.2.1...v1.3.0) (2020-03-24)


### Features

* **health-check:** expose Health Check to external PHP code ([62cc8e0](https://github.com/Automattic/newspack-plugin/commit/62cc8e0d77ccb42c9d4a63d8cd082a0f1e2afa67))
* **starter content:** parallelise post creation; tweaks for handling E2E ([#489](https://github.com/Automattic/newspack-plugin/issues/489)) ([62974b2](https://github.com/Automattic/newspack-plugin/commit/62974b2090d5ef8fdcddc500ae5b1505ddaa8efc))

## [1.2.1](https://github.com/Automattic/newspack-plugin/compare/v1.2.0...v1.2.1) (2020-03-20)


### Bug Fixes

* **handoff:** enqueue shared JS & CSS in handoff ([#492](https://github.com/Automattic/newspack-plugin/issues/492)) ([a7b8b8f](https://github.com/Automattic/newspack-plugin/commit/a7b8b8f3d5105ea772abe1def0e67d93a7a26ea3))
* **wizards:** broken REST URL in SEO wizard ([21779bd](https://github.com/Automattic/newspack-plugin/commit/21779bd57d20f85ed0eb6af4c25f7e67a25b19ce))

# [1.2.0](https://github.com/Automattic/newspack-plugin/compare/v1.1.0...v1.2.0) (2020-03-17)


### Bug Fixes

* button box-shadow since Gutenberg 7.7 ([#483](https://github.com/Automattic/newspack-plugin/issues/483)) ([0b4e70a](https://github.com/Automattic/newspack-plugin/commit/0b4e70ab71c14c7dd2eb94106f63931b8aad93f6))
* prevent errors if ads plugin out of date with this plugin ([f4943f0](https://github.com/Automattic/newspack-plugin/commit/f4943f07a0976380ca1a142c9aabd8f1c272b3c3))
* use https in author/plugin uri ([fbf7088](https://github.com/Automattic/newspack-plugin/commit/fbf7088e21eb1749d3ebc448c654da7bf608a336))


### Features

* ability to suppress ads on posts and pages ([86b08c2](https://github.com/Automattic/newspack-plugin/commit/86b08c20631e8368ea8f2d589dbd26b38a38f5e1))
* non-random starter content for e2e testing ([b35e749](https://github.com/Automattic/newspack-plugin/commit/b35e749746f05b972c21ff2f2dada04ce5746df9))


### Performance Improvements

* reduce deliverable zip size by 70% ([#481](https://github.com/Automattic/newspack-plugin/issues/481)) ([6a10fb5](https://github.com/Automattic/newspack-plugin/commit/6a10fb55106053265598fa4495ce715a91562d3a))

# [1.1.0](https://github.com/Automattic/newspack-plugin/compare/v1.0.0...v1.1.0) (2020-03-10)


### Bug Fixes

* fix Site Kit module deactivation ([96787e9](https://github.com/Automattic/newspack-plugin/commit/96787e971ef08ce3a6df1cdd715a20d804bb317b))
* standardize REST API namespace usage ([44d15b2](https://github.com/Automattic/newspack-plugin/commit/44d15b20fd113277cd43a9113aabbea963b0c696))


### Features

* **support:** add a link to support docs ([#470](https://github.com/Automattic/newspack-plugin/issues/470)) ([af4754d](https://github.com/Automattic/newspack-plugin/commit/af4754d1856294c73cad429d33c686aac756c7c8))
* **support:** send initial info on chat start ([#471](https://github.com/Automattic/newspack-plugin/issues/471)) ([1392452](https://github.com/Automattic/newspack-plugin/commit/13924525f889de4509f0501e39dd2fec6703e2ba))
* **support:** update subject string for support tickets ([aca9963](https://github.com/Automattic/newspack-plugin/commit/aca9963a778855ee7a9d656605958ac7255450ab))

# 1.0.0 (2020-03-03)


### Bug Fixes

* action card right region text align ([168c64d](https://github.com/Automattic/newspack-plugin/commit/168c64da8c618b33dd1bc950ec596109b48fc364))
* Better population of countries for ones with no states defined ([314d6a2](https://github.com/Automattic/newspack-plugin/commit/314d6a20016a1c5429ceeae3483b67d45adb7f5e))
* linting and formatting js ([#383](https://github.com/Automattic/newspack-plugin/issues/383)) ([b4828c8](https://github.com/Automattic/newspack-plugin/commit/b4828c83a625fd5fe011bb55097b7587714f453c))
* more reliable Site Kit connection check ([05a66f2](https://github.com/Automattic/newspack-plugin/commit/05a66f2269e4fc33c8e43842bcf43604f7ed9869))
* move author bio length setting inside togglegroup ([a374d96](https://github.com/Automattic/newspack-plugin/commit/a374d962f9c59267e5bf601d10c0e0381e60480d))
* newspack logo in starter content ([#465](https://github.com/Automattic/newspack-plugin/issues/465)) ([ace948e](https://github.com/Automattic/newspack-plugin/commit/ace948e56ca4c91026a6edfdd365bdb11a547561))
* npm dev script ([4ec6fcb](https://github.com/Automattic/newspack-plugin/commit/4ec6fcbc5958c9ff53d475e5a472a7f74e128c45))
* plugins-screen script and stylesheet paths ([#445](https://github.com/Automattic/newspack-plugin/issues/445)) ([fb98e3a](https://github.com/Automattic/newspack-plugin/commit/fb98e3a7f6c35b4b28029182e7c2ef9d32c2308f))
* remove calc() style for column blocks in starter content homepage. fixes [#393](https://github.com/Automattic/newspack-plugin/issues/393). ([#394](https://github.com/Automattic/newspack-plugin/issues/394)) ([71a7bd7](https://github.com/Automattic/newspack-plugin/commit/71a7bd733621e5bfb4ca252d0ae1210908a7cebe))
* store support token per-user instead of globally ([2b4af08](https://github.com/Automattic/newspack-plugin/commit/2b4af08d6ad8b4803a11f2557f869454b7dbeace))
* update Site Kit connection check for latest SK version ([6ffd884](https://github.com/Automattic/newspack-plugin/commit/6ffd884e9e8bccfef66353315b8e6c31de5a698d))
* when preview is waiting vertically align items to the center ([0f2c48a](https://github.com/Automattic/newspack-plugin/commit/0f2c48ae371c865ad6eb980e0cded3859401654c))
* wpcom  endpoints for stripe activity ([#458](https://github.com/Automattic/newspack-plugin/issues/458)) ([7230ac5](https://github.com/Automattic/newspack-plugin/commit/7230ac5c6053c903b0836f309ca5ee6365142123))


### Features

* adjust happychat style ([a6d501c](https://github.com/Automattic/newspack-plugin/commit/a6d501c4c5a5b5745498ae6492a0bd29b41c00d0))
* **support:** add Happychat ([f483f84](https://github.com/Automattic/newspack-plugin/commit/f483f8409eaac6379bca8bafabeee08a6568cc6c))
* Add MC4WP to supported plugins. ([68b1476](https://github.com/Automattic/newspack-plugin/commit/68b1476e407518e484deacc7a8373e991c2a0243))
* add support screen ([#451](https://github.com/Automattic/newspack-plugin/issues/451)) ([c76cb72](https://github.com/Automattic/newspack-plugin/commit/c76cb727bea7bea4f75843941f5e1b7d298f8fef))
* move proxied-imports ([d6973e3](https://github.com/Automattic/newspack-plugin/commit/d6973e3de97bf00055048aa9a1a12c3165656bc6))
* remove tabbed navigation on Donations/Memberships screens, add back buttons. ([20678b7](https://github.com/Automattic/newspack-plugin/commit/20678b77fc9778330e7fa3915ada97808a345b75))
* whitelist plugin - Password Protected. ([#398](https://github.com/Automattic/newspack-plugin/issues/398)) ([71febf4](https://github.com/Automattic/newspack-plugin/commit/71febf432db13aafad20556b9e4e1c0eadb6c361))


### Reverts

* Revert "Handoff Banner: Update style to visually match the rest of the plugin" ([3b9243c](https://github.com/Automattic/newspack-plugin/commit/3b9243cf3e95180d2ab0b157be1685ad3c06a992))
