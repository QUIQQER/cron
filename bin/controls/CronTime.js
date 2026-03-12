/**
 * Control for setting up cron timetables in a user-friendly fashion
 */
define('package/quiqqer/cron/bin/controls/CronTime', [

    'qui/controls/Control',
    'qui/controls/loader/Loader',
    'qui/controls/buttons/Select',

    'Locale',
    'Ajax',

    'css!package/quiqqer/cron/bin/controls/CronTime.css'

], function (QUIControl, QUILoader, QUISelect, QUILocale, QUIAjax) {
    "use strict";

    var lg = 'quiqqer/cron';

    return new Class({

        Extends: QUIControl,
        Type: 'package/quiqqer/cron/bin/controls/CronTime',

        Binds: [
            '$loadIntervalOptions',
            '$change',
            '$showCronStyleInput'
        ],

        initialize: function (options) {
            this.parent(options);

            this.$CronTime = null;
            this.$IntervalSelect = null;
            this.$OptionsElm = null;
            this.Loader = new QUILoader();

            this.$SelectMinute = null;
            this.$SelectHour = null;
            this.$SelectDay = null;
            this.$SelectMonth = null;
            this.$SelectDayOfWeek = null;

            this.$minute = '*';
            this.$hour = '*';
            this.$day = '*';
            this.$month = '*';
            this.$dayofweek = '*';

            this.$interval = false;
        },

        /**
         * create the domnode element
         *
         * @return {HTMLDivElement}
         */
        create: function () {
            var i, len, label;
            var self = this;

            this.$Elm = new Element('div', {
                'class': 'quiqqer-cron-crontime',
                html: '<div class="quiqqer-cron-crontime-interval">' +
                    '</div>' +
                    '<div class="quiqqer-cron-crontime-options"></div>'
            });

            //QUILocale.get(lg, 'controls.crontime.label.interval')

            this.$IntervalSelect = new QUISelect({
                'class': 'quiqqer-cron-crontime-interval-select',
                showIcons: false,
                events: {
                    onChange: function (value) {
                        self.$interval = value;
                        self.$loadIntervalOptions();
                    }
                }
            }).inject(
                this.$Elm.getElement(
                    '.quiqqer-cron-crontime-interval'
                )
            );

            this.$OptionsElm = this.$Elm.getElement('.quiqqer-cron-crontime-options');

            var intervals = [
                'everyminute', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'cron'
            ];

            for (i = 0, len = intervals.length; i < len; i++) {
                this.$IntervalSelect.appendChild(
                    QUILocale.get(lg, 'controls.crontime.intervalselect.option.' + intervals[i]),
                    intervals[i],
                    false
                );
            }

            this.$IntervalSelect.setValue('everyminute');

            this.$SelectMinute = new QUISelect({
                'class': 'quiqqer-cron-crontime-number-select',
                showIcons: false
            });

            for (i = 0; i < 60; i++) {
                label = i;

                if (i < 10) {
                    label = "0" + i;
                }

                this.$SelectMinute.appendChild(
                    label,
                    i,
                    false
                );
            }

            this.$SelectHour = new QUISelect({
                'class': 'quiqqer-cron-crontime-number-select',
                showIcons: false
            });

            for (i = 0; i < 24; i++) {
                label = i;

                if (i < 10) {
                    label = "0" + i;
                }

                this.$SelectHour.appendChild(
                    label,
                    i,
                    false
                );
            }

            this.$SelectDay = new QUISelect({
                'class': 'quiqqer-cron-crontime-number-select',
                showIcons: false
            });

            for (i = 0; i < 31; i++) {
                this.$SelectDay.appendChild(
                    i + 1 + ".",
                    i + 1,
                    false
                );
            }

            this.$SelectMonth = new QUISelect({
                'class': 'quiqqer-cron-crontime-month-select',
                showIcons: false
            });

            for (i = 0; i < 12; i++) {
                this.$SelectMonth.appendChild(
                    QUILocale.get(lg, 'controls.crontime.month.' + (i + 1)),
                    i + 1,
                    false
                );
            }

            this.$SelectDayOfWeek = new QUISelect({
                'class': 'quiqqer-cron-crontime-dayofweek-select',
                showIcons: false
            });

            var daysOfWeek = [
                'su', 'mo', 'tu', 'we', 'th', 'fr', 'sa'
            ];

            for (i = 0, len = daysOfWeek.length; i < len; i++) {
                this.$SelectDayOfWeek.appendChild(
                    QUILocale.get(lg, 'controls.crontime.dayofweek.' + daysOfWeek[i]),
                    i,
                    false
                );
            }

            return this.$Elm;
        },

        /**
         * Load option inputs depending on interval
         */
        $loadIntervalOptions: function () {
            this.$OptionsElm.set('html', '');

            switch (this.$interval) {
                case 'everyminute':
                    this.$change();
                    break;

                case 'hourly':
                    this.$OptionsElm.set(
                        'html',
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.minute') + '</span>' +
                        '<div class="quiqqer-cron-crontime-hour"></div>'
                    );

                    this.$SelectMinute.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-hour'
                        )
                    );

                    if (this.$isNumeric(this.$minute)) {
                        this.$SelectMinute.setValue(this.$minute);
                    } else {
                        this.$SelectMinute.setValue(0);
                    }
                    break;

                case 'daily':
                    this.$OptionsElm.set(
                        'html',
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.at') + '</span>' +
                        '<div class="quiqqer-cron-crontime-hour"></div>' +
                        '<span>:</span>' +
                        '<div class="quiqqer-cron-crontime-minute"></div>'
                    );

                    this.$SelectHour.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-hour'
                        )
                    );

                    if (this.$isNumeric(this.$hour)) {
                        this.$SelectHour.setValue(this.$hour);
                    } else {
                        this.$SelectHour.setValue(0);
                    }

                    this.$SelectMinute.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-minute'
                        )
                    );

                    if (this.$isNumeric(this.$minute)) {
                        this.$SelectMinute.setValue(this.$minute);
                    } else {
                        this.$SelectMinute.setValue(0);
                    }
                    break;

                case 'weekly':
                    this.$OptionsElm.set(
                        'html',
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.on') + '</span>' +
                        '<div class="quiqqer-cron-crontime-dayofweek"></div>' +
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.at') + '</span>' +
                        '<div class="quiqqer-cron-crontime-hour"></div>' +
                        '<span>:</span>' +
                        '<div class="quiqqer-cron-crontime-minute"></div>'
                    );

                    this.$SelectDayOfWeek.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-dayofweek'
                        )
                    );

                    if (this.$isNumeric(this.$dayofweek)) {
                        this.$SelectDayOfWeek.setValue(this.$dayofweek);
                    } else {
                        this.$SelectDayOfWeek.setValue(0);
                    }

                    this.$SelectHour.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-hour'
                        )
                    );

                    if (this.$isNumeric(this.$hour)) {
                        this.$SelectHour.setValue(this.$hour);
                    } else {
                        this.$SelectHour.setValue(0);
                    }

                    this.$SelectMinute.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-minute'
                        )
                    );

                    if (this.$isNumeric(this.$minute)) {
                        this.$SelectMinute.setValue(this.$minute);
                    } else {
                        this.$SelectMinute.setValue(0);
                    }
                    break;

                case 'monthly':
                    this.$OptionsElm.set(
                        'html',
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.on') + '</span>' +
                        '<div class="quiqqer-cron-crontime-day"></div>' +
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.at') + '</span>' +
                        '<div class="quiqqer-cron-crontime-hour"></div>' +
                        '<span>:</span>' +
                        '<div class="quiqqer-cron-crontime-minute"></div>'
                    );

                    this.$SelectDay.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-day'
                        )
                    );

                    if (this.$isNumeric(this.$day)) {
                        this.$SelectDay.setValue(this.$day);
                    } else {
                        this.$SelectDay.setValue(1);
                    }

                    this.$SelectHour.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-hour'
                        )
                    );

                    if (this.$isNumeric(this.$hour)) {
                        this.$SelectHour.setValue(this.$hour);
                    } else {
                        this.$SelectHour.setValue(0);
                    }

                    this.$SelectMinute.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-minute'
                        )
                    );

                    if (this.$isNumeric(this.$minute)) {
                        this.$SelectMinute.setValue(this.$minute);
                    } else {
                        this.$SelectMinute.setValue(0);
                    }
                    break;

                case 'yearly':
                    this.$OptionsElm.set(
                        'html',
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.on') + '</span>' +
                        '<div class="quiqqer-cron-crontime-day"></div>' +
                        '<div class="quiqqer-cron-crontime-month"></div>' +
                        '<span>' + QUILocale.get(lg, 'controls.crontime.label.at') + '</span>' +
                        '<div class="quiqqer-cron-crontime-hour"></div>' +
                        '<span>:</span>' +
                        '<div class="quiqqer-cron-crontime-minute"></div>'
                    );

                    this.$SelectDay.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-day'
                        )
                    );

                    if (this.$isNumeric(this.$day)) {
                        this.$SelectDay.setValue(this.$day);
                    } else {
                        this.$SelectDay.setValue(1);
                    }

                    this.$SelectMonth.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-month'
                        )
                    );

                    if (this.$isNumeric(this.$month)) {
                        this.$SelectMonth.setValue(this.$month);
                    } else {
                        this.$SelectMonth.setValue(1);
                    }

                    this.$SelectHour.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-hour'
                        )
                    );

                    if (this.$isNumeric(this.$hour)) {
                        this.$SelectHour.setValue(this.$hour);
                    } else {
                        this.$SelectHour.setValue(0);
                    }

                    this.$SelectMinute.inject(
                        this.$OptionsElm.getElement(
                            '.quiqqer-cron-crontime-minute'
                        )
                    );

                    if (this.$isNumeric(this.$minute)) {
                        this.$SelectMinute.setValue(this.$minute);
                    } else {
                        this.$SelectMinute.setValue(0);
                    }
                    break;

                case 'cron':
                    var inputs = [
                        'minute', 'hour', 'day', 'month', 'dayofweek'
                    ];

                    for (var i = 0, len = inputs.length; i < len; i++) {
                        var Label = new Element('label', {
                            'class': 'quiqqer-cron-crontime-cron-label',
                            html: '<span>' +
                                QUILocale.get(lg, 'controls.crontime.label.' + inputs[i]) +
                                '</span>'
                        }).inject(this.$OptionsElm);

                        var Input = new Element('input', {
                            'class': 'quiqqer-cron-crontime-cron-input',
                            'data-type': inputs[i],
                            type: 'text'
                        }).inject(Label, 'top');

                        switch (inputs[i]) {
                            case 'minute':
                                Input.value = this.$minute;
                                break;

                            case 'hour':
                                Input.value = this.$hour;
                                break;

                            case 'day':
                                Input.value = this.$day;
                                break;

                            case 'month':
                                Input.value = this.$month;
                                break;

                            case 'dayofweek':
                                Input.value = this.$dayofweek;
                                break;
                        }
                    }

                    this.$Elm.getElement(
                        '.quiqqer-cron-crontime-cron-input'
                    ).focus();

                    break;
            }
        },

        /**
         * Fires change event with current value
         */
        $change: function () {
            this.fireEvent(
                'change',
                [this.$minute, this.$hour, this.$day, this.$month, this.$dayofweek, this]
            );
        },

        /**
         * Set value to control
         *
         * @param minute
         * @param hour
         * @param day
         * @param month
         * @param dayofweek
         */
        setValue: function (minute, hour, day, month, dayofweek) {
            this.$minute = minute;
            this.$hour = hour;
            this.$day = day;
            this.$month = month;
            this.$dayofweek = dayofweek;

            if (minute.match(/[^\d\*]/gi) ||
                hour.match(/[^\d\*]/gi) ||
                day.match(/[^\d\*]/gi) ||
                month.match(/[^\d\*]/gi) ||
                dayofweek.match(/[^\d\*]/gi)
            ) {
                this.$showCronStyleInput();
                return;
            }

            if (minute == '*' &&
                hour == '*' &&
                day == '*' &&
                month == '*' &&
                dayofweek == '*') {
                this.$IntervalSelect.setValue('everyminute');
                return;
            }

            if (minute != '*' &&
                hour == '*' &&
                day == '*' &&
                month == '*' &&
                dayofweek == '*') {
                this.$IntervalSelect.setValue('hourly');
                this.$SelectMinute.setValue(minute);
                return;
            }

            if (minute != '*' &&
                hour != '*' &&
                day == '*' &&
                month == '*' &&
                dayofweek == '*') {
                this.$IntervalSelect.setValue('daily');
                this.$SelectMinute.setValue(minute);
                this.$SelectHour.setValue(hour);
                return;
            }

            if (minute != '*' &&
                hour != '*' &&
                day == '*' &&
                month == '*' &&
                dayofweek != '*') {
                this.$IntervalSelect.setValue('weekly');
                this.$SelectMinute.setValue(minute);
                this.$SelectHour.setValue(hour);
                this.$SelectDayOfWeek.setValue(dayofweek);
                return;
            }

            if (minute != '*' &&
                hour != '*' &&
                day != '*' &&
                month == '*' &&
                dayofweek == '*') {
                this.$IntervalSelect.setValue('monthly');
                this.$SelectMinute.setValue(minute);
                this.$SelectHour.setValue(hour);
                this.$SelectDay.setValue(day);
                return;
            }

            if (minute != '*' &&
                hour != '*' &&
                day != '*' &&
                month != '*' &&
                dayofweek == '*') {
                this.$IntervalSelect.setValue('yearly');
                this.$SelectMinute.setValue(minute);
                this.$SelectHour.setValue(hour);
                this.$SelectDay.setValue(day);
                this.$SelectMonth.setValue(month);
                return;
            }

            this.$showCronStyleInput();
        },

        /**
         * Show cron style type input
         */
        $showCronStyleInput: function () {
            var m = this.$minute,
                h = this.$hour,
                d = this.$day,
                mo = this.$month,
                dw = this.$dayofweek;

            this.$IntervalSelect.setValue('cron');

            var cronStyleInputs = this.$Elm.getElements(
                '.quiqqer-cron-crontime-cron-input'
            );


            for (var i = 0, len = cronStyleInputs.length; i < len; i++) {
                var Elm = cronStyleInputs[i];

                switch (Elm.getProperty('data-type')) {
                    case 'minute':
                        Elm.value = m;
                        break;

                    case 'hour':
                        Elm.value = h;
                        break;

                    case 'day':
                        Elm.value = d;
                        break;

                    case 'month':
                        Elm.value = mo;
                        break;

                    case 'dayofweek':
                        Elm.value = dw;
                        break;
                }
            }

            this.$minute = m;
            this.$hour = h;
            this.$day = d;
            this.$month = mo;
            this.$dayofweek = dw;
        },

        /**
         * Check if a string is numeric
         *
         * @param {String} str
         * @return {boolean}
         */
        $isNumeric: function (str) {
            return /^\d+$/.test(str);
        },

        /**
         * Get current cron time values
         *
         * @return {Object}
         */
        getValue: function () {
            var CronTime = {
                minute: '*',
                hour: '*',
                day: '*',
                month: '*',
                dayOfWeek: '*'
            };

            switch (this.$interval) {
                case 'everyminute':
                    // nothing
                    break;

                case 'hourly':
                    CronTime.minute = this.$SelectMinute.getValue();
                    break;

                case 'daily':
                    CronTime.minute = this.$SelectMinute.getValue();
                    CronTime.hour = this.$SelectHour.getValue();
                    break;

                case 'weekly':
                    CronTime.minute = this.$SelectMinute.getValue();
                    CronTime.hour = this.$SelectHour.getValue();
                    CronTime.dayOfWeek = this.$SelectDayOfWeek.getValue();
                    break;

                case 'monthly':
                    CronTime.minute = this.$SelectMinute.getValue();
                    CronTime.hour = this.$SelectHour.getValue();
                    CronTime.day = this.$SelectDay.getValue();
                    break;

                case 'yearly':
                    CronTime.minute = this.$SelectMinute.getValue();
                    CronTime.hour = this.$SelectHour.getValue();
                    CronTime.day = this.$SelectDay.getValue();
                    CronTime.month = this.$SelectMonth.getValue();
                    break;

                case 'cron':
                    CronTime = {
                        minute: this.$Elm.getElement('input.quiqqer-cron-crontime-cron-input[data-type="minute"]').value,
                        hour: this.$Elm.getElement('input.quiqqer-cron-crontime-cron-input[data-type="hour"]').value,
                        day: this.$Elm.getElement('input.quiqqer-cron-crontime-cron-input[data-type="day"]').value,
                        month: this.$Elm.getElement('input.quiqqer-cron-crontime-cron-input[data-type="month"]').value,
                        dayOfWeek: this.$Elm.getElement('input.quiqqer-cron-crontime-cron-input[data-type="dayofweek"]').value
                    };
                    break;
            }

            return CronTime;
        }
    });
});
