<?php

namespace Drupal\massmail\Controller;

use Aws\Ses\SesClient;
use Drupal\Core\Controller\ControllerBase;

class Status extends ControllerBase {

  /**
   * Status page.
   * @return array
   */
  public function page() {
    $markup = '';
    $logpath = massmail_data_dir() . '/massmail.log';

    if (file_exists($logpath)) {
      if (massmail_send_is_complete()) {
        $markup .= '<h4>' . $this->t('Sending complete!') . '</h4>' .
                   '<p>' . 'Details can be found in the log file at: <a href="' . file_create_url(file_build_uri('/')) . \Drupal::config('massmail.settings')->get('data_dir') . '/massmail.log' . '">' . $logpath . '</a></p>';
      } else {
        $markup .= '<h4>' . $this->t('Sending...') . '</h4>' .
                   '<p>' . $this->t("This may take several minutes to complete. Refresh this page to check for completion.") . '</p>';
      }

    } else {
      $markup .= '<h4>' . $this->t('Send queue is empty.') . '</h4>' .
                 '<p>' . $this->t("Logfile of previous submission for sending does not exist at: " . $logpath) . '</p>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $markup,
      '#cache' => ['max-age' => 0,],
    ];
  }

}
