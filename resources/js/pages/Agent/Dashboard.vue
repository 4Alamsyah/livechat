<script setup lang="ts">
import { Button } from '@/components/ui/button';
import echo from '@/echo';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Mic, MicOff, PhoneOff, Send, Video, VideoOff } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

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
}

interface ChatMessage {
    id?: number;
    sender_type: 'visitor' | 'agent' | 'system';
    sender_name: string | null;
    body: string;
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

let conversationChannelName: string | null = null;

// -- Calling state --------------------------------------------------------
const inCall = ref(false);
const callStatus = ref('');
const callMode = ref<'video' | 'audio' | 'screen' | null>(null);
const pendingInvite = ref<CallSignalPayload | null>(null);
const remoteVideoEl = ref<HTMLVideoElement | null>(null);
const localVideoEl = ref<HTMLVideoElement | null>(null);
const micEnabled = ref(true);
const cameraEnabled = ref(true);

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

function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function agentFetch(url: string, options: RequestInit = {}) {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() };
    if (options.body) {
        headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    if (!res.ok) {
        throw new Error('Request failed (' + res.status + ')');
    }
    if (res.status === 204) {
        return null;
    }
    return res.json();
}

function scrollToBottom() {
    nextTick(() => {
        if (messagesEl.value) {
            messagesEl.value.scrollTop = messagesEl.value.scrollHeight;
        }
    });
}

async function selectConversation(conversation: ConversationSummary) {
    if (conversationChannelName) {
        echo.leave(conversationChannelName);
        conversationChannelName = null;
    }
    endCall(false);

    selected.value = conversation;
    messages.value = [];
    loadingMessages.value = true;

    try {
        messages.value = await agentFetch(route('agent.conversations.messages', conversation.uuid));
        scrollToBottom();
    } finally {
        loadingMessages.value = false;
    }

    conversationChannelName = 'conversation.' + conversation.uuid;
    echo.channel(conversationChannelName)
        .listen('.message.sent', (payload: ChatMessage) => {
            if (payload.sender_type !== 'agent') {
                messages.value.push(payload);
                scrollToBottom();
            }
        })
        .listen('.call.signal', handleCallSignal);
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
        await agentFetch(route('agent.conversations.messages.store', selected.value.uuid), {
            method: 'POST',
            body: JSON.stringify({ body }),
        });
    } catch {
        messages.value.push({ sender_type: 'system', sender_name: null, body: 'Failed to send message.', created_at: new Date().toISOString() });
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
        return Promise.resolve(null);
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

        await agentFetch(route('agent.conversations.call', selected.value.uuid), {
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
        await agentFetch(route('agent.conversations.call', selected.value.uuid), {
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

function endCall(notifyRemote: boolean) {
    if (notifyRemote && selected.value) {
        agentFetch(route('agent.conversations.call', selected.value.uuid), {
            method: 'POST',
            body: JSON.stringify({ type: 'end' }),
        }).catch(() => {});
    }
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
    callMode.value = null;
    callStatus.value = '';
    micEnabled.value = true;
    cameraEnabled.value = true;
}

function conversationLabel(conversation: ConversationSummary): string {
    return conversation.visitor_name || 'Visitor ' + conversation.visitor_id.slice(0, 8);
}

function formatTime(iso: string | null): string {
    if (!iso) {
        return '';
    }
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

onMounted(() => {
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
        });
    });
});

onBeforeUnmount(() => {
    if (conversationChannelName) {
        echo.leave(conversationChannelName);
    }
    echo.leave('dashboard');
    endCall(false);
    if (peer) {
        peer.destroy();
    }
});
</script>

<template>
    <Head title="Live Chat" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-[calc(100vh-8rem)] gap-4 p-4">
            <!-- Conversation list -->
            <div class="w-72 shrink-0 overflow-y-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <div class="border-b border-sidebar-border/70 p-3 text-sm font-semibold dark:border-sidebar-border">Visitors</div>
                <button
                    v-for="conversation in conversations"
                    :key="conversation.uuid"
                    class="flex w-full flex-col gap-1 border-b border-sidebar-border/50 p-3 text-left text-sm hover:bg-accent"
                    :class="{ 'bg-accent': selected?.uuid === conversation.uuid }"
                    @click="selectConversation(conversation)"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-medium">{{ conversationLabel(conversation) }}</span>
                        <span
                            class="rounded-full px-2 py-0.5 text-[10px] uppercase"
                            :class="conversation.status === 'open' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
                        >
                            {{ conversation.status }}
                        </span>
                    </div>
                    <span class="text-xs text-muted-foreground">{{ conversation.property_id || 'unknown site' }}</span>
                </button>
                <div v-if="conversations.length === 0" class="p-4 text-sm text-muted-foreground">No visitors yet.</div>
            </div>

            <!-- Conversation detail -->
            <div class="relative flex flex-1 flex-col overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                <template v-if="selected">
                    <div class="flex items-center justify-between border-b border-sidebar-border/70 p-3 dark:border-sidebar-border">
                        <div>
                            <div class="font-medium">{{ conversationLabel(selected) }}</div>
                            <div class="text-xs text-muted-foreground">{{ selected.property_id || 'unknown site' }}</div>
                        </div>
                    </div>

                    <div ref="messagesEl" class="flex-1 space-y-3 overflow-y-auto p-4">
                        <div v-if="loadingMessages" class="text-sm text-muted-foreground">Loading messages...</div>
                        <div
                            v-for="(message, index) in messages"
                            :key="message.id ?? index"
                            class="flex flex-col"
                            :class="message.sender_type === 'agent' ? 'items-end' : 'items-start'"
                        >
                            <div
                                v-if="message.sender_type !== 'system'"
                                class="max-w-[75%] rounded-2xl px-3 py-2 text-sm"
                                :class="message.sender_type === 'agent' ? 'rounded-br-sm bg-primary text-primary-foreground' : 'rounded-bl-sm bg-muted'"
                            >
                                {{ message.body }}
                            </div>
                            <div v-else class="text-xs italic text-muted-foreground">{{ message.body }}</div>
                            <div class="mt-1 text-[10px] text-muted-foreground">{{ formatTime(message.created_at) }}</div>
                        </div>
                    </div>

                    <form class="flex items-center gap-2 border-t border-sidebar-border/70 p-3 dark:border-sidebar-border" @submit.prevent="sendMessage">
                        <input
                            v-model="messageInput"
                            type="text"
                            placeholder="Type a reply..."
                            class="flex-1 rounded-full border border-input bg-background px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                        <Button type="submit" size="icon" :disabled="!messageInput.trim()">
                            <Send class="h-4 w-4" />
                        </Button>
                    </form>

                    <!-- Incoming call banner -->
                    <div
                        v-if="pendingInvite"
                        class="absolute inset-x-4 top-16 z-10 rounded-lg border border-sidebar-border bg-background p-3 shadow-lg"
                    >
                        <p class="text-sm font-medium">
                            {{ pendingInvite.visitor_name || 'Visitor' }} is calling ({{ pendingInvite.mode }})
                        </p>
                        <div class="mt-2 flex gap-2">
                            <Button size="sm" class="flex-1" @click="acceptIncomingCall">Accept</Button>
                            <Button size="sm" variant="destructive" class="flex-1" @click="rejectIncomingCall">Decline</Button>
                        </div>
                    </div>

                    <!-- In-call panel -->
                    <div v-if="inCall" class="absolute inset-0 z-20 flex flex-col bg-black">
                        <div class="relative flex-1">
                            <video ref="remoteVideoEl" autoplay playsinline class="h-full w-full object-contain"></video>
                            <video
                                ref="localVideoEl"
                                autoplay
                                playsinline
                                muted
                                class="absolute bottom-3 right-3 h-24 w-32 rounded-lg border-2 border-white object-cover"
                                :class="{ hidden: callMode === 'screen' || !localStream }"
                            ></video>
                        </div>
                        <div class="p-1 text-center text-xs text-white/80">{{ callStatus }}</div>
                        <div class="flex justify-center gap-3 bg-gray-900 p-3">
                            <button
                                v-if="callMode !== 'screen'"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-white"
                                :class="micEnabled ? 'bg-gray-700' : 'bg-red-700'"
                                @click="toggleMic"
                            >
                                <component :is="micEnabled ? Mic : MicOff" class="h-5 w-5" />
                            </button>
                            <button
                                v-if="callMode === 'video'"
                                class="flex h-11 w-11 items-center justify-center rounded-full text-white"
                                :class="cameraEnabled ? 'bg-gray-700' : 'bg-red-700'"
                                @click="toggleCamera"
                            >
                                <component :is="cameraEnabled ? Video : VideoOff" class="h-5 w-5" />
                            </button>
                            <button class="flex h-11 w-11 items-center justify-center rounded-full bg-red-600 text-white" @click="endCall(true)">
                                <PhoneOff class="h-5 w-5" />
                            </button>
                        </div>
                    </div>
                </template>
                <div v-else class="flex flex-1 items-center justify-center text-sm text-muted-foreground">
                    Select a visitor to start chatting.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
