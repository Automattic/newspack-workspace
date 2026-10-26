export const LAYOUT_CPT_SLUG = 'newspack_nl_layo_cpt';
export const NEWSLETTER_CPT_SLUG = 'newspack_nl_cpt';
export const NEWSLETTER_AD_CPT_SLUG = 'newspack_nl_ads_cpt';
export const BLANK_LAYOUT_ID = 0;

// The post-publish "sent"/"published" success notice. Created in the editor and
// preserved across saves by this id, so the logic doesn't depend on its wording.
export const CAMPAIGN_SENT_NOTICE_ID = 'newspack-newsletters-campaign-sent-notice';

// Matches the iframe inside a core `BlockPreview` by its wrapper class. The
// iframe's own title is translated, so selecting by it fails on non-English admins.
export const BLOCK_PREVIEW_IFRAME_SELECTOR = '.block-editor-block-preview__content iframe';
