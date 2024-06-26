const time_retry_seconds = 3;
const number_of_retries = 20;

let intervalId;
let monei_counter = 0;
let url_monei_ajax = new URL(monei_index_url);

function executeEveryNsecondsNtimes(func, interval, times) {
    intervalId = setInterval(function () {
        func();
        if (++monei_counter === times || number_of_retries == 0) {
            clearInterval(intervalId);
        }
    }, interval);
}

function checkMoneiStatus() {
    setCountDown();
    let params = {
        fc: 'module',
        module: 'monei',
        controller: 'check',
        action: 'payment',
        cart_id: monei_cart_id,
        order_id: monei_order_id,
        monei_id: monei_id,
        ajax: true,
        monei_counter: monei_counter
    };

    url_monei_ajax.search = new URLSearchParams(params).toString();
    fetch(url_monei_ajax, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        }
    })
        .then((response) => response.json())
        .then((data) => {
            // Get the JSON from the response
            if (typeof data.order_exists !== 'undefined' && data.order_exists) {
                clearInterval(intervalId);
                validateMoneiCart(monei_cart_id);
            }
            // If is the last try, then validate the cart
            if (monei_counter == number_of_retries) {
                validateMoneiCart(monei_cart_id);
            }

        }).catch(function (error) {
            document.querySelector(".custom__modal").style.display = 'none';
            clearInterval(intervalId);
            swal(conf_msg_mon1_ko, conf_msg_mon2_ko, conf_mon_icon_ko);
            console.log('Error MONEI: ' + error);
        });
}

function validateMoneiCart(id_cart) {
    let url_monei_ajax = new URL(monei_index_url);
    let params = {
        fc: 'module',
        module: 'monei',
        controller: 'check',
        action: 'convert',
        cart_id: id_cart,
        order_id: monei_order_id,
        monei_id: monei_id,
        ajax: true,
        XDEBUG_SESSION_START: 'xdebug1'
    };

    url_monei_ajax.search = new URLSearchParams(params).toString();
    fetch(url_monei_ajax, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        }
    })
        .then((response) => response.json())
        .then((data) => {
            // Replace the Order ID
            let order_ref = document.getElementById('order_id_span');
            let new_order_ref = document.createElement('span');
            new_order_ref.id = 'order_id_span';
            new_order_ref.innerHTML = data.order_reference;
            order_ref.replaceWith(new_order_ref);

            // Replace HTML content
            let currentDiv = document.getElementById('content');
            let newDiv = document.createElement('div');
            newDiv.classList.add('page-content', 'card', 'card-block');
            newDiv.id = 'content';
            newDiv.innerHTML = data.content;
            currentDiv.replaceWith(newDiv);

            // Time left to show

            swal(conf_msg_mon1_ok, conf_msg_mon2_ok, conf_mon_icon_ok);

        }).catch(function (error) {
            document.querySelector(".custom__modal").style.display = 'none';
            clearInterval(intervalId);
            swal(conf_msg_mon1_ko, conf_msg_mon2_ko, conf_mon_icon_ko);
            console.log('Error MONEI: ' + error);
        });
}

function setCountDown() {
    let timeleft = time_retry_seconds * (number_of_retries - monei_counter);
    let countdown = document.getElementById("countdown");
    let newCountdown = document.createElement('span');
    newCountdown.id = 'countdown';
    let minutes = Math.floor(timeleft / 60);
    // Format minutes with two digits
    minutes = minutes < 10 ? '0' + minutes : minutes;
    // Same with seconds
    let seconds = timeleft - minutes * 60;
    seconds = seconds < 10 ? '0' + seconds : seconds;
    newCountdown.innerHTML = minutes + ':' + seconds + ' s.';
    countdown.replaceWith(newCountdown);
}

document.addEventListener("DOMContentLoaded", function (event) {
    setCountDown();
    executeEveryNsecondsNtimes(checkMoneiStatus, time_retry_seconds * 1000, number_of_retries);
    document.querySelector(".custom__modal").style.display = "block";
});
