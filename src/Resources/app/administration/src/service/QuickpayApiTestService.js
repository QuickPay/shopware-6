const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class QuickpayApiTestService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'quickpay-api-test') {
        super(httpClient, loginService, apiEndpoint);
    }

    testConfig(configData) {
        const headers = this.getBasicHeaders({});

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/verify`,
                configData,
                headers
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}

Application.addServiceProvider('quickpayApiTestService', (container) => {
    const initContainer = Application.getContainer('init');
    return new QuickpayApiTestService(initContainer.httpClient, container.loginService);
});
