// TestChat front: provider → model selector, live cost counter, activity badges
// and the write-confirmation dialog. Vanilla on top of Basecoat (shadcn/ui as
// plain HTML + CSS): no build step.
//
// A turn is a POST answered with server-sent events. EventSource only does GET,
// hence the small SSE reader below.

const $ = (id) => document.getElementById(id);
// A message sent while logged out, kept across the login round trip and given back in the input.
const PENDING_MESSAGE = 'testchat.pending_message';
const { usd } = ChatFormat; // format.js, loaded first
// A price source (Langfuse) is configured: without one, the page mentions no price at all.
const PRICING = '1' === document.body.dataset.pricing;

const ICONS = {
    bot: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect x="4" y="8" width="16" height="12" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/></svg>',
    spark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/></svg>',
    tool: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
    loader: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    hand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 11V6a2 2 0 0 0-4 0M14 10V4a2 2 0 0 0-4 0v2M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>',
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>',
    alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>',
};

const state = {
    catalog: null,
    conversationId: null,
    busy: false,
    calls: 0,
    usage: ChatFormat.ZERO_USAGE, // the tokens of the conversation, every call summed
    activity: null, // the badge row of the running turn
    screen: null, // the last screen the running turn opened, kept in sight
    thinking: null,
};

const storage = {
    get: (key) => { try { return localStorage.getItem(key); } catch { return null; } },
    set: (key, value) => { try { localStorage.setItem(key, value); } catch { /* private mode */ } },
};

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------

$('theme').addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    storage.set('testchat.theme', dark ? 'dark' : 'light');
});

// ---------------------------------------------------------------------------
// Selector
// ---------------------------------------------------------------------------

async function loadCatalog() {
    const response = await fetch('/api/catalog');
    if (401 === response.status) {
        location.href = '/login';
        return;
    }
    state.catalog = await response.json();

    const provider = $('provider');
    provider.replaceChildren();
    for (const p of state.catalog.providers) {
        const reason = p.available ? '' : (p.error === 'No API key.' ? ' - no key' : ' - unavailable');
        const option = new Option(`${p.label}${p.available ? ` (${p.models.length})` : reason}`, p.key);
        option.title = p.error ?? '';
        option.disabled = !p.available;
        provider.add(option);
    }

    const saved = storage.get('testchat.provider');
    const firstAvailable = state.catalog.providers.find((p) => p.available);
    provider.value = state.catalog.providers.some((p) => p.key === saved && p.available) ? saved : (firstAvailable?.key ?? state.catalog.providers[0]?.key ?? '');
    fillModels();

    if (!firstAvailable) {
        errorMessage('No API key configured. Set them in .project.local.ini ([infrastructure.ai.*] api_key), then run castor init:env and castor chat:up.');
        $('send').disabled = true;
    }
}

function currentProvider() {
    return state.catalog?.providers.find((p) => p.key === $('provider').value);
}

function fillModels() {
    const provider = currentProvider();
    const model = $('model');
    model.replaceChildren();

    // Every chat model the provider's API lists. With a price source, the
    // priced ones first, since only they move the cost counter.
    const all = provider?.models ?? [];
    const groups = PRICING
        ? [['Priced (Langfuse)', all.filter((m) => null !== m.prices)], ['No known price', all.filter((m) => null === m.prices)]]
        : [[null, all]];
    for (const [label, models] of groups) {
        if (!models.length) {
            continue;
        }
        const parent = label ? document.createElement('optgroup') : model;
        for (const m of models) {
            parent.append(new Option(m.name, m.name));
        }
        if (label) {
            parent.label = label;
            model.append(parent);
        }
    }
    const saved = storage.get('testchat.model.' + provider?.key);
    if (provider?.models.some((m) => m.name === saved)) {
        model.value = saved;
    } else if (provider?.default_model) {
        model.value = provider.default_model;
    }
    showPrices();
}

function showPrices() {
    if (!PRICING) {
        return;
    }
    const model = currentProvider()?.models.find((m) => m.name === $('model').value);
    const list = $('prices');
    list.replaceChildren();
    if (!model) {
        return;
    }
    if (null === model.prices) {
        const dt = document.createElement('dt');
        dt.textContent = 'No price in Langfuse: the cost will stay unknown. Add it in Langfuse (Settings → Models) or in config/langfuse-models.yaml.';
        dt.className = 'no-price';
        list.append(dt);
        return;
    }
    const labels = { input: 'Input', input_cached_tokens: 'Cache read', input_cache_creation: 'Cache write', output: 'Output' };
    for (const [type, label] of Object.entries(labels)) {
        if (undefined === model.prices[type]) {
            continue;
        }
        const dt = document.createElement('dt');
        dt.textContent = label;
        const dd = document.createElement('dd');
        dd.textContent = '$' + model.prices[type];
        list.append(dt, dd);
    }
}

// ---------------------------------------------------------------------------
// Auto-accept switch: the server accepts every write of the turn on the spot
// ---------------------------------------------------------------------------

function showAutoAccept() {
    $('auto-accept-label').textContent = $('auto-accept').checked ? 'Auto-accepted' : 'Confirm';
}

$('auto-accept').checked = '1' === storage.get('testchat.auto_accept');
showAutoAccept();
$('auto-accept').addEventListener('change', () => {
    storage.set('testchat.auto_accept', $('auto-accept').checked ? '1' : '0');
    showAutoAccept();
});

$('provider').addEventListener('change', () => {
    storage.set('testchat.provider', $('provider').value);
    fillModels();
});
$('model').addEventListener('change', () => {
    storage.set('testchat.model.' + $('provider').value, $('model').value);
    showPrices();
});

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * A small Markdown subset for the model's answers. The text is HTML-escaped
 * FIRST, so whatever the model writes can only ever produce the tags below.
 */
function renderMarkdown(text) {
    const blocks = [];
    let html = escapeHtml(text).replace(/```[a-z]*\n?([\s\S]*?)```/g, (_, code) => {
        blocks.push(`<pre><code>${code.replace(/\n$/, '')}</code></pre>`);
        return `\u0000${blocks.length - 1}\u0000`;
    });

    // Links: [text](http…) and bare http(s) URLs, opened in a new tab (an authorization link, for one).
    // The text is escaped already: `&` is `&amp;`, which is what an href attribute wants.
    const link = (href, label) => `<a href="${href}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    const inline = (line) => line
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, (_, label, href) => link(href, label))
        .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, (_, before, href) => before + link(href, href))
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');

    const out = [];
    let list = null;
    const closeList = () => {
        if (list) {
            out.push(`</${list}>`);
            list = null;
        }
    };

    for (const line of html.split('\n')) {
        const bullet = line.match(/^\s*[-*]\s+(.*)$/);
        const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
        const heading = line.match(/^#{1,4}\s+(.*)$/);
        const block = line.match(/^\u0000(\d+)\u0000$/);

        if (bullet || numbered) {
            const tag = bullet ? 'ul' : 'ol';
            if (list !== tag) {
                closeList();
                out.push(`<${tag}>`);
                list = tag;
            }
            out.push(`<li>${inline((bullet ?? numbered)[1])}</li>`);
            continue;
        }
        closeList();
        if (block) {
            out.push(blocks[Number(block[1])]);
        } else if (heading) {
            out.push(`<h4>${inline(heading[1])}</h4>`);
        } else if ('' !== line.trim()) {
            out.push(`<p>${inline(line)}</p>`);
        }
    }
    closeList();
    html = out.join('');

    return html.replace(/\u0000(\d+)\u0000/g, (_, i) => blocks[Number(i)]);
}

function append(node) {
    $('empty')?.remove();
    $('messages').append(node);
    node.scrollIntoView({ block: 'end' });
    // A screen opened by this turn stays in sight: what follows it never pushes its top out of view.
    const screen = state.screen;
    if (screen && screen !== node && screen.getBoundingClientRect().top < $('messages').getBoundingClientRect().top) {
        screen.scrollIntoView({ block: 'start' });
    }

    return node;
}

function message(role, text) {
    const row = document.createElement('div');
    row.className = 'msg ' + role;

    if ('user' !== role) {
        const avatar = document.createElement('span');
        avatar.className = 'msg-avatar';
        avatar.innerHTML = 'error' === role ? ICONS.alert : ICONS.bot; // static markup only
        row.append(avatar);
    }

    const body = document.createElement('div');
    body.className = 'msg-body';
    if ('assistant' === role) {
        body.innerHTML = renderMarkdown(text); // escaped before formatting
    } else {
        body.textContent = text;
    }
    row.append(body);

    return append(row);
}

function errorMessage(text) {
    return message('error', text);
}

function badge(icon, text, extra = '') {
    if (!state.activity) {
        state.activity = document.createElement('div');
        state.activity.className = 'activity';
        if (state.thinking) {
            state.thinking.before(state.activity);
        } else {
            append(state.activity);
        }
    }
    const span = document.createElement('span');
    span.className = 'badge ' + extra;
    span.dataset.variant = 'outline';
    span.innerHTML = ICONS[icon]; // static markup only
    span.append(document.createTextNode(text));
    state.activity.append(span);
    span.scrollIntoView({ block: 'end' });

    return span;
}

function setBadge(span, icon, text, extra) {
    span.className = 'badge ' + extra;
    span.innerHTML = ICONS[icon]; // static markup only
    span.append(document.createTextNode(text));
}

function startThinking() {
    state.thinking = document.createElement('div');
    state.thinking.className = 'thinking';
    state.thinking.innerHTML = '<span class="dots"><i></i><i></i><i></i></span>'; // static markup only
    state.thinking.append(document.createTextNode('The model is working…'));
    append(state.thinking);
}

function stopThinking() {
    state.thinking?.remove();
    state.thinking = null;
}

function setCosts(conversation, turn, complete = true) {
    $('call-count').textContent = String(state.calls);
    if (!PRICING) {
        return;
    }
    if (null !== conversation && undefined !== conversation) {
        const node = $('conversation-cost');
        if (node.textContent !== usd(conversation)) {
            node.textContent = usd(conversation);
            node.classList.add('bump');
            setTimeout(() => node.classList.remove('bump'), 600);
        }
        node.classList.toggle('incomplete', !complete);
        $('incomplete-note').hidden = complete;
    }
    if (null !== turn && undefined !== turn) {
        $('turn-cost').textContent = usd(turn);
    }
}

/** The tokens of the conversation, in the top bar: every call summed, in the format of a call. */
function setTokens(usage) {
    state.usage = usage;
    $('conversation-tokens').textContent = ChatFormat.usage(usage);
}

// ---------------------------------------------------------------------------
// Conversation
// ---------------------------------------------------------------------------

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

function showEmpty() {
    $('messages').replaceChildren($('empty-template').content.cloneNode(true));
}

/**
 * Opens a conversation in place: its messages, and the activity of each turn
 * replayed as badges, the way it looked.
 */
async function openConversation(id) {
    state.conversationId = id;
    state.calls = 0;
    state.activity = null;
    setTokens(ChatFormat.ZERO_USAGE);
    storage.set('testchat.conversation', id);
    if (location.hash !== '#' + id) {
        history.replaceState(null, '', '#' + id);
    }
    showEmpty();
    setCosts(0, 0);
    markActive();
    document.body.classList.remove('history-open');

    const response = await fetch('/api/conversations/' + id);
    if (!response.ok || id !== state.conversationId) {
        return;
    }
    const conversation = await response.json();
    for (const line of conversation.transcript) {
        if ('activity' === line.role) {
            ({ llm_call: renderLlmCall, tool_call: renderToolCall, elicitation_resolved: renderElicitation })[line.kind]?.(line.data);
            continue;
        }
        state.activity = null;
        message(line.role, line.text);
    }
    state.activity = null;
    state.calls = conversation.calls.length;
    setTokens(conversation.calls.map(ChatFormat.callUsage).reduce(ChatFormat.addUsage, ChatFormat.ZERO_USAGE));
    setCosts(conversation.cost_usd, null, conversation.cost_complete);
}

function newConversation() {
    if (!state.busy) {
        openConversation(crypto.randomUUID());
        $('input').focus();
    }
}

$('new-conversation').addEventListener('click', newConversation);

// The suggestions live in the empty state, re-created for each new conversation.
$('messages').addEventListener('click', (event) => {
    const button = event.target.closest('[data-suggestion]');
    if (button) {
        $('input').value = button.textContent.trim();
        $('composer').requestSubmit();
    }
});

// ---------------------------------------------------------------------------
// History
// ---------------------------------------------------------------------------

function dayGroup(date) {
    const day = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((day(new Date()) - day(date)) / 86_400_000);

    return 0 === days ? 'Today' : 1 === days ? 'Yesterday' : days < 7 ? 'Last 7 days' : 'Older';
}

function when(date) {
    return 0 === Math.round((new Date().setHours(0, 0, 0, 0) - new Date(date).setHours(0, 0, 0, 0)) / 86_400_000)
        ? date.toLocaleTimeString('en', { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString('en', { day: 'numeric', month: 'short' });
}

async function loadHistory() {
    const response = await fetch('/api/conversations');
    if (!response.ok) {
        return;
    }
    const { conversations } = await response.json();
    const list = $('history-list');
    list.replaceChildren();

    if (!conversations.length) {
        const empty = document.createElement('p');
        empty.className = 'history-empty';
        empty.textContent = 'No conversation yet.';
        list.append(empty);
        return;
    }

    let group = null;
    for (const c of conversations) {
        const date = new Date(c.updated_at ?? c.created_at ?? Date.now());
        if (dayGroup(date) !== group) {
            group = dayGroup(date);
            const heading = document.createElement('div');
            heading.className = 'history-group';
            heading.textContent = group;
            list.append(heading);
        }

        const item = document.createElement('div');
        item.className = 'history-item';
        item.dataset.id = c.id;

        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'history-open';
        const title = document.createElement('span');
        title.className = 'history-title';
        title.textContent = c.title;
        const meta = document.createElement('span');
        meta.className = 'history-meta';
        for (const text of [when(date), c.models.at(-1), PRICING && c.calls ? usd(c.cost_usd) + (c.cost_complete ? '' : ' *') : null]) {
            if (text) {
                const span = document.createElement('span');
                span.textContent = text;
                meta.append(span);
            }
        }
        open.append(title, meta);
        open.addEventListener('click', () => {
            if (!state.busy && c.id !== state.conversationId) {
                openConversation(c.id);
            }
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn history-delete';
        remove.dataset.variant = 'ghost';
        remove.dataset.size = 'icon-sm';
        remove.setAttribute('aria-label', 'Delete “' + c.title + '”');
        remove.innerHTML = ICONS.trash; // static markup only
        remove.addEventListener('click', () => askDelete(c));

        item.append(open, remove);
        list.append(item);
    }
    markActive();
}

function markActive() {
    for (const item of document.querySelectorAll('.history-item')) {
        item.classList.toggle('active', item.dataset.id === state.conversationId);
    }
}

let pendingDelete = null;

function askDelete(conversation) {
    pendingDelete = conversation;
    $('delete-message').textContent = `“${conversation.title}” and its history will be deleted for good.`;
    $('delete-dialog').showModal();
}

for (const button of document.querySelectorAll('#delete-dialog [data-delete]')) {
    button.addEventListener('click', async () => {
        const conversation = pendingDelete;
        pendingDelete = null;
        $('delete-dialog').close();
        if ('confirm' !== button.dataset.delete || !conversation) {
            return;
        }

        const response = await fetch('/api/conversations/' + conversation.id, { method: 'DELETE' });
        if (!response.ok && 404 !== response.status) {
            const body = await response.json().catch(() => ({}));
            errorMessage(body.error ?? `Could not delete it (HTTP ${response.status})`);
            return;
        }
        if (conversation.id === state.conversationId) {
            newConversation();
        }
        loadHistory();
    });
}

$('history-toggle').addEventListener('click', () => document.body.classList.toggle('history-open'));

// ---------------------------------------------------------------------------
// Activity badges, live or replayed
// ---------------------------------------------------------------------------

function renderLlmCall(data) {
    // The input split the way it is billed (uncached, cache read, cache write):
    // the cached part costs a fraction of the rest. The cost only with a price source.
    badge('spark', ChatFormat.callLine(data, PRICING));
}

function toolName(data) {
    return data.name.replace(/^shop_/, '');
}

function renderToolCall(data, running = null) {
    const span = running ?? badge('tool', toolName(data));
    'done' === data.status
        ? setBadge(span, 'check', `${toolName(data)} · ${data.duration_ms} ms`, 'tool-done')
        : setBadge(span, 'alert', `${toolName(data)} · ${data.error}`, 'tool-error');
    if (data.app) {
        renderApp(data.app);
    }
}

function renderElicitation(data) {
    const labels = {
        manual_accept: 'write accepted',
        manual_decline: 'write declined',
        manual_cancel: 'write cancelled',
        auto_accept: 'write accepted automatically (30 s)',
        preset_accept: 'write auto-accepted (switch)',
    };
    badge('hand', labels[data.outcome] ?? data.outcome, 'accept' === data.action ? 'tool-done' : 'tool-error');
}

// ---------------------------------------------------------------------------
// Screens: the chat as an MCP Apps host
// ---------------------------------------------------------------------------
//
// A tool linked to a `ui://` resource opens a screen. Its document is the
// server's: it runs in a sandboxed iframe without the page's origin, and talks
// to the page with JSON-RPC over postMessage. The page answers the handshake,
// hands it the tool result, and relays its tool calls - only those of its own
// app, checked by the chat's server, with the user's token.

const apps = new Map(); // iframe window → { uri, frame, arguments, result }
const appDocuments = new Map(); // uri → Promise<string>

function appDocument(uri) {
    if (!appDocuments.has(uri)) {
        appDocuments.set(uri, fetch('/api/apps/resource?uri=' + encodeURIComponent(uri)).then(async (response) => {
            const body = await response.json().catch(() => ({}));
            if (!response.ok || 'string' !== typeof body.html) {
                appDocuments.delete(uri);
                throw new Error(body.error ?? `HTTP error ${response.status}`);
            }
            return body.html;
        }));
    }
    return appDocuments.get(uri);
}

/** What the model's toolbox kept of a tool result, back in the shape of a tools/call result. */
function appToolResult(result) {
    if ('string' === typeof result) {
        return { content: [{ type: 'text', text: result }] };
    }
    if (result && 'object' === typeof result && result.structuredContent && Array.isArray(result.content)) {
        return result;
    }
    return { content: [{ type: 'text', text: JSON.stringify(result) }], structuredContent: result };
}

function renderApp(app) {
    state.activity = null;
    const card = document.createElement('div');
    card.className = 'app-card';
    const frame = document.createElement('iframe');
    frame.className = 'app-frame';
    frame.title = 'Screen';
    frame.setAttribute('sandbox', 'allow-scripts allow-forms');
    card.append(frame);
    append(card);
    state.screen = card;
    apps.set(frame.contentWindow, { uri: app.uri, frame, arguments: app.arguments ?? {}, result: appToolResult(app.result) });

    appDocument(app.uri).then((html) => {
        // The screen follows the page's theme, not the system's.
        const scheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        frame.srcdoc = html.replace('<html', `<html style="color-scheme: ${scheme}"`);
    }).catch((error) => {
        card.replaceChildren(document.createTextNode(`Screen unavailable: ${error.message}`));
        card.classList.add('app-error');
    });
}

/** One tool call made by a screen; a write confirmation it triggers is asked like any other. */
async function callAppTool(app, name, args) {
    const response = await fetch('/api/apps/call', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
        body: JSON.stringify({ uri: app.uri, name, arguments: args ?? {}, auto_accept: $('auto-accept').checked }),
    });
    if (401 === response.status) {
        location.href = '/login';
        throw new Error('Session expired.');
    }
    if (!response.ok || !response.body) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.error ?? `HTTP error ${response.status}`);
    }

    let result = null;
    let failure = null;
    try {
        await readEvents(response.body, (event, data) => ({
            elicitation: askConfirmation,
            elicitation_resolved: closeConfirmation,
            result: (value) => { result = value; },
            error: (value) => { failure = value.message; },
        })[event]?.(data));
    } finally {
        closeConfirmation();
    }
    if (null === result) {
        throw new Error(failure ?? 'The server answered nothing.');
    }
    return result;
}

addEventListener('message', async (event) => {
    const app = apps.get(event.source);
    if (!app) {
        return;
    }
    let msg = event.data;
    if ('string' === typeof msg) {
        try { msg = JSON.parse(msg); } catch { return; }
    }
    if (!msg || '2.0' !== msg.jsonrpc || 'string' !== typeof msg.method) {
        return;
    }

    const post = (message) => event.source.postMessage({ jsonrpc: '2.0', ...message }, '*');
    const reply = (result) => post({ id: msg.id, result });
    const fail = (message) => post({ id: msg.id, error: { code: -32000, message } });

    switch (msg.method) {
        case 'ui/initialize':
            reply({
                protocolVersion: msg.params?.protocolVersion ?? '2026-01-26',
                hostInfo: { name: 'sylius-assistant-testchat', version: '0.1.0' },
                hostCapabilities: { openLinks: {}, serverTools: {} },
                hostContext: { displayMode: 'inline', theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light' },
            });
            break;
        case 'ui/notifications/initialized':
            post({ method: 'ui/notifications/tool-input', params: { arguments: app.arguments } });
            post({ method: 'ui/notifications/tool-result', params: app.result });
            break;
        case 'ui/notifications/size-changed': {
            const height = Number(msg.params?.height);
            if (height > 0) {
                app.frame.style.height = Math.min(height, 1400) + 'px';
            }
            break;
        }
        case 'ui/open-link':
            if (/^https?:\/\//.test(msg.params?.url ?? '')) {
                window.open(msg.params.url, '_blank', 'noopener');
                reply({});
            } else {
                fail('Only http(s) links are opened.');
            }
            break;
        case 'tools/call':
            try {
                reply(await callAppTool(app, msg.params?.name, msg.params?.arguments));
            } catch (error) {
                fail(error.message);
            }
            break;
        default:
            if (undefined !== msg.id) {
                fail(`The host does not implement ${msg.method}.`);
            }
    }
});

// ---------------------------------------------------------------------------
// A turn
// ---------------------------------------------------------------------------

async function send(text) {
    state.busy = true;
    state.screen = null;
    $('send').disabled = true;
    state.activity = null;
    message('user', text);
    startThinking();
    setCosts(null, 0);

    const tools = new Map();
    const handlers = {
        llm_call(data) {
            state.calls += 1;
            renderLlmCall(data);
            setTokens(ChatFormat.addUsage(state.usage, ChatFormat.callUsage(data)));
            setCosts(data.conversation_cost_usd, data.turn_cost_usd, null !== data.cost_usd);
        },
        tool_call(data) {
            if ('started' === data.status) {
                tools.set(data.id, badge('loader', toolName(data), 'running'));
                return;
            }
            renderToolCall(data, tools.get(data.id));
        },
        elicitation: askConfirmation,
        elicitation_resolved(data) {
            renderElicitation(data);
            closeConfirmation();
        },
        assistant(data) {
            stopThinking();
            state.activity = null;
            message('assistant', data.text);
        },
        error(data) {
            stopThinking();
            errorMessage(data.message);
        },
        done(data) {
            setCosts(data.conversation_cost_usd, data.turn_cost_usd, data.cost_complete);
            loadHistory();
        },
    };

    try {
        const response = await fetch(`/api/conversations/${state.conversationId}/turns`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
            body: JSON.stringify({ message: text, provider: $('provider').value, model: $('model').value, auto_accept: $('auto-accept').checked }),
        });

        if (401 === response.status) {
            // Logged out (a real expiry): the message survives the round trip to the login page.
            sessionStorage.setItem(PENDING_MESSAGE, text);
            location.href = '/login';
            return;
        }
        if (!response.ok || !response.body) {
            const body = await response.json().catch(() => ({}));
            stopThinking();
            errorMessage(body.error ?? `HTTP error ${response.status}`);
            return;
        }

        await readEvents(response.body, (event, data) => handlers[event]?.(data));
    } catch (error) {
        errorMessage(String(error));
    } finally {
        stopThinking();
        closeConfirmation();
        // A screen this turn opened: its top in sight once the answer is written.
        const screen = state.screen;
        if (screen && screen.getBoundingClientRect().top < $('messages').getBoundingClientRect().top) {
            screen.scrollIntoView({ block: 'start' });
        }
        state.busy = false;
        $('send').disabled = false;
        $('input').focus();
    }
}

async function readEvents(body, onEvent) {
    const reader = body.pipeThrough(new TextDecoderStream()).getReader();
    let buffer = '';

    for (;;) {
        const { value, done } = await reader.read();
        if (done) {
            return;
        }
        buffer += value;

        let end;
        while (-1 !== (end = buffer.indexOf('\n\n'))) {
            const block = buffer.slice(0, end);
            buffer = buffer.slice(end + 2);

            let event = 'message';
            const data = [];
            for (const line of block.split('\n')) {
                if (line.startsWith('event:')) {
                    event = line.slice(6).trim();
                } else if (line.startsWith('data:')) {
                    data.push(line.slice(5).trimStart());
                }
            }
            if (data.length) {
                onEvent(event, JSON.parse(data.join('\n')));
            }
        }
    }
}

const input = $('input');

function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 220) + 'px';
}

input.addEventListener('input', autosize);
input.addEventListener('keydown', (event) => {
    if ('Enter' === event.key && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        $('composer').requestSubmit();
    }
});

$('composer').addEventListener('submit', (event) => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text || state.busy) {
        return;
    }
    input.value = '';
    autosize();
    send(text);
});

// ---------------------------------------------------------------------------
// Write confirmation, auto-accepted by the server after 30 s
// ---------------------------------------------------------------------------

let confirmation = null;

function askConfirmation(data) {
    closeConfirmation();
    $('confirmation-message').textContent = data.message;

    const fields = $('confirmation-fields');
    fields.replaceChildren();
    for (const field of Object.values(data.fields ?? {})) {
        const li = document.createElement('li');
        if (field.title) {
            const title = document.createElement('strong');
            title.textContent = field.title;
            li.append(title);
        }
        if (field.description) {
            li.append(document.createTextNode(field.description));
        }
        fields.append(li);
    }

    const total = data.timeout_s * 1000;
    const tick = () => {
        const left = Math.max(0, data.deadline - Date.now());
        $('confirmation-countdown').textContent = String(Math.ceil(left / 1000));
        $('confirmation-bar').style.transform = `scaleX(${left / total})`;
    };
    tick();
    confirmation = { id: data.id, timer: setInterval(tick, 250) };
    $('confirmation').showModal();
}

function closeConfirmation() {
    if (confirmation) {
        clearInterval(confirmation.timer);
        confirmation = null;
    }
    if ($('confirmation').open) {
        $('confirmation').close();
    }
}

for (const button of document.querySelectorAll('#confirmation [data-action]')) {
    button.addEventListener('click', async () => {
        if (!confirmation) {
            return;
        }
        const { id } = confirmation;
        closeConfirmation();
        await fetch('/api/elicitations/' + id, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: button.dataset.action }),
        });
    });
}

// Esc must not silently dismiss the question: it would then auto-accept.
$('confirmation').addEventListener('cancel', (event) => event.preventDefault());

// ---------------------------------------------------------------------------
// Skills: what the MCP server offers this user, what they installed here
// ---------------------------------------------------------------------------
//
// An installed skill is named to the model at every turn (its description says
// when to use it); the model reads its instructions on the server, get_skill,
// when it applies one.

async function loadSkills() {
    const response = await fetch('/api/skills');
    if (response.ok) {
        renderSkills((await response.json()).skills);
    }
}

function renderSkills(skills) {
    $('skills-card').hidden = 0 === skills.length;
    const list = $('skills');
    list.replaceChildren();

    for (const skill of skills) {
        const item = document.createElement('li');
        item.className = 'skill';
        item.title = skill.description;

        const name = document.createElement('strong');
        name.textContent = skill.name;
        const status = document.createElement('span');
        status.className = 'skill-status';
        const action = (label, method, variant) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn';
            button.dataset.size = 'sm';
            button.dataset.variant = variant;
            button.textContent = label;
            button.addEventListener('click', async () => {
                button.disabled = true;
                const changed = await fetch('/api/skills/' + encodeURIComponent(skill.name), { method });
                changed.ok ? renderSkills((await changed.json()).skills) : loadSkills();
            });
            return button;
        };

        const buttons = document.createElement('span');
        buttons.className = 'skill-actions';
        if (null === skill.installed) {
            status.textContent = `v${skill.version} · not installed`;
            buttons.append(action('Install', 'PUT', 'outline'));
        } else if (null === skill.version) {
            status.textContent = `v${skill.installed} · no longer offered`;
            buttons.append(action('Remove', 'DELETE', 'ghost'));
        } else if (skill.installed !== skill.version) {
            status.textContent = `v${skill.installed} installed · v${skill.version} available`;
            buttons.append(action('Update', 'PUT', 'outline'), action('Remove', 'DELETE', 'ghost'));
        } else {
            status.textContent = `v${skill.installed} · installed`;
            item.classList.add('installed');
            buttons.append(action('Remove', 'DELETE', 'ghost'));
        }

        item.append(name, status, buttons);
        list.append(item);
    }
}

// ---------------------------------------------------------------------------

loadSkills();
loadCatalog().then(() => {
    const fromHash = location.hash.slice(1);
    const remembered = storage.get('testchat.conversation');
    openConversation(UUID.test(fromHash) ? fromHash : (UUID.test(remembered ?? '') ? remembered : crypto.randomUUID()));
    loadHistory();

    const pending = sessionStorage.getItem(PENDING_MESSAGE);
    if (null !== pending) {
        sessionStorage.removeItem(PENDING_MESSAGE);
        $('input').value = pending;
        $('input').focus();
    }
});

addEventListener('hashchange', () => {
    const id = location.hash.slice(1);
    if (UUID.test(id) && id !== state.conversationId && !state.busy) {
        openConversation(id);
    }
});
