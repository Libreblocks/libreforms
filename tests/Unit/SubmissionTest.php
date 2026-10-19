<?php

use LibreBlocks\Forge\Model\Definition;
use LibreBlocks\Forge\Model\DefinitionRepository;
use LibreBlocks\Forge\Model\FieldList;
use LibreBlocks\Forms\Entries;
use LibreBlocks\Forms\FormKind;
use LibreBlocks\Forms\Forms;
use LibreBlocks\Forms\Protection;
use LibreBlocks\Forms\Submission;
use LibreBlocks\Forms\View;
use PHPUnit\Framework\TestCase;

final class SubmissionTest extends TestCase {

	private DefinitionRepository $repository;
	private FormKind $kind;
	private Submission $submission;

	protected function setUp(): void {
		global $wpdb;

		WPStub::reset();
		FormsStub::reset();
		WPStub::$registered['post_types'] = [ 'imovel' ];
		$wpdb             = new FormsWpdb( new FakeWpdb() );
		$this->repository = new DefinitionRepository( libreblocks()->cache() );
		$this->kind       = new FormKind( new FieldList( libreblocks()->fields() ) );
		$forms            = new Forms( $this->repository, libreblocks()->fields() );
		$this->submission = new Submission( $forms, new Entries(), libreblocks()->tokens() );
		$this->submission->register_tokens( libreblocks()->tokens() );
	}

	private function form( array $config ): Definition {
		$definition = new Definition( 'form', 'contato', $this->kind->sanitize( $config + [
			'title'  => 'Contato',
			'fields' => [
				[ 'name' => 'nome', 'label' => 'Nome', 'type' => 'text', 'required' => true ],
				[ 'name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true ],
				[ 'name' => 'assunto', 'label' => 'Assunto', 'type' => 'select', 'options' => [ [ 'value' => 'venda', 'label' => 'Quero vender' ], [ 'value' => 'compra', 'label' => 'Quero comprar' ] ] ],
				[ 'name' => 'empresa', 'label' => 'Empresa', 'type' => 'text', 'required' => true, 'conditions' => [ 'rules' => [ [ 'field' => 'assunto', 'operator' => '=', 'value' => 'venda' ] ] ] ],
				[ 'name' => 'mensagem', 'label' => 'Mensagem', 'type' => 'textarea' ],
			],
		] ) );

		return $this->repository->save( $definition );
	}

	/** Input as the browser sends it, filled in 10 seconds ago. */
	private function input( array $values ): array {
		$timer = (string) ( time() - 10 );

		return $values + [ View::TIMER => $timer . '.' . substr( hash_hmac( 'sha256', $timer, wp_salt( 'nonce' ) ), 0, 20 ) ];
	}

	public function test_valid_submission_runs_every_action_in_order(): void {
		$form = $this->form( [
			'success_message' => 'Obrigado, {{form.value|nome}}!',
			'actions'         => [
				[ 'type' => 'save_entry' ],
				[ 'type' => 'email', 'to' => 'equipe@example.test, {{form.value|email}}', 'subject' => 'Contato de {{form.value|nome}} ({{form.value|assunto}})', 'reply_to' => 'email' ],
				[ 'type' => 'email', 'to' => 'ti@example.test', 'subject' => 'IP {{form.meta|ip}}', 'message' => 'Só detalhes', 'meta' => [ 'ip' ] ],
				[ 'type' => 'create_post', 'post_type' => 'imovel', 'title_field' => 'nome', 'content_field' => 'mensagem' ],
				[ 'type' => 'webhook', 'url' => 'https://hooks.example.test/n8n' ],
			],
		] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$result = $this->submission->process( $form, $this->input( [ 'nome' => 'Ana', 'email' => 'ana@example.test', 'assunto' => 'compra', 'mensagem' => 'Olá', '_lbf_ref' => 'https://example.test/contato/' ] ), [] );

		$this->assertTrue( $result['success'], (string) $result['message'] );
		$this->assertSame( 'Obrigado, Ana!', $result['message'] );

		// The post is created first, so the entry knows its ID.
		$this->assertSame( 'Ana', FormsStub::$posts[0]['post_title'] );
		$this->assertSame( 'pending', FormsStub::$posts[0]['post_status'] );
		$this->assertSame( 'compra', FormsStub::$post_meta[101]['assunto'] );
		$this->assertArrayNotHasKey( 'nome', FormsStub::$post_meta[101], 'The title field is not repeated as meta.' );
		$this->assertSame( 101, FormsStub::$entries[0]['post_id'] );

		$mail = FormsStub::$mails[0];
		$this->assertSame( [ 'equipe@example.test', 'ana@example.test' ], array_values( $mail['to'] ) );
		$this->assertSame( 'Contato de Ana (Quero comprar)', $mail['subject'], 'Option labels, not raw values.' );
		$this->assertContains( 'Reply-To: ana@example.test', $mail['headers'] );
		$this->assertStringContainsString( '<th', $mail['message'], 'Empty message = table of answers.' );
		$this->assertStringNotContainsString( 'Empresa', $mail['message'], 'Hidden fields are left out.' );
		$this->assertStringContainsString( 'https://example.test/contato/', $mail['message'], 'Page URL listed by default.' );
		$this->assertStringContainsString( 'Date:', $mail['message'] );
		$this->assertStringNotContainsString( '203.0.113.9', $mail['message'], 'IP only when chosen.' );

		$this->assertSame( 'IP 203.0.113.9', FormsStub::$mails[1]['subject'] );
		$this->assertStringContainsString( '203.0.113.9', FormsStub::$mails[1]['message'] );
		$this->assertStringNotContainsString( 'Date:', FormsStub::$mails[1]['message'], 'Only the chosen details.' );

		$body = json_decode( FormsStub::$webhooks[0]['body'], true );
		$this->assertSame( 'https://hooks.example.test/n8n', FormsStub::$webhooks[0]['url'] );
		$this->assertSame( 'Ana', $body['values']['nome'] );
		$this->assertSame( 101, $body['post_id'] );
	}

	public function test_required_fields_and_conditions(): void {
		$form = $this->form( [ 'actions' => [ [ 'type' => 'save_entry' ] ] ] );

		$result = $this->submission->process( $form, $this->input( [ 'nome' => '', 'email' => 'not-an-email', 'assunto' => 'venda' ] ), [] );

		$this->assertFalse( $result['success'] );
		$this->assertSame( [ 'nome', 'email', 'empresa' ], array_keys( $result['errors'] ), 'Empresa is required once "venda" is chosen.' );
		$this->assertSame( 'not-an-email', $result['values']['email'], 'What was typed comes back to refill the form.' );
		$this->assertSame( [], FormsStub::$entries );
	}

	public function test_honeypot_pretends_success_and_does_nothing(): void {
		$form   = $this->form( [ 'actions' => [ [ 'type' => 'save_entry' ], [ 'type' => 'email', 'to' => 'a@example.test' ] ] ] );
		$result = $this->submission->process( $form, $this->input( [ 'nome' => 'Bot', 'email' => 'bot@example.test', View::HONEYPOT => 'http://spam' ] ), [] );

		$this->assertTrue( $result['success'] );
		$this->assertSame( [], FormsStub::$entries );
		$this->assertSame( [], FormsStub::$mails );
	}

	public function test_too_fast_tampered_and_rate_limited(): void {
		$form = $this->form( [ 'actions' => [ [ 'type' => 'save_entry' ] ], 'limit_per_hour' => 2 ] );
		$ok   = [ 'nome' => 'Ana', 'email' => 'ana@example.test' ];

		$fast = $this->submission->process( $form, $ok + [ View::TIMER => Protection::timer() ], [] );
		$this->assertFalse( $fast['success'], 'Sent in 0 seconds.' );

		$forged = $this->submission->process( $form, $ok + [ View::TIMER => ( time() - 60 ) . '.abc' ], [] );
		$this->assertFalse( $forged['success'], 'Timer with a wrong signature.' );

		FormsStub::$transients = [];
		$this->assertTrue( $this->submission->process( $form, $this->input( $ok ), [] )['success'] );
		$this->assertTrue( $this->submission->process( $form, $this->input( $ok ), [] )['success'] );
		$this->assertFalse( $this->submission->process( $form, $this->input( $ok ), [] )['success'], 'Third one in the same hour.' );
	}

	public function test_logged_in_only(): void {
		$form = $this->form( [ 'logged_in_only' => true, 'actions' => [ [ 'type' => 'save_entry' ] ] ] );

		$this->assertFalse( $this->submission->process( $form, $this->input( [ 'nome' => 'Ana', 'email' => 'ana@example.test' ] ), [] )['success'] );

		FormsStub::$logged_in = true;
		$this->assertTrue( $this->submission->process( $form, $this->input( [ 'nome' => 'Ana', 'email' => 'ana@example.test' ] ), [] )['success'] );
	}

	public function test_actions_added_by_other_plugins(): void {
		$extra = new class() implements LibreBlocks\Forms\ActionType {
			public array $log = [];
			public function type(): string { return 'reserve'; }
			public function early(): bool { return true; }
			public function sanitize( array $settings ): array { return [ 'limit' => (int) ( $settings['limit'] ?? 0 ) ]; }
			public function validate( array $settings, Definition $form ): ?string { return $settings['limit'] > 0 ? null : 'Set a limit.'; }
			public function check( array $settings, LibreBlocks\Forms\SubmissionContext $context ): array {
				return 'Bloqueado' === $context->value( 'nome' ) ? [ 'nome' => 'Taken.' ] : [];
			}
			public function run( array $settings, LibreBlocks\Forms\SubmissionContext $context ): void {
				$this->log[]             = 'run';
				$context->data['reserve'] = 'R-' . $settings['limit'];
			}
		};

		$this->submission->register( $extra );
		$this->kind = new FormKind( new FieldList( libreblocks()->fields() ), fn() => $this->submission->types() );

		$form = $this->form( [ 'actions' => [ [ 'type' => 'email', 'to' => 'a@example.test', 'subject' => 'x' ], [ 'type' => 'reserve', 'settings' => [ 'limit' => '3', 'junk' => 1 ] ] ] ] );

		$this->assertSame( [ 'limit' => 3 ], $form->get( 'actions' )[1]['settings'], 'Settings cleaned by the action itself.' );
		$this->assertSame( [], $this->kind->validate( $form, $this->repository ) );

		$blocked = $this->submission->process( $form, $this->input( [ 'nome' => 'Bloqueado', 'email' => 'ana@example.test' ] ), [] );
		$this->assertFalse( $blocked['success'] );
		$this->assertSame( [ 'nome' => 'Taken.' ], $blocked['errors'] );
		$this->assertSame( [], FormsStub::$mails, 'Nothing runs when check() refuses.' );

		$this->assertTrue( $this->submission->process( $form, $this->input( [ 'nome' => 'Ana', 'email' => 'ana@example.test' ] ), [] )['success'] );
		$this->assertSame( [ 'run' ], $extra->log );
		$this->assertCount( 1, FormsStub::$mails );
	}

	public function test_kind_validation(): void {
		$definition = new Definition( 'form', 'teste', $this->kind->sanitize( [
			'title'   => 'Teste',
			'fields'  => [
				[ 'name' => 'action', 'label' => 'Ação', 'type' => 'text' ],
				[ 'name' => 'fotos', 'label' => 'Fotos', 'type' => 'gallery' ],
			],
			'actions' => [
				[ 'type' => 'email', 'to' => '' ],
				[ 'type' => 'create_post', 'post_type' => 'nao_existe' ],
				[ 'type' => 'webhook', 'url' => 'ftp://x' ],
				[ 'type' => 'unknown' ],
			],
		] ) );

		$errors = $this->kind->validate( $definition, $this->repository );

		$this->assertArrayHasKey( 'fields.0', $errors, 'Reserved name.' );
		$this->assertArrayHasKey( 'fields.1', $errors, 'Gallery is not a form field type.' );
		$this->assertArrayHasKey( 'actions.0', $errors );
		$this->assertArrayHasKey( 'actions.1', $errors );
		$this->assertArrayHasKey( 'actions.2', $errors );
		$this->assertCount( 3, $definition->get( 'actions' ), 'Unknown actions are dropped.' );
	}
}
