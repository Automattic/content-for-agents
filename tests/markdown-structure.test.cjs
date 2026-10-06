const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const test = require( 'node:test' );
const MarkdownIt = require( 'markdown-it' );

const parser = new MarkdownIt();

function parseFixture( name ) {
	return parser.parse( readFileSync( join( __dirname, 'fixtures', name ), 'utf8' ), {} );
}

function locationOf( tokens, text ) {
	const stack = [];
	for ( let index = 0; index < tokens.length; index++ ) {
		const token = tokens[ index ];
		if ( token.type.endsWith( '_open' ) ) {
			stack.push( { type: token.type, index } );
		}
		if ( token.content.includes( text ) ) {
			return { token, stack: [ ...stack ] };
		}
		if ( token.type.endsWith( '_close' ) ) {
			stack.pop();
		}
	}
	assert.fail( `Missing content: ${ text }` );
}

function listItemsAt( tokens, text ) {
	return locationOf( tokens, text ).stack.filter( ( entry ) => entry.type === 'list_item_open' );
}

test( 'block gauntlet keeps rich Classic Editor content in one list item', () => {
	const tokens = parseFixture( 'block-gauntlet.md' );
	const intro = listItemsAt( tokens, 'SENTINEL-RICH-LIST-INTRO' );
	const followup = listItemsAt( tokens, 'SENTINEL-RICH-LIST-FOLLOWUP' );
	const nested = listItemsAt( tokens, 'SENTINEL-RICH-LIST-NESTED' );
	const ending = listItemsAt( tokens, 'SENTINEL-RICH-LIST-END' );
	const sibling = listItemsAt( tokens, 'SENTINEL-RICH-LIST-SIBLING' );

	assert.equal( intro.length, 1 );
	assert.deepEqual( followup, intro );
	assert.equal( nested.length, 2 );
	assert.deepEqual( nested[ 0 ], intro[ 0 ] );
	assert.deepEqual( ending, intro );
	assert.equal( sibling.length, 1 );
	assert.notDeepEqual( sibling[ 0 ], intro[ 0 ] );
} );

test( 'block gauntlet parses headings, quotes, table, links, and media as intended', () => {
	const tokens = parseFixture( 'block-gauntlet.md' );
	for ( const text of [ 'SENTINEL-START', 'SENTINEL-ACCORDION-QUESTION', 'SENTINEL-MEDIA-TEXT' ] ) {
		assert.ok( locationOf( tokens, text ).stack.some( ( entry ) => entry.type === 'heading_open' ), text );
	}
	for ( const text of [ 'SENTINEL-QUOTE', 'SENTINEL-PULLQUOTE', 'SENTINEL-CUSTOM-QUOTE' ] ) {
		assert.ok( locationOf( tokens, text ).stack.some( ( entry ) => entry.type === 'blockquote_open' ), text );
	}
	assert.ok( locationOf( tokens, 'SENTINEL-TABLE' ).stack.some( ( entry ) => entry.type === 'table_open' ) );
	assert.equal( locationOf( tokens, 'SENTINEL-CODE' ).token.type, 'fence' );

	const link = locationOf( tokens, 'SENTINEL-CTA-LINK' ).token.children.find( ( child ) => child.type === 'link_open' );
	assert.equal( link.attrGet( 'href' ), 'https://example.com/action' );
	const image = locationOf( tokens, 'Media text image' ).token.children.find( ( child ) => child.type === 'image' );
	assert.equal( image.attrGet( 'src' ), 'https://example.com/media-text.jpg' );
} );

test( 'renderer target Markdown keeps quote, code, and tail in the first list item', () => {
	const tokens = parseFixture( 'renderer-list-blocks.md' );
	const lead = listItemsAt( tokens, 'Lead' );
	for ( const text of [ 'Quote', 'echo 1;', 'Tail' ] ) {
		assert.deepEqual( listItemsAt( tokens, text ), lead, text );
	}
	assert.ok( locationOf( tokens, 'Quote' ).stack.some( ( entry ) => entry.type === 'blockquote_open' ) );
	assert.equal( locationOf( tokens, 'echo 1;' ).token.type, 'fence' );
	assert.notDeepEqual( listItemsAt( tokens, 'Next' ), lead );
} );
