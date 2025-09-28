'use strict';

const fs = require('fs');
const path = require('path');

function ensureDirSync(dir) {
  try {
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
  } catch (_) {
    // ignore
  }
}

function safeStringify(obj) {
  try {
    return JSON.stringify(obj);
  } catch (_) {
    return String(obj);
  }
}

function createLogger(slug) {
  const now = new Date();
  const LOG_DIR = process.env.PP_LOG_DIR || path.join(process.cwd(), 'logs');
  ensureDirSync(LOG_DIR);
  const LOG_FILE = process.env.PP_LOG_FILE || path.join(
    LOG_DIR,
    `network-${slug}-${now.toISOString().replace(/[:.]/g, '-')}-${process.pid}.log`
  );

  const logLine = (msg, data) => {
    const line = `[${new Date().toISOString()}] ${msg}${data ? ' ' + safeStringify(data) : ''}\n`;
    try {
      fs.appendFileSync(LOG_FILE, line);
    } catch (_) {
      // ignore
    }
  };

  const verbose = /^(1|true|yes)$/i.test(String(process.env.PP_VERBOSE || '0'));
  const logDebug = verbose ? logLine : () => {};

  return { LOG_DIR, LOG_FILE, logLine, logDebug };
}

module.exports = { createLogger };
