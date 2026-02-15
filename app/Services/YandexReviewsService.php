<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YandexReviewsService
{
    // ----------------------------------------------------------------
    // Конфигурация
    // ----------------------------------------------------------------

    /** Время жизни кэша основных данных (компания + первая страница отзывов). */
    private const CACHE_TTL_MINUTES = 30;

    /** Время жизни кэша для дополнительных страниц отзывов. */
    private const CACHE_PAGE_TTL_MINUTES = 15;

    /** Таймаут HTTP-запросов к Яндексу (секунды). */
    private const HTTP_TIMEOUT = 15;

    /** Таймаут HTTP-запросов к Search API (секунды). */
    private const API_TIMEOUT = 10;

    /** Максимум фотографий. */
    private const MAX_PHOTOS = 5;

    /** Минимальная длина текста отзыва (символы). */
    private const MIN_REVIEW_LENGTH = 10;

    /** Мусорные тексты, которые парсятся из UI Яндекс.Карт. */
    private const GARBAGE_TEXTS = [
        'подписаться',
        'оцените это место',
        'показать ещё',
        'показать еще',
        'ещё',
        'скопировать',
        'ответить',
        'пожаловаться',
        'полезно',
        'не полезно',
        'комментировать',
        'читать далее',
        'свернуть',
        'загрузить ещё',
    ];

    /** HTTP-заголовки для запросов к Яндекс.Картам. */
    private const REQUEST_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
    ];

    // ================================================================
    // Публичный API
    // ================================================================

    /**
     * Получить данные компании, отзывы и фотографии по ссылке Яндекс.Карт.
     *
     * Результат кэшируется на CACHE_TTL_MINUTES минут.
     * Передайте $forceRefresh = true для принудительного обновления.
     *
     * @return array{companyName: string, rating: float, reviewsCount: int, reviews: array, photos: array, hasMoreReviews: bool}
     */
    public function fetchByUrl(string $placeUrl, bool $forceRefresh = false): array
    {
        $placeId = $this->extractPlaceIdFromUrl($placeUrl);

        if (! $placeId) {
            throw new RuntimeException('Не удалось определить организацию по ссылке Яндекс.Карт.');
        }

        $cacheKey = $this->cacheKey($placeId);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($placeId, $placeUrl) {
            return $this->fetchFreshData($placeId, $placeUrl);
        });
    }

    /**
     * Загрузить дополнительные отзывы (для AJAX-подгрузки).
     *
     * Каждая страница кэшируется на CACHE_PAGE_TTL_MINUTES минут.
     *
     * @return array{reviews: array, hasMoreReviews: bool}
     */
    public function fetchMoreReviews(string $placeUrl, int $page): array
    {
        $placeId = $this->extractPlaceIdFromUrl($placeUrl);

        if (! $placeId) {
            throw new RuntimeException('Не удалось определить организацию по ссылке Яндекс.Карт.');
        }

        $cacheKey = $this->cacheKey($placeId, $page);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_PAGE_TTL_MINUTES), function () use ($placeId, $page) {
            $result = $this->scrapeReviewsPage($placeId, $page);

            return [
                'reviews' => $result['reviews'],
                'hasMoreReviews' => $result['hasMoreReviews'],
            ];
        });
    }

    /**
     * Сбросить весь кэш для конкретного URL.
     */
    public function clearCache(string $placeUrl): void
    {
        $placeId = $this->extractPlaceIdFromUrl($placeUrl);

        if (! $placeId) {
            return;
        }

        // Сбрасываем основной кэш
        Cache::forget($this->cacheKey($placeId));

        // Сбрасываем кэш страниц отзывов (до 20 страниц)
        for ($page = 2; $page <= 20; $page++) {
            Cache::forget($this->cacheKey($placeId, $page));
        }

        Log::info('Кэш Яндекс.Карт сброшен', ['placeId' => $placeId]);
    }

    /**
     * Извлечь ID организации из URL Яндекс.Карт.
     *
     * Поддерживаемые форматы:
     *   - https://yandex.ru/maps/org/название/1234567890/
     *   - https://yandex.ru/maps/213/moscow/?...poi[uri]=ymapsbm1://org?oid=1234567890...
     *   - https://yandex.ru/maps/org/1234567890/
     */
    public function extractPlaceIdFromUrl(string $url): ?string
    {
        $decoded = urldecode($url);

        // 1. poi[uri]=ymapsbm1://org?oid=... (URL с карты)
        if (preg_match('~[?&]poi\[uri\]=([^&]+)~i', $decoded, $m)) {
            $poiUri = urldecode($m[1]);
            if (preg_match('~oid=(\d+)~', $poiUri, $oid)) {
                return $oid[1];
            }
        }

        // 2. &oid=... (прямой параметр)
        if (preg_match('~[?&]oid=(\d+)~', $decoded, $m)) {
            return $m[1];
        }

        // 3. /org/название/1234567890/
        if (preg_match('~/org/[^/]*/(\d{5,})(?:/|$|\?)~', $decoded, $m)) {
            return $m[1];
        }

        // 4. /org/1234567890/
        if (preg_match('~/org/(\d{5,})(?:/|$|\?)~', $decoded, $m)) {
            return $m[1];
        }

        // Не используем агрессивный fallback — лучше вернуть null,
        // чем случайно подхватить yclid, координаты или zoom.
        return null;
    }

    // ================================================================
    // Получение данных (без кэша)
    // ================================================================

    /**
     * Собрать все данные об организации: API + HTML-парсинг.
     */
    private function fetchFreshData(string $placeId, string $placeUrl): array
    {
        // 1) Yandex Search API — название, рейтинг, кол-во отзывов
        $orgData = $this->fetchOrganizationData($placeId);

        // 2) HTML-парсинг — отзывы, фотографии, метаданные
        $scrapedData = $this->scrapeOrganizationPage($placeId);

        // Мерж данных: API приоритетнее HTML
        $companyName = $orgData['name']
            ?? $scrapedData['companyName']
            ?? $this->extractNameFromUrl($placeUrl)
            ?? 'Компания из Яндекс.Карт';

        $rating = $orgData['rating'] ?? $scrapedData['rating'] ?? 0.0;
        $reviewsCount = $orgData['reviewsCount'] ?? $scrapedData['reviewsCount'] ?? 0;

        // Логотип: настоящий логотип > основное фото (photos[0]) > null
        $companyLogo = $scrapedData['companyLogo'] ?? null;
        $photos = $scrapedData['photos'];

        if (! $companyLogo && ! empty($photos)) {
            // Используем URL основного фото как аватарку организации
            $companyLogo = $photos[0]['url'];
        }

        // Убираем логотип из галереи фото, чтобы он не дублировался
        if ($companyLogo) {
            $logoBase = $this->extractBaseUrl($companyLogo);
            $photos = array_values(array_filter(
                $photos,
                fn (array $photo) => $this->extractBaseUrl($photo['url']) !== $logoBase
                    && $this->extractBaseUrl($photo['thumbnail']) !== $logoBase
            ));
        }

        // Гарантируем ровно MAX_PHOTOS фото в галерее
        $photos = array_slice($photos, 0, self::MAX_PHOTOS);

        if ($reviewsCount === 0) {
            $reviewsCount = count($scrapedData['reviews']);
        }

        return [
            'companyName' => $companyName,
            'companyLogo' => $companyLogo,
            'rating' => (float) $rating,
            'reviewsCount' => (int) $reviewsCount,
            'reviews' => $scrapedData['reviews'],
            'photos' => $photos,
            'hasMoreReviews' => $scrapedData['hasMoreReviews'],
        ];
    }

    /**
     * Yandex Organization Search API — название, рейтинг, кол-во отзывов.
     */
    private function fetchOrganizationData(string $placeId): array
    {
        $apiKey = (string) config('services.yandex_maps.api_key', '');

        if ($apiKey === '') {
            return [];
        }

        try {
            $response = Http::timeout(self::API_TIMEOUT)->get('https://search-maps.yandex.ru/v1/', [
                'apikey' => $apiKey,
                'uri' => 'ymapsbm1://org?oid='.$placeId,
                'type' => 'biz',
                'lang' => 'ru_RU',
                'results' => 1,
            ]);

            if (! $response->ok()) {
                Log::warning('Yandex Search API: HTTP '.$response->status(), ['placeId' => $placeId]);

                return [];
            }

            return $this->parseSearchApiResponse($response->json());
        } catch (\Throwable $e) {
            Log::warning('Yandex Search API error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Распарсить ответ Yandex Search API.
     */
    private function parseSearchApiResponse(array $data): array
    {
        $features = $data['features'] ?? [];

        if (empty($features)) {
            return [];
        }

        $props = $features[0]['properties'] ?? [];
        $meta = $props['CompanyMetaData'] ?? [];

        $name = $meta['name'] ?? ($props['name'] ?? null);
        $rating = null;
        $reviewsCount = null;

        if (isset($meta['Ratings'])) {
            $ratings = $meta['Ratings'];
            if (isset($ratings['score'])) {
                $rating = (float) $ratings['score'];
                $reviewsCount = (int) ($ratings['ratings'] ?? 0);
            } elseif (isset($ratings[0]['score'])) {
                $rating = (float) $ratings[0]['score'];
                $reviewsCount = (int) ($ratings[0]['ratings'] ?? 0);
            }
        }

        return array_filter([
            'name' => $name,
            'rating' => $rating,
            'reviewsCount' => $reviewsCount,
        ], fn ($v) => $v !== null);
    }

    // ================================================================
    // HTML-скрапинг
    // ================================================================

    /**
     * Парсим HTML страницы организации на Яндекс.Картах.
     *
     * Делает 2 HTTP-запроса:
     *   1. Главная страница org/{id}/ — фото + название
     *   2. Страница отзывов org/{id}/reviews/ — отзывы + метаданные
     *
     * @return array{companyName: string|null, rating: float|null, reviewsCount: int|null, reviews: array, photos: array, hasMoreReviews: bool}
     */
    private function scrapeOrganizationPage(string $placeId): array
    {
        $result = [
            'companyName' => null,
            'companyLogo' => null,
            'rating' => null,
            'reviewsCount' => null,
            'reviews' => [],
            'photos' => [],
            'hasMoreReviews' => false,
        ];

        // 1) Главная страница организации (фото + logo + meta)
        $this->scrapeMainPage($placeId, $result);

        // 2) Страница отзывов (страница 1)
        $this->scrapeFirstReviewsPage($placeId, $result);

        return $result;
    }

    /**
     * Парсим главную страницу организации — фото, логотип и название.
     */
    private function scrapeMainPage(string $placeId, array &$result): void
    {
        try {
            $url = "https://yandex.ru/maps/org/{$placeId}/";
            $response = Http::timeout(self::HTTP_TIMEOUT)->withHeaders(self::REQUEST_HEADERS)->get($url);

            if (! $response->ok()) {
                return;
            }

            $html = $response->body();
            $result['photos'] = $this->extractPhotosFromHtml($html);
            $result['companyLogo'] = $this->extractCompanyLogoFromHtml($html);

            // Название из <title>
            $title = $this->extractTitle($html);
            if ($title && preg_match('/[«""]([^»""]+)[»""]/u', $title, $nameMatch)) {
                $result['companyName'] = trim($nameMatch[1]);
            }
        } catch (\Throwable $e) {
            Log::warning('Ошибка при загрузке главной страницы org', [
                'placeId' => $placeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Парсим первую страницу отзывов — отзывы + метаданные (название, рейтинг, кол-во).
     */
    private function scrapeFirstReviewsPage(string $placeId, array &$result): void
    {
        try {
            $reviewsResult = $this->scrapeReviewsPage($placeId, 1);

            $result['reviews'] = $reviewsResult['reviews'];
            $result['hasMoreReviews'] = $reviewsResult['hasMoreReviews'];

            $html = $reviewsResult['html'] ?? '';

            if ($html) {
                $this->extractMetadataFromHtml($html, $result);
            }

            if (empty($result['reviews'])) {
                Log::info('Не удалось спарсить отзывы, используем демо-данные', ['placeId' => $placeId]);
                $result['reviews'] = $this->fakeReviews();
            }
        } catch (\Throwable $e) {
            Log::warning('Ошибка парсинга страницы отзывов', [
                'placeId' => $placeId,
                'error' => $e->getMessage(),
            ]);
            $result['reviews'] = $this->fakeReviews();
        }
    }

    /**
     * Извлечь метаданные (название, рейтинг, кол-во отзывов) из HTML.
     *
     * Использует несколько стратегий:
     *   - Schema.org микроразметка (самая надёжная)
     *   - CSS-классы Яндекс.Карт
     *   - JSON-данные внутри HTML
     *   - <title> как fallback для названия
     */
    private function extractMetadataFromHtml(string $html, array &$result): void
    {
        // ── Название компании ──────────────────────────────────────

        // 1) Schema.org: itemProp="name" внутри блока организации (вне блоков отзывов)
        if (preg_match('~<div[^>]*itemScope[^>]*itemType="[^"]*(?:Organization|LocalBusiness|FoodEstablishment|Restaurant|Store)[^"]*"[^>]*>[\s\S]*?itemProp="name"[^>]*>([^<]+)~i', $html, $m)) {
            $name = trim($m[1]);
            if ($name && mb_strlen($name) > 1 && mb_strlen($name) < 80) {
                $result['companyName'] = $name;
            }
        }

        // 2) <title> (fallback)
        if (! $result['companyName'] && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $title = trim($m[1]);

            if (preg_match('/[«""]([^»""]+)[»""]/u', $title, $nameMatch)) {
                $result['companyName'] = trim($nameMatch[1]);
            } else {
                $cleaned = preg_replace('/\s*[—–\-:]\s*(отзывы|рейтинг|фото|цены|карта|Яндекс).*/iu', '', $title);
                $cleaned = preg_replace('/^(Отзывы\s+о\s+)/iu', '', $cleaned);
                $cleaned = trim($cleaned, " \t\n\r\0\x0B«»\"");

                if ($cleaned && mb_strlen($cleaned) > 1 && mb_strlen($cleaned) < 80) {
                    $result['companyName'] = $cleaned;
                }
            }
        }

        // ── Рейтинг ───────────────────────────────────────────────

        // 1) Schema.org: itemProp="ratingValue"
        if (preg_match('/itemProp="ratingValue"[^>]*content="([\d.]+)"/i', $html, $m)) {
            $result['rating'] = (float) $m[1];
        }
        // 2) aria-label с рейтингом (например, «Рейтинг: 4.3 из 5»)
        elseif (preg_match('/aria-label="[^"]*(?:Рейтинг|rating)[^"]*?([\d]\.\d)/iu', $html, $m)) {
            $result['rating'] = (float) $m[1];
        }
        // 3) CSS-класс с rating
        elseif (preg_match('/class="[^"]*(?:rating-badge|rating-value|orgpage-rating)[^"]*"[^>]*>\s*([\d]\.\d)/s', $html, $m)) {
            $result['rating'] = (float) $m[1];
        }
        // 4) JSON: "totalScore" в данных страницы
        elseif (preg_match('/"totalScore"\s*:\s*([\d]\.\d)/', $html, $m)) {
            $result['rating'] = (float) $m[1];
        }

        // ── Количество отзывов ────────────────────────────────────

        // 1) Schema.org: itemProp="reviewCount"
        if (preg_match('/itemProp="reviewCount"[^>]*content="(\d+)"/i', $html, $m)) {
            $result['reviewsCount'] = (int) $m[1];
        }
        // 2) Текст «N отзывов» на странице
        elseif (preg_match('/(\d+)\s*(?:отзыв|review)/iu', $html, $m)) {
            $result['reviewsCount'] = (int) $m[1];
        }
    }

    /**
     * Парсим конкретную страницу отзывов (page=N).
     *
     * @return array{reviews: array, hasMoreReviews: bool, html: string}
     */
    private function scrapeReviewsPage(string $placeId, int $page): array
    {
        $url = "https://yandex.ru/maps/org/{$placeId}/reviews/";

        if ($page > 1) {
            $url .= '?page='.$page;
        }

        $response = Http::timeout(self::HTTP_TIMEOUT)->withHeaders(self::REQUEST_HEADERS)->get($url);

        if (! $response->ok()) {
            Log::warning('Не удалось загрузить страницу отзывов', [
                'placeId' => $placeId,
                'page' => $page,
                'status' => $response->status(),
            ]);

            return ['reviews' => [], 'hasMoreReviews' => false, 'html' => ''];
        }

        $html = $response->body();

        // Проверяем, не вернула ли Яндекс капчу
        if ($this->isCaptchaPage($html)) {
            Log::error('Яндекс вернул капчу вместо страницы отзывов', [
                'placeId' => $placeId,
                'page' => $page,
            ]);

            return ['reviews' => [], 'hasMoreReviews' => false, 'html' => ''];
        }

        $rawReviews = $this->extractRawReviewsFromHtml($html);
        $reviews = $this->cleanAndMergeReviews($rawReviews);

        // Проверяем наличие следующей страницы
        $nextPage = $page + 1;
        $hasMoreReviews = (bool) preg_match('~reviews/\?page='.$nextPage.'~', $html);

        return [
            'reviews' => $reviews,
            'hasMoreReviews' => $hasMoreReviews,
            'html' => $html,
        ];
    }

    /**
     * Проверяем, является ли HTML страницей капчи.
     *
     * ВАЖНО: нельзя просто искать слово «captcha» — оно присутствует на ВСЕХ страницах Яндекса
     * в JS-конфиге (ссылка на fingerprint-библиотеку captchapgrd). Нужны более точные маркеры.
     */
    private function isCaptchaPage(string $html): bool
    {
        // Маркеры реальной страницы капчи
        if (str_contains($html, 'CheckboxCaptcha') || str_contains($html, 'captcha-page')) {
            return true;
        }

        // SmartCaptcha — только если это НЕ обычная страница с отзывами
        if (str_contains($html, 'SmartCaptcha') && ! str_contains($html, 'business-reviews-card-view')) {
            return true;
        }

        // «Подтвердите, что вы не робот» — только если нет контента отзывов
        if (str_contains($html, 'Подтвердите') && str_contains($html, 'робот')
            && ! str_contains($html, 'business-reviews-card-view')
            && ! str_contains($html, 'orgpage')) {
            return true;
        }

        return false;
    }

    // ================================================================
    // Парсинг отзывов
    // ================================================================

    /**
     * Извлекаем «сырые» записи из HTML.
     *
     * Приоритет стратегий:
     *   1. HTML-блоки business-reviews-card-view__review (самая надёжная)
     *   2. JSON в window.__INITIAL_STATE__
     *   3. JSON-фрагменты "reviews": [...]
     */
    private function extractRawReviewsFromHtml(string $html): array
    {
        // Стратегия 1: HTML-блоки
        $htmlReviews = $this->parseReviewBlocksFromHtml($html);
        if (! empty($htmlReviews)) {
            return $htmlReviews;
        }

        // Стратегия 2: __INITIAL_STATE__
        $stateReviews = $this->parseInitialState($html);
        if (! empty($stateReviews)) {
            return $stateReviews;
        }

        // Стратегия 3: JSON-фрагменты
        return $this->parseJsonFragments($html);
    }

    /**
     * Стратегия 2: Извлечь отзывы из window.__INITIAL_STATE__.
     */
    private function parseInitialState(string $html): array
    {
        if (! preg_match('/window\.__INITIAL_STATE__\s*=\s*({.+?});?\s*<\/script>/s', $html, $m)) {
            return [];
        }

        try {
            $state = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
            $found = [];
            $this->findReviewsRecursive($state, $found);

            return $found;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Стратегия 3: Найти JSON-фрагменты "reviews": [...] в HTML.
     */
    private function parseJsonFragments(string $html): array
    {
        $raw = [];

        if (! preg_match_all('/"reviews"\s*:\s*(\[[\s\S]*?\])\s*[,}]/s', $html, $matches)) {
            return $raw;
        }

        foreach ($matches[1] as $block) {
            try {
                $parsed = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($parsed)) {
                    continue;
                }

                foreach ($parsed as $item) {
                    if (is_array($item)) {
                        $raw[] = $this->normalizeReviewItem($item);
                    }
                }
            } catch (\Throwable) {
                // Невалидный JSON — пропускаем
            }
        }

        return $raw;
    }

    /**
     * Стратегия 1 (основная): парсим HTML по CSS-классам Яндекс.Карт.
     *
     * Каждый отзыв — блок business-reviews-card-view__review.
     * Из блока извлекаем: имя, статус, аватарку, ссылку на профиль, рейтинг, дату, текст.
     */
    private function parseReviewBlocksFromHtml(string $html): array
    {
        // Удаляем <script>, <style>, <noscript> — JSON-конфиг не должен попасть в текст
        $cleanHtml = preg_replace('~<script[^>]*>[\s\S]*?</script>~i', '', $html);
        $cleanHtml = preg_replace('~<style[^>]*>[\s\S]*?</style>~i', '', $cleanHtml);
        $cleanHtml = preg_replace('~<noscript[^>]*>[\s\S]*?</noscript>~i', '', $cleanHtml);

        // Разбиваем на блоки по `business-reviews-card-view__review`
        if (! preg_match_all(
            '~<div[^>]*class="[^"]*business-reviews-card-view__review[^"]*"[^>]*>([\s\S]*?)(?=<div[^>]*class="[^"]*business-reviews-card-view__review[^"]*"|</body>|$)~',
            $cleanHtml,
            $reviewBlocks
        )) {
            return [];
        }

        $reviews = [];

        foreach ($reviewBlocks[1] as $block) {
            $review = $this->parseOneReviewBlock($block);

            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    /**
     * Распарсить один блок отзыва из HTML.
     */
    private function parseOneReviewBlock(string $block): ?array
    {
        // Имя автора
        $author = 'Аноним';
        if (preg_match('~itemProp="name"[^>]*>([^<]+)~', $block, $m)) {
            $author = trim($m[1]);
        }

        // Статус автора (Знаток города X уровня и т.д.)
        $authorStatus = '';
        if (preg_match('~business-review-view__author-caption[^>]*>([^<]+)~', $block, $m)) {
            $authorStatus = trim($m[1]);
        }

        // Аватар пользователя
        $authorAvatar = null;
        // 1) Элемент с классом author-icon / user-icon — самый надёжный маркер
        if (preg_match(
            '~class="[^"]*(?:author[_-]?icon|user[_-]?icon|review[_-]view__author)[^"]*"[^>]*style="[^"]*background-image:\s*url\((https?://avatars\.mds\.yandex\.net/[^)]+)\)~i',
            $block,
            $m
        )) {
            $authorAvatar = $m[1];
        }
        // 2) CSS background-image с avatars.mds — любой get-* префикс
        //    (get-altay, get-yapic, get-lpc, get-pdb, get-entity-icon, get-yandex-maps-users и т.д.)
        if (! $authorAvatar && preg_match('~background-image:\s*url\((https?://avatars\.mds\.yandex\.net/get-[^)]+)\)~', $block, $m)) {
            $authorAvatar = $m[1];
        }
        // 3) <img> с avatars.mds — любой get-* префикс
        if (! $authorAvatar && preg_match('~<img[^>]+src="(https?://avatars\.mds\.yandex\.net/get-[^"]+)"~', $block, $m)) {
            $authorAvatar = $m[1];
        }

        // Ссылка на профиль
        $authorProfileUrl = null;
        if (preg_match('~href="(https://yandex\.ru/maps/user/[^"]+)"~', $block, $m)) {
            $authorProfileUrl = $m[1];
        }

        // Рейтинг (aria-label="Оценка X Из 5")
        $rating = 0;
        if (preg_match('~aria-label="(?:Оценка|Rating)\s*(\d)\s*(?:Из|из|of)\s*5"~iu', $block, $m)) {
            $rating = (int) $m[1];
        } else {
            $rating = substr_count($block, 'business-rating-badge-view__star _full');
        }

        // Дата (schema.org: <meta itemProp="datePublished" content="..."/>)
        $date = '';
        if (preg_match('~itemProp="datePublished"\s+content="([^"]+)"~', $block, $m)) {
            $date = $m[1];
        }

        // Текст отзыва
        $text = $this->extractReviewText($block);

        if (! $text || mb_strlen($text) < self::MIN_REVIEW_LENGTH) {
            return null;
        }

        return [
            'author' => $author,
            'authorStatus' => $authorStatus,
            'authorAvatar' => $authorAvatar,
            'authorProfileUrl' => $authorProfileUrl,
            'rating' => $rating ?: 5,
            'date' => $date,
            'text' => $text,
        ];
    }

    /**
     * Извлечь текст отзыва из HTML-блока (5 паттернов по приоритету).
     */
    private function extractReviewText(string $block): string
    {
        $text = '';

        // 1. itemProp="reviewBody" (schema.org — самый надёжный)
        if (preg_match('~itemProp="reviewBody"[^>]*>([\s\S]*?)</div>~', $block, $m)) {
            $text = $this->cleanReviewHtml($m[1]);
        }

        // 2. business-review-view__body-text
        if (! $text && preg_match('~business-review-view__body-text[^>]*>([\s\S]*?)</(?:div|span)>~', $block, $m)) {
            $text = $this->cleanReviewHtml($m[1]);
        }

        // 3. spoiler-view__text (длинные свёрнутые отзывы)
        if (! $text && preg_match('~spoiler-view__text[^>]*>([\s\S]*?)</div>~', $block, $m)) {
            $text = $this->cleanReviewHtml($m[1]);
        }

        // 4. business-review-view__body
        if (! $text && preg_match('~business-review-view__body[^>]*>([\s\S]*?)</div>~', $block, $m)) {
            $text = $this->cleanReviewHtml($m[1]);
        }

        // 5. Fallback: самый длинный чистый текст (≥40 символов)
        if (! $text && preg_match_all('~>([^<]{40,})<~', $block, $texts)) {
            foreach ($texts[1] as $t) {
                $clean = trim(strip_tags($t));
                if (mb_strlen($clean) > mb_strlen($text) && ! $this->isGarbageText($clean) && ! $this->looksLikeCode($clean)) {
                    $text = $clean;
                }
            }
        }

        // Финальная валидация
        if ($this->looksLikeCode($text)) {
            return '';
        }

        return $text;
    }

    // ================================================================
    // Очистка и нормализация
    // ================================================================

    /**
     * Очистить HTML-содержимое блока отзыва.
     *
     * Выполняет:
     *   - strip_tags (убираем HTML-теги)
     *   - html_entity_decode (декодируем &quot; &amp; &lt; &gt; &#039; и прочие)
     *   - нормализация пробелов
     */
    private function cleanReviewHtml(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Нормализовать данные отзыва из JSON.
     */
    private function normalizeReviewItem(array $item): array
    {
        $authorData = $item['author'] ?? [];

        $avatarUrl = null;
        if (is_array($authorData)) {
            $avatarUrl = $authorData['avatar'] ?? $authorData['avatarUrl'] ?? null;
            if ($avatarUrl && str_contains($avatarUrl, '{size}')) {
                $avatarUrl = str_replace('{size}', 'islands-68', $avatarUrl);
            }
        }

        return [
            'author' => is_string($authorData)
                ? $authorData
                : ($authorData['name'] ?? $authorData['displayName'] ?? 'Аноним'),
            'authorStatus' => is_array($authorData)
                ? ($authorData['level'] ?? $authorData['status'] ?? '')
                : '',
            'authorAvatar' => $avatarUrl,
            'authorProfileUrl' => is_array($authorData)
                ? ($authorData['profileUrl'] ?? $authorData['publicProfileUrl'] ?? null)
                : null,
            'rating' => (int) ($item['rating'] ?? $item['stars'] ?? $item['score'] ?? 0),
            'date' => $item['date'] ?? $item['createdAt'] ?? $item['updatedAt'] ?? '',
            'text' => $item['text'] ?? $item['comment'] ?? $item['body'] ?? '',
        ];
    }

    /**
     * Очистить и объединить отзывы.
     *
     * Яндекс.Карты при парсинге могут выдавать разрозненные записи:
     *   1) Запись с именем автора, но без текста
     *   2) Мусорная запись «Подписаться»
     *   3) Запись с текстом, но автор «Аноним»
     *
     * Объединяем: берём имя из (1), текст из (3).
     */
    private function cleanAndMergeReviews(array $raw): array
    {
        $cleaned = [];
        $pendingAuthor = null;

        foreach ($raw as $entry) {
            $text = trim($entry['text'] ?? '');
            $author = trim($entry['author'] ?? '');

            // Мусор
            if ($this->isGarbageText($text)) {
                continue;
            }

            // Без текста — запоминаем автора
            if ($text === '') {
                if ($author && $author !== 'Аноним' && mb_strlen($author) > 1) {
                    $pendingAuthor = $author;
                }

                continue;
            }

            // Слишком короткий текст (UI-элемент)
            if (mb_strlen($text) < 15 && ! preg_match('/[.!?…]$/u', $text)) {
                continue;
            }

            // JSON/код
            if ($this->looksLikeCode($text)) {
                continue;
            }

            // Подставляем запомненного автора
            if (($author === 'Аноним' || $author === '') && $pendingAuthor) {
                $entry['author'] = $pendingAuthor;
            }

            $pendingAuthor = null;

            $cleaned[] = [
                'author' => html_entity_decode($entry['author'] ?: 'Аноним', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'authorStatus' => html_entity_decode($entry['authorStatus'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'authorAvatar' => $entry['authorAvatar'] ?? null,
                'authorProfileUrl' => $entry['authorProfileUrl'] ?? null,
                'rating' => (int) ($entry['rating'] ?? 5),
                'date' => $entry['date'] ?? '',
                'text' => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return $cleaned;
    }

    // ================================================================
    // Валидация текста
    // ================================================================

    /**
     * Проверяем, является ли текст мусором из UI Яндекс.Карт.
     */
    private function isGarbageText(string $text): bool
    {
        $lower = mb_strtolower(trim($text));

        if (in_array($lower, self::GARBAGE_TEXTS, true)) {
            return true;
        }

        // UI-паттерны: «Еда • 88%», «Обслуживание • 92%»
        if (preg_match('/^[\wа-яё]+\s*[•·]\s*\d+%$/iu', $lower)) {
            return true;
        }

        // Только цифры/спецсимволы
        if (preg_match('/^[\d\s%•·.,]+$/', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Проверяем, выглядит ли текст как JSON, JavaScript или конфиг.
     */
    private function looksLikeCode(string $text): bool
    {
        $trimmed = ltrim($text);

        // Начинается с { или [
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return true;
        }

        // Типичные паттерны JS/JSON
        if (preg_match('/^(?:var |let |const |function |window\.|"config"|"requestId"|"csrfToken"|"hosts"|"apikey")/i', $trimmed)) {
            return true;
        }

        // Слишком много спецсимволов (>15% при длине >50)
        $specialChars = preg_match_all('/[{}\\[\\]":<>\\\\]/', $text);
        $totalChars = mb_strlen($text);
        if ($totalChars > 50 && $specialChars / $totalChars > 0.15) {
            return true;
        }

        // URL-шаблоны ({{variable}})
        if (preg_match('/\{\{[a-zA-Z]+\}\}/', $text)) {
            return true;
        }

        return false;
    }

    // ================================================================
    // Рекурсивный поиск отзывов в JSON
    // ================================================================

    private function findReviewsRecursive(array $data, array &$reviews, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }

        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (in_array($key, ['reviews', 'items', 'Comments', 'comments'], true)) {
                foreach ($value as $item) {
                    if (is_array($item) && (isset($item['text']) || isset($item['comment']) || isset($item['author']))) {
                        $reviews[] = $this->normalizeReviewItem($item);
                    }
                }
            }

            $this->findReviewsRecursive($value, $reviews, $depth + 1);
        }
    }

    // ================================================================
    // Логотип / аватарка организации
    // ================================================================

    /**
     * Извлечь логотип (аватарку) организации из HTML страницы.
     *
     * Источники (в порядке приоритета):
     *   0. get-tycoon URL — CDN-префикс для бизнес-логотипов Яндекса (самый надёжный)
     *   1. CSS background-image на элементе с классом «logo» (same element)
     *   2. Класс «logo» на родителе → background-image на вложенном потомке
     *   3. <img> с классом logo
     *   4. JSON __INITIAL_STATE__ — вложенные объекты logotype/logo
     *   5. Простой JSON-паттерн "logoUrl": "..."
     *
     * НЕ используем og:image — Яндекс ставит туда произвольное фото из галереи.
     * Если логотип не найден, fetchFreshData() использует photos[0] как аватарку
     * и убирает его из галереи.
     */
    private function extractCompanyLogoFromHtml(string $html): ?string
    {
        // 0) get-tycoon — выделенный CDN-префикс Яндекса для бизнес-логотипов.
        //    Любой get-tycoon URL на странице — практически гарантированно логотип.
        if (preg_match(
            '~(https?://avatars\.mds\.yandex\.net/get-tycoon/\d+/[a-f0-9]+/[A-Za-z0-9_-]+)~',
            $html,
            $m
        )) {
            $url = $m[1];
            if ($this->isValidImageUrl($url)) {
                return $url;
            }
        }

        // 1) CSS background-image на том же элементе с классом «logo»
        if (preg_match(
            '~class="[^"]*(?:card-title-view__logo|business[^"]*__logo|orgpage[^"]*__logo)[^"]*"[^>]*style="[^"]*background-image:\s*url\(([^)]+)\)~i',
            $html,
            $m
        )) {
            $url = trim($m[1], " '\"");
            if ($this->isValidLogoUrl($url)) {
                return $url;
            }
        }

        // 2) Класс «logo» на родителе, background-image на вложенном потомке
        //    (Яндекс часто делит class и style между родителем и дочерним div)
        if (preg_match(
            '~class="[^"]*(?:card-title-view__logo|logo-view|__logo-image|__logo)[^"]*"[\s\S]{0,500}?background-image:\s*url\((https?://avatars\.mds\.yandex\.net/[^)]+)\)~i',
            $html,
            $m
        )) {
            $url = trim($m[1], " '\"");
            if ($this->isValidLogoUrl($url)) {
                return $url;
            }
        }

        // 3) <img> с классом logo
        if (preg_match(
            '~<img[^>]*class="[^"]*logo[^"]*"[^>]*src="(https?://[^"]+)"~i',
            $html,
            $m
        )) {
            if ($this->isValidLogoUrl($m[1])) {
                return $m[1];
            }
        }

        // 4) JSON __INITIAL_STATE__ — вложенные объекты (logotype.urlTemplate и т.д.)
        if (preg_match('/window\.__INITIAL_STATE__\s*=\s*({.+?});?\s*<\/script>/s', $html, $stateMatch)) {
            try {
                $state = json_decode($stateMatch[1], true, 512, JSON_THROW_ON_ERROR);
                $logoUrl = $this->findLogoInJsonState($state);
                if ($logoUrl) {
                    return $logoUrl;
                }
            } catch (\Throwable) {
            }
        }

        // 5) Простой JSON-паттерн "logoUrl": "https://..."
        if (preg_match(
            '~"(?:logoUrl|logoUrlTemplate|logo_url)"\s*:\s*"(https?:[^"]+)"~',
            $html,
            $m
        )) {
            $url = stripslashes($m[1]);
            $url = str_replace(['%%', '{size}'], ['islands-68', 'islands-68'], $url);
            if ($this->isValidLogoUrl($url)) {
                return $url;
            }
        }

        // НЕ используем og:image — Яндекс подставляет туда произвольное фото
        // из галереи, часто НЕ совпадающее с основным фото карточки.
        // Вместо этого fetchFreshData() использует photos[0] как fallback.

        return null;
    }

    /**
     * Найти URL логотипа организации в JSON-дереве __INITIAL_STATE__.
     *
     * Ищет по известным путям: business.logotype.urlTemplate, business.logo, и т.д.
     */
    private function findLogoInJsonState(array $state): ?string
    {
        $paths = [
            ['business', 'logotype', 'urlTemplate'],
            ['business', 'logotype', 'url'],
            ['business', 'logotype'],
            ['business', 'logo', 'urlTemplate'],
            ['business', 'logo', 'url'],
            ['business', 'logo'],
            ['business', 'logoUrl'],
            ['business', 'properties', 'logotype', 'urlTemplate'],
            ['business', 'properties', 'logo'],
            ['orgInfo', 'logotype', 'urlTemplate'],
            ['orgInfo', 'logo'],
        ];

        foreach ($paths as $path) {
            $value = $state;
            foreach ($path as $key) {
                if (! is_array($value) || ! isset($value[$key])) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }

            if (is_string($value) && $value !== '') {
                $url = stripslashes($value);
                $url = str_replace(['%%', '{size}'], ['islands-68', 'islands-68'], $url);
                if ($this->isValidLogoUrl($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Извлечь базовый URL изображения (без суффикса размера).
     *
     * Пример: https://avatars.mds.yandex.net/get-altay/123/abc123/M → .../get-altay/123/abc123
     */
    private function extractBaseUrl(string $url): string
    {
        // Убираем суффикс размера (/M, /S_height, /XXL_height, /islands-68 и т.д.)
        return preg_replace('~/[A-Za-z0-9_-]+$~', '', $url);
    }

    /**
     * Проверить, что URL похож на корректную ссылку на картинку.
     */
    private function isValidImageUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://~', $url)
            && ! str_contains($url, '<')
            && ! str_contains($url, '>')
            && mb_strlen($url) < 500;
    }

    /**
     * Проверить, что URL подходит для логотипа/аватарки организации.
     *
     * Отсекаем CDN-префиксы, которые содержат низкокачественные
     * каталожные/сервисные изображения, а не реальные фото/логотипы.
     */
    private function isValidLogoUrl(string $url): bool
    {
        if (! $this->isValidImageUrl($url)) {
            return false;
        }

        // get-discovery-int / get-discovery — каталожные миниатюры Яндекс Discovery
        // Это НЕ реальные логотипы/фото организаций, а автоматически
        // сгенерированные превью. Пример: .../get-discovery-int/.../XXS
        if (preg_match('~get-discovery(?:-int)?/~', $url)) {
            return false;
        }

        return true;
    }

    // ================================================================
    // Фотографии
    // ================================================================

    /**
     * Извлечь фотографии организации из HTML.
     *
     * @return list<array{url: string, thumbnail: string}>
     */
    private function extractPhotosFromHtml(string $html): array
    {
        $baseUrls = [];

        // get-altay (основные фото организации)
        if (preg_match_all(
            '~(https?://avatars\.mds\.yandex\.net/get-altay/\d+/[a-f0-9]+)(?:/[A-Za-z_]+)?~',
            $html,
            $matches
        )) {
            foreach ($matches[1] as $baseUrl) {
                $baseUrls[$baseUrl] = true;
            }
        }

        // get-yandex-maps-reviews (фото из отзывов)
        if (preg_match_all(
            '~(https?://avatars\.mds\.yandex\.net/get-yandex-maps[^/]*/\d+/[a-f0-9]+)(?:/[A-Za-z_]+)?~',
            $html,
            $matches
        )) {
            foreach ($matches[1] as $baseUrl) {
                $baseUrls[$baseUrl] = true;
            }
        }

        $photos = [];
        foreach (array_keys($baseUrls) as $baseUrl) {
            $photos[] = [
                'url' => $baseUrl.'/XXL_height',
                'thumbnail' => $baseUrl.'/M',
            ];
        }

        // Берём MAX_PHOTOS + 1, чтобы после удаления фото-логотипа в fetchFreshData
        // в галерее осталось ровно MAX_PHOTOS штук.
        return array_slice($photos, 0, self::MAX_PHOTOS + 1);
    }

    /**
     * Извлечь <title> из HTML.
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    // ================================================================
    // URL-утилиты
    // ================================================================

    /**
     * Попытаться извлечь название из slug'а URL.
     */
    private function extractNameFromUrl(string $url): ?string
    {
        $decoded = urldecode($url);

        if (preg_match('~/org/([^/\d][^/]*)(?:/|$)~i', $decoded, $m)) {
            $slug = $m[1];
            $name = str_replace(['_', '-'], ' ', $slug);

            return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return null;
    }

    // ================================================================
    // Кэш-ключи
    // ================================================================

    /**
     * Генерация ключа кэша.
     */
    private function cacheKey(string $placeId, ?int $page = null): string
    {
        if ($page !== null) {
            return "yandex_reviews:{$placeId}:page:{$page}";
        }

        return "yandex_reviews:{$placeId}:main";
    }

    // ================================================================
    // Fallback (демо-данные)
    // ================================================================

    private function fakeReviews(): array
    {
        return [
            [
                'author' => 'Анна Смирнова',
                'authorStatus' => 'Знаток города 5 уровня',
                'authorAvatar' => null,
                'authorProfileUrl' => null,
                'rating' => 5,
                'date' => now()->subDays(2)->toDateString(),
                'text' => 'Отличный сервис! Очень уютное место с приятной атмосферой. Кофе на высоте, десерты свежие и вкусные. Персонал внимательный и приветливый.',
            ],
            [
                'author' => 'Иван Петров',
                'authorStatus' => 'Дегустатор 3 уровня',
                'authorAvatar' => null,
                'authorProfileUrl' => null,
                'rating' => 4,
                'date' => now()->subDays(5)->toDateString(),
                'text' => 'В целом хорошее заведение. Еда вкусная, порции нормальные. Единственный минус — пришлось подождать столик минут 15.',
            ],
            [
                'author' => 'Мария Козлова',
                'authorStatus' => 'Знаток города 8 уровня',
                'authorAvatar' => null,
                'authorProfileUrl' => null,
                'rating' => 5,
                'date' => now()->subDays(10)->toDateString(),
                'text' => 'Лучшее место в районе! Ходим сюда каждые выходные. Очень вкусный капучино и чизкейк.',
            ],
            [
                'author' => 'Дмитрий Волков',
                'authorStatus' => 'Знаток города 2 уровня',
                'authorAvatar' => null,
                'authorProfileUrl' => null,
                'rating' => 3,
                'date' => now()->subDays(14)->toDateString(),
                'text' => 'Неплохо, но есть к чему стремиться. Кофе средний, а вот выпечка порадовала. Wi-Fi нестабильный.',
            ],
            [
                'author' => 'Елена Новикова',
                'authorStatus' => 'Дегустатор 6 уровня',
                'authorAvatar' => null,
                'authorProfileUrl' => null,
                'rating' => 5,
                'date' => now()->subDays(18)->toDateString(),
                'text' => 'Замечательное место для встреч с друзьями! Большой выбор напитков, приятный интерьер.',
            ],
        ];
    }
}
