<?php

/**
 * Created by 七月.
 * Author: 七月
 * 微信公号：小楼昨夜又秋风
 * 知乎ID: 七月在夏天
 * Date: 2017/2/26
 * Time: 16:02
 */

namespace app\api\service;

use app\api\service\PayService;
use think\Loader;
use think\Db;

Loader::import('WxPay.WxPayConfig');
class PaymentService
{
	public $payId;
	public $uniacid;
	public $price;

	public function __construct($payId = '')
	{
		/*
		$this->payId = $payId;
		$PayService = new PayService($this->payId);
		$data = $PayService->checkOrderValid();// 验证订单是否已支付,验证金额
		$this->uniacid = $data['uniacid'];
		$this->price = $data['totalPrice'];
		*/
	}

	public function wechatPay()
	{
		$params = $this->getParams();
		$params['notify_url'] = SITE_ROOT . '/api/pay/wechatNotify';
		$config = $this->getConfig();
		return $this->wechat_app($params, $config);
	}

	public function aliPay()
	{
		$params = $this->getParams();
		$params['notify_url'] = SITE_ROOT . '/api/pay/aliNotify';
		$config = $this->getConfig();
		return $this->ali_app($params, $config);
	}

	public function getParams()
	{
		$order = $this->getOrder();
		if (count($order) == 1) {
			$title = $order[0]['shop_name'];
		}
		if (count($order) > 1) {
			$title = "合并付款";
		}
		return array(
			"goodsName" => $title,
			"payPrice" => $this->price,
			"orderId" => $this->payId,
		);
	}

	public function getOrder()
	{
		return Db::name('order')->where('payid', $this->payId)->select()->toArray();
	}

	public function getConfig()
	{
		return Db::name('config')->where('uniacid', $this->uniacid)->find();
	}

	// ali支付参数
	public function ali_app($params, $aliCfg)
	{

		require_once EXTEND_PATH . 'alipay/AopSdk.php';

		require_once EXTEND_PATH . 'alipay/aop/AopClient.php';
		require_once EXTEND_PATH . 'alipay/aop/request/AlipayTradeAppPayRequest.php';

		//读取配置信息
		$aop = new \AopClient;
		$aop->gatewayUrl = "https://openapi.alipay.com/gateway.do";
		$aop->format = "json";
		$aop->charset = "UTF-8";
		$aop->signType = "RSA2";
		$aop->appId = $aliCfg['alipay_appid'];
		$aop->rsaPrivateKey = $aliCfg['alipay_privateKey'];
		$aop->alipayrsaPublicKey = $aliCfg['alipay_publicKey'];
		$aop->app_cert_sn = $aliCfg['app_cert'];
		$aop->alipay_root_cert_sn = $aliCfg['root_cert'];

		$request = new \AlipayTradeAppPayRequest();
		//$bizcontent = "{\"body\":\"" . $this->uniacid . "\","
		$bizcontent = "{\"body\":\"" . $params['goodsName'] . "\","
			. "\"subject\": \"" . $params['goodsName'] . "\","
			. "\"out_trade_no\": \"" . $params['orderId'] . "\","
			. "\"timeout_express\": \"30m\","
			. "\"total_amount\": \"" . $params['payPrice'] . "\","
			. "\"product_code\":\"QUICK_MSECURITY_PAY\""
			. "}";
		$request->setNotifyUrl($params['notify_url']);
		$request->setBizContent($bizcontent);

		$response = $aop->sdkExecute($request);
		$payInfo = $response; //就是orderString 可以直接给客户端请求，无需再做处理。
		return array(
			"payInfo" => $payInfo,
		);

		/*
		include_once EXTEND_PATH.'alipay/TopSdk.php';
		//读取配置信息
		$aop = new AopClient;
		$aop->gatewayUrl = "https://openapi.alipay.com/gateway.do";
		$aop->appId = $aliCfg['alipay_appid'] ;
		$aop->rsaPrivateKey = $aliCfg['alipay_privateKey'];
		$aop->format = "json";
		$aop->charset = "UTF-8";
		$aop->signType = "RSA2";
		$aop->alipayrsaPublicKey = $aliCfg['alipay_publicKey'];

        $request = new AlipayTradeAppPayRequest();
        $bizcontent = "{\"body\":\"" . $this->uniacid . "\","
                . "\"subject\": \"" . $params['goodsName'] . "\","
                . "\"out_trade_no\": \"" . $params['orderId'] . "\","
                . "\"timeout_express\": \"30m\","
                . "\"total_amount\": \"" . $params['payPrice'] . "\","
                . "\"product_code\":\"QUICK_MSECURITY_PAY\""
                . "}";
        $request->setNotifyUrl($params['notify_url']);
        $request->setBizContent($bizcontent);
        $response = $aop->sdkExecute($request);
        $payInfo = $response; //就是orderString 可以直接给客户端请求，无需再做处理。
        return array(
            "payInfo" => $payInfo,
        );
        */
	}

	//微信支付参数
	public function wechat_app($params, $wechat)
	{
		$prepay_id = $this->generatePrepayId($wechat, $params);
		if (empty($prepay_id) || empty($wechat["appid"]) || empty($wechat["mchid"]) || empty($wechat["signkey"])) {
			return ['errno' => -1, 'message' => "参数错误"];
		}
		if (is_error($prepay_id)) {
			return ['errno' => -1, 'message' => $prepay_id['message']];
		}
		$response = array(
			'appid'     => $wechat["appid"],
			'partnerid' => $wechat["mchid"],
			'prepayid'  => $prepay_id,
			'package'   => 'Sign=WXPay',
			'noncestr'  => random(8),
			'timestamp' => time(),
		);
		$response['sign'] = $this->calculateSign($response, $wechat["signkey"]);
		return $response;
	}

	//临时订单
	public function generatePrepayId($wechat, $params)
	{
		$package = array();
		$package['appid'] = $wechat['appid'];
		$package['mch_id'] = $wechat['mchid'];
		$package['attach'] = $this->uniacid;
		$package['nonce_str'] = random(8);
		$package['body'] = $params['goodsName'];
		$package['out_trade_no'] = $params['orderId'];
		$package['total_fee'] = $params['payPrice'] * 100;
		$package['spbill_create_ip'] = get_client_ip();
		$package['notify_url'] = $params['notify_url'];
		$package['trade_type'] = 'APP';
		ksort($package, SORT_STRING);
		$string1 = '';
		foreach ($package as $key => $v) {
			if (empty($v)) {
				continue;
			}
			$string1 .= "{$key}={$v}&";
		}
		$string1 .= "key={$wechat['signkey']}";
		$package['sign'] = strtoupper(md5($string1));
		$dat = array2xml($package);
		$response = ihttp_request('https://api.mch.weixin.qq.com/pay/unifiedorder', $dat); // 改
		if (is_error($response)) {
			return $response;
		}
		$xml = @$this->isimplexml_load_string($response['content'], 'SimpleXMLElement', LIBXML_NOCDATA);
		if (strval($xml->return_code) == 'FAIL') {
			return ['errno' => -1, 'message' => strval($xml->return_msg)];
		}
		if (strval($xml->result_code) == 'FAIL') {
			return ['errno' => -1, 'message' => strval($xml->err_code) . ': ' . strval($xml->err_code_des)];
		}
		return  (string) $xml->prepay_id;
	}

	//签名
	public function calculateSign($arr, $key)
	{
		ksort($arr);
		$buff = "";
		foreach ($arr as $k => $v) {
			if ($k != "sign" && $k != "key" && $v != "" && !is_array($v)) {
				$buff .= $k . "=" . $v . "&";
			}
		}
		$buff = trim($buff, "&");
		return strtoupper(md5($buff . "&key=" . $key));
	}

	public function isimplexml_load_string($string, $class_name = 'SimpleXMLElement', $options = 0, $ns = '', $is_prefix = false)
	{
		libxml_disable_entity_loader(true);
		if (preg_match('/(\<\!DOCTYPE|\<\!ENTITY)/i', $string)) {
			return false;
		}
		return simplexml_load_string($string, $class_name, $options, $ns, $is_prefix);
	}











	/********************************************************************************************************************************/
	public static function ali_app2($params, $aliCfg)
	{

		include_once EXTEND_PATH . 'alipay/AopSdk.php';
		$aop = new \AopClient();
		$aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
		$aop->appId = $aliCfg['alipay_appid'];
		$aop->rsaPrivateKey = $aliCfg['alipay_privateKey'];
		$aop->alipayrsaPublicKey = $aliCfg['alipay_publicKey'];
		$aop->apiVersion = '1.0';
		$aop->signType = 'RSA2';
		$aop->postCharset = 'UTF-8';
		$aop->format = 'json';
		$request = new \AlipayFundTransToaccountTransferRequest();

		$out_biz_no      = $params['out_biz_no'];
		//收款方账户
		$payee_account   = $params['payee_account'];
		$amount          = $params['amount'];
		$payer_show_name = $params['payer_show_name'];
		$payee_real_name = $params['payee_real_name'];
		$remark          = $params['remark'];

		$request->setBizContent("{" .
			"\"out_biz_no\":\"{$out_biz_no}\"," .
			"\"payee_type\":\"ALIPAY_LOGONID\"," .
			"\"payee_account\":\"{$payee_account}\"," .
			"\"amount\":\"{$amount}\"," .   //金额
			"\"payer_show_name\":\"{$payer_show_name}\"," .   //付款方姓名，可以为 “海淘日记余额提现”
			"\"payee_real_name\":\"{$payee_real_name}\"," .         //收款方真实姓名
			"\"remark\":\"{$remark}\"" .
			"}");

		$result = $aop->execute($request);

		$responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
		$resultCode = $result->$responseNode->code;
		return $resultCode;
		if (!empty($resultCode) && $resultCode == 10000) {
			//echo "成功";
			return true;
		} else {
			//echo "失败";
			return false;
		}
	}
}

function array2xml($arr, $level = 1)
{
	$s = $level == 1 ? "<xml>" : '';
	foreach ($arr as $tagname => $value) {
		if (is_numeric($tagname)) {
			$tagname = $value['TagName'];
			unset($value['TagName']);
		}
		if (!is_array($value)) {
			$s .= "<{$tagname}>" . (!is_numeric($value) ? '<![CDATA[' : '') . $value . (!is_numeric($value) ? ']]>' : '') . "</{$tagname}>";
		} else {
			$s .= "<{$tagname}>" . array2xml($value, $level + 1) . "</{$tagname}>";
		}
	}
	$s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
	return $level == 1 ? $s . "</xml>" : $s;
}

function ihttp_request($url, $post = '', $extra = array(), $timeout = 60)
{
	$urlset = parse_url($url);
	if (empty($urlset['path'])) {
		$urlset['path'] = '/';
	}
	if (!empty($urlset['query'])) {
		$urlset['query'] = "?{$urlset['query']}";
	}
	if (empty($urlset['port'])) {
		$urlset['port'] = $urlset['scheme'] == 'https' ? '443' : '80';
	}
	if (strexists($url, 'https://') && !extension_loaded('openssl')) {
		if (!extension_loaded("openssl")) {
			return ['errno' => -1, 'message' => '请开启您PHP环境的openssl'];
		}
	}
	if (function_exists('curl_init') && function_exists('curl_exec')) {
		$ch = curl_init();
		if (ver_compare(phpversion(), '5.6') >= 0) {
			curl_setopt($ch, CURLOPT_SAFE_UPLOAD, false);
		}
		if (!empty($extra['ip'])) {
			$extra['Host'] = $urlset['host'];
			$urlset['host'] = $extra['ip'];
			unset($extra['ip']);
		}
		@curl_setopt($ch, CURLOPT_URL, $urlset['scheme'] . '://' . $urlset['host'] . ($urlset['port'] == '80' ? '' : ':' . $urlset['port']) . $urlset['path'] . $urlset['query']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		@curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
		if ($post) {
			if (is_array($post)) {
				$filepost = false;
				foreach ($post as $name => $value) {
					if ((is_string($value) && substr($value, 0, 1) == '@') || (class_exists('CURLFile') && $value instanceof CURLFile)) {
						$filepost = true;
						break;
					}
				}
				if (!$filepost) {
					$post = http_build_query($post);
				}
			}
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		}
		if (!empty($GLOBALS['_W']['config']['setting']['proxy'])) {
			$urls = parse_url($GLOBALS['_W']['config']['setting']['proxy']['host']);
			if (!empty($urls['host'])) {
				curl_setopt($ch, CURLOPT_PROXY, "{$urls['host']}:{$urls['port']}");
				$proxytype = 'CURLPROXY_' . strtoupper($urls['scheme']);
				if (!empty($urls['scheme']) && defined($proxytype)) {
					curl_setopt($ch, CURLOPT_PROXYTYPE, constant($proxytype));
				} else {
					curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
					curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 1);
				}
				if (!empty($GLOBALS['_W']['config']['setting']['proxy']['auth'])) {
					curl_setopt($ch, CURLOPT_PROXYUSERPWD, $GLOBALS['_W']['config']['setting']['proxy']['auth']);
				}
			}
		}
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSLVERSION, 1);
		if (defined('CURL_SSLVERSION_TLSv1')) {
			curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
		}
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:9.0.1) Gecko/20100101 Firefox/9.0.1');
		if (!empty($extra) && is_array($extra)) {
			$headers = array();
			foreach ($extra as $opt => $value) {
				if (strexists($opt, 'CURLOPT_')) {
					curl_setopt($ch, constant($opt), $value);
				} elseif (is_numeric($opt)) {
					curl_setopt($ch, $opt, $value);
				} else {
					$headers[] = "{$opt}: {$value}";
				}
			}
			if (!empty($headers)) {
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			}
		}
		$data = curl_exec($ch);
		$status = curl_getinfo($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		curl_close($ch);
		if ($errno || empty($data)) {
			return ['errno' => 1, 'message' => $error];
		} else {
			return ihttp_response_parse($data);
		}
	}
	$method = empty($post) ? 'GET' : 'POST';
	$fdata = "{$method} {$urlset['path']}{$urlset['query']} HTTP/1.1\r\n";
	$fdata .= "Host: {$urlset['host']}\r\n";
	if (function_exists('gzdecode')) {
		$fdata .= "Accept-Encoding: gzip, deflate\r\n";
	}
	$fdata .= "Connection: close\r\n";
	if (!empty($extra) && is_array($extra)) {
		foreach ($extra as $opt => $value) {
			if (!strexists($opt, 'CURLOPT_')) {
				$fdata .= "{$opt}: {$value}\r\n";
			}
		}
	}
	$body = '';
	if ($post) {
		if (is_array($post)) {
			$body = http_build_query($post);
		} else {
			$body = urlencode($post);
		}
		$fdata .= 'Content-Length: ' . strlen($body) . "\r\n\r\n{$body}";
	} else {
		$fdata .= "\r\n";
	}
	if ($urlset['scheme'] == 'https') {
		$fp = fsockopen('ssl://' . $urlset['host'], $urlset['port'], $errno, $error);
	} else {
		$fp = fsockopen($urlset['host'], $urlset['port'], $errno, $error);
	}
	stream_set_blocking($fp, true);
	stream_set_timeout($fp, $timeout);
	if (!$fp) {
		return ['errno' => 1, 'message' => $error];
	} else {
		fwrite($fp, $fdata);
		$content = '';
		while (!feof($fp))
			$content .= fgets($fp, 512);
		fclose($fp);
		return ihttp_response_parse($content, true);
	}
}

function ihttp_response_parse($data, $chunked = false)
{
	$rlt = array();
	$headermeta = explode('HTTP/', $data);
	if (count($headermeta) > 2) {
		$data = 'HTTP/' . array_pop($headermeta);
	}
	$pos = strpos($data, "\r\n\r\n");
	$split1[0] = substr($data, 0, $pos);
	$split1[1] = substr($data, $pos + 4, strlen($data));

	$split2 = explode("\r\n", $split1[0], 2);
	preg_match('/^(\S+) (\S+) (\S+)$/', $split2[0], $matches);
	$rlt['code'] = $matches[2];
	$rlt['status'] = $matches[3];
	$rlt['responseline'] = $split2[0];
	$header = explode("\r\n", $split2[1]);
	$isgzip = false;
	$ischunk = false;
	foreach ($header as $v) {
		$pos = strpos($v, ':');
		$key = substr($v, 0, $pos);
		$value = trim(substr($v, $pos + 1));
		if (@is_array($rlt['headers'][$key])) {
			$rlt['headers'][$key][] = $value;
		} elseif (!empty($rlt['headers'][$key])) {
			$temp = $rlt['headers'][$key];
			unset($rlt['headers'][$key]);
			$rlt['headers'][$key][] = $temp;
			$rlt['headers'][$key][] = $value;
		} else {
			$rlt['headers'][$key] = $value;
		}
		if (!$isgzip && strtolower($key) == 'content-encoding' && strtolower($value) == 'gzip') {
			$isgzip = true;
		}
		if (!$ischunk && strtolower($key) == 'transfer-encoding' && strtolower($value) == 'chunked') {
			$ischunk = true;
		}
	}
	if ($chunked && $ischunk) {
		$rlt['content'] = ihttp_response_parse_unchunk($split1[1]);
	} else {
		$rlt['content'] = $split1[1];
	}
	if ($isgzip && function_exists('gzdecode')) {
		$rlt['content'] = gzdecode($rlt['content']);
	}

	$rlt['meta'] = $data;
	if ($rlt['code'] == '100') {
		return ihttp_response_parse($rlt['content']);
	}
	return $rlt;
}

function ihttp_response_parse_unchunk($str = null)
{
	if (!is_string($str) or strlen($str) < 1) {
		return false;
	}
	$eol = "\r\n";
	$add = strlen($eol);
	$tmp = $str;
	$str = '';
	do {
		$tmp = ltrim($tmp);
		$pos = strpos($tmp, $eol);
		if ($pos === false) {
			return false;
		}
		$len = hexdec(substr($tmp, 0, $pos));
		if (!is_numeric($len) or $len < 0) {
			return false;
		}
		$str .= substr($tmp, ($pos + $add), $len);
		$tmp  = substr($tmp, ($len + $pos + $add));
		$check = trim($tmp);
	} while (!empty($check));
	unset($tmp);
	return $str;
}

function strexists($string, $find)
{
	return !(strpos($string, $find) === FALSE);
}

function ver_compare($version1, $version2)
{
	$version1 = str_replace('.', '', $version1);
	$version2 = str_replace('.', '', $version2);
	$oldLength = istrlen($version1);
	$newLength = istrlen($version2);
	if (is_numeric($version1) && is_numeric($version2)) {
		if ($oldLength > $newLength) {
			$version2 .= str_repeat('0', $oldLength - $newLength);
		}
		if ($newLength > $oldLength) {
			$version1 .= str_repeat('0', $newLength - $oldLength);
		}
		$version1 = intval($version1);
		$version2 = intval($version2);
	}
	return version_compare($version1, $version2);
}

function istrlen($string, $charset = '')
{
	if (empty($charset)) {
		$charset = 'utf-8';
	}
	if (strtolower($charset) == 'gbk') {
		$charset = 'gbk';
	} else {
		$charset = 'utf8';
	}
	if (function_exists('mb_strlen')) {
		return mb_strlen($string, $charset);
	} else {
		$n = $noc = 0;
		$strlen = strlen($string);
		if ($charset == 'utf8') {
			while ($n < $strlen) {
				$t = ord($string[$n]);
				if ($t == 9 || $t == 10 || (32 <= $t && $t <= 126)) {
					$n++;
					$noc++;
				} elseif (194 <= $t && $t <= 223) {
					$n += 2;
					$noc++;
				} elseif (224 <= $t && $t <= 239) {
					$n += 3;
					$noc++;
				} elseif (240 <= $t && $t <= 247) {
					$n += 4;
					$noc++;
				} elseif (248 <= $t && $t <= 251) {
					$n += 5;
					$noc++;
				} elseif ($t == 252 || $t == 253) {
					$n += 6;
					$noc++;
				} else {
					$n++;
				}
			}
		} else {
			while ($n < $strlen) {
				$t = ord($string[$n]);
				if ($t > 127) {
					$n += 2;
					$noc++;
				} else {
					$n++;
					$noc++;
				}
			}
		}
		return $noc;
	}
}
