<?php
/**
 * View to edit a list.
 *
 * @package     Joomla
 * @subpackage  Fabrik
 * @copyright   Copyright (C) 2005-2018  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

namespace Fabrik\Component\Fabrik\Administrator\View\ListView;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Fabrik\Component\Fabrik\Administrator\Model\FabModel;
use Fabrik\Component\Fabrik\Site\Model\ListModel;
use Joomla\Utilities\ArrayHelper;
use Joomla\CMS\MVC\View\FormView as BaseHtmlView;

/**
 * View to edit a list.
 *
 * @package     Joomla.Administrator
 * @subpackage  Fabrik
 * @since       1.5
 */

class RawView extends BaseHtmlView
{
	/**
	 * Display a json object representing the table data.
	 *
	 * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */

	public function display($tpl = null)
	{
		$app = Factory::getApplication();
		$input = $app->input;
		$model = FabModel::getInstance(ListModel::class);
		$model->setId($input->getInt('listid'));
		$this->setModel($model, true);
		$item = $model->getTable();
		$params = $model->getParams();
		$model->render();
		$this->emptyDataMessage = Text::_($params->get('empty_data_msg', 'COM_FABRIK_LIST_NO_DATA_MSG'));
		$rowid = $input->getString('rowid', '', 'string');
		list($this->headings, $groupHeadings, $this->headingClass, $this->cellClass) = $this->get('Headings');
		$data = $model->getData();
		$nav = $model->getPagination();
		$c = 0;

		foreach ($data as $groupk => $group)
		{
			foreach ($group as $i => $x)
			{
				$o = new \stdClass;

				if (is_object($data[$groupk]))
				{
					$o->data = ArrayHelper::fromObject($data[$groupk]);
				}
				else
				{
					$o->data = $data[$groupk][$i];
				}

				$o->cursor = $i + $nav->limitstart;
				$o->total = $nav->total;
				$o->id = 'list_' . $item->id . '_row_' . @$o->data->__pk_val;
				$o->class = "fabrik_row oddRow" . $c;

				if (is_object($data[$groupk]))
				{
					$data[$groupk] = $o;
				}
				else
				{
					$data[$groupk][$i] = $o;
				}

				$c = 1 - $c;
			}
		}

		// $$$ hugh - heading[3] doesn't exist any more?  Trying [0] instead.
		$d = array('id' => $item->id, 'rowid' => $rowid, 'model' => 'list', 'data' => $data,
				'headings' => $this->headings,
				'formid' => $model->getTable()->form_id,
				'lastInsertedRow' => $app->getSession()->get('lastInsertedRow', 'test'));
		$d['nav'] = get_object_vars($nav);
		$d['htmlnav'] = $params->get('show-table-nav', 1) ? $nav->getListFooter($model->getId(), $this->getTmpl()) : '';
		$d['calculations'] = $model->getCalculations();
		$msg = $app->getMessageQueue();

		if (!empty($msg))
		{
			$d['msg'] = $msg[0]['message'];
		}

		echo json_encode($d);
	}

	/**
	 * Get the view template name
	 *
	 * @return string template name
	 *
	 * @since 4.0
	 */
	private function getTmpl()
	{
		$app = Factory::getApplication();
		$input = $app->input;
		$input->set('hidemainmenu', true);
		$model = $this->getModel();
		$item = $model->getTable();
		$params = $model->getParams();

		if ($app->isAdmin())
		{
			$tmpl = $params->get('admin_template');

			if ($tmpl == -1 || $tmpl == '')
			{
				$tmpl = $input->get('layout', $item->template);
			}
		}
		else
		{
			$tmpl = $input->get('layout', $item->template);
		}

		return $tmpl;
	}
}
