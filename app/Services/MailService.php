<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailService
{
    public function remindTraffic (User $user)
    {
        if (!$user->remind_traffic) return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!($user->expired_at !== NULL && ($user->expired_at - 86400) < time() && $user->expired_at > time())) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
               'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    public function orderComplete($order, $user, $plan, $payment)
    {
        // 格式化套餐时间
        $planTime = '';
        if ($order->period === 'onetime_price') {
            $planTime = '一次性流量包';
        } else {
            // 计算开始时间：对于续费订单，应该从用户之前的到期时间开始
            $startTime = $this->calculateStartTime($order, $user);
            $endTime = date('Y/m/d H:i:s', $user->expired_at);
            $planTime = $startTime . '-' . $endTime;
        }

        // 格式化订单时间
        $orderTime = date('Y/m/d H:i:s', $order->created_at);

        // 格式化订单金额（数据库存储的是分，需要转换为元）
        $orderAmount = '¥' . number_format($order->total_amount / 100, 2);

        // 获取支付方式名称
        $paymentMethod = $payment ? $payment->name : '余额支付';

        // 根据订单类型添加标识
        $planNameWithType = $this->getPlanNameWithType($plan->name, $order->type);

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '您在' . config('v2board.app_name', 'V2Board') . '的订单已完成',
            'template_name' => 'orderComplete',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'user_email' => $user->email,
                'order_trade_no' => $order->trade_no,
                'plan_name' => $planNameWithType,
                'plan_time' => $planTime,
                'order_time' => $orderTime,
                'order_amount' => $orderAmount,
                'payment_method' => $paymentMethod
            ]
        ]);
    }

    private function calculateStartTime($order, $user)
    {
        // 根据订单类型计算开始时间
        switch ((int)$order->type) {
            case 1: // 新购
                return date('Y/m/d H:i:s', $order->created_at);
            case 2: // 续费
                // 续费时，开始时间应该是用户之前的到期时间
                // 需要计算用户之前的到期时间
                $previousExpiredAt = $this->getPreviousExpiredAt($order, $user);
                return date('Y/m/d H:i:s', $previousExpiredAt);
            case 3: // 更换套餐
                return date('Y/m/d H:i:s', $order->created_at);
            default:
                return date('Y/m/d H:i:s', $order->created_at);
        }
    }

    private function getPreviousExpiredAt($order, $user)
    {
        // 计算用户之前的到期时间
        // 从当前到期时间减去本次购买的时间长度
        $currentExpiredAt = $user->expired_at;
        $periodMonths = $this->getPeriodMonths($order->period);
        
        if ($periodMonths > 0) {
            return strtotime('-' . $periodMonths . ' month', $currentExpiredAt);
        }
        
        return $order->created_at;
    }

    private function getPeriodMonths($period)
    {
        $periodMap = [
            'month_price' => 1,
            'quarter_price' => 3,
            'half_year_price' => 6,
            'year_price' => 12,
            'two_year_price' => 24,
            'three_year_price' => 36
        ];
        
        return $periodMap[$period] ?? 0;
    }

    private function getPlanNameWithType($planName, $orderType)
    {
        switch ((int)$orderType) {
            case 1: // 新购
                return $planName . '（新购）';
            case 2: // 续费
                return $planName . '（续费）';
            case 3: // 更换套餐
                return $planName . '（变更）';
            case 4: // 重置流量
                return $planName . '（重置流量）';
            default:
                return $planName;
        }
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) return false;
        if (!$transfer_enable) return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80) return false;
        if ($percentage >= 100) return false;
        return true;
    }
}
