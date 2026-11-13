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
	 * The document is found by its version ID (FindByVersionId, a POST to _Execute), then read by
	 * its document ID; its content, sent as binary, is decoded (plain or compressed) and kept out
	 * of the reply shown. Nothing but the token and _Execute is a POST.
	 *
	 * @return void
	 */
	public function test_run(): void {
		$key      = '6fdbde19-109c-43f8-b008-8b95e69f1704';
		$doc      = '11111111-2222-3333-4444-555555555555';
		$xml      = '<ContentItem><Html>&lt;p&gt;Vale Professor&lt;/p&gt;</Html></ContentItem>';
		$document = static function ( string $data ) use ( $doc, $key ): array {
			return [
				'$type'             => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
				'DocumentId'        => $doc,
				'DocumentVersionId' => $key,
				'Data'              => [
					'$type'  => 'System.Byte[], mscorlib',
					'$value' => $data,
				],
			];
		};
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data as iMIS sends it.
		$this->imis->token()
			->reply( '/api/Document/_Execute', 200, [ 'Result' => $document( base64_encode( $xml ) ) ] )
			->reply( '/api/Document?DocumentVersionId=' . $key, 400, [ 'Message' => 'Not a known property name.' ] )
			->reply( '/api/Document/_metadata', 200, [ 'EntityTypeName' => 'Document' ] )
			->reply( '/api/DocumentSummary/_metadata', 200, [ 'EntityTypeName' => 'DocumentSummary' ] )
			->reply( '/api/Document/' . $doc, 200, $document( base64_encode( gzencode( $xml ) ) ) );
		// phpcs:enable
		$results = Wormiss_Content_Check::run( strtoupper( $key ) );
		$this->assertCount( 5, $results );
		$this->assertTrue( $results[0]['ok'] );
		$this->assertSame( 'POST', $results[0]['method'] );
		$this->assertSame( $doc, $results[0]['document_key'] );
		$this->assertSame( [ $xml ], $results[0]['decoded'] );
		$this->assertStringContainsString( 'decoded below', $results[0]['body'] );
		$this->assertStringContainsString( '400', $results[1]['status'] );
		$this->assertStringContainsString( 'DocumentSummary', $results[3]['body'] );
		$this->assertSame( '/api/Document/' . $doc, $results[4]['path'] );
		$this->assertSame( [ $xml ], $results[4]['decoded'], 'Compressed content is unzipped.' );

		$execute = null;
		foreach ( $this->imis->requests as $request ) {
			if ( 'POST' !== $request['method'] ) {
				$this->assertSame( 'GET', $request['method'] );
			} elseif ( false !== strpos( $request['url'], '_Execute' ) ) {
				$execute = json_decode( (string) $request['body'], true );
			} else {
				$this->assertStringContainsString( '/token', $request['url'] );
			}
		}
		$this->assertSame( 'FindByVersionId', $execute['OperationName'] ?? '' );
		$this->assertSame( 'Document', $execute['EntityTypeName'] ?? '' );
		$this->assertSame( $key, $execute['Parameters']['$values'][0]['$value'] ?? '' );
	}

	/**
	 * Nothing found: no lookup by a document ID (and not by the version key itself).
	 *
	 * @return void
	 */
	public function test_not_found(): void {
		$key = '6fdbde19-109c-43f8-b008-8b95e69f1704';
		$this->imis->token()
			->reply( '/api/Document/_Execute', 200, [ 'Result' => [ 'DocumentVersionId' => $key ] ] )
			->reply( '/api/Document?', 404, '' )
			->reply( '/api/Document/_metadata', 404, '' )
			->reply( '/api/DocumentSummary/_metadata', 404, '' );
		$this->assertCount( 4, Wormiss_Content_Check::run( $key ) );
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
