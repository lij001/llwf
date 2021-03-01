<?php


namespace app\api\service\goods;


use app\api\BaseService;
use app\api\model\goods\GoodsBrand;

class GoodsBrandService extends BaseService {
    /**
     * @param array $data 'brand_name','brand_logo','type','status'
     */
    public function insert($data) {
        $model = GoodsBrand::_whereCV(['type' => $data['type'], 'brand_name' => $data['brand_name']]);
        if (empty($data['shop_id'])) {
            $model->where('shop_id', 0);
        } else {
            $model->where('shop_id', $data['shop_id']);
        }
        $info = $model->find();
        if ($info === null) {
            $info = GoodsBrand::create($data);
        }
        return $info;
    }
}
