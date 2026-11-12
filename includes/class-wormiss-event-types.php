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
 * Event types: each has its own iMIS template event and, optionally, an iMIS event category.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Event types taxonomy and its iMIS settings.
 */
class Wormiss_Event_Types {

	/** Taxonomy. */
	const TAXONOMY = 'wormiss_event_type';

	/** Term meta: the iMIS event a new event of this type is copied from. */
	const META_TEMPLATE = 'wormiss_template_id';

	/** Term meta: the iMIS event category (EventCategoryId) given to events of this type. */
	const META_CATEGORY = 'wormiss_category_id';

	/** Term meta: default capacity for events of this type (0 for none). */
	const META_CAPACITY = 'wormiss_capacity';

	/** Term meta: what happened the last time the type's category was created in iMIS. */
	const META_CATEGORY_STATUS = 'wormiss_category_status';

	/** Form field: create the category in iMIS when the type is saved. */
	const FIELD_CREATE = 'wormiss_create_category';

	/** Longest iMIS category ID made from a type's name. */
	const CATEGORY_ID_LENGTH = 10;

	/** Nonce action for the type form fields. */
	const NONCE = 'wormiss_event_type_fields';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ], 9 );
		add_action( self::TAXONOMY . '_add_form_fields', [ __CLASS__, 'add_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ __CLASS__, 'edit_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ __CLASS__, 'save_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ __CLASS__, 'save_fields' ] );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ __CLASS__, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ __CLASS__, 'column' ], 10, 3 );
	}

	/**
	 * Register the taxonomy (before the Events post type, which lists it) and its term meta.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			'wormiss_event',
			[
				'labels'            => [
					'name'          => __( 'Event types', 'wormiss' ),
					'singular_name' => __( 'Event type', 'wormiss' ),
					'add_new_item'  => __( 'Add New Event Type', 'wormiss' ),
					'edit_item'     => __( 'Edit Event Type', 'wormiss' ),
					'search_items'  => __( 'Search Event Types', 'wormiss' ),
					'not_found'     => __( 'No event types found.', 'wormiss' ),
					'all_items'     => __( 'All Event Types', 'wormiss' ),
					'menu_name'     => __( 'Event types', 'wormiss' ),
				],
				// Hierarchical for the checkbox list in the editor (types are not meant to nest).
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => [ 'slug' => 'event-type' ],
			]
		);
		register_term_meta(
			self::TAXONOMY,
			self::META_CAPACITY,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => min( 100000, absint( $value ) ),
				'auth_callback'     => static fn( $allowed, $meta_key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
			]
		);
		foreach ( [ self::META_TEMPLATE, self::META_CATEGORY ] as $key ) {
			register_term_meta(
				self::TAXONOMY,
				$key,
				[
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => [ __CLASS__, 'clean_id' ],
					'auth_callback'     => static fn( $allowed, $meta_key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
				]
			);
		}
	}

	/**
	 * An iMIS event or category ID: letters, digits, _ and -, upper case.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' if it is not usable.
	 */
	public static function clean_id( $value ): string {
		$id = strtoupper( trim( (string) $value ) );
		return Wormiss_Rest_Client::is_event_id( $id ) ? $id : '';
	}

	/**
	 * The iMIS template and category for an event, from its type, falling back to the default
	 * template in Settings.
	 *
	 * @param int $post_id Event post ID.
	 * @return array{template: string, category: string, type: string, problem: string}
	 */
	public static function for_event( int $post_id ): array {
		$out   = [
			'template' => (string) Wormiss_Settings::get( 'event_template_id' ),
			'category' => '',
			'capacity' => 0,
			'type'     => '',
			'problem'  => '',
		];
		$terms = get_the_terms( $post_id, self::TAXONOMY );
		if ( ! is_array( $terms ) || ! $terms ) {
			return $out;
		}
		if ( count( $terms ) > 1 ) {
			$out['problem'] = __( 'Choose only one event type: each type has its own iMIS template.', 'wormiss' );
			return $out;
		}
		$term            = reset( $terms );
		$out['type']     = $term->name;
		$template        = (string) get_term_meta( $term->term_id, self::META_TEMPLATE, true );
		$out['template'] = '' !== $template ? $template : $out['template'];
		$out['category'] = (string) get_term_meta( $term->term_id, self::META_CATEGORY, true );
		$out['capacity'] = (int) get_term_meta( $term->term_id, self::META_CAPACITY, true );
		return $out;
	}

	/**
	 * The form fields on the Add Event Type screen.
	 *
	 * @return void
	 */
	public static function add_fields(): void {
		wp_nonce_field( self::NONCE, 'wormiss_event_type_nonce' );
		foreach ( self::fields() as $key => [ $label, $help ] ) {
			printf(
				'<div class="form-field"><label for="%1$s">%2$s</label>'
					. '<input type="text" class="code" id="%1$s" name="%1$s" value="" /><p>%3$s</p></div>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_html( $help )
			);
		}
		printf(
			'<div class="form-field"><label for="%1$s">%2$s</label>'
				. '<input type="number" min="0" class="small-text" id="%1$s" name="%1$s" value="" /><p>%3$s</p></div>',
			esc_attr( self::META_CAPACITY ),
			esc_html__( 'Default capacity', 'wormiss' ),
			esc_html( self::capacity_help() )
		);
		printf(
			'<div class="form-field"><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label><p>%3$s</p></div>',
			esc_attr( self::FIELD_CREATE ),
			esc_html__( 'Create this category in iMIS when saved', 'wormiss' ),
			esc_html( self::create_help() )
		);
	}

	/**
	 * The form fields on the Edit Event Type screen.
	 *
	 * @param WP_Term $term Term.
	 * @return void
	 */
	public static function edit_fields( $term ): void {
		foreach ( self::fields() as $key => [ $label, $help ] ) {
			printf(
				'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td>'
					. '<input type="text" class="code" id="%1$s" name="%1$s" value="%3$s" />'
					. '<p class="description">%4$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) get_term_meta( $term->term_id, $key, true ) ),
				esc_html( $help )
			);
		}
		$capacity = (int) get_term_meta( $term->term_id, self::META_CAPACITY, true );
		printf(
			'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td>'
				. '<input type="number" min="0" class="small-text" id="%1$s" name="%1$s" value="%3$s" />'
				. '<p class="description">%4$s</p></td></tr>',
			esc_attr( self::META_CAPACITY ),
			esc_html__( 'Default capacity', 'wormiss' ),
			esc_attr( $capacity > 0 ? (string) $capacity : '' ),
			esc_html( self::capacity_help() )
		);
		$status = (string) get_term_meta( $term->term_id, self::META_CATEGORY_STATUS, true );
		printf(
			'<tr class="form-field"><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s" value="1" /> %3$s</label>'
				. '<p class="description">%4$s</p>%5$s</td></tr>',
			esc_html__( 'iMIS category', 'wormiss' ),
			esc_attr( self::FIELD_CREATE ),
			esc_html__( 'Create this category in iMIS when saved', 'wormiss' ),
			esc_html( self::create_help() ),
			'' !== $status ? '<p><strong>' . esc_html__( 'Last result:', 'wormiss' ) . '</strong> ' . esc_html( $status ) . '</p>' : ''
		);
		echo '<tr class="hidden"><td>';
		wp_nonce_field( self::NONCE, 'wormiss_event_type_nonce' );
		echo '</td></tr>';
	}

	/**
	 * Help for the default capacity.
	 *
	 * @return string
	 */
	private static function capacity_help(): string {
		return __( 'Places for events of this type when an event leaves its own Capacity at 0. Blank keeps the template\'s.', 'wormiss' );
	}

	/**
	 * Help for creating the category.
	 *
	 * @return string
	 */
	private static function create_help(): string {
		return __( 'Makes the iMIS event category if it does not exist yet, named after this type.', 'wormiss' )
			. ' ' . __( 'With no category ID, one is made from the name (e.g. WORKSHOP).', 'wormiss' )
			. ' ' . __( 'Uses the event sending switch and dry run in Wormiss > Settings.', 'wormiss' );
	}

	/**
	 * The two fields: meta key => [label, help].
	 *
	 * @return array
	 */
	private static function fields(): array {
		return [
			self::META_TEMPLATE => [
				__( 'iMIS template event ID', 'wormiss' ),
				__( 'New events of this type are copied from this iMIS event. Blank uses the default template in Settings.', 'wormiss' ),
			],
			self::META_CATEGORY => [
				__( 'iMIS event category ID', 'wormiss' ),
				__( 'Events of this type get this iMIS event category each time they are sent. Blank keeps the one in iMIS.', 'wormiss' ),
			],
		];
	}

	/**
	 * Save the fields when a type is added or edited.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_fields( $term_id ): void {
		$nonce = isset( $_POST['wormiss_event_type_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['wormiss_event_type_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_term', (int) $term_id ) ) {
			return;
		}
		foreach ( array_keys( self::fields() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = self::clean_id( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				update_term_meta( (int) $term_id, $key, $value );
			}
		}
		if ( isset( $_POST[ self::META_CAPACITY ] ) ) {
			update_term_meta( (int) $term_id, self::META_CAPACITY, min( 100000, absint( wp_unslash( $_POST[ self::META_CAPACITY ] ) ) ) );
		}
		if ( ! empty( $_POST[ self::FIELD_CREATE ] ) ) {
			self::create_category( (int) $term_id );
		}
	}

	/**
	 * Create the type's event category in iMIS, if it is not there yet. The result is kept on
	 * the type and shown on its edit screen.
	 *
	 * @param int $term_id Term ID.
	 * @return string Result: created, exists, dry_run, off, invalid or error.
	 */
	public static function create_category( int $term_id ): string {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term instanceof WP_Term ) {
			return 'invalid';
		}
		$category = (string) get_term_meta( $term_id, self::META_CATEGORY, true );
		if ( '' === $category ) {
			$category = self::category_id_from_name( $term->name );
			if ( '' !== $category ) {
				update_term_meta( $term_id, self::META_CATEGORY, $category );
			}
		}
		[ $result, $message ] = self::send_category( $category, $term );
		update_term_meta( $term_id, self::META_CATEGORY_STATUS, $message );
		return $result;
	}

	/**
	 * Check for, and create, the category in iMIS.
	 *
	 * @param string  $category EventCategoryId.
	 * @param WP_Term $term     Type.
	 * @return string[] [result, message].
	 */
	private static function send_category( string $category, WP_Term $term ): array {
		if ( '' === $category ) {
			return [ 'invalid', __( 'Add an iMIS event category ID (letters, digits, _ and -).', 'wormiss' ) ];
		}
		if ( ! Wormiss_Settings::get( 'event_sync_enabled' ) || ! Wormiss_Settings::is_configured() ) {
			return [ 'off', __( 'Sending events to iMIS is off (Wormiss > Settings), so nothing was created.', 'wormiss' ) ];
		}
		$client = new Wormiss_Rest_Client();
		try {
			if ( $client->event_category_exists( $category ) ) {
				/* translators: %s: category ID. */
				return [ 'exists', sprintf( __( 'Category %s is already in iMIS.', 'wormiss' ), $category ) ];
			}
			$description = wp_strip_all_tags( (string) $term->description );
			if ( Wormiss_Settings::get( 'event_sync_dry_run' ) ) {
				Wormiss_Logger::record_call(
					'DRY RUN POST /api/EventCategory (' . $category . ')',
					Wormiss_Logger::STATUS_SUCCESS,
					null,
					(string) wp_json_encode( Wormiss_Rest_Client::event_category_body( $category, $term->name, $description ) )
				);
				/* translators: %s: category ID. */
				return [ 'dry_run', sprintf( __( 'Dry run: category %s would have been created. Nothing was sent.', 'wormiss' ), $category ) ];
			}
			$client->create_event_category( $category, $term->name, $description );
		} catch ( Wormiss_Exception $e ) {
			return [ 'error', $e->getMessage() ];
		}
		/* translators: %s: category ID. */
		return [ 'created', sprintf( __( 'Category %s was created in iMIS.', 'wormiss' ), $category ) ];
	}

	/**
	 * An iMIS category ID made from a type's name: letters and digits, upper case, up to 10.
	 *
	 * @param string $name Type name.
	 * @return string
	 */
	public static function category_id_from_name( string $name ): string {
		$id = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', remove_accents( $name ) ) );
		return substr( $id, 0, self::CATEGORY_ID_LENGTH );
	}

	/**
	 * Add the template column to the Event types list.
	 *
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public static function columns( array $columns ): array {
		$columns[ self::META_TEMPLATE ] = __( 'iMIS template', 'wormiss' );
		return $columns;
	}

	/**
	 * The template column's value.
	 *
	 * @param string $content Column content.
	 * @param string $column  Column name.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function column( $content, $column, $term_id ): string {
		if ( self::META_TEMPLATE !== $column ) {
			return (string) $content;
		}
		$template = (string) get_term_meta( (int) $term_id, self::META_TEMPLATE, true );
		return '' !== $template ? esc_html( $template ) : esc_html__( 'Default', 'wormiss' );
	}
}
