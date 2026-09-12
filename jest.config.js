/**
 * Jest configuration for the JavaScript suite.
 *
 * The WordPress preset brings the jsdom environment and the global setup.
 * Tests live under tests/js so they are never part of the shipped bundle.
 *
 * The Babel transform is declared here rather than through a root babel.config.js
 * on purpose: the project has no root Babel configuration because @wordpress/scripts
 * supplies its own during the build, and adding one would change build behaviour
 * as a side effect of adding tests.
 */

module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: __dirname,
	roots: [ '<rootDir>/tests/js' ],
	testMatch: [ '**/*.test.js' ],
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],
	moduleNameMapper: {
		'\\.css$': '<rootDir>/tests/js/style-mock.js',
	},
	transform: {
		'^.+\\.[jt]sx?$': [
			require.resolve( 'babel-jest' ),
			{
				presets: [
					require.resolve( '@wordpress/babel-preset-default' ),
				],
			},
		],
	},
};
