<?php
/**
 * Per-product WooCommerce sales, aggregated by day and reported to Rezolve Ai.
 *
 * WHY THE ANALYTICS LOOKUP TABLES AND NOT wc_get_orders()
 * wc_get_orders() is the correct CRUD API and it is the wrong tool here.
 * Hydrating order objects and their line items is expensive enough to need
 * batching and continuation on any real store, and it would have us reading
 * order records that carry names, emails and addresses purely to throw them
 * away. {$prefix}wc_order_product_lookup already holds exactly what we want,
 * product_id with units and net revenue per day, and nothing we do not:
 *
 *   - it is storage-agnostic, populated identically under HPOS and legacy
 *   - it is the SAME source WooCommerce Analytics reads, so our revenue
 *     reconciles with the screen the merchant already trusts rather than
 *     offering a competing number they have to reconcile by hand
 *   - it is one GROUP BY rather than a batch job
 *
 * WHAT IS DELIBERATELY NOT SENT
 * No order ids, no customer ids, no emails, no addresses. Per-product units and
 * revenue per day is store business data, not personal data, which is what
 * keeps this clear of wp_privacy_* exporter and eraser obligations. Sending
 * order-level rows would flip that and is not worth it for any question this
 * feature asks.
 *
 * DEGRADES RATHER THAN LIES
 * The lookup tables only exist when WooCommerce Analytics is enabled, and on
 * some stores the historical import never finished. Both cases report nothing
 * rather than reporting zeros, because a zero here would read as "you sold
 * nothing" when the truth is "we cannot see".
 *
 * @package Agencoen
 */

namespace Agencoen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sales_Tracker {

	/** Days of history sent per run. */
	const REPORT_DAYS = 3;
	/** Products per day. */
	const MAX_PRODUCTS = 1000;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Whether WooCommerce Analytics has the tables we need. */
	public function is_available() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * Aggregate and send. Returns false when there is nothing to say, which
	 * includes the case where Analytics is off.
	 */
	public function report_back() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_traffic_report() ) {
			return false;
		}
		$store_id = $settings->get_store_id();
		if ( ! $store_id || ! $this->is_available() ) {
			return false;
		}

		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';
		$since  = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . self::REPORT_DAYS . ' days' ) );

		// Joined to wc_order_stats for the status filter: the lookup table holds
		// rows for every status, so counting it alone would report cancelled and
		// failed orders as revenue.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(l.date_created) AS day,
				        l.product_id AS product_id,
				        SUM(l.product_qty) AS units,
				        SUM(l.product_net_revenue) AS net,
				        COUNT(DISTINCT l.order_id) AS orders
				 FROM {$lookup} l
				 INNER JOIN {$stats} s ON s.order_id = l.order_id
				 WHERE l.date_created >= %s
				   AND s.status IN ('wc-completed','wc-processing','wc-on-hold')
				 GROUP BY day, l.product_id
				 ORDER BY day ASC, net DESC
				 LIMIT %d",
				$since,
				self::MAX_PRODUCTS * self::REPORT_DAYS
			)
		);

		if ( empty( $rows ) ) {
			return false;
		}

		$days = array();
		foreach ( $rows as $row ) {
			$day = (string) $row->day;
			if ( ! isset( $days[ $day ] ) ) {
				$days[ $day ] = array();
			}
			if ( count( $days[ $day ] ) >= self::MAX_PRODUCTS ) {
				continue;
			}
			$days[ $day ][] = array(
				'productId'    => (int) $row->product_id,
				'units'        => (int) $row->units,
				// Minor units, so the server never has to guess decimal places.
				'revenueMinor' => (int) round( (float) $row->net * 100 ),
				'orders'       => (int) $row->orders,
			);
		}

		$payload = array();
		foreach ( $days as $day => $products ) {
			$payload[] = array(
				'date'     => $day,
				'products' => $products,
			);
		}

		$result = $this->plugin->api()->sales_ingest( $store_id, $payload );
		return ! is_wp_error( $result );
	}
}
