## How ads are inserted by default.

1. You activate the plugin.
2. You configure the plugin.
3. The plugin registers a number of sidebars equal to the number of times that ads should be inserted into the post.
4. Users add widgets to those sidebars
5. When `the_content()` is called on a post:
    1. A filter on `the_content` with priority `5` checks to see if there are any SCAIP blocks in the post, and if so, removes step #3 below.
    2. Gutenberg (if enabled) converts SCAIP blocks into HTML content, passing the block arguments to the function `scaip_shortcode` which runs the `scaip_shortcode` action. On the `scaip_shortcode` action is hooked a function that outputs the sidebar for that block.
    3. A filter on `the_content` with priority `10` checks to see if there are any SCAIP shortcodes in the post, and if so, skips step #4 below.
    4. The filter from step #3 above then automatically inserts the plugin's shortcodes.
    5. WordPress converts shortcodes into HTML content, passing the shortcode arguments to the function `scaip_shortcode`, described above.


## Customization!

### Manually place shortcodes in a post

If you want to manually place SCAIP widget areas in a post, you can do so using the `[ad]` shortcode.

For example, to display the "Inserted Ad Position 2" in a custom location in your post, you would use the following shortcode:

    [ad number="2"]

Using this method, you can reorder inserted widget areas or manually limit the number of inserted ad positions on a per-post basis without having to define a callback to determine what gets placed in the widget area.

A shortcode without a number specified will be ignored, as will a shortcode with a number higher than the plugin's settings allow.

### Define your own behavior.

Of course, if you need to do something that is more complex than place a widget or SCAIP-inserted widget area, you can write your own hooks on the SCAIP shortcode.

1. You write a function that accepts a PHP array of arguments and `echo`s some HTML to the page.
2. You attach that function to the `scaip_shortcode` WordPress action:

```php
function scaip_test_function( $args ) {
	echo "<p>SCAIP was here, with these arguments: ";
	echo var_dump( $args );
	echo "</p>";
}
add_action( 'scaip_shortcode', 'scaip_test_function' );
remove_action( 'scaip_shortcode', 'scaip_shortcode_do_sidebar', 10 );
```

The remove_action call is necessary to prevent the default sidebar output from occurring.

### Disable the normal shortcodes

If you wish to disable the shortcode that is inserted normally, in order to replace it with your own work, write a hook that hooks the shortcode ahead of the default scaip function (so, with priority number 9 or lower) that performs your desired output and then uses [`remove_action( 'scaip_shortcode', 'scaip_shortcode_do_sidebar', 10 )`](https://codex.wordpress.org/Function_Reference/remove_action) to remove the default SCAIP shortcode handler.

If you wish to selectively disable programmatic shortcode insertion on a particular post, category of post, or other criteria, you can write a filter on `'scaip_whether_insert'`, accepting three parameters and returning `true` or `false`. The three parameters are:

1. Bool $whether Whether to insert ads programmatically in this instance.
2. String $content The post content.
3. Mixed $queried_object `$wp_query->queried_object` in the context in which the programmatic shortcode inserter is running.

An example filter would look like:

```php
function scaip_test_inserter_disabler( $whether, $content, $queried_object ) {
	if ( isset( $queried_object->ID ) && 120 == $queried_object->ID ) {
		return false;
	}

	return $whether;
}
add_filter( 'scaip_whether_insert', 'scaip_test_inserter_disabler', 10, 3 );
```

### Custom sidebar inserters

First, disable the normal inserter as described above.

Then, create a function that inserts the `[ad number=$n]` shortcode in your `post_content` where you want it to appears, where `n` is an integer between `1` and the value of `get_option( 'scaip_settings_repetitions' )`.

### Insert shortcodes in new and unusual places

A simple three-step process:

1. Identify the HTML content where you want the SCAIP widget areas to be inserted.
2. Via a filter or other function, pass that HTML as the first parameter of the function `scaip_insert_shortcode( $content );`.
3. That function will return the HTML string.

For more information on the `scaip_insert_shortcode()` function, see [`inc/scaip-shortcode-inserter.php`](/inc/scaip-shortcode-inserter.php).

As an example of a place where you might want to use the shortcode insertion function, consider that the default inserter does not insert the shortcode for the sidebar on the front page or on term archive pages.

### Change shortcode sidebar HTML

When using [`register_sidebar()`](https://developer.wordpress.org/reference/functions/register_sidebar/), one normally passes an array `$args` of arguments which define the HTML markup of the sidebar:

```php
register_sidebar(array(
	'name' => 'Example',
	'description' => __( 'Example sidebar description', 'text-domain' ),
	'id' => 'example',
	'before_widget' => '<aside id="%1$s" class="%2$s clearfix">',
	'after_widget' => '</aside>',
	'before_title' => '<h5 class="adtitle">',
	'after_title' => '</h5>',
));
```

Because SCAIP handles the sidebar registration for you, you cannot directly manipulate the sidebar's `before_widget`, `after_widget`, `before_title`, or `after_title` HTML. To change that HTML, use the following filters:

- `scaip_before_widget`
- `scaip_after_widget`
- `scaip_before_title`
- `scaip_after_title`

Callbacks hooked on these filters should accept two arguments:

1. The HTML for the filtered tag
2. A number `$i` which is the Inserted Ad Position sidebar number. This parameter is optional, but you may find it useful if you want to set the `before_widget` and `after_widget` differently for sidebar 1 compared to sidebar 3.

If you put a filter on a "before" filter, **check whether you need to filter the "after" filter.** The following case outputs unbalanced HTML:

```php
function scaip_example_filter( $html, $i ) {
	if ( isset( $i ) && 4 === $i ) {
		$html = '<div id="%1$s" class="%2$s">';
	}
	return $html;
}
```

That filter will produce the following output on the page, when a Text Widget is used as an example:

```html
<div id="text-7" class="widget_text clearfix">
	<h5 class="adtitle">Text Widget, Sidebar 4</h5>
	<div class="textwidget">
		<p>An example text widget in the fourth sidebar</p>
	</div>
</aside>
```

Do you see the mismatch between the opening `div` tag and the closing `aside`? Many browsers will silently correct this, but some will throw errors, and your search engine ratings may be negatively affected.
