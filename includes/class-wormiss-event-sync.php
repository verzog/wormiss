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

	/** Capacity (0 keeps the template's). */
	const META_CAPACITY = '_wormiss_event_capacity';

	/** The event's ID in iMIS, once created (set by the sync only). */
	const META_IMIS_ID = '_wormiss_imis_event_id';

	/** Last sync result: queued, dry_run, synced, error or cancelled (set by the sync only). */
	const META_STATUS = '_wormiss_event_sync_status';

	/** Last sync message (set by the sync only). */
	const META_MESSAGE = '_wormiss_event_sync_message';

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
		add_action( 'wp_trash_post', [ __CLASS__, 'maybe_queue_cancel' ] );
		add_action( self::SYNC_HOOK, [ __CLASS__, 'sync' ] );
		add_action( self::CANCEL_HOOK, [ __CLASS__, 'cancel' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
		add_filter( 'the_content', [ __CLASS__, 'details_box' ], 20 );
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
				'menu_icon'    => 'dashicons-calendar-alt',
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
			]
		);

		$auth    = static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id );
		$strings = [
			self::META_CODE     => [ __CLASS__, 'clean_code' ],
			self::META_START    => [ __CLASS__, 'clean_datetime' ],
			self::META_END      => [ __CLASS__, 'clean_datetime' ],
			self::META_LOCATION => 'sanitize_text_field',
			self::META_ONLINE   => static fn( $value ) => (string) esc_url_raw( (string) $value, [ 'https', 'http' ] ),
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
		register_post_meta(
			self::POST_TYPE,
			self::META_CAPACITY,
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => min( 100000, absint( $value ) ),
				'auth_callback'     => $auth,
			]
		);
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
			'imis_id' => (string) get_post_meta( $post_id, self::META_IMIS_ID, true ),
			'status'  => (string) get_post_meta( $post_id, self::META_STATUS, true ),
			'message' => (string) get_post_meta( $post_id, self::META_MESSAGE, true ),
		];
	}

	/**
	 * An event code: letters, digits, _ and - only (as an iMIS event ID), upper case.
	 *
	 * @param mixed $value Raw value.
	 * @return string '' if it is not usable.
	 */
	public static function clean_code( $value ): string {
		$code = strtoupper( trim( (string) $value ) );
		return Wormiss_Rest_Client::is_event_id( $code ) ? $code : '';
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
			wp_schedule_single_event( time(), self::SYNC_HOOK, [ (int) $post_id ] );
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
			|| ! Wormiss_Settings::get( 'event_sync_enabled' ) ) {
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
		$template = (string) Wormiss_Settings::get( 'event_template_id' );

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
		return [ 'synced', sprintf( __( 'Saved in iMIS as event %s.', 'wormiss' ), $imis_id ) ];
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
		return [
			'code'        => (string) $meta( self::META_CODE ),
			'name'        => wp_strip_all_tags( get_the_title( $post ) ),
			'description' => trim( (string) preg_replace( '/<!--.*?-->/s', '', $html ) ),
			'start'       => (string) $meta( self::META_START ),
			'end'         => (string) $meta( self::META_END ),
			'location'    => (string) $meta( self::META_LOCATION ),
			'online'      => (string) $meta( self::META_ONLINE ),
			'capacity'    => (int) $meta( self::META_CAPACITY ),
		];
	}

	/**
	 * What stops an event being sent, in words, or ''.
	 *
	 * @param array $details From details().
	 * @return string
	 */
	public static function problem( array $details ): string {
		if ( '' === (string) Wormiss_Settings::get( 'event_template_id' ) ) {
			return __( 'Choose the iMIS template event in Settings > iMIS Bridge first.', 'wormiss' );
		}
		if ( '' === $details['code'] ) {
			return __( 'Add an event code (letters, digits, _ and - only).', 'wormiss' );
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
	 * When a sent event is trashed, cancel it in iMIS too if that is switched on.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function maybe_queue_cancel( $post_id ): void {
		$imis_id = (string) get_post_meta( (int) $post_id, self::META_IMIS_ID, true );
		if ( '' === $imis_id || self::POST_TYPE !== get_post_type( (int) $post_id )
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
		$rows    = '';
		if ( '' !== $details['start'] ) {
			$when  = Wormiss_Events::when( $details['start'], $details['end'] );
			$rows .= '<dt>' . esc_html__( 'When', 'wormiss' ) . '</dt><dd>' . esc_html( $when ) . '</dd>';
		}
		$where = '' !== $details['location'] ? $details['location'] : ( '' !== $details['online'] ? __( 'Online', 'wormiss' ) : '' );
		if ( '' !== $where ) {
			$rows .= '<dt>' . esc_html__( 'Where', 'wormiss' ) . '</dt><dd>' . esc_html( $where ) . '</dd>';
		}
		$imis_id = (string) get_post_meta( $post->ID, self::META_IMIS_ID, true );
		$url     = '' !== $imis_id ? Wormiss_Events::event_url(
			[
				'id'   => $imis_id,
				'code' => $details['code'],
			]
		) : '';
		$box     = '' !== $rows ? '<dl class="wormiss-event-details">' . $rows . '</dl>' : '';
		if ( '' !== $url ) {
			$box .= '<p class="wormiss-event-register"><a class="wp-element-button" href="' . esc_url( $url ) . '">'
				. esc_html__( 'Register', 'wormiss' ) . '</a></p>';
		}
		return $box . $content;
	}
}
