<?php


namespace app\api\service\goods;


use app\api\BaseService;
use think\Db;


abstract class Goods extends BaseService {
    abstract public function getGoodsId(&$data);

    abstract public function getSkuId(&$data);

    /**
     * 商品新增和更新 都会执行
     * @param $goods
     * @param $data
     * @return mixed
     */
    abstract public function insert($goods, &$data);

    /**
     * sku新增和更新 都会执行
     * @param $sku
     * @param $data
     * @return mixed
     */
    abstract public function insertSku($sku, &$data);

    abstract public function getDetail($info);

    abstract public function getExtendGoods($info);

    abstract public function getExtendSku($sku_list);

    /**
     * @param float $price 售价
     * @param float $cost_price 成本价
     * @param \think\Model $goods 商品模型
     * @return mixed
     */
    public function getSharePrice($price, $cost_price, $goods) {
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        $money = $price - bcdiv($price * $cashFlow, 100, 2) - $cost_price;
        $up1_m = Db::name('config_reward')->where('type', 2)->value('up1_m');
        $sharePrice = bcdiv($money * $up1_m, 100, 2);
        if ($sharePrice < 0) {
            $sharePrice = 0;
        }
        return $sharePrice;
    }

    abstract public function getGoodsListWhere($model, $param);

    abstract public function getGoodsList($goods);

    public function getPrice($goods, $sku, &$data) {
        $cate_info = GoodsCateService::objectInit()->getForefathers($goods['cate_id']);//获取分类的顶级分类溢价比
        if ($cate_info === null) throw new \Exception('未找到分类信息,无法计算价格!');
        //售价=成本*(100+溢价比)%
        $price = round(($sku['cost_price'] * (100 + $cate_info['rate']) / 100), 2);
        return $price;

    }

    public function getOriginalPrice($goods, $sku, &$data) {
        return (int)($sku['price'] * 1.5);
    }
}
