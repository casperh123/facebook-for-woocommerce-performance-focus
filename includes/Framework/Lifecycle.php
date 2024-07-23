<?php
// phpcs:ignoreFile

/**
 * Facebook for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework;

defined('ABSPATH') or exit;

/**
 * Plugin lifecycle handler.
 *
 * Registers and displays milestone notice prompts and eventually the plugin
 * install, upgrade, activation, and deactivation routines.
 */
class Lifecycle
{

	/** @var Plugin plugin instance */
	private $plugin;

	/**
	 * Constructs the class.
	 *
	 * @param Plugin $plugin plugin instance
	 * @since 5.1.0
	 *
	 */
	public function __construct(Plugin $plugin)
	{
		$this->plugin = $plugin;
		$this->add_hooks();
	}


	/**
	 * Adds the action & filter hooks.
	 *
	 * @since 5.1.0
	 */
	protected function add_hooks()
	{
		add_action('admin_init', array($this, 'handle_activation'));
		add_action('deactivate_' . $this->get_plugin()->get_plugin_file(), array($this, 'handle_deactivation'));
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
	public function handle_activation()
	{
		if (!get_option('wc_' . $this->get_plugin()->get_id() . '_is_active', false)) {
			/**
			 * Fires when the plugin is activated.
			 *
			 * @since 5.2.0
			 */
			do_action('wc_' . $this->get_plugin()->get_id() . '_activated');
			update_option('wc_' . $this->get_plugin()->get_id() . '_is_active', 'yes');
		}
	}


	/**
	 * Triggers plugin deactivation.
	 *
	 * @internal
	 *
	 * @since 5.2.0
	 */
	public function handle_deactivation()
	{
		/**
		 * Fires when the plugin is deactivated.
		 *
		 * @since 5.2.0
		 */
		do_action('wc_' . $this->get_plugin()->get_id() . '_deactivated');
		delete_option('wc_' . $this->get_plugin()->get_id() . '_is_active');
	}


	/**
	 * Gets the plugin instance.
	 *
	 * @return Plugin
	 */
	protected function get_plugin()
	{
		return $this->plugin;
	}
}
