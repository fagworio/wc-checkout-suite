/**
 * Blocks controlled-field tests.
 *
 * The components exist because the Blocks additional-fields API knows three types
 * and the product has more. What is asserted here is what a component owes the
 * checkout that owns the value: it renders the control its type calls for, it
 * reports changes, it says whether it is required to assistive technology, and it
 * refuses to draw a type it has no control for rather than guessing at one.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	componentFor,
	MaskedTextField,
	MultiSelectField,
	RadioField,
	TemporalField,
	TextareaField,
	unrenderable,
} from '../../../resources/blocks/fields';

/**
 * A field payload.
 *
 * @param {Object} overrides Overrides.
 * @return {any} Field.
 */
function field( overrides = {} ) {
	return {
		id: 'wc-checkoutsuite/wccs_note',
		name: 'wccs_note',
		label: 'Note',
		type: 'textarea',
		location: 'address',
		required: false,
		...overrides,
	};
}

describe( 'blocks controlled fields', () => {
	it( 'renders a textarea with the label as its accessible name', () => {
		render(
			<TextareaField field={ field() } value="" onChange={ () => {} } />
		);

		expect( screen.getByLabelText( /Note/ ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Note/ ).tagName ).toBe( 'TEXTAREA' );
	} );

	it( 'reports what was typed, without keeping a copy', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<TextareaField field={ field() } value="" onChange={ onChange } />
		);

		await user.type( screen.getByLabelText( /Note/ ), 'hi' );

		expect( onChange ).toHaveBeenCalled();
		expect( onChange.mock.calls[ 0 ][ 0 ] ).toBe( 'h' );
	} );

	it( 'keeps the value it was given', () => {
		render(
			<TextareaField
				field={ field() }
				value="from the checkout"
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /Note/ ) ).toHaveValue(
			'from the checkout'
		);
	} );

	it( 'announces a required field, and marks it visibly', () => {
		render(
			<TextareaField
				field={ field( { required: true } ) }
				value=""
				onChange={ () => {} }
			/>
		);

		const control = screen.getByLabelText( /Note/ );

		expect( control ).toBeRequired();
		expect( control ).toHaveAttribute( 'aria-required', 'true' );
	} );

	it( 'shows a message as an alert and marks the control invalid', () => {
		render(
			<TextareaField
				field={ field() }
				value=""
				onChange={ () => {} }
				error="This is not a document."
			/>
		);

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'This is not a document.'
		);
		expect( screen.getByLabelText( /Note/ ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
	} );

	it( 'renders a radio group from the options the server published', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<RadioField
				field={ field( {
					type: 'radio',
					options: [
						{ value: 'pf', label: 'Person' },
						{ value: 'pj', label: 'Company' },
					],
				} ) }
				value="pf"
				onChange={ onChange }
			/>
		);

		expect( screen.getByLabelText( 'Person' ) ).toBeChecked();
		expect( screen.getByLabelText( 'Company' ) ).not.toBeChecked();

		await user.click( screen.getByLabelText( 'Company' ) );

		expect( onChange ).toHaveBeenCalledWith( 'pj' );
	} );

	it( 'renders a multi-select whose value is the list of chosen options', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<MultiSelectField
				field={ field( {
					type: 'multiselect',
					options: [
						{ value: 'a', label: 'A' },
						{ value: 'b', label: 'B' },
					],
				} ) }
				value={ [ 'a' ] }
				onChange={ onChange }
			/>
		);

		const control = screen.getByLabelText( /Note/ );

		expect( control ).toHaveAttribute( 'multiple' );

		await user.selectOptions( control, 'b' );

		expect( onChange ).toHaveBeenCalledWith( [ 'a', 'b' ] );
	} );

	it( 'uses the native input the type asks for', () => {
		const { unmount } = render(
			<TemporalField
				field={ field( { type: 'date' } ) }
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /Note/ ) ).toHaveAttribute(
			'type',
			'date'
		);

		unmount();

		render(
			<TemporalField
				field={ field( { type: 'datetime' } ) }
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /Note/ ) ).toHaveAttribute(
			'type',
			'datetime-local'
		);
	} );

	it( 'masks a field that carries a mask definition', async () => {
		const user = userEvent.setup();

		render(
			<MaskedTextField
				field={ field( {
					type: 'text',
					mask: {
						key: 'br.cpf',
						version: 1,
						definition: '000.000.000-00',
					},
				} ) }
				value=""
				onChange={ () => {} }
			/>
		);

		const control = screen.getByLabelText( /Note/ );

		await user.type( control, '52998224725' );

		expect( control ).toHaveValue( '529.982.247-25' );
	} );

	it( 'asks for the keyboard the mask pattern implies, and none for a permissive one', () => {
		const { unmount } = render(
			<MaskedTextField
				field={ field( {
					type: 'text',
					mask: {
						key: 'br.cpf',
						version: 1,
						definition: '000.000.000-00',
					},
				} ) }
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /Note/ ) ).toHaveAttribute(
			'inputmode',
			'numeric'
		);

		unmount();

		render(
			<MaskedTextField
				field={ field( {
					type: 'text',
					mask: {
						key: 'br.cnpj',
						version: 1,
						definition: '**.***.***/****-**',
					},
				} ) }
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( /Note/ ) ).not.toHaveAttribute(
			'inputmode'
		);
	} );

	it( 'has a component for every type it renders, and none for the rest', () => {
		expect( componentFor( field( { type: 'textarea' } ) ) ).toBe(
			TextareaField
		);
		expect( componentFor( field( { type: 'radio' } ) ) ).toBe( RadioField );
		expect( componentFor( field( { type: 'multiselect' } ) ) ).toBe(
			MultiSelectField
		);
		expect( componentFor( field( { type: 'time' } ) ) ).toBe(
			TemporalField
		);
		expect( componentFor( field( { type: 'file' } ) ) ).toBeNull();
		expect( componentFor( field( { type: 'heading' } ) ) ).toBeNull();
		expect( componentFor( /** @type {any} */ ( null ) ) ).toBeNull();
	} );

	it( 'renders a plain text field here only when it carries a mask', () => {
		expect( componentFor( field( { type: 'text' } ) ) ).toBeNull();
		expect(
			componentFor(
				field( {
					type: 'text',
					mask: { key: 'br.cpf', version: 1, definition: '000' },
				} )
			)
		).toBe( MaskedTextField );
	} );

	it( 'says which type has no component rather than drawing a wrong control', () => {
		expect(
			unrenderable( /** @type {any} */ ( { type: 'signature' } ) )
		).toContain( 'signature' );
	} );
} );
