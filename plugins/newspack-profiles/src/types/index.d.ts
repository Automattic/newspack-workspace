declare module '*.webp' {
	const src: string;
	export default src;
}

declare module '*.scss' {
	const content: Record<string, string>;
	export default content;
}

declare module '@wordpress/block-editor';
declare module '@wordpress/block-library';
declare module '@wordpress/edit-site';
