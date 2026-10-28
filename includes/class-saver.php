<?php
/**
 * Salvataggio: riceve le modifiche dal frontend e le scrive nel post_content del blocco giusto.
 * WordPress crea una revisione a ogni salvataggio, quindi ogni modifica si può annullare dal backend.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_Saver {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		// Anteprima di uno shortcode (es. un modulo di contatto), per vedere il risultato prima di salvare.
		register_rest_route(
			'starter-live/v1',
			'/shortcode',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'preview_shortcode' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'starter-live/v1',
			'/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				// Serve almeno un permesso di modifica; poi ogni singola modifica è controllata sulla pagina / categoria / prodotto a cui si riferisce.
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' ) || current_user_can( 'manage_categories' );
				},
			)
		);
	}

	public static function preview_shortcode( WP_REST_Request $request ) {
		$code = self::clean_shortcode( $request->get_param( 'code' ) );

		if ( is_wp_error( $code ) ) {
			return new WP_Error( $code->get_error_code(), $code->get_error_message(), array( 'status' => 400 ) );
		}

		return array( 'html' => '' === $code ? '' : do_shortcode( $code ) );
	}

	/** Uno shortcode valido: inizia con [nome], il nome esiste (o è vuoto, per toglierlo). */
	private static function clean_shortcode( $value ) {
		$code = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $code ) {
			return '';
		}

		// Deve essere UN solo shortcode, per intero: niente altri shortcode o testo accodati.
		if ( ! preg_match( '/^\[([A-Za-z0-9_-]+)[\s\]\/]/', $code, $m ) || ! shortcode_exists( $m[1] ) || ! preg_match( '/^' . get_shortcode_regex( array( $m[1] ) ) . '$/s', $code ) ) {
			return new WP_Error( 'starter_live_shortcode', __( 'Shortcode non valido: scrivi uno shortcode solo, tra parentesi quadre, per esempio [contact-form-7 id="…"].', 'starter-live' ) );
		}

		return $code;
	}

	public static function save( WP_REST_Request $request ) {
		$changes = $request->get_param( 'changes' );

		if ( ! is_array( $changes ) || ! $changes ) {
			return new WP_Error( 'starter_live_empty', __( 'Nessuna modifica da salvare.', 'starter-live' ), array( 'status' => 400 ) );
		}

		$by_post = array();

		$saved  = 0;
		$errors = array();
		$stale  = array();

		// Una pagina cambiata da qualcun altro dopo l'apertura non si sovrascrive: si avvisa e non si salva niente per quella pagina.
		foreach ( $changes as $change ) {
			$pid = is_array( $change ) ? (int) ( $change['post'] ?? 0 ) : 0;
			$rev = is_array( $change ) ? (string) ( $change['rev'] ?? '' ) : '';

			// Si guarda la data solo per pagine che l'utente può modificare: per le altre non si rivela niente (nemmeno il titolo), ci pensa il rifiuto più sotto.
			if ( $pid && '' !== $rev && ! isset( $stale[ $pid ] ) && current_user_can( 'edit_post', $pid ) && $rev !== (string) get_post_field( 'post_modified_gmt', $pid ) ) {
				$stale[ $pid ] = true;
				$errors[]      = sprintf( /* translators: %s: titolo della pagina */ __( '«%s» è stata modificata da qualcun altro mentre la modificavi: ricarica la pagina e rifai le modifiche.', 'starter-live' ), get_the_title( $pid ) );
			}
		}

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			if ( ! empty( $change['post'] ) && isset( $stale[ (int) $change['post'] ] ) ) {
				continue;
			}

			// Nome, descrizione ed extra di una categoria/tag/termine.
			if ( in_array( $change['src'] ?? '', array( 'term', 'termmeta' ), true ) ) {
				$result = self::apply_term( $change );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$saved++;
				}

				continue;
			}

			if ( empty( $change['post'] ) ) {
				continue;
			}

			// Immagine in evidenza di una pagina o di un articolo.
			if ( 'post_thumbnail' === ( $change['src'] ?? '' ) ) {
				$att = self::attachment_id( is_array( $change['value'] ?? null ) ? ( $change['value']['id'] ?? 0 ) : ( $change['value'] ?? 0 ) );

				if ( is_wp_error( $att ) ) {
					$errors[] = $att->get_error_message();
				} elseif ( ! current_user_can( 'edit_post', (int) $change['post'] ) ) {
					$errors[] = __( 'Non puoi modificare questa pagina.', 'starter-live' );
				} else {
					set_post_thumbnail( (int) $change['post'], $att );
					$saved++;
				}

				continue;
			}

			// Immagini di un prodotto WooCommerce (principale + galleria).
			if ( 'product_images' === ( $change['src'] ?? '' ) ) {
				$result = self::apply_product_images( (int) $change['post'], $change );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$saved++;
				}

				continue;
			}

			// Campi di una scheda che non è fatta di blocchi (es. un immobile).
			if ( 'metapost' === ( $change['src'] ?? '' ) ) {
				$result = self::apply_meta_post( (int) $change['post'], $change );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$saved++;
				}

				continue;
			}

			// Titolo, occhiello… che non sono blocchi: si salvano direttamente sulla pagina.
			if ( in_array( $change['src'] ?? '', array( 'meta', 'post' ), true ) ) {
				$result = self::apply_direct( (int) $change['post'], $change );

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					$saved++;
				}

				continue;
			}

			$by_post[ (int) $change['post'] ][] = $change;
		}

		foreach ( $by_post as $post_id => $list ) {
			$post = get_post( $post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$errors[] = sprintf( /* translators: %d: ID pagina */ __( 'Non puoi modificare il contenuto %d.', 'starter-live' ), $post_id );
				continue;
			}

			$blocks  = parse_blocks( $post->post_content );
			$applied = 0;

			foreach ( $list as $change ) {
				$path   = array_map( 'intval', explode( '.', (string) ( $change['path'] ?? '' ) ) );
				$result = self::update_at(
					$blocks,
					$path,
					function ( array $block ) use ( $change ) {
						return self::apply( $block, $change );
					}
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
					continue;
				}

				$blocks = $result;
				$applied++;
			}

			if ( $applied ) {
				$update = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => serialize_blocks( $blocks ),
						)
					),
					true
				);

				if ( is_wp_error( $update ) ) {
					$errors[] = $update->get_error_message();
					continue;
				}

				$saved += $applied;
			}
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}

	/**
	 * Un campo di una scheda che non è fatta di blocchi (es. un immobile): stessi controlli e stessa pulizia dei campi dei blocchi
	 * (apply_field), poi si scrive il valore nel meta del contenuto. Solo per i tipi di contenuto dichiarati dal tema e solo per campi veri.
	 */
	private static function apply_meta_post( $post_id, array $change ) {
		$post = get_post( $post_id );

		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) || ! in_array( $post->post_type, Starter_Live_Frontend::meta_post_types(), true ) ) {
			return new WP_Error( 'starter_live_cap', __( 'Non puoi modificare questa scheda.', 'starter-live' ) );
		}

		$name  = (string) ( $change['field'] ?? '' );
		$stale = new WP_Error( 'starter_live_field', __( 'Questo campo non si può modificare dal frontend.', 'starter-live' ) );

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) || ! metadata_exists( 'post', $post_id, $name ) ) {
			return $stale;
		}

		$ref   = get_post_meta( $post_id, '_' . $name, true );
		$block = array(
			'attrs' => array(
				'data' => array(
					$name         => get_post_meta( $post_id, $name, true ),
					'_' . $name   => $ref,
				),
			),
		);

		$result = self::apply_field( $block, $name, null, $change['value'] ?? '', $stale );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post_id, $name, $result['attrs']['data'][ $name ] );
		wp_update_post( array( 'ID' => $post_id ) ); // aggiorna la data di modifica (serve al controllo "pagina cambiata da qualcun altro")

		return true;
	}

	/** Immagini di un prodotto: la prima diventa l'immagine principale, le altre la galleria. */
	private static function apply_product_images( $post_id, array $change ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'starter_live_cap', __( 'Non puoi modificare questo prodotto.', 'starter-live' ) );
		}

		$product = wc_get_product( $post_id );
		$list    = isset( $change['value'] ) && is_array( $change['value'] ) ? array_values( $change['value'] ) : array();
		$ids     = array();

		if ( ! $product || ! $list ) {
			return new WP_Error( 'starter_live_product', __( "Il prodotto deve avere almeno un'immagine.", 'starter-live' ) );
		}

		foreach ( $list as $item ) {
			$att_id = self::attachment_id( $item );

			if ( is_wp_error( $att_id ) ) {
				return $att_id;
			}

			if ( ! in_array( $att_id, $ids, true ) ) {
				$ids[] = $att_id;
			}
		}

		$product->set_image_id( $ids[0] );
		$product->set_gallery_image_ids( array_slice( $ids, 1 ) );
		$product->save();

		return true;
	}

	/** Salvataggio di nome / descrizione / campi extra di un termine (categoria, tag, categoria prodotto…). */
	private static function apply_term( array $change ) {
		$term_id = absint( $change['term'] ?? 0 );
		$term    = $term_id ? get_term( $term_id, (string) ( $change['tax'] ?? '' ) ) : null;

		if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new WP_Error( 'starter_live_cap', __( 'Non puoi modificare questo elemento.', 'starter-live' ) );
		}

		$key = (string) ( $change['key'] ?? '' );

		if ( 'term' === $change['src'] ) {
			if ( 'name' === $key ) {
				$value = sanitize_text_field( (string) ( $change['value'] ?? '' ) );

				if ( '' === $value ) {
					return new WP_Error( 'starter_live_name', __( 'Il nome non può essere vuoto.', 'starter-live' ) );
				}

				$update = wp_update_term( $term->term_id, $term->taxonomy, array( 'name' => $value ) );
			} elseif ( 'description' === $key ) {
				$update = wp_update_term( $term->term_id, $term->taxonomy, array( 'description' => wp_kses_post( (string) ( $change['value'] ?? '' ) ) ) );
			} else {
				return new WP_Error( 'starter_live_key', __( 'Campo non modificabile.', 'starter-live' ) );
			}

			return is_wp_error( $update ) ? $update : true;
		}

		$allowed = (array) apply_filters( 'starter_live_meta_keys', array( 'starter_eyebrow' ) );

		if ( ! in_array( $key, $allowed, true ) ) {
			return new WP_Error( 'starter_live_key', __( 'Campo non modificabile.', 'starter-live' ) );
		}

		update_term_meta( $term->term_id, $key, sanitize_text_field( (string) ( $change['value'] ?? '' ) ) );

		return true;
	}

	/** Salvataggio di titolo della pagina e di campi extra (meta) approvati dal tema. */
	private static function apply_direct( $post_id, array $change ) {
		if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'starter_live_cap', __( 'Non puoi modificare questa pagina.', 'starter-live' ) );
		}

		$key   = (string) ( $change['key'] ?? '' );
		$value = sanitize_text_field( (string) ( $change['value'] ?? '' ) );

		if ( 'post' === $change['src'] && 'post_intro' === $key ) {
			// Testo introduttivo di una pagina (Blog/Shop) che non ne ha ancora: si crea un paragrafo. Se ha già un contenuto non si tocca.
			$post = get_post( $post_id );

			if ( '' !== trim( wp_strip_all_tags( $post->post_content ) ) ) {
				return new WP_Error( 'starter_live_intro', __( 'Questa pagina ha già un testo: modificalo direttamente.', 'starter-live' ) );
			}

			$html = self::clean_rich( (string) ( $change['value'] ?? '' ), true );

			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				return true;
			}

			$update = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->" ) ), true );

			return is_wp_error( $update ) ? $update : true;
		}

		if ( 'post' === $change['src'] ) {
			if ( ! in_array( $key, array( 'post_title', 'post_excerpt', 'post_content' ), true ) ) {
				return new WP_Error( 'starter_live_key', __( 'Campo non modificabile.', 'starter-live' ) );
			}

			if ( 'post_content' === $key && has_blocks( (string) get_post_field( 'post_content', $post_id ) ) ) {
				return new WP_Error( 'starter_live_content', __( 'Questo testo è fatto con dei blocchi: modifica direttamente i blocchi.', 'starter-live' ) );
			}

			// Il titolo è testo semplice; descrizione breve e lunga ammettono la formattazione di un normale articolo (come nell'editor).
			$new    = 'post_title' === $key ? $value : wp_kses_post( (string) ( $change['value'] ?? '' ) );
			$update = wp_update_post( wp_slash( array( 'ID' => $post_id, $key => $new ) ), true );

			return is_wp_error( $update ) ? $update : true;
		}

		$allowed = (array) apply_filters( 'starter_live_meta_keys', array( 'starter_eyebrow' ) );

		if ( ! in_array( $key, $allowed, true ) ) {
			return new WP_Error( 'starter_live_key', __( 'Campo non modificabile.', 'starter-live' ) );
		}

		update_post_meta( $post_id, $key, $value );

		return true;
	}

	/** Applica $fn al blocco che sta al percorso indicato (es. [2,1,0]) e restituisce l'albero aggiornato. */
	private static function update_at( array $blocks, array $path, callable $fn ) {
		$i = array_shift( $path );

		if ( ! isset( $blocks[ $i ] ) ) {
			return new WP_Error( 'starter_live_path', __( 'Il contenuto è cambiato dopo aver aperto la pagina: ricarica e riprova.', 'starter-live' ) );
		}

		if ( $path ) {
			$inner = self::update_at( isset( $blocks[ $i ]['innerBlocks'] ) ? $blocks[ $i ]['innerBlocks'] : array(), $path, $fn );

			if ( is_wp_error( $inner ) ) {
				return $inner;
			}

			$blocks[ $i ]['innerBlocks'] = $inner;

			return $blocks;
		}

		$block = $fn( $blocks[ $i ] );

		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$blocks[ $i ] = $block;

		return $blocks;
	}

	private static function apply( array $block, array $change ) {
		$stale = new WP_Error( 'starter_live_stale', __( 'Il contenuto è cambiato dopo aver aperto la pagina: ricarica e riprova.', 'starter-live' ) );

		if ( ( $block['blockName'] ?? '' ) !== ( $change['block'] ?? null ) ) {
			return $stale;
		}

		$value = $change['value'] ?? null;

		if ( 'shortcode' === ( $change['t'] ?? '' ) ) {
			$value = self::clean_shortcode( $value );

			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		if ( 'field' === ( $change['src'] ?? '' ) ) {
			return self::apply_field( $block, (string) ( $change['field'] ?? '' ), isset( $change['idx'] ) ? (int) $change['idx'] : null, $value, $stale );
		}

		return self::apply_core( $block, $value, $stale );
	}

	/* ---------------------------------------------------------------- Campi */

	private static function apply_field( array $block, $name, $idx, $value, WP_Error $stale ) {
		if ( ! Starter_Live_Frontend::has_fields() || ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) || ! isset( $block['attrs']['data'][ $name ], $block['attrs']['data'][ '_' . $name ] ) ) {
			return $stale;
		}

		$field = Starter_Live_Frontend::field_def( $block['attrs']['data'][ '_' . $name ] );

		if ( ! $field ) {
			return $stale;
		}

		switch ( $field['type'] ) {
			case 'text':
				$new = self::inline_value( $value, false );
				break;

			case 'email':
				$new = sanitize_text_field( (string) $value );
				break;

			case 'textarea':
				// Elenco modificato voce per voce: una riga per voce, solo testo.
				if ( is_array( $value ) ) {
					$lines = array();

					foreach ( $value as $item ) {
						$line = sanitize_text_field( wp_specialchars_decode( wp_strip_all_tags( (string) $item ), ENT_QUOTES ) );

						if ( '' !== $line ) {
							$lines[] = $line;
						}
					}

					$new = implode( "\n", $lines );
					break;
				}

				$new = self::inline_value( $value, true );
				break;

			case 'wysiwyg':
				$new = wp_kses_post( (string) $value );
				break;

			case 'select':
				$choices = array();

				foreach ( array_keys( (array) ( $field['choices'] ?? array() ) ) as $key ) {
					$choices[] = (string) $key;
				}

				if ( ! empty( $field['multiple'] ) ) {
					$list = array();

					foreach ( (array) $value as $item ) {
						if ( ! is_scalar( $item ) || ! in_array( (string) $item, $choices, true ) ) {
							return new WP_Error( 'starter_live_select', sprintf( /* translators: %s: etichetta del campo */ __( 'Scelta non valida per «%s».', 'starter-live' ), $field['label'] ) );
						}

						if ( ! in_array( (string) $item, $list, true ) ) {
							$list[] = (string) $item;
						}
					}

					$new = $list;
					break;
				}

				$item = is_scalar( $value ) ? (string) $value : '';

				if ( '' === $item && ! empty( $field['allow_null'] ) ) {
					$new = '';
					break;
				}

				if ( ! in_array( $item, $choices, true ) ) {
					return new WP_Error( 'starter_live_select', sprintf( /* translators: %s: etichetta del campo */ __( 'Scelta non valida per «%s».', 'starter-live' ), $field['label'] ) );
				}

				$new = $item;
				break;

			case 'number':
				$raw = trim( str_replace( ',', '.', (string) $value ) );

				if ( '' === $raw ) {
					$new = '';
					break;
				}

				if ( ! is_numeric( $raw ) ) {
					return new WP_Error( 'starter_live_number', sprintf( /* translators: %s: etichetta del campo */ __( 'Valore non valido per «%s»: serve un numero.', 'starter-live' ), $field['label'] ) );
				}

				$num = $raw + 0;

				if ( ( '' !== (string) ( $field['min'] ?? '' ) && $num < $field['min'] ) || ( '' !== (string) ( $field['max'] ?? '' ) && $num > $field['max'] ) ) {
					return new WP_Error( 'starter_live_number', sprintf( /* translators: %s: etichetta del campo */ __( 'Valore fuori dai limiti per «%s».', 'starter-live' ), $field['label'] ) );
				}

				$new = (string) $num;
				break;

			case 'link':
				$link = self::clean_link( $value );
				$new  = array(
					'title'  => $link['title'],
					'url'    => $link['url'],
					'target' => $link['target'],
				);
				break;

			case 'image':
			case 'file':
				$new = self::attachment_id( $value );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				if ( 'file' === $field['type'] && ! empty( $field['mime_types'] ) ) {
					$allowed = array_map( 'trim', explode( ',', strtolower( str_replace( '.', '', $field['mime_types'] ) ) ) );
					$ext     = strtolower( pathinfo( (string) get_attached_file( $new ), PATHINFO_EXTENSION ) );

					if ( ! in_array( $ext, $allowed, true ) ) {
						return new WP_Error( 'starter_live_type', sprintf( /* translators: %s: estensioni */ __( 'Formato non ammesso per «%1$s»: usa %2$s.', 'starter-live' ), $field['label'], strtoupper( implode( ', ', $allowed ) ) ) );
					}
				}
				break;

			case 'starter_icon':
				$new = is_string( $value ) ? strtolower( trim( $value ) ) : '';

				if ( '' === $new ) {
					if ( empty( $field['allow_null'] ) ) {
						return new WP_Error( 'starter_live_icon', sprintf( /* translators: %s: etichetta */ __( 'Scegli un\'icona per «%s».', 'starter-live' ), $field['label'] ) );
					}
				} elseif ( ! preg_match( '/^[a-z0-9-]+$/', $new ) || ( function_exists( 'starter_icon_exists' ) && ! starter_icon_exists( $new ) ) ) {
					return new WP_Error( 'starter_live_icon', __( 'Icona non valida.', 'starter-live' ) );
				}
				break;

			case 'gallery':
				$list = is_array( $value ) ? array_values( $value ) : array();
				$max  = ! empty( $field['max'] ) ? (int) $field['max'] : 0;
				$min  = ! empty( $field['min'] ) ? (int) $field['min'] : 0;

				if ( ( $max && count( $list ) > $max ) || count( $list ) < $min ) {
					return new WP_Error( 'starter_live_gallery', sprintf( /* translators: %s: etichetta del campo */ __( 'Numero di foto non valido per «%s».', 'starter-live' ), $field['label'] ) );
				}

				$current = $block['attrs']['data'][ $name ];
				$strings = is_array( $current ) && $current && is_string( reset( $current ) ); // stesso formato già salvato
				$new     = array();

				foreach ( $list as $att ) {
					$att_id = self::attachment_id( $att );

					if ( is_wp_error( $att_id ) ) {
						return $att_id;
					}

					$new[] = $strings ? (string) $att_id : $att_id;
				}
				break;

			default:
				return $stale;
		}

		$block['attrs']['data'][ $name ] = $new;

		return $block;
	}

	/* ---------------------------------------------------------------- Blocchi core */

	private static function apply_core( array $block, $value, WP_Error $stale ) {
		switch ( $block['blockName'] ) {
			case 'core/paragraph':
			case 'core/heading':
			case 'core/list-item':
				if ( ! empty( $block['innerBlocks'] ) ) {
					return $stale;
				}

				$new = self::clean_rich( $value, true );

				return self::map_html(
					$block,
					static function ( $html ) use ( $new ) {
						return preg_replace_callback(
							'~^(\s*<([a-z0-9]+)\b[^>]*>)(.*)(</\2>\s*)$~is',
							static function ( $m ) use ( $new ) {
								return $m[1] . $new . $m[4];
							},
							$html,
							1
						);
					}
				);

			case 'core/shortcode':
				if ( ! is_string( $value ) || '' === $value ) {
					return $stale;
				}

				return self::map_html(
					$block,
					static function ( $html ) use ( $value ) {
						return preg_replace_callback( '/\[.*\]/s', static function () use ( $value ) { return $value; }, $html, 1 );
					}
				);

			case 'core/list':
				if ( ! is_array( $value ) ) {
					return $stale;
				}

				// Una voce per riga, solo testo e formattazione base; le righe vuote si scartano.
				$items = array();

				foreach ( $value as $item ) {
					$html = self::clean_rich( (string) $item, true );

					if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
						$items[] = $html;
					}
				}

				if ( ! $items ) {
					return new WP_Error( 'starter_live_list', __( "L'elenco deve avere almeno una voce.", 'starter-live' ) );
				}

				$parts = $block['innerContent'];
				$first = (string) reset( $parts );
				$last  = (string) end( $parts );
				$open  = ( false !== stripos( $first, '<ul' ) || false !== stripos( $first, '<ol' ) ) ? $first : '<ul class="wp-block-list">';
				$close = false !== stripos( $last, '</' ) ? $last : '</ul>';

				$block['innerBlocks']  = array();
				$block['innerContent'] = array( $open );

				foreach ( $items as $item ) {
					$block['innerBlocks'][]  = array(
						'blockName'    => 'core/list-item',
						'attrs'        => array(),
						'innerBlocks'  => array(),
						'innerHTML'    => "\n<li>" . $item . "</li>\n",
						'innerContent' => array( "\n<li>" . $item . "</li>\n" ),
					);
					$block['innerContent'][] = null;
				}

				$block['innerContent'][] = $close;
				$block['innerHTML']      = $open . $close;

				return $block;

			case 'core/button':
				$link = self::clean_link( $value );

				return self::map_html(
					$block,
					static function ( $html ) use ( $link ) {
						$p = new WP_HTML_Tag_Processor( $html );

						if ( ! $p->next_tag( 'a' ) ) {
							return $html;
						}

						$p->set_attribute( 'href', $link['url'] );

						if ( '_blank' === $link['target'] ) {
							$p->set_attribute( 'target', '_blank' );
							$p->set_attribute( 'rel', 'noopener' );
						} else {
							$p->remove_attribute( 'target' );
							$p->remove_attribute( 'rel' );
						}

						return preg_replace_callback(
							'~(<a\b[^>]*>)(.*?)(</a>)~is',
							static function ( $m ) use ( $link ) {
								return $m[1] . esc_html( $link['title'] ) . $m[3];
							},
							$p->get_updated_html(),
							1
						);
					}
				);

			case 'core/image':
			case 'core/cover':
			case 'core/media-text':
			case 'core/video':
				// Il valore è l'id dell'allegato, oppure { id, alt }: con il testo alternativo scelto (o proposto) per questa immagine.
				$alt = null;

				if ( is_array( $value ) ) {
					$alt   = isset( $value['alt'] ) ? sanitize_text_field( (string) $value['alt'] ) : null;
					$value = $value['id'] ?? 0;
				}

				$id = self::attachment_id( $value );

				if ( is_wp_error( $id ) ) {
					return $id;
				}

				return self::replace_media( $block, $id, $alt );
		}

		return $stale;
	}

	private static function replace_media( array $block, $id, $custom_alt = null ) {
		$name = $block['blockName'];
		$size = ! empty( $block['attrs']['sizeSlug'] ) ? $block['attrs']['sizeSlug'] : 'full';
		$url  = ( 0 === strpos( (string) get_post_mime_type( $id ), 'video/' ) ) ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, $size );
		// Testo alternativo: quello scelto per questa immagine, altrimenti quello della nuova immagine nella Libreria media (non quello della vecchia).
		$alt  = null !== $custom_alt ? $custom_alt : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		if ( ! $url ) {
			return new WP_Error( 'starter_live_media', __( 'File non valido.', 'starter-live' ) );
		}

		if ( 'core/media-text' === $name ) {
			$block['attrs']['mediaId']  = $id;
			$block['attrs']['mediaUrl'] = $url;
		} else {
			$block['attrs']['id'] = $id;

			if ( 'core/video' !== $name ) {
				$block['attrs']['url'] = $url;
			}
		}

		return self::map_html(
			$block,
			static function ( $html ) use ( $name, $id, $url, $alt ) {
				$p = new WP_HTML_Tag_Processor( $html );

				while ( $p->next_tag() ) {
					$tag   = strtolower( $p->get_tag() );
					$class = (string) $p->get_attribute( 'class' );

					if ( 'video' === $tag && ( 'core/video' === $name || ( 'core/cover' === $name && false !== strpos( $class, 'wp-block-cover__video-background' ) ) ) ) {
						$p->set_attribute( 'src', $url );
					} elseif ( 'img' !== $tag && 'core/cover' === $name && false !== strpos( $class, 'wp-block-cover__image-background' ) ) {
						$style = (string) $p->get_attribute( 'style' );
						$p->set_attribute( 'style', preg_match( '/background-image\s*:\s*url\([^)]*\)/i', $style ) ? preg_replace( '/(background-image\s*:\s*)url\([^)]*\)/i', '$1url(' . $url . ')', $style ) : $style . ';background-image:url(' . $url . ')' );
						$p->set_attribute( 'class', preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $class ) );
					} elseif ( 'img' === $tag && ( 'core/image' === $name || 'core/media-text' === $name || false !== strpos( $class, 'wp-block-cover__image-background' ) ) ) {
						$p->set_attribute( 'src', $url );
						$p->set_attribute( 'class', preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $class ) );
						$p->remove_attribute( 'srcset' );
						$p->remove_attribute( 'sizes' );

						$p->set_attribute( 'alt', $alt );
					}
				}

				return $p->get_updated_html();
			}
		);
	}

	/* ---------------------------------------------------------------- Utilità */

	/** Applica $fn a innerHTML e a tutte le parti di testo di innerContent del blocco. */
	private static function map_html( array $block, callable $fn ) {
		$block['innerHTML'] = $fn( (string) $block['innerHTML'] );

		foreach ( $block['innerContent'] as $i => $part ) {
			if ( is_string( $part ) ) {
				$block['innerContent'][ $i ] = $fn( $part );
			}
		}

		return $block;
	}

	/** HTML ammesso nei testi modificati dal frontend: solo formattazione base e link. */
	private static function clean_rich( $html, $block = false ) {
		$html = (string) $html;

		// Grassetto e corsivo si salvano come <strong> e <em> (così li riconosce Gutenberg).
		$html = preg_replace( array( '~<b(\s[^>]*)?>~i', '~</b>~i', '~<i(\s[^>]*)?>~i', '~</i>~i' ), array( '<strong>', '</strong>', '<em>', '</em>' ), $html );

		// Nei blocchi Gutenberg il sottolineato è uno <span> con stile (non un <u>): è la forma che l'editor dei blocchi conosce e mantiene.
		if ( $block ) {
			$html = preg_replace( array( '~<u(\s[^>]*)?>~i', '~</u>~i' ), array( '<span style="text-decoration: underline;">', '</span>' ), $html );
		}

		return wp_kses(
			$html,
			array(
				'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
				'strong' => array(),
				'em'     => array(),
				'u'      => array(),
				'br'     => array(),
				'sub'    => array(),
				'sup'    => array(),
				'mark'   => array( 'class' => true ),
				'span'   => array( 'style' => true ),
			)
		);
	}

	/**
	 * Testo di un campo modificato dal frontend, con grassetto / corsivo / sottolineato.
	 * Senza formattazione resta testo semplice (come prima); con formattazione si salva l'HTML ridotto ai soli tag in linea.
	 */
	private static function inline_value( $value, $multiline ) {
		$html = self::clean_rich( $value );

		if ( ! $multiline ) {
			$html = preg_replace( '~<br\s*/?>~i', ' ', $html );
		}

		if ( $html === wp_strip_all_tags( $html ) ) {
			$plain = wp_specialchars_decode( $html, ENT_QUOTES );

			return $multiline ? sanitize_textarea_field( $plain ) : sanitize_text_field( $plain );
		}

		return trim( $html );
	}

	private static function clean_link( $value ) {
		$value = is_array( $value ) ? $value : array();

		return array(
			'title'  => sanitize_text_field( (string) ( $value['title'] ?? '' ) ),
			'url'    => esc_url_raw( (string) ( $value['url'] ?? '' ) ),
			'target' => '_blank' === ( $value['target'] ?? '' ) ? '_blank' : '',
		);
	}

	private static function attachment_id( $value ) {
		$id = absint( $value );

		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
			return new WP_Error( 'starter_live_media', __( 'File non valido.', 'starter-live' ) );
		}

		return $id;
	}
}
