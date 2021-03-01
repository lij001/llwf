<?php

namespace app\api\service\AliPay\Fund;

use app\api\service\AliPay\Kernel\BaseClient;

class Trans extends BaseClient
{
    /**
     * 单笔转账接口
     * @param array $options
     * @return array|bool|mixed
     * @throws \App\Services\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \App\Services\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function apply($options = [])
    {
        $this->setAppCertSnAndRootCertSn();
        $this->options->set('method', 'alipay.fund.trans.uni.transfer');

        return $this->getResult($options);
    }

    /**
     * 转账业务单据查询接口
     * @param array $options
     * @return array|bool
     * @throws \App\Services\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \App\Services\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function CommonQuery($options = [])
    {
        $this->setAppCertSnAndRootCertSn();
        $this->options->set('method', 'alipay.fund.trans.common.query');
        return $this->getResult($options);
    }

    /**
     * 支付宝资金账户资产查询接口
     * @param array $options
     * @return array|bool
     * @throws \App\Services\AliPay\Kernel\Exceptions\InvalidResponseException
     * @throws \App\Services\AliPay\Kernel\Exceptions\LocalCacheException
     */
    public function AccountQuery($options=[])
    {
        $this->setAppCertSnAndRootCertSn();
        $this->options->set('method', 'alipay.fund.account.query');
        return $this->getResult($options);
    }

    public function apppay($options = [])
    {
        $this->setAppCertSnAndRootCertSn();
        $this->options->set('method', 'alipay.trade.app.pay');
        return $this->getResult($options);
    }
}
