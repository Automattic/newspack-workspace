/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

const postAvatar = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style={ { fill: 'none' } } stroke="currentColor" strokeWidth="1.5">
		<Path d="M4 15.25H20M4 19.25H13M13.25 7A4.25 4.25 0 1 1 4.75 7A4.25 4.25 0 1 1 13.25 7ZM6.341 10.537C6.617 9.578 7.186 9.001 8 9.001H10C10.814 9.001 11.383 9.578 11.659 10.537" />
		<Path
			fill="currentColor"
			stroke="none"
			d="M9 7.5C9.39782 7.5 9.77936 7.34196 10.0607 7.06066C10.342 6.77936 10.5 6.39782 10.5 6C10.5 5.60218 10.342 5.22064 10.0607 4.93934C9.77936 4.65804 9.39782 4.5 9 4.5C8.60218 4.5 8.22064 4.65804 7.93934 4.93934C7.65804 5.22064 7.5 5.60218 7.5 6C7.5 6.39782 7.65804 6.77936 7.93934 7.06066C8.22064 7.34196 8.60218 7.5 9 7.5Z"
		/>
	</SVG>
);

export default postAvatar;
