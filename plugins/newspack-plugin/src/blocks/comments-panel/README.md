# Comments Panel block

A button that opens a side panel holding the post's comments and comment form, so readers can read and reply without leaving their place in the article. Intended for single post templates. Block theme only.

The block (`newspack/comments-panel`) always holds two locked child blocks: a **Comments Button** (`newspack/comments-panel-trigger`) and a **Comments Panel Content** block (`newspack/comments-panel-content`) that wraps a `core/comments` block.

## Settings

### Comments Button
- Button text (`triggerText`): Label shown on the button. When left empty, the editor shows a translated "Comments" placeholder and the front end renders the translated "Comments".
- Width (`width`): 25%, 50%, 75%, or 100% of the block's width, under **Settings → Width**. Uses the same classes as the core Button block's width setting.

The button has three styles: **Default** (icon + label), **Icon only**, and **Text only**. When only the icon is visible, the label is still available to screen readers via a `screen-reader-text` span.

Standard button block supports are available too: text/background color, typography, padding, and border (color, style, width, and radius).

### Comments Panel Content
- Overlay color (`overlayColor`): Color of the backdrop behind the open panel. Supports custom colors with transparency (alpha).

The panel's comment layout is editable: it starts with the comment form, comments title, comment template, and pagination, and those inner blocks can be rearranged or restyled.

## Editor behavior
- A toolbar toggle on the first Comments Panel on the page opens the panel in the editor so its contents can be styled. The preview state isn't saved.
- Edit the button label inline. The comments icon is fixed and not configurable.

## Front-end behavior
- Clicking a Comments Button opens the panel from the right. The panel is a focus-trapped dialog: Escape, the close button, or clicking the backdrop closes it, and focus returns to the button.
- Pagination links and the comment form work inside the panel without a page reload. After posting, the panel stays open and moves to the new comment.
- The panel opens on page load when the URL points at comments: a comment link (`#comment-N`, `#comments`, `#respond`) or a paginated comments view.

## Notes
- **One panel per page.** Only the first Comments Panel Content block renders a panel (`#newspack-comments-panel`). Every Comments Button on the page opens that same panel, so the block can appear more than once (for example, near the title and after the content) without duplicating the comments.
- **Not for Query Loops.** Because only one panel renders per page, the block can't give each post in a Query Loop its own comments. Every button would open the panel for the first post in the loop.
- Block theme only. Registration is gated on `wp_is_block_theme()` (PHP) and `newspack_blocks.is_block_theme` (JS).
- On open, the panel is moved to `document.body` to avoid stacking-context issues, so its styles are written at root scope rather than nested under the block wrapper, the same approach as the [Overlay Menu block](../overlay-menu/README.md).
- The block theme places it in the `post-footer` template part.
