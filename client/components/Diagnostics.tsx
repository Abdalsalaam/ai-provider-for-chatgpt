import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { useDiagnostics } from '../hooks/useDiagnostics';

function tick( ok: boolean ): string {
	return ok ? '✓' : '✗';
}

export function Diagnostics() {
	const { diagnostics, probe, refetch, runRemoteProbe } = useDiagnostics();

	return (
		<>
			{ diagnostics.loading && ! diagnostics.data && <Spinner /> }
			{ diagnostics.error && <p>{ diagnostics.error }</p> }

			{ diagnostics.data && (
				<table
					className="form-table ai-provider-for-chatgpt-table"
					role="presentation"
				>
					<tbody>
						<tr>
							<th scope="row">
								{ __(
									'Registry hasProvider',
									'ai-provider-for-chatgpt'
								) }
							</th>
							<td>
								<code>
									{ tick( diagnostics.data.registry.has ) }
								</code>
							</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'Registry isProviderConfigured',
									'ai-provider-for-chatgpt'
								) }
							</th>
							<td>
								<code>
									{ tick(
										diagnostics.data.registry.configured
									) }
								</code>
							</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'SDK model list size',
									'ai-provider-for-chatgpt'
								) }
							</th>
							<td>
								<code>
									{ diagnostics.data.sdk_models.count }
								</code>
							</td>
						</tr>
						<tr>
							<th scope="row">
								{ __(
									'First few models',
									'ai-provider-for-chatgpt'
								) }
							</th>
							<td>
								<code>
									{ diagnostics.data.sdk_models.sample.join(
										', '
									) || '—' }
								</code>
							</td>
						</tr>
					</tbody>
				</table>
			) }

			<p className="ai-provider-for-chatgpt-actions-row">
				<Button
					variant="secondary"
					isBusy={ diagnostics.loading }
					disabled={ diagnostics.loading }
					onClick={ refetch }
				>
					{ __( 'Re-check', 'ai-provider-for-chatgpt' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ probe.loading }
					disabled={ probe.loading }
					onClick={ runRemoteProbe }
				>
					{ __(
						'Probe ChatGPT /models directly',
						'ai-provider-for-chatgpt'
					) }
				</Button>
			</p>

			{ probe.loading && <Spinner /> }
			{ probe.data && (
				<>
					<table
						className="form-table ai-provider-for-chatgpt-table"
						role="presentation"
					>
						<tbody>
							<tr>
								<th scope="row">
									{ __(
										'HTTP status',
										'ai-provider-for-chatgpt'
									) }
								</th>
								<td>
									<code>
										{ probe.data.http_status || '—' }
									</code>
								</td>
							</tr>
							<tr>
								<th scope="row">
									{ __(
										'Total models returned',
										'ai-provider-for-chatgpt'
									) }
								</th>
								<td>
									<code>{ probe.data.model_ids.length }</code>
								</td>
							</tr>
							<tr>
								<th scope="row">
									{ __(
										'Models the SDK exposes (text-generation filter)',
										'ai-provider-for-chatgpt'
									) }
								</th>
								<td>
									<code>
										{ probe.data.text_only_ids.join(
											', '
										) || '—' }
									</code>
								</td>
							</tr>
							{ probe.data.error && (
								<tr>
									<th scope="row">
										{ __(
											'Error',
											'ai-provider-for-chatgpt'
										) }
									</th>
									<td>
										<code>{ probe.data.error }</code>
									</td>
								</tr>
							) }
						</tbody>
					</table>

					{ probe.data.raw_models.length > 0 && (
						<details className="ai-provider-for-chatgpt-details">
							<summary>
								{ __(
									'Raw model rows',
									'ai-provider-for-chatgpt'
								) }
							</summary>
							<table
								className="form-table ai-provider-for-chatgpt-table"
								role="presentation"
							>
								<thead>
									<tr>
										<th>
											{ __(
												'ID',
												'ai-provider-for-chatgpt'
											) }
										</th>
										<th>
											{ __(
												'Slug',
												'ai-provider-for-chatgpt'
											) }
										</th>
										<th>
											{ __(
												'Family',
												'ai-provider-for-chatgpt'
											) }
										</th>
										<th>
											{ __(
												'Text filter',
												'ai-provider-for-chatgpt'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ probe.data.raw_models.map( ( row ) => (
										<tr key={ row.id }>
											<td>
												<code>{ row.id }</code>
											</td>
											<td>
												<code>{ row.slug ?? '—' }</code>
											</td>
											<td>
												<code>
													{ row.model_family_display_name ??
														'—' }
												</code>
											</td>
											<td>
												<code>
													{ row.passes_text_filter
														? '✓'
														: '✗' }
												</code>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</details>
					) }
				</>
			) }
		</>
	);
}
