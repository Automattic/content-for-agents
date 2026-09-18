export interface AboutLink {
	id: string;
	title: string;
	url: string;
	description: string;
}
export interface ResourceBlock {
	id: string;
	title: string;
	body: string;
}
export interface Settings {
	site_summary: string;
	about_description: string;
	about_links: AboutLink[];
	category_ids: number[];
	featured_posts: number[];
	additional_resources_blocks: ResourceBlock[];
}
export interface ResolvedPost {
	id: number;
	title: string;
	excerpt: string;
}
export interface Category {
	id: number;
	name: string;
	count: number;
}
export interface SearchPost {
	id: number;
	title: { rendered: string };
	excerpt: { rendered: string; protected: boolean };
}
export interface SettingsResponse {
	settings: Settings;
	featured_posts_resolved: ResolvedPost[];
	categories_available: Category[];
}
export interface SectionProps {
	saveSettings: ( patch: Partial< Settings > ) => Promise< SettingsResponse >;
	settings: Settings;
	onSaved: ( response: SettingsResponse ) => void;
}
export interface SaveState {
	busy: boolean;
	notice: { status: 'success' | 'error'; message: string } | null;
	save: () => Promise< void >;
}
declare global {
	interface Window {
		contentForAgentsSettings: {
			llmsTxtUrl: string;
			permalinkSettingsUrl: string;
			prettyPermalinksEnabled: boolean;
			restUrl: string;
			nonce: string;
		};
	}
}
