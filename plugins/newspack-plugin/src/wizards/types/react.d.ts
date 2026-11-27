/**
 * React module augmentation. A module (not a global script like the sibling
 * index.d.ts) because augmenting a real module requires module context.
 */
import 'react';

declare module 'react' {
	// The Newspack Grid positions its children with `start` and `end` DOM
	// attributes, which its CSS selects on (`> [start="2"]`). React's typings
	// only know `start` on `<ol>`, so every typed caller would otherwise have
	// to cast.
	interface HTMLAttributes< T > {
		start?: number;
		end?: number;
		// The banner previews mark their container `inert` to freeze it; @types/react
		// 18 has no `inert` attribute, and the markup writes the string form.
		inert?: string | boolean;
	}
}
