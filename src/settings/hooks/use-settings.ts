import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { settingsPath } from '../api';
import { errorMessage } from '../utils';

import type { Settings, SettingsResponse } from '../types';

export default function useSettings() {
	const [ data, setData ] = useState< SettingsResponse | null >( null );
	const [ error, setError ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );
	// Sections share one option in WordPress. Serialize writes to avoid one
	// section overwriting another while preserving each section's local draft.
	const pendingSaveRef = useRef< Promise< unknown > >( Promise.resolve() );
	const saveSettings = useCallback( ( patch: Partial< Settings > ) => {
		const request = pendingSaveRef.current.then( () =>
			apiFetch< SettingsResponse >( {
				path: settingsPath,
				method: 'POST',
				data: patch,
			} )
		);
		pendingSaveRef.current = request.catch( () => undefined );
		return request;
	}, [] );

	useEffect( () => {
		const controller = new AbortController();
		setError( '' );
		apiFetch< SettingsResponse >( {
			path: settingsPath,
			signal: controller.signal,
		} )
			.then( response => {
				if ( ! controller.signal.aborted ) {
					setData( response );
				}
			} )
			.catch( ( failure: unknown ) => {
				if ( ! controller.signal.aborted ) {
					setError(
						errorMessage( failure, __( 'Unable to load settings.', 'content-for-agents' ) )
					);
				}
			} );
		return () => controller.abort();
	}, [ attempt ] );
	return { data, setData, error, setAttempt, saveSettings };
}
