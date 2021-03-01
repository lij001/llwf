<?php

/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/5/25
 * Time: 11:38
 */

namespace app\api\service;

use think\Db;

define("FLURL2", "http://openapi.fulu.com/api/getway");

class RechargeSevice
{
    public $url = "https://syc.boosoo.com.cn";
    public $app_id = "100001";
    public $app_key = "4b1a3e282c3195274fdd7c34730c66a2";

    private function curlPost($url, $data = null)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json;charset='utf-8'"]);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data); //需要json数组
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    private function getUrl($prame, $url2)
    {
        $str = "";
        $i = 0;
        foreach ($prame as $k => $v) {
            if ($i == 0) {
                $str .= $k . "=" . $v;
            } else {
                $str .= "&" . $k . "=" . $v;
            }
            $i++;
        }
        $thisUrl = $this->url . $url2 . "?" . $str;
        return $thisUrl;
    }

    public function getResult($prame, $url2)
    {
        $thisUrl = $this->getUrl($prame, $url2);
        $result =  $this->curlPost($thisUrl);
        return $result;
    }

    //1.	检测手机号码及金额是否能充值
    public function telcheck($cardnum, $phoneno)
    {
        $url2 = "/api/mobile/telcheck";
        $prame = [
            'cardnum' => $cardnum,
            'phoneno' => $phoneno,
            'app_id' => $this->app_id
        ];
        $result = $this->getResult($prame, $url2);
        $resArr = (array) json_decode($result);
        return $resArr;
    }

    //2.	账户余额查询
    public function yue()
    {
        $url2 = "/api/mobile/yue";
        $timestamp = time();
        $prame = [
            'timestamp' => $timestamp,
            'app_id' => $this->app_id,
            'sign' => md5($this->app_id . $this->app_key . $timestamp)
        ];
        $result = $this->getResult($prame, $url2);
        $resArr = (array) json_decode($result);
        $resArr['result'] = (array) $resArr['result'];
        return $resArr;
    }

    //可交易检查
    public function makeCheck($cardnum, $phoneno)
    {
        $resArr = $this->telcheck($cardnum, $phoneno);
        if ($resArr['msg'] == "允许充值的手机号码及金额") {
            return $resArr;
        }
        $resArr = $this->yue();
        if ($resArr['result']['money'] <= 0) {
            return array("code" => 100, "msg" => "系统繁忙,暂时无法提交订单,敬请谅解", "result" => "");
        }
        return array("code" => 0, "msg" => "success", "result" => "");
    }

    // 3.	手机充值提交接口
    public function telorder($phoneno, $cardnum)
    {
        $url2 = "/api/mobile/telorder";
        $timestamp = time();
        $ordersn = date("Ymd", $timestamp) . rand(10000, 99999);
        $prame = [
            'app_id' => $this->app_id,
            'phoneno' => $phoneno,
            'cardnum' => $cardnum,
            'ordersn' => $ordersn,
            'sign' => md5($this->app_id . $this->app_key . $phoneno . $cardnum . $ordersn)
        ];
        $str = "";
        $i = 0;
        foreach ($prame as $k => $v) {
            if ($i == 0) {
                $str .= $k . "=" . $v;
            } else {
                $str .= "&" . $k . "=" . $v;
            }
            $i++;
        }
        $thisUrl = $this->url . $url2 . "?" . $str;
        $result =  $this->curlPost($thisUrl);
        $result = (array) json_decode($result);
        return $result;
    }

    // 3.	手机充值提交接口
    // gas_moible 持卡人手机号
    // gas_userid 加油卡卡号
    // gas_name 持卡人姓名
    public function onlineorder($gas_moible, $gas_userid, $gas_name, $cardnum)
    {
        $url2 = "/api/sinopec/onlineorder";
        $timestamp = time();
        $ordersn = date("Ymd", $timestamp) . rand(10000, 99999);
        $prame = [
            'app_id' => $this->app_id,
            'gas_userid' => $gas_userid,
            'gas_moible' => $gas_moible,
            'gas_name' => $gas_name,
            'cardnum' => $cardnum,
            'ordersn' => $ordersn,
            'sign' => md5($this->app_id . $this->app_key . $gas_userid . $ordersn . $cardnum . $gas_moible)
        ];
        $str = "";
        $i = 0;
        foreach ($prame as $k => $v) {
            if ($i == 0) {
                $str .= $k . "=" . $v;
            } else {
                $str .= "&" . $k . "=" . $v;
            }
            $i++;
        }
        $thisUrl = $this->url . $url2 . "?" . $str;
        $result =  $this->curlPost($thisUrl);
        $result = (array) json_decode($result);
        return $result;
    }

    // 4.	订单状态查询
    public function ordersta($ordersn)
    {
        $url2 = "/api/mobile/ordersta";
        $timestamp = time();
        $ordersn = date("Ymd", $timestamp) . rand(10000, 99999);
        $prame = [
            'app_id' => $this->app_id,
            'ordersn' => $ordersn,
            'sign' => md5($this->app_id . $this->app_key . $ordersn)
        ];
        $str = "";
        $i = 0;
        foreach ($prame as $k => $v) {
            if ($i == 0) {
                $str .= $k . "=" . $v;
            } else {
                $str .= "&" . $k . "=" . $v;
            }
            $i++;
        }
        $thisUrl = $this->url . $url2 . "?" . $str;
        $result =  $this->curlPost($thisUrl);
        var_dump($result);
    }

    //创建订单
    public function submitOrder($type_id, $pay_amount, $user_num, $uid, $gas_userid = null, $gas_name = null, $address_id = null, $card_type = null)
    {
        $total_gxz = 0;
        $time = time();
        if ($type_id == 1) {
            $order_num = "HF" . $uid . date('YmdHis', $time) . randStr(5);
            $prame = [
                'uid' => $uid,
                'type_id' => $type_id,
                'pay_amount' => $pay_amount,
                'order_num' => $order_num,
                'total_gxz' => $pay_amount,
                'user_num' => $user_num,
                'state' => 0,
                'pay_state' => 0,
                'creat_time' => date('Y-m-d H:i:s', $time),
            ];
        } else if ($type_id == 2) {
            $order_num = "YK" . $uid . date('YmdHis', $time) . randStr(5);
            $config = Db::name('recharge_config')->where('id', 1)->find();
            if ($card_type == 'zsy') {
                $total_gxz = $pay_amount * $config['zsy_gxz_rate'];
            } else if ($card_type == 'zsh') {
                $total_gxz = $pay_amount * $config['zsh_gxz_rate'];
            }
            $member = Db::name('members')->where('id', $uid)->find();
            if (empty($user_num)) {
                $user_num = $member['mobile'];
            }
            if (empty($gas_name) && $member['realname'] != null) {
                $gas_name = $member['realname'];
            }
            $prame = [
                'uid' => $uid,
                'type_id' => $type_id,
                'pay_amount' => $pay_amount,
                'order_num' => $order_num,
                'total_gxz' => $total_gxz,
                'user_num' => $user_num,
                'gas_userid' => $gas_userid,
                'gas_name' => $gas_name,
                'state' => 0,
                'pay_state' => 0,
                'creat_time' => date('Y-m-d H:i:s', $time),
            ];
        } else if ($type_id == 3) {
            $order_num = "NK" . $uid . date('YmdHis', $time) . randStr(5);
            $config = Db::name('recharge_config')->where('id', 1)->find();
            if ($card_type == 'zsy') {
                $total_gxz = ($pay_amount - 50) * $config['zsy_gxz_rate'];
            } else if ($card_type == 'zsh') {
                $total_gxz = ($pay_amount - 50) * $config['zsh_gxz_rate'];
            }
            $prame = [
                'uid' => $uid,
                'type_id' => $type_id,
                'pay_amount' => $pay_amount,
                'order_num' => $order_num,
                'total_gxz' => $total_gxz,
                'address_id' => $address_id,
                'state' => 0,
                'pay_state' => 0,
                'creat_time' => date('Y-m-d H:i:s', $time),
                'card_type' => $card_type,
            ];
        }

        try {
            $result = Db::name("recharge_order")->insert($prame);
            if ($result) {
                return $order_num;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    // 福禄充值
    public $AppSecret = 'eb3146ce63134759ace4aff486c62f97';
    public $appKey = '9W+oGkwOMbg6UUcgIFAwuPQdC3RFILS7fY7/E6DFAOgveFTp4x1ndipcEf27dM07';
    /**
     * 充值订单
     */
    function phoneChargeOrder($phone, $value)
    {
        $time = time();
        $timestamp = date('Y-m-d H:i:s', $time);
        $orderTime = date('YmdHis', $time);
        $orderNo = "TC" . $orderTime . round(100000, 999999);
        $bizContent = [
            "customer_order_no" => $orderNo,
            "charge_phone" => $phone,
            "charge_value" => $value
        ];
        $baseData = [
            "app_key" => $this->appKey,
            "method" => "fulu.order.mobile.add",
            "timestamp" => $timestamp,
            "version" => "2.0",
            "format" => "json",
            "charset" => "utf-8",
            "sign_type" => "md5",
            "app_auth_token" => "",
            "biz_content" => json_encode($bizContent)
        ];
        $sign = $this->getSign($baseData);
        $baseData['sign'] = $sign;
        // echo "报文:<br/>";
        // var_dump(json_encode($baseData));
        // echo "<br/>";
        $result = json_decode($this->curlPost2(FLURL2, json_encode($baseData)), true);
        // echo "返回:<br/>";
        // var_dump($result);
        // echo "<br/>";
        return $result;
    }

    /**
     * php签名方法
     */
    public function getSign($Parameters)
    {
        //签名步骤一：把字典json序列化
        $json = json_encode($Parameters, 320);
        //签名步骤二：转化为数组
        $jsonArr = $this->mb_str_split($json);
        //签名步骤三：排序
        sort($jsonArr);
        //签名步骤四：转化为字符串
        $string = implode('', $jsonArr);
        //签名步骤五：在string后加入secret
        $string = $string . $this->AppSecret;
        //签名步骤六：MD5加密
        $result_ = strtolower(md5($string));
        return $result_;
    }

    /**
     * 可将字符串中中文拆分成字符数组
     */
    function mb_str_split($str)
    {
        return preg_split('/(?<!^)(?!$)/u', $str);
    }

    // 发送curl
    public function curlPost2($url, $data = null)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json;"]);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data); //需要json数组
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * 聚合数据接口
     */
    //归属地查找
    function JHgetPhoneInfo($phone)
    {
        header('Content-type:text/html;charset=utf-8');
        $apiurl = 'http://apis.juhe.cn/mobile/get';
        $params = array(
            'key' => '55e58cb6b4fc296b389f39ce573a63fb', //您申请的手机号码归属地查询接口的appkey
            'phone' => $phone //要查询的手机号码
        );
        $paramsString = http_build_query($params);
        $content = @file_get_contents($apiurl . '?' . $paramsString);
        $result = json_decode($content, true);
        return $result;
    }

    private $JHappkey = 'd0c393c1fe256dd55f80684c3b4254a6'; //从聚合申请的话费充值appkey

    private $JHopenid = 'JHe2a6463cb38731776770e6d01914916c'; //注册聚合账号就会分配的openid，在个人中心可以查看

    private $telCheckUrl = 'http://op.juhe.cn/ofpay/mobile/telcheck';

    private $telQueryUrl = 'http://op.juhe.cn/ofpay/mobile/telquery';

    private $submitUrl = 'http://op.juhe.cn/ofpay/mobile/onlineorder';

    private $staUrl = 'http://op.juhe.cn/ofpay/mobile/ordersta';

    /**
     * 根据手机号码及面额查询是否支持充值
     * @param  string $mobile   [手机号码]
     * @param  int $pervalue [充值金额]
     * @return  boolean
     */
    public function JHtelcheck($mobile, $pervalue)
    {
        $params = 'key=' . $this->JHappkey . '&phoneno=' . $mobile . '&cardnum=' . $pervalue;
        $content = $this->juhecurl($this->telCheckUrl, $params);
        $result = $this->_returnArray($content);
        if ($result['error_code'] == '0') {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 根据手机号码和面额获取商品信息
     * @param  string $mobile   [手机号码]
     * @param  int $pervalue [充值金额]
     * @return  array
     */
    public function telquery($mobile, $pervalue)
    {
        $params = 'key=' . $this->JHappkey . '&phoneno=' . $mobile . '&cardnum=' . $pervalue;
        $content = $this->juhecurl($this->telQueryUrl, $params);
        return $this->_returnArray($content);
    }

    /**
     * 提交话费充值
     * @param  [string] $mobile   [手机号码]
     * @param  [int] $pervalue [充值面额]
     * @param  [string] $orderid  [自定义单号]
     * @return  [array]
     */
    public function telcz($mobile, $pervalue, $orderid)
    {
        $sign = md5($this->JHopenid . $this->JHappkey . $mobile . $pervalue . $orderid); //校验值计算
        $params = array(
            'key' => $this->JHappkey,
            'phoneno'   => $mobile,
            'cardnum'   => $pervalue,
            'orderid'   => $orderid,
            'sign' => $sign
        );
        $content = $this->juhecurl($this->submitUrl, $params, 1);
        return $this->_returnArray($content);
    }

    /**
     * 查询订单的充值状态
     * @param  [string] $orderid [自定义单号]
     * @return  [array]
     */
    public function sta($orderid)
    {
        $params = 'key=' . $this->JHappkey . '&orderid=' . $orderid;
        $content = $this->juhecurl($this->staUrl, $params);
        return $this->_returnArray($content);
    }

    /**
     * 将JSON内容转为数据，并返回
     * @param string $content [内容]
     * @return array
     */
    public function _returnArray($content)
    {
        return json_decode($content, true);
    }

    /**
     * 请求接口返回内容
     * @param  string $url [请求的URL地址]
     * @param  string $params [请求的参数]
     * @param  int $ipost [是否采用POST形式]
     * @return  string
     */
    public function juhecurl($url, $params = false, $ispost = 0)
    {
        $httpInfo = array();
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'JuheData');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($ispost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_URL, $url);
        } else {
            if ($params) {
                curl_setopt($ch, CURLOPT_URL, $url . '?' . $params);
            } else {
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        }
        $response = curl_exec($ch);
        if ($response === FALSE) {
            //echo "cURL Error: " . curl_error($ch);
            return false;
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $httpInfo = array_merge($httpInfo, curl_getinfo($ch));
        curl_close($ch);
        return $response;
    }
}
