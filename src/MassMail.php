<?php

namespace Drupal\massmail;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Composer\InstalledVersions;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Builds and sends MassMail emails through Amazon SES.
 */
class MassMail {

  /**
   * Last log line of a finished send.
   */
  const COMPLETE = 'Complete.';

  /**
   * Prefix of the last log line of a failed send.
   */
  const ERROR = 'Error: ';

  /**
   * Service day_of_week codes.
   */
  const DAYS = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
  ];

  /**
   * Service service_type codes.
   */
  const SERVICE_TYPES = [
    1 => 'Adoration',
    2 => 'Confession',
    3 => 'Devotion',
    5 => 'Holy Day',
    6 => 'Vigil for Holy Day',
    7 => 'Weekday',
    8 => 'Weekend',
  ];

  /**
   * Open handle of the log file during a send.
   *
   * @var resource|null
   */
  protected $log;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected string $appRoot,
  ) {}

  /**
   * AWS credentials from the environment.
   *
   * Set MASSMAIL_AWS_ACCESS_KEY_ID and MASSMAIL_AWS_SECRET_ACCESS_KEY as
   * (sensitive) environment variables, e.g. Upsun env: variables.
   *
   * @return array
   *   With 'key' and 'secret'; empty strings when not set.
   */
  public function credentials(): array {
    return [
      'key' => (string) getenv('MASSMAIL_AWS_ACCESS_KEY_ID'),
      'secret' => (string) getenv('MASSMAIL_AWS_SECRET_ACCESS_KEY'),
    ];
  }

  /**
   * Whether credentials, settings and the private file system are in place.
   */
  public function isConfigured(): bool {
    $credentials = $this->credentials();
    $settings = $this->configFactory->get('massmail.settings');
    return $credentials['key'] && $credentials['secret']
      && $settings->get('aws_region') && $settings->get('data_dir')
      && $this->fileSystem->realpath('private://');
  }

  /**
   * Stream URI of the data directory.
   *
   * Always private: the source data and log contain contact details.
   */
  public function dataUri(): string {
    return 'private://' . $this->configFactory->get('massmail.settings')->get('data_dir');
  }

  /**
   * Stream URI of the send log.
   */
  public function logUri(): string {
    return $this->dataUri() . '/massmail.log';
  }

  /**
   * Last line of the send log, or NULL if there is no log.
   */
  public function lastLogLine(): ?string {
    $path = $this->fileSystem->realpath($this->logUri());
    if (!$path || !is_file($path)) {
      return NULL;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return $lines ? end($lines) : '';
  }

  /**
   * Starts a send in a detached background Drush process.
   *
   * @param string $mode
   *   'test' or 'live'.
   */
  public function startSend(string $mode): void {
    // A fresh log, so the status page shows "Sending..." straight away.
    $directory = $this->dataUri();
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($this->fileSystem->realpath($directory) . '/massmail.log', "Starting...\n");

    $drush = InstalledVersions::getInstallPath('drush/drush') . '/drush.php';
    $command = implode(' ', array_map('escapeshellarg', [
      PHP_BINDIR . '/php',
      $drush,
      '--root=' . $this->appRoot,
      'massmail:send',
      $mode,
    ]));
    exec($command . ' > /dev/null 2>&1 &');
  }

  /**
   * Builds and sends the emails, logging progress.
   *
   * @param string $mode
   *   'test' sends the first and last emails to their From address; 'live'
   *   sends all of them.
   * @param callable|null $output
   *   Also receives each log line, e.g. to print it.
   */
  public function send(string $mode, ?callable $output = NULL): void {
    $path = $this->fileSystem->realpath($this->dataUri()) . '/massmail.log';
    $this->log = fopen($path, 'w');
    if (!$this->log) {
      throw new \RuntimeException('Cannot create log at ' . $path);
    }

    $log = function (string $line) use ($output) {
      fwrite($this->log, $line . "\n");
      if ($output) {
        $output($line);
      }
    };

    try {
      $log('Initializing...');
      $requests = $this->buildRequests($log);

      $log('Preparing to send ' . count($requests) . ' emails...');
      $ses = $this->sesClient();
      $rate = max(1, (int) $ses->getSendQuota()['MaxSendRate']);

      if ($mode === 'live') {
        $log('Sending live emails...');
      }
      else {
        // Test the first and last email, sent to the From address.
        $requests = array_values(array_unique(array_filter([reset($requests), end($requests)]), SORT_REGULAR));
        foreach ($requests as &$request) {
          $request['Destination']['ToAddresses'] = [$request['Source']];
        }
        unset($request);
        $log('Sending ' . count($requests) . ' test emails to the From address...');
      }

      $sent = $failed = 0;
      foreach ($requests as $i => $request) {
        // SES API rate limit.
        if ($i > 0 && $i % $rate === 0) {
          sleep(1);
        }
        $to = implode(', ', $request['Destination']['ToAddresses']);
        try {
          $ses->sendEmail($request);
          $sent++;
          $log("Sent email $i to $to");
        }
        catch (AwsException $e) {
          $failed++;
          $log("Failed email $i to $to: " . ($e->getAwsErrorMessage() ?: $e->getMessage()));
        }
      }

      $log("Sent $sent, failed $failed.");
      $log(self::COMPLETE);
    }
    catch (\Throwable $e) {
      $log(self::ERROR . $e->getMessage());
      throw $e;
    }
    finally {
      fclose($this->log);
      $this->log = NULL;
    }
  }

  /**
   * Returns an authorized Amazon SES client.
   */
  protected function sesClient(): SesClient {
    return new SesClient([
      'version' => '2010-12-01',
      'region' => $this->configFactory->get('massmail.settings')->get('aws_region'),
      'credentials' => $this->credentials(),
    ]);
  }

  /**
   * Reads a CSV source file by fid into rows keyed by column name.
   */
  protected function sourceData($fid): array {
    $file = $fid ? $this->entityTypeManager->getStorage('file')->load($fid) : NULL;
    if (!$file) {
      throw new \RuntimeException('Source data file ' . $fid . ' not found. Upload it again.');
    }

    $data = [];
    foreach (file($file->getFileUri()) ?: [] as $line) {
      $data[] = str_getcsv($line);
    }
    if (!$data) {
      return [];
    }

    $headings = array_shift($data);
    $rows = [];
    foreach ($data as $row) {
      // Skip rows whose column count doesn't match the headings (e.g. blank
      // lines); array_combine() throws a ValueError on these.
      if (count($row) === count($headings)) {
        $rows[] = array_combine($headings, $row);
      }
    }
    return $rows;
  }

  /**
   * Builds one SES request per church.
   */
  protected function buildRequests(callable $log): array {
    $mail = $this->configFactory->get('massmail.mail');

    $log('Getting primary source data...');
    $data = $this->sourceData($mail->get('source_fid_0'));
    $log('Assigning ' . count($data) . ' primary source data items...');

    $records = [];
    foreach ($data as $row) {
      if (is_numeric($row['id'] ?? NULL)) {
        $records[$row['id']] = $row;
        $records[$row['id']]['services'] = [];
      }
    }

    $log('Getting secondary source data...');
    $data = $this->sourceData($mail->get('source_fid_1'));
    $log('Assigning ' . count($data) . ' secondary source data items...');

    foreach ($data as $row) {
      if (
        is_numeric($row['id'] ?? NULL) &&
        is_numeric($row['church_id'] ?? NULL) &&
        array_key_exists((int) $row['church_id'], $records)
      ) {
        $records[(int) $row['church_id']]['services'][$row['id']] = $row;
      }
    }
    unset($data);

    $log('Creating ' . count($records) . ' email requests...');
    $requests = [];
    foreach ($records as $record) {
      $requests[] = $this->buildRequest($record);
    }
    return $requests;
  }

  /**
   * Builds the SES request for one church record.
   */
  protected function buildRequest(array $record): array {
    $mail = $this->configFactory->get('massmail.mail');

    $build = [];

    // Emails
    $build['to'] = ($record['email'] ?? '') ?: $mail->get('to');
    $build['from'] = $mail->get('from');
    $build['reply'] = $mail->get('reply') ?: $build['from'];
    $build['bounce'] = $mail->get('bounce') ?: $build['from'];
    $build['suppressionlist'] = $mail->get('suppressionlist') ?: $build['from'];

    // Subject
    $build['subject'] = $mail->get('subject');

    // Intro (plain)
    $build['body'] = $mail->get('body') . "\n\n";

    // Intro (html)
    $build['html'] = '<div style="font-family:Helvetica, Arial, sans-serif; font-size:14px;color:#333;">';
    $build['html'] .= '<h3 style="color:#4f77a6;font-size: 18px;margin: 18px 0;"><span style="font-weight:normal;font-size: 20px;">MassTimes – </span>150 million searches and counting!</h3>';
    $build['html'] .= str_replace("\n", "<br>", $mail->get('body'));
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

    foreach ($record['services'] as $service) {
      $build['html'] .= '<tr>';

      // Service day
      $day = self::DAYS[$service['day_of_week'] ?? ''] ?? '';
      if ($day !== '') {
        $build['body'] .= $day . ' ';
      }
      $build['html'] .= '<td style="padding-left:0;padding-right:25px;">' . $day . '</td>';

      // Service type
      $type = self::SERVICE_TYPES[$service['service_type'] ?? ''] ?? '';
      if ($type !== '') {
        $build['body'] .= "($type)";
      }
      $build['html'] .= '<td style="padding-left:0;padding-right:25px;">' . $type . '</td>';

      // Start time
      $build['html'] .= '<td style="padding-left:0;padding-right:25px;">';
      if (($service['time_start'] ?? '') !== '') {
        $time = date('g:ia', strtotime($service['time_start']));
        $build['body'] .= "– Start: $time";
        $build['html'] .= $time;
      }
      $build['html'] .= '</td>';

      // End time
      $build['html'] .= '<td>';
      if (($service['time_end'] ?? '') !== '') {
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
    foreach ($record as $token => $replace) {
      if (!is_array($replace)) {
        foreach ($build as $key => $value) {
          $build[$key] = str_replace("[[$token]]", (string) $replace, $value);
        }
      }
    }

    return [
      'Source' => $build['from'],
      'Destination' => [
        'ToAddresses' => [$build['to']],
      ],
      'Message' => [
        'Subject' => [
          'Data' => $build['subject'],
          'Charset' => 'UTF-8',
        ],
        'Body' => [
          'Text' => [
            'Data' => $build['body'],
            'Charset' => 'UTF-8',
          ],
          'Html' => [
            'Data' => $build['html'],
            'Charset' => 'UTF-8',
          ],
        ],
      ],
      'ReplyToAddresses' => [$build['reply']],
      'ReturnPath' => $build['bounce'],
    ];
  }

}
