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
            refundedTotal: 0,
            available: null,
            isCapturing: false,
            captureSuccessful: false,
        };
    },
    async created() {
        this.$emit('loading-change', true);
        this.repository = this.repositoryFactory.create('order');

        await this.quickpayApiService.updateResponse({
            'orderId': this.orderId
        });

        const criteria = new Shopware.Data.Criteria();
        criteria.addAssociation('stateMachineState');

        this.repository.get(this.orderId, Shopware.Context.api, criteria).then(entity => {
            this.order = entity;
            const orderResponse = this.order?.customFields?.quickpay_response;

            if (orderResponse) {
                this.quickpayResponse = JSON.parse(orderResponse);
                this.$emit('loading-change', false);

                return Promise.resolve();
            } else {
                this.$emit('loading-change', false);
            }
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
            if (!this.order || !this.quickpayResponse) {
                return [];
            }

            let secure3d = '-';
            if (this.quickpayResponse.metadata && 'is_3d_secure' in this.quickpayResponse.metadata) {
                secure3d = this.quickpayResponse.metadata.is_3d_secure
            }
            let brand = '-';
            if (this.quickpayResponse.metadata && 'brand' in this.quickpayResponse.metadata) {
                brand = this.quickpayResponse.metadata.brand
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

            const isValid = (operation) => (operation['qp_status_msg'] || null) === 'Approved' ||
                (operation['aq_status_msg'] || null) === 'Approved';

            this.quickpayResponse.operations.forEach((operation) => {
                if(!isValid(operation)){
                    return;
                }

                if (operation.type === "authorize" || operation.type === "recurring") {
                    this.authorized = operation.amount;
                }

                if (operation.type === "capture") {
                    this.captured += operation.amount;
                }

                if (operation.type === 'refund') {
                    this.refundedTotal += operation.amount;
                }
            });

            this.available = (this.authorized - this.captured) / 100;

            return [{
                attribute: 'Transaction Status',
                value: this.order.stateMachineState?.name || '-'
            }, {
                attribute: 'Order Number',
                value: this.order.orderNumber
            }, {
                attribute: 'Acquirer',
                value: acquirer
            }, {
                attribute: 'Type',
                value: type
            }, {
                attribute: 'Brand',
                value: brand
            }, {
                attribute: 'Currency code',
                value: currencyCode
            }, {
                attribute: '3D Secure',
                value: secure3d
            }, {
                attribute: 'Amount to authorize',
                value: (amount / 100).toFixed(2)
            }, {
                attribute: 'Authorized amount',
                value: (this.authorized / 100).toFixed(2)
            }, {
                attribute: 'Captured amount',
                value: (this.captured / 100).toFixed(2)
            }, {
                attribute: 'Refunded amount',
                value: (this.refundedTotal / 100).toFixed(2)
            }];
        },

        capture() {
            this.isCapturing = true;

            this.quickpayApiService.capture({
                orderId: this.orderId,
                amount: (this.amount * 100)
            }).then((response) => {
                if (response.success) {
                    const messageSaveSuccess = this.$tc(
                        'quickpay.capture.messageSaveSuccess',
                        0,
                        {name: "Quickpay Capture"}
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

                    this.captureSuccessful = true;
                } else if (!response.success) {
                    const messageSaveError = this.$tc(
                        'quickpay.capture.messageSaveError',
                        0,
                        {name: "Quickpay Capture"}
                    );

                    this.createNotificationError({
                        title: this.$tc('global.default.error'),
                        message: messageSaveError
                    });
                }
            })
                .finally(() => {
                    this.isCapturing = false;
                });
        }
    }
});
