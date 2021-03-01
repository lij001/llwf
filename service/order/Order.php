<?php


namespace app\api\service\order;


use app\api\BaseService;
use app\api\model\order\OrderAddress;
use app\api\model\order\OrderGoods;
use app\api\service\goods\GoodsService_v3;
use think\Validate;

abstract class Order extends BaseService {
    abstract public function createBefore($data);

    abstract public function create($data);

    abstract public function freightPrice($goods_list, $address);

    abstract public function getOrderNo();

    abstract public function getOrderDetail($order);

    abstract public function payCallBack($order, $payNotifyData);

    abstract public function payOrder($order);

    abstract public function deliverOrder($order, $data);

    abstract public function cancelOrder($order, $data);

    abstract public function getOrderList($order);

    protected function createOrder($data) {
        $validate = new Validate([
            'mid' => 'require',
            'address' => 'require',
            'type' => 'require',
            'goods_list' => 'require',
        ]);
        if (!$validate->check($data)) throw new \Exception($validate->getError());
        $order = \app\api\model\order\Order::create([
            'shop_id' => $data['shop_id'] ?: 0,
            'mid' => $data['mid'],
            'parent_no' => $data['parent_no'] ?: '',
            'order_no' => $this->getOrderNo(),
            'status' => '待付款',
            'remark' => $data['remark'] ?: '',
            'total_quantity' => $data['total_quantity'] ?: 0,
            'total_goods_price' => $data['goods_total_price'] ?: 0,
            'freight_price' => $data['freight_price'] ?: 0,
            'total_price' => $data['total_price'] ?: 0,
            'total_cost_price' => $data['total_cost_price'] ?: 0,
            'gxz' => $data['gxz'] ?: 0,
            'llj' => $data['llj'] ?: 0,
            'type' => $data['type'],
            'number' => $data['number'] ?: '',
        ]);
        $order['address'] = $this->insertAddress($order, $data['address']);
        $goods_list = [];
        foreach ($data['goods_list'] as $goods) {
            $order['total_quantity'] += $goods['quantity'];
            $order['total_goods_price'] += bcadd($goods['price'] * $goods['quantity'], 0, 2);
            $order['total_cost_price'] += bcadd($goods['cost_price'] * $goods['quantity'], 0, 2);
            $goods['order_id'] = $order['id'];
            $goods->allowField(true)->save();
            if ($goods['goods']['freight'] == '包邮') continue;
            $goods_list[] = $goods;
        }
        if (empty($goods_list)) {
            $order['freight_price'] = 0;
        } else {
            $order['freight_price'] = $this->freightPrice($goods_list, $order['address']);//获取运费
        }
        $order['total_price'] = $order['freight_price'] + $order['total_goods_price'];
        $order['gxz'] = $order['gxz'] ?: $order['total_price'];
        $order->allowField(true)->save();
        return $order;
    }

    private function insertAddress($order, $address) {
        return OrderAddress::create([
            'order_id' => $order['id'],
            'name' => $address['name'],
            'mobile' => $address['mobile'],
            'province' => $address['province'],
            'city' => $address['city'],
            'country' => $address['country'],
            'detail' => $address['detail'],
        ]);
    }

    protected function insertOrderGoods($sku) {
        $validate = new Validate([
            'quantity' => 'require',
            'goods_id' => 'require',
            'sku_id' => 'require'
        ]);
        if (!$validate->check($sku)) throw new \Exception($validate->getError());
        $goods = GoodsService_v3::objectInit()->getDetailOrder($sku['goods_id'], $sku['sku_id']);
        $goods['quantity'] -= $sku['quantity'];
        $goods['sale_quantity'] += $sku['quantity'];
        $goods['sku']['quantity'] += $sku['quantity'];
        $goods['sku']['sale_quantity'] += $sku['quantity'];
        if ($goods['sku']['quantity'] < 0) throw new \Exception($goods['sku']['sku_detail_value'] . '库存不足!');
        $order_goods = OrderGoods::create([
            'order_id' => 0,
            'goods_id' => $sku['goods_id'],
            'sku_id' => $sku['sku_id'],
            'quantity' => $sku['quantity'],
            'goods_title' => $goods['title'],
            'goods_thumb' => $goods['thumb_img'],
            'goods_sku' => $goods['sku']['sku_detail_value'],
            'price' => $goods['sku']['price'],
            'cost_price' => $goods['sku']['cost_price'],
            'gxz' => 0,
            'llj' => 0
        ]);
        $goods['sku']->allowField(true)->save();
        $goods->allowField(true)->save();
        $order_goods['goods'] = $goods;
        return $order_goods;
    }

    public function createOrderNo($tag) {
        return createOrderNo($tag);
    }

    protected function getOrderOne($order_no) {
        return \app\api\model\order\Order::_whereCV('order_no', $order_no)->find();
    }

    public function returnOrder($order) {

    }
}
