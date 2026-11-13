<?php
// This file is part of Wormiss, an iMIS bridge for WordPress.
//
// Wormiss is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Wormiss is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Wormiss.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Check a news article: ask iMIS, read only, what it gives out for one content page.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Before news articles can be imported, find out how iMIS gives out an article's text. An
 * administrator enters an article's Document Version Key; a few fixed read-only lookups are made
 * and their replies shown (and kept in the audit log). Nothing is changed in iMIS, and only a
 * key in the expected form is accepted, so nothing else can be asked of iMIS this way.
 */
class Wormiss_Content_Check {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'wormiss_content_check';

	/**
	 * The lookups made by the article's key, label => path ({key} is the article's key). The
	 * Document object holds a content page, its text in Blob; it is keyed by DocumentKey, so it is
	 * found by DocumentVersionKey first, then read by its own key.
	 */
	public const LOOKUPS = [
		'Document by version key' => '/api/Document?DocumentVersionKey={key}',
		'Document path'           => '/api/DocumentPath?DocumentVersionKey={key}',
	];

	/**
	 * The lookup by the Document's own key, once found.
	 */
	public const BY_DOCUMENT_KEY = [ 'Document by its document key' => '/api/Document/{key}' ];

	/**
	 * Longest reply or decoded text kept for showing, in characters.
	 */
	public const MAX_SHOWN = 20000;

	/**
	 * Hook the button.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/**
	 * Whether the text is an iMIS key (a GUID such as 6fdbde19-109c-43f8-b008-8b95e69f1704).
	 *
	 * @param string $key Text entered.
	 * @return bool
	 */
	public static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key );
	}

	/**
	 * Make the lookups for one article.
	 *
	 * @param string $key The article's Document Version Key.
	 * @return array[] One per lookup: label, path, ok, status, body, decoded (Blob text found).
	 */
	public static function run( string $key ): array {
		$key = strtolower( trim( $key ) );
		if ( ! self::is_key( $key ) ) {
			return [];
		}
		$client       = new Wormiss_Rest_Client();
		$results      = [];
		$document_key = '';
		foreach ( self::LOOKUPS as $label => $path ) {
			$result    = self::lookup( $client, $label, str_replace( '{key}', $key, $path ) );
			$results[] = $result;
			if ( '' === $document_key && '' !== $result['document_key'] ) {
				$document_key = $result['document_key'];
			}
		}
		if ( '' !== $document_key ) {
			foreach ( self::BY_DOCUMENT_KEY as $label => $path ) {
				$results[] = self::lookup( $client, $label, str_replace( '{key}', $document_key, $path ) );
			}
		}
		return $results;
	}

	/**
	 * One read-only lookup.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param string              $label  Label.
	 * @param string              $path   Path.
	 * @return array label, path, ok, status, body, decoded, document_key.
	 */
	private static function lookup( Wormiss_Rest_Client $client, string $label, string $path ): array {
		$result = [
			'label'        => $label,
			'path'         => $path,
			'ok'           => false,
			'status'       => '',
			'body'         => '',
			'decoded'      => [],
			'document_key' => '',
		];
		try {
			$reply            = $client->request( 'GET', $path );
			$result['ok']     = true;
			$result['status'] = 'HTTP ' . $reply->code;
			$body             = json_decode( (string) $reply->raw );
			if ( is_object( $body ) ) {
				$result['decoded']      = self::take_blobs( $body );
				$result['document_key'] = self::document_key( $body );
				$result['body']         = self::pretty( (string) wp_json_encode( $body ) );
			} else {
				$result['body'] = self::pretty( (string) $reply->raw );
			}
		} catch ( Wormiss_Exception $e ) {
			$result['status'] = $e->getMessage();
		}
		return $result;
	}

	/**
	 * The DocumentKey of the first Document in a reply (one entity or a page of them), if it is a key.
	 *
	 * @param object $body Decoded reply.
	 * @return string
	 */
	public static function document_key( object $body ): string {
		$rows = Wormiss_Rest_Client::items( $body );
		$row  = $rows ? $rows[0] : $body;
		if ( ! is_object( $row ) ) {
			return '';
		}
		$key = (string) ( Wormiss_Rest_Client::properties( $row )['DocumentKey'] ?? '' );
		return self::is_key( $key ) ? strtolower( $key ) : '';
	}

	/**
	 * Replace each Blob value in a reply with a short note, and return the Blobs decoded to text.
	 *
	 * @param mixed $node Decoded reply, changed in place.
	 * @return string[]
	 */
	public static function take_blobs( &$node ): array {
		$found = [];
		if ( is_array( $node ) ) {
			foreach ( $node as &$child ) {
				$found = array_merge( $found, self::take_blobs( $child ) );
			}
			unset( $child );
			return $found;
		}
		if ( ! is_object( $node ) ) {
			return $found;
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( isset( $node->Name ) && 'Blob' === $node->Name && isset( $node->Value ) ) {
			$value = is_object( $node->Value ) && property_exists( $node->Value, '$value' ) ? $node->Value->{'$value'} : $node->Value;
			if ( is_string( $value ) && '' !== $value ) {
				$text        = self::decode_blob( $value );
				$found[]     = $text;
				$node->Value = sprintf( '[%d characters of encoded content: decoded below]', strlen( $value ) );
			}
			// phpcs:enable
			return $found;
		}
		foreach ( get_object_vars( $node ) as $name => $child ) {
			$found       = array_merge( $found, self::take_blobs( $child ) );
			$node->$name = $child;
		}
		return $found;
	}

	/**
	 * A Blob (base 64) as readable text: unzipped if compressed, UTF-16 converted, cut to the
	 * longest shown. Binary that is not text is reported as such.
	 *
	 * @param string $value Base 64.
	 * @return string
	 */
	public static function decode_blob( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- iMIS sends binary as base 64.
		$bytes = base64_decode( $value, true );
		if ( false === $bytes ) {
			return __( '(Not base 64: shown as sent.)', 'wormiss' ) . "\n" . self::cut( $value );
		}
		if ( str_starts_with( $bytes, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) {
			$unzipped = @gzdecode( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not compressed is an answer.
			$bytes    = false === $unzipped ? $bytes : $unzipped;
		} elseif ( ! mb_check_encoding( $bytes, 'UTF-8' ) && function_exists( 'gzinflate' ) ) {
			$inflated = @gzinflate( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not compressed is an answer.
			$bytes    = false === $inflated ? $bytes : $inflated;
		}
		if ( str_starts_with( $bytes, "\xff\xfe" ) ) {
			$bytes = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( str_starts_with( $bytes, "\xef\xbb\xbf" ) ) {
			$bytes = substr( $bytes, 3 );
		}
		if ( ! mb_check_encoding( $bytes, 'UTF-8' ) ) {
			return sprintf(
				/* translators: %d: number of bytes. */
				__( '(%d bytes that are not text; the first ones in hex follow.)', 'wormiss' ),
				strlen( $bytes )
			) . "\n" . bin2hex( substr( $bytes, 0, 64 ) );
		}
		return self::cut( $bytes );
	}

	/**
	 * A reply laid out for reading, cut to the longest shown.
	 *
	 * @param string $raw Reply body.
	 * @return string
	 */
	public static function pretty( string $raw ): string {
		$decoded = json_decode( $raw );
		if ( null !== $decoded ) {
			$raw = (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		return self::cut( $raw );
	}

	/**
	 * Text cut to the longest shown.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cut( string $text ): string {
		if ( mb_strlen( $text ) > self::MAX_SHOWN ) {
			$text = mb_substr( $text, 0, self::MAX_SHOWN ) . "\n…";
		}
		return $text;
	}

	/**
	 * Handle the button: administrators only, then show the replies once.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$key = isset( $_POST['wormiss_content_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wormiss_content_key'] ) ) : '';
		if ( ! self::is_key( trim( $key ) ) ) {
			$result = [ 'error' => __( 'Enter a Document Version Key, such as 6fdbde19-109c-43f8-b008-8b95e69f1704.', 'wormiss' ) ];
		} elseif ( ! Wormiss_Settings::is_configured() ) {
			$result = [ 'error' => __( 'Fill in the iMIS address, username and password first.', 'wormiss' ) ];
		} else {
			$result = [
				'key'     => strtolower( trim( $key ) ),
				'lookups' => self::run( $key ),
			];
		}
		set_transient( self::transient(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-content-check' );
		exit;
	}

	/**
	 * Where this administrator's last result is kept.
	 *
	 * @return string
	 */
	private static function transient(): string {
		return 'wormiss_content_check_' . get_current_user_id();
	}

	/**
	 * The section on the settings page: the form, then the last result (shown once).
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<h2 id="wormiss-content-check">' . esc_html__( 'Check a news article', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Shows what iMIS gives out for one news article, to plan the news import. Enter the article\'s', 'wormiss' )
			. ' ' . esc_html__( 'Document Version Key (from the news IQA). Nothing is changed in iMIS; the replies are also kept', 'wormiss' )
			. ' ' . esc_html__( 'in the audit log.', 'wormiss' ) . '</p>';

		$result = get_transient( self::transient() );
		if ( is_array( $result ) ) {
			delete_transient( self::transient() );
			self::render_result( $result );
		}

		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="wormiss-content-key">' . esc_html__( 'Document Version Key', 'wormiss' ) . '</label> ';
		echo '<input type="text" class="regular-text code" id="wormiss-content-key" name="wormiss_content_key" /> ';
		submit_button( __( 'Check article', 'wormiss' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}

	/**
	 * Show one result.
	 *
	 * @param array $result Error, or key and lookups.
	 * @return void
	 */
	private static function render_result( array $result ): void {
		if ( isset( $result['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $result['error'] ) . '</p></div>';
			return;
		}
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: %s: the article's key. */
				__( 'iMIS replies for %s:', 'wormiss' ),
				(string) ( $result['key'] ?? '' )
			)
		) . '</strong></p>';
		foreach ( (array) ( $result['lookups'] ?? [] ) as $lookup ) {
			echo '<h3>' . esc_html( (string) $lookup['label'] ) . '</h3>';
			echo '<p><code>GET ' . esc_html( (string) $lookup['path'] ) . '</code> — '
				. esc_html( (string) $lookup['status'] ) . '</p>';
			if ( '' !== (string) $lookup['body'] ) {
				echo '<textarea class="large-text code" rows="12" readonly>' . esc_textarea( (string) $lookup['body'] ) . '</textarea>';
			}
			foreach ( (array) ( $lookup['decoded'] ?? [] ) as $text ) {
				echo '<p>' . esc_html__( 'Content (Blob) decoded:', 'wormiss' ) . '</p>';
				echo '<textarea class="large-text code" rows="16" readonly>' . esc_textarea( (string) $text ) . '</textarea>';
			}
		}
	}
}
