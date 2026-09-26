<?php
// admin/partials/headbar_actions.php
// Common right-aligned headbar actions: Theme Toggle + User Profile Chip
?>
<div class="topbar-divider"></div>
<button class="theme-toggle" id="themeToggle" type="button" title="Toggle Theme" aria-label="Toggle Theme">
    <i class="fas fa-<?php echo ($theme ?? 'light') === 'dark' ? 'sun' : 'moon'; ?>"></i>
</button>
<div class="user-profile">
    <div class="user-avatar"><?php echo htmlspecialchars(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?></div>
    <div class="user-info">
        <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
        <div class="role"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'Admin')); ?></div>
    </div>
</div>
