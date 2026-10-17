export function errorMessage( err: unknown ): string {
	if ( err && typeof err === 'object' && 'message' in err ) {
		const message = ( err as { message: unknown } ).message;
		if ( typeof message === 'string' ) {
			return message;
		}
	}
	if ( typeof err === 'string' ) {
		return err;
	}
	return 'Unexpected error';
}

export function errorCode( err: unknown ): string | null {
	if ( err && typeof err === 'object' && 'code' in err ) {
		const code = ( err as { code: unknown } ).code;
		if ( typeof code === 'string' ) {
			return code;
		}
	}
	return null;
}

// Formats a number of seconds as m:ss for the sign-in countdown.
export function formatClock( seconds: number ): string {
	const safe = Math.max( 0, Math.floor( seconds ) );
	return `${ Math.floor( safe / 60 ) }:${ String( safe % 60 ).padStart(
		2,
		'0'
	) }`;
}
