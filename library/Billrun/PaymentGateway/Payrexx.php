<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2022 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

use Payrexx\Models\Request\SignatureCheck;
use Payrexx\Models\Request\Transaction;
use Payrexx\Payrexx;
use Payrexx\PayrexxException;

/**
 * This class represents a payment gateway
 *
 * @since    5.13
 */
class Billrun_PaymentGateway_Payrexx extends Billrun_PaymentGateway {

	const DEFAULT_CURRENCY = 'CHF';
	const DEFAULT_PAYMENT_METHODS = ['visa', 'mastercard'];
	const DEFAULT_AMOUNT = 0.5;

	/**
	 * @inheritDoc
	 */
	protected $omnipayName = "Payrexx";

	protected $billrunName = "Payrexx";

	protected $pendingCodes = '/^(waiting|refund_pending)$/';
	protected $completionCodes = '/^(authorized|confirmed|reserved|refunded|partially-refunded)/';
	protected $rejectionCodes = '/^(cancelled|declined)$/';

	public function __construct() {
		parent::__construct();
		$credentials = $this->getGatewayCredentials();
		$this->omnipayGateway->setApiKey($credentials['instance_api_secret']);
		$this->omnipayGateway->setInstance($credentials['instance_name']);
	}

	public function getDefaultParameters() {
		$params = array("instance_name", "instance_api_secret");
		return $this->rearrangeParametres($params);
	}

	public function getSecretFields() {
		return array('instance_api_secret');
	}

	/**
	 * @inheritDoc
	 */
	public function authenticateCredentials($params) {
		try {
			$payrexx = new Payrexx($params['instance_name'], $params['instance_api_secret']);

			$signatureCheck = new SignatureCheck();
			$payrexx->getOne($signatureCheck);
		} catch (PayrexxException $e) {
			Billrun_Factory::log('Payrexx credentials validation failed with message: ' . $e->getMessage(), Zend_Log::DEBUG);
			return false;
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function needRequestForToken() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getToken($aid, $returnUrl, $okPage, $failPage, $singlePaymentParams, $options, $maxTries = 10) {
		$account = Billrun_Factory::account();
		$account->loadAccountForQuery(array('aid' => (int) $aid));

		$this->transactionId  = Billrun_Util::generateRandomNum();

		if (!empty($singlePaymentParams)) {
			$this->saveDetails['charge'] = 1;
		}

		$request = $this->omnipayGateway->purchase([
			'amount' => $singlePaymentParams['amount'] ?? self::DEFAULT_AMOUNT,
			'currency' => Billrun_Factory::config()->getConfigValue('pricing.currency', self::DEFAULT_CURRENCY),
			'preAuthorization' => 1, // indicate tokenization procedure
			'pm' => self::DEFAULT_PAYMENT_METHODS,
			'forename' => $account->firstname,
			'surname' => $account->lastname,
			'email' => $account->email,
			'successRedirectUrl' => $this->adjustRedirectUrl($okPage, $this->transactionId),
			'failedRedirectUrl' => $this->adjustRedirectUrl($failPage, $this->transactionId),
			'buttonText' => 'Add Card'
		]);

		return $request->send();
	}

	protected function adjustRedirectUrl($url, $txId): string {

		$params = http_build_query([
			'name' => $this->billrunName,
			'txId' => $txId
		]);

		return $url . (strpos($url, '?') !== false ? '&' : '?') . $params;
	}

	/**
	 * @inheritDoc
	 */
	protected function isUrlRedirect() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function isHtmlRedirect() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function updateRedirectUrl($result) {
		$this->redirectUrl = $result->getRedirectUrl();
	}

	/**
	 * @inheritDoc
	 */
	function updateSessionTransactionId($result) {
		$this->saveDetails['ref'] = $result->getTransactionReference();
	}

	protected function signalStartingProcess($aid, $timestamp) {
		parent::signalStartingProcess($aid, $timestamp);

		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		$query = array("name" => $this->billrunName, "tx" => (string) $this->transactionId, "stamp" => md5($timestamp . $this->transactionId), "aid" => (int)$aid);

		$paymentRow = $paymentColl->query($query)->cursor()->sort(array('t' => -1))->limit(1)->current();
		if ($paymentRow->isEmpty()) {
			return;
		}

		$paymentRow['ref'] = $this->saveDetails['ref'];
		$paymentRow['charge'] = $this->saveDetails['charge'];

		$paymentColl->updateEntity($paymentRow);
	}

	/**
	 * @inheritDoc
	 */
	public function getTransactionIdName() {
		return 'txId';
	}

	/**
	 * @inheritDoc
	 */
	public function handleOkPageData($txId) {
		return true;
	}

	/**
	 * Saving Details to Subscribers collection and redirect to our success page or the merchant page if supplied.
	 *
	 * @param $txId
	 * @param $additionalParams
	 * @return array
	 * @throws PayrexxException
	 */
	public function saveTransactionDetails($txId, $additionalParams) {
		$aid = $this->getAidFromProxy($txId);
		$tenantUrl = $this->getTenantReturnUrl($aid);
		$this->updateReturnUrlOnEror($tenantUrl);

		$paymentColl = Billrun_Factory::db()->creditproxyCollection();
		$query = array("name" => $this->billrunName, "tx" => (string) $txId);
		$paymentRow = $paymentColl->query($query)->cursor()->current();

		$request = $this->omnipayGateway->completePurchase(['transactionReference' => $paymentRow['ref']]);

		$tokenizationResult = $request->send();

		if (($cardDetails = $this->getCardDetails($tokenizationResult)) === FALSE) {
			Billrun_Factory::log("Error: Redirecting to " . $this->returnUrlOnError, Zend_Log::ALERT);
			throw new Exception('Operation Failed. Try Again...');
		}

		$this->saveDetails['aid'] = $aid; // for validatePaymentProcess(), savePaymentGateway() and paySinglePayment()
		if (!$this->validatePaymentProcess($txId)) {
			Billrun_Factory::log("Error: Redirecting to " . $this->returnUrlOnError . ' message: Too much time passed', Zend_Log::ALERT);
			throw new Exception('Too much time passed');
		}

		$this->saveDetails['card_token'] = $cardDetails['card_token'];
		$this->saveDetails['four_digits'] = $cardDetails['four_digits'];
		$this->saveDetails['card_expiration'] = $cardDetails['expiration_date'];
		$this->savePaymentGateway();

		if ($paymentRow['charge']) {
			$amountCents = $tokenizationResult->getData()->getAmount(); // in cents
			$paymentResult = $this->chargeCard($cardDetails['card_token'], $amountCents);
			$paymentDetails = $this->getResponseDetails($paymentResult);

			$this->transactionId = $paymentDetails['payment_identifier']; // for paySinglePayment()
			$this->saveDetails['card_token'] = $cardDetails['card_token']; // for paySinglePayment()
			$this->paySinglePayment($cardDetails + $paymentDetails);
		}

		return array(
			'tenantUrl' => $tenantUrl,
			'creditCard' => $cardDetails['four_digits'],
			'expirationDate' => $cardDetails['expiration_date']
		);
	}

	/**
	 * Query the response to getting needed details.
	 *
	 * @param \Payrexx\Models\Response\Transaction $result
	 * @return array
	 */
	protected function getResponseDetails($result) {
		$amount = $this->convertReceivedAmount($result->getAmount());

		$payrexx = $this->getPayrexxClient();
		$transaction = new Transaction();
		$transaction->setId($result->getId());

		$transactionResponse = $payrexx->getOne($transaction);

		$payrexxFee =  $this->convertReceivedAmount($transactionResponse->getPayrexxFee());

		return [
			'payment_identifier' => (string) $result->getId(),
			'transferred_amount' => $amount,
			'fee' => $payrexxFee,
			'transaction_status' => $result->getStatus()
		];
	}

	/**
	 * @param \Omnipay\Payrexx\Message\Response\CompletePurchaseResponse $result
	 * @return array
	 * @throws Exception
	 */
	protected function getCardDetails($result) {
		$gatewayInfo = $result->getData();
		if (!isset($gatewayInfo->getInvoices()[0]["transactions"][0])) {
			throw new Exception('Wrong response from payment gateway');
		}
		$transaction = $gatewayInfo->getInvoices()[0]["transactions"][0];

		$lastDigits = !empty($transaction['payment']['cardNumber'])
			? substr($transaction['payment']['cardNumber'], -4) : '';

		return [
			'card_token' => (string) $transaction['id'],
			'four_digits' => (string) $lastDigits,
			'expiration_date' => (string) $transaction['payment']['expiry']
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function buildSetQuery() {
		return array(
			'active' => array(
				'name' => $this->billrunName,
				'card_token' => (string) $this->saveDetails['card_token'],
				'card_expiration' => (string) $this->saveDetails['card_expiration'],
				'transaction_exhausted' => true,
				'generate_token_time' => new MongoDate(time()),
				'four_digits' => (string) $this->saveDetails['four_digits'],
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function validateStructureForCharge($structure) {
		return !empty($structure['card_token']);
	}

	/**
	 * @inheritDoc
	 */
	protected function pay($gatewayDetails, $addonData) {
		$response = $this->chargeCard(
			$gatewayDetails['card_token'],
			$this->convertAmountToSend($gatewayDetails['amount'])
		);

		$this->transactionId = $response->getId(); // for outside use

		return [
			'status' => $response->getStatus(),
			'additional_params' => []
		];
	}

	/**
	 * @param array $gatewayDetails
	 * @return \Payrexx\Models\Response\Transaction
	 * @throws PayrexxException
	 */
	private function chargeCard($cardToken, $amountCents) {
		$transaction = new Transaction();
		$transaction->setId($cardToken);
		$transaction->setAmount($amountCents); // convert to cents
		$response = $this->getPayrexxClient()->charge($transaction);
		return $response;
	}

	/**
	 * @return Payrexx
	 * @throws PayrexxException
	 */
	private function getPayrexxClient(): Payrexx {
		$credentials = $this->getGatewayCredentials();

		$payrexx = new Payrexx(
			$credentials['instance_name'],
			$credentials['instance_api_secret'],
			'',
			\Payrexx\Communicator::API_URL_BASE_DOMAIN,
			'1.1'
		);
		return $payrexx;
	}

	/**
	 * @inheritDoc
	 */
	protected function convertAmountToSend($amount) {
		$amount = round($amount, 2);
		return $amount * 100;
	}

	protected function convertReceivedAmount($amount) {
		return $amount / 100;
	}

	/**
	 * @inheritDoc
	 */
	protected function buildPostArray($aid, $returnUrl, $okPage, $failPage) {
		// not applicable for Payrexx client
		return [];
	}

	/**
	 * @inheritDoc
	 */
	protected function buildTransactionPost($txId, $additionalParams) {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function verifyPending($txId) {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function hasPendingStatus() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function isNeedAdjustingRequest() {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function handleTokenRequestError($response, $params) {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	protected function buildSinglePaymentArray($params, $options) {
		// not applicable for Payrexx client
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function createRecurringBillingProfile($aid, $gatewayDetails, $params = []) {
		return '';
	}

}