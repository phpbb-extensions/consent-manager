<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\acp;

class consentmanager_info
{
	public function module()
	{
		return array(
			'filename'	=> '\phpbb\consentmanager\acp\consentmanager_module',
			'title'		=> 'ACP_CONSENTMANAGER',
			'modes'		=> array(
				'settings'	=> array(
					'title' => 'ACP_CONSENTMANAGER_SETTINGS',
					'auth' => 'ext_phpbb/consentmanager && acl_a_board',
					'cat' => array('ACP_CONSENTMANAGER'),
				),
			),
		);
	}
}
