<?php

namespace app\api\service;


use think\Db;


class PaymentWebService
{
    
    public function getAliPay($order_no,$amount,$subject,$url='')
    {
        require_once EXTEND_PATH.'alipay/wappay/buildermodel/AlipayTradeWapPayContentBuilder.php';
        
        require_once EXTEND_PATH.'alipay/wappay/service/AlipayTradeService.php';
        
        $config = Db::name("config")->where("id",1)->find();
        
        //支付宝回调地址 site_root返回当前域名
        //$notify_url = SITE_ROOT.'/api/pay/aliNotify';
        $notify_url = url("api/pay/aliNotify",'',false, true);
        $contentBuilder = new \AlipayTradeWapPayContentBuilder();
        $contentBuilder->setOutTradeNo($order_no);
        $contentBuilder->setSubject($subject);
        $contentBuilder->setTotalAmount($amount);
        
        $alipay_config=[];
        $alipay_config['app_id'] = trim($config['zfb_appid']);
        $alipay_config['merchant_private_key'] = trim($config['zfb_develop_private_key']);
        $alipay_config['alipay_public_key'] = trim($config['zfb_public_key']);
        
        $alipay = new  \AlipayTradeService($alipay_config);
        $respon = $alipay->wapPay($contentBuilder,$url,$notify_url);
        return $respon;
        
    }
    public function getWXPayurl($order_no,$amount,$subject)
    {
        
        $config = Db::name("config")->where("id",1)->find();
        $config_tmpe = json_decode($config['wxh5'], true);
        $wx_appid   = $config_tmpe['appid'];
        $wx_mchid   = $config_tmpe['mchid'];
        $wx_signkey = $config_tmpe['signkey'];
        
        $out_trade_no = $order_no;
        $total_fee = intval($amount * 100);
        $body = $subject;
        $nonceStr = $this->createNonceStr();
        $ip = get_client_ip(0, true);
        $sinfo='{"h5_info": {"type":"Wap","wap_url": "'.cmf_get_domain().'","wap_name": "留莲忘返"}}';
        
        $requestConfigs = array(
            'appid'=>$wx_appid,
            'mch_id'=>$wx_mchid,
            'nonce_str'=>$nonceStr,
            'body'=>$body,
            'out_trade_no'=>$out_trade_no,
            'total_fee'=>$total_fee,//1分
            'spbill_create_ip'=>$ip,
            'notify_url'=>SITE_ROOT.'/api/pay/wechatNotify',
            'trade_type'=>'MWEB',
            'scene_info'=>$sinfo
        );
        $requestConfigs['sign'] = self::getSign($requestConfigs, $wx_signkey);
        $responseXml = self::curlPost('https://api.mch.weixin.qq.com/pay/unifiedorder', self::arrayToXml($requestConfigs));
        $unifiedOrder = simplexml_load_string($responseXml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($unifiedOrder === false) {
            return  ['errno'=>1,'message'=>"parse xml error"];
        }
        if ($unifiedOrder->return_code != 'SUCCESS') {
            return  ['errno'=>1,'message'=>$unifiedOrder->return_msg];
        }
        if ($unifiedOrder->result_code != 'SUCCESS') {
            return  ['errno'=>1,'message'=>$unifiedOrder->err_code];
        }
        $weburl="$unifiedOrder->mweb_url";
        $data=['url'=>$weburl];
        return ['errno'=>0,'message'=>"返回微信支付链接成功",'info'=>$data];
        
    }
    
    //数组转xml
    public static function arrayToXml($arr)
    {
        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= sprintf("<%s>%s</%s>", $key, $val, $key);
            } else {
                $xml .= sprintf("<%s><![CDATA[%s]]></%s>", $key, $val, $key);
            }
        }
        $xml .= "</xml>";
        return $xml;
    }
    
    public static function curlPost($url = '', $postData = '', $options = array())
    {
        if (is_array($postData)) {
            $postData = http_build_query($postData);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); //设置cURL允许执行的最长秒数
        if (!empty($options)) {
            curl_setopt_array($ch, $options);
        }
        //https请求 不验证证书和host
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }
    
    public static function getSign($params, $key)
    {
        ksort($params, SORT_STRING);
        $unSignParaString = self::formatQueryParaMap($params, false);
        $signStr = strtoupper(md5($unSignParaString . "&key=" . $key));
        return $signStr;
    }
    
    public static function formatQueryParaMap($paraMap, $urlEncode = false)
    {
        $buff = "";
        ksort($paraMap);
        foreach ($paraMap as $k => $v) {
            if (null != $v && "null" != $v) {
                if ($urlEncode) {
                    $v = urlencode($v);
                }
                $buff .= $k . "=" . $v . "&";
            }
        }
        $reqPar = '';
        if (strlen($buff) > 0) {
            $reqPar = substr($buff, 0, strlen($buff) - 1);
        }
        return $reqPar;
    }
    
    private function createNonceStr($length = 16) {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

}

function array2xml($arr, $level = 1) {
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

function ihttp_request($url, $post = '', $extra = array(), $timeout = 60) {
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
			return ['errno'=>-1,'message'=>'请开启您PHP环境的openssl'];
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
			return ['errno'=>1,'message'=>$error];
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
		return ['errno'=>1,'message'=>$error];
	} else {
		fwrite($fp, $fdata);
		$content = '';
		while (!feof($fp))
			$content .= fgets($fp, 512);
		fclose($fp);
		return ihttp_response_parse($content, true);
	}
}

function ihttp_response_parse($data, $chunked = false) {
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
		if(!$isgzip && strtolower($key) == 'content-encoding' && strtolower($value) == 'gzip') {
			$isgzip = true;
		}
		if(!$ischunk && strtolower($key) == 'transfer-encoding' && strtolower($value) == 'chunked') {
			$ischunk = true;
		}
	}
	if($chunked && $ischunk) {
		$rlt['content'] = ihttp_response_parse_unchunk($split1[1]);
	} else {
		$rlt['content'] = $split1[1];
	}
	if($isgzip && function_exists('gzdecode')) {
		$rlt['content'] = gzdecode($rlt['content']);
	}

	$rlt['meta'] = $data;
	if($rlt['code'] == '100') {
		return ihttp_response_parse($rlt['content']);
	}
	return $rlt;
}

function ihttp_response_parse_unchunk($str = null) {
	if(!is_string($str) or strlen($str) < 1) {
		return false; 
	}
	$eol = "\r\n";
	$add = strlen($eol);
	$tmp = $str;
	$str = '';
	do {
		$tmp = ltrim($tmp);
		$pos = strpos($tmp, $eol);
		if($pos === false) {
			return false;
		}
		$len = hexdec(substr($tmp, 0, $pos));
		if(!is_numeric($len) or $len < 0) {
			return false;
		}
		$str .= substr($tmp, ($pos + $add), $len);
		$tmp  = substr($tmp, ($len + $pos + $add));
		$check = trim($tmp);
	} while(!empty($check));
	unset($tmp);
	return $str;
}

function strexists($string, $find) {
	return !(strpos($string, $find) === FALSE);
}

function ver_compare($version1, $version2) {
	$version1 = str_replace('.', '', $version1);
	$version2 = str_replace('.', '', $version2);
	$oldLength = istrlen($version1);
	$newLength = istrlen($version2);
	if(is_numeric($version1) && is_numeric($version2)) {
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

function istrlen($string, $charset = '') {
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
