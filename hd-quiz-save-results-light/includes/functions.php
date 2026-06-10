<?php

/**
 * General HDQ Addon Save Results Light functions.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* RATE LIMITING
------------------------------------------------------- */
function hdq_a_light_get_client_ip()
{
    $ip = '';

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
        $ip = trim($parts[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }

    return $ip;
}

/* Rate-limit key: logged-in user id, otherwise hashed IP. */
function hdq_a_light_rate_limit_identifier()
{
    if (is_user_logged_in()) {
        return 'user_' . (int) get_current_user_id();
    }

    $ip = hdq_a_light_get_client_ip();
    if ($ip === '') {
        return 'unknown';
    }

    return 'ip_' . md5($ip);
}

/* Returns true when the request exceeds configured save limits. */
function hdq_a_light_is_rate_limited()
{
    $identifier = hdq_a_light_rate_limit_identifier();
    $short_limit = (int) apply_filters('hdq_a_light_rate_limit', 3); // only allow 3 saves per minute by default
    $short_window = (int) apply_filters('hdq_a_light_rate_limit_window', MINUTE_IN_SECONDS);
    $hour_limit = (int) apply_filters('hdq_a_light_rate_limit_hourly', 60); // 60 per hour
    $hour_window = (int) apply_filters('hdq_a_light_rate_limit_hourly_window', HOUR_IN_SECONDS);

    if ($short_limit > 0 && hdq_a_light_rate_limit_bucket_exceeded('short', $identifier, $short_limit, $short_window)) {
        return true;
    }

    if ($hour_limit > 0 && hdq_a_light_rate_limit_bucket_exceeded('hour', $identifier, $hour_limit, $hour_window)) {
        return true;
    }

    return false;
}

/* Increment rate if exceeded */
function hdq_a_light_rate_limit_bucket_exceeded($bucket, $identifier, $limit, $window)
{
    $key = 'hdq_a_light_rl_' . $bucket . '_' . md5($identifier);
    $count = (int) get_transient($key);

    if ($count >= $limit) {
        return true;
    }

    set_transient($key, $count + 1, $window);
    return false;
}

/* Get stored results */
function hdq_a_light_get_results()
{
    $data = get_option('hdq_quiz_results_l', array());

    if (is_array($data)) {
        return $data;
    }

    if ($data === '' || $data === null) {
        return array();
    }

    $decoded = json_decode(html_entity_decode((string) $data), true);
    return is_array($decoded) ? $decoded : array();
}

/* Trim results to HDQ_SRL_MAX_RESULTS 
NOTE: Originallly did not trim to users could still access all results,
but I just don't think it's worth it to bloat the db */
function hdq_a_light_save_results_option(array $results)
{
    if (!defined('HDQ_SRL_MAX_RESULTS')) {
        define('HDQ_SRL_MAX_RESULTS', 1000);
    }   

    $max = (int) HDQ_SRL_MAX_RESULTS;
    if ($max > 0 && count($results) > $max) {
        $results = array_slice($results, -$max);
    }

    update_option('hdq_quiz_results_l', $results, false);
}

/* Accept HDQ payload */
function hdq_a_light_parse_submit_payload()
{
    if (!isset($_POST['data'])) {
        return null;
    }

    $raw = wp_unslash($_POST['data']);
    if (is_array($raw)) {
        return $raw;
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : null;
}

/* Make sure quiz exists */
function hdq_a_light_quiz_exists($quiz_id)
{
    $quiz_id = (int) $quiz_id;
    if ($quiz_id <= 0) {
        return false;
    }

    if (function_exists('hdq_get_quiz')) {
        $quiz = hdq_get_quiz($quiz_id);
        return is_array($quiz) && !empty($quiz['quiz_name']);
    }

    // for older version of HD Quiz (1.8.x)
    if (function_exists('get_hdq_quiz')) {
        $quiz = get_hdq_quiz($quiz_id);
        return is_array($quiz) && !empty($quiz);
    }

    $term = get_term($quiz_id, 'quiz');
    return $term && !is_wp_error($term);
}

function hdq_a_light_can_save_result($quiz_id)
{
    if (hdq_a_light_is_rate_limited()) {
        return false;
    }

    if (!hdq_a_light_quiz_exists($quiz_id)) {
        return false;
    }

    return true;
}

function hdq_a_light_append_result($result)
{
    $data = hdq_a_light_get_results();
    $data[] = $result;
    hdq_a_light_save_results_option($data);
}

/* Tell HD Quiz to send an AJAX request to `hdq_a_light_submit_action()`
once quiz has been submitted. */
function hdq_a_light_submit($quizOptions)
{
    array_push($quizOptions->hdq_submit, 'hdq_a_light_submit_action');
    return $quizOptions;
}
add_action('hdq_submit', 'hdq_a_light_submit');

/* The function that runs once quiz submitted. */
function hdq_a_light_submit_action($data = null)
{
    $members_only = sanitize_text_field(get_option('hdq_a_l_members_only'));
    if ($members_only === 'yes' && !is_user_logged_in()) {
        wp_die('', '', array('response' => 403));
    }

    if (function_exists('get_hdq_quiz')) {
        hdq_a_light_submit_1_8_x();
        return;
    }

    $payload = hdq_a_light_parse_submit_payload();
    if ($payload === null || empty($payload['quizID'])) {
        wp_die('', '', array('response' => 400));
    }

    $quiz_id = (int) $payload['quizID'];
    if (!hdq_a_light_can_save_result($quiz_id)) {
        wp_die('', '', array('response' => 429));
    }

    $quiz_type = sanitize_text_field(get_term_meta($quiz_id, 'hdq_quiz_type', true));

    if ($quiz_type !== 'personality') {
        hdq_a_light_quiz_type_general($payload);
    } else {
        hdq_a_light_quiz_type_personality($payload);
    }
}
add_action('wp_ajax_hdq_a_light_submit_action', 'hdq_a_light_submit_action');
add_action('wp_ajax_nopriv_hdq_a_light_submit_action', 'hdq_a_light_submit_action');

function hdq_a_light_get_quiz_taker()
{
    $quiz_taker = array();
    $current_user = wp_get_current_user();
    if ($current_user->ID === 0) {
        $quiz_taker[0] = '0';
        $quiz_taker[1] = '--';
    } else {
        $quiz_taker[0] = (string) $current_user->ID;
        $quiz_taker[1] = sanitize_text_field($current_user->display_name);
    }
    return $quiz_taker;
}

// Save general / scored quiz results.
function hdq_a_light_quiz_type_general($data)
{
    $quiz_id = (int) $data['quizID'];
    if (!isset($data['score']) || !is_array($data['score'])) {
        wp_die('', '', array('response' => 400));
    }

    $score = array_map('intval', $data['score']);
    if (count($score) < 2) {
        wp_die('', '', array('response' => 400));
    }

    if ($score[1] > 0 && $score[0] > $score[1]) {
        $score[0] = $score[1];
    }

    $result = new stdClass();
    $result->quizID = $quiz_id;
    $result->score = array($score[0], $score[1]);
    $result->type = 'general';

    $quiz = hdq_get_quiz($quiz_id);
    $result->passPercent = (int) $quiz['quiz_pass_percentage'];
    $result->quizName = sanitize_text_field($quiz['quiz_name']);
    $result->quizTaker = hdq_a_light_get_quiz_taker();
    $result->datetime = wp_date('m-d-Y h:i:s a');

    hdq_a_light_append_result($result);

    echo esc_html__('Quiz result has been logged', 'hd-quiz-save-results-light');
    wp_die();
}

function hdq_a_light_quiz_type_personality($data)
{
    $quiz_id = (int) $data['quizID'];
    if (!isset($data['score'])) {
        wp_die('', '', array('response' => 400));
    }

    $score = sanitize_text_field((string) $data['score']);
    if (strlen($score) > 200) {
        $score = substr($score, 0, 200);
    }

    $result = new stdClass();
    $result->quizID = $quiz_id;
    $result->score = $score;
    $result->type = 'personality';

    $quiz = hdq_get_quiz($quiz_id);
    $result->quizName = sanitize_text_field($quiz['quiz_name']);
    $result->quizTaker = hdq_a_light_get_quiz_taker();
    $result->datetime = wp_date('m-d-Y h:i:s a');

    hdq_a_light_append_result($result);

    echo esc_html__('Quiz result has been logged', 'hd-quiz-save-results-light');
    wp_die();
}

// Legacy for HD Quiz 1.8.x.
function hdq_a_light_submit_1_8_x()
{
    if (!isset($_POST['data']) || !is_array($_POST['data'])) {
        wp_die('', '', array('response' => 400));
    }

    $post_data = wp_unslash($_POST['data']);
    $quiz_id = isset($post_data['quizID']) ? (int) $post_data['quizID'] : 0;

    if (!hdq_a_light_can_save_result($quiz_id)) {
        wp_die('', '', array('response' => 429));
    }

    $result = new stdClass();
    $result->quizID = $quiz_id;

    if (!isset($post_data['score']) || !is_array($post_data['score'])) {
        wp_die('', '', array('response' => 400));
    }

    $score = array_map('intval', $post_data['score']);
    if (count($score) < 2) {
        wp_die('', '', array('response' => 400));
    }

    if ($score[1] > 0 && $score[0] > $score[1]) {
        $score[0] = $score[1];
    }

    $result->score = array($score[0], $score[1]);

    if (defined('HDQ_PLUGIN_VERSION') && HDQ_PLUGIN_VERSION < 1.8 && function_exists('hdq_get_quiz_options')) {
        $hdq_quiz_options = hdq_get_quiz_options($quiz_id);
        $result->passPercent = (int) $hdq_quiz_options['passPercent'];
    } else {
        $hdq_quiz_options = get_hdq_quiz($quiz_id);
        $result->passPercent = (int) $hdq_quiz_options['quiz_pass_percentage']['value'];
    }

    $term = get_term($quiz_id, 'quiz');
    $result->quizName = ($term && !is_wp_error($term)) ? sanitize_text_field($term->name) : '';
    $result->quizTaker = hdq_a_light_get_quiz_taker();
    $result->datetime = wp_date('m-d-Y h:i:s a');

    hdq_a_light_append_result($result);

    echo esc_html__('Quiz result has been logged', 'hd-quiz-save-results-light');
    wp_die();
}

// Delete all results.
function hdq_a_light_delete_results()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }

    check_ajax_referer('hdq_srl_options_nonce', 'nonce');

    update_option('hdq_quiz_results_l', array(), false);
    wp_send_json_success();
}
add_action('wp_ajax_hdq_a_light_delete_results', 'hdq_a_light_delete_results');
