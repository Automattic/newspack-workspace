/**
 * Category Autocomplete
 */

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';
import { Component } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies.
 */
import { FormTokenField } from '../';
import './style.scss';

/**
 * External dependencies
 */
import debounce from 'lodash/debounce';
import chunk from 'lodash/chunk';
import filter from 'lodash/filter';
import classnames from 'classnames';

/**
 * The most terms the REST API returns per request.
 */
const MAX_TERMS_PER_REQUEST = 100;

/**
 * Category autocomplete field component.
 */
class CategoryAutocomplete extends Component {
	state = {
		suggestions: {},
		knownCategories: {},
		isLoading: false,
	};

	/**
	 * Class constructor.
	 */
	constructor( props ) {
		super( props );
		this.debouncedUpdateSuggestions = debounce( this.updateSuggestions, 100 );
		// IDs already requested, so a deleted term or a failed request is not fetched again on every update.
		this.requestedIds = new Set();
	}

	componentDidMount() {
		this.fetchUnknownCategories();
	}

	componentDidUpdate() {
		this.fetchUnknownCategories();
	}

	/**
	 * Fetch the names of numeric IDs in the value that are not known yet.
	 * Only those IDs are requested, never the whole taxonomy.
	 */
	fetchUnknownCategories() {
		const { taxonomy, value } = this.props;
		const { knownCategories } = this.state;
		const isUnknown = item => Number.isInteger( item ) && ! knownCategories[ item ] && ! this.requestedIds.has( item );
		const ids = [ ...new Set( value.filter( isUnknown ) ) ];
		if ( ! ids.length ) {
			return;
		}
		ids.forEach( id => this.requestedIds.add( id ) );
		this.setState( { isLoading: true } );
		const requests = chunk( ids, MAX_TERMS_PER_REQUEST ).map( idsChunk =>
			apiFetch( {
				path: addQueryArgs( `/wp/v2/${ taxonomy }`, {
					include: idsChunk.join( ',' ),
					per_page: MAX_TERMS_PER_REQUEST,
					_fields: 'id,name',
				} ),
			} )
		);
		Promise.all( requests )
			.then( responses => this.addKnownCategories( responses.flat() ) )
			.finally( () => this.setState( { isLoading: false } ) );
	}

	/**
	 * Add terms to the lookup used to show names for numeric IDs.
	 *
	 * @param {Array} categories Terms with `id` and `name`.
	 */
	addKnownCategories( categories ) {
		this.setState( ( { knownCategories } ) => {
			const nextKnownCategories = { ...knownCategories };
			categories.forEach( ( { id, name } ) => {
				nextKnownCategories[ id ] = { id, name };
			} );
			return { knownCategories: nextKnownCategories };
		} );
	}

	/**
	 * Clean up debounced suggestions method.
	 */
	componentWillUnmount() {
		this.debouncedUpdateSuggestions.cancel();
	}

	/**
	 * Refresh the autocomplete UI based on text that was typed.
	 *
	 * @param {string} search The typed text to search for.
	 */
	updateSuggestions( search ) {
		this.setState( { isLoading: true } );
		apiFetch( {
			path: addQueryArgs( `/wp/v2/${ this.props.taxonomy }`, {
				search,
				per_page: 20,
				_fields: 'id,name',
				orderby: 'count',
				order: 'desc',
			} ),
		} )
			.then( categories => {
				this.setState( {
					suggestions: categories.reduce( ( accumulator, category ) => ( { ...accumulator, [ category.name ]: category } ), {} ),
				} );
			} )
			.finally( () => this.setState( { isLoading: false } ) );
	}

	/**
	 * Prepare categories data for the API endpoint, call the change handler.
	 *
	 * @param {Array} tokens An array of category tokens.
	 */
	handleOnChange = tokens => {
		const { onChange } = this.props;
		const { suggestions } = this.state;
		// Categories that are already will be objects, while new additions will be strings (the name).
		// allValues nomalizes the array so that they are all objects.
		const allValues = tokens
			.filter( token => 'undefined' !== typeof token ) // Ensure each token is a valid value.
			.map( token => ( 'string' === typeof token ? suggestions[ token ] : token ) )
			.filter( Boolean );
		// Callers that store bare IDs pass picked terms back as numbers, so remember their names.
		const pickedCategories = tokens
			.filter( token => 'string' === typeof token )
			.map( token => suggestions[ token ] )
			.filter( Boolean );
		if ( pickedCategories.length ) {
			this.addKnownCategories( pickedCategories );
		}
		onChange( allValues );
	};

	getAvailableSuggestions = () => {
		const { value } = this.props;
		const { suggestions } = this.state;
		const selectedIds = value.reduce( ( acc, item ) => {
			if ( item?.id ) {
				acc.push( item.id );
			}
			return acc;
		}, [] );
		const availableSuggestions = filter( suggestions, ( { id } ) => selectedIds.indexOf( id ) === -1 );
		return availableSuggestions.map( v => v.name );
	};

	/**
	 * Render the component.
	 */
	render() {
		const { className, disabled, description, hideHelpFromVision, hideLabelFromVision, label, value } = this.props;
		const { knownCategories, isLoading } = this.state;
		const classes = classnames( 'newspack-category-autocomplete', className );
		return (
			<div className={ classes }>
				<FormTokenField
					onInputChange={ input => this.debouncedUpdateSuggestions( input ) }
					value={ value.reduce( ( acc, item ) => {
						const categoryOrItem = typeof item === 'number' ? knownCategories[ item ] : item;
						if ( categoryOrItem ) {
							acc.push( {
								id: categoryOrItem.term_id || categoryOrItem.id,
								value: categoryOrItem.value || categoryOrItem.name,
							} );
						}
						return acc;
					}, [] ) }
					suggestions={ this.getAvailableSuggestions() }
					onChange={ this.handleOnChange }
					label={ label }
					disabled={ disabled }
					description={ description }
					hideHelpFromVision={ hideHelpFromVision }
					hideLabelFromVision={ hideLabelFromVision }
				/>
				{ isLoading ? (
					<span className="newspack-category-autocomplete__suggestions-spinner">
						<Spinner />
					</span>
				) : null }
			</div>
		);
	}
}

CategoryAutocomplete.defaultProps = {
	taxonomy: 'categories',
};

export default CategoryAutocomplete;
