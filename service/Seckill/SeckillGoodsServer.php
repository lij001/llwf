<?php

namespace app\api\service\Seckill;

use app\api\BaseService;
use app\api\model\AliProductV2;
use app\api\model\Seckill\SeckillGoods;
use app\api\model\Seckill\SeckillGoodsAttr;
use app\api\model\Seckill\SeckillList;
use app\api\model\Seckill\SeckillListTime;
use app\api\service\Alibaba\AlibabaServiceV2;
use app\api\service\HotService;
use app\api\service\MemberService;
use app\api\service\RedisServer;
use app\api\service\TokenService;
use think\Cache;
use think\Db;

class SeckillGoodsServer extends BaseService {

    static public function syncs($list_id, $data) {
        $model = SeckillGoods::objectInit();
        $attrServer = SeckillGoodsAttrServer::objectInit();
        $goodsList = $model->where('list_id', $list_id)->whereCV('enable_selfset', '关闭')->select();
        if ($goodsList->isEmpty()) return true;
        $original = [];
        $goodsModel = new AliProductV2();
        $alibaba = new AlibabaServiceV2();
        $l = $goodsModel->whereIn('feedId', array_column($goodsList->toArray(), 'feedId'))->select();
        foreach ($l as $val) {
            $val['price'] = $alibaba->getProductListPriceV2($val['feedId']);
            $original['A' . $val['feedId']] = $val;
        }

        if (empty($original)) return true;
        try {
            $model->startTrans();
            foreach ($goodsList as $goods) {
                $goods['quantity_limit'] = $data['quantity_limit'];
                $goods['price'] = $original['A' . $goods['feedId']]['price'] * $data['price_discount'] / 100;
                $goods['old_price'] = $original['A' . $goods['feedId']]['price'];
                $goods['quantity'] = $data['quantity'];
                $goods->isUpdate()->save();
                $goods['price_discount'] = $data['price_discount'];
                $attrServer::syncs($goods['feedId'], $goods);
            }
            $model->commit();
            return true;
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
    }

    public function addGoods($param) {
        SeckillListServer::validTime($param, ['list_id', 'time_id', 'feedId']);
        $seckillGoods = SeckillGoods::get(['list_id' => $param['list_id'], 'feedId' => $param['feedId']]);
        if ($seckillGoods) {
            $seckillTime = SeckillListTime::get(['id' => $seckillGoods['time_id']]);
            throw new \Exception('该商品已在列表中"' . $seckillTime->title . '"(' . $seckillTime->start_time . '-' . $seckillTime->end_time . ')');
        }
        $goodsModel = new AliProductV2();
        $aliGoods = $goodsModel->where('feedId', $param['feedId'])->find();
        if (!$aliGoods) throw new \Exception('未找到该商品');
        if ($aliGoods['invalid'] == 'true') throw new \Exception('下架的商品不可以加入秒杀活动哦');
        $alibaba2 = new AlibabaServiceV2();
        $aliGoods['price'] = $alibaba2->getProductListPriceV2($aliGoods['feedId']);
        $info = SeckillList::get(['id' => $param['list_id']]);
        $data = [
            'list_id' => $param['list_id'],
            'feedId' => $param['feedId'],
            'title' => $aliGoods['title'] ?: '',
            'price' => $aliGoods['price'] * $info['price_discount'] / 100,
            'old_price' => $aliGoods['price'],
            'time_id' => $param['time_id']
        ];
        $model = new SeckillGoods();
        try {
            $model->startTrans();
            if (!$model->isUpdate(false)->save($data)) throw new \Exception('商品添加失败');
            if (!SeckillGoodsAttrServer::objectInit()->addAttr($param['feedId'], $data)) throw new \Exception('添加属性失败,请重新尝试!');
            $model->commit();
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
    }

    public function allAddGoods(array $param) {
        if (empty($param['feedIds'])) return;
        $data = [
            'list_id' => $param['list_id'],
            'time_id' => $param['time_id'],
            'feedId' => 0
        ];
        foreach ($param['feedIds'] as $feedId) {
            try {
                $data['feedId'] = $feedId;
                $this->addGoods($data);
            } catch (\Exception $e) {
                continue;
            }
        }
        return;
    }

    public function validateFeedId($post) {
        if (empty($post['feedId'])) throw new \Exception('商品id不能为空!');
    }

    public function validateListId($post) {
        if (empty($post['list_id'])) throw new \Exception('活动id不能为空!');
    }

    public function gList($param) {
        SeckillListServer::validTime($param, ['list_id', 'time_id']);
        $model = SeckillGoods::objectInit();
        $aliproductModel = new AliProductV2();
        $model->alias('a')->field('a.*,b.thumb imgUrl')
            ->join($aliproductModel->getTable() . ' b', 'b.feedId=a.feedId')
            ->where('a.list_id', $param['list_id']);
        if (!empty($param['title'])) {
            $model->whereLike('a.title', $param['title'] . '%');
        }
        if (!empty($param['list_id'])) {
            $model->where('a.list_id', $param['list_id']);
        }
        if (!empty($param['time_id'])) {
            $model->where('a.time_id', $param['time_id']);
        }
        if (!empty($param['enable_selfset'])) {
            $model->whereCV('a.enable_selfset', $param['enable_selfset']);
        }
        if (!empty($param['status'])) {
            $model->whereCV('a.status', $param['status']);
        }
        if (!empty($param['price'])) {
            $model->where(['price' => $param['price']]);
        }
        return $model->order('sort', 'asc')->paginate(20, false, ['page' => $param['start']]);
    }

    public function apiGoodsList($param) {
        $param['status'] = '开启';
        $param['price'] = ['>', '0.01'];
        $list = $this->gListAndAttr($param);
        if (empty($list)) return [];
        $redis = RedisServer::objectInit();
        $goods_reduce_time = Cache::get('goods_reduce_time');
        $goods_reduce_time_bool = false;
        if (!$goods_reduce_time || $goods_reduce_time < time()) {
            $goods_reduce_time_bool = true;
            Cache::set('goods_reduce_time', time() + 600);
        }
        foreach ($list as &$val) {
            if (empty($val['attr_list'])) {
                $key = implode('-', [$val['list_id'], $val['feedId']]);
                $val['shengxia'] = $redis->get($key);
                continue;
            }
            $shengxia = 0;
            $quantity = 0;
            foreach ($val['attr_list'] as $key => &$attr) {
                if (!empty($param['new']) && $attr['price'] != 0.01) {
                    unset($val['attr_list'][$key]);
                    continue;
                }
                $key = implode('-', [$attr['list_id'], $attr['feedId'], $attr['skuId']]);
                $num = $redis->get($key);
                $max = (int)substr($attr['feedId'], -2);
                $max = $max < 20 ? 10 + $max : $max;
                $max = $max > 90 ? $max - 10 : $max;
                if ($goods_reduce_time_bool && $attr['price'] > 1 && $num > $max) {
                    $timeData = SeckillListTime::get(['id' => $param['time_id']]);
                    if ($timeData !== null && $timeData->getData('start_time') < time()) {
                        $d = rand(1, 5);
                        if ($d % 2 > 0) {
                            $num -= $d;
                            $redis->set($key, $num);
                        }
                    }
                }
                $shengxia += $num;
                $quantity += $attr['quantity'];
                $attr['shengxia'] = $num;
            }
            $val['shengxia'] = $shengxia;
            $val['total_quantity'] = $quantity;
        }
        $mid = 1001;
        if (!empty(request()->header('token'))) {
            $mid = TokenService::getCurrentUid();
        }
        HotService::objectInit()->seckillAddCount(0, $mid);
        return $list;
    }

    protected function gListAndAttr($param) {
        if (empty($param['start']) || $param['start'] == 1) {
            $key = 'seckill' . $param['list_id'] . '-' . $param['time_id'];
            $list = Cache::get($key);
            if (!$list) {
                $list = $this->getGListAndAttr($param);
                if (!empty($list)) {
                    $list = json_encode($list, JSON_UNESCAPED_UNICODE);
                    Cache::set($key, $list, 3600);
                } else {
                    return [];
                }
            }
            return json_decode($list, true);
        }
        return $this->getGListAndAttr($param);

    }

    protected function getGListAndAttr($param) {
        $tempList = $this->gList($param)->items();
        if (empty($tempList)) return [];
        $list = [];
        foreach ($tempList as $k => $v) {
            $v['attr_list'] = [];
            $list['A' . $v['list_id'] . $v['feedId']] = $v->toArray();
        }
        $feedIds = array_column($list, 'feedId');
        $attr = SeckillGoodsAttr::objectInit()->where('list_id', $param['list_id'])->whereIn('feedId', $feedIds)->select();
        foreach ($attr as $k => $v) {
            array_push($list['A' . $v['list_id'] . $v['feedId']]['attr_list'], $v->toArray());
        }
        return array_values($list);
    }

    public function zeroGoodsList(){
        $start = strtotime('this friday');
        $end = strtotime('+1 day',$start)-1;
        if(time() > $end){
            $start = strtotime('next friday');
            $end = strtotime('+1 day',$start)-1;
        }
        $idArr = SeckillList::objectInit()->where(['start_time'=>['>=',$start],'end_time'=>['<=',$end],'status'=>1])->column('id');
        if(empty($idArr)) return [];
        $model = SeckillGoods::objectInit();
        $aliproductModel = new AliProductV2();
        $list = $model->alias('a')->field('a.*,b.thumb imgUrl')
            ->join($aliproductModel->getTable() . ' b', 'b.feedId=a.feedId')
            ->where('a.price',0.01)
            ->whereIn('a.list_id', $idArr)
            ->order('a.sort', 'asc')
            ->select();
        foreach($list as &$v){
            $attrs = SeckillGoodsAttr::objectInit()->where('feedId',$v['feedId'])->select();
            $v['stock'] = $this->checkAttrStock($attrs);
            $v['start_time'] =  SeckillListTime::objectInit()->where('id',$v['time_id'])->value('start_time');
        }
        return $list;
    }

    public function checkAttrStock($data){
        $num = 0;
        if($data){
            $redis =  RedisServer::objectInit();
            foreach($data as $v){
                $key = implode('-', [$v['list_id'], $v['feedId'], $v['skuId']]);
                $num += $redis->get($key);
            }
        }
        return $num;
    }

    protected function validateId(&$post) {
        if (empty($post['id'])) throw new \Exception('活动id不能为空!');
    }

    public function editGoods($post) {
        $this->validateId($post);
        $goods = SeckillGoods::get(['id' => $post['id']]);
        if (empty($goods)) throw new \Exception('未找到该商品');
        $goods['attrs'] = SeckillGoodsAttrServer::objectInit()->getAttrs($goods['list_id'], $goods['feedId']);
        return $goods;
    }

    protected function validate(&$param) {
        $valid = new \think\Validate([
            'title' => 'require',
            'enable_selfset' => 'require',
            'start_time' => 'date',
            'end_time' => 'date',
            'status' => 'require',
            'price' => 'float',
            'quantity_limit' => 'require|number',
        ],
            [
                'title' => '标题不能为空',
                'start_time' => '开始时间不能为空',
                'end_time' => '结束时间不能为空',
                'price' => '价格不能为空',
                'quantity_limit' => '限购不能为空',
            ]);
        if (!$valid->check($param)) throw new \Exception($valid->getError());
        $param['start_time'] = strtotime($param['start_time']);
        $param['end_time'] = strtotime($param['end_time']);
    }

    public function updateGoods(array $param) {
        $this->validateId($param);
        $this->validate($param);
        $goods = SeckillGoods::get($param['id']);
        $info = SeckillList::get($goods['list_id']);
        if ($info->status == '开启') throw new \Exception('活动开启后不能修改数据');
        $goods->allowField(true)->isUpdate()->save($param, ['id' => $param['id']]);
        $attrModel = SeckillGoodsAttr::objectInit();
        foreach ($param['attrs'] as $attr) {
            $attrModel->update([
                'price' => $attr['price'],
                'quantity' => $attr['quantity']
            ], ['feedId' => $goods['feedId'], 'list_id' => $goods['list_id'], 'skuId' => $attr['skuId']]);
        }
        return true;
    }

    public function goodsDetail(array $param) {
        $this->validateListId($param);
        $this->validateFeedId($param);
        $goods = SeckillGoods::get(['list_id' => $param['list_id'], 'feedId' => $param['feedId']]);
        if (empty($goods)) return;
        $attrs = SeckillGoodsAttrServer::objectInit()->getAttrs($param['list_id'], $param['feedId']);
        $redis = RedisServer::objectInit();
        if ($attrs->isEmpty()) {
            $key = [$goods['list_id'], $goods['feedId']];
            $goods['shengxia'] = $redis->get($key);
        } else {
            foreach ($attrs as $val) {
                $key = [$val['list_id'], $val['feedId'], $val['skuId']];
                $val['shengxia'] = $redis->get($key);
            }
            $goods['attrs'] = $attrs;
        }
        $goods['uid'] = TokenService::getCurrentUid();
        return $goods;
    }

    static public function goodsInfo(&$return, $feedId) {
        $return['seckill_status'] = 0;
        $key = 'seckillGoodsInfo' . $feedId;
        $goods = Cache::get($key);
        if (!$goods) {
            $time = SeckillListTime::_whereCV(['status' => '开启', 'end_time' => ['>', time()]])->select();
            if ($time->isEmpty()) return;
            $time_ids = array_column($time->toArray(), 'id');
            $goods = SeckillGoods::_whereCV(['status' => '开启', 'feedId' => $feedId])->whereCVIn('time_id', $time_ids)->find();
            if ($goods === null) return;
            foreach ($time as $val) {
                if ($val['id'] == $goods['time_id']) {
                    $goods['start_time'] = $val->getData('start_time');
                    $goods['end_time'] = $val->getData('end_time');
                }
            }
            $attr = SeckillGoodsAttr::_whereCV(['feedId' => $goods['feedId'], 'list_id' => $goods['list_id']])->select();
            $a = [];
            foreach ($attr as $val) {
                $key = $val['feedId'] . '_' . $val['skuId'] . '_' . $val['list_id'];
                $a[$key] = $val;
            }
            $goods['attr'] = $a;
            Cache::set($key, json_encode($goods, JSON_UNESCAPED_UNICODE), 3600);
            $goods = $goods->toArray();
        } else {
            $goods = json_decode($goods, 1);
        }
        if ($goods['end_time'] < time()) {
            return;
        }
        $return['seckill_status'] = 1;
        $redis = RedisServer::objectInit();
        if ($goods['start_time'] < time()) {
            $goods['enable'] = 1;
            if (empty($return['productInfo']['skuInfos'])) {
                $redis_key = implode('-', [$goods['list_id'], $goods['feedId']]);
                $goods['shengxia'] = $redis->get($redis_key);
            }
        } else {
            $goods['enable'] = 0;
            $goods['date_time'] = date('m月d日 H:i', $goods['start_time']);
        }
        $return['seckill_info'] = $goods;
        if (!empty($return['productInfo']['skuInfos'])) {
            foreach ($return['productInfo']['skuInfos'] as &$val) {
                $key = $goods['feedId'] . '_' . $val['skuId'] . '_' . $return['seckill_info']['list_id'];
                if (array_key_exists($key, $goods['attr'])) {
                    $seckill_attr = $goods['attr'][$key];
                    $redis_key = implode('-', [$seckill_attr['list_id'], $seckill_attr['feedId'], $seckill_attr['skuId']]);
                    $seckill_attr['shengxia'] = $redis->get($redis_key);;
                    $val['seckill_attr'] = $seckill_attr;
                }
            }
        }
        if (!empty(request()->header('token'))) {
            $return['uid'] = TokenService::getCurrentUid();
        } else {
            $return['uid'] = 1001;
        }
        $return['sharePrice']=0;
        HotService::objectInit()->seckillAddCount(1, $return['uid']);
    }

    public function allDeleteGoods(array $param) {
        if (empty($param['ids'])) return;
        if (empty($param['list_id'])) return;
        $model = SeckillGoods::objectInit();
        SeckillGoods::startTrans();
        try {
            $feedIds = $model->where('list_id', $param['list_id'])->whereIn('id', $param['ids'])->column('feedId');
            $model->where('list_id', $param['list_id'])->whereIn('id', $param['ids'])->delete();
            SeckillGoodsAttr::objectInit()->where('list_id', $param['list_id'])->whereIn('feedId', $feedIds)->delete();
            SeckillGoods::commit();
        } catch (\Exception $e) {
            SeckillGoods::rollback();
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

    public function deleteGoods(array $param) {
        if (empty($param['feedId']) || empty($param['list_id'])) throw new \Exception('feedId且list_id不能为空');
        $info = SeckillGoods::get(['list_id' => $param['list_id'], 'feedId' => $param['feedId']]);
        if ($info===null) throw new \Exception('未找到该商品');
        return $this->allDeleteGoods(['list_id' => $param['list_id'], 'ids' => [$info['id']]]);
    }

    public function sortGoods(array $param) {
        if (empty($param['data'])) return;
        $model = SeckillGoods::objectInit();
        foreach ($param['data'] as $key => $val) {
            $model->where('id', $val['id'])->update(['sort' => $val['sort']]);
        }
        return true;
    }

    public function freeShipping(array $param) {
        if (empty($param['ids'])) return;
        if (empty($param['list_id'])) throw new \Exception('list_id不能为空!');
        if (empty($param['free_shipping'])) throw new \Exception('free_shipping不能为空!');
        $model = SeckillGoods::objectInit();
        $model->where(function ($query) use ($param) {
            $query->where('list_id', $param['list_id'])->whereIn('id', $param['ids']);
        })->isUpdate()->save(['free_shipping' => $param['free_shipping']]);
        return 1;
    }

    public function count(array $param) {
        $co = Db::name('seckill_count');
        $ali = Db::name('ali_order');
        $data['list_num'] = 0;
        $data['detail_num'] = 0;
        $data['down_order_num'] = 0;
        $data['pay_num'] = 0;
        if (!empty($param['start_time']) || !empty($param['end_time'])) {
            $where = [];
            $where1 = [];
            if (!empty($param['start_time'])) {
                $where[] = 'create_time > ' . strtotime($param['start_time']);
                $where1[] = 'UNIX_TIMESTAMP(create_time) > ' . strtotime($param['start_time']);
            }
            if (!empty($param['end_time'])) {
                $where[] = 'create_time < ' . strtotime($param['end_time']);
                $where1[] = 'UNIX_TIMESTAMP(create_time) < ' . strtotime($param['start_time']);
            }
            $where = implode(' and ', $where);
            $where1 = implode(' and ', $where1);
            $data['list_num'] = $co->where($where)->where('detail', 0)->count();
            $data['detail_num'] = $co->where($where)->where('detail', 1)->count();
            $data['down_order_num'] = $ali->where($where1)->whereLike('order_no', 'MSME%')->count();
            $data['pay_num'] = $ali->where($where1)->whereLike('order_no', 'MSME%')->whereIn('status', [1, 2, 3])->count();
        } else {
            if (!empty($param['cycle'])) {
                switch ($param['cycle']) {
                    case '日':
                        $time = strtotime(date('Y-m-d'));
                        break;
                    case '周':
                        $time = strtotime('-1 week', strtotime(date('Y-m-d')));
                        break;
                    case '月':
                        $time = strtotime("-1 month", strtotime(date('Y-m-d')));
                        break;
                }
                $data['list_num'] = $co->where('create_time', '>', $time)->where('detail', 0)->count();
                $data['detail_num'] = $co->where('create_time', '>', $time)->where('detail', 1)->count();
                $data['down_order_num'] = $ali->where('UNIX_TIMESTAMP(create_time) > ' . $time)->whereLike('order_no', 'MSME%')->count();
                $data['pay_num'] = $ali->where('UNIX_TIMESTAMP(create_time) > ' . $time)->whereLike('order_no', 'MSME%')->whereIn('status', [1, 2, 3])->count();
            } else {
                $data['list_num'] = $co->where('detail', 0)->count();
                $data['detail_num'] = $co->where('detail', 1)->count();
                $data['down_order_num'] = $ali->whereLike('order_no', 'MSME%')->count();
                $data['pay_num'] = $ali->whereLike('order_no', 'MSME%')->whereIn('status', [1, 2, 3])->count();
            }
        }
        return $data;
    }
    public function addCount($detail = 0) {
        if (!empty(request()->header('token'))) {
            $uid = MemberService::getCurrentMid();
        } else {
            $uid = 1001;
        }
        $start_time = strtotime(date('Y-m-d'));
        $end_time = strtotime(date('Y-m-d 23:59:59'));
        $db = Db::name('seckill_count');
        $db->where('detail', $detail)
            ->where('uid', $uid)
            ->where('create_time', ['>', $start_time], ['<', $end_time], 'and');
        if (empty($db->find())) {
            $db->insert([
                'uid' => $uid,
                'create_time' => time(),
                'detail' => $detail
            ]);
        } else {
            $db->where('detail', $detail)
                ->where('uid', $uid)
                ->where('create_time', ['>', $start_time], ['<', $end_time], 'and')->setInc('num');
        }
    }

    public function randomAddGoods(array $param) {
        if (empty($param['list_id'])) throw new \Exception('list_id不能为空');
        if (empty($param['group_id'])) throw new \Exception('选品库id不能为空');
        $where = [
            'groupId' => $param['group_id'],
            'invalid' => 'false'
        ];
        $aliProductModel = new AliProductV2();
        $total = $aliProductModel->where($where)->count();
        if ($total < 90) throw new \Exception('该选品库商品不能低于90个');
        $max = (int)($total / 30);
        $times = SeckillListTime::all(['list_id' => $param['list_id']]);
        $b = [];
        foreach ($times as $k => $time) {
            $start = $this->randStart($max, $b);
            $feedIds = $aliProductModel->where($where)->limit($start * 30, 30)->column('feedId');
            $p = [
                'feedIds' => $feedIds,
                'time_id' => $time['id'],
                'list_id' => $param['list_id']
            ];
            $this->allAddGoods($p);
        }
        return 1;
    }

    protected function randStart($max, &$b) {
        $start = rand(0, $max);
        if (array_key_exists($start, $b)) {
            $start = $this->randStart($max, $b);
        } else {
            $b[$start] = 1;
        }
        return $start;
    }
}
