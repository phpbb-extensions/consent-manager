<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\tests\functional;

/**
 * @group functional
 */
class acp_test extends functional_base
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->add_lang_ext('phpbb/consentmanager', 'acp_consentmanager');
	}

	public function test_acp_page_renders_consent_manager_settings()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', $this->get_module_url());

		$this->assertContainsLang('ACP_CONSENTMANAGER_CATEGORIES', $crawler->filter('#main')->text());
		$this->assertContainsLang('ACP_CONSENTMANAGER_INTEGRATIONS', $crawler->filter('#main')->text());
		$this->assertContainsLang('ACP_CONSENTMANAGER_VERSION', $crawler->filter('#main')->text());
	}

	public function test_acp_page_escapes_integration_label()
	{
		$label = '<script>alert(1)</script>';
		$integrations = json_encode(array(array(
			'id' => 'board.analytics',
			'category' => 'analytics',
			'label' => $label,
			'src' => '/analytics.js',
		)));

		$this->db->sql_query('UPDATE ' . CONFIG_TEXT_TABLE . "
			SET config_value = '" . $this->db->sql_escape($integrations) . "'
			WHERE config_name = 'consentmanager_integrations'");
		$this->purge_cache();

		$this->login();
		$this->admin_login();
		self::request('GET', $this->get_module_url());
		$content = self::get_content();

		$this->assertStringNotContainsString($label, $content);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
	}

	public function test_acp_form_saves_settings_and_integrations()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', $this->get_module_url());
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$form['consentmanager_analytics_enabled']->select('0');
		$form['consentmanager_marketing_enabled']->select('1');
		$form['consentmanager_media_enabled']->select('1');
		$form['consentmanager_integrations']->setValue('[{"id":"board.analytics","category":"analytics","label":"Board Analytics","src":"https://cdn.example.com/analytics.js"}]');

		$crawler = self::submit($form);

		$this->assertStringContainsString($this->lang('CONFIG_UPDATED'), $crawler->text());

		$sql = 'SELECT config_name, config_value
			FROM ' . CONFIG_TABLE . '
			WHERE config_name IN (\'consentmanager_analytics_enabled\', \'consentmanager_marketing_enabled\', \'consentmanager_media_enabled\')';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$config = array();
		foreach ($rows as $row)
		{
			$config[$row['config_name']] = $row['config_value'];
		}

		$this->assertSame('0', $config['consentmanager_analytics_enabled']);
		$this->assertSame('1', $config['consentmanager_marketing_enabled']);
		$this->assertSame('1', $config['consentmanager_media_enabled']);

		$sql = 'SELECT config_value
			FROM ' . CONFIG_TEXT_TABLE . '
			WHERE config_name = \'consentmanager_integrations\'';
		$result = $this->db->sql_query($sql);
		$stored_integrations = $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);

		$this->assertSame('[{"id":"board.analytics","category":"analytics","label":"Board Analytics","src":"https://cdn.example.com/analytics.js"}]', $stored_integrations);
	}

	public function test_acp_force_reprompt_increments_version()
	{
		$this->login();
		$this->admin_login();

		$before = $this->get_consent_version();
		$crawler = self::request('GET', $this->get_module_url());
		$form = $crawler->selectButton($this->lang('ACP_CONSENTMANAGER_FORCE_REPROMPT'))->form();
		$crawler = self::submit($form);

		$this->assertContainsLang('ACP_CONSENTMANAGER_REPROMPT_SUCCESS', $crawler->text());
		$this->assertSame($before + 1, $this->get_consent_version());
	}

	public function test_banner_translation_does_not_double_escape_on_resave()
	{
		$banner_title = 'Cookies & Privacy';

		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', $this->get_module_url('banner'));
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$form['translations[en][banner_title]']->setValue($banner_title);
		$crawler = self::submit($form);

		$this->assertStringContainsString($this->lang('ACP_CONSENTMANAGER_BANNER_UPDATED'), $crawler->text());
		$this->assertSame('Cookies &amp; Privacy', $this->get_stored_translation('banner_title', 'en'));

		$crawler = self::request('GET', $this->get_module_url('banner'));
		$form = $crawler->selectButton($this->lang('SUBMIT'))->form();
		$this->assertSame($banner_title, $form['translations[en][banner_title]']->getValue());
		self::submit($form);

		$this->assertSame('Cookies &amp; Privacy', $this->get_stored_translation('banner_title', 'en'));

		$crawler = self::request('GET', 'index.php');
		$this->assertSame($banner_title, $crawler->filter('#consent-manager-banner-title')->text());
	}

	protected function get_module_url($mode = 'settings')
	{
		return 'adm/index.php?i=%5Cphpbb%5Cconsentmanager%5Cacp%5Cconsentmanager_module&mode=' . $mode . '&sid=' . $this->sid;
	}

	protected function get_consent_version()
	{
		$sql = 'SELECT config_value
			FROM ' . CONFIG_TABLE . '
			WHERE config_name = \'consentmanager_consent_version\'';
		$result = $this->db->sql_query($sql);
		$value = (int) $this->db->sql_fetchfield('config_value');
		$this->db->sql_freeresult($result);

		return $value;
	}

	protected function get_stored_translation($translation_key, $lang_iso)
	{
		$sql = 'SELECT translation_text
			FROM phpbb_consentmanager_translations
			WHERE translation_key = \'' . $this->db->sql_escape($translation_key) . '\'
				AND lang_iso = \'' . $this->db->sql_escape($lang_iso) . "'";
		$result = $this->db->sql_query($sql);
		$value = $this->db->sql_fetchfield('translation_text');
		$this->db->sql_freeresult($result);

		return $value;
	}
}
