<?php
// general HDQ Addon Save Results Light functions

// Tell HD Quiz to send an AJAX request to `hdq_a_light_submit_action()`
// once quiz has been submitted
function hdq_a_light_submit($quizOptions)
{
    array_push($quizOptions->hdq_submit, "hdq_a_light_submit_action");
    return $quizOptions;
}
add_action('hdq_submit', 'hdq_a_light_submit');

// the functon that runs once quiz submitted
function hdq_a_light_submit_action($data)
{
    // check if logged-in users only should be saved
    $membersOnly = sanitize_text_field(get_option("hdq_a_l_members_only"));
    if ($membersOnly === "yes" && !is_user_logged_in()) {
        die();
    }

    if (function_exists("get_hdq_quiz")) {
        hdq_a_light_submit_1_8_x(); // legacy
        return;
    }

    $data = $_POST["data"];
    $data = stripslashes($data);
    $data = json_decode($data, true);
    $quizID = intval($data["quizID"]);
    $quiz_type = sanitize_text_field(get_term_meta($quizID, "hdq_quiz_type", true));

    if ($quiz_type !== "personality") {
        hdq_a_light_quiz_type_general($data);
    } else {
        hdq_a_light_quiz_type_personality($data);
    }
    die();
}
add_action('wp_ajax_hdq_a_light_submit_action', 'hdq_a_light_submit_action');
add_action('wp_ajax_nopriv_hdq_a_light_submit_action', 'hdq_a_light_submit_action');

function hdq_a_light_get_quiz_taker()
{
    $quizTaker = array();
    $current_user = wp_get_current_user();
    if ($current_user->ID === 0) {
        $quizTaker[0] = "0";
        $quizTaker[1] = "--";
    } else {
        $quizTaker[0] = $current_user->ID;
        $quizTaker[1] = $current_user->data->display_name;
    }
    return $quizTaker;
}


// Save general /scored quiz results
function hdq_a_light_quiz_type_general($data)
{
    $quiz_id = intval($data["quizID"]);
    $score = array_map("intval", $data["score"]);

    $result = new stdClass();
    $result->quizID = $quiz_id;
    $result->score = $score;
    $result->type = "general";

    $quiz = hdq_get_quiz($quiz_id);
    $passPercent = $quiz["quiz_pass_percentage"];
    $result->passPercent = $passPercent;
    $result->quizName = sanitize_text_field($quiz["quiz_name"]); // sanitize as text field to make display better in table
    $result->quizTaker = hdq_a_light_get_quiz_taker();

    // save the date and time
    $timezone = get_option('timezone_string');
    date_default_timezone_set($timezone);
    $result->datetime = date('m-d-Y h:i:s a', time());

    // read in existing results
    $data = get_option("hdq_quiz_results_l");

    if ($data == "" || $data == null) {
        $data = array();
        update_option("hdq_quiz_results_l", "");
    } else {
        $data = json_decode(html_entity_decode($data), true);
    }
    array_push($data, $result);

    // re-encode and update record
    $result = json_encode($data);
    update_option("hdq_quiz_results_l", sanitize_text_field($result));

    echo "Quiz result has been logged";
    die();
}

function hdq_a_light_quiz_type_personality($data)
{
    $quiz_id = intval($data["quizID"]);
    $score = sanitize_text_field($data["score"]);

    $result = new stdClass();
    $result->quizID = $quiz_id;
    $result->score = $score;
    $result->type = "personality";

    $quiz = hdq_get_quiz($quiz_id);
    $result->quizName = sanitize_text_field($quiz["quiz_name"]); // sanitize as text field to make display better in table
    $result->quizTaker = hdq_a_light_get_quiz_taker();

    // save the date and time
    $timezone = get_option('timezone_string');
    date_default_timezone_set($timezone);
    $result->datetime = date('m-d-Y h:i:s a', time());

    // read in existing results
    $data = get_option("hdq_quiz_results_l");

    if ($data == "" || $data == null) {
        $data = array();
        update_option("hdq_quiz_results_l", "");
    } else {
        $data = json_decode(html_entity_decode($data), true);
    }
    array_push($data, $result);

    // re-encode and update record
    $result = json_encode($data);
    update_option("hdq_quiz_results_l", sanitize_text_field($result));

    echo "Quiz result has been logged";
    die();
}


// Legacy for HD Quiz 1.8.x
function hdq_a_light_submit_1_8_x()
{
    $result = new stdClass();
    $quizID = intval($_POST['data']["quizID"]);
    $result->quizID = $quizID;
    $score = array_map('intval', $_POST['data']["score"]);
    $result->score = $score;

    // get quiz meta
    if (HDQ_PLUGIN_VERSION < 1.8 && function_exists("hdq_get_quiz_options")) {
        $hdq_quiz_options = hdq_get_quiz_options($quizID);
        $passPercent = intval($hdq_quiz_options["passPercent"]);
    } else {
        $hdq_quiz_options = get_hdq_quiz($quizID);
        $passPercent = $hdq_quiz_options["quiz_pass_percentage"]["value"];
    }
    $result->passPercent = $passPercent;

    // get quiz term info
    $term = get_term($quizID, "quiz");
    $quizName = $term->name;
    $result->quizName = $quizName;

    // create the user info
    $quizTaker = array();
    $current_user = wp_get_current_user();
    if ($current_user->ID === 0) {
        $quizTaker[0] = "0";
        $quizTaker[1] = "--";
    } else {
        $quizTaker[0] = $current_user->ID;
        $quizTaker[1] = $current_user->data->display_name;
    }
    $result->quizTaker = $quizTaker;

    // save the date and time
    $timezone = get_option('timezone_string');
    date_default_timezone_set($timezone);
    $result->datetime = date('m-d-Y h:i:s a', time());

    // read in existing results
    $data = get_option("hdq_quiz_results_l");

    if ($data == "" || $data == null) {
        $data = array();
        update_option("hdq_quiz_results_l", "");
    } else {
        $data = json_decode(html_entity_decode($data), true);
    }

    // append new result to data
    array_push($data, $result);

    // re-encode and update record
    $result = json_encode($data);
    update_option("hdq_quiz_results_l", sanitize_text_field($result));

    echo "Quiz result has been logged";

    die();
}



// delete all results
function hdq_a_light_delete_results()
{
    if (!current_user_can('manage_options')) {
        die();
    }

    if (!isset($_POST["nonce"])) {
        die();
    }

    $nonce = sanitize_text_field($_POST["nonce"]);
    if (!wp_verify_nonce($nonce, 'hdq_about_options_nonce')) {
        die();
    }

    update_option("hdq_quiz_results_l", "");
    die();
}
add_action('wp_ajax_hdq_a_light_delete_results', 'hdq_a_light_delete_results');
