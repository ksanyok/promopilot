require('dotenv').config();
const fs = require('fs');
const path = require('path');

const pageUrl = process.argv[2];
const anchorText = process.argv[3];
const language = process.argv[4];
const openaiApiKey = process.argv[5];

async function run() {
    const networks = ['bloger']; // Добавлен 'site123' в список сетей
    const results = [];

    for (let i = 0; i < 3; i++) { // Публикация трех статей поочередно
        const selectedNetwork = networks[Math.floor(Math.random() * networks.length)];
        const networkScriptPath = path.join(__dirname, 'publishing', `${selectedNetwork}.js`);
        
        if (!fs.existsSync(networkScriptPath)) {
            console.error(`Network script not found for ${selectedNetwork}`);
            continue; // Пропускаем текущую итерацию и переходим к следующей
        }

        const networkScript = require(networkScriptPath);
        try {
            const publishedData = await networkScript.publish(pageUrl, anchorText, language, openaiApiKey);
            const { publishedUrl, title, author, network } = publishedData;
            results.push({ publishedUrl, title, author, network });
            console.log(JSON.stringify({ publishedUrl, title, author, network }));
        } catch (error) {
            console.error(`Ошибка при публикации в сети ${selectedNetwork}: ${error.message}`);
        }
    }
}

run().catch(error => console.error(`Ошибка: ${error.message}`));


/* require('dotenv').config();
const fs = require('fs');
const path = require('path');

const pageUrl = process.argv[2];
const anchorText = process.argv[3];
const language = process.argv[4];
const openaiApiKey = process.argv[5];

async function run() {
    const networks = ['wordpress']; // Теперь сценарий включает обе сети
    const results = [];

    for (let i = 0; i < 3; i++) { // Публикация трех статей поочередно
        const selectedNetwork = networks[Math.floor(Math.random() * networks.length)];
        const networkScriptPath = path.join(__dirname, 'publishing', `${selectedNetwork}.js`);
        
        if (!fs.existsSync(networkScriptPath)) {
            console.error(`Network script not found for ${selectedNetwork}`);
            continue; // Пропускаем текущую итерацию и переходим к следующей
        }

        try {
            const networkScript = require(networkScriptPath);
            const publishedData = await networkScript.publish(pageUrl, anchorText, language, openaiApiKey);
            
            const { publishedUrl, title, author, network } = publishedData;
            results.push({ publishedUrl, title, author, network });
            
            console.log(JSON.stringify({ publishedUrl, title: title || "Не указано", author: author || "Анонимный автор", network }));
        } catch (error) {
            console.error(`Ошибка при публикации в сети ${selectedNetwork}: ${error.message}`);
        }
    }
}

run().catch(error => console.error(`Ошибка: ${error.message}`)); */

/* require('dotenv').config();
const fs = require('fs');
const path = require('path');

const pageUrl = process.argv[2];
const anchorText = process.argv[3];
const language = process.argv[4];
const openaiApiKey = process.argv[5];

async function run() {
    const networks = ['telegraph']; // Пока что только одна сеть, но скрипт готов к расширению
    const results = [];

    for (let i = 0; i < 3; i++) { // Публикация трех статей поочередно
        const selectedNetwork = networks[Math.floor(Math.random() * networks.length)];
        const networkScriptPath = path.join(__dirname, 'publishing', `${selectedNetwork}.js`);
        
        if (!fs.existsSync(networkScriptPath)) {
            console.error(`Network script not found for ${selectedNetwork}`);
            continue; // Пропускаем текущую итерацию и переходим к следующей
        }

        try {
            const networkScript = require(networkScriptPath);
            const publishedData = await networkScript.publish(pageUrl, anchorText, language, openaiApiKey);
            
            const { publishedUrl, title, author, network } = publishedData;
            results.push({ publishedUrl, title, author, network });
            
            console.log(JSON.stringify({ publishedUrl, title: title || "Не указано", author: author || "Анонимный автор", network }));
        } catch (error) {
            console.error(`Ошибка при публикации в сети ${selectedNetwork}: ${error.message}`);
        }
    }
}

run().catch(error => console.error(`Ошибка: ${error.message}`));


 */