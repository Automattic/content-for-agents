import { Button, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import Section from '../components/section';
import useSection from '../hooks/use-section';

import type { Category, SectionProps } from '../types';

export default function Categories( {
	settings,
	categories,
	onSaved,
	saveSettings,
}: SectionProps & { categories: Category[] } ) {
	const state = useSection( { category_ids: settings.category_ids }, onSaved, saveSettings );
	const automatic = state.draft.category_ids.length === 0;
	const selected = automatic
		? categories.filter( term => term.count > 0 ).map( term => term.id )
		: state.draft.category_ids;
	const automaticDescription = __(
		'Automatic: includes top-level categories with published posts.',
		'content-for-agents'
	);
	const customDescription = __(
		'Custom selection: includes the selected top-level categories.',
		'content-for-agents'
	);
	return (
		<Section
			title={ __( 'Categories', 'content-for-agents' ) }
			state={ state }
			description={ automatic ? automaticDescription : customDescription }
		>
			{ categories.map( term => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ term.id }
					label={ `${ term.name } (${ term.count })` }
					checked={ selected.includes( term.id ) }
					onChange={ checked => {
						const next = checked
							? [ ...selected, term.id ]
							: selected.filter( id => id !== term.id );
						state.setDraft( {
							category_ids: categories
								.filter( item => next.includes( item.id ) )
								.map( item => item.id ),
						} );
					} }
				/>
			) ) }
			{ ! categories.length && (
				<p>{ __( 'No top-level categories found.', 'content-for-agents' ) }</p>
			) }
			<Button
				variant="secondary"
				disabled={ automatic }
				onClick={ () => state.setDraft( { category_ids: [] } ) }
			>
				{ __( 'Reset to automatic', 'content-for-agents' ) }
			</Button>
		</Section>
	);
}
