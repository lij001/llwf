<?php

namespace app\api\service\AliPay\Kernel;

use app\api\service\AliPay\Kernel\Exceptions\InvalidArgumentException;
use app\api\service\AliPay\Kernel\Exceptions\InvalidResponseException;

/**
 * 支付宝支付基类
 * Class AliPay
 * @package AliPay\Contracts
 */
abstract class BaseClient
{

    /**
     * 支持配置
     * @var Config
     */
    protected $config;

    /**
     * 当前请求数据
     * @var Config
     */
    protected $options;

    /**
     * DzContent数据
     * @var Config
     */
    protected $params;

    /**
     * 静态缓存
     * @var static
     */
    protected static $cache;

    /**
     * 正常请求网关
     * @var string
     */
    protected $gateway = 'https://openapi.alipay.com/gateway.do';

    /**
     * AliPay constructor.
     * @param array $options
     */
    public function __construct($options)
    {
        $this->params = new Config([]);
        $this->config = new Config($options);
        if (empty($options['appid'])) {
            throw new InvalidArgumentException("Missing Config -- [appid]");
        }
        if (empty($options['public_key'])) {
            throw new InvalidArgumentException("Missing Config -- [public_key]");
        }
        if (empty($options['private_key'])) {
            throw new InvalidArgumentException("Missing Config -- [private_key]");
        }
        if (!empty($options['debug'])) {
            $this->gateway = 'https://openapi.alipaydev.com/gateway.do';
        }
        $this->options = new Config([
            'app_id'    => $this->config->get('appid'),
            'charset'   => empty($options['charset']) ? 'utf-8' : $options['charset'],
            'format'    => 'JSON',
            'version'   => '1.0',
            'sign_type' => empty($options['sign_type']) ? 'RSA2' : $options['sign_type'],
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        if (isset($options['notify_url']) && $options['notify_url'] !== '') {
            $this->options->set('notify_url', $options['notify_url']);
        }
        if (isset($options['return_url']) && $options['return_url'] !== '') {
            $this->options->set('return_url', $options['return_url']);
        }
        if (isset($options['app_auth_token']) && $options['app_auth_token'] !== '') {
            $this->options->set('app_auth_token', $options['app_auth_token']);
        }
    }

    /**
     * 静态创建对象
     * @param array $config
     * @return static
     */
    public static function instance(array $config)
    {
        $key = md5(get_called_class() . serialize($config));
        if (isset(self::$cache[$key])) return self::$cache[$key];
        return self::$cache[$key] = new static($config);
    }


    /**
     * 获取通知数据
     * @param boolean $needSignType 是否需要sign_type字段
     * @return boolean|array
     * @throws InvalidResponseException
     */
    public function notify($needSignType = false)
    {
        $data = $_POST;
        if (empty($data) || empty($data['sign'])) {
            throw new InvalidResponseException('Illegal push request.', 0, $data);
        }
        $string = $this->getSignContent($data, $needSignType);
        $content = wordwrap($this->config->get('public_key'), 64, "\n", true);
        $res = "-----BEGIN PUBLIC KEY-----\n{$content}\n-----END PUBLIC KEY-----";
        if (openssl_verify($string, base64_decode($data['sign']), $res, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidResponseException('Data signature verification failed.', 0, $data);
        }
        return $data;
    }

    /**
     * 验证接口返回的数据签名
     * @param array $data 通知数据
     * @param null|string $sign 数据签名
     * @return array|boolean
     * @throws InvalidResponseException
     */
    protected function verify($data, $sign)
    {
        $content = wordwrap($this->config->get('public_key'), 64, "\n", true);
        $res = "-----BEGIN PUBLIC KEY-----\n{$content}\n-----END PUBLIC KEY-----";
        if ($this->options->get('sign_type') === 'RSA2') {
            if (openssl_verify(json_encode($data, 256), base64_decode($sign), $res, OPENSSL_ALGO_SHA256) !== 1) {
                throw new InvalidResponseException('Data signature verification failed.');
            }
        } else {
            if (openssl_verify(json_encode($data, 256), base64_decode($sign), $res, OPENSSL_ALGO_SHA1) !== 1) {
                throw new InvalidResponseException('Data signature verification failed.');
            }
        }
        return $data;
    }

    /**
     * 获取数据签名
     * @return string
     */
    protected function getSign()
    {
        $content = wordwrap($this->trimCert($this->config->get('private_key')), 64, "\n", true);
        $string = "-----BEGIN RSA PRIVATE KEY-----\n{$content}\n-----END RSA PRIVATE KEY-----";
        if ($this->options->get('sign_type') === 'RSA2') {
            openssl_sign($this->getSignContent($this->options->get(), true), $sign, $string, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($this->getSignContent($this->options->get(), true), $sign, $string, OPENSSL_ALGO_SHA1);
        }
        return base64_encode($sign);
    }

    /**
     * 去除证书前后内容及空白
     * @param string $sign
     * @return string
     */
    protected function trimCert($sign)
    {
        // if (file_exists($sign)) $sign = file_get_contents($sign);
        return preg_replace(['/\s+/', '/\-{5}.*?\-{5}/'], '', $sign);
    }

    /**
     * 数据签名处理
     * @param array $data 需要进行签名数据
     * @param boolean $needSignType 是否需要sign_type字段
     * @return bool|string
     */
    private function getSignContent(array $data, $needSignType = false)
    {
        list($attrs,) = [[], ksort($data)];
        if (isset($data['sign'])) unset($data['sign']);
        if (empty($needSignType)) unset($data['sign_type']);
        foreach ($data as $key => $value) {
            if ($value === '' || is_null($value)) continue;
            array_push($attrs, "{$key}={$value}");
        }
        return join('&', $attrs);
    }

    /**
     * 数据包生成及数据签名
     * @param array $options
     * @param string $method
     */
    protected function applyData($options,$method)
    {
        if ($method == 'GET') {
            $this->options->set('biz_content', json_encode($this->params->merge($options), 256));
        }
        $this->options->set('sign', $this->getSign());
    }

    /**
     * 请求接口并验证访问数据
     * @param array $options
     * @param string $m
     * @return mixed
     * @throws Exceptions\LocalCacheException
     * @throws InvalidResponseException
     */
    protected function getResult($options,$m='GET')
    {
        $this->applyData($options,$m);
        $method = str_replace('.', '_', $this->options['method']) . '_response';

        if ($m == 'POST') {
            $data = json_decode(Tools::post($this->gateway, $this->options->get()), true);
        } else {
            $data = json_decode(Tools::get($this->gateway, $this->options->get()), true);
        }
//halt($data);
        if (!isset($data[$method])) {
            $method='error_response';
        }
        // if (isset($data[$method]['code']) && $data[$method]['code'] !== '10000') {
        //     throw new InvalidResponseException(
        //         "Error: " .
        //         (empty($data[$method]['code']) ? '' : "{$data[$method]['msg']} [{$data[$method]['code']}]\r\n") .
        //         (empty($data[$method]['sub_code']) ? '' : "{$data[$method]['sub_msg']} [{$data[$method]['sub_code']}]\r\n"),
        //         $data[$method]['code'], $data
        //     );
        // }
        // return $data[$method];
        // 去除返回结果签名检查
        // return $this->verify($data[$method], $data['sign']);
        return $data;
    }

    /**
     * 生成支付HTML代码
     * @return string
     */
    protected function buildPayHtml()
    {
        $html = "<form id='alipaysubmit' name='alipaysubmit' action='{$this->gateway}' method='post'>";
        foreach ($this->options->get() as $key => $value) {
            $value = str_replace("'", '&apos;', $value);
            $html .= "<input type='hidden' name='{$key}' value='{$value}'/>";
        }
        $html .= "<input type='submit' value='ok' style='display:none;'></form>";
        return "{$html}<script>document.forms['alipaysubmit'].submit();</script>";
    }

    /**
     * 新版 从证书中提取序列号
     * @param string $sign
     * @return string
     */
    public function getCertSN($sign)
    {
         if (file_exists($sign)) $sign = file_get_contents($sign);
        $ssl = openssl_x509_parse($sign);
        return md5($this->_arr2str(array_reverse($ssl['issuer'])) . $ssl['serialNumber']);
    }

    /**
     * 新版 提取根证书序列号
     * @param string $sign
     * @return string|null
     */
    public function getRootCertSN($sign)
    {
        $sn = null;
        if (file_exists($sign)) $sign = file_get_contents($sign);
        $array = explode("-----END CERTIFICATE-----", $sign);
        for ($i = 0; $i < count($array) - 1; $i++) {
            $ssl[$i] = openssl_x509_parse($array[$i] . "-----END CERTIFICATE-----");
            if (strpos($ssl[$i]['serialNumber'], '0x') === 0) {
                $ssl[$i]['serialNumber'] = $this->_hex2dec($ssl[$i]['serialNumber']);
            }
            if ($ssl[$i]['signatureTypeLN'] == "sha1WithRSAEncryption" || $ssl[$i]['signatureTypeLN'] == "sha256WithRSAEncryption") {
                if ($sn == null) {
                    $sn = md5($this->_arr2str(array_reverse($ssl[$i]['issuer'])) . $ssl[$i]['serialNumber']);
                } else {
                    $sn = $sn . "_" . md5($this->_arr2str(array_reverse($ssl[$i]['issuer'])) . $ssl[$i]['serialNumber']);
                }
            }
        }
        return $sn;
    }

    /**
     * 从证书中提取公钥
     * @param $sign
     * @return mixed|string
     */
    public function getPublicKey($sign)
    {
        if (file_exists($sign)) $cert = file_get_contents($sign);
        $pkey = openssl_pkey_get_public($cert);
        $keyData = openssl_pkey_get_details($pkey);
        $public_key = str_replace('-----BEGIN PUBLIC KEY-----', '', $keyData['key']);
        $public_key = trim(str_replace('-----END PUBLIC KEY-----', '', $public_key));
        return $public_key;
    }

    /**
     * 新版 设置网关应用公钥证书SN、支付宝根证书SN
     */
    protected function setAppCertSnAndRootCertSn()
    {
        if (!$this->config->get('app_cert')) {
            throw new InvalidArgumentException("Missing Config -- [app_cert]");
        }
        if (!$this->config->get('root_cert')) {
            throw new InvalidArgumentException("Missing Config -- [root_cert]");
        }
        $this->options->set('app_cert_sn', $this->getCertSN($this->config->get('app_cert')));
        $this->options->set('alipay_root_cert_sn', $this->getRootCertSN($this->config->get('root_cert')));
        if (!$this->options->get('app_cert_sn')) {
            throw new InvalidArgumentException("Missing options -- [app_cert_sn]");
        }
        if (!$this->options->get('alipay_root_cert_sn')) {
            throw new InvalidArgumentException("Missing options -- [alipay_root_cert_sn]");
        }
    }


    /**
     * 新版 数组转字符串
     * @param array $array
     * @return string
     */
    private function _arr2str($array)
    {
        $string = [];
        if ($array && is_array($array)) {
            foreach ($array as $key => $value) {
                $string[] = $key . '=' . $value;
            }
        }
        return implode(',', $string);
    }


    /**
     * 新版 0x转高精度数字
     * @param string $hex
     * @return int|string
     */
    private function _hex2dec($hex)
    {
        list($dec, $len) = [0, strlen($hex)];
        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
        }
        return $dec;
    }

    /**
     * 应用数据操作
     * @param array $options
     * @return mixed
     */
    abstract public function apply($options);
}
