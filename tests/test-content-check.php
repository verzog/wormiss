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
 * Tests for checking a news article.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Checking a news article makes only fixed read-only lookups, and only for a proper key.
 *
 * @covers Wormiss_Content_Check
 */
class Test_Wormiss_Content_Check extends Wormiss_Test_Case {

	/**
	 * Only an iMIS key is accepted.
	 *
	 * @return void
	 */
	public function test_is_key(): void {
		$this->assertTrue( Wormiss_Content_Check::is_key( '6fdbde19-109c-43f8-b008-8b95e69f1704' ) );
		$this->assertTrue( Wormiss_Content_Check::is_key( '6FDBDE19-109C-43F8-B008-8B95E69F1704' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '6fdbde19-109c-43f8-b008-8b95e69f1704&x=1' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '../Party/123' ) );
		$this->assertSame( [], Wormiss_Content_Check::run( 'not a key' ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * The Document is found by its version key, then read by its own key; its Blob is decoded
	 * (plain or compressed) and kept out of the reply shown. Every lookup is a GET.
	 *
	 * @return void
	 */
	public function test_run(): void {
		$key      = '6fdbde19-109c-43f8-b008-8b95e69f1704';
		$doc      = '11111111-2222-3333-4444-555555555555';
		$xml      = '<ContentItem><Html>&lt;p&gt;Vale Professor&lt;/p&gt;</Html></ContentItem>';
		$document = static function ( string $blob ) use ( $doc ): array {
			return [
				'$type'      => 'Asi.Soa.Core.DataContracts.GenericEntityData',
				'Properties' => [
					'$values' => [
						[
							'Name'  => 'DocumentKey',
							'Value' => $doc,
						],
						[
							'Name'  => 'Blob',
							'Value' => [
								'$type'  => 'System.Byte[], mscorlib',
								'$value' => $blob,
							],
						],
					],
				],
			];
		};
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data as iMIS sends it.
		$this->imis->token()
			->reply( '/api/Document?DocumentVersionKey=' . $key, 200, [ 'Items' => [ '$values' => [ $document( base64_encode( $xml ) ) ] ] ] )
			->reply( '/api/DocumentPath', 404, '' )
			->reply( '/api/Document/' . $doc, 200, $document( base64_encode( gzencode( $xml ) ) ) );
		// phpcs:enable
		$results = Wormiss_Content_Check::run( strtoupper( $key ) );
		$this->assertCount( 3, $results );
		$this->assertTrue( $results[0]['ok'] );
		$this->assertSame( '/api/Document?DocumentVersionKey=' . $key, $results[0]['path'] );
		$this->assertSame( $doc, $results[0]['document_key'] );
		$this->assertSame( [ $xml ], $results[0]['decoded'] );
		$this->assertStringContainsString( 'decoded below', $results[0]['body'] );
		$this->assertStringContainsString( '404', $results[1]['status'] );
		$this->assertSame( '/api/Document/' . $doc, $results[2]['path'] );
		$this->assertSame( [ $xml ], $results[2]['decoded'], 'Compressed content is unzipped.' );
		foreach ( $this->imis->requests as $request ) {
			if ( 'POST' === $request['method'] ) {
				$this->assertStringContainsString( '/token', $request['url'] );
			} else {
				$this->assertSame( 'GET', $request['method'] );
			}
		}
	}

	/**
	 * No Document found: no lookup by a document key.
	 *
	 * @return void
	 */
	public function test_not_found(): void {
		$this->imis->token()
			->reply( '/api/Document', 404, '' )
			->reply( '/api/DocumentPath', 404, '' );
		$this->assertCount( 2, Wormiss_Content_Check::run( '6fdbde19-109c-43f8-b008-8b95e69f1704' ) );
	}

	/**
	 * Content in UTF-16 (either byte order) or deflated is decoded; binary is reported.
	 *
	 * @return void
	 */
	public function test_decode_blob(): void {
		$xml = '<ContentItem><Html>Article text</Html></ContentItem>';
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data as iMIS sends it.
		$this->assertSame( $xml, Wormiss_Content_Check::decode_blob( base64_encode( "\xff\xfe" . mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ) ) ) );
		$this->assertSame( $xml, Wormiss_Content_Check::decode_blob( base64_encode( "\xfe\xff" . mb_convert_encoding( $xml, 'UTF-16BE', 'UTF-8' ) ) ) );
		$this->assertSame( $xml, Wormiss_Content_Check::decode_blob( base64_encode( gzdeflate( $xml ) ) ) );
		$this->assertStringContainsString( 'not text', Wormiss_Content_Check::decode_blob( base64_encode( "\x00\xff\x80\x81" ) ) );
		// phpcs:enable
	}

	/**
	 * Long replies are cut.
	 *
	 * @return void
	 */
	public function test_pretty(): void {
		$this->assertStringContainsString( "\n", Wormiss_Content_Check::pretty( '{"a":1,"b":2}' ) );
		$long = Wormiss_Content_Check::pretty( str_repeat( 'x', Wormiss_Content_Check::MAX_SHOWN + 10 ) );
		$this->assertSame( Wormiss_Content_Check::MAX_SHOWN + 2, mb_strlen( $long ) );
	}
}
