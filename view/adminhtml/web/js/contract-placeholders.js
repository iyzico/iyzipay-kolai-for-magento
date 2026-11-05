define([], function () {
    'use strict';

    /**
     * Inserts the clicked placeholder at the caret of the contract textarea.
     * The textarea is found through the shared <td class="value"> rather than by
     * id: the system config form derives element ids from the field name, so an
     * id guessed here drifts the moment the field moves.
     */
    return function (config, element) {
        element.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-placeholder]'),
                target = (element.closest('td') || element.parentNode).querySelector('textarea'),
                token,
                start;

            if (!chip || !target || target.disabled) {
                return;
            }

            event.preventDefault();
            token = chip.getAttribute('data-placeholder');
            start = target.selectionStart || 0;

            target.value = target.value.slice(0, start) +
                token +
                target.value.slice(target.selectionEnd || start);
            target.focus();
            target.selectionStart = target.selectionEnd = start + token.length;
        });
    };
});
