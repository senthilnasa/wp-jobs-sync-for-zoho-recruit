<?php
/**
 * Admin screens and actions.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Every administration screen, list table integration and admin-post handler.
 */
class Admin {

	/**
	 * Top level menu slug.
	 */
	const MENU_SLUG = 'jszr-dashboard';

	/**
	 * Settings page slug.
	 */
	const SETTINGS_SLUG = 'jszr-settings';

	/**
	 * Field mapping page slug.
	 */
	const MAPPING_SLUG = 'jszr-mapping';

	/**
	 * Logs page slug.
	 */
	const LOGS_SLUG = 'jszr-logs';

	/**
	 * OAuth handler.
	 *
	 * @var Zoho_Auth
	 */
	private $auth;

	/**
	 * API client.
	 *
	 * @var Zoho_API
	 */
	private $api;

	/**
	 * Sync engine.
	 *
	 * @var Sync
	 */
	private $sync;

	/**
	 * Run storage.
	 *
	 * @var Sync_Queue
	 */
	private $queue;

	/**
	 * Field discovery.
	 *
	 * @var Field_Metadata
	 */
	private $metadata;

	/**
	 * Field mapper.
	 *
	 * @var Field_Mapper
	 */
	private $mapper;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Zoho_Auth      $auth     OAuth handler.
	 * @param Zoho_API       $api      API client.
	 * @param Sync           $sync     Sync engine.
	 * @param Sync_Queue     $queue    Run storage.
	 * @param Field_Metadata $metadata Field discovery.
	 * @param Field_Mapper   $mapper   Field mapper.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct(
		Zoho_Auth $auth,
		Zoho_API $api,
		Sync $sync,
		Sync_Queue $queue,
		Field_Metadata $metadata,
		Field_Mapper $mapper,
		Logger $logger
	) {
		$this->auth     = $auth;
		$this->api      = $api;
		$this->sync     = $sync;
		$this->queue    = $queue;
		$this->metadata = $metadata;
		$this->mapper   = $mapper;
		$this->logger   = $logger;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		add_filter( 'plugin_action_links_' . PLUGIN_BASENAME, array( $this, 'action_links' ) );

		// Admin-post handlers.
		add_action( 'admin_post_jszr_start_sync', array( $this, 'handle_start_sync' ) );
		add_action( 'admin_post_jszr_cancel_sync', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_jszr_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_jszr_save_credentials', array( $this, 'handle_save_credentials' ) );
		add_action( 'admin_post_jszr_save_mapping', array( $this, 'handle_save_mapping' ) );
		add_action( 'admin_post_jszr_reset_mapping', array( $this, 'handle_reset_mapping' ) );
		add_action( 'admin_post_jszr_import_mapping', array( $this, 'handle_import_mapping' ) );
		add_action( 'admin_post_jszr_export_mapping', array( $this, 'handle_export_mapping' ) );
		add_action( 'admin_post_jszr_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_jszr_import_settings', array( $this, 'handle_import_settings' ) );
		add_action( 'admin_post_jszr_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_jszr_refresh_fields', array( $this, 'handle_refresh_fields' ) );
		add_action( 'admin_post_jszr_regenerate_webhook', array( $this, 'handle_regenerate_webhook' ) );
		add_action( 'admin_post_jszr_resync_job', array( $this, 'handle_resync_job' ) );
		add_action( 'admin_post_jszr_reset_job', array( $this, 'handle_reset_job' ) );

		// Job list table.
		add_filter( 'manage_' . Post_Type::POST_TYPE . '_posts_columns', array( $this, 'list_columns' ) );
		add_action( 'manage_' . Post_Type::POST_TYPE . '_posts_custom_column', array( $this, 'list_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . Post_Type::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( $this, 'status_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_query' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );

		// Job edit screen.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	// ----------------------------------------------------------------------
	// Menu and assets
	// ----------------------------------------------------------------------

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = Plugin::capability();

		// The screens hang off the job post type menu. That keeps one menu for
		// everything Zoho Recruit related, and it means an editor who can manage
		// jobs but not the connection still sees the job list.
		$parent = 'edit.php?post_type=' . Post_Type::POST_TYPE;

		add_submenu_page(
			$parent,
			__( 'Zoho Recruit Dashboard', 'jobs-sync-for-zoho-recruit' ),
			__( 'Dashboard', 'jobs-sync-for-zoho-recruit' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			$parent,
			__( 'Settings', 'jobs-sync-for-zoho-recruit' ),
			__( 'Settings', 'jobs-sync-for-zoho-recruit' ),
			$capability,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			$parent,
			__( 'Field Mapping', 'jobs-sync-for-zoho-recruit' ),
			__( 'Field Mapping', 'jobs-sync-for-zoho-recruit' ),
			$capability,
			self::MAPPING_SLUG,
			array( $this, 'render_mapping' )
		);

		add_submenu_page(
			$parent,
			__( 'Sync Logs', 'jobs-sync-for-zoho-recruit' ),
			__( 'Sync Logs', 'jobs-sync-for-zoho-recruit' ),
			$capability,
			self::LOGS_SLUG,
			array( $this, 'render_logs' )
		);
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function is_plugin_screen( $hook ) {
		return (bool) preg_match( '/_page_jszr-|toplevel_page_jszr-/', (string) $hook );
	}

	/**
	 * Enqueue admin assets only where they are used.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen    = get_current_screen();
		$is_editor = $screen && Post_Type::POST_TYPE === $screen->post_type;

		if ( ! $this->is_plugin_screen( $hook ) && ! $is_editor ) {
			return;
		}

		wp_enqueue_style(
			'jszr-admin',
			PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			VERSION
		);

		wp_enqueue_script(
			'jszr-admin',
			PLUGIN_URL . 'admin/assets/js/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			VERSION,
			true
		);

		wp_localize_script(
			'jszr-admin',
			'jszrAdmin',
			array(
				'restNamespace' => REST_API::NAMESPACE_V1,
				'restUrl'       => esc_url_raw( rest_url( REST_API::NAMESPACE_V1 ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'pollInterval'  => 3000,
				'i18n'          => array(
					'testing'         => __( 'Testing the connection...', 'jobs-sync-for-zoho-recruit' ),
					'testSuccess'     => __( 'Connection successful.', 'jobs-sync-for-zoho-recruit' ),
					'syncing'         => __( 'Sync in progress...', 'jobs-sync-for-zoho-recruit' ),
					'syncComplete'    => __( 'Sync finished.', 'jobs-sync-for-zoho-recruit' ),
					'syncFailed'      => __( 'The sync failed. See the sync log for details.', 'jobs-sync-for-zoho-recruit' ),
					'resyncing'       => __( 'Refreshing this job from Zoho...', 'jobs-sync-for-zoho-recruit' ),
					'resynced'        => __( 'Job refreshed from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
					'genericError'    => __( 'Something went wrong. Please try again.', 'jobs-sync-for-zoho-recruit' ),
					'processed'       => __( 'Processed', 'jobs-sync-for-zoho-recruit' ),
					'replaceTemplate' => __( 'Replace what you have written with this layout?', 'jobs-sync-for-zoho-recruit' ),
					'page'            => __( 'Page', 'jobs-sync-for-zoho-recruit' ),
				),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'jszr-admin', 'jobs-sync-for-zoho-recruit', PLUGIN_DIR . 'languages' );
		}
	}

	/**
	 * Add a Settings link to the plugin row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( jszr_admin_url( self::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'jobs-sync-for-zoho-recruit' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	// ----------------------------------------------------------------------
	// Settings registration
	// ----------------------------------------------------------------------

	/**
	 * Register the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'jszr_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	// ----------------------------------------------------------------------
	// Screens
	// ----------------------------------------------------------------------

	/**
	 * Render the dashboard screen.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->require_capability();

		$this->view(
			'dashboard.php',
			array(
				'connection' => $this->auth->connection_info(),
				'state'      => $this->sync->get_state(),
				'counts'     => Job::counts(),
				'auth'       => $this->auth,
				'runs'       => Sync_Queue::get_recent( 5 ),
			)
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->require_capability();

		$tabs = array(
			'connection' => __( 'Connection', 'jobs-sync-for-zoho-recruit' ),
			'sync'       => __( 'Sync', 'jobs-sync-for-zoho-recruit' ),
			'api'        => __( 'Public API', 'jobs-sync-for-zoho-recruit' ),
			'frontend'   => __( 'Frontend', 'jobs-sync-for-zoho-recruit' ),
			'display'    => __( 'Display', 'jobs-sync-for-zoho-recruit' ),
			'schema'     => __( 'Structured Data', 'jobs-sync-for-zoho-recruit' ),
			'advanced'   => __( 'Advanced', 'jobs-sync-for-zoho-recruit' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection';

		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'connection';
		}

		$this->view(
			'settings.php',
			array(
				'tabs'       => $tabs,
				'active'     => $active,
				'settings'   => Settings::all(),
				'auth'       => $this->auth,
				'connection' => $this->auth->connection_info(),
			)
		);
	}

	/**
	 * Render the field mapping screen.
	 *
	 * @return void
	 */
	public function render_mapping() {
		$this->require_capability();

		$this->view(
			'mapping.php',
			array(
				'mapping'    => Field_Mapper::get_mapping(),
				'fields'     => $this->metadata->get_fields(),
				'has_live'   => $this->metadata->has_cached_fields(),
				'targets'    => Field_Mapper::targets(),
				'transforms' => Field_Mapper::transforms(),
				'connected'  => $this->auth->is_connected(),
			)
		);
	}

	/**
	 * Render the sync logs screen.
	 *
	 * @return void
	 */
	public function render_logs() {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$run_id = isset( $_GET['run_id'] ) ? (int) $_GET['run_id'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 20;

		$this->view(
			'logs.php',
			array(
				'runs'     => Sync_Queue::get_recent( $per_page, ( $paged - 1 ) * $per_page ),
				'total'    => Sync_Queue::count_runs(),
				'paged'    => $paged,
				'per_page' => $per_page,
				'run_id'   => $run_id,
				'entries'  => $run_id > 0
					? Logger::get_entries(
						array(
							'run_id' => $run_id,
							'limit'  => 200,
						)
					)
					: Logger::get_entries( array( 'limit' => 50 ) ),
			)
		);
	}

	/**
	 * Include an admin view.
	 *
	 * @param string $view View file name.
	 * @param array  $args Variables for the view.
	 * @return void
	 */
	private function view( $view, array $args = array() ) {
		$path = PLUGIN_DIR . 'admin/views/' . basename( $view );

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Deliberate view scope.
		extract( $args, EXTR_SKIP );

		include $path;
	}

	/**
	 * Stop rendering when the user lacks the capability.
	 *
	 * @return void
	 */
	private function require_capability() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jobs-sync-for-zoho-recruit' ), 403 );
		}
	}

	// ----------------------------------------------------------------------
	// Notices
	// ----------------------------------------------------------------------

	/**
	 * Render one-off and persistent notices.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$key    = 'jszr_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( $key );

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
				esc_html( $notice['message'] )
			);
		}

		$screen           = get_current_screen();
		$on_plugin_screen = $screen && false !== strpos( (string) $screen->id, 'jszr-' );

		if ( $this->auth->is_circuit_open() ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Zoho Recruit syncing is paused after repeated authentication failures.', 'jobs-sync-for-zoho-recruit' ),
				esc_url( jszr_admin_url( self::SETTINGS_SLUG, array( 'tab' => 'connection' ) ) ),
				esc_html__( 'Reconnect Zoho Recruit', 'jobs-sync-for-zoho-recruit' )
			);
		} elseif ( $this->auth->keys_changed() ) {
			printf(
				'<div class="notice notice-error"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'The stored Zoho Recruit credentials can no longer be decrypted because this site\'s security keys changed.', 'jobs-sync-for-zoho-recruit' ),
				esc_url( jszr_admin_url( self::SETTINGS_SLUG, array( 'tab' => 'connection' ) ) ),
				esc_html__( 'Reconnect Zoho Recruit', 'jobs-sync-for-zoho-recruit' )
			);
		} elseif ( $on_plugin_screen && ! $this->auth->is_connected() ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'This site is not connected to Zoho Recruit yet, so no jobs can be synchronized.', 'jobs-sync-for-zoho-recruit' ),
				esc_url( jszr_admin_url( self::SETTINGS_SLUG, array( 'tab' => 'connection' ) ) ),
				esc_html__( 'Connect now', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( $on_plugin_screen && Cron::is_wp_cron_disabled() && 'disabled' !== Settings::get( 'sync_frequency', '' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Scheduled syncing will only run if a system cron job calls wp-cron.php.', 'jobs-sync-for-zoho-recruit' )
			);
		}
	}

	/**
	 * Store a notice for the next page load.
	 *
	 * @param string $type    success|error.
	 * @param string $message Message.
	 * @return void
	 */
	private function notice( $type, $message ) {
		set_transient(
			'jszr_admin_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Redirect back to a plugin screen.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query arguments.
	 * @return void
	 */
	private function redirect( $page = self::MENU_SLUG, array $args = array() ) {
		wp_safe_redirect( jszr_admin_url( $page, $args ) );
		exit;
	}

	// ----------------------------------------------------------------------
	// Actions
	// ----------------------------------------------------------------------

	/**
	 * Start a sync from the dashboard.
	 *
	 * @return void
	 */
	public function handle_start_sync() {
		jszr_verify_admin_request( 'jszr_start_sync' );

		$type    = isset( $_POST['sync_type'] ) ? sanitize_key( wp_unslash( $_POST['sync_type'] ) ) : 'incremental'; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() at the top of the handler.
		$dry_run = ! empty( $_POST['dry_run'] ); // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() at the top of the handler.

		$result = $this->sync->start(
			in_array( $type, array( 'full', 'incremental' ), true ) ? $type : 'incremental',
			array(
				'dry_run' => $dry_run,
				'trigger' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice(
				'success',
				$dry_run
					? __( 'Dry run started. No changes will be written.', 'jobs-sync-for-zoho-recruit' )
					: __( 'Sync started. Progress is shown below.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$this->redirect();
	}

	/**
	 * Cancel the running sync.
	 *
	 * @return void
	 */
	public function handle_cancel_sync() {
		jszr_verify_admin_request( 'jszr_cancel_sync' );

		$cancelled = Sync_Queue::cancel_active();

		$this->notice(
			'success',
			$cancelled
				? __( 'The running sync was cancelled.', 'jobs-sync-for-zoho-recruit' )
				: __( 'No sync was running.', 'jobs-sync-for-zoho-recruit' )
		);

		$this->redirect();
	}

	/**
	 * Test the API connection.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		jszr_verify_admin_request( 'jszr_test_connection' );

		$result = $this->api->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice(
				'success',
				sprintf(
					/* translators: 1: Zoho module name, 2: number of job openings. */
					__( 'Connection successful. Module "%1$s" reports %2$s job openings.', 'jobs-sync-for-zoho-recruit' ),
					$result['module'],
					number_format_i18n( (int) $result['count'] )
				)
			);
		}

		$this->redirect();
	}

	/**
	 * Save the OAuth client credentials.
	 *
	 * @return void
	 */
	public function handle_save_credentials() {
		jszr_verify_admin_request( 'jszr_save_credentials' );

		$client_id     = isset( $_POST['jszr_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['jszr_client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() at the top of the handler.
		$client_secret = isset( $_POST['jszr_client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['jszr_client_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() at the top of the handler.
		$data_center   = isset( $_POST['jszr_data_center'] ) ? sanitize_key( wp_unslash( $_POST['jszr_data_center'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() at the top of the handler.

		if ( '' !== $data_center && ! Zoho_Auth::data_center_is_constant() ) {
			Settings::update( Settings::sanitize( array( 'data_center' => $data_center ) ) );
		}

		$result = $this->auth->save_credentials( $client_id, $client_secret );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->auth->reset_circuit();
			$this->notice( 'success', __( 'Zoho credentials saved.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$this->redirect( self::SETTINGS_SLUG, array( 'tab' => 'connection' ) );
	}

	/**
	 * Save the field mapping.
	 *
	 * @return void
	 */
	public function handle_save_mapping() {
		jszr_verify_admin_request( 'jszr_save_mapping' );

		$rows = array();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- Each value is sanitized in sanitize_mapping(); the nonce is verified by jszr_verify_admin_request() above.
		$raw = isset( $_POST['jszr_mapping'] ) ? wp_unslash( $_POST['jszr_mapping'] ) : array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$rows[] = array(
					'zoho_field' => isset( $row['zoho_field'] ) ? (string) $row['zoho_field'] : '',
					'target'     => isset( $row['target'] ) ? (string) $row['target'] : '',
					'transform'  => isset( $row['transform'] ) ? (string) $row['transform'] : 'text',
				);
			}
		}

		Field_Mapper::save_mapping( $rows );

		$this->notice( 'success', __( 'Field mapping saved.', 'jobs-sync-for-zoho-recruit' ) );
		$this->redirect( self::MAPPING_SLUG );
	}

	/**
	 * Restore the default mapping.
	 *
	 * @return void
	 */
	public function handle_reset_mapping() {
		jszr_verify_admin_request( 'jszr_reset_mapping' );

		Field_Mapper::reset_mapping();

		$this->notice( 'success', __( 'The default field mapping was restored.', 'jobs-sync-for-zoho-recruit' ) );
		$this->redirect( self::MAPPING_SLUG );
	}

	/**
	 * Import a mapping JSON file.
	 *
	 * @return void
	 */
	public function handle_import_mapping() {
		jszr_verify_admin_request( 'jszr_import_mapping' );

		$json = $this->read_uploaded_json( 'jszr_mapping_file' );

		if ( is_wp_error( $json ) ) {
			$this->notice( 'error', $json->get_error_message() );
			$this->redirect( self::MAPPING_SLUG );
		}

		$result = Field_Mapper::import( $json );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice( 'success', __( 'Field mapping imported.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$this->redirect( self::MAPPING_SLUG );
	}

	/**
	 * Download the field mapping as JSON.
	 *
	 * @return void
	 */
	public function handle_export_mapping() {
		jszr_verify_admin_request( 'jszr_export_mapping' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=jobs-sync-for-zoho-recruit-mapping.json' );

		echo Field_Mapper::export(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Download settings and mapping as JSON.
	 *
	 * @return void
	 */
	public function handle_export_settings() {
		jszr_verify_admin_request( 'jszr_export_settings' );

		$settings = Settings::all();

		// Credentials and tokens are never exported.
		unset( $settings['notify_email'] );

		$payload = array(
			'plugin'   => 'jobs-sync-for-zoho-recruit',
			'version'  => VERSION,
			'exported' => gmdate( 'c' ),
			'settings' => $settings,
			'mapping'  => Field_Mapper::get_mapping(),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=jobs-sync-for-zoho-recruit-settings.json' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import settings and mapping from JSON.
	 *
	 * @return void
	 */
	public function handle_import_settings() {
		jszr_verify_admin_request( 'jszr_import_settings' );

		$json = $this->read_uploaded_json( 'jszr_settings_file' );

		if ( is_wp_error( $json ) ) {
			$this->notice( 'error', $json->get_error_message() );
			$this->redirect( self::SETTINGS_SLUG, array( 'tab' => 'advanced' ) );
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$this->notice( 'error', __( 'The uploaded file is not valid JSON.', 'jobs-sync-for-zoho-recruit' ) );
			$this->redirect( self::SETTINGS_SLUG, array( 'tab' => 'advanced' ) );
		}

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			Settings::replace( Settings::sanitize( $data['settings'] ) );
			Cron::schedule();
		}

		if ( isset( $data['mapping'] ) && is_array( $data['mapping'] ) ) {
			Field_Mapper::save_mapping( $data['mapping'] );
		}

		$this->notice( 'success', __( 'Settings imported. Credentials and tokens were not changed.', 'jobs-sync-for-zoho-recruit' ) );
		$this->redirect( self::SETTINGS_SLUG, array( 'tab' => 'advanced' ) );
	}

	/**
	 * Read an uploaded JSON file safely.
	 *
	 * Only ever called from an admin-post handler that has already run
	 * jszr_verify_admin_request(), so the nonce and capability are settled by the
	 * time execution reaches here.
	 *
	 * @param string $field File input name.
	 * @return string|\WP_Error
	 */
	private function read_uploaded_json( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling handler.
		if ( empty( $_FILES[ $field ]['tmp_name'] ) ) {
			return new \WP_Error( 'jszr_no_file', __( 'Choose a JSON file to upload.', 'jobs-sync-for-zoho-recruit' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Path is validated below; nonce verified by the calling handler.
		$tmp = isset( $_FILES[ $field ]['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES[ $field ]['tmp_name'] ) ) : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'jszr_bad_upload', __( 'The upload could not be read.', 'jobs-sync-for-zoho-recruit' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling handler.
		$size = isset( $_FILES[ $field ]['size'] ) ? (int) $_FILES[ $field ]['size'] : 0;

		if ( $size <= 0 || $size > MB_IN_BYTES ) {
			return new \WP_Error( 'jszr_bad_size', __( 'The file is empty or larger than 1 MB.', 'jobs-sync-for-zoho-recruit' ) );
		}

		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$contents = $wp_filesystem ? $wp_filesystem->get_contents( $tmp ) : false;

		if ( false === $contents ) {
			return new \WP_Error( 'jszr_unreadable', __( 'The uploaded file could not be read.', 'jobs-sync-for-zoho-recruit' ) );
		}

		return (string) $contents;
	}

	/**
	 * Delete log rows.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		jszr_verify_admin_request( 'jszr_clear_logs' );

		Logger::clear();
		Sync_Queue::clear();

		$this->notice( 'success', __( 'Sync logs cleared.', 'jobs-sync-for-zoho-recruit' ) );
		$this->redirect( self::LOGS_SLUG );
	}

	/**
	 * Re-fetch the Zoho field list.
	 *
	 * @return void
	 */
	public function handle_refresh_fields() {
		jszr_verify_admin_request( 'jszr_refresh_fields' );

		$result = $this->metadata->refresh();

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
		} else {
			$this->notice(
				'success',
				sprintf(
					/* translators: %s: number of fields. */
					__( 'Loaded %s fields from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
					number_format_i18n( count( $result ) )
				)
			);
		}

		$this->redirect( self::MAPPING_SLUG );
	}

	/**
	 * Generate a new webhook secret.
	 *
	 * @return void
	 */
	public function handle_regenerate_webhook() {
		jszr_verify_admin_request( 'jszr_regenerate_webhook' );

		Webhook::regenerate_secret();

		$this->notice( 'success', __( 'A new webhook URL was generated. Update it in Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
		$this->redirect( self::SETTINGS_SLUG, array( 'tab' => 'advanced' ) );
	}

	/**
	 * Re-import a single job.
	 *
	 * @return void
	 */
	public function handle_resync_job() {
		$this->refresh_job( 'jszr_resync_job', false );
	}

	/**
	 * Discard local edits for a single job and take Zoho's values.
	 *
	 * This is the escape hatch for "preserve fields edited in WordPress" mode,
	 * where an ordinary resync deliberately leaves edited fields alone. It has
	 * its own nonce so a resync link can never be escalated into a reset.
	 *
	 * @return void
	 */
	public function handle_reset_job() {
		$this->refresh_job( 'jszr_reset_job', true );
	}

	/**
	 * Re-import one job from Zoho.
	 *
	 * @param string $nonce_action Nonce action to verify.
	 * @param bool   $force        Whether to overwrite locally edited fields.
	 * @return void
	 */
	private function refresh_job( $nonce_action, $force ) {
		jszr_verify_admin_request( $nonce_action );

		$post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce and capability verified by jszr_verify_admin_request() above.

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to refresh this job.', 'jobs-sync-for-zoho-recruit' ), 403 );
		}

		$zoho_id = (string) get_post_meta( $post_id, Job::META_ZOHO_ID, true );

		if ( '' === $zoho_id ) {
			$this->notice( 'error', __( 'This job was not imported from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
		} else {
			$result = $this->sync->sync_single( $zoho_id, $force );

			if ( is_wp_error( $result ) ) {
				$this->notice( 'error', $result->get_error_message() );
			} else {
				$this->notice(
					'success',
					$force
						? __( 'Job reset to its Zoho Recruit values.', 'jobs-sync-for-zoho-recruit' )
						: __( 'Job refreshed from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' )
				);
			}
		}

		$referer = wp_get_referer();

		wp_safe_redirect( $referer ? $referer : admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) );
		exit;
	}

	// ----------------------------------------------------------------------
	// Job list table
	// ----------------------------------------------------------------------

	/**
	 * Add plugin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function list_columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['jszr_status']  = __( 'Job Status', 'jobs-sync-for-zoho-recruit' );
				$new['jszr_code']    = __( 'Job Code', 'jobs-sync-for-zoho-recruit' );
				$new['jszr_closing'] = __( 'Closing Date', 'jobs-sync-for-zoho-recruit' );
				$new['jszr_synced']  = __( 'Last Sync', 'jobs-sync-for-zoho-recruit' );
			}
		}

		return $new;
	}

	/**
	 * Render plugin column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function list_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'jszr_status':
				if ( Job::is_manual( $post_id ) ) {
					echo '<span class="jszr-badge jszr-badge-manual">' . esc_html__( 'Manual', 'jobs-sync-for-zoho-recruit' ) . '</span>';
					break;
				}

				$status = Job::get_status( $post_id );

				printf(
					'<span class="jszr-badge jszr-badge-%1$s">%2$s</span>',
					esc_attr( $status ),
					esc_html( self::status_label( $status ) )
				);
				break;

			case 'jszr_code':
				echo esc_html( (string) get_post_meta( $post_id, '_jszr_job_code', true ) );
				break;

			case 'jszr_closing':
				$closing = (string) get_post_meta( $post_id, '_zoho_recruit_closing_date', true );

				if ( '' === $closing ) {
					echo '&mdash;';
					break;
				}

				$timestamp = strtotime( $closing );

				echo esc_html( $timestamp ? wp_date( (string) get_option( 'date_format' ), $timestamp ) : $closing );
				break;

			case 'jszr_synced':
				$synced = (string) get_post_meta( $post_id, Job::META_LAST_SYNCED, true );

				if ( '' === $synced ) {
					echo '&mdash;';
					break;
				}

				$timestamp = strtotime( $synced . ' UTC' );

				echo esc_html(
					$timestamp
						? sprintf(
							/* translators: %s: human readable time difference. */
							__( '%s ago', 'jobs-sync-for-zoho-recruit' ),
							human_time_diff( $timestamp, time() )
						)
						: $synced
				);
				break;
		}
	}

	/**
	 * Human label for a normalised status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'active'   => __( 'Active', 'jobs-sync-for-zoho-recruit' ),
			'inactive' => __( 'Inactive', 'jobs-sync-for-zoho-recruit' ),
			'expired'  => __( 'Expired', 'jobs-sync-for-zoho-recruit' ),
			'closed'   => __( 'Closed', 'jobs-sync-for-zoho-recruit' ),
			'draft'    => __( 'Draft', 'jobs-sync-for-zoho-recruit' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	/**
	 * Declare sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_columns( $columns ) {
		$columns['jszr_status']  = 'jszr_status';
		$columns['jszr_closing'] = 'jszr_closing';
		$columns['jszr_synced']  = 'jszr_synced';

		return $columns;
	}

	/**
	 * Status filter dropdown above the job list.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public function status_filter_dropdown( $post_type ) {
		if ( Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$current = isset( $_GET['jszr_status'] ) ? sanitize_key( wp_unslash( $_GET['jszr_status'] ) ) : '';

		$options = array(
			''         => __( 'All job statuses', 'jobs-sync-for-zoho-recruit' ),
			'active'   => __( 'Active', 'jobs-sync-for-zoho-recruit' ),
			'inactive' => __( 'Inactive', 'jobs-sync-for-zoho-recruit' ),
			'expired'  => __( 'Expired', 'jobs-sync-for-zoho-recruit' ),
			'closed'   => __( 'Closed', 'jobs-sync-for-zoho-recruit' ),
			'draft'    => __( 'Draft', 'jobs-sync-for-zoho-recruit' ),
		);

		echo '<label class="screen-reader-text" for="jszr_status">' . esc_html__( 'Filter by job status', 'jobs-sync-for-zoho-recruit' ) . '</label>';
		echo '<select name="jszr_status" id="jszr_status">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply admin list sorting and filtering.
	 *
	 * @param \WP_Query $query Query object.
	 * @return void
	 */
	public function filter_admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( Post_Type::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$status = isset( $_GET['jszr_status'] ) ? sanitize_key( wp_unslash( $_GET['jszr_status'] ) ) : '';

		if ( '' !== $status ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'key'     => Job::META_STATUS,
				'value'   => $status,
				'compare' => '=',
			);

			$query->set( 'meta_query', $meta_query );
		}

		$orderby = (string) $query->get( 'orderby' );

		$sortable = array(
			'jszr_status'  => Job::META_STATUS,
			'jszr_closing' => '_zoho_recruit_closing_date',
			'jszr_synced'  => Job::META_LAST_SYNCED,
		);

		if ( isset( $sortable[ $orderby ] ) ) {
			$query->set( 'meta_key', $sortable[ $orderby ] );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add a "Resync" row action.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post    Post object.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( Post_Type::POST_TYPE !== $post->post_type || Job::is_manual( $post->ID ) ) {
			return $actions;
		}

		if ( ! current_user_can( Plugin::capability() ) ) {
			return $actions;
		}

		$actions['jszr_resync'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::job_action_url( 'jszr_resync_job', (int) $post->ID ) ),
			esc_html__( 'Resync from Zoho', 'jobs-sync-for-zoho-recruit' )
		);

		// The reset only means something different from a resync when local
		// edits are being preserved, so it is offered only in that mode.
		if ( 'preserve_manual' === Settings::get( 'conflict_mode', 'mapped_only' ) ) {
			$actions['jszr_reset'] = sprintf(
				'<a href="%1$s" class="jszr-confirm" data-jszr-confirm="%2$s">%3$s</a>',
				esc_url( self::job_action_url( 'jszr_reset_job', (int) $post->ID ) ),
				esc_attr__( 'Discard the changes made to this job in WordPress and replace them with the values from Zoho Recruit?', 'jobs-sync-for-zoho-recruit' ),
				esc_html__( 'Reset to Zoho values', 'jobs-sync-for-zoho-recruit' )
			);
		}

		return $actions;
	}

	// ----------------------------------------------------------------------
	// Job edit screen
	// ----------------------------------------------------------------------

	/**
	 * Build a nonced admin-post URL for a per-job action.
	 *
	 * @param string $action  Admin-post action and nonce action name.
	 * @param int    $post_id Job post ID.
	 * @return string
	 */
	public static function job_action_url( $action, $post_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'post'   => (int) $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action
		);
	}

	/**
	 * Register the Zoho details metabox.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		add_meta_box(
			'jszr_zoho_details',
			__( 'Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
			array( $this, 'render_meta_box' ),
			Post_Type::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the Zoho details metabox.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$this->view(
			'meta-box.php',
			array(
				'post'    => $post,
				'zoho_id' => (string) get_post_meta( $post->ID, Job::META_ZOHO_ID, true ),
				'mapped'  => (array) get_post_meta( $post->ID, Job::META_MAPPED, true ),
			)
		);
	}
}
