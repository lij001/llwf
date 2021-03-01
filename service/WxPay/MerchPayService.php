<?php

namespace app\api\service\WxPay;

use think\Db;

/**
 * 企业付款类
 * Class MerchPayService
 * @package app\api\service
 */
class MerchPayService {
    //微信支付配置信息
    protected $config = [
        'api_cert' => ROOT_PATH . 'app/api/service/WxPay/cert/apiclient_cert.pem',
        'api_key' => ROOT_PATH . 'app/api/service/WxPay/cert/apiclient_key.pem'
    ];

    /**
     * 企业付款
     * @param string $openid 用户openID
     * @param string $trade_no 单号
     * @param string $money 金额
     * @param string $desc 描述
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function pay($openid, $trade_no, $money, $desc, $real_name, $check) {
        $config = Db::name("config")->where("id", 1)->find();
        $app_id = $config['appid'];
        $mch_id = $config['mchid'];
        $signkey = $config['signkey'];
        if (empty($app_id) || empty($mch_id) || empty($signkey)) {
            halt('微信支付相关参数不完整');
            return [1, '微信支付相关参数不完整'];
        }
        // 常规参数
        $data = [
            'mch_appid' => $app_id,
            'mchid' => $mch_id,
            'nonce_str' => $this->getNonceStr(),
            'partner_trade_no' => $trade_no,
            'openid' => $openid,
            'check_name' => $check ? 'FORCE_CHECK' : 'NO_CHECK',//FORCE_CHECK 强制检测,NO_CHECK 不校验
            're_user_name' => $real_name,
            'amount' => $money,
            'desc' => $desc,
            'spbill_create_ip' => self::getip()
        ];
        if (!$check) unset($data['re_user_name']);
        //生成签名
        $data['sign'] = self::MakeSign($data, $signkey);
        //构造XML数据
        $xml = self::array2xml($data);
        $url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers';
        //发送post请求
        $res = self::curl_post_ssl($xml, $url, true);
        if (!$res) {
            return ['status' => 1, 'msg' => "不能连接服务器"];
        }

        //付款结果分析
        $content = self::xml2array($res);
        if (strval($content['return_code']) == 'FAIL') {
            return ['status' => 1, 'msg' => strval($content['return_msg'])];
        }
        if (strval($content['result_code']) == 'FAIL') {
            return ['status' => 1, 'msg' => strval($content['err_code']) . ':' . strval($content['err_code_des'])];
        }
        $resdata = [
            'return_code' => strval($content['return_code']),
            'result_code' => strval($content['result_code']),
            'nonce_str' => strval($content['nonce_str']),
            'partner_trade_no' => strval($content['partner_trade_no']),
            'payment_no' => strval($content['payment_no']),
            'payment_time' => strval($content['payment_time']),
        ];
        return $resdata;
    }

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return string 产生的随机字符串
     */
    public function getNonceStr($length = 32) {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 签名
     * @param $params
     * @param $signkey
     * @return string
     */
    public function MakeSign($params, $signkey) {
        // 去空
        $params = array_filter($params);
        //签名步骤一：按字典序排序参数
        ksort($params);
        $string = $this->ToUrlParams($params);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=" . $signkey;
        // $string = $string . "&key=".WxPayConfig::KEY;
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 格式化参数格式化成url参数
     */
    public function ToUrlParams($params) {
        $buff = "";
        foreach ($params as $k => $v) {
            if ($k != "sign" && $v != "" && !is_array($v)) {
                $buff .= $k . "=" . $v . "&";
            }
        }
        $buff = trim($buff, "&");
        return $buff;
    }

    /**
     * 将一个数组转换为 XML 结构的字符串
     * @param array $arr 要转换的数组
     * @param int $level 节点层级, 1 为 Root.
     * @return string XML 结构的字符串
     */
    public function array2xml($arr, $level = 1) {
        $s = $level == 1 ? "<xml>" : '';
        foreach ($arr as $tagname => $value) {
            if (is_numeric($tagname)) {
                $tagname = $value['TagName'];
                unset($value['TagName']);
            }
            if (!is_array($value)) {
                $s .= "<{$tagname}>" . (!is_numeric($value) ? '<![CDATA[' : '') . $value . (!is_numeric($value) ? ']]>' : '') . "</{$tagname}>";
            } else {
                $s .= "<{$tagname}>" . $this->array2xml($value, $level + 1) . "</{$tagname}>";
            }
        }
        $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
        return $level == 1 ? $s . "</xml>" : $s;
    }

    /**
     * 将xml转为array
     * @param string $xml xml字符串
     * @return array    转换得到的数组
     */
    public function xml2array($xml) {
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $result = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result;
    }

    /**
     * 获取IP地址
     * @return [String] [ip地址]
     */
    public function getip() {
        static $ip = '';
        $ip = $_SERVER['REMOTE_ADDR'];
        if (isset($_SERVER['HTTP_CDN_SRC_IP'])) {
            $ip = $_SERVER['HTTP_CDN_SRC_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) and preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
            foreach ($matches[0] as $xip) {
                if (!preg_match('#^(10|172\.16|192\.168)\.#', $xip)) {
                    $ip = $xip;
                    break;
                }
            }
        }
        return $ip;
    }

    /**
     * 企业付款发起请求
     * 此函数来自:https://pay.weixin.qq.com/wiki/doc/api/download/cert.zip
     *
     * @param string $xml 需要post的xml数据
     * @param string $url url
     * @param bool $useCert 是否需要证书，默认不需要
     * @param int $second url执行超时时间，默认30s
     * @return bool|string
     */
    public function curl_post_ssl($xml, $url, $useCert = false, $second = 30) {
        $ch = curl_init();
        //设置超时
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);

        //如果有配置代理这里就设置代理
        // if (WxPayConfig::CURL_PROXY_HOST != "0.0.0.0"
        //   && WxPayConfig::CURL_PROXY_PORT != 0) {
        //   curl_setopt($ch, CURLOPT_PROXY, WxPayConfig::CURL_PROXY_HOST);
        //   curl_setopt($ch, CURLOPT_PROXYPORT, WxPayConfig::CURL_PROXY_PORT);
        // }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); //严格校验
        //设置header
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

        if ($useCert == true) {
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, $this->config['api_cert']);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, $this->config['api_key']);
        }
        //post提交方式
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        //运行curl
        $data = curl_exec($ch);
        //返回结果
        if ($data) {
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            echo "curl出错，错误码:$error";
            curl_close($ch);
            return false;
        }
    }

    public function ihttp_request($url, $post = '', $extra = array(), $timeout = 60) {
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
        if (self::strexists($url, 'https://') && !extension_loaded('openssl')) {
            if (!extension_loaded("openssl")) {
                return ['errno' => -1, 'message' => '请开启您PHP环境的openssl'];
            }
        }
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init();
            if (self::ver_compare(phpversion(), '5.6') >= 0) {
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

    public function strexists($string, $find) {
        return !(strpos($string, $find) === FALSE);
    }

    function ver_compare($version1, $version2) {
        $version1 = str_replace('.', '', $version1);
        $version2 = str_replace('.', '', $version2);
        $oldLength = self::istrlen($version1);
        $newLength = self::istrlen($version2);
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
}
