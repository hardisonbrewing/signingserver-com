<?php

require("include/constants.php");

if (strcmp(SUBMISSION_KEY, $_GET['apikey']) != 0) {
  header("HTTP/1.0 404 Not Found");
  exit;
}

if(!fsockopen("www.google.com", 80)){
  exit_with_error_code(10);
}

$json = file_get_contents('php://input');
if(!$json) exit_with_error_code(9);

$json = json_decode($json);
$results = $json->{'results'};
if(!$results) exit_with_error_code(8);

require("include/mysql_connect.php");
require("include/common_sql.php");
require("tweet/twitteroauth.php");

tweet_results($results);

$signerIds = array();

foreach ($results as $result) {
  array_push($signerIds, $result->{'signerId'});
  store_result($result);
}

$control = load_controls();
$control = json_encode($control);
$control = json_decode($control);
store_result($control);

echo "Successfully saved results to isthesigningserverdown.com for ".implode($signerIds, ", ");

exit;

function load_controls()
{
  $controls = array( "http://google.com/robots.txt",
                     "http://amazon.com/robots.txt",
                     "http://aws.amazon.com/robots.txt",
                     "http://youtube.com/robots.txt");
  
  $duration = 0;
  $size = 0;
  $count = 0;

  foreach ($controls as $control)
  {
    $start = round(microtime(true) * 1000);
    $data = get_data($control);
    $end = round(microtime(true) * 1000);

    if (strlen($data) == 0) continue;

    $count++;
    $duration += ($end-$start);
    $size += strlen($data);
  }

  $result = array();
  $result['signerId'] = "CTL";
  $result['count'] = $count;
  $result['success'] = $count;
  $result['failure'] = 0;
  $result['duration'] = $duration;
  $result['size'] = $size;
  $result['retry'] = 0;
  return $result;
}

function get_data($url)
{
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
  $data = curl_exec($ch);
  curl_close($ch);
  return $data;
}

function exit_with_error_code($exitCode) {
  header("HTTP/1.0 400 Bad Request (".$exitCode.")");
  echo $exitCode;
  exit($exitCode);
}

function tweet_results($results) {

  $now_succeeding = array();
  $now_failing = array();

  foreach ($results as $result) {
    sort_result($result, $now_succeeding, $now_failing);
  }
  
  if (count($now_succeeding) == 0 && count($now_failing) == 0) {
    return;
  }
  
  $tweet = "At ".date('H:i (T)').":";
  
  if (count($now_succeeding) > 0) {
    $tweet .= " ".glue_result_text($now_succeeding)." succeeding.";
  }
  
  if (count($now_failing) > 0) {
    $tweet .= " ".glue_result_text($now_failing)." failing.";
  }

  $connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, OAUTH_TOKEN, OAUTH_SECRET);
  $content = $connection->get('account/verify_credentials');
  $connection->post('statuses/update', array('status' => $tweet));
}

function glue_result_text($results) {
  $results_text = "";
  for ($i = 0; $i < count($results) - 1; $i++) {
    if ($i > 0) $results_text .= ", ";
    $results_text .= strtoupper($results[$i]);
  }
  if (!empty($results_text)) $results_text .= " and ";
  $results_text .= strtoupper($results[count($results) - 1]);
  $results_text .= count($results) > 1 ? " are" : " is";
  return $results_text;
}
  
function sort_result($result, &$now_succeeding, &$now_failing) {
  
  $key = $result->{'signerId'};

  $failures = failures($key, true);
  if ($failures == 0) {
    return;
  }  

  $failure = $result->{'failure'} > 0;

  if ($failure && $failures == TWEETER_THRESHOLD - 1) {
    array_push($now_failing, $key);
  }
  else if (!$failure && $failures >= TWEETER_THRESHOLD) {
    array_push($now_succeeding, $key);
  }
}

function store_result($result) {

  global $mysqli;
  
  $sigType = strtolower($result->{'signerId'});

  $statement = $mysqli->prepare("INSERT INTO ".DB_TABLE."
    SET sig_type=?,
    cod_count=?,
    result_success=?,
    result_failure=?,
    response_time=?,
    cod_size=?,
    retry=?;");
  $statement->bind_param('siiiiii',
    $sigType,
    $result->{'count'},
    $result->{'success'},
    $result->{'failure'},
    $result->{'duration'},
    $result->{'size'},
    $result->{'retry'});
  $statement->execute();
  $statement->close();
}

?>
