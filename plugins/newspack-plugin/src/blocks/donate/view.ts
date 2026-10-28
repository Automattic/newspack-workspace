/**
 * Style dependencies
 */
import { isString } from 'lodash';
import './styles/view.scss';

type FormValues = { [ key: string ]: string };

const CLASS_NAME_BASE = 'wpbnbd';
const selectedClassNameBase = `${ CLASS_NAME_BASE }--selected`;

const donateBlocks = Array.from( document.querySelectorAll( `.${ CLASS_NAME_BASE }` ) );

export const updateBlockClassName = ( element: Element, formValues: FormValues ) => {
	const amountKey = `donation_value_${ formValues.donation_frequency }`;
	if ( amountKey ) {
		Array.from( element.classList ).forEach( className => {
			if ( className.startsWith( selectedClassNameBase ) ) {
				element.classList.remove( className );
			}
		} );
		element.classList.add( `${ selectedClassNameBase }-${ formValues[ amountKey ] }` );
	}
};

export const getFormData: ( form: HTMLFormElement ) => FormValues = form => {
	const formData = new FormData( form );
	const formValues: FormValues = {};
	formData.forEach( ( value, key ) => {
		if ( isString( key ) && isString( value ) ) {
			formValues[ key ] = value;
		}
	} );
	return formValues;
};

donateBlocks.forEach( element => {
	const form = element.querySelector( 'form' );
	if ( ! form ) {
		return;
	}
	updateBlockClassName( element, getFormData( form ) );
	form.addEventListener( 'change', () => updateBlockClassName( element, getFormData( form ) ) );
} );
