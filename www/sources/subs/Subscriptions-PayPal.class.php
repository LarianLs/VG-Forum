<?php

/**
 * Payment Gateway: PayPal
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1.9
 *
 */

/**
 * Class for returning available form data for this gateway
 *
 * @package Subscriptions
 */
class PayPal_Display
{
	/**
	 * Name of this payment gateway
	 * @var string
	 */
	public $title = 'PayPal';

	/**
	 * Return the admin settings for this gateway
	 *
	 * @return array
	 */
	public function getGatewaySettings()
	{
		global $txt;

		$setting_data = array(
			array(
				'text', 'paypal_email',
				'subtext' => $txt['paypal_email_desc']
			),
		);

		return $setting_data;
	}

	/**
	 * Is this enabled for new payments?
	 *
	 * @return boolean
	 */
	public function gatewayEnabled()
	{
		global $modSettings;

		return !empty($modSettings['paypal_email']);
	}

	/**
	 * Called from Profile-Actions.php to return a unique set of fields for the given gateway
	 * plus all the standard ones for the subscription form
	 *
	 * @param int $unique_id for the transaction
	 * @param mixed[] $sub_data subscription data array, name, reoccurring, etc
	 * @param int $value amount of the transaction
	 * @param string $period length of the transaction
	 * @param string $return_url
	 * @return string
	 */
	public function fetchGatewayFields($unique_id, $sub_data, $value, $period, $return_url)
	{
		global $modSettings, $txt, $boardurl;

		$return_data = array(
			'form' => 'https://www.' . (!empty($modSettings['paidsubs_test']) ? 'sandbox.' : '') . 'paypal.com/cgi-bin/webscr',
			'id' => 'paypal',
			'hidden' => array(),
			'title' => $txt['paypal'],
			'desc' => $txt['paid_confirm_paypal'],
			'submit' => $txt['paid_paypal_order'],
			'javascript' => '',
		);

		// All the standard bits.
		$return_data['hidden']['business'] = $modSettings['paypal_email'];
		$return_data['hidden']['item_name'] = $sub_data['name'] . ' ' . $txt['subscription'];
		$return_data['hidden']['item_number'] = $unique_id;
		$return_data['hidden']['currency_code'] = strtoupper($modSettings['paid_currency_code']);
		$return_data['hidden']['no_shipping'] = 1;
		$return_data['hidden']['no_note'] = 1;
		$return_data['hidden']['amount'] = $value;
		$return_data['hidden']['cmd'] = !$sub_data['repeatable'] ? '_xclick' : '_xclick-subscriptions';
		$return_data['hidden']['return'] = $return_url;
		$return_data['hidden']['a3'] = $value;
		$return_data['hidden']['src'] = 1;
		$return_data['hidden']['notify_url'] = $boardurl . '/subscriptions.php';

		// Now stuff dependant on what we're doing.
		if ($sub_data['flexible'])
		{
			$return_data['hidden']['p3'] = 1;
			$return_data['hidden']['t3'] = strtoupper(substr($period, 0, 1));
		}
		else
		{
			preg_match('~(\d*)(\w)~', $sub_data['real_length'], $match);
			$unit = $match[1];
			$period = $match[2];

			$return_data['hidden']['p3'] = $unit;
			$return_data['hidden']['t3'] = $period;
		}

		// If it's repeatable do some javascript to respect this idea.
		if (!empty($sub_data['repeatable']))
		{
			$return_data['javascript'] = '
				var container = document.getElementById("' . $return_data['id'] . '");

				container.innerHTML += \'<label for="do_paypal_recur"><input type="checkbox" name="do_paypal_recur" id="do_paypal_recur" checked="checked" onclick="switchPaypalRecur();" class="input_check" />' . $txt['paid_make_recurring'] . '</label><br />\';

				function switchPaypalRecur()
				{
					document.getElementById("paypal_cmd").value = document.getElementById("do_paypal_recur").checked ? "_xclick-subscriptions" : "_xclick";
				}';
		}

		return $return_data;
	}
}

/**
 * Class of functions to validate a IPN response and provide details of the payment
 *
 * @package Subscriptions
 */
class PayPal_Payment
{
	/**
	 * Holds the IPN response data
	 * @var string|mixed[]
	 */
	private $return_data;

	/**
	 * Data to send to paypal IPN
	 * @var string
	 */
	private $requestString;

	/**
	 * If this is a test sandbox run or not
	 * @var bool
	 */
	private $paidsubsTest;

	/**
	 * This function returns true/false for whether this gateway thinks the data is intended for it.
	 *
	 * @return boolean
	 */
	public function isValid()
	{
		global $modSettings;

		// Has the user set up an email address?
		if (empty($modSettings['paypal_email']))
		{
			return false;
		}

		// Check the correct transaction types are even here.
		if ((!isset($_POST['txn_type']) && !isset($_POST['payment_status'])) || (!isset($_POST['business']) && !isset($_POST['receiver_email'])))
		{
			return false;
		}

		// Correct email address?
		if (!isset($_POST['business']))
		{
			$_POST['business'] = $_POST['receiver_email'];
		}

		// Return true or false if the data is intended for this
		return !($modSettings['paypal_email'] !== $_POST['business'] && (empty($modSettings['paypal_additional_emails']) || !in_array($_POST['business'], explode(',', $modSettings['paypal_additional_emails']))));
	}

	/**
	 * Post the IPN data received back to paypal for validation
	 *
	 * - Sends the complete unaltered message back to PayPal.
	 * - The message must contain the same fields in the same order and be encoded in the same way as the original message
	 * - PayPal will respond back with a single word, which is either VERIFIED if the message originated with PayPal or INVALID
	 * - If valid returns the subscription and member IDs we are going to process if it passes
	 *
	 * @return string
	 */
	public function precheck()
	{
		global $modSettings, $txt;

		$my_post = array();

		// Reading POSTed data directly from $_POST may causes serialization issues with array data
		// in the POST. Instead, read raw POST data from the input stream.
		$raw_post_data = file_get_contents('php://input');
		$raw_post_array = explode('&', $raw_post_data);

		// Process it
		foreach ($raw_post_array as $keyval)
		{
			$keyval = explode('=', $keyval);
			if (count($keyval) === 2)
			{
				$my_post[$keyval[0]] = urldecode($keyval[1]);
			}
		}

		// Put this to some default value.
		if (!isset($my_post['txn_type']))
		{
			$my_post['txn_type'] = '';
		}

		// Build the request string - starting with the minimum requirement.
		$this->requestString = 'cmd=_notify-validate';

		// Now my dear, add all the posted bits back in the exact order we got them
		foreach ($my_post as $key => $value)
		{
			$this->requestString .= '&' . $key . '=' . urlencode($value);
		}

		// Post IPN data back to PayPal to validate the IPN data is genuine
		$this->paidsubsTest = !empty($modSettings['paidsubs_test']);
		$this->_fetchReturnResponse();

		// If PayPal IPN does not return verified then give up...
		if (strcmp(trim($this->return_data), 'VERIFIED') !== 0)
		{
			exit;
		}

		// Now that we have received a VERIFIED response from PayPal, we perform some checks
		// before we assume that the IPN is legitimate. First check that this is intended for us.
		if (strtolower($modSettings['paypal_email']) !== strtolower($_POST['business']) && (empty($modSettings['paypal_additional_emails']) || !in_array(strtolower($_POST['business']), explode(',', strtolower($modSettings['paypal_additional_emails'])))))
		{
			exit;
		}

		// Is this a subscription - and if so is it a secondary payment that we need to process?
		if ($this->isSubscription() && (empty($_POST['item_number']) || strpos($_POST['item_number'], '+') === false))
		{
			// Calculate the subscription it relates to!
			$this->_findSubscription();
		}

		// Verify the currency!
		if (trim(strtolower($_POST['mc_currency'])) !== strtolower($modSettings['paid_currency_code']))
		{
			generateSubscriptionError(sprintf($txt['paypal_currency_unkown'], $_POST['mc_currency'], $modSettings['paid_currency_code']));
		}

		// Can't exist if it doesn't contain anything.
		if (empty($_POST['item_number']))
		{
			exit;
		}

		// Return the id_sub and id_member
		return explode('+', $_POST['item_number']);
	}

	/**
	 * Is this a refund?
	 *
	 * @return boolean
	 */
	public function isRefund()
	{
		return (($_POST['payment_status'] === 'Refunded' || $_POST['payment_status'] === 'Reversed' || $_POST['txn_type'] === 'Refunded' || ($_POST['txn_type'] === 'reversal' && $_POST['payment_status'] === 'Completed')));
	}

	/**
	 * Is this a subscription?
	 *
	 * @return boolean
	 */
	public function isSubscription()
	{
		return (substr($_POST['txn_type'], 0, 14) === 'subscr_payment' && $_POST['payment_status'] === 'Completed');
	}

	/**
	 * Is this a normal payment?
	 *
	 * @return boolean
	 */
	public function isPayment()
	{
		return ($_POST['payment_status'] === 'Completed' && $_POST['txn_type'] === 'web_accept');
	}

	/**
	 * Is this a cancellation?
	 *
	 * @return boolean
	 */
	public function isCancellation()
	{
		// subscr_cancel: This IPN response (txn_type) is sent only when the subscriber cancels his/her
		// current subscription or the merchant cancels the subscribers subscription. In this event according
		// to Paypal rules the subscr_eot (End of Term) IPN response is NEVER sent, and it is up to you to
		// keep the subscription of the subscriber active for remaining days of subscription should they cancel
		// their subscription in the middle of the subscription period.
		//
		// subscr_eot: This IPN response (txn_type) is sent ONLY when the subscription ends naturally/expires
		//
		return (substr($_POST['txn_type'], 0, 13) === 'subscr_cancel' || substr($_POST['txn_type'], 0, 10) === 'subscr_eot');
	}

	/**
	 * How much was paid?
	 *
	 * @return float
	 */
	public function getCost()
	{
		return (isset($_POST['tax']) ? $_POST['tax'] : 0) + $_POST['mc_gross'];
	}

	/**
	 * Record the transaction reference and exit
	 */
	public function close()
	{
		global $subscription_id;

		$db = database();

		// If it's a subscription record the reference.
		if ($_POST['txn_type'] == 'subscr_payment' && !empty($_POST['subscr_id']))
		{
			$db->query('', '
				UPDATE {db_prefix}log_subscribed
				SET vendor_ref = {string:vendor_ref}
				WHERE id_sublog = {int:current_subscription}',
				array(
					'current_subscription' => $subscription_id,
					'vendor_ref' => $_POST['subscr_id'],
				)
			);
		}

		exit();
	}

	/**
	 * A private function to find out the subscription details.
	 *
	 * @return false|null
	 */
	private function _findSubscription()
	{
		$db = database();

		// Assume we have this?
		if (empty($_POST['subscr_id']))
		{
			return false;
		}

		// Do we have this in the database?
		$request = $db->query('', '
			SELECT
				id_member, id_subscribe
			FROM {db_prefix}log_subscribed
			WHERE vendor_ref = {string:vendor_ref}
			LIMIT 1',
			array(
				'vendor_ref' => $_POST['subscr_id'],
			)
		);
		// No joy?
		if ($db->num_rows($request) == 0)
		{
			// Can we identify them by email?
			if (!empty($_POST['payer_email']))
			{
				$db->free_result($request);
				$request = $db->query('', '
					SELECT
						ls.id_member, ls.id_subscribe
					FROM {db_prefix}log_subscribed AS ls
						INNER JOIN {db_prefix}members AS mem ON (mem.id_member = ls.id_member)
					WHERE mem.email_address = {string:payer_email}
					LIMIT 1',
					array(
						'payer_email' => $_POST['payer_email'],
					)
				);
				if ($db->num_rows($request) === 0)
				{
					return false;
				}
			}
			else
			{
				return false;
			}
		}

		list ($member_id, $subscription_id) = $db->fetch_row($request);
		$_POST['item_number'] = $member_id . '+' . $subscription_id;
		$db->free_result($request);
	}

	/**
	 * Makes the request to paypal and returns the response
	 * Attempts curl first and if not available fsockopen
	 */
	private function _fetchReturnResponse()
	{
		// First we try cURL
		if (function_exists('curl_init') && $curl = curl_init(($this->paidsubsTest ? 'https://ipnpb.sandbox.' : 'https://ipnpb.') . 'paypal.com/cgi-bin/webscr'))
		{
			$this->_fetchReturnResponseCurl($curl);
		}
		// Otherwise good old HTTP.
		else
		{
			$this->_fetchReturnResponseFs();
		}
	}

	/**
	 * Get paypal response to our requestString using curl
	 *
	 * @param $curl resource
	 */
	private function _fetchReturnResponseCurl($curl)
	{
		// Set the post data.
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $this->requestString);

		// Set up the headers so paypal will accept the post
		curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 1);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);

		// Set TCP timeout to 30 seconds
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);

		// Set the http headers
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/x-www-form-urlencoded',
			'Content-Length: ' . strlen($this->requestString),
			'Host: ipnpb.' . ($this->paidsubsTest ? 'sandbox.' : '') . 'paypal.com',
			'Connection: close'
		));

		// The data returned as a string.
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

		// Fetch the data.
		$this->return_data = curl_exec($curl);

		// Close the session.
		curl_close($curl);
	}

	/**
	 * Get paypal response to our requestString using curl
	 */
	private function _fetchReturnResponseFs()
	{
		global $txt;

		// Setup the headers.
		$header = 'POST /cgi-bin/webscr HTTP/1.1' . "\r\n";
		$header .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n";
		$header .= 'Host: ipnpb.' . ($this->paidsubsTest ? 'sandbox.' : '') . 'paypal.com' . "\r\n";
		$header .= 'Content-Length: ' . strlen($this->requestString) . "\r\n";
		$header .= 'Connection: close' . "\r\n\r\n";

		// Open the connection.
		if ($this->paidsubsTest)
		{
			$fp = fsockopen('ssl://ipnpb.sandbox.paypal.com', 443, $errno, $errstr, 30);
		}
		else
		{
			$fp = fsockopen('ssl://ipnpb.paypal.com', 443, $errno, $errstr, 30);
		}

		// Did it work?
		if (!$fp)
		{
			generateSubscriptionError($txt['paypal_could_not_connect']);
		}

		// Put the data to the port.
		fputs($fp, $header . $this->requestString);

		// Get the data back...
		while (!feof($fp))
		{
			$this->return_data = fgets($fp, 1024);
			if (strcmp(trim($this->return_data), 'VERIFIED') === 0)
			{
				break;
			}
		}

		// Clean up.
		fclose($fp);
	}
}
