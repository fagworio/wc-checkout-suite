/**
 * Form component tests.
 *
 * The point of these components is that the relationship between a label, its
 * control, the help text and the error exists in the accessibility tree, not
 * only on screen.
 */

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	TextField,
	TextareaField,
	SelectField,
	CheckboxField,
} from '../../../resources/admin/app/components/controls';
import ErrorSummary from '../../../resources/admin/app/components/ErrorSummary';

describe( 'TextField', () => {
	it( 'associates the label with the control', () => {
		render(
			<TextField
				id="document"
				label="Document"
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( 'Document' ) ).toHaveAttribute(
			'id',
			'document'
		);
	} );

	it( 'describes the control with both the help text and the error', () => {
		render(
			<TextField
				id="document"
				label="Document"
				value=""
				help="Digits only."
				error="This document is not valid."
				onChange={ () => {} }
			/>
		);

		const control = screen.getByLabelText( /Document/ );

		expect( control ).toHaveAttribute(
			'aria-describedby',
			'document-help document-error'
		);
		expect( control ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( screen.getByText( 'Digits only.' ) ).toHaveAttribute(
			'id',
			'document-help'
		);
		expect(
			screen.getByText( 'This document is not valid.' )
		).toHaveAttribute( 'id', 'document-error' );
	} );

	it( 'explains the required asterisk in text', () => {
		render(
			<TextField
				id="document"
				label="Document"
				value=""
				required
				onChange={ () => {} }
			/>
		);

		expect( screen.getByText( '(required)' ) ).toHaveClass(
			'wccs-screen-reader-text'
		);
		expect( screen.getByLabelText( /Document/ ) ).toBeRequired();
	} );

	it( 'is reachable and editable by keyboard', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<TextField
				id="document"
				label="Document"
				value=""
				onChange={ onChange }
			/>
		);

		await user.tab();

		expect( screen.getByLabelText( 'Document' ) ).toHaveFocus();

		await user.keyboard( 'A' );

		expect( onChange ).toHaveBeenCalled();
	} );
} );

describe( 'TextareaField and SelectField', () => {
	it( 'renders a textarea associated with its label', () => {
		render(
			<TextareaField
				id="note"
				label="Note"
				value=""
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( 'Note' ).tagName ).toBe( 'TEXTAREA' );
	} );

	it( 'renders the declared options', () => {
		render(
			<SelectField
				id="section"
				label="Section"
				value="billing"
				options={ [
					{ value: 'billing', label: 'Billing' },
					{ value: 'shipping', label: 'Shipping' },
				] }
				onChange={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'combobox', { name: 'Section' } )
		).toHaveValue( 'billing' );
		expect( screen.getAllByRole( 'option' ) ).toHaveLength( 2 );
	} );
} );

describe( 'CheckboxField', () => {
	it( 'places the label after the control and keeps them associated', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<CheckboxField
				id="consent"
				label="I agree"
				checked={ false }
				onChange={ onChange }
			/>
		);

		const control = screen.getByLabelText( 'I agree' );

		expect( control ).toHaveAttribute( 'type', 'checkbox' );

		await user.click( control );

		expect( onChange ).toHaveBeenCalled();
	} );

	it( 'refuses to render without an id', () => {
		expect( () => render( <CheckboxField label="I agree" /> ) ).toThrow(
			/requires an id/
		);
	} );
} );

describe( 'ErrorSummary', () => {
	it( 'renders nothing when there are no errors', () => {
		const { container } = render( <ErrorSummary errors={ [] } /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'announces the problem count and lists each one as a link', () => {
		render(
			<ErrorSummary
				errors={ [
					{ fieldId: 'document', message: 'Document is required.' },
					{ fieldId: 'phone', message: 'Phone is invalid.' },
				] }
			/>
		);

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'2 problems prevent saving.'
		);

		expect(
			screen.getByRole( 'link', { name: 'Document is required.' } )
		).toHaveAttribute( 'href', '#document' );
		expect(
			screen.getByRole( 'link', { name: 'Phone is invalid.' } )
		).toHaveAttribute( 'href', '#phone' );
	} );

	it( 'takes focus so the summary is read before the fields', () => {
		render(
			<ErrorSummary
				errors={ [ { fieldId: 'document', message: 'Required.' } ] }
			/>
		);

		expect( screen.getByRole( 'alert' ) ).toHaveFocus();
	} );

	it( 'asks the caller to focus the field behind a link', async () => {
		const user = userEvent.setup();
		const onFocusField = jest.fn();

		render(
			<ErrorSummary
				errors={ [ { fieldId: 'document', message: 'Required.' } ] }
				onFocusField={ onFocusField }
			/>
		);

		await user.click( screen.getByRole( 'link', { name: 'Required.' } ) );

		expect( onFocusField ).toHaveBeenCalledWith( 'document' );
	} );

	it( 'uses the singular wording for one problem', () => {
		render(
			<ErrorSummary
				errors={ [ { fieldId: 'document', message: 'Required.' } ] }
			/>
		);

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'1 problem prevents saving.'
		);
	} );
} );
