<?php
/** Frontend: chi può modificare, tracciamento dei blocchi, caricamento di JS/CSS e dati per l'interfaccia. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_Frontend {

	private static $can_edit = null;

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'tag_blocks' ), 8 );
		add_filter( 'render_block', array( __CLASS__, 'annotate_block' ), 20, 2 );
		// Il tema modifica il markup del pulsante su questo filtro (priorità 10): noi arriviamo dopo.
		add_filter( 'render_block_core/button', array( __CLASS__, 'annotate_block' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_js_delay_exc' ) as $starter_ls_filter ) {
			add_filter( $starter_ls_filter, array( __CLASS__, 'litespeed_exclude' ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'print_data' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 999 );
		add_filter( 'starter_live_attrs', array( __CLASS__, 'attrs' ), 10, 2 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'thumbnail' ), 99, 3 );
		add_filter( 'starter_live_post_html', array( __CLASS__, 'annotate_post_acf' ), 10, 2 );
	}

	/**
	 * La pagina "modificabile" di quello che si sta guardando: la pagina/articolo singolo, oppure la pagina scelta come Blog
	 * o come Shop (il cui titolo, occhiello e testo introduttivo stanno in quella pagina).
	 */
	public static function target_post_id() {
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}

		if ( is_home() && ! is_front_page() ) {
			return (int) get_option( 'page_for_posts' );
		}

		if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
			return max( 0, (int) wc_get_page_id( 'shop' ) );
		}

		return 0;
	}

	/** Categoria, tag o altro termine di cui si sta guardando l'archivio (null se non è un archivio di un termine). */
	public static function target_term() {
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			return $term instanceof WP_Term ? $term : null;
		}

		return null;
	}

	/** Solo chi può modificare la pagina (o il termine) che sta guardando. */
	public static function can_edit() {
		if ( null !== self::$can_edit ) {
			return self::$can_edit;
		}

		self::$can_edit = false;

		if ( is_admin() || is_feed() || is_customize_preview() || ! is_user_logged_in() ) {
			return false;
		}

		$post_id = self::target_post_id();
		$term    = self::target_term();
		$allowed = ( $post_id && current_user_can( 'edit_post', $post_id ) ) || ( $term && current_user_can( 'edit_term', $term->term_id ) );

		if ( $allowed ) {
			self::$can_edit = (bool) apply_filters( 'starter_live_can_edit', true, $post_id );
		}

		return self::$can_edit;
	}

	/**
	 * Scrive in ogni blocco (anche annidato) il suo "percorso" nel post_content, es. 2.1.0.
	 * Serve al salvataggio per ritrovare esattamente lo stesso blocco.
	 */
	public static function tag_blocks( $content ) {
		static $done = array();

		if ( ! self::can_edit() || ! has_blocks( $content ) ) {
			return $content;
		}

		$target = self::target_post_id();
		$post_id = (int) get_the_ID();

		// Il contenuto della pagina nel suo ciclo, oppure esattamente il contenuto della pagina Blog/Shop stampato dal tema fuori dal ciclo.
		$in_loop = $post_id && $post_id === $target && in_the_loop() && is_main_query();
		$direct  = $content === get_post_field( 'post_content', $target );

		if ( ( ! $in_loop && ! $direct ) || isset( $done[ $target ] ) ) {
			return $content;
		}

		$done[ $target ] = true;

		return serialize_blocks( self::walk( parse_blocks( $content ), array(), $target ) );
	}

	private static function walk( array $blocks, array $prefix, $post_id ) {
		foreach ( $blocks as $i => $block ) {
			$path = array_merge( $prefix, array( $i ) );

			if ( ! empty( $block['blockName'] ) ) {
				$block['attrs']                = is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$block['attrs']['livePath']  = implode( '.', $path );
				$block['attrs']['livePost']  = $post_id;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::walk( $block['innerBlocks'], $path, $post_id );
			}

			$blocks[ $i ] = $block;
		}

		return $blocks;
	}

	/** Aggiunge data-live agli elementi modificabili del blocco appena renderizzato. */
	public static function annotate_block( $html, $block ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || empty( $block['blockName'] ) || ! isset( $block['attrs']['livePath'] ) || ! self::can_edit() ) {
			return $html;
		}

		// Il filtro generico non deve ripassare dal pulsante già gestito dal suo filtro dedicato.
		if ( 'render_block' === current_filter() && 'core/button' === $block['blockName'] ) {
			return $html;
		}

		$context = array(
			'post'  => (int) $block['attrs']['livePost'],
			'path'  => (string) $block['attrs']['livePath'],
			'block' => $block['blockName'],
		);

		if ( isset( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] ) && Starter_Live_Frontend::has_fields() ) {
			$html = Starter_Live_Annotator::acf( $html, $block, $context );
		} else {
			$html = Starter_Live_Annotator::core( $html, $block, $context );
		}

		return $html;
	}

	/** Vero se c'è chi fornisce le definizioni dei campi (plugin Starter Blocks, oppure ACF). */
	public static function has_fields() {
		return function_exists( 'starter_field_def' ) || function_exists( 'acf_get_field' );
	}

	/** Definizione di un campo dalla sua chiave (tipo, scelte, minimo e massimo…). */
	public static function field_def( $key ) {
		if ( function_exists( 'starter_field_def' ) ) {
			return starter_field_def( $key );
		}

		return function_exists( 'acf_get_field' ) ? acf_get_field( $key ) : null;
	}

	/**
	 * Tipi di contenuto con campi modificabili dal frontend anche se non sono blocchi (es. la scheda di un immobile).
	 * Il tema li dichiara con il filtro starter_live_meta_post_types.
	 */
	public static function acf_post_types() {
		return (array) apply_filters( 'starter_live_meta_post_types', array() );
	}

	/**
	 * Scheda di un contenuto con campi (non blocchi): si passa il suo HTML, ogni campo riconosciuto diventa modificabile.
	 * Si usa come filtro (starter_live_post_html): il tema lo applica all'HTML della scheda solo se il plugin c'è.
	 */
	public static function annotate_post_acf( $html, $post_id ) {
		$post_id = (int) $post_id;

		if ( ! is_string( $html ) || '' === trim( $html ) || ! $post_id || ! self::can_edit() || $post_id !== self::target_post_id() || ! Starter_Live_Frontend::has_fields() ) {
			return $html;
		}

		if ( ! in_array( get_post_type( $post_id ), self::acf_post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $html;
		}

		// Valori dei campi come li legge un blocco: nome => valore, _nome => chiave del campo.
		$data = array();

		foreach ( get_post_meta( $post_id ) as $name => $values ) {
			if ( ! isset( $values[0] ) || 0 === strpos( (string) $name, '_' ) ) {
				continue;
			}

			$ref = get_post_meta( $post_id, '_' . $name, true );

			if ( is_string( $ref ) && 0 === strpos( $ref, 'field_' ) ) {
				$data[ $name ]        = maybe_unserialize( $values[0] );
				$data[ '_' . $name ] = $ref;
			}
		}

		// Campi che il tema non mostra nella scheda (o che mostra altrove) e quindi non vanno agganciati a un altro elemento.
		foreach ( (array) apply_filters( 'starter_live_meta_post_skip', array(), get_post_type( $post_id ) ) as $skip ) {
			unset( $data[ $skip ], $data[ '_' . $skip ] );
		}

		if ( ! $data ) {
			return $html;
		}

		return Starter_Live_Annotator::acf(
			$html,
			array(
				'blockName' => 'acf/scheda',
				'attrs'     => array( 'data' => $data ),
			),
			array(
				'post'  => $post_id,
				'path'  => '',
				'block' => '',
				'src'   => 'acfpost',
			)
		);
	}

	/**
	 * Chi può modificare non deve mai ricevere (né far memorizzare) una pagina dalla cache: i marcatori di modifica sono solo per lui.
	 * DONOTCACHEPAGE è rispettata dai principali plugin di cache (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed, WP Fastest Cache…).
	 */
	public static function no_cache() {
		if ( self::can_edit() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			nocache_headers();

			// LiteSpeed (cache del server e plugin LiteSpeed Cache): intestazione ufficiale per non memorizzare la pagina.
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
			}
		}
	}

	/** LiteSpeed Cache: lo script di modifica non deve essere unito, ritardato o differito dalle sue ottimizzazioni, altrimenti il pulsante può non comparire. */
	public static function litespeed_exclude( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = 'starter-live';
		$list[] = 'starter-pencil';

		return $list;
	}

	public static function enqueue() {
		if ( ! self::can_edit() ) {
			return;
		}

		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( 'starter-live', STARTER_LIVE_URL . 'assets/live.js', array(), STARTER_LIVE_VERSION, true );

		// Spazi vuoti facoltativi (es. descrizione di una categoria): invisibili finché non si entra in modalità modifica.
		wp_register_style( 'starter-live', false, array(), STARTER_LIVE_VERSION );
		wp_enqueue_style( 'starter-live' );
		wp_add_inline_style(
			'starter-live',
			'[data-live-empty]{display:none!important}' .
			'html.starter-live-on iframe[data-live]{pointer-events:none}' .
			'html.starter-live-on [data-live-empty]{display:block!important;min-height:2.6em;padding:.5em .75em;border-radius:8px;outline:2px dashed rgba(124,58,237,.55);outline-offset:2px}' .
			'html.starter-live-on [data-live-empty]::before{content:attr(data-live-placeholder);color:#7c3aed;opacity:.85;font-style:italic;font-size:.95rem}'
		);

		// Voce nella barra admin: stesso colore del pulsante.
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-starter-live>.ab-item{background:#b7ff00;color:#031428;font-weight:600;transition:background-color .25s ease}' .
			'#wpadminbar #wp-admin-bar-starter-live:hover>.ab-item,#wpadminbar #wp-admin-bar-starter-live>.ab-item:focus{background:#a6e800!important;color:#031428!important}' .
			'@media (max-width:782px){#wpadminbar #wp-admin-bar-starter-live{display:none!important}}'
		);
	}

	/** Dati per il JS: stampati nel footer, quando la pagina è già stata renderizzata. */
	public static function print_data() {
		if ( ! self::can_edit() || ! wp_script_is( 'starter-live', 'enqueued' ) ) {
			return;
		}

		// Data dell'ultima modifica di ogni pagina al momento in cui è stata aperta: al salvataggio serve a capire se nel frattempo
		// qualcun altro (o un'altra scheda) l'ha cambiata, per non sovrascriverla in silenzio.
		$revs = array();

		foreach ( Starter_Live_Registry::all() as $field ) {
			if ( ! empty( $field['post'] ) ) {
				$revs[ (int) $field['post'] ] = (string) get_post_field( 'post_modified_gmt', (int) $field['post'] );
			}
		}

		$config = array(
			'revs'     => (object) $revs,
			'rest'     => esc_url_raw( rest_url( 'starter-live/v1/save' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'canMedia' => current_user_can( 'upload_files' ),
			// Libreria icone del tema (se c'è): serve per cambiare le icone dal frontend.
			'shortcodes' => array(
				'rest'  => esc_url_raw( rest_url( 'starter-live/v1/shortcode' ) ),
				'forms' => self::contact_forms(),
			),
			'icons'    => function_exists( 'starter_icon_library' ) ? esc_url_raw( rest_url( 'starter/v1/icons' ) ) : '',
			'iconsSvg' => esc_url_raw( rest_url( 'starter/v1/icons/svg/' ) ),
			// "Carica SVG": come l'upload SVG del tema, solo chi può modificare le opzioni del tema.
			'canSvg'   => function_exists( 'starter_icon_svg_parts' ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_theme_options' ),
			'fields'   => (object) array_map( array( __CLASS__, 'safe_entry' ), Starter_Live_Registry::all() ),
			'i18n'     => array(
				'open'        => __( 'Modifica contenuti', 'starter-live' ),
				'saveClose'   => __( 'Salva e chiudi', 'starter-live' ),
				'close'       => __( 'Chiudi', 'starter-live' ),
				'cancel'      => __( 'Annulla', 'starter-live' ),
				'apply'       => __( 'Applica', 'starter-live' ),
				'revert'      => __( 'Ripristina', 'starter-live' ),
				'changes'     => __( 'modifiche', 'starter-live' ),
				'change'      => __( 'modifica', 'starter-live' ),
				'noChanges'   => __( 'Nessuna modifica', 'starter-live' ),
				'saving'      => __( 'Salvataggio…', 'starter-live' ),
				'saved'       => __( 'Modifiche salvate', 'starter-live' ),
				'error'       => __( 'Non è stato possibile salvare:', 'starter-live' ),
				'discard'     => __( 'Scartare tutte le modifiche non salvate?', 'starter-live' ),
				'hint'        => __( 'Le modifiche vengono salvate quando premi «Salva e chiudi».', 'starter-live' ),
				'replaceImg'  => __( 'Sostituisci immagine', 'starter-live' ),
				'replaceFile' => __( 'Sostituisci file', 'starter-live' ),
				'chooseTitle' => __( 'Scegli dalla libreria', 'starter-live' ),
				'chooseBtn'   => __( 'Usa questo', 'starter-live' ),
				'linkText'    => __( 'Testo', 'starter-live' ),
				'linkUrl'     => __( 'Indirizzo (URL)', 'starter-live' ),
				'newTab'      => __( 'Apri in una nuova scheda', 'starter-live' ),
				'askUrl'      => __( 'Indirizzo del link', 'starter-live' ),
				'bold'        => __( 'Grassetto', 'starter-live' ),
				'underline'   => __( 'Sottolineato', 'starter-live' ),
				'italic'      => __( 'Corsivo', 'starter-live' ),
				'link'        => __( 'Link', 'starter-live' ),
				'remove'      => __( 'Rimuovi', 'starter-live' ),
				'moveLeft'    => __( 'Sposta prima', 'starter-live' ),
				'moveRight'   => __( 'Sposta dopo', 'starter-live' ),
				'addImages'   => __( 'Aggiungi foto', 'starter-live' ),
				'galleryFull' => __( 'Massimo %d foto raggiunto', 'starter-live' ),
				'galleryMax'  => __( 'Massimo %d foto. L\'anteprima si vede dopo «Salva e chiudi».', 'starter-live' ),
				'galleryMin'  => __( 'Servono almeno %d foto.', 'starter-live' ),
				'poster'       => __( 'Copertina (mostrata mentre il video carica)', 'starter-live' ),
				'replacePoster' => __( 'Sostituisci copertina', 'starter-live' ),
				'iconTitle'    => __( 'Cerca un\'icona (es. casa, telefono, instagram…)', 'starter-live' ),
				'iconAll'      => __( 'Tutte', 'starter-live' ),
				'iconLines'    => __( 'Icone', 'starter-live' ),
				'iconBrands'   => __( 'Loghi / social', 'starter-live' ),
				'iconMine'     => __( 'I miei SVG', 'starter-live' ),
				'iconUpload'   => __( 'Carica SVG', 'starter-live' ),
				'iconUploadTitle' => __( 'Carica o scegli un SVG', 'starter-live' ),
				'iconUseSvg'   => __( 'Usa questo SVG', 'starter-live' ),
				'iconNone'     => __( 'Nessuna icona', 'starter-live' ),
				'iconEmpty'    => __( 'Nessuna icona trovata.', 'starter-live' ),
				'iconLoading'  => __( 'Caricamento icone…', 'starter-live' ),
				'iconCount'    => __( 'icone', 'starter-live' ),
				'galleryTrimmed' => __( 'Puoi avere al massimo %d foto: ho aggiunto solo quelle che ci stanno.', 'starter-live' ),
				'staleMsg'    => __( 'Questa pagina è stata modificata da qualcun altro mentre la modificavi. Ricarica la pagina e rifai le modifiche.', 'starter-live' ),
				'listHint'    => __( 'Invio = nuova voce. Per toglierne una cancellane il testo e premi Backspace.', 'starter-live' ),
				'listEmpty'   => __( "L'elenco deve avere almeno una voce.", 'starter-live' ),
				'shortcodeLabel' => __( 'Shortcode', 'starter-live' ),
				'shortcodeHint'  => __( 'Per esempio [contact-form-7 id="…" title="…"]. Lascia vuoto per togliere il modulo.', 'starter-live' ),
				'chooseForm'     => __( 'Scegli un modulo di contatto…', 'starter-live' ),
				'previewLoading' => __( 'Anteprima in caricamento…', 'starter-live' ),
				'altLabel'       => __( 'Testo alternativo (per chi non vede l\'immagine e per Google)', 'starter-live' ),
				'addressLabel'   => __( 'Indirizzo', 'starter-live' ),
				'noMedia'     => __( 'Non hai il permesso di caricare file.', 'starter-live' ),
			),
		);

		// HEX_*: dentro un <script> nessun testo salvato può chiudere il tag o aprire un commento.
		wp_add_inline_script( 'starter-live', 'window.StarterLive=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/** Immagine in evidenza della pagina o dell'articolo che si sta guardando: modificabile. (Quelle nelle liste di altri contenuti restano com'erano.) */
	public static function thumbnail( $html, $post_id, $thumbnail_id ) {
		static $done = array();

		if ( '' === $html || ! self::can_edit() || ! is_singular() || ! in_the_loop() || ! is_main_query() || (int) $post_id !== self::target_post_id() || isset( $done[ $post_id ] ) ) {
			return $html;
		}

		$p = new WP_HTML_Tag_Processor( $html );

		if ( ! $p->next_tag( 'img' ) ) {
			return $html;
		}

		$done[ $post_id ] = true;
		$id               = Starter_Live_Registry::add(
			array(
				'src'   => 'post_thumbnail',
				'post'  => (int) $post_id,
				't'     => 'image',
				'media' => 'image',
				'label' => __( 'Immagine in evidenza', 'starter-live' ),
				'value' => (int) $thumbnail_id,
				'block' => '',
				'path'  => '',
			)
		);
		$p->set_attribute( 'data-live', $id );

		return $p->get_updated_html();
	}

	/** Voce nella barra admin: ultima a sinistra, con lo stesso colore del pulsante. */
	/**
	 * I valori che il plugin porta nel browser per essere modificati sono testo salvato nel database: se contengono HTML (scritto da qualcuno
	 * con pochi permessi o da un altro plugin) vengono ripuliti prima, così non possono eseguire codice nel browser di chi modifica.
	 */
	public static function safe_entry( $entry ) {
		if ( isset( $entry['value'] ) ) {
			$entry['value'] = self::safe_value( $entry['value'] );
		}

		return $entry;
	}

	private static function safe_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'safe_value' ), $value );
		}

		// Solo se sembra HTML (un testo semplice come "a < b" resta com'è).
		if ( is_string( $value ) && preg_match( '/<[a-z!\/]/i', $value ) ) {
			return wp_kses_post( $value );
		}

		return $value;
	}

	/** Moduli Contact Form 7 disponibili (titolo + shortcode), per sceglierli da un elenco quando si cambia uno shortcode. */
	private static function contact_forms() {
		$has_shortcode = false;

		foreach ( Starter_Live_Registry::all() as $field ) {
			if ( 'shortcode' === ( $field['t'] ?? '' ) ) {
				$has_shortcode = true;
				break;
			}
		}

		if ( ! $has_shortcode || ! class_exists( 'WPCF7_ContactForm' ) || ! current_user_can( 'edit_posts' ) ) {
			return array();
		}

		$forms = array();

		foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
			$form = WPCF7_ContactForm::get_instance( $post->ID );

			if ( $form ) {
				$forms[] = array(
					'title' => $post->post_title,
					'code'  => method_exists( $form, 'shortcode' ) ? $form->shortcode() : sprintf( '[contact-form-7 id="%d" title="%s"]', $post->ID, esc_attr( $post->post_title ) ),
				);
			}
		}

		return $forms;
	}

	public static function admin_bar( $bar ) {
		if ( ! self::can_edit() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'starter-live',
				'title' => esc_html__( 'Modifica contenuti', 'starter-live' ),
				'href'  => '#starter-live',
			)
		);

	}

	/**
	 * Per il tema: marca un elemento che NON è un blocco (titolo, occhiello…) come modificabile.
	 * Uso: echo apply_filters( 'starter_live_attrs', '', array( 'post' => 24, 'src' => 'meta', 'key' => 'starter_eyebrow', 'label' => 'Occhiello', 'value' => $testo ) );
	 * Senza il plugin (o per chi non può modificare) restituisce una stringa vuota.
	 */
	public static function attrs( $attrs, $args ) {
		if ( ! self::can_edit() || ! is_array( $args ) ) {
			return $attrs;
		}

		$args = wp_parse_args(
			$args,
			array(
				'post'      => 0,
				'term'      => 0,
				'tax'       => '',
				'src'       => 'meta',
				'key'       => '',
				't'         => 'text',
				'label'     => '',
				'value'     => '',
				'multiline' => false,
			)
		);

		// Marcatore di un numero (prezzo, superficie…) di una scheda con campi: lo raccoglie annotate_post_acf().
		if ( 'acfnum' === $args['src'] ) {
			return preg_match( '/^[A-Za-z0-9_]+$/', (string) $args['key'] ) ? $attrs . ' data-live-num="' . esc_attr( $args['key'] ) . '"' : $attrs;
		}

		// Marcatore di un elenco scritto in un campo di testo, una voce per riga (es. l'elenco con spunte di un servizio).
		if ( 'acflines' === $args['src'] ) {
			return preg_match( '/^[A-Za-z0-9_]+$/', (string) $args['key'] ) ? $attrs . ' data-live-lines="' . esc_attr( $args['key'] ) . '"' : $attrs;
		}

		$entry = array(
			'src'       => $args['src'],
			'post'      => 0,
			'key'       => (string) $args['key'],
			't'         => 'rich' === $args['t'] ? 'rich' : 'text',
			'label'     => (string) $args['label'],
			'value'     => (string) $args['value'],
			'multiline' => (bool) $args['multiline'],
			'block'     => '',
			'path'      => '',
		);

		if ( in_array( $args['src'], array( 'meta', 'post' ), true ) ) {
			$post_id = (int) $args['post'];

			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				return $attrs;
			}

			$entry['post'] = $post_id;
		} elseif ( in_array( $args['src'], array( 'term', 'termmeta' ), true ) ) {
			$term = get_term( (int) $args['term'], (string) $args['tax'] );

			if ( ! $term instanceof WP_Term || ! current_user_can( 'edit_term', $term->term_id ) ) {
				return $attrs;
			}

			$entry['term'] = (int) $term->term_id;
			$entry['tax']  = $term->taxonomy;
		} else {
			return $attrs;
		}

		return $attrs . ' data-live="' . esc_attr( Starter_Live_Registry::add( $entry ) ) . '"';
	}
}
