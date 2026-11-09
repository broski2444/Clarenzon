(function (global) {
  const SCAN_COOLDOWN_MS = 500;
  const FORMATS = [
    'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code', 'codabar'
  ];

  function permHelp() {
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent);
    if (ios) {
      return 'Camera access is blocked. On iPhone: Settings → Safari → Camera → Allow, then reload this page.';
    }
    return 'Camera access is blocked. Open your browser site settings, allow Camera for this site, then reload.';
  }

  function isNativeApp() {
    try {
      return !!(window.ClarenzonNativeApp && window.ClarenzonNativeApp.isNative && window.ClarenzonNativeApp.isNative());
    } catch (e) {
      return false;
    }
  }

  function isSecure() {
    return isNativeApp()
      || window.isSecureContext
      || location.hostname === 'localhost'
      || location.hostname === '127.0.0.1';
  }

  class ClarenzonScanner {
    constructor(opts) {
      this.viewportId = opts.viewportId;
      this.onCode = opts.onCode;
      this.onStatus = opts.onStatus || function () {};
      this.onError = opts.onError || function () {};
      this.active = false;
      this.lastCode = '';
      this.lastAt = 0;
      this.html5 = null;
      this.stream = null;
      this.raf = 0;
      this.detector = null;
      this.video = null;
    }

    async start() {
      if (this.active && !isNativeApp()) return;
      if (isNativeApp() && window.ClarenzonNativeApp.scanBarcode) {
        window.__clarenzonScannerEmit = (code) => this._emit(code);
        this.onStatus('opening');
        try {
          window.ClarenzonNativeApp.scanBarcode();
        } catch (e) {
          this.onError('Could not open the phone camera.');
        }
        return;
      }
      if (!isSecure()) {
        this.onError('Camera needs HTTPS or localhost. Open this app from a secure address.');
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.onError('This browser cannot open the camera. Use Safari or Chrome on the phone.');
        return;
      }
      try {
        if (navigator.permissions && navigator.permissions.query) {
          const st = await navigator.permissions.query({ name: 'camera' }).catch(() => null);
          if (st && st.state === 'denied') {
            this.onError(permHelp());
            return;
          }
        }
      } catch (e) { /* Safari may throw */ }

      this.active = true;
      this.onStatus('opening');

      if ('BarcodeDetector' in window) {
        try {
          this.detector = new BarcodeDetector({ formats: FORMATS });
          await this._startNative();
          this.onStatus('live');
          return;
        } catch (err) {
          this.detector = null;
        }
      }

      if (typeof Html5Qrcode === 'undefined') {
        this.active = false;
        this.onError(permHelp());
        return;
      }
      try {
        this.html5 = new Html5Qrcode(this.viewportId);
        let formats;
        if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
          formats = [
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.QR_CODE
          ];
          if (Html5QrcodeSupportedFormats.ITF) formats.push(Html5QrcodeSupportedFormats.ITF);
        }
        await this.html5.start(
          { facingMode: 'environment' },
          { fps: 8, qrbox: { width: 260, height: 160 }, formatsToSupport: formats },
          (text) => this._emit(text),
          () => {}
        );
        this.onStatus('live');
      } catch (err) {
        this.active = false;
        const name = String(err && (err.name || err.message) || err);
        if (/NotAllowed|Permission|Denied/i.test(name)) {
          this.onError(permHelp());
        } else {
          this.onError('Could not open the camera. ' + permHelp());
        }
      }
    }

    async _startNative() {
      const host = document.getElementById(this.viewportId);
      if (!host) throw new Error('viewport');
      host.innerHTML = '';
      this.video = document.createElement('video');
      this.video.setAttribute('playsinline', 'true');
      this.video.setAttribute('autoplay', 'true');
      this.video.muted = true;
      host.appendChild(this.video);
      this.stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' } },
        audio: false
      });
      this.video.srcObject = this.stream;
      await this.video.play();
      const tick = async () => {
        if (!this.active || !this.detector || !this.video) return;
        try {
          if (this.video.readyState >= 2) {
            const codes = await this.detector.detect(this.video);
            if (codes && codes[0] && codes[0].rawValue) {
              this._emit(codes[0].rawValue);
            }
          }
        } catch (e) { /* keep scanning */ }
        this.raf = requestAnimationFrame(tick);
      };
      this.raf = requestAnimationFrame(tick);
    }

    _emit(raw) {
      const code = String(raw || '').trim();
      if (!code) return;
      const now = Date.now();
      if (code === this.lastCode && (now - this.lastAt) < SCAN_COOLDOWN_MS) {
        return;
      }
      this.lastCode = code;
      this.lastAt = now;
      this.onCode(code);
    }

    async stop() {
      this.active = false;
      if (this.raf) cancelAnimationFrame(this.raf);
      this.raf = 0;
      if (this.video) {
        this.video.srcObject = null;
        this.video.remove();
        this.video = null;
      }
      if (this.stream) {
        this.stream.getTracks().forEach((t) => t.stop());
        this.stream = null;
      }
      if (this.html5) {
        try { await this.html5.stop(); } catch (e) {}
        try { await this.html5.clear(); } catch (e) {}
        this.html5 = null;
      }
      this.onStatus('idle');
    }
  }

  global.ClarenzonScanner = ClarenzonScanner;
  global.ClarenzonScannerIsNative = isNativeApp;
  global.__clarenzonOnNativeScan = function (code) {
    if (typeof global.__clarenzonScannerEmit === 'function') {
      global.__clarenzonScannerEmit(code);
    }
  };
})(window);
