const {ApiService} = Shopware.Classes;

const { Application } = Shopware;

class QuickpayApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'quickpay-api') {
        super(httpClient, loginService, apiEndpoint);
    }

    testConfig(data) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/verify`,
                data,
                headers
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    capture(data) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/capture`,
                data,
                headers
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    updateResponse(data) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/update`,
                data,
                headers
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}

Application.addServiceProvider('quickpayApiService', (container) => {
    const initContainer = Application.getContainer('init');
    return new QuickpayApiService(initContainer.httpClient, container.loginService);
});
