<?php
/**
 * WooCommerce: descrizione breve, descrizione lunga e immagini del prodotto, modificabili dalla scheda prodotto.
 *
 * La scheda usa i template classici di WooCommerce (niente blocchi), quindi qui si marcano gli elementi dal markup che WooCommerce stampa:
 * - descrizione breve: avvolta in un <div> (o, se manca, uno spazio vuoto facoltativo visibile solo in modalità modifica);
 * - descrizione lunga: solo se è testo classico; se è fatta con blocchi, i blocchi sono già modificabili uno per uno;
 * - immagini: un solo editor per tutte (la prima è l'immagine principale, le altre la galleria).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_WooCommerce {

	/** True mentre WooCommerce stampa la scheda "Descrizione". */
	private static $in_description_tab = false;

	/** Id (nel registro) dell'editor delle immagini del prodotto: uno per pagina. */
	private static $images_id = null;

	public static function init() {
		add_filter( 'woocommerce_short_description', array( __CLASS__, 'short_description' ), 99 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'empty_short_description' ), 21 );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'wrap_description_tab' ), 99 );
		add_filter( 'the_content', array( __CLASS__, 'long_description' ), 99 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'image' ), 99, 2 );
	}

	/** Siamo nella scheda di un prodotto che l'utente può modificare. */
	private static function active() {
		return function_exists( 'is_product' ) && is_product() && Starter_Live_Frontend::can_edit() && (int) get_the_ID() === Starter_Live_Frontend::target_post_id();
	}

	/** Descrizione breve: la stampa WooCommerce nel riepilogo del prodotto. */
	public static function short_description( $html ) {
		// Vuota: WooCommerce non deve stampare nulla (ci pensa lo spazio facoltativo qui sotto).
		if ( '' === trim( (string) $html ) || ! self::active() || ! doing_action( 'woocommerce_single_product_summary' ) ) {
			return $html;
		}

		$id = Starter_Live_Registry::add( self::entry( 'post_excerpt', __( 'Descrizione breve', 'starter-live' ), (string) get_post_field( 'post_excerpt', get_the_ID() ) ) );

		return '<div data-live="' . esc_attr( $id ) . '">' . $html . '</div>';
	}

	/** Descrizione breve assente: WooCommerce non stampa niente, qui c'è uno spazio vuoto facoltativo (visibile solo in modalità modifica). */
	public static function empty_short_description() {
		if ( ! self::active() || '' !== trim( (string) get_post_field( 'post_excerpt', get_the_ID() ) ) ) {
			return;
		}

		$id = Starter_Live_Registry::add( self::entry( 'post_excerpt', __( 'Descrizione breve', 'starter-live' ), '' ) );

		echo '<div class="woocommerce-product-details__short-description" data-live-empty data-live-placeholder="' . esc_attr__( 'Aggiungi una descrizione breve (facoltativa)', 'starter-live' ) . '" data-live="' . esc_attr( $id ) . '"></div>';
	}

	/** Segna quando WooCommerce sta stampando la scheda "Descrizione". */
	public static function wrap_description_tab( $tabs ) {
		if ( self::active() && isset( $tabs['description']['callback'] ) && is_callable( $tabs['description']['callback'] ) ) {
			$original = $tabs['description']['callback'];

			$tabs['description']['callback'] = static function () use ( $original ) {
				self::$in_description_tab = true;
				call_user_func( $original );
				self::$in_description_tab = false;
			};
		}

		return $tabs;
	}

	/** Descrizione lunga in testo classico: l'intero testo diventa un elemento modificabile. Se è fatta con blocchi, ci pensano i blocchi. */
	public static function long_description( $content ) {
		if ( ! self::$in_description_tab || has_blocks( $content ) || '' === trim( wp_strip_all_tags( $content ) ) ) {
			return $content;
		}

		$id = Starter_Live_Registry::add( self::entry( 'post_content', __( 'Descrizione', 'starter-live' ), (string) get_post_field( 'post_content', get_the_ID() ) ) );

		return '<div data-live="' . esc_attr( $id ) . '">' . $content . '</div>';
	}

	/** Immagini del prodotto: ogni immagine della scheda (principale e galleria) apre lo stesso editor. */
	public static function image( $html, $attachment_id ) {
		if ( ! self::active() ) {
			return $html;
		}

		$product = wc_get_product( get_the_ID() );

		if ( ! $product ) {
			return $html;
		}

		$ids      = array_values( array_unique( array_filter( array_map( 'absint', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) ) ) );
		$position = array_search( (int) $attachment_id, $ids, true );

		if ( false === $position ) {
			return $html;
		}

		if ( null === self::$images_id ) {
			$thumbs = array();

			foreach ( $ids as $att_id ) {
				$thumbs[ $att_id ] = (string) wp_get_attachment_image_url( $att_id, 'thumbnail' );
			}

			self::$images_id = Starter_Live_Registry::add(
				array(
					'src'    => 'product_images',
					'post'   => (int) get_the_ID(),
					't'      => 'gallery',
					'media'  => 'image',
					'label'  => __( 'Immagini del prodotto', 'starter-live' ),
					'hint'   => __( 'La prima è l\'immagine principale; le altre compaiono nella galleria.', 'starter-live' ),
					'value'  => $ids,
					'thumbs' => (object) $thumbs,
					'max'    => 0,
					'min'    => 1,
					'block'  => '',
					'path'   => '',
				)
			);
		}

		$p = new WP_HTML_Tag_Processor( $html );

		if ( ! $p->next_tag( 'img' ) ) {
			return $html;
		}

		$p->set_attribute( 'data-live', self::$images_id );
		$p->set_attribute( 'data-live-i', (string) $position );

		return $p->get_updated_html();
	}

	private static function entry( $key, $label, $value ) {
		return array(
			'src'       => 'post',
			'post'      => (int) get_the_ID(),
			'key'       => $key,
			't'         => 'rich',
			'label'     => $label,
			'value'     => $value,
			'multiline' => true,
			'block'     => '',
			'path'      => '',
		);
	}
}
