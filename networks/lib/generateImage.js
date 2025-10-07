const https = require('https');
const http = require('http');

const SPACE_URL = 'https://stabilityai-stable-diffusion.hf.space';
const API = 'infer';

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
                                        resolve(imgUrl);
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