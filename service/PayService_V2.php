<?php


namespace app\api\service;


use app\api\service\Pay\weChat;
use app\api\BaseService;
use think\Db;

/**
 * Class PayService_V2
 * @package app\api\service
 */
class PayService_V2 extends BaseService {
    private $service;
    private $orderNo;
    private $money;
    protected function initialization($name, $orderNo, $money) {
        $this->orderNo = $orderNo;
        $this->money = $money;
        $class = implode('\\', [__NAMESPACE__, 'Pay', $name]);
        if (!class_exists($class)) throw new \Exception($class . '未定义');
        $this->service = call_user_func($class . '::objectInit', $orderNo, $money);
    }

    public function h5Pay() {
        $this->payLog();
        return $this->service->h5Pay();
    }
    public function appPay() {
        $this->payLog();
        return $this->service->appPay();
    }
    private function payLog() {
        if (empty($this->orderNo)) throw new \Exception('订单号不能为空!');
        $order = Db::name('order_offline')->where('order_no', $this->orderNo)->find();
        if ($order === null) throw new \Exception('未找到该订单,该订单是否未生成!');
        $pay = [];
        $pay['order_no'] = $order['order_no'];
        $pay['order_id'] = $order['id'];
        $pay['create_time'] = date('Y-m-d H:i:s');
        $pay['pay_status'] = 2;
        $pay['pay_amount'] = $order['total'];
        $pay['pay_type'] = $this->service->payType();
        $pay['order_type'] = 2;
        $pay['uid'] = $order['uid'];
        if (!Db::name('pay_info')->insert($pay)) throw new \Exception('支付信息插入失败!');
        return 1;
    }

}
