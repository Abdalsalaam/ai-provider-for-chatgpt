import { useEffect, useRef, useState } from '@wordpress/element';
import { FormFileUpload, TextareaControl } from '@wordpress/components';
import { Button, Notice } from '@wordpress/ui';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { ConnectionStatus, PairingToken } from '../types';

type Props = {
	onImported: ( next: ConnectionStatus ) => void;
};

// npm package name of the companion CLI (source: github.com/Abdalsalaam/chatgpt-wp-connect).
const CLI_PACKAGE = '@abdalsalaam/chatgpt-wp-connect';
const POLL_INTERVAL_MS = 2500;

export function ConnectScreen( { onImported }: Props ) {
	const [ bundle, setBundle ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ inlineError, setInlineError ] = useState< string | null >( null );

	const [ pairing, setPairing ] = useState< PairingToken | null >( null );
	const [ pairingBusy, setPairingBusy ] = useState( false );
	const [ pairingExpired, setPairingExpired ] = useState( false );
	const [ showAdvanced, setShowAdvanced ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const [ now, setNow ] = useState( () => Date.now() );
	const pollTimer = useRef< ReturnType< typeof setInterval > | null >( null );
	const copyTimer = useRef< ReturnType< typeof setTimeout > | null >( null );

	// One-second tick so the countdown re-renders smoothly while a pairing
	// token is outstanding.
	useEffect( () => {
		if ( ! pairing || pairingExpired ) {
			return;
		}
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [ pairing, pairingExpired ] );

	useEffect( () => {
		return () => {
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
		};
	}, [] );

	// Poll /connection while a pairing token is outstanding; the CLI may post
	// the bundle at any moment, and there's no push channel back from PHP.
	useEffect( () => {
		if ( ! pairing ) {
			return;
		}
		pollTimer.current = setInterval( async () => {
			if ( pairing.expires_at * 1000 < Date.now() ) {
				stopPolling();
				setPairingExpired( true );
				return;
			}
			try {
				const next = await api.getConnection();
				if ( next.connected ) {
					stopPolling();
					setPairing( null );
					onImported( next );
					void dispatch( noticesStore ).createSuccessNotice(
						__(
							'Connected to ChatGPT.',
							'ai-provider-for-chatgpt'
						),
						{ type: 'snackbar' }
					);
				}
			} catch {
				// Network blip — keep polling.
			}
		}, POLL_INTERVAL_MS );

		return () => stopPolling();
		// We intentionally re-init the timer only when the pairing token changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ pairing?.token ] );

	const stopPolling = () => {
		if ( pollTimer.current ) {
			clearInterval( pollTimer.current );
			pollTimer.current = null;
		}
	};

	const beginPairing = async () => {
		setPairingExpired( false );
		setInlineError( null );
		setPairingBusy( true );
		try {
			const next = await api.issuePairing();
			setPairing( next );
		} catch ( err ) {
			setInlineError( errorMessage( err ) );
		} finally {
			setPairingBusy( false );
		}
	};

	const cancelPairing = () => {
		stopPolling();
		setPairing( null );
		setPairingExpired( false );
	};

	const copyCommand = async ( cmd: string ) => {
		const flashCopied = () => {
			setCopied( true );
			if ( copyTimer.current ) {
				clearTimeout( copyTimer.current );
			}
			copyTimer.current = setTimeout( () => setCopied( false ), 2000 );
		};
		try {
			if ( navigator.clipboard?.writeText ) {
				await navigator.clipboard.writeText( cmd );
			} else {
				// Fallback for insecure contexts where the async clipboard API
				// is unavailable. Uses a hidden textarea + execCommand('copy').
				const ta = document.createElement( 'textarea' );
				ta.value = cmd;
				ta.setAttribute( 'readonly', '' );
				ta.style.position = 'absolute';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
			}
			flashCopied();
			void dispatch( noticesStore ).createSuccessNotice(
				__( 'Command copied.', 'ai-provider-for-chatgpt' ),
				{ type: 'snackbar' }
			);
		} catch {
			void dispatch( noticesStore ).createErrorNotice(
				__(
					'Could not copy the command — copy it manually.',
					'ai-provider-for-chatgpt'
				),
				{ type: 'snackbar' }
			);
		}
	};

	const submit = async ( raw: string ) => {
		if ( raw.trim() === '' ) {
			setInlineError(
				__(
					'Paste the auth bundle JSON first.',
					'ai-provider-for-chatgpt'
				)
			);
			return;
		}
		setInlineError( null );
		setSubmitting( true );
		try {
			const next = await api.importBundle( raw );
			onImported( next );
			void dispatch( noticesStore ).createSuccessNotice(
				__( 'Auth bundle imported.', 'ai-provider-for-chatgpt' ),
				{ type: 'snackbar' }
			);
			setBundle( '' );
		} catch ( err ) {
			setInlineError( errorMessage( err ) );
		} finally {
			setSubmitting( false );
		}
	};

	const command = pairing
		? `npx ${ CLI_PACKAGE } ${ pairing.site_url } ${ pairing.token }`
		: '';

	const remainingSeconds = pairing
		? Math.max( 0, Math.round( pairing.expires_at - now / 1000 ) )
		: 0;
	const remainingClock = `${ Math.floor( remainingSeconds / 60 ) }:${ String(
		remainingSeconds % 60
	).padStart( 2, '0' ) }`;

	return (
		<div className="ai-provider-for-chatgpt-connect">
			<p>
				{ __(
					'Click the button below, then run the one-line command on your laptop. Your browser opens an OpenAI sign-in window; once you approve, this page connects automatically.',
					'ai-provider-for-chatgpt'
				) }
			</p>

			{ inlineError && (
				<Notice.Root intent="error">
					<Notice.Description>{ inlineError }</Notice.Description>
					<Notice.CloseIcon
						onClick={ () => setInlineError( null ) }
					/>
				</Notice.Root>
			) }

			{ ! pairing && (
				<p className="ai-provider-for-chatgpt-actions-row">
					<Button
						variant="solid"
						tone="brand"
						loading={ pairingBusy }
						disabled={ pairingBusy }
						onClick={ beginPairing }
					>
						{ __(
							'Connect with ChatGPT',
							'ai-provider-for-chatgpt'
						) }
					</Button>
					<Button
						variant="minimal"
						onClick={ () => setShowAdvanced( ( v ) => ! v ) }
					>
						{ showAdvanced
							? __(
									'Hide advanced',
									'ai-provider-for-chatgpt'
							  )
							: __(
									'Have a Codex auth.json already? Use advanced',
									'ai-provider-for-chatgpt'
							  ) }
					</Button>
				</p>
			) }

			{ pairing && ! pairingExpired && (
				<div className="ai-provider-for-chatgpt-pairing">
					<p>
						{ __(
							'Run this on your laptop (Node 18+ required):',
							'ai-provider-for-chatgpt'
						) }
					</p>
					<pre className="ai-provider-for-chatgpt-pairing-cmd">
						<code>{ command }</code>
					</pre>
					<p className="ai-provider-for-chatgpt-actions-row">
						<Button
							variant="solid"
							onClick={ () => copyCommand( command ) }
						>
							{ copied
								? __(
										'Copied!',
										'ai-provider-for-chatgpt'
								  )
								: __(
										'Copy command',
										'ai-provider-for-chatgpt'
								  ) }
						</Button>
						<Button variant="minimal" onClick={ cancelPairing }>
							{ __( 'Cancel', 'ai-provider-for-chatgpt' ) }
						</Button>
					</p>
					<p>
						<em>
							{ sprintf(
								/* translators: %s: remaining time formatted as m:ss until the pairing token expires */
								__(
									'Waiting for sign-in… (token expires in %s)',
									'ai-provider-for-chatgpt'
								),
								remainingClock
							) }
						</em>
					</p>
				</div>
			) }

			{ pairingExpired && (
				<Notice.Root intent="warning">
					<Notice.Description>
						{ __(
							'The pairing token expired before sign-in completed. Start over.',
							'ai-provider-for-chatgpt'
						) }
					</Notice.Description>
					<Notice.Actions>
						<Button variant="solid" onClick={ beginPairing }>
							{ __( 'Try again', 'ai-provider-for-chatgpt' ) }
						</Button>
					</Notice.Actions>
				</Notice.Root>
			) }

			{ showAdvanced && (
				<div className="ai-provider-for-chatgpt-advanced">
					<h3>
						{ __(
							'Advanced: import a Codex auth.json',
							'ai-provider-for-chatgpt'
						) }
					</h3>
					<p>
						{ __(
							'If you already ran `codex login`, paste ~/.codex/auth.json below or upload the file.',
							'ai-provider-for-chatgpt'
						) }
					</p>
					<TextareaControl
						label={ __(
							'Paste the contents of ~/.codex/auth.json',
							'ai-provider-for-chatgpt'
						) }
						value={ bundle }
						onChange={ setBundle }
						rows={ 10 }
						placeholder={
							'{"tokens": {"id_token": "...", "access_token": "...", "refresh_token": "...", "account_id": "..."}, "last_refresh": "..."}'
						}
					/>
					<p className="ai-provider-for-chatgpt-actions-row">
						<Button
							variant="solid"
							loading={ submitting }
							disabled={ submitting }
							onClick={ () => submit( bundle ) }
						>
							{ __(
								'Import bundle',
								'ai-provider-for-chatgpt'
							) }
						</Button>
						<FormFileUpload
							accept="application/json,.json"
							onChange={ ( event ) => {
								const file =
									event.currentTarget.files?.[ 0 ];
								if ( ! file ) {
									return;
								}
								const reader = new FileReader();
								reader.onload = () => {
									const text = String(
										reader.result ?? ''
									);
									setBundle( text );
									void submit( text );
								};
								reader.onerror = () => {
									setInlineError(
										__(
											'Could not read the selected file.',
											'ai-provider-for-chatgpt'
										)
									);
								};
								reader.readAsText( file );
							} }
						>
							{ __(
								'…or upload the file',
								'ai-provider-for-chatgpt'
							) }
						</FormFileUpload>
					</p>
				</div>
			) }
		</div>
	);
}
