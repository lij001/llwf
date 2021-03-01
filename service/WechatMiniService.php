<?php


namespace app\api\service;


use app\api\model\ConfigNew;
use app\api\service\WxMiniCrypt\wxBizDataCrypt;
use app\BaseService;
use think\Cache;
use think\Db;
use think\Exception;

class WechatMiniService extends BaseService {
    const CODE_SESSION = 'https://api.weixin.qq.com/sns/jscode2session';
    const PAY = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
    const USER_INFO = 'https://api.weixin.qq.com/wxa/getpaidunionid';
    const ACCESS_TOKEN = 'https://api.weixin.qq.com/cgi-bin/token';
    const MINI_ACCESS_TOKEN = 'https://api.weixin.qq.com/cgi-bin/token';
    const MINI_QE_CODE = 'https://api.weixin.qq.com/wxa/getwxacodeunlimit';
    protected $config;

    public function config() {
        $this->config = [
            'appid' => 'wx20edb06e9a7d5a21',
            'secret' => '12e7bab9f1defe6a005aff387db0b158',
            'grant_type' => 'authorization_code'
        ];
        return $this;
    }

    public function jsApiConfig() {
        $this->config = ConfigNew::getWechatJsApi();
        return $this;
    }

    protected function urlParamHandle($param) {
        $p = [];
        foreach ($param as $key => $val) {
            array_push($p, $key . '=' . $val);
        }
        $a = implode('&', $p);
        return $a;
    }

    private function curl($url, $data = null) {
        $ch = curl_init();                                      //初始化curl
        curl_setopt($ch, CURLOPT_URL, $url);                 //抓取指定网页
        curl_setopt($ch, CURLOPT_HEADER, 0);                    //设置header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            //要求结果为字符串
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, 1);                      //post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        //curl_setopt($ch, CURLOPT_HTTPHEADER,$header);           // 增加 HTTP Header（头）里的字段
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);        // 终止从服务端进行验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        $data = curl_exec($ch);
        //运行curl
        curl_close($ch);
        return $this->response($data);
    }


    private function curl2($url, $data = null) {
        $ch = curl_init();                                      //初始化curl
        curl_setopt($ch, CURLOPT_URL, $url);                 //抓取指定网页
        curl_setopt($ch, CURLOPT_HEADER, 0);                    //设置header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            //要求结果为字符串
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, 1);                      //post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        //curl_setopt($ch, CURLOPT_HTTPHEADER,$header);           // 增加 HTTP Header（头）里的字段
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);        // 终止从服务端进行验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        $data = curl_exec($ch);                        //运行curl
        curl_close($ch);
        return $this->response($data);
    }

    private function response($data) {
        if (strpos($data, '<xml>') !== false) {
            $obj = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);
            $data = json_encode($obj);
        }
        $response = json_decode($data, 1);
        if ($response === null) return $data;
        if (array_key_exists('errcode', $response)) {
            throw new \Exception('错误代码:' . $response['errcode'] . ',错误信息:' . $response['errmsg'], $response['errcode']);
        }
        return $response;
    }

    public function getOpenId() {
        $code = $this->getAccessCode();
        $accessToken = $this->getAccessToken($code);
        return $accessToken['openid'];

    }

    public function getUserInfo() {
        $code = $this->getAccessCode('snsapi_userinfo');
        if (Cache::get($code)) {
            $userInfo = json_decode(cache::get($code));
        } else {
            $userInfo = $this->code2Session($code);
            Cache::set($code, json_encode($userInfo, JSON_UNESCAPED_UNICODE));
        }
        return $userInfo;
    }

    protected function getAccessToken() {
        $config = $this->config;
        $param = [
            'appid' => $config['appid'],
            'secret' => $config['secret'],
            'grant_type' => 'client_credential',
        ];
        $param = $this->urlParamHandle($param);
        $url = self::ACCESS_TOKEN . '?' . $param;
        $response = $this->curl2($url);
        if (array_key_exists('errcode', $response)) {
            throw new \Exception('错误代码:' . $response['errcode'] . ',错误信息:' . $response['errmsg']);
        }
        return $response;
    }

    protected function code2Session($code) {
        $config = $this->config;
        $param = [
            'appid' => $config['appid'],
            'secret' => $config['secret'],
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ];
        $param = $this->urlParamHandle($param);
        $url = self::CODE_SESSION . '?' . $param;
        $response = $this->curl2($url);
        if (array_key_exists('errcode', $response)) {
            throw new \Exception('错误代码:' . $response['errcode'] . ',错误信息:' . $response['errmsg']);
        }
        return $response;
    }

    /**
     * @param string $scope 只获取openid:snsapi_base 获取详细信息:snsapi_userinfo
     * @throws \Exception
     */
    public function getAccessCode($scope = 'snsapi_base') {
        $code = request()->param('code', null);
        return $code;
    }

    protected function getSign($sign) {
        ksort($sign);
        $signStr = $this->urlParamHandle($sign);
        $signStr .= '&key=' . $this->config['signKey'];
        return strtoupper(md5($signStr));
    }

    protected function getXml($param) {
        $xml = ['<xml>'];
        foreach ($param as $key => $val) {
            $xml[] = "<$key>$val</$key>";
        }
        $xml [] = '</xml>';
        return implode('', $xml);
    }

    private function JsApiPayResponse($response) {
        $response['timeStamp'] = time();
        $sign = [
            'appId' => $response['appid'],
            'timeStamp' => time(),
            'nonceStr' => $response['nonce_str'],
            'package' => 'prepay_id=' . $response['prepay_id'],
            'signType' => 'MD5'
        ];
        $sign['paySign'] = $this->getSign($sign);
        return $sign;
    }

    public function JsApiPay($orderNo, $title, $money, $notify_url) {
        $this->jsApiConfig();
        $weChat = $this->getUserInfo();
        $nonce_str = substr($orderNo, 0, 8);
        $ip = request()->ip();
        $param = [
            'appid' => $this->config['appid'],
            'mch_id' => $this->config['mchid'],
            'nonce_str' => $nonce_str,
            'body' => $title,
            'out_trade_no' => $orderNo,
            'total_fee' => (int)($money * 100),
            'spbill_create_ip' => $ip,
            'notify_url' => $notify_url,
            'trade_type' => 'JSAPI',
            'openid' => $weChat['openid']
        ];
        $param['sign'] = $this->getSign($param);
        $response = $this->curl(self::PAY, $this->getXml($param));
        return $this->JsApiPayResponse($response);
    }

    public function getUnionid($param, $key) {
        if (empty($key)) throw new Exception('缺少key');
        $crypt = new wxBizDataCrypt($this->config['appid'], $key);
        $errCode = $crypt->decryptData($param['encryptedData'], $param['iv'], $data);
        if ($errCode != 0) throw new Exception($data);
        return json_decode($data, true);
    }

    public function miniProgramQr($param) {
        if (empty($param['scene'])) throw new \Exception('scene不能为空!');
        $accessToken = $this->getMiniAccessToken();
        $param = [
            'scene' => $param['scene'],
            'page' => $param['page'] ?: 'pages/index/index',
            'width' => $param['width'] ?: 200
        ];
        $url = self::MINI_QE_CODE . '?access_token=' . $accessToken;
        $path = null;
        try {
            $buffer = $this->curl($url, json_encode($param));
            $saveName = sprintf('%.0f', microtime(true) * 1000);
            $path = 'upload/share_goods/' . $saveName . '.png';
            file_put_contents($path, $buffer);
        } catch (\Exception $e) {
            if ($e->getCode() == 40001) {
                $this->getMiniAccessToken(false);
                $path = $this->miniProgramQr($param);
            }
        }
        return $path;
    }

    private function getMiniAccessToken($cached = true) {
        $key = 'MiniAccessToken';
        $MiniAccessToken = null;
        if ($cached) {
            $MiniAccessToken = Cache::get($key);
        }
        if (!$MiniAccessToken) {
            $param = [
                'grant_type' => 'client_credential',
                'appid' => $this->config['appid'],
                'secret' => $this->config['secret']
            ];
            $url = self::MINI_ACCESS_TOKEN . '?' . $this->urlParamHandle($param);
            $MiniAccessToken = $this->curl($url);
            Cache::set($key, json_encode($MiniAccessToken, JSON_UNESCAPED_UNICODE), $MiniAccessToken['expires_in']);
        } else {
            $MiniAccessToken = json_decode($MiniAccessToken, 1);
        }
        return $MiniAccessToken['access_token'];
    }
}
