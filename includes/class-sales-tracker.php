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
 * AGENT-SENT ORDERS
 * Orders that came through a shopping agent's buy link carry the opaque token
 * Rezolve Ai put on that link (see Ucp). They are reported as day, token and
 * product totals, with the number of distinct orders per token per day. The
 * token is the only join; no order id, customer id or email is sent, the same
 * line the rest of this report holds.
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
	/** Agent-attributed orders read per run. */
	const MAX_AGENT_ORDERS = 500;

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
		if ( ! $store_id ) {
			return false;
		}

		$agent = $this->agent_report();
		$days  = $this->is_available() ? $this->sales_days() : array();
		if ( empty( $days ) && empty( $agent ) ) {
			return false;
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$result   = $this->plugin->api()->sales_ingest( $store_id, $days, $agent, $currency );
		return ! is_wp_error( $result );
	}

	/**
	 * Per-product sales per day from the Analytics lookup tables, or an empty
	 * array when there are none.
	 *
	 * @return array<int,array{date:string,products:array}>
	 */
	private function sales_days() {
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
			return array();
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

		return $payload;
	}

	/**
	 * Agent-sent orders and landings over the report window.
	 *
	 * Reads order ids by meta, then each order's line items: product, quantity
	 * and net line total. The order object is loaded to read those and nothing
	 * else leaves this method.
	 *
	 * @return array<int,array{date:string,handoffs:array}>
	 */
	private function agent_report() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$since = gmdate( 'Y-m-d', strtotime( '-' . self::REPORT_DAYS . ' days' ) );

		$ids = wc_get_orders(
			array(
				'limit'        => self::MAX_AGENT_ORDERS,
				'return'       => 'ids',
				'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'date_created' => '>=' . strtotime( $since . ' 00:00:00' ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Ucp::ORDER_META_AGENT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$orders = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$created = $order->get_date_created();
			$items   = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array(
					'product_id' => (int) $item->get_product_id(),
					'quantity'   => (int) $item->get_quantity(),
					'total'      => (float) $item->get_total(),
				);
			}
			$orders[] = array(
				'date'  => $created ? $created->date( 'Y-m-d' ) : '',
				'ref'   => (string) $order->get_meta( Ucp::ORDER_META_AGENT ),
				'items' => $items,
			);
		}

		return self::fold_agent_orders( $orders, Ucp::landings_since( $since ) );
	}

	/**
	 * Fold agent orders and landings into the report's day, token and product
	 * totals. Pure, so the test runner can drive it.
	 *
	 * @param array $orders   Each {date, ref, items: [{product_id, quantity, total}]}.
	 * @param array $landings day => [ref => count].
	 * @return array<int,array{date:string,handoffs:array}>
	 */
	public static function fold_agent_orders( array $orders, array $landings ) {
		$days = array();

		foreach ( $landings as $day => $refs ) {
			foreach ( (array) $refs as $ref => $count ) {
				$ref = Ucp::sanitize_agent_ref( (string) $ref );
				if ( '' === $ref || (int) $count <= 0 ) {
					continue;
				}
				$days[ $day ][ $ref ]             = isset( $days[ $day ][ $ref ] ) ? $days[ $day ][ $ref ] : self::empty_handoff();
				$days[ $day ][ $ref ]['landings'] = (int) $count;
			}
		}

		foreach ( $orders as $order ) {
			$ref = Ucp::sanitize_agent_ref( isset( $order['ref'] ) ? $order['ref'] : '' );
			$day = isset( $order['date'] ) ? (string) $order['date'] : '';
			if ( '' === $ref || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
				continue;
			}
			$entry = isset( $days[ $day ][ $ref ] ) ? $days[ $day ][ $ref ] : self::empty_handoff();
			++$entry['orders'];
			foreach ( isset( $order['items'] ) ? (array) $order['items'] : array() as $item ) {
				$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				if ( $pid <= 0 ) {
					continue;
				}
				if ( ! isset( $entry['products'][ $pid ] ) ) {
					$entry['products'][ $pid ] = array(
						'productId'    => $pid,
						'units'        => 0,
						'revenueMinor' => 0,
					);
				}
				$entry['products'][ $pid ]['units']        += max( 0, (int) $item['quantity'] );
				// Minor units, so the server never has to guess decimal places.
				$entry['products'][ $pid ]['revenueMinor'] += (int) round( (float) $item['total'] * 100 );
			}
			$days[ $day ][ $ref ] = $entry;
		}

		ksort( $days );
		$out = array();
		foreach ( $days as $day => $refs ) {
			$handoffs = array();
			foreach ( $refs as $ref => $entry ) {
				$handoffs[] = array(
					'ref'      => $ref,
					'landings' => $entry['landings'],
					'orders'   => $entry['orders'],
					'products' => array_values( $entry['products'] ),
				);
			}
			$out[] = array(
				'date'     => $day,
				'handoffs' => $handoffs,
			);
		}
		return $out;
	}

	/** @return array{landings:int,orders:int,products:array} */
	private static function empty_handoff() {
		return array(
			'landings' => 0,
			'orders'   => 0,
			'products' => array(),
		);
	}
}
