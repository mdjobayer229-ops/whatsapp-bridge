if (!globalThis.crypto) globalThis.crypto = require('crypto').webcrypto;
const http = require('http');
const fs = require('fs');
const path = require('path');
const axios = require('axios');
const net = require('net');
const dns = require('dns');
const accountManager = require('./account-manager');
const scanner = require('./scanner');
const validator = require('./validator');
const blocklist = require('./blocklist');
const contacts = require('./contacts');
const messenger = require('./messenger');
const warmup = require('./warmup');

const LOG_MAX = 500;
const logs = [];

function log(level, msg) {
  const line = `[${new Date().toISOString()}] [${level}] ${msg}`;
  console.log(line);
  logs.unshift(line);
  if (logs.length > LOG_MAX) logs.length = LOG_MAX;
}
function logInfo(msg) { log('INFO', msg); }
function logWarn(msg) { log('WARN', msg); }
function logError(msg) { log('ERR', msg); }

const APP_URL = process.env.APP_URL || 'https://jobayer-group-career.workers.dev';
const WEBHOOK_URL = (process.env.WP_API_URL || `${APP_URL}/api/whatsapp/webhook`).replace(/\/+$/, '');

async function handleIncomingMessage(sock, accountId, msg, config) {
  try {
    if (!msg || !msg.key) return;
    if (msg.key.fromMe) return;
    if (!msg.message || msg.message.protocolMessage) return;
    const text = msg.message.conversation || msg.message.extendedTextMessage?.text || msg.message.imageMessage?.caption || '';
    if (!text || !text.trim()) return;
    const from = msg.key.remoteJid;
    const sender = msg.pushName || (from ? from.split('@')[0] : 'unknown');
    if (!from) return;

    const phone = from.split('@')[0];
    if (blocklist.isBlocked(phone)) {
      logInfo(`[BLOCKED] ${sender} (${from}) — skipped`);
      return;
    }

    contacts.addOrUpdate(phone, { status: 'replied', name_guess: sender });

    logInfo(`[IN][${accountId}] ${sender} (${from}): ${text.slice(0, 80)}`);

    const res = await axios.post(config.wpApiUrl || WEBHOOK_URL, {
      phone,
      text,
      name: sender || '',
      fromBrowser: true
    }, { timeout: 65000 });

    const reply = res.data?.reply || res.data?.message || 'Sorry, I could not process that.';

    const typingMs = Math.min(4000, Math.max(1500, reply.length * 50));
    const delay = Math.round(typingMs * (0.7 + Math.random() * 0.6));
    await sock.sendPresenceUpdate('composing', from);
    await new Promise(r => setTimeout(r, delay));
    await sock.sendMessage(from, { text: reply });
    logInfo(`[OUT][${accountId}] ${from}: ${reply.slice(0, 80)}`);
  } catch (err) {
    const from = msg?.key?.remoteJid || 'unknown';
    const errMsg = err.response?.data?.message || err.message || 'Unknown error';
    const statusCode = err.response?.status || '';
    logError(`[${accountId}] ${statusCode ? 'HTTP ' + statusCode + ' ' : ''}${from}: ${errMsg}`);
  }
}

async function processOutboundQueue() {
  const accounts = accountManager.getAllAccounts();
  for (const acc of accounts) {
    if (!acc.connected) continue;
    const status = warmup.getStatus(acc.id);
    if (!status || status.remaining <= 0) continue;
    const sock = accountManager.getAccount(acc.id)?.sock;
    if (!sock) continue;
    await messenger.processQueue(sock, acc.id, status.limit, status.sent_today, async (result) => {
      if (result.status === 'sent') {
        warmup.incrementSent(acc.id);
        contacts.markContacted(result.to.split('@')[0]);
        logInfo(`[SENT][${acc.id}] ${result.to}: ${result.text.slice(0, 60)}`);
      }
    });
  }
}

function bodyParser(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', c => { body += c; });
    req.on('end', () => {
      try { resolve(body ? JSON.parse(body) : {}); }
      catch (e) { reject(new Error('Invalid JSON')); }
    });
    req.on('error', reject);
  });
}

function jsonResponse(res, data, status = 200) {
  res.writeHead(status, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
  res.end(JSON.stringify(data));
}

const HTML_HEAD = `<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp AI Bridge</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#0f0f1a;color:#e0e0e0;padding:20px}
.container{max-width:960px;margin:0 auto}
h1{color:#00e676;font-size:24px;margin-bottom:20px}
h2{color:#69f0ae;font-size:18px;margin:20px 0 10px}
.card{background:#1a1a2e;border-radius:12px;padding:16px;margin-bottom:12px;border:1px solid #2a2a4a}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:10px 0}
.stat{padding:12px;background:#16213e;border-radius:8px;text-align:center}
.stat-value{font-size:28px;font-weight:700;color:#00e676}
.stat-label{font-size:12px;color:#888;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #2a2a4a}
th{color:#69f0ae;font-weight:600}
.status-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
.status-connected{background:#1b5e20;color:#a5d6a7}
.status-waiting{background:#e65100;color:#ffcc80}
.status-error{background:#b71c1c;color:#ef9a9a}
a{color:#69f0ae;text-decoration:none;margin-right:12px;font-size:13px}
a:hover{text-decoration:underline}
.nav{padding:10px 0;margin-bottom:20px;border-bottom:1px solid #2a2a4a}
</style></head><body><div class="container">`;

const HTML_FOOT = `</div></body></html>`;

async function startServer() {
  const port = process.env.PORT || 8080;
  http.createServer(async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    const url = new URL(req.url, `http://localhost:${port}`);
    const pathname = url.pathname;

    try {
      if (pathname === '/health' || pathname === '/') {
        const accounts = accountManager.getAllAccounts();
        const contactStats = contacts.getStats();
        const queueStats = messenger.getQueueStats();
        const scanStats = scanner.getStats();

        jsonResponse(res, {
          connected: accounts.some(a => a.connected),
          accounts,
          contacts: contactStats,
          queue: queueStats,
          scanner: scanStats,
          uptime: process.uptime()
        });
      }

      else if (pathname === '/dashboard') {
        const accounts = accountManager.getAllAccounts();
        const contactStats = contacts.getStats();
        const queueStats = messenger.getQueueStats();
        const scanStats = scanner.getStats();
        const warmupStats = warmup.getAllStatus();

        let accountRows = accounts.map(a => {
          const w = warmupStats[a.id] || {};
          const cls = a.connected ? 'status-connected' : 'status-waiting';
          const qrImg = a.qr_buffer ? `<img src="/qr/${a.id}" style="width:200px;height:200px;border-radius:8px;margin:8px 0" />` : '';
          return `<tr><td>${a.id}</td><td>${a.phone}</td><td><span class="status-badge ${cls}">${a.connected ? 'Connected' : 'Waiting'}</span>${!a.connected && a.qr_buffer ? '<br/>📱 Scan QR' : ''}</td><td>${w.day_count || '-'}</td><td>${w.limit || '-'}</td><td>${w.sent_today || 0}</td><td>${w.remaining || 0}</td><td>${Math.floor(a.uptime / 1000)}s</td></tr>`;
        }).join('');
        let accountQrSections = accounts.filter(a => a.qr_buffer).map(a =>
          `<div class="card" style="text-align:center"><h3 style="color:#69f0ae;margin-bottom:8px">📱 Scan QR for ${a.id} (${a.phone})</h3><img src="/qr/${a.id}" style="width:260px;height:260px;border-radius:12px;border:2px solid #333" /><p style="color:#888;font-size:13px;margin-top:6px">Open WhatsApp → Menu → Linked Devices → Link a Device</p></div>`
        ).join('');

        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`${HTML_HEAD}
<div class="nav">
  <a href="/">Home</a>
  <a href="/dashboard">Dashboard</a>
  <a href="/env">Env</a>
  <a href="/logs">Logs</a>
  <a href="/contacts">Contacts</a>
  <a href="/blocklist">Blocklist</a>
</div>
<h1>Dashboard</h1>
<div class="grid">
  <div class="stat"><div class="stat-value">${contactStats.total}</div><div class="stat-label">Total Contacts</div></div>
  <div class="stat"><div class="stat-value">${contactStats.converted}</div><div class="stat-label">Converted</div></div>
  <div class="stat"><div class="stat-value">${contactStats.replied}</div><div class="stat-label">Replied</div></div>
  <div class="stat"><div class="stat-value">${contactStats.blocked}</div><div class="stat-label">Blocked</div></div>
  <div class="stat"><div class="stat-value">${contactStats.highPriority}</div><div class="stat-label">High Priority</div></div>
  <div class="stat"><div class="stat-value">${contactStats.female}</div><div class="stat-label">Female</div></div>
  <div class="stat"><div class="stat-value">${queueStats.pending}</div><div class="stat-label">Queue Pending</div></div>
  <div class="stat"><div class="stat-value">${queueStats.sent}</div><div class="stat-label">Sent Today</div></div>
</div>
${accountQrSections}
<h2>WhatsApp Accounts</h2>
<div class="card">
<table><thead><tr><th>ID</th><th>Phone</th><th>Status</th><th>Day</th><th>Limit</th><th>Sent</th><th>Remaining</th><th>Uptime</th></tr></thead><tbody>${accountRows || '<tr><td colspan="8">No accounts</td></tr>'}</tbody></table>
</div>
<h2>Scanner</h2>
<div class="card">
<div class="grid">
  <div class="stat"><div class="stat-value">${scanStats.total}</div><div class="stat-label">Scanned</div></div>
  <div class="stat"><div class="stat-value">${scanStats.whatsapp}</div><div class="stat-label">WhatsApp</div></div>
  <div class="stat"><div class="stat-value">${scanStats.noWhatsApp}</div><div class="stat-label">No WhatsApp</div></div>
  <div class="stat"><div class="stat-value">${scanStats.pending}</div><div class="stat-label">Pending</div></div>
</div>
</div>
${HTML_FOOT}`);
      }

      else if (pathname === '/env') {
        jsonResponse(res, {
          APP_URL,
          WEBHOOK_URL,
          NODE_VERSION: process.version,
          ACCOUNTS: process.env.WHATSAPP_ACCOUNTS || 'not set',
          DATA_DIR_EXISTS: fs.existsSync(path.join(__dirname, 'data')),
          cont: {
            contacts: fs.existsSync(path.join(__dirname, 'data', 'contacts.json')) ? JSON.parse(fs.readFileSync(path.join(__dirname, 'data', 'contacts.json'), 'utf8')).length : 0,
            queue: fs.existsSync(path.join(__dirname, 'data', 'queue.json')) ? JSON.parse(fs.readFileSync(path.join(__dirname, 'data', 'queue.json'), 'utf8')).length : 0,
          }
        });
      }

      else if (pathname === '/logs') {
        const limit = parseInt(url.searchParams.get('limit') || '100');
        jsonResponse(res, logs.slice(0, limit));
      }

      else if (pathname === '/contacts') {
        const status = url.searchParams.get('status') || '';
        if (status) {
          jsonResponse(res, { contacts: contacts.getContactsByStatus(status, 200) });
        } else {
          jsonResponse(res, { stats: contacts.getStats() });
        }
      }

      else if (pathname === '/blocklist') {
        if (req.method === 'POST') {
          const data = await bodyParser(req);
          if (data.action === 'add' && data.phone) {
            blocklist.addToBlocklist(data.phone, data.reason || 'api');
            jsonResponse(res, { ok: true });
          } else if (data.action === 'remove' && data.phone) {
            blocklist.removeFromBlocklist(data.phone);
            jsonResponse(res, { ok: true });
          } else {
            jsonResponse(res, { error: 'Invalid action' }, 400);
          }
        } else {
          jsonResponse(res, { blocklist: blocklist.getBlocklist() });
        }
      }

      else if (pathname === '/numbers/generate') {
        const count = parseInt(url.searchParams.get('count') || '1000');
        const phones = scanner.generateBatch(count);
        jsonResponse(res, { generated: phones.length, phones });
      }

      else if (pathname === '/numbers/validate') {
        if (req.method !== 'POST') { jsonResponse(res, { error: 'POST required' }, 405); return; }
        const data = await bodyParser(req);
        const phones = data.phones || [];
        if (phones.length === 0) { jsonResponse(res, { error: 'No phones' }, 400); return; }
        const acc = accountManager.getLeastLoadedAccount();
        if (!acc) { jsonResponse(res, { error: 'No connected account' }, 503); return; }
        const results = await validator.validateBatch(acc.sock, phones);
        for (const r of results) {
          scanner.markScanned(r.phone, r.isWhatsApp, r.jid);
          if (r.isWhatsApp && !blocklist.isBlocked(r.phone)) {
            contacts.addOrUpdate(r.phone, { status: 'pending', source: 'whatsapp_scan', jid: r.jid });
          }
        }
        const valid = results.filter(r => r.isWhatsApp).length;
        jsonResponse(res, { total: results.length, valid, invalid: results.length - valid, results });
      }

      else if (pathname === '/send') {
        if (req.method !== 'POST') { jsonResponse(res, { error: 'POST required' }, 405); return; }
        const data = await bodyParser(req);
        const { to, text, priority } = data;
        if (!to || !text) { jsonResponse(res, { error: 'to and text required' }, 400); return; }
        const phone = to.replace(/[^0-9]/g, '');
        if (blocklist.isBlocked(phone)) { jsonResponse(res, { error: 'Blocked number' }, 403); return; }
        messenger.enqueue(to, text, priority || 0);
        jsonResponse(res, { ok: true, queued: true });
      }

      else if (pathname === '/queue') {
        jsonResponse(res, { queue: messenger.getQueueStats() });
      }

      else if (pathname === '/queue/flush') {
        if (req.method !== 'POST') { jsonResponse(res, { error: 'POST required' }, 405); return; }
        await processOutboundQueue();
        jsonResponse(res, { ok: true });
      }

      else if (pathname === '/warmup') {
        jsonResponse(res, { accounts: warmup.getAllStatus() });
      }

      else if (pathname === '/diag') {
        const results = { node: process.version, arch: process.arch, platform: process.platform, memory: process.memoryUsage(), tests: [] };
        const waHosts = ['web.whatsapp.com', 'w1.web.whatsapp.com', 'w2.web.whatsapp.com', 'w3.web.whatsapp.com', 'w4.web.whatsapp.com', 'w5.web.whatsapp.com', 'w6.web.whatsapp.com', 'w7.web.whatsapp.com', 'w8.web.whatsapp.com', 'w9.web.whatsapp.com', 'w10.web.whatsapp.com', 'v1.web.whatsapp.com', 'v2.web.whatsapp.com'];
        for (const host of waHosts.slice(0, 4)) {
          try {
            const addr = await new Promise((res, rej) => dns.resolve4(host, (e, a) => e ? rej(e) : res(a?.[0] || 'none')));
            const tcpOk = await new Promise((res) => {
              const s = net.connect(443, addr, () => { s.destroy(); res(true); });
              s.on('error', () => { s.destroy(); res(false); });
              s.setTimeout(5000, () => { s.destroy(); res(false); });
            });
            results.tests.push({ host, resolved: addr, tcp_port_443: tcpOk });
          } catch (e) { results.tests.push({ host, error: e.message }); }
        }
        const acc = accountManager.getAllAccounts();
        const accObj = acc.length > 0 ? accountManager.getAccount(acc[0].id) : null;
        results.ws_readyState = accObj?.sock?.ws?.readyState ?? -1;
        results.ws_state_labels = { 0: 'CONNECTING', 1: 'OPEN', 2: 'CLOSING', 3: 'CLOSED', '-1': 'NO_SOCKET' };
        jsonResponse(res, results);
      }

      else if (pathname === '/accounts') {
        if (req.method === 'POST') {
          const data = await bodyParser(req);
          const id = data.id || `acc_${Object.keys(accountManager.getAllAccounts()).length + 1}`;
          const phone = data.phone || process.env.WHATSAPP_PHONE || '880130585531';
          try {
            await accountManager.createAccount({ id, phone, wpApiUrl: WEBHOOK_URL, logInfo, logError });
            jsonResponse(res, { ok: true, id });
          } catch (err) {
            jsonResponse(res, { error: err.message }, 500);
          }
        } else {
          jsonResponse(res, { accounts: accountManager.getAllAccounts() });
        }
      }

      else if (pathname === '/accounts/remove') {
        if (req.method !== 'POST') { jsonResponse(res, { error: 'POST required' }, 405); return; }
        const data = await bodyParser(req);
        if (!data.id) { jsonResponse(res, { error: 'id required' }, 400); return; }
        await accountManager.removeAccount(data.id);
        jsonResponse(res, { ok: true });
      }

      else if (pathname === '/stats') {
        jsonResponse(res, {
          contacts: contacts.getStats(),
          queue: messenger.getQueueStats(),
          scanner: scanner.getStats(),
          accounts: accountManager.getAllAccounts().map(a => ({
            id: a.id,
            phone: a.phone,
            connected: a.connected,
            warmup: warmup.getStatus(a.id)
          }))
        });
      }

      else if (pathname === '/campaign/start') {
        if (req.method !== 'POST') { jsonResponse(res, { error: 'POST required' }, 405); return; }
        const data = await bodyParser(req);
        const count = data.count || 10000;
        const message = data.message || process.env.DEFAULT_OUTREACH_MSG || '';

        const phones = scanner.generateBatch(count);
        jsonResponse(res, { generated: phones.length, message: 'Generated. Now validate with POST /numbers/validate' });
      }

      else if (pathname.startsWith('/qr/')) {
        const accId = pathname.slice(4);
        const acc = accountManager.getAccountById(accId);
        if (acc && acc.qrBuffer) {
          res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'no-cache' });
          res.end(acc.qrBuffer);
        } else {
          jsonResponse(res, { error: 'No QR available' }, 404);
        }
      }

      else {
        jsonResponse(res, { error: 'Not found', paths: ['/health', '/dashboard', '/env', '/logs', '/contacts', '/blocklist', '/numbers/generate', '/numbers/validate', '/send', '/queue', '/queue/flush', '/warmup', '/accounts', '/accounts/remove', '/stats', '/campaign/start', '/qr/:id'] }, 404);
      }
    } catch (err) {
      logError(`HTTP Error: ${err.message}`);
      jsonResponse(res, { error: err.message }, 500);
    }
  }).listen(port, () => {
    logInfo(`Server: http://localhost:${port}`);
    logInfo(`Webhook URL: ${WEBHOOK_URL}`);
  });
}

async function pollServerQueue() {
  const accounts = accountManager.getConnectedAccounts();
  if (accounts.length === 0) return;
  const appBase = APP_URL || WEBHOOK_URL.replace('/api/whatsapp/webhook', '');
  try {
    const res = await axios.get(`${appBase}/api/whatsapp/queue?account_id=web_main`, { timeout: 10000 });
    const pending = res.data?.pending || [];
    for (const msg of pending) {
      const phone = msg.to || msg.to_phone;
      const text = msg.text || msg.text_content;
      if (!phone || !text) continue;
      const acc = accounts[0];
      if (!acc?.sock) continue;
      const jid = phone.includes('@s.whatsapp.net') ? phone : `${phone}@s.whatsapp.net`;
      await acc.sock.sendMessage(jid, { text });
      await axios.post(`${appBase}/api/whatsapp/queue`, {
        action: 'mark_sent', id: msg.id
      }, { timeout: 5000 });
      logInfo(`[QUEUE] Sent to ${phone}: ${text.slice(0, 60)}`);
    }
  } catch (e) {
    if (e.code !== 'ECONNREFUSED' && e.code !== 'ENOTFOUND') {
      logError(`Queue poll error: ${e.message}`);
    }
  }
}

async function startBot() {
  logInfo('Starting WhatsApp AI Bridge v2...');

  const accountsConfig = process.env.WHATSAPP_ACCOUNTS || '1';
  const phone = process.env.WHATSAPP_PHONE || '880130585531';
  const count = parseInt(accountsConfig);

  accountManager.setIncomingHandler(handleIncomingMessage);

  for (let i = 1; i <= count; i++) {
    const id = `acc_${i}`;
    const accPhone = process.env[`WHATSAPP_PHONE_${i}`] || phone;
    logInfo(`Creating account ${id} (${accPhone})...`);
    accountManager.createAccount({ id, phone: accPhone, wpApiUrl: WEBHOOK_URL, logInfo, logError }).catch(err => {
      logError(`Failed to create ${id}: ${err.message}`);
    });
    await new Promise(r => setTimeout(r, 2000));
  }

  setInterval(() => {
    processOutboundQueue().catch(err => logError(`Queue error: ${err.message}`));
  }, 15000);

  setInterval(() => {
    pollServerQueue().catch(err => logError(`Server queue error: ${err.message}`));
  }, 5000);

  setInterval(() => {
    const accounts = accountManager.getAllAccounts();
    for (const acc of accounts) {
      const accObj = accountManager.getAccount(acc.id);
      if (accObj?.sock?.ws?.readyState === 1) {
        logInfo(`[HEARTBEAT][${acc.id}] OK`);
      }
    }
  }, 120000);

  setInterval(() => {
    for (const acc of accountManager.getAllAccounts()) {
      warmup.incrementDay(acc.id);
    }
  }, 86400000);
}

startServer();
startBot().catch(err => {
  logError(`Fatal: ${err.message}`);
  process.exit(1);
});
