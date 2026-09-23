/**
 * Cron Window
 */
define('package/quiqqer/cron/bin/CronWindow', [

    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Select',
    'package/quiqqer/cron/bin/controls/Params',
    'Ajax',
    'Locale',

    'package/quiqqer/cron/bin/controls/CronTime',

    'text!package/quiqqer/cron/bin/CronWindow.html',
    'css!package/quiqqer/cron/bin/CronWindow.css'

], function (QUIConfirm, QUISelect, QUIParams, Ajax, QUILocale, CronTime, cronWindowTemplate) {
    "use strict";

    var lg = 'quiqqer/cron';

    return new Class({

        Type: 'package/quiqqer/cron/bin/CronWindow',
        Extends: QUIConfirm,

        options: {
            title: QUILocale.get('quiqqer/cron', 'cron.window.add.cron.title'),
            icon: 'fa fa-clock-o',
            maxWidth: 750,
            maxHeight: 500,

            cronId: null, // if you want to edit a cron

            cancel_button: {
                text: QUILocale.get('quiqqer/core', 'cancel'),
                textimage: 'fa fa-remove'
            },
            ok_button: {
                text: QUILocale.get('quiqqer/core', 'ok'),
                textimage: 'fa fa-check'
            }
        },

        initialize: function (options) {
            options = options || {};

            if (options.cronId && !options.title) {
                options.title = QUILocale.get(lg, 'cron.window.edit.cron.title');
            }

            this.parent(options);

            this.$available = [];

            this.$List = null;
            this.$Description = null;
            this.$DescriptionText = null;
            this.$CronTimeControl = null;

            this.$ParamsControl = null;
        },

        /**
         * Open the Window
         *
         * @return {Object} self
         */
        open: function () {
            this.parent();
            this.Loader.show();

            var self = this,
                Content = this.getContent();

            Content.set('html', cronWindowTemplate);

            this.$Description = Content.querySelector('[data-name="description"]');
            this.$DescriptionText = Content.querySelector('[data-name="description-text"]');

            // locale
            Content.getElement(
                '.control-cron-add-intervall-title'
            ).set('html', QUILocale.get(lg, 'cron.interval'));

            Content.getElement(
                '[for="control-cron-add-params"]'
            ).set('html', QUILocale.get(lg, 'cron.params'));


            // data
            //this.$List   = Content.getElement('.control-cron-add-list');
            this.$List = new QUISelect({
                showIcons: false,
                searchable: true
            }).inject(
                Content.getElement('.control-cron-add-list')
            );

            this.$Params = Content.getElement('[name="params"]');

            this.$List.addEvent('change', function (val) {
                const selectedCrons = self.$available.filter(function (cron) {
                    return cron.exec === val;
                });
                const selectedCron = selectedCrons[0];
                const description = selectedCron?.description ?? '';

                self.$Description.hidden = description === '';
                self.$DescriptionText.textContent = description;

                if (!self.$ParamsControl) {
                    return;
                }

                const allowedParams = selectedCrons.reduce(function (params, cron) {
                    return params.concat(cron.params);
                }, []);

                self.$ParamsControl.setAttribute('allowedParams', allowedParams);
            });

            this.$CronTimeControl = new CronTime().inject(
                this.$Elm.getElement(
                    '.control-cron-add-intervall-control'
                )
            );

            Ajax.get('package_quiqqer_cron_ajax_getAvailableCrons', function (result) {
                self.$available = result;

                for (var i = 0, len = result.length; i < len; i++) {
                    self.$List.appendChild(
                        '<b>' + result[i].title + '</b> - ' + result[i].description,
                        result[i].exec,
                        false
                    );
                }

                self.$ParamsControl = new QUIParams(self.$Params);

                if (!self.getAttribute('cronId')) {
                    self.Loader.hide();
                    return;
                }

                Ajax.get('package_quiqqer_cron_ajax_cron_get', function (result) {
                    self.$List.setValue(result.exec);

                    if (result.system) {
                        self.$List.disable();
                    }

                    self.$CronTimeControl.setValue(
                        result.min,
                        result.hour,
                        result.day,
                        result.month,
                        result.dayOfWeek
                    );

                    self.$Params.value = result.params;

                    self.$Params.fireEvent('change');
                    //self.$List.fireEvent('change');

                    self.Loader.hide();
                }, {
                    'package': 'quiqqer/cron',
                    cronId: self.getAttribute('cronId')
                });

            }, {
                'package': 'quiqqer/cron'
            });


            return this;
        },

        /**
         * Add the Cron to the list
         *
         * @return {Object} self
         */
        submit: function () {
            var self = this;

            if (!this.$List) {
                return this;
            }

            if (!this.getContent()) {
                return this;
            }

            var CronTime = this.$CronTimeControl.getValue();

            if (this.getAttribute('cronId')) {
                Ajax.post('package_quiqqer_cron_ajax_edit', function () {
                    self.fireEvent('submit');
                    self.close();
                }, {
                    'package': 'quiqqer/cron',
                    cronId: this.getAttribute('cronId'),
                    cron: this.$List.getValue(),
                    min: CronTime.minute,
                    hour: CronTime.hour,
                    day: CronTime.day,
                    month: CronTime.month,
                    dayOfWeek: CronTime.dayOfWeek,
                    params: JSON.encode(this.$ParamsControl.getValue())
                });

                return this;
            }

            Ajax.post('package_quiqqer_cron_ajax_add', function () {
                self.fireEvent('submit');
                self.close();
            }, {
                'package': 'quiqqer/cron',
                cron: this.$List.getValue(),
                min: CronTime.minute,
                hour: CronTime.hour,
                day: CronTime.day,
                month: CronTime.month,
                dayOfWeek: CronTime.dayOfWeek,
                params: JSON.encode(this.$ParamsControl.getValue())
            });

            return this;
        }
    });
});
