/**
 * Fast Checkout block — editor bundle entry.
 */

import { registerBlockType } from '@wordpress/blocks';
import { name, settings } from './index';
import './bindings-source';
import './use-context';
import './editor.scss';

registerBlockType( name, settings );
