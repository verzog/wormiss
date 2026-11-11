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
 * Tests for Wormiss_Rest_Client.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The iMIS REST client against a fake iMIS.
 *
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Rest_Client extends Wormiss_Test_Case {

	/**
	 * A query is read across pages, with typed values unwrapped and the token reused.
	 *
	 * @return void
	 */
	public function test_run_iqa_pages_and_unwraps(): void {
		$this->imis->token()
			->reply(
				'Offset=0',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'Name'   => 'Course A',
							'Places' => 12,
						],
					],
					true,
					500
				)
			)
			->reply(
				'Offset=500',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'Name'   => 'Course B',
							'Places' => 0,
						],
					]
				)
			);

		$rows = ( new Wormiss_Rest_Client() )->run_iqa( self::QUERY, [ 'NSW', 'a|b' ] );

		$this->assertSame(
			[
				[
					'Name'   => 'Course A',
					'Places' => 12,
				],
				[
					'Name'   => 'Course B',
					'Places' => 0,
				],
			],
			$rows
		);
		$this->assertSame( 1, $this->imis->count( '/token' ) );
		$iqa = $this->imis->requests[1];
		$this->assertStringContainsString( 'QueryName=' . rawurlencode( self::QUERY ), $iqa['url'] );
		$this->assertStringContainsString( '&Parameter=NSW&Parameter=a%7Cb', $iqa['url'] );
		$this->assertSame( 'Bearer tok-1', $iqa['headers']['Authorization'] );
	}

	/**
	 * The token request uses the password grant with the saved credentials.
	 *
	 * @return void
	 */
	public function test_token_request(): void {
		$this->imis->token();
		$this->assertSame( 'tok-1', ( new Wormiss_Rest_Client() )->get_token() );
		$request = $this->imis->requests[0];
		$this->assertSame( 'https://imis.example.org/token', $request['url'] );
		$this->assertSame( 'password', $request['body']['grant_type'] );
		$this->assertSame( 'svc-wordpress', $request['body']['username'] );
		$this->assertSame( 's3cret&pass', $request['body']['password'] );
	}

	/**
	 * A genuinely empty result is fine, and a successful login is logged without secrets.
	 *
	 * @return void
	 */
	public function test_empty_result_and_login_logged(): void {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( [] ) );
		$this->assertSame( [], ( new Wormiss_Rest_Client() )->run_iqa( self::QUERY ) );
		$endpoints = array_column( Wormiss_Logger::recent( 5 ), 'endpoint' );
		$this->assertContains( 'POST /token', $endpoints );
		foreach ( Wormiss_Logger::recent( 5 ) as $row ) {
			$this->assertStringNotContainsString( 'tok-1', implode( ' ', array_map( 'strval', $row ) ) );
		}
	}

	/**
	 * An expired token (401) is refreshed once and the call retried.
	 *
	 * @return void
	 */
	public function test_401_refreshes_token_once(): void {
		$this->imis->token( 'old' )
			->reply( '/api/iqa', 401, [ 'Message' => 'Authorization has been denied for this request.' ] )
			->token( 'new' )
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( [ [ 'Name' => 'Course A' ] ] ) );

		$rows = ( new Wormiss_Rest_Client() )->run_iqa( self::QUERY );

		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $this->imis->count( '/token' ) );
		$this->assertSame( 'Bearer new', end( $this->imis->requests )['headers']['Authorization'] );
	}

	/**
	 * A rejected login throws with iMIS's reason, and the password never appears in the log.
	 *
	 * @return void
	 */
	public function test_token_failure_throws_with_reason(): void {
		$this->imis->reply( '/token', 400, [ 'error_description' => 'The user name or password is incorrect.' ] );
		try {
			( new Wormiss_Rest_Client() )->get_token();
			$this->fail( 'Expected an exception.' );
		} catch ( Wormiss_Exception $e ) {
			$this->assertStringContainsString( 'The user name or password is incorrect.', $e->getMessage() );
		}
		foreach ( Wormiss_Logger::recent( 10 ) as $row ) {
			$this->assertStringNotContainsString( 's3cret', implode( ' ', array_map( 'strval', $row ) ) );
		}
	}

	/**
	 * A server error, a timeout and an unreadable body all throw rather than returning partial rows.
	 *
	 * @return void
	 */
	public function test_failures_throw(): void {
		$this->imis->token()->reply( '/api/iqa', 500, '<html><script>x()</script><h1>Server Error</h1></html>' );
		$this->assert_throws_containing( 'HTTP 500: Server Error' );

		$this->imis->token()->fail( '/api/iqa' );
		$this->assert_throws_containing( 'timed out' );

		$this->imis->token()
			->reply( 'Offset=0', 200, Wormiss_Fake_Imis::page( [ [ 'Name' => 'A' ] ], true, 500 ) )
			->reply( 'Offset=500', 200, 'not json' );
		$this->assert_throws_containing( 'could not be read' );

		// An error message sent with HTTP 200 is not an empty page.
		$this->imis->token()
			->reply( 'Offset=0', 200, Wormiss_Fake_Imis::page( [ [ 'Name' => 'A' ] ], true, 500 ) )
			->reply( 'Offset=500', 200, [ 'Message' => 'Query failed' ] );
		$this->assert_throws_containing( 'could not be read' );
	}

	/**
	 * An unconfigured connection throws without contacting anything.
	 *
	 * @return void
	 */
	public function test_unconfigured_throws(): void {
		delete_option( Wormiss_Settings::PASSWORD_OPTION );
		$this->assert_throws_containing( 'not set up' );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Error reasons are short single lines without markup.
	 *
	 * @return void
	 */
	public function test_error_reason(): void {
		$this->assertSame( 'Bad prompt', Wormiss_Rest_Client::error_reason( '{"Message":"Generic","ExceptionMessage":"Bad prompt"}' ) );
		$this->assertSame( 'Plain', Wormiss_Rest_Client::error_reason( '"Plain"' ) );
		$this->assertSame( 'Oops it broke', Wormiss_Rest_Client::error_reason( "<p>Oops\n <b>it</b> broke</p><style>p{}</style>" ) );
		$this->assertSame( 300, mb_strlen( Wormiss_Rest_Client::error_reason( str_repeat( 'x', 400 ) ) ) );
	}

	/**
	 * Run the approved query and check the exception message.
	 *
	 * @param string $needle Expected text in the message.
	 * @return void
	 */
	private function assert_throws_containing( string $needle ): void {
		try {
			( new Wormiss_Rest_Client() )->run_iqa( self::QUERY );
			$this->fail( 'Expected an exception.' );
		} catch ( Wormiss_Exception $e ) {
			$this->assertStringContainsString( $needle, $e->getMessage() );
		}
	}
}
