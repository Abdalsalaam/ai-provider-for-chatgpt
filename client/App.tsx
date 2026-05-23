import { useState } from '@wordpress/element';
import { Page } from '@wordpress/admin-ui';
import { Card, Link, Notice, Stack } from '@wordpress/ui';
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Spinner,
} from '@wordpress/components';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { moreVertical as moreVerticalIcon } from '@wordpress/icons';
import { __, sprintf, _n } from '@wordpress/i18n';

import { api } from './api/client';
import { useConnection } from './hooks/useConnection';
import { errorMessage } from './utils';
import { ChatGptIcon } from './components/ChatGptIcon';
import { ConnectionCard } from './components/ConnectionCard';
import { Diagnostics } from './components/Diagnostics';
import { NoticesArea } from './components/NoticesArea';

const DOCS_URL =
	'https://github.com/Abdalsalaam/ai-provider-for-chatgpt#readme';

export function App() {
	const { data, loading, error, refetch, apply } = useConnection();
	const [ clearingCache, setClearingCache ] = useState( false );

	const isConnected = !! data?.connected;

	const runDisconnect = async () => {
		try {
			const next = await api.disconnect();
			apply( next );
			void dispatch( noticesStore ).createSuccessNotice(
				__( 'Local token bundle removed.', 'ai-provider-for-chatgpt' ),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			void dispatch( noticesStore ).createErrorNotice(
				errorMessage( err ),
				{
					type: 'snackbar',
				}
			);
		}
	};

	const runClearCache = async () => {
		setClearingCache( true );
		try {
			const result = await api.clearCache();
			void dispatch( noticesStore ).createSuccessNotice(
				sprintf(
					/* translators: %d: number of cached entries removed. */
					_n(
						'AI client cache cleared (%d entry).',
						'AI client cache cleared (%d entries).',
						result.deleted,
						'ai-provider-for-chatgpt'
					),
					result.deleted
				),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			void dispatch( noticesStore ).createErrorNotice(
				errorMessage( err ),
				{
					type: 'snackbar',
				}
			);
		} finally {
			setClearingCache( false );
		}
	};

	const actions = (
		<>
			<Link href={ DOCS_URL } openInNewTab>
				{ __( 'Docs', 'ai-provider-for-chatgpt' ) }
			</Link>
			<DropdownMenu
				icon={ moreVerticalIcon }
				label={ __( 'Developer tools', 'ai-provider-for-chatgpt' ) }
			>
				{ () => (
					<MenuGroup>
						<MenuItem
							icon={ clearingCache ? <Spinner /> : undefined }
							disabled={ clearingCache }
							onClick={ runClearCache }
						>
							{ __(
								'Clear cached model list',
								'ai-provider-for-chatgpt'
							) }
						</MenuItem>
						<MenuItem
							isDestructive
							disabled={ ! isConnected }
							onClick={ runDisconnect }
						>
							{ __( 'Disconnect', 'ai-provider-for-chatgpt' ) }
						</MenuItem>
					</MenuGroup>
				) }
			</DropdownMenu>
		</>
	);

	return (
		<>
			<Page
				visual={ <ChatGptIcon /> }
				title={ __( 'ChatGPT', 'ai-provider-for-chatgpt' ) }
				subTitle={ __(
					'Use your ChatGPT account as a WordPress AI provider.',
					'ai-provider-for-chatgpt'
				) }
				actions={ actions }
			>
				<Stack
					direction="column"
					gap="md"
					className="ai-provider-for-chatgpt-stack"
				>
					{ loading && ! data && <Spinner /> }

					{ error && ! data && (
						<Notice.Root intent="error">
							<Notice.Description>{ error }</Notice.Description>
							<Notice.Actions>
								<Notice.ActionButton onClick={ refetch }>
									{ __( 'Retry', 'ai-provider-for-chatgpt' ) }
								</Notice.ActionButton>
							</Notice.Actions>
						</Notice.Root>
					) }

					{ data && ! isConnected && (
						<Notice.Root intent="warning">
							<Notice.Description>
								{ __(
									'No ChatGPT credentials imported yet. Connect below to enable the provider.',
									'ai-provider-for-chatgpt'
								) }
							</Notice.Description>
						</Notice.Root>
					) }

					{ data && (
						<Card.Root>
							<Card.Header>
								<Card.Title>
									{ isConnected
										? __(
												'Current connection',
												'ai-provider-for-chatgpt'
										  )
										: __(
												'Connect ChatGPT',
												'ai-provider-for-chatgpt'
										  ) }
								</Card.Title>
							</Card.Header>
							<Card.Content>
								<ConnectionCard data={ data } apply={ apply } />
							</Card.Content>
						</Card.Root>
					) }

					<Card.Root>
						<Card.Header>
							<Card.Title>
								{ __(
									'Diagnostics',
									'ai-provider-for-chatgpt'
								) }
							</Card.Title>
						</Card.Header>
						<Card.Content>
							<Diagnostics />
						</Card.Content>
					</Card.Root>
				</Stack>
			</Page>
			<NoticesArea />
		</>
	);
}
