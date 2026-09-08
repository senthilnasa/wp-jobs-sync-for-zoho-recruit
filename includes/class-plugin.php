<?php
/**
 * Plugin container and lifecycle.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components together and owns activation/deactivation.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instantiated components keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private $components = array();

	/**
	 * Boot the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Register hooks and construct components.
	 *
	 * @return void
	 */
	private function boot() {
		// No load_plugin_textdomain() call: from WordPress 4.6 translations are
		// loaded automatically, just in time, from the Domain Path in the plugin
		// header and from the language packs WordPress.org builds.
		$this->components['logger']   = new Logger();
		$this->components['auth']     = new Zoho_Auth();
		$this->components['api']      = new Zoho_API( $this->components['auth'] );
		$this->components['metadata'] = new Field_Metadata( $this->components['api'] );
		$this->components['mapper']   = new Field_Mapper();
		$this->components['queue']    = new Sync_Queue();
		$this->components['sync']     = new Sync( $this->components['api'], $this->components['mapper'], $this->components['queue'], $this->components['logger'] );

		Post_Type::init();
		Templates::init();
		Structured_Data::init();
		SEO::init();
		Page_Cache::init();
		Privacy::init();
		Site_Health::init();
		Shortcode::init();
		Blocks::init();

		$this->components['cron']    = new Cron( $this->components['sync'] );
		$this->components['rest']    = new REST_API( $this->components['sync'], $this->components['queue'] );
		$this->components['webhook'] = new Webhook( $this->components['sync'] );

		if ( is_admin() ) {
			$this->components['admin'] = new Admin(
				$this->components['auth'],
				$this->components['api'],
				$this->components['sync'],
				$this->components['queue'],
				$this->components['metadata'],
				$this->components['mapper'],
				$this->components['logger']
			);
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register( $this );
		}

		/*
		 * Migrations run on init, not plugins_loaded. They touch the post type
		 * and the rewrite rules, and $wp_rewrite does not exist until init --
		 * registering a post type before then is a fatal error. Priority 20 puts
		 * this after Post_Type::register() at priority 5, and after the point
		 * where translations may safely be loaded.
		 */
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 100 );
	}

	/**
	 * Capability required to manage the plugin.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required for all plugin administration.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'jszr_capability', 'manage_zoho_recruit' );
	}

	/**
	 * Component accessor: OAuth handler.
	 *
	 * @return Zoho_Auth
	 */
	public function auth() {
		return $this->components['auth'];
	}

	/**
	 * Component accessor: API client.
	 *
	 * @return Zoho_API
	 */
	public function api() {
		return $this->components['api'];
	}

	/**
	 * Component accessor: sync engine.
	 *
	 * @return Sync
	 */
	public function sync() {
		return $this->components['sync'];
	}

	/**
	 * Component accessor: sync queue.
	 *
	 * @return Sync_Queue
	 */
	public function queue() {
		return $this->components['queue'];
	}

	/**
	 * Component accessor: logger.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->components['logger'];
	}

	/**
	 * Component accessor: field mapper.
	 *
	 * @return Field_Mapper
	 */
	public function mapper() {
		return $this->components['mapper'];
	}

	/**
	 * Component accessor: field metadata discovery.
	 *
	 * @return Field_Metadata
	 */
	public function metadata() {
		return $this->components['metadata'];
	}

	/**
	 * Component accessor: webhook receiver.
	 *
	 * @return Webhook
	 */
	public function webhook() {
		return $this->components['webhook'];
	}

	/**
	 * Run data upgrades once per version change.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored = (string) get_option( 'jszr_db_version', '' );

		if ( DB_VERSION === $stored ) {
			return;
		}

		Upgrader::run( $stored );

		update_option( 'jszr_db_version', DB_VERSION, false );
	}

	/**
	 * Activation handler.
	 *
	 * @param bool $network_wide Whether the plugin is being network activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 0,
					'archived' => 0,
					'deleted'  => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_single_site();
				restore_current_blog();
			}

			return;
		}

		self::activate_single_site();
	}

	/**
	 * Per-site activation work.
	 *
	 * @return void
	 */
	public static function activate_single_site() {
		Settings::flush_cache();

		Logger::install_table();
		Sync_Queue::install_table();

		self::add_capability();

		Post_Type::register();
		flush_rewrite_rules( false );

		if ( ! get_option( 'jszr_db_version', false ) ) {
			update_option( 'jszr_db_version', DB_VERSION, false );
		}

		if ( ! get_option( 'jszr_webhook_secret', false ) ) {
			update_option( 'jszr_webhook_secret', wp_generate_password( 40, false, false ), false );
		}

		Cron::schedule();
	}

	/**
	 * Deactivation handler.
	 *
	 * @param bool $network_wide Whether the plugin was network activated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_single_site();
				restore_current_blog();
			}

			return;
		}

		self::deactivate_single_site();
	}

	/**
	 * Per-site deactivation work.
	 *
	 * @return void
	 */
	public static function deactivate_single_site() {
		Cron::unschedule_all();
		Sync_Queue::release_lock();
		flush_rewrite_rules( false );
	}

	/**
	 * Run activation for sites created while the plugin is network active.
	 *
	 * @param \WP_Site $site New site.
	 * @return void
	 */
	public static function on_new_site( $site ) {
		if ( ! is_plugin_active_for_network( PLUGIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::activate_single_site();
		restore_current_blog();
	}

	/**
	 * Grant the plugin capability to roles that should have it.
	 *
	 * @return void
	 */
	public static function add_capability() {
		$capability = self::capability();

		/**
		 * Filter the roles granted the plugin capability on activation.
		 *
		 * @param string[] $roles Role names.
		 */
		$roles = (array) apply_filters( 'jszr_capability_roles', array( 'administrator' ) );

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );

			if ( $role instanceof \WP_Role && ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}

	/**
	 * Remove the plugin capability from all roles.
	 *
	 * @return void
	 */
	public static function remove_capability() {
		$capability = self::capability();
		$roles      = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role instanceof \WP_Role && $role->has_cap( $capability ) ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
