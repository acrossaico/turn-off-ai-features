<?php
/**
 * Tests for the turn-off-ai-features plugin.
 *
 * @package TurnOffAIFeatures
 */

/**
 * Tests core plugin behavior.
 */
class Test_Toaif_Plugin extends WP_UnitTestCase {

	/**
	 * Reset options after each test.
	 */
	public function tear_down() {
		delete_option( 'toaif_disable_ai' );
		delete_option( 'toaif_hide_connectors' );
		delete_option( 'toaif_disable_abilities' );
		delete_option( 'toaif_disable_mcp' );
		parent::tear_down();
	}

	/**
	 * Confirm the main plugin function was loaded.
	 */
	public function test_plugin_loaded_function_exists() {
		$this->assertTrue( function_exists( 'toaif_disable_field_cb' ) );
	}

	/**
	 * When the option is off (unset or '0'), the filter must not interfere.
	 */
	public function test_wp_supports_ai_filter_passthrough_when_option_off() {
		delete_option( 'toaif_disable_ai' );
		$this->assertTrue( apply_filters( 'wp_supports_ai', true ) );

		update_option( 'toaif_disable_ai', '0' );
		$this->assertTrue( apply_filters( 'wp_supports_ai', true ) );
	}

	/**
	 * When the option is '1', the filter must return false.
	 */
	public function test_wp_supports_ai_returns_false_when_option_enabled() {
		update_option( 'toaif_disable_ai', '1' );
		$this->assertFalse( apply_filters( 'wp_supports_ai', true ) );
	}

	/**
	 * WP_AI_SUPPORT constant must not be defined when the option is off at load time.
	 * The constant is set once at plugin load — this test bootstraps with option='0'.
	 */
	public function test_wp_ai_support_constant_not_defined_when_option_off_at_load() {
		$this->assertFalse( defined( 'WP_AI_SUPPORT' ) );
	}

	/**
	 * The wp_supports_ai filter (filter-level fallback) must return false with option on.
	 * Tests the fallback path — covers environments where the constant was not set at load.
	 */
	public function test_wp_supports_ai_filter_returns_false_when_option_on() {
		update_option( 'toaif_disable_ai', '1' );
		$this->assertFalse( (bool) apply_filters( 'wp_supports_ai', true ) );
	}

	/**
	 * The wp_supports_ai filter must pass through when the option is off.
	 */
	public function test_wp_supports_ai_filter_passes_through_when_option_off() {
		update_option( 'toaif_disable_ai', '0' );
		$this->assertTrue( (bool) apply_filters( 'wp_supports_ai', true ) );
	}

	/**
	 * Connectors submenu is removed when both options are on.
	 */
	public function test_connectors_menu_hidden_when_both_options_on() {
		global $submenu;
		$submenu['options-general.php']   = array();
		$submenu['options-general.php'][] = array( 'Connectors', 'manage_options', 'options-connectors.php' );

		update_option( 'toaif_disable_ai', '1' );
		update_option( 'toaif_hide_connectors', '1' );
		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertNotContains( 'options-connectors.php', $slugs );
	}

	/**
	 * Connectors submenu stays when only the sub-toggle is on (main is off).
	 */
	public function test_connectors_menu_visible_when_main_toggle_off() {
		global $submenu;
		$submenu['options-general.php']   = array();
		$submenu['options-general.php'][] = array( 'Connectors', 'manage_options', 'options-connectors.php' );

		update_option( 'toaif_disable_ai', '0' );
		update_option( 'toaif_hide_connectors', '1' );
		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertContains( 'options-connectors.php', $slugs );
	}

	/**
	 * Connectors submenu stays when only the main toggle is on (sub is off).
	 */
	public function test_connectors_menu_visible_when_sub_toggle_off() {
		global $submenu;
		$submenu['options-general.php']   = array();
		$submenu['options-general.php'][] = array( 'Connectors', 'manage_options', 'options-connectors.php' );

		update_option( 'toaif_disable_ai', '1' );
		update_option( 'toaif_hide_connectors', '0' );
		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['options-general.php'], 2 );
		$this->assertContains( 'options-connectors.php', $slugs );
	}

	/**
	 * The shared helper gates every sub-option behind the master switch.
	 */
	public function test_toaif_is_enabled_requires_master_switch() {
		update_option( 'toaif_disable_ai', '0' );
		update_option( 'toaif_disable_abilities', '1' );
		$this->assertFalse( toaif_is_enabled() );
		$this->assertFalse( toaif_abilities_disabled() );

		update_option( 'toaif_disable_ai', '1' );
		$this->assertTrue( toaif_is_enabled() );
		$this->assertTrue( toaif_abilities_disabled() );
	}

	/**
	 * Abilities and MCP sub-options default to on once the master switch is on.
	 */
	public function test_sub_options_default_to_enabled() {
		update_option( 'toaif_disable_ai', '1' );
		delete_option( 'toaif_disable_abilities' );
		delete_option( 'toaif_disable_mcp' );

		$this->assertTrue( toaif_abilities_disabled() );
		$this->assertTrue( toaif_mcp_disabled() );
	}

	/**
	 * The Connectors sub-option keeps its historical off-by-default behaviour.
	 */
	public function test_connectors_sub_option_defaults_to_off() {
		update_option( 'toaif_disable_ai', '1' );
		delete_option( 'toaif_hide_connectors' );

		$this->assertFalse( toaif_is_enabled( 'toaif_hide_connectors', '0' ) );
	}

	/**
	 * The wp_get_abilities() result is emptied while abilities are turned off.
	 */
	public function test_abilities_result_filtered_to_empty() {
		$fixture = array( 'test/ability' => 'placeholder' );

		update_option( 'toaif_disable_ai', '0' );
		$this->assertSame( $fixture, apply_filters( 'wp_get_abilities_result', $fixture, array() ) );

		update_option( 'toaif_disable_ai', '1' );
		$this->assertSame( array(), apply_filters( 'wp_get_abilities_result', $fixture, array() ) );
	}

	/**
	 * Ability registration args are stripped of REST and MCP exposure.
	 */
	public function test_ability_args_hidden_from_rest_and_mcp() {
		update_option( 'toaif_disable_ai', '1' );

		$args = array(
			'label' => 'Test',
			'meta'  => array(
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			),
		);

		$filtered = apply_filters( 'wp_register_ability_args', $args, 'test/ability' );

		$this->assertFalse( $filtered['meta']['show_in_rest'] );
		$this->assertFalse( $filtered['meta']['public'] );
		$this->assertFalse( $filtered['meta']['mcp']['public'] );
	}

	/**
	 * Ability registration args are untouched while the plugin is off.
	 */
	public function test_ability_args_untouched_when_off() {
		update_option( 'toaif_disable_ai', '0' );

		$args     = array( 'meta' => array( 'show_in_rest' => true ) );
		$filtered = apply_filters( 'wp_register_ability_args', $args, 'test/ability' );

		$this->assertTrue( $filtered['meta']['show_in_rest'] );
	}

	/**
	 * Ability permission checks are denied while abilities are turned off.
	 */
	public function test_ability_permission_denied() {
		update_option( 'toaif_disable_ai', '1' );
		$this->assertFalse( apply_filters( 'wp_ability_permission_result', true, 'test/ability', null, null ) );

		update_option( 'toaif_disable_ai', '0' );
		$this->assertTrue( apply_filters( 'wp_ability_permission_result', true, 'test/ability', null, null ) );
	}

	/**
	 * Ability execution is short-circuited with a WP_Error.
	 */
	public function test_ability_execution_short_circuited() {
		$sentinel = new stdClass();

		update_option( 'toaif_disable_ai', '0' );
		$this->assertSame( $sentinel, apply_filters( 'wp_pre_execute_ability', $sentinel, 'test/ability', null, null ) );

		update_option( 'toaif_disable_ai', '1' );
		$result = apply_filters( 'wp_pre_execute_ability', $sentinel, 'test/ability', null, null );

		$this->assertWPError( $result );
		$this->assertSame( 'toaif_ai_disabled', $result->get_error_code() );
	}

	/**
	 * Turning the abilities sub-option off leaves the Abilities API alone.
	 */
	public function test_abilities_untouched_when_sub_option_off() {
		update_option( 'toaif_disable_ai', '1' );
		update_option( 'toaif_disable_abilities', '0' );

		$fixture = array( 'test/ability' => 'placeholder' );
		$this->assertSame( $fixture, apply_filters( 'wp_get_abilities_result', $fixture, array() ) );
	}

	/**
	 * Every registered ability is unregistered on the sweep.
	 */
	public function test_all_abilities_unregistered() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'The Abilities API is not available.' );
		}

		update_option( 'toaif_disable_ai', '1' );

		add_action(
			'wp_abilities_api_categories_init',
			static function () {
				wp_register_ability_category(
					'toaif-test',
					array( 'label' => 'Turn Off AI Features test' )
				);
			}
		);

		add_action(
			'wp_abilities_api_init',
			static function () {
				wp_register_ability(
					'toaif-test/example',
					array(
						'label'               => 'Example',
						'description'         => 'Test ability.',
						'category'            => 'toaif-test',
						'execute_callback'    => '__return_true',
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		// Force the registry to build and fire wp_abilities_api_init.
		WP_Abilities_Registry::get_instance();

		$this->assertFalse( wp_has_ability( 'toaif-test/example' ) );
		$this->assertSame( array(), WP_Abilities_Registry::get_instance()->get_all_registered() );
	}

	/**
	 * The default MCP server is not created while MCP is turned off.
	 */
	public function test_mcp_default_server_blocked() {
		update_option( 'toaif_disable_ai', '0' );
		$this->assertTrue( apply_filters( 'mcp_adapter_create_default_server', true ) );

		update_option( 'toaif_disable_ai', '1' );
		$this->assertFalse( apply_filters( 'mcp_adapter_create_default_server', true ) );
		$this->assertFalse( apply_filters( 'mcp_adapter_enable_stdio_transport', true ) );
	}

	/**
	 * MCP permission checks fall back to an unsatisfiable capability.
	 */
	public function test_mcp_capability_denied() {
		update_option( 'toaif_disable_ai', '1' );

		$this->assertSame( 'do_not_allow', apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', null ) );
		$this->assertSame( 'do_not_allow', apply_filters( 'mcp_adapter_execute_ability_capability', 'read' ) );

		update_option( 'toaif_disable_ai', '0' );
		$this->assertSame( 'read', apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', null ) );
	}

	/**
	 * MCP listings are emptied and calls are short-circuited.
	 */
	public function test_mcp_listings_and_calls_blocked() {
		update_option( 'toaif_disable_ai', '1' );

		$this->assertSame( array(), apply_filters( 'mcp_adapter_tools_list', array( 'tool' ), null ) );
		$this->assertSame( array(), apply_filters( 'mcp_adapter_prompts_list', array( 'prompt' ), null ) );

		$result = apply_filters( 'mcp_adapter_pre_tool_call', array( 'arg' => 1 ), 'tool', null, null );
		$this->assertWPError( $result );
		$this->assertSame( 'toaif_mcp_disabled', $result->get_error_code() );
	}

	/**
	 * The default MCP server config is stripped of every transport.
	 */
	public function test_mcp_default_server_config_stripped() {
		update_option( 'toaif_disable_ai', '1' );

		$config = apply_filters(
			'mcp_adapter_default_server_config',
			array(
				'mcp_transports' => array( 'HttpTransport' ),
				'tools'          => array( 'a' ),
			)
		);

		$this->assertSame( array(), $config['mcp_transports'] );
		$this->assertSame( array(), $config['tools'] );
	}

	/**
	 * MCP REST routes are removed from the REST API.
	 */
	public function test_mcp_rest_routes_removed() {
		$endpoints = array(
			'/mcp/mcp-adapter-default-server' => array( 'callback' ),
			'/wp/v2/posts'                    => array( 'callback' ),
		);

		update_option( 'toaif_disable_ai', '0' );
		$this->assertArrayHasKey( '/mcp/mcp-adapter-default-server', apply_filters( 'rest_endpoints', $endpoints ) );

		update_option( 'toaif_disable_ai', '1' );
		$filtered = apply_filters( 'rest_endpoints', $endpoints );

		$this->assertArrayNotHasKey( '/mcp/mcp-adapter-default-server', $filtered );
		$this->assertArrayHasKey( '/wp/v2/posts', $filtered );
	}

	/**
	 * Turning the MCP sub-option off leaves MCP servers alone.
	 */
	public function test_mcp_untouched_when_sub_option_off() {
		update_option( 'toaif_disable_ai', '1' );
		update_option( 'toaif_disable_mcp', '0' );

		$this->assertTrue( apply_filters( 'mcp_adapter_create_default_server', true ) );
		$this->assertSame( array( 'tool' ), apply_filters( 'mcp_adapter_tools_list', array( 'tool' ), null ) );
	}
}
