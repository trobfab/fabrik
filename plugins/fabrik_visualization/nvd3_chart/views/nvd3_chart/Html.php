<?php
/**
 * Fabrik nvd3_chart Chart HTML View
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.visualization.nvd3_chart
 * @copyright   Copyright (C) 2005-2015 fabrikar.com - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

namespace Fabrik\Plugins\Visualization\Nvd3_chart\Views;

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Html as HtmlHelper;
use Fabrik\Helpers\Text;
use \JFactory;
use \JHtml;
use \JViewLegacy;
use \JComponentHelper;

/**
 * Fabrik nvd3_chart Chart HTML View
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.visualization.nvd3_chart
 * @since       3.1
 */
class Html extends JViewLegacy
{
	/**
	 * Execute and display a template script.
	 *
	 * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
	 *
	 * @return  mixed  A string if successful, otherwise a JError object.
	 */

	public function display($tpl = 'default')
	{
		$document = JFactory::getDocument();
		$app = JFactory::getApplication();
		$input = $app->input;

		$model = $this->getModel();
		$usersConfig = JComponentHelper::getParams('com_fabrik');
		$model->setId($input->getInt('id', $usersConfig->get('visualizationid', $input->getInt('visualizationid', 0))));

		if (!$model->canView())
		{
			echo Text::_('JERROR_ALERTNOAUTHOR');

			return false;
		}

		$srcs = HtmlHelper::framework();
		HtmlHelper::stylesheet('plugins/fabrik_visualization/nvd3_chart/lib/novus-nvd3/src/nv.d3.css');

		$srcs['FbListFilter'] = 'media/com_fabrik/js/listfilter.js';
		$srcs['AdvancedSearch'] = 'media/com_fabrik/js/advanced-search.js';

		$lib = COM_FABRIK_LIVESITE . 'plugins/fabrik_visualization/nvd3_chart/lib/novus-nvd3/';
		$document->addScript($lib . 'lib/d3.v2.js');
		$document->addScript($lib . 'nv.d3.js');
		$document->addScript($lib . 'src/tooltip.js');
		$document->addScript($lib . 'lib/fisheye.js');
		$document->addScript($lib . 'src/utils.js');
		$document->addScript($lib . 'src/models/legend.js');
		$document->addScript($lib . 'src/models/axis.js');
		$document->addScript($lib . 'src/models/scatter.js');
		$document->addScript($lib . 'src/models/line.js');
		$document->addScript($lib . 'src/models/lineChart.js');
		$document->addScript($lib . 'src/models/multiBar.js');
		$document->addScript($lib . 'src/models/multiBarChart.js');
		$this->row = $model->getVisualization();

		$this->requiredFiltersFound = $model->getRequiredFiltersFound();
		$params = $model->getParams();
		$js = $model->js();
		HtmlHelper::addScriptDeclaration($js);

		$this->params = $params;
		$this->postText = $model->postText;
		$this->containerId = $this->get('ContainerId');
		$this->filters = $this->get('Filters');
		$this->showFilters = $model->showFilters();
		$this->filterFormURL = $this->get('FilterFormURL');
		$tpl = $params->get('nvd3_chart_layout', $tpl);
		$this->_setPath('template', JPATH_ROOT . '/plugins/fabrik_visualization/Nvd3_chart/Views/Nvd3_chart/tmpl/' . $tpl);

		HtmlHelper::stylesheetFromPath(
			'plugins/fabrik_visualization/nvd3_chart/views/nvd3_chart/tmpl/' . $tpl . '/template.css');

		// Assign something to Fabrik.blocks to ensure we can clear filters
		$ref = $model->getJSRenderContext();
		$js = "$ref = {};";
		$js .= "\n" . "Fabrik.addBlock('$ref', $ref);";
		$js .= $model->getFilterJs();

		HtmlHelper::iniRequireJs($model->getShim());
		HtmlHelper::script($srcs, $js);

		$text = $this->loadTemplate();
		HtmlHelper::runContentPlugins($text, true);
		echo $text;
	}
}
