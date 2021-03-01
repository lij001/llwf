<?php


namespace app\api\service;


use app\api\controller\AliApiController;
use app\api\controller\GoodsController;
use app\api\controller\OrderController;
use app\api\model\AliProductV2;
use app\api\model\order\AliOrder;
use app\api\service\Alibaba\AlibabaService;
use app\api\service\Alibaba\AlibabaServiceV2;
use app\BaseService;
use think\Db;
use think\Validate;

class OrderService_v2 extends BaseService {
    public function validate($param, $field) {
        $data = [];
        $error = [];
        foreach ($field as $val) {
            switch ($val) {
                case 'order_no':
                    $data['order_no'] = 'require';
                    $error['order_no'] = '订单号生成失败!';
                    break;
            }
        }
        $validate = Validate::make($data, $error);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
    }

    public function orderList($param) {
        $uid = TokenService::getCurrentUid();
        $model = Db::table(Db::name('ali_order')->field('order_no,UNIX_TIMESTAMP(create_time) create_time,uid,status,1 ali')->union(Db::name('order_info')->field('order_no,UNIX_TIMESTAMP(create_time) create_time,uid,status,0 ali')->select(false), true)->buildSql())
            ->alias('a');
        $model->where('uid', $uid);
        if (!empty($param['status'])) {
            $aliStatus = array_search($param['status'], $this->getMEStatus());
            if ($aliStatus === false) throw new \Exception('订单状态不正确');
            $model->where(function ($query) use ($aliStatus, $uid) {
                $query->where(['status' => $aliStatus, 'ali' => 1, 'uid' => $uid]);
            });
            $wMStatus = array_search($param['status'], $this->getWMStatus());
            if ($wMStatus === false) throw new \Exception('订单状态不正确');
            $model->whereOr(function ($query) use ($wMStatus, $uid) {
                $query->where(['status' => $wMStatus, 'ali' => 0, 'uid' => $uid]);
            });
        }
        $list = $model->order('create_time', 'desc')->paginate(10);
        $r = [];
        foreach ($list as $key => $val) {
            $fn = substr($val['order_no'], 0, $this->orderTagLength($val['order_no']));
            if ($this->$fn($val) === false) continue;
            $r[] = $val;
        }
        return $r;
    }

    public function orderList2($param) {
        $uid = TokenService::getCurrentUid();
        $model = Db::table(Db::name('ali_order')->field('order_no,UNIX_TIMESTAMP(create_time) create_time,uid,status,1 ali')->buildSql())->alias('a');
        $model->where('uid', $uid);
        if (!empty($param['status'])) {
            $aliStatus = array_search($param['status'], $this->getMEStatus());
            if ($aliStatus === false) throw new \Exception('订单状态不正确');
            $model->where(function ($query) use ($aliStatus, $uid) {
                $query->where(['status' => $aliStatus, 'ali' => 1, 'uid' => $uid]);
            });
            $wMStatus = array_search($param['status'], $this->getWMStatus());
            if ($wMStatus === false) throw new \Exception('订单状态不正确');
            $model->whereOr(function ($query) use ($wMStatus, $uid) {
                $query->where(['status' => $wMStatus, 'ali' => 0, 'uid' => $uid]);
            });
        }
        $list = $model->order('create_time', 'desc')->paginate(10);
        $r = [];
        foreach ($list as $key => $val) {
            $fn = substr($val['order_no'], 0, $this->orderTagLength($val['order_no']));
            if ($this->$fn($val) === false) continue;
            $r[] = $val;
        }
        return $r;
    }


    protected function statusC($d) {
        if (is_numeric($d)) return $d;
        $data = [
            '待付款' => 0,
            '待发货' => 1,
            '待收货' => 2,
            '已完成' => 3,
            '退款中' => 4,
            '交易关闭' => 5,
            '已退款' => 6
        ];
        return $data[$d];
    }

    private function orderTagLength($order_sn) {
        $i = -1;
        do {
            ++$i;
        } while (!is_numeric($order_sn[$i]));
        return $i;
    }

    public function addOrder(array $order) {

    }

    protected function getMEStatus() {
        return [
            0 => '待付款',
            1 => '待发货',
            2 => '待收货',
            3 => '已完成',
            4 => '退款中',
            5 => '交易关闭',
            6 => '已退款',
            10 => '待付款',
        ];
    }

    protected function getWMStatus() {
        return [
            2 => '待付款',
            3 => '待发货',
            4 => '待收货',
            1 => '已完成',
            5 => '退款中',
            0 => '交易关闭',
            6 => '已退款'
        ];
    }

    protected function getMSMEStatus() {
        return $this->getMEStatus();
    }

    protected function ME(&$d) {
        $alibaba = new AlibabaService();
        $o = Db::name('ali_order')->where('order_no', $d['order_no'])->find();
        if (empty($o)) return false;
        $s = $this->getMEStatus();
        $buyerView = $alibaba->buyerView(['orderId' => $o['orderId']]);
        $status = $o['status'];
        switch ($buyerView['result']['baseInfo']['status']) {
            case "waitsellersend":
                $status = '1';
                break;
            case "waitbuyerreceive":
                $status = '2';
                break;
            case "confirm_goods":
                $status = '2';
                break;
            case "success":
                $status = '2';
                break;
            case "cancel":
                $status = '5';
                break;
            case "terminated":
                $status = '5';
                break;
            case "waitbuyerpay":
                $status = '0';
                break;
        }
        if ($o['status'] != $status && in_array($o['status'], [0, 1])) {
            $o['status'] = $status;
            $res = Db::name('ali_order')->where('order_no', $o['order_no'])->where('sync', 1)->update(['status' => $status]);
        }
        if ($o['status'] == 10 && $status = 1) {
            $o['status'] = $status;
            $res = Db::name('ali_order')->where('order_no', $o['order_no'])->where('sync', 1)->update(['status' => $status]);
        }
        if ($status == 5 && $res) {
            $uid = TokenService::getCurrentUid();
            $right = new RightsService();
            $order = Db::name('ali_order')->where('order_no', $o['order_no'])->find();
            if ($order['balance'] > 0) {
                $right->giveBalance($uid, $order['balance'], '余额抵扣退还', 1, $o['order_no'], date('Y-m-d H:i:s'), 1, 9);
            }
            if ($order['tz'] > 0) {
                $right->giveTz($uid, $order['tz'], '通证抵扣退还', $o['order_no'], date('Y-m-d H:i:s'), 9);
            }
        }
        $o['status'] = $s[$d['status']];
        $d = $o;
        return true;
    }

    protected function WM(&$d) {
        $o = Db::name('order_info')->where('order_no', $d['order_no'])->find();
        if (empty($o)) return false;
        $s = $this->getWMStatus();
        $o['status'] = $s[$d['status']];
        $o['detail'] = Db::name('order_detail')->where('order_no', $d['order_no'])->find();
        $d = $o;
        return true;
    }

    protected function KJ(&$d) {
        $o = Db::name('order_info')->where('order_no', $d['order_no'])->find();
        if (empty($o)) return false;
        $s = $this->getWMStatus();
        $o['status'] = $s[$d['status']];
        $o['detail'] = Db::name('order_detail')->where('order_no', $d['order_no'])->find();
        $d = $o;
        return true;
    }

    protected function MSME(&$d) {
        return $this->ME($d);
    }

    protected function PTME(&$d) {
        return $this->ME($d);
    }

    protected function FEME(&$d) {
        return $this->ME($d);
    }

    public function autoConfirmOrder() {
        try {
            $time = time() - 86400 * 14;
            $list = Db::name('ali_order')->where('UNIX_TIMESTAMP(create_time) < ' . $time)->where('status', 2)->select();
            foreach ($list as $key => $val) {
                $this->aliConfirmOrder($val['order_no'], $val['uid'], false);;
            }
        } catch (\Exception $e) {
            writeLog('autoConfirmOrder.log', $e->getMessage());
            return;
        }
    }

    public function autoComment() {
        $time = time() - 86400 * 14;
        $list = Db::name('order_info')->field('order_no,uid')->where('UNIX_TIMESTAMP(create_time) < ' . $time)->where('comment', 0)->where('status', 1)->select();
        $list1 = Db::name('ali_order')->field('order_no,uid')->where('UNIX_TIMESTAMP(create_time) < ' . $time)->where('comment', 0)->where('status', 3)->select();
        $list = array_merge($list->toArray(), $list1->toArray());
        foreach ($list as $key => $val) {
            GoodsCommentService::objectInit()->addComment(['degree_of_match_star' => 10, 'logistics_service_star' => 10, 'service_attitude_star' => 10, 'info' => '默认好评', 'order_no' => $val['order_no']], $val['uid'], false);
        }
    }

    /**
     * 更新15天以前已支付但是未发货的订单
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function updateOldOrder() {
        try {
            $alibaba2 = new AlibabaServiceV2();
            $Morder = new AliOrder();
            $list = $Morder->where([
                'create_time' => ['<', date('Y-m-d H:i:s', time() - 24 * 60 * 60 * 16)],
                'pay_status' => 1,
                'status' => 1
            ])->select()->toArray();
            foreach ($list as $v) {
                $buyerView = $alibaba2->buyerView(['orderId' => $v['orderId']]);
                if ($buyerView['result']['baseInfo']['status'] == 'success') {
                    Db::name('ali_order')->where('id', $v['id'])->update(['status' => 2]);
                }
            }
        } catch (\Exception $e) {
            writeLog('error_log.log', $e->getMessage(), '15天旧订单状态更新');
            return;
        }
    }

    public function confirmOrder($param, $uid = null, $ajax = true) {
        $rights = new RightsService();
        if ($uid === null) {
            $uid = TokenService::getCurrentUid();
        }
        $order_id = $param['order_id'];

        if (empty($order_id)) {
            if (!$ajax) return;
            throw new \Exception('订单号不可空');
        }
        $order = Db::name('order_info')->where('uid', $uid)->where('id', $order_id)->field('status,order_type,order_no,spid,uid,amount,total,cal_money')->find();
        if (empty($order)) {
            if (!$ajax) return;
            throw new \Exception('订单不存在');
        }
        if ($order['status'] != 4) {
            if (!$ajax) return;
            throw new \Exception('状态异常');
        }
        $goodsReturn = Db::name('goods_return')->where('order_no', $order['order_no'])->find();
        if (!empty($goodsReturn)) {
            switch ($goodsReturn['status']) {
                case 0:
                    Db::name('ali_order')->where('order_no', $order['order_no'])->update(['status' => 5]);
                    if (!$ajax) return;
                    throw new \Exception('商品还在退款');
                    break;
                case 1:
                    Db::name('ali_order')->where('order_no', $order['order_no'])->update(['status' => 6]);
                    if (!$ajax) return;
                    throw new \Exception('商品已退款');
                    break;
                default:
                    break;
            }
        }
        //自营赠送美券
        if (empty($order['spid'])) {
            $up1_p = Db::name('config_reward')->where('type', 2)->value('up1_p');
            if ($up1_p == 1) {
                $glist = Db::name('goods_attr')->alias('ga')->join('order_detail od', 'od.goods_attr_id=ga.id')
                    ->where('od.order_no', $order['order_no'])->field('ga.given_mei_coupon')->select()->toArray();
                $given_mei = 0;
                if (!empty($glist)) {
                    foreach ($glist as $g) {
                        $given_mei += $g['given_mei_coupon'];
                    }
                }
                if ($given_mei > 0) {
                    Db::name('user_balance')->where('uid', $order['uid'])->setInc('mei_coupon', $given_mei);
                    $balancelog = [];
                    $balancelog['uid'] = $uid;
                    $balancelog['type'] = 1;
                    $balancelog['source'] = 2;
                    $balancelog['source_name'] = "下单赠送";
                    $balancelog['mei_coupon'] = $given_mei;
                    $balancelog['create_time'] = date('Y-m-d H:i:s', time());
                    Db::name('user_balance_log')->insert($balancelog);
                }
            }
        }
        $rights->confirmOrder($uid, $order);
        if ($order['order_type'] == 2) {
            $now_time = date('Y-m-d H:i:s', time());
            $re = Db::name('order_info')->where('uid', $uid)->where('id', $order_id)
                ->update(['status' => 1, 'finish_time' => $now_time, 'confirm_time' => $now_time]);
            //确认收货，进行权益、等级计算
            $flag = $rights->confirm_check($order['order_no'], $uid);
            if ($re) {
                if (!$ajax) return true;
                return $re;
            } else {
                if (!$ajax) return;
                throw new \Exception('系统错误1');
            }
        } else {
            $re = Db::name('order_info')->where('uid', $uid)->where('id', $order_id)->update(['status' => 1, 'finish_time' => date('Y-m-d H:i:s', time())]);
            if ($order['llj']) {
                $rights->givellj($uid, $order['llj'], '购物获取留莲券', 1);
            }
            if ($re) {
                if (!$ajax) return;
                return $re;
            }
        }
        if (!$ajax) return true;
        throw new \Exception('确认收货失败');
    }


    function aliConfirmOrder($order_no, $uid = null, $ajax = true) {
        $rights = new RightsService();
        if ($uid === null) {
            $uid = TokenService::getCurrentUid();
        }

        $order = Db::name('ali_order')->where('order_no', $order_no)->find();
        if (empty($order['parent_no'])) {
            $pay = Db::name('pay_info')->where('order_no', $order['order_no'])->find();
        } else {
            $pay = Db::name('pay_info')->where('order_no', $order['parent_no'])->find();
            if (empty($pay)) {
                $pay = Db::name('pay_info')->where('order_no', $order['order_no'])->find();
            }
        }
        $errorMsg = '';
        $goodsReturn = Db::name('goods_return')->where('order_no', $order_no)->find();
        if (!empty($goodsReturn)) {
            switch ($goodsReturn['status']) {
                case 0:
                    Db::name('ali_order')->where('order_no', $order_no)->update(['status' => 4]);
                    return ['2', "商品还在退款"];
                    break;
                case 1:
                    Db::name('ali_order')->where('order_no', $order_no)->update(['status' => 6]);
                    return ['2', "商品已退款"];
                    break;
                default:
                    break;
            }
        }
        Db::startTrans();
        try {
            if ($order['status'] == 2 && $pay['pay_status'] == 1 && $order['payamount'] <= $pay['pay_amount']) {
                $errorMsg .= '开始更新订单状态;';
                $status = Db::name('ali_order')->where('order_no', $order_no)->update(['status' => 3]);
                $errorMsg .= '更新订单状态完成;';
                $rights->teamRightsForAli($uid, $order['order_no'], $order['nopost']);
                Db::commit();
                UpgradeService::objectInit()->userUpgradeCheck(Db::name('members')->where('id', $uid)->value('parent_id'));
                return ['0', '确认收货成功'];
            } else {
                Db::rollback();
                return ['2', "订单异常,请联系客服!"];
            }
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => 'orderService_v2/aliConfirmOrder',
                'desc' => $e->getMessage(),
                'order' => $order_no,
                'uid' => $uid,
                'date' => date('Y-m-d H:i:s')
            ]);
            return ['2', "系统繁忙,请联系客服!"];
        }
    }

    public function createOfflinePayOrder($param) {
        $shop_id = session('shop_id');
        if (empty($shop_id)) throw new \Exception('商家id不能为空!');
        if (empty($param['money']) || (float)$param['money'] <= 0) throw new \Exception('金额不能为空!');
        if (empty($param['type'])) throw new \Exception('支付类型不能为空!');
        $this->offlinePayOrderLimit($shop_id, $param);
        $uid = $this->getOfflinePayOrderUid($param['type']);
        $orderNo = createOrderNo('OF');
        $shop = Db::name('shop')->where(['shop_type' => 2, 'status' => 1])->where('id', $shop_id)->find();
        if ($shop === null) throw new \Exception('未找到商家信息!');
        $order = [
            'spid' => $shop['id'],
            'shop_name' => $shop['title'],
            'uid' => $uid,
            'order_no' => $orderNo,
            'source' => 5,
            'order_type' => 1,
            'total' => $param['money'],
            'amount' => $param['money'],
            'tz_yue' => 0,
            'coupon_id' => 0,
            'pay_amount' => $param['money'],
            'status' => 2,
            'create_time' => date('Y-m-d H:i:s'),
            'cal_money' => $param['money'],
            'sp_gxz' => $param['money']
        ];
        $order['order_json'] = json_encode($order, JSON_UNESCAPED_UNICODE);
        Db::startTrans();
        $r = null;
        try {
            Db::name('order_offline')->insert($order);
            $r = PayService_V2::objectInit($param['type'], $orderNo, $param['money'])->h5Pay();
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $r;
    }

    public function offlinePayOrderLimit($shop_id, $param) {
        $off_line_daily_limit = Db::name('shop')->where('id', $shop_id)->value('amount_limit');
        if ($param['money'] > $off_line_daily_limit) throw new \Exception('该店铺超过线下单笔交易限制' . $off_line_daily_limit . '元,如有疑问请联系客服');

        $dayStart = date('Y-m-d 00:00:00');
        $todaySale = Db::name('order_offline')->where(['spid' => $shop_id, 'status' => 1, 'finish_time' => ['>', $dayStart]])->sum('pay_amount');
        $todaySale += $param['money'];
        $off_line_daily_limit = Db::name('shop')->where('id', $shop_id)->value('amount_daily_limit');
        if ($todaySale > $off_line_daily_limit) {
            throw new \Exception("超过线下单日交易限制" . $off_line_daily_limit . "元,如有疑问请联系客服");
        }
    }

    public function h5OrderList($uid) {
        $list = Db::name('order_offline')->where('uid', $uid)->where('status', 'in', [1, 3, 4])->order('id', 'desc')->limit(0, 20)->select();
        return $list;
    }

    private function getOfflinePayOrderUid($type) {
        $fn = 'get' . ucfirst($type) . 'Uid';
        $user = UserService::objectInit();
        if (!method_exists($user, $fn)) throw new \Exception('支付类型错误!');
        return $user->$fn();
    }

}
