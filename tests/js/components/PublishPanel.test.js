/**
 * Publication panel tests.
 *
 * The panel exists because ROADMAP.md section 428 asks the publication to show
 * "diferenças, validações e incompatibilidades" — three things that look alike in
 * a list and mean completely different things. These specs pin down that they stay
 * distinguishable, and that the two ways of publishing by accident are closed:
 * with unsaved changes on screen, and with a schema that would be refused anyway.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PublishPanel from '../../../resources/admin/app/components/PublishPanel';

/**
 * Builds a report in the shape the diff route sends.
 *
 * @param {Object} overrides Parts to override.
 * @return {any} Report.
 */
function report( overrides = {} ) {
	return {
		diff: {
			empty: false,
			total_changes: 2,
			fields: {
				added: [
					{
						id: 'billing_document',
						label: 'CPF',
						type: 'text',
						section: 'billing',
						origin: 'custom',
					},
				],
				removed: [],
				changed: [
					{
						id: 'billing_ie',
						label: 'State registration',
						differences: [
							{
								key: 'label',
								from: 'IE',
								to: 'Inscrição Estadual',
							},
						],
					},
				],
			},
			sections: { added: [], removed: [], changed: [] },
			order: [],
			settings: [],
			published: { revision: 3, updated_at: '2026-09-11T10:00:00+00:00' },
			draft: { revision: 7, updated_at: '' },
		},
		validation: { valid: true, errors: [] },
		incompatibilities: {
			total: 0,
			adapters: { classic: [], blocks: [] },
			store: [],
		},
		adapters: [
			{
				value: 'classic',
				label: 'Classic checkout',
				description: '',
			},
			{ value: 'blocks', label: 'Block checkout', description: '' },
		],
		...overrides,
	};
}

/**
 * Renders the panel with a spy.
 *
 * @param {Object} overrides Props to override.
 * @return {any} Render result and the publish spy.
 */
function renderPanel( overrides = {} ) {
	const onPublish = jest.fn();
	const result = render(
		<PublishPanel
			report={ report() }
			onPublish={ onPublish }
			{ ...overrides }
		/>
	);

	return { ...result, onPublish };
}

describe( 'state', () => {
	it( 'counts the changes waiting to be published', () => {
		renderPanel();

		expect( screen.getByText( '2 change(s) ready' ) ).toBeInTheDocument();
	} );

	it( 'says when there is nothing to publish', () => {
		renderPanel( {
			report: report( {
				diff: { ...report().diff, empty: true, total_changes: 0 },
			} ),
		} );

		expect( screen.getByText( 'Nothing to publish' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /identical to the published version/ )
		).toBeInTheDocument();
	} );

	it( 'states that saving does not reach the store', () => {
		renderPanel();

		expect(
			screen.getByText(
				/keeps running the published version until you publish/
			)
		).toBeInTheDocument();
	} );
} );

describe( 'differences', () => {
	it( 'lists what was added', () => {
		renderPanel();

		const heading = screen.getByRole( 'heading', { name: /Fields added/ } );
		const group = heading.closest( 'div' );

		expect( group ).not.toBeNull();
		expect(
			within( /** @type {HTMLElement} */ ( group ) ).getByText(
				'billing_document'
			)
		).toBeInTheDocument();
	} );

	it( 'shows both sides of a changed value', () => {
		renderPanel();

		expect( screen.getByText( 'IE' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Inscrição Estadual' ) ).toBeInTheDocument();
	} );

	it( 'does not list a group that has nothing in it', () => {
		renderPanel();

		expect(
			screen.queryByRole( 'heading', { name: /Fields removed/ } )
		).toBeNull();
	} );

	it( 'reports a reordering with the two orders', () => {
		renderPanel( {
			report: report( {
				diff: {
					...report().diff,
					order: [
						{
							section: 'billing',
							from: [ 'a', 'b' ],
							to: [ 'b', 'a' ],
						},
					],
				},
			} ),
		} );

		expect( screen.getByText( /a, b/ ) ).toBeInTheDocument();
		expect( screen.getByText( /b, a/ ) ).toBeInTheDocument();
	} );
} );

describe( 'validations block publication', () => {
	const invalid = report( {
		validation: {
			valid: false,
			errors: [
				{
					code: 'unknown_type',
					message: 'The field type is not registered.',
				},
			],
		},
	} );

	it( 'shows the validation problem', () => {
		renderPanel( { report: invalid } );

		expect(
			screen.getByText( 'The field type is not registered.' )
		).toBeInTheDocument();
	} );

	it( 'refuses to publish', () => {
		renderPanel( { report: invalid } );

		expect(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		).toBeDisabled();
		expect(
			screen.getByText( /Fix the problems above first/ )
		).toBeInTheDocument();
	} );
} );

describe( 'the capability matrix', () => {
	it( 'is absent when the report does not carry one', () => {
		render( <PublishPanel report={ report() } onPublish={ () => {} } /> );

		expect(
			screen.queryByText( 'What each checkout does with these fields' )
		).not.toBeInTheDocument();
	} );

	it( 'shows what each checkout does with each field, before publishing', () => {
		render(
			<PublishPanel
				report={ {
					...report(),
					capabilities: [
						{
							field: 'wccs_birth_date',
							label: 'Birth date',
							limits: [
								{
									family: 'type',
									adapter: 'blocks',
									level: 'limited',
									reason: 'The Blocks checkout has no native date field, so this plugin renders it (WCCS-037).',
								},
								{
									family: 'width',
									adapter: 'blocks',
									level: 'limited',
									reason: 'The Block checkout lays out its own fields, so the requested width is not applied there.',
								},
							],
						},
					],
				} }
				onPublish={ () => {} }
			/>
		);

		expect(
			screen.getByText( 'What each checkout does with these fields' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Birth date' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /The Blocks checkout has no native date field/ )
		).toBeInTheDocument();
		expect( screen.getByText( 'type · blocks' ) ).toBeInTheDocument();
		expect( screen.getByText( 'width · blocks' ) ).toBeInTheDocument();
	} );

	it( 'names the family without an adapter when the limit applies to both', () => {
		render(
			<PublishPanel
				report={ {
					...report(),
					capabilities: [
						{
							field: 'wccs_a',
							label: 'A',
							limits: [
								{
									family: 'core',
									adapter: 'all',
									level: 'limited',
									reason: 'WooCommerce owns this field.',
								},
							],
						},
					],
				} }
				onPublish={ () => {} }
			/>
		);

		expect( screen.getByText( 'core' ) ).toBeInTheDocument();
	} );
} );

describe( 'incompatibilities are warnings, not blocks', () => {
	const warned = report( {
		incompatibilities: {
			total: 1,
			adapters: {
				classic: [],
				blocks: [
					{
						kind: 'adapter',
						field: 'billing_birthdate',
						label: 'Date of birth',
						type: 'date',
						level: 'component',
						reason: 'needs a Suite component.',
					},
				],
			},
			store: [],
		},
	} );

	it( 'explains that they do not block', () => {
		renderPanel( { report: warned } );

		expect(
			screen.getByText( /do not block publication/ )
		).toBeInTheDocument();
	} );

	it( 'names the affected field and the reason', () => {
		renderPanel( { report: warned } );

		expect( screen.getByText( 'Date of birth' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /needs a Suite component/ )
		).toBeInTheDocument();
	} );

	it( 'says an adapter has none, rather than staying silent', () => {
		renderPanel( { report: warned } );

		expect(
			screen.getByText( /No incompatibilities with the Classic checkout/ )
		).toBeInTheDocument();
	} );

	it( 'still allows publishing', () => {
		renderPanel( { report: warned } );

		expect(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		).toBeEnabled();
	} );

	it( 'flags an override whose WooCommerce field is gone', () => {
		renderPanel( {
			report: report( {
				incompatibilities: {
					total: 1,
					adapters: { classic: [], blocks: [] },
					store: [
						{
							kind: 'store',
							field: 'billing_phone',
							label: 'Phone',
							type: 'tel',
							level: 'unsupported',
							reason: 'no longer exists on this store',
						},
					],
				},
			} ),
		} );

		expect(
			screen.getByText( /no longer exists on this store/ )
		).toBeInTheDocument();
	} );
} );

describe( 'publishing by accident is closed off', () => {
	it( 'refuses while the draft has unsaved changes', () => {
		renderPanel( { dirty: true } );

		expect(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		).toBeDisabled();
		expect(
			screen.getByText( /Save the draft first/ )
		).toBeInTheDocument();
	} );

	it( 'refuses when there is nothing to publish', () => {
		renderPanel( {
			report: report( {
				diff: { ...report().diff, empty: true, total_changes: 0 },
			} ),
		} );

		expect(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		).toBeDisabled();
	} );

	it( 'refuses while a publication is already running', () => {
		renderPanel( { publishing: true } );

		expect(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		).toBeDisabled();
	} );
} );

describe( 'publishing', () => {
	it( 'asks once when the button is pressed', async () => {
		const user = userEvent.setup();
		const { onPublish } = renderPanel();

		await user.click(
			screen.getByRole( 'button', { name: /Publish changes/ } )
		);

		expect( onPublish ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports a failure without hiding the changes', () => {
		renderPanel( { error: 'Someone else published first.' } );

		expect(
			screen.getByText( 'Someone else published first.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: /Fields added/ } )
		).toBeInTheDocument();
	} );
} );
