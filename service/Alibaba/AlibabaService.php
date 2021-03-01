<?php

namespace app\api\service\Alibaba;

use app\api\controller\AliProductController;
use think\Db;
use think\Cache;
use think\Image;
use app\api\model\AliProductV2;
use app\api\model\AliProductV2Attr;

class AlibabaService extends BaseClient {
    const PAGE_SIZE = 15;
    const PAGE_SIZE_MIN = 10;
    const PAGE_SIZE_MAX = 20;
    var $_aop_datePattern = 'yyyyMMddHHmmssSSSZ';
    //社交电商采购对接方案

    /**
     * 获取我的选品库列表
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function listCybUserGroup($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_P4P);
        $this->setName(Request::NAME_P4P_LIST_CYB_USER_GROUP);
        // 参数处理
        $data = array_filter(array_merge([
            'pageNo' => isset($params['pageNo']) ? $params['pageNo'] : 1,
            'pageSize' => isset($params['pageSize']) ? $params['pageSize'] : self::PAGE_SIZE,
        ], $params));

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 获取选品库已选商品列表
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function listCybUserGroupFeed($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_P4P);
        $this->setName(Request::NAME_P4P_LIST_CYB_USER_GROUP_FEED);
        // 参数处理
        $data = array_filter(array_merge([
            'groupId' => isset($params['groupId']) ? $params['groupId'] : 1,
            'pageNo' => isset($params['pageNo']) ? $params['pageNo'] : 1,
            'pageSize' => isset($params['pageSize']) ? $params['pageSize'] : self::PAGE_SIZE,
        ], $params));

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 获取商品详情
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function productInfo($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_PRODUCT);
        $this->setName(Request::NAME_PRODUCT_PRODUCT_INFO);
        // 参数处理
        $data = array_filter(array_merge([
            'offerId' => isset($params['offerId']) ? $params['offerId'] : null,
            'needCpsSuggestPrice' => 'true',
            'needIntelligentInfo' => 'true',
        ], $params));

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 获取营销活动价格等活动信息
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function activity($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_P4P);
        $this->setName(Request::NAME_P4P_QUERY_OFFER_DETAIL_ACTIVITY);
        // 参数处理
        $data = array_filter(array_merge([
            'offerId' => isset($params['offerId']) ? $params['offerId'] : null,
        ], $params));

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 根据类目Id查询类目
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function category($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_PRODUCT);
        $this->setName(Request::NAME_PRODUCT_CATEGORY_GET);
        // 参数处理
        $data = array_merge([
            'categoryID' => $params['categoryID'],
        ], $params);

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 根据类目Id查询类目
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function PricedOffer($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_P4P);
        $this->setName(Request::NAME_P4P_SEARCH_CYB_OFFERS);
        // 参数处理
        $data = array_filter(array_merge([
            'biztype' => isset($params['biztype']) ? $params['biztype'] : null,                              //枚举;经营模式;1:生产加工,2:经销批发,3:招商代理,4:商业服务
            'buyerProtection' => isset($params['buyerProtection']) ? $params['buyerProtection'] : null,      //枚举;买家保障,多个值用逗号分割;qtbh:7天包换;swtbh:15天包换
            'city' => isset($params['city']) ? $params['city'] : null,                                       //所在地区- 市
            'deliveryTimeType' => isset($params['deliveryTimeType']) ? $params['deliveryTimeType'] : null,   //枚举;发货时间;1:24小时发货;2:48小时发货;3:72小时发货
            'descendOrder' => isset($params['descendOrder']) ? $params['descendOrder'] : null,               //是否倒序;正序: false;倒序:true
            'holidayTagId' => isset($params['holidayTagId']) ? $params['holidayTagId'] : null,               //商品售卖类型筛选;枚举,多个值用分号分割;免费赊账:50000114
            'keyWords' => isset($params['keyWords']) ? $params['keyWords'] : null,                           //搜索关键词
            'page' => isset($params['page']) ? $params['page'] : 1,                                          //页码
            'pageSize' => isset($params['pageSize']) ? $params['pageSize'] : self::PAGE_SIZE_MIN,            //页面数量;最大20
            'postCategoryId' => isset($params['postCategoryId']) ? $params['postCategoryId'] : null,         //类目id;一级为0
            'priceStart' => isset($params['priceStart']) ? $params['priceStart'] : null,                     //最低价
            'priceEnd' => isset($params['priceEnd']) ? $params['priceEnd'] : null,                           //最高价
            'priceFilterFields' => isset($params['priceFilterFields']) ? $params['priceFilterFields'] : 'agent_price', //价格类型;默认分销价;agent_price:分销价;
            'province' => isset($params['province']) ? $params['province'] : null,                           //所在地区- 省
            'sortType' => isset($params['sortType']) ? $params['sortType'] : null,                           //枚举;排序字段;normal:综合;
            'offerTags' => isset($params['offerTags']) ? $params['offerTags'] : null,                        //枚举;1387842:渠道专享价商品
            'offerIds' => isset($params['offerIds']) ? $params['offerIds'] : null,                           //商品id搜索，多个id用逗号分割
            'tags' => isset($params['tags']) ? $params['tags'] : null,                                       //枚举:271938:厂货通
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 对成功和失败进行处理
     * @param $data
     * @return array
     */
    protected function Response($data) {
        return $data;
    }

    /**
     * 过滤商品详情,只输出图片
     * @param $data
     * @return array
     */
    function getDescImg($desc) {
        $pattern = "/<[img|IMG].*?src=[\'|\"](.*?(?:[\.gif|\.jpg]))[\'|\"].*?[\/]?>/";
        preg_match_all($pattern, $desc, $match);
        return $match;
    }

    /**
     * 获取阿里产品的分享图片
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function shareGoods($uid, $imgUrl = '', $price = '', $offerId = '', $title = '') {
        $number = Db::name('members')->where('id', $uid)->value('number');
        $dir = "upload/share_goods";
        $mkdir = iconv("UTF-8", "GBK", $dir);
        if (!file_exists($mkdir)) {
            mkdir($mkdir, 0777, true);
        }
        //下载商品图片
        downloadImage($imgUrl, 'upload/share_goods/');

        $downUrl = explode('/', $imgUrl);
        $imgName = $downUrl[count($downUrl) - 1];

        //下载的图片缩放
        $image_down = \think\Image::open('upload/share_goods/' . $imgName);
        $image_down->thumb(480, 400, \think\Image::THUMB_FIXED)->save('upload/share_goods/' . $number . '_' . $imgName);
        //删除下载的原图
        @unlink('upload/share_goods/' . $imgName);

        //商品二维码
        $goods_id = '100000' . $offerId;
        $goods_file = shareGoods($number, $goods_id);
        $location1 = [10, 10];   // 商品图片
        $location2 = [10, 420];  // 推广二维码图片
        $location3 = [200, 430]; // 商品标题
        $location4 = [200, 460]; // 商品标题
        $location5 = [200, 490]; // 商品标题
        $location6 = [350, 560]; // 券后价

        $ttf = VENDOR_PATH . '/topthink/think-captcha/assets/ttfs2/1.ttf';
        if (!empty($mei)) {
            $x_location = 500 - mb_strlen($price) * 14; //500背景图宽度，每个字大概14像素
            $location6 = [$x_location, 560]; // 券后价
        }
        // 文字换行处理
        $title1 = mb_substr($title, 0, 13, 'utf-8');
        $title2 = mb_substr($title, 13, 13, 'utf-8');
        $title3 = mb_substr($title, 26, 13, 'utf-8');
        $image0 = \think\Image::open('static/images/goods_white.png');
        $image0->water('upload/share_goods/' . $number . '_' . $imgName, $location1)
            ->water($goods_file, $location2)
            ->text($title1, $ttf, 15, '#283645', $location3)
            ->text($title2, $ttf, 15, '#283645', $location4)
            ->text($title3, $ttf, 15, '#283645', $location5)
            ->text("价格:¥" . $price, $ttf, 16, '#D4376C', $location6)
            ->save('upload/share_goods/' . $number . '_copy' . '_' . $imgName);
        //删除二维码
        //@unlink($goods_file);
        $re_url = cmf_get_image_url('share_goods/' . $number . '_copy' . '_' . $imgName);
        return $re_url;
    }

    /**
     * 获取阿里产品的分享赚
     * @param $price 销售价
     * @param $old_price 成本价
     * @return array
     * @throws \think\Exception
     */
    function getThisSharePrice($price, $old_price) {
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        $money = $price - bcdiv($price * $cashFlow, 100, 2) - $old_price;
        $up1_m = Db::name('config_reward')->where('type', 2)->value('up1_m');
        $sharePrice = bcdiv($money * $up1_m, 100, 2);
        if ($sharePrice < 0) {
            $sharePrice = 0;
        }
        return $sharePrice;
    }

    /**
     * 查询商品的一级分类
     * @param $price 销售价
     * @param $price 成本价
     * @return array
     * @throws \think\Exception
     */
    public function getParentCate($categoryID) {
        $this_cate = Db::name('ali_category')->where('id', $categoryID)->find();
        if ($this_cate['pid'] != 0) {
            $this_cate = $this->getParentCate($this_cate['pid']);
        }
        return $this_cate;
    }

    /**
     * 查询商品在商城中的溢价比
     * @param $price 销售价
     * @param $price 成本价
     * @return array
     * @throws \think\Exception
     */
    public function getRate($categoryID) {
        $rate = $this->getCateRate($categoryID)['rate'];
        if ($rate == 0) {
            $rate = Db::name('config_new')->where('name', 'ali_rate')->value('info');
        }
        return $rate;
    }

    /**
     * 查询商品在商城中的溢价比,先查最低级分类,向上查询,碰到有溢价比的分类就返回
     * @param $price 销售价
     * @param $price 成本价
     * @return array
     * @throws \think\Exception
     */
    public function getCateRate($categoryID) {
        $this_cate = Db::name('ali_category')->where('id', $categoryID)->find();
        if ($this_cate['pid'] != 0 && $this_cate['rate'] == 0) {
            $this_cate = $this->getCateRate($this_cate['pid']);
        }
        return $this_cate;
    }

    /**
     * 查询商品在供货商中的溢价比
     * @param $price 销售价
     * @param $price 成本价
     * @return array
     * @throws \think\Exception
     */
    public function getGhsRate($categoryID) {
        $rate = $this->getCateGhsRate($categoryID)['ghs_rate'];
        if ($rate == 0) {
            $rate = Db::name('config_new')->where('name', 'ali_ghs_rate')->value('info');
        }
        return $rate;
    }

    /**
     * 查询商品在供货商中的溢价比,先查最低级分类,向上查询,碰到有溢价比的分类就返回
     * @param $price 销售价
     * @param $price 成本价
     * @return array
     * @throws \think\Exception
     */
    public function getCateGhsRate($categoryID) {
        $this_cate = Db::name('ali_category')->where('id', $categoryID)->find();
        if ($this_cate['pid'] != 0 && $this_cate['ghs_rate'] == 0) {
            $this_cate = $this->getCateGhsRate($this_cate['pid']);
        }
        return $this_cate;
    }

    /**
     * 订单预览
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function preview4CybMedia($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_PREVIEW_CYB_MEDIA);
        // 参数处理
        $data = [
            'addressParam' => json_encode($params['addressParam']),
            'cargoParamList' => json_encode($params['cargoParamList']),
            '_aop_datePattern' => $this->_aop_datePattern
        ];

        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 订单 下单
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function createOrder4CybMedia($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_CREATE_ORDER_CYB_MEIDA);
        // 参数处理
        $data = [
            'addressParam' => json_encode($params['addressParam']),
            'cargoParamList' => json_encode($params['cargoParamList']),
            'outerOrderInfo' => json_encode($params['outerOrderInfo']),
            'message' => '需要支持无痕发货,如有问题请联系微信:LLWF7676',
        ];
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 商品推荐接口
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function mediaUserRecommendOfferService($mobile, $pageNo, $pageSize = 4) {
        $this->setNamespace(Request::NAMESPACE_P4P);
        $this->setName(Request::NAME_P4P_MEDIA_USER_RECOMMEND_OFFER_SERVICE);
        $data = [
            'deviceIdMd5' => $mobile,
            'pageNo' => $pageNo,
            'pageSize' => $pageSize
        ];
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 关注商品接口
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function productFollow($productId) {
        $this->setNamespace(Request::NAMESPACE_PRODUCT);
        $this->setName(Request::NAME_PRODUCT_FOLLOW);
        $data = [
            'productId' => $productId,
        ];
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 取消关注商品接口
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function productUnfollow($productId) {
        $this->setNamespace(Request::NAMESPACE_PRODUCT);
        $this->setName(Request::NAME_PRODUCT_UNFOLLOW_CROSSBORDER);
        $data = [
            'productId' => $productId,
        ];
        $res = $this->result($data);
        return $this->Response($res);
    }


    /**
     * 商品的列表价格,详情价格
     * @param $params
     * @return float
     * @throws \think\Exception
     */
    public function getListPrice($offerId, $isGhs) {
        $price_info = Db::name('ali_price_info')->where('feedId', $offerId)->find();
        if (empty($price_info)) {
            //将详情缓存
            $info = $this->productInfo(['offerId' => $offerId]);
            Cache::set("aliproduct_$offerId", json_encode($info), 3600);
            //将活动信息缓存
            $activity = $this->activity(['offerId' => $offerId]);
            $startTime = $this->getAliTime($activity['result']['result']['startTime']);
            $endTime = $this->getAliTime($activity['result']['result']['endTime']);
            Cache::set("activity_$offerId", json_encode($activity), 3600);
            $rate = $this->getRateV2($info['productInfo']['categoryID'], $isGhs);
            $acNew = [];
            if (!empty($activity['result']['result']['promotionItemList'])) {
                foreach ($activity['result']['result']['promotionItemList'] as $v) {
                    if ($v['skuId']) {
                        $acNew['skuid_' . $v['skuId']] = $v['promotionPrice'] / 100;
                    }
                }
            }
            $retailprice_old = 99999999;
            if (isset($info["productInfo"]['skuInfos'])) {
                $consignPriceMin = $promotionPriceMin = $channelPriceMin = 0;
                foreach ($info["productInfo"]['skuInfos'] as &$vv) {
                    $channelPrice = $vv['channelPrice'];
                    $promotionPrice = $acNew['skuid_' . $vv['skuId']];
                    $consignPrice = $vv['consignPrice'];
                    $retailprice_old_sku = $consignPrice;
                    if ($promotionPrice && time() > $startTime && time() < $endTime) {
                        $retailprice_old_sku = $promotionPrice;
                    }
                    if ($channelPrice) {
                        $retailprice_old_sku = $channelPrice;
                    }
                    if ($retailprice_old_sku < $retailprice_old) {
                        $retailprice_old = $retailprice_old_sku;
                        $retailprice = round($retailprice_old * (100 + $rate) / 100, 2);
                        $consignPriceMin = $consignPrice;
                        $promotionPriceMin = $promotionPrice;
                        $channelPriceMin = $channelPrice;
                    }
                }
                unset($vv);
            } else {
                $consignPriceMin = $retailprice_old = $info["productInfo"]["saleInfo"]["consignPrice"];
                if ($activity['result']['result']['promotionItemList'][0]["promotionPrice"] && time() > $startTime && time() < $endTime) {
                    $promotionPriceMin = $retailprice_old = $activity['result']['result']['promotionItemList'][0]["promotionPrice"] / 100;
                }
                if ($info["productInfo"]["saleInfo"]["channelPrice"]) {
                    $channelPriceMin = $retailprice_old = $info["productInfo"]["saleInfo"]["channelPrice"];
                }
                $retailprice = round($retailprice_old * (100 + $rate) / 100, 2);
            }
            if ($channelPriceMin > 0 || $promotionPriceMin > 0 || $consignPriceMin > 0) {
                Db::name('ali_price_info')->insert([
                    'feedId' => $offerId,
                    'channelPrice' => $channelPriceMin,
                    'promotionPrice' => $promotionPriceMin,
                    'consignPrice' => $consignPriceMin,
                    'categoryID' => $info['productInfo']['categoryID'],
                    'startTime' => $activity['result']['result']['startTime'],
                    'endTime' => $activity['result']['result']['endTime'],
                ]);
                $this->followThis($offerId, $info['productInfo']['categoryID'], $retailprice_old);
            }
        } else {
            $rate = $this->getRateV2($price_info['categoryID'], $isGhs);
            $price = $price_info['consignPrice'];
            $startTime = $this->getAliTime($price_info["startTime"]);
            $endTime = $this->getAliTime($price_info["endTime"]);
            if ($price_info["promotionPrice"] && time() > $startTime && time() < $endTime) {
                $price = $price_info['promotionPrice'];
            }
            if ($price_info["channelPrice"]) {
                $price = $price_info['channelPrice'];
            }
            $retailprice = round($price * (100 + $rate) / 100, 2);
        }
        return $retailprice;
    }

    /**
     * 关注商品接口并记录到数据库
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    function followThis($offerId, $categoryId, $price) {
        $count = Db::name('ali_product_quick_search')->where('feedId', $offerId)->count();
        if (!$count) {
            $follow = $this->productFollow($offerId);
            if ($follow['message'] == "success") {
                Db::name('ali_product_quick_search')->insert(['feedId' => $offerId, 'categoryId' => $categoryId, 'price' => $price]);
            }
        }
    }

    /**
     * 将阿里的时间转换为时间戳
     * @param string $time
     * @return false|int|null
     */
    public function getAliTime($time = '20251125234641000+0800') {
        if ($time) {
            $time = substr($time, 0, 14);
            $y = substr($time, 0, 4);
            if ((int)$y > 2036) {
                $y = 2036;
            }
            $m = substr($time, 4, 2);
            $d = substr($time, 6, 2);
            $h = substr($time, 8, 2);
            $i = substr($time, 10, 2);
            $s = substr($time, 12, 2);
            $time = mktime($h, $i, $s, $m, $d, $y);
            return $time;
        } else {
            return null;
        }
    }

    /**
     * 订单详情
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function buyerView($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_ALIBABA_TRADE_GET_BUYERVIEW);
        // 参数处理
        $data = array_filter(array_merge([
            'webSite' => isset($params['webSite']) ? $params['webSite'] : 1688,
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null,
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 阿里订单代扣
     * @param $orderId
     */
    public function protocolPay($params) {
        if (config('protocol_pay')) {
            return 1;
        }
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_ALIBABA_TRADE_PAY_PROTOCOLPAY);
        // 参数处理
        $data = array_filter(array_merge([
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null,
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    public function _protocolPay_($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_ALIBABA_TRADE_PAY_PROTOCOLPAY);
        // 参数处理
        $data = array_filter(array_merge([
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null,
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    /**
     * 阿里取消订单
     * @param $orderId
     */
    public function cancel($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRDE_ALIBABA_TRADE_CANCEL);
        // 参数处理
        $data = array_filter(array_merge([
            'webSite' => isset($params['webSite']) ? $params['webSite'] : 1688,
            'tradeID' => isset($params['tradeID']) ? $params['tradeID'] : null,
            'cancelReason' => isset($params['cancelReason']) ? $params['cancelReason'] : 'buyerCancel',
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    //获取交易订单的物流信息(买家视角)
    public function getLogisticsInfos($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_LOGISTICS);
        $this->setName(Request::NAME_LOGISTICS_ALIBABA_TRADE_GETLOGISTICSINFOS_BUYERVIEW);

        // 参数处理
        $data = array_filter(array_merge([
            'webSite' => isset($params['webSite']) ? $params['webSite'] : 1688,
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    //获取交易订单的物流信息(买家视角)
    public function getLogisticsTraceInfo($params) {
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_LOGISTICS);
        $this->setName(Request::NAME_LOGISTICS_ALIBABA_TRADE_GETLOGISTICSTRACEINFO_BUYERVIEW);

        // 参数处理
        $data = array_filter(array_merge([
            'webSite' => isset($params['webSite']) ? $params['webSite'] : 1688,
            'orderId' => isset($params['orderId']) ? $params['orderId'] : null
        ], $params));
        $res = $this->result($data);
        return $this->Response($res);
    }

    //阿里商品退款
    public function createRefund($orderId) {
        return (new AliProductController())->createRefund($orderId);
        $RefundReason = $this->getRefundReasonList($orderId);
        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_ALIBABA_TRADE_CREATEREFUND);

        // 参数处理
        $data = [
            'orderId' => $orderId,
            'orderEntryIds' => json_encode([$orderId]),
            'disputeRequest' => 'refund',
            'applyPayment' => $RefundReason['applyPayment'], //（单位：分）
            'applyCarriage' => $RefundReason['applyCarriage'], //（单位：分）
            'applyReasonId' => $RefundReason['data']['id'],
            'description' => $RefundReason['data']['name'],
            'goodsStatus' => $RefundReason['goodsStatus']
        ];
        $res = $this->result($data);
        return $this->Response($res);
        //返回
        // "{\"result\":{\"result\":{\"refundId\":\"TQ64694850563892856\"},\"success\":true}}"
    }

    //阿里商品退款原因
    public function getRefundReasonList($orderId) {
        //获取订单状态
        $buyerView = $this->buyerView(['orderId' => $orderId]);
        $amount = ($buyerView['result']['baseInfo']['totalAmount'] - $buyerView['result']['baseInfo']['shippingFee']) * 100;
        $shippingFee = $buyerView['result']['baseInfo']['shippingFee'] * 100;
        switch ($buyerView['result']['productItems'][0]['status']) {
            case 'waitsellersend':
                $goodsStatus = 'refundWaitSellerSend';
                break;
            case 'waitbuyerreceive':
                $goodsStatus = 'refundWaitBuyerReceive';
                break;
        }

        // 命名空间名和接口名
        $this->setNamespace(Request::NAMESPACE_TRADE);
        $this->setName(Request::NAME_TRADE_ALIBABA_TRADE_GETREFUNDREASONLIST);

        // 参数处理
        $data = [
            'orderId' => $orderId,
            'orderEntryIds' => json_encode([$orderId]),
            'goodsStatus' => $goodsStatus
        ];
        $res = $this->result($data);
        $Response = $this->Response($res);
        return [
            'data' => $Response['result']['result']['reasons'][0],
            'goodsStatus' => $goodsStatus,
            'applyPayment' => $amount,
            'applyCarriage' => $shippingFee
        ];
    }

    /**
     * 商品的列表价格,详情价格
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function getSpecPrice($offerId, $specId, $isGhs = 0) {
        //将详情缓存
        if (Cache::get("aliproduct_$offerId") && !empty(Cache::get("aliproduct_$offerId"))) {
            $info = json_decode(Cache::get("aliproduct_$offerId"), true);
        } else {
            $info = $this->productInfo(['offerId' => $offerId]);
            Cache::set("aliproduct_$offerId", json_encode($info), 3600);
        }
        //将活动信息缓存
        if (Cache::get("activity_$offerId") && !empty(Cache::get("activity_$offerId"))) {
            $activity = json_decode(Cache::get("activity_$offerId"), true);
        } else {
            $activity = $this->activity(['offerId' => $offerId]);
            Cache::set("activity_$offerId", json_encode($activity), 3600);
        }
        $startTime = $this->getAliTime($activity['result']['result']['startTime']);
        $endTime = $this->getAliTime($activity['result']['result']['endTime']);
        $rate = $this->getRateV2($info['productInfo']['categoryID'], $isGhs);
        $acNew = [];
        if (!empty($activity['result']['result']['promotionItemList'])) {
            foreach ($activity['result']['result']['promotionItemList'] as $v) {
                if ($v['skuId']) {
                    $acNew['skuid_' . $v['skuId']] = $v['promotionPrice'] / 100;
                }
            }
        }
        if (empty($info["productInfo"]['skuInfos']) && !empty($specId)) {
            return false;
        }
        if ($specId) {
            foreach ($info["productInfo"]['skuInfos'] as &$vv) {
                if ($vv['specId'] == $specId) {
                    $channelPrice = $vv['channelPrice'];
                    $promotionPrice = $acNew['skuid_' . $vv['skuId']];
                    $consignPrice = $vv['consignPrice'];
                    $retailprice_old_sku = $consignPrice;
                    if ($promotionPrice && time() > $startTime && time() < $endTime) {
                        $retailprice_old_sku = $promotionPrice;
                    }
                    if ($channelPrice) {
                        $retailprice_old_sku = $channelPrice;
                    }
                    $retailprice_old = $retailprice_old_sku;
                    $retailprice = round($retailprice_old * (100 + $rate) / 100, 2);
                }
            }
            unset($vv);
        } else {
            $retailprice_old = $info["productInfo"]["saleInfo"]["consignPrice"];
            if ($activity['result']['result']['promotionItemList'][0]["promotionPrice"] && time() > $startTime && time() < $endTime) {
                $retailprice_old = $activity['result']['result']['promotionItemList'][0]["promotionPrice"] / 100;
            }
            if ($info["productInfo"]["saleInfo"]["channelPrice"]) {
                $retailprice_old = $info["productInfo"]["saleInfo"]["channelPrice"];
            }
            $retailprice = round($retailprice_old * (100 + $rate) / 100, 2);
        }
        return $retailprice;
    }
}
