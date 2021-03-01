<?php


namespace app\api\service;


use app\api\model\bargain\BargainCateMode;
use app\api\model\bargain\BargainGoodsAttrModel;
use app\api\model\bargain\BargainGoodsModel;
use app\api\model\bargain\BargainJoinModel;
use app\api\model\bargain\BargainOrderModel;
use app\api\model\bargain\BargainRedpacketLogModel;
use app\BaseService;
use app\service\CommonService;
use think\Cache;
use think\Db;
use think\Exception;

class BargainService extends BaseService {
    public $endTime = 86400;//24小时,86400
    public $endTime2 = 172800;//48小时后自动砍价,或者取消订单,172800
    public $endTime3 = 1800; //30分钟自动取消订单,1800

    /**
     * 砍价开关
     * @return string
     */
    public function bargainSwitch() {
        $switch = Db::name('config_new')->where('name', 'bargain_switch')->value('info');
        switch ($switch) {
            case '1':
                $switch = '开启';
                break;
            case '0':
            default:
                $switch = '关闭';
                break;
        }
        return $switch;
    }

    /**
     * 减少库存
     * @param $goods_id
     * @param $attr_id
     * @throws Exception
     */
    static function subStock($goods_id, $attr_id) {
        $stock = BargainGoodsAttrModel::objectInit()->where(['goods_id' => $goods_id, 'attr_id' => $attr_id])->value('stock');
        if ($stock < 1) throw new Exception('产品库存不足');
        BargainGoodsAttrModel::objectInit()->where(['goods_id' => $goods_id, 'attr_id' => $attr_id])->setDec('stock', 1);
    }

    /**
     * 增加库存
     * @param $goods_id
     * @param $attr_id
     * @throws Exception
     */
    static function addStock($goods_id, $attr_id) {
        BargainGoodsAttrModel::objectInit()->where(['goods_id' => $goods_id, 'attr_id' => $attr_id])->setInc('stock', 1);
    }

    /**
     * 增加销量
     * @param $goods_id
     * @throws Exception
     */
    static public function addBargainSaleNum($goods_id) {
        BargainGoodsModel::objectInit()->where('goods_id', $goods_id)->setInc('saleNum', 1);
    }

    /**
     * 获取砍价商品列表
     * @param $param
     * @return array
     */
    public function bargainGoodsList($param) {
        $bargainCate = BargainCateMode::objectInit();
        $bargainGoods = new BargainGoodsModel();
        if ($param['cate_id']) {
            $cates = $bargainCate->field('id,name,desc')->where('id', $param['cate_id'])->order('order_list desc')->select();
        } else {
            $cates = $bargainCate->field('id,name,desc')->order('order_list desc')->select();
        }
        foreach ($cates as &$v) {
            $v['detail'] = $bargainGoods->getList(['cate_id' => $v['id']], $param['page'] ?: 1);
        }
        if (empty($cates)) return [];
        return $cates->toArray();
    }

    /**
     * 获取砍价商品详情
     * @param $param
     * @return array
     * @throws Exception
     */
    public function bargainGoodsDetail($param) {
        if (!$param['goods_id']) throw new Exception('缺少参数');
        $bargainGoods = new BargainGoodsModel();
        $detail = $bargainGoods->getDetail($param['goods_id']);
        return $detail;
    }

    /**
     * 砍价创建订单
     * @param $param
     * @return string
     * @throws Exception
     */
    public function bargainCreateOrder($param, $i = 0) {
        $param['uid'] = TokenService::getCurrentUid();
        if (!$param['goods_id'] || !$param['attr_id'] || !$param['quantity']) throw new Exception('缺少参数');
        if (Cache::get('kjorder_' . $param['goods_id'])) {
            if ($i > 2) {
                throw new Exception('太多人下单啦,请重试!');
            } else {
                sleep(1);
                return $this->bargainCreateOrder($param, ++$i);
            }
        } else {
            Cache::set('kjorder_' . $param['goods_id'], 1, 5);
            self::subStock($param['goods_id'], $param['attr_id']);
        }
        $bargainOrder = new BargainOrderModel();
        $oldOrderNo = $bargainOrder
            ->where('uid', $param['uid'])
            ->where('goods_id', $param['goods_id'])
            ->where('status', 'in', [0, 1, 2])
            ->value('order_no');
        if ($oldOrderNo) throw new Exception('已存在该商品的砍价订单');
        $res = $bargainOrder->createOrder($param);
        $bargainOrder->where('id', $res['id'])->update(['kl' => self::getKL($res['id'])]);
        self::addBargainSaleNum($param['goods_id']);
        return 'YF' . $res['order_no'];
    }

    /**
     * 砍价支付预付款
     * @param $param
     * @return mixed
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bargainPrepay($param) {
        $uid = TokenService::getCurrentUid();
        if (!$param['order_no'] || !$param['paytype']) throw new Exception('缺少参数');
        $bargainOrder = new BargainOrderModel();
        $order = $bargainOrder->where('order_no', self::findOrderNo($param['order_no']))->find();
        if (empty($order)) throw new Exception('找不到当前订单');
        if ($order['status'] != 0) throw new Exception('订单状态错误');
        if ((float)$order['pre_price']) {//是否为0元支付
            $payInfo = OrderService::pay($uid, $order['id'], $order['order_no'], $order['pre_price'], $param['paytype'], '留莲忘返-砍价预付款', 11);
            return $payInfo;
        } else {
            $res = $this->updatePrepayOrderStatus($param['order_no']);
            if (!$res) throw new Exception('支付异常');
            return ['order_no' => $param['order_no']];
        }
    }

    /**
     * 砍价支付尾款
     * @param $param
     * @return mixed
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bargainFinalpay($param) {
        $uid = TokenService::getCurrentUid();
        if (!$param['order_no'] || !$param['paytype'] || !$param['address_id']) throw new Exception('缺少参数');
        $bargainOrder = new BargainOrderModel();
        $order = $bargainOrder
            ->where('uid', $uid)
            ->where('order_no', $param['order_no'])
            ->find();
        if (empty($order)) throw new Exception('找不到当前订单');
        if ($order['status'] != '待付尾款') throw new Exception('订单状态错误');
        $update = [];
        BargainOrderModel::getAddressInfo($update, $param['address_id'], $uid);
        $bargainOrder
            ->where('order_no', $param['order_no'])
            ->update($update);
        if (!Db::name('order_info')->where('order_no', $param['order_no'])->value('id')) {
            BargainService::objectInit()->createSelfOrder($param['order_no']);
        } else {
            Db::name('order_info')
                ->where('order_no', $param['order_no'])
                ->update($update);
        }
        if ((float)$order['cost_price']) {//是否为0元支付
            $payInfo = OrderService::pay($uid, $order['id'], $order['order_no'], $order['cost_price'], $param['paytype'], '留莲忘返-砍价尾款', 11);
            return $payInfo;
        } else {
            $res = $this->updateFinalpayOrderStatus($param['order_no']);
            if (!$res) throw new Exception('支付异常');
            return ['order_no' => $param['order_no']];
        }
    }

    /**
     * 砍价预付款更新状态
     * @param $orderNo
     * @return BargainOrderModel
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function updatePrepayOrderStatus($orderNo) {
        $find = (new BargainOrderModel())->where('order_no', self::findOrderNo($orderNo))->where('status', 0)->value('id');
        if (empty($find)) throw new Exception('订单状态异常');
        $res = (new BargainOrderModel())->where('order_no', self::findOrderNo($orderNo))->update(['status' => '砍价中', 'pay_status' => '已支付预付款', 'pay_time' => time()]);
        return $res;
    }

    /**
     * 砍价尾款更新状态
     * @param $orderNo
     * @return BargainOrderModel
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function updateFinalpayOrderStatus($orderNo) {
        $find = (new BargainOrderModel())->field('uid,order_no,cost_price,goods_id,goods_name,quantity,attr_id,attr_title,img_url,recept_name,recept_mobile,province,city,country,detail')->where('order_no', $orderNo)->where('status', 2)->find();
        if (!$find) throw new Exception('订单状态异常');
        $res = (new BargainOrderModel())->where('order_no', $orderNo)->update(['status' => '已完成', 'pay_status' => '已支付尾款', 'update_time' => time()]);
        Db::name('order_info')->where('order_no', $orderNo)->update(['status' => 3]);
        return $res;
    }

    /**
     * 创建自营订单
     * @param $orderNo
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createSelfOrder($orderNo) {
        $find = (new BargainOrderModel())->field('uid,order_no,cost_price,goods_id,goods_name,quantity,attr_id,attr_title,img_url,recept_name,recept_mobile,province,city,country,detail')->where('order_no', $orderNo)->where('status', 2)->find();
        if (!$find) throw new Exception('订单异常');
        $parentNo = getOrderNo('PW');
        Db::name('order_parent')->insertGetId([
            'uid' => $find['uid'],
            'parent_no' => $parentNo,
            'total' => $find['cost_price'],
            'pay_amount' => $find['cost_price'],
            'create_time' => date('Y-m-d H:i:s')
        ]);
        $orderId = Db::name('order_info')->insertGetId([
            'uid' => $find['uid'],
            'parent_no' => $parentNo,
            'order_no' => $orderNo,
            'source' => 1,
            'order_type' => 1,
            'total' => $find['cost_price'],
            'amount' => $find['cost_price'],
            'pay_amount' => $find['cost_price'],
            'cal_money' => $find['cost_price'],
            'sp_gxz' => $find['cost_price'],
            'status' => 2,
            'create_time' => date('Y-m-d H:i:s'),
            'finish_time' => date('Y-m-d H:i:s'),
            'recept_name' => $find['recept_name'],
            'recept_mobile' => $find['recept_mobile'],
            'province' => $find['province'],
            'city' => $find['city'],
            'country' => $find['country'],
            'detail' => $find['detail'],
        ]);
        Db::name('order_detail')->insert([
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'sale_price' => $find['cost_price'],
            'cost_price' => $find['cost_price'],
            'goods_id' => $find['goods_id'],
            'goods_name' => $find['goods_name'],
            'goods_num' => $find['quantity'],
            'goods_attr_id' => $find['attr_id'],
            'free_delivery' => 1,
            'create_time' => date('Y-m-d H:i:s'),
            'postage' => 0,
            'gxz' => $find['cost_price'],
            'thumb' => $find['img_url'],
            'field' => $find['attr_title']
        ]);
    }

    /**
     * 砍价详情
     * @param array $param
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bargainDetail($param) {
        $bargainOrder = new BargainOrderModel();
        if (empty($param['order_no']) && empty($param['kl'])) {
            throw new Exception('缺少参数');
        }
        $param['order_no'] = $param['kl'] ? $bargainOrder->where('kl', self::explodeKl($param['kl']))->value('order_no') : $param['order_no'];
        $order = $bargainOrder->where('order_no', self::findOrderNo($param['order_no']))->find();
        if (empty($order)) throw new Exception('找不到该订单');
        $order['saleNum'] = BargainGoodsModel::objectInit()->where('goods_id', $order['goods_id'])->value('saleNum') ?: 0;
        $order['last_price'] = $order['og_price'];
        $join = BargainJoinModel::objectInit()
            ->alias('b')
            ->join('members m', 'm.id=b.uid', 'left')
            ->field('b.*,m.avatar,m.mobile')
            ->where('order_no', $param['order_no'])
            ->select();
        $temp = [];
        foreach ($join as &$v) {
            $v['mobile'] = self::mobileDeal($v['mobile']);
            $temp[] = $v;
        }
        $order['join'] = $temp;
        if (!empty($order['join'])) {
            $order['last_price'] = bcsub($order['last_price'], BargainJoinModel::objectInit()->where('order_no', $param['order_no'])->sum('bargain_amount'), 2);
        }
        if ($order['pay_time']) {
            $order['end_time'] = $order['pay_time'] + $this->endTime2;
        } else {
            $order['end_time'] = null;
        }
        $order['last_price'] = getFloor($order['last_price'], 2);
        return $order->toArray();
    }

    /**
     * 用户砍价所有详情
     * @param array $param
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bargainDetails() {
        if (!Request()->header('token')) return null;
        $uid = TokenService::getCurrentUid();
        $bargainOrder = new BargainOrderModel();
        $orders = $bargainOrder->where(['uid' => $uid, 'status' => ['<=', 2]])->order('id desc,status desc,create_time desc')->select()->toArray();
        if (empty($orders)) return null;
        foreach ($orders as &$v) {
            $v['last_price'] = $v['og_price'];
            $v['join'] = BargainJoinModel::objectInit()
                ->alias('b')
                ->join('members m', 'm.id=b.uid', 'left')
                ->field('b.*,m.avatar,m.mobile')
                ->where('order_no', $v['order_no'])
                ->select()->toArray();
            foreach ($v['join'] as &$vv) {
                self::mobileDeal($vv['mobile']);
            }
            if (!empty($v['join'])) {
                $v['last_price'] = bcsub($v['last_price'], BargainJoinModel::objectInit()->where('order_no', $v['order_no'])->sum('bargain_amount'), 2);
            }
            if ($v['status'] == '待付款') {
                $v['end_time'] = $v['create_time'] + $this->endTime3;
            } else {
                if ($v['pay_time']) {
                    $v['end_time'] = $v['pay_time'] + $this->endTime2;
                } else {
                    $v['end_time'] = null;
                }
            }
        }
        return $orders;
    }

    /**
     * 用户砍价所有详情
     * @param array $param
     * @return array
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function publicDargainDetails($page) {
        $bargainOrder = new BargainOrderModel();
        $orders = $bargainOrder
            ->where('status', 1)
            ->where('update_time', '<=', time() - $this->endTime)
            ->where('auto', 0)
            ->page($page, 20)
            ->select();
        if (empty($orders)) return null;
        $orders = $orders->toArray();
        foreach ($orders as &$v) {
            $v['last_price'] = $v['og_price'];
            $v['join'] = BargainJoinModel::objectInit()
                ->alias('b')
                ->join('members m', 'm.id=b.uid', 'left')
                ->field('b.*,m.avatar,m.mobile')
                ->where('order_no', $v['order_no'])
                ->select()->toArray();
            foreach ($v['join'] as &$vv) {
                self::mobileDeal($vv['mobile']);
            }
            if (!empty($v['join'])) {
                $v['last_price'] = bcsub($v['last_price'], BargainJoinModel::objectInit()->where('order_no', $v['order_no'])->sum('bargain_amount'), 2);
            }
            if ($v['pay_time']) {
                $v['end_time'] = $v['pay_time'] + $this->endTime2;
            } else {
                $v['end_time'] = null;
            }
        }
        return $orders;
    }

    /**
     * 参与砍价
     * @param $param
     * @return float|int
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function joinBargain($param, $uid = null) {
        $param['uid'] = $uid ? $uid : TokenService::getCurrentUid();
        if (!$param['order_no']) throw new Exception('缺少参数');
        if ($uid >= 1001) {
            if (Cache::get('joinBargain' . $param['order_no'])) {
                throw new Exception('请稍后');
            } else {
                Cache::set('joinBargain' . $param['order_no'], 1, 3);
            }
        }
        $join = new BargainJoinModel();
        if ($param['uid'] > 1000) {
            $thisUid = (new BargainOrderModel())->where('order_no', $param['order_no'])->value('uid');
            if ($thisUid != $param['uid']) {
                $count = $join
                    ->alias('j')
                    ->join('bargain_order o', 'o.order_no = j.order_no')
                    ->join('bargain_goods g', 'g.goods_id = o.goods_id')
                    ->field('j.*,g.cate_id')
                    ->where('j.uid', $param['uid'])
                    ->where('j.from_uid', '<>', $param['uid'])
                    ->where('j.create_time', '>=', CommonService::todayZeroTime())
                    ->where('g.cate_id', self::getCateIdByOrderNo($param['order_no']))
                    ->count();
                $limitTimes = self::objectInit()->getLimitTimesByOrderNo($param['order_no']);
                if ($count >= $limitTimes) throw new Exception('每天参与到达上限');
            }
        }
        $order = (new BargainOrderModel())
            ->field("uid,og_price,red_packet,cost_price,copies,(red_packet/copies) single,status,pay_status,goods_name")
            ->where('order_no', $param['order_no'])
            ->find()->toArray();
        if ($order['pay_status'] == '未支付') throw new Exception('订单不能参与砍价');
        if (!($order['status'] == '砍价中' && $order['pay_status'] == '已支付预付款')) throw new Exception('订单已完成砍价');
        $find = $join->where(['order_no' => $param['order_no'], 'uid' => $param['uid']])->value('id');
        if ($find) throw new Exception('无法再次参与此次砍价');
        $allCopies = $join->getAllCopies($param['order_no']);
        $order['lastCopies'] = $order['copies'] - $allCopies;
        if ($order['uid'] != $param['uid']) {
            $res = $join->joinBargain($param['uid'], $order['uid'], $param['order_no'], $order['lastCopies'], $order['single'], $order['copies'], $order['og_price'] - $order['cost_price'], $order['status'],$order['red_packet']);
            $res2 = RightsService::giveRedpacket($param['uid'], $res['red_packet'], '砍价红包', $param['order_no']);
            if (empty($res) || !$res2) throw new Exception('参与失败');
        } else {
            $res = $join->selfJoinBargain($param['uid'], $order['uid'], $param['order_no'], $order['lastCopies'], $order['single'], $order['copies'], $order['og_price'] - $order['cost_price'], $order['status']);
        }
        if ($res['status'] == '待付尾款') {
            $res['sms'] = CommonService::send_sms_aliyun_new(Db::name('members')->where('id', $order['uid'])->value('mobile'), ['goodsName' => self::getSmsGoodsName($order['goods_name'])], 'SMS_209832191');
        }
        return $res;
    }

    /**
     * 生成口令
     * @param $id
     * @return string
     */
    static public function getKL($id) {
        $kl = substr(md5($id), 0, 5);
        $kl .= substr($id, -1);
        $kl .= substr(base64_encode($id), 0, 4);
        return $kl;
    }

    /**
     * 获取口令
     * @param $kl
     * @return mixed|string
     * @throws Exception
     */
    static public function explodeKl($kl) {
        $klArr = explode('#', $kl);
        switch (count($klArr)) {
            case 1:
                $kl = $klArr[0];
                break;
            case 3:
                $kl = $klArr[1];
                break;
            default:
                throw new Exception('口令获取失败');
                break;
        }
        return $kl;
    }

    /**
     * 强制参与砍价
     * @param $order_no
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static function enforceJoinBargain($order_no, $uidArr = []) {
        $uid = CommonService::getRandMemberId();
        if (count($uidArr) > 50) return false;
        if (in_array($uid, $uidArr)) self::enforceJoinBargain($order_no, $uidArr);
        $uidArr[] = $uid;
        $res = self::joinBargain(['order_no' => $order_no], $uid);
        return $res;
    }


    /**
     * 砍价机器人
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bargainRobot() {
        $expiredOrder = BargainOrderModel::objectInit()
            ->field('order_no,status')
            ->whereCV('status', '砍价中')
            ->where('update_time', '<=', time() - $this->endTime2)
            ->where('auto', 0)
            ->page(1, 20)
            ->select()->toArray();
        foreach ($expiredOrder as $v) {
            $res = self::enforceJoinBargain($v['order_no']);
            dump($res);
        }
        dump('success');
    }

    /**
     * 半小时内未付款订单取消
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function cancelExpiredOrder() {
        $expiredOrder = BargainOrderModel::objectInit()
            ->field('order_no')
            ->whereCV('status', '待付款')
            ->where('create_time', '<=', time() - $this->endTime3)
            ->select();
        foreach ($expiredOrder as $v) {
            $orderNo = self::findOrderNo($v['order_no']);
            dump($orderNo);
            BargainService::cancelOrder($orderNo);
        }
        $expiredOrder2 = BargainOrderModel::objectInit()
            ->field('order_no')
            ->whereCV('status', '砍价中')
            ->where('auto', 1)
            ->where('pay_time', '<=', time() - $this->endTime2)
            ->select();
        foreach ($expiredOrder2 as $v) {
            $orderNo = self::findOrderNo($v['order_no']);
            dump($orderNo);
            BargainService::cancelOrder($orderNo);
        }
        exit('success');
    }

    /**
     * 取消订单
     * @param $order_no
     */
    static function cancelOrder($order_no) {
        if (Cache::get('bargain_cancelOrder_' . $order_no)) {
            throw new Exception('正在处理');
        } else {
            Cache::set('bargain_cancelOrder_' . $order_no, $order_no, 3);
        }
        BargainOrderModel::objectInit()->where('order_no', self::findOrderNo($order_no))->update(['status' => '已取消']);
        $order = BargainOrderModel::objectInit()->field('goods_id,attr_id')->where('order_no', self::findOrderNo($order_no))->find();
        self::addStock($order['goods_id'], $order['attr_id']);
    }

    /**
     * 获取用于查找的订单号
     * @param $orderNo
     * @return string
     */
    static function findOrderNo($orderNo) {
        return (strlen($orderNo) > 21) ? 'KJ' . substr($orderNo, CommonService::orderTagLength($orderNo), strlen($orderNo)) : $orderNo;
    }

    /**
     * 手机号隐私处理
     * @param $mobile
     */
    static function mobileDeal(&$mobile) {
        $mobile = substr($mobile, 0, 3) . '****' . substr($mobile, -4);
        return $mobile;
    }

    /**
     * 获取符合短信标准的商品标题
     * @param $name
     * @return string
     */
    static function getSmsGoodsName($name) {
        if (mb_strlen($name) > 10) $name = mb_substr($name, 0, 7) . '...';
        return $name;
    }

    /**
     * 根据订单号获取红包领取次数限制
     * @param $order_no
     * @return bool|float|int|mixed|string|null
     */
    static function getLimitTimesByOrderNo($order_no) {
        $limit_times = (new BargainCateMode())->where('id', self::getCateIdByOrderNo($order_no))->value('limit_times') ?: 0;
        return $limit_times;
    }

    /**
     * 根据订单号获取分类ID
     * @param $order_no
     * @return bool|float|int|mixed|string|null
     */
    static function getCateIdByOrderNo($order_no) {
        $goods_id = (new BargainOrderModel())->where('order_no', $order_no)->value('goods_id');
        $cate_id = (new BargainGoodsModel())->where('goods_id', $goods_id)->value('cate_id');
        return $cate_id;
    }
}
