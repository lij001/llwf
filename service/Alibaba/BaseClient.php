<?php

namespace app\api\service\Alibaba;

use think\Exception;

abstract class BaseClient extends Request
{
    /**
     * 格式
     * host地址/接口类型/请求格式/API版本/API命名空间/API接口名/appKey
     * @var string
     */
    // 1688 开放平台公网域名
    private $serverHost = 'http://gw.open.1688.com';
    // 接口类型：api和openapi
    private $apiType = 'openapi';
    // 请求协议格式： param2，json2，xml2，param，json，xml，http
    private $param = 'param2';
    // API版本
    private $version = 1;
    /**
     * appKey
     * @var
     */
    protected $appKey;
    /**
     * secretKey
     * @var
     */
    protected $secretKey;
    /**
     *
     * @var int
     */
    /**
     * API命名空间
     * @var
     */
    protected $namespace;
    /**
     * API接口名
     * @var
     */
    protected $name;

    public function __construct()
    {
        $this->appKey = config('alibaba_app_key');
        $this->secretKey = config('alibaba_secret_key');
        if (empty($this->appKey) || empty($this->appKey)) {
            throw new \think\Exception('缺少key或secret', 422);
        }
    }

    /**
     * 获取数据
     * @param $params
     * @return mixed
     * @throws Exception
     */
    protected function result($params)
    {
        // 接口url
        $path = implode('/', [$this->serverHost, $this->apiType,]) . '/' . $this->url_path();
        // 获取签名
        $data = $this->curlPost($path, $this->getParams($params));
        return json_decode($data, true);
    }

    /**
     * 格式化数据
     * @param $params
     * @return string
     * @throws Exception
     */
    private function getParams($params)
    {
        //$params['_aop_timestamp'] = time();
        $params['_aop_datePattern'] = 'yyyyMMddHHmmssSSSZ';
        $params['_aop_signature'] = $this->signature($this->url_path(), $params);
        $parameter = [];
        foreach ($params as $k => $v) {
            $parameter[] = $k . "=" . $v;
        }
        return implode("&", $parameter);
    }

    /**
     * 构造签名因子
     * @return string
     * @throws Exception
     */
    private function url_path()
    {
        if (empty($this->namespace) || empty($this->name)) {
            throw new \think\Exception('缺少命名空间或接口名', 422);
        }
        return implode('/', [
            $this->param,
            $this->version,
            $this->getNamespace(),
            $this->getName(),
            $this->appKey
        ]);
    }
    /**
     * 请求签名
     * @param $path
     * @param array $params
     * @return string
     */
    private function signature($path, array $params)
    {
        $paramsToSign = array();
        foreach ($params as $k => $v) {
            $paramToSign = $k . $v;
            Array_push($paramsToSign, $paramToSign);
        }
        sort($paramsToSign);
        $implodeParams = implode($paramsToSign);
        $pathAndParams = $path . $implodeParams;
        $sign = hash_hmac("sha1", $pathAndParams, $this->secretKey, true);
        $signHexWithLowcase = bin2hex($sign);
        $signHexUppercase = strtoupper($signHexWithLowcase);
        return $signHexUppercase;
    }

    /**
     * curl_post 发送
     * @param $urlRequest
     * @param $paramToSign
     * @return bool|string
     */
    private function curlPost($urlRequest, $paramToSign)
    {
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $urlRequest);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paramToSign);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        return $data;
    }
    /**
     * @return mixed
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @param mixed $namespace
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return mixed
     */
    public function getAppKey()
    {
        return $this->appKey;
    }

    /**
     * @param mixed $appKey
     */
    public function setAppKey($appKey)
    {
        $this->appKey = $appKey;
    }

    /**
     * @return mixed
     */
    public function getSecretKey()
    {
        return $this->secretKey;
    }

    /**
     * @param mixed $secretKey
     */
    public function setSecretKey($secretKey)
    {
        $this->secretKey = $secretKey;
    }
}
