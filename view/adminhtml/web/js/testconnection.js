define([
    'ko',
    'uiComponent',
    'jquery'
], function (ko, Component, $) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'MageZero_CloudflareR2/testconnection',
            connectionFailedMessage: 'Connection test failed.',
            url: '',
            success: false,
            message: '',
            visible: false,
            loading: false,
            fieldMapping: {}
        },

        initObservable: function () {
            this._super()
                .observe([
                    'success',
                    'message',
                    'visible',
                    'loading'
                ]);

            return this;
        },

        initialize: function () {
            this._super();
            this.messageClass = ko.computed(function () {
                return 'message-validation message message-' + (this.success() ? 'success' : 'error');
            }, this);

            if (this.success === false) {
                // Don't show failure message on initial load - let user click the button
            }
        },

        showMessage: function (success, message) {
            this.message(message);
            this.success(success);
            this.visible(true);
        },

        getFieldValue: function (fieldId) {
            var element = document.getElementById(fieldId);
            if (!element) {
                return '';
            }
            if (element.type === 'checkbox') {
                return element.checked ? '1' : '0';
            }
            return element.value || '';
        },

        testConnection: function () {
            var self = this,
                data = {};

            // Gather field values from the form
            Object.keys(this.fieldMapping).forEach(function (key) {
                data[key] = self.getFieldValue(self.fieldMapping[key]);
            });

            this.visible(false);
            this.loading(true);

            $.ajax({
                type: 'POST',
                url: this.url,
                dataType: 'json',
                data: data,
                success: function (response) {
                    self.loading(false);
                    self.showMessage(response.success === true, response.message);
                },
                error: function () {
                    self.loading(false);
                    self.showMessage(false, self.connectionFailedMessage);
                }
            });
        }
    });
});
