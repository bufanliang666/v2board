<?php

namespace App\Plugins\Telegram\Commands;

use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\StatisticalService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use function PHPSTORM_META\map;



class GetSiteInfo extends Telegram
{
    public $command = '/GetSiteInfo';
    public $description = '查询站点信息';

    //获取今日用户流量排行
    public function getUserTodayRank()
    {
        $startAt = strtotime('today');
        $endAt = time();
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        
        $data = [];
        $idIndexMap = [];

        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;

            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }

        array_multisort(array_column($data, 'total'), SORT_DESC, $data);

        return [
            'data' => array_slice($data, 0, 6)
        ];
    }
    
    //获取用户昨日流量排行
    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        
        $data = [];
        $idIndexMap = [];

        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;

            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }

        array_multisort(array_column($data, 'total'), SORT_DESC, $data);

        return [
            //排行人数
            'data' => array_slice($data, 0, 6)
        ];
    }

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        
        // 如果不是私聊，则直接返回
        if (!$message->is_private) {
            return;
        }

        // 获取当前用户信息
        $user = User::where('telegram_id', $message->chat_id)->first();

        // 如果用户不存在，则提示绑定账号
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }
        //判断是否为管理员
        if (!$user->is_admin) {
            $telegramService->sendMessage($message->chat_id, '您不是授权用户，无法执行此操作', 'markdown');
            return;
        }

        
        // 查询十分钟内在线用户数量
        $tenMinutesAgo = now()->subMinutes(10)->timestamp;
        //今日零点时间戳
        $todayStartTimestamp = strtotime('today');
        //本月的零点时间戳
        $monthStartTimestamp = strtotime('first day of this month midnight');
        
        //查询10分钟在线用户数量
        $onlineUserCount = User::where('t', '>=', $tenMinutesAgo)->count();
        // 查询今日订单数量
        $dailyOrders = Order::where('status', 3)
        ->where('created_at', '>=', $todayStartTimestamp)
        ->count();
        // 查询日收入的 SQL 语句
        $dailyIncome = Order::where('status', 3)
        ->where('created_at', '>=', $todayStartTimestamp)
        ->sum('total_amount') * 0.01;
        // 查询本月收入的 SQL 语句
        $monthlyIncome = Order::where('status', 3)
        ->where('created_at', '>=', $monthStartTimestamp)
        ->sum('total_amount') * 0.01;
        // 查询今日新注册用户数量
        $dailyNewUsers = User::where('created_at', '>=', $todayStartTimestamp)->count();
        // 查询本月新注册用户数量
        $monthlyNewUsers = User::where('created_at', '>=', $monthStartTimestamp)->count();
        
        // 获取今日用户流量排行
        $userRankToday = $this->getUserTodayRank();
        // 获取昨日用户流量排行
        $userRankYesterday = $this->getUserLastRank();        
    
        // 构建流量信息文本
        $text = "📊在线收支情况";
        $text .= "\n———————————————\n";
        $text .= "👨‍💻实时在线：`{$onlineUserCount}`人\n";
        $text .= "🏷今日订单：`{$dailyOrders}`个\n";
        $text .= "💰今日收入：`{$dailyIncome} `元\n";
        $text .= "💰本月收入：`{$monthlyIncome} `元\n";
        $text .= "👤今日注册：`{$dailyNewUsers}`人\n";
        $text .= "👤本月注册：`{$monthlyNewUsers}`人\n";
        $text .= "\n\n";
        
        // 添加昨日用户流量排行信息
        $text .= "\n🔙昨日用户流量排行 Top 6";
        $text .= "\n———————————————\n";
        foreach ($userRankYesterday['data'] as $index => $userInfo) {
            $totalFormatted = sprintf('%.2f', $userInfo['total']);
            $text .= ($index + 1) . ". {$userInfo['email']}：{$totalFormatted} GB\n";
        }
        $text .= "\n";

        // 添加今日用户流量排行信息
        $text .= "🚀 今日用户流量排行 Top 6\n";
        foreach ($userRankToday['data'] as $index => $userInfo) {
            $totalFormatted = sprintf('%.2f', $userInfo['total']);
            $text .= ($index + 1) . ". {$userInfo['email']}：{$totalFormatted} GB\n";
        }        

        // 发送消息给 Telegram 用户
        $telegramService->sendMessage($message->chat_id, $text, 'markdown');


    }
}
