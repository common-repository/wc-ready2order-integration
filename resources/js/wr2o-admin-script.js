
jQuery(function ($) {

    function setButtonText(button_id, response) {
        if (response.button_text) {
            $('#' + button_id).text(`${response.button_text}`);
        }
    }

    function removeStatus(button_id, response) {
        var message = jQuery(`.${button_id}_status`);
        message.remove()
    }

    $('#wr2o_authorize').on('click', function (e) {
        e.preventDefault()
        var user_email = $('#wr2o_user_email')
        jQuery.post(ajaxurl, { action: 'wr2o_connect', user_email: user_email.val(), nonce: wr2o_admin_data.nonce }, function (response) {
            if (response.state == 'error') {
                alert(response.message)
            } else {
                if (confirm(response.message)) {
                    window.location.replace(response.state)
                }
            }
        })
    })

    $('#wr2o_refresh').on('click', function (e) {
        e.preventDefault()
        jQuery.post(ajaxurl, { action: 'wr2o_refresh', nonce: wr2o_admin_data.nonce }, function (response) {
          window.location.reload();
        })
      })

    // Dismiss notice
    $('.notice-dismiss').on('click', function (e) {
        console.log('click');
        var is_ready2order_notice = jQuery(e.target).parents('div').hasClass('ready2order_notice');
        if (is_ready2order_notice) {
            var parents = jQuery(e.target).parent().prop('className');
            jQuery.post(ajaxurl, { action: 'ready2order_clear_notice', nonce: wr2o_admin_data.nonce, parents: parents }, function (response) { })
        }
    });


    function displayMessage(button_id, response) {
        var message = jQuery(`<div id="message" class="updated"><p>${response.message}</p></div>`)
        message.hide()
        message.insertBefore(jQuery(`#${button_id}_titledesc`))
        message.fadeIn('fast', function () {
            setTimeout(function () {
                message.fadeOut('fast', function () {
                    message.remove()
                })
            }, 5000)
        })
    }

    function displayStatus(button_id, response) {
        if (response.status_message) {
            var message = jQuery(`.${button_id}_status`);
            if (0 !== message.length) {
                message.html('<p>' + response.status_message + '</p>');
            } else {
                var message = jQuery(`<div id="message" class="updated ${button_id}_status"><p>${response.status_message}</p></div>`);
                message.hide();
                message.insertBefore(jQuery(`#${button_id}_titledesc`));
                message.fadeIn('fast');
            }
        }
    }


    function checkQueue(button_id) {
        jQuery.post(ajaxurl, { action: 'wr2o_processing_button', id: button_id, nonce: wr2o_admin_data.nonce, task: 'check' }, function (response) {
            setButtonText(button_id, response);
            displayStatus(button_id, response)
            if (!response.ready) {
                setTimeout(function () { checkQueue(button_id); }, 3000);
            } else {
                removeStatus(button_id, response);
                displayMessage(button_id, response);
            }
        });
    }

    $('.wr2o_processing_button').on('click', function (e) {
        e.preventDefault()
        var button_id = $(this).attr('id');
        $.post(ajaxurl, { action: 'wr2o_processing_button', id: button_id, nonce: wr2o_admin_data.nonce, task: 'start' }, function (response) {
            displayMessage(button_id, response);
            setButtonText(button_id, response);
            if (!response.ready) {
                checkQueue(button_id);
            }
        })
    });

    $(document).ready(function () {
        $(window).load(function () {
            let processing_status = $('.wr2o_processing_status');
            if (processing_status.length) {
                checkQueue(processing_status.attr('name'));
            };
        })
    });

});
