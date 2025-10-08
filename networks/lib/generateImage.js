const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { URL } = require('url');
const sharp = require('sharp');

const SPACE_URL = 'https://stabilityai-stable-diffusion.hf.space';
const API = 'infer';
const TARGET_WIDTH = Number(process.env.PP_IMAGE_WIDTH || 1280);
const TARGET_HEIGHT = Number(process.env.PP_IMAGE_HEIGHT || 720);
const fsp = fs.promises;

function ensureDirSync(dirPath) {
    try {
        fs.mkdirSync(dirPath, { recursive: true, mode: 0o775 });
    } catch (_) {
        // ignore
    }
}

function sanitizeFilename(name) {
    return name.replace(/[^a-zA-Z0-9_\-.]+/g, '-');
}

function pickExtensionFromUrl(remoteUrl) {
    try {
        const parsed = new URL(remoteUrl);
        const ext = path.extname(parsed.pathname || '').toLowerCase();
        if (ext && ext.length <= 5) {
            return ext;
        }
    } catch (_) {
        // ignore
    }
    return '.png';
}

function normalizeExtension(ext, fallback = '.png') {
    if (!ext) {
        return fallback;
    }
    const cleaned = String(ext).trim();
    if (!cleaned) {
        return fallback;
    }
    if (cleaned.startsWith('.')) {
        return cleaned.length > 1 ? cleaned : fallback;
    }
    return `.${cleaned}`;
}

function writeFileAsync(filePath, buffer) {
    return new Promise((resolve, reject) => {
        fs.writeFile(filePath, buffer, { mode: 0o664 }, (err) => {
            if (err) {
                reject(err);
            } else {
                resolve();
            }
        });
    });
}

async function enforceAspectRatio(filePath, targetWidth = TARGET_WIDTH, targetHeight = TARGET_HEIGHT) {
    if (!filePath) {
        return false;
    }
    const width = Number(targetWidth) || 1280;
    const height = Number(targetHeight) || 720;
    const tmpPath = `${filePath}.tmp`;
    try {
        await sharp(filePath)
            .resize(width, height, {
                fit: 'cover',
                position: 'centre',
            })
            .toFile(tmpPath);
        await fsp.rename(tmpPath, filePath);
        return true;
    } catch (err) {
        try { await fsp.unlink(tmpPath); } catch (_) {}
        return false;
    }
}

function buildStorageTarget(extensionHint = '.png') {
    const now = new Date();
    const year = String(now.getUTCFullYear());
    const month = String(now.getUTCMonth() + 1).padStart(2, '0');
    const relativeSegments = ['uploads', 'generated-images', year, month];
    const relativeDirFs = path.join(...relativeSegments);
    const targetDir = path.join(process.cwd(), relativeDirFs);
    ensureDirSync(targetDir);

    const ext = normalizeExtension(extensionHint || '.png');
    const fileName = sanitizeFilename(`${Date.now()}-${crypto.randomBytes(4).toString('hex')}${ext}`);
    const destPath = path.join(targetDir, fileName);

    const publicPath = [...relativeSegments, fileName].join('/');
    const baseUrlRaw = process.env.PP_BASE_URL ? String(process.env.PP_BASE_URL).trim() : '';
    const validBase = baseUrlRaw && /^https?:\/\//i.test(baseUrlRaw) ? baseUrlRaw.replace(/\/+$/, '') : '';
    const absoluteUrl = validBase ? `${validBase}/${publicPath}` : '';
    const relativeUrl = `/${publicPath}`;

    return { destPath, publicPath, absoluteUrl, relativeUrl };
}

function downloadFile(remoteUrl, destPath) {
    return new Promise((resolve, reject) => {
        const handler = remoteUrl.startsWith('https:') ? https : http;
        handler.get(remoteUrl, (res) => {
            if (res.statusCode && res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
                const redirectUrl = res.headers.location.startsWith('http')
                    ? res.headers.location
                    : new URL(res.headers.location, remoteUrl).toString();
                res.resume();
                downloadFile(redirectUrl, destPath).then(resolve).catch(reject);
                return;
            }
            if (!res.statusCode || res.statusCode !== 200) {
                res.resume();
                reject(new Error(`DOWNLOAD_FAILED_${res.statusCode || 'UNKNOWN'}`));
                return;
            }
            const fileStream = fs.createWriteStream(destPath);
            res.pipe(fileStream);
            fileStream.on('finish', () => {
                fileStream.close(resolve);
            });
            fileStream.on('error', (err) => {
                try { fs.unlinkSync(destPath); } catch (_) {}
                reject(err);
            });
        }).on('error', (err) => {
            reject(err);
        });
    });
}

async function storeImageLocally(remoteUrl) {
    const sourceUrl = String(remoteUrl || '').trim();
    if (!sourceUrl) {
        return { publicUrl: '', filePath: null, relativePath: null, relativeUrl: null, saved: false };
    }
    const target = buildStorageTarget(pickExtensionFromUrl(sourceUrl));
    try {
        await downloadFile(sourceUrl, target.destPath);
        try { await enforceAspectRatio(target.destPath); } catch (_) {}
        const isRemoteSource = /^https?:\/\//i.test(sourceUrl);
        const publicUrl = target.absoluteUrl || (isRemoteSource ? sourceUrl : target.relativeUrl);
        return {
            publicUrl,
            filePath: target.destPath,
            relativePath: target.publicPath,
            relativeUrl: target.relativeUrl,
            saved: true,
            fallbackUrl: sourceUrl,
        };
    } catch (err) {
        try { fs.unlinkSync(target.destPath); } catch (_) {}
        return {
            publicUrl: sourceUrl,
            filePath: null,
            relativePath: null,
            relativeUrl: null,
            saved: false,
            error: err,
        };
    }
}

async function storeBase64Image(dataUri) {
    const match = /^data:image\/([a-z0-9+.-]+);base64,(.+)$/i.exec(String(dataUri || '').trim());
    if (!match) {
        throw new Error('INVALID_DATA_URI');
    }
    const mime = match[1].toLowerCase();
    const base64Data = match[2];
    let extension = '.png';
    if (mime === 'jpeg' || mime === 'jpg') extension = '.jpg';
    else if (mime === 'png') extension = '.png';
    else if (mime === 'webp') extension = '.webp';
    else if (mime === 'gif') extension = '.gif';
    else {
        const sanitized = mime.replace(/[^a-z0-9]/gi, '');
        extension = sanitized ? normalizeExtension(sanitized) : '.png';
        if (!/^\.(png|jpg|jpeg|webp|gif)$/i.test(extension)) {
            extension = '.png';
        }
    }

    const buffer = Buffer.from(base64Data, 'base64');
    const target = buildStorageTarget(extension);
    try {
        await writeFileAsync(target.destPath, buffer);
        try { await enforceAspectRatio(target.destPath); } catch (_) {}
        const publicUrl = target.absoluteUrl || target.relativeUrl;
        return {
            publicUrl,
            filePath: target.destPath,
            relativePath: target.publicPath,
            relativeUrl: target.relativeUrl,
            saved: true,
        };
    } catch (err) {
        try { fs.unlinkSync(target.destPath); } catch (_) {}
        throw err;
    }
}

function extractImageResource(payload) {
    if (!payload) {
        return null;
    }
    if (typeof payload === 'string') {
        const trimmed = payload.trim();
        if (!trimmed) {
            return null;
        }
        if (/^data:image\//i.test(trimmed)) {
            return { type: 'data', value: trimmed };
        }
        if (/^https?:\/\//i.test(trimmed)) {
            return { type: 'url', value: trimmed };
        }
        return null;
    }
    if (Array.isArray(payload)) {
        for (const item of payload) {
            const found = extractImageResource(item);
            if (found) {
                return found;
            }
        }
        return null;
    }
    if (typeof payload === 'object') {
        if (payload.url && typeof payload.url === 'string') {
            const found = extractImageResource(payload.url);
            if (found) {
                return found;
            }
        }
        if (payload.image) {
            const found = extractImageResource(payload.image);
            if (found) {
                return found;
            }
        }
        if (payload.path && typeof payload.path === 'string') {
            return { type: 'url', value: `${SPACE_URL}/file=${payload.path}` };
        }
        if (payload.data) {
            const found = extractImageResource(payload.data);
            if (found) {
                return found;
            }
        }
        if (payload.output) {
            const found = extractImageResource(payload.output);
            if (found) {
                return found;
            }
        }
        if (payload.result) {
            const found = extractImageResource(payload.result);
            if (found) {
                return found;
            }
        }
        if (payload.completion) {
            const found = extractImageResource(payload.completion);
            if (found) {
                return found;
            }
        }
    }
    return null;
}

async function materializeImageResource(resource) {
    if (!resource) {
        throw new Error('IMAGE_RESOURCE_NOT_FOUND');
    }
    if (resource.type === 'url') {
        const stored = await storeImageLocally(resource.value);
        return stored.publicUrl || stored.relativeUrl || resource.value;
    }
    if (resource.type === 'data') {
        try {
            const stored = await storeBase64Image(resource.value);
            return stored.publicUrl || (stored.relativeUrl || resource.value);
        } catch (_) {
            return resource.value;
        }
    }
    throw new Error('UNSUPPORTED_IMAGE_RESOURCE');
}

function requestQueueTicket(payload) {
    const url = `${SPACE_URL}/call/${API}`;
    return new Promise((resolve, reject) => {
        const req = https.request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(payload)
            }
        }, (res) => {
            let data = '';
            res.on('data', (chunk) => data += chunk);
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    if (json && json.event_id) {
                        resolve(json.event_id);
                    } else {
                        reject(new Error('NO_EVENT_ID'));
                    }
                } catch (err) {
                    reject(err);
                }
            });
        });
        req.on('error', (err) => {
            try { req.destroy(); } catch (_) {}
            reject(err);
        });
        req.write(payload);
        req.end();
    });
}

function waitForQueueResult(eventId) {
    return new Promise((resolve, reject) => {
        const url = `${SPACE_URL}/call/${API}/${eventId}`;
        const req = https.request(url, {
            headers: { Accept: 'text/event-stream' },
        }, (res) => {
            res.setEncoding('utf8');
            let buffer = '';
            let eventType = '';
            let lastPayload = null;
            const finish = (result) => {
                try { req.destroy(); } catch (_) {}
                resolve(result);
            };
            const fail = (err) => {
                try { req.destroy(); } catch (_) {}
                reject(err);
            };
            res.on('data', (chunk) => {
                buffer += chunk;
                const lines = buffer.split('\n');
                buffer = lines.pop();
                for (const rawLine of lines) {
                    const line = rawLine.trim();
                    if (line.startsWith('event:')) {
                        eventType = line.slice(6).trim();
                    } else if (line.startsWith('data:')) {
                        const payload = line.slice(5).trim();
                        if (payload === '[DONE]') {
                            continue;
                        }
                        try {
                            const parsed = JSON.parse(payload);
                            lastPayload = parsed;
                            const resource = extractImageResource(parsed);
                            if (resource) {
                                materializeImageResource(resource).then(finish).catch(fail);
                                return;
                            }
                        } catch (_) {
                            // ignore malformed JSON chunks
                        }
                        if (eventType === 'complete' && lastPayload) {
                            const resource = extractImageResource(lastPayload);
                            if (resource) {
                                materializeImageResource(resource).then(finish).catch(fail);
                                return;
                            }
                        }
                    }
                }
            });
            res.on('end', () => {
                if (lastPayload) {
                    const resource = extractImageResource(lastPayload);
                    if (resource) {
                        materializeImageResource(resource).then(finish).catch(fail);
                        return;
                    }
                }
                fail(new Error('NO_IMAGE_DATA'));
            });
        });
        req.on('error', reject);
        req.end();
    });
}

function directPredict(payload) {
    const url = `${SPACE_URL}/run/predict`;
    return new Promise((resolve, reject) => {
        const req = https.request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(payload)
            }
        }, (res) => {
            let data = '';
            res.on('data', (chunk) => data += chunk);
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    const resource = extractImageResource(json);
                    if (!resource) {
                        reject(new Error('NO_IMAGE_IN_DIRECT_RESPONSE'));
                        return;
                    }
                    materializeImageResource(resource)
                        .then((result) => { try { req.destroy(); } catch (_) {} resolve(result); })
                        .catch((err) => { try { req.destroy(); } catch (_) {} reject(err); });
                } catch (err) {
                    try { req.destroy(); } catch (_) {}
                    reject(err);
                }
            });
        });
        req.on('error', (err) => {
            try { req.destroy(); } catch (_) {}
            reject(err);
        });
        req.write(payload);
        req.end();
    });
}

async function generateImage(prompt) {
    const cleanPrompt = String(prompt || '').trim();
    if (!cleanPrompt) {
        throw new Error('EMPTY_PROMPT');
    }
    const scale = 9;
    const negative = '';
    const payload = JSON.stringify({ data: [cleanPrompt, negative, scale] });
    try {
        const eventId = await requestQueueTicket(payload);
        return await waitForQueueResult(eventId);
    } catch (queueError) {
        try {
            return await directPredict(payload);
        } catch (directError) {
            const error = queueError instanceof Error ? queueError : new Error(String(queueError));
            error.details = {
                queue: queueError && queueError.message ? queueError.message : queueError,
                fallback: directError && directError.message ? directError.message : directError,
            };
            throw error;
        }
    }
}

module.exports = { generateImage };
