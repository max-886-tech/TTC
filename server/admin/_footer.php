  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  const body = document.body;
  const toggleBtn = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('adminSidebarBackdrop');
  const desktopBreakpoint = 992;
  const storageKey = 'admin_sidebar_collapsed';

  function isMobile() {
    return window.innerWidth < desktopBreakpoint;
  }

  function applySavedDesktopState() {
    if (isMobile()) {
      body.classList.remove('sidebar-collapsed');
      body.classList.remove('sidebar-open');
      return;
    }
    const collapsed = localStorage.getItem(storageKey) === '1';
    body.classList.toggle('sidebar-collapsed', collapsed);
    body.classList.remove('sidebar-open');
  }

  function toggleSidebar() {
    if (isMobile()) {
      body.classList.toggle('sidebar-open');
      return;
    }
    const willCollapse = !body.classList.contains('sidebar-collapsed');
    body.classList.toggle('sidebar-collapsed', willCollapse);
    localStorage.setItem(storageKey, willCollapse ? '1' : '0');
  }

  function closeMobileSidebar() {
    if (isMobile()) body.classList.remove('sidebar-open');
  }

  toggleBtn?.addEventListener('click', toggleSidebar);
  backdrop?.addEventListener('click', closeMobileSidebar);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMobileSidebar();
  });
  window.addEventListener('resize', applySavedDesktopState);
  applySavedDesktopState();
})();
</script>
</body>
</html>
