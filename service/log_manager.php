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

class log_manager
{
	/** Seconds during which an unchanged decision is considered a duplicate. */
	public const DUPLICATE_WINDOW = 300;

	/** Maximum accepted decisions per anonymized subject during the rate-limit window. */
	public const RATE_LIMIT_MAX = 20;

	/** Rate-limit window in seconds. */
	public const RATE_LIMIT_WINDOW = 3600;

	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var user */
	protected $user;

	/** @var string */
	protected $consent_logs_table;

	/**
	 * Constructor.
	 *
	 * @param config           $config Config service
	 * @param driver_interface $db Database connection
	 * @param user             $user Current user
	 * @param string           $consent_logs_table Consent log table name
	 */
	public function __construct(config $config, driver_interface $db, user $user, $consent_logs_table)
	{
		$this->config = $config;
		$this->db = $db;
		$this->user = $user;
		$this->consent_logs_table = $consent_logs_table;
	}

	/**
	 * Persist a consent decision for the current subject.
	 *
	 * @param array $categories Accepted category ids
	 * @param int   $version Consent version
	 *
	 * @return bool True when a row was inserted, false when suppressed
	 */
	public function log_consent(array $categories, $version)
	{
		$anonymized_id = $this->get_anonymized_subject();
		$accepted_categories = json_encode(array_values($categories));
		$now = time();

		if ($this->should_suppress_submission($anonymized_id, (int) $version, $accepted_categories, $now))
		{
			return false;
		}

		$record = [
			'anonymized_id' => $anonymized_id,
			'consent_version' => (int) $version,
			'accepted_categories' => $accepted_categories,
			'consent_time' => $now,
		];

		$sql = 'INSERT INTO ' . $this->consent_logs_table . ' ' . $this->db->sql_build_array('INSERT', $record);
		$this->db->sql_query($sql);

		return true;
	}

	/**
	 * Suppress rapid duplicates and excessive submissions from one subject.
	 *
	 * @param string $anonymized_id Anonymized user or guest-session identifier
	 * @param int    $version Consent version
	 * @param string $accepted_categories JSON-encoded normalized categories
	 * @param int    $now Current Unix timestamp
	 *
	 * @return bool
	 */
	protected function should_suppress_submission($anonymized_id, $version, $accepted_categories, $now)
	{
		$sql = 'SELECT consent_version, accepted_categories, consent_time
			FROM ' . $this->consent_logs_table . "
			WHERE anonymized_id = '" . $this->db->sql_escape($anonymized_id) . "'
				AND consent_time >= " . ((int) $now - self::RATE_LIMIT_WINDOW) . '
			ORDER BY consent_log_id DESC';
		$result = $this->db->sql_query_limit($sql, self::RATE_LIMIT_MAX);
		$count = 0;
		$latest = null;

		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($latest === null)
			{
				$latest = $row;
			}
			$count++;
		}
		$this->db->sql_freeresult($result);

		if ($latest !== null
			&& (int) $latest['consent_time'] >= (int) $now - self::DUPLICATE_WINDOW
			&& (int) $latest['consent_version'] === (int) $version
			&& $latest['accepted_categories'] === $accepted_categories)
		{
			return true;
		}

		return $count >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Build an anonymized identifier for the current user or session.
	 *
	 * @return string
	 */
	protected function get_anonymized_subject()
	{
		$subject = (int) $this->user->data['user_id'] !== ANONYMOUS ? 'u:' . (int) $this->user->data['user_id'] : 's:' . $this->user->session_id;

		return hash_hmac('sha256', $subject, $this->config['rand_seed']);
	}
}
