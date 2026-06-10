<?php

/**
 * Admin results and settings page.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('publish_posts')) {
    wp_die(esc_html__('You do not have permission to access this page.', 'hd-quiz-save-results-light'));
}

// show results and settings tabs
wp_enqueue_style(
    'hdq_admin_style',
    plugin_dir_url(__FILE__) . './css/hdq_a_light_admin_style.css?v=' . HDQ_A_LIGHT_PLUGIN_VERSION
);
wp_enqueue_script(
    'hdq_admin_script',
    plugins_url('./js/hdq_a_light_admin.js?v=' . HDQ_A_LIGHT_PLUGIN_VERSION, __FILE__),
    array('jquery'),
    '1.0',
    true
);

$opt_name1 = 'hdq_a_l_members_only';
$hidden_field_name = 'hd_submit_hidden';
$data_field_name1 = 'hdq_a_l_members_only';

// Read in existing option value from database
$opt_val1 = sanitize_text_field(get_option($opt_name1));

// See if the user has posted us some information.
if (
    isset($_POST['hdq_srl_options_nonce'], $_POST[$hidden_field_name]) &&
    $_POST[$hidden_field_name] === 'Y' &&
    check_admin_referer('hdq_srl_options_nonce', 'hdq_srl_options_nonce')
) {
    if (isset($_POST[$data_field_name1]) && $_POST[$data_field_name1] === 'yes') {
        $opt_val1 = 'yes';
    } else {
        $opt_val1 = '';
    }
    update_option($opt_name1, $opt_val1);
}
?>
<div id="hdq_meta_forms">
    <div id="hdq_wrapper">
        <div id="hdq_form_wrapper">
            <h1>HD Quiz Results - Light</h1>
            <p>
                This is the light version of this plugin and as such, has limited functionality. Generally speaking,
                this version is meant to be used more as an analytical tool so that you can see when users are completing
                quizzes and roughly how well they are performing.
            </p>
            <p>
                NOTE: The main HD Quiz plugin never stores <em>any</em> user information for submitted quizzes and thus
                is 100% GDPR compliant. The use of this addon, however, requires storing some information when a user
                submits a quiz meaning that you will need to update your privacy policy to disclose this if you wish to
                be GDPR compliant. This addon tracks the IP of the user for secirity as well as the usernme if they are logged in.</p>

            <div id="hdq_srp">
                <div style="display: grid; grid-template-columns: 1fr max-content max-content; grid-gap: 4em">
                    <div>
                        <p>
                            <strong>Save Results Pro addon</strong>
                        </p>

                        <p>
                            If you need to add a custom form to the start or end of quizzes, add a leaderboard/scoreboard to quizzes, or know what the individual answers were for each completed quiz, please consider purchasing the pro version of this addon.
                        </p>
                        <p>
                            <a href="https://harmonicdesign.ca/product/hd-quiz-save-results-pro/?utm_source=HD-Quiz-Save-Results-Light&utm_medium=pro-link" style="text-decoration:none" class="hdq_button2" target="_blank">VIEW ADDON PAGE</a>
                        </p>
                    </div>
                    <ul style="font-weight: bold; line-height: 1.8">
                        <li>+ save quiz taker name and email</li>
                        <li>+ add custom form fields</li>
                        <li>+ send results via email</li>
                        <li>+ sort and filter results</li>
                    </ul>
                    <ul style="font-weight: bold; line-height: 1.8">
                        <li>+ save each question result</li>
                        <li>+ leaderboard</li>
                        <li>+ Zapier integration</li>
                        <li>+ Mailchimp integration</li>
                    </ul>
                </div>
            </div>

            <div id="hdq_tabs">
                <ul>
                    <li class="hdq_active_tab" data-hdq-content="hdq_tab_content">Results</li>
                    <li data-hdq-content="hdq_tab_settings">Settings</li>
                </ul>
                <div class="clear"></div>
            </div>
            <div id="hdq_tab_content" class="hdq_tab">

                <?php
                $data = hdq_a_light_get_results();
                $total = 0;

                if (!defined("HDQ_SRL_MAX_RESULTS")) {
                    define("HDQ_SRL_MAX_RESULTS", 1000);
                }

                if (!empty($data)) {
                    $total = count($data);
                    if ($total > HDQ_SRL_MAX_RESULTS) {
                        $total = HDQ_SRL_MAX_RESULTS;
                    }
                }

                // echo '<pre>' . print_r($data, true) . '</pre>';

                ?>

                <h3>
                    <?php echo esc_html((string) $total); ?> records in table
                </h3>

                <table class="hdq_a_light_table">
                    <thead>
                        <tr>
                            <th>Quiz Name</th>
                            <th>Datetime (MM-DD-YYY)</th>
                            <th>Score</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($data)) {
                            $data = array_reverse($data);
                            $x = 0;
                            foreach ($data as $d) {
                                if (!is_array($d)) {
                                    $d = (array)$d;
                                    if (!isset($d['quizName']) || !isset($d['datetime']) || !isset($d['score']) || !isset($d['quizTaker'])) {
                                        continue;
                                    }
                                }
                                $x++;
                                $d['quizName'] = isset($d['quizName']) ? sanitize_text_field($d['quizName']) : '';
                                $d['datetime'] = isset($d['datetime']) ? sanitize_text_field($d['datetime']) : '';
                                if (!isset($d['quizTaker']) || !is_array($d['quizTaker'])) {
                                    $d['quizTaker'] = array('0', '--');
                                }
                                $d['quizTaker'][1] = sanitize_text_field($d['quizTaker'][1]);

                                if (is_array($d["score"])) {
                                    $d["score"][0] = intval($d["score"][0]);
                                    $d["score"][1] = intval($d["score"][1]);
                                    $d["passPercent"] = intval($d["passPercent"]);
                                } else {
                                    $d["score"] = sanitize_text_field($d["score"]);
                                }

                                $passFail = "";
                                if (is_array(($d["score"]))) {
                                    $passFail = "fail";

                                    if ($d["score"][0] !== 0 && $d["score"][1] !== 0) {
                                        if ($d["score"][0] / $d["score"][1] * 100 >= $d["passPercent"]) {
                                            $passFail = "pass";
                                        }
                                    }
                                }
                        ?>
                                <tr class="<?php echo esc_attr($passFail); ?>">
                                    <td><?php echo esc_html($d['quizName']); ?></td>
                                    <td><?php echo esc_html($d['datetime']); ?></td>
                                    <td>
                                        <?php
                                        if (is_array($d['score'])) {
                                            echo esc_html($d['score'][0] . '/' . $d['score'][1]);
                                        } else {
                                            echo esc_html($d['score']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($d['quizTaker'][1]); ?></td>
                                </tr>
                        <?php
                                // limit total results for super large datasets
                                if ($x >= HDQ_SRL_MAX_RESULTS) {
                                    break;
                                }
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <div id="hdq_tab_settings" class="hdq_tab">
                <form id="hdq_settings" method="post">
                    <input type="hidden" name="hdq_submit_hidden" value="Y">
                    <?php wp_nonce_field('hdq_srl_options_nonce', 'hdq_srl_options_nonce'); ?>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; grid-gap: 2rem">
                        <div class="hdq_row">
                            <label for="hdq_a_l_members_only">Only save results for logged in users
                                <span class="hdq_tooltip hdq_tooltip_question">?<span class="hdq_tooltip_content"><span>By default, all results will be saved, and non-logged-in users will show up as
                                            <code>--</code></span></span></span></label>
                            <div class="hdq_check_row">
                                <div class="hdq-options-check">
                                    <input type="checkbox" id="hdq_a_l_members_only" name="hdq_a_l_members_only" value="yes" <?php if ($opt_val1 == "yes") {
                                                                                                                                    echo 'checked = ""';
                                                                                                                                } ?> />
                                    <label for="hdq_a_l_members_only"></label>
                                </div>

                                <div role="button" id="hdq_a_light_delete_results" class="hdq_button4" title="clear all of the current results and start from scratch"><span class="dashicons dashicons-trash"></span> DELETE ALL RESULTS</div>

                                <div id="hdq_a_light_export_csv_wrap">
                                    <div role="button" id="hdq_a_light_export_results" class="hdq_button3" title="clear all of the current results and start from scratch">EXPORT AS CSV</div>
                                </div>

                            </div>
                        </div>
                        <div class="hdq_row" style="text-align:right">
                            <input type="submit" class="hdq_button2" id="hdq_save_settings" value="SAVE">
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>