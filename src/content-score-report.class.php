<?php

/**
 * Content Score Report PHP library
 *
 * A PHP library that uses data from Google Analytics & MailChimp to calculate
 * content acquisition and retention scores.
 *
 * @author Ben Marshall <me@benmarshall.me>
 * @version 2.0
 * @link https://github.com/bmarshall511/content-score-report-php-library
 * @since Class available since Release 1.0
 */

class Content_Score_Report
{
  var $client;
  var $viewID;
  var $startDate;
  var $endDate;
  var $type;

  public function __construct( $config )
  {
    $this->config = $config;
  }

  public function checkConnections()
  {
    $return = array(
      'gaConnection' => false,
      'mcConnection' => false,
      'gaURL'        => false
    );

    // Connect to the GA API.
    $result                 = $this->authenticateGA();
    $return['gaConnection'] = $result['connected'];
    $return['gaURL']        = $result['url'];

    return $return;
  }

  public function deauthorizeGA()
  {
    $_SESSION['access_token'] = false;
    session_destroy();
    $result = $this->authenticateGA();
  }

  public function authenticateGA( $code = false )
  {
    $return = array(
      'connected' => false,
      'url'       => false
    );

    $this->_gaConnect();

    if ( $this->config['app_type'] === 'service' ) {
       $return['connected'] = true;
    }
    else {
      if( $code ) {
        $response = $this->client->authenticate( $code );
        $_SESSION['access_token'] = $this->client->getAccessToken();
      }

      if( isset( $_SESSION['access_token'] ) && $_SESSION['access_token'] ) {
        $this->client->setAccessToken( $_SESSION['access_token'] );

        if( $this->client->isAccessTokenExpired() ) {
          /*$this->client->authenticate();
          $newToken = json_decode( $this->client->getAccessToken() );
          $this->client->refreshToken( $newToken->refresh_token );*/
          $return['url'] = $this->client->createAuthUrl();
        }
        else {
          $return['connected'] = true;
        }
      }
      else {
        $return['url'] = $this->client->createAuthUrl();
      }
    }

    return $return;
  }

  private function _curl( $url, $headers )
  {
    if (!function_exists('curl_init')){
        die('Sorry cURL is not installed!');
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    // Close the cURL resource, and free system resources
    curl_close($ch);

    return $response;
  }

  public function getScore( $args )
  {
    $this->viewID    = $args['viewID'];
    $this->startDate = $args['startDate'];
    $this->endDate   = $args['endDate'];
    $this->type      = $args['type'];

    $return = array(
      "errors" => array(),
      "gaData" => array(),
      "mcData" => array(),
      "score"  => array()
    );

    foreach( $args as $key => $val ) {
      if( ! $val ) {
        $return['errors'][] = "Missing required parameter: " . $key;
      }
    }

    if( ! count( $return['errors'] ) ) {

      // Get pageviews from Google Analytics.
      $result = $this->_gaCall( array( 'type' => 'pageviews', 'path' => $args['path'] ) );
      $return['errors']              = $return['errors'] + $result['errors'];
      $return['gaData']['pageviews'] = $result['data'];

      switch( $this->type ) {
        case 'retention':

          $campaign = json_decode($this->_curl( 'https://us4.api.mailchimp.com/3.0/reports/' . $args['mcID'],  array('Authorization: apikey ' . $this->config['mcAPI'])));

          $return['mcData'] = $this->_parseMCData( $campaign );

          // Calculate retention score.
          if(
            isset( $return['mcData']['open_rate'] ) && $return['mcData']['open_rate'] &&
            isset( $return['gaData']['pageviews']['unique_pageviews'] ) && $return['gaData']['pageviews']['unique_pageviews'] &&
            isset( $return['gaData']['pageviews']['avg_time'] ) && $return['gaData']['pageviews']['avg_time']
          ) {
            $return['score'] = $this->_calculateScore( array(
              'open_rate'        => $return['mcData']['open_rate'],
              'unique_pageviews' => $return['gaData']['pageviews']['unique_pageviews'],
              'avg_time'         => $return['gaData']['pageviews']['avg_time']
            ));
          }
          else {
            $return['errors'][] = "Not enough data to calculate the retention score.";
          }
          break;
        case 'acquisition':
          // Get events from Google Analytics.
          $result = $this->_gaCall( array(
            'type'     => 'events',
            'category' => $args['category'],
            'action'   => $args['action'],
            'label'    => $args['label']
          ));
          $return['errors']           = $return['errors'] + $result['errors'];
          $return['gaData']['events'] = $result['data'];

          // Calculate acquisition score.
          if(
            isset( $return['gaData']['pageviews']['unique_pageviews'] ) && $return['gaData']['pageviews']['unique_pageviews'] &&
            isset( $return['gaData']['pageviews']['avg_time'] ) && $return['gaData']['pageviews']['avg_time'] &&
            isset( $return['gaData']['events']['unique_events'] ) && $return['gaData']['events']['unique_events']
          ) {
            $return['score'] = $this->_calculateScore( array(
              'unique_pageviews' => $return['gaData']['pageviews']['unique_pageviews'],
              'avg_time'         => $return['gaData']['pageviews']['avg_time'],
              'unique_events'    => $return['gaData']['events']['unique_events']
            ));
          }
          else {
            $return['errors'][] = "Not enough data to calculate the acquisition score.";
          }
          break;
      }
    }

    return $return;
  }

  private function _gaCall( $args )
  {
    $return = array(
      "errors" => array(),
      "data"   => array()
    );
    $optParams = array();

    switch( $args['type'] ) {
      case 'pageviews':
        $metrics              = 'ga:uniquePageviews,ga:avgTimeOnPage';
        $optParams['filters'] = "ga:pagePath==" . $args['path'];
        break;
      case 'events':
        $metrics               = 'ga:uniqueEvents';
        $optParams['filters']  = "ga:eventCategory==" . $args['category'] . ";";
        $optParams['filters'] .= "ga:eventAction==" . $args['action'] . ";";
        $optParams['filters'] .= "ga:eventLabel==" . $args['label'];
       break;
    }

    try {
      $connect = $this->authenticateGA();

      if( $connect['connected'] ) {

        $analytics    = new Google_Service_Analytics( $this->client );
        $analytics_id = 'ga:' . $this->viewID;
        $result       = $analytics->data_ga->get( $analytics_id,
                        date( 'Y-m-d', strtotime( $this->startDate ) ),
                        date( 'Y-m-d', strtotime( $this->endDate ) ), $metrics, $optParams);

        if( $result->getRows() ) {
          $results = $result->getRows();

          $metricsAry = explode( ",", $metrics );
          foreach( $metricsAry as $key => $val ) {
            $return['data'][$val] = $results[0][$key];
          }

          $return['data'] = $this->_parseGAData( $return['data'] );
        }
        else {
          $return['errors'][] = "No data available.";
        }
      }
      else {
        $return['errors'][] = "Not connected to the Google API.";
      }
    }
    catch(Exception $e) {
      $return['errors'][] = "There was an error: " . $e->getMessage();
    }

    return $return;
  }

  private function _openRateScore( $openRate )
  {
    switch( $openRate ) {
      case $openRate < 38:
        return 10;
        break;
      case $openRate >= 38 && $openRate <= 41:
        return 20;
        break;
      case $openRate > 41 && $openRate <= 43:
        return 30;
        break;
      case $openRate > 43 && $openRate <= 45:
        return 40;
        break;
      case $openRate > 45:
        return 50;
        break;
    }
  }

  private function _getScore( $num, $range )
  {
    switch( $num ) {
      case $num <= $range[10]:
        return 10;
        break;
      case $num > $range[10] && $num <= $range[20]:
        return 20;
        break;
      case $num > $range[20] && $num <= $range[30]:
        return 30;
        break;
      case $num > $range[30] && $num <= $range[40]:
        return 40;
        break;
      case $num > $range[40]:
        return 50;
        break;
    }
  }

  private function _pageviewsScore( $pageviews )
  {
    $ranges = array(
      'retention' => array(
        10 => 50,
        20 => 200,
        30 => 475,
        40 => 750
      ),
      'acquisition' => array(
        10 => 300,
        20 => 400,
        30 => 750,
        40 => 1500
      )
    );

    return $this->_getScore( $pageviews, $ranges[$this->type] );
  }

  private function _avgTimeScore( $time )
  {
    $ranges = array(
      'retention' => array(
        10 => 60,
        20 => 180,
        30 => 240,
        40 => 300
      ),
      'acquisition' => array(
        10 => 120,
        20 => 210,
        30 => 300,
        40 => 420
      )
    );

    return $this->_getScore( $time, $ranges[$this->type] );
  }

  private function _eventsScore( $events )
  {
    switch( $events ) {
      case $events <= 15:
        return 10;
      break;
      case $events > 15 && $events <= 30:
        return 20;
      break;
      case $events > 30 && $events <= 60:
        return 30;
      break;
      case $events > 60 && $events <= 110:
        return 40;
      break;
      case $events > 110:
        return 50;
      break;
    }
  }

  private function _calculateScore( $data )
  {
    $return = array(
      'pageviews' => 0,
      'avg_time'  => 0
    );

    switch( $this->type ) {
      case 'retention':
        $return['open_rate'] = 0;

        // Calculate open rate score.
        $return['open_rate'] = $this->_openRateScore( $data['open_rate'] );
        break;
      case 'acquisition':
        $return['events'] = 0;

        // Calculate events score.
        $return['events'] = $this->_eventsScore( $data['unique_events'] );
        break;
    }

    // Calculate pageviews score.
    $return['pageviews'] = $this->_pageviewsScore( $data['unique_pageviews'] );

    // Calculate average time on page score.
    $return['avg_time'] = $this->_avgTimeScore( $data['avg_time'] );

    return $return;
  }

  private function _parseMCData( $data )
  {
    $return = array();

    $return['open_rate']           = $data->opens->open_rate;
    $return['open_rate_formatted'] = round( $return['open_rate'], 2 );

    return $return;
  }

  private function _calculateOpenRate( $data )
  {
    $opened        = $data['unique_opens'];
    $sent          = $data['emails_sent'];
    $bounces       = $data['hard_bounces'] + $data['soft_bounces'];
    $unsubscribes  = $data['unsubscribes'];
    $abuse_reports = $data['abuse_reports'];
    $invalid = $bounces + $unsubscribes + $abuse_reports;

    return ( $opened / ( $sent - $invalid ) ) * 100;
  }

  private function _parseGAData( $data )
  {
    $return = array();

    foreach( $data as $key => $value ) {
      switch( $key ) {
        case "ga:uniquePageviews":
          $key = "unique_pageviews";
          break;
        case "ga:avgTimeOnPage":
          $key                          = "avg_time";
          $return['avg_time_formatted'] = $this->_toTime( $value );
          break;
        case "ga:uniqueEvents":
          $key = "unique_events";
          break;
      }

      $return[$key] = $value;
    }

    return $return;
  }

  private function _toTime( $secs ) {
    $t = round( $secs );
    return sprintf('%02d:%02d:%02d', ($t/3600),($t/60%60), $t%60);
  }

  private function _gaConnect() {
    $this->client = new Google_Client();
    $this->client->setApplicationName( $this->config['app_name'] );

    if ( !empty($this->config['curl_ssl_verify_peer']) ) {
      $io = $this->client->getIo();
      $io->setOptions($this->config['curl_ssl_verify_peer']);
      $this->client->setIo($io);
    }

    if ( $this->config['app_type'] == 'service' ) {
      $this->client->setAssertionCredentials(
        new Google_Auth_AssertionCredentials(

          // Google client ID email address
          $this->config['email_address'],
          array('https://www.googleapis.com/auth/analytics.readonly'),

          // Downloaded client ID certificate file
          file_get_contents( $this->config['private_key_file'] )
        )
      );
    }
    else {
      $this->client->setClientSecret( $this->config['client_secret'] );
      $this->client->setRedirectUri( $this->config['redirect_uri'] );
      $this->client->setDeveloperKey( $this->config['key'] );
      $this->client->setScopes( array( "https://www.googleapis.com/auth/analytics.readonly" ) );
    }

    // Set the Client ID.
    if ( !empty( $this->config['client_id'] ) ) $this->client->setClientId( $this->config['client_id'] );

    $this->client->setAccessType( "offline" );
  }
}
