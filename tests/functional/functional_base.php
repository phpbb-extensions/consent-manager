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
abstract class functional_base extends \phpbb_functional_test_case
{
	protected static function setup_extensions()
	{
		return array('phpbb/consentmanager');
	}
}
