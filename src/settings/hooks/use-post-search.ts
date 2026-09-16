import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { errorMessage, renderedHtmlToText } from '../utils';

import type { ResolvedPost, SearchPost } from '../types';

export default function usePostSearch( resolved: ResolvedPost[] ) {
	const [ records, setRecords ] = useState< Record< number, ResolvedPost > >( () =>
		Object.fromEntries( resolved.map( post => [ post.id, post ] ) )
	);
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState< ResolvedPost[] >( [] );
	const [ page, setPage ] = useState( 1 );
	const [ more, setMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		const controller = new AbortController();
		if ( ! query.trim() ) {
			setResults( [] );
			setLoading( false );
			setError( '' );
			return () => controller.abort();
		}
		setLoading( true );
		setError( '' );
		const timer = setTimeout( async () => {
			try {
				const response = await apiFetch( {
					parse: false,
					path: `/wp/v2/posts?status=publish&per_page=10&page=${ page }&search=${ encodeURIComponent(
						query
					) }&_fields=id,title,excerpt`,
					signal: controller.signal,
				} );
				const posts = ( await response.json() ) as SearchPost[];
				if ( controller.signal.aborted ) {
					return;
				}
				const found = posts
					.filter( post => ! post.excerpt.protected )
					.map( post => ( {
						id: post.id,
						title: renderedHtmlToText( post.title.rendered ),
						excerpt: renderedHtmlToText( post.excerpt.rendered ),
					} ) );
				setResults( previous => ( page === 1 ? found : [ ...previous, ...found ] ) );
				setRecords( previous => ( {
					...previous,
					...Object.fromEntries( found.map( post => [ post.id, post ] ) ),
				} ) );
				setMore( page < Number( response.headers.get( 'X-WP-TotalPages' ) || 1 ) );
			} catch ( failure ) {
				if ( ! controller.signal.aborted ) {
					setError( errorMessage( failure, __( 'Search failed.', 'content-for-agents' ) ) );
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setLoading( false );
				}
			}
		}, 300 );
		return () => {
			clearTimeout( timer );
			controller.abort();
		};
	}, [ query, page ] );
	return {
		records,
		query,
		setQuery,
		results,
		setResults,
		page,
		setPage,
		more,
		loading,
		error,
	};
}
