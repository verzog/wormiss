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
 * Client for the iMIS REST API.
 *
 * Adapted from local_membershipbridge's rest_client (Moodle) for WordPress's HTTP API:
 * OAuth password-grant token, one retry on 401, IQA queries with paging, and the
 * GenericEntityData helpers that unwrap iMIS's `$values` / `$value` envelopes.
 *
 * @package    wormiss
 * @copyright  2024 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the iMIS REST API.
 */
class Wormiss_Rest_Client {

	/** Rows requested per IQA page. */
	const PAGE_SIZE = 500;

	/** Most rows read from one query, so a mistaken query cannot exhaust the server. */
	const MAX_ROWS = 5000;

	/**
	 * The iMIS site root.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Service-account username.
	 *
	 * @var string
	 */
	private string $username;

	/**
	 * Service-account password.
	 *
	 * @var string
	 */
	private string $password;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Cached bearer token for this instance.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Unix time after which the cached token must be refreshed.
	 *
	 * @var int
	 */
	private int $token_expiry = 0;

	/**
	 * Build a client from the plugin settings, or from explicit values (tests).
	 *
	 * @param string|null $base_url iMIS site root.
	 * @param string|null $username Service-account username.
	 * @param string|null $password Service-account password.
	 * @param int|null    $timeout  Timeout in seconds.
	 */
	public function __construct( ?string $base_url = null, ?string $username = null, ?string $password = null, ?int $timeout = null ) {
		$this->base_url = $base_url ?? Wormiss_Settings::base_url();
		$this->username = $username ?? Wormiss_Settings::username();
		$this->password = $password ?? Wormiss_Settings::password();
		$this->timeout  = $timeout ?? (int) Wormiss_Settings::get( 'timeout' );
	}

	/**
	 * Obtain a bearer token via the OAuth password grant, caching it for this instance.
	 *
	 * @param bool $force Request a new token even if the cached one looks valid.
	 * @return string The bearer token.
	 * @throws Wormiss_Exception If iMIS is not configured or does not return a token.
	 */
	public function get_token( bool $force = false ): string {
		if ( ! $force && null !== $this->token && time() < $this->token_expiry ) {
			return $this->token;
		}
		if ( '' === $this->base_url || '' === $this->username || '' === $this->password ) {
			throw new Wormiss_Exception( __( 'The iMIS connection is not set up.', 'wormiss' ) );
		}

		$response = wp_remote_post(
			$this->base_url . '/token',
			[
				'timeout' => $this->timeout,
				'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'    => [
					'grant_type' => 'password',
					'username'   => $this->username,
					'password'   => $this->password,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			Wormiss_Logger::record_call( 'POST /token', Wormiss_Logger::STATUS_ERROR, $error );
			throw new Wormiss_Exception( Wormiss_Logger::redact( $error ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw );
		if ( 200 !== $code || ! is_object( $decoded ) || empty( $decoded->access_token ) ) {
			$reason = self::error_reason( $raw );
			$error  = 'HTTP ' . $code . ( '' !== $reason ? ': ' . $reason : '' );
			Wormiss_Logger::record_call( 'POST /token', Wormiss_Logger::STATUS_ERROR, $error );
			/* translators: %s: HTTP status and reason. */
			throw new Wormiss_Exception( sprintf( __( 'iMIS refused the login (%s).', 'wormiss' ), Wormiss_Logger::redact( $error ) ) );
		}

		// Logged without the request or response, which hold the password and the token.
		Wormiss_Logger::record_call( 'POST /token', Wormiss_Logger::STATUS_SUCCESS );
		$this->token = (string) $decoded->access_token;
		// Refresh a minute before the stated expiry to avoid racing the cut-off.
		$expires_in         = (int) ( $decoded->expires_in ?? 3600 );
		$this->token_expiry = time() + max( 0, $expires_in - 60 );
		return $this->token;
	}

	/**
	 * Perform an authenticated request, refreshing the token once on a 401.
	 *
	 * @param string      $method       GET, POST, PUT or DELETE.
	 * @param string      $path         Path beginning with '/', appended to the base URL.
	 * @param array|null  $json         Body to send as JSON, or null.
	 * @param string|null $imis_id      The contact the call is about, for the audit log.
	 * @param bool        $capture_body Whether a successful response body may be kept in the log.
	 *                                  False for bulk reads that hold many contacts' data.
	 * @return object {code:int, body:mixed, raw:string}
	 * @throws Wormiss_Exception On a non-2xx response or transport failure.
	 */
	public function request(
		string $method,
		string $path,
		?array $json = null,
		?string $imis_id = null,
		bool $capture_body = true
	): object {
		$result = $this->raw_request( $method, $path, $json, $this->get_token() );

		// A 401 means the token expired or was revoked: refresh once and retry.
		if ( 401 === $result->code ) {
			$result = $this->raw_request( $method, $path, $json, $this->get_token( true ) );
		}

		$ok     = $result->code >= 200 && $result->code < 300;
		$reason = $ok ? '' : ( '' !== $result->error ? $result->error : self::error_reason( $result->raw ) );
		// A transport failure has no HTTP status: its reason is the whole error.
		$status = 0 === $result->code ? '' : 'HTTP ' . $result->code;
		$error  = '' === $reason ? $status : ( '' === $status ? $reason : $status . ': ' . $reason );

		Wormiss_Logger::record_call(
			strtoupper( $method ) . ' ' . $path,
			$ok ? Wormiss_Logger::STATUS_SUCCESS : Wormiss_Logger::STATUS_ERROR,
			$ok ? null : $error,
			null !== $json ? (string) wp_json_encode( $json ) : null,
			( $capture_body || ! $ok ) ? $result->raw : null,
			$imis_id
		);

		if ( ! $ok ) {
			throw new Wormiss_Exception( Wormiss_Logger::redact( $error ) );
		}
		return $result;
	}

	/**
	 * One HTTP request with the given token (no retry).
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path beginning with '/'.
	 * @param array|null $json   JSON body or null.
	 * @param string     $token  Bearer token.
	 * @return object {code:int, body:mixed, raw:string, error:string}
	 */
	private function raw_request( string $method, string $path, ?array $json, string $token ): object {
		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => $this->timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
		];
		if ( null !== $json ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $json );
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			return (object) [
				'code'  => 0,
				'body'  => null,
				'raw'   => '',
				'error' => $response->get_error_message(),
			];
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		return (object) [
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'  => json_decode( $raw ),
			'raw'   => $raw,
			'error' => '',
		];
	}

	/**
	 * Run an IQA (saved query) and return all its rows as name => value arrays.
	 *
	 * Prompts are passed as repeated `Parameter` entries in prompt order. Pages are
	 * read to the end (up to MAX_ROWS). A page with an unreadable body aborts the
	 * whole read rather than returning a partial set.
	 *
	 * @param string      $query      IQA document path, e.g. '$/Events/UpcomingEvents'.
	 * @param array       $parameters Prompt values in prompt order.
	 * @param string|null $imis_id    The member the query is about, for the audit log.
	 * @return array[] Rows, each property name => unwrapped value.
	 * @throws Wormiss_Exception On any failed or unreadable page.
	 */
	public function run_iqa( string $query, array $parameters = [], ?string $imis_id = null ): array {
		$base = '/api/iqa?QueryName=' . rawurlencode( $query );
		foreach ( $parameters as $value ) {
			$base .= '&Parameter=' . rawurlencode( (string) $value );
		}

		$rows   = [];
		$offset = 0;
		do {
			$path   = $base . '&Limit=' . self::PAGE_SIZE . '&Offset=' . $offset;
			$result = $this->request( 'GET', $path, null, $imis_id, false );
			if ( ! self::is_paged_result( $result->body ) ) {
				// Not a page of results (e.g. an error message sent with HTTP 200): never treat it
				// as an empty page, or a partial read would be cached as complete.
				Wormiss_Logger::record_call( 'GET ' . $path, Wormiss_Logger::STATUS_ERROR, 'Unreadable response body' );
				throw new Wormiss_Exception( __( 'iMIS sent a response that could not be read.', 'wormiss' ) );
			}
			$page = self::items( $result->body );
			foreach ( $page as $row ) {
				$rows[] = self::properties( $row );
			}
			$page_count            = count( $page );
			$row_count             = count( $rows );
			[ $has_next, $offset ] = self::next_page( $result->body, $offset, $page_count );
			// An empty page ends the read even if iMIS says there is more, so a bad offset cannot loop.
		} while ( $has_next && $page_count > 0 && $row_count < self::MAX_ROWS );

		return array_slice( $rows, 0, self::MAX_ROWS );
	}

	/**
	 * Create an activity record in iMIS.
	 *
	 * @param array  $body    Body from build_activity_body().
	 * @param string $imis_id The member's iMIS ID, for the audit log.
	 * @return object The response.
	 * @throws Wormiss_Exception On failure.
	 */
	public function add_activity( array $body, string $imis_id ): object {
		return $this->request( 'POST', '/api/Activity', $body, $imis_id );
	}

	/**
	 * An iMIS Activity record (GenericEntityData) for something a member did on the site.
	 *
	 * Ported from local_membershipbridge's note record: iMIS rejected an activity carrying
	 * only the filled-in columns and accepted one with every column, typed decimals, ints
	 * and booleans, and the envelope `$type`s, so every column is sent.
	 *
	 * @param string $activity_type iMIS activity type (e.g. WEBACTIVITY).
	 * @param string $imis_id       The member's iMIS ID.
	 * @param string $kind          read, download, completion or form (stored in NOTE_2).
	 * @param string $description   What it was about (DESCRIPTION, max 255).
	 * @param string $note          Detail, e.g. the title and page address (NOTE).
	 * @param string $date          ISO date-time, e.g. 2026-11-12T09:30:00.
	 * @param float  $points        CPD points or score (UF_4).
	 * @param int    $post_id       WordPress post ID (UF_5).
	 * @return array
	 */
	public static function build_activity_body(
		string $activity_type,
		string $imis_id,
		string $kind,
		string $description,
		string $note,
		string $date,
		float $points = 0,
		int $post_id = 0
	): array {
		$values = [
			'ACTION_CODES'          => null,
			'ACTIVITY_TYPE'         => $activity_type,
			'AMOUNT'                => self::typed( 'System.Decimal', 0 ),
			'ATTACH_SEQN'           => self::typed( 'System.Int32', 0 ),
			'ATTACH_TOTAL'          => self::typed( 'System.Int32', 0 ),
			'BATCH_NUM'             => null,
			'CAMPAIGN_CODE'         => null,
			'CATEGORY'              => null,
			'CO_ID'                 => null,
			'DESCRIPTION'           => mb_substr( $description, 0, 255 ),
			'EFFECTIVE_DATE'        => null,
			'GRACE_PERIOD'          => self::typed( 'System.Int32', 0 ),
			'PartyId'               => $imis_id,
			'INTENT_TO_EDIT'        => null,
			'MEM_TRIB_CODE'         => null,
			'MEMBER_TYPE'           => null,
			'NEXT_INSTALL_DATE'     => null,
			'NOTE'                  => $note,
			'NOTE_2'                => $kind,
			'OBJECT'                => null,
			'ORG_CODE'              => null,
			'ORIGINATING_TRANS_NUM' => self::typed( 'System.Int32', 0 ),
			'OTHER_CODE'            => null,
			'OTHER_ID'              => null,
			'PAY_METHOD'            => null,
			'PRODUCT_CODE'          => null,
			'QUANTITY'              => self::typed( 'System.Decimal', 0 ),
			'RECURRING_REQUEST'     => self::typed( 'System.Boolean', false ),
			'SOLICITOR_ID'          => null,
			'SOURCE_CODE'           => 'WORDPRESS',
			'SOURCE_SYSTEM'         => null,
			'STATUS_CODE'           => null,
			'TAXABLE_VALUE'         => self::typed( 'System.Decimal', 0 ),
			'THRU_DATE'             => $date,
			'TICKLER_DATE'          => null,
			'TRANSACTION_DATE'      => $date,
			'UF_1'                  => null,
			'UF_2'                  => null,
			'UF_3'                  => null,
			'UF_4'                  => self::typed( 'System.Decimal', $points ),
			'UF_5'                  => self::typed( 'System.Decimal', $post_id ),
			'UF_6'                  => null,
			'UF_7'                  => null,
			'UNITS'                 => self::typed( 'System.Decimal', 0 ),
		];

		$props = [];
		foreach ( $values as $name => $value ) {
			$prop = [
				'$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts',
				'Name'  => $name,
			];
			if ( null !== $value && '' !== $value ) {
				$prop['Value'] = $value;
			}
			$props[] = $prop;
		}

		return [
			'$type'                       => 'Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts',
			'EntityTypeName'              => 'Activity',
			'PrimaryParentEntityTypeName' => 'Party',
			'PrimaryParentIdentity'       => [
				'$type'            => 'Asi.Soa.Core.DataContracts.IdentityData, Asi.Contracts',
				'EntityTypeName'   => 'Party',
				'IdentityElements' => [
					'$type'   => 'System.Collections.ObjectModel.Collection`1[[System.String, mscorlib]], mscorlib',
					'$values' => [ $imis_id ],
				],
			],
			'Properties'                  => [
				'$type'   => 'Asi.Soa.Core.DataContracts.GenericPropertyDataCollection, Asi.Contracts',
				'$values' => $props,
			],
		];
	}

	/**
	 * A .NET-typed value for iMIS.
	 *
	 * @param string $type  .NET type name.
	 * @param mixed  $value Value.
	 * @return array
	 */
	private static function typed( string $type, $value ): array {
		return [
			'$type'  => $type,
			'$value' => $value,
		];
	}

	/**
	 * A short, single-line reason from an iMIS error body, without markup (max 300 characters).
	 *
	 * @param string $raw Raw response body.
	 * @return string
	 */
	public static function error_reason( string $raw ): string {
		$decoded = json_decode( $raw );
		$text    = '';
		if ( is_string( $decoded ) ) {
			$text = $decoded;
		} elseif ( is_object( $decoded ) ) {
			foreach ( [ 'ExceptionMessage', 'error_description', 'Message', 'message', 'error' ] as $key ) {
				if ( isset( $decoded->$key ) && is_scalar( $decoded->$key ) && '' !== trim( (string) $decoded->$key ) ) {
					$text = (string) $decoded->$key;
					break;
				}
			}
		}
		if ( '' === $text && null === $decoded ) {
			// An HTML error page: drop scripts and styles, whose text would crowd out the reason.
			$text = (string) ( preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $raw ) ?? $raw );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		return mb_strlen( $text ) > 300 ? mb_substr( $text, 0, 297 ) . '...' : $text;
	}

	/**
	 * Whether a decoded body is a page of results: an object with an `Items.$values` list
	 * (which may be empty).
	 *
	 * @param mixed $body Decoded body.
	 * @return bool
	 */
	public static function is_paged_result( $body ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
		return is_object( $body ) && is_object( $body->Items ?? null ) && is_array( $body->Items->{'$values'} ?? null );
	}

	/**
	 * The `Items.$values` rows of a paged response.
	 *
	 * @param object $body Decoded body.
	 * @return array
	 */
	public static function items( object $body ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
		return self::values( $body->Items ?? null );
	}

	/**
	 * Resolve the next page from a PagedResult, stopping when paging fields are absent.
	 *
	 * @param object $body       Decoded body.
	 * @param int    $offset     Current offset.
	 * @param int    $page_count Rows in the current page.
	 * @return array [bool $has_next, int $next_offset]
	 */
	public static function next_page( object $body, int $offset, int $page_count ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( isset( $body->HasNext ) ) {
			$has_next    = (bool) ( is_object( $body->HasNext ) ? ( $body->HasNext->{'$value'} ?? false ) : $body->HasNext );
			$next_offset = isset( $body->NextOffset )
				? (int) ( is_object( $body->NextOffset ) ? ( $body->NextOffset->{'$value'} ?? 0 ) : $body->NextOffset )
				: $offset + $page_count;
			// phpcs:enable
			return [ $has_next, $next_offset ];
		}
		return [ false, $offset + $page_count ];
	}

	/**
	 * A GenericEntityData row's properties as name => value, type-wrapped scalars unwrapped.
	 *
	 * @param object $entity Row.
	 * @return array
	 */
	public static function properties( object $entity ): array {
		$out = [];
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		foreach ( self::values( $entity->Properties ?? null ) as $prop ) {
			if ( ! is_object( $prop ) || ! isset( $prop->Name ) ) {
				continue;
			}
			$value                       = $prop->Value ?? null;
			$out[ (string) $prop->Name ] = ( is_object( $value ) && property_exists( $value, '$value' ) ) ? $value->{'$value'} : $value;
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * The `$values` array of a typed collection, or an empty array.
	 *
	 * @param mixed $collection Decoded `{"$values":[...]}` object, or null.
	 * @return array
	 */
	private static function values( $collection ): array {
		if ( is_object( $collection ) && isset( $collection->{'$values'} ) && is_array( $collection->{'$values'} ) ) {
			return $collection->{'$values'};
		}
		return [];
	}
}
