<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing sending Sms through Http OAuth2
 *
 * @package  Sms
 * @since    5.16
 * 
 * @todo make a generic http rest
 * 
 */
class Billrun_Sms_Httpoauth2 extends Billrun_Sms_Abstract {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'httpouth2';

	/**
	 * url for the REST API
	 * @var string
	 */
	protected $url;

	/**
	 * login path
	 * @var string
	 */
	protected $loginPath;

	/**
	 * logout path
	 * @var string
	 */
	protected $logoutPath;

	/**
	 * send sms path
	 * @var string
	 */
	protected $sendSmsPath;

	/**
	 * the from (number or name) that the sms will be sent on behalf of
	 * @var string
	 */
	protected $from;

	/**
	 * authentication user
	 * @var string
	 */
	protected $user;

	/**
	 * authentication password
	 * @var string
	 */
	protected $password;

	/**
	 * authentication bearer
	 * @var string
	 */
	protected $bearer;

	/**
	 * authentication bearer expiration
	 * @var int
	 */
	protected $bearerExpiration;

	/**
	 * array of client options
	 * 
	 * @var array
	 */
	protected $clientOptions = array();

	/**
	 * array of client options
	 * 
	 * @var array
	 */
	protected $sendSmsOptions = array();

	/**
	 * http headers to send
	 * 
	 * @var array
	 */
	protected $httpHeaders = array();

	/**
	 * http request method type
	 * 
	 * @var string
	 */
	protected $httpRequestMethod = Zend_Http_Client::POST;

	/**
	 * http request method type
	 * 
	 * @var string
	 */
	protected $bearerField = 'token';

	/**
	 * destination field format; array or string; string default
	 * 
	 * @var string
	 */
	protected $destinationFieldFormat = 'string';

	/**
	 * destination field name; to default
	 * 
	 * @var string
	 */
	protected $destinationField = 'to';

	/**
	 * from field name; from default
	 * 
	 * @var string
	 */
	protected $fromField = 'from';

	/**
	 * from field name; message default
	 * 
	 * @var string
	 */
	protected $messageField = 'message';

	/**
	 * user field name; login default
	 * 
	 * @var string
	 */
	protected $userField = 'login';

	/**
	 * user field name; password default
	 * 
	 * @var string
	 */
	protected $passwordField = 'password';

	/**
	 * the field to that defined the return sms ack in the http response
	 * 
	 * @var string
	 */
	protected $returnResultCodeField = 'returnCode';

	/**
	 * the token bearer expiration duration
	 * 
	 * @var int
	 */
	protected $bearerExpirationDuration = 3540; // 1 hour minus buffer

	/**
	 * socket time out in milliseconds
	 * 
	 * @var int
	 */
	protected $httpTimeout = 10;

	public function getFrom() {
		return $this->from;
	}

	public function setFrom($from) {
		$this->setFrom($from);
	}

	protected function init($params) {
		parent::init($params);
		$this->initHttpMethod();
	}

	/**
	 * on class destruction close logout
	 */
	public function __destruct() {
		if ($this->bearer && !empty($this->logoutPath)) {
			$this->logout();
		}
	}

	protected function getHeadersWithAuthorization() {
		$authHeader = array(
			'Authorization' => 'bearer ' . $this->bearer,
		);
		return array_merge($authHeader, $this->httpHeaders);
	}

	public function logout() {
		try {
			$output = Billrun_Util::sendRequest($this->url . $this->logoutPath, array(), $this->httpRequestMethod, $this->httpHeaders, $this->httpTimeout);
			$this->bearer = null;
			Billrun_Factory::log("Send Http OAuth2 SMS logout http response: " . $output, Zend_Log::DEBUG);
		} catch (Throwable $th) {
			Billrun_Factory::log('Send Http OAuth2 SMS logout: got throwable. code: ' . $th->getCode() . ', message: ' . $th->getMessage(), Zend_Log::WARN);
		} catch (Exception $ex) {
			Billrun_Factory::log('Send Http OAuth2 SMS logout: got exception. code: ' . $ex->getCode() . ', message: ' . $ex->getMessage(), Zend_Log::WARN);
		}
	}

	protected function parseData($data) {
		return json_encode($data);
	}

	public function login() {
		if (!empty($this->bearer) && $this->bearerExpiration > time()) {
			return $this->bearer;
		}

		$data = array(
			$this->userField => $this->user,
			$this->passwordField => $this->password,
		);

		$output = billrun_util::sendRequest($this->url . $this->loginPath, $this->parseData($data), $this->httpRequestMethod, $this->httpHeaders, $this->httpTimeout);
		Billrun_Factory::log("Send Http OAuth2 SMS login http response: " . $output, Zend_Log::DEBUG);
		$ret = json_decode($output, true);

		$this->bearer = Billrun_Util::getIn($ret, $this->bearerField) ?? false;

		$this->bearerExpiration = time() + $this->bearerExpirationDuration;

		return $this->bearer;
	}

	/**
	 * see parent::send
	 * 
	 * @return mixed msg id if success, false on failure
	 */
	public function send() {
		Billrun_Factory::log('Sending Http OAuth2 SMS to: ' . $this->to . ' content: ' . $this->body, Zend_Log::DEBUG);

		try {
			if (empty($this->body)) {
				Billrun_Factory::log('SMS Http OAuth2: need to set sms body', Zend_Log::NOTICE);
				return false;
			}

			if (empty($this->to)) {
				Billrun_Factory::log('SMS Http OAuth2: need to set sms destination (to)', Zend_Log::NOTICE);
				return false;
			}

			if ($this->destinationFieldFormat == 'array' && !is_array($this->to)) {
				$destination = array($this->to);
			} else if ($this->destinationFieldFormat == 'string' && is_array($this->to)) {
				$destination = implode(',', $this->to);
			} else if ($this->destinationFieldFormat == 'string') {
				$destination = (string) $this->to;
			} else {
				$destination = $this->to;
			}

			if (!$this->login()) {
				return false;
			}

			$data = array(
				$this->fromField => $this->from,
				$this->destinationField => $destination,
				$this->messageField => $this->body,
			);

			$unifiedData = array_merge($data, $this->sendSmsOptions);

			$output = billrun_util::sendRequest($this->url . $this->sendSmsPath, $this->parseData($unifiedData), $this->httpRequestMethod, $this->getHeadersWithAuthorization(), $this->httpTimeout);

			Billrun_Factory::log("Send Http OAuth2 SMS send http response: " . $output, Zend_Log::DEBUG);
			$retArray = json_decode($output, true);

			$ret = Billrun_Util::getIn($retArray, $this->returnResultCodeField);
		} catch (Throwable $th) {
			Billrun_Factory::log('Send Http OAuth2 SMS send: got throwable. code: ' . $th->getCode() . ', message: ' . $th->getMessage(), Zend_Log::WARN);
			$ret = false;
		} catch (Exception $ex) {
			Billrun_Factory::log('Send Http OAuth2 SMS send: got exception. code: ' . $ex->getCode() . ', message: ' . $ex->getMessage(), Zend_Log::WARN);
			$ret = false;
		}

		return $ret;
	}

	protected function initHttpMethod() {
		if (empty($this->clientOptions['httpRequestMethod'])) {
			return;
		}
		return $this->getClassConstant('Zend_Http_Client', $this->clientOptions['httpRequestMethod']);
	}

	/**
	 * method to get variable from class constants; on some case the variable input is the value itself
	 * 
	 * @param string $class
	 * @param mixed $var
	 * 
	 * @return the constant value
	 */
	protected function getClassConstant($class, $var) {
		if (is_numeric($var)) {
			return (int) $var;
		}

		if (is_bool($var)) {
			return (bool) $var;
		}

		return constant($class . '::' . $var);
	}

}