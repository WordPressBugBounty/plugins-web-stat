function wts_init() {
    window.wts_data = window.wts_data || {};
    
    if (window.wts_data.is_admin_user === "0" && window.wts_data.alias && window.wts_data.db) {
        window.wts_data.fetched = 1;
    } else if (window.wts_data.is_admin_user === "1" && window.wts_data.alias && window.wts_data.db && window.wts_data.oc_a2) {
        window.wts_data.fetched = 1;
    }

	if (window.wts_data.fetched == 1 && window.wts_data.is_admin_page === "0"){
    	recordHit();
        return;
     }
    
    fetchData().then(function() {
        if (window.wts_data.is_admin_page === "0"){
            recordHit();
        }
    });
}

function fetchData() {
    return fetch(wts_data.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                JSON_data: JSON.stringify(window.wts_data)
            })
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data && Object.keys(data).length > 0) {
                if (window.wts_data.is_admin_user === "1") {
                    sendDataToPHP(data);
                }
                window.wts_data.fetched = true;
                Object.assign(window.wts_data, data);
            }
        })
        .catch(function(error) {
            console.error('Error fetching data:', error);
        });
}

function recordHit() {
    var script = document.createElement('script');
    script.src = 'https://app.ardalio.com/log7.js';
    script.onload = function() {
        var wts_div = document.createElement("div");
        wts_div.setAttribute("id", "wts" + wts_data.alias);
        wts_div.style.textAlign = "center";
        document.body.appendChild(wts_div);
        window.wts7 = {};
        window.wts7.user_id = wts_data.user_id;
        window.wts7.user_info = wts_data.user_info;
        window.wts7.is_owner = wts_data.is_admin_user;
        window.wts7.origin = "wordPress";
        wtslog7(wts_data.alias, wts_data.db);
    };
    document.head.appendChild(script);
}

function sendDataToPHP(data) {
    fetch(wts_data.php_ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'handle_ajax_data',
                nonce: wts_data.nonce,
                data: JSON.stringify(data)
            })
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(response) {
            if (! response.success) {
				send_debug_message('Error sending data to PHP', response.data);
            }
        })
        .catch(function(error) {
            send_debug_message('Error sending data to PHP', error);
        });
}

function send_debug_message(e_text, e_object) {
	console.log(e_text, e_object);
	var errData = new URLSearchParams();
	errData.append('origin', 'WP Plugin v.'+window.wts_data.version);
	errData.append('e_text', e_text);
	if (e_object) {
       errData.append('e_object', e_object.toString());
    }
	errData.append('url', document.URL);
	navigator.sendBeacon("https://app.ardalio.com/print.pl", errData);
	return;
}

if (document.readyState !== 'loading') {
    wts_init();
} else {
    document.addEventListener('DOMContentLoaded', wts_init);
}

