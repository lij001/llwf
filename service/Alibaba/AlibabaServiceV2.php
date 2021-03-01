<?php


namespace app\api\service\Alibaba;


use app\api\controller\QnyController;
use app\api\model\AliProductV2;
use app\api\model\AliProductV2Attr;
use app\api\service\HotService;
use app\api\service\RedisServer;
use app\api\service\Seckill\SeckillGoodsServer;
use app\api\service\TokenService;
use think\Cache;
use think\Db;
use think\Exception;

class AlibabaServiceV2 extends AlibabaService {

    /**
     * 自动拉取商品写入
     * @param $alibaba
     * @param int $page
     * @param int $pageSize
     * @return false|float
     */
    protected function autoPullProductInsertV2($page = 1, $type = 1, $province = null) {
        Db::name('ali_log')->insert(['feedId' => '000' . $page, 'desc' => '页数']);
        $pageSize = 20;
        //调用列表查询
        switch ($type) {
            case 2:
                $param = [
                    'page' => $page,
                    'postCategoryId' => 0,
                    'offerTags' => '1387842',
                    'province' => $province,
                    'pageSize' => $pageSize
                ];
                break;
            case 1:
            default:
                $param = [
                    'page' => $page,
                    'postCategoryId' => 0,
                    'tags' => '271938',
                    'province' => $province,
                    'pageSize' => $pageSize
                ];
                break;
        }
        $PricedOffer = $this->PricedOffer($param);
        if (!empty($PricedOffer['result']['result'])) {
            foreach ($PricedOffer['result']['result'] as $v) {
                $count = Db::name('ali_product_v2')->where('feedId', $v['offerId'])->count();
                if (!$count) {
                    Db::name('ali_log')->insert(['feedId' => $v['offerId'], 'desc' => '写入']);
                    $this->insertProductV2($v['offerId']);
                }
            }
        }
        return ceil($PricedOffer['result']['totalCount'] / $pageSize);
    }

    /**
     * 自动拉取商品入口
     */
    public function autoPullProductV2($type = 1, $province = null) {
        for ($i = 1; $i <= 150; $i++) {
            $this->autoPullProductInsertV2($i, $type, $province);
        }
        return true;
    }

    /**
     * 根据选品库拉取
     * @param $groupId
     * @param int $page
     * @return bool
     * @throws \think\Exception
     */
    public function saveProductByGroup($groupId, $page = 1, $i = 1, $froce = 0) {
        $alipro = new AliProductV2();
        $pageSize = 50;
        $plist = $this->listCybUserGroupFeed([
            'groupId' => $groupId,
            'pageNo' => $page,
            'pageSize' => $pageSize
        ]);
        foreach ($plist['result']['resultList'] as $v) {
            $i++;
            $find = $alipro->where(['feedId' => $v['feedId'], 'groupId' => $groupId])->find();
            if (empty($find) || $froce) {
                Db::name('ali_log')->insert(['feedId' => $v['feedId'], 'desc' => '写入(选品:库' . $groupId . ')']);
                $this->insertProductV2($v['feedId'], $groupId);
            }
        }
        $page++;
        if ($i < $plist['result']['totalRow']) {
            return $this->saveProductByGroup($groupId, $page, $i, $froce);
        }
        return true;
    }

    /**
     * 根据选品库拉取
     * @param $groupId
     * @param int $page
     * @return bool
     * @throws \think\Exception
     */
    public function saveProductByGroup2($groupId, $page = 1, $i = 1) {
        $alipro = new AliProductV2();
        $pageSize = 50;
        $plist = $this->listCybUserGroupFeed([
            'groupId' => $groupId,
            'pageNo' => $page,
            'pageSize' => $pageSize
        ]);
        foreach ($plist['result']['resultList'] as $v) {
            dump($i);
            $i++;
            $find = $alipro->where(['feedId' => $v['feedId'], 'groupId' => $groupId])->find();
            if (empty($find)) {
                Db::name('ali_log')->insert(['feedId' => $v['feedId'], 'desc' => '写入(选品:库' . $groupId . ')']);
                $this->insertProductV2($v['feedId'], $groupId);
            }
        }
        $page++;
        if ($i < $plist['result']['totalRow']) {
            sleep(2);
            return $this->saveProductByGroup2($groupId, $page, $i);
        }
        return true;
    }

    /**
     * 商品入库
     * @param $feedId
     * @throws \think\Exception
     */
    public function insertProductV2($feedId, $groupId = 0, $isRecom = 0) {
        $count = Db::name('ali_blacklist')->where('feedId', $feedId)->count();
        if ($count) return false;
        $info = $this->productInfo(['offerId' => $feedId]);
        try {
            if ($info['success']) {
                $activity = $this->activity(['offerId' => $feedId]);
                Db::startTrans();
                $this->insertInfoV2($feedId, $info, $groupId, $isRecom);
                $this->insertAttrV2($feedId, $info, $activity);
                Db::commit();
                $this->productFollow($feedId);
            } else {
                (new AliProductV2())->where('feedId', $feedId)->update([
                    'invalid' => 'delete'
                ]);
                Db::name('ali_log')->insert(['feedId' => $feedId, 'desc' => '无法获取商品信息']);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('ali_log')->insert(['feedId' => $feedId, 'desc' => '写入失败']);
            Db::name('error_log')->insert([
                'source' => 'AlibabaService2/insertProductV2',
                'desc' => $e->getMessage(),
                'uid' => 0,
                'order' => $feedId,
                'date' => date('Y-m-d H:i:s')
            ]);
            return false;
        }
    }

    /**
     * 写入商品信息
     * @param $feedId
     * @param $info
     * @param $activity
     * @throws \think\Exception
     */
    public function insertInfoV2($feedId, $info, $groupId = 0, $isRecom = 0) {
        if (empty($feedId) || empty($info)) {
            throw new Exception('缺少参数');
        }
        $alipro = new AliProductV2();
        $find = $alipro->where('feedId', $feedId)->find();
        if ($find) {
            if ($groupId == 0 && $find['groupId'] != 0) {
                $groupId = $find['groupId'];
            }
            $alipro->where('feedId', $feedId)->update([
                'groupId' => $groupId,
                'isRecom' => $isRecom,
                'cateId' => $info['productInfo']['categoryID'],
                'title' => $info['productInfo']['intelligentInfo']['title'],
                'thumb' => $info['productInfo']['intelligentInfo']['images'][0],
                'images' => json_encode($info['productInfo']['intelligentInfo']['images'], JSON_UNESCAPED_UNICODE),
                'desc' => json_encode($info['productInfo']['intelligentInfo']['descriptionImages'], JSON_UNESCAPED_UNICODE),
                'video' => $info['productInfo']['mainVedio'],
                'send_address' => $info['productInfo']['shippingInfo']['sendGoodsAddressText'],
                'free_postage' => $info['productInfo']['shippingInfo']['channelPriceFreePostage'] ? $info['productInfo']['shippingInfo']['channelPriceFreePostage'] : 0,
                'exclude_area' => $info['productInfo']['shippingInfo']['channelPriceExcludeAreaCodes'] ? json_encode(array_column($info['productInfo']['shippingInfo']['channelPriceExcludeAreaCodes'], 'name'), JSON_UNESCAPED_UNICODE) : '无',
                'min_quantity' => $info['productInfo']['saleInfo']['minOrderQuantity'],
                'unit' => $info['productInfo']['saleInfo']['unit'],
                'update_time' => time()
            ]);
        } else {
            $alipro->insert([
                'groupId' => $groupId,
                'isRecom' => $isRecom,
                'feedId' => $feedId,
                'cateId' => $info['productInfo']['categoryID'],
                'title' => $info['productInfo']['intelligentInfo']['title'],
                'thumb' => $info['productInfo']['intelligentInfo']['images'][0],
                'images' => json_encode($info['productInfo']['intelligentInfo']['images'], JSON_UNESCAPED_UNICODE),
                'desc' => json_encode($info['productInfo']['intelligentInfo']['descriptionImages'], JSON_UNESCAPED_UNICODE),
                'video' => $info['productInfo']['mainVedio'],
                'send_address' => $info['productInfo']['shippingInfo']['sendGoodsAddressText'],
                'free_postage' => $info['productInfo']['shippingInfo']['channelPriceFreePostage'] ? $info['productInfo']['shippingInfo']['channelPriceFreePostage'] : 0,
                'exclude_area' => $info['productInfo']['shippingInfo']['channelPriceExcludeAreaCodes'] ? json_encode(array_column($info['productInfo']['shippingInfo']['channelPriceExcludeAreaCodes'], 'name'), JSON_UNESCAPED_UNICODE) : '无',
                'min_quantity' => $info['productInfo']['saleInfo']['minOrderQuantity'],
                'unit' => $info['productInfo']['saleInfo']['unit'],
                'insert_time' => time(),
                'update_time' => time()
            ]);
        }
    }

    /**
     * 写入商品规格
     * @param $feedId
     * @param $info
     * @param $activity
     * @throws \think\Exception\
     */
    public function insertAttrV2($feedId, $info, $activity) {
        $aliAttr = new AliProductV2Attr();
        if (!empty($info['productInfo']['skuInfos'])) {
            if (!empty($info['productInfo']['intelligentInfo']['skuImages'])) {
                $skuImages = [];
                foreach ($info['productInfo']['intelligentInfo']['skuImages'] as $imgs) {
                    $skuImages['A' . $imgs['skuId']] = $imgs;
                }
            }
            if (!empty($activity)) {
                $promotionItemList = [];
                foreach ($activity['result']['result']['promotionItemList'] as $act) {
                    $promotionItemList['A' . $act['skuId']] = $act;
                }
            }
            foreach ($info['productInfo']['skuInfos'] as $v) {
                $title = '';
                foreach ($v['attributes'] as $vv) {
                    $title .= $vv['attributeName'] . ':' . $vv['attributeValue'];
                }
                $count = $aliAttr->where(['feedId' => $feedId, 'specId' => $v['specId']])->count();
                if ($count) {
                    $aliAttr->where(['feedId' => $feedId, 'specId' => $v['specId']])->update([
                        'cateId' => $info['productInfo']['categoryID'],
                        'title' => $title,
                        'img' => $skuImages['A' . $v['skuId']]["imageUrl"] ? $skuImages['A' . $v['skuId']]["imageUrl"] : $info['productInfo']['intelligentInfo']['images'][0],
                        'amountOnSale' => $v['amountOnSale'],
                        'channelPrice' => $v['channelPrice'] ? $v['channelPrice'] : 0,
                        'consignPrice' => $v['consignPrice'] ? $v['consignPrice'] : 0,
                        'promotionPrice' => $promotionItemList ? $promotionItemList['A' . $v['skuId']]['promotionPrice'] / 100 : 0,
                        'originalPrice' => $promotionItemList ? $promotionItemList['A' . $v['skuId']]['originalPrice'] / 100 : 0,
                        'attributes' => json_encode($v['attributes'], JSON_UNESCAPED_UNICODE),
                        'start_time' => $this->getAliTime($activity['result']['result']['startTime']) ? $this->getAliTime($activity['result']['result']['startTime']) : 0,
                        'end_time' => $this->getAliTime($activity['result']['result']['endTime']) ? $this->getAliTime($activity['result']['result']['endTime']) : 0,
                    ]);
                } else {
                    $aliAttr->insert([
                        'feedId' => $feedId,
                        'cateId' => $info['productInfo']['categoryID'],
                        'specId' => $v['specId'],
                        'skuId' => $v['skuId'],
                        'title' => $title,
                        'img' => $skuImages['A' . $v['skuId']]["imageUrl"] ? $skuImages['A' . $v['skuId']]["imageUrl"] : $info['productInfo']['intelligentInfo']['images'][0],
                        'amountOnSale' => $v['amountOnSale'],
                        'channelPrice' => $v['channelPrice'] ? $v['channelPrice'] : 0,
                        'consignPrice' => $v['consignPrice'] ? $v['consignPrice'] : 0,
                        'promotionPrice' => $promotionItemList ? $promotionItemList['A' . $v['skuId']]['promotionPrice'] / 100 : 0,
                        'originalPrice' => $promotionItemList ? $promotionItemList['A' . $v['skuId']]['originalPrice'] / 100 : 0,
                        'attributes' => json_encode($v['attributes'], JSON_UNESCAPED_UNICODE),
                        'start_time' => $this->getAliTime($activity['result']['result']['startTime']) ? $this->getAliTime($activity['result']['result']['startTime']) : 0,
                        'end_time' => $this->getAliTime($activity['result']['result']['endTime']) ? $this->getAliTime($activity['result']['result']['endTime']) : 0,
                    ]);
                }
            }
        } else {
            $count = $aliAttr->where(['feedId' => $feedId, 'specId' => 0])->count();
            if ($count) {
                $aliAttr->where(['feedId' => $feedId, 'specId' => 0])->update([
                    'cateId' => $info['productInfo']['categoryID'],
                    'title' => '默认',
                    'img' => $info['productInfo']['intelligentInfo']['images'][0],
                    'amountOnSale' => $info['productInfo']['saleInfo']['amountOnSale'],
                    'channelPrice' => $info['productInfo']['saleInfo']['channelPrice'] ? $info['productInfo']['saleInfo']['channelPrice'] : 0,
                    'consignPrice' => $info['productInfo']['saleInfo']['consignPrice'] ? $info['productInfo']['saleInfo']['consignPrice'] : 0,
                    'promotionPrice' => 0,
                    'originalPrice' => 0,
                    'attributes' => json_encode([
                        [
                            "attributeID" => 3000,
                            "attributeValue" => "默认",
                            "skuImageUrl" => $info['productInfo']['intelligentInfo']['images'][0],
                            "attributeName" => "规格"
                        ]
                    ]),
                    'start_time' => 0,
                    'end_time' => 0,
                ]);
            } else {
                $aliAttr->insert([
                    'feedId' => $feedId,
                    'cateId' => $info['productInfo']['categoryID'],
                    'specId' => 0,
                    'skuId' => 0,
                    'title' => '默认',
                    'img' => $info['productInfo']['intelligentInfo']['images'][0],
                    'amountOnSale' => $info['productInfo']['saleInfo']['amountOnSale'],
                    'channelPrice' => $info['productInfo']['saleInfo']['channelPrice'] ? $info['productInfo']['saleInfo']['channelPrice'] : 0,
                    'consignPrice' => $info['productInfo']['saleInfo']['consignPrice'] ? $info['productInfo']['saleInfo']['consignPrice'] : 0,
                    'promotionPrice' => 0,
                    'originalPrice' => 0,
                    'attributes' => json_encode([
                        [
                            "attributeID" => 3000,
                            "attributeValue" => "默认",
                            "skuImageUrl" => $info['productInfo']['intelligentInfo']['images'][0],
                            "attributeName" => "规格"
                        ]
                    ]),
                    'start_time' => 0,
                    'end_time' => 0,
                ]);
            }
        }
    }

    /**
     * 商品详情V2
     * @param $feedId
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getProductV2($feedId, $isGhs = 0, $isXL = 0, $uid = 0) {
        //$alipro->where('feedId', $feedId)->setInc('hot', 1);
        $key = 'aliGoods' . $feedId . $uid;
        $infoNew = Cache::get($key);
        if (!$infoNew) {
            $infoNew = $this->getProductV2Cached($feedId, $isGhs, $isXL, $uid);
            Cache::set($key, json_encode($infoNew, JSON_UNESCAPED_UNICODE), 3600);
        } else {
            $infoNew = json_decode($infoNew, 1);
        }
        if ($uid) {
            $infoNew['favorite'] = Db::name('ali_favorite')->where(['feedId' => $feedId, 'uid' => $uid])->count();
        } else {
            $infoNew['favorite'] = 0;
        }
        HotService::objectInit()->aliProAddHot($feedId);
        SeckillGoodsServer::goodsInfo($infoNew, $feedId);
        return $infoNew;
    }

    protected function getProductV2Cached($feedId, $isGhs = 0, $isXL = 0, $uid = 0) {
        $alipro = new AliProductV2();
        $count = $alipro->where('feedId', $feedId)->count();
        $count2 = $alipro->attrs()->where('feedId', $feedId)->count();
        if (!$count || !$count2) {
            $res = $this->insertProductV2($feedId);
            if (!$res) {
                return false;
            }
        }
        $info = $alipro->where('feedId', $feedId)->find();
        if ($info === null) return false;
        $attr = $alipro->attrs()->where('feedId', $feedId)->select();
        $infoNew = [];
        $infoNew['productInfo'] = [
            'productID' => $feedId,
            'categoryID' => $info['cateId'],
            'subject' => $info['title2'] ? $info['title2'] : $info['title'],
            'image' => ['images' => json_decode($info['images'], true)],
            'description' => json_decode($info['desc'], true),
            'saleInfo' => [
                'unit' => $info['unit'],//单位
                'minOrderQuantity' => 1,//最低起批量
            ],
            'skuInfos' => [

            ],
            'intelligentInfo' => [
                'title' => $info['title'],
                'images' => json_decode($info['images'], true),
                'descriptionImages' => json_decode($info['desc'], true)
            ],
            'mainVedio' => $info['video'],
        ];
        $infoNew['sale_num'] = $info['sale_num'];
        foreach ($attr as $v) {
            $infoNew['productInfo']['saleInfo']['amountOnSale'] += $v['amountOnSale'];
            $attributes['attributes'] = json_decode($v['attributes'], true);
            $attributes['amountOnSale'] = $v['amountOnSale'];
            $attributes['skuId'] = $v['skuId'];
            $attributes['specId'] = $v['specId'];
            $attributes['cpsSuggestPrice'] = $v['sale_price'];
            $infoNew['productInfo']['skuInfos'][] = $attributes;
        }
        $costPrice = $this->getProductListPriceV2($feedId, 0, 1);
        $newPrice = $this->getProductListPriceV2($feedId);
        $infoNew['newPrice'] = $newPrice;
        $infoNew['gxz'] = $infoNew['newPrice'];
        $infoNew['og_price'] = $info['og_price'];
        $infoNew['sharePrice'] = $this->getThisSharePrice($newPrice, $costPrice);
        $infoNew['send_address'] = $info['send_address'];
        $infoNew['free_postage'] = $info['free_postage'];
        $infoNew['invalid'] = $info['invalid'] == 'false' ? false : true;
        if ($costPrice < 5) {
            $infoNew['productInfo']['saleInfo']['minOrderQuantity'] = $info['min_quantity'];
        }
        if ($isXL) {
            $infoNew['llj'] = getXiaolianPrice($newPrice, $costPrice);
        }
        if ($uid) {
            $infoNew['favorite'] = Db::name('ali_favorite')->where(['feedId' => $feedId, 'uid' => $uid])->count();
        } else {
            $infoNew['favorite'] = 0;
        }
        return $infoNew;
    }

    /**
     * 获取V2商品的列表价格
     * @param $feedId
     * @return float|int
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getProductListPriceV2($feedId, $isGhs = 0, $isCost = 0) {
        $aliAttr = new AliProductV2Attr();
        $attrInfo = $aliAttr->where('feedId', $feedId)->order('consignPrice')->find();
        if ($isCost) {
            $listPrice = $attrInfo['consignPrice'];
        } else {
            $listPrice = $attrInfo['sale_price'];
        }
        return $listPrice;
    }

    /**
     * 获取V2商品的列表价格
     * @param $feedId
     * @return float|int
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getProductListOgPriceV2($feedId, $listPrice = 0) {
        $alipro = new AliProductV2();
        $og_price = $alipro->where('feedId', $feedId)->value('og_price');
        if ($listPrice == 0) {
            $attrInfo = $alipro->attrs()->where('feedId', $feedId)->order('consignPrice')->find();
            $listPrice = $attrInfo['sale_price'];
        }
        if ($og_price == 0 || $og_price < $listPrice) {
            $og_price = ceil($listPrice * 1.5);
        }
        return $og_price;
    }


    /**
     * 商品的列表价格,详情价格
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function getSpecPriceV2($offerId, $specId, $isGhs = 0, $isCost = 0) {
        $aliAttr = new AliProductV2Attr();
        $attrInfo = $aliAttr->where(['feedId' => $offerId, 'specId' => $specId])->find();
        if ($isCost) {
            return $attrInfo['consignPrice'];
        } else {
            return $attrInfo['sale_price'];
        }
    }

    /**
     * 获取费率
     * @param $categoryID
     * @param int $isGhs
     * @return array
     * @throws \think\Exception
     */
    public function getRateV2($categoryID, $isGhs = 0) {
        if (!$isGhs) {
            $rate = $this->getRate($categoryID);
        } else {
            $rate = $this->getGhsRate($categoryID);
        }
        return $rate;
    }

    /**
     * 商品列表搜索接口
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function PricedOfferV2($page = 1, $categoryId = 0, $keywords = '', $isGhs = 0, $isChannel = 0, $sortType = 'normal') {
        $alipro = new AliProductV2();
        $alipro2 = $alipro;
        if ($categoryId) {
            $cateIds = $this->getCateIds($categoryId);
            $where['cateId'] = ['in', $cateIds];
        }
        if ($keywords) {
            for ($i = 0; $i < mb_strlen($keywords); $i++) {
                $arr[] = mb_substr($keywords, $i, 1);
            }
            $keywords = implode('%', $arr);
            $where2 = "title like '%$keywords%' or title2 like '%$keywords%'";
        }
        if ($isChannel) {
            $where['free_postage'] = 1;
        }
        if (!empty($where)) {
            $alipro2 = $alipro2->where($where)->where('invalid', 'false');
        }
        if (!empty($where2)) {
            $alipro2 = $alipro2->where($where2)->where('invalid', 'false');
        }
        if ($sortType == 'normal') {
            $return = $alipro2->where('invalid', 'false')->order('hot desc,sale_num desc')->page($page, 10)->select()->toArray();
        } else {
            $return = $alipro2->where('invalid', 'false')->order('hot desc,id desc')->page($page, 10)->select()->toArray();
        }
        $productList = [];
        foreach ($return as $v) {
            $product = [
                "imgUrl" => $v['thumb'],
                "offerId" => $v['feedId'],
                "recommendTitle" => $v['title'],
                "title" => $v['title'],
                "currentPriceNew" => $this->getProductListPriceV2($v['feedId'], $isGhs),
                "recommendPrice" => $v['og_price'],
                "free_postage" => $v['free_postage']
            ];
            $product['gxz'] = $product['currentPriceNew'];
            if ($v['invalid'] == 'false') {
                $product['enable'] = true;
            } else {
                $product['enable'] = false;
            }
            $productList[] = $product;
        }
        return ['result' => ['success' => true, 'result' => $productList]];
    }

    /**
     * 商品列表搜索接口
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    function PricedOffer_old($page, $categoryId = 0, $keywords = '', $sortType = 'normal', $offerTags = null, $isGhs = 0, $header = null) {
        $alibaba = new AlibabaService();
        //列表查询
        if ($categoryId != 0) {
            //调用列表查询
            $param = [
                'page' => $page,
                'postCategoryId' => $categoryId,
                'keyWords' => $keywords,
                'sortType' => $sortType,
                'offerTags' => $offerTags
            ];
            $PricedOffer = $alibaba->PricedOffer($param);
            //处理商品信息
            foreach ($PricedOffer['result']['result'] as &$v) {
                $v['currentPriceNew'] = $alibaba->getListPrice($v['offerId'], $isGhs); //处理价格
                $v['recommendPrice'] = ceil($v['currentPriceNew'] * 1.5);
                $v['gxz'] = $v['currentPriceNew'];
                $alibaba->followThis($v['offerId'], $categoryId, $v['currentPrice']);
            }
            unset($v);
        } else {
            if ($keywords == '') {
                //获取用户信息
                if ($header) {
                    $uid = TokenService::getCurrentUid();
                    $mobile = Db::name('members')->where('id', $uid)->value('mobile');
                } else {
                    $mobile = 15278727177;
                }
                //调用商品推荐接口
                $PricedOffer = $alibaba->mediaUserRecommendOfferService(md5($mobile), $page);
                //处理商品信息
                foreach ($PricedOffer['result']['result'] as &$v) {
                    $offerId = $v['offerId'];
                    $v['currentPriceNew'] = $alibaba->getListPrice($offerId, $isGhs); //价格处理
                    $v['recommendPrice'] = ceil($v['currentPriceNew'] * 1.5);
                    $v['gxz'] = $v['currentPriceNew'];
                    //标签处理
                    $v['enable'] = true;
                    $v['title'] = $v['recommendTitle'];
                }
                unset($v);
            } else {
                //调用列表查询
                $param = [
                    'page' => $page,
                    'pageSize' => 8,
                    'postCategoryId' => $categoryId,
                    'keyWords' => $keywords,
                    'sortType' => $sortType,
                    'offerTags' => $offerTags
                ];
                $PricedOffer = $alibaba->PricedOffer($param);
                //处理商品信息
                foreach ($PricedOffer['result']['result'] as &$v) {
                    $v['currentPriceNew'] = $alibaba->getListPrice($v['offerId'], $isGhs); //处理价格
                    $v['recommendPrice'] = ceil($v['currentPriceNew'] * 1.5);
                    $v['gxz'] = $v['currentPriceNew'];
                    $alibaba->followThis($v['offerId'], $categoryId, $v['currentPrice']);
                }
                unset($v);
            }
        }
        return $PricedOffer;
    }

    /**
     * 供应链爆款专区
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    function PricedOffer_bk($page = 1, $keywords = '',$isGhs = 1) {
        $alipro = new AliProductV2();
        $where = [
            'invalid' => 'false',
            'groupId' => 9436,
        ];
        if ($keywords != '') {
            for ($i = 0; $i < mb_strlen($keywords); $i++) {
                $arr[] = mb_substr($keywords, $i, 1);
            }
            $keywords = implode('%', $arr);
            $where['title'] = ['like', "%$keywords%"];
        }
        $infos = $alipro->where($where)->order('hot desc,sale_num desc')->paginate(10)->toArray()['data'];
        $arr = [];
        foreach ($infos as $v) {
            $rate = $this->getRateV2($v['cateId'], $isGhs);
            $costPrice = $this->getProductListPriceV2($v['feedId'], 0, 1);
            $price = round($costPrice * (100 + $rate) / 100, 2);
            if ($v['title'] && $price > 0 && $v['thumb']) {
                $temp = [
                    "title" => $v['title'],
                    "recommendTitle" => $v['title'],
                    "imgUrl" => $v['thumb'],
                    "offerId" => $v['feedId'],
                    "recommendPrice" => $v['og_price'],
                    "currentPriceNew" => $price,
                    "gxz" => $price,
                    "enable" => true,
                ];
                $arr['result']['result'][] = $temp;
            }
        }
        return $arr;
    }

    /**
     * 获取父级分类
     * @param $cateId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getCateIds($cateId) {
        $cateIds2_3 = Db::name('ali_category')->field('id')->where('pid in ' . Db::name('ali_category')->field('id')->where('pid', $cateId)->buildSql())
            ->union(Db::name('ali_category')->field('id')->where('pid', $cateId)->buildSql(), true)
            ->select()->toArray();
        if (!empty($cateIds2_3)) {
            $cateIds = array_column($cateIds2_3, 'id');
        }
        $cateIds[] = (int)$cateId;
        return $cateIds;
    }

    /**
     * 小莲列表
     * @param $page
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function xiaolian($page, $start, $end) {
        $alipro = new AliProductV2();
        $recom = Db::name('goods_recommend2')->where(['status' => 1, 'type' => 2, 'section' => 'fl'])->order('list_order')->page($page, 10)->select()->toArray();
        $xlq_rate = Db::name('config_new')->where('name', 'xiaolianquan_rate')->value('info');
        $xlq_add_rate = Db::name('config_new')->where('name', 'xiaolianquan_add_rate')->value('info');
        $productList = [];
        foreach ($recom as $v) {
            $info = $alipro->where('feedId', $v['goods_id'])->find();
            if (!empty($info)) {
                $costPrice = $this->getProductListPriceV2($info['feedId'], 0, 1);
                $salePrice = ceil($costPrice * (100 + $this->getRateV2($info['cateId'], 0))) / 100;
                $temp = [
                    "goods_id" => $v['goods_id'],
                    "title" => $info['title'],
                    "thumb" => $info['thumb'],
                    "type" => $v['type'],
                    "status" => $v['status'],
                    "section" => $v['section'],
                    "cost_price" => $costPrice,
                    "sale_price" => $salePrice,
                    "gxz" => $salePrice,
                    "saleNum" => $info['sale_num']
                ];
                $temp['llj'] = ($salePrice - $salePrice * $xlq_add_rate / 100 - $costPrice) * $xlq_rate;
                $productList[] = $temp;
            } else {
                Db::name('goods_recommend2')->where('goods_id', $v['goods_id'])->update(['status' => 2]);
            }
        }
        return $productList;
    }

    /**
     * 分享赚接口V2
     * @param string $imgUrl
     * @param string $goods_id
     * @param string $uid
     * @return array|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function shareGoods($imgUrl = '', $goods_id = '', $uid = '', $d = '', $c = '') {
        $alipro = new AliProductV2();
        $alipro->where('feedId', $goods_id)->setInc('hot', 1);
        $number = Db::name('members')->where('id', $uid)->value('number');
        $goods = Db::name('ali_product_v2')->where('feedId', $goods_id)->find();
        $price = $this->getProductListPriceV2($goods_id);
        $title = $goods['title2'] ? $goods['title2'] : $goods['title'];
//        $sharePrice = $this->getThisSharePrice($goods_id);
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
        $goods_id = '100000' . $goods_id;
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
     * 新分享赚图片分享
     * @param string $imgUrl
     * @param string $goods_id
     * @param string $uid
     * @param string $param
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function shareGoodsNew($imgUrl = '', $goods_id = '', $uid = '', $param = '') {
        $alipro = new AliProductV2();
        $alipro->where('feedId', $goods_id)->setInc('hot', 1);
        $number = Db::name('members')->where('id', $uid)->value('number');
        $goods = Db::name('ali_product_v2')->where('feedId', $goods_id)->find();
        $price = $this->getProductListPriceV2($goods_id);
        $title = $goods['title2'] ? $goods['title2'] : $goods['title'];
        $dir = "upload/share_goods";
        $mkdir = iconv("UTF-8", "GBK", $dir);
        if (!file_exists($mkdir)) {
            mkdir($mkdir, 0777, true);
        }
        //下载商品图片
        $type = strFind($imgUrl, ['jpg', 'png', 'gif', 'jpeg']);
        $imgName = time(date('YmdHis') . $uid) . '.' . $type;
        $file = file_get_contents($imgUrl);
        file_put_contents('upload/share_goods/' . $imgName, $file);
        //下载的图片缩放
        $image_down = \think\Image::open('upload/share_goods/' . $imgName);
        $image_down->thumb(480, 400, \think\Image::THUMB_FIXED)->save('upload/share_goods/' . $number . '_' . $imgName);

        //商品二维码
        $goods_id = '100000' . $goods_id;
        $goods_file = shareGoodsNew($number, $goods_id, $param);
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
        $re_url = cmf_get_image_url('share_goods/' . $number . '_copy' . '_' . $imgName);

        $res = 'http://pro.liulianwangfan7676.com/' . QnyController::admin_upload_file('upload/share_goods/' . $number . '_copy' . '_' . $imgName, 'prollwf', $type);
        //删除二维码
        @unlink($goods_file);
        @unlink('upload/share_goods/' . $imgName);
        @unlink('upload/share_goods/' . $number . '_' . $imgName);
        @unlink('upload/share_goods/' . $number . '_copy' . '_' . $imgName);
        return $res;
    }

    /**
     * 获取商品单规格溢价
     * @param $feedId
     * @param $skuId
     * @param null $attr
     * @param int $isGhs
     * @param int $isCost
     * @return float|int
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAttrPrice($feedId, $skuId, $attr = null, $isGhs = 0, $isCost = 0) {
        $aliattrpro = new AliProductV2Attr();
        if ($attr == null) {
            $attr = $aliattrpro->where(['specId' => $skuId, 'feedId' => $feedId])->find();
        }
        if ($attr['consignPrice'] > 0) {
            $cpsSuggestPrice = $attr['consignPrice'];
        }
        if ($attr['promotionPrice'] > 0 && $attr['start_time'] >= time() && $attr['end_time'] < time()) {
            $cpsSuggestPrice = $attr['promotionPrice'];
        }
        if ($attr['channelPrice'] > 0) {
            $cpsSuggestPrice = $attr['channelPrice'];
        }
        if ($isCost) {
            return $cpsSuggestPrice;
        } else {
            return ceil($cpsSuggestPrice * (100 + $this->getRateV2($attr['cateId']))) / 100;
        }

    }

    /**
     * 获取订单成本价
     * @param $orderId
     * @return mixed
     * @throws \think\Exception
     */
    public function getPhasAmount($orderId, $freight = -1) {
        $buyerView = $this->buyerView(['orderId' => $orderId]);
        $totalAmount = $buyerView['result']['baseInfo']['totalAmount'];
//        if ($freight >= 0) {
//            $totalAmount = $buyerView['result']['baseInfo']['totalAmount'] - $buyerView['result']['baseInfo']['shippingFee'] + $freight;
//        }
        return $totalAmount;
    }

    public function createOrder($mid, $address_id) {

    }

    /**
     * 商品回调
     * @param $params
     * @return array
     * @throws \think\Exception
     */
    public function calkBack() {
        $redis = RedisServer::objectInit();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        $db = null;
        while (1) {
            try {
                $message = $redis->BrPop('aliCalkBack');
                $message = $message['message'];
                $db = Db::connect([], true);
                $this->dispose($message);
            } catch (\Exception $e) {
                Db::name('error_log')->insert([
                    'source' => 'aliApiNew/calkBack',
                    'desc' => '回调错误:' . $e->getMessage() . $e->getFile() . $e->getLine(),
                    'date' => date('Y-m-d H:i:s')
                ]);
            }
            $db->close();
        }
    }

    protected function testLog($message) {
        //$message = json_encode($message, JSON_UNESCAPED_UNICODE);
        //Db::name('test')->insert(['param' => '未知回调:' . $message, 'time' => date('Y-m-d H:i:s')]);
        return 1;
    }

    protected function dispose($message) {
        $alibaba2 = new AlibabaServiceV2();
        $alipro = new AliProductV2();
        $message = json_decode($message, true);
        if (empty($message)) return 1;
        switch ($message['type']) {
            case 'PRODUCT_RELATION_VIEW_PRODUCT_NEW_OR_MODIFY'://1688产品新增或修改
                $productArr = explode(',', $message['data']['productIds']);
                foreach ($productArr as $v) {
                    $count1 = Db::name('ali_price_info')->where('feedId', $v)->count();
                    if ($count1) {
                        Db::name('ali_price_info')->where('feedId', $v)->delete();//删除价格导航
                        $alibaba2->getListPrice($v, 0);//重新获取价格导航
                    }
                    $find = $alipro->field('sync')->where('feedId', $v)->find();
                    if (!empty($find)) {
                        $alibaba2->insertProductV2($v, 0, 0, 1);
                        if ($find['sync'] == 0) {
                            $alipro->where('feedId', $v)->update(['invalid' => 'false']);
                        }
                        $this->testLog($message);
                    }
                }
                break;
            case 'PRODUCT_RELATION_VIEW_PRODUCT_DELETE': //1688产品删除
                $productIdsArr = explode(',', $message['data']['productIds']);
                foreach ($productIdsArr as $v) {
                    $alipro->where('feedId', $v)->update(['invalid' => 'true']);
                    $alibaba2->productUnfollow($v); //商品取关
                    $this->testLog($message);
                }
                break;
            case 'PRODUCT_RELATION_VIEW_PRODUCT_AUDIT'://1688产品审核下架
            case 'PRODUCT_RELATION_VIEW_PRODUCT_EXPIRE'://1688产品下架
                $productIdsArr = explode(',', $message['data']['productIds']);
                foreach ($productIdsArr as $v) {
                    $count = $alipro->where('feedId', $v)->count();
                    if ($count) {
                        $alipro->where('feedId', $v)->update(['invalid' => 'true']);
                        $this->testLog($message);
                    }
                }
                break;
            case 'PRODUCT_RELATION_VIEW_PRODUCT_REPOST'://1688产品上架
                $productIdsArr = explode(',', $message['data']['productIds']);
                foreach ($productIdsArr as $v) {
                    $find = $alipro->field('sync')->where('feedId', $v)->count();
                    if (!empty($find)) {
                        $alibaba2->insertProductV2($v);
                        if ($find['sync'] == 0) {
                            $alipro->where('feedId', $v)->update(['invalid' => 'false']);
                        }
                        $this->testLog($message);
                    }
                }
                break;
            case 'PRODUCT_PRODUCT_INVENTORY_CHANGE'://1688商品库存变更消息
                foreach ($message['data']['OfferInventoryChangeList'] as $v) {
                    $count = $alipro->where('feedId', $v['offerId'])->count();
                    if ($count) {
                        $alipro->where('feedId', $v['offerId'])->setInc('sale_num', abs($v['quantity']));
                        $alipro->attrs()->where(['feedId' => $v['offerId'], 'skuId' => $v['skuId']])->update(['amountOnSale' => $v['offerOnSale']]);
                    }
                }
                break;
            case 'PRODUCT_RELATION_VIEW_EXIT_SUPERBUYER'://商品池&超买价变更消息
                foreach ($message['data']['products'] as $v) {
                    if ($v['status'] == 'DELETE') {
                        $alipro->where('feedId', $v['productId'])->update(['invalid' => 'true']);
                        $alibaba2->productUnfollow($v['productId']); //商品取关
                    }
                }
                break;
            case 'ORDER_BUYER_VIEW_PART_PART_SENDGOODS'://1688订单部分发货
            case 'ORDER_BUYER_VIEW_ANNOUNCE_SENDGOODS'://1688订单发货（买家视角）
            case 'ORDER_BUYER_VIEW_ORDER_COMFIRM_RECEIVEGOODS'://1688订单确认收货（买家视角）
                writeLog('callback_log.log', $message, '阿里回调');
            default:
                $this->testLog($message);
                break;
        }
        return 1;
    }

    public function setFreight($param) {
        if (!isset($param['type'])) throw new \Exception('type 不能为空!');
        if (empty($param['ids'])) return;
        $freight = 0;
        if ($param['type'] == 0) {
            $freight = -1;
        }
        $alipro = new AliProductV2();
        $alipro->whereIn('id', $param['ids'])->update(['freight' => $freight]);
        return $alipro;
    }

    public function getFreight($feedId) {
        $alipro = (new AliProductV2())->where('feedId', $feedId)->find();
        if ($alipro === null) return false;
        if ($alipro['freight'] < 0) {
            return false;
        }
        return $alipro['freight'];
    }
}
