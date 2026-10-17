import { useState } from '@wordpress/element';
import { TextControl } from '@wordpress/components';
import { Button, Notice } from '@wordpress/ui';
import { lock as lockIcon } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';

import { EMPTY_FIELD, useSignIn } from '../hooks/useSignIn';
import { formatClock } from '../utils';
import { ChatGptIcon } from './ChatGptIcon';
import { ForgetAccountModal } from './ForgetAccountModal';
import type { ConnectionStatus, SignInStart } from '../types';

const ELIGIBILITY_URL =
	'https://help.openai.com/en/articles/20001542-using-your-chatgpt-plan-in-other-apps-and-sites';

// Reopens the current attempt's sign-in page without starting a new attempt.
function reopenSignIn( start: SignInStart | null ) {
	if ( start ) {
		window.open( start.authorize_url, '_blank', 'noopener,noreferrer' );
	}
}

type Props = {
	status: ConnectionStatus;
	onConnected: ( next: ConnectionStatus ) => void;
};

export function ConnectScreen( { status, onConnected }: Props ) {
	const signIn = useSignIn( onConnected );
	const [ callbackUrl, setCallbackUrl ] = useState( '' );
	const [ clipboardFailed, setClipboardFailed ] = useState( false );
	const [ confirmForget, setConfirmForget ] = useState( false );

	// A signed-out site keeps its registration and account until it is forgotten.
	const canForget = ! status.connected && status.client_id !== null;
	const rememberedAccount = canForget ? status.email ?? status.name : null;

	const onForgotten = ( next: ConnectionStatus ) => {
		setConfirmForget( false );
		setCallbackUrl( '' );
		signIn.reset();
		onConnected( next );
	};

	const restart = () => {
		setCallbackUrl( '' );
		void signIn.begin();
	};

	const errorText =
		signIn.error?.code === EMPTY_FIELD
			? __( 'Paste the address first.', 'ai-provider-for-chatgpt' )
			: signIn.error?.message;

	const blocked = signIn.expired || signIn.needsRestart;
	const inStep2 = signIn.start !== null;
	const exampleAddress = `${ status.redirect_uri }?code=...&state=...`;

	const pasteFromClipboard = async () => {
		setClipboardFailed( false );
		try {
			setCallbackUrl( ( await navigator.clipboard.readText() ).trim() );
		} catch {
			setClipboardFailed( true );
		}
	};

	const steps = [
		__(
			'Click Continue with ChatGPT. A ChatGPT sign-in page opens in a new tab.',
			'ai-provider-for-chatgpt'
		),
		__(
			'Sign in, keep the agent name, and approve Use your ChatGPT plan.',
			'ai-provider-for-chatgpt'
		),
		__(
			'The tab then shows an error such as "This site can\'t be reached" for 127.0.0.1. That is expected: nothing is wrong.',
			'ai-provider-for-chatgpt'
		),
		__(
			"Copy the full address from that tab's address bar, come back to this tab, paste it below and click Connect.",
			'ai-provider-for-chatgpt'
		),
	];

	return (
		<div className="ai-provider-for-chatgpt-connect">
			{ status.needs_signin && (
				<Notice.Root intent="warning">
					<Notice.Description>
						{ status.email
							? sprintf(
									/* translators: %s: email of the ChatGPT account that was connected. */
									__(
										'The ChatGPT session for %s ended. Sign in again to keep using your plan on this site.',
										'ai-provider-for-chatgpt'
									),
									status.email
							  )
							: __(
									'The ChatGPT session ended. Sign in again to keep using your plan on this site.',
									'ai-provider-for-chatgpt'
							  ) }
					</Notice.Description>
				</Notice.Root>
			) }

			<p>
				{ __(
					"Plan usage needs a ChatGPT Plus, Pro or Go account. AI requests from this site are billed to that plan. Requests are sent with store set to false, so OpenAI does not keep them for later retrieval. OpenAI's privacy policy covers how your content is handled.",
					'ai-provider-for-chatgpt'
				) }
			</p>

			{ errorText && (
				<Notice.Root intent="error">
					<Notice.Description>{ errorText }</Notice.Description>
					{ signIn.error?.code === 'plan_not_enabled' && (
						<Notice.Actions>
							<Notice.ActionLink
								href={ ELIGIBILITY_URL }
								openInNewTab
							>
								{ __(
									'Learn more',
									'ai-provider-for-chatgpt'
								) }
							</Notice.ActionLink>
						</Notice.Actions>
					) }
					{ signIn.error?.code === 'account_mismatch' &&
						canForget && (
							<Notice.Actions>
								<Notice.ActionButton
									onClick={ () => setConfirmForget( true ) }
								>
									{ __(
										'Use a different ChatGPT account',
										'ai-provider-for-chatgpt'
									) }
								</Notice.ActionButton>
							</Notice.Actions>
						) }
					<Notice.CloseIcon onClick={ signIn.clearError } />
				</Notice.Root>
			) }

			<Notice.Root
				intent="info"
				icon={ lockIcon }
				className="ai-provider-for-chatgpt-privacy"
			>
				<Notice.Title>
					{ __(
						'Your ChatGPT account stays private',
						'ai-provider-for-chatgpt'
					) }
				</Notice.Title>
				<Notice.Description>
					{ __(
						'This plugin runs only on your website. The plugin developer never receives your ChatGPT sign-in, your tokens or anything you send, and has no way to see or use your account. Your website keeps the connection encrypted in its own database and talks to OpenAI directly. The connection cannot read your ChatGPT conversations or memories. You can disconnect at any time on this page or in your ChatGPT settings.',
						'ai-provider-for-chatgpt'
					) }
				</Notice.Description>
			</Notice.Root>

			<h3 className="ai-provider-for-chatgpt-steps-title">
				{ __( 'How connecting works', 'ai-provider-for-chatgpt' ) }
			</h3>
			<ol className="ai-provider-for-chatgpt-steps">
				{ steps.map( ( text, index ) => {
					// After Continue, the admin's next move is steps 3 and 4.
					const current = inStep2 && index >= 2;
					return (
						<li
							key={ index }
							className={ current ? 'is-current' : undefined }
							aria-current={
								current && index === 2 ? 'step' : undefined
							}
						>
							{ text }
						</li>
					);
				} ) }
			</ol>
			<figure className="ai-provider-for-chatgpt-addressbar">
				<div className="ai-provider-for-chatgpt-addressbar-bar">
					<span
						className="ai-provider-for-chatgpt-addressbar-dots"
						aria-hidden="true"
					/>
					<code>{ exampleAddress }</code>
				</div>
				<figcaption>
					{ __(
						'Copy this whole address',
						'ai-provider-for-chatgpt'
					) }
				</figcaption>
			</figure>

			{ ! signIn.start && canForget && (
				<p className="ai-provider-for-chatgpt-actions-row">
					{ rememberedAccount && (
						<span>
							{ sprintf(
								/* translators: %s: email, or name, of the ChatGPT account this site remembers. */
								__(
									'Sign in again as %s',
									'ai-provider-for-chatgpt'
								),
								rememberedAccount
							) }
						</span>
					) }
					<Button
						variant="minimal"
						size="small"
						onClick={ () => setConfirmForget( true ) }
					>
						{ __(
							'Use a different ChatGPT account',
							'ai-provider-for-chatgpt'
						) }
					</Button>
				</p>
			) }

			{ ! signIn.start && (
				<p className="ai-provider-for-chatgpt-actions-row">
					<Button
						className="ai-provider-for-chatgpt-continue"
						variant="solid"
						tone="neutral"
						loading={ signIn.starting }
						disabled={ signIn.starting }
						onClick={ () => void signIn.begin() }
					>
						<ChatGptIcon className="ai-provider-for-chatgpt-continue-icon" />
						{ __(
							'Continue with ChatGPT',
							'ai-provider-for-chatgpt'
						) }
					</Button>
				</p>
			) }

			{ signIn.start && (
				<div className="ai-provider-for-chatgpt-step">
					<p>
						<strong>
							{ __(
								'Waiting for you in the other tab. When it shows the error page, copy its address and paste it here.',
								'ai-provider-for-chatgpt'
							) }
						</strong>
					</p>
					<p className="ai-provider-for-chatgpt-actions-row">
						<span>
							{ __(
								"Didn't see the ChatGPT tab?",
								'ai-provider-for-chatgpt'
							) }
						</span>
						<Button
							variant="outline"
							tone="neutral"
							disabled={ blocked }
							onClick={ () => reopenSignIn( signIn.start ) }
						>
							<ChatGptIcon className="ai-provider-for-chatgpt-continue-icon" />
							{ __(
								'Open ChatGPT sign-in',
								'ai-provider-for-chatgpt'
							) }
						</Button>
					</p>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Address of the page you landed on',
							'ai-provider-for-chatgpt'
						) }
						value={ callbackUrl }
						onChange={ setCallbackUrl }
						placeholder={ `${ signIn.start.redirect_uri }?code=...` }
						help={
							clipboardFailed
								? __(
										'Could not read the clipboard. Paste the address with Ctrl+V or Cmd+V.',
										'ai-provider-for-chatgpt'
								  )
								: sprintf(
										/* translators: %s: the callback address the pasted address must start with. */
										__(
											'It starts with %s',
											'ai-provider-for-chatgpt'
										),
										signIn.start.redirect_uri
								  )
						}
						autoComplete="off"
						spellCheck={ false }
						disabled={ blocked || signIn.submitting }
					/>
					<p className="ai-provider-for-chatgpt-actions-row">
						<Button
							variant="solid"
							tone="brand"
							loading={ signIn.submitting }
							disabled={ blocked || signIn.submitting }
							onClick={ () => void signIn.submit( callbackUrl ) }
						>
							{ __( 'Connect', 'ai-provider-for-chatgpt' ) }
						</Button>
						{ typeof navigator.clipboard?.readText ===
							'function' && (
							<Button
								variant="outline"
								disabled={ blocked || signIn.submitting }
								onClick={ () => void pasteFromClipboard() }
							>
								{ __(
									'Paste from clipboard',
									'ai-provider-for-chatgpt'
								) }
							</Button>
						) }
						<Button
							variant={ blocked ? 'solid' : 'minimal' }
							tone={ blocked ? 'brand' : 'neutral' }
							disabled={ signIn.starting || signIn.submitting }
							loading={ signIn.starting }
							onClick={ restart }
						>
							{ __( 'Start again', 'ai-provider-for-chatgpt' ) }
						</Button>
					</p>
					<p className="ai-provider-for-chatgpt-countdown">
						{ signIn.expired
							? __(
									'This sign-in attempt expired. Start again.',
									'ai-provider-for-chatgpt'
							  )
							: sprintf(
									/* translators: %s: time left as m:ss before the sign-in attempt expires. */
									__(
										'This sign-in attempt expires in %s.',
										'ai-provider-for-chatgpt'
									),
									formatClock( signIn.remainingSeconds )
							  ) }
					</p>
				</div>
			) }
			{ confirmForget && canForget && (
				<ForgetAccountModal
					onClose={ () => setConfirmForget( false ) }
					onForgotten={ onForgotten }
				/>
			) }
		</div>
	);
}
