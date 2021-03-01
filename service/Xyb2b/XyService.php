<?php


namespace app\api\service\Xyb2b;


use app\api\service\goods\GoodsBrandService;
use app\api\service\goods\GoodsCateService;
use app\api\service\goods\GoodsService_v3;
use app\api\service\goods\GoodsXyService;
use app\api\service\order\OrderService;
use app\api\service\order\OrderXyService;
use app\api\service\RedisServer;
use think\Db;

class XyService extends BaseClient {
    /**
     * 获取商品分类
     * @param int $parent_cat_id 父级分类id
     * @return bool|string
     */
    public function getRemoteGoodsCategory($parent_cat_id) {
        $param = [
            "opcode" => "get_goods_category",
            "merchant_id" => self::XY_MERCH_ID,
            "parent_cat_id" => $parent_cat_id ?: 0
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 获取商品列表
     * @param int $category_id 分类id
     * @param int $page_index 页码:从1开始
     * @param int $page_size 每页显示多少条(最多50)
     * @return bool|string
     */
    public function getRemoteGoodsList($category_id, $page_index, $page_size) {
        $param = [
            "opcode" => "get_goods_list",
            "merchant_id" => self::XY_MERCH_ID,
            "category_id" => $category_id ?: 0,
            "page_index" => $page_index ?: 1,
            "page_size" => $page_size ?: 10
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 获取商品详情
     * @param $goods_code
     * @return bool|string
     */
    public function getRemoteGoodsDetail($goods_code) {
        $param = [
            "opcode" => "get_goods_detail",
            "merchant_id" => self::XY_MERCH_ID,
            "goods_code" => $goods_code,
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 获取sku库存
     * @param $sku_code sku编码
     * @return bool|string
     */
    public function getSkuBatch($sku_code) {
        $param = [
            "opcode" => "get_sku_batch",
            "merchant_id" => self::XY_MERCH_ID,
            "sku_code" => $sku_code,
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 获取sku价格
     * @param $sku_code sku编码
     * @return bool|string
     */
    public function getSkuPrice($sku_code) {
        $param = [
            "opcode" => "get_sku_price",
            "merchant_id" => self::XY_MERCH_ID,
            "sku_code" => $sku_code,
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 订单生成
     * @param string $merchant_order_id 商家订单号
     * @param array $sku_list 商品数组 签名前按字段名的字母顺序排序数组内字段
     * @param string $pay_type 付款方式(1.余额支付，2.信用支付）
     * @param string $recipient_name 收货人姓名
     * @param string $recipient_mobile 收货人手机号
     * @param string $order_province 所属区域：省（中文）
     * @param string $order_city 所属区域：市（中文）
     * @param string $order_area 所属区域:区县
     * @param string $order_address 收货详细地址
     * @param string $order_pay_amount 终端支付总金额(单位：元，保留两位小数）
     * @param string $order_discount_amount 平台优惠总金额(单位：元，保留两位小数)
     * @param string $channel_discount_amount 渠道优惠总金额(单位：元，保留两位小数)
     * @param string $order_freight_amount 运费总金额(单位：元，保留两位小数)）
     * @param string $order_total_amount 订单总金额 (单位：元，保留两位小数）
     * @param string $buyer_name 收货人名称
     * @param string $buyer_card_id 收货人身份证
     * @param string $pay_third_no 交易流水号
     * @param string $pay_company_name 支付公司名称
     * @param string $pay_custom_no 支付公司编码
     * @param string $third_pay_type 支付方式：(1 支付宝,2 微信)
     * @param string $custom_pay_order 清关单号（推支付单时带的订单号）
     * @return bool|string
     */
    public function addCustomOrder($merchant_order_id, $sku_list, $pay_type, $recipient_name, $recipient_mobile, $order_province, $order_city,$order_area, $order_address, $order_pay_amount, $order_discount_amount, $channel_discount_amount, $order_freight_amount, $order_total_amount, $buyer_name, $buyer_card_id, $pay_third_no = '', $pay_company_name = '', $pay_custom_no = '', $third_pay_type = '', $custom_pay_order = '') {
        $param = [
            "opcode" => "add_custom_order",
            "merchant_id" => self::XY_MERCH_ID,
            "order_type" => 2,
            "merchant_order_id" => $merchant_order_id,
            "pay_type" => $pay_type,
            "recipient_name" => $recipient_name,
            "recipient_mobile" => $recipient_mobile,
            "order_province" => $order_province,
            "order_city" => $order_city,
            "order_area" => $order_area,
            "order_address" => $order_address,
            "order_pay_amount" => $order_pay_amount,
            "order_discount_amount" => $order_discount_amount,
            "channel_discount_amount" => $channel_discount_amount,
            "order_freight_amount" => $order_freight_amount,
            "order_total_amount" => $order_total_amount,
            "sku_list" => $sku_list,
            'ecommerce_name' => self::ECOMMERCE_NAME,
            'ecommerce_code' => self::ECOMMERCE_CODE,
        ];
        if (!empty($pay_third_no)) {
            $param['buyer_name'] = $buyer_name;
            $param['buyer_card_id'] = $buyer_card_id;
            $param['recipient_card_id'] = $buyer_card_id;
            $param['pay_third_no'] = $pay_third_no;//交易流水号
            $param['pay_company_name'] = $pay_company_name;//支付公司名称
            $param['pay_custom_no'] = $pay_custom_no;//支付公司编码
            $param['third_pay_type'] = $third_pay_type;//支付方式
            $param['custom_pay_order'] = $custom_pay_order;//清关单号
            $param['order_time'] = date('Y-m-d H:i:s');//下单时间
            $param['pay_time'] = date('Y-m-d H:i:s');//支付时间
            $param['push_type'] = 1;
            $param['order_type'] = 1;
        } elseif (!empty($buyer_card_id)) {
            $param['recipient_card_id'] = $buyer_card_id;
        }
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 查询订单状态
     * @param $merchant_order_id 商家订单号|是
     * @param $order_id 行云订单号|是
     * @return bool|string
     */
    public function queryOrder($merchant_order_id, $order_id) {
        $param = [
            "opcode" => "query_order",
            "merchant_id" => self::XY_MERCH_ID,
            "merchant_order_id" => $merchant_order_id,
            "order_id" => $order_id,
        ];
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**
     * 通知确认
     * 通知确认类型 1.生成订单通知(生成订单通知，除余额不足和库存不足外，取值1) 2.生成订单余额不足通知 3.生成订单库存不足通知 4.取消订单通知 5.售后订单通知 6.发货通知
     */
    public function notificationConfirm($merchant_order_id, $order_id, $notice_type, $biz_order_id = null) {
        $param = [
            "opcode" => "notice_ack",
            "merchant_id" => self::XY_MERCH_ID,
            "merchant_order_id" => $merchant_order_id,
            "order_id" => $order_id,
            "notice_type" => $notice_type
        ];
        if ($biz_order_id != null) {
            $param["biz_order_id"] = $biz_order_id;
        }
        $paramToSign = $this->getSign($param);
        $data = $this->curlPost(self::XY_URL, $paramToSign);
        return $data;
    }

    /**拉取商品
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function pullGoods() {
        GoodsCateService::objectInit()->insertXyCate();//拉取插入最新的分类
        $cate_list = GoodsCateService::objectInit()->getXyCate(['pid' => 0]);//获取行云分类
        foreach ($cate_list as $val) {
            $page = 0;
            $page_size = 50;
            while (++$page) {
                $response = $this->getRemoteGoodsList($val['remote_id'], $page, $page_size);
                $response = $response['goods_list'];
                if ($page > $response['page_count']) break;
                foreach ($response['list'] as $item) {
                    $this->insertGoods($item['goods_code']);
                }
            }
        }
    }

    private function insertGoods($goods_code) {
        try {
            $d = $this->getRemoteGoodsDetail($goods_code);
            $goods = $d['goods_detail'];
            $cate = GoodsCateService::objectInit()->getXyCateFind([
                $goods['category_name3'],
                $goods['category_name2'],
                $goods['category_name1']
            ]);
            $goods_bak = [];
            //未找到分类就跳过该商品 查找下一条
            if ($cate === null) return;
            $goods_bak['brand'] = [
                'brand_name' => $goods['brand_name'],
                'brand_logo' => $goods['brand_logo'],
                'type' => '行云',
                'status' => '上架'
            ];
            $goods_bak['self_goods'] = [
                'shop_id' => 0,
                'brand_id' => 0,
                'cate_id' => $cate['id'],
                'title' => $goods['goods_name'],
                'quantity' => 0,
                'status' => '上架',
                'type' => '行云',
                'goods_code' => $goods['goods_code'],
                'trade_name' => $goods['trade_name'],
                'origin_name' => $goods['origin_name'],
                'origin_icon' => $goods['origin_icon'],
                'thumb' => $goods['goods_img'],
                'goods_main_img' => $goods['goods_img'],
                'goods_images' => $goods['goods_thumb_image'],
                'goods_detail' => $goods['goods_detail'] ?: '',
            ];
            $specification = ['name' => '规格', 'detailValue' => '', 'attrHidden' => false, 'values' => []];
            $period = ['name' => '效期', 'detailValue' => '', 'attrHidden' => false, 'values' => []];
            $pieces = ['name' => '件装', 'detailValue' => '', 'attrHidden' => false, 'values' => []];
            $measure_spec = '';
            $sku_bak = [];
            foreach ($goods['sku_list'] as $sku) {
                $measure_spec = $sku['measure_spec'];
                if (!in_array($sku['sku_spec_value'], $specification['values'])) {
                    $specification['values'][] = $sku['sku_spec_value'];
                }
                $sku_price = $this->getSkuPrice($sku['sku_code'])['sku_price'];
                if (empty($sku_price)) continue;
                $sku_price = $sku_price[0];
                $sku_price['quality_end_time'] = date('Y-m-d', strtotime($sku_price['quality_end_time']));
                if (!in_array($sku_price['quality_end_time'], $period['values'])) {
                    $period['values'][] = $sku_price['quality_end_time'];
                }
                $sku_stock = $this->getSkuBatch($sku['sku_code'])['sku_batch'];
                if (empty($sku_stock)) continue;
                $sku_stock = $sku_stock[0];
                $sku_json = $sku;
                $sku_price_json = $sku_price;
                $sku_stock_json = $sku_stock;
                foreach ($sku_price['price_list'] as $price) {
                    if (!in_array($price['batch_packagenum'], $pieces['values'])) {
                        $pieces['values'][] = $price['batch_packagenum'];
                    }
                    $sku_bak[] = [
                        'title' => $sku['sku_name'] ?: '',
                        'sku_value' => ['规格' => $sku['sku_spec_value'], '效期' => $sku_price['quality_end_time'], '件装' => $price['batch_packagenum']],
                        'sku_img' => $sku['sku_thumb_image'],
                        'quantity' => $sku_stock['lock_stock_num'],
                        'min_quantity' => $price['batch_start_num'],
                        'cost_price' => $price['batch_sell_price'],
                        'weight' => $price['package_weight'],
                        'status' => '上架',
                        'sku_code' => $sku['sku_code'] . '_' . $price['batch_packagenum'],
                        'international_code' => $sku['international_code'],//国际条码
                        'sku_tax_rate' => $sku['sku_tax_rate'],//跨境综合税率
                        'sku_attr' => $sku_json,
                        'sku_stock' => $sku_stock_json,
                        'sku_price' => $sku_price_json
                    ];
                    $goods_bak['quantity'] += $sku_stock['lock_stock_num'];
                }
            }
            if (empty($sku_bak)) return;
            $goods_bak['measure_spec'] = $measure_spec;
            $goods_bak['sku_list'] = $sku_bak;
            $goods_bak['attr'] = [$specification, $period, $pieces];
            $this->insertData($goods_bak);
        } catch (\Exception $e) {

        }
    }

    private function insertData($goods) {
        try {
            Db::startTrans();
            $brand = GoodsBrandService::objectInit()->insert($goods['brand']);
            if ($brand === null) throw new \Exception('品牌不存在!');
            $goods['self_goods']['brand_id'] = $brand['id'];
            $goods['self_goods']['sku_list'] = $goods['sku_list'];
            $goods['self_goods']['attr_list'] = $goods['attr'];
            $goods['self_goods']['measure_spec'] = $goods['measure_spec'];
            $goods_id = GoodsXyService::objectInit()->getGoodsId($goods['self_goods']);
            if (!$goods_id) {
                GoodsService_v3::objectInit()->insert($goods['self_goods']);
            } else {
                $info = GoodsService_v3::objectInit()->getDetail($goods_id);
                if ($info === null || $info['extend']['sync'] === '不同步') throw new \Exception('不同步的商品不更新');
                $goods['self_goods']['goods_id'] = $goods_id;
                GoodsService_v3::objectInit()->update($goods['self_goods']);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            dump($e->getMessage());
        }
    }

    public function callBackPush($param) {
        RedisServer::objectInit()->lPush('xy_callBack', $param);
    }

    public function callBack() {
        $redis = RedisServer::objectInit();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        while (1) {
            try {
                $param = $redis->BrPop('xy_callBack');
                switch ($param['notice_code']) {
                    case 'generate_order_notice'://订单创建通知
                        OrderXyService::objectInit()->callBack($param, '创建订单');
                        $this->notificationConfirm($param['merchant_order_id'], $param['order_id'], $param['generate_fail_type']);
                        break;
                    case 'deliver_order_notice'://订单发货
                        $this->notificationConfirm($param['merchant_order_id'], $param['order_id'], 6, $param['biz_order_id']);
                        $express = current($param['express_list']);
                        $data = [
                            'order_no' => $param['merchant_order_id'],
                            'express_company' => $express['express_company'],
                            'express_code' => $express['express_code'],
                            'express_sn' => $express['express_order']
                        ];
                        OrderService::objectInit()->deliverOrder($data);
                        break;
                    case 'cancel_order_notice'://订单取消通知
                        $this->notificationConfirm($param['merchant_order_id'], $param['order_id'], 4);
                        $data = [
                            'order_no' => $param['merchant_order_id'],
                            'notice_code' => $param['notice_code']
                        ];
                        OrderService::objectInit()->cancelOrder($data);
                        break;
                    case 'aftersale_order_notice'://订单售后通知
                        $this->notificationConfirm($param['merchant_order_id'], $param['order_id'], 5, $param['biz_order_id']);
                        OrderXyService::objectInit()->callBack($param, '订单售后');
                        break;
                    case 'sku_price_notice'://价格&库存通知
                        $this->skuPriceNotice($param);
                        break;
                }
            } catch (\Exception $e) {
                Db::name('xy_callback')->insert(['param' => $e->getMessage()]);
            }
        }

    }

    public function skuPriceNotice($param) {
        $sku_code = $param['sku_code'];
        $goods_code = $param['spu_code'];
        $data = ['goods_code' => $goods_code];
        $goods_id = GoodsXyService::objectInit()->getGoodsId($data);
        if ($goods_id === null) return;
        $goods = GoodsService_v3::objectInit()->getDetail($goods_id, 0, false);
        if ($goods === null || $goods['extend']['sync'] === '不同步') return;
        switch (((int)$param['notice_type'])) {
            case 1://价格变动通知
                $sku_price = $this->getSkuPrice($sku_code)['sku_price'];
                if (empty($sku_price)) return;
                $t_sku = [];
                foreach ($sku_price[0]['price_list'] as $price) {
                    $t_sku[$sku_code . '_' . $price['batch_packagenum']] = $price;
                    $t_sku['cost_price'] = $price['batch_sell_price'];
                }
                foreach ($goods['sku'] as $sku) {
                    if (array_key_exists($sku['extend']['sku_code'], $t_sku)) {
                        $sku['cost_price'] = $t_sku[$sku['extend']['sku_code']]['batch_sell_price'];
                        $sku->allowField(true)->save();
                    }
                }
                break;
            case 2://有货通知
                $sku_stock = $this->getSkuBatch($sku_code)['sku_batch'][0];
                if (empty($sku_stock)) return;
                $goods['quantity'] = 0;
                foreach ($goods['sku'] as $sku) {
                    $sku['quantity'] = $sku_stock['lock_stock_num'];
                    $sku->allowField(true)->save();
                    $goods['quantity'] += $sku_stock['lock_stock_num'];
                }
                $goods['status'] = '上架';
                $goods->allowField(true)->save();
                break;
            case 3://无货通知
                $goods['quantity'] = 0;
                foreach ($goods['sku'] as $sku) {
                    $sku['quantity'] = 0;
                    $sku->allowField(true)->save();
                }
                $goods['status'] = '下架';
                $goods->allowField(true)->save();
                break;
            case 4://上架通知
                $goods['status'] = '上架';
                $goods->allowField(true)->save();
                break;
            case 5://下架通知
                $goods['status'] = '下架';
                $goods->allowField(true)->save();
                break;
        }
        Db::name('xy_callback')->insert(['param' => json_encode($param, JSON_UNESCAPED_UNICODE)]);
    }


}
