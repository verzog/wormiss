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
 * Records what signed-in members do on the site as iMIS activity records.
 *
 * Reads of members-only posts, tracked file downloads, "Mark as done" completions and
 * form submissions are queued and sent in the background (WP-Cron), so pages never
 * wait on iMIS; a failed send is retried twice. Recording is off, and a dry run (log
 * only), until an administrator turns it on. Only members with an iMIS ID are recorded.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues, sends and de-duplicates activity records.
 */
class Wormiss_Activity {

	/** Cron hook that sends one record. */
	const SEND_HOOK = 'wormiss_send_activity';

	/** Delay before each retry, in seconds (after the first and second failures). */
	const RETRY_DELAYS = [ 300, 1800 ];

	/** Query argument for tracked download links. */
	const DOWNLOAD_ARG = 'wormiss_download';

	/**
	 * Hook the recorders.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::SEND_HOOK, [ __CLASS__, 'send' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_record_read' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_download' ], 1 );
		add_shortcode( 'wormiss_download', [ __CLASS__, 'download_shortcode' ] );

		// For developers: fire the wormiss_record_activity action with a kind, a description and
		// optional arguments (note, points, post_id) to record anything else.
		add_action(
			'wormiss_record_activity',
			static function ( $kind, $description, $args = [] ) {
				self::record( (string) $kind, (string) $description, is_array( $args ) ? $args : [] );
			},
			10,
			3
		);

		// Form plugins: a submission by a signed-in member.
		add_action( 'gform_after_submission', [ __CLASS__, 'gravity_forms' ], 10, 2 );
		add_action( 'wpforms_process_complete', [ __CLASS__, 'wpforms' ], 10, 4 );
		add_action( 'wpcf7_mail_sent', [ __CLASS__, 'contact_form_7' ] );
	}

	/**
	 * Whether records are being made at all (switched on and an activity type set).
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return (bool) Wormiss_Settings::get( 'activity_enabled' ) && '' !== (string) Wormiss_Settings::get( 'activity_type' );
	}

	/**
	 * Record something the current member did.
	 *
	 * @param string $kind        read, download, completion or form.
	 * @param string $description What it was about (e.g. the post title).
	 * @param array  $args        Optional: note, points, post_id, dedupe (a key: recorded once a day).
	 * @return bool Whether a record was queued (or, in a dry run, logged).
	 */
	public static function record( string $kind, string $description, array $args = [] ): bool {
		if ( ! self::is_on() ) {
			return false;
		}
		$user_id = get_current_user_id();
		$imis_id = Wormiss_Member::imis_id( $user_id );
		if ( '' === $imis_id ) {
			return false;
		}
		if ( isset( $args['dedupe'] ) ) {
			$key = 'wormiss_act_' . md5( $user_id . "\0" . $kind . "\0" . $args['dedupe'] . "\0" . wp_date( 'Ymd' ) );
			if ( get_transient( $key ) ) {
				return false;
			}
			set_transient( $key, 1, DAY_IN_SECONDS );
		}

		$record = [
			'kind'        => $kind,
			'imis_id'     => $imis_id,
			'user_id'     => $user_id,
			'description' => wp_strip_all_tags( $description ),
			'note'        => wp_strip_all_tags( (string) ( $args['note'] ?? '' ) ),
			'points'      => (float) ( $args['points'] ?? 0 ),
			'post_id'     => (int) ( $args['post_id'] ?? 0 ),
			'date'        => wp_date( 'Y-m-d\TH:i:s' ),
		];

		if ( Wormiss_Settings::get( 'activity_dry_run' ) ) {
			// A dry run: log what would be sent, and send nothing.
			Wormiss_Logger::record_call(
				'DRY RUN POST /api/Activity (' . $kind . ': ' . $record['description'] . ')',
				Wormiss_Logger::STATUS_SUCCESS,
				null,
				(string) wp_json_encode( self::body( $record ) ),
				null,
				$imis_id
			);
			return true;
		}
		wp_schedule_single_event( time(), self::SEND_HOOK, [ $record, 1 ] );
		return true;
	}

	/**
	 * The iMIS activity body for a queued record.
	 *
	 * @param array $record Record from record().
	 * @return array
	 */
	public static function body( array $record ): array {
		return Wormiss_Rest_Client::build_activity_body(
			(string) Wormiss_Settings::get( 'activity_type' ),
			(string) $record['imis_id'],
			(string) $record['kind'],
			(string) $record['description'],
			(string) $record['note'],
			(string) $record['date'],
			(float) $record['points'],
			(int) $record['post_id']
		);
	}

	/**
	 * Send one record (cron), retrying later on failure.
	 *
	 * @param array $record  Record from record().
	 * @param int   $attempt Attempt number, from 1.
	 * @return bool Whether it was sent.
	 */
	public static function send( $record, $attempt = 1 ): bool {
		if ( ! is_array( $record ) || empty( $record['imis_id'] ) || ! self::is_on() ) {
			return false;
		}
		try {
			( new Wormiss_Rest_Client() )->add_activity( self::body( $record ), (string) $record['imis_id'] );
			return true;
		} catch ( Wormiss_Exception $e ) {
			$attempt = (int) $attempt;
			if ( isset( self::RETRY_DELAYS[ $attempt - 1 ] ) ) {
				wp_schedule_single_event( time() + self::RETRY_DELAYS[ $attempt - 1 ], self::SEND_HOOK, [ $record, $attempt + 1 ] );
			}
			// The failed call is already in the audit log; after the last attempt it is not retried.
			return false;
		}
	}

	/**
	 * Record a member reading a members-only post or page (once a day per post).
	 *
	 * @return void
	 */
	public static function maybe_record_read(): void {
		if ( ! Wormiss_Settings::get( 'record_reads' ) || ! is_singular() || ! is_user_logged_in() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		$rules   = Wormiss_Access::post_rules( $post_id );
		// Members who meet the rules only: not editors reading with editing rights, not the teaser.
		if ( null === $rules || post_password_required( $post_id ) || ! Wormiss_Access::user_meets( $rules ) ) {
			return;
		}
		self::record(
			'read',
			get_the_title( $post_id ),
			[
				'note'    => sprintf( 'Read: %s (%s)', get_the_title( $post_id ), get_permalink( $post_id ) ),
				'post_id' => $post_id,
				'dedupe'  => 'post-' . $post_id,
			]
		);
	}

	/**
	 * A tracked download link for a Media Library file.
	 *
	 * Signed-in visitors get a nonce, so other sites cannot make a member "download" files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string URL.
	 */
	public static function download_url( int $attachment_id ): string {
		$url = add_query_arg( self::DOWNLOAD_ARG, $attachment_id, home_url( '/' ) );
		return is_user_logged_in() ? wp_nonce_url( $url, 'wormiss_download_' . $attachment_id ) : $url;
	}

	/**
	 * The [wormiss_download id="123"]Link text[/wormiss_download] shortcode.
	 *
	 * @param array|string $atts    Attributes (id).
	 * @param string|null  $content Link text (default: the file's title).
	 * @return string
	 */
	public static function download_shortcode( $atts, $content = null ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], is_array( $atts ) ? $atts : [], 'wormiss_download' );
		$id   = absint( $atts['id'] );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return '';
		}
		$text = null !== $content && '' !== trim( $content ) ? $content : get_the_title( $id );
		return '<a class="wormiss-download" href="' . esc_url( self::download_url( $id ) ) . '">'
			. esc_html( wp_strip_all_tags( $text ) ) . '</a>';
	}

	/**
	 * Where a tracked download link goes, recording the download when it is genuine.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $nonce         Nonce from the link.
	 * @return string The file URL, or '' if there is no such file.
	 */
	public static function handle_download( int $attachment_id, string $nonce ): string {
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return '';
		}
		$url = (string) wp_get_attachment_url( $attachment_id );
		if ( '' !== $url && Wormiss_Settings::get( 'record_downloads' ) && is_user_logged_in()
			&& wp_verify_nonce( $nonce, 'wormiss_download_' . $attachment_id ) ) {
			$name = get_the_title( $attachment_id );
			self::record(
				'download',
				$name,
				[
					'note'    => sprintf( 'Downloaded: %s (%s)', $name, $url ),
					'post_id' => (int) wp_get_post_parent_id( $attachment_id ),
					'dedupe'  => 'file-' . $attachment_id,
				]
			);
		}
		return $url;
	}

	/**
	 * Serve a tracked download link: record it, then send the visitor to the file.
	 *
	 * @return void
	 */
	public static function maybe_download(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is checked in handle_download().
		if ( ! isset( $_GET[ self::DOWNLOAD_ARG ] ) ) {
			return;
		}
		$id    = absint( $_GET[ self::DOWNLOAD_ARG ] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		$url = self::handle_download( $id, $nonce );
		if ( '' === $url ) {
			return;
		}
		// The file is in this site's Media Library, so a safe (same-site) redirect.
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Record a form submission, if recording forms is on.
	 *
	 * @param string $title Form title.
	 * @return void
	 */
	public static function record_form( string $title ): void {
		if ( ! Wormiss_Settings::get( 'record_forms' ) ) {
			return;
		}
		$post_id = (int) get_the_ID();
		self::record(
			'form',
			$title,
			[
				'note'    => sprintf( 'Form submitted: %s', $title ),
				'post_id' => $post_id,
			]
		);
	}

	/**
	 * Gravity Forms submission.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return void
	 */
	public static function gravity_forms( $entry, $form ): void {
		unset( $entry );
		self::record_form( (string) ( is_array( $form ) ? ( $form['title'] ?? '' ) : '' ) );
	}

	/**
	 * WPForms submission.
	 *
	 * @param array $fields    Fields.
	 * @param array $entry     Entry.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Entry ID.
	 * @return void
	 */
	public static function wpforms( $fields, $entry, $form_data, $entry_id = 0 ): void {
		unset( $fields, $entry, $entry_id );
		self::record_form( (string) ( $form_data['settings']['form_title'] ?? '' ) );
	}

	/**
	 * Contact Form 7 submission.
	 *
	 * @param object $contact_form The form.
	 * @return void
	 */
	public static function contact_form_7( $contact_form ): void {
		self::record_form( is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '' );
	}
}
