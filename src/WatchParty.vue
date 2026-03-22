<template>
    <div class="wp-wrap" ref="wrapRef">
        <!-- Toggle button — shows badge if there are approved proposals -->
        <button
            class="wp-btn"
            :class="{ active: open }"
            @click="toggle"
            title="Watch Party"
        >
            🍿
            <span v-if="approvedCount > 0 || pendingCount > 0" class="wp-badge"
                :class="{ 'wp-badge--pending': pendingCount > 0 && canModerate }">
                {{ canModerate && pendingCount > 0 ? pendingCount : approvedCount || pendingCount }}
            </span>
        </button>

        <!-- Panel -->
        <Teleport to="body">
        <div v-if="open" class="wp-panel" :style="panelStyle" @click.stop>
            <div class="wp-panel-header">
                <span class="wp-title">🍿 Watch Party</span>
                <button class="wp-close" @click="open = false">✕</button>
            </div>

            <!-- ── NOW PLAYING ─────────────────────────────────────────── -->
            <div v-if="session && session.state !== 'idle'" class="wp-session">
                <div class="wp-session-header">
                    <span class="wp-session-state" :class="'wp-state--' + session.state">
                        {{ stateLabel }}
                    </span>
                    <span class="wp-session-title" :title="session.title || session.url">
                        {{ session.title || shortenUrl(session.url) }}
                    </span>
                </div>

                <!-- Countdown during synchronising -->
                <div v-if="session.state === 'synchronising'" class="wp-countdown">
                    <div class="wp-countdown-num">{{ countdown }}</div>
                    <div class="wp-countdown-label">Starting in…</div>
                </div>

                <!-- Video player -->
                <div v-else-if="session.state === 'playback' || session.state === 'paused'" class="wp-player-wrap">
                    <!-- YouTube embed -->
                    <iframe
                        v-if="ytId"
                        :src="`https://www.youtube.com/embed/${ytId}?enablejsapi=1&autoplay=0&controls=1`"
                        ref="ytFrame"
                        class="wp-player wp-player--yt"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                    />
                    <!-- Native video -->
                    <video
                        v-else-if="isDirectVideo"
                        ref="videoEl"
                        class="wp-player"
                        :src="session.url"
                        controls
                        preload="auto"
                    />
                    <!-- Unsupported — just link -->
                    <div v-else class="wp-player-link">
                        <a :href="session.url" target="_blank" rel="noopener">
                            ↗ Open link
                        </a>
                    </div>
                </div>

                <!-- Playback controls (controller only) -->
                <div v-if="canControl" class="wp-controls">
                    <template v-if="session.state === 'playback'">
                        <button class="wp-ctrl-btn" @click="sessionAction('pause')" title="Pause">⏸</button>
                        <button class="wp-ctrl-btn wp-ctrl-btn--stop" @click="sessionAction('stop')" title="Stop">⏹</button>
                    </template>
                    <template v-else-if="session.state === 'paused'">
                        <button class="wp-ctrl-btn wp-ctrl-btn--play" @click="sessionAction('resume')" title="Resume">▶</button>
                        <button class="wp-ctrl-btn wp-ctrl-btn--stop" @click="sessionAction('stop')" title="Stop">⏹</button>
                    </template>
                    <template v-else-if="session.state === 'synchronising'">
                        <button class="wp-ctrl-btn wp-ctrl-btn--stop" @click="sessionAction('stop')" title="Cancel">✕</button>
                    </template>
                </div>
            </div>

            <!-- ── PENDING APPROVAL (moderators only) ──────────────────── -->
            <div v-if="canModerate && pendingProposals.length > 0" class="wp-section">
                <div class="wp-section-title">⏳ Pending Approval ({{ pendingProposals.length }})</div>
                <div v-for="p in pendingProposals" :key="p.id" class="wp-proposal wp-proposal--pending">
                    <div class="wp-proposal-meta">
                        <a :href="p.url" target="_blank" rel="noopener" class="wp-proposal-title">
                            {{ p.title || shortenUrl(p.url) }}
                        </a>
                        <span class="wp-proposal-by">by {{ p.proposed_by }}</span>
                    </div>
                    <div class="wp-proposal-actions">
                        <button class="wp-approve-btn" @click="approve(p)" title="Approve">✓</button>
                        <button class="wp-del-btn" @click="reject(p)" title="Reject">✕</button>
                    </div>
                </div>
            </div>

            <!-- ── APPROVED PROPOSALS ──────────────────────────────────── -->
            <div v-if="approvedProposals.length > 0 || (!loading && approvedProposals.length === 0 && pendingProposals.length === 0)" class="wp-section wp-section--proposals">
                <div v-if="approvedProposals.length > 0" class="wp-section-title">
                    Proposals
                    <span class="wp-section-hint" v-if="!canModerate && pendingProposals.length > 0">
                        ({{ pendingProposals.length }} awaiting approval)
                    </span>
                </div>

                <div v-if="loading && approvedProposals.length === 0" class="wp-empty">Loading…</div>
                <div v-else-if="!loading && approvedProposals.length === 0 && pendingProposals.length === 0" class="wp-empty">
                    No proposals yet. Be the first!
                </div>

                <div class="wp-proposals">
                    <div
                        v-for="(p, i) in approvedProposals"
                        :key="p.id"
                        class="wp-proposal"
                        :class="{ 'wp-proposal--top': i === 0 }"
                    >
                        <div class="wp-proposal-meta">
                            <a :href="p.url" target="_blank" rel="noopener" class="wp-proposal-title">
                                {{ p.title || shortenUrl(p.url) }}
                            </a>
                            <span class="wp-proposal-by">by {{ p.proposed_by }}</span>
                        </div>
                        <div class="wp-proposal-actions">
                            <!-- Start watch party (controller only, top proposal) -->
                            <button
                                v-if="canControl && i === 0 && (!session || session.state === 'idle')"
                                class="wp-start-btn"
                                @click="startSession(p)"
                                title="Start watch party with this"
                            >▶</button>
                            <button
                                class="wp-vote-btn"
                                :class="{ voted: p.voted }"
                                @click="vote(p)"
                                :disabled="voting === p.id"
                            >
                                <span>👍</span>
                                <span class="wp-vote-count">{{ p.votes }}</span>
                            </button>
                            <a :href="p.url" target="_blank" rel="noopener" class="wp-open-btn" title="Open link">↗</a>
                            <button
                                v-if="p.is_mine || isAdmin"
                                class="wp-del-btn"
                                @click="remove(p)"
                                title="Remove"
                            >✕</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ADD PROPOSAL ────────────────────────────────────────── -->
            <form v-if="canPropose" class="wp-form" @submit.prevent="propose">
                <input
                    v-model="newUrl"
                    class="wp-input"
                    type="url"
                    placeholder="Paste a link to propose…"
                    required
                />
                <input
                    v-model="newTitle"
                    class="wp-input"
                    type="text"
                    placeholder="Title (optional)"
                    maxlength="128"
                />
                <div class="wp-form-footer">
                    <button class="wp-submit-btn" type="submit" :disabled="proposing">
                        {{ proposing ? 'Adding…' : 'Propose' }}
                    </button>
                    <button
                        v-if="isAdmin && approvedProposals.length > 0"
                        type="button"
                        class="wp-clear-btn"
                        @click="clearAll"
                    >Clear all</button>
                </div>
                <div v-if="formError" class="wp-form-error">{{ formError }}</div>
                <div v-if="formInfo" class="wp-form-info">{{ formInfo }}</div>
            </form>
        </div>
        </Teleport>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'

const props = defineProps({
    settings:      { type: Object, default: () => ({}) },
    authToken:     { type: String, default: '' },
    apiBase:       { type: String, default: '' },
    channelId:     { type: String, default: '' },
    currentMember: { type: Object, default: null },
})

const open     = ref(false)
const loading  = ref(false)
const voting   = ref(null)
const proposing = ref(false)
const newUrl   = ref('')
const newTitle = ref('')
const formError = ref('')
const formInfo  = ref('')
const wrapRef   = ref(null)
const panelStyle = ref({})
const ytFrame   = ref(null)
const videoEl   = ref(null)

// All proposals returned from server
const allProposals = ref([])
// Current watch session
const session      = ref(null)
// Countdown value during synchronising state
const countdown    = ref(5)

let pollTimer      = null
let countdownTimer = null
let echoChannel    = null

// ── Permissions ────────────────────────────────────────────────────────────

const isAdmin    = computed(() => props.currentMember?.isAdmin ?? false)
const canPropose = computed(() => {
    if (!props.currentMember) return false
    return props.currentMember.isSuperAdmin || props.currentMember.isAdmin ||
           props.currentMember.can?.('watch_party.propose') ?? true
})
const canModerate = computed(() => {
    if (!props.currentMember) return false
    return props.currentMember.isSuperAdmin || props.currentMember.isAdmin ||
           props.currentMember.can?.('watch_party.moderate')
})
const canControl = computed(() => {
    if (!props.currentMember) return false
    return props.currentMember.isSuperAdmin || props.currentMember.isAdmin ||
           props.currentMember.can?.('watch_party.control')
})

// ── Derived lists ──────────────────────────────────────────────────────────

const pendingProposals  = computed(() =>
    allProposals.value.filter(p => !p.is_approved).sort((a, b) => b.votes - a.votes)
)
const approvedProposals = computed(() =>
    allProposals.value.filter(p => p.is_approved).sort((a, b) => b.votes - a.votes)
)
const approvedCount = computed(() => approvedProposals.value.length)
const pendingCount  = computed(() => pendingProposals.value.length)

// ── Session helpers ────────────────────────────────────────────────────────

const stateLabel = computed(() => {
    switch (session.value?.state) {
        case 'synchronising': return '🔄 Synchronising'
        case 'playback':      return '▶ Playback'
        case 'paused':        return '⏸ Paused'
        default:              return ''
    }
})

// Extract YouTube video ID from various URL formats
const ytId = computed(() => {
    const url = session.value?.url
    if (!url) return null
    try {
        const u = new URL(url)
        if (u.hostname.includes('youtube.com')) return u.searchParams.get('v')
        if (u.hostname === 'youtu.be') return u.pathname.slice(1)
    } catch {}
    return null
})

const isDirectVideo = computed(() => {
    const url = session.value?.url
    if (!url) return false
    return /\.(mp4|webm|ogg|mov)(\?|$)/i.test(url)
})

// ── API helpers ────────────────────────────────────────────────────────────

function base() { return props.apiBase.replace(/\/$/, '') }

function headers() {
    return {
        Authorization:  'Bearer ' + props.authToken,
        'Content-Type': 'application/json',
        Accept:         'application/json',
    }
}

function shortenUrl(url) {
    try {
        const u = new URL(url)
        return u.hostname + (u.pathname !== '/' ? u.pathname.slice(0, 40) : '')
    } catch {
        return (url || '').slice(0, 50)
    }
}

// ── Data fetching ──────────────────────────────────────────────────────────

async function fetchProposals() {
    if (!props.channelId) return
    loading.value = allProposals.value.length === 0
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals?channel_id=${props.channelId}`, {
            headers: { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json' },
        })
        if (res.ok) {
            const data = await res.json()
            allProposals.value = data.proposals ?? []
        }
    } finally {
        loading.value = false
    }
}

async function fetchSession() {
    if (!props.channelId) return
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/session/${props.channelId}`, {
            headers: { Authorization: 'Bearer ' + props.authToken, Accept: 'application/json' },
        })
        if (res.ok) {
            const data = await res.json()
            applySession(data.session)
        }
    } catch {}
}

// ── Session state machine ──────────────────────────────────────────────────

function applySession(s) {
    session.value = s
    clearTimeout(countdownTimer)

    if (!s || s.state === 'idle') {
        session.value = null
        return
    }

    if (s.state === 'synchronising' && s.sync_at) {
        // Start countdown
        const syncAt   = new Date(s.sync_at).getTime()
        const tickDown = () => {
            const remaining = Math.max(0, Math.ceil((syncAt - Date.now()) / 1000))
            countdown.value = remaining
            if (remaining > 0) {
                countdownTimer = setTimeout(tickDown, 200)
            } else {
                // Sync time reached — controller tells server to transition
                if (canControl.value) {
                    notifyReady()
                } else {
                    // Non-controllers wait for the broadcast which will arrive via WSS
                }
            }
        }
        tickDown()
    }

    if (s.state === 'playback') {
        syncPlayer(s)
    } else if (s.state === 'paused') {
        pausePlayer(s.timecode)
    }
}

function syncPlayer(s) {
    // Calculate effective timecode
    const tcAt    = s.timecode_at ? new Date(s.timecode_at).getTime() : Date.now()
    const elapsed = (Date.now() - tcAt) / 1000
    const target  = Math.max(0, s.timecode + elapsed)

    // Apply to player
    if (ytId.value && ytFrame.value) {
        ytFrame.value.contentWindow?.postMessage(
            JSON.stringify({ event: 'command', func: 'seekTo', args: [target, true] }), '*'
        )
        ytFrame.value.contentWindow?.postMessage(
            JSON.stringify({ event: 'command', func: 'playVideo', args: [] }), '*'
        )
    } else if (videoEl.value) {
        videoEl.value.currentTime = target
        videoEl.value.play().catch(() => {})
    }
}

function pausePlayer(timecode) {
    if (ytId.value && ytFrame.value) {
        ytFrame.value.contentWindow?.postMessage(
            JSON.stringify({ event: 'command', func: 'seekTo', args: [timecode, true] }), '*'
        )
        ytFrame.value.contentWindow?.postMessage(
            JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }), '*'
        )
    } else if (videoEl.value) {
        videoEl.value.currentTime = timecode
        videoEl.value.pause()
    }
}

async function notifyReady() {
    try {
        await fetch(`${base()}/api/plugins/watch-party/session/ready`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ channel_id: props.channelId }),
        })
    } catch {}
}

// ── WebSocket subscription ─────────────────────────────────────────────────

function subscribeToSession() {
    if (!window._echo || !props.channelId) return
    try {
        echoChannel = window._echo.channel('watch-party.' + props.channelId)
        echoChannel.listen('.session.updated', (data) => {
            applySession(data.state === 'idle' ? null : data)
        })
    } catch {}
}

function unsubscribeFromSession() {
    if (echoChannel) {
        try { window._echo?.leaveChannel?.('watch-party.' + props.channelId) } catch {}
        echoChannel = null
    }
}

// ── Proposal actions ───────────────────────────────────────────────────────

async function vote(p) {
    voting.value = p.id
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}/vote`, {
            method: 'POST',
            headers: headers(),
        })
        if (res.ok) {
            const data = await res.json()
            p.voted = data.voted
            p.votes = data.votes
        }
    } finally {
        voting.value = null
    }
}

async function propose() {
    if (!newUrl.value.trim()) return
    formError.value = ''
    formInfo.value  = ''
    proposing.value = true
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/proposals`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({
                channel_id: props.channelId,
                url:        newUrl.value.trim(),
                title:      newTitle.value.trim() || null,
            }),
        })
        if (!res.ok) {
            const data = await res.json().catch(() => ({}))
            formError.value = data.message || `Error ${res.status}.`
            return
        }
        const data = await res.json()
        newUrl.value   = ''
        newTitle.value = ''

        if (data.deduplicated) {
            formInfo.value = 'That link was already proposed — your vote has been added.'
        } else if (!canModerate.value) {
            formInfo.value = 'Your proposal is pending moderator approval.'
        }

        await fetchProposals()
    } catch {
        formError.value = 'Network error.'
    } finally {
        proposing.value = false
    }
}

async function approve(p) {
    await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}/approve`, {
        method: 'POST', headers: headers(),
    })
    p.is_approved = true
    // Move it to approved list in UI immediately
    const idx = allProposals.value.findIndex(x => x.id === p.id)
    if (idx !== -1) allProposals.value[idx] = { ...allProposals.value[idx], is_approved: true }
}

async function reject(p) {
    await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}/approve`, {
        method: 'DELETE', headers: headers(),
    })
    allProposals.value = allProposals.value.filter(x => x.id !== p.id)
}

async function remove(p) {
    await fetch(`${base()}/api/plugins/watch-party/proposals/${p.id}`, {
        method: 'DELETE', headers: headers(),
    })
    allProposals.value = allProposals.value.filter(x => x.id !== p.id)
}

async function clearAll() {
    if (!confirm('Clear all watch party proposals?')) return
    await fetch(`${base()}/api/plugins/watch-party/proposals?channel_id=${props.channelId}`, {
        method: 'DELETE', headers: headers(),
    })
    allProposals.value = []
}

// ── Session control ────────────────────────────────────────────────────────

async function startSession(p) {
    await sessionAction('start', { proposal_id: p.id })
}

async function sessionAction(action, extra = {}) {
    try {
        const res = await fetch(`${base()}/api/plugins/watch-party/session`, {
            method: 'POST',
            headers: headers(),
            body: JSON.stringify({ channel_id: props.channelId, action, ...extra }),
        })
        if (res.ok) {
            const data = await res.json()
            applySession(data.session)
        }
    } catch {}
}

// ── Panel lifecycle ────────────────────────────────────────────────────────

function positionPanel() {
    const rect = wrapRef.value?.getBoundingClientRect()
    if (!rect) return
    panelStyle.value = {
        left:   Math.max(8, Math.min(rect.left, window.innerWidth - 360)) + 'px',
        bottom: (window.innerHeight - rect.top + 8) + 'px',
    }
}

function toggle() {
    open.value = !open.value
    if (open.value) {
        positionPanel()
        fetchProposals()
        fetchSession()
        pollTimer = setInterval(() => { fetchProposals(); fetchSession() }, 8000)
    } else {
        clearInterval(pollTimer)
    }
}

function onClickOutside(e) {
    if (open.value && !wrapRef.value?.contains(e.target) && !document.querySelector('.wp-panel')?.contains(e.target)) {
        open.value = false
        clearInterval(pollTimer)
    }
}

onMounted(() => {
    document.addEventListener('click', onClickOutside)
    subscribeToSession()
    // Also fetch session on mount for badge state
    fetchProposals()
    fetchSession()
})

onUnmounted(() => {
    document.removeEventListener('click', onClickOutside)
    clearInterval(pollTimer)
    clearTimeout(countdownTimer)
    unsubscribeFromSession()
})
</script>

<style scoped>
.wp-wrap { position: relative; display: inline-flex; align-items: center; }

.wp-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 17px;
    line-height: 1;
    color: rgba(255,255,255,.7);
    transition: background .15s;
    position: relative;
    display: flex;
    align-items: center;
    gap: 3px;
}
.wp-btn:hover, .wp-btn.active { background: rgba(251,191,36,.12); }

.wp-badge {
    font-size: 10px;
    font-weight: 700;
    background: #f59e0b;
    color: #000;
    border-radius: 8px;
    padding: 1px 5px;
    line-height: 1.4;
}
.wp-badge--pending { background: #ef4444; color: #fff; }

.wp-panel {
    position: fixed;
    width: 340px;
    max-height: 540px;
    background: #1a1d26;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 12px;
    box-shadow: 0 16px 48px rgba(0,0,0,.6);
    display: flex;
    flex-direction: column;
    z-index: 9999;
    overflow: hidden;
}

.wp-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px 10px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.wp-title { font-size: 14px; font-weight: 700; color: rgba(255,255,255,.9); }
.wp-close { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.4); font-size: 14px; padding: 2px 6px; border-radius: 4px; }
.wp-close:hover { color: rgba(255,255,255,.8); background: rgba(255,255,255,.06); }

/* ── Session / Now Playing ── */
.wp-session {
    border-bottom: 1px solid rgba(255,255,255,.06);
    padding: 10px 14px;
    flex-shrink: 0;
}
.wp-session-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.wp-session-state {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    flex-shrink: 0;
}
.wp-state--synchronising { background: rgba(251,191,36,.2); color: #fcd34d; }
.wp-state--playback      { background: rgba(74,222,128,.2); color: #4ade80; }
.wp-state--paused        { background: rgba(156,163,175,.2); color: #9ca3af; }

.wp-session-title {
    font-size: 12px;
    color: rgba(255,255,255,.6);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.wp-countdown {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 0;
}
.wp-countdown-num {
    font-size: 36px;
    font-weight: 800;
    color: #fcd34d;
    line-height: 1;
}
.wp-countdown-label {
    font-size: 12px;
    color: rgba(255,255,255,.4);
    margin-top: 4px;
}

.wp-player-wrap {
    border-radius: 8px;
    overflow: hidden;
    background: #000;
    margin-bottom: 6px;
}
.wp-player {
    width: 100%;
    height: 175px;
    display: block;
    border: none;
}
.wp-player-link {
    padding: 20px;
    text-align: center;
    font-size: 13px;
}
.wp-player-link a { color: #67e8f9; text-decoration: none; }
.wp-player-link a:hover { text-decoration: underline; }

.wp-controls {
    display: flex;
    gap: 6px;
    justify-content: flex-end;
}
.wp-ctrl-btn {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 13px;
    cursor: pointer;
    color: rgba(255,255,255,.6);
    transition: all .15s;
}
.wp-ctrl-btn:hover { background: rgba(255,255,255,.12); color: #fff; }
.wp-ctrl-btn--play { color: #4ade80; border-color: rgba(74,222,128,.3); }
.wp-ctrl-btn--play:hover { background: rgba(74,222,128,.1); }
.wp-ctrl-btn--stop { color: #f87171; border-color: rgba(248,113,113,.3); }
.wp-ctrl-btn--stop:hover { background: rgba(248,113,113,.1); }

/* ── Sections ── */
.wp-section {
    border-bottom: 1px solid rgba(255,255,255,.06);
    padding: 8px 0 4px;
    flex-shrink: 0;
}
.wp-section--proposals {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.wp-section-title {
    font-size: 11px;
    font-weight: 700;
    color: rgba(255,255,255,.4);
    text-transform: uppercase;
    letter-spacing: .06em;
    padding: 0 14px 6px;
}
.wp-section-hint {
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
}

.wp-empty, .wp-loading {
    padding: 16px 14px;
    font-size: 13px;
    color: rgba(255,255,255,.3);
}

.wp-proposals { padding: 0; }

.wp-proposal {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px;
    transition: background .1s;
}
.wp-proposal:hover { background: rgba(255,255,255,.03); }
.wp-proposal--top { background: rgba(251,191,36,.05); }
.wp-proposal--pending { background: rgba(239,68,68,.04); }
.wp-proposal--pending:hover { background: rgba(239,68,68,.08); }

.wp-proposal-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.wp-proposal-title {
    font-size: 13px;
    font-weight: 500;
    color: #67e8f9;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-decoration: none;
}
.wp-proposal-title:hover { text-decoration: underline; }
.wp-proposal-by { font-size: 11px; color: rgba(255,255,255,.3); }

.wp-proposal-actions { display: flex; gap: 4px; align-items: center; flex-shrink: 0; }

.wp-vote-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 12px;
    cursor: pointer;
    color: rgba(255,255,255,.6);
    transition: all .15s;
}
.wp-vote-btn:hover { background: rgba(251,191,36,.15); border-color: rgba(251,191,36,.3); color: #fcd34d; }
.wp-vote-btn.voted { background: rgba(251,191,36,.2); border-color: #f59e0b; color: #fcd34d; }
.wp-vote-count { font-weight: 700; }

.wp-start-btn {
    background: rgba(74,222,128,.1);
    border: 1px solid rgba(74,222,128,.3);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 12px;
    cursor: pointer;
    color: #4ade80;
    transition: all .15s;
}
.wp-start-btn:hover { background: rgba(74,222,128,.2); }

.wp-open-btn {
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 6px;
    padding: 3px 7px;
    font-size: 12px;
    color: rgba(255,255,255,.4);
    text-decoration: none;
    transition: all .15s;
}
.wp-open-btn:hover { color: rgba(255,255,255,.8); background: rgba(255,255,255,.1); }

.wp-approve-btn {
    background: rgba(74,222,128,.1);
    border: 1px solid rgba(74,222,128,.3);
    border-radius: 6px;
    padding: 3px 8px;
    font-size: 13px;
    cursor: pointer;
    color: #4ade80;
    font-weight: 700;
    transition: all .15s;
}
.wp-approve-btn:hover { background: rgba(74,222,128,.2); }

.wp-del-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 3px 6px;
    border-radius: 4px;
    font-size: 11px;
    color: rgba(255,255,255,.25);
}
.wp-del-btn:hover { color: #f87171; background: rgba(248,113,113,.1); }

/* ── Form ── */
.wp-form {
    border-top: 1px solid rgba(255,255,255,.06);
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex-shrink: 0;
}
.wp-input {
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 13px;
    color: rgba(255,255,255,.85);
    width: 100%;
    box-sizing: border-box;
}
.wp-input:focus { outline: none; border-color: rgba(251,191,36,.4); }
.wp-input::placeholder { color: rgba(255,255,255,.3); }

.wp-form-footer { display: flex; gap: 6px; align-items: center; }

.wp-submit-btn {
    background: rgba(251,191,36,.15);
    border: 1px solid rgba(251,191,36,.3);
    border-radius: 7px;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #fcd34d;
    cursor: pointer;
    transition: all .15s;
}
.wp-submit-btn:hover { background: rgba(251,191,36,.25); }
.wp-submit-btn:disabled { opacity: .5; cursor: default; }

.wp-clear-btn {
    background: none;
    border: 1px solid rgba(248,113,113,.3);
    border-radius: 7px;
    padding: 6px 10px;
    font-size: 12px;
    color: #f87171;
    cursor: pointer;
    transition: all .15s;
    margin-left: auto;
}
.wp-clear-btn:hover { background: rgba(248,113,113,.1); }

.wp-form-error { font-size: 12px; color: #f87171; }
.wp-form-info  { font-size: 12px; color: #fcd34d; }
</style>
