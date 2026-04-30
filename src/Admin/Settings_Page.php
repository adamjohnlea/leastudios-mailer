<?php
/**
 * Admin settings page with tabbed interface.
 *
 * @package LEAStudios\Mailer\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Email\Health_Check;
use LEAStudios\Mailer\Encryption\Options_Encryptor;
use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Mailer\SES\Client;
use LEAStudios\Mailer\Security\Nonce;

/**
 * Registers and renders the plugin settings page.
 */
class Settings_Page {

	/**
	 * The option group name.
	 */
	private const OPTION_GROUP = 'leastudios_mailer_settings';

	/**
	 * The option name in the database.
	 */
	private const OPTION_NAME = 'leastudios_mailer_options';

	/**
	 * The required capability.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Display labels for the admin region select. Keys MUST be a superset
	 * of {@see Client::ALLOWED_REGIONS}; the runtime allow-list lives there.
	 */
	private const REGION_LABELS = [
		'us-east-1'      => 'US East (N. Virginia)',
		'us-east-2'      => 'US East (Ohio)',
		'us-west-1'      => 'US West (N. California)',
		'us-west-2'      => 'US West (Oregon)',
		'af-south-1'     => 'Africa (Cape Town)',
		'ap-south-1'     => 'Asia Pacific (Mumbai)',
		'ap-northeast-1' => 'Asia Pacific (Tokyo)',
		'ap-northeast-2' => 'Asia Pacific (Seoul)',
		'ap-northeast-3' => 'Asia Pacific (Osaka)',
		'ap-southeast-1' => 'Asia Pacific (Singapore)',
		'ap-southeast-2' => 'Asia Pacific (Sydney)',
		'ca-central-1'   => 'Canada (Central)',
		'eu-central-1'   => 'Europe (Frankfurt)',
		'eu-west-1'      => 'Europe (Ireland)',
		'eu-west-2'      => 'Europe (London)',
		'eu-west-3'      => 'Europe (Paris)',
		'eu-north-1'     => 'Europe (Stockholm)',
		'eu-south-1'     => 'Europe (Milan)',
		'il-central-1'   => 'Israel (Tel Aviv)',
		'me-south-1'     => 'Middle East (Bahrain)',
		'sa-east-1'      => 'South America (São Paulo)',
	];

	/**
	 * Region <code> => <label> pairs filtered by the runtime allow-list.
	 *
	 * @return array<string, string>
	 */
	private static function region_choices(): array {
		$choices = [];
		foreach ( Client::ALLOWED_REGIONS as $code ) {
			$choices[ $code ] = self::REGION_LABELS[ $code ];
		}
		return $choices;
	}

	/**
	 * The settings page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Options_Encryptor $encryptor    The encryptor for credential storage.
	 * @param Email_Logger      $logger       The email logger.
	 * @param Health_Check      $health_check The health check service.
	 */
	public function __construct(
		private readonly Options_Encryptor $encryptor,
		private readonly Email_Logger $logger,
		private readonly Health_Check $health_check,
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_leastudios_mailer_send_test', [ $this, 'handle_send_test' ] );
		add_action( 'wp_ajax_leastudios_mailer_health_check', [ $this, 'handle_health_check' ] );
		add_action( 'wp_ajax_leastudios_mailer_delete_log', [ $this, 'handle_delete_log' ] );
	}

	/**
	 * Add the top-level admin menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = \add_menu_page(
			__( 'leaStudios Mailer', 'leastudios-mailer' ),
			__( 'Mailer', 'leastudios-mailer' ),
			self::CAPABILITY,
			'leastudios-mailer',
			[ $this, 'render_page' ],
			'dashicons-email-alt'
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => $this->get_defaults(),
			]
		);

		add_settings_section(
			'leastudios_mailer_credentials',
			__( 'AWS Credentials', 'leastudios-mailer' ),
			[ $this, 'render_credentials_section' ],
			'leastudios-mailer'
		);

		add_settings_field(
			'access_key',
			__( 'Access Key ID', 'leastudios-mailer' ),
			[ $this, 'render_password_field' ],
			'leastudios-mailer',
			'leastudios_mailer_credentials',
			[
				'id'          => 'access_key',
				'placeholder' => 'AKIA...',
			]
		);

		add_settings_field(
			'secret_key',
			__( 'Secret Access Key', 'leastudios-mailer' ),
			[ $this, 'render_password_field' ],
			'leastudios-mailer',
			'leastudios_mailer_credentials',
			[
				'id'          => 'secret_key',
				'placeholder' => '********',
			]
		);

		add_settings_field(
			'region',
			__( 'AWS Region', 'leastudios-mailer' ),
			[ $this, 'render_region_field' ],
			'leastudios-mailer',
			'leastudios_mailer_credentials'
		);

		add_settings_section(
			'leastudios_mailer_sender',
			__( 'Sender Settings', 'leastudios-mailer' ),
			'__return_empty_string',
			'leastudios-mailer'
		);

		add_settings_field(
			'from_email',
			__( 'From Email', 'leastudios-mailer' ),
			[ $this, 'render_text_field' ],
			'leastudios-mailer',
			'leastudios_mailer_sender',
			[
				'id'   => 'from_email',
				'type' => 'email',
			]
		);

		add_settings_field(
			'from_name',
			__( 'From Name', 'leastudios-mailer' ),
			[ $this, 'render_text_field' ],
			'leastudios-mailer',
			'leastudios_mailer_sender',
			[
				'id'   => 'from_name',
				'type' => 'text',
			]
		);

		add_settings_section(
			'leastudios_mailer_general',
			__( 'General', 'leastudios-mailer' ),
			'__return_empty_string',
			'leastudios-mailer'
		);

		add_settings_field(
			'enabled',
			__( 'Enable SES', 'leastudios-mailer' ),
			[ $this, 'render_checkbox_field' ],
			'leastudios-mailer',
			'leastudios_mailer_general',
			[
				'id'    => 'enabled',
				'label' => __( 'Route all WordPress emails through Amazon SES.', 'leastudios-mailer' ),
			]
		);

		add_settings_field(
			'log_retention_days',
			__( 'Log Retention', 'leastudios-mailer' ),
			[ $this, 'render_number_field' ],
			'leastudios-mailer',
			'leastudios_mailer_general',
			[
				'id'     => 'log_retention_days',
				'min'    => 1,
				'max'    => 365,
				'suffix' => __( 'days', 'leastudios-mailer' ),
			]
		);
	}

	/**
	 * Sanitize options before saving.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_options( array $input ): array {
		$current = get_option( self::OPTION_NAME, [] );

		$sanitized = [];

		// Encrypt credentials. Only update if a new value was provided.
		$access_key = sanitize_text_field( $input['access_key'] ?? '' );
		if ( '' !== $access_key ) {
			$sanitized['access_key'] = $this->encryptor->encrypt( $access_key );
		} else {
			$sanitized['access_key'] = $current['access_key'] ?? '';
		}

		$secret_key = sanitize_text_field( $input['secret_key'] ?? '' );
		if ( '' !== $secret_key ) {
			$sanitized['secret_key'] = $this->encryptor->encrypt( $secret_key );
		} else {
			$sanitized['secret_key'] = $current['secret_key'] ?? '';
		}

		$region = sanitize_text_field( $input['region'] ?? 'us-east-1' );
		if ( ! in_array( $region, Client::ALLOWED_REGIONS, true ) ) {
			$region = 'us-east-1';
		}
		$sanitized['region'] = $region;

		$sanitized['from_email']         = sanitize_email( $input['from_email'] ?? '' );
		$sanitized['from_name']          = sanitize_text_field( $input['from_name'] ?? '' );
		$sanitized['enabled']            = ! empty( $input['enabled'] );
		$sanitized['log_retention_days'] = absint( $input['log_retention_days'] ?? 30 );

		if ( $sanitized['log_retention_days'] < 1 ) {
			$sanitized['log_retention_days'] = 30;
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin assets on our settings page only.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'leastudios-mailer-admin',
			LEASTUDIOS_MAILER_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_MAILER_VERSION
		);

		wp_enqueue_script(
			'leastudios-mailer-admin',
			LEASTUDIOS_MAILER_URL . 'assets/js/admin.js',
			[],
			LEASTUDIOS_MAILER_VERSION,
			true
		);

		wp_localize_script(
			'leastudios-mailer-admin',
			'leastudiosMailer',
			[
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'sendTestNonce'    => Nonce::create( 'send_test' ),
				'healthCheckNonce' => Nonce::create( 'health_check' ),
				'deleteLogNonce'   => Nonce::create( 'delete_log' ),
				'strings'          => [
					'sending'    => __( 'Sending...', 'leastudios-mailer' ),
					'checking'   => __( 'Checking...', 'leastudios-mailer' ),
					'success'    => __( 'Success!', 'leastudios-mailer' ),
					'error'      => __( 'Error:', 'leastudios-mailer' ),
					'confirmDel' => __( 'Delete this log entry?', 'leastudios-mailer' ),
				],
			]
		);
	}

	/**
	 * Render the settings page with tabs.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs = [
			'configuration' => __( 'Configuration', 'leastudios-mailer' ),
			'email-log'     => __( 'Email Log', 'leastudios-mailer' ),
			'test-email'    => __( 'Test Email', 'leastudios-mailer' ),
		];

		/**
		 * Filter the settings page tabs.
		 *
		 * Allows adding custom tabs to the mailer settings page.
		 *
		 * @param array $tabs Associative array of tab slug => label.
		 */
		$tabs = apply_filters( 'leastudios_mailer_settings_tabs', $tabs );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab_param = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'configuration';
		// Constrain the tab to a known key — the slug feeds a dynamic
		// `do_action()` below, and we don't want a query-string value to
		// fire arbitrary action hook names. The third-party-tabs filter
		// has already had a chance to register additional keys.
		$active_tab = isset( $tabs[ $tab_param ] ) ? $tab_param : 'configuration';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'leaStudios Mailer', 'leastudios-mailer' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="?page=leastudios-mailer&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo $slug === $active_tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="leastudios-mailer-tab-content" style="margin-top: 20px;">
				<?php
				switch ( $active_tab ) {
					case 'email-log':
						$this->render_email_log_tab();
						break;
					case 'test-email':
						$this->render_test_email_tab();
						break;
					case 'configuration':
						$this->render_configuration_tab();
						break;
					default:
						/**
						 * Fires when rendering a custom settings tab.
						 *
						 * Dynamic portion of the hook name refers to the tab slug.
						 *
						 * @param string $active_tab The active tab slug.
						 */
						do_action( "leastudios_mailer_settings_tab_{$active_tab}", $active_tab );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the configuration tab.
	 *
	 * @return void
	 */
	private function render_configuration_tab(): void {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( self::OPTION_GROUP );
			do_settings_sections( 'leastudios-mailer' );
			submit_button( __( 'Save Settings', 'leastudios-mailer' ) );
			?>
		</form>
		<?php
	}

	/**
	 * Render the email log tab.
	 *
	 * @return void
	 */
	private function render_email_log_tab(): void {
		$log_table = new Email_Log_Table( $this->logger );
		$log_table->prepare_items();

		?>
		<form method="get">
			<input type="hidden" name="page" value="leastudios-mailer" />
			<input type="hidden" name="tab" value="email-log" />
			<?php
			$log_table->views();
			$log_table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Render the test email tab.
	 *
	 * @return void
	 */
	private function render_test_email_tab(): void {
		$options = get_option( self::OPTION_NAME, [] );

		?>
		<div class="leastudios-mailer-test-email">
			<h2><?php esc_html_e( 'Send Test Email', 'leastudios-mailer' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="leastudios-mailer-test-to"><?php esc_html_e( 'Recipient', 'leastudios-mailer' ); ?></label>
					</th>
					<td>
						<input type="email" id="leastudios-mailer-test-to" class="regular-text"
							value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					</td>
				</tr>
			</table>
			<p>
				<button type="button" id="leastudios-mailer-send-test" class="button button-primary">
					<?php esc_html_e( 'Send Test Email', 'leastudios-mailer' ); ?>
				</button>
				<button type="button" id="leastudios-mailer-health-check" class="button">
					<?php esc_html_e( 'Run Health Check', 'leastudios-mailer' ); ?>
				</button>
			</p>
			<div id="leastudios-mailer-test-result" style="margin-top: 15px;"></div>

			<hr style="margin: 30px 0;" />

			<h2><?php esc_html_e( 'Connection Status', 'leastudios-mailer' ); ?></h2>
			<table class="widefat striped" style="max-width: 600px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'SES Enabled', 'leastudios-mailer' ); ?></strong></td>
						<td>
							<?php if ( ! empty( $options['enabled'] ) ) : ?>
								<span style="color: #00a32a;">&#10003; <?php esc_html_e( 'Yes', 'leastudios-mailer' ); ?></span>
							<?php else : ?>
								<span style="color: #d63638;">&#10007; <?php esc_html_e( 'No', 'leastudios-mailer' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Region', 'leastudios-mailer' ); ?></strong></td>
						<td><code><?php echo esc_html( $options['region'] ?? 'Not set' ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'From Address', 'leastudios-mailer' ); ?></strong></td>
						<td><code><?php echo esc_html( $options['from_email'] ?? 'Not set' ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Webhook URL', 'leastudios-mailer' ); ?></strong></td>
						<td><code style="word-break: break-all;"><?php echo esc_url( rest_url( 'leastudios-mailer/v1/sns-webhook' ) ); ?></code></td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( 'Use the webhook URL above when configuring SNS notifications in your AWS Console for bounce and delivery tracking.', 'leastudios-mailer' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX handler: send a test email.
	 *
	 * @return void
	 */
	public function handle_send_test(): void {
		Nonce::check_ajax( 'send_test' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-mailer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via Nonce::check_ajax().
		$to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );

		if ( '' === $to ) {
			wp_send_json_error( __( 'Please enter a valid email address.', 'leastudios-mailer' ) );
		}

		$options = get_option( self::OPTION_NAME, [] );
		$from    = $options['from_email'] ?? get_option( 'admin_email' );

		$result = $this->health_check->send_test_email( $to, $from );

		if ( $result['success'] ) {
			wp_send_json_success(
				sprintf(
					/* translators: %s: SES message ID. */
					__( 'Test email sent successfully. Message ID: %s', 'leastudios-mailer' ),
					$result['message_id'] ?? 'N/A'
				)
			);
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	/**
	 * AJAX handler: run health check.
	 *
	 * @return void
	 */
	public function handle_health_check(): void {
		Nonce::check_ajax( 'health_check' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-mailer' ) );
		}

		$status = $this->health_check->get_overall_status();

		wp_send_json_success( $status );
	}

	/**
	 * AJAX handler: delete a log entry.
	 *
	 * @return void
	 */
	public function handle_delete_log(): void {
		Nonce::check_ajax( 'delete_log' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( __( 'Permission denied.', 'leastudios-mailer' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above via Nonce::check_ajax().
		$id = absint( $_POST['log_id'] ?? 0 );

		if ( 0 === $id ) {
			wp_send_json_error( __( 'Invalid log ID.', 'leastudios-mailer' ) );
		}

		$this->logger->delete_log( $id );

		wp_send_json_success( __( 'Log entry deleted.', 'leastudios-mailer' ) );
	}

	/**
	 * Render the credentials section description.
	 *
	 * @return void
	 */
	public function render_credentials_section(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Enter your AWS IAM credentials with SES send permissions. Credentials are encrypted before storage.', 'leastudios-mailer' )
		);
	}

	/**
	 * Render a password input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_password_field( array $args ): void {
		$options   = get_option( self::OPTION_NAME, [] );
		$has_value = ! empty( $options[ $args['id'] ] );

		printf(
			'<input type="password" id="%1$s" name="%2$s[%1$s]" class="regular-text" placeholder="%3$s" autocomplete="off" />',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $has_value ? __( '(saved — leave blank to keep)', 'leastudios-mailer' ) : ( $args['placeholder'] ?? '' ) )
		);
	}

	/**
	 * Render the region select field.
	 *
	 * @return void
	 */
	public function render_region_field(): void {
		$options = get_option( self::OPTION_NAME, [] );
		$current = $options['region'] ?? 'us-east-1';

		echo '<select id="region" name="' . esc_attr( self::OPTION_NAME ) . '[region]">';

		foreach ( self::region_choices() as $value => $label ) {
			printf(
				'<option value="%s" %s>%s (%s)</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options[ $args['id'] ] ?? '';

		printf(
			'<input type="%1$s" id="%2$s" name="%3$s[%2$s]" class="regular-text" value="%4$s" />',
			esc_attr( $args['type'] ?? 'text' ),
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$checked = ! empty( $options[ $args['id'] ] );

		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html( $args['label'] ?? '' )
		);
	}

	/**
	 * Render a number input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options[ $args['id'] ] ?? 30;

		printf(
			'<input type="number" id="%1$s" name="%2$s[%1$s]" class="small-text" value="%3$d" min="%4$d" max="%5$d" /> %6$s',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			(int) $value,
			(int) ( $args['min'] ?? 1 ),
			(int) ( $args['max'] ?? 365 ),
			esc_html( $args['suffix'] ?? '' )
		);
	}

	/**
	 * Get default option values.
	 *
	 * @return array Default options.
	 */
	private function get_defaults(): array {
		return [
			'access_key'         => '',
			'secret_key'         => '',
			'region'             => 'us-east-1',
			'from_email'         => '',
			'from_name'          => '',
			'enabled'            => false,
			'log_retention_days' => 30,
		];
	}
}
