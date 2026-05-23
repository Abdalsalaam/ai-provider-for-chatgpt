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
