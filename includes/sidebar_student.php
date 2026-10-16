<?php
// Expects $active to be set by the including page (e.g. 'dashboard', 'subjects', ...)
$active = $active ?? '';
?>
<aside class="sidebar">
    <?php $brandTagline = 'Student Portal'; include __DIR__ . '/brand.php'; ?>


    <div class="nav-label">Academic</div>
    <a class="nav-item <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/dashboard.php"><span class="ic"><?= icon('home') ?></span> Dashboard</a>
    <a class="nav-item <?= $active === 'subjects' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/subjects.php"><span class="ic"><?= icon('book') ?></span> My Subjects</a>
    <a class="nav-item <?= $active === 'predictions' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/predictions.php"><span class="ic"><?= icon('trend') ?></span> Predictions</a>
    <a class="nav-item <?= $active === 'history' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/grade-history.php"><span class="ic"><?= icon('clock') ?></span> Grade History</a>
    <a class="nav-item <?= $active === 'inputs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/data-inputs.php"><span class="ic"><?= icon('edit') ?></span> Data Inputs</a>
    <a class="nav-item <?= $active === 'finance' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/finance.php"><span class="ic"><?= icon('wallet') ?></span> My Finance</a>

    <div class="nav-label">Account</div>
    <?php $__me = current_user(); if (can_approve_accounts($__me)): $__pending = pending_approval_count(db(), $__me); ?>
    <a class="nav-item <?= $active === 'approvals' ? 'active' : '' ?>" href="<?= BASE_URL ?>/approvals.php"><span class="ic"><?= icon('users') ?></span> Approvals<?php if ($__pending > 0): ?><span class="nav-badge"><?= $__pending ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a class="nav-item <?= $active === 'profile' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/profile.php"><span class="ic"><?= icon('user') ?></span> Profile</a>
    <a class="nav-item <?= $active === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/student/settings.php"><span class="ic"><?= icon('settings') ?></span> Settings</a>
    <a class="nav-item" href="<?= BASE_URL ?>/logout.php"><span class="ic"><?= icon('logout') ?></span> Logout</a>

</aside>
