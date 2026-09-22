import apiFetch from '@wordpress/api-fetch';
import { createElement, createRoot, flushSync } from '@wordpress/element';

import usePostSearch from '../../src/settings/hooks/use-post-search';
import useSection from '../../src/settings/hooks/use-section';
import Featured from '../../src/settings/sections/featured';
import { renderedHtmlToText } from '../../src/settings/utils';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => {
	const { createElement: element } = require( '@wordpress/element' );
	return {
		Button: ( { children, disabled, onClick } ) =>
			element( 'button', { disabled, onClick }, children ),
		Notice: ( { children } ) => element( 'div', null, children ),
		Spinner: () => element( 'span', null, 'Loading' ),
		TextControl: ( { label, value, onChange } ) =>
			element(
				'label',
				null,
				label,
				element( 'input', {
					value,
					onChange: event => onChange( event.target.value ),
				} )
			),
	};
} );

let container;
let root;

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	flushSync( () => root.unmount() );
	container.remove();
	jest.useRealTimers();
	jest.clearAllMocks();
} );

function renderHook( hook ) {
	let current;
	function Harness() {
		current = hook();
		return null;
	}
	flushSync( () => root.render( createElement( Harness ) ) );
	return () => current;
}

async function flushUpdates( callback ) {
	flushSync( callback );
	await Promise.resolve();
	await Promise.resolve();
	flushSync( () => {} );
}

function searchResponse( posts, totalPages = 1 ) {
	return {
		json: async () => posts,
		headers: new Headers( { 'X-WP-TotalPages': String( totalPages ) } ),
	};
}

function post( id, title ) {
	return {
		id,
		title: { rendered: title },
		excerpt: { rendered: '<p>Excerpt</p>', protected: false },
	};
}

test( 'rendered post text is decoded once without inserting HTML', () => {
	expect( renderedHtmlToText( '<strong>A &amp;amp; B</strong>' ) ).toBe( 'A &amp; B' );
	expect( document.querySelector( 'strong' ) ).toBeNull();
} );

test( 'featured title keeps a literal entity after server decoding', async () => {
	const settings = {
		site_summary: '',
		about_description: '',
		about_links: [],
		category_ids: [],
		featured_posts: [ 7 ],
		additional_resources_blocks: [],
	};
	flushSync( () => {
		root.render(
			createElement( Featured, {
				settings,
				resolved: [ { id: 7, title: 'A &amp; B', excerpt: '' } ],
				onSaved: jest.fn(),
				saveSettings: jest.fn(),
			} )
		);
	} );
	expect( container.querySelector( 'strong' )?.textContent ).toBe( 'A &amp; B' );
} );

test( 'changing a search resets pagination and ignores an old response', async () => {
	jest.useFakeTimers();
	let resolveOld;
	apiFetch
		.mockImplementationOnce(
			() =>
				new Promise( resolve => {
					resolveOld = resolve;
				} )
		)
		.mockResolvedValueOnce( searchResponse( [ post( 2, 'New result' ) ], 2 ) );
	const current = renderHook( () => usePostSearch( [] ) );
	await flushUpdates( () => current().setSearchQuery( 'old' ) );
	await flushUpdates( () => jest.advanceTimersByTime( 300 ) );
	await flushUpdates( () => current().setSearchQuery( 'new' ) );
	await flushUpdates( () => {
		resolveOld( searchResponse( [ post( 1, 'Old result' ) ] ) );
		jest.advanceTimersByTime( 300 );
	} );
	await jest.advanceTimersByTimeAsync( 0 );
	flushSync( () => {} );
	expect( current().results.map( result => result.id ) ).toEqual( [ 2 ] );
	expect( current().more ).toBe( true );
	await flushUpdates( () => current().setPage( 2 ) );
	await flushUpdates( () => current().setSearchQuery( 'third' ) );
	expect( current().page ).toBe( 1 );
	expect( current().results ).toEqual( [] );
	expect( current().more ).toBe( false );
} );

test( 'saving one section preserves another section’s unsaved draft', async () => {
	const saveSettings = jest.fn( async patch => ( {
		settings: { site_summary: 'Saved', about_description: 'Server', ...patch },
	} ) );
	const onSaved = jest.fn();
	const current = renderHook( () => ( {
		summary: useSection( { site_summary: 'Old' }, onSaved, saveSettings ),
		description: useSection( { about_description: 'Original' }, onSaved, saveSettings ),
	} ) );
	await flushUpdates( () => {
		current().description.setDraft( { about_description: 'Unsaved' } );
	} );
	await current().summary.save();
	flushSync( () => {} );
	expect( saveSettings ).toHaveBeenCalledWith( { site_summary: 'Old' } );
	expect( current().description.draft.about_description ).toBe( 'Unsaved' );
} );
