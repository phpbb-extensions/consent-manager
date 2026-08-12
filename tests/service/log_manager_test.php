<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\service;

class log_manager_test extends \phpbb_database_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	public static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	protected function setUp(): void
	{
		parent::setUp();

		global $db, $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);

		$db = $this->db = $this->new_dbal();
		$this->db->sql_query('DELETE FROM phpbb_consentmanager_logs');
	}

	public function getDataSet()
	{
		return new \PHPUnit\DbUnit\DataSet\DefaultDataSet();
	}

	public function test_log_consent_persists_authenticated_subject()
	{
		$manager = $this->create_manager(42, 'ignored-session');
		$manager->log_consent(array('necessary', 'analytics'), 3);

		$this->assertSqlResultEquals(array(
			array(
				'anonymized_id' => hash_hmac('sha256', 'u:42', 'random-seed'),
				'consent_version' => '3',
				'accepted_categories' => '["necessary","analytics"]',
			),
		), 'SELECT anonymized_id, consent_version, accepted_categories
			FROM phpbb_consentmanager_logs');
	}

	public function test_log_consent_uses_session_identifier_for_guests()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');
		$manager->log_consent(array('necessary'), 9);

		$this->assertSqlResultEquals(array(
			array(
				'anonymized_id' => hash_hmac('sha256', 's:guest-session', 'random-seed'),
				'consent_version' => '9',
				'accepted_categories' => '["necessary"]',
			),
		), 'SELECT anonymized_id, consent_version, accepted_categories
			FROM phpbb_consentmanager_logs');
	}

	public function test_log_consent_suppresses_recent_duplicate()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');

		self::assertTrue($manager->log_consent(array('necessary'), 1));
		self::assertFalse($manager->log_consent(array('necessary'), 1));
		$this->assertLogCount(1);
	}

	public function test_log_consent_preserves_changed_decision()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');

		self::assertTrue($manager->log_consent(array('necessary'), 1));
		self::assertTrue($manager->log_consent(array('necessary', 'analytics'), 1));
		$this->assertLogCount(2);
	}

	public function test_log_consent_allows_duplicate_after_deduplication_window()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');
		$manager->log_consent(array('necessary'), 1);
		$this->db->sql_query('UPDATE phpbb_consentmanager_logs
			SET consent_time = ' . (time() - \phpbb\consentmanager\service\log_manager::DUPLICATE_WINDOW - 1));

		self::assertTrue($manager->log_consent(array('necessary'), 1));
		$this->assertLogCount(2);
	}

	public function test_log_consent_limits_submissions_per_subject()
	{
		$manager = $this->create_manager(ANONYMOUS, 'guest-session');

		for ($version = 1; $version <= \phpbb\consentmanager\service\log_manager::RATE_LIMIT_MAX; $version++)
		{
			self::assertTrue($manager->log_consent(array('necessary'), $version));
		}

		self::assertFalse($manager->log_consent(array('necessary', 'analytics'), 999));
		$this->assertLogCount(\phpbb\consentmanager\service\log_manager::RATE_LIMIT_MAX);
	}

	protected function assertLogCount($expected)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS log_count
			FROM phpbb_consentmanager_logs');
		$count = (int) $this->db->sql_fetchfield('log_count');
		$this->db->sql_freeresult($result);

		self::assertSame($expected, $count);
	}

	protected function create_manager($user_id, $session_id)
	{
		$config = new \phpbb\config\config(array(
			'rand_seed' => 'random-seed',
		));

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\log_manager(
			$config,
			$this->db,
			$user,
			'phpbb_consentmanager_logs'
		);
	}
}
