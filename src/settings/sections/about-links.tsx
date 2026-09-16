import { Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import OrderButtons from '../components/order-buttons';
import Section from '../components/section';
import useSection from '../hooks/use-section';
import { newId } from '../utils';

import type { AboutLink, SectionProps } from '../types';

export default function AboutLinks( { settings, onSaved, saveSettings }: SectionProps ) {
	const state = useSection( { about_links: settings.about_links }, onSaved, saveSettings );
	const items = state.draft.about_links;
	const change = ( next: AboutLink[] ) => state.setDraft( { about_links: next } );
	const update = ( index: number, field: 'title' | 'url' | 'description', value: string ) =>
		change( items.map( ( item, i ) => ( i === index ? { ...item, [ field ]: value } : item ) ) );
	return (
		<Section
			title={ __( 'About links', 'content-for-agents' ) }
			state={ state }
			description={ __( 'Add ordered links to useful pages on your site.', 'content-for-agents' ) }
		>
			<ol className="content-for-agents-list">
				{ items.map( ( item, index ) => (
					<li key={ item.id }>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Title', 'content-for-agents' ) }
							value={ item.title }
							onChange={ value => update( index, 'title', value ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="url"
							label={ __( 'URL', 'content-for-agents' ) }
							value={ item.url }
							onChange={ value => update( index, 'url', value ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Description', 'content-for-agents' ) }
							value={ item.description }
							onChange={ value => update( index, 'description', value ) }
						/>
						<OrderButtons
							items={ items }
							index={ index }
							onChange={ change }
							label={ item.title || __( 'Untitled item', 'content-for-agents' ) }
						/>
					</li>
				) ) }
			</ol>
			<p>
				{ __(
					'Links need a title and a valid HTTP or HTTPS URL to be saved.',
					'content-for-agents'
				) }
			</p>
			<Button
				variant="secondary"
				disabled={ items.length >= 10 }
				onClick={ () =>
					change( [ ...items, { id: newId(), title: '', url: '', description: '' } ] )
				}
			>
				{ __( 'Add link', 'content-for-agents' ) }
			</Button>
			{ items.length >= 10 && (
				<p>{ __( 'Maximum of 10 items reached.', 'content-for-agents' ) }</p>
			) }
		</Section>
	);
}
