/**
 * Live Support Widget (PoC)
 * Embed with: <script src="https://your-domain/js/widget.js" data-property-id="XYZ"></script>
 *
 * Vanilla JS, no build step. Loads Pusher-js (talks to Laravel Reverb, which is
 * wire-compatible with the Pusher protocol) and PeerJS from CDN at runtime.
 */
(function () {
    'use strict';

    if (window.__liveSupportWidgetLoaded) {
        return;
    }
    window.__liveSupportWidgetLoaded = true;

    var CDN = {
        pusher: 'https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js',
        peerjs: 'https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js',
    };

    var currentScript =
        document.currentScript ||
        (function () {
            var scripts = document.getElementsByTagName('script');
            return scripts[scripts.length - 1];
        })();

    var BASE_URL = new URL(currentScript.src).origin;
    var PROPERTY_ID = currentScript.getAttribute('data-property-id') || 'default';

    var STORAGE_VISITOR_KEY = 'lc_visitor_id_' + PROPERTY_ID;
    var STORAGE_CONVERSATION_KEY = 'lc_conversation_' + PROPERTY_ID;
    var STORAGE_NAME_KEY = 'lc_visitor_name_' + PROPERTY_ID;
    var STORAGE_DISMISSED_ANNOUNCEMENT_KEY = 'lc_dismissed_announcement_' + PROPERTY_ID;
    var STORAGE_HEARTBEAT_KEY = 'lc_site_reported_' + PROPERTY_ID;

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function getVisitorId() {
        var id = localStorage.getItem(STORAGE_VISITOR_KEY);
        if (!id) {
            id = uuid();
            localStorage.setItem(STORAGE_VISITOR_KEY, id);
        }
        return id;
    }

    var scriptCache = {};
    function loadScript(url) {
        if (scriptCache[url]) {
            return scriptCache[url];
        }
        scriptCache[url] = new Promise(function (resolve, reject) {
            var el = document.createElement('script');
            el.src = url;
            el.async = true;
            el.onload = resolve;
            el.onerror = function () {
                reject(new Error('Failed to load ' + url));
            };
            document.head.appendChild(el);
        });
        return scriptCache[url];
    }

    function api(path, options) {
        options = options || {};
        var headers = { Accept: 'application/json' };
        if (options.body) {
            headers['Content-Type'] = 'application/json';
        }
        return fetch(BASE_URL + path, {
            method: options.method || 'GET',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () {
                    return {};
                }).then(function (data) {
                    throw new Error(data.message || 'Request failed (' + res.status + ')');
                });
            }
            if (res.status === 204) {
                return null;
            }
            return res.json();
        });
    }

    function apiUpload(path, formData) {
        return fetch(BASE_URL + path, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: formData,
        }).then(function (res) {
            if (!res.ok) {
                return res.json().catch(function () {
                    return {};
                }).then(function (data) {
                    throw new Error(data.message || 'Request failed (' + res.status + ')');
                });
            }
            return res.json();
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function formatTime(iso) {
        try {
            var d = new Date(iso);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    /** Computes a darker shade of a hex color for hover states. */
    function darkenColor(hex, amount) {
        hex = (hex || '#2563eb').replace('#', '');
        if (hex.length === 3) {
            hex = hex.split('').map(function (c) {
                return c + c;
            }).join('');
        }
        var num = parseInt(hex, 16);
        if (isNaN(num)) {
            return '#1d4ed8';
        }
        var channel = function (shift) {
            var value = Math.max(0, Math.floor(((num >> shift) & 0xff) * (1 - amount)));
            return ('0' + value.toString(16)).slice(-2);
        };
        return '#' + channel(16) + channel(8) + channel(0);
    }

    // ---------------------------------------------------------------------
    // Styles
    // ---------------------------------------------------------------------

    function buildStyle(color, side) {
        var hover = darkenColor(color, 0.15);
        return '\
        .lc-launcher{position:fixed;bottom:20px;' + side + ':20px;width:60px;height:60px;border-radius:50%;\
            background:' + color + ';color:#fff;border:none;box-shadow:0 6px 18px rgba(0,0,0,.25);cursor:pointer;\
            z-index:2147483000;display:flex;align-items:center;justify-content:center;font-size:26px;}\
        .lc-launcher:hover{background:' + hover + ';}\
        .lc-panel{position:fixed;bottom:92px;' + side + ':20px;width:340px;max-width:calc(100vw - 24px);\
            height:520px;max-height:calc(100vh - 120px);background:#fff;border-radius:14px;\
            box-shadow:0 12px 40px rgba(0,0,0,.28);z-index:2147483000;display:none;flex-direction:column;\
            overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;\
            font-size:14px;color:#111827;}\
        .lc-panel.lc-open{display:flex;}\
        .lc-header{background:' + color + ';color:#fff;padding:14px 16px;display:flex;align-items:center;\
            justify-content:space-between;}\
        .lc-header-brand{display:flex;align-items:center;min-width:0;}\
        .lc-logo{width:24px;height:24px;border-radius:6px;object-fit:cover;margin-right:8px;flex-shrink:0;}\
        .lc-header h3{margin:0;font-size:15px;font-weight:600;}\
        .lc-header .lc-sub{font-size:11px;opacity:.85;}\
        .lc-header-actions{display:flex;align-items:center;gap:10px;}\
        .lc-end-btn{background:rgba(255,255,255,.18);border:none;color:#fff;font-size:11px;font-weight:600;\
            padding:5px 9px;border-radius:6px;cursor:pointer;}\
        .lc-end-btn:hover{background:rgba(255,255,255,.28);}\
        .lc-close{background:transparent;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;}\
        .lc-body{flex:1;display:flex;flex-direction:column;min-height:0;}\
        .lc-prechat{padding:16px;display:flex;flex-direction:column;gap:10px;}\
        .lc-prechat input{padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;}\
        .lc-prechat button{padding:10px;border:none;border-radius:8px;background:' + color + ';color:#fff;\
            font-weight:600;cursor:pointer;}\
        .lc-welcome{margin:0;font-size:13px;line-height:1.4;color:#374151;}\
        .lc-offline-banner{background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:12px;\
            line-height:1.4;border-radius:8px;padding:9px 11px;}\
        .lc-messages{flex:1;overflow-y:auto;padding:12px;background:#f9fafb;}\
        .lc-msg{margin-bottom:10px;display:flex;flex-direction:column;max-width:82%;}\
        .lc-msg.visitor{margin-left:auto;align-items:flex-end;}\
        .lc-msg.agent,.lc-msg.system{margin-right:auto;align-items:flex-start;}\
        .lc-bubble{padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;}\
        .lc-msg.visitor .lc-bubble{background:' + color + ';color:#fff;border-bottom-right-radius:4px;}\
        .lc-msg.agent .lc-bubble{background:#e5e7eb;color:#111827;border-bottom-left-radius:4px;}\
        .lc-msg.system .lc-bubble{background:transparent;color:#6b7280;font-size:12px;font-style:italic;padding:0;}\
        .lc-meta{font-size:10px;color:#9ca3af;margin-top:2px;}\
        .lc-callbar{display:flex;gap:6px;padding:8px 10px;border-top:1px solid #e5e7eb;background:#fff;}\
        .lc-callbar button{flex:1;padding:8px 4px;border:1px solid #d1d5db;background:#fff;border-radius:8px;\
            cursor:pointer;font-size:11px;display:flex;flex-direction:column;align-items:center;gap:2px;}\
        .lc-callbar button:hover{background:#f3f4f6;}\
        .lc-callbar button:disabled{opacity:.5;cursor:not-allowed;}\
        .lc-callbar button span.lc-ico{font-size:16px;}\
        .lc-inputbar{display:flex;gap:8px;padding:10px;border-top:1px solid #e5e7eb;background:#fff;}\
        .lc-inputbar input[type=text]{flex:1;padding:9px 12px;border:1px solid #d1d5db;border-radius:20px;font-size:14px;}\
        .lc-inputbar button{width:38px;height:38px;border-radius:50%;border:none;background:' + color + ';color:#fff;\
            cursor:pointer;font-size:16px;flex-shrink:0;}\
        .lc-inputbar button:disabled{opacity:.5;cursor:not-allowed;}\
        .lc-attach-btn{background:#e5e7eb !important;color:#374151 !important;}\
        .lc-image{display:block;max-width:180px;max-height:180px;border-radius:12px;margin-bottom:2px;\
            object-fit:cover;}\
        .lc-restart-bar{padding:12px;border-top:1px solid #e5e7eb;background:#fff;}\
        .lc-restart-btn{width:100%;padding:10px;border:none;border-radius:8px;background:' + color + ';color:#fff;\
            font-weight:600;cursor:pointer;font-size:14px;}\
        .lc-restart-btn:hover{background:' + hover + ';}\
        .lc-call-panel{position:absolute;inset:0;background:#111827;display:none;flex-direction:column;z-index:5;}\
        .lc-call-panel.lc-active{display:flex;}\
        .lc-call-videos{flex:1;position:relative;background:#000;}\
        .lc-remote-video{width:100%;height:100%;object-fit:contain;background:#000;}\
        .lc-local-video{position:absolute;bottom:10px;right:10px;width:90px;height:68px;object-fit:cover;\
            border-radius:8px;border:2px solid #fff;background:#000;}\
        .lc-call-status{color:#fff;text-align:center;padding:8px;font-size:12px;opacity:.85;}\
        .lc-call-controls{display:flex;justify-content:center;gap:10px;padding:12px;background:#1f2937;}\
        .lc-call-controls button{width:44px;height:44px;border-radius:50%;border:none;cursor:pointer;\
            font-size:16px;background:#374151;color:#fff;}\
        .lc-call-controls button.lc-hangup{background:#dc2626;}\
        .lc-call-controls button.lc-off{background:#b91c1c;}\
        .lc-incoming{position:absolute;left:10px;right:10px;top:10px;background:#111827;color:#fff;\
            border-radius:10px;padding:10px 12px;z-index:6;box-shadow:0 8px 24px rgba(0,0,0,.3);display:none;}\
        .lc-incoming.lc-show{display:block;}\
        .lc-incoming .lc-row{display:flex;gap:8px;margin-top:8px;}\
        .lc-incoming button{flex:1;padding:7px;border-radius:6px;border:none;cursor:pointer;font-size:12px;}\
        .lc-incoming .lc-accept{background:#16a34a;color:#fff;}\
        .lc-incoming .lc-reject{background:#dc2626;color:#fff;}\
        .lc-badge{position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:50%;\
            width:18px;height:18px;font-size:11px;display:flex;align-items:center;justify-content:center;}\
        .lc-toast{position:fixed;bottom:92px;' + side + ':20px;width:280px;max-width:calc(100vw - 24px);\
            background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:12px 14px;\
            z-index:2147483000;display:none;cursor:pointer;font-family:-apple-system,BlinkMacSystemFont,\
            "Segoe UI",Roboto,Helvetica,Arial,sans-serif;}\
        .lc-toast.lc-show{display:block;}\
        .lc-toast-title{font-size:12px;font-weight:700;color:' + color + ';margin-bottom:3px;}\
        .lc-toast-body{font-size:13px;color:#111827;overflow:hidden;text-overflow:ellipsis;\
            display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}\
        .lc-announce{position:fixed;bottom:92px;' + side + ':20px;width:320px;max-width:calc(100vw - 24px);\
            background:#fff;border-radius:12px;border-left:4px solid #d97706;\
            box-shadow:0 12px 34px rgba(0,0,0,.28);padding:13px 15px;z-index:2147483001;display:none;\
            font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;\
            animation:lc-announce-in .28s ease-out;}\
        .lc-announce.lc-show{display:block;}\
        .lc-announce.lc-level-info{border-left-color:#2563eb;}\
        .lc-announce.lc-level-critical{border-left-color:#dc2626;}\
        .lc-announce-head{display:flex;align-items:center;gap:7px;margin-bottom:5px;}\
        .lc-announce-ico{font-size:15px;line-height:1;}\
        .lc-announce-title{font-size:13px;font-weight:700;color:#92400e;}\
        .lc-announce.lc-level-info .lc-announce-title{color:#1d4ed8;}\
        .lc-announce.lc-level-critical .lc-announce-title{color:#b91c1c;}\
        .lc-announce-body{font-size:13px;line-height:1.45;color:#374151;white-space:pre-wrap;word-break:break-word;}\
        .lc-announce-dismiss{margin-top:10px;width:100%;padding:7px;border:none;border-radius:7px;\
            background:#f3f4f6;color:#374151;font-size:12px;font-weight:600;cursor:pointer;}\
        .lc-announce-dismiss:hover{background:#e5e7eb;}\
        @keyframes lc-announce-in{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}\
        ';
    }

    function injectStyles(color, side) {
        var existing = document.getElementById('lc-widget-styles');
        if (!existing) {
            existing = document.createElement('style');
            existing.id = 'lc-widget-styles';
            document.head.appendChild(existing);
        }
        existing.textContent = buildStyle(color, side);
    }

    // ---------------------------------------------------------------------
    // Widget class
    // ---------------------------------------------------------------------

    function Widget(settings) {
        this.settings = settings || {};
        this.visitorId = getVisitorId();
        this.visitorName = localStorage.getItem(STORAGE_NAME_KEY) || '';
        this.conversationUuid = sessionStorage.getItem(STORAGE_CONVERSATION_KEY) || null;
        this.config = null;
        this.pusher = null;
        this.channel = null;
        this.peer = null;
        this.activeCall = null;
        this.localStream = null;
        this.callMode = null; // 'video' | 'audio' | 'screen'
        this.unreadCount = 0;
        this.pusherReady = null;
        this.currentAnnouncementId = null;

        this.buildDom();
        this.bindEvents();

        if (this.conversationUuid) {
            this.enterChatView();
        }

        this.reportSite();
        this.checkAnnouncement();
        this.connectAnnouncements();
    }

    Widget.prototype.buildDom = function () {
        var settings = this.settings;
        var side = settings.position === 'bottom-left' ? 'left' : 'right';
        var isOnline = settings.is_online !== false;
        injectStyles(settings.primary_color || '#2563eb', side);

        var launcher = document.createElement('button');
        launcher.className = 'lc-launcher';
        launcher.setAttribute('aria-label', 'Open live chat');
        launcher.innerHTML = '💬<span class="lc-badge" style="display:none"></span>';
        document.body.appendChild(launcher);
        this.launcherEl = launcher;
        this.badgeEl = launcher.querySelector('.lc-badge');

        var toast = document.createElement('div');
        toast.className = 'lc-toast';
        toast.innerHTML = '<div class="lc-toast-title">New message</div><div class="lc-toast-body"></div>';
        document.body.appendChild(toast);
        this.toastEl = toast;
        this.toastBodyEl = toast.querySelector('.lc-toast-body');
        this.toastTimer = null;

        var announce = document.createElement('div');
        announce.className = 'lc-announce';
        announce.innerHTML =
            '<div class="lc-announce-head">' +
                '<span class="lc-announce-ico">⚠️</span>' +
                '<span class="lc-announce-title"></span>' +
            '</div>' +
            '<div class="lc-announce-body"></div>' +
            '<button class="lc-announce-dismiss" type="button">Got it</button>';
        document.body.appendChild(announce);
        this.announceEl = announce;
        this.announceIcoEl = announce.querySelector('.lc-announce-ico');
        this.announceTitleEl = announce.querySelector('.lc-announce-title');
        this.announceBodyEl = announce.querySelector('.lc-announce-body');
        this.announceDismissEl = announce.querySelector('.lc-announce-dismiss');

        var brandName = settings.brand_name || 'Live Support';
        var logoHtml = settings.logo_url
            ? '<img class="lc-logo" src="' + escapeHtml(settings.logo_url) + '" alt="" />'
            : '';
        var welcomeHtml = settings.welcome_message
            ? '<p class="lc-welcome">' + escapeHtml(settings.welcome_message) + '</p>'
            : '';
        var offlineHtml = !isOnline && settings.offline_message
            ? '<div class="lc-offline-banner">' + escapeHtml(settings.offline_message) + '</div>'
            : '';
        var emailHtml = settings.collect_email
            ? '<input type="email" class="lc-email-input"' + (settings.require_email ? ' required' : '') +
              ' placeholder="Your email' + (settings.require_email ? '' : ' (optional)') + '" />'
            : '';
        var topicHtml = settings.collect_topic
            ? '<input type="text" class="lc-topic-input" placeholder="What can we help with? (optional)" />'
            : '';

        var panel = document.createElement('div');
        panel.className = 'lc-panel';
        panel.innerHTML =
            '<div class="lc-header">' +
                '<div class="lc-header-brand">' + logoHtml +
                    '<div><h3>' + escapeHtml(brandName) + '</h3><div class="lc-sub">We usually reply in a few minutes</div></div>' +
                '</div>' +
                '<div class="lc-header-actions">' +
                    '<button class="lc-end-btn" style="display:none;" title="End chat">End chat</button>' +
                    '<button class="lc-close" aria-label="Close">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div class="lc-body" style="position:relative;">' +
                '<div class="lc-prechat">' +
                    offlineHtml + welcomeHtml +
                    '<input type="text" class="lc-name-input"' + (settings.require_name ? ' required' : '') +
                    ' placeholder="Your name' + (settings.require_name ? '' : ' (optional)') + '" />' +
                    emailHtml + topicHtml +
                    '<button class="lc-start-btn">Start Chat</button>' +
                '</div>' +
                '<div class="lc-chatview" style="display:none;flex:1;flex-direction:column;min-height:0;">' +
                    '<div class="lc-messages"></div>' +
                    '<div class="lc-callbar">' +
                        '<button class="lc-call-btn" data-mode="video"><span class="lc-ico">📹</span>Video</button>' +
                        '<button class="lc-call-btn" data-mode="audio"><span class="lc-ico">📞</span>Audio</button>' +
                        '<button class="lc-call-btn" data-mode="screen"><span class="lc-ico">🖥️</span>Share Screen</button>' +
                    '</div>' +
                    '<div class="lc-inputbar">' +
                        '<button class="lc-attach-btn" type="button" title="Send image">📎</button>' +
                        '<input type="file" class="lc-image-input" accept="image/*" style="display:none;" />' +
                        '<input type="text" class="lc-msg-input" placeholder="Type a message..." />' +
                        '<button class="lc-send-btn">➤</button>' +
                    '</div>' +
                    '<div class="lc-restart-bar" style="display:none;">' +
                        '<button class="lc-restart-btn">Start New Chat</button>' +
                    '</div>' +
                '</div>' +
                '<div class="lc-call-panel">' +
                    '<div class="lc-call-videos">' +
                        '<video class="lc-remote-video" autoplay playsinline></video>' +
                        '<video class="lc-local-video" autoplay playsinline muted></video>' +
                    '</div>' +
                    '<div class="lc-call-status">Calling...</div>' +
                    '<div class="lc-call-controls">' +
                        '<button class="lc-mute-btn" title="Mute">🎤</button>' +
                        '<button class="lc-video-btn" title="Camera">📷</button>' +
                        '<button class="lc-hangup-btn lc-hangup" title="Hang up">✖</button>' +
                    '</div>' +
                '</div>' +
                '<div class="lc-incoming">' +
                    '<div class="lc-incoming-text"></div>' +
                    '<div class="lc-row">' +
                        '<button class="lc-accept">Accept</button>' +
                        '<button class="lc-reject">Decline</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(panel);
        this.panelEl = panel;

        this.nameInputEl = panel.querySelector('.lc-name-input');
        this.emailInputEl = panel.querySelector('.lc-email-input');
        this.topicInputEl = panel.querySelector('.lc-topic-input');
        this.startBtnEl = panel.querySelector('.lc-start-btn');
        this.prechatEl = panel.querySelector('.lc-prechat');
        this.chatViewEl = panel.querySelector('.lc-chatview');
        this.messagesEl = panel.querySelector('.lc-messages');
        this.msgInputEl = panel.querySelector('.lc-msg-input');
        this.sendBtnEl = panel.querySelector('.lc-send-btn');
        this.attachBtnEl = panel.querySelector('.lc-attach-btn');
        this.imageInputEl = panel.querySelector('.lc-image-input');
        this.endBtnEl = panel.querySelector('.lc-end-btn');
        this.restartBarEl = panel.querySelector('.lc-restart-bar');
        this.restartBtnEl = panel.querySelector('.lc-restart-btn');
        this.callBarEl = panel.querySelector('.lc-callbar');
        this.inputBarEl = panel.querySelector('.lc-inputbar');
        this.callPanelEl = panel.querySelector('.lc-call-panel');
        this.remoteVideoEl = panel.querySelector('.lc-remote-video');
        this.localVideoEl = panel.querySelector('.lc-local-video');
        this.callStatusEl = panel.querySelector('.lc-call-status');
        this.muteBtnEl = panel.querySelector('.lc-mute-btn');
        this.videoBtnEl = panel.querySelector('.lc-video-btn');
        this.hangupBtnEl = panel.querySelector('.lc-hangup-btn');
        this.incomingEl = panel.querySelector('.lc-incoming');
        this.incomingTextEl = panel.querySelector('.lc-incoming-text');
        this.acceptBtnEl = panel.querySelector('.lc-accept');
        this.rejectBtnEl = panel.querySelector('.lc-reject');

        if (this.visitorName) {
            this.nameInputEl.value = this.visitorName;
        }
    };

    Widget.prototype.bindEvents = function () {
        var self = this;

        this.toastEl.addEventListener('click', function () {
            self.hideToast();
            self.setPanelOpen(true);
        });

        this.announceDismissEl.addEventListener('click', function () {
            self.dismissAnnouncement();
        });

        this.launcherEl.addEventListener('click', function () {
            self.togglePanel();
        });
        this.panelEl.querySelector('.lc-close').addEventListener('click', function () {
            self.setPanelOpen(false);
        });

        this.startBtnEl.addEventListener('click', function () {
            self.startConversation();
        });
        this.nameInputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                self.startConversation();
            }
        });

        this.sendBtnEl.addEventListener('click', function () {
            self.sendMessage();
        });
        this.msgInputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                self.sendMessage();
            }
        });

        this.attachBtnEl.addEventListener('click', function () {
            self.imageInputEl.click();
        });
        this.imageInputEl.addEventListener('change', function () {
            var file = self.imageInputEl.files[0];
            self.imageInputEl.value = '';
            if (file) {
                self.sendImage(file);
            }
        });

        this.endBtnEl.addEventListener('click', function () {
            self.endSession();
        });
        this.restartBtnEl.addEventListener('click', function () {
            self.startNewSession();
        });

        Array.prototype.forEach.call(this.panelEl.querySelectorAll('.lc-call-btn'), function (btn) {
            btn.addEventListener('click', function () {
                self.startCall(btn.getAttribute('data-mode'));
            });
        });

        this.hangupBtnEl.addEventListener('click', function () {
            self.endCall(true);
        });
        this.muteBtnEl.addEventListener('click', function () {
            self.toggleTrack('audio', self.muteBtnEl);
        });
        this.videoBtnEl.addEventListener('click', function () {
            self.toggleTrack('video', self.videoBtnEl);
        });

        this.acceptBtnEl.addEventListener('click', function () {
            self.acceptIncomingCall();
        });
        this.rejectBtnEl.addEventListener('click', function () {
            self.rejectIncomingCall();
        });
    };

    Widget.prototype.togglePanel = function () {
        this.setPanelOpen(!this.panelEl.classList.contains('lc-open'));
    };

    Widget.prototype.setPanelOpen = function (open) {
        this.panelEl.classList.toggle('lc-open', open);
        if (open) {
            this.unreadCount = 0;
            this.updateBadge();
            this.scrollToBottom();
            this.hideToast();
        }
    };

    Widget.prototype.showToast = function (m) {
        this.toastBodyEl.textContent = m.body;
        this.toastEl.classList.add('lc-show');
        clearTimeout(this.toastTimer);
        this.toastTimer = setTimeout(this.hideToast.bind(this), 6000);
    };

    Widget.prototype.hideToast = function () {
        this.toastEl.classList.remove('lc-show');
        clearTimeout(this.toastTimer);
    };

    Widget.prototype.requestNotificationPermission = function () {
        if (window.Notification && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    };

    Widget.prototype.notifyNewAgentMessage = function (m) {
        if (!this.panelEl.classList.contains('lc-open')) {
            this.showToast(m);
        }
        if (document.hidden && window.Notification && Notification.permission === 'granted') {
            try {
                var notification = new Notification('New message from ' + (m.sender_name || 'Support'), { body: m.body });
                notification.onclick = function () {
                    window.focus();
                    notification.close();
                };
            } catch (e) {
                // Notification constructor can throw on unsupported platforms; ignore.
            }
        }
    };

    Widget.prototype.updateBadge = function () {
        if (this.unreadCount > 0) {
            this.badgeEl.style.display = 'flex';
            this.badgeEl.textContent = this.unreadCount > 9 ? '9+' : String(this.unreadCount);
        } else {
            this.badgeEl.style.display = 'none';
        }
    };

    Widget.prototype.startConversation = function () {
        var self = this;
        var name = this.nameInputEl.value.trim();
        var email = this.emailInputEl ? this.emailInputEl.value.trim() : '';
        var topic = this.topicInputEl ? this.topicInputEl.value.trim() : '';

        if (this.nameInputEl.hasAttribute('required') && !name) {
            this.nameInputEl.reportValidity();
            return;
        }
        if (this.emailInputEl && this.emailInputEl.hasAttribute('required') && !this.emailInputEl.checkValidity()) {
            this.emailInputEl.reportValidity();
            return;
        }

        this.visitorName = name;
        localStorage.setItem(STORAGE_NAME_KEY, name);

        this.startBtnEl.disabled = true;
        this.startBtnEl.textContent = 'Connecting...';

        api('/api/widget/conversations', {
            method: 'POST',
            body: {
                property_id: PROPERTY_ID,
                visitor_id: this.visitorId,
                visitor_name: name || null,
                visitor_email: email || null,
                topic: topic || null,
            },
        })
            .then(function (data) {
                self.conversationUuid = data.uuid;
                sessionStorage.setItem(STORAGE_CONVERSATION_KEY, data.uuid);
                self.enterChatView();
            })
            .catch(function (err) {
                self.startBtnEl.disabled = false;
                self.startBtnEl.textContent = 'Start Chat';
                alert('Could not start chat: ' + err.message);
            });
    };

    Widget.prototype.enterChatView = function () {
        this.prechatEl.style.display = 'none';
        this.chatViewEl.style.display = 'flex';
        this.endBtnEl.style.display = 'inline-block';
        this.loadHistory();
        this.connectRealtime();
        this.requestNotificationPermission();
    };

    Widget.prototype.loadHistory = function () {
        var self = this;
        api('/api/widget/conversations/' + this.conversationUuid + '/messages')
            .then(function (messages) {
                messages.forEach(function (m) {
                    self.renderMessage(m);
                });
                self.scrollToBottom();
            })
            .catch(function () {});
    };

    /**
     * One shared Pusher client for both the announcement channel (opened on
     * page load) and the conversation channel (opened once a chat starts).
     */
    Widget.prototype.ensurePusher = function () {
        var self = this;
        if (this.pusherReady) {
            return this.pusherReady;
        }
        this.pusherReady = api('/api/widget/config')
            .then(function (config) {
                self.config = config;
                return loadScript(CDN.pusher);
            })
            .then(function () {
                self.pusher = new window.Pusher(self.config.reverb.key, {
                    cluster: '',
                    wsHost: self.config.reverb.host,
                    wsPort: self.config.reverb.port,
                    wssPort: self.config.reverb.port,
                    forceTLS: self.config.reverb.scheme === 'https',
                    enabledTransports: ['ws', 'wss'],
                    disableStats: true,
                });
                return self.pusher;
            });
        return this.pusherReady;
    };

    Widget.prototype.connectRealtime = function () {
        var self = this;
        this.ensurePusher()
            .then(function (pusher) {
                self.channel = pusher.subscribe('conversation.' + self.conversationUuid);
                self.channel.bind('message.sent', function (payload) {
                    if (payload.sender_type !== 'visitor') {
                        self.renderMessage(payload);
                        self.scrollToBottom();
                        if (!self.panelEl.classList.contains('lc-open')) {
                            self.unreadCount++;
                            self.updateBadge();
                        }
                        self.notifyNewAgentMessage(payload);
                    }
                });
                self.channel.bind('call.signal', function (payload) {
                    self.handleCallSignal(payload);
                });
                self.channel.bind('conversation.closed', function (payload) {
                    if (payload.closed_by !== 'visitor') {
                        self.handleConversationClosed(payload);
                    }
                });
            })
            .catch(function (err) {
                console.error('[live-support] realtime connection failed', err);
            });
    };

    // -- Announcements ------------------------------------------------------

    /**
     * Tells the backend this site has the widget installed, so agents can pick
     * it as an announcement target before anyone here has ever chatted. Once
     * per browser session is plenty — this is a roster, not analytics.
     */
    Widget.prototype.reportSite = function () {
        if (sessionStorage.getItem(STORAGE_HEARTBEAT_KEY)) {
            return;
        }
        api('/api/widget/sites/heartbeat', {
            method: 'POST',
            body: { property_id: PROPERTY_ID },
        })
            .then(function () {
                sessionStorage.setItem(STORAGE_HEARTBEAT_KEY, '1');
            })
            .catch(function () {});
    };

    /** Catches an announcement broadcast before this page was opened. */
    Widget.prototype.checkAnnouncement = function () {
        var self = this;
        api('/api/widget/announcements?property_id=' + encodeURIComponent(PROPERTY_ID))
            .then(function (announcement) {
                if (announcement) {
                    self.showAnnouncement(announcement);
                }
            })
            .catch(function () {});
    };

    Widget.prototype.connectAnnouncements = function () {
        var self = this;
        this.ensurePusher()
            .then(function (pusher) {
                var channel = pusher.subscribe('announcements');
                channel.bind('announcement.created', function (announcement) {
                    if (self.announcementTargetsThisWidget(announcement)) {
                        self.showAnnouncement(announcement);
                    }
                });
                channel.bind('announcement.cleared', function (payload) {
                    if (self.currentAnnouncementId === payload.id) {
                        self.hideAnnouncement();
                    }
                });
            })
            .catch(function (err) {
                console.error('[live-support] announcement channel failed', err);
            });
    };

    Widget.prototype.announcementTargetsThisWidget = function (announcement) {
        var targets = announcement.property_ids;
        if (!targets || !targets.length) {
            return true;
        }
        return targets.indexOf(PROPERTY_ID) !== -1;
    };

    Widget.prototype.showAnnouncement = function (announcement) {
        if (String(localStorage.getItem(STORAGE_DISMISSED_ANNOUNCEMENT_KEY)) === String(announcement.id)) {
            return;
        }
        var level = announcement.level || 'warning';
        this.currentAnnouncementId = announcement.id;
        this.announceEl.className = 'lc-announce lc-show lc-level-' + level;
        this.announceIcoEl.textContent = level === 'critical' ? '🚨' : level === 'info' ? 'ℹ️' : '⚠️';
        this.announceTitleEl.textContent = announcement.title || 'Service notice';
        this.announceBodyEl.textContent = announcement.message;
    };

    Widget.prototype.hideAnnouncement = function () {
        this.announceEl.classList.remove('lc-show');
        this.currentAnnouncementId = null;
    };

    Widget.prototype.dismissAnnouncement = function () {
        if (this.currentAnnouncementId !== null) {
            localStorage.setItem(STORAGE_DISMISSED_ANNOUNCEMENT_KEY, String(this.currentAnnouncementId));
        }
        this.hideAnnouncement();
    };

    Widget.prototype.renderMessage = function (m) {
        var row = document.createElement('div');
        row.className = 'lc-msg ' + m.sender_type;
        var content =
            m.type === 'image' && m.attachment_url
                ? '<a href="' + escapeHtml(m.attachment_url) + '" target="_blank" rel="noopener"><img class="lc-image" src="' + escapeHtml(m.attachment_url) + '" /></a>'
                : '';
        if (m.body) {
            content += '<div class="lc-bubble">' + escapeHtml(m.body) + '</div>';
        }
        row.innerHTML =
            content +
            '<div class="lc-meta">' + (m.sender_type === 'agent' ? escapeHtml(m.sender_name || 'Agent') + ' · ' : '') + formatTime(m.created_at) + '</div>';
        this.messagesEl.appendChild(row);
    };

    Widget.prototype.renderSystemMessage = function (text) {
        var row = document.createElement('div');
        row.className = 'lc-msg system';
        row.innerHTML = '<div class="lc-bubble">' + escapeHtml(text) + '</div>';
        this.messagesEl.appendChild(row);
        this.scrollToBottom();
    };

    Widget.prototype.scrollToBottom = function () {
        this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
    };

    Widget.prototype.sendMessage = function () {
        var self = this;
        var body = this.msgInputEl.value.trim();
        if (!body || !this.conversationUuid) {
            return;
        }
        this.msgInputEl.value = '';
        this.renderMessage({ sender_type: 'visitor', body: body, created_at: new Date().toISOString() });
        this.scrollToBottom();

        api('/api/widget/conversations/' + this.conversationUuid + '/messages', {
            method: 'POST',
            body: { body: body, visitor_name: this.visitorName || null },
        }).catch(function (err) {
            self.renderSystemMessage('Failed to send: ' + err.message);
        });
    };

    Widget.prototype.sendImage = function (file) {
        var self = this;
        if (!this.conversationUuid) {
            return;
        }
        var formData = new FormData();
        formData.append('image', file);
        if (this.visitorName) {
            formData.append('visitor_name', this.visitorName);
        }

        apiUpload('/api/widget/conversations/' + this.conversationUuid + '/messages', formData)
            .then(function (message) {
                self.renderMessage(message);
                self.scrollToBottom();
            })
            .catch(function (err) {
                self.renderSystemMessage('Failed to send image: ' + err.message);
            });
    };

    Widget.prototype.endSession = function () {
        var self = this;
        if (!this.conversationUuid) {
            return;
        }
        api('/api/widget/conversations/' + this.conversationUuid + '/close', { method: 'POST' })
            .then(function () {
                self.handleConversationClosed({ closed_by: 'visitor' });
            })
            .catch(function (err) {
                self.renderSystemMessage('Could not end chat: ' + err.message);
            });
    };

    Widget.prototype.handleConversationClosed = function (payload) {
        if (this.sessionEnded) {
            return;
        }
        this.sessionEnded = true;
        this.renderSystemMessage(payload.closed_by === 'agent' ? 'The agent ended this chat.' : 'Chat ended.');
        this.scrollToBottom();
        this.disableChat();
    };

    Widget.prototype.disableChat = function () {
        this.msgInputEl.disabled = true;
        this.attachBtnEl.disabled = true;
        this.sendBtnEl.disabled = true;
        this.endBtnEl.style.display = 'none';
        this.callBarEl.style.display = 'none';
        this.inputBarEl.style.display = 'none';
        this.restartBarEl.style.display = 'block';
        Array.prototype.forEach.call(this.panelEl.querySelectorAll('.lc-call-btn'), function (btn) {
            btn.disabled = true;
        });
        this.endCall(false);
    };

    Widget.prototype.startNewSession = function () {
        if (this.pusher && this.conversationUuid) {
            this.pusher.unsubscribe('conversation.' + this.conversationUuid);
        }
        this.channel = null;
        sessionStorage.removeItem(STORAGE_CONVERSATION_KEY);
        this.conversationUuid = null;
        this.sessionEnded = false;
        this.unreadCount = 0;
        this.updateBadge();

        this.messagesEl.innerHTML = '';
        this.msgInputEl.disabled = false;
        this.msgInputEl.value = '';
        this.attachBtnEl.disabled = false;
        this.sendBtnEl.disabled = false;
        Array.prototype.forEach.call(this.panelEl.querySelectorAll('.lc-call-btn'), function (btn) {
            btn.disabled = false;
        });
        this.callBarEl.style.display = 'flex';
        this.inputBarEl.style.display = 'flex';
        this.restartBarEl.style.display = 'none';

        this.startBtnEl.disabled = false;
        this.startBtnEl.textContent = 'Start Chat';

        this.chatViewEl.style.display = 'none';
        this.prechatEl.style.display = 'flex';
    };

    // -- Calling ------------------------------------------------------------

    Widget.prototype.ensurePeer = function () {
        var self = this;
        if (this.peer && !this.peer.destroyed) {
            return Promise.resolve(this.peer);
        }
        return loadScript(CDN.peerjs).then(function () {
            return new Promise(function (resolve, reject) {
                var peer = new window.Peer(undefined, { debug: 1 });
                peer.on('open', function () {
                    self.peer = peer;
                    self.peer.on('call', function (call) {
                        call.answer(self.localStream || undefined);
                        self.bindCallEvents(call);
                    });
                    resolve(peer);
                });
                peer.on('error', function (err) {
                    console.error('[live-support] peer error', err);
                    reject(err);
                });
            });
        });
    };

    Widget.prototype.getLocalStream = function (mode) {
        if (mode === 'screen') {
            return navigator.mediaDevices.getDisplayMedia({ video: true }).then(function (screenStream) {
                return navigator.mediaDevices
                    .getUserMedia({ audio: true })
                    .then(function (micStream) {
                        return new MediaStream(screenStream.getVideoTracks().concat(micStream.getAudioTracks()));
                    })
                    .catch(function () {
                        return screenStream;
                    });
            });
        }
        return navigator.mediaDevices.getUserMedia({
            video: mode === 'video',
            audio: true,
        });
    };

    Widget.prototype.startCall = function (mode) {
        var self = this;
        if (!this.conversationUuid) {
            return;
        }
        this.callMode = mode;
        this.showCallPanel('Calling agent...');

        this.getLocalStream(mode)
            .then(function (stream) {
                self.localStream = stream;
                if (stream && mode !== 'screen') {
                    self.localVideoEl.srcObject = stream;
                    self.localVideoEl.style.display = 'block';
                } else {
                    self.localVideoEl.style.display = 'none';
                }
                if (mode === 'screen') {
                    self.remoteVideoEl.muted = true;
                    self.remoteVideoEl.srcObject = stream;
                    stream.getVideoTracks()[0].onended = function () {
                        self.endCall(true);
                    };
                } else {
                    self.remoteVideoEl.muted = false;
                }
                return self.ensurePeer();
            })
            .then(function (peer) {
                return api('/api/widget/conversations/' + self.conversationUuid + '/call', {
                    method: 'POST',
                    body: { type: 'invite', mode: mode, peer_id: peer.id, visitor_name: self.visitorName || null },
                });
            })
            .catch(function (err) {
                self.renderSystemMessage('Call failed: ' + err.message);
                self.endCall(false);
            });
    };

    Widget.prototype.handleCallSignal = function (payload) {
        if (payload.from !== 'agent') {
            return;
        }
        if (payload.type === 'invite') {
            this.pendingInvite = payload;
            this.incomingTextEl.textContent = (payload.agent_name || 'Agent') + ' is calling you (' + payload.mode + ')';
            this.incomingEl.classList.add('lc-show');
            this.setPanelOpen(true);
        } else if (payload.type === 'accept') {
            this.remotePeerId = payload.peer_id;
            this.callStatusEl.textContent = 'Connected';
            if (this.callMode === 'screen' && this.peer) {
                var call = this.peer.call(payload.peer_id, this.localStream || undefined);
                this.bindCallEvents(call);
            }
        } else if (payload.type === 'reject') {
            this.renderSystemMessage('Call declined.');
            this.endCall(false);
        } else if (payload.type === 'end') {
            this.renderSystemMessage('Call ended.');
            this.endCall(false);
        }
    };

    Widget.prototype.acceptIncomingCall = function () {
        var self = this;
        var invite = this.pendingInvite;
        if (!invite) {
            return;
        }
        this.incomingEl.classList.remove('lc-show');
        this.callMode = invite.mode;
        this.showCallPanel('Connecting...');

        var streamPromise = invite.mode === 'screen' ? Promise.resolve(null) : this.getLocalStream(invite.mode);

        streamPromise
            .then(function (stream) {
                self.localStream = stream;
                if (stream) {
                    self.localVideoEl.srcObject = stream;
                    self.localVideoEl.style.display = 'block';
                }
                return self.ensurePeer();
            })
            .then(function (peer) {
                var call = peer.call(invite.peer_id, self.localStream || undefined);
                self.bindCallEvents(call);
                return api('/api/widget/conversations/' + self.conversationUuid + '/call', {
                    method: 'POST',
                    body: { type: 'accept', peer_id: peer.id },
                });
            })
            .catch(function (err) {
                self.renderSystemMessage('Could not join call: ' + err.message);
                self.endCall(true);
            });
    };

    Widget.prototype.rejectIncomingCall = function () {
        this.incomingEl.classList.remove('lc-show');
        if (this.conversationUuid) {
            api('/api/widget/conversations/' + this.conversationUuid + '/call', {
                method: 'POST',
                body: { type: 'reject' },
            }).catch(function () {});
        }
        this.pendingInvite = null;
    };

    Widget.prototype.bindCallEvents = function (call) {
        var self = this;
        this.activeCall = call;
        call.on('stream', function (remoteStream) {
            self.remoteVideoEl.srcObject = remoteStream;
            self.callStatusEl.textContent = 'Connected';
        });
        call.on('close', function () {
            self.endCall(false);
        });
        call.on('error', function (err) {
            console.error('[live-support] call error', err);
        });
    };

    Widget.prototype.showCallPanel = function (statusText) {
        this.callPanelEl.classList.add('lc-active');
        this.callStatusEl.textContent = statusText;
    };

    Widget.prototype.toggleTrack = function (kind, btnEl) {
        if (!this.localStream) {
            return;
        }
        var tracks = kind === 'audio' ? this.localStream.getAudioTracks() : this.localStream.getVideoTracks();
        tracks.forEach(function (t) {
            t.enabled = !t.enabled;
            btnEl.classList.toggle('lc-off', !t.enabled);
        });
    };

    Widget.prototype.endCall = function (notifyRemote) {
        if (notifyRemote && this.conversationUuid) {
            api('/api/widget/conversations/' + this.conversationUuid + '/call', {
                method: 'POST',
                body: { type: 'end' },
            }).catch(function () {});
        }
        if (this.activeCall) {
            try {
                this.activeCall.close();
            } catch (e) {}
            this.activeCall = null;
        }
        if (this.localStream) {
            this.localStream.getTracks().forEach(function (t) {
                t.stop();
            });
            this.localStream = null;
        }
        this.remoteVideoEl.srcObject = null;
        this.remoteVideoEl.muted = false;
        this.localVideoEl.srcObject = null;
        this.callPanelEl.classList.remove('lc-active');
        this.incomingEl.classList.remove('lc-show');
        this.pendingInvite = null;
        this.callMode = null;
    };

    // ---------------------------------------------------------------------

    function boot() {
        api('/api/widget/settings?property_id=' + encodeURIComponent(PROPERTY_ID))
            .catch(function () {
                return {};
            })
            .then(function (settings) {
                new Widget(settings || {});
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
