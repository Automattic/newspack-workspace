/**
 * Section
 *
 * Shared wrapper for an Insights section: a semantic `<section>` laid out as a
 * vertical stack. Centralizes the inter-element spacing (VStack `spacing={ 8 }`
 * = 32px) so every section matches and the value changes in one place.
 */

/**
 * WordPress dependencies
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

const Section = ( props: React.ComponentPropsWithoutRef< 'section' > ) => <VStack as="section" spacing={ 8 } { ...props } />;

export default Section;
