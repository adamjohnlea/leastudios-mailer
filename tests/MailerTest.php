<?php
/**
 * Tests for Mailer.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Email\Mailer;
use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Mailer\SES\Client;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Mailer\Email\Mailer
 */
class MailerTest extends TestCase {

	private Client $ses_client;
	private Email_Logger $logger;
	private Mailer $mailer;

	/** @var string[] Temp file paths created during a test, cleaned up in tear_down(). */
	private array $temp_files = [];

	public function set_up(): void {
		parent::set_up();

		$this->ses_client = $this->createMock( Client::class );
		$this->logger     = $this->createMock( Email_Logger::class );
		$this->mailer     = new Mailer( $this->ses_client, $this->logger );
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = [];

		parent::tear_down();
	}

	/**
	 * Create a tracked temp file with the given contents and return its absolute path.
	 */
	private function make_temp_file( string $contents = '%PDF-1.4 stub', string $extension = '.pdf' ): string {
		$path = tempnam( sys_get_temp_dir(), 'lsm-att-' );
		// tempnam() returns a name without an extension; rename to give it one.
		$with_ext = $path . $extension;
		rename( $path, $with_ext );
		file_put_contents( $with_ext, $contents );
		$this->temp_files[] = $with_ext;
		return $with_ext;
	}

	public function test_send_returns_null_when_disabled(): void {
		update_option( 'leastudios_mailer_options', [ 'enabled' => false ] );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Test',
				'message' => 'Hello',
				'headers' => [],
			]
		);

		$this->assertNull( $result );
	}

	public function test_send_returns_null_when_no_options(): void {
		delete_option( 'leastudios_mailer_options' );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Test',
				'message' => 'Hello',
				'headers' => [],
			]
		);

		$this->assertNull( $result );
	}

	public function test_send_calls_ses_client_when_enabled(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
				'from_name'  => 'Test Sender',
			]
		);

		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'test-msg-id',
					'error'      => '',
				]
			);

		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with( 'test@example.com', 'Test Subject', 'sent', 'test-msg-id', '' );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Test Subject',
				'message' => 'Hello World',
				'headers' => [],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_send_logs_failure(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->willReturn(
				[
					'success'    => false,
					'message_id' => '',
					'error'      => 'SES error',
				]
			);

		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with( 'test@example.com', 'Fail Test', 'failed', '', 'SES error' );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Fail Test',
				'message' => 'Hello',
				'headers' => [],
			]
		);

		$this->assertFalse( $result );
	}

	public function test_send_parses_string_headers(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'default@example.com',
			]
		);

		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->with(
				$this->stringContains( 'Custom Sender' ),  // from.
				$this->equalTo( [ 'test@example.com' ] ),  // to.
				$this->equalTo( 'Header Test' ),            // subject.
				$this->equalTo( 'Hello' ),                  // body_html (Content-Type: text/html).
				$this->equalTo( '' ),                       // body_text.
				$this->equalTo( [ 'cc@example.com' ] ),     // cc.
				$this->equalTo( [] ),                       // bcc.
				$this->equalTo( [ 'reply@example.com' ] ),  // reply_to.
			)
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'msg-123',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$headers = "From: Custom Sender <custom@example.com>\r\nContent-Type: text/html\r\nCc: cc@example.com\r\nReply-To: reply@example.com";

		$this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Header Test',
				'message' => 'Hello',
				'headers' => $headers,
			]
		);
	}

	public function test_send_returns_false_when_pre_send_filter_returns_null(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		add_filter( 'leastudios_mailer_pre_send', '__return_null' );

		$this->ses_client->expects( $this->never() )->method( 'send_email' );
		$this->ses_client->expects( $this->never() )->method( 'send_raw_email' );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Drop me',
				'message' => 'Hello',
				'headers' => [],
			]
		);

		// Returning false short-circuits pre_wp_mail so core does NOT fall
		// back to its default transport; the email is dropped entirely.
		$this->assertFalse( $result );
	}

	public function test_send_returns_null_when_intercept_filter_is_false(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		add_filter( 'leastudios_mailer_should_intercept', '__return_false' );

		$this->ses_client->expects( $this->never() )->method( 'send_email' );

		$result = $this->mailer->send(
			null,
			[
				'to'      => 'test@example.com',
				'subject' => 'Test',
				'message' => 'Hello',
				'headers' => [],
			]
		);

		$this->assertNull( $result );
	}

	public function test_send_routes_keyed_attachment_to_raw_path(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
				'from_name'  => 'Sender',
			]
		);

		$pdf = $this->make_temp_file( '%PDF-1.4 stub bytes', '.pdf' );

		// Simple path must NOT be invoked when valid attachments are present.
		$this->ses_client->expects( $this->never() )->method( 'send_email' );

		$this->ses_client->expects( $this->once() )
			->method( 'send_raw_email' )
			->with(
				$this->equalTo( 'sender@example.com' ),
				$this->equalTo( 'Sender' ),
				$this->equalTo( [ 'recipient@example.com' ] ),
				$this->equalTo( 'With Attachment' ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->equalTo(
					[
						[
							'name' => 'report.pdf',
							'path' => $pdf,
						],
					]
				),
			)
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'raw-msg-1',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$result = $this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'With Attachment',
				'message'     => 'Please see attached.',
				'headers'     => [],
				'attachments' => [ 'report.pdf' => $pdf ],
			]
		);

		$this->assertTrue( $result );
	}

	public function test_send_normalizes_legacy_indexed_attachments(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$pdf = $this->make_temp_file( '%PDF-1.4 stub', '.pdf' );

		$this->ses_client->expects( $this->once() )
			->method( 'send_raw_email' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->equalTo(
					[
						[
							'name' => basename( $pdf ),
							'path' => $pdf,
						],
					]
				),
			)
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'raw-msg-2',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Legacy Attachment',
				'message'     => 'Hello',
				'headers'     => [],
				'attachments' => [ $pdf ],
			]
		);
	}

	public function test_send_drops_unreadable_attachment_and_fires_skipped_action(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$captured = null;
		add_action(
			'leastudios_mailer_attachments_skipped',
			function ( array $skipped ) use ( &$captured ): void {
				$captured = $skipped;
			}
		);

		// All attachments unreadable -> Simple path is used (no raw send).
		$this->ses_client->expects( $this->never() )->method( 'send_raw_email' );
		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'simple-msg',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Bad Attachment',
				'message'     => 'Hi',
				'headers'     => [],
				'attachments' => [ 'missing.pdf' => '/tmp/this-file-does-not-exist-' . uniqid() . '.pdf' ],
			]
		);

		$this->assertIsArray( $captured );
		$this->assertArrayHasKey( 'missing.pdf', $captured );
	}

	public function test_send_uses_simple_path_when_no_attachments(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$this->ses_client->expects( $this->never() )->method( 'send_raw_email' );
		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'simple-msg',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$this->mailer->send(
			null,
			[
				'to'      => 'recipient@example.com',
				'subject' => 'No Attachment',
				'message' => 'Hi',
				'headers' => [],
			]
		);
	}

	public function test_pre_send_from_override_applies_to_raw_path(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
				'from_name'  => 'Original Sender',
			]
		);

		$pdf = $this->make_temp_file( '%PDF-1.4 stub', '.pdf' );

		add_filter(
			'leastudios_mailer_pre_send',
			static function ( array $args ): array {
				$args['from'] = 'Override Sender <override@example.com>';
				return $args;
			}
		);

		$this->ses_client->expects( $this->once() )
			->method( 'send_raw_email' )
			->with(
				$this->equalTo( 'override@example.com' ),
				$this->equalTo( 'Override Sender' ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'raw-msg-override',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Override From',
				'message'     => 'Hi',
				'headers'     => [],
				'attachments' => [ 'report.pdf' => $pdf ],
			]
		);
	}

	public function test_send_handles_array_to_recipients(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$this->ses_client->expects( $this->once() )
			->method( 'send_email' )
			->with(
				$this->anything(),
				$this->equalTo( [ 'a@example.com', 'b@example.com' ] ),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn(
				[
					'success'    => true,
					'message_id' => 'msg-456',
					'error'      => '',
				]
			);

		$this->logger->method( 'log' );

		$this->mailer->send(
			null,
			[
				'to'      => [ 'a@example.com', 'b@example.com' ],
				'subject' => 'Multi',
				'message' => 'Hello',
				'headers' => [],
			]
		);
	}

	public function test_skipped_attachment_is_recorded_in_the_email_log(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		// The only attachment is unreadable, so it is dropped and the email
		// goes out via the Simple path with no attachments.
		$this->ses_client->method( 'send_email' )->willReturn(
			[
				'success'    => true,
				'message_id' => 'simple-msg',
				'error'      => '',
			]
		);

		// The log entry must carry a note naming the dropped attachment,
		// even though the email itself was sent successfully.
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->equalTo( 'sent' ),
				$this->anything(),
				$this->stringContains( 'missing.pdf' )
			);

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Missing Attachment',
				'message'     => 'Hi',
				'headers'     => [],
				'attachments' => [ 'missing.pdf' => '/tmp/lsm-does-not-exist-' . uniqid() . '.pdf' ],
			]
		);
	}

	public function test_skipped_indexed_attachment_is_named_by_basename_in_the_log(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		$missing = '/tmp/lsm-missing-' . uniqid() . '.pdf';

		// The only attachment is unreadable, so it is dropped and the email
		// goes out via the Simple path with no attachments.
		$this->ses_client->method( 'send_email' )->willReturn(
			[
				'success'    => true,
				'message_id' => 'simple-msg',
				'error'      => '',
			]
		);

		// Legacy indexed attachment form: there is no display-name key, so
		// the log note must name the dropped file by its base name.
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->equalTo( 'sent' ),
				$this->anything(),
				$this->stringContains( basename( $missing ) )
			);

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Indexed Missing Attachment',
				'message'     => 'Hi',
				'headers'     => [],
				'attachments' => [ $missing ],
			]
		);
	}
}
