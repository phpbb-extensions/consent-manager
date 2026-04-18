<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\service;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class log_manager
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var EventDispatcherInterface */
	protected $dispatcher;

	/** @var user */
	protected $user;

	/** @var string */
	protected $consent_logs_table;

	public function __construct(
		config $config,
		driver_interface $db,
		EventDispatcherInterface $dispatcher,
		user $user,
		$consent_logs_table
	) {
		$this->config = $config;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->user = $user;
		$this->consent_logs_table = $consent_logs_table;
	}

	public function log_consent(array $categories, $version)
	{
		$record = array(
			'anonymized_id' => $this->get_anonymized_subject(),
			'consent_version' => (int) $version,
			'accepted_categories' => json_encode(array_values($categories)),
			'consent_time' => time(),
		);

		$sql = 'INSERT INTO ' . $this->consent_logs_table . ' ' . $this->db->sql_build_array('INSERT', $record);
		$this->db->sql_query($sql);

		$vars = array('record');
		extract($this->dispatcher->trigger_event('phpbb.consentmanager.after_log', compact($vars)));
	}

	protected function get_anonymized_subject()
	{
		$subject = $this->user->data['user_id'] != ANONYMOUS ? 'u:' . $this->user->data['user_id'] : 's:' . $this->user->session_id;

		return hash_hmac('sha256', $subject, (string) $this->config['rand_seed']);
	}
}
