<?php

namespace app\api\service;

/**
 * 景彤云链接口服务
 * Class JtGlobleService
 * @package app\api\service
 */
class JtGlobleService
{
    /**
     * 接口url
     * @var string
     */
    private $url = 'https://www.jtgloble.com/api.php';
    /**
     * 版本号
     * @var string
     */
    private $api_version = '1.0';
    /**
     * token
     * @var string
     */
    private $token;

    /**
     * 公共参数
     * @var array
     */
    private $parms = [];

    public function __construct()
    {
        $this->parms = [
            'api_version' => $this->api_version,
            'token' => config('jtgloble_token'),
        ];
    }

    /**
     * 获取商品价格接口
     * @param $id
     * @return array
     * @author Vance
     *
     */
    public function search_goods_price($id)
    {
        $parms = array_merge($this->parms,[
           'act' => 'search_goods_price',
           'goods_id' => $id,
        ]);

        return $this->result($parms);
    }

    /**
     * 获取商品信息接口
     * @param $id
     * @return array
     * @author Vance
     *
     */
    public function search_goods_detail($id)
    {
        $parms = array_merge($this->parms,[
            'act' => 'search_goods_detail',
            'goods_id' => $id,
        ]);

        return $this->result($parms);
    }

    /**
     * 结果集处理
     * @param $parms
     * @return array
     * @author Vance
     *
     */
    private function result($parms)
    {
        // 获取xml数据
        $xml = curl_post($this->url,$parms);
        // 解析xml数据为数组
        $result = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $xmljson= json_encode($result);//将对象转换个JSON
        $xmlarray=json_decode($xmljson,true);//将json转换成数组

        // 判断接口数据
        if ($xmlarray['result'] == 'success') {
            $data = ['code' => 0,'data' => $xmlarray['info']['data_info']];
        } else {
            halt($xmlarray);
            $data = ['code' => 1,'message' => $xmlarray['msg']];
        }
        return $data;
    }
}
