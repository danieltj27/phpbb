<?php

/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\auth\provider;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\language\language;
use phpbb\user;

/**
 * Database authentication provider for phpBB
 * This is for authentication via the integrated user table
 */
class ldap extends base
{
	/** @var config phpBB config */
	protected $config;

	/** @var driver_interface DBAL driver interface */
	protected $db;

	/** @var language phpBB language class */
	protected $language;

	/** @var user phpBB user */
	protected $user;

	/**
	 * LDAP Authentication Constructor
	 *
	 * @param	config				$config		Config object
	 * @param	driver_interface	$db			DBAL driver interface
	 * @param	language			$language	Language object
	 * @param	user				$user		User object
	 */
	public function __construct(config $config, driver_interface $db, language $language, user $user)
	{
		$this->config = $config;
		$this->db = $db;
		$this->language = $language;
		$this->user = $user;
	}

	/**
	 * {@inheritdoc}
	 */
	public function init()
	{
		if (!@extension_loaded('ldap'))
		{
			return $this->language->lang('LDAP_NO_LDAP_EXTENSION');
		}

		$this->config['ldap_port'] = (int) $this->config['ldap_port'];
		if ($this->config['ldap_port'])
		{
			$ldap = @ldap_connect($this->config['ldap_server'], $this->config['ldap_port']);
		}
		else
		{
			$ldap = @ldap_connect($this->config['ldap_server']);
		}

		if (!$ldap)
		{
			return $this->language->lang('LDAP_NO_SERVER_CONNECTION');
		}

		@ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
		@ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

		if ($this->config['ldap_user'] || $this->config['ldap_password'])
		{
			if (!@ldap_bind($ldap, html_entity_decode($this->config['ldap_user'], ENT_COMPAT), html_entity_decode($this->config['ldap_password'], ENT_COMPAT)))
			{
				return $this->language->lang('LDAP_INCORRECT_USER_PASSWORD');
			}
		}

		// ldap_connect only checks whether the specified server is valid, so the connection might still fail
		$search = @ldap_search(
			$ldap,
			html_entity_decode($this->config['ldap_base_dn'], ENT_COMPAT),
			$this->ldap_user_filter($this->user->data['username']),
			(empty($this->config['ldap_email'])) ?
				array(html_entity_decode($this->config['ldap_uid'], ENT_COMPAT)) :
				array(html_entity_decode($this->config['ldap_uid'], ENT_COMPAT), html_entity_decode($this->config['ldap_email'], ENT_COMPAT)),
			0,
			1
		);

		if ($search === false)
		{
			return $this->language->lang('LDAP_SEARCH_FAILED');
		}

		$result = @ldap_get_entries($ldap, $search);

		@ldap_close($ldap);

		if (!is_array($result) || count($result) < 2)
		{
			return $this->language->lang('LDAP_NO_IDENTITY', $this->user->data['username']);
		}

		if (!empty($this->config['ldap_email']) && !isset($result[0][html_entity_decode($this->config['ldap_email'])]))
		{
			return $this->language->lang('LDAP_NO_EMAIL');
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function login($username, $password)
	{
		// do not allow empty password
		if (!$password)
		{
			return array(
				'status'	=> LOGIN_ERROR_PASSWORD,
				'error_msg'	=> 'NO_PASSWORD_SUPPLIED',
				'user_row'	=> array('user_id' => ANONYMOUS),
			);
		}

		if (!$username)
		{
			return array(
				'status'	=> LOGIN_ERROR_USERNAME,
				'error_msg'	=> 'LOGIN_ERROR_USERNAME',
				'user_row'	=> array('user_id' => ANONYMOUS),
			);
		}

		if (!@extension_loaded('ldap'))
		{
			return array(
				'status'		=> LOGIN_ERROR_EXTERNAL_AUTH,
				'error_msg'		=> 'LDAP_NO_LDAP_EXTENSION',
				'user_row'		=> array('user_id' => ANONYMOUS),
			);
		}

		$this->config['ldap_port'] = (int) $this->config['ldap_port'];
		if ($this->config['ldap_port'])
		{
			$ldap = @ldap_connect($this->config['ldap_server'], $this->config['ldap_port']);
		}
		else
		{
			$ldap = @ldap_connect($this->config['ldap_server']);
		}

		if (!$ldap)
		{
			return array(
				'status'		=> LOGIN_ERROR_EXTERNAL_AUTH,
				'error_msg'		=> 'LDAP_NO_SERVER_CONNECTION',
				'user_row'		=> array('user_id' => ANONYMOUS),
			);
		}

		@ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
		@ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

		if ($this->config['ldap_user'] || $this->config['ldap_password'])
		{
			if (!@ldap_bind($ldap, html_entity_decode($this->config['ldap_user'], ENT_COMPAT), html_entity_decode($this->config['ldap_password'], ENT_COMPAT)))
			{
				return array(
					'status'		=> LOGIN_ERROR_EXTERNAL_AUTH,
					'error_msg'		=> 'LDAP_NO_SERVER_CONNECTION',
					'user_row'		=> array('user_id' => ANONYMOUS),
				);
			}
		}

		$search = @ldap_search(
			$ldap,
			html_entity_decode($this->config['ldap_base_dn'], ENT_COMPAT),
			$this->ldap_user_filter($username),
			(empty($this->config['ldap_email'])) ?
				array(html_entity_decode($this->config['ldap_uid'], ENT_COMPAT)) :
				array(html_entity_decode($this->config['ldap_uid'], ENT_COMPAT), html_entity_decode($this->config['ldap_email'], ENT_COMPAT)),
			0,
			1
		);

		$ldap_result = $search !== false ? @ldap_get_entries($ldap, $search) : [];

		if (is_array($ldap_result) && count($ldap_result) > 1)
		{
			if (@ldap_bind($ldap, $ldap_result[0]['dn'], html_entity_decode($password, ENT_COMPAT)))
			{
				@ldap_close($ldap);

				$sql ='SELECT user_id, username, user_password, user_passchg, user_email, user_type
					FROM ' . USERS_TABLE . "
					WHERE username_clean = '" . $this->db->sql_escape(utf8_clean_string($username)) . "'";
				$result = $this->db->sql_query($sql);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				if ($row)
				{
					unset($ldap_result);

					// User inactive...
					if ($row['user_type'] == USER_INACTIVE || $row['user_type'] == USER_IGNORE)
					{
						return array(
							'status'		=> LOGIN_ERROR_ACTIVE,
							'error_msg'		=> 'ACTIVE_ERROR',
							'user_row'		=> $row,
						);
					}

					// Successful login... set user_login_attempts to zero...
					return array(
						'status'		=> LOGIN_SUCCESS,
						'error_msg'		=> false,
						'user_row'		=> $row,
					);
				}
				else
				{
					// retrieve default group id
					$sql = 'SELECT group_id
						FROM ' . GROUPS_TABLE . "
						WHERE group_name = '" . $this->db->sql_escape('REGISTERED') . "'
							AND group_type = " . GROUP_SPECIAL;
					$result = $this->db->sql_query($sql);
					$row = $this->db->sql_fetchrow($result);
					$this->db->sql_freeresult($result);

					if (!$row)
					{
						trigger_error('NO_GROUP');
					}

					// generate user account data
					$ldap_user_row = array(
						'username'		=> $username,
						'user_password'	=> '',
						'user_email'	=> (!empty($this->config['ldap_email'])) ? utf8_htmlspecialchars($ldap_result[0][html_entity_decode($this->config['ldap_email'], ENT_COMPAT)][0]) : '',
						'group_id'		=> (int) $row['group_id'],
						'user_type'		=> USER_NORMAL,
						'user_ip'		=> $this->user->ip,
						'user_new'		=> ($this->config['new_member_post_limit']) ? 1 : 0,
					);

					unset($ldap_result);

					// this is the user's first login so create an empty profile
					return array(
						'status'		=> LOGIN_SUCCESS_CREATE_PROFILE,
						'error_msg'		=> false,
						'user_row'		=> $ldap_user_row,
					);
				}
			}
			else
			{
				unset($ldap_result);
				@ldap_close($ldap);

				// Give status about wrong password...
				return array(
					'status'		=> LOGIN_ERROR_PASSWORD,
					'error_msg'		=> 'LOGIN_ERROR_PASSWORD',
					'user_row'		=> array('user_id' => ANONYMOUS),
				);
			}
		}

		@ldap_close($ldap);

		return array(
			'status'	=> LOGIN_ERROR_USERNAME,
			'error_msg'	=> 'LOGIN_ERROR_USERNAME',
			'user_row'	=> array('user_id' => ANONYMOUS),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function acp()
	{
		// These are fields required in the config table
		return array(
			'ldap_server', 'ldap_port', 'ldap_base_dn', 'ldap_uid', 'ldap_user_filter', 'ldap_email', 'ldap_user', 'ldap_password',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_acp_template($new_config)
	{
		return array(
			'TEMPLATE_FILE'	=> 'auth_provider_ldap.html',
			'TEMPLATE_VARS'	=> array(
				'AUTH_LDAP_BASE_DN'		=> $new_config['ldap_base_dn'],
				'AUTH_LDAP_EMAIL'		=> $new_config['ldap_email'],
				'AUTH_LDAP_PASSORD'		=> $new_config['ldap_password'] !== '' ? '********' : '',
				'AUTH_LDAP_PORT'		=> $new_config['ldap_port'],
				'AUTH_LDAP_SERVER'		=> $new_config['ldap_server'],
				'AUTH_LDAP_UID'			=> $new_config['ldap_uid'],
				'AUTH_LDAP_USER'		=> $new_config['ldap_user'],
				'AUTH_LDAP_USER_FILTER'	=> $new_config['ldap_user_filter'],
			),
		);
	}

	/**
	 * Generates a filter string for ldap_search to find a user
	 *
	 * @param	$username	string	Username identifying the searched user
	 *
	 * @return				string	A filter string for ldap_search
	 */
	private function ldap_user_filter($username)
	{
		$filter = '(' . $this->config['ldap_uid'] . '=' . $this->ldap_escape(html_entity_decode($username, ENT_COMPAT)) . ')';
		if ($this->config['ldap_user_filter'])
		{
			$_filter = ($this->config['ldap_user_filter'][0] == '(' && substr($this->config['ldap_user_filter'], -1) == ')') ? $this->config['ldap_user_filter'] : "({$this->config['ldap_user_filter']})";
			$filter = "(&{$filter}{$_filter})";
		}
		return $filter;
	}

	/**
	 * Escapes an LDAP AttributeValue
	 *
	 * @param	string	$string	The string to be escaped
	 * @return	string	The escaped string
	 */
	private function ldap_escape($string)
	{
		return str_replace(array('*', '\\', '(', ')'), array('\\*', '\\\\', '\\(', '\\)'), $string);
	}
}
