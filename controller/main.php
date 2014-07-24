<?php
/**
*
* @package Board3 Portal v2.1
* @copyright (c) 2013 Board3 Group ( www.board3.de )
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace board3\portal\controller;

class main
{
	/**
	* Auth object
	* @var \phpbb\auth\auth
	*/
	protected $auth;

	/**
	* phpBB Config object
	* @var \phpbb\config\config
	*/
	protected $config;

	/**
	* Template object
	* @var \phpbb\template
	*/
	protected $template;

	/**
	* User object
	* @var \phpbb\user
	*/
	protected $user;

	/**
	* phpBB root path
	* @var string
	*/
	protected $phpbb_root_path;

	/**
	* PHP file extension
	* @var string
	*/
	protected $php_ext;

	/**
	* Portal root path
	* @var string
	*/
	protected $root_path;

	/**
	* Portal includes path
	* @var string
	*/
	protected $includes_path;

	/**
	* phpBB path helper
	* @var \phpbb\path_helper
	*/
	protected $path_helper;

	/**
	* Portal Helper object
	* @var \board3\portal\includes\helper
	*/
	protected $portal_helper;

	/**
	* Portal modules count
	* @var array
	*/
	protected $module_count;

	/**
	* Portal modules array
	* @var array
	*/
	protected $portal_modules;

	/**
	* Constructor
	* NOTE: The parameters of this method must match in order and type with
	* the dependencies defined in the services.yml file for this service.
	* @param \phpbb\auth\auth $auth Auth object
	* @param \phpbb\config\config $config phpBB Config object
	* @param \phpbb\template $template Template object
	* @param \phpbb\user $user User object
	* @param \phpbb\path_helper $path_helper phpBB path helper
	* @param \board3\portal\includes\helper $portal_helper Portal helper class
	* @param string $phpbb_root_path phpBB root path
	* @param string $php_ext PHP file extension
	* @param string $config_table Board3 config table
	* @param string $modules_table Board3 modules table
	*/
	public function __construct($auth, $config, $template, $user, $path_helper, $portal_helper, $phpbb_root_path, $php_ext, $config_table, $modules_table)
	{
		global $portal_root_path;

		$this->auth = $auth;
		$this->config = $config;
		$this->template = $template;
		$this->user = $user;
		$this->path_helper = $path_helper;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->portal_helper = $portal_helper;

		$this->includes_path = $phpbb_root_path . 'ext/board3/portal/includes/';
		$this->root_path = $phpbb_root_path . 'ext/board3/portal/';
		$portal_root_path = $this->root_path;
		define('PORTAL_MODULES_TABLE', $modules_table);
		define('PORTAL_CONFIG_TABLE', $config_table);

		if (!function_exists('obtain_portal_config'))
		{
			include($this->includes_path . 'constants' . $this->php_ext);
			include($this->includes_path . 'functions_modules' . $this->php_ext);
			include($this->includes_path . 'functions' . $this->php_ext);
		}
	}

	/**
	* Extension front handler method. This is called automatically when your extension is accessed
	* through index.php?ext=example/foobar
	* @return null
	*/
	public function handle()
	{
		$this->run_initial_tasks();

		// Set default data
		$this->portal_modules = obtain_portal_modules();
		$display_online = false;

		/**
		* set up column_count array
		* with this we can hide unneeded parts of the portal
		*/
		$this->module_count = array(
			'total' 	=> 0,
			'top'		=> 0,
			'left'		=> 0,
			'center'	=> 0,
			'right'		=> 0,
			'bottom'	=> 0,
		);

		/**
		* start assigning block vars
		*/
		foreach ($this->portal_modules as $row)
		{
			if (!($module = $this->get_portal_module($row)))
			{
				continue;
			}

			// Load module language file
			$this->load_module_language($module);

			if ($row['module_column'] == column_string_num('left') && $this->config['board3_left_column'])
			{
				$template_module = $module->get_template_side($row['module_id']);
				++$this->module_count['left'];
			}
			if ($row['module_column'] == column_string_num('center'))
			{
				$template_module = $module->get_template_center($row['module_id']);
				++$this->module_count['center'];
			}
			if ($row['module_column'] == column_string_num('right') && $this->config['board3_right_column'])
			{
				$template_module = $module->get_template_side($row['module_id']);
				++$this->module_count['right'];
			}
			if ($row['module_column'] == column_string_num('top'))
			{
				$template_module = $module->get_template_center($row['module_id']);
				++$this->module_count['top'];
			}
			if ($row['module_column'] == column_string_num('bottom'))
			{
				$template_module = $module->get_template_center($row['module_id']);
				++$this->module_count['bottom'];
			}
			if (!isset($template_module))
			{
				continue;
			}

			// Custom Blocks that have been defined in the ACP will return an array instead of just the name of the template file
			$this->assign_module_vars($row, $template_module);

			// Check if we need to show the online list
			$display_online = $this->check_online_list($row['module_classname'], $display_online);

			unset($template_module);
		}

		// Redirect to index if there are currently no active modules
		$this->check_redirect();

		// Assign specific vars
		$this->assign_template_vars();

		// And now to output the page.
		page_header($this->user->lang('PORTAL'), $display_online);

		// foobar_body.html is in ./ext/foobar/example/styles/prosilver/template/foobar_body.html
		$this->template->set_filenames(array(
			'body' => 'portal/portal_body.html'
		));

		page_footer();
	}

	/**
	* Check if user should be able to access this page. Redirect to index
	* if this does not apply.
	*
	* @return null
	*/
	protected function check_permission()
	{
		if (!isset($this->config['board3_enable']) || !$this->config['board3_enable'] || !$this->auth->acl_get('u_view_portal'))
		{
			redirect(append_sid($this->phpbb_root_path . 'index' . $this->php_ext));
		}
	}

	/**
	* Check if portal needs to redirect to index page
	*/
	protected function check_redirect()
	{
		$this->module_count['total'] = sizeof($this->portal_modules);

		if ($this->module_count['total'] < 1)
		{
			redirect(append_sid($this->phpbb_root_path . 'index' . $this->php_ext));
		}
	}

	/**
	* Return true if online list should be displayed
	*
	* @return mixed True if online list should be display, current value
	*		if unsure
	*/
	protected function check_online_list($module_classname, $display_online)
	{
		return ($module_classname === '\board3\portal\modules\whois_online') ? true : $display_online;
	}

	/**
	* Get portal module and run module start checks
	*
	* @param array $row Module row
	*
	* @return mixed False if one of the module checks failed, the module
	*		object if checks were successful
	*/
	protected function get_portal_module($row)
	{
		// Do not try to load non-existent or disabled modules
		if ($row['module_status'] == B3_MODULE_DISABLED || !is_object($module = $this->portal_helper->get_module($row['module_classname'])))
		{
			return false;
		}

		/**
		* Check for permissions before loading anything
		* the default group of a user always defines his/her permission
		*/
		return ($this->check_group_access($row)) ? $module : false;
	}

	/**
	* Check if user is in required groups
	*
	* @param array $row Module row
	*/
	protected function check_group_access($row)
	{
		$group_ary = (!empty($row['module_group_ids'])) ? explode(',', $row['module_group_ids']) : '';
		if ((is_array($group_ary) && !in_array($this->user->data['group_id'], $group_ary)))
		{
			return false;
		}
		else
		{
			return true;
		}
	}

	/**
	* Load language file of module
	*
	* @param object $module Module of which language file should be loaded
	*/
	protected function load_module_language($module)
	{
		if ($language_file = $module->get_language())
		{
			$this->user->add_lang_ext('board3/portal', 'modules/' . $language_file);
		}
	}

	/**
	* Assign template vars for portal
	*
	* @return null
	*/
	protected function assign_template_vars()
	{
		$this->template->assign_vars(array(
			'S_PORTAL_LEFT_COLUMN'		=> $this->config['board3_left_column_width'],
			'S_PORTAL_RIGHT_COLUMN'		=> $this->config['board3_right_column_width'],
			'S_LEFT_COLUMN'			=> $this->check_module_count('left', $this->config['board3_left_column']),
			'S_CENTER_COLUMN'		=> $this->check_module_count('center'),
			'S_RIGHT_COLUMN'		=> $this->check_module_count('right', $this->config['board3_right_column']),
			'S_TOP_COLUMN'			=> $this->check_module_count('top'),
			'S_BOTTOM_COLUMN'		=> $this->check_module_count('bottom'),
			'S_DISPLAY_PHPBB_MENU'		=> $this->config['board3_phpbb_menu'],
			'B3P_DISPLAY_JUMPBOX'		=> $this->config['board3_display_jumpbox'],
			'T_EXT_THEME_PATH'		=> $this->path_helper->get_web_root_path() . $this->root_path . 'styles/' . $this->user->style['style_path'] . '/theme/',
		));
	}

	/**
	* Assign module's template vars
	*
	* @param array $row Database row of module
	* @param mixed $template_module Template data as returned by module
	*
	* @return null
	*/
	protected function assign_module_vars($row, $template_module)
	{
		if (is_array($template_module))
		{
			$this->template->assign_block_vars('modules_' . column_num_string($row['module_column']), array(
				'TEMPLATE_FILE'			=> 'portal/modules/' . $template_module['template'],
				'IMAGE_SRC'			=> $this->path_helper->get_web_root_path() . $this->root_path . 'styles/' . $this->user->style['style_path'] . '/theme/images/portal/' . $template_module['image_src'],
				'TITLE'				=> $template_module['title'],
				'CODE'				=> $template_module['code'],
				'MODULE_ID'			=> $row['module_id'],
				'IMAGE_WIDTH'			=> $row['module_image_width'],
				'IMAGE_HEIGHT'			=> $row['module_image_height'],
			));
		}
		else
		{
			$this->template->assign_block_vars('modules_' . column_num_string($row['module_column']), array(
				'TEMPLATE_FILE'			=> 'portal/modules/' . $template_module,
				'IMAGE_SRC'			=> $this->path_helper->get_web_root_path() . $this->root_path . 'styles/' . $this->user->style['style_path'] . '/theme/images/portal/' . $row['module_image_src'],
				'IMAGE_WIDTH'			=> $row['module_image_width'],
				'IMAGE_HEIGHT'			=> $row['module_image_height'],
				'MODULE_ID'			=> $row['module_id'],
				'TITLE'				=> (isset($this->user->lang[$row['module_name']])) ? $this->user->lang[$row['module_name']] : utf8_normalize_nfc($row['module_name']),
			));
		}
	}

	/**
	* Check module count and related config setting
	*
	* @param string $column Column to check
	* @param bool $config Config value to check
	*
	* @return bool True if module count is bigger than 0 and $config is true
	*/
	protected function check_module_count($column, $config = true)
	{
		return $this->module_count[$column] > 0 && $config;
	}

	/**
	* Run initial tasks that are required for a properly setup extension
	*
	* @return null
	*/
	protected function run_initial_tasks()
	{
		// Check for permissions first
		$this->check_permission();

		// Load language file
		$this->user->add_lang_ext('board3/portal', 'portal');

		// Obtain portal config
		obtain_portal_config();
	}
}
