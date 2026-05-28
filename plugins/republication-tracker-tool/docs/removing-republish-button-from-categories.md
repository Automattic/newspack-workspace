# Removing the Republish Button From Specific Categories

If you'd like to remove the Republish button from specific categories, the plugin includes a filter that will help you do this programatically. 

The `hide_republication_widget` filter allows you to target specific posts/categories/tags/whatever you want to hide. All you have to do is check if the current post exists in the specific category, and tell the filter to return `true` if you'd like the button to be hidden.

Here's an example of how to hide the Republish button on all posts in the category with an ID of 14 or 15.

```
/**
* Hide the Republication sharing widget on posts that are
* included in the category with the ID of 14 or 15.
*
* @return bool Whether or not the sharing widget should be hidden
*/
function remove_republish_button_from_category( $hide_republication_widget, $post ){

    if( true !== $hide_republication_widget ){

        // if the current post is in either of these categories, return true
        if( in_category( array( 14, 15 ), $post->ID ) ){
            
            // returning true will cause the filter to hide the button
            $hide_republication_widget = true;

        }
    
    }

    return $hide_republication_widget;

}
add_filter( 'hide_republication_widget', 'remove_republish_button_from_category', 10, 2 );
```
