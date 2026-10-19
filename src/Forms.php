<?php
/**
 * Lookup of saved forms and their fields.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Fields\Field;
use LibreBlocks\Core\Fields\Registry;
use LibreBlocks\Forge\Model\Definition;
use LibreBlocks\Forge\Model\DefinitionRepository;

defined( 'ABSPATH' ) || exit;

final class Forms {

	public function __construct( private DefinitionRepository $repository, private Registry $registry ) {}

	public function find( int $id ): ?Definition {
		foreach ( $this->repository->active( FormKind::ID ) as $form ) {
			if ( $form->id === $id ) {
				return $form;
			}
		}

		return null;
	}

	/**
	 * @return Definition[]
	 */
	public function all(): array {
		return $this->repository->active( FormKind::ID );
	}

	/**
	 * Fields a visitor can fill in (glossary options resolved).
	 *
	 * @return Field[]
	 */
	public function fields( Definition $form ): array {
		$types = FormKind::front_types();

		return array_values( array_filter(
			$this->registry->make_all( (array) $form->get( 'fields', [] ) ),
			fn( Field $field ) => in_array( $field->type, $types, true )
		) );
	}

	public function registry(): Registry {
		return $this->registry;
	}
}
