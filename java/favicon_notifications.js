(function () {
    const faviconVersion = '20260809-role-folders';
    const scriptEl = document.currentScript || document.querySelector('script[src*="favicon_notifications.js"]');
    const normalFaviconHref = scriptEl
        ? new URL('../images/clarenzon-removebg.png?v=' + faviconVersion, scriptEl.src).href
        : 'images/clarenzon-removebg.png?v=' + faviconVersion;
    let faviconLink = null;
    let lastDotState = null;

    function getFaviconLink() {
        if (faviconLink) return faviconLink;
        faviconLink = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
        if (!faviconLink) {
            faviconLink = document.createElement('link');
            faviconLink.rel = 'icon';
            faviconLink.type = 'image/png';
            document.head.appendChild(faviconLink);
        }
        faviconLink.setAttribute('rel', 'icon');
        return faviconLink;
    }

    function setNormalFavicon() {
        const link = getFaviconLink();
        link.type = 'image/png';
        link.href = normalFaviconHref;
    }

    function setDotFavicon() {
        const img = new Image();
        img.onload = function () {
            const size = 64;
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                setNormalFavicon();
                return;
            }

            ctx.clearRect(0, 0, size, size);
            ctx.drawImage(img, 0, 0, size, size);
            ctx.beginPath();
            ctx.arc(50, 14, 10, 0, Math.PI * 2);
            ctx.fillStyle = '#ef4444';
            ctx.fill();
            ctx.lineWidth = 4;
            ctx.strokeStyle = '#ffffff';
            ctx.stroke();

            const link = getFaviconLink();
            link.type = 'image/png';
            link.href = canvas.toDataURL('image/png');
        };
        img.onerror = setNormalFavicon;
        img.src = normalFaviconHref;
    }

    window.updateFaviconNotificationDot = function (hasAlert) {
        const nextState = !!hasAlert;
        if (lastDotState === nextState) return;
        lastDotState = nextState;
        if (nextState) {
            setDotFavicon();
        } else {
            setNormalFavicon();
        }
    };

    window.refreshFaviconNotificationDot = async function () {
        try {
            const res = await fetch('notifications_api.php?action=get-notifications', {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await res.json();
            window.updateFaviconNotificationDot(!!(data && data.success && Number(data.count) > 0));
        } catch (_) {
            window.updateFaviconNotificationDot(false);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        setNormalFavicon();
        window.refreshFaviconNotificationDot();
        setInterval(window.refreshFaviconNotificationDot, 30000);
    });
})();
