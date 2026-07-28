<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { agentFetch, agentUpload } from '@/lib/agent-api';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Loader2, Save, Upload, X } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

interface WidgetSite {
    property_id: string;
    origins: string[];
    last_seen_at: string | null;
}

interface BusinessHoursDay {
    enabled: boolean;
    start: string;
    end: string;
}

interface SettingsForm {
    property_id: string;
    primary_color: string;
    position: 'bottom-right' | 'bottom-left';
    brand_name: string;
    welcome_message: string;
    require_name: boolean;
    collect_email: boolean;
    require_email: boolean;
    collect_topic: boolean;
    timezone: string;
    business_hours_enabled: boolean;
    business_hours: Record<string, BusinessHoursDay>;
    offline_message: string;
    logo_url: string | null;
}

const props = defineProps<{ sites: WidgetSite[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Widget Settings', href: '/agent/widget-settings' }];

const DAYS: { key: string; label: string }[] = [
    { key: 'mon', label: 'Monday' },
    { key: 'tue', label: 'Tuesday' },
    { key: 'wed', label: 'Wednesday' },
    { key: 'thu', label: 'Thursday' },
    { key: 'fri', label: 'Friday' },
    { key: 'sat', label: 'Saturday' },
    { key: 'sun', label: 'Sunday' },
];

const TIMEZONES = [
    { value: 'Asia/Jakarta', label: 'WIB — Jakarta' },
    { value: 'Asia/Makassar', label: 'WITA — Makassar' },
    { value: 'Asia/Jayapura', label: 'WIT — Jayapura' },
    { value: 'UTC', label: 'UTC' },
];

function defaultBusinessHours(): Record<string, BusinessHoursDay> {
    const hours: Record<string, BusinessHoursDay> = {};
    for (const day of DAYS) {
        hours[day.key] = { enabled: day.key !== 'sat' && day.key !== 'sun', start: '09:00', end: '17:00' };
    }
    return hours;
}

function defaultForm(propertyId: string): SettingsForm {
    return {
        property_id: propertyId,
        primary_color: '#2563eb',
        position: 'bottom-right',
        brand_name: '',
        welcome_message: '',
        require_name: false,
        collect_email: false,
        require_email: false,
        collect_topic: false,
        timezone: 'Asia/Jakarta',
        business_hours_enabled: false,
        business_hours: defaultBusinessHours(),
        offline_message: '',
        logo_url: null,
    };
}

const sites = ref<WidgetSite[]>([...props.sites]);
const selectedPropertyId = ref('');
const customSiteId = ref('');
const form = ref<SettingsForm>(defaultForm(''));
const loadingSettings = ref(false);
const saving = ref(false);
const saveError = ref('');
const justSaved = ref(false);
let savedTimer: ReturnType<typeof setTimeout> | null = null;
const logoFile = ref<File | null>(null);
const logoPreview = ref<string | null>(null);
const removeLogo = ref(false);
const logoInputEl = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (sites.value.length) {
        selectSite(sites.value[0].property_id);
    }
});

async function selectSite(propertyId: string) {
    selectedPropertyId.value = propertyId;
    loadingSettings.value = true;
    saveError.value = '';
    logoFile.value = null;
    removeLogo.value = false;

    try {
        const data = await agentFetch(route('agent.widget-settings.show', { property_id: propertyId }));
        const hours = defaultBusinessHours();
        for (const day of DAYS) {
            if (data.business_hours && data.business_hours[day.key]) {
                hours[day.key] = { ...hours[day.key], ...data.business_hours[day.key] };
            }
        }
        form.value = {
            property_id: propertyId,
            primary_color: data.primary_color || '#2563eb',
            position: data.position === 'bottom-left' ? 'bottom-left' : 'bottom-right',
            brand_name: data.brand_name || '',
            welcome_message: data.welcome_message || '',
            require_name: !!data.require_name,
            collect_email: !!data.collect_email,
            require_email: !!data.require_email,
            collect_topic: !!data.collect_topic,
            timezone: data.timezone || 'Asia/Jakarta',
            business_hours_enabled: !!data.business_hours_enabled,
            business_hours: hours,
            offline_message: data.offline_message || '',
            logo_url: data.logo_url || null,
        };
        logoPreview.value = data.logo_url || null;
    } finally {
        loadingSettings.value = false;
    }
}

function addCustomSite() {
    const value = customSiteId.value.trim();
    if (!value) {
        return;
    }
    if (!sites.value.some((s) => s.property_id === value)) {
        sites.value.push({ property_id: value, origins: [], last_seen_at: null });
    }
    customSiteId.value = '';
    selectSite(value);
}

function onLogoChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
        return;
    }
    logoFile.value = file;
    removeLogo.value = false;
    logoPreview.value = URL.createObjectURL(file);
}

function clearLogo() {
    logoFile.value = null;
    logoPreview.value = null;
    removeLogo.value = true;
    if (logoInputEl.value) {
        logoInputEl.value.value = '';
    }
}

async function save() {
    if (!form.value.property_id || saving.value) {
        return;
    }
    saving.value = true;
    saveError.value = '';

    const data = new FormData();
    data.append('property_id', form.value.property_id);
    data.append('primary_color', form.value.primary_color);
    data.append('position', form.value.position);
    data.append('brand_name', form.value.brand_name);
    data.append('welcome_message', form.value.welcome_message);
    data.append('require_name', form.value.require_name ? '1' : '0');
    data.append('collect_email', form.value.collect_email ? '1' : '0');
    data.append('require_email', form.value.require_email ? '1' : '0');
    data.append('collect_topic', form.value.collect_topic ? '1' : '0');
    data.append('timezone', form.value.timezone);
    data.append('business_hours_enabled', form.value.business_hours_enabled ? '1' : '0');
    data.append('offline_message', form.value.offline_message);
    for (const day of DAYS) {
        const config = form.value.business_hours[day.key];
        data.append(`business_hours[${day.key}][enabled]`, config.enabled ? '1' : '0');
        data.append(`business_hours[${day.key}][start]`, config.start);
        data.append(`business_hours[${day.key}][end]`, config.end);
    }
    if (logoFile.value) {
        data.append('logo', logoFile.value);
    }
    if (removeLogo.value) {
        data.append('remove_logo', '1');
    }

    try {
        const result = await agentUpload(route('agent.widget-settings.store'), data);
        form.value.logo_url = result.logo_url || null;
        logoPreview.value = result.logo_url || null;
        logoFile.value = null;
        removeLogo.value = false;
        justSaved.value = true;
        if (savedTimer) {
            clearTimeout(savedTimer);
        }
        savedTimer = setTimeout(() => {
            justSaved.value = false;
        }, 3000);

        const site = sites.value.find((s) => s.property_id === form.value.property_id);
        if (!site) {
            sites.value.push({ property_id: form.value.property_id, origins: [], last_seen_at: null });
        }
    } catch {
        saveError.value = 'Failed to save settings. Please try again.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Widget Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-3xl space-y-6 p-4 sm:p-6">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Widget Settings</h1>
                <p class="text-sm text-muted-foreground">Customize appearance, pre-chat form, and business hours per site.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-sm">Site</CardTitle>
                    <CardDescription>Settings apply to the selected site only.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <select
                        v-if="sites.length"
                        :value="selectedPropertyId"
                        class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                        @change="selectSite(($event.target as HTMLSelectElement).value)"
                    >
                        <option v-for="site in sites" :key="site.property_id" :value="site.property_id">
                            {{ site.property_id }}{{ site.origins.length ? ' — ' + site.origins.join(', ') : '' }}
                        </option>
                    </select>
                    <p v-else class="text-sm text-muted-foreground">No sites detected yet — add one below to configure it in advance.</p>

                    <div class="flex gap-2">
                        <input
                            v-model="customSiteId"
                            type="text"
                            placeholder="Add another site id"
                            class="h-9 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                            @keydown.enter.prevent="addCustomSite"
                        />
                        <Button type="button" size="sm" variant="outline" :disabled="!customSiteId.trim()" @click="addCustomSite"> Add </Button>
                    </div>
                </CardContent>
            </Card>

            <template v-if="form.property_id">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Appearance</CardTitle>
                        <CardDescription>Color, position, and branding shown in the widget.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Primary color</label>
                                <div class="flex gap-2">
                                    <input v-model="form.primary_color" type="color" class="h-9 w-12 rounded-lg border border-input p-0.5" />
                                    <input
                                        v-model="form.primary_color"
                                        type="text"
                                        class="h-9 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                                    />
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Position</label>
                                <select
                                    v-model="form.position"
                                    class="h-9 w-full rounded-lg border border-input bg-background px-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                                >
                                    <option value="bottom-right">Bottom right</option>
                                    <option value="bottom-left">Bottom left</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-xs font-medium text-muted-foreground">Brand name</label>
                            <input
                                v-model="form.brand_name"
                                type="text"
                                maxlength="120"
                                placeholder="Live Support"
                                class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                            />
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-xs font-medium text-muted-foreground">Logo</label>
                            <div class="flex items-center gap-3">
                                <img v-if="logoPreview" :src="logoPreview" alt="" class="h-10 w-10 rounded-lg border border-sidebar-border object-cover" />
                                <input ref="logoInputEl" type="file" accept="image/*" class="hidden" @change="onLogoChange" />
                                <Button type="button" size="sm" variant="outline" @click="logoInputEl?.click()">
                                    <Upload class="mr-1.5 h-3.5 w-3.5" />
                                    {{ logoPreview ? 'Replace' : 'Upload' }}
                                </Button>
                                <Button v-if="logoPreview" type="button" size="sm" variant="ghost" @click="clearLogo">
                                    <X class="mr-1.5 h-3.5 w-3.5" />
                                    Remove
                                </Button>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-xs font-medium text-muted-foreground">Welcome message</label>
                            <textarea
                                v-model="form.welcome_message"
                                rows="2"
                                maxlength="1000"
                                placeholder="Hi! Ask us anything, we're happy to help."
                                class="w-full resize-none rounded-lg border border-input bg-background p-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                            ></textarea>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Pre-chat form</CardTitle>
                        <CardDescription>Collect visitor details before the chat starts.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.require_name" type="checkbox" class="h-4 w-4 rounded border-input" />
                            Require visitor name
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.collect_email" type="checkbox" class="h-4 w-4 rounded border-input" />
                            Collect email address
                        </label>
                        <label v-if="form.collect_email" class="ml-6 flex items-center gap-2 text-sm">
                            <input v-model="form.require_email" type="checkbox" class="h-4 w-4 rounded border-input" />
                            Require email address
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.collect_topic" type="checkbox" class="h-4 w-4 rounded border-input" />
                            Ask what the visitor needs help with
                        </label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm">Business hours</CardTitle>
                        <CardDescription>Outside these hours, visitors see your offline message instead of being blocked.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.business_hours_enabled" type="checkbox" class="h-4 w-4 rounded border-input" />
                            Enable business hours
                        </label>

                        <template v-if="form.business_hours_enabled">
                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Timezone</label>
                                <select
                                    v-model="form.timezone"
                                    class="h-9 w-full rounded-lg border border-input bg-background px-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                                >
                                    <option v-for="tz in TIMEZONES" :key="tz.value" :value="tz.value">{{ tz.label }}</option>
                                </select>
                            </div>

                            <div class="space-y-1.5">
                                <div v-for="day in DAYS" :key="day.key" class="flex items-center gap-3 py-1">
                                    <label class="flex w-32 shrink-0 items-center gap-2 text-sm">
                                        <input v-model="form.business_hours[day.key].enabled" type="checkbox" class="h-4 w-4 rounded border-input" />
                                        {{ day.label }}
                                    </label>
                                    <input
                                        v-model="form.business_hours[day.key].start"
                                        type="time"
                                        :disabled="!form.business_hours[day.key].enabled"
                                        class="h-8 rounded-lg border border-input bg-background px-2 text-sm outline-none disabled:opacity-40"
                                    />
                                    <span class="text-xs text-muted-foreground">to</span>
                                    <input
                                        v-model="form.business_hours[day.key].end"
                                        type="time"
                                        :disabled="!form.business_hours[day.key].enabled"
                                        class="h-8 rounded-lg border border-input bg-background px-2 text-sm outline-none disabled:opacity-40"
                                    />
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-xs font-medium text-muted-foreground">Offline message</label>
                                <textarea
                                    v-model="form.offline_message"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="We're offline right now — leave a message and we'll reply as soon as we're back."
                                    class="w-full resize-none rounded-lg border border-input bg-background p-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                                ></textarea>
                            </div>
                        </template>
                    </CardContent>
                </Card>

                <div class="flex items-center gap-3">
                    <Button :disabled="saving || loadingSettings" @click="save">
                        <Loader2 v-if="saving" class="mr-1.5 h-4 w-4 animate-spin" />
                        <Save v-else class="mr-1.5 h-4 w-4" />
                        {{ saving ? 'Saving…' : 'Save settings' }}
                    </Button>
                    <span v-if="justSaved" class="text-sm text-emerald-600 dark:text-emerald-400">Saved</span>
                    <span v-if="saveError" class="text-sm text-destructive">{{ saveError }}</span>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
