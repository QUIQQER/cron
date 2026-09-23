/**
 * Cron Manager
 */
define('package/quiqqer/cron/bin/Manager', [

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
        Type: 'package/quiqqer/cron/bin/Manager',

        Binds: [
            '$onCreate',
            '$onResize'
        ],

        options: {
            title: 'Cron-Manager',
            icon: 'fa fa-clock-o'
        },

        initialize: function (options) {
            this.parent(options);

            this.$cronData = [];
            this.$cronTypeFilter = 'all';
            this.$TypeFilterButton = null;

            this.addEvents({
                onCreate: this.$onCreate,
                onResize: this.$onResize
            });
        },

        /**
         * Load the crons into the grid
         *
         * @return {self}
         */
        loadCrons: function () {
            const self = this;

            Ajax.get('package_quiqqer_cron_ajax_getList', function (result) {
                if (!self.$Grid) {
                    return;
                }

                const execCron = function (Btn) {
                    self.execCron(
                        Btn.getAttribute('cronId')
                    );
                };

                const toggleCron = function (Btn) {
                    self.toggleStatusOfCron(
                        Btn.getAttribute('cronId')
                    );
                };

                for (let i = 0, len = result.length; i < len; i++) {
                    const cliOnly = result[i].cliOnly === true;

                    result[i].cronTypeBadge = self.$createCronTypeBadge(result[i].cronType);

                    result[i].status = {
                        title: QUILocale.get(lg, 'cron.panel.manager.btn.toggle'),
                        icon: parseInt(result[i].active) === 1 ? 'fa fa-check' : 'fa fa-remove',
                        cronId: result[i].id,
                        events: {
                            onClick: toggleCron
                        }
                    };

                    result[i].play = {
                        name: 'cron-play-button-' + result[i].id,
                        title: QUILocale.get(
                            lg,
                            cliOnly
                                ? 'message.cron.cli_only'
                                : 'cron.panel.manager.btn.execute'
                        ),
                        icon: cliOnly ? 'fa fa-terminal' : 'fa fa-play',
                        disabled: cliOnly,
                        cronId: result[i].id,
                        events: {
                            onClick: execCron
                        }
                    };
                }

                self.$cronData = result;
                self.$applyCronTypeFilter();

            }, {
                'package': 'quiqqer/cron'
            });

            return this;
        },

        /**
         * event : on Create
         */
        $onCreate: function () {
            const self = this;
            const addButtonTitle = QUILocale.get(lg, 'cron.panel.manager.btn.add');
            const cronserviceButtonTitle = QUILocale.get(
                lg,
                'cron.panel.manager.btn.cronservice.register'
            );
            const deleteButtonTitle = QUILocale.get(lg, 'cron.panel.manager.btn.delete');
            const filterButtonTitle = QUILocale.get(lg, 'cron.panel.manager.btn.filter');
            const AddButton = new QUIButton({
                name: 'add',
                title: addButtonTitle,
                icon: 'fa fa-plus',
                events: {
                    onClick: function () {
                        self.openAddCronWindow();
                    }
                }
            });

            this.addButton(AddButton);
            AddButton.getElm().setAttribute('aria-label', addButtonTitle);

            this.addButton(new QUIButtonSeparator());

            this.addButton(
                new QUIButton({
                    name: 'edit',
                    text: QUILocale.get(lg, 'cron.panel.manager.btn.edit'),
                    textimage: 'fa fa-edit',
                    events: {
                        onClick: function () {
                            self.editMarkedCron();
                        }
                    }
                })
            );

            this.addButton(new QUIButtonSeparator());

            this.addButton(
                new QUIButton({
                    name: 'history',
                    text: QUILocale.get(lg, 'cron.panel.manager.btn.history'),
                    textimage: 'fa fa-history',
                    events: {
                        onClick: function () {
                            self.showHistory();
                        }
                    }
                })
            );

            const DeleteButton = new QUIButton({
                name: 'delete',
                title: deleteButtonTitle,
                icon: 'fa fa-trash',
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: function () {
                        self.deleteMarkedCrons();
                    }
                }
            });

            this.addButton(DeleteButton);
            DeleteButton.getElm().setAttribute('aria-label', deleteButtonTitle);

            this.addButton(new QUIButtonSeparator({
                styles: {
                    'float': 'right'
                }
            }));

            this.$TypeFilterButton = new QUIButton({
                name: 'filter',
                title: filterButtonTitle,
                icon: 'fa fa-filter',
                menuCorner: 'topRight',
                styles: {
                    'float': 'right'
                },
                events: {
                    onChange: function (Button, Item) {
                        self.$setCronTypeFilter(Button, Item);
                    }
                }
            });

            this.addButton(this.$TypeFilterButton);
            this.$TypeFilterButton.getElm().setAttribute('aria-label', filterButtonTitle);

            [
                {
                    type: 'all',
                    locale: 'cron.panel.manager.filter.type.all'
                },
                {
                    type: 'system',
                    locale: 'cron.panel.manager.filter.type.system'
                },
                {
                    type: 'custom',
                    locale: 'cron.panel.manager.filter.type.custom'
                }
            ].forEach(function (filter) {
                self.$TypeFilterButton.appendChild({
                    name: 'filter-' + filter.type,
                    text: QUILocale.get(lg, filter.locale),
                    cronType: filter.type,
                    checkable: true,
                    events: {
                        onInject: function (Item) {
                            if (filter.type === self.$cronTypeFilter) {
                                Item.check();
                            }
                        }
                    }
                });
            });

            this.getButtons('edit').disable();
            this.getButtons('delete').disable();

            this.addButton(new QUIButtonSeparator({
                styles: {
                    'float': 'right'
                }
            }));

            const CronserviceButton = new QUIButton({
                name: 'cronservice',
                title: cronserviceButtonTitle,
                icon: 'fa fa-cloud',
                styles: {
                    'float': 'right'
                },
                events: {
                    onClick: function () {
                        self.registerCronservice();
                    }
                }
            });

            this.addButton(CronserviceButton);
            CronserviceButton.getElm().setAttribute('aria-label', cronserviceButtonTitle);

            const Content = this.getContent(),
                Container = new Element('div', {
                    'class': 'box',
                    styles: {
                        width: '100%',
                        height: '100%'
                    }
                }).inject(Content);


            this.$Grid = new Grid(Container, {
                storageKey: 'quiqqer-cron-manager',
                columnModel: [
                    {
                        header: QUILocale.get('quiqqer/core', 'status'),
                        dataIndex: 'status',
                        dataType: 'button',
                        width: 60
                    },
                    {
                        header: '&nbsp;',
                        dataIndex: 'play',
                        dataType: 'button',
                        width: 60
                    },
                    {
                        header: QUILocale.get('quiqqer/core', 'id'),
                        dataIndex: 'id',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.title'),
                        dataIndex: 'title',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'cron.type'),
                        dataIndex: 'cronTypeBadge',
                        dataType: 'node',
                        width: 80
                    },
                    {
                        header: QUILocale.get(lg, 'cron.min'),
                        dataIndex: 'min',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.hour'),
                        dataIndex: 'hour',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.day'),
                        dataIndex: 'day',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.month'),
                        dataIndex: 'month',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.dayOfWeek'),
                        dataIndex: 'dayOfWeek',
                        dataType: 'string',
                        width: 50
                    },
                    {
                        header: QUILocale.get(lg, 'cron.execute'),
                        dataIndex: 'exec',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'cron.params'),
                        dataIndex: 'params',
                        dataType: 'string',
                        width: 150
                    },
                    {
                        header: QUILocale.get(lg, 'cron.desc'),
                        dataIndex: 'desc',
                        dataType: 'string',
                        width: 200
                    },
                    {
                        header: QUILocale.get(lg, 'last.exec.time'),
                        dataIndex: 'lastexec',
                        dataType: 'string',
                        width: 200
                    }
                ],
                multipleSelection: true,
                pagination: true
            });

            this.$Grid.addEvents({
                onRefresh: function () {
                    self.loadCrons();
                },
                onClick: function () {
                    const delButton = self.getButtons('delete'),
                        editButton = self.getButtons('edit'),
                        selected = self.$Grid.getSelectedIndices().length;

                    if (parseInt(selected) === 1) {
                        editButton.enable();
                    } else {
                        editButton.disable();
                    }

                    if (selected && self.$Grid.getSelectedData().every((cron) => cron.cronType !== 'system')) {
                        delButton.enable();
                    } else {
                        delButton.disable();
                    }
                },

                onDblClick: function (data) {
                    const rowData = self.$Grid.getDataByRow(data.row);

                    self.editCron(rowData.id);
                }
            });

            this.loadCrons();
        },

        /**
         * Create a badge for the cron type grid column.
         *
         * @param {String} cronType
         * @return {HTMLElement}
         */
        $createCronTypeBadge: function (cronType) {
            const Badge = document.createElement('span');
            const isSystemCron = cronType === 'system';

            Badge.classList.add(
                'badge',
                'badge-pill',
                isSystemCron ? 'badge-info' : 'badge-success'
            );
            Badge.textContent = QUILocale.get(
                lg,
                isSystemCron
                    ? 'cron.panel.manager.filter.type.system'
                    : 'cron.panel.manager.filter.type.custom'
            );

            return Badge;
        },

        /**
         * Apply the selected cron type filter to the grid data.
         *
         * @return {self}
         */
        $applyCronTypeFilter: function () {
            if (!this.$Grid) {
                return this;
            }

            let data = this.$cronData.slice();

            if (this.$cronTypeFilter !== 'all') {
                data = data.filter(function (cron) {
                    return cron.cronType === this.$cronTypeFilter;
                }.bind(this));
            }

            this.$Grid.setData({
                data: data
            });

            this.getButtons('edit').disable();
            this.getButtons('delete').disable();

            return this;
        },

        /**
         * Select a cron type filter from the filter button menu.
         *
         * @param {Object} Button
         * @param {Object} Item
         * @return {self}
         */
        $setCronTypeFilter: function (Button, Item) {
            const cronType = Item.getAttribute('cronType');

            if (!['all', 'system', 'custom'].includes(cronType)) {
                return this;
            }

            this.$cronTypeFilter = cronType;

            Button.getChildren().forEach(function (FilterItem) {
                if (FilterItem.getAttribute('cronType') === cronType) {
                    FilterItem.check();
                    return;
                }

                FilterItem.uncheck();
            });

            Button.getContextMenu(function (Menu) {
                Menu.hide();
            });

            return this.$applyCronTypeFilter();
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
        },

        /**
         * Open the delete marked cron windows and delete all marked crons
         *
         * @return {self}
         */
        deleteMarkedCrons: function () {
            if (!this.$Grid) {
                return this;
            }

            const self = this,
                data = this.$Grid.getSelectedData();

            if (!data.length || data.some((cron) => cron.cronType === 'system')) {
                return this;
            }

            let ids = data.map(function (o) {
                return o.id;
            });

            new QUIConfirm({
                icon: 'fa fa-remove',
                title: QUILocale.get(lg, 'cron.window.delete.cron.title'),
                text: QUILocale.get(lg, 'cron.window.delete.cron.text'),
                information: QUILocale.get(lg, 'cron.window.delete.cron.information', {
                    ids: ids.join(',')
                }),
                events: {
                    onSubmit: function (Win) {
                        Win.Loader.show();

                        Ajax.post('package_quiqqer_cron_ajax_delete', function () {
                            Win.close();
                            self.loadCrons();
                        }, {
                            'package': 'quiqqer/cron',
                            ids: JSON.encode(ids)
                        });
                    }
                }
            }).open();

            return this;
        },

        /**
         * Edit a cron, opens the cron Edit-Window
         *
         * @param {String} cronId - ID of the Cron
         */
        editCron: function (cronId) {
            const self = this;

            require(['package/quiqqer/cron/bin/CronWindow'], function (Window) {
                new Window({
                    cronId: cronId,
                    events: {
                        onSubmit: function () {
                            self.loadCrons();
                        }
                    }
                }).open();
            });

            return this;
        },

        /**
         * Opens the Edit-Window for the marked cron
         */
        editMarkedCron: function () {
            if (!this.$Grid) {
                return this;
            }

            const data = this.$Grid.getSelectedData();

            if (!data.length) {
                return this;
            }

            this.editCron(data[0].id);
        },

        /**
         * Open the add Cron-Window
         *
         * @return {self}
         */
        openAddCronWindow: function () {
            const self = this;

            require(['package/quiqqer/cron/bin/CronWindow'], function (Window) {
                new Window({
                    events: {
                        onSubmit: function () {
                            self.loadCrons();
                        }
                    }
                }).open();
            });

            return this;
        },

        /**
         * Change the cron status
         * If the cron is active to deactive
         * If the cron is deactive to active
         *
         * @param {Number} cronId - ID of the Cron
         * @return {self}
         */
        toggleStatusOfCron: function (cronId) {
            const self = this;

            Ajax.post('package_quiqqer_cron_ajax_cron_toggle', function () {
                self.loadCrons();
            }, {
                'package': 'quiqqer/cron',
                cronId: cronId
            });

            return this;
        },

        /**
         * Execute the cron
         *
         * @param {Number} cronId - ID of the Cron
         * @return {self}
         */
        execCron: function (cronId) {
            let i, len;
            let buttons = [];

            if (this.$Grid) {
                buttons = QUI.Controls.get('cron-play-button-' + cronId);
            }

            for (i = 0, len = buttons.length; i < len; i++) {
                buttons[i].setAttribute('icon', 'fa fa-spinner fa-spin');
            }

            Ajax.post('package_quiqqer_cron_ajax_cron_executeCron', function () {
                for (i = 0, len = buttons.length; i < len; i++) {
                    buttons[i].setAttribute('icon', 'fa fa-play');
                }

            }, {
                'package': 'quiqqer/cron',
                cronId: cronId
            });
        },

        /**
         * Show the Cron-History Panel
         */
        showHistory: function () {
            const self = this;

            require(['package/quiqqer/cron/bin/History'], function (Panel) {
                new Panel().inject(self.getParent());
            });
        },

        /**
         * Opens the Cronservice registration
         */
        registerCronservice: function () {
            require(['package/quiqqer/cron/bin/CronServiceWindow'], function (CronServiceWindow) {
                const csWindow = new CronServiceWindow();
                csWindow.open();
            });
        }
    });
});
