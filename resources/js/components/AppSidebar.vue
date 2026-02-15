<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { MapPinned, Settings, Moon, Sun } from 'lucide-vue-next';
import { computed } from 'vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useAppearance } from '@/composables/useAppearance';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { type NavItem } from '@/types';
import AppLogoIcon from './AppLogoIcon.vue';

const { isCurrentUrl } = useCurrentUrl();
const { resolvedAppearance, updateAppearance } = useAppearance();

const toggleTheme = () => {
    const next = resolvedAppearance.value === 'dark' ? 'light' : 'dark';
    updateAppearance(next);
};

const mainNavItems: NavItem[] = [
    {
        title: 'Отзывы',
        href: '/yandex',
        icon: MapPinned,
    },
];

const bottomNavItems: NavItem[] = [
    {
        title: 'Настройки',
        href: editProfile(),
        icon: Settings,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <!-- Header -->
        <SidebarHeader class="relative">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()" class="group/logo">
                            <div
                                class="flex aspect-square size-8 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-400 to-cyan-500 shadow-md shadow-emerald-500/20 transition-shadow group-hover/logo:shadow-emerald-500/40"
                            >
                                <AppLogoIcon class="size-4 fill-current text-white" />
                            </div>
                            <div class="ml-1 grid flex-1 text-left text-sm">
                                <span class="mb-0.5 truncate font-semibold leading-tight">
                                    YM Insights
                                </span>
                                <span class="truncate text-[10px] text-sidebar-foreground/50">
                                    Аналитика отзывов
                                </span>
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <!-- Разделитель с градиентом -->
            <div class="mx-3 mt-1 h-px bg-gradient-to-r from-transparent via-sidebar-border to-transparent" />
        </SidebarHeader>

        <!-- Content -->
        <SidebarContent class="px-1 py-2">
            <!-- Секция навигации -->
            <div class="px-2 py-1">
                <p class="mb-2 px-2 text-[10px] font-medium uppercase tracking-widest text-sidebar-foreground/40 group-data-[collapsible=icon]:hidden">
                    Навигация
                </p>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in mainNavItems" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link
                                :href="item.href"
                                :class="[
                                    'group/nav-item relative transition-all duration-200',
                                    isCurrentUrl(item.href)
                                        ? 'font-medium'
                                        : 'opacity-70 hover:opacity-100',
                                ]"
                            >
                                <!-- Индикатор активного элемента -->
                                <div
                                    v-if="isCurrentUrl(item.href)"
                                    class="absolute -left-1 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-gradient-to-b from-emerald-400 to-cyan-500 group-data-[collapsible=icon]:hidden"
                                />
                                <component :is="item.icon" class="shrink-0" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </div>
        </SidebarContent>

        <!-- Footer -->
        <SidebarFooter>
            <!-- Разделитель -->
            <div class="mx-3 h-px bg-gradient-to-r from-transparent via-sidebar-border to-transparent" />

            <!-- Быстрые действия -->
            <div class="flex items-center justify-between px-2 py-1 group-data-[collapsible=icon]:justify-center">
                <SidebarMenu class="flex-1">
                    <SidebarMenuItem v-for="item in bottomNavItems" :key="item.title">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(item.href)"
                            :tooltip="item.title"
                        >
                            <Link :href="item.href" class="opacity-60 hover:opacity-100 transition-opacity">
                                <component :is="item.icon" class="shrink-0" />
                                <span>{{ item.title }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                <!-- Переключатель темы -->
                <button
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-sidebar-foreground/50 transition-all hover:bg-sidebar-accent hover:text-sidebar-foreground group-data-[collapsible=icon]:hidden"
                    @click="toggleTheme"
                >
                    <Sun v-if="resolvedAppearance === 'dark'" class="size-4" />
                    <Moon v-else class="size-4" />
                </button>
            </div>

            <!-- Разделитель -->
            <div class="mx-3 h-px bg-gradient-to-r from-transparent via-sidebar-border to-transparent" />

            <!-- Пользователь -->
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
