<?php
/**
 * Created by 七月.
 * Author: 七月
 * 微信公号：小楼昨夜又秋风
 * 知乎ID: 七月在夏天
 * Date: 2017/2/26
 * Time: 16:02
 */

namespace app\api\service;


use app\api\model\OrderModel;
use app\lib\exception\OrderException;
use app\lib\exception\TokenException;
use think\Exception;
use think\Loader;
use think\Log;
use think\Db;

Loader::import('WxPay.WxPayApi');
class PayService
{
    private $orderNo;
    private $payId;
//    private $orderModel;

    function __construct($payId)
    {
        if (!$payId)
        {
            throw new Exception('支付订单号不允许为NULL');
        }
        $this->payId = $payId;
    }

    public function pay()
    {
        $data = $this->checkOrderValid();// 验证订单是否已支付,验证金额
        // $order = new OrderService();
        // $status = $order->checkOrderStock($this->orderID);
        // if (!$status['pass'])
        // {
            // return $status;
        // }
        return $this->makeWxPreOrder($data);
        //        $this->checkProductStock();
    }

    // 构建微信支付订单信息
    private function makeWxPreOrder($param)
    {
        $openid = TokenService::getCurrentTokenVar('openid');

        if (!$openid)
        {
            throw new TokenException();
        }
        $wxOrderData = new \WxPayUnifiedOrder();
        $wxOrderData->SetOut_trade_no($this->payId);
        $wxOrderData->SetTrade_type('JSAPI');
        $wxOrderData->SetTotal_fee($param['totalPrice'] * 100);
        $wxOrderData->SetBody('小程序商城');
        $wxOrderData->SetAttach($param['uniacid']);
        $wxOrderData->SetOpenid($openid);
        $wxOrderData->SetNotify_url(config('pay_back_url').'/uniacid/'.UNIACID);

        return $this->getPaySignature($wxOrderData);
    }

    //向微信请求订单号并生成签名
    private function getPaySignature($wxOrderData)
    {
        $api = new \WxPayApi(UNIACID);// 改
        $wxOrder = $api::unifiedOrder($wxOrderData);
        // $wxOrder = \WxPayApi::unifiedOrder($wxOrderData);  // old
		// dump($wxOrder);
        // 失败时不会返回result_code
        if($wxOrder['return_code'] != 'SUCCESS' || $wxOrder['result_code'] !='SUCCESS'){
            Log::record($wxOrder,'error');
            Log::record('获取预支付订单失败','error');
//            throw new Exception('获取预支付订单失败');
        }
        $this->recordPreOrder($wxOrder);
        $signature = $this->sign($wxOrder);
        return $signature;
    }

    private function recordPreOrder($wxOrder){
        // 必须是update，每次用户取消支付后再次对同一订单支付，prepay_id是不同的
        OrderModel::where('payid', '=', $this->payId)
            ->update(['prepay_id' => $wxOrder['prepay_id']]);
    }

    // 签名
    private function sign($wxOrder)
    {
        $config = Db::name('config')->field('appid,app_secret')->where('uniacid',UNIACID)->find();

        $jsApiPayData = new \WxPayJsApiPay();
        $jsApiPayData->SetAppid($config['appid']);
        $jsApiPayData->SetTimeStamp((string)time());
        $rand = md5(time() . mt_rand(0, 1000));
        $jsApiPayData->SetNonceStr($rand);
        $jsApiPayData->SetPackage('prepay_id=' . $wxOrder['prepay_id']);
        $jsApiPayData->SetSignType('md5');
        $sign = $jsApiPayData->MakeSign();
        $rawValues = $jsApiPayData->GetValues();
        $rawValues['paySign'] = $sign;
        unset($rawValues['appId']);
        return $rawValues;
    }

    /**
     * @return bool
     * @throws OrderException
     * @throws TokenException
     */
    private function checkOrderValidOld()
    {
        $order = OrderModel::where('id', '=', $this->orderID)
            ->select();
        if (!$order)
        {
            throw new OrderException();
        }
        // $currentUid = Token::getCurrentUid();
        if(!TokenService::isValidOperate($order->user_id))
        {
            throw new TokenException(
                [
                    'msg' => '订单与用户不匹配',
                    'errorCode' => 10003
                ]);
        }
        if($order->status != 0){
            throw new OrderException([
                'msg' => '订单已支付过啦',
                 'errorCode' => 80003,
                'code' => 400
            ]);
        }
        // $this->orderNo = $order->order_no;
        return true;
    }

    /**
     * @return payPrice
     * @throws OrderException
     * @throws TokenException
     */
    public function checkOrderValid()
    {
        $allOrder = OrderModel::field('id,uniacid,user_id,order_no,pay_status,pay_price')
                ->where('payid', '=', $this->payId)
                ->select()->toArray();
        if (!$allOrder)
        {
            throw new OrderException();
        }
        // $currentUid = Token::getCurrentUid();
        $totalPrice = 0;
        $uniacid = $allOrder[0]['uniacid'];
        foreach ($allOrder as $order) {
            // dump($order);
            if(!TokenService::isValidOperate($order['user_id']))
            {
                throw new TokenException(
                    [
                        'msg' => '订单与用户不匹配',
                        'errorCode' => 10003
                    ]);
            }
            if($order['pay_status'] != 0){
                throw new OrderException([
                    'msg' => '订单已支付过啦',
                    'errorCode' => 80003,
                    'code' => 400
                ]);
            }
            $totalPrice += $order['pay_price'];
        }
        // $this->orderNo = $order->order_no;
        return array(
            'totalPrice' => $totalPrice,
            'totalPrice' => 0.01,
            'uniacid' => $uniacid
            );
    }

    // 企业付款
    public function MerchPay()
    {

    }
}
