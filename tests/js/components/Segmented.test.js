/**
 * Segmented control tests.
 *
 * A segmented control that only changes colour would be unusable without sight,
 * and one built from `div`s would be unreachable by keyboard. These specs pin
 * down the two properties that make it usable: each option is a real button, and
 * each option states whether it is selected.
 */

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import Segmented from '../../../resources/admin/app/components/Segmented';

/**
 * Options used by the specs.
 *
 * @type {{ id: string, label: string }[]}
 */
const OPTIONS = [
	{ id: 'desktop', label: 'Desktop' },
	{ id: 'tablet', label: 'Tablet' },
	{ id: 'mobile', label: 'Mobile' },
];

describe( 'Segmented', () => {
	it( 'names the group so the choice has a subject', () => {
		render(
			<Segmented
				label="Device"
				options={ OPTIONS }
				value="desktop"
				onChange={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'group', { name: 'Device' } )
		).toBeInTheDocument();
	} );

	it( 'exposes every option as a button', () => {
		render(
			<Segmented
				label="Device"
				options={ OPTIONS }
				value="desktop"
				onChange={ () => {} }
			/>
		);

		const group = screen.getByRole( 'group', { name: 'Device' } );

		expect( within( group ).getAllByRole( 'button' ) ).toHaveLength( 3 );
	} );

	it( 'marks the selected option as pressed and the others as not', () => {
		render(
			<Segmented
				label="Device"
				options={ OPTIONS }
				value="tablet"
				onChange={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Tablet' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect(
			screen.getByRole( 'button', { name: 'Desktop' } )
		).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	it( 'reports the option the person chose', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<Segmented
				label="Device"
				options={ OPTIONS }
				value="desktop"
				onChange={ onChange }
			/>
		);

		await user.click( screen.getByRole( 'button', { name: 'Mobile' } ) );

		expect( onChange ).toHaveBeenCalledWith( 'mobile' );
	} );

	it( 'is operable from the keyboard', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<Segmented
				label="Device"
				options={ OPTIONS }
				value="desktop"
				onChange={ onChange }
			/>
		);

		await user.tab();
		expect(
			screen.getByRole( 'button', { name: 'Desktop' } )
		).toHaveFocus();

		await user.keyboard( '{Enter}' );

		expect( onChange ).toHaveBeenCalledWith( 'desktop' );
	} );

	it( 'renders nothing without options', () => {
		const { container } = render(
			<Segmented
				label="Device"
				options={ [] }
				value="desktop"
				onChange={ () => {} }
			/>
		);

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'passes the option description on as a tooltip', () => {
		render(
			<Segmented
				label="Device"
				options={ [
					{
						id: 'mobile',
						label: 'Mobile',
						description: 'Single-column layout.',
					},
				] }
				value="mobile"
				onChange={ () => {} }
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Mobile' } )
		).toHaveAttribute( 'title', 'Single-column layout.' );
	} );
} );
