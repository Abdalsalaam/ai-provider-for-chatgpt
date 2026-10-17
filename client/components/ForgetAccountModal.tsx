import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { Button, Notice } from '@wordpress/ui';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { ConnectionStatus } from '../types';

type Props = {
	onClose: () => void;
	onForgotten: ( next: ConnectionStatus ) => void;
};

export function ForgetAccountModal( { onClose, onForgotten }: Props ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const forget = async () => {
		setBusy( true );
		setError( null );
		try {
			const next = await api.forgetAccount();
			onForgotten( next );
			void dispatch( noticesStore ).createSuccessNotice(
				__(
					'The previous ChatGPT account was removed from this site. You can now sign in with a different account.',
					'ai-provider-for-chatgpt'
				),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			setError( errorMessage( err ) );
			setBusy( false );
		}
	};

	return (
		<Modal
			title={ __(
				'Use a different ChatGPT account?',
				'ai-provider-for-chatgpt'
			) }
			size="small"
			onRequestClose={ () => {
				if ( ! busy ) {
					onClose();
				}
			} }
		>
			<p>
				{ __(
					'This forgets the ChatGPT account this site was connected to. You can then sign in with another account.',
					'ai-provider-for-chatgpt'
				) }
			</p>
			{ error && (
				<Notice.Root intent="error">
					<Notice.Description>{ error }</Notice.Description>
				</Notice.Root>
			) }
			<p className="ai-provider-for-chatgpt-actions-row">
				<Button variant="minimal" disabled={ busy } onClick={ onClose }>
					{ __( 'Cancel', 'ai-provider-for-chatgpt' ) }
				</Button>
				<Button
					variant="solid"
					tone="brand"
					loading={ busy }
					disabled={ busy }
					onClick={ () => void forget() }
				>
					{ __(
						'Use a different account',
						'ai-provider-for-chatgpt'
					) }
				</Button>
			</p>
		</Modal>
	);
}
