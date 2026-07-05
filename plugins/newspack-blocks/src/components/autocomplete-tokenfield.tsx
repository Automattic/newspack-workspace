/**
 * External dependencies
 */
import { debounce } from 'lodash';
import type { DebouncedFunc } from 'lodash';

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { FormTokenField, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './autocomplete-tokenfield.scss';

type TokenValue = string | number;

type Suggestion = {
	value: TokenValue;
	label: string;
};

type AutocompleteTokenFieldProps = {
	tokens: TokenValue[];
	onChange: ( values: TokenValue[] ) => void;
	fetchSuggestions?: ( input: string ) => Promise< Suggestion[] >;
	fetchSavedInfo?: ( tokens: TokenValue[] ) => Promise< Suggestion[] >;
	label?: string;
	help?: string;
};

type AutocompleteTokenFieldState = {
	suggestions: string[];
	validValues: Record< TokenValue, string >;
	loading: boolean;
};

/**
 * An multi-selecting, api-driven autocomplete input suitable for use in block attributes.
 */
class AutocompleteTokenField extends Component< AutocompleteTokenFieldProps, AutocompleteTokenFieldState > {
	debouncedUpdateSuggestions: DebouncedFunc< ( input: string ) => void >;
	suggestionsRequest?: Promise< Suggestion[] >;

	constructor( props: AutocompleteTokenFieldProps ) {
		super( props );
		this.state = {
			suggestions: [],
			validValues: {},
			loading: this.isFetchingInfoOnLoad(),
		};

		this.debouncedUpdateSuggestions = debounce( this.updateSuggestions, 500 );
	}

	/**
	 * If the component has tokens passed in props, it should fetch info after it mounts.
	 */
	isFetchingInfoOnLoad = () => {
		const { tokens, fetchSavedInfo } = this.props;
		return Boolean( tokens.length && fetchSavedInfo );
	};

	/**
	 * When the component loads, fetch information about the tokens so we can populate
	 * the tokens with the correct labels.
	 */
	componentDidMount() {
		if ( this.isFetchingInfoOnLoad() ) {
			const { tokens, fetchSavedInfo } = this.props;
			if ( ! fetchSavedInfo ) {
				return;
			}

			fetchSavedInfo( tokens ).then( results => {
				const { validValues } = this.state;

				results.forEach( suggestion => {
					validValues[ suggestion.value ] = suggestion.label;
				} );

				this.setState( { validValues, loading: false } );
			} );
		}
	}

	/**
	 * Clean up any unfinished autocomplete api call requests.
	 */
	componentWillUnmount() {
		delete this.suggestionsRequest;
		this.debouncedUpdateSuggestions.cancel();
	}

	/**
	 * Get a list of labels for input values.
	 *
	 * @param values Array of values (ids, etc.).
	 * @return array of valid labels corresponding to the values.
	 */
	getLabelsForValues( values: TokenValue[] ) {
		const { validValues } = this.state;
		return values.reduce< string[] >(
			( accumulator, value ) => ( validValues[ value ] ? [ ...accumulator, validValues[ value ] ] : accumulator ),
			[]
		);
	}

	/**
	 * Get a list of values for input labels.
	 *
	 * @param labels Array of labels from the tokens.
	 * @return Array of valid values corresponding to the labels.
	 */
	getValuesForLabels( labels: string[] ) {
		const { validValues } = this.state;

		return (
			labels
				.map( label => Object.keys( validValues ).find( key => validValues[ key ] === label ) )
				// It's possible the submitted label doesn't have a corresponding value, so we filter out any undefined values.
				.filter( ( value ): value is string => !! value )
		);
	}

	/**
	 * Refresh the autocomplete dropdown.
	 *
	 * @param input Input to fetch suggestions for
	 */
	updateSuggestions( input: string ) {
		const { fetchSuggestions } = this.props;
		if ( ! fetchSuggestions ) {
			return;
		}

		this.setState( { loading: true }, () => {
			const request = fetchSuggestions( input );
			request
				.then( suggestions => {
					// A fetch Promise doesn't have an abort option. It's mimicked by
					// comparing the request reference in on the instance, which is
					// reset or deleted on subsequent requests or unmounting.
					if ( this.suggestionsRequest !== request ) {
						return;
					}

					const { validValues } = this.state;
					const currentSuggestions: string[] = [];

					suggestions.forEach( suggestion => {
						const trimmedSuggestionLabel = suggestion.label.trim();
						const duplicatedSuggestionIndex = currentSuggestions.indexOf( trimmedSuggestionLabel );
						if ( duplicatedSuggestionIndex >= 0 ) {
							suggestion.label = `${ trimmedSuggestionLabel } (${ suggestion.value })`;
						}
						currentSuggestions.push( trimmedSuggestionLabel );
						validValues[ suggestion.value ] = trimmedSuggestionLabel;
					} );

					this.setState( { suggestions: currentSuggestions, validValues, loading: false } );
				} )
				.catch( () => {
					if ( this.suggestionsRequest === request ) {
						this.setState( {
							loading: false,
						} );
					}
				} );

			this.suggestionsRequest = request;
		} );
	}

	/**
	 * When a token is selected, we need to convert the string label into a recognized value suitable for saving as an attribute.
	 *
	 * @param tokenStrings An array of token label strings.
	 */
	handleOnChange( tokenStrings: string[] ) {
		const { onChange } = this.props;
		onChange( this.getValuesForLabels( tokenStrings ) );
	}

	/**
	 * To populate the tokens, we need to convert the values into a human-readable label.
	 *
	 * @return An array of token label strings.
	 */
	getTokens() {
		const { tokens } = this.props;
		return this.getLabelsForValues( tokens );
	}

	/**
	 * Render.
	 */
	render() {
		const { help, label = '' } = this.props;
		const { suggestions, loading } = this.state;

		return (
			<div className="autocomplete-tokenfield">
				<FormTokenField
					value={ this.getTokens() }
					suggestions={ suggestions }
					onChange={ tokens => this.handleOnChange( tokens as string[] ) }
					onInputChange={ input => this.debouncedUpdateSuggestions( input ) }
					label={ label }
					__next40pxDefaultSize
				/>
				{ loading && <Spinner /> }
				{ help && <p className="autocomplete-tokenfield__help">{ help }</p> }
			</div>
		);
	}
}

export default AutocompleteTokenField;
