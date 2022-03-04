import ApiService from 'src/core/service/api.service';

export default class QuickpayApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'quickpay') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'quickpayService';
    }
    
    async capture(id, amount) {
        return await this.httpClient.post('_action/quickpay/capture', {
            id: id,
            amount: amount
        }, {
            headers: this.getBasicHeaders()
        });
    }
    
    async cancel(id) {
        return await this.httpClient.post('_action/quickpay/cancel', {
            id: id,
        }, {
            headers: this.getBasicHeaders()
        });
    }
    
    async refund(id, amount) {
        return await this.httpClient.post('_action/quickpay/refund', {
            id: id,
            amount: amount
        }, {
            headers: this.getBasicHeaders()
        });
    }
    
    async refresh(id) {
        return await this.httpClient.post('_action/quickpay/refresh', {
            id: id,
        }, {
            headers: this.getBasicHeaders()
        });
    }
}