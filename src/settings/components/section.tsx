import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { SaveState } from '../types';
import type { ReactNode } from 'react';

export default function Section( {
	title,
	description,
	state,
	children,
}: {
	title: string;
	description?: string;
	state: SaveState;
	children: ReactNode;
} ) {
	return (
		<section className="content-for-agents-section" aria-label={ title }>
			<h2>{ title }</h2>
			{ description && <p>{ description }</p> }
			{ state.notice && (
				<Notice status={ state.notice.status } isDismissible={ false }>
					{ state.notice.message }
				</Notice>
			) }
			<fieldset disabled={ state.busy } className="content-for-agents-fields">
				<legend className="screen-reader-text">{ title }</legend>
				{ children }
				<Button
					variant="primary"
					onClick={ () => void state.save() }
					isBusy={ state.busy }
					disabled={ state.busy }
				>
					{ sprintf(
						/* translators: %s: Settings section name. */
						__( 'Save %s', 'content-for-agents' ),
						title
					) }
				</Button>
			</fieldset>
		</section>
	);
}
