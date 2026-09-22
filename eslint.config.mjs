import plugin from '@automattic/eslint-plugin-wpvip';
import { defineConfig } from 'eslint/config';

export default defineConfig( [
	...plugin.configs.recommended,
	{
		ignores: [ '*.php', '**/build/', '**/node_modules/', '**/vendor/' ],
	},
	{
		files: [ 'tests/frontend/**/*.test.js' ],
		languageOptions: {
			globals: {
				beforeEach: 'readonly',
				afterEach: 'readonly',
				document: 'readonly',
				expect: 'readonly',
				jest: 'readonly',
				test: 'readonly',
			},
		},
	},
] );
