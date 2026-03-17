<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DatabaseController extends Controller
{
    public function userInfo(Request $request)
    {
        $telegramId = $request->input('telegram_id');
        if (!$telegramId) {
            return response()->json(['error' => 'telegram_id is required'], 400);
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'subscription_end_date' => '2000-01-01',
                'todays_requests_count' => 0,
                'last_request_date' => '2000-01-01',
            ]
        );

        $today = Carbon::today();
        $hasSubscription = $user->subscription_end_date->gt($today);
        $maxRequests = $hasSubscription ? 50 : 10;

        if ($user->last_request_date->lt($today)) {
            $user->todays_requests_count = 0;
            $user->save();
        }

        return response()->json([
            'telegram_id' => $user->telegram_id,
            'has_subscription' => $hasSubscription,
            'subscription_end_date' => $user->subscription_end_date,
            'todays_requests_count' => $user->todays_requests_count,
            'max_requests_per_day' => $maxRequests,
        ]);
    }

    public function subscribe(Request $request)
    {
        $telegramId = $request->input('telegram_id');
        if (!$telegramId) {
            return response()->json(['error' => 'telegram_id is required'], 400);
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'subscription_end_date' => '2000-01-01',
                'todays_requests_count' => 0,
                'last_request_date' => '2000-01-01',
            ]
        );

        $user->subscription_end_date = now()->addMonth()->toDateString();
        $user->save();

        return response()->json([
            'status' => 'subscribed',
            'subscription_end_date' => $user->subscription_end_date,
        ]);
    }

    public function resetLimits(Request $request)
    {
        $telegramId = $request->input('telegram_id');
        if (!$telegramId) {
            return response()->json(['error' => 'telegram_id is required'], 400);
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'subscription_end_date' => '2000-01-01',
                'todays_requests_count' => 0,
                'last_request_date' => '2000-01-01',
            ]
        );

        $user->todays_requests_count = 0;
        $user->save();

        return response()->json(['status' => 'limits reseted']);
    }

    public function checkLimits(Request $request)
    {
        $telegramId = $request->input('telegram_id');
        if (!$telegramId) {
            return response()->json(['error' => 'telegram_id is required'], 400);
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'subscription_end_date' => '2000-01-01',
                'todays_requests_count' => 0,
                'last_request_date' => '2000-01-01',
            ]
        );

        $today = Carbon::today();
        $hasSubscription = $user->subscription_end_date->gt($today);
        $maxRequests = $hasSubscription ? 50 : 10;

        if ($user->last_request_date->lt($today)) {
            $user->todays_requests_count = 0;
            $user->save();
        }

        return response()->json([
            'todays_requests_count' => $user->todays_requests_count,
            'max_requests_per_day' => $maxRequests,
        ]);
    }

    public function incrementLimits(Request $request)
    {
        $telegramId = $request->input('telegram_id');
        if (!$telegramId) {
            return response()->json(['error' => 'telegram_id is required'], 400);
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'subscription_end_date' => '2000-01-01',
                'todays_requests_count' => 0,
                'last_request_date' => '2000-01-01',
            ]
        );

        $user->todays_requests_count += 1;
        $user->last_request_date = Carbon::today();
        $user->save();

        return response()->json(['status' => 'limits incremented']);
    }
}
