<?php


namespace app\api\service;


use app\api\model\Seckill\SeckillOrder;
use app\api\service\Alibaba\AlibabaService;
use app\api\service\Award\AwardApiServer;
use app\api\service\Seckill\SeckillOrderServer;
use think\Db;

abstract class PayNotifyService {
    public $data;
    protected $payId;
    protected $rights;
    protected $pay_type;

    abstract public function check();

    public function __construct($data) {
        $this->data = $data;
    }

    private function orderTagLength($payId) {
        $i = -1;
        do {
            ++$i;
        } while (!is_numeric($payId[$i]));
        return $i;
    }

    protected function doBack() {
        $this->rights = new RightsService();
        $this->payId = $this->data['out_trade_no'];
        try {
            Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => date('Y-m-d H:i:s'), 'pay_type' => $this->pay_type]);
            Db::name('pay_log')->insert(['payId' => $this->payId, 'type' => 1, 'datetime' => date('Y-m-d H:i:s', time())]);
            Db::startTrans();
            $fn = strtoupper(substr($this->payId, 0, $this->orderTagLength($this->payId)));
            switch ($fn) {
                case 'YK':
                case 'NK':
                    $this->YK_NK();
                    break;
                default:
                    $this->$fn();
            }
            Db::commit();
            $uid = Db::name('pay_info')->where('order_no', $this->payId)->value('uid');
            UpgradeService::objectInit()->userUpgradeCheck($uid);
        } catch (\Exception $e) {
            Db::rollback();
            $data = [];
            $data['order_no'] = $this->payId;
            $data['time'] = time();
            $data['msg'] = $e->getMessage();
            $data['param'] = json_encode($data, JSON_UNESCAPED_UNICODE);
            Db::name('pay_callback_log')->insert($data);
        }
        return true;
    }

    protected function WM() {
        $order = Db::name("order_info")->where("order_no", $this->payId)->find();
        if (!empty($order) && intval($order['status']) == 2) {
            $finish_time = date('Y-m-d H:i:s', time());
            $re2 = Db::name('order_info')->where("order_no", $this->payId)->update(['status' => 3, 'finish_time' => $finish_time]);
            if ($re2) {
                if ($order['order_type'] == 2) {
                    Db::name('user_balance')->where('uid', $order['uid'])->update([
                        'rights_balance' => 0
                    ]);
                    $amount = Db::name('order_info')->where('order_no', $this->payId)->value('amount');
                    $num = floor($amount / 499);
                    $reward = RewardService::objectInit();
                    $reward->giveLibaoReward($order['uid'], $this->payId, $num);
                }
                //店铺款预估
                if ($order['spid'] > 0 && $order['amount'] > 0) {
                    $shop = Db::name('shop')->field('uid,title')->where('id', $order['spid'])->find();
                    $mobile = Db::name('members')->where('id', $shop['uid'])->value('mobile');
                    if ($mobile && $shop['title']) {
                        send_sms_aliyun_new($mobile, ['shopname' => $shop['title']], 'SMS_199221015');
                    }
                }
                return true;
            } else {
                //主动抛出异常
                throw  new \Exception('order_info或pay_info更新失败!');
            }
        }
        throw new \Exception('订单未找到或订单状态不是待付款');
    }

    protected function PW() {
        $orderlist = Db::name("order_info")->where("parent_no", $this->payId)->field('spid,amount,uid,order_no,status,order_type')->select()->toArray();
        if (!empty($orderlist)) {
            $finish_time = date('Y-m-d H:i:s', time());
            foreach ($orderlist as $or) {
                if (intval($or['status']) != 2) {
                    throw new \Exception('订单状态不是待付款');
                }
                $re2 = Db::name('order_info')->where("parent_no", $this->payId)->update(['status' => 3, 'finish_time' => $finish_time]);
                if ($re2) {
                    //升级权益校验
                    $this->rights->estimate_check($or['uid'], $or['order_type'], $or['order_no']);
                    //店铺款预估
                    if ($or['spid'] > 0 && $or['amount'] > 0) {
                        $shopService = new ShopService();
                        $shopService->ygShopBalance($or['spid'], $or['amount'], $or['order_no']);
                    }
                } else {
                    throw new \Exception('order_info或pay_info更新失败!');
                }
            }
            return true;
        }
        throw new \Exception('未找到订单');
    }

    protected function OF() {
        $order = Db::name("order_offline")->where("order_no", $this->payId)->find();
        if (!empty($order) && intval($order['status']) == 2) {
            $finish_time = date('Y-m-d H:i:s', time());
            $re2 = Db::name('order_offline')->where("order_no", $this->payId)->update(['status' => 1, 'finish_time' => $finish_time]);
            if ($re2) {
                //店铺款
                $shop_flag = true;
                if ($order['spid'] > 0) {
                    $shopService = new ShopService();
                    $shop_flag = $shopService->shopBalance($order['spid'], $order['total'], $this->payId);
                }
                //推荐奖励
                $right_flag = true;
                if ($order['spid'] > 0 && $order['total'] > 0) {
                    $right_flag = $this->rights->shopRights($order['uid'], $this->payId, $order['total'], $order['spid']);
                }
                //线下贡献值
                if ($order['sp_gxz'] > 0) {
//                    $buyer_gxz = $order['sp_gxz'];
                    //发放贡献值
                    $this->rights->giveGxz($order['uid'], $order['total'], '线下支付', 1, $this->payId);

                    //店铺贡献值增加
                    if ($order['spid'] > 0) {
                        $shop_cal = Db::name('shop')->where('id', $order['spid'])->field('rate,uid')->find();
                        $shop_gxz = bcmul($order['total'], ($shop_cal['rate'] / 100), 2);

                        $this->rights->giveGxz($shop_cal['uid'], $shop_gxz, '店铺收益', 1, $this->payId);
                    }
                }
                //运营中心
                if ($order['spid'] > 0 && $order['total'] > 0) {
                    $oc_area_id = Db::name('shop')->where('id', $order['spid'])->value('area_id');

                    $this->rights->ocRight(3, $this->payId, $oc_area_id, $order['total'], $order['spid']);
                }
                //团队消费收益

                $this->rights->teamRights($order['uid'], $this->payId, $order['total'], $order['spid']);
                if ($shop_flag && $right_flag) {

                    $spUid = Db::name('shop')->where('id', $order['spid'])->value('uid');
                    $mobile = Db::name('members')->where('id', $spUid)->value('mobile');
                    sendMsgForShoper($mobile, '线下', $order['total']);
                    return true;
                } else {
                    throw new \Exception('店铺款或奖励款不成功');
                }
            } else {
                //主动抛出异常
                throw  new \Exception('order_info或pay_info更新失败!');
            }
        }
        throw new \Exception('订单未找到或订单状态不是待付款');
    }

    protected function HF() {
        $order = Db::name("recharge_order")->where("order_num", $this->payId)->find();
        if (!empty($order) && $order['state'] == 0) {
            $finish_time = date('Y-m-d H:i:s', time());
            $re2 = Db::name('recharge_order')->where("order_num", $this->payId)->update(['state' => 1, 'pay_state' => 1, 'pay_time' => $finish_time]);
            $recharge = new RechargeSevice();
            if ($order['type_id'] == 1) {
                $telRechargeRes = $recharge->telcz($order['user_num'], $order['pay_amount'], $order['order_num']);
                $return = json_encode($telRechargeRes);
                $data = [
                    'param' => $return,
                    'time' => date('Y-m-d H:i:s', time()),
                ];
                Db::name('recharge_log')->insert($data);
                //发放贡献值
                $this->rights->giveGxz($order['uid'], $order['total_gxz'], '话费充值', 1, $this->payId);
                if ($telRechargeRes['error_code'] == '0') {
                    return true;
                }
                throw new \Exception('api调用错误');
            }
        }
        throw new \Exception('订单不存在');
    }

    protected function YK_NK() {
        $order = Db::name("recharge_order")->where("order_num", $this->payId)->find();
        if (!empty($order) && intval($order['state']) == 0) {
            $finish_time = date('Y-m-d H:i:s', time());
            if (strpos($this->payId, 'NK') !== false) {
                $orderState = 5;
            } else {
                $orderState = 1;
            }
            $re2 = Db::name('recharge_order')->where("order_num", $this->payId)->update(['state' => $orderState, 'pay_state' => 1, 'pay_time' => $finish_time]);
            $gxz = Db::name('user_balance')->where('uid', $order['uid'])->setInc('gxz', $order['total_gxz']);
            $llz = Db::name('user_balance')->where('uid', $order['uid'])->setInc('calculator', $order['total_gxz']);
            if ($re2 && $gxz && $llz) {
                return true;
            } else {
                //主动抛出异常
                throw  new \Exception('数据更新失败!');
            }
        }
    }

    protected function JD() {
        $order = Db::name("hotel_order")->where("order_no", $this->payId)->find();
        if (!empty($order) && intval($order['state']) === 0) {
            // 添加代扣,更新订单
            $hotelservice = new HotelDataService();
            $update = $hotelservice->hotelSubmitOrder(3, $order['orderId'], $order['amount_old']);
            if ($update) {
                $finish_time = date('Y-m-d H:i:s', time());
                $re2 = Db::name('hotel_order')->where("order_no", $this->payId)->update(['state' => 1, 'pay_state' => 1, 'pay_time' => $finish_time]);
                if ($re2) {
                    return true;
                } else {
                    throw  new \Exception('数据更新失败');
                }
            } else {
                //主动抛出异常
                throw  new \Exception('api扣款失败');
            }
        }
        throw new \Exception('未找到订单或订单不是待支付状态.');
    }

    protected function AT() {
        $air = new AirTicketService();
        $finish_time = date('Y-m-d H:i:s', time());
        $order = Db::name("airticket_order")->where("order_no", $this->payId)->find();
        if (empty($order) || intval($order['status']) !== 0) throw new \Exception('未找到订单或订单不是待支付状态');
        $re2 = Db::name('airticket_order')->where("order_no", $this->payId)->update(['status' => 5, 'pay_status' => 1, 'pay_time' => $finish_time]);
        $return2 = $air->submitOrder($order['orderId'], $order['pay_amount2']);
        if ($return2) {
            $finish_time = date('Y-m-d H:i:s', time());
            if ($re2) {
                return true;
            } else {
                //主动抛出异常
                throw  new \Exception('数据更新失败');
            }
        } else {
            throw  new \Exception('api扣款失败');
        }
    }

    protected function ME() {
        $finish_time = date('Y-m-d H:i:s', time());
        $alibaba = new AlibabaService();
        $order = Db::name("ali_order")->where("order_no", $this->payId)->find();
        if (empty($order) || ($order['status'] != 0 && $order['status'] != 10)) throw  new \Exception('订单未找到或不是待付款状态');
        $re2 = Db::name('ali_order')->where("order_no", $this->payId)->update(['status' => 1, 'pay_status' => 1, 'pay_time' => $finish_time, 'sync' => 1]);
        $return = $alibaba->protocolPay(['orderId' => $order['orderId']]);
        if (!empty($return)) {
            if ($re2) {
                return true;
            } else {
                throw  new \Exception('数据更新失败');
            }
        } else {
            throw  new \Exception('api扣款失败');
        }
    }

    protected function JT() {
        $now = date('Y-m-d H:i:s');
        $order = Db::name("jt_order")->where("order_no", $this->payId)->find();
        $re2 = Db::name('jt_order')->where("order_no", $this->payId)->update(['order_state' => 5, 'payment_status' => 1, 'payment_time' => $now, 'payment_code' => 2]);
        if (!empty($order)) {
            if ($re2) {
                return true;
            } else {
                throw  new \Exception('数据更新失败');
            }
        } else {
            throw  new \Exception('api扣款失败');
        }
    }

    protected function TY() {
        $date = date('Y-m-d H:i:s');
        $order = Db::name('gas_order')->where('order_no', $this->payId)->find();
        if (!$order) throw new \Exception('未找到订单信息!');
        if ($order['is_convert'] == 2) throw new \Exception('订单已经兑换了,不要重复兑换!');
        if (!Db::name('gas_order')->where("order_no", $this->payId)->update(['is_convert' => 2, 'convert_time' => $date])) throw new \Exception('订单更新失败!');
        //计算利润
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');//循环比
        $cost_rate = Db::name('config_new')->where('name', 'xiaolian_cost_rate')->value('info');//团油成本
        $profit = $order['gxz'] * (100 - $cashFlow - $cost_rate) / 100;//利润
        //发放贡献值
        RightsService::giveGxz($order['uid'], $order['gxz'], '加油订单兑换贡献值', 1, $this->payId);
        //发放直推奖励
        RightsService::giveReferralAward($profit, $order['uid'], $this->payId);
        //发放市场奖励
        RightsService::giveTeamAward($profit, $order['uid'], $this->payId);
        //发放运营中心奖励
        RightsService::giveOcAward($order['gxz'], $order['uid'], $this->payId, 0, 4, '加油系统');
        return true;
    }

    protected function JHYK() {
        $gasCardOrder = \app\api\model\GasCardOrder::get(['order_no' => $this->payId]);
        if (!$gasCardOrder) throw new \Exception('未找到订单!');
        if ($gasCardOrder->order_status != '未支付') throw new \Exception('订单不是待支付状态!');

        $gasCardOrder->pay_time = time();
        $gasCardOrder->game_state = '充值中';
        $gasCardOrder->order_status = '已支付';
        $balance = Db::name('user_balance')->where('uid', $gasCardOrder->uid)->find();
        if ($gasCardOrder->money >= $balance['llj']) {
            $gasCardOrder->gxz = $balance['llj'];
        } else {
            $gasCardOrder->gxz = $gasCardOrder->money;
        }
        if (!$gasCardOrder->isUpdate()->save()) throw new \Exception('订单信息更新失败!');
        RightsService::givellj($gasCardOrder->uid, $gasCardOrder->gxz, '榴莲卷兑换贡献值', 0);
        $gasCardServer = new \app\api\service\JhInterface\GasCardServer();
        $gasCardServer->originGasCardRecharge($gasCardOrder);
        return true;
    }

    protected function SP() {
        Db::name('shop')->where('order_no', $this->payId)->update(['status' => 2, 'pay_status' => 1]);
        return true;
    }

    protected function MSME() {
        try {
            SeckillOrderServer::payOrder($this->payId);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return $this->ME();
    }

    protected function FEME() {
        return $this->ME();
    }

    protected function PME() {
        $finish_time = date('Y-m-d H:i:s', time());
        $alibaba = new AlibabaService();
        $orders = Db::name("ali_order")->where("parent_no", $this->payId)->select()->toArray();
        $re2 = Db::name('ali_order')->where("parent_no", $this->payId)->update(['status' => 1, 'pay_status' => 1, 'pay_time' => $finish_time]);
        $return = [];
        $temOrder_id = [];
        foreach ($orders as $order) {
            if (empty($temOrder_id[$order['orderId']])) {
                $temOrder_id[$order['orderId']] = 1;
                try {
                    $return[] = $alibaba->protocolPay(['orderId' => $order['orderId']]);
                } catch (\Exception $e) {
                    Db::name('test')->insert(['param' => '代扣失败订单号:' . $order['orderId'] . ';错误原因:' . $e->getMessage(), 'time' => date('Y-m-d H:i:s')]);
                    continue;
                }
                Db::name('test')->insert(['param' => $order['orderId']]);
            }
            Db::name('ali_shopping_car')->where(['uid' => $order['uid'], 'feedId' => $order['feedId'], 'status' => 0])->update(['status' => 2]);
        }
        if (!empty($return)) {
            if ($re2) {
                return true;
            } else {
                Db::name('error_log')->insert(['source' => 'payNotifyService/PME', 'desc' => '数据更新失败:', 'uid' => $orders[0]['uid'], 'order' => $this->payId]);
                throw  new \Exception('数据更新失败');
            }
        } else {
            Db::name('error_log')->insert(['source' => 'payNotifyService/PME', 'desc' => 'api代扣失败:', 'uid' => $orders[0]['uid'], 'order' => $this->payId]);
            throw  new \Exception('api扣款失败');
        }
    }

    protected function PTKT() {
        $order_no = $this->payId;
        PingGroupService::objectInit()->openGroupPayCallBack($order_no);
    }

    protected function PTME() {
        $this->ME();
        PingGroupService::objectInit()->createOrderPayCallBack($this->payId);
    }

    protected function XYQQG() {
        \app\api\service\order\OrderService::objectInit()->payCallBack($this->payId,$this->data);
    }
    protected function KJ() {
        BargainService::objectInit()->updateFinalpayOrderStatus($this->payId);
    }

    protected function YFKJ() {
        BargainService::objectInit()->updatePrepayOrderStatus($this->payId);
    }

    protected function CJ(){
        AwardApiServer::objectInit()->payCallBack($this->payId);
    }
}
