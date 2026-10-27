<?php
/**
 * Aggiornamenti di Starter Live da GitHub: WordPress mostra la notifica classica (Bacheca → Aggiornamenti, pagina Plugin)
 * quando l'ultima Release del repository ha una versione più alta di quella installata.
 * Il tag della release (es. v1.2.0) è la versione; il file starter-live.zip allegato alla release è il pacchetto.
 *
 * Unico contatto con l'esterno del plugin. Si spegne con define( 'STARTER_LIVE_UPDATE_REPO', '' ); in wp-config.php
 * oppure con il filtro starter_live_update_repo. Per un repository privato: define( 'STARTER_GITHUB_TOKEN', '...' ) (token di sola lettura).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_Updater {

	const SLUG    = 'starter-live';
	const CACHE   = 'starter_live_update_release';
	const DEFAULT_REPO = 'BurOrub/StarterLiveWP';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'authorize' ), 10, 2 );
		add_action( 'load-update-core.php', array( __CLASS__, 'force_check' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	/** Repository "utente/repo" già ripulito; stringa vuota = nessun controllo. */
	public static function repo() {
		$repo = defined( 'STARTER_LIVE_UPDATE_REPO' ) ? STARTER_LIVE_UPDATE_REPO : self::DEFAULT_REPO;
		$repo = trim( (string) apply_filters( 'starter_live_update_repo', $repo ) );

		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/** Percorso del plugin come lo conosce WordPress (cartella/file.php). */
	public static function basename() {
		return plugin_basename( STARTER_LIVE_DIR . 'starter-live.php' );
	}

	/** Ultima release: array( version, package, url, notes ) oppure false. In cache 6 ore (30 minuti se GitHub non risponde). */
	public static function latest_release() {
		$repo = self::repo();

		if ( '' === $repo ) {
			return false;
		}

		$cached = get_transient( self::CACHE );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false;
		}

		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => self::SLUG . '-updater',
			),
		);

		if ( defined( 'STARTER_GITHUB_TOKEN' ) && STARTER_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'Bearer ' . STARTER_GITHUB_TOKEN;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', $args );
		$data     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			? null
			: json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE, 'errore', 30 * MINUTE_IN_SECONDS );

			return false;
		}

		// Pacchetto: il .zip allegato alla release (cartella pulita); se manca, lo zip automatico di GitHub.
		$package = '';

		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::SLUG . '.zip' === $asset['name'] ) {
				$package = defined( 'STARTER_GITHUB_TOKEN' ) && STARTER_GITHUB_TOKEN && ! empty( $asset['url'] ) ? $asset['url'] : $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $package && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}

		// Il pacchetto deve stare su GitHub: niente indirizzi di altri siti, qualunque cosa risponda l'API.
		if ( ! in_array( wp_parse_url( $package, PHP_URL_HOST ), array( 'github.com', 'api.github.com', 'codeload.github.com' ), true ) ) {
			$package = '';
		}

		$release = array(
			'version' => ltrim( (string) $data['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => ! empty( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : 'https://github.com/' . $repo . '/releases',
			'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
		);

		set_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}

	/** Mette la release nell'elenco degli aggiornamenti di WordPress: notifica se è più nuova, altrimenti "aggiornato". */
	public static function apply_release( $transient, $release, $current ) {
		$basename = self::basename();
		$item     = (object) array(
			'id'          => 'github.com/' . self::repo(),
			'slug'        => self::SLUG,
			'plugin'      => $basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);

		if ( version_compare( $release['version'], $current, '>' ) ) {
			$transient->response[ $basename ] = $item;
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update[ $basename ] = $item;
			unset( $transient->response[ $basename ] );
		}

		return $transient;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Copia di sviluppo (c'è .git): l'aggiornamento sostituirebbe la cartella e cancellerebbe il repository.
		if ( is_dir( dirname( __DIR__ ) . '/.git' ) ) {
			return $transient;
		}

		$current = isset( $transient->checked[ self::basename() ] ) ? (string) $transient->checked[ self::basename() ] : '';
		$release = self::latest_release();

		if ( '' === $current || ! $release || '' === $release['version'] ) {
			return $transient;
		}

		return self::apply_release( $transient, $release, $current );
	}

	/** Finestra "Dettagli versione" della pagina Plugin. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Starter Live',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Denis Burani',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '' !== $release['notes'] ? nl2br( esc_html( $release['notes'] ) ) : esc_html__( 'Vedi la pagina della release su GitHub.', 'starter-live' ),
			),
		);
	}

	/** Lo zip automatico di GitHub si estrae in "utente-repo-hash": lo rinomina nella cartella del plugin, altrimenti WordPress ne installerebbe uno nuovo. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || basename( untrailingslashit( $source ) ) === self::SLUG ) {
			return $source;
		}

		global $wp_filesystem;

		$target = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return $target;
		}

		return new WP_Error( 'starter_live_update_rename', __( 'Impossibile preparare la cartella del plugin aggiornato.', 'starter-live' ) );
	}

	/** Repository privato: il download del pacchetto porta il token (e chiede il file, non la pagina della release). */
	public static function authorize( $args, $url ) {
		$repo = self::repo();

		if ( '' === $repo || ! defined( 'STARTER_GITHUB_TOKEN' ) || ! STARTER_GITHUB_TOKEN || 0 !== strpos( $url, 'https://api.github.com/repos/' . $repo . '/' ) ) {
			return $args;
		}

		$args['headers']['Authorization'] = 'Bearer ' . STARTER_GITHUB_TOKEN;

		if ( false !== strpos( $url, '/releases/assets/' ) || false !== strpos( $url, '/zipball/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/** "Controlla di nuovo" in Bacheca → Aggiornamenti ignora la cache. */
	public static function force_check() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( self::CACHE );
		}
	}

	/** Dopo un aggiornamento di plugin si scarta la cache, così la notifica sparisce subito. */
	public static function after_upgrade( $upgrader, $hook_extra ) {
		if ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] ) {
			delete_transient( self::CACHE );
		}
	}
}
