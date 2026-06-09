/**
 * Build a `{ STATUS_KIND_LABELS, statusKindLabel }` pair. `buildLabels` is
 * invoked lazily so i18n data has time to register before any `__()` runs.
 *
 * @param {() => Object} buildLabels Factory returning the `{kind: label}` map.
 * @return {{ STATUS_KIND_LABELS: () => Object, statusKindLabel: ( kind: string ) => string }} Memoised accessors.
 */
export function createStatusLabelModule( buildLabels ) {
	let cached = null;

	const STATUS_KIND_LABELS = () => {
		if ( null === cached ) {
			cached = buildLabels();
		}
		return cached;
	};

	const statusKindLabel = kind => {
		const labels = STATUS_KIND_LABELS();
		return labels[ kind ] || labels.draft;
	};

	return { STATUS_KIND_LABELS, statusKindLabel };
}

/**
 * @param {Object} item Post object from REST.
 * @return {boolean} True when the row is trashed.
 */
export function isTrashed( item ) {
	return 'trash' === item?.status;
}
