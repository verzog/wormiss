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
 * Tests for the shared Media Library helpers.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Wormiss_Media::sideload_name() names a downloaded image from its real type (sniffed from the
 * bytes), gives it an extension WordPress accepts regardless of the address, and returns null for a
 * type WordPress does not accept as an image.
 *
 * @covers Wormiss_Media
 */
class Test_Wormiss_Media extends Wormiss_Test_Case {

	/**
	 * Write a small image of the given type to a temp file and return its path.
	 *
	 * @param string $type One of jpeg, png, gif, webp.
	 * @return string Temp file path.
	 */
	private function image_file( string $type ): string {
		$path  = (string) tempnam( sys_get_temp_dir(), 'wormiss-img' );
		$image = imagecreatetruecolor( 4, 4 );
		switch ( $type ) {
			case 'png':
				imagepng( $image, $path );
				break;
			case 'gif':
				imagegif( $image, $path );
				break;
			case 'webp':
				imagewebp( $image, $path );
				break;
			default:
				imagejpeg( $image, $path );
		}
		return $path;
	}

	/**
	 * An extension-less address (as iMIS serves images) still yields a name WordPress accepts, taken
	 * from the real type of the bytes.
	 *
	 * @return void
	 */
	public function test_names_extensionless_jpeg(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$tmp  = $this->image_file( 'jpeg' );
		$name = Wormiss_Media::sideload_name( $tmp, 'https://imis.example.org/iCore/GetImage?id=7', 'page-image' );
		wp_delete_file( $tmp );
		$this->assertSame( 'GetImage.jpg', $name );
	}

	/**
	 * A misleading address extension (.aspx) is replaced with the real type's extension.
	 *
	 * @return void
	 */
	public function test_corrects_misleading_extension(): void {
		if ( ! function_exists( 'imagepng' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$tmp  = $this->image_file( 'png' );
		$name = Wormiss_Media::sideload_name( $tmp, 'https://imis.example.org/images/logo.aspx', 'page-image' );
		wp_delete_file( $tmp );
		$this->assertSame( 'logo.png', $name );
	}

	/**
	 * WebP is named .webp (a format WordPress accepts), proving the set follows WordPress rather than
	 * a hard-coded list.
	 *
	 * @return void
	 */
	public function test_names_webp(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD WebP support is not available.' );
		}
		$tmp  = $this->image_file( 'webp' );
		$name = Wormiss_Media::sideload_name( $tmp, 'https://imis.example.org/images/_SCCA/about-us/About-Us.webp', 'page-image' );
		wp_delete_file( $tmp );
		$this->assertSame( 'About-Us.webp', $name );
	}

	/**
	 * When the address has no usable base name, the fallback is used with the real extension.
	 *
	 * @return void
	 */
	public function test_uses_fallback_when_no_base(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$tmp  = $this->image_file( 'jpeg' );
		$name = Wormiss_Media::sideload_name( $tmp, 'https://imis.example.org/', 'news-image' );
		wp_delete_file( $tmp );
		$this->assertSame( 'news-image.jpg', $name );
	}

	/**
	 * A file WordPress does not accept as an image (here, plain text standing in for an SVG or a
	 * login page returned instead of an image) returns null, so the caller can skip it.
	 *
	 * @return void
	 */
	public function test_rejects_non_image(): void {
		$path = (string) tempnam( sys_get_temp_dir(), 'wormiss-img' );
		file_put_contents( $path, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$name = Wormiss_Media::sideload_name( $path, 'https://imis.example.org/images/vision.svg', 'page-image' );
		wp_delete_file( $path );
		$this->assertNull( $name );
	}
}
