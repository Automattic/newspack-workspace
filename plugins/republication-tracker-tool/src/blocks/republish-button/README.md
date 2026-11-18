# Republish Button block

A button (`republication-tracker-tool/republish-button`) that opens the republish modal, where readers copy a post's content and tracking pixel to republish it under the site's Creative Commons license. Works on both classic and block themes.

## Settings
- Button text (`buttonText`): Label shown on the button. Defaults to "Republish This Story". If left empty, the front end falls back to the translated default.
- Show license badge (`showLicense`): Displays the site's Creative Commons license badge below the button, linked to the license. On by default.

The license itself is a site-wide setting (`republication_tracker_tool_license`), not a block setting.

### Display
Standard button block supports are available: text/background color, typography, padding, border, shadow, and HTML anchor. They apply to the button element, which uses the same markup as a `core/button` inside `core/buttons`, so theme button styles carry over.

## Editor behavior
- Edit the label inline on the button. Formatting is not allowed.
- The license badge preview shows the site's license setting as it was when the editor loaded; a change to the setting shows after reloading the editor. If no recognized license is set, the preview shows a notice instead, and the front end renders no badge.

## Front-end behavior
- The block renders only on a single post view, for a post that can be republished. That means the post is public and not password-protected, its post type is in the `republication_tracker_tool_post_types` filter (`post` by default), and republishing isn't turned off for it, either with the "Hide Republication widget" toggle in the post sidebar (which hides this block too) or the `hide_republication_widget` filter.
- Clicking the button opens the republish modal. Visiting a post with `#show-republish` in the URL opens it on load.
- The modal is rendered once per page, even with several Republish Button blocks or a Republish widget on the same page. Every button opens the same modal.
- The modal uses Newspack UI when newspack-plugin provides it, and the plugin's own markup and styles otherwise. The `republication_tracker_tool_use_newspack_ui` filter overrides the choice.
- On AMP pages the button still shows, but it doesn't open the modal.

## Notes
- Dynamic block: `save` returns `null`, and markup comes from `Republication_Tracker_Tool_Republish_Button_Block::render_block()`.
- Registration reads `block.json` from `dist/`, because `src/` isn't included in the released plugin. On an unbuilt checkout the block doesn't exist; run `n build republication-tracker-tool`.
- If you add `supports` to the registration args, deep-merge it with the `block.json` values, or existing blocks lose their saved styles.
- The "Republish Section" pattern (`republication-tracker-tool/republish-section`) wraps this block in a group with a short description paragraph.
- This block's editor script also adds a "Republish" variation of Jetpack's Sharing Button block (`sharing-button.js`), which opens the same modal.
