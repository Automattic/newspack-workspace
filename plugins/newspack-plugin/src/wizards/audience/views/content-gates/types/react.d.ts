/**
 * React module augmentation. This file is intentionally a module (unlike the
 * sibling index.d.ts, which must stay a global script) because augmenting a
 * real module requires module context.
 */
import 'react';

declare module 'react' {
	// React 18 type definitions don't know the `inert` DOM attribute (it was
	// only added to the React 19 typings, as a boolean). The React 18 runtime
	// requires the string form (`inert="true"`), which is what the gate
	// previews render.
	interface HTMLAttributes< T > {
		inert?: string;
	}
}
