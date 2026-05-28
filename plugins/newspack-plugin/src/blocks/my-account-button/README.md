# My Account Button block

Provides a reader account/sign-in button for sites using Newspack Reader Activation. The button label and link update based on the reader’s authentication state.

## Settings
- Signed in label (`signedInLabel`): Text shown when the reader is authenticated.
- Signed out label (`signedOutLabel`): Text shown when the reader is not authenticated.

### Display
The button also has the ability to toggle on/off the display of the icon or text label. You cannot toggle off both the icon and label at the same time.

When only the icon is visible, screenreaders will still be able to read the label, even if it's not visible. If this hidden label needs to be edited, it will need to be toggled back on in the editor, changed, and then hidden again.

## Editor behavior
- Use the toolbar toggle (Signed in / Signed out) to edit each label.

## Front-end behavior
- If Reader Activation is disabled, the block renders nothing.
- If the user is signed in, the button links to the My Account page (WooCommerce).
- If the user is signed out, the button uses `data-newspack-reader-account-link` to open the Reader Activation modal.

## Notes
- Can be used alone or inside of `core/navigation` blocks.

