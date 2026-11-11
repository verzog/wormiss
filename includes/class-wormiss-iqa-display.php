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
 * Shows the results of an approved iMIS IQA query on a page (block and shortcode).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches (with caching) and renders IQA results as a table, cards or a list.
 */
class Wormiss_Iqa_Display {

	/** How long the last good copy is kept, to show while iMIS is unavailable. */
	const STALE_SECONDS = WEEK_IN_SECONDS;

	/** Most rows shown by one block. */
	const MAX_LIMIT = 100;

	/**
	 * Default display options.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'query'      => '',
			'columns'    => '',
			'parameters' => '',
			'layout'     => 'table',
			'limit'      => 20,
			'link'       => '',
			'empty_text' => '',
		];
	}

	/**
	 * The [wormiss_iqa] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		return self::render( shortcode_atts( self::defaults(), is_array( $atts ) ? $atts : [], 'wormiss_iqa' ) );
	}

	/**
	 * Render the block or shortcode.
	 *
	 * @param array $args Display options (see defaults()).
	 * @return string HTML.
	 */
	public static function render( array $args ): string {
		$args   = array_merge( self::defaults(), $args );
		$query  = trim( (string) $args['query'] );
		$layout = in_array( $args['layout'], [ 'table', 'cards', 'list' ], true ) ? $args['layout'] : 'table';
		$limit  = min( self::MAX_LIMIT, max( 1, (int) $args['limit'] ) );

		if ( '' === $query ) {
			return self::editor_notice( __( 'Choose an iMIS query in the block settings.', 'wormiss' ) );
		}
		if ( ! Wormiss_Settings::is_allowed_query( $query ) ) {
			// Only queries an administrator has approved may be shown, whatever a page asks for.
			return self::editor_notice( __( 'This iMIS query is not on the approved list (Settings > iMIS Bridge).', 'wormiss' ) );
		}

		try {
			$rows = self::get_rows( $query, self::parse_parameters( (string) $args['parameters'] ) );
		} catch ( Wormiss_Exception $e ) {
			return self::unavailable( $e->getMessage() );
		}

		$rows = array_slice( $rows, 0, $limit );
		if ( ! $rows ) {
			$empty = '' !== trim( (string) $args['empty_text'] ) ? (string) $args['empty_text'] : __( 'Nothing to show yet.', 'wormiss' );
			return '<div class="wormiss-iqa wormiss-iqa--empty"><p>' . esc_html( $empty ) . '</p></div>';
		}

		$columns = self::parse_columns( (string) $args['columns'], $rows[0] );
		$link    = (string) $args['link'];
		switch ( $layout ) {
			case 'cards':
				return self::render_cards( $rows, $columns, $link );
			case 'list':
				return self::render_list( $rows, $columns, $link );
			default:
				return self::render_table( $rows, $columns, $link );
		}
	}

	/**
	 * Rows for a query: the cached copy, a fresh read, or the last good copy if iMIS fails.
	 *
	 * @param string   $query      IQA path.
	 * @param string[] $parameters Prompt values.
	 * @return array[]
	 * @throws Wormiss_Exception If iMIS fails and no earlier copy exists.
	 */
	public static function get_rows( string $query, array $parameters = [] ): array {
		$key    = 'wormiss_iqa_' . md5( $query . "\0" . wp_json_encode( $parameters ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$rows = ( new Wormiss_Rest_Client() )->run_iqa( $query, $parameters );
		} catch ( Wormiss_Exception $e ) {
			$stale = get_transient( $key . '_stale' );
			if ( is_array( $stale ) ) {
				return $stale;
			}
			throw $e;
		}
		$minutes = max( 1, (int) Wormiss_Settings::get( 'cache_minutes' ) );
		set_transient( $key, $rows, $minutes * MINUTE_IN_SECONDS );
		set_transient( $key . '_stale', $rows, self::STALE_SECONDS );
		return $rows;
	}

	/**
	 * Prompt values from "value1 | value2" (pipe-separated, so values may contain commas).
	 *
	 * @param string $text Raw parameters.
	 * @return string[]
	 */
	public static function parse_parameters( string $text ): array {
		if ( '' === trim( $text ) ) {
			return [];
		}
		return array_map( 'trim', explode( '|', $text ) );
	}

	/**
	 * Columns to show from "Label=Property, Property2"; every property of the first row if blank.
	 *
	 * Properties not in the results are dropped, so a typo cannot show an empty column.
	 *
	 * @param string $text  Raw column list.
	 * @param array  $first First result row.
	 * @return array Property name => heading label.
	 */
	public static function parse_columns( string $text, array $first ): array {
		$columns = [];
		if ( '' === trim( $text ) ) {
			foreach ( array_keys( $first ) as $name ) {
				if ( 0 !== strpos( (string) $name, '$' ) ) {
					$columns[ (string) $name ] = (string) $name;
				}
			}
			return $columns;
		}
		foreach ( explode( ',', $text ) as $item ) {
			$parts    = array_map( 'trim', explode( '=', $item, 2 ) );
			$property = $parts[1] ?? $parts[0];
			$label    = isset( $parts[1] ) && '' !== $parts[0] ? $parts[0] : $property;
			if ( '' !== $property && array_key_exists( $property, $first ) ) {
				$columns[ $property ] = $label;
			}
		}
		return $columns;
	}

	/**
	 * A value as display text: dates in the site's format, booleans as Yes/No.
	 *
	 * @param mixed $value Raw value.
	 * @return string Plain text (not yet escaped).
	 */
	public static function format_value( $value ): string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'wormiss' ) : __( 'No', 'wormiss' );
		}
		$value = (string) $value;
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(:\d{2}(\.\d+)?)?$/', $value, $m ) ) {
			// IQA dates carry no time zone: read them as the site's local time.
			$date = date_create_immutable( $m[1] . ' ' . $m[2], wp_timezone() );
			if ( $date ) {
				$format = get_option( 'date_format' );
				if ( '00:00' !== $m[2] ) {
					$format .= ' ' . get_option( 'time_format' );
				}
				return (string) wp_date( $format, $date->getTimestamp() );
			}
		}
		return $value;
	}

	/**
	 * A row's link from a template like https://example.org/Event?id={EventKey}, or ''.
	 *
	 * @param string $template URL with {Property} placeholders.
	 * @param array  $row      Result row.
	 * @return string Escaped http(s) URL, or '' when there is no usable link.
	 */
	public static function row_link( string $template, array $row ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}
		$url = preg_replace_callback(
			'/\{([A-Za-z0-9_]+)\}/',
			static function ( $m ) use ( $row ) {
				$value = $row[ $m[1] ] ?? '';
				return is_scalar( $value ) ? rawurlencode( (string) $value ) : '';
			},
			$template
		);
		return esc_url( (string) $url, [ 'http', 'https' ] );
	}

	/**
	 * Results as a table; the first column links when a link template is set.
	 *
	 * @param array[] $rows    Rows.
	 * @param array   $columns Property => label.
	 * @param string  $link    Link template.
	 * @return string
	 */
	private static function render_table( array $rows, array $columns, string $link ): string {
		$html = '<div class="wormiss-iqa wormiss-iqa--table"><table><thead><tr>';
		foreach ( $columns as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			$first = true;
			foreach ( array_keys( $columns ) as $property ) {
				$text  = esc_html( self::format_value( $row[ $property ] ?? null ) );
				$html .= '<td>' . ( $first ? self::maybe_link( $text, self::row_link( $link, $row ) ) : $text ) . '</td>';
				$first = false;
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * Results as cards: the first column as the heading, the rest as a description list.
	 *
	 * @param array[] $rows    Rows.
	 * @param array   $columns Property => label.
	 * @param string  $link    Link template.
	 * @return string
	 */
	private static function render_cards( array $rows, array $columns, string $link ): string {
		$properties = array_keys( $columns );
		$title      = array_shift( $properties );
		$html       = '<ul class="wormiss-iqa wormiss-iqa--cards">';
		foreach ( $rows as $row ) {
			$heading = esc_html( self::format_value( $row[ $title ] ?? null ) );
			$html   .= '<li class="wormiss-iqa__card"><h3 class="wormiss-iqa__title">'
				. self::maybe_link( $heading, self::row_link( $link, $row ) ) . '</h3>';
			if ( $properties ) {
				$html .= '<dl>';
				foreach ( $properties as $property ) {
					$html .= '<dt>' . esc_html( $columns[ $property ] ) . '</dt><dd>'
						. esc_html( self::format_value( $row[ $property ] ?? null ) ) . '</dd>';
				}
				$html .= '</dl>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Results as a simple list: the first column (linked), then the rest separated by dots.
	 *
	 * @param array[] $rows    Rows.
	 * @param array   $columns Property => label.
	 * @param string  $link    Link template.
	 * @return string
	 */
	private static function render_list( array $rows, array $columns, string $link ): string {
		$properties = array_keys( $columns );
		$title      = array_shift( $properties );
		$html       = '<ul class="wormiss-iqa wormiss-iqa--list">';
		foreach ( $rows as $row ) {
			$main  = esc_html( self::format_value( $row[ $title ] ?? null ) );
			$extra = array_filter(
				array_map(
					static fn( $p ) => self::format_value( $row[ $p ] ?? null ),
					$properties
				),
				'strlen'
			);
			$html .= '<li>' . self::maybe_link( $main, self::row_link( $link, $row ) );
			if ( $extra ) {
				$html .= ' <span class="wormiss-iqa__meta">' . esc_html( implode( ' · ', $extra ) ) . '</span>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Wrap already-escaped text in a link when there is a URL.
	 *
	 * @param string $text Escaped text.
	 * @param string $url  Escaped URL or ''.
	 * @return string
	 */
	private static function maybe_link( string $text, string $url ): string {
		return '' === $url || '' === $text ? $text : '<a href="' . $url . '">' . $text . '</a>';
	}

	/**
	 * What visitors see when iMIS cannot be read; editors also see the reason.
	 *
	 * @param string $reason Short failure reason.
	 * @return string
	 */
	private static function unavailable( string $reason ): string {
		$html = '<div class="wormiss-iqa wormiss-iqa--unavailable"><p>'
			. esc_html__( "This information isn't available right now. Please try again later.", 'wormiss' ) . '</p>';
		if ( current_user_can( 'edit_posts' ) ) {
			/* translators: %s: reason from iMIS. */
			$note  = sprintf( __( 'Shown to editors only: %s', 'wormiss' ), $reason );
			$html .= '<p class="wormiss-iqa__reason"><small>' . esc_html( $note ) . '</small></p>';
		}
		return $html . '</div>';
	}

	/**
	 * A set-up message shown only to editors (visitors see nothing).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function editor_notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<div class="wormiss-iqa wormiss-iqa--notice"><p>' . esc_html( $message ) . '</p></div>';
	}
}
