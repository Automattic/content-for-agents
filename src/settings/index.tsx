import { createRoot } from '@wordpress/element';

import App from './app';
import './style.scss';

const container = document.getElementById( 'content-for-agents-settings-admin' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
