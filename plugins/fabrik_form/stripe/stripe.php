<?php
/**
 * Stripe payment gateway processor
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.stripe
 * @copyright   Copyright (C) 2005-2018  Media A-Team, Inc. - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Fabrik\Helpers\Stripe;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Fabrik\Component\Fabrik\Site\Model\FormModel;
use Fabrik\Component\Fabrik\Site\Plugin\AbstractFormPlugin;
use Fabrik\Helpers\Worker;
use Fabrik\Helpers\ArrayHelper as FArrayHelper;
use Fabrik\Helpers\StringHelper as FStringHelper;
use Fabrik\Component\Fabrik\Administrator\Model\FabModel;

/**
 * Stripe payment gateway processor
 *
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.form.stripe
 * @since       3.0
 */
class PlgFabrik_FormStripe extends AbstractFormPlugin
{
	/**
	 * Stripe charge object
	 *
	 * @since 4.0
	 */
	private $charge = null;

	/**
	 * Stripe customer object
	 *
	 * @since 4.0
	 */
	private $customer = null;

	/**
	 * Customer table name
	 *
	 * @since 4.0
	 */
	private $customerTableName = null;

	/**
	 * Coupon table name
	 *
	 * @since 4.0
	 */
	private $couponsTableName = null;

	/**
	 * Coupon msg
	 *
	 * @since 4.0
	 */
	private $couponMsg = null;

	/**
	 * Attempt to run the Stripe payment, return false (abort save) if it fails
	 *
	 * @return    bool
	 *
	 * @since 4.0
	 */
	public function onBeforeStore()
	{
		$params = $this->getParams();

		if (!Stripe::setupStripe($params, 'stripe'))
		{
			$this->app->enqueueMessage(Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL'));

			return false;
		}

		$formModel  = $this->getModel();
		$listModel  = $formModel->getListModel();
		$input      = $this->app->input;
		$this->data = $this->getProcessData();

		if (!$this->shouldProcess('stripe_conditon', null, $params))
		{
			return true;
		}

		$w      = new Worker;
		$userId = $this->user->get('id');

		if ($this->isTestMode())
		{
			$publicKey = trim($params->get('stripe_test_publishable_key', ''));
			$secretKey = trim($params->get('stripe_test_secret_key', ''));
		}
		else
		{
			$publicKey = trim($params->get('stripe_publishable_key', ''));
			$secretKey = trim($params->get('stripe_secret_key', ''));
		}

		$tokenId    = FArrayHelper::getValue($this->data, 'stripe_token_id', '');
		$tokenEmail = FArrayHelper::getValue($this->data, 'stripe_token_email', '');
		$tokenOpts  = FArrayHelper::getValue($this->data, 'stripe_token_opts', '{}');
		$tokenOpts  = json_decode($tokenOpts);

		if ($this->getProductTableName())
		{
			$product          = $this->getProductOpts();
			$amount           = $product->amount;
			$item             = $product->item;
			$amountMultiplied = $product->amountMultiplied;
		}
		else
		{
			$amount = $params->get('stripe_cost');
			$amount = $w->parseMessageForPlaceHolder($amount, $this->data);

			/**
			 * Adding eval option on cost field
			 * Useful if you use a cart system which will calculate on total shipping or tax fee and apply it. You can return it in the Cost field.
			 * Returning false will log an error and bang out with a runtime exception.
			 */

			if ($params->get('stripe_cost_eval_to_element', '0') === '1')
			{
				$amountKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_cost_element'));
				$amount    = FArrayHelper::getValue($this->data, $amountKey);
				$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

				if (is_array($amount))
				{
					$amount = array_shift($amount);
				}
			}
			else if ($params->get('stripe_cost_eval', 0) == 1)
			{
				$amount = @eval($amount);

				if ($amount === false)
				{
					$msgType   = 'fabrik.form.stripe.cost.eval.err';
					$msg       = new \stdClass;
					$msg->data = $this->data;
					$msg->msg  = "Eval amount code returned false.";
					$msg       = json_encode($msg);
					$this->doLog($msgType, $msg);
					throw new \RuntimeException(Text::_('PLG_FORM_STRIPE_COST_ELEMENT_ERROR'), 500);
				}
			}

			if (trim($amount) == '')
			{
				// Priority to raw data.
				$amountKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_cost_element'));
				$amount    = FArrayHelper::getValue($this->data, $amountKey);
				$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

				if (is_array($amount))
				{
					$amount = array_shift($amount);
				}
			}

			if ($this->useCoupon())
			{
				$amount = $this->getCouponAmount($amount, false);
			}

			$costMultiplier   = $params->get('stripe_currency_multiplier', '100');
			$amountMultiplied = $amount * $costMultiplier;

			$item = $params->get('stripe_item');
			$item = $w->parseMessageForPlaceHolder($item, $this->data);

			if ($params->get('stripe_item_eval_to_element', '0') === '1')
			{
				$itemKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_item_element'));
				$item    = FArrayHelper::getValue($this->data, $itemKey);
				$item    = FArrayHelper::getValue($this->data, $itemKey . '_raw', $item);

				if (is_array($item))
				{
					$amount = array_shift($item);
				}
			}
			else if ($params->get('stripe_item_eval', 0) == 1)
			{
				$item = @eval($item);

				if ($item === false)
				{
					$msgType   = 'fabrik.form.stripe.item.eval.err';
					$msg       = new \stdClass;
					$msg->data = $this->data;
					$msg->msg  = "Eval item code returned false.";
					$msg       = json_encode($msg);
					$this->doLog($msgType, $msg);
					throw new \RuntimeException(Text::_('PLG_FORM_STRIPE_ITEM_ELEMENT_ERROR'), 500);
				}
			}

			$itemRaw = $item;

			if (trim($item) == '')
			{
				$itemRaw = FArrayHelper::getValue($this->data, FStringHelper::safeColNameToArrayKey($params->get('stripe_item_element') . '_raw'));
				$item    = $this->data[FStringHelper::safeColNameToArrayKey($params->get('stripe_item_element'))];

				if (is_array($item))
				{
					$item = array_shift($item);
				}

				if (is_array($itemRaw))
				{
					$itemRaw = array_shift($itemRaw);
				}
			}
		}

		$currencyCode = $params->get('stripe_currency_code', 'USD');
		$currencyCode = $w->parseMessageForPlaceHolder($currencyCode, $this->data);
		$currencyCode = strtolower($currencyCode);

		$customerId        = false;
		$customerTableName = $this->getCustomerTableName();
		$doCustomer        = $customerTableName !== false && !empty($userId);

		if ($doCustomer)
		{
			$customerId = $this->getCustomerId($userId);
		}

		$logErrMsg    = '';
		$logErrType   = '';
		$chargeErrMsg = '';
		$customer     = null;

		if ($amount > 0)
		{
			try
			{
				if ($doCustomer)
				{
					if (empty($customerId))
					{
						$this->customer = \Stripe\Customer::create(array(
							'source'   => $tokenId,
							'email'    => $tokenEmail,
							'metadata' => array(
								'userid' => $userId
							)
						));

						$customerId = $this->customer->id;

						$this->updateCustomerId($userId, $customerId, $tokenOpts);
					}
					else
					{
						if ($params->get('stripe_customers_allow_update_cc', '0') === '1')
						{
							// if they used "update CC" button, we'll have a token
							if (!empty($tokenId))
							{
								$this->customer         = \Stripe\Customer::retrieve($customerId); // stored in your application
								$this->customer->source = $tokenId;
								$this->customer->save();

								$this->updateCustomerId($userId, $customerId, $tokenOpts);
							}
						}
					}

					$this->charge = \Stripe\Charge::create(array(
						"amount"      => $amountMultiplied,
						"currency"    => $currencyCode,
						"customer"    => $customerId,
						"description" => $item,
						"metadata"    => array(
							"listid" => (string) $listModel->getId(),
							"formid" => (string) $formModel->getId(),
							"rowid"  => (string) $this->data['rowid'],
							"userid" => (string) $userId
						)
					));
				}
				else
				{
					$this->charge = \Stripe\Charge::create(array(
						"amount"      => $amountMultiplied,
						"currency"    => $currencyCode,
						"source"      => $tokenId,
						"description" => $item,
						"metadata"    => array(
							"listid" => (string) $listModel->getId(),
							"formid" => (string) $formModel->getId(),
							"rowid"  => (string) $this->data['rowid'],
							"userid" => (string) $userId
						)
					));
				}
			}
			catch (\Stripe\Error\Card $e)
			{
				// Since it's a decline, \Stripe\Error\Card will be caught
				$body         = $e->getJsonBody();
				$err          = $body['error'];
				$logErrMsg    = json_encode($body);
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::sprintf('PLG_FORM_STRIPE_ERROR_DECLINED', $err['message']);
			}
			catch (\Stripe\Error\RateLimit $e)
			{
				// Too many requests made to the API too quickly
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_RATE_LIMITED');
			}
			catch (\Stripe\Error\InvalidRequest $e)
			{
				// Invalid parameters were supplied to Stripe's API
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}
			catch (\Stripe\Error\Authentication $e)
			{
				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_AUTHENTICATION');
			}
			catch (\Stripe\Error\ApiConnection $e)
			{
				// Network communication with Stripe failed
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_NETWORK');
			}
			catch (\Stripe\Error\Base $e)
			{
				// Display a very generic error to the user, and maybe send
				// yourself an email
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}
			catch (Exception $e)
			{
				// Something else happened, completely unrelated to Stripe
				$logErrMsg    = $e->getMessage();
				$logErrType   = 'fabrik.form.stripe.charge.err';
				$chargeErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}

			if (!empty($chargeErrMsg))
			{
				$formModel->setFormErrorMsg($chargeErrMsg);

				$opts           = new \stdClass;
				$opts->listid   = $listModel->getId();
				$opts->formid   = $formModel->getId();
				$opts->rowid    = $this->data['rowid'];
				$opts->userid   = $userId;
				$opts->charge   = $this->charge;
				$opts->customer = $customer;
				$opts->amount   = $amount;
				$opts->item     = $item;
				$msg            = new \stdClass;
				$msg->opts      = $opts;
				$msg->data      = $this->data;
				$msg->err       = $logErrMsg;
				$msg            = json_encode($msg);

				$this->doLog($logErrType, $msg);

				return false;
			}

			if (isset($this->charge))
			{
				$chargeIdField = $this->getFieldName('stripe_charge_id_element', '');

				if (!empty($chargeIdField))
				{
					$formModel->updateFormData($chargeIdField, $this->charge->id, true, true);
				}

				$chargeEmailField = $this->getFieldName('stripe_charge_email_element', '');

				if (!empty($chargeEmailField))
				{
					$formModel->updateFormData($chargeEmailField, $tokenEmail, true, true);
				}
			}

			$opts           = new \stdClass;
			$opts->listid   = $listModel->getId();
			$opts->formid   = $formModel->getId();
			$opts->rowid    = $this->data['rowid'];
			$opts->userid   = $userId;
			$opts->charge   = $this->charge;
			$opts->customer = $customer;
			$msgType        = 'fabrik.form.stripe.charge.prestore.success';
			$msg            = new \stdClass;
			$msg->opts      = $opts;
			$msg->data      = $this->data;
			$msg            = json_encode($msg);
			$this->doLog($msgType, $msg);
		}
		else
		{
			$opts           = new \stdClass;
			$opts->listid   = $listModel->getId();
			$opts->formid   = $formModel->getId();
			$opts->rowid    = $this->data['rowid'];
			$opts->userid   = $userId;
			$opts->charge   = null;
			$opts->customer = $customer;
			$msgType        = 'fabrik.form.stripe.charge.prestore.nocharge';
			$msg            = new \stdClass;
			$msg->opts      = $opts;
			$msg->data      = $this->data;
			$msg            = json_encode($msg);
			$this->doLog($msgType, $msg);
		}

		$stripeCostField = $this->getFieldName('stripe_cost_element', '');

		if (!empty($stripeCostField))
		{
			$formModel->updateFormData($stripeCostField, $amount, true, true);
		}

		if ($this->getProductTableName())
		{
			$productsTotalField = $this->getFieldName('stripe_products_total_element', '');

			if (!empty($productsTotalField))
			{
				$formModel->updateFormData($productsTotalField, $amount, true, true);
			}
		}

		$this->updateCustomerCustom($userId);

		return true;
	}

	/**
	 *
	 * @return bool|void
	 *
	 * @since 4.0
	 */
	public function onAfterProcess()
	{
		if (isset($this->charge))
		{
			$formModel       = $this->getModel();
			$listModel       = $formModel->getListModel();
			$userId          = Factory::getUser()->get('id');
			$opts            = new \stdClass;
			$opts->listid    = $this->getModel()->getListModel()->getId();
			$opts->formid    = (string) $this->getModel()->getId();
			$opts->rowid     = (string) $formModel->formData['rowid'];
			$opts->userid    = $userId;
			$opts->chargeId  = $this->charge->id;
			$opts->timestamp = time();
			$opts->date      = date('Y-m-d H:i:s');
			$opts->userid    = $userId;

			// if this was a new row, we need to update the metadata with the new rowid
			if (empty($this->data['rowid']))
			{
				try
				{
					$this->charge->metadata = array(
						"listid" => (string) $listModel->getId(),
						"formid" => (string) $formModel->getId(),
						"rowid"  => (string) $formModel->formData['rowid'],
						"userid" => (string) $userId
					);
					$this->charge->save();
				}
				catch (\Exception $e)
				{
					// meh
					$this->app->enqueueMessage('Error updating metadata');
					$msgType   = 'fabrik.form.stripe.charge.poststore.err';
					$msg       = new \stdClass;
					$msg->opts = $opts;
					$msg       = json_encode($msg);
					$this->doLog($msgType, $msg);
				}
			}

			$msgType   = 'fabrik.form.stripe.charge.poststore.success';
			$msg       = new \stdClass;
			$msg->opts = $opts;
			$msg       = json_encode($msg);
			$this->doLog($msgType, $msg);
		}
	}

	/**
	 * Sets up HTML to be injected into the form's bottom
	 *
	 * @return void
	 *
	 * @since 4.0
	 */
	public function getBottomContent()
	{
		$this->html = '';

		if ($this->app->input->get('format', 'html') === 'pdf')
		{
			return;
		}

		$params = $this->getParams();

		if (!Stripe::setupStripe($params, 'stripe'))
		{
			$this->app->enqueueMessage(Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL'));

			return false;
		}

		$formModel  = $this->getModel();
		$input      = $this->app->input;
		$this->data = $formModel->data;

		$opts = new \stdClass();

		$opts->formid = $formModel->getId();

		$w      = new Worker;
		$userId = $this->user->get('id');

		if (!empty($userId))
		{
			$opts->email = $this->user->get('email');
		}

		if ($this->isTestMode())
		{
			$opts->publicKey = trim($params->get('stripe_test_publishable_key', ''));
			$secretKey       = trim($params->get('stripe_test_secret_key', ''));
		}
		else
		{
			$opts->publicKey = trim($params->get('stripe_publishable_key', ''));
			$secretKey       = trim($params->get('stripe_secret_key', ''));
		}

		$opts->name            = Text::_($params->get('stripe_dialog_name', ''));
		$opts->panelLabel      = Text::_($params->get('stripe_panel_label', 'PLG_FORM_STRIPE_PAY'));
		$opts->allowRememberMe = false;
		$opts->zipCode         = $params->get('stripe_zipcode_check', '1') === '1';
		$opts->couponElement   = FStringHelper::safeColNameToArrayKey($params->get('stripe_coupon_element'));
		$opts->productElement  = '';
		$opts->qtyElement      = '';
		$opts->totalElement    = '';
		$opts->ccOnFree        = $params->get('stripe_coupons_cc_on_free', '0') === '1';
		$opts->renderOrder     = $this->renderOrder;

		$this->couponMsg    = Text::_('PLG_FORM_STRIPE_COUPON_NO_COUPON_TEXT');
		$currencyCode       = $params->get('stripe_currency_code', 'USD');
		$currencyCode       = $w->parseMessageForPlaceHolder($currencyCode, $this->data);
		$opts->currencyCode = $currencyCode;


		if ($this->getProductTableName())
		{
			$product = $this->getProductOpts(true);
			$amount  = $product->amount;

			if ($this->useCoupon())
			{
				$amount = $this->getCouponAmount($amount, true);
			}

			$amountMultiplied     = $product->amountMultiplied;
			$item                 = $product->item;
			$opts->item           = $product->item;
			$opts->amount         = $amountMultiplied;
			$opts->origAmount     = $amountMultiplied;
			$opts->productElement = FStringHelper::safeColNameToArrayKey($params->get('stripe_products_product_element'));
			$opts->qtyElement     = FStringHelper::safeColNameToArrayKey($params->get('stripe_products_qty_element'));
			$opts->totalElement   = $totalKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_products_total_element'));

			if (!empty($totalKey))
			{
				if (class_exists('NumberFormatter'))
				{
					$formatter                  = new NumberFormatter(Factory::getLanguage()->getTag(), NumberFormatter::CURRENCY);
					$formModel->data[$totalKey] = $formatter->formatCurrency($amount, $currencyCode);
				}
				else
				{
					$formModel->data[$totalKey] = number_format((float) $amount, 2) . ' ' . $currencyCode;;
				}
				$formModel->data[$totalKey . '_raw'] = $amount;
			}
		}
		else
		{
			$amount = $params->get('stripe_cost');
			$amount = $w->parseMessageForPlaceHolder($amount, $this->data);

			if ($params->get('stripe_cost_eval', 0) == 1)
			{
				$amount = @eval($amount);

				if ($amount === false)
				{
					$msgType   = 'fabrik.form.stripe.cost.eval.err';
					$msg       = new \stdClass;
					$msg->data = $this->data;
					$msg->msg  = "Eval amount code returned false.";
					$msg       = json_encode($msg);
					$this->doLog($msgType, $msg);
					throw new \RuntimeException(Text::_('PLG_FORM_STRIPE_COST_ELEMENT_ERROR'), 500);
				}
			}

			if ($this->useCoupon())
			{
				$amount = $this->getCouponAmount($amount, false);
			}

			if ($params->get('stripe_cost_eval_to_element', '0') === '1')
			{
				$amountKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_cost_element'));
				if (!empty($amountKey))
				{
					if (class_exists('NumberFormatter'))
					{
						$formatter                   = new NumberFormatter(Factory::getLanguage()->getTag(), NumberFormatter::CURRENCY);
						$formModel->data[$amountKey] = $formatter->formatCurrency($amount, $currencyCode);
					}
					else
					{
						$formModel->data[$amountKey] = $amount;
					}
					$formModel->data[$amountKey . '_raw'] = $amount;
				}
			}
			else
			{
				if (trim($amount) == '')
				{
					// Priority to raw data.
					$amountKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_cost_element'));
					$amount    = FArrayHelper::getValue($this->data, $amountKey);
					$amount    = FArrayHelper::getValue($this->data, $amountKey . '_raw', $amount);

					if (is_array($amount))
					{
						$amount = array_shift($amount);
					}
				}
			}

			$costMultiplier   = $params->get('stripe_currency_multiplier', '100');
			$amountMultiplied = $amount * $costMultiplier;

			$opts->amount     = $amountMultiplied;
			$opts->origAmount = $amountMultiplied;

			$item = $params->get('stripe_item');
			$item = $w->parseMessageForPlaceHolder($item, $this->data);

			if ($params->get('stripe_item_eval', 0) == 1)
			{
				$item = @eval($item);

				if ($item === false)
				{
					$msgType   = 'fabrik.form.stripe.item.eval.err';
					$msg       = new \stdClass;
					$msg->data = $this->data;
					$msg->msg  = "Eval item code returned false.";
					$msg       = json_encode($msg);
					$this->doLog($msgType, $msg);
					throw new \RuntimeException(Text::_('PLG_FORM_STRIPE_ITEM_ELEMENT_ERROR'), 500);
				}
			}

			if ($params->get('stripe_item_eval_to_element', '0') === '1')
			{
				$itemKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_item_element'));
				if (!empty($itemKey))
				{
					$formModel->data[$itemKey]          = $item;
					$formModel->data[$itemKey . '_raw'] = $item;
				}
			}
			else
			{
				if (trim($item) == '')
				{
					$item = $this->data[FStringHelper::safeColNameToArrayKey($params->get('stripe_item_element'))];

					if (is_array($item))
					{
						$item = array_shift($item);
					}
				}
			}

			$opts->item = $item;
		}

		$opts->billingAddress = $params->get('stripe_collect_billing_address', '0') === '1';

		$customerTableName = $this->getCustomerTableName();
		$doCustomer        = $customerTableName !== false && !empty($userId);

		if ($doCustomer)
		{
			$customerId = $this->getCustomerId($userId);
		}

		$logErrMsg      = '';
		$logErrType     = '';
		$customerErrMsg = '';

		$shim = array();

		if (!empty($customerId))
		{
			$opts->useCheckout = false;

			try
			{
				$customer = \Stripe\Customer::retrieve($customerId);
				$card     = $customer->sources->retrieve($customer->default_source);
			}
			catch (\Stripe\Error\RateLimit $e)
			{
				// Too many requests made to the API too quickly
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$customerErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_RATE_LIMITED');
			}
			catch (\Stripe\Error\InvalidRequest $e)
			{
				// Invalid parameters were supplied to Stripe's API
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$body           = $e->getJsonBody();
				$err            = $body['error'];
				$customerErrMsg = Text::sprintf('PLG_FORM_STRIPE_ERROR_CUSTOMER', $err['message']);
			}
			catch (\Stripe\Error\Authentication $e)
			{
				// Authentication with Stripe's API failed
				// (maybe you changed API keys recently)
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$customerErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_AUTHENTICATION');
			}
			catch (\Stripe\Error\ApiConnection $e)
			{
				// Network communication with Stripe failed
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$customerErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_NETWORK');
			}
			catch (\Stripe\Error\Base $e)
			{
				// Display a very generic error to the user, and maybe send
				// yourself an email
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$customerErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}
			catch (Exception $e)
			{
				// Something else happened, completely unrelated to Stripe
				$logErrMsg      = $e->getMessage();
				$logErrType     = 'fabrik.form.stripe.customer.err';
				$customerErrMsg = Text::_('PLG_FORM_STRIPE_ERROR_INTERNAL');
			}


			if (!empty($customerErrMsg))
			{
				$this->app->enqueueMessage($customerErrMsg, 'message');

				$opts            = new \stdClass;
				$opts->listid    = $formModel->getListModel()->getId();
				$opts->formid    = $formModel->getId();
				$opts->rowid     = $formModel->getRowId();
				$opts->cusomerid = $customerId;
				$opts->customer  = $customer;
				$opts->amount    = $amount;
				$opts->item      = $item;
				$opts->userid    = $userId;
				$msg             = new \stdClass;
				$msg->opts       = $opts;
				$msg->data       = $this->data;
				$msg->err        = $logErrMsg;
				$msg             = json_encode($msg);

				$this->doLog($logErrType, $msg);

				return false;
			}

			$opts->useCheckout = false;

			if ($params->get('stripe_customers_allow_update_cc', '0') === '1')
			{
				$opts->updateCheckout = true;
				$opts->panelLabel     = Text::_(
					$params->get('stripe_customers_update_button_name', "PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_BUTTON_NAME")
				);
				$dep                  = new \stdClass;
				$dep->deps            = array(
					'stripe'
				);
				$shim['fabrik/form']  = $dep;
				//FabrikHelperHTML::script('https://checkout.stripe.com/checkout.js');
				Text::script('PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_UPDATED');
			}
			else
			{
				$opts->updateCheckout = false;
			}

			Text::script('PLG_FORM_STRIPE_CALCULATING');

			$layout                       = $this->getLayout('existing-customer');
			$layoutData                   = new \stdClass();
			$layoutData->testMode         = $this->isTestMode();
			$layoutData->useUpdateButton  = $opts->updateCheckout;
			$layoutData->updateButtonName = Text::_($params->get('stripe_customers_update_button_name', "PLG_FORM_STRIPE_CUSTOMERS_UPDATE_CC_BUTTON_NAME"));
			$layoutData->card             = $card;
			$layoutData->amount           = $amount;
			$layoutData->currencyCode     = $currencyCode;
			$layoutData->langTag          = Factory::getLanguage()->getTag();
			$layoutData->item             = $item;
			$layoutData->showCoupon       = $this->useCoupon();
			$layoutData->couponMsg        = $this->couponMsg;
			$layoutData->bottomText       = Text::_($params->get('stripe_charge_bottom_text_existing', 'PLG_FORM_STRIPE_CHARGE_BOTTOM_TEXT_EXISTING'));
			$this->html                   = $layout->render($layoutData);
		}
		else
		{
			$opts->useCheckout        = true;
			$layout                   = $this->getLayout('checkout');
			$layoutData               = new \stdClass();
			$layoutData->testMode     = $this->isTestMode();
			$layoutData->amount       = $amount;
			$layoutData->currencyCode = $currencyCode;
			$layoutData->langTag      = Factory::getLanguage()->getTag();
			$layoutData->bottomText   = Text::_($params->get('stripe_charge_bottom_text_new', 'PLG_FORM_STRIPE_CHARGE_BOTTOM_TEXT_NEW'));
			$layoutData->bottomText   = $w->parseMessageForPlaceHolder($layoutData->bottomText, $this->data);
			$layoutData->item         = $item;
			$layoutData->showCoupon   = $this->useCoupon();
			$layoutData->couponMsg    = $this->couponMsg;
			$this->html               = $layout->render($layoutData);
			$dep                      = new \stdClass;
			$dep->deps                = array(
				'stripe'
			);
			$shim['fabrik/form']      = $dep;
			//FabrikHelperHTML::script('https://checkout.stripe.com/checkout.js');
		}

		//FabrikHelperHTML::iniRequireJS($shim, array('stripe' => 'https://checkout.stripe.com/checkout'));

		$opts = json_encode($opts);

		$this->formJavascriptClass($params, $formModel);
		$formModel->formPluginJS['Stripe' . $this->renderOrder] = 'var stripe = new Stripe(' . $opts . ');';

	}

	/**
	 * Inject custom html into the bottom of the form
	 *
	 * @param   int $c plugin counter
	 *
	 * @return  string  html
	 *
	 * @since 4.0
	 */
	public function getBottomContent_result($c)
	{
		return $this->html;
	}

	/**
	 * Get the Customer table name
	 *
	 * @return  string  db table name
	 *
	 * @since 4.0
	 */
	protected function getProductTableName()
	{
		if (isset($this->productTableName))
		{
			return $this->productTableName;
		}

		$params       = $this->getParams();
		$productTable = (int) $params->get('stripe_products_table', '');

		if (empty($productTable))
		{
			$this->productTableName = false;

			return false;
		}

		$db    = Worker::getDbo();
		$query = $db->getQuery(true);
		$query->select('db_table_name')->from('#__{package}_lists')->where('id = ' . $productTable);
		$db->setQuery($query);
		$db_table_name = $db->loadResult();

		if (!isset($db_table_name))
		{
			throw new \RuntimeException('PLG_FORM_STRIPE_CONFIG_ERROR');

			$this->productTableName = false;

			return false;
		}

		$this->productTableName = $db_table_name;

		return $this->productTableName;
	}

	/**
	 * @param $productId
	 *
	 * @return mixed
	 *
	 * @since 4.0
	 */
	private function getProduct($productId)
	{
		$params           = $this->getParams();
		$pDb              = Worker::getDbo(false, $params->get('stripe_products_connection'));
		$pQuery           = $pDb->getQuery(true);
		$productIdField   = FStringHelper::shortColName($params->get('stripe_products_pk'));
		$productNameField = FStringHelper::shortColName($params->get('stripe_products_name'));
		$productDescField = FStringHelper::shortColName($params->get('stripe_products_desc'));
		$productCostField = FStringHelper::shortColName($params->get('stripe_products_cost'));

		if (empty($productIdField) || empty($productNameField) || empty($productCostField))
		{
			throw new \RuntimeException('PLG_FORM_STRIPE_CONFIG_ERROR');
		}

		$pQuery
			->select($pDb->quoteName($productNameField) . ' AS `product_name`')
			->select($pDb->quoteName($productCostField) . ' AS `product_cost`')
			->from($pDb->quoteName($this->getProductTableName()))
			->where($pDb->quoteName($productIdField) . ' = ' . $pDb->quote($productId));

		if (!empty($productDescField))
		{
			$pQuery->select($pDb->quoteName($productDescField) . 'AS `product_desc`');
		}
		else
		{
			$pQuery->select('"" AS `product_desc`');
		}

		$pDb->setQuery($pQuery);

		return $pDb->loadObject();
	}

	/**
	 * @param bool $getDefaults
	 *
	 * @return \stdClass
	 *
	 * @since 4.0
	 */
	protected function getProductOpts($getDefaults = false)
	{
		$opts       = new \stdClass;
		$formModel  = $this->getModel();
		$params     = $this->getParams();
		$productKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_products_product_element'));

		if ($getDefaults)
		{
			$elementModel = $formModel->getElement($productKey);
			$default      = $elementModel->getDefaultValue($this->data);
		}
		else
		{
			$default = '';
		}

		$productId = FArrayHelper::getValue($this->data, $productKey, $default);
		$productId = FArrayHelper::getValue($this->data, $productKey . '_raw', $productId);
		$productId = is_array($productId) ? $productId[0] : $productId;

		if (!empty($productId))
		{
			$product = $this->getProduct($productId);
			$amount  = $product->product_cost;

			$productQtyKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_products_qty_element'));

			if (!empty($productQtyKey))
			{
				if ($getDefaults)
				{
					$elementModel = $formModel->getElement($productQtyKey);
					$default      = $elementModel->getDefaultValue($this->data);
				}
				else
				{
					$default = '';
				}

				$productQty = FArrayHelper::getValue($this->data, $productQtyKey, $default);
				$productQty = FArrayHelper::getValue($this->data, $productQtyKey . '_raw', $productQty);

				$amount = $amount * (int) $productQty;
			}

			if ($this->useCoupon())
			{
				$amount = $this->getCouponAmount($amount, $getDefaults);
			}

			$costMultiplier   = $params->get('stripe_currency_multiplier', '100');
			$amountMultiplied = $amount * $costMultiplier;

			$opts->amountMultiplied = $amountMultiplied;
			$opts->amount           = $amount;
			$opts->origAmount       = $amount;
			$opts->item             = $product->product_name;
			$opts->desc             = $product->product_desc;
		}
		else
		{
			$opts->amountMultiplied = '';
			$opts->amount           = '';
			$opts->origAmount       = '';
			$opts->amount           = '';
			$opts->item             = '';
			$opts->desc             = '';
		}

		return $opts;
	}

	/**
	 * Get the Customer table name
	 *
	 * @return  string  db table name
	 *
	 * @since 4.0
	 */
	protected function getCustomerTableName()
	{
		if (isset($this->customerTableName))
		{
			return $this->customerTableName;
		}

		$params        = $this->getParams();
		$customerTable = (int) $params->get('stripe_customers_table', '');

		if (empty($customerTable))
		{
			$this->customerTableName = false;

			return false;
		}

		$db    = Worker::getDbo();
		$query = $db->getQuery(true);
		$query->select('db_table_name')->from('#__{package}_lists')->where('id = ' . (int) $params->get('stripe_customers_table'));
		$db->setQuery($query);
		$db_table_name = $db->loadResult();

		if (!isset($db_table_name))
		{
			$this->customerTableName = false;

			return false;
		}

		$this->customerTableName = $db_table_name;

		return $this->customerTableName;
	}


	/**
	 * @param $userId
	 *
	 * @return mixed
	 *
	 * @since 4.0
	 */
	private function getCustomerId($userId)
	{
		$params       = $this->getParams();
		$cDb          = Worker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery       = $cDb->getQuery(true);
		$cUserIdField = FStringHelper::shortColName($params->get('stripe_customers_userid'));

		if ($this->isTestMode())
		{
			$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid_test'));

			if (empty($cStripeIdField))
			{
				$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid'));
			}
		}
		else
		{
			$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid'));
		}

		if (empty($cUserIdField) || empty($cStripeIdField))
		{
			throw new \RuntimeException('PLG_FORM_STRIPE_CONFIG_ERROR');
		}

		$cQuery
			->select($cDb->quoteName($cStripeIdField))
			->from($cDb->quoteName($this->getCustomerTableName()))
			->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
		$cDb->setQuery($cQuery);

		return $cDb->loadResult();
	}

	/**
	 * @param $userId
	 *
	 *
	 * @since 4.0
	 */
	private function updateCustomerCustom($userId)
	{
		$params       = $this->getParams();
		$cDb          = Worker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery       = $cDb->getQuery(true);
		$cUserIdField = FStringHelper::shortColName($params->get('stripe_customers_userid'));
		$customField  = FStringHelper::shortColName($params->get('stripe_customers_custom_field'));
		$customValue  = $params->get('stripe_customers_custom_value', '');

		if (empty($cUserIdField) || empty($customField) || empty($customValue))
		{
			return;
		}

		$w           = new Worker;
		$customValue = $w->parseMessageForPlaceHolder($customValue, $this->data);

		$cQuery
			->clear()
			->update($cDb->quoteName($this->getCustomerTableName()))
			->set($cDb->quoteName($customField) . ' = ' . $cDb->quote($customValue))
			->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
		$cDb->setQuery($cQuery);
		$cDb->execute();
	}

	/**
	 * @param $userId
	 * @param $customerId
	 * @param $opts
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	private function updateCustomerId($userId, $customerId, $opts)
	{
		$params       = $this->getParams();
		$cDb          = Worker::getDbo(false, $params->get('stripe_customers_connection'));
		$cQuery       = $cDb->getQuery(true);
		$cUserIdField = FStringHelper::shortColName($params->get('stripe_customers_userid'));

		if ($this->isTestMode())
		{
			$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid_test'));

			if (empty($cStripeIdField))
			{
				$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid'));
			}
		}
		else
		{
			$cStripeIdField = FStringHelper::shortColName($params->get('stripe_customers_stripeid'));
		}

		$done = false;

		if (empty($cUserIdField) || empty($cStripeIdField))
		{
			throw new \RuntimeException('Stripe plugin not configured correctly');
		}

		$stripeAddressFields = array(
			"name",
			"address_country",
			"address_country_code",
			"address_zip",
			"address_line1",
			"address_city",
			"address_state"
		);

		$setList = array();

		if ($params->get('stripe_collect_billing_address', '0') === '1')
		{
			foreach ($stripeAddressFields as $field)
			{
				$paramName = 'stripe_customers_billing_' . $field;
				$fieldName = FStringHelper::shortColName($params->get($paramName));

				if (!empty($fieldName))
				{
					$optName = 'billing_' . $field;

					if (isset($opts->$optName))
					{
						$setList[] = $cDb->quoteName($fieldName) . ' = ' . $cDb->quote($opts->$optName);
					}
				}

			}
		}

		if ($params->get('stripe_collect_shipping_address', '0') === '1')
		{
			foreach ($stripeAddressFields as $field)
			{
				$paramName = 'stripe_customers_shipping_' . $field;
				$fieldName = FStringHelper::shortColName($params->get($paramName));

				if (!empty($fieldName))
				{
					$optName = 'shipping_' . $field;

					if (isset($opts->$optName))
					{
						$setList[] = $cDb->quoteName($fieldName) . ' = ' . $cDb->quote($opts->$optName);
					}
				}

			}
		}

		try
		{
			if ($params->get('stripe_customers_insert', '') === '1')
			{
				$cQuery
					->select('*')
					->from($cDb->quoteName($this->getCustomerTableName()))
					->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));
				$cDb->setQuery($cQuery);
				$result = $cDb->loadObject();

				if (empty($result))
				{
					$cQuery
						->clear()
						->insert($cDb->quoteName($this->getCustomerTableName()))
						->set($cDb->quoteName($cStripeIdField) . ' = ' . $cDb->quote($customerId))
						->set($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));

					foreach ($setList as $set)
					{
						$cQuery->set($set);
					}

					$cDb->setQuery($cQuery);
					$cDb->execute();
					$done = true;
				}
			}

			if (!$done)
			{
				$cQuery
					->clear()
					->update($cDb->quoteName($this->getCustomerTableName()))
					->set($cDb->quoteName($cStripeIdField) . ' = ' . $cDb->quote($customerId))
					->where($cDb->quoteName($cUserIdField) . ' = ' . $cDb->quote($userId));

				foreach ($setList as $set)
				{
					$cQuery->set($set);
				}

				$cDb->setQuery($cQuery);
				$cDb->execute();
			}
		}
		catch (\Exception $e)
		{
			$this->doLog('fabrik.form.stripe.customer.err', $e->getMessage());
			$this->app->enqueueMessage($e->getMessage(), 'error');

			return false;
		}

		return true;
	}

	/**
	 * Get the Customer table name
	 *
	 * @return  string  db table name
	 *
	 * @since 4.0
	 */
	protected function getcouponsTableName()
	{
		if (isset($this->couponsTableName))
		{
			return $this->couponsTableName;
		}

		$params       = $this->getParams();
		$couponsTable = (int) $params->get('stripe_coupons_table', '');

		if (empty($couponsTable))
		{
			$this->couponsTableName = false;

			return false;
		}

		$db    = Worker::getDbo();
		$query = $db->getQuery(true);
		$query->select('db_table_name')->from('#__{package}_lists')->where('id = ' . (int) $params->get('stripe_coupons_table'));
		$db->setQuery($query);
		$db_table_name = $db->loadResult();

		if (!isset($db_table_name))
		{
			echo (string) $query;
			$this->couponsTableName = false;

			return false;
		}

		$this->couponsTableName = $db_table_name;

		return $this->couponsTableName;
	}

	/**
	 * @param      $amount
	 * @param bool $getDefaults
	 *
	 * @return float|int
	 *
	 * @since 4.0
	 */
	private function getCouponAmount($amount, $getDefaults = false)
	{
		$params    = $this->getParams();
		$formModel = $this->getModel();
		$couponKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_coupon_element'));

		if (!empty($couponKey))
		{
			if ($getDefaults)
			{
				$elementModel = $formModel->getElement($couponKey);
				$couponCode   = $elementModel->getDefaultValue($this->data);
			}
			else
			{
				$couponCode = FArrayHelper::getValue($this->data, $couponKey);
				$couponCode = FArrayHelper::getValue($this->data, $couponKey . '_raw', $couponCode);
			}

			if (!empty($couponCode))
			{
				$coupon          = $this->getCoupon($couponCode, false);
				$this->couponMsg = $coupon->msg;

				if ($coupon->ok === '1')
				{
					switch ($coupon->discount_type)
					{
						case 'amount':
							$amount = $coupon->discount_amount;
							break;
						case 'amount_off':
							$amount = $amount - $coupon->discount_amount;
							break;
						case 'percent':
							$amount = ($amount * $coupon->discount_amount) / 100;
							break;
						case 'percent_off':
						default:
							$discount = ($amount * $coupon->discount_amount) / 100;
							$amount   = $amount - $discount;
					}
				}
			}
		}

		return $amount;
	}

	/**
	 * @param      $value
	 * @param bool $increment
	 *
	 * @return StdClass
	 *
	 * @since 4.0
	 */
	private function getCoupon($value, $increment = false)
	{
		$params      = $this->getParams();
		$couponTable = $this->getcouponsTableName();

		$ret                  = new \stdClass;
		$ret->ok              = '0';
		$ret->msg             = Text::_('PLG_FORM_STRIPE_COUPON_ERROR');
		$ret->discount_amount = '';
		$ret->discount_type   = '2';

		if (empty($value))
		{
			$ret->msg = Text::_('PLG_FORM_STRIPE_COUPON_NO_COUPON_TEXT');
		}
		else if (!empty($couponTable))
		{
			$couponField    = FStringHelper::shortColName($params->get('stripe_coupons_coupon_field'));
			$discountField  = FStringHelper::shortColName($params->get('stripe_coupons_discount_field'));
			$typeField      = FStringHelper::shortColName($params->get('stripe_coupons_type_field'));
			$publishedField = FStringHelper::shortColName($params->get('stripe_coupons_published_field'));
			$limitField     = FStringHelper::shortColName($params->get('stripe_coupons_limit_field'));
			$useField       = FStringHelper::shortColName($params->get('stripe_coupons_use_field'));
			$startDateField = FStringHelper::shortColName($params->get('stripe_coupons_start_date_field'));
			$endDateField   = FStringHelper::shortColName($params->get('stripe_coupons_end_date_field'));

			$useLimit     = !empty($limitField) && !empty($useField);
			$useUse       = !empty($useField);
			$usePublish   = !empty($publishedField);
			$useType      = !empty($typeField);
			$useStartDate = !empty($startDateField);
			$useEndDate   = !empty($endDateField);
			$useDateRange = $useStartDate && $useEndDate;

			$db    = Factory::getDbo();
			$query = $db->getQuery(true);
			$query->select($db->quoteName($couponField) . ' AS `coupon_code`');
			$query->select($db->quoteName($discountField) . ' AS `discount`');

			if ($useLimit)
			{
				$query->select($db->quoteName($limitField) . ' AS `limit`');
			}

			if ($useUse)
			{
				$query->select($db->quoteName($useField) . ' AS `used`');
			}

			if ($useType)
			{
				$query->select($db->quoteName($typeField) . ' AS `type`');
			}

			$query->from($db->quoteName($couponTable));
			$query->where($db->quoteName($couponField) . ' = ' . $db->quote($value));

			if ($usePublish)
			{
				$query->where('COALESCE(' . $db->quoteName($publishedField) . ', 0) != 0');
			}

			if ($useDateRange)
			{
				$query->where('NOW() BETWEEN ' . $db->nameQuote($startDateField) . ' AND ' . $db->quote($endDateField));
			}
			else if ($useStartDate)
			{
				$query->where('NOW() >= ' . $db->quoteName($startDateField));
			}
			else if ($useEndDate)
			{
				$query->where('NOW() <= ' . $db->quoteName($endDateField));
			}

			$db->setQuery($query);

			try
			{
				$coupon = $db->loadObject();
			}
			catch (\Exception $e)
			{
				$ret->msg = Text::_('PLG_FORM_STRIPE_COUPON_ERROR');
				$ret->ok  = '0';

				return $ret;
			}

			if (empty($coupon))
			{
				$ret->msg = Text::_('PLG_FORM_STRIPE_COUPON_NOSUCH');
				$ret->ok  = '0';

				return $ret;
			}

			if ($useLimit)
			{
				if ((int) $coupon->limit !== 0 && (int) $coupon->used >= (int) $coupon->limit)
				{
					$ret->msg = Text::_('PLG_FORM_STRIPE_COUPON_LIMIT_REACHED');
					$ret->ok  = '0';

					return $ret;
				}
			}

			$ret->ok              = '1';
			$ret->msg             = Text::_('PLG_FORM_STRIPE_COUPON_OK');
			$ret->discount_amount = $coupon->discount;

			if ($useType)
			{
				switch ($coupon->type)
				{
					case '1':
					case 'percent':
						$ret->discount_type = 'percent';
						break;
					case '2':
					case 'percent_off':
						$ret->discount_type = 'percent_off';
						break;
					case '3':
					case 'amount_off':
						$ret->discount_type = 'amount_off';
						break;
					case '4':
					case 'amount':
						$ret->discount_type = 'amount';
						break;
				}
			}

			if ($useUse && $increment)
			{
				$query->clear()
					->update($db->quoteName($couponTable))
					->set($db->quoteName($useField) . ' = ' . $db->quoteName($useField) . ' + 1')
					->where($db->quoteName($couponField) . ' = ' . $db->quote($value));
				$db->setQuery($query);

				try
				{
					$db->execute();
				}
				catch (\Exception $e)
				{
					// meh
				}
			}
		}

		return $ret;
	}

	/**
	 * Gets the options for the drop down - used in package when forms update
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */
	public function onAjax_getCoupon()
	{
		$input       = $this->app->input;
		$value       = $input->get('v', '', 'string');
		$amount      = $input->get('amount', '', 'string');
		$formId      = $input->get('formid', '', 'string');
		$renderOrder = $input->get('renderOrder', '', 'string');
		/** @var FormModel $formModel */
		$formModel = FabModel::getInstance(FormModel::class);
		$formModel->setId($formId);
		$params         = $formModel->getParams();
		$params         = $this->setParams($params, $renderOrder);
		$coupon         = $this->getCoupon($value);
		$costMultiplier = $params->get('stripe_currency_multiplier', '100');

		if ($coupon->ok === '1')
		{
			switch ($coupon->discount_type)
			{
				case 'amount':
					$coupon->stripe_amount = $coupon->discount_amount * $costMultiplier;
					break;
				case 'amount_off':
					$coupon->stripe_amount = $amount - ($coupon->discount_amount * $costMultiplier);
					break;
				case 'percent':
					$coupon->stripe_amount = ($amount * $coupon->discount_amount) / 100;
					break;
				case 'percent_off':
				default:
					$discount              = ($amount * $coupon->discount_amount) / 100;
					$coupon->stripe_amount = $amount - $discount;
			}
		}
		else
		{
			$coupon->stripe_amount = $amount;
		}


		$amountUnMultiplied = $coupon->stripe_amount / $costMultiplier;

		if (class_exists('NumberFormatter'))
		{
			$currencyCode           = $params->get('stripe_currency_code', 'USD');
			$currencyCode           = strtolower($currencyCode);
			$formatter              = new NumberFormatter(Factory::getLanguage()->getTag(), NumberFormatter::CURRENCY);
			$coupon->display_amount = $formatter->formatCurrency($amountUnMultiplied, $currencyCode);
		}
		else
		{
			$currencyCode           = $params->get('stripe_currency_code', 'USD');
			$coupon->display_amount = number_format((float) $amountUnMultiplied, 2) . ' ' . $currencyCode;
		}

		echo json_encode($coupon);
	}

	/**
	 * Gets the options for the drop down - used in package when forms update
	 *
	 * @return  void
	 *
	 * @since 4.0
	 */
	public function onAjax_getCost()
	{
		$input       = $this->app->input;
		$productId   = $input->get('productId', '', 'string');
		$qty         = $input->get('qty', '', 'string');
		$couponCode  = $input->get('coupon', '', 'string');
		$formId      = $input->get('formid', '', 'string');
		$renderOrder = $input->get('renderOrder', '', 'string');
		$formModel   = FabModel::getInstance(FormModel::class);
		$formModel->setId($formId);
		$params                  = $formModel->getParams();
		$params                  = $this->setParams($params, $renderOrder);
		$product                 = $this->getProduct($productId);
		$costMultiplier          = $params->get('stripe_currency_multiplier', '100');
		$response                = new \stdClass;
		$response->stripe_amount = $product->product_cost * $costMultiplier;

		if (!empty($qty))
		{
			$response->stripe_amount = $response->stripe_amount * (int) $qty;
		}

		if (!empty($couponCode))
		{
			$coupon        = $this->getCoupon($couponCode);
			$response->msg = $coupon->msg;

			if ($coupon->ok === '1')
			{
				switch ($coupon->discount_type)
				{
					case 'amount':
						$response->stripe_amount = $coupon->discount_amount * $costMultiplier;
						break;
					case 'amount_off':
						$response->stripe_amount = $response->stripe_amount - ($coupon->discount_amount * $costMultiplier);
						break;
					case 'percent':
						$response->stripe_amount = ($response->stripe_amount * $coupon->discount_amount) / 100;
						break;
					case 'percent_off':
					default:
						$discount                = ($response->stripe_amount * $coupon->discount_amount) / 100;
						$response->stripe_amount = $response->stripe_amount - $discount;
				}
			}
		}
		else
		{
			$response->msg = Text::_('PLG_FORM_STRIPE_COUPON_NO_COUPON_TEXT');
		}

		$amountUnMultiplied = $response->stripe_amount / $costMultiplier;
		$currencyCode       = $params->get('stripe_currency_code', 'USD');

		if (class_exists('NumberFormatter'))
		{
			$currencyCode             = strtolower($currencyCode);
			$formatter                = new NumberFormatter(Factory::getLanguage()->getTag(), NumberFormatter::CURRENCY);
			$response->display_amount = $formatter->formatCurrency($amountUnMultiplied, $currencyCode);
		}
		else
		{
			$response->display_amount = number_format((float) $amountUnMultiplied, 2) . ' ' . $currencyCode;
		}

		echo json_encode($response);
	}

	/**
	 *
	 *
	 * @since 4.0
	 */
	public function onWebhook()
	{
		$formId      = $this->app->input->get('formid', '', 'string');
		$renderOrder = $this->app->input->get('renderOrder', '', 'string');
		$formModel   = FabModel::getInstance(FormModel::class);
		$formModel->setId($formId);
		$params = $formModel->getParams();
		$params = $this->setParams($params, $renderOrder);

		if (!Stripe::setupStripe($params, 'stripe'))
		{
			http_response_code(400);
			jexit();
		}

		if ($this->isTestMode())
		{
			$webhookSecret = trim($params->get('stripe_test_webhook_secret', ''));
		}
		else
		{
			$webhookSecret = trim($params->get('stripe_webhook_secret', ''));
		}

		$input     = @file_get_contents("php://input");
		$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];

		try
		{
			$event = \Stripe\Webhook::constructEvent($input, $signature, $webhookSecret);
		}
		catch (\UnexpectedValueException $e)
		{
			http_response_code(400);
			jexit();
		}
		catch (\Stripe\SignatureVerification $e)
		{
			http_response_code(400);
			jexit();
		}

		switch ($event->type)
		{
			default:
				http_response_code(200);
				break;
		}

		jexit();
	}

	/**
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	private function isTestMode()
	{
		$params = $this->getParams();

		return $params->get('stripe_test_mode', $this->app->input->get('stripe_testmode', '0')) === '1';
	}

	/**
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	private function useCoupon()
	{
		$params    = $this->getParams();
		$couponKey = FStringHelper::safeColNameToArrayKey($params->get('stripe_coupon_element'));

		return !empty($couponKey);
	}
}
