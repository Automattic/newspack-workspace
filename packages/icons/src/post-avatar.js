/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const postAvatar = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4 15H20M4 19H13M6 10C6.75 9.25 7.75 9 9 9C10.25 9 11.25 9.25 12 10M13.25 7C13.25 9.34721 11.3472 11.25 9 11.25C6.65279 11.25 4.75 9.34721 4.75 7C4.75 4.65279 6.65279 2.75 9 2.75C11.3472 2.75 13.25 4.65279 13.25 7Z" />
		<Path d="M9 7.5C9.82843 7.5 10.5 6.82843 10.5 6C10.5 5.17157 9.82843 4.5 9 4.5C8.17157 4.5 7.5 5.17157 7.5 6C7.5 6.82843 8.17157 7.5 9 7.5Z" fill="currentColor" stroke="none" />
	</SVG>
);

export default postAvatar;
