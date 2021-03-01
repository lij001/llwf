<?php

namespace app\api\service\Pay;

use app\api\service\MemberService;
use app\api\service\OrderService;

class weChat extends pay {
    protected $notifyUrl;

    public function h5Pay() {
        $this->notifyUrl = cmf_get_domain() . '/api/pay/wechatNotify';
        return \app\extend\WeChat::objectInit()->JsApiPay($this->orderNo, $this->title, $this->money, $this->notifyUrl);
    }

    public function payType() {
        // TODO: Implement payType() method.
        return 1;
    }

    public function appPay() {
        // TODO: Implement appPay() method.
        $this->notifyUrl = cmf_get_domain() . '/api/pay/wechatNotify';
        return \app\extend\WeChat::objectInit()->appPay($this->orderNo, $this->title, $this->money, $this->notifyUrl);
    }
}
