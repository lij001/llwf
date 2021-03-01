<?php

namespace app\api\service\OrderFree;


use app\api\model\AliProductV2;
use app\api\model\AliProductV2Attr;
use app\api\service\Alibaba\AlibabaServiceV2;
use app\api\service\OrderService;
use app\api\service\RightsService;
use app\api\service\TokenService;
use app\api\BaseService;
use think\Db;
use think\Request;

class OrderFreeService extends BaseService {
    /**获取免单列表
     * @return array|void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function index() {
        if (Request::instance()->header('token')) {
            $uid = TokenService::getCurrentUid();
        } else {
            $uid = 1001;
        }
        $plan = Db::name('order_free_plan')->where([
            'start_time' => ['<', date('Y-m-d H:i:s')],
            'end_time' => ['>', date('Y-m-d H:i:s')],
            'status' => 1
        ])->find();
        if (empty($plan)) {
            $this->dieJson(2, '暂无内容');
        }
        $status = $this->getBuyNum($uid, $plan['id']);
        $goods = Db::name('order_free_goods')
            ->field('cost,status', true)
            ->where(['plan_id' => $plan['id'], 'status' => 1])
            ->order('orderList desc')
            ->select()->toArray();
        $alipro = new AliProductV2();
        foreach ($goods as &$v) {
            $aliGoods = $alipro->where('feedId', $v['goods_id'])->find();
            $v['imgUrl'] = $aliGoods['thumb'];
            $v['titlle'] = $aliGoods['title'];
            $thisOrder = Db::name('ali_order')->where([
                'uid' => $uid,
                'order_type' => 3,
                'feedId' => $v['goods_id'],
                'planId' => $plan['id'],
                'status' => ['<', 4]
            ])->order('id desc')->find();
            $v['order_no'] = $thisOrder['order_no'] ? $thisOrder['order_no'] : null;
            if (empty($thisOrder)) {
                $v['goodStatus'] = $status;
            } else {
                if ($thisOrder['pay_status']) {
                    $v['goodStatus'] = 3;
//                    $v['goodStatus'] = $status;
                } else {
                    $v['goodStatus'] = 2;
                }
            }
            $v['end_time'] = strtotime($plan['end_time']);
            $this->addHot($v['id'], $plan['id']);
        }
        if (empty($goods)) {
            $this->dieJson(2, '暂无内容');
        }
        return $goods;
    }

    public function getBuyNum_old($uid, $pid) {
        $member = Db::name('members')->field('star,number,create_time')->where('id', $uid)->find();
        if ($member['star'] == 0) {
            $times = 0;
            if ($member['create_time'] + 604800 > time()) {
                $times = Db::name('config_new')->where('name', 'order_free_times')->value('info');
            }
            $count = Db::name('ali_order')
                ->where([
                    'number' => $member['number'],
                    'order_type' => 3,
                ])
                ->count();
            $count2 = Db::name('ali_order')
                ->where([
                    'uid' => $uid,
                    'order_type' => 3,
                    'planId' => $pid,
                    'status' => ['<', 4]
                ])
                ->count();
            $res = $times + $count - $count2;
            if ($res > 0) {
                return 1;
            } else {
                return 0;
            }
        } else {
            return 1;
        }
    }

    /**
     * 获取用户的是否存在兑换次数
     * @param $uid
     * @param $pid
     * @return int
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getBuyNum($uid, $pid) {
        $member = Db::name('members')->field('star,number')->where('id', $uid)->find();
        if ($member['star'] == 0) {//获取用户每周可兑换次数
            $times = Db::name('config_new')->where('name', 'order_free_times')->value('info');
        } else {
            $times = Db::name('config_new')->where('name', 'order_free_times_zg')->value('info');
        }
        $count = Db::name('ali_order')//获取用户每周分享的次数
        ->where([
            'number' => $member['number'],
            'order_type' => 3,
            'planId' => $pid
        ])
            ->count();
        $count2 = Db::name('ali_order')//获取用户以兑换的次数
        ->where([
            'uid' => $uid,
            'order_type' => 3,
            'planId' => $pid,
            'status' => ['<', 4]
        ])
            ->count();
        $res = $times + $count - $count2;
        if ($res > 0) {
            return 1;
        } else {
            return 0;
        }
    }

    /**
     * 获取商品详情页
     * @param $goods_id
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getProduct($plan_id, $goods_id) {
        $plan = Db::name('order_free_plan')->where([
            'id' => $plan_id
        ])->find();
        if (strtotime($plan['end_time']) < time()) {
            $this->dieJson(2, '活动已过期');
        }
        if (!$plan['status']) {
            $this->dieJson(2, '当前活动已关闭');
        }
        $uid = TokenService::getCurrentUid();
        $product = Db::name('order_free_goods')
            ->alias('o')
            ->join('ali_product_v2 a', 'a.feedId = o.goods_id')
            ->field('o.id,o.plan_id,o.goods_id,o.skuId,o.price,o.post gxz,o.post,o.status,o.hot,a.title,a.title2,a.images,a.images2,a.desc,a.desc2,a.send_address')
            ->where(['o.plan_id' => $plan_id, 'o.goods_id' => $goods_id, 'o.type' => 1])
            ->find();
        $this->addHot($goods_id, $plan_id);
        if (empty($product)) {
            $this->dieJson(2, '商品失效');
        }
        if ($product['title2']) {
            $product['title'] = $product['title2'];
        }
        if ($product['images2']) {
            $product['images'] = $product['images2'];
        }
        if ($product['desc2']) {
            $product['desc'] = $product['desc2'];
        }
        unset($product['title2']);
        unset($product['images2']);
        unset($product['desc2']);

        $status = $this->getBuyNum($uid, $plan_id);
        $thisOrder = Db::name('ali_order')->where([
            'uid' => $uid,
            'order_type' => 3,
            'feedId' => $goods_id,
            'planId' => $plan_id,
            'status' => ['<', 4]
        ])->order('id desc')->find();
        $product['order_no'] = $thisOrder['order_no'] ? $thisOrder['order_no'] : null;
        if (empty($thisOrder)) {
            $product['goodStatus'] = $status;
        } else {
            if ($thisOrder['pay_status']) {
                $product['goodStatus'] = 3;
//                $product['goodStatus'] = $status;
            } else {
                $product['goodStatus'] = 2;
            }
        }
        $end_time = Db::name('order_free_plan')->where('id', $product['plan_id'])->value('end_time');
        $product['end_time'] = strtotime($end_time);
        return $product;
    }

    /**
     * 获取下单所需参数
     * @param $goodsId
     * @param $addressId
     * @param $uid
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function getOrderParam($goodsId, $addressId, $uid) {
        $addressInfo = Db::name('member_address')->where(['id' => $addressId, 'uid' => $uid])->find();
        if (empty($addressInfo)) {
            $this->dieJson(2, '请填选地址');
        }
        if (mb_strlen($addressInfo['name']) == 1) {
            $addressInfo['name'] .= '先生';
        }
        $addressParam = [
            "address" => $addressInfo['detail'],
            "mobile" => $addressInfo['mobile'],
            "phone" => $addressInfo['mobile'],
            "fullName" => $addressInfo['name'],
            "postCode" => "",
            "areaText" => $addressInfo['country'],
            "townText" => $addressInfo['country'],
            "cityText" => $addressInfo['city'],
            "provinceText" => $addressInfo['province']
        ];
        $planId = Db::name('order_free_plan')->where([
            'start_time' => ['<', date('Y-m-d H:i:s')],
            'end_time' => ['>', date('Y-m-d H:i:s')],
            'status' => 1
        ])->value('id');
        if (!$planId) {
            $this->dieJson(2, '暂无活动');
        }
        $goods = Db::name('order_free_goods')->where(['goods_id' => $goodsId, 'plan_id' => $planId])->find();
        $this->addHot($goodsId, $planId);
        if (strlen($goods['skuId']) < 2) {
            $goods['skuId'] = null;
        }
        $cargoParamList = [
            [
                "specId" => $goods['skuId'],
                "quantity" => 1,
                "offerId" => $goodsId,
            ]
        ];
        $order_no = "FEME" . date('YmdHis', time()) . rand(10000, 99999);
        $outerOrderInfo = [
            "mediaOrderId" => $order_no,
            "phone" => $addressInfo['mobile'],
            "offers" => [
                [
                    "id" => $goods['goods_id'],
                    "specId" => $goods['skuId'],
                    "price" => $goods['post'] * 100,
                    "num" => 1
                ]
            ]
        ];
        $post = $this->getPost($goodsId, $planId, $addressId);
        return [
            'addressParam' => $addressParam,
            'cargoParamList' => $cargoParamList,
            'outerOrderInfo' => $outerOrderInfo,
            'order_no' => $order_no,
            'goods' => $goods,
            'post' => $post
        ];
    }

    /**
     * 订单预览
     * @param $goodsId
     * @param $addressId
     * @param $uid
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function previewOrder($goodsId, $addressId) {
        $uid = TokenService::getCurrentUid();
        $orderParam = $this->getOrderParam($goodsId, $addressId, $uid);
        $alibaba = new AlibabaServiceV2();
        $return = $alibaba->preview4CybMedia(['addressParam' => $orderParam['addressParam'], 'cargoParamList' => $orderParam['cargoParamList']]);
        if (!empty($return['errorCode'])) {
            $this->dieJson('2', '下单失败');
        }
        return $orderParam['post'];
    }

    /**
     * 创建订单
     * @param $goodsId
     * @param $addressId
     * @return array
     * @throws \think\Exception
     */
    public function createOrder($goodsId, $addressId, $number) {
        $uid = TokenService::getCurrentUid();
        //抓取计划id
        $plan = Db::name('order_free_plan')->where([
            'start_time' => ['<', date('Y-m-d H:i:s')],
            'end_time' => ['>', date('Y-m-d H:i:s')],
            'status' => 1
        ])->find();
        $thisOrder = Db::name('ali_order')->where([
            'uid' => $uid,
            'feedId' => $goodsId,
            'planId' => $plan['id'],
            'status' => ['<', 4]
        ])->find();
//        if (!empty($thisOrder)) {
//            $this->dieJson(2, '该商品已存在订单,请前往支付');
//        }
        $orderParam = $this->getOrderParam($goodsId, $addressId, $uid);
        $alibaba = new AlibabaServiceV2();
        $return = $alibaba->createOrder4CybMedia([
            'addressParam' => $orderParam['addressParam'],
            'cargoParamList' => $orderParam['cargoParamList'],
            'outerOrderInfo' => $orderParam['outerOrderInfo']
        ]);
        if (!empty($return['errorCode'])) {
            if ($return['errorCode'] == 'BOOKED_BEYOND_THE_MAX_QUANTITY') {
                $errorMsg = '该订单超过了最大允许的购买量。';
            } else if ($return['errorCode'] == 'FAIL_BIZ_QUANTITY_UNMATCH_SELLUNIT_SCALE') {
                $errorMsg = '该笔订单的货品数量不符合订购要求。';
            } else if ($return['errorMsg'] == '拆单结果发生了变化。') {
                $errorMsg = '下单失败。';
            } else {
                $errorMsg = '商品已失效';
            }
            $this->dieJson(2, $errorMsg);
        } else {
            //抓取商品信息
            $alipro = new AliProductV2();
            $aliattrpro = new AliProductV2Attr();
            $attr = $aliattrpro->where(['specId' => $orderParam['goods']['skuId'], 'feedId' => $goodsId])->find();
            $attrtitle = $attr['title'];
            $goodsname = $alipro->where('feedId', $goodsId)->value('title');
            $imgUrl = $alipro->where('feedId', $goodsId)->value('thumb');

            $order['order_no'] = $orderParam['order_no'];
            $order['orderId'] = $return['result']['orderId'];
            $order['uid'] = $uid;
            $order['feedId'] = $goodsId;
            $order['skuId'] = $orderParam['goods']['skuId'];
            $order['quantity'] = 1;
            $order['payamount'] = $orderParam['post'];
            $order['total'] = $orderParam['post'];
            $order['unitprice'] = 0;
            $order['unitprice_old'] = $orderParam['goods']['cost'];
            $order['nopost'] = 0;
            $order['postage'] = $orderParam['post'];
            $order['status'] = 0;
            $order['pay_status'] = 0;
            $order['create_time'] = date('Y-m-d H:i:s', time());
            $order['attrtitle'] = $attrtitle;
            $order['goodsname'] = $goodsname;
            $order['imgUrl'] = $imgUrl;
            $order['order_type'] = 3;
            $order['source'] = 'orderfree';
            $order['balance'] = 0;
            $order['tz'] = 0;
            $order['planId'] = $plan['id'];
            if ($number) {
                $order['number'] = $number;
            }
            $this->addSale($goodsId, $plan['id']);
            $res = Db::name('ali_order')->insert($order);
            if (!$res) {
                $this->dieJson(2, '下单失败');
            }
        }
        return ['orderId' => $orderParam['order_no']];
    }

    /**
     * 免单支付
     * @param $orderNo
     * @param $payType
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function payOrder($orderNo, $payType) {
        $uid = TokenService::getCurrentUid();
        $order = Db::name('ali_order')->where('order_no', $orderNo)->find();
        if (empty($order)) {
            $this->dieJson(2, '找不到该订单');
        }
        return OrderService::pay($uid, $order['id'], $order['order_no'], $order['payamount'], $payType, '留莲忘返-商品消费', 10);
    }

    /**
     * 增加热度
     * @param $id
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function addHot($id, $pid) {
        Db::name('order_free_goods')->where(['id' => $id, 'plan_id' => $pid])->update([
            'hot' => Db::raw('hot+' . rand(1, 10))
        ]);
    }

    /**
     * 增加销量
     * @param $id
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function addSale($id, $pid) {
        Db::name('order_free_goods')->where(['goods_id' => $id, 'plan_id' => $pid])->update([
            'saleNum' => Db::raw('saleNum+' . 1)
        ]);
    }

    /**
     * 获取商品的邮费
     * @param $goodsId
     * @param $planId
     * @param $addressId
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getPost($goodsId, $planId, $addressId) {
        $goods = Db::name('order_free_goods')->where(['goods_id' => $goodsId, 'plan_id' => $planId])->find();
        if (empty($goods)) {
            $this->dieJson(2, '订单预览失败');
        }
        $post = $goods['post'];
        if ($goods['postId']) {
            $mode = Db::name('order_free_post_mode')->where('id', $goods['postId'])->find();

            if ($mode) {
                $postInfo = json_decode($mode['post_info']);
                self::areaOrderList($postInfo);
                $postDetail = [];
                foreach ($postInfo as $k => $v) {
                    $postDetail = $this->getAllArea($k, $v, $postDetail);
                }
            }
            $areaId = $this->getSelfArea($addressId);
            if (isset($postDetail[$areaId])) {
                $post = $postDetail[$areaId]["post"];
            }
        }
        return number_format(floor($post * 100) / 100, 2, '.', '');
    }

    /**
     * 将地区数据重新排序,方便判断地区邮费
     * @param $arr
     */
    static public function areaOrderList(&$arr) {
        $arrNew = [];
        foreach ($arr as $k => $v) {
            if (!empty($k) && !empty($v)) {
                $arrNew[] = ['key' => $k, 'value' => $v];
            }
        }
        $len = count($arrNew);
        for ($i = 0; $i < $len - 1; $i++) {//循环对比的轮数
            for ($j = 0; $j < $len - $i - 1; $j++) {//当前轮相邻元素循环对比
                if ($arrNew[$j]['key'] > $arrNew[$j + 1]['key']) {//如果前边的大于后边的
                    $tmp = $arrNew[$j];//交换数据
                    $arrNew[$j] = $arrNew[$j + 1];
                    $arrNew[$j + 1] = $tmp;
                }
            }
        }
        $arr2 = [];
        foreach ($arrNew as $v) {
            $arr2[$v['key']] = $v['value'];
        }
        $arr = $arr2;
    }

    /**
     * 获取不包邮的地区
     * @param $id
     * @param $post
     * @param array $allArea
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAllArea($id, $post, $allArea = []) {
        $thisArea = Db::name('baidu_map')->where('id', $id)->find();
        if (empty($thisArea)) {
            return $allArea;
        }
        $allArea[$id] = ['name' => $thisArea['aname'], 'post' => $post];
        $childArea = Db::name('baidu_map')->where('pid', $id)->select()->toArray();
        foreach ($childArea as $v) {
            $allArea[$v['id']] = ['name' => $v['aname'], 'post' => $post];
            $allArea = $this->getAllArea($v['id'], $post, $allArea);
        }
        return $allArea;
    }

    /**
     * 获取地址中的地区ID
     * @param $id
     * @return bool|float|mixed|string|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getSelfArea($id) {
        $address = Db::name('member_address')->where('id', $id)->find();
        $p = Db::name('baidu_map')->where('aname', $address['province'])->find();
        $ci = Db::name('baidu_map')->where(['aname' => $address['city'], 'pid' => $p['id']])->find();
        $thisId = Db::name('baidu_map')->where(['aname' => ['like', "%" . mb_substr($address['country'], 0, 2) . "%"], 'pid' => $ci['id']])->value('id');
        return $thisId;
    }
}

