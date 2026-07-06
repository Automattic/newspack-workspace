/**
 * Fast Checkout Donate Selector — editor bundle entry.
 */

import { registerBlockType } from '@wordpress/blocks';
import { name, settings } from './index';
import './editor.scss';

registerBlockType( name, settings );
