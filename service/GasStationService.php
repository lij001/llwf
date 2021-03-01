<?php


namespace app\api\service;

use app\api\BaseService;
use app\api\model\GasList;
use app\api\model\GasOrder;
use app\api\model\GasPriceList;
use app\api\service\TokenService;
use think\Db;

class GasStationService extends BaseService {
    private $app_key = 'appm_api_h598644323';
    private $app_secret = 'a71b8b51fe9cd25944c344d39f1acbc8';
    private $sn = '98644323';
    private $name = '留莲忘返';
    private $baseurl = 'https://mcs.czb365.com/services/v3/';

    private function token($phone) {
        $uid = TokenService::getCurrentUid();
        $gasToken = json_decode(Db::name('members')->where('id', $uid)->value('gas_token'), true);
        if ($gasToken == null || $gasToken['end_time'] < time()) {
            $url = 'begin/platformLoginSimpleAppV4';
            $data = [
                'platformType' => $this->sn,
                'platformCode' => $phone
            ];

            $url = $this->sign($url, $data);
            $res = $this->curl($url, $data);
            if ($res['code'] != 200) {
                throw new \Exception('token获取失败!');
            }
            $gasToken = [
                'time' => time(),
                'end_time' => time() + 86400 * 18,
                'token' => $res['result']['token']
            ];
            Db::name('members')->where('id', $uid)->update(['gas_token' => json_encode($gasToken)]);
        }
        return $gasToken['token'];
    }

    private function sign($url, &$data, $baseurl = true) {
        ksort($data);
        list($t1, $t2) = explode(' ', microtime());
        $timestamp = (float)(sprintf('%.0f', ((float)$t1 + (float)$t2) * 1000));
        $data = array_merge(['app_key' => $this->app_key], $data, ['timestamp' => $timestamp]);
        $url_extend = [];
        $sign = [$this->app_secret];
        foreach ($data as $key => $value) {
            $url_extend[] = $key . '=' . $value;
            $sign[] = $key . $value;
        }
        array_push($sign, $this->app_secret);
        $signstr = md5(implode('', $sign));
        array_push($url_extend, 'sign=' . $signstr);
        $data['sign'] = $signstr;
        return $baseurl ? $this->baseurl . $url . '?' . implode('&', $url_extend) : $url . '?' . implode('&', $url_extend);
    }

    private function curl($url, $data = [], $re = true) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json;charset='utf-8'"]);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data); //需要json数组
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $output = curl_exec($curl);
        //var_dump(curl_getinfo($curl,CURLINFO_HTTP_CODE));exit;
        curl_close($curl);
        return $this->res($output, $url, $data, $re);
    }

    private function res($output, $url, &$data, $re) {
        $res = json_decode($output, true);
        if (($res == null || $res['code'] != 200) && $re == true) {
            $res = $this->curl($url, $data, false);
            if (!is_array($res)) return $res;
        }
        if (!is_array($res)) return $output;
        return $res;
    }

    public function gasList($post) {
        if ((empty($post['Longitude'])) || empty($post['Latitude']))
            throw new \Exception('定位信息不能为空!');
        if (empty($post['phone'])) {
            $post['phone'] = Db::name('members')->where('id', TokenService::getCurrentUid())->value('mobile');
        }

        $db1 = db::name('');
        $db = Db::name('gas_list')->alias('a');
        $db->field("a.*,(round(6378.138*2*asin(sqrt(pow(sin((" . $post['Latitude'] . "*pi()/180-a.gasAddressLatitude*pi()/180)/2),2)+cos(" . $post['Latitude'] . "*pi()/180)*cos(a.gasAddressLatitude*pi()/180)*pow(sin((" . $post['Longitude'] . "*pi()/180-a.gasAddressLongitude*pi()/180)/2),2)))*1000)/1000) distance");
        $db->field('b.priceYfq,b.oilNo');
        $db->join('gas_price_list b', 'a.id=b.gl_id');
        $distance = 50;
        if (!empty($post['distance']) && $post['distance'] > 1) {
            $distance = $post['distance'];
        }
        $db1->where('distance', '<=', $distance);
        if (!empty($post['priority'])) {
            if ($post['priority'] == 2) {
                $db1->order('priceYfq', 'asc');
            }
        }
        if (!empty($post['oilName'])) {
            $db1->where('oilNo', $post['oilName']);
        }
        if (!empty($post['brand_name'])) {
            //1中石油 2中石化 3壳牌 4其他
            $gasType = ['中石油' => 1, '中石化' => 2, '壳牌' => 3, '其他' => 4];
            foreach ($post['brand_name'] as $brand_name) {
                if (empty($gasType[$brand_name])) throw new \Exception('品牌不正确');
                $db->whereOr('a.gasType', $gasType[$brand_name]);
            }
        }
        $list = $db1->table($db->buildSql())->alias('t')->order('distance', 'asc')->limit(limit($post['start'], 10), 10)->select();
        if ($list->isEmpty()) return [];
        $list = $list->toArray();
        $gasIds = array_column($list, 'gasId');
        $originGasList = $this->gasStatus($gasIds, $post['phone']);
        if (empty($originGasList)) return [];
        $originGasIds = array_column($originGasList, 'gasId');
        $return = [];
        foreach ($list as $key => $value) {
            if (($index = array_search($value['gasId'], $originGasIds)) !== false) {
                foreach ($originGasList[$index]['oilPriceList'] as $v) {
                    $update = [
                        'oilName' => $v['oilName'],
                        'priceYfq' => $v['priceYfq'],
                        'priceGun' => $v['priceGun'],
                        'priceOfficial' => $v['priceOfficial'],
                        'gunNos' => implode(',', array_column($v['gunNos'], 'gunNo'))
                    ];
                    Db::name('gas_price_list')->where(['gl_id' => $value['id'], 'oilNo' => $v['oilNo']])->update($update);
                }
                $value['list'] = [];
                $return[$value['id']] = $value;
            }
        }
        $gl_ids = array_keys($return);
        $priceList = Db::name('gas_price_list')->whereIn('gl_id', $gl_ids)->select();
        foreach ($priceList as $k => $value) {
            if (empty($return[$value['gl_id']]['priceYfq']) || empty($return[$value['gl_id']]['priceGun']) || $return[$value['gl_id']]['priceYfq'] > $value['priceYfq']) {
                $return[$value['gl_id']]['priceYfq'] = $value['priceYfq'];
                $return[$value['gl_id']]['priceGun'] = $value['priceGun'];
                $return[$value['gl_id']]['priceOfficial'] = $value['priceOfficial'];
            }
            $return[$value['gl_id']]['list'][] = $value;
        }
        $return = array_values($return);
        if (!empty($post['priority'])) {
            if ($post['priority'] == 2) {
                quickRow($return, 'priceYfq', 0, count($return) - 1);
            }
        }
        return $return;
    }

    public function gasStatus($gasIds, $phone) {
        $this->token($phone);
        $url = 'gas/queryPriceByPhone';
        $data = [
            'gasIds' => implode(',', $gasIds),
            'platformType' => $this->sn,
            'phone' => $phone,
        ];
        $url = $this->sign($url, $data);
        $res = $this->curl($url, $data);
        if ($res['code'] != 200) return [];
        return $res['result'];
    }

    public function orderList($post) {
        $db = Db::name('gas_order');
        if (!empty($post['is_convert'])) {
            $db->where('is_convert', $post['is_convert']);
        }
        $uid = TokenService::getCurrentUid();
        return $db->where('uid', $uid)
            ->order('orderTime', 'desc')
            ->limit(limit($post['start'], 10), 10)->select();
    }

    protected function orderListWhere($post, $alias = '') {
        $db = Db::name('gas_order');
        if (!empty($alias)) {
            $db->alias($alias);
            $alias .= '.';
        }
        if (!empty($post['is_convert'])) {
            $db->where($alias . 'is_convert', $post['is_convert']);
        }
        if (!empty($post['phone'])) {
            $db->where($alias . 'phone', $post['phone']);
        }
        if (!empty($post['orderStatus'])) {
            $db->where($alias . 'orderStatus', $post['orderStatus']);
        }
        if (!empty($post['order_no'])) {
            $db->where($alias . 'order_no', $post['order_no']);
        }
        if (!empty($post['orderId'])) {
            $db->where($alias . 'orderId', $post['orderId']);
        }
        if (!empty($post['pay_type'])) {
            $db->where($alias . 'order_no in ' . Db::name('pay_info')->field('order_no')->where('pay_type', $post['pay_type'])->whereLike('order_no', 'TY%')->buildSql());
        }
        return $db;
    }

    public function adminOrderList($post) {
        $db = $this->orderListWhere($post);
        return $db->order('convert_time', 'desc')->order('orderTime', 'desc')->paginate(10, false, ['page' => $post['start']]);
    }

    public function getOrderListStatistical($post) {
        $db = $this->orderListWhere($post, 'a');
        $result = $db->field('sum(p.pay_amount) pay_amount,count(a.id) num,p.pay_type')->join('pay_info p', 'p.order_no=a.order_no ')->group('p.pay_type')->select();
        $ali = $we = ['pay_amount' => 0, 'num' => 0];
        foreach ($result as $val) {
            if ($val['pay_type'] === 1) {
                $we = $val;
            } elseif ($val['pay_type'] === 2) {
                $ali = $val;
            }
        }
        return ['ali' => $ali, 'wechat' => $we];
    }

    /**远程拉取加油站列表
     * @return array|mixed|null
     */
    public function originGasList() {
        $url = 'gas/queryGasInfoListOilNoNew';
        $data = ['channelId' => $this->sn];
        $url = $this->sign($url, $data);
        return $this->curl($url, $data);
    }

    /**
     * 拉取订单
     * @param $start 起始页
     * @return array|mixed|null
     */
    public function originOrderList($start) {
        $url = 'orderws/platformOrderInfoV2';
        $data = [
            'orderSource' => $this->sn,
            'orderStatus' => 1,
            'pageIndex' => $start,
            'pageSize' => 100,
        ];
        $url = $this->sign($url, $data);
        return $this->curl($url, $data);
    }

    public function orderDetail($post) {
        if (empty($post['order_no'])) throw new \Exception('订单号不能为空!');
        $uid = TokenService::getCurrentUid();
        $order = Db::name('gas_order')->where('uid', $uid)->where('order_no', $post['order_no'])->find();
        if (empty($order)) throw new \Exception('未找到订单详情');
        return $order;
    }

    public function adminOrderDetail($post) {
        if (empty($post['order_no'])) throw new \Exception('订单号不能为空!');
        $order = Db::name('gas_order')->where('order_no', $post['order_no'])->find();
        if (empty($order)) throw new \Exception('未找到订单详情');
        return $order;
    }

    public function convert($post) {
        if (empty($post['order_no'])) throw new \Exception('订单号不能为空');
        $uid = TokenService::getCurrentUid();
        $order = $this->orderDetail($post);
        if ($order['convert_money'] == 0) throw new \Exception('订单金额为0 不能兑换');
        return OrderService::pay($uid, $order['id'], $order['order_no'], $order['convert_money'], $post['paytype'], '留莲忘返-积分兑换', 7);
    }

    public function jumpPayUrl($post) {
        $url = 'https://open.czb365.com/redirection/todo/';
        $data = [
            'platformType' => $this->sn,
            'platformCode' => $post['phone'],
            'gasId' => $post['gasId'],
            'gunNo' => $post['gunNo']
        ];
        $url = $this->sign($url, $data, false);
        return $url;
    }

    public function originOrderStatus($orderId) {
        $url = 'orderws/platformOrderInfoV2';
        $data = [
            'orderSource' => $this->sn,
            'orderId' => $orderId,
            'pageIndex' => 1,
            'pageSize' => 5,
        ];
        $url = $this->sign($url, $data);
        return $this->curl($url, $data);
    }

    public function remoteOrderStatus($param) {
        if (empty($param['orderId'])) return [];
        return $this->originOrderStatus(trim($param['orderId']));
    }

    /**
     * 获取油站并插入数据库
     */
    public function timingGasList() {
        $res = $this->originGasList();
        if (!is_array($res) || $res['code'] != 200) {
            $res = is_array($res) ? json_encode($res) : $res;
            $msg = date('Y-m-d H:i:s') . $res;
            writeLog('gas.log', $msg);
            exit;
        }
        $gasIds = [];
        foreach ($res['result'] as $gas) {
            $gasIds[] = ['gasId' => $gas['gasId']];
        }
        //生成临时表 并插入临时表
        Db::execute('drop table if exists xcx_temp_gas_list');
        Db::execute('CREATE TEMPORARY TABLE xcx_temp_gas_list(gasId varchar(255) not null default "")');
        Db::name('temp_gas_list')->insertAll($gasIds);

        $list = GasList::objectInit()->alias('g')->field('g.id,g.gasId')
            ->join('temp_gas_list t', 't.gasId=g.gasId')
            ->select();
        $localGasList = [];
        foreach ($list as $key => $gsa) {
            $localGasList[$gsa['gasId']] = $gsa;
            $localGasList[$gsa['gasId']]['list'] = [];
        }
        $gasTypeName = [1 => '中石油', 2 => '中石化', 3 => '壳牌', 4 => '其他'];
        foreach ($res['result'] as $key => $gas) {
            $data = [
                'gasId' => $gas['gasId'],
                'gasName' => $gas['gasName'] ?: '',
                'gasType' => $gas['gasType'],
                'gasTypeName' => $gasTypeName[$gas['gasType']] ? $gasTypeName[$gas['gasType']] : '其他',
                'gasLogoBig' => $gas['gasLogoBig'],
                'gasLogoSmall' => $gas['gasLogoSmall'],
                'gasAddress' => $gas['gasAddress'],
                'gasAddressLongitude' => $gas['gasAddressLongitude'],
                'gasAddressLatitude' => $gas['gasAddressLatitude'],
                'provinceCode' => $gas['provinceCode'],
                'cityCode' => $gas['cityCode'] ? $gas['cityCode'] : 0,
                'countyCode' => $gas['countyCode'] ? $gas['countyCode'] : 0,
                'provinceName' => $gas['provinceName'] ? $gas['provinceName'] : 0,
                'cityName' => $gas['cityName'] ? $gas['cityName'] : '',
                'countyName' => $gas['countyName'] ? $gas['countyName'] : '',
                'isInvoice' => $gas['isInvoice'] ? $gas['isInvoice'] : 0,
                'companyId' => $gas['companyId'] ? $gas['companyId'] : 0,
                'update_time' => date('Y-m-d H:i:s')
            ];
            if (!array_key_exists($gas['gasId'], $localGasList)) {
                $info = GasList::create($data, true);
                if ($info) {
                    $id = $info->getAttr('id');
                    $inserts = [];
                    foreach ($gas['oilPriceList'] as $kk => $vv) {
                        $inserts[] = [
                            'gl_id' => $id,
                            'gasId' => $gas['gasId'],
                            'oilNo' => $vv['oilNo'],
                            'oilName' => $vv['oilName'],
                            'priceYfq' => $vv['priceYfq'],
                            'priceGun' => $vv['priceGun'],
                            'priceOfficial' => $vv['priceOfficial'],
                            'oilType' => $vv['oilType'],
                        ];
                    }
                    GasPriceList::insertAll($inserts);
                }
            } else {
                GasList::_whereCV(['id' => $localGasList[$gas['gasId']]['id']])->update($data);
                foreach ($gas['oilPriceList'] as $kk => $vv) {
                    $data2 = [
                        'gl_id' => $localGasList[$gas['gasId']]['id'],
                        'gasId' => $gas['gasId'],
                        'oilNo' => $vv['oilNo'],
                        'oilName' => $vv['oilName'],
                        'priceYfq' => $vv['priceYfq'],
                        'priceGun' => $vv['priceGun'],
                        'priceOfficial' => $vv['priceOfficial'],
                        'oilType' => $vv['oilType'],
                    ];
                    if (empty($localGasList[$gas['gasId']]['list'][$vv['oilNo']])) {
                        GasPriceList::create($data2);
                    } else {
                        GasPriceList::where(['id' => $localGasList[$gas['gasId']]['list'][$vv['oilNo']]['id']])->update($data2);
                    }
                }
            }
        }
        return 1;
    }

    /**
     * 获取团油订单并插入数据库
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function GasOrderList() {
        $start = 1;
        $start_time = strtotime(date('Y-m-d').' -1 day');
        while (1) {
            $originOrderList = $this->originOrderList($start);
            if ($originOrderList['code'] != 200) {
                $res = is_array($originOrderList['message']) ? json_encode($originOrderList['message']) : $originOrderList['message'];
                $msg = date('Y-m-d H:i:s') . $res;
                writeLog('gas.log', $msg);
                exit;
            }
            if (empty($originOrderList['result'])) exit('操作完成');
            $orderIds = array_column($originOrderList['result'], 'orderId');
            $orderList = GasOrder::_whereCVIn('orderId', $orderIds)->field('id,orderId')->select();
            $localOrderList = [];
            foreach ($orderList as $key => $val) {
                $localOrderList[$val['orderId']] = 1;
            }
            $orderStatus = ['已支付' => 1, '退款申请中' => 4, '已退款' => 5, '退款失败' => 6];
            foreach ($originOrderList['result'] as $order) {
                $order_time = strtotime($order['payTime']);
                if ($order_time == false) {
                    continue;
                }
                if ($start_time > $order_time) {
                    break 2;
                }
                if (!array_key_exists($order['orderId'], $localOrderList)) {
                    $convert_money = 0;
                    if ($order['couponMoney'] > 0) {
                        $convert_money = $order['couponMoney'];
                    }
                    if ($order['amountDiscounts'] > 0) {
                        $convert_money = $order['amountDiscounts'];
                    }
                    $gxz = sprintf("%.2f", $convert_money * 6.67);
                    $data = [
                        'orderId' => $order['orderId'] ?: '',
                        'paySn' => $order['paySn'] ?: '',
                        'phone' => $order['phone'] ?: '',
                        'orderTime' => $order['orderTime'],
                        'payTime' => $order['payTime'],
                        'refundTime' => $order['refundTime'],
                        'gasName' => $order['gasName'] ?: '',
                        'province' => $order['province'] ?: '',
                        'city' => $order['city'] ?: '',
                        'county' => $order['county'] ?: '',
                        'gunNo' => $order['gunNo'] ?: '',
                        'oilNo' => $order['oilNo'] ?: '',
                        'amountPay' => $order['amountPay'] ?: '',
                        'amountGun' => $order['amountGun'] ?: '',
                        'amountDiscounts' => $order['amountDiscounts'] ?: '',
                        'orderStatus' => $orderStatus[$order['orderStatusName']] ?: '',
                        'orderStatusName' => $order['orderStatusName'] ?: '',
                        'couponMoney' => $order['couponMoney'] ?: '',
                        'couponId' => (int)$order['couponId'] ?: '',
                        'couponCode' => $order['couponCode'] ? $order['couponCode'] : '',
                        'gxz' => $gxz,
                        'convert_money' => $convert_money,
                        'litre' => $order['litre'] ?: '',
                        'payType' => $order['payType'] ?: '',
                        'priceUnit' => $order['priceUnit'] ?: '',
                        'priceOfficial' => $order['priceOfficial'] ?: '',
                        'priceGun' => $order['priceGun'] ?: '',
                        'qrCode4PetroChina' => $order['qrCode4PetroChina'] ?: '',
                    ];
                    try {
                        $data['uid'] = MemberService::getMemberInfo(['mobile' => $order['phone']])->getAttr('id');
                        $data['order_no'] = createOrderNo('TY');
                        GasOrder::create($data);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
            $start++;
        }
    }
}
