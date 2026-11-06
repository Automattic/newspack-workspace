/**
 * External dependencies
 */
import { debounce } from 'lodash';

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';
import { FormTokenField, Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './autocomplete-tokenfield.scss';

/**
 * An multi-selecting, api-driven autocomplete input suitable for use in block attributes.
 */
class AutocompleteTokenField extends Component {
	constructor( props ) {
		super( props );
		this.state = {
			suggestions: [],
			validValues: {},
			loading: this.isFetchingInfoOnLoad(),
		};

		this.debouncedUpdateSuggestions = debounce( this.updateSuggestions, 500 );
		this.knownSuggestions = {};
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

			fetchSavedInfo( tokens )
				.then( results => {
					this.setState( { validValues: this.addValidValues( results ), loading: false } );
				} )
				.catch( error => {
					// eslint-disable-next-line no-console
					console.error( 'Newspack Blocks: could not load the titles for the saved selection.', error );
					// Without labels the field would render no tokens, and the next
					// selection would then write back a list missing every saved ID.
					const { validValues } = this.state;

					tokens.forEach( token => {
						validValues[ token ] = String( token );
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
	 * @param {Array} values Array of values (ids, etc.).
	 * @return {Array} array of valid labels corresponding to the values.
	 */
	getLabelsForValues( values ) {
		const { validValues } = this.state;
		return values.reduce( ( accumulator, value ) => ( validValues[ value ] ? [ ...accumulator, validValues[ value ] ] : accumulator ), [] );
	}

	/**
	 * Add suggestions to the valid values, relabelling every value whose title clashes.
	 *
	 * Tokens are plain strings, so a label is the only thing that maps a token back to its ID.
	 * Two IDs sharing one would collapse onto a single ID on the next change. A clashing value
	 * shows its `clashNote` when that tells it apart, and its ID when it doesn't.
	 *
	 * @param {Array} suggestions Objects with a value, a label and an optional clashNote.
	 * @return {Object} The valid values, keyed by value.
	 */
	addValidValues( suggestions ) {
		const { validValues } = this.state;

		suggestions.forEach( ( { value, label, clashNote } ) => {
			this.knownSuggestions[ value ] = { title: label.trim(), clashNote };
		} );

		const known = Object.values( this.knownSuggestions );
		Object.entries( this.knownSuggestions ).forEach( ( [ value, { title, clashNote } ] ) => {
			const clashes = known.filter( other => other.title === title );
			if ( clashes.length === 1 ) {
				validValues[ value ] = title;
			} else if ( ! clashNote ) {
				validValues[ value ] = `${ title } (${ value })`;
			} else if ( clashes.filter( other => other.clashNote === clashNote ).length === 1 ) {
				validValues[ value ] = `${ title } (${ clashNote })`;
			} else {
				validValues[ value ] = `${ title } (${ clashNote }, ${ value })`;
			}
		} );

		return validValues;
	}

	/**
	 * Get a list of values for input labels.
	 *
	 * @param {Array} labels Array of labels from the tokens.
	 * @return {Array} Array of valid values corresponding to the labels.
	 */
	getValuesForLabels( labels ) {
		const { validValues } = this.state;

		return (
			labels
				.map( label => Object.keys( validValues ).find( key => validValues[ key ] === label ) )
				// It's possible the submitted label doesn't have a corresponding value, so we filter out any undefined values.
				.filter( value => !! value )
		);
	}

	/**
	 * Refresh the autocomplete dropdown.
	 *
	 * @param {string} input Input to fetch suggestions for
	 */
	updateSuggestions( input ) {
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

					const validValues = this.addValidValues( suggestions );
					const currentSuggestions = suggestions.map( suggestion => validValues[ suggestion.value ] );

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
	 * @param {Array} tokenStrings An array of token label strings.
	 */
	handleOnChange( tokenStrings ) {
		const { onChange } = this.props;
		onChange( this.getValuesForLabels( tokenStrings ) );
	}

	/**
	 * To populate the tokens, we need to convert the values into a human-readable label.
	 *
	 * @return {Array} An array of token label strings.
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
					onChange={ tokens => this.handleOnChange( tokens ) }
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
