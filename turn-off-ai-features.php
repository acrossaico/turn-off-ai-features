<?php
/**
 * Plugin Name: Turn Off AI Features
 * Description: Adds an option to the General Settings page to turn off AI features in WordPress, including the Abilities API and MCP servers.
 * Version:     1.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:      raftaar1191
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turn-off-ai-features
 * Domain Path: /languages
 *
 * @package TurnOffAIFeatures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version. Also used to cache-bust enqueued assets.
 */
define( 'TOAIF_VERSION', '1.1.0' );

/**
 * Returns whether a Turn Off AI Features toggle is currently active.
 *
 * Every sub-toggle is gated behind the master switch, so a sub-toggle only takes
 * effect while AI features are turned off site-wide.
 *
 * @param string $sub_option    Optional. Sub-option to check alongside the master switch.
 * @param string $default_value Optional. Default value for the sub-option. Default '1'.
 * @return bool Whether the toggle is active.
 */
function toaif_is_enabled( $sub_option = '', $default_value = '1' ) {
	if ( '1' !== get_option( 'toaif_disable_ai', '0' ) ) {
		return false;
	}

	if ( '' === $sub_option ) {
		return true;
	}

	return '1' === get_option( $sub_option, $default_value );
}

/**
 * Returns whether the Abilities API should be turned off.
 *
 * @return bool Whether abilities should be disabled.
 */
function toaif_abilities_disabled() {
	return toaif_is_enabled( 'toaif_disable_abilities' );
}

/**
 * Returns whether MCP servers should be turned off.
 *
 * @return bool Whether MCP servers should be disabled.
 */
function toaif_mcp_disabled() {
	return toaif_is_enabled( 'toaif_disable_mcp' );
}

/**
 * Defines WP_AI_SUPPORT as false at load time when the option is enabled.
 * WP core checks this constant before the wp_supports_ai filter.
 */
if ( '1' === get_option( 'toaif_disable_ai', '0' ) ) {

	if ( ! defined( 'WP_AI_SUPPORT' ) ) {
		define( 'WP_AI_SUPPORT', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	}

	add_filter( 'jetpack_ai_enabled', '__return_false' );
	add_filter( 'wpforms_disable_ai_features', '__return_true' );
}

/**
 * Hooks into wp_supports_ai as a filter-level fallback when the option is enabled.
 */
add_filter(
	'wp_supports_ai',
	static function ( $supported ) {
		if ( toaif_is_enabled() ) {
			return false;
		}
		return $supported;
	},
	1000
);

/*
 * ---------------------------------------------------------------------------
 * Abilities API
 *
 * The Abilities API does not consult wp_supports_ai() or WP_AI_SUPPORT, so it
 * has to be turned off separately. No single core filter disables abilities,
 * so several layers are combined below: the registry is emptied, late
 * registrations are stripped of their REST exposure, listings are emptied, and
 * execution is denied.
 * ---------------------------------------------------------------------------
 */

/**
 * Unregisters every registered ability.
 *
 * Runs at PHP_INT_MAX on wp_abilities_api_init so it fires after core (priority
 * 10) and after other plugins have registered.
 *
 * Enumerates through the registry instance rather than wp_get_abilities(),
 * because the wp_get_abilities_result filter below already empties that
 * function's return value while abilities are turned off.
 *
 * @param WP_Abilities_Registry|null $registry The abilities registry instance.
 * @return void
 */
function toaif_unregister_all_abilities( $registry = null ) {
	if ( ! toaif_abilities_disabled() ) {
		return;
	}

	if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_all_registered' ) ) {
		return;
	}

	// Snapshot the names first: the registry array is mutated while unregistering.
	$names = array_keys( $registry->get_all_registered() );

	foreach ( $names as $name ) {
		// Guard against _doing_it_wrong() notices for names already gone.
		if ( $registry->is_registered( $name ) ) {
			$registry->unregister( $name );
		}
	}
}
add_action( 'wp_abilities_api_init', 'toaif_unregister_all_abilities', PHP_INT_MAX, 1 );

/**
 * Forces every ability registration to be hidden from REST and from MCP.
 *
 * Catches abilities registered after the sweep above. Core resolves
 * meta.show_in_rest from meta.public, and MCP exposure keys off meta.public
 * too, so clearing both closes each surface without emitting debug notices.
 *
 * @param array  $args The ability registration arguments.
 * @param string $name The ability name.
 * @return array The filtered arguments.
 */
function toaif_filter_ability_args( $args, $name ) {
	unset( $name );

	if ( ! toaif_abilities_disabled() ) {
		return $args;
	}

	if ( ! is_array( $args ) ) {
		return $args;
	}

	if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
		$args['meta'] = array();
	}

	$args['meta']['show_in_rest'] = false;
	$args['meta']['public']       = false;

	// MCP exposure reads meta.mcp.public before falling back to meta.public.
	if ( isset( $args['meta']['mcp'] ) && is_array( $args['meta']['mcp'] ) ) {
		$args['meta']['mcp']['public'] = false;
	}

	return $args;
}
add_filter( 'wp_register_ability_args', 'toaif_filter_ability_args', PHP_INT_MAX, 2 );

/**
 * Empties the result of wp_get_abilities().
 *
 * Closes the REST list controller and MCP ability discovery, both of which read
 * through wp_get_abilities(). Filter is WP 7.1+; on earlier versions the hook
 * never fires and this callback is simply inert.
 *
 * @param array $matched The matched abilities.
 * @param array $args    The arguments passed to wp_get_abilities().
 * @return array The filtered abilities.
 */
function toaif_filter_abilities_result( $matched, $args ) {
	unset( $args );

	if ( toaif_abilities_disabled() ) {
		return array();
	}

	return $matched;
}
add_filter( 'wp_get_abilities_result', 'toaif_filter_abilities_result', PHP_INT_MAX, 2 );

/**
 * Denies the permission check for any ability that still resolves.
 *
 * Filter is WP 7.1+; inert on earlier versions.
 *
 * @param bool|WP_Error $permission The permission result.
 * @return bool|WP_Error The filtered permission result.
 */
function toaif_filter_ability_permission( $permission ) {
	if ( toaif_abilities_disabled() ) {
		return false;
	}

	return $permission;
}
add_filter( 'wp_ability_permission_result', 'toaif_filter_ability_permission', PHP_INT_MAX );

/**
 * Short-circuits ability execution.
 *
 * Returning any value other than the sentinel passed in bypasses input
 * normalization, validation, the permission check and the execute callback.
 * Filter is WP 7.1+; inert on earlier versions.
 *
 * @param mixed  $pre          The pre-computed result sentinel.
 * @param string $ability_name The ability name.
 * @return mixed WP_Error when disabled, otherwise the sentinel unchanged.
 */
function toaif_pre_execute_ability( $pre, $ability_name ) {
	if ( ! toaif_abilities_disabled() ) {
		return $pre;
	}

	return new WP_Error(
		'toaif_ai_disabled',
		sprintf(
			/* translators: %s: Ability name. */
			__( 'The ability "%s" is unavailable because AI features are turned off on this site.', 'turn-off-ai-features' ),
			$ability_name
		),
		array( 'status' => 403 )
	);
}
add_filter( 'wp_pre_execute_ability', 'toaif_pre_execute_ability', PHP_INT_MAX, 2 );

/*
 * ---------------------------------------------------------------------------
 * MCP servers
 *
 * The MCP Adapter has no settings option; it is entirely filter-driven. Every
 * server, default and third-party, is created inside the single
 * mcp_adapter_init action, so stopping that action stops every server. The
 * remaining filters are defence in depth.
 * ---------------------------------------------------------------------------
 */

/**
 * Prevents the MCP Adapter from initializing.
 *
 * The adapter bootstraps at file-include time and hooks its init() to
 * rest_api_init (priority 15), or to init (priority 20) under WP-CLI. Removing
 * both stops mcp_adapter_init from ever firing, so no server is created and no
 * transport route is registered.
 *
 * @return void
 */
function toaif_disable_mcp_adapter() {
	if ( ! toaif_mcp_disabled() ) {
		return;
	}

	if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		return;
	}

	$adapter = \WP\MCP\Core\McpAdapter::instance();

	remove_action( 'rest_api_init', array( $adapter, 'init' ), 15 );
	remove_action( 'init', array( $adapter, 'init' ), 20 );
}
add_action( 'plugins_loaded', 'toaif_disable_mcp_adapter', 0 );

/**
 * Neutralizes mcp_adapter_init if the adapter is initialized another way.
 *
 * Runs at PHP_INT_MIN, ahead of the default server factory (priority 10) and of
 * any third-party server registration.
 *
 * @return void
 */
function toaif_neutralize_mcp_adapter_init() {
	if ( ! toaif_mcp_disabled() ) {
		return;
	}

	remove_all_actions( 'mcp_adapter_init' );
}
add_action( 'mcp_adapter_init', 'toaif_neutralize_mcp_adapter_init', PHP_INT_MIN );

/**
 * Removes MCP transport routes from the REST API.
 *
 * Final safety net for servers registered outside the adapter's init, including
 * any built with a custom transport permission callback (which bypasses the
 * capability filter below).
 *
 * @param array $endpoints The registered REST routes.
 * @return array The filtered routes.
 */
function toaif_filter_mcp_rest_endpoints( $endpoints ) {
	if ( ! toaif_mcp_disabled() || ! is_array( $endpoints ) ) {
		return $endpoints;
	}

	$namespaces = array( 'mcp' );

	if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		$servers = \WP\MCP\Core\McpAdapter::instance()->get_servers();

		if ( is_array( $servers ) ) {
			foreach ( $servers as $server ) {
				if ( is_object( $server ) && method_exists( $server, 'get_server_route_namespace' ) ) {
					$namespaces[] = $server->get_server_route_namespace();
				}
			}
		}
	}

	$namespaces = array_unique( array_filter( $namespaces ) );

	foreach ( array_keys( $endpoints ) as $route ) {
		foreach ( $namespaces as $namespace ) {
			if ( 0 === strpos( $route, '/' . $namespace . '/' ) ) {
				unset( $endpoints[ $route ] );
				break;
			}
		}
	}

	return $endpoints;
}
add_filter( 'rest_endpoints', 'toaif_filter_mcp_rest_endpoints', PHP_INT_MAX );

/**
 * Returns false for MCP boolean filters while MCP is turned off.
 *
 * @param bool $value The incoming value.
 * @return bool The filtered value.
 */
function toaif_mcp_return_false( $value ) {
	return toaif_mcp_disabled() ? false : $value;
}
add_filter( 'mcp_adapter_create_default_server', 'toaif_mcp_return_false', PHP_INT_MAX );
add_filter( 'mcp_adapter_enable_stdio_transport', 'toaif_mcp_return_false', PHP_INT_MAX );

/**
 * Returns an unsatisfiable capability for MCP permission checks.
 *
 * Must return a non-empty string: the adapter coerces anything else back to
 * the 'read' default.
 *
 * @param string $capability The incoming capability.
 * @return string The filtered capability.
 */
function toaif_mcp_deny_capability( $capability ) {
	return toaif_mcp_disabled() ? 'do_not_allow' : $capability;
}
add_filter( 'mcp_adapter_default_transport_permission_user_capability', 'toaif_mcp_deny_capability', PHP_INT_MAX );
add_filter( 'mcp_adapter_discover_abilities_capability', 'toaif_mcp_deny_capability', PHP_INT_MAX );
add_filter( 'mcp_adapter_get_ability_info_capability', 'toaif_mcp_deny_capability', PHP_INT_MAX );
add_filter( 'mcp_adapter_execute_ability_capability', 'toaif_mcp_deny_capability', PHP_INT_MAX );

/**
 * Strips every transport from the default MCP server configuration.
 *
 * A server with no transports registers no REST route.
 *
 * @param array $config The default server configuration.
 * @return array The filtered configuration.
 */
function toaif_filter_mcp_default_server_config( $config ) {
	if ( toaif_mcp_disabled() && is_array( $config ) ) {
		$config['mcp_transports'] = array();
		$config['tools']          = array();
		$config['resources']      = array();
		$config['prompts']        = array();
	}

	return $config;
}
add_filter( 'mcp_adapter_default_server_config', 'toaif_filter_mcp_default_server_config', PHP_INT_MAX );

/**
 * Empties MCP tool, resource and prompt listings.
 *
 * @param array $items The listed items.
 * @return array The filtered items.
 */
function toaif_filter_mcp_list( $items ) {
	return toaif_mcp_disabled() ? array() : $items;
}
add_filter( 'mcp_adapter_tools_list', 'toaif_filter_mcp_list', PHP_INT_MAX );
add_filter( 'mcp_adapter_resources_list', 'toaif_filter_mcp_list', PHP_INT_MAX );
add_filter( 'mcp_adapter_prompts_list', 'toaif_filter_mcp_list', PHP_INT_MAX );

/**
 * Short-circuits MCP tool calls, resource reads and prompt gets.
 *
 * The adapter's handlers bail when these filters return a WP_Error.
 *
 * @param mixed $value The incoming value.
 * @return mixed WP_Error when disabled, otherwise the value unchanged.
 */
function toaif_block_mcp_request( $value ) {
	if ( ! toaif_mcp_disabled() ) {
		return $value;
	}

	return new WP_Error(
		'toaif_mcp_disabled',
		__( 'MCP servers are turned off on this site.', 'turn-off-ai-features' ),
		array( 'status' => 403 )
	);
}
add_filter( 'mcp_adapter_pre_tool_call', 'toaif_block_mcp_request', PHP_INT_MAX );
add_filter( 'mcp_adapter_pre_resource_read', 'toaif_block_mcp_request', PHP_INT_MAX );
add_filter( 'mcp_adapter_pre_prompt_get', 'toaif_block_mcp_request', PHP_INT_MAX );

/**
 * Returns the settings fields rendered on the General Settings page.
 *
 * @return array Field definitions keyed by option name.
 */
function toaif_get_settings_fields() {
	return array(
		'toaif_disable_ai'        => array(
			'title'   => __( 'AI Features', 'turn-off-ai-features' ),
			'label'   => __( 'Turn off AI features on this site', 'turn-off-ai-features' ),
			'default' => '0',
		),
		'toaif_disable_abilities' => array(
			'title'   => __( 'Abilities API', 'turn-off-ai-features' ),
			'label'   => __( 'Also unregister every ability and block the Abilities REST API', 'turn-off-ai-features' ),
			'default' => '1',
		),
		'toaif_disable_mcp'       => array(
			'title'   => __( 'MCP Servers', 'turn-off-ai-features' ),
			'label'   => __( 'Also turn off every MCP server and its REST routes', 'turn-off-ai-features' ),
			'default' => '1',
		),
		'toaif_hide_connectors'   => array(
			'title'   => __( 'Hide Connectors Page', 'turn-off-ai-features' ),
			'label'   => __( 'Also hide the Connectors page from the Settings menu', 'turn-off-ai-features' ),
			'default' => '0',
		),
	);
}

/**
 * Registers the settings and adds them to the General Settings page.
 */
add_action(
	'admin_init',
	static function () {
		foreach ( toaif_get_settings_fields() as $option => $field ) {
			register_setting(
				'general',
				$option,
				array(
					'type'              => 'string',
					'sanitize_callback' => static function ( $value ) {
						return '1' === $value ? '1' : '0';
					},
					'default'           => $field['default'],
				)
			);

			add_settings_field(
				$option,
				$field['title'],
				'toaif_render_field',
				'general',
				'default',
				array( 'option' => $option )
			);
		}
	}
);

/**
 * Renders a Turn Off AI Features checkbox on the General Settings page.
 *
 * @param array $args Field arguments. Expects an 'option' key.
 * @return void
 */
function toaif_render_field( $args ) {
	$option = isset( $args['option'] ) ? $args['option'] : '';
	$fields = toaif_get_settings_fields();

	if ( ! isset( $fields[ $option ] ) ) {
		return;
	}

	$field = $fields[ $option ];
	$value = get_option( $option, $field['default'] );
	?>
	<label for="<?php echo esc_attr( $option ); ?>">
		<input
			type="checkbox"
			name="<?php echo esc_attr( $option ); ?>"
			id="<?php echo esc_attr( $option ); ?>"
			value="1"
			<?php checked( '1', $value ); ?>
		/>
		<?php echo esc_html( $field['label'] ); ?>
	</label>
	<?php
}

/**
 * Renders the AI Features checkbox field.
 *
 * Retained for backwards compatibility with anything calling it directly.
 *
 * @return void
 */
function toaif_disable_field_cb() {
	toaif_render_field( array( 'option' => 'toaif_disable_ai' ) );
}

/**
 * Renders the Hide Connectors Page checkbox field.
 *
 * Retained for backwards compatibility with anything calling it directly.
 *
 * @return void
 */
function toaif_hide_connectors_field_cb() {
	toaif_render_field( array( 'option' => 'toaif_hide_connectors' ) );
}

/**
 * Enqueues the admin settings script on the General Settings page.
 */
add_action(
	'admin_enqueue_scripts',
	static function ( $hook_suffix ) {
		if ( 'options-general.php' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			'toaif-admin-settings',
			plugins_url( 'assets/js/admin-settings.js', __FILE__ ),
			array(),
			TOAIF_VERSION,
			true
		);
	}
);

/**
 * Removes the Connectors submenu entry when both hide options are enabled.
 */
add_action(
	'admin_menu',
	static function () {
		if ( toaif_is_enabled( 'toaif_hide_connectors', '0' ) ) {
			remove_submenu_page( 'options-general.php', 'options-connectors.php' );
		}
	},
	999
);

/**
 * Redirects direct visits to the Connectors page when both hide options are enabled.
 */
add_action(
	'load-options-connectors.php',
	static function () {
		if ( toaif_is_enabled( 'toaif_hide_connectors', '0' ) ) {
			wp_safe_redirect( admin_url( 'options-general.php#toaif_disable_ai' ) );
			exit;
		}
	}
);

/**
 * Adds a "Settings" link on the Plugins page pointing to Settings > General.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php#toaif_disable_ai' ) ),
			esc_html__( 'Settings', 'turn-off-ai-features' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
);

/**
 * Registers the WP-CLI commands for managing AI features.
 *
 * Commands:
 *   wp toaif disable   — Turns off AI features site-wide.
 *   wp toaif enable    — Turns on AI features site-wide.
 *   wp toaif status    — Shows the current AI on/off state.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Manages AI features via WP-CLI.
	 */
	class TOAIF_Disable_CLI extends WP_CLI_Command {

		/**
		 * Turns off AI features site-wide.
		 *
		 * ## OPTIONS
		 *
		 * [--abilities]
		 * : Also unregister every ability and block the Abilities REST API.
		 *
		 * [--mcp]
		 * : Also turn off every MCP server and its REST routes.
		 *
		 * ## EXAMPLES
		 *
		 *   wp toaif disable
		 *   wp toaif disable --abilities --mcp
		 *
		 * @subcommand disable
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function disable( $args, $assoc_args ) {
			unset( $args );

			update_option( 'toaif_disable_ai', '1' );

			if ( ! empty( $assoc_args['abilities'] ) ) {
				update_option( 'toaif_disable_abilities', '1' );
			}

			if ( ! empty( $assoc_args['mcp'] ) ) {
				update_option( 'toaif_disable_mcp', '1' );
			}

			WP_CLI::success( 'AI features have been turned off.' );
		}

		/**
		 * Turns on AI features site-wide.
		 *
		 * ## OPTIONS
		 *
		 * [--abilities]
		 * : Turn the Abilities API back on without turning AI features back on.
		 *
		 * [--mcp]
		 * : Turn MCP servers back on without turning AI features back on.
		 *
		 * ## EXAMPLES
		 *
		 *   wp toaif enable
		 *   wp toaif enable --mcp
		 *
		 * @subcommand enable
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function enable( $args, $assoc_args ) {
			unset( $args );

			$scoped = false;

			if ( ! empty( $assoc_args['abilities'] ) ) {
				update_option( 'toaif_disable_abilities', '0' );
				$scoped = true;
			}

			if ( ! empty( $assoc_args['mcp'] ) ) {
				update_option( 'toaif_disable_mcp', '0' );
				$scoped = true;
			}

			if ( $scoped ) {
				WP_CLI::success( 'The selected features have been turned back on.' );
				return;
			}

			update_option( 'toaif_disable_ai', '0' );
			WP_CLI::success( 'AI features have been turned on.' );
		}

		/**
		 * Shows the current AI on/off status.
		 *
		 * ## EXAMPLES
		 *
		 *   wp toaif status
		 *
		 * @subcommand status
		 */
		public function status() {
			$off = toaif_is_enabled();

			WP_CLI::log( 'AI features are currently: ' . ( $off ? 'off' : 'on' ) );
			WP_CLI::log( 'Abilities API: ' . ( toaif_abilities_disabled() ? 'off' : 'on' ) );
			WP_CLI::log( 'MCP servers:   ' . ( toaif_mcp_disabled() ? 'off' : 'on' ) );

			if ( function_exists( 'wp_get_abilities' ) ) {
				WP_CLI::log( 'Registered abilities visible right now: ' . count( wp_get_abilities() ) );
			}
		}
	}

	WP_CLI::add_command( 'toaif', 'TOAIF_Disable_CLI' );
}
