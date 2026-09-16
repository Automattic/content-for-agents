import { TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import Section from '../components/section';
import useSection from '../hooks/use-section';

import type { SectionProps } from '../types';

export default function About( { settings, onSaved, saveSettings }: SectionProps ) {
	const state = useSection(
		{
			site_summary: settings.site_summary,
			about_description: settings.about_description,
		},
		onSaved,
		saveSettings
	);
	return (
		<Section title={ __( 'Site summary', 'content-for-agents' ) } state={ state }>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Site summary', 'content-for-agents' ) }
				help={ __(
					'Shown below the site name in /llms.txt. Defaults to the WordPress tagline.',
					'content-for-agents'
				) }
				value={ state.draft.site_summary }
				onChange={ value => state.setDraft( { ...state.draft, site_summary: value } ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'About description', 'content-for-agents' ) }
				value={ state.draft.about_description }
				onChange={ value =>
					state.setDraft( {
						...state.draft,
						about_description: value,
					} )
				}
			/>
		</Section>
	);
}
