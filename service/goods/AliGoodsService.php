<?php

namespace app\api\service\goods;

use app\api\model\goods\AliGoods;

class AliGoodsService extends GoodsService {
    /**
     * id
     * @param $param
     * @return array|bool|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGoodsInfo($param) {
        $model = AliGoods::objectInit();
        if (is_array($param)) {
            $model->whereCV($param);
        } else {
            $model->where('id', $param);
        }
        return $model->with('attr')->find();
    }
}
