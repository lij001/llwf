<?php


namespace app\api\service\goods;

use app\api\BaseService;
use app\api\model\goods\Goods as GoodsModel;
use app\api\model\goods\GoodsAttr;
use app\api\model\goods\GoodsDetails;
use app\api\model\goods\GoodsImg;
use app\api\model\goods\GoodsRemark;
use app\api\model\goods\GoodsSku;
use app\service\CommonService;
use think\Db;
use think\Validate;

class GoodsService_v3 extends BaseService {
    /**
     * 获取第三方service
     * @param $goods
     * @return GoodsXyService
     * @throws \Exception
     */
    private function typeService($type) {
        $service = null;
        switch ($type) {
            case '行云':
                $service = GoodsXyService::objectInit();
                break;
            default:
                throw new \Exception('未知的类型!');
        }
        return $service;
    }

    /**
     * 插入商品
     * @param array $data 'brand_id','shop_id','cate_id','title','status','type','thumb','goods_img','goods_images','goods_detail'
     */
    public function insert($data) {
        try {
            $validate = new Validate([
                'brand_id' => 'require',
                'shop_id' => 'require',
                'cate_id' => 'require',
                'title' => 'require',
                'status' => 'require',
                'type' => 'require',
                'sku_list' => 'require',
                'attr_list' => 'require',
            ]);
            if (!$validate->check($data)) throw new \Exception($validate->getError());
            $service = $this->typeService($data['type']);
            Db::startTrans();
            $goods = GoodsModel::create($data, true);//插入商品
            $this->insertDetail($goods, $data);//插入详情
            $this->insertImages($goods, $data);//插入商品图片
            $service->insert($goods, $data);//插入第三方信息
            $this->insertAttr($goods, $data['attr_list']);
            $this->insertSku($goods, $data['sku_list']);
            Db::commit();
            return $goods;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }

    }

    /**
     * 插入商品详情
     * @param $goods
     * @param $data
     */
    private function insertDetail($goods, &$data) {
        $d = [
            'goods_id' => $goods['id'],
            'details' => $data['goods_detail'] ?: ''
        ];
        if (!empty($d['details'])) {
            $d['details'] = CommonService::objectInit()->htmlspecialchars_decode_disable_js($d['details']);
        }
        if (isset($data['measure_spec'])) $d['measure_spec'] = $data['measure_spec'];
        $info = GoodsDetails::_whereCV('goods_id', $goods['id'])->find();
        if ($info === null) {
            GoodsDetails::create($d);
        } else {
            $info->allowField(true)->save($d);
        }
    }

    /**
     * 插入商品图片
     * @param $goods
     * @param $data
     */
    private function insertImages($goods, &$data) {
        if (!empty($data['thumb'])) {
            $info = GoodsImg::_whereCV(['goods_id' => $goods['id'], 'type' => '缩略图'])->find();
            if ($info === null) {
                GoodsImg::create(['goods_id' => $goods['id'], 'img_url' => $data['thumb'], 'type' => '缩略图']);
            } else {
                $info['img_url'] = $data['thumb'];
                $info->save();
            }
        }
        if (!empty($data['goods_main_img'])) {
            $info = GoodsImg::_whereCV(['goods_id' => $goods['id'], 'type' => '主图'])->find();
            if ($info === null) {
                GoodsImg::create(['goods_id' => $goods['id'], 'img_url' => $data['goods_main_img'], 'type' => '主图']);
            } else {
                $info['img_url'] = $data['goods_main_img'];
                $info->save();
            }
        }
        if (!empty($data['goods_images'])) {
            $images = GoodsImg::_whereCV(['goods_id' => $goods['id'], 'type' => '轮播图'])->select();
            $key = 0;
            foreach ($data['goods_images'] as $k => $img_url) {
                if (!empty($images[$k])) {
                    GoodsImg::update(['img_url' => $img_url], ['id' => $images[$k]['id']]);
                } else {
                    GoodsImg::create(['goods_id' => $goods['id'], 'img_url' => $img_url, 'type' => '轮播图']);
                }
                $key = $k;
            }
            for ($count = $images->count() - 1 - $key; $count > 0; $count--) {
                $images[++$key]->delete();
            }
        }
    }

    /**
     * 商品属性
     * @param $goods
     * @param array $data
     */
    public function insertAttr($goods, $attr) {
        $d = [
            'goods_id' => $goods['id'],
            'attr' => $attr
        ];
        $info = GoodsAttr::_whereCV('goods_id', $goods['id'])->find();
        if ($info === null) {
            GoodsAttr::create($d, true);
        } else {
            $info->allowField(true)->save($d);
        }
    }

    /**
     * 插入sku
     * @param $goods
     * @param array $sku_list [['title','sku_value','sku_img','quantity','cost_price','status']]
     * @return
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function insertSku($goods, $sku_list) {
        if (empty($sku_list)) throw new \Exception('sku_list不能为空!');
        $service = $this->typeService($goods['type']);

        foreach ($sku_list as $k => $data) {
            $sku_id = null;
            if (empty($data['id'])) {
                $sku_id = $service->getSkuId($data);
            } else {
                $sku_id = $data['id'];
            }
            $sku = null;
            if (!$sku_id) {
                $validate = new Validate([
                    'sku_value' => 'require',
                    'sku_img' => 'require',
                    'quantity' => 'require',
                    'cost_price' => 'require',
                    'status' => 'require'
                ]);
                if (!$validate->check($data)) throw new \Exception($validate->getError());
                $data['goods_id'] = $goods['id'];
                $data['sku_detail_value'] = [];
                foreach ($data['sku_value'] as $key => $val) {
                    $data['sku_detail_value'][] = $key . ':' . $val;
                }
                $data['sku_value'] = implode('_', $data['sku_value']);
                $data['sku_detail_value'] = implode(',', $data['sku_detail_value']);
                $sku = GoodsSku::create($data, true);
                $sku['price'] = $service->getPrice($goods, $sku, $data);
                $sku['original_price'] = $service->getOriginalPrice($goods, $sku, $data);
                $sku->save();
            } else {
                $sku = GoodsSku::withTrashed()->where('id', $sku_id)->find();
                if (!empty($data['price']) && $data['price'] < $sku['cost_price']) {
                    throw new \Exception('售价不能低于成本价');
                }
                $sku->allowField(true)->save($data);
            }
            $service->insertSku($sku, $data);
        }
        $list = GoodsSku::_whereCV('goods_id', $goods['id'])->select();
        $goods['quantity'] = 0;
        $goods['sale_quantity'] = 0;
        $goods['price'] = 9999999;
        foreach ($list as $item) {
            if ($goods['price'] > $item['price']) {
                $goods['price'] = $item['price'];
                $goods['cost_price'] = $item['cost_price'];
                $goods['original_price'] = $item['original_price'];
            }
            $goods['quantity'] += $item['quantity'];
            $goods['sale_quantity'] += $item['sale_quantity'];
        }
        $goods->save();
        return $list;
    }

    public function getGoodsList($param) {
        $model = GoodsModel::objectInit();
        if (!empty($param['type'])) {
            $model->whereCV('type', $param['type']);
            $this->typeService($param['type'])->getGoodsListWhere($model, $param);
        }
        if (!empty($param['status'])) {
            $model->whereCV('status', $param['status']);
        }
        if (!empty($param['cate_id'])) {
            $cate_ids = GoodsCateService::objectInit()->getProgenyId($param['cate_id']);
            $model->whereIn('cate_id', $cate_ids);
        }
        if (!empty($param['title'])) {
            $model->where('title', 'like', '%' . $param['title'] . '%');
        }
        if (!empty($param['sort'])) {

        } else {
            $model->order('sale_quantity', 'desc');
        }
        $list = $model->with('thumb')->order('id', 'desc')->paginate();
        foreach ($list as $goods) {
            $this->typeService($goods['type'])->getGoodsList($goods);
        }
        return $list;
    }

    /**
     * 获取一条基础信息
     * @param $id
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getBaseGoodsOne($id) {
        return GoodsModel::_whereCV('id', $id)->find();
    }

    /**
     * @param int $id 商品id
     * @param int $sku_id
     * @param bool $normal true获取上架的sku,false获取所有sku
     * @return array|bool|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getDetail($id, $sku_id = 0, $normal = true) {
        if (!$id) throw new \Exception('商品id不能为空!');
        $with = ['thumb', 'brand'];
        $sku = $normal ? 'skuNormal' : 'skuAll';
        if ($sku_id > 0) {
            $with[$sku] = function ($query) use ($sku_id) {
                $query->where('id', $sku_id);
            };
        } else {
            $with = array_merge($with, ['goodsImg', 'goodsImages', 'detail', 'attr']);
        }
        $info = GoodsModel::_whereCV('id', $id)->with($with)->find();
        if ($info === null) throw new \Exception('未找到商品详情');
        $sku = $sku === 'skuNormal' ? 'sku_normal' : 'sku_all';
        $info['sku'] = $info[$sku];
        $info->hidden(['sku_normal', 'sku_all']);
        $service = $this->typeService($info['type']);
        $service->getDetail($info);
        $info['share_price'] = $service->getSharePrice($info['price'], $info['cost_price'], $info);
        return $info;
    }

    /**
     * @param array[] $param key=(id或sku_id)
     * @param bool $extend
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGoodsOne($param, $extend = false) {
        if (empty($param['id']) && ($param['sku_id'])) throw new \Exception('id或sku_id不能为空!');
        if (empty($param['id'])) {
            $sku = $this->getSkuOne($param['sku_id']);
            if ($sku === null) throw new \Exception('通过sku_id查找商品的sku_id不正确!');
            $param['id'] = $sku['goods_id'];
        }
        $info = GoodsModel::_whereCV('id', $param['id'])->find();
        if ($extend) {
            if ($info === null) return null;
            $this->typeService($info['type'])->getExtendGoods($info);
        }
        return $info;
    }

    public function getSkuOne($sku_id) {
        return GoodsSku::_whereCV('id', $sku_id)->find();
    }

    public function getDetailOrder($id, $sku_id) {
        $info = $this->getDetail($id, $sku_id);
        $info['sku'] = $info['sku'][0];
        $info['share_price'] = $this->typeService($info['type'])->getSharePrice($info['sku']['price'], $info['sku']['cost_price'], $info);
        return $info;
    }

    public function delete($id) {
        return GoodsModel::destroy($id);
    }

    public function disabled($id) {
        $info = GoodsModel::_whereCV('id', $id)->find();
        if ($info === null) throw new \Exception('未找到商品!');
        $info['status'] = '下架';
        $info->save();
        return $info;
    }

    public function update($data) {
        try {
            $validate = new Validate([
                'brand_id' => 'require',
                'shop_id' => 'require',
                'cate_id' => 'require',
                'title' => 'require',
                'status' => 'require',
                'type' => 'require',
                'goods_id' => 'require',
                'attr_list' => 'require',
                'sku_list' => 'require',
            ]);
            if (!$validate->check($data)) throw new \Exception($validate->getError());
            $service = $this->typeService($data['type']);
            $goods = GoodsModel::withTrashed()->where('id', $data['goods_id'])->find();
            if ($goods === null) throw new \Exception('该商品不存在!');
            Db::startTrans();
            $goods->allowField(true)->save($data);
            $this->insertDetail($goods, $data);//插入详情
            $this->insertImages($goods, $data);//插入商品图片
            $service->insert($goods, $data);//插入第三方信息
            $this->insertAttr($goods, $data['attr_list']);
            $this->insertSku($goods, $data['sku_list']);
            Db::commit();
            return $goods;
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 获取商品备注
     * @param int $id 商品id
     */
    public function getRemark($id) {
        if (!$id) throw new \Exception('id不能为空!');
        return GoodsRemark::_whereCV('goods_id', $id)->with('user')->order('id', 'desc')->select();
    }

    public function addGoodsRemark($param) {
        $validate = new Validate([
            'goods_id' => 'require',
            'uid' => 'require',
            'remark' => 'require',
        ]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        $param['create_time'] = time();
        return GoodsRemark::create($param, true);
    }

    public function deleteGoodsRemark($param) {
        $validate = new Validate(['id' => 'require',]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        return GoodsRemark::destroy($param['id']);
    }
}
