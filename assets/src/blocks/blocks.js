/**
 * LibreForms block: Form (rendered on the server).
 */
import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { Button, PanelBody, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import form from '../../../blocks/form/block.json';

const manageUrl = `${ window.location.origin }/wp-admin/admin.php?page=libreforms`;

function useForms() {
	const [ forms, setForms ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/libreblocks/v1/forms' } ).then( setForms ).catch( () => setForms( [] ) );
	}, [] );

	return forms;
}

function FormEdit( { attributes, setAttributes, name } ) {
	const forms = useForms();
	const props = useBlockProps();

	const picker = ! forms ? (
		<Spinner />
	) : (
		<SelectControl
			label={ __( 'Form', 'libreforms' ) }
			help={ __( 'Create forms in LibreForms → Forms.', 'libreforms' ) }
			value={ String( attributes.formId || '' ) }
			options={ [ { value: '', label: __( '— Choose —', 'libreforms' ) }, ...forms.map( ( item ) => ( { value: String( item.value ), label: item.label } ) ) ] }
			onChange={ ( value ) => setAttributes( { formId: Number( value ) || 0 } ) }
			__nextHasNoMarginBottom
		/>
	);

	return (
		<div { ...props }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'libreforms' ) }>
					{ picker }
					<Button variant="link" href={ manageUrl } target="_blank">
						{ __( 'Manage forms', 'libreforms' ) }
					</Button>
				</PanelBody>
			</InspectorControls>
			{ attributes.formId ? (
				<div inert="">
					<ServerSideRender block={ name } attributes={ attributes } skipBlockSupportAttributes />
				</div>
			) : (
				<div className="lbf-notice">
					<p>
						{ forms && forms.length === 0
							? __( 'Create your first form in LibreForms → Forms, then choose it here.', 'libreforms' )
							: __( 'Which form do you want to show?', 'libreforms' ) }
					</p>
					{ forms && forms.length > 0 && picker }
				</div>
			) }
		</div>
	);
}

registerBlockType( form.name, {
	...form,
	title: __( 'Form', 'libreforms' ),
	description: __( 'Shows a form made in LibreForms.', 'libreforms' ),
	edit: FormEdit,
	save: () => null,
} );
