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

async function registerAndPublishToSite123(pageUrl, anchorText, language, openaiApiKey) {
  const browser = await puppeteer.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto('https://app.site123.com/manager/login/sign_up.php', { waitUntil: 'networkidle2' });
  await page.waitForSelector('div[data-cat-name="Blog"]', {visible: true});
  const blogButton = await page.$('div[data-cat-name="Blog"]');
  if (blogButton) {
    await blogButton.click();
  } else {
    console.log("Blog category button not found.");
    await browser.close();
    return;
  }

  await page.waitForSelector('input#websiteName', {visible: true});
  await page.type('input#websiteName', 'My Unique Blog Name');
  await page.click('button.moveToStep3');

  await page.waitForSelector('input#name', {visible: true});
  await page.type('input#name', 'John Doe');
  await page.type('input#email', `john.doe${Date.now()}@example.com`);
  await page.type('input#password', 'strongpassword123');
  await page.click('input#agree');

  await page.click('button[type="submit"]');

  const title = await generateTextWithChat(`Generate a title for an article about this link: ${pageUrl}`, openaiApiKey);
  const content = await generateTextWithChat(`Create content in ${language} based on this link: ${pageUrl}. Include the anchor text "${anchorText}" as a link.`, openaiApiKey);

  await page.waitForNavigation({ waitUntil: 'networkidle2' });
  await page.type('.m-h-item header2', title);
  await page.type('.m-h-item header2 + div', content);

  await page.waitForSelector('button[type="submit"]', {visible: true});
  await page.click('button[type="submit"]');

  const url = await page.evaluate(() => location.href);
  await browser.close();
  return { publishedUrl: url, title, author: 'John Doe', network: 'Site123' };
}

module.exports = {
  publish: registerAndPublishToSite123
};
