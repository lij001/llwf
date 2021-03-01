<?php
namespace app\api\service;

use think\Db;
use cmf\phpqrcode\QRcode;

class MissionService
{
    /**
     * 
     * @param  int  $uid 我
     * @param  int $my_star 我的当前星级
     * @param  int $my_inviter 我的X级推荐人
     * @return number
     */
    public function checkMission($uid,$my_star,$my_inviter){
        //发视频
        $copunt1 = Db::name("my_mission")->where("uid", $uid)->where('star',$my_star)->where('type',1)->count();
        if($copunt1 < 1){
            return 0;
        }
        
        //打赏推荐人
        $copunt2 = Db::name("my_mission")->where("uid", $uid)->where('star',$my_star)->where('type',2)->count();
        
        if($copunt2 < 1){
            return 0;
        }
        if($my_star== 0 || $my_star== 4 || $my_star== 9){
            //打赏网咖
            $copunt3 = Db::name("my_mission")->where("uid", $uid)->where('star',$my_star)->where('type',3)->count();
            
            if($copunt3 < 1){
                return 0;
            }
        }
        
        //关注推荐人
        $copunt4 = Db::name("video_focus")->where('uid',$uid)->where('bei_uid',$my_inviter)->count();
        if($copunt4 < 1){
            return 0;
        }
        return 1;
    }
    /**
     *
     * @param  int  $uid 我
     * @param  string $path 我的当前星级
     * @param  int  $star 星级
     * @return number
     */
    public function checkTeam($uid,$path,$star){
        $mypath=$uid."-".$path;
        $config = Db::name('config_reward')->where('star',$star)->where('type',7)->find();
        
        //团队总有效人数(有效:星级大于0)
        $total_count=Db::name("members")->where("path like '%{$mypath}'")->where('star','>',0)->count("id");
        if($config['star2']>$total_count){
            return 0;
        }
        //直属有效人数(有效:星级大于0)
        $zs_count=Db::name("members")->where("path",$mypath)->where('star','>',0)->count('id');
        if($config['star1']>$zs_count){
            return 0;
        }
        return 1;
    }
    /**
     * @Description 今天挖取的金币是否超过阀值
     * @param  int  $uid 我
     * @return number
     */
    public function checkTodayCoin($uid,$coin){
        $today = strtotime(date('Y-m-d'));
        $today_coin = Db::name('my_coin_log')->where("uid",$uid)->where('create_time','>=',$today)->where('type',2)->sum('coin');
        $config_coin = Db::name('config_reward')->where('type',4)->value('coin');
        $can_max_coin =$config_coin-$today_coin;
        if(($today_coin+$coin) <= $config_coin){
            return $coin;
        }else if($can_max_coin >0){
            return $can_max_coin;
        }else{
            return 0;
        }
    }
    
    /**
     * @Description 今天挖币的次数是否超过阀值
     * @param  int  $uid 我
     * @return number
     */
    public function checkTodayCoinTime($uid){
        $today = strtotime(date('Y-m-d'));
        $today_count = Db::name('my_coin_log')->where("uid",$uid)->where('create_time','>=',$today)->where('type',2)->count('id');
        $config_time = Db::name('config_reward')->where('type',4)->value('coin_time');
        
        if($today_count < $config_time){
            return 1;
        }else{
            return 0;
        }
    }
}

