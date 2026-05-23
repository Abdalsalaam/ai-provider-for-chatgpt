import { useCallback, useEffect, useState } from '@wordpress/element';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { ConnectionStatus } from '../types';

type State = {
	data: ConnectionStatus | null;
	loading: boolean;
	error: string | null;
};

export function useConnection() {
	const [ state, setState ] = useState< State >( {
		data: null,
		loading: true,
		error: null,
	} );

	const refetch = useCallback( async () => {
		setState( ( s ) => ( { ...s, loading: true, error: null } ) );
		try {
			const data = await api.getConnection();
			setState( { data, loading: false, error: null } );
		} catch ( err ) {
			setState( {
				data: null,
				loading: false,
				error: errorMessage( err ),
			} );
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		( async () => {
			try {
				const data = await api.getConnection();
				if ( ! cancelled ) {
					setState( { data, loading: false, error: null } );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setState( {
						data: null,
						loading: false,
						error: errorMessage( err ),
					} );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [] );

	const apply = useCallback(
		( next: ConnectionStatus ) =>
			setState( { data: next, loading: false, error: null } ),
		[]
	);

	return { ...state, refetch, apply };
}
