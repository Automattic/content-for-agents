import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage } from '../utils';

import type { Settings, SectionProps, SaveState } from '../types';

export default function useSection< T extends Partial< Settings > >(
	initial: T,
	onSaved: SectionProps[ 'onSaved' ],
	saveSettings: SectionProps[ 'saveSettings' ]
) {
	const [ draft, setDraft ] = useState( initial );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState< SaveState[ 'notice' ] >( null );
	const save = async () => {
		setBusy( true );
		setNotice( null );
		try {
			const response = await saveSettings( draft );
			// The submitted keys define this section. Keep other sections' drafts intact.
			const keys = Object.keys( draft ) as Array< keyof T & keyof Settings >;
			const next = Object.fromEntries( keys.map( key => [ key, response.settings[ key ] ] ) ) as T;
			setDraft( next );
			onSaved( response );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'content-for-agents' ),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: errorMessage(
					error,
					__( 'Unable to save settings. Please try again.', 'content-for-agents' )
				),
			} );
		} finally {
			setBusy( false );
		}
	};
	return { draft, setDraft, busy, notice, save };
}
