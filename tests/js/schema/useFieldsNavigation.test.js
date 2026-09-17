import { renderHook } from '@testing-library/react';

import useFieldsNavigation from '../../../resources/admin/app/schema/useFieldsNavigation';

describe( 'useFieldsNavigation', () => {
	beforeEach( () => {
		window.history.replaceState(
			null,
			'',
			'/?page=wccs-checkoutsuite&section=fields&area=customer_account'
		);
	} );

	it( 'restores the destination from the URL on reload', () => {
		const { result } = renderHook( () =>
			useFieldsNavigation( {
				areaIds: [ 'checkout', 'customer_account' ],
			} )
		);

		expect( result.current.area ).toBe( 'customer_account' );
	} );
} );
