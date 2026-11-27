/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const playlist = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4.625 3.75H16.375A0.875 0.875 0 0 1 17.25 4.625V16.375A0.875 0.875 0 0 1 16.375 17.25H4.625A0.875 0.875 0 0 1 3.75 16.375V4.625A0.875 0.875 0 0 1 4.625 3.75ZM6 21H19A2 2 0 0 0 21 19V8" />
		<Path fill="currentColor" stroke="none" d="M8.833 8L13 10.5L8.833 13V8Z" />
	</SVG>
);

export default playlist;
