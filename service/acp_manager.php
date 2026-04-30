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
use phpbb\log\log as phpbb_log;
use phpbb\user;

class acp_manager
{
	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var phpbb_log */
	protected $log;

	/** @var user */
	protected $user;

	/** @var string */
	protected $consent_logs_table;

	/**
	 * Constructor.
	 *
	 * @param config           $config Config service
	 * @param driver_interface $db Database connection
	 * @param phpbb_log        $log phpBB log service
	 * @param user             $user Current user
	 * @param string           $consent_logs_table Consent log table name
	 */
	public function __construct(config $config, driver_interface $db, phpbb_log $log, user $user, $consent_logs_table)
	{
		$this->config = $config;
		$this->db = $db;
		$this->log = $log;
		$this->user = $user;
		$this->consent_logs_table = $consent_logs_table;
	}

	/**
	 * Parse a YYYY-MM-DD date string into a UTC timestamp.
	 *
	 * @param string $date_str   Input date string
	 * @param bool   $end_of_day When true, uses 23:59:59 instead of 00:00:00
	 *
	 * @return int|false Timestamp on success, false if the string is empty or invalid
	 */
	public function parse_date_filter($date_str, $end_of_day = false)
	{
		if ($date_str === '')
		{
			return false;
		}

		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date_str, new \DateTimeZone('UTC'));

		if ($dt === false || $dt->format('Y-m-d') !== $date_str)
		{
			return false;
		}

		return $end_of_day
			? (int) $dt->setTime(23, 59, 59)->getTimestamp()
			: (int) $dt->getTimestamp();
	}

	/**
	 * Write filtered consent log rows as CSV to the given file handle.
	 *
	 * Uses keyset pagination on consent_log_id to iterate rows in batches,
	 * avoiding memory exhaustion on large datasets.
	 *
	 * @param resource $handle     Writable stream (e.g. opened on php://output)
	 * @param array    $filters    Optional: date_from, date_to, user_id, consent_version
	 * @param int      $batch_size Rows per DB query
	 *
	 * @return void
	 */
	public function stream_logs_csv($handle, array $filters = [], $batch_size = 500)
	{
		$last_id = 0;

		do
		{
			$sql = 'SELECT consent_log_id, anonymized_id, consent_time, consent_version, accepted_categories'
				. ' FROM ' . $this->consent_logs_table
				. $this->build_filter_where($filters, $last_id)
				. ' ORDER BY consent_log_id ASC';

			$result = $this->db->sql_query_limit($sql, $batch_size);
			$count  = 0;

			while ($row = $this->db->sql_fetchrow($result))
			{
				$count++;
				$last_id    = (int) $row['consent_log_id'];
				$categories = json_decode($row['accepted_categories'], true);
				$cat_string = is_array($categories) ? implode(',', $categories) : '';

				fputcsv($handle, [
					$row['anonymized_id'],
					gmdate('Y-m-d\TH:i:s\Z', (int) $row['consent_time']),
					(int) $row['consent_version'],
					$this->sanitize_csv_value($cat_string),
				]);
			}

			$this->db->sql_freeresult($result);
		}
		while ($count === $batch_size);
	}

	/**
	 * Compute the anonymized identifier for a given registered user ID.
	 *
	 * Mirrors the HMAC used in log_manager::log_consent() so that admins can
	 * filter exports by user ID without exposing raw identifiers.
	 *
	 * Note: only matches rows hashed with the current config[rand_seed]. Records
	 * logged before a rand_seed rotation will not be found.
	 *
	 * @param int $user_id Numeric phpBB user ID (must be > 0)
	 *
	 * @return string 64-character hex hash
	 */
	public function hash_user_id($user_id)
	{
		return hash_hmac('sha256', 'u:' . (int) $user_id, $this->config['rand_seed']);
	}

	/**
	 * Add an admin log entry for consent settings changes.
	 *
	 * @return void
	 */
	public function log_admin_settings_updated()
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_UPDATED');
	}

	/**
	 * Add an admin log entry when users are re-prompted for consent.
	 *
	 * @return void
	 */
	public function log_admin_reprompt()
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_REPROMPT');
	}

	/**
	 * Add an admin log entry when consent logs are exported.
	 *
	 * @return void
	 */
	public function log_admin_export()
	{
		$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONSENTMANAGER_EXPORT');
	}

	/**
	 * Build a WHERE clause for consent log queries.
	 *
	 * The keyset condition (consent_log_id > last_id) is always included so
	 * that the caller can page through results without OFFSET.
	 *
	 * @param array $filters  Filter map from parse_export_filters
	 * @param int   $last_id  Highest consent_log_id seen in the previous batch
	 *
	 * @return string SQL WHERE clause (including the leading " WHERE " keyword)
	 */
	protected function build_filter_where(array $filters, $last_id = 0)
	{
		$where = ['consent_log_id > ' . (int) $last_id];

		if (!empty($filters['date_from']))
		{
			$where[] = 'consent_time >= ' . (int) $filters['date_from'];
		}

		if (!empty($filters['date_to']))
		{
			$where[] = 'consent_time <= ' . (int) $filters['date_to'];
		}

		if (!empty($filters['user_id']))
		{
			$anonymized = $this->hash_user_id((int) $filters['user_id']);
			$where[]    = "anonymized_id = '" . $this->db->sql_escape($anonymized) . "'";
		}

		if (!empty($filters['consent_version']))
		{
			$where[] = 'consent_version = ' . (int) $filters['consent_version'];
		}

		return ' WHERE ' . implode(' AND ', $where);
	}

	protected function sanitize_csv_value($value)
	{
		// Prevent spreadsheet formula injection (CSV injection).
		// Excel/LibreOffice treat cells starting with =, +, -, @, or \t as formulas.
		if ($value !== '' && strpos('=+-@' . "\t", $value[0]) !== false)
		{
			return "\t" . $value;
		}

		return $value;
	}
}
