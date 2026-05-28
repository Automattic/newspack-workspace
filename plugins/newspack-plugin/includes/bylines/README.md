# Custom Bylines

This feature allows authors to add custom bylines to posts.

## How it works

When you enable this feature, a new settings panel will be added to the post editor sidebar. You can use this panel to add a custom byline.

Note that the custom bylines does not interfere with the post's authors. Removing a name from the byline does not remove that author from the post. This means that the author's bio might still show up at the bottom of the post and that the post will still show up in that author's archive. This is intentional.

## Data

Bylines are stored as post meta and consists of the following fields:

| Name                          | Type      | Stored As   | Description                                                                                                           |
| ----------------------------- | --------- | ----------- | --------------------------------------------------------------------------------------------------------------------- |
| `_newspack_byline_active`     | `boolean` | `post_meta` | Whether custom byline is active for the post                                                                          |
| `_newspack_byline`            | `string`  | `post_meta` | The custom byline. Author links can be included by wrapping text in the Author tag (`by [Author id=5]Jane Doe[/Author] and Eric Doe`) |

Obs: The author display name is also dynamically fetched based on the author ID. The display name stored in the DB is just a fallback for when an author goes missing.
