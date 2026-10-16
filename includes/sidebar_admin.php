<?php
$active = $active ?? '';
?>
<aside class="sidebar dark">
    <?php $brandTagline = 'Admin Portal'; include __DIR__ . '/brand.php'; ?>

    <div class="nav-label">Main</div>
    <a class="nav-item <?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php"><span class="ic"><?= icon('chart') ?></span> Dashboard</a>
    <a class="nav-item <?= $active === 'students' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/students.php"><span class="ic"><?= icon('users') ?></span> Students</a>
    <a class="nav-item <?= $active === 'teachers' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/teachers.php"><span class="ic"><?= icon('user') ?></span> Teachers</a>

    <div class="nav-label">Registrar</div>
    <a class="nav-item <?= $active === 'programs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/programs.php"><span class="ic"><?= icon('cap') ?></span> Programs &amp; Majors</a>
    <a class="nav-item <?= $active === 'curriculum' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/curriculum.php"><span class="ic"><?= icon('book') ?></span> Curriculum</a>
    <a class="nav-item <?= $active === 'sections' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/sections.php"><span class="ic"><?= icon('building') ?></span> Sections</a>
    <a class="nav-item <?= $active === 'subjects' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/subjects.php"><span class="ic"><?= icon('book') ?></span> Subjects</a>
    <a class="nav-item <?= $active === 'sheets' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/grading-sheets.php"><span class="ic"><?= icon('file') ?></span> Grading Sheets</a>

    <div class="nav-label">Cashier</div>
    <a class="nav-item <?= $active === 'fees' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/fees.php"><span class="ic"><?= icon('wallet') ?></span> Fee Structures</a>

    <div class="nav-label">Academics</div>
    <a class="nav-item <?= $active === 'predictions' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/predictions.php"><span class="ic"><?= icon('trend') ?></span> Predictions</a>
    <a class="nav-item <?= $active === 'flags' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/grade-flags.php"><span class="ic"><?= icon('flag') ?></span> Grade Flags</a>
    <a class="nav-item <?= $active === 'accuracy' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/model-accuracy.php"><span class="ic"><?= icon('chart') ?></span> Model Accuracy</a>
    <a class="nav-item <?= $active === 'reports' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/reports.php"><span class="ic"><?= icon('file') ?></span> Reports</a>
    <a class="nav-item <?= $active === 'inputs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/data-inputs.php"><span class="ic"><?= icon('folder') ?></span> Data Inputs</a>
    <a class="nav-item <?= $active === 'history' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/grade-history.php"><span class="ic"><?= icon('clock') ?></span> Grade History</a>
    <a class="nav-item <?= $active === 'import' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/import-data.php"><span class="ic"><?= icon('upload') ?></span> Import Data</a>

    <div class="nav-label">Communication</div>
    <a class="nav-item <?= $active === 'announcements' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/announcements.php"><span class="ic"><?= icon('megaphone') ?></span> Announcements</a>

    <div class="nav-label">System</div>
    <?php $__me = current_user(); if (can_approve_accounts($__me)): $__pending = pending_approval_count(db(), $__me); ?>
    <a class="nav-item <?= $active === 'approvals' ? 'active' : '' ?>" href="<?= BASE_URL ?>/approvals.php"><span class="ic"><?= icon('users') ?></span> Approvals<?php if ($__pending > 0): ?><span class="nav-badge"><?= $__pending ?></span><?php endif; ?></a>
    <?php endif; ?>
    <a class="nav-item <?= $active === 'settings' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/settings.php"><span class="ic"><?= icon('settings') ?></span> Settings</a>
    <a class="nav-item <?= $active === 'users' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/users.php"><span class="ic"><?= icon('user') ?></span> Users</a>
    <a class="nav-item <?= $active === 'generator' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/account-generator.php"><span class="ic"><?= icon('key') ?></span> Account generator</a>
    <a class="nav-item <?= $active === 'logs' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/activity-logs.php"><span class="ic"><?= icon('clock') ?></span> Activity Logs</a>
    <a class="nav-item" href="<?= BASE_URL ?>/logout.php"><span class="ic"><?= icon('logout') ?></span> Logout</a>

</aside>
