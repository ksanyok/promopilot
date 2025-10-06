<?php
/** @var int $totalPages */
/** @var int $currentPage */
/** @var callable $crowdBuildUrl */
?>
<?php if ($totalPages > 1): ?>
    <?php
    $pagesToRender = [];
    if ($totalPages <= 10) {
        for ($p = 1; $p <= $totalPages; $p++) {
            $pagesToRender[] = $p;
        }
    } else {
        $pagesToRender[] = 1;
        $windowStart = max(2, $currentPage - 2);
        $windowEnd = min($totalPages - 1, $currentPage + 2);
        if ($windowStart > 2) {
            $pagesToRender[] = 'ellipsis';
        }
        for ($p = $windowStart; $p <= $windowEnd; $p++) {
            $pagesToRender[] = $p;
        }
        if ($windowEnd < $totalPages - 1) {
            $pagesToRender[] = 'ellipsis';
        }
        $pagesToRender[] = $totalPages;
    }
    $prevDisabled = $currentPage <= 1;
    $nextDisabled = $currentPage >= $totalPages;
    ?>
    <nav aria-label="<?php echo __('Навигация по страницам'); ?>" class="mt-4 crowd-pagination-wrapper">
        <ul class="pagination pagination-sm crowd-pagination mb-0">
            <li class="page-item <?php echo $prevDisabled ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $prevDisabled ? '#' : htmlspecialchars($crowdBuildUrl(['crowd_page' => max(1, $currentPage - 1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo __('Предыдущая'); ?>">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
            <?php foreach ($pagesToRender as $pageItem): ?>
                <?php if ($pageItem === 'ellipsis'): ?>
                    <li class="page-item disabled crowd-pagination__ellipsis"><span class="page-link">&hellip;</span></li>
                <?php else: ?>
                    <?php $page = (int)$pageItem; ?>
                    <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($crowdBuildUrl(['crowd_page' => $page]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $page; ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="page-item <?php echo $nextDisabled ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $nextDisabled ? '#' : htmlspecialchars($crowdBuildUrl(['crowd_page' => min($totalPages, $currentPage + 1)]), ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo __('Следующая'); ?>">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
