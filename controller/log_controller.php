<?php
/**
 *
 * Consent Manager extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\consentmanager\controller;

use phpbb\consentmanager\service\consent_manager;
use phpbb\consentmanager\service\log_manager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class log_controller
{
	/** @var log_manager */
	protected $log_manager;

	/** @var consent_manager */
	protected $consent_manager;

	public function __construct(log_manager $log_manager, consent_manager $consent_manager)
	{
		$this->log_manager = $log_manager;
		$this->consent_manager = $consent_manager;
	}

	public function log(Request $request)
	{
		$payload = json_decode($request->getContent(), true);

		if (!is_array($payload))
		{
			return new JsonResponse(array(
				'success' => false,
				'error' => 'invalid_payload',
			), Response::HTTP_BAD_REQUEST);
		}

		$hash = isset($payload['hash']) ? (string) $payload['hash'] : '';
		if (!check_link_hash($hash, 'phpbb.consentmanager.log'))
		{
			return new JsonResponse(array(
				'success' => false,
				'error' => 'invalid_hash',
			), Response::HTTP_FORBIDDEN);
		}

		$version = isset($payload['version']) ? (int) $payload['version'] : 0;
		$categories = isset($payload['categories']) && is_array($payload['categories']) ? $payload['categories'] : array();
		$categories = $this->consent_manager->normalize_categories($categories);

		if ($version !== $this->consent_manager->get_version())
		{
			return new JsonResponse(array(
				'success' => false,
				'error' => 'version_mismatch',
			), Response::HTTP_CONFLICT);
		}

		$this->log_manager->log_consent($categories, $version);

		return new JsonResponse(array(
			'success' => true,
			'categories' => $categories,
			'version' => $version,
		));
	}
}
