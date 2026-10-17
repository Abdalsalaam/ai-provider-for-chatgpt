import { useCallback, useEffect, useState } from '@wordpress/element';

import { api } from '../api/client';
import { errorCode, errorMessage } from '../utils';
import type { ConnectionStatus, SignInStart } from '../types';

// Server keeps a pending sign-in for 600 seconds (SignInFlow::PENDING_TTL).
const PENDING_TTL_MS = 600 * 1000;

// Local error code for Connect pressed with an empty field.
export const EMPTY_FIELD = 'empty_field';

// Codes that end the sign-in attempt on the server; other errors may be retried.
const ATTEMPT_ENDED_CODES = [
	'no_pending',
	'state_mismatch',
	'access_denied',
	'registration_incomplete',
	'exchange_failed',
	'id_token_invalid',
	'plan_not_enabled',
	'account_mismatch',
];

export type SignInError = { code: string | null; message: string };

export type SignInState = {
	start: SignInStart | null;
	starting: boolean;
	submitting: boolean;
	remainingSeconds: number;
	expired: boolean;
	error: SignInError | null;
	needsRestart: boolean;
	begin: () => Promise< void >;
	submit: ( callbackUrl: string ) => Promise< void >;
	clearError: () => void;
};

// Local deadline from the server expiry, bounded so a skewed clock cannot break it.
function localDeadline( expiresAt: number, receivedAt: number ): number {
	const remaining = expiresAt * 1000 - receivedAt;
	if ( remaining > 0 && remaining <= PENDING_TTL_MS ) {
		return receivedAt + remaining;
	}
	return receivedAt + PENDING_TTL_MS;
}

export function useSignIn(
	onConnected: ( next: ConnectionStatus ) => void
): SignInState {
	const [ start, setStart ] = useState< SignInStart | null >( null );
	const [ deadline, setDeadline ] = useState( 0 );
	const [ now, setNow ] = useState( () => Date.now() );
	const [ starting, setStarting ] = useState( false );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState< SignInError | null >( null );
	const [ needsRestart, setNeedsRestart ] = useState( false );

	const expired = start !== null && now >= deadline;

	useEffect( () => {
		if ( ! start || expired ) {
			return;
		}
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [ start, expired ] );

	const begin = useCallback( async () => {
		setStarting( true );
		setError( null );
		try {
			const next = await api.startSignIn();
			const receivedAt = Date.now();
			setStart( next );
			setDeadline( localDeadline( next.expires_at, receivedAt ) );
			setNow( receivedAt );
			setNeedsRestart( false );
			window.open( next.authorize_url, '_blank', 'noopener' );
		} catch ( err ) {
			setError( {
				code: errorCode( err ),
				message: errorMessage( err ),
			} );
		} finally {
			setStarting( false );
		}
	}, [] );

	const submit = useCallback(
		async ( callbackUrl: string ) => {
			const value = callbackUrl.trim();
			if ( value === '' ) {
				setError( { code: EMPTY_FIELD, message: '' } );
				return;
			}
			setSubmitting( true );
			setError( null );
			try {
				const next = await api.completeSignIn( value );
				setStart( null );
				onConnected( next );
			} catch ( err ) {
				const code = errorCode( err );
				setError( { code, message: errorMessage( err ) } );
				// Anything else is retryable; the server answers no_pending if it was used.
				setNeedsRestart(
					code !== null && ATTEMPT_ENDED_CODES.includes( code )
				);
			} finally {
				setSubmitting( false );
			}
		},
		[ onConnected ]
	);

	const clearError = useCallback( () => setError( null ), [] );

	return {
		start,
		starting,
		submitting,
		remainingSeconds: start ? ( deadline - now ) / 1000 : 0,
		expired,
		error,
		needsRestart,
		begin,
		submit,
		clearError,
	};
}
