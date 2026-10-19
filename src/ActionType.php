<?php
/**
 * An action added to LibreForms by another plugin (e.g. "Create booking").
 *
 * Register it on the "libreforms/register" action:
 *
 *     add_action( 'libreforms/register', fn( $forms ) => $forms->register_action( new MyAction() ) );
 *
 * Its settings are stored in $action['settings'] and edited by a component the
 * plugin adds to the admin with the JS filter "libreforms.actionTypes".
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

use LibreBlocks\Forge\Model\Definition;

defined( 'ABSPATH' ) || exit;

interface ActionType {

	public function type(): string;

	/**
	 * True to run before the regular actions, so e-mails and entries can use its result
	 * (like "Create a post").
	 */
	public function early(): bool;

	/**
	 * @param array<string, mixed> $settings Raw settings from the editor.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $settings ): array;

	/**
	 * Problem with the saved settings, shown in the form editor.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function validate( array $settings, Definition $form ): ?string;

	/**
	 * Runs before any action, after the fields were validated. Returning errors
	 * (field name => message) stops the submission, e.g. "these dates are taken".
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, string>
	 */
	public function check( array $settings, SubmissionContext $context ): array;

	/**
	 * @param array<string, mixed> $settings
	 */
	public function run( array $settings, SubmissionContext $context ): void;
}
