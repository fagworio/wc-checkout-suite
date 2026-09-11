/**
 * Condition builder tests.
 *
 * The acceptance for WCCS-032 names three things: readable rules, a preview of
 * the result, and messages about what contradicts itself. Each spec below checks
 * one of them through the interface the merchant uses, because a component that
 * computes the right sentence but never renders it has not delivered any of the
 * three.
 *
 * The vocabulary used here is the shape the server publishes, including a
 * reference source, so the specs also cover the surface that names another field.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ConditionBuilder from '../../../resources/admin/app/components/ConditionBuilder';

/**
 * Vocabulary in the shape `GET /field-types` publishes it.
 *
 * @type {import('../../../resources/admin/app/schema/types').ConditionVocabularyShape}
 */
const VOCABULARY = {
	operators: [
		{
			key: 'equals',
			label: 'is equal to',
			takesValue: true,
			valueTypes: [ 'string', 'number', 'boolean' ],
			sourceTypes: [ 'string', 'number', 'boolean' ],
			negated: false,
		},
		{
			key: 'contains',
			label: 'contains',
			takesValue: true,
			valueTypes: [ 'string' ],
			sourceTypes: [ 'string', 'list' ],
			negated: false,
		},
		{
			key: 'greater_than',
			label: 'is greater than',
			takesValue: true,
			valueTypes: [ 'number' ],
			sourceTypes: [ 'number' ],
			negated: false,
		},
		{
			key: 'is_empty',
			label: 'is empty',
			takesValue: false,
			valueTypes: [],
			sourceTypes: [ 'string', 'number', 'boolean', 'list' ],
			negated: false,
		},
	],
	sources: [
		{
			key: 'field',
			label: 'Another field',
			type: 'mixed',
			scope: 'client',
			isReference: true,
		},
		{
			key: 'country',
			label: 'Country',
			type: 'string',
			scope: 'client',
			isReference: false,
		},
		{
			key: 'cart_total',
			label: 'Cart total',
			type: 'number',
			scope: 'server',
			isReference: false,
		},
	],
	limits: { maxDepth: 8, maxNodes: 100 },
};

/**
 * Fields a rule may read.
 *
 * @type {{id: string, label: string}[]}
 */
const FIELDS = [
	{ id: 'billing_person_type', label: 'Person type' },
	{ id: 'billing_document', label: 'CNPJ' },
];

/**
 * Renders the builder with a change spy.
 *
 * @param {Object} overrides Props to override.
 * @return {any} Render result and the change spy.
 */
function renderBuilder( overrides = {} ) {
	const onChange = jest.fn();

	const result = render(
		<ConditionBuilder
			value={ {} }
			vocabulary={ VOCABULARY }
			fieldId="billing_document"
			fields={ FIELDS }
			onChange={ onChange }
			{ ...overrides }
		/>
	);

	return { ...result, onChange };
}

/**
 * The document a change spy was last called with.
 *
 * @param {any} onChange Change spy.
 * @return {any} Conditions document.
 */
function lastDocument( onChange ) {
	return onChange.mock.calls[ onChange.mock.calls.length - 1 ][ 0 ];
}

describe( 'ConditionBuilder', () => {
	describe( 'a field with no rule', () => {
		it( 'says the field is always shown and offers to change that', () => {
			renderBuilder();

			expect(
				screen.getByText( 'This field is always shown.' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Add a condition' } )
			).toBeEnabled();
		} );

		it( 'creates a rule that already names a source and an operator', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder();

			await user.click(
				screen.getByRole( 'button', { name: 'Add a condition' } )
			);

			const document = lastDocument( onChange );

			expect( document.visible.source ).toBe( 'field' );
			expect( document.visible.operator ).not.toBe( '' );
		} );

		it( 'refuses to build a rule before the vocabulary arrives', () => {
			// A rule written without a vocabulary would name a source and an
			// operator the server refuses, so the honest state while loading is a
			// disabled button and a sentence saying why.
			renderBuilder( { vocabulary: {} } );

			expect(
				screen.getByRole( 'button', { name: 'Add a condition' } )
			).toBeDisabled();
			expect(
				screen.getByText(
					'The list of sources and operators has not been loaded yet.'
				)
			).toBeInTheDocument();
		} );
	} );

	describe( 'reading a rule back', () => {
		it( 'shows the rule as one sentence', () => {
			renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			expect(
				screen.getByText( 'Country is equal to BR' )
			).toBeInTheDocument();
		} );

		it( 'names a referenced field by its label', () => {
			renderBuilder( {
				value: {
					visible: {
						source: 'field',
						field: 'billing_person_type',
						operator: 'equals',
						value: 'pj',
					},
				},
			} );

			expect(
				screen.getByText( 'Person type is equal to pj' )
			).toBeInTheDocument();
		} );

		it( 'does not treat a document the validator would not accept as a rule', () => {
			// A stored value is either a rule the validator accepted or nothing at
			// all, so a third possibility is damage: the builder reads it as "no
			// rule" instead of offering to save it back.
			renderBuilder( { value: { visible: 'nonsense' } } );

			expect(
				screen.getByText( 'This field is always shown.' )
			).toBeInTheDocument();
		} );
	} );

	describe( 'messages about a rule that contradicts itself', () => {
		it( 'says a comparison has nothing to compare against', () => {
			renderBuilder( {
				value: {
					visible: { source: 'country', operator: 'equals' },
				},
			} );

			expect(
				screen.getByText( 'This rule cannot be saved as it is' )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'"is equal to" needs something to compare against.'
				)
			).toBeInTheDocument();
		} );

		it( 'says a rule reads a field that is no longer there', () => {
			renderBuilder( {
				value: {
					visible: {
						source: 'field',
						field: 'billing_person_type',
						operator: 'is_empty',
					},
				},
				fields: [ { id: 'billing_document', label: 'CNPJ' } ],
			} );

			expect(
				screen.getByText(
					'This rule reads "billing_person_type", which is not a field any more.'
				)
			).toBeInTheDocument();
		} );

		it( 'is silent about a rule that is well formed', () => {
			renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			expect(
				screen.queryByText( 'This rule cannot be saved as it is' )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'editing a rule', () => {
		it( 'changes the operator to one that can read the new source', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			await user.selectOptions(
				screen.getByLabelText( 'Reads' ),
				'cart_total'
			);

			const leaf = lastDocument( onChange ).visible;

			expect( leaf.source ).toBe( 'cart_total' );
			expect( leaf.operator ).toBe( 'equals' );
			expect(
				( VOCABULARY.operators ?? [] ).find(
					( entry ) => entry.key === leaf.operator
				)?.sourceTypes
			).toContain( 'number' );
		} );

		it( 'drops the value when the operator stops asking for one', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			await user.selectOptions(
				screen.getByLabelText( 'Comparison' ),
				'is_empty'
			);

			const leaf = lastDocument( onChange ).visible;

			expect( leaf.operator ).toBe( 'is_empty' );
			expect( leaf ).not.toHaveProperty( 'value' );
		} );

		it( 'adds a second condition as a group of both', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder( {
				value: {
					visible: { source: 'country', operator: 'is_empty' },
				},
			} );

			await user.click(
				screen.getByRole( 'button', { name: 'Add a condition' } )
			);

			expect( lastDocument( onChange ).visible.all ).toHaveLength( 2 );
		} );

		it( 'clears the rule when the last condition is removed, keeping what it does not own', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder( {
				value: {
					evaluator: 'always',
					visible: {
						all: [ { source: 'country', operator: 'is_empty' } ],
					},
				},
			} );

			await user.click(
				screen.getByRole( 'button', {
					name: 'Remove this condition',
				} )
			);

			const document = lastDocument( onChange );

			expect( document ).not.toHaveProperty( 'visible' );
			expect( document.evaluator ).toBe( 'always' );
		} );

		it( 'clears the rule without leaving a key behind', async () => {
			const user = userEvent.setup();
			const { onChange } = renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			await user.click(
				screen.getByRole( 'button', {
					name: 'Always show this field',
				} )
			);

			expect( lastDocument( onChange ) ).toEqual( {} );
		} );
	} );

	describe( 'preview', () => {
		it( 'asks only for the values the rule reads', () => {
			renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			expect( screen.getByLabelText( 'Country' ) ).toBeInTheDocument();
			expect(
				screen.queryByLabelText( 'Cart total' )
			).not.toBeInTheDocument();
		} );

		it( 'decides with the values the merchant fills in', async () => {
			const user = userEvent.setup();

			renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			expect(
				screen.getByText(
					'With these values the rule does not match, so the field is hidden.'
				)
			).toBeInTheDocument();

			await user.type( screen.getByLabelText( 'Country' ), 'BR' );

			expect(
				screen.getByText(
					'With these values the rule matches, so the field is shown.'
				)
			).toBeInTheDocument();
		} );

		it( 'says what each comparison decided', async () => {
			const user = userEvent.setup();

			renderBuilder( {
				value: {
					visible: {
						source: 'country',
						operator: 'equals',
						value: 'BR',
					},
				},
			} );

			await user.type( screen.getByLabelText( 'Country' ), 'BR' );

			const result = screen
				.getByText(
					'With these values the rule matches, so the field is shown.'
				)
				.closest( '.wccs-conditions__preview-result' );

			expect(
				within( /** @type {HTMLElement} */ ( result ) ).getByText(
					'Country is equal to BR → matches'
				)
			).toBeInTheDocument();
		} );

		it( 'offers nothing to try while there is no rule', () => {
			renderBuilder();

			expect( screen.queryByText( 'Try it' ) ).not.toBeInTheDocument();
		} );
	} );
} );
