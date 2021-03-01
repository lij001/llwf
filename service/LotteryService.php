<?php


namespace app\api\service;


use app\api\BaseService;

class LotteryService extends BaseService {
    function get_gift(){
        //拼装奖项数组
        // 奖项id，奖品，概率
        $prize_arr = array(
            '0' => array('id'=>1,'prize'=>'平板电脑','v'=>10),
            '1' => array('id'=>2,'prize'=>'数码相机','v'=>20),
            '2' => array('id'=>3,'prize'=>'音箱设备','v'=>30),
            '3' => array('id'=>4,'prize'=>'4G优盘','v'=>40),
            '4' => array('id'=>5,'prize'=>'10Q币','v'=>50),
            '5' => array('id'=>6,'prize'=>'空奖','v'=>60),
        );
        foreach ($prize_arr as $key => $val) {
            $arr[$val['id']] = $val['v'];//概率数组
        }
        $rid = $this->get_rand($arr); //根据概率获取奖项id
        $res['yes'] = $prize_arr[$rid-1]['prize']; //中奖项
        unset($prize_arr[$rid-1]); //将中奖项从数组中剔除，剩下未中奖项
        shuffle($prize_arr); //打乱数组顺序
        for($i=0;$i<count($prize_arr);$i++){
            $pr[] = $prize_arr[$i]['prize']; //未中奖项数组
        }
        $res['no'] = $pr;


        if($res['yes']!='空奖'){
            $result['status']=1;
            $result['name']=$res['yes'];
        }else{
            $result['status']=-1;
            $result['msg']=$res['yes'];
        }
        //return $result;
        dump($result);
    }

    //计算中奖概率
    function get_rand($proArr) {
        $result = '';
        //概率数组的总概率精度
        $proSum = array_sum($proArr);
        //概率数组循环
        foreach ($proArr as $key => $proCur) {
            $randNum = mt_rand(1, $proSum); //返回随机整数
            if ($randNum <= $proCur) {
                $result = $key;
                break;
            } else {
                $proSum -= $proCur;
            }
        }
        unset ($proArr);
        return $result;
    }
}
