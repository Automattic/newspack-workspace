export const filter = ( budgets, view ) => {
	let pagination = false;

	for ( const { field, value } of view.filters ) {
		if ( null === value || undefined === value ) {
			continue;
		}

		budgets = budgets.filter( budget => {
			switch ( field ) {
				case 'status':
					if ( 'archived' === value ) {
						if ( false === pagination ) {
							pagination = true;
						}
						return budget?.archived;
					}
					return ! budget?.archived;
				default:
					return true;
			}
		} );
	}

	if ( pagination ) {
		const startIndex = ( view.page - 1 ) * view.perPage;
		const endIndex = view.page * view.perPage;
		budgets = budgets.slice( startIndex, endIndex );
	}

	return budgets;
};

export const sortByOrder = ( items, order ) => {
	return [ ...items ].sort( ( a, b ) => {
		// Items with order 0 should go to the end
		if ( a.order === 0 && b.order !== 0 ) {
			return 1;
		}
		if ( b.order === 0 && a.order !== 0 ) {
			return -1;
		}
		if ( a.order === 0 && b.order === 0 ) {
			return 0;
		}

		const aIndex = order.indexOf( a.id );
		const bIndex = order.indexOf( b.id );

		// Sort by relative index
		if ( -1 !== aIndex && -1 !== bIndex ) {
			return aIndex - bIndex;
		}

		// Push to the end
		if ( aIndex !== -1 ) {
			return -1;
		}
		if ( bIndex !== -1 ) {
			return 1;
		}

		return 0;
	} );
};

export const isBudgetStories = () => {
	const urlParams = new URLSearchParams( window.location.search );
	return urlParams.has( 'budget_id' );
};

export const getCurrentBudget = () => {
	const urlParams = new URLSearchParams( window.location.search );

	const budgetId = urlParams.get( 'budget_id' );

	return budgetId ? budgetId : null;
};

export const redirectWithCleanUrl = () => {
	const url = new URL( window.location.href );

	url.searchParams.delete( 'budget_id' );

	window.location.replace( url.toString() );
};
