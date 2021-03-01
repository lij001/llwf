<?php

namespace app\api\service\order;

use app\api\BaseService;
use app\api\model\order\OrderGoodsReturn;
use app\api\model\order\OrderAddress;
use app\api\model\order\OrderGoods;
use app\api\service\goods\GoodsService_v3;
use app\api\service\MemberService;
use app\api\service\Pay\PayService;
use app\api\service\RightsService;
use app\api\service\UpgradeService;
use app\service\CommonService;
use think\Db;
use think\facade\Cache;
use think\Validate;
use app\api\model\order\Order;

class OrderService extends BaseService {
    /**
     * 获取第三方service
     * @param string $type
     * @return OrderXyService
     * @throws \Exception
     */
    private function typeService($type) {
        $service = null;
        switch ($type) {
            case '行云':
                $service = OrderXyService::objectInit();
                break;
            default:
                throw new \Exception('未知的类型!');
        }
        return $service;
    }

    /**
     * 查询运费
     * @param $data
     * @return int|string
     * @throws \Exception
     */
    public function freightPrice($data) {
        $validate = new Validate([
            'mid' => 'require',
            'goods' => 'require',
            'address_id' => 'require',
            'type' => 'require',
        ]);
        if (!$validate->check($data)) throw new \Exception($validate->getError());
        $address = MemberService::objectInit()->getAddress($data['mid'], $data['address_id']);
        if ($address === null) throw new \Exception('未找到该收获地址!');
        $goodsList = [];
        foreach ($data['goods'] as $sku) {
            $goods = GoodsService_v3::objectInit()->getDetailOrder($sku['goods_id'], $sku['sku_id']);
            if ($goods['freight'] === '包邮') break;
            $d = [];
            $d['quantity'] = $sku['quantity'];
            $d['goods'] = $goods;
            $goodsList[] = $d;
        }
        if (empty($goodsList)) return 0;
        $freight_price = $this->typeService($data['type'])->freightPrice($goodsList, $address);
        return $freight_price;
    }

    /**
     * 创建订单
     * @param $data
     * @return Order
     * @throws \Exception
     */
    public function create($data) {
        $validate = new Validate([
            'mid' => 'require',
            'goods' => 'require',
            'address_id' => 'require',
            'type' => 'require',
        ]);
        if (!$validate->check($data)) throw new \Exception($validate->getError());
        if (empty($data['goods'])) throw new \Exception('goods不能为空!');
        $address = MemberService::objectInit()->getAddress($data['mid'], $data['address_id']);
        if ($address === null) throw new \Exception('未找到该收获地址!');
        $data['address'] = $address;
        $this->typeService($data['type'])->createBefore($data);
        try {
            Db::startTrans();
            $order = $this->typeService($data['type'])->create($data);
            $this->createOrderSuccess($order, $data);
            Db::commit();
            return $order;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
    }

    private function createOrderSuccess($order, $data) {
        PayService::insertPay($order, $data, $order['type']);
        return 1;
    }

    /**
     * 支付订单
     * @param string $order_no 订单号
     * @param string $type 支付类型
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function payOrder($order_no, $type) {
        $order = $this->getOrderDetail(['order_no'=> $order_no]);
        if ($order === null) throw new \Exception('未找到订单信息!');
        if ($order['status'] !== '待付款') throw new \Exception('订单不是待付款状态!');
        $this->typeService($order['type'])->payOrder($order);
        return PayService::make($order, $type)->appPay();
    }

    /**
     * 支付回调
     * @param $order_no
     * @param null $payNotifyData
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function payCallBack($order_no, $payNotifyData) {
        $order = Order::_whereCV('order_no', $order_no)->find();
        if ($order === null) throw new \Exception('未找到订单信息!');
        if ($order['status'] !== '待付款') throw new \Exception('订单不是待付款状态!');
        $order['status'] = '待发货';
        $order->save();
        $order = $this->getOrderDetail(['mid' => $order['mid'], 'order_no' => $order['order_no']]);
        return $this->typeService($order['type'])->payCallBack($order, $payNotifyData);
    }

    /**
     * 订单获取商品详情
     * @param $param
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getOrderDetail($param) {
        $model = Order::objectInit();
        if (!empty($param['mid'])) $model->where('mid', $param['mid']);
        if (empty($param['order_no'])) throw new \Exception('订单号不能为空!');
        $order = $model->where('order_no', $param['order_no'])->with(['address', 'goods', 'goodsReturn', 'payInfo'])->find();
        if ($order === null) return null;
        $this->typeService($order['type'])->getOrderDetail($order);
        return $order;
    }

    /**
     * 发货
     * @param $data
     */
    public function deliverOrder($data) {
        $validate = new Validate([
            'order_no' => 'require',
            'express_company' => 'require',
            'express_code' => 'require',
            'express_sn' => 'require',
        ]);
        if (!$validate->check($data)) throw new \Exception($validate->getError());
        $order = Order::_whereCV('order_no', $data['order_no'])->with('address')->find();
        if ($order === null) throw new \Exception('订单号不正确!');
        $order->status = '待收货';
        $order->allowField(true)->save();
        $order->address->express_company = $data['express_company'];
        $order->address->express_code = $data['express_code'];
        $order->address->express_sn = $data['express_sn'];
        $order->address->save();
        $this->typeService($order['type'])->deliverOrder($order, $data);
        return $order;
    }

    /**
     * 取消订单
     * @param $data
     * @return array|bool|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function cancelOrder($data) {
        CommonService::objectInit()->checkRequest('cancelOrder');
        $validate = new Validate([
            'order_no' => 'require',
        ]);
        if (!$validate->check($data)) throw new \Exception($validate->getError());
        $model = Order::_whereCV(['order_no' => $data['order_no']]);
        if (!empty($data['mid'])) $model->where('mid', $data['mid']);
        $order = $model->find();
        if ($order === null) throw new \Exception('订单号不正确!');
        if ($order['status'] != '待付款') throw new \Exception('不是待付款订单不可以取消!');
        $order['status'] = '交易关闭';
        $order->allowField(true)->save();
        $this->typeService($order['type'])->cancelOrder($order, $data);
        PayService::cancelOrder($order);//取消订单
        return $order;
    }

    /**
     * 申请退货或退款
     */
    public function returnOrder($param) {
        $validate = new Validate([
            'order_no' => 'require',
            'mid' => 'require',
            'return_type' => 'require',
            'mobile' => 'require',
            'code' => 'require',
            'type' => 'require',
            'name' => 'require'
        ]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        $IsCode = IsCode(trim($param['code']), $param['mobile']);
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }
        $order = Order::_whereCV(['order_no' => $param['order_no'], 'mid' => $param['mid']])->find();
        if ($order === null) throw new \Exception('订单号不正确!');
        if ($order['status'] != '待收货' && $order['status'] != '待发货') throw new \Exception('该状态无法申请退款!');
        $this->typeService($order['type'])->returnOrder($order);
        $d = [
            'uid' => $order['mid'],
            'order_no' => $order['order_no'],
            'reason' => $param['reason'] ?: '',
            'goods_type' => $order['type'],
            'name' => $param['name'],
            'status' => '退款申请',
            'create_time' => date('Y-m-d H:i:s'),
            'return_type' => $param['return_type'],
            'order_status' => $order['status'],
            'mobile' => $param['mobile'],
        ];
        if ($param['type'] == 2) {
            if (empty($param['bankcard']) || empty($param['bankname']) || empty($param['bankbranch'])) throw new \Exception('银行卡信息不能留空!');
            $d['bankcard'] = $param['bankcard'];
            $d['bankname'] = $param['bankname'];
            $d['bankbranch'] = $param['bankbranch'];
        } else {
            if (empty($param['alipay'])) throw new \Exception('支付宝账号不能为空');
            $d['alipay'] = $param['alipay'];
        }
        $return = OrderGoodsReturn::_whereCV('order_no', $order['order_no'])->find();
        if ($return === null) {
            $return = OrderGoodsReturn::create($d, true);
        } else {
            $return->allowField(true)->save($d);
        }
        $order['status'] = '退款中';
        $order->save();
        return $return;
    }

    /**
     * 确认退款
     */
    public function confirmRefund($order_no) {
        $order = Order::_whereCV(['order_no' => $order_no])->find();
        if ($order === null) throw new \Exception('订单号不正确!');
        if ($order['status'] != '退款中') throw new \Exception('该订单不是退款中的订单!');
        PayService::cancelOrder($order);//取消订单
        $order['status'] = '已退款';
        $order->save();
        return $order;
    }

    /**
     * 驳回退款
     * @param array|null $param
     */
    public function rejectRefund($param) {
        if (empty($param['order_no'])) throw new \Exception('订单号不能为空!');
        $return = OrderGoodsReturn::_whereCV('order_no', $param['order_no'])->find();
        if ($return === null) throw new \Exception('未找到退款申请!');
        $order = Order::_whereCV('order_no', $param['order_no'])->find();
        if ($order === null) throw new \Exception('未找到订单信息!');
        $order['status'] = $return['order_status'];
        $return['status'] = '退款驳回';
        $order->save();
        $return->save();
        return $return;
    }

    /**
     * 确认订单
     * @param $order_no
     * @param $mid
     */
    public function confirmOrder($order_no, $mid = 0) {
        CommonService::objectInit()->checkRequest('confirmOrder');
        if (empty($order_no)) throw new \Exception('订单号不能为空!');
        $model = Order::_whereCV('order_no', $order_no);
        if ($mid > 0) $model->where('mid', $mid);
        $order = $model->find();
        if ($order === null) throw new \Exception('订单号不正确!');
        if ($order['status'] !== '待收货') throw new \Exception('未发货订单不可以确认收货!');
        $return = OrderGoodsReturn::_whereCV('order_no', $order['order_no'])->find();
        if ($return !== null) {
            switch ($return['status']) {
                case '退款申请':
                    throw new \Exception('商品还在退款');
                    break;
                case '退货完成':
                    throw new \Exception('商品已退款');
                    break;
            }
        }
        $pay = PayService::confirmOrderCheckPay($order);
        try {
            Db::startTrans();
            $order['status'] = '已完成';
            $order->save();
            $rights = new RightsService();
            $rights->teamRightsForAli_v2($order['mid'], $order, $pay);
            Db::commit();
            UpgradeService::objectInit()->userUpgradeCheck(MemberService::objectInit()->getParentId($order['mid']));
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $order;
    }

    public function getStatusList() {
        return Order::objectInit()->getStatusList();
    }

    public function getOrderList($param) {
        $model = Order::objectInit();
        if (!empty($param['mid'])) $model->where('mid', $param['mid']);
        if (!empty($param['status'])) $model->whereCV('status', $param['status']);
        if (!empty($param['order_no'])) $model->where('order_no', $param['order_no']);
        $list = $model->with(['goods', 'payInfo'])->order('id', 'desc')->paginate();
        foreach ($list as $item) {
            $this->typeService($item['type'])->getOrderList($item);
        }
        return $list;
    }
}







