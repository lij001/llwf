<?php


namespace app\api\service\Sdw;

use app\api\model\order\SdwOrder;
use app\api\service\MemberBalanceService;
use think\Exception;

class SdwGameService extends Request {
    /**
     * get方式请求接口数据
     * @param $url
     * @return bool|string
     */
    public function getCurl($url) {
        $header = array(
            'Accept: application/json',
        );
        $curl = curl_init();
        //设置抓取的url
        curl_setopt($curl, CURLOPT_URL, $url);
        //设置头文件的信息作为数据流输出
        curl_setopt($curl, CURLOPT_HEADER, 0);
        // 超时设置,以秒为单位
        curl_setopt($curl, CURLOPT_TIMEOUT, 1);
        // 设置请求头
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        //设置获取的信息以文件流的形式返回，而不是直接输出。
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        //执行命令
        $data = curl_exec($curl);
        // 显示错误信息
        if (curl_error($curl)) {
            print "Error: " . curl_error($curl);
            exit;
        } else {
            // 打印返回的内容
            curl_close($curl);
        }
        return json_decode($data, 1);
    }

    /**
     * 获取签名
     * @param $paramArr
     * @return string
     */
    public function getSign($paramArr) {
        $strArr = [];
        foreach ($paramArr as $k => $v) {
            $strArr[] = $k . '=' . $v;
        }
        $str2 = implode('&', $strArr) . self::SDW_APP_KEY;
        $sign = md5($str2);
        return $sign;
    }

    /**
     * 获取签名
     * @param $paramArr
     * @return string
     */
    public function getSign2() {
        $paramArr = [
            'account' => self::SDW_ACOUNT,
            'channel' => self::SDW_CHANNEL_ID,
            'sec' => time(),
            'key' => self::SDW_API_KEY
        ];
        $strArr = [];
        foreach ($paramArr as $k => $v) {
            $strArr[] = $k . '=' . $v;
        }
        $str2 = implode('&', $strArr);
        $sign = md5($str2);
        return $sign;
    }

    /**
     * 获取登陆URL
     * @param $param
     * @return string
     */
    public function getLoginUrl($param, $type) {
        if (empty($param['openid']) || empty($param['nick']) || empty($param['avatar']) || empty($param['phone'])) {
            return '缺少参数';
        }
        $paramArr = [
            'channel' => self::SDW_CHANNEL_ID,
            'openid' => $param['openid'],
            'time' => time(),
            'nick' => $param['nick'],
            'avatar' => $param['avatar'],
            'sex' => $param['sex'],
            'phone' => $param['phone'],
        ];
        $sign = $this->getSign($paramArr);
        $strArr2 = [];
        foreach ($paramArr as $k => $v) {
            $strArr2[] = $k . '=' . urlencode($v);
        }
        $urlNew = self::SDW_URL . '?' . implode('&', $strArr2) . '&sign=' . $sign . '&sdw_simple=' . $type . '&sdw_dl=1';
        return $urlNew;
    }

    /**
     * 获取玩家的历史数据
     * @param $uid
     * @return bool|string
     */
    public function queryGameHistoryByUser($uid) {
        $sign = $this->getSign2();
        $paramArr = [
            'account' => self::SDW_ACOUNT,
            'channel' => self::SDW_CHANNEL_ID,
            'sec' => time(),
            'sign' => $sign,
            'userId' => $uid
        ];
        $strArr = [];
        foreach ($paramArr as $k => $v) {
            $strArr[] = $k . '=' . urlencode($v);
        }
        $url = self::QUERY_GAME_GISTORY_BY_USER . '?' . implode('&', $strArr);
        $data = $this->getCurl($url);
        return $data;
    }

    /**
     * 游戏数据上报接口
     * @param $uid
     * @return bool|string
     */
    public function queryUserGameRecord($uid) {
        $sign = $this->getSign2();
        $paramArr = [
            'account' => self::SDW_ACOUNT,
            'channel' => self::SDW_CHANNEL_ID,
            'sec' => time(),
            'sign' => $sign,
            'userId' => "SDW" . self::SDW_ACOUNT . "U" . $uid,
            'gameId' => '1938126031',
        ];
        $strArr = [];
        foreach ($paramArr as $k => $v) {
            $strArr[] = $k . '=' . urlencode($v);
        }
        $url = self::QUERY_USER_GAME_RECORD . '?' . implode('&', $strArr);
        $data = $this->getCurl($url);
        return $data;
    }

    /**
     * 支付信息查询接口
     * @param int $page
     * @return bool|string
     */
    public function queryPayByChannel($start_time, $end_time, $page = 0) {
        $sign = $this->getSign2();
        $paramArr = [
            'account' => self::SDW_ACOUNT,
            'channel' => self::SDW_CHANNEL_ID,
            'sec' => time(),
            'sign' => $sign,
            'page' => $page,
            'pageSize' => 100,
            'stime' => $start_time * 1000,
            'etime' => $end_time * 1000
        ];
        $strArr = [];
        foreach ($paramArr as $k => $v) {
            $strArr[] = $k . '=' . urlencode($v);
        }
        $url = self::QUERY_PAY_BY_CHANNEL . '?' . implode('&', $strArr);
        $data = $this->getCurl($url);
        return $data;
    }

    public function sendGxz() {
        $i = 0;
        $start_time = strtotime(date('Y-m-d 00:00:01', strtotime('-1 day')));
        $end_time = strtotime(date('Y-m-d'));
        while (1) {
            $response = $this->queryPayByChannel($start_time, $end_time, $i);
            if ($response['msg'] == 'ok') {
                $list = $response['data']['list'];
                if (count($list) === 0) break;
                $sdwOrderIds = array_column($list, 'cpOrderId');
                $existOrderIds = SdwOrder::where('sdw_order_id', 'in', $sdwOrderIds)->column('sdw_order_id');
                $bool = count($existOrderIds) > 1 ? 1 : 0;
                $existOrderIds = array_flip($existOrderIds);
                foreach ($list as $item) {
                    if (!$bool || !array_key_exists($item['cpOrderId'], $existOrderIds)) {
                        try {
                            SdwOrder::startTrans();
                            $data = [
                                'sdw_order_id' => $item['cpOrderId'],
                                'channel' => $item['channel'],
                                'sdw_uid' => $item['uid'],
                                'mid' => $item['openId'],
                                'appId' => $item['appId'],
                                'product' => $item['product'],
                                'money' => $item['money'] / 100,
                                'create_time' => (int)($item['time'] / 1000),
                            ];
                            SdwOrder::create($data);
                            MemberBalanceService::objectInit()->sdwSuccess($data['mid'], $item['cpOrderId'], $data['money']);
                            SdwOrder::commit();
                        } catch (\Exception $e) {
                            SdwOrder::rollback();
                        }
                    }
                }
                if ($response['data']['totalNum'] < 100) {
                    break;
                }
                $i++;
            }
        }
    }
}
