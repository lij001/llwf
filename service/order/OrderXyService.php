<?php


namespace app\api\service\order;


use app\api\model\order\OrderGoodsXy;
use app\api\model\order\OrderXy;
use app\api\service\CheckService;
use app\api\service\goods\GoodsService_v3;
use app\api\service\Xyb2b\XyService;
use app\extend\Ali;
use app\extend\WeChat;
use think\Cache;
use think\Db;

class OrderXyService extends Order {
    protected $is_need_idcard;
    protected $trade_name;
    protected $sku_tax_rate = 0;

    public function createBefore($data) {
        foreach ($data['goods'] as $sku) {
            $goods = GoodsService_v3::objectInit()->getDetailOrder($sku['goods_id'], $sku['sku_id']);
            if ($goods === null) throw new \Exception('未找到商品!');
            try {
                $sku_price = XyService::objectInit()->getSkuPrice(explode('_', $goods['sku']['extend']['sku_code'])[0])['sku_price'];
                $sku_stock = XyService::objectInit()->getSkuBatch(explode('_', $goods['sku']['extend']['sku_code'])[0])['sku_batch'];
                if (empty($sku_price) || empty($sku_stock)) {
                    throw new \Exception('该商品已下架');
                }
            } catch (\Exception $e) {
                GoodsService_v3::objectInit()->disabled($sku['goods_id']);
                throw new \Exception('该商品已下架');
            }
        }
    }

    public function create($data) {
        foreach ($data['goods'] as $sku) {
            $order_goods = $this->insertOrderGoods($sku);
            $data['goods_list'][] = $order_goods;
        }
        if ($this->is_need_idcard > 0 || $this->trade_name === '跨境保税') {
            $cacheName = 'CheckService' . date('Ymd') . $data['mid'];
            $times = Cache::get($cacheName) ?: 0;
            if ($times) {
                if ($times >= 3) {
                    throw new \Exception('今日错误过多,请次日再试');
                }
            }
            if (empty($data['buyer_name']) || strlen($data['buyer_card_id']) != 18) throw new \Exception('购买人姓名和身份证号码不能为空!');
            $check = new CheckService();
            $return = $check->idCardCheck($data['buyer_card_id'], $data['buyer_name']);
            if ($return['status'] != 0) {
                Cache::set($cacheName, ++$times, 0);
                throw new \Exception($return['msg']);
            }
        }
        $data['tax_price'] = 0;
        $order = $this->createOrder($data);
        if ($this->trade_name === '跨境保税') {
            $data['tax_price'] = bcmul($order['total_price'], $this->sku_tax_rate, 2);
            $order['total_price'] += $data['tax_price'];
            $order->allowField(true)->save();
        }
        $order['extend'] = OrderXy::create([
            'order_id' => $order['id'],
            'buyer_name' => $data['buyer_name'] ?: '',
            'buyer_card_id' => $data['buyer_card_id'] ?: '',
            'tax_price' => $data['tax_price'] ?: 0,
        ]);
        return $order;
    }

    protected function insertOrderGoods($sku) {
        $order_goods = parent::insertOrderGoods($sku);
        $data = [
            'order_goods_id' => $order_goods['id'],
            'goods_code' => $order_goods['goods']['extend']['goods_code'],
            'sku_code' => $order_goods['goods']['sku']['extend']['sku_code'],
            'trade_name' => $order_goods['goods']['extend']['trade_name'],
        ];
        $order_goods['extend'] = OrderGoodsXy::create($data);
        $this->is_need_idcard = $order_goods['goods']['sku']['extend']['sku_price']['is_need_idcard'];
        $this->trade_name = $order_goods['goods']['extend']['trade_name'];
        $this->sku_tax_rate = $order_goods['goods']['sku']['extend']['sku_tax_rate'];
        return $order_goods;
    }

    public function getOrderNo() {
        return $this->createOrderNo('XYQQG');
    }

    public function freightPrice($goods_list, $address) {
        // TODO: Implement freightPrice() method.
        $freightPrice = 0;
        $bool = false;
        foreach ($goods_list as $goods) {
            foreach ($goods['goods']['sku']['extend']['sku_price']['freight_list'] as $val) {
                if ($val['region_name'] === '全国包邮') {
                    $freightPrice += 0;
                    $bool = true;
                    break;
                }
                if (strpos($val['region_name'], $address['city']) !== false) {
                    $weight = ($goods['goods']['sku']['weight'] * $goods['quantity']) - $val['first_freight'];
                    $freightPrice += $val['first_price'];
                    if ($weight > 0) {
                        $freightPrice += bcadd(0, ceil($weight / $val['second_freight']) * $val['second_price'], 2);
                    }
                    $bool = true;
                    break;
                }
            }
        }
        if (!$bool) throw new \Exception('该地区不支持购买!');
        return $freightPrice;
    }

    public function getOrderDetail($order) {
        $this->getExtendOrder($order);
        $this->getExtendOrderGoods($order);
    }

    public function payOrder($order) {
        foreach ($order['goods'] as $goods) {
            try {
                $sku_price = XyService::objectInit()->getSkuPrice(explode('_', $goods['extend']['sku_code'])[0])['sku_price'];
                $sku_stock = XyService::objectInit()->getSkuBatch(explode('_', $goods['extend']['sku_code'])[0])['sku_batch'];
                if (empty($sku_price) || empty($sku_stock)) {
                    throw new \Exception('该商品已下架');
                }
            } catch (\Exception $e) {
                GoodsService_v3::objectInit()->disabled($goods['goods_id']);
                throw new \Exception('该商品已下架');
            }
        }
    }

    public function payCallBack($order, $payNotifyData) {
        $xy_order = OrderXy::_whereCV('order_id', $order['id'])->find();
        if ($xy_order === null) throw new \Exception('行云扩展订单没有信息!');
        $sku_list = [];
        $trade_name = null;
        foreach ($order['goods'] as $goods) {
            $sku_code = explode('_', $goods['extend']['sku_code']);
            $sku_list[] = [
                'sku_code' => $sku_code[0],//sku编码
                'package_num' => 0,//包装规格
                'buy_num' => $sku_code[1] * $goods['quantity'],//购买数量
                'sku_price' => bcdiv($goods['price'], $sku_code[1] * $goods['quantity'], 2),//渠道售价
                'price_type' => 2,//渠道售价
                'channel_discount_amount' => '0',//渠道优惠金额
                'sku_tax_amount' => $order['extend']['tax_price'],//税费
                'sku_pay_amount' => bcadd($goods['price'] * $goods['quantity'], $order['extend']['tax_price'], 2),//终端支付金额
            ];
            $trade_name = $goods['extend']['trade_name'];
        }
        $pay_third_no = '';
        $pay_company_name = '';
        $pay_custom_no = '';
        $third_pay_type = '';
        $custom_pay_order = '';
        if ($trade_name === '跨境保税') {//推送支付单
            try {
                if ($order['pay_info']['pay_type'] == 1) {//微信
                    $third_pay_type = 2;
                    $pay_third_no = $payNotifyData['transaction_id'];
                    $pay_company_name = '微信';
                    $pay_custom_no = '4403169D3W';
                    $custom_pay_order = $order['order_no'];
                    $param = [
                        'out_trade_no' => $custom_pay_order,
                        'transaction_id' => $pay_third_no,
                        'customs' => 'HANGZHOU_ZS',
                        'mch_customs_no' => XyService::ECOMMERCE_CODE,
                    ];
                    $we_response = WeChat::objectInit()->customsDeclaration($param);
                    if ($we_response['result_code'] !== 'SUCCESS' || $we_response['return_code'] !== 'SUCCESS') {
                        throw new \Exception($we_response['err_code_des'] . '|' . $we_response['return_msg']);
                    }
                } elseif ($order['pay_info']['pay_type'] == 2) {//支付宝
                    $third_pay_type = 1;
                    $pay_third_no = $payNotifyData['trade_no'];
                    $pay_company_name = '支付宝';
                    $pay_custom_no = '31222699S7';
                    $custom_pay_order = $order['order_no'];
                    $param = [
                        'out_request_no' => $order['order_no'],
                        'trade_no' => $payNotifyData['trade_no'],
                        'merchant_customs_code' => XyService::ECOMMERCE_CODE,
                        'merchant_customs_name' => XyService::ECOMMERCE_NAME,
                        'amount' => $order['total_price'],
                        'customs_place' => 'zongshu',
                        'buyer_name' => $order['extend']['buyer_name'],
                        'buyer_id_no' => $order['extend']['buyer_card_id']
                    ];
                    $ali_response = Ali::objectInit()->customsDeclaration($param);
                    if ($ali_response['is_success'] !== 'T') {
                        throw new \Exception($ali_response['error']);
                    }
                }
            } catch (\Exception $e) {
                $xy_order['remark'] = '推单失败:' . $e->getMessage();
                $xy_order->save();
                return 0;
            }

        }
        try {
            $response = XyService::objectInit()->addCustomOrder(
                $order['order_no'],
                $sku_list,
                1,
                $order['address']['name'],
                $order['address']['mobile'],
                $order['address']['province'],
                $order['address']['city'],
                $order['address']['country'],
                $order['address']['detail'],
                $order['total_price'],
                '0',
                '0',
                $order['freight_price'],
                $order['total_price'],
                $order['extend']['buyer_name'],
                $order['extend']['buyer_card_id'],
                $pay_third_no,
                $pay_company_name,
                $pay_custom_no,
                $third_pay_type,
                $custom_pay_order
            );
            $xy_order['remark'] = $response['ret_msg'];
            $xy_order->save();
            return 1;
        } catch (\Exception $e) {
            $xy_order['remark'] = $e->getMessage();
            $xy_order->save();
            return 0;
        }

    }

    public function callBack($param, $type) {
        $order = $this->getOrderOne($param['merchant_order_id']);
        if ($order === null) throw new \Exception('未找到该订单!' . $param['merchant_order_id']);
        $order_xy = OrderXy::_whereCV('order_id', $order['id'])->find();
        if ($order_xy === null) throw new \Exception('行云订单扩展表未写入信息!');
        switch ($type) {
            case '创建订单':
                if ($param['is_success'] == 1) {//订单处理成功事件
                    $order_list = current($param['order_list']);
                    $order_xy['xy_order_id'] = $order_list['child_order_id'];
                } else {
                    $order_xy['remark'] = $param['ret_msg'];
                }
                $order_xy->save();
                break;
            case '订单售后':
                if ($param['is_return'] == 2) {
                    $aftersale_address = [
                        'province' => $param['aftersale_province'],
                        'city' => $param['aftersale_city'],
                        'country' => $param['aftersale_area'] ?: '',
                        'detail' => $param['aftersale_address'],
                        'name' => $param['aftersale_recipient'],
                        'mobile' => $param['aftersale_recipient_mobile'],
                    ];
                    $data = [
                        'aftersale_address' => json_encode($aftersale_address, JSON_UNESCAPED_UNICODE)
                    ];
                    if (!empty($param['aftersale_remark'])) {
                        $data['turn_down'] = $param['aftersale_remark'];
                    }
                    Db::name('goods_return')->where('order_no', $order['order_no'])->update($data);
                }
                break;
        }
        return $order_xy;
    }

    public function deliverOrder($order, $data) {

    }

    public function cancelOrder($order, $data) {
        if (empty($data['notice_code'])) return;
        $order_xy = OrderXy::_whereCV('order_id', $order['id'])->find();
        if ($order_xy === null) throw new \Exception('行云订单扩展表未写入信息!');
        $order_xy['remark'] = '行云全球购通知取消订单!';
        $order_xy->save();
        return $order_xy;
    }

    public function getOrderList($order) {
        $this->getExtendOrder($order);
    }

    private function getExtendOrder($order) {
        $order_xy = OrderXy::_whereCV('order_id', $order['id'])->find();
        if ($order_xy === null) throw new \Exception('行云订单扩展表未写入信息!');
        $order['extend'] = $order_xy;
    }

    private function getExtendOrderGoods($order) {
        foreach ($order['goods'] as $goods) {
            $goods['extend'] = OrderGoodsXy::_whereCV('order_goods_id', $goods['id'])->find();
        }
    }


}
