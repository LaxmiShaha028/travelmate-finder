<script setup>
import axios from 'axios'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || (import.meta.env.DEV ? 'http://127.0.0.1:8000/api' : '/api'),
})

const token = ref(localStorage.getItem('travelmate_token') || '')
const currentUser = ref(null)
const conversations = ref([])
const selectedConversation = ref(null)
const messages = ref([])
const contacts = ref([])
const notifications = ref([])
const unreadCount = ref(0)
const search = ref('')
const contactSearch = ref('')
const draft = ref('')
const activeFilter = ref('all')
const newChatType = ref('private')
const groupName = ref('')
const selectedContactIds = ref([])
const showNewChat = ref(false)
const showNotifications = ref(false)
const showLogin = ref(false)
const authMode = ref('login')
const loginForm = ref({ name: '', email: '', password: '', password_confirmation: '' })
const error = ref('')
const loading = ref(false)
let refreshTimer
let contactSearchRequest = 0
let chatSearchTimer
let chatSearchRequest = 0
let messageLoadRequest = 0

function apiErrorMessage(exception, fallback) {
    if (exception.response?.status === 401) return 'Your session expired. Please sign in again.'
    if (exception.response?.status >= 500) return fallback
    const validationErrors = exception.response?.data?.errors
    return (validationErrors && Object.values(validationErrors).flat()[0])
        || exception.response?.data?.message
        || fallback
}

api.interceptors.request.use((config) => {
    if (token.value) config.headers.Authorization = `Bearer ${token.value}`
    return config
})

api.interceptors.response.use((response) => response, (exception) => {
    if (exception.response?.status === 401 && token.value) {
        token.value = ''
        currentUser.value = null
        conversations.value = []
        selectedConversation.value = null
        messages.value = []
        showNewChat.value = false
        showNotifications.value = false
        showLogin.value = true
        messageLoadRequest += 1
        localStorage.removeItem('travelmate_token')
    }

    return Promise.reject(exception)
})

const visibleConversations = computed(() => conversations.value.filter((conversation) => {
    const matchesFilter = activeFilter.value === 'all' || conversation.type === activeFilter.value
    const searchText = [
        conversation.name,
        ...(conversation.users
        ?.filter((user) => user.id !== currentUser.value?.id)
        .map((user) => user.name) || []),
    ].filter(Boolean).join(' ').toLocaleLowerCase()
    const searchTerm = search.value.trim().toLocaleLowerCase()

    return matchesFilter && (!searchTerm || searchText.includes(searchTerm))
}))

const selectedTitle = computed(() => {
    if (!selectedConversation.value) return ''
    if (selectedConversation.value.type === 'group') return selectedConversation.value.name
    return selectedConversation.value.users
        ?.find((user) => user.id !== currentUser.value?.id)?.name || 'Private conversation'
})

const onlineMemberCount = computed(() => selectedConversation.value?.users
    ?.filter((user) => user.is_online).length || 0)

watch(search, (value) => {
    window.clearTimeout(chatSearchTimer)
    chatSearchRequest += 1
    messageLoadRequest += 1

    const searchTerm = value.trim().toLocaleLowerCase()
    if (!searchTerm) return

    selectedConversation.value = null
    messages.value = []
    const requestId = chatSearchRequest
    chatSearchTimer = window.setTimeout(async () => {
        if (requestId !== chatSearchRequest) return

        const matches = visibleConversations.value
        const directMatch = matches.find((conversation) => conversation.type === 'private'
            && conversation.users?.some((user) => user.id !== currentUser.value?.id
                && user.name.toLocaleLowerCase().includes(searchTerm)))
        const target = directMatch || matches[0]
        if (!target) return

        try {
            await loadMessages(target)
        } catch (exception) {
            error.value = apiErrorMessage(exception, 'Could not open this conversation.')
        }
    }, 220)
})

async function loadConversations() {
    const { data } = await api.get('/conversations')
    conversations.value = data
    if (selectedConversation.value) {
        selectedConversation.value = data.find((item) => item.id === selectedConversation.value.id) || null
    }
}

async function loadNotifications() {
    const { data } = await api.get('/notifications')
    notifications.value = data.notifications
    unreadCount.value = data.unread_count
}

async function loadMessages(conversation) {
    const requestId = ++messageLoadRequest
    selectedConversation.value = conversation
    const { data } = await api.get(`/conversations/${conversation.id}/messages`)
    if (requestId !== messageLoadRequest) return
    messages.value = data
    await loadConversations()
    if (requestId !== messageLoadRequest) return
    selectedConversation.value = conversations.value.find((item) => item.id === conversation.id) || conversation
    scrollMessagesToEnd()
}

function openConversation(conversation) {
    window.clearTimeout(chatSearchTimer)
    chatSearchRequest += 1
    loadMessages(conversation).catch((exception) => {
        error.value = apiErrorMessage(exception, 'Could not open this conversation.')
    })
}

function scrollMessagesToEnd() {
    requestAnimationFrame(() => {
        const panel = document.querySelector('.message-list')
        if (panel) panel.scrollTop = panel.scrollHeight
    })
}

async function loadContacts() {
    const requestId = ++contactSearchRequest
    if (!token.value) {
        showLogin.value = true
        showNewChat.value = false
        return
    }
    try {
        const { data } = await api.get('/users', { params: { search: contactSearch.value.trim() } })
        if (requestId === contactSearchRequest) contacts.value = data
    } catch (exception) {
        if (requestId === contactSearchRequest) {
            error.value = apiErrorMessage(exception, 'Could not load travelers. Please retry.')
        }
    }
}

async function openNewChat() {
    if (!token.value) {
        showLogin.value = true
        return
    }
    error.value = ''
    newChatType.value = 'private'
    groupName.value = ''
    selectedContactIds.value = []
    contactSearch.value = ''
    showNewChat.value = true
    await loadContacts()
}

async function startConversation(user) {
    if (newChatType.value === 'group') {
        selectedContactIds.value = selectedContactIds.value.includes(user.id)
            ? selectedContactIds.value.filter((id) => id !== user.id)
            : [...selectedContactIds.value, user.id]
        return
    }
    loading.value = true
    error.value = ''
    try {
        const { data } = await api.post('/conversations', { user_ids: [user.id] })
        await loadConversations()
        const conversation = conversations.value.find((item) => item.id === data.id) || data
        showNewChat.value = false
        await loadMessages(conversation)
    } catch (exception) {
        error.value = apiErrorMessage(exception, 'Could not start the conversation.')
    } finally {
        loading.value = false
    }
}

async function createGroupConversation() {
    if (!groupName.value.trim() || selectedContactIds.value.length === 0) return
    loading.value = true
    error.value = ''
    try {
        const { data } = await api.post('/conversations', {
            type: 'group',
            name: groupName.value.trim(),
            user_ids: selectedContactIds.value,
        })
        await loadConversations()
        const conversation = conversations.value.find((item) => item.id === data.id) || data
        showNewChat.value = false
        await loadMessages(conversation)
    } catch (exception) {
        error.value = apiErrorMessage(exception, 'Could not create the group.')
    } finally {
        loading.value = false
    }
}

async function sendMessage() {
    const message = draft.value.trim()
    if (!message || !selectedConversation.value || loading.value) return
    loading.value = true
    error.value = ''
    try {
        const { data } = await api.post('/messages', {
            conversation_id: selectedConversation.value.id,
            message,
        })
        messages.value.push(data)
        draft.value = ''
        await loadConversations()
        selectedConversation.value = conversations.value.find((item) => item.id === data.conversation_id) || selectedConversation.value
        scrollMessagesToEnd()
    } catch (exception) {
        error.value = apiErrorMessage(exception, 'Message could not be sent.')
    } finally {
        loading.value = false
    }
}

async function login() {
    loading.value = true
    error.value = ''
    try {
        const endpoint = authMode.value === 'register' ? '/register' : '/login'
        const { data } = await api.post(endpoint, loginForm.value)
        token.value = data.token
        currentUser.value = data.user
        localStorage.setItem('travelmate_token', data.token)
        showLogin.value = false
        loginForm.value = { name: '', email: '', password: '', password_confirmation: '' }
        await refreshData()
    } catch (exception) {
        error.value = apiErrorMessage(exception, authMode.value === 'register'
                ? 'Could not create your account.'
            : 'Invalid email or password. Create an account if you are new here.')
    } finally {
        loading.value = false
    }
}

async function refreshData() {
    if (!token.value) {
        showLogin.value = true
        return
    }
    error.value = ''
    try {
        const [{ data: userData }] = await Promise.all([
            api.get('/user'),
            api.post('/presence'),
            loadConversations(),
            loadNotifications(),
        ])
        currentUser.value = userData.user
        if (selectedConversation.value) {
            const { data } = await api.get(`/conversations/${selectedConversation.value.id}/messages`)
            messages.value = data
            scrollMessagesToEnd()
        }
    } catch (exception) {
        if (exception.response?.status === 401) {
            token.value = ''
            localStorage.removeItem('travelmate_token')
            showLogin.value = true
        } else {
            error.value = apiErrorMessage(exception, 'Could not connect to TravelMate. Please retry shortly.')
        }
    }
}

async function toggleNotifications() {
    const opening = !showNotifications.value
    showNotifications.value = opening
    if (!opening || !token.value) return

    try {
        await loadNotifications()
    } catch (exception) {
        error.value = apiErrorMessage(exception, 'Could not load notifications.')
    }
}

async function openNotification(notification) {
    showNotifications.value = false
    if (!notification.read_at) {
        try {
            const { data } = await api.post(`/notifications/${notification.id}/read`)
            notification.read_at = new Date().toISOString()
            unreadCount.value = data.unread_count
        } catch (exception) {
            error.value = apiErrorMessage(exception, 'Could not update notification status.')
        }
    }
    const conversationId = notification.data?.conversation_id
    if (!conversationId) return

    let conversation = conversations.value.find((item) => item.id === conversationId)
    if (!conversation) {
        await loadConversations()
        conversation = conversations.value.find((item) => item.id === conversationId)
    }
    if (conversation) openConversation(conversation)
}

function signOut() {
    api.post('/logout').catch(() => {})
    token.value = ''
    currentUser.value = null
    conversations.value = []
    messages.value = []
    selectedConversation.value = null
    localStorage.removeItem('travelmate_token')
    showLogin.value = true
}

function formatTime(value) {
    if (!value) return ''
    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

onMounted(async () => {
    await refreshData()
    refreshTimer = window.setInterval(() => {
        if (token.value) refreshData()
    }, 12000)
})

onUnmounted(() => {
    window.clearInterval(refreshTimer)
    window.clearTimeout(chatSearchTimer)
})
</script>

<template>
    <main class="communication-page">
        <header class="topbar">
            <a class="brand" href="/" aria-label="TravelMate home">
                <span class="brand-mark">T</span>
                <span><strong>TravelMate</strong><small>Stay connected</small></span>
            </a>
            <div class="top-actions">
                <button class="icon-button notification-trigger" aria-label="Notifications" @click="toggleNotifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
                    <span class="count-badge">{{ unreadCount }}</span>
                </button>
                <button class="avatar-button" :aria-label="currentUser?.name || 'Sign in'" @click="currentUser ? signOut() : showLogin = true">
                    {{ currentUser?.name?.slice(0, 1)?.toUpperCase() || 'T' }}
                </button>
            </div>
            <aside v-if="showNotifications" class="notification-popover">
                <strong>Notifications</strong>
                <p v-if="!notifications.length">You're all caught up.</p>
                <button v-for="notification in notifications" :key="notification.id" class="notification-item" :class="{ unread: !notification.read_at }" @click="openNotification(notification)">
                    {{ notification.data.message || notification.data.title || 'TravelMate update' }}
                </button>
            </aside>
        </header>

        <section class="messenger-shell">
            <aside class="conversation-sidebar">
                <div class="sidebar-heading">
                    <div><span class="eyebrow">COMMUNICATION</span><h1>Messages</h1></div>
                    <button class="new-chat-button" aria-label="Start a new chat" title="Start a new chat" @click="openNewChat">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    </button>
                </div>
                <label class="search-box">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>
                    <input v-model="search" placeholder="Search chats..." aria-label="Search chats">
                </label>
                <div class="filter-tabs" role="tablist" aria-label="Conversation type">
                    <button v-for="filter in ['all', 'private', 'group']" :key="filter" :class="{ active: activeFilter === filter }" @click="activeFilter = filter">
                        {{ filter === 'all' ? 'All' : filter === 'private' ? 'Private' : 'Groups' }}
                    </button>
                </div>
                <div class="conversation-list">
                    <button v-for="conversation in visibleConversations" :key="conversation.id" class="conversation-row" :class="{ selected: selectedConversation?.id === conversation.id }" @click="openConversation(conversation)">
                        <span class="contact-avatar">{{ conversation.type === 'group' ? 'G' : (conversation.users?.find(user => user.id !== currentUser?.id)?.name?.slice(0, 1) || 'T').toUpperCase() }}</span>
                        <span class="conversation-copy">
                            <strong>{{ conversation.type === 'group' ? conversation.name : (conversation.users?.find(user => user.id !== currentUser?.id)?.name || 'Private chat') }}</strong>
                            <small>{{ conversation.latest_message?.message || 'Start a conversation' }}</small>
                        </span>
                        <time>{{ formatTime(conversation.latest_message?.created_at) }}</time>
                    </button>
                    <p v-if="!visibleConversations.length" class="empty-list">{{ search ? 'No matching conversations.' : 'No conversations found.' }}</p>
                </div>
                <footer class="sidebar-footer">
                    <button @click="toggleNotifications"><span class="footer-icon">♧</span>Notifications<span class="footer-count">{{ unreadCount }}</span></button>
                    <button @click="toggleNotifications"><span class="footer-icon">▦</span>Trip Reminders<span class="footer-count">0</span></button>
                </footer>
            </aside>

            <section class="chat-panel">
                <div v-if="error" class="inline-error" role="alert">{{ error }}</div>
                <template v-if="selectedConversation">
                    <header class="chat-header">
                        <span class="contact-avatar large">{{ selectedConversation.type === 'group' ? 'G' : selectedTitle.slice(0, 1).toUpperCase() }}</span>
                        <div class="chat-heading-copy">
                            <strong>{{ selectedTitle }}</strong>
                            <small>{{ selectedConversation.type === 'group' ? `${selectedConversation.users?.length || 0} members · ${onlineMemberCount} online` : 'TravelMate' }}</small>
                            <div v-if="selectedConversation.type === 'group'" class="member-presence" aria-label="Group members and online status">
                                <span v-for="member in selectedConversation.users" :key="member.id" class="member-presence-item" :title="`${member.name}: ${member.is_online ? 'online' : 'offline'}`">
                                    <span class="presence-dot" :class="{ online: member.is_online }"></span>{{ member.id === currentUser?.id ? 'You' : member.name }}
                                </span>
                            </div>
                        </div>
                    </header>
                    <div class="message-list" aria-live="polite">
                        <p v-if="!messages.length" class="empty-chat">Say hello and start planning your trip.</p>
                        <article v-for="message in messages" :key="message.id" class="message" :class="{ own: message.sender_id === currentUser?.id }">
                            <span v-if="selectedConversation.type === 'group' || message.sender_id !== currentUser?.id" class="message-sender">
                                {{ message.sender_id === currentUser?.id ? 'You' : message.sender?.name }}
                            </span>
                            <p>{{ message.message }}</p><time>{{ formatTime(message.created_at) }}</time>
                        </article>
                    </div>
                    <form class="composer" @submit.prevent="sendMessage">
                        <input v-model="draft" maxlength="10000" placeholder="Write a message..." aria-label="Write a message" :disabled="loading">
                        <button type="submit" aria-label="Send message" :disabled="!draft.trim() || loading">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                        </button>
                    </form>
                </template>
                <div v-else class="empty-state">
                    <span class="empty-mark">T</span>
                    <h2>{{ token ? 'Your travel conversations' : 'Sign in to your messages' }}</h2>
                    <p>{{ token ? 'Select a conversation or start a new chat.' : 'Connect with your travel companions and plan together.' }}</p>
                    <button v-if="!token" class="primary-action" @click="showLogin = true">Sign in</button>
                    <button v-else class="primary-action" @click="openNewChat">Start a chat</button>
                </div>
            </section>
        </section>

        <div v-if="showLogin" class="modal-backdrop" @click.self="showLogin = false">
            <form class="dialog" @submit.prevent="login">
                <button class="close-button" type="button" aria-label="Close" @click="showLogin = false">×</button>
                <span class="eyebrow">TRAVELMATE ACCOUNT</span><h2>{{ authMode === 'login' ? 'Sign in to Messages' : 'Create your account' }}</h2>
                <label v-if="authMode === 'register'">Name<input v-model="loginForm.name" autocomplete="name" maxlength="255" required></label>
                <label>Email<input v-model="loginForm.email" type="email" autocomplete="username" required></label>
                <label>Password<input v-model="loginForm.password" type="password" :autocomplete="authMode === 'login' ? 'current-password' : 'new-password'" :minlength="authMode === 'register' ? 8 : undefined" required></label>
                <label v-if="authMode === 'register'">Confirm password<input v-model="loginForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
                <p v-if="error" class="form-error">{{ error }}</p>
                <button class="primary-action" :disabled="loading">{{ loading ? 'Please wait...' : authMode === 'login' ? 'Sign in' : 'Create account' }}</button>
                <button class="auth-switch" type="button" @click="authMode = authMode === 'login' ? 'register' : 'login'; error = ''">
                    {{ authMode === 'login' ? 'New to TravelMate? Create an account' : 'Already have an account? Sign in' }}
                </button>
            </form>
        </div>

        <div v-if="showNewChat" class="modal-backdrop" @click.self="showNewChat = false">
            <section class="dialog contact-dialog">
                <button class="close-button" aria-label="Close" @click="showNewChat = false">×</button>
                <span class="eyebrow">NEW CONVERSATION</span><h2>Find a travel mate</h2>
                <div class="filter-tabs modal-tabs" role="tablist" aria-label="Conversation type">
                    <button :class="{ active: newChatType === 'private' }" @click="newChatType = 'private'; selectedContactIds = []">Private</button>
                    <button :class="{ active: newChatType === 'group' }" @click="newChatType = 'group'; selectedContactIds = []">Group</button>
                </div>
                <label v-if="newChatType === 'group'" class="group-name-field">Group name
                    <input v-model="groupName" maxlength="255" placeholder="e.g. Cox's Bazar weekend">
                </label>
                <label class="search-box dialog-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 4 4"/></svg>
                    <input v-model="contactSearch" placeholder="Search travelers by name..." aria-label="Search travelers" @input="loadContacts">
                </label>
                <div class="contact-results">
                    <button v-for="contact in contacts" :key="contact.id" class="contact-result" :class="{ chosen: selectedContactIds.includes(contact.id) }" :disabled="loading" @click="startConversation(contact)">
                        <span class="contact-avatar">{{ contact.name.slice(0, 1).toUpperCase() }}</span><span>{{ contact.name }}</span><span class="result-arrow">→</span>
                    </button>
                    <p v-if="!contacts.length" class="empty-list">No travelers found.</p>
                </div>
                <button v-if="newChatType === 'group'" class="primary-action create-group-button" :disabled="loading || !groupName.trim() || !selectedContactIds.length" @click="createGroupConversation">
                    {{ loading ? 'Creating group...' : `Create group (${selectedContactIds.length} selected)` }}
                </button>
                <p v-if="error" class="form-error">{{ error }}</p>
            </section>
        </div>
    </main>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap');

:global(*) { box-sizing: border-box; }
:global(body) { margin: 0; background: #f8f7f4; color: #21201e; font-family: 'DM Sans', sans-serif; }
button, input { font: inherit; }
button { cursor: pointer; }
.communication-page { min-height: 100vh; display: flex; flex-direction: column; }
.topbar { height: 68px; padding: 0 clamp(20px, 4vw, 48px); display: flex; align-items: center; justify-content: space-between; background: #fff; border-bottom: 1px solid #e9e5dd; position: relative; z-index: 2; }
.brand { display: flex; align-items: center; gap: 11px; color: inherit; text-decoration: none; }
.brand-mark { width: 34px; height: 34px; display: grid; place-items: center; background: #df6738; color: #fff; border-radius: 9px; font: 800 18px 'Manrope', sans-serif; }
.brand strong { display: block; font: 800 16px 'Manrope', sans-serif; }
.brand small { display: block; margin-top: 1px; color: #857f76; font-size: 11px; }
.top-actions { display: flex; align-items: center; gap: 20px; }
.icon-button, .avatar-button { border: 0; background: transparent; position: relative; color: #262522; }
.icon-button svg, .search-box svg, .new-chat-button svg, .composer button svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.count-badge { position: absolute; right: -8px; top: -7px; min-width: 16px; height: 16px; display: grid; place-items: center; border-radius: 50%; background: #df6738; color: white; font-size: 10px; }
.avatar-button { width: 36px; height: 36px; border-radius: 50%; background: #efeae0; font-weight: 700; }
.notification-popover { position: absolute; top: 58px; right: 60px; width: min(320px, calc(100vw - 32px)); padding: 18px; background: #fff; border: 1px solid #e9e5dd; border-radius: 10px; box-shadow: 0 12px 34px #23201b1c; }
.notification-popover p, .notification-item { color: #746e65; font-size: 13px; }
.notification-item { width: 100%; padding: 12px 0; border: 0; border-bottom: 1px solid #eeeae4; background: transparent; text-align: left; cursor: pointer; }
.notification-item.unread { color: #34312d; font-weight: 600; }
.messenger-shell { width: min(1244px, calc(100% - 48px)); height: calc(100vh - 104px); min-height: 520px; max-height: 860px; margin: 18px auto; display: grid; grid-template-columns: 320px minmax(0, 1fr); overflow: hidden; border: 1px solid #e8e3db; border-radius: 16px; background: #fff; box-shadow: 0 14px 46px #30281b0a; }
.conversation-sidebar { min-width: 0; display: flex; flex-direction: column; background: #eeeae2; border-right: 1px solid #e2ddd4; }
.sidebar-heading { display: flex; justify-content: space-between; align-items: center; padding: 24px 19px 17px; }
.eyebrow { color: #dc6435; letter-spacing: 1px; font-size: 10px; font-weight: 800; }
h1, h2, p { margin-top: 0; }
.sidebar-heading h1 { margin: 3px 0 0; font: 800 22px 'Manrope', sans-serif; }
.new-chat-button { width: 36px; height: 36px; border: 0; border-radius: 11px; background: #242321; color: #fff; display: grid; place-items: center; }
.search-box { height: 40px; margin: 0 16px; padding: 0 12px; display: flex; align-items: center; gap: 10px; border: 1px solid #e1ddd6; border-radius: 10px; background: #fff; color: #8b857c; }
.search-box svg { width: 17px; height: 17px; }
.search-box input { min-width: 0; width: 100%; height: 100%; border: 0; outline: 0; background: transparent; color: #262522; font-size: 12px; }
.filter-tabs { padding: 14px 16px 10px; display: flex; gap: 8px; }
.filter-tabs button { padding: 6px 12px; border: 0; border-radius: 20px; background: transparent; color: #6f6a62; font-size: 11px; font-weight: 600; }
.filter-tabs button.active { background: #242321; color: #fff; }
.conversation-list { min-height: 0; flex: 1; overflow-y: auto; padding: 4px 8px 12px; }
.conversation-row { width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 9px; text-align: left; border: 0; border-radius: 10px; background: transparent; color: inherit; }
.conversation-row:hover, .conversation-row.selected { background: #fff; }
.contact-avatar { flex: 0 0 auto; width: 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; background: #dce8df; color: #426a52; font-weight: 700; }
.conversation-copy { min-width: 0; flex: 1; }
.conversation-copy strong, .conversation-copy small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.conversation-copy strong { font-size: 12px; }
.conversation-copy small { margin-top: 4px; color: #807a71; font-size: 11px; }
.conversation-row time { align-self: flex-start; color: #8a847b; font-size: 9px; }
.empty-list { padding: 28px 12px; color: #817b72; text-align: center; font-size: 12px; }
.sidebar-footer { padding: 12px 15px 14px; border-top: 1px solid #ded9d0; }
.sidebar-footer button { width: 100%; padding: 8px 3px; display: flex; align-items: center; gap: 9px; border: 0; background: transparent; color: #534f49; text-align: left; font-size: 11px; }
.footer-icon { width: 22px; height: 22px; display: grid; place-items: center; border-radius: 7px; background: white; color: #dc6435; font-size: 13px; }
.footer-count { width: 17px; height: 17px; display: grid; place-items: center; margin-left: auto; border-radius: 50%; background: #dc6435; color: white; font-size: 9px; }
.chat-panel { min-width: 0; display: flex; flex-direction: column; position: relative; background: #fff; }
.chat-header { min-height: 70px; padding: 14px 22px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #eeeae4; }
.contact-avatar.large { width: 42px; height: 42px; }
.chat-header strong, .chat-header small { display: block; }
.chat-header strong { font: 700 14px 'Manrope', sans-serif; }
.chat-header small { margin-top: 3px; color: #817b72; font-size: 11px; }
.chat-heading-copy { min-width: 0; }
.member-presence { display: flex; flex-wrap: wrap; gap: 5px 12px; margin-top: 7px; }
.member-presence-item { display: inline-flex; align-items: center; gap: 5px; color: #777168; font-size: 10px; }
.presence-dot { width: 7px; height: 7px; flex: 0 0 auto; border-radius: 50%; background: #aaa49a; }
.presence-dot.online { background: #2e9a62; }
.message-list { flex: 1; padding: 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
.empty-chat { margin: auto; color: #89837a; font-size: 13px; }
.message { max-width: min(70%, 480px); align-self: flex-start; }
.message.own { align-self: flex-end; text-align: right; }
.message-sender { display: block; margin-bottom: 4px; color: #6e685f; font-size: 10px; }
.message p { margin: 0; padding: 11px 14px; border-radius: 14px 14px 14px 4px; background: #f1eee8; text-align: left; font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.message.own p { border-radius: 14px 14px 4px 14px; background: #de693d; color: white; }
.message time { display: block; margin: 4px 4px 0; color: #8a847b; font-size: 9px; }
.composer { margin: 0 20px 18px; padding: 7px 8px 7px 15px; display: flex; gap: 10px; align-items: center; border: 1px solid #e5e0d8; border-radius: 12px; }
.composer input { min-width: 0; flex: 1; height: 38px; border: 0; outline: 0; font-size: 13px; }
.composer button { width: 38px; height: 38px; display: grid; place-items: center; border: 0; border-radius: 10px; background: #dc6435; color: white; }
.composer button:disabled, .primary-action:disabled { opacity: .5; cursor: not-allowed; }
.empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 28px; text-align: center; }
.empty-mark { width: 50px; height: 50px; margin-bottom: 18px; display: grid; place-items: center; border-radius: 15px; background: #df6738; color: #fff; font: 800 22px 'Manrope', sans-serif; }
.empty-state h2 { margin-bottom: 8px; font: 700 19px 'Manrope', sans-serif; }
.empty-state p { max-width: 300px; color: #817b72; font-size: 13px; line-height: 1.55; }
.primary-action { min-height: 42px; padding: 0 18px; border: 0; border-radius: 9px; background: #22211f; color: #fff; font-weight: 600; }
.inline-error { margin: 12px 18px 0; padding: 10px 12px; border-radius: 8px; background: #fff0e9; color: #a3401e; font-size: 12px; }
.modal-backdrop { position: fixed; inset: 0; z-index: 5; display: grid; place-items: center; padding: 16px; background: #25221ecc; }
.dialog { width: min(420px, 100%); max-height: min(620px, 90vh); overflow-y: auto; position: relative; padding: 27px; border-radius: 14px; background: white; box-shadow: 0 20px 70px #0003; }
.dialog h2 { margin: 5px 32px 22px 0; font: 800 21px 'Manrope', sans-serif; }
.close-button { position: absolute; top: 16px; right: 17px; width: 30px; height: 30px; border: 0; border-radius: 50%; background: #f1eee8; font-size: 20px; }
.dialog > label:not(.search-box) { display: block; margin-bottom: 14px; color: #5d574e; font-size: 12px; font-weight: 600; }
.dialog > label input { width: 100%; height: 42px; margin-top: 6px; padding: 0 11px; border: 1px solid #ddd8d0; border-radius: 8px; outline-color: #dc6435; }
.dialog > .primary-action { width: 100%; margin-top: 8px; }
.auth-switch { width: 100%; margin-top: 14px; border: 0; background: transparent; color: #a64c2b; font-size: 12px; font-weight: 600; }
.form-error { color: #aa361d; font-size: 12px; }
.dialog-search { margin: 0 0 12px; }
.modal-tabs { padding: 0 0 12px; }
.group-name-field { display: block; margin: 0 0 12px; color: #5d574e; font-size: 12px; font-weight: 600; }
.group-name-field input { width: 100%; height: 40px; margin-top: 6px; padding: 0 11px; border: 1px solid #ddd8d0; border-radius: 8px; outline-color: #dc6435; }
.contact-results { max-height: 290px; overflow: auto; }
.contact-result { width: 100%; display: flex; align-items: center; gap: 11px; padding: 9px 2px; border: 0; border-bottom: 1px solid #eeeae4; background: white; text-align: left; font-size: 13px; }
.contact-result.chosen { background: #f5f1e9; }
.contact-result .result-arrow { margin-left: auto; color: #dd6739; font-size: 18px; }
.create-group-button { width: 100%; margin-top: 13px; }
@media (max-width: 720px) {
    .topbar { height: 60px; padding: 0 16px; }
    .messenger-shell { width: 100%; height: calc(100dvh - 60px); min-height: 0; max-height: none; margin: 0; grid-template-columns: minmax(108px, 34%) minmax(0, 1fr); border: 0; border-radius: 0; }
    .sidebar-heading { padding: 17px 11px 13px; }
    .sidebar-heading h1 { font-size: 19px; }
    .new-chat-button { width: 32px; height: 32px; }
    .search-box { margin: 0 8px; padding: 0 8px; gap: 5px; }
    .search-box input { font-size: 11px; }
    .filter-tabs { padding: 10px 7px; gap: 2px; }
    .filter-tabs button { padding: 6px 8px; font-size: 10px; }
    .conversation-list { padding-left: 4px; padding-right: 4px; }
    .conversation-row { gap: 7px; padding: 8px 5px; }
    .conversation-row .contact-avatar { width: 32px; height: 32px; }
    .conversation-copy strong { font-size: 11px; }
    .conversation-copy small, .conversation-row time { display: none; }
    .sidebar-footer { padding: 8px 5px; }
    .sidebar-footer button { gap: 5px; font-size: 10px; }
    .footer-count { width: 15px; height: 15px; }
    .chat-header { padding: 12px; }
    .message-list { padding: 14px 10px; }
    .composer { margin: 0 9px 10px; padding-left: 10px; }
    .message { max-width: 88%; }
}
</style>