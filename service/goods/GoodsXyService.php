<?php


namespace app\api\service\goods;


use app\api\model\goods\GoodsXy;
use app\api\model\goods\GoodsXySku;
use app\api\model\order\Order;
use think\Model;
use think\Validate;

class GoodsXyService extends Goods {
    public function getGoodsId(&$data) {
        if (!isset($data['goods_code'])) throw new \Exception('行云goods_code不能为空!');
        // TODO: Implement getGoodsId() method.
        $info = GoodsXy::_whereCV('goods_code', $data['goods_code'])->find();
        if ($info === null) return null;
        return $info['goods_id'];
    }

    public function getSkuId(&$data) {
        // TODO: Implement getSkuId() method.
        if (!isset($data['sku_code'])) throw new \Exception('行云sku_code不能为空!');
        $info = GoodsXySku::_whereCV('sku_code', $data['sku_code'])->find();
        if ($info === null) return null;
        return $info['sku_id'];
    }

    public function insert($goods, &$data) {
        // TODO: Implement insert() method.
        $info = GoodsXy::_whereCV('goods_id', $goods['id'])->find();
        if ($info === null) {
            if (!isset($data['goods_code'])) throw new \Exception('行云goods_code不能为空!');
            $d = [
                'goods_id' => $goods['id'],
                'goods_code' => $data['goods_code'],
                'trade_name' => $data['trade_name'] ?: '',
                'origin_name' => $data['origin_name'] ?: '',
                'origin_icon' => $data['origin_icon'] ?: '',
            ];
            GoodsXy::create($d);
        } else {
            $info->allowField(true)->save($data);
        }
    }

    public function insertSku($sku, &$data) {
        // TODO: Implement insertSku() method.
        $info = GoodsXySku::_whereCV('sku_id', $sku['id'])->find();
        if ($info === null) {
            $validate = new Validate([
                'sku_code' => 'require',
                'sku_attr' => 'require',
                'sku_stock' => 'require',
                'sku_price' => 'require'
            ]);
            $d = [
                'sku_id' => $sku['id'],
                'sku_code' => $data['sku_code'],
                'international_code' => $data['international_code'] ?: '',
                'sku_tax_rate' => $data['sku_tax_rate'] ?: '',
                'sku_attr' => $data['sku_attr'],
                'sku_stock' => $data['sku_stock'],
                'sku_price' => $data['sku_price']
            ];
            if (!$validate->check($data)) throw new \Exception($validate->getError());
            GoodsXySku::create($d);
        } else {
            $info->allowField(true)->save($data);
        }
    }

    /**
     * @param Model $info
     */
    public function getDetail($info) {
        $this->getExtendGoods($info);
        $this->getExtendSku($info['sku']);
        return $info;
    }

    public function getExtendGoods($info) {
        $xy_info = GoodsXy::_whereCV('goods_id', $info['id'])->find();
        if ($xy_info === null) throw new \Exception('行云商品信息不全,扩展未记载信息!');
        $info['extend'] = $xy_info;
    }

    public function getExtendSku($sku_list) {
        if ($sku_list->isEmpty()) throw new \Exception('商品异常,sku为空!');
        $d = [];
        foreach ($sku_list as $sku) {
            $d[$sku['id']] = $sku;
        }
        $xy_sku = GoodsXySku::_whereCVIn('sku_id', array_keys($d))->select();
        if ($xy_sku->isEmpty()) throw new \Exception('行云商品sku信息不全,扩展未记载信息!');
        foreach ($xy_sku as $sku) {
            $d[$sku['sku_id']]['extend'] = $sku;
        }
    }

    /**
     * @param \app\api\model\goods\Goods $model
     * @param $param
     * @throws \think\exception\DbException
     */
    public function getGoodsListWhere($model, $param) {
        $model->where('id in ' . GoodsXy::_whereCV('trade_name', '<>', '跨境保税')->field('goods_id')->buildSql());
    }

    public function getGoodsList($goods) {
        $this->getExtendGoods($goods);
    }

}
