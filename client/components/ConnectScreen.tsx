import { useState } from '@wordpress/element';
import { TextControl } from '@wordpress/components';
import { Button, Link, Notice } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';

import { EMPTY_FIELD, useSignIn } from '../hooks/useSignIn';
import { formatClock } from '../utils';
import { ChatGptIcon } from './ChatGptIcon';
import type { ConnectionStatus } from '../types';

const ELIGIBILITY_URL =
	'https://help.openai.com/en/articles/20001542-using-your-chatgpt-plan-in-other-apps-and-sites';

type Props = {
	status: ConnectionStatus;
	onConnected: ( next: ConnectionStatus ) => void;
};

export function ConnectScreen( { status, onConnected }: Props ) {
	const signIn = useSignIn( onConnected );
	const [ callbackUrl, setCallbackUrl ] = useState( '' );

	const restart = () => {
		setCallbackUrl( '' );
		void signIn.begin();
	};

	const errorText =
		signIn.error?.code === EMPTY_FIELD
			? __( 'Paste the address first.', 'ai-provider-for-chatgpt' )
			: signIn.error?.message;

	const blocked = signIn.expired || signIn.needsRestart;

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
					'Plan usage needs a ChatGPT Plus, Pro or Go account. AI requests from this site are billed to that plan. Requests are sent with store set to false, so OpenAI does not keep them for later retrieval; whether your content is used to improve models is governed by the data controls of your ChatGPT account.',
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
					<Notice.CloseIcon onClick={ signIn.clearError } />
				</Notice.Root>
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
						{ __(
							'A ChatGPT sign-in page opened in a new tab. If it did not open, use this link:',
							'ai-provider-for-chatgpt'
						) }{ ' ' }
						<Link href={ signIn.start.authorize_url } openInNewTab>
							{ __(
								'Open the ChatGPT sign-in page',
								'ai-provider-for-chatgpt'
							) }
						</Link>
					</p>
					<p>
						{ __(
							'After you approve, the browser opens a page at 127.0.0.1 that cannot load. That is expected. Copy the full address from the address bar and paste it here.',
							'ai-provider-for-chatgpt'
						) }
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
		</div>
	);
}
