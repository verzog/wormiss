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
 * Tests for email diversion.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Email diversion.
 *
 * @covers Wormiss_Mail_Divert
 */
class Test_Wormiss_Mail_Divert extends Wormiss_Test_Case {

	/**
	 * Turn diversion on.
	 *
	 * @param string $except Exceptions.
	 * @return void
	 */
	private function divert( string $except = '' ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'mail_divert_to'     => 'tester@example.com',
					'mail_divert_except' => $except,
				]
			)
		);
		reset_phpmailer_instance();
	}

	/**
	 * Who the last email went to.
	 *
	 * @return string[]
	 */
	private function sent_to(): array {
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		return array_map( static fn( array $to ): string => $to[0], $mail->to );
	}

	/**
	 * Off by default: emails go to the people they are for.
	 *
	 * @return void
	 */
	public function test_off(): void {
		reset_phpmailer_instance();
		wp_mail( 'ann@example.org', 'Hello', 'Text' );
		$this->assertSame( [ 'ann@example.org' ], $this->sent_to() );
		$this->assertSame( 'Hello', tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	/**
	 * On: emails (copies too) go to the divert address, the subject naming who they were for;
	 * an email only to excepted addresses and domains still goes to them.
	 *
	 * @return void
	 */
	public function test_divert(): void {
		$this->divert( '@college.example, keep@example.net' );
		wp_mail( 'Ann Lee <ann@example.org>', 'Hello', 'Text', [ 'Cc: bob@example.org', 'Reply-To: office@example.org' ] );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( [ 'tester@example.com' ], $this->sent_to() );
		$this->assertSame( '[Diverted from ann@example.org, bob@example.org] Hello', $mail->subject );
		$this->assertStringNotContainsString( 'Cc:', (string) $mail->header, 'No copy goes out.' );

		$this->divert( '@college.example, keep@example.net' );
		wp_mail( [ 'staff@college.example', 'keep@example.net' ], 'Kept', 'Text' );
		$this->assertSame( [ 'staff@college.example', 'keep@example.net' ], $this->sent_to() );
		$this->assertSame( 'Kept', tests_retrieve_phpmailer_instance()->get_sent()->subject );

		// Mixed: only the tester gets it, so a hidden copy is never shown to the others.
		$this->divert( 'college.example' );
		wp_mail( 'staff@college.example', 'Mixed', 'Text', [ 'Bcc: auditor@example.org' ] );
		$this->assertSame( [ 'tester@example.com' ], $this->sent_to() );
		$this->assertSame( '[Diverted from staff@college.example, auditor@example.org] Mixed', tests_retrieve_phpmailer_instance()->get_sent()->subject );

		// Spaces inside the brackets do not slip past.
		$this->divert();
		wp_mail( 'Ann < ann@example.org >', 'Spaced', 'Text' );
		$this->assertSame( [ 'tester@example.com' ], $this->sent_to() );
	}

	/**
	 * Settings keep only an email address and clean addresses or domains.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$clean = Wormiss_Settings::sanitize(
			[
				'mail_divert_to'     => 'not an email',
				'mail_divert_except' => "@College.example\nkeep@example.net, junk!, example.org",
			]
		);
		$this->assertSame( '', $clean['mail_divert_to'] );
		$this->assertSame( '@college.example, keep@example.net, example.org', $clean['mail_divert_except'] );
	}
}
