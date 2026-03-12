/**
 * Update check
 */
define('package/quiqqer/cron/bin/UpdateInfo', [

    'qui/QUI',
    'Ajax',
    'Locale',
    URL_OPT_DIR + 'bin/quiqqer-asset/animejs/animejs/lib/anime.min.js',

    'css!package/quiqqer/cron/bin/UpdateInfo.css'

], function (QUI, QUIAjax, QUILocale, anime) {
    "use strict";

    setTimeout(function () {
        QUIAjax.post("package_quiqqer_cron_ajax_updateCheck", function (result) {
            if (!result.length) {
                return;
            }

            const Message = new Element("div", {
                class: "updates-are-available",
                role: 'alert',
                html: '<div class="updates-are-available-text">' +
                    '    <div class="updates-are-available-icon">' +
                    '        <span class="fa fa-exclamation"></span>' +
                    '    </div>' +
                    '    <div>' +
                    '        ' + QUILocale.get('quiqqer/cron', 'message.updates.available') +
                    '    </div>' +
                    '</div>',
                styles: {
                    opacity: 0
                }
            }).inject(document.body);

            const closeMessage = function () {
                anime({
                    targets: Message,
                    duration: 150,
                    opacity: 0,
                    top: 70,
                    easing: 'easeOutSine',
                    complete: function () {
                        Message.destroy();
                    }
                });
            };

            new Element('button', {
                html: QUILocale.get('quiqqer/cron', 'message.updates.available.button'),
                events: {
                    click: function () {
                        closeMessage();

                        require([
                            'utils/Panels',
                            'controls/packages/Panel'
                        ], function (PanelUtils, SystemPanel) {
                            PanelUtils.openPanelInTasks(
                                new SystemPanel()
                            );
                        });
                    }
                }
            }).inject(Message);

            new Element('button', {
                html: QUILocale.get('quiqqer/cron', 'message.updates.available.cancel.button'),
                'class': 'updates-are-available-cancel',
                styles: {
                    marginRight: 10
                },
                events: {
                    click: closeMessage
                }
            }).inject(Message);

            Message.setStyle('top', 70);

            anime({
                targets: Message,
                duration: 250,
                opacity: 1,
                top: 60,
                easing: 'easeOutSine'
            });
        }, {
            "package": "quiqqer/cron"
        });
    }, 3000);
});
