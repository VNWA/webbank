// Cấu hình axios singleton — mọi `import axios from 'axios'` dùng chung defaults sau khi app import `./bootstrap` trước.
import axios from 'axios';

declare global {
    interface Window {
        axios: typeof axios;
    }
}

axios.defaults.headers.common['Accept'] = 'application/json';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

window.axios = axios;
