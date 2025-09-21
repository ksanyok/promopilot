require('dotenv').config();
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const pageUrl = process.argv[2];
const anchorText = process.argv[3];
const language = process.argv[4];
const openaiApiKey = process.argv[5] || process.env.OPENAI_API_KEY;

// Optional CLI/env configuration
const networksArg = process.argv[6] || process.env.NETWORKS; // e.g. "telegraph,wordpress,bloger"
const iterationsArg = process.argv[7] || process.env.COUNT; // e.g. "3"
const interactive = process.argv.includes('--interactive') ||
  String(process.env.INTERACTIVE || '').toLowerCase() === 'true' ||
  process.env.INTERACTIVE === '1';

const networks = (networksArg ? networksArg.split(',').map(s => s.trim()).filter(Boolean) : ['telegraph']);
const iterations = Math.max(1, parseInt(iterationsArg || '1', 10));

if (!pageUrl || !anchorText || !language || !openaiApiKey) {
    console.error('Usage: node publish.js <pageUrl> <anchorText> <language> [openaiApiKey or set OPENAI_API_KEY] [networks(csv)] [count] [--interactive]');
    process.exit(1);
}

const sleep = (ms) => new Promise(res => setTimeout(res, ms));

function askYesNo(question) {
  return new Promise(resolve => {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    rl.question(`${question} (y/N): `, answer => {
      rl.close();
      const a = String(answer || '').trim().toLowerCase();
      resolve(a === 'y' || a === 'yes');
    });
  });
}

async function run() {
    const results = [];

    for (let i = 0; i < iterations; i++) { // configurable number of iterations
        const selectedNetwork = networks[Math.floor(Math.random() * networks.length)];
        const networkScriptPath = path.join(__dirname, 'publishing', `${selectedNetwork}.js`);
        
        if (!fs.existsSync(networkScriptPath)) {
            console.error(`Network script not found for ${selectedNetwork}`);
            continue; // Skip if script missing
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

        // small delay to be polite to targets and rate limits
        await sleep(1500);

        // optionally ask to continue
        if (interactive && i < iterations - 1) {
          const cont = await askYesNo('Continue to iterate?');
          if (!cont) break;
        }
    }
}

run().catch(error => console.error(`Ошибка: ${error.message}`));