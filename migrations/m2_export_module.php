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

class m2_export_module extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\phpbb\consentmanager\migrations\m1_initial'];
	}

	public function effectively_installed()
	{
		$sql = 'SELECT module_id
			FROM ' . $this->table_prefix . 'modules
			WHERE ' . $this->db->sql_build_array('SELECT', [
				'module_basename' => '\phpbb\consentmanager\acp\consentmanager_module',
				'module_mode'     => 'export',
			]);

		$result = $this->db->sql_query($sql);
		$row    = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (bool) $row;
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_CONSENTMANAGER', [
				'module_basename' => '\phpbb\consentmanager\acp\consentmanager_module',
				'modes'           => ['export'],
			]]],
		];
	}

	public function revert_data()
	{
		return [
			['module.remove', ['acp', 'ACP_CONSENTMANAGER', 'ACP_CONSENTMANAGER_EXPORT']],
		];
	}
}
