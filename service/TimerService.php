<?php
namespace app\api\service;

use cmf\queue\connector\Database;

class TimerService
{
    public function cancleOrderJob(){
        
    $dat = new Database();
    
    $jobHandlerClassName  = 'api\timer\can\Hello';
    
    // 2.当前任务归属的队列名称，如果为新队列，会自动创建
    $jobQueueName     = "cancleOrderQueue";
    
    // 3.当前任务所需的业务数据 . 不能为 resource 类型，其他类型最终将转化为json形式的字符串
    $jobData          = [ 'ts' => time(), 'bizId' => uniqid() , 'data' => $_GET ] ;
    }
}

