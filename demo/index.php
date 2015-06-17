<?php

// Configuration
$config = array(
  'app_type'         => 'service', // service, web, app
  'app_name'         => 'my_app',
  'email_address'    => 'me@example.com',
  'private_key_file' => 'file.p12',
  'client_id'        => 'yourID',
  'mcAPI'            => 'APIKEY'
  // 'client_secret'   => '',
  // 'redirect_uri'    => '',
  // 'key'             => '',
);

// For demo purposes only.
require_once( 'config.php' );

/**
 * Autoload the dependencies.
 */
require_once( '../vendor/autoload.php' );

/**
 * Include the Content Score Report (CSR) library.
 */
require_once( '../src/content-score-report.class.php' );

// Initialize the CSR library.
$csr = new Content_Score_Report( $config );

// Check GA & MailChimp API connections.
print_r( $csr->checkConnections() );

// Get the retention content scores.
print_r( $csr->getScore( array(
  'viewID'    => $config['viewID'],
  'startDate' => '2015-01-01',
  'endDate'   => '2015-06-01',
  'type'      => 'retention',
  'path'      => '/analysis/chinese-rail-fostering-regional-change-global-implications',
  'mcID'      => '23ebbb9fdb'
) ) );

// Get the retention content scores.
print_r( $csr->getScore( array(
  'viewID'    => $config['viewID'],
  'startDate' => '2015-01-01',
  'endDate'   => '2015-06-01',
  'type'      => 'acquisition',
  'path'      => '/analysis/chinese-rail-fostering-regional-change-global-implications',
  'category'  => 'Lead List Join',
  'action'    => 'Barrier',
  'label'     => 'sample/thank-you/analysis/chinese-rail-fostering-regional-change-global-implications'
) ) );
