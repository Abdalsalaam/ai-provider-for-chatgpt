import { useCallback, useEffect, useState } from '@wordpress/element';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { Diagnostics, RemoteModelsProbe } from '../types';

type DiagnosticsState = {
	data: Diagnostics | null;
	loading: boolean;
	error: string | null;
};

type ProbeState = {
	data: RemoteModelsProbe | null;
	loading: boolean;
	error: string | null;
};

export function useDiagnostics() {
	const [ diagnostics, setDiagnostics ] = useState< DiagnosticsState >( {
		data: null,
		loading: true,
		error: null,
	} );
	const [ probe, setProbe ] = useState< ProbeState >( {
		data: null,
		loading: false,
		error: null,
	} );

	const refetch = useCallback( async () => {
		setDiagnostics( ( s ) => ( { ...s, loading: true, error: null } ) );
		try {
			const data = await api.getDiagnostics();
			setDiagnostics( { data, loading: false, error: null } );
		} catch ( err ) {
			setDiagnostics( {
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
				const data = await api.getDiagnostics();
				if ( ! cancelled ) {
					setDiagnostics( { data, loading: false, error: null } );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setDiagnostics( {
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

	const runRemoteProbe = useCallback( async () => {
		setProbe( { data: null, loading: true, error: null } );
		try {
			const data = await api.probeRemoteModels();
			setProbe( { data, loading: false, error: null } );
		} catch ( err ) {
			setProbe( {
				data: null,
				loading: false,
				error: errorMessage( err ),
			} );
		}
	}, [] );

	return { diagnostics, probe, refetch, runRemoteProbe };
}
