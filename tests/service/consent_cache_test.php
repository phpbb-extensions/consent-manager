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

class consent_cache_test extends \phpbb_test_case
{
	public function test_invalidate_clears_cached_entries()
	{
		$cache_store = [];
		$consent_cache = $this->get_consent_cache($cache_store);

		$consent_cache->put_integrations('fingerprint', [['id' => 'board.analytics']]);

		self::assertSame([['id' => 'board.analytics']], $consent_cache->get_integrations('fingerprint'));

		$consent_cache->invalidate();

		self::assertNull($consent_cache->get_integrations('fingerprint'));
	}

	protected function get_consent_cache(array &$cache_store = [])
	{
		return new \phpbb\consentmanager\service\consent_cache($this->get_cache_service($cache_store));
	}

	protected function get_cache_service(array &$cache_store = [])
	{
		$driver = $this->createMock('\phpbb\cache\driver\driver_interface');
		$driver->method('get')
			->willReturnCallback(function ($key) use (&$cache_store) {
				return array_key_exists($key, $cache_store) ? $cache_store[$key] : false;
			});
		$driver->method('put')
			->willReturnCallback(function ($key, $value) use (&$cache_store) {
				$cache_store[$key] = $value;
				return true;
			});
		$driver->method('destroy')
			->willReturnCallback(function ($key) use (&$cache_store) {
				unset($cache_store[$key]);
				return true;
			});

		return new \phpbb\cache\service(
			$driver,
			new \phpbb\config\config([]),
			$this->createMock('\phpbb\db\driver\driver_interface'),
			new \phpbb_mock_event_dispatcher(),
			'',
			'php'
		);
	}
}
