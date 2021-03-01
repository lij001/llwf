<?php


namespace app\api\service;

use think\Db;

/**
 * 支付宝app支付回调
 */
class AliAPPNotifyService extends PayNotifyService {

    protected $pay_type = 2;

    public function check() {
        if (empty($this->data)) {
            return false;
        }
        Db::name('pay_callback')->insert(['param' => json_encode($this->data, JSON_UNESCAPED_UNICODE)]);
        if (empty($this->data['out_trade_no'])) {
            return false;
        }
        if (!$this->checkSign()) {
            return false;
        }
        if ($this->data['trade_status'] != 'TRADE_SUCCESS') {
            return false;
        }
        return $this->doBack();
    }

    private function checkSign() {
        require_once EXTEND_PATH . "alipay/AopSdk.php";
        $alipay_publicKey = Db::name('config')->where('id', 1)->value('zfb_public_Key');
        $aop = new \AopClient;
        $aop->alipayrsaPublicKey = $alipay_publicKey;
        $flag = $aop->rsaCheckV1($_POST, NULL, "RSA2");
        return $flag;
    }

    public function checkText() {
        if (empty($this->data)) {
            return false;
        }
        return $this->doBack();
    }
    /*备份代码
    private function backUp(){
        $data    = $this->data;
        $this->payId   = $data['out_trade_no'];
        $this->rights  = new RightsService();
        Db::name('pay_log')->insert(['payId'=>$this->payId,'type'=>2,'datetime'=>date('Y-m-d H:i:s',time())]);
        try {
            if (strpos($this->payId, 'WM') !== false) {
                $order = Db::name("order_info")->where("order_no", $this->payId)->find();
                if (!empty($order) && intval($order['status']) == 2) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    Db::startTrans();
                    $re2 = Db::name('order_info')->where("order_no", $this->payId)->update(['status' => 3, 'finish_time' => $finish_time]);
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);

                    if ($re2 && $cs) {
                        Db::commit();
                        // 升级权益校验
                        $this->rights->estimate_check($order['uid'], $order['order_type'], $this->payId);
                        //店铺款预估
                        if ($order['spid'] > 0 && $order['amount'] > 0) {
                            $shopService = new ShopService();
                            $shopService->ygShopBalance($order['spid'], $order['amount'], $this->payId);
                        }
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                }
            } else if (strpos($this->payId, 'PW') !== false) {
                $orderlist = Db::name("order_info")->where("parent_no", $this->payId)->field('spid,amount,uid,order_no,status,order_type')->select()->toArray();
                if (!empty($orderlist)) {
                    Db::startTrans();
                    foreach ($orderlist as $or) {
                        if (intval($or['status']) != 2) {
                            Db::rollback();
                            throw  new \Exception();
                        }
                        $finish_time = date('Y-m-d H:i:s', time());
                        $re2 = Db::name('order_info')->where("parent_no", $this->payId)->update(['status' => 3, 'finish_time' => $finish_time]);
                        $cs = Db::name("pay_info")->where("order_no",  $or['order_no'])->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);
                        if ($re2 && $cs) {
                            //升级权益校验

                            $this->rights->estimate_check($or['uid'], $or['order_type'], $or['order_no']);
                            //店铺款预估
                            if ($or['spid'] > 0 && $or['amount'] > 0) {
                                $shopService = new ShopService();
                                $shopService->ygShopBalance($or['spid'], $or['amount'], $or['order_no']);
                            }
                        } else {
                            Db::rollback();
                            throw  new \Exception();
                        }
                    }
                    Db::commit();
                }
            } else if (strpos($this->payId, 'OF') !== false) {
                $order = Db::name("order_offline")->where("order_no", $this->payId)->find();
                if (!empty($order) && intval($order['status']) == 2) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    Db::startTrans();
                    $re2 = Db::name('order_offline')->where("order_no", $this->payId)->update(['status' => 1, 'finish_time' => $finish_time]);
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);
                    if ($re2 && $cs) {
                        //店铺款
                        $shop_flag = true;
                        if ($order['spid'] > 0) {
                            $shopService = new ShopService();
                            $shop_flag = $shopService->shopBalance($order['spid'], $order['amount'], $this->payId);
                        }
                        //推荐奖励
                        $right_flag = true;
                        if ($order['spid'] > 0 && $order['amount'] > 0) {

                            $right_flag = $this->rights->shopRights($order['uid'], $this->payId, $order['amount'], $order['spid']);
                        }
                        //线下贡献值
                        if ($order['sp_gxz'] > 0) {
                            $buyer_gxz = $order['sp_gxz'];

                            //发放贡献值
                            $this->rights->giveGxz($order['uid'], $buyer_gxz, '线下支付', 1,$this->payId);

                            //店铺贡献值增加
                            if ($order['spid'] > 0) {
                                $shop_cal = Db::name('shop')->where('id', $order['spid'])->field('rate,uid')->find();
                                $shop_gxz = bcmul($buyer_gxz, ($shop_cal['rate'] / 100), 2);

                                $this->rights->giveGxz($shop_cal['uid'], $shop_gxz, '店铺收益', 1, $this->payId);
                            }
                        }
                        //运营中心
                        if ($order['spid'] > 0 && $order['amount'] > 0) {
                            $oc_area_id = Db::name('shop')->where('id', $order['spid'])->value('area_id');

                            $this->rights->ocRight(3, $this->payId, $oc_area_id, $order['amount'], $order['spid']);
                        }
                        //团队消费收益

                        $this->rights->teamRights($order['uid'], $this->payId, $order['amount'], $order['spid']);
                        if ($shop_flag && $right_flag) {
                            Db::commit();
                        } else {
                            Db::rollback();
                            //主动抛出异常
                            throw  new \Exception();
                        }
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                }
            } else if (strpos($this->payId, 'HF') !== false) {
                $order = Db::name("recharge_order")->where("order_num", $this->payId)->find();
                if (!empty($order) && $order['state'] == 0) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);
                    $re2 = Db::name('recharge_order')->where("order_num", $this->payId)->update(['state' => 1, 'pay_state' => 1, 'pay_time' => $finish_time]);
                    Db::startTrans();
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
                        $this->rights->giveGxz($order['uid'], $order['total_gxz'], '话费充值', 1);
                        if ($telRechargeRes['error_code'] == '0') {
                            Db::commit();
                        }
                    }
                }
            } else if (strpos($this->payId, 'YK') !== false || strpos($this->payId, 'NK') !== false) {
                $order = Db::name("recharge_order")->where("order_num", $this->payId)->find();
                if (!empty($order) && intval($order['state']) == 0) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    Db::startTrans();
                    if (strpos($this->payId, 'NK') !== false) {
                        $orderState = 5;
                    } else {
                        $orderState = 1;
                    }
                    $re2 = Db::name('recharge_order')->where("order_num", $this->payId)->update(['state' => $orderState, 'pay_state' => 1, 'pay_time' => $finish_time]);
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);

                    $gxz = Db::name('user_balance')->where('uid', $order['uid'])->setInc('gxz', $order['total_gxz']);
                    $llz = Db::name('user_balance')->where('uid', $order['uid'])->setInc('calculator', $order['total_gxz']);
                    if ($re2 && $cs && $gxz && $llz) {
                        Db::commit();
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                }
            } else if (strpos($this->payId, 'JD') !== false) {
                $order = Db::name("hotel_order")->where("order_no", $this->payId)->find();
                if (!empty($order)) {
                    // 添加代扣,更新订单
                    $hotelservice = new HotelDataService();
                    $update = $hotelservice->hotelSubmitOrder(3, $order['orderId'], $order['amount_old']);
                    if ($update) {
                        $finish_time = date('Y-m-d H:i:s', time());
                        Db::startTrans();
                        $re2 = Db::name('hotel_order')->where("order_no", $this->payId)->update(['state' => 1, 'pay_state' => 1, 'pay_time' => $finish_time]);
                        $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);

                        if ($re2 && $cs) {
                            Db::commit();
                        } else {
                            Db::rollback();
                            //主动抛出异常
                            throw  new \Exception();
                        }
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                }
            } else if (strpos($this->payId, 'AT') !== false) {
                $air = new AirTicketService();
                $order = Db::name("airticket_order")->where("order_no", $this->payId)->find();
                $finish_time = date('Y-m-d H:i:s', time());
                $re2 = Db::name('airticket_order')->where("order_no", $this->payId)->update(['status' => 5, 'pay_status' => 1, 'pay_time' => $finish_time]);
                $return2 = $air->submitOrder($order['orderId'], $order['pay_amount2']);
                if (!empty($order)) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    Db::startTrans();
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);

                    if ($re2 && $cs) {
                        Db::commit();
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                } else {
                    Db::rollback();
                    //主动抛出异常
                    throw  new \Exception();
                }
            } else if (strpos($this->payId, 'ME') !== false) {
                $finish_time = date('Y-m-d H:i:s', time());
                $order = Db::name("ali_order")->where("order_no", $this->payId)->find();
                $re2 = Db::name('ali_order')->where("order_no", $this->payId)->update(['status' => 1, 'pay_status' => 1, 'pay_time' => $finish_time]);
                $return = $ali->protocolPay($order['orderId']);
                if (!empty($return)) {
                    $finish_time = date('Y-m-d H:i:s', time());
                    Db::startTrans();
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $finish_time, 'pay_type' => 2]);
                    if ($re2 && $cs) {
                        Db::commit();
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                } else {
                    Db::rollback();
                    //主动抛出异常
                    throw  new \Exception();
                }
            } else if (strpos($this->payId, 'JT') !== false) {
                $now = date('Y-m-d H:i:s');
                $order = Db::name("jt_order")->where("order_no", $this->payId)->find();
                $re2 = Db::name('jt_order')->where("order_no", $this->payId)->update(['order_state' => 5, 'payment_status' => 1, 'payment_time' => $now, 'payment_code' => 2]);

                if (!empty($order)) {
                    Db::startTrans();
                    $cs = Db::name("pay_info")->where("order_no", $this->payId)->update(['pay_status' => 1, 'finish_time' => $now, 'pay_type' => 2]);

                    if ($re2 && $cs) {
                        Db::commit();
                    } else {
                        Db::rollback();
                        //主动抛出异常
                        throw  new \Exception();
                    }
                } else {
                    Db::rollback();
                    //主动抛出异常
                    throw  new \Exception();
                }
            } else {
                throw  new \Exception();
            }
        } catch (Exception $ex) {
            echo '<pre>';
            var_dump($ex);
            die;
            Db::rollback();
            // 如果出现异常，向微信返回false，请求重新发送通知
            return false;
        }
        return true;
    }
    */
}
