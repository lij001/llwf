<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\goods\AliGoods;
use app\api\model\goods\AliGoodsAttr;
use app\api\model\order\AliOrder;
use app\api\model\PingGroup;
use app\api\model\pinggroup\PingGroupGoods;
use app\api\model\pinggroup\PingGroupGoodsAttr;
use app\api\model\pinggroup\PingGroupOpen;
use app\api\model\pinggroup\PingGroupOrder;
use app\api\model\redpacket\RedPacketList;
use app\api\service\Alibaba\AlibabaService;
use app\api\service\Alibaba\AlibabaServiceV2;
use DateTime;
use Svg\Tag\Group;
use think\Cache;
use think\Db;
use think\Validate;

class PingGroupService extends BaseService {
    protected function _index($param) {
        $model = PingGroup::objectInit();
        return $model;
    }

    public function pingGroupListPage($param) {
        $model = $this->_index($param);
        return $model->order('sort', 'asc')->order('id', 'desc')->paginate();
    }

    protected function validateList() {
        $rule = [
            'title' => 'require',
            'start_time' => 'require|date',
            'end_time' => 'require|date',
            'num' => 'require|number|>=:3|<:8',
            'reward' => 'require|number|<:70|>:0',
            'status' => 'require',
            'sort' => 'require|number',
        ];
        $msg = [
            'title' => '标题不能为空!',
            'start_time' => '日期格式不正确',
            'end_time' => '日期格式不正确',
            'num' => '拼团人数不正确',
            'reward' => '佣金百分比不能为空且不能大于等于70',
            'status' => '状态不正确',
            'sort' => '排序不能为空',
        ];
        return new Validate($rule, $msg);
    }

    protected function checkListData($param) {
        $where = $param['id'] ? ['id' => ['<>', $param['id']]] : [];
        $model = PingGroup::_whereCV('title', $param['title'])->where($where);
        if ($model->find() !== null) throw new \Exception('标题已被使用了!');
        $param['start_time'] = strtotime($param['start_time']);
        $param['end_time'] = strtotime($param['end_time']);
        if ($param['start_time'] == $param['end_time']) throw new \Exception('开始时间和结束时间不能同一天');
        if ($param['start_time'] > $param['end_time']) throw new \Exception('开始时间不能大于结束时间');
        $model->where("(start_time < {$param['start_time']} and end_time > {$param['start_time']}) or (start_time < {$param['end_time']} and end_time > {$param['end_time']})")
            ->where($where);
        if ($model->find() !== null) throw new \Exception('开始时间和结束时间不能和其他时间交错!');
        return 1;
    }

    public function add($param) {
        $valida = $this->validateList();
        if (!$valida->check($param)) throw new \Exception($valida->getError());
        $this->checkListData($param);
        PingGroup::create($param, true);
        return 1;
    }

    public function info($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        $model = PingGroup::_whereCV('id', $param['id']);
        if (!empty($param['status'])) {
            $model->whereCV('status', $param['status']);
        }
        return $model->find();
    }

    public function update($param) {
        $valida = $this->validateList();
        if (!$valida->check($param)) throw new \Exception($valida->getError());
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        $this->checkListData($param);
        $group = PingGroup::_whereCV('id', $param['id'])->find();
        if ($group->isUpdate(true)->save($param)) {
            $list = PingGroupGoods::_whereCV('group_id', $param['id'])->select();
            foreach ($list as $item) {
                if ($item['self_set'] === '关闭') {
                    $this->updateGoods([
                        'id' => $item['id'],
                        'reward' => $group['reward'],
                        'num' => $group['num'],
                        'freight_price' => $group['freight_price']
                    ], false);
                }
            }
        }
        return 1;
    }

    public function del($param) {
        if (empty($param['ids'])) return 1;
        PingGroup::destroy($param['ids']);
        return 1;
    }

    protected function _goodsIndex($param) {
        if (empty($param['group_id'])) throw new \Exception('拼团id不能为空!');
        $model = PingGroupGoods::_whereCV('group_id', $param['group_id']);
        if (!empty($param['status'])) {
            $model->whereCV('status', $param['status']);
        }
        if (!empty($param['goods_ids'])) {
            $model->whereIn('id', $param['goods_ids']);
        }
        return $model;
    }

    /**
     * @param $param
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function goodsListPage($param) {
        $model = $this->_goodsIndex($param);
        return $this->deleteGoodsNull($model->with('goods')->order('price', 'asc')->order('id', 'desc')->paginate(request()->get('offset', 20)));
    }


    /**
     * @param $list
     * @return mixed
     */
    protected function deleteGoodsNull($list) {
        foreach ($list as $k => $val) {
            if ($val['goods'] === null || $val['goods']['invalid'] === '失效') {
                PingGroupGoodsAttr::_whereCV(['group_id' => $val['group_id'], 'feed_id' => $val['feed_id']])->delete();
                $val->delete();
                unset($list[$k]);
            }
        }
        return $list;
    }

    private function getPriceCalculate($costPrice, $freight_price, $reward) {
        $const = (100 - 30 - $reward) / 100;
        $price = round(($costPrice + $freight_price) / $const, 1);
        return (float)$price;
    }

    private function getOldPriceCalculate($price) {
        return $price * 5;
    }

    public function addGoods($param) {
        if (empty($param['feed_id'])) throw new \Exception('商品id不能为空!');
        if (empty($param['group_id'])) throw new \Exception('拼团id不能为空!');
        $groupInfo = PingGroup::get($param['group_id']);
        if ($groupInfo === null) throw new \Exception('拼团id不正确');
        if (PingGroupGoods::_whereCV(['group_id' => $param['group_id'], 'feed_id' => $param['feed_id']])->find() !== null) throw new \Exception('该商品已经在列表中!');
        $info = GoodsService::ali()->getGoodsInfo(['feedId' => $param['feed_id'], 'invalid' => '生效']);
        if ($info === null) throw new \Exception('未找到该商品');
        $aliService = new AlibabaServiceV2();
        $costPrice = $aliService->getProductListPriceV2($info['feedId'], 0, 1);
        $price = $this->getPriceCalculate($costPrice, $groupInfo['freight_price'], $groupInfo['reward']);
        $data = [
            'group_id' => $param['group_id'],
            'feed_id' => $info['feedId'],
            'cost_price' => $costPrice,
            'old_price' => $this->getOldPriceCalculate($price),
            'price' => $price,
            'title' => $info['title'],
            'thumb' => $info['thumb'],
            'freight_price' => $groupInfo['freight_price'],
            'reward' => $groupInfo['reward'],
            'num' => $groupInfo['num']
        ];
        try {
            PingGroupGoods::startTrans();
            $groupGoods = PingGroupGoods::create($data, true);
            $this->addGoodsAttr($groupGoods, $info['attr']);
            PingGroupGoods::commit();
        } catch (\Exception $e) {
            PingGroupGoods::rollback();
            throw new \Exception($e->getMessage());
        }
    }

    protected function addGoodsAttr($groupGoods, $attr) {
        if (empty($attr)) throw new \Exception('属性不能为空!');
        $data = [];
        foreach ($attr as $val) {
            $costPrice = $val['consignPrice'];
            $price = $this->getPriceCalculate($costPrice, $groupGoods['freight_price'], $groupGoods['reward']);
            $data[] = [
                'group_id' => $groupGoods['group_id'],
                'feed_id' => $val['feedId'],
                'attr_id' => $val['id'],
                'title' => $val['title'],
                'cost_price' => $val['consignPrice'],
                'old_price' => $this->getOldPriceCalculate($price),
                'thumb' => $val['img'],
                'price' => $price
            ];
        }
        PingGroupGoodsAttr::insertAll($data);
    }

    public function addAllGoods($param) {
        if (empty($param['feed_ids'])) return;
        if (empty($param['group_id'])) throw new \Exception('拼团id不能为空');
        foreach ($param['feed_ids'] as $id) {
            try {
                $this->addGoods(['group_id' => $param['group_id'], 'feed_id' => $id]);
            } catch (\Exception $e) {
            }
        }
        return 1;
    }

    protected function getGoodsAttr($group_id, $feed_id, $status = null) {
        $model = PingGroupGoodsAttr::_whereCV(['group_id' => $group_id, 'feed_id' => $feed_id]);
        if ($status !== null) {
            $model->whereCV('status', $status);
        }
        return $model->with('attr')->select();
    }

    public function getGoodsInfo($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        $model = PingGroupGoods::objectInit();
        $info = $model->with('goods')->where('id', $param['id'])->find();
        if ($info === null) throw new \Exception('未找到拼团商品');
        $param['attr_status'] = $param['attr_status'] ?: null;
        $info['attr'] = $this->getGoodsAttr($info['group_id'], $info['feed_id'], $param['attr_status']);
        return $info;
    }

    public function updateGoods($param, $attr_status = true) {
        if (empty($param['id'])) throw new \Exception('商品id不能为空!');
        if (empty($param['freight_price'])) throw new \Exception('原价不能为空!');
        if (empty($param['reward']) || $param['reward'] >= 70 || $param['reward'] < 0) throw new \Exception('佣金折扣不能为空或大于等于70!');
        if (empty($param['num']) || $param['num'] < 3 || $param['num'] > 7) throw new \Exception('拼团人数不正常!');
        if ($attr_status && empty($param['prices'])) throw new \Exception('价格列表不能为空!');
        $info = PingGroupGoods::get($param['id']);
        if ($info === null) throw new \Exception('未找到商品!');
        $info['freight_price'] = $param['freight_price'];
        if (!empty($param['self_set'])) {
            $info['self_set'] = $param['self_set'];
        }
        if (!empty($param['old_price']) && $info['old_price'] < $param['old_price']) {
            $info['old_price'] = $param['old_price'];
        }
        if (!empty($param['status'])) {
            $info['status'] = $param['status'];
        }
        $not_change = $info['reward'] == $param['reward'];
        $info['num'] = $param['num'];
        try {
            PingGroupGoods::startTrans();
            $data = [];
            $checked_attr_ids = $param['checked_attr_ids'] ?: [];
            $cost_price = 100000;
            $price = 100000;
            $attrList = PingGroupGoodsAttr::_whereCV(['group_id' => $info['group_id'], 'feed_id' => $info['feed_id']])->select();
            foreach ($attrList as $key => $attr) {
                $attr['price'] = $this->getPriceCalculate($attr['cost_price'], $param['freight_price'], $param['reward']);
                if ($attr_status) {
                    if ($attr['price'] > $param['prices'][$key] && $not_change) {
                        throw new \Exception('价格不能低于系统计算出的' . $attr['price'] . '元');
                    }
                    if ($not_change) {
                        $attr['price'] = $param['prices'][$key];
                    }
                    $attr['status'] = '禁用';
                    if (in_array($attr['id'], $checked_attr_ids)) {
                        $attr['status'] = '启用';
                        $cost_price = $cost_price > $attr['cost_price'] ? $attr['cost_price'] : $cost_price;
                        $price = $price > $attr['price'] ? $attr['price'] : $price;
                    }
                }
                $attr['old_price'] = $this->getOldPriceCalculate($attr['price']);
                $attr->save();
            }
            $info['reward'] = $param['reward'];
            $info['cost_price'] = $cost_price === 100000 ? $info['cost_price'] : $cost_price;
            $info['price'] = $price === 100000 ? $info['price'] : $price;
            $info['old_price'] = $this->getOldPriceCalculate($info['price']);
            $info->save();
            PingGroupGoodsAttr::objectInit()->saveAll($data);
            PingGroupGoods::commit();
        } catch (\Exception $e) {
            PingGroupGoods::rollback();
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

    /**
     * 商品列表api
     * @return array|bool|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getActivityGoodsList() {
        $time = time();
        $group = PingGroup::_whereCV(['status' => '开启'])->where(['start_time' => ['<', $time], 'end_time' => ['>', $time]])->find();
        if ($group === null) throw new \Exception('暂未拼团商品');
        $list = $this->goodsListPage(['group_id' => $group['id'], 'status' => '上架']);
        $group->goods_list = $list;
        return $group;
    }

    public function getOpenGroupActivityGoodsList() {
        $time = time();
        $open = PingGroupOpen::_whereCV(['end_time' => ['>', $time], 'status' => '开始'])->select();
        $data = [];
        $group_id = 0;
        foreach ($open as $val) {
            $data[$val['goods_id']] = $val['id'];
            $group_id = $val['group_id'];
        }
        if (empty($data)) return null;
        $goods_ids = array_keys($data);
        $group = PingGroup::_whereCV(['status' => '开启', 'id' => $group_id])->where(['start_time' => ['<', $time], 'end_time' => ['>', $time]])->find();
        $list = $this->goodsListPage(['group_id' => $group_id, 'status' => '上架', 'goods_ids' => $goods_ids]);
        foreach ($list as $val) {
            $val['open_id'] = $data[$val['id']];
        }
        $group->goods_list = $list;
        return $group;
    }

    /**
     * 商品详情
     * @param $param
     * @return false
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getActivityGoodsInfo($param) {
        if (empty($param['goods_id'])) throw new \Exception('id不能为空!');

        $info = $this->getGoodsInfo(['id' => $param['goods_id'], 'attr_status' => '启用']);
        if ($info['status'] !== '上架') throw new \Exception('该商品已经下架!');

        $alibaba2 = new AlibabaServiceV2();
        $aliInfo = $alibaba2->getProductV2($info['feed_id'], 0, 0, 0);
        if ($aliInfo === false) {
            $aliInfo = [];
            $aliInfo['productInfo'] = null;
        }

        $aliInfo['newPrice'] = $info['price'];
        $aliInfo['gxz'] = $info['price'];
        $aliInfo['ping_group_goods_id'] = $info['id'];
        $aliInfo['ping_group_num'] = $info['num'];
        $aliInfo['thumb'] = $info['goods']['thumb'];
        $aliInfo['title'] = $info['goods']['title'];
        $aliInfo['reward'] = $info['reward'];
        $aliInfo['max_price'] = $info['price'];
        $aliInfo['og_price'] = $info['old_price'];
        $sku = [];
        if ($aliInfo['productInfo'] !== null) {
            foreach ($info['attr'] as $val) {
                $sku['A' . $val['attr']['skuId']] = $val;
            }
            foreach ($aliInfo['productInfo']['skuInfos'] as $key => &$val) {
                $sku_key = 'A' . $val['skuId'];
                if (array_key_exists($sku_key, $sku)) {
                    $val['ping_group_attr_id'] = $sku[$sku_key]['id'];
                    $val['cpsSuggestPrice'] = $sku[$sku_key]['price'];
                    $val['open_group_price'] = sprintf("%.2f", $sku[$sku_key]['price'] * 0.2);
                    $aliInfo['max_price'] = $aliInfo['max_price'] > $val['cpsSuggestPrice'] ? $aliInfo['max_price'] : $val['cpsSuggestPrice'];
                } else {
                    unset($aliInfo['productInfo']['skuInfos'][$key]);
                }
            }
            $aliInfo['productInfo']['skuInfos'] = array_values($aliInfo['productInfo']['skuInfos']);
        }
        $mid = MemberService::getCurrentMid();
        $openList = PingGroupOpen::_whereCV(['goods_id' => $param['goods_id'], 'status' => '开始'])
            ->where('start_time', '>', time() - 60 * 60 * 24)->where('mid', '<>', $mid)->with('member')->select();
        $openInfo = PingGroupOpen::_whereCV(['goods_id' => $param['goods_id'], 'status' => '开始', 'mid' => $mid])
            ->where('start_time', '>', time() - 60 * 60 * 24)->with('member')->select();
        if (!$openInfo->isEmpty()) {
            $openList = $openInfo->merge($openList);
        }
        foreach ($openList as $item) {
            $item['enter_the'] = PingGroupOrder::_whereCV(['mid' => $mid, 'open_id' => $item['id']])->count();
            if (mb_strlen($item['nickname'], 'utf8') === 11) {
                $item['nickname'] = substr_replace($item['nickname'], '****', 3, 4);
            } else {
                $item['nickname'] = mb_strlen($item['nickname'], 'utf8') > 3 ? $item['nickname'] : substr_replace($item['nickname'], '*', 3, 3);
            }
        }
        $aliInfo['ping_group_open_group_list'] = $openList;
        return $aliInfo;
    }

    /**
     * 开团
     * @param $param
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function openGroup($param) {
        if (empty($param['mid'])) throw new \Exception('会员id不能为空!');
        //if (empty($param['address_id'])) throw new \Exception('会员地址id不能为空!');
        if (empty($param['goods_id'])) throw new \Exception('拼团商品id不能为空!');
        if (empty($param['attr_id'])) throw new \Exception('拼团商品属性id不能为空!');
        $orderNo = createOrderNo('PTKT');
        $attr = PingGroupGoodsAttr::get($param['attr_id']);
        if ($attr === null) throw new \Exception('未找到属性!');
        $goods = PingGroupGoods::get($param['goods_id']);
        if ($goods === null) throw new \Exception('商品未找到!');
        $aliAttr = AliGoodsAttr::_whereCV('id', $attr['attr_id'])->find();
        if ($aliAttr === null) throw new \Exception('未找到属性!');
        $cargoParamList = [
            'specId' => $aliAttr['specId'],
            'quantity' => 1,
            'offerId' => $goods['feed_id']
        ];
        $address = [
            'detail' => '西平西湖游乐园留莲忘返',
            'mobile' => '13728143010',
            'name' => '周星星',
            'country' => '南城街道办事处',
            'city' => '东莞市',
            'province' => '广东省'
        ];
        $this->preview4CybMedia($address, $cargoParamList);
        $price = $attr['price'] * 0.2;
        $data = [
            'mid' => $param['mid'],
            'address_id' => $param['address_id'] ?: 0,
            'order_no' => $orderNo,
            'start_time' => time(),
            'end_time' => time(),
            'goods_id' => $param['goods_id'],
            'attr_id' => $param['attr_id'],
            'price' => $price,
            'num' => $goods['num'],
            'group_id' => $goods['group_id']
        ];
        PingGroupOpen::create($data);
        return $orderNo;
    }

    /**
     * 开团支付回调
     * @param $order_no
     */
    public function openGroupPayCallBack($order_no) {
        $info = PingGroupOpen::_whereCV('order_no', $order_no)->find();
        if ($info === null) throw new \Exception('未找到该开团信息');
        if ($info['status'] !== '待开始') throw new \Exception('该团不是待开始状态');
        $info['start_time'] = time();
        $info['end_time'] = time() + 60 * 60 * 24;
        $info['status'] = '开始';
        $info->save();
        RedPacketService::objectInit()->openGroupRedPacket($info['mid']);
        $this->isTeamer($info['mid']);
        return 1;
    }

    /**
     * 判断会员是否为团队长
     * @param $mid
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public function isTeamer($mid) {
        $isTeamer = Db::name('members')->where('id', $mid)->value('teamer');
        if ($isTeamer == 0) {
            $price = PingGroupOpen::_whereCV('status', '<>', '待开始')->where('mid', $mid)->sum('price');
            $teamer_price = Db::name('config_new')->where('name', 'teamer_price')->value('info') ?: 210;
            if ($price >= $teamer_price) {
                Db::name('members')->where('id', $mid)->update(['teamer' => 1]);
            }
        }
    }

    /**
     * 团详情
     * @param $param
     * @return array|bool|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function groupInfo($param) {
        if (empty($param['id']) && empty($param['order_no'])) throw new \Exception('团id不能为空!');
        if (!empty($param['id'])) {
            if (strlen($param['id']) > 11) {
                $param['order_no'] = $param['id'];
            }
        }
        $model = PingGroupOpen::objectInit();
        if (empty($param['order_no'])) {
            $model->where('id', $param['id']);
        } else {
            $model->where('order_no', $param['order_no']);
        }

        $group = $model->with('member')->find();
        if ($group === null) throw new \Exception('未找到团信息');
        if ($group['status'] !== '开始') throw new \Exception('该团已经结束了!', 3);
        if ($group['end_time'] < time()) {
            $this->groupEnd($group);
            throw new \Exception('该团已经结束了!', 3);
        }
        $group['goods'] = $this->getActivityGoodsInfo(['goods_id' => $group['goods_id']]);
        if ($group['goods'] === null) throw new \Exception('拼团商品已失效!');
        $group['order_list'] = [];
        $groupOrder = PingGroupOrder::_whereCV('open_id', $group['id'])->select();
        if (!$groupOrder->isEmpty()) {
            $order_nos = array_column($groupOrder->toArray(), 'order_no');
            $group['order_list'] = AliOrder::_whereCVIn('status', ['待发货', '待收货', '已完成', '退款中'])->whereIn('order_no', $order_nos)->with('member')->select();
        }
        $group['max_reward'] = 0;
        if ($param['mid'] === $group['mid']) {
            $group['max_reward'] = number_format(($group['goods']['max_price'] * $group['goods']['reward'] * $group['goods']['ping_group_num']) / 100, 2);
        }

        request()->get(['offset' => 6]);
        $group['recommend_list'] = $this->getActivityGoodsList();

        if (MemberService::getCurrentMid() != $group['mid']) {
            $group['reward'] = null;
        }
        return $group;
    }

    private function preview4CybMedia($address, $cargoParamList) {
        $aliServer = new AlibabaServiceV2();
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
            'cargoParamList' => [
                'specId' => $cargoParamList['specId'],
                'quantity' => $cargoParamList['quantity'],
                'offerId' => $cargoParamList['offerId']
            ]
        ]);
        if (!empty($freight['errorCode'])) {
            if ($freight['errorCode'] == '500_004') {//这个是接口方无库存

            }
            AliGoods::_whereCV('feedId', $cargoParamList['offerId'])->update(['invalid' => '失效']);
            throw new \Exception('商品失效!');
        }
        return $freight;
    }

    private function createOrder4CybMedia($orderNo, $address, $cargoParamList, $offers) {
        $aliServer = new AlibabaServiceV2();
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
                'specId' => $cargoParamList['specId'],
                'quantity' => $cargoParamList['quantity'],
                'offerId' => $cargoParamList['offerId']
            ]],
            'outerOrderInfo' => [
                'mediaOrderId' => $orderNo,
                'phone' => $address['mobile'],
                'offers' => [$offers],
            ]
        ]);
        if (!empty($aliOrder['errorCode'])) {
            throw new \Exception('订单创建失败:' . json_encode($aliOrder, JSON_UNESCAPED_UNICODE));
        }
        return $aliOrder;
    }

    /**
     * 参团创建订单
     * @param $param
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function createOrder($param) {
        if (empty($param['mid'])) throw new \Exception('会员id不能为空!');
        if (empty($param['open_id'])) throw new \Exception('拼团id不能为空!');
        if (empty($param['address_id'])) throw new \Exception('收货地址不能为空!');
        if (empty($param['attr_id'])) throw new \Exception('属性id不能为空!');
        if (empty($param['goods_id'])) throw new \Exception('属性id不能为空!');
        $quantity = $param['quantity'] ?: 1;
        $orderNo = createOrderNo('PTME');
        $open = PingGroupOpen::_whereCV(['id' => $param['open_id'], 'status' => '开始'])->find();
        if ($open === null) throw new \Exception('团已经结束了!');
        if ($open['goods_id'] != $param['goods_id']) throw new \Exception('不是同一个团的商品');
        $address = Db::name('member_address')->where('id', $param['address_id'])->find();
        $attrInfo = PingGroupGoodsAttr::_whereCV('id', $param['attr_id'])->with('attr')->find();
        if ($attrInfo === null || $attrInfo['attr'] === null) throw new \Exception('未找到商品');
        $cargoParamList = ['specId' => $attrInfo['attr']['specId'], 'quantity' => $quantity, 'offerId' => $attrInfo['attr']['feedId']];
        $freight = $this->preview4CybMedia($address, $cargoParamList);
        $freight = current($freight['orderPreviewResuslt']);
        $old_price = current($freight['cargoList'])['finalUnitPrice'];
        $offers = ['id' => $attrInfo['attr']['feedId'], 'specId' => $attrInfo['attr']['specId'], 'price' => $old_price, 'num' => $quantity];
        $aliOrder = $this->createOrder4CybMedia($orderNo, $address, $cargoParamList, $offers);
        $goods = PingGroupGoods::_whereCV(['group_id' => $attrInfo['group_id'], 'feed_id' => $attrInfo['feed_id']])->with('goods')->find();
        $data = [
            'uid' => $param['mid'],
            'order_no' => $orderNo,
            'feedId' => $attrInfo['attr']['feedId'],
            'skuId' => $attrInfo['attr']['specId'],
            'orderId' => $aliOrder['result']['orderId'],
            'status' => '待付款',
            'pay_status' => 0,
            'create_time' => date('Y-m-d H:i:s'),
            'postage' => 0,
            'nopost' => sprintf('%.2f', $attrInfo['price'] * $quantity),
            'unitprice' => $attrInfo['price'],
            'unitprice_old' => $attrInfo['price'],
            'quantity' => $quantity,
            'goodsname' => $goods['goods']['title'],
            'attrtitle' => $attrInfo['attr']['title'],
            'imgUrl' => $goods['goods']['thumb2'] ? $goods['goods']['thumb2'] : $goods['goods']['thumb'],
            'order_type' => 0,
            'source' => 'pinggroup'
        ];
        $data['total'] = $data['payamount'] = $data['postage'] + $data['nopost'];
        try {
            AliOrder::startTrans();
            AliOrder::create($data, true);
            $data2 = [
                'mid' => $param['mid'],
                'open_id' => $param['open_id'],
                'order_no' => $orderNo
            ];
            PingGroupOrder::create($data2, true);
            AliOrder::commit();
            return $orderNo;
        } catch (\Exception $e) {
            AliOrder::rollback();
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 参团支付回调
     * @param $order_no
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createOrderPayCallBack($order_no) {
        $order = PingGroupOrder::_whereCV('order_no', $order_no)->find();
        $open = PingGroupOpen::_whereCV('id', $order['open_id'])->find();
        $goods = PingGroupGoods::_whereCV('id', $open['goods_id'])->find();
        $aliOrder = AliOrder::_whereCV('order_no', $order_no)->find();
        $money = $this->commission($aliOrder, $goods, $open);
        $open['reward'] += $money;//记录收益
        MemberBalanceService::objectInit()->pingGroupEstimateBalance($open['mid'], $money, $open['order_no']);
        $open['sell_num'] += 1;
        if ($open['sell_num'] === $open['num']) {
            //返还团长押金
            //MemberBalanceService::objectInit()->PingGroupOpenReturn($order['mid'], $open['price']);
            $order_list = PingGroupOrder::_whereCV('open_id', $order['open_id'])->select();
            $order_nos = array_column($order_list->toArray(), 'order_no');
            AliOrder::_whereCVIn('order_no', $order_nos)
                ->whereCV('status', '待付款')
                ->update(['status' => '交易关闭']);
            $this->groupEnd($open, '拼团成功');
        }
        $open->save();
    }

    /**
     * 我的团列表
     * @param $param
     * @return \think\Paginator
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function openList($param) {
        if (empty($param['mid'])) throw new \Exception('会员id不能为空!');
        $list = PingGroupOpen::_whereCV('mid', $param['mid'])->whereCV('status', '<>', '待开始')->order('id', 'desc')->paginate();
        $time = time();
        foreach ($list as $item) {
            $goods = PingGroupGoods::_whereCV('id', $item['goods_id'])->with('goods')->find();
            if ($item['status'] == '开始' && $item['end_time'] < $time) {
                $this->groupEnd($item);
            }
            $price = PingGroupGoodsAttr::_whereCV(['group_id' => $goods['group_id'], 'feed_id' => $goods['feed_id']])->order('price', 'desc')->value('price');
            $item['max_reward'] = bcadd(($price * $goods['num'] * $goods['reward']) / 100, 0, 2);
            $item['goods'] = $goods;
        }
        return $list;
    }

    /**
     * 获取运费
     * @param $param
     * @return array|bool|float|int|mixed|object|\stdClass|null
     * @throws \think\exception\DbException
     */
    public function freightPrice($param) {
        if (empty($param['address_id'])) throw new \Exception('收货地址不能为空!');
        if (empty($param['goods_id'])) throw new \Exception('商品id不能为空!');
        $goods = PingGroupGoods::get($param['goods_id']);
        if ($goods === null) throw new \Exception('商品不存在!');
        return 0;
    }

    /**
     * 开团支付
     * @param $param
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function groupPay($param) {
        if (empty($param['order_no'])) throw new \Exception('订单号不能为空!');
        if (empty($param['paytype'])) throw new \Exception('支付方式不能为空!');
        $mid = MemberService::getCurrentMid();
        $open = PingGroupOpen::_whereCV('order_no', $param['order_no'])->find();
        if ($open === null) throw new \Exception('该团不存在!');
        if ($open['status'] !== '待开始') throw new \Exception('该团不是待开始状态!');
        $return = [];
        $return['info'] = OrderService::pay($mid, $open['id'], $param['order_no'], $open['price'], $param['paytype'], '留莲忘返-商品消费', 3);
        return $return;
    }

    /**
     * 更新排序
     * @param $param
     * @return int
     * @throws \think\exception\DbException
     */
    public function updateGoodsSort($param) {
        if (empty($param['ids'])) throw new \Exception('ids不能为空!');
        if (empty($param['sort'])) throw new \Exception('排序不能为空!');
        foreach ($param['ids'] as $key => $id) {
            $info = PingGroupGoods::get($id);
            if ($info === null) continue;
            $info['sort'] = $param['sort'][$key];
            $info->save();
        }
        return 1;
    }

    /**
     * 删除商品
     * @param $param
     * @return int
     * @throws \think\exception\DbException
     */
    public function delGoods($param) {
        if (empty($param['ids'])) throw new \Exception('ids不能为空!');
        foreach ($param['ids'] as $id) {
            $info = PingGroupGoods::get($id);
            if ($info === null) continue;
            PingGroupGoodsAttr::_whereCV(['group_id' => $info['group_id'], 'feed_id' => $info['feed_id']])->delete();
            $info->delete();
        }
        return 1;
    }

    /**
     * 团结束事件
     * @param $info
     */
    protected function groupEnd($info, $status = '结束') {
        $info['status'] = $status;
        $info->allowField(true)->save();
        MemberBalanceService::objectInit()->givePingGroupEstimateBalance($info['mid'],$info['order_no']);
    }

    /**
     * 计算获得佣金
     * @param $aliOrder
     * @param $group
     */
    private function commission($aliOrder, $goods, $open) {
        $price = $aliOrder['payamount'];
        $money = bcadd(($price * $goods['reward'] / 100), 0, 2);
        $subMoney = RedPacketService::objectInit()->PingGroupCommission($open['mid'], $aliOrder['uid'], $money);
        $money = $money - $subMoney;
        return $money;
    }

    /**
     * 7个自然日后退款到余额
     */
    public function returnMoney() {
        $end_time = strtotime(date('Y-m-d 23:59:59') . ' -7 day');
        $list = PingGroupOpen::_whereCVIn('status', ['结束', '开始', '拼团成功'])->whereCV(['end_time' => ['<', $end_time], 'back' => '否'])->select();
        foreach ($list as $item) {
            $item['price'] = sprintf("%.2f", ($item['price'] * 1.074));
            MemberBalanceService::objectInit()->PingGroupOpenReturn($item['mid'], $item['price']);//返还押金
            $item['back'] = '是';
            $item->save();
        }
    }


    protected function getActivityListInfo() {
        $time = time();
        $l = PingGroup::_whereCV('status', '开启')->where(['start_time' => ['<', $time], 'end_time' => ['>', $time]])->find();
        return $l;
    }

    /**
     * 获取会员团次数
     */
    public function getMemberOpenCount($mid) {
        $l = $this->getActivityListInfo();
        if ($l === null) {
            throw new \Exception('没有团活动!');
        }
        return PingGroupOpen::_whereCV(['mid' => $mid, 'status' => ['<>', '待开始'], 'group_id' => $l['id']])->count();
    }

    private function _openGroupList($param) {
        $model = PingGroupOpen::_whereCV('status', ['<>', '待开始']);

    }

    /**
     * 开团排行榜
     * @param $param
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function openGroupRankingList($param) {
        $endTime = 1605110399;
        $where = ['status' => ['<>', '待开始']];
        if ($endTime <= time()) {
            $where['start_time'] = ['>', time()];
        } else {
            $where['start_time'] = ['>', 1604419200];
        }
        //查找排行数据
        $sql = PingGroupOpen::_whereCV($where)
            ->field('mid,count(id) num')
            ->group('mid')->buildSql();
        $pgList = Db::table($sql)->alias('a')
            ->field('a.num,a.mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->page(1, 100)
            ->order('num desc')->select()->toArray();
        //插入假数据
        self::fakeData($param['type'], $pgList);
        //获取自己在排行中的位置
        $selfData = Db::table($sql)->alias('a')
            ->field('a.*,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->where('a.mid', $param['mid'])->find();
        $self = $this->getSelfData($param, $pgList, $selfData);
        //姓名加密
        self::nicknameDeal($pgList);
        return ['list' => $pgList, 'self' => $self];
    }

    /**
     * 参团金额排行榜
     * @param $param
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function groupRewardRankingList($param) {
        $endTime = 1605110399;
        $where = ['a.pay_status' => 1];
        if ($endTime <= time()) {
            $where['a.create_time'] = ['>', date('Y-m-d H:i:s')];
        } else {
            $where['a.create_time'] = ['>', '2020-11-04'];
        }
        //查找排行数据
        $sql = PingGroupOrder::objectInit()->alias('o')
            ->field('o.*,sum(a.total) num')
            ->join('ali_order a', 'a.order_no = o.order_no')
            ->where($where)
            ->group('mid')
            ->buildSql();
        $pgoList = Db::table($sql)->alias('a')
            ->field('a.num,a.mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->order('num desc')
            ->page(1, 100)->select()->toArray();
        //插入假数据
        self::fakeData($param['type'], $pgoList);
        //获取自己在排行中的位置
        $selfData = Db::table($sql)->alias('a')
            ->field('a.*,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->where('a.mid', $param['mid'])->find();
        $self = $this->getSelfData($param, $pgoList, $selfData);
        //姓名加密
        self::nicknameDeal($pgoList);
        return ['list' => $pgoList, 'self' => $self];
    }

    /**
     * 免单单数排行榜
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function orderFreeRankingList($param) {
        $endTime = 1605110399;
        $where = ['source' => 'orderfree', 'pay_status' => 1];
        if ($endTime <= time()) {
            $where['create_time'] = ['>', date('Y-m-d H:i:s')];
        } else {
            $where['create_time'] = ['>', '2020-11-04'];
        }
        //查找排行数据
        $sql = AliOrder::objectInit()
            ->field('uid,count(uid) num')
            ->where($where)
            ->group('uid')
            ->buildSql();
        $ofList = Db::table($sql)->alias('a')
            ->field('a.num,a.uid mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.uid = m.id', 'left')
            ->order('num desc')
            ->page(1, 100)->select()->toArray();
        //插入假数据
        self::fakeData($param['type'], $ofList);
        //获取自己在排行中的位置
        $selfData = Db::table($sql)->alias('a')
            ->field('a.*,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.uid = m.id', 'left')
            ->where('uid', $param['mid'])
            ->order('num desc')
            ->find();
        $self = $this->getSelfData($param, $ofList, $selfData);
        //姓名加密
        self::nicknameDeal($ofList);
        return ['list' => $ofList, 'self' => $self];
    }

    /**
     * 用户显示昵称处理
     * @param $list
     */
    static function nicknameDeal(&$list) {
        foreach ($list as &$v) {
            if ($v['nickname'] == $v['realname']) {
                $v['nickname'] = '';
            }
            if (empty($v['nickname']) || $v['nickname'] == $v['mobile'] || is_numeric($v['nickname'])) {
                if (empty($v['realname'])) {
                    $v['nickname'] = '尾号' . substr($v['mobile'], -4);
                } else {
                    switch (mb_strlen($v['realname'])) {
                        case 2:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '*';
                            break;
                        case 3:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '*' . mb_substr($v['realname'], -1);
                            break;
                        case 4:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '**' . mb_substr($v['realname'], -1);
                            break;
                        default:
                            $v['nickname'] = $v['realname'];
                            break;
                    }
                }
            }
            unset($v['mobile']);
            unset($v['realname']);
        }
        unset($v);
    }


    /**
     * 插入假数据
     * @param string $type 排行榜类型 openGroup|groupReward|orderFree
     * @param array $param 排行榜原数据
     */
    static function fakeData2($type, &$param) {
        $key = $type . '_mid';
        $m = Cache::get($key);
        $first = reset($param);
        $num = 0;
        switch ($type) {
            case 'openGroup':
                $num = rand(10, 200);
                break;
            case 'groupReward':
                $num = rand(200, 1000);
                break;
            case 'orderFree':
                $num = rand(30, 150);
                break;
        }
        if (!$m) {
            $mid_s = [];
            while (1) {
                $mid = rand(100, 150);
                if (!in_array($mid, $mid_s)) {
                    array_push($mid_s, $mid);
                    if (count($mid_s) >= 10) {
                        break;
                    }
                }
            }
            $list = MemberService::objectInit()->getMemberList(['ids' => $mid_s], 'id mid,mobile,nickname,realname,avatar');
            $_num_ = $first ? $first['num'] : 10;
            foreach ($list as $k => &$item) {
                $item['num'] = $_num_ + $num + (9 - $k) * 10;
            }
            $m = json_encode($list, JSON_UNESCAPED_UNICODE);
            Cache::set($key, $m, new DateTime(date('Y-m-d', strtotime('+1 day'))));
        }
        $m = json_decode($m, 1);
        if (empty($param)) {
            $param = $m;
            return;
        }
        //a.num,m.mobile,m.nickname,m.realname,m.avatar
        if (end($m)['num'] < $first['num']) {
            $m[count($m) - 1]['num'] = $first['num'] + $num;
        }
        $param = array_merge($m, $param);
    }

    static function fakeData($type, &$param) {
        $key = $type . '_mid';
        $m = Cache::get($key);
        $param = $param ?: [];
        if (!$m) {
            $mid_s = [];
            while (1) {
                $mid = rand(100, 150);
                if (!in_array($mid, $mid_s)) {
                    array_push($mid_s, $mid);
                    if (count($mid_s) >= 10) {
                        break;
                    }
                }
            }
            $num = 0;
            $num_min = 0;
            $num_max = 0;
            switch ($type) {
                case 'openGroup':
                    $num = rand(1, 5);
                    $num_min = 2;
                    $num_max = 15;
                    break;
                case 'groupReward':
                    $num = rand(200, 1000);
                    $num_min = 50;
                    $num_max = 830;
                    break;
                case 'orderFree':
                    $num = rand(3, 10);
                    $num_min = 7;
                    $num_max = 29;
                    break;
            }
            $list = MemberService::objectInit()->getMemberList(['ids' => $mid_s], 'id mid,mobile,nickname,realname,avatar');
            foreach ($list as $k => &$item) {
                $item['num'] = $num + $k + rand($num_min, $num_max);
            }
            quickRow($list, 'num', 0, count($list) - 1, 'max');
            $m = json_encode($list, JSON_UNESCAPED_UNICODE);
            Cache::set($key, $m, new DateTime(date('Y-m-d', strtotime('+1 day'))));
        }
        $m = json_decode($m, 1);
        $param = array_merge($m, $param);
        //重新排序
        quickRow($param, 'num', 0, count($param) - 1, 'max');
    }

    public function autoFakeData() {
        $types = ['openGroup', 'groupReward', 'orderFree'];
        foreach ($types as $type) {
            $list = [];
            $num = 0;
            switch ($type) {
                case 'openGroup':
                    $num = rand(4, 20);
                    $list = $this->getOpenGroupRankingList();
                    break;
                case 'groupReward':
                    $num = rand(50, 200);
                    $list = $this->getGroupRewardRankingList();
                    break;
                case 'orderFree':
                    $num = rand(8, 15);
                    $list = $this->getOrderFreeRankingList();
                    break;
            }
            $key = $type . '_mid';
            $m = Cache::get($key);
            if (!$m || !empty($list)) {
                $m = json_decode($m, 1);
                $first = reset($list);
                $fakeFirst = end($m);

                $time = date('H:i') == '23:59';
                if ($first['num'] >= $fakeFirst['num'] || $time) {
                    if ($time) {
                        $num = $num * 5;
                    }
                    foreach ($m as &$item) {
                        $n = abs($item['num'] - $first['num']);
                        $item['num'] = $item['num'] + $n + $num;
                    }
                    $m = json_encode($m, JSON_UNESCAPED_UNICODE);
                    Cache::set($key, $m, new DateTime(date('Y-m-d', strtotime('+1 day'))));
                }
            }
        }


    }

    /**
     * 获取排行前100的团次数排行榜
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function getOpenGroupRankingList() {
        $endTime = 1605110399;
        $where = ['status' => ['<>', '待开始']];
        if ($endTime <= time()) {
            $where['start_time'] = ['>', time()];
        } else {
            $where['start_time'] = ['>', 1604419200];
        }
        //查找排行数据
        $sql = PingGroupOpen::_whereCV($where)
            ->field('mid,count(id) num')
            ->group('mid')->buildSql();
        $pgList = Db::table($sql)->alias('a')
            ->field('a.num,a.mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->page(1, 100)
            ->order('num desc')->select()->toArray();
        return $pgList;
    }

    /**
     * 获取排行前100的团佣金排行榜
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function getGroupRewardRankingList() {
        $endTime = 1605110399;
        $where = ['a.pay_status' => 1];
        if ($endTime <= time()) {
            $where['a.create_time'] = ['>', date('Y-m-d H:i:s')];
        } else {
            $where['a.create_time'] = ['>', '2020-11-04'];
        }
        //查找排行数据
        $sql = PingGroupOrder::objectInit()->alias('o')
            ->field('o.*,sum(a.total) num')
            ->join('ali_order a', 'a.order_no = o.order_no')
            ->where($where)
            ->group('mid')
            ->buildSql();
        $pgoList = Db::table($sql)->alias('a')
            ->field('a.num,a.mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.mid = m.id', 'left')
            ->order('num desc')
            ->page(1, 100)->select()->toArray();
        return $pgoList;
    }

    /**
     * 获取排行前100的免单排行榜
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function getOrderFreeRankingList() {
        $endTime = 1605110399;
        $where = ['source' => 'orderfree', 'pay_status' => 1];
        if ($endTime <= time()) {
            $where['create_time'] = ['>', date('Y-m-d H:i:s')];
        } else {
            $where['create_time'] = ['>', '2020-11-04'];
        }
        //查找排行数据
        $sql = AliOrder::objectInit()
            ->field('uid,count(uid) num')
            ->where($where)
            ->group('uid')
            ->buildSql();
        $ofList = Db::table($sql)->alias('a')
            ->field('a.num,a.uid mid,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.uid = m.id', 'left')
            ->order('num desc')
            ->page(1, 100)->select()->toArray();
        return $ofList;
    }


    /**
     * 获取自己的排名数据
     * @param $param
     * @param $sql
     * @param $list
     * @param $selfData
     */
    public function getSelfData($param, $list, $selfData) {
        //查找自己的排名
        $selfNo = 101;
        foreach ($list as $k => $v) {
            if ($v['uid'] == $param['mid'] || $v['mid'] == $param['mid']) {
                $selfNo = $k + 1;
            }
        }
        $selfInfo = Db::name('members')->field('avatar,nickname,realname,mobile')->where('id', $param['mid'])->find();
        $self = [
            'avatar' => $selfInfo['avatar'],
            'nickname' => $selfInfo['nickname'] ?: $selfInfo['mobile'],
            'data' => $selfData['num'] ?: 0
        ];
        if ($selfNo == 1) {
            $diff = null;
        } else {
            $diff = $list[$selfNo - 2]['num'] - $selfData['num'] + 1;
        }
        if ($selfNo === 101) {
            $self['no'] = '未上榜';
        } else {
            $self['no'] = '第' . $selfNo . '名';
        }
        switch ($param['type']) {
            case 'orderFree':
                if ($diff) {
                    $self['diff'] = '再下' . $diff . '单可超越前一名';
                } else {
                    $self['diff'] = '恭喜您已经登顶,请继续保持!';
                }
                $self['data'] .= '单';
                break;
            case 'openGroup':
                if ($diff) {
                    $self['diff'] = '再拼' . $diff . '个团可超越前一名';
                } else {
                    $self['diff'] = '恭喜您已经登顶,请继续保持!';
                }
                $self['data'] = '已开' . $self['data'] . '团';
                break;
            case 'groupReward':
                if ($diff) {
                    $self['diff'] = '再参团' . $diff . '元可超越前一名';
                } else {
                    $self['diff'] = '恭喜您已经登顶,请继续保持!';
                }
                $self['data'] = '已参团' . $self['data'] . '元';
                break;
            default:
                break;
        }
        return $self;
    }

}
