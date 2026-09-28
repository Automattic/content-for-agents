import MarkdownIt from 'markdown-it';

import fixtures from '../fixtures/linked-content.json';

const markdown = new MarkdownIt();
const visibleText = value => value.replace( /\s+/g, ' ' ).trim();

test.each( fixtures )( '$name renders as linked content', fixture => {
	const container = document.createElement( 'div' );
	container.innerHTML = markdown.render( fixture.markdown );

	expect( visibleText( container.textContent ) ).toBe( fixture.text );
	expect(
		Array.from( container.querySelectorAll( 'a' ), link => link.getAttribute( 'href' ) )
	).toEqual( fixture.links );
	expect(
		Array.from( container.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ), heading =>
			visibleText( heading.textContent )
		)
	).toEqual( fixture.headings );
	expect(
		Array.from( container.querySelectorAll( 'li' ), item => visibleText( item.textContent ) )
	).toEqual( fixture.listItems );
	expect( Array.from( container.querySelectorAll( 'img' ), image => image.alt ) ).toEqual(
		fixture.images
	);
} );
