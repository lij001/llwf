<?php

namespace app\api\service\Alibaba;

abstract class Request
{
    // API命名空间
    /**
     * 商品列表搜索接口
     * 获取offer价格雷达信息列表
     * 获取营销活动价格等活动信息
     * 获取选品库已选商品列表
     * 获取我的选品库列表
     */
    const NAMESPACE_P4P = 'com.alibaba.p4p';

    /**
     * 获取商品详情接口
     * 根据类目Id查询类目
     * 关注商品
     * 解除关注商品
     */
    const NAMESPACE_PRODUCT = 'com.alibaba.product';

    /**
     * 订单预览
     */
    const NAMESPACE_TRADE = 'com.alibaba.trade';

    /**
     * 物理信息
     */
    const NAMESPACE_LOGISTICS = 'com.alibaba.logistics';

    // API接口名
    /**
     * 商品
     */
    const NAME_P4P_SEARCH_CYB_OFFERS = 'alibaba.cps.op.searchCybOffers';                                // 商品列表搜索接口
    const NAME_PRODUCT_PRODUCT_INFO = 'alibaba.cpsMedia.productInfo';                                   // 获取商品详情接口
    const NAME_P4P_LIST_PRICE_RADAR_OFFER = 'alibaba.cps.listPriceRadarOffer';                          // 获取offer价格雷达信息列表
    const NAME_P4P_QUERY_OFFER_DETAIL_ACTIVITY = 'alibaba.cps.queryOfferDetailActivity';                // 获取营销活动价格等活动信息
    const NAME_PRODUCT_CATEGORY_GET = 'alibaba.category.get';                                           // 根据类目Id查询类目
    const NAME_PRODUCT_FOLLOW = 'alibaba.product.follow';                                               // 关注商品
    const NAME_PRODUCT_UNFOLLOW_CROSSBORDER = 'alibaba.product.unfollow.crossborder';                   // 解除关注商品
    const NAME_P4P_LIST_CYB_USER_GROUP_FEED = 'alibaba.cps.op.listCybUserGroupFeed';                    // 获取选品库已选商品列表
    const NAME_P4P_LIST_CYB_USER_GROUP = 'alibaba.cps.op.listCybUserGroup';                             // 获取我的选品库列表
    const NAME_P4P_LIST_OVER_PRICED_OFFER = 'alibaba.cps.op.searchCybOffers';                           // 商品列表搜索接口
    const NAME_TRADE_PREVIEW_CYB_MEDIA = 'alibaba.createOrder.preview4CybMedia';                        // 订单预览
    const NAME_TRADE_CREATE_ORDER_CYB_MEIDA = 'alibaba.trade.createOrder4CybMedia';                     // 下单
    const NAME_P4P_MEDIA_USER_RECOMMEND_OFFER_SERVICE = 'alibaba.cps.op.mediaUserRecommendOfferService';// 商品推荐(千人千面接口)
    const NAME_P4P_LIST_OFFER_FETAIL_ACTIVITY = 'alibaba.cps.listOfferDetailActivity';                  // 获取所有可用营销活动列表(媒体选择要使用的最优活动)
    const NAME_TRADE_ALIBABA_TRADE_GET_BUYERVIEW = 'alibaba.trade.get.buyerView';                       // 订单详情查看(买家视角)
    const NAME_TRADE_ALIBABA_TRADE_PAY_PROTOCOLPAY = 'alibaba.trade.pay.protocolPay';                   // 订单代扣
    const NAME_TRDE_ALIBABA_TRADE_CANCEL = 'alibaba.trade.cancel';                                      // 阿里订单取消
    const NAME_LOGISTICS_ALIBABA_TRADE_GETLOGISTICSINFOS_BUYERVIEW = 'alibaba.trade.getLogisticsInfos.buyerView';  //物流信息
    const NAME_LOGISTICS_ALIBABA_TRADE_GETLOGISTICSTRACEINFO_BUYERVIEW = 'alibaba.trade.getLogisticsTraceInfo.buyerView';  //物流信息
    const NAME_TRADE_ALIBABA_TRADE_GETREFUNDREASONLIST = 'alibaba.trade.getRefundReasonList';           // 退款理由
    const NAME_TRADE_ALIBABA_TRADE_CREATEREFUND = 'alibaba.trade.createRefund';                         // 阿里商品退款
    /**
     * 订单支付
     */
}
