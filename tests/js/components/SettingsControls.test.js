/**
 * Settings controls tests.
 *
 * These controls are generated from the `settingsSchema` a field type declares, which
 * is what lets a type the admin has never heard of get an editor. The suite that used
 * to cover them rendered the whole inspector that has since been replaced by the
 * design's, so the behaviour that outlived that panel is pinned here directly: a
 * control per declared setting, of the kind the declaration asks for, and nothing for
 * a type that declares nothing.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SettingsControls } from '../../../resources/admin/app/components/SettingsControls';

describe( 'the settings a type declares', () => {
	it( 'says so when the type declares nothing', () => {
		render(
			<SettingsControls
				schema={ {} }
				value={ {} }
				onChange={ () => {} }
			/>
		);

		expect(
			screen.getByText( 'This type declares no settings of its own.' )
		).toBeInTheDocument();
	} );

	it( 'gives every declared setting a control', () => {
		render(
			<SettingsControls
				schema={ {
					placeholder: {
						type: 'string',
						label: 'Placeholder',
					},
					rows: { type: 'integer', label: 'Rows' },
				} }
				value={ {} }
				onChange={ () => {} }
			/>
		);

		// The label is the setting's name: the schema declares rules, not copy, and the
		// merchant sees the key the type itself uses.
		expect( screen.getByLabelText( 'placeholder' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'rows' ) ).toBeInTheDocument();
	} );

	it( 'reports the setting that changed, not the whole map', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<SettingsControls
				schema={ {
					placeholder: {
						type: 'string',
						label: 'Placeholder',
					},
				} }
				value={ {} }
				onChange={ onChange }
			/>
		);

		await user.type( screen.getByLabelText( 'placeholder' ), 'abc' );

		expect( onChange ).toHaveBeenCalled();

		// A partial map with the setting that changed, which is what the caller
		// merges — the control keeps no copy of the whole settings object.
		expect( onChange.mock.calls[ 0 ][ 0 ] ).toEqual( {
			placeholder: 'a',
		} );
	} );

	it( 'shows the value the caller holds', () => {
		render(
			<SettingsControls
				schema={ {
					placeholder: {
						type: 'string',
					},
				} }
				value={ { placeholder: 'guardado' } }
				onChange={ () => {} }
			/>
		);

		expect( screen.getByLabelText( 'placeholder' ) ).toHaveValue(
			'guardado'
		);
	} );

	it( 'carries through a setting it does not render', async () => {
		const user = userEvent.setup();
		const onChange = jest.fn();

		render(
			<SettingsControls
				schema={ { placeholder: { type: 'string' } } }
				value={ { placeholder: 'a', unknown_to_this_build: 'b' } }
				onChange={ onChange }
			/>
		);

		await user.type( screen.getByLabelText( 'placeholder' ), 'c' );

		// A setting written by a newer version of the plugin is not deleted by a
		// build that does not know it: the partial map is merged over what the caller
		// already held, and the unknown key survives.
		const last = onChange.mock.calls[ onChange.mock.calls.length - 1 ][ 0 ];

		expect( last ).toEqual( {
			placeholder: 'ac',
			unknown_to_this_build: 'b',
		} );
	} );

	it( 'edits comma-separated array settings in one input while saving an array', async () => {
		const onChange = jest.fn();

		render(
			<SettingsControls
				schema={ {
					allowedExtensions: {
						type: 'array',
						format: 'comma-separated',
						label: 'Extensões permitidas',
					},
				} }
				value={ { allowedExtensions: [ 'pdf' ] } }
				onChange={ onChange }
			/>
		);

		const input = screen.getByLabelText( 'Extensões permitidas' );
		expect( input ).toHaveValue( 'pdf' );

		fireEvent.change( input, { target: { value: '.pdf, csv, png' } } );

		expect(
			onChange.mock.calls[ onChange.mock.calls.length - 1 ][ 0 ]
		).toEqual( {
			allowedExtensions: [ 'pdf', 'csv', 'png' ],
		} );
	} );
} );
