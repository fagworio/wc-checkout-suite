/**
 * Condition model tests.
 *
 * The module under test decides three things the rule editor is built on: how a
 * rule is put together, how it reads back, and what is wrong with it. Each spec
 * below pins one of those to a property that can be checked without a browser, so
 * a regression shows up as a failing assertion rather than as a merchant
 * discovering that a rule they wrote does something else.
 *
 * The vocabulary used here is the shape the server publishes, and the values are
 * the awkward ones on purpose: `0`, `''`, `false` and `[]`, which is where a
 * naive implementation of "is empty" and "is equal to" disagrees with the
 * pipeline.
 */

import {
	addChildAt,
	childrenOf,
	conflicts,
	depth,
	// The rule renderer is aliased because `describe` is also jest's own suite
	// function: importing it unaliased made every suite call below invoke the
	// renderer instead, and the file registered no tests at all.
	describe as readRule,
	emptyGroup,
	emptyLeaf,
	firstOperatorFor,
	formatValue,
	groupKind,
	isGroup,
	issues,
	operatorsFor,
	preview,
	references,
	removeAt,
	replaceAt,
	size,
} from '../../../resources/admin/app/schema/conditions';

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
			key: 'not_equals',
			label: 'is not equal to',
			takesValue: true,
			valueTypes: [ 'string', 'number', 'boolean' ],
			sourceTypes: [ 'string', 'number', 'boolean' ],
			negated: true,
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
		{
			key: 'is_not_empty',
			label: 'is not empty',
			takesValue: false,
			valueTypes: [],
			sourceTypes: [ 'string', 'number', 'boolean', 'list' ],
			negated: true,
		},
		{
			key: 'in',
			label: 'is one of',
			takesValue: true,
			valueTypes: [ 'list' ],
			sourceTypes: [ 'string', 'number' ],
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
			key: 'customer_logged_in',
			label: 'Customer is logged in',
			type: 'boolean',
			scope: 'server',
			isReference: false,
		},
		{
			key: 'cart_items',
			label: 'Products in the cart',
			type: 'list',
			scope: 'server',
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
 * A rule that hides a field outside Brazil.
 *
 * @type {import('../../../resources/admin/app/schema/conditions').ConditionNode}
 */
const RULE = {
	all: [
		{ source: 'country', operator: 'not_equals', value: 'BR' },
		{ source: 'cart_total', operator: 'greater_than', value: 100 },
	],
};

/**
 * The operators and sources of the fixture.
 *
 * The vocabulary type marks both lists optional, because a catalogue that failed
 * to publish them is a real possibility the server has to be able to express. The
 * fixture declares them in full, and these two constants say so once instead of
 * at every use.
 */
const OPERATORS = VOCABULARY.operators ?? [];
const SOURCES = VOCABULARY.sources ?? [];

describe( 'condition model', () => {
	describe( 'shape', () => {
		it( 'reads a group as a group and a leaf as a leaf', () => {
			expect( isGroup( RULE ) ).toBe( true );
			expect(
				isGroup( {
					source: 'country',
					operator: 'equals',
					value: 'BR',
				} )
			).toBe( false );
		} );

		it( 'does not read an empty object as a group', () => {
			// `{}` is how a field with no conditions is stored, and reading it as
			// a group would render an editor for a rule that does not exist.
			expect( isGroup( {} ) ).toBe( false );
			expect( groupKind( {} ) ).toBe( '' );
		} );

		it( 'names the kind of a group and its children', () => {
			expect( groupKind( { any: [] } ) ).toBe( 'any' );
			expect( childrenOf( RULE ) ).toHaveLength( 2 );
			expect(
				childrenOf( { source: 'country', operator: 'is_empty' } )
			).toEqual( [] );
		} );
	} );

	describe( 'vocabulary', () => {
		it( 'offers only the operators that can read the source type', () => {
			const keys = operatorsFor( VOCABULARY, 'cart_total' ).map(
				( entry ) => entry.key
			);

			expect( keys ).toContain( 'greater_than' );
			expect( keys ).not.toContain( 'contains' );
		} );

		it( 'offers every operator for a reference, whose type another field decides', () => {
			expect( operatorsFor( VOCABULARY, 'field' ) ).toHaveLength(
				OPERATORS.length
			);
		} );

		it( 'chooses an operator that can read the source rather than the first one', () => {
			const list = SOURCES.find(
				( entry ) => 'cart_items' === entry.key
			);

			expect( OPERATORS[ 0 ].key ).toBe( 'equals' );
			expect( firstOperatorFor( VOCABULARY, list )?.key ).toBe(
				'contains'
			);
		} );

		it( 'builds a leaf with a source and an operator already chosen', () => {
			const leaf = emptyLeaf( VOCABULARY );

			expect( leaf.source ).toBe( 'field' );
			expect( leaf.operator ).not.toBe( '' );
			expect( leaf ).toHaveProperty( 'field' );
		} );

		it( 'builds a group of the kind it is asked for, holding one leaf', () => {
			expect( groupKind( emptyGroup( VOCABULARY, 'any' ) ) ).toBe(
				'any'
			);
			expect( childrenOf( emptyGroup( VOCABULARY ) ) ).toHaveLength( 1 );
		} );
	} );

	describe( 'reading a rule back', () => {
		it( 'reads a leaf as a sentence with the published labels', () => {
			expect(
				readRule(
					{ source: 'country', operator: 'not_equals', value: 'BR' },
					VOCABULARY
				)
			).toBe( 'Country is not equal to BR' );
		} );

		it( 'reads a group as one sentence joined by and', () => {
			expect( readRule( RULE, VOCABULARY ) ).toBe(
				'All of: Country is not equal to BR and Cart total is greater than 100'
			);
		} );

		it( 'does not wrap a group of one in a heading that says nothing', () => {
			expect(
				readRule(
					{
						any: [
							{
								source: 'country',
								operator: 'equals',
								value: 'BR',
							},
						],
					},
					VOCABULARY
				)
			).toBe( 'Country is equal to BR' );
		} );

		it( 'names a referenced field by its label', () => {
			expect(
				readRule(
					{
						source: 'field',
						field: 'billing_person_type',
						operator: 'equals',
						value: 'pj',
					},
					VOCABULARY,
					( field ) =>
						'billing_person_type' === field ? 'Person type' : field
				)
			).toBe( 'Person type is equal to pj' );
		} );

		it( 'says a value is empty rather than printing nothing', () => {
			expect( formatValue( '' ) ).toBe( '(empty)' );
			expect( formatValue( false ) ).toBe( 'no' );
			expect( formatValue( [ 'BR', 'PT' ] ) ).toBe( 'BR, PT' );
		} );
	} );

	describe( 'counting', () => {
		it( 'counts the nodes of a rule', () => {
			expect( size( RULE ) ).toBe( 3 );
			expect( size( { source: 'country', operator: 'is_empty' } ) ).toBe(
				1
			);
		} );

		it( 'measures depth', () => {
			expect( depth( RULE ) ).toBe( 2 );
			expect( depth( { all: [ { any: [ RULE ] } ] } ) ).toBe( 4 );
		} );

		it( 'collects the fields a rule reads', () => {
			expect(
				references( {
					any: [
						{
							source: 'field',
							field: 'a',
							operator: 'is_empty',
						},
						{
							all: [
								{
									source: 'field',
									field: 'b',
									operator: 'is_empty',
								},
								{ source: 'country', operator: 'is_empty' },
							],
						},
					],
				} )
			).toEqual( [ 'a', 'b' ] );
		} );
	} );

	describe( 'editing a tree', () => {
		it( 'replaces a node without changing the tree it was given', () => {
			const before = JSON.stringify( RULE );
			const next = replaceAt( RULE, [ 1 ], {
				source: 'country',
				operator: 'is_empty',
			} );

			expect( JSON.stringify( RULE ) ).toBe( before );
			expect( readRule( next, VOCABULARY ) ).toBe(
				'All of: Country is not equal to BR and Country is empty'
			);
		} );

		it( 'replaces the whole tree when the path is empty', () => {
			const leaf = { source: 'country', operator: 'is_empty' };

			expect( replaceAt( RULE, [], leaf ) ).toBe( leaf );
		} );

		it( 'adds a child to the group at a path', () => {
			const next = addChildAt( RULE, [], {
				source: 'country',
				operator: 'is_empty',
			} );

			expect( childrenOf( next ) ).toHaveLength( 3 );
		} );

		it( 'removes a node, and the group that losing it would leave empty', () => {
			const nested = {
				all: [
					{ source: 'country', operator: 'is_empty' },
					{
						any: [
							{
								source: 'cart_total',
								operator: 'greater_than',
								value: 1,
							},
						],
					},
				],
			};

			const removed = removeAt( nested, [ 1, 0 ] );

			// The nested group lost its only child, so it goes with it: what is
			// left is the sibling that was already there.
			expect( removed ).not.toBeNull();

			const next =
				/** @type {import('../../../resources/admin/app/schema/conditions').ConditionNode} */ (
					removed
				);

			expect( childrenOf( next ) ).toHaveLength( 1 );
			expect( readRule( next, VOCABULARY ) ).toBe( 'Country is empty' );
		} );

		it( 'clears the rule when the last condition is removed from the root', () => {
			// A group with nothing in it is refused by the server
			// (`empty_condition_group`), so the cascade has to reach the root:
			// removing the last condition means the field is always shown.
			expect(
				removeAt(
					{ all: [ { source: 'country', operator: 'is_empty' } ] },
					[ 0 ]
				)
			).toBeNull();
			expect( removeAt( RULE, [] ) ).toBeNull();
		} );
	} );

	describe( 'what is wrong with a rule', () => {
		it( 'is silent about a rule that is well formed', () => {
			expect( issues( RULE, VOCABULARY ) ).toEqual( [] );
		} );

		it( 'says when a comparison has nothing to compare against', () => {
			expect(
				issues( { source: 'country', operator: 'equals' }, VOCABULARY )
			).toEqual( [
				'"is equal to" needs something to compare against.',
			] );
		} );

		it( 'treats 0, "0" and false as values, the way the pipeline does', () => {
			for ( const value of [ 0, '0', false ] ) {
				expect(
					issues(
						{ source: 'cart_total', operator: 'equals', value },
						VOCABULARY
					)
				).toEqual( [] );
			}

			expect(
				issues(
					{ source: 'cart_total', operator: 'equals', value: '' },
					VOCABULARY
				)
			).toHaveLength( 1 );
		} );

		it( 'says when an operator that asks nothing was given a value', () => {
			expect(
				issues(
					{ source: 'country', operator: 'is_empty', value: 'BR' },
					VOCABULARY
				)
			).toEqual( [
				'"is empty" asks a question about what is there and takes no value.',
			] );
		} );

		it( 'reports an operator that cannot read the source it is pointed at', () => {
			// "Cart total is greater than 5" is one thing; "Country is greater than
			// 5" is a comparison with no meaning, and the value being a number does
			// not make it meaningful. The server refuses it with the same code.
			expect(
				issues(
					{ source: 'country', operator: 'greater_than', value: 5 },
					VOCABULARY
				)
			).toEqual( [
				'"is greater than" cannot read Country, which holds a value of type string.',
			] );
		} );

		it( 'does not judge what a reference holds, which only the document can resolve', () => {
			expect(
				issues(
					{
						source: 'field',
						field: 'billing_person_type',
						operator: 'greater_than',
						value: 5,
					},
					VOCABULARY
				)
			).toEqual( [] );
		} );

		it( 'reports a source and an operator the vocabulary does not have', () => {
			expect(
				issues( { source: 'nope', operator: 'equals' }, VOCABULARY )
			).toEqual( [ '"nope" is not something a rule can read.' ] );

			expect(
				issues( { source: 'country', operator: 'nope' }, VOCABULARY )
			).toEqual( [ '"nope" is not an operator.' ] );
		} );

		it( 'reports a reference with no field named', () => {
			expect(
				issues( { source: 'field', operator: 'is_empty' }, VOCABULARY )
			).toEqual( [ 'Another field is chosen but no field is named.' ] );
		} );

		it( 'reports a field that reads itself', () => {
			const rule = {
				source: 'field',
				field: 'billing_document',
				operator: 'is_empty',
			};

			expect(
				issues( rule, VOCABULARY, { fieldId: 'billing_document' } )
			).toEqual( [ 'A field cannot depend on itself.' ] );
		} );

		it( 'reports a reference to a field that is no longer there', () => {
			const rule = {
				source: 'field',
				field: 'gone',
				operator: 'is_empty',
			};

			expect(
				issues( rule, VOCABULARY, {
					knownFields: [ 'country_field' ],
					fieldLabel: () => 'Removed field',
				} )
			).toEqual( [
				'This rule reads "Removed field", which is not a field any more.',
			] );
		} );

		it( 'does not report every reference as missing when it knows no fields', () => {
			// The caller that has no field list must not get messages about fields
			// it never supplied.
			expect(
				issues(
					{ source: 'field', field: 'a', operator: 'is_empty' },
					VOCABULARY
				)
			).toEqual( [] );
		} );

		it( 'reports a group with nothing in it', () => {
			expect( issues( { all: [] }, VOCABULARY ) ).toEqual( [
				'A group with no conditions in it says nothing.',
			] );
		} );

		it( 'reports a rule that is past the published limits', () => {
			const deep = {
				all: [ { any: [ { all: [ RULE ] } ] } ],
			};
			const messages = issues( deep, {
				...VOCABULARY,
				limits: { maxDepth: 3, maxNodes: 100 },
			} );

			expect( messages.join( ' ' ) ).toContain( 'levels deep' );
		} );
	} );

	describe( 'preview', () => {
		it( 'matches when every part of the rule matches', () => {
			const result = preview(
				RULE,
				{ country: 'PT', cart_total: 150 },
				VOCABULARY
			);

			expect( result.matches ).toBe( true );
			expect( result.because ).toHaveLength( 2 );
		} );

		it( 'does not match when one part of an "all" does not', () => {
			expect(
				preview( RULE, { country: 'BR', cart_total: 150 }, VOCABULARY )
					.matches
			).toBe( false );
		} );

		it( 'compares a cart total as a number and not as text', () => {
			// The context is typed to hold the numbers it should hold, and the
			// sample here is deliberately text: that is what a form input produces
			// before it is parsed, and comparing "100" with 9 as text is how a rule
			// about a cart total goes wrong.
			const context =
				/** @type {import('../../../resources/admin/app/schema/conditions').PreviewContext} */ (
					/** @type {unknown} */ ( { cart_total: '100' } )
				);

			expect(
				preview(
					{
						source: 'cart_total',
						operator: 'greater_than',
						value: 9,
					},
					context,
					VOCABULARY
				).matches
			).toBe( true );
		} );

		it( 'counts 0, "0" and false as present, and "" and an empty list as absent', () => {
			/**
			 * Whether a value counts as empty.
			 *
			 * @param {any} value Value to test.
			 * @return {boolean} Whether the rule matched.
			 */
			const empty = ( value ) =>
				preview(
					{ source: 'field', field: 'x', operator: 'is_empty' },
					{ fields: { x: value } },
					VOCABULARY
				).matches;

			expect( empty( 0 ) ).toBe( false );
			expect( empty( '0' ) ).toBe( false );
			expect( empty( false ) ).toBe( false );
			expect( empty( '' ) ).toBe( true );
			expect( empty( [] ) ).toBe( true );
			expect( empty( undefined ) ).toBe( true );
		} );

		it( 'finds a value inside a text and inside a list', () => {
			expect(
				preview(
					{ source: 'country', operator: 'contains', value: 'RA' },
					{ country: 'BRAZIL' },
					VOCABULARY
				).matches
			).toBe( true );
			expect(
				preview(
					{
						source: 'cart_items',
						operator: 'contains',
						value: 'shirt',
					},
					{ cart_items: [ 'hat', 'shirt' ] },
					VOCABULARY
				).matches
			).toBe( true );
		} );

		it( 'reads a list of accepted values', () => {
			expect(
				preview(
					{
						source: 'country',
						operator: 'in',
						value: [ 'BR', 'PT' ],
					},
					{ country: 'PT' },
					VOCABULARY
				).matches
			).toBe( true );
		} );

		it( 'matches nothing when the operator is not in the vocabulary', () => {
			const result = preview(
				{ source: 'country', operator: 'nope', value: 'BR' },
				{ country: 'BR' },
				VOCABULARY
			);

			expect( result.matches ).toBe( false );
			expect( result.because ).toEqual( [] );
		} );

		it( 'does not match an empty group, which says nothing', () => {
			expect( preview( { all: [] }, {}, VOCABULARY ).matches ).toBe(
				false
			);
		} );

		it( 'reads a missing sample as empty rather than as false', () => {
			const result = preview(
				{ source: 'customer_logged_in', operator: 'is_empty' },
				{},
				VOCABULARY
			);

			expect( result.matches ).toBe( true );
		} );
	} );
} );

/**
 * Two comparisons that cannot both hold.
 *
 * §6.9 asks for a conflict to be shown rather than refused, and a rule nothing can satisfy is the
 * failure the warning exists for: a field that never appears, a checkout that never runs. These
 * specs pin what counts as a conflict and — as importantly — what does not, because a report that
 * cried wolf would be turned off and the real one would go with it.
 */
describe( 'conflicts', () => {
	/** The operators the contradictions need, beside the sources they read. */
	const vocabulary = {
		operators: [
			...( VOCABULARY.operators ?? [] ),
			{
				key: 'less_than',
				label: 'is less than',
				takesValue: true,
				valueTypes: [ 'number' ],
				sourceTypes: [ 'number' ],
				negated: false,
			},
			{
				key: 'not_contains',
				label: 'does not contain',
				takesValue: true,
				valueTypes: [ 'string' ],
				sourceTypes: [ 'string', 'list' ],
				negated: true,
			},
			{
				key: 'not_in',
				label: 'is not one of',
				takesValue: true,
				valueTypes: [ 'list' ],
				sourceTypes: [ 'string', 'number' ],
				negated: true,
			},
		],
		// The sources the contradictions are written about. Two of them are the newest the
		// vocabulary has, because a rule about a tag or a subtotal is exactly where a merchant
		// writes two bounds and means one.
		sources: [
			...( VOCABULARY.sources ?? [] ),
			{
				key: 'state',
				label: 'State',
				type: 'string',
				scope: 'client',
				isReference: false,
			},
			{
				key: 'cart_tags',
				label: 'Product tags in the cart',
				type: 'list',
				scope: 'server',
				isReference: false,
			},
			{
				key: 'cart_quantity',
				label: 'Items in the cart',
				type: 'number',
				scope: 'server',
				isReference: false,
			},
			{
				key: 'cart_subtotal',
				label: 'Cart subtotal, before shipping',
				type: 'number',
				scope: 'server',
				isReference: false,
			},
		],
	};

	it( 'reports two different values asked of one source', () => {
		const found = conflicts(
			{
				all: [
					{ source: 'country', operator: 'equals', value: 'BR' },
					{ source: 'country', operator: 'equals', value: 'PT' },
				],
			},
			vocabulary
		);

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ].source ).toBe( 'country' );
		expect( found[ 0 ].message ).toContain( 'cannot both be true' );
	} );

	it( 'says nothing about the same value asked twice', () => {
		expect(
			conflicts(
				{
					all: [
						{ source: 'country', operator: 'equals', value: 'BR' },
						{ source: 'country', operator: 'equals', value: 'BR' },
					],
				},
				vocabulary
			)
		).toEqual( [] );
	} );

	it( 'reports a value and its negation', () => {
		expect(
			conflicts(
				{
					all: [
						{ source: 'state', operator: 'equals', value: 'MG' },
						{
							source: 'state',
							operator: 'not_equals',
							value: 'MG',
						},
					],
				},
				vocabulary
			)
		).toHaveLength( 1 );
	} );

	it( 'reports a list that must contain and must not contain the same entry', () => {
		expect(
			conflicts(
				{
					all: [
						{
							source: 'cart_tags',
							operator: 'contains',
							value: 'promocao',
						},
						{
							source: 'cart_tags',
							operator: 'not_contains',
							value: 'promocao',
						},
					],
				},
				vocabulary
			)
		).toHaveLength( 1 );
	} );

	it( 'reports a source asked to be both empty and present', () => {
		expect(
			conflicts(
				{
					all: [
						{ source: 'country', operator: 'is_empty' },
						{ source: 'country', operator: 'is_not_empty' },
					],
				},
				vocabulary
			)
		).toHaveLength( 1 );
	} );

	it( 'reports a bound no number can satisfy', () => {
		expect(
			conflicts(
				{
					all: [
						{
							source: 'cart_subtotal',
							operator: 'greater_than',
							value: 500,
						},
						{
							source: 'cart_subtotal',
							operator: 'less_than',
							value: 100,
						},
					],
				},
				vocabulary
			)
		).toHaveLength( 1 );
	} );

	it( 'reports a value that the bound excludes', () => {
		expect(
			conflicts(
				{
					all: [
						{
							source: 'cart_quantity',
							operator: 'greater_than',
							value: 5,
						},
						{
							source: 'cart_quantity',
							operator: 'equals',
							value: 5,
						},
					],
				},
				vocabulary
			)
		).toHaveLength( 1 );
	} );

	it( 'says nothing about two bounds that overlap', () => {
		expect(
			conflicts(
				{
					all: [
						{
							source: 'cart_subtotal',
							operator: 'greater_than',
							value: 100,
						},
						{
							source: 'cart_subtotal',
							operator: 'less_than',
							value: 500,
						},
					],
				},
				vocabulary
			)
		).toEqual( [] );
	} );

	it( 'says nothing about two different sources', () => {
		expect(
			conflicts(
				{
					all: [
						{ source: 'country', operator: 'equals', value: 'BR' },
						{ source: 'state', operator: 'equals', value: 'MG' },
					],
				},
				vocabulary
			)
		).toEqual( [] );
	} );

	it( 'says nothing inside an any, where the alternatives may hold', () => {
		expect(
			conflicts(
				{
					any: [
						{ source: 'country', operator: 'equals', value: 'BR' },
						{ source: 'country', operator: 'equals', value: 'PT' },
					],
				},
				vocabulary
			)
		).toEqual( [] );
	} );

	it( 'reads a contradiction nested inside an all of the same group', () => {
		const found = conflicts(
			{
				all: [
					{ source: 'country', operator: 'equals', value: 'BR' },
					{
						all: [
							{
								source: 'country',
								operator: 'equals',
								value: 'PT',
							},
						],
					},
				],
			},
			vocabulary
		);

		// The nested group is one `all`; the contradiction between the first leaf and the
		// nested one is outside the scope this report claims, and it says so by staying quiet.
		expect( found ).toEqual( [] );
	} );

	it( 'finds the contradiction inside a nested all of its own', () => {
		const found = conflicts(
			{
				any: [
					{ source: 'state', operator: 'equals', value: 'SP' },
					{
						all: [
							{
								source: 'country',
								operator: 'equals',
								value: 'BR',
							},
							{
								source: 'country',
								operator: 'equals',
								value: 'PT',
							},
						],
					},
				],
			},
			vocabulary
		);

		expect( found ).toHaveLength( 1 );
		expect( found[ 0 ].source ).toBe( 'country' );
	} );

	it( 'says nothing about a reference, whose value only the document knows', () => {
		expect(
			conflicts(
				{
					all: [
						{
							source: 'field',
							field: 'tipo_pessoa',
							operator: 'equals',
							value: 'pj',
						},
						{
							source: 'field',
							field: 'tipo_pessoa',
							operator: 'equals',
							value: 'pf',
						},
					],
				},
				vocabulary
			)
		).toEqual( [] );
	} );

	it( 'says nothing about a rule with one part', () => {
		expect(
			conflicts(
				{ source: 'country', operator: 'equals', value: 'BR' },
				vocabulary
			)
		).toEqual( [] );
	} );
} );
