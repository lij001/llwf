<?php

namespace app\api\service\Pdd;

class PddService extends BaseClient
{
    const PAGE_SIZE = 15;
    // 多多客API

    /**
     * 多多进宝商品查询
     * @param $params
     * @return array
     */
    public function ddkGoodsSearch($params)
    {
        $this->setType(Request::TYPE_DDK_GOODS_SEARCH);
        $res = $this->result(array_filter($params));
        return $this->Response($res);
    }

    /**
     * 对成功和失败进行处理
     * @param $data
     * @return array
     */
    protected function Response($data)
    {
        if (isset($data['error_response'])) {
            return ['code'=> $data['error_response']['error_code'], 'message'=>$data['error_response']['error_msg']];
        } else {
            return ['data'=>$data['goods_search_response']];
        }
    }
}
