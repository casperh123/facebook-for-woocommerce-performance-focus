<?php
// phpcs:ignoreFile
/**
 * Facebook for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework;

defined( 'ABSPATH' ) or exit;

/**
 * Plugin lifecycle handler.
 *
 * Registers and displays milestone notice prompts and eventually the plugin
 * install, upgrade, activation, and deactivation routines.
 */
class Lifecycle {
	/** @var array the version numbers that have an upgrade routine */
	protected $upgrade_versions = [];

	/** @var string minimum milestone version */
	private $milestone_version;

	/** @var Plugin plugin instance */
	private $plugin;

	/**
	 * Constructs the class.
	 *
	 * @since 5.1.0
	 *
	 * @param Plugin $plugin plugin instance
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->add_hooks();
	}


	/**
	 * Adds the action & filter hooks.
	 *
	 * @since 5.1.0
	 */
	protected function add_hooks() {
		// handle activation
		add_action( 'admin_init', array( $this, 'handle_activation' ) );
		// handle deactivation
		add_action( 'deactivate_' . $this->get_plugin()->get_plugin_file(), array( $this, 'handle_deactivation' ) );

		if ( is_admin() && ! wp_doing_ajax() ) {
			// initialize the plugin lifecycle
			add_action( 'wp_loaded', array( $this, 'init' ) );
		}

		// catch any milestones triggered by action
		add_action( 'wc_' . $this->get_plugin()->get_id() . '_milestone_reached', array( $this, 'trigger_milestone' ), 10, 3 );
	}


	/**
	 * Initializes the plugin lifecycle.
	 *
	 * @since 5.2.0
	 */
	public function init() {
		// potentially handle a new activation
		$this->handle_activation();
		$installed_version = $this->get_installed_version();
		$plugin_version    = $this->get_plugin()->get_version();
		// installed version lower than plugin version?
		if ( version_compare( $installed_version, $plugin_version, '<' ) ) {
			if ( ! $installed_version ) {
				// store the upgrade event regardless if there was a routine for it
				/**
				 * Fires after the plugin has been installed.
				 *
				 * @since 5.1.0
				 */
				do_action( 'wc_' . $this->get_plugin()->get_id() . '_installed' );
			} else {
				$this->upgrade( $installed_version );

				/**
				 * Fires after the plugin has been updated.
				 *
				 * @since 5.1.0
				 *
				 * @param string $installed_version previously installed version
				 */
				do_action( 'wc_' . $this->get_plugin()->get_id() . '_updated', $installed_version );
			}
			// new version number
			$this->set_installed_version( $plugin_version );
		}
	}


	/**
	 * Triggers plugin activation.
	 *
	 * We don't use register_activation_hook() as that can't be called inside
	 * the 'plugins_loaded' action. Instead, we rely on setting to track the
	 * plugin's activation status.
	 *
	 * @internal
	 *
	 * @link https://developer.wordpress.org/reference/functions/register_activation_hook/#comment-2100
	 *
	 * @since 5.2.0
	 */
	public function handle_activation() {
		if ( ! get_option( 'wc_' . $this->get_plugin()->get_id() . '_is_active', false ) ) {
			/**
			 * Fires when the plugin is activated.
			 *
			 * @since 5.2.0
			 */
			do_action( 'wc_' . $this->get_plugin()->get_id() . '_activated' );
			update_option( 'wc_' . $this->get_plugin()->get_id() . '_is_active', 'yes' );
		}
	}


	/**
	 * Triggers plugin deactivation.
	 *
	 * @internal
	 *
	 * @since 5.2.0
	 */
	public function handle_deactivation() {
		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 5.2.0
		 */
		do_action( 'wc_' . $this->get_plugin()->get_id() . '_deactivated' );
		delete_option( 'wc_' . $this->get_plugin()->get_id() . '_is_active' );
	}


	/**
	 * Performs any upgrade tasks based on the provided installed version.
	 *
	 * @since 5.2.0
	 *
	 * @param string $installed_version installed version
	 */
	protected function upgrade( $installed_version ) {
		foreach ( $this->upgrade_versions as $upgrade_version ) {
			$upgrade_method = 'upgrade_to_' . str_replace( array( '.', '-' ), '_', $upgrade_version );
			if ( version_compare( $installed_version, $upgrade_version, '<' ) && is_callable( array( $this, $upgrade_method ) ) ) {
				$this->get_plugin()->log( "Starting upgrade to v{$upgrade_version}" );
				$this->$upgrade_method( $installed_version );
				$this->get_plugin()->log( "Upgrade to v{$upgrade_version} complete" );
			}
		}
	}


	/**
	 * Gets the currently installed plugin version.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	protected function get_installed_version() {
		return get_option( $this->get_plugin()->get_plugin_version_name() );
	}


	/**
	 * Sets the installed plugin version.
	 *
	 * @since 5.2.0
	 *
	 * @param string $version version to set
	 */
	protected function set_installed_version( $version ) {
		update_option( $this->get_plugin()->get_plugin_version_name(), $version );
	}


	/**
	 * Gets the plugin instance.
	 *
	 * @return Plugin
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}
