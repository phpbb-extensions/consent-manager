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

class m4_guest_throttling extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'consentmanager_logs', 'throttle_id');
	}

	public static function depends_on()
	{
		return [
			'\phpbb\consentmanager\migrations\m1_initial',
			'\phpbb\consentmanager\migrations\m3_banner_translations'
		];
	}

	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'consentmanager_logs' => [
					'throttle_id' => ['VCHAR:64', ''],
				],
			],
			'add_index' => [
				$this->table_prefix . 'consentmanager_logs' => [
					'throttle_id' => ['throttle_id'],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_keys' => [
				$this->table_prefix . 'consentmanager_logs' => [
					'throttle_id',
				],
			],
			'drop_columns' => [
				$this->table_prefix . 'consentmanager_logs' => [
					'throttle_id',
				],
			],
		];
	}
}
