<?php


namespace app\api\service;


use app\api\model\AliProductV2;
use app\api\model\AliShoppingCarModel;
use app\api\service\Alibaba\AlibabaService;
use app\api\service\Alibaba\AlibabaServiceV2;
use think\Cache;
use think\Db;
use think\Exception;

class AliShoppingCarService {
    /**
     * 添加购物车
     * @param $feedId
     * @param $specId
     * @param $quantity
     * @param $type
     * @param $title
     * @param $thumb
     * @param $price
     * @return int
     * @throws \think\Exception
     */
    public function addShoppingCar($feedId, $specId, $quantity, $type, $title, $attrTitle, $thumb, $price) {
        $uid = TokenService::getCurrentUid();
        $aliSC = new AliShoppingCarModel();
        $alipro = new AliProductV2();
        $attrTitle ? null : $attrTitle = '默认';
        $specId ? null : $specId = 0;

        if ($type == 2) {
            $alibaba2 = new AlibabaServiceV2();
            $v['price'] = $alibaba2->getSpecPrice($feedId, $specId, 1);
            if (!$v['price']) {
                return 2;
            }
        }
        $count = $aliSC->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type])->count();
        $min_quantity = $alipro->where('feedId', $feedId)->value('min_quantity');
        if (empty($min_quantity) || $min_quantity < 4) {
            $min_quantity = 1;
        }
        if ($count) {
            $result = $aliSC->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type])->update([
                'quantity' => $quantity,
                'min_quantity' => $min_quantity,
                'title' => $title,
                'attrTitle' => $attrTitle,
                'thumb' => $thumb,
                'price' => $price
            ]);
            if ($result) {
                return 0;
            } else {
                return 1;
            }
        } else {
            $aliSC->insert([
                'uid' => $uid,
                'feedId' => $feedId,
                'specId' => $specId,
                'type' => $type,
                'quantity' => $quantity,
                'min_quantity' => $min_quantity,
                'title' => $title,
                'attrTitle' => $attrTitle,
                'thumb' => $thumb,
                'price' => $price,
                'status' => 0
            ]);
            return 0;
        }
    }

    /**
     * 编辑购物车
     * @param $feedId
     * @param $specId
     * @param $quantity
     * @param $type
     * @return bool
     * @throws \think\Exception
     */
    public function editShoppingCar($feedId, $specId, $quantity, $type) {
        $uid = TokenService::getCurrentUid();
        $aliSC = new AliShoppingCarModel();
        $where = $aliSC->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type]);
        $count = $where->count();
        if ($count) {
            $aliSC->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type])->update(['quantity' => $quantity]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * 删除购物车
     * @param $feedId
     * @param $specId
     * @param $type
     * @return bool
     * @throws \think\Exception
     */
    public function deleteShoppingCar($feedId, $specId, $type) {
        $uid = TokenService::getCurrentUid();
        $aliSC = new AliShoppingCarModel();
        $where = $aliSC->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type]);
        $count = $where->count();
        if ($count) {
            $aliSC->destroy(function ($query) use ($uid, $feedId, $specId, $type) {
                $query->where(['uid' => $uid, 'feedId' => $feedId, 'specId' => $specId, 'type' => $type]);
            });
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取购物车列表
     * @param $page
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function listShoppingCar($page, $isGhs, $uid = null) {
        $alibaba2 = new AlibabaServiceV2();
        if (!$uid) {
            $uid = TokenService::getCurrentUid();
        }
        $aliSC = new AliShoppingCarModel();
        $alipro = new AliProductV2();
        $list = [];
        if ($isGhs) {
            $list = $aliSC->where('uid', $uid)->where('status', '<', 2)->where('type', 2)->order('status,feedId')->paginate(10)->toArray()['data'];
        } else {
            $list = $aliSC->where('uid', $uid)->where('status', '<', 2)->where('type', '<', 2)->order('status,feedId')->paginate(10)->toArray()['data'];
        }
        $arr = [];
        foreach ($list as $k => &$v) {
            $offerId = $v['feedId'];
            //获取商品详情
            $info = $alibaba2->getProductV2($offerId);
            if (!$info) {
                $aliSC->where('id', $v['id'])->delete();
                unset($list[$k]);
                continue;
            }
            //查找库存
            if ($v['specId']) {
                foreach ($info['productInfo']['skuInfos'] as $vv) {
                    if ($vv['specId'] == $v['specId']) {
                        $v['amountOnSale'] = $vv['amountOnSale'];
                    }
                }
            } else {
                $v['amountOnSale'] = $info['productInfo']['saleInfo']['amountOnSale'];
            }
            //查找价格
            switch ($v['type']) {
                case 0:
                case 1:
                    $find = $alipro->where('feedId', $v['feedId'])->find();
                    if (empty($find)) {
                        $alibaba2->insertProductV2($v['feedId']);
                    }
                    $v['price'] = $alibaba2->getSpecPriceV2($v['feedId'], $v['specId']);
                    $aliSC->where('uid', $uid)->where('feedId', $v['feedId'])->where('specId', $v['specId'])->update(['price' => $v['price']]);
                    $arr[] = $v;
                    break;
                case 2:
                    $v['price'] = $alibaba2->getSpecPrice($v['feedId'], $v['specId'], 1);
                    if (!$v['price']) {
                        $aliSC->where('id', $v['id'])->delete();
                        unset($list[$k]);
                        continue;
                    }
                    $aliSC->where('uid', $uid)->where('feedId', $v['feedId'])->where('specId', $v['specId'])->update(['price' => $v['price']]);
                    $arr[] = $v;
                    break;
                default:
                    break;
            }
        }
        return $arr;
    }

    /**
     * 阿里购物车订单预览
     * @param $address
     * @param $mobile
     * @param $fullName
     * @param $areaText
     * @param $cityText
     * @param $provinceText
     * @param $ids
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function shoppingCarPreviewOrder($areaText, $cityText, $provinceText, $ids) {
        try {
            $alibaba = new AlibabaService();
            $uid = TokenService::getCurrentUid();
            $addressParam = [
                "address" => '解放街15号',
                "mobile" => '15278727177',
                "phone" => '',
                "fullName" => '何先生',
                "postCode" => "",
                "areaText" => $areaText,
                "townText" => "",
                "cityText" => $cityText,
                "provinceText" => $provinceText
            ];
            $shixiao = Db::name('ali_shopping_car')->where(['uid' => $uid, 'id' => ['in', $ids], 'status' => 1])->count();
            if ($shixiao) throw new Exception('有商品失效,请刷新购物车');
            $shopping_car = Db::name('ali_shopping_car')->where(['uid' => $uid, 'id' => ['in', $ids], 'status' => 0])->select();
            $cargoParamList = [];
            foreach ($shopping_car as $v) {
                $temp = [
                    "offerId" => $v['feedId'],
                    "quantity" => $v['quantity'],
                ];
                $v['specId'] ? $temp['specId'] = $v['specId'] : null;
                $cargoParamList['FE' . $v['feedId']][] = $temp;
            }
            $post = 0;
            foreach ($cargoParamList as $v) {
                $return = $alibaba->preview4CybMedia(['addressParam' => $addressParam, 'cargoParamList' => $v]);
                if (!$return['success']) {
                    Db::name('ali_shopping_car')->where(['uid' => $uid, 'feedId' => $v[0]['offerId']])->update(['status' => 1]);
                    throw new Exception('[' . $v[0]['offerId'] . ']有商品失效,请刷新购物车');
                }
                $returns[] = $return;
                $post += $posts['ID' . $v[0]['offerId']] = $return['orderPreviewResuslt'][0]['sumCarriage'] / 100;//计算总邮费
            }
            return ['status' => 0, 'msg' => 'success', 'data' => ['post' => $post, 'posts' => $posts]];
        } catch (\Exception $e) {
            Db::name('error_log')->insert([
                'source' => 'shoppingCarPreviewOrder',
                'desc' => $e->getMessage(),
                'uid' => $uid,
                'date' => date('Y-m-d H:i:s')
            ]);
            return ['status' => 3, 'msg' => $e->getMessage(), 'data' => $post];
        }
    }

    public function shoppingCarCreateOrder($address, $mobile, $fullName, $areaText, $cityText, $provinceText, $ids, $balance, $tz, $postFee) {
        Db::startTrans();
        try {
            $alibaba = new AlibabaService();
            $uid = TokenService::getCurrentUid();
            $addressParam = [
                "address" => $address,
                "mobile" => $mobile,
                "phone" => "",
                "fullName" => $fullName,
                "postCode" => "",
                "areaText" => $areaText,
                "townText" => "",
                "cityText" => $cityText,
                "provinceText" => $provinceText
            ];
            $shopping_car = Db::name('ali_shopping_car')->where(['uid' => $uid, 'id' => ['in', $ids], 'status' => 0])->select();
            $cargoParamList = [];
            $payAmount = 0;
            foreach ($shopping_car as $v) {
                $cargoParamList['FE' . $v['feedId']][] = [
                    "offerId" => $v['feedId'],
                    "specId" => $v['specId'] ? $v['specId'] : null,
                    "quantity" => $v['quantity'],
                ];
                $outerOrderInfo['FE' . $v['feedId']]['mediaOrderId'] = 'ME' . date('YmdHis') . rand(1000, 9999);
                $outerOrderInfo['FE' . $v['feedId']]['phone'] = $mobile;
                $outerOrderInfo['FE' . $v['feedId']]['offers'][] = [
                    'id' => $v['feedId'],
                    'specId' => $v['specId'] ? $v['specId'] : null,
                    'num' => $v['quantity'],
                    'price' => $v['price'] * 100
                ];
                $payAmount += $v['quantity'] * $v['price'];
            }
            $payAmount += $postFee;
            $pid = 'PME' . date('YmdHis') . rand(1000, 9999);
            if ($payAmount < ($balance + $tz)) {
                throw new Exception(json_encode('抵扣金额大于待支付金额', JSON_UNESCAPED_UNICODE));
            }
            if (!empty($tz) || !empty($balance)) {
                $configPay = Db::name('config_pay')->where('id', 1)->find();
                if (!empty($tz)) {
                    if ($configPay['tz'] == 0) {
                        throw new Exception(json_encode('通证抵扣已关闭,请重新下单', JSON_UNESCAPED_UNICODE));
                    }
                    $dikouMax = $payAmount * $configPay['tz_rate'] / 100;
                    if ($dikouMax < $configPay['tz']) {
                        throw new Exception(json_encode('通证抵扣金额已超过限定百分比', JSON_UNESCAPED_UNICODE));
                    }
                }
                if (!empty($balance) && $configPay['yue'] == 0) {
                    if ($configPay['yue'] == 0) {
                        throw new Exception(json_encode('余额抵扣已关闭,请重新下单', JSON_UNESCAPED_UNICODE));
                    }
                    $dikouMax = $payAmount * $configPay['yue_rate'] / 100;
                    if ($dikouMax < $configPay['yue']) {
                        throw new Exception(json_encode('余额抵扣金额已超过限定百分比', JSON_UNESCAPED_UNICODE));
                    }
                    throw new Exception(json_encode('余额抵扣已关闭,请重新下单', JSON_UNESCAPED_UNICODE));
                }
            }
            $tzNew = $balanceNew = $postages = $noposts = 0;
            $payAmount -= $postFee;
            $right = new RightsService();
            foreach ($cargoParamList as $k => $v) {
                $return = $alibaba->createOrder4CybMedia([
                    'addressParam' => $addressParam,
                    'cargoParamList' => $v,
                    'outerOrderInfo' => $outerOrderInfo[$k]
                ]);
                if ($return['success']) {
                    $postages += $postage = $return['result']['postFee'] / 100;
                    foreach ($v as $vv) {
                        if ($vv['specId'] === null) {
                            $specId = 0;
                        } else {
                            $specId = $vv['specId'];
                        }
                        $carInfo = Db::name('ali_shopping_car')->where(['id' => ['in', $ids], 'feedId' => $vv['offerId'], 'specId' => $specId])->find();
                        $noposts += $nopost = $carInfo['price'] * $carInfo['quantity'];
                        $buyerView = $alibaba->buyerView(['orderId' => $return['result']['orderId']]);
                        foreach ($buyerView['result']['productItems'] as $b) {
                            if ($b['productID'] == $vv['offerId'] && $b['specId'] == $vv['specId']) {
                                $consignPrice = $b['price'] * $b['quantity'];
                            }
                        }
                        $thisOrderNo = 'ME' . date('YmdHis') . rand(1000, 9999);
                        if (!empty($balance)) {
                            $balanceNew += $balanceTimes = floor($balance * ($nopost) / $payAmount * 100) / 100;
                        } else {
                            $balanceTimes = 0;
                        }
                        if (!empty($tz)) {
                            $tzNew += $tzTimes = floor($tz * ($nopost) / $payAmount * 100) / 100;
                        } else {
                            $tzTimes = 0;
                        }
                        Db::name('ali_order')->insert([
                            'parent_no' => $pid,
                            'order_no' => $thisOrderNo,
                            'orderId' => $return['result']['orderId'],
                            'uid' => $uid,
                            'feedId' => $vv['offerId'],
                            'skuId' => $vv['specId'],
                            'quantity' => $vv['quantity'],
                            'payamount' => $nopost + $postage - $balanceTimes - $tzTimes,
                            'total' => $nopost + $postage,
                            'unitprice' => $carInfo['price'],
                            'unitprice_old' => $consignPrice,
                            'nopost' => $nopost,
                            'postage' => $postage,
                            'status' => 0,
                            'pay_status' => 0,
                            'create_time' => date('Y-m-d H:i:s', time()),
                            'attrtitle' => $carInfo['attrTitle'],
                            'goodsname' => $carInfo['title'],
                            'imgUrl' => $carInfo['thumb'],
                            'order_type' => 0,
                            'source' => 'shoppingcar',
                            'balance' => $balanceTimes,
                            'tz' => $tzTimes
                        ]);
                    }
                    $returns[] = $return;
                } else {
//                    throw new Exception('有商品失效,请重新提交订单');
                    throw new Exception(json_encode($return['errorMsg'], JSON_UNESCAPED_UNICODE));
                }
            }
            if (!empty($balanceNew)) {
                $right->deductBalance($uid, $balanceNew, '余额支付抵扣', $pid);
            }
            if (!empty($tzNew)) {
                $right->deductTz($uid, $tzNew, '通证支付抵扣', $pid);
            }
//            Db::name('ali_shopping_car')->where(['uid' => $uid, 'id' => ['in', $ids], 'status' => 0])->update(['status' => 2]);
            Db::commit();
            return ['status' => 0, 'msg' => 'success', 'data' => ['parent_no' => $pid, 'ordersInfo' => $returns, 'postages' => $postages, 'noposts' => $noposts]];
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => 'shoppingCarCreateOrder',
                'desc' => $e->getMessage() . '|' . json_encode([$address, $mobile, $fullName, $areaText, $cityText, $provinceText, $ids, $balance, $tz, $postFee]),
                'uid' => $uid,
                'date' => date('Y-m-d H:i:s')
            ]);
            return ['status' => 2, 'msg' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 获取订单需支付金额
     * @param $pno 父订单号
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function shoppingCarOrderPay($pno, $payType) {
        $uid = TokenService::getCurrentUid();
        Db::startTrans();
        try {
            $noposts = Db::name('ali_order')->where('parent_no', $pno)->sum('nopost');
            $balance = Db::name('ali_order')->where('parent_no', $pno)->sum('balance');
            $tz = Db::name('ali_order')->where('parent_no', $pno)->sum('tz');
            $postageArr = Db::name('ali_order')->distinct('orderId')->field('postage')->where('parent_no', $pno)->select()->toArray();
            $postages = 0;
            foreach ($postageArr as $v) {
                $postages += $v['postage'];
            }
            $payamount = $noposts + $postages - $balance - $tz;
            if ($payType) {
                $return = OrderService::pay($uid, 1000, $pno, $payamount, $payType, '留莲忘返-购物车消费', 3);
                Db::commit();
                return ['status' => 0, 'msg' => 'success', 'data' => ['info' => $return]];
            } else {
                Db::commit();
                return ['status' => 0, 'msg' => 'success', 'data' => $payamount];
            }
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => 'shoppingCarOrderPay',
                'desc' => $e->getMessage(),
                'uid' => $uid,
                'date' => date('Y-m-d H:i:s')
            ]);
            return ['status' => 2, 'msg' => $e->getMessage(), 'data' => null];
        }
    }

    /**
     * 获取购物车支付总金额
     * @param $pno
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAmount($pno) {
        $noposts = Db::name('ali_order')->where('parent_no', $pno)->sum('nopost');
        $balance = Db::name('ali_order')->where('parent_no', $pno)->sum('balance');
        $tz = Db::name('ali_order')->where('parent_no', $pno)->sum('tz');
        $postageArr = Db::name('ali_order')->distinct('orderId')->field('postage')->where('parent_no', $pno)->select()->toArray();
        $postages = 0;
        foreach ($postageArr as $v) {
            $postages += $v['postage'];
        }
        $payamount = $noposts + $postages - $balance - $tz;
    }
}
