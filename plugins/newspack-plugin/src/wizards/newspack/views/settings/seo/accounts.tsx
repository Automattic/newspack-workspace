/**
 * Components for managing SEO accounts.
 */

/**
 * WordPress dependencies.
 */
import { ACCOUNTS } from './constants';
import { TextControl } from '../../../../../../packages/components/src';

/**
 * Internal dependencies.
 */

function Accounts( { setData, data }: { setData: ( v: SeoData[ 'urls' ] ) => void; data: SeoData[ 'urls' ] & { [ k: string ]: string } } ) {
	return (
		<>
			{ ACCOUNTS.map( ( [ key, label, placeholder ] ) => (
				<TextControl
					key={ key }
					label={ label }
					onChange={ ( value: string ) => setData( { ...data, [ key ]: value } ) }
					value={ data[ key ] }
					placeholder={ placeholder }
					withMargin={ false }
				/>
			) ) }
		</>
	);
}

export default Accounts;
