<?php

namespace app\api\service;

use app\api\service\Pdd\Request;
use think\Db;


define("HOTAL_URL2", "https://dop-api.dfyoo.com");

class AirTicketService
{

    public $secretKey = "SJuVZzHQwnh6RRDLAI3b";
    public $apiKey = "243851_DomesticFlightNew";
    private $acctId = '159082';

    //航班查询接口
    function inquiry($params)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/inquiry";
        // 请求参数请根据业务员自行定义
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //舱位列表
    function cabinQuery($params)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/cabinQuery";
        // 请求参数请根据业务员自行定义
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //退改签查询
    function queryFareRemark()
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/queryFareRemark";
        // 请求参数请根据业务员自行定义
        $params = array(
            "fareBreakdownList" => [
                [
                    "baseFare" => 1599,
                    "psgType" => "ADT"
                ],
                [
                    "baseFare" => 1843,
                    "psgType" => "CHD"
                ]
            ],
            "cabinCodes" => "K#Z",
            "distributeId" => 224369,
            "flightNos" => "CA1479#CZ3735",
            "vendorId" => "100",
            "resId" => "",
            "specVendorId" => "",
            "priceInfoId" => "MTAjMjE=",
            "queryId" => ''
        );
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //验舱验价
    function checkCabinAndPrice($params)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/checkCabinAndPrice";
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //创建订单
    function addNewOrder($params)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/addNewOrder";
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //订单详情
    function getOrderDetail($params)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/getOrderDetail";
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        $resArr = json_decode($result, true);
        return $resArr;
    }

    //出票代扣接口
    function submitOrder($orderId, $pay)
    {
        $url = HOTAL_URL2 . "/DomesticFlight/interface/submitOrder";
        $params = [
            "payType" => 3,
            "orderId" => $orderId,
            "acctId" => $this->acctId,
            "pay" => $pay,
            "platform" => 30001
        ];
        $rest_data = $this->_formRequestParams($params, $this->secretKey, $this->apiKey);
        $result = $this->curlPost($url, $rest_data);
        Db::name('error_log')->insert([
            'source' => 'airTicketService/submitOrder',
            'desc' => $result,
            'order' => $orderId,
            'date' => date('Y-m-d H:i:s')
        ]);
        $resArr = json_decode($result, true);

        if ($resArr['success']) {
            return true;
        }
//        else {
//            Db::name('error_log')->insert([
//                'source' => 'airTicketService/submitOrder',
//                'desc' => json_encode($resArr, JSON_UNESCAPED_UNICODE),
//                'order' => $orderId,
//                'date' => date('Y-m-d H:i:s')
//            ]);
//        }
        return false;
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
                if ($value !== '') {
                    if (is_numeric($key) && $parentKey != null) {
                        $return[] = $parentKey . "=" . $value;
                    } else {
                        $return[] = $key . "=" . $value;
                    }
                }
            }
        }
    }

    // 发送curl
    public function curlPost($url, $data = null)
    {
        // echo "<br/>".$url."<br/>";
        // echo $data."<br/>";
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
}
