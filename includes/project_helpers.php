<?php

// Project-related helper functions extracted from client/project.php to reduce duplication

if (!function_exists('pp_project_fetch_with_user')) {
    /**
     * Загружает проект вместе с данными пользователя.
     */
    function pp_project_fetch_with_user(int $projectId): ?array
    {
    $linksCount = 0;
    $conn = connect_db();
        if (!$conn) {
            return null;
        }

        $sql = 'SELECT p.*, u.username, u.promotion_discount, u.balance FROM projects p JOIN users u ON p.user_id = u.id WHERE p.id = ?';
        $project = null;

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('i', $projectId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res && $res->num_rows > 0) {
                    $project = $res->fetch_assoc();
                }
                if ($res) {
                    $res->free();
                }
            }
            $stmt->close();
        }

        $conn->close();

        return $project ?: null;
    }
}

if (!function_exists('pp_project_fetch_links')) {
    /**
     * Возвращает ссылки проекта в виде унифицированного массива.
     */
    function pp_project_fetch_links(int $projectId, ?string $fallbackLanguage = null): array
    {
        $conn = connect_db();
        if (!$conn) {
            return [];
        }

        $links = [];
        $fallbackLanguage = $fallbackLanguage ?: 'ru';

        if ($stmt = $conn->prepare('SELECT id, url, anchor, language, wish, created_at, updated_at FROM project_links WHERE project_id = ? ORDER BY created_at DESC, id DESC')) {
            $stmt->bind_param('i', $projectId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $links[] = [
                            'id' => (int)($row['id'] ?? 0),
                            'url' => (string)($row['url'] ?? ''),
                            'anchor' => (string)($row['anchor'] ?? ''),
                            'language' => (string)($row['language'] ?? $fallbackLanguage),
                            'wish' => (string)($row['wish'] ?? ''),
                            'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : null,
                            'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : null,
                        ];
                    }
                    $res->free();
                }
            }
            $stmt->close();
        }

        if (!empty($links)) {
            $duplicateCounts = [];
            foreach ($links as $entry) {
                $urlKey = pp_project_normalize_link_key($entry['url'] ?? '');
                if ($urlKey === '') {
                    continue;
                }
                if (!isset($duplicateCounts[$urlKey])) {
                    $duplicateCounts[$urlKey] = 0;
                }
                $duplicateCounts[$urlKey]++;
            }

            foreach ($links as $idx => $entry) {
                $urlKey = pp_project_normalize_link_key($entry['url'] ?? '');
                $duplicates = ($urlKey !== '' && isset($duplicateCounts[$urlKey])) ? (int)$duplicateCounts[$urlKey] : 1;
                $links[$idx]['duplicate_key'] = $urlKey;
                $links[$idx]['duplicates'] = max(1, $duplicates);
            }
        }

        $conn->close();

        return $links;
    }
}

if (!function_exists('pp_project_normalize_link_key')) {
    /**
     * Нормализует URL для подсчета дубликатов.
     */
    function pp_project_normalize_link_key(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return strtolower($url);
        }

        $host = isset($parts['host']) ? pp_normalize_host($parts['host']) : '';
        $path = isset($parts['path']) ? trim((string)$parts['path']) : '';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) ? trim((string)$parts['query']) : '';
        $fragment = isset($parts['fragment']) ? trim((string)$parts['fragment']) : '';

        $key = strtolower($host . $path);
        if ($query !== '') {
            $key .= '?' . strtolower($query);
        }
        if ($fragment !== '') {
            $key .= '#' . strtolower($fragment);
        }

        if ($key === '' && isset($parts['path'])) {
            $key = strtolower(trim((string)$parts['path']));
        }

        return $key !== '' ? $key : strtolower($url);
    }
}

if (!function_exists('pp_project_anchor_presets')) {
    /**
     * Набор предустановленных анкоров по языкам.
     * Возвращает массив вида ['lang' => [['id' => ..., 'label' => ..., 'type' => ..., 'value' => ...], ...]].
     */
    function pp_project_anchor_presets(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $set = [
            'ru' => [
                ['id' => 'ru_none', 'label' => 'Без анкора', 'type' => 'none', 'value' => '', 'description' => 'Оставим поле пустым — площадка будет использовать URL или сгенерированный текст.'],
                ['id' => 'ru_more', 'label' => 'Подробнее', 'type' => 'static', 'value' => 'Подробнее', 'default' => true],
                ['id' => 'ru_visit', 'label' => 'Перейти на сайт', 'type' => 'static', 'value' => 'Перейти на сайт'],
                ['id' => 'ru_read', 'label' => 'Читать материал', 'type' => 'static', 'value' => 'Читать материал'],
                ['id' => 'ru_domain', 'label' => 'Домен страницы', 'type' => 'domain', 'value' => '', 'description' => 'Используем адрес страницы вида example.com.'],
                ['id' => 'ru_project', 'label' => 'Название проекта', 'type' => 'project', 'value' => ''],
            ],
            'uk' => [
                ['id' => 'uk_none', 'label' => 'Без анкора', 'type' => 'none', 'value' => '', 'description' => 'Залишаємо посилання без тексту — платформа покаже адресу.'],
                ['id' => 'uk_more', 'label' => 'Докладніше', 'type' => 'static', 'value' => 'Докладніше', 'default' => true],
                ['id' => 'uk_visit', 'label' => 'Перейти на сайт', 'type' => 'static', 'value' => 'Перейти на сайт'],
                ['id' => 'uk_read', 'label' => 'Читати матеріал', 'type' => 'static', 'value' => 'Читати матеріал'],
                ['id' => 'uk_domain', 'label' => 'Домен сторінки', 'type' => 'domain', 'value' => ''],
                ['id' => 'uk_project', 'label' => 'Назва проєкту', 'type' => 'project', 'value' => ''],
            ],
            'en' => [
                ['id' => 'en_none', 'label' => 'No anchor', 'type' => 'none', 'value' => '', 'description' => 'Keep the anchor empty so the link stays non-branded.'],
                ['id' => 'en_more', 'label' => 'Learn more', 'type' => 'static', 'value' => 'Learn more', 'default' => true],
                ['id' => 'en_visit', 'label' => 'Visit site', 'type' => 'static', 'value' => 'Visit site'],
                ['id' => 'en_read', 'label' => 'Read article', 'type' => 'static', 'value' => 'Read article'],
                ['id' => 'en_domain', 'label' => 'Page domain', 'type' => 'domain', 'value' => ''],
                ['id' => 'en_project', 'label' => 'Project name', 'type' => 'project', 'value' => ''],
            ],
            'de' => [
                ['id' => 'de_none', 'label' => 'Ohne Anker', 'type' => 'none', 'value' => ''],
                ['id' => 'de_more', 'label' => 'Mehr erfahren', 'type' => 'static', 'value' => 'Mehr erfahren', 'default' => true],
                ['id' => 'de_visit', 'label' => 'Website besuchen', 'type' => 'static', 'value' => 'Website besuchen'],
                ['id' => 'de_read', 'label' => 'Artikel lesen', 'type' => 'static', 'value' => 'Artikel lesen'],
                ['id' => 'de_domain', 'label' => 'Domain der Seite', 'type' => 'domain', 'value' => ''],
                ['id' => 'de_project', 'label' => 'Projektname', 'type' => 'project', 'value' => ''],
            ],
            'fr' => [
                ['id' => 'fr_none', 'label' => 'Sans ancre', 'type' => 'none', 'value' => ''],
                ['id' => 'fr_more', 'label' => 'En savoir plus', 'type' => 'static', 'value' => 'En savoir plus', 'default' => true],
                ['id' => 'fr_visit', 'label' => 'Visiter le site', 'type' => 'static', 'value' => 'Visiter le site'],
                ['id' => 'fr_read', 'label' => 'Lire l’article', 'type' => 'static', 'value' => 'Lire l’article'],
                ['id' => 'fr_domain', 'label' => 'Domaine de la page', 'type' => 'domain', 'value' => ''],
                ['id' => 'fr_project', 'label' => 'Nom du projet', 'type' => 'project', 'value' => ''],
            ],
            'es' => [
                ['id' => 'es_none', 'label' => 'Sin ancla', 'type' => 'none', 'value' => ''],
                ['id' => 'es_more', 'label' => 'Más información', 'type' => 'static', 'value' => 'Más información', 'default' => true],
                ['id' => 'es_visit', 'label' => 'Visitar el sitio', 'type' => 'static', 'value' => 'Visitar el sitio'],
                ['id' => 'es_read', 'label' => 'Leer el artículo', 'type' => 'static', 'value' => 'Leer el artículo'],
                ['id' => 'es_domain', 'label' => 'Dominio de la página', 'type' => 'domain', 'value' => ''],
                ['id' => 'es_project', 'label' => 'Nombre del proyecto', 'type' => 'project', 'value' => ''],
            ],
            'it' => [
                ['id' => 'it_none', 'label' => 'Senza ancora', 'type' => 'none', 'value' => ''],
                ['id' => 'it_more', 'label' => 'Scopri di più', 'type' => 'static', 'value' => 'Scopri di più', 'default' => true],
                ['id' => 'it_visit', 'label' => 'Visita il sito', 'type' => 'static', 'value' => 'Visita il sito'],
                ['id' => 'it_read', 'label' => 'Leggi l’articolo', 'type' => 'static', 'value' => 'Leggi l’articolo'],
                ['id' => 'it_domain', 'label' => 'Dominio della pagina', 'type' => 'domain', 'value' => ''],
                ['id' => 'it_project', 'label' => 'Nome del progetto', 'type' => 'project', 'value' => ''],
            ],
            'pt' => [
                ['id' => 'pt_none', 'label' => 'Sem âncora', 'type' => 'none', 'value' => ''],
                ['id' => 'pt_more', 'label' => 'Saiba mais', 'type' => 'static', 'value' => 'Saiba mais', 'default' => true],
                ['id' => 'pt_visit', 'label' => 'Visitar o site', 'type' => 'static', 'value' => 'Visitar o site'],
                ['id' => 'pt_read', 'label' => 'Ler o artigo', 'type' => 'static', 'value' => 'Ler o artigo'],
                ['id' => 'pt_domain', 'label' => 'Domínio da página', 'type' => 'domain', 'value' => ''],
                ['id' => 'pt_project', 'label' => 'Nome do projeto', 'type' => 'project', 'value' => ''],
            ],
            'pt-br' => [
                ['id' => 'ptbr_none', 'label' => 'Sem âncora', 'type' => 'none', 'value' => ''],
                ['id' => 'ptbr_more', 'label' => 'Saiba mais', 'type' => 'static', 'value' => 'Saiba mais', 'default' => true],
                ['id' => 'ptbr_visit', 'label' => 'Visitar o site', 'type' => 'static', 'value' => 'Visitar o site'],
                ['id' => 'ptbr_read', 'label' => 'Ler o artigo', 'type' => 'static', 'value' => 'Ler o artigo'],
                ['id' => 'ptbr_domain', 'label' => 'Domínio da página', 'type' => 'domain', 'value' => ''],
                ['id' => 'ptbr_project', 'label' => 'Nome do projeto', 'type' => 'project', 'value' => ''],
            ],
            'pl' => [
                ['id' => 'pl_none', 'label' => 'Bez zakotwiczeń', 'type' => 'none', 'value' => ''],
                ['id' => 'pl_more', 'label' => 'Dowiedz się więcej', 'type' => 'static', 'value' => 'Dowiedz się więcej', 'default' => true],
                ['id' => 'pl_visit', 'label' => 'Odwiedź stronę', 'type' => 'static', 'value' => 'Odwiedź stronę'],
                ['id' => 'pl_read', 'label' => 'Przeczytaj artykuł', 'type' => 'static', 'value' => 'Przeczytaj artykuł'],
                ['id' => 'pl_domain', 'label' => 'Domena strony', 'type' => 'domain', 'value' => ''],
                ['id' => 'pl_project', 'label' => 'Nazwa projektu', 'type' => 'project', 'value' => ''],
            ],
            'tr' => [
                ['id' => 'tr_none', 'label' => 'Ankorsuz', 'type' => 'none', 'value' => ''],
                ['id' => 'tr_more', 'label' => 'Daha fazla bilgi', 'type' => 'static', 'value' => 'Daha fazla bilgi', 'default' => true],
                ['id' => 'tr_visit', 'label' => 'Siteyi ziyaret et', 'type' => 'static', 'value' => 'Siteyi ziyaret et'],
                ['id' => 'tr_read', 'label' => 'Makaleyi oku', 'type' => 'static', 'value' => 'Makaleyi oku'],
                ['id' => 'tr_domain', 'label' => 'Alan adı', 'type' => 'domain', 'value' => ''],
                ['id' => 'tr_project', 'label' => 'Proje adı', 'type' => 'project', 'value' => ''],
            ],
            'nl' => [
                ['id' => 'nl_none', 'label' => 'Zonder anchor', 'type' => 'none', 'value' => ''],
                ['id' => 'nl_more', 'label' => 'Meer lezen', 'type' => 'static', 'value' => 'Meer lezen', 'default' => true],
                ['id' => 'nl_visit', 'label' => 'Bezoek de site', 'type' => 'static', 'value' => 'Bezoek de site'],
                ['id' => 'nl_read', 'label' => 'Lees het artikel', 'type' => 'static', 'value' => 'Lees het artikel'],
                ['id' => 'nl_domain', 'label' => 'Domein van de pagina', 'type' => 'domain', 'value' => ''],
                ['id' => 'nl_project', 'label' => 'Projectnaam', 'type' => 'project', 'value' => ''],
            ],
            'cs' => [
                ['id' => 'cs_none', 'label' => 'Bez odkazu', 'type' => 'none', 'value' => ''],
                ['id' => 'cs_more', 'label' => 'Více informací', 'type' => 'static', 'value' => 'Více informací', 'default' => true],
                ['id' => 'cs_visit', 'label' => 'Navštívit web', 'type' => 'static', 'value' => 'Navštívit web'],
                ['id' => 'cs_read', 'label' => 'Přečíst článek', 'type' => 'static', 'value' => 'Přečíst článek'],
                ['id' => 'cs_domain', 'label' => 'Doména stránky', 'type' => 'domain', 'value' => ''],
                ['id' => 'cs_project', 'label' => 'Název projektu', 'type' => 'project', 'value' => ''],
            ],
            'sk' => [
                ['id' => 'sk_none', 'label' => 'Bez anchoru', 'type' => 'none', 'value' => ''],
                ['id' => 'sk_more', 'label' => 'Viac informácií', 'type' => 'static', 'value' => 'Viac informácií', 'default' => true],
                ['id' => 'sk_visit', 'label' => 'Navštíviť web', 'type' => 'static', 'value' => 'Navštíviť web'],
                ['id' => 'sk_read', 'label' => 'Prečítať článok', 'type' => 'static', 'value' => 'Prečítať článок'],
                ['id' => 'sk_domain', 'label' => 'Doména stránky', 'type' => 'domain', 'value' => ''],
                ['id' => 'sk_project', 'label' => 'Názov projektu', 'type' => 'project', 'value' => ''],
            ],
            'bg' => [
                ['id' => 'bg_none', 'label' => 'Без анкор', 'type' => 'none', 'value' => ''],
                ['id' => 'bg_more', 'label' => 'Научи повече', 'type' => 'static', 'value' => 'Научи повече', 'default' => true],
                ['id' => 'bg_visit', 'label' => 'Посети сайта', 'type' => 'static', 'value' => 'Посети сайта'],
                ['id' => 'bg_read', 'label' => 'Прочети статията', 'type' => 'static', 'value' => 'Прочети статията'],
                ['id' => 'bg_domain', 'label' => 'Домейн на страницата', 'type' => 'domain', 'value' => ''],
                ['id' => 'bg_project', 'label' => 'Име на проекта', 'type' => 'project', 'value' => ''],
            ],
            'ro' => [
                ['id' => 'ro_none', 'label' => 'Fără ancoră', 'type' => 'none', 'value' => ''],
                ['id' => 'ro_more', 'label' => 'Află mai multe', 'type' => 'static', 'value' => 'Află mai multe', 'default' => true],
                ['id' => 'ro_visit', 'label' => 'Vizitează site-ul', 'type' => 'static', 'value' => 'Vizitează site-ul'],
                ['id' => 'ro_read', 'label' => 'Citește articolul', 'type' => 'static', 'value' => 'Citește articolul'],
                ['id' => 'ro_domain', 'label' => 'Domeniul paginii', 'type' => 'domain', 'value' => ''],
                ['id' => 'ro_project', 'label' => 'Numele proiectului', 'type' => 'project', 'value' => ''],
            ],
            'el' => [
                ['id' => 'el_none', 'label' => 'Χωρίς άγκυρα', 'type' => 'none', 'value' => ''],
                ['id' => 'el_more', 'label' => 'Μάθε περισσότερα', 'type' => 'static', 'value' => 'Μάθε περισσότερα', 'default' => true],
                ['id' => 'el_visit', 'label' => 'Επίσκεψη στον ιστότοπο', 'type' => 'static', 'value' => 'Επίσκεψη στον ιστότοπο'],
                ['id' => 'el_read', 'label' => 'Διάβασε το άρθρο', 'type' => 'static', 'value' => 'Διάβασε το άρθρο'],
                ['id' => 'el_domain', 'label' => 'Domain σελίδας', 'type' => 'domain', 'value' => ''],
                ['id' => 'el_project', 'label' => 'Όνομα έργου', 'type' => 'project', 'value' => ''],
            ],
            'hu' => [
                ['id' => 'hu_none', 'label' => 'Anchor nélkül', 'type' => 'none', 'value' => ''],
                ['id' => 'hu_more', 'label' => 'Tudj meg többet', 'type' => 'static', 'value' => 'Tudj meg többet', 'default' => true],
                ['id' => 'hu_visit', 'label' => 'Látogasd meg a honlapot', 'type' => 'static', 'value' => 'Látogasd meg a honlapot'],
                ['id' => 'hu_read', 'label' => 'Olvasd el a cikket', 'type' => 'static', 'value' => 'Olvasd el a cikket'],
                ['id' => 'hu_domain', 'label' => 'Oldal domainje', 'type' => 'domain', 'value' => ''],
                ['id' => 'hu_project', 'label' => 'Projekt neve', 'type' => 'project', 'value' => ''],
            ],
            'sv' => [
                ['id' => 'sv_none', 'label' => 'Utan ankar', 'type' => 'none', 'value' => ''],
                ['id' => 'sv_more', 'label' => 'Läs mer', 'type' => 'static', 'value' => 'Läs mer', 'default' => true],
                ['id' => 'sv_visit', 'label' => 'Besök sajten', 'type' => 'static', 'value' => 'Besök sajten'],
                ['id' => 'sv_read', 'label' => 'Läs artikeln', 'type' => 'static', 'value' => 'Läs artikeln'],
                ['id' => 'sv_domain', 'label' => 'Sidans domän', 'type' => 'domain', 'value' => ''],
                ['id' => 'sv_project', 'label' => 'Projektnamn', 'type' => 'project', 'value' => ''],
            ],
            'da' => [
                ['id' => 'da_none', 'label' => 'Uden anker', 'type' => 'none', 'value' => ''],
                ['id' => 'da_more', 'label' => 'Læs mere', 'type' => 'static', 'value' => 'Læs mere', 'default' => true],
                ['id' => 'da_visit', 'label' => 'Besøg siden', 'type' => 'static', 'value' => 'Besøg siden'],
                ['id' => 'da_read', 'label' => 'Læs artiklen', 'type' => 'static', 'value' => 'Læs artiklen'],
                ['id' => 'da_domain', 'label' => 'Sidens domæne', 'type' => 'domain', 'value' => ''],
                ['id' => 'da_project', 'label' => 'Projektnavn', 'type' => 'project', 'value' => ''],
            ],
            'no' => [
                ['id' => 'no_none', 'label' => 'Uten anker', 'type' => 'none', 'value' => ''],
                ['id' => 'no_more', 'label' => 'Les mer', 'type' => 'static', 'value' => 'Les mer', 'default' => true],
                ['id' => 'no_visit', 'label' => 'Besøk siden', 'type' => 'static', 'value' => 'Besøk siden'],
                ['id' => 'no_read', 'label' => 'Les artikkelen', 'type' => 'static', 'value' => 'Les artikkelen'],
                ['id' => 'no_domain', 'label' => 'Sidens domene', 'type' => 'domain', 'value' => ''],
                ['id' => 'no_project', 'label' => 'Prosjektnavn', 'type' => 'project', 'value' => ''],
            ],
            'fi' => [
                ['id' => 'fi_none', 'label' => 'Ilman ankkuria', 'type' => 'none', 'value' => ''],
                ['id' => 'fi_more', 'label' => 'Lue lisää', 'type' => 'static', 'value' => 'Lue lisää', 'default' => true],
                ['id' => 'fi_visit', 'label' => 'Vieraile sivulla', 'type' => 'static', 'value' => 'Vieraile sivulla'],
                ['id' => 'fi_read', 'label' => 'Lue artikkeli', 'type' => 'static', 'value' => 'Lue artikkeli'],
                ['id' => 'fi_domain', 'label' => 'Sivun domain', 'type' => 'domain', 'value' => ''],
                ['id' => 'fi_project', 'label' => 'Projektin nimi', 'type' => 'project', 'value' => ''],
            ],
            'et' => [
                ['id' => 'et_none', 'label' => 'Ilma ankruta', 'type' => 'none', 'value' => ''],
                ['id' => 'et_more', 'label' => 'Loe lähemalt', 'type' => 'static', 'value' => 'Loe lähemalt', 'default' => true],
                ['id' => 'et_visit', 'label' => 'Külasta saiti', 'type' => 'static', 'value' => 'Külasta saiti'],
                ['id' => 'et_read', 'label' => 'Loe artiklit', 'type' => 'static', 'value' => 'Loe artiklit'],
                ['id' => 'et_domain', 'label' => 'Lehe domeen', 'type' => 'domain', 'value' => ''],
                ['id' => 'et_project', 'label' => 'Projekti nimi', 'type' => 'project', 'value' => ''],
            ],
            'lv' => [
                ['id' => 'lv_none', 'label' => 'Bez enkura', 'type' => 'none', 'value' => ''],
                ['id' => 'lv_more', 'label' => 'Uzziniet vairāk', 'type' => 'static', 'value' => 'Uzziniet vairāk', 'default' => true],
                ['id' => 'lv_visit', 'label' => 'Apmeklējiet vietni', 'type' => 'static', 'value' => 'Apmeklējiet vietni'],
                ['id' => 'lv_read', 'label' => 'Izlasiet rakstu', 'type' => 'static', 'value' => 'Izlasiet rakstu'],
                ['id' => 'lv_domain', 'label' => 'Lapas domēns', 'type' => 'domain', 'value' => ''],
                ['id' => 'lv_project', 'label' => 'Projekta nosaukums', 'type' => 'project', 'value' => ''],
            ],
            'lt' => [
                ['id' => 'lt_none', 'label' => 'Be ankerio', 'type' => 'none', 'value' => ''],
                ['id' => 'lt_more', 'label' => 'Sužinokite daugiau', 'type' => 'static', 'value' => 'Sužinokite daugiau', 'default' => true],
                ['id' => 'lt_visit', 'label' => 'Apsilankykite svetainėje', 'type' => 'static', 'value' => 'Apsilankykite svetainėje'],
                ['id' => 'lt_read', 'label' => 'Perskaitykite straipsnį', 'type' => 'static', 'value' => 'Perskaitykite straipsnį'],
                ['id' => 'lt_domain', 'label' => 'Puslapio domenas', 'type' => 'domain', 'value' => ''],
                ['id' => 'lt_project', 'label' => 'Projekto pavadinimas', 'type' => 'project', 'value' => ''],
            ],
            'ka' => [
                ['id' => 'ka_none', 'label' => 'ანკორის გარეშე', 'type' => 'none', 'value' => ''],
                ['id' => 'ka_more', 'label' => 'გაიგე მეტი', 'type' => 'static', 'value' => 'გაიგე მეტი', 'default' => true],
                ['id' => 'ka_visit', 'label' => 'ეწვიე საიტს', 'type' => 'static', 'value' => 'ეწვიე საიტს'],
                ['id' => 'ka_read', 'label' => 'წაიკითხე სტატია', 'type' => 'static', 'value' => 'წაიკითხე სტატია'],
                ['id' => 'ka_domain', 'label' => 'დൊმენი', 'type' => 'domain', 'value' => ''],
                ['id' => 'ka_project', 'label' => 'პროექტის სახელი', 'type' => 'project', 'value' => ''],
            ],
            'az' => [
                ['id' => 'az_none', 'label' => 'Ankorsuz', 'type' => 'none', 'value' => ''],
                ['id' => 'az_more', 'label' => 'Ətraflı məlumat', 'type' => 'static', 'value' => 'Ətraflı məlumat', 'default' => true],
                ['id' => 'az_visit', 'label' => 'Saytı ziyarət et', 'type' => 'static', 'value' => 'Saytı ziyarət et'],
                ['id' => 'az_read', 'label' => 'Məqaləni oxu', 'type' => 'static', 'value' => 'Məqaləni oxu'],
                ['id' => 'az_domain', 'label' => 'Səhifə domeni', 'type' => 'domain', 'value' => ''],
                ['id' => 'az_project', 'label' => 'Layihənin adı', 'type' => 'project', 'value' => ''],
            ],
            'kk' => [
                ['id' => 'kk_none', 'label' => 'Анкорсыз', 'type' => 'none', 'value' => ''],
                ['id' => 'kk_more', 'label' => 'Толығырақ', 'type' => 'static', 'value' => 'Толығырақ', 'default' => true],
                ['id' => 'kk_visit', 'label' => 'Сайтқа өту', 'type' => 'static', 'value' => 'Сайтқа өту'],
                ['id' => 'kk_read', 'label' => 'Мақаланы оқу', 'type' => 'static', 'value' => 'Мақаланы оқу'],
                ['id' => 'kk_domain', 'label' => 'Бет домені', 'type' => 'domain', 'value' => ''],
                ['id' => 'kk_project', 'label' => 'Жоба атауы', 'type' => 'project', 'value' => ''],
            ],
            'uz' => [
                ['id' => 'uz_none', 'label' => 'Ankorsiz', 'type' => 'none', 'value' => ''],
                ['id' => 'uz_more', 'label' => 'Batafsil maʼlumot', 'type' => 'static', 'value' => 'Batafsil maʼlumot', 'default' => true],
                ['id' => 'uz_visit', 'label' => 'Saytga o‘tish', 'type' => 'static', 'value' => 'Saytga o‘tish'],
                ['id' => 'uz_read', 'label' => 'Maqolani o‘qish', 'type' => 'static', 'value' => 'Maqolani o‘qish'],
                ['id' => 'uz_domain', 'label' => 'Sahifa domeni', 'type' => 'domain', 'value' => ''],
                ['id' => 'uz_project', 'label' => 'Loyiha nomi', 'type' => 'project', 'value' => ''],
            ],
            'sr' => [
                ['id' => 'sr_none', 'label' => 'Bez ankora', 'type' => 'none', 'value' => ''],
                ['id' => 'sr_more', 'label' => 'Saznaj više', 'type' => 'static', 'value' => 'Saznaj više', 'default' => true],
                ['id' => 'sr_visit', 'label' => 'Poseti sajt', 'type' => 'static', 'value' => 'Poseti sajt'],
                ['id' => 'sr_read', 'label' => 'Pročitaj članak', 'type' => 'static', 'value' => 'Pročitaj članak'],
                ['id' => 'sr_domain', 'label' => 'Domen stranice', 'type' => 'domain', 'value' => ''],
                ['id' => 'sr_project', 'label' => 'Naziv projekta', 'type' => 'project', 'value' => ''],
            ],
            'sl' => [
                ['id' => 'sl_none', 'label' => 'Brez sidra', 'type' => 'none', 'value' => ''],
                ['id' => 'sl_more', 'label' => 'Izvedi več', 'type' => 'static', 'value' => 'Izvedi več', 'default' => true],
                ['id' => 'sl_visit', 'label' => 'Obišči spletišče', 'type' => 'static', 'value' => 'Obišči spletišče'],
                ['id' => 'sl_read', 'label' => 'Preberi članek', 'type' => 'static', 'value' => 'Preberi članek'],
                ['id' => 'sl_domain', 'label' => 'Domena strani', 'type' => 'domain', 'value' => ''],
                ['id' => 'sl_project', 'label' => 'Ime projekta', 'type' => 'project', 'value' => ''],
            ],
            'hr' => [
                ['id' => 'hr_none', 'label' => 'Bez ankora', 'type' => 'none', 'value' => ''],
                ['id' => 'hr_more', 'label' => 'Saznaj više', 'type' => 'static', 'value' => 'Saznaj više', 'default' => true],
                ['id' => 'hr_visit', 'label' => 'Posjeti stranicu', 'type' => 'static', 'value' => 'Posjeti stranicu'],
                ['id' => 'hr_read', 'label' => 'Pročitaj članak', 'type' => 'static', 'value' => 'Pročitaj članak'],
                ['id' => 'hr_domain', 'label' => 'Domena stranice', 'type' => 'domain', 'value' => ''],
                ['id' => 'hr_project', 'label' => 'Ime projekta', 'type' => 'project', 'value' => ''],
            ],
            'he' => [
                ['id' => 'he_none', 'label' => 'ללא עוגן', 'type' => 'none', 'value' => ''],
                ['id' => 'he_more', 'label' => 'למידע נוסף', 'type' => 'static', 'value' => 'למידע נוסף', 'default' => true],
                ['id' => 'he_visit', 'label' => 'בקרו באתר', 'type' => 'static', 'value' => 'בקרו באתר'],
                ['id' => 'he_read', 'label' => 'קראו את המאמר', 'type' => 'static', 'value' => 'קראו את המאמר'],
                ['id' => 'he_domain', 'label' => 'דומיין העמוד', 'type' => 'domain', 'value' => ''],
                ['id' => 'he_project', 'label' => 'שם הפרויקט', 'type' => 'project', 'value' => ''],
            ],
            'ar' => [
                ['id' => 'ar_none', 'label' => 'بدون مرساة', 'type' => 'none', 'value' => ''],
                ['id' => 'ar_more', 'label' => 'اعرف المزيد', 'type' => 'static', 'value' => 'اعرف المزيد', 'default' => true],
                ['id' => 'ar_visit', 'label' => 'زر الموقع', 'type' => 'static', 'value' => 'زر الموقع'],
                ['id' => 'ar_read', 'label' => 'اقرأ المقال', 'type' => 'static', 'value' => 'اقرأ المقال'],
                ['id' => 'ar_domain', 'label' => 'نطاق الصفحة', 'type' => 'domain', 'value' => ''],
                ['id' => 'ar_project', 'label' => 'اسم المشروع', 'type' => 'project', 'value' => ''],
            ],
            'fa' => [
                ['id' => 'fa_none', 'label' => 'بدون انکر', 'type' => 'none', 'value' => ''],
                ['id' => 'fa_more', 'label' => 'اطلاعات بیشتر', 'type' => 'static', 'value' => 'اطلاعات بیشتر', 'default' => true],
                ['id' => 'fa_visit', 'label' => 'بازدید از سایت', 'type' => 'static', 'value' => 'بازدید از سایت'],
                ['id' => 'fa_read', 'label' => 'مطالعه مقاله', 'type' => 'static', 'value' => 'مطالعه مقاله'],
                ['id' => 'fa_domain', 'label' => 'دامنه صفحه', 'type' => 'domain', 'value' => ''],
                ['id' => 'fa_project', 'label' => 'نام پروژه', 'type' => 'project', 'value' => ''],
            ],
            'hi' => [
                ['id' => 'hi_none', 'label' => 'बिना एंकर', 'type' => 'none', 'value' => ''],
                ['id' => 'hi_more', 'label' => 'और जानें', 'type' => 'static', 'value' => 'और जानें', 'default' => true],
                ['id' => 'hi_visit', 'label' => 'साइट देखें', 'type' => 'static', 'value' => 'साइट देखें'],
                ['id' => 'hi_read', 'label' => 'लेख पढ़ें', 'type' => 'static', 'value' => 'लेख पढ़ें'],
                ['id' => 'hi_domain', 'label' => 'पेज डोमेन', 'type' => 'domain', 'value' => ''],
                ['id' => 'hi_project', 'label' => 'प्रोजेक्ट का नाम', 'type' => 'project', 'value' => ''],
            ],
            'id' => [
                ['id' => 'id_none', 'label' => 'Tanpa anchor', 'type' => 'none', 'value' => ''],
                ['id' => 'id_more', 'label' => 'Pelajari lebih lanjut', 'type' => 'static', 'value' => 'Pelajari lebih lanjut', 'default' => true],
                ['id' => 'id_visit', 'label' => 'Kunjungi situs', 'type' => 'static', 'value' => 'Kunjungi situs'],
                ['id' => 'id_read', 'label' => 'Baca artikel', 'type' => 'static', 'value' => 'Baca artikel'],
                ['id' => 'id_domain', 'label' => 'Domain halaman', 'type' => 'domain', 'value' => ''],
                ['id' => 'id_project', 'label' => 'Nama proyek', 'type' => 'project', 'value' => ''],
            ],
            'ms' => [
                ['id' => 'ms_none', 'label' => 'Tanpa sauh', 'type' => 'none', 'value' => ''],
                ['id' => 'ms_more', 'label' => 'Ketahui lebih lanjut', 'type' => 'static', 'value' => 'Ketahui lebih lanjut', 'default' => true],
                ['id' => 'ms_visit', 'label' => 'Lawati laman', 'type' => 'static', 'value' => 'Lawati laman'],
                ['id' => 'ms_read', 'label' => 'Baca artikel', 'type' => 'static', 'value' => 'Baca artikel'],
                ['id' => 'ms_domain', 'label' => 'Domain halaman', 'type' => 'domain', 'value' => ''],
                ['id' => 'ms_project', 'label' => 'Nama projek', 'type' => 'project', 'value' => ''],
            ],
            'vi' => [
                ['id' => 'vi_none', 'label' => 'Không anchor', 'type' => 'none', 'value' => ''],
                ['id' => 'vi_more', 'label' => 'Tìm hiểu thêm', 'type' => 'static', 'value' => 'Tìm hiểu thêm', 'default' => true],
                ['id' => 'vi_visit', 'label' => 'Truy cập trang', 'type' => 'static', 'value' => 'Truy cập trang'],
                ['id' => 'vi_read', 'label' => 'Đọc bài viết', 'type' => 'static', 'value' => 'Đọc bài viết'],
                ['id' => 'vi_domain', 'label' => 'Tên miền trang', 'type' => 'domain', 'value' => ''],
                ['id' => 'vi_project', 'label' => 'Tên dự án', 'type' => 'project', 'value' => ''],
            ],
            'th' => [
                ['id' => 'th_none', 'label' => 'ไม่มีแองเคอร์', 'type' => 'none', 'value' => ''],
                ['id' => 'th_more', 'label' => 'ดูเพิ่มเติม', 'type' => 'static', 'value' => 'ดูเพิ่มเติม', 'default' => true],
                ['id' => 'th_visit', 'label' => 'เยี่ยมชมเว็บไซต์', 'type' => 'static', 'value' => 'เยี่ยมชมเว็บไซต์'],
                ['id' => 'th_read', 'label' => 'อ่านบทความ', 'type' => 'static', 'value' => 'อ่านบทความ'],
                ['id' => 'th_domain', 'label' => 'โดเมนของหน้า', 'type' => 'domain', 'value' => ''],
                ['id' => 'th_project', 'label' => 'ชื่อโปรเจ็กต์', 'type' => 'project', 'value' => ''],
            ],
            'zh' => [
                ['id' => 'zh_none', 'label' => '无锚文本', 'type' => 'none', 'value' => ''],
                ['id' => 'zh_more', 'label' => '了解更多', 'type' => 'static', 'value' => '了解更多', 'default' => true],
                ['id' => 'zh_visit', 'label' => '访问网站', 'type' => 'static', 'value' => '访问网站'],
                ['id' => 'zh_read', 'label' => '阅读文章', 'type' => 'static', 'value' => '阅读文章'],
                ['id' => 'zh_domain', 'label' => '页面域名', 'type' => 'domain', 'value' => ''],
                ['id' => 'zh_project', 'label' => '项目名称', 'type' => 'project', 'value' => ''],
            ],
            'zh-cn' => [
                ['id' => 'zhcn_none', 'label' => '无锚文本', 'type' => 'none', 'value' => ''],
                ['id' => 'zhcn_more', 'label' => '了解更多', 'type' => 'static', 'value' => '了解更多', 'default' => true],
                ['id' => 'zhcn_visit', 'label' => '访问网站', 'type' => 'static', 'value' => '访问网站'],
                ['id' => 'zhcn_read', 'label' => '阅读文章', 'type' => 'static', 'value' => '阅读文章'],
                ['id' => 'zhcn_domain', 'label' => '页面域名', 'type' => 'domain', 'value' => ''],
                ['id' => 'zhcn_project', 'label' => '项目名称', 'type' => 'project', 'value' => ''],
            ],
            'zh-tw' => [
                ['id' => 'zhtw_none', 'label' => '無錨文本', 'type' => 'none', 'value' => ''],
                ['id' => 'zhtw_more', 'label' => '了解更多', 'type' => 'static', 'value' => '了解更多', 'default' => true],
                ['id' => 'zhtw_visit', 'label' => '造訪網站', 'type' => 'static', 'value' => '造訪網站'],
                ['id' => 'zhtw_read', 'label' => '閱讀文章', 'type' => 'static', 'value' => '閱讀文章'],
                ['id' => 'zhtw_domain', 'label' => '頁面網域', 'type' => 'domain', 'value' => ''],
                ['id' => 'zhtw_project', 'label' => '專案名稱', 'type' => 'project', 'value' => ''],
            ],
            'ja' => [
                ['id' => 'ja_none', 'label' => 'アンカーなし', 'type' => 'none', 'value' => ''],
                ['id' => 'ja_more', 'label' => '詳しく見る', 'type' => 'static', 'value' => '詳しく見る', 'default' => true],
                ['id' => 'ja_visit', 'label' => 'サイトを見る', 'type' => 'static', 'value' => 'サイトを見る'],
                ['id' => 'ja_read', 'label' => '記事を読む', 'type' => 'static', 'value' => '記事を読む'],
                ['id' => 'ja_domain', 'label' => 'ページのドメイン', 'type' => 'domain', 'value' => ''],
                ['id' => 'ja_project', 'label' => 'プロジェクト名', 'type' => 'project', 'value' => ''],
            ],
            'ko' => [
                ['id' => 'ko_none', 'label' => '앵커 없음', 'type' => 'none', 'value' => ''],
                ['id' => 'ko_more', 'label' => '자세히 보기', 'type' => 'static', 'value' => '자세히 보기', 'default' => true],
                ['id' => 'ko_visit', 'label' => '사이트 방문', 'type' => 'static', 'value' => '사이트 방문'],
                ['id' => 'ko_read', 'label' => '기사 읽기', 'type' => 'static', 'value' => '기사 읽기'],
                ['id' => 'ko_domain', 'label' => '페이지 도메인', 'type' => 'domain', 'value' => ''],
                ['id' => 'ko_project', 'label' => '프로젝트 이름', 'type' => 'project', 'value' => ''],
            ],
        ];

        $set['default'] = $set['en'];
        $cache = $set;
        return $cache;
    }
}

if (!function_exists('pp_project_resolve_anchor_value')) {
    /**
     * Преобразует пресет анкоров в итоговую строку с учётом контекста.
     * @param array|null $preset
     * @param array $context ['url' => string, 'project' => array, 'language' => string, 'fallback' => string]
     */
    function pp_project_resolve_anchor_value($preset, array $context = []): string
    {
        if (!is_array($preset) || empty($preset)) {
            return (string)($context['fallback'] ?? '');
        }
        $type = strtolower((string)($preset['type'] ?? 'static'));
        $raw = (string)($preset['value'] ?? '');
        $url = (string)($context['url'] ?? '');
        $project = is_array($context['project'] ?? null) ? $context['project'] : [];
        $fallback = (string)($context['fallback'] ?? '');

        switch ($type) {
            case 'none':
                return '';
            case 'domain':
                $host = '';
                if ($url !== '') {
                    $host = parse_url($url, PHP_URL_HOST) ?: '';
                }
                if ($host === '' && !empty($project['domain_host'])) {
                    $host = (string)$project['domain_host'];
                }
                $host = pp_normalize_host($host);
                return $host !== '' ? $host : ($raw !== '' ? $raw : $fallback);
            case 'url':
                if ($url !== '') {
                    $normalized = preg_replace('~^https?://~i', '', $url);
                    if (is_string($normalized)) {
                        return rtrim($normalized, '/');
                    }
                }
                return $raw !== '' ? $raw : ($fallback !== '' ? $fallback : $url);
            case 'project':
                $name = trim((string)($project['name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
                return $raw !== '' ? $raw : $fallback;
            default:
                return $raw !== '' ? $raw : $fallback;
        }
    }
}

if (!function_exists('pp_project_pick_default_anchor')) {
    /**
     * Подбирает мягкий анкор по умолчанию на основании языка и проекта.
     */
    function pp_project_pick_default_anchor(string $language, array $project, string $url = '', array $options = []): string
    {
        $language = strtolower(trim($language));
        if ($language === '' || $language === 'auto') {
            $language = strtolower(trim((string)($project['language'] ?? '')));
        }

        $presets = pp_project_anchor_presets();
        $candidates = [];
        if ($language !== '') {
            $candidates[] = $language;
            if (strpos($language, '-') !== false) {
                $candidates[] = strtok($language, '-');
            }
        }
        if (!empty($project['language'])) {
            $candidates[] = strtolower((string)$project['language']);
        }
        $candidates[] = 'default';
        $candidates[] = 'en';

        $picked = null;
        $pickedLang = null;
        foreach ($candidates as $candidateLang) {
            $key = strtolower(trim((string)$candidateLang));
            if ($key === '' || !isset($presets[$key]) || !is_array($presets[$key])) {
                continue;
            }
            foreach ($presets[$key] as $preset) {
                if (!empty($preset['default'])) {
                    $picked = $preset;
                    $pickedLang = $key;
                    break 2;
                }
            }
            if ($picked === null && !empty($presets[$key])) {
                $picked = $presets[$key][0];
                $pickedLang = $key;
            }
            if ($picked !== null) {
                break;
            }
        }

        if ($picked === null) {
            return '';
        }

        $resolved = pp_project_resolve_anchor_value($picked, [
            'url' => $url,
            'project' => $project,
            'language' => $pickedLang,
        ]);

        $resolved = trim($resolved);
        if ($resolved === '') {
            $resolved = trim((string)($options['fallback'] ?? ''));
        }
        if ($resolved === '') {
            $resolved = __('Подробнее');
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($resolved, 'UTF-8') > 64) {
                $resolved = rtrim(mb_substr($resolved, 0, 63, 'UTF-8')) . '…';
            }
        } elseif (strlen($resolved) > 64) {
            $resolved = rtrim(substr($resolved, 0, 63)) . '…';
        }

        return $resolved;
    }
}

if (!function_exists('pp_normalize_host')) {
    /**
     * Приводит хост к единому виду, убирая www и пробелы.
     */
    function pp_normalize_host(?string $host): string
    {
        $host = strtolower(trim((string)$host));
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        return $host;
    }
}

if (!function_exists('pp_project_promotion_snapshot')) {
    /**
     * Собирает информацию о статусах промо-ссылок проекта.
     */
    function pp_project_promotion_snapshot(int $projectId, array $links): array
    {
        $summary = [
            'total' => count($links),
            'active' => 0,
            'completed' => 0,
            'idle' => 0,
            'issues' => 0,
        ];
    $statusByUrl = [];
    $statusByLink = [];

        if ($summary['total'] === 0 || !function_exists('pp_promotion_get_status')) {
            $summary['idle'] = $summary['total'];
            return [
                'summary' => $summary,
                'status_by_url' => $statusByUrl,
                'can_delete' => true,
            ];
        }

        $activeStates = ['queued','running','level1_active','pending_level2','level2_active','pending_level3','level3_active','pending_crowd','crowd_ready','report_ready'];
        $issueStates = ['failed','cancelled'];

        foreach ($links as $link) {
            $linkUrl = (string)($link['url'] ?? '');
            $linkId = isset($link['id']) ? (int)$link['id'] : 0;
            if ($linkUrl === '') {
                $summary['idle']++;
                continue;
            }

            $status = 'idle';
            $payload = pp_promotion_get_status($projectId, $linkUrl, $linkId > 0 ? $linkId : null);
            if (is_array($payload) && !empty($payload['ok'])) {
                $statusByUrl[$linkUrl] = $payload;
                if ($linkId > 0) {
                    $statusByLink[$linkId] = $payload;
                }
                $status = (string)($payload['status'] ?? 'idle');
            }

            if (in_array($status, $activeStates, true)) {
                $summary['active']++;
            } elseif ($status === 'completed') {
                $summary['completed']++;
            } elseif (in_array($status, $issueStates, true)) {
                $summary['issues']++;
            } else {
                $summary['idle']++;
            }
        }

        $canDelete = ($summary['total'] === 0) || ($summary['idle'] === $summary['total']);

        return [
            'summary' => $summary,
            'status_by_url' => $statusByUrl,
            'status_by_link' => $statusByLink,
            'can_delete' => $canDelete,
        ];
    }
}

if (!function_exists('pp_project_delete_with_relations')) {
    /**
     * Полностью удаляет проект и связанные сущности из базы данных.
     */
    function pp_project_delete_with_relations(int $projectId, int $userId, bool $isAdmin): array
    {
        $conn = null;
        $transactionStarted = false;
        $deleteOk = false;
        $error = null;

        try {
            $conn = connect_db();
            if (!$conn) {
                return ['ok' => false, 'error' => 'DB'];
            }

            if (method_exists($conn, 'begin_transaction')) {
                $transactionStarted = @$conn->begin_transaction();
            }

            $cleanupStatements = [
                'DELETE FROM promotion_crowd_tasks WHERE run_id IN (SELECT id FROM promotion_runs WHERE project_id = ?)',
                'DELETE FROM promotion_nodes WHERE run_id IN (SELECT id FROM promotion_runs WHERE project_id = ?)',
                'DELETE FROM promotion_nodes WHERE publication_id IN (SELECT id FROM publications WHERE project_id = ?)',
                'DELETE FROM promotion_runs WHERE project_id = ?',
                'DELETE FROM publication_queue WHERE project_id = ?',
                'DELETE FROM publications WHERE project_id = ?',
                'DELETE FROM project_links WHERE project_id = ?',
                'DELETE FROM page_meta WHERE project_id = ?',
            ];

            foreach ($cleanupStatements as $sql) {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param('i', $projectId);
                    $ok = $stmt->execute();
                    $stmt->close();
                    if ($ok === false) {
                        throw new RuntimeException('Cleanup failed: ' . $conn->error, (int)$conn->errno);
                    }
                }
            }

            if ($isAdmin) {
                $stmt = $conn->prepare('DELETE FROM projects WHERE id = ?');
                if ($stmt) {
                    $stmt->bind_param('i', $projectId);
                    $execOk = $stmt->execute();
                    $deleteOk = $execOk && (($stmt->affected_rows ?? 0) > 0);
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
                if ($stmt) {
                    $stmt->bind_param('ii', $projectId, $userId);
                    $execOk = $stmt->execute();
                    $deleteOk = $execOk && (($stmt->affected_rows ?? 0) > 0);
                    $stmt->close();
                }
            }

            if ($transactionStarted) {
                if ($deleteOk) {
                    @$conn->commit();
                } else {
                    @$conn->rollback();
                }
                $transactionStarted = false;
            }
        } catch (Throwable $e) {
            if ($conn && $transactionStarted) {
                @$conn->rollback();
                $transactionStarted = false;
            }
            $deleteOk = false;
            $error = $e->getMessage();
            @error_log('[PromoPilot] Project delete failed for #' . $projectId . ': ' . $error);
        } finally {
            if ($conn) {
                $conn->close();
            }
        }

        return ['ok' => $deleteOk, 'error' => $deleteOk ? null : ($error ?: null)];
    }
}

if (!function_exists('pp_project_update_main_info')) {
    /**
     * Обновляет основную информацию о проекте.
     */
    function pp_project_update_main_info(int $projectId, array &$project, array $payload, array $options = []): array
    {
        $allowedLangs = $options['allowed_languages'] ?? ['ru', 'en', 'es', 'fr', 'de'];
        $availableRegions = $options['available_regions'] ?? [];
        $availableTopics = $options['available_topics'] ?? [];

        $newName = trim($payload['project_name'] ?? '');
        if ($newName === '') {
            return ['ok' => false, 'message' => __('Название не может быть пустым.')];
        }

        $newDesc = trim($payload['project_description'] ?? '');
        $newWishes = trim($payload['project_wishes'] ?? '');
        $newLang = trim($payload['project_language'] ?? (string)($project['language'] ?? 'ru'));
        $newRegion = trim((string)($payload['project_region'] ?? ($project['region'] ?? '')));
        $newTopic = trim((string)($payload['project_topic'] ?? ($project['topic'] ?? '')));

        if (!in_array($newLang, $allowedLangs, true)) {
            $newLang = (string)($project['language'] ?? 'ru');
        }

        if (!empty($availableRegions) && !in_array($newRegion, $availableRegions, true)) {
            $newRegion = $availableRegions[0];
        }

        if (!empty($availableTopics) && !in_array($newTopic, $availableTopics, true)) {
            $newTopic = $availableTopics[0];
        }

        $conn = connect_db();
        if (!$conn) {
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $stmt = $conn->prepare('UPDATE projects SET name = ?, description = ?, wishes = ?, language = ?, region = ?, topic = ? WHERE id = ?');
        if (!$stmt) {
            $conn->close();
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $stmt->bind_param('ssssssi', $newName, $newDesc, $newWishes, $newLang, $newRegion, $newTopic, $projectId);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();

        if (!$ok) {
            return ['ok' => false, 'message' => __('Ошибка сохранения основной информации.')];
        }

        $project['name'] = $newName;
        $project['description'] = $newDesc;
        $project['wishes'] = $newWishes;
        $project['language'] = $newLang;
        $project['region'] = $newRegion;
        $project['topic'] = $newTopic;

        return ['ok' => true, 'message' => __('Основная информация обновлена.')];
    }
}

if (!function_exists('pp_project_handle_links_update')) {
    /**
     * Обрабатывает обновление ссылок и пожеланий проекта.
     */
    function pp_project_handle_links_update(int $projectId, array &$project, array $request): array
    {
        $pp_update_ok = false;
        $domainErrors = 0;
        $domainToSet = '';
        $newLinkPayload = null;
        $message = '';

        $pp_is_valid_lang = static function ($code): bool {
            $code = trim((string)$code);
            if ($code === '') return false;
            if (strlen($code) > 10) return false;
            return (bool)preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $code);
        };

        $defaultLang = strtolower((string)($project['language'] ?? 'ru')) ?: 'ru';
        $projectHost = pp_normalize_host($project['domain_host'] ?? '');

        $conn = connect_db();
        if (!$conn) {
            return ['ok' => false, 'message' => __('Ошибка обновления проекта.'), 'domain_errors' => 0, 'domain_host' => (string)($project['domain_host'] ?? ''), 'links_count' => 0, 'new_link' => null];
        }

        try {
            // Удаление ссылок
            $removeIds = array_map('intval', (array)($request['remove_links'] ?? []));
            $removeIds = array_values(array_filter($removeIds, static fn($v) => $v > 0));
            if (!empty($removeIds)) {
                $ph = implode(',', array_fill(0, count($removeIds), '?'));
                $types = str_repeat('i', count($removeIds) + 1);
                $sql = "DELETE FROM project_links WHERE project_id = ? AND id IN ($ph)";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $params = array_merge([$projectId], $removeIds);
                    $stmt->bind_param($types, ...$params);
                    @$stmt->execute();
                    $stmt->close();
                }
            }

            // Обновление существующих ссылок
            if (!empty($request['edited_links']) && is_array($request['edited_links'])) {
                foreach ($request['edited_links'] as $lid => $row) {
                    $linkId = (int)$lid;
                    if ($linkId <= 0) continue;
                    $url = trim((string)($row['url'] ?? ''));
                    $anchor = trim((string)($row['anchor'] ?? ''));
                    $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                    $wish = trim((string)($row['wish'] ?? ''));
                    $anchorStrategy = strtolower(trim((string)($row['anchor_strategy'] ?? '')));
                    if ($lang === 'auto' || !$pp_is_valid_lang($lang)) { $lang = $defaultLang; }
                    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                        $uHost = pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                        if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                            $domainErrors++;
                            continue;
                        }
                        $st = $conn->prepare('UPDATE project_links SET url = ?, anchor = ?, language = ?, wish = ? WHERE id = ? AND project_id = ?');
                        if ($st) {
                            $st->bind_param('ssssii', $url, $anchor, $lang, $wish, $linkId, $projectId);
                            @$st->execute();
                            $st->close();
                        }
                        if ($projectHost === '' && $uHost !== '') {
                            $domainToSet = $uHost;
                            $projectHost = $uHost;
                        }
                    }
                }
            }

            // Добавление новых ссылок (bulk)
            if (!empty($request['added_links']) && is_array($request['added_links'])) {
                foreach ($request['added_links'] as $row) {
                    if (!is_array($row)) continue;
                    $url = trim((string)($row['url'] ?? ''));
                    $anchor = trim((string)($row['anchor'] ?? ''));
                    $lang = strtolower(trim((string)($row['language'] ?? $defaultLang)));
                    $wish = trim((string)($row['wish'] ?? ''));
                    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                        $uHost = pp_normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
                        if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                        if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                            $domainErrors++;
                            continue;
                        }
                        $meta = null;
                        if ($lang === 'auto' || !$pp_is_valid_lang($lang)) {
                            if (function_exists('pp_analyze_url_data')) {
                                try { $meta = pp_analyze_url_data($url); } catch (Throwable $e) { $meta = null; }
                                $detected = '';
                                if (is_array($meta)) {
                                    $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                    if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                        foreach ($meta['hreflang'] as $hl) {
                                            $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                            if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                        }
                                        if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                            $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                        }
                                    }
                                }
                                if ($detected !== '' && $pp_is_valid_lang($detected)) { $lang = $detected; }
                                else { $lang = $defaultLang; }
                            } else {
                                $lang = $defaultLang;
                            }
                        }
                        $lang = substr($lang, 0, 10);

                        if ($anchor === '' && $anchorStrategy !== 'none') {
                            $anchor = pp_project_pick_default_anchor($lang, $project, $url);
                        }

                        $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                        if ($ins) {
                            $ins->bind_param('issss', $projectId, $url, $anchor, $lang, $wish);
                            if (@$ins->execute()) {
                                $newId = (int)$conn->insert_id;
                                $newLinkPayload = ['id' => $newId, 'url' => $url, 'anchor' => $anchor, 'language' => $lang, 'wish' => $wish, 'anchor_strategy' => $anchorStrategy];
                                try {
                                    if (function_exists('pp_save_page_meta')) {
                                        if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($url); }
                                        if (is_array($meta)) { @pp_save_page_meta($projectId, $url, $meta); }
                                    }
                                } catch (Throwable $e) {
                                    // ignore meta errors
                                }
                            }
                            $ins->close();
                        }
                    }
                }
            } else {
                // Наследие: одиночное добавление
                $new_link = trim($request['new_link'] ?? '');
                $new_anchor = trim($request['new_anchor'] ?? '');
                $new_language = strtolower(trim($request['new_language'] ?? $defaultLang));
                $new_wish = trim($request['new_wish'] ?? '');
                $new_anchor_strategy = strtolower(trim((string)($request['new_anchor_strategy'] ?? '')));
                if ($new_link && filter_var($new_link, FILTER_VALIDATE_URL)) {
                    $uHost = pp_normalize_host(parse_url($new_link, PHP_URL_HOST) ?: '');
                    if ($projectHost === '') { $domainToSet = $uHost; $projectHost = $uHost; }
                    if ($uHost !== '' && $projectHost !== '' && $uHost !== $projectHost) {
                        $domainErrors++;
                    } else {
                        $meta = null;
                        if ($new_language === 'auto' || !$pp_is_valid_lang($new_language)) {
                            if (function_exists('pp_analyze_url_data')) {
                                try { $meta = pp_analyze_url_data($new_link); } catch (Throwable $e) { $meta = null; }
                                $detected = '';
                                if (is_array($meta)) {
                                    $detected = strtolower(trim((string)($meta['lang'] ?? '')));
                                    if ($detected === '' && !empty($meta['hreflang']) && is_array($meta['hreflang'])) {
                                        foreach ($meta['hreflang'] as $hl) {
                                            $h = strtolower(trim((string)($hl['hreflang'] ?? '')));
                                            if ($h === $defaultLang || strpos($h, $defaultLang . '-') === 0) { $detected = $h; break; }
                                        }
                                        if ($detected === '' && isset($meta['hreflang'][0]['hreflang'])) {
                                            $detected = strtolower(trim((string)$meta['hreflang'][0]['hreflang']));
                                        }
                                    }
                                }
                                if ($detected !== '' && $pp_is_valid_lang($detected)) { $new_language = $detected; }
                                else { $new_language = $defaultLang; }
                            } else {
                                $new_language = $defaultLang;
                            }
                        }
                        $new_language = substr($new_language, 0, 10);

                        if ($new_anchor === '' && $new_anchor_strategy !== 'none') {
                            $new_anchor = pp_project_pick_default_anchor($new_language, $project, $new_link);
                        }

                        $ins = $conn->prepare('INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)');
                        if ($ins) {
                            $ins->bind_param('issss', $projectId, $new_link, $new_anchor, $new_language, $new_wish);
                            if (@$ins->execute()) {
                                $newId = (int)$conn->insert_id;
                                $newLinkPayload = ['id' => $newId, 'url' => $new_link, 'anchor' => $new_anchor, 'language' => $new_language, 'wish' => $new_wish, 'anchor_strategy' => $new_anchor_strategy];
                                try {
                                    if (function_exists('pp_save_page_meta')) {
                                        if (!is_array($meta) && function_exists('pp_analyze_url_data')) { $meta = pp_analyze_url_data($new_link); }
                                        if (is_array($meta)) { @pp_save_page_meta($projectId, $new_link, $meta); }
                                    }
                                } catch (Throwable $e) {
                                    // ignore meta errors
                                }
                            }
                            $ins->close();
                        }
                    }
                }
            }

            if (isset($request['wishes'])) {
                $wishes = trim((string)$request['wishes']);
            } else {
                $wishes = (string)($project['wishes'] ?? '');
            }
            $language = $project['language'] ?? 'ru';

            if ($domainToSet !== '') {
                $stmt = $conn->prepare('UPDATE projects SET wishes = ?, language = ?, domain_host = ? WHERE id = ?');
                $stmt->bind_param('sssi', $wishes, $language, $domainToSet, $projectId);
                $project['domain_host'] = $domainToSet;
            } else {
                $stmt = $conn->prepare('UPDATE projects SET wishes = ?, language = ? WHERE id = ?');
                $stmt->bind_param('ssi', $wishes, $language, $projectId);
            }

            if ($stmt) {
                if ($stmt->execute()) {
                    $pp_update_ok = true;
                    $message = __('Проект обновлен.');
                    if ($domainErrors > 0) {
                        $message .= ' ' . sprintf(__('Отклонено ссылок с другим доменом: %d.'), $domainErrors);
                    }
                    $project['language'] = $language;
                    $project['wishes'] = $wishes;
                } else {
                    $message = __('Ошибка обновления проекта.');
                }
                $stmt->close();
            }

            if ($cst = $conn->prepare('SELECT COUNT(*) FROM project_links WHERE project_id = ?')) {
                $cst->bind_param('i', $projectId);
                $cst->execute();
                $cst->bind_result($linksCount);
                $cst->fetch();
                $cst->close();
            }
        } finally {
            $conn->close();
        }

        return [
            'ok' => (bool)$pp_update_ok,
            'message' => (string)$message,
            'domain_errors' => (int)$domainErrors,
            'domain_host' => (string)($project['domain_host'] ?? ''),
            'links_count' => (int)($linksCount ?? 0),
            'new_link' => $newLinkPayload,
        ];
    }
}

if (!function_exists('pp_project_publication_statuses')) {
    /**
     * Возвращает статусы публикаций для ссылок проекта.
     */
    function pp_project_publication_statuses(int $projectId): array
    {
        $status = [];
        try {
            $conn = connect_db();
            if (!$conn) {
                return $status;
            }
            $stmt = $conn->prepare('SELECT page_url, post_url, network, status FROM publications WHERE project_id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $projectId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $url = (string)($row['page_url'] ?? '');
                    if ($url === '') { continue; }
                    $postUrl = (string)($row['post_url'] ?? '');
                    $st = trim((string)($row['status'] ?? ''));
                    $mapped = 'not_published';
                    if ($st === 'partial') {
                        $mapped = 'manual_review';
                    } elseif ($postUrl !== '' || $st === 'success') {
                        $mapped = 'published';
                    } elseif ($st === 'failed' || $st === 'cancelled') {
                        $mapped = 'not_published';
                    } elseif ($st === 'queued' || $st === 'running') {
                        $mapped = 'pending';
                    }
                    $info = [
                        'status' => $mapped,
                        'post_url' => $postUrl,
                        'network' => trim((string)($row['network'] ?? '')),
                    ];
                    if (!isset($status[$url]) || $mapped === 'published') {
                        $status[$url] = $info;
                    }
                }
                $stmt->close();
            }
            $conn->close();
        } catch (Throwable $e) {
            // ignore
        }

        return $status;
    }
}
