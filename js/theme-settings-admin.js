(function () {
    function findHeadlessSectionElements(field) {
        if (!field) {
            return [];
        }

        var table = field.closest('table');
        if (!table) {
            var row = field.closest('tr');
            return row ? [row] : [];
        }

        // Settings API renders a section as: heading, description, table.
        // Collect everything up to the heading, whatever tags the section
        // callback uses, but never cross into the previous section's table.
        var elements = [table];
        var sibling = table.previousElementSibling;

        while (sibling && sibling.tagName !== 'TABLE') {
            elements.push(sibling);
            if (sibling.tagName === 'H2') {
                break;
            }
            sibling = sibling.previousElementSibling;
        }

        return elements;
    }

    function init() {
        var toggle = document.querySelector('input[type="checkbox"][name="wp_theme_settings[headless]"]');
        if (!toggle) {
            return;
        }

        var elements = [];
        [
            'wp_theme_settings[rest_allowed_origins]',
            'wp_theme_settings[headless_robots_txt]'
        ].forEach(function (name) {
            var field = document.querySelector('textarea[name="' + name + '"]');
            findHeadlessSectionElements(field).forEach(function (element) {
                if (elements.indexOf(element) === -1) {
                    elements.push(element);
                }
            });
        });

        if (!elements.length) {
            return;
        }

        // Hide the section, but keep the fields enabled so their saved
        // values are preserved when headless mode is turned off.
        function sync() {
            elements.forEach(function (element) {
                element.hidden = !toggle.checked;
                element.style.display = toggle.checked ? '' : 'none';
            });
        }

        toggle.addEventListener('change', sync);
        sync();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
