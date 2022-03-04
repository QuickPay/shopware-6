import template from './sw-order-quickpay-cancel-modal.html.twig';
import './sw-order-quickpay-cancel-modal.scss';

const { Component, Mixin } = Shopware;

Component.register('sw-order-quickpay-cancel-modal', {
    template,
    inject: [
        'quickpayApiService',
    ],
    mixins: [
        Mixin.getByName('notification')
    ],
    props: {
        payment: {
            type: Object,
            required: true
        },
    },
    data() {
        return {
            isLoading: false,
        };
    },
    computed: {
    },
    created() {
        this.createdComponent();
    },
    methods: {
        createdComponent() {
        },
        async onConfirm() {
            try {
                this.isLoading = true;
                const response = await this.quickpayApiService.cancel(this.payment.id);
                this.$emit('success');
                this.createNotificationSuccess({
                    message: this.$tc('sw-order.quickpay.cancelRequestedNotification')
                })
                this.isLoading = false;
            } catch (e) {
                this.$emit('fail');
                this.createNotificationError({
                    message: this.$tc('sw-order.quickpay.cancelRequestFailedNotification')
                })
                this.isLoading = false;
            }
    
        }
    
    }

});
