<?php
/**
 * Is Numeric Validation Rule
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.validationrule.isnumeric
 * @copyright   Copyright (C) 2005-2016  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Component\Fabrik\Site\Plugin\AbstractValidationRulePlugin;

/**
 * Is Numeric Validation Rule
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.validationrule.isnumeric
 * @since       3.0
 */
class PlgFabrik_ValidationruleIsNumeric extends AbstractValidationRulePlugin
{
	/**
	 * Plugin name
	 *
	 * @var string
	 *
	 * @since 4.0
	 */
	protected $pluginName = 'isnumeric';

	/**
	 * Validate the elements data against the rule
	 *
	 * @param   string $data          To check
	 * @param   int    $repeatCounter Repeat group counter
	 *
	 * @return  bool  true if validation passes, false if fails
	 *
	 * @since 4.0
	 */
	public function validate($data, $repeatCounter)
	{
		// Could be a drop-down with multi-values
		if (is_array($data))
		{
			$data = implode('', $data);
		}

		$params      = $this->getParams();
		$allow_empty = $params->get('isnumeric-allow_empty');

		if ($allow_empty == '1' and empty($data))
		{
			return true;
		}

		return is_numeric($this->elementPlugin->unNumberFormat($data));
	}

	/**
	 * Does the validation allow empty value?
	 * Default is false, can be overridden on per-validation basis (such as isnumeric)
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	protected function allowEmpty()
	{
		$params      = $this->getParams();
		$allow_empty = $params->get('isnumeric-allow_empty');

		return $allow_empty == '1';
	}
}
