const { Component, Mixin } = Shopware;
import template from './quickpay-api-text-button.html.twig';

Component.register('quickpay-api-test-button', {
    template: template,

    props: ['label'],
    inject: ['quickpayApiService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
        };
    },

    computed: {
        pluginConfig() {
            return this.$parent.$parent.$parent.actualConfigData.null;
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        check() {
            this.isLoading = true;
            this.quickpayApiService.check(this.pluginConfig).then((res) => {
                if (res.success) {
                    this.isSaveSuccessful = true;
                    this.createNotificationSuccess({
                        title: this.$tc('quickpay-api-test-button.title'),
                        message: this.$tc('quickpay-api-test-button.success')
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('quickpay-api-test-button.title'),
                        message: this.$tc('quickpay-api-test-button.error')
                    });
                }

                this.isLoading = false;
            });
        }
    }
})
