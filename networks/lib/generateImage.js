const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { URL } = require('url');

const SPACE_URL = 'https://stabilityai-stable-diffusion.hf.space';
const API = 'infer';

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
    const baseUrlRaw = process.env.PP_BASE_URL ? String(process.env.PP_BASE_URL).trim() : '';
    if (!baseUrlRaw || !/^https?:\/\//i.test(baseUrlRaw)) {
        return { publicUrl: remoteUrl, filePath: null };
    }
    const baseUrl = baseUrlRaw.replace(/\/+$/, '');
    const now = new Date();
    const year = String(now.getUTCFullYear());
    const month = String(now.getUTCMonth() + 1).padStart(2, '0');
    const relativeDir = path.join('uploads', 'generated-images', year, month);
    const targetDir = path.join(process.cwd(), 'public', relativeDir);
    ensureDirSync(targetDir);
    const ext = pickExtensionFromUrl(remoteUrl);
    const fileName = sanitizeFilename(`${Date.now()}-${crypto.randomBytes(4).toString('hex')}${ext}`);
    const destPath = path.join(targetDir, fileName);
    try {
        await downloadFile(remoteUrl, destPath);
        const publicPath = `${relativeDir}/${fileName}`.replace(/\\/g, '/');
        const publicUrl = `${baseUrl}/${publicPath}`;
        return { publicUrl, filePath: destPath };
    } catch (err) {
        try { fs.unlinkSync(destPath); } catch (_) {}
        return { publicUrl: remoteUrl, filePath: null };
    }
}

async function generateImage(prompt) {
    const scale = 9;
    const negative = '';
    const payload = JSON.stringify({ data: [prompt, negative, scale] });
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
                    if (!json.event_id) {
                        reject(new Error('No event_id received'));
                        return;
                    }
                    const eventId = json.event_id;
                    // Wait a bit then get result
                    setTimeout(() => getResult(eventId).then(resolve).catch(reject), 10000);
                } catch (e) {
                    reject(e);
                }
            });
        });
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

function getResult(eventId) {
    return new Promise((resolve, reject) => {
        const url = `${SPACE_URL}/call/${API}/${eventId}`;
        const req = https.request(url, (res) => {
            let buffer = '';
            let eventType = '';
            res.on('data', (chunk) => {
                buffer += chunk.toString();
                const lines = buffer.split('\n');
                buffer = lines.pop();
                for (const line of lines) {
                    if (line.startsWith('event: ')) {
                        eventType = line.slice(7).trim();
                    } else if (line.startsWith('data: ')) {
                        const data = line.slice(6).trim();
                        if (data === '[DONE]') continue;
                        if (eventType === 'complete') {
                            try {
                                const json = JSON.parse(data);
                                if (json && Array.isArray(json) && json[0]) {
                                    let imgUrl = null;
                                    if (json[0].url) imgUrl = json[0].url;
                                    else if (json[0].image && json[0].image.url) imgUrl = json[0].image.url;
                                    else if (json[0].image && json[0].image.path) imgUrl = `${SPACE_URL}/file=${json[0].image.path}`;
                                    else if (json[0].path) imgUrl = `${SPACE_URL}/file=${json[0].path}`;
                                    if (imgUrl) {
                                        storeImageLocally(imgUrl)
                                            .then((result) => resolve(result.publicUrl))
                                            .catch(() => resolve(imgUrl));
                                        return;
                                    }
                                }
                            } catch (e) {
                                // Ignore
                            }
                        }
                    }
                }
            });
            res.on('end', () => {
                reject(new Error('No image URL found'));
            });
        });
        req.on('error', reject);
        req.end();
    });
}

module.exports = { generateImage };
