<?php


namespace app\api\service\Seckill;

use app\api\BaseService;
use app\api\model\order\AliOrder;
use app\api\model\Seckill\SeckillOrder;
use app\api\service\Alibaba\AlibabaService;
use app\api\service\Alibaba\AlibabaServiceV2;
use app\api\service\RedisServer;
use app\api\service\TokenService;
use mysql_xdevapi\Table;
use think\Cache;
use think\Db;

class SeckillOrderServer extends BaseService {
    protected function getQuantityLimit($redis, $key) {
        return (int)$redis->get($key . '-quantity_limit');
    }

    protected function getSellQuantity($redis, $key, $uid) {
        $keys = explode('-', $key);
        if (count($keys) > 2) {
            unset($keys[2]);
            $key = implode('-', $keys);
        }
        return (int)$redis->get($key . '-' . $uid);
    }

    protected function setSellQuantity($redis, $key, $uid, $quantity) {
        $num = $this->getSellQuantity($redis, $key, $uid);
        $keys = explode('-', $key);
        if (count($keys) > 2) {
            unset($keys[2]);
            $key = implode('-', $keys);
        }
        return $redis->set($key . '-' . $uid, $num + $quantity);
    }

    protected function getUserOrderNum($redis, $key, $uid) {
        $keys = explode('-', $key);
        if (count($keys) > 2) {
            unset($keys[2]);
            $key = implode('-', $keys);
        }
        return (int)$redis->get(implode('-', [$key, $uid, 'order']));
    }

    protected function setUserOrderNum($redis, $key, $uid, $quantity) {
        $num = $this->getUserOrderNum($redis, $key, $uid);
        $keys = explode('-', $key);
        if (count($keys) > 2) {
            unset($keys[2]);
            $key = implode('-', $keys);
        }
        return $redis->set(implode('-', [$key, $uid, 'order']), $num + $quantity);
    }

    protected function getStock($redis, $key) {
        return (int)$redis->get($key);
    }

    protected function setStock($redis, $key, $quantity) {
        $num = $this->getStock($redis, $key);
        return $redis->set($key, $num + $quantity);
    }

    public function addOrder(array $param) {
        $v = SeckillGoodsServer::objectInit();
        $v->validateListId($param);
        $v->validateFeedId($param);
        if (empty($param['quantity'])) throw new \Exception('数量不能为空');
        if (empty($param['uid'])) throw new \Exception('用户id不能为空');
        if (empty($param['address_id'])) throw new \Exception('收货地址id不能为空');
        //if(empty($param['start_time']))throw new \Exception('开始时间不能为空');
        //if($param['start_time']>time())throw new \Exception('活动还未开始');
        $quantity = $param['quantity'];
        $uid = $param['uid'];
        $redis = RedisServer::objectInit();
        $key = '';
        $return = ['list_id' => $param['list_id'], 'feedId' => $param['feedId']];
        if (!isset($param['skuId'])) {
            $key = implode('-', [$param['list_id'], $param['feedId']]);
        } else {
            $param['skuId'] = (int)$param['skuId'];
            $key = implode('-', [$param['list_id'], $param['feedId'], $param['skuId']]);
            $return['skuId'] = $param['skuId'];
        }
        $stock = $this->getStock($redis, $key);
        if ($stock < 1) throw new \Exception('已经抢购完了哦');

        if ($stock < $quantity) throw new \Exception('没有这么多库存哦,仅剩下数量' . $stock);

        $quantity_limit = $this->getQuantityLimit($redis, $key);

        $sell_quantity = $this->getSellQuantity($redis, $key, $uid);

        if (($sell_quantity + $quantity) > $quantity_limit) {
            $userOrderNum = $this->getUserOrderNum($redis, $key, $uid);
            if ($userOrderNum == $sell_quantity) {
                if (Cache::get('seckill_fail' . $uid) && ($param['price'] ?: 100) == 0.01) {
                    throw new \Exception('仅限未参加过一分钱秒杀的新用户!');
                }
                throw new \Exception('抱歉,每个人限购数量' . $quantity_limit . ',您已经超购了哦!,请到订单列表查看');
            } else {
                throw new \Exception('订单正在后台生成中,请稍等..');
            }
        }
        $return['order_no'] = createOrderNo('MSME');
        if ($redis->lPush('orderList', ['uid' => $uid, 'quantity' => $quantity, 'order_no' => $return['order_no'], 'key' => $key, 'address_id' => $param['address_id']])) {
            $this->setSellQuantity($redis, $key, $uid, $quantity);//设置用户购买数
            $this->setStock($redis, $key, -$quantity);//减库存
            if ($redis->ttl($key . '-' . $uid) == -1) {
                $end_time = time() + $redis->ttl($key) + 300;
                $redis->expireAt($key . '-' . $uid, $end_time);
            }
            return $return;
        }
        throw new \Exception('抢购失败!');
    }

    public function consumerOrder() {
        $redis = RedisServer::objectInit();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        $aliServer = new AlibabaServiceV2();
        while (1) {
            $info = $redis->BrPop('orderList');
            $db = Db::connect([], true);
            try {
                $order_no = $info['order_no'];
                $key = explode('-', $info['key']);
                if ($key === false) continue;
                $data = ['uid' => $info['uid'], 'order_no' => $order_no, 'list_id' => $key[0], 'feedId' => $key[1], 'skuId' => 0];
                $goods = ['specId' => '', 'feedId' => $key[1]];
                $seckillGoods = [];
                if (count($key) > 2) {
                    $data['skuId'] = $key[2];
                    $seckillGoods = $db->name('seckill_goods_attr')->alias(' m')->field('m.*,g.free_shipping,g.title goods_name,a.title attr_name,a.specId')
                        ->join('seckill_goods g', 'g.list_id=m.list_id and g.feedId=m.feedId')
                        ->join('ali_product_v2_attr a', 'a.feedId=m.feedId and a.skuId=m.skuId')
                        ->where(['m.list_id' => $key[0], 'm.feedId' => $key[1], 'm.skuId' => $key[2]])
                        ->find();
                    if (!$seckillGoods) throw new \Exception('商品未查询到');
                    $goods['specId'] = $seckillGoods['specId'];
                } else {
                    $seckillGoods = $db->name('seckill_goods')->where(['feedId' => $key[1], 'list_id' => $key[0]])->find();
                    if (!$seckillGoods) throw new \Exception('商品未查询到');
                    $seckillGoods['goods_name'] = $seckillGoods['title'];
                    $seckillGoods['attr_name'] = '';
                }
                if ($seckillGoods['price'] == 0.01 && AliOrder::_whereCV('order_no', 'like', 'MSME%')->whereCVIn('status', ['待发货', '待收货', '已完成'])->where(['payamount' => '0.01', 'uid' => $info['uid']])->find() !== null) {
                    Cache::set('seckill_fail' . $order_no, 1, 300);
                    Cache::set('seckill_fail' . $info['uid'], 1, 3600 * 5);
                    $this->setUserOrderNum($redis, $info['key'], $info['uid'], $info['quantity']);
                    $this->setStock($redis, $info['key'], $info['quantity']);//加库存
                    continue;
                }
                if (empty($goods['specId'])) {
                    $goods['specId'] = null;
                }
                $aliGoods = $db->name('ali_product_v2')->where(['feedId' => $key[1]])->find();
                $address = $db->name('member_address')->where(['uid' => $info['uid'], 'id' => $info['address_id']])->find();
                if ($address === null) throw new \Exception('未找到用户地址信息');
                $cargoParamList = ['specId' => $goods['specId'], 'quantity' => $info['quantity'], 'offerId' => $goods['feedId']];
                $freight = $aliServer->preview4CybMedia([
                    'addressParam' => [
                        'address' => $address['detail'],
                        'phone' => '',
                        'mobile' => $address['mobile'],
                        'fullName' => $address['name'],
                        'postCode' => '',
                        'areaText' => $address['country'],
                        'townText' => '',
                        'cityText' => $address['city'],
                        'provinceText' => $address['province']
                    ],
                    'cargoParamList' => [$cargoParamList,]
                ]);
                //echo "预览完成\n";
                if (!empty($freight['errorCode'])) {
                    if ($freight['errorCode'] == '500_004') {//这个是接口方无库存
                        $redis->set($key, 0);//库存减到0
                    }
                    throw new \Exception('订单预览失败' . json_encode($freight, JSON_UNESCAPED_UNICODE));
                }
                $freight = current($freight['orderPreviewResuslt']);
                $old_price = current($freight['cargoList'])['finalUnitPrice'];
//                $freight['sumCarriage'] = 0;
//                $old_price = 100;
                $offers = ['id' => $goods['feedId'], 'specId' => $goods['specId'], 'price' => $old_price, 'num' => $info['quantity']];
                $aliOrder = $aliServer->createOrder4CybMedia([
                    'addressParam' => [
                        'address' => $address['detail'],
                        'phone' => '',
                        'mobile' => $address['mobile'],
                        'fullName' => $address['name'],
                        'postCode' => '',
                        'areaText' => $address['country'],
                        'townText' => $address['country'],
                        'cityText' => $address['city'],
                        'provinceText' => $address['province']
                    ],
                    'cargoParamList' => [[
                        'specId' => $goods['specId'],
                        'quantity' => $info['quantity'],
                        'offerId' => $goods['feedId']
                    ]],
                    'outerOrderInfo' => [
                        'mediaOrderId' => $order_no,
                        'phone' => $address['mobile'],
                        'offers' => [$offers]
                    ]
                ]);
                //echo "创建完成\n";
                if (!empty($aliOrder['errorCode'])) {
                    $this->setSellQuantity($redis, $info['key'], $data['uid'], -$info['quantity']);//设置用户购买数
                    throw new \Exception('订单创建失败:' . json_encode($aliOrder, JSON_UNESCAPED_UNICODE));
                }
                try {
                    //echo "开始创建订单\n";
                    $db->startTrans();
                    if (!$db->name('seckill_order')->insert($data)) throw new \Exception('插入失败');
                    $data = [
                        'uid' => $data['uid'],
                        'order_no' => $data['order_no'],
                        'feedId' => $data['feedId'],
                        'skuId' => $goods['specId'],
                        'orderId' => $aliOrder['result']['orderId'],
                        'status' => 0,
                        'pay_status' => 0,
                        'create_time' => date('Y-m-d H:i:s'),
                        'postage' => sprintf('%.2f', $freight['sumCarriage'] / 100),
                        'nopost' => sprintf('%.2f', $seckillGoods['price'] * $info['quantity']),
                        'unitprice' => $seckillGoods['price'],
                        'unitprice_old' => $old_price,
                        'quantity' => $info['quantity'],
                        'goodsname' => $seckillGoods['goods_name'],
                        'attrtitle' => $seckillGoods['attr_name'],
                        'imgUrl' => $aliGoods['thumb2'] ? $aliGoods['thumb2'] : $aliGoods['thumb'],
                        'order_type' => 0,
                        'sync' => 0
                    ];
                    if ($seckillGoods['free_shipping'] == 1) {
                        $data['postage'] = 0;
                    }
                    $data['total'] = $data['payamount'] = $data['postage'] + $data['nopost'];
                    if (!$db->name('ali_order')->insert($data)) {
                        throw new \Exception('订单创建失败');
                    }
                    //echo "创建订单完成\n";
                    $db->commit();
                    $this->setUserOrderNum($redis, $info['key'], $info['uid'], $info['quantity']);
                    $db->close();
                } catch (\PDOException $e) {
                    throw new \Exception($e->getMessage());
                }
            } catch (\Exception $e) {
                $db->rollBack();
                writeLog('redisOrder.log', $e->getMessage() . json_encode($info));
                continue;
            }
        }
    }

    public function ajaxOrderExists(array $param) {
        $v = SeckillGoodsServer::objectInit();
        $v->validateListId($param);
        $v->validateFeedId($param);
        if (empty($param['order_no'])) throw new \Exception('订单号不能为空!');
        if (Cache::get('seckill_fail' . $param['order_no'])) {
            throw new \Exception('仅限未参加过一分钱秒杀的新用户!');
        }
        $model = SeckillOrder::objectInit();
        $uid = TokenService::getCurrentUid();
        return $model->where(['list_id' => $param['list_id'], 'feedId' => $param['feedId'], 'uid' => $uid, 'order_no' => $param['order_no']])->find();
    }

    public function checkSeckillOrder() {
        $orders = Db::name('ali_order')->whereLike('order_no', 'MSME%')->where('TIMESTAMPDIFF(MINUTE, create_time, now())>15')->where('status', 0)->select();
        if ($orders->isEmpty()) return true;
        $redis = RedisServer::objectInit();
        $order_nos = array_column($orders->toArray(), 'order_no');
        $so = SeckillOrder::all(function ($query) use ($order_nos) {
            $query->whereIn('order_no', $order_nos);
        });
        $seckillOrders = [];
        foreach ($so as $key => $value) {
            $seckillOrders[$value['order_no']] = $value;
        }
        foreach ($orders as $order) {
            $key = [];
            $seckillOrder = $seckillOrders[$order['order_no']];
            if (empty($order['skuId'])) {
                $key = [$seckillOrder['list_id'], $seckillOrder['feedId']];
            } else {
                $key = [$seckillOrder['list_id'], $seckillOrder['feedId'], $seckillOrder['skuId']];
            }
            $key = implode('-', $key);
            if (!$this->setSellQuantity($redis, $key, $order['uid'], -$order['quantity'])) continue;

            $this->setStock($redis, $key, $order['quantity']);

            Db::name('ali_order')->where('id', $order['id'])->update(['status' => 5]);
        }
        return true;
    }

    /**
     * 验证订单库存
     * @param string $order_no 订单号
     * @param bool $reduceStock 是否减少库存
     * @throws \think\exception\DbException
     */
    static public function payOrder($order_no, $reduceStock = true) {
        return;
//        $seckillOrder=SeckillOrder::get(['order_no'=>$order_no]);
//        if(empty($seckillOrder))throw new \Exception('未找到该订单!');
//        $orderQuantity = Db::name('ali_order')->where( 'order_no' , $order_no)->value('quantity');
//        $key=[];
//        if(empty($seckillOrder['skuId'])){
//            $key=[$seckillOrder['list_id'],$seckillOrder['feedId']];
//        }else{
//            $key=[$seckillOrder['list_id'],$seckillOrder['feedId'],$seckillOrder['skuId']];
//        }
//        $key=implode('-',$key);
//        $redis=RedisServer::objectInit();
//        $quantity=$redis->get($key);
//        if($orderQuantity>$quantity)throw new \Exception('库存不足,库存数量只有'.$quantity);
//        if($reduceStock){
//            $redis->set($key,($quantity-$orderQuantity));
//        }
    }

    public function orderCancel($order_no) {
        $seckillOder = SeckillOrder::get(['order_no' => $order_no]);
        if (empty($seckillOder)) return;
        $orderQuantity = Db::name('ali_order')->where('order_no', $order_no)->value('quantity');
        $key = [];
        if (empty($seckillOder['skuId'])) {
            $key = [$seckillOder['list_id'], $seckillOder['feedId']];
        } else {
            $key = [$seckillOder['list_id'], $seckillOder['feedId'], $seckillOder['skuId']];
        }
        $key = implode('-', $key);
        $redis = RedisServer::objectInit();
        $this->setSellQuantity($redis, $key, $seckillOder['uid'], -$orderQuantity);
        $this->setStock($redis, $key, $orderQuantity);
    }
//    public static function test(){
//        $redis=RedisServer::objectInit();
//        dump($redis->keys('*'));
//        //dump($redis->get('19-615971914895-4583271239834'));
//    }

}
