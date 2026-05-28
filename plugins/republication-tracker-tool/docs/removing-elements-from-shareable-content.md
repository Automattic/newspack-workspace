# Removing HTML Elements from the Shareable Content

If you'd like to remove specific HTML elements from the shareable content widget, you can use the `republication_tracker_tool_allowed_tags_excerpt` filter. The array returned through this filter is used to run the post content through the <a href="https://codex.wordpress.org/Function_Reference/wp_kses" target="_blank">`wp_kses`</a> function.

Here's an example of how to remove `video` and `button` elements from the shareable content:

```
/**
* Remove video and button elements from the shareable content
*
* @return Array $allowed_tags_excerpt The array of tags to allow in the shareable content
**/
function remove_elements_from_shareable_content( $allowed_tags_excerpt, $post ){

    unset( $allowed_tags_excerpt['video'] );
    unset( $allowed_tags_excerpt['button'] );

    return $allowed_tags_excerpt;

}
add_filter( 'republication_tracker_tool_allowed_tags_excerpt', 'remove_elements_from_shareable_content', 10, 2 );
```