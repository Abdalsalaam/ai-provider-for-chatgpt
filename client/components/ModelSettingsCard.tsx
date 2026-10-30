import { useEffect, useState } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { Button, Notice, Stack } from '@wordpress/ui';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';

import { api } from '../api/client';
import { errorMessage } from '../utils';
import type { ModelSettings } from '../types';

type Props = {
	// Changes when the connection changes, so the model list is read again.
	statusKey?: string;
};

// Offered when the account's model list did not report efforts.
const FALLBACK_EFFORTS = [ 'low', 'medium', 'high' ];

function effortLabel( effort: string ): string {
	switch ( effort ) {
		case 'low':
			return __( 'Low (fastest)', 'ai-provider-for-chatgpt' );
		case 'medium':
			return __( 'Medium', 'ai-provider-for-chatgpt' );
		case 'high':
			return __( 'High', 'ai-provider-for-chatgpt' );
		case 'xhigh':
			return __( 'Extra high', 'ai-provider-for-chatgpt' );
		case 'max':
			return __( 'Maximum', 'ai-provider-for-chatgpt' );
		default:
			return effort.charAt( 0 ).toUpperCase() + effort.slice( 1 );
	}
}

export function ModelSettingsCard( { statusKey }: Props ) {
	const [ data, setData ] = useState< ModelSettings | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ model, setModel ] = useState( '' );
	const [ effort, setEffort ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setError( null );
		api.getSettings()
			.then( ( next ) => {
				if ( ! cancelled ) {
					setData( next );
					setModel( next.default_model );
					setEffort( next.reasoning_effort );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( errorMessage( err ) );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ statusKey ] );

	if ( error && ! data ) {
		return (
			<Notice.Root intent="error">
				<Notice.Description>{ error }</Notice.Description>
			</Notice.Root>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}

	const selected = data.models.find( ( m ) => m.id === model );
	// A saved model that is no longer listed stays selectable so it is not lost on save.
	const modelOptions = [
		{
			label: __( 'Automatic (first listed)', 'ai-provider-for-chatgpt' ),
			value: '',
		},
		...data.models.map( ( m ) => ( { label: m.name, value: m.id } ) ),
		...( model && ! selected ? [ { label: model, value: model } ] : [] ),
	];

	const known = selected
		? selected.efforts
		: data.models.reduce< string[] | null >( ( all, m ) => {
				if ( ! m.efforts ) {
					return all;
				}
				const merged = all ?? [];
				m.efforts.forEach( ( e ) => {
					if ( ! merged.includes( e ) ) {
						merged.push( e );
					}
				} );
				return merged;
		  }, null );
	const efforts = known ?? FALLBACK_EFFORTS;
	const defaultEffort = selected?.default_effort ?? null;
	const effortOptions = [
		{
			label: defaultEffort
				? sprintf(
						/* translators: %s: the model's default effort, such as Medium. */
						__( 'Model default (%s)', 'ai-provider-for-chatgpt' ),
						effortLabel( defaultEffort )
				  )
				: __( 'Model default', 'ai-provider-for-chatgpt' ),
			value: '',
		},
		...efforts.map( ( e ) => ( { label: effortLabel( e ), value: e } ) ),
		...( effort && ! efforts.includes( effort )
			? [ { label: effortLabel( effort ), value: effort } ]
			: [] ),
	];
	const unsupported = !! effort && !! known && ! known.includes( effort );
	const changed =
		model !== data.default_model || effort !== data.reasoning_effort;

	const save = async () => {
		setSaving( true );
		try {
			const next = await api.saveSettings( model, effort );
			setData( next );
			setModel( next.default_model );
			setEffort( next.reasoning_effort );
			void dispatch( noticesStore ).createSuccessNotice(
				__( 'Model settings saved.', 'ai-provider-for-chatgpt' ),
				{ type: 'snackbar' }
			);
		} catch ( err ) {
			void dispatch( noticesStore ).createErrorNotice(
				errorMessage( err ),
				{ type: 'snackbar' }
			);
		} finally {
			setSaving( false );
		}
	};

	return (
		<Stack direction="column" gap="md">
			{ data.approval && (
				<Notice.Root intent="warning">
					<Notice.Description>
						{ sprintf(
							/* translators: %s: comma-separated plugin names, such as AI. */
							__(
								'The AI plugin is blocking ChatGPT requests from: %s. Approve them under Tools > Connector Approvals, or their AI features fail with a generic error.',
								'ai-provider-for-chatgpt'
							),
							data.approval.callers.join( ', ' )
						) }{ ' ' }
						<a href={ data.approval.url }>
							{ __(
								'Review requests',
								'ai-provider-for-chatgpt'
							) }
						</a>
					</Notice.Description>
				</Notice.Root>
			) }
			<p>
				{ __(
					'These apply when the plugin sending the prompt does not choose a model or a reasoning effort itself.',
					'ai-provider-for-chatgpt'
				) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Default model', 'ai-provider-for-chatgpt' ) }
				value={ model }
				options={ modelOptions }
				onChange={ setModel }
				help={ __(
					'Used for prompts sent to ChatGPT without a model. If another AI provider is set up and the prompt does not name ChatGPT, that provider may be used instead.',
					'ai-provider-for-chatgpt'
				) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Reasoning effort', 'ai-provider-for-chatgpt' ) }
				value={ effort }
				options={ effortOptions }
				onChange={ setEffort }
				help={
					unsupported
						? __(
								'The selected model does not list this effort, so it uses its default.',
								'ai-provider-for-chatgpt'
						  )
						: __(
								'Lower effort answers faster. Low suits translation and other short tasks. ChatGPT models cannot turn reasoning off.',
								'ai-provider-for-chatgpt'
						  )
				}
			/>
			<p className="ai-provider-for-chatgpt-actions-row">
				<Button
					variant="solid"
					loading={ saving }
					disabled={ saving || ! changed }
					onClick={ () => void save() }
				>
					{ __( 'Save', 'ai-provider-for-chatgpt' ) }
				</Button>
			</p>
		</Stack>
	);
}
