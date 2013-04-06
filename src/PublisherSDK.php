<?php

/**
 * Copyright 2013 Playgistics, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

class PublisherSDK extends SDKComponent {

	private $_token;
	private $_secret;
	private $_pubId;
	private $_sessionKey;
	protected $endpoint;

	public function __construct($pubId, $secret) {
		$this->_pubId = $pubId;
		$this->_secret = $secret;
		$this->_sessionKey = md5($this->_pubId.'-'.$this->_secret);	
	}

	public function __get($name) {
		switch ($name) {
			case 'pub':
			case 'publisher':
				$this->endpoint .= $this->ensureSlash($this->_pubId);
				return $this;
				break;
			case 'endpoint':
				break;
			default:
				$this->endpoint .= $this->ensureSlash($name);
				return $this;
				break;
		}
	}

	public function __call($name, $arguments) {
		switch ($name) {
			case 'get':
			case 'post':
			case 'delete':
				$firstArg = $this->safeArrayGet($arguments, 0);
				
				if (is_string($firstArg)) {
					$this->endpoint = $firstArg;
					$params = $this->safeArrayGet($arguments, 1);
					return $this->makeGraphRequest($params, $name);
				}
				else {
					return $this->makeGraphRequest($firstArg, $name);
				}
				break;
		}
	}

	public function getToken() {
		if (!$this->_token && !($this->_token = $this->getTokenFromSession())) {
			$this->_token = $this->getTokenFromServer();
		}
		return $this->_token;
	}	

	private function makeGraphRequest($params, $method) {
		$params['access_token'] = $this->getToken();
		$resp = $this->handleResponse(GraphRequest::create($this->endpoint, $params, $method)->getResponse());
		$this->endpoint = null;
		return $resp;
	}

	private function getTokenFromServer() {
		return $this->safeArrayGet(
				$this->handleResponse(
					GraphRequest::create('oauth/access_token',
					array(
						'client_id'=>$this->_pubId,
						'client_secret'=>$this->_secret,
						'grant_type'=>'publisher_credentials'
					))->getResponse())->data,'access_token');
	}

	private function handleResponse($response) {
		if ($response->error) {
			throw $response->error;
		}
		return $response;
	}

	private function getTokenFromSession() {
		return $this->safeArrayGet($_SESSION, $this->_sessionKey);
	}

}

class GraphResponse extends SDKComponent{

	public $request;
	public $response;
	public $error;
	public $data;
	public $paging;
	public $metadata;

	public function __construct($response, $request) {
		$this->request = $request;
		$this->response = $response;
		if (isset($response['error'])) {
			$this->error = new SDKException(
					'Error in request to '.$this->request->effectiveUrl.': '.$this->safeArrayGet($response['error'], 'message'), 
					$this->safeArrayGet($response['error'], 'code'));
		}
		else {
			$this->data = $this->safeArrayGet($response, 'data', $response);
			$this->paging = $this->safeArrayGet($response, 'paging');
			$this->metadata = $this->safeArrayGet($response, 'metadata');
		}
	}

	public function next() {
		return $this->iterate($this->safeArrayGet($this->paging, 'next'));
	}

	public function previous() {
		return $this->iterate($this->safeArrayGet($this->paging, 'previous'));
	}

	private function iterate($url) {
		if ($url) {
			list($endpoint, $params) = $this->parseUrl($url);
			return GraphRequest::create($endpoint, $params)->getResponse();
		}
	}

	private function parseUrl($url) {
		$endHost = strpos($url, '/',8);
		$vals = explode('?',  substr($url, $endHost));
		parse_str(join('?', array_slice($vals, 1)),$params);
		return array($this->safeArrayGet($vals, 0),$params);
	}

}

class GraphRequest extends SDKComponent {

	const VERSION = '1.0';

	public static $CURL_OPTS = array(
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_USERAGENT => 'goplay-php-1.0',
	);	

	public $endpoint;
	public $url;
	public $params;
	public $effectiveUrl;
	public static $graph = 'https://graph.goplay.com';

	public function __construct($endpoint, $params, $method='get') {
		$this->endpoint = $this->ensureSlash($endpoint);
		$this->url = self::$graph.$this->endpoint;
		$this->params = array_merge($params, array('method'=>$method));
	}

	public function getResponse() {
		$result = $this->run();
		if (!($data = json_decode($result, true))) {
			parse_str($result, $data);
		}
		return new GraphResponse($data, $this);
	}

	protected function run($ch=null) {
		if (!$ch) {
			$ch = curl_init();
		}

		$opts = self::$CURL_OPTS;
		$opts[CURLOPT_POSTFIELDS] = http_build_query($this->params, null, '&');
		$opts[CURLOPT_URL] = $this->url;

		$this->effectiveUrl = $this->url.'?'.$opts[CURLOPT_POSTFIELDS];

		// disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
		// for 2 seconds if the server does not support this header.
		if (isset($opts[CURLOPT_HTTPHEADER])) {
			$existing_headers = $opts[CURLOPT_HTTPHEADER];
			$existing_headers[] = 'Expect:';
			$opts[CURLOPT_HTTPHEADER] = $existing_headers;
		} else {
			$opts[CURLOPT_HTTPHEADER] = array('Expect:');
		}

		curl_setopt_array($ch, $opts);
		$result = curl_exec($ch);

		if (curl_errno($ch) == 60) { // CURLE_SSL_CACERT
			self::errorLog('Invalid or no certificate authority found, ' .
				'using bundled information');
			curl_setopt($ch, CURLOPT_CAINFO,
				dirname(__FILE__) . '/ca_chain_bundle.cert');
			$result = curl_exec($ch);
		}

		if ($result === false) {
			$e = new SDKException(curl_error($ch), curl_errno($ch), $previous);
			curl_close($ch);
			throw $e;
		}
		curl_close($ch);
		return $result;
	}

}

class SDKComponent {

	public function safeArrayGet($array, $key, $default = null) {
		return isset($array[$key]) ? $array[$key] : $default;
	}

	protected function ensureSlash($string) {
		return empty($string) ? '' :
			($string[0]=='/' ? $string : '/'.$string);
	}

	public static function create() {
		$ref = new ReflectionClass(get_called_class());
		return $ref->newInstanceArgs(func_get_args());
	}

	protected static function errorLog($msg) {
		if (php_sapi_name() != 'cli') {
			error_log($msg);
		}
	}

}

class SDKException extends Exception {

}