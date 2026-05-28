// Remove the Border style that is coming from Newspack Blocks.
wp.domReady( () => {
	wp.blocks.unregisterBlockStyle( 'core/group', 'border' );
} );
