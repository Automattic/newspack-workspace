/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const tabItem = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M5 3.75H19A1.25 1.25 0 0 1 20.25 5V19A1.25 1.25 0 0 1 19 20.25H5A1.25 1.25 0 0 1 3.75 19V5A1.25 1.25 0 0 1 5 3.75ZM9.25 3.75V10M8.5 9.25H20.25M14.75 3.75V9.25" />
	</SVG>
);

export default tabItem;
