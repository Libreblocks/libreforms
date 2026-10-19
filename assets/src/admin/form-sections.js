/**
 * Editor sections of a form: general, fields, after sending (actions), protection.
 * Rows and the field builder come from LibreForge so every editor feels the same.
 */
import { Button, TextareaControl, TextControl } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import { IconTile, SettingRow } from '@libreblocks/ui';

import FieldBuilder from '@libreforge/admin/field-builder';
import { CheckboxList } from '@libreforge/admin/components';
import { Choose, followSlug, Identifier, LongText, Text, Toggle } from '@libreforge/admin/forms';

const data = window.libreformsAdmin || { types: [], adminEmail: '' };

const BUILT_IN = {
	save_entry: {
		label: __( 'Save the entry', 'libreforms' ),
		help: __( 'Keeps a copy in LibreForms → Entries, with CSV export.', 'libreforms' ),
		icon: 'database',
	},
	email: {
		label: __( 'Send an e-mail', 'libreforms' ),
		help: __( 'To you, to the team or to the visitor.', 'libreforms' ),
		icon: 'email-alt',
	},
	create_post: {
		label: __( 'Create a post', 'libreforms' ),
		help: __( 'Turns the answers into a post of any Post Type (front-end submission).', 'libreforms' ),
		icon: 'admin-post',
	},
	webhook: {
		label: __( 'Send to a webhook', 'libreforms' ),
		help: __( 'Sends the answers as JSON to n8n, Zapier, Make, a CRM…', 'libreforms' ),
		icon: 'rest-api',
	},
	redirect: {
		label: __( 'Go to another page', 'libreforms' ),
		help: __( 'After sending, the visitor goes to a thank-you page.', 'libreforms' ),
		icon: 'external',
	},
};

/**
 * Built-in actions plus the ones other plugins add with:
 *
 *     wp.hooks.addFilter( 'libreforms.actionTypes', 'my-plugin', ( types ) => ( {
 *         ...types,
 *         my_action: { label, help, icon, defaults: {}, Settings: ( { settings, set, config, environment } ) => … },
 *     } ) );
 */
export const actionTypes = () => applyFilters( 'libreforms.actionTypes', BUILT_IN );

/** Details the visitor does not type, listed at the end of the e-mail. */
const META = [
	{ value: 'date', label: __( 'Date', 'libreforms' ) },
	{ value: 'time', label: __( 'Time', 'libreforms' ) },
	{ value: 'page_url', label: __( 'Page URL', 'libreforms' ) },
	{ value: 'item', label: __( 'Item of the page', 'libreforms' ) },
	{ value: 'user', label: __( 'Logged-in user', 'libreforms' ) },
	{ value: 'user_agent', label: __( 'Browser (user agent)', 'libreforms' ) },
	{ value: 'ip', label: __( 'IP address', 'libreforms' ) },
	{ value: 'form', label: __( 'Form name', 'libreforms' ) },
];

const META_DEFAULT = [ 'date', 'time', 'page_url' ];

export const newAction = ( type ) => ( {
	type,
	to: type === 'email' ? data.adminEmail : '',
	subject: '',
	message: '',
	reply_to: '',
	meta: META_DEFAULT,
	post_type: '',
	status: 'pending',
	title_field: '',
	content_field: '',
	image_field: '',
	save_meta: true,
	url: '',
	settings: { ...( actionTypes()[ type ]?.defaults || {} ) },
} );

export const FORM_DEFAULTS = {
	title: '',
	fields: [],
	submit_label: '',
	success_message: '',
	hide_on_success: true,
	actions: [ newAction( 'save_entry' ), newAction( 'email' ) ],
	logged_in_only: false,
	min_seconds: 3,
	limit_per_hour: 20,
};

/** Fields of the form as select options (only the ones that hold a value). */
const fieldOptions = ( fields, types, empty ) => [
	{ value: '', label: empty },
	...( fields || [] )
		.filter( ( field ) => field.name && field.type !== 'html' && ( ! types || types.includes( field.type ) ) )
		.map( ( field ) => ( { value: field.name, label: `${ field.label || field.name } (${ field.name })` } ) ),
];

function TokenHelp( { fields } ) {
	const named = ( fields || [] ).filter( ( field ) => field.name && field.type !== 'html' );

	return (
		<div className="lbf-tokens">
			<p>{ __( 'Use the answers in the texts: click to copy.', 'libreforms' ) }</p>
			<div className="lbf-tokens__list">
				{ named.map( ( field ) => (
					<code key={ field.name } title={ field.label } onClick={ () => navigator.clipboard?.writeText( `{{form.value|${ field.name }}}` ) }>
						{ `{{form.value|${ field.name }}}` }
					</code>
				) ) }
				<code title={ __( 'Every answer, as a table', 'libreforms' ) } onClick={ () => navigator.clipboard?.writeText( '{{form.all}}' ) }>
					{ '{{form.all}}' }
				</code>
				<code title={ __( 'Form title', 'libreforms' ) } onClick={ () => navigator.clipboard?.writeText( '{{form.title}}' ) }>
					{ '{{form.title}}' }
				</code>
				{ META.map( ( item ) => (
					<code key={ item.value } title={ item.label } onClick={ () => navigator.clipboard?.writeText( `{{form.meta|${ item.value }}}` ) }>
						{ `{{form.meta|${ item.value }}}` }
					</code>
				) ) }
			</div>
		</div>
	);
}

function ActionSettings( { action, set, config, environment } ) {
	const fields = config.fields || [];

	const extra = actionTypes()[ action.type ];

	if ( extra?.Settings && ! BUILT_IN[ action.type ] ) {
		const Settings = extra.Settings;
		return <Settings settings={ action.settings || {} } set={ ( patch ) => set( { settings: { ...( action.settings || {} ), ...patch } } ) } config={ config } environment={ environment } />;
	}

	switch ( action.type ) {
		case 'save_entry':
			return <p className="lf-intro">{ __( 'Nothing to set up: every answer appears in LibreForms → Entries.', 'libreforms' ) }</p>;

		case 'email':
			return (
				<>
					<Text label={ __( 'Send to', 'libreforms' ) } help={ __( 'One or more e-mails separated by commas. To answer the visitor, use {{form.value|email}}.', 'libreforms' ) } value={ action.to } placeholder={ data.adminEmail } onChange={ ( to ) => set( { to } ) } />
					<Text label={ __( 'Subject', 'libreforms' ) } help={ __( 'Empty = "New submission: form title".', 'libreforms' ) } value={ action.subject } onChange={ ( subject ) => set( { subject } ) } />
					<SettingRow label={ __( 'Message', 'libreforms' ) } help={ __( 'Empty = a table with every answer.', 'libreforms' ) }>
						<TextareaControl label={ __( 'Message', 'libreforms' ) } hideLabelFromVision rows={ 5 } value={ action.message } onChange={ ( message ) => set( { message } ) } __nextHasNoMarginBottom />
					</SettingRow>
					<SettingRow label={ __( 'Submission details', 'libreforms' ) } help={ __( 'Listed at the end of the e-mail. The visitor does not fill them in. The IP address is personal data: only include it if you need it.', 'libreforms' ) }>
						<CheckboxList options={ META } value={ action.meta ?? META_DEFAULT } onChange={ ( meta ) => set( { meta } ) } />
					</SettingRow>
					<Choose label={ __( 'Reply goes to', 'libreforms' ) } help={ __( 'Clicking "Reply" in your e-mail answers the visitor.', 'libreforms' ) } value={ action.reply_to } options={ fieldOptions( fields, [ 'email' ], __( '— Nobody —', 'libreforms' ) ) } onChange={ ( reply_to ) => set( { reply_to } ) } />
				</>
			);

		case 'create_post':
			return (
				<>
					<Choose
						label={ __( 'Post Type', 'libreforms' ) }
						help={ __( 'Which kind of content is created.', 'libreforms' ) }
						value={ action.post_type }
						options={ [ { value: '', label: __( '— Choose —', 'libreforms' ) }, ...( environment.post_types || [] ).filter( ( type ) => ! [ 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'lbforge_template' ].includes( type.value ) ) ] }
						onChange={ ( post_type ) => set( { post_type } ) }
					/>
					<Choose
						label={ __( 'Status', 'libreforms' ) }
						help={ __( '"Pending review" lets you approve before it appears on the site.', 'libreforms' ) }
						value={ action.status }
						options={ [
							{ value: 'pending', label: __( 'Pending review', 'libreforms' ) },
							{ value: 'draft', label: __( 'Draft', 'libreforms' ) },
							{ value: 'publish', label: __( 'Published', 'libreforms' ) },
							{ value: 'private', label: __( 'Private', 'libreforms' ) },
						] }
						onChange={ ( status ) => set( { status } ) }
					/>
					<Choose label={ __( 'Title comes from', 'libreforms' ) } value={ action.title_field } options={ fieldOptions( fields, null, __( '— Form title and date —', 'libreforms' ) ) } onChange={ ( title_field ) => set( { title_field } ) } />
					<Choose label={ __( 'Content comes from', 'libreforms' ) } value={ action.content_field } options={ fieldOptions( fields, [ 'textarea', 'wysiwyg', 'text' ], __( '— Empty —', 'libreforms' ) ) } onChange={ ( content_field ) => set( { content_field } ) } />
					<Choose label={ __( 'Featured image comes from', 'libreforms' ) } value={ action.image_field } options={ fieldOptions( fields, [ 'media' ], __( '— None —', 'libreforms' ) ) } onChange={ ( image_field ) => set( { image_field } ) } />
					<Toggle
						label={ __( 'Fill the Meta Box fields', 'libreforms' ) }
						help={ __( 'Answers go to the custom fields with the same name. Ex.: a form field "price" fills the Meta Box field "price".', 'libreforms' ) }
						checked={ action.save_meta }
						onChange={ ( save_meta ) => set( { save_meta } ) }
					/>
				</>
			);

		case 'webhook':
			return <Text label={ __( 'Webhook address', 'libreforms' ) } help={ __( 'The answers are sent as JSON with a POST request.', 'libreforms' ) } type="url" value={ action.url } placeholder="https://" mono onChange={ ( url ) => set( { url } ) } />;

		case 'redirect':
			return <Text label={ __( 'Page address', 'libreforms' ) } help={ __( 'Ex.: https://yoursite.com/thank-you', 'libreforms' ) } type="url" value={ action.url } placeholder="https://" mono onChange={ ( url ) => set( { url } ) } />;
	}

	return null;
}

function Actions( { config, set, errors, environment } ) {
	const actions = config.actions || [];
	const update = ( index, patch ) => set( { actions: actions.map( ( action, i ) => ( i === index ? { ...action, ...patch } : action ) ) } );
	const remove = ( index ) => set( { actions: actions.filter( ( _, i ) => i !== index ) } );
	const add = ( type ) => set( { actions: [ ...actions, newAction( type ) ] } );
	const types = actionTypes();

	return (
		<>
			<p className="lf-intro">{ __( 'What happens when someone sends the form. They run in this order.', 'libreforms' ) }</p>

			{ actions.length === 0 && <p className="lbf-warning">{ __( 'No actions: the answers will not be saved or sent anywhere.', 'libreforms' ) }</p> }

			{ actions.map( ( action, index ) => {
				const meta = types[ action.type ] || { label: action.type };
				const error = errors[ `actions.${ index }` ];

				return (
					<div key={ index } className={ `lb-card lbf-action${ error ? ' has-error' : '' }` }>
						<div className="lbf-action__head">
							<IconTile icon={ meta.icon } />
							<span className="lbf-action__title">
								<strong>{ meta.label }</strong>
								<small>{ meta.help }</small>
							</span>
							<Button icon="trash" size="small" isDestructive label={ __( 'Remove action', 'libreforms' ) } onClick={ () => remove( index ) } />
						</div>
						{ error && <p className="lf-row__error">{ error }</p> }
						<ActionSettings action={ action } set={ ( patch ) => update( index, patch ) } config={ config } environment={ environment } />
					</div>
				);
			} ) }

			<div className="lbf-add-actions">
				<span>{ __( 'Add action:', 'libreforms' ) }</span>
				{ Object.entries( types ).map( ( [ type, meta ] ) => (
					<Button key={ type } variant="secondary" icon={ meta.icon } onClick={ () => add( type ) }>
						{ meta.label }
					</Button>
				) ) }
			</div>

			{ actions.some( ( action ) => [ 'email', 'redirect' ].includes( action.type ) ) && <TokenHelp fields={ config.fields } /> }
		</>
	);
}

export function formSections( { config, set, errors, environment, slug, setSlug, isNew } ) {
	const syncSlug = followSlug( { slug, setSlug, isNew, maxLength: 64, previous: config.title } );
	const formEnvironment = { ...environment, field_types: [ ...( environment.field_types || [] ), ...( data.formTypes || [] ) ].filter( ( type ) => data.types.includes( type.type ) ) };
	const fields = config.fields || [];

	return [
		{
			id: 'general',
			title: __( 'General settings', 'libreforms' ),
			icon: 'admin-generic',
			open: true,
			keys: [ 'title' ],
			content: (
				<>
					<Text
						label={ __( 'Form name', 'libreforms' ) }
						help={ __( 'Only you see it. Ex.: Contact, Quote request', 'libreforms' ) }
						value={ config.title }
						placeholder={ __( 'Contact', 'libreforms' ) }
						error={ errors.title }
						onChange={ ( title ) => {
							syncSlug( title );
							set( { title } );
						} }
					/>
					<Text label={ __( 'Button text', 'libreforms' ) } value={ config.submit_label } placeholder={ __( 'Send', 'libreforms' ) } onChange={ ( submit_label ) => set( { submit_label } ) } />
					<LongText label={ __( 'Message after sending', 'libreforms' ) } help={ __( 'Shown to the visitor when everything worked.', 'libreforms' ) } value={ config.success_message } placeholder={ __( 'Thank you! Your message was sent.', 'libreforms' ) } onChange={ ( success_message ) => set( { success_message } ) } />
					<Toggle label={ __( 'Hide the form after sending', 'libreforms' ) } help={ __( 'Only the message stays on the page.', 'libreforms' ) } checked={ config.hide_on_success } onChange={ ( hide_on_success ) => set( { hide_on_success } ) } />
				</>
			),
		},
		{
			id: 'fields',
			title: __( 'Form fields', 'libreforms' ),
			icon: 'forms',
			open: true,
			count: fields.length,
			keys: [ 'fields.' ],
			content: (
				<>
					<p className="lf-intro">{ __( 'What the visitor fills in. The name of each field is how its answer is saved (and which Meta Box field it fills).', 'libreforms' ) }</p>
					<FieldBuilder fields={ fields } onChange={ ( value ) => set( { fields: value } ) } environment={ formEnvironment } errors={ errors } />
				</>
			),
		},
		{
			id: 'actions',
			title: __( 'After sending', 'libreforms' ),
			icon: 'controls-play',
			open: true,
			count: ( config.actions || [] ).length,
			keys: [ 'actions.' ],
			content: <Actions config={ config } set={ set } errors={ errors } environment={ environment } />,
		},
		{
			id: 'protection',
			title: __( 'Spam protection', 'libreforms' ),
			icon: 'shield',
			keys: [],
			content: (
				<>
					<p className="lf-intro">{ __( 'Forms already block robots without annoying captchas: a hidden trap field, a minimum time to fill in and a limit per hour.', 'libreforms' ) }</p>
					<Toggle label={ __( 'Only logged-in users', 'libreforms' ) } help={ __( 'Visitors see a link to log in instead of the form.', 'libreforms' ) } checked={ config.logged_in_only } onChange={ ( logged_in_only ) => set( { logged_in_only } ) } />
					<SettingRow label={ __( 'Minimum time to fill in (seconds)', 'libreforms' ) } help={ __( 'Robots send forms instantly. 0 turns it off.', 'libreforms' ) }>
						<TextControl label={ __( 'Seconds', 'libreforms' ) } hideLabelFromVision type="number" min={ 0 } max={ 60 } value={ config.min_seconds } onChange={ ( value ) => set( { min_seconds: Number( value ) } ) } __nextHasNoMarginBottom />
					</SettingRow>
					<SettingRow label={ __( 'Submissions per hour per visitor', 'libreforms' ) } help={ __( '0 = no limit.', 'libreforms' ) }>
						<TextControl label={ __( 'Per hour', 'libreforms' ) } hideLabelFromVision type="number" min={ 0 } max={ 1000 } value={ config.limit_per_hour } onChange={ ( value ) => set( { limit_per_hour: Number( value ) } ) } __nextHasNoMarginBottom />
					</SettingRow>
				</>
			),
		},
		{
			id: 'advanced',
			title: __( 'Advanced settings', 'libreforms' ),
			icon: 'admin-tools',
			keys: [ 'slug' ],
			content: <Identifier slug={ slug } setSlug={ setSlug } errors={ errors } maxLength={ 64 } isNew={ isNew } help={ __( 'Internal name of the form. Filled in automatically.', 'libreforms' ) } />,
		},
	];
}

/** Sidebar card: how to put the form on a page. */
export function EmbedHelp( { definition } ) {
	if ( ! definition.id ) {
		return null;
	}

	const shortcode = `[libreforms id="${ definition.id }"]`;

	return (
		<div className="lb-card lf-aside-card lbf-embed">
			<h3>{ __( 'Show it on the site', 'libreforms' ) }</h3>
			<ol>
				<li>{ __( 'Gutenberg: add the "Form" block and choose this form.', 'libreforms' ) }</li>
				<li>{ __( 'Elementor: drag the "Form" widget (LibreForge category).', 'libreforms' ) }</li>
				<li>
					{ __( 'Anywhere else, the shortcode:', 'libreforms' ) }
					<code onClick={ () => navigator.clipboard?.writeText( shortcode ) } title={ __( 'Click to copy', 'libreforms' ) }>
						{ shortcode }
					</code>
				</li>
			</ol>
		</div>
	);
}
