<?php

namespace App\Http\Controllers;

use App\Services\YandexReviewsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class YandexIntegrationController extends Controller
{
    public function __construct(
        protected YandexReviewsService $reviewsService
    ) {}

    /**
     * Показать страницу интеграции: форма ввода URL + отзывы.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user?->yandex_place_url) {
            return $this->renderEmpty();
        }

        try {
            $data = $this->reviewsService->fetchByUrl($user->yandex_place_url);

            return Inertia::render('yandex/Integration', [
                'yandexUrl' => $user->yandex_place_url,
                'company' => [
                    'name' => $data['companyName'],
                    'logo' => $data['companyLogo'] ?? null,
                    'rating' => $data['rating'],
                    'reviewsCount' => $data['reviewsCount'],
                ],
                'reviews' => $data['reviews'],
                'photos' => $data['photos'],
                'hasMoreReviews' => $data['hasMoreReviews'],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Ошибка загрузки данных Яндекс.Карт', [
                'user_id' => $user->id,
                'url' => $user->yandex_place_url,
                'error' => $e->getMessage(),
            ]);

            return Inertia::render('yandex/Integration', [
                'yandexUrl' => $user->yandex_place_url,
                'company' => null,
                'reviews' => [],
                'photos' => [],
                'hasMoreReviews' => false,
                'error' => 'Не удалось загрузить данные. Попробуйте обновить позже.',
            ]);
        }
    }

    /**
     * Сохранить / обновить ссылку на Яндекс.Карты.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'yandex_url' => [
                'required',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail) {
                    // Проверяем хост
                    $parsed = parse_url($value);
                    $host = $parsed['host'] ?? '';

                    $allowedHosts = ['yandex.ru', 'yandex.com', 'maps.yandex.ru', 'maps.yandex.com'];

                    if (! in_array($host, $allowedHosts, true)) {
                        $fail('Ссылка должна вести на Яндекс.Карты (yandex.ru/maps или maps.yandex.ru).');
                        return;
                    }

                    $path = $parsed['path'] ?? '';
                    $isMapsPath = str_contains($path, '/maps');
                    $isMapsHost = str_starts_with($host, 'maps.');

                    if (! $isMapsPath && ! $isMapsHost) {
                        $fail('Ссылка должна вести на Яндекс.Карты (содержать /maps в адресе).');
                        return;
                    }

                    // Проверяем, что можно извлечь OID организации
                    $placeId = $this->reviewsService->extractPlaceIdFromUrl($value);
                    if (! $placeId) {
                        $fail('Не удалось определить организацию по этой ссылке. Убедитесь, что ссылка ведёт на конкретное заведение.');
                    }
                },
            ],
        ]);

        $user = $request->user();

        // Сбрасываем кэш старого URL при смене ссылки
        if ($user->yandex_place_url && $user->yandex_place_url !== $data['yandex_url']) {
            $this->reviewsService->clearCache($user->yandex_place_url);
        }

        $user->update([
            'yandex_place_url' => $data['yandex_url'],
        ]);

        return back()->with('status', 'yandex-url-updated');
    }

    /**
     * Принудительно обновить данные из Яндекс.Карт (сбросить кэш).
     */
    public function refresh(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user?->yandex_place_url) {
            return back();
        }

        $this->reviewsService->clearCache($user->yandex_place_url);

        // Перезагружаем данные (сервис сделает свежий запрос, т.к. кэш сброшен)
        return back()->with('status', 'yandex-data-refreshed');
    }

    /**
     * AJAX: Подгрузить дополнительные отзывы (следующая страница).
     */
    public function loadMore(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'required|integer|min:2',
        ]);

        $user = $request->user();

        if (! $user?->yandex_place_url) {
            return response()->json(['reviews' => [], 'hasMoreReviews' => false]);
        }

        try {
            $data = $this->reviewsService->fetchMoreReviews(
                $user->yandex_place_url,
                (int) $request->input('page')
            );

            return response()->json($data);
        } catch (\Throwable $e) {
            Log::error('Ошибка подгрузки отзывов', [
                'user_id' => $user->id,
                'page' => $request->input('page'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'reviews' => [],
                'hasMoreReviews' => false,
                'error' => 'Не удалось загрузить дополнительные отзывы.',
            ], 422);
        }
    }

    /**
     * Пустая страница (нет URL).
     */
    private function renderEmpty(): Response
    {
        return Inertia::render('yandex/Integration', [
            'yandexUrl' => null,
            'company' => null,
            'reviews' => [],
            'photos' => [],
            'hasMoreReviews' => false,
            'error' => null,
        ]);
    }
}
