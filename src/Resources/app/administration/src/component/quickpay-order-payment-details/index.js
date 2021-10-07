import template from './quickpay-order-payment-details.html.twig';

const {Component, Mixin} = Shopware;

Component.register('quickpay-order-payment-details', {
    template,

    inject: [
        'repositoryFactory',
        'quickpayApiService'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        orderId: {
            type: String,
            required: true
        },
        title: {
            type: String,
            required: false
        },
        isLoading: {
            type: Boolean,
            required: false,
            default: false
        },
        isEditing: {
            type: Boolean,
            required: true
        },
    },
    data() {
        return {
            order: null,
            quickpayResponse: null,
            repository: null,
            amount: null,
            authorized: 0,
            captured: 0,
            available: null
        };
    },
    async created() {
        this.$emit('loading-change', true);
        this.repository = this.repositoryFactory.create('order');

        await this.quickpayApiService.updateResponse({
            'orderId': this.orderId
        });

        this.repository.get(this.orderId, Shopware.Context.api).then(entity => {
            this.order = entity;
            this.quickpayResponse = JSON.parse(this.order.customFields.quickpay_response);
            this.$emit('loading-change', false);

            return Promise.resolve();
        });
    },
    computed: {
        orderColumns() {
            return this.getOrderColumns();
        },
        dataSource() {
            return this.getDataSource();
        },
    },
    methods: {
        getOrderColumns() {
            return [{
                label: 'Attribute',
                property: 'attribute',
                rawData: true
            }, {
                label: 'Value',
                property: 'value',
                rawData: true
            }];
        },
        getDataSource() {
            if (!this.order) {
                return [];
            }

            let secure3d = '-';
            if (this.quickpayResponse.metadata && 'is_3d_secure' in this.quickpayResponse.metadata) {
                secure3d = this.quickpayResponse.metadata.is_3d_secure
            }
            let acquirer = '-';
            if (this.quickpayResponse && 'acquirer' in this.quickpayResponse) {
                acquirer = this.quickpayResponse.acquirer
            }
            let type = '-';
            if (this.quickpayResponse && 'type' in this.quickpayResponse) {
                type = this.quickpayResponse.type
            }
            let currencyCode = '-';
            if (this.quickpayResponse && 'currency' in this.quickpayResponse) {
                currencyCode = this.quickpayResponse.currency;
            }
            let amount = 0;
            if (this.quickpayResponse && this.quickpayResponse.link) {
                amount = this.quickpayResponse.link.amount ? this.quickpayResponse.link.amount : amount
            }

            this.quickpayResponse.operations.forEach((operation) => {
                if (operation.type == "authorize") {
                    this.authorized = operation.amount;
                }

                if (operation.type == "capture") {
                    this.captured += operation.amount;
                }
            });

            this.available = (this.authorized - this.captured) / 100;

            this.amount = ((this.authorized - this.captured) / 100).toFixed(2);

            return [{
                id: 'uuid1',
                attribute: 'Transaction Status',
                value: this.order.stateMachineState.name
            }, {
                id: 'uuid2',
                attribute: 'Order Number',
                value: this.order.orderNumber
            }, {
                id: 'uuid3',
                attribute: 'Acquirer',
                value: acquirer
            }, {
                id: 'uuid4',
                attribute: 'Type',
                value: type
            }, {
                id: 'uuid5',
                attribute: 'Currency code',
                value: currencyCode
            }, {
                id: 'uuid6',
                attribute: '3D Secure',
                value: secure3d
            }, {
                id: 'uuid7',
                attribute: 'Amount to authorize',
                value: (amount / 100).toFixed(2)
            }, {
                id: 'uuid8',
                attribute: 'Authorized amount',
                value: (this.authorized / 100).toFixed(2)
            }, {
                id: 'uuid9',
                attribute: 'Captured amount',
                value: (this.captured / 100).toFixed(2)
            }];
        },
        updateAmount(value) {
            this.amount = value;
        },
        capture() {
            this.quickpayApiService.capture({
                orderId: this.orderId,
                amount: (this.amount * 100)
            }).then((response) => {
                if (response.success) {
                    const messageSaveSuccess = this.$tc(
                        'quickpay.capture.messageSaveSuccess',
                        0,
                        { name: "Quickpay Capture" }
                    );

                    this.createNotificationSuccess({
                        title: this.$tc('global.default.success'),
                        message: messageSaveSuccess
                    });

                    this.$router.push({
                        name: 'sw.order.detail',
                        params: {
                            id: this.order.id
                        }
                    });
                } else if(! response.success) {
                    const messageSaveError = this.$tc(
                        'quickpay.capture.messageSaveError',
                        0,
                        { name: "Quickpay Capture" }
                    );

                    this.createNotificationError({
                        title: this.$tc('global.default.error'),
                        message: messageSaveError
                    });
                }
            });
        }
    }
});


