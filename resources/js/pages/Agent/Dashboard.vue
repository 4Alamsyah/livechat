<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import echo from '@/echo';
import AppLayout from '@/layouts/AppLayout.vue';
import { agentFetch, agentUpload } from '@/lib/agent-api';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Image as ImageIcon,
    Loader2,
    Megaphone,
    MessageSquare,
    Mic,
    MicOff,
    PhoneOff,
    Search,
    Send,
    Trash2,
    Video,
    VideoOff,
    XCircle,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

interface AgentSummary {
    id: number;
    name: string;
}

interface ConversationSummary {
    id: number;
    uuid: string;
    property_id: string | null;
    visitor_id: string;
    visitor_name: string | null;
    agent_id: number | null;
    status: string;
    last_message_at: string | null;
    created_at: string;
    agent?: AgentSummary | null;
    messages_count?: number;
    unread_count?: number;
}

interface ToastPayload {
    title: string;
    body: string;
}

interface ChatMessage {
    id?: number;
    sender_type: 'visitor' | 'agent' | 'system';
    sender_name: string | null;
    type?: 'text' | 'image';
    body: string;
    attachment_url?: string | null;
    created_at: string;
}

interface CallSignalPayload {
    type: 'invite' | 'accept' | 'reject' | 'end';
    from: 'visitor' | 'agent';
    mode?: 'video' | 'audio' | 'screen';
    peer_id?: string;
    visitor_name?: string | null;
}

declare global {
    interface Window {
        Peer: any;
    }
}

const props = defineProps<{ conversations: ConversationSummary[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Live Chat', href: '/agent/dashboard' }];

const conversations = ref<ConversationSummary[]>([...props.conversations]);
const selected = ref<ConversationSummary | null>(null);
const messages = ref<ChatMessage[]>([]);
const messageInput = ref('');
const messagesEl = ref<HTMLElement | null>(null);
const loadingMessages = ref(false);
const endingSession = ref(false);
const toast = ref<ToastPayload | null>(null);
const imageInputEl = ref<HTMLInputElement | null>(null);

const isClosed = computed(() => selected.value?.status === 'closed');

// -- List filtering & responsive master/detail -----------------------------
const search = ref('');
const showDetailOnMobile = ref(false);

const filteredConversations = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) {
        return conversations.value;
    }
    return conversations.value.filter(
        (conversation) =>
            conversationLabel(conversation).toLowerCase().includes(term) || (conversation.property_id ?? '').toLowerCase().includes(term),
    );
});

const openCount = computed(() => conversations.value.filter((conversation) => conversation.status === 'open').length);

const subscribedChannels = new Set<string>();
let toastTimer: ReturnType<typeof setTimeout> | null = null;

// -- Calling state --------------------------------------------------------
const inCall = ref(false);
const callStatus = ref('');
const callMode = ref<'video' | 'audio' | 'screen' | null>(null);
const pendingInvite = ref<CallSignalPayload | null>(null);
const isEndingCall = ref(false);
const remoteVideoEl = ref<HTMLVideoElement | null>(null);
const localVideoEl = ref<HTMLVideoElement | null>(null);
const micEnabled = ref(true);
const cameraEnabled = ref(true);

const callOverlayState = computed<'connecting' | 'ending' | null>(() => {
    if (isEndingCall.value) {
        return 'ending';
    }
    if (inCall.value && callStatus.value && callStatus.value !== 'Connected') {
        return 'connecting';
    }
    return null;
});

const PEERJS_CDN_URL = 'https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js';

let peer: any = null;
let activeCall: any = null;
let localStream: MediaStream | null = null;
let peerReady: Promise<any> | null = null;
let peerjsScriptPromise: Promise<void> | null = null;

function loadPeerJs(): Promise<void> {
    if (window.Peer) {
        return Promise.resolve();
    }
    if (!peerjsScriptPromise) {
        peerjsScriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = PEERJS_CDN_URL;
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Failed to load PeerJS'));
            document.head.appendChild(script);
        });
    }
    return peerjsScriptPromise;
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

function subscribeToConversation(uuid: string) {
    if (subscribedChannels.has(uuid)) {
        return;
    }
    subscribedChannels.add(uuid);
    echo.channel('conversation.' + uuid)
        .listen('.message.sent', (payload: ChatMessage) => handleIncomingMessage(uuid, payload))
        .listen('.call.signal', (payload: CallSignalPayload) => {
            if (selected.value?.uuid === uuid) {
                handleCallSignal(payload);
            }
        })
        .listen('.conversation.closed', (payload: { closed_by: 'agent' | 'visitor' }) => {
            if (payload.closed_by !== 'agent') {
                applyConversationClosed(uuid, payload.closed_by);
            }
        });
}

function applyConversationClosed(uuid: string, closedBy: 'agent' | 'visitor') {
    const conversation = conversations.value.find((c) => c.uuid === uuid);
    if (conversation?.status === 'closed') {
        return;
    }
    if (conversation) {
        conversation.status = 'closed';
    }
    if (selected.value?.uuid === uuid) {
        messages.value.push({
            sender_type: 'system',
            sender_name: null,
            body: closedBy === 'visitor' ? 'Visitor ended the chat.' : 'You ended the chat.',
            created_at: new Date().toISOString(),
        });
        scrollToBottom();
        endCall(false);
    }
}

function handleIncomingMessage(uuid: string, payload: ChatMessage) {
    if (selected.value?.uuid === uuid) {
        if (payload.sender_type !== 'agent') {
            messages.value.push(payload);
            scrollToBottom();
        }
        return;
    }
    if (payload.sender_type !== 'visitor') {
        return;
    }
    const conversation = conversations.value.find((c) => c.uuid === uuid);
    if (conversation) {
        conversation.unread_count = (conversation.unread_count ?? 0) + 1;
    }
    notify(conversation ? conversationLabel(conversation) : 'Visitor', payload.body);
}

function notify(title: string, body: string) {
    if (typeof Notification !== 'undefined' && Notification.permission === 'granted' && document.hidden) {
        new Notification(title, { body });
    }
    toast.value = { title, body };
    if (toastTimer) {
        clearTimeout(toastTimer);
    }
    toastTimer = setTimeout(() => {
        toast.value = null;
    }, 6000);
}

async function selectConversation(conversation: ConversationSummary) {
    endCall(false, true);

    selected.value = conversation;
    showDetailOnMobile.value = true;
    conversation.unread_count = 0;
    messages.value = [];
    loadingMessages.value = true;

    try {
        messages.value = await agentFetch(route('agent.conversations.messages', { uuid: conversation.uuid }));
        scrollToBottom();
    } finally {
        loadingMessages.value = false;
    }

    subscribeToConversation(conversation.uuid);
}

async function sendMessage() {
    const body = messageInput.value.trim();
    if (!body || !selected.value) {
        return;
    }
    messageInput.value = '';
    messages.value.push({ sender_type: 'agent', sender_name: 'You', body, created_at: new Date().toISOString() });
    scrollToBottom();

    try {
        await agentFetch(route('agent.conversations.messages.store', { uuid: selected.value.uuid }), {
            method: 'POST',
            body: JSON.stringify({ body }),
        });
    } catch {
        messages.value.push({ sender_type: 'system', sender_name: null, body: 'Failed to send message.', created_at: new Date().toISOString() });
    }
}

function triggerImagePicker() {
    imageInputEl.value?.click();
}

function onImageSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file) {
        sendImage(file);
    }
}

async function sendImage(file: File) {
    if (!selected.value) {
        return;
    }
    const formData = new FormData();
    formData.append('image', file);

    try {
        const message = await agentUpload(route('agent.conversations.messages.store', { uuid: selected.value.uuid }), formData);
        messages.value.push(message);
        scrollToBottom();
    } catch {
        messages.value.push({ sender_type: 'system', sender_name: null, body: 'Failed to send image.', created_at: new Date().toISOString() });
    }
}

async function endSession() {
    if (!selected.value || endingSession.value) {
        return;
    }
    endingSession.value = true;
    try {
        await agentFetch(route('agent.conversations.close', { uuid: selected.value.uuid }), { method: 'POST' });
        applyConversationClosed(selected.value.uuid, 'agent');
    } catch {
        // ignore, agent can retry
    } finally {
        endingSession.value = false;
    }
}

// -- Calling ---------------------------------------------------------------

function ensurePeer(): Promise<any> {
    if (peer && !peer.destroyed) {
        return Promise.resolve(peer);
    }
    if (peerReady) {
        return peerReady;
    }
    peerReady = loadPeerJs().then(
        () =>
            new Promise((resolve, reject) => {
                const p = new window.Peer(undefined, { debug: 1 });
                p.on('open', () => {
                    peer = p;
                    peer.on('call', (call: any) => {
                        call.answer(localStream || undefined);
                        bindCallEvents(call);
                    });
                    resolve(p);
                });
                p.on('error', (err: unknown) => reject(err));
            }),
    );
    return peerReady;
}

function getLocalStream(mode: 'video' | 'audio' | 'screen'): Promise<MediaStream | null> {
    if (mode === 'screen') {
        return navigator.mediaDevices.getUserMedia({ audio: true }).catch(() => null);
    }
    return navigator.mediaDevices.getUserMedia({ video: mode === 'video', audio: true });
}

function bindCallEvents(call: any) {
    activeCall = call;
    call.on('stream', (remoteStream: MediaStream) => {
        if (remoteVideoEl.value) {
            remoteVideoEl.value.srcObject = remoteStream;
        }
        callStatus.value = 'Connected';
    });
    call.on('close', () => endCall(false));
    call.on('error', (err: unknown) => console.error('[agent] call error', err));
}

async function acceptIncomingCall() {
    const invite = pendingInvite.value;
    if (!invite || !selected.value) {
        return;
    }
    pendingInvite.value = null;
    callMode.value = invite.mode ?? null;
    inCall.value = true;
    callStatus.value = 'Connecting...';

    try {
        localStream = await getLocalStream(invite.mode ?? 'video');
        if (localStream && localVideoEl.value) {
            localVideoEl.value.srcObject = localStream;
        }
        const p = await ensurePeer();

        if (invite.mode !== 'screen') {
            const call = p.call(invite.peer_id, localStream || undefined);
            bindCallEvents(call);
        }

        await agentFetch(route('agent.conversations.call', { uuid: selected.value.uuid }), {
            method: 'POST',
            body: JSON.stringify({ type: 'accept', peer_id: p.id }),
        });
    } catch (err) {
        console.error('[agent] failed to accept call', err);
        endCall(true);
    }
}

async function rejectIncomingCall() {
    if (selected.value) {
        await agentFetch(route('agent.conversations.call', { uuid: selected.value.uuid }), {
            method: 'POST',
            body: JSON.stringify({ type: 'reject' }),
        }).catch(() => {});
    }
    pendingInvite.value = null;
}

function handleCallSignal(payload: CallSignalPayload) {
    if (payload.from !== 'visitor' || !selected.value) {
        return;
    }
    if (payload.type === 'invite') {
        pendingInvite.value = payload;
    } else if (payload.type === 'end') {
        endCall(false);
    }
}

function toggleMic() {
    if (!localStream) {
        return;
    }
    localStream.getAudioTracks().forEach((t) => (t.enabled = !t.enabled));
    micEnabled.value = localStream.getAudioTracks().some((t) => t.enabled);
}

function toggleCamera() {
    if (!localStream) {
        return;
    }
    localStream.getVideoTracks().forEach((t) => (t.enabled = !t.enabled));
    cameraEnabled.value = localStream.getVideoTracks().some((t) => t.enabled);
}

function endCall(notifyRemote: boolean, immediate = false) {
    if (notifyRemote && selected.value) {
        agentFetch(route('agent.conversations.call', { uuid: selected.value.uuid }), {
            method: 'POST',
            body: JSON.stringify({ type: 'end' }),
        }).catch(() => {});
    }
    if (!inCall.value) {
        return;
    }
    if (immediate || isEndingCall.value) {
        finishEndCall();
        return;
    }
    isEndingCall.value = true;
    callStatus.value = 'Ending call...';
    window.setTimeout(finishEndCall, 550);
}

function finishEndCall() {
    if (activeCall) {
        try {
            activeCall.close();
        } catch {
            // ignore
        }
        activeCall = null;
    }
    if (localStream) {
        localStream.getTracks().forEach((t) => t.stop());
        localStream = null;
    }
    if (remoteVideoEl.value) {
        remoteVideoEl.value.srcObject = null;
    }
    if (localVideoEl.value) {
        localVideoEl.value.srcObject = null;
    }
    inCall.value = false;
    isEndingCall.value = false;
    callMode.value = null;
    callStatus.value = '';
    micEnabled.value = true;
    cameraEnabled.value = true;
}

function conversationLabel(conversation: ConversationSummary): string {
    return conversation.visitor_name || 'Visitor ' + conversation.visitor_id.slice(0, 8);
}

const AVATAR_TONES = [
    'bg-blue-500/15 text-blue-600 dark:text-blue-400',
    'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
    'bg-violet-500/15 text-violet-600 dark:text-violet-400',
    'bg-amber-500/15 text-amber-600 dark:text-amber-400',
    'bg-rose-500/15 text-rose-600 dark:text-rose-400',
    'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
];

function conversationInitials(conversation: ConversationSummary): string {
    const words = conversationLabel(conversation).split(' ').filter(Boolean);
    return words
        .slice(0, 2)
        .map((word) => word[0])
        .join('')
        .toUpperCase();
}

function avatarTone(conversation: ConversationSummary): string {
    const seed = conversation.uuid || conversation.visitor_id;
    let hash = 0;
    for (let index = 0; index < seed.length; index++) {
        hash = (hash * 31 + seed.charCodeAt(index)) >>> 0;
    }
    return AVATAR_TONES[hash % AVATAR_TONES.length];
}

function backToList() {
    showDetailOnMobile.value = false;
}

function formatTime(iso: string | null): string {
    if (!iso) {
        return '';
    }
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

onMounted(() => {
    if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    conversations.value.forEach((conversation) => subscribeToConversation(conversation.uuid));

    echo.channel('dashboard').listen('.conversation.started', (payload: { uuid: string; visitor_name: string | null; property_id: string | null; created_at: string }) => {
        conversations.value.unshift({
            id: 0,
            uuid: payload.uuid,
            property_id: payload.property_id,
            visitor_id: '',
            visitor_name: payload.visitor_name,
            agent_id: null,
            status: 'open',
            last_message_at: null,
            created_at: payload.created_at,
            messages_count: 0,
            unread_count: 0,
        });
        subscribeToConversation(payload.uuid);
        notify(payload.visitor_name || 'New visitor', 'Started a new conversation');
    });
});

// -- Announcements --------------------------------------------------------

type AnnouncementLevel = 'info' | 'warning' | 'critical';

interface Announcement {
    id: number;
    title: string | null;
    message: string;
    level: AnnouncementLevel;
    property_ids: string[] | null;
    expires_at: string | null;
}

interface WidgetSite {
    property_id: string;
    origins: string[];
    last_seen_at: string | null;
}

const announcementDialogOpen = ref(false);
const announcements = ref<Announcement[]>([]);
const knownSites = ref<WidgetSite[]>([]);
const sendingAnnouncement = ref(false);
const announcementError = ref('');

const form = ref({
    title: '',
    message: '',
    level: 'warning' as AnnouncementLevel,
    allSites: true,
    targets: [] as string[],
    customTarget: '',
    expiresInMinutes: '' as string,
});

async function openAnnouncementDialog() {
    announcementDialogOpen.value = true;
    announcementError.value = '';
    const [list, sites] = await Promise.all([
        agentFetch(route('agent.announcements.index')),
        agentFetch(route('agent.property-ids')),
    ]);
    announcements.value = list;
    knownSites.value = sites;
}

function toggleTarget(propertyId: string) {
    const targets = form.value.targets;
    const index = targets.indexOf(propertyId);
    if (index === -1) {
        targets.push(propertyId);
    } else {
        targets.splice(index, 1);
    }
}

function addCustomTarget() {
    const value = form.value.customTarget.trim();
    if (value && !form.value.targets.includes(value)) {
        form.value.targets.push(value);
        if (!knownSites.value.some((site) => site.property_id === value)) {
            knownSites.value.push({ property_id: value, origins: [], last_seen_at: null });
        }
    }
    form.value.customTarget = '';
}

async function sendAnnouncement() {
    if (!form.value.message.trim() || sendingAnnouncement.value) {
        return;
    }
    sendingAnnouncement.value = true;
    announcementError.value = '';

    try {
        await agentFetch(route('agent.announcements.store'), {
            method: 'POST',
            body: JSON.stringify({
                title: form.value.title.trim() || null,
                message: form.value.message.trim(),
                level: form.value.level,
                property_ids: form.value.allSites ? [] : form.value.targets,
                expires_in_minutes: form.value.expiresInMinutes ? Number(form.value.expiresInMinutes) : null,
            }),
        });
        announcements.value = await agentFetch(route('agent.announcements.index'));
        form.value.title = '';
        form.value.message = '';
    } catch {
        announcementError.value = 'Failed to send announcement. Please try again.';
    } finally {
        sendingAnnouncement.value = false;
    }
}

async function deactivateAnnouncement(announcement: Announcement) {
    await agentFetch(route('agent.announcements.deactivate', { announcement: announcement.id }), { method: 'POST' });
    announcements.value = announcements.value.filter((a) => a.id !== announcement.id);
}

function announcementTargetLabel(announcement: Announcement): string {
    return announcement.property_ids?.length ? announcement.property_ids.join(', ') : 'All sites';
}

onBeforeUnmount(() => {
    subscribedChannels.forEach((uuid) => echo.leave('conversation.' + uuid));
    echo.leave('dashboard');
    endCall(false, true);
    if (peer) {
        peer.destroy();
    }
});
</script>

<template>
    <Head title="Live Chat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100dvh-4rem)] overflow-hidden md:gap-4 md:p-4">
            <!-- Conversation list -->
            <aside
                class="w-full flex-col overflow-hidden bg-card md:w-72 md:shrink-0 md:rounded-2xl md:border md:border-sidebar-border/70 md:shadow-sm lg:w-80 dark:md:border-sidebar-border"
                :class="showDetailOnMobile ? 'hidden md:flex' : 'flex'"
            >
                <div class="shrink-0 space-y-3 border-b border-sidebar-border/70 p-3 sm:p-4 dark:border-sidebar-border">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold tracking-tight">Visitors</h2>
                        <div class="flex items-center gap-2">
                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                {{ openCount }} open
                            </span>
                            <button
                                type="button"
                                class="flex h-7 w-7 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                title="Broadcast a service notice"
                                aria-label="Broadcast a service notice"
                                @click="openAnnouncementDialog"
                            >
                                <Megaphone class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search visitors..."
                            class="h-9 w-full rounded-lg border border-input bg-background pl-9 pr-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                        />
                    </div>
                </div>

                <div class="min-h-0 flex-1 space-y-1 overflow-y-auto p-2">
                    <button
                        v-for="conversation in filteredConversations"
                        :key="conversation.uuid"
                        class="group relative flex w-full items-center gap-3 rounded-xl p-2.5 text-left transition-colors hover:bg-accent"
                        :class="{ 'bg-accent': selected?.uuid === conversation.uuid }"
                        @click="selectConversation(conversation)"
                    >
                        <span
                            v-if="selected?.uuid === conversation.uuid"
                            class="absolute inset-y-2 left-0 w-0.5 rounded-full bg-primary"
                        ></span>
                        <span class="relative shrink-0">
                            <span
                                class="flex h-10 w-10 items-center justify-center rounded-full text-xs font-semibold"
                                :class="avatarTone(conversation)"
                            >
                                {{ conversationInitials(conversation) }}
                            </span>
                            <span
                                class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-card"
                                :class="conversation.status === 'open' ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
                            ></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-medium">{{ conversationLabel(conversation) }}</span>
                                <span class="shrink-0 text-[10px] text-muted-foreground">
                                    {{ formatTime(conversation.last_message_at ?? conversation.created_at) }}
                                </span>
                            </span>
                            <span class="mt-0.5 flex items-center justify-between gap-2">
                                <span class="truncate text-xs text-muted-foreground">{{ conversation.property_id || 'unknown site' }}</span>
                                <span
                                    v-if="conversation.unread_count"
                                    class="flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground"
                                >
                                    {{ conversation.unread_count > 9 ? '9+' : conversation.unread_count }}
                                </span>
                            </span>
                        </span>
                    </button>

                    <div v-if="filteredConversations.length === 0" class="flex flex-col items-center gap-2 px-4 py-12 text-center">
                        <MessageSquare class="h-8 w-8 text-muted-foreground/40" />
                        <p class="text-sm text-muted-foreground">
                            {{ search ? 'No visitors match your search.' : 'No visitors yet.' }}
                        </p>
                    </div>
                </div>
            </aside>

            <!-- Conversation detail -->
            <section
                class="relative min-w-0 flex-1 flex-col overflow-hidden bg-card md:rounded-2xl md:border md:border-sidebar-border/70 md:shadow-sm dark:md:border-sidebar-border"
                :class="showDetailOnMobile ? 'flex' : 'hidden md:flex'"
            >
                <template v-if="selected">
                    <header class="flex shrink-0 items-center gap-3 border-b border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <button
                            type="button"
                            class="-ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-accent md:hidden"
                            aria-label="Back to visitors"
                            @click="backToList"
                        >
                            <ArrowLeft class="h-4 w-4" />
                        </button>
                        <span
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="avatarTone(selected)"
                        >
                            {{ conversationInitials(selected) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold">{{ conversationLabel(selected) }}</div>
                            <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="isClosed ? 'bg-muted-foreground/40' : 'bg-emerald-500'"></span>
                                <span class="truncate">{{ isClosed ? 'Chat ended' : selected.property_id || 'unknown site' }}</span>
                            </div>
                        </div>
                        <Button v-if="!isClosed" size="sm" variant="outline" class="shrink-0" :disabled="endingSession" @click="endSession">
                            <Loader2 v-if="endingSession" class="h-4 w-4 animate-spin sm:mr-1.5" />
                            <XCircle v-else class="h-4 w-4 sm:mr-1.5" />
                            <span class="hidden sm:inline">{{ endingSession ? 'Ending…' : 'End Chat' }}</span>
                        </Button>
                    </header>

                    <div ref="messagesEl" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-muted/20 p-3 sm:p-4">
                        <div v-if="loadingMessages" class="space-y-3">
                            <div v-for="n in 3" :key="n" class="flex" :class="n % 2 === 0 ? 'justify-end' : 'justify-start'">
                                <div class="h-10 animate-pulse rounded-2xl bg-muted" :class="n % 2 === 0 ? 'w-40' : 'w-52'"></div>
                            </div>
                        </div>
                        <div v-else-if="messages.length === 0" class="flex h-full items-center justify-center text-sm text-muted-foreground">
                            No messages yet — say hello.
                        </div>

                        <div
                            v-for="(message, index) in messages"
                            :key="message.id ?? index"
                            class="flex flex-col"
                            :class="message.sender_type === 'agent' ? 'items-end' : 'items-start'"
                        >
                            <div
                                v-if="message.sender_type !== 'system'"
                                class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm sm:max-w-[70%]"
                                :class="
                                    message.sender_type === 'agent'
                                        ? 'rounded-br-md bg-primary text-primary-foreground'
                                        : 'rounded-bl-md border border-sidebar-border/60 bg-card dark:border-sidebar-border'
                                "
                            >
                                <a v-if="message.type === 'image' && message.attachment_url" :href="message.attachment_url" target="_blank" rel="noopener">
                                    <img
                                        :src="message.attachment_url"
                                        class="max-h-52 w-full max-w-[16rem] rounded-xl object-cover transition-opacity hover:opacity-90"
                                        :class="{ 'mb-1.5': message.body }"
                                    />
                                </a>
                                <p v-if="message.body" class="whitespace-pre-wrap break-words">{{ message.body }}</p>
                            </div>
                            <div v-else class="mx-auto rounded-full bg-muted px-3 py-1 text-[11px] italic text-muted-foreground">
                                {{ message.body }}
                            </div>
                            <div v-if="message.sender_type !== 'system'" class="mt-1 px-1 text-[10px] text-muted-foreground">
                                {{ formatTime(message.created_at) }}
                            </div>
                        </div>
                    </div>

                    <form
                        class="flex shrink-0 items-center gap-2 border-t border-sidebar-border/70 p-2.5 sm:p-3 dark:border-sidebar-border"
                        @submit.prevent="sendMessage"
                    >
                        <input ref="imageInputEl" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
                        <Button type="button" size="icon" variant="outline" class="shrink-0 rounded-full" :disabled="isClosed" @click="triggerImagePicker">
                            <ImageIcon class="h-4 w-4" />
                        </Button>
                        <input
                            v-model="messageInput"
                            type="text"
                            :placeholder="isClosed ? 'This chat has ended' : 'Type a reply...'"
                            :disabled="isClosed"
                            class="h-10 min-w-0 flex-1 rounded-full border border-input bg-background px-4 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30 disabled:opacity-50"
                        />
                        <Button type="submit" size="icon" class="shrink-0 rounded-full" :disabled="!messageInput.trim() || isClosed">
                            <Send class="h-4 w-4" />
                        </Button>
                    </form>

                    <!-- Incoming call banner -->
                    <Transition
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="-translate-y-2 opacity-0"
                        leave-active-class="transition duration-150 ease-in"
                        leave-to-class="-translate-y-2 opacity-0"
                    >
                        <div
                            v-if="pendingInvite"
                            class="absolute inset-x-3 top-[4.5rem] z-10 rounded-2xl border border-sidebar-border bg-background/95 p-3 shadow-xl backdrop-blur sm:inset-x-auto sm:right-4 sm:w-80"
                        >
                            <div class="flex items-center gap-3">
                                <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500/30"></span>
                                    <Video class="relative h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">{{ pendingInvite.visitor_name || 'Visitor' }} is calling</p>
                                    <p class="text-xs capitalize text-muted-foreground">{{ pendingInvite.mode }} call</p>
                                </div>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <Button size="sm" class="flex-1" @click="acceptIncomingCall">Accept</Button>
                                <Button size="sm" variant="destructive" class="flex-1" @click="rejectIncomingCall">Decline</Button>
                            </div>
                        </div>
                    </Transition>

                    <!-- In-call panel -->
                    <div v-if="inCall" class="absolute inset-0 z-20 flex flex-col bg-black md:rounded-2xl md:overflow-hidden">
                        <div class="relative min-h-0 flex-1">
                            <video ref="remoteVideoEl" autoplay playsinline class="h-full w-full object-contain"></video>
                            <video
                                ref="localVideoEl"
                                autoplay
                                playsinline
                                muted
                                class="absolute bottom-3 right-3 h-20 w-28 rounded-xl border border-white/20 object-cover shadow-lg sm:h-24 sm:w-32"
                                :class="{ hidden: callMode === 'screen' || !localStream }"
                            ></video>
                            <Transition
                                enter-active-class="transition duration-300 ease-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition duration-300 ease-in"
                                leave-to-class="opacity-0"
                            >
                                <div
                                    v-if="callOverlayState"
                                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-4 bg-black/85 backdrop-blur-sm"
                                >
                                    <div class="relative flex h-20 w-20 items-center justify-center">
                                        <span
                                            class="absolute inline-flex h-full w-full animate-ping rounded-full"
                                            :class="callOverlayState === 'ending' ? 'bg-red-500/30' : 'bg-emerald-500/30'"
                                        ></span>
                                        <span
                                            class="absolute h-full w-full animate-spin rounded-full border-2 border-white/15"
                                            :class="callOverlayState === 'ending' ? 'border-t-red-500' : 'border-t-emerald-400'"
                                        ></span>
                                        <component
                                            :is="callOverlayState === 'ending' ? PhoneOff : callMode === 'audio' ? Mic : Video"
                                            class="relative h-7 w-7 text-white"
                                        />
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <p class="text-sm font-medium text-white">
                                            {{ callOverlayState === 'ending' ? 'Ending call' : 'Connecting' }}
                                        </p>
                                        <span class="flex items-center gap-1">
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-white/80" style="animation-delay: 0ms"></span>
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-white/80" style="animation-delay: 150ms"></span>
                                            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-white/80" style="animation-delay: 300ms"></span>
                                        </span>
                                    </div>
                                </div>
                            </Transition>
                        </div>
                        <div class="flex shrink-0 justify-center gap-3 bg-gray-900/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full text-white transition-colors"
                                :class="micEnabled ? 'bg-white/10 hover:bg-white/20' : 'bg-red-600 hover:bg-red-500'"
                                :aria-label="micEnabled ? 'Mute microphone' : 'Unmute microphone'"
                                @click="toggleMic"
                            >
                                <component :is="micEnabled ? Mic : MicOff" class="h-5 w-5" />
                            </button>
                            <button
                                v-if="callMode === 'video'"
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full text-white transition-colors"
                                :class="cameraEnabled ? 'bg-white/10 hover:bg-white/20' : 'bg-red-600 hover:bg-red-500'"
                                :aria-label="cameraEnabled ? 'Turn camera off' : 'Turn camera on'"
                                @click="toggleCamera"
                            >
                                <component :is="cameraEnabled ? Video : VideoOff" class="h-5 w-5" />
                            </button>
                            <button
                                type="button"
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-red-600 text-white transition-colors hover:bg-red-500"
                                aria-label="Hang up"
                                @click="endCall(true)"
                            >
                                <PhoneOff class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </template>

                <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted">
                        <MessageSquare class="h-6 w-6 text-muted-foreground" />
                    </span>
                    <div>
                        <p class="text-sm font-medium">No conversation selected</p>
                        <p class="mt-1 text-sm text-muted-foreground">Pick a visitor from the list to start chatting.</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- New message toast -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-y-2 opacity-0 sm:translate-x-2 sm:translate-y-0"
            leave-active-class="transition duration-200 ease-in"
            leave-to-class="translate-y-2 opacity-0 sm:translate-x-2 sm:translate-y-0"
        >
            <div
                v-if="toast"
                class="fixed inset-x-4 bottom-4 z-50 cursor-pointer rounded-xl border border-sidebar-border bg-background/95 p-3 shadow-xl backdrop-blur sm:inset-x-auto sm:right-4 sm:w-80 dark:border-sidebar-border"
                @click="toast = null"
            >
                <div class="truncate text-sm font-medium">{{ toast.title }}</div>
                <div class="mt-1 line-clamp-2 text-sm text-muted-foreground">{{ toast.body }}</div>
            </div>
        </Transition>

        <!-- Service notice composer -->
        <Dialog v-model:open="announcementDialogOpen">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Broadcast a service notice</DialogTitle>
                    <DialogDescription>
                        Pushes a popup to every widget on the selected sites, whether or not the visitor has started a chat.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Title</label>
                        <input
                            v-model="form.title"
                            type="text"
                            maxlength="120"
                            placeholder="Scheduled maintenance"
                            class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                        />
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-xs font-medium text-muted-foreground">Message</label>
                        <textarea
                            v-model="form.message"
                            rows="3"
                            maxlength="2000"
                            placeholder="The server restarts at 22:00 WIB. Chat will be unavailable for about 15 minutes."
                            class="w-full resize-none rounded-lg border border-input bg-background p-3 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                        ></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label class="text-xs font-medium text-muted-foreground">Level</label>
                            <select
                                v-model="form.level"
                                class="h-9 w-full rounded-lg border border-input bg-background px-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                            >
                                <option value="info">Info</option>
                                <option value="warning">Warning</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs font-medium text-muted-foreground">Auto-expire</label>
                            <select
                                v-model="form.expiresInMinutes"
                                class="h-9 w-full rounded-lg border border-input bg-background px-2 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                            >
                                <option value="">No expiry</option>
                                <option value="30">30 minutes</option>
                                <option value="60">1 hour</option>
                                <option value="120">2 hours</option>
                                <option value="240">4 hours</option>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.allSites" type="checkbox" class="h-4 w-4 rounded border-input" />
                            <span class="font-medium">All sites</span>
                        </label>

                        <div v-if="!form.allSites" class="space-y-2 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border">
                            <p v-if="!knownSites.length" class="text-xs text-muted-foreground">
                                No sites detected yet — they appear here once a page with the widget is opened.
                            </p>
                            <label v-for="site in knownSites" :key="site.property_id" class="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 shrink-0 rounded border-input"
                                    :checked="form.targets.includes(site.property_id)"
                                    @change="toggleTarget(site.property_id)"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ site.property_id }}</span>
                                    <span v-if="site.origins.length" class="block truncate text-[11px] text-muted-foreground">
                                        {{ site.origins.join(', ') }}
                                    </span>
                                    <span v-else class="block text-[11px] italic text-muted-foreground">domain not detected yet</span>
                                </span>
                            </label>
                            <div class="flex gap-2 pt-1">
                                <input
                                    v-model="form.customTarget"
                                    type="text"
                                    placeholder="Add another site id"
                                    class="h-8 min-w-0 flex-1 rounded-lg border border-input bg-background px-2.5 text-sm outline-none transition focus:border-ring focus:ring-2 focus:ring-ring/30"
                                    @keydown.enter.prevent="addCustomTarget"
                                />
                                <Button type="button" size="sm" variant="outline" :disabled="!form.customTarget.trim()" @click="addCustomTarget">
                                    Add
                                </Button>
                            </div>
                        </div>
                    </div>

                    <p v-if="announcementError" class="text-sm text-destructive">{{ announcementError }}</p>

                    <Button
                        class="w-full"
                        :disabled="!form.message.trim() || sendingAnnouncement || (!form.allSites && !form.targets.length)"
                        @click="sendAnnouncement"
                    >
                        <Loader2 v-if="sendingAnnouncement" class="mr-1.5 h-4 w-4 animate-spin" />
                        <Megaphone v-else class="mr-1.5 h-4 w-4" />
                        {{ sendingAnnouncement ? 'Broadcasting…' : 'Broadcast now' }}
                    </Button>

                    <div v-if="announcements.length" class="space-y-2 border-t border-sidebar-border/70 pt-3 dark:border-sidebar-border">
                        <p class="text-xs font-medium text-muted-foreground">Currently showing</p>
                        <div
                            v-for="announcement in announcements"
                            :key="announcement.id"
                            class="flex items-start gap-2 rounded-lg border border-sidebar-border/70 p-2.5 dark:border-sidebar-border"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ announcement.title || 'Service notice' }}</p>
                                <p class="line-clamp-2 text-xs text-muted-foreground">{{ announcement.message }}</p>
                                <p class="mt-1 truncate text-[11px] text-muted-foreground">{{ announcementTargetLabel(announcement) }}</p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-destructive"
                                title="Stop showing this notice"
                                aria-label="Stop showing this notice"
                                @click="deactivateAnnouncement(announcement)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
