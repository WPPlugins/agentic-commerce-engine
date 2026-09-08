<?php
/**
 * Capability resolution for the two site modes.
 *
 * With WooCommerce active the plugin belongs to shop managers
 * (`manage_woocommerce`); on a plain WordPress site it belongs to
 * administrators (`manage_options`). Filterable for bespoke roles.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Capabilities
 */
class Capabilities {

	/**
	 * The capability required for every Rezolve Ai admin screen and ajax action.
	 *
	 * @return string
	 */
	public static function manage() {
		$cap = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';

		/**
		 * Filter the capability that guards Rezolve Ai screens and actions.
		 *
		 * @param string $cap Resolved capability.
		 */
		return apply_filters( 'agencoen_capability', $cap );
	}

	/**
	 * Whether the current user may use Rezolve Ai.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage() {
		return current_user_can( self::manage() );
	}
}
