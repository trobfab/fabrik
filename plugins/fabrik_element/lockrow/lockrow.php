<?php
/**
* Plugin element to render internal id
* @package fabrikar
* @author Rob Clayburn
* @copyright (C) Rob Clayburn
* @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
*/

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

use Fabrik\Helpers\Html;
use Joomla\CMS\Factory;
use Fabrik\Component\Fabrik\Site\Plugin\AbstractElementPlugin;
use Fabrik\Helpers\StringHelper as FStringHelper;
use Fabrik\Helpers\Worker;

class PlgFabrik_ElementLockrow extends AbstractElementPlugin
{

	/**
	 * Db table field type
	 *
	 * @var string
	 *
	 * @since 4.0
	 */
	protected $fieldDesc = 'VARCHAR(32)';

	/**
	 * @param      $value
	 * @param null $this_user_id
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public function isLocked($value, $this_user_id = null)
	{
		if (!empty($value))
		{
			if (!isset($this_user_id))
			{
				$this_user    = Factory::getUser();
				$this_user_id = (int) $this_user->get('id');
			}
			else
			{
				$this_user_id = (int) $this_user_id;
			}

			list($time, $locking_user_id) = explode(';', $value);

			if ((int) $this_user_id === (int) $locking_user_id)
			{
				return false;
			}

			$params   = $this->getParams();
			$ttl      = (int) $params->get('lockrow_ttl', '24');
			$ttl_time = (int) $time + ($ttl * 60);
			$time_now = time();
			if ($time_now < $ttl_time)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param      $value
	 * @param null $this_user_id
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public function isLockOwner($value, $this_user_id = null)
	{
		if (!empty($value))
		{
			if (!isset($this_user_id))
			{
				$this_user    = Factory::getUser();
				$this_user_id = (int) $this_user->get('id');
			}
			else
			{
				$this_user_id = (int) $this_user_id;
			}

			list($time, $locking_user_id) = explode(';', $value);
			$this_user    = Factory::getUser();
			$this_user_id = $this_user->get('id');

			if ((int) $this_user_id === (int) $locking_user_id)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param      $value
	 * @param null $this_user_id
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public function showLocked($value, $this_user_id = null)
	{
		if (!empty($value))
		{
			if (!isset($this_user_id))
			{
				$this_user    = Factory::getUser();
				$this_user_id = (int) $this_user->get('id');
			}
			else
			{
				$this_user_id = (int) $this_user_id;
			}
			list($time, $locking_user_id) = explode(';', $value);
			/*
			$this_user = Factory::getUser();
			// $$$ decide what to do about guests
			$this_user_id = $this_user->get('id');
			if ((int)$this_user_id === (int)$locking_user_id)
			{
				return false;
			}
			*/
			$params   = $this->getParams();
			$ttl      = (int) $params->get('lockrow_ttl', '24');
			$ttl_time = (int) $time + ($ttl * 60);
			$time_now = time();
			if ($time_now < $ttl_time)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param      $value
	 * @param null $this_user_id
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	private function canUnlock($value, $this_user_id = null)
	{
		$can_unlock = false;
		if (!empty($value))
		{
			if (!isset($this_user_id))
			{
				$this_user    = Factory::getUser();
				$this_user_id = (int) $this_user->get('id');
			}
			else
			{
				$this_user_id = (int) $this_user_id;
			}
			list($time, $locking_user_id) = explode(';', $value);
			$locking_user_id = (int) $locking_user_id;
			if ($this_user_id === $locking_user_id)
			{
				$can_unlock = true;
			}
		}

		return $can_unlock;
	}

	/**
	 * @param      $value
	 * @param null $this_user_id
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	private function canLock($value, $this_user_id = null)
	{
		return false;
	}

	/**
	 * draws the form element
	 *
	 * @param int repeat group counter
	 *
	 * @return string returns element html
	 *
	 * @since 4.0
	 */
	function render($data, $repeatCounter = 0)
	{
		$app     = Factory::getApplication();
		$name    = $this->getHTMLName($repeatCounter);
		$id      = $this->getHTMLId($repeatCounter);
		$params  = $this->getParams();
		$element = $this->getElement();
		$value   = $this->getValue($data, $repeatCounter);

		$element->hidden = true;

		if (!$this->editable || !$this->canUse() || ($this->app->input->get('view', '', 'string') === 'details'))
		{
			return '';
		}

		$rowid = (int) $this->getFormModel()->getRowId();

		if (empty($rowid))
		{
			return "";
		}

		$ttl_unlock = false;
		if (!empty($value))
		{
			list($time, $locking_user_id) = explode(';', $value);
			$ttl      = (int) $params->get('lockrow_ttl', '24');
			$ttl_time = (int) $time + ($ttl * 60);

			$this_user = Factory::getUser();
			// $$$ decide what to do about guests
			$this_user_id = $this_user->get('id');
			if ((int) $this_user_id === (int) $locking_user_id)
			{
				$app->enqueueMessage('ROW RE-LOCKED!');
			}
			else
			{
				if (time() < $ttl_time)
				{
					$app->enqueueMessage('ROW IS LOCKED!');

					return "";
				}
				else
				{
					$app->enqueueMessage('ROW LOCK EXPIRED!');
					$ttl_unlock = true;
				}
			}
		}

		$db_table_name = $this->getTableName();
		$field_name    = FStringHelper::safeColName($this->getFullName(false, false));
		$listModel     = $this->getListModel();
		$pk            = $listModel->getTable()->db_primary_key;
		$db            = $listModel->getDb();
		$query         = $db->getQuery(true);
		$user          = Factory::getUser();
		$user_id       = $user->get('id');
		$lockstr       = time() . ";" . $user_id;
		//$query = "UPDATE $db_table_name SET $field_name = " . $db->quote($lockstr) . " WHERE $pk = " . $db->quote($rowid);
		$query->update($db->quoteName($db_table_name))
			->set($field_name . ' = ' . $db->quote($lockstr))
			->where($pk . ' = ' . $db->quote($rowid));

		$db->setQuery($query);
		$db->execute();

		// $$$ @TODO - may need to clean com_content cache as well
		$cache = Factory::getCache('com_fabrik');
		$cache->clean();

		return "";
	}

	/**
	 * shows the data formatted for the table view
	 *
	 * @param   string     $data
	 * @param   stdClass  &$thisRow All the data in the lists current row
	 * @param   array      $opts    Rendering options
	 *
	 * @since 4.0
	 */
	function renderListData($data, \stdClass $thisRow, $opts = array())
	{
		if (!isset($data))
		{
			$data = '';
		}

		$data = Worker::JSONtoData($data, true);

		for ($i = 0; $i < count($data); $i++)
		{
			$data[$i] = $this->_renderListData($data[$i], $thisRow, $opts);
		}

		$data = json_encode($data);

		return parent::renderListData($data, $thisRow, $opts);
	}

	/**
	 * @param $data
	 * @param $thisRow
	 * @param $opts
	 *
	 * @return string
	 *
	 * @since version
	 */
	function _renderListData($data, $thisRow, $opts)
	{
		$params   = $this->getParams();
		$showIcon = true;

		if ($params->get('lockrow_show_icon_read_only', '1') === '0')
		{
			$showIcon = $this->getListModel()->canEdit($data);

			// show icon if we are the lock owner
			if (!$showIcon)
			{
				$showIcon = $this->isLocked($data, false) && $this->isLockOwner($data);
			}
		}

		if ($showIcon)
		{
			$layout           = $this->getLayout('list');
			$layoutData       = new \stdClass();
			$layoutData->tmpl = $this->tmpl;
			$imagepath        = COM_FABRIK_LIVESITE . '/plugins/fabrik_element/lockrow/images/';
			if ($this->showLocked($data))
			{
				$layoutData->icon  = $params->get('lockrow_locked_icon', 'lock');
				$layoutData->alt   = 'Locked';
				$layoutData->class = 'fabrikElement_lockrow_locked';
			}
			else
			{
				$layoutData->icon  = $params->get('lockrow_locked_icon', 'unlock');
				$layoutData->alt   = 'Not Locked';
				$layoutData->class = 'fabrikElement_lockrow_unlocked';
			}

			//$str = "<img src='" . $imagepath . $icon . "' alt='" . $alt . "' class='fabrikElement_lockrow " . $class . "' />";
			return $layout->render($layoutData);
		}

		return '';
	}

	/**
	 * @param mixed $val
	 * @param array $data
	 *
	 * @return mixed|string
	 *
	 * @since 4.0
	 */
	function storeDatabaseFormat($val, $data)
	{
		return '0';
	}

	/**
	 * defines the type of database table field that is created to store the element's data
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	function getFieldDescription()
	{
		return "VARCHAR(32)";
	}

	/**
	 * return the javascript to create an instance of the class defined in formJavascriptClass
	 * @return array javascript to create instance. Instance name must be 'el'
	 *
	 * @since 4.0
	 */
	function elementJavascript($repeatCounter)
	{
		$id   = $this->getHTMLId($repeatCounter);
		$opts = $this->getElementJSOptions($repeatCounter);

		return array('fbLockrow', $id, $opts);
	}

	/**
	 * @return bool
	 *
	 * @since 4.0
	 */
	function isHidden()
	{
		return true;
	}

	/**
	 * @return string
	 *
	 * @since 4.0
	 */
	public function elementListJavascript()
	{
		$user = Factory::getUser();

		$params      = $this->getParams();
		$user        = Factory::getUser();
		$userid      = $user->get('id');
		$id          = $this->getHTMLId();
		$listModel   = $this->getListModel();
		$list        = $listModel->getTable();
		$formid      = $list->form_id;
		$data        = $listModel->getData();
		$gKeys       = array_keys($data);
		$el_name     = $this->getFullName(true, false);
		$el_name_raw = $el_name . '_raw';
		$row_locks   = array();
		$can_unlocks = array();
		$can_locks   = array();
		foreach ($gKeys as $gKey)
		{
			$groupedData = $data[$gKey];
			foreach ($groupedData as $rowkey)
			{
				$row_locks[$rowkey->__pk_val]   = isset($rowkey->$el_name_raw) ? $this->showLocked($rowkey->$el_name_raw, $userid) : false;
				$can_unlocks[$rowkey->__pk_val] = isset($rowkey->$el_name_raw) ? $this->canUnlock($rowkey->$el_name_raw, $userid) : false;
				$can_locks[$rowkey->__pk_val]   = isset($rowkey->$el_name_raw) ? $this->canLock($rowkey->$el_name_raw, $userid) : false;
			}
		}
		$opts = new \stdClass();

		$crypt        = Worker::getCrypt('aes');
		$crypt_userid = $crypt->encrypt($userid);

		$opts->tableid     = $list->id;
		$opts->livesite    = COM_FABRIK_LIVESITE;
		$opts->imagepath   = COM_FABRIK_LIVESITE . '/plugins/fabrik_element/lockrow/images/';
		$opts->elid        = $this->getElement()->id;
		$opts->userid      = urlencode($crypt_userid);
		$opts->row_locks   = $row_locks;
		$opts->can_unlocks = $can_unlocks;
		$opts->can_locks   = $can_locks;
		$opts->listRef     = $listModel->getRenderContext();
		$opts->formid      = $listModel->getFormModel()->getId();
		$opts->lockIcon    = Html::icon("icon-lock", '', '', true);
		$opts->unlockIcon  = Html::icon("icon-unlock", '', '', true);
		$opts->keyIcon     = Html::icon("icon-key", '', '', true);
		$opts              = json_encode($opts);

		return "new FbLockrowList('$id', $opts);\n";
	}

	/**
	 * @since 4.0
	 */
	function onAjax_unlock()
	{
		$input = $this->app->input;
		$this->setId($input->getInt('element_id'));
		$this->loadMeForAjax();

		$crypt = Worker::getCrypt('aes');

		$listModel = $this->getListModel();
		$list      = $listModel->getTable();
		$listId    = $list->id;
		$formId    = $listModel->getFormModel()->getId();
		$rowid     = $this->app->input->get('row_id', '', 'string');
		$userid    = $this->app->input->get('userid', '', 'string');

		$db_table_name = $this->getTableName();
		$field_name    = FStringHelper::safeColName($this->getFullName(false, false));
		$listModel     = $this->getListModel();
		$pk            = $listModel->getTable()->db_primary_key;
		$db            = $listModel->getDb();
		$query         = $db->getQuery(true);
		//$this_user = Factory::getUser();
		//$this_user_id = $this_user->get('id');
		$this_user_id = $crypt->decrypt(urldecode($userid));

		//$query = "SELECT $field_name FROM $db_table_name WHERE $pk = " . $db->quote($rowid);
		$query->select($field_name)
			->from($db->quoteName($db_table_name))
			->where($pk . ' = ' . $db->quote($rowid));
		$db->setQuery($query);
		$value = $db->loadResult();

		$ret['status'] = 'unlocked';
		$ret['msg']    = 'Row unlocked';
		if (!empty($value))
		{
			if ($this->canUnlock($value, $this_user_id))
			{
				//$query = "UPDATE $db_table_name SET $field_name = 0 WHERE $pk = " . $db->quote($rowid);
				$query->clear()
					->update($db->quoteName($db_table_name))
					->set($field_name . ' = "0"')
					->where($pk . ' = ' . $db->quote($rowid));
				$db->setQuery($query);
				$db->execute();

				// $$$ @TODO - may need to clean com_content cache as well
				$cache = Factory::getCache('com_fabrik');
				$cache->clean();
			}
			else
			{
				$ret['status'] = 'locked';
				$ret['msg']    = 'Row was not unlocked!';
			}
		}
		echo json_encode($ret);
	}
}
