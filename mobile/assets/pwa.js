(function () {
  const swUrl = document.documentElement.getAttribute('data-sw') || 'sw.js';
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(swUrl).catch(function () {});
  }

  let deferredPrompt = null;
  const banner = document.getElementById('mInstall');
  const btn = document.getElementById('mInstallBtn');
  const dismiss = document.getElementById('mInstallDismiss');

  function standalone() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function showBanner() {
    if (!banner || standalone()) return;
    banner.hidden = false;
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showBanner();
  });

  window.addEventListener('appinstalled', function () {
    if (banner) banner.hidden = true;
    deferredPrompt = null;
  });

  if (btn) {
    btn.addEventListener('click', async function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        try { await deferredPrompt.userChoice; } catch (e) {}
        deferredPrompt = null;
        if (banner) banner.hidden = true;
        return;
      }
      window.location.href = btn.getAttribute('data-install') || 'install.php';
    });
  }
  if (dismiss) {
    dismiss.addEventListener('click', function () {
      if (banner) banner.hidden = true;
      try { sessionStorage.setItem('clar_hide_install', '1'); } catch (e) {}
    });
  }

  const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const hide = (function () {
    try { return sessionStorage.getItem('clar_hide_install') === '1'; } catch (e) { return false; }
  })();
  if (!standalone() && !hide && (isIos || window.matchMedia('(max-width: 820px)').matches)) {
    showBanner();
  }
})();
