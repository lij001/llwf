<?php

namespace app\api\service\Pdd;

use think\Exception;

abstract class BaseClient extends Request
{
    /**
     * 拼多多开放平台接口地址
     * @var string
     */
    private $serverHost = 'https://gw-api.pinduoduo.com/api/router';
    /**
     * API接口名称。必填
     * @var
     */
    protected $type;
    /**
     * pop分配给应用的client_id。必填
     * @var
     */
    protected $client_id;
    /**
     *
     * @var
     */
    protected $client_secret;
    /**
     * 响应格式，XML和JSON二选一，注意是大写。默认JSON,非必填
     * @var string
     */
    private $data_type;
    /**
     * API协议版本号。默认是V1,非必填
     * @var
     */
    private $version;

    public function __construct()
    {
        $this->client_id = config('pdd_client_id');
        $this->client_secret = config('pdd_client_secret');
        if (empty($this->appKey) || empty($this->appKey)) {
            return false;
        }
    }

    /**
     * 获取数据
     * @param $params
     * @return bool
     */
    protected function result($params)
    {
        if (empty($this->type)) {
            return false;
        }
        // 合并参数
        $params = array_merge([
            'type' => $this->type,
            'client_id' => $this->client_id,
            'timestamp' => time()
        ],$params);
        $params['sign'] = $this->signature($params,$this->client_secret);

        $data = $this->curlPost($this->serverHost, $params);
        return json_decode($data,true);
    }
    /**
     * 生成签名
     * @param array $data
     * @param string $client_secret
     * @return bool|string
     */
    private function signature(array $data, $client_secret = '')
    {
        if (!$data || !$client_secret) {
            return false;
        }
        //签名步骤一：按首字母升序排列
        ksort($data);
        //签名步骤二：连接字符串
        $string = $this->toUrlParam($data);
        //签名步骤二：并在首尾加上client_secret
        $string = $client_secret.$string.$client_secret;
        //签名步骤三：MD5加密
        $string = md5($string);
        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 连接字符串
     * @param array $data
     * @return string
     */
    private function  toUrlParam(array $data)
    {
        $buff = "";
        foreach ($data as $k => $v) {
            if ($k != "client_secret" && $v != "" && !is_array($v)) {
                $buff .= $k . $v;
            }
        }
        return $buff;
    }

    /**
     * curl_post 发送
     * @param $urlRequest
     * @param $paramToSign
     * @return bool|string
     */
    private function curlPost($url, $curlPost)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $curlPost);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        return $data;
    }

    /**
     * @return mixed
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param mixed $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function getClientId()
    {
        return $this->client_id;
    }

    /**
     * @param mixed $client_id
     */
    public function setClientId($client_id)
    {
        $this->client_id = $client_id;
    }

    /**
     * @return mixed
     */
    public function getClientSecret()
    {
        return $this->client_secret;
    }

    /**
     * @param mixed $client_secret
     */
    public function setClientSecret($client_secret)
    {
        $this->client_secret = $client_secret;
    }
}
