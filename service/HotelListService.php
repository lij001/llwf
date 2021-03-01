<?php


namespace app\api\service;

use app\api\BaseService;
use think\Db;


class HotelListService extends BaseService {
    private $checkInDate;
    private $checkOutDate;
    public $secretKey = "TnDIhp8EAiTP5gbtmWsG";
    public $apiKey = "243851_DomesticHotel";
    private $acctId = '159082';
    private $limit = 100;
    private $baseurl = 'https://dop-api.dfyoo.com';

    public function initialization() {
        $this->checkInDate = date('Y-m-d');
        $this->checkOutDate = date('Y-m-d', strtotime('+1 day'));
    }

    public function pullHotelList() {
        $cityList = Db::name('hotel_cityname')->order('select_times desc')->select();
        foreach ($cityList as $city) {
            $update_time = date('Y-m-d H:i:s');
            $start = 1;
            $postData = $this->postData($city['city_name'], $start);
            $res = $this->post($this->hotelListUrl(), $postData);
            if ($res['msg'] == '无效城市名') {
                Db::name('hotel_cityname')->where(['city_name' => $city['city_name']])->delete();
                continue;
            }
            $stop = ceil($res['data']['count'] / $this->getLimit());
            while (true) {
                if ($res['success'] && !empty($res['data']['hotelList'])) {
                    $inserts = [];
                    $hotelIds = array_column($res['data']['hotelList'], 'hotelId');
                    $hotelTemp = Db::name('hotel_list')->field('id,hotelId')->whereIn('hotelId', $hotelIds)->select();
                    $localHotelList = [];
                    foreach ($hotelTemp as $value) {
                        $localHotelList['A' . $value['hotelId']] = $value['id'];
                    }
                    foreach ($res['data']['hotelList'] as $key => $hotel) {
                        $temp = [];
                        $temp['hotelId'] = $hotel['hotelId'];
                        $temp['address'] = $hotel['address'];
                        $temp['star'] = $hotel['star'];
                        $temp['starName'] = $hotel['starName'];
                        $temp['price'] = $hotel['price'];
                        $temp['chineseName'] = $hotel['chineseName'];
                        $temp['picture'] = $hotel['picture'];
                        $temp['latitude'] = $hotel['latitude'];
                        $temp['longitude'] = $hotel['longitude'];
                        $temp['city_name'] = $city['city_name'];
                        $temp['update_time'] = $update_time;
                        $exists_key = 'A' . $hotel['hotelId'];
                        if (array_key_exists($exists_key, $localHotelList)) {
                            Db::name('hotel_list')->where('id', $localHotelList[$exists_key])->update($temp);
                        } else {
                            $inserts[] = $temp;
                        }
                    }
                    if (!empty($inserts)) {
                        Db::name('hotel_list')->insertAll($inserts);
                    }
                    ++$start;
                    if ($start > $stop) {
                        break;
                    }
                    $postData = $this->postData($city['city_name'], $start);
                    $res = $this->post($this->hotelListUrl(), $postData);
                } else {
                    break;
                }
            }
        }
    }

    protected function hotelListUrl() {
        return $this->baseurl . '/DomesticHotel/query/hotelList';
    }

    protected function res($output, $url, $data) {
        $res = json_decode($output, true);
        if ($res != null && $res['msg'] == '无效城市名') {
            return $res;
        }
        $data = json_decode($data, true);
        if ($res != null && $res['errorCode'] == 231004) {
            sleep(60);
        }
        if ($res != null && $res['errorCode'] == 231005) {
            exit;
        }
        if ($res == null || !$res['success']) {
            if ($res == null || $res['errorCode'] != 231099) {
                $msg = date('Y-m-d H:i:s') . $output . $data['data']['cityName'];
                writeLog('HotelList.log', $msg);
            }
            sleep(5);
            $res = $this->post($url, $this->postData($data['data']['cityName'], $data['data']['start'], true));
        }
        return $res;
    }

    protected function postData($city, $start, $bool = false) {
        if ($bool == false) {
            $start = (($start > 1 ? $start : 1) - 1) * $this->limit;
        }
        $data = [
            'start' => $start,
            'limit' => $this->limit,
            'cityName' => $city,
            'checkInDate' => $this->checkInDate,
            'checkOutDate' => $this->checkOutDate,
            'sortKey' => 'price-asc',
            'returnFilter' => 0,
            'filter' => [
                'star' => [6, 7, 8, 9, 0],
                'price' => ['0-9999']
            ]
        ];
        $sign = [
            'apiKey' => $this->apiKey,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $sign['sign'] = $this->getSign($sign);
        return json_encode($sign);
    }

    protected function getLimit() {
        return $this->limit;
    }

    protected function post($url, $data) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json;charset='utf-8'"]);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data); //需要json数组
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $output = curl_exec($curl);
        curl_close($curl);
        return $this->res($output, $url, $data);

    }

    private function getSign($sign) {
        $this->propertyFilter($sign, $data);
        asort($data, SORT_REGULAR);
        $formatText = implode('&', $data);
        //在首尾加上秘钥
        $finalText = $this->secretKey . '&' . $formatText . '&' . $this->secretKey;
        return strtoupper(md5($finalText));
    }

    private function propertyFilter($input, &$return, $parentKey = null) {
        if (!is_array($input)) {
            return null;
        }
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $this->propertyFilter($value, $return, $key);
            } else {
                if (is_numeric($key) && $parentKey != null) {
                    $return[] = $parentKey . "=" . $value;
                } else {
                    $return[] = $key . "=" . $value;
                }
            }
        }
    }
}
