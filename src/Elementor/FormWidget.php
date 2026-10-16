<?php
/**
 * Elementor widget: LibreForms form.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use LibreBlocks\Forms\Plugin;

defined( 'ABSPATH' ) || exit;

final class FormWidget extends \Elementor\Widget_Base {

	public function get_name(): string {
		return 'libreforms-form';
	}

	public function get_title(): string {
		return __( 'Form', 'libreforms' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	public function get_categories(): array {
		return [ 'libreforge' ];
	}

	public function get_keywords(): array {
		return [ 'form', 'contact', 'libreforms' ];
	}

	public function get_style_depends(): array {
		return [ 'libreforms' ];
	}

	/**
	 * @return array<string, string>
	 */
	private function form_options(): array {
		$options = [ '' => __( '— Choose —', 'libreforms' ) ];

		foreach ( Plugin::instance()->forms()->all() as $form ) {
			$options[ (string) $form->id ] = $form->title();
		}

		return $options;
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'content_section', [ 'label' => __( 'Form', 'libreforms' ) ] );

		$this->add_control( 'form_id', [
			'label'       => __( 'Form', 'libreforms' ),
			'type'        => Controls_Manager::SELECT,
			'options'     => $this->form_options(),
			'default'     => '',
			'description' => __( 'Create forms in LibreForms → Forms.', 'libreforms' ),
		] );

		$this->end_controls_section();

		$this->start_controls_section( 'style_fields', [ 'label' => __( 'Fields', 'libreforms' ), 'tab' => Controls_Manager::TAB_STYLE ] );

		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'label_typography', 'label' => __( 'Labels', 'libreforms' ), 'selector' => '{{WRAPPER}} .lbf-label' ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'input_typography', 'label' => __( 'Typed text', 'libreforms' ), 'selector' => '{{WRAPPER}} .lbf-field input, {{WRAPPER}} .lbf-field select, {{WRAPPER}} .lbf-field textarea' ] );

		$this->add_control( 'accent', [
			'label'     => __( 'Accent color', 'libreforms' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .lbf-form' => '--lbf-accent: {{VALUE}};' ],
		] );

		$this->add_control( 'field_background', [
			'label'     => __( 'Field background', 'libreforms' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .lbf-field input:not([type=checkbox]):not([type=radio]), {{WRAPPER}} .lbf-field select, {{WRAPPER}} .lbf-field textarea' => 'background-color: {{VALUE}};' ],
		] );

		$this->add_control( 'field_border', [
			'label'     => __( 'Border color', 'libreforms' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .lbf-form' => '--lbf-border: {{VALUE}};' ],
		] );

		$this->add_responsive_control( 'radius', [
			'label'      => __( 'Rounded corners', 'libreforms' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 30 ] ],
			'selectors'  => [ '{{WRAPPER}} .lbf-form' => '--lbf-radius: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_responsive_control( 'gap', [
			'label'      => __( 'Space between fields', 'libreforms' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px', 'em' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 60 ], 'em' => [ 'min' => 0, 'max' => 4, 'step' => 0.1 ] ],
			'selectors'  => [ '{{WRAPPER}} .lbf-form' => '--lbf-gap: {{SIZE}}{{UNIT}};' ],
		] );

		$this->end_controls_section();

		$this->start_controls_section( 'style_button', [ 'label' => __( 'Button', 'libreforms' ), 'tab' => Controls_Manager::TAB_STYLE ] );

		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'button_typography', 'selector' => '{{WRAPPER}} .lbf-submit' ] );

		$this->add_control( 'button_color', [
			'label'     => __( 'Text color', 'libreforms' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .lbf-submit' => 'color: {{VALUE}};' ],
		] );

		$this->add_control( 'button_background', [
			'label'     => __( 'Background', 'libreforms' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .lbf-submit' => 'background-color: {{VALUE}}; border-color: {{VALUE}};' ],
		] );

		$this->add_responsive_control( 'button_align', [
			'label'     => __( 'Alignment', 'libreforms' ),
			'type'      => Controls_Manager::CHOOSE,
			'options'   => [
				'left'   => [ 'title' => __( 'Left', 'libreforms' ), 'icon' => 'eicon-text-align-left' ],
				'center' => [ 'title' => __( 'Center', 'libreforms' ), 'icon' => 'eicon-text-align-center' ],
				'right'  => [ 'title' => __( 'Right', 'libreforms' ), 'icon' => 'eicon-text-align-right' ],
			],
			'selectors' => [ '{{WRAPPER}} .lbf-footer' => 'text-align: {{VALUE}};' ],
		] );

		$this->end_controls_section();
	}

	protected function render(): void {
		$s = $this->get_settings_for_display();

		echo Plugin::instance()->view()->form( [ 'form_id' => (int) ( $s['form_id'] ?? 0 ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the View.
	}
}
