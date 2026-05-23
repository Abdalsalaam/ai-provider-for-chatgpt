import { __ } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';

import { ConnectScreen } from './ConnectScreen';
import type { ConnectionStatus } from '../types';

type Props = {
	data: ConnectionStatus;
	apply: ( next: ConnectionStatus ) => void;
};

function maskedDots( length: number ): string {
	return '•'.repeat( length );
}

function formatTimestamp( ts: number | null ): string {
	if ( ! ts ) {
		return '—';
	}
	return dateI18n( 'Y-m-d H:i', ts * 1000 );
}

export function ConnectionCard( { data, apply }: Props ) {
	if ( ! data.connected ) {
		return <ConnectScreen onImported={ apply } />;
	}

	return (
		<table
			className="form-table ai-provider-for-chatgpt-table"
			role="presentation"
		>
			<tbody>
				<tr>
					<th scope="row">
						{ __( 'Connected as', 'ai-provider-for-chatgpt' ) }
					</th>
					<td>
						<code>
							{ data.email ? maskedDots( 12 ) : '—' }
						</code>
					</td>
				</tr>
				<tr>
					<th scope="row">
						{ __( 'ChatGPT plan', 'ai-provider-for-chatgpt' ) }
					</th>
					<td>
						<code>{ data.plan_type ?? '—' }</code>
					</td>
				</tr>
				<tr>
					<th scope="row">
						{ __( 'Account ID', 'ai-provider-for-chatgpt' ) }
					</th>
					<td>
						<code>{ maskedDots( 24 ) }</code>
					</td>
				</tr>
				<tr>
					<th scope="row">
						{ __( 'Last refresh', 'ai-provider-for-chatgpt' ) }
					</th>
					<td>
						<code>{ formatTimestamp( data.last_refresh ) }</code>
					</td>
				</tr>
			</tbody>
		</table>
	);
}
