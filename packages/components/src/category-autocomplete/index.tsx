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
import find from 'lodash/find';
import filter from 'lodash/filter';
import classnames from 'classnames';
import type { DebouncedFunc } from 'lodash';
import type { ReactNode } from 'react';

/**
 * A term as returned by the REST API (id/name), or as previously saved by
 * this component (term_id/value).
 */
type Term = {
	id?: number;
	term_id?: number;
	name?: string;
	value?: string;
};

/**
 * A term reference in the `value` prop: a term id or a term object.
 */
type TermValue = number | Term;

type CategoryAutocompleteProps = {
	value: TermValue[];
	onChange: ( terms: Term[] ) => void;
	taxonomy?: string;
	className?: string;
	disabled?: boolean;
	description?: ReactNode;
	hideHelpFromVision?: boolean;
	hideLabelFromVision?: boolean;
	label?: string;
};

type CategoryAutocompleteState = {
	suggestions: Record< string, Term >;
	allCategories: Term[];
	isLoading: boolean;
};

/**
 * Category autocomplete field component.
 */
class CategoryAutocomplete extends Component< CategoryAutocompleteProps, CategoryAutocompleteState > {
	static defaultProps = {
		taxonomy: 'categories',
	};

	debouncedUpdateSuggestions: DebouncedFunc< ( search: string ) => void >;

	state: CategoryAutocompleteState = {
		suggestions: {},
		allCategories: [],
		isLoading: false,
	};

	/**
	 * Class constructor.
	 */
	constructor( props: CategoryAutocompleteProps ) {
		super( props );
		this.debouncedUpdateSuggestions = debounce( this.updateSuggestions, 100 );
	}

	componentDidMount() {
		this.setState( { isLoading: true } );
		apiFetch< Term[] >( {
			path: addQueryArgs( `/wp/v2/${ this.props.taxonomy }`, {
				per_page: -1,
				_fields: 'id,name',
			} ),
		} )
			.then( categories => this.setState( { allCategories: categories } ) )
			.finally( () => this.setState( { isLoading: false } ) );
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
	 * @param search The typed text to search for.
	 */
	updateSuggestions( search: string ) {
		this.setState( { isLoading: true } );
		apiFetch< Term[] >( {
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
					suggestions: categories.reduce(
						( accumulator: Record< string, Term >, category ) => ( { ...accumulator, [ category.name as string ]: category } ),
						{}
					),
				} );
			} )
			.finally( () => this.setState( { isLoading: false } ) );
	}

	/**
	 * Prepare categories data for the API endpoint, call the change handler.
	 *
	 * @param tokens An array of category tokens.
	 */
	handleOnChange = ( tokens: ( string | Term | undefined )[] ) => {
		const { onChange } = this.props;
		const { suggestions } = this.state;
		// Categories that are already will be objects, while new additions will be strings (the name).
		// allValues nomalizes the array so that they are all objects.
		const allValues = tokens
			.filter( token => 'undefined' !== typeof token ) // Ensure each token is a valid value.
			.map( token => ( 'string' === typeof token ? suggestions[ token ] : token ) )
			.filter( ( token ): token is Term => Boolean( token ) );
		onChange( allValues );
	};

	getAvailableSuggestions = () => {
		const { value } = this.props;
		const { suggestions } = this.state;
		const selectedIds = value.reduce( ( acc: number[], item ) => {
			if ( typeof item === 'object' && item?.id ) {
				acc.push( item.id );
			}
			return acc;
		}, [] );
		const availableSuggestions = filter( suggestions, ( { id } ) => selectedIds.indexOf( id as number ) === -1 );
		return availableSuggestions.map( v => v.name as string );
	};

	/**
	 * Render the component.
	 */
	render() {
		const { className, disabled, description, hideHelpFromVision, hideLabelFromVision, label, value } = this.props;
		const { allCategories, isLoading } = this.state;
		const classes = classnames( 'newspack-category-autocomplete', className );
		return (
			<div className={ classes }>
				<FormTokenField
					onInputChange={ ( input: string ) => this.debouncedUpdateSuggestions( input ) }
					value={ value.reduce( ( acc: { id: number; value: string }[], item ) => {
						const categoryOrItem = typeof item === 'number' ? find( allCategories, [ 'id', item ] ) : item;
						if ( categoryOrItem ) {
							acc.push( {
								id: ( categoryOrItem.term_id || categoryOrItem.id ) as number,
								value: ( categoryOrItem.value || categoryOrItem.name ) as string,
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

export default CategoryAutocomplete;
