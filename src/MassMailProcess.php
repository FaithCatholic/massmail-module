#!/usr/bin/php
<?php

// Drupal root path
$drupal_root = $argv[1];
$mode = $argv[2];

// Drupal container
require_once $drupal_root . '/core/includes/database.inc';
require_once $drupal_root . '/core/includes/schema.inc';
$autoloader = require_once $drupal_root . '/autoload.php';
$request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$kernel = \Drupal\Core\DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->prepareLegacyRequest($request);

// Load helper functions
\Drupal::moduleHandler()->loadInclude('massmail', 'module');

// Start log file
$logpath = massmail_data_dir() . '/massmail.log';
if (file_exists($logpath)) {
  unlink($logpath);
}
$logfile = fopen($logpath, 'w') or die('Cannot create log at ' . $logpath);

fwrite($logfile, "Initializing...\n");
print "Initializing...\n";

// Semi-final pre-built data
$records = [];

// Get primary source data
fwrite($logfile, "Getting primary source data...\n");
print "Getting primary source data...\n";

$filepath = massmail_path_by_fid(\Drupal::config('massmail.mail')->get('source_fid_0'));
$data = massmail_source_data($filepath, 'Church');

// Assign primary source data
fwrite($logfile, 'Assigning ' . count($data) . " primary source data items...\n");
print 'Assigning ' . count($data) . " primary source data items...\n";

foreach($data as $row) {
  if (
    is_numeric($row['id'])
  ) {
    $records[$row['id']] = (array) $row;
    $records[$row['id']]['services'] = [];
  }
}
unset($data);

// Get key-related secondary source data
fwrite($logfile, "Getting secondary source data...\n");
print "Getting secondary source data...\n";

$filepath = massmail_path_by_fid(\Drupal::config('massmail.mail')->get('source_fid_1'));
$data = massmail_source_data($filepath, 'Services');

// Assign secondary source data
fwrite($logfile, 'Assigning ' . count($data) . " secondary source data items...\n");
print 'Assigning ' . count($data) . " secondary source data items...\n";

foreach($data as $row) {
  if (
    is_numeric($row['id']) &&
    is_numeric($row['church_id']) &&
    array_key_exists((integer) $row['church_id'], $records)
  ) {
    $records[$row['church_id']]['services'][$row['id']] = (array) $row;
  }
}
unset($data);

// Build email requests
fwrite($logfile, 'Creating ' . count($records) . " email requests...\n");
print 'Creating ' . count($records) . " email requests...\n";

$requests = [];
$i = 0;

foreach($records as $record) {
  $i++;
  print "Building email $i/" . count($records) . "\n";

  // Final build
  $build = [];

  // Emails
  $build['to'] = $record['email']  ? $record['email'] : \Drupal::config('massmail.mail')->get('to');
  $build['from'] = \Drupal::config('massmail.mail')->get('from');
  $build['reply'] = \Drupal::config('massmail.mail')->get('reply') ? \Drupal::config('massmail.mail')->get('reply') : $build['from'];
  $build['bounce'] = \Drupal::config('massmail.mail')->get('bounce') ? \Drupal::config('massmail.mail')->get('bounce') : $build['from'];
  $build['suppressionlist'] = \Drupal::config('massmail.mail')->get('suppressionlist') ? \Drupal::config('massmail.mail')->get('suppressionlist') : $build['from'];

  // Subject
  $build['subject'] = \Drupal::config('massmail.mail')->get('subject');

  // Intro (plain)
  $build['body'] = \Drupal::config('massmail.mail')->get('body');
  $build['body'] .= "\n\n";

  // Intro (html)
  $build['html'] = '<div style="font-family:Helvetica, Arial, sans-serif; font-size:14px;color:#333;">';
  $build['html'] .= '<h3 style="color:#4f77a6;font-size: 18px;margin: 18px 0;"><span style="font-weight:normal;font-size: 20px;">MassTimes – </span>150 million searches and counting!</h3>';
  $build['html'] .= str_replace("\n", "<br>", \Drupal::config('massmail.mail')->get('body'));
  $build['html'] .= '<br><br><br>';

  // Services (html)
  $build['html'] .= '<table style="font-family:Helvetica, Arial, sans-serif; font-size:14px;color:#333;">';
  $build['html'] .= '<thead><tr>';
  $build['html'] .= '<th style="text-align:left;padding-left:0;padding-right:25px;">Day</th>';
  $build['html'] .= '<th style="text-align:left;padding-left:0;padding-right:25px;">Service</th>';
  $build['html'] .= '<th style="text-align:left;padding-left:0;padding-right:25px;">Start</th>';
  $build['html'] .= '<th style="text-align:left;">End</th>';
  $build['html'] .= '</tr></thead>';
  $build['html'] .= '<tbody>';

  // Append secondary data
  foreach($record['services'] as $service) {
    $build['html'] .= '<tr>';

    // Service day
    $build['html'] .= '<td style="padding-left:0;padding-right:25px;">';
    if (array_key_exists('day_of_week', $service) && $service['day_of_week'] != '') {
      // Only the switch statement seems fast enough to
      // achieve the outcome of mapping a key to a string
      // when processing thousands of records in PHP.
      // Using an array, for example, is way too slow.
      switch ($service['day_of_week']) {
        case 0:
          $day = 'Sunday';
          break;
        case 1:
          $day = 'Monday';
          break;
        case 2:
          $day = 'Tuesday';
          break;
        case 3:
          $day = 'Wednesday';
          break;
        case 4:
          $day = 'Thursday';
          break;
        case 5:
          $day = 'Friday';
          break;
        case 6:
          $day = 'Saturday';
          break;
      }
      $build['body'] .= $day . ' ';
      $build['html'] .= $day;
    }
    $build['html'] .= '</td>';

    // Service type
    $build['html'] .= '<td style="padding-left:0;padding-right:25px;">';
    if (array_key_exists('service_type', $service) && $service['service_type'] != '') {
      // Only the switch statement seems fast enough to
      // achieve the outcome of mapping a key to a string
      // when processing thousands of records in PHP.
      // Using an array, for example, is way too slow.
      switch ($service['service_type']) {
        case 1:
          $type = 'Adoration';
          break;
        case 2:
          $type = 'Confession';
          break;
        case 3:
          $type = 'Devotion';
          break;
        case 5:
          $type = 'Holy Day';
          break;
        case 6:
          $type = 'Vigil for Holy Day';
          break;
        case 7:
          $type = 'Weekday';
          break;
        case 8:
          $type = 'Weekend';
          break;
      }
      $build['body'] .= "($type)";
      $build['html'] .= $type;
    }
    $build['html'] .= '</td>';

    // Start time
    $build['html'] .= '<td style="padding-left:0;padding-right:25px;">';
    if (array_key_exists('time_start', $service) && $service['time_start'] != '') {
      $time = date('g:ia', strtotime($service['time_start']));
      $build['body'] .= "– Start: $time";
      $build['html'] .= $time;
    }
    $build['html'] .= '</td>';

    // End time
    $build['html'] .= '<td>';
    if (array_key_exists('time_end', $service) && $service['time_end'] != '') {
      $time = date('g:ia', strtotime($service['time_end']));
      $build['body'] .= "– End: $time";
      $build['html'] .= $time;
    }
    $build['html'] .= '</td>';

    $build['body'] .= "\n";
    $build['html'] .= '</tr>';

  }

  // Suffix (html)
  $build['html'] .= '</tbody></table>';
  $build['html'] .= '<br><br>';
  $build['html'] .= 'Please correct any inaccuracies at http://www.updateparishdata.org/UpdateChurchInfo.aspx?churchid=' . $record['id'];

  // Footer (html)
  $build['html'] .= '<br><br><br>';
  $build['html'] .= '<div style="background-color:#4f77a6;color:#fff;padding:25px;text-align:center;font-size:12px;">';
  $build['html'] .= '<em>&copy; ' . date('Y') . ' Mass Times Trust, All Rights Reserved.</em>';
  $build['html'] .= '<br><br>';
  $build['html'] .= '<strong>Mass Times Trust</strong>';
  $build['html'] .= '<br>';
  $build['html'] .= '1500 E. Saginaw Street';
  $build['html'] .= '<br>';
  $build['html'] .= 'Lansing, MI 48906';
  $build['html'] .= '<br><br>';
  $build['html'] .= 'Sponsored by FAITH Catholic and the Diocese of Lansing';
  $build['html'] .= '<br><br>';
  $build['html'] .= '<a href="mailto:unsubscribe@masstimes.org?subject=Unsubscribe ' . $build['suppressionlist'] . '&body=I would like to unsubscribe this address: ' . $build['suppressionlist'] . '" style="font-size:10px;color:#8bb9ef;text-decoration:none;">Unsubscribe</a>';
  $build['html'] .= '</div>';
  $build['html'] .= '<br><br>';

  $build['html'] .= '</div>';

  // Replace tokens
  foreach($record as $token => $replace) {
    if (!is_array($replace)) {

      foreach($build as $key => $value) {
        $build[$key] = str_replace("[[$token]]", $replace, $value);
      }

    }
  }

  // Add SES client request
  $requests[] = array(
    'Source' => $build['from'],
    'Destination' => array(
      'ToAddresses' => array($build['to']),
    ),
    'Message' => array(
      'Subject' => array(
        'Data' => $build['subject'],
        'Charset' => 'UTF-8',
      ),
      'Body' => array(
        'Text' => array(
          'Data' => $build['body'],
          'Charset' => 'UTF-8',
        ),
        'Html' => array(
          'Data' => $build['html'],
          'Charset' => 'UTF-8',
        ),
      ),
     'ReplyToAddresses' => array($build['reply']),
     'ReturnPath' => $build['bounce'],
    ),
  );

}
unset($records);

// Send the emails
fwrite($logfile, 'Preparing to send ' . count($requests) . " emails...\n");
print 'Preparing to send ' . count($requests) . " emails...\n";

$ses = massmail_ses_client();
$rate = $ses->getSendQuota()->get('MaxSendRate');

if ($mode === 'test' || $mode === '') {

  if (count($requests)) {

    // Test first email
    fwrite($logfile, 'Sending test of first email in queue...' . "\n");
    print 'Sending test of first email in queue...' . "\n";

    $request = array_shift($requests);
    $request['Destination']['ToAddresses'] = [$build['from']];
    $result = $ses->sendEmail($request);

    fwrite($logfile, 'Sent email.' . "\n");
    print 'Sent email.' . "\n";

    // Test last email
    fwrite($logfile, 'Sending test of last email in queue...' . "\n");
    print 'Sending test of last email in queue...' . "\n";

    $request = array_pop($requests);
    $request['Destination']['ToAddresses'] = [$build['from']];
    $result = $ses->sendEmail($request);

    fwrite($logfile, 'Sent email.' . "\n");
    print 'Sent email.' . "\n";

  }

} elseif ($mode === 'live') {

  fwrite($logfile, "Sending live emails...\n");
  print "Sending live emails...\n";

  for($i = 1; $i <= count($requests); $i++) {

    if ($i % $rate === 0) { // 1 sec api rate limit delay
      sleep(1);
    }

    $result = $ses->sendEmail($requests[$i - 1]); // Send the email to SES
    fwrite($logfile, 'Sent email ' . ($i - 1) . "\n");
    print('Sent email ' . ($i - 1) . "\n");
  }
  unset($requests);

}

fwrite($logfile, "Complete.");
print "Complete.\n";
