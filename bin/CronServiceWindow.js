/**
 * @module package/quiqqer/cron/bin/CronServiceWindow
 */
define('package/quiqqer/cron/bin/CronServiceWindow', [

    'qui/QUI',
    'qui/controls/windows/Popup',
    'qui/controls/buttons/Button',
    'Mustache',
    'Locale',
    'Ajax',
    'qui/controls/desktop/panels/Sheet',

    'text!package/quiqqer/cron/bin/CronServiceWindow.html',
    'text!package/quiqqer/cron/bin/CronServiceWindowRegistration.html',
    'text!package/quiqqer/cron/bin/CronServiceWindowRegistrationSuccess.html',
    'css!package/quiqqer/cron/bin/CronServiceWindow.css'

], function (QUI, QUIPopup, QUIButton, Mustache, QUILocale, QUIAjax, QUISheets, template, registrationTemplate, registrationSuccessTemplate) {
    "use strict";

    const lg = 'quiqqer/cron';

    return new Class({

        Extends: QUIPopup,
        Type: 'package/quiqqer/cron/bin/CronServiceWindow',

        Binds: [
            '$onSubmit',
            '$onOpen',
            'showRegistration'
        ],

        options: {
            title: QUILocale.get(lg, 'cron.window.cronservice.title'),
            icon: 'fa fa-cloud',
            maxWidth: 400,
            maxHeight: 650,
            autoclose: false,
            buttons: false
        },

        initialize: function (options) {
            this.parent(options);

            this.registered = false;

            this.addEvents({
                onOpen: this.$onOpen
            });
        },

        $onOpen: function () {
            const Content = this.getContent();

            Content.set('html', '');
            Content.addClass('quiqqer-cron-cronserviceWindow');

            this.refresh();
        },

        /**
         * refresh
         */
        refresh: function () {
            const self = this,
                Content = this.getContent();

            this.Loader.show();

            QUIAjax.get('package_quiqqer_cron_ajax_cronservice_getStatus', function (result) {
                let status = result;
                let statusText = QUILocale.get(lg, 'cron.window.cronservice.status.text.unregistered');

                status.status = parseInt(status.status);

                if (status.status === 1) {
                    statusText = QUILocale.get(lg, 'cron.window.cronservice.status.text.registered');
                }

                if (status.status === 2) {
                    statusText = QUILocale.get(lg, 'cron.window.cronservice.status.text.inactive');
                }

                Content.set('html', Mustache.render(template, {
                    cron_window_cronservice_content_title: QUILocale.get(lg, 'cron.window.cronservice.content.title'),
                    cron_window_cronservice_content_about_title: QUILocale.get(lg, 'cron.window.cronservice.content.about.title'),
                    cron_window_cronservice_content_about_text: QUILocale.get(lg, 'cron.window.cronservice.content.about.text'),
                    cron_window_cronservice_content_status_title: QUILocale.get(lg, 'cron.window.cronservice.content.status.title'),
                    cron_window_cronservice_content_status_text: QUILocale.get(lg, 'cron.window.cronservice.content.status.text'),
                    cron_window_cronservice_content_btn_unregister: QUILocale.get(lg, 'cron.window.cronservice.content.register.btn.unregister'),
                    cron_window_cronservice_content_btn_register: QUILocale.get(lg, 'cron.window.cronservice.content.btn.register'),
                    cron_window_cronservice_content_register_lbl_stats_status: QUILocale.get(lg, 'cron.window.cronservice.content.register.lbl.stats.status'),
                    cron_window_cronservice_content_register_lbl_stats_errors: QUILocale.get(lg, 'cron.window.cronservice.content.register.lbl.stats.errors'),
                    cron_window_cronservice_content_register_lbl_stats_lastExecution: QUILocale.get(lg, 'cron.window.cronservice.content.register.lbl.stats.lastExecution'),
                    cron_window_cronservice_content_register_lbl_stats_lastLocalExecution: QUILocale.get(lg, 'cron.window.cronservice.content.register.lbl.stats.lastLocalExecution'),
                    statusText: statusText,
                    status: status.status,
                    statusErrors: status.current_failures, //== 0 ? "0": status['errors'].toString(),
                    statusLastExecution: status.last_execution,
                    statusLastLocalExecution: status.last_local_execution,
                    registered: (status.status !== 0),
                    active: (status.status === 1),
                    inactive: (status.status === 2)
                }));

                self.registered = (status.status !== 0);
                self.status = status.status;

                const Buttons = Content.getElement('.quiqqer-cron-cronservicewindow-buttons');


                // Register/Unregister Button
                let btnText;

                if (self.status) {
                    btnText = QUILocale.get(lg, 'cron.window.cronservice.content.btn.register');
                }

                if (self.registered) {
                    btnText = QUILocale.get(lg, 'cron.window.cronservice.content.btn.unregister');
                }


                if (typeof self.status == 'undefined') {
                    document.getElement(".quiqqer-cron-cronservicewindow-section-status").innerHTML =
                        "<h2>" +
                        QUILocale.get(lg, 'cron.window.cronservice.content.status.title') +
                        "</h2><br />" +
                        QUILocale.get(lg, 'cron.window.cronservice.content.status.unavailable');

                    self.Loader.hide();
                    return;
                }

                let Button;

                if (parseInt(self.status) === 2) {
                    // Resend Activation Button
                    Button = new QUIButton({
                        text: QUILocale.get(lg, 'cron.window.cronservice.content.btn.resend.activation.mail'),
                        textimage: 'fa fa-envelope-o',
                        events: {
                            onClick: function () {
                                self.resendActivationMail(this);
                            }
                        },
                        styles: {
                            'float': 'none',
                            width: 'calc(50% - 5px)'
                        }
                    });

                    // Cancel Registration Button
                    new QUIButton({
                        text: '<span class="quiqqer-cron-cronservicewindow-registration-success-btn-cancel-text">' +
                            QUILocale.get(lg, 'cron.window.cronservice.registration.button.text.cancel') +
                            '</span>',
                        events: {
                            onClick: function (Button) {
                                Button.setAttribute('text', QUILocale.get('quiqqer/cron', 'cron.window.cronservice.content.btn.unregister.confirm'));

                                if (Button.getAttribute('clickcnt') === 1) {
                                    self.unregister().then(function () {
                                        self.refresh();
                                    });
                                }

                                Button.setAttribute('clickcnt', 1);
                            }
                        },
                        styles: {
                            'float': 'none',
                            margin: '0 10px 0 0',
                            width: 'calc(50% - 5px)'
                        }
                    }).inject(Buttons);
                } else {
                    Button = new QUIButton({
                        text: btnText,
                        textimage: 'fa fa-arrow-right',
                        events: {
                            onClick: function (Button) {
                                if (!self.registered) {
                                    self.showRegistration();
                                    return;
                                }

                                Button.setAttribute('text', QUILocale.get('quiqqer/cron', 'cron.window.cronservice.content.btn.unregister.confirm'));

                                if (Button.getAttribute('clickcnt') === 1) {
                                    self.unregister().then(function () {
                                        self.refresh();
                                    });
                                }

                                Button.setAttribute('clickcnt', 1);
                            }
                        },
                        styles: {
                            'float': 'none',
                            margin: '0 auto',
                            width: 200
                        }
                    });
                }

                Button.inject(Buttons);

                self.Loader.hide();
            }, {
                'package': lg,
                'onError': function () {
                    self.Loader.hide();
                }
            });
        },

        /**
         * Opens the registration sheet
         */
        showRegistration: function () {
            const self = this;

            new QUISheets({
                header: true,
                icon: 'fa fa-cloud',
                title: QUILocale.get(lg, 'cron.window.cronservice.title'),
                buttons: false,
                events: {
                    onOpen: function (Sheet) {
                        let ErrorContainer;
                        const Content = Sheet.getContent();

                        Content.set('html', Mustache.render(registrationTemplate, {
                            cron_window_cronservice_registration_title: QUILocale.get(lg, 'cron.window.cronservice.registration.title'),
                            cron_window_cronservice_content_register_txt_email_title: QUILocale.get(lg, 'cron.window.cronservice.content.register.txt.email.title'),
                            cron_window_cronservice_content_register_placeholder_email: QUILocale.get(lg, 'cron.window.cronservice.content.register.placeholder.email'),
                            cron_window_cronservice_content_btn_register: QUILocale.get(lg, 'cron.window.cronservice.registration.title')
                        }));

                        const register = () => {
                            self.Loader.show();
                            self.register(Email.value).then(function () {
                                self.Loader.hide();
                                self.showRegistrationSuccess();
                                Sheet.destroy();
                            }).catch(function (e) {
                                if (ErrorContainer) {
                                    ErrorContainer.destroy();
                                }

                                ErrorContainer = new Element('div', {
                                    'class': 'quiqqer-cron-cronservicewindow-registration-error'
                                }).inject(Content, 'top');

                                ErrorContainer.set('html', e.getMessage ? e.getMessage() : e);
                                self.Loader.hide();
                            });
                        };

                        const Form  = Content.getElement('form');
                        Form.addEventListener('submit', (event) => {
                            event.preventDefault();
                            register();
                        });

                        const Email = Content.getElement('.quiqqer-cron-cronservicewindow-registration-txt-email');
                        Email.focus();

                        Content.getElement('.quiqqer-cron-cronservicewindow-btn-register').addEvent('click', function () {
                            register();
                        });
                    },

                    onClose: function (Sheet) {
                        Sheet.destroy();
                    }
                }
            }).inject(this.$Elm).show();
        },

        /**
         * Shows the registration success sheet, which contains information about the activation email
         */
        showRegistrationSuccess: function () {
            const self = this;

            new QUISheets({
                header: true,
                icon: 'fa fa-cloud',
                title: QUILocale.get(lg, 'cron.window.cronservice.title'),
                buttons: false,
                events: {
                    onOpen: function (Sheet) {
                        const Content = Sheet.getContent();

                        self.Loader.show();

                        Content.set('html', Mustache.render(registrationSuccessTemplate, {
                            cron_window_cronservice_registration_success_title: QUILocale.get(lg, 'cron.window.cronservice.registration.success.title'),
                            cron_window_cronservice_registration_success_text: QUILocale.get(lg, 'cron.window.cronservice.registration.success.text'),
                            cron_window_cronservice_content_registration_successfull_btn_confirm: QUILocale.get(lg, 'cron.window.cronservice.registration.success.btn.confirm.text')
                        }));

                        // Click event handler
                        Content.getElement('.quiqqer-cron-cronservicewindow-registration-success-btn-confirm').addEvent('click', function () {
                            self.refresh();
                            Sheet.destroy();
                        });

                        self.Loader.hide();
                    },
                    onClose: function (Sheet) {
                        Sheet.destroy();
                    }
                }
            }).inject(this.$Elm).show();
        },

        /**
         * Register a email to the cron service
         *
         * @param {String} email
         * @returns {Promise}
         */
        register: function (email) {
            return new Promise(function (resolve, reject) {
                QUIAjax.post('package_quiqqer_cron_ajax_cronservice_sendRegistration', resolve, {
                    'package': lg,
                    'email': email,
                    onError: reject,
                    showError: false
                });
            });
        },

        /**
         * Unregister a email to the cron service
         *
         * @returns {Promise}
         */
        unregister: function () {
            return new Promise(function (resolve, reject) {
                QUIAjax.get('package_quiqqer_cron_ajax_cronservice_revokeRegistration', resolve, {
                    'package': lg,
                    onError: reject
                });
            });
        },

        /**
         * Sends the activation mail again
         * @returns {*}
         */
        resendActivationMail: function (Button) {
            return new Promise(function (resolve, reject) {
                Button.setAttribute("text", "<span class='fa fa-spinner fa-spin'></span>");

                QUIAjax.get('package_quiqqer_cron_ajax_cronservice_resendActivation', function () {
                    Button.setAttribute("textimage", "fa fa-check");
                    Button.setAttribute("text", QUILocale.get(lg, "cron.window.cronservice.content.button.message.sent.success"));
                    Button.disable();
                    resolve();
                }, {
                    'package': lg,
                    onError: reject
                });
            });
        }
    });
});
