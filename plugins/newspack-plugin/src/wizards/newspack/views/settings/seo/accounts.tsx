/**
 * Components for managing SEO accounts.
 */

/**
 * WordPress dependencies.
 */
import { ACCOUNTS } from './constants';
import { Grid, TextControl } from '../../../../../../packages/components/src';

/**
 * Internal dependencies.
 */

function Accounts( { setData, data }: { setData: ( v: SeoData[ 'urls' ] ) => void; data: SeoData[ 'urls' ] & { [ k: string ]: string } } ) {
	return (
		<Grid columns={ 3 } rowGap={ 16 }>
			{ ACCOUNTS.map( ( [ key, label, placeholder ] ) => (
				<TextControl
					key={ key }
					label={ label }
					onChange={ ( value: string ) => setData( { ...data, [ key ]: value } ) }
					value={ data[ key ] }
					placeholder={ placeholder }
				/>
			) ) }
		</Grid>
	);
}

export default Accounts;
