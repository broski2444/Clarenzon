(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp-box'));
    boxes.forEach(function (box, index) {
        box.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(0, 1);
            if (this.value && boxes[index + 1]) {
                boxes[index + 1].focus();
            }
        });
        box.addEventListener('keydown', function (event) {
            if (event.key === 'Backspace' && !this.value && boxes[index - 1]) {
                boxes[index - 1].focus();
            }
        });
        box.addEventListener('paste', function (event) {
            var text = ((event.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, 6);
            if (!text) {
                return;
            }
            event.preventDefault();
            text.split('').forEach(function (digit, digitIndex) {
                if (boxes[digitIndex]) {
                    boxes[digitIndex].value = digit;
                }
            });
            var focusAt = Math.min(text.length, boxes.length - 1);
            boxes[focusAt].focus();
        });
    });
    if (boxes[0]) {
        boxes[0].focus();
    }

    var form = document.getElementById('otpForm');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('.login-button');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.textContent = 'Verifying...';
            }
        });
    }

    var resendBtn = document.getElementById('resendOtpBtn');
    var wait = parseInt(window.CLARENZON_OTP_RESEND_WAIT || '0', 10);
    if (!resendBtn || wait <= 0) {
        return;
    }
    function tick() {
        if (wait <= 0) {
            resendBtn.disabled = false;
            resendBtn.textContent = "Didn't receive the code? Resend OTP";
            return;
        }
        resendBtn.disabled = true;
        resendBtn.textContent = 'Resend available in ' + wait + ' seconds';
        wait -= 1;
        window.setTimeout(tick, 1000);
    }
    tick();
})();
