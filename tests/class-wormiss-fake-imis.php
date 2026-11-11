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
 * A fake iMIS for tests: intercepts WordPress HTTP requests and replies from a script.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Scripted HTTP responses keyed by URL fragment, with a record of every request made.
 */
class Wormiss_Fake_Imis {

	/**
	 * Queued [fragment, response] pairs, used in order.
	 *
	 * @var array[]
	 */
	private array $queue = [];

	/**
	 * Requests made: url, method, body, headers.
	 *
	 * @var array[]
	 */
	public array $requests = [];

	/**
	 * Start intercepting requests.
	 */
	public function __construct() {
		add_filter( 'pre_http_request', [ $this, 'intercept' ], 10, 3 );
	}

	/**
	 * Stop intercepting.
	 *
	 * @return void
	 */
	public function stop(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept' ], 10 );
	}

	/**
	 * Queue a reply for the next request whose URL contains $fragment.
	 *
	 * @param string       $fragment URL fragment, e.g. '/token' or '/api/iqa'.
	 * @param int          $code     HTTP status.
	 * @param string|array $body     Body; arrays are JSON-encoded.
	 * @return self
	 */
	public function reply( string $fragment, int $code, $body ): self {
		$this->queue[] = [ $fragment, $code, is_array( $body ) ? wp_json_encode( $body ) : $body ];
		return $this;
	}

	/**
	 * Queue a transport failure (no HTTP response at all).
	 *
	 * @param string $fragment URL fragment.
	 * @return self
	 */
	public function fail( string $fragment ): self {
		$this->queue[] = [ $fragment, 0, null ];
		return $this;
	}

	/**
	 * A successful token reply.
	 *
	 * @param string $token Token value.
	 * @return self
	 */
	public function token( string $token = 'tok-1' ): self {
		return $this->reply(
			'/token',
			200,
			[
				'access_token' => $token,
				'expires_in'   => 3600,
			]
		);
	}

	/**
	 * A GenericEntityData page of IQA rows.
	 *
	 * @param array[] $rows     Rows as name => value.
	 * @param bool    $has_next Whether another page follows.
	 * @param int     $next     Next offset.
	 * @return array
	 */
	public static function page( array $rows, bool $has_next = false, int $next = 0 ): array {
		$items = [];
		foreach ( $rows as $row ) {
			$props = [];
			foreach ( $row as $name => $value ) {
				$props[] = [
					'Name'  => $name,
					'Value' => is_int( $value ) ? [
						'$type'  => 'System.Int32',
						'$value' => $value,
					] : $value,
				];
			}
			$items[] = [ 'Properties' => [ '$values' => $props ] ];
		}
		return [
			'Items'      => [ '$values' => $items ],
			'HasNext'    => $has_next,
			'NextOffset' => $next,
		];
	}

	/**
	 * The pre_http_request filter.
	 *
	 * @param false|array $pre  Short-circuit value.
	 * @param array       $args Request arguments.
	 * @param string      $url  URL.
	 * @return array|WP_Error
	 */
	public function intercept( $pre, $args, $url ) {
		$this->requests[] = [
			'url'     => $url,
			'method'  => $args['method'] ?? 'GET',
			'body'    => $args['body'] ?? null,
			'headers' => $args['headers'] ?? [],
		];
		foreach ( $this->queue as $i => [ $fragment, $code, $body ] ) {
			if ( false !== strpos( $url, $fragment ) ) {
				unset( $this->queue[ $i ] );
				if ( 0 === $code ) {
					return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
				}
				return [
					'headers'  => [],
					'body'     => (string) $body,
					'response' => [
						'code'    => $code,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => null,
				];
			}
		}
		return new WP_Error( 'http_request_failed', 'Unexpected request in test: ' . $url );
	}

	/**
	 * Number of requests whose URL contains $fragment.
	 *
	 * @param string $fragment URL fragment.
	 * @return int
	 */
	public function count( string $fragment ): int {
		return count( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $fragment ) ) );
	}
}
