import type { PostItem } from '../types';

type StatusKindLabels = Record< string, string >;

interface StatusLabelModule {
	STATUS_KIND_LABELS: () => StatusKindLabels;
	statusKindLabel: ( kind: string ) => string;
}

/**
 * Build a `{ STATUS_KIND_LABELS, statusKindLabel }` pair. `buildLabels` is
 * invoked lazily so i18n data has time to register before any `__()` runs.
 *
 * @param buildLabels Factory returning the `{kind: label}` map.
 * @return Memoised accessors.
 */
export function createStatusLabelModule( buildLabels: () => StatusKindLabels ): StatusLabelModule {
	let cached: StatusKindLabels | null = null;

	const STATUS_KIND_LABELS = (): StatusKindLabels => {
		if ( null === cached ) {
			cached = buildLabels();
		}
		return cached;
	};

	const statusKindLabel = ( kind: string ): string => {
		const labels = STATUS_KIND_LABELS();
		return labels[ kind ] || labels.draft;
	};

	return { STATUS_KIND_LABELS, statusKindLabel };
}

/**
 * @param item Post object from REST.
 * @return True when the row is trashed.
 */
export function isTrashed( item: PostItem ): boolean {
	return 'trash' === item?.status;
}
