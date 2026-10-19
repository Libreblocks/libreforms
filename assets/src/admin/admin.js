/**
 * LibreForms admin app: Forms (list + editor, shared with LibreForge) and Entries.
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { createRoot, Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import { AppShell, EmptyState, PageHeader } from '@libreblocks/ui';

import { KindScreen } from '@libreforge/admin/kind-screen';
import '@libreforge/admin/admin.scss';

import { EmbedHelp, FORM_DEFAULTS, formSections } from './form-sections';
import './admin.scss';

const data = window.libreformsAdmin || { tabs: [], forms: [] };

const FORM_KIND = {
	title: __( 'Forms', 'libreforms' ),
	singular: __( 'form', 'libreforms' ),
	icon: 'feedback',
	addLabel: __( 'New form', 'libreforms' ),
	createLabel: __( 'Create form', 'libreforms' ),
	emptyTitle: __( 'Create your first form', 'libreforms' ),
	empty: __( 'Contact, quote request, sign-up, "send your property"… Choose the fields, then what happens after sending: e-mail, saved entry, new post.', 'libreforms' ),
	deleteMessage: __( 'Delete this form? The entries already received stay in LibreForms → Entries.', 'libreforms' ),
	listUrlLabel: __( 'See the entries', 'libreforms' ),
	sections: formSections,
	aside: ( definition ) => <EmbedHelp definition={ definition } />,
	itemIcon: () => 'feedback',
	describe: ( item ) =>
		[
			/* translators: %d: number of fields */
			sprintf( _n( '%d field', '%d fields', item.summary.fields || 0, 'libreforms' ), item.summary.fields || 0 ),
			/* translators: %d: number of entries */
			sprintf( _n( '%d entry', '%d entries', item.summary.entries || 0, 'libreforms' ), item.summary.entries || 0 ),
			/* translators: %d: number of new entries */
			item.summary.new ? sprintf( _n( '%d new', '%d new', item.summary.new, 'libreforms' ), item.summary.new ) : '',
		]
			.filter( Boolean )
			.join( ' · ' ),
	defaults: FORM_DEFAULTS,
};

/* -------------------------------------------------------------------------- entries */

function Entries() {
	const [ formId, setFormId ] = useState( Number( getQueryArg( window.location.href, 'form' ) ) || data.forms[ 0 ]?.value || 0 );
	const [ page, setPage ] = useState( 1 );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );

	const load = () => {
		if ( ! formId ) {
			return;
		}

		setResult( null );
		apiFetch( { path: addQueryArgs( `/libreblocks/v1/forms/${ formId }/entries`, { page } ) } )
			.then( setResult )
			.catch( ( err ) => setError( err.message ) );
	};

	useEffect( load, [ formId, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const choose = ( value ) => {
		const id = Number( value );
		window.history.replaceState( {}, '', addQueryArgs( window.location.href, { form: id } ) );
		setFormId( id );
		setPage( 1 );
		setOpen( null );
	};

	const remove = ( entry ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( __( 'Delete this entry? This cannot be undone.', 'libreforms' ) ) ) {
			apiFetch( { path: `/libreblocks/v1/forms/entries/${ entry.id }`, method: 'DELETE' } )
				.then( load )
				.catch( ( err ) => setError( err.message ) );
		}
	};

	if ( ! data.forms.length ) {
		return (
			<>
				<PageHeader icon="email-alt" title={ __( 'Entries', 'libreforms' ) } />
				<EmptyState icon="feedback" title={ __( 'No forms yet', 'libreforms' ) } text={ __( 'Create a form first. Its answers will appear here.', 'libreforms' ) } action={ <Button variant="primary" href={ data.tabs[ 0 ]?.href }>{ __( 'Create form', 'libreforms' ) }</Button> } />
			</>
		);
	}

	// The table shows the first 3 fields; the rest appears when a row is opened.
	const visible = ( result?.columns || [] ).slice( 0, 3 );

	return (
		<>
			<PageHeader
				icon="email-alt"
				title={ __( 'Entries', 'libreforms' ) }
				subtitle={ result ? sprintf( /* translators: %d: number of entries */ _n( '%d entry', '%d entries', result.total, 'libreforms' ), result.total ) : '' }
				actions={
					<>
						<SelectControl label={ __( 'Form', 'libreforms' ) } hideLabelFromVision value={ String( formId ) } options={ data.forms.map( ( form ) => ( { value: String( form.value ), label: form.label } ) ) } onChange={ choose } __nextHasNoMarginBottom />
						{ result?.total > 0 && (
							<Button variant="secondary" icon="download" href={ result.export }>
								{ __( 'Export CSV', 'libreforms' ) }
							</Button>
						) }
					</>
				}
			/>

			{ error && <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice> }
			{ ! result && ! error && <Spinner /> }

			{ result && result.total === 0 && <EmptyState icon="email-alt" title={ __( 'No entries yet', 'libreforms' ) } text={ __( 'When someone sends this form (with the "Save the entry" action), the answers appear here.', 'libreforms' ) } /> }

			{ result && result.total > 0 && (
				<div className="lb-card lbf-entries">
					<table className="lbf-entries__table">
						<thead>
							<tr>
								<th>{ __( 'Date', 'libreforms' ) }</th>
								{ visible.map( ( column ) => (
									<th key={ column.name }>{ column.label }</th>
								) ) }
								<th className="screen-reader-text">{ __( 'Actions', 'libreforms' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ result.items.map( ( entry ) => (
								<Fragment key={ entry.id }>
									<tr className={ `${ entry.is_new ? 'is-new' : '' }${ open === entry.id ? ' is-open' : '' }` } onClick={ () => setOpen( open === entry.id ? null : entry.id ) }>
										<td>
											{ entry.is_new && <span className="lbf-dot" title={ __( 'New', 'libreforms' ) } /> }
											{ entry.date }
										</td>
										{ visible.map( ( column ) => (
											<td key={ column.name }>{ entry.values[ column.name ] }</td>
										) ) }
										<td className="lbf-entries__actions">
											<Button icon={ open === entry.id ? 'arrow-up-alt2' : 'arrow-down-alt2' } size="small" label={ __( 'See everything', 'libreforms' ) } />
											<Button
												icon="trash"
												size="small"
												isDestructive
												label={ __( 'Delete', 'libreforms' ) }
												onClick={ ( event ) => {
													event.stopPropagation();
													remove( entry );
												} }
											/>
										</td>
									</tr>
									{ open === entry.id && (
										<tr className="lbf-entries__detail">
											<td colSpan={ visible.length + 2 }>
												<dl>
													{ result.columns.map( ( column ) => (
														<Fragment key={ column.name }>
															<dt>{ column.label }</dt>
															<dd>{ /^https?:\/\//.test( entry.values[ column.name ] ) ? <a href={ entry.values[ column.name ] } target="_blank" rel="noreferrer">{ entry.values[ column.name ] }</a> : entry.values[ column.name ] || '—' }</dd>
														</Fragment>
													) ) }
													{ entry.user && (
														<>
															<dt>{ __( 'User', 'libreforms' ) }</dt>
															<dd>{ entry.user }</dd>
														</>
													) }
													{ entry.post && (
														<>
															<dt>{ __( 'Post created', 'libreforms' ) }</dt>
															<dd>
																<a href={ entry.post.url }>{ entry.post.title || __( '(no title)', 'libreforms' ) }</a>
															</dd>
														</>
													) }
													{ entry.page && (
														<>
															<dt>{ __( 'Sent from', 'libreforms' ) }</dt>
															<dd>
																<a href={ entry.page } target="_blank" rel="noreferrer">{ entry.page }</a>
															</dd>
														</>
													) }
												</dl>
											</td>
										</tr>
									) }
								</Fragment>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ result?.pages > 1 && (
				<div className="lbf-pager">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
						{ __( '← Newer', 'libreforms' ) }
					</Button>
					<span>{ sprintf( /* translators: 1: page, 2: pages */ __( 'Page %1$d of %2$d', 'libreforms' ), page, result.pages ) }</span>
					<Button variant="secondary" disabled={ page >= result.pages } onClick={ () => setPage( page + 1 ) }>
						{ __( 'Older →', 'libreforms' ) }
					</Button>
				</div>
			) }
		</>
	);
}

/* -------------------------------------------------------------------------- app */

function App( { section } ) {
	return (
		<AppShell product={ { name: 'LibreForms', icon: data.icon || 'feedback' } } tabs={ data.tabs } current={ section }>
			{ section === 'forms' && <KindScreen kind="form" meta={ FORM_KIND } /> }
			{ section === 'entries' && <Entries /> }
		</AppShell>
	);
}

// After every script loaded, so other plugins can add their action types first.
domReady( () => {
	const mount = document.getElementById( 'libreforms-app' );

	if ( mount ) {
		createRoot( mount ).render( <App section={ mount.dataset.section } /> );
	}
} );
