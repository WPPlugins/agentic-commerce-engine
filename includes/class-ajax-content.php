<?php
/**
 * admin-ajax handlers for the Content screen (Content Hub): type catalog,
 * article generation + job polling, brief listing, publishing to WP, and the
 * brand voice note.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Content
 */
class Ajax_Content extends Ajax_Base {

	/**
	 * Register every wp_ajax_agencoen_* action in this group.
	 */
	public function register() {
		$actions = array(
			'content_types_list',
			'content_generate',
			'content_job_status',
			'content_briefs',
			'content_brief_get',
			'content_publish',
			'content_discard',
			'content_posts',
			'brand_get',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_agencoen_' . $action, array( $this, $action ) );
		}
	}

	/**
	 * Content-type catalog (article types + enrichment types).
	 */
	public function content_types_list() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$types = $this->plugin->articles()->types( isset( $_POST['fresh'] ) );
		if ( is_wp_error( $types ) ) {
			wp_send_json_error( $this->error_payload( $types ) );
		}
		wp_send_json_success( $types );
	}

	/**
	 * Start an article generation job.
	 */
	public function content_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( strlen( trim( $title ) ) < 3 ) {
			wp_send_json_error( array( 'message' => __( 'Give the article a title of at least 3 characters.', 'agentic-commerce-engine' ) ) );
		}

		$args = array( 'title' => $title );

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		if ( '' !== $type ) {
			$args['type'] = $type;
		}

		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
		if ( '' !== $locale ) {
			$args['locale'] = $locale;
		}

		$overview = isset( $_POST['overview'] ) ? sanitize_textarea_field( wp_unslash( $_POST['overview'] ) ) : '';
		if ( '' !== $overview ) {
			$args['overview'] = $overview;
		}

		$keywords_raw = isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '';
		if ( '' !== $keywords_raw ) {
			$keywords = array_values( array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) ) );
			if ( ! empty( $keywords ) ) {
				$args['keywords'] = array_slice( $keywords, 0, 30 );
			}
		}

		$tone = isset( $_POST['tone'] ) ? sanitize_text_field( wp_unslash( $_POST['tone'] ) ) : '';
		if ( '' !== $tone ) {
			$args['tone'] = $tone;
		}

		$word_count = isset( $_POST['word_count'] ) ? absint( $_POST['word_count'] ) : 0;
		if ( $word_count > 0 ) {
			$args['wordCount'] = min( 8000, max( 100, $word_count ) );
		}

		// Category grounding (only when the selected type supports it).
		$category_path = isset( $_POST['category_path'] ) ? sanitize_text_field( wp_unslash( $_POST['category_path'] ) ) : '';
		$category_name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
		if ( '' !== $category_path && '' !== $category_name ) {
			$args['categoryTarget'] = array(
				'path' => $category_path,
				'name' => $category_name,
			);
		}

		$result = $this->plugin->articles()->generate( $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Poll one generation job (also materializes the draft on completion).
	 */
	public function content_job_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		if ( '' === $job_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing job id.', 'agentic-commerce-engine' ) ) );
		}

		$status = $this->plugin->articles()->job_status( $job_id );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( $this->error_payload( $status ) );
		}
		wp_send_json_success( $status );
	}

	/**
	 * List project briefs with filters + pagination.
	 */
	public function content_briefs() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$query = array();

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( '' !== $status && 'all' !== $status ) {
			$query['status'] = $status;
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( '' !== $search ) {
			$query['search'] = $search;
		}

		$page          = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$query['page'] = (string) $page;

		$result = $this->plugin->articles()->briefs( $query );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Full article payload for one brief.
	 */
	public function content_brief_get() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$brief_id = isset( $_POST['brief_id'] ) ? sanitize_text_field( wp_unslash( $_POST['brief_id'] ) ) : '';
		if ( '' === $brief_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing brief id.', 'agentic-commerce-engine' ) ) );
		}

		$detail = $this->plugin->articles()->article( $brief_id );
		if ( is_wp_error( $detail ) ) {
			wp_send_json_error( $this->error_payload( $detail ) );
		}
		wp_send_json_success( $detail );
	}

	/**
	 * Publish an article to WordPress.
	 *
	 * Three shapes:
	 *  - { brief_id, post_id }               flip an existing draft live.
	 *  - { brief_id }                        materialize with the settings status.
	 *  - { brief_id, post_id, republish:1 }  refetch from ACE and update the post
	 *                                        in place, keeping its current status.
	 */
	public function content_publish() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$brief_id  = isset( $_POST['brief_id'] ) ? sanitize_text_field( wp_unslash( $_POST['brief_id'] ) ) : '';
		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$republish = ! empty( $_POST['republish'] );

		if ( '' === $brief_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing brief id.', 'agentic-commerce-engine' ) ) );
		}

		$articles  = $this->plugin->articles();
		$publisher = $this->plugin->publisher();

		if ( $post_id > 0 && $republish ) {
			// Republish: pull the latest article from ACE and update in place.
			$detail = $articles->article( $brief_id );
			if ( is_wp_error( $detail ) ) {
				wp_send_json_error( $this->error_payload( $detail ) );
			}

			$current = get_post_status( $post_id );
			$updated = $publisher->materialize( $detail, array( 'status' => $current ? $current : 'draft' ) );
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( $this->error_payload( $updated ) );
			}

			$live = 'publish' === get_post_status( $updated );
			$articles->report_published( $brief_id, $updated, $live );

			wp_send_json_success(
				array(
					'postId'   => (int) $updated,
					'url'      => $live ? get_permalink( $updated ) : null,
					'editLink' => get_edit_post_link( $updated, 'raw' ),
				)
			);
		}

		if ( $post_id > 0 ) {
			// Flip an existing materialized draft live.
			$permalink = $publisher->publish( $post_id );
			if ( is_wp_error( $permalink ) ) {
				wp_send_json_error( $this->error_payload( $permalink ) );
			}

			$articles->report_published( $brief_id, $post_id, true );

			wp_send_json_success(
				array(
					'postId'   => $post_id,
					'url'      => $permalink,
					'editLink' => get_edit_post_link( $post_id, 'raw' ),
				)
			);
		}

		// No post yet: fetch the article and materialize with the settings status.
		$detail = $articles->article( $brief_id );
		if ( is_wp_error( $detail ) ) {
			wp_send_json_error( $this->error_payload( $detail ) );
		}

		$new_id = $publisher->materialize( $detail, array( 'status' => $this->plugin->settings()->get_content_status() ) );
		if ( is_wp_error( $new_id ) ) {
			wp_send_json_error( $this->error_payload( $new_id ) );
		}

		$live = 'publish' === get_post_status( $new_id );
		$articles->report_published( $brief_id, $new_id, $live );

		wp_send_json_success(
			array(
				'postId'   => (int) $new_id,
				'url'      => $live ? get_permalink( $new_id ) : null,
				'editLink' => get_edit_post_link( $new_id, 'raw' ),
			)
		);
	}

	/**
	 * Trash a materialized post (guarded to ACE-authored posts).
	 */
	public function content_discard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing post id.', 'agentic-commerce-engine' ) ) );
		}

		if ( ! $this->plugin->publisher()->discard( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not move this post to the trash.', 'agentic-commerce-engine' ) ) );
		}
		wp_send_json_success( array( 'trashed' => true ) );
	}

	/**
	 * Local WP posts materialized from briefs (works without a connection).
	 */
	public function content_posts() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		wp_send_json_success( $this->plugin->articles()->local_posts( $page ) );
	}

	/**
	 * Brand identity + voice note for the generate panel.
	 */
	public function brand_get() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		wp_send_json_success( $this->plugin->brand()->summary( isset( $_POST['fresh'] ) ) );
	}
}
