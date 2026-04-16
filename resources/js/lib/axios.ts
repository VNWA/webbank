import axios from 'axios';

const http = axios.create({
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
});

http.defaults.xsrfCookieName = 'XSRF-TOKEN';
http.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

export default http;
