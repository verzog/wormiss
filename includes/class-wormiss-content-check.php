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
	 * The lookups made, label => path ({key} is the article's key).
	 */
	public const LOOKUPS = [
		'Content items by content key' => '/api/ContentItem?ContentKey={key}',
		'Content by key'               => '/api/Content/{key}',
		'Document by key'              => '/api/Document/{key}',
	];

	/**
	 * Longest reply kept for showing, in characters.
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
	 * @return array[] One per lookup: label, path, ok, status, body.
	 */
	public static function run( string $key ): array {
		$key = strtolower( trim( $key ) );
		if ( ! self::is_key( $key ) ) {
			return [];
		}
		$client  = new Wormiss_Rest_Client();
		$results = [];
		foreach ( self::LOOKUPS as $label => $path ) {
			$path = str_replace( '{key}', $key, $path );
			try {
				$reply  = $client->request( 'GET', $path );
				$result = [
					'ok'     => true,
					'status' => 'HTTP ' . $reply->code,
					'body'   => self::pretty( (string) $reply->raw ),
				];
			} catch ( Wormiss_Exception $e ) {
				$result = [
					'ok'     => false,
					'status' => $e->getMessage(),
					'body'   => '',
				];
			}
			$results[] = array_merge(
				[
					'label' => $label,
					'path'  => $path,
				],
				$result
			);
		}
		return $results;
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
		if ( mb_strlen( $raw ) > self::MAX_SHOWN ) {
			$raw = mb_substr( $raw, 0, self::MAX_SHOWN ) . "\n…";
		}
		return $raw;
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
		}
	}
}
