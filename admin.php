<?php
global $wpdb;

$test = new Mrkt_Markight_db($wpdb);

if (isset($_GET['item'])) {

    $item = sanitize_text_field($_GET['item']);
    if ($item == 'reset_all') {
        (new Mrkt_Markight_db($wpdb))->resetSyncDate();
    } elseif ($item == 'logout') {
        update_option(MRKT_PLUGIN_NAME . '_token', '');
    }
}

if (isset($_POST)) {

    if (isset($_POST[MRKT_PLUGIN_NAME . '_username'])) {

        $api = new Mrkt_Markight_api();

        $body = [
            'username' => sanitize_text_field($_POST[MRKT_PLUGIN_NAME . '_username']),
            'password' => sanitize_text_field($_POST[MRKT_PLUGIN_NAME . '_password'])
        ];

        $result = $api->apiRequest(json_encode($body), 'token');

        if ($api->isSuccess($result)) {
            $response = json_decode($result['result'], true);
            update_option(MRKT_PLUGIN_NAME . '_token', $response['token']);
        } else {
            $login_error = 'Wrong username or password';
        }
    }

    if (isset($_POST['sale_status_select'])) {

        $complete_status = sanitize_text_field($_POST['sale_status_select'] ?? '');
        $refunded_status = sanitize_text_field($_POST['refunded_status_select'] ?? '');

        if (!empty($complete_status) and !empty($refunded_status)) {
            $option = explode(",", $complete_status);
            if (in_array($option[1], wc_get_order_statuses())) {
                update_option(MRKT_PLUGIN_NAME . '_sale_status', $option[0]);
                update_option(MRKT_PLUGIN_NAME . '_refunded_status', $refunded_status);
            }
        }
    }
}

$token = get_option(MRKT_PLUGIN_NAME . '_token');
$sale_status = get_option(MRKT_PLUGIN_NAME . '_sale_status');
$refunded_status = get_option(MRKT_PLUGIN_NAME . '_refunded_status');
$last_date = 'Not synced';

if (!empty($date = get_option(MRKT_PLUGIN_NAME . '_sync_date'))) {

    $last_date = round((time() - strtotime($date)) / (60 * 60 * 24));

    if ($last_date < 1) {
        $last_date = "today" . " ( " . $date . " )";
    } else {
        $last_date = $last_date . " days ago " . " ( " . $date . " )";
    }
}

?>

<div class="wrap">

    <a href="https://markight.tech" target="_blank">
        <img class="mt-12" src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/markight-logo.png'; ?>" alt="logo">
    </a>

    <hr/>

    <div id="mrkt_login" class="mt-12 mb-12">
        <div style="display: <?php echo empty($token) ? esc_html('unset') : esc_html('none'); ?>">
            <h2>Login to the Markight account </h2>
            <form method="post" action="admin.php?page=markight_Integration">

                <br>
                <input type="text" class="regular-text" name="<?php echo esc_attr(MRKT_PLUGIN_NAME . "_username") ?>"
                       placeholder="username"/>
                <br/>
                <br>
                <input type="text" class="regular-text" name="<?php echo esc_attr(MRKT_PLUGIN_NAME . "_password") ?>"
                       placeholder="password"/>

                <p class="error-text"> <?php echo $login_error ?? ""; ?></p>

                <?php submit_button("Log in"); ?>

                <p>
                    Dont have an account? <a href="https://markight.com/request-demo/" target="_blank"> request
                        demo </a>
                </p>
            </form>
        </div>
        <div style="display: <?php echo empty($token) ? esc_html('none') : esc_html('unset'); ?>">

            <h2>The store was connected to the <a href="https://app.markight.com" target="_blank">Markight</a> platform!
            </h2>

            <ul class="mt-8">
                <li>1- By logging out of the account, the synchronization of the store data with the Markight will
                    stop.
                </li>
                <li>2- The data synchronization will be resumed by login again.</li>
            </ul>

            <a
                    href="admin.php?page=markight_Integration&item=logout"
                    class="button-primary mt-8 mb-12"
                    style="background-color:#ce1e1e">Logout
            </a>

        </div>
    </div>

    <hr/>

    <div style="display: <?php echo empty($token) ? esc_html('none') : esc_html('unset'); ?>">

        <div id="mrkt_setting" class="mt-12">
            <h2>Sync settings </h2>
            <form method="post" action="admin.php?page=markight_Integration">
                <label>Status of completed orders: </label>
                <select name="sale_status_select">
                    <?php foreach (wc_get_order_statuses() as $key => $value) { ?>
                        <option <?php echo esc_html($sale_status) == esc_html($key) ? 'selected' : '' ?>
                                value="<?php echo esc_attr("$key,$value"); ?>"><?php echo esc_html($value); ?>
                        </option>
                    <?php } ?>
                </select>
                <br/>
                <br/>
                <label>Status of refunded orders: </label>
                <select name="refunded_status_select">
                    <?php foreach (wc_get_order_statuses() as $key => $value) { ?>
                        <option <?php echo esc_html($refunded_status) == esc_html($key) ? 'selected' : '' ?>
                                value="<?php echo esc_attr($key); ?>"><?php echo esc_html($value); ?>
                        </option>
                    <?php } ?>
                </select>
                <?php submit_button("Save settings"); ?>
            </form>
        </div>

        <hr/>

        <div id="mrkt_sync">
            <h2>Sync data </h2>
            <h3 id="title"> Last synced order date: <strong id="sync-date"><?php echo esc_html($last_date) ?></strong>
            </h3>
            <h4 id="sync-date"></h4>
            <ul class="mt-8">
                <li>1- The operation is based on the date of stored orders and <strong>display date is the date of the
                        last invoice completed in your store </strong>.
                </li>
                <li>2- From start to finish operations and display <strong>"End of sync"</strong> keep this page open,
                    otherwise the operation will stop and the synchronization will be done automatically in a few hours.
                </li>
                <li>3- Depending on the number of orders placed in your store, the initial synchronization operation may
                    take 10 minutes or more.
                </li>
                <li>4- After the initial sync and update of the date displayed above, as <strong>automatic</strong> new
                    or updated data in the background will be synced with <strong>Markight</strong></li>
            </ul>
            <br>
            <div id="message"></div>
            <a id="panel"
               href="https://app.markight.com"
               target="_blank"
               class="button-primary mt-8"
               style="display: none">View the Markight panel
            </a>
            <div id="loader" class="loader mt-12"></div>
            <br/>
            <div class="mb-12">
                <button id="ajaxsync" class="button-primary">Start operation</button>
                <button id="stopajaxsync" class="button-primary" style="background-color:#ce1e1e ; display: none">Stop
                    the operation
                </button>
            </div>
            <br/>
            <br/>

        </div>

        <hr/>

        <div id="mrkt_reset_log" class="mt-8">
            <h2>Reset sync date </h2>
            <ul class="mt-8 mb-12 ">
                <li>1- Clear the latest sync history and recorded errors.</li>
                <li>2- The synchronization date is set to non-synchronized and the synchronization operation will start
                    from the first registered invoice.
                </li>
                <li>3- <strong>This will not delete the data in the Markight panel and only the old data will be
                        resent </strong>.
                </li>
            </ul>
            <br/>
            <a href="admin.php?page=markight_Integration&item=reset_all" class="button-primary"
               style="background-color:#ce1e1e">Reset</a>
        </div>

    </div>

    <script>
        const $ = jQuery;
        let sync_locked = true;

        const data = {
            'action': 'mrkt_markight_sync',
            'item': 'sync'
        };

        function sync_data() {
            if (!sync_locked) {
                jQuery.post('admin-ajax.php', data, function (response) {
                    let res = JSON.parse(response)
                    if (res['stop']) {
                        $('#message').html('<h2>' + res['message'] + '<h2/>');
                        $('#loader').fadeOut(200);
                        $('#panel').show();
                        $('#ajaxsync').hide();
                        $('#stopajaxsync').hide();
                        sync_locked = true;
                    } else {
                        if (!sync_locked) {
                            $('#message')
                                .append('<br>')
                                .append(res['message'])
                                .append("   ===>  ")
                                .append(res['state']);
                            $('#sync-date').html(res['date']);
                        }
                        sync_data();
                    }
                }).fail(function (response) {
                    alert('<textarea>' + JSON.stringify(response) + '</textarea>');
                    $('#loader').fadeOut(200);
                });
            }
        }

        $(document).ready(function () {

            $('#ajaxsync').click(function () {
                sync_locked = false;
                $('#message').html("Syncing data please wait ... ").show();
                $('#loader').fadeIn(200);
                $(this).hide();
                $('#stopajaxsync').show();
                sync_data();
            });

            $('#stopajaxsync').click(function () {
                sync_locked = true;
                $(this).hide();
                $('#message').html("Click the Start button to start data extraction and synchronization").show();
                $('#loader').fadeOut(200);
                $('#ajaxsync').show();
            });
        });

    </script>

</div>

<style>

    .mt-12 {
        margin-top: 12px;
    }

    .mt-8 {
        margin-top: 8px;
    }

    .mb-12 {
        margin-bottom: 12px;
    }

    .error-text {
        color: red;
    }

    .loader {
        display: inline-block;
        border: 2px solid #f3f3f3; /* Light grey */
        border-top: 2px solid #3498db; /* Blue */
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: none;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }
        100% {
            transform: rotate(360deg);
        }
    }

</style>

