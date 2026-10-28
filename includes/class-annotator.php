<?php
/**
 * Abbina i contenuti di un blocco all'HTML già renderizzato e marca gli elementi modificabili con data-live="id".
 *
 * - Blocchi: i valori stanno in attrs.data; si cerca nell'HTML l'elemento che mostra quel valore
 *   (testo uguale, link con lo stesso href, immagine/video con lo stesso file).
 * - Blocchi core: si marca l'elemento principale (paragrafo, titolo, immagine, pulsante…).
 *
 * Un contenuto che non si riesce ad abbinare resta semplicemente non modificabile da frontend (si usa il backend).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_Annotator {

	/* ---------------------------------------------------------------- Blocchi core */

	public static function core( $html, array $block, array $ctx ) {
		$name = $block['blockName'];
		$base = $ctx + array( 'src' => 'core' );

		switch ( $name ) {
			case 'core/paragraph':
			case 'core/heading':
			case 'core/list-item':
				if ( ! empty( $block['innerBlocks'] ) ) {
					return $html;
				}

				$inner = self::inner_html( (string) $block['innerHTML'] );

				if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
					return $html;
				}

				$labels = array(
					'core/paragraph' => __( 'Paragrafo', 'starter-live' ),
					'core/heading'   => __( 'Titolo', 'starter-live' ),
					'core/list-item' => __( 'Voce elenco', 'starter-live' ),
				);

				return self::mark_first_tag(
					$html,
					null,
					$base + array(
						't'         => 'rich',
						'label'     => $labels[ $name ],
						'value'     => $inner,
						'multiline' => 'core/paragraph' === $name,
					)
				);

			case 'core/list':
				// Elenco nativo di Gutenberg: si modifica come un blocco unico (aggiungi / togli / riordina le voci), non voce per voce.
				$items = array();

				foreach ( $block['innerBlocks'] as $item ) {
					// Voci con elenchi annidati o blocchi diversi: troppo complesse per un editor unico, restano modificabili una per una.
					if ( 'core/list-item' !== ( $item['blockName'] ?? '' ) || ! empty( $item['innerBlocks'] ) ) {
						return $html;
					}

					$items[] = self::inner_html( (string) $item['innerHTML'] );
				}

				if ( ! $items ) {
					return $html;
				}

				// Le singole voci erano già state marcate (i blocchi interni si renderizzano prima): si tolgono.
				$html = preg_replace_callback(
					'~(<li\b[^>]*?)\sdata-live="(p\d+)"~',
					static function ( $m ) {
						Starter_Live_Registry::remove( $m[2] );

						return $m[1];
					},
					$html
				);

				return self::mark_first_tag(
					$html,
					null,
					$base + array(
						't'     => 'list',
						'label' => __( 'Elenco', 'starter-live' ),
						'value' => $items,
					)
				);

			case 'core/button':
				$p = new WP_HTML_Tag_Processor( $html );

				if ( ! $p->next_tag( array( 'tag_name' => 'a', 'class_name' => 'wp-block-button__link' ) ) ) {
					return $html;
				}

				$inner = preg_match( '~<a\b[^>]*>(.*?)</a>~is', (string) $block['innerHTML'], $am ) ? $am[1] : '';
				$id    = Starter_Live_Registry::add(
					$base + array(
						't'     => 'link',
						'label' => __( 'Pulsante', 'starter-live' ),
						'value' => array(
							'title'  => trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES ) ),
							'url'    => (string) $p->get_attribute( 'href' ),
							'target' => '_blank' === $p->get_attribute( 'target' ) ? '_blank' : '',
						),
					)
				);
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();

			case 'core/image':
				$p = new WP_HTML_Tag_Processor( $html );

				if ( ! $p->next_tag( 'img' ) ) {
					return $html;
				}

				$id = Starter_Live_Registry::add(
					$base + array(
						't'       => 'image',
						'media'   => 'image',
						'label'   => __( 'Immagine', 'starter-live' ),
						'value'   => isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0,
						'altEdit' => true,
						'alt'     => (string) $p->get_attribute( 'alt' ),
					)
				);
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();

			case 'core/shortcode':
				// Blocco Shortcode (es. un modulo Contact Form 7): lo shortcode viene espanso dopo, qui si avvolge per poterlo cambiare.
				$code = trim( (string) $block['innerHTML'] );

				if ( '' === $code ) {
					return $html;
				}

				$id = Starter_Live_Registry::add( $base + array( 't' => 'shortcode', 'label' => __( 'Shortcode', 'starter-live' ), 'value' => $code ) );

				return '<div data-live="' . esc_attr( $id ) . '">' . $html . '</div>';

			case 'core/cover':
				$p = new WP_HTML_Tag_Processor( $html );

				$is_video = false;

				// Sfondo: <img> oppure <div> con background-image (parallax / sfondo fisso).
				if ( ! $p->next_tag( array( 'class_name' => 'wp-block-cover__image-background' ) ) ) {
					$p        = new WP_HTML_Tag_Processor( $html );
					$is_video = $p->next_tag( array( 'tag_name' => 'video', 'class_name' => 'wp-block-cover__video-background' ) );

					if ( ! $is_video ) {
						return $html;
					}
				}

				$id = Starter_Live_Registry::add(
					$base + array(
						't'       => $is_video ? 'file' : 'image',
						'media'   => $is_video ? 'video' : 'image',
						'label'   => $is_video ? __( 'Video di sfondo', 'starter-live' ) : __( 'Immagine di sfondo', 'starter-live' ),
						'value'   => isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0,
						'altEdit' => ! $is_video && 'img' === strtolower( (string) $p->get_tag() ),
						'alt'     => $is_video ? '' : (string) $p->get_attribute( 'alt' ),
					)
				);
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();

			case 'core/video':
				return self::mark_first_tag(
					$html,
					'video',
					$base + array(
						't'     => 'file',
						'media' => 'video',
						'label' => __( 'Video', 'starter-live' ),
						'value' => isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0,
					)
				);

			case 'core/media-text':
				$p = new WP_HTML_Tag_Processor( $html );

				if ( ! $p->next_tag( array( 'tag_name' => 'figure', 'class_name' => 'wp-block-media-text__media' ) ) || ! $p->next_tag() ) {
					return $html;
				}

				$tag = strtolower( $p->get_tag() );

				if ( ! in_array( $tag, array( 'img', 'video' ), true ) ) {
					return $html;
				}

				$id = Starter_Live_Registry::add(
					$base + array(
						't'       => 'img' === $tag ? 'image' : 'file',
						'media'   => 'img' === $tag ? 'image' : 'video',
						'label'   => 'img' === $tag ? __( 'Immagine', 'starter-live' ) : __( 'Video', 'starter-live' ),
						'value'   => isset( $block['attrs']['mediaId'] ) ? (int) $block['attrs']['mediaId'] : 0,
						'altEdit' => 'img' === $tag,
						'alt'     => 'img' === $tag ? (string) $p->get_attribute( 'alt' ) : '',
					)
				);
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();
		}

		return $html;
	}

	/* ---------------------------------------------------------------- Campi dei blocchi */

	public static function fields( $html, array $block, array $ctx ) {
		$data  = $block['attrs']['data'];
		$type  = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
		$title = $type && $type->title ? $type->title : '';
		$posters = array();

		foreach ( $data as $name => $value ) {
			if ( 0 === strpos( $name, '_' ) || empty( $data[ '_' . $name ] ) || ! is_string( $data[ '_' . $name ] ) ) {
				continue;
			}

			$field = Starter_Live_Frontend::field_def( $data[ '_' . $name ] );

			if ( ! $field || empty( $field['type'] ) ) {
				continue;
			}

			// Etichetta breve: il nome del campo senza le note tra parentesi ("Titolo (H2 grande)" → "Titolo").
			$label = trim( preg_replace( '/\s*\(.*?\)/', '', (string) $field['label'] ) );

			if ( preg_match( '/_(\d+)_/', $name, $m ) ) {
				$label .= ' ' . ( (int) $m[1] + 1 );
			}

			$base = $ctx + array(
				'src'   => 'field',
				'field' => $name,
				'label' => $label,
				'group' => $title,
			);

			// Elenco scritto in un campo di testo, una voce per riga: l'elemento (<ul>) porta il marcatore data-live-lines.
			if ( 'textarea' === $field['type'] && is_string( $value ) && false !== strpos( $html, 'data-live-lines="' . $name . '"' ) ) {
				$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ), 'strlen' ) );
				$html  = self::field_marked( $html, 'data-live-lines', $name, $base + array( 't' => 'list', 'lines' => true, 'value' => $lines ) );
				continue;
			}

			// Valore scritto nella pagina con un marcatore del tema (data-live-num): vale per tutti gli elementi che lo mostrano.
			if ( false !== strpos( $html, 'data-live-num="' . $name . '"' ) ) {
				$marked = self::field_marked_entry( $field, $base, $value );

				if ( null !== $marked ) {
					// Un indirizzo mostrato anche in una mappa (iframe): la stessa voce cambia il testo e la mappa.
					$map = 'text' === $marked['t'] && is_string( $value ) && '' !== $value && self::has_map_iframe( $html, $value );

					if ( $map ) {
						$marked['map'] = true;
					}

					$id   = null;
					$html = self::field_number( $html, $name, $value, $marked, $id );

					if ( $map && null !== $id ) {
						$html = self::field_iframe_attach( $html, $value, $id );
					}

					continue;
				}
			}

			switch ( $field['type'] ) {
				case 'text':
				case 'textarea':
				case 'email':
					// Un campo che contiene uno shortcode (es. il modulo Contact Form 7 del blocco Contatti): si cambia lo shortcode.
					if ( is_string( $value ) && preg_match( '/^\s*\[[A-Za-z0-9_-]+[\s\]\/]/', $value ) ) {
						$html = self::field_shortcode( $html, $value, $base + array( 't' => 'shortcode' ) );
						break;
					}

					if ( is_string( $value ) && '' !== trim( $value ) ) {
						$html = self::field_text( $html, $value, $base + array( 't' => ( false !== strpos( $value, '<' ) ) ? 'rich' : 'text', 'multiline' => 'textarea' === $field['type'] ), false );
					}
					break;

				case 'wysiwyg':
					if ( is_string( $value ) && '' !== trim( $value ) ) {
						$html = self::field_text( $html, $value, $base + array( 't' => 'rich', 'multiline' => true ), true );
					}
					break;

				case 'number':
					$html = self::field_number( $html, $name, $value, $base + array( 't' => 'number', 'min' => isset( $field['min'] ) ? $field['min'] : '', 'max' => isset( $field['max'] ) ? $field['max'] : '' ) );
					break;

				case 'link':
					if ( is_array( $value ) && ! empty( $value['url'] ) ) {
						$html = self::field_link( $html, $value, $base );
					}
					break;

				case 'image':
				case 'file':
					if ( 'image' === $field['type'] && is_numeric( $value ) && (int) $value > 0 ) {
						$posters[] = array( (int) $value, $base );
					}

					if ( is_numeric( $value ) && (int) $value > 0 ) {
						$html = self::field_media( $html, (int) $value, $base + array( 't' => 'image' === $field['type'] ? 'image' : 'file' ), $field );
					}
					break;

				case 'starter_icon':
					// Campo vuoto: il tema mostra l'icona predefinita del campo, che è quella da agganciare.
					$icon = ( is_string( $value ) && '' !== $value ) ? $value : (string) ( $field['default_value'] ?? '' );

					if ( '' !== $icon ) {
						$html = self::field_icon( $html, $icon, $base + array( 't' => 'icon', 'allowNull' => ! empty( $field['allow_null'] ) ) );
					}
					break;

				case 'gallery':
					if ( is_array( $value ) ) {
						$ids = array_values( array_filter( array_map( 'absint', $value ) ) );

						if ( $ids ) {
							$html = self::field_gallery(
								$html,
								$ids,
								$base + array(
									't'     => 'gallery',
									'media' => 'image',
									'max'   => isset( $field['max'] ) ? (int) $field['max'] : 0,
									'min'   => isset( $field['min'] ) ? (int) $field['min'] : 0,
								)
							);
						}
					}
					break;
			}
		}

		// Copertine dei video: non sono un elemento a sé, si cambiano insieme al video.
		foreach ( $posters as $poster ) {
			self::field_poster( $html, $poster[0], $poster[1] );
		}

		return $html;
	}

	/**
	 * Numero (prezzo, superficie, locali…): nell'HTML il valore è scritto in forma diversa ("€ 1.500.000", "5+ locali"), quindi il tema
	 * indica l'elemento con data-live-num="nome del campo" (vedi l'attributo "fieldnum" di starter_live_attrs) e qui lo si aggancia.
	 */
	private static function field_number( $html, $name, $value, array $entry, &$id = null ) {
		if ( ! isset( $entry['value'] ) ) {
			return $html;
		}

		$p  = new WP_HTML_Tag_Processor( $html );
		$id = null;

		while ( $p->next_tag() ) {
			if ( (string) $name !== (string) $p->get_attribute( 'data-live-num' ) || null !== $p->get_attribute( 'data-live' ) ) {
				continue;
			}

			if ( null === $id ) {
				$id = Starter_Live_Registry::add( $entry );
			}

			$p->remove_attribute( 'data-live-num' );
			$p->set_attribute( 'data-live', $id );
		}

		return null === $id ? $html : $p->get_updated_html();
	}

	/** Marca con una voce sola tutti gli elementi che portano l'attributo $attr = $name; senza elementi non fa niente. */
	private static function field_marked( $html, $attr, $name, array $entry ) {
		$p  = new WP_HTML_Tag_Processor( $html );
		$id = null;

		while ( $p->next_tag() ) {
			if ( (string) $name !== (string) $p->get_attribute( $attr ) || null !== $p->get_attribute( 'data-live' ) ) {
				continue;
			}

			if ( null === $id ) {
				$id = Starter_Live_Registry::add( $entry );
			}

			$p->remove_attribute( $attr );
			$p->set_attribute( 'data-live', $id );
		}

		return null === $id ? $html : $p->get_updated_html();
	}

	/** Voce di un valore marcato dal tema (numero, scelta da elenco, testo breve); null se il tipo di campo non è gestito. */
	private static function field_marked_entry( array $field, array $base, $value ) {
		switch ( $field['type'] ) {
			case 'number':
				return is_scalar( $value ) ? $base + array( 't' => 'number', 'min' => isset( $field['min'] ) ? $field['min'] : '', 'max' => isset( $field['max'] ) ? $field['max'] : '', 'value' => (string) $value ) : null;

			case 'select':
				$choices  = array();
				$multiple = ! empty( $field['multiple'] );

				foreach ( (array) ( $field['choices'] ?? array() ) as $key => $label ) {
					$choices[ (string) $key ] = (string) $label;
				}

				return $base + array(
					't'        => 'select',
					'choices'  => $choices,
					'multiple' => $multiple,
					'nullable' => ! empty( $field['allow_null'] ),
					'value'    => $multiple ? array_values( array_map( 'strval', (array) $value ) ) : ( is_scalar( $value ) ? (string) $value : '' ),
				);

			case 'text':
			case 'textarea':
				return is_scalar( $value ) ? $base + array( 't' => 'text', 'multiline' => 'textarea' === $field['type'], 'value' => (string) $value ) : null;
		}

		return null;
	}

	/** Se un <video> già marcato usa questa immagine come poster, la collega alla voce del video. */
	private static function field_poster( $html, $attachment_id, array $entry ) {
		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return;
		}

		$stem  = preg_replace( '/\.[a-z0-9]+$/i', '', wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		$regex = '~/' . preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.[a-z0-9]+~i';
		$p     = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'video' ) ) {
			$poster = $p->get_attribute( 'poster' );
			$vid    = $p->get_attribute( 'data-live' );

			if ( is_string( $poster ) && is_string( $vid ) && preg_match( $regex, $poster ) ) {
				$pid = Starter_Live_Registry::add(
					$entry + array(
						't'      => 'image',
						'media'  => 'image',
						'value'  => $attachment_id,
						'hidden' => true,
						'thumb'  => (string) wp_get_attachment_image_url( $attachment_id, 'medium' ),
					)
				);
				Starter_Live_Registry::update( $vid, array( 'poster' => $pid ) );

				return;
			}
		}
	}

	/** Shortcode: il tema segna l'elemento che lo contiene con data-live-shortcode="nome_campo". */
	private static function field_shortcode( $html, $value, array $entry ) {
		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag() ) {
			if ( null === $p->get_attribute( 'data-live' ) && $entry['field'] === $p->get_attribute( 'data-live-shortcode' ) ) {
				$id = Starter_Live_Registry::add( $entry + array( 'value' => trim( $value ) ) );
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();
			}
		}

		return $html;
	}

	/** Testo che non si vede ma sta in un iframe (es. l'indirizzo di una mappa): si cerca l'iframe che lo contiene nell'indirizzo. */
	/** C'è un <iframe> non ancora marcato la cui mappa punta a questo indirizzo? */
	private static function has_map_iframe( $html, $value ) {
		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'iframe' ) ) {
			$src = (string) $p->get_attribute( 'src' );

			if ( null === $p->get_attribute( 'data-live' ) && ( false !== strpos( $src, rawurlencode( $value ) ) || false !== strpos( $src, urlencode( $value ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** Collega la mappa a una voce già creata (stesso data-live del testo dell'indirizzo). */
	private static function field_iframe_attach( $html, $value, $id ) {
		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'iframe' ) ) {
			$src = (string) $p->get_attribute( 'src' );

			if ( null === $p->get_attribute( 'data-live' ) && ( false !== strpos( $src, rawurlencode( $value ) ) || false !== strpos( $src, urlencode( $value ) ) ) ) {
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();
			}
		}

		return $html;
	}

	private static function field_iframe_value( $html, $value, array $entry ) {
		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'iframe' ) ) {
			$src = (string) $p->get_attribute( 'src' );

			if ( null === $p->get_attribute( 'data-live' ) && ( false !== strpos( $src, rawurlencode( $value ) ) || false !== strpos( $src, urlencode( $value ) ) ) ) {
				$id = Starter_Live_Registry::add( $entry + array( 'value' => $value, 'map' => true ) );
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();
			}
		}

		return null;
	}

	/** Icona del tema: l'<svg> disegnato da starter_icon() porta la classe "icon--nome"; si marca il primo non ancora marcato. */
	private static function field_icon( $html, $name, array $entry ) {
		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'svg' ) ) {
			if ( null === $p->get_attribute( 'data-live' ) && $p->has_class( 'icon--' . $name ) ) {
				$id = Starter_Live_Registry::add( $entry + array( 'value' => $name ) );
				$p->set_attribute( 'data-live', $id );

				return $p->get_updated_html();
			}
		}

		return $html;
	}

	/** Testo: cerca <tag>valore</tag> e marca il primo elemento non ancora marcato. */
	private static function field_text( $html, $value, array $entry, $wysiwyg ) {
		$candidates = array( esc_html( $value ), nl2br( esc_html( $value ) ), wp_kses_post( $value ) );

		if ( $wysiwyg ) {
			array_unshift( $candidates, trim( wpautop( $value ) ), trim( wpautop( wp_kses_post( $value ) ) ) );
		}

		$candidates = array_unique( array_filter( $candidates ) );
		$attrs      = '((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)';

		// 1ª passata: l'elemento contiene solo il testo. 2ª (solo testo semplice): il testo è accanto a un'icona o a virgolette.
		foreach ( $wysiwyg || 'text' !== $entry['t'] ? array( false ) : array( false, true ) as $loose ) {
			foreach ( $candidates as $candidate ) {
				$text    = preg_quote( $candidate, '~' );
				$pattern = $loose
					? '~<([a-z][a-z0-9]*)\b' . $attrs . '>((?:\s|<span\b[^>]*>(?:(?!</span>).)*</span>)*)([^<]{0,6}?)' . $text . '([^<]{0,6}?)\s*</\1\s*>~is'
					: '~<([a-z][a-z0-9]*)\b' . $attrs . '>\s*' . $text . '\s*</\1\s*>~i';

				if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
					continue;
				}

				foreach ( $matches as $m ) {
					if ( false !== strpos( $m[2][0], 'data-live' ) ) {
						continue;
					}

					$id     = Starter_Live_Registry::add( $entry + array( 'value' => $value ) + ( $loose ? array( 'tn' => true ) : array() ) );
					$insert = $m[1][1] + strlen( $m[1][0] );

					return substr( $html, 0, $insert ) . ' data-live="' . $id . '"' . substr( $html, $insert );
				}
			}
		}

		// Testo lungo (editor): il tema può aver cambiato gli a capo o la punteggiatura, si cerca il contenitore con lo stesso testo.
		if ( $wysiwyg ) {
			$fuzzy = self::field_container_with_text( $html, $value, $entry );

			return null !== $fuzzy ? $fuzzy : $html;
		}

		// Ultima possibilità (solo testi semplici e a formattazione ridotta): il tema può aver tolto o cambiato dei tag (es. <b>),
		// quindi si cerca l'elemento il cui testo, senza tag, è uguale a quello salvato.
		if ( ! $wysiwyg ) {
			$fuzzy = self::field_text_fuzzy( $html, $value, $entry );

			if ( null !== $fuzzy ) {
				return $fuzzy;
			}

			// Non è testo visibile: può essere l'indirizzo di una mappa (iframe).
			$map = self::field_iframe_value( $html, $value, $entry );

			if ( null !== $map ) {
				return $map;
			}
		}

		return $html;
	}

	/** Testo senza tag, con spazi e entità normalizzati: serve per confrontare un valore salvato con quello mostrato. */
	private static function norm_text( $s ) {
		$s = html_entity_decode( wp_strip_all_tags( preg_replace( '~<(?:br|/p|/div|/li|/h[1-6])\b[^>]*>~i', ' ', (string) $s ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = strtr( $s, array( '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '×' => 'x', "\xC2\xA0" => ' ' ) );

		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Contenitore (div, section, article, anche con altri div dentro) non ancora marcato il cui testo è uguale a quello salvato; null se non c'è. */
	private static function field_container_with_text( $html, $value, array $entry ) {
		$want = self::norm_text( $value );

		if ( '' === $want || ! preg_match_all( '~<(div|section|article)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)>~i', $html, $opens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		foreach ( $opens as $open ) {
			if ( false !== strpos( $open[2][0], 'data-live' ) ) {
				continue;
			}

			$tag   = strtolower( $open[1][0] );
			$start = $open[0][1] + strlen( $open[0][0] );
			$depth = 1;
			$pos   = $start;

			while ( $depth > 0 && preg_match( '~<(/?)' . $tag . '\b[^>]*>~i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
				$depth += '' === $t[1][0] ? 1 : -1;
				$pos    = $t[0][1] + strlen( $t[0][0] );

				if ( 0 === $depth && self::norm_text( substr( $html, $start, $t[0][1] - $start ) ) === $want ) {
					$id     = Starter_Live_Registry::add( $entry + array( 'value' => $value ) );
					$insert = $open[1][1] + strlen( $open[1][0] );

					return substr( $html, 0, $insert ) . ' data-live="' . $id . '"' . substr( $html, $insert );
				}
			}
		}

		return null;
	}

	/** Cerca (prima i tag "di testo", per ultimo div) un elemento non ancora marcato con lo stesso testo; null se non lo trova. */
	private static function field_text_fuzzy( $html, $value, array $entry, array $tags = array() ) {
		$want = self::norm_text( $value );

		if ( '' === $want ) {
			return null;
		}

		foreach ( $tags ? $tags : array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'blockquote', 'figcaption', 'span', 'strong', 'em', 'label', 'dt', 'dd', 'td', 'th', 'cite', 'div' ) as $tag ) {
			if ( ! preg_match_all( '~<' . $tag . '\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</' . $tag . '\s*>~is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches as $m ) {
				if ( false !== strpos( $m[1][0], 'data-live' ) || self::norm_text( $m[2][0] ) !== $want ) {
					continue;
				}

				$id     = Starter_Live_Registry::add( $entry + array( 'value' => $value ) );
				$insert = $m[0][1] + 1 + strlen( $tag );

				return substr( $html, 0, $insert ) . ' data-live="' . $id . '"' . substr( $html, $insert );
			}
		}

		return null;
	}

	/** Link: cerca il primo <a> con lo stesso href non ancora marcato. */
	private static function field_link( $html, array $value, array $entry ) {
		$wanted = untrailingslashit( (string) $value['url'] );
		$p      = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag( 'a' ) ) {
			if ( null !== $p->get_attribute( 'data-live' ) || untrailingslashit( (string) $p->get_attribute( 'href' ) ) !== $wanted ) {
				continue;
			}

			$id = Starter_Live_Registry::add(
				$entry + array(
					't'     => 'link',
					'value' => array(
						'title'  => isset( $value['title'] ) ? (string) $value['title'] : '',
						'url'    => (string) $value['url'],
						'target' => ! empty( $value['target'] ) ? '_blank' : '',
					),
				)
			);
			$p->set_attribute( 'data-live', $id );

			return $p->get_updated_html();
		}

		return $html;
	}

	/**
	 * Gallery: una sola voce per tutto il campo. Ogni elemento della pagina che mostra una delle sue foto
	 * (slide, miniature, foto sovrapposte…) riceve lo stesso data-live e apre lo stesso editor.
	 */
	private static function field_gallery( $html, array $ids, array $entry ) {
		$matchers = array();
		$thumbs   = array();

		foreach ( $ids as $position => $att_id ) {
			$url = wp_get_attachment_url( $att_id );

			if ( $url ) {
				$stem                 = preg_replace( '/\.[a-z0-9]+$/i', '', wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
				$matchers[ $position ] = '~/' . preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.[a-z0-9]+~i';
				$thumbs[ $att_id ]    = (string) wp_get_attachment_image_url( $att_id, 'thumbnail' );
			}
		}

		if ( ! $matchers ) {
			return $html;
		}

		$id = null;
		$p  = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag() ) {
			if ( null !== $p->get_attribute( 'data-live' ) ) {
				continue;
			}

			foreach ( array( 'src', 'srcset', 'data-src', 'data-srcset', 'style' ) as $attr ) {
				$v = $p->get_attribute( $attr );

				if ( ! is_string( $v ) ) {
					continue;
				}

				foreach ( $matchers as $position => $regex ) {
					if ( preg_match( $regex, $v ) ) {
						if ( null === $id ) {
							$id = Starter_Live_Registry::add( $entry + array( 'value' => $ids, 'thumbs' => (object) $thumbs ) );
						}

						$p->set_attribute( 'data-live', $id );
						// Posizione della foto nella gallery: serve all'anteprima per sapere quale elemento cambiare.
						$p->set_attribute( 'data-live-i', (string) $position );
						continue 3;
					}
				}
			}
		}

		return null === $id ? $html : $p->get_updated_html();
	}

	/** Immagine / video: cerca l'elemento che usa lo stesso file (anche in una versione ridimensionata). */
	private static function field_media( $html, $attachment_id, array $entry, array $field ) {
		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return $html;
		}

		$stem  = preg_replace( '/\.[a-z0-9]+$/i', '', wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		$regex = '~/' . preg_quote( $stem, '~' ) . '(?:-\d+x\d+)?\.[a-z0-9]+~i';
		$media = 'image';

		if ( 'file' === $field['type'] ) {
			$media = ( 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'video/' ) ) ? 'video' : '';
		}

		$p = new WP_HTML_Tag_Processor( $html );

		while ( $p->next_tag() ) {
			$tag = strtolower( $p->get_tag() );

			if ( 'video' === $tag ) {
				$p->set_bookmark( 'video' );
			}

			if ( null !== $p->get_attribute( 'data-live' ) ) {
				continue;
			}

			// Il file di un video sta nel <source>: si marca il <video> che lo contiene.
			if ( 'source' === $tag ) {
				$src = $p->get_attribute( 'src' );

				if ( is_string( $src ) && preg_match( $regex, $src ) && $p->has_bookmark( 'video' ) ) {
					$id = Starter_Live_Registry::add( $entry + array( 'value' => $attachment_id, 'media' => $media ) );
					$p->seek( 'video' );
					$p->set_attribute( 'data-live', $id );

					return $p->get_updated_html();
				}

				continue;
			}

			$attrs = array( 'src', 'srcset', 'data-src', 'data-srcset', 'style' );

			if ( 'a' === $tag ) {
				$attrs = array( 'href' );
			}

			foreach ( $attrs as $attr ) {
				$v = $p->get_attribute( $attr );

				if ( is_string( $v ) && preg_match( $regex, $v ) ) {
					$id = Starter_Live_Registry::add( $entry + array( 'value' => $attachment_id, 'media' => $media ) );
					$p->set_attribute( 'data-live', $id );

					return $p->get_updated_html();
				}
			}
		}

		return $html;
	}

	/* ---------------------------------------------------------------- Utilità */

	/** Marca il primo tag (o il primo <$tag>) dell'HTML. */
	private static function mark_first_tag( $html, $tag, array $entry ) {
		$p = new WP_HTML_Tag_Processor( $html );

		if ( ! ( $tag ? $p->next_tag( $tag ) : $p->next_tag() ) ) {
			return $html;
		}

		$id = Starter_Live_Registry::add( $entry );
		$p->set_attribute( 'data-live', $id );

		return $p->get_updated_html();
	}

	/** Contenuto di un elemento "<p>…</p>" salvato nel post. */
	public static function inner_html( $html ) {
		if ( preg_match( '~^\s*<([a-z0-9]+)\b[^>]*>(.*)</\1>\s*$~is', $html, $m ) ) {
			return $m[2];
		}

		return '';
	}
}
