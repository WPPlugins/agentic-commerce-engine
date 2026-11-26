<?php
/**
 * Where generated product-page content is written, per content type (4.25.0).
 *
 * Q&A, highlights, IRL scenario cards, internal links and product specs each
 * used to have one home: this plugin's own meta (`_agencoen_qa` and the rest),
 * shown by its own block. A merchant can now send each one to:
 *
 *   block        This plugin's meta and block (the default). Placement decides
 *                where on the page the block sits, including a product tab.
 *   field        One of the store's own fields: an ACF field, post meta, or the
 *                WooCommerce short description, written in that field's format
 *                through Custom_Fields, whose ledger lets revert restore it.
 *   description  A section of its own inside the product description.
 *
 * The choice is made once on the platform and delivered on the heartbeat and
 * with every apply-queue drain. This class mirrors src/lib/content-destinations.ts;
 * tests/fixtures/content-destination-format.json pins both to the same output.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Content_Destinations
 */
class Content_Destinations {

	/** Option holding the routed types; a type not in it goes to the block. */
	const OPTION = 'agencoen_content_destinations';

	/** Content types whose destination the merchant chooses. */
	const TYPES = array( 'product-qa', 'highlights', 'irl-cards', 'internal-links', 'product-attributes' );

	/** Formats each type can be written in, on this platform. */
	const ACCEPTS = array(
		'highlights'         => array( 'multiline', 'html' ),
		'product-qa'         => array( 'multiline', 'html' ),
		'irl-cards'          => array( 'multiline', 'html' ),
		'internal-links'     => array( 'html' ),
		'product-attributes' => array( 'multiline', 'html' ),
	);

	/** Caps, generous for a real product page. Mirrors MAX_ITEMS. */
	const MAX_ITEMS = array(
		'highlights'         => 20,
		'product-qa'         => 20,
		'irl-cards'          => 10,
		'internal-links'     => 20,
		'product-attributes' => 50,
	);

	/**
	 * Between block elements. A block theme's excerpt block shows the short
	 * description with its tags stripped, and without whitespace every list
	 * item ran into the next. Mirrors BLOCK_JOIN.
	 */
	const BLOCK_JOIN = "\n";

	/** Longest section heading. Mirrors MAX_SECTION_HEADING. */
	const MAX_HEADING = 120;

	/**
	 * A section: one <div> carrying these attributes, with no <div> inside, so
	 * it is found and replaced without an HTML parser. Mirrors SECTION_PATTERN.
	 */
	const SECTION_PATTERN = '/(\r?\n)?<div\b[^>]*\bdata-rezolve-ai-section\s*=\s*(["\'])([a-z-]+)\2[^>]*>[\s\S]*?<\/div>(\r?\n)?/i';

	/** Whitespace as JavaScript's \s sees it. */
	const SPACE = '/[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u';

	/* --------------------------------------------------------------------- */
	/* Settings                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * The routed types, validated. A type missing here goes to the block.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		return self::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Where one type goes, or null for the block.
	 *
	 * @param string $type Content type.
	 * @return array|null
	 */
	public static function for_type( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : null;
	}

	/**
	 * Store what the platform sent. Always the complete answer: a type the
	 * platform left out is back on the block.
	 *
	 * @param mixed $raw Map of content type to destination.
	 * @return bool Whether anything changed.
	 */
	public static function save( $raw ) {
		$clean   = self::normalize( $raw );
		$current = get_option( self::OPTION, array() );
		if ( $clean === $current ) {
			return false;
		}
		update_option( self::OPTION, $clean );
		return true;
	}

	/**
	 * Only usable, routed entries. Two types never share a field.
	 *
	 * @param mixed $raw Stored or received map.
	 * @return array<string,array>
	 */
	public static function normalize( $raw ) {
		$out  = array();
		$used = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( self::TYPES as $type ) {
			if ( ! isset( $raw[ $type ] ) ) {
				continue;
			}
			$destination = self::read_destination( $type, $raw[ $type ] );
			if ( null === $destination || 'block' === $destination['kind'] ) {
				continue;
			}
			if ( 'field' === $destination['kind'] ) {
				$id = $destination['field']['id'];
				if ( isset( $used[ $id ] ) ) {
					continue;
				}
				$used[ $id ] = true;
			}
			$out[ $type ] = $destination;
		}
		return $out;
	}

	/**
	 * One destination, validated for its type. Mirrors readDestination for the
	 * sources this platform has.
	 *
	 * @param string $type Content type.
	 * @param mixed  $raw  Destination.
	 * @return array|null
	 */
	public static function read_destination( $type, $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['kind'] ) ) {
			return null;
		}
		if ( 'block' === $raw['kind'] ) {
			return array( 'kind' => 'block' );
		}
		$style = self::read_style( isset( $raw['style'] ) ? $raw['style'] : null );
		if ( 'description' === $raw['kind'] ) {
			$out = array(
				'kind'     => 'description',
				'position' => ( isset( $raw['position'] ) && 'start' === $raw['position'] ) ? 'start' : 'end',
				'heading'  => self::clean_heading( isset( $raw['heading'] ) ? $raw['heading'] : '' ),
			);
			if ( ! empty( $style ) ) {
				$out['style'] = $style;
			}
			return $out;
		}
		if ( 'field' !== $raw['kind'] || ! isset( $raw['field'] ) || ! is_array( $raw['field'] ) ) {
			return null;
		}
		$f         = $raw['field'];
		$str       = function ( $key ) use ( $f ) {
			return isset( $f[ $key ] ) && is_string( $f[ $key ] ) ? trim( $f[ $key ] ) : '';
		};
		$source    = $str( 'source' );
		$namespace = $str( 'namespace' );
		$key       = $str( 'key' );
		$field_t   = $str( 'type' );
		if ( ! self::field_allowed( $source, $namespace, $key ) || ! self::accepts( $type, self::format_for( $source, $field_t ) ) ) {
			return null;
		}
		$field = array(
			'id'        => $source . ':' . $namespace . '.' . $key,
			'source'    => $source,
			'namespace' => $namespace,
			'key'       => $key,
			'type'      => $field_t,
			'label'     => '' !== $str( 'label' ) ? substr( $str( 'label' ), 0, 256 ) : $key,
		);
		$acf_key = $str( 'acfKey' );
		if ( 'acf' === $source && preg_match( '/^field_[A-Za-z0-9]{1,64}$/', $acf_key ) ) {
			$field['acfKey'] = $acf_key;
		}
		$out = array(
			'kind'  => 'field',
			'field' => $field,
		);
		if ( ! empty( $style ) ) {
			$out['style'] = $style;
		}
		return $out;
	}

	/** Bullet characters a style may use. Mirrors DESTINATION_BULLETS. */
	const STYLE_BULLETS = array( "\u{2022}", '-', '*', "\u{00b7}", "\u{25aa}", "\u{2713}", "\u{2714}" );

	/**
	 * A stored style, keeping only values a renderer knows (field formats: the
	 * store's own bullet, question layout, heading level and bold tag).
	 * Mirrors readDestinationStyle.
	 *
	 * @param mixed $raw Stored style.
	 * @return array Empty when there is no usable style.
	 */
	public static function read_style( $raw ) {
		$style = array();
		if ( ! is_array( $raw ) ) {
			return $style;
		}
		if ( isset( $raw['bullet'] ) && is_string( $raw['bullet'] ) && in_array( $raw['bullet'], self::STYLE_BULLETS, true ) ) {
			$style['bullet'] = $raw['bullet'];
		}
		if ( isset( $raw['qa'] ) && in_array( $raw['qa'], array( 'bold_question', 'heading_question', 'q_a_lines' ), true ) ) {
			$style['qa'] = $raw['qa'];
		}
		if ( isset( $raw['headingLevel'] ) && in_array( $raw['headingLevel'], array( 2, 3, 4 ), true ) ) {
			$style['headingLevel'] = $raw['headingLevel'];
		}
		if ( isset( $raw['strongTag'] ) && in_array( $raw['strongTag'], array( 'strong', 'b' ), true ) ) {
			$style['strongTag'] = $raw['strongTag'];
		}
		return $style;
	}

	/**
	 * A heading as plain text on one line.
	 *
	 * @param mixed $value Heading.
	 * @return string
	 */
	private static function clean_heading( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = (string) preg_replace( '/[\x00-\x1f<>]/', ' ', $value );
		$value = self::collapse( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_HEADING ) : substr( $value, 0, self::MAX_HEADING );
	}

	/**
	 * A choice posted by the settings screen, as a destination. The screen
	 * sends `block`, `description:end`, `description:start` or `field:<id>`;
	 * a field is looked up among the candidates, never taken from the post.
	 *
	 * @param string $type       Content type.
	 * @param string $choice     Posted choice.
	 * @param string $heading    Posted section heading.
	 * @param array  $candidates From candidates().
	 * @return array|null
	 */
	public static function from_choice( $type, $choice, $heading, array $candidates ) {
		if ( 'block' === $choice ) {
			return array( 'kind' => 'block' );
		}
		if ( 0 === strpos( $choice, 'description:' ) ) {
			return self::read_destination(
				$type,
				array(
					'kind'     => 'description',
					'position' => substr( $choice, strlen( 'description:' ) ),
					'heading'  => $heading,
				)
			);
		}
		if ( 0 === strpos( $choice, 'field:' ) ) {
			$id = substr( $choice, strlen( 'field:' ) );
			foreach ( $candidates as $field ) {
				if ( $field['id'] === $id ) {
					return self::read_destination(
						$type,
						array(
							'kind'  => 'field',
							'field' => $field,
						)
					);
				}
			}
		}
		return null;
	}

	/**
	 * Every field on this store a destination could be, deduplicated by id:
	 * ACF text areas and editors defined for products (even empty ones, with
	 * the ACF key they need), the WooCommerce short description, the fields
	 * the platform has seen on synced products, and whatever is chosen now.
	 *
	 * @param Plugin $plugin Orchestrator, for the platform call.
	 * @return array[] Fields: id, source, namespace, key, type, label, acfKey?.
	 */
	public static function candidates( $plugin ) {
		$fields = array(
			array(
				'source'    => 'wc_core',
				'namespace' => 'woocommerce',
				'key'       => 'short_description',
				'type'      => 'wc:html',
				'label'     => __( 'Short description', 'agentic-commerce-engine' ),
			),
		);

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( array( 'post_type' => 'product' ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $f ) {
					if ( ! is_array( $f ) || empty( $f['name'] ) || ! in_array( isset( $f['type'] ) ? $f['type'] : '', array( 'textarea', 'wysiwyg' ), true ) ) {
						continue;
					}
					$fields[] = array(
						'source'    => 'acf',
						'namespace' => 'acf',
						'key'       => (string) $f['name'],
						'type'      => 'acf:' . $f['type'],
						'label'     => isset( $f['label'] ) && '' !== $f['label'] ? (string) $f['label'] : (string) $f['name'],
						'acfKey'    => isset( $f['key'] ) ? (string) $f['key'] : '',
					);
				}
			}
		}

		$settings = $plugin->settings();
		if ( $settings->is_connected() ) {
			$remote = get_transient( 'agencoen_destination_candidates' );
			if ( ! is_array( $remote ) ) {
				$remote   = array();
				$response = $plugin->api()->content_placement( $settings->get_store_id() );
				if ( ! is_wp_error( $response ) && isset( $response['candidates'] ) && is_array( $response['candidates'] ) ) {
					foreach ( $response['candidates'] as $list ) {
						foreach ( (array) $list as $field ) {
							if ( is_array( $field ) ) {
								$remote[] = $field;
							}
						}
					}
				}
				// Short: a field added to products shows up within minutes.
				set_transient( 'agencoen_destination_candidates', $remote, 10 * MINUTE_IN_SECONDS );
			}
			$fields = array_merge( $fields, $remote );
		}

		foreach ( self::all() as $destination ) {
			if ( 'field' === $destination['kind'] ) {
				$fields[] = $destination['field'];
			}
		}

		$out = array();
		foreach ( $fields as $f ) {
			$source    = isset( $f['source'] ) ? (string) $f['source'] : '';
			$namespace = isset( $f['namespace'] ) ? (string) $f['namespace'] : '';
			$key       = isset( $f['key'] ) ? (string) $f['key'] : '';
			$id        = $source . ':' . $namespace . '.' . $key;
			if ( isset( $out[ $id ] ) || ! self::field_allowed( $source, $namespace, $key ) || null === self::format_for( $source, isset( $f['type'] ) ? (string) $f['type'] : '' ) ) {
				continue;
			}
			$field = array(
				'id'        => $id,
				'source'    => $source,
				'namespace' => $namespace,
				'key'       => $key,
				'type'      => (string) $f['type'],
				'label'     => isset( $f['label'] ) && '' !== (string) $f['label'] ? (string) $f['label'] : $key,
			);
			if ( ! empty( $f['acfKey'] ) ) {
				$field['acfKey'] = (string) $f['acfKey'];
			}
			$out[ $id ] = $field;
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);
		return array_values( $out );
	}

	/**
	 * The candidates that can hold $type.
	 *
	 * @param string $type       Content type.
	 * @param array  $candidates From candidates().
	 * @return array[]
	 */
	public static function candidates_for( $type, array $candidates ) {
		return array_values(
			array_filter(
				$candidates,
				function ( $f ) use ( $type ) {
					return self::accepts( $type, self::format_for( $f['source'], $f['type'] ) );
				}
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Which fields can take which content                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * The format a field is written in, or null. Mirrors destinationFormatFor.
	 *
	 * @param string $source Source.
	 * @param string $type   Field type.
	 * @return string|null
	 */
	public static function format_for( $source, $type ) {
		if ( 'acf' === $source ) {
			if ( 'acf:textarea' === $type ) {
				return 'multiline';
			}
			return 'acf:wysiwyg' === $type ? 'html' : null;
		}
		if ( 'wp_meta' === $source ) {
			if ( 'wp:html' === $type ) {
				return 'html';
			}
			return 'wp:string' === $type ? 'multiline' : null;
		}
		if ( 'wc_core' === $source ) {
			return 'wc:html' === $type ? 'html' : null;
		}
		return null;
	}

	/**
	 * @param string      $type   Content type.
	 * @param string|null $format Format.
	 * @return bool
	 */
	public static function accepts( $type, $format ) {
		return null !== $format && isset( self::ACCEPTS[ $type ] ) && in_array( $format, self::ACCEPTS[ $type ], true );
	}

	/**
	 * Whether a field's address may be written at all. Mirrors
	 * isDestinationFieldAllowed; Custom_Fields applies its own rules again.
	 *
	 * @param string $source    Source.
	 * @param string $namespace Namespace.
	 * @param string $key       Key.
	 * @return bool
	 */
	public static function field_allowed( $source, $namespace, $key ) {
		if ( Custom_Fields::is_private_key( $key ) ) {
			return false;
		}
		$meta_key = (bool) preg_match( '/^[A-Za-z0-9_-]{1,190}$/', $key ) && 0 !== strpos( $key, '_' );
		if ( 'acf' === $source ) {
			return 'acf' === $namespace && $meta_key;
		}
		if ( 'wp_meta' === $source ) {
			return 'meta' === $namespace && $meta_key && ! preg_match( '/^agencoen/i', $key ) && ! Custom_Fields::skipped_meta_key( $key );
		}
		if ( 'wc_core' === $source ) {
			return 'woocommerce' === $namespace && 'short_description' === $key;
		}
		return false;
	}

	/* --------------------------------------------------------------------- */
	/* Reading generated content                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Whitespace runs to one space, trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function collapse( $text ) {
		return trim( (string) preg_replace( self::SPACE, ' ', $text ), ' ' );
	}

	/**
	 * One line of text. Numbers are text too. Mirrors `line`.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function line( $value ) {
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			return (string) $value;
		}
		return is_string( $value ) ? self::collapse( $value ) : '';
	}

	/**
	 * Whether an array is a JSON list. `array_is_list` is PHP 8.1; this plugin
	 * supports 8.0.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		$i = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $i++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The first list under any of $keys, or the value when it is one.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $keys  Keys to look under.
	 * @return array
	 */
	private static function list_in( $value, array $keys ) {
		if ( is_array( $value ) && self::is_list( $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		foreach ( $keys as $key ) {
			if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) && self::is_list( $value[ $key ] ) ) {
				return $value[ $key ];
			}
		}
		return array();
	}

	/**
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function record( $value ) {
		return ( is_array( $value ) && ! self::is_list( $value ) ) ? $value : array();
	}

	/**
	 * Only links a browser can safely follow. Mirrors isSafeLinkUrl.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function safe_link( $url ) {
		return (bool) preg_match( '#^https?://[^\s"\'<>]+$#i', $url ) || (bool) preg_match( '#^/(?!/)[^\s"\'<>]*$#', $url );
	}

	/**
	 * Generated content of $type, in any stored shape, as clean items. Null
	 * when nothing usable is left. Mirrors readDestinationItems.
	 *
	 * @param string $type  Content type.
	 * @param mixed  $value Value, or JSON.
	 * @return array|null
	 */
	public static function items( $type, $value ) {
		if ( is_string( $value ) ) {
			$t = trim( $value );
			if ( '' !== $t && ( '[' === $t[0] || '{' === $t[0] ) ) {
				$decoded = json_decode( $t, true );
				if ( null !== $decoded ) {
					$value = $decoded;
				}
			}
		}
		$cap   = isset( self::MAX_ITEMS[ $type ] ) ? self::MAX_ITEMS[ $type ] : 0;
		$items = array();
		switch ( $type ) {
			case 'highlights':
				foreach ( self::list_in( $value, array( 'highlights', 'bullets', 'items' ) ) as $entry ) {
					$line = self::line( $entry );
					if ( '' !== $line && ! in_array( $line, $items, true ) ) {
						$items[] = $line;
					}
				}
				break;
			case 'product-qa':
				foreach ( self::list_in( $value, array( 'faqs', 'qa', 'items' ) ) as $entry ) {
					$r = self::record( $entry );
					$q = self::line( isset( $r['question'] ) ? $r['question'] : '' );
					$a = self::line( isset( $r['answer'] ) ? $r['answer'] : '' );
					if ( '' !== $q && '' !== $a ) {
						$items[] = array(
							'question' => $q,
							'answer'   => $a,
						);
					}
				}
				break;
			case 'irl-cards':
				$items = self::irl_cards( $value );
				break;
			case 'internal-links':
				foreach ( self::list_in( $value, array( 'links', 'content', 'items' ) ) as $entry ) {
					$r   = self::record( $entry );
					$url = self::line( isset( $r['targetUrl'] ) ? $r['targetUrl'] : ( isset( $r['url'] ) ? $r['url'] : '' ) );
					if ( '' === $url || ! self::safe_link( $url ) ) {
						continue;
					}
					$text = self::line( isset( $r['anchorText'] ) ? $r['anchorText'] : ( isset( $r['text'] ) ? $r['text'] : '' ) );
					if ( '' === $text ) {
						$text = self::line( isset( $r['targetTitle'] ) ? $r['targetTitle'] : '' );
					}
					$items[] = array(
						'url'  => $url,
						'text' => '' !== $text ? $text : $url,
					);
				}
				break;
			case 'product-attributes':
				foreach ( self::list_in( $value, array( 'attributes', 'specs', 'items' ) ) as $entry ) {
					$r = self::record( $entry );
					if ( isset( $r['values'] ) && is_array( $r['values'] ) ) {
						$v = implode( ', ', array_values( array_filter( array_map( array( __CLASS__, 'line' ), $r['values'] ), 'strlen' ) ) );
					} else {
						$v = self::line( isset( $r['value'] ) ? $r['value'] : '' );
					}
					$n = self::line( isset( $r['name'] ) ? $r['name'] : '' );
					if ( '' !== $n && '' !== $v ) {
						$items[] = array(
							'name'  => $n,
							'value' => $v,
						);
					}
				}
				break;
			default:
				return null;
		}
		$items = array_slice( $items, 0, $cap );
		return empty( $items ) ? null : $items;
	}

	/**
	 * The merchant's selected card when the payload names one, else every card.
	 *
	 * @param mixed $value Payload.
	 * @return array
	 */
	private static function irl_cards( $value ) {
		$r      = self::record( $value );
		$nested = self::record( isset( $r['irlCards'] ) ? $r['irlCards'] : null );
		$cards  = self::list_in( $value, array( 'cards', 'enhanced', 'scenarios' ) );
		if ( empty( $cards ) ) {
			$cards = self::list_in( $nested, array( 'enhanced', 'cards', 'scenarios' ) );
		}
		$items = array();
		foreach ( $cards as $card ) {
			$c        = self::record( $card );
			$raw      = isset( $c['keyFeatures'] ) && is_array( $c['keyFeatures'] ) ? $c['keyFeatures'] : ( isset( $c['features'] ) && is_array( $c['features'] ) ? $c['features'] : array() );
			$features = array_values( array_filter( array_map( array( __CLASS__, 'line' ), $raw ), 'strlen' ) );
			$title    = self::line( isset( $c['title'] ) ? $c['title'] : '' );
			$desc     = self::line( isset( $c['description'] ) ? $c['description'] : '' );
			if ( '' !== $title || '' !== $desc ) {
				$items[] = array(
					'title'       => $title,
					'description' => $desc,
					'features'    => $features,
				);
			}
		}
		foreach ( array( array( $r, 'selectedIndex' ), array( $r, 'selectedCardIndex' ), array( $nested, 'selectedCardIndex' ), array( $nested, 'selectedIndex' ) ) as $pair ) {
			if ( isset( $pair[0][ $pair[1] ] ) && is_int( $pair[0][ $pair[1] ] ) ) {
				$selected = $pair[0][ $pair[1] ];
				return ( $selected >= 0 && $selected < count( $items ) ) ? array( $items[ $selected ] ) : $items;
			}
		}
		return $items;
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * HTML-escaped text. Mirrors escapeHtml.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
	}

	/**
	 * @param string[] $items Escaped items.
	 * @return string
	 */
	private static function ul( array $items ) {
		$lis = array();
		foreach ( $items as $item ) {
			$lis[] = '<li>' . $item . '</li>';
		}
		return '<ul>' . self::BLOCK_JOIN . implode( self::BLOCK_JOIN, $lis ) . self::BLOCK_JOIN . '</ul>';
	}

	/**
	 * The value for a field of $format, or null. Mirrors renderForField for
	 * the formats this platform has.
	 *
	 * @param string $type   Content type.
	 * @param array  $items  From items().
	 * @param string $format multiline|html.
	 * @param array  $style  From read_style(); empty for Rezolve Ai's layout.
	 * @return string|null
	 */
	public static function render_field( $type, array $items, $format, array $style = array() ) {
		if ( ! self::accepts( $type, $format ) ) {
			return null;
		}
		return 'html' === $format ? self::html( $type, $items, false, $style ) : self::multiline( $type, $items, $style );
	}

	/**
	 * @param string $type  Content type.
	 * @param array  $items Items.
	 * @return string|null
	 */
	private static function multiline( $type, array $items, array $style = array() ) {
		$bullet = ( isset( $style['bullet'] ) ? $style['bullet'] : "\u{2022}" ) . ' ';
		$parts  = array();
		switch ( $type ) {
			case 'highlights':
				foreach ( $items as $h ) {
					$parts[] = $bullet . $h;
				}
				return implode( "\n", $parts );
			case 'product-qa':
				foreach ( $items as $q ) {
					$parts[] = ( isset( $style['qa'] ) && 'q_a_lines' === $style['qa'] )
						? 'Q: ' . $q['question'] . "\n" . 'A: ' . $q['answer']
						: $q['question'] . "\n" . $q['answer'];
				}
				return implode( "\n\n", $parts );
			case 'irl-cards':
				foreach ( $items as $c ) {
					$lines = array_values( array_filter( array( $c['title'], $c['description'] ), 'strlen' ) );
					foreach ( $c['features'] as $f ) {
						$lines[] = $bullet . $f;
					}
					$parts[] = implode( "\n", $lines );
				}
				return implode( "\n\n", $parts );
			case 'product-attributes':
				foreach ( $items as $s ) {
					$parts[] = $s['name'] . ': ' . $s['value'];
				}
				return implode( "\n", $parts );
		}
		return null;
	}

	/**
	 * In a section questions and card titles are headings; in a field, bold
	 * paragraphs. Mirrors htmlFor.
	 *
	 * @param string $type       Content type.
	 * @param array  $items      Items.
	 * @param bool   $in_section Inside a description section.
	 * @param array  $style      From read_style().
	 * @return string
	 */
	private static function html( $type, array $items, $in_section, array $style = array() ) {
		$tag        = isset( $style['strongTag'] ) ? $style['strongTag'] : 'strong';
		$level      = isset( $style['headingLevel'] ) ? (int) $style['headingLevel'] : 3;
		$as_heading = isset( $style['qa'] ) ? 'heading_question' === $style['qa'] : $in_section;
		$strong     = function ( $text ) use ( $as_heading, $tag, $level ) {
			return $as_heading
				? '<h' . $level . '>' . self::esc( $text ) . '</h' . $level . '>'
				: '<p><' . $tag . '>' . self::esc( $text ) . '</' . $tag . '></p>';
		};
		$parts = array();
		switch ( $type ) {
			case 'highlights':
				return self::ul( array_map( array( __CLASS__, 'esc' ), $items ) );
			case 'product-qa':
				foreach ( $items as $q ) {
					$parts[] = ( isset( $style['qa'] ) && 'q_a_lines' === $style['qa'] )
						? '<p>Q: ' . self::esc( $q['question'] ) . '</p>' . self::BLOCK_JOIN . '<p>A: ' . self::esc( $q['answer'] ) . '</p>'
						: $strong( $q['question'] ) . self::BLOCK_JOIN . '<p>' . self::esc( $q['answer'] ) . '</p>';
				}
				return implode( self::BLOCK_JOIN, $parts );
			case 'irl-cards':
				foreach ( $items as $c ) {
					$parts[] = implode(
						self::BLOCK_JOIN,
						array_filter(
							array(
								'' !== $c['title'] ? $strong( $c['title'] ) : '',
								'' !== $c['description'] ? '<p>' . self::esc( $c['description'] ) . '</p>' : '',
								! empty( $c['features'] ) ? self::ul( array_map( array( __CLASS__, 'esc' ), $c['features'] ) ) : '',
							),
							'strlen'
						)
					);
				}
				return implode( self::BLOCK_JOIN, $parts );
			case 'internal-links':
				$links = array();
				foreach ( $items as $l ) {
					$links[] = '<a href="' . self::esc( $l['url'] ) . '">' . self::esc( $l['text'] ) . '</a>';
				}
				return self::ul( $links );
			case 'product-attributes':
				$specs = array();
				foreach ( $items as $s ) {
					$specs[] = '<' . $tag . '>' . self::esc( $s['name'] ) . ':</' . $tag . '> ' . self::esc( $s['value'] );
				}
				return self::ul( $specs );
		}
		return '';
	}

	/**
	 * A whole description section for $type, wrapper included. Mirrors
	 * renderDescriptionSection.
	 *
	 * @param string $type     Content type.
	 * @param array  $items    Items.
	 * @param string $position start|end.
	 * @param string $heading  Optional heading.
	 * @param array  $style    From read_style().
	 * @return string
	 */
	public static function render_section( $type, array $items, $position, $heading = '', array $style = array() ) {
		$position = 'start' === $position ? 'start' : 'end';
		$heading  = self::clean_heading( $heading );
		return '<div class="rezolve-ai-section rezolve-ai-section--' . $type . '" data-rezolve-ai-section="' . $type . '" data-rezolve-ai-position="' . $position . '">'
			. self::BLOCK_JOIN
			. ( '' !== $heading ? '<h2>' . self::esc( $heading ) . '</h2>' . self::BLOCK_JOIN : '' )
			. self::html( $type, $items, true, $style )
			. self::BLOCK_JOIN
			. '</div>';
	}

	/* --------------------------------------------------------------------- */
	/* Description sections                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * @param string $open_tag A section's opening tag.
	 * @return string start|end
	 */
	private static function position_of( $open_tag ) {
		return preg_match( '/\bdata-rezolve-ai-position\s*=\s*["\']start["\']/i', $open_tag ) ? 'start' : 'end';
	}

	/**
	 * Every section in a description, in order.
	 *
	 * @param string $html Description.
	 * @return array[] { type, position, html }
	 */
	public static function sections( $html ) {
		$out = array();
		if ( ! is_string( $html ) || false === strpos( $html, 'data-rezolve-ai-section' ) ) {
			return $out;
		}
		if ( preg_match_all( self::SECTION_PATTERN, $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$whole = (string) preg_replace( '/\r?\n$/', '', (string) preg_replace( '/^\r?\n/', '', $m[0] ) );
				$out[] = array(
					'type'     => $m[3],
					'position' => self::position_of( substr( $whole, 0, strpos( $whole, '>' ) + 1 ) ),
					'html'     => $whole,
				);
			}
		}
		return $out;
	}

	/**
	 * Whether a description holds any of our sections.
	 *
	 * @param mixed $html Description.
	 * @return bool
	 */
	public static function has_sections( $html ) {
		return ! empty( self::sections( $html ) );
	}

	/**
	 * The description without the sections $drop matches. Mirrors removeSections.
	 *
	 * @param string   $html Description.
	 * @param callable $drop fn( string $type ): bool.
	 * @return string
	 */
	private static function remove_where( $html, callable $drop ) {
		return (string) preg_replace_callback(
			self::SECTION_PATTERN,
			function ( $m ) use ( $drop ) {
				if ( ! $drop( $m[3] ) ) {
					return $m[0];
				}
				return ( ! empty( $m[1] ) && ! empty( $m[4] ) ) ? "\n" : '';
			},
			(string) $html
		);
	}

	/**
	 * @param string $html Description.
	 * @param string $type Content type.
	 * @return string
	 */
	public static function remove_section( $html, $type ) {
		return self::remove_where(
			$html,
			function ( $t ) use ( $type ) {
				return $t === $type;
			}
		);
	}

	/**
	 * What the merchant wrote: every section taken out.
	 *
	 * @param string $html Description.
	 * @return string
	 */
	public static function strip_sections( $html ) {
		return self::remove_where(
			$html,
			function () {
				return true;
			}
		);
	}

	/**
	 * $section put into $html, replacing any earlier one of its type.
	 *
	 * @param string $html    Description.
	 * @param string $section From render_section().
	 * @return string
	 */
	public static function upsert_section( $html, $section ) {
		if ( ! preg_match( '/data-rezolve-ai-section\s*=\s*["\']([a-z-]+)["\']/i', $section, $open ) ) {
			return (string) $html;
		}
		$position = self::position_of( substr( $section, 0, strpos( $section, '>' ) + 1 ) );
		$base     = self::remove_section( (string) $html, $open[1] );
		if ( '' === self::collapse( $base ) ) {
			return $section;
		}
		return 'start' === $position ? $section . "\n" . $base : $base . "\n" . $section;
	}

	/**
	 * Sections live in $from carried into $into, for a write that replaces the
	 * whole description. A section $into has is kept as it has it.
	 *
	 * @param string $from Live description.
	 * @param string $into New description.
	 * @return string
	 */
	public static function carry_sections( $from, $into ) {
		$out     = (string) $into;
		$present = array();
		foreach ( self::sections( $out ) as $section ) {
			$present[ $section['type'] ] = true;
		}
		foreach ( self::sections( (string) $from ) as $section ) {
			if ( ! isset( $present[ $section['type'] ] ) ) {
				$out = self::upsert_section( $out, $section['html'] );
			}
		}
		return $out;
	}

	/**
	 * A new description for a product, with the sections it has live carried
	 * over and any section the new copy tried to bring along dropped. Every
	 * description write goes through this.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $value   New description.
	 * @return string
	 */
	public static function description_for( $product, $value ) {
		return self::carry_sections( (string) $product->get_description( 'edit' ), self::strip_sections( (string) $value ) );
	}

	/* --------------------------------------------------------------------- */
	/* Routing                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Write $data where the merchant chose for $type.
	 *
	 * @param Plugin $plugin  Orchestrator, for the pre-apply backup.
	 * @param int    $post_id Product id.
	 * @param string $type    Content type.
	 * @param mixed  $data    Generated content, any stored shape or JSON.
	 * @return true|string|null Null: the block, so the caller writes its own
	 *                          meta as before. True: written. A string: why not.
	 */
	public static function route( $plugin, $post_id, $type, $data ) {
		$destination = self::for_type( $type );
		if ( null === $destination ) {
			return null;
		}
		$items = self::items( $type, $data );
		if ( null === $items ) {
			return 'nothing usable to publish';
		}

		if ( 'field' === $destination['kind'] ) {
			$field  = $destination['field'];
			$format = self::format_for( $field['source'], $field['type'] );
			$value  = null === $format ? null : self::render_field( $type, $items, $format, isset( $destination['style'] ) ? $destination['style'] : array() );
			if ( null === $value ) {
				return $field['label'] . ': ' . __( 'This field cannot hold this content. Choose another field in Settings.', 'agentic-commerce-engine' );
			}
			$result = Custom_Fields::write_rewrites(
				$post_id,
				array(
					array(
						'ref'        => $field['id'],
						'source'     => $field['source'],
						'namespace'  => $field['namespace'],
						'key'        => $field['key'],
						'type'       => $field['type'],
						'format'     => $format,
						'label'      => $field['label'],
						'value'      => $value,
						// No hash: the merchant chose to have this field replaced.
						'beforeHash' => '',
						'acfKey'     => isset( $field['acfKey'] ) ? $field['acfKey'] : '',
					),
				)
			);
			return $result['written'] > 0 ? true : implode( ' ', $result['skipped'] );
		}

		$product = wc_get_product( (int) $post_id );
		if ( ! $product ) {
			return 'product not loadable';
		}
		// The original description is what revert restores; take it before the
		// first change, as every other apply does.
		$plugin->apply()->backup_once( $product );
		$section = self::render_section( $type, $items, $destination['position'], $destination['heading'], isset( $destination['style'] ) ? $destination['style'] : array() );
		$product->set_description( wp_kses_post( self::upsert_section( (string) $product->get_description( 'edit' ), $section ) ) );
		$product->save();
		return true;
	}
}
