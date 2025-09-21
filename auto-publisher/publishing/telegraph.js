const puppeteer = require('puppeteer');
const fetch = require('node-fetch');

async function generateTextWithChat(prompt, openaiApiKey) {
  const response = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${openaiApiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: "gpt-3.5-turbo",
      messages: [{ role: "user", content: prompt }]
    })
  });

  if (!response.ok) {
    console.error(`Error making request: ${response.statusText}`);
    return 'Error: Failed to generate content';
  }

  const data = await response.json();
  return data.choices[0].message.content.trim();
}

async function publishToTelegraph(pageUrl, anchorText, language, openaiApiKey) {
    const prompts = {
        title: `What would be a good title for an article about this link without using quotes? ${pageUrl}`,
        author: `What is a suitable author's name for an article in ${language}? Avoid using region-specific names.`,
        content: `Please write a text in ${language} with at least 3000 characters based on the following link: ${pageUrl}. The article must include the anchor text "${anchorText}" as part of a single active link in the format <a href="${pageUrl}">${anchorText}</a>. This link should be naturally integrated into the content, ideally in the first half of the article. The content should be informative, cover the topic comprehensively, and include headings. Use <h2></h2> tags for subheadings. Please ensure the article contains only this one link and focuses on integrating the anchor text naturally within the content’s flow.`
    };

	const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

	// Генерация заголовка
	const title = await generateTextWithChat(prompts.title, openaiApiKey);
	await sleep(5000); // Пауза в 5 секунд

	// Генерация имени автора
	const author = await generateTextWithChat(prompts.author, openaiApiKey);
	await sleep(5000); // Пауза в 5 секунд

	// Генерация содержания статьи
	const content = await generateTextWithChat(prompts.content, openaiApiKey);


    const cleanTitle = title.replace(/['"]+/g, '');

    const browser = await puppeteer.launch();
    const page = await browser.newPage();
    await page.goto('https://telegra.ph/', {waitUntil: 'networkidle2'});

    await page.waitForSelector('h1[data-placeholder="Title"]');
    await page.click('h1[data-placeholder="Title"]');
    await page.keyboard.type(cleanTitle);

    await page.waitForSelector('address[data-placeholder="Your name"]');
    await page.click('address[data-placeholder="Your name"]');
    await page.keyboard.type(author);

    await page.evaluate((content) => {
        const articleContentElement = document.querySelector('p[data-placeholder="Your story..."]');
        articleContentElement.innerHTML = content;
    }, content);

    await Promise.all([
        page.waitForNavigation({waitUntil: 'networkidle2'}),
        page.click('button.publish_button')
    ]);

    const url = page.url();
    await browser.close();
    return {publishedUrl: url, title: cleanTitle, author, network: 'telegraph'};
}

module.exports = {
	publish: publishToTelegraph
};


