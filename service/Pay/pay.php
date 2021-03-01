<?php


namespace app\api\service\Pay;


abstract class pay extends \app\api\BaseService {
    protected $orderNo;
    protected $money;
    protected $notifyUrl = null;
    protected $title = '商户支付';

    protected function initialization($orderNo, $money) {
        $this->orderNo = $orderNo;
        $this->money = $money;
    }

    abstract public function h5Pay();

    abstract public function payType();

    abstract public function appPay();
}
