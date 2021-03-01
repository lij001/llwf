<?php


namespace app\api\service\WxMiniPay;

use app\api\service\PaymentService;
use think\Db;

class WxMiniPayService extends common {
    /**
     * 构建微信支付
     * @return \Response
     */
    public function WxPay($input) {
        $openId = $input['openId'] ? $input['openId'] : null;
        $total_fee = $input['total_fee'] * 100;
        $order_id = $input['order_no'];
        $desc = $input['desc'] ? $input['desc'] : null;
        //判断金额是否为正整数
        if (!preg_match("/^[1-9][0-9]*$/", $total_fee)) {
            throw new \Exception('金额错误');
        }

        $data = [
            'out_trade_no' => $order_id,
            'total_fee' => $total_fee,
            'openid' => $openId,
            'body' => $desc,
            'notify_url' => url("api/pay/wechatNotify", '', false, true)
        ];
        //调用微信支付统一下单
        $result = $this->unifiedOrder($data);
        // 请求失败
        if (!$result) {
            //请求失败
            throw new \Exception('支付请求失败');
        }
        if ($result['return_code'] === 'FAIL' || $result['result_code'] === 'FAIL') {
//            throw new \Exception(json_encode($data,JSON_UNESCAPED_UNICODE).json_encode($result,JSON_UNESCAPED_UNICODE));
            //调用出错
            throw new \Exception('支付调用失败');
        }

        //调起支付数据签名字段
        $timeStamp = time();
        $appid = $result['appid'];
        $nonce_pay = str_shuffle($result['nonce_str']);//随机字符串
        $package = $result['prepay_id'];
        $signType = "MD5";
        $key = Common::KEY;
        $stringPay = "appId=" . $appid . "&nonceStr=" . $nonce_pay . "&package=prepay_id=" . $package . "&signType=" . $signType . "&timeStamp=" . $timeStamp . "&key=" . $key;
        $paySign = strtoupper(md5($stringPay));

        //这些参数需要返回给小程序组件使用，弹出支付页面
        $pay_data = array(
            'nonceStr' => $nonce_pay,
            'package' => "prepay_id=" . $package,
            'timeStamp' => (string)$timeStamp,
            'paySign' => $paySign,
            'signType' => $signType
        );
        return $pay_data;
    }


    /**
     * 小程序 统一下单方法
     * @param $data
     * array(
     * 'out_trade_no' => 商户订单号,
     * 'total_fee' => 总金额,
     * 'openid' => 用户标识,
     * 'body' => 商品描述,
     * )
     * @return bool|mixed
     */
    public function unifiedOrder(array $data) {
        $common = (new common());
        $params['appid'] = $common::APPID;
        $params['mch_id'] = $common::MCH_ID;
        $params['nonce_str'] = $common->GenRandomString();//随机字符串
        $params['body'] = $data['body'];//商品描述
        $params['out_trade_no'] = $data['out_trade_no'];//商户订单号
        $params['total_fee'] = $data['total_fee'];//总金额
        $params['spbill_create_ip'] = $_SERVER['REMOTE_ADDR'];//终端IP
        $params['notify_url'] = $data['notify_url'];//通知地址
        $params['trade_type'] = 'JSAPI';//交易类型
        $params['openid'] = $data['openid'];//用户标识
        //$this->params['detail']           = $data['detail'] ? null;//商品详情
        //$this->params['attach']           = $data['attach'] ? null;//附加数据
        ksort($params);
        //获取签名数据
        $sign = $common->Sign($params);
        $params['sign'] = $sign;//签名
        $xml = $common->DataToXml($params);
        $response = $common->PostXmlCurl($xml, 'https://api.mch.weixin.qq.com/pay/unifiedorder');
        if (!$response) {
            return false;
        }
        return $common->XmlToData($response);
    }
}
