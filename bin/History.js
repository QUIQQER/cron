/**
 * Cron History Panel
 */

define('package/quiqqer/cron/bin/History', [

    'qui/QUI',
    'qui/controls/desktop/Panel',
    'qui/controls/windows/Confirm',
    'qui/controls/buttons/Button',
    'qui/controls/buttons/Separator',
    'controls/grid/Grid',
    'Ajax',
    'Locale'

], function (QUI, QUIPanel, QUIConfirm, QUIButton, QUIButtonSeparator, Grid, Ajax, QUILocale) {
    "use strict";

    const lg = 'quiqqer/cron';

    return new Class({

        Extends: QUIPanel,
        Type: 'package/quiqqer/cron/bin/History',

        Binds: [
            '$onCreate',
            '$onResize'
        ],

        options: {
            title: 'Cron-History',
            icon: 'fa fa-long-arrow-right'
        },

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize
            });
        },

        /**
         * Load the History list
         */
        loadData: function () {
            const self = this,
                options = this.$Grid.options;

            this.Loader.show();

            Ajax.get('package_quiqqer_cron_ajax_history_get', function (result) {
                self.$Grid.setData(result);
                self.Loader.hide();
            }, {
                'package': 'quiqqer/cron',
                params: JSON.encode({
                    perPage: options.perPage,
                    page: options.page
                })
            });
        },

        /**
         * event : on Create
         */
        $onCreate: function () {
            const self = this,
                Content = this.getContent(),

                Container = new Element('div', {
                    'class': 'box',
                    styles: {
                        width: '100%',
                        height: '100%'
                    }
                }).inject(Content);


            this.$Grid = new Grid(Container, {
                columnModel: [
                    {
                        header: QUILocale.get(lg, 'cron.start_date'),
                        dataIndex: 'lastexec',
                        dataType: 'date',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'cron.finish_date'),
                        dataIndex: 'finish',
                        dataType: 'date',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'cron.id'),
                        dataIndex: 'cronid',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.title'),
                        dataIndex: 'cronTitle',
                        dataType: 'string',
                        width: 200
                    },
                    {
                        header: QUILocale.get('quiqqer/system', 'user_id'),
                        dataIndex: 'uid',
                        dataType: 'string',
                        width: 100
                    },
                    {
                        header: QUILocale.get('quiqqer/system', 'username'),
                        dataIndex: 'username',
                        dataType: 'string',
                        width: 150
                    }
                ],
                pagination: true
            });

            this.$Grid.addEvents({
                onRefresh: function () {
                    self.loadData();
                }
            });

            this.$Grid.refresh();
        },

        /**
         * event : on resize
         */
        $onResize: function () {
            if (!this.$Grid) {
                return;
            }

            const Content = this.getContent(),
                size = Content.getSize();

            this.$Grid.setHeight(size.y - 40);
            this.$Grid.setWidth(size.x - 40);
        }
    });
});
