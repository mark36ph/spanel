<?php
$items = [
    ['dashboard', '▦', 'Dashboard', '/dashboard.php'],
    ['sites', '◎', 'Websites', '/sites.php'],
    ['servers', '▦', 'Servers', '/servers.php'],
    ['operations', '◷', 'Operations', '/operations.php'],
    ['audit', '≡', 'Audit log', '/audit.php'],
    ['email', '✉', 'Email accounts', '#'],
    ['databases', '▤', 'Databases', '#'],
    ['cron', '◷', 'Cron jobs', '#'],
    ['backups', '▱', 'Backups', '#'],
    ['security', '◇', 'Security & SSL', '#'],
    ['settings', '⚙', 'Server settings', '#'],
];
?>
<aside class="sidebar">
    <a class="brand" href="/dashboard.php"><span class="brand-mark">S</span><span>SPanel<small>Control panel</small></span></a>
    <div class="nav-label">MANAGEMENT</div>
    <nav>
    <?php foreach ($items as [$key, $icon, $label, $url]): ?>
        <a class="nav-link <?= $activeItem === $key ? 'active' : '' ?> <?= $url === '#' ? 'disabled' : '' ?>"
           href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" <?= $url === '#' ? 'aria-disabled="true" title="Planned for a later stage"' : '' ?>>
            <span class="nav-symbol"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($url === '#'): ?><span class="soon">SOON</span><?php endif; ?>
        </a>
    <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <div class="local-pill"><span class="status-dot"></span>Local development</div>
        <form method="post" action="/logout.php">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
            <button class="logout-button" type="submit">↗ &nbsp; Sign out</button>
        </form>
    </div>
</aside>
