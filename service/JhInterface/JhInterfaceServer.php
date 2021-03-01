<?php
namespace app\api\service\JhInterface;

abstract class JhInterfaceServer{
    protected $openId='JHe2a6463cb38731776770e6d01914916c';
    abstract protected function sign($data);
    abstract protected function url($url,$data);
    
}