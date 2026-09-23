/**
 * Checkbox backed directly by the corresponding update cron's activation state.
 */
define('package/quiqqer/cron/bin/controls/UpdateSetting', [
    'qui/controls/Control',
    'Ajax',
    'css!package/quiqqer/cron/bin/controls/UpdateSetting.css'
], function (QUIControl, Ajax) {
    'use strict';

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/cron/bin/controls/UpdateSetting',

        initialize: function (options) {
            this.parent(options);
            this.$loaded = null;
            this.$state = null;
            this.addEvents({onImport: () => this.load()});
        },

        load: function () {
            const Input = this.getElm();
            Input.id = 'cron-update-setting-' + this.getId();
            Input.disabled = true;
            Input.setAttribute('aria-busy', 'true');
            this.$setting = Input.getAttribute('data-setting');
            this.$loaded = new Promise((resolve, reject) => {
                Ajax.get('package_quiqqer_cron_ajax_updateSettings_get', (states) => {
                    this.$state = states[this.$setting];
                    Input.checked = this.$state.active;
                    Input.disabled = false;
                    Input.removeAttribute('aria-busy');
                    resolve();
                }, {
                    'package': 'quiqqer/cron',
                    onError: reject
                });
            });

            // Ajax reports load failures. Keep the checkbox disabled and reject subsequent saves.
            this.$loaded.catch(() => {});
        },

        save: function () {
            return this.$loaded.then(() => {
                const active = this.getElm().checked;

                if (this.$state.exists && active === this.$state.active) {
                    return;
                }

                return new Promise((resolve, reject) => {
                    Ajax.post('package_quiqqer_cron_ajax_updateSettings_save', (states) => {
                        this.$state = states[this.$setting];
                        resolve();
                    }, {
                        'package': 'quiqqer/cron',
                        setting: this.$setting,
                        active: active ? 1 : 0,
                        onError: reject
                    });
                });
            });
        }
    });
});
