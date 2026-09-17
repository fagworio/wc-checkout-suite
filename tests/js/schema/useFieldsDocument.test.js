import { act, renderHook, waitFor } from '@testing-library/react';

import useFieldsDocument from '../../../resources/admin/app/schema/useFieldsDocument';

function draft() {
	return {
		revision: 3,
		sections: [],
		fields: [],
		profiles: [],
	};
}

function client( overrides = {} ) {
	return {
		getDraft: jest.fn( async () => draft() ),
		fieldTypes: jest.fn( async () => ( {} ) ),
		coreFields: jest.fn( async () => ( {} ) ),
		diff: jest.fn( async () => ( { validation: { valid: true } } ) ),
		revisions: jest.fn( async () => ( { revisions: [] } ) ),
		publish: jest.fn( async () => ( {} ) ),
		restore: jest.fn( async () => ( {} ) ),
		saveDraft: jest.fn( async () => draft() ),
		...overrides,
	};
}

describe( 'useFieldsDocument validation state', () => {
	it( 'clears old problems before retrying and exposes server field errors', async () => {
		const stub = client();
		const { result } = renderHook( () =>
			useFieldsDocument( { client: stub } )
		);

		await waitFor( () => expect( result.current.document ).toBeTruthy() );
		stub.saveDraft.mockRejectedValueOnce( {
			isValidationFailure: true,
			fieldErrors: [ { fieldId: 'name', message: 'Nome inválido' } ],
		} );

		await act( async () => result.current.save() );
		await waitFor( () =>
			expect( result.current.problems ).toEqual( [
				{ fieldId: 'name', message: 'Nome inválido' },
			] )
		);

		await act( async () => result.current.save() );
		await waitFor( () => expect( result.current.problems ).toEqual( [] ) );
	} );
} );
