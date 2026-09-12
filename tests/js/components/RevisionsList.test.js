/**
 * Publication history tests.
 *
 * The history is the mechanism a merchant reaches for when something went wrong,
 * so what matters is that it says what it will do and that the current revision
 * cannot be "restored" to itself. The wording is asserted rather than assumed: a
 * restore that quietly overwrote a revision would look identical in the DOM and
 * would destroy the record it exists to keep.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import RevisionsList from '../../../resources/admin/app/components/RevisionsList';

/**
 * Builds a revision list, newest first.
 *
 * @return {any[]} Revisions.
 */
function revisions() {
	return [
		{
			revision: 5,
			published_at: '2026-09-11T12:00:00+00:00',
			published_by: 1,
			hash: 'e',
		},
		{
			revision: 4,
			published_at: '2026-09-10T12:00:00+00:00',
			published_by: 1,
			hash: 'd',
		},
		{
			revision: 2,
			published_at: '2026-09-09T12:00:00+00:00',
			published_by: 1,
			hash: 'b',
		},
	];
}

/**
 * Renders the list with a spy.
 *
 * @param {Object} overrides Props to override.
 * @return {any} Render result and the restore spy.
 */
function renderList( overrides = {} ) {
	const onRestore = jest.fn();
	const result = render(
		<RevisionsList
			revisions={ revisions() }
			currentRevision={ 5 }
			onRestore={ onRestore }
			{ ...overrides }
		/>
	);

	return { ...result, onRestore };
}

/**
 * The rows of the history, in the order the design draws them.
 *
 * The design draws them as rows in a panel, not as items of an ordered list, so the
 * order is read from the DOM the screen actually produces.
 *
 * @return {HTMLElement[]} Rows.
 */
function list() {
	return Array.from(
		globalThis.document.querySelectorAll(
			'.wccs-revisions__list .history-row'
		)
	);
}

describe( 'the current revision', () => {
	it( 'is marked as the one in the store', () => {
		renderList();

		expect( screen.getByText( 'Na loja' ) ).toBeInTheDocument();
	} );

	it( 'offers no way to restore itself', () => {
		renderList();

		// Three revisions, one of them current: two restorable buttons.
		expect(
			screen.getAllByRole( 'button', { name: 'Usar como rascunho' } )
		).toHaveLength( 2 );
	} );
} );

describe( 'the history', () => {
	it( 'lists every revision with its number', () => {
		renderList();

		expect( screen.getByText( 'Revisão 5' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Revisão 4' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Revisão 2' ) ).toBeInTheDocument();
	} );

	it( 'keeps the order it was given, newest first', () => {
		renderList();

		const items = list();

		expect( items[ 0 ] ).toHaveTextContent( 'Revisão 5' );
		expect( items[ 2 ] ).toHaveTextContent( 'Revisão 2' );
	} );

	it( 'explains that restoring publishes again rather than erasing', () => {
		renderList();

		expect(
			screen.getByText( /Restaurar cria um rascunho/ )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				/Desfazer edição e restaurar revisão são ações distintas/
			)
		).toBeInTheDocument();
	} );

	it( 'says so when nothing has ever been published', () => {
		renderList( { revisions: [] } );

		expect(
			screen.getByText( /Nada foi publicado ainda/ )
		).toBeInTheDocument();
	} );

	it( 'survives a timestamp it cannot parse', () => {
		renderList( {
			revisions: [ { revision: 1, published_at: 'not a date' } ],
		} );

		expect( screen.getByText( 'not a date' ) ).toBeInTheDocument();
	} );

	it( 'says the date is unknown rather than showing an empty cell', () => {
		renderList( {
			revisions: [ { revision: 1, published_at: '' } ],
		} );

		expect( screen.getByText( 'data desconhecida' ) ).toBeInTheDocument();
	} );
} );

describe( 'restoring', () => {
	it( 'asks for the revision that was chosen', async () => {
		const user = userEvent.setup();
		const { onRestore } = renderList();

		const rows = list();

		await user.click(
			within( rows[ 1 ] ).getByRole( 'button', {
				name: 'Usar como rascunho',
			} )
		);

		expect( onRestore ).toHaveBeenCalledWith( 4 );
	} );

	it( 'refuses to start a second restore while one is running', () => {
		renderList( { restoring: true } );

		// The name is matched loosely on purpose: while it is busy, Button keeps
		// its label and appends "(working…)" to the accessible name rather than
		// replacing it, which is the behaviour worth having.
		const buttons = screen.getAllByRole( 'button', {
			name: /Usar como rascunho/,
		} );

		expect( buttons ).toHaveLength( 2 );

		for ( const button of buttons ) {
			expect( button ).toBeDisabled();
		}
	} );

	it( 'confirms what happened after a restore', () => {
		renderList( {
			restored:
				'A revisão 2 foi publicada de novo como uma revisão nova.',
		} );

		expect(
			screen.getByText( /A revisão 2 foi publicada de novo/ )
		).toBeInTheDocument();
	} );

	it( 'reports a failure without hiding the history', () => {
		renderList( { error: 'The revision could not be restored.' } );

		expect(
			screen.getByText( 'The revision could not be restored.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Revisão 4' ) ).toBeInTheDocument();
	} );
} );
