<?php if (!empty($announcements)): ?>
<div class="card" style="margin-bottom:18px;background:var(--blue-light);border-color:transparent;">
    <?php foreach ($announcements as $ann): ?>
        <div style="display:flex;gap:12px;align-items:flex-start;<?= $ann !== end($announcements) ? 'margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid rgba(37,99,235,.15);' : '' ?>">
            <span style="font-size:18px;">📢</span>
            <div>
                <strong style="font-size:13.5px;"><?= e($ann['title']) ?></strong>
                <p style="font-size:13px;margin:4px 0 0;color:var(--text-dark);"><?= nl2br(e($ann['body'])) ?></p>
                <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= e(date('M j, Y', strtotime($ann['created_at']))) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
