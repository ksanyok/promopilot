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
        throw new Error(`Error making request: ${response.statusText}`);
    }

    const data = await response.json();
    return data.choices[0].message.content.trim();
}

async function publishToBlogger(pageUrl, anchorText, language, openaiApiKey) {
    const prompts = {
        title: `What would be a good title for an article about this link without using quotes? ${pageUrl}`,
        author: `What is a suitable author's name for an article in ${language}?`,
        content: `Please write a text in ${language} with at least 3000 characters based on the following link: ${pageUrl}. Include the anchor text "${anchorText}" as a single active link.`
    };

    const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage();

    try {
        await page.goto('https://www.blogger.com/about/?bpli=1', { waitUntil: 'networkidle2' });
        await page.screenshot({path: '1_initial_page.png'});

        await page.waitForSelector('a.sign-in', { timeout: 30000 });
        await page.click('a.sign-in');
        await page.screenshot({path: '2_after_sign_in_click.png'});

        await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
        await page.screenshot({path: '3_after_navigation.png'});

        await page.waitForSelector('input[type="email"]', { timeout: 30000 });
        await page.screenshot({path: '4_email_input.png'});
        await page.type('input[type="email"]', 'promopilot57@gmail.com');
        await page.keyboard.press('Enter');

        await page.waitForSelector('input[type="password"]', { timeout: 30000 });
        await page.screenshot({path: '5_password_input.png'});
        await page.type('input[type="password"]', '123promopilot!');
        await page.keyboard.press('Enter');
		
		// После успешного ввода пароля и нажатия 'Enter'
		await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 });
		await page.screenshot({path: '6_after_login.png'});

/* 		// Попытка перейти на другой способ верификации
		try {
			await page.waitForSelector('text=Try another way', { timeout: 5000 }); // Ищем текст на странице
			await page.click('text=Try another way'); // Кликаем по элементу с этим текстом
			await page.screenshot({path: '7_try_another_way_clicked.png'}); // Сделаем скриншот после клика
		} catch (e) {
			console.log('Не удалось перейти на другой способ верификации или он не требуется.');
		} */

        console.log('Успешный вход в систему Blogger.');

        // Добавьте здесь код для создания и публикации статьи
    } catch (error) {
        console.error('Ошибка входа или публикации: ', error);
        await page.screenshot({path: 'error_screenshot.png'});
        throw error; // Переброс ошибки дальше
    } finally {
        await browser.close(); // Обязательно закрываем браузер после выполнения операций
    }
}

module.exports = {
    publish: publishToBlogger
};