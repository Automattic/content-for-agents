import apiFetch from '@wordpress/api-fetch';

export const config = window.contentForAgentsSettings;
apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
apiFetch.use( apiFetch.createRootURLMiddleware( config.restUrl ) );
export const settingsPath = '/content-for-agents/v1/settings';
