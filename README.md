# PromoPilot

Автоматизация публикаций (например, в Telegraph) с генерацией контента через OpenAI и управлением из веб‑админки.

## Требования
- PHP 8.x + MySQL/MariaDB, расширения: mysqli, curl, zip (желательно), json
- Node.js >= 18 (рекомендовано 20+), npm
- Исходящие HTTPS соединения (для OpenAI и загрузки Chrome for Testing)

## Быстрый старт (локально)
1) Клонировать репозиторий и настроить БД (installer.php поможет создать config/config.php).
2) Установить Node‑зависимости:
   - `npm install`
3) Запустить из веба index.php и настроить проект в админке.

## Установка на хостинге (команды в корне проекта)
Ниже команды выполнять в каталоге проекта (корень):

1) Перейти в каталог проекта
```
cd /home/USER/DOMAIN/promopilot
```

2) Установить зависимости Node.js
```
rm -rf node_modules package-lock.json
npm install
```

3) Загрузить Chrome for Testing в проект (node_runtime)
Вариант А (предпочтительный; если доступен npm):
```
export PUPPETEER_CACHE_DIR="$PWD/node_runtime"
export PUPPETEER_PRODUCT=chrome
npm exec puppeteer browsers install chrome
```
Вариант Б (если есть npx):
```
export PUPPETEER_CACHE_DIR="$PWD/node_runtime"
export PUPPETEER_PRODUCT=chrome
npx --yes puppeteer browsers install chrome
```
Вариант В (без npm/npx, только Node):
```
export PUPPETEER_CACHE_DIR="$PWD/node_runtime"
node --input-type=module -e "import('@puppeteer/browsers').then(m=>m.install({browser:m.Browser.CHROME, cacheDir: process.env.PUPPETEER_CACHE_DIR||'node_runtime', buildId:'stable'})).then(r=>console.log(r)).catch(e=>{console.error(e);process.exit(1);})"
```

4) Найти путь к бинарю Chrome (для справки)
```
find "$PWD/node_runtime" -type f -name chrome -perm -u+x 2>/dev/null | head -n1
```

## Настройка в админке
- Админка → Сети:
  - Путь до Node.js: укажите бинарь при необходимости (или оставьте пустым для системного node)
  - Путь до Chrome/Chromium: укажите найденный путь, например:
    `/home/USER/DOMAIN/promopilot/node_runtime/chrome/linux-140.0.7339.207/chrome-linux64/chrome`
  - Доп. аргументы: `--no-sandbox --disable-setuid-sandbox`
- Админка → Диагностика:
  - Есть кнопка «Автоопределение Chrome». Нажмите, чтобы автоматически найти и сохранить путь.

## Обновление до новой версии
```
git fetch --tags
git checkout v1.2.31   # либо main
npm install
```
Если после обновления Puppeteer не запускается — повторите шаг установки Chrome (см. выше).

## Диагностика и логирование
- Логи выполнения Node‑скриптов: папка `logs/`, файл начинается с `telegraph-...`. 
- В логах видны версии Puppeteer/Node и путь к Chrome.

## Частые проблемы
– BYOA (собственное API) не отвечает/зависает:
  - На сервере в корне проекта выполните установку клиента Gradio:
    
    npm i @gradio/client
    
  - Затем перезапустите публикацию. В логах `logs/network-telegraph-*.log` должна появиться строка `BYOA:pkg_ok`.
- Chrome executable not found:
  - Не найден бинарь Chrome. Установите Chrome for Testing в `node_runtime` и/или укажите путь в админке.
- npx: command not found:
  - Используйте `npm exec ...` (вариант А) или вариант В c `@puppeteer/browsers` через Node.
- PUPPETEER_VERSION_UNSUPPORTED / CdpWebWorker export mismatch:
  - Обновите зависимости: `npm install` (в проекте указаны совместимые версии puppeteer/puppeteer-core 24.x).
- Permission denied при запуске Chrome:
  - Проверьте права: `chmod +x` на бинарь Chrome и доступность каталога `node_runtime`.

## Лицензия
MIT (при необходимости уточните в проекте).
