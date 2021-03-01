<?php


namespace app\api\service\Seckill;


use app\api\BaseService;
use app\api\model\AliProduct;
use app\api\model\AliProductV2Attr;
use app\api\model\Seckill\SeckillGoodsAttr;
use app\api\service\RedisServer;
use think\Db;

class SeckillGoodsAttrServer extends BaseService {

    static public function syncs($feedId, &$data) {
        $model = SeckillGoodsAttr::objectInit();
        $list = $model->query('select * from ' . $model->getTable() . ' where feedId=' . $feedId . ' AND list_id=' . $data['list_id']);
        if (empty($list)) return true;
        $aliattrModel = new AliProductV2Attr();
        $l = $aliattrModel->whereIn('skuId', array_column($list, 'skuId'))->where('feedId', $feedId)->select();
        $original = [];
        foreach ($l as $val) {
            $original['A' . $val['skuId']] = $val;
        }
        foreach ($list as $val) {
            $val['quantity'] = $data['quantity'];
            $val['price'] = $original['A' . $val['skuId']]['sale_price'] * $data['price_discount'] / 100;
            Db::table($model->getTable())->where(['list_id' => $val['list_id'], 'skuId' => $val['skuId'], 'feedId' => $val['feedId']])->update($val);
        }

        return true;
    }

    static public function attrInfo(&$return, &$data, $skuId) {
        if ($return['seckill_status'] == 0) return false;
        $attr = SeckillGoodsAttr::get(['skuId' => $skuId, 'list_id' => $return['seckill_info']['list_id'], 'feedId' => $return['seckill_info']['feedId']]);
        if (empty($attr)) return false;
        $data['seckill_attr'] = $attr->toArray();
        $redis = RedisServer::objectInit();
        $key = implode('-', [$attr['list_id'], $attr['feedId'], $attr['skuId']]);
        $data['seckill_attr']['shengxia'] = $redis->get($key);
        return true;
    }

    public function addAttr($feedId, $data) {
        if (empty($feedId)) throw new \Exception('商品id不能为空!');
        $attrModel = new AliProductV2Attr();
        $attrList = $attrModel->where('feedId', $feedId)->select();
        if ($attrList->isEmpty()) return true;
        $model = SeckillGoodsAttr::objectInit();
        $seckillAttrList = $model->where('feedId', $feedId)->where('list_id', $data['list_id'])->select();
        $skAL = [];
        foreach ($seckillAttrList as $val) {
            $skAL['A' . $val['skuId']] = $val;
        }
        $insert = [];
        foreach ($attrList as $attr) {
            $temp = [
                'feedId' => $feedId,
                'skuId' => $attr['skuId'],
                'price' => $data['price'],
                'list_id' => $data['list_id'],
                'sell_quantity' => 0
            ];
            if (empty($skAL['A' . $attr['skuId']])) {
                $insert[] = $temp;
            } else {
                $model->where('feedId', $temp['feedId'])->where('skuId', $temp['skuId'])->where('list_id', $temp['list_id'])->update($temp);
            }
        }
        $model->saveAll($insert);
        return true;
    }

    public function getAttrs($list_id, $feedId) {
        $model = new AliProductV2Attr();
        return SeckillGoodsAttr::objectInit()
            ->alias('a')
            ->field('a.*,b.title,b.img,b.sale_price')
            ->join($model->getTable() . ' b', 'b.skuId=a.skuId AND b.feedId=a.feedId')
            ->where(['a.list_id' => $list_id, 'a.feedId' => $feedId])
            ->select();
    }
}

