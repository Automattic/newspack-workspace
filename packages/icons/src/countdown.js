/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const countdown = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M3.75 7C3.75 6.30964 4.30964 5.75 5 5.75H19C19.6904 5.75 20.25 6.30964 20.25 7V17C20.25 17.6904 19.6904 18.25 19 18.25H5C4.30964 18.25 3.75 17.6904 3.75 17V7Z" />
		<Path d="M6.75 10.5L8.25 9.5V14.5M12 9.25L10.5 14.75M14.25 14.5V9.5H14.75L16.75 14.5H17.25V9.5" />
	</SVG>
);

export default countdown;
