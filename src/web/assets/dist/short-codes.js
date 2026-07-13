(function () {
    'use strict';

    Craft.ShortCodesFieldGenerator = class {
        constructor(settings) {
            this.settings = settings;
            this.fieldSelector = `.field[data-attribute="${settings.fieldHandle}"]`;

            this.enhance(document);

            this.observer = new MutationObserver((records) => {
                records.forEach((record) => {
                    record.addedNodes.forEach((node) => {
                        if (node instanceof Element) {
                            this.enhance(node);
                        }
                    });
                });
            });

            this.observer.observe(document.body, {
                childList: true,
                subtree: true,
            });
        }

        enhance(root) {
            const fields = [];

            if (root instanceof Element && root.matches(this.fieldSelector)) {
                fields.push(root);
            }

            root.querySelectorAll(this.fieldSelector).forEach((field) => {
                fields.push(field);
            });

            fields.forEach((field) => this.enhanceField(field));
        }

        enhanceField(field) {
            if (field.dataset.shortCodesGenerator === 'true') {
                return;
            }

            const input = field.querySelector('input[type="text"], textarea');
            if (!input || input.disabled || input.readOnly) {
                return;
            }

            const inputContainer = field.querySelector(':scope > .input') || input.closest('.input');
            if (!inputContainer) {
                return;
            }

            field.dataset.shortCodesGenerator = 'true';

            const controls = document.createElement('div');
            controls.className = 'short-codes-generator';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn small';

            const spinner = document.createElement('div');
            spinner.className = 'spinner hidden';
            spinner.setAttribute('aria-hidden', 'true');

            const status = document.createElement('p');
            status.className = 'short-codes-generator__status light';
            status.setAttribute('aria-live', 'polite');

            const updateButtonLabel = () => {
                button.textContent = input.value.trim() === ''
                    ? this.settings.generateLabel
                    : this.settings.regenerateLabel;
            };

            updateButtonLabel();
            input.addEventListener('input', updateButtonLabel);

            button.addEventListener('click', async () => {
                if (
                    input.value.trim() !== '' &&
                    !window.confirm(this.settings.confirmMessage)
                ) {
                    return;
                }

                button.disabled = true;
                spinner.classList.remove('hidden');
                status.textContent = '';

                try {
                    const response = await Craft.sendActionRequest(
                        'POST',
                        this.settings.action
                    );
                    const code = response.data && response.data.code;

                    if (typeof code !== 'string' || code === '') {
                        throw new Error(this.settings.errorMessage);
                    }

                    input.value = code;
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                    input.focus();
                    input.select();

                    status.textContent = this.settings.unsavedMessage;
                    Craft.cp.displaySuccess(this.settings.generatedMessage);
                } catch (error) {
                    const responseMessage = error &&
                        error.response &&
                        error.response.data &&
                        error.response.data.message;
                    const message = responseMessage ||
                        (error && error.message) ||
                        this.settings.errorMessage;

                    status.textContent = message;
                    Craft.cp.displayError(message);
                } finally {
                    button.disabled = false;
                    spinner.classList.add('hidden');
                    updateButtonLabel();
                }
            });

            controls.append(button, spinner, status);
            inputContainer.insertAdjacentElement('afterend', controls);
        }
    };
})();
