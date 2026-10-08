<?php
// admin/partials/headbar_actions.php
// Common right-aligned headbar actions: Theme Toggle + User Profile Chip
?>
<div class="topbar-divider"></div>
<button class="theme-toggle" id="themeToggle" type="button" title="Toggle Theme" aria-label="Toggle Theme">
    <i class="fas fa-<?php echo ($theme ?? 'light') === 'dark' ? 'sun' : 'moon'; ?>"></i>
</button>
<div class="user-profile-wrapper" style="position: relative;">
    <div class="user-profile" id="userProfileDropdownBtn" style="cursor: pointer; display: flex; align-items: center;" onclick="toggleUserProfileMenu(event)" title="Profile & Security">
        <div class="user-avatar"><?php echo htmlspecialchars(substr($_SESSION['full_name'] ?? 'A', 0, 1)); ?></div>
        <div class="user-info">
            <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?></div>
            <div class="role"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? 'Admin'))); ?></div>
        </div>
        <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: 8px; opacity: 0.6;"></i>
    </div>
    <div class="user-dropdown-menu" id="userProfileMenu" style="display: none; position: absolute; right: 0; top: calc(100% + 8px); min-width: 220px; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(12px); border: 1px solid rgba(51, 65, 85, 0.8); border-radius: 12px; box-shadow: 0 15px 35px -5px rgba(0,0,0,0.5); z-index: 1000; padding: 6px 0; color: #f8fafc;">
        <div style="padding: 10px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 11px; color: #94a3b8;">
            Signed in as <strong style="color: #38bdf8; display: block; font-size: 12px; margin-top: 2px;"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong>
        </div>
        <a href="security.php" style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; font-size: 12px; color: #f1f5f9; text-decoration: none; transition: background 0.15s;" onmouseover="this.style.background='rgba(56,189,248,0.15)'" onmouseout="this.style.background='transparent'">
            <i class="fas fa-shield-alt" style="color: #38bdf8; width: 16px;"></i> Account Security & 2FA
        </a>
        <div style="height: 1px; background: rgba(255,255,255,0.08); margin: 4px 0;"></div>
        <a href="../logout.php" onclick="return confirm('Are you sure you want to logout?');" style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; font-size: 12px; color: #f87171; text-decoration: none; transition: background 0.15s;" onmouseover="this.style.background='rgba(239,68,68,0.15)'" onmouseout="this.style.background='transparent'">
            <i class="fas fa-sign-out-alt" style="width: 16px;"></i> Logout
        </a>
    </div>
</div>
<script>
function toggleUserProfileMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('userProfileMenu');
    if (menu) {
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    }
}
document.addEventListener('click', function(e) {
    const menu = document.getElementById('userProfileMenu');
    if (menu && menu.style.display !== 'none' && !e.target.closest('#userProfileDropdownBtn')) {
        menu.style.display = 'none';
    }
});
</script>
