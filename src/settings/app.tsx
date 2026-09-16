import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { config } from './api';
import useSettings from './hooks/use-settings';
import About from './sections/about';
import AboutLinks from './sections/about-links';
import Categories from './sections/categories';
import Featured from './sections/featured';
import Resources from './sections/resources';

import type { ReactNode } from 'react';

export default function App() {
	const { data, setData, error, setAttempt, saveSettings } = useSettings();
	let content: ReactNode = <Spinner />;
	if ( error ) {
		content = (
			<Notice status="error" isDismissible={ false }>
				{ error }{ ' ' }
				<Button variant="secondary" onClick={ () => setAttempt( previous => previous + 1 ) }>
					{ __( 'Retry', 'content-for-agents' ) }
				</Button>
			</Notice>
		);
	} else if ( data ) {
		content = (
			<>
				<About settings={ data.settings } onSaved={ setData } saveSettings={ saveSettings } />
				<AboutLinks settings={ data.settings } onSaved={ setData } saveSettings={ saveSettings } />
				<Categories
					settings={ data.settings }
					categories={ data.categories_available }
					onSaved={ setData }
					saveSettings={ saveSettings }
				/>
				<Featured
					settings={ data.settings }
					resolved={ data.featured_posts_resolved }
					onSaved={ setData }
					saveSettings={ saveSettings }
				/>
				<Resources settings={ data.settings } onSaved={ setData } saveSettings={ saveSettings } />
			</>
		);
	}
	return (
		<div className="arc-settings">
			<h1>{ __( 'Content for Agents', 'content-for-agents' ) }</h1>
			<p>
				{ __( 'Choose what appears in your site’s agent discovery index.', 'content-for-agents' ) }{ ' ' }
				<a href={ config.llmsTxtUrl }>{ __( 'View /llms.txt', 'content-for-agents' ) }</a>
			</p>
			{ content }
		</div>
	);
}
