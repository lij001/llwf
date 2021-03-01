<?php

namespace app\api\service;

use think\Db;


//define("HOTAL_URL2", "https://dop-api.dfyoo.com");

class HotelDataService
{

    public $secretKey = "TnDIhp8EAiTP5gbtmWsG";
    public $apiKey = "243851_DomesticHotel";
    private $acctId = '159082';

    const HOTAL_URL2 = 'https://dop-api.dfyoo.com';


    //酒店付款(代扣)接口
    public function hotelSubmitOrder($payType, $orderId, $pay)
    {
        $url = self::HOTAL_URL2 . "/DomesticHotel/submitOrder";
        // 请求参数请根据业务员自行定义
        $params = array(
            "payType" => $payType,
            "orderId" => $orderId,
            "acctId" => $this->acctId,
            "pay" => $pay,
            "platform" => '30001'
        );
        $resArr = $this->getPostData($url, $params);
        Db::name('test')->insert(['param' => '酒店代扣(' . $orderId . '):' . json_encode($resArr, JSON_UNESCAPED_UNICODE), 'time' => date('Y-m-d H:i:s')]);
        if (!$resArr['success']) {
            for ($i = 0; $i < 3; $i++) {
                $res = $this->hotelOrderInfo($orderId);
                if ($res['success'] && $res['orderInfo']['orderStatus'] == '待付款') {
                    $resArr = $this->getPostData($url, $params);
                    if ($resArr['success'] && $resArr['data']['outTradeNo']) {
                        break;
                    }
                }
            }
        }
        if ($resArr['success'] && $resArr['data']['outTradeNo']) {
            Db::name('hotel_order')->where('orderId', $orderId)->update(['outTradeNo' => $resArr['data']['outTradeNo']]);
            return true;
        } else {
            return false;
        }
    }

    //酒店订单详情接口
    public function hotelOrderInfo($orderId)
    {
        $url = self::HOTAL_URL2 . "/DomesticHotel/query/orderDetail";
        // 请求参数请根据业务员自行定义
        $params = array(
            "orderId" => $orderId,
            "acctId" => $this->acctId
        );
        return $this->repeatGetPostData($url, $params);
    }

    //付款(代扣)接口不要调用这个方法.这个方法会递归请求.
    protected function repeatGetPostData($url, &$data)
    {

        $rest_data = $this->_formRequestParams($data, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        if ($resArr['errorCode'] == 231099) {
            $resArr = $this->repeatGetPostData($url, $data);
        }
        return $resArr;
    }

    //发送post数据
    protected function getPostData($url, $data)
    {
        $rest_data = $this->_formRequestParams($data, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        return json_decode($result, true);
    }

    //组装请求入参
    public function _formRequestParams($data, $secretKey, $apiKey)
    {
        if (empty($data)) {
            return null;
        }
        //组装临时参数
        $tmp["apiKey"] = $apiKey;
        $tmp['data'] = $data;
        $tmp['timestamp'] = date("Y-m-d H:i:s");
        $signKey = $this->getSign($tmp, $secretKey);
        $tmp["sign"] = $signKey;
        return json_encode($tmp);
    }

    //获取签名秘钥算法
    public function getSign($param, $secretKey)
    {
        if (empty($secretKey)) {
            return null;
        }
        //忽略签名
        if (!empty($param["sign"])) {
            unset($param["sign"]);
        }
        //递归获取json结构中的键值对，组合键值并保存到列表中

        $this->propertyFilter($param, $keyParams);
        //根据键值排序（升序）
        asort($keyParams, SORT_NATURAL);
        //分割数组
        $formatText = implode('&', $keyParams);
        //在首尾加上秘钥
        $finalText = $secretKey . "&" . $formatText . "&" . $secretKey;
        //MD5加密
        return strtoupper(md5($finalText));
    }

    // 递归获取键值对
    public function propertyFilter($input, &$return, $parentKey = null)
    {
        if (!is_array($input)) {
            return null;
        }
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $this->propertyFilter($value, $return, $key);
            } else {
                if (is_numeric($key) && $parentKey != null) {
                    $return[] = $parentKey . "=" . $value;
                } else {
                    $return[] = $key . "=" . $value;
                }
            }
        }
    }

    // 发送curl
    public function curlPost($url, $data = null)
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

    //酒店列表
    public function hotelHotelListSelect($cityName, $checkInDate, $checkOutDate, $start, $star, $price, $sortKey, $keywords)
    {
        $limit = 100;
        $url = self::HOTAL_URL2 . "/DomesticHotel/query/hotelList";
        // 请求参数请根据业务员自行定义
        $params = array(
            "filter" => [
                "star" => $star,
                "price" => [
                    $price
                ],
            ],
            "sortKey" => $sortKey,
            "checkOutDate" => $checkOutDate,
            "cityName" => $cityName,
            "start" => $start,
            "limit" => $limit,
            "checkInDate" => $checkInDate,
            "returnFilter" => 0
        );
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        if (empty($resArr['data']['hotelList'])) {
            return false;
        }
        $hotelList = [];
        foreach ($resArr['data']['hotelList'] as $v) {
            if (strpos($v['chineseName'], $keywords) !== false) {
                $hotelList[] = $v;
            }
        }
        $start += $limit;
        $return = $this->hotelHotelListSelect($cityName, $checkInDate, $checkOutDate, $start, $star, $price, $sortKey, $keywords);
        if (!empty($return) && $return != false) {
            foreach ($return as $v) {
                $hotelList[] = $v;
            }
        }
        return $hotelList;
    }
}
