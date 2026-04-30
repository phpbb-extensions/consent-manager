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

class acp_manager_test extends \phpbb_database_test_case
{
	/** @var \phpbb\language\language */
	protected $language;

	public static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}

	protected function setUp(): void
	{
		parent::setUp();

		global $phpbb_root_path, $phpEx;

		$lang_loader = new \phpbb\language\language_file_loader($phpbb_root_path, $phpEx);
		$this->language = new \phpbb\language\language($lang_loader);

		$db = $this->new_dbal();
		$db->sql_query('DELETE FROM phpbb_consentmanager_logs');
		$db->sql_close();
	}

	public function getDataSet()
	{
		return new \PHPUnit\DbUnit\DataSet\DefaultDataSet();
	}

	public function test_log_admin_settings_updated_delegates_to_phpbb_log()
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', 'LOG_CONSENTMANAGER_UPDATED');

		$manager = $this->create_manager(7, 'admin-session', $log);
		$manager->log_admin_settings_updated();
	}

	public function test_log_admin_reprompt_delegates_to_phpbb_log()
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', 'LOG_CONSENTMANAGER_REPROMPT');

		$manager = $this->create_manager(7, 'admin-session', $log);
		$manager->log_admin_reprompt();
	}

	public function test_log_admin_export_delegates_to_phpbb_log()
	{
		$log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->setMethods(array('add'))
			->getMock();
		$log->expects(self::once())
			->method('add')
			->with('admin', 7, '127.0.0.1', 'LOG_CONSENTMANAGER_EXPORT');

		$manager = $this->create_manager(7, 'admin-session', $log);
		$manager->log_admin_export();
	}

	public function test_hash_user_id_returns_hmac_of_user_prefix()
	{
		$manager = $this->create_manager(1, 'session');
		$expected = hash_hmac('sha256', 'u:42', 'random-seed');

		self::assertSame($expected, $manager->hash_user_id(42));
	}

	public function test_hash_user_id_is_consistent()
	{
		$manager = $this->create_manager(1, 'session');

		self::assertSame($manager->hash_user_id(99), $manager->hash_user_id(99));
		self::assertNotSame($manager->hash_user_id(1), $manager->hash_user_id(2));
	}

	public function test_stream_logs_csv_empty_table_writes_no_rows()
	{
		$manager = $this->create_manager(1, 'session');

		$handle = fopen('php://memory', 'w+');
		$manager->stream_logs_csv($handle, []);
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		self::assertSame('', $content);
	}

	public function test_stream_logs_csv_writes_all_rows_unfiltered()
	{
		$log_manager_a = $this->create_log_manager(10, 'session-a');
		$log_manager_a->log_consent(array('necessary', 'analytics'), 2);

		$log_manager_b = $this->create_log_manager(20, 'session-b');
		$log_manager_b->log_consent(array('necessary'), 2);

		$handle = fopen('php://memory', 'w+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, []);
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(2, $rows);
	}

	public function test_stream_logs_csv_filters_by_consent_version()
	{
		$log_manager = $this->create_log_manager(10, 'session');
		$log_manager->log_consent(array('necessary'), 1);
		$log_manager->log_consent(array('necessary', 'analytics'), 2);
		$log_manager->log_consent(array('necessary'), 1);

		$handle = fopen('php://memory', 'w+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, array('consent_version' => 1));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(2, $rows);
		foreach ($rows as $row)
		{
			self::assertStringContainsString(',1,', $row);
		}
	}

	public function test_stream_logs_csv_filters_by_date_range()
	{
		$db   = $this->new_dbal();
		$now  = time();
		$past = $now - 7200; // 2 hours ago

		$db->sql_query('INSERT INTO phpbb_consentmanager_logs
			(anonymized_id, consent_version, accepted_categories, consent_time)
			VALUES
			(\'' . $db->sql_escape('hash-old') . '\', 1, \'["necessary"]\', ' . $past . '),
			(\'' . $db->sql_escape('hash-new') . '\', 1, \'["necessary","analytics"]\', ' . $now . ')');
		$db->sql_close();

		$handle = fopen('php://memory', 'w+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, array(
			'date_from' => $now - 3600, // 1 hour ago
			'date_to'   => $now + 3600,
		));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(1, $rows);
		self::assertStringContainsString('hash-new', reset($rows));
	}

	public function test_stream_logs_csv_filters_by_user_id()
	{
		$manager_target = $this->create_log_manager(42, 'any-session');
		$manager_target->log_consent(array('necessary'), 1);

		$manager_other = $this->create_log_manager(99, 'other-session');
		$manager_other->log_consent(array('necessary', 'analytics'), 1);

		$reader = $this->create_manager(1, 'session');

		$handle = fopen('php://memory', 'w+');
		$reader->stream_logs_csv($handle, array('user_id' => 42));
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(1, $rows);

		$expected_hash = hash_hmac('sha256', 'u:42', 'random-seed');
		self::assertStringContainsString($expected_hash, reset($rows));
	}

	public function test_stream_logs_csv_row_format_is_correct()
	{
		$log_manager = $this->create_log_manager(5, 'session');
		$log_manager->log_consent(array('necessary', 'analytics'), 3);

		$handle = fopen('php://memory', 'w+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, []);
		rewind($handle);
		$content = stream_get_contents($handle);
		fclose($handle);

		$row = str_getcsv(trim($content));
		self::assertCount(4, $row);

		// anonymized_id: 64-char hex
		self::assertRegExp('/^[0-9a-f]{64}$/', $row[0]);

		// timestamp: ISO 8601 UTC
		self::assertRegExp('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $row[1]);

		// consent_version
		self::assertSame('3', $row[2]);

		// categories as comma-separated string
		self::assertSame('necessary,analytics', $row[3]);
	}

	public function test_stream_logs_csv_batch_pagination_retrieves_all_rows()
	{
		$log_manager = $this->create_log_manager(1, 'session');

		for ($i = 0; $i < 5; $i++)
		{
			$log_manager->log_consent(array('necessary'), 1);
		}

		$handle = fopen('php://memory', 'w+');
		// Use a batch size of 2 to exercise the pagination loop
		$this->create_manager(1, 'session')->stream_logs_csv($handle, [], 2);
		rewind($handle);
		$rows = array_filter(explode("\n", stream_get_contents($handle)));
		fclose($handle);

		self::assertCount(5, $rows);
	}

	public function test_stream_logs_csv_sanitizes_formula_injection_in_categories()
	{
		$db = $this->new_dbal();
		// Insert a row whose accepted_categories begins with '=' — a formula injection attempt
		$db->sql_query('INSERT INTO phpbb_consentmanager_logs
			(anonymized_id, consent_version, accepted_categories, consent_time)
			VALUES (\'hash-x\', 1, \'["=DANGEROUS()"]\', ' . time() . ')');
		$db->sql_close();

		$handle = fopen('php://memory', 'w+');
		$this->create_manager(1, 'session')->stream_logs_csv($handle, []);
		rewind($handle);
		$row = str_getcsv(trim(stream_get_contents($handle)));
		fclose($handle);

		// categories cell must be prefixed with a tab to defuse the formula
		self::assertStringStartsWith("\t", $row[3]);
		self::assertStringContainsString('=DANGEROUS()', $row[3]);
	}

	public function test_parse_date_filter_returns_false_for_empty_string()
	{
		$manager = $this->create_manager(1, 'session');
		self::assertFalse($manager->parse_date_filter(''));
	}

	public function test_parse_date_filter_returns_false_for_invalid_date()
	{
		$manager = $this->create_manager(1, 'session');
		self::assertFalse($manager->parse_date_filter('not-a-date'));
		self::assertFalse($manager->parse_date_filter('2024-13-01'));
		self::assertFalse($manager->parse_date_filter('2024-02-31'));
	}

	public function test_parse_date_filter_returns_start_of_day_timestamp()
	{
		$manager = $this->create_manager(1, 'session');
		$ts = $manager->parse_date_filter('2024-06-15');
		self::assertSame(
			\DateTimeImmutable::createFromFormat('!Y-m-d', '2024-06-15', new \DateTimeZone('UTC'))->getTimestamp(),
			$ts
		);
	}

	public function test_parse_date_filter_returns_end_of_day_timestamp_when_flag_set()
	{
		$manager  = $this->create_manager(1, 'session');
		$start    = $manager->parse_date_filter('2024-06-15');
		$end      = $manager->parse_date_filter('2024-06-15', true);
		self::assertSame(86399, $end - $start); // 23h 59m 59s difference
	}

	protected function create_manager($user_id, $session_id, $log = null)
	{
		$config = new \phpbb\config\config(array(
			'rand_seed' => 'random-seed',
		));
		$db = $this->new_dbal();

		if ($log === null)
		{
			$log = $this->getMockBuilder('\phpbb\log\log')
				->disableOriginalConstructor()
				->getMock();
		}

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\acp_manager(
			$config,
			$db,
			$log,
			$user,
			'phpbb_consentmanager_logs'
		);
	}

	protected function create_log_manager($user_id, $session_id)
	{
		$config = new \phpbb\config\config(array(
			'rand_seed' => 'random-seed',
		));
		$db = $this->new_dbal();

		$user = new \phpbb\user($this->language, '\phpbb\datetime');
		$user->data = array(
			'user_id' => $user_id,
		);
		$user->session_id = $session_id;
		$user->ip = '127.0.0.1';

		return new \phpbb\consentmanager\service\log_manager(
			$config,
			$db,
			$user,
			'phpbb_consentmanager_logs'
		);
	}
}
