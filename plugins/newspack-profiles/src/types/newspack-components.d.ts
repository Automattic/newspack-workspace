declare module 'newspack-components/dist/esm/badge' {
	import type { ComponentType } from 'react';

	const Badge: ComponentType< {
		text: string;
		level?: 'default' | 'info' | 'warning' | 'error' | 'success';
		className?: string;
	} >;

	export default Badge;
}

declare module 'newspack-components/dist/esm/newspack-icon' {
	import type { ComponentType } from 'react';

	const NewspackIcon: ComponentType< {
		size?: number;
		className?: string;
	} >;

	export default NewspackIcon;
}
