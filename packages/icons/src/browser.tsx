/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const browser = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M5 3.75H19A1.25 1.25 0 0 1 20.25 5V19A1.25 1.25 0 0 1 19 20.25H5A1.25 1.25 0 0 1 3.75 19V5A1.25 1.25 0 0 1 5 3.75ZM8 10.5H16M8 13.5H16M8 16.5H12.5" />
		<Path
			fill="currentColor"
			stroke="none"
			d="M4 4H20V7.5H4ZM5.25 4.5C4.83579 4.5 4.5 4.83579 4.5 5.25C4.5 5.66421 4.83579 6 5.25 6C5.66421 6 6 5.66421 6 5.25C6 4.83579 5.66421 4.5 5.25 4.5ZM7.5 4.5C7.08579 4.5 6.75 4.83579 6.75 5.25C6.75 5.66421 7.08579 6 7.5 6C7.91421 6 8.25 5.66421 8.25 5.25C8.25 4.83579 7.91421 4.5 7.5 4.5ZM9.75 4.5C9.33579 4.5 9 4.83579 9 5.25C9 5.66421 9.33579 6 9.75 6C10.1642 6 10.5 5.66421 10.5 5.25C10.5 4.83579 10.1642 4.5 9.75 4.5Z"
		/>
	</SVG>
);

export default browser;
