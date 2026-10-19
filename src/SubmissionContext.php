<?php
/**
 * Everything about the submission being processed, shared with the actions
 * and with the {{form.*}} tokens.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Core\Fields\Field;
use LibreBlocks\Forge\Model\Definition;

defined( 'ABSPATH' ) || exit;

final class SubmissionContext {

	/** Post created by "Create a post" (0 = none). */
	public int $post_id = 0;

	/**
	 * Details the visitor did not type: date, time, page_url, user_agent, ip, user, item.
	 *
	 * @var array<string, string>
	 */
	public array $meta = [];

	/**
	 * Results of actions added by other plugins, e.g. [ 'booking' => [...] ].
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * @param Field[]              $fields
	 * @param array<string, mixed> $values Sanitized answers.
	 * @param int                  $object_id Item of the page where the form was sent (0 = none).
	 */
	public function __construct(
		public Definition $form,
		public array $fields,
		public array $values,
		public int $object_id = 0
	) {}

	public function field( string $name ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( $field->name === $name ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @return mixed
	 */
	public function value( string $name ) {
		return $this->values[ $name ] ?? null;
	}
}
