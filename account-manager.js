const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason
} = require('@whiskeysockets/baileys');
const QRCode = require('qrcode');
const path = require('path');
const fs = require('fs');
const warmup = require('./warmup');
const messenger = require('./messenger');
const validator = require('./validator');
const contacts = require('./contacts');
const blocklist = require('./blocklist');

const accounts = {};
const accountList = [];
let onIncomingMessage = null;

function setIncomingHandler(handler) {
  onIncomingMessage = handler;
}

async function createAccount(config) {
  const { id, phone, wpApiUrl, logInfo, logError } = config;
  const authDir = path.join(__dirname, `auth_info_${id}`);

  if (accounts[id]) {
    return accounts[id];
  }

  warmup.registerAccount(id, phone);

  const log = logInfo || ((msg) => console.log(`[${id}] ${msg}`));
  const logErr = logError || ((msg) => console.error(`[${id}] ${msg}`));

  const { state, saveCreds } = await useMultiFileAuthState(authDir);
  const sock = makeWASocket({
    auth: state,
    browser: ['Chrome', 'macOS', '10.15.7'],
    printQRInTerminal: false,
    defaultQueryTimeoutMs: 60000,
    logger: require('pino')({ level: 'warn' }),
    connectTimeoutMs: 60000,
    keepAliveIntervalMs: 25000,
    markOnlineOnConnect: false,
    syncFullHistory: false,
  });

  let reconnectAttempts = 0;
  const accountObj = { sock, id, phone, config, connected: false, qrBuffer: null, authDir, createdAt: Date.now() };

  let qrTimeout = setTimeout(() => {
    if (!accountObj.qrBuffer && !accountObj.connected) {
      log(`No QR/connect after 30s — WebSocket may be blocked`);
    }
  }, 30000);

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async ({ connection, lastDisconnect, qr }) => {
    if (qr) {
      reconnectAttempts = 0;
      clearTimeout(qrTimeout);
      log(`QR code received, generating buffer...`);
      try {
        accountObj.qrBuffer = await QRCode.toBuffer(qr, { width: 400, margin: 2, type: 'png' });
        log(`QR buffer generated (${accountObj.qrBuffer.length} bytes)`);
        log(`Scan QR at /qr/${id} or visit /dashboard`);
      } catch (e) {
        log(`QR buffer generation failed: ${e.message}`);
      }
    }

    if (connection === 'open') {
      reconnectAttempts = 0;
      clearTimeout(qrTimeout);
      accountObj.connected = true;
      log(`Connection opened ✓`);
    }

    if (connection === 'close') {
      clearTimeout(qrTimeout);
      accountObj.connected = false;
      const reason = lastDisconnect?.error?.output?.statusCode;
      const reasonText = lastDisconnect?.error?.message || 'unknown';
      log(`Connection closed — reason code: ${reason}, message: ${reasonText}`);
      if (reason === DisconnectReason.loggedOut) {
        log(`Logged out, removing auth dir...`);
        try { fs.rmSync(authDir, { recursive: true, force: true }); } catch (_) {}
      }
      reconnectAttempts++;
      const delay = Math.min(1000 * Math.pow(2, reconnectAttempts), 300000);
      log(`Reconnecting in ${delay}ms (attempt ${reconnectAttempts})...`);
        setTimeout(() => {
          const oldIdx = accountList.findIndex(a => a.id === id);
          if (oldIdx >= 0) accountList.splice(oldIdx, 1);
          delete accounts[id];
          createAccount(config).catch(() => {});
        }, delay);
    }
  });

  sock.ev.on('messages.upsert', async ({ messages }) => {
    if (!onIncomingMessage) return;
    for (const msg of messages) {
      await onIncomingMessage(sock, id, msg, config);
    }
  });

  accounts[id] = accountObj;
  accountList.push(accountObj);

  return accountObj;
}

function getAccount(id) {
  return accounts[id] || null;
}

function getConnectedAccounts() {
  return accountList.filter(a => a.connected);
}

function getLeastLoadedAccount() {
  const connected = getConnectedAccounts();
  if (connected.length === 0) return null;
  let best = connected[0];
  let bestLoad = Infinity;
  for (const acc of connected) {
    const sent = warmup.getSentToday(acc.id);
    const limit = warmup.getDailyLimit(acc.id);
    const load = sent / (limit || 1);
    if (load < bestLoad) {
      bestLoad = load;
      best = acc;
    }
  }
  return best;
}

async function removeAccount(id) {
  const acc = accounts[id];
  if (!acc) return;
  try { acc.sock?.ws?.close(); } catch (_) {}
  delete accounts[id];
  const idx = accountList.findIndex(a => a.id === id);
  if (idx >= 0) accountList.splice(idx, 1);
}

function getAllAccounts() {
  return accountList.map(a => ({
    id: a.id,
    phone: a.config.phone,
    connected: a.connected,
    uptime: Date.now() - a.createdAt,
    warmup: warmup.getStatus(a.id),
    qr_buffer: a.qrBuffer ? true : false
  }));
}

function getAccountById(id) {
  return accounts[id] || null;
}

module.exports = { createAccount, getAccount, getConnectedAccounts, getLeastLoadedAccount, removeAccount, getAllAccounts, setIncomingHandler, getAccountById };
