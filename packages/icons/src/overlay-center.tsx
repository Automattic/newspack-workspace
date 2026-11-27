/**
 * WordPress dependencies
 */
import { SVG, Path, Rect } from '@wordpress/primitives';

const overlayCenter = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M6 19.25H18C18.6904 19.25 19.25 18.6904 19.25 18V6C19.25 5.30964 18.6904 4.75 18 4.75H6C5.30964 4.75 4.75 5.30964 4.75 6V18C4.75 18.6904 5.30964 19.25 6 19.25Z" />
		<Rect x="8" y="10" width="8" height="4" fill="currentColor" stroke="none" />
	</SVG>
);

export default overlayCenter;
