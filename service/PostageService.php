<?php

/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/5/25
 * Time: 11:38
 */

namespace app\api\service;

use think\Db;

class PostageService
{

    public function calulatePost($tmep = [])
    {
        if (empty($tmep)) {
            return 0;
        }
        $post = 0;
        foreach ($tmep as $tid => $v) {
            $a_num = 0;
            $a_price = 0;
            $a_weight = 0;
            //0:数量1:单价2:重量
            foreach ($v as $goods) {
                $a_num += $goods[0];
                $a_price += ($goods[0] * $goods[1]);
                $a_weight += ($goods[0] * $goods[2]);
            }
            $res = Db::name('expressTemplate')->where('id', $tid)->find();
            //包邮
            if ($res['is_fs'] == 1) {
                if ($a_price >= $res['count_price']) {
                    continue;
                }
            }
            //按规则计算
            $template_content = json_decode($res['template_content'], true);
            $template = $template_content['express'];
            if ($res['method'] == 1) { //按件
                $post += $template['postage'];
                if ($a_num > $template['start']) {
                    $p_num = $a_num - $template['start']; //超出首部分
                    //向上取整
                    $post += ceil($p_num / $template['plus']) * $template['postageplus'];
                }
            } else if ($res['method'] == 2) { //重量
                $post += $template['postage'];
                if ($a_weight > $template['start']) {
                    $p_weight = $a_weight - $template['start']; //超出首部分
                    //向上取整
                    $post += ceil($p_weight / $template['plus']) * $template['postageplus'];
                }
            }
        }

        return $post;
    }

    // 商品详情里面的快递费
    static public function getPostageByID($tid, $goods_num = 1, $price = 0)
    {
        if ($tid){
            return 0;
        }
        $postage = 0;
        $res = Db::name('expressTemplate')->field('template_content,is_fs,count_price')->where('id', $tid)->find();
        if (empty($res)) {
            return $postage;
        }
        $template_content = json_decode($res['template_content'], true);
        $template = $template_content['express'];
        if ($template['plus'] > 0 && $goods_num > $template['start']) {
            $postage = $template['postage'] + intval($goods_num - $template['start'] / $template['start']) * $template['postageplus'];
        } else {
            $postage = $template['postage'];
        }
        if ($res['is_fs'] == 1) {
            if ($res['count_price'] <= $price) {
                $postage = 0;
            }
        }
        return $postage;
    }

    static public function getPostageDesc($tid)
    {
        $res = Db::name('expressTemplate')->where('id', $tid)->find();
        $template_content = json_decode($res['template_content'], true);
        $template = $template_content['express'];
        if ($res['method'] == 1) {
            $base = $template['start'] . '件内,运费' . $template['start'] . '元；<br/>每增加' . $template['plus'] . '件,增加运费' . $template['postageplus'] . '元；';
        } else {
            $base = '首重' . $template['start'] . 'kg,运费' . $template['start'] . '元；<br/>每增加' . $template['plus'] . 'kg,增加运费' . $template['postageplus'] . '元；';
        }
        if ($res['is_fs'] == 1) {
            $free = '<br/>满' . $res['count_price'] . "元包邮；";
        } else {
            $free = '';
        }
        return $base . $free;
    }
}
