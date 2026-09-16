import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

import OrderButtons from '../components/order-buttons';
import Section from '../components/section';
import usePostSearch from '../hooks/use-post-search';
import useSection from '../hooks/use-section';

import type { ResolvedPost, SectionProps } from '../types';

export default function Featured( {
	settings,
	resolved,
	onSaved,
	saveSettings,
}: SectionProps & { resolved: ResolvedPost[] } ) {
	const state = useSection( { featured_posts: settings.featured_posts }, onSaved, saveSettings );
	const { records, query, setQuery, results, setResults, page, setPage, more, loading, error } =
		usePostSearch( resolved );
	const ids = state.draft.featured_posts;
	return (
		<Section
			title={ __( 'Featured posts', 'content-for-agents' ) }
			state={ state }
			description={ __(
				'Select public posts and arrange their order. With no selection, recent posts are shown. Password-protected posts are excluded.',
				'content-for-agents'
			) }
		>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Search published posts', 'content-for-agents' ) }
				value={ query }
				onChange={ value => {
					setQuery( value );
					setPage( 1 );
					setResults( [] );
				} }
				autoComplete="off"
			/>
			<div aria-live="polite">
				{ loading && <Spinner /> }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ query.trim() && ! loading && ! error && ! results.length && (
					<p>{ __( 'No posts found.', 'content-for-agents' ) }</p>
				) }
			</div>
			<ul className="arc-search-results">
				{ results.map( post => (
					<li key={ post.id }>
						<Button
							variant="link"
							disabled={ ids.includes( post.id ) }
							onClick={ () => {
								state.setDraft( {
									featured_posts: [ ...ids, post.id ],
								} );
								setQuery( '' );
							} }
						>
							{ post.title || __( 'Untitled post', 'content-for-agents' ) }
						</Button>
						<p>{ post.excerpt }</p>
					</li>
				) ) }
			</ul>
			{ query.trim() && more && ! error && (
				<Button variant="secondary" disabled={ loading } onClick={ () => setPage( page + 1 ) }>
					{ __( 'More results', 'content-for-agents' ) }
				</Button>
			) }
			<ol className="arc-list">
				{ ids.map( ( id, index ) => {
					const label = decodeEntities(
						records[ id ]?.title ||
							sprintf(
								/* translators: %d: Post ID. */
								__( 'Post %d (unavailable)', 'content-for-agents' ),
								id
							)
					);
					return (
						<li key={ id }>
							<strong>{ label }</strong>
							<OrderButtons
								items={ ids }
								index={ index }
								onChange={ next => state.setDraft( { featured_posts: next } ) }
								label={ label }
							/>
						</li>
					);
				} ) }
			</ol>
		</Section>
	);
}
