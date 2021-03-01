<?php
/**
 * Created by 七月
 * Author: 七月
 * 微信公号: 小楼昨夜又秋风
 * 知乎ID: 七月在夏天
 * Date: 2017/2/28
 * Time: 18:12
 */

namespace app\api\service;


use app\admin\service\OrderService;
use app\api\model\Order;
use app\api\model\Product;
// use app\api\service\Order as OrderService;
use app\lib\enum\OrderStatusEnum;
use app\lib\order\OrderStatus;
use think\Db;
use think\Exception;
use think\Loader;
use think\Log;

Loader::import('WxPay.WxPay', EXTEND_PATH, 'Api.php');

//Loader::import('WxPay.WxPay', EXTEND_PATH, '.Data.php');


class WxNotifyService extends \WxPayNotify
{
//    protected $data = <<<EOD
// <xml><appid><![CDATA[wxaaf1c852597e365b]]></appid>
// <bank_type><![CDATA[CFT]]></bank_type>
// <cash_fee><![CDATA[1]]></cash_fee>
// <fee_type><![CDATA[CNY]]></fee_type>
// <is_subscribe><![CDATA[N]]></is_subscribe>
// <mch_id><![CDATA[1392378802]]></mch_id>
// <nonce_str><![CDATA[k66j676kzd3tqq2sr3023ogeqrg4np9z]]></nonce_str>
// <openid><![CDATA[ojID50G-cjUsFMJ0PjgDXt9iqoOo]]></openid>
// <out_trade_no><![CDATA[A301089188132321]]></out_trade_no>
// <result_code><![CDATA[SUCCESS]]></result_code>
// <return_code><![CDATA[SUCCESS]]></return_code>
// <sign><![CDATA[944E2F9AF80204201177B91CEADD5AEC]]></sign>
// <time_end><![CDATA[20170301030852]]></time_end>
// <total_fee>1</total_fee>
// <trade_type><![CDATA[JSAPI]]></trade_type>
// <transaction_id><![CDATA[4004312001201703011727741547]]></transaction_id>
// </xml>
// EOD;

    public function NotifyProcess($data, &$msg)
    {
        // $data = $this->data;
        Log::record('123');
        Log::record($data);
        if ($data['result_code'] == 'SUCCESS') {
            $payId = $data['out_trade_no'];
            $uniacid = $data['attach'];
            Db::startTrans();
            try {
                $allOrder = Db::name('order')
                            ->field('id,pay_status,order_type')
                            ->where('payid',$payId)->select()->toArray();
                            Log::record('234');
                            Log::record($allOrder);
                $retConfig = Db::name('rebateConfig')->field('type,isConfirmPayStatus')->where('uniacid',$uniacid)->find();
                if(!empty($allOrder)){
                    foreach ($allOrder as $v) {
                        if($v['pay_status'] == 0){
                            $orderService = new OrderService($v['id'],$uniacid);
                            $arr = [
                                'pay_status' => 1,
                                'order_status' => 1,
                                'pay_type' => 'wechat',
                                'pay_time' => time(),
                            ];
                            $orderService->changeStatus($arr);
                            // 成为分销商
                            if($retConfig['type'] == 2 || $retConfig['type'] == 3){
                                if($retConfig['isConfirmPayStatus'] == 1){
                                    $orderService->becomeDis();
                                }
                            }
                            
                            if($v['order_type'] == 1){// 线下
                                $orderService->confirmOrder();// 直接确认订单, 走完流程
                            }
                            unset($orderService);
                        }
                    }
                }
                Db::commit();
            } catch (Exception $ex) {
                Db::rollback();
                Log::error($ex);
                // 如果出现异常，向微信返回false，请求重新发送通知
                return false;
            }
        }
        return true;
    }


    private function reduceStock($status)
    {
//        $pIDs = array_keys($status['pStatus']);
        foreach ($status['pStatusArray'] as $singlePStatus) {
            Product::where('id', '=', $singlePStatus['id'])
                ->setDec('stock', $singlePStatus['count']);
        }
    }

    private function updateOrderStatus($orderID, $success)
    {
        $status = $success ? OrderStatusEnum::PAID : OrderStatusEnum::PAID_BUT_OUT_OF;
        Order::where('id', '=', $orderID)
            ->update(['status' => $status]);
    }
}