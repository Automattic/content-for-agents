import { Button, TextControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import OrderButtons from '../components/order-buttons';
import Section from '../components/section';
import useSection from '../hooks/use-section';
import { newId } from '../utils';

import type { ResourceBlock, SectionProps } from '../types';

export default function Resources( { settings, onSaved, saveSettings }: SectionProps ) {
	const state = useSection(
		{ additional_resources_blocks: settings.additional_resources_blocks },
		onSaved,
		saveSettings
	);
	const items = state.draft.additional_resources_blocks;
	const change = ( next: ResourceBlock[] ) =>
		state.setDraft( { additional_resources_blocks: next } );
	const update = ( index: number, field: 'title' | 'body', value: string ) =>
		change( items.map( ( item, i ) => ( i === index ? { ...item, [ field ]: value } : item ) ) );
	return (
		<Section
			title={ __( 'Additional resources', 'content-for-agents' ) }
			state={ state }
			description={ __(
				'Add ordered subsections containing plain text or Markdown.',
				'content-for-agents'
			) }
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
						<TextareaControl
							__nextHasNoMarginBottom
							rows={ 6 }
							label={ __( 'Content', 'content-for-agents' ) }
							value={ item.body }
							onChange={ value => update( index, 'body', value ) }
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
			<p>{ __( 'Items without a title are omitted when saved.', 'content-for-agents' ) }</p>
			<Button
				variant="secondary"
				disabled={ items.length >= 20 }
				onClick={ () => change( [ ...items, { id: newId(), title: '', body: '' } ] ) }
			>
				{ __( 'Add resource', 'content-for-agents' ) }
			</Button>
			{ items.length >= 20 && (
				<p>{ __( 'Maximum of 20 items reached.', 'content-for-agents' ) }</p>
			) }
		</Section>
	);
}
