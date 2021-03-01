<?php


namespace app\api\service\Pay;


use app\api\service\OrderService;

class ali extends pay {
    protected $notifyUrl;

    public function h5Pay() {
        $this->notifyUrl = cmf_get_domain() . '/api/pay/aliNotify';
        $res = (new OrderService($this->orderNo, $this->money, $this->title))->aliPay();
        return $res;
    }

    public function appPay() {
        return $this->h5Pay();
    }

    public function payType() {
        // TODO: Implement payType() method.
        return 2;
    }


}
