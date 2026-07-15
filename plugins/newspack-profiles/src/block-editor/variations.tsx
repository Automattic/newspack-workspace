import { registerBlockVariation } from '@wordpress/blocks';
import { _x } from '@wordpress/i18n';
import { SVG, Path } from '@wordpress/primitives';

registerBlockVariation( 'core/social-link', {
	name: 'phone',
	title: _x(
		'Phone',
		'Social Link block variation title',
		'newspack-profiles'
	),
	icon: (
		<SVG
			width="24"
			height="24"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false"
		>
			<Path d="M9.1 5.0C8.9 4.5 8.2 4.2 7.7 4.4L7.5 4.4C5.6 5.0 3.9 6.8 4.4 9.1C5.5 14.3 9.7 18.5 14.9 19.6C17.2 20.1 19.0 18.4 19.6 16.5L19.6 16.3C19.8 15.8 19.5 15.1 19.0 14.9L16.0 13.7C15.5 13.4 14.9 13.6 14.6 14.0L13.4 15.4C11.4 14.4 9.6 12.6 8.6 10.5L10.0 9.4C10.4 9.0 10.6 8.5 10.3 8.0L9.1 5.0z" />
		</SVG>
	),
	attributes: {
		service: 'phone',
	},
	isActive: [ 'service' ],
} );
