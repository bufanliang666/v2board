<?php

namespace App\Payments;

use \Curl\Curl;

class EpusdtPay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'epusdt_pay_url' => [
                'label' => 'API 地址',
                'description' => '您的 EpusdtPay API 接口地址 (例如: https://epusdt-pay.xxx.com)',
                'type' => 'input',
            ],
            'epusdt_pay_apitoken' => [
                'label' => 'API Token',
                'description' => '您的 EpusdtPay API Token',
                'type' => 'input',
            ],
            'trade_type' => [
                'label' => '支付方式',
                'description' => '请输入支付方式 (usdt.trc20, tron.trx, usdt.polygon)',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $params = [
            "address" => "", // 可留空，或根据实际情况传入收款地址
            "trade_type" => $this->config['trade_type'] ?? 'usdt.trc20', // 默认 usdt.trc20
            "order_id" => $order['trade_no'],
            "amount" => round($order['total_amount'] / 100, 2),
            "notify_url" => $order['notify_url'],
            "redirect_url" => $order['return_url'],
        ];
        $params['signature'] = $this->sign($params);

        $curl = new Curl();
        $curl->setUserAgent('EpusdtPay');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $curl->post($this->config['epusdt_pay_url'] . '/api/v1/order/create-transaction', json_encode($params));
        $result = $curl->response;
        $curl->close();

        if (!isset($result->status_code) || $result->status_code != 200) {
            abort(500, "Failed to create order. Error: " . ($result->message ?? 'Unknown error'));
        }

        return [
            'type' => 1, // 0: qrcode, 1: url
            'data' => $result->data->payment_url ?? '',
        ];
    }

    public function notify($params)
    {
        if ($params['status'] != 2) {
            die('failed');
        }
        if (!$this->verify($params)) {
            die('cannot pass verification');
        }
        return [
            'trade_no' => $params['order_id'],
            'callback_no' => $params['trade_id'],
            'custom_result' => 'ok',
        ];
    }
    
    public function verify($params) {
        return $params['signature'] === $this->sign($params);
    }

    protected function sign(array $params)
    {
        ksort($params);
        reset($params);
        $sign = '';
        foreach ($params as $key => $val) {
            if ($val == '' || $key == 'signature') continue;
            $sign .= "$key=$val&";
        }
        $sign = rtrim($sign, '&');
        return md5($sign . $this->config['epusdt_pay_apitoken']);
    }
}
