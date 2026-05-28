/**
 * WordPress dependencies
 */
import { Path, SVG } from '@wordpress/components';

const List = ( { size = 24 } ) => (
	<SVG xmlns="http://www.w3.org/2000/svg" width={ size } height={ size } viewBox="0 0 24 24">
		<Path d="M5.5 7.5h2v2h-2v-2zM7.5 11.5h-2v2h2v-2zM8.5 7.5h7v2h-7v-2zM15.5 11.5h-7v2h7v-2z" />
		<Path
			clipRule="evenodd"
			d="M4.625 3C3.728 3 3 3.728 3 4.625v11.75C3 17.273 3.728 18 4.625 18h11.75c.898 0 1.625-.727 1.625-1.625V4.625C18 3.728 17.273 3 16.375 3H4.625zm11.75 1.5H4.625a.125.125 0 00-.125.125v11.75c0 .069.056.125.125.125h11.75a.125.125 0 00.125-.125V4.625a.125.125 0 00-.125-.125z"
			fillRule="evenodd"
		/>
		<Path d="M21.75 8h-1.5v11c0 .69-.56 1.25-1.249 1.25H6v1.5h13.001A2.749 2.749 0 0021.75 19V8z" />
	</SVG>
);

export default List;
