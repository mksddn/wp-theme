(function () {
    var l10n = window.wpThemeSecurityHardening || {};

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();

            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(textarea);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('copy-failed'));
                }
            } catch (error) {
                document.body.removeChild(textarea);
                reject(error);
            }
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.wp-theme-security-hardening-copy-button');
        if (!button) {
            return;
        }

        event.preventDefault();

        var targetId = button.getAttribute('data-copy-target');
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) {
            return;
        }

        var text = 'TEXTAREA' === target.tagName ? target.value : target.textContent;
        var original = button.textContent;

        copyText(text).then(function () {
            button.textContent = l10n.copied || 'Copied';
            window.setTimeout(function () {
                button.textContent = original;
            }, 2000);
        }).catch(function () {
            button.textContent = l10n.failed || 'Copy failed';
            window.setTimeout(function () {
                button.textContent = original;
            }, 2000);
        });
    });
})();
