<?php
/** Elenco dei contenuti modificabili trovati durante il render della pagina (inviato al JS nel footer). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Starter_Live_Registry {

	/** @var array<string,array> */
	private static $fields = array();

	/** Registra un campo e restituisce il suo id (da mettere in data-live). */
	public static function add( array $entry ) {
		$id            = 'p' . ( count( self::$fields ) + 1 );
		self::$fields[ $id ] = $entry;

		return $id;
	}

	/** Aggiunge dati a una voce già registrata. */
	public static function update( $id, array $extra ) {
		if ( isset( self::$fields[ $id ] ) ) {
			self::$fields[ $id ] = $extra + self::$fields[ $id ];
		}
	}

	/** Toglie una voce (es. le singole voci di un elenco che si modifica come blocco unico). */
	public static function remove( $id ) {
		unset( self::$fields[ $id ] );
	}

	public static function all() {
		return self::$fields;
	}
}
