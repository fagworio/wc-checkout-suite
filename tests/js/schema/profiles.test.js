/**
 * The checkouts a store runs.
 *
 * §3.4 gives the store profiles, §6.3 gives the screen and §6.9 gives the two mechanisms that
 * decide which one a cart gets. This suite pins the rules that keep the screen from writing
 * something the store will refuse, and from hiding a decision the merchant has to make.
 */

import {
	checkoutSections,
	compositionOf,
	compositionSections,
	isCheckoutSection,
	createProfile,
	fallbacks,
	hasRule,
	minimalChecklist,
	moveProfile,
	needsChecklist,
	nextPriority,
	orderedProfiles,
	overlapsFor,
	profilesOf,
	readableProfiles,
	removeProfile,
	setFallback,
	uniqueProfileId,
	updateProfile,
	withComposition,
	withProfiles,
} from '../../../resources/admin/app/schema/profiles';

/**
 * A container.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Section.
 */
function section( overrides = {} ) {
	return {
		id: 'contato',
		title: 'Contato',
		description: '',
		position: 10,
		location: 'billing',
		areas: [ 'checkout' ],
		...overrides,
	};
}

/**
 * A profile.
 *
 * @param {Object} overrides Values to override.
 * @return {any} Profile.
 */
function profile( overrides = {} ) {
	return {
		id: 'digital',
		name: 'Checkout digital',
		enabled: true,
		source: 'woocommerce_current',
		priority: 10,
		fallback: false,
		conditions: {},
		sections: [ section() ],
		presentation: {},
		...overrides,
	};
}

/** The condition vocabulary the preview needs. */
const VOCABULARY = {
	operators: [
		{
			key: 'contains',
			label: 'contains',
			takesValue: true,
			valueTypes: [ 'string' ],
			sourceTypes: [ 'string', 'list' ],
			negated: false,
		},
	],
	sources: [
		{
			key: 'cart_categories',
			label: 'Categories in the cart',
			type: 'list',
			scope: 'server',
			isReference: false,
		},
	],
};

describe( 'profiles', () => {
	test( 'a document written before profiles existed has none', () => {
		expect( profilesOf( {} ) ).toEqual( [] );
		expect( profilesOf( null ) ).toEqual( [] );
		expect(
			profilesOf( /** @type {any} */ ( { profiles: null } ) )
		).toEqual( [] );
	} );

	test( 'a document carries its profiles without losing anything else', () => {
		const document = withProfiles(
			{ revision: 4, fields: [], sections: [], settings: {} },
			[ profile() ]
		);

		expect( document.revision ).toBe( 4 );
		expect( profilesOf( document ) ).toHaveLength( 1 );
	} );

	test( 'an entry that is not a composition is not shown', () => {
		expect(
			readableProfiles( { profiles: [ profile(), 'digital', null, {} ] } )
		).toHaveLength( 1 );
	} );

	describe( 'identity', () => {
		test( 'a name becomes a readable id', () => {
			expect( uniqueProfileId( [], 'Produtos químicos' ) ).toBe(
				'produtos_quimicos'
			);
		} );

		test( 'a name that collides is numbered, because two ids are two answers to one name', () => {
			expect( uniqueProfileId( [ profile() ], 'Checkout digital' ) ).toBe(
				'checkout_digital'
			);
			expect(
				uniqueProfileId(
					[ profile( { id: 'checkout_digital' } ) ],
					'Checkout digital'
				)
			).toBe( 'checkout_digital_2' );
		} );

		test( 'a name with nothing usable still gets an id', () => {
			expect( uniqueProfileId( [], '!!!' ) ).toBe( 'checkout' );
		} );

		test( 'a change to a profile never renames it', () => {
			const changed = updateProfile( [ profile() ], 'digital', {
				id: 'outro',
				name: 'Outro',
			} );

			expect( changed[ 0 ].id ).toBe( 'digital' );
			expect( changed[ 0 ].name ).toBe( 'Outro' );
		} );
	} );

	describe( 'creating one', () => {
		const document = {
			sections: [
				section( { id: 'contato', location: 'billing' } ),
				section( { id: 'entrega', location: 'shipping' } ),
				section( {
					id: 'documentos_do_email',
					location: 'order',
					areas: [ 'customer_email' ],
				} ),
			],
		};

		test( 'a new checkout starts from the current one', () => {
			const created = createProfile(
				document,
				'Checkout digital',
				'woocommerce_current'
			);

			expect( created.sections.map( ( entry ) => entry.id ) ).toEqual( [
				'contato',
				'entrega',
			] );
			expect( created.conditions ).toEqual( {} );
			expect( created.fallback ).toBe( false );
			expect( created.enabled ).toBe( true );
		} );

		test( 'the containers of other areas are not part of a checkout', () => {
			expect(
				checkoutSections( document ).map( ( entry ) => entry.id )
			).toEqual( [ 'contato', 'entrega' ] );
		} );

		test( 'a duplication copies the profile it was told to copy', () => {
			const from = profile( {
				sections: [ section( { id: 'so_essencial' } ) ],
			} );
			const created = createProfile(
				document,
				'Cópia',
				'duplicate_profile',
				from
			);

			expect( created.sections.map( ( entry ) => entry.id ) ).toEqual( [
				'so_essencial',
			] );
		} );

		test( 'a minimal checkout keeps only what it cannot do without', () => {
			const created = createProfile( document, 'Mínimo', 'minimal' );

			expect( created.sections.map( ( entry ) => entry.id ) ).toEqual( [
				'contato',
			] );
			expect( needsChecklist( created ) ).toBe( true );
			expect(
				needsChecklist(
					createProfile( document, 'Outro', 'woocommerce_current' )
				)
			).toBe( false );
		} );

		test( 'a new checkout is considered before the ones that already exist', () => {
			expect( nextPriority( [] ) ).toBe( 10 );
			expect( nextPriority( [ profile( { priority: 30 } ) ] ) ).toBe(
				40
			);
		} );
	} );

	describe( 'the fallback', () => {
		test( 'marking one unmarks the other, because two have no answer', () => {
			const profiles = [
				profile( { id: 'um', fallback: true } ),
				profile( { id: 'dois' } ),
			];

			const marked = setFallback( profiles, 'dois' );

			expect( fallbacks( marked ).map( ( entry ) => entry.id ) ).toEqual(
				[ 'dois' ]
			);
		} );

		test( 'a store may have no fallback profile at all', () => {
			expect( fallbacks( setFallback( [ profile() ], '' ) ) ).toEqual(
				[]
			);
		} );

		test( 'the only fallback cannot be deleted', () => {
			const profiles = [
				profile( { id: 'padrao', fallback: true } ),
				profile( { id: 'digital' } ),
			];

			const removed = removeProfile( profiles, 'padrao' );

			expect( removed.refusal ).toBe( 'only_fallback' );
			expect( removed.profiles ).toHaveLength( 2 );
		} );

		test( 'a fallback that is not the only one can be deleted', () => {
			const profiles = [
				profile( { id: 'um', fallback: true } ),
				profile( { id: 'dois', fallback: true } ),
			];

			const removed = removeProfile( profiles, 'dois' );

			expect( removed.refusal ).toBeNull();
			expect( removed.profiles.map( ( entry ) => entry.id ) ).toEqual( [
				'um',
			] );
		} );

		test( 'a profile that is not the fallback is deleted without ceremony', () => {
			const removed = removeProfile( [ profile() ], 'digital' );

			expect( removed.refusal ).toBeNull();
			expect( removed.profiles ).toEqual( [] );
		} );
	} );

	describe( 'the order they are considered in', () => {
		test( 'priority first, and the declaration order when two agree', () => {
			const ordered = orderedProfiles( [
				profile( { id: 'a', priority: 10 } ),
				profile( { id: 'b', priority: 30 } ),
				profile( { id: 'c', priority: 10 } ),
			] );

			expect( ordered.map( ( entry ) => entry.id ) ).toEqual( [
				'b',
				'a',
				'c',
			] );
		} );

		test( 'moving one rewrites the priorities the screen shows', () => {
			const moved = moveProfile(
				[
					profile( { id: 'a', priority: 30 } ),
					profile( { id: 'b', priority: 20 } ),
				],
				'b',
				-1
			);

			expect( moved.map( ( entry ) => entry.id ) ).toEqual( [
				'b',
				'a',
			] );
			expect(
				orderedProfiles( moved ).map( ( entry ) => entry.id )
			).toEqual( [ 'b', 'a' ] );
		} );

		test( 'moving past the end changes nothing', () => {
			const profiles = [ profile( { id: 'a' } ), profile( { id: 'b' } ) ];

			expect( moveProfile( profiles, 'b', 1 ) ).toEqual( profiles );
			expect( moveProfile( profiles, 'a', -1 ) ).toEqual( profiles );
		} );
	} );

	describe( 'the overlap §6.9 asks to be shown', () => {
		const store = [
			profile( {
				id: 'restrito',
				priority: 30,
				conditions: {
					source: 'cart_categories',
					operator: 'contains',
					value: 'quimicos',
				},
			} ),
			profile( {
				id: 'digital',
				priority: 20,
				conditions: {
					source: 'cart_categories',
					operator: 'contains',
					value: 'quimicos',
				},
			} ),
			profile( {
				id: 'outro',
				priority: 10,
				conditions: {
					source: 'cart_categories',
					operator: 'contains',
					value: 'livros',
				},
			} ),
		];

		test( 'two profiles that match the same cart are reported', () => {
			const pairs = overlapsFor(
				store,
				{ cart_categories: [ 'quimicos' ] },
				VOCABULARY
			);

			expect( pairs ).toHaveLength( 1 );
			expect( pairs[ 0 ].profile.id ).toBe( 'restrito' );
			expect( pairs[ 0 ].other.id ).toBe( 'digital' );
		} );

		test( 'a cart only one profile answers has no overlap', () => {
			expect(
				overlapsFor(
					store,
					{ cart_categories: [ 'livros' ] },
					VOCABULARY
				)
			).toHaveLength( 0 );
		} );

		test( 'a profile with no rule is the checkout for every cart it can be selected for', () => {
			const pairs = overlapsFor(
				[ profile( { id: 'sempre' } ), profile( { id: 'sempre2' } ) ],
				{},
				VOCABULARY
			);

			expect( pairs ).toHaveLength( 1 );
		} );

		test( 'a profile that is off is not a candidate', () => {
			const pairs = overlapsFor(
				[
					profile( { id: 'a', enabled: false } ),
					profile( { id: 'b' } ),
				],
				{},
				VOCABULARY
			);

			expect( pairs ).toHaveLength( 0 );
		} );

		test( 'an empty rule is not a rule', () => {
			expect( hasRule( profile() ) ).toBe( false );
			expect(
				hasRule(
					profile( {
						conditions: {
							source: 'cart_categories',
							operator: 'contains',
							value: 'x',
						},
					} )
				)
			).toBe( true );
		} );
	} );

	describe( 'the minimal checkout', () => {
		const minimal = profile( { source: 'minimal' } );

		test( 'every requirement the store cannot answer for is reported, and so is a dropped field', () => {
			const checklist = minimalChecklist(
				minimal,
				[
					{
						id: 'licenca',
						label: 'Licença química',
						enabled: true,
						required: true,
						section: 'documentacao',
					},
				],
				{
					gateway: true,
					taxes: false,
					shipping: true,
					legal: true,
				}
			);

			expect( checklist.map( ( entry ) => entry.key ) ).toEqual( [
				'gateway',
				'taxes',
				'shipping',
				'integrations',
				'legal',
			] );
			expect(
				checklist
					.filter( ( entry ) => ! entry.met )
					.map( ( entry ) => entry.key )
			).toEqual( [ 'taxes', 'integrations' ] );
			expect( checklist[ 1 ].reason ).toContain( 'impostos' );
			// The merchant has to be told which field the composition left behind.
			expect( checklist[ 3 ].reason ).toContain( 'Licença química' );
		} );

		test( 'a required field the composition keeps is not a missing datum', () => {
			const checklist = minimalChecklist(
				minimal,
				[
					{
						id: 'contato_email',
						label: 'E-mail',
						enabled: true,
						required: true,
						section: 'contato',
					},
				],
				{
					gateway: true,
					taxes: true,
					shipping: true,
					legal: true,
				}
			);

			expect( checklist.every( ( entry ) => entry.met ) ).toBe( true );
		} );

		test( 'a store that answers for everything has nothing to fix', () => {
			const checklist = minimalChecklist( minimal, [], {
				gateway: true,
				taxes: true,
				shipping: true,
				legal: true,
			} );

			expect( checklist.every( ( entry ) => entry.met ) ).toBe( true );
		} );
	} );
	describe( 'the composition one checkout edits (§6.4)', () => {
		/** A document with a checkout container, a shared one and an account-only one. */
		const document = {
			revision: 7,
			fields: [],
			settings: {},
			sections: [
				section( { id: 'contato', position: 10 } ),
				section( {
					id: 'documentacao',
					title: 'Documentação',
					position: 20,
					areas: [ 'customer_account' ],
				} ),
			],
			profiles: [],
		};

		test( 'a container with no areas belongs to the checkout', () => {
			expect( isCheckoutSection( section( { areas: undefined } ) ) ).toBe(
				true
			);
			expect(
				isCheckoutSection(
					section( { areas: [ 'customer_account' ] } )
				)
			).toBe( false );
		} );

		test( 'without a profile the composition is the document itself', () => {
			expect( compositionOf( document, null ) ).toBe( document );
			expect( compositionSections( document, null ) ).toBe(
				document.sections
			);
		} );

		test( 'a profile owns the checkout containers and the document keeps the rest', () => {
			const digital = profile( {
				id: 'digital',
				sections: [
					section( {
						id: 'contato',
						title: 'Contacto (digital)',
						position: 10,
					} ),
				],
			} );

			const composed = compositionOf( document, digital );

			expect( composed.sections.map( ( entry ) => entry.id ) ).toEqual( [
				'contato',
				'documentacao',
			] );
			expect( composed.sections[ 0 ].title ).toBe( 'Contacto (digital)' );
			// A copy: the draft the screen owns is not rewritten by simply looking at it.
			expect( document.sections[ 0 ].title ).toBe( 'Contato' );
		} );

		test( 'editing a composed document writes back to that profile and nobody else', () => {
			const digital = profile( { id: 'digital' } );
			const other = profile( { id: 'restrito', name: 'Restrito' } );
			const draft = withProfiles( document, [ digital, other ] );

			const composed = compositionOf( draft, digital );
			const edited = {
				...composed,
				sections: [
					...composed.sections,
					section( {
						id: 'entrega_propria',
						title: 'Entrega própria',
					} ),
				],
			};

			const written = withComposition( draft, 'digital', edited );

			expect(
				(
					profilesOf( written ).find(
						( entry ) => entry.id === 'digital'
					)?.sections ?? []
				).map( ( entry ) => entry.id )
			).toEqual( [ 'contato', 'entrega_propria' ] );
			expect(
				(
					profilesOf( written ).find(
						( entry ) => entry.id === 'restrito'
					)?.sections ?? []
				).map( ( entry ) => entry.id )
			).toEqual( [ 'contato' ] );
			// The store's own checkout is untouched by editing a profile.
			expect( written.sections.map( ( entry ) => entry.id ) ).toEqual( [
				'contato',
				'documentacao',
			] );
		} );

		test( 'without a profile the edit is the draft itself, as it always was', () => {
			const edited = { ...document, settings: { columns: 2 } };

			expect( withComposition( document, '', edited ) ).toBe( edited );
		} );
	} );
} );
