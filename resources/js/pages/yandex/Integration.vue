<script setup lang="ts">
import { computed, ref, nextTick, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { MapPin, ExternalLink, Search, Star, User, ChevronLeft, ChevronRight, Camera, X, Filter, ArrowUpDown, Loader2, ChevronDown, RefreshCw } from 'lucide-vue-next';
import axios from 'axios';
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';

// ================================================================
// Типы
// ================================================================

type YandexReview = {
    author: string;
    authorStatus: string;
    authorAvatar: string | null;
    authorProfileUrl: string | null;
    rating: number;
    date: string;
    text: string;
};

type YandexPhoto = {
    url: string;
    thumbnail: string;
};

type CompanyInfo = {
    name: string;
    logo: string | null;
    rating: number;
    reviewsCount: number;
} | null;

type Props = {
    yandexUrl: string | null;
    company: CompanyInfo;
    reviews: YandexReview[];
    photos: YandexPhoto[];
    hasMoreReviews: boolean;
    error: string | null;
};

type RatingFilter = 0 | 1 | 2 | 3 | 4 | 5;
type DateSort = 'newest' | 'oldest';

// ================================================================
// Props & breadcrumbs
// ================================================================

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Заведения из Яндекс.Карт', href: '/yandex' },
];

// ================================================================
// Форма ввода URL
// ================================================================

const form = useForm({
    yandex_url: props.yandexUrl ?? '',
});

function submit() {
    form.put('/yandex', { preserveScroll: true });
}

// ================================================================
// Кнопка «Обновить данные» (сброс кэша)
// ================================================================

const isRefreshing = ref(false);

function refreshData() {
    if (isRefreshing.value) return;

    isRefreshing.value = true;
    router.post('/yandex/refresh', {}, {
        preserveScroll: true,
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
}

// ================================================================
// Отзывы (все загруженные, включая подгруженные)
// ================================================================

const allReviews = ref<YandexReview[]>([...props.reviews]);
const hasMore = ref(props.hasMoreReviews);
const loadingMore = ref(false);
const loadMoreError = ref('');
const scrapePage = ref(1);

// Сбрасываем при смене URL (Inertia обновляет props)
watch(() => props.reviews, (newReviews) => {
    allReviews.value = [...newReviews];
    hasMore.value = props.hasMoreReviews;
    scrapePage.value = 1;
    currentPage.value = 1;
});

// ================================================================
// Фильтры и сортировка
// ================================================================

const ratingFilter = ref<RatingFilter>(0);
const dateSort = ref<DateSort>('newest');

const ratingOptions: { value: RatingFilter; label: string }[] = [
    { value: 0, label: 'Все оценки' },
    { value: 5, label: '★ 5' },
    { value: 4, label: '★ 4' },
    { value: 3, label: '★ 3' },
    { value: 2, label: '★ 2' },
    { value: 1, label: '★ 1' },
];

const dateSortOptions: { value: DateSort; label: string }[] = [
    { value: 'newest', label: 'Сначала новые' },
    { value: 'oldest', label: 'Сначала старые' },
];

const filteredReviews = computed(() => {
    let reviews = [...allReviews.value];

    if (ratingFilter.value > 0) {
        reviews = reviews.filter(r => r.rating === ratingFilter.value);
    }

    reviews.sort((a, b) => {
        const dateA = a.date ? new Date(a.date).getTime() : 0;
        const dateB = b.date ? new Date(b.date).getTime() : 0;
        return dateSort.value === 'newest' ? dateB - dateA : dateA - dateB;
    });

    return reviews;
});

// Сброс страницы при изменении фильтров
watch([ratingFilter, dateSort], () => {
    currentPage.value = 1;
});

// ================================================================
// Пагинация
// ================================================================

const perPage = 5;
const currentPage = ref(1);

const totalPages = computed(() => Math.max(1, Math.ceil(filteredReviews.value.length / perPage)));

const paginatedReviews = computed(() => {
    const start = (currentPage.value - 1) * perPage;
    return filteredReviews.value.slice(start, start + perPage);
});

const showLoadMore = computed(() => {
    return hasMore.value && currentPage.value === totalPages.value && ratingFilter.value === 0;
});

const visiblePages = computed(() => {
    const total = totalPages.value;
    const current = currentPage.value;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const pages: (number | '...')[] = [1];
    if (current > 3) pages.push('...');

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);
    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    if (current < total - 2) pages.push('...');
    pages.push(total);
    return pages;
});

function goToPage(page: number) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
        document.getElementById('reviews-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// ================================================================
// Подгрузка дополнительных отзывов (с дедупликацией)
// ================================================================

/**
 * Генерируем уникальный ключ отзыва для дедупликации.
 */
function reviewKey(review: YandexReview): string {
    return `${review.author}|${review.date}|${review.text.slice(0, 80)}`;
}

async function loadMoreReviews() {
    if (loadingMore.value || !hasMore.value) return;

    loadingMore.value = true;
    loadMoreError.value = '';

    try {
        const nextPage = scrapePage.value + 1;
        const response = await axios.post('/yandex/load-more', { page: nextPage });

        const data = response.data;

        if (data.reviews && data.reviews.length > 0) {
            // Дедупликация: не добавляем отзывы, которые уже есть
            const existingKeys = new Set(allReviews.value.map(reviewKey));
            const newReviews = (data.reviews as YandexReview[]).filter(r => !existingKeys.has(reviewKey(r)));

            if (newReviews.length > 0) {
                allReviews.value.push(...newReviews);
            }

            scrapePage.value = nextPage;
        }

        hasMore.value = data.hasMoreReviews ?? false;
    } catch (e: any) {
        loadMoreError.value = e.response?.data?.error || 'Не удалось загрузить отзывы';
    } finally {
        loadingMore.value = false;
    }
}

// ================================================================
// Галерея фото (lightbox)
// ================================================================

const lightboxOpen = ref(false);
const lightboxIndex = ref(0);
const lightboxRef = ref<HTMLElement | null>(null);

watch(lightboxOpen, async (open) => {
    if (open) {
        await nextTick();
        lightboxRef.value?.focus();
    }
});

function openLightbox(index: number) {
    lightboxIndex.value = index;
    lightboxOpen.value = true;
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    lightboxOpen.value = false;
    document.body.style.overflow = '';
}

function prevPhoto() {
    lightboxIndex.value = (lightboxIndex.value - 1 + props.photos.length) % props.photos.length;
}

function nextPhoto() {
    lightboxIndex.value = (lightboxIndex.value + 1) % props.photos.length;
}

function onLightboxKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') prevPhoto();
    if (e.key === 'ArrowRight') nextPhoto();
}

// ================================================================
// Хелперы
// ================================================================

function getInitials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

function formatDate(dateStr: string): string {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString('ru-RU', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function pluralReviews(count: number): string {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) return `${count} отзыв`;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) return `${count} отзыва`;
    return `${count} отзывов`;
}

function pluralPhotos(count: number): string {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) return `${count} фото`;
    return `${count} фото`;
}
</script>

<template>
    <Head title="Заведения из Яндекс.Карт" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="relative flex h-full flex-1 flex-col gap-6 overflow-x-hidden rounded-xl border border-sidebar-border/70 bg-gradient-to-br from-background via-background/95 to-background/80 p-6 dark:border-sidebar-border"
        >
            <div class="pointer-events-none absolute inset-0 opacity-40">
                <PlaceholderPattern />
            </div>

            <!-- Форма ввода ссылки -->
            <Card class="relative z-10 bg-card/70 shadow-md backdrop-blur">
                <CardHeader class="pb-3">
                    <CardTitle class="flex items-center gap-2">
                        <MapPin class="h-5 w-5 text-emerald-500" />
                        Ссылка на заведение
                    </CardTitle>
                    <CardDescription>
                        Вставьте ссылку на карточку заведения в Яндекс.Картах — мы покажем рейтинг,
                        количество отзывов и реальные мнения гостей.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="flex flex-col gap-4 sm:flex-row sm:items-start">
                        <div class="flex-1 space-y-1">
                            <Input
                                id="yandex_url"
                                v-model="form.yandex_url"
                                type="url"
                                name="yandex_url"
                                autocomplete="off"
                                placeholder="https://yandex.ru/maps/org/название_заведения/1234567890/"
                                required
                                class="w-full"
                            />
                            <InputError :message="form.errors.yandex_url" />
                        </div>

                        <Button
                            type="submit"
                            :disabled="form.processing"
                            class="shrink-0 gap-2"
                        >
                            <Search class="h-4 w-4" />
                            {{ form.processing ? 'Загрузка...' : 'Показать отзывы' }}
                        </Button>
                    </form>

                    <Transition
                        enter-active-class="transition ease-in-out duration-300"
                        enter-from-class="opacity-0 -translate-y-1"
                        leave-active-class="transition ease-in-out duration-200"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-if="form.recentlySuccessful"
                            class="mt-3 text-sm text-emerald-600"
                        >
                            Ссылка сохранена. Данные обновлены.
                        </p>
                    </Transition>
                </CardContent>
            </Card>

            <!-- Данные заведения — показываем только когда есть URL -->
            <template v-if="yandexUrl">
                <!-- Карточка компании -->
                <Card class="relative z-10 bg-card/70 shadow-md backdrop-blur">
                    <CardContent class="p-6">
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-4">
                                <div class="relative h-14 w-14 shrink-0">
                                    <Avatar class="h-14 w-14 rounded-xl shadow-lg ring-2 ring-border/50">
                                        <AvatarImage
                                            v-if="company?.logo"
                                            :src="company.logo"
                                            :alt="company?.name ?? 'Организация'"
                                            class="object-cover"
                                        />
                                        <AvatarFallback class="rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white">
                                            <MapPin class="h-7 w-7" />
                                        </AvatarFallback>
                                    </Avatar>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-foreground">
                                        {{ company?.name ?? 'Компания из Яндекс.Карт' }}
                                    </h2>
                                    <p class="mt-0.5 text-sm text-muted-foreground">
                                        Карточка организации в Яндекс.Картах
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-5">
                                <div class="flex flex-col items-center gap-1">
                                    <div class="flex items-baseline gap-1.5">
                                        <span class="text-3xl font-bold text-foreground">
                                            {{ company?.rating?.toFixed(1) ?? '—' }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">/ 5</span>
                                    </div>
                                    <div class="flex items-center gap-0.5">
                                        <template v-for="star in 5" :key="star">
                                            <Star
                                                class="h-4 w-4"
                                                :class="
                                                    star <= Math.round(company?.rating ?? 0)
                                                        ? 'fill-amber-400 text-amber-400'
                                                        : 'fill-muted text-muted'
                                                "
                                            />
                                        </template>
                                    </div>
                                </div>

                                <Separator orientation="vertical" class="h-12" />

                                <div class="flex flex-col items-center gap-0.5">
                                    <span class="text-2xl font-bold text-foreground">
                                        {{ company?.reviewsCount ?? 0 }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ pluralReviews(company?.reviewsCount ?? 0).replace(/^\d+\s/, '') }}
                                    </span>
                                </div>

                                <Separator orientation="vertical" class="h-12" />

                                <div class="flex items-center gap-2">
                                    <a
                                        :href="yandexUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-600 transition-colors hover:bg-emerald-500/20 dark:text-emerald-400"
                                    >
                                        <ExternalLink class="h-4 w-4" />
                                        На карте
                                    </a>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        :disabled="isRefreshing"
                                        @click="refreshData"
                                    >
                                        <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': isRefreshing }" />
                                        {{ isRefreshing ? '...' : 'Обновить' }}
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="error"
                            class="mt-4 rounded-lg border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs text-destructive"
                        >
                            {{ error }}
                        </div>
                    </CardContent>
                </Card>

                <!-- Фотографии -->
                <Card v-if="photos.length > 0" class="relative z-10 bg-card/70 shadow-md backdrop-blur">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Camera class="h-5 w-5 text-emerald-500" />
                            Фотографии
                            <Badge variant="secondary" class="ml-1">
                                {{ pluralPhotos(photos.length) }}
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            Фотографии из карточки заведения на Яндекс.Картах.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                            <button
                                v-for="(photo, index) in photos"
                                :key="index"
                                class="group relative aspect-square overflow-hidden rounded-xl border border-border/60 bg-muted/30 transition-all hover:shadow-lg hover:ring-2 hover:ring-emerald-500/40 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
                                @click="openLightbox(index)"
                            >
                                <img
                                    :src="photo.thumbnail"
                                    :alt="`Фото ${index + 1}`"
                                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    loading="lazy"
                                    @error="($event.target as HTMLImageElement).style.display = 'none'"
                                />
                                <div class="absolute inset-0 bg-black/0 transition-colors group-hover:bg-black/10" />
                                <div class="absolute bottom-2 right-2 rounded-md bg-black/50 px-1.5 py-0.5 text-xs text-white opacity-0 backdrop-blur-sm transition-opacity group-hover:opacity-100">
                                    {{ index + 1 }} / {{ photos.length }}
                                </div>
                            </button>
                        </div>
                    </CardContent>
                </Card>

                <!-- Lightbox -->
                <Teleport to="body">
                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition duration-150 ease-in"
                        leave-to-class="opacity-0"
                    >
                        <div
                            v-if="lightboxOpen"
                            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/90 backdrop-blur-sm"
                            @click.self="closeLightbox"
                            @keydown="onLightboxKeydown"
                            tabindex="0"
                            ref="lightboxRef"
                        >
                            <button
                                class="absolute right-4 top-4 z-10 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/20"
                                @click="closeLightbox"
                            >
                                <X class="h-6 w-6" />
                            </button>

                            <div class="absolute left-4 top-4 z-10 rounded-lg bg-black/50 px-3 py-1.5 text-sm font-medium text-white backdrop-blur-sm">
                                {{ lightboxIndex + 1 }} / {{ photos.length }}
                            </div>

                            <button
                                v-if="photos.length > 1"
                                class="absolute left-4 top-1/2 z-10 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20"
                                @click.stop="prevPhoto"
                            >
                                <ChevronLeft class="h-6 w-6" />
                            </button>

                            <div class="flex max-h-[85vh] max-w-[90vw] items-center justify-center">
                                <img
                                    :src="photos[lightboxIndex]?.url"
                                    :alt="`Фото ${lightboxIndex + 1}`"
                                    class="max-h-[85vh] max-w-[90vw] rounded-lg object-contain shadow-2xl"
                                />
                            </div>

                            <button
                                v-if="photos.length > 1"
                                class="absolute right-4 top-1/2 z-10 -translate-y-1/2 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/20"
                                @click.stop="nextPhoto"
                            >
                                <ChevronRight class="h-6 w-6" />
                            </button>
                        </div>
                    </Transition>
                </Teleport>

                <!-- Отзывы -->
                <Card id="reviews-section" class="relative z-10 bg-card/75 shadow-md backdrop-blur">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            Отзывы клиентов
                            <Badge v-if="allReviews.length > 0" variant="secondary" class="ml-1">
                                {{ allReviews.length }}
                                <span v-if="hasMore" class="ml-0.5">+</span>
                            </Badge>
                        </CardTitle>
                        <CardDescription>
                            Все отзывы из карточки в Яндекс.Картах.
                            <span v-if="filteredReviews.length > perPage" class="text-muted-foreground/70">
                                Страница {{ currentPage }} из {{ totalPages }}.
                            </span>
                            <span v-if="ratingFilter > 0" class="text-muted-foreground/70">
                                Показано {{ filteredReviews.length }} из {{ allReviews.length }}.
                            </span>
                        </CardDescription>

                        <!-- Фильтры -->
                        <div v-if="allReviews.length > 0" class="mt-3 flex flex-wrap items-center gap-3">
                            <div class="flex items-center gap-2">
                                <Filter class="h-4 w-4 text-muted-foreground" />
                                <div class="flex flex-wrap gap-1">
                                    <button
                                        v-for="opt in ratingOptions"
                                        :key="opt.value"
                                        class="rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors"
                                        :class="ratingFilter === opt.value
                                            ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                            : 'border-border bg-background/60 text-muted-foreground hover:border-emerald-500/40 hover:text-foreground'
                                        "
                                        @click="ratingFilter = opt.value"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <ArrowUpDown class="h-4 w-4 text-muted-foreground" />
                                <div class="flex gap-1">
                                    <button
                                        v-for="opt in dateSortOptions"
                                        :key="opt.value"
                                        class="rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors"
                                        :class="dateSort === opt.value
                                            ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                            : 'border-border bg-background/60 text-muted-foreground hover:border-emerald-500/40 hover:text-foreground'
                                        "
                                        @click="dateSort = opt.value"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="filteredReviews.length === 0 && allReviews.length > 0"
                            class="flex h-full min-h-[200px] items-center justify-center rounded-lg border border-dashed border-muted-foreground/40 bg-muted/20 text-center text-sm text-muted-foreground"
                        >
                            Нет отзывов с оценкой {{ ratingFilter }}. Попробуйте другой фильтр.
                        </div>

                        <div
                            v-else-if="allReviews.length === 0"
                            class="flex h-full min-h-[200px] items-center justify-center rounded-lg border border-dashed border-muted-foreground/40 bg-muted/20 text-center text-sm text-muted-foreground"
                        >
                            Для этой компании пока нет отзывов.
                        </div>

                        <template v-else>
                            <div class="flex flex-col gap-4">
                                <article
                                    v-for="(review, index) in paginatedReviews"
                                    :key="(currentPage - 1) * perPage + index"
                                    class="rounded-xl border border-border/60 bg-background/80 p-5 shadow-[0_2px_12px_rgb(0,0,0,0.04)] transition-shadow hover:shadow-md"
                                >
                                    <div class="mb-4 flex items-start justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <component
                                                :is="review.authorProfileUrl ? 'a' : 'div'"
                                                :href="review.authorProfileUrl || undefined"
                                                :target="review.authorProfileUrl ? '_blank' : undefined"
                                                :rel="review.authorProfileUrl ? 'noopener noreferrer' : undefined"
                                                class="shrink-0"
                                                :class="review.authorProfileUrl ? 'cursor-pointer' : ''"
                                            >
                                                <Avatar class="h-11 w-11 ring-2 ring-border">
                                                    <AvatarImage
                                                        v-if="review.authorAvatar"
                                                        :src="review.authorAvatar"
                                                        :alt="review.author"
                                                    />
                                                    <AvatarFallback class="bg-gradient-to-br from-violet-500/20 to-blue-500/20 text-sm font-semibold text-foreground">
                                                        {{ getInitials(review.author || 'Гость') }}
                                                    </AvatarFallback>
                                                </Avatar>
                                            </component>

                                            <div>
                                                <div class="flex items-center gap-1.5">
                                                    <component
                                                        :is="review.authorProfileUrl ? 'a' : 'span'"
                                                        :href="review.authorProfileUrl || undefined"
                                                        :target="review.authorProfileUrl ? '_blank' : undefined"
                                                        :rel="review.authorProfileUrl ? 'noopener noreferrer' : undefined"
                                                        class="text-sm font-semibold text-foreground"
                                                        :class="review.authorProfileUrl ? 'hover:underline' : ''"
                                                    >
                                                        {{ review.author || 'Гость' }}
                                                    </component>
                                                    <User v-if="review.authorProfileUrl" class="h-3.5 w-3.5 text-muted-foreground" />
                                                </div>
                                                <p v-if="review.authorStatus" class="text-xs text-muted-foreground">
                                                    {{ review.authorStatus }}
                                                </p>
                                                <p v-if="review.date" class="text-xs text-muted-foreground/60">
                                                    {{ formatDate(review.date) }}
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-0.5">
                                            <template v-for="star in 5" :key="star">
                                                <Star
                                                    class="h-4.5 w-4.5"
                                                    :class="
                                                        star <= review.rating
                                                            ? 'fill-amber-400 text-amber-400'
                                                            : 'fill-muted text-muted-foreground/30'
                                                    "
                                                />
                                            </template>
                                        </div>
                                    </div>

                                    <p class="text-sm leading-relaxed text-foreground/90">
                                        {{ review.text }}
                                    </p>
                                </article>
                            </div>

                            <!-- Пагинация -->
                            <div
                                v-if="totalPages > 1"
                                class="mt-6 flex items-center justify-center gap-1"
                            >
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="h-9 w-9"
                                    :disabled="currentPage === 1"
                                    @click="goToPage(currentPage - 1)"
                                >
                                    <ChevronLeft class="h-4 w-4" />
                                </Button>

                                <template v-for="(page, idx) in visiblePages" :key="idx">
                                    <span
                                        v-if="page === '...'"
                                        class="flex h-9 w-9 items-center justify-center text-sm text-muted-foreground"
                                    >
                                        …
                                    </span>
                                    <Button
                                        v-else
                                        :variant="page === currentPage ? 'default' : 'outline'"
                                        size="icon"
                                        class="h-9 w-9 text-sm"
                                        @click="goToPage(page as number)"
                                    >
                                        {{ page }}
                                    </Button>
                                </template>

                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="h-9 w-9"
                                    :disabled="currentPage === totalPages"
                                    @click="goToPage(currentPage + 1)"
                                >
                                    <ChevronRight class="h-4 w-4" />
                                </Button>
                            </div>

                            <!-- «Загрузить ещё» -->
                            <div
                                v-if="showLoadMore"
                                class="mt-6 flex flex-col items-center gap-2"
                            >
                                <Separator class="mb-2 w-full" />
                                <p class="text-sm text-muted-foreground">
                                    Загружено {{ allReviews.length }} отзывов. Доступны ещё.
                                </p>
                                <Button
                                    variant="outline"
                                    class="gap-2"
                                    :disabled="loadingMore"
                                    @click="loadMoreReviews"
                                >
                                    <Loader2 v-if="loadingMore" class="h-4 w-4 animate-spin" />
                                    <ChevronDown v-else class="h-4 w-4" />
                                    {{ loadingMore ? 'Загрузка...' : 'Загрузить ещё отзывы' }}
                                </Button>
                                <p v-if="loadMoreError" class="text-xs text-destructive">
                                    {{ loadMoreError }}
                                </p>
                            </div>
                        </template>
                    </CardContent>
                </Card>
            </template>

            <!-- Пустое состояние -->
            <div
                v-else
                class="relative z-10 flex min-h-[300px] flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-muted-foreground/30 bg-muted/10 p-8 text-center backdrop-blur-sm"
            >
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-500">
                    <MapPin class="h-8 w-8" />
                </div>
                <div class="max-w-md space-y-2">
                    <p class="text-lg font-semibold text-foreground">
                        Добавьте заведение
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Вставьте ссылку на карточку заведения из Яндекс.Карт в поле выше,
                        и здесь появятся рейтинг, фотографии и отзывы.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
