<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\migrations;

class m5_hmac_secret extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['consentmanager_hmac_secret']);
	}

	public static function depends_on()
	{
		return ['\phpbb\consentmanager\migrations\m4_guest_throttling'];
	}

	public function update_data()
	{
		return [
			['config.add', ['consentmanager_hmac_secret', (string) $this->config['rand_seed']]],
		];
	}

	public function revert_data()
	{
		return [
			['config.remove', ['consentmanager_hmac_secret']],
		];
	}
}
