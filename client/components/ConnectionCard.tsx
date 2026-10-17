import { useEffect, useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { Button, Link, Notice } from '@wordpress/ui';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { dateI18n, humanTimeDiff } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { ConnectionStatus } from '../types';

type Props = {
	data: ConnectionStatus;
	apply: ( next: ConnectionStatus ) => void;
	refetch: () => Promise< void >;
};

function formatTimestamp( ts: number | null ): string {
	if ( ! ts ) {
		return '-';
	}
	return dateI18n( 'Y-m-d H:i', ts * 1000 );
}

function formatRelative( ts: number | null ): string {
	if ( ! ts ) {
		return '-';
	}
	return humanTimeDiff( ts * 1000, Date.now() );
}

export function ConnectionCard( { data, apply, refetch }: Props ) {
	const [ showPlanNotice, setShowPlanNotice ] = useState(
		data.show_first_signin_notice
	);
	const [ refreshing, setRefreshing ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ disconnecting, setDisconnecting ] = useState( false );
	const [ disconnectError, setDisconnectError ] = useState< string | null >(
		null
	);

	// Later payloads carry false, so only a true value may change the notice.
	useEffect( () => {
		if ( data.show_first_signin_notice ) {
			setShowPlanNotice( true );
		}
	}, [ data.show_first_signin_notice ] );

	const runRefresh = async () => {
		setRefreshing( true );
		try {
			const next = await api.refresh();
			apply( next );
			void dispatch( noticesStore ).createSuccessNotice(
				__( 'Connection refreshed.', 'ai-provider-for-chatgpt' ),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			void dispatch( noticesStore ).createErrorNotice(
				errorMessage( err ),
				{ type: 'snackbar' }
			);
			// A terminal refresh marks the record as needing sign-in, so reload it.
			await refetch();
		} finally {
			setRefreshing( false );
		}
	};

	const closeConfirm = () => {
		setConfirming( false );
		setDisconnectError( null );
	};

	const runDisconnect = async () => {
		setDisconnecting( true );
		setDisconnectError( null );
		try {
			const next = await api.disconnect();
			setConfirming( false );
			apply( next );
			if ( next.revoked ) {
				void dispatch( noticesStore ).createSuccessNotice(
					__(
						'Disconnected. ChatGPT access for this site was revoked.',
						'ai-provider-for-chatgpt'
					),
					{ type: 'snackbar' }
				);
			} else {
				void dispatch( noticesStore ).createWarningNotice(
					__(
						'Disconnected here, but ChatGPT did not confirm the revocation. You can remove the app under ChatGPT Settings > Usage.',
						'ai-provider-for-chatgpt'
					),
					{
						type: 'snackbar',
						explicitDismiss: true,
						actions: [
							{
								label: __(
									'Manage usage in ChatGPT',
									'ai-provider-for-chatgpt'
								),
								url: data.usage_url,
							},
						],
					}
				);
			}
		} catch ( err ) {
			setDisconnectError( errorMessage( err ) );
		} finally {
			setDisconnecting( false );
		}
	};

	return (
		<>
			{ showPlanNotice && (
				<Notice.Root intent="success">
					<Notice.Title>
						{ __(
							"You're using your ChatGPT plan",
							'ai-provider-for-chatgpt'
						) }
					</Notice.Title>
					<Notice.Description>
						{ __(
							'Eligible AI requests on this site use your ChatGPT plan. Manage usage in ChatGPT settings.',
							'ai-provider-for-chatgpt'
						) }
					</Notice.Description>
					<Notice.Actions>
						<Notice.ActionLink href={ data.usage_url } openInNewTab>
							{ __(
								'Manage usage in ChatGPT',
								'ai-provider-for-chatgpt'
							) }
						</Notice.ActionLink>
					</Notice.Actions>
					<Notice.CloseIcon
						onClick={ () => setShowPlanNotice( false ) }
					/>
				</Notice.Root>
			) }

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
							{ data.name && <strong>{ data.name }</strong> }{ ' ' }
							<code>{ data.email ?? '-' }</code>
						</td>
					</tr>
					<tr>
						<th scope="row">
							{ __( 'Plan usage', 'ai-provider-for-chatgpt' ) }
						</th>
						<td>
							{ data.plan_usage_enabled
								? __( 'Enabled', 'ai-provider-for-chatgpt' )
								: __(
										'Not enabled',
										'ai-provider-for-chatgpt'
								  ) }
						</td>
					</tr>
					<tr>
						<th scope="row">
							{ __( 'Client ID', 'ai-provider-for-chatgpt' ) }
						</th>
						<td>
							<code>{ data.client_id ?? '-' }</code>
						</td>
					</tr>
					<tr>
						<th scope="row">
							{ __(
								'Access token expires',
								'ai-provider-for-chatgpt'
							) }
						</th>
						<td>{ formatRelative( data.expires_at ) }</td>
					</tr>
					<tr>
						<th scope="row">
							{ __( 'Last refresh', 'ai-provider-for-chatgpt' ) }
						</th>
						<td>
							<code>
								{ formatTimestamp( data.last_refresh ) }
							</code>
						</td>
					</tr>
				</tbody>
			</table>

			<p className="ai-provider-for-chatgpt-actions-row">
				<Button
					variant="outline"
					loading={ refreshing }
					disabled={ refreshing }
					onClick={ () => void runRefresh() }
				>
					{ __( 'Refresh now', 'ai-provider-for-chatgpt' ) }
				</Button>
				<Button
					variant="minimal"
					onClick={ () => setConfirming( true ) }
				>
					{ __( 'Disconnect', 'ai-provider-for-chatgpt' ) }
				</Button>
				<Link href={ data.usage_url } openInNewTab>
					{ __(
						'Manage usage in ChatGPT',
						'ai-provider-for-chatgpt'
					) }
				</Link>
			</p>

			{ confirming && (
				<Modal
					title={ __(
						'Disconnect ChatGPT?',
						'ai-provider-for-chatgpt'
					) }
					size="small"
					onRequestClose={ () => {
						if ( ! disconnecting ) {
							closeConfirm();
						}
					} }
				>
					<p>
						{ __(
							'The site stops using your ChatGPT plan and its access is revoked. You can sign in again at any time.',
							'ai-provider-for-chatgpt'
						) }
					</p>
					{ disconnectError && (
						<Notice.Root intent="error">
							<Notice.Description>
								{ disconnectError }
							</Notice.Description>
						</Notice.Root>
					) }
					<p className="ai-provider-for-chatgpt-actions-row">
						<Button
							variant="minimal"
							disabled={ disconnecting }
							onClick={ closeConfirm }
						>
							{ __( 'Cancel', 'ai-provider-for-chatgpt' ) }
						</Button>
						<Button
							variant="solid"
							tone="brand"
							loading={ disconnecting }
							disabled={ disconnecting }
							onClick={ () => void runDisconnect() }
						>
							{ __( 'Disconnect', 'ai-provider-for-chatgpt' ) }
						</Button>
					</p>
				</Modal>
			) }
		</>
	);
}
