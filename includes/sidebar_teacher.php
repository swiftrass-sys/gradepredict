<?php
$active = $active ?? '';
?>
<aside class="sidebar">
    <?php $brandTagline = 'Teacher Portal'; include __DIR__ . '/brand.php'; ?>


    <div class="nav-label">Teaching</div>
    <a class="nav-item <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/dashboard.php"><span class="ic"><?= icon('home') ?></span> Dashboard</a>
    <a class="nav-item <?= $active === 'students' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/students.php"><span class="ic"><?= icon('users') ?></span> My Students</a>

    <div class="nav-label">Account</div>
    <?php $__me = current_user(); if (can_approve_accounts($__me)): $__pending = pending_approval_count(db(), $__me); ?>
    <a class="nav-item <?= $active === 'approvals' ? 'active' : '' ?>" href="<?= BASE_URL ?>/approvals.php"><span class="ic"><?= icon('users') ?></span> Approvals<?php if ($__pending > 0): ?><span class="nav-badge"><?= $__pending ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a class="nav-item <?= $active === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/profile.php"><span class="ic"><?= icon('user') ?></span> Profile</a>
    <a class="nav-item <?= $active === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/teacher/settings.php"><span class="ic"><?= icon('settings') ?></span> Settings</a>
    <a class="nav-item" href="<?= BASE_URL ?>/logout.php"><span class="ic"><?= icon('logout') ?></span> Logout</a>
</aside>
