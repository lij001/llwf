<?php


namespace app\api\service\Pay;

use app\api\BaseService;
use app\api\service\MemberBalanceService;
use app\api\service\Pay\weChat;
use app\api\service\Pay\ali;
use app\api\model\pay\Pay;
use think\Db;

class PayService extends BaseService {
    /**
     * @var \app\api\service\Pay\pay
     */
    private $service;
    private $order;

    /**
     * 实例化支付类
     * @param $order
     * @param string $type 支付类型
     * @throws \Exception
     */
    public static function make($order, $type) {
        $self = self::objectInit();
        $self->order = $order;
        $self->createService($type);
        $self->updatePay();
        return $self;
    }

    private function createService($type) {
        $class=__NAMESPACE__.'\\'.$type;
        if (!class_exists($class)) throw new \Exception($class . '未定义');
        $info = Pay::_whereCV('order_no', $this->order['order_no'])->find();
        if ($info === null) throw new \Exception('没有支付信息!');
        $this->service = call_user_func($class . '::objectInit', $this->order['order_no'], $info['pay_money']);
    }

    /**
     * @param $order
     * @param $data
     * @param $order_type
     * @return Pay
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function insertPay($order, $data, $order_type) {
        $create = [
            'order_no' => $order['order_no'],
            'order_id' => $order['id'],
            'create_time' => date('Y-m-d H:i:s'),
            'pay_status' => '未支付',
            'pay_amount' => $order['total_price'],
            'pay_type' => 0,
            'pay_tz' => 0,
            'pay_balance' => 0,
            'pay_money' => $order['total_price'],
            'order_type' => $order_type,
            'uid' => $order['mid'],
        ];
        if (!empty($data['balance']) || !empty($data['tz'])) {
            $configPay = Db::name('config_pay')->where('id', 1)->find();
            if (!empty($data['balance'])) {
                if ($configPay['yue'] == 0) {
                    throw new \Exception('余额抵扣已关闭,请重新下单!');
                }
                $dikouMax = $order['total_price'] * $configPay['yue_rate'] / 100;
                if ($dikouMax < $data['balance']) {
                    throw new \Exception('余额抵扣金额已超过限定百分比!');
                }
                MemberBalanceService::objectInit()->balancePayOrder($order['mid'], $data['balance'],$order['order_no']);
                $create['pay_balance'] = $data['balance'];
            } else {
                if ($configPay['tz'] == 0) {
                    throw new \Exception('通证抵扣已关闭,请重新下单!');
                }
                $dikouMax = $order['total_price'] * $configPay['tz_rate'] / 100;
                if ($dikouMax < $data['tz']) {
                    throw new \Exception('余额抵扣金额已超过限定百分比!');
                }
                MemberBalanceService::objectInit()->tzPayOrder($order['mid'], $data['tz'],$order['order_no']);
                $create['pay_tz'] = $data['tz'];
            }
            $create['pay_money'] = $create['pay_amount'] - $create['pay_tz'] - $create['pay_balance'];
        }
        $log = Pay::create($create);
        return $log;
    }

    public static function cancelOrder($order) {
        $info = Pay::_whereCV('order_no', $order['order_no'])->find();
        if ($info === null) return false;
        if ($info['pay_tz'] > 0) {
            MemberBalanceService::objectInit()->tzPayOrder($order['mid'], $info['pay_tz'], $order['order_no'], true);
        } elseif ($info['pay_balance'] > 0) {
            MemberBalanceService::objectInit()->balancePayOrder($order['mid'], $info['pay_balance'], $order['order_no'], true);
        }
        return $info;
    }

    public static function confirmOrderCheckPay($order) {
        $info = Pay::_whereCV('order_no', $order['order_no'])->find();
        if ($info === null) throw new \Exception('没有支付信息!');
        if ($info['pay_status'] != '已支付') throw new \Exception('订单未支付!');
        if ($info['pay_amount'] < $order['total_price']) throw new \Exception('订单支付信息不对称');
        return $info;
    }

    private function updatePay() {
//        $info = Pay::_whereCV('order_no', $this->order['order_no'])->find();
//        if ($info === null) throw new \Exception('未有支付信息!');
//        $info['pay_type'] = $this->service->payType();
//        $info->save();
//        return $info;

    }


    public function appPay() {
        return $this->service->appPay();
    }
}
