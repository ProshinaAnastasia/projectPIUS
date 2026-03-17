<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TelegramBotController extends Controller
{
    public function handle(Request $request)
    {
        $messageText = $request->input('message.text');
        $chatId = $request->input('message.chat.id');
        $telegramId = $request->input('message.from.id');

        if (!$messageText || !$chatId || !$telegramId) {
            return response()->json(['status' => 'ignored']);
        }

        $userKey = "user_settings_{$telegramId}";
        $settings = Cache::get($userKey, []);

        if (isset($settings['state'])) {
            if ($settings['state'] === 'awaiting_movie_input') {
                $settings['movie'] = $messageText;
                unset($settings['state']);
                Cache::put($userKey, $settings, now()->addMinutes(15));
                $this->sendMessage($chatId, "✅ Фильм установлен: {$messageText}");
                return response()->json(['status' => 'movie_input_saved']);
            } elseif ($settings['state'] === 'awaiting_style_input') {
                $settings['style'] = $messageText;
                unset($settings['state']);
                Cache::put($userKey, $settings, now()->addMinutes(15));
                $this->sendMessage($chatId, "✅ Стиль установлен: {$messageText}");
                return response()->json(['status' => 'style_input_saved']);
            } else {
                unset($settings['state']);
                return response()->json(['status' => 'invalid_state']);
            }
        } elseif ($messageText === '/start') {
            $startText = <<<TEXT
👋 Привет! Я бот, который может пересказать фильм от лица твоего бухого деда.

Доступные команды:

🎬 /set_movie – Выбрать фильм для пересказа.

🎭 /set_style – Выбрать кастомный стиль (режиссёр, жанр и т.д.).
Стиль по умолчанию - 'Бухой дед'.

📤 /generate_summary – Сгенерировать пересказ фильма.

ℹ️ /info – Посмотреть информацию о себе: подписка, лимиты и текущие настройки.

💎 /subscribe – Оформить подписку (даёт больше запросов в день).
TEXT;

            $this->sendMessage($chatId, $startText);
            return response()->json(['status' => 'start_command_handled']);
        } elseif ($messageText === '/info') {
            $infoResponse = Http::withoutVerifying()
                ->timeout(60)
                ->post(env('DATABASE_USER_INFO_URL'), [
                    'telegram_id' => $telegramId,
                ]);

            if ($infoResponse->failed()) {
                $this->sendMessage($chatId, '❌ Не удалось получить информацию.');
                return response()->json(['status' => 'user_info_fetch_failed']);
            }

            $userInfo = $infoResponse->json();
            $movie = $settings['movie'] ?? '-';
            $style = $settings['style'] ?? 'Бухой дед';

            $subscriptionStatus = $userInfo['has_subscription']
                ? '✅ Активна'
                : '❌ Не Активна';
            $maxRequests = $userInfo['max_requests_per_day'] ?? 'Неизвестно';

            $infoText = <<<TEXT
ℹ️ Информация о пользователе:

🔹 telegram_id: {$telegramId}
🔹 Подписка: {$subscriptionStatus}
TEXT;

            if ($userInfo['has_subscription']) {
                $formattedDate = Carbon::parse($userInfo['subscription_end_date'])
                    ->format('d.m.Y');
                $infoText .= " (до {$formattedDate})";
            }

            $infoText .= <<<TEXT

🔹 Лимит запросов в день: {$maxRequests}
🔹 Запросов за сегодня: {$userInfo['todays_requests_count']}

🎬 Фильм: {$movie}
🎭 Стиль: {$style}
TEXT;

            $this->sendMessage($chatId, $infoText);
            return response()->json(['status' => 'user_info_sent']);
        } elseif ($messageText === '/subscribe') {
            $this->sendMessage($chatId, '🚧 Извините, данный сервис пока что не доступен');
            return response()->json(['status' => 'subscription_success']);
        } elseif ($messageText === '/set_movie') {
            $this->sendMessage($chatId, "🎬 Введите название фильма:");
            $settings['state'] = 'awaiting_movie_input';
            Cache::put($userKey, $settings, now()->addMinutes(15));
            return response()->json(['status' => 'awaiting_movie_input']);
        } elseif ($messageText === '/set_style') {
            $this->sendMessage(
                $chatId,
                "🎨 Введите стиль:\n(Для того, чтобы выбрать стиль по умолчанию, введите 'Бухой дед')"
            );
            $settings['state'] = 'awaiting_style_input';
            Cache::put($userKey, $settings, now()->addMinutes(15));
            return response()->json(['status' => 'awaiting_style_input']);
        } elseif ($messageText === '/generate_summary') {
            $movie = $settings['movie'] ?? '';
            $style = $settings['style'] ?? 'Бухой дед';
            if (empty($movie)) {
                $this->sendMessage($chatId, "⚠️ Укажите фильм с помощью /set_movie.");
                return response()->json(['status' => 'incomplete_settings']);
            }

            $this->sendMessage($chatId, '🔄 Проверка лимитов запросов...');
            $limitResponse = Http::withoutVerifying()
                ->timeout(60)
                ->post(env('DATABASE_CHECK_LIMITS_URL'), [
                    'telegram_id' => $telegramId,
                ]);

            if ($limitResponse->failed()) {
                $this->sendMessage($chatId, '❌ Ошибка связи с сервисом проверки лимитов.');
                return response()->json(['status' => 'user_limits_fetch_failed']);
            }

            $requestsCount = $limitResponse->json('todays_requests_count');
            $maxRequests = $limitResponse->json('max_requests_per_day');

            if ($requestsCount >= $maxRequests) {
                $this->sendMessage(
                    $chatId,
                    "🚫 Вы достигли лимита запросов в день! ({$requestsCount}/{$maxRequests})"
                );
                return response()->json(['status' => 'limited']);
            }

            $this->sendMessage(
                $chatId,
                "✅ Лимиты не превышены! ({$requestsCount}/{$maxRequests})"
            );

            $this->sendMessage(
                $chatId,
                "🛠 Генерация пересказа...\n🎬 Фильм: {$movie}\n🎭 Стиль: {$style}\n\n"
                . "P.S. Обычно это занимает примерно 30 сек."
            );

            if ($style == 'Бухой дед') {
                $prompt = <<<TEXT
Представь, что ты русский дед, который воевал во второй мировой войне.
Ты любишь СССР, Сталина и водку.
Ты ненавидишь Америку, Европу, Обаму, Байдена, негров, геев и фашистов.
Ты часто упоминаешь, что раньше(в СССР) было лучше.
Также ты часто говоришь, что Америка и Европа - загнивающие страны,
там гораздо хуже, чем в матушке-России.
Используй устареший диалект и просторечия("ихний", "евонный" и т. д.).
В соответствии с этим образом перескажи фильм '{$movie}'.
TEXT;
            } else {
                $prompt = "Перескажи фильм '{$movie}' в стиле '{$style}'.";
            }

            $generationResponse = Http::withoutVerifying()
                ->timeout(180)
                ->post(env('DEEPSEEK_SERVICE_URL'), [
                    'prompt' => $prompt,
                ]);

            if ($generationResponse->failed()) {
                $this->sendMessage($chatId, '❌ Ошибка генерации текста. Попробуйте позже.');
                return response()->json(['status' => 'text_generation_failed']);
            }

            $generatedText = $generationResponse->json('text');
            if (empty($generatedText)) {
                $this->sendMessage($chatId, '❌ Ошибка генерации текста. Попробуйте позже.');
                return response()->json(['status' => 'text_generation_failed']);
            }

            $this->sendMessage($chatId, $generatedText);

            $updateResponse = Http::withoutVerifying()
                ->timeout(60)
                ->post(env('DATABASE_INCREMENT_LIMITS_URL'), [
                    'telegram_id' => $telegramId,
                ]);

            if ($updateResponse->failed()) {
                $this->sendMessage($chatId, '❌ Ошибка связи с базой данных.');
                return response()->json(['status' => 'user_request_update_failed']);
            }

            return response()->json(['status' => 'summary_generated']);
        } else {
            $availableCommandsText = <<<TEXT
Доступные команды:

🎬 /set_movie – Выбрать фильм для пересказа.

🎭 /set_style – Выбрать кастомный стиль для пересказа

📤 /generate_summary – Сгенерировать пересказ фильма.

ℹ️ /info – Посмотреть информацию о себе: подписка, лимиты и текущие настройки.

💎 /subscribe – Оформить подписку (даёт больше запросов в день).
TEXT;

            $this->sendMessage($chatId, $availableCommandsText);
            return response()->json(['status' => 'unknown_command']);
        }
    }

    private function sendMessage($chatId, $text)
    {
        try {
            Http::withoutVerifying()
                ->timeout(60)
                ->post(
                    "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage",
                    ['chat_id' => $chatId, 'text' => $text]
                );
        } catch (\Exception $e) {
            \Log::error("Ошибка отправки Telegram-сообщения: " . $e->getMessage());
        }
    }

    public function setBotCommands()
    {
        $commands = [
            [
                'command' => 'start',
                'description' => 'Вывести общую информацию о боте',
            ],
            [
                'command' => 'set_movie',
                'description' => 'Выбрать фильм для пересказа',
            ],
            [
                'command' => 'set_style',
                'description' => 'Выбрать кастомный стиль для пересказа',
            ],
            [
                'command' => 'generate_summary',
                'description' => 'Сгенерировать пересказ фильма',
            ],
            [
                'command' => 'info',
                'description' => 'Показать информацию о подписке, лимитах и настройках',
            ],
            [
                'command' => 'subscribe',
                'description' => 'Оформить подписку для увеличения лимита запросов',
            ],
        ];

        try {
            $response = Http::withoutVerifying()
                ->timeout(60)
                ->post(
                    "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/setMyCommands",
                    [
                        'commands' => json_encode($commands),
                        'scope' => json_encode(['type' => 'all_private_chats']),
                        'language_code' => 'ru',
                    ]
                );

            if ($response->successful()) {
                \Log::info("Команды бота успешно зарегистрированы.");
                return response()->json(['status' => 'commands_set']);
            } else {
                \Log::error("Ошибка регистрации команд бота: " . $response->body());
                return response()->json(['status' => 'commands_set_failed'], 500);
            }
        } catch (\Exception $e) {
            \Log::error("Исключение при регистрации команд бота: " . $e->getMessage());
            return response()->json(['status' => 'commands_set_exception'], 500);
        }
    }
}
