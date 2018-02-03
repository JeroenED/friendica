<?php
/**
 * @file src/Render/FriendicaSmarty.php
 */
namespace Friendica\Render;

use Smarty;

define('SMARTY3_TEMPLATE_FOLDER', 'templates');

/**
 * Description of FriendicaSmarty
 *
 * @author benlo
 */
class FriendicaSmarty extends Smarty
{
	public $filename;

	function __construct()
	{
		parent::__construct();

		$a = get_app();
		$theme = current_theme();

		// setTemplateDir can be set to an array, which Smarty will parse in order.
		// The order is thus very important here
		$template_dirs = ['theme' => "view/theme/$theme/" . SMARTY3_TEMPLATE_FOLDER . "/"];
		if (x($a->theme_info, "extends")) {
			$template_dirs = $template_dirs + ['extends' => "view/theme/" . $a->theme_info["extends"] . "/" . SMARTY3_TEMPLATE_FOLDER . "/"];
		}

		$template_dirs = $template_dirs + ['base' => "view/" . SMARTY3_TEMPLATE_FOLDER . "/"];
		$this->setTemplateDir($template_dirs);

		$this->setCompileDir('view/smarty3/compiled/');
		$this->setConfigDir('view/smarty3/config/');
		$this->setCacheDir('view/smarty3/cache/');

		$this->left_delimiter = $a->get_template_ldelim('smarty3');
		$this->right_delimiter = $a->get_template_rdelim('smarty3');

		// Don't report errors so verbosely
		$this->error_reporting = E_ALL & ~E_NOTICE;
	}

	function parsed($template = '')
	{
		if ($template) {
			return $this->fetch('string:' . $template);
		}
		return $this->fetch('file:' . $this->filename);
	}

}