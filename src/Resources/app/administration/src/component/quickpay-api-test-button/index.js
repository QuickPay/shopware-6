const { Component, Mixin } = Shopware;
import template from './quickpay-api-test-button.html.twig';

Component.register('quickpay-api-test-button', {
    template: template,

    props: ['btnLabel'],
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
            let systemConfigComponent = this.$parent;
            while (!systemConfigComponent.hasOwnProperty('actualConfigData')) {
                systemConfigComponent = systemConfigComponent.$parent
            }
            let selectedSalesChannelId = systemConfigComponent.currentSalesChannelId;
            let config = systemConfigComponent.actualConfigData;
            // Properties NOT set in the sales channel config will be inherited from default config.
            return Object.assign({}, config.null, config[selectedSalesChannelId]);
        }
    },

    methods: {
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        testApi() {
            this.isLoading = true;
            this.quickpayApiService
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

                    setTimeout(() => {
                        this.isLoading = false;
                    }, 2500);
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
