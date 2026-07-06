// Add styles to display the post terms as buttons or links.
wp.domReady( () => {
	wp.blocks.registerBlockStyle( 'core/post-terms', {
		name: 'buttons',
		label: 'Buttons',
	} );

	wp.blocks.registerBlockStyle( 'core/post-terms', {
		name: 'links',
		label: 'Links',
	} );
} );
