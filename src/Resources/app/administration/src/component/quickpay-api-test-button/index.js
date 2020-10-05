const { Component, Mixin } = Shopware;
import template from './quickpay-api-test-button.html.twig';

Component.register('quickpay-api-test-button', {
    template: template,

    props: ['label'],
    inject: ['quickpayApiTestService'],

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
            const configData = this.$parent.$parent.$parent.actualConfigData.null;

            return {
                quickpayApiKey: configData['WexoQuickpay.config.quickpayApiKey'],
                mobilepayId: configData['WexoQuickpay.config.mobilepayId']
            };
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        testApi() {
            this.isLoading = true;
            this.quickpayApiTestService
                .testConfig(this.pluginConfig)
                .then((res) => {
                    if (res.isValid) {
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
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: this.$tc('quickpay-api-test-button.title'),
                        message: this.$tc('quickpay-api-test-button.error')
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        }
    }
})
