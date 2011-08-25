<?php

/* XRC API Class for PHP 5
 *  based on Everyone.net XRC API Document Version 1.0.30
 * By Christopher Blay - da.blayde@gmail.com
 *  (C) 2009 PMT - Licensed under GNU GPL v3
 *
 * Here is a list of helpful things to know:
 *  requires PHP cURL extension to be installed and available
 *  create a new object with the clientID and password
 *  set your domain clientID in $cid for easy access
 *  set $debug to true for useful info
 *  call test() to see how it encodes/decodes stuff
 *  call call() with method name and then arguments (if any) and the result should be the decoded output stream
 *  check $error after a call() to see if anything went wrong
 *  set $crtLoc to the location of your xrc.everyone.net SSL certificate or false to use http
 *  set $logFile to a file to append error messages to or false to disable
 *
 * For convenience, I've place the xrc.everyone.net SSL certificate below. You'll have to copy it to it's own file

-----BEGIN CERTIFICATE-----
MIICYjCCAcsCBD6xsXMwDQYJKoZIhvcNAQEEBQAweDELMAkGA1UEBhMCVVMxCzAJ
BgNVBAgTAkNBMREwDwYDVQQHEwhTYW4gSm9zZTEVMBMGA1UEChMMRXZlcnlvbmUu
bmV0MRcwFQYDVQQLEw5FbWFpbCBTZXJ2aWNlczEZMBcGA1UEAxMQeHJjLmV2ZXJ5
b25lLm5ldDAeFw0wMzA1MDEyMzQ0NTFaFw0xMzA0MjgyMzQ0NTFaMHgxCzAJBgNV
BAYTAlVTMQswCQYDVQQIEwJDQTERMA8GA1UEBxMIU2FuIEpvc2UxFTATBgNVBAoT
DEV2ZXJ5b25lLm5ldDEXMBUGA1UECxMORW1haWwgU2VydmljZXMxGTAXBgNVBAMT
EHhyYy5ldmVyeW9uZS5uZXQwgZ8wDQYJKoZIhvcNAQEBBQADgY0AMIGJAoGBANaL
GjNIbZ3sqRqBcepgmq8S6MR3t1kNG5zmpDwT6Pvnpsx7Fwsi9bvq9tB+b8xtFVga
3iNiVk6lYV+jPVt7GVuZwqHDTjChBch/xBd4PkMd40e5zCz8ah06QgZjH7R27ovV
WPxv2d0ZyZUGUVzNFaxRQaUy3XkaLQCmo9cBs/tNAgMBAAEwDQYJKoZIhvcNAQEE
BQADgYEAjPylsMo/bIkIwag2Tb3vJluKjGYXNGmudNeUgYchqpu8Avv/OCwwO32n
sPbEXD5te/quBXDnz5arQ8q2+89dUL5J/3gIMVQG/M2AmI5TnG0HWpkw7Nkcw0iQ
jrPUYAzYoCrXKfY4no+8o8CFbMRszpkYAbR0o5cLPyZIsC6URRQ=
-----END CERTIFICATE-----

 */

class XRC{

	private $clientId;
	private $cliendPw;
	private $xrcVersion = '1';
	private $postUrl = 'https://xrc.everyone.net/ccc/xrc';
	public $error = false;
	public $debug = false;
	public $cid = false;
	public $logFile = false;
	public $crtLoc = 'xrc.everyone.net.crt';

	public function __construct($id = null, $pw = null){
		if($id == null || $pw == null)
			die('Constructor arguments should be the XRC clientID and password');
		$this->clientId = $id;
		$this->clientPw = $pw;
	}

	private function freakOut($str, $var){
		$this->error = true;
		$this->debugMsg($str, $var);
		$str .= "\n" . var_export($var, true);
		if($this->logFile){
			if($fh = fopen($this->logFile, 'a')){
				fwrite($fh, date('[dM H:i:s] ') . $str . "\n\n");
				fclose($fh);
	}	}	}

	private function debugMsg($str, $var){
		if(!$this->debug) return;
		echo '<pre><fieldset><legend>[DEBUG] ' . $str . '</legend>';
		var_dump($var);
		echo '</fieldset></pre>';
	}

	private function encode($val){
		if(is_array($val)){
			if(count($val) == 1 && isset($val['__bytes'])) // bytes
				return '{' . strlen($val['__bytes']) . '}' . $val['__bytes'];
			$ret = array();
			if(isset($val['__complex'])){ // complex
				$complex = ':' . $val['__complex'];
				unset($val['__complex']);
				foreach($val as $k => $v){
					$ret[] = $k;
					$ret[] = $this->encode($v);
				}
			}else{ // list
				$complex = '';
				foreach($val as $v)
					$ret[] = $this->encode($v);
			}
			array_unshift($ret, '(');
			$ret[] = ')';
			return $complex . implode(' ', $ret);
		}
		if(is_bool($val)) // bool
			return $val ? '/T' : '/F';
		if(is_string($val)) // string
			return '"' . addcslashes($val, '\\"') . '"';
		if(preg_match('/^-?\d+$/', $val)){
			if(is_int($val)) // int
				return $val;
			if(is_float($val)) // long
				return $val . 'L';
		}
		if(is_null($val)) // null
			return '/NULL';
		$this->freakOut('Encode Error', $val);
	}

	private function reduce(&$val, $mat){
		$val = ltrim(substr($val, strlen($mat[0])));
		return true;
	}

	private function decode(&$val){
		if(preg_match('/^(-?\d+)(\s|$)/', $val, $mat) && $this->reduce($val, $mat)) // int
			return (int) $mat[1];
		if(preg_match('/^(-?\d+)L(\s|$)/', $val, $mat) && $this->reduce($val, $mat)) // long
			return (float) $mat[1];
		if(preg_match('/^"((\\\\\\\)*|.*?[^\\\](\\\\\\\)*)"(\s|$)/s', $val, $mat) && $this->reduce($val, $mat))
			return str_replace(array('\\\\', '\"'), array('\\', '"'), $mat[1]);
		if(preg_match('/^\\/NULL(\s|$)/', $val, $mat) && $this->reduce($val, $mat)) // null
			return null;
		if(preg_match('/^\\/(T|F)(\s|$)/', $val, $mat) && $this->reduce($val, $mat)) // bool
			return $mat[1] == 'T' ? true : false;
		if(preg_match('/^\{(\d+)\}/', $val, $mat)){ // bytes
			$return = array('__bytes' => substr($val, strlen($mat[0]), $mat[1]));
			$val = ltrim(substr($val, strlen($mat[0]) + $mat[1]));
			return $return;
		}
		if(preg_match('/^\(\s/', $val, $mat) && $this->reduce($val, $mat)){ // list
			$list = array();
			while(!$this->error && !(preg_match('/^\)(\s|$)/', $val, $mat) && $this->reduce($val, $mat)))
				$list[] = $this->decode($val);
			return $list;
		}
		if(preg_match('/^:([A-Za-z]+)\(\s/', $val, $mat) && $this->reduce($val, $mat)){ // complex
			$complex = array('__complex' => $mat[1]);
			while(!$this->error && !(preg_match('/^\)(\s|$)/', $val, $mat) && $this->reduce($val, $mat)))
				if(preg_match('/^([A-Za-z]+)\s/', $val, $mat) && $this->reduce($val, $mat))
					$field = $mat[1];
				else
					$complex[$field] = $this->decode($val);
			return $complex;
		}
		$this->freakOut('Decode Error', $val);
	}

	public function call(){ // first method name, then method arguments (if any)

		// get stuff from args
		$args = func_get_args();
		$this->debugMsg('Starting Call', $args);
		if(!count($args))
			return false;
		$name = array_shift($args);

		// prepare POST data
		$meta = array(
			'version' => $this->xrcVersion,
			'clientID' => $this->clientId,
			'password' => $this->clientPw
		);
		$data = '';
		foreach($meta as $k => $v)
			$data .= $k . ': ' . $v . "\n";
		$this->debugMsg('Starting to encode', $args);
		$args = $this->encode($args);
		if($this->error) return;
		$this->debugMsg('Encoding result', $args);
		$data .= "\n" . $name . ' ' . $args;

		// prepare/execute cURL request
		if(!$this->crtLoc)
			$this->postUrl = str_replace('https', 'http', $this->postUrl);
		$ch = curl_init($this->postUrl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-eon-xrc-request'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		if($this->crtLoc){
			curl_setopt($ch, CURLOPT_CAINFO, $this->crtLoc);
			curl_setopt($ch, CURLOPT_SSLVERSION, 3);
		}else
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$result = curl_exec($ch);
		if($result === false)
			$this->freakOut('cURL Error', curl_error($ch));
		$result = explode("\n\n", preg_replace("/\r\n\r\n/", "\n\n", $result, 1), 3); // results have the HTTP headers separated with \r\n\r\n but the metadata separated with \n\n so we replace the first \r\n\r\n with \n\n and then explode by \n\n but limit to 3 - this preserves any \r stuff that may be in a bytes value

		// check headers
		if(!strpos($result[0], '200 OK'))
			return $this->freakOut('Not HTTP Status 200', $result);

		// parse metadata
		$temp = explode("\n", $result[1]);
		$meta = array();
		foreach($temp as $v){
			$v = explode(':', $v);
			$meta[trim($v[0])] = trim($v[1]);
		}

		// check metadata
		if($meta['version'] != $this->xrcVersion)
			return $this->freakOut('Incorrect Version', $meta);
		if($meta['status'] != 0)
			return $this->freakOut('XRC Status Not 0', $meta);

		// parse/return output stream
		$os = ltrim($result[2]);
		$this->debugMsg('Starting to decode', $os);
		$os = $this->decode($os);
		if($this->error) return;
		$this->debugMsg('Decoding result', $os);
		return $os;

	}

	public function test(){

		$this->debug = true;

		// Test Values
		$arr = array(
			1234,
			array(
				'__complex' => 'FakeComplexObject',
				'BoolTrue' => true,
				'BoolFalse' => false,
				'String' => "This is a string with \n, \r, \t, \", and \\",
				'Null' => null,
				'Long' => -2147483649,
				'Bytes' => array('__bytes' => 'Abc123 ')
			)
		);
		$str = '( 1234 :FakeComplexObject( BoolTrue /T BoolFalse /F String "' . "This is a string with \n, \r, \t, \\\", and \\\\" . '" Null /NULL Long -2147483649L Bytes {7}Abc123  ) )';

		// Encode Test
		$a = $this->encode($arr);
		if($this->error) die();
		$r = ($a == $str);
		$this->debugMsg('Encode Test Result', $r);
		$this->debugMsg('Encode Test Attempt', $a);
		if(!$r)
			$this->debugMsg('Encode Test Key', $str);

		// Decode Test
		$a = $this->decode($str);
		if($this->error) die();
		$r = ($a == $arr);
		$this->debugMsg('Decode Test Result', $r);
		$this->debugMsg('Decode Test Attempt', $a);
		if(!$r)
			$this->debugMsg('Decode Test Key', $arr);

		// Network Test
		$this->call('noop');

	}

}

?>
