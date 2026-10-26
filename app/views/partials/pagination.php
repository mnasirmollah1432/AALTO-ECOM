<?php
/**
 * Page links, shared by the storefront and admin.
 *
 * @var int    $page
 * @var int    $pages
 * @var string $path    e.g. "shop" or "admin/categories"
 * @var array  $params  current filters to keep in page links
 */
if ($pages <= 1) {
    return;
}

$link = static fn (int $p): string => url_with_query($path, $params + ['page' => $p > 1 ? $p : null]);

// Window of page numbers around the current page
$start = max(1, $page - 2);
$end = min($pages, $page + 2);
?>
<nav aria-label="Pagination">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= $page > 1 ? e($link($page - 1)) : '#' ?>" aria-label="Previous page"
               <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&lsaquo;</a>
        </li>
        <?php if ($start > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($link(1)) ?>">1</a></li>
            <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item<?= $p === $page ? ' active' : '' ?>">
                <a class="page-link" href="<?= e($link($p)) ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($end < $pages): ?>
            <?php if ($end < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($link($pages)) ?>"><?= $pages ?></a></li>
        <?php endif; ?>
        <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= $page < $pages ? e($link($page + 1)) : '#' ?>" aria-label="Next page"
               <?= $page >= $pages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&rsaquo;</a>
        </li>
    </ul>
</nav>
