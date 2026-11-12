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
 * Events written in WordPress and created or updated in iMIS from a template event.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Event post type, its details, and sending it to iMIS in the background.
 *
 * A new event is made by copying the template event in iMIS (CloneEvent, which brings its
 * prices, registration options and program items), then saving the WordPress details over
 * the copy (PUT). Later saves update that event. In a dry run, iMIS is only asked to check
 * the event (_validate); nothing is created or changed.
 */
class Wormiss_Event_Sync {

	/** Post type. */
	const POST_TYPE = 'wormiss_event';

	/** Cron hook that sends one event. */
	const SYNC_HOOK = 'wormiss_sync_event';

	/** Cron hook that cancels one event in iMIS. */
	const CANCEL_HOOK = 'wormiss_cancel_event';

	/** Event code (also the new event's ID in iMIS). */
	const META_CODE = '_wormiss_event_code';

	/** Start, as YYYY-MM-DDTHH:MM in the site's time zone. */
	const META_START = '_wormiss_event_start';

	/** End, as YYYY-MM-DDTHH:MM in the site's time zone. */
	const META_END = '_wormiss_event_end';

	/** Venue name. */
	const META_LOCATION = '_wormiss_event_location';

	/** Online meeting link. */
	const META_ONLINE = '_wormiss_event_online';

	/** Street address, first line. */
	const META_ADDRESS1 = '_wormiss_event_address1';

	/** Street address, second line. */
	const META_ADDRESS2 = '_wormiss_event_address2';

	/** Suburb or city. */
	const META_CITY = '_wormiss_event_city';

	/** State or region, as iMIS has it (e.g. QLD). */
	const META_STATE = '_wormiss_event_state';

	/** Postcode. */
	const META_POSTCODE = '_wormiss_event_postcode';

	/** Country, as iMIS names it (Australia or New Zealand; '' keeps the template's). */
	const META_COUNTRY = '_wormiss_event_country';

	/** Countries the address can be in, as iMIS names them. */
	const COUNTRIES = [ 'Australia', 'New Zealand' ];

	/** Capacity (0 keeps the template's). */
	const META_CAPACITY = '_wormiss_event_capacity';

	/** Minimum attendance (0 uses the type's, if any). */
	const META_MINIMUM = '_wormiss_event_minimum';

	/** Expected attendance (0 uses the type's, if any). */
	const META_EXPECTED = '_wormiss_event_expected';

	/** Who may join the waitlist: None, OnlyStaff or StaffAndPublicUsers ('' uses the type's, else iMIS's). */
	const META_WAITLIST = '_wormiss_event_waitlist';

	/** Release freed places to the waitlist automatically: yes or no ('' uses the type's, else iMIS's). */
	const META_AUTO_RELEASE = '_wormiss_event_auto_release';

	/** An event already in iMIS to link to, instead of creating one. */
	const META_EXISTING = '_wormiss_event_existing';

	/** Set when the event is linked to an existing iMIS event (set by the link only). */
	const META_LINKED = '_wormiss_event_linked';

	/** A linked event's details as iMIS had them when linked (set by the link only). */
	const META_LINKED_DETAILS = '_wormiss_event_linked_details';

	/** Longest event code: iMIS event IDs are up to 10 characters. */
	const CODE_LENGTH = 10;

	/** The event's ID in iMIS, once created (set by the sync only). */
	const META_IMIS_ID = '_wormiss_imis_event_id';

	/** Last sync result: queued, dry_run, synced, error or cancelled (set by the sync only). */
	const META_STATUS = '_wormiss_event_sync_status';

	/** Last sync message (set by the sync only). */
	const META_MESSAGE = '_wormiss_event_sync_message';

	/** When the event was last saved in iMIS (Unix time; set by the sync only). */
	const META_SYNCED_AT = '_wormiss_event_synced_at';

	/** Set when an editor saves the event, so its scheduled publication may be sent too. */
	const META_APPROVED = '_wormiss_event_sync_approved';

	/** Seconds one event's sync holds its lock. */
	const LOCK_SECONDS = 300;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'maybe_queue' ], 20, 2 );
		// After the editor's meta is saved (the block editor saves it after save_post).
		add_action( 'wp_after_insert_post', [ __CLASS__, 'maybe_link' ], 10, 2 );
		add_action( 'wp_trash_post', [ __CLASS__, 'maybe_queue_cancel' ] );
		add_action( self::SYNC_HOOK, [ __CLASS__, 'sync' ] );
		add_action( self::CANCEL_HOOK, [ __CLASS__, 'cancel' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
		add_filter( 'the_content', [ __CLASS__, 'details_box' ], 20 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ __CLASS__, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ __CLASS__, 'column' ], 10, 2 );
	}

	/**
	 * Register the post type and its details.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			'wormiss_event',
			[
				'labels'       => [
					'name'          => __( 'Events', 'wormiss' ),
					'singular_name' => __( 'Event', 'wormiss' ),
					'add_new_item'  => __( 'Add New Event', 'wormiss' ),
					'edit_item'     => __( 'Edit Event', 'wormiss' ),
					'new_item'      => __( 'New Event', 'wormiss' ),
					'view_item'     => __( 'View Event', 'wormiss' ),
					'search_items'  => __( 'Search Events', 'wormiss' ),
					'not_found'     => __( 'No events found.', 'wormiss' ),
					'all_items'     => __( 'All Events', 'wormiss' ),
					'menu_name'     => __( 'Events', 'wormiss' ),
				],
				'public'       => true,
				'has_archive'  => 'events',
				'rewrite'      => [ 'slug' => 'event' ],
				// Listed under the Wormiss menu rather than a menu of its own.
				'show_in_menu' => 'wormiss',
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
			]
		);

		$auth    = static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id );
		$strings = [
			self::META_CODE         => [ __CLASS__, 'clean_code' ],
			self::META_EXISTING     => [ __CLASS__, 'clean_existing' ],
			self::META_START        => [ __CLASS__, 'clean_datetime' ],
			self::META_END          => [ __CLASS__, 'clean_datetime' ],
			self::META_LOCATION     => 'sanitize_text_field',
			self::META_ONLINE       => static fn( $value ) => (string) esc_url_raw( (string) $value, [ 'https', 'http' ] ),
			self::META_ADDRESS1     => [ __CLASS__, 'clean_address' ],
			self::META_ADDRESS2     => [ __CLASS__, 'clean_address' ],
			self::META_CITY         => [ __CLASS__, 'clean_address' ],
			self::META_STATE        => static fn( $value ) => strtoupper( substr( sanitize_text_field( (string) $value ), 0, 15 ) ),
			self::META_POSTCODE     => static fn( $value ) => substr( (string) preg_replace( '/[^A-Za-z0-9 ]/', '', (string) $value ), 0, 10 ),
			self::META_COUNTRY      => static fn( $value ) => in_array( (string) $value, self::COUNTRIES, true ) ? (string) $value : '',
			self::META_WAITLIST     => [ 'Wormiss_Event_Types', 'clean_waitlist' ],
			self::META_AUTO_RELEASE => [ 'Wormiss_Event_Types', 'clean_yes_no' ],
		];
		foreach ( $strings as $key => $sanitize ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => $auth,
				]
			);
		}
		foreach ( [ self::META_CAPACITY, self::META_MINIMUM, self::META_EXPECTED ] as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				[
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'show_in_rest'      => true,
					'sanitize_callback' => [ 'Wormiss_Event_Types', 'clean_number' ],
					'auth_callback'     => $auth,
				]
			);
		}
		// Set by the sync, so not editable meta (the editor sends all of its meta back on every save):
		// the editor reads them from a separate read-only field.
		register_rest_field(
			self::POST_TYPE,
			'wormiss_sync',
			[
				'get_callback' => [ __CLASS__, 'rest_sync_field' ],
				'schema'       => [
					'description' => __( 'What happened the last time this event was sent to iMIS.', 'wormiss' ),
					'type'        => 'object',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);

		// New permalinks need the rewrite rules rebuilt once after an update.
		if ( get_option( 'wormiss_rewrite_version' ) !== WORMISS_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'wormiss_rewrite_version', WORMISS_VERSION );
		}
	}

	/**
	 * The read-only wormiss_sync REST field: iMIS event ID, last status and message.
	 *
	 * @param array $post Post data from the REST controller.
	 * @return array
	 */
	public static function rest_sync_field( array $post ): array {
		$post_id = (int) ( $post['id'] ?? 0 );
		return [
			'imis_id'  => (string) get_post_meta( $post_id, self::META_IMIS_ID, true ),
			'status'   => (string) get_post_meta( $post_id, self::META_STATUS, true ),
			'message'  => (string) get_post_meta( $post_id, self::META_MESSAGE, true ),
			// The template a new event would be copied from, as saved (type or default).
			'template' => $post_id ? Wormiss_Event_Types::for_event( $post_id )['template'] : '',
			// Linked to an existing iMIS event (never changed or cancelled from WordPress).
			'linked'   => (bool) get_post_meta( $post_id, self::META_LINKED, true ),
			// Places left in iMIS, in words ('' if not known).
			'places'   => $post_id ? self::places_text( $post_id ) : '',
		];
	}

	/**
	 * Places left on a sent event, in words, or ''.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function places_text( int $post_id ): string {
		$imis_id = (string) get_post_meta( $post_id, self::META_IMIS_ID, true );
		if ( '' === $imis_id || '' === (string) Wormiss_Settings::get( 'event_places_query' ) ) {
			return '';
		}
		$listed = self::listed_event( $imis_id );
		$places = null !== $listed ? Wormiss_Events::places( $listed ) : null;
		return null !== $places ? Wormiss_Events::places_text( $places ) : '';
	}

	/**
	 * An event code: letters, digits, _ and - only (as an iMIS event ID), up to 10, upper case.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' if it is not usable.
	 */
	public static function clean_code( $value ): string {
		$code = strtoupper( trim( (string) $value ) );
		return ( strlen( $code ) <= self::CODE_LENGTH && Wormiss_Rest_Client::is_event_id( $code ) ) ? $code : '';
	}

	/**
	 * An existing iMIS event ID as typed, upper case. Not cleaned to '' when it is not a usable
	 * ID, so a typo is reported rather than taken as a request to unlink.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_existing( $value ): string {
		return substr( strtoupper( trim( sanitize_text_field( (string) $value ) ) ), 0, 64 );
	}

	/**
	 * A date and time from the editor, as YYYY-MM-DDTHH:MM.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' if it is not a real date and time.
	 */
	public static function clean_datetime( $value ): string {
		$value = substr( trim( (string) $value ), 0, 16 );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		return ( $date && $date->format( 'Y-m-d\TH:i' ) === $value ) ? $value : '';
	}

	/**
	 * Whether this is a REST API request (as the block editor's save is).
	 *
	 * @return bool
	 */
	private static function in_rest(): bool {
		/**
		 * Filters whether the current request counts as a REST API save.
		 *
		 * @param bool $in_rest Whether it does.
		 */
		return (bool) apply_filters( 'wormiss_event_sync_in_rest', defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * After an editor publishes or updates an event, send it to iMIS in the background.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function maybe_queue( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// A linked event is only read from iMIS, never sent (maybe_link() handles it).
		if ( self::is_linked( (int) $post_id ) ) {
			return;
		}
		// Creating events in iMIS is for editors and administrators, not every author. An editor's
		// save is remembered, so the event can still be sent when a scheduled publication happens
		// later in the background (no one is signed in then); anyone else's save needs an editor again.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			if ( current_user_can( 'edit_others_posts' ) ) {
				update_post_meta( $post_id, self::META_APPROVED, $user_id );
			} else {
				delete_post_meta( $post_id, self::META_APPROVED );
				return;
			}
		} elseif ( ! get_post_meta( $post_id, self::META_APPROVED, true ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! Wormiss_Settings::get( 'event_sync_enabled' ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_STATUS, 'queued' );
		update_post_meta( $post_id, self::META_MESSAGE, __( 'Waiting to be sent to iMIS.', 'wormiss' ) );
		if ( ! wp_next_scheduled( self::SYNC_HOOK, [ (int) $post_id ] ) ) {
			// The block editor saves through the REST API, then sends the "Event details" box in a
			// second request: wait a minute so a send cannot start on the details before the box's.
			// The box's save sends straight away (Wormiss_Event_Editor::save()).
			$when = self::in_rest() ? time() + MINUTE_IN_SECONDS : time();
			wp_schedule_single_event( $when, self::SYNC_HOOK, [ (int) $post_id ] );
		}
	}

	/**
	 * Send one event to iMIS (cron). The result is saved on the event for the editor to see.
	 *
	 * @param int $post_id Post ID.
	 * @return string The result status.
	 */
	public static function sync( $post_id ): string {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status
			|| ! Wormiss_Settings::get( 'event_sync_enabled' ) || self::is_linked( $post_id ) ) {
			return '';
		}
		$lock = 'wormiss_event_lock_' . $post_id;
		if ( get_transient( $lock ) ) {
			// Another run is sending this event: try again shortly so the latest save is sent.
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SYNC_HOOK, [ $post_id ] );
			return 'queued';
		}
		set_transient( $lock, 1, self::LOCK_SECONDS );
		try {
			[ $status, $message ] = self::send( $post );
		} catch ( Wormiss_Exception $e ) {
			[ $status, $message ] = [ 'error', $e->getMessage() ];
		} finally {
			delete_transient( $lock );
		}
		update_post_meta( $post_id, self::META_STATUS, $status );
		update_post_meta( $post_id, self::META_MESSAGE, $message );
		if ( 'synced' === $status ) {
			// When it was last saved in iMIS, so "Send events now" can tell edits made since.
			update_post_meta( $post_id, self::META_SYNCED_AT, time() );
		}
		return $status;
	}

	/**
	 * Create or update the event in iMIS, or check it in a dry run.
	 *
	 * @param WP_Post $post Event post.
	 * @return string[] [status, message].
	 * @throws Wormiss_Exception On an iMIS failure.
	 */
	private static function send( WP_Post $post ): array {
		$details = self::details( $post );
		$problem = self::problem( $details );
		if ( '' !== $problem ) {
			return [ 'error', $problem ];
		}
		$client   = new Wormiss_Rest_Client();
		$imis_id  = (string) get_post_meta( $post->ID, self::META_IMIS_ID, true );
		$dry_run  = (bool) Wormiss_Settings::get( 'event_sync_dry_run' );
		$template = $details['template'];
		// iMIS saves an event with a category it does not have (or fails with "HTTP 500"), so check first.
		if ( '' !== $details['category'] && ! $client->event_category_exists( $details['category'] ) ) {
			return [
				'error',
				sprintf(
					/* translators: %s: category ID. */
					__( 'iMIS has no event category %s. On the event type, use the ID of a category iMIS has (e.g. COUR), or clear it to keep the template\'s.', 'wormiss' ),
					$details['category']
				),
			];
		}

		if ( $dry_run ) {
			// Check what would be sent: the existing event, or the template as the new event.
			$event = $client->get_event( '' !== $imis_id ? $imis_id : $template );
			if ( '' === $imis_id ) {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
				$event->EventId       = '';
				$event->EventCode     = $details['code'];
				$event->TemplateState = 'None';
				// phpcs:enable
			}
			$errors = $client->validate_event( self::apply( $event, $details ) );
			if ( $errors ) {
				/* translators: %s: problems iMIS reported. */
				return [ 'error', sprintf( __( 'Dry run: iMIS would not accept this event: %s', 'wormiss' ), implode( ' ', $errors ) ) ];
			}
			$message = '' !== $imis_id
				? __( 'Dry run: iMIS accepted the changes. Nothing was sent.', 'wormiss' )
				: __( 'Dry run: iMIS accepted the new event. Nothing was created.', 'wormiss' );
			if ( '' !== $imis_id && Wormiss_Event_Cpd::owned( $post->ID ) ) {
				// What would change in its CPD hours (logged, nothing sent).
				$message = trim( $message . ' ' . Wormiss_Event_Cpd::push( $post->ID, $imis_id, true, $client ) );
			}
			return [ 'dry_run', $message ];
		}

		if ( '' === $imis_id ) {
			$imis_id = $client->clone_event( $template, $details['code'], $details['name'] );
			// Kept straight away, so a failure below never copies the template a second time.
			update_post_meta( $post->ID, self::META_IMIS_ID, $imis_id );
			// Trashed (or unpublished) while iMIS was copying: the cancel at trash time found no
			// iMIS event yet, so arrange it now rather than leave an orphan, and save nothing more.
			if ( 'publish' !== get_post_status( $post->ID ) ) {
				$cancel = 'trash' === get_post_status( $post->ID ) && Wormiss_Settings::get( 'event_cancel_on_trash' );
				if ( $cancel ) {
					wp_schedule_single_event( time(), self::CANCEL_HOOK, [ $post->ID, $imis_id ] );
				}
				$message = $cancel
					/* translators: %s: iMIS event ID. */
					? __( 'Created in iMIS as event %s, but the event was trashed meanwhile, so it is being cancelled there.', 'wormiss' )
					/* translators: %s: iMIS event ID. */
					: __( 'Created in iMIS as event %s, but the event was unpublished meanwhile. Check it in iMIS.', 'wormiss' );
				return [ 'error', sprintf( $message, $imis_id ) ];
			}
		}
		$client->update_event( $imis_id, self::apply( $client->get_event( $imis_id ), $details ) );
		/* translators: %s: iMIS event ID. */
		$message = sprintf( __( 'Saved in iMIS as event %s.', 'wormiss' ), $imis_id );
		if ( Wormiss_Event_Cpd::owned( $post->ID ) ) {
			// The CPD hours set here, after the event itself (a CPD failure is reported, and the
			// next send tries again: lines already saved keep their iMIS row numbers).
			try {
				$message = trim( $message . ' ' . Wormiss_Event_Cpd::push( $post->ID, $imis_id, false, $client ) );
			} catch ( Wormiss_Exception $e ) {
				/* translators: 1: iMIS event ID, 2: reason. */
				return [ 'error', sprintf( __( 'Saved in iMIS as event %1$s, but its CPD hours were not: %2$s', 'wormiss' ), $imis_id, $e->getMessage() ) ];
			}
		}
		return [ 'synced', $message ];
	}

	/**
	 * The event's details from WordPress, in iMIS terms.
	 *
	 * @param WP_Post $post Event post.
	 * @return array
	 */
	public static function details( WP_Post $post ): array {
		$meta = static fn( string $key ) => get_post_meta( $post->ID, $key, true );
		$html = wp_kses_post( do_blocks( $post->post_content ) );
		$type = Wormiss_Event_Types::for_event( $post->ID );
		return [
			'imis_id'      => (string) $meta( self::META_IMIS_ID ),
			'template'     => $type['template'],
			'category'     => $type['category'],
			'type_problem' => $type['problem'],
			'code'         => (string) $meta( self::META_CODE ),
			'name'         => wp_strip_all_tags( get_the_title( $post ) ),
			'description'  => trim( (string) preg_replace( '/<!--.*?-->/s', '', $html ) ),
			'start'        => (string) $meta( self::META_START ),
			'end'          => (string) $meta( self::META_END ),
			'location'     => (string) $meta( self::META_LOCATION ),
			'online'       => (string) $meta( self::META_ONLINE ),
			'address'      => [
				'line1'    => (string) $meta( self::META_ADDRESS1 ),
				'line2'    => (string) $meta( self::META_ADDRESS2 ),
				'city'     => (string) $meta( self::META_CITY ),
				'state'    => (string) $meta( self::META_STATE ),
				'postcode' => (string) $meta( self::META_POSTCODE ),
				'country'  => (string) $meta( self::META_COUNTRY ),
			],
			// The event's own numbers, else its type's defaults (0 keeps what iMIS has).
			'capacity'     => (int) $meta( self::META_CAPACITY ) > 0 ? (int) $meta( self::META_CAPACITY ) : $type['capacity'],
			'minimum'      => (int) $meta( self::META_MINIMUM ) > 0 ? (int) $meta( self::META_MINIMUM ) : $type['minimum'],
			'expected'     => (int) $meta( self::META_EXPECTED ) > 0 ? (int) $meta( self::META_EXPECTED ) : $type['expected'],
			// The event's own waitlist settings, else its type's ('' keeps what iMIS has).
			'waitlist'     => self::own_or( (string) $meta( self::META_WAITLIST ), $type['waitlist'] ),
			'release'      => self::own_or( (string) $meta( self::META_AUTO_RELEASE ), $type['release'] ),
		];
	}

	/**
	 * One line of an address: plain text, at most 100 characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_address( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, 100 );
	}

	/**
	 * Whether an address has anything filled in.
	 *
	 * @param array $address From details()['address'].
	 * @return bool
	 */
	public static function has_address( array $address ): bool {
		return '' !== implode( '', array_map( 'strval', $address ) );
	}

	/**
	 * Put the address on an iMIS event: iMIS keeps it in AdditionalAttributes, under
	 * fullAddressAttribute (lines, city, state, postcode and country, with the display text).
	 * The country and state left blank keep the template's; the other parts are written as
	 * they are, so a cleared line is cleared in iMIS too.
	 *
	 * @param object $event   iMIS EventData, changed in place.
	 * @param array  $address From details()['address'].
	 * @return void
	 */
	public static function apply_address( object $event, array $address ): void {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( ! isset( $event->AdditionalAttributes ) || ! is_object( $event->AdditionalAttributes ) ) {
			$event->AdditionalAttributes = (object) [
				'$type'   => 'Asi.Soa.Core.DataContracts.GenericPropertyDataCollection, Asi.Contracts',
				'$values' => [],
			];
		}
		$values = isset( $event->AdditionalAttributes->{'$values'} ) && is_array( $event->AdditionalAttributes->{'$values'} )
			? $event->AdditionalAttributes->{'$values'} : [];
		$entry  = null;
		foreach ( $values as $value ) {
			if ( is_object( $value ) && 'fullAddressAttribute' === ( $value->Name ?? '' ) ) {
				$entry = $value;
				break;
			}
		}
		if ( null === $entry ) {
			$entry    = (object) [
				'$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts',
				'Name'  => 'fullAddressAttribute',
			];
			$values[] = $entry;
		}
		if ( ! isset( $entry->Value ) || ! is_object( $entry->Value ) ) {
			$entry->Value = (object) [ '$type' => 'Asi.Soa.Membership.DataContracts.FullAddressData, Asi.Contracts' ];
		}
		if ( ! isset( $entry->Value->Address ) || ! is_object( $entry->Value->Address ) ) {
			$entry->Value->Address = (object) [ '$type' => 'Asi.Soa.Membership.DataContracts.AddressData, Asi.Contracts' ];
		}
		$to               = $entry->Value->Address;
		$to->AddressLines = (object) [
			'$type'   => 'Asi.Soa.Membership.DataContracts.AddressLineDataCollection, Asi.Contracts',
			'$values' => [ $address['line1'], $address['line2'], '' ],
		];
		$to->CityName     = $address['city'];
		$to->PostalCode   = $address['postcode'];
		if ( '' !== $address['state'] ) {
			$to->CountrySubEntityCode = $address['state'];
		}
		if ( '' !== $address['country'] ) {
			$to->CountryCode = $address['country'];
		}
		$state   = (string) ( $to->CountrySubEntityCode ?? '' );
		$country = (string) ( $to->CountryCode ?? '' );
		// The display text, laid out as iMIS lays it out (it may redo it when it saves).
		$place                                    = trim( $address['city'] . ' ' . $state . '  ' . $address['postcode'] );
		$lines                                    = array_filter( [ $address['line1'], $address['line2'], $place, strtoupper( $country ) ], static fn( $line ) => '' !== $line );
		$to->FullAddress                          = implode( "\r", $lines );
		$entry->Value->DisplayName                = trim( implode( ' ', array_filter( [ $address['line1'], $address['city'], $state, $country ] ) ) );
		$event->AdditionalAttributes->{'$values'} = $values;
		// phpcs:enable
	}

	/**
	 * The address as one line for people, e.g. "1 Main St, Brisbane QLD 4000".
	 *
	 * @param array $address From details()['address'].
	 * @return string
	 */
	public static function address_text( array $address ): string {
		$place = trim( implode( ' ', array_filter( [ $address['city'] ?? '', $address['state'] ?? '', $address['postcode'] ?? '' ] ) ) );
		return implode( ', ', array_filter( [ $address['line1'] ?? '', $address['line2'] ?? '', $place ] ) );
	}

	/**
	 * The event's own setting, or the fallback when it has none.
	 *
	 * @param string $own      The event's value.
	 * @param string $fallback The type's value.
	 * @return string
	 */
	private static function own_or( string $own, string $fallback ): string {
		return '' !== $own ? $own : $fallback;
	}

	/**
	 * What stops an event being sent, in words, or ''.
	 *
	 * @param array $details From details().
	 * @return string
	 */
	public static function problem( array $details ): string {
		if ( '' !== ( $details['type_problem'] ?? '' ) ) {
			return $details['type_problem'];
		}
		// A template is only used to create the event; one already in iMIS is just updated.
		if ( '' === ( $details['template'] ?? '' ) && '' === ( $details['imis_id'] ?? '' ) ) {
			return __( 'No iMIS template event: give this event type one, or set the default in Wormiss > Settings.', 'wormiss' );
		}
		if ( '' === $details['code'] ) {
			return __( 'Add an event code (letters, digits, _ and - only, up to 10 characters).', 'wormiss' );
		}
		// A category saved before the 5-character limit was checked (e.g. COURSE).
		$category = (string) ( $details['category'] ?? '' );
		if ( '' !== $category && ! Wormiss_Rest_Client::is_category_id( $category ) ) {
			return __( 'The event type\'s iMIS category ID is too long or not valid: use up to 5 characters, the ID iMIS uses (e.g. COUR).', 'wormiss' );
		}
		$minimum  = (int) ( $details['minimum'] ?? 0 );
		$expected = (int) ( $details['expected'] ?? 0 );
		$capacity = (int) ( $details['capacity'] ?? 0 );
		if ( $capacity > 0 && max( $minimum, $expected ) > $capacity ) {
			return __( 'The minimum and expected attendance cannot be more than the capacity.', 'wormiss' );
		}
		if ( $minimum > 0 && $expected > 0 && $minimum > $expected ) {
			return __( 'The minimum attendance is more than the expected attendance.', 'wormiss' );
		}
		if ( '' === trim( $details['name'] ) ) {
			return __( 'Add a title.', 'wormiss' );
		}
		if ( '' === $details['start'] || '' === $details['end'] ) {
			return __( 'Add the start and end.', 'wormiss' );
		}
		if ( $details['end'] < $details['start'] ) {
			return __( 'The end is before the start.', 'wormiss' );
		}
		return '';
	}

	/**
	 * The iMIS event with the WordPress details written over it (everything else is kept).
	 *
	 * @param object $event   EventData from iMIS.
	 * @param array  $details From details().
	 * @return object
	 */
	public static function apply( object $event, array $details ): object {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( ! isset( $event->{'$type'} ) ) {
			$event->{'$type'} = 'Asi.Soa.Events.DataContracts.EventData, Asi.Contracts';
		}
		$event->Name              = $details['name'];
		$event->Description       = $details['description'];
		$event->StartDateTime     = $details['start'] . ':00';
		$event->EndDateTime       = $details['end'] . ':00';
		$event->VirtualMeetingUrl = $details['online'];
		if ( $details['capacity'] > 0 ) {
			$event->Capacity = $details['capacity'];
		}
		if ( '' !== ( $details['waitlist'] ?? '' ) ) {
			$event->WaitlistPermissions = self::enum_value( $event->WaitlistPermissions ?? null, $details['waitlist'] );
		}
		if ( '' !== ( $details['release'] ?? '' ) ) {
			$event->AutomaticallyReleaseFromWaitlist = 'yes' === $details['release'];
		}
		// Minimum and expected attendance belong to the event's main registration option.
		$minimum  = (int) ( $details['minimum'] ?? 0 );
		$expected = (int) ( $details['expected'] ?? 0 );
		$options  = $event->RegistrationOptions->{'$values'} ?? null;
		$option   = is_array( $options ) ? reset( $options ) : null;
		if ( is_object( $option ) ) {
			if ( $minimum > 0 ) {
				$option->MinimumAttendance = $minimum;
			}
			if ( $expected > 0 ) {
				$option->ExpectedAttendance = $expected;
			}
		}
		if ( '' !== ( $details['category'] ?? '' ) ) {
			// The type's category replaces whatever the event had (its name comes from iMIS).
			$event->Category = (object) [
				'$type'           => 'Asi.Soa.Events.DataContracts.EventCategoryData, Asi.Contracts',
				'EventCategoryId' => $details['category'],
			];
		}
		if ( self::has_address( $details['address'] ?? [] ) ) {
			self::apply_address( $event, $details['address'] );
		}
		if ( '' !== $details['location'] ) {
			if ( isset( $event->Location ) && is_object( $event->Location ) ) {
				$event->Location->Name = $details['location'];
			} else {
				$event->Location = (object) [
					'$type' => 'Asi.Soa.Membership.DataContracts.LocationPartyAddressData, Asi.Contracts',
					'Name'  => $details['location'],
				];
			}
		}
		// phpcs:enable
		return $event;
	}

	/**
	 * An iMIS enum value in the same shape iMIS sent it: plain, or wrapped in {"$type","$value"}.
	 *
	 * @param mixed  $current The value iMIS sent.
	 * @param string $value   The new value.
	 * @return mixed
	 */
	private static function enum_value( $current, string $value ) {
		if ( is_object( $current ) && property_exists( $current, '$value' ) && is_string( $current->{'$value'} ) ) {
			$current->{'$value'} = $value;
			return $current;
		}
		return $value;
	}

	/**
	 * When a sent event is trashed, cancel it in iMIS too if that is switched on.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function maybe_queue_cancel( $post_id ): void {
		$imis_id = (string) get_post_meta( (int) $post_id, self::META_IMIS_ID, true );
		if ( '' === $imis_id || self::POST_TYPE !== get_post_type( (int) $post_id ) || self::is_linked( (int) $post_id )
			|| ! Wormiss_Settings::get( 'event_sync_enabled' ) || ! Wormiss_Settings::get( 'event_cancel_on_trash' )
			|| ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::CANCEL_HOOK, [ (int) $post_id, $imis_id ] );
	}

	/**
	 * Cancel an event in iMIS (cron); a dry run only logs it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $imis_id iMIS event ID.
	 * @return string The result status.
	 */
	public static function cancel( $post_id, $imis_id ): string {
		$post_id = (int) $post_id;
		$imis_id = (string) $imis_id;
		// Restored from the bin, or cancelling switched off, before this ran: leave the iMIS event alone.
		if ( 'trash' !== get_post_status( $post_id ) || ! Wormiss_Settings::get( 'event_sync_enabled' )
			|| ! Wormiss_Settings::get( 'event_cancel_on_trash' ) ) {
			return '';
		}
		if ( Wormiss_Settings::get( 'event_sync_dry_run' ) ) {
			Wormiss_Logger::record_call( 'DRY RUN POST /api/Event/_execute (CancelEvent ' . $imis_id . ')', Wormiss_Logger::STATUS_SUCCESS );
			$status  = 'dry_run';
			$message = __( 'Dry run: the event would have been cancelled in iMIS.', 'wormiss' );
		} else {
			try {
				( new Wormiss_Rest_Client() )->cancel_event( $imis_id );
				$status  = 'cancelled';
				$message = __( 'Cancelled in iMIS.', 'wormiss' );
			} catch ( Wormiss_Exception $e ) {
				$status  = 'error';
				$message = $e->getMessage();
			}
		}
		update_post_meta( $post_id, self::META_STATUS, $status );
		update_post_meta( $post_id, self::META_MESSAGE, $message );
		return $status;
	}

	/**
	 * The editor panel for events.
	 *
	 * @return void
	 */
	public static function editor_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$asset = require WORMISS_DIR . '/assets/event-panel.asset.php';
		wp_enqueue_script(
			'wormiss-event-panel',
			plugins_url( 'assets/event-panel.js', WORMISS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		$data = [
			'enabled'  => (bool) Wormiss_Settings::get( 'event_sync_enabled' ),
			'dryRun'   => (bool) Wormiss_Settings::get( 'event_sync_dry_run' ),
			'template' => (string) Wormiss_Settings::get( 'event_template_id' ),
		];
		wp_add_inline_script( 'wormiss-event-panel', 'window.wormissEventSync = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_set_script_translations( 'wormiss-event-panel', 'wormiss' );
	}

	/**
	 * The iMIS event with this ID from the cached events list, or null.
	 *
	 * @param string $imis_id iMIS event ID.
	 * @return array|null
	 */
	private static function listed_event( string $imis_id ): ?array {
		try {
			foreach ( Wormiss_Events::get_events() as $event ) {
				if ( $event['id'] === $imis_id ) {
					return $event;
				}
			}
		} catch ( Wormiss_Exception $e ) {
			unset( $e ); // iMIS unavailable: fall back to the link.
		}
		return null;
	}

	/**
	 * On an event's own page, show when and where it is, with a button to register in iMIS.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function details_box( $content ) {
		$post = get_post();
		if ( ! $post || self::POST_TYPE !== $post->post_type || ! is_singular( self::POST_TYPE ) || ! in_the_loop() ) {
			return $content;
		}
		$details = self::details( $post );
		$imis_id = (string) get_post_meta( $post->ID, self::META_IMIS_ID, true );
		$linked  = (bool) get_post_meta( $post->ID, self::META_LINKED, true );
		// The event as iMIS lists it (cached): for a linked event's details, places left and Register.
		$wanted = $linked || Wormiss_Event_Registration::is_on() || '' !== (string) Wormiss_Settings::get( 'event_places_query' );
		$listed = ( '' !== $imis_id && $wanted ) ? self::listed_event( $imis_id ) : null;
		if ( $linked && null === $listed ) {
			// Not in the events list (e.g. it started long ago): the details kept when it was linked.
			$kept   = get_post_meta( $post->ID, self::META_LINKED_DETAILS, true );
			$listed = is_array( $kept ) && ! empty( $kept['id'] ) ? $kept : null;
		}
		if ( $linked && null !== $listed ) {
			// A linked event's details live in iMIS; anything filled in here still comes first.
			foreach ( [ 'start', 'end', 'location', 'online', 'code' ] as $key ) {
				$details[ $key ] = '' !== $details[ $key ] ? $details[ $key ] : (string) $listed[ $key ];
			}
		}
		$rows = '';
		if ( '' !== $details['start'] ) {
			$when  = Wormiss_Events::when( $details['start'], $details['end'] );
			$rows .= '<dt>' . esc_html__( 'When', 'wormiss' ) . '</dt><dd>' . esc_html( $when ) . '</dd>';
		}
		$where   = '' !== $details['location'] ? $details['location'] : ( '' !== $details['online'] ? __( 'Online', 'wormiss' ) : '' );
		$address = self::address_text( $details['address'] );
		if ( '' !== $where || '' !== $address ) {
			$rows .= '<dt>' . esc_html__( 'Where', 'wormiss' ) . '</dt><dd>' . esc_html( $where )
				. ( '' !== $where && '' !== $address ? '<br />' : '' ) . esc_html( $address ) . '</dd>';
		}
		if ( '' !== $details['code'] ) {
			$rows .= '<dt>' . esc_html__( 'Event code', 'wormiss' ) . '</dt><dd>' . esc_html( $details['code'] ) . '</dd>';
		}
		$closes = null !== $listed ? Wormiss_Events::timestamp( (string) ( $listed['reg_end'] ?? '' ) ) : null;
		if ( null !== $closes && $closes >= time() ) {
			$rows .= '<dt>' . esc_html__( 'Registration closes', 'wormiss' ) . '</dt><dd>'
				. esc_html( Wormiss_Events::when( (string) $listed['reg_end'], '' ) ) . '</dd>';
		}
		$places = null !== $listed ? Wormiss_Events::places( $listed ) : null;
		if ( null !== $places ) {
			$rows .= '<dt>' . esc_html__( 'Places', 'wormiss' ) . '</dt><dd>' . esc_html( Wormiss_Events::places_text( $places ) ) . '</dd>';
		}
		$url = '' !== $imis_id ? Wormiss_Events::event_url(
			[
				'id'   => $imis_id,
				'code' => $details['code'],
			]
		) : '';
		$box = '' !== $rows ? '<dl class="wormiss-event-details">' . $rows . '</dl>' : '';
		// A free event: register (or cancel) here instead of linking to iMIS.
		$button = ( null !== $listed && Wormiss_Event_Registration::is_on() ) ? Wormiss_Event_Registration::button( $listed, true ) : '';
		if ( '' !== $button ) {
			return $box . $button . $content;
		}
		if ( '' !== $url ) {
			$box .= '<p class="wormiss-event-register"><a class="wp-element-button" href="' . esc_url( $url ) . '">'
				. esc_html__( 'Register', 'wormiss' ) . '</a></p>';
		}
		return $box . $content;
	}

	/**
	 * Add the event code and iMIS columns to the Events list.
	 *
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public static function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['wormiss_code'] = __( 'Event code', 'wormiss' );
				$out['wormiss_imis'] = __( 'iMIS', 'wormiss' );
			}
		}
		return $out;
	}

	/**
	 * The event code and iMIS column values.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		if ( 'wormiss_code' === $column ) {
			echo esc_html( (string) get_post_meta( (int) $post_id, self::META_CODE, true ) );
		} elseif ( 'wormiss_imis' === $column ) {
			$imis_id = (string) get_post_meta( (int) $post_id, self::META_IMIS_ID, true );
			$status  = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );
			$labels  = [
				'queued'    => __( 'Waiting to be sent', 'wormiss' ),
				'dry_run'   => __( 'Dry run', 'wormiss' ),
				'synced'    => __( 'Saved', 'wormiss' ),
				'error'     => __( 'Problem', 'wormiss' ),
				'linked'    => __( 'Linked', 'wormiss' ),
				'cancelled' => __( 'Cancelled', 'wormiss' ),
			];
			$text    = $labels[ $status ] ?? '';
			if ( '' !== $imis_id ) {
				/* translators: %s: iMIS event ID. */
				$text = trim( $text . ' ' . sprintf( __( '(%s)', 'wormiss' ), $imis_id ) );
			}
			echo esc_html( '' !== $text ? $text : '—' );
		}
	}

	/**
	 * Whether an event is linked to an existing iMIS event, or about to be.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_linked( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, self::META_LINKED, true )
			|| '' !== (string) get_post_meta( $post_id, self::META_EXISTING, true );
	}

	/**
	 * After an editor saves an event, link it to (or unlink it from) the existing iMIS event
	 * entered in its panel. Linking only reads iMIS: the event there is never changed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return string Result: linked, unlinked, error, or '' when nothing was to be done.
	 */
	public static function maybe_link( $post_id, $post ): string {
		$post_id = (int) $post_id;
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id )
			|| in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) || ! current_user_can( 'edit_others_posts' ) ) {
			return '';
		}
		$existing = (string) get_post_meta( $post_id, self::META_EXISTING, true );
		$linked   = (bool) get_post_meta( $post_id, self::META_LINKED, true );
		$imis_id  = (string) get_post_meta( $post_id, self::META_IMIS_ID, true );
		if ( '' === $existing && ! $linked ) {
			return '';
		}
		// Whatever happens, an event to link is never copied from the template.
		wp_clear_scheduled_hook( self::SYNC_HOOK, [ $post_id ] );
		if ( '' === $existing ) {
			delete_post_meta( $post_id, self::META_LINKED );
			delete_post_meta( $post_id, self::META_IMIS_ID );
			delete_post_meta( $post_id, self::META_LINKED_DETAILS );
			return self::link_result( $post_id, 'unlinked', __( 'No longer linked to an iMIS event.', 'wormiss' ) );
		}
		if ( '' === self::clean_code( $existing ) ) {
			// A typo: keep any link there is, and say what is wrong.
			/* translators: %s: what was typed. */
			$message = __( '"%s" is not an iMIS event ID (up to 10 letters, digits, _ and -). Nothing was changed.', 'wormiss' );
			return self::link_result( $post_id, 'error', sprintf( $message, $existing ) );
		}
		if ( '' !== $imis_id && ! $linked ) {
			/* translators: %s: iMIS event ID. */
			$message = __( 'This event is already in iMIS as %s, so it cannot be linked to another one.', 'wormiss' );
			return self::link_result( $post_id, 'error', sprintf( $message, $imis_id ) );
		}
		if ( $linked && $imis_id === $existing ) {
			return '';
		}
		if ( ! Wormiss_Settings::is_configured() ) {
			return self::link_result( $post_id, 'error', __( 'The iMIS connection is not set up yet (Wormiss > Settings).', 'wormiss' ) );
		}
		/* translators: %s: iMIS event ID. */
		$reason = sprintf( __( 'iMIS event %s was not found.', 'wormiss' ), $existing );
		try {
			$event = Wormiss_Events::normalise( ( new Wormiss_Rest_Client() )->get_event( $existing ) );
		} catch ( Wormiss_Exception $e ) {
			// Some events cannot be read on their own (iMIS errors on them); the events list may have it.
			$event = self::listed_event( $existing );
			/* translators: 1: iMIS event ID, 2: reason. */
			$reason = sprintf( __( 'iMIS event %1$s could not be read (%2$s).', 'wormiss' ), $existing, $e->getMessage() );
		}
		if ( null === $event ) {
			delete_post_meta( $post_id, self::META_LINKED );
			delete_post_meta( $post_id, self::META_IMIS_ID );
			delete_post_meta( $post_id, self::META_LINKED_DETAILS );
			return self::link_result( $post_id, 'error', $reason );
		}
		update_post_meta( $post_id, self::META_IMIS_ID, $event['id'] );
		update_post_meta( $post_id, self::META_LINKED, 1 );
		// Kept for the page, as the events list only reaches back LOOKBACK_DAYS.
		update_post_meta( $post_id, self::META_LINKED_DETAILS, wp_slash( $event ) );
		/* translators: 1: iMIS event ID, 2: event name in iMIS. */
		$message = __( 'Linked to iMIS event %1$s (%2$s). Its details come from iMIS; WordPress never changes or cancels it.', 'wormiss' );
		return self::link_result( $post_id, 'linked', sprintf( $message, $event['id'], $event['name'] ) );
	}

	/**
	 * Keep a link result on the event for the editor to see.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Result.
	 * @param string $message Message.
	 * @return string The result.
	 */
	private static function link_result( int $post_id, string $status, string $message ): string {
		update_post_meta( $post_id, self::META_STATUS, 'unlinked' === $status ? '' : $status );
		update_post_meta( $post_id, self::META_MESSAGE, $message );
		return $status;
	}
}
