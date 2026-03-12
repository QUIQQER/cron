/**
 * Param input field
 */

define('package/quiqqer/cron/bin/controls/Params', [

    'qui/QUI',
    'qui/controls/Control',
    'qui/controls/buttons/Button',
    'qui/controls/windows/Confirm',

    'Locale',

    'css!package/quiqqer/cron/bin/controls/Params.css'

], function (QUI, QUIControl, QUIButton, QUIConfirm, QUILocale) {
    "use strict";

    var lg = 'quiqqer/cron';

    /**
     * @class package/quiqqer/cron/bin/controls/Params
     *
     * @memberof! <global>
     */
    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/cron/bin/controls/Params',

        Binds: [
            '$setValue',
            'openAddParamWindow'
        ],

        options: {
            name: '',
            styles: false, // optional -> style parameter
            allowedParams: false, // optional {array} -> set which param names are allowed
            allowDuplicate: false, // optional -> allow duplicate param entries
            windowMaxHeight: 420,   // optional -> the add window max height
            windowMaxWidth: 540    // optional -> the add window max width
        },

        initialize: function (Input, options) {
            this.parent(options);

            this.$Input = Input || null;
            this.$ParamList = null;
            this.$AddButton = null;

            if (this.$Input) {
                this.create();
            }
        },

        /**
         * Bind an input element to the control
         *
         * @method package/quiqqer/cron/bin/controls/Params#bindElement
         * @param {HTMLElement} Input - Input Element
         */
        bindElement: function (Input) {
            var self = this;

            this.$Input = Input;
            this.$Input.type = 'hidden';

            this.$Input.addEvent('change', function () {
                self.$setValue(this.value);
            });

            return this;
        },

        /**
         * Create the DOMNode
         *
         * @method package/quiqqer/cron/bin/controls/Params#create
         */
        create: function () {
            var self = this;

            this.$Elm = new Element('div', {
                'class': 'qui-control-input-param box'
            });

            this.$ParamList = new Element('div', {
                'class': 'qui-control-input-param-list box'
            });

            this.$AddButton = new QUIButton({
                textimage: 'icon-plus fa fa-plus',
                text: QUILocale.get(lg, 'controls.Params.add'),
                events: {
                    onClick: function () {
                        self.openAddParamWindow();
                    }
                },
                styles: {
                    clear: 'both',
                    margin: 0,
                    width: 230
                }
            });

            if (!this.$Input) {
                this.$Input = new Element('input', {
                    name: this.getAttribute('name')
                });

                this.$Elm.inject(this.$Elm);

            } else {
                this.$Elm.wraps(this.$Input);
            }

            this.$Input.type = 'hidden';
            this.$Input.addEvent('change', function () {
                self.$setValue(this.value);
            });

            if (this.getAttribute('styles')) {
                this.$ParamList.setStyles(this.getAttribute('styles'));
            }

            this.$AddButton.inject(this.$Elm);
            this.$ParamList.inject(this.$Elm);

            // display params
            this.$setValue(this.$Input.value);

            return this.$Elm;
        },

        /**
         * Set the value to the control
         * on Change event at the input field
         *
         * @method package/quiqqer/cron/bin/controls/Params#$setValue
         * @param {String} value - JSON array string
         */
        $setValue: function (value) {
            var i, len;

            var jsonValue = JSON.decode(value);

            if (typeOf(jsonValue) != 'array') {
                jsonValue = [];
            }

            for (i = 0, len = jsonValue.length; i < len; i++) {
                if (typeof jsonValue[i].name === 'undefined') {
                    continue;
                }

                if (typeof jsonValue[i].value === 'undefined') {
                    continue;
                }

                this.addParam(
                    jsonValue[i].name,
                    jsonValue[i].value
                );
            }
        },

        /**
         * Return the values
         *
         * @method package/quiqqer/cron/bin/controls/Params#getValue
         * @return {Array}
         */
        getValue: function () {
            var i, len, Elm;

            var list = this.$ParamList.getElements('.qui-control-input-param-entry'),
                data = [];

            for (i = 0, len = list.length; i < len; i++) {
                Elm = list[i];

                data.push({
                    name: Elm.get('data-name'),
                    value: Elm.get('data-value')
                });
            }

            return data;
        },

        /**
         * Add a parameter to the list
         *
         * @method package/quiqqer/cron/bin/controls/Params#addParam
         * @param {String} name - Name of the parameter
         * @param {String} value - Value of the parameter
         * @return {self}
         */
        addParam: function (name, value) {
            var self = this;

            var Elm = new Element('div', {
                'class': 'qui-control-input-param-entry box',
                html: name + ' : ' + value,
                "data-name": name,
                "data-value": value,
                events:
                    {
                        dblclick: function () {
                            self.openAddParamWindow(this);
                        }
                    }
            }).inject(this.$ParamList);

            new QUIButton({
                icon: 'icon-remove fa fa-remove',
                styles: {
                    'float': 'right',
                    lineHeight: 20,
                    margin: 0
                },
                events:
                    {
                        onClick: function () {
                            Elm.destroy();
                        }
                    }
            }).inject(Elm);

            return this;
        },

        /**
         * Open the add parameter sub window
         *
         * @method package/quiqqer/cron/bin/controls/Params#openAddParamWindow
         * @param {HTMLElement} [Param] - optional, edit a param (.qui-control-input-param-entry)
         */
        openAddParamWindow: function (Param) {
            var self = this,
                ParamControl = false,
                ParamValue = false;

            new QUIConfirm({
                title: QUILocale.get(lg, 'controls.Params.add.title'),
                icon: 'fa fa-plus',
                texticon: 'fa fa-plus',
                maxHeight: this.getAttribute('windowMaxHeight'),
                maxWidth: this.getAttribute('windowMaxWidth'),
                autoclose: false,

                text: QUILocale.get(lg, 'controls.Params.add.text'),
                information: '<div class="qui-control-input-param-window">' +
                    '<label>' +
                    '     <span class="qui-control-input-param-window-label">Name</span>' +
                    '     <input type="text" name="paramName" value="" />' +
                    '</label>' +
                    '<label>' +
                    '     <span class="qui-control-input-param-window-label">Wert</span>' +
                    '     <div class="qui-control-input-param-window-value"></div>' +
                    '</label>' +
                    '<div class="qui-control-input-param-window-description' +
                    ' messages-message box message-information"></div>' +
                    '</div>',

                events:
                    {
                        onCreate: function (Confirm) {
                            Confirm.getElm().addClass('qui-control-input-param-window');
                        },

                        onOpen: function (Confirm) {
                            var NameSelect,
                                Content = Confirm.getContent(),
                                ParamName = Content.getElement('[name="paramName"]'),
                                allowedParams = self.getAttribute('allowedParams'),
                                DescriptionContainer = Content.getElement('.qui-control-input-param-window-description'),
                                ParamValueContainer = Content.getElement('.qui-control-input-param-window-value'),
                                initialValueSet = false;

                            var onParamChange = function () {
                                if (!NameSelect) {
                                    return;
                                }

                                ParamValueContainer.set('html', '');

                                var Option = NameSelect.getElement('option[value="' + NameSelect.value + '"]'),
                                    description = Option.get('data-desc'),

                                    ParamValue = new Element('input', {
                                        type: 'text',
                                        name: 'paramValue'
                                    }).inject(ParamValueContainer);

                                if (!description) {
                                    DescriptionContainer.set('html', '');
                                    DescriptionContainer.setStyle('display', 'none');
                                } else {
                                    DescriptionContainer.set('html', description);
                                    DescriptionContainer.setStyle('display', '');
                                }

                                if (!initialValueSet && typeOf(Param) === 'element') {
                                    ParamValue.value = Param.get('data-value');
                                }

                                initialValueSet = true;

                                if (!Option.get('data-control')) {
                                    return;
                                }

                                Confirm.Loader.show();
                                ParamValue.set('data-qui', Option.get('data-control'));

                                QUI.parse(Content).then(function () {
                                    //ParamControl = QUI.Controls.getById(ParamValue.get('dat-quiid'));
                                    Confirm.Loader.hide();
                                });
                            };

                            if (allowedParams.length) {
                                NameSelect = new Element('select', {
                                    name: "paramName",
                                    events: {
                                        change: onParamChange
                                    }
                                }).replaces(ParamName);

                                for (var i = 0, len = allowedParams.length; i < len; i++) {
                                    new Element('option', {
                                        value: allowedParams[i].name,
                                        html: allowedParams[i].name,
                                        'data-desc': allowedParams[i].desc,
                                        'data-control': allowedParams[i]['data-qui']
                                    }).inject(NameSelect);
                                }

                                ParamName = NameSelect;
                            }

                            Content.getElements('input,select').addEvents({
                                keyup: function (event) {
                                    if (event.key === 'enter') {
                                        Confirm.submit();
                                    }
                                }
                            });

                            if (!initialValueSet && typeOf(Param) === 'element') {
                                ParamName.value = Param.get('data-name');
                            }

                            (function () {
                                ParamName.focus();
                            }).delay(800);

                            DescriptionContainer.setStyle('display', 'none');
                            onParamChange();
                        },

                        onSubmit: function (Confirm) {
                            var Content = Confirm.getContent(),
                                ParamName = Content.getElement('[name="paramName"]'),
                                ParamValue = Content.getElement('[name="paramValue"]');

                            if (ParamName.value === '') {
                                ParamName.focus();
                                return;
                            }

                            if (ParamValue.value === '') {
                                ParamValue.focus();
                                return;
                            }

                            // if no duplicate params allowed, then check it
                            if (!self.getAttribute('allowDuplicate') && (!Param || Param.get('data-name') !== ParamName.value)) {
                                var result = self.$ParamList.getElements(
                                    '[data-name="' + ParamName.value + '"]'
                                );

                                if (result.length) {
                                    QUI.getMessageHandler(function (MH) {
                                        ParamName.focus();

                                        MH.addError(
                                            'Doppelte Parameter sind nicht erlaubt',
                                            ParamName
                                        );
                                    });

                                    return;
                                }
                            }

                            if (typeOf(Param) === 'element') {
                                // update param
                                Param.set('data-name', ParamName.value);
                                Param.set('data-value', ParamValue.value);

                                Param.set(
                                    'html',
                                    ParamName.value + ' : ' + ParamValue.value
                                );

                            } else {
                                // create param
                                self.addParam(
                                    ParamName.value,
                                    ParamValue.value
                                );
                            }

                            Confirm.close();
                        }
                    }
            }).open();
        }
    });
});
