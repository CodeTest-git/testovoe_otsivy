<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Moon, Sun, MapPin, Star, MessageSquare } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { login, register, dashboard } from '@/routes';
import { useAppearance } from '@/composables/useAppearance';

type Props = {
        canRegister: boolean;
};

const props = withDefaults(defineProps<Props>(), {
        canRegister: true,
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const { resolvedAppearance, updateAppearance } = useAppearance();

const toggleTheme = () => {
    const next = resolvedAppearance.value === 'dark' ? 'light' : 'dark';
    updateAppearance(next);
};

const isVisible = ref(false);
onMounted(() => {
    setTimeout(() => {
        isVisible.value = true;
    }, 100);
});
</script>

<template>
    <Head title="Yandex Maps Insights — Отзывы заведений" />

    <div
        class="relative flex min-h-screen flex-col overflow-hidden bg-gradient-to-br from-slate-50 via-white to-slate-100 text-slate-900 dark:from-slate-950 dark:via-slate-900 dark:to-slate-950 dark:text-slate-50"
    >
        <!-- Анимированный фон -->
        <div class="pointer-events-none absolute inset-0 overflow-hidden">
            <div
                class="absolute -left-40 top-0 h-[500px] w-[500px] rounded-full bg-violet-400/15 blur-[120px] dark:bg-violet-600/20"
                :class="isVisible ? 'animate-pulse' : 'opacity-0'"
                style="animation-duration: 8s"
            />
            <div
                class="absolute -bottom-32 -right-32 h-[600px] w-[600px] rounded-full bg-emerald-400/10 blur-[120px] dark:bg-emerald-500/15"
                :class="isVisible ? 'animate-pulse' : 'opacity-0'"
                style="animation-duration: 10s"
            />
            <div
                class="absolute left-1/2 top-1/3 h-[400px] w-[400px] -translate-x-1/2 rounded-full bg-cyan-400/10 blur-[100px] dark:bg-cyan-500/10"
                :class="isVisible ? 'animate-pulse' : 'opacity-0'"
                style="animation-duration: 12s"
            />
            <div
                class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(0,0,0,0.02),transparent_60%)] dark:bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.06),transparent_60%)]"
            />
        </div>

        <!-- Верхняя панель -->
        <header
            class="relative z-10 flex items-center justify-between px-6 py-5 md:px-10"
        >
            <div class="flex items-center gap-2.5 text-sm font-semibold">
                <span
                    class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 ring-1 ring-emerald-500/30 dark:text-emerald-400"
                >
                    <MapPin class="h-4 w-4" />
                </span>
                <span class="tracking-tight text-slate-800 dark:text-slate-100">
                    Yandex Maps Insights
                </span>
            </div>

            <div class="flex items-center gap-3 text-sm">
                <!-- Переключатель темы -->
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white/80 text-slate-500 shadow-sm backdrop-blur transition hover:border-slate-300 hover:bg-white hover:text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-white/25 dark:hover:bg-white/10 dark:hover:text-slate-100"
                    @click="toggleTheme"
                >
                    <Sun
                        v-if="resolvedAppearance === 'dark'"
                        class="h-4 w-4"
                    />
                    <Moon v-else class="h-4 w-4" />
                    <span class="sr-only">Переключить тему</span>
                </button>

                <template v-if="user">
                    <Link
                        :href="dashboard()"
                        class="hidden rounded-full border border-slate-200 bg-white/80 px-5 py-2 text-xs font-medium text-slate-700 shadow-sm backdrop-blur transition hover:bg-white hover:text-slate-900 dark:border-white/15 dark:bg-white/10 dark:text-slate-50 dark:hover:bg-white/20 md:inline-flex"
                    >
                        Войти в кабинет
                </Link>
                </template>
                <template v-else>
                    <Link
                        :href="login()"
                        class="hidden rounded-full border border-slate-200 bg-white/60 px-5 py-2 text-xs font-medium text-slate-600 backdrop-blur transition hover:bg-white hover:text-slate-900 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10 dark:hover:text-white md:inline-flex"
                    >
                        Войти
                    </Link>
                </template>
            </div>
        </header>

        <!-- Hero-блок -->
        <main
            class="relative z-10 flex flex-1 flex-col items-center justify-center px-6 pb-20 pt-8 md:px-10 md:pt-0"
        >
            <div
                class="mx-auto flex w-full max-w-5xl flex-col items-center gap-12 text-center lg:flex-row lg:text-left"
            >
                <!-- Текст -->
                <div
                    class="flex-1 space-y-8 transition-all duration-700"
                    :class="
                        isVisible
                            ? 'translate-y-0 opacity-100'
                            : 'translate-y-8 opacity-0'
                    "
                >
                    <p
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-4 py-1.5 text-xs font-medium text-emerald-700 shadow-sm backdrop-blur dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-300"
                    >
                        <Star class="h-3 w-3" />
                        Мгновенная аналитика по отзывам
                    </p>

                    <div class="space-y-5">
                        <h1
                            class="text-balance text-3xl font-bold leading-tight tracking-tight text-slate-900 dark:text-white sm:text-4xl lg:text-5xl"
                        >
                            Узнайте информацию о&nbsp;любом заведении
                            <span
                                class="bg-gradient-to-r from-emerald-600 to-cyan-600 bg-clip-text text-transparent dark:from-emerald-300 dark:to-cyan-300"
                            >
                                из&nbsp;Яндекс&nbsp;Карт
                            </span>
                            прямо сейчас
                        </h1>
                        <p
                            class="mx-auto max-w-xl text-balance text-sm leading-relaxed text-slate-500 dark:text-slate-400 sm:text-base lg:mx-0"
                        >
                            Вставьте ссылку на карточку заведения в
                            Яндекс&nbsp;Картах — мы соберём рейтинг, количество
                            отзывов и реальные мнения гостей в одном удобном
                            окне.
                        </p>
                    </div>

                    <div
                        class="flex flex-col items-center gap-4 pt-2 sm:flex-row lg:justify-start"
                    >
                        <template v-if="user">
                            <Link
                                :href="dashboard()"
                                class="group inline-flex items-center justify-center gap-2 rounded-full bg-emerald-500 px-7 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/25 transition hover:bg-emerald-600 hover:shadow-emerald-500/40 dark:bg-emerald-400 dark:text-emerald-950 dark:hover:bg-emerald-300"
                            >
                                Перейти к заведениям
                                <ArrowRight
                                    class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                                />
                            </Link>
                        </template>
                        <template v-else>
                            <Link
                                v-if="props.canRegister"
                                :href="register()"
                                class="group inline-flex items-center justify-center gap-2 rounded-full bg-emerald-500 px-7 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/25 transition hover:bg-emerald-600 hover:shadow-emerald-500/40 dark:bg-emerald-400 dark:text-emerald-950 dark:hover:bg-emerald-300"
                            >
                                Начать бесплатно
                                <ArrowRight
                                    class="h-4 w-4 transition-transform group-hover:translate-x-0.5"
                                />
                            </Link>
                            <Link
                                :href="login()"
                                class="text-sm font-medium text-slate-500 underline-offset-4 transition hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-white"
                            >
                                Уже есть аккаунт? Войти
                            </Link>
                        </template>
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 pt-2 text-[11px] text-slate-400 dark:text-slate-500 lg:justify-start"
                    >
                        <div class="flex items-center gap-1.5">
                            <span
                                class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500 dark:bg-emerald-400"
                            />
                            Любые заведения
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="inline-block h-1.5 w-1.5 rounded-full bg-cyan-500 dark:bg-cyan-400"
                            />
                            Рейтинг и отзывы
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="inline-block h-1.5 w-1.5 rounded-full bg-violet-500 dark:bg-violet-400"
                            />
                            Бесплатно
                        </div>
                    </div>
                </div>

                <!-- Анимированная карточка справа -->
                <div
                    class="relative flex flex-1 items-center justify-center transition-all delay-300 duration-700"
                    :class="
                        isVisible
                            ? 'translate-y-0 opacity-100'
                            : 'translate-y-12 opacity-0'
                    "
                >
                    <div
                        class="relative w-full max-w-md overflow-hidden rounded-3xl border border-slate-200/80 bg-gradient-to-br from-white/90 via-slate-50/50 to-white/90 p-6 shadow-2xl shadow-slate-300/30 backdrop-blur-xl dark:border-white/10 dark:from-slate-900/90 dark:via-slate-800/50 dark:to-slate-900/90 dark:shadow-black/40"
                    >
                        <div
                            class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_rgba(52,211,153,0.08),transparent_50%)] dark:bg-[radial-gradient(ellipse_at_top,_rgba(52,211,153,0.12),transparent_50%)]"
                        />

                        <div class="relative flex flex-col gap-5">
                            <!-- Заголовок карточки -->
                            <div class="flex items-start justify-between">
                                <div>
                                    <p
                                        class="text-[10px] font-medium uppercase tracking-widest text-slate-400 dark:text-slate-500"
                                    >
                                        Пример
                                    </p>
                                    <p
                                        class="mt-1 text-base font-semibold text-slate-800 dark:text-slate-50"
                                    >
                                        Кофейня «Точка Встречи»
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Москва, ул. Тверская, 12
                                    </p>
                                </div>
                                <div
                                    class="flex items-center gap-1.5 rounded-xl bg-emerald-500/10 px-3 py-2 ring-1 ring-emerald-500/30"
                                >
                                    <Star
                                        class="h-4 w-4 fill-emerald-500 text-emerald-500 dark:fill-emerald-400 dark:text-emerald-400"
                                    />
                                    <span
                                        class="text-lg font-bold leading-none text-emerald-600 dark:text-emerald-300"
                                        >4.8</span
                                    >
                                </div>
                            </div>

                            <!-- Статистика -->
                            <div class="grid grid-cols-3 gap-2.5">
                                <div
                                    class="rounded-2xl bg-slate-100/80 p-3 ring-1 ring-slate-200/80 dark:bg-white/5 dark:ring-white/5"
                                >
                                    <p
                                        class="text-sm font-semibold text-slate-800 dark:text-slate-100"
                                    >
                                        327
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400"
                                    >
                                        отзывов
                                    </p>
                                </div>
                                <div
                                    class="rounded-2xl bg-slate-100/80 p-3 ring-1 ring-slate-200/80 dark:bg-white/5 dark:ring-white/5"
                                >
                                    <p
                                        class="text-sm font-semibold text-emerald-600 dark:text-emerald-300"
                                    >
                                        92%
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400"
                                    >
                                        позитивных
                                    </p>
                                </div>
                                <div
                                    class="rounded-2xl bg-slate-100/80 p-3 ring-1 ring-slate-200/80 dark:bg-white/5 dark:ring-white/5"
                                >
                                    <p
                                        class="text-sm font-semibold text-slate-800 dark:text-slate-100"
                                    >
                                        4.8
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-slate-500 dark:text-slate-400"
                                    >
                                        рейтинг
                                    </p>
                                </div>
                            </div>

                            <!-- Отзыв -->
                            <div
                                class="flex flex-col gap-3 rounded-2xl bg-slate-50/80 p-4 ring-1 ring-slate-200/60 dark:bg-slate-950/60 dark:ring-white/5"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-violet-500/15 text-[10px] font-bold text-violet-600 dark:bg-violet-500/20 dark:text-violet-300"
                                    >
                                        ИП
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs font-medium text-slate-700 dark:text-slate-200"
                                        >
                                            Иван Петров
                                        </p>
                                        <div
                                            class="flex items-center gap-0.5"
                                        >
                                            <Star
                                                v-for="i in 5"
                                                :key="i"
                                                class="h-2.5 w-2.5 fill-amber-400 text-amber-400"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <p
                                    class="text-xs leading-relaxed text-slate-600 dark:text-slate-300"
                                >
                                    «Отличное место для встреч, очень вежливый
                                    персонал и невероятно вкусный кофе. Обязательно вернусь!»
                                </p>
                            </div>

                            <div
                                class="flex items-center gap-2 rounded-xl bg-slate-100/80 px-3 py-2 text-[10px] text-slate-400 dark:bg-white/5 dark:text-slate-400"
                            >
                                <MessageSquare class="h-3 w-3 text-slate-400 dark:text-slate-500" />
                                <span>Данные из Яндекс Карт обновляются автоматически</span>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </main>

        <!-- Нижняя часть -->
        <footer class="relative z-10 px-6 pb-6 text-center">
            <p class="text-[11px] text-slate-400 dark:text-slate-600">
                Yandex Maps Insights &copy; 2026. Сервис аналитики отзывов.
            </p>
        </footer>
    </div>
</template>
