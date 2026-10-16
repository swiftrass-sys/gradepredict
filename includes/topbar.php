<?php
/** Expects $user (array from users table) and optional $showSearch (bool) */
$showSearch = $showSearch ?? false;
$unread = unread_notification_count((int) $user['id']);
$notifs = recent_notifications((int) $user['id'], 8);
$avatarUrl = avatar_url($user);
?>
<div class="topbar">
    <div class="topbar-left">
        <button type="button" id="sidebarToggle" class="btn btn-outline btn-icon" aria-label="Open menu"><?= icon('menu', 20) ?></button>
        <?php if ($showSearch): ?>
        <div class="search autocomplete-wrap">
            <span><?= icon('search', 16) ?></span>
            <input type="text" placeholder="Search students, subjects..." data-autocomplete="<?= BASE_URL ?>/admin/ajax_search.php" autocomplete="off">
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-outline btn-sm" data-cmdk-trigger data-tooltip="Ctrl/Cmd + K">⌘ Quick Jump</button>
    </div>
    <div class="topbar-right">
        <div class="notif-wrap">
            <button type="button" class="icon-btn" id="notifBell" aria-label="Notifications<?= $unread > 0 ? ', ' . $unread . ' unread' : '' ?>" aria-haspopup="true" aria-expanded="false" aria-controls="notifDropdown">
                <?= icon('bell', 20) ?>
                <?php if ($unread > 0): ?><span class="dot" aria-hidden="true"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
            </button>
            <div id="notifDropdown" class="notif-panel" role="region" aria-label="Notifications">
                <div class="np-head">
                    <strong>Notifications</strong>
                    <?php if ($unread > 0): ?>
                        <form method="post" action="<?= BASE_URL ?>/notifications-read.php">
                            <?= csrf_field() ?>
                            <button type="submit" class="np-markread">Mark all read</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="np-list">
                    <?php if (empty($notifs)): ?>
                        <div class="np-empty">You're all caught up.</div>
                    <?php endif; ?>
                    <?php foreach ($notifs as $n): ?>
                        <a class="np-item<?= $n['is_read'] ? '' : ' unread' ?>" href="<?= e(BASE_URL . ($n['link'] ?? '/notifications.php')) ?>">
                            <span class="np-dot" aria-hidden="true"></span>
                            <span class="np-body">
                                <span class="np-msg"><?= e($n['message']) ?></span>
                                <span class="np-time"><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <a class="np-foot" href="<?= BASE_URL ?>/notifications.php">View all notifications</a>
            </div>
        </div>
        <div class="profile-wrap">
            <button type="button" class="user-chip" id="profileBtn" aria-haspopup="menu" aria-expanded="false" aria-controls="profileMenu">
                <?php if ($avatarUrl): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="" style="width:38px;height:38px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                    <div class="avatar"><?= e(initials($user['full_name'])) ?></div>
                <?php endif; ?>
                <div class="user-chip-text">
                    <div class="name"><?= e($user['full_name']) ?></div>
                    <div class="role"><?= e($user['user_type']) ?></div>
                </div>
                <span class="chev"><?= icon('chevron', 16) ?></span>
            </button>
            <div class="profile-menu" id="profileMenu" role="menu" aria-label="Account menu">
                <div class="pm-head">
                    <div class="name"><?= e($user['full_name']) ?></div>
                    <div class="label-quiet"><?= e($user['email']) ?></div>
                </div>
                <?php if ($user['role'] !== 'admin'): ?>
                    <a class="pm-item" role="menuitem" href="<?= BASE_URL ?>/<?= e($user['role']) ?>/profile.php"><?= icon('user', 16) ?> My profile</a>
                <?php endif; ?>
                <a class="pm-item" role="menuitem" href="<?= BASE_URL ?>/<?= e($user['role']) ?>/settings.php"><?= icon('settings', 16) ?> Settings</a>
                <a class="pm-item" role="menuitem" href="<?= BASE_URL ?>/change-password.php"><?= icon('key', 16) ?> Change password</a>
                <div class="pm-sep"></div>
                <a class="pm-item pm-danger" role="menuitem" href="<?= BASE_URL ?>/logout.php"><?= icon('logout', 16) ?> Log out</a>
            </div>
        </div>
    </div>
</div>

<style>
/* Safety net for the header. :where() has zero specificity, so these rules only apply when the
   real stylesheet has no rule for the same thing (for example a browser still holding an old copy). */
:where(.notif-panel, .profile-menu) { display: none; position: absolute; right: 0; top: 100%; z-index: 70; }
:where(.notif-panel.open) { display: flex; }
:where(.profile-menu.open) { display: block; }
:where(.notif-wrap, .profile-wrap) { position: relative; }
:where(.notif-panel) { flex-direction: column; width: 360px; max-width: calc(100vw - 24px); max-height: 480px; overflow: hidden; }
</style>
<script>
(function () {
  var bell = document.getElementById('notifBell');
  var notif = document.getElementById('notifDropdown');
  var pBtn = document.getElementById('profileBtn');
  var pMenu = document.getElementById('profileMenu');

  function closeProfile() {
    if (!pMenu) return;
    pMenu.classList.remove('open');
    pBtn.setAttribute('aria-expanded', 'false');
  }
  function closeNotif() {
    if (!notif) return;
    notif.classList.remove('open');
    if (bell) bell.setAttribute('aria-expanded', 'false');
  }

  if (bell && notif) {
    bell.addEventListener('click', function (e) {
      e.stopPropagation();
      closeProfile();
      var open = notif.classList.toggle('open');
      bell.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  if (pBtn && pMenu) {
    pBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      closeNotif();
      var open = pMenu.classList.toggle('open');
      pBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var first = pMenu.querySelector('.pm-item'); if (first) first.focus(); }
    });
    pMenu.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(pMenu.querySelectorAll('.pm-item'));
      var i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
  }
  document.addEventListener('click', function (e) {
    if (notif && !notif.contains(e.target) && !(bell && bell.contains(e.target))) closeNotif();
    if (pMenu && !pMenu.contains(e.target)) closeProfile();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { var nWasOpen = notif && notif.classList.contains('open'); closeNotif(); if (nWasOpen && bell) bell.focus(); if (pMenu && pMenu.classList.contains('open')) { closeProfile(); pBtn.focus(); } }
  });
})();
</script>
